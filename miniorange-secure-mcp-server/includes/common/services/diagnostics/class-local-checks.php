<?php
/**
 * The connection test's checks that run inside WordPress: setup state, site address, loopback requests.
 *
 * @package Miniorange_Secure_MCP_Server
 */

namespace MoSMCP\Common\Services\Diagnostics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MoSMCP\Common\Migration\Migration;
use MoSMCP\Common\Repositories\Audit_Store;
use MoSMCP\Common\Repositories\Debug_Store;
use MoSMCP\Common\Repositories\NHI_Store;
use MoSMCP\Common\Repositories\Store;
use MoSMCP\Common\Services\MCP\MCP_Server;
use MoSMCP\Common\Utils\Utils;

/**
 * Class Local_Checks
 *
 * Gathers facts, then delegates every verdict to the pure evaluators. Each check runs inside
 * {@see guard()} so one that throws becomes a failed row instead of aborting the rest.
 */
class Local_Checks {

	/**
	 * Dummy bearer value; never a real credential.
	 */
	const PROBE_BEARER = 'mosmcp-diagnostic-probe';

	/**
	 * Timeout for the certificate fetch, in seconds.
	 */
	const CERT_TIMEOUT = 4;

	/**
	 * Runs every local check. Call with the public-base URL rewrite disabled so loopbacks hit this server directly.
	 *
	 * @return array{rows:array[], env:array, facts:array{expected_count:int, tables_ok:bool, agent_ok:bool}}
	 */
	public static function run() {
		$facts = array(
			'expected_count' => 0,
			'tables_ok'      => false,
			'agent_ok'       => false,
		);
		$rows  = array();

		$rows[] = self::guard(
			'plugin_tables',
			__( 'Plugin database tables', 'miniorange-secure-mcp-server' ),
			function () use ( &$facts ) {
				$verdict            = Setup_Evaluator::evaluate_tables( self::expected_tables(), self::present_tables() );
				$facts['tables_ok'] = Response_Classifier::PASS === $verdict['status'];
				return $verdict;
			}
		);

		$rows[] = self::guard(
			'schema_version',
			__( 'Database schema version', 'miniorange-secure-mcp-server' ),
			function () {
				return Setup_Evaluator::evaluate_schema_version( get_option( Migration::DB_VERSION_OPTION, false ), MOSMCP_VERSION );
			}
		);

		$rows[] = self::guard(
			'agent_enabled',
			__( 'An agent is enabled', 'miniorange-secure-mcp-server' ),
			function () use ( &$facts ) {
				if ( ! $facts['tables_ok'] ) {
					return self::skipped( __( 'Skipped: the plugin tables are missing.', 'miniorange-secure-mcp-server' ) );
				}
				$verdict           = Setup_Evaluator::evaluate_agents( NHI_Store::count_all(), NHI_Store::count_enabled() );
				$facts['agent_ok'] = Response_Classifier::PASS === $verdict['status'];
				return $verdict;
			}
		);

		$rows[] = self::guard(
			'agent_grants',
			__( 'Abilities are granted to a role', 'miniorange-secure-mcp-server' ),
			function () use ( &$facts ) {
				if ( ! $facts['agent_ok'] ) {
					return self::skipped( __( 'Skipped: no enabled agent.', 'miniorange-secure-mcp-server' ) );
				}
				$registered = function_exists( 'wp_get_abilities' ) ? count( wp_get_abilities() ) : 0;
				$exposed    = MCP_Server::count_exposed_for_user( get_current_user_id() );

				$facts['expected_count'] = $exposed;

				return Setup_Evaluator::evaluate_grants( $registered, NHI_Store::count_ability_grants(), $exposed );
			}
		);

		$rows[] = self::guard(
			'site_https',
			__( 'Site address uses https', 'miniorange-secure-mcp-server' ),
			function () {
				$urls = Setup_Evaluator::evaluate_urls( self::url_facts() );
				return $urls['https'];
			}
		);

		$rows[] = self::guard(
			'site_hosts',
			__( 'Site address is consistent (www / non-www)', 'miniorange-secure-mcp-server' ),
			function () {
				$urls = Setup_Evaluator::evaluate_urls( self::url_facts() );
				return $urls['hosts'];
			}
		);

		$rows[] = self::guard(
			'site_certificate',
			__( 'HTTPS certificate', 'miniorange-secure-mcp-server' ),
			function () {
				return self::certificate_verdict();
			}
		);

		$responses = array();
		try {
			$responses = Http_Probe::batch( self::loopback_requests() );
		} catch ( \Throwable $e ) {
			$responses = array();
		}

		foreach ( self::http_rows( $responses ) as $row ) {
			$rows[] = $row;
		}

		$env    = self::environment( $responses );
		$rows[] = self::guard(
			'environment',
			__( 'Server environment', 'miniorange-secure-mcp-server' ),
			function () use ( $env ) {
				return Response_Classifier::verdict( Response_Classifier::PASS, 'ok', self::environment_summary( $env ) );
			}
		);

		foreach ( self::token_rows( $facts['tables_ok'] ) as $row ) {
			$rows[] = $row;
		}

		return array(
			'rows'  => $rows,
			'env'   => $env,
			'facts' => $facts,
		);
	}

