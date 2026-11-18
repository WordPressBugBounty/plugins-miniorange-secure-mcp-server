<?php
/**
 * REST route registration for the REST bridge's own settings endpoint.
 *
 * @package Miniorange_Secure_MCP_Server
 */

namespace MoSMCP\Common\Apis\Rest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MoSMCP\Common\Controllers\Rest\Rest_Bridge_Controller;

/**
 * Class Rest_Bridge_Routes
 *
 * Registers GET/PATCH /mosmcp/v1/rest-bridge-settings for the site-wide write kill
 * switches. Distinct from the per-NHI route grants served under /nhi/*.
 */
class Rest_Bridge_Routes {

	/**
	 * Registers the settings route.
	 *
	 * @return void
	 */
	public static function register() {
		register_rest_route(
			MOSMCP_REST_NAMESPACE,
			'/rest-bridge-settings',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( Rest_Bridge_Controller::class, 'get_settings' ),
					'permission_callback' => array( Rest_Bridge_Controller::class, 'check_permission' ),
					'args'                => array(),
				),
				array(
					'methods'             => 'PATCH',
					'callback'            => array( Rest_Bridge_Controller::class, 'update_settings' ),
					'permission_callback' => array( Rest_Bridge_Controller::class, 'check_permission' ),
					'args'                => array(
						'enable_create' => array(
							'type'     => 'boolean',
							'required' => false,
						),
						'enable_update' => array(
							'type'     => 'boolean',
							'required' => false,
						),
						'enable_delete' => array(
							'type'     => 'boolean',
							'required' => false,
						),
					),
				),
			)
		);
	}
}
