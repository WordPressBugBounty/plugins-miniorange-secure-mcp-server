<?php
/**
 * WordPress dashboard widget telling team members they can connect their own AI.
 *
 * @package Miniorange_Secure_MCP_Server
 */

namespace MoSMCP\Common\Views\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MoSMCP\Common\Repositories\NHI_Store;
use MoSMCP\Common\Utils\Utils;

/**
 * Class Member_Dashboard_Widget
 *
 * An editor, author or shop manager whose role has AI abilities rarely opens the
 * plugin's own screen, so they never learn that they can connect their own AI.
 * This puts the news where they already are: the WordPress dashboard. It is a
 * normal dashboard widget, so each person can move it, or hide it from Screen Options.
 *
 * Administrators manage access in the plugin itself and do not get the widget.
 */
class Member_Dashboard_Widget {

	/**
	 * Widget ID. Kept from the first release so a person who hid the widget keeps it hidden.
	 */
	const ID = 'mosmcp_connect_your_ai';

	/**
	 * Stylesheet handle.
	 */
	const STYLE_HANDLE = 'mosmcp-member-dashboard-widget';

	/**
	 * Registers the widget and its stylesheet for team members whose role has AI abilities.
	 *
	 * Hooked to wp_dashboard_setup, which runs before the admin header prints the
	 * page's styles, so the stylesheet can be enqueued from here.
	 *
	 * @return void
	 */
	public static function register() {
		if ( ! function_exists( 'wp_add_dashboard_widget' ) || null === self::ability_count() ) {
			return;
		}

		// Plain text, because Screen Options lists the widget under this title.
		wp_add_dashboard_widget(
			self::ID,
			esc_html__( 'miniOrange Secure MCP Server', 'miniorange-secure-mcp-server' ),
			array( __CLASS__, 'render' ),
			null,
			null,
			'normal',
			'high'
		);

		// Version by file mtime so a changed stylesheet busts the browser cache even
		// when the plugin version is unchanged.
		$css_path = MOSMCP_PLUGIN_DIR . 'views/public/css/member-dashboard-widget.css';
		wp_enqueue_style(
			self::STYLE_HANDLE,
			plugins_url( 'views/public/css/member-dashboard-widget.css', MOSMCP_PLUGIN_FILE ),
			array( 'dashicons' ),
			file_exists( $css_path ) ? (string) filemtime( $css_path ) : MOSMCP_VERSION
		);
	}

	/**
	 * Renders the widget.
	 *
	 * @return void
	 */
	public static function render() {
		$count = (int) self::ability_count();
		$roles = self::role_names();
		$page  = admin_url( 'admin.php?page=' . Admin_App::MEMBER_PAGE_SLUG );
		$tags  = array(
			'strong' => array(),
			'code'   => array(),
		);
		?>
		<div class="mosmcp-dw">
			<div class="mosmcp-dw__body">
				<div class="mosmcp-dw__hero">
					<span class="mosmcp-dw__logo" aria-hidden="true"></span>
					<div class="mosmcp-dw__intro">
						<h3 class="mosmcp-dw__title"><?php esc_html_e( 'Connect your own AI to this site', 'miniorange-secure-mcp-server' ); ?></h3>
						<p class="mosmcp-dw__text"><?php esc_html_e( 'Work on this site with ChatGPT, Claude or another AI assistant, signed in with your own WordPress login.', 'miniorange-secure-mcp-server' ); ?></p>
					</div>
				</div>

				<ul class="mosmcp-dw__facts" role="list" aria-label="<?php esc_attr_e( 'Your AI access', 'miniorange-secure-mcp-server' ); ?>">
					<?php if ( ! empty( $roles ) ) : ?>
						<li>
							<span class="dashicons dashicons-admin-users" aria-hidden="true"></span>
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: WordPress role name(s), e.g. "Editor". */
									_n( '%s role', '%s roles', count( $roles ), 'miniorange-secure-mcp-server' ),
									implode( ', ', $roles )
								)
							);
							?>
						</li>
					<?php endif; ?>
					<li>
						<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: number of abilities. */
								_n( '%s ability', '%s abilities', $count, 'miniorange-secure-mcp-server' ),
								number_format_i18n( $count )
							)
						);
						?>
					</li>
					<li>
						<span class="dashicons dashicons-shield" aria-hidden="true"></span>
						<?php esc_html_e( 'Every action logged', 'miniorange-secure-mcp-server' ); ?>
					</li>
				</ul>

				<h4 class="mosmcp-dw__label"><?php esc_html_e( 'How to connect', 'miniorange-secure-mcp-server' ); ?></h4>
				<ol class="mosmcp-dw__steps" role="list">
					<li><?php echo wp_kses( __( '<strong>Add the connector</strong> in ChatGPT, Claude or Cursor.', 'miniorange-secure-mcp-server' ), $tags ); ?></li>
					<li>
						<?php
						echo wp_kses(
							sprintf(
								/* translators: %s: this site's address. */
								__( '<strong>Enter this site</strong> when asked: %s', 'miniorange-secure-mcp-server' ),
								'<code>' . esc_html( Utils::issuer_url() ) . '</code>'
							),
							$tags
						);
						?>
					</li>
					<li><?php echo wp_kses( __( '<strong>Sign in as you</strong> and click Allow access.', 'miniorange-secure-mcp-server' ), $tags ); ?></li>
				</ol>
			</div>

			<div class="mosmcp-dw__footer">
				<a class="button button-primary" href="<?php echo esc_url( $page . '#/connect' ); ?>"><?php esc_html_e( 'Connect your AI', 'miniorange-secure-mcp-server' ); ?></a>
				<a class="button" href="<?php echo esc_url( $page . '#/me' ); ?>"><?php esc_html_e( 'See your abilities', 'miniorange-secure-mcp-server' ); ?></a>
			</div>
		</div>
		<?php
	}

	/**
	 * How many abilities the current user's role may use, or null when the widget
	 * does not apply (an administrator, a role with no abilities, or the service off).
	 *
	 * @return int|null
	 */
	private static function ability_count() {
		if ( ! is_user_logged_in() || current_user_can( 'manage_options' ) || ! Utils::service_enabled() ) {
			return null;
		}

		$user    = wp_get_current_user();
		$roles   = is_array( $user->roles ) ? array_values( $user->roles ) : array();
		$allowed = NHI_Store::resolve_for_roles( $roles );

		if ( null === $allowed ) {
			return function_exists( 'wp_get_abilities' ) ? count( wp_get_abilities() ) : 0;
		}

		return empty( $allowed ) ? null : count( (array) $allowed );
	}

	/**
	 * The current user's role names, translated.
	 *
	 * @return string[]
	 */
	private static function role_names() {
		$names = array();
		foreach ( (array) wp_get_current_user()->roles as $slug ) {
			$names[] = isset( wp_roles()->roles[ $slug ]['name'] ) ? translate_user_role( wp_roles()->roles[ $slug ]['name'] ) : $slug;
		}
		return $names;
	}
}
