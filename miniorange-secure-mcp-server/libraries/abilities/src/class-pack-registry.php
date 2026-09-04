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
			if ( empty( self::abilities_for( $pack ) ) ) {
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
			foreach ( self::abilities_for( $pack ) as $ability ) {
				if ( $ability instanceof Ability ) {
					Ability_Registrar::register( $ability );
				}
			}
		}
	}

	/**
	 * Instantiates the manifest and filters it to packs whose dependency is met.
	 *
	 * Memoized: both Abilities API init hooks (register_categories(),
	 * register_abilities()) call this once per request, and without caching each
	 * call would re-instantiate every pack class and re-run is_available() for
	 * a second time — pure duplicated work within the same request.
	 *
	 * @return Ability_Pack[]
	 */
	private static function active_packs() {
		if ( null !== self::$active_packs ) {
			return self::$active_packs;
		}

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

		self::$active_packs = $packs;

		return $packs;
	}

	/**
	 * Memoized cache of {@see active_packs()}'s result for this request.
	 *
	 * @var Ability_Pack[]|null
	 */
	private static $active_packs = null;

	/**
	 * Returns (and memoizes) a pack's ability list.
	 *
	 * Building a pack's abilities() constructs a full Ability value object with
	 * its schema per ability; without this cache, register_categories() (which
	 * only needs to know whether the list is non-empty) and register_abilities()
	 * (which needs the full list) would each rebuild it from scratch.
	 *
	 * @param Ability_Pack $pack The pack.
	 * @return Ability[]
	 */
	private static function abilities_for( Ability_Pack $pack ) {
		$key = spl_object_id( $pack );

		if ( ! array_key_exists( $key, self::$abilities_cache ) ) {
			self::$abilities_cache[ $key ] = $pack->abilities();
		}

		return self::$abilities_cache[ $key ];
	}

	/**
	 * Memoized cache of {@see abilities_for()}'s results for this request, keyed
	 * by spl_object_id() of the owning pack instance.
	 *
	 * @var array<int, Ability[]>
	 */
	private static $abilities_cache = array();
}
