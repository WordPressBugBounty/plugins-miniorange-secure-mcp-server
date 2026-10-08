<?php
/**
 * Turns the gateway's probe report into check rows.
 *
 * @package Miniorange_Secure_MCP_Server
 */

namespace MoSMCP\Common\Services\Diagnostics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Gateway_Result_Evaluator
 *
 * Pure. Consumes the decoded body of the gateway's POST /diagnostics and returns display rows plus
 * the egress IPs to allowlist. Every field beyond the original `checks` is optional, so a gateway that
 * sends only `checks` still evaluates.
 * Detail text comes from {@see Response_Classifier} so each failure reads the same from either side.
 */
class Gateway_Result_Evaluator {

	/**
	 * Fingerprints that mean "a firewall answered instead of the site".
	 */
	const BLOCK_FINGERPRINTS = array( 'cloudflare_challenge', 'cloudflare_block', 'wordfence_block', 'sucuri_block', 'modsecurity_block', 'imunify_block', 'rest_restricted' );

	/**
	 * Legacy checks that only make sense once the MCP endpoint itself answered.
	 */
	const DEPENDENT_ON_MCP = array( 'auth_header_passthrough' );

	/**
	 * Checks that only matter for direct connections, which are deprecated. The gateway still runs them
	 * for older plugins; this plugin does not show them.
	 */
	const DIRECT_ONLY = array( 'wellknown_protected_resource', 'wellknown_authorization_server', 'www_authenticate_present' );

	/**
	 * Evaluates a gateway report.
	 *
	 * @param array $gw  Decoded gateway response.
	 * @param array $ctx {expected_count:int, discovery_requested:bool, now:int}.
	 * @return array{rows:array[], allowlist_ips:string[], stray_ips:string[]}
	 */
	public static function evaluate( array $gw, array $ctx ) {
		$rows  = array();
		$stray = array();

		foreach ( self::legacy_checks( isset( $gw['checks'] ) ? (array) $gw['checks'] : array() ) as $row ) {
			$rows[] = $row;
		}

		$egress = isset( $gw['egress'] ) ? (array) $gw['egress'] : array();
		$ips    = self::allowlist( $gw, $egress );

		if ( isset( $gw['dns'] ) || ! empty( $egress ) ) {
			list( $dns_row, $stray ) = self::dns_row( isset( $gw['dns'] ) ? (array) $gw['dns'] : array(), $egress );
			$rows[]                  = $dns_row;

			foreach ( self::egress_rows( $egress, $stray ) as $row ) {
				$rows[] = $row;
			}
		}

		$rows[] = self::discovery_row( isset( $gw['discovery'] ) ? (array) $gw['discovery'] : array(), $ctx );

		foreach ( self::token_rows( isset( $gw['token_health'] ) ? (array) $gw['token_health'] : array(), (int) $ctx['now'] ) as $row ) {
			$rows[] = $row;
		}

		return array(
			'rows'          => $rows,
			'allowlist_ips' => $ips,
			'stray_ips'     => $stray,
		);
	}

	/**
	 * Judges a linked client's tokens as stored on this site.
	 *
	 * @param array $client {client_name:string, last_issued:int|null, refresh_expires:int|null}.
	 * @param int   $now    Current Unix time.
	 * @return array
	 */
	public static function evaluate_linked_client( array $client, $now ) {
		$issued  = isset( $client['last_issued'] ) ? (int) $client['last_issued'] : 0;
		$expires = isset( $client['refresh_expires'] ) ? (int) $client['refresh_expires'] : 0;

		if ( $expires < 1 ) {
			return Response_Classifier::verdict( Response_Classifier::SKIP, 'token_none', __( 'This client registered but never finished signing in.', 'miniorange-secure-mcp-server' ) );
		}
		if ( $expires < $now ) {
			return Response_Classifier::verdict( Response_Classifier::FAIL, 'token_reauth', __( 'The refresh token expired, so this client must reconnect.', 'miniorange-secure-mcp-server' ) );
		}

		$age = self::human_age( $now - $issued );
		if ( $expires - $now < 172800 ) {
			/* translators: %s: how long ago the token was last refreshed. */
			return Response_Classifier::verdict( Response_Classifier::WARN, 'token_expiring', sprintf( __( 'Last refreshed %s and the refresh token expires within 2 days.', 'miniorange-secure-mcp-server' ), $age ) );
		}

		/* translators: %s: how long ago the token was last refreshed. */
		return Response_Classifier::verdict( Response_Classifier::PASS, 'ok', sprintf( __( 'Last refreshed %s.', 'miniorange-secure-mcp-server' ), $age ) );
	}

