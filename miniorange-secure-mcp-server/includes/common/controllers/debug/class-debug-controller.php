<?php
/**
 * Handles the debug log REST endpoints and the CSV download action.
 *
 * @package Miniorange_Secure_MCP_Server
 */

namespace MoSMCP\Common\Controllers\Debug;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MoSMCP\Common\Controllers\Abstract_Admin_Controller;
use MoSMCP\Common\Repositories\Debug_Store;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Class Debug_Controller
 *
 * Exposes the plugin's internal debug log to admin users: a paginated/filtered
 * list, capture on/off toggle, clear, and a CSV export. The export runs
 * through admin-post.php (see {@see download_logs()}) rather than the REST
 * API, since a REST route always JSON-encodes its response and can't stream a
 * plain file download.
 */
class Debug_Controller extends Abstract_Admin_Controller {

	/**
	 * admin-post.php action name that routes to download_logs().
	 */
	const DOWNLOAD_ACTION = 'mosmcp_download_debug_logs';

	/**
	 * Nonce action used to authorize the CSV download link.
	 */
	const DOWNLOAD_NONCE_ACTION = 'mosmcp_download_debug_logs';

	/**
	 * Returns a paginated, optionally filtered list of debug log entries.
	 *
	 * Query parameters: page, per_page, level, channel, search, client_id,
	 * date_from (YYYY-MM-DD), date_to (YYYY-MM-DD).
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public static function list_logs( WP_REST_Request $request ) {
		$args = self::filters_from_request( $request );

		return new WP_REST_Response( Debug_Store::query( $args ), 200 );
	}

	/**
	 * Returns the distinct channel names seen in the log, for the filter dropdown.
	 *
	 * @return WP_REST_Response
	 */
	public static function list_channels() {
		return new WP_REST_Response( array( 'channels' => Debug_Store::channels() ), 200 );
	}

	/**
	 * Returns whether capture is currently switched on.
	 *
	 * @return WP_REST_Response
	 */
	public static function get_status() {
		return new WP_REST_Response( array( 'enabled' => Debug_Store::is_enabled() ), 200 );
	}

	/**
	 * Switches capture on or off.
	 *
	 * @param WP_REST_Request $request REST request; body: { enabled: bool }.
	 * @return WP_REST_Response
	 */
	public static function toggle_logging( WP_REST_Request $request ) {
		$enabled = (bool) $request->get_param( 'enabled' );
		Debug_Store::set_enabled( $enabled );

		return new WP_REST_Response( array( 'enabled' => $enabled ), 200 );
	}

	/**
	 * Truncates the entire debug log. Irreversible; requires manage_options.
	 *
	 * @return WP_REST_Response
	 */
	public static function clear_logs() {
		Debug_Store::clear();

		return new WP_REST_Response( array( 'cleared' => true ), 200 );
	}

	/**
	 * Streams the (optionally filtered) debug log as a CSV attachment.
	 *
	 * Registered as an admin-post.php action rather than a REST route so the
	 * response can be a raw file download instead of JSON. Verifies both the
	 * admin capability and a dedicated nonce, since admin-post.php actions are
	 * reachable via a plain browser navigation (no X-WP-Nonce header check).
	 *
	 * @return void
	 */
	public static function download_logs() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'miniorange-secure-mcp-server' ), 403 );
		}

		check_admin_referer( self::DOWNLOAD_NONCE_ACTION );

		$args = array(
			'level'     => isset( $_GET['level'] ) ? sanitize_text_field( wp_unslash( $_GET['level'] ) ) : '',
			'channel'   => isset( $_GET['channel'] ) ? sanitize_text_field( wp_unslash( $_GET['channel'] ) ) : '',
			'search'    => isset( $_GET['search'] ) ? sanitize_text_field( wp_unslash( $_GET['search'] ) ) : '',
			'client_id' => isset( $_GET['client_id'] ) ? sanitize_text_field( wp_unslash( $_GET['client_id'] ) ) : '',
			'date_from' => isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '',
			'date_to'   => isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : '',
		);

		$rows = Debug_Store::export( $args );

		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="mosmcp-debug-' . gmdate( 'Y-m-d-His' ) . '.log"' );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Raw log file stream, not HTML output.
		echo self::to_log( $rows );
		exit;
	}

	/**
	 * Renders rows as a plain-text .log document, one line per entry, each
	 * carrying its own timestamp — readable directly in any text editor without
	 * importing anything.
	 *
	 * @param list<array<string, mixed>> $rows Rows shaped like Debug_Store::query()'s 'logs'.
	 * @return string
	 */
	private static function to_log( array $rows ) {
		$lines = array();

		foreach ( $rows as $row ) {
			$who_parts = array(
				$row['client_name'] ? 'client=' . $row['client_name'] : '',
				$row['user_login'] ? 'user=' . $row['user_login'] : '',
				$row['ip_address'] ? 'ip=' . $row['ip_address'] : '',
			);

			$who = trim( implode( ' ', array_filter( $who_parts ) ) );

			$line = sprintf(
				'[%s] [%s] [%s] %s',
				gmdate( 'Y-m-d H:i:s', (int) $row['created'] ) . ' UTC',
				strtoupper( (string) $row['level'] ),
				$row['channel'],
				$row['message']
			);

			if ( '' !== $who ) {
				$line .= ' (' . $who . ')';
			}
			if ( $row['request_id'] ) {
				$line .= ' [request:' . $row['request_id'] . ']';
			}
			if ( null !== $row['context'] ) {
				$line .= "\n    " . wp_json_encode( $row['context'] );
			}

			$lines[] = $line;
		}

		return implode( "\n", $lines ) . ( $lines ? "\n" : '' );
	}

	/**
	 * Extracts and sanitizes the shared filter set from a REST request.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return array<string, mixed>
	 */
	private static function filters_from_request( WP_REST_Request $request ) {
		return array(
			'page'      => absint( $request->get_param( 'page' ) ?? 1 ),
			'per_page'  => absint( $request->get_param( 'per_page' ) ?? 50 ),
			'level'     => sanitize_text_field( (string) ( $request->get_param( 'level' ) ?? '' ) ),
			'channel'   => sanitize_text_field( (string) ( $request->get_param( 'channel' ) ?? '' ) ),
			'search'    => sanitize_text_field( (string) ( $request->get_param( 'search' ) ?? '' ) ),
			'client_id' => sanitize_text_field( (string) ( $request->get_param( 'client_id' ) ?? '' ) ),
			'date_from' => sanitize_text_field( (string) ( $request->get_param( 'date_from' ) ?? '' ) ),
			'date_to'   => sanitize_text_field( (string) ( $request->get_param( 'date_to' ) ?? '' ) ),
		);
	}
}
