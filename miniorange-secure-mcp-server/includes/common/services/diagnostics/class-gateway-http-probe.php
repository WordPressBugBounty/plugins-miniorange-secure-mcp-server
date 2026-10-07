<?php
/**
 * Calls the miniOrange gateway's /diagnostics probe over HTTPS.
 *
 * @package Miniorange_Secure_MCP_Server
 */

namespace MoSMCP\Common\Services\Diagnostics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Gateway_Http_Probe
 *
 * The only outbound call a connection test makes besides the site's own loopback.
 */
class Gateway_Http_Probe implements Gateway_Probe {

	/**
	 * The gateway's external-prober endpoint (same host as the "Connect via Gateway" URL).
	 */
	const URL = 'https://gateway.miniorange.ai/diagnostics';

	/**
	 * Request timeout, in seconds. The gateway bounds its own fan-out well below this.
	 */
	const TIMEOUT = 30;

	/**
	 * Largest response accepted, in bytes.
	 */
	const MAX_BYTES = 262144;

	/**
	 * Protocol version this client speaks; a gateway that predates it answers without the new fields.
	 */
	const PROBE_VERSION = 2;

	/**
	 * Asks the gateway to probe the site.
	 *
	 * @param array $request {site_url:string, test_token?:string, expected_ability_count?:int}.
	 * @return array{state:string, retry_after:int|null, report:array}
	 */
	public function probe( array $request ) {
		$response = $this->post( array_merge( $request, array( 'probe_version' => self::PROBE_VERSION ) ) );

		// A gateway that predates probe_version rejects unknown fields (422), so ask the way it understands.
		if ( ! is_wp_error( $response ) && 422 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$response = $this->post( array( 'site_url' => $request['site_url'] ) );
		}

		if ( is_wp_error( $response ) ) {
			return $this->result( Result_Builder::GATEWAY_UNREACHABLE, array(), null, 'transport' );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 429 === $code ) {
			$retry_after = wp_remote_retrieve_header( $response, 'retry-after' );
			return $this->result( Result_Builder::GATEWAY_RATE_LIMITED, array(), is_numeric( $retry_after ) ? (int) $retry_after : null );
		}

		$data = ( 200 === $code ) ? json_decode( wp_remote_retrieve_body( $response ), true ) : null;
		if ( ! is_array( $data ) || ! isset( $data['checks'] ) || ! is_array( $data['checks'] ) ) {
			return $this->result( Result_Builder::GATEWAY_UNREACHABLE, array(), null, 200 === $code ? 'bad_response' : 'http_' . $code );
		}

		return $this->result( Result_Builder::GATEWAY_OK, $data );
	}

	/**
	 * Sends one request to the gateway.
	 *
	 * @param array $payload JSON body.
	 * @return array|\WP_Error
	 */
	private function post( array $payload ) {
		return wp_remote_post(
			self::URL,
			array(
				'timeout'             => self::TIMEOUT,
				'redirection'         => 0,
				'limit_response_size' => self::MAX_BYTES,
				'headers'             => array( 'Content-Type' => 'application/json' ),
				'body'                => wp_json_encode( $payload ),
			)
		);
	}

	/**
	 * Builds the probe result.
	 *
	 * @param string   $state       Gateway state.
	 * @param array    $report      Decoded gateway report.
	 * @param int|null $retry_after Seconds until another run is allowed.
	 * @param string   $reason      Why the gateway counted as unreachable: transport, bad_response or http_<code>.
	 * @return array{state:string, retry_after:int|null, report:array, reason:string}
	 */
	private function result( $state, array $report = array(), $retry_after = null, $reason = '' ) {
		return array(
			'state'       => $state,
			'retry_after' => $retry_after,
			'report'      => $report,
			'reason'      => $reason,
		);
	}
}
