<?php
/**
 * The MCP transport endpoint and its bearer-token authentication.
 *
 * @package Miniorange_Secure_MCP_Server
 */

namespace MoSMCP\Common\Controllers\MCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MoSMCP\Common\Repositories\NHI_Store;
use MoSMCP\Common\Repositories\Store;
use MoSMCP\Common\Services\Discovery\Discovery;
use MoSMCP\Common\Services\Logging\Debug_Logger;
use MoSMCP\Common\Services\MCP\MCP_Server;
use MoSMCP\Common\Services\OAuth\Tokens;
use MoSMCP\Common\Utils\Utils;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Class REST_Controller
 *
 * Registers the single MCP endpoint (Streamable HTTP, POST + JSON), validates
 * the OAuth bearer token on each request, and hands the JSON-RPC payload to
 * {@see MCP_Server}. Route registration lives in {@see Rest_Routes}.
 */
class REST_Controller {

	/**
	 * Returns a public health/discovery document.
	 *
	 * Lets an MCP broker confirm this plugin is present and learn its MCP and
	 * OAuth endpoints in a single probe. Exposes only the same public endpoint
	 * URLs already published via the .well-known discovery documents.
	 *
	 * @return WP_REST_Response The health document.
	 */
	public static function health() {
		$metadata = Discovery::authorization_server_metadata();

		$nhi_count = NHI_Store::count_enabled();

		return new WP_REST_Response(
			array(
				'service'      => 'mosmcp',
				'version'      => MOSMCP_VERSION,
				'mcp_endpoint' => Utils::resource_url(),
				'has_nhi'      => $nhi_count > 0,
				'nhi_count'    => $nhi_count,
				'oauth'        => array(
					'issuer'                 => $metadata['issuer'],
					'authorization_endpoint' => $metadata['authorization_endpoint'],
					'token_endpoint'         => $metadata['token_endpoint'],
					'registration_endpoint'  => $metadata['registration_endpoint'],
				),
			),
			200
		);
	}

	/**
	 * Authenticates the request using the OAuth bearer token.
	 *
	 * On success the current user is set to the token's owner so that each
	 * ability's own permission check applies during execution.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return true|WP_Error True when authenticated, WP_Error (401) otherwise.
	 */
	public static function authenticate( WP_REST_Request $request ) {
		$token = Utils::extract_bearer_token( $request );
		if ( '' === $token ) {
			return self::unauthorized( 'missing_token' );
		}

		$row = Store::get_token( Tokens::hash( $token ) );

		if ( ! $row || 'access' !== $row['type'] ) {
			return self::unauthorized( 'invalid_token', array( 'token_prefix' => substr( $token, 0, 8 ) ) );
		}

		if ( (int) $row['expires'] < time() ) {
			Store::delete_token( $row['token_hash'] );
			return self::unauthorized( 'expired_token', array( 'client_id' => $row['client_id'] ) );
		}

		// Audience binding (RFC 8707): the token must have been issued for this server.
		// Compared scheme-insensitively so an http/https drift behind a TLS-terminating
		// proxy (and tokens minted before an https fix) don't 401 a same-host request.
		$strip_scheme = static function ( $u ) {
			return preg_replace( '#^https?://#i', '', untrailingslashit( (string) $u ) );
		};
		if ( $strip_scheme( $row['resource'] ) !== $strip_scheme( Utils::resource_url() ) ) {
			return self::unauthorized(
				'audience_mismatch',
				array(
					'client_id'         => $row['client_id'],
					'token_resource'    => $row['resource'],
					'expected_resource' => Utils::resource_url(),
				)
			);
		}

		// The OAuth connection is user-scoped: the token's client must still
		// exist for the binding to be valid, but ability governance no longer
		// lives on the client — it is the union of every enabled NHI.
		$client = Store::get_client( $row['client_id'] );
		if ( ! $client ) {
			return self::unauthorized( 'unknown_client', array( 'client_id' => $row['client_id'] ) );
		}

		// Establish the token's user first so we can read its role(s), then apply the
		// role-scoped NHI allow-list for this request. null means unrestricted (a
		// migrated pre-RBAC "no restriction" NHI); an empty array means no abilities
		// are exposed to this user's role(s).
		wp_set_current_user( (int) $row['user_id'] );

		$roles   = array();
		$current = wp_get_current_user();
		if ( $current && isset( $current->roles ) && is_array( $current->roles ) ) {
			$roles = $current->roles;
		}

		MCP_Server::set_allowed_abilities( NHI_Store::resolve_for_roles( $roles ) );

		// Capture per-request context for the audit logger.
		$ip = '';
		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$raw_ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
			$ip     = filter_var( $raw_ip, FILTER_VALIDATE_IP ) ? $raw_ip : '';
		}

