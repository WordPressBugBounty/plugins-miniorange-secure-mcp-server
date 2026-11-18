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
use MoSMCP\Common\Services\Logging\Debug_Logger;
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

		// Remove orphaned registrations that have no active or refreshable tokens,
		// left behind each time the same broker/app re-registers. This prevents the
		// clients table (and the NHI migration) from accumulating stale rows.
		//
		// Matched on client_name AND the exact registered redirect_uris set, not name
		// alone: two unrelated apps sharing a generic/default client_name (a real
		// possibility — brokers often ship one) must not have each other's rows
		// deleted just because a new, unrelated registration happens to share that
		// name. The redirect_uris set is effectively unique per app/install, so
		// requiring both to match ties the deletion to "the same client
		// re-registering" rather than "any client with this name".
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
					 WHERE c.client_name = %s AND c.redirect_uris = %s AND c.created < %d AND t.token_hash IS NULL',
					Store::table( 'clients' ),
					Store::table( 'tokens' ),
					time(),
					$client_name,
					wp_json_encode( $redirect_uris ),
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

		Debug_Logger::info(
			Debug_Logger::CHANNEL_OAUTH,
			sprintf( 'Registered OAuth client "%s".', '' !== $client_name ? $client_name : $client_id ),
			array(
				'client_id'     => $client_id,
				'client_name'   => $client_name,
				'redirect_uris' => implode( ',', $redirect_uris ),
			)
		);

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

			Debug_Logger::info(
				Debug_Logger::CHANNEL_OAUTH,
				sprintf( 'Consent %s.', 'approve' === $decision ? 'approved' : 'denied' ),
				array(
					'decision'  => 'approve' === $decision ? 'approve' : 'deny',
					'client_id' => $params['client_id'],
				)
			);

			if ( 'approve' !== $decision ) {
				self::redirect_error( $params['redirect_uri'], 'access_denied', $params['state'] );
			}

			// Refuse the connection if the user's role(s) resolve to no abilities —
			// mirrors the consent screen, which offers only Cancel in that case.
			$user    = wp_get_current_user();
			$roles   = ( isset( $user->roles ) && is_array( $user->roles ) ) ? array_values( $user->roles ) : array();
			$allowed = NHI_Store::resolve_for_roles( $roles );
			if ( null !== $allowed && empty( (array) $allowed ) ) {
				// Reported to the client as a plain access_denied, which is
				// indistinguishable from the user pressing Deny. Without this row the
				// refusal leaves no trace at all, and the admin who has to fix the role
				// policy has nothing pointing them at it.
				//
				// Worded "Authorization refused:" rather than "Refused authorization:"
				// deliberately. Debug_Logger::redact_message() masks any `<pattern>: value`
				// fragment, and "authorization" is one of those patterns — the latter
				// phrasing stores as "Refused authorization=***redacted***".
				Debug_Logger::warning(
					Debug_Logger::CHANNEL_OAUTH,
					'Authorization refused: the user\'s role(s) resolve to no abilities.',
					array(
						'client_id'  => $params['client_id'],
						'user_login' => (string) $user->user_login,
						'roles'      => ! empty( $roles ) ? implode( ',', $roles ) : '(none)',
					)
				);

				self::redirect_error( $params['redirect_uri'], 'access_denied', $params['state'] );
			}

			$code        = Tokens::generate();
			$code_stored = Store::insert_code(
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

			if ( ! $code_stored ) {
				// Redirecting with a code that was never stored sends the user back to the AI
				// client with a code the token endpoint can only reject, with no trace of why.
				Debug_Logger::error(
					Debug_Logger::CHANNEL_OAUTH,
					'Could not store the authorization code. The database write failed.',
					array( 'client_id' => $params['client_id'] )
				);
				self::redirect_error( $params['redirect_uri'], 'server_error', $params['state'] );
			}

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

		// Codes are single-use regardless of outcome. Claiming is atomic, so if two exchanges
		// race, only the one that removed the row continues.
		if ( ! Store::claim_code( $row['code_hash'] ) ) {
			return self::token_error( 'invalid_grant', __( 'Invalid authorization code.', 'miniorange-secure-mcp-server' ) );
		}

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

		return self::issue_token_response( $client_id, (int) $row['user_id'], $row['scope'], $row['resource'], 'authorization_code' );
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

		// A spent token inside its grace window is a retry, not an unknown token.
		$is_retry = $row && Tokens::TYPE_ROTATED === $row['type'];

		if ( ! $row || ( Tokens::TYPE_REFRESH !== $row['type'] && ! $is_retry ) ) {
			return self::token_error( 'invalid_grant', __( 'Invalid refresh token.', 'miniorange-secure-mcp-server' ) );
		}

		if ( (int) $row['expires'] < time() ) {
			Store::delete_token( $hash );
			// An expired grace marker is just a spent token: say what an unknown one says.
			$message = $is_retry
				? __( 'Invalid refresh token.', 'miniorange-secure-mcp-server' )
				: __( 'The refresh token has expired.', 'miniorange-secure-mcp-server' );
			return self::token_error( 'invalid_grant', $message );
		}

		if ( $row['client_id'] !== $client_id ) {
			return self::token_error( 'invalid_grant', __( 'Client mismatch.', 'miniorange-secure-mcp-server' ) );
		}

		$client_check = self::authenticate_client( $request, $client_id );
		if ( is_wp_error( $client_check ) ) {
			return self::token_error( 'invalid_client', $client_check->get_error_message(), 401 );
		}

		if ( $is_retry ) {
			return self::reissue_after_rotation( $hash, $row );
		}

		// Rotate: spend the presented refresh token. Spending is atomic, so if two refreshes race,
		// only the one whose UPDATE changed the row rotates; the other is told the token is no longer
		// valid. The successor is chosen first and recorded in that same UPDATE, so a concurrent retry
		// that reads the marker always sees which pair it replaces.
		$successor = Tokens::generate();
		$spent     = self::spend_refresh_token( $hash, Tokens::hash( $successor ) );
		if ( false === $spent ) {
			// The database failed: not the caller's fault, and not a reason for it to drop the
			// connection. A 5xx tells the broker to try again later (invalid_grant would not).
			Debug_Logger::error(
				Debug_Logger::CHANNEL_OAUTH,
				// (No colon after "token": Debug_Logger::redact_message() would mask the rest.)
				'Could not rotate a refresh token. The database write failed.',
				array( 'client_id' => $client_id )
			);
			return self::token_error( 'server_error', __( 'The refresh could not be completed. Try again shortly.', 'miniorange-secure-mcp-server' ), 500 );
		}
		if ( 0 === $spent ) {
			return self::token_error( 'invalid_grant', __( 'Invalid refresh token.', 'miniorange-secure-mcp-server' ) );
		}
		Store::delete_tokens_by_parent( $hash );

		$response = self::issue_successor( $hash, $row, $successor, 'refresh_token' );
		if ( 500 === $response->get_status() ) {
			// Issuing failed after the token was spent. Put it back so the client's next attempt works,
			// rather than leaving it to lapse with the grace window and force a full reconnect.
			self::unspend_refresh_token( $hash, (int) $row['expires'] );
		}

		return $response;
	}

	/**
	 * Undoes {@see spend_refresh_token()} when no replacement could be issued.
	 *
	 * @param string $hash    The hashed refresh token.
	 * @param int    $expires Its original expiry.
	 * @return void
	 */
	private static function unspend_refresh_token( $hash, $expires ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET type = %s, expires = %d, parent_hash = NULL WHERE token_hash = %s AND type = %s',
				Store::table( 'tokens' ),
				Tokens::TYPE_REFRESH,
				$expires,
				$hash,
				Tokens::TYPE_ROTATED
			)
		);
	}

	/**
	 * Spends a refresh token by turning it into a short-lived "rotated" marker that names its successor.
	 *
	 * One UPDATE guarded by `type = refresh` is the whole claim: of any number of concurrent
	 * requests with the same token, exactly one changes the row. (The marker, instead of a delete,
	 * is what lets {@see reissue_after_rotation()} answer a retry.)
	 *
	 * @param string $hash           The hashed refresh token.
	 * @param string $successor_hash The hash of the refresh token that will replace it.
	 * @return int|false 1 when this call spent it, 0 when it was already spent, false on a database error.
	 */
	private static function spend_refresh_token( $hash, $successor_hash ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$changed = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET type = %s, expires = %d, parent_hash = %s WHERE token_hash = %s AND type = %s',
				Store::table( 'tokens' ),
				Tokens::TYPE_ROTATED,
				time() + Tokens::REFRESH_GRACE_TTL,
				$successor_hash,
				$hash,
				Tokens::TYPE_REFRESH
			)
		);

		return false === $changed ? false : ( $changed > 0 ? 1 : 0 );
	}

	/**
	 * Takes over a marker from the pair issued for it before, so only one retry replaces that pair.
	 *
	 * @param string $marker_hash   The hashed spent refresh token.
	 * @param string $previous_hash The successor the marker named when it was read ('' if none).
	 * @param string $new_hash      The hash of the refresh token that will replace it.
	 * @return int|false 1 when this call took the marker over, 0 when another retry got there first, false on a database error.
	 */
	private static function claim_marker( $marker_hash, $previous_hash, $new_hash ) {
		global $wpdb;

		$guard = '' === $previous_hash ? "(parent_hash IS NULL OR parent_hash = '')" : $wpdb->prepare( 'parent_hash = %s', $previous_hash );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$changed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET parent_hash = %s WHERE token_hash = %s AND type = %s AND {$guard}",
				Store::table( 'tokens' ),
				$new_hash,
				$marker_hash,
				Tokens::TYPE_ROTATED
			)
		);

		return false === $changed ? false : ( $changed > 0 ? 1 : 0 );
	}

	/**
	 * Answers a retry of a refresh whose reply may never have arrived.
	 *
	 * Inside the grace window the same refresh token gets a new pair. The pair issued the first
	 * time is revoked first, so one refresh token never leaves two live pairs behind. A reuse after
	 * the window is rejected, as it always was.
	 *
	 * @param string               $hash The hashed (spent) refresh token the caller presented.
	 * @param array<string, mixed> $row  Its marker row.
	 * @return WP_REST_Response The token response.
	 */
	private static function reissue_after_rotation( $hash, array $row ) {
		global $wpdb;

		$previous  = (string) $row['parent_hash'];
		$successor = Tokens::generate();
		$claimed   = self::claim_marker( $hash, $previous, Tokens::hash( $successor ) );

		if ( false === $claimed ) {
			Debug_Logger::error(
				Debug_Logger::CHANNEL_OAUTH,
				'Could not answer a refresh retry. The database write failed.',
				array( 'client_id' => (string) $row['client_id'] )
			);
			return self::token_error( 'server_error', __( 'The refresh could not be completed. Try again shortly.', 'miniorange-secure-mcp-server' ), 500 );
		}
		if ( 0 === $claimed ) {
			// Another retry of this token is being answered right now. Asking the caller to come back
			// is safe (the next attempt finds the marker settled) and keeps one live pair.
			return self::token_error( 'temporarily_unavailable', __( 'Another refresh of this token is in progress. Try again shortly.', 'miniorange-secure-mcp-server' ), 503 );
		}

		if ( '' !== $previous ) {
			// Only its access token and the refresh token itself: the marker rows that point at the
			// successor (including this one) must survive for a further retry.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete(
				Store::table( 'tokens' ),
				array(
					'parent_hash' => $previous,
					'type'        => Tokens::TYPE_ACCESS,
				)
			);
			Store::delete_token( $previous );
		}

		Debug_Logger::warning(
			Debug_Logger::CHANNEL_OAUTH,
			'A spent refresh token was used again inside the grace window; issued a new pair.',
			array(
				'client_id'   => (string) $row['client_id'],
				'age_seconds' => max( 0, Tokens::REFRESH_GRACE_TTL - ( (int) $row['expires'] - time() ) ),
			)
		);

		return self::issue_successor( $hash, $row, $successor, 'refresh_token_retry' );
	}

	/**
	 * Issues the pair that replaces a spent refresh token, and checks it is still the one the marker names.
	 *
	 * A retry can take the marker over while this pair is still being written. In that case this pair has
	 * already been replaced, so it is revoked rather than left live beside the retry's.
	 *
	 * @param string               $marker_hash The hashed spent refresh token.
	 * @param array<string, mixed> $row         The spent token's row.
	 * @param string               $refresh     The new refresh token, already named on the marker.
	 * @param string               $grant_type  The grant that produced this pair.
	 * @return WP_REST_Response The token response (200), or an error response.
	 */
	private static function issue_successor( $marker_hash, array $row, $refresh, $grant_type ) {
		global $wpdb;

		$response = self::issue_token_response( (string) $row['client_id'], (int) $row['user_id'], $row['scope'], $row['resource'], $grant_type, $refresh );
		if ( 200 !== $response->get_status() ) {
			return $response;
		}

		$refresh_hash = Tokens::hash( $refresh );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$current = $wpdb->get_var(
			$wpdb->prepare( 'SELECT parent_hash FROM %i WHERE token_hash = %s AND type = %s', Store::table( 'tokens' ), $marker_hash, Tokens::TYPE_ROTATED )
		);
		if ( $current === $refresh_hash ) {
			return $response;
		}

		Store::delete_tokens_by_parent( $refresh_hash );
		Store::delete_token( $refresh_hash );

		return self::token_error( 'temporarily_unavailable', __( 'Another refresh of this token is in progress. Try again shortly.', 'miniorange-secure-mcp-server' ), 503 );
	}

	/**
	 * Mints an access/refresh token pair and builds the token response.
	 *
	 * @param string      $client_id  The client identifier.
	 * @param int         $user_id    The WordPress user the tokens act on behalf of.
	 * @param string      $scope      The granted scope.
	 * @param string      $audience   The bound resource (audience).
	 * @param string      $grant_type The grant that produced this pair.
	 * @param string|null $refresh    A refresh token chosen in advance, or null to generate one.
	 * @return WP_REST_Response The token response.
	 */
	private static function issue_token_response( $client_id, $user_id, $scope, $audience, $grant_type = '', $refresh = null ) {
		$refresh      = null === $refresh ? Tokens::generate() : $refresh;
		$refresh_hash = Tokens::hash( $refresh );

		$refresh_stored = Store::insert_token(
			array(
				'token_hash'  => $refresh_hash,
				'type'        => Tokens::TYPE_REFRESH,
				'client_id'   => $client_id,
				'user_id'     => $user_id,
				'scope'       => $scope,
				'resource'    => $audience,
				'expires'     => time() + Tokens::REFRESH_TTL,
				'parent_hash' => null,
			)
		);

		$access = Tokens::generate();

		$access_stored = $refresh_stored && Store::insert_token(
			array(
				'token_hash'  => Tokens::hash( $access ),
				'type'        => Tokens::TYPE_ACCESS,
				'client_id'   => $client_id,
				'user_id'     => $user_id,
				'scope'       => $scope,
				'resource'    => $audience,
				'expires'     => time() + Tokens::ACCESS_TTL,
				'parent_hash' => $refresh_hash,
			)
		);

		if ( ! $access_stored ) {
			// Never hand out credentials that were not stored: the caller would hold a token that
			// every later request rejects, with nothing anywhere saying why.
			if ( $refresh_stored ) {
				Store::delete_token( $refresh_hash );
			}
			Debug_Logger::error(
				Debug_Logger::CHANNEL_OAUTH,
				'Could not store the issued tokens. The database write failed.',
				array(
					'client_id'  => $client_id,
					'grant_type' => $grant_type,
				)
			);
			return self::token_error( 'server_error', __( 'The tokens could not be issued. Try again shortly.', 'miniorange-secure-mcp-server' ), 500 );
		}

		Debug_Logger::info(
			Debug_Logger::CHANNEL_OAUTH,
			'Issued an access token.',
			array(
				'grant_type' => $grant_type,
				// The audience recorded at mint time. The MCP endpoint logs the same key
				// at use time, so an audience mismatch reads straight off the two rows
				// instead of having to be inferred from a rejection on its own.
				'issued_for' => (string) $audience,
				'expires_in' => Tokens::ACCESS_TTL,
				'client_id'  => $client_id,
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
			Debug_Logger::warning( Debug_Logger::CHANNEL_OAUTH, 'Token request rejected: missing client_id.' );
			return new WP_Error( 'invalid_client', __( 'Missing client_id.', 'miniorange-secure-mcp-server' ) );
		}

		$client = Store::get_client( $client_id );
		if ( ! $client ) {
			Debug_Logger::warning( Debug_Logger::CHANNEL_OAUTH, 'Token request rejected: unknown client.', array( 'client_id' => $client_id ) );
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
			Debug_Logger::warning(
				Debug_Logger::CHANNEL_OAUTH,
				'Token request rejected: client secret authentication failed.',
				array(
					'client_id'   => $client_id,
					'client_name' => isset( $client['client_name'] ) ? (string) $client['client_name'] : '',
				)
			);
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
