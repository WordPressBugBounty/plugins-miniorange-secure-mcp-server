<?php
/**
 * Users ability pack: definitions for the mosmcp user, role, and credential abilities.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Users;

use MoSMCP\Abilities\Ability;
use MoSMCP\Abilities\Ability_Pack;
use MoSMCP\Abilities\Packs\Core\Support\Core_Schema as Schema;

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
 * Class Users_Pack
 *
 * Declares user, role, and credential abilities and their governed contract.
 * Execute logic lives in Users_Provider and is preserved from the reviewed
 * source. The three metadata abilities declare guard_meta_keys so the registrar
 * refuses reserved keys (capabilities/role-level/session) before the callback runs.
 */
class Users_Pack extends Ability_Pack {

	/**
	 * Ability category slug.
	 */
	const CATEGORY = 'mosmcp-users';

	/**
	 * Ability category for user/role/credential abilities.
	 *
	 * @return array Category definition.
	 */
	public function category() {
		return array(
			'slug'        => self::CATEGORY,
			'label'       => __( 'Users & Roles', 'mosmcp-abilities' ),
			'description' => __( 'Manage WordPress users, profiles, roles, capabilities, and credentials.', 'mosmcp-abilities' ),
		);
	}

	/**
	 * The user, role, and credential abilities.
	 *
	 * @return Ability[]
	 */
	public function abilities() {
		return array_merge(
			$this->read_abilities(),
			$this->write_abilities(),
			$this->role_abilities(),
		);
	}

