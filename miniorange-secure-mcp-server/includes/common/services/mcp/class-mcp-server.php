<?php
/**
 * The MCP protocol handler: JSON-RPC dispatch and Abilities-to-tools mapping.
 *
 * @package Miniorange_Secure_MCP_Server
 */

namespace MoSMCP\Common\Services\MCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MoSMCP\Common\Repositories\NHI_Store;
use MoSMCP\Common\Services\Logging\Audit_Logger;
use MoSMCP\Common\Services\Logging\Debug_Logger;
use stdClass;

/**
 * Class MCP_Server
 *
 * Translates JSON-RPC 2.0 MCP messages into Abilities API calls. Every
 * registered ability is exposed as an MCP tool; tool execution runs through
 * WP_Ability::execute(), which performs input validation, the ability's own
 * permission check, and output validation.
 */
class MCP_Server {

	/**
	 * The MCP protocol version implemented by this server.
	 */
	const PROTOCOL_VERSION = '2025-06-18';

	/**
	 * Ability names the current NHI client is allowed to use.
	 * NULL means all abilities are permitted.
	 *
	 * @var string[]|null
	 */
	private static $allowed_abilities = null;

	/**
	 * Restricts tool exposure to the given ability list for this request.
	 * Pass null to lift all restrictions (the default).
	 *
	 * @param string[]|null $allowed Ability name list, or null for unrestricted.
	 * @return void
	 */
	public static function set_allowed_abilities( $allowed ) {
		self::$allowed_abilities = is_array( $allowed ) ? array_values( $allowed ) : null;
	}

	/**
	 * Stores the authenticated request context so the audit logger can use it.
	 *
	 * Accepted keys: user_id (int), client_id (string), client_name (string), ip (string).
	 *
	 * @param array<string, mixed> $ctx Context values from the bearer-token auth step.
	 * @return void
	 */
	public static function set_request_context( array $ctx ) {
		Audit_Logger::set_context( $ctx );

		Debug_Logger::set_client_context(
			isset( $ctx['client_id'] ) ? (string) $ctx['client_id'] : '',
			isset( $ctx['client_name'] ) ? (string) $ctx['client_name'] : ''
		);
	}