		// Resolve the primary NHI for this user's roles so denied/unknown-tool events
		// can still be attributed to an agent even when the tool isn't in any grant.
		$primary_nhi = NHI_Store::primary_for_roles( $roles );

		MCP_Server::set_request_context(
			array(
				'user_id'     => (int) $row['user_id'],
				'client_id'   => (string) $row['client_id'],
				'client_name' => isset( $client['client_name'] ) ? (string) $client['client_name'] : '',
				'ip'          => $ip,
				'nhi_id'      => $primary_nhi ? (int) $primary_nhi['id'] : null,
				'nhi_uuid'    => $primary_nhi ? (string) $primary_nhi['uuid'] : '',
				'nhi_name'    => $primary_nhi ? (string) $primary_nhi['name'] : '',
			)
		);

		Debug_Logger::debug(
			Debug_Logger::CHANNEL_MCP,
			sprintf( 'Authenticated MCP request from client "%s".', isset( $client['client_name'] ) ? (string) $client['client_name'] : (string) $row['client_id'] ),
			array(
				'client_id'   => $row['client_id'],
				'client_name' => isset( $client['client_name'] ) ? (string) $client['client_name'] : '',
				'user_id'     => (int) $row['user_id'],
				'nhi_name'    => $primary_nhi ? (string) $primary_nhi['name'] : '',
				'ip'          => $ip,
			)
		);

