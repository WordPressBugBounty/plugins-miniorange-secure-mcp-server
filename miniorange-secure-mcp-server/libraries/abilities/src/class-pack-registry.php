<?php
/**
 * Loads first-party ability packs and drives their registration.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities;

use MoSMCP\Abilities\Packs\Core\Categories_Pack;
use MoSMCP\Abilities\Packs\Core\Media_Pack;
use MoSMCP\Abilities\Packs\Core\Pages_Pack;
use MoSMCP\Abilities\Packs\Core\Posts_Pack;
use MoSMCP\Abilities\Packs\Core\Revisions_Pack;
use MoSMCP\Abilities\Packs\Core\Tags_Pack;
use MoSMCP\Abilities\Packs\Comments\Comments_Pack;
use MoSMCP\Abilities\Packs\Cpt\Cpt_Pack;
use MoSMCP\Abilities\Packs\Elementor\Elementor_Pack;
use MoSMCP\Abilities\Packs\Users\Users_Pack;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Pack_Registry
 *
 * The manifest of first-party ability packs and the glue between the Abilities
 * API init hooks and the Ability_Registrar. Packs are appended to the manifest
 * as each one is built, reviewed, and merged; a pack whose dependency is missing
 * is skipped, and a pack with no abilities registers nothing (including no
 * empty category).
 */
class Pack_Registry {

	/**
	 * The ordered manifest of ability pack classes.
	 *
	 * @return string[]
	 */
	private static function pack_classes() {
		return array(
			// Merge order: core, users, comments, woocommerce, acf, cpt, yoast, forms.
			// Each is appended here once its abilities have been reviewed.
			Posts_Pack::class,
			Pages_Pack::class,
			Categories_Pack::class,
			Tags_Pack::class,
			Media_Pack::class,
			Revisions_Pack::class,
			Users_Pack::class,
			Comments_Pack::class,
			Cpt_Pack::class,
			Elementor_Pack::class,
		);
	}

	/**
	 * Registers the category for every available, non-empty pack.
	 *
	 * Hooked to wp_abilities_api_categories_init.
	 *
	 * @return void
	 */
	public static function register_categories() {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		foreach ( self::active_packs() as $pack ) {
			if ( empty( $pack->abilities() ) ) {
				continue;
			}

			$category = $pack->category();
			if ( empty( $category['slug'] ) ) {
				continue;
			}

			Naming::register_category(
				(string) $category['slug'],
				array(
					'label'       => isset( $category['label'] ) ? (string) $category['label'] : '',
					'description' => isset( $category['description'] ) ? (string) $category['description'] : '',
				)
			);
		}
	}

	/**
	 * Registers every ability from every available pack through the registrar.
	 *
	 * Hooked to wp_abilities_api_init.
	 *
	 * @return void
	 */
	public static function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		foreach ( self::active_packs() as $pack ) {
			foreach ( $pack->abilities() as $ability ) {
				if ( $ability instanceof Ability ) {
					Ability_Registrar::register( $ability );
				}
			}
		}
	}

	/**
	 * Instantiates the manifest and filters it to packs whose dependency is met.
	 *
	 * @return Ability_Pack[]
	 */
	private static function active_packs() {
		$packs = array();

		foreach ( self::pack_classes() as $class ) {
			if ( ! class_exists( $class ) ) {
				continue;
			}

			$pack = new $class();
			if ( $pack instanceof Ability_Pack && $pack->is_available() ) {
				$packs[] = $pack;
			}
		}

		return $packs;
	}
}
