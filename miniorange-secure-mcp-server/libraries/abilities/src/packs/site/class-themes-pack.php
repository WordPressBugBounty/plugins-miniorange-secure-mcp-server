<?php
/**
 * Themes ability pack: definitions for the mosmcp/theme-* abilities.
 *
 * Read-only by design in this release. Switching, installing, and deleting a theme
 * can leave a site visibly broken with no way back over the same connection, so
 * those wait for the draft-and-rollback machinery rather than shipping without it.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Site;

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
 * directive covers IDE and Plugin Check runs that don't read that ruleset).
 */
// phpcs:disable WordPress.WP.I18n.TextDomainMismatch

/**
 * Class Themes_Pack
 *
 * Declares the theme abilities and their governed contract. Execute logic lives
 * in Themes_Provider.
 */
class Themes_Pack extends Ability_Pack {

	/**
	 * Ability category slug.
	 */
	const CATEGORY = 'mosmcp-themes';

	/**
	 * Ability category for theme abilities.
	 *
	 * @return array Category definition.
	 */
	public function category() {
		return array(
			'slug'        => self::CATEGORY,
			'label'       => __( 'Themes', 'mosmcp-abilities' ),
			'description' => __( 'Inspect the themes installed on this site and the one currently active.', 'mosmcp-abilities' ),
		);
	}

	/**
	 * The theme abilities.
	 *
	 * @return Ability[]
	 */
	public function abilities() {
		return array(
			$this->theme_list(),
			$this->theme_get_active(),
			$this->theme_get(),
		);
	}

