<?php
/**
 * Guards specific to editing stored values in place.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Editing;

use MoSMCP\Abilities\Packs\Site\Site_Support;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * This library authors every translatable string under its own fixed text domain
 * ('mosmcp-abilities'). The host plugin remaps them to its own text domain at
 * runtime via Abilities_Library::init(). The domain therefore intentionally will
 * not match any host plugin's slug, so the text-domain-mismatch check is disabled
 * for this file (the library's phpcs.xml.dist allows the domain on the CLI; this
 * directive covers IDE and Plugin Check runs that don't read that ruleset).
 */
// phpcs:disable WordPress.WP.I18n.TextDomainMismatch

/**
 * Class Editing_Support
 *
 * Option protection and error shaping for the editing abilities.
 */
class Editing_Support {

	/**
	 * Post fields that may be edited in place.
	 *
	 * @var string[]
	 */
	const EDITABLE_FIELDS = array( 'post_content', 'post_excerpt', 'post_title' );

	/**
	 * Options that may never be written by a text edit.
	 *
	 * Changing the first two cuts the connection this request arrived on. The rest
	 * are the identity and integrity of the site: which plugins load, what the roles
	 * are, and the keys that sign every session. None of them are content, and none
	 * of them should ever be reachable by a find-and-replace.
	 *
	 * @var string[]
	 */
	const BLOCKED_OPTIONS = array(
		'siteurl',
		'home',
		'active_plugins',
		'template',
		'stylesheet',
		'wp_user_roles',
		'cron',
		'db_version',
		'initial_db_version',
		'recently_activated',
		'uninstall_plugins',
		'auto_update_plugins',
		'auto_update_themes',
		'admin_email',
		'users_can_register',
		'default_role',
		'auth_key',
		'auth_salt',
		'secure_auth_key',
		'secure_auth_salt',
		'logged_in_key',
		'logged_in_salt',
		'nonce_key',
		'nonce_salt',
		'mosmcp_salt',
	);

