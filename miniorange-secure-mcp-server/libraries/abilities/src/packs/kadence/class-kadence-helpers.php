<?php
/**
 * Shared helpers for the Kadence ability group: registration wrapper, category,
 * dispatch, MCP annotations, dependency gating, and permission checks.
 *
 * The group is split into two independently dependency-gated halves: block
 * abilities require the Kadence Blocks plugin (KADENCE_BLOCKS_VERSION) and
 * theme-layout abilities require the Kadence theme (KADENCE_VERSION). Either
 * half registers nothing when its dependency is absent, so a site running only
 * the theme, only the blocks plugin, or both gets exactly the abilities it can
 * actually service.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Kadence;

use MoSMCP\Abilities\Naming;

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
 * Class Kadence_Helpers
 *
 * Entry point wired to the Abilities API hooks in Abilities_Library. Adding a new
 * Kadence ability domain means adding one *_Abilities::register_all() call in
 * register_abilities(), guarded by the matching dependency check.
 */
class Kadence_Helpers {

	/**
	 * Ability name namespace (re-prefixed to the host prefix by Naming).
	 */
	const NS = 'mosmcp';

	/**
	 * Ability category slug shared by every Kadence ability.
	 */
	const CATEGORY = 'mosmcp-kadence';

	/**
	 * Whether the Kadence Blocks plugin is active (block abilities depend on it).
	 *
	 * @return bool
	 */
	public static function blocks_active() {
		return defined( 'KADENCE_BLOCKS_VERSION' ) || class_exists( 'Kadence_Blocks_Frontend' );
	}

	/**
	 * Whether the Kadence theme (or a child of it) is active (theme-layout abilities depend on it).
	 *
	 * @return bool
	 */
	public static function theme_active() {
		return defined( 'KADENCE_VERSION' ) || function_exists( 'kadence' );
	}

	/**
	 * Registers one Kadence ability under the plugin namespace with shared defaults.
	 *
	 * Mirrors the governed contract the other vendor packs apply: MCP is the single
	 * governed door, so abilities are never exposed on the public REST surface, and
	 * every ability carries its explicit MCP annotation hints.
	 *
	 * @param string              $key         Ability key without namespace, e.g. 'page-read'.
	 * @param array<string, mixed> $args        Ability registration args (label, description, schemas, callbacks).
	 * @param array<string, bool>  $annotations The four MCP hints from {@see self::ann()}.
	 * @return void
	 */
	public static function register( $key, array $args, array $annotations ) {
		$args['category'] = self::CATEGORY;

		$meta                 = ( isset( $args['meta'] ) && is_array( $args['meta'] ) ) ? $args['meta'] : array();
		$meta['show_in_rest'] = false;
		unset( $meta['mcp'] );
		$meta['annotations'] = $annotations;
		$args['meta']        = $meta;

		Naming::register_ability( self::NS . '/' . $key, $args );
	}

	/**
	 * Builds the four MCP annotation hints.
	 *
	 * @param bool $readonly    Read-only hint.
	 * @param bool $destructive Destructive hint.
	 * @param bool $idempotent  Idempotent hint.
	 * @param bool $open_world  Open-world hint (touches publicly rendered output).
	 * @return array<string, bool>
	 */
	public static function ann( $readonly, $destructive, $idempotent, $open_world ) {
		return array(
			'readonly'    => (bool) $readonly,
			'destructive' => (bool) $destructive,
			'idempotent'  => (bool) $idempotent,
			'open_world'  => (bool) $open_world,
		);
	}

	/**
	 * A permission callback requiring edit rights on the post referenced by input['id'].
	 *
	 * @return callable
	 */
	public static function can_edit_post() {
		return static function ( $input ) {
			$id = isset( $input['id'] ) ? absint( $input['id'] ) : 0;
			return $id > 0 && current_user_can( 'edit_post', $id );
		};
	}

	/**
	 * A permission callback requiring a capability with no object context.
	 *
	 * @param string $capability Capability to check.
	 * @return callable
	 */
	public static function can( $capability ) {
		$capability = (string) $capability;
		return static function () use ( $capability ) {
			return current_user_can( $capability );
		};
	}

	/**
	 * Registers the Kadence ability category. No-op unless a Kadence dependency is active.
	 *
	 * Hooked (via Abilities_Library) to wp_abilities_api_categories_init.
	 *
	 * @return void
	 */
	public static function register_categories() {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}
		if ( ! self::blocks_active() && ! self::theme_active() ) {
			return;
		}

		Naming::register_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Kadence', 'mosmcp-abilities' ),
				'description' => __( 'Read and build Kadence block layouts and manage Kadence theme per-page layout settings.', 'mosmcp-abilities' ),
			)
		);
	}

	/**
	 * Registers every available Kadence ability domain, each guarded by its dependency.
	 *
	 * Hooked (via Abilities_Library) to wp_abilities_api_init.
	 *
	 * @return void
	 */
	public static function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		if ( self::blocks_active() ) {
			Kadence_Read_Abilities::register_all();
			Kadence_Block_Write_Abilities::register_all();
			Kadence_Insert_Abilities::register_all();
		}

		if ( self::theme_active() ) {
			Kadence_Theme_Abilities::register_all();
		}
	}
}
