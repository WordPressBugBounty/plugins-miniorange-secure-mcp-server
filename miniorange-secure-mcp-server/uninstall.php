<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 *
 * Removes the OAuth tables (clients, codes, tokens) and the plugin settings
 * option created by the MCP server. No other data is touched.
 *
 * @package Miniorange_Secure_MCP_Server
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once plugin_dir_path( __FILE__ ) . 'vendor/autoload.php';

use MoSMCP\Common\Migration\Migration;
use MoSMCP\Common\Repositories\Audit_Store;
use MoSMCP\Common\Repositories\Debug_Store;

Migration::drop_tables();

delete_option( 'mosmcp_settings' );
delete_option( 'mosmcp_db_version' );
delete_option( 'mosmcp_nhi_grants_backfilled' );
delete_option( 'mosmcp_ability_exclusivity_reconciled' );
delete_option( 'mosmcp_migrated_version' );
delete_option( Audit_Store::OPTION_RETENTION );
delete_option( Debug_Store::OPTION_ENABLED );
delete_option( Debug_Store::OPTION_RETENTION );

wp_clear_scheduled_hook( Audit_Store::CRON_HOOK );
wp_clear_scheduled_hook( Debug_Store::CRON_HOOK );
