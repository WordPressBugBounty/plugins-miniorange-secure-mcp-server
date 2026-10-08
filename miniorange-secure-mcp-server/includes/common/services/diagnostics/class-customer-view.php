<?php
/**
 * Decides which connection-test results a customer sees.
 *
 * @package Miniorange_Secure_MCP_Server
 */

namespace MoSMCP\Common\Services\Diagnostics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Customer_View
 *
 * Pure. Marks each result row `shown` only when it is likely a real problem worth a customer's
 * attention. Everything is still kept, so the "Copy for support" bundle loses nothing.
 */
class Customer_View {

	/**
	 * Rows that are information, not problems.
	 */
	const HIDDEN_IDS = array( 'environment', 'gateway_reachable' );

	/**
	 * Results too uncertain to put in front of a customer.
	 */
	const HIDDEN_CODES = array(
		'www_mismatch',         // The admin may simply be using an alias.
		'cert_unreadable',      // This server could not call itself; says nothing about the certificate.
		'auth_header_unknown',  // A proxy removed our echo header; not evidence of a problem.
		'token_none',           // A client that never finished signing in.
		'token_expiring',       // Usually an abandoned client.
		'token_refresh_failed', // A single failed refresh; it may already have recovered.
	);

	/**
	 * Checks the site and the gateway both run, so a failure on one side can be checked against the other.
	 */
	const CROSS_CHECKED = array(
		'mcp_health',
		'mcp_json_passthrough',
		'auth_header_passthrough',
		'oauth_registration_endpoint',
		'oauth_token_endpoint',
		'oauth_authorization_endpoint',
	);

	/**
	 * Adds `shown` to every row.
	 *
	 * @param array[] $rows Result rows.
	 * @return array[]
	 */
	public static function apply( array $rows ) {
		$gateway_passed = array();
		foreach ( $rows as $row ) {
			if ( 'gateway' === $row['group'] && Response_Classifier::PASS === $row['status'] ) {
				$gateway_passed[ $row['id'] ] = true;
			}
		}

		foreach ( $rows as $i => $row ) {
			$rows[ $i ]['shown'] = self::shown( $row, $gateway_passed );
		}

		return $rows;
	}

	/**
	 * The rows a customer sees.
	 *
	 * @param array[] $rows Rows already passed through {@see apply()}.
	 * @return array[]
	 */
	public static function visible( array $rows ) {
		return array_values(
			array_filter(
				$rows,
				function ( $row ) {
					return ! empty( $row['shown'] );
				}
			)
		);
	}

	/**
	 * Whether one row is shown.
	 *
	 * @param array               $row            Result row.
	 * @param array<string, bool> $gateway_passed Ids the gateway checked successfully.
	 * @return bool
	 */
	private static function shown( array $row, array $gateway_passed ) {
		if ( Response_Classifier::SKIP === $row['status'] ) {
			return false;
		}
		if ( in_array( $row['id'], self::HIDDEN_IDS, true ) || in_array( $row['code'], self::HIDDEN_CODES, true ) ) {
			return false;
		}

		// The gateway reached this fine, so the site failing it only means the server cannot call itself.
		if ( 'site' === $row['group'] && Response_Classifier::PASS !== $row['status'] && in_array( $row['id'], self::CROSS_CHECKED, true ) && isset( $gateway_passed[ $row['id'] ] ) ) {
			return false;
		}

		return true;
	}
}
