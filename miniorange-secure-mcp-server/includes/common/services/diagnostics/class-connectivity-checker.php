<?php
/**
 * Runs the "Test Connection" probe set from two vantage points.
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
 * Answers "will an AI client be able to connect to this site?" by running the same set of
 * checks from two vantage points:
 *
 * - This WordPress server's own loopback (`wp_remote_*` to its own endpoints).
 * - The miniOrange gateway, acting as a genuine external caller.
 *
 * A server-only loopback routinely bypasses the Cloudflare/WAF edge, which would give a false
 * "all clear" on exactly the firewall/`.htaccess` issues this feature exists to catch — so the
 * overall pass/fail verdict is driven ONLY by the internet (gateway) results. The server results
 * are kept only as corroborating detail (surfaced via Advanced Details on the frontend): when a
 * check passes from the server but fails from the internet, that disagreement is itself the most
 * common signature of a direct-connection failure.
 *
 * Every probe authenticates with a deliberately invalid/synthetic credential, so this can be run
 * repeatedly with no side effects (no client registered, no token issued) — see the inline notes
 * on {@see registration_check()} and {@see token_check()} for the exact code paths verified.
 */
class Connectivity_Checker {

	/**
	 * Canonical check order, shared with the gateway's `/diagnostics` probe set so both sides
	 * report the identical set of checks.
	 */
	const CHECK_KEYS = array(
		'mcp_health',
		'wellknown_protected_resource',
		'wellknown_authorization_server',
		'scheme_host_consistency',
		'mcp_json_passthrough',
		'www_authenticate_present',
		'auth_header_passthrough',
		'oauth_registration_endpoint',
		'oauth_token_endpoint',
		'oauth_authorization_endpoint',
	);

	/**
	 * Human-readable label per check, kept in sync with the gateway's copy of the same map.
	 */
	const LABELS = array(
		'mcp_health'                     => 'Site reachable',
		'wellknown_protected_resource'   => 'OAuth protected resource metadata',
		'wellknown_authorization_server' => 'OAuth authorization server metadata',
		'scheme_host_consistency'        => 'Advertised URLs match the address used to reach the site',
		'mcp_json_passthrough'           => 'MCP endpoint returns a valid response',
		'www_authenticate_present'       => 'Authentication challenge header present',
		'auth_header_passthrough'        => 'Authorization header reaches WordPress',
		'oauth_registration_endpoint'    => 'Client registration endpoint reachable',
		'oauth_token_endpoint'           => 'Token endpoint reachable',
		'oauth_authorization_endpoint'   => 'Authorization endpoint reachable',
	);

	/**
	 * The gateway's external-prober endpoint (same base as the "Connect via Gateway" URL
	 * already shown on the Connect page).
	 */
	const GATEWAY_DIAGNOSTICS_URL = 'https://gateway.miniorange.ai/diagnostics';

	/**
	 * Per-check timeout for the server-side loopback probes, in seconds.
	 */
	const CHECK_TIMEOUT = 8;

	/**
	 * Timeout for the single call to the gateway, which itself runs the full probe set
	 * sequentially — generous headroom over the sum of its own per-probe timeouts.
	 */
	const GATEWAY_TIMEOUT = 45;

	/**
	 * WordPress option that caches the last run so the admin page can show a result on load.
	 */
	const LAST_RUN_OPTION = 'mosmcp_connectivity_last_run';

