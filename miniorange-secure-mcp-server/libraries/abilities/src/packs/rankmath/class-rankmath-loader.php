<?php
/**
 * Loader for the Rank Math ability pack.
 *
 * This mirrors what Pack_Registry does for the framework packs — instantiate,
 * check the dependency, register the category, register each ability through the
 * Ability_Registrar — but keeps the pack in its own "rankmath" group rather than
 * adding it to Pack_Registry's manifest. A pack listed there registers as part of
 * the "core" group, which a host cannot switch off through the packs allow-list;
 * an integration with a third-party plugin has to stay separately excludable.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Rankmath;

use MoSMCP\Abilities\Ability;
use MoSMCP\Abilities\Ability_Registrar;
use MoSMCP\Abilities\Naming;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Rankmath_Loader
 */
class Rankmath_Loader {

	/**
	 * Memoized pack instance for this request, or false once found unavailable.
	 *
	 * Both Abilities API init hooks reach this class, and building the pack's
	 * abilities constructs a full Ability with its schemas; without this the work
	 * would be repeated on the second hook.
	 *
	 * @var Rankmath_Pack|false|null
	 */
	private static $pack = null;

	/**
	 * Registers the Rank Math ability category.
	 *
	 * Hooked (through Abilities_Library) to wp_abilities_api_categories_init.
	 *
	 * @return void
	 */
	public static function register_categories() {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		$pack = self::pack();
		if ( ! $pack ) {
			return;
		}

		$category = $pack->category();
		if ( empty( $category['slug'] ) ) {
			return;
		}

		Naming::register_category(
			(string) $category['slug'],
			array(
				'label'       => isset( $category['label'] ) ? (string) $category['label'] : '',
				'description' => isset( $category['description'] ) ? (string) $category['description'] : '',
			)
		);
	}

	/**
	 * Registers every Rank Math ability through the registrar.
	 *
	 * Hooked (through Abilities_Library) to wp_abilities_api_init.
	 *
	 * @return void
	 */
	public static function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		$pack = self::pack();
		if ( ! $pack ) {
			return;
		}

		foreach ( $pack->abilities() as $ability ) {
			if ( $ability instanceof Ability ) {
				Ability_Registrar::register( $ability );
			}
		}
	}

	/**
	 * The pack instance, or false when Rank Math is not installed.
	 *
	 * @return Rankmath_Pack|false
	 */
	private static function pack() {
		if ( null !== self::$pack ) {
			return self::$pack;
		}

		$pack       = new Rankmath_Pack();
		self::$pack = $pack->is_available() ? $pack : false;

		return self::$pack;
	}
}
