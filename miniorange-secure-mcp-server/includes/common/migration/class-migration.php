<?php
/**
 * Database schema management: table creation, upgrades, and teardown.
 *
 * @package Miniorange_Secure_MCP_Server
 */

namespace MoSMCP\Common\Migration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MoSMCP\Common\Repositories\Audit_Store;
use MoSMCP\Common\Repositories\Debug_Store;
use MoSMCP\Common\Repositories\NHI_Store;
use MoSMCP\Common\Repositories\Store;
use MoSMCP\Common\Services\OAuth\Tokens;

/**
 * Class Migration
 *
 * Owns the schema lifecycle for the OAuth server's tables. Table names and CRUD
 * live in {@see Store}; this class only creates, upgrades, and drops the schema.
 */
class Migration {

	/** One-time flag: legacy allowed_abilities JSON has been fanned out into grants. */
	const GRANTS_BACKFILL_OPTION = 'mosmcp_nhi_grants_backfilled';

	/** One-time flag: duplicate ability grants have been deduplicated for exclusivity. */
	const EXCLUSIVITY_BACKFILL_OPTION = 'mosmcp_ability_exclusivity_reconciled';

	/**
	 * Creates or updates the database schema and records the schema version.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$clients         = Store::table( 'clients' );
		$codes           = Store::table( 'codes' );
		$tokens          = Store::table( 'tokens' );
		$nhis            = NHI_Store::table();
		$grants          = NHI_Store::grants_table();
		$audit           = Audit_Store::table();
		$debug           = Debug_Store::table();

		// Pin the engine so the schema never inherits the server default. The grants table's
		// four-column unique key is ~1669 bytes under utf8mb4, and MyISAM caps a composite key
		// at 1000 bytes *in total* — it rejects the CREATE TABLE outright, which dbDelta then
		// reports as nothing at all, leaving the table silently absent. InnoDB's 767/3072 limit
		// applies per column, and every column here clears it (varchar(191) utf8mb4 = 764 bytes,
		// which is why WordPress standardised on 191), so InnoDB accepts this key in any row
		// format. ROW_FORMAT=DYNAMIC is pinned for consistency, not to make the key fit.
		// Table options apply at creation only, so existing installs need no migration.
		$table_opts = "ENGINE=InnoDB {$charset_collate} ROW_FORMAT=DYNAMIC";

		$sql = array();

		$sql[] = "CREATE TABLE {$clients} (
			client_id varchar(64) NOT NULL,
			client_secret_hash varchar(64) DEFAULT NULL,
			client_name varchar(255) NOT NULL DEFAULT '',
			redirect_uris longtext NOT NULL,
			grant_types varchar(255) NOT NULL DEFAULT '',
			token_endpoint_auth_method varchar(40) NOT NULL DEFAULT 'none',
			created bigint(20) unsigned NOT NULL DEFAULT 0,
			is_enabled tinyint(1) NOT NULL DEFAULT 1,
			allowed_abilities longtext DEFAULT NULL,
			PRIMARY KEY  (client_id)
		) {$table_opts};";

		$sql[] = "CREATE TABLE {$codes} (
			code_hash varchar(64) NOT NULL,
			client_id varchar(64) NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			redirect_uri text NOT NULL,
			code_challenge varchar(255) NOT NULL DEFAULT '',
			scope varchar(255) NOT NULL DEFAULT '',
			resource text NOT NULL,
			expires bigint(20) unsigned NOT NULL DEFAULT 0,
			used tinyint(1) NOT NULL DEFAULT 0,
			PRIMARY KEY  (code_hash)
		) {$table_opts};";

		$sql[] = "CREATE TABLE {$tokens} (
			token_hash varchar(64) NOT NULL,
			type varchar(10) NOT NULL,
			client_id varchar(64) NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			scope varchar(255) NOT NULL DEFAULT '',
			resource text NOT NULL,
			expires bigint(20) unsigned NOT NULL DEFAULT 0,
			parent_hash varchar(64) DEFAULT NULL,
			PRIMARY KEY  (token_hash),
			KEY type (type),
			KEY expires (expires)
		) {$table_opts};";

		// NHIs (role-scoped ability policies). Per-role grants live in the grants table;
		// allowed_abilities is a legacy column kept unused so the RBAC upgrade stays
		// additive. uuid = public id, id = internal.
		$sql[] = "CREATE TABLE {$nhis} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			uuid varchar(36) NOT NULL DEFAULT '',
			name varchar(255) NOT NULL DEFAULT '',
			allowed_abilities longtext DEFAULT NULL,
			is_enabled tinyint(1) NOT NULL DEFAULT 1,
			created bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY uuid (uuid)
		) {$table_opts};";

		// Normalized grants: one row per (nhi_id, role, resource_type, resource).
		// `resource` holds the identifier for its resource_type ('ability' today,
		// 'rest_route' later). Reserved sentinel: resource '*' = all abilities of
		// that type (used by the upgrade backfill to grant Administrators everything).
		$sql[] = "CREATE TABLE {$grants} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			nhi_id bigint(20) unsigned NOT NULL,
			role varchar(191) NOT NULL DEFAULT '',
			resource_type varchar(32) NOT NULL DEFAULT 'ability',
			resource varchar(191) NOT NULL DEFAULT '',
			created bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY nhi_role_resource (nhi_id,role,resource_type,resource),
			KEY role (role),
			KEY resource (resource),
			KEY nhi_id (nhi_id),
			KEY type_role (resource_type,role)
		) {$table_opts};";

		// MCP tool-call audit log: one row per tools/call invocation.
		// nhi_id/nhi_uuid/nhi_name are snapshotted so the log stays readable after NHI deletion.
		$sql[] = "CREATE TABLE {$audit} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_id varchar(36) NOT NULL DEFAULT '',
			created bigint(20) unsigned NOT NULL DEFAULT 0,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			user_login varchar(191) NOT NULL DEFAULT '',
			nhi_id bigint(20) unsigned DEFAULT NULL,
			nhi_uuid varchar(36) NOT NULL DEFAULT '',
			nhi_name varchar(255) NOT NULL DEFAULT '',
			client_id varchar(64) NOT NULL DEFAULT '',
			client_name varchar(255) NOT NULL DEFAULT '',
			tool_name varchar(191) NOT NULL DEFAULT '',
			status varchar(16) NOT NULL DEFAULT 'success',
			error_code varchar(64) DEFAULT NULL,
			error_message text DEFAULT NULL,
			latency_ms int(10) unsigned DEFAULT NULL,
			ip_address varchar(45) DEFAULT NULL,
			request_id varchar(64) DEFAULT NULL,
			meta longtext DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY event_id (event_id),
			KEY created (created),
			KEY user_id (user_id),
			KEY nhi_id (nhi_id),
			KEY nhi_uuid (nhi_uuid),
			KEY tool_name (tool_name),
			KEY status (status),
			KEY user_created (user_id,created),
			KEY nhi_created (nhi_id,created)
		) {$table_opts};";

		// Internal debug log: developer-facing diagnostic events, independent of
		// WP_DEBUG. client_id/client_name/user_login are snapshotted (like the
		// audit log) so entries stay readable after the client or user is deleted.
		$sql[] = "CREATE TABLE {$debug} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created bigint(20) unsigned NOT NULL DEFAULT 0,
			level varchar(10) NOT NULL DEFAULT 'info',
			channel varchar(50) NOT NULL DEFAULT 'general',
			message longtext NOT NULL,
			context longtext NOT NULL,
			request_id varchar(36) NOT NULL DEFAULT '',
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			user_login varchar(191) NOT NULL DEFAULT '',
			client_id varchar(64) NOT NULL DEFAULT '',
			client_name varchar(255) NOT NULL DEFAULT '',
			ip_address varchar(45) DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY created (created),
			KEY level (level),
			KEY channel (channel),
			KEY request_id (request_id),
			KEY client_id (client_id)
		) {$table_opts};";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}

		self::migrate_clients_to_nhis();
		self::backfill_nhi_uuids();
		self::backfill_nhi_grants();
		self::deduplicate_ability_grants();

		Tokens::ensure_salt();

		update_option( 'mosmcp_db_version', MOSMCP_VERSION, false );
	}

	/**
	 * One-time migration of legacy auto-created OAuth clients into NHIs.
	 *
	 * Older versions treated each OAuth client row as an NHI (with its own
	 * is_enabled / allowed_abilities). NHIs are now a separate admin-managed
	 * entity. On upgrade, when the NHI table is still empty, every existing
	 * client becomes an NHI so current connections keep working unchanged.
	 *
	 * @return void
	 */
	private static function migrate_clients_to_nhis() {
		global $wpdb;

		$clients = Store::table( 'clients' );
		$nhis    = NHI_Store::table();

		// Only seed when the NHI table is empty.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $nhis ) );
		if ( $existing > 0 ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT client_name, is_enabled, allowed_abilities, created FROM %i', $clients ),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return;
		}

		foreach ( $rows as $row ) {
			$name = '' !== (string) $row['client_name'] ? (string) $row['client_name'] : __( 'Migrated client', 'miniorange-secure-mcp-server' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->insert(
				$nhis,
				array(
					'name'              => $name,
					'allowed_abilities' => $row['allowed_abilities'],
					'is_enabled'        => (int) $row['is_enabled'],
					'created'           => (int) $row['created'],
				)
			);
		}
	}

	/**
	 * Assigns a UUID to any NHI row missing one (older rows / client-seeded rows).
	 *
	 * @return void
	 */
	private static function backfill_nhi_uuids() {
		global $wpdb;

		$nhis = NHI_Store::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare( 'SELECT id FROM %i WHERE uuid = %s', $nhis, '' )
		);

		foreach ( (array) $ids as $id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update( $nhis, array( 'uuid' => wp_generate_uuid4() ), array( 'id' => (int) $id ) );
		}
	}

