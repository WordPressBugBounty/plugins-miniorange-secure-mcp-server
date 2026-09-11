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
use WP_REST_Response;

/**
 * Class Diagnostics_Controller
 *
 * Thin delegation to {@see Connectivity_Checker} for the React admin UI's "Test Connection"
 * button on the Connect page.
 */
class Diagnostics_Controller extends Abstract_Admin_Controller {

	/**
	 * Runs the connectivity check set now.
	 *
	 * @return WP_REST_Response
	 */
	public static function run() {
		return new WP_REST_Response( Connectivity_Checker::run(), 200 );
	}

	/**
	 * Returns the cached result of the last run, if any.
	 *
	 * @return WP_REST_Response
	 */
	public static function last_run() {
		$cached = Connectivity_Checker::last_run();

		return new WP_REST_Response( array( 'last_run' => $cached ), 200 );
	}
}