	/**
	 * Runs the full check set from both vantage points and caches the result.
	 *
	 * @return array{status:string, failed_checks:string[], checks:array, env:array, retry_after:int|null} The result.
	 */
	public static function run() {
		$server_checks = self::run_server_checks();
		list( $internet_checks, $internet_state, $retry_after ) = self::run_internet_checks();

		$server_by_key   = self::index_by_key( $server_checks );
		$internet_by_key = self::index_by_key( $internet_checks );

		$checks              = array();
		$failed_checks       = array();
		$internet_available  = 'ok' === $internet_state;

		foreach ( self::CHECK_KEYS as $key ) {
			$server   = isset( $server_by_key[ $key ] ) ? $server_by_key[ $key ] : null;
			$internet = isset( $internet_by_key[ $key ] ) ? $internet_by_key[ $key ] : null;

			$checks[] = array(
				'check_key' => $key,
				'label'     => self::LABELS[ $key ],
				'server'    => $server,
				'internet'  => $internet,
			);

			if ( $internet_available && $internet && 'pass' !== $internet['status'] ) {
				$failed_checks[] = $key;
			}
		}

		if ( 'rate_limited' === $internet_state ) {
			$status = 'rate_limited';
		} elseif ( ! $internet_available ) {
			$status = 'unavailable';
		} elseif ( empty( $failed_checks ) ) {
			$status = 'ok';
		} else {
			$status = 'issue';
		}

		$result = array(
			'status'        => $status,
			'failed_checks' => $failed_checks,
			'checks'        => $checks,
			'env'           => self::env_snapshot(),
			'retry_after'   => $retry_after,
		);

		update_option( self::LAST_RUN_OPTION, $result, false );

		return $result;
	}

	/**
	 * Returns the cached result of the last run, if any.
	 *
	 * @return array|null The cached result, or null if a check has never been run.
	 */
	public static function last_run() {
		$cached = get_option( self::LAST_RUN_OPTION, null );

		return is_array( $cached ) ? $cached : null;
	}

	/**
	 * Indexes a list of check rows by their check_key.
	 *
	 * @param array $checks The check rows.
	 * @return array<string, array>
	 */
	private static function index_by_key( array $checks ) {
		$by_key = array();
		foreach ( $checks as $check ) {
			$by_key[ $check['check_key'] ] = $check;
		}

		return $by_key;
	}

	/**
	 * Runs the full probe set from this server's own loopback.
	 *
	 * @return array The check rows.
	 */
	private static function run_server_checks() {
		return self::without_public_base_override(
			function () {
				list( $health_check )    = self::health_check();
				list( $prm_check )       = self::fetch_json( home_url( '/.well-known/oauth-protected-resource' ), 'wellknown_protected_resource' );
				list( $asm_check )       = self::fetch_json( home_url( '/.well-known/oauth-authorization-server' ), 'wellknown_authorization_server' );

				list( $mcp_json_check, $www_auth_check, $auth_header_check ) = self::mcp_challenge_checks( Utils::resource_url() );

				return array(
					$health_check,
					$prm_check,
					$asm_check,
					// Comparing "what the site advertises" against "the address used to reach it" is
					// only meaningful from a genuinely external caller — the server comparing its own
					// config against itself would trivially always agree. See the internet-side check.
					self::make_check( 'scheme_host_consistency', 'unavailable', null, 0, 'only meaningful when checked from the internet' ),
					$mcp_json_check,
					$www_auth_check,
					$auth_header_check,
					self::registration_check( rest_url( MOSMCP_REST_NAMESPACE . '/register' ) ),
					self::token_check( rest_url( MOSMCP_REST_NAMESPACE . '/token' ) ),
					self::authorize_check( admin_url( 'admin-post.php?action=mosmcp_authorize' ) ),
				);
			}
		);
	}

	/**
	 * Runs a callback with the plugin's `MOSMCP_PUBLIC_BASE` URL rewrite (see
	 * {@see Utils::rewrite_url()}) temporarily disabled, so `home_url()`/`site_url()`/
	 * `rest_url()`/`admin_url()` resolve to this server's real local address instead of the
	 * public tunnel URL used to advertise the site to external MCP clients.
	 *
	 * Without this, the "server" vantage — meant to be a same-machine loopback — would instead
	 * route back out through the public tunnel and back in, which is both slow and, on a
	 * resource-constrained server, can self-deadlock (the request ties up the one Apache worker
	 * it's waiting on to also serve the incoming tunnel request).
	 *
	 * @param callable $fn The callback to run with local, unrewritten URLs.
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
	 * touch is the read at line 320 — `Store::delete_code()` never fires for a code that was
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
