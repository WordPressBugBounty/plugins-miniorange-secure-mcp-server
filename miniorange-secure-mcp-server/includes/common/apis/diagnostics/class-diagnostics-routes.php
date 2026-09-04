<?php
/**
 * REST route registration for the connectivity-test endpoint.
 *
 * @package Miniorange_Secure_MCP_Server
 */

namespace MoSMCP\Common\Apis\Diagnostics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MoSMCP\Common\Controllers\Diagnostics\Diagnostics_Controller;

/**
 * Class Diagnostics_Routes
 *
 * Registers the "Test Connection" endpoints for the React admin UI:
 * - POST /mosmcp/v1/connectivity-test — runs the check set now.
 * - GET  /mosmcp/v1/connectivity-test — returns the cached result of the last run, if any.
 */
class Diagnostics_Routes {

	/**
	 * Registers the connectivity-test routes.
	 *
	 * @return void
	 */
	public static function register() {
		register_rest_route(
			MOSMCP_REST_NAMESPACE,
			'/connectivity-test',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( Diagnostics_Controller::class, 'run' ),
					'permission_callback' => array( Diagnostics_Controller::class, 'check_permission' ),
				),
				array(
					'methods'             => 'GET',
					'callback'            => array( Diagnostics_Controller::class, 'last_run' ),
					'permission_callback' => array( Diagnostics_Controller::class, 'check_permission' ),
				),
			)
		);
	}
}
