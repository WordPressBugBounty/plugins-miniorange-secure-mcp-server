<?php
/**
 * Turns plugin/site state (tables, agents, grants, URLs, certificate) into check verdicts.
 *
 * @package Miniorange_Secure_MCP_Server
 */

namespace MoSMCP\Common\Services\Diagnostics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Setup_Evaluator
 *
 * Pure counterpart of the WordPress-bound local checks: callers gather the facts, this decides
 * what they mean. Verdicts share the shape of {@see Response_Classifier::verdict()}.
 */
class Setup_Evaluator {

	/**
	 * Days before expiry at which a certificate starts to warn.
	 */
	const CERT_WARN_DAYS = 14;

	/**
	 * Checks every expected plugin table exists.
	 *
	 * @param string[] $expected Table names the schema defines.
	 * @param string[] $present  Table names found in the database.
	 * @return array
	 */
	public static function evaluate_tables( array $expected, array $present ) {
		$missing = array_values( array_diff( $expected, $present ) );

		if ( empty( $missing ) ) {
			/* translators: %d: number of tables. */
			return Response_Classifier::verdict( Response_Classifier::PASS, 'ok', sprintf( __( 'All %d plugin tables exist.', 'miniorange-secure-mcp-server' ), count( $expected ) ) );
		}

		/* translators: %s: comma-separated table names. */
		$detail = sprintf( __( 'Missing tables: %s. Agents and tokens cannot be saved without them.', 'miniorange-secure-mcp-server' ), implode( ', ', $missing ) );

		return Response_Classifier::verdict( Response_Classifier::FAIL, 'tables_missing', $detail );
	}

	/**
	 * Compares the recorded schema version with the running plugin version.
	 *
	 * @param string|false $stored  Value of the stored schema-version option.
	 * @param string       $current The running plugin version.
	 * @return array
	 */
	public static function evaluate_schema_version( $stored, $current ) {
		if ( false === $stored || '' === $stored ) {
			return Response_Classifier::verdict( Response_Classifier::FAIL, 'schema_missing', __( 'The plugin has never recorded a database version, so its install step did not complete.', 'miniorange-secure-mcp-server' ) );
		}

		if ( version_compare( (string) $stored, (string) $current, '<' ) ) {
			/* translators: 1: stored schema version, 2: plugin version. */
			return Response_Classifier::verdict( Response_Classifier::FAIL, 'schema_outdated', sprintf( __( 'The database is at version %1$s but the plugin is %2$s, so the upgrade did not finish.', 'miniorange-secure-mcp-server' ), $stored, $current ) );
		}

		/* translators: %s: schema version. */
		return Response_Classifier::verdict( Response_Classifier::PASS, 'ok', sprintf( __( 'Database schema is current (%s).', 'miniorange-secure-mcp-server' ), $current ) );
	}

	/**
	 * Checks at least one enabled agent (NHI) exists.
	 *
	 * @param int $total   Agents defined.
	 * @param int $enabled Agents enabled.
	 * @return array
	 */
	public static function evaluate_agents( $total, $enabled ) {
		if ( $total < 1 ) {
			return Response_Classifier::verdict( Response_Classifier::FAIL, 'no_agent', __( 'No agent exists yet, so every AI client is refused.', 'miniorange-secure-mcp-server' ) );
		}
		if ( $enabled < 1 ) {
			return Response_Classifier::verdict( Response_Classifier::FAIL, 'agent_disabled', __( 'Agents exist but all are disabled, so every AI client is refused.', 'miniorange-secure-mcp-server' ) );
		}

		/* translators: 1: enabled agents, 2: total agents. */
		return Response_Classifier::verdict( Response_Classifier::PASS, 'ok', sprintf( __( '%1$d of %2$d agents enabled.', 'miniorange-secure-mcp-server' ), $enabled, $total ) );
	}

	/**
	 * Checks abilities are registered and granted to at least one role.
	 *
	 * @param int $registered Abilities registered with WordPress.
	 * @param int $grants     Ability grants across enabled agents.
	 * @param int $exposed    Abilities the current user's role would see.
	 * @return array
	 */
	public static function evaluate_grants( $registered, $grants, $exposed ) {
		if ( $registered < 1 ) {
			return Response_Classifier::verdict( Response_Classifier::FAIL, 'no_abilities_registered', __( 'WordPress has no registered abilities, so there is nothing to expose.', 'miniorange-secure-mcp-server' ) );
		}
		if ( $grants < 1 ) {
			return Response_Classifier::verdict( Response_Classifier::FAIL, 'no_grants', __( 'No role has any ability granted, so AI clients connect but see 0 abilities.', 'miniorange-secure-mcp-server' ) );
		}
		if ( $exposed < 1 ) {
			return Response_Classifier::verdict( Response_Classifier::WARN, 'role_has_no_abilities', __( 'Abilities are granted, but none to your role, so a connection signed in as you sees 0 abilities.', 'miniorange-secure-mcp-server' ) );
		}

		/* translators: 1: abilities visible to this account, 2: abilities registered. */
		return Response_Classifier::verdict( Response_Classifier::PASS, 'ok', sprintf( __( 'A connection signed in as you will see %1$d of %2$d registered abilities.', 'miniorange-secure-mcp-server' ), $exposed, $registered ) );
	}

