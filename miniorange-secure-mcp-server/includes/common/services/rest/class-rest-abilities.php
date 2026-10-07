<?php
/**
 * Registers the three REST bridge abilities with the Abilities API.
 *
 * @package Miniorange_Secure_MCP_Server
 */

namespace MoSMCP\Common\Services\Rest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MoSMCP\Abilities\Ability;
use MoSMCP\Abilities\Ability_Registrar;
use MoSMCP\Abilities\Naming;

/**
 * Class Rest_Abilities
 *
 * Exposes the WordPress REST API as three abilities rather than one tool per route:
 * a site's route table runs to hundreds of route/method pairs, which would blow past
 * every client's tool cap. Discovery is progressive (list, then describe, then call)
 * and governance lives on the routes themselves, not on these three tool names.
 */
class Rest_Abilities {

	/**
	 * Authored category slug for the bridge abilities.
	 */
	const CATEGORY = 'mosmcp-rest';

	/**
	 * Capability floor for the bridge. The real gates are the NHI's route grants and
	 * the endpoint's own permission_callback, which runs against the token's user.
	 */
	const CAPABILITY = 'edit_posts';

	/**
	 * Hooks category and ability registration.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ) );
	}

	/**
	 * Registers the bridge category.
	 *
	 * @return void
	 */
	public static function register_category() {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		Naming::register_category(
			self::CATEGORY,
			array(
				'label'       => __( 'REST API', 'miniorange-secure-mcp-server' ),
				'description' => __( 'Discover and call the WordPress REST API routes this connection has been granted.', 'miniorange-secure-mcp-server' ),
			)
		);
	}

	/**
	 * Registers the three bridge abilities.
	 *
	 * @return void
	 */
	public static function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		foreach ( array( self::list_routes(), self::describe_route(), self::call() ) as $ability ) {
			Ability_Registrar::register( $ability );
		}
	}

	/**
	 * Defines the mosmcp/rest-list-routes ability.
	 *
	 * @return Ability
	 */
	private static function list_routes() {
		return new Ability(
			'mosmcp/rest-list-routes',
			array(
				'label'        => __( 'List REST Routes', 'miniorange-secure-mcp-server' ),
				'description'  => __( 'Lists the WordPress REST API routes this connection is allowed to call, with the HTTP methods allowed on each. Read-only. Start here, then describe a route to learn its parameters before calling it. Routes this connection has not been granted are not listed.', 'miniorange-secure-mcp-server' ),
				'category'     => self::CATEGORY,
				'capability'   => self::CAPABILITY,
				'annotations'  => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
					'open_world'  => false,
				),
				'execute'      => array( Rest_Bridge::class, 'list_routes' ),
				'input_schema' => array(
					'type'                 => 'object',
					'properties'           => array(
						'namespace' => array(
							'type'        => 'string',
							'description' => __( 'Return only routes in this REST namespace, for example "wp/v2" or "wc/v3".', 'miniorange-secure-mcp-server' ),
						),
						'search'    => array(
							'type'        => 'string',
							'description' => __( 'Return only routes whose path contains this text, for example "media".', 'miniorange-secure-mcp-server' ),
						),
						'method'    => array(
							'type'        => 'string',
							'enum'        => array( 'GET', 'POST', 'PATCH', 'PUT', 'DELETE' ),
							'description' => __( 'Return only routes that allow this HTTP method.', 'miniorange-secure-mcp-server' ),
						),
						'page'      => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'default'     => 1,
							'description' => __( 'Page of results to return.', 'miniorange-secure-mcp-server' ),
						),
						'per_page'  => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'maximum'     => 200,
							'default'     => 50,
							'description' => __( 'Number of routes per page.', 'miniorange-secure-mcp-server' ),
						),
					),
					'additionalProperties' => false,
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/rest-describe-route ability.
	 *
	 * @return Ability
	 */
	private static function describe_route() {
		return new Ability(
			'mosmcp/rest-describe-route',
			array(
				'label'        => __( 'Describe REST Route', 'miniorange-secure-mcp-server' ),
				'description'  => __( 'Returns the parameters one REST route accepts for one HTTP method: name, type, whether it is required, allowed values and defaults. Read-only. Call this before calling a route you have not used before, so the request is built correctly the first time.', 'miniorange-secure-mcp-server' ),
				'category'     => self::CATEGORY,
				'capability'   => self::CAPABILITY,
				'annotations'  => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
					'open_world'  => false,
				),
				'execute'      => array( Rest_Bridge::class, 'describe_route' ),
				'input_schema' => array(
					'type'                 => 'object',
					'properties'           => array(
						'route'  => array(
							'type'        => 'string',
							'description' => __( 'Route to describe, exactly as the route listing returned it, for example "/wp/v2/posts" or "/wp/v2/posts/{id}".', 'miniorange-secure-mcp-server' ),
						),
						'method' => array(
							'type'        => 'string',
							'enum'        => array( 'GET', 'POST', 'PATCH', 'PUT', 'DELETE' ),
							'description' => __( 'HTTP method to describe the route for.', 'miniorange-secure-mcp-server' ),
						),
					),
					'required'             => array( 'route', 'method' ),
					'additionalProperties' => false,
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/rest-call ability.
	 *
	 * @return Ability
	 */
	private static function call() {
		return new Ability(
			'mosmcp/rest-call',
			array(
				'label'        => __( 'Call REST Route', 'miniorange-secure-mcp-server' ),
				'description'  => __( 'Calls a WordPress REST API route and returns its response. Can create, update and delete content, so confirm the route and parameters before calling it with a write method. The call is refused unless this connection has been granted that route and method, and the endpoint\'s own permission check also applies. Use "_fields" to keep responses small; large list responses are trimmed.', 'miniorange-secure-mcp-server' ),
				'category'     => self::CATEGORY,
				'capability'   => self::CAPABILITY,
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => false,
					'open_world'  => false,
				),
				'execute'      => array( Rest_Bridge::class, 'call' ),
				'input_schema' => array(
					'type'                 => 'object',
					'properties'           => array(
						'route'   => array(
							'type'        => 'string',
							'description' => __( 'Route to call, with any path placeholders filled in, for example "/wp/v2/posts" or "/wp/v2/posts/42".', 'miniorange-secure-mcp-server' ),
						),
						'method'  => array(
							'type'        => 'string',
							'enum'        => array( 'GET', 'POST', 'PATCH', 'PUT', 'DELETE' ),
							'description' => __( 'HTTP method to use.', 'miniorange-secure-mcp-server' ),
						),
						'params'  => array(
							'type'                 => 'object',
							'description'          => __( 'Request parameters. Query parameters for GET and DELETE, request body for POST, PATCH and PUT.', 'miniorange-secure-mcp-server' ),
							'additionalProperties' => true,
						),
						'_fields' => array(
							'type'        => 'string',
							'description' => __( 'Comma-separated list of response fields to return, for example "id,title,status". Strongly recommended on list routes.', 'miniorange-secure-mcp-server' ),
						),
					),
					'required'             => array( 'route', 'method' ),
					'additionalProperties' => false,
				),
			)
		);
	}
}
