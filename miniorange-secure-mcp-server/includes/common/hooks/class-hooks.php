<?php
/**
 * Central registration of the plugin's WordPress actions and filters.
 *
 * @package Miniorange_Secure_MCP_Server
 */

namespace MoSMCP\Common\Hooks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MoSMCP\Abilities\Abilities_Library;
use MoSMCP\Common\Apis\Rest_Routes;
use MoSMCP\Common\Controllers\Debug\Debug_Controller;
use MoSMCP\Common\Migration\Migration;
use MoSMCP\Common\Repositories\Audit_Store;
use MoSMCP\Common\Repositories\Debug_Store;
use MoSMCP\Common\Services\Discovery\Discovery;
use MoSMCP\Common\Services\OAuth\OAuth_Server;
use MoSMCP\Common\Utils\Utils;
use MoSMCP\Common\Views\Admin\Admin_App;
use MoSMCP\Common\Views\Admin\Deactivation_Feedback;

/**
 * Class Hooks
 *
 * Wires every action and filter the plugin uses to the autoloaded class that
 * implements it. Called once from includes/common/loader.php during bootstrap.
 */
class Hooks {

	/** Plugin version the well-known rewrite rules were last flushed for. */
	const REWRITE_VERSION_OPTION = 'mosmcp_rewrite_version';

	/**
	 * Registers all hooks. Invoked from the common loader.
	 *
	 * @return void
	 */
	public static function init() {
		self::register_public_base_filters();

		// Database schema lifecycle. maybe_upgrade also runs on plugins_loaded so the
		// first request of any kind — including an MCP/REST request that never fires
		// admin_init — migrates before the role-scoped resolver reads the grants table.
		register_activation_hook( MOSMCP_PLUGIN_FILE, array( Migration::class, 'install' ) );
		register_activation_hook( MOSMCP_PLUGIN_FILE, array( __CLASS__, 'on_activate' ) );
		register_deactivation_hook( MOSMCP_PLUGIN_FILE, array( __CLASS__, 'unschedule_cron_jobs' ) );
		add_action( 'plugins_loaded', array( Migration::class, 'maybe_upgrade' ) );
		add_action( 'admin_init', array( Migration::class, 'maybe_upgrade' ) );

		// Daily retention cleanup for the audit and debug logs.
		add_action( Audit_Store::CRON_HOOK, array( Audit_Store::class, 'purge' ) );
		add_action( Debug_Store::CRON_HOOK, array( Debug_Store::class, 'purge' ) );

		// REST routes (MCP transport + OAuth server).
		add_action( 'rest_api_init', array( Rest_Routes::class, 'register' ) );

		// Log export for the debug log — a raw file download, so it runs through
		// admin-post.php rather than the REST API (see Debug_Controller).
		add_action( 'admin_post_' . Debug_Controller::DOWNLOAD_ACTION, array( Debug_Controller::class, 'download_logs' ) );

		// Let our OAuth/MCP endpoints begin the handshake even when a security plugin
		// blocks the REST API site-wide (our routes carry their own auth). High priority
		// so this runs after such plugins' own rest_authentication_errors callbacks.
		add_filter( 'rest_authentication_errors', array( __CLASS__, 'allow_plugin_routes' ), 999 );

		// Bundled abilities library. Deferred to plugins_loaded so that if another
		// active plugin also bundles this library, every copy has loaded before we
		// touch the library's classes — the point at which a copy "wins" (and where
		// a version-arbitration step, if present, must already have picked the
		// highest copy). If plugins_loaded has already fired (late activation or a
		// manual include), initialize immediately.
		if ( did_action( 'plugins_loaded' ) ) {
			self::init_abilities_library();
		} else {
			add_action( 'plugins_loaded', array( __CLASS__, 'init_abilities_library' ) );
		}

		// The OAuth authorization endpoint runs through admin-post.php so that the
		// normal cookie session (and is_user_logged_in()) applies; both the logged-in
		// and logged-out actions point at the same handler, which redirects to login
		// when needed.
		add_action( 'admin_post_mosmcp_authorize', array( OAuth_Server::class, 'authorize' ) );
		add_action( 'admin_post_nopriv_mosmcp_authorize', array( OAuth_Server::class, 'authorize' ) );

		// Serve the OAuth discovery documents from the site root well-known paths.
		// Rewrite rules (flushed on activation) let WordPress own these paths; the
		// parse_request handler is the fallback.
		add_action( 'init', array( Discovery::class, 'register_rewrite' ) );
		add_action( 'init', array( __CLASS__, 'maybe_flush_rewrite' ), 20 );
		add_filter( 'query_vars', array( Discovery::class, 'add_query_var' ) );
		add_action( 'parse_request', array( Discovery::class, 'maybe_serve_wellknown' ) );

		// Deactivation feedback modal (plugins.php only).
		add_action( 'admin_footer', array( Deactivation_Feedback::class, 'maybe_render' ) );

		// "Settings" link on the plugin's row on the Plugins screen.
		add_filter( 'plugin_action_links_' . plugin_basename( MOSMCP_PLUGIN_FILE ), array( __CLASS__, 'add_action_links' ) );

		// Admin menu + React app asset hooks.
		add_action( 'admin_menu', array( __CLASS__, 'register_admin_menu' ) );
		Admin_App::register_hooks();
	}