	/**
	 * Dispatches a single decoded JSON-RPC message.
	 *
	 * @param mixed $message The decoded request (associative array expected).
	 * @return array<string, mixed>|null A JSON-RPC response, or null for notifications.
	 */
	public static function dispatch( $message ) {
		if ( ! is_array( $message ) || ! isset( $message['method'] ) || ! is_string( $message['method'] ) ) {
			return self::error_response( null, -32600, __( 'Invalid Request.', 'miniorange-secure-mcp-server' ) );
		}

		$method          = $message['method'];
		$params          = isset( $message['params'] ) && is_array( $message['params'] ) ? $message['params'] : array();
		$is_notification = ! array_key_exists( 'id', $message );
		$id              = $is_notification ? null : $message['id'];

		if ( NHI_Store::count_enabled() === 0 ) {
			return self::error_response(
				$id,
				-32001,
				'No NHI is enabled. Create or enable an NHI to allow MCP access: ' . admin_url( 'admin.php?page=mosmcp-abilities' ) . '#/nhi'
			);
		}

		if ( $is_notification ) {
			return null;
		}

		switch ( $method ) {
			case 'initialize':
				return self::success_response( $id, self::initialize_result() );

			case 'ping':
				return self::success_response( $id, new stdClass() );

			case 'tools/list':
				return self::success_response( $id, self::list_tools() );

			case 'tools/call':
				return self::call_tool( $id, $params );

			default:
				return self::error_response( $id, -32601, __( 'Method not found.', 'miniorange-secure-mcp-server' ) );
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function initialize_result() {
		return array(
			'protocolVersion' => self::PROTOCOL_VERSION,
			'capabilities'    => array(
				'tools' => array( 'listChanged' => false ),
			),
			'serverInfo'      => array(
				'name'    => 'miniOrange Secure MCP Server',
				'version' => MOSMCP_VERSION,
			),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function list_tools() {
		$tools = array();

		foreach ( self::exposed_abilities() as $ability ) {
			$tools[] = self::ability_to_tool( $ability );
		}

		return array( 'tools' => $tools );
	}

	/**
	 * @param mixed                $id
	 * @param array<string, mixed> $params
	 * @return array<string, mixed>
	 */
	private static function call_tool( $id, array $params ) {
		$tool_name = isset( $params['name'] ) ? (string) $params['name'] : '';
		$arguments = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();

		$abilities = self::exposed_abilities();
		$ability   = null;

		foreach ( $abilities as $candidate ) {
			if ( self::encode_name( $candidate->get_name() ) === $tool_name ) {
				$ability = $candidate;
				break;
			}
		}

		if ( null === $ability ) {
			// Log the denied call: best-effort decode the encoded name back to ability form.
			$decoded_name = str_replace( '__', '/', $tool_name );

			Audit_Logger::record_tool_call(
				$decoded_name,
				'denied',
				0,
				'unknown_tool',
				__( 'Unknown tool.', 'miniorange-secure-mcp-server' ),
				$id
			);

			Debug_Logger::warning(
				Debug_Logger::CHANNEL_MCP,
				sprintf( 'Denied tools/call for unknown tool "%s".', $decoded_name ),
				array(
					'tool_name'     => $decoded_name,
					'raw_tool_name' => $tool_name,
					'json_rpc_id'   => $id,
				)
			);

			return self::error_response( $id, -32602, __( 'Unknown tool.', 'miniorange-secure-mcp-server' ) );
		}

		$input = array_key_exists( 'input', $arguments ) ? $arguments['input'] : null;

		// Execute and measure wall-clock latency.
		$start_us = microtime( true );
		$result   = $ability->execute( $input );
		$latency  = (int) round( ( microtime( true ) - $start_us ) * 1000.0 );

		if ( is_wp_error( $result ) ) {
			Audit_Logger::record_tool_call(
				$ability->get_name(),
				'failed',
				$latency,
				$result->get_error_code(),
				$result->get_error_message(),
				$id
			);

			Debug_Logger::error(
				Debug_Logger::CHANNEL_MCP,
				sprintf( 'Tool call "%s" failed: %s', $ability->get_name(), $result->get_error_message() ),
				array(
					'tool_name'   => $ability->get_name(),
					'error_code'  => $result->get_error_code(),
					'error_data'  => $result->get_error_data(),
					'latency_ms'  => $latency,
					'input_keys'  => is_array( $input ) ? array_keys( $input ) : gettype( $input ),
					'json_rpc_id' => $id,
				)
			);

			return self::success_response(
				$id,
				array(
					'content' => array(
						array(
							'type' => 'text',
							'text' => $result->get_error_message(),
						),
					),
					'isError' => true,
				)
			);
		}

		Audit_Logger::record_tool_call(
			$ability->get_name(),
			'success',
			$latency,
			null,
			null,
			$id
		);

		Debug_Logger::debug(
			Debug_Logger::CHANNEL_MCP,
			sprintf( 'Tool call "%s" succeeded in %dms.', $ability->get_name(), $latency ),
			array(
				'tool_name'   => $ability->get_name(),
				'latency_ms'  => $latency,
				'input_keys'  => is_array( $input ) ? array_keys( $input ) : gettype( $input ),
				'json_rpc_id' => $id,
			)
		);

		$text = is_string( $result ) ? $result : (string) wp_json_encode( $result );

		$tool_result = array(
			'content' => array(
				array(
					'type' => 'text',
					'text' => $text,
				),
			),
			'isError' => false,
		);

		if ( is_array( $result ) ) {
			$tool_result['structuredContent'] = $result;
		}

		return self::success_response( $id, $tool_result );
	}

	/**
	 * @return \WP_Ability[]
	 */
	private static function exposed_abilities() {
		$abilities = function_exists( 'wp_get_abilities' ) ? wp_get_abilities() : array();

		/** @param \WP_Ability[] $abilities */
		$abilities = apply_filters( 'mosmcp_exposed_abilities', $abilities );

		if ( ! is_array( $abilities ) ) {
			return array();
		}

		// Filter to the per-NHI allow-list when one is set (null = unrestricted).
		if ( null !== self::$allowed_abilities ) {
			$allowed   = self::$allowed_abilities;
			$abilities = array_values(
				array_filter(
					$abilities,
					function ( $ability ) use ( $allowed ) {
						return in_array( $ability->get_name(), $allowed, true );
					}
				)
			);
		}

		$abilities = self::apply_prefix_trim( $abilities );

		return $abilities;
	}

	/**
	 * Optional install-level trim: keep only abilities whose action segment starts
	 * with one of the prefixes named in the MOSMCP_EXPOSED_PREFIXES constant
	 * (comma-separated, e.g. "kadence-,page-,media-,attachment-,image-").
	 *
	 * Default (constant absent or empty) is no trim, so this is fully backward
	 * compatible. Its purpose is to keep the exposed tool count under a connector's
	 * callable-tool cap when the full ability set is too large for a client to bind.
	 *
	 * @param \WP_Ability[] $abilities Abilities after per-NHI filtering.
	 * @return \WP_Ability[]
	 */
	private static function apply_prefix_trim( $abilities ) {
		if ( ! defined( 'MOSMCP_EXPOSED_PREFIXES' ) || '' === trim( (string) MOSMCP_EXPOSED_PREFIXES ) ) {
			return $abilities;
		}

		$prefixes = array_filter( array_map( 'trim', explode( ',', (string) MOSMCP_EXPOSED_PREFIXES ) ) );
		if ( empty( $prefixes ) ) {
			return $abilities;
		}

		return array_values(
			array_filter(
				$abilities,
				function ( $ability ) use ( $prefixes ) {
					$name = (string) $ability->get_name();
					$slug = false !== strpos( $name, '/' ) ? substr( strstr( $name, '/' ), 1 ) : $name;
					foreach ( $prefixes as $prefix ) {
						if ( 0 === strpos( $slug, $prefix ) ) {
							return true;
						}
					}
					return false;
				}
			)
		);
	}

	/**
	 * @param \WP_Ability $ability
	 * @return array<string, mixed>
	 */
	private static function ability_to_tool( $ability ) {
		$name = (string) $ability->get_name();

		$tool = array(
			'name'        => self::encode_name( $name ),
			'title'       => $ability->get_label(),
			'description' => $ability->get_description(),
			'inputSchema' => self::wrap_input_schema( $ability->get_input_schema() ),
		);

		// Emit behavioral hints only for abilities we can vouch for — WordPress core
		// and this plugin's own abilities. For those, all four hints are always set as
		// booleans (never null). Third-party abilities are left untouched: we don't
		// guess hints on their behalf (their author can declare their own annotations).
		$namespace = strtolower( (string) strstr( $name, '/', true ) );
		if ( in_array( $namespace, array( 'core', 'mosmcp' ), true ) ) {
			$tool['annotations'] = self::map_annotations( $ability );
		}

		return $tool;
	}

	/**
	 * @param array $schema
	 * @return array<string, mixed>
	 */
	private static function wrap_input_schema( $schema ) {
		if ( empty( $schema ) || ! is_array( $schema ) ) {
			return array(
				'type'       => 'object',
				'properties' => new stdClass(),
			);
		}

		$required = false;
		if ( isset( $schema['required'] ) ) {
			$required = (bool) $schema['required'];
			unset( $schema['required'] );
		}

		$wrapper = array(
			'type'       => 'object',
			'properties' => array(
				'input' => self::normalize_schema_maps( $schema ),
			),
		);

		if ( $required ) {
			$wrapper['required'] = array( 'input' );
		}

		return $wrapper;
	}

	/**
	 * Coerces JSON Schema keywords that must serialize as objects, not arrays.
	 *
	 * PHP can't distinguish an empty map from an empty list, so an ability that
	 * legitimately declares 'properties' => array() (meaning "takes no arguments")
	 * encodes as "properties": [], which strict MCP clients reject with an invalid
	 * tool schema error. Walks the schema and swaps every empty object-valued
	 * keyword for a stdClass so json_encode() emits {} instead of [].
	 *
	 * @param mixed $schema A schema node.
	 * @return mixed The normalized node.
	 */
	private static function normalize_schema_maps( $schema ) {
		if ( ! is_array( $schema ) ) {
			return $schema;
		}

		$object_keywords = array( 'properties', 'patternProperties', 'definitions', '$defs' );

		foreach ( $schema as $key => $value ) {
			if ( in_array( $key, $object_keywords, true ) && is_array( $value ) ) {
				if ( array() === $value ) {
					$schema[ $key ] = new stdClass();
					continue;
				}

				foreach ( $value as $sub_key => $sub_schema ) {
					$value[ $sub_key ] = self::normalize_schema_maps( $sub_schema );
				}

				$schema[ $key ] = $value;
				continue;
			}

			$schema[ $key ] = self::normalize_schema_maps( $value );
		}

		return $schema;
	}

	/**
	 * Builds the MCP tool annotations for an ability.
	 *
	 * Only called for abilities we can vouch for (WordPress core + this plugin's own).
	 * Every hint is always resolved to an explicit boolean (never null/omitted), as
	 * required by MCP app reviewers. Values come from the ability's declared
	 * `annotations` meta when present, otherwise from a verb heuristic on the
	 * ability's action segment (e.g. "get" in "core/get-site-info") that matches
	 * the tool's actual behavior:
	 *
	 * - readOnlyHint:    true for read/query verbs (get, list, search, …) — no changes.
	 * - destructiveHint: true only for a non-read delete/remove verb (data loss).
	 * - idempotentHint:  true for reads and deletes (repeating reaches the same state);
	 *                    false for additive writes like create.
	 * - openWorldHint:   false — these tools act on this site's own data, not external
	 *                    services.
	 *
	 * @param \WP_Ability $ability The ability.
	 * @return array<string, mixed>
	 */
	private static function map_annotations( $ability ) {
		$source = $ability->get_meta_item( 'annotations' );
		if ( ! is_array( $source ) ) {
			$source = array();
		}

		// Action verb: the segment after the namespace slash, hyphens as spaces
		// ("core/get-site-info" -> "get site info") so we can match a leading verb.
		$name   = (string) $ability->get_name();
		$action = strtolower( ltrim( (string) strrchr( '/' . $name, '/' ), '/' ) );
		$action = str_replace( '-', ' ', $action );

		$is_read   = (bool) preg_match( '/^(get|list|search|query|read|view|fetch|find|count|show|describe|check|is|has)\b/', $action );
		$is_delete = (bool) preg_match( '/^(delete|remove|trash|destroy|drop|purge|revoke|unschedule|clear|disconnect|unblock)\b/', $action );

		$read_only   = self::annotation_bool( $source, 'readonly', $is_read );
		$destructive = self::annotation_bool( $source, 'destructive', ( ! $read_only && $is_delete ) );

		if ( isset( $source['idempotent'] ) && null !== $source['idempotent'] ) {
			$idempotent = (bool) $source['idempotent'];
		} else {
			$idempotent = ( $read_only || $destructive );
		}

		$annotations = array(
			'readOnlyHint'    => $read_only,
			'destructiveHint' => $destructive,
			'idempotentHint'  => $idempotent,
			'openWorldHint'   => self::annotation_bool( $source, 'open_world', false ),
		);

		$title = $ability->get_label();
		if ( is_string( $title ) && '' !== $title ) {
			$annotations['title'] = $title;
		}

		/** @param array<string, bool> $annotations @param \WP_Ability $ability */
		return apply_filters( 'mosmcp_tool_annotations', $annotations, $ability );
	}

	/**
	 * @param array<string, mixed> $source
	 * @param string               $key
	 * @param bool                 $fallback
	 * @return bool
	 */
	private static function annotation_bool( $source, $key, $fallback ) {
		if ( isset( $source[ $key ] ) && null !== $source[ $key ] ) {
			return (bool) $source[ $key ];
		}

		return (bool) $fallback;
	}

	/**
	 * @param string $name
	 * @return string
	 */
	private static function encode_name( $name ) {
		return str_replace( '/', '__', $name );
	}

	/**
	 * @param mixed $id
	 * @param mixed $result
	 * @return array<string, mixed>
	 */
	private static function success_response( $id, $result ) {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => $result,
		);
	}

	/**
	 * @param mixed  $id
	 * @param int    $code
	 * @param string $message
	 * @return array<string, mixed>
	 */
	private static function error_response( $id, $code, $message ) {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'error'   => array(
				'code'    => $code,
				'message' => $message,
			),
		);
	}
}
