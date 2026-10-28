<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 *
 * Removes the common tables and options, runs the uninstaller of each plan
 * shipped in this build, then sweeps up anything else under the plugin's prefix.
 *
 * @package Miniorange_Secure_MCP_Server
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once plugin_dir_path( __FILE__ ) . 'vendor/autoload.php';

use MoSMCP\Common\Hooks\Hooks;
use MoSMCP\Common\Migration\Migration;
use MoSMCP\Common\Repositories\Audit_Store;
use MoSMCP\Common\Repositories\Debug_Store;
use MoSMCP\Common\Services\OAuth\Tokens;

Migration::drop_tables();

delete_option( 'mosmcp_settings' );
delete_option( Migration::DB_VERSION_OPTION );
delete_option( Migration::GRANTS_BACKFILL_OPTION );
delete_option( Migration::EXCLUSIVITY_BACKFILL_OPTION );
delete_option( Migration::MIGRATED_VERSION_OPTION );
delete_option( Hooks::REWRITE_VERSION_OPTION );
delete_option( Hooks::CRON_RECONCILED_OPTION );
delete_option( Tokens::SALT_CLAIM_OPTION );
delete_option( Audit_Store::OPTION_RETENTION );
delete_option( Debug_Store::OPTION_ENABLED );
delete_option( Debug_Store::OPTION_RETENTION );

wp_clear_scheduled_hook( Audit_Store::CRON_HOOK );
wp_clear_scheduled_hook( Debug_Store::CRON_HOOK );

// Each plan shipped in this build removes its own data.
$mosmcp_plan_uninstallers = glob( plugin_dir_path( __FILE__ ) . 'includes/plans/*/uninstall.php' );
if ( is_array( $mosmcp_plan_uninstallers ) ) {
	foreach ( $mosmcp_plan_uninstallers as $mosmcp_plan_uninstaller ) {
		require $mosmcp_plan_uninstaller;
	}
}

// A site that ran another build earlier can still hold that build's plan data,
// whose uninstaller this build does not ship. Everything under the plugin's
// prefix is the plugin's own, so remove what is left of it.
global $wpdb;

$mosmcp_like = $wpdb->esc_like( 'mosmcp_' ) . '%';

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$mosmcp_tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . $mosmcp_like ) );
foreach ( (array) $mosmcp_tables as $mosmcp_table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $mosmcp_table ) );
}

// Options plus transients and their timeouts.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s',
		$wpdb->options,
		$mosmcp_like,
		$wpdb->esc_like( '_transient_' ) . $mosmcp_like,
		$wpdb->esc_like( '_transient_timeout_' ) . $mosmcp_like
	)
);
wp_cache_delete( 'alloptions', 'options' );
wp_cache_delete( 'notoptions', 'options' );

foreach ( (array) _get_cron_array() as $mosmcp_events ) {
	foreach ( array_keys( (array) $mosmcp_events ) as $mosmcp_hook ) {
		if ( 0 === strpos( (string) $mosmcp_hook, 'mosmcp_' ) ) {
			wp_clear_scheduled_hook( $mosmcp_hook );
		}
	}
}
