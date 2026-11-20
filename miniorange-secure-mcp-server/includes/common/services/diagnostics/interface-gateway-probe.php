<?php
/**
 * Contract for asking the gateway to test this site from outside.
 *
 * @package Miniorange_Secure_MCP_Server
 */

namespace MoSMCP\Common\Services\Diagnostics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface Gateway_Probe
 *
 * The seam between the plugin and the gateway: production uses {@see Gateway_Http_Probe}, tests
 * inject a fake.
 */
interface Gateway_Probe {

	/**
	 * Asks the gateway to probe the site.
	 *
	 * @param array $request {site_url:string, test_token?:string, expected_ability_count?:int}.
	 * @return array{state:string, retry_after:int|null, report:array, reason?:string} State is one of the Result_Builder::GATEWAY_* constants.
	 */
	public function probe( array $request );
}
