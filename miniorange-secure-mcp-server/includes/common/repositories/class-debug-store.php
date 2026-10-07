<?php
/**
 * Persistence layer for the plugin's internal debug log.
 *
 * @package Miniorange_Secure_MCP_Server
 */

namespace MoSMCP\Common\Repositories;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Debug_Store
 *
 * Owns the wp_mosmcp_debug_log table: developer-facing diagnostic events
 * written by {@see \MoSMCP\Common\Services\Logging\Debug_Logger}. Independent
 * of WordPress's own debug log (WP_DEBUG_LOG / error_log) — this table is the
 * only place these events are written, so it works regardless of the site's
 * PHP error-logging configuration.
 *
 * Schema lifecycle lives in {@see \MoSMCP\Common\Migration\Migration}.
 */
class Debug_Store {

	/**
	 * WP-Cron hook that runs purge() daily. Scheduled/unscheduled from Hooks.
	 */
	const CRON_HOOK = 'mosmcp_debug_log_cleanup';

	/**
	 * WordPress option toggling whether new events are captured.
	 */
	const OPTION_ENABLED = 'mosmcp_debug_logging_enabled';

	/**
	 * WordPress option holding retention configuration.
	 */
	const OPTION_RETENTION = 'mosmcp_debug_log_retention';

	/**
	 * Default log retention in days. Shorter than the audit log's, since debug
	 * events are high-volume and only useful for near-term troubleshooting.
	 */
	const DEFAULT_RETENTION_DAYS = 7;

	/**
	 * Default maximum total rows kept in the log table.
	 */
	const DEFAULT_MAX_ROWS = 20000;

	/**
	 * The recognized severity levels, lowest to highest.
	 *
	 * @var string[]
	 */
	const LEVELS = array( 'debug', 'info', 'warning', 'error' );

	/**
	 * Returns the fully prefixed debug log table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'mosmcp_debug_log';
	}

	/**
	 * Inserts multiple log rows in a single query.
	 *
	 * Called once per request (from a shutdown handler) rather than once per
	 * log call, so a chatty request doesn't add N round-trips to the database.
	 *
	 * @param list<array<string, mixed>> $rows Rows shaped like {@see Debug_Logger::log()}.
	 * @return bool True when the batch was written; false on a DB failure (or
	 *              trivially true for an empty batch — there was nothing to fail).
	 */
	public static function insert_many( array $rows ) {
		global $wpdb;

		if ( empty( $rows ) ) {
			return true;
		}

		$table        = self::table();
		$columns      = array( 'created', 'level', 'channel', 'message', 'context', 'request_id', 'user_id', 'user_login', 'client_id', 'client_name', 'ip_address' );
		$placeholders = array();
		$values       = array( $table );

		foreach ( $rows as $row ) {
			$placeholders[] = '(%d, %s, %s, %s, %s, %s, %d, %s, %s, %s, %s)';
			$values[]       = (int) $row['created'];
			$values[]       = (string) $row['level'];
			$values[]       = (string) $row['channel'];
			$values[]       = (string) $row['message'];
			$values[]       = null !== $row['context'] ? (string) $row['context'] : '';
			$values[]       = (string) $row['request_id'];
			$values[]       = (int) $row['user_id'];
			$values[]       = (string) $row['user_login'];
			$values[]       = (string) $row['client_id'];
			$values[]       = (string) $row['client_name'];
			$values[]       = null !== $row['ip_address'] ? (string) $row['ip_address'] : '';
		}

		$columns_sql = '(' . implode( ', ', $columns ) . ')';
		$sql         = "INSERT INTO %i {$columns_sql} VALUES " . implode( ', ', $placeholders );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return false !== $wpdb->query( $wpdb->prepare( $sql, $values ) );
	}

