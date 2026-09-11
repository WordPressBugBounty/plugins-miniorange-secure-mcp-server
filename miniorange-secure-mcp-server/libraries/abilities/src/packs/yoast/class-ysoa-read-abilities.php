<?php
/**
 * Read-only Yoast SEO abilities (1-2).
 *
 * Note: SEO/readability/inclusive-language scoring is intentionally NOT
 * duplicated here - Yoast SEO itself registers `yoast-seo/get-seo-scores`,
 * `yoast-seo/get-readability-scores`, and `yoast-seo/get-inclusive-language-scores`
 * natively (see wordpress-seo/src/abilities/) when the Abilities API is available.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Yoast;

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
 * Class YSOA_Read_Abilities
 */
class YSOA_Read_Abilities {

	/**
	 * Register abilities 1-2.
	 */
	public static function register_all() {

		YSOA_Helpers::register(
			'get-focus-keyword',
			array(
				'label'               => __( 'Get Yoast Focus Keyword', 'mosmcp-abilities' ),
				'description'         => __( 'Retrieves the primary focus keyword set on a post, plus secondary/related keyphrases if the site runs Yoast Premium\'s multi-keyword feature.', 'mosmcp-abilities' ),
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id'           => array(
							'type'        => 'integer',
							'description' => __( 'ID of the post/page/CPT entry.', 'mosmcp-abilities' ),
						),
						'include_secondary' => array(
							'type'        => 'boolean',
							'description' => __( 'Whether to include Premium secondary/related keyphrases if present.', 'mosmcp-abilities' ),
							'default'     => true,
						),
					),
					'required'             => array( 'post_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'focus_keyword'      => array( 'type' => 'string' ),
						'secondary_keywords' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_get_focus_keyword' ),
				'permission_callback' => YSOA_Helpers::post_permission(),
			)
		);