	/**
	 * Refuses an option name that is protected, or that is shaped so it could
	 * resolve to a different row than it appears to.
	 *
	 * MySQL trims and compares option names case-insensitively under the usual
	 * collations, so a name carrying a trailing space or an invisible character can
	 * read as harmless here and still bind to a protected row. Anything outside
	 * printable ASCII is refused rather than guessed at.
	 *
	 * @param string $option Option name supplied by the caller.
	 * @return WP_Error|null WP_Error when the name must not be written, null otherwise.
	 */
	public static function blocked_option( $option ) {
		$option = (string) $option;

		if ( '' === trim( $option ) ) {
			return Site_Support::error(
				'mosmcp_option_name_required',
				__( 'No option name was given.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_INVALID_INPUT,
				true
			);
		}

		if ( 1 !== preg_match( '/^[\x21-\x7E]+$/', trim( $option ) ) ) {
			return Site_Support::error(
				'mosmcp_option_name_not_printable',
				__( 'That option name contains characters outside printable ASCII. A padded or disguised name can resolve to a different, possibly protected, option than the one it appears to name, so it is refused rather than guessed at.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_INVALID_INPUT,
				false
			);
		}

		$needle = strtolower( trim( $option ) );

		foreach ( self::BLOCKED_OPTIONS as $blocked ) {
			if ( $needle === $blocked ) {
				return Site_Support::error(
					'mosmcp_option_protected',
					sprintf(
						/* translators: %s: the option name. */
						__( 'The option "%s" is protected and can never be changed by a text edit. It controls how the site loads, who may do what, or how sessions are signed, and a find-and-replace is not a safe way to change any of those. Change it through the setting that owns it.', 'mosmcp-abilities' ),
						$option
					),
					Site_Support::CAUSE_HOST_ENVIRONMENT,
					false,
					array( 'option' => $option )
				);
			}
		}

		return null;
	}

	/**
	 * Whether the current user may write one post meta key.
	 *
	 * WordPress denies `edit_post_meta` for any underscore-prefixed key that has no
	 * registered authorisation callback, to everyone, administrators included. Every
	 * page builder stores its layout under exactly such a key, so honouring that
	 * answer alone would put all builder content permanently out of reach while the
	 * same administrator can edit it freely through the custom fields box or the
	 * REST API.
	 *
	 * So an administrator who can already edit the post is allowed through, but only
	 * when nothing has deliberately gated that key: a registered authorisation
	 * callback, or a filter on it, is treated as a real refusal and respected.
	 *
	 * @param int    $post_id Post the meta belongs to.
	 * @param string $key     Meta key.
	 * @return bool
	 */
	public static function can_write_meta( $post_id, $key ) {
		$post_id = absint( $post_id );
		$key     = (string) $key;

		if ( current_user_can( 'edit_post_meta', $post_id, $key ) ) {
			return true;
		}

		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return false;
		}

		$post_type = (string) get_post_type( $post_id );

		// Someone has taken a deliberate position on this key; respect it.
		if ( has_filter( 'auth_post_meta_' . $key ) || has_filter( 'auth_post_' . $post_type . '_meta_' . $key ) ) {
			return false;
		}

		$registered = function_exists( 'get_registered_meta_keys' ) ? get_registered_meta_keys( 'post', $post_type ) : array();

		if ( isset( $registered[ $key ]['auth_callback'] ) && null !== $registered[ $key ]['auth_callback'] ) {
			return false;
		}

		return true;
	}

	/**
	 * Turns a failed match into an error a caller can act on.
	 *
	 * A bare "not found" is what starts an edit loop: the caller has no way to learn
	 * how the stored text actually differs, so it guesses again. The near misses and
	 * match positions are what turn a retry into a correction.
	 *
	 * @param array<string, mixed> $outcome Result from Content_Matcher::compute_replacement().
	 * @param string               $stored  The stored value that was searched.
	 * @param string               $old     The text the caller looked for.
	 * @param string               $label   Human description of what was being edited.
	 * @return WP_Error
	 */
	public static function match_error( array $outcome, $stored, $old, $label ) {
		$code = isset( $outcome['code'] ) ? (string) $outcome['code'] : 'no_match';

		if ( 'multiple_matches' === $code ) {
			return Site_Support::error(
				'mosmcp_multiple_matches',
				sprintf(
					/* translators: 1: number of matches, 2: what was being edited. */
					__( 'That text appears %1$d times in %2$s, so it is not clear which one to change. Include more of the surrounding wording so the text you pass appears exactly once, or set replace_all to true if every occurrence really should change.', 'mosmcp-abilities' ),
					(int) $outcome['count'],
					$label
				),
				Site_Support::CAUSE_INVALID_INPUT,
				true,
				array(
					'match_count'     => (int) $outcome['count'],
					'match_positions' => isset( $outcome['positions'] ) ? $outcome['positions'] : array(),
				)
			);
		}

		if ( 'no_change' === $code ) {
			return Site_Support::error(
				'mosmcp_no_change',
				__( 'The replacement text is identical to the text being replaced, so there is nothing to change.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_INVALID_INPUT,
				true
			);
		}

		if ( 'empty_old' === $code ) {
			return Site_Support::error(
				'mosmcp_empty_search_text',
				__( 'No text to find was given. Pass the exact wording to replace.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_INVALID_INPUT,
				true
			);
		}

		if ( 'not_text' === $code ) {
			return Site_Support::error(
				'mosmcp_not_text',
				sprintf(
					/* translators: %s: what was being edited. */
					__( 'The value stored in %s is not plain text, so it cannot be edited by find-and-replace. Write the whole value through the ability that owns it instead.', 'mosmcp-abilities' ),
					$label
				),
				Site_Support::CAUSE_NOT_FOUND,
				false
			);
		}

		$near    = Content_Matcher::near_misses( (string) $stored, (string) $old );
		$message = sprintf(
			/* translators: %s: what was being edited. */
			__( 'That exact text was not found in %s. Quotes, ampersands and spacing are matched forgivingly, so a miss usually means the stored wording genuinely differs.', 'mosmcp-abilities' ),
			$label
		);

		if ( ! empty( $near ) ) {
			$message .= ' ' . __( 'The closest stored passages are given below; copy the wording from one of them exactly.', 'mosmcp-abilities' );
		} else {
			$message .= ' ' . __( 'Nothing similar was found either, so check you are editing the right item.', 'mosmcp-abilities' );
		}

		return Site_Support::error(
			'mosmcp_text_not_found',
			$message,
			Site_Support::CAUSE_NOT_FOUND,
			true,
			array(
				'nearby_text'   => $near,
				'stored_length' => strlen( (string) $stored ),
			)
		);
	}

	/**
	 * Refuses an edit that would damage the structure of the stored value.
	 *
	 * @param string $before      Value before the edit.
	 * @param string $after       Value after the edit.
	 * @param bool   $check_blocks Whether block markup applies to this value.
	 * @return WP_Error|null WP_Error when the edit must not be written, null otherwise.
	 */
	public static function integrity_error( $before, $after, $check_blocks ) {
		if ( Content_Matcher::json_broken_by_edit( $before, $after ) ) {
			return Site_Support::error(
				'mosmcp_json_would_break',
				__( 'Refused: the stored value is valid JSON now and would not be after this change, which would make whatever reads it fail. Nothing was written. This usually means the text being replaced spans a quote or a bracket that JSON needs.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_CONFLICT,
				true
			);
		}

		if ( $check_blocks && Content_Matcher::blocks_broken_by_edit( $before, $after ) ) {
			return Site_Support::error(
				'mosmcp_blocks_would_break',
				__( 'Refused: this change would damage the block structure of the content, which makes blocks vanish from the editor and can lose the text inside them. Nothing was written. Replace only the words, leaving the surrounding block comments untouched.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_CONFLICT,
				true
			);
		}

		return null;
	}

	/**
	 * Refuses a value that is PHP-serialized.
	 *
	 * @param mixed  $value Stored value.
	 * @param string $label Human description of what holds it.
	 * @return WP_Error|null
	 */
	public static function serialized_error( $value, $label ) {
		if ( ! Content_Matcher::is_serialized_value( $value ) ) {
			return null;
		}

		return Site_Support::error(
			'mosmcp_serialized_value',
			sprintf(
				/* translators: %s: what holds the value. */
				__( 'Refused: %s holds a PHP-serialized value, which records the length of every piece of text inside it. Replacing text without rewriting those lengths produces a value nothing can read back, so this is never safe as a find-and-replace. Change it through the plugin or setting that owns it.', 'mosmcp-abilities' ),
				$label
			),
			Site_Support::CAUSE_CONFLICT,
			false
		);
	}
}