	/**
	 * Formats a duration compactly for diagnostics.
	 *
	 * @param int $seconds Seconds elapsed.
	 * @return string
	 */
	public static function human_age( $seconds ) {
		$seconds = max( 0, (int) $seconds );

		if ( $seconds < 3600 ) {
			/* translators: %d: minutes. */
			return sprintf( __( '%d min ago', 'miniorange-secure-mcp-server' ), (int) floor( $seconds / 60 ) );
		}
		if ( $seconds < 172800 ) {
			/* translators: %d: hours. */
			return sprintf( __( '%d h ago', 'miniorange-secure-mcp-server' ), (int) floor( $seconds / 3600 ) );
		}

		/* translators: %d: days. */
		return sprintf( __( '%d d ago', 'miniorange-secure-mcp-server' ), (int) floor( $seconds / 86400 ) );
	}

	/**
	 * Converts the gateway's original 10 checks into rows.
	 *
	 * @param array $checks Gateway check rows.
	 * @return array[]
	 */
	private static function legacy_checks( array $checks ) {
		$by_key = array();
		foreach ( $checks as $check ) {
			if ( isset( $check['check_key'] ) ) {
				$by_key[ $check['check_key'] ] = $check;
			}
		}

		$mcp_failed_upstream = isset( $by_key['mcp_json_passthrough'] )
			&& 'pass' !== $by_key['mcp_json_passthrough']['status']
			&& 401 !== ( isset( $by_key['mcp_json_passthrough']['http_status'] ) ? (int) $by_key['mcp_json_passthrough']['http_status'] : 0 );

		$rows = array();
		foreach ( $checks as $check ) {
			$key = isset( $check['check_key'] ) ? (string) $check['check_key'] : '';
			if ( in_array( $key, self::DIRECT_ONLY, true ) ) {
				continue;
			}

			if ( $mcp_failed_upstream && in_array( $key, self::DEPENDENT_ON_MCP, true ) ) {
				$verdict = Response_Classifier::verdict( Response_Classifier::SKIP, 'not_reached', __( 'Skipped: the MCP endpoint could not be reached.', 'miniorange-secure-mcp-server' ) );
			} else {
				$verdict = self::verdict_for_check( $check, $key );
			}

			$rows[] = self::row(
				$key,
				isset( $check['label'] ) ? (string) $check['label'] : $key,
				$verdict,
				array(
					'http_status' => isset( $check['http_status'] ) ? $check['http_status'] : null,
					'duration_ms' => isset( $check['duration_ms'] ) ? (int) $check['duration_ms'] : null,
				)
			);
		}

		return $rows;
	}

	/**
	 * Maps one gateway check to a verdict.
	 *
	 * @param array  $check The gateway check.
	 * @param string $key   Its check key.
	 * @return array
	 */
	private static function verdict_for_check( array $check, $key ) {
		if ( isset( $check['status'] ) && 'pass' === $check['status'] ) {
			return Response_Classifier::verdict( Response_Classifier::PASS, 'ok', __( 'Passed from the gateway.', 'miniorange-secure-mcp-server' ) );
		}

		$http    = isset( $check['http_status'] ) ? (int) $check['http_status'] : 0;
		$print   = isset( $check['fingerprint'] ) ? (string) $check['fingerprint'] : '';
		$kind    = isset( $check['error_kind'] ) ? (string) $check['error_kind'] : '';
		$blocked = $http > 0 || '' !== $print || '' !== $kind;

		if ( 'auth_header_passthrough' === $key && 401 === $http ) {
			// The gateway sends this text when the echo header never came back; only an explicit "0" means both headers were removed.
			if ( isset( $check['detail'] ) && 'the plugin did not report whether the Authorization header arrived' === $check['detail'] ) {
				return Response_Classifier::verdict( Response_Classifier::WARN, 'auth_header_unknown', __( 'The plugin did not report whether the Authorization header arrived (a proxy may be stripping response headers).', 'miniorange-secure-mcp-server' ) );
			}
			return Response_Classifier::verdict( Response_Classifier::FAIL, 'auth_header_stripped', __( 'The server removed both sign-in headers (Authorization and the gateway\'s backup header) before WordPress saw them, so every request looks signed out.', 'miniorange-secure-mcp-server' ) );
		}
		if ( 'scheme_host_consistency' === $key ) {
			return Response_Classifier::verdict( Response_Classifier::FAIL, 'scheme_host_mismatch', __( 'The site advertises addresses (http or another host) that differ from the one the gateway used, so tokens are rejected after connecting.', 'miniorange-secure-mcp-server' ) );
		}

		if ( $blocked ) {
			$verdict = Response_Classifier::common_failure( self::synthesize( $print, $http, $kind ) );
			if ( null !== $verdict ) {
				return $verdict;
			}
		}

		if ( isset( $check['detail'] ) && 'blocked' === $check['detail'] ) {
			return Response_Classifier::verdict( Response_Classifier::FAIL, 'gateway_refused_target', __( 'The gateway refuses to call this address because it is private or does not resolve publicly.', 'miniorange-secure-mcp-server' ) );
		}

		/* translators: %d: HTTP status. */
		return Response_Classifier::verdict( Response_Classifier::FAIL, 'unexpected_response', sprintf( __( 'The gateway got an unexpected response (HTTP %d).', 'miniorange-secure-mcp-server' ), $http ) );
	}

