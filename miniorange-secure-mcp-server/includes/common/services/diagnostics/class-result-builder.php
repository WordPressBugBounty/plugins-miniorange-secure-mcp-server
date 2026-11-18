<?php
/**
 * Assembles check rows into the final connection-test result and the support bundle.
 *
 * @package Miniorange_Secure_MCP_Server
 */

namespace MoSMCP\Common\Services\Diagnostics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Result_Builder
 *
 * Pure: takes rows from the local checks and the gateway evaluator and produces the one
 * pass/warn/fail/skipped list, the de-duplicated fixes, and a redacted "Copy diagnostics" text.
 */
class Result_Builder {

	/**
	 * Result shape version, so a cached result from an older plugin is ignored rather than misread.
	 */
	const SCHEMA = 2;

	/**
	 * Gateway states a run can end in.
	 */
	const GATEWAY_OK           = 'ok';
	const GATEWAY_UNREACHABLE  = 'unreachable';
	const GATEWAY_RATE_LIMITED = 'rate_limited';

	/**
	 * Builds the result.
	 *
	 * @param array[] $rows Rows from local checks then gateway evaluation, in display order.
	 * @param array   $meta {gateway_state:string, retry_after:int|null, allowlist_ips:string[], stray_ips:string[], env:array, site_url:string, expected_count:int, ran_at:int, secrets:string[]}.
	 * @return array
	 */
	public static function build( array $rows, array $meta ) {
		$meta = array_merge(
			array(
				'gateway_state'  => self::GATEWAY_OK,
				'retry_after'    => null,
				'gateway_reason' => '',
				'allowlist_ips'  => array(),
				'stray_ips'      => array(),
				'env'            => array(),
				'site_url'       => '',
				'expected_count' => 0,
				'ran_at'         => 0,
				'secrets'        => array(),
			),
			$meta
		);

		$state_row = self::gateway_state_row( $meta['gateway_state'], $meta['retry_after'], $meta['gateway_reason'] );
		if ( null !== $state_row ) {
			$rows[] = $state_row;
		}

		$rows    = Customer_View::apply( self::plain_labels( $rows ) );
		$visible = Customer_View::visible( $rows );

		$summary = array(
			'pass'    => 0,
			'warn'    => 0,
			'fail'    => 0,
			'skipped' => 0,
		);
		foreach ( $visible as $row ) {
			if ( isset( $summary[ $row['status'] ] ) ) {
				++$summary[ $row['status'] ];
			}
		}

		$env   = $meta['env'];
		$fixes = Fix_Catalog::for_rows(
			$visible,
			array(
				'auth_fix'          => isset( $env['auth_fix'] ) ? $env['auth_fix'] : 'host_only',
				'server'            => isset( $env['server'] ) ? $env['server'] : 'unknown',
				'sapi'              => isset( $env['sapi'] ) ? $env['sapi'] : '',
				'layers'            => isset( $env['layers'] ) ? $env['layers'] : array(),
				'egress_ips'        => $meta['allowlist_ips'],
				'stray_ips'         => $meta['stray_ips'],
				'htaccess_has_rule' => ! empty( $env['htaccess_has_rule'] ),
			)
		);

		$result = array(
			'schema'         => self::SCHEMA,
			'ran_at'         => (int) $meta['ran_at'],
			'status'         => self::overall( $summary, $meta['gateway_state'] ),
			'summary'        => $summary,
			'gateway'        => array(
				'state'       => $meta['gateway_state'],
				'retry_after' => $meta['retry_after'],
			),
			'checks'         => $rows,
			'failed_checks'  => self::failed_ids( $visible ),
			'fixes'          => $fixes,
			'allowlist_ips'  => $meta['allowlist_ips'],
			'expected_count' => (int) $meta['expected_count'],
			'environment'    => $env,
		);

		$result = Redactor::redact_array( $result, $meta['secrets'] );

		$result['bundle'] = self::bundle( $result, (string) $meta['site_url'], $meta['secrets'] );

		return $result;
	}

