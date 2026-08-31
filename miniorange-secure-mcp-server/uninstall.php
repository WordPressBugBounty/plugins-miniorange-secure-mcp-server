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

Migration::drop_tables();

delete_option( 'mosmcp_settings' );
delete_option( 'mosmcp_db_version' );
delete_option( 'mosmcp_nhi_grants_backfilled' );
delete_option( 'mosmcp_ability_exclusivity_reconciled' );
delete_option( 'mosmcp_audit_retention' );
delete_option( 'mosmcp_migrated_version' );

wp_clear_scheduled_hook( 'mosmcp_audit_cleanup' );
