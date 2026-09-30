<?php
/**
 * REST route registration for the audit log endpoints.
 *
 * @package Miniorange_Secure_MCP_Server
 */

namespace MoSMCP\Common\Apis\Audit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MoSMCP\Common\Controllers\Audit\Audit_Controller;

/**
 * Class Audit_Routes
 *
 * Registers admin-only endpoints under /mosmcp/v1/audit:
 *   GET  /audit         — paginated, filtered log list
 *   GET  /audit/summary — aggregate stats for the dashboard cards
 *   DELETE /audit       — clear the entire log (irreversible)
 */
class Audit_Routes {

	/**
	 * Registers all audit routes.
	 *
	 * @return void
	 */
	public static function register() {
		register_rest_route(
			MOSMCP_REST_NAMESPACE,
			'/audit',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( Audit_Controller::class, 'list_logs' ),
					'permission_callback' => array( Audit_Controller::class, 'check_permission' ),
					'args'                => array(
						'page'       => array(
							'type'    => 'integer',
							'minimum' => 1,
							'default' => 1,
						),
						'per_page'   => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 100,
							'default' => 25,
						),
						'user_login' => array(
							'type'    => 'string',
							'default' => '',
						),
						'nhi_uuid'   => array(
							'type'    => 'string',
							'default' => '',
						),
						'tool_name'  => array(
							'type'    => 'string',
							'default' => '',
						),
						'status'     => array(
							'type' => 'string',
							'enum' => array( '', 'success', 'failed', 'denied' ),
						),
						'date_from'  => array(
							'type'    => 'string',
							'default' => '',
						),
						'date_to'    => array(
							'type'    => 'string',
							'default' => '',
						),
					),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( Audit_Controller::class, 'clear_logs' ),
					'permission_callback' => array( Audit_Controller::class, 'check_permission' ),
				),
			)
		);

		register_rest_route(
			MOSMCP_REST_NAMESPACE,
			'/audit/summary',
			array(
				'methods'             => 'GET',
				'callback'            => array( Audit_Controller::class, 'get_summary' ),
				'permission_callback' => array( Audit_Controller::class, 'check_permission' ),
			)
		);
	}
}