	/**
	 * User read abilities.
	 *
	 * @return Ability[]
	 */
	private function read_abilities() {
		return array(
			$this->ability(
				'list-users',
				__( 'List Users', 'mosmcp-abilities' ),
				__( 'Returns a paginated list of WordPress users on this site, optionally filtered by role slug. Read-only. To search by keyword use mosmcp/search-users; for a single user use mosmcp/get-user-by-id, mosmcp/get-user-by-username, or mosmcp/get-user-by-email.', 'mosmcp-abilities' ),
				'list_users',
				self::annotations( true, false, true, false ),
				'list_users',
				Schema::object(
					array(
						'page'     => Schema::int(
							__( 'Page number to return, starting at 1.', 'mosmcp-abilities' ),
							array(
								'default' => 1,
								'minimum' => 1,
							)
						),
						'per_page' => Schema::int(
							__( 'Users per page (max 100).', 'mosmcp-abilities' ),
							array(
								'default' => 20,
								'minimum' => 1,
								'maximum' => 100,
							)
						),
						'role'     => Schema::str( __( 'Optional role slug to filter by (e.g. "editor"). See mosmcp/list-roles for valid slugs.', 'mosmcp-abilities' ) ),
					)
				),
				self::users_list_output()
			),
			$this->ability(
				'get-user-by-id',
				__( 'Get User by ID', 'mosmcp-abilities' ),
				__( 'Retrieves full profile details for a single user, given their numeric user ID. Read-only. If you only know a username or email, use mosmcp/get-user-by-username or mosmcp/get-user-by-email instead.', 'mosmcp-abilities' ),
				'list_users',
				self::annotations( true, false, true, false ),
				'get_user_by_id',
				self::id_input( __( 'The user ID to look up (required).', 'mosmcp-abilities' ) ),
				self::full_profile_schema()
			),
			$this->ability(
				'get-user-by-username',
				__( 'Get User by Username', 'mosmcp-abilities' ),
				__( 'Retrieves full profile details for a single user, given their exact username (user_login). Read-only. If you have the numeric ID instead, use mosmcp/get-user-by-id.', 'mosmcp-abilities' ),
				'list_users',
				self::annotations( true, false, true, false ),
				'get_user_by_username',
				Schema::object(
					array(
						'username' => Schema::str( __( 'The exact username to look up (required).', 'mosmcp-abilities' ) ),
					),
					array( 'username' )
				),
				self::full_profile_schema()
			),
			$this->ability(
				'get-user-by-email',
				__( 'Get User by Email', 'mosmcp-abilities' ),
				__( 'Retrieves full profile details for a single user, given their exact email address. Read-only. If you have the numeric ID instead, use mosmcp/get-user-by-id.', 'mosmcp-abilities' ),
				'list_users',
				self::annotations( true, false, true, false ),
				'get_user_by_email',
				Schema::object(
					array(
						'email' => Schema::str( __( 'The exact email address to look up (required).', 'mosmcp-abilities' ) ),
					),
					array( 'email' )
				),
				self::full_profile_schema()
			),
			$this->ability(
				'search-users',
				__( 'Search Users', 'mosmcp-abilities' ),
				__( 'Searches users by a keyword matched against username, display name, and email, paginated. Read-only. For a plain filtered listing (no keyword) use mosmcp/list-users.', 'mosmcp-abilities' ),
				'list_users',
				self::annotations( true, false, true, false ),
				'search_users',
				Schema::object(
					array(
						'query'    => Schema::str( __( 'The keyword to search for (required).', 'mosmcp-abilities' ) ),
						'page'     => Schema::int(
							__( 'Page number to return, starting at 1.', 'mosmcp-abilities' ),
							array(
								'default' => 1,
								'minimum' => 1,
							)
						),
						'per_page' => Schema::int(
							__( 'Users per page (max 100).', 'mosmcp-abilities' ),
							array(
								'default' => 20,
								'minimum' => 1,
								'maximum' => 100,
							)
						),
					),
					array( 'query' )
				),
				self::users_list_output()
			),
			$this->ability(
				'count-users',
				__( 'Count Users', 'mosmcp-abilities' ),
				__( 'Returns the total number of registered users on this site. Read-only. For a per-role breakdown use mosmcp/count-users-by-role.', 'mosmcp-abilities' ),
				'list_users',
				self::annotations( true, false, true, false ),
				'count_users',
				Schema::object( array() ),
				Schema::object( array( 'total' => Schema::int() ), array( 'total' ) )
			),
			$this->ability(
				'count-users-by-role',
				__( 'Count Users by Role', 'mosmcp-abilities' ),
				__( 'Returns the number of users assigned to a given role, given the role slug. Read-only. See mosmcp/list-roles for valid slugs; for the site-wide total use mosmcp/count-users.', 'mosmcp-abilities' ),
				'list_users',
				self::annotations( true, false, true, false ),
				'count_users_by_role',
				Schema::object(
					array( 'role' => Schema::str( __( 'The role slug to count (required).', 'mosmcp-abilities' ) ) ),
					array( 'role' )
				),
				Schema::object(
					array(
						'role'  => Schema::str(),
						'count' => Schema::int(),
					),
					array( 'role', 'count' )
				)
			),
			$this->ability(
				'get-user-email',
				__( "Get User's Email Address", 'mosmcp-abilities' ),
				__( 'Returns only the email address for one user, given their user ID (a narrow, PII-gated read, separate from the fuller mosmcp/get-user-by-id profile). Read-only.', 'mosmcp-abilities' ),
				'list_users',
				self::annotations( true, false, true, false ),
				'get_user_email',
				self::id_input( __( 'The user ID to look up (required).', 'mosmcp-abilities' ) ),
				Schema::object(
					array(
						'id'    => Schema::int(),
						'email' => Schema::str(),
					),
					array( 'id', 'email' )
				)
			),
			$this->guarded(
				$this->ability(
					'get-user-metadata',
					__( 'Get User Metadata', 'mosmcp-abilities' ),
					__( 'Returns non-sensitive usermeta key/value pairs for a user, given their user ID. Pass meta_key to fetch a single key, or omit it to return all. Internal keys (session tokens, capabilities, and user level) are always refused/excluded. Read-only.', 'mosmcp-abilities' ),
					'edit_users',
					self::annotations( true, false, true, false ),
					'get_user_metadata',
					self::id_input(
						__( 'The user ID to look up (required).', 'mosmcp-abilities' ),
						array(
							'meta_key' => Schema::str( __( 'Optional. A single meta key to fetch. If omitted, all non-sensitive metadata is returned.', 'mosmcp-abilities' ) ),
						)
					),
					Schema::object(
						array(
							'id'   => Schema::int(),
							'meta' => array( 'type' => 'object' ),
						),
						array( 'id', 'meta' )
					)
				)
			),
			$this->ability(
				'get-user-registration-date',
				__( 'Get User Registration Date', 'mosmcp-abilities' ),
				__( 'Returns the date and time a user registered, given their user ID. Read-only.', 'mosmcp-abilities' ),
				'list_users',
				self::annotations( true, false, true, false ),
				'get_user_registration_date',
				self::id_input( __( 'The user ID to look up (required).', 'mosmcp-abilities' ) ),
				Schema::object(
					array(
						'id'         => Schema::int(),
						'registered' => Schema::str(),
					),
					array( 'id', 'registered' )
				)
			),
			$this->ability(
				'get-user-avatar-url',
				__( "Get User's Avatar URL", 'mosmcp-abilities' ),
				__( 'Returns the Gravatar/avatar URL configured for a user, given their user ID and an optional pixel size (default 96). Read-only.', 'mosmcp-abilities' ),
				'list_users',
				self::annotations( true, false, true, false ),
				'get_user_avatar_url',
				self::id_input(
					__( 'The user ID to look up (required).', 'mosmcp-abilities' ),
					array( 'size' => Schema::int( __( 'Avatar size in pixels.', 'mosmcp-abilities' ), array( 'default' => 96 ) ) )
				),
				Schema::object(
					array(
						'id'         => Schema::int(),
						'avatar_url' => Schema::str(),
					),
					array( 'id', 'avatar_url' )
				)
			),
			$this->ability(
				'get-user-post-count',
				__( "Get User's Post Count", 'mosmcp-abilities' ),
				__( 'Returns how many posts a user has authored, given their user ID and an optional post_type (default "post"). Read-only. For the actual list of posts use mosmcp/list-user-authored-posts.', 'mosmcp-abilities' ),
				'list_users',
				self::annotations( true, false, true, false ),
				'get_user_post_count',
				self::id_input(
					__( 'The user ID to look up (required).', 'mosmcp-abilities' ),
					array( 'post_type' => Schema::str( __( 'The post type to count. Defaults to "post".', 'mosmcp-abilities' ), array( 'default' => 'post' ) ) )
				),
				Schema::object(
					array(
						'id'        => Schema::int(),
						'post_type' => Schema::str(),
						'count'     => Schema::int(),
					),
					array( 'id', 'post_type', 'count' )
				)
			),
			$this->ability(
				'check-username-availability',
				__( 'Check Username Availability', 'mosmcp-abilities' ),
				__( 'Checks whether a username is already taken, given the candidate username. Use this before mosmcp/create-user to avoid a login-exists error. Read-only.', 'mosmcp-abilities' ),
				'list_users',
				self::annotations( true, false, true, false ),
				'check_username_availability',
				Schema::object(
					array( 'username' => Schema::str( __( 'The username to check (required).', 'mosmcp-abilities' ) ) ),
					array( 'username' )
				),
				Schema::object(
					array(
						'username'  => Schema::str(),
						'available' => Schema::boolean(),
					),
					array( 'username', 'available' )
				)
			),
			$this->ability(
				'check-email-availability',
				__( 'Check Email Availability', 'mosmcp-abilities' ),
				__( 'Checks whether an email address is already registered to an account, given the candidate email. Use this before mosmcp/create-user to avoid an email-exists error. Read-only.', 'mosmcp-abilities' ),
				'list_users',
				self::annotations( true, false, true, false ),
				'check_email_availability',
				Schema::object(
					array( 'email' => Schema::str( __( 'The email address to check (required).', 'mosmcp-abilities' ) ) ),
					array( 'email' )
				),
				Schema::object(
					array(
						'email'     => Schema::str(),
						'available' => Schema::boolean(),
					),
					array( 'email', 'available' )
				)
			),
			$this->ability(
				'get-user-locale',
				__( "Get User's Admin Locale", 'mosmcp-abilities' ),
				__( 'Returns a user\'s configured admin language/locale, given their user ID. Read-only.', 'mosmcp-abilities' ),
				'list_users',
				self::annotations( true, false, true, false ),
				'get_user_locale',
				self::id_input( __( 'The user ID to look up (required).', 'mosmcp-abilities' ) ),
				self::locale_output()
			),
			$this->ability(
				'list-user-authored-posts',
				__( 'List Posts Authored by User', 'mosmcp-abilities' ),
				__( 'Lists posts authored by a given user, given their user ID. Optional post_type (default "post") and status (default "publish"), paginated. Read-only. For just the count, use mosmcp/get-user-post-count.', 'mosmcp-abilities' ),
				'list_users',
				self::annotations( true, false, true, false ),
				'list_user_authored_posts',
				self::id_input(
					__( 'The author user ID (required).', 'mosmcp-abilities' ),
					array(
						'post_type' => Schema::str( __( 'The post type to list. Defaults to "post".', 'mosmcp-abilities' ), array( 'default' => 'post' ) ),
						'status'    => Schema::str( __( 'The post status to list. Defaults to "publish".', 'mosmcp-abilities' ), array( 'default' => 'publish' ) ),
						'page'      => Schema::int(
							__( 'Page number, starting at 1.', 'mosmcp-abilities' ),
							array(
								'default' => 1,
								'minimum' => 1,
							)
						),
						'per_page'  => Schema::int(
							__( 'Posts per page (max 100).', 'mosmcp-abilities' ),
							array(
								'default' => 20,
								'minimum' => 1,
								'maximum' => 100,
							)
						),
					)
				),
				Schema::object(
					array(
						'posts'    => Schema::arr(
							Schema::object(
								array(
									'id'     => Schema::int(),
									'title'  => Schema::str(),
									'status' => Schema::str(),
									'type'   => Schema::str(),
									'date'   => Schema::str(),
									'link'   => Schema::str(),
								),
								array( 'id', 'title', 'status' )
							)
						),
						'total'    => Schema::int(),
						'page'     => Schema::int(),
						'per_page' => Schema::int(),
					),
					array( 'posts', 'total', 'page', 'per_page' )
				)
			),
			$this->ability(
				'validate-password-reset-key',
				__( 'Validate Password Reset Key', 'mosmcp-abilities' ),
				__( 'Validates a password reset key/token for a given login before allowing a reset to complete, given the key and login. Read-only.', 'mosmcp-abilities' ),
				'edit_users',
				self::annotations( true, false, true, false ),
				'validate_password_reset_key',
				Schema::object(
					array(
						'key'   => Schema::str( __( 'The reset key/token to validate (required).', 'mosmcp-abilities' ) ),
						'login' => Schema::str( __( 'The username the key was issued for (required).', 'mosmcp-abilities' ) ),
					),
					array( 'key', 'login' )
				),
				Schema::object(
					array(
						'valid'   => Schema::boolean(),
						'user_id' => Schema::int(),
					),
					array( 'valid' )
				)
			),
		);
	}

