<?php
/**
 * Logging service: a generic, WP_DEBUG-independent debug/diagnostic logger.
 *
 * @package Miniorange_Secure_MCP_Server
 */

namespace MoSMCP\Common\Services\Logging;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MoSMCP\Common\Repositories\Debug_Store;

/**
 * Class Debug_Logger
 *
 * Public logging API for the whole plugin: `Debug_Logger::info()`,
 * `::warning()`, `::error()`, `::debug()`. Entirely independent of
 * WP_DEBUG / error_log / wp-content/debug.log — events are written to the
 * plugin's own `wp_mosmcp_debug_log` table (via {@see Debug_Store}) only when
 * capture is switched on (`Debug_Store::OPTION_ENABLED`).
 *
 * Calls are cheap no-ops while capture is off. While on, entries are buffered
 * in memory for the request and flushed in a single batched insert on
 * shutdown, so a chatty request never costs more than one extra query.
 *
 * Per-request context (authenticated user, MCP client) is captured once, by
 * whichever code path establishes it — see {@see set_client_context()} — so
 * every log call during that request is automatically attributed without the
 * caller having to pass it explicitly.
 */
class Debug_Logger {

	/**
	 * Channel used for MCP transport/auth/tool-call events.
	 */
	const CHANNEL_MCP = 'mcp';

	/**
	 * Channel used for OAuth server events.
	 */
	const CHANNEL_OAUTH = 'oauth';

	/**
	 * Channel used for NHI (agent) and role-grant persistence events.
	 */
	const CHANNEL_NHI = 'nhi';

	/**
	 * Hard cap on buffered entries per request. A runaway loop that logs
	 * thousands of times in one request must not exhaust memory; once hit,
	 * further calls are dropped and a single "buffer truncated" marker is
	 * appended so the gap is visible rather than silent.
	 */
	const MAX_BUFFER = 500;

	/**
	 * Longest message stored, in characters. Longer messages are truncated so
	 * one call can't bloat the table.
	 */
	const MAX_MESSAGE_LENGTH = 4000;

	/**
	 * Longest JSON-encoded context stored, in characters.
	 */
	const MAX_CONTEXT_LENGTH = 8000;

	/**
	 * Context object keys masked before storage, regardless of channel —
	 * defense in depth against a call site accidentally logging a secret.
	 * Matched case-insensitively as a substring of the key.
	 *
	 * @var string[]
	 */
	const REDACTED_KEY_PATTERNS = array( 'password', 'secret', 'token', 'authorization', 'api_key', 'apikey', 'private_key', 'salt' );

	/**
	 * Cached capture-enabled flag for the current request.
	 *
	 * @var bool|null
	 */
	private static $enabled = null;

	/**
	 * Buffered rows awaiting flush.
	 *
	 * @var list<array<string, mixed>>
	 */
	private static $buffer = array();

	/**
	 * Whether the buffer has exceeded MAX_BUFFER and a truncation marker was recorded.
	 *
	 * @var bool
	 */
	private static $truncated = false;

	/**
	 * Whether the shutdown flush has already been registered.
	 *
	 * @var bool
	 */
	private static $shutdown_registered = false;

	/**
	 * Per-request correlation id, shared by every entry logged during this request.
	 *
	 * @var string|null
	 */
	private static $request_id = null;

	/**
	 * Per-request MCP client context, set by {@see set_client_context()}.
	 *
	 * @var array{client_id: string, client_name: string}
	 */
	private static $client = array(
		'client_id'   => '',
		'client_name' => '',
	);

	/**
	 * Records the authenticated MCP client for this request, so subsequent log
	 * calls are automatically attributed to it without passing it explicitly.
	 *
	 * @param string $client_id   The OAuth client identifier.
	 * @param string $client_name The client's registered display name.
	 * @return void
	 */
	public static function set_client_context( $client_id, $client_name = '' ) {
		self::$client['client_id']   = (string) $client_id;
		self::$client['client_name'] = (string) $client_name;
	}

