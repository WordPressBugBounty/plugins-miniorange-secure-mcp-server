<?php
/**
 * REST route registration for the OAuth 2.0 server.
 *
 * @package Miniorange_Secure_MCP_Server
 */

namespace MoSMCP\Common\Apis\OAuth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MoSMCP\Common\Services\OAuth\OAuth_Server;
use WP_REST_Server;

/**
 * Class OAuth_Routes
 *
 * Registers the Dynamic Client Registration and token endpoints of the
 * OAuth 2.0 authorization server. Route handling lives in
 * {@see OAuth_Server}.
 */
class OAuth_Routes {

	/**
	 * Registers OAuth routes. Called from {@see Rest_Routes::register()}.
	 *
	 * @return void
	 */
	public static function register() {
		// OAuth Dynamic Client Registration (RFC 7591).
		register_rest_route(
			MOSMCP_REST_NAMESPACE,
			'/register',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( OAuth_Server::class, 'register_client' ),
				'permission_callback' => '__return_true',
			)
		);

		// OAuth token endpoint.
		register_rest_route(
			MOSMCP_REST_NAMESPACE,
			'/token',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( OAuth_Server::class, 'token' ),
				'permission_callback' => '__return_true',
			)
		);
	}
}
