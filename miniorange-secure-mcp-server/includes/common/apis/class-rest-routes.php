<?php
/**
 * REST route registration for the MCP transport and the OAuth server.
 *
 * @package Miniorange_Secure_MCP_Server
 */

namespace MoSMCP\Common\Apis;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MoSMCP\Common\Apis\Audit\Audit_Routes;
use MoSMCP\Common\Apis\Contact\Contact_Routes;
use MoSMCP\Common\Apis\Debug\Debug_Routes;
use MoSMCP\Common\Apis\Diagnostics\Diagnostics_Routes;
use MoSMCP\Common\Apis\MCP\MCP_Routes;
use MoSMCP\Common\Apis\NHI\NHI_Routes;
use MoSMCP\Common\Apis\OAuth\OAuth_Routes;
use MoSMCP\Common\Apis\Stats\Stats_Routes;

/**
 * Class Rest_Routes
 *
 * Aggregator that delegates route registration to each resource-specific
 * routes class. Hooked to `rest_api_init` via {@see Hooks}.
 *
 * @see MCP_Routes
 * @see OAuth_Routes
 * @see Contact_Routes
 */
class Rest_Routes {

	/**
	 * Registers all REST routes by delegating to resource-specific classes.
	 *
	 * @return void
	 */
	public static function register() {
		MCP_Routes::register();
		OAuth_Routes::register();
		Contact_Routes::register();
		Stats_Routes::register();
		NHI_Routes::register();
		Audit_Routes::register();
		Debug_Routes::register();
		Diagnostics_Routes::register();
	}
}
