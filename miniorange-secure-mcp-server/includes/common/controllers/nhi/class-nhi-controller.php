<?php
/**
 * Handles NHI CRUD REST endpoints, the current-user access view, and the roles list.
 *
 * @package Miniorange_Secure_MCP_Server
 */

namespace MoSMCP\Common\Controllers\NHI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MoSMCP\Common\Controllers\Abstract_Admin_Controller;
use MoSMCP\Common\Repositories\NHI_Store;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Class NHI_Controller
 *
 * Admins create NHIs and, per WordPress role, choose which abilities each one
 * permits (stored as normalized grants). A request is governed by the union of
 * every enabled NHI's grants that match the connecting user's role(s). Admin CRUD
 * requires `manage_options`; the member access view and roles list have their own
 * permission checks.
 */
class NHI_Controller extends Abstract_Admin_Controller {

	/**
	 * Permission callback for member-facing endpoints: any logged-in user.
	 *
	 * @return bool
	 */
	public static function check_logged_in() {
		return is_user_logged_in();
	}

	/**
	 * Returns all NHIs (each with its role => abilities map).
	 *
	 * @return WP_REST_Response
	 */
	public static function list_clients() {
		return new WP_REST_Response( NHI_Store::list_all(), 200 );
	}

	/**
	 * Creates a new NHI.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_client( WP_REST_Request $request ) {
		$body = $request->get_json_params();

		if ( ! is_array( $body ) ) {
			return new WP_Error( 'mosmcp_bad_request', __( 'Invalid request body.', 'miniorange-secure-mcp-server' ), array( 'status' => 400 ) );
		}

		$name = isset( $body['name'] ) ? sanitize_text_field( (string) $body['name'] ) : '';

		if ( '' === $name ) {
			return new WP_Error( 'mosmcp_bad_request', __( 'Name is required.', 'miniorange-secure-mcp-server' ), array( 'status' => 400 ) );
		}

		$map = self::sanitize_role_ability_map( isset( $body['role_ability_map'] ) ? $body['role_ability_map'] : array() );

		// Enforce tool exclusivity: each (ability, role) pair may belong to at most one NHI.
		$conflicts = NHI_Store::abilities_owned_by_others( 0, $map );
		if ( ! empty( $conflicts ) ) {
			return self::conflict_error( $conflicts );
		}

		$result = NHI_Store::create(
			array(
				'name'             => $name,
				'role_ability_map' => $map,
			)
		);

		if ( false === $result ) {
			return new WP_Error( 'mosmcp_create_failed', __( 'Failed to create NHI.', 'miniorange-secure-mcp-server' ), array( 'status' => 500 ) );
		}

		return new WP_REST_Response(
			array(
				'created' => true,
				'uuid'    => $result['uuid'],
			),
			201
		);
	}

	/**
	 * Updates `is_enabled`, `name`, and/or `role_ability_map` for one NHI.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_client( WP_REST_Request $request ) {
		$id = self::resolve_id_or_404( (string) $request->get_param( 'uuid' ) );

		if ( $id instanceof WP_Error ) {
			return $id;
		}

		$body = $request->get_json_params();

		if ( ! is_array( $body ) ) {
			return new WP_Error( 'mosmcp_bad_request', __( 'Invalid request body.', 'miniorange-secure-mcp-server' ), array( 'status' => 400 ) );
		}

		$updates = array();

		if ( array_key_exists( 'is_enabled', $body ) ) {
			$updates['is_enabled'] = $body['is_enabled'] ? 1 : 0;
		}

		if ( array_key_exists( 'name', $body ) ) {
			$updates['name'] = sanitize_text_field( (string) $body['name'] );
		}

		if ( array_key_exists( 'role_ability_map', $body ) ) {
			$updates['role_ability_map'] = self::sanitize_role_ability_map( $body['role_ability_map'] );
		}

		if ( empty( $updates ) ) {
			return new WP_REST_Response( array( 'updated' => false ), 200 );
		}

		// Enforce tool exclusivity when the ability map is being changed.
		if ( isset( $updates['role_ability_map'] ) ) {
			$conflicts = NHI_Store::abilities_owned_by_others( $id, $updates['role_ability_map'] );
			if ( ! empty( $conflicts ) ) {
				return self::conflict_error( $conflicts );
			}
		}

		$ok = NHI_Store::update( $id, $updates );

		if ( ! $ok ) {
			return new WP_Error( 'mosmcp_update_failed', __( 'Failed to update NHI.', 'miniorange-secure-mcp-server' ), array( 'status' => 500 ) );
		}

		return new WP_REST_Response( array( 'updated' => true ), 200 );
	}

	/**
	 * Deletes an NHI.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function delete_client( WP_REST_Request $request ) {
		$id = self::resolve_id_or_404( (string) $request->get_param( 'uuid' ) );

		if ( $id instanceof WP_Error ) {
			return $id;
		}

		if ( ! NHI_Store::delete( $id ) ) {
			return new WP_Error( 'mosmcp_delete_failed', __( 'Failed to delete NHI.', 'miniorange-secure-mcp-server' ), array( 'status' => 500 ) );
		}

		return new WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	/**
	 * Returns the abilities available to the current user, grouped by the enabled
	 * NHIs that grant them. Drives the member "tools available to you" view.
	 *
	 * @return WP_REST_Response
	 */
	public static function my_access() {
		$roles   = array();
		$current = wp_get_current_user();
		if ( $current && isset( $current->roles ) && is_array( $current->roles ) ) {
			$roles = $current->roles;
		}

		$sources      = NHI_Store::access_for_roles( $roles );
		$unrestricted = false;
		$abilities    = array();

		foreach ( $sources as $source ) {
			foreach ( $source['abilities'] as $ability ) {
				if ( '*' === $ability ) {
					$unrestricted = true;
					continue;
				}
				$abilities[ $ability ] = true;
			}
		}

		return new WP_REST_Response(
			array(
				'roles'        => array_values( $roles ),
				'unrestricted' => $unrestricted,
				'abilities'    => array_keys( $abilities ),
				'sources'      => $sources,
			),
			200
		);
	}

