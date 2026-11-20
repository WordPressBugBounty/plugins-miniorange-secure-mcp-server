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
	 * Error-level events are recorded even while capture is switched off, so a failure has a trace
	 * on the site without the admin having to enable logging first and reproduce it. This caps how
	 * many one request may add in that mode...
	 */
	const ALWAYS_ON_MAX_PER_REQUEST = 10;

	/**
	 * ...and this caps how many always-on rows the table keeps (the oldest are trimmed on write,
	 * so the log cannot grow while nobody is looking at it).
	 */
	const ALWAYS_ON_MAX_ROWS = 500;

	/**
	 * Longest correlation id adopted from an incoming X-Request-ID header. The debug table's
	 * request_id column is varchar(36); an id that would not fit is replaced, not truncated.
	 */
	const MAX_REQUEST_ID_LENGTH = 36;

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
	 * Per-field budget, in bytes, for a raw wire payload logged as a single
	 * context value ({@see payload()}). Deliberately well under
	 * MAX_CONTEXT_LENGTH: that cap lands wherever it lands, so an oversized body
	 * in the first field can push every later field out of the row. The counts
	 * and flags beside a payload are usually what identify the fault, and they
	 * are worthless if the payload crowds them out.
	 */
	const MAX_PAYLOAD_LENGTH = 2000;

	/**
	 * Default number of entries per chunk row ({@see chunk_list()}). Sized so a
	 * full chunk of the longest ability names in use still encodes inside
	 * MAX_CONTEXT_LENGTH, with headroom for the packs that keep being added.
	 */
	const LIST_CHUNK_SIZE = 150;

	/**
	 * Context object keys masked before storage, regardless of channel —
	 * defense in depth against a call site accidentally logging a secret.
	 * Matched case-insensitively as a substring of the key.
	 *
	 * WARNING to call-site authors: the match is a SUBSTRING, not a whole-key
	 * comparison, and it recurses into nested arrays and objects. A harmless key
	 * that merely contains one of these words — `token_resource`, `api_key_count`,
	 * `salt_index` — stores as ***redacted***, silently destroying the very value
	 * the log line exists to report, with nothing in the row to say it happened.
	 * Name such fields around the collision instead (`issued_for`,
	 * `bearer_prefix`). {@see redact_message()} applies the same list to
	 * `key: value` fragments inside the free-text message, so message wording is
	 * subject to it too.
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
	 * Error rows buffered this request while capture was off ({@see ALWAYS_ON_MAX_PER_REQUEST}).
	 *
	 * @var int
	 */
	private static $always_on_count = 0;

	/**
	 * Whether the shutdown check for a fatal error is already armed.
	 *
	 * @var bool
	 */
	private static $fatal_watch = false;

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
		$capturing = self::is_enabled();

		if ( ! $capturing ) {
			// Capture is off: only a failure is kept, and only a few per request.
			if ( 'error' !== $level || self::$always_on_count >= self::ALWAYS_ON_MAX_PER_REQUEST ) {
				return;
			}
			++self::$always_on_count;
		}

		if ( count( self::$buffer ) >= self::MAX_BUFFER ) {
			if ( ! self::$truncated ) {
				self::$truncated = true;
				self::$buffer[]  = self::build_row( 'warning', 'debug-logger', 'Buffer limit reached; further events this request were dropped.', array() );
			}
			return;
		}

		self::$buffer[] = self::build_row( $level, $channel, $message, $context, $capturing );

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
	 * @param bool                 $capturing Whether the admin has switched capture on. When it is off
	 *                                        this is an always-on error row, which carries no username
	 *                                        or IP address: the admin never opted in to those.
	 * @return array<string, mixed>
	 */
	private static function build_row( $level, $channel, $message, array $context, $capturing = true ) {
		$user    = wp_get_current_user();
		$user_id = $user ? (int) $user->ID : 0;
		$login   = ( $capturing && $user && $user_id ) ? (string) $user->user_login : '';

		$ip = '';
		if ( $capturing && ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
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

		if ( ! self::is_enabled() ) {
			// Cap the error rows, and only those: the info, warning and debug rows somebody collected
			// with capture on must survive until they are downloaded or age out.
			Debug_Store::trim_to( self::ALWAYS_ON_MAX_ROWS, 'error' );
		}
	}

	/**
	 * Whether capture is currently switched on.
	 *
	 * Exposed for call sites on a hot path that must not pay to build their
	 * context — an ability-name list, a registry scan, a JSON-encoded payload —
	 * before knowing whether anything will store it. The logging methods are
	 * cheap no-ops while capture is off, but their *arguments* are evaluated
	 * either way, so an expensive context has to be guarded by this rather than
	 * by the call itself.
	 *
	 * @return bool
	 */
	public static function is_capturing() {
		return self::is_enabled();
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
	 * This request's correlation id: the one the MCP gateway sent in X-Request-ID when it is
	 * well-formed (so a gateway log line and the entries here can be matched), otherwise a fresh
	 * UUID.
	 *
	 * @return string
	 */
	private static function request_id() {
		if ( null === self::$request_id ) {
			self::$request_id = self::incoming_request_id();
			if ( '' === self::$request_id ) {
				self::$request_id = wp_generate_uuid4();
			}
		}

		return self::$request_id;
	}

	/**
	 * This request's correlation id, for quoting in an error shown to a user or an AI client so the
	 * matching log rows can be found. (The detail itself stays in the log.)
	 *
	 * @return string
	 */
	public static function current_request_id() {
		return self::request_id();
	}

	/**
	 * Arms a one-time check, at shutdown, for a fatal PHP error during this request.
	 *
	 * A fatal error (memory exhausted, an uncatchable engine error) cannot be caught, so the caller
	 * gets whatever the server answers and, without this, nothing records why. It can only log:
	 * the response is already lost.
	 *
	 * @return void
	 */
	public static function watch_for_fatal() {
		if ( self::$fatal_watch ) {
			return;
		}
		self::$fatal_watch = true;
		register_shutdown_function( array( __CLASS__, 'log_fatal' ) );
	}

	/**
	 * Shutdown handler for {@see watch_for_fatal()}.
	 *
	 * @return void
	 */
	public static function log_fatal() {
		$last = error_get_last();
		if ( ! $last || ! in_array( $last['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR ), true ) ) {
			return;
		}

		// An uncaught exception's message ends in a stack trace, and with exception args on it lists each
		// call's argument values (a bearer token, a secret). The first line says what happened and where.
		$message = (string) $last['message'];
		$trace   = strpos( $message, "\nStack trace:" );
		if ( false !== $trace ) {
			$message = substr( $message, 0, $trace );
		}

		self::error(
			self::CHANNEL_MCP,
			'PHP stopped with a fatal error while handling an MCP request.',
			array(
				'error_type' => (int) $last['type'],
				'detail'     => self::truncate( $message, 300 ),
				'at'         => basename( (string) $last['file'] ) . ':' . (int) $last['line'],
			)
		);
		self::flush();
	}

	/**
	 * The X-Request-ID header, if present and safe to store.
	 *
	 * The value is caller-controlled, so it is only adopted when it is short and made of id-like
	 * characters (the gateway sends 32 hex characters); anything else is ignored rather than cleaned.
	 *
	 * @return string The id, or '' when there is none or it is unusable.
	 */
	private static function incoming_request_id() {
		if ( empty( $_SERVER['HTTP_X_REQUEST_ID'] ) || ! is_string( $_SERVER['HTTP_X_REQUEST_ID'] ) ) {
			return '';
		}

		// Not sanitized, deliberately: cleaning a hostile value would adopt a different id than the
		// one sent. The allow-list below accepts the value exactly as sent or not at all.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$id = wp_unslash( $_SERVER['HTTP_X_REQUEST_ID'] );

		return 1 === preg_match( '/^[A-Za-z0-9._:=\-]{1,' . self::MAX_REQUEST_ID_LENGTH . '}$/D', $id ) ? $id : '';
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
			} elseif ( is_string( $value ) ) {
				// A secret can sit in a value under an innocent key (a notice snippet, an exception message).
				$result[ $key ] = self::redact_message( $value );
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
		// An auth scheme word ("Authorization: Bearer <credential>") is skipped so the credential is masked too.
		$pattern = '/\b(' . implode( '|', self::REDACTED_KEY_PATTERNS ) . ')\w*\s*[:=]\s*(?:(?:bearer|basic|digest|token)\s+)?\S+/i';

		return (string) preg_replace( $pattern, '$1=***redacted***', $message );
	}

	/**
	 * Trims one raw wire payload to its own budget, marking what was dropped.
	 *
	 * The reported size is the true one, which is itself diagnostic: a tools/list
	 * envelope announcing six figures says the registry is large, not empty.
	 *
	 * @param string $raw    The raw payload.
	 * @param int    $budget Maximum bytes kept.
	 * @return string The payload, truncated with a marker when over budget.
	 */
	public static function payload( $raw, $budget = self::MAX_PAYLOAD_LENGTH ) {
		$raw   = (string) $raw;
		$bytes = strlen( $raw );

		if ( $bytes <= $budget ) {
			return $raw;
		}

		// Cut on a character boundary: a bare byte-offset cut can split a multibyte
		// sequence, and the resulting invalid UTF-8 makes wp_json_encode() return
		// false for the entire context — losing every field in the row, not just
		// this one.
		if ( function_exists( 'mb_strcut' ) ) {
			$cut = mb_strcut( $raw, 0, $budget, 'UTF-8' );
		} else {
			$cut = (string) preg_replace( '/(?:[\xC0-\xFF][\x80-\xBF]*)$/', '', substr( $raw, 0, $budget ) );
		}

		return $cut . sprintf( '… [truncated, %d bytes total]', $bytes );
	}

	/**
	 * Splits a long list of short strings into per-row chunks.
	 *
	 * A few hundred ability names JSON-encode past MAX_CONTEXT_LENGTH on their
	 * own, so the list cannot travel as one context value; chunking spreads it
	 * over a few rows that share the request's correlation id. Each chunk carries
	 * an explicit "k/n" so a reader can tell a complete set from a clipped one.
	 *
	 * @param string[] $items     The list.
	 * @param int      $per_chunk Entries per chunk.
	 * @return list<array{chunk: string, names: string}> One entry per row to log; empty for an empty list.
	 */
	public static function chunk_list( array $items, $per_chunk = self::LIST_CHUNK_SIZE ) {
		$chunks = array_chunk( array_values( $items ), max( 1, (int) $per_chunk ) );
		$total  = count( $chunks );
		$rows   = array();

		foreach ( $chunks as $index => $chunk ) {
			$rows[] = array(
				'chunk' => sprintf( '%d/%d', $index + 1, $total ),
				'names' => implode( ',', $chunk ),
			);
		}

		return $rows;
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
