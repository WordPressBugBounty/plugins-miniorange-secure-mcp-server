<?php
/**
 * Translation loading and the locale pin for agent-facing requests.
 *
 * @package Miniorange_Secure_MCP_Server
 */

namespace MoSMCP\Common\I18n;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MoSMCP\Common\Controllers\MCP\REST_Controller;

/**
 * Class I18n
 *
 * Loads the plugin's bundled translations and keeps our own strings in English on MCP requests.
 * The abilities library's `mosmcp-abilities` domain is deliberately never loaded,
 * so tool labels and descriptions reach agents in English on any site language.
 */
class I18n {

	/** Text domain for every user-facing plugin string. */
	const TEXT_DOMAIN = 'miniorange-secure-mcp-server';

	/**
	 * Registers the hooks. Called from {@see \MoSMCP\Common\Hooks\Hooks::init()}.
	 *
	 * @return void
	 */
	public static function register_hooks() {
		add_action( 'init', array( __CLASS__, 'load_textdomain' ), 0 );
		add_filter( 'override_load_textdomain', array( __CLASS__, 'skip_on_mcp' ), 10, 2 );
	}

	/**
	 * Registers the bundled languages/ folder; a WordPress.org language pack, when present, still wins.
	 *
	 * @return void
	 */
	public static function load_textdomain() {
		load_plugin_textdomain( self::TEXT_DOMAIN, false, dirname( plugin_basename( MOSMCP_PLUGIN_FILE ) ) . '/languages' );
	}

	/**
	 * `override_load_textdomain` filter: agents parse our errors, so our own strings stay English on the MCP endpoint.
	 * Only our domain is skipped. The site language still applies to everything else a call renders (core, themes,
	 * other plugins), such as a theme's block patterns an agent inserts into a page.
	 *
	 * @param bool   $override Whether another callback already handled the load.
	 * @param string $domain   The text domain being loaded.
	 * @return bool
	 */
	public static function skip_on_mcp( $override, $domain ) {
		return self::TEXT_DOMAIN === $domain && REST_Controller::is_mcp_endpoint_request() ? true : $override;
	}

	/**
	 * Absolute path of the bundled languages/ folder.
	 *
	 * @return string
	 */
	public static function languages_dir() {
		return MOSMCP_PLUGIN_DIR . 'languages';
	}
}