	/**
	 * Logs a debug-level event: fine-grained internal detail, useful mainly
	 * while actively troubleshooting.
	 *
	 * @param string               $channel Short category, e.g. 'mcp', 'oauth', 'ability'.
	 * @param string               $message Human-readable summary.
	 * @param array<string, mixed> $context Additional structured detail (optional).
	 * @return void
	 */
	public static function debug( $channel, $message, array $context = array() ) {
		self::log( 'debug', $channel, $message, $context );
	}

	/**
	 * Logs an info-level event: a notable but expected occurrence.
	 *
	 * @param string               $channel Short category, e.g. 'mcp', 'oauth', 'ability'.
	 * @param string               $message Human-readable summary.
	 * @param array<string, mixed> $context Additional structured detail (optional).
	 * @return void
	 */
	public static function info( $channel, $message, array $context = array() ) {
		self::log( 'info', $channel, $message, $context );
	}

	/**
	 * Logs a warning-level event: something recoverable but worth attention.
	 *
	 * @param string               $channel Short category, e.g. 'mcp', 'oauth', 'ability'.
	 * @param string               $message Human-readable summary.
	 * @param array<string, mixed> $context Additional structured detail (optional).
	 * @return void
	 */
	public static function warning( $channel, $message, array $context = array() ) {
		self::log( 'warning', $channel, $message, $context );
	}

	/**
	 * Logs an error-level event: an operation failed.
	 *
	 * @param string               $channel Short category, e.g. 'mcp', 'oauth', 'ability'.
	 * @param string               $message Human-readable summary.
	 * @param array<string, mixed> $context Additional structured detail (optional).
	 * @return void
	 */
	public static function error( $channel, $message, array $context = array() ) {
		self::log( 'error', $channel, $message, $context );
	}

	/**
	 * Buffers a log entry, registering the shutdown flush on first use.
	 *
	 * @param string               $level   One of Debug_Store::LEVELS.
	 * @param string               $channel Short category.
	 * @param string               $message Human-readable summary.
	 * @param array<string, mixed> $context Additional structured detail.
	 * @return void
	 */
	private static function log( $level, $channel, $message, array $context ) {
		if ( ! self::is_enabled() ) {
			return;
		}

		if ( count( self::$buffer ) >= self::MAX_BUFFER ) {
			if ( ! self::$truncated ) {
				self::$truncated = true;
				self::$buffer[]  = self::build_row( 'warning', 'debug-logger', 'Buffer limit reached; further events this request were dropped.', array() );
			}
			return;
		}

		self::$buffer[] = self::build_row( $level, $channel, $message, $context );

		if ( ! self::$shutdown_registered ) {
			self::$shutdown_registered = true;
			register_shutdown_function( array( __CLASS__, 'flush' ) );
		}
	}

	/**
	 * Builds a storable row from a log call, resolving ambient request context.
	 *
	 * @param string               $level   One of Debug_Store::LEVELS.
	 * @param string               $channel Short category.
	 * @param string               $message Human-readable summary.
	 * @param array<string, mixed> $context Additional structured detail.
	 * @return array<string, mixed>
	 */
	private static function build_row( $level, $channel, $message, array $context ) {
		$user    = wp_get_current_user();
		$user_id = $user ? (int) $user->ID : 0;
		$login   = ( $user && $user_id ) ? (string) $user->user_login : '';

		$ip = '';
		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$raw_ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
			$ip     = filter_var( $raw_ip, FILTER_VALIDATE_IP ) ? $raw_ip : '';
		}

		$message = self::truncate( self::redact_message( (string) $message ), self::MAX_MESSAGE_LENGTH );

		$encoded_context = null;
		if ( ! empty( $context ) ) {
			$encoded_context = self::truncate(
				(string) wp_json_encode( self::redact( $context ) ),
				self::MAX_CONTEXT_LENGTH
			);
		}

