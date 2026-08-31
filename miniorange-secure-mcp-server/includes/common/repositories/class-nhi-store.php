<?php
/**
 * Persistence layer for NHIs (Non-Human Identities) and their role-based grants.
 *
 * @package Miniorange_Secure_MCP_Server
 */

namespace MoSMCP\Common\Repositories;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * NHIs + their normalized role→ability grants (wp_mosmcp_nhis, wp_mosmcp_nhi_grants).
 *
 * A request's abilities = union, across enabled NHIs, of grants matching the
 * connecting user's role(s); see resolve_for_roles(). Reserved sentinels (written
 * only by migration): role '*' = any role, resource '*' = all.
 */
class NHI_Store {

	/**
	 * Returns the prefixed NHI table name.
	 *
	 * @return string Fully prefixed table name.
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'mosmcp_nhis';
	}

	/**
	 * Returns the prefixed NHI grants (junction) table name.
	 *
	 * @return string Fully prefixed table name.
	 */
	public static function grants_table() {
		global $wpdb;

		return $wpdb->prefix . 'mosmcp_nhi_grants';
	}

	/**
	 * Returns all NHIs, newest first, each with its role => abilities map.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function list_all() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT id, uuid, name, is_enabled, created FROM %i ORDER BY created DESC, id DESC', self::table() ),
			ARRAY_A
		);

		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return array();
		}

		$grants_by_nhi = self::grants_map_for( wp_list_pluck( $rows, 'id' ) );

		$out = array();
		foreach ( $rows as $row ) {
			$id    = (int) $row['id'];
			$out[] = self::shape( $row, isset( $grants_by_nhi[ $id ] ) ? $grants_by_nhi[ $id ] : array() );
		}

		return $out;
	}

	/**
	 * Fetches a single NHI by its numeric id, with its role => abilities map.
	 *
	 * @param int $id The NHI id.
	 * @return array<string, mixed>|null Shaped NHI row, or null when missing.
	 */
	public static function get( $id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT id, uuid, name, is_enabled, created FROM %i WHERE id = %d', self::table(), $id ),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		$grants_by_nhi = self::grants_map_for( array( (int) $row['id'] ) );

		return self::shape( $row, isset( $grants_by_nhi[ (int) $row['id'] ] ) ? $grants_by_nhi[ (int) $row['id'] ] : array() );
	}

	/**
	 * Inserts a new NHI and its per-role grants.
	 *
	 * @param array{name:string, role_ability_map?:array<string,string[]>} $data NHI fields.
	 * @return array{id:int,uuid:string}|false Inserted id + uuid, or false on failure.
	 */
	public static function create( array $data ) {
		global $wpdb;

		$uuid = wp_generate_uuid4();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ok = $wpdb->insert(
			self::table(),
			array(
				'uuid'       => $uuid,
				'name'       => isset( $data['name'] ) ? (string) $data['name'] : '',
				'is_enabled' => 1,
				'created'    => time(),
			)
		);

		if ( ! $ok ) {
			return false;
		}

		$id = (int) $wpdb->insert_id;

		if ( isset( $data['role_ability_map'] ) && is_array( $data['role_ability_map'] ) ) {
			self::replace_grants( $id, $data['role_ability_map'] );
		}

		return array(
			'id'   => $id,
			'uuid' => $uuid,
		);
	}