	/**
	 * Rebuilds a minimal response from the gateway's facts so the shared classifier supplies the wording.
	 *
	 * @param string $fingerprint Gateway body fingerprint.
	 * @param int    $http        HTTP status, 0 if none.
	 * @param string $error_kind  Transport error kind, '' if none.
	 * @return array
	 */
	private static function synthesize( $fingerprint, $http, $error_kind ) {
		if ( '' !== $error_kind ) {
			$messages = array(
				'dns'     => 'could not resolve host',
				'tls'     => 'ssl certificate problem',
				'refused' => 'connection refused',
				'timeout' => 'timed out',
			);
			return Response_Classifier::response( 0, array(), '', isset( $messages[ $error_kind ] ) ? $messages[ $error_kind ] : 'unknown error' );
		}

		$bodies = array(
			'cloudflare_challenge' => '<html><head><title>Just a moment...</title></head></html>',
			'cloudflare_block'     => '<html><head><title>Attention Required! | Cloudflare</title></head></html>',
			'wordfence_block'      => '<html><body>Your access to this site has been limited</body></html>',
			'sucuri_block'         => '<html><body>Sucuri Website Firewall</body></html>',
			'modsecurity_block'    => '<html><body>Not Acceptable! Mod_Security</body></html>',
			'imunify_block'        => '<html><body>Imunify360</body></html>',
			'rest_restricted'      => '{"code":"rest_forbidden"}',
			'html'                 => '<html><head></head></html>',
		);

		$body = isset( $bodies[ $fingerprint ] ) ? $bodies[ $fingerprint ] : ( '' === $fingerprint ? '' : 'x' );

		return Response_Classifier::response( $http > 0 ? $http : 403, array(), $body );
	}

	/**
	 * Every address worth allowlisting: published gateway IPs plus any that probed.
	 *
	 * @param array $gw     Gateway report.
	 * @param array $egress Egress entries.
	 * @return string[]
	 */
	private static function allowlist( array $gw, array $egress ) {
		$ips = isset( $gw['egress_ips'] ) ? (array) $gw['egress_ips'] : array();

		foreach ( $egress as $entry ) {
			if ( ! empty( $entry['ip'] ) ) {
				$ips[] = $entry['ip'];
			}
		}

		$ips = array_filter( array_map( array( __CLASS__, 'valid_ip' ), $ips ), 'strlen' );

		return array_values( array_unique( $ips ) );
	}