	/**
	 * Returns paginated, optionally filtered log rows with the total count.
	 *
	 * Accepted $args keys: page (int, 1-based), per_page (int, max $per_page_cap),
	 * level (string), channel (string), search (string, matched against
	 * message), client_id (string), date_from (YYYY-MM-DD), date_to (YYYY-MM-DD).
	 *
	 * @param array<string, mixed> $args         Query arguments.
	 * @param int                  $per_page_cap Upper bound on per_page. Defaults to
	 *                                            the admin list UI's page size (100);
	 *                                            {@see export_chunk()} raises this so a
	 *                                            full export isn't silently truncated
	 *                                            to the UI's page size.
	 * @return array{logs: list<array<string,mixed>>, total_count: int, page: int, per_page: int}
	 */
	public static function query( array $args, $per_page_cap = 100 ) {
		global $wpdb;

		$page     = max( 1, (int) ( isset( $args['page'] ) ? $args['page'] : 1 ) );
		$per_page = max( 1, min( $per_page_cap, (int) ( isset( $args['per_page'] ) ? $args['per_page'] : 50 ) ) );
		$offset   = ( $page - 1 ) * $per_page;

		$where  = array();
		$params = array( self::table() );

		if ( ! empty( $args['level'] ) && in_array( (string) $args['level'], self::LEVELS, true ) ) {
			$where[]  = 'level = %s';
			$params[] = (string) $args['level'];
		}
		if ( ! empty( $args['channel'] ) ) {
			$where[]  = 'channel = %s';
			$params[] = (string) $args['channel'];
		}
		if ( ! empty( $args['client_id'] ) ) {
			$where[]  = 'client_id = %s';
			$params[] = (string) $args['client_id'];
		}
		if ( ! empty( $args['search'] ) ) {
			$where[]  = 'message LIKE %s';
			$params[] = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
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

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i {$where_sql}", $params ) );

		$select_params = array_merge( $params, array( $per_page, $offset ) );
		$select_sql    = "SELECT id, created, level, channel, message, context, request_id,
		                         user_id, user_login, client_id, client_name, ip_address
		                  FROM %i {$where_sql}
		                  ORDER BY id DESC
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
	 * Returns every distinct channel name currently present in the log, for the
	 * admin filter dropdown.
	 *
	 * @return string[]
	 */
	public static function channels() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT channel FROM %i ORDER BY channel ASC', self::table() ) );

		return is_array( $rows ) ? array_map( 'strval', $rows ) : array();
	}

	/**
	 * Chunk size {@see export_chunk()} pages through at, and the ceiling on
	 * `per_page` passed to {@see query()} for an export. Small enough that one
	 * chunk's rows/output stay a modest, bounded amount of memory regardless of
	 * how large the full export is; large enough to keep the query count for a
	 * near-DEFAULT_MAX_ROWS export reasonable (40 queries at the current size).
	 */
	const EXPORT_CHUNK_SIZE = 500;

	/**
	 * Returns one page of rows matching the given filters, for a streamed
	 * export — the caller (see Debug_Controller::download_logs()) requests
	 * successive chunks and writes each to the response as it arrives, rather
	 * than loading the whole (up to DEFAULT_MAX_ROWS) export into memory at
	 * once the way a single unpaginated fetch would.
	 *
	 * @param array<string, mixed> $args  Same filter keys as query(), minus pagination.
	 * @param int                  $chunk 1-based chunk index.
	 * @return list<array<string, mixed>> Empty once every row up to DEFAULT_MAX_ROWS
	 *                                    has been returned.
	 */
	public static function export_chunk( array $args, $chunk ) {
		if ( ( $chunk - 1 ) * self::EXPORT_CHUNK_SIZE >= self::DEFAULT_MAX_ROWS ) {
			return array();
		}

		$args['page']     = $chunk;
		$args['per_page'] = self::EXPORT_CHUNK_SIZE;

		return self::query( $args, self::EXPORT_CHUNK_SIZE )['logs'];
	}

	/**
	 * Enforces the configured retention policy: age-based deletion then a global row cap.
	 *
	 * Called daily by the mosmcp_debug_log_cleanup cron hook.
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
	 * Truncates the entire debug log. Admin-only; used via the REST clear endpoint.
	 *
	 * @return void
	 */
	public static function clear() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', self::table() ) );
	}

	/**
	 * Whether capture is currently switched on.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return (bool) get_option( self::OPTION_ENABLED, false );
	}

	/**
	 * Switches capture on or off.
	 *
	 * @param bool $enabled True to start capturing new events.
	 * @return void
	 */
	public static function set_enabled( $enabled ) {
		update_option( self::OPTION_ENABLED, (bool) $enabled, false );
	}

	/**
	 * Casts a raw DB row to the public shape returned by the REST API.
	 *
	 * @param array<string, mixed> $row Raw row from $wpdb.
	 * @return array<string, mixed>
	 */
	private static function shape( array $row ) {
		$context = null;
		if ( '' !== (string) $row['context'] ) {
			$decoded = json_decode( (string) $row['context'], true );
			$context = is_array( $decoded ) ? $decoded : null;
		}

		return array(
			'id'          => (int) $row['id'],
			'created'     => (int) $row['created'],
			'level'       => (string) $row['level'],
			'channel'     => (string) $row['channel'],
			'message'     => (string) $row['message'],
			'context'     => $context,
			'request_id'  => '' !== (string) $row['request_id'] ? (string) $row['request_id'] : null,
			'user_id'     => (int) $row['user_id'],
			'user_login'  => (string) $row['user_login'],
			'client_id'   => '' !== (string) $row['client_id'] ? (string) $row['client_id'] : null,
			'client_name' => '' !== (string) $row['client_name'] ? (string) $row['client_name'] : null,
			'ip_address'  => '' !== (string) $row['ip_address'] ? (string) $row['ip_address'] : null,
		);
	}
}