	/**
	 * Returns the connected users this NHI reaches (users with an active token
	 * whose role(s) the NHI grants abilities to). Admin-only.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function members( WP_REST_Request $request ) {
		$id = self::resolve_id_or_404( (string) $request->get_param( 'uuid' ) );

		if ( $id instanceof WP_Error ) {
			return $id;
		}

		return new WP_REST_Response( NHI_Store::members_for_nhi( $id ), 200 );
	}

	/**
	 * Returns the site's WordPress roles with their granted capabilities, for the
	 * role-ability matrix and its capability-conflict detection.
	 *
	 * @return WP_REST_Response
	 */
	public static function list_roles() {
		$roles = array();

		$wp_roles = wp_roles();
		if ( $wp_roles && is_array( $wp_roles->roles ) ) {
			foreach ( $wp_roles->roles as $slug => $role ) {
				$caps = isset( $role['capabilities'] ) && is_array( $role['capabilities'] ) ? array_filter( $role['capabilities'] ) : array();
				$roles[] = array(
					'slug'         => (string) $slug,
					'name'         => isset( $role['name'] ) ? (string) $role['name'] : (string) $slug,
					'capabilities' => (object) $caps,
				);
			}
		}

		return new WP_REST_Response( $roles, 200 );
	}

	/**
	 * Returns a map of every explicitly-assigned ability to the NHI that owns it.
	 *
	 * Used by the frontend ability picker to mark tools that are already taken,
	 * so the admin cannot accidentally assign them to a second NHI.
	 *
	 * @return WP_REST_Response
	 */
	public static function ability_owners() {
		return new WP_REST_Response( NHI_Store::all_ability_owners(), 200 );
	}

	/**
	 * Sanitizes a raw role => abilities map from a request body.
	 *
	 * Drops non-array ability lists and empty role/ability values; de-duplicates.
	 *
	 * @param mixed $raw The raw value from the request.
	 * @return array<string, string[]>
	 */
	private static function sanitize_role_ability_map( $raw ) {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$map = array();
		foreach ( $raw as $role => $abilities ) {
			$role = sanitize_text_field( (string) $role );
			if ( '' === $role || ! is_array( $abilities ) ) {
				continue;
			}

			$clean = array();
			foreach ( $abilities as $ability ) {
				$ability = sanitize_text_field( (string) $ability );
				if ( '' !== $ability ) {
					$clean[] = $ability;
				}
			}

			$map[ $role ] = array_values( array_unique( $clean ) );
		}

		return $map;
	}

	/**
	 * Resolves a public UUID to its internal id, or a 404 WP_Error when not found.
	 *
	 * @param string $uuid The public NHI UUID.
	 * @return int|WP_Error
	 */
	private static function resolve_id_or_404( $uuid ) {
		$id = NHI_Store::find_id_by_uuid( $uuid );

		if ( null === $id ) {
			return new WP_Error( 'mosmcp_not_found', __( 'NHI not found.', 'miniorange-secure-mcp-server' ), array( 'status' => 404 ) );
		}

		return $id;
	}

	/**
	 * Builds the 409 WP_Error returned when a role_ability_map assigns an
	 * (ability, role) pair another enabled NHI already owns.
	 *
	 * @param array<string, array{uuid:string,name:string}> $conflicts Conflicting pairs keyed "ability::role".
	 * @return WP_Error
	 */
	private static function conflict_error( array $conflicts ) {
		return new WP_Error(
			'mosmcp_ability_conflict',
			__( 'One or more abilities are already assigned to another NHI.', 'miniorange-secure-mcp-server' ),
			array(
				'status'    => 409,
				'conflicts' => $conflicts,
			)
		);
	}
}
