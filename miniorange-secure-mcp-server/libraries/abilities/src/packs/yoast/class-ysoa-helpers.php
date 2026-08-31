<?php
/**
 * Shared helpers for Yoast abilities: registration wrapper, permission checks,
 * post/meta resolution, and score-color bucketing.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Yoast;

use MoSMCP\Abilities\Naming;
use WP_Error;
use WPSEO_Meta;

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
 * Class YSOA_Helpers
 */
class YSOA_Helpers {

	/**
	 * Ability name namespace.
	 */
	const NS = 'mosmcp';

	/**
	 * Ability category slug.
	 */
	const CATEGORY = 'mosmcp-yoast';

	/**
	 * Post meta key used to store Premium secondary/related keyphrases.
	 *
	 * {@internal Yoast Premium is not installed in this environment, so this key
	 *            is the best-effort documented key (comma-separated keyphrases).
	 *            Verify against the active Premium version before relying on it
	 *            to stay in sync with the block editor's own synonym field.}}
	 */
	const SECONDARY_KEYWORDS_META = '_yoast_wpseo_keyphrase_synonyms';

	/**
	 * Register one ability under the plugin namespace with shared defaults.
	 *
	 * @param string $key  Ability key (without namespace), e.g. 'get-focus-keyword'.
	 * @param array  $args Ability args passed to Naming::register_ability().
	 */
	public static function register( $key, array $args ) {
		$args['category'] = self::CATEGORY;

		$meta = ( isset( $args['meta'] ) && is_array( $args['meta'] ) ) ? $args['meta'] : array();
		// MCP is the single governed door: never expose over the public REST
		// surface, and let the NHI/role policy decide exposure. The source set
		// neither annotations nor show_in_rest:false.
		$meta['show_in_rest'] = false;
		unset( $meta['mcp'] );
		$meta['annotations'] = self::annotations_for( $key );
		$args['meta']        = $meta;

		Naming::register_ability( self::NS . '/' . $key, $args );
	}