	/**
	 * Renders the redacted text a user pastes into a support request.
	 *
	 * @param array    $result  Result built so far (already redacted).
	 * @param string   $site_url The site URL.
	 * @param string[] $secrets  Exact values to scrub again, as a second pass.
	 * @return string
	 */
	public static function bundle( array $result, $site_url, array $secrets = array() ) {
		$checks = array();
		foreach ( $result['checks'] as $row ) {
			$checks[] = array(
				'id'          => $row['id'],
				'group'       => $row['group'],
				'status'      => $row['status'],
				'code'        => $row['code'],
				'label'       => $row['label'],
				'detail'      => $row['detail'],
				'http_status' => $row['http_status'],
				'duration_ms' => $row['duration_ms'],
				'meta'        => $row['meta'],
				// Whether the customer saw this row; support sees all of them.
				'shown_to_customer' => ! empty( $row['shown'] ),
			);
		}

		$payload = array(
			'generated_at_unix'     => $result['ran_at'],
			'site_url'              => $site_url,
			'overall_status'        => $result['status'],
			'summary'               => $result['summary'],
			'environment'           => $result['environment'],
			'expected_ability_count' => $result['expected_count'],
			'gateway'               => array(
				'state'         => $result['gateway']['state'],
				'egress_ips'    => $result['allowlist_ips'],
			),
			'recommended_fixes'     => array_column( $result['fixes'], 'id' ),
			'checks'                => $checks,
		);

		$payload = Redactor::redact_array( $payload, $secrets );

		return "Secure MCP Server connection diagnostics (secrets removed)\n" . json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * Swaps technical check names for plain ones, keeping the original as `technical` for support.
	 *
	 * @param array[] $rows Rows.
	 * @return array[]
	 */
	private static function plain_labels( array $rows ) {
		$plain = array(
			'plugin_tables'                  => __( 'Plugin data is set up', 'miniorange-secure-mcp-server' ),
			'schema_version'                 => __( 'Plugin data is up to date', 'miniorange-secure-mcp-server' ),
			'agent_enabled'                  => __( 'An AI agent is switched on', 'miniorange-secure-mcp-server' ),
			'agent_grants'                   => __( 'The agent has abilities to share', 'miniorange-secure-mcp-server' ),
			'site_https'                     => __( 'Your site uses https', 'miniorange-secure-mcp-server' ),
			'site_hosts'                     => __( 'Your site address is consistent', 'miniorange-secure-mcp-server' ),
			'site_certificate'               => __( 'Your security certificate is valid', 'miniorange-secure-mcp-server' ),
			'mcp_health'                     => __( 'Your site\'s AI address can be reached', 'miniorange-secure-mcp-server' ),
			'scheme_host_consistency'        => __( 'Your site\'s addresses all match', 'miniorange-secure-mcp-server' ),
			'mcp_json_passthrough'           => __( 'Your site answers AI requests', 'miniorange-secure-mcp-server' ),
			'auth_header_passthrough'        => __( 'The AI\'s sign-in reaches WordPress', 'miniorange-secure-mcp-server' ),
			'oauth_registration_endpoint'    => __( 'AI apps can register with your site', 'miniorange-secure-mcp-server' ),
			'oauth_token_endpoint'           => __( 'AI apps can get access', 'miniorange-secure-mcp-server' ),
			'oauth_authorization_endpoint'   => __( 'The approval page works', 'miniorange-secure-mcp-server' ),
			'environment'                    => __( 'Your server setup', 'miniorange-secure-mcp-server' ),
			'gateway_dns'                    => __( 'Your domain points at the right server', 'miniorange-secure-mcp-server' ),
			'gateway_discovery'              => __( 'The AI sees the right abilities', 'miniorange-secure-mcp-server' ),
			'gateway_reachable'              => __( 'Test from outside your server', 'miniorange-secure-mcp-server' ),
		);

		foreach ( $rows as $i => $row ) {
			if ( isset( $plain[ $row['id'] ] ) ) {
				$rows[ $i ]['technical'] = $row['label'];
				$rows[ $i ]['label']     = $plain[ $row['id'] ];
			}
		}

		return $rows;
	}

	/**
	 * Row describing a gateway that could not complete the probe, or null when it did.
	 *
	 * @param string   $state       Gateway state.
	 * @param int|null $retry_after Seconds until the gateway allows another run.
	 * @return array|null
	 */
	private static function gateway_state_row( $state, $retry_after, $reason = '' ) {
		$label = __( 'Test from outside (gateway)', 'miniorange-secure-mcp-server' );

		if ( self::GATEWAY_RATE_LIMITED === $state ) {
			$detail = $retry_after
				/* translators: %d: seconds. */
				? sprintf( __( 'The gateway limits how often this test can run. Try again in about %d seconds.', 'miniorange-secure-mcp-server' ), (int) $retry_after )
				: __( 'The gateway limits how often this test can run. Try again in a minute.', 'miniorange-secure-mcp-server' );
			return self::state_row( $label, Response_Classifier::verdict( Response_Classifier::SKIP, 'gateway_rate_limited', $detail ) );
		}

		if ( self::GATEWAY_UNREACHABLE === $state ) {
			return self::state_row( $label, Response_Classifier::verdict( Response_Classifier::WARN, 'gateway_unreachable', self::unreachable_detail( $reason ) ) );
		}

		return null;
	}

	/**
	 * Says why the gateway could not be used, so "blocked outgoing traffic" is not blamed for a gateway-side problem.
	 *
	 * @param string $reason transport, bad_response, http_<code>, or '' when unknown.
	 * @return string
	 */
	private static function unreachable_detail( $reason ) {
		if ( 'transport' === $reason ) {
			return __( 'This server could not open a connection to the miniOrange gateway (a firewall or network setting may be blocking outgoing HTTPS), so the view from outside could not be tested.', 'miniorange-secure-mcp-server' );
		}

		if ( 0 === strpos( (string) $reason, 'http_' ) ) {
			/* translators: %s: HTTP status code returned by the gateway. */
			return sprintf( __( 'The miniOrange gateway answered with an error (HTTP %s), so the view from outside could not be tested. This is on the gateway side, not your server.', 'miniorange-secure-mcp-server' ), substr( $reason, 5 ) );
		}

		return __( 'The miniOrange gateway gave an answer this plugin could not read, so the view from outside could not be tested.', 'miniorange-secure-mcp-server' );
	}
	/**
	 * Builds the gateway-state row.
	 *
	 * @param string $label   Row label.
	 * @param array  $verdict Verdict.
	 * @return array
	 */
	private static function state_row( $label, array $verdict ) {
		return array(
			'id'          => 'gateway_reachable',
			'group'       => 'gateway',
			'label'       => $label,
			'status'      => $verdict['status'],
			'code'        => $verdict['code'],
			'detail'      => $verdict['detail'],
			'http_status' => null,
			'duration_ms' => null,
			'meta'        => array(),
		);
	}

	/**
	 * Overall verdict from what the customer sees: a problem wins, and the outside test not running is only a note.
	 *
	 * @param array  $summary       Counts per status.
	 * @param string $gateway_state Gateway state.
	 * @return string ok|warn|issue|unavailable|rate_limited
	 */
	private static function overall( array $summary, $gateway_state ) {
		if ( $summary['fail'] > 0 ) {
			return 'issue';
		}
		if ( $summary['warn'] > 0 ) {
			return 'warn';
		}
		if ( self::GATEWAY_RATE_LIMITED === $gateway_state ) {
			return 'rate_limited';
		}

		return self::GATEWAY_UNREACHABLE === $gateway_state ? 'unavailable' : 'ok';
	}

	/**
	 * Row ids that failed or warned, for opening the matching troubleshooting entry.
	 *
	 * @param array[] $rows Rows.
	 * @return string[]
	 */
	private static function failed_ids( array $rows ) {
		$ids = array();
		foreach ( $rows as $row ) {
			if ( in_array( $row['status'], array( Response_Classifier::FAIL, Response_Classifier::WARN ), true ) ) {
				$ids[] = $row['id'];
			}
		}

		return array_values( array_unique( $ids ) );
	}
}
