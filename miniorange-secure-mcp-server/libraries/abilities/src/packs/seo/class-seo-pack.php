<?php
/**
 * Read-only SEO ability pack: SEO data for people who may read a post, not edit it.
 *
 * Every SEO plugin's own read ability requires edit rights on the post, because
 * it returns what the editor sidebar shows. An agent kept strictly read-only (a
 * Subscriber reporting on SEO) therefore could not read SEO data at all. These
 * abilities need only permission to read the post, read whichever SEO plugin the
 * site uses, and change nothing.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Seo;

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

/**
 * Class Seo_Pack
 */
class Seo_Pack extends Ability_Pack {

	/**
	 * Ability category slug.
	 */
	const CATEGORY = 'mosmcp-seo-read';

	/**
	 * Ability category for the read-only SEO abilities.
	 *
	 * @return array Category definition.
	 */
	public function category() {
		return array(
			'slug'        => self::CATEGORY,
			'label'       => __( 'SEO (read-only)', 'mosmcp-abilities' ),
			'description' => __( 'Read the SEO title, meta description, focus keyword and indexing settings of posts and pages, whichever SEO plugin the site uses, without any editing rights.', 'mosmcp-abilities' ),
		);
	}

	/**
	 * The read-only SEO abilities.
	 *
	 * @return Ability[]
	 */
	public function abilities() {
		return array(
			$this->get_post(),
			$this->list_posts(),
		);
	}

