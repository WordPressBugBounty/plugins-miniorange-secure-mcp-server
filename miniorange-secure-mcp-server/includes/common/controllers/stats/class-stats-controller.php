<?php
/**
 * Handles the /stats REST endpoint.
 *
 * @package Miniorange_Secure_MCP_Server
 */

namespace MoSMCP\Common\Controllers\Stats;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MoSMCP\Common\Controllers\Abstract_Admin_Controller;
use MoSMCP\Common\Repositories\NHI_Store;
use MoSMCP\Common\Repositories\Store;
use WP_REST_Response;

/**
 * Class Stats_Controller
 *
 * Returns aggregated counts used by the React admin dashboard.
 */
class Stats_Controller extends Abstract_Admin_Controller {

	/**
	 * Returns dashboard statistics.
	 *
	 * @return WP_REST_Response
	 */
	public static function get_stats() {
		$abilities_total = function_exists( 'wp_get_abilities' ) ? count( wp_get_abilities() ) : 0;

		return new WP_REST_Response(
			array(
				'nhi_total'       => NHI_Store::count_all(),
				'nhi_enabled'     => NHI_Store::count_enabled(),
				'active_tokens'   => Store::count_active_tokens(),
				'has_connected'   => Store::has_ever_issued_token(),
				'abilities_total' => $abilities_total,
			),
			200
		);
	}

}