	/**
	 * Defines the mosmcp/theme-list ability.
	 *
	 * @return Ability
	 */
	private function theme_list() {
		return new Ability(
			'mosmcp/theme-list',
			array(
				'label'         => __( 'List Themes', 'mosmcp-abilities' ),
				'description'   => __( 'Lists the themes installed on this site, with version, author, parent theme, and whether an update is available. Read-only. For full detail on the theme currently in use, call mosmcp/theme-get-active instead.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'switch_themes',
				'annotations'   => Site_Support::annotations( true, false, true, false ),
				'execute'       => array( Themes_Provider::class, 'theme_list' ),
				'input_schema'  => Schema::object(
					array(
						'status'          => Schema::str(
							__( 'Which themes to return: "all", "active", or "inactive".', 'mosmcp-abilities' ),
							array(
								'enum'    => array( 'all', 'active', 'inactive' ),
								'default' => 'all',
							)
						),
						'search'          => Schema::str( __( 'Optional keyword matched against the theme name and folder.', 'mosmcp-abilities' ) ),
						'has_update'      => Schema::boolean( __( 'When true, returns only themes with an update available.', 'mosmcp-abilities' ) ),
						'refresh_updates' => Schema::boolean(
							__( 'When true, asks WordPress.org for fresh update data before answering. Slower; leave false unless the answer looks stale.', 'mosmcp-abilities' ),
							array( 'default' => false )
						),
					)
				),
				'output_schema' => Schema::object(
					array(
						'showing' => Schema::int(),
						'total'   => Schema::int( __( 'Total installed themes before any filter was applied.', 'mosmcp-abilities' ) ),
						'active'  => Schema::str( __( 'Stylesheet of the theme currently active.', 'mosmcp-abilities' ) ),
						'themes'  => Schema::arr( self::theme_summary() ),
					),
					array( 'showing', 'total', 'themes' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/theme-get-active ability.
	 *
	 * @return Ability
	 */
	private function theme_get_active() {
		return new Ability(
			'mosmcp/theme-get-active',
			array(
				'label'         => __( 'Get Active Theme', 'mosmcp-abilities' ),
				'description'   => __( 'Returns full detail about the theme this site is currently running: version, author, parent theme, whether it is a block theme, the page templates it offers, and the features it supports. Read-only. Useful before editing a page, because the available page templates come from the active theme.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'edit_theme_options',
				'annotations'   => Site_Support::annotations( true, false, true, false ),
				'execute'       => array( Themes_Provider::class, 'theme_get_active' ),
				'input_schema'  => Schema::object( array() ),
				'output_schema' => self::theme_detail(),
			)
		);
	}

	/**
	 * Defines the mosmcp/theme-get ability.
	 *
	 * @return Ability
	 */
	private function theme_get() {
		return new Ability(
			'mosmcp/theme-get',
			array(
				'label'         => __( 'Get Theme by Slug', 'mosmcp-abilities' ),
				'description'   => __( 'Returns full detail about one installed theme, named by its folder. Read-only. Use mosmcp/theme-list to find the folder names installed on this site.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'switch_themes',
				'annotations'   => Site_Support::annotations( true, false, true, false ),
				'execute'       => array( Themes_Provider::class, 'theme_get' ),
				'input_schema'  => Schema::object(
					array(
						'stylesheet' => Schema::str( __( 'The theme folder name, for example "twentytwentyfour". This is what mosmcp/theme-list returns as "stylesheet".', 'mosmcp-abilities' ) ),
					),
					array( 'stylesheet' )
				),
				'output_schema' => self::theme_detail(),
			)
		);
	}

	/**
	 * The compact per-theme shape returned by the list ability.
	 *
	 * @return array<string, mixed>
	 */
	private static function theme_summary() {
		return Schema::object(
			array(
				'stylesheet'       => Schema::str( __( 'Theme folder name, and the identifier every other theme ability takes.', 'mosmcp-abilities' ) ),
				'name'             => Schema::str(),
				'version'          => Schema::str(),
				'author'           => Schema::str(),
				'status'           => Schema::str( __( 'Either "active" or "inactive".', 'mosmcp-abilities' ) ),
				'is_child'         => Schema::boolean( __( 'True when this theme inherits from a parent theme.', 'mosmcp-abilities' ) ),
				'parent'           => Schema::str( __( 'Stylesheet of the parent theme, or empty when there is none.', 'mosmcp-abilities' ) ),
				'update_available' => Schema::boolean(),
				'new_version'      => Schema::str(),
			),
			array( 'stylesheet', 'name', 'status' )
		);
	}

	/**
	 * The full per-theme shape returned by the two get abilities.
	 *
	 * @return array<string, mixed>
	 */
	private static function theme_detail() {
		return Schema::object(
			array(
				'stylesheet'       => Schema::str( __( 'Theme folder name.', 'mosmcp-abilities' ) ),
				'template'         => Schema::str( __( 'Folder of the theme providing the templates. Differs from stylesheet only for a child theme.', 'mosmcp-abilities' ) ),
				'name'             => Schema::str(),
				'version'          => Schema::str(),
				'author'           => Schema::str(),
				'description'      => Schema::str(),
				'theme_uri'        => Schema::str(),
				'status'           => Schema::str( __( 'Either "active" or "inactive".', 'mosmcp-abilities' ) ),
				'is_child'         => Schema::boolean(),
				'parent'           => Schema::object(
					array(
						'stylesheet' => Schema::str(),
						'name'       => Schema::str(),
						'version'    => Schema::str(),
					),
					array()
				),
				'is_block_theme'   => Schema::boolean( __( 'True for a block (full site editing) theme, false for a classic theme.', 'mosmcp-abilities' ) ),
				'requires_wp'      => Schema::str(),
				'requires_php'     => Schema::str(),
				'tags'             => Schema::arr( Schema::str() ),
				'screenshot'       => Schema::str(),
				'update_available' => Schema::boolean(),
				'new_version'      => Schema::str(),
				'page_templates'   => Schema::arr(
					Schema::object(
						array(
							'slug'  => Schema::str(),
							'label' => Schema::str(),
						)
					),
					__( 'Page templates this theme offers. Only populated for the active theme, because WordPress reads them from the theme in use.', 'mosmcp-abilities' )
				),
				'supports'         => Schema::arr( Schema::str(), __( 'Theme features the active theme declares support for. Only populated for the active theme.', 'mosmcp-abilities' ) ),
				'notes'            => Schema::arr( Schema::str(), __( 'Anything about this theme the caller should know before acting on it.', 'mosmcp-abilities' ) ),
			),
			array( 'stylesheet', 'name', 'status' )
		);
	}
}
