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
	 * Option set when something suggests the admin should run the test (a client saw 0 abilities).
	 */
	const AUTO_RUN_OPTION = 'mosmcp_connectivity_autorun';

	/**
	 * Transient that blocks a flood of auto-run requests.
	 */
	const AUTO_RUN_COOLDOWN = 'mosmcp_connectivity_autorun_cooldown';

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
		// A pending auto-run means something changed since the last run, so a cached result may be stale.
		if ( $wait > 0 && null === self::pending_auto_run() ) {
			$cached = self::last_run();
			if ( null !== $cached ) {
				$cached['throttled']            = true;
				$cached['throttle_retry_after'] = $wait;
				return $cached;
			}
		}

		set_transient( self::LOCK_TRANSIENT, time() + self::MIN_INTERVAL, self::MIN_INTERVAL );
		delete_option( self::AUTO_RUN_OPTION );

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
	 * Asks the admin UI to run the test next time it loads. At most once an hour.
	 *
	 * @param string $reason Why (for the UI to explain).
	 * @return void
	 */
	public static function flag_auto_run( $reason ) {
		if ( get_transient( self::AUTO_RUN_COOLDOWN ) ) {
			return;
		}

		set_transient( self::AUTO_RUN_COOLDOWN, 1, HOUR_IN_SECONDS );
		update_option(
			self::AUTO_RUN_OPTION,
			array(
				'reason' => (string) $reason,
				'at'     => time(),
			),
			false
		);
	}

	/**
	 * The pending auto-run request, if any.
	 *
	 * @return array{reason:string, at:int}|null
	 */
	public static function pending_auto_run() {
		$pending = get_option( self::AUTO_RUN_OPTION, null );

		return ( is_array( $pending ) && isset( $pending['reason'] ) ) ? $pending : null;
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

	/**
	 * Calls the gateway's external-vantage probe set.
	 *
	 * Distinguishes a rate-limited response (429, from the gateway's own per-endpoint throttle)
	 * from a genuine outage/unreachable gateway — otherwise both collapse into the same
	 * "couldn't check from an AI client's view" message, which misleads a user who is simply
	 * being throttled into thinking the service is down.
	 *
	 * @return array{0: array, 1: string, 2: int|null} The check rows, the vantage state
	 *                                                   ("ok"|"unreachable"|"rate_limited"), and
	 *                                                   the Retry-After seconds when rate-limited.
	 */
	private static function run_internet_checks() {
		$response = wp_remote_post(
			self::GATEWAY_DIAGNOSTICS_URL,
			array(
				'timeout' => self::GATEWAY_TIMEOUT,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( array( 'site_url' => home_url() ) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( array(), 'unreachable', null );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 429 === $code ) {
			$retry_after = wp_remote_retrieve_header( $response, 'retry-after' );
			return array( array(), 'rate_limited', is_numeric( $retry_after ) ? (int) $retry_after : null );
		}

		if ( 200 !== $code ) {
			return array( array(), 'unreachable', null );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || ! isset( $data['checks'] ) || ! is_array( $data['checks'] ) ) {
			return array( array(), 'unreachable', null );
		}

		return array( $data['checks'], 'ok', null );
	}

	/**
	 * Probes this site's own /health endpoint.
	 *
	 * @return array{0: array, 1: array|null} The check row and the parsed health document.
	 */
	private static function health_check() {
		$url      = rest_url( MOSMCP_REST_NAMESPACE . '/health' );
		$start    = microtime( true );
		$response = wp_remote_get( $url, array( 'timeout' => self::CHECK_TIMEOUT ) );
		$ms       = self::elapsed_ms( $start );

		$classified = self::classify_transport_error( $response );
		if ( null !== $classified ) {
			return array( self::make_check( 'mcp_health', 'fail', null, $ms, $classified ), null );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return array( self::make_check( 'mcp_health', 'fail', $code, $ms, "expected 200, got {$code}" ), null );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || 'mosmcp' !== ( isset( $data['service'] ) ? $data['service'] : null ) ) {
			return array( self::make_check( 'mcp_health', 'fail', $code, $ms, 'response was not a valid health document' ), null );
		}

		return array( self::make_check( 'mcp_health', 'pass', $code, $ms, 'ok' ), $data );
	}

	/**
	 * GETs a URL and validates the response is a 200 JSON object.
	 *
	 * @param string $url       The URL to fetch.
	 * @param string $check_key The check key this fetch backs.
	 * @return array{0: array, 1: array|null} The check row and the parsed body, if any.
	 */
	private static function fetch_json( $url, $check_key ) {
		$start    = microtime( true );
		$response = wp_remote_get( $url, array( 'timeout' => self::CHECK_TIMEOUT ) );
		$ms       = self::elapsed_ms( $start );

		$classified = self::classify_transport_error( $response );
		if ( null !== $classified ) {
			return array( self::make_check( $check_key, 'fail', null, $ms, $classified ), null );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return array( self::make_check( $check_key, 'fail', $code, $ms, "expected 200, got {$code}" ), null );
		}

		$content_type = wp_remote_retrieve_header( $response, 'content-type' );
		if ( ! is_string( $content_type ) || 0 !== strpos( $content_type, 'application/json' ) ) {
			return array( self::make_check( $check_key, 'fail', $code, $ms, 'response was not JSON — likely a firewall or CDN block page' ), null );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return array( self::make_check( $check_key, 'fail', $code, $ms, 'response body did not parse as JSON' ), null );
		}

		return array( self::make_check( $check_key, 'pass', $code, $ms, 'ok' ), $data );
	}

	/**
	 * Sends one POST to the real /mcp transport and reads three independent signals off the
	 * single response: whether the JSON-RPC body survived unmangled, whether the spec-required
	 * WWW-Authenticate challenge is present, and (via the diagnostic marker header handled in
	 * {@see \MoSMCP\Common\Controllers\MCP\REST_Controller::add_challenge_header()}) whether the
	 * Authorization request header actually reached WordPress.
	 *
	 * @param string $mcp_endpoint The MCP endpoint URL.
	 * @return array{0: array, 1: array, 2: array} The three check rows.
	 */
	private static function mcp_challenge_checks( $mcp_endpoint ) {
		$start    = microtime( true );
		$response = wp_remote_post(
			$mcp_endpoint,
			array(
				'timeout' => self::CHECK_TIMEOUT,
				'headers' => array(
					'Authorization'             => 'Bearer mosmcp-diagnostic-probe',
					'X-MOSMCP-Diagnostic-Probe' => '1',
					'Content-Type'              => 'application/json',
					'Accept'                    => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'jsonrpc' => '2.0',
						'id'      => 1,
						'method'  => 'initialize',
						'params'  => array(
							'protocolVersion' => '2025-06-18',
							'capabilities'    => array(),
							'clientInfo'      => array(
								'name'    => 'mosmcp-self-check',
								'version' => defined( 'MOSMCP_VERSION' ) ? MOSMCP_VERSION : '',
							),
						),
					)
				),
			)
		);
		$ms = self::elapsed_ms( $start );

		$classified = self::classify_transport_error( $response );
		if ( null !== $classified ) {
			return array(
				self::make_check( 'mcp_json_passthrough', 'fail', null, $ms, $classified ),
				self::make_check( 'www_authenticate_present', 'fail', null, $ms, $classified ),
				self::make_check( 'auth_header_passthrough', 'fail', null, $ms, $classified ),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		$is_json_error     = false;
		$json_shape_detail = "expected a 401 response, got status {$code}";
		if ( 401 === $code ) {
			$data          = json_decode( wp_remote_retrieve_body( $response ), true );
			$is_json_error = is_array( $data ) && ( isset( $data['error'] ) || isset( $data['jsonrpc'] ) );
			if ( ! $is_json_error ) {
				$json_shape_detail = 'got a 401, but the response body is not JSON-RPC shaped (missing "jsonrpc"/"error")';
			}
		}

		$has_www_auth = '' !== (string) wp_remote_retrieve_header( $response, 'www-authenticate' );
		$seen_header  = wp_remote_retrieve_header( $response, 'x-mosmcp-auth-header-seen' );

		return array(
			self::make_check(
				'mcp_json_passthrough',
				$is_json_error ? 'pass' : 'fail',
				$code,
				$ms,
				$is_json_error ? 'ok' : $json_shape_detail
			),
			self::make_check(
				'www_authenticate_present',
				$has_www_auth ? 'pass' : 'fail',
				$code,
				$ms,
				$has_www_auth ? 'ok' : 'no WWW-Authenticate header on the 401 response'
			),
			self::make_check(
				'auth_header_passthrough',
				'1' === $seen_header ? 'pass' : 'fail',
				$code,
				$ms,
				'1' === $seen_header ? 'ok' : 'the Authorization header did not reach WordPress'
			),
		);
	}

	/**
	 * Probes the DCR registration endpoint with a deliberately invalid body.
	 *
	 * Verified against `OAuth_Server::register_client()` (`class-oauth-server.php:59-61`): the
	 * empty-`redirect_uris` check returns 400 before `Store::purge_expired()` or
	 * `Store::insert_client()` are ever reached — no client row is created.
	 *
	 * @param string $registration_endpoint The registration endpoint URL.
	 * @return array The check row.
	 */
	private static function registration_check( $registration_endpoint ) {
		$start    = microtime( true );
		$response = wp_remote_post(
			$registration_endpoint,
			array(
				'timeout' => self::CHECK_TIMEOUT,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( array() ),
			)
		);
		$ms = self::elapsed_ms( $start );

		$classified = self::classify_transport_error( $response );
		if ( null !== $classified ) {
			return self::make_check( 'oauth_registration_endpoint', 'fail', null, $ms, $classified );
		}

		$code  = (int) wp_remote_retrieve_response_code( $response );
		$clean = self::is_clean_oauth_error( $code, wp_remote_retrieve_body( $response ) );

		return self::make_check(
			'oauth_registration_endpoint',
			$clean ? 'pass' : 'fail',
			$code,
			$ms,
			$clean ? 'ok' : "expected a clean 4xx OAuth error, got status {$code}"
		);
	}

	/**
	 * Probes the token endpoint with a deliberately invalid grant.
	 *
	 * Verified against `grant_authorization_code()` (`class-oauth-server.php:316-326`): omitting
	 * `code_verifier` returns `invalid_request` before `Store::get_code()` runs; even with a
	 * `code_verifier` present, an unmatched code returns `invalid_grant` and the only prior DB
	 * touch is the read at line 320 — `Store::claim_code()` never fires for a code that was
	 * never found. No token is issued, no rows are touched, either way.
	 *
	 * @param string $token_endpoint The token endpoint URL.
	 * @return array The check row.
	 */
	private static function token_check( $token_endpoint ) {
		$start    = microtime( true );
		$response = wp_remote_post(
			$token_endpoint,
			array(
				'timeout' => self::CHECK_TIMEOUT,
				'body'    => array(
					'grant_type' => 'authorization_code',
					'code'       => 'mosmcp-diagnostic-invalid',
				),
			)
		);
		$ms = self::elapsed_ms( $start );

		$classified = self::classify_transport_error( $response );
		if ( null !== $classified ) {
			return self::make_check( 'oauth_token_endpoint', 'fail', null, $ms, $classified );
		}

		$code  = (int) wp_remote_retrieve_response_code( $response );
		$clean = self::is_clean_oauth_error( $code, wp_remote_retrieve_body( $response ) );

		return self::make_check(
			'oauth_token_endpoint',
			$clean ? 'pass' : 'fail',
			$code,
			$ms,
			$clean ? 'ok' : "expected a clean 4xx OAuth error, got status {$code}"
		);
	}

	/**
	 * Probes the authorization endpoint anonymously with no parameters. The plugin's own code
	 * path for this (`OAuth_Server::authorize()`, `class-oauth-server.php:153-156`) returns a
	 * definite 400 (`wp_die` on an unknown/empty client_id) rather than any real client flow —
	 * anything else (403, 5xx, a stray 200) suggests a WAF or other interference.
	 *
	 * @param string $authorization_endpoint The authorization endpoint URL.
	 * @return array The check row.
	 */
	private static function authorize_check( $authorization_endpoint ) {
		$start    = microtime( true );
		$response = wp_remote_get(
			$authorization_endpoint,
			array(
				'timeout'     => self::CHECK_TIMEOUT,
				'redirection' => 0,
			)
		);
		$ms = self::elapsed_ms( $start );

		$classified = self::classify_transport_error( $response );
		if ( null !== $classified ) {
			return self::make_check( 'oauth_authorization_endpoint', 'fail', null, $ms, $classified );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$ok   = 400 === $code || ( $code >= 300 && $code < 400 );

		return self::make_check(
			'oauth_authorization_endpoint',
			$ok ? 'pass' : 'fail',
			$code,
			$ms,
			$ok ? 'ok' : "expected 400 or a redirect, got status {$code}"
		);
	}

	/**
	 * Whether an HTTP response looks like a clean OAuth error document (4xx + a JSON `error`
	 * field) as opposed to a block page, a 5xx, or a raw connection failure.
	 *
	 * @param int    $code     The HTTP status code.
	 * @param string $raw_body The raw response body.
	 * @return bool
	 */
	private static function is_clean_oauth_error( $code, $raw_body ) {
		if ( $code < 400 || $code >= 500 ) {
			return false;
		}
		$data = json_decode( $raw_body, true );

		return is_array( $data ) && isset( $data['error'] );
	}

	/**
	 * Classifies a `wp_remote_*` transport failure into a short, user-facing reason.
	 *
	 * @param array|\WP_Error $response The value returned by `wp_remote_get()`/`wp_remote_post()`.
	 * @return string|null The classification, or null when `$response` was not a WP_Error.
	 */
	private static function classify_transport_error( $response ) {
		if ( ! is_wp_error( $response ) ) {
			return null;
		}

		$message = strtolower( $response->get_error_message() );

		if ( false !== strpos( $message, 'could not resolve host' ) || false !== strpos( $message, 'name or service not known' ) ) {
			return 'dns';
		}
		if ( false !== strpos( $message, 'ssl' ) || false !== strpos( $message, 'certificate' ) ) {
			return 'tls';
		}
		if ( false !== strpos( $message, 'connection refused' ) ) {
			return 'refused';
		}
		if ( false !== strpos( $message, 'timed out' ) || false !== strpos( $message, 'timeout' ) ) {
			return 'timeout';
		}

		return 'unknown';
	}

	/**
	 * Builds a standardized check row.
	 *
	 * @param string   $check_key   The check key.
	 * @param string   $status      "pass" | "fail" | "unavailable".
	 * @param int|null $http_status The HTTP status code, if any.
	 * @param int      $duration_ms Elapsed time in milliseconds.
	 * @param string   $detail      A short, human-readable detail string.
	 * @return array
	 */
	private static function make_check( $check_key, $status, $http_status, $duration_ms, $detail ) {
		return array(
			'check_key'   => $check_key,
			'label'       => self::LABELS[ $check_key ],
			'status'      => $status,
			'http_status' => $http_status,
			'duration_ms' => $duration_ms,
			'detail'      => $detail,
		);
	}

	/**
	 * Milliseconds elapsed since a `microtime( true )` start timestamp.
	 *
	 * @param float $start The start timestamp.
	 * @return int
	 */
	private static function elapsed_ms( $start ) {
		return (int) round( ( microtime( true ) - $start ) * 1000 );
	}

	/**
	 * A secret-free environment snapshot for the "Copy report for support" payload. Every probe
	 * above uses synthetic credentials, so nothing here (or in the check results) is sensitive.
	 *
	 * @return array
	 */
	private static function env_snapshot() {
		return array(
			'wordpress_version'   => get_bloginfo( 'version' ),
			'php_version'         => phpversion(),
			'plugin_version'      => defined( 'MOSMCP_VERSION' ) ? MOSMCP_VERSION : '',
			'server_software'     => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '',
			'permalink_structure' => get_option( 'permalink_structure' ) ? 'pretty' : 'plain',
		);
	}
}
