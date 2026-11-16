<?php
/**
 * REST route registration for the MCP transport.
 *
 * @package Miniorange_Secure_MCP_Server
 */

namespace MoSMCP\Common\Apis\MCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MoSMCP\Common\Controllers\MCP\REST_Controller;
use WP_REST_Server;

/**
 * Class MCP_Routes
 *
 * Registers the MCP Streamable HTTP endpoint and the public health probe.
 * Also wires the WWW-Authenticate challenge header filter for unauthorized
 * MCP responses. Route handling lives in {@see REST_Controller}.
 */
class MCP_Routes {

	/**
	 * Registers MCP routes. Called from {@see Rest_Routes::register()}.
	 *
	 * @return void
	 */
	public static function register() {
		// MCP transport endpoint (Streamable HTTP, POST + JSON).
		register_rest_route(
			MOSMCP_REST_NAMESPACE,
			'/mcp',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( REST_Controller::class, 'handle' ),
				'permission_callback' => array( REST_Controller::class, 'authenticate' ),
			)
		);

		// Public health / discovery probe.
		register_rest_route(
			MOSMCP_REST_NAMESPACE,
			'/health',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( REST_Controller::class, 'health' ),
				'permission_callback' => '__return_true',
			)
		);

		// Point unauthorized MCP responses at the protected-resource metadata.
		add_filter( 'rest_post_dispatch', array( REST_Controller::class, 'add_challenge_header' ), 10, 3 );
	}
}