	/**
	 * Builds the DNS row and finds records that do not serve this site.
	 *
	 * @param array $dns    Gateway DNS report.
	 * @param array $egress Egress entries.
	 * @return array{0:array, 1:string[]} The row and the stray target IPs.
	 */
	private static function dns_row( array $dns, array $egress ) {
		$label = __( 'DNS records the gateway resolved', 'miniorange-secure-mcp-server' );

		if ( ! empty( $dns['error'] ) ) {
			return array( self::row( 'gateway_dns', $label, Response_Classifier::verdict( Response_Classifier::FAIL, 'transport_dns', __( 'The address could not be resolved (DNS).', 'miniorange-secure-mcp-server' ) ) ), array() );
		}

		$records = isset( $dns['records'] ) ? (array) $dns['records'] : array();
		$targets = self::target_outcomes( $egress );
		$stray   = array();

		$any_ok = in_array( true, $targets, true );
		foreach ( $targets as $ip => $ok ) {
			if ( ! $ok && $any_ok ) {
				$stray[] = $ip;
			}
		}

		if ( ! empty( $stray ) ) {
			/* translators: %s: comma-separated IP addresses. */
			$detail = sprintf( __( 'DNS points at addresses that do not serve this site: %s. Clients that pick one of them fail at random.', 'miniorange-secure-mcp-server' ), implode( ', ', $stray ) );
			return array( self::row( 'gateway_dns', $label, Response_Classifier::verdict( Response_Classifier::FAIL, 'dns_stray_records', $detail ), array( 'stray_ips' => $stray ) ), $stray );
		}

		/* translators: %d: number of A/AAAA records. */
		$detail = sprintf( __( '%d address records, all serving the site.', 'miniorange-secure-mcp-server' ), count( $records ) );

		return array( self::row( 'gateway_dns', $label, Response_Classifier::verdict( Response_Classifier::PASS, 'ok', $detail ), array( 'records' => $records ) ), array() );
	}

	/**
	 * For each target IP, whether at least one egress reached the site through it.
	 *
	 * @param array $egress Egress entries.
	 * @return array<string,bool>
	 */
	private static function target_outcomes( array $egress ) {
		$targets = array();

		foreach ( $egress as $entry ) {
			if ( isset( $entry['probed'] ) && false === $entry['probed'] ) {
				continue;
			}
			foreach ( isset( $entry['hops'] ) ? (array) $entry['hops'] : array() as $hop ) {
				$ip = self::valid_ip( isset( $hop['target_ip'] ) ? $hop['target_ip'] : '' );
				if ( '' === $ip || self::hop_unreachable( $hop ) ) {
					continue;
				}
				$targets[ $ip ] = ( isset( $targets[ $ip ] ) && $targets[ $ip ] ) || self::hop_ok( $hop );
			}
		}

		return $targets;
	}

	/**
	 * Builds one row per gateway egress IP.
	 *
	 * @param array    $egress Egress entries.
	 * @param string[] $stray  Target IPs already blamed on DNS.
	 * @return array[]
	 */
	private static function egress_rows( array $egress, array $stray ) {
		$targets = self::target_outcomes( $egress );
		$rows    = array();

		foreach ( array_values( $egress ) as $i => $entry ) {
			$ip    = self::valid_ip( isset( $entry['ip'] ) ? $entry['ip'] : '' );
			$label = '' !== $ip
				/* translators: %s: gateway IP address. */
				? sprintf( __( 'Gateway IP %s', 'miniorange-secure-mcp-server' ), $ip )
				: __( 'Gateway (default route)', 'miniorange-secure-mcp-server' );
			$meta  = array( 'ip' => $ip );

			if ( isset( $entry['probed'] ) && false === $entry['probed'] ) {
				$rows[] = self::row( 'gateway_egress_' . $i, $label, Response_Classifier::verdict( Response_Classifier::SKIP, 'not_probed', __( 'This IP was not probed individually. Allowlist it anyway.', 'miniorange-secure-mcp-server' ) ), $meta );
				continue;
			}

			$egress_specific = null;
			$any_failed      = null;
			$answered        = 0;
			foreach ( isset( $entry['hops'] ) ? (array) $entry['hops'] : array() as $hop ) {
				if ( self::hop_ok( $hop ) ) {
					++$answered;
					continue;
				}
				// No route from this egress (for example no IPv6) says nothing about the site.
				if ( self::hop_unreachable( $hop ) ) {
					continue;
				}
				$target = self::valid_ip( isset( $hop['target_ip'] ) ? $hop['target_ip'] : '' );
				if ( in_array( $target, $stray, true ) ) {
					continue;
				}
				$any_failed = null === $any_failed ? $hop : $any_failed;
				if ( ! empty( $targets[ $target ] ) && null === $egress_specific ) {
					$egress_specific = $hop;
				}
			}

			if ( null !== $egress_specific ) {
				$cause = self::hop_verdict( $egress_specific );
				/* translators: %s: cause of the block. */
				$detail = sprintf( __( 'Requests from this gateway IP are blocked while other gateway IPs get through. %s', 'miniorange-secure-mcp-server' ), $cause['detail'] );
				$rows[] = self::row( 'gateway_egress_' . $i, $label, Response_Classifier::verdict( Response_Classifier::FAIL, 'egress_blocked', $detail ), array_merge( $meta, array( 'cause' => $cause['code'] ) ) );
			} elseif ( null !== $any_failed ) {
				$rows[] = self::row( 'gateway_egress_' . $i, $label, self::hop_verdict( $any_failed ), $meta );
			} elseif ( 0 === $answered ) {
				$rows[] = self::row( 'gateway_egress_' . $i, $label, Response_Classifier::verdict( Response_Classifier::SKIP, 'not_probed', __( 'This gateway IP had no network route to the site, so it could not be tested.', 'miniorange-secure-mcp-server' ) ), $meta );
			} else {
				$rows[] = self::row( 'gateway_egress_' . $i, $label, Response_Classifier::verdict( Response_Classifier::PASS, 'ok', __( 'The site answered this gateway IP.', 'miniorange-secure-mcp-server' ) ), $meta );
			}
		}

		return $rows;
	}

