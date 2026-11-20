<?php
/**
 * Site overview ability pack: one call that tells an AI how this site is built.
 *
 * An assistant that knows the site uses Elementor, a block theme and WooCommerce
 * picks the right abilities the first time instead of trying generic ones that
 * fail or, worse, change something the visitor never sees.
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
 * Class Site_Overview_Pack
 *
 * Declares the site overview ability. Logic lives in Site_Overview_Provider.
 */
class Site_Overview_Pack extends Ability_Pack {

	/**
	 * Ability category slug.
	 */
	const CATEGORY = 'mosmcp-site-overview';

	/**
	 * Ability category for the overview.
	 *
	 * @return array Category definition.
	 */
	public function category() {
		return array(
			'slug'        => self::CATEGORY,
			'label'       => __( 'Site Overview', 'mosmcp-abilities' ),
			'description' => __( 'A summary of how the site is built and what an AI can do on it.', 'mosmcp-abilities' ),
		);
	}

	/**
	 * The overview ability.
	 *
	 * @return Ability[]
	 */
	public function abilities() {
		$named = Schema::object(
			array(
				'name' => Schema::str(),
				'kind' => Schema::str(),
			)
		);

		return array(
			new Ability(
				'mosmcp/site-get-overview',
				array(
					'label'         => __( 'Get Site Overview', 'mosmcp-abilities' ),
					'description'   => __( 'Returns, in one call, how this WordPress site is built and what you can do on it: the site\'s name and address, the theme and whether it is a block theme, which page builder is used (Elementor, Divi, Bricks, Kadence and others) and which abilities edit its pages, the shop, SEO, forms, fields and language plugins in use, the caches that are cleared automatically after changes, how much content there is, and every group of abilities on the site with how many this connection may use. Read-only. Call it first in a conversation about a site, before choosing which abilities to use. Also known as: about this site, site summary, what is this site built with, what can you do here, site info, tech stack.', 'mosmcp-abilities' ),
					'category'      => self::CATEGORY,
					'capability'    => 'edit_posts',
					'annotations'   => Site_Support::annotations( true, false, true, false ),
					'execute'       => array( Site_Overview_Provider::class, 'overview' ),
					'input_schema'  => Schema::object( array() ),
					'output_schema' => Schema::object(
						array(
							'site'               => Schema::object(
								array(
									'name'                => Schema::str(),
									'url'                 => Schema::str(),
									'language'            => Schema::str(),
									'timezone'            => Schema::str(),
									'wordpress_version'   => Schema::str(),
									'multisite'           => Schema::boolean(),
									'search_engines'      => Schema::boolean( __( 'False when the site asks search engines not to index it.', 'mosmcp-abilities' ) ),
									'permalink_structure' => Schema::str(),
									'homepage'            => Schema::str( __( 'What the front page shows: "latest posts" or the title of the static page.', 'mosmcp-abilities' ) ),
								)
							),
							'theme'              => Schema::object(
								array(
									'name'           => Schema::str(),
									'stylesheet'     => Schema::str(),
									'parent'         => Schema::str( __( 'Parent theme folder, when this is a child theme.', 'mosmcp-abilities' ) ),
									'version'        => Schema::str(),
									'is_block_theme' => Schema::boolean( __( 'True for a block theme: layout lives in templates, menus in Navigation blocks.', 'mosmcp-abilities' ) ),
									'has_menus'      => Schema::boolean( __( 'Whether the theme has classic menu locations.', 'mosmcp-abilities' ) ),
									'has_widgets'    => Schema::boolean( __( 'Whether the theme has classic widget areas.', 'mosmcp-abilities' ) ),
								)
							),
							'builders'           => Schema::arr(
								Schema::object(
									array(
										'name'      => Schema::str(),
										'abilities' => Schema::str( __( 'Prefix of the abilities that edit its pages, such as "mosmcp/elementor-", or empty when there are none.', 'mosmcp-abilities' ) ),
										'abilities_enabled' => Schema::boolean( __( 'Whether this connection may use those abilities. False means they exist but an administrator has not switched them on for this role.', 'mosmcp-abilities' ) ),
										'edits_with_blocks' => Schema::boolean( __( 'True for builders that add blocks to the block editor (Kadence Blocks, Spectra, GenerateBlocks): their pages are edited with the block abilities.', 'mosmcp-abilities' ) ),
									)
								),
								__( 'Page builders active on the site.', 'mosmcp-abilities' )
							),
							'integrations'       => Schema::arr( $named, __( 'Notable plugins in use, with their kind (shop, seo, forms, fields, languages, events, learning, membership, email, backup, security).', 'mosmcp-abilities' ) ),
							'caches'             => Schema::arr( Schema::str(), __( 'Caches detected; pages are cleared in them automatically after a change.', 'mosmcp-abilities' ) ),
							'content'            => Schema::object(
								array(
									'posts_published'  => Schema::int(),
									'posts_draft'      => Schema::int(),
									'pages_published'  => Schema::int(),
									'pages_draft'      => Schema::int(),
									'products'         => Schema::int( __( 'Published WooCommerce products, or 0.', 'mosmcp-abilities' ) ),
									'menus'            => Schema::int( __( 'Classic menus.', 'mosmcp-abilities' ) ),
									'navigation_menus' => Schema::int( __( 'Block-theme Navigation menus.', 'mosmcp-abilities' ) ),
								)
							),
							'ability_categories' => Schema::arr(
								Schema::object(
									array(
										'slug'       => Schema::str(),
										'label'      => Schema::str(),
										'registered' => Schema::int( __( 'Abilities in this group on the site.', 'mosmcp-abilities' ) ),
										'available'  => Schema::int( __( 'How many this connection may use, or -1 when unknown.', 'mosmcp-abilities' ) ),
									)
								)
							),
							'recommendations'    => Schema::arr( Schema::str(), __( 'What to use, and what to avoid, on this particular site.', 'mosmcp-abilities' ) ),
						),
						array( 'site', 'theme', 'builders', 'integrations', 'ability_categories', 'recommendations' )
					),
				)
			),
		);
	}
}
