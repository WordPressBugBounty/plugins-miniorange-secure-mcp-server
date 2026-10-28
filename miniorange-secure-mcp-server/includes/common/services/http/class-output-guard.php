<?php
/**
 * Keeps stray PHP output out of this plugin's REST responses.
 *
 * On a site with display_errors on, a notice or deprecation warning from any plugin or theme is
 * printed straight into the response. In front of a JSON body it makes the body invalid, and the
 * broker (or any other JSON-RPC client) cannot parse the reply even though the plugin did its job.
 * This buffers output for requests to the plugin's own REST namespace and drops whatever was printed
 * before the response is served, leaving the JSON intact.
 *
 * @package Miniorange_Secure_MCP_Server
 */

namespace MoSMCP\Common\Services\Http;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MoSMCP\Common\Services\Logging\Debug_Logger;

/**
 * Class Output_Guard
 */
class Output_Guard {

	/**
	 * REST namespace this guard applies to. Nothing else on the site is touched.
	 */
	const NAMESPACE_PREFIX = 'mosmcp/';

	/**
	 * Transient that limits the always-on (error level) report to once an hour, so a notice that
	 * fires on every request cannot fill the error log.
	 */
	const REPORTED_TRANSIENT = 'mosmcp_stray_output_reported';

	/**
	 * Output-buffer level of the buffer this guard opened, or null when none is open.
	 *
	 * @var int|null
	 */
	private static $level = null;

	/**
	 * Opens the buffer, when the current request is for the plugin's REST namespace.
	 *
	 * Called as early as the plugin loads, so output printed by other plugins' load-time notices is
	 * caught too. A no-op for every other request.
	 *
	 * @return void
	 */
	public static function maybe_start() {
		if ( null !== self::$level || ! self::is_plugin_rest_request() ) {
			return;
		}

		if ( ob_start() ) {
			self::$level = ob_get_level();
		}
	}

	/**
	 * Whether the request is a call to one of this plugin's REST routes (pretty or plain permalinks).
	 *
	 * @return bool
	 */
	public static function is_plugin_rest_request() {
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Recommended -- Only compared, never stored or output.
		$uri = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		// A site can rename the REST prefix (some hardening plugins do), so match that too.
		$prefixes = array( 'wp-json' );
		if ( function_exists( 'rest_get_url_prefix' ) ) {
			$prefixes[] = (string) rest_get_url_prefix();
		}
		// The path only: a query string or fragment must not be able to switch the guard on for a page.
		$path = (string) strtok( (string) strtok( $uri, '#' ), '?' );
		foreach ( array_unique( array_filter( $prefixes ) ) as $prefix ) {
			if ( false !== strpos( $path, '/' . $prefix . '/' . self::NAMESPACE_PREFIX ) ) {
				return true;
			}
		}

		$route = isset( $_GET['rest_route'] ) && is_string( $_GET['rest_route'] ) ? wp_unslash( $_GET['rest_route'] ) : '';
		// phpcs:enable

		return 0 === strpos( ltrim( $route, '/' ), self::NAMESPACE_PREFIX );
	}

	/**
	 * `rest_pre_serve_request` filter: runs after the route has produced its response and before
	 * WordPress prints it, so everything in the buffer now is stray.
	 *
	 * @param bool $served Whether the request has already been served.
	 * @return bool Unchanged.
	 */
	public static function discard_stray_output( $served ) {
		if ( null === self::$level ) {
			return $served;
		}

		$level       = self::$level;
		self::$level = null;

		// Someone else already served the response (their output is in our buffer), or left a buffer
		// of their own open on top of ours: do not touch it. PHP flushes it at shutdown, as before.
		if ( $served || ob_get_level() !== $level ) {
			return $served;
		}

		$stray = (string) ob_get_clean();
		if ( '' !== trim( $stray ) ) {
			self::report( $stray );
		}

		return $served;
	}

	/**
	 * Records that output was discarded, so the cause (a notice on the site) is not lost along with it.
	 *
	 * The first one each hour is an error row, which is kept even with debug logging off; the rest are
	 * warnings, kept only while it is on.
	 *
	 * @param string $stray What was discarded.
	 * @return void
	 */
	private static function report( $stray ) {
		$snippet = trim( (string) preg_replace( '/\s+/', ' ', strip_tags( $stray ) ) );
		$first   = false === get_transient( self::REPORTED_TRANSIENT );
		if ( $first ) {
			set_transient( self::REPORTED_TRANSIENT, 1, HOUR_IN_SECONDS );
		}

		$message = 'Discarded unexpected PHP output ahead of an MCP response. A notice or warning is being printed on this site; turn off display_errors.';
		$context = array(
			'bytes'   => strlen( $stray ),
			'snippet' => function_exists( 'mb_substr' ) ? mb_substr( $snippet, 0, 300 ) : substr( $snippet, 0, 300 ),
		);

		if ( $first ) {
			Debug_Logger::error( Debug_Logger::CHANNEL_MCP, $message, $context );
		} else {
			Debug_Logger::warning( Debug_Logger::CHANNEL_MCP, $message, $context );
		}
	}
}
