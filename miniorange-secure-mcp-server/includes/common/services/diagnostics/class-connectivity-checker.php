<?php
/**
 * Orchestrates the "Test Connection" run: local checks, the gateway probe, and the result.
 *
 * @package Miniorange_Secure_MCP_Server
 */

namespace MoSMCP\Common\Services\Diagnostics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MoSMCP\Common\Utils\Utils;

/**
 * Class Connectivity_Checker
 *
 * Answers "why can't an AI client connect, or why does it see 0 abilities?" from two vantage
 * points: this server's own loopback ({@see Local_Checks}) and the miniOrange gateway acting as
 * a real external caller ({@see Gateway_Probe}). The gateway view is what catches CDN, firewall
 * and DNS problems, which a loopback routinely bypasses.
 *
 * Every probe uses a dummy credential except the discovery test, which uses a two-minute
 * read-only token ({@see Test_Token}) revoked as soon as the gateway answers.
 */
class Connectivity_Checker {

	/**
	 * Option caching the last run so the admin page can show a result on load.
	 */
	const LAST_RUN_OPTION = 'mosmcp_connectivity_last_run';

	/**
	 * Transient holding the time before which the next run is refused.
	 */
	const LOCK_TRANSIENT = 'mosmcp_connectivity_lock';

	/**
	 * Minimum seconds between runs. Protects the gateway and this server from a held-down button.
	 */
	const MIN_INTERVAL = 15;

	/**
	 * Runs the full test and caches the result.
	 *
	 * @param Gateway_Probe|null $probe Gateway client; defaults to the real one. Tests inject a fake.
	 * @return array The result; carries `throttled` and `throttle_retry_after` when a recent run was reused.
	 */
	public static function run( ?Gateway_Probe $probe = null ) {
		$wait = self::throttle_remaining();
		if ( $wait > 0 ) {
			$cached = self::last_run();
			if ( null !== $cached ) {
				$cached['throttled']            = true;
				$cached['throttle_retry_after'] = $wait;
				return $cached;
			}
		}

		set_transient( self::LOCK_TRANSIENT, time() + self::MIN_INTERVAL, self::MIN_INTERVAL );

		$probe = $probe ? $probe : new Gateway_Http_Probe();
		$local = self::without_public_base_override( array( Local_Checks::class, 'run' ) );

		$handle  = null;
		$secrets = array();
		$gateway = array(
			'state'       => Result_Builder::GATEWAY_UNREACHABLE,
			'retry_after' => null,
			'report'      => array(),
		);

		try {
			if ( $local['facts']['tables_ok'] && $local['facts']['agent_ok'] ) {
				$handle = Test_Token::mint( get_current_user_id() );
			}

			$request = array( 'site_url' => home_url() );
			if ( $handle ) {
				$secrets[]                       = $handle['token'];
				$request['test_token']           = $handle['token'];
				$request['expected_ability_count'] = (int) $local['facts']['expected_count'];
			}

			$gateway = $probe->probe( $request );
		} finally {
			if ( $handle ) {
				Test_Token::revoke( $handle );
			}
		}

		$rows          = $local['rows'];
		$allowlist     = array();
		$stray         = array();
		$gateway_state = $gateway['state'];

		if ( Result_Builder::GATEWAY_OK === $gateway_state ) {
			$evaluated = Gateway_Result_Evaluator::evaluate(
				$gateway['report'],
				array(
					'expected_count'      => (int) $local['facts']['expected_count'],
					'discovery_requested' => null !== $handle,
					'now'                 => time(),
				)
			);
			$rows      = array_merge( $rows, $evaluated['rows'] );
			$allowlist = $evaluated['allowlist_ips'];
			$stray     = $evaluated['stray_ips'];
		}

		$result = Result_Builder::build(
			$rows,
			array(
				'gateway_state'  => $gateway_state,
				'retry_after'    => $gateway['retry_after'],
				'gateway_reason' => isset( $gateway['reason'] ) ? $gateway['reason'] : '',
				'allowlist_ips'  => $allowlist,
				'stray_ips'      => $stray,
				'env'            => $local['env'],
				'site_url'       => home_url(),
				'expected_count' => (int) $local['facts']['expected_count'],
				'ran_at'         => time(),
				'secrets'        => $secrets,
			)
		);

		update_option( self::LAST_RUN_OPTION, $result, false );

		return $result;
	}

	/**
	 * Returns the cached result of the last run.
	 *
	 * @return array|null Null when never run, or when the cache predates the current result shape.
	 */
	public static function last_run() {
		$cached = get_option( self::LAST_RUN_OPTION, null );

		return ( is_array( $cached ) && isset( $cached['schema'] ) && Result_Builder::SCHEMA === $cached['schema'] ) ? $cached : null;
	}

	/**
	 * Seconds until another run is allowed, 0 when one is.
	 *
	 * @return int
	 */
	private static function throttle_remaining() {
		$until = (int) get_transient( self::LOCK_TRANSIENT );

		return max( 0, $until - time() );
	}

	/**
	 * Runs a callback with the plugin's `MOSMCP_PUBLIC_BASE` URL rewrite disabled, so the loopback
	 * checks reach this server directly instead of looping out through a public tunnel (which is slow
	 * and can deadlock a single-worker server).
	 *
	 * @param callable $fn The callback.
	 * @return mixed The callback's return value.
	 */
	private static function without_public_base_override( callable $fn ) {
		if ( '' === Utils::public_base() ) {
			return $fn();
		}

		$filters  = array( 'home_url', 'site_url', 'rest_url', 'admin_url' );
		$callback = array( Utils::class, 'rewrite_url' );

		foreach ( $filters as $filter ) {
			remove_filter( $filter, $callback, 99 );
		}

		try {
			return $fn();
		} finally {
			foreach ( $filters as $filter ) {
				add_filter( $filter, $callback, 99 );
			}
		}
	}
}
