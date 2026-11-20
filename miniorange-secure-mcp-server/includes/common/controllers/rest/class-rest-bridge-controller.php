<?php
/**
 * Handles the /rest-bridge-settings REST endpoint.
 *
 * @package Miniorange_Secure_MCP_Server
 */

namespace MoSMCP\Common\Controllers\Rest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MoSMCP\Common\Controllers\Abstract_Admin_Controller;
use MoSMCP\Common\Services\Rest\Rest_Bridge;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Class Rest_Bridge_Controller
 *
 * Reads and writes the site-wide write kill switches for the REST bridge. These are
 * an emergency override on top of the per-NHI route grants, not a replacement for
 * them — see {@see Rest_Bridge::verb_disabled()}.
 */
class Rest_Bridge_Controller extends Abstract_Admin_Controller {

	/**
	 * Returns the current kill switch settings.
	 *
	 * @return WP_REST_Response
	 */
	public static function get_settings() {
		return new WP_REST_Response( Rest_Bridge::settings(), 200 );
	}

	/**
	 * Updates one or more kill switches.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_settings( WP_REST_Request $request ) {
		$body = $request->get_json_params();

		if ( ! is_array( $body ) ) {
			return new WP_Error( 'mosmcp_bad_request', __( 'Invalid request body.', 'miniorange-secure-mcp-server' ), array( 'status' => 400 ) );
		}

		$settings = Rest_Bridge::settings();

		foreach ( array( 'enable_create', 'enable_update', 'enable_delete' ) as $key ) {
			if ( array_key_exists( $key, $body ) ) {
				// get_param returns the value sanitized by the route's boolean schema; the raw body would cast "false" to true.
				$settings[ $key ] = (bool) $request->get_param( $key );
			}
		}

		// update_option returns false for an unchanged value too, so only a mismatch is a real failure.
		if ( ! update_option( Rest_Bridge::SETTINGS_OPTION, $settings, false ) && Rest_Bridge::settings() !== $settings ) {
			return new WP_Error( 'mosmcp_update_failed', __( 'Failed to update settings.', 'miniorange-secure-mcp-server' ), array( 'status' => 500 ) );
		}

		return new WP_REST_Response( $settings, 200 );
	}
}
