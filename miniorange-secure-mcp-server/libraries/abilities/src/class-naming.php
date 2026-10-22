<?php
/**
 * Prefix-aware naming + registration helpers.
 *
 * Abilities and categories are authored under {@see Config::AUTHORING_PREFIX}
 * ("mosmcp") throughout the library source. These helpers re-prefix an authored
 * name/slug to the host's configured prefix at registration time, so the same
 * source registers as `mosmcp/…` for this plugin or `acme/…` for another host —
 * with no changes to the authored literals.
 *
 * The register_* wrappers are drop-in replacements for the core
 * wp_register_ability()/wp_register_ability_category() functions: every call
 * site in the library goes through them.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Naming
 */
class Naming {

	/**
	 * Re-prefixes an authored ability name ("mosmcp/x" -> "{prefix}/x").
	 *
	 * @param string $authored Authored ability name.
	 * @return string
	 */
	public static function name( $authored ) {
		$authored = (string) $authored;
		$auth     = Config::AUTHORING_PREFIX . '/';

		if ( 0 === strpos( $authored, $auth ) ) {
			return Config::prefix() . '/' . substr( $authored, strlen( $auth ) );
		}

		return $authored;
	}

	/**
	 * Re-prefixes an authored category slug ("mosmcp-x" -> "{prefix}-x").
	 *
	 * @param string $slug Authored category slug.
	 * @return string
	 */
	public static function category( $slug ) {
		$slug = (string) $slug;
		$auth = Config::AUTHORING_PREFIX . '-';

		if ( 0 === strpos( $slug, $auth ) ) {
			return Config::prefix() . '-' . substr( $slug, strlen( $auth ) );
		}

		return $slug;
	}

	/**
	 * Registers an ability, re-prefixing its name and its declared category.
	 *
	 * Drop-in wrapper for wp_register_ability().
	 *
	 * @param string               $name Authored ability name.
	 * @param array<string, mixed> $args Ability registration args.
	 * @return mixed The value returned by wp_register_ability().
	 */
	public static function register_ability( $name, $args ) {
		if ( is_array( $args ) && isset( $args['category'] ) ) {
			$args['category'] = self::category( (string) $args['category'] );
		}

		return wp_register_ability( self::name( $name ), $args );
	}

	/**
	 * Registers an ability category, re-prefixing its slug.
	 *
	 * Drop-in wrapper for wp_register_ability_category().
	 *
	 * @param string               $slug Authored category slug.
	 * @param array<string, mixed> $args Category registration args.
	 * @return mixed The value returned by wp_register_ability_category().
	 */
	public static function register_category( $slug, $args ) {
		return wp_register_ability_category( self::category( $slug ), $args );
	}
}
