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
use MoSMCP\Common\Services\Diagnostics\Test_Token;
use MoSMCP\Common\Services\Discovery\Discovery;
use MoSMCP\Common\Services\Logging\Debug_Logger;
use MoSMCP\Common\Services\MCP\MCP_Server;
use MoSMCP\Common\Services\OAuth\Tokens;
use MoSMCP\Common\Services\Rest\Rest_Bridge;
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

		$response = new WP_REST_Response(
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

		// This document reports live NHI state that flips the moment an admin enables an
		// agent. It MUST NOT be cached: page/edge caches (LiteSpeed on Hostinger, Cloudflare,
		// etc.) otherwise serve a stale `nhi_count:0` to the gateway, which then blocks
		// connections with "No NHI configured" even after an agent is enabled. Mirrors the
		// no-store the OAuth endpoints already send.
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
		$response->header( 'Pragma', 'no-cache' );

		return $response;
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
		self::log_request_entry( $request );

		$token = Utils::extract_bearer_token( $request );
		if ( '' === $token ) {
			return self::unauthorized( 'missing_token' );
		}

		$row = Store::get_token( Tokens::hash( $token ) );

		if ( ! $row || Tokens::TYPE_ACCESS !== $row['type'] ) {
			// Named to avoid Debug_Logger's redaction (keyed on substrings like "token"),
			// which would otherwise mask this deliberately-short, non-sensitive fragment.
			return self::unauthorized( 'invalid_token', array( 'bearer_prefix' => substr( $token, 0, 8 ) ) );
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
			// `issued_for`, not `token_resource`: Debug_Logger redacts any context key
			// containing "token", which would mask the one value this rejection exists
			// to report. The same trap `bearer_prefix` above was named to dodge.
			return self::unauthorized(
				'audience_mismatch',
				array(
					'client_id'         => $row['client_id'],
					'issued_for'        => $row['resource'],
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

		// A connection-test token may only read; handle() enforces that.
		MCP_Server::set_diagnostic( Test_Token::SCOPE === $row['scope'] );

		$roles   = array();
		$current = wp_get_current_user();
		if ( $current && isset( $current->roles ) && is_array( $current->roles ) ) {
			$roles = $current->roles;
		}

		$allowed = NHI_Store::resolve_for_roles( $roles );
		MCP_Server::set_allowed_abilities( $allowed );

		// REST routes are granted separately from abilities and deny by default: no grants (or a
		// failed lookup) is an empty list that reaches nothing; null is the '*' wildcard, unrestricted.
		Rest_Bridge::set_allowed_routes( NHI_Store::resolve_routes_for_roles( $roles ) );

		// Capture per-request context for the audit logger.
		$ip = self::client_ip();

		// Resolve the primary NHI for this user's roles so denied/unknown-tool events
		// can still be attributed to an agent even when the tool isn't in any grant.
		$primary_nhi = NHI_Store::primary_for_roles( $roles );
		$source      = Store::client_connection_source( $client );

		MCP_Server::set_request_context(
			array(
				'user_id'     => (int) $row['user_id'],
				'client_id'   => (string) $row['client_id'],
				'client_name' => isset( $client['client_name'] ) ? (string) $client['client_name'] : '',
				'source'      => $source,
				'roles'       => $roles,
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
				'client_id'     => $row['client_id'],
				'client_name'   => isset( $client['client_name'] ) ? (string) $client['client_name'] : '',
				'user_id'       => (int) $row['user_id'],
				'user_login'    => $current ? (string) $current->user_login : '',
				// The two fields that decide whether this connection can see anything at
				// all. An authenticated request whose role resolves to zero abilities is
				// indistinguishable, from the client's side, from a broken server.
				'roles'         => ! empty( $roles ) ? implode( ',', $roles ) : '(none)',
				'allowed_count' => null === $allowed ? 'unrestricted' : count( (array) $allowed ),
				'source'        => $source,
				'nhi_name'      => $primary_nhi ? (string) $primary_nhi['name'] : '',
				'ip'            => $ip,
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
		$body    = $request->get_body();
		$decoded = json_decode( $body, true );

		if ( null === $decoded && JSON_ERROR_NONE !== json_last_error() ) {
			if ( Debug_Logger::is_capturing() ) {
				Debug_Logger::warning(
					Debug_Logger::CHANNEL_MCP,
					'Rejected MCP request: the body is not valid JSON.',
					array(
						'json_error' => json_last_error_msg(),
						'body'       => Debug_Logger::payload( $body ),
					)
				);
			}

			return self::finish(
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
			if ( Debug_Logger::is_capturing() ) {
				Debug_Logger::warning(
					Debug_Logger::CHANNEL_MCP,
					'Rejected MCP request: JSON-RPC batching is not supported.',
					array(
						'messages' => count( $decoded ),
						'body'     => Debug_Logger::payload( $body ),
					)
				);
			}

			return self::finish(
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

		if ( MCP_Server::is_diagnostic() && ! in_array( is_array( $decoded ) && isset( $decoded['method'] ) ? $decoded['method'] : '', Test_Token::ALLOWED_METHODS, true ) ) {
			return new WP_REST_Response(
				array(
					'jsonrpc' => '2.0',
					'id'      => is_array( $decoded ) && isset( $decoded['id'] ) ? $decoded['id'] : null,
					'error'   => array(
						'code'    => -32001,
						'message' => __( 'This token is only valid for the connection test.', 'miniorange-secure-mcp-server' ),
					),
				),
				403
			);
		}

		$response = MCP_Server::dispatch( $decoded );

		if ( null === $response ) {
			// Notification: acknowledge with no content.
			return self::finish( null, 202 );
		}

		return self::finish( $response, 200 );
	}

	/**
	 * Logs the outcome of an MCP request and returns the response.
	 *
	 * Every exit from {@see handle()} runs through here, so a request that got
	 * past authentication always has a closing row. A correlation id that opens
	 * with an entry row and never reaches one of these died inside an ability or
	 * in PHP itself, not in transport — which is the distinction between "the
	 * MCP layer is fine, look downstream" and "the MCP layer rejected you".
	 * Rejected requests never get here: `authenticate()` fails first, and its own
	 * warning row is the closing row for those.
	 *
	 * The envelope is held to the standard payload budget rather than logged
	 * whole. A tools/list response on a large install runs to six figures and
	 * would evict every other field in this row, and the input schemas that make
	 * up its bulk are already visible in the abilities admin screen. The byte
	 * count survives truncation and is the part worth having.
	 *
	 * @param mixed $data   The response body.
	 * @param int   $status The HTTP status.
	 * @return WP_REST_Response The response.
	 */
	private static function finish( $data, $status ) {
		if ( Debug_Logger::is_capturing() ) {
			Debug_Logger::debug(
				Debug_Logger::CHANNEL_MCP,
				sprintf( 'MCP request completed with HTTP %d.', $status ),
				array(
					'http_status' => $status,
					'response'    => Debug_Logger::payload( (string) wp_json_encode( $data ) ),
				)
			);
		}

		return new WP_REST_Response( $data, $status );
	}

	/**
	 * Records the arrival of an MCP request, before authentication runs.
	 *
	 * This is the row whose *absence* carries the information: if a customer
	 * reports a failing client and no entry row exists for that window, the
	 * request never reached PHP — a WAF, CDN or redirect ate it, and nothing in
	 * this plugin can account for it. `has_auth_header` splits the next case out,
	 * since a proxy that forwards the request but strips the Authorization header
	 * looks, from the client's side, exactly like a rejected token.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return void
	 */
	private static function log_request_entry( WP_REST_Request $request ) {
		if ( ! Debug_Logger::is_capturing() ) {
			return;
		}

		Debug_Logger::debug(
			Debug_Logger::CHANNEL_MCP,
			'MCP request received.',
			array(
				'route'             => $request->get_route(),
				'http_method'       => $request->get_method(),
				// Upper-case "NO" on purpose: it makes the pathological case greppable
				// without also matching the healthy one.
				'has_auth_header'   => '' !== Utils::authorization_header( $request ) ? 'yes' : 'NO',
				'content_type'      => (string) $request->get_header( 'content-type' ),
				'content_length'    => (string) $request->get_header( 'content-length' ),
				'user_agent'        => (string) $request->get_header( 'user-agent' ),
				'ip'                => self::client_ip(),
				'expected_resource' => Utils::resource_url(),
				'body'              => Debug_Logger::payload( $request->get_body() ),
			)
		);
	}

	/**
	 * The validated client IP for this request, or an empty string.
	 *
	 * @return string
	 */
	private static function client_ip() {
		if ( empty( $_SERVER['REMOTE_ADDR'] ) ) {
			return '';
		}

		$raw = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );

		return filter_var( $raw, FILTER_VALIDATE_IP ) ? $raw : '';
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
	 * Builds the standard 401 error used by the MCP endpoint, logging the
	 * specific reason for the rejection to the debug log (never exposed in the
	 * HTTP response, which always carries the same generic message).
	 *
	 * @param string               $reason  Short machine-readable rejection reason.
	 * @param array<string, mixed> $context Additional detail for the debug log.
	 * @return WP_Error The unauthorized error.
	 */
	private static function unauthorized( $reason = 'unknown', array $context = array() ) {
		Debug_Logger::warning(
			Debug_Logger::CHANNEL_MCP,
			sprintf( 'Rejected MCP request: %s.', $reason ),
			array_merge(
				array(
					'reason' => $reason,
					'ip'     => self::client_ip(),
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