	/**
	 * Judges the site address settings.
	 *
	 * @param array{home:string, siteurl:string, request_host:string, localhost:bool} $in The addresses.
	 * @return array{https:array, hosts:array}
	 */
	public static function evaluate_urls( array $in ) {
		$home   = self::parts( $in['home'] );
		$site   = self::parts( $in['siteurl'] );
		$report = array();

		if ( ! empty( $in['localhost'] ) ) {
			$report['https'] = Response_Classifier::verdict( Response_Classifier::FAIL, 'localhost_site', __( 'The site address is localhost, which no AI client outside this computer can reach.', 'miniorange-secure-mcp-server' ) );
		} elseif ( 'https' !== $home['scheme'] ) {
			$report['https'] = Response_Classifier::verdict( Response_Classifier::WARN, 'not_https', __( 'The site address uses http. Claude and ChatGPT require https.', 'miniorange-secure-mcp-server' ) );
		} else {
			$report['https'] = Response_Classifier::verdict( Response_Classifier::PASS, 'ok', __( 'The site address uses https.', 'miniorange-secure-mcp-server' ) );
		}

		$request_host = strtolower( (string) $in['request_host'] );
		$request_host = preg_replace( '/:\d+$/', '', $request_host );

		if ( $home['host'] !== $site['host'] ) {
			/* translators: 1: WordPress Address host, 2: Site Address host. */
			$report['hosts'] = Response_Classifier::verdict( Response_Classifier::WARN, 'host_mismatch', sprintf( __( 'WordPress Address (%1$s) and Site Address (%2$s) use different hosts, so advertised URLs and the address clients call can disagree.', 'miniorange-secure-mcp-server' ), $site['host'], $home['host'] ) );
		} elseif ( '' !== $request_host && $request_host !== $home['host'] && self::strip_www( $request_host ) === self::strip_www( $home['host'] ) ) {
			/* translators: 1: host the admin used, 2: configured host. */
			$report['hosts'] = Response_Classifier::verdict( Response_Classifier::WARN, 'www_mismatch', sprintf( __( 'You opened this page on %1$s but the site is configured as %2$s, so both serve the site without redirecting. Pick one and redirect the other.', 'miniorange-secure-mcp-server' ), $request_host, $home['host'] ) );
		} else {
			$report['hosts'] = Response_Classifier::verdict( Response_Classifier::PASS, 'ok', __( 'Site Address, WordPress Address and the address you used all agree.', 'miniorange-secure-mcp-server' ) );
		}

		return $report;
	}

	/**
	 * Judges a TLS certificate fetched from the site.
	 *
	 * @param array{error?:string, valid_to?:int, names?:string[]} $cert The certificate facts.
	 * @param string                                               $host The site host.
	 * @param int                                                  $now  Current Unix time.
	 * @return array
	 */
	public static function evaluate_certificate( array $cert, $host, $now ) {
		if ( isset( $cert['error'] ) && 'connect' === $cert['error'] ) {
			return Response_Classifier::verdict( Response_Classifier::WARN, 'cert_unreadable', __( 'This server could not open an HTTPS connection to the site\'s public address to read its certificate (it may be unable to reach itself).', 'miniorange-secure-mcp-server' ) );
		}

		if ( ! empty( $cert['error'] ) ) {
			return Response_Classifier::verdict( Response_Classifier::FAIL, 'transport_tls', __( 'The site could not complete an HTTPS handshake or its certificate chain is invalid.', 'miniorange-secure-mcp-server' ) );
		}

		$valid_to = isset( $cert['valid_to'] ) ? (int) $cert['valid_to'] : 0;
		if ( $valid_to > 0 && $valid_to < $now ) {
			return Response_Classifier::verdict( Response_Classifier::FAIL, 'cert_expired', __( 'The HTTPS certificate has expired.', 'miniorange-secure-mcp-server' ) );
		}

		$names = isset( $cert['names'] ) ? (array) $cert['names'] : array();
		if ( ! empty( $names ) && ! self::certificate_covers( $names, $host ) ) {
			/* translators: %s: site host. */
			return Response_Classifier::verdict( Response_Classifier::FAIL, 'cert_name_mismatch', sprintf( __( 'The HTTPS certificate does not cover %s.', 'miniorange-secure-mcp-server' ), $host ) );
		}

		$days = (int) floor( ( $valid_to - $now ) / 86400 );
		if ( $valid_to > 0 && $days < self::CERT_WARN_DAYS ) {
			/* translators: %d: days until the certificate expires. */
			return Response_Classifier::verdict( Response_Classifier::WARN, 'cert_expiring', sprintf( __( 'The HTTPS certificate expires in %d days.', 'miniorange-secure-mcp-server' ), $days ) );
		}

		return Response_Classifier::verdict( Response_Classifier::PASS, 'ok', __( 'The HTTPS certificate is valid and covers this host.', 'miniorange-secure-mcp-server' ) );
	}

