<?php
/**
 * Base class for admin-only REST controllers.
 *
 * @package Miniorange_Secure_MCP_Server
 */

namespace MoSMCP\Common\Controllers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Abstract_Admin_Controller
 *
 * Provides the shared permission check used by every admin-facing REST endpoint.
 * Extend this class for any controller whose routes require `manage_options`.
 */
abstract class Abstract_Admin_Controller {

	/**
	 * Permission callback for admin-only endpoints.
	 *
	 * @return bool True when the current user is a WordPress administrator.
	 */
	public static function check_permission() {
		return current_user_can( 'manage_options' );
	}
}
