<?php
/**
 * Execute callbacks for the Users ability pack (users, roles, credentials).
 *
 * Business logic is preserved from the reviewed source collection; only naming,
 * text domain, and error-code prefixes were adapted, plus one security hardening:
 * can_grant_role() now enforces a capability-subset check (a caller may only grant
 * a role whose capabilities they already hold). Reserved user-meta keys are refused
 * upstream by the Ability_Registrar guard, so the metadata callbacks never receive
 * capability/role/session keys.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Users;

use WP_Error;
use WP_Query;
use WP_User_Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * This library authors every translatable string under its own fixed text domain
 * ('mosmcp-abilities'). The host plugin remaps them to its own text domain at
 * runtime via Abilities_Library::init(). The domain therefore intentionally will
 * not match any host plugin's slug, so the text-domain-mismatch check is disabled
 * for this file (the library's phpcs.xml.dist allows the domain on the CLI; this
 * directive covers IDE and Plugin Check runs that don't load that ruleset).
 */
// phpcs:disable WordPress.WP.I18n.TextDomainMismatch

/*
 * These abilities intentionally query by post/user/comment meta or taxonomy
 * (and exclude specific IDs) — that is the tool surface the library exposes.
 * The queries are bounded and parameterized, so this performance advisory is
 * accepted here (the sniff is not part of the library's own phpcs.xml.dist; this
 * directive covers Plugin Check, which enforces its own broader standard).
 */
// phpcs:disable WordPress.DB.SlowDBQuery

/**
 * Class Users_Provider
 *
 * Static execute callbacks for user, role, and credential abilities.
 */
class Users_Provider {

