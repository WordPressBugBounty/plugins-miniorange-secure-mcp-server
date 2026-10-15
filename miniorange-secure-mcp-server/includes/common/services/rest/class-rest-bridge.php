<?php
/**
 * Grant checking, dispatch and response shaping for the REST bridge abilities.
 *
 * @package Miniorange_Secure_MCP_Server
 */

namespace MoSMCP\Common\Services\Rest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MoSMCP\Common\Services\Logging\Audit_Logger;
use WP_Error;
use WP_REST_Request;

/**
 * Class Rest_Bridge
 *
 * Backs the three REST bridge abilities. Every path in here is gated twice: by the
 * route grants resolved for this request's NHI, and by the endpoint's own
 * permission_callback, which rest_do_request() runs against the token's WordPress user.
 */
class Rest_Bridge {

	/**
	 * Largest JSON response handed back before list results are trimmed.
	 */
	const MAX_RESPONSE_BYTES = 60000;

	/**
	 * per_page injected into collection reads when the caller does not set one.
	 */
	const DEFAULT_PER_PAGE = 10;

	/**
	 * Option holding the site-wide write kill switches, keyed 'enable_create',
	 * 'enable_update', 'enable_delete'. All default true: the real gate is the
	 * per-route NHI grant, and this is an emergency, site-wide override on top of it.
	 */
	const SETTINGS_OPTION = 'mosmcp_rest_bridge_settings';

	/**
	 * Route grants for this request; defaults to an empty list (deny all), null means unrestricted.
	 * Per-request static state: reassigned by the MCP controller before every dispatch.
	 *
	 * @var string[]|null
	 */
	private static $allowed_routes = array();

	/**
	 * Sets the route allow-list for this request.
	 *
	 * @param string[]|null $grants Grant strings, or null for unrestricted.
	 * @return void
	 */
	public static function set_allowed_routes( $grants ) {
		if ( null === $grants ) {
			self::$allowed_routes = null;

			return;
		}

		self::$allowed_routes = is_array( $grants ) ? array_values( $grants ) : array();
	}