	/**
	 * Updates an NHI. Recognized keys: name, is_enabled (entity columns) and
	 * role_ability_map (replaces the NHI's grants wholesale).
	 *
	 * @param int                  $id   The NHI id.
	 * @param array<string, mixed> $data Fields to update.
	 * @return bool True on success.
	 */
	public static function update( $id, array $data ) {
		global $wpdb;

		$columns = array();
		if ( array_key_exists( 'name', $data ) ) {
			$columns['name'] = (string) $data['name'];
		}
		if ( array_key_exists( 'is_enabled', $data ) ) {
			$columns['is_enabled'] = $data['is_enabled'] ? 1 : 0;
		}

		$ok = true;
		if ( ! empty( $columns ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->update( self::table(), $columns, array( 'id' => $id ) );
			$ok     = false !== $result;
		}

		if ( array_key_exists( 'role_ability_map', $data ) && is_array( $data['role_ability_map'] ) ) {
			self::replace_grants( (int) $id, $data['role_ability_map'] );
		}

		return $ok;
	}

	/**
	 * Deletes an NHI and all of its grants.
	 *
	 * @param int $id The NHI id.
	 * @return void
	 */
	public static function delete( $id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( self::grants_table(), array( 'nhi_id' => $id ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( self::table(), array( 'id' => $id ) );
	}

	/**
	 * Returns the total number of NHIs.
	 *
	 * @return int NHI count.
	 */
	public static function count_all() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', self::table() ) );
	}

	/**
	 * Returns the number of enabled NHIs.
	 *
	 * @return int Enabled NHI count.
	 */
	public static function count_enabled() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE is_enabled = 1', self::table() )
		);
	}

	/**
	 * Ability allow-list for a request: the union, across enabled NHIs, of abilities
	 * granted to the user's role(s) or to '*'. Returns null (unrestricted) if a
	 * migrated '*' ability grant applies.
	 *
	 * @param string[] $roles The user's role slugs.
	 * @return string[]|null Ability names, or null for unrestricted.
	 */
	public static function resolve_for_roles( array $roles ) {
		global $wpdb;

		list( $role_args, $role_ph ) = self::role_args_and_placeholders( $roles );

		$sql = "SELECT DISTINCT g.resource
			FROM %i g
			INNER JOIN %i n ON n.id = g.nhi_id
			WHERE n.is_enabled = 1 AND g.resource_type = 'ability' AND g.role IN ( {$role_ph} )";

		$args = array_merge( array( self::grants_table(), self::table() ), $role_args );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$abilities = $wpdb->get_col( $wpdb->prepare( $sql, $args ) );

		if ( ! is_array( $abilities ) ) {
			return array();
		}

		// '*' ability (migrated "no restriction" NHI) = unrestricted.
		if ( in_array( '*', $abilities, true ) ) {
			return null;
		}

		return array_values( $abilities );
	}