	/**
	 * Lists users, optionally filtered by role.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public static function list_users( $input = array() ) {
		$input    = is_array( $input ) ? $input : array();
		$paginate = self::paginate_args( $input );

		$args = array(
			'number' => $paginate['number'],
			'offset' => $paginate['offset'],
			'fields' => 'all',
		);
		if ( ! empty( $input['role'] ) ) {
			$args['role'] = sanitize_key( (string) $input['role'] );
		}

		$query = new WP_User_Query( $args );

		return array(
			'users'    => array_values( array_map( array( __CLASS__, 'user_summary' ), $query->get_results() ) ),
			'total'    => (int) $query->get_total(),
			'page'     => $paginate['page'],
			'per_page' => $paginate['per_page'],
		);
	}

	/**
	 * Gets a user's full profile by ID.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function get_user_by_id( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$id    = isset( $input['id'] ) ? absint( $input['id'] ) : 0;

		$user = $id > 0 ? get_userdata( $id ) : false;
		if ( ! $user ) {
			return new WP_Error( 'mosmcp_user_not_found', __( 'No user was found with that ID.', 'mosmcp-abilities' ) );
		}
		return self::full_user_profile( $user );
	}

	/**
	 * Gets a user's full profile by username.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function get_user_by_username( $input = array() ) {
		$input    = is_array( $input ) ? $input : array();
		$username = isset( $input['username'] ) ? (string) $input['username'] : '';

		$user = '' !== $username ? get_user_by( 'login', $username ) : false;
		if ( ! $user ) {
			return new WP_Error( 'mosmcp_user_not_found', __( 'No user was found with that username.', 'mosmcp-abilities' ) );
		}
		return self::full_user_profile( $user );
	}

	/**
	 * Gets a user's full profile by email.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function get_user_by_email( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$email = isset( $input['email'] ) ? sanitize_email( (string) $input['email'] ) : '';

		$user = '' !== $email ? get_user_by( 'email', $email ) : false;
		if ( ! $user ) {
			return new WP_Error( 'mosmcp_user_not_found', __( 'No user was found with that email address.', 'mosmcp-abilities' ) );
		}
		return self::full_user_profile( $user );
	}

	/**
	 * Searches users by keyword.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function search_users( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$term  = isset( $input['query'] ) ? trim( (string) $input['query'] ) : '';

		if ( '' === $term ) {
			return new WP_Error( 'mosmcp_missing_query', __( 'A search query is required.', 'mosmcp-abilities' ) );
		}

		$paginate = self::paginate_args( $input );

		$query = new WP_User_Query(
			array(
				'search'         => '*' . $term . '*',
				'search_columns' => array( 'user_login', 'user_email', 'display_name', 'user_nicename' ),
				'number'         => $paginate['number'],
				'offset'         => $paginate['offset'],
				'fields'         => 'all',
			)
		);

		return array(
			'users'    => array_values( array_map( array( __CLASS__, 'user_summary' ), $query->get_results() ) ),
			'total'    => (int) $query->get_total(),
			'page'     => $paginate['page'],
			'per_page' => $paginate['per_page'],
		);
	}

	/**
	 * Counts all users.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public static function count_users( $input = array() ) {
		unset( $input );
		require_once ABSPATH . 'wp-admin/includes/user.php';
		$counts = count_users();
		return array( 'total' => (int) $counts['total_users'] );
	}

	/**
	 * Counts users in a given role.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function count_users_by_role( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$role  = isset( $input['role'] ) ? sanitize_key( (string) $input['role'] ) : '';

		if ( '' === $role || ! wp_roles()->is_role( $role ) ) {
			return new WP_Error( 'mosmcp_invalid_role', __( 'That role does not exist.', 'mosmcp-abilities' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/user.php';
		$counts = count_users();

		return array(
			'role'  => $role,
			'count' => isset( $counts['avail_roles'][ $role ] ) ? (int) $counts['avail_roles'][ $role ] : 0,
		);
	}

	/**
	 * Gets a user's email address.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function get_user_email( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$id    = isset( $input['id'] ) ? absint( $input['id'] ) : 0;

		$user = $id > 0 ? get_userdata( $id ) : false;
		if ( ! $user ) {
			return new WP_Error( 'mosmcp_user_not_found', __( 'No user was found with that ID.', 'mosmcp-abilities' ) );
		}

		return array(
			'id'    => $id,
			'email' => (string) $user->user_email,
		);
	}

	/**
	 * Returns non-sensitive user metadata. Reserved keys are refused by the
	 * registrar guard before this callback runs; the all-keys branch also strips
	 * the capability/level/session stores.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function get_user_metadata( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$id    = isset( $input['id'] ) ? absint( $input['id'] ) : 0;

		if ( $id <= 0 || ! get_userdata( $id ) ) {
			return new WP_Error( 'mosmcp_user_not_found', __( 'No user was found with that ID.', 'mosmcp-abilities' ) );
		}

		$meta_key = isset( $input['meta_key'] ) ? sanitize_key( (string) $input['meta_key'] ) : '';

		if ( '' !== $meta_key ) {
			return array(
				'id'   => $id,
				'meta' => (object) array( $meta_key => get_user_meta( $id, $meta_key, false ) ),
			);
		}

		global $wpdb;
		$all       = get_user_meta( $id );
		$sensitive = array( 'session_tokens', $wpdb->prefix . 'capabilities', $wpdb->prefix . 'user_level' );
		foreach ( $sensitive as $key ) {
			unset( $all[ $key ] );
		}

		return array(
			'id'   => $id,
			'meta' => (object) $all,
		);
	}

	/**
	 * Gets a user's registration date.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function get_user_registration_date( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$id    = isset( $input['id'] ) ? absint( $input['id'] ) : 0;

		$user = $id > 0 ? get_userdata( $id ) : false;
		if ( ! $user ) {
			return new WP_Error( 'mosmcp_user_not_found', __( 'No user was found with that ID.', 'mosmcp-abilities' ) );
		}

		return array(
			'id'         => $id,
			'registered' => (string) $user->user_registered,
		);
	}

	/**
	 * Gets a user's avatar URL.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function get_user_avatar_url( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$id    = isset( $input['id'] ) ? absint( $input['id'] ) : 0;

		if ( $id <= 0 || ! get_userdata( $id ) ) {
			return new WP_Error( 'mosmcp_user_not_found', __( 'No user was found with that ID.', 'mosmcp-abilities' ) );
		}

		$size = isset( $input['size'] ) ? absint( $input['size'] ) : 96;

		return array(
			'id'         => $id,
			'avatar_url' => (string) get_avatar_url( $id, array( 'size' => $size ) ),
		);
	}

	/**
	 * Counts posts authored by a user.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function get_user_post_count( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$id    = isset( $input['id'] ) ? absint( $input['id'] ) : 0;

		if ( $id <= 0 || ! get_userdata( $id ) ) {
			return new WP_Error( 'mosmcp_user_not_found', __( 'No user was found with that ID.', 'mosmcp-abilities' ) );
		}

		$post_type = ! empty( $input['post_type'] ) ? sanitize_key( (string) $input['post_type'] ) : 'post';

		return array(
			'id'        => $id,
			'post_type' => $post_type,
			'count'     => (int) count_user_posts( $id, $post_type ),
		);
	}

	/**
	 * Creates a new user account.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function create_user( $input = array() ) {
		$input = is_array( $input ) ? $input : array();

		$login = isset( $input['user_login'] ) ? sanitize_user( (string) $input['user_login'], true ) : '';
		$email = isset( $input['user_email'] ) ? sanitize_email( (string) $input['user_email'] ) : '';

		if ( '' === $login || ! validate_username( $login ) ) {
			return new WP_Error( 'mosmcp_invalid_login', __( 'A valid username is required.', 'mosmcp-abilities' ) );
		}
		if ( '' === $email || ! is_email( $email ) ) {
			return new WP_Error( 'mosmcp_invalid_email', __( 'A valid email address is required.', 'mosmcp-abilities' ) );
		}
		if ( username_exists( $login ) ) {
			return new WP_Error( 'mosmcp_login_exists', __( 'That username is already taken.', 'mosmcp-abilities' ) );
		}
		if ( email_exists( $email ) ) {
			return new WP_Error( 'mosmcp_email_exists', __( 'That email address is already in use.', 'mosmcp-abilities' ) );
		}

		$role = ! empty( $input['role'] ) ? sanitize_key( (string) $input['role'] ) : 'subscriber';
		if ( ! wp_roles()->is_role( $role ) ) {
			return new WP_Error( 'mosmcp_invalid_role', __( 'That role does not exist.', 'mosmcp-abilities' ) );
		}
		if ( ! self::can_grant_role( $role ) ) {
			return new WP_Error( 'mosmcp_cannot_grant_role', __( 'You may only grant a role whose capabilities you already hold.', 'mosmcp-abilities' ) );
		}

		$password = ! empty( $input['password'] ) ? (string) $input['password'] : wp_generate_password( 20 );

		$user_id = wp_insert_user(
			array(
				'user_login' => $login,
				'user_email' => $email,
				'user_pass'  => $password,
				'role'       => $role,
				'first_name' => isset( $input['first_name'] ) ? sanitize_text_field( (string) $input['first_name'] ) : '',
				'last_name'  => isset( $input['last_name'] ) ? sanitize_text_field( (string) $input['last_name'] ) : '',
			)
		);

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$notify = ! isset( $input['send_notification'] ) || (bool) $input['send_notification'];
		if ( $notify ) {
			wp_new_user_notification( $user_id, null, 'user' );
		}

		return self::full_user_profile( get_userdata( $user_id ) );
	}

	/**
	 * Updates a user's display name.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function update_user_profile( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$id    = isset( $input['id'] ) ? absint( $input['id'] ) : 0;
		$value = isset( $input['display_name'] ) ? sanitize_text_field( (string) $input['display_name'] ) : '';

		return self::update_single_user_field( $id, 'display_name', $value );
	}

	/**
	 * Updates a user's email address.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function update_user_email( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$id    = isset( $input['id'] ) ? absint( $input['id'] ) : 0;
		$email = isset( $input['user_email'] ) ? sanitize_email( (string) $input['user_email'] ) : '';

		if ( '' === $email || ! is_email( $email ) ) {
			return new WP_Error( 'mosmcp_invalid_email', __( 'That is not a valid email address.', 'mosmcp-abilities' ) );
		}

		return self::update_single_user_field( $id, 'user_email', $email );
	}

	/**
	 * Updates a user's first name.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function update_user_first_name( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$id    = isset( $input['id'] ) ? absint( $input['id'] ) : 0;
		$value = isset( $input['first_name'] ) ? sanitize_text_field( (string) $input['first_name'] ) : '';

		return self::update_single_user_field( $id, 'first_name', $value );
	}

	/**
	 * Updates a user's last name.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function update_user_last_name( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$id    = isset( $input['id'] ) ? absint( $input['id'] ) : 0;
		$value = isset( $input['last_name'] ) ? sanitize_text_field( (string) $input['last_name'] ) : '';

		return self::update_single_user_field( $id, 'last_name', $value );
	}

	/**
	 * Updates a user's nickname.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function update_user_nickname( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$id    = isset( $input['id'] ) ? absint( $input['id'] ) : 0;
		$value = isset( $input['nickname'] ) ? sanitize_text_field( (string) $input['nickname'] ) : '';

		return self::update_single_user_field( $id, 'nickname', $value );
	}

	/**
	 * Updates a user's website URL.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function update_user_website_url( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$id    = isset( $input['id'] ) ? absint( $input['id'] ) : 0;
		$value = isset( $input['user_url'] ) ? esc_url_raw( (string) $input['user_url'] ) : '';

		return self::update_single_user_field( $id, 'user_url', $value );
	}

	/**
	 * Updates a user's biography.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function update_user_biography( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$id    = isset( $input['id'] ) ? absint( $input['id'] ) : 0;
		$value = isset( $input['description'] ) ? wp_kses_post( (string) $input['description'] ) : '';

		return self::update_single_user_field( $id, 'description', $value );
	}

	/**
	 * Sets a custom (non-reserved) user meta key. Reserved keys are refused by the
	 * registrar guard before this callback runs.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function update_user_metadata( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$id    = isset( $input['id'] ) ? absint( $input['id'] ) : 0;

		if ( $id <= 0 || ! get_userdata( $id ) ) {
			return new WP_Error( 'mosmcp_user_not_found', __( 'No user was found with that ID.', 'mosmcp-abilities' ) );
		}
		if ( ! current_user_can( 'edit_user', $id ) ) {
			return new WP_Error( 'mosmcp_cannot_edit', __( 'You are not allowed to edit this user.', 'mosmcp-abilities' ) );
		}

		$meta_key = isset( $input['meta_key'] ) ? sanitize_key( (string) $input['meta_key'] ) : '';
		if ( '' === $meta_key ) {
			return new WP_Error( 'mosmcp_missing_meta_key', __( 'A meta_key is required.', 'mosmcp-abilities' ) );
		}

		// wp_slash because the metadata API unslashes on the way in; a caller's value
		// would otherwise lose a level of backslashes before it is stored.
		update_user_meta( $id, $meta_key, wp_slash( $input['meta_value'] ) );

		return array(
			'id'       => $id,
			'meta_key' => $meta_key,
			'updated'  => true,
		);
	}

	/**
	 * Deletes a custom (non-reserved) user meta key. Reserved keys are refused by
	 * the registrar guard before this callback runs.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function delete_user_metadata( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$id    = isset( $input['id'] ) ? absint( $input['id'] ) : 0;

		if ( $id <= 0 || ! get_userdata( $id ) ) {
			return new WP_Error( 'mosmcp_user_not_found', __( 'No user was found with that ID.', 'mosmcp-abilities' ) );
		}
		if ( ! current_user_can( 'edit_user', $id ) ) {
			return new WP_Error( 'mosmcp_cannot_edit', __( 'You are not allowed to edit this user.', 'mosmcp-abilities' ) );
		}

		$meta_key = isset( $input['meta_key'] ) ? sanitize_key( (string) $input['meta_key'] ) : '';
		if ( '' === $meta_key ) {
			return new WP_Error( 'mosmcp_missing_meta_key', __( 'A meta_key is required.', 'mosmcp-abilities' ) );
		}

		delete_user_meta( $id, $meta_key );

		return array(
			'id'       => $id,
			'meta_key' => $meta_key,
			'deleted'  => true,
		);
	}

	/**
	 * Permanently deletes a user account.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function delete_user( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$id    = isset( $input['id'] ) ? absint( $input['id'] ) : 0;

		if ( $id <= 0 || ! get_userdata( $id ) ) {
			return new WP_Error( 'mosmcp_user_not_found', __( 'No user was found with that ID.', 'mosmcp-abilities' ) );
		}
		if ( get_current_user_id() === $id ) {
			return new WP_Error( 'mosmcp_cannot_delete_self', __( 'You cannot delete your own account through this ability.', 'mosmcp-abilities' ) );
		}
		if ( ! current_user_can( 'delete_user', $id ) ) {
			return new WP_Error( 'mosmcp_cannot_delete', __( 'You are not allowed to delete this user.', 'mosmcp-abilities' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/user.php';

		$reassign = isset( $input['reassign'] ) ? absint( $input['reassign'] ) : null;

		if ( ! wp_delete_user( $id, $reassign ) ) {
			return new WP_Error( 'mosmcp_delete_failed', __( 'Failed to delete the user.', 'mosmcp-abilities' ) );
		}

		return array(
			'id'      => $id,
			'deleted' => true,
		);
	}

	/**
	 * Checks whether a username is available.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public static function check_username_availability( $input = array() ) {
		$input    = is_array( $input ) ? $input : array();
		$username = isset( $input['username'] ) ? sanitize_user( (string) $input['username'], true ) : '';

		return array(
			'username'  => $username,
			'available' => '' !== $username && ! username_exists( $username ),
		);
	}

	/**
	 * Checks whether an email is available.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public static function check_email_availability( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$email = isset( $input['email'] ) ? sanitize_email( (string) $input['email'] ) : '';

		return array(
			'email'     => $email,
			'available' => '' !== $email && ! email_exists( $email ),
		);
	}

	/**
	 * Gets a user's admin locale.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function get_user_locale( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$id    = isset( $input['id'] ) ? absint( $input['id'] ) : 0;

		if ( $id <= 0 || ! get_userdata( $id ) ) {
			return new WP_Error( 'mosmcp_user_not_found', __( 'No user was found with that ID.', 'mosmcp-abilities' ) );
		}

		return array(
			'id'     => $id,
			'locale' => (string) get_user_locale( $id ),
		);
	}

	/**
	 * Updates a user's admin locale.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function update_user_locale( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$id    = isset( $input['id'] ) ? absint( $input['id'] ) : 0;

		if ( $id <= 0 || ! get_userdata( $id ) ) {
			return new WP_Error( 'mosmcp_user_not_found', __( 'No user was found with that ID.', 'mosmcp-abilities' ) );
		}
		if ( ! current_user_can( 'edit_user', $id ) ) {
			return new WP_Error( 'mosmcp_cannot_edit', __( 'You are not allowed to edit this user.', 'mosmcp-abilities' ) );
		}

		$locale = isset( $input['locale'] ) ? sanitize_text_field( (string) $input['locale'] ) : '';
		update_user_meta( $id, 'locale', wp_slash( $locale ) );

		return array(
			'id'     => $id,
			'locale' => $locale,
		);
	}

	/**
	 * Resets a user's password and invalidates their sessions.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function change_user_password( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$id    = isset( $input['id'] ) ? absint( $input['id'] ) : 0;

		if ( $id <= 0 || ! get_userdata( $id ) ) {
			return new WP_Error( 'mosmcp_user_not_found', __( 'No user was found with that ID.', 'mosmcp-abilities' ) );
		}
		if ( ! current_user_can( 'edit_user', $id ) ) {
			return new WP_Error( 'mosmcp_cannot_edit', __( 'You are not allowed to change this user\'s password.', 'mosmcp-abilities' ) );
		}

		$password = isset( $input['new_password'] ) ? (string) $input['new_password'] : '';
		if ( strlen( $password ) < 8 ) {
			return new WP_Error( 'mosmcp_password_too_short', __( 'The new password must be at least 8 characters.', 'mosmcp-abilities' ) );
		}

		wp_set_password( $password, $id );

		return array(
			'id'      => $id,
			'changed' => true,
		);
	}

	/**
	 * Triggers WordPress's native password-reset email for a user.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function send_password_reset_link( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$id    = isset( $input['id'] ) ? absint( $input['id'] ) : 0;

		$user = $id > 0 ? get_userdata( $id ) : false;
		if ( ! $user ) {
			return new WP_Error( 'mosmcp_user_not_found', __( 'No user was found with that ID.', 'mosmcp-abilities' ) );
		}
		if ( ! current_user_can( 'edit_user', $id ) ) {
			return new WP_Error( 'mosmcp_cannot_edit', __( 'You are not allowed to reset this user\'s password.', 'mosmcp-abilities' ) );
		}

		$key = get_password_reset_key( $user );
		if ( is_wp_error( $key ) ) {
			return $key;
		}

		$reset_url = network_site_url( 'wp-login.php?action=rp&key=' . $key . '&login=' . rawurlencode( $user->user_login ), 'login' );

		$message  = __( 'Someone has requested a password reset for the following account:', 'mosmcp-abilities' ) . "\r\n\r\n";
		$message .= network_home_url( '/' ) . "\r\n\r\n";
		/* translators: %s: user login. */
		$message .= sprintf( __( 'Username: %s', 'mosmcp-abilities' ), $user->user_login ) . "\r\n\r\n";
		$message .= __( 'If this was a mistake, ignore this email and nothing will happen.', 'mosmcp-abilities' ) . "\r\n\r\n";
		$message .= __( 'To reset your password, visit the following address:', 'mosmcp-abilities' ) . "\r\n\r\n";
		$message .= $reset_url . "\r\n";