	/**
	 * Lists the routes this request may reach.
	 *
	 * @param array<string, mixed> $input Tool input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function list_routes( $input = array() ) {
		$input = is_array( $input ) ? $input : array();

		$namespace = isset( $input['namespace'] ) ? (string) $input['namespace'] : '';
		$search    = isset( $input['search'] ) ? strtolower( (string) $input['search'] ) : '';
		$method    = isset( $input['method'] ) ? strtoupper( (string) $input['method'] ) : '';
		$page      = isset( $input['page'] ) ? max( 1, absint( $input['page'] ) ) : 1;
		$per_page  = isset( $input['per_page'] ) ? min( 200, max( 1, absint( $input['per_page'] ) ) ) : 50;

		$matches = array();

		foreach ( self::visible_routes() as $entry ) {
			if ( '' !== $namespace && $entry['namespace'] !== $namespace ) {
				continue;
			}

			if ( '' !== $search && false === strpos( strtolower( $entry['template'] ), $search ) ) {
				continue;
			}

			if ( '' !== $method && ! in_array( $method, $entry['methods'], true ) ) {
				continue;
			}

			$matches[] = array(
				'route'   => $entry['template'],
				'methods' => $entry['methods'],
			);
		}

		$total = count( $matches );

		return array(
			'routes'      => array_slice( $matches, ( $page - 1 ) * $per_page, $per_page ),
			'total'       => $total,
			'page'        => $page,
			'per_page'    => $per_page,
			'total_pages' => (int) ceil( $total / $per_page ),
		);
	}

	/**
	 * Describes the parameters one route and method accepts.
	 *
	 * @param array<string, mixed> $input Tool input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function describe_route( $input = array() ) {
		$input  = is_array( $input ) ? $input : array();
		$method = isset( $input['method'] ) ? strtoupper( (string) $input['method'] ) : 'GET';
		$route  = isset( $input['route'] ) ? (string) $input['route'] : '';

		$resolved = self::authorize( $route, $method );

		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		$handler = Route_Registry::handler( $resolved['entry']['pattern'], $method );

		if ( null === $handler ) {
			return new WP_Error(
				'mosmcp_rest_route_unknown',
				__( 'That route is no longer registered on this site.', 'miniorange-secure-mcp-server' )
			);
		}

		return array(
			'route'       => $resolved['entry']['template'],
			'method'      => $method,
			'path_params' => self::path_params( $resolved['entry']['template'] ),
			'params'      => self::describe_args( isset( $handler['args'] ) ? (array) $handler['args'] : array() ),
			'notes'       => __( 'Pass "_fields" to limit which fields come back; large list responses are trimmed otherwise.', 'miniorange-secure-mcp-server' ),
		);
	}

	/**
	 * Executes a REST call on behalf of the agent.
	 *
	 * @param array<string, mixed> $input Tool input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function call( $input = array() ) {
		$input  = is_array( $input ) ? $input : array();
		$method = isset( $input['method'] ) ? strtoupper( (string) $input['method'] ) : 'GET';
		$route  = isset( $input['route'] ) ? (string) $input['route'] : '';
		$params = ( isset( $input['params'] ) && is_array( $input['params'] ) ) ? $input['params'] : array();
		$fields = isset( $input['_fields'] ) ? (string) $input['_fields'] : '';

		$path     = Route_Registry::normalize_path( $route );
		$resolved = self::authorize( $route, $method );

		// Record what was attempted even when it is refused, so the audit trail shows the target.
		Audit_Logger::set_meta(
			array(
				'route'  => '' !== $path ? $path : $route,
				'method' => $method,
			)
		);

		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		// Query params an agent appended directly to the route string (e.g. '?per_page=5'),
		// merged the same way the original single-method implementation did: only for
		// GET/DELETE, since a route-string query on a write has nowhere sensible to go.
		if ( 'GET' === $method || 'DELETE' === $method ) {
			$params = array_merge( self::query_from_route( $route ), $params );
		}

		return self::dispatch( $resolved['entry'], $resolved['canonical'], $method, $path, $params, $fields );
	}

	/**
	 * Runs an already-authorized REST call: kill switches, the pre-dispatch filter,
	 * request assembly, dispatch, and response shaping/trimming.
	 *
	 * Shared by the generic rest-call ability (after its route-grant check) and by a
	 * promoted route's own ability (which is governed by its own ability grant instead
	 * of a route grant, but must still honor the same site-wide switches and filter).
	 *
	 * @param array<string, mixed> $entry     Route_Registry entry for the matched route.
	 * @param string                $canonical Canonical grant method for this route.
	 * @param string                $method    Wire HTTP method to send.
	 * @param string                $path      Concrete request path, placeholders filled in.
	 * @param array<string, mixed>  $params    Query parameters (GET/DELETE) or body (otherwise).
	 * @param string                $fields    Optional "_fields" projection.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function dispatch( array $entry, $canonical, $method, $path, array $params, $fields = '' ) {
		// Checked only for execution, not for describe_route(): the kill switches pause
		// writes from taking effect, they don't need to stop an agent from learning a
		// write route's shape while that pause is in effect.
		$disabled = self::verb_disabled( $canonical );

		if ( '' !== $disabled ) {
			return new WP_Error( 'mosmcp_rest_verb_disabled', $disabled );
		}

		/**
		 * Fires after the bridge has authorized a call but before it dispatches it.
		 *
		 * rest_do_request() dispatches directly rather than through serve_request(), so
		 * the core 'rest_authentication_errors' filter never runs for bridge calls — a
		 * security plugin that uses it for rate limiting, IP blocking or step-up auth
		 * gets no say over an otherwise-authorized bridge call. Hook here instead to get
		 * one: returning a WP_Error short-circuits the call before rest_do_request() runs.
		 *
		 * @param null|WP_Error $pre    Null to proceed.
		 * @param string         $route  Route template, e.g. '/wp/v2/posts/{id}'.
		 * @param string         $method HTTP method as requested.
		 * @param array          $params Parameters the bridge is about to send.
		 */
		$pre = apply_filters( 'mosmcp_rest_bridge_pre_dispatch', null, $entry['template'], $method, $params );