	/**
	 * Defines the mosmcp/seo-get-post ability.
	 *
	 * @return Ability
	 */
	private function get_post() {
		return new Ability(
			'mosmcp/seo-get-post',
			array(
				'label'         => __( 'Read SEO Data of a Post', 'mosmcp-abilities' ),
				'description'   => sprintf(
					/* translators: %s: comma-separated SEO plugin names */
					__( 'Reads the SEO data of one post or page: the SEO title and meta description, each as saved and as search engines actually get it, the focus keywords, canonical URL, noindex and nofollow, and the social titles and images. Needs only permission to read the post, so it works for read-only and Subscriber accounts, and it changes nothing. Works with %s; with no SEO plugin it reports what WordPress itself outputs. Also known as: Rank Math SEO meta, Yoast SEO meta, get SEO title, read meta description, focus keyword, read-only SEO.', 'mosmcp-abilities' ),
					implode( ', ', Seo_Readers::names() )
				),
				'category'      => self::CATEGORY,
				'capability'    => 'read_post',
				'cap_args'      => self::id_args(),
				'annotations'   => self::annotations( true, false, true, false ),
				'execute'       => array( Seo_Provider::class, 'get_post' ),
				'input_schema'  => Schema::object(
					array(
						'id' => Schema::int( __( 'The ID of the post or page.', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
					),
					array( 'id' )
				),
				'output_schema' => Schema::object(
					array(
						'id'               => Schema::int(),
						'title'            => Schema::str( __( 'The post\'s own title.', 'mosmcp-abilities' ) ),
						'post_type'        => Schema::str(),
						'status'           => Schema::str(),
						'url'              => Schema::str(),
						'seo_plugin'       => self::plugin_schema(),
						'seo_title'        => self::text_schema( __( 'The SEO title. saved is what was typed for this post ("" when the site-wide template applies); effective is what search engines get.', 'mosmcp-abilities' ) ),
						'meta_description' => self::text_schema( __( 'The meta description, saved and effective as for the title.', 'mosmcp-abilities' ) ),
						'focus_keywords'   => Schema::arr( Schema::str(), __( 'The focus keywords, main one first.', 'mosmcp-abilities' ) ),
						'canonical'        => Schema::object(
							array(
								'saved'     => Schema::str(),
								'effective' => Schema::str(),
							)
						),
						'robots'           => Schema::object(
							array(
								'noindex'  => Schema::boolean( __( 'True when search engines are asked not to list the page.', 'mosmcp-abilities' ) ),
								'nofollow' => Schema::boolean(),
							)
						),
						'social'           => Schema::object(
							array(
								'og_title'            => Schema::str(),
								'og_description'      => Schema::str(),
								'og_image'            => Schema::str(),
								'twitter_title'       => Schema::str(),
								'twitter_description' => Schema::str(),
								'twitter_image'       => Schema::str(),
							)
						),
						'issues'           => Schema::arr( Schema::str( '', array( 'enum' => Seo_Provider::ISSUES ) ), __( 'What is worth fixing, if anything.', 'mosmcp-abilities' ) ),
						'notes'            => Schema::arr( Schema::str() ),
					),
					array( 'id', 'seo_plugin', 'seo_title', 'meta_description' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/seo-list-posts ability.
	 *
	 * @return Ability
	 */
	private function list_posts() {
		return new Ability(
			'mosmcp/seo-list-posts',
			array(
				'label'         => __( 'List Posts with SEO Data', 'mosmcp-abilities' ),
				'description'   => sprintf(
					/* translators: %s: comma-separated SEO plugin names */
					__( 'Lists posts and pages with their SEO title, meta description, focus keyword and indexing, newest first, for an SEO review across the site. Can return only the posts with one issue, such as a missing meta description or a title that is too long. Only posts the account may read are included, so it works for read-only and Subscriber accounts, and it changes nothing. Works with %s. Also known as: SEO audit, find posts missing meta descriptions, check SEO titles, Rank Math overview, Yoast overview.', 'mosmcp-abilities' ),
					implode( ', ', Seo_Readers::names() )
				),
				'category'      => self::CATEGORY,
				'capability'    => 'read',
				'annotations'   => self::annotations( true, false, true, false ),
				'execute'       => array( Seo_Provider::class, 'list_posts' ),
				'input_schema'  => Schema::object(
					array(
						'post_type' => Schema::str( __( 'Optional. Only this content type, such as "post" or "page". Leave out for every public type.', 'mosmcp-abilities' ) ),
						'status'    => Schema::str(
							__( 'Which status to include. Drafts and private posts are only included where the account may read them.', 'mosmcp-abilities' ),
							array(
								'enum'    => array_merge( Seo_Provider::STATUSES, array( 'any' ) ),
								'default' => 'publish',
							)
						),
						'issue'     => Schema::str(
							__( 'Optional. Only posts with this issue.', 'mosmcp-abilities' ),
							array( 'enum' => Seo_Provider::ISSUES )
						),
						'search'    => Schema::str( __( 'Optional. Only posts whose title or content contains these words.', 'mosmcp-abilities' ) ),
						'per_page'  => Schema::int(
							__( 'Posts per page.', 'mosmcp-abilities' ),
							array(
								'default' => Seo_Provider::DEFAULT_PER_PAGE,
								'minimum' => 1,
								'maximum' => Seo_Provider::MAX_PER_PAGE,
							)
						),
						'page'      => Schema::int(
							__( 'Which page of results, counting from 1.', 'mosmcp-abilities' ),
							array(
								'default' => 1,
								'minimum' => 1,
							)
						),
					)
				),
				'output_schema' => Schema::object(
					array(
						'seo_plugin'   => self::plugin_schema(),
						'page'         => Schema::int(),
						'per_page'     => Schema::int(),
						'returned'     => Schema::int(),
						'has_more'     => Schema::boolean( __( 'True when another page of results exists.', 'mosmcp-abilities' ) ),
						'scanned'      => Schema::int( __( 'How many posts were read to build this page.', 'mosmcp-abilities' ) ),
						'scan_limited' => Schema::boolean( __( 'True when the per-call reading limit was reached, so older posts were not looked at.', 'mosmcp-abilities' ) ),
						'items'        => Schema::arr(
							Schema::object(
								array(
									'id'               => Schema::int(),
									'title'            => Schema::str(),
									'url'              => Schema::str(),
									'post_type'        => Schema::str(),
									'status'           => Schema::str(),
									'seo_title'        => Schema::str( __( 'What search engines get.', 'mosmcp-abilities' ) ),
									'seo_title_length' => Schema::int(),
									'meta_description' => Schema::str( __( 'What search engines get.', 'mosmcp-abilities' ) ),
									'meta_description_length' => Schema::int(),
									'focus_keyword'    => Schema::str( __( 'The main focus keyword.', 'mosmcp-abilities' ) ),
									'noindex'          => Schema::boolean(),
									'issues'           => Schema::arr( Schema::str() ),
								)
							)
						),
						'notes'        => Schema::arr( Schema::str() ),
					),
					array( 'seo_plugin', 'items', 'has_more' )
				),
			)
		);
	}

	/**
	 * Schema for a saved/effective text pair.
	 *
	 * @param string $description Description.
	 * @return array<string, mixed>
	 */
	private static function text_schema( $description ) {
		return array_merge(
			Schema::object(
				array(
					'saved'     => Schema::str(),
					'effective' => Schema::str(),
					'length'    => Schema::int( __( 'Length of the effective text in characters.', 'mosmcp-abilities' ) ),
				)
			),
			array( 'description' => $description )
		);
	}

	/**
	 * Schema for the SEO plugin a result came from.
	 *
	 * @return array<string, mixed>
	 */
	private static function plugin_schema() {
		return Schema::object(
			array(
				'slug'    => Schema::str( __( 'rank-math, yoast, aioseo, seopress, or none when no SEO plugin is active.', 'mosmcp-abilities' ) ),
				'name'    => Schema::str(),
				'version' => Schema::str(),
			)
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

	/**
	 * Resolves the id input for the read_post capability check (cap_args).
	 *
	 * @return callable
	 */
	private static function id_args() {
		return static function ( $input ) {
			return array( isset( $input['id'] ) ? absint( $input['id'] ) : 0 );
		};
	}
}
