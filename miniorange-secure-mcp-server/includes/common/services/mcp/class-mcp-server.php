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

use MoSMCP\Common\Repositories\Audit_Store;
use MoSMCP\Common\Repositories\NHI_Store;
use MoSMCP\Common\Repositories\Store;
use MoSMCP\Common\Services\Logging\Audit_Logger;
use MoSMCP\Common\Services\Logging\Debug_Logger;
use MoSMCP\Common\Views\Admin\Admin_App;
use stdClass;
use WP_Error;

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
	 * Longest "Details:" block added to an error result, in bytes of JSON.
	 */
	const ERROR_DETAILS_MAX = 4000;

	/**
	 * Ability names the current NHI client is allowed to use.
	 * NULL means all abilities are permitted.
	 *
	 * @var string[]|null
	 */
	private static $allowed_abilities = null;

	/**
	 * Throttle key for the direct-connection deprecation notice, or '' when this
	 * request should never carry it (gateway-brokered, or no client context).
	 *
	 * @var string
	 */
	private static $deprecation_key = '';

	/**
	 * Whether this request arrived over a direct (non-gateway) connection.
	 *
	 * Separate from $deprecation_key because the two are consumed differently:
	 * the key is throttled per client, while `initialize` carries the warning on
	 * every session handshake.
	 *
	 * @var bool
	 */
	private static $is_direct = false;

	/**
	 * Role slugs of the authenticated user, kept for diagnostics only.
	 *
	 * Ability filtering itself works off the resolved allow-list, not the roles;
	 * these are recorded so a log reader can see *which* role produced an empty
	 * tool list without having to look the user up.
	 *
	 * @var string[]
	 */
	private static $roles = array();

	/**
	 * The AI client this request authenticated as, for the tool-call filters.
	 *
	 * @var array{id: string, name: string}
	 */
	private static $client = array(
		'id'   => '',
		'name' => '',
	);

	/**
	 * Per-request record of how the exposed tool list was narrowed, in order.
	 *
	 * Populated by {@see exposed_abilities()}, read by {@see list_tools()}: the
	 * chain is measured where the narrowing happens but reported once, on the
	 * call a client's empty tool list actually comes from.
	 *
	 * @var array<string, int>|null
	 */
	private static $exposure_stats = null;

	/**
	 * Whether the exposure-narrowed warning has already been emitted this request.
	 *
	 * exposed_abilities() also runs on every tools/call, and the condition cannot
	 * change within a request, so repeating the warning would be pure noise.
	 *
	 * @var bool
	 */
	private static $exposure_warned = false;

	/**
	 * Transient key prefix marking a client as already warned about the deprecation.
	 */
	const DEPRECATION_NOTICE_PREFIX = 'mosmcp_direct_notice_';

	/**
	 * How long a client stays "already warned".
	 *
	 * The notice rides inside the tool result, which the model re-reads as input on
	 * every call — appending it to all of them would flood the agent's context and
	 * have it repeating the warning to the user on every answer. Once a day per
	 * client gives the same reach without the noise.
	 */
	const DEPRECATION_NOTICE_TTL = DAY_IN_SECONDS;

	/**
	 * Whether this request carries a connection-test token (read-only methods only).
	 *
	 * @var bool
	 */
	private static $diagnostic = false;

	/**
	 * Marks the current request as authenticated by a connection-test token.
	 *
	 * @param bool $diagnostic Whether the token is a connection-test token.
	 * @return void
	 */
	public static function set_diagnostic( $diagnostic ) {
		self::$diagnostic = (bool) $diagnostic;
	}

	/**
	 * Whether the current request is authenticated by a connection-test token.
	 *
	 * @return bool
	 */
	public static function is_diagnostic() {
		return self::$diagnostic;
	}

	/**
	 * Counts the tools a connection signed in as the given user would list.
	 *
	 * @param int $user_id The WordPress user id.
	 * @return int
	 */
	public static function count_exposed_for_user( $user_id ) {
		$user  = get_userdata( (int) $user_id );
		$roles = ( $user && is_array( $user->roles ) ) ? $user->roles : array();
		$saved = self::$allowed_abilities;

		self::set_allowed_abilities( NHI_Store::resolve_for_roles( $roles ) );

		try {
			return count( self::exposed_abilities() );
		} finally {
			self::$allowed_abilities = $saved;
		}
	}

	/**
	 * Most granted-but-unregistered ability names listed in one log row. A role
	 * policy pointing at a whole deactivated pack can produce hundreds; the count
	 * is what matters, and a sample is enough to identify which pack.
	 */
	const MISSING_GRANT_LOG_CAP = 100;

	/**
	 * How many registered ability names to quote when nothing was exposed. Enough
	 * to recognise which packs are present, short enough to leave the counts
	 * beside it readable.
	 */
	const REGISTRY_SAMPLE_SIZE = 20;

	/**
	 * Names of the abilities this request may call, captured by tools/call.
	 *
	 * @var string[]|null
	 */
	private static $exposed_names = null;

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
	 * Accepted keys: user_id (int), client_id (string), client_name (string),
	 * source (string: 'gateway'|'direct'), ip (string), roles (string[]).
	 *
	 * @param array<string, mixed> $ctx Context values from the bearer-token auth step.
	 * @return void
	 */
	public static function set_request_context( array $ctx ) {
		Audit_Logger::set_context( $ctx );

		$client_id = isset( $ctx['client_id'] ) ? (string) $ctx['client_id'] : '';

		self::$roles = ( isset( $ctx['roles'] ) && is_array( $ctx['roles'] ) ) ? array_values( $ctx['roles'] ) : array();

		self::$client = array(
			'id'   => $client_id,
			'name' => isset( $ctx['client_name'] ) ? (string) $ctx['client_name'] : '',
		);

		// Only direct connections carry the deprecation notice; the client id is used
		// solely to build the per-client throttle key, so it is not kept beyond this.
		self::$is_direct       = isset( $ctx['source'] ) && Store::SOURCE_DIRECT === $ctx['source'];
		self::$deprecation_key = ( '' !== $client_id && self::$is_direct )
			? self::DEPRECATION_NOTICE_PREFIX . $client_id
			: '';

		Debug_Logger::set_client_context(
			$client_id,
			isset( $ctx['client_name'] ) ? (string) $ctx['client_name'] : ''
		);
	}

	/**
	 * Appends the direct-connection deprecation warning to a tool result.
	 *
	 * Gateway-brokered requests are already on the supported path and get nothing.
	 * Added as its own text block so the tool's real output and `structuredContent`
	 * are untouched — anything parsing the result programmatically keeps working.
	 *
	 * The text is addressed to the assistant and states outright that it is not part
	 * of the result: without that framing models tend to either fold it into their
	 * answer as though it were data, or drop it silently.
	 *
	 * @param array<string, mixed> $tool_result The MCP tool result.
	 * @return array<string, mixed> The result, with the notice appended when due.
	 */
	private static function maybe_append_deprecation_notice( array $tool_result ) {
		if ( '' === self::$deprecation_key ) {
			return $tool_result;
		}

		if ( false !== get_transient( self::$deprecation_key ) ) {
			return $tool_result;
		}

		set_transient( self::$deprecation_key, 1, self::DEPRECATION_NOTICE_TTL );

		$tool_result['content'][] = array(
			'type' => 'text',
			'text' => sprintf(
				/* translators: %s: URL of the plugin's "Connect to AI" admin page. */
				__( '[Notice from the WordPress site, not part of the tool result. Relay this to the user once.] You are connected to this site directly. That method is deprecated and will stop working, so the user should switch their connection to AI via miniOrange Gateway. This is done because not every WordPress ability is AI ready on its own; the Gateway presents them correctly, making the experience seamless and improving your performance. To switch, open the Connect to AI page in WordPress and follow the steps there: %s', 'miniorange-secure-mcp-server' ),
				self::connect_page_url()
			),
		);

		return $tool_result;
	}

	/**
	 * URL of the plugin's "Connect to AI" screen for the token's own user.
	 *
	 * The admin menu page requires `manage_options`; members get the separate
	 * "My AI Access" page, which renders the same app and has the same /connect
	 * route. Sending an admin URL to a member would just land them on
	 * "Sorry, you are not allowed to access this page", so the capability of the
	 * user the token belongs to decides which one is quoted. Safe to check here:
	 * authenticate() has already called wp_set_current_user() for that user.
	 *
	 * The app is a HashRouter, so the route lives in the fragment.
	 *
	 * @return string
	 */
	private static function connect_page_url() {
		$slug = current_user_can( 'manage_options' ) ? Admin_App::PAGE_SLUG : Admin_App::MEMBER_PAGE_SLUG;

		return admin_url( 'admin.php?page=' . $slug ) . '#/connect';
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

		if ( Debug_Logger::is_capturing() ) {
			Debug_Logger::debug(
				Debug_Logger::CHANNEL_MCP,
				sprintf( 'Dispatching MCP method "%s".', $method ),
				array(
					'method'          => $method,
					'rpc_id'          => $id,
					'param_keys'      => array_keys( $params ),
					'is_notification' => $is_notification,
				)
			);
		}

		// Notifications never receive a response, per JSON-RPC 2.0 — checked before
		// any other early-return so a notification sent while zero NHIs are enabled
		// is silently dropped rather than answered with an error object.
		if ( $is_notification ) {
			return null;
		}

		$enabled_nhis = NHI_Store::count_enabled();

		if ( 0 === $enabled_nhis ) {
			// The -32001 an AI client reports only as a failed connection. Logged as a
			// warning because it is never a normal state for a site someone is actively
			// trying to connect to.
			Debug_Logger::warning(
				Debug_Logger::CHANNEL_MCP,
				'Refused MCP request: no NHI is enabled, so no tools can be exposed.',
				array(
					'count_enabled' => $enabled_nhis,
					'method'        => $method,
					'rpc_id'        => $id,
					'rpc_error'     => -32001,
				)
			);

			return self::error_response(
				$id,
				-32001,
				'No NHI is enabled. Create or enable an NHI to allow MCP access: ' . admin_url( 'admin.php?page=mosmcp-abilities' ) . '#/nhi'
			);
		}

		try {
			switch ( $method ) {
				case 'initialize':
					return self::success_response( $id, self::initialize_result( $params ) );

				case 'ping':
					return self::success_response( $id, new stdClass() );

				case 'tools/list':
					return self::success_response( $id, self::list_tools() );

				case 'tools/call':
					return self::call_tool( $id, $params );

				default:
					Debug_Logger::warning(
						Debug_Logger::CHANNEL_MCP,
						sprintf( 'Rejected unknown MCP method "%s".', $method ),
						array(
							'method' => $method,
							'rpc_id' => $id,
						)
					);

					return self::error_response( $id, -32601, __( 'Method not found.', 'miniorange-secure-mcp-server' ) );
			}
		} catch ( \Throwable $e ) {
			// Anything that escapes a handler (a registry or filter callback that throws, a TypeError)
			// would otherwise become a bare HTML 500 the broker can only report as a bad reply.
			return self::internal_error( $id, $method, $e );
		}
	}

	/**
	 * Answers a request whose handler threw: a JSON-RPC internal error carrying the log reference.
	 *
	 * The exception's message and location go to the log, not to the caller: they are for the site
	 * owner, and a file path or query fragment has no business in an AI client's context.
	 *
	 * @param mixed      $id     The JSON-RPC id.
	 * @param string     $method The method being handled.
	 * @param \Throwable $e      What was thrown.
	 * @return array<string, mixed> The JSON-RPC error response.
	 */
	private static function internal_error( $id, $method, \Throwable $e ) {
		Debug_Logger::error(
			Debug_Logger::CHANNEL_MCP,
			sprintf( 'MCP method "%s" threw %s.', $method, get_class( $e ) ),
			array(
				'method'    => $method,
				'exception' => get_class( $e ),
				'detail'    => self::describe_throwable( $e ),
				'rpc_id'    => $id,
			)
		);

		return self::error_response(
			$id,
			-32603,
			sprintf(
				/* translators: %s: a reference the site owner can find in the debug log. */
				__( 'Internal error. Reference: %s', 'miniorange-secure-mcp-server' ),
				Debug_Logger::current_request_id()
			)
		);
	}

	/**
	 * A throwable's message and location, short enough to log.
	 *
	 * @param \Throwable $e What was thrown.
	 * @return array{message: string, at: string}
	 */
	private static function describe_throwable( \Throwable $e ) {
		return array(
			'message' => function_exists( 'mb_substr' ) ? mb_substr( $e->getMessage(), 0, 300 ) : substr( $e->getMessage(), 0, 300 ),
			'at'      => basename( $e->getFile() ) . ':' . $e->getLine(),
		);
	}

	/**
	 * @param array<string, mixed> $params The `initialize` request params.
	 * @return array<string, mixed>
	 */
	private static function initialize_result( array $params = array() ) {
		$result = array(
			'protocolVersion' => self::PROTOCOL_VERSION,
			'capabilities'    => array(
				'tools' => array( 'listChanged' => false ),
			),
			'serverInfo'      => array(
				'name'    => 'miniOrange Secure MCP Server',
				'version' => MOSMCP_VERSION,
			),
		);

		// Server-level steering, read by the model once per session. This is the
		// reliable channel for the deprecation warning: the copy appended to each
		// tool result is routinely dropped by clients that summarise the payload
		// (ChatGPT does, especially when structuredContent is present), whereas
		// `instructions` is the field the protocol provides for telling the model
		// something about the server itself.
		if ( self::$is_direct ) {
			$result['instructions'] = sprintf(
				/* translators: %s: URL of the plugin's "Connect to AI" admin page. */
				__( 'IMPORTANT: this connection reaches the WordPress site directly. That connection method is deprecated and will stop working. Once in this conversation, after answering the user\'s request, tell them briefly that they need to switch their connection to AI via miniOrange Gateway, because not every WordPress ability is AI ready on its own and the Gateway presents them correctly, making the experience seamless and improving your performance. Point them to this page for the steps: %s', 'miniorange-secure-mcp-server' ),
				self::connect_page_url()
			);
		}

		if ( Debug_Logger::is_capturing() ) {
			$requested = isset( $params['protocolVersion'] ) ? (string) $params['protocolVersion'] : '';

			Debug_Logger::debug(
				Debug_Logger::CHANNEL_MCP,
				'MCP session initialized.',
				array(
					// The server answers with its own version whatever was asked for, so
					// both are recorded: a client that wanted a version this server does
					// not speak shows up here rather than as odd behaviour further down.
					'client_protocol' => '' !== $requested ? $requested : '(unset)',
					'server_protocol' => self::PROTOCOL_VERSION,
					'is_direct'       => self::$is_direct,
				)
			);
		}

		return $result;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function list_tools() {
		$tools     = array();
		$abilities = self::exposed_abilities();

		foreach ( $abilities as $ability ) {
			$tools[] = self::ability_to_tool( $ability );
		}

		self::log_exposure( $abilities );

		return array( 'tools' => $tools );
	}

	/**
	 * Reports how a tools/list call arrived at the tool count it did.
	 *
	 * This is the record that separates the ways an AI client ends up reporting
	 * "no abilities": a short or empty registry, a role policy that matched none
	 * of it, an install-level trim, or a perfectly healthy list whose fault lies
	 * downstream. All four are the same symptom to the user and, until now, the
	 * same silence in the log.
	 *
	 * The response envelope is deliberately never recorded — on a large install
	 * it runs past a hundred kilobytes, almost all of it input schemas that the
	 * abilities admin screen already shows. Names alone answer the question.
	 *
	 * @param \WP_Ability[] $abilities The abilities actually exposed.
	 * @return void
	 */
	private static function log_exposure( array $abilities ) {
		if ( ! Debug_Logger::is_capturing() ) {
			return;
		}

		$names = array_map(
			static function ( $ability ) {
				return (string) $ability->get_name();
			},
			array_values( $abilities )
		);

		$exposed_count = count( $names );
		$registered    = self::registered_names();

		$context = array_merge(
			is_array( self::$exposure_stats ) ? self::$exposure_stats : array(),
			array( 'roles' => ! empty( self::$roles ) ? implode( ',', self::$roles ) : '(none)' )
		);

		if ( 0 === $exposed_count ) {
			// Name a few things that ARE registered. It is the cheapest way to tell
			// "the registry is empty or short" apart from "the registry is full and
			// this role's policy selected none of it".
			$context['registry_sample'] = ! empty( $registered )
				? implode( ',', array_slice( $registered, 0, self::REGISTRY_SAMPLE_SIZE ) )
				: '(registry empty)';

			// Escalated: a connected client being handed zero tools is never a normal
			// resting state, so it has to surface to someone filtering to warning+.
			Debug_Logger::warning(
				Debug_Logger::CHANNEL_MCP,
				'tools/list exposed no tools.',
				$context
			);
		} else {
			Debug_Logger::info(
				Debug_Logger::CHANNEL_MCP,
				sprintf( 'tools/list exposed %d tool(s).', $exposed_count ),
				$context
			);
		}

		foreach ( Debug_Logger::chunk_list( $names ) as $chunk ) {
			Debug_Logger::debug(
				Debug_Logger::CHANNEL_MCP,
				sprintf( 'tools/list exposed ability names (chunk %s).', $chunk['chunk'] ),
				$chunk
			);
		}

		// Grants naming abilities the registry no longer has: the usual cause is a
		// pack being deactivated while the role policy kept pointing at it. The
		// consent screen still prints these by name, so the user is told they have
		// access to tools that can never appear.
		if ( null !== self::$allowed_abilities ) {
			$missing = array_values( array_diff( self::$allowed_abilities, $registered ) );

			if ( ! empty( $missing ) ) {
				Debug_Logger::warning(
					Debug_Logger::CHANNEL_MCP,
					sprintf( '%d granted ability name(s) are absent from the registry.', count( $missing ) ),
					array(
						'missing_count' => count( $missing ),
						'listed_cap'    => self::MISSING_GRANT_LOG_CAP,
						'missing'       => implode( ',', array_slice( $missing, 0, self::MISSING_GRANT_LOG_CAP ) ),
					)
				);
			}
		}
	}

	/**
	 * Every ability name currently in the registry, unfiltered.
	 *
	 * Compared against the role's grants rather than against the exposed list:
	 * a grant that survives policy but has no registry entry behind it is a
	 * different fault from one the policy itself removed.
	 *
	 * @return string[]
	 */
	private static function registered_names() {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return array();
		}

		return array_map(
			static function ( $ability ) {
				return (string) $ability->get_name();
			},
			array_values( wp_get_abilities() )
		);
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

		self::$exposed_names = array();
		foreach ( $abilities as $candidate ) {
			self::$exposed_names[] = (string) $candidate->get_name();
			if ( null === $ability && self::encode_name( $candidate->get_name() ) === $tool_name ) {
				$ability = $candidate;
			}
		}

		if ( null === $ability ) {
			// Log the denied call: best-effort decode the encoded name back to ability form.
			$decoded_name = str_replace( '__', '/', $tool_name );
			$unavailable  = self::unavailable_reason( $decoded_name );

			Audit_Logger::record_tool_call(
				$decoded_name,
				'denied',
				0,
				$unavailable['code'],
				$unavailable['message'],
				$id
			);

			Debug_Logger::warning(
				Debug_Logger::CHANNEL_MCP,
				sprintf( 'Denied tools/call for unavailable tool "%s" (%s).', $decoded_name, $unavailable['code'] ),
				array(
					'tool_name'     => $decoded_name,
					'raw_tool_name' => $tool_name,
					'reason'        => $unavailable['code'],
					'json_rpc_id'   => $id,
				)
			);

			return self::error_response( $id, -32602, $unavailable['message'] );
		}

		// Lets the site-overview ability tell the AI which abilities this connection may use.
		add_filter( 'mosmcp_overview_ability_access', array( __CLASS__, 'overview_access' ) );

		$input = array_key_exists( 'input', $arguments ) ? $arguments['input'] : null;

		/*
		 * `input` is optional in the advertised schema, and a client calling an
		 * ability that takes no parameters often leaves it out. The ability's own
		 * schema is an object, so a missing input is an empty object, not null;
		 * passing null fails validation with "input is not of type object".
		 */
		if ( null === $input ) {
			$schema = $ability->get_input_schema();
			if ( is_array( $schema ) && isset( $schema['type'] ) && 'object' === $schema['type'] ) {
				$input = array();
			}
		}

		$call_context = array(
			'user_id'     => get_current_user_id(),
			'roles'       => self::$roles,
			'client_id'   => self::$client['id'],
			'client_name' => self::$client['name'],
		);

		/**
		 * Filters a tool call's input before the ability runs.
		 *
		 * Return the input, changed or not, for the call to go ahead with it, or a
		 * WP_Error to refuse the call: the ability then never runs, the call is
		 * logged as denied, and the error's message is what the AI client sees.
		 * The error data may set `audit_status` to log the refusal under another
		 * status registered through `mosmcp_audit_statuses`.
		 *
		 * @param mixed       $input        The ability input the AI client sent.
		 * @param \WP_Ability $ability      The ability being called.
		 * @param array       $call_context {
		 *     The caller.
		 *
		 *     @type int      $user_id     The WordPress user the connection acts as.
		 *     @type string[] $roles       That user's role slugs.
		 *     @type string   $client_id   The AI client's id.
		 *     @type string   $client_name The AI client's name.
		 * }
		 */
		$input = apply_filters( 'mosmcp_tool_call_input', $input, $ability, $call_context );
		if ( is_wp_error( $input ) ) {
			return self::refuse_tool_call( $id, $ability, $input, 0, 'input' );
		}

		// Execute and measure wall-clock latency.
		$start_us = microtime( true );
		try {
			$result = $ability->execute( $input );
		} catch ( \Throwable $e ) {
			// A crash inside an ability becomes the same "tool failed" result as an ability that
			// returned a WP_Error, so the AI client gets an answer it can relay (with a reference to
			// quote) instead of a dead connection. The detail rides in `cause`, which is logged
			// below but never shown to the client (see error_details()).
			$result = new WP_Error(
				'ability_exception',
				sprintf(
					/* translators: %s: a reference the site owner can find in the debug log. */
					__( 'The tool failed unexpectedly. Reference: %s', 'miniorange-secure-mcp-server' ),
					Debug_Logger::current_request_id()
				),
				array(
					'status'    => 500,
					'retryable' => false,
					'cause'     => array(
						'exception' => get_class( $e ),
						'detail'    => self::describe_throwable( $e ),
					),
				)
			);
		}
		$latency = (int) round( ( microtime( true ) - $start_us ) * 1000.0 );

		if ( is_wp_error( $result ) ) {
			/**
			 * Filters a failed tool call's error before it is logged and sent to the AI client.
			 *
			 * An error's message and data reach the AI client just as a result does
			 * (the data as a "Details" block), so callbacks that filter results usually
			 * need to filter errors too. Return the error, changed or not, or a replacement
			 * WP_Error; anything else is treated as a generic failure.
			 *
			 * @param WP_Error    $result       The ability's error.
			 * @param \WP_Ability $ability      The ability that ran.
			 * @param array       $call_context The caller; see `mosmcp_tool_call_input`.
			 */
			$result = apply_filters( 'mosmcp_tool_call_error', $result, $ability, $call_context );
			if ( ! is_wp_error( $result ) ) {
				$result = new WP_Error( 'mosmcp_tool_call_failed', __( 'The tool call failed.', 'miniorange-secure-mcp-server' ) );
			}

			Audit_Logger::record_tool_call(
				$ability->get_name(),
				'failed',
				$latency,
				$result->get_error_code(),
				$result->get_error_message(),
				$id
			);

			// A crash or server fault is a failure worth keeping with capture off; a refusal or bad input
			// (permission denied, not found, invalid argument) is routine and would crowd those out.
			$error_data = $result->get_error_data();
			$http       = is_array( $error_data ) && isset( $error_data['status'] ) ? (int) $error_data['status'] : 0;
			$failed_at  = 'ability_exception' === $result->get_error_code() || $http >= 500 ? 'error' : 'warning';

			Debug_Logger::$failed_at(
				Debug_Logger::CHANNEL_MCP,
				sprintf( 'Tool call "%s" failed: %s', $ability->get_name(), $result->get_error_message() ),
				array(
					'tool_name'   => $ability->get_name(),
					'error_code'  => $result->get_error_code(),
					'error_data'  => $error_data,
					'latency_ms'  => $latency,
					'input_keys'  => is_array( $input ) ? array_keys( $input ) : gettype( $input ),
					'json_rpc_id' => $id,
				)
			);

			$content = array(
				array(
					'type' => 'text',
					'text' => $result->get_error_message(),
				),
			);
			$details = self::error_details( $result );
			if ( '' !== $details ) {
				$content[] = array(
					'type' => 'text',
					'text' => $details,
				);
			}

			return self::success_response(
				$id,
				self::maybe_append_deprecation_notice(
					array(
						'content' => $content,
						'isError' => true,
					)
				)
			);
		}

		/**
		 * Filters a successful tool call's result before it goes back to the AI client.
		 *
		 * Return the result, changed or not, to send it, or a WP_Error to withhold
		 * it: the call is logged as denied and the error's message is what the AI
		 * client sees. The ability has already run by then, so a refusal here
		 * withholds its response but does not undo what it did.
		 *
		 * @param mixed       $result       The ability's result.
		 * @param \WP_Ability $ability      The ability that ran.
		 * @param array       $call_context The caller; see `mosmcp_tool_call_input`.
		 */
		$result = apply_filters( 'mosmcp_tool_call_result', $result, $ability, $call_context );
		if ( is_wp_error( $result ) ) {
			return self::refuse_tool_call( $id, $ability, $result, $latency, 'result' );
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

		return self::success_response( $id, self::maybe_append_deprecation_notice( $tool_result ) );
	}

	/**
	 * Logs and answers a tool call that a `mosmcp_tool_call_input` or
	 * `mosmcp_tool_call_result` callback refused.
	 *
	 * Answered as a tool error rather than a protocol error, so the AI client reads
	 * the reason and can tell the user, instead of treating the call as broken.
	 *
	 * @param mixed       $id      JSON-RPC id of the call.
	 * @param \WP_Ability $ability The ability called.
	 * @param WP_Error    $error   Why the call was refused.
	 * @param int         $latency Milliseconds the ability ran for; 0 when it never ran.
	 * @param string      $stage   'input' (refused before running) or 'result' (response withheld).
	 * @return array<string, mixed>
	 */
	private static function refuse_tool_call( $id, $ability, $error, $latency, $stage ) {
		$message = $error->get_error_message();
		if ( '' === $message ) {
			$message = __( 'This tool call was refused by the site\'s policy.', 'miniorange-secure-mcp-server' );
		}

		// The status the refusing callback asked for (see `mosmcp_tool_call_input`), else denied.
		// A refusal is never logged as a success.
		$data   = $error->get_error_data();
		$asked  = is_array( $data ) && isset( $data['audit_status'] ) ? (string) $data['audit_status'] : '';
		$status = 'success' !== $asked && in_array( $asked, Audit_Store::statuses(), true ) ? $asked : 'denied';

		Audit_Logger::record_tool_call(
			$ability->get_name(),
			$status,
			$latency,
			$error->get_error_code(),
			$message,
			$id
		);

		Debug_Logger::warning(
			Debug_Logger::CHANNEL_MCP,
			sprintf( 'Tool call "%s" refused (%s): %s', $ability->get_name(), $stage, $error->get_error_code() ),
			array(
				'tool_name'   => $ability->get_name(),
				'stage'       => $stage,
				'error_code'  => $error->get_error_code(),
				'latency_ms'  => $latency,
				'json_rpc_id' => $id,
			)
		);

		return self::success_response(
			$id,
			self::maybe_append_deprecation_notice(
				array(
					'content' => array(
						array(
							'type' => 'text',
							'text' => $message,
						),
					),
					'isError' => true,
				)
			)
		);
	}

	/**
	 * @return \WP_Ability[]
	 */
	private static function exposed_abilities() {
		$abilities = function_exists( 'wp_get_abilities' ) ? wp_get_abilities() : array();

		$registry_total = count( $abilities );

		/** @param \WP_Ability[] $abilities */
		$abilities = apply_filters( 'mosmcp_exposed_abilities', $abilities );

		// A filter returning a non-array is treated as having removed everything,
		// rather than returned from early as before, so the stage that emptied the
		// list is still recorded below. Both later stages are no-ops on an empty
		// array, so the result is unchanged.
		if ( ! is_array( $abilities ) ) {
			$abilities = array();
		}

		$after_filter = count( $abilities );

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

		$allowed_count = count( $abilities );

		$abilities = self::apply_prefix_trim( $abilities );

		$after_prefix_trim = count( $abilities );

		self::$exposure_stats = array(
			'registry_total'    => $registry_total,
			'after_filter'      => $after_filter,
			'allowed_count'     => $allowed_count,
			'after_prefix_trim' => $after_prefix_trim,
			'exposed_count'     => $after_prefix_trim,
		);

		self::maybe_warn_exposure_narrowed( $registry_total, $after_filter, $allowed_count, $after_prefix_trim );

		return $abilities;
	}

	/**
	 * Warns when something other than the role policy changed the tool count.
	 *
	 * The `mosmcp_exposed_abilities` filter and the MOSMCP_EXPOSED_PREFIXES trim
	 * are both invisible from the admin screens, so a site whose tool list has
	 * been reshaped by either one looks, to everyone involved, like a site whose
	 * role policy is wrong. Silent while neither changed anything, which is the
	 * default install.
	 *
	 * @param int $registry_total    Abilities in the registry.
	 * @param int $after_filter      Abilities after the mosmcp_exposed_abilities filter.
	 * @param int $allowed_count     Abilities after the role allow-list.
	 * @param int $after_prefix_trim Abilities after the prefix trim.
	 * @return void
	 */
	private static function maybe_warn_exposure_narrowed( $registry_total, $after_filter, $allowed_count, $after_prefix_trim ) {
		if ( self::$exposure_warned ) {
			return;
		}

		$filter_delta = $after_filter - $registry_total;
		$trim_delta   = $after_prefix_trim - $allowed_count;

		if ( 0 === $filter_delta && 0 === $trim_delta ) {
			return;
		}

		self::$exposure_warned = true;

		Debug_Logger::warning(
			Debug_Logger::CHANNEL_MCP,
			'Tool exposure was changed outside the role policy.',
			array(
				// Negative removed, positive added.
				'filter_delta'   => $filter_delta,
				'trim_delta'     => $trim_delta,
				'prefixes'       => defined( 'MOSMCP_EXPOSED_PREFIXES' ) ? (string) MOSMCP_EXPOSED_PREFIXES : '(unset)',
				'registry_total' => $registry_total,
				'exposed_count'  => $after_prefix_trim,
			)
		);
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
	 * The actionable part of an ability error's data — closest matching text,
	 * conflicts, what to use instead — as a compact text block. Messages often
	 * refer to these ("the closest passages are given below"), and without them
	 * the model is left guessing. Transport-only keys are dropped and the block is
	 * capped so a large payload cannot flood the context.
	 *
	 * @param WP_Error $error Error.
	 * @return string Empty when there is nothing worth adding.
	 */
	private static function error_details( $error ) {
		$data = $error->get_error_data();
		if ( ! is_array( $data ) ) {
			return '';
		}
		unset( $data['status'], $data['cause'], $data['retryable'] );
		$data = array_filter(
			$data,
			static function ( $value ) {
				return null !== $value && '' !== $value && array() !== $value;
			}
		);
		if ( empty( $data ) ) {
			return '';
		}
		$json = wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $json ) ) {
			return '';
		}
		// Too long: shrink the data rather than cut the text, so the block is always
		// valid JSON — first shorter strings and lists, then just the field names.
		foreach ( array( array( 300, 5 ), array( 80, 2 ) ) as $limits ) {
			if ( strlen( $json ) <= self::ERROR_DETAILS_MAX ) {
				break;
			}
			$json = wp_json_encode( array( 'truncated' => true ) + self::shrink_details( $data, $limits[0], $limits[1] ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		}
		if ( ! is_string( $json ) || strlen( $json ) > self::ERROR_DETAILS_MAX ) {
			$json = wp_json_encode(
				array(
					'truncated' => true,
					'fields'    => array_keys( $data ),
				),
				JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
			);
		}
		return 'Details: ' . $json;
	}

	/**
	 * Shortens every string and list in error details, keeping the structure.
	 *
	 * @param mixed $value      Value.
	 * @param int   $max_string Longest string kept, in characters.
	 * @param int   $max_items  Most list items kept.
	 * @return mixed
	 */
	private static function shrink_details( $value, $max_string, $max_items ) {
		if ( is_string( $value ) ) {
			return mb_strlen( $value ) > $max_string ? mb_substr( $value, 0, $max_string ) . '…' : $value;
		}
		if ( ! is_array( $value ) ) {
			return $value;
		}
		$is_list = array() === $value || array_keys( $value ) === range( 0, count( $value ) - 1 );
		if ( $is_list && count( $value ) > $max_items ) {
			$value = array_slice( $value, 0, $max_items );
		}
		foreach ( $value as $key => $item ) {
			$value[ $key ] = self::shrink_details( $item, $max_string, $max_items );
		}
		return $value;
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

		// A list of required fields stays on the input object, so clients know which
		// arguments are mandatory before calling; it also makes "input" itself required.
		// A bare boolean (the older per-property form) is not valid there, so it only
		// marks "input" as required.
		$required = false;
		if ( isset( $schema['required'] ) ) {
			if ( is_array( $schema['required'] ) ) {
				$required = ! empty( $schema['required'] );
				if ( ! $required ) {
					unset( $schema['required'] );
				}
			} else {
				$required = (bool) $schema['required'];
				unset( $schema['required'] );
			}
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
	 * Whether an ability only reads, as far as this site can tell.
	 *
	 * For WordPress core and this plugin's own abilities, the same read-only hint
	 * AI clients are given (declared, or from the verb). Any other ability counts
	 * as read-only only when its author declared it so; when in doubt, it is
	 * treated as one that can make changes.
	 *
	 * @param \WP_Ability $ability The ability.
	 * @return bool
	 */
	public static function is_read_only( $ability ) {
		$namespace = strtolower( (string) strstr( (string) $ability->get_name(), '/', true ) );
		if ( in_array( $namespace, array( 'core', 'mosmcp' ), true ) ) {
			$annotations = self::map_annotations( $ability );
			return ! empty( $annotations['readOnlyHint'] );
		}

		$source = $ability->get_meta_item( 'annotations' );

		return is_array( $source ) && isset( $source['readonly'] ) && true === $source['readonly'];
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
	 * Why a called tool is not available, in words the AI can relay.
	 *
	 * "Unknown tool" alone reads as "this site cannot do that", when often the
	 * ability exists and is simply not switched on for the caller's role. Telling
	 * the two apart turns a dead end into an instruction the person can act on.
	 *
	 * @param string $name Ability name the client asked for.
	 * @return array{code:string,message:string}
	 */
	private static function unavailable_reason( $name ) {
		$registered = function_exists( 'wp_get_ability' ) ? wp_get_ability( (string) $name ) : null;

		if ( ! $registered ) {
			return array(
				'code'    => 'unknown_tool',
				'message' => sprintf(
					/* translators: %s: ability name. */
					__( 'Unknown tool: this site has no ability named %s. List the abilities this connection can use, and use one of those names exactly.', 'miniorange-secure-mcp-server' ),
					(string) $name
				),
			);
		}

		if ( null !== self::$allowed_abilities && ! in_array( (string) $name, self::$allowed_abilities, true ) ) {
			return array(
				'code'    => 'not_granted',
				'message' => sprintf(
					/* translators: 1: ability name, 2: comma-separated role names, 3: admin page URL. */
					__( 'The ability %1$s exists on this site, but it is not switched on for this account\'s role (%2$s) in any active AI agent, so it cannot be used through this connection. Tell the person: a site administrator can turn it on in WordPress under Secure MCP Server, AI Agents, then the agent\'s Access tab: %3$s', 'miniorange-secure-mcp-server' ),
					(string) $name,
					empty( self::$roles ) ? __( 'no role', 'miniorange-secure-mcp-server' ) : implode( ', ', array_map( 'strval', self::$roles ) ),
					admin_url( 'admin.php?page=' . Admin_App::PAGE_SLUG ) . '#/nhi'
				),
			);
		}

		return array(
			'code'    => 'hidden_by_site',
			'message' => sprintf(
				/* translators: %s: ability name. */
				__( 'The ability %s exists on this site, but the site\'s configuration hides it from AI clients (the MOSMCP_EXPOSED_PREFIXES setting or a developer filter). Only a site developer can change that.', 'miniorange-secure-mcp-server' ),
				(string) $name
			),
		);
	}

	/**
	 * Supplies the abilities this connection may use to the site-overview ability.
	 *
	 * Hooked to `mosmcp_overview_ability_access` for the duration of a tools/call.
	 *
	 * @param string[]|null $names Names supplied by an earlier filter, if any.
	 * @return string[]|null
	 */
	public static function overview_access( $names ) {
		return is_array( self::$exposed_names ) ? self::$exposed_names : $names;
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
