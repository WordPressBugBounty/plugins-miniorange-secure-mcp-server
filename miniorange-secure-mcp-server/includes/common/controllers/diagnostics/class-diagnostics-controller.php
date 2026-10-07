<?php
/**
 * Handles the /connectivity-test REST endpoint.
 *
 * @package Miniorange_Secure_MCP_Server
 */

namespace MoSMCP\Common\Controllers\Diagnostics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MoSMCP\Common\Controllers\Abstract_Admin_Controller;
use MoSMCP\Common\Services\Diagnostics\Connectivity_Checker;
use MoSMCP\Common\Services\Diagnostics\Fix_Catalog;
use WP_REST_Response;

/**
 * Class Diagnostics_Controller
 *
 * Thin delegation to {@see Connectivity_Checker}. Both routes require `manage_options`, and
 * WordPress rejects cookie-authenticated REST calls that lack a valid `wp_rest` nonce.
 */
class Diagnostics_Controller extends Abstract_Admin_Controller {

	/**
	 * Runs the connection test now.
	 *
	 * @return WP_REST_Response
	 */
	public static function run() {
		return new WP_REST_Response( Connectivity_Checker::run(), 200 );
	}

	/**
	 * Returns the troubleshooting guide, built from the same fix catalog the test results use.
	 *
	 * @return WP_REST_Response
	 */
	public static function guide() {
		return new WP_REST_Response( array( 'entries' => Fix_Catalog::guide() ), 200 );
	}

	/**
	 * Returns the cached result of the last run and any pending auto-run request.
	 *
	 * @return WP_REST_Response
	 */
	public static function last_run() {
		return new WP_REST_Response(
			array(
				'last_run' => Connectivity_Checker::last_run(),
				'auto_run' => Connectivity_Checker::pending_auto_run(),
			),
			200
		);
	}
}
