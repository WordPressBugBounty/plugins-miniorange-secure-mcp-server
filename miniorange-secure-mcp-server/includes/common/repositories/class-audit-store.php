<?php
/**
 * Persistence layer for the MCP tool-call audit log.
 *
 * @package Miniorange_Secure_MCP_Server
 */

namespace MoSMCP\Common\Repositories;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Audit_Store
 *
 * Owns the wp_mosmcp_audit_log table: one row per tools/call invocation, storing
 * the acting user, owning NHI, client, tool, execution status, and wall-clock
 * latency. Sensitive fields are stripped before insertion by {@see Audit_Logger}.
 *
 * Schema lifecycle lives in {@see \MoSMCP\Common\Migration\Migration}.
 */
class Audit_Store {

	/**
	 * WP-Cron hook that runs purge() daily. Scheduled/unscheduled from Hooks.
	 */
	const CRON_HOOK = 'mosmcp_audit_cleanup';

	/**
	 * WordPress option that holds retention configuration.
	 */
	const OPTION_RETENTION = 'mosmcp_audit_retention';

	/**
	 * Default log retention in days.
	 */
	const DEFAULT_RETENTION_DAYS = 90;

	/**
	 * Default maximum total rows kept in the log table.
	 */
	const DEFAULT_MAX_ROWS = 50000;

	/**
	 * Returns the fully prefixed audit log table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'mosmcp_audit_log';
	}

	/**
	 * Inserts a single log entry, auto-assigning a UUID and timestamp.
	 *
	 * @param array<string, mixed> $row Column => value pairs (no event_id / created needed).
	 * @return bool True on success.
	 */
	public static function insert( array $row ) {
		global $wpdb;

		$row['event_id'] = wp_generate_uuid4();
		if ( ! isset( $row['created'] ) ) {
			$row['created'] = time();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (bool) $wpdb->insert( self::table(), $row );
	}

	/**
	 * Returns paginated, optionally filtered log rows with the total count.
	 *
	 * Accepted $args keys: page (int, 1-based), per_page (int, max 100),
	 * user_login (string, partial match), nhi_uuid (string), tool_name (string),
	 * status (string), date_from (YYYY-MM-DD), date_to (YYYY-MM-DD).
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return array{logs: list<array<string,mixed>>, total_count: int, page: int, per_page: int}
	 */
	public static function query( array $args ) {
		global $wpdb;

		$page     = max( 1, (int) ( isset( $args['page'] ) ? $args['page'] : 1 ) );
		$per_page = max( 1, min( 100, (int) ( isset( $args['per_page'] ) ? $args['per_page'] : 25 ) ) );
		$offset   = ( $page - 1 ) * $per_page;

		// Build WHERE clause and its bound parameters.
		$where  = array();
		$params = array( self::table() );  // first slot is %i (table name).

		if ( ! empty( $args['user_login'] ) ) {
			$where[]  = 'user_login LIKE %s';
			$params[] = '%' . $wpdb->esc_like( (string) $args['user_login'] ) . '%';
		}
		if ( ! empty( $args['nhi_uuid'] ) ) {
			$where[]  = 'nhi_uuid = %s';
			$params[] = (string) $args['nhi_uuid'];
		}
		if ( ! empty( $args['tool_name'] ) ) {
			$where[]  = 'tool_name = %s';
			$params[] = (string) $args['tool_name'];
		}
		if ( isset( $args['status'] ) && '' !== (string) $args['status'] ) {
			$where[]  = 'status = %s';
			$params[] = (string) $args['status'];
		}
		if ( ! empty( $args['date_from'] ) ) {
			$ts = strtotime( (string) $args['date_from'] . ' 00:00:00' );
			if ( $ts ) {
				$where[]  = 'created >= %d';
				$params[] = $ts;
			}
		}
		if ( ! empty( $args['date_to'] ) ) {
			$ts = strtotime( (string) $args['date_to'] . ' 23:59:59' );
			if ( $ts ) {
				$where[]  = 'created <= %d';
				$params[] = $ts;
			}
		}

		$where_sql = $where ? ( 'WHERE ' . implode( ' AND ', $where ) ) : '';

		// Total count.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i {$where_sql}", $params ) );

		// Paginated rows: append LIMIT / OFFSET params.
		$select_params = array_merge( $params, array( $per_page, $offset ) );
		$select_sql    = "SELECT event_id, created, user_id, user_login,
		                         nhi_uuid, nhi_name, client_id, client_name,
		                         tool_name, status, error_code, error_message,
		                         latency_ms, ip_address
		                  FROM %i {$where_sql}
		                  ORDER BY created DESC, id DESC
		                  LIMIT %d OFFSET %d";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows = $wpdb->get_results( $wpdb->prepare( $select_sql, $select_params ), ARRAY_A );

		return array(
			'logs'        => is_array( $rows ) ? array_map( array( __CLASS__, 'shape' ), $rows ) : array(),
			'total_count' => $total,
			'page'        => $page,
			'per_page'    => $per_page,
		);
	}

	/**
	 * Returns aggregate statistics for the admin summary cards.
	 *
	 * @return array{total:int, success:int, failed:int, denied:int, avg_latency_ms:int|null, top_tools: list<array{tool:string,count:int}>}
	 */
	public static function summary() {
		global $wpdb;

		$table = self::table();

		// Counts per status + avg latency for success in one pass.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$status_rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT status, COUNT(*) AS cnt, AVG(CASE WHEN latency_ms IS NOT NULL THEN latency_ms END) AS avg_lat FROM %i GROUP BY status',
				$table
			),
			ARRAY_A
		);

