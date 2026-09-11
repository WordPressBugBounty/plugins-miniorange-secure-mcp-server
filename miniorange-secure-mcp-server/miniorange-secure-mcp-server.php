<?php
/**
 * Plugin Name:       Secure MCP Server for Claude, ChatGPT, Gemini and other AI providers
 * Plugin URI:        https://plugins.miniorange.com/
 * Description:       AI governance and policy enforcement for WordPress, built on the Abilities API. Exposes a secure, OAuth-protected MCP server so AI clients such as ChatGPT and Claude can connect.
 * Version:           1.4.11
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Author:            miniOrange
 * Author URI:        https://www.miniorange.com/
 * License:           Expat
 * License URI:       https://plugins.miniorange.com/mit-license
 * Text Domain:       miniorange-secure-mcp-server
 *
 * @package Miniorange_Secure_MCP_Server
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MOSMCP_VERSION', '1.4.11' );
define( 'MOSMCP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MOSMCP_PLUGIN_FILE', __FILE__ );

/**
 * The REST API namespace under which all MCP and OAuth routes are registered.
 */
define( 'MOSMCP_REST_NAMESPACE', 'mosmcp/v1' );

/**
 * Optional public base URL override (advanced / development).
 *
 * Leave empty for normal operation: the plugin derives its MCP and OAuth URLs
 * from the site's own address ( home_url() ), which is correct on any normally
 * configured site. Set this only when the site is reached at a public address
 * that differs from its stored WordPress Address (for example, behind a
 * development tunnel whose URL is not the configured site URL); when non-empty,
 * the plugin advertises and generates URLs on this base so external MCP clients
 * can reach it.
 *
 * Prefer defining it in wp-config.php rather than editing this file, e.g.:
 *   define( 'MOSMCP_PUBLIC_BASE', 'https://your-tunnel.example/wp' );
 */
if ( ! defined( 'MOSMCP_PUBLIC_BASE' ) ) {
	define( 'MOSMCP_PUBLIC_BASE', '' );
}

// All plugin classes under includes/ are loaded through the Composer classmap.
require_once MOSMCP_PLUGIN_DIR . 'vendor/autoload.php';

// Bundled abilities library. Like the bridge above, this only registers the
// copy and schedules version arbitration (highest bundled copy wins) on
// plugins_loaded; it loads no classes here. Hooks::init() defers the
// Abilities_Library::init() call to plugins_loaded so it runs after arbitration.
// See libraries/abilities/bootstrap.php.
require_once MOSMCP_PLUGIN_DIR . 'libraries/abilities/bootstrap.php';

// Bootstrap the shared (plan-independent) hooks. When plan-specific code is
// added, the matching plans/<plan>/loader.php will be required here too.
require_once MOSMCP_PLUGIN_DIR . 'includes/common/loader.php';
