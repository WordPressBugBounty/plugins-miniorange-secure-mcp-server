<?php
/**
 * REST route registration for NHI (Non-Human Identity) management.
 *
 * @package Miniorange_Secure_MCP_Server
 */

namespace MoSMCP\Common\Apis\NHI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MoSMCP\Common\Controllers\NHI\NHI_Controller;

/**
 * Class NHI_Routes
 *
 * Registers the NHI CRUD endpoints under /mosmcp/v1/nhi.
 */
class NHI_Routes {

	/**
	 * Registers all NHI routes.
	 *
	 * @return void
	 */
	public static function register() {
		// List all NHI clients / create a new one.
		register_rest_route(
			MOSMCP_REST_NAMESPACE,
			'/nhi',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( NHI_Controller::class, 'list_clients' ),
					'permission_callback' => array( NHI_Controller::class, 'check_permission' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( NHI_Controller::class, 'create_client' ),
					'permission_callback' => array( NHI_Controller::class, 'check_permission' ),
				),
			)
		);

		// Update or delete a single NHI.
		register_rest_route(
			MOSMCP_REST_NAMESPACE,
			'/nhi/(?P<uuid>[0-9a-f\-]{36})',
			array(
				array(
					'methods'             => 'PATCH',
					'callback'            => array( NHI_Controller::class, 'update_client' ),
					'permission_callback' => array( NHI_Controller::class, 'check_permission' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( NHI_Controller::class, 'delete_client' ),
					'permission_callback' => array( NHI_Controller::class, 'check_permission' ),
				),
			)
		);

		// Abilities available to the current user (member "tools available to you" view).
		register_rest_route(
			MOSMCP_REST_NAMESPACE,
			'/me/access',
			array(
				'methods'             => 'GET',
				'callback'            => array( NHI_Controller::class, 'my_access' ),
				'permission_callback' => array( NHI_Controller::class, 'check_logged_in' ),
			)
		);

		// Connected users this NHI reaches (for the NHI Members tab).
		register_rest_route(
			MOSMCP_REST_NAMESPACE,
			'/nhi/(?P<uuid>[0-9a-f\-]{36})/members',
			array(
				'methods'             => 'GET',
				'callback'            => array( NHI_Controller::class, 'members' ),
				'permission_callback' => array( NHI_Controller::class, 'check_permission' ),
			)
		);

		// WordPress roles + capabilities, for the admin role-ability matrix.
		register_rest_route(
			MOSMCP_REST_NAMESPACE,
			'/roles',
			array(
				'methods'             => 'GET',
				'callback'            => array( NHI_Controller::class, 'list_roles' ),
				'permission_callback' => array( NHI_Controller::class, 'check_permission' ),
			)
		);

		// Map of ability => owning NHI, for the exclusivity picker in the admin UI.
		register_rest_route(
			MOSMCP_REST_NAMESPACE,
			'/nhi/ability-owners',
			array(
				'methods'             => 'GET',
				'callback'            => array( NHI_Controller::class, 'ability_owners' ),
				'permission_callback' => array( NHI_Controller::class, 'check_permission' ),
			)
		);
	}
}
