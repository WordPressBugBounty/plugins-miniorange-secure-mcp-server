<?php
/**
 * Shared URL helpers for the MCP server and OAuth endpoints.
 *
 * @package Miniorange_Secure_MCP_Server
 */

namespace MoSMCP\Common\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_REST_Request;

/**
 * Class Utils
 *
 * Stateless helpers for deriving the plugin's canonical URLs and applying the
 * optional public-base override used during local development.
 */
class Utils {

	/**
	 * Returns the canonical resource URI of the MCP server.
	 *
	 * @return string The canonical MCP endpoint URL, without a trailing slash.
	 */
	public static function resource_url() {
		return self::maybe_force_https( untrailingslashit( rest_url( MOSMCP_REST_NAMESPACE . '/mcp' ) ) );
	}

	/**
	 * Returns the OAuth issuer identifier for this site.
	 *
	 * @return string The issuer URL, without a trailing slash.
	 */
	public static function issuer_url() {
		return self::maybe_force_https( untrailingslashit( home_url() ) );
	}

	/**
	 * Whether the current request actually reached the site over HTTPS, including
	 * when TLS is terminated at a reverse proxy / load balancer (Cloudflare, etc.).
	 *
	 * @return bool
	 */
	public static function request_is_https() {
		if ( is_ssl() ) {
			return true;
		}
		if ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ) {
			$proto = strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ) );
			if ( 'https' === $proto ) {
				return true;
			}
		}
		if ( isset( $_SERVER['HTTP_X_FORWARDED_SSL'] ) ) {
			$ssl = strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_SSL'] ) ) );
			if ( 'on' === $ssl ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Upgrades an http:// URL to https:// when the request truly arrived over HTTPS.
	 *
	 * Fixes the reverse-proxy case where WordPress is configured as http:// but is served
	 * publicly over https: without this the plugin would advertise http:// OAuth/metadata
	 * endpoints, which MCP clients refuse.
	 *
	 * @param string $url The URL to normalize.
	 * @return string The (possibly https) URL.
	 */
	public static function maybe_force_https( $url ) {
		if ( is_string( $url ) && 0 === strpos( $url, 'http://' ) && self::request_is_https() ) {
			return 'https://' . substr( $url, 7 );
		}
		return $url;
	}

	/**
	 * Returns the configured public base URL override, or an empty string when off.
	 *
	 * @return string The public base, without a trailing slash.
	 */
	public static function public_base() {
		if ( defined( 'MOSMCP_PUBLIC_BASE' ) && '' !== MOSMCP_PUBLIC_BASE ) {
			return untrailingslashit( MOSMCP_PUBLIC_BASE );
		}

		return '';
	}

	/**
	 * Returns the local base URLs that the public base override should replace.
	 *
	 * @return string[] The local base URLs, without trailing slashes.
	 */
	public static function local_bases() {
		static $bases = null;

		if ( null !== $bases ) {
			return $bases;
		}

		$candidates = array();

		if ( defined( 'WP_HOME' ) && WP_HOME ) {
			$candidates[] = WP_HOME;
		}
		if ( defined( 'WP_SITEURL' ) && WP_SITEURL ) {
			$candidates[] = WP_SITEURL;
		}
		$candidates[] = get_option( 'home' );
		$candidates[] = get_option( 'siteurl' );

		$bases = array();
		foreach ( $candidates as $candidate ) {
			if ( is_string( $candidate ) && '' !== $candidate ) {
				$bases[] = untrailingslashit( $candidate );
			}
		}

		$bases = array_values( array_unique( array_filter( $bases ) ) );

		return $bases;
	}

	/**
	 * Rewrites a generated WordPress URL onto the public base override.
	 *
	 * @param string $url The URL produced by WordPress.
	 * @return string The rewritten URL.
	 */
	public static function rewrite_url( $url ) {
		$public = self::public_base();

		if ( '' === $public || ! is_string( $url ) || '' === $url ) {
			return $url;
		}

		foreach ( self::local_bases() as $base ) {
			if ( 0 === strpos( $url, $base ) ) {
				return $public . substr( $url, strlen( $base ) );
			}
		}

		return $url;
	}

	/**
	 * Whether this site's public URL is localhost/127.0.0.1 — unreachable by any AI
	 * client outside this machine. False when a public-base override is configured
	 * (`MOSMCP_PUBLIC_BASE`), since that override exists precisely to work around this.
	 *
	 * @return bool
	 */
	public static function is_localhost_site() {
		if ( '' !== self::public_base() ) {
			return false;
		}

		$host = wp_parse_url( home_url(), PHP_URL_HOST );

		return in_array( $host, array( 'localhost', '127.0.0.1' ), true );
	}

	/**
	 * Extracts the bearer token from a request, working around servers that strip the
	 * Authorization header from the CGI environment.
	 *
	 * Shared by {@see \MoSMCP\Common\Controllers\MCP\REST_Controller::authenticate()} (the real
	 * auth path) and the connectivity-diagnostic header-echo check, so both see identical
	 * extraction behavior.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return string The token, or an empty string when absent.
	 */
	public static function extract_bearer_token( WP_REST_Request $request ) {
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

		// Some Apache/FastCGI setups surface the header only via apache_request_headers()
		// (not $_SERVER['HTTP_AUTHORIZATION'] or getallheaders()).
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
}