		YSOA_Helpers::register(
			'get-meta-fields',
			array(
				'label'               => __( 'Get Yoast Meta Fields', 'mosmcp-abilities' ),
				'description'         => __( 'Gets every SEO field for a post in one call: the SEO title as stored and as it will render, meta description, focus keyword, canonical URL, breadcrumb title, all robots directives, the Open Graph and X (Twitter) sharing fields, the cornerstone flag, and the schema types along with the values this Yoast version accepts. Every field returned here can also be set, so this is the reliable way to see what is currently in place before changing anything.', 'mosmcp-abilities' ),
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id' => array(
							'type'        => 'integer',
							'description' => __( 'ID of the post/page/CPT entry.', 'mosmcp-abilities' ),
						),
					),
					'required'             => array( 'post_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'meta_title'            => array(
							'type'        => 'string',
							'description' => __( 'The SEO title as stored, which may contain Yoast template variables.', 'mosmcp-abilities' ),
						),
						'meta_title_rendered'   => array(
							'type'        => 'string',
							'description' => __( 'The SEO title with template variables resolved — what a search engine will actually show.', 'mosmcp-abilities' ),
						),
						'meta_description'      => array( 'type' => 'string' ),
						'focus_keyword'         => array( 'type' => 'string' ),
						'canonical_url'         => array( 'type' => 'string' ),
						'breadcrumb_title'      => array(
							'type'        => 'string',
							'description' => __( 'Text used in breadcrumb trails. Empty means breadcrumbs fall back to the post title.', 'mosmcp-abilities' ),
						),
						'robots_noindex'        => array( 'type' => 'boolean' ),
						'robots_nofollow'       => array( 'type' => 'boolean' ),
						'og_title'              => array( 'type' => 'string' ),
						'og_description'        => array( 'type' => 'string' ),
						'og_image'              => array( 'type' => 'string' ),
						'og_image_id'           => array( 'type' => 'integer' ),
						'twitter_title'         => array( 'type' => 'string' ),
						'twitter_description'   => array( 'type' => 'string' ),
						'twitter_image'         => array( 'type' => 'string' ),
						'twitter_image_id'      => array( 'type' => 'integer' ),
						'robots_noimageindex'   => array( 'type' => 'boolean' ),
						'robots_noarchive'      => array( 'type' => 'boolean' ),
						'robots_nosnippet'      => array( 'type' => 'boolean' ),
						'is_cornerstone'        => array(
							'type'        => 'boolean',
							'description' => __( 'Whether Yoast treats this post as cornerstone content.', 'mosmcp-abilities' ),
						),
						'schema_page_type'      => array(
							'type'        => 'string',
							'description' => __( 'Schema.org page type override. Empty means the post type default applies.', 'mosmcp-abilities' ),
						),
						'schema_article_type'   => array(
							'type'        => 'string',
							'description' => __( 'Schema.org article type override. Empty means the post type default applies.', 'mosmcp-abilities' ),
						),
						'allowed_page_types'    => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => __( 'The schema page types this Yoast version accepts, so a value can be chosen without guessing.', 'mosmcp-abilities' ),
						),
						'allowed_article_types' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_get_meta_fields' ),
				'permission_callback' => YSOA_Helpers::post_permission(),
			)
		);
	}

	/**
	 * 1. Get the focus keyword (and Premium secondary keyphrases if present).
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function execute_get_focus_keyword( $input ) {
		$post_id = YSOA_Helpers::require_post( $input );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$include_secondary = ! isset( $input['include_secondary'] ) || filter_var( $input['include_secondary'], FILTER_VALIDATE_BOOLEAN );

		$secondary = array();
		if ( $include_secondary && YSOA_Helpers::is_premium() ) {
			$raw = get_post_meta( $post_id, YSOA_Helpers::SECONDARY_KEYWORDS_META, true );
			if ( is_string( $raw ) && '' !== $raw ) {
				$secondary = array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) );
			}
		}

		return array(
			'focus_keyword'      => YSOA_Helpers::get_meta( 'focuskw', $post_id ),
			'secondary_keywords' => $secondary,
		);
	}

	/**
	 * 2. Get the full SEO meta bundle for a post.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function execute_get_meta_fields( $input ) {
		$post_id = YSOA_Helpers::require_post( $input );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$stored_title = (string) YSOA_Helpers::get_meta( 'title', $post_id );

		/*
		 * The stored title may be a template such as '%%title%% %%sep%% %%sitename%%'.
		 * Returning only that leaves a caller unable to judge length or wording, so
		 * the resolved string is returned alongside it — the same distinction the
		 * update-seo-title ability reports back.
		 */
		$rendered_title = $stored_title;
		if ( '' !== $stored_title && function_exists( 'wpseo_replace_vars' ) ) {
			$post = get_post( $post_id );
			if ( $post ) {
				$rendered_title = (string) wpseo_replace_vars( $stored_title, $post );
			}
		}

		// Yoast keeps the advanced directives as one comma-separated string.
		$advanced = array_values(
			array_filter(
				array_map( 'trim', explode( ',', (string) YSOA_Helpers::get_meta( 'meta-robots-adv', $post_id ) ) )
			)
		);

		return array(
			'meta_title'            => $stored_title,
			'meta_title_rendered'   => $rendered_title,
			'meta_description'      => (string) YSOA_Helpers::get_meta( 'metadesc', $post_id ),
			'focus_keyword'         => (string) YSOA_Helpers::get_meta( 'focuskw', $post_id ),
			'canonical_url'         => (string) YSOA_Helpers::get_meta( 'canonical', $post_id ),
			'breadcrumb_title'      => (string) YSOA_Helpers::get_meta( 'bctitle', $post_id ),
			'robots_noindex'        => '1' === YSOA_Helpers::get_meta( 'meta-robots-noindex', $post_id ),
			'robots_nofollow'       => '1' === YSOA_Helpers::get_meta( 'meta-robots-nofollow', $post_id ),
			'og_title'              => (string) YSOA_Helpers::get_meta( 'opengraph-title', $post_id ),
			'og_description'        => (string) YSOA_Helpers::get_meta( 'opengraph-description', $post_id ),
			'og_image'              => (string) YSOA_Helpers::get_meta( 'opengraph-image', $post_id ),
			'og_image_id'           => (int) YSOA_Helpers::get_meta( 'opengraph-image-id', $post_id ),
			'twitter_title'         => (string) YSOA_Helpers::get_meta( 'twitter-title', $post_id ),
			'twitter_description'   => (string) YSOA_Helpers::get_meta( 'twitter-description', $post_id ),
			'twitter_image'         => (string) YSOA_Helpers::get_meta( 'twitter-image', $post_id ),
			'twitter_image_id'      => (int) YSOA_Helpers::get_meta( 'twitter-image-id', $post_id ),
			'robots_noimageindex'   => in_array( 'noimageindex', $advanced, true ),
			'robots_noarchive'      => in_array( 'noarchive', $advanced, true ),
			'robots_nosnippet'      => in_array( 'nosnippet', $advanced, true ),
			'is_cornerstone'        => '1' === (string) YSOA_Helpers::get_meta( 'is_cornerstone', $post_id ),
			'schema_page_type'      => (string) YSOA_Helpers::get_meta( 'schema_page_type', $post_id ),
			'schema_article_type'   => (string) YSOA_Helpers::get_meta( 'schema_article_type', $post_id ),

			/*
			 * The valid values come back with the current ones, so a caller can pick a
			 * schema type in one round trip instead of guessing and being refused.
			 */
			'allowed_page_types'    => YSOA_Helpers::allowed_values( 'schema_page_type' ),
			'allowed_article_types' => YSOA_Helpers::allowed_values( 'schema_article_type' ),
		);
	}
}