	/**
	 * One-time (option-guarded) RBAC backfill: fan legacy allowed_abilities JSON into
	 * grant rows. Pre-RBAC abilities applied to every connecting user; on upgrade we
	 * deliberately narrow that to the Administrator role only, so an upgrade never
	 * silently widens access — the admin reviews and grants other roles explicitly.
	 * NULL → ('administrator','*'); flat array → ('administrator',ability);
	 * map → per-role (unchanged). INSERT IGNORE makes a re-run a no-op.
	 *
	 * @return void
	 */
	private static function backfill_nhi_grants() {
		global $wpdb;

		if ( get_option( self::GRANTS_BACKFILL_OPTION ) ) {
			return;
		}

		$nhis = NHI_Store::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT id, allowed_abilities FROM %i', $nhis ),
			ARRAY_A
		);

		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$nhi_id = (int) $row['id'];
				$raw    = $row['allowed_abilities'];

				if ( null === $raw ) {
					// Pre-RBAC "no restriction" → all abilities, Administrator only.
					NHI_Store::add_grant( $nhi_id, 'administrator', '*' );
					continue;
				}

				$decoded = json_decode( (string) $raw, true );
				if ( ! is_array( $decoded ) ) {
					continue;
				}

				if ( self::is_json_list( $decoded ) ) {
					// Legacy flat array — applied to everyone; narrow to Administrator.
					foreach ( $decoded as $ability ) {
						NHI_Store::add_grant( $nhi_id, 'administrator', (string) $ability );
					}
				} else {
					// Already a role => abilities map.
					foreach ( $decoded as $role => $abilities ) {
						if ( ! is_array( $abilities ) ) {
							continue;
						}
						foreach ( $abilities as $ability ) {
							NHI_Store::add_grant( $nhi_id, (string) $role, (string) $ability );
						}
					}
				}
			}
		}

		update_option( self::GRANTS_BACKFILL_OPTION, 1, false );
	}

	/**
	 * One-time (option-guarded) exclusivity reconciliation: when the same ability
	 * appears in grants belonging to multiple NHIs, keep it only on the NHI with
	 * the lowest id (oldest) and remove it from all others.
	 *
	 * Wildcard ('*') grants are intentionally left untouched — they are a legacy
	 * pre-RBAC sentinel and do not participate in the exclusivity model.
	 *
	 * After this runs, each explicit non-wildcard ability resource belongs to at
	 * most one NHI, which makes audit attribution unambiguous.
	 *
	 * @return void
	 */
	private static function deduplicate_ability_grants() {
		global $wpdb;

		if ( get_option( self::EXCLUSIVITY_BACKFILL_OPTION ) ) {
			return;
		}

		$grants = NHI_Store::grants_table();

		// Delete grant rows where a lower nhi_id already holds a grant for the same
		// (ability, role) pair. The same ability in a different role is not a conflict.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE g1 FROM %i g1
				 INNER JOIN %i g2
				    ON g2.resource_type = 'ability'
				   AND g2.resource      = g1.resource
				   AND g2.resource     != '*'
				   AND g2.role          = g1.role
				   AND g2.nhi_id        < g1.nhi_id
				 WHERE g1.resource_type = 'ability'
				   AND g1.resource     != '*'",
				$grants,
				$grants
			)
		);

		update_option( self::EXCLUSIVITY_BACKFILL_OPTION, 1, false );
	}

	/**
	 * Whether a decoded JSON value is a list vs a map (PHP 7.4 array_is_list()).
	 *
	 * @param array $arr Decoded JSON array.
	 * @return bool
	 */
	private static function is_json_list( array $arr ) {
		if ( array() === $arr ) {
			return true;
		}
		return array_keys( $arr ) === range( 0, count( $arr ) - 1 );
	}

	/**
	 * Runs install() when the stored schema version differs. Hooked on plugins_loaded
	 * (so an MCP/REST request migrates before the resolver runs) and admin_init.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		$stored = get_option( 'mosmcp_db_version' );
		if ( $stored !== MOSMCP_VERSION ) {
			if ( false !== $stored && version_compare( (string) $stored, MOSMCP_VERSION, '<' ) ) {
				// Flag the upgrade so the app shows an in-registry migration notice.
				update_option( 'mosmcp_migrated_version', MOSMCP_VERSION, false );
			}
			self::install();
		}
	}

	/**
	 * Drops all plugin tables. Used on uninstall only.
	 *
	 * @return void
	 */
	public static function drop_tables() {
		global $wpdb;

		$tables = array( Store::table( 'clients' ), Store::table( 'codes' ), Store::table( 'tokens' ), NHI_Store::grants_table(), NHI_Store::table(), Audit_Store::table(), Debug_Store::table() );

		foreach ( $tables as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
		}
	}
}