		/* translators: %s: site name. */
		$subject = sprintf( __( '[%s] Password Reset', 'mosmcp-abilities' ), wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES ) );

		$sent = wp_mail( $user->user_email, wp_specialchars_decode( $subject ), $message );

		return array(
			'id'   => $id,
			'sent' => (bool) $sent,
		);
	}

	/**
	 * Validates a password reset key for a login.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function validate_password_reset_key( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$key   = isset( $input['key'] ) ? (string) $input['key'] : '';
		$login = isset( $input['login'] ) ? (string) $input['login'] : '';

		if ( '' === $key || '' === $login ) {
			return new WP_Error( 'mosmcp_missing_params', __( 'Both key and login are required.', 'mosmcp-abilities' ) );
		}

		$result = check_password_reset_key( $key, $login );

		if ( is_wp_error( $result ) ) {
			return array( 'valid' => false );
		}

		return array(
			'valid'   => true,
			'user_id' => (int) $result->ID,
		);
	}

	/**
	 * Lists posts authored by a user.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function list_user_authored_posts( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$id    = isset( $input['id'] ) ? absint( $input['id'] ) : 0;

		if ( $id <= 0 || ! get_userdata( $id ) ) {
			return new WP_Error( 'mosmcp_user_not_found', __( 'No user was found with that ID.', 'mosmcp-abilities' ) );
		}

		$paginate  = self::paginate_args( $input );
		$post_type = ! empty( $input['post_type'] ) ? sanitize_key( (string) $input['post_type'] ) : 'post';
		$status    = ! empty( $input['status'] ) ? sanitize_key( (string) $input['status'] ) : 'publish';

		$query = new WP_Query(
			array(
				'author'         => $id,
				'post_type'      => $post_type,
				'post_status'    => $status,
				'posts_per_page' => $paginate['per_page'],
				'paged'          => $paginate['page'],
			)
		);

		$posts = array_map(
			static function ( $post ) {
				return array(
					'id'     => (int) $post->ID,
					'title'  => (string) get_the_title( $post ),
					'status' => (string) $post->post_status,
					'type'   => (string) $post->post_type,
					'date'   => (string) $post->post_date,
					'link'   => (string) get_permalink( $post ),
				);
			},
			$query->posts
		);

		return array(
			'posts'    => $posts,
			'total'    => (int) $query->found_posts,
			'page'     => $paginate['page'],
			'per_page' => $paginate['per_page'],
		);
	}

	/**
	 * Lists all roles with their capabilities.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public static function list_roles( $input = array() ) {
		unset( $input );
		$roles = array();

		$wp_roles = wp_roles();
		if ( $wp_roles && is_array( $wp_roles->roles ) ) {
			foreach ( $wp_roles->roles as $slug => $role ) {
				$caps    = ( isset( $role['capabilities'] ) && is_array( $role['capabilities'] ) ) ? array_keys( array_filter( $role['capabilities'] ) ) : array();
				$roles[] = array(
					'slug'         => (string) $slug,
					'name'         => isset( $role['name'] ) ? (string) $role['name'] : (string) $slug,
					'capabilities' => $caps,
				);
			}
		}

		return array( 'roles' => $roles );
	}

	/**
	 * Gets the capabilities of a role.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function get_role_capabilities( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$slug  = isset( $input['role'] ) ? sanitize_key( (string) $input['role'] ) : '';

		$role = '' !== $slug ? get_role( $slug ) : null;
		if ( ! $role ) {
			return new WP_Error( 'mosmcp_invalid_role', __( 'That role does not exist.', 'mosmcp-abilities' ) );
		}

		return array(
			'role'         => $slug,
			'capabilities' => array_keys( array_filter( (array) $role->capabilities ) ),
		);
	}

	/**
	 * Gets the roles the current user may assign to others.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public static function get_editable_roles( $input = array() ) {
		unset( $input );
		require_once ABSPATH . 'wp-admin/includes/user.php';

		$roles  = get_editable_roles();
		$result = array();
		foreach ( $roles as $slug => $role ) {
			$result[] = array(
				'slug'         => (string) $slug,
				'name'         => isset( $role['name'] ) ? (string) $role['name'] : (string) $slug,
				'capabilities' => ( isset( $role['capabilities'] ) && is_array( $role['capabilities'] ) ) ? array_keys( array_filter( $role['capabilities'] ) ) : array(),
			);
		}

		return array( 'roles' => $result );
	}

	/**
	 * Gets the roles assigned to a user.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function get_user_roles( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$id    = isset( $input['id'] ) ? absint( $input['id'] ) : 0;

		$user = $id > 0 ? get_userdata( $id ) : false;
		if ( ! $user ) {
			return new WP_Error( 'mosmcp_user_not_found', __( 'No user was found with that ID.', 'mosmcp-abilities' ) );
		}

		return array(
			'id'    => $id,
			'roles' => array_values( (array) $user->roles ),
		);
	}

	/**
	 * Replaces a user's roles with a single role.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function assign_role_to_user( $input = array() ) {
		$input   = is_array( $input ) ? $input : array();
		$checked = self::validate_role_change_input( $input );
		if ( is_wp_error( $checked ) ) {
			return $checked;
		}
		list( $user, $role ) = $checked;

		$user->set_role( $role );

		return array(
			'id'    => $user->ID,
			'roles' => array_values( (array) get_userdata( $user->ID )->roles ),
		);
	}

	/**
	 * Adds a role to a user, keeping existing roles.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function add_additional_role_to_user( $input = array() ) {
		$input   = is_array( $input ) ? $input : array();
		$checked = self::validate_role_change_input( $input );
		if ( is_wp_error( $checked ) ) {
			return $checked;
		}
		list( $user, $role ) = $checked;

		$user->add_role( $role );

		return array(
			'id'    => $user->ID,
			'roles' => array_values( (array) get_userdata( $user->ID )->roles ),
		);
	}

	/**
	 * Removes a role from a user.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function remove_role_from_user( $input = array() ) {
		$input   = is_array( $input ) ? $input : array();
		$checked = self::validate_role_change_input( $input );
		if ( is_wp_error( $checked ) ) {
			return $checked;
		}
		list( $user, $role ) = $checked;

		$user->remove_role( $role );

		return array(
			'id'    => $user->ID,
			'roles' => array_values( (array) get_userdata( $user->ID )->roles ),
		);
	}

	/**
	 * Standard pagination args (page, per_page clamped to 1..100, number, offset).
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, int>
	 */
	private static function paginate_args( $input ) {
		$page     = isset( $input['page'] ) ? absint( $input['page'] ) : 1;
		$per_page = isset( $input['per_page'] ) ? absint( $input['per_page'] ) : 20;

		if ( $page < 1 ) {
			$page = 1;
		}
		if ( $per_page < 1 ) {
			$per_page = 20;
		}
		if ( $per_page > 100 ) {
			$per_page = 100;
		}

		return array(
			'page'     => $page,
			'per_page' => $per_page,
			'number'   => $per_page,
			'offset'   => ( $page - 1 ) * $per_page,
		);
	}

	/**
	 * Slim user representation for listings/search.
	 *
	 * @param \WP_User $user User object.
	 * @return array<string, mixed>
	 */
	private static function user_summary( $user ) {
		if ( ! $user instanceof \WP_User ) {
			return array();
		}
		return array(
			'id'           => (int) $user->ID,
			'username'     => (string) $user->user_login,
			'display_name' => (string) $user->display_name,
			'email'        => (string) $user->user_email,
			'roles'        => array_values( (array) $user->roles ),
			'registered'   => (string) $user->user_registered,
		);
	}

	/**
	 * Full profile representation for single-user lookups.
	 *
	 * @param \WP_User $user User object.
	 * @return array<string, mixed>
	 */
	private static function full_user_profile( $user ) {
		if ( ! $user instanceof \WP_User ) {
			return array();
		}
		return array(
			'id'           => (int) $user->ID,
			'username'     => (string) $user->user_login,
			'email'        => (string) $user->user_email,
			'display_name' => (string) $user->display_name,
			'first_name'   => (string) $user->first_name,
			'last_name'    => (string) $user->last_name,
			'nickname'     => (string) $user->nickname,
			'roles'        => array_values( (array) $user->roles ),
			'registered'   => (string) $user->user_registered,
			'website_url'  => (string) $user->user_url,
			'biography'    => (string) $user->description,
			'locale'       => get_user_locale( $user ),
		);
	}

	/**
	 * Updates one profile field after confirming the target exists and is editable.
	 *
	 * @param int    $id    User ID.
	 * @param string $field wp_update_user() field key.
	 * @param mixed  $value Sanitized value.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function update_single_user_field( $id, $field, $value ) {
		if ( $id <= 0 || ! get_userdata( $id ) ) {
			return new WP_Error( 'mosmcp_user_not_found', __( 'No user was found with that ID.', 'mosmcp-abilities' ) );
		}
		if ( ! current_user_can( 'edit_user', $id ) ) {
			return new WP_Error( 'mosmcp_cannot_edit', __( 'You are not allowed to edit this user.', 'mosmcp-abilities' ) );
		}

		$result = wp_update_user(
			array(
				'ID'   => $id,
				$field => $value,
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return self::full_user_profile( get_userdata( $id ) );
	}

	/**
	 * Validates a role-change request (existence, object cap, role validity, grant right).
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array{0:\WP_User,1:string}|WP_Error
	 */
	private static function validate_role_change_input( $input ) {
		$id   = isset( $input['id'] ) ? absint( $input['id'] ) : 0;
		$role = isset( $input['role'] ) ? sanitize_key( (string) $input['role'] ) : '';

		$user = $id > 0 ? get_userdata( $id ) : false;
		if ( ! $user ) {
			return new WP_Error( 'mosmcp_user_not_found', __( 'No user was found with that ID.', 'mosmcp-abilities' ) );
		}
		if ( ! current_user_can( 'promote_user', $id ) ) {
			return new WP_Error( 'mosmcp_cannot_promote', __( 'You are not allowed to change this user\'s role.', 'mosmcp-abilities' ) );
		}
		if ( '' === $role || ! wp_roles()->is_role( $role ) ) {
			return new WP_Error( 'mosmcp_invalid_role', __( 'That role does not exist.', 'mosmcp-abilities' ) );
		}
		if ( ! self::can_grant_role( $role ) ) {
			return new WP_Error( 'mosmcp_cannot_grant_role', __( 'You may only grant a role whose capabilities you already hold.', 'mosmcp-abilities' ) );
		}

		return array( $user, $role );
	}

	/**
	 * Whether the current user may grant a role.
	 *
	 * Hardened over the source: a caller may grant a role only if every capability
	 * that role confers is one the caller already holds. This blocks privilege
	 * escalation via role assignment (e.g. an editor granting a role that carries
	 * manage_options), not just the administrator role.
	 *
	 * @param string $role_slug Role slug being granted.
	 * @return bool
	 */
	private static function can_grant_role( $role_slug ) {
		$role = get_role( $role_slug );
		if ( ! $role ) {
			return false;
		}

		$current = wp_get_current_user();
		if ( ! $current || 0 === $current->ID ) {
			return false;
		}

		foreach ( (array) $role->capabilities as $cap => $granted ) {
			if ( $granted && ! $current->has_cap( $cap ) ) {
				return false;
			}
		}

		return true;
	}
}
