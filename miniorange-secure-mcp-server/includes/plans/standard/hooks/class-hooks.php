<?php
/**
 * Registration of the standard plan's WordPress actions and filters.
 *
 * @package Miniorange_Secure_MCP_Server
 */

namespace MoSMCP\Standard\Hooks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MoSMCP\Standard\Apis\Feedback\Feedback_Routes;
use MoSMCP\Standard\Views\Admin\Deactivation_Feedback;

/**
 * Class Hooks
 *
 * Wires the standard-only hooks on top of the common ones. Called once from
 * includes/plans/standard/loader.php.
 */
class Hooks {

	/**
	 * Registers all standard hooks. Invoked from the standard loader.
	 *
	 * @return void
	 */
	public static function init() {
		// Deactivation feedback: the modal on plugins.php and the route it posts to.
		add_action( 'admin_footer', array( Deactivation_Feedback::class, 'maybe_render' ) );
		add_action( 'rest_api_init', array( Feedback_Routes::class, 'register' ) );
	}
}