	/**
	 * Runs one check, turning an exception into a failed row.
	 *
	 * @param string   $id    Row id.
	 * @param string   $label Row label.
	 * @param callable $fn    Returns a verdict.
	 * @return array
	 */
	private static function guard( $id, $label, callable $fn ) {
		try {
			$verdict = $fn();
		} catch ( \Throwable $e ) {
			/* translators: %s: error message. */
			$verdict = Response_Classifier::verdict( Response_Classifier::FAIL, 'check_error', sprintf( __( 'This check could not run: %s', 'miniorange-secure-mcp-server' ), Redactor::redact_text( substr( $e->getMessage(), 0, 200 ) ) ) );
		}

		return self::row( $id, $label, $verdict );
	}

	/**
	 * Builds a display row.
	 *
	 * @param string $id      Row id.
	 * @param string $label   Row label.
	 * @param array  $verdict Verdict.
	 * @param array  $extra   Optional http_status and duration_ms.
	 * @return array
	 */
	private static function row( $id, $label, array $verdict, array $extra = array() ) {
		return array(
			'id'          => $id,
			'group'       => 'site',
			'label'       => $label,
			'status'      => $verdict['status'],
			'code'        => $verdict['code'],
			'detail'      => $verdict['detail'],
			'http_status' => isset( $extra['http_status'] ) ? $extra['http_status'] : null,
			'duration_ms' => null,
			'meta'        => array(),
		);
	}

	/**
	 * A skipped verdict.
	 *
	 * @param string $reason Why.
	 * @return array
	 */
	private static function skipped( $reason ) {
		return Response_Classifier::verdict( Response_Classifier::SKIP, 'not_reached', $reason );
	}

	/**
	 * Table names the schema defines.
	 *
	 * @return string[]
	 */
	private static function expected_tables() {
		return array(
			Store::table( 'clients' ),
			Store::table( 'codes' ),
			Store::table( 'tokens' ),
			NHI_Store::table(),
			NHI_Store::grants_table(),
			Audit_Store::table(),
			Debug_Store::table(),
		);
	}

