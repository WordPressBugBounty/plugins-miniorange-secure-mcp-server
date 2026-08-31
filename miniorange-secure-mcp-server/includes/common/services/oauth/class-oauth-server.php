<?php
/**
 * The self-hosted OAuth 2.1 Authorization Server.
 *
 * Implements Dynamic Client Registration (RFC 7591), the authorization
 * endpoint (login + consent, served through admin-post.php so that the normal
 * cookie session applies), and the token endpoint (authorization-code with
 * mandatory PKCE, plus refresh-token rotation).
 *
 * @package Miniorange_Secure_MCP_Server
 */

namespace MoSMCP\Common\Services\OAuth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MoSMCP\Common\Repositories\NHI_Store;
use MoSMCP\Common\Repositories\Store;
use MoSMCP\Common\Utils\Utils;
use MoSMCP\Common\Views\OAuth\Consent_View;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Class OAuth_Server
 */
class OAuth_Server {

	/**
	 * The single OAuth scope this server issues.
	 */
	const SCOPE = 'mcp';

	/*
	 * Dynamic Client Registration (RFC 7591)
	 *
	 * The /register and /token routes are registered in {@see Rest_Routes};
	 * the authorization endpoint runs on admin-post.php (see the bootstrap hooks).
	 */

	/**
	 * Handles POST /register.
	 *
	 * @param WP_REST_Request $request The registration request.
	 * @return WP_REST_Response The registration response or an error document.
	 */
	public static function register_client( WP_REST_Request $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = array();
		}

		$redirect_uris = isset( $body['redirect_uris'] ) ? (array) $body['redirect_uris'] : array();
		$redirect_uris = self::sanitize_redirect_uris( $redirect_uris );

		if ( empty( $redirect_uris ) ) {
			return self::registration_error( 'invalid_redirect_uri', __( 'At least one valid HTTPS or localhost redirect URI is required.', 'miniorange-secure-mcp-server' ) );
		}

		$auth_method = isset( $body['token_endpoint_auth_method'] ) ? sanitize_text_field( $body['token_endpoint_auth_method'] ) : 'none';
		if ( ! in_array( $auth_method, array( 'none', 'client_secret_post', 'client_secret_basic' ), true ) ) {
			$auth_method = 'none';
		}

		$grant_types = isset( $body['grant_types'] ) ? array_map( 'sanitize_text_field', (array) $body['grant_types'] ) : array( 'authorization_code', 'refresh_token' );
		$grant_types = array_values( array_intersect( $grant_types, array( 'authorization_code', 'refresh_token' ) ) );
		if ( empty( $grant_types ) ) {
			$grant_types = array( 'authorization_code' );
		}

		$client_name = isset( $body['client_name'] ) ? sanitize_text_field( $body['client_name'] ) : '';

		$client_id     = 'mcp_' . Tokens::base64url_encode( random_bytes( 16 ) );
		$client_secret = '';
		$secret_hash   = null;

		if ( 'none' !== $auth_method ) {
			$client_secret = Tokens::generate();
			$secret_hash   = Tokens::hash( $client_secret );
		}

		Store::purge_expired();

