<?php
/**
 * REST route registration for the deactivation feedback form.
 *
 * @package Miniorange_Secure_MCP_Server
 */

namespace MoSMCP\Standard\Apis\Feedback;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MoSMCP\Standard\Controllers\Feedback\Deactivation_Controller;
use WP_REST_Server;

/**
 * Class Feedback_Routes
 *
 * Registers the deactivation feedback endpoint. Route handling lives in
 * {@see Deactivation_Controller}.
 */
class Feedback_Routes {

	/**
	 * Registers the feedback route.
	 *
	 * @return void
	 */
	public static function register() {
		// Deactivation feedback — forwards reason + comments to miniOrange support.
		register_rest_route(
			MOSMCP_REST_NAMESPACE,
			'/deactivation-feedback',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( Deactivation_Controller::class, 'submit' ),
				'permission_callback' => array( Deactivation_Controller::class, 'check_permission' ),
			)
		);
	}
}
