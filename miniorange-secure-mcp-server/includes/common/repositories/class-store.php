<?php
/**
 * Persistence layer for the OAuth server (clients, codes, tokens).
 *
 * @package Miniorange_Secure_MCP_Server
 */

namespace MoSMCP\Common\Repositories;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MoSMCP\Common\Services\OAuth\Tokens;

/**
 * Class Store
 *
 * Owns the three custom database tables used by the OAuth server and provides
 * a small typed CRUD surface over them. The schema lifecycle (create/upgrade/
 * drop) lives in {@see \MoSMCP\Common\Migration\Migration}. Secrets and tokens
 * are only ever stored here in hashed form; see
 * {@see \MoSMCP\Common\Services\OAuth\Tokens}.
 */
class Store {

	/**
	 * Hosts that identify the miniOrange gateway as the registering party.
	 *
	 * An allow-list rather than one constant so a staging gateway can be added
	 * without touching connection_source().
	 */
	const GATEWAY_HOSTS = array( 'gateway.miniorange.ai' );

	/**
	 * Values returned by connection_source().
	 */
	const SOURCE_GATEWAY = 'gateway';
	const SOURCE_DIRECT  = 'direct';

	/**
	 * Transient holding the cached connection_summary() result, and its lifetime.
	 *
	 * The admin bootstrap reads the summary on every plugin page load, so the join
	 * behind it is cached. Invalidated on client insert/delete; the TTL is only a
	 * backstop for a missed invalidation.
	 */
	const SUMMARY_TRANSIENT = 'mosmcp_connection_summary';
	const SUMMARY_TTL       = 300;

	/**
	 * Returns the prefixed table name for a given short key.
	 *
	 * @param string $key One of 'clients', 'codes', 'tokens'.
	 * @return string Fully prefixed table name.
	 */
	public static function table( $key ) {
		global $wpdb;

		return $wpdb->prefix . 'mosmcp_oauth_' . $key;
	}

	/*
	 * Clients
	 */

	/**
	 * Inserts a dynamically registered client.
	 *
	 * @param array $data Column => value pairs.
	 * @return bool True on success.
	 */
	public static function insert_client( array $data ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ok = (bool) $wpdb->insert( self::table( 'clients' ), $data );

		delete_transient( self::SUMMARY_TRANSIENT );

		return $ok;
	}

	/**
	 * Fetches a client by its identifier.
	 *
	 * @param string $client_id The client identifier.
	 * @return array|null Client row as an associative array, or null when missing.
	 */
	public static function get_client( $client_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE client_id = %s', self::table( 'clients' ), $client_id ),
			ARRAY_A
		);