		return true;
	}

	/**
	 * Processes the JSON-RPC request body.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response The JSON-RPC response.
	 */
	public static function handle( WP_REST_Request $request ) {
		$decoded = json_decode( $request->get_body(), true );

		if ( null === $decoded && JSON_ERROR_NONE !== json_last_error() ) {
			return new WP_REST_Response(
				array(
					'jsonrpc' => '2.0',
					'id'      => null,
					'error'   => array(
						'code'    => -32700,
						'message' => __( 'Parse error.', 'miniorange-secure-mcp-server' ),
					),
				),
				400
			);
		}

		// JSON-RPC batching was removed in MCP 2025-06-18; reject arrays of messages.
		if ( is_array( $decoded ) && isset( $decoded[0] ) ) {
			return new WP_REST_Response(
				array(
					'jsonrpc' => '2.0',
					'id'      => null,
					'error'   => array(
						'code'    => -32600,
						'message' => __( 'Batch requests are not supported.', 'miniorange-secure-mcp-server' ),
					),
				),
				400
			);
		}

		$response = MCP_Server::dispatch( $decoded );

		if ( null === $response ) {
			// Notification: acknowledge with no content.
			return new WP_REST_Response( null, 202 );
		}

		return new WP_REST_Response( $response, 200 );
	}

	/**
	 * Adds the WWW-Authenticate challenge header to unauthorized responses for
	 * the MCP route, pointing clients at the protected-resource metadata, and
	 * reshapes the body into a JSON-RPC error envelope.
	 *
	 * `authenticate()` returns a plain `WP_Error`, which the REST server auto-serializes
	 * into its generic `{code, message, data}` shape before this filter runs — not the
	 * `{jsonrpc, error}` shape every other `/mcp` response uses. Rewriting it here (rather
	 * than in `authenticate()`) is what lets the permission_callback stay a plain
	 * `true|WP_Error`, which is all `register_rest_route()` supports.
	 *
	 * Also powers the connectivity-diagnostic header-echo check: when the request carries
	 * the diagnostic marker header, an additional response header reports whether an
	 * Authorization header was actually seen — gated on the marker so it is never ambient
	 * for real client traffic. This lets an external prober tell "the request was rejected
	 * because the token is invalid" apart from "the Authorization header never arrived at
	 * all" (e.g. stripped by Apache/CGI) using the real `/mcp` route, not a side-channel one.
	 *
	 * @param WP_REST_Response $result  The response.
	 * @param WP_REST_Server   $server  The REST server instance.
	 * @param WP_REST_Request  $request The request.
	 * @return WP_REST_Response The (possibly modified) response.
	 */
	public static function add_challenge_header( $result, $server, $request ) {
		if ( '/' . MOSMCP_REST_NAMESPACE . '/mcp' !== $request->get_route() ) {
			return $result;
		}

		if ( $result instanceof WP_REST_Response && 401 === $result->get_status() ) {
			$result->header( 'WWW-Authenticate', Discovery::unauthorized_header( 'invalid_token' ) );

			$original = $result->get_data();
			$message  = ( is_array( $original ) && isset( $original['message'] ) )
				? $original['message']
				: __( 'A valid OAuth bearer token is required.', 'miniorange-secure-mcp-server' );

			$result->set_data(
				array(
					'jsonrpc' => '2.0',
					'id'      => null,
					'error'   => array(
						'code'    => -32001,
						'message' => $message,
					),
				)
			);

			if ( '1' === $request->get_header( 'x-mosmcp-diagnostic-probe' ) ) {
				$seen = '' !== Utils::extract_bearer_token( $request );
				$result->header( 'X-MOSMCP-Auth-Header-Seen', $seen ? '1' : '0' );
			}
		}

		return $result;
	}

	/**
	 * Extracts the bearer token from the request, working around servers that
	 * strip the Authorization header from the CGI environment.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return string The token, or an empty string when absent.
	 */
	private static function extract_bearer_token( WP_REST_Request $request ) {
		$header = (string) $request->get_header( 'authorization' );

		if ( '' === $header && ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			$header = sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) );
		}

		if ( '' === $header && ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			$header = sanitize_text_field( wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) );
		}

		if ( '' === $header && function_exists( 'getallheaders' ) ) {
			foreach ( (array) getallheaders() as $key => $value ) {
				if ( 'authorization' === strtolower( (string) $key ) ) {
					$header = sanitize_text_field( $value );
					break;
				}
			}
		}

		// Some Apache/FastCGI setups surface the header only via apache_request_headers().
		if ( '' === $header && function_exists( 'apache_request_headers' ) ) {
			foreach ( (array) apache_request_headers() as $key => $value ) {
				if ( 'authorization' === strtolower( (string) $key ) ) {
					$header = sanitize_text_field( $value );
					break;
				}
			}
		}

		if ( preg_match( '/^Bearer\s+(.+)$/i', trim( $header ), $matches ) ) {
			return trim( $matches[1] );
		}

		return '';
	}

	/**
	 * Builds the standard 401 error used by the MCP endpoint, logging the
	 * specific reason for the rejection to the debug log (never exposed in the
	 * HTTP response, which always carries the same generic message).
	 *
	 * @param string               $reason  Short machine-readable rejection reason.
	 * @param array<string, mixed> $context Additional detail for the debug log.
	 * @return WP_Error The unauthorized error.
	 */
	private static function unauthorized( $reason = 'unknown', array $context = array() ) {
		$ip = '';
		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$raw_ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
			$ip     = filter_var( $raw_ip, FILTER_VALIDATE_IP ) ? $raw_ip : '';
		}

		Debug_Logger::warning(
			Debug_Logger::CHANNEL_MCP,
			sprintf( 'Rejected MCP request: %s.', $reason ),
			array_merge(
				array(
					'reason' => $reason,
					'ip'     => $ip,
				),
				$context
			)
		);

		return new WP_Error(
			'mosmcp_unauthorized',
			__( 'A valid OAuth bearer token is required.', 'miniorange-secure-mcp-server' ),
			array( 'status' => 401 )
		);
	}
}