	/**
	 * Returns the first enabled NHI (lowest id) that has at least one ability grant
	 * for the given role(s) or the '*' sentinel. Used to attribute denied/unknown-tool
	 * events to the NHI context the user is operating in.
	 *
	 * @param string[] $roles The user's role slugs.
	 * @return array{id:int,uuid:string,name:string}|null
	 */
	public static function primary_for_roles( array $roles ) {
		global $wpdb;

		list( $role_args, $role_ph ) = self::role_args_and_placeholders( $roles );

		$sql  = "SELECT n.id, n.uuid, n.name
				 FROM %i g
				 INNER JOIN %i n ON n.id = g.nhi_id
				 WHERE n.is_enabled = 1 AND g.resource_type = 'ability' AND g.role IN ( {$role_ph} )
				 ORDER BY n.id ASC
				 LIMIT 1";
		$args = array_merge( array( self::grants_table(), self::table() ), $role_args );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $args ), ARRAY_A );

		if ( ! is_array( $row ) ) {
			return null;
		}

		return self::shape_nhi_ref( $row );
	}

	/**
	 * Returns, for the given role(s), the enabled NHIs that grant abilities to those
	 * role(s), grouped per NHI. Powers the member "tools available to you" view.
	 *
	 * @param string[] $roles The user's role slugs.
	 * @return list<array{uuid:string,name:string,abilities:string[]}>
	 */
	public static function access_for_roles( array $roles ) {
		global $wpdb;

		list( $role_args, $role_ph ) = self::role_args_and_placeholders( $roles );

		$sql = "SELECT n.uuid, n.name, g.resource
			FROM %i g
			INNER JOIN %i n ON n.id = g.nhi_id
			WHERE n.is_enabled = 1 AND g.resource_type = 'ability' AND g.role IN ( {$role_ph} )
			ORDER BY n.created DESC, n.id DESC, g.resource ASC";

		$args = array_merge( array( self::grants_table(), self::table() ), $role_args );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );

		$by_uuid = array();
		foreach ( (array) $rows as $row ) {
			$uuid = (string) $row['uuid'];
			if ( ! isset( $by_uuid[ $uuid ] ) ) {
				$by_uuid[ $uuid ] = array(
					'uuid'      => $uuid,
					'name'      => (string) $row['name'],
					'abilities' => array(),
				);
			}
			$by_uuid[ $uuid ]['abilities'][] = (string) $row['resource'];
		}

		return array_values( $by_uuid );
	}

	/**
	 * Connected users this NHI reaches: users with an active token whose role(s)
	 * the NHI grants abilities to.
	 *
	 * @param int $nhi_id The NHI id.
	 * @return list<array{user_id:int,display_name:string,email:string,roles:string[],clients:string[]}>
	 */
	public static function members_for_nhi( $nhi_id ) {
		global $wpdb;

		// Roles this NHI grants to ('*' role = every user).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$grants = $wpdb->get_results(
			$wpdb->prepare( 'SELECT role, resource FROM %i WHERE nhi_id = %d AND resource_type = %s', self::grants_table(), (int) $nhi_id, 'ability' ),
			ARRAY_A
		);

		$reached   = array();
		$reach_all = false;
		foreach ( (array) $grants as $g ) {
			if ( '' === (string) $g['resource'] ) {
				continue;
			}
			if ( '*' === (string) $g['role'] ) {
				$reach_all = true;
				continue;
			}
			$reached[ (string) $g['role'] ] = true;
		}

		if ( empty( $reached ) && ! $reach_all ) {
			return array();
		}

		// Users with a live token = connected.
		$tokens = Store::table( 'tokens' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT DISTINCT user_id, client_id FROM %i WHERE expires >= %d', $tokens, time() ),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return array();
		}

		$clients_table = Store::table( 'clients' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$client_rows = $wpdb->get_results( $wpdb->prepare( 'SELECT client_id, client_name FROM %i', $clients_table ), ARRAY_A );
		$client_name = array();
		foreach ( (array) $client_rows as $c ) {
			$client_name[ (string) $c['client_id'] ] = '' !== (string) $c['client_name'] ? (string) $c['client_name'] : (string) $c['client_id'];
		}

		$by_user = array();
		foreach ( (array) $rows as $row ) {
			$uid  = (int) $row['user_id'];
			$user = get_userdata( $uid );
			if ( ! $user ) {
				continue;
			}

			$roles      = is_array( $user->roles ) ? array_values( $user->roles ) : array();
			$is_reached = $reach_all;
			if ( ! $is_reached ) {
				foreach ( $roles as $r ) {
					if ( isset( $reached[ $r ] ) ) {
						$is_reached = true;
						break;
					}
				}
			}
			if ( ! $is_reached ) {
				continue;
			}

			if ( ! isset( $by_user[ $uid ] ) ) {
				$by_user[ $uid ] = array(
					'user_id'      => $uid,
					'display_name' => '' !== $user->display_name ? $user->display_name : $user->user_login,
					'email'        => (string) $user->user_email,
					'roles'        => $roles,
					'clients'      => array(),
				);
			}

			$cn = isset( $client_name[ (string) $row['client_id'] ] ) ? $client_name[ (string) $row['client_id'] ] : (string) $row['client_id'];
			if ( '' !== $cn && ! in_array( $cn, $by_user[ $uid ]['clients'], true ) ) {
				$by_user[ $uid ]['clients'][] = $cn;
			}
		}

		return array_values( $by_user );
	}

	/**
	 * Returns the NHI that owns the given ability for the specified user roles.
	 *
	 * When $user_roles is provided the lookup is narrowed to NHIs that grant the
	 * ability to at least one of those roles, ensuring the correct NHI is attributed
	 * in the audit log when the same ability exists under different roles in multiple
	 * NHIs. Falls back to any matching NHI when $user_roles is empty.
	 *
	 * @param string   $ability    Ability name (e.g. 'mosmcp/post-create-draft').
	 * @param string[] $user_roles WordPress role slugs for the authenticated user.
	 * @return array{id:int,uuid:string,name:string}|null
	 */
	public static function owner_for_ability( $ability, array $user_roles = array() ) {
		global $wpdb;

		$user_roles = array_values( array_filter( array_map( 'strval', $user_roles ), 'strlen' ) );

		if ( ! empty( $user_roles ) ) {
			$ph   = self::placeholders( count( $user_roles ) );
			$args = array_merge(
				array( self::grants_table(), self::table(), (string) $ability ),
				$user_roles
			);
			$sql  = "SELECT n.id, n.uuid, n.name
					 FROM %i g
					 INNER JOIN %i n ON n.id = g.nhi_id
					 WHERE g.resource_type = 'ability'
					   AND g.resource      = %s
					   AND g.role          IN ( {$ph} )
					   AND n.is_enabled    = 1
					 ORDER BY n.id ASC
					 LIMIT 1";
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$row = $wpdb->get_row( $wpdb->prepare( $sql, $args ), ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT n.id, n.uuid, n.name
					 FROM %i g
					 INNER JOIN %i n ON n.id = g.nhi_id
					 WHERE g.resource_type = 'ability'
					   AND g.resource      = %s
					   AND n.is_enabled    = 1
					 ORDER BY n.id ASC
					 LIMIT 1",
					self::grants_table(),
					self::table(),
					(string) $ability
				),
				ARRAY_A
			);
		}

		if ( ! $row ) {
			return null;
		}

		return self::shape_nhi_ref( $row );
	}

	/**
	 * Returns the subset of role/ability pairs in $role_ability_map that are already
	 * owned by an NHI other than $nhi_id, keyed as "ability::role".
	 *
	 * Exclusivity is enforced at the (ability, role) level: the same ability may be
	 * assigned to the same role by at most one NHI, but it may exist under a different
	 * role in another NHI simultaneously.
	 *
	 * Pass $nhi_id = 0 when checking for a new NHI that has no id yet.
	 *
	 * @param int                     $nhi_id           ID of the NHI being created/updated (0 for new).
	 * @param array<string, string[]> $role_ability_map Role slug => ability names.
	 * @return array<string, array{uuid:string,name:string}> Conflicting pairs keyed "ability::role".
	 */
	public static function abilities_owned_by_others( $nhi_id, array $role_ability_map ) {
		global $wpdb;

		// Build the flat set of (role, ability) pairs and collect unique ability names.
		$pairs         = array();
		$all_abilities = array();
		foreach ( $role_ability_map as $role => $abilities ) {
			foreach ( (array) $abilities as $ab ) {
				$ab = (string) $ab;
				if ( '' !== $ab ) {
					$pairs[]         = array(
						'role'    => (string) $role,
						'ability' => $ab,
					);
					$all_abilities[] = $ab;
				}
			}
		}

		if ( empty( $pairs ) ) {
			return array();
		}

		$nhi_id        = (int) $nhi_id;
		$all_abilities = array_values( array_unique( $all_abilities ) );
		$ph            = self::placeholders( count( $all_abilities ) );

		// Fetch all grants from other enabled NHIs for abilities appearing in our map.
		// Role-level filtering is done in PHP to avoid dynamic tuple IN clauses.
		$args = array_merge( array( self::grants_table(), self::table() ), $all_abilities, array( $nhi_id ) );
		$sql  = "SELECT g.role, g.resource AS ability, n.uuid, n.name
				 FROM %i g
				 INNER JOIN %i n ON n.id = g.nhi_id
				 WHERE g.resource_type = 'ability'
				   AND g.resource     != '*'
				   AND g.resource      IN ( {$ph} )
				   AND g.nhi_id        != %d
				   AND n.is_enabled    = 1
				 ORDER BY n.id ASC";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );

		// Index the pairs we are checking as "role::ability" for O(1) lookup.
		$pair_index = array();
		foreach ( $pairs as $p ) {
			$pair_index[ $p['role'] . '::' . $p['ability'] ] = true;
		}

		// Collect conflicts: only (role, ability) pairs that appear in our new map.
		$map = array();
		foreach ( (array) $rows as $row ) {
			$index_key = (string) $row['role'] . '::' . (string) $row['ability'];
			$out_key   = (string) $row['ability'] . '::' . (string) $row['role'];
			if ( isset( $pair_index[ $index_key ] ) && ! isset( $map[ $out_key ] ) ) {
				$map[ $out_key ] = self::shape_owner( $row );
			}
		}

		return $map;
	}

	/**
	 * Returns a map of every explicitly-granted, non-wildcard (ability, role) pair to
	 * the enabled NHI that owns it, keyed as "ability::role".
	 *
	 * Exclusivity is per (ability, role): the same ability may appear under a different
	 * role in another NHI without conflict. The frontend uses this map to lock
	 * already-assigned entries in the ability picker for the role currently being edited.
	 *
	 * @return array<string, array{uuid:string,name:string}>
	 */
	public static function all_ability_owners() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT g.resource AS ability, g.role, n.uuid, n.name
				 FROM %i g
				 INNER JOIN %i n ON n.id = g.nhi_id
				 WHERE g.resource_type = 'ability'
				   AND g.resource     != '*'
				   AND n.is_enabled    = 1
				 GROUP BY g.resource, g.role
				 ORDER BY n.id ASC",
				self::grants_table(),
				self::table()
			),
			ARRAY_A
		);

		$map = array();
		foreach ( (array) $rows as $row ) {
			$key = (string) $row['ability'] . '::' . (string) $row['role'];
			if ( ! isset( $map[ $key ] ) ) {
				$map[ $key ] = self::shape_owner( $row );
			}
		}

		return $map;
	}

	/**
	 * Inserts a grant; INSERT IGNORE makes repeated/concurrent calls safe.
	 *
	 * @param int    $nhi_id        The NHI id.
	 * @param string $role          Role slug, or '*'.
	 * @param string $ability       Ability name (resource identifier), or '*'.
	 * @param string $resource_type Grant type (default 'ability').
	 * @return void
	 */
	public static function add_grant( $nhi_id, $role, $ability, $resource_type = 'ability' ) {
		global $wpdb;

		$ability = (string) $ability;
		if ( '' === $ability ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO %i (nhi_id, role, resource_type, resource, created) VALUES (%d, %s, %s, %s, %d)',
				self::grants_table(),
				(int) $nhi_id,
				(string) $role,
				(string) $resource_type,
				$ability,
				time()
			)
		);
	}

	/**
	 * Internal id for a public UUID (server-side only — never expose the id), or null.
	 *
	 * @param string $uuid The public UUID.
	 * @return int|null
	 */
	public static function find_id_by_uuid( $uuid ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$id = $wpdb->get_var(
			$wpdb->prepare( 'SELECT id FROM %i WHERE uuid = %s', self::table(), $uuid )
		);

		return null !== $id ? (int) $id : null;
	}

	/**
	 * Replaces all grants for one NHI with the given role => abilities map.
	 *
	 * @param int                     $nhi_id The NHI id.
	 * @param array<string, string[]> $map    Role slug => list of ability names.
	 * @return void
	 */
	private static function replace_grants( $nhi_id, array $map ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( self::grants_table(), array( 'nhi_id' => (int) $nhi_id ) );

		foreach ( $map as $role => $abilities ) {
			if ( ! is_array( $abilities ) ) {
				continue;
			}
			foreach ( $abilities as $ability ) {
				self::add_grant( (int) $nhi_id, (string) $role, (string) $ability );
			}
		}
	}

	/**
	 * Loads grants for the given NHI ids, grouped as [ nhi_id => [ role => [abilities] ] ].
	 *
	 * @param array<int|string> $ids NHI ids.
	 * @return array<int, array<string, string[]>>
	 */
	private static function grants_map_for( array $ids ) {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( empty( $ids ) ) {
			return array();
		}

		$id_ph = self::placeholders( count( $ids ), '%d' );
		$sql   = "SELECT nhi_id, role, resource FROM %i WHERE resource_type = 'ability' AND nhi_id IN ( {$id_ph} )";
		$args  = array_merge( array( self::grants_table() ), $ids );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );

		$map = array();
		foreach ( (array) $rows as $row ) {
			$nid  = (int) $row['nhi_id'];
			$role = (string) $row['role'];
			if ( ! isset( $map[ $nid ] ) ) {
				$map[ $nid ] = array();
			}
			if ( ! isset( $map[ $nid ][ $role ] ) ) {
				$map[ $nid ][ $role ] = array();
			}
			$map[ $nid ][ $role ][] = (string) $row['resource'];
		}

		return $map;
	}

	/**
	 * Shapes a row for the API: drops the internal `id`, adds `role_ability_map`.
	 *
	 * @param array<string, mixed>    $row              Raw DB row.
	 * @param array<string, string[]> $role_ability_map Role => abilities.
	 * @return array<string, mixed> Shaped row.
	 */
	private static function shape( array $row, array $role_ability_map = array() ) {
		return array(
			'uuid'             => (string) $row['uuid'],
			'name'             => (string) $row['name'],
			'is_enabled'       => (int) $row['is_enabled'],
			'created'          => (int) $row['created'],
			'role_ability_map' => $role_ability_map,
		);
	}

	/**
	 * Shapes a joined nhis row to its id/uuid/name reference form.
	 *
	 * @param array<string, mixed> $row Raw row with at least id, uuid, name.
	 * @return array{id:int,uuid:string,name:string}
	 */
	private static function shape_nhi_ref( array $row ) {
		return array(
			'id'   => (int) $row['id'],
			'uuid' => (string) $row['uuid'],
			'name' => (string) $row['name'],
		);
	}

	/**
	 * Shapes a joined nhis row to its uuid/name owner form.
	 *
	 * @param array<string, mixed> $row Raw row with at least uuid, name.
	 * @return array{uuid:string,name:string}
	 */
	private static function shape_owner( array $row ) {
		return array(
			'uuid' => (string) $row['uuid'],
			'name' => (string) $row['name'],
		);
	}

	/**
	 * Normalizes role slugs, prepends the '*' (any-role) sentinel, and builds the
	 * matching SQL IN(...) placeholder list.
	 *
	 * @param string[] $roles Raw role slugs.
	 * @return array{0: string[], 1: string} [bind args, placeholder SQL fragment].
	 */
	private static function role_args_and_placeholders( array $roles ) {
		$roles     = array_values( array_unique( array_filter( array_map( 'strval', $roles ), 'strlen' ) ) );
		$role_args = array_merge( array( '*' ), $roles );

		return array( $role_args, self::placeholders( count( $role_args ) ) );
	}

	/**
	 * Builds a comma-separated SQL placeholder list, e.g. placeholders( 3 ) => '%s, %s, %s'.
	 *
	 * @param int    $count Number of placeholders.
	 * @param string $type  Placeholder token ('%s', '%d', ...).
	 * @return string
	 */
	private static function placeholders( $count, $type = '%s' ) {
		return implode( ', ', array_fill( 0, $count, $type ) );
	}
}
