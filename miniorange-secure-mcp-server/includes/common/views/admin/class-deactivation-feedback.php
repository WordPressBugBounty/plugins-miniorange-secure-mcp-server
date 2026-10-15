<?php
/**
 * Deactivation feedback modal rendered in the admin footer on plugins.php.
 *
 * @package Miniorange_Secure_MCP_Server
 */

namespace MoSMCP\Common\Views\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Deactivation_Feedback
 *
 * Intercepts the plugin's Deactivate link, shows a one-screen feedback modal,
 * and forwards the submission to the miniOrange notification API before
 * completing the deactivation.
 */
class Deactivation_Feedback {

	/**
	 * Enqueues assets and outputs the modal markup into the admin footer.
	 * Only runs on the plugins.php screen.
	 *
	 * @return void
	 */
	public static function maybe_render() {
		$screen = get_current_screen();
		if ( ! $screen || 'plugins' !== $screen->id ) {
			return;
		}

		$views_url = plugins_url( 'views/', MOSMCP_PLUGIN_FILE );
		$css_path  = MOSMCP_PLUGIN_DIR . 'views/public/css/deactivation-feedback.css';
		$js_path   = MOSMCP_PLUGIN_DIR . 'views/public/js/deactivation-feedback.js';

		// Version by file mtime so a changed asset busts the browser cache even
		// when the plugin version is unchanged.
		$css_ver = file_exists( $css_path ) ? (string) filemtime( $css_path ) : MOSMCP_VERSION;
		$js_ver  = file_exists( $js_path ) ? (string) filemtime( $js_path ) : MOSMCP_VERSION;

		wp_enqueue_style(
			'mosmcp-deactivation-feedback',
			$views_url . 'public/css/deactivation-feedback.css',
			array(),
			$css_ver
		);

		wp_enqueue_script(
			'mosmcp-deactivation-feedback',
			$views_url . 'public/js/deactivation-feedback.js',
			array(),
			$js_ver,
			true
		);

		$current_user = wp_get_current_user();
		$first_name   = $current_user->first_name;
		if ( '' === $first_name ) {
			// Fall back to the first token of the display name.
			$first_name = trim( (string) strtok( (string) $current_user->display_name, ' ' ) );
		}

		// Whole days the plugin has been active, for the "days active" note.
		// 0 when the install time is unknown (upgrades from before it was tracked).
		$installed_at = (int) get_option( 'mosmcp_installed_at', 0 );
		$days_active  = $installed_at > 0 ? (int) floor( ( time() - $installed_at ) / DAY_IN_SECONDS ) : 0;

		wp_localize_script(
			'mosmcp-deactivation-feedback',
			'MOSMCPDeactivation',
			array(
				'endpoint'   => rest_url( MOSMCP_REST_NAMESPACE . '/deactivation-feedback' ),
				'nonce'      => wp_create_nonce( 'wp_rest' ),
				'pluginSlug' => plugin_basename( MOSMCP_PLUGIN_FILE ),
				'firstName'  => $first_name,
				'email'      => $current_user->user_email,
				'daysActive' => $days_active,
				'adminUrl'   => admin_url( 'admin.php?page=mosmcp-abilities' ),
			)
		);

		$template = MOSMCP_PLUGIN_DIR . 'views/assets/templates/deactivation-feedback.html';
		if ( file_exists( $template ) ) {
			include $template;
		}
	}
}