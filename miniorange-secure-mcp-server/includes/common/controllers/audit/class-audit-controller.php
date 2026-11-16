<?php
/**
 * Handles the audit log REST endpoints.
 *
 * @package Miniorange_Secure_MCP_Server
 */

namespace MoSMCP\Common\Controllers\Audit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MoSMCP\Common\Controllers\Abstract_Admin_Controller;
use MoSMCP\Common\Repositories\Audit_Store;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Class Audit_Controller
 *
 * Exposes the MCP tool-call audit log to admin users via REST. Provides a
 * paginated/filtered log list, an aggregated summary for the dashboard cards,
 * and a clear endpoint for admins who want to wipe the log.
 */
class Audit_Controller extends Abstract_Admin_Controller {

	/**
	 * Returns a paginated, optionally filtered list of audit log entries.
	 *
	 * Query parameters: page, per_page, user_id, nhi_uuid, tool_name, status,
	 * date_from (YYYY-MM-DD), date_to (YYYY-MM-DD).
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public static function list_logs( WP_REST_Request $request ) {
		$args = array(
			'page'       => absint( $request->get_param( 'page' ) ?? 1 ),
			'per_page'   => absint( $request->get_param( 'per_page' ) ?? 25 ),
			'user_login' => sanitize_text_field( (string) ( $request->get_param( 'user_login' ) ?? '' ) ),
			'nhi_uuid'   => sanitize_text_field( (string) ( $request->get_param( 'nhi_uuid' ) ?? '' ) ),
			'tool_name'  => sanitize_text_field( (string) ( $request->get_param( 'tool_name' ) ?? '' ) ),
			'status'     => sanitize_text_field( (string) ( $request->get_param( 'status' ) ?? '' ) ),
			'date_from'  => sanitize_text_field( (string) ( $request->get_param( 'date_from' ) ?? '' ) ),
			'date_to'    => sanitize_text_field( (string) ( $request->get_param( 'date_to' ) ?? '' ) ),
		);

		return new WP_REST_Response( Audit_Store::query( $args ), 200 );
	}

	/**
	 * Returns aggregate statistics: total calls, counts by status, average
	 * latency, and the top 5 most-invoked tools.
	 *
	 * @return WP_REST_Response
	 */
	public static function get_summary() {
		return new WP_REST_Response( Audit_Store::summary(), 200 );
	}

	/**
	 * Truncates the entire audit log. Irreversible; requires manage_options.
	 *
	 * @return WP_REST_Response
	 */
	public static function clear_logs() {
		Audit_Store::clear();

		return new WP_REST_Response( array( 'cleared' => true ), 200 );
	}
}
