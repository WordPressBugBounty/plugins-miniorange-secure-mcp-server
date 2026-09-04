<?php
/**
 * OAuth discovery metadata (RFC 9728 and RFC 8414) and the 401 challenge header.
 *
 * @package Miniorange_Secure_MCP_Server
 */

namespace MoSMCP\Common\Services\Discovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MoSMCP\Common\Utils\Utils;

/**
 * Class Discovery
 *
 * Publishes the Protected Resource Metadata and Authorization Server Metadata
 * documents that MCP clients use to discover this site's OAuth endpoints, and
 * builds the WWW-Authenticate header returned on unauthenticated MCP requests.
 */
class Discovery {

	/**
	 * Returns the URL of the Protected Resource Metadata document.
	 *
	 * @return string The metadata URL.
	 */
	public static function protected_resource_metadata_url() {
		return Utils::issuer_url() . '/.well-known/oauth-protected-resource';
	}

	/**
	 * Builds the Protected Resource Metadata document (RFC 9728).
	 *
	 * @return array<string, mixed>
	 */
	public static function protected_resource_metadata() {
		return array(
			'resource'                 => Utils::resource_url(),
			'authorization_servers'    => array( Utils::issuer_url() ),
			'scopes_supported'         => array( 'mcp' ),
			'bearer_methods_supported' => array( 'header' ),
			'resource_documentation'   => 'https://plugins.miniorange.com/',
		);
	}

	/**
	 * Builds the Authorization Server Metadata document (RFC 8414).
	 *
	 * @return array<string, mixed>
	 */
	public static function authorization_server_metadata() {
		return array(
			'issuer'                                => Utils::issuer_url(),
			'authorization_endpoint'                => Utils::maybe_force_https( admin_url( 'admin-post.php?action=mosmcp_authorize' ) ),
			'token_endpoint'                        => Utils::maybe_force_https( rest_url( MOSMCP_REST_NAMESPACE . '/token' ) ),
			'registration_endpoint'                 => Utils::maybe_force_https( rest_url( MOSMCP_REST_NAMESPACE . '/register' ) ),
			'scopes_supported'                      => array( 'mcp' ),
			'response_types_supported'              => array( 'code' ),
			'grant_types_supported'                 => array( 'authorization_code', 'refresh_token' ),
			'code_challenge_methods_supported'      => array( 'S256' ),
			'token_endpoint_auth_methods_supported' => array( 'none', 'client_secret_post', 'client_secret_basic' ),
		);
	}

	/**
	 * Builds the value for a WWW-Authenticate response header.
	 *
	 * @param string $error Optional OAuth error code (e.g. 'invalid_token').
	 * @return string The header value.
	 */
	public static function unauthorized_header( $error = '' ) {
		$value = sprintf( 'Bearer resource_metadata="%s"', self::protected_resource_metadata_url() );

		if ( '' !== $error ) {
			$value .= sprintf( ', error="%s"', $error );
		}

		return $value;
	}

	/**
	 * Registers rewrite rules so WordPress formally owns the well-known discovery
	 * paths (and resolves them reliably on sites with pretty permalinks), instead of
	 * relying only on runtime path-sniffing in {@see maybe_serve_wellknown()}.
	 *
	 * Hooked on init; rules are persisted by flush_rewrite_rules() on activation.
	 *
	 * @return void
	 */
	public static function register_rewrite() {
		add_rewrite_rule( '^\.well-known/oauth-protected-resource(/.*)?/?$', 'index.php?mosmcp_wellknown=prs', 'top' );
		add_rewrite_rule( '^\.well-known/oauth-authorization-server(/.*)?/?$', 'index.php?mosmcp_wellknown=as', 'top' );
	}

	/**
	 * Registers the query var the rewrite rules map to.
	 *
	 * @param string[] $vars Existing public query vars.
	 * @return string[]
	 */
	public static function add_query_var( $vars ) {
		$vars[] = 'mosmcp_wellknown';
		return $vars;
	}

	/**
	 * Serves a discovery document directly when the request targets a recognised
	 * well-known path — via the rewrite query var, or a raw path match as fallback.
	 *
	 * @return void
	 */
	public static function maybe_serve_wellknown() {
		$which = '';

		if ( isset( $GLOBALS['wp'] ) && ! empty( $GLOBALS['wp']->query_vars['mosmcp_wellknown'] ) ) {
			$which = (string) $GLOBALS['wp']->query_vars['mosmcp_wellknown'];
		}

		if ( '' === $which && ! empty( $_SERVER['REQUEST_URI'] ) ) {
			$uri  = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) );
			$path = (string) wp_parse_url( $uri, PHP_URL_PATH );

			if ( preg_match( '#/\.well-known/oauth-protected-resource(/.*)?$#', $path ) ) {
				$which = 'prs';
			} elseif ( preg_match( '#/\.well-known/oauth-authorization-server(/.*)?$#', $path ) ) {
				$which = 'as';
			}
		}

		if ( 'prs' === $which ) {
			self::send_json( self::protected_resource_metadata() );
		}
		if ( 'as' === $which ) {
			self::send_json( self::authorization_server_metadata() );
		}
	}

	/**
	 * Emits a JSON document with caching and CORS headers, then exits.
	 *
	 * @param array<string, mixed> $data
	 * @return void
	 */
	private static function send_json( array $data ) {
		if ( ! headers_sent() ) {
			header( 'Access-Control-Allow-Origin: *' );
			header( 'Cache-Control: public, max-age=3600' );
		}

		wp_send_json( $data, 200 );
	}
}