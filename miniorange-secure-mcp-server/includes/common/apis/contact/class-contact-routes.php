<?php
/**
 * REST route registration for the Contact Us form.
 *
 * @package Miniorange_Secure_MCP_Server
 */

namespace MoSMCP\Common\Apis\Contact;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MoSMCP\Common\Controllers\Contact\Contact_Controller;
use MoSMCP\Common\Controllers\Contact\Deactivation_Controller;
use WP_REST_Server;

/**
 * Class Contact_Routes
 *
 * Registers the contact form submission endpoint. Route handling lives in
 * {@see Contact_Controller}.
 */
class Contact_Routes {

	/**
	 * Registers the contact route. Called from {@see Rest_Routes::register()}.
	 *
	 * @return void
	 */
	public static function register() {
		// Contact Us form — forwards the query to miniOrange support.
		register_rest_route(
			MOSMCP_REST_NAMESPACE,
			'/contact',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( Contact_Controller::class, 'submit' ),
				'permission_callback' => array( Contact_Controller::class, 'check_permission' ),
			)
		);

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