		return array(
			'created'     => time(),
			'level'       => in_array( $level, Debug_Store::LEVELS, true ) ? $level : 'info',
			'channel'     => '' !== $channel ? substr( $channel, 0, 50 ) : 'general',
			'message'     => $message,
			'context'     => $encoded_context,
			'request_id'  => self::request_id(),
			'user_id'     => $user_id,
			'user_login'  => $login,
			'client_id'   => self::$client['client_id'],
			'client_name' => self::$client['client_name'],
			'ip_address'  => $ip,
		);
	}

	/**
	 * Writes every buffered entry in a single batched insert and clears the buffer.
	 *
	 * Registered as a shutdown function; safe to call directly (e.g. tests, or
	 * a long-running CLI process that wants to flush mid-run).
	 *
	 * @return void
	 */
	public static function flush() {
		if ( empty( self::$buffer ) ) {
			return;
		}

		$rows            = self::$buffer;
		self::$buffer    = array();
		self::$truncated = false;

		Debug_Store::insert_many( $rows );
	}

	/**
	 * Whether capture is currently switched on, cached for the request.
	 *
	 * @return bool
	 */
	private static function is_enabled() {
		if ( null === self::$enabled ) {
			self::$enabled = Debug_Store::is_enabled();
		}

		return self::$enabled;
	}

	/**
	 * Lazily generates this request's correlation id.
	 *
	 * @return string
	 */
	private static function request_id() {
		if ( null === self::$request_id ) {
			self::$request_id = wp_generate_uuid4();
		}

		return self::$request_id;
	}

	/**
	 * Masks values of any context key that looks like it might hold a secret,
	 * recursively — including into nested objects (e.g. a WP_Error's
	 * get_error_data(), whose shape is up to whichever ability raised it), not
	 * just nested arrays. Defense in depth: call sites should avoid logging
	 * secrets in the first place, but this keeps an accidental inclusion from
	 * persisting.
	 *
	 * @param array<string, mixed>|object $context Raw context.
	 * @return array<string, mixed> Context with sensitive values masked.
	 */
	private static function redact( $context ) {
		$context = is_object( $context ) ? get_object_vars( $context ) : $context;
		$result  = array();

		foreach ( $context as $key => $value ) {
			$is_sensitive = false;
			foreach ( self::REDACTED_KEY_PATTERNS as $pattern ) {
				if ( is_string( $key ) && false !== stripos( $key, $pattern ) ) {
					$is_sensitive = true;
					break;
				}
			}

			if ( $is_sensitive ) {
				$result[ $key ] = '***redacted***';
			} elseif ( is_array( $value ) || is_object( $value ) ) {
				$result[ $key ] = self::redact( $value );
			} else {
				$result[ $key ] = $value;
			}
		}

		return $result;
	}

	/**
	 * Masks "key: value" / "key=value" fragments embedded in free-text messages
	 * where the key looks like it might hold a secret. {@see redact()} only
	 * covers structured `context` values keyed by name — a secret can also end
	 * up woven into the human-readable `message` string itself (e.g. a WP_Error
	 * message like "Invalid API key sk-live-XXXX" surfaced via
	 * get_error_message()), which this catches without mangling ordinary prose.
	 *
	 * @param string $message Raw message.
	 * @return string Message with sensitive-looking fragments masked.
	 */
	private static function redact_message( $message ) {
		$pattern = '/\b(' . implode( '|', self::REDACTED_KEY_PATTERNS ) . ')\w*\s*[:=]\s*\S+/i';

		return (string) preg_replace( $pattern, '$1=***redacted***', $message );
	}

	/**
	 * Truncates a string to a maximum length, appending a marker when cut.
	 *
	 * @param string $value  The string.
	 * @param int    $length Maximum length.
	 * @return string
	 */
	private static function truncate( $value, $length ) {
		if ( function_exists( 'mb_strlen' ) ? mb_strlen( $value ) <= $length : strlen( $value ) <= $length ) {
			return $value;
		}

		$cut = function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $length ) : substr( $value, 0, $length );

		return $cut . '… [truncated]';
	}
}