		$total     = 0;
		$avg       = null;
		$by_status = array(
			'success' => 0,
			'failed'  => 0,
			'denied'  => 0,
		);
		foreach ( (array) $status_rows as $row ) {
			$s               = (string) $row['status'];
			$cnt             = (int) $row['cnt'];
			$by_status[ $s ] = $cnt;
			$total          += $cnt;
			if ( 'success' === $s && null !== $row['avg_lat'] ) {
				$avg = (int) round( (float) $row['avg_lat'] );
			}
		}

		// Top 5 tools by invocation count.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$top = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT tool_name AS tool, COUNT(*) AS count FROM %i GROUP BY tool_name ORDER BY count DESC LIMIT 5',
				$table
			),
			ARRAY_A
		);

		return array(
			'total'          => $total,
			'success'        => $by_status['success'],
			'failed'         => $by_status['failed'],
			'denied'         => $by_status['denied'],
			'avg_latency_ms' => $avg,
			'top_tools'      => is_array( $top )
				? array_map(
					function ( $r ) {
						return array(
							'tool'  => (string) $r['tool'],
							'count' => (int) $r['count'],
						);
					},
					$top
				)
				: array(),
		);
	}

	/**
	 * Enforces the configured retention policy: age-based deletion then a global row cap.
	 *
	 * Called daily by the mosmcp_audit_cleanup cron hook.
	 *
	 * @return void
	 */
	public static function purge() {
		global $wpdb;

		$table = self::table();
		$cfg   = (array) get_option( self::OPTION_RETENTION, array() );
		$days  = isset( $cfg['days'] ) ? max( 1, (int) $cfg['days'] ) : self::DEFAULT_RETENTION_DAYS;
		$max   = isset( $cfg['max_rows'] ) ? max( 100, (int) $cfg['max_rows'] ) : self::DEFAULT_MAX_ROWS;

		$cutoff = time() - ( $days * DAY_IN_SECONDS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created < %d', $table, $cutoff ) );

		// Keep at most $max rows globally (delete everything older than the $max-th newest row).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$boundary = $wpdb->get_var(
			$wpdb->prepare( 'SELECT id FROM %i ORDER BY id DESC LIMIT 1 OFFSET %d', $table, $max )
		);
		if ( $boundary ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id < %d', $table, (int) $boundary ) );
		}
	}

	/**
	 * Truncates the entire audit log. Admin-only; used via the REST clear endpoint.
	 *
	 * @return void
	 */
	public static function clear() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', self::table() ) );
	}

	/**
	 * Casts a raw DB row to the public shape returned by the REST API.
	 *
	 * @param array<string, mixed> $row Raw row from $wpdb.
	 * @return array<string, mixed>
	 */
	private static function shape( array $row ) {
		return array(
			'event_id'      => (string) $row['event_id'],
			'created'       => (int) $row['created'],
			'user_id'       => (int) $row['user_id'],
			'user_login'    => (string) $row['user_login'],
			'nhi_uuid'      => (string) $row['nhi_uuid'],
			'nhi_name'      => (string) $row['nhi_name'],
			'client_id'     => (string) $row['client_id'],
			'client_name'   => (string) $row['client_name'],
			'tool_name'     => (string) $row['tool_name'],
			'status'        => (string) $row['status'],
			'error_code'    => null !== $row['error_code'] ? (string) $row['error_code'] : null,
			'error_message' => null !== $row['error_message'] ? (string) $row['error_message'] : null,
			'latency_ms'    => null !== $row['latency_ms'] ? (int) $row['latency_ms'] : null,
			'ip_address'    => null !== $row['ip_address'] ? (string) $row['ip_address'] : null,
		);
	}
}
