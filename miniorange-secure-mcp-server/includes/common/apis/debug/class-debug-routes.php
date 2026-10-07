<?php
/**
 * REST route registration for the debug log endpoints.
 *
 * @package Miniorange_Secure_MCP_Server
 */

namespace MoSMCP\Common\Apis\Debug;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MoSMCP\Common\Controllers\Debug\Debug_Controller;
use MoSMCP\Common\Repositories\Debug_Store;

/**
 * Class Debug_Routes
 *
 * Registers admin-only endpoints under /mosmcp/v1/debug-logs:
 *   GET    /debug-logs           — paginated, filtered log list
 *   GET    /debug-logs/channels  — distinct channel names, for the filter dropdown
 *   GET    /debug-logs/status    — whether capture is on
 *   POST   /debug-logs/status    — switch capture on/off
 *   DELETE /debug-logs           — clear the entire log (irreversible)
 *
 * The log download is served via admin-post.php instead — see
 * {@see Debug_Controller::download_logs()}.
 */
class Debug_Routes {

	/**
	 * Registers all debug log routes.
	 *
	 * @return void
	 */
	public static function register() {
		register_rest_route(
			MOSMCP_REST_NAMESPACE,
			'/debug-logs',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( Debug_Controller::class, 'list_logs' ),
					'permission_callback' => array( Debug_Controller::class, 'check_permission' ),
					'args'                => array(
						'page'      => array(
							'type'    => 'integer',
							'minimum' => 1,
							'default' => 1,
						),
						'per_page'  => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 100,
							'default' => 50,
						),
						'level'     => array(
							'type' => 'string',
							'enum' => array_merge( array( '' ), Debug_Store::LEVELS ),
						),
						'channel'   => array(
							'type'    => 'string',
							'default' => '',
						),
						'search'    => array(
							'type'    => 'string',
							'default' => '',
						),
						'client_id' => array(
							'type'    => 'string',
							'default' => '',
						),
						'date_from' => array(
							'type'    => 'string',
							'default' => '',
						),
						'date_to'   => array(
							'type'    => 'string',
							'default' => '',
						),
					),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( Debug_Controller::class, 'clear_logs' ),
					'permission_callback' => array( Debug_Controller::class, 'check_permission' ),
				),
			)
		);

		register_rest_route(
			MOSMCP_REST_NAMESPACE,
			'/debug-logs/channels',
			array(
				'methods'             => 'GET',
				'callback'            => array( Debug_Controller::class, 'list_channels' ),
				'permission_callback' => array( Debug_Controller::class, 'check_permission' ),
			)
		);

		register_rest_route(
			MOSMCP_REST_NAMESPACE,
			'/debug-logs/status',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( Debug_Controller::class, 'get_status' ),
					'permission_callback' => array( Debug_Controller::class, 'check_permission' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( Debug_Controller::class, 'toggle_logging' ),
					'permission_callback' => array( Debug_Controller::class, 'check_permission' ),
					'args'                => array(
						'enabled' => array(
							'type'     => 'boolean',
							'required' => true,
						),
					),
				),
			)
		);
	}
}
