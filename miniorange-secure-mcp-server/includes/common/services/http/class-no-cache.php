<?php
/**
 * Keeps every response this plugin serves out of page, server and edge caches.
 *
 * @package Miniorange_Secure_MCP_Server
 */

namespace MoSMCP\Common\Services\Http;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_HTTP_Response;
use WP_REST_Request;
use WP_REST_Server;

/**
 * Class No_Cache
 *
 * Every response under the mosmcp/v1 namespace, and both .well-known discovery
 * documents, reports live state (whether an agent is enabled, which client was just
 * registered, a fresh token) or a per-request challenge. A cached copy breaks
 * connections: a gateway that is served a stale `nhi_count: 0` reports "No agent
 * registered" after the admin has created one.
 *
 * Standard Cache-Control is not enough on its own. LiteSpeed Cache with "Cache REST
 * API" turned on ignores it and obeys only its own header and action, and
 * page-cache plugins such as WP Super Cache and W3 Total Cache look for the
 * DONOTCACHEPAGE constant. Each of these is a documented opt-out and does nothing
 * when the cache in question is not installed.
 */
class No_Cache {

	/**
	 * Headers sent on every uncacheable response.
	 */
	const HEADERS = array(
		'Cache-Control'             => 'no-store, no-cache, must-revalidate, max-age=0',
		'Pragma'                    => 'no-cache',
		'Expires'                   => 'Wed, 11 Jan 1984 05:00:00 GMT',
		'X-LiteSpeed-Cache-Control' => 'no-cache',
	);

	/**
	 * Marks this plugin's REST responses uncacheable. Hooked on rest_post_dispatch.
	 *
	 * Scoped to the plugin's own namespace, so other plugins' REST routes keep
	 * whatever caching they chose.
	 *
	 * @param WP_HTTP_Response|mixed $result  The response about to be served.
	 * @param WP_REST_Server         $server  The REST server.
	 * @param WP_REST_Request        $request The request.
	 * @return WP_HTTP_Response|mixed The same response, with no-cache headers when it is ours.
	 */
	public static function filter_rest_response( $result, $server, $request ) {
		if ( ! $result instanceof WP_HTTP_Response || ! $request instanceof WP_REST_Request ) {
			return $result;
		}

		if ( ! self::is_own_route( $request->get_route() ) ) {
			return $result;
		}

		foreach ( self::HEADERS as $name => $value ) {
			$result->header( $name, $value );
		}

		self::disable_page_caches();

		return $result;
	}

	/**
	 * Sends the no-cache headers directly, for responses served outside the REST API.
	 *
	 * @return void
	 */
	public static function send_headers() {
		if ( ! headers_sent() ) {
			foreach ( self::HEADERS as $name => $value ) {
				header( $name . ': ' . $value );
			}
		}

		self::disable_page_caches();
	}

	/**
	 * Whether a REST route belongs to this plugin's namespace.
	 *
	 * @param string $route Route, e.g. '/mosmcp/v1/health'.
	 * @return bool
	 */
	public static function is_own_route( $route ) {
		$prefix = '/' . MOSMCP_REST_NAMESPACE;
		$route  = (string) $route;

		return $route === $prefix || 0 === strpos( $route, $prefix . '/' );
	}

	/**
	 * Tells page-cache plugins not to store the current response.
	 *
	 * @return void
	 */
	private static function disable_page_caches() {
		// LiteSpeed Cache's documented opt-out; a no-op when it is not installed.
		do_action( 'litespeed_control_set_nocache', 'mosmcp: live MCP/OAuth state' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache's own hook.

		// Honoured by WP Super Cache, W3 Total Cache, WP Rocket and others.
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- The page-cache plugins' shared constant.
		}
	}
}