	/**
	 * Combines the pretty-permalink and ?rest_route= probes of the same endpoint.
	 *
	 * @param array $pretty   Verdict for /wp-json/mosmcp/v1/health.
	 * @param array $fallback Verdict for /index.php?rest_route=/mosmcp/v1/health.
	 * @return array
	 */
	public static function evaluate_rest_paths( array $pretty, array $fallback ) {
		if ( Response_Classifier::PASS === $pretty['status'] && Response_Classifier::PASS === $fallback['status'] ) {
			return Response_Classifier::verdict( Response_Classifier::PASS, 'ok', __( 'The REST API answers on /wp-json/ and on the ?rest_route= fallback.', 'miniorange-secure-mcp-server' ) );
		}

		if ( Response_Classifier::PASS === $fallback['status'] ) {
			return Response_Classifier::verdict( Response_Classifier::WARN, 'wpjson_blocked', __( '/wp-json/ is blocked or not routed, but the ?rest_route= fallback works.', 'miniorange-secure-mcp-server' ) );
		}

		if ( Response_Classifier::PASS === $pretty['status'] ) {
			return Response_Classifier::verdict( Response_Classifier::WARN, 'rest_route_blocked', __( '/wp-json/ works but the ?rest_route= fallback is blocked, so a client forced onto the fallback would fail.', 'miniorange-secure-mcp-server' ) );
		}

		return $pretty;
	}

	/**
	 * Compares the ability count the gateway discovered with what this site expects.
	 *
	 * @param int      $expected Count this site computes for the test user.
	 * @param int|null $seen     Count the gateway discovered, null when unknown.
	 * @return array
	 */
	public static function evaluate_count( $expected, $seen ) {
		if ( null === $seen ) {
			return Response_Classifier::verdict( Response_Classifier::SKIP, 'not_reached', __( 'No ability count was discovered.', 'miniorange-secure-mcp-server' ) );
		}
		if ( 0 === (int) $seen ) {
			return Response_Classifier::verdict( Response_Classifier::FAIL, 'zero_abilities', __( 'The gateway connected and authenticated but discovered 0 abilities.', 'miniorange-secure-mcp-server' ) );
		}
		if ( (int) $seen < (int) $expected ) {
			/* translators: 1: abilities the gateway discovered, 2: abilities this site expects. */
			return Response_Classifier::verdict( Response_Classifier::FAIL, 'count_mismatch', sprintf( __( 'The gateway discovered %1$d abilities but this dashboard expects %2$d.', 'miniorange-secure-mcp-server' ), $seen, $expected ) );
		}
		// More than expected is not a customer problem, but say so rather than claim an exact match.
		if ( (int) $seen > (int) $expected ) {
			/* translators: 1: abilities the gateway discovered, 2: abilities this site expects. */
			return Response_Classifier::verdict( Response_Classifier::PASS, 'ok', sprintf( __( 'The gateway discovered %1$d abilities, more than the %2$d this dashboard expects.', 'miniorange-secure-mcp-server' ), $seen, $expected ) );
		}

		/* translators: %d: ability count. */
		return Response_Classifier::verdict( Response_Classifier::PASS, 'ok', sprintf( __( 'The gateway discovered %d abilities, matching this dashboard.', 'miniorange-secure-mcp-server' ), $seen ) );
	}

	/**
	 * Whether any certificate name covers a host, honouring single-label wildcards.
	 *
	 * @param string[] $names Subject alternative names / common name.
	 * @param string   $host  Host to cover.
	 * @return bool
	 */
	public static function certificate_covers( array $names, $host ) {
		$host = strtolower( $host );

		foreach ( $names as $name ) {
			$name = strtolower( trim( $name ) );
			if ( $name === $host ) {
				return true;
			}
			if ( 0 === strpos( $name, '*.' ) ) {
				$suffix = substr( $name, 1 );
				$label  = substr( $host, 0, -strlen( $suffix ) );
				if ( substr( $host, -strlen( $suffix ) ) === $suffix && '' !== $label && false === strpos( $label, '.' ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Splits a URL into lowercase scheme and host.
	 *
	 * @param string $url The URL.
	 * @return array{scheme:string, host:string}
	 */
	private static function parts( $url ) {
		$parsed = parse_url( (string) $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- WP-free by design.

		return array(
			'scheme' => is_array( $parsed ) && isset( $parsed['scheme'] ) ? strtolower( $parsed['scheme'] ) : '',
			'host'   => is_array( $parsed ) && isset( $parsed['host'] ) ? strtolower( $parsed['host'] ) : '',
		);
	}

	/**
	 * Removes a leading www. label.
	 *
	 * @param string $host The host.
	 * @return string
	 */
	private static function strip_www( $host ) {
		return 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : $host;
	}
}