	/**
	 * User write abilities (create, profile fields, metadata, deletion, credentials).
	 *
	 * @return Ability[]
	 */
	private function write_abilities() {
		return array(
			$this->ability(
				'create-user',
				__( 'Create New User', 'mosmcp-abilities' ),
				__( 'Creates a new WordPress user account with the given username and email. Role defaults to "subscriber". If password is omitted, a random one is generated and, unless send_notification is false, the new user is emailed a "set your password" link. You may only grant a role whose capabilities you already hold.', 'mosmcp-abilities' ),
				'create_users',
				self::annotations( false, false, false, false ),
				'create_user',
				Schema::object(
					array(
						'user_login'        => Schema::str( __( 'The new account\'s username (required).', 'mosmcp-abilities' ) ),
						'user_email'        => Schema::str( __( 'The new account\'s email address (required).', 'mosmcp-abilities' ) ),
						'first_name'        => Schema::str( __( 'Optional first name.', 'mosmcp-abilities' ) ),
						'last_name'         => Schema::str( __( 'Optional last name.', 'mosmcp-abilities' ) ),
						'role'              => Schema::str( __( 'Role slug to assign. Defaults to "subscriber". You may only grant a role whose capabilities you already hold.', 'mosmcp-abilities' ), array( 'default' => 'subscriber' ) ),
						'password'          => Schema::str( __( 'Optional explicit password. If omitted, a secure random password is generated.', 'mosmcp-abilities' ) ),
						'send_notification' => Schema::boolean( __( 'Whether to email the new user their account/password-setup details. Defaults to true.', 'mosmcp-abilities' ), array( 'default' => true ) ),
					),
					array( 'user_login', 'user_email' )
				),
				self::full_profile_schema()
			),
			$this->profile_setter( 'update-user-profile', __( "Update User's Display Name", 'mosmcp-abilities' ), __( 'Updates a user\'s public display name, given their user ID and the new display_name.', 'mosmcp-abilities' ), 'display_name', __( 'The new public display name (required).', 'mosmcp-abilities' ) ),
			$this->profile_setter( 'update-user-email', __( "Update User's Email Address", 'mosmcp-abilities' ), __( 'Updates a user\'s email address, given their user ID and the new user_email.', 'mosmcp-abilities' ), 'user_email', __( 'The new email address (required).', 'mosmcp-abilities' ) ),
			$this->profile_setter( 'update-user-first-name', __( "Update User's First Name", 'mosmcp-abilities' ), __( 'Updates a user\'s first name, given their user ID and the new first_name.', 'mosmcp-abilities' ), 'first_name', __( 'The new first name (required).', 'mosmcp-abilities' ) ),
			$this->profile_setter( 'update-user-last-name', __( "Update User's Last Name", 'mosmcp-abilities' ), __( 'Updates a user\'s last name, given their user ID and the new last_name.', 'mosmcp-abilities' ), 'last_name', __( 'The new last name (required).', 'mosmcp-abilities' ) ),
			$this->profile_setter( 'update-user-nickname', __( "Update User's Nickname", 'mosmcp-abilities' ), __( 'Updates a user\'s nickname, given their user ID and the new nickname.', 'mosmcp-abilities' ), 'nickname', __( 'The new nickname (required).', 'mosmcp-abilities' ) ),
			$this->profile_setter( 'update-user-website-url', __( "Update User's Website URL", 'mosmcp-abilities' ), __( 'Updates the website URL field on a user\'s profile, given their user ID and the new user_url.', 'mosmcp-abilities' ), 'user_url', __( 'The new website URL (required).', 'mosmcp-abilities' ) ),
			$this->profile_setter( 'update-user-biography', __( "Update User's Biography", 'mosmcp-abilities' ), __( 'Updates the "About"/description field on a user\'s profile, given their user ID and the new description text.', 'mosmcp-abilities' ), 'description', __( 'The new biographical info (required).', 'mosmcp-abilities' ) ),
			$this->ability(
				'update-user-locale',
				__( "Update User's Admin Locale", 'mosmcp-abilities' ),
				__( 'Updates a user\'s configured admin language/locale, given their user ID and the new locale (e.g. "fr_FR", or "" for the site default).', 'mosmcp-abilities' ),
				'edit_users',
				self::annotations( false, false, true, false ),
				'update_user_locale',
				self::id_input(
					__( 'The user ID to update (required).', 'mosmcp-abilities' ),
					array( 'locale' => Schema::str( __( 'The new locale code (required). Use an empty string for the site default.', 'mosmcp-abilities' ) ) ),
					array( 'locale' )
				),
				self::locale_output()
			),
			$this->guarded(
				$this->ability(
					'update-user-metadata',
					__( 'Update User Metadata', 'mosmcp-abilities' ),
					__( 'Sets a custom usermeta key/value pair on a user, given their user ID, the meta_key, and the meta_value. Protected keys (capabilities, role level, session tokens) are refused. To remove a key, use mosmcp/delete-user-metadata.', 'mosmcp-abilities' ),
					'edit_users',
					self::annotations( false, true, true, false ),
					'update_user_metadata',
					self::id_input(
						__( 'The user ID to update (required).', 'mosmcp-abilities' ),
						array(
							'meta_key'   => Schema::str( __( 'The meta key to set (required). Protected keys are refused.', 'mosmcp-abilities' ) ),
							'meta_value' => array( 'description' => __( 'The value to store (required).', 'mosmcp-abilities' ) ),
						),
						array( 'meta_key', 'meta_value' )
					),
					Schema::object(
						array(
							'id'       => Schema::int(),
							'meta_key' => Schema::str(),
							'updated'  => Schema::boolean(),
						),
						array( 'id', 'meta_key', 'updated' )
					)
				)
			),
			$this->guarded(
				$this->ability(
					'delete-user-metadata',
					__( 'Delete User Metadata', 'mosmcp-abilities' ),
					__( 'Deletes a custom usermeta key from a user entirely, given their user ID and the meta_key. Protected keys (capabilities, role level, session tokens) are refused.', 'mosmcp-abilities' ),
					'edit_users',
					self::annotations( false, true, true, false ),
					'delete_user_metadata',
					self::id_input(
						__( 'The user ID to update (required).', 'mosmcp-abilities' ),
						array( 'meta_key' => Schema::str( __( 'The meta key to delete (required). Protected keys are refused.', 'mosmcp-abilities' ) ) ),
						array( 'meta_key' )
					),
					Schema::object(
						array(
							'id'       => Schema::int(),
							'meta_key' => Schema::str(),
							'deleted'  => Schema::boolean(),
						),
						array( 'id', 'meta_key', 'deleted' )
					)
				)
			),
			$this->ability(
				'delete-user',
				__( 'Delete User', 'mosmcp-abilities' ),
				__( 'Permanently deletes a user account, given their user ID. Optionally pass reassign with another user\'s ID to move their authored content to that user; otherwise their content is deleted too. Destructive and irreversible.', 'mosmcp-abilities' ),
				'delete_users',
				self::annotations( false, true, false, false ),
				'delete_user',
				self::id_input(
					__( 'The user ID to delete (required).', 'mosmcp-abilities' ),
					array( 'reassign' => Schema::int( __( 'Optional user ID to reassign the deleted user\'s content to.', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ) )
				),
				Schema::object(
					array(
						'id'      => Schema::int(),
						'deleted' => Schema::boolean(),
					),
					array( 'id', 'deleted' )
				)
			),
			$this->ability(
				'change-user-password',
				__( "Reset User's Password", 'mosmcp-abilities' ),
				__( 'Resets a user\'s password to the given new_password (minimum 8 characters) and invalidates their existing login sessions. To have the user choose their own password instead, use mosmcp/send-password-reset-link.', 'mosmcp-abilities' ),
				'edit_users',
				self::annotations( false, true, true, false ),
				'change_user_password',
				self::id_input(
					__( 'The user ID whose password will be changed (required).', 'mosmcp-abilities' ),
					array( 'new_password' => Schema::str( __( 'The new password, at least 8 characters (required).', 'mosmcp-abilities' ) ) ),
					array( 'new_password' )
				),
				Schema::object(
					array(
						'id'      => Schema::int(),
						'changed' => Schema::boolean(),
					),
					array( 'id', 'changed' )
				)
			),
			$this->ability(
				'send-password-reset-link',
				__( 'Send Password Reset Link', 'mosmcp-abilities' ),
				__( 'Triggers WordPress\'s native password-reset email for a user, given their user ID (the same flow as the "Lost your password?" screen). Use this instead of mosmcp/change-user-password when the user should choose their own new password. Each call sends another email.', 'mosmcp-abilities' ),
				'edit_users',
				self::annotations( false, false, false, false ),
				'send_password_reset_link',
				self::id_input( __( 'The user ID to send the reset link to (required).', 'mosmcp-abilities' ) ),
				Schema::object(
					array(
						'id'   => Schema::int(),
						'sent' => Schema::boolean(),
					),
					array( 'id', 'sent' )
				)
			),
		);
	}

	/**
	 * Role and permission abilities.
	 *
	 * @return Ability[]
	 */
	private function role_abilities() {
		$role_input  = Schema::object(
			array(
				'id'   => Schema::int( __( 'The user ID to change (required).', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
				'role' => Schema::str( __( 'The role slug (required). See mosmcp/list-roles for valid slugs. You may only grant a role whose capabilities you already hold.', 'mosmcp-abilities' ) ),
			),
			array( 'id', 'role' )
		);
		$role_output = Schema::object(
			array(
				'id'    => Schema::int(),
				'roles' => Schema::arr( Schema::str() ),
			),
			array( 'id', 'roles' )
		);
		$role_list   = Schema::object( array( 'roles' => Schema::arr( self::role_item_schema() ) ), array( 'roles' ) );

		return array(
			$this->ability(
				'list-roles',
				__( 'List Roles', 'mosmcp-abilities' ),
				__( 'Lists all roles registered on the site, with each role\'s display name and full capability list. Read-only. To see only roles you personally may assign, use mosmcp/get-editable-roles.', 'mosmcp-abilities' ),
				'list_users',
				self::annotations( true, false, true, false ),
				'list_roles',
				Schema::object( array() ),
				$role_list
			),
			$this->ability(
				'get-role-capabilities',
				__( 'Get Role Capabilities', 'mosmcp-abilities' ),
				__( 'Returns the capabilities assigned to a specific role, given the role slug. Read-only. See mosmcp/list-roles for valid slugs.', 'mosmcp-abilities' ),
				'list_users',
				self::annotations( true, false, true, false ),
				'get_role_capabilities',
				Schema::object(
					array( 'role' => Schema::str( __( 'The role slug to look up (required).', 'mosmcp-abilities' ) ) ),
					array( 'role' )
				),
				Schema::object(
					array(
						'role'         => Schema::str(),
						'capabilities' => Schema::arr( Schema::str() ),
					),
					array( 'role', 'capabilities' )
				)
			),
			$this->ability(
				'get-editable-roles',
				__( 'Get Editable Roles', 'mosmcp-abilities' ),
				__( 'Returns the roles the current user is permitted to assign to others. Read-only.', 'mosmcp-abilities' ),
				'promote_users',
				self::annotations( true, false, true, false ),
				'get_editable_roles',
				Schema::object( array() ),
				$role_list
			),
			$this->ability(
				'get-user-roles',
				__( "Get User's Roles", 'mosmcp-abilities' ),
				__( 'Returns all roles currently assigned to a user, given their user ID. Read-only.', 'mosmcp-abilities' ),
				'list_users',
				self::annotations( true, false, true, false ),
				'get_user_roles',
				self::id_input( __( 'The user ID to look up (required).', 'mosmcp-abilities' ) ),
				Schema::object(
					array(
						'id'    => Schema::int(),
						'roles' => Schema::arr( Schema::str() ),
					),
					array( 'id', 'roles' )
				)
			),
			$this->ability(
				'assign-role-to-user',
				__( 'Assign Role to User', 'mosmcp-abilities' ),
				__( 'Replaces a user\'s role with a new one, given their user ID and the role slug â removes any existing roles first. To add a role without removing existing ones, use mosmcp/add-additional-role-to-user.', 'mosmcp-abilities' ),
				'promote_users',
				self::annotations( false, true, true, false ),
				'assign_role_to_user',
				$role_input,
				$role_output
			),
			$this->ability(
				'add-additional-role-to-user',
				__( 'Add Additional Role to User', 'mosmcp-abilities' ),
				__( 'Adds an additional role to a user without removing existing roles, given their user ID and the role slug. To replace all roles with one, use mosmcp/assign-role-to-user.', 'mosmcp-abilities' ),
				'promote_users',
				self::annotations( false, false, true, false ),
				'add_additional_role_to_user',
				$role_input,
				$role_output
			),
			$this->ability(
				'remove-role-from-user',
				__( 'Remove Role from User', 'mosmcp-abilities' ),
				__( 'Removes a specific role from a user, given their user ID and the role slug, leaving any other roles they hold intact.', 'mosmcp-abilities' ),
				'promote_users',
				self::annotations( false, true, true, false ),
				'remove_role_from_user',
				$role_input,
				$role_output
			),
		);
	}

	/**
	 * Builds one ability with the standard user-pack wiring.
	 *
	 * @param string               $name          Action slug (becomes mosmcp/<name>).
	 * @param string               $label         Ability label.
	 * @param string               $description   Ability description.
	 * @param string               $capability    Capability gate.
	 * @param array<string, bool>  $annotations   The four annotation hints.
	 * @param string               $method        Users_Provider method name.
	 * @param array<string, mixed> $input_schema  Input schema.
	 * @param array<string, mixed> $output_schema Output schema.
	 * @return Ability
	 */
	private function ability( $name, $label, $description, $capability, array $annotations, $method, array $input_schema, array $output_schema ) {
		return new Ability(
			'mosmcp/' . $name,
			array(
				'label'         => $label,
				'description'   => $description,
				'category'      => self::CATEGORY,
				'capability'    => $capability,
				'annotations'   => $annotations,
				'execute'       => array( Users_Provider::class, $method ),
				'input_schema'  => $input_schema,
				'output_schema' => $output_schema,
			)
		);
	}

	/**
	 * Builds a single-field profile update ability (edit_users, returns full profile).
	 *
	 * @param string $name       Action slug.
	 * @param string $label      Ability label.
	 * @param string $desc       Ability description.
	 * @param string $field      Input field name.
	 * @param string $field_desc Input field description.
	 * @return Ability
	 */
	private function profile_setter( $name, $label, $desc, $field, $field_desc ) {
		return $this->ability(
			$name,
			$label,
			$desc,
			'edit_users',
			self::annotations( false, false, true, false ),
			str_replace( '-', '_', $name ),
			self::id_input(
				__( 'The user ID to update (required).', 'mosmcp-abilities' ),
				array( $field => Schema::str( $field_desc ) ),
				array( $field )
			),
			self::full_profile_schema()
		);
	}

	/**
	 * Attaches the reserved-key guard on the meta_key input to an ability.
	 *
	 * @param Ability $ability Ability to guard.
	 * @return Ability
	 */
	private function guarded( Ability $ability ) {
		$args                    = $ability->to_args();
		$args['guard_meta_keys'] = array( 'meta_key' );
		return new Ability( $ability->get_name(), $args );
	}

	/**
	 * Input schema with a required integer `id` plus optional extra properties.
	 *
	 * @param string               $id_desc        Description for the id property.
	 * @param array<string, mixed> $extra_props    Extra input properties.
	 * @param string[]             $extra_required Extra required property names.
	 * @return array<string, mixed>
	 */
	private static function id_input( $id_desc, array $extra_props = array(), array $extra_required = array() ) {
		$props = array_merge(
			array( 'id' => Schema::int( $id_desc, array( 'minimum' => 1 ) ) ),
			$extra_props
		);
		return Schema::object( $props, array_merge( array( 'id' ), $extra_required ) );
	}

	/**
	 * Full user profile output schema.
	 *
	 * @return array<string, mixed>
	 */
	private static function full_profile_schema() {
		return Schema::object(
			array(
				'id'           => Schema::int(),
				'username'     => Schema::str(),
				'email'        => Schema::str(),
				'display_name' => Schema::str(),
				'first_name'   => Schema::str(),
				'last_name'    => Schema::str(),
				'nickname'     => Schema::str(),
				'roles'        => Schema::arr( Schema::str() ),
				'registered'   => Schema::str(),
				'website_url'  => Schema::str(),
				'biography'    => Schema::str(),
				'locale'       => Schema::str(),
			)
		);
	}

	/**
	 * Paginated user-list output schema.
	 *
	 * @return array<string, mixed>
	 */
	private static function users_list_output() {
		return Schema::object(
			array(
				'users'    => Schema::arr(
					Schema::object(
						array(
							'id'           => Schema::int(),
							'username'     => Schema::str(),
							'display_name' => Schema::str(),
							'email'        => Schema::str(),
							'roles'        => Schema::arr( Schema::str() ),
							'registered'   => Schema::str(),
						)
					)
				),
				'total'    => Schema::int(),
				'page'     => Schema::int(),
				'per_page' => Schema::int(),
			),
			array( 'users', 'total', 'page', 'per_page' )
		);
	}

	/**
	 * { id, locale } output schema.
	 *
	 * @return array<string, mixed>
	 */
	private static function locale_output() {
		return Schema::object(
			array(
				'id'     => Schema::int(),
				'locale' => Schema::str(),
			),
			array( 'id', 'locale' )
		);
	}

	/**
	 * Role list-item output schema.
	 *
	 * @return array<string, mixed>
	 */
	private static function role_item_schema() {
		return Schema::object(
			array(
				'slug'         => Schema::str(),
				'name'         => Schema::str(),
				'capabilities' => Schema::arr( Schema::str() ),
			),
			array( 'slug', 'name' )
		);
	}

	/**
	 * Builds the four MCP annotation hints.
	 *
	 * @param bool $read_only   Read-only hint.
	 * @param bool $destructive Destructive hint.
	 * @param bool $idempotent  Idempotent hint.
	 * @param bool $open_world  Open-world hint.
	 * @return array<string, bool>
	 */
	private static function annotations( $read_only, $destructive, $idempotent, $open_world ) {
		return array(
			'readonly'    => $read_only,
			'destructive' => $destructive,
			'idempotent'  => $idempotent,
			'open_world'  => $open_world,
		);
	}
}