	/**
	 * Whether a hop reached this plugin.
	 *
	 * @param array $hop One probe hop.
	 * @return bool
	 */
	private static function hop_ok( array $hop ) {
		return isset( $hop['http_status'] ) && 200 === (int) $hop['http_status'] && ! empty( $hop['is_mosmcp'] ) && empty( $hop['error'] );
	}

	/**
	 * Whether a hop failed only because the gateway had no network route to the target.
	 *
	 * @param array $hop One probe hop.
	 * @return bool
	 */
	private static function hop_unreachable( array $hop ) {
		return isset( $hop['error'] ) && 'unreachable' === $hop['error'];
	}

	/**
	 * Returns a value only when it is a literal IP address; anything else becomes ''.
	 *
	 * @param mixed $value Candidate address from the gateway.
	 * @return string
	 */
	private static function valid_ip( $value ) {
		return ( is_string( $value ) && false !== filter_var( $value, FILTER_VALIDATE_IP ) ) ? $value : '';
	}

	/**
	 * Explains why a hop failed.
	 *
	 * @param array $hop One probe hop.
	 * @return array
	 */
	private static function hop_verdict( array $hop ) {
		$tls = isset( $hop['tls']['status'] ) ? (string) $hop['tls']['status'] : 'ok';
		if ( ! in_array( $tls, array( 'ok', 'none' ), true ) ) {
			return Response_Classifier::common_failure( self::synthesize( '', 0, 'tls' ) );
		}

		$http  = isset( $hop['http_status'] ) ? (int) $hop['http_status'] : 0;
		$print = isset( $hop['fingerprint'] ) ? (string) $hop['fingerprint'] : '';
		$kind  = isset( $hop['error'] ) ? (string) $hop['error'] : '';

		$verdict = Response_Classifier::common_failure( self::synthesize( $print, $http, $kind ) );
		if ( null !== $verdict ) {
			return $verdict;
		}

		return Response_Classifier::verdict( Response_Classifier::FAIL, 'not_this_site', __( 'This address answered, but not with this site.', 'miniorange-secure-mcp-server' ) );
	}

