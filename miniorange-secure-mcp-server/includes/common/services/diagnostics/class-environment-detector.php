<?php
/**
 * Detects the CDN, cache, firewall and web server layers in front of the site.
 *
 * @package Miniorange_Secure_MCP_Server
 */

namespace MoSMCP\Common\Services\Diagnostics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Environment_Detector
 *
 * Pure: callers pass response headers, active plugin paths and server globals. The result tailors
 * fix hints (for example naming Cloudflare or LiteSpeed Cache) and picks the right header fix.
 */
class Environment_Detector {

	/**
	 * Active-plugin directory => layer name.
	 */
	const PLUGIN_LAYERS = array(
		'wordfence'                           => 'wordfence',
		'sucuri-scanner'                      => 'sucuri',
		'better-wp-security'                  => 'solid_security',
		'ithemes-security-pro'                => 'solid_security',
		'all-in-one-wp-security-and-firewall' => 'aios',
		'wp-cerber'                           => 'wp_cerber',
		'cloudflare'                          => 'cloudflare',
		'litespeed-cache'                     => 'litespeed_cache',
		'wp-rocket'                           => 'wp_rocket',
		'w3-total-cache'                      => 'w3_total_cache',
		'wp-super-cache'                      => 'wp_super_cache',
		'wp-fastest-cache'                    => 'wp_fastest_cache',
		'disable-json-api'                    => 'rest_disabler',
		'disable-wp-rest-api'                 => 'rest_disabler',
		'hostinger'                           => 'hostinger',
	);

	/**
	 * Detects layers from headers and active plugins.
	 *
	 * @param array<string,string> $headers         Lowercase response headers from a loopback request.
	 * @param string[]             $active_plugins  Active plugin paths such as "wordfence/wordfence.php".
	 * @param string               $server_software The SERVER_SOFTWARE string.
	 * @param string               $sapi            PHP_SAPI value.
	 * @return array{layers:string[], server:string, sapi:string, auth_fix:string}
	 */
	public static function detect( array $headers, array $active_plugins, $server_software, $sapi ) {
		$layers = array();
		$server = isset( $headers['server'] ) ? strtolower( $headers['server'] ) : '';

		if ( isset( $headers['cf-ray'] ) || 'cloudflare' === $server ) {
			$layers[] = 'cloudflare';
		}
		if ( 0 === strpos( $server, 'litespeed' ) || 'litespeed' === $sapi || isset( $headers['x-litespeed-cache'] ) ) {
			$layers[] = 'litespeed';
		}
		if ( isset( $headers['x-sucuri-id'] ) ) {
			$layers[] = 'sucuri';
		}
		if ( isset( $headers['platform'] ) && false !== stripos( $headers['platform'], 'hostinger' ) ) {
			$layers[] = 'hostinger';
		}

		foreach ( $active_plugins as $path ) {
			// The option is user-editable data: ignore anything that is not a "dir/file.php" string.
			if ( ! is_string( $path ) ) {
				continue;
			}
			$dir = strtok( $path, '/' );
			if ( false !== $dir && isset( self::PLUGIN_LAYERS[ $dir ] ) ) {
				$layers[] = self::PLUGIN_LAYERS[ $dir ];
			}
		}

		$layers = array_values( array_unique( $layers ) );
		$kind   = self::server_kind( $server_software, $sapi );

		return array(
			'layers'   => $layers,
			'server'   => $kind,
			'sapi'     => (string) $sapi,
			'auth_fix' => self::auth_fix_kind( $kind ),
		);
	}

	/**
	 * Normalizes the web server family.
	 *
	 * @param string $server_software The SERVER_SOFTWARE string.
	 * @param string $sapi            PHP_SAPI value.
	 * @return string apache|litespeed|nginx|iis|unknown
	 */
	public static function server_kind( $server_software, $sapi ) {
		$software = strtolower( (string) $server_software );

		if ( false !== strpos( $software, 'litespeed' ) || 'litespeed' === $sapi ) {
			return 'litespeed';
		}
		if ( false !== strpos( $software, 'nginx' ) ) {
			return 'nginx';
		}
		if ( false !== strpos( $software, 'apache' ) ) {
			return 'apache';
		}
		if ( false !== strpos( $software, 'iis' ) ) {
			return 'iis';
		}

		return 'unknown';
	}

	/**
	 * Which header fix applies: an editable .htaccess rule, an nginx server-block change, or a host-only change.
	 *
	 * @param string $server_kind Result of {@see server_kind()}.
	 * @return string htaccess|nginx|host_only
	 */
	public static function auth_fix_kind( $server_kind ) {
		if ( 'apache' === $server_kind || 'litespeed' === $server_kind ) {
			return 'htaccess';
		}

		return 'nginx' === $server_kind ? 'nginx' : 'host_only';
	}
}