		return $row ? $row : null;
	}

	/**
	 * Classifies a set of registered redirect URIs as gateway or direct.
	 *
	 * Nothing reaches the MCP endpoint without registering as an OAuth client first,
	 * and whoever registers supplies its own redirect URI: the gateway supplies a
	 * callback on its own host, while an AI client talking straight to this site
	 * supplies its own (chatgpt.com, claude.ai, a loopback for desktop apps). So the
	 * redirect URI's host already records which route was used — and since these rows
	 * are never garbage-collected, it records it for connections made long before
	 * this code shipped.
	 *
	 * Gateway only when EVERY URI is on a gateway host: a registration mixing a
	 * gateway callback with a foreign one is not the gateway, and accepting it as one
	 * would be a way to opt out of the deprecation notice.
	 *
	 * Matched on the parsed host, never a substring of the stored JSON — a
	 * `LIKE '%gateway.miniorange.ai%'` would also match
	 * `https://elsewhere.example/?x=gateway.miniorange.ai`.
	 *
	 * @param string[] $redirect_uris Registered redirect URIs.
	 * @return string self::SOURCE_GATEWAY or self::SOURCE_DIRECT.
	 */
	public static function connection_source( array $redirect_uris ) {
		if ( empty( $redirect_uris ) ) {
			return self::SOURCE_DIRECT;
		}

		foreach ( $redirect_uris as $uri ) {
			if ( ! is_string( $uri ) ) {
				return self::SOURCE_DIRECT;
			}

			$host = wp_parse_url( $uri, PHP_URL_HOST );
			if ( ! is_string( $host ) || ! in_array( strtolower( $host ), self::GATEWAY_HOSTS, true ) ) {
				return self::SOURCE_DIRECT;
			}
		}

		return self::SOURCE_GATEWAY;
	}

	/**
	 * Classifies a client row by the redirect URIs stored on it.
	 *
	 * Derived on read rather than stored in a column: `redirect_uris` is already
	 * persisted and immutable for the life of the row, so a column would only
	 * duplicate it — and would need a schema change plus a backfill to say the same
	 * thing.
	 *
	 * @param array<string, mixed> $client Client row.
	 * @return string self::SOURCE_GATEWAY or self::SOURCE_DIRECT.
	 */
	public static function client_connection_source( array $client ) {
		$uris = isset( $client['redirect_uris'] ) ? json_decode( (string) $client['redirect_uris'], true ) : array();

		return self::connection_source( is_array( $uris ) ? $uris : array() );
	}

	/**
	 * Site-level gateway/direct counts, for the deprecation notice.
	 *
	 * `direct_live` counts direct clients still holding an unexpired refresh token.
	 * Refresh tokens last 14 days and only go away via rotation or client deletion,
	 * making them the closest available signal for "this connection is in active
	 * use"; access tokens expire hourly and would read as idle almost immediately.
	 *
	 * @return array{gateway:int, direct:int, direct_live:int, direct_names:string[]}
	 */
	public static function connection_summary() {
		global $wpdb;

		$cached = get_transient( self::SUMMARY_TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT c.client_name, c.redirect_uris,
				        COUNT(t.token_hash) AS live_refresh
				 FROM %i AS c
				 LEFT JOIN %i AS t
				        ON t.client_id = c.client_id AND t.type = %s AND t.expires >= %d
				 GROUP BY c.client_id',
				self::table( 'clients' ),
				self::table( 'tokens' ),
				Tokens::TYPE_REFRESH,
				time()
			),
			ARRAY_A
		);

		$summary = array(
			'gateway'      => 0,
			'direct'       => 0,
			'direct_live'  => 0,
			'direct_names' => array(),
		);

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( self::SOURCE_GATEWAY === self::client_connection_source( $row ) ) {
				++$summary['gateway'];
				continue;
			}

			++$summary['direct'];

			if ( (int) $row['live_refresh'] > 0 ) {
				++$summary['direct_live'];
			}

			$name = trim( (string) $row['client_name'] );
			if ( '' !== $name && ! in_array( $name, $summary['direct_names'], true ) ) {
				$summary['direct_names'][] = $name;
			}
		}

		set_transient( self::SUMMARY_TRANSIENT, $summary, self::SUMMARY_TTL );

		return $summary;
	}

	/**
	 * Returns the number of registered clients.
	 *
	 * @return int Client count.
	 */
	public static function count_clients() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', self::table( 'clients' ) ) );
	}

	/**
	 * Returns the number of enabled clients.
	 *
	 * @return int Enabled client count.
	 */
	public static function count_enabled_clients() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE is_enabled = 1', self::table( 'clients' ) )
		);
	}

	/**
	 * Returns all clients with their active (non-expired access) token count.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function list_clients() {
		global $wpdb;

		$clients_table = self::table( 'clients' );
		$tokens_table  = self::table( 'tokens' );
		$now           = time();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT c.client_id, c.client_name, c.is_enabled, c.allowed_abilities, c.created,
				        COUNT(t.token_hash) AS active_token_count
				 FROM %i AS c
				 LEFT JOIN %i AS t
				        ON t.client_id = c.client_id AND t.type = %s AND t.expires >= %d
				 GROUP BY c.client_id
				 ORDER BY c.created DESC',
				$clients_table,
				$tokens_table,
				Tokens::TYPE_ACCESS,
				$now
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map(
			function ( $row ) {
				$row['is_enabled']         = (int) $row['is_enabled'];
				$row['created']            = (int) $row['created'];
				$row['active_token_count'] = (int) $row['active_token_count'];
				$row['allowed_abilities']  = null !== $row['allowed_abilities']
					? json_decode( $row['allowed_abilities'], true )
					: null;
				return $row;
			},
			$rows
		);
	}

	/**
	 * Updates allowed fields on a client row.
	 *
	 * @param string               $client_id The client identifier.
	 * @param array<string, mixed> $data      Column => value pairs (is_enabled, client_name, allowed_abilities).
	 * @return bool True on success.
	 */
	public static function update_client( $client_id, array $data ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			self::table( 'clients' ),
			$data,
			array( 'client_id' => $client_id )
		);

		return false !== $result;
	}

	/**
	 * Deletes a client and all tokens issued for it.
	 *
	 * @param string $client_id The client identifier.
	 * @return void
	 */
	public static function delete_client( $client_id ) {
		global $wpdb;

		// Revoke all tokens first.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( self::table( 'tokens' ), array( 'client_id' => $client_id ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( self::table( 'codes' ), array( 'client_id' => $client_id ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( self::table( 'clients' ), array( 'client_id' => $client_id ) );

		delete_transient( self::SUMMARY_TRANSIENT );
	}

	/**
	 * Returns the number of non-expired access tokens across all clients.
	 *
	 * @return int Active token count.
	 */
	public static function count_active_tokens() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE type = %s AND expires >= %d',
				self::table( 'tokens' ),
				Tokens::TYPE_ACCESS,
				time()
			)
		);
	}

	/**
	 * Returns the number of distinct WordPress users with a non-expired access token.
	 *
	 * @return int Connected member count.
	 */
	public static function count_connected_members() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(DISTINCT user_id) FROM %i WHERE type = %s AND expires >= %d',
				self::table( 'tokens' ),
				Tokens::TYPE_ACCESS,
				time()
			)
		);
	}

	/**
	 * Whether any client has ever completed a token exchange.
	 *
	 * Checks for a refresh token rather than an access token: access tokens are
	 * deleted on their first expired use (see REST_Controller::authenticate()),
	 * so they disappear between sessions even though the connection is still
	 * intact. Refresh tokens only go away via rotation (replaced, not lost) or
	 * client deletion, making them a durable "connected at least once" signal.
	 *
	 * @return bool
	 */
	public static function has_ever_issued_token() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE type = %s',
				self::table( 'tokens' ),
				Tokens::TYPE_REFRESH
			)
		) > 0;
	}

	/*
	 * Authorization codes
	 */

	/**
	 * Stores an authorization code.
	 *
	 * @param array $data Column => value pairs.
	 * @return bool True on success.
	 */
	public static function insert_code( array $data ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (bool) $wpdb->insert( self::table( 'codes' ), $data );
	}

	/**
	 * Fetches an authorization code by its hash.
	 *
	 * @param string $code_hash The hashed code.
	 * @return array|null Code row as an associative array, or null when missing.
	 */
	public static function get_code( $code_hash ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE code_hash = %s', self::table( 'codes' ), $code_hash ),
			ARRAY_A
		);

		return $row ? $row : null;
	}

	/**
	 * Deletes an authorization code (codes are single-use).
	 *
	 * @param string $code_hash The hashed code.
	 * @return void
	 */
	public static function delete_code( $code_hash ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( self::table( 'codes' ), array( 'code_hash' => $code_hash ) );
	}

	/*
	 * Tokens
	 */

	/**
	 * Stores an access or refresh token.
	 *
	 * @param array $data Column => value pairs.
	 * @return bool True on success.
	 */
	public static function insert_token( array $data ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (bool) $wpdb->insert( self::table( 'tokens' ), $data );
	}

	/**
	 * Fetches a token by its hash.
	 *
	 * @param string $token_hash The hashed token.
	 * @return array|null Token row as an associative array, or null when missing.
	 */
	public static function get_token( $token_hash ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE token_hash = %s', self::table( 'tokens' ), $token_hash ),
			ARRAY_A
		);

		return $row ? $row : null;
	}

	/**
	 * Deletes a token by its hash.
	 *
	 * @param string $token_hash The hashed token.
	 * @return void
	 */
	public static function delete_token( $token_hash ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( self::table( 'tokens' ), array( 'token_hash' => $token_hash ) );
	}

	/**
	 * Deletes all tokens descended from a given refresh token.
	 *
	 * @param string $parent_hash The hashed refresh token whose children to remove.
	 * @return void
	 */
	public static function delete_tokens_by_parent( $parent_hash ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( self::table( 'tokens' ), array( 'parent_hash' => $parent_hash ) );
	}

	/**
	 * Removes expired codes and tokens.
	 *
	 * @return void
	 */
	public static function purge_expired() {
		global $wpdb;

		$now = time();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE expires < %d', self::table( 'codes' ), $now ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE expires < %d', self::table( 'tokens' ), $now ) );
	}
}