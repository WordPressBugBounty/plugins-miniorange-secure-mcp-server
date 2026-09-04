<?php
/**
 * Logging service: records MCP tool-call events to the audit log table.
 *
 * @package Miniorange_Secure_MCP_Server
 */

namespace MoSMCP\Common\Services\Logging;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MoSMCP\Common\Repositories\Audit_Store;
use MoSMCP\Common\Repositories\NHI_Store;

/**
 * Class Audit_Logger
 *
 * Called once per request from {@see \MoSMCP\Common\Controllers\MCP\REST_Controller::authenticate()}
 * to set the request context (user, client, IP), and then from
 * {@see \MoSMCP\Common\Services\MCP\MCP_Server::call_tool()} for each tools/call.
 */
class Audit_Logger {

	/**
	 * Per-request context set during bearer-token authentication.
	 *
	 * @var array{user_id: int, client_id: string, client_name: string, ip: string, nhi_id: int|null, nhi_uuid: string, nhi_name: string}
	 */
	private static $context = array(
		'user_id'     => 0,
		'client_id'   => '',
		'client_name' => '',
		'ip'          => '',
		'nhi_id'      => null,
		'nhi_uuid'    => '',
		'nhi_name'    => '',
	);

	/**
	 * Stores the authenticated request context so record_tool_call() can use it.
	 *
	 * Accepted keys: user_id (int), client_id (string), client_name (string), ip (string).
	 *
	 * @param array<string, mixed> $ctx Context values.
	 * @return void
	 */
	public static function set_context( array $ctx ) {
		foreach ( array_keys( self::$context ) as $key ) {
			if ( array_key_exists( $key, $ctx ) ) {
				self::$context[ $key ] = $ctx[ $key ];
			}
		}
	}

	/**
	 * Writes a tools/call audit entry.
	 *
	 * Called after every tools/call dispatch, whether the call succeeded, failed,
	 * or was denied (unknown tool / not in allowed-ability list).
	 *
	 * @param string      $ability_name  Ability name with `/` separator (e.g. mosmcp/post-create-draft).
	 *                                   For unknown tools, the best-effort decoded tool name.
	 * @param string      $status        One of 'success', 'failed', 'denied'.
	 * @param int|null    $latency_ms    Wall-clock execution time in milliseconds, or null.
	 * @param string|null $error_code   WP_Error code on failure, or null.
	 * @param string|null $error_message Human-readable error detail, or null.
	 * @param mixed       $request_id    The JSON-RPC id from the call, or null for notifications.
	 * @return void
	 */
	public static function record_tool_call( $ability_name, $status, $latency_ms, $error_code, $error_message, $request_id ) {
		// Resolve the NHI that owns this ability for the authenticated user's roles.
		// Role-scoped lookup is needed because the same ability may exist under different
		// roles in different NHIs; only the NHI that granted this user's role matters.
		$user_roles = array();
		$userdata   = self::$context['user_id'] ? get_userdata( (int) self::$context['user_id'] ) : false;
		if ( $userdata ) {
			$user_roles = (array) $userdata->roles;
		}
		$owner = NHI_Store::owner_for_ability( (string) $ability_name, $user_roles );

		// For unknown/denied tools that aren't in any NHI's grants, fall back to the
		// primary NHI resolved at auth time so the row is still attributed to an agent.
		if ( ! $owner && self::$context['nhi_id'] ) {
			$owner = array(
				'id'   => self::$context['nhi_id'],
				'uuid' => self::$context['nhi_uuid'],
				'name' => self::$context['nhi_name'],
			);
		}

		$row = array(
			'user_id'       => (int) self::$context['user_id'],
			'user_login'    => $userdata ? (string) $userdata->user_login : '',
			'nhi_id'        => $owner ? (int) $owner['id'] : null,
			'nhi_uuid'      => $owner ? (string) $owner['uuid'] : '',
			'nhi_name'      => $owner ? (string) $owner['name'] : '',
			'client_id'     => (string) self::$context['client_id'],
			'client_name'   => (string) self::$context['client_name'],
			'tool_name'     => (string) $ability_name,
			'status'        => (string) $status,
			'error_code'    => $error_code ? (string) $error_code : null,
			'error_message' => $error_message ? (string) $error_message : null,
			'latency_ms'    => null !== $latency_ms ? (int) $latency_ms : null,
			'ip_address'    => '' !== self::$context['ip'] ? (string) self::$context['ip'] : null,
			'request_id'    => null !== $request_id ? (string) $request_id : null,
		);

		Audit_Store::insert( $row );
	}

}