	/**
	 * Registers the Yoast ability category. No-op unless Yoast SEO is active.
	 *
	 * @return void
	 */
	public static function register_categories() {
		if ( ! function_exists( 'wp_register_ability_category' ) || ! self::yoast_active() ) {
			return;
		}
		Naming::register_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Yoast SEO', 'mosmcp-abilities' ),
				'description' => __( 'Read and manage Yoast SEO metadata: titles, descriptions, focus keywords, social tags, robots, and sitemap inclusion.', 'mosmcp-abilities' ),
			)
		);
	}

	/**
	 * Registers every Yoast ability domain. No-op unless Yoast SEO is active.
	 *
	 * @return void
	 */
	public static function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) || ! self::yoast_active() ) {
			return;
		}
		YSOA_Read_Abilities::register_all();
		YSOA_Write_Abilities::register_all();
		YSOA_Sitemap_Abilities::register_all();
	}

	/**
	 * Whether Yoast SEO (free or Premium) is active.
	 *
	 * @return bool
	 */
	public static function yoast_active() {
		return function_exists( 'YoastSEO' ) || class_exists( 'WPSEO_Meta' );
	}

	/**
	 * The four MCP annotation hints for an ability, keyed by ability key.
	 *
	 * The source registered no annotations; they are supplied here. The metadata
	 * that renders in the public page head or controls public search visibility
	 * (description, Open Graph, robots/index, sitemap inclusion) is open_world;
	 * the focus keyword is an internal editorial field and is not.
	 *
	 * @param string $key Ability key.
	 * @return array<string, bool>
	 */
	private static function annotations_for( $key ) {
		$read = self::ann( true, false, true, false );

		$map = array(
			'get-focus-keyword'        => $read,
			'get-meta-fields'          => $read,
			'update-focus-keyword'     => self::ann( false, false, true, false ),

			/*
			 * Breadcrumb text appears in the page a visitor sees, so it is open_world
			 * in the same sense as the meta description.
			 */
			'update-breadcrumb-title'  => self::ann( false, false, true, true ),
			'update-meta-description'  => self::ann( false, false, true, true ),
			'update-seo-title'         => self::ann( false, false, true, true ),
			'update-canonical'         => self::ann( false, false, true, true ),
			'update-og-tags'           => self::ann( false, false, true, true ),
			'update-twitter-card'      => self::ann( false, false, true, true ),
			'update-robots-meta'       => self::ann( false, false, true, true ),
			'update-robots-advanced'   => self::ann( false, false, true, true ),
			'update-schema-types'      => self::ann( false, false, true, true ),

			/*
			 * Cornerstone is an internal editorial flag: it changes how Yoast's own
			 * analysis and internal-linking suggestions treat the post, and renders
			 * nothing in the public page. Like the focus keyword, it is not open_world.
			 */
			'update-cornerstone'       => self::ann( false, false, true, false ),
			'update-sitemap-inclusion' => self::ann( false, false, true, true ),
		);

		return isset( $map[ $key ] ) ? $map[ $key ] : $read;
	}

	/**
	 * Builds the four annotation hints.
	 *
	 * @param bool $read_only   Read-only hint.
	 * @param bool $destructive Destructive hint.
	 * @param bool $idempotent  Idempotent hint.
	 * @param bool $open_world  Open-world hint.
	 * @return array<string, bool>
	 */
	private static function ann( $read_only, $destructive, $idempotent, $open_world ) {
		return array(
			'readonly'    => $read_only,
			'destructive' => $destructive,
			'idempotent'  => $idempotent,
			'open_world'  => $open_world,
		);
	}

	/**
	 * Whether Yoast SEO Premium is active.
	 *
	 * @return bool
	 */
	public static function is_premium() {
		if ( ! function_exists( 'YoastSEO' ) ) {
			return false;
		}
		$yoast = YoastSEO();
		return isset( $yoast->helpers->product ) && $yoast->helpers->product->is_premium();
	}

	/**
	 * Permission callback factory for post-scoped abilities.
	 *
	 * @param string $cap Meta capability to check against the post. Default 'edit_post'.
	 * @return callable
	 */
	public static function post_permission( $cap = 'edit_post' ) {
		return function ( $input = null ) use ( $cap ) {
			$post_id = is_array( $input ) && isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
			if ( $post_id > 0 ) {
				return current_user_can( $cap, $post_id );
			}
			return current_user_can( 'edit_posts' );
		};
	}

	/**
	 * Validate the post_id input and the current user's capability on it.
	 *
	 * @param array  $input Ability input.
	 * @param string $cap   Meta capability. Default 'edit_post'.
	 * @return int|WP_Error Post ID on success.
	 */
	public static function require_post( $input, $cap = 'edit_post' ) {
		$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
		if ( $post_id <= 0 || ! get_post( $post_id ) ) {
			return new WP_Error( 'ysoa_invalid_post', __( 'The given post_id does not match an existing post.', 'mosmcp-abilities' ) );
		}
		if ( ! current_user_can( $cap, $post_id ) ) {
			return new WP_Error( 'ysoa_forbidden', __( 'You do not have permission to access this post.', 'mosmcp-abilities' ) );
		}
		return $post_id;
	}

	/**
	 * Get a Yoast post meta value by its internal (unprefixed) key.
	 *
	 * @param string $key     Internal WPSEO_Meta key, e.g. 'metadesc'.
	 * @param int    $post_id Post ID.
	 * @return string
	 */
	public static function get_meta( $key, $post_id ) {
		return WPSEO_Meta::get_value( $key, $post_id );
	}

	/**
	 * Set a Yoast post meta value by its internal (unprefixed) key.
	 *
	 * @param string $key     Internal WPSEO_Meta key, e.g. 'metadesc'.
	 * @param mixed  $value   Value to store.
	 * @param int    $post_id Post ID.
	 * @return bool Whether the stored value now matches the requested value.
	 */
	public static function set_meta( $key, $value, $post_id ) {
		WPSEO_Meta::set_value( $key, $value, $post_id );
		return (string) self::get_meta( $key, $post_id ) === (string) $value;
	}

	/**
	 * Delete a Yoast post meta value by its internal (unprefixed) key.
	 *
	 * @param string $key     Internal WPSEO_Meta key.
	 * @param int    $post_id Post ID.
	 */
	public static function delete_meta( $key, $post_id ) {
		WPSEO_Meta::delete( $key, $post_id );
	}

	/**
	 * The values Yoast itself accepts for one of its fields.
	 *
	 * Yoast declares these in WPSEO_Meta::$meta_fields, and its own admin dropdowns
	 * are built from the same definitions. Reading them at runtime rather than
	 * copying them here means a Yoast release that adds a schema type — they add
	 * them fairly often — is accepted without a change to this plugin, and that a
	 * type Yoast drops stops being offered.
	 *
	 * @param string $key Internal WPSEO_Meta key, e.g. 'schema_page_type'.
	 * @return string[] Allowed values, or an empty array when the field is free text.
	 */
	public static function allowed_values( $key ) {
		if ( ! class_exists( 'WPSEO_Meta' ) || ! isset( WPSEO_Meta::$meta_fields ) ) {
			return array();
		}

		foreach ( WPSEO_Meta::$meta_fields as $fields ) {
			if ( isset( $fields[ $key ]['options'] ) && is_array( $fields[ $key ]['options'] ) ) {
				return array_map( 'strval', array_keys( $fields[ $key ]['options'] ) );
			}
		}

		return array();
	}
}
