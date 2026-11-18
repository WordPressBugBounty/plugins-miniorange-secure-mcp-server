<?php
/**
 * Mints and revokes the short-lived token the gateway uses for the discovery test.
 *
 * @package Miniorange_Secure_MCP_Server
 */

namespace MoSMCP\Common\Services\Diagnostics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MoSMCP\Common\Repositories\Store;
use MoSMCP\Common\Services\OAuth\Tokens;
use MoSMCP\Common\Utils\Utils;

/**
 * Class Test_Token
 *
 * The token belongs to a throwaway client registered under the gateway's own host (so it never
 * shows as a direct connection), lives for two minutes, and is deleted as soon as the probe
 * returns. Its scope limits it to read-only MCP methods, enforced in the MCP controller.
 */
class Test_Token {

	/**
	 * Scope that marks a token as connection-test only.
	 */
	const SCOPE = 'mosmcp:connection-test';

	/**
	 * Client-id prefix, used to find and exclude the throwaway clients.
	 */
	const CLIENT_PREFIX = 'mosmcp-diag-';

	/**
	 * Token lifetime, in seconds.
	 */
	const TTL = 120;

	/**
	 * Age after which a leftover throwaway client (from a crashed run) is swept.
	 */
	const STALE_AFTER = 600;

	/**
	 * MCP methods a connection-test token may call.
	 */
	const ALLOWED_METHODS = array( 'initialize', 'ping', 'tools/list' );

	/**
	 * Mints a token for a user.
	 *
	 * @param int $user_id The user whose abilities the gateway will see.
	 * @return array{token:string, client_id:string}|null Null when the rows could not be written.
	 */
	public static function mint( $user_id ) {
		self::purge_stale();

		$now       = time();
		$client_id = self::CLIENT_PREFIX . bin2hex( random_bytes( 6 ) );
		$token     = Tokens::generate();

		$client_ok = Store::insert_client(
			array(
				'client_id'                  => $client_id,
				'client_name'                => 'Connection test',
				'redirect_uris'              => wp_json_encode( array( 'https://' . Store::GATEWAY_HOSTS[0] . '/connection-test' ) ),
				'grant_types'                => '',
				'token_endpoint_auth_method' => 'none',
				'created'                    => $now,
				'is_enabled'                 => 1,
			)
		);
		if ( ! $client_ok ) {
			return null;
		}

		$token_ok = Store::insert_token(
			array(
				'token_hash'  => Tokens::hash( $token ),
				'type'        => Tokens::TYPE_ACCESS,
				'client_id'   => $client_id,
				'user_id'     => (int) $user_id,
				'scope'       => self::SCOPE,
				'resource'    => Utils::resource_url(),
				'expires'     => $now + self::TTL,
				'parent_hash' => null,
			)
		);
		if ( ! $token_ok ) {
			Store::delete_client( $client_id );
			return null;
		}

		return array(
			'token'     => $token,
			'client_id' => $client_id,
		);
	}

	/**
	 * Deletes the throwaway client and its token.
	 *
	 * @param array{client_id:string} $handle The value returned by {@see mint()}.
	 * @return void
	 */
	public static function revoke( array $handle ) {
		Store::delete_client( $handle['client_id'] );
	}

	/**
	 * Removes throwaway clients left behind by a run that died before revoking.
	 *
	 * @return void
	 */
	public static function purge_stale() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT client_id FROM %i WHERE client_id LIKE %s AND created < %d',
				Store::table( 'clients' ),
				$wpdb->esc_like( self::CLIENT_PREFIX ) . '%',
				time() - self::STALE_AFTER
			)
		);

		foreach ( is_array( $ids ) ? $ids : array() as $id ) {
			Store::delete_client( (string) $id );
		}
	}
}