	/**
	 * Initializes the bundled abilities library: registers all first-party ability
	 * packs (core content, users & roles, comments) plus the provider-gated packs
	 * (WooCommerce, ACF, Yoast, forms), each dependency-gated internally. The prefix
	 * scopes every ability name and category to this plugin; text_domain routes the
	 * library's strings through this plugin's own translation catalog.
	 *
	 * Hooked to plugins_loaded (or called directly on late load) — see {@see init()}.
	 *
	 * @return void
	 */
	public static function init_abilities_library() {
		Abilities_Library::init(
			array(
				'prefix'      => 'mosmcp',
				'version'     => MOSMCP_VERSION,
				'text_domain' => 'miniorange-secure-mcp-server',
			)
		);
	}

	/**
	 * Removes both retention-cleanup cron events. Called on deactivation.
	 *
	 * @return void
	 */
	public static function unschedule_cron_jobs() {
		foreach ( array( Audit_Store::CRON_HOOK, Debug_Store::CRON_HOOK ) as $hook ) {
			$timestamp = wp_next_scheduled( $hook );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, $hook );
			}
		}
	}

	/**
	 * Activation tasks: persist the well-known rewrite rules, schedule the daily
	 * log-retention cron jobs, and best-effort ensure the Authorization header
	 * reaches PHP on Apache.
	 *
	 * @return void
	 */
	public static function on_activate() {
		self::maybe_flush_rewrite();
		self::schedule_cron_jobs();
		self::ensure_authorization_htaccess();
	}

	/**
	 * Schedules the daily audit/debug log retention cron jobs if not already
	 * scheduled. Idempotent, so safe to call on every activation (including
	 * reactivation after a deactivation that cleared them).
	 *
	 * @return void
	 */
	private static function schedule_cron_jobs() {
		foreach ( array( Audit_Store::CRON_HOOK, Debug_Store::CRON_HOOK ) as $hook ) {
			if ( ! wp_next_scheduled( $hook ) ) {
				wp_schedule_event( time(), 'daily', $hook );
			}
		}
	}

	/**
	 * Rebuilds the rewrite-rules option once per plugin version — covering both fresh
	 * activation and updates — so the well-known rewrite rules take effect. Soft flush,
	 * so it does not touch .htaccess. (The parse_request handler serves the docs even
	 * before this runs; this just lets WordPress formally own the routes.)
	 *
	 * @return void
	 */
	public static function maybe_flush_rewrite() {
		if ( get_option( self::REWRITE_VERSION_OPTION ) === MOSMCP_VERSION ) {
			return;
		}
		flush_rewrite_rules( false );
		update_option( self::REWRITE_VERSION_OPTION, MOSMCP_VERSION, false );
	}

	/**
	 * Clears another plugin's REST authentication error for this plugin's own
	 * OAuth/MCP endpoints, so a site-wide "REST requires auth" block can't stop the
	 * OAuth handshake. Those endpoints enforce their own authentication.
	 *
	 * @param \WP_Error|null|true $result The current authentication result.
	 * @return \WP_Error|null|true
	 */
	public static function allow_plugin_routes( $result ) {
		if ( ! is_wp_error( $result ) ) {
			return $result;
		}

		$route = '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing check; no state change.
		if ( ! empty( $_GET['rest_route'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$route = sanitize_text_field( wp_unslash( $_GET['rest_route'] ) );
		} elseif ( isset( $GLOBALS['wp'] ) && ! empty( $GLOBALS['wp']->query_vars['rest_route'] ) ) {
			$route = (string) $GLOBALS['wp']->query_vars['rest_route'];
		} elseif ( ! empty( $_SERVER['REQUEST_URI'] ) ) {
			$route = (string) wp_parse_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH );
		}

		$prefix = '/' . MOSMCP_REST_NAMESPACE;
		foreach ( array( $prefix . '/mcp', $prefix . '/token', $prefix . '/register' ) as $needle ) {
			if ( false !== strpos( $route, $needle ) ) {
				return null; // Clear the block; our own permission_callback decides.
			}
		}

		return $result;
	}

	/**
	 * Best-effort: add the rewrite that forwards the Authorization header to PHP on
	 * Apache/LiteSpeed. No-op on other servers or when .htaccess isn't writable.
	 *
	 * @return void
	 */
	public static function ensure_authorization_htaccess() {
		$server = isset( $_SERVER['SERVER_SOFTWARE'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) ) : '';
		if ( false === strpos( $server, 'apache' ) && false === strpos( $server, 'litespeed' ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';

		if ( ! function_exists( 'get_home_path' ) || ! function_exists( 'insert_with_markers' ) ) {
			return;
		}

		$htaccess = get_home_path() . '.htaccess';
		if ( file_exists( $htaccess ) ) {
			if ( ! is_writable( $htaccess ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Pre-flight guard mirrors core's save_mod_rewrite_rules(); insert_with_markers() below requires direct filesystem access.
				return;
			}
		} elseif ( ! is_writable( dirname( $htaccess ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Pre-flight guard mirrors core's save_mod_rewrite_rules(); insert_with_markers() below requires direct filesystem access.
			return;
		}

		$rules = array(
			'<IfModule mod_rewrite.c>',
			'RewriteEngine On',
			'RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]',
			'</IfModule>',
		);

		insert_with_markers( $htaccess, 'miniOrange Secure MCP Server', $rules );
	}

	/**
	 * Prepends a "Settings" link to the plugin's action links on the Plugins screen.
	 *
	 * @param string[] $links Existing action links.
	 * @return string[] The links with a Settings link prepended.
	 */
	public static function add_action_links( $links ) {
		$settings = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=' . Admin_App::PAGE_SLUG ) ),
			esc_html__( 'Settings', 'miniorange-secure-mcp-server' )
		);

		array_unshift( $links, $settings );

		return $links;
	}

	/**
	 * Applies the public base override (testing aid) to WordPress URL generation.
	 *
	 * No-op unless MOSMCP_PUBLIC_BASE is set; see {@see Utils::rewrite_url()}.
	 *
	 * @return void
	 */
	private static function register_public_base_filters() {
		if ( '' === Utils::public_base() ) {
			return;
		}

		$filters = array( 'home_url', 'site_url', 'admin_url', 'rest_url', 'includes_url', 'content_url', 'plugins_url', 'login_url', 'logout_url', 'network_home_url', 'network_site_url', 'wp_redirect' );

		foreach ( $filters as $filter ) {
			add_filter( $filter, array( Utils::class, 'rewrite_url' ), 99 );
		}
	}

	/**
	 * Registers the plugin's top-level admin menu.
	 *
	 * @return void
	 */
	public static function register_admin_menu() {
		$icon = plugins_url( 'views/assets/images/miniorange-logo.png', MOSMCP_PLUGIN_FILE );

		// Admins get the full registry (build NHIs + their own member view via the
		// in-app switcher). Every other logged-in user gets a separate, read-capability
		// slug so the admin surface is never exposed to them; the SPA renders the
		// member "tools available to you" view there.
		if ( current_user_can( 'manage_options' ) ) {
			add_menu_page(
				esc_html__( 'Secure MCP Server', 'miniorange-secure-mcp-server' ),
				esc_attr__( 'Secure MCP Server', 'miniorange-secure-mcp-server' ),
				'manage_options',
				Admin_App::PAGE_SLUG,
				array( Admin_App::class, 'render' ),
				$icon
			);
		} else {
			add_menu_page(
				esc_html__( 'My AI Access', 'miniorange-secure-mcp-server' ),
				esc_attr__( 'My AI Access', 'miniorange-secure-mcp-server' ),
				'read',
				Admin_App::MEMBER_PAGE_SLUG,
				array( Admin_App::class, 'render' ),
				$icon
			);
		}
	}
}