	/**
	 * Builds the authenticated-discovery row.
	 *
	 * @param array $discovery Gateway discovery report.
	 * @param array $ctx       Evaluation context.
	 * @return array
	 */
	private static function discovery_row( array $discovery, array $ctx ) {
		$label  = __( 'Abilities the gateway discovers', 'miniorange-secure-mcp-server' );
		$status = isset( $discovery['status'] ) ? (string) $discovery['status'] : 'skipped';

		if ( empty( $ctx['discovery_requested'] ) || 'skipped' === $status ) {
			return self::row( 'gateway_discovery', $label, Response_Classifier::verdict( Response_Classifier::SKIP, 'not_reached', __( 'Skipped: the gateway did not run an authenticated discovery.', 'miniorange-secure-mcp-server' ) ) );
		}

		if ( 'auth_failed' === $status ) {
			return self::row( 'gateway_discovery', $label, Response_Classifier::verdict( Response_Classifier::FAIL, 'gateway_auth_failed', __( 'The gateway reached the site, but the site rejected a valid test token. Usually the site advertises a different address (http instead of https, or another host) than the one the gateway calls, or the Authorization header is dropped on that path.', 'miniorange-secure-mcp-server' ) ) );
		}

		if ( 'ok' !== $status ) {
			$verdict = self::verdict_for_check(
				array(
					'status'      => 'fail',
					'http_status' => isset( $discovery['http_status'] ) ? $discovery['http_status'] : 0,
					'fingerprint' => isset( $discovery['fingerprint'] ) ? $discovery['fingerprint'] : '',
					'error_kind'  => isset( $discovery['error_kind'] ) ? $discovery['error_kind'] : '',
				),
				'gateway_discovery'
			);
			return self::row( 'gateway_discovery', $label, $verdict );
		}

		$seen = isset( $discovery['tool_count'] ) ? (int) $discovery['tool_count'] : null;

		return self::row(
			'gateway_discovery',
			$label,
			Setup_Evaluator::evaluate_count( (int) $ctx['expected_count'], $seen ),
			array(
				'seen'     => $seen,
				'expected' => (int) $ctx['expected_count'],
			)
		);
	}

	/**
	 * Builds one row per gateway-linked client.
	 *
	 * @param array $clients Gateway token-health entries.
	 * @param int   $now     Current Unix time.
	 * @return array[]
	 */
	private static function token_rows( array $clients, $now ) {
		$rows = array();

		foreach ( array_values( $clients ) as $i => $c ) {
			$name = ! empty( $c['client_label'] ) ? (string) $c['client_label'] : __( 'Unnamed client', 'miniorange-secure-mcp-server' );
			$when = ! empty( $c['last_refresh_at'] ) ? self::human_age( $now - (int) $c['last_refresh_at'] ) : '';

			if ( isset( $c['status'] ) && 'reauth_required' === $c['status'] ) {
				$verdict = Response_Classifier::verdict( Response_Classifier::FAIL, 'token_reauth', __( 'The refresh token is dead, so this client must reconnect from the AI app.', 'miniorange-secure-mcp-server' ) );
			} elseif ( isset( $c['last_refresh_result'] ) && 'failed' === $c['last_refresh_result'] ) {
				/* translators: %s: how long ago the refresh failed. */
				$verdict = Response_Classifier::verdict( Response_Classifier::FAIL, 'token_refresh_failed', sprintf( __( 'The last token refresh failed (%s).', 'miniorange-secure-mcp-server' ), $when ) );
			} elseif ( '' !== $when ) {
				/* translators: %s: how long ago the token was refreshed. */
				$verdict = Response_Classifier::verdict( Response_Classifier::PASS, 'ok', sprintf( __( 'Last refresh succeeded %s.', 'miniorange-secure-mcp-server' ), $when ) );
			} else {
				$verdict = Response_Classifier::verdict( Response_Classifier::PASS, 'ok', __( 'No refresh needed yet.', 'miniorange-secure-mcp-server' ) );
			}

			/* translators: %s: AI client name such as Claude or ChatGPT. */
			$rows[] = self::row( 'gateway_token_' . $i, sprintf( __( 'Gateway sign-in: %s', 'miniorange-secure-mcp-server' ), $name ), $verdict );
		}

		return $rows;
	}

	/**
	 * Builds a display row from a verdict.
	 *
	 * @param string $id      Row id.
	 * @param string $label   Row label.
	 * @param array  $verdict Verdict.
	 * @param array  $extra   Extra fields (http_status, duration_ms) or meta values.
	 * @return array
	 */
	private static function row( $id, $label, array $verdict, array $extra = array() ) {
		$row = array(
			'id'          => $id,
			'group'       => 'gateway',
			'label'       => $label,
			'status'      => $verdict['status'],
			'code'        => $verdict['code'],
			'detail'      => $verdict['detail'],
			'http_status' => isset( $extra['http_status'] ) ? $extra['http_status'] : null,
			'duration_ms' => isset( $extra['duration_ms'] ) ? $extra['duration_ms'] : null,
			'meta'        => array_diff_key( $extra, array_flip( array( 'http_status', 'duration_ms' ) ) ),
		);

		return $row;
	}
}