		if ( is_wp_error( $pre ) ) {
			return $pre;
		}

		$request = new WP_REST_Request( $method, $path );
		$query   = array();

		if ( '' !== $fields ) {
			$query['_fields'] = $fields;
		}

		if ( 'GET' === $method || 'DELETE' === $method ) {
			$query = array_merge( $query, $params );
		} else {
			$request->set_body_params( $params );
		}

		if ( 'GET' === $method && ! isset( $query['per_page'] ) ) {
			$handler = Route_Registry::handler( $entry['pattern'], $method );

			// Collections default to everything the site has; cap them so one call can't flood the context.
			if ( $handler && isset( $handler['args']['per_page'] ) ) {
				$query['per_page'] = self::DEFAULT_PER_PAGE;
			}
		}

		if ( ! empty( $query ) ) {
			$request->set_query_params( $query );
		}

		$response = rest_do_request( $request );
		$status   = $response->get_status();
		$headers  = $response->get_headers();
		$data     = $response->get_data();

		Audit_Logger::set_meta(
			array(
				'route'  => $entry['template'],
				'method' => $method,
				'status' => $status,
			)
		);

		if ( $response->is_error() ) {
			return new WP_Error(
				isset( $data['code'] ) ? (string) $data['code'] : 'mosmcp_rest_request_failed',
				isset( $data['message'] ) ? (string) $data['message'] : __( 'The REST request failed.', 'miniorange-secure-mcp-server' ),
				array( 'status' => $status )
			);
		}

		$trimmed = self::trim_response( $data );

		if ( is_wp_error( $trimmed ) ) {
			return $trimmed;
		}

		$result = array(
			'route'  => $entry['template'],
			'method' => $method,
			'status' => $status,
			'data'   => $trimmed['data'],
		);

		if ( isset( $headers['X-WP-Total'] ) ) {
			$result['total'] = (int) $headers['X-WP-Total'];
		}

		if ( isset( $headers['X-WP-TotalPages'] ) ) {
			$result['total_pages'] = (int) $headers['X-WP-TotalPages'];
		}

		if ( '' !== $trimmed['notice'] ) {
			$result['notice'] = $trimmed['notice'];
		}