		// Remove orphaned registrations with the same client name that have no
		// active or refreshable tokens. This prevents the clients table (and the
		// NHI migration) from accumulating stale rows each time a broker re-registers.
		//
		// Age guard: only prune clients older than the OAuth flow window. A client is
		// token-less between registration and the token exchange; without this guard a
		// second (or concurrent) connect would delete the first connect's in-flight
		// client, and its later authorize step would fail with "Unknown OAuth client".
		if ( '' !== $client_name ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				$wpdb->prepare(
					'DELETE c FROM %i c
					 LEFT JOIN %i t ON t.client_id = c.client_id AND t.expires >= %d
					 WHERE c.client_name = %s AND c.created < %d AND t.token_hash IS NULL',
					Store::table( 'clients' ),
					Store::table( 'tokens' ),
					time(),
					$client_name,
					time() - 600
				)
			);
		}

		$inserted = Store::insert_client(
			array(
				'client_id'                  => $client_id,
				'client_secret_hash'         => $secret_hash,
				'client_name'                => $client_name,
				'redirect_uris'              => wp_json_encode( $redirect_uris ),
				'grant_types'                => implode( ' ', $grant_types ),
				'token_endpoint_auth_method' => $auth_method,
				'created'                    => time(),
			)
		);

		if ( ! $inserted ) {
			return self::registration_error( 'invalid_client_metadata', __( 'The client could not be registered.', 'miniorange-secure-mcp-server' ) );
		}

		$response = array(
			'client_id'                  => $client_id,
			'client_id_issued_at'        => time(),
			'redirect_uris'              => $redirect_uris,
			'grant_types'                => $grant_types,
			'response_types'             => array( 'code' ),
			'token_endpoint_auth_method' => $auth_method,
			'client_name'                => $client_name,
		);

		if ( '' !== $client_secret ) {
			$response['client_secret']            = $client_secret;
			$response['client_secret_expires_at'] = 0;
		}

		return new WP_REST_Response( $response, 201 );
	}

	/*
	 * Authorization endpoint (admin-post.php?action=mosmcp_authorize)
	 */

	/**
	 * Handles the authorization request: validates, logs the user in, shows a
	 * consent screen, and issues an authorization code on approval.
	 *
	 * @return void
	 */
	public static function authorize() {
		$params = self::collect_authorize_params();

		$client = Store::get_client( $params['client_id'] );
		if ( ! $client ) {
			self::fatal( __( 'Unknown OAuth client.', 'miniorange-secure-mcp-server' ) );
		}

		$registered = (array) json_decode( $client['redirect_uris'], true );
		if ( '' === $params['redirect_uri'] || ! in_array( $params['redirect_uri'], $registered, true ) ) {
			self::fatal( __( 'The supplied redirect URI is not registered for this client.', 'miniorange-secure-mcp-server' ) );
		}

		// Beyond this point the redirect URI is trusted, so errors are reported
		// back to the client via redirect per OAuth 2.1.
		if ( 'code' !== $params['response_type'] ) {
			self::redirect_error( $params['redirect_uri'], 'unsupported_response_type', $params['state'] );
		}

		if ( '' === $params['code_challenge'] || 'S256' !== $params['code_challenge_method'] ) {
			self::redirect_error( $params['redirect_uri'], 'invalid_request', $params['state'] );
		}

		if ( '' !== $params['resource'] && untrailingslashit( $params['resource'] ) !== Utils::resource_url() ) {
			self::redirect_error( $params['redirect_uri'], 'invalid_target', $params['state'] );
		}

		if ( ! is_user_logged_in() ) {
			$login_url = wp_login_url( self::current_authorize_url( $params ) );
			// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Internal login URL.
			wp_safe_redirect( $login_url );
			exit;
		}

		if ( 'POST' === strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET' ) ) {
			check_admin_referer( 'mosmcp_authorize' );

			$decision = isset( $_POST['mosmcp_decision'] ) ? sanitize_text_field( wp_unslash( $_POST['mosmcp_decision'] ) ) : '';

			if ( 'approve' !== $decision ) {
				self::redirect_error( $params['redirect_uri'], 'access_denied', $params['state'] );
			}

			// Refuse the connection if the user's role(s) resolve to no abilities —
			// mirrors the consent screen, which offers only Cancel in that case.
			$user    = wp_get_current_user();
			$roles   = ( isset( $user->roles ) && is_array( $user->roles ) ) ? array_values( $user->roles ) : array();
			$allowed = NHI_Store::resolve_for_roles( $roles );
			if ( null !== $allowed && empty( (array) $allowed ) ) {
				self::redirect_error( $params['redirect_uri'], 'access_denied', $params['state'] );
			}

			$code = Tokens::generate();
			Store::insert_code(
				array(
					'code_hash'      => Tokens::hash( $code ),
					'client_id'      => $params['client_id'],
					'user_id'        => get_current_user_id(),
					'redirect_uri'   => $params['redirect_uri'],
					'code_challenge' => $params['code_challenge'],
					'scope'          => self::SCOPE,
					'resource'       => Utils::resource_url(),
					'expires'        => time() + Tokens::CODE_TTL,
					'used'           => 0,
				)
			);

			$redirect = add_query_arg(
				array(
					'code'  => rawurlencode( $code ),
					'state' => rawurlencode( $params['state'] ),
				),
				$params['redirect_uri']
			);

			// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Redirect URI validated against the client's registered set.
			wp_redirect( $redirect );
			exit;
		}

		Consent_View::render( $params, $client );
	}

	/**
	 * Collects and sanitizes the OAuth parameters from the current request.
	 *
	 * Parameters arrive as query args on the initial GET and as hidden form
	 * fields on the consent POST; both sources are read here. The state-changing
	 * branch is protected by {@see check_admin_referer()} in {@see authorize()}.
	 *
	 * @return array<string, string> The collected parameters.
	 */
	private static function collect_authorize_params() {
		// phpcs:ignore WordPress.Security.NonceVerification -- Inbound OAuth authorization request; the approval action is nonce-protected via check_admin_referer() in authorize().
		$src = array_merge( wp_unslash( $_GET ), wp_unslash( $_POST ) );

		$get = static function ( $key ) use ( $src ) {
			return isset( $src[ $key ] ) ? (string) $src[ $key ] : '';
		};

		return array(
			'response_type'         => sanitize_text_field( $get( 'response_type' ) ),
			'client_id'             => sanitize_text_field( $get( 'client_id' ) ),
			'redirect_uri'          => esc_url_raw( $get( 'redirect_uri' ) ),
			'scope'                 => sanitize_text_field( $get( 'scope' ) ),
			'state'                 => sanitize_text_field( $get( 'state' ) ),
			'code_challenge'        => sanitize_text_field( $get( 'code_challenge' ) ),
			'code_challenge_method' => sanitize_text_field( $get( 'code_challenge_method' ) ),
			'resource'              => esc_url_raw( $get( 'resource' ) ),
		);
	}

	/**
	 * Rebuilds the authorization URL for the current request (used as the
	 * post-login return destination).
	 *
	 * @param array<string, string> $params The collected OAuth parameters.
	 * @return string The absolute authorization URL.
	 */
	private static function current_authorize_url( array $params ) {
		$args = array( 'action' => 'mosmcp_authorize' );
		foreach ( $params as $key => $value ) {
			if ( '' !== $value ) {
				$args[ $key ] = rawurlencode( $value );
			}
		}

		return add_query_arg( $args, admin_url( 'admin-post.php' ) );
	}

	/*
	 * Token endpoint
	 */

	/**
	 * Handles POST /token for the authorization_code and refresh_token grants.
	 *
	 * @param WP_REST_Request $request The token request.
	 * @return WP_REST_Response The token response or an error document.
	 */
	public static function token( WP_REST_Request $request ) {
		$grant_type = (string) $request->get_param( 'grant_type' );

		if ( 'authorization_code' === $grant_type ) {
			return self::grant_authorization_code( $request );
		}

		if ( 'refresh_token' === $grant_type ) {
			return self::grant_refresh_token( $request );
		}

		return self::token_error( 'unsupported_grant_type', __( 'Unsupported grant type.', 'miniorange-secure-mcp-server' ) );
	}

	/**
	 * Exchanges an authorization code for tokens.
	 *
	 * @param WP_REST_Request $request The token request.
	 * @return WP_REST_Response The token response or an error document.
	 */
	private static function grant_authorization_code( WP_REST_Request $request ) {
		$code          = (string) $request->get_param( 'code' );
		$redirect_uri  = (string) $request->get_param( 'redirect_uri' );
		$client_id     = (string) $request->get_param( 'client_id' );
		$code_verifier = (string) $request->get_param( 'code_verifier' );

		if ( '' === $code || '' === $code_verifier ) {
			return self::token_error( 'invalid_request', __( 'Missing code or code_verifier.', 'miniorange-secure-mcp-server' ) );
		}

		$row = Store::get_code( Tokens::hash( $code ) );
		if ( ! $row ) {
			return self::token_error( 'invalid_grant', __( 'Invalid authorization code.', 'miniorange-secure-mcp-server' ) );
		}

		// Codes are single-use regardless of outcome.
		Store::delete_code( $row['code_hash'] );

		if ( (int) $row['expires'] < time() ) {
			return self::token_error( 'invalid_grant', __( 'The authorization code has expired.', 'miniorange-secure-mcp-server' ) );
		}

		if ( $row['client_id'] !== $client_id ) {
			return self::token_error( 'invalid_grant', __( 'Client mismatch.', 'miniorange-secure-mcp-server' ) );
		}

		if ( $row['redirect_uri'] !== $redirect_uri ) {
			return self::token_error( 'invalid_grant', __( 'Redirect URI mismatch.', 'miniorange-secure-mcp-server' ) );
		}

		if ( ! Tokens::verify_pkce( $code_verifier, $row['code_challenge'] ) ) {
			return self::token_error( 'invalid_grant', __( 'PKCE verification failed.', 'miniorange-secure-mcp-server' ) );
		}

		$client_check = self::authenticate_client( $request, $client_id );
		if ( is_wp_error( $client_check ) ) {
			return self::token_error( 'invalid_client', $client_check->get_error_message(), 401 );
		}

		return self::issue_token_response( $client_id, (int) $row['user_id'], $row['scope'], $row['resource'] );
	}

	/**
	 * Issues a new token pair from a valid refresh token, rotating the old one.
	 *
	 * @param WP_REST_Request $request The token request.
	 * @return WP_REST_Response The token response or an error document.
	 */
	private static function grant_refresh_token( WP_REST_Request $request ) {
		$refresh_token = (string) $request->get_param( 'refresh_token' );
		$client_id     = (string) $request->get_param( 'client_id' );

		if ( '' === $refresh_token ) {
			return self::token_error( 'invalid_request', __( 'Missing refresh_token.', 'miniorange-secure-mcp-server' ) );
		}

		$hash = Tokens::hash( $refresh_token );
		$row  = Store::get_token( $hash );

		if ( ! $row || 'refresh' !== $row['type'] ) {
			return self::token_error( 'invalid_grant', __( 'Invalid refresh token.', 'miniorange-secure-mcp-server' ) );
		}

		if ( (int) $row['expires'] < time() ) {
			Store::delete_token( $hash );
			return self::token_error( 'invalid_grant', __( 'The refresh token has expired.', 'miniorange-secure-mcp-server' ) );
		}

		if ( $row['client_id'] !== $client_id ) {
			return self::token_error( 'invalid_grant', __( 'Client mismatch.', 'miniorange-secure-mcp-server' ) );
		}

		$client_check = self::authenticate_client( $request, $client_id );
		if ( is_wp_error( $client_check ) ) {
			return self::token_error( 'invalid_client', $client_check->get_error_message(), 401 );
		}

		// Rotate: revoke the presented refresh token and any access tokens minted from it.
		Store::delete_tokens_by_parent( $hash );
		Store::delete_token( $hash );

		return self::issue_token_response( $client_id, (int) $row['user_id'], $row['scope'], $row['resource'] );
	}

	/**
	 * Mints an access/refresh token pair and builds the token response.
	 *
	 * @param string $client_id The client identifier.
	 * @param int    $user_id   The WordPress user the tokens act on behalf of.
	 * @param string $scope     The granted scope.
	 * @param string $audience  The bound resource (audience).
	 * @return WP_REST_Response The token response.
	 */
	private static function issue_token_response( $client_id, $user_id, $scope, $audience ) {
		$refresh      = Tokens::generate();
		$refresh_hash = Tokens::hash( $refresh );

		Store::insert_token(
			array(
				'token_hash'  => $refresh_hash,
				'type'        => 'refresh',
				'client_id'   => $client_id,
				'user_id'     => $user_id,
				'scope'       => $scope,
				'resource'    => $audience,
				'expires'     => time() + Tokens::REFRESH_TTL,
				'parent_hash' => null,
			)
		);

		$access = Tokens::generate();

		Store::insert_token(
			array(
				'token_hash'  => Tokens::hash( $access ),
				'type'        => 'access',
				'client_id'   => $client_id,
				'user_id'     => $user_id,
				'scope'       => $scope,
				'resource'    => $audience,
				'expires'     => time() + Tokens::ACCESS_TTL,
				'parent_hash' => $refresh_hash,
			)
		);

		$response = new WP_REST_Response(
			array(
				'access_token'  => $access,
				'token_type'    => 'Bearer',
				'expires_in'    => Tokens::ACCESS_TTL,
				'refresh_token' => $refresh,
				'scope'         => $scope,
			),
			200
		);
		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'Pragma', 'no-cache' );

		return $response;
	}

	/**
	 * Authenticates the token-endpoint client according to its registered
	 * authentication method.
	 *
	 * @param WP_REST_Request $request   The token request.
	 * @param string          $client_id The client identifier from the request body.
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	private static function authenticate_client( WP_REST_Request $request, $client_id ) {
		if ( '' === $client_id ) {
			return new WP_Error( 'invalid_client', __( 'Missing client_id.', 'miniorange-secure-mcp-server' ) );
		}

		$client = Store::get_client( $client_id );
		if ( ! $client ) {
			return new WP_Error( 'invalid_client', __( 'Unknown client.', 'miniorange-secure-mcp-server' ) );
		}

		if ( 'none' === $client['token_endpoint_auth_method'] ) {
			return true;
		}

		$secret = (string) $request->get_param( 'client_secret' );

		if ( '' === $secret ) {
			$header = (string) $request->get_header( 'authorization' );
			if ( 0 === stripos( $header, 'Basic ' ) ) {
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding an HTTP Basic credential, not obfuscation.
				$decoded = base64_decode( substr( $header, 6 ), true );
				if ( false !== $decoded && false !== strpos( $decoded, ':' ) ) {
					list( , $secret ) = explode( ':', $decoded, 2 );
				}
			}
		}

		if ( '' === $secret || ! hash_equals( (string) $client['client_secret_hash'], Tokens::hash( $secret ) ) ) {
			return new WP_Error( 'invalid_client', __( 'Client authentication failed.', 'miniorange-secure-mcp-server' ) );
		}

		return true;
	}

	/*
	 * Helpers
	 */

	/**
	 * Filters a list of redirect URIs down to the ones that are acceptable
	 * (HTTPS, or HTTP only for loopback hosts).
	 *
	 * @param array $uris Candidate redirect URIs.
	 * @return string[] The accepted URIs.
	 */
	private static function sanitize_redirect_uris( array $uris ) {
		$valid = array();

		foreach ( $uris as $uri ) {
			if ( ! is_string( $uri ) ) {
				continue;
			}

			$clean = esc_url_raw( $uri, array( 'https', 'http' ) );
			if ( '' === $clean ) {
				continue;
			}

			$scheme = wp_parse_url( $clean, PHP_URL_SCHEME );
			$host   = wp_parse_url( $clean, PHP_URL_HOST );

			if ( 'https' === $scheme || in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
				$valid[] = $clean;
			}
		}

		return array_values( array_unique( $valid ) );
	}

	/**
	 * Builds a registration (RFC 7591) error response.
	 *
	 * @param string $error       The error code.
	 * @param string $description Human-readable description.
	 * @return WP_REST_Response The error response.
	 */
	private static function registration_error( $error, $description ) {
		return new WP_REST_Response(
			array(
				'error'             => $error,
				'error_description' => $description,
			),
			400
		);
	}

	/**
	 * Builds an OAuth token-endpoint error response.
	 *
	 * @param string $error       The error code.
	 * @param string $description Human-readable description.
	 * @param int    $status      HTTP status code.
	 * @return WP_REST_Response The error response.
	 */
	private static function token_error( $error, $description, $status = 400 ) {
		$response = new WP_REST_Response(
			array(
				'error'             => $error,
				'error_description' => $description,
			),
			$status
		);
		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'Pragma', 'no-cache' );

		return $response;
	}

	/**
	 * Redirects an authorization error back to the client.
	 *
	 * @param string $redirect_uri The validated client redirect URI.
	 * @param string $error        The OAuth error code.
	 * @param string $state        The client state value.
	 * @return void
	 */
	private static function redirect_error( $redirect_uri, $error, $state ) {
		$redirect = add_query_arg(
			array(
				'error' => rawurlencode( $error ),
				'state' => rawurlencode( $state ),
			),
			$redirect_uri
		);

		// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Redirect URI validated against the client's registered set.
		wp_redirect( $redirect );
		exit;
	}

	/**
	 * Renders a terminal error page for problems that must not be redirected
	 * (unknown client or unregistered redirect URI).
	 *
	 * @param string $message The message to display.
	 * @return void
	 */
	private static function fatal( $message ) {
		wp_die(
			esc_html( $message ),
			esc_html__( 'Authorization error', 'miniorange-secure-mcp-server' ),
			array( 'response' => 400 )
		);
	}
}
