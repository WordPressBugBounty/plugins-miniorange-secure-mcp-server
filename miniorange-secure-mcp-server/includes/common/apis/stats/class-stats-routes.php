<?php
/**
 * REST route registration for the statistics endpoint.
 *
 * @package Miniorange_Secure_MCP_Server
 */

namespace MoSMCP\Common\Apis\Stats;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MoSMCP\Common\Controllers\Stats\Stats_Controller;

/**
 * Class Stats_Routes
 *
 * Registers GET /mosmcp/v1/stats for the React admin dashboard.
 */
class Stats_Routes {

	/**
	 * Registers the stats route.
	 *
	 * @return void
	 */
	public static function register() {
		register_rest_route(
			MOSMCP_REST_NAMESPACE,
			'/stats',
			array(
				'methods'             => 'GET',
				'callback'            => array( Stats_Controller::class, 'get_stats' ),
				'permission_callback' => array( Stats_Controller::class, 'check_permission' ),
			)
		);
	}
}
