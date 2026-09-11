<?php
/**
 * React SPA admin page: asset loading and bootstrap data injection.
 *
 * @package Miniorange_Secure_MCP_Server
 */

namespace MoSMCP\Common\Views\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MoSMCP\Common\Controllers\Debug\Debug_Controller;
use MoSMCP\Common\Migration\Migration;
use MoSMCP\Common\Repositories\NHI_Store;
use MoSMCP\Common\Repositories\Store;
use MoSMCP\Common\Utils\Utils;

/**
 * Class Admin_App
 *
 * Renders the single-page React application for the plugin admin UI.
 * In development the Vite dev server on port 5173 is used automatically;
 * in production assets are loaded from views/build/assets/.
 */
class Admin_App {

	/**
	 * WordPress admin page slug (admin, manage_options).
	 */
	const PAGE_SLUG = 'mosmcp-abilities';

	/**
	 * Member page slug (any logged-in user, `read`). Shown to non-admins so the
	 * admin surface is never exposed to them; the SPA renders the member view.
	 */
	const MEMBER_PAGE_SLUG = 'mosmcp-access';


	/**
	 * Script handle used for `wp_add_inline_script`.
	 */
	const SCRIPT_HANDLE = 'mosmcp-app';

	/**
	 * Registers admin-page hooks. Called from {@see Hooks::init()}.
	 *
	 * @return void
	 */
	public static function register_hooks() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_filter( 'admin_body_class', array( __CLASS__, 'maybe_add_fullscreen_body_class' ) );
		add_action( 'admin_head', array( __CLASS__, 'maybe_print_fullscreen_head_script' ) );
	}

	/**
	 * True when this request is the gateway's registration popup (the WAYF
	 * "no agent" screen opens `?page=mosmcp-abilities&mosmcp_popup=1#/nhi`).
	 *
	 * Read-only display flag, not a state change, so no nonce is needed here.
	 *
	 * @return bool
	 */
	private static function is_popup_request() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display flag, no state change.
		return isset( $_GET['page'], $_GET['mosmcp_popup'] )
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			&& self::PAGE_SLUG === sanitize_text_field( wp_unslash( $_GET['page'] ) )
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			&& '1' === sanitize_text_field( wp_unslash( $_GET['mosmcp_popup'] ) );
	}

	/**
	 * Adds the `mosmcp-fullscreen` body class server-side for the popup request,
	 * so the WP admin bar/menu are hidden before the browser's first paint
	 * rather than flashing on screen while the React bundle loads and mounts.
	 *
	 * @param string $classes Space-separated body classes.
	 * @return string
	 */
	public static function maybe_add_fullscreen_body_class( $classes ) {
		if ( self::is_popup_request() ) {
			$classes .= ' mosmcp-fullscreen';
		}
		return $classes;
	}

	/**
	 * Prints the fullscreen chrome-hiding rules inline in `<head>` for the popup
	 * request, plus a script mirroring the `mosmcp-fullscreen` body class onto
	 * `<html>` (`admin_body_class` only touches `<body>`; the CSS below also
	 * needs `html.mosmcp-fullscreen` to beat WP's inline `margin-top` on `html`).
	 *
	 * These are duplicated from the `.mosmcp-fullscreen` rules in the app's own
	 * stylesheet rather than relying on it: in development that stylesheet is
	 * injected by the Vite client as part of the module bundle, which only runs
	 * (and only then hides the admin bar/menu) after the whole bundle has
	 * fetched and executed — late enough that the admin bar/menu paint first
	 * and then disappear, which is the flash this is meant to prevent. Printing
	 * the rules directly here makes them available before `<body>` exists,
	 * independent of how or when the rest of the app's CSS loads.
	 *
	 * @return void
	 */
	public static function maybe_print_fullscreen_head_script() {
		if ( ! self::is_popup_request() ) {
			return;
		}
		?>
		<style>
			html.mosmcp-fullscreen { margin-top: 0 !important; padding-top: 0 !important; scroll-padding-top: 0 !important; }
			body.mosmcp-fullscreen #wpadminbar { display: none !important; }
			body.mosmcp-fullscreen #adminmenumain,
			body.mosmcp-fullscreen #adminmenuwrap,
			body.mosmcp-fullscreen #adminmenuback,
			body.mosmcp-fullscreen #adminmenushadow { display: none !important; }
			body.mosmcp-fullscreen #wpcontent { margin: 0 !important; padding: 0 !important; }
			body.mosmcp-fullscreen #wpbody,
			body.mosmcp-fullscreen #wpbody-content { padding: 0 !important; margin: 0 !important; }
			body.mosmcp-fullscreen #wpfooter { display: none !important; }
			body.mosmcp-fullscreen .notice,
			body.mosmcp-fullscreen .update-nag { display: none !important; }
			body.mosmcp-fullscreen #mosmcp-app-root { position: fixed; inset: 0; overflow-y: auto; z-index: 9999; background: #fff; }
		</style>
		<script>document.documentElement.classList.add("mosmcp-fullscreen");</script>
		<?php
	}

	/**
	 * Enqueues (or schedules) assets for the plugin admin page.
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 * @return void
	 */
	public static function enqueue_assets( $hook_suffix ) {
		if ( 'toplevel_page_' . self::PAGE_SLUG !== $hook_suffix && 'toplevel_page_' . self::MEMBER_PAGE_SLUG !== $hook_suffix ) {
			return;
		}

		// Enqueue the production build if it exists.
		self::enqueue_prod_assets();

		// Bootstrap payload always printed before the app scripts.
		add_action( 'admin_footer', array( __CLASS__, 'print_bootstrap' ), 1 );
	}

	/**
	 * Renders the React mount point.
	 *
	 * @return void
	 */
	public static function render() {
		// The menu registration gates each slug by capability (admin vs member);
		// any logged-in user may reach the mount, and the SPA decides which view to
		// render from the `isAdmin` flag in the bootstrap payload.
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'miniorange-secure-mcp-server' ) );
		}

		echo '<div id="mosmcp-app-root"></div>';
	}


	/**
	 * Enqueues the compiled IIFE bundle and its extracted CSS.
	 *
	 * No-op when the production build does not exist (i.e. during development).
	 *
	 * @return void
	 */
	private static function enqueue_prod_assets() {
		$js_path   = MOSMCP_PLUGIN_DIR . 'views/build/assets/index.js';
		$css_path  = MOSMCP_PLUGIN_DIR . 'views/build/assets/index.css';
		$build_url = plugins_url( 'views/build/assets/', MOSMCP_PLUGIN_FILE );

		if ( ! file_exists( $js_path ) ) {
			return;
		}

		// Version by file mtime so a rebuilt bundle busts the browser cache even
		// when the plugin version is unchanged.
		$js_ver  = (string) filemtime( $js_path );
		$css_ver = file_exists( $css_path ) ? (string) filemtime( $css_path ) : $js_ver;

		if ( file_exists( $css_path ) ) {
			wp_enqueue_style(
				self::SCRIPT_HANDLE . '-css',
				$build_url . 'index.css',
				array(),
				$css_ver
			);
		}

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			$build_url . 'index.js',
			array(),
			$js_ver,
			true
		);
	}

	/**
	 * Prints the `window.MOSMCP_BOOTSTRAP` inline script.
	 *
	 * @return void
	 */
	public static function print_bootstrap() {
		$bootstrap = self::build_bootstrap();
		$json      = wp_json_encode( $bootstrap );

		echo '<script>window.MOSMCP_BOOTSTRAP=' . $json . ';</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON-encoded by wp_json_encode.
	}

	/**
	 * Builds the bootstrap payload injected into `window.MOSMCP_BOOTSTRAP`.
	 *
	 * @return array<string, mixed>
	 */
	private static function build_bootstrap() {
		$user     = wp_get_current_user();
		$is_admin = current_user_can( 'manage_options' );

		return array(
			'restRoot'               => esc_url_raw( rest_url() ),
			'siteUrl'                => esc_url_raw( Utils::issuer_url() ),
			'restNamespace'          => MOSMCP_REST_NAMESPACE,
			'nonce'                  => wp_create_nonce( 'wp_rest' ),
			'pluginUrl'              => esc_url_raw( plugins_url( '/', MOSMCP_PLUGIN_FILE ) ),
			'version'                => MOSMCP_VERSION,
			'mcpEndpoint'            => Utils::resource_url(),
			'isLocalhost'            => Utils::is_localhost_site(),
			'migratedVersion'        => $is_admin ? (string) get_option( Migration::MIGRATED_VERSION_OPTION, '' ) : '',
			'adminPostUrl'           => $is_admin ? esc_url_raw( admin_url( 'admin-post.php' ) ) : '',
			'downloadDebugLogsNonce' => $is_admin ? wp_create_nonce( Debug_Controller::DOWNLOAD_NONCE_ACTION ) : '',
			'user'                   => array(
				'id'          => (int) $user->ID,
				'displayName' => $user->display_name,
				'isAdmin'     => $is_admin,
				'roles'       => array_values( (array) $user->roles ),
			),
			'abilities'              => self::get_abilities_payload( $user, $is_admin ),
			// Bundled ability sets whose companion plugin is inactive, for the
			// in-app discovery callout. Admin-only; empty for members.
			'dormantPacks'           => $is_admin ? Dormant_Abilities_Provider::packs() : array(),
			// Direct-connection deprecation notice. Admin-only, and absent entirely
			// for sites with no direct connection — so a gateway-only install never
			// sees the notice.
			'directConnection'       => $is_admin ? self::direct_connection_payload() : null,
		);
	}

	/**
	 * Deprecation payload for sites that have a direct connection.
	 *
	 * Returns null when the site has none, so gateway-only and never-connected
	 * installs get no notice at all. `live` separates "someone is using direct right
	 * now" from "direct was used at some point and has gone idle", which the notice
	 * uses to pick its wording.
	 *
	 * @return array{live:bool, clients:string[]}|null
	 */
	private static function direct_connection_payload() {
		$summary = Store::connection_summary();

		// Gated on direct_live, not direct: client rows are never garbage-collected,
		// so a site that tried a direct connection once (or whose registration never
		// completed) keeps that row forever. Counting those would warn people who have
		// since moved to the gateway, every time they open the plugin. An unexpired
		// refresh token is what separates "a direct connection is in use" from "a
		// direct connection was registered here at some point".
		if ( $summary['direct_live'] < 1 ) {
			return null;
		}

		return array(
			'live'    => $summary['direct_live'] > 0,
			'clients' => $summary['direct_names'],
		);
	}

	/**
	 * Returns a JSON-serializable list of registered abilities.
	 *
	 * Admins see the full catalog (they manage NHI ability grants and need to see
	 * every option). Non-admins only see the abilities their own role(s) resolve
	 * to via the NHI grants — the same scope the MCP endpoint and the OAuth
	 * consent screen apply — so a low-privilege member page never leaks the
	 * existence/description of abilities gated behind a higher capability.
	 *
	 * @param WP_User $user     The current user.
	 * @param bool    $is_admin Whether the current user manages options (already
	 *                          resolved by the caller, so this isn't recomputed here).
	 * @return list<array<string, string|bool>>
	 */
	private static function get_abilities_payload( $user, $is_admin ) {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return array();
		}

		$allowed = null;
		if ( ! $is_admin ) {
			$roles   = ( isset( $user->roles ) && is_array( $user->roles ) ) ? array_values( $user->roles ) : array();
			$allowed = NHI_Store::resolve_for_roles( $roles );
		}

		$raw    = wp_get_abilities();
		$result = array();

		foreach ( $raw as $ability ) {
			if ( null !== $allowed && ! in_array( $ability->get_name(), (array) $allowed, true ) ) {
				continue;
			}

			$category = '';
			if ( method_exists( $ability, 'get_category' ) ) {
				$category = (string) $ability->get_category();
			} elseif ( method_exists( $ability, 'get_meta_item' ) ) {
				$meta_cat = $ability->get_meta_item( 'category' );
				$category = is_string( $meta_cat ) ? $meta_cat : '';
			}

			$required_cap = '';
			$object_level = false;
			if ( method_exists( $ability, 'get_meta_item' ) ) {
				$rc           = $ability->get_meta_item( 'required_cap' );
				$required_cap = is_string( $rc ) ? $rc : '';
				$object_level = (bool) $ability->get_meta_item( 'object_level' );
			}

			$result[] = array(
				'name'         => $ability->get_name(),
				'label'        => $ability->get_label(),
				'description'  => $ability->get_description(),
				'category'     => $category,
				'required_cap' => $required_cap,
				'object_level' => $object_level,
			);
		}

		return $result;
	}
}
