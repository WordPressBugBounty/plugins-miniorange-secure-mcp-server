<?php
/**
 * Shared input-safety helpers for first-party abilities.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Support;

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
 * directive covers IDE and Plugin Check runs that don't load that ruleset).
 */
// phpcs:disable WordPress.WP.I18n.TextDomainMismatch

/**
 * Class Permissions
 *
 * Central home for the security rules the framework enforces on every ability,
 * regardless of what an individual callback does. The reserved-key blocklist here
 * is the single mechanism that closes the privilege-escalation class of bug: an
 * ability that reads or writes an arbitrary user meta key can never touch the
 * capability, role-level, or session stores.
 */
class Permissions {

	/**
	 * User meta keys that abilities may never read or write, matched case-insensitively.
	 *
	 * @return string[]
	 */
	private static function reserved_exact_keys() {
		return array(
			'session_tokens',
			'user_level',
			'wp_user_level',
			'primary_blog',
			'source_domain',
			'use_ssl',
			'default_password_nag',
		);
	}

	/**
	 * Whether a user meta key is protected from ability access.
	 *
	 * Blocks the capability/role and session vectors under any table prefix
	 * (for example wp_capabilities, wp_2_capabilities, wp_user_level,
	 * wp_session_tokens), which is how a write-arbitrary-meta ability could
	 * otherwise escalate a low-privileged user to administrator.
	 *
	 * @param string $key Meta key to test.
	 * @return bool True when the key is reserved and must be refused.
	 */
	public static function is_reserved_user_meta_key( $key ) {
		$key = strtolower( trim( (string) $key ) );

		if ( '' === $key ) {
			return true;
		}

		if ( in_array( $key, self::reserved_exact_keys(), true ) ) {
			return true;
		}

		// Capability and user-level keys under any table prefix.
		if ( 1 === preg_match( '/(^|_)(capabilities|user_level)$/', $key ) ) {
			return true;
		}

		// Session token stores under any table prefix.
		if ( 1 === preg_match( '/session_tokens$/', $key ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Screens the meta-key inputs of an ability against the reserved-key blocklist.
	 *
	 * Runs before the developer callback, so a reserved key never reaches the
	 * underlying update_user_meta()/get_user_meta()/delete_user_meta() call.
	 *
	 * @param array<string, mixed> $input  Ability input.
	 * @param string[]             $fields Input field names that carry a meta key.
	 * @return true|WP_Error True when every supplied key is allowed; WP_Error otherwise.
	 */
	public static function screen_meta_keys( array $input, array $fields ) {
		foreach ( $fields as $field ) {
			if ( ! isset( $input[ $field ] ) ) {
				continue;
			}

			if ( self::is_reserved_user_meta_key( (string) $input[ $field ] ) ) {
				return new WP_Error(
					'mosmcp_forbidden_meta_key',
					__( 'That meta key is protected and cannot be read or modified through this ability.', 'mosmcp-abilities' )
				);
			}
		}

		return true;
	}
}