	/**
	 * Which of this plugin's tables exist.
	 *
	 * @return string[]
	 */
	private static function present_tables() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'mosmcp_' ) . '%' ) );

		return is_array( $found ) ? $found : array();
	}

	/**
	 * Facts for the site-address evaluation, read from stored settings rather than the filtered URL helpers.
	 *
	 * @return array{home:string, siteurl:string, request_host:string, localhost:bool}
	 */
	private static function url_facts() {
		$public = Utils::public_base();

		return array(
			'home'         => '' !== $public ? $public : (string) get_option( 'home' ),
			'siteurl'      => '' !== $public ? $public : (string) get_option( 'siteurl' ),
			'request_host' => isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '',
			'localhost'    => Utils::is_localhost_site(),
		);
	}

	/**
	 * Fetches the certificate the site presents and judges it.
	 *
	 * @return array
	 */
	private static function certificate_verdict() {
		$facts = self::url_facts();
		$parts = wp_parse_url( $facts['home'] );
		$host  = is_array( $parts ) && isset( $parts['host'] ) ? $parts['host'] : '';

		if ( 'https' !== ( is_array( $parts ) && isset( $parts['scheme'] ) ? $parts['scheme'] : '' ) || '' === $host || $facts['localhost'] ) {
			return self::skipped( __( 'Skipped: the site is not served over public https.', 'miniorange-secure-mcp-server' ) );
		}
		if ( ! function_exists( 'openssl_x509_parse' ) ) {
			return self::skipped( __( 'Skipped: the PHP OpenSSL extension is not available.', 'miniorange-secure-mcp-server' ) );
		}

		return Setup_Evaluator::evaluate_certificate( self::fetch_certificate( $host, isset( $parts['port'] ) ? (int) $parts['port'] : 443 ), $host, time() );
	}

	/**
	 * Reads the leaf certificate presented by a host, without trusting it (trust is judged by the loopback requests).
	 *
	 * @param string $host Host name.
	 * @param int    $port Port.
	 * @return array{error?:string, valid_to?:int, names?:string[]}
	 */
	private static function fetch_certificate( $host, $port ) {
		$context = stream_context_create(
			array(
				'ssl' => array(
					'capture_peer_cert' => true,
					'verify_peer'       => false,
					'verify_peer_name'  => false,
					'SNI_enabled'       => true,
					'peer_name'         => $host,
				),
			)
		);

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A refused connection is an expected, reported outcome.
		$stream = @stream_socket_client( "ssl://{$host}:{$port}", $errno, $errstr, self::CERT_TIMEOUT, STREAM_CLIENT_CONNECT, $context );
		if ( ! is_resource( $stream ) ) {
			return array( 'error' => 'connect' );
		}

		$params = stream_context_get_params( $stream );
		fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- A socket, not a file.

		$cert   = isset( $params['options']['ssl']['peer_certificate'] ) ? openssl_x509_parse( $params['options']['ssl']['peer_certificate'] ) : false;
		$parsed = is_array( $cert ) ? $cert : array();
		if ( empty( $parsed ) ) {
			return array( 'error' => 'parse' );
		}

		$names = array();
		if ( isset( $parsed['extensions']['subjectAltName'] ) ) {
			foreach ( explode( ',', $parsed['extensions']['subjectAltName'] ) as $entry ) {
				$entry = trim( $entry );
				if ( 0 === strpos( $entry, 'DNS:' ) ) {
					$names[] = substr( $entry, 4 );
				}
			}
		}
		if ( empty( $names ) && isset( $parsed['subject']['CN'] ) ) {
			$names[] = (string) $parsed['subject']['CN'];
		}

		return array(
			'valid_to' => isset( $parsed['validTo_time_t'] ) ? (int) $parsed['validTo_time_t'] : 0,
			'names'    => $names,
		);
	}

	/**
	 * The loopback requests, all sent together.
	 *
	 * @return array<string, array>
	 */
	private static function loopback_requests() {
		$ns = MOSMCP_REST_NAMESPACE;

		return array(
			'health_pretty'   => array( 'url' => home_url( '/wp-json/' . $ns . '/health' ) ),
			'health_fallback' => array( 'url' => home_url( '/index.php' ) . '?rest_route=/' . $ns . '/health' ),
			'mcp'             => array(
				'url'     => Utils::resource_url(),
				'method'  => 'POST',
				'headers' => array(
					'Authorization'             => 'Bearer ' . self::PROBE_BEARER,
					// Same two headers the gateway sends, so the result matches real gateway traffic.
					'X-MO-Authorization'        => 'Bearer ' . self::PROBE_BEARER,
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
							'capabilities'    => new \stdClass(),
							'clientInfo'      => array(
								'name'    => 'mosmcp-self-check',
								'version' => MOSMCP_VERSION,
							),
						),
					)
				),
			),
			'register'        => array(
				'url'     => rest_url( $ns . '/register' ),
				'method'  => 'POST',
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( new \stdClass() ),
			),
			'token'           => array(
				'url'    => rest_url( $ns . '/token' ),
				'method' => 'POST',
				'body'   => array(
					'grant_type' => 'authorization_code',
					'code'       => 'mosmcp-diagnostic-invalid',
				),
			),
			'authorize'       => array( 'url' => admin_url( 'admin-post.php?action=mosmcp_authorize' ) ),
		);
	}

	/**
	 * Turns the loopback responses into rows.
	 *
	 * @param array<string, array> $res Normalized responses, empty when the batch itself failed.
	 * @return array[]
	 */
	private static function http_rows( array $res ) {
		$labels = array(
			'mcp_health'                     => __( 'REST API reachable (/wp-json/ and ?rest_route=)', 'miniorange-secure-mcp-server' ),
			'mcp_json_passthrough'           => __( 'MCP endpoint answers 401 without a valid token', 'miniorange-secure-mcp-server' ),
			'auth_header_passthrough'        => __( 'Authorization header reaches WordPress', 'miniorange-secure-mcp-server' ),
			'oauth_registration_endpoint'    => __( 'Client registration endpoint', 'miniorange-secure-mcp-server' ),
			'oauth_token_endpoint'           => __( 'Token endpoint', 'miniorange-secure-mcp-server' ),
			'oauth_authorization_endpoint'   => __( 'Authorization endpoint', 'miniorange-secure-mcp-server' ),
		);

		$none   = Response_Classifier::response( 0, array(), '', 'no response' );
		$get    = function ( $key ) use ( $res, $none ) {
			return isset( $res[ $key ] ) ? $res[ $key ] : $none;
		};

		$pretty   = Response_Classifier::classify_health( $get( 'health_pretty' ) );
		$fallback = Response_Classifier::classify_health( $get( 'health_fallback' ) );
		$mcp      = Response_Classifier::classify_mcp_endpoint( $get( 'mcp' ) );

		$verdicts = array(
			'mcp_health'                     => Setup_Evaluator::evaluate_rest_paths( $pretty, $fallback ),
			'mcp_json_passthrough'           => $mcp['mcp'],
			'auth_header_passthrough'        => $mcp['auth_header'],
			'oauth_registration_endpoint'    => Response_Classifier::classify_oauth_error( $get( 'register' ) ),
			'oauth_token_endpoint'           => Response_Classifier::classify_oauth_error( $get( 'token' ) ),
			'oauth_authorization_endpoint'   => Response_Classifier::classify_authorize( $get( 'authorize' ) ),
		);

		$status_for = array(
			'mcp_health'                     => 'health_pretty',
			'mcp_json_passthrough'           => 'mcp',
			'auth_header_passthrough'        => 'mcp',
			'oauth_registration_endpoint'    => 'register',
			'oauth_token_endpoint'           => 'token',
			'oauth_authorization_endpoint'   => 'authorize',
		);

		$rows = array();
		foreach ( $verdicts as $id => $verdict ) {
			$http   = $get( $status_for[ $id ] )['status'];
			$rows[] = self::row( $id, $labels[ $id ], $verdict, array( 'http_status' => $http > 0 ? $http : null ) );
		}

		return $rows;
	}

	/**
	 * Summarizes the environment: versions, web server, layers, and whether .htaccess already carries the header rule.
	 *
	 * @param array<string, array> $res Loopback responses (used for CDN/cache headers).
	 * @return array
	 */
	private static function environment( array $res ) {
		$headers  = isset( $res['health_pretty']['headers'] ) ? $res['health_pretty']['headers'] : array();
		$software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '';
		$active   = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}

		$detected = Environment_Detector::detect( $headers, $active, $software, PHP_SAPI );

		return array_merge(
			$detected,
			array(
				'wordpress_version'   => get_bloginfo( 'version' ),
				'php_version'         => PHP_VERSION,
				'plugin_version'      => MOSMCP_VERSION,
				'server_software'     => $software,
				'permalink_structure' => get_option( 'permalink_structure' ) ? 'pretty' : 'plain',
				'htaccess_has_rule'   => 'htaccess' === $detected['auth_fix'] && self::htaccess_has_auth_rule(),
			)
		);
	}

	/**
	 * Whether .htaccess already mentions the Authorization pass-through (read only; nothing is written).
	 *
	 * @return bool
	 */
	private static function htaccess_has_auth_rule() {
		$path = ( function_exists( 'get_home_path' ) ? get_home_path() : ABSPATH ) . '.htaccess';
		if ( ! is_readable( $path ) ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A bounded local read of .htaccess.
		$contents = file_get_contents( $path, false, null, 0, 65536 );

		return false !== $contents && false !== stripos( $contents, 'HTTP_AUTHORIZATION' );
	}

	/**
	 * One-line environment description for the info row.
	 *
	 * @param array $env Environment.
	 * @return string
	 */
	private static function environment_summary( array $env ) {
		/* translators: 1: PHP version, 2: PHP handler (SAPI), 3: web server software. */
		$line = sprintf( __( 'PHP %1$s (%2$s) on %3$s.', 'miniorange-secure-mcp-server' ), $env['php_version'], $env['sapi'], '' !== $env['server_software'] ? $env['server_software'] : __( 'an unidentified web server', 'miniorange-secure-mcp-server' ) );

		if ( ! empty( $env['layers'] ) ) {
			/* translators: %s: comma-separated names of detected layers. */
			$line .= ' ' . sprintf( __( 'Detected: %s.', 'miniorange-secure-mcp-server' ), implode( ', ', $env['layers'] ) );
		}

		return $line;
	}

	/**
	 * One row per client with stored tokens, showing last refresh age.
	 *
	 * @param bool $tables_ok Whether the tables exist.
	 * @return array[]
	 */
	private static function token_rows( $tables_ok ) {
		if ( ! $tables_ok ) {
			return array();
		}

		$rows = array();
		try {
			$clients = Store::linked_client_tokens( Test_Token::CLIENT_PREFIX );
		} catch ( \Throwable $e ) {
			return array();
		}

		foreach ( $clients as $i => $client ) {
			/* translators: %s: client name. */
			$label  = sprintf( __( 'Sign-in: %s', 'miniorange-secure-mcp-server' ), '' !== $client['client_name'] ? $client['client_name'] : __( 'Unnamed client', 'miniorange-secure-mcp-server' ) );
			$rows[] = self::row( 'token_local_' . $i, $label, Gateway_Result_Evaluator::evaluate_linked_client( $client, time() ) );
		}

		return $rows;
	}
}