		return $result;
	}

	/**
	 * Resolves a route and confirms this request may call it with this method.
	 *
	 * @param string $route  Route template or concrete path.
	 * @param string $method HTTP method.
	 * @return array<string, mixed>|WP_Error Entry plus canonical method, or an error.
	 */
	private static function authorize( $route, $method ) {
		$entry = self::find( $route );

		if ( null === $entry ) {
			return new WP_Error(
				'mosmcp_rest_route_unknown',
				__( 'No REST route on this site matches that path. Use the route listing tool to find one.', 'miniorange-secure-mcp-server' )
			);
		}

		$blocked = Route_Registry::blocked( $entry['pattern'], $method );

		if ( '' !== $blocked ) {
			return new WP_Error( 'mosmcp_rest_route_blocked', $blocked );
		}

		if ( ! isset( $entry['accepts'][ $method ] ) ) {
			return new WP_Error(
				'mosmcp_rest_method_unsupported',
				sprintf(
					/* translators: 1: HTTP method, 2: route template. */
					__( '%1$s is not supported on %2$s.', 'miniorange-secure-mcp-server' ),
					$method,
					$entry['template']
				)
			);
		}

		$canonical = $entry['accepts'][ $method ];

		if ( ! self::granted( $entry['template'], $canonical ) ) {
			return new WP_Error(
				'mosmcp_rest_route_denied',
				sprintf(
					/* translators: 1: HTTP method, 2: route template. */
					__( 'This connection is not granted %1$s on %2$s.', 'miniorange-secure-mcp-server' ),
					$canonical,
					$entry['template']
				)
			);
		}

		return array(
			'entry'     => $entry,
			'canonical' => $canonical,
		);
	}

	/**
	 * Finds a route entry by template or by concrete path.
	 *
	 * @param string $route Route template or concrete path.
	 * @return array<string, mixed>|null
	 */
	private static function find( $route ) {
		$path = Route_Registry::normalize_path( $route );

		if ( '' === $path ) {
			return null;
		}

		$by_template = Route_Registry::find_by_template( $path );

		return $by_template ?? Route_Registry::resolve( $path );
	}

	/**
	 * Returns the gated route table narrowed to what this request may reach.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function visible_routes() {
		$visible = array();

		foreach ( Route_Registry::routes() as $entry ) {
			$methods = array();

			foreach ( $entry['methods'] as $method ) {
				if ( self::granted( $entry['template'], $method ) ) {
					$methods[] = $method;
				}
			}

			if ( empty( $methods ) ) {
				continue;
			}

			$entry['methods'] = $methods;
			$visible[]        = $entry;
		}

		return $visible;
	}

	/**
	 * Returns the site-wide write kill switches, merged over their enabled defaults.
	 *
	 * @return array{enable_create: bool, enable_update: bool, enable_delete: bool}
	 */
	public static function settings() {
		$defaults = array(
			'enable_create' => true,
			'enable_update' => true,
			'enable_delete' => true,
		);

		$stored = get_option( self::SETTINGS_OPTION, array() );

		return array_merge( $defaults, is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Classifies a canonical grant method as the write operation it represents.
	 *
	 * Keyed off the canonical method Route_Registry already resolved per handler, so a
	 * collection's lone 'POST' (create) and an item's EDITABLE handler collapsed to
	 * 'PATCH' (update) are told apart the same way grants already are.
	 *
	 * @param string $canonical Canonical HTTP method.
	 * @return string One of 'create', 'update', 'delete', or '' for reads.
	 */
	private static function verb_category( $canonical ) {
		switch ( $canonical ) {
			case 'POST':
				return 'create';
			case 'PUT':
			case 'PATCH':
				return 'update';
			case 'DELETE':
				return 'delete';
			default:
				return '';
		}
	}

	/**
	 * Checks a canonical method against the site-wide write kill switches.
	 *
	 * This is a coarse, emergency override on top of the per-route NHI grant, not a
	 * replacement for it: flipping all three switches back on restores exactly the
	 * grants already configured, nothing more.
	 *
	 * @param string $canonical Canonical HTTP method.
	 * @return string Empty string when allowed, otherwise the reason it is disabled.
	 */
	private static function verb_disabled( $canonical ) {
		$category = self::verb_category( $canonical );

		if ( '' === $category ) {
			return '';
		}

		$settings = self::settings();

		if ( ! empty( $settings[ 'enable_' . $category ] ) ) {
			return '';
		}

		switch ( $category ) {
			case 'create':
				return __( 'Create operations are disabled site-wide for the REST bridge.', 'miniorange-secure-mcp-server' );
			case 'update':
				return __( 'Update operations are disabled site-wide for the REST bridge.', 'miniorange-secure-mcp-server' );
			default:
				return __( 'Delete operations are disabled site-wide for the REST bridge.', 'miniorange-secure-mcp-server' );
		}
	}

	/**
	 * Checks a route template and canonical method against this request's grants.
	 *
	 * @param string $template Route template.
	 * @param string $method   Canonical HTTP method.
	 * @return bool
	 */
	private static function granted( $template, $method ) {
		if ( null === self::$allowed_routes ) {
			return true;
		}

		foreach ( self::$allowed_routes as $grant ) {
			if ( self::grant_matches( (string) $grant, $template, $method ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Matches one grant string ("METHOD /template", with an optional trailing '/*').
	 *
	 * @param string $grant    Grant string.
	 * @param string $template Route template.
	 * @param string $method   Canonical HTTP method.
	 * @return bool
	 */
	public static function grant_matches( $grant, $template, $method ) {
		$parts = preg_split( '/\s+/', trim( $grant ), 2 );

		if ( ! is_array( $parts ) || 2 !== count( $parts ) ) {
			return false;
		}

		list( $grant_method, $grant_path ) = $parts;
		$grant_method                      = strtoupper( $grant_method );

		if ( '*' !== $grant_method && $grant_method !== $method ) {
			return false;
		}

		if ( $grant_path === $template ) {
			return true;
		}

		if ( '/*' === substr( $grant_path, -2 ) ) {
			return 0 === strpos( $template, substr( $grant_path, 0, -1 ) );
		}

		return false;
	}

	/**
	 * Validates and normalizes one grant string ("METHOD /path", '/*' allowed at the end).
	 *
	 * Grants the hard blocklist would refuse are rejected here rather than stored, so a
	 * saved policy never contains a rule that could not take effect.
	 *
	 * @param string $grant Raw grant string.
	 * @return string Normalized grant, or an empty string when invalid.
	 */
	public static function normalize_grant( $grant ) {
		$parts = preg_split( '/\s+/', trim( (string) $grant ), 2 );

		if ( ! is_array( $parts ) || 2 !== count( $parts ) ) {
			return '';
		}

		$method = strtoupper( $parts[0] );
		$path   = $parts[1];

		if ( ! in_array( $method, array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE', '*' ), true ) ) {
			return '';
		}

		if ( 0 !== strpos( $path, '/' ) ) {
			return '';
		}

		$target = ( '/*' === substr( $path, -2 ) ) ? substr( $path, 0, -1 ) : $path;

		// For an exact grant (not a wildcard) on a route that currently exists, store
		// its real canonical method rather than whatever wire verb was typed. Without
		// this, a grant for PUT or POST on an item route whose canonical is PATCH (an
		// EDITABLE handler collapses all three) would save cleanly but could never
		// match anything at authorization time — a silently dead grant.
		if ( '*' !== $method && $path === $target ) {
			$entry = Route_Registry::find_by_template( $path );

			if ( null !== $entry ) {
				if ( ! isset( $entry['accepts'][ $method ] ) ) {
					return '';
				}

				$method = $entry['accepts'][ $method ];
			}
		}

		// A '*' method grant covers writes, so judge it by the strictest verb it implies.
		if ( '' !== Route_Registry::blocked( $target, '*' === $method ? 'POST' : $method ) ) {
			return '';
		}

		return $method . ' ' . $path;
	}

	/**
	 * Extracts the query parameters an agent appended to the route string.
	 *
	 * @param string $route Route as supplied by the caller.
	 * @return array<string, mixed>
	 */
	private static function query_from_route( $route ) {
		$position = strpos( (string) $route, '?' );

		if ( false === $position ) {
			return array();
		}

		$query = array();
		parse_str( substr( (string) $route, $position + 1 ), $query );

		return is_array( $query ) ? $query : array();
	}

	/**
	 * Lists the placeholders a route template carries.
	 *
	 * @param string $template Route template.
	 * @return list<string>
	 */
	private static function path_params( $template ) {
		$matches = array();
		preg_match_all( '/\{(\w+)\}/', $template, $matches );

		return isset( $matches[1] ) ? array_values( $matches[1] ) : array();
	}

	/**
	 * Flattens an endpoint's registered args into a compact parameter description.
	 *
	 * @param array<string, mixed> $args Endpoint args.
	 * @return array<string, array<string, mixed>>
	 */
	public static function describe_args( array $args ) {
		$described = array();

		foreach ( $args as $name => $arg ) {
			if ( ! is_array( $arg ) ) {
				continue;
			}

			$type = isset( $arg['type'] ) ? $arg['type'] : 'string';

			// WP core's "rendered content" fields (title, content, excerpt, and any
			// custom field following the same convention) declare type strictly as
			// 'object' with a 'raw' sub-property, but their own prepare_item_for_database()
			// also accepts a plain string as shorthand for {raw: <string>} — confirmed by
			// core setting 'validate_callback' => null on exactly these args, deliberately
			// opting out of standard schema validation so this dual handling can happen in
			// code instead (class-wp-rest-posts-controller.php). The declared schema alone
			// doesn't say any of this, so detect the convention (object + a 'raw' property)
			// and widen accordingly, rather than only trusting what WP advertises — a
			// promoted ability enforces its schema for real and would otherwise reject a
			// plain string the live endpoint has always accepted.
			if ( 'object' === $type && isset( $arg['properties']['raw'] ) ) {
				$type = array( 'string', 'object' );
			}

			// A small number of args are declared as an honest JSON Schema union, e.g.
			// array('string', 'object'); keep the full set rather than collapsing to
			// whichever WP happened to list first.
			$described[ $name ] = array(
				'type'        => is_array( $type ) ? array_values( $type ) : (string) $type,
				'required'    => ! empty( $arg['required'] ),
				'description' => isset( $arg['description'] ) ? (string) $arg['description'] : '',
			);

			foreach ( array( 'enum', 'default', 'format', 'minimum', 'maximum', 'items' ) as $key ) {
				if ( isset( $arg[ $key ] ) ) {
					$described[ $name ][ $key ] = $arg[ $key ];
				}
			}
		}

		return $described;
	}

	/**
	 * Whether an array is a list; the plugin still supports PHP 7.4, so array_is_list() is out.
	 *
	 * @param array<mixed> $value Array to test.
	 * @return bool
	 */
	private static function is_list( array $value ) {
		if ( array() === $value ) {
			return true;
		}

		return array_keys( $value ) === range( 0, count( $value ) - 1 );
	}

	/**
	 * Keeps a response within the size budget, trimming list results rather than failing.
	 *
	 * @param mixed $data Response data.
	 * @return array{data: mixed, notice: string}|WP_Error
	 */
	private static function trim_response( $data ) {
		$encoded = wp_json_encode( $data );

		if ( ! is_string( $encoded ) || strlen( $encoded ) <= self::MAX_RESPONSE_BYTES ) {
			return array(
				'data'   => $data,
				'notice' => '',
			);
		}

		if ( ! is_array( $data ) || ! self::is_list( $data ) ) {
			return new WP_Error(
				'mosmcp_rest_response_too_large',
				__( 'The response is too large to return. Retry with "_fields" set to just the fields you need.', 'miniorange-secure-mcp-server' )
			);
		}

		$total = count( $data );

		while ( count( $data ) > 1 ) {
			$data    = array_slice( $data, 0, (int) floor( count( $data ) / 2 ) );
			$encoded = wp_json_encode( $data );

			if ( is_string( $encoded ) && strlen( $encoded ) <= self::MAX_RESPONSE_BYTES ) {
				break;
			}
		}

		// Halving stops at one item whether or not that item fits — a single oversized
		// item (e.g. one post with a huge body) would otherwise ship over budget anyway,
		// quietly breaking the ceiling this function exists to enforce.
		if ( 1 === count( $data ) && is_string( $encoded ) && strlen( $encoded ) > self::MAX_RESPONSE_BYTES ) {
			return new WP_Error(
				'mosmcp_rest_response_too_large',
				__( 'The response is too large to return, and it contains only one item, so there is nothing left to trim. Retry with "_fields" set to just the fields you need.', 'miniorange-secure-mcp-server' )
			);
		}

		return array(
			'data'   => $data,
			'notice' => sprintf(
				/* translators: 1: number of items returned, 2: number of items on this page. */
				__( 'Response was too large, so only %1$d of %2$d items are shown. Retry with "_fields", or a smaller "per_page".', 'miniorange-secure-mcp-server' ),
				count( $data ),
				$total
			),
		);
	}
}
