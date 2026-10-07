<?php
/**
 * Write / update Yoast SEO abilities.
 *
 * Covers the fields an editor changes per post: meta description, SEO title,
 * canonical, focus keyword, Open Graph tags, and robots directives.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Yoast;

use WP_Error;

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
 * Class YSOA_Write_Abilities
 */
class YSOA_Write_Abilities {

	/**
	 * Recommended max meta description length before search engines may truncate it.
	 */
	const META_DESC_RECOMMENDED_LENGTH = 160;

	/**
	 * Recommended max SEO title length before search engines may truncate it.
	 *
	 * Measured against the *rendered* title, not the stored template: a template of
	 * '%%title%% %%sep%% %%sitename%%' is 30 characters but can render to well over
	 * 60, and the rendered string is what users actually see in results.
	 */
	const SEO_TITLE_RECOMMENDED_LENGTH = 60;

	/**
	 * Length beyond which breadcrumb text tends to wrap or be cut off by a theme.
	 *
	 * Advisory only — Yoast imposes no limit of its own on this field.
	 */
	const BREADCRUMB_ADVISORY_LENGTH = 40;

	/**
	 * Register abilities 3-6.
	 */
	public static function register_all() {

		YSOA_Helpers::register(
			'update-breadcrumb-title',
			array(
				'label'               => __( 'Update Yoast Breadcrumb Title', 'mosmcp-abilities' ),
				'description'         => __( 'Sets the text used for this post in breadcrumb trails, which is often shorter than the full title — "Hosting" rather than "10 Ways To Speed Up Your WordPress Site". Pass an empty string to clear it and fall back to the post title. Only affects breadcrumbs, not the page title or the search-result title.', 'mosmcp-abilities' ),
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id'          => array(
							'type'        => 'integer',
							'description' => __( 'ID of the post/page/CPT entry.', 'mosmcp-abilities' ),
						),
						'breadcrumb_title' => array(
							'type'        => 'string',
							'description' => __( 'Breadcrumb text, or an empty string to clear the override.', 'mosmcp-abilities' ),
						),
					),
					'required'             => array( 'post_id', 'breadcrumb_title' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'          => array( 'type' => 'boolean' ),
						'breadcrumb_title' => array( 'type' => 'string' ),
						'cleared'          => array( 'type' => 'boolean' ),
						'warnings'         => array(
							'type'  => 'array',
							'items' => array(
								'type'                 => 'object',
								'properties'           => array(
									'code'    => array( 'type' => 'string' ),
									'message' => array( 'type' => 'string' ),
								),
								'additionalProperties' => false,
							),
						),
					),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( __CLASS__, 'execute_update_breadcrumb_title' ),
				'permission_callback' => YSOA_Helpers::post_permission(),
			)
		);

		YSOA_Helpers::register(
			'update-meta-description',
			array(
				'label'               => __( 'Update Yoast Meta Description', 'mosmcp-abilities' ),
				'description'         => __( 'Updates the meta description for a post/page. ~155-160 characters recommended; longer values are accepted but may be truncated in search results.', 'mosmcp-abilities' ),
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id'          => array(
							'type'        => 'integer',
							'description' => __( 'ID of the post/page/CPT entry.', 'mosmcp-abilities' ),
						),
						'meta_description' => array(
							'type'        => 'string',
							'description' => __( 'New meta description text.', 'mosmcp-abilities' ),
						),
					),
					'required'             => array( 'post_id', 'meta_description' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'            => array( 'type' => 'boolean' ),
						'meta_description'   => array( 'type' => 'string' ),
						'length'             => array( 'type' => 'integer' ),
						'truncation_warning' => array( 'type' => 'boolean' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_update_meta_description' ),
				'permission_callback' => YSOA_Helpers::post_permission(),
			)
		);

		YSOA_Helpers::register(
			'update-seo-title',
			array(
				'label'               => __( 'Update Yoast SEO Title', 'mosmcp-abilities' ),
				'description'         => __( 'Updates the SEO title (the <title> tag used in search results), which is separate from the post title shown on the page. Yoast template variables such as %%title%%, %%sep%% and %%sitename%% are supported and stored verbatim; the response reports the rendered result. Pass an empty string to clear the override and fall back to the post type\'s title template. ~60 rendered characters recommended.', 'mosmcp-abilities' ),
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id'   => array(
							'type'        => 'integer',
							'description' => __( 'ID of the post/page/CPT entry.', 'mosmcp-abilities' ),
						),
						'seo_title' => array(
							'type'        => 'string',
							'description' => __( 'New SEO title. May contain Yoast template variables. Empty string clears the override.', 'mosmcp-abilities' ),
						),
					),
					'required'             => array( 'post_id', 'seo_title' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'            => array( 'type' => 'boolean' ),
						'seo_title'          => array( 'type' => 'string' ),
						'rendered_title'     => array( 'type' => 'string' ),
						'length'             => array( 'type' => 'integer' ),
						'truncation_warning' => array( 'type' => 'boolean' ),
						'cleared'            => array( 'type' => 'boolean' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_update_seo_title' ),
				'permission_callback' => YSOA_Helpers::post_permission(),
			)
		);

		YSOA_Helpers::register(
			'update-canonical',
			array(
				'label'               => __( 'Update Yoast Canonical URL', 'mosmcp-abilities' ),
				'description'         => __( 'Sets or clears the canonical URL for a post. Only absolute http(s) URLs are accepted. Pass an empty string to clear the override and fall back to the post permalink. Warnings are returned when the canonical points at another domain or at a different post on this site, because either one removes this post from search results.', 'mosmcp-abilities' ),
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id'       => array(
							'type'        => 'integer',
							'description' => __( 'ID of the post/page/CPT entry.', 'mosmcp-abilities' ),
						),
						'canonical_url' => array(
							'type'        => 'string',
							'description' => __( 'Absolute http(s) URL, or an empty string to clear the override.', 'mosmcp-abilities' ),
						),
					),
					'required'             => array( 'post_id', 'canonical_url' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'       => array( 'type' => 'boolean' ),
						'canonical_url' => array( 'type' => 'string' ),
						'cleared'       => array( 'type' => 'boolean' ),
						'warnings'      => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'code'    => array( 'type' => 'string' ),
									'message' => array( 'type' => 'string' ),
								),
							),
						),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_update_canonical' ),
				'permission_callback' => YSOA_Helpers::post_permission(),
			)
		);

		YSOA_Helpers::register(
			'update-focus-keyword',
			array(
				'label'               => __( 'Update Yoast Focus Keyword', 'mosmcp-abilities' ),
				'description'         => __( 'Sets or changes the focus keyword(s) for a post. Changing the focus keyword does not retroactively recompute the stored SEO score until the post is next saved through the editor.', 'mosmcp-abilities' ),
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id'            => array(
							'type'        => 'integer',
							'description' => __( 'ID of the post/page/CPT entry.', 'mosmcp-abilities' ),
						),
						'keyword'            => array(
							'type'        => 'string',
							'description' => __( 'New primary focus keyword.', 'mosmcp-abilities' ),
						),
						'secondary_keywords' => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => __( 'Additional related keyphrases. Requires Yoast Premium; ignored on free tier.', 'mosmcp-abilities' ),
						),
					),
					'required'             => array( 'post_id', 'keyword' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'                    => array( 'type' => 'boolean' ),
						'focus_keyword'              => array( 'type' => 'string' ),
						'secondary_keywords'         => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'secondary_keywords_ignored' => array( 'type' => 'boolean' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_update_focus_keyword' ),
				'permission_callback' => YSOA_Helpers::post_permission(),
			)
		);

		YSOA_Helpers::register(
			'update-og-tags',
			array(
				'label'               => __( 'Update Yoast Open Graph Tags', 'mosmcp-abilities' ),
				'description'         => __( 'Updates Open Graph title, description, and image used for social sharing previews - distinct from the main meta title/description.', 'mosmcp-abilities' ),
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id'        => array(
							'type'        => 'integer',
							'description' => __( 'ID of the post/page/CPT entry.', 'mosmcp-abilities' ),
						),
						'og_title'       => array(
							'type'        => 'string',
							'description' => __( 'Open Graph title override.', 'mosmcp-abilities' ),
						),
						'og_description' => array(
							'type'        => 'string',
							'description' => __( 'Open Graph description override.', 'mosmcp-abilities' ),
						),
						'og_image_id'    => array(
							'type'        => 'integer',
							'description' => __( 'WordPress attachment ID to use as the Open Graph image. Pass 0 to clear the current image.', 'mosmcp-abilities' ),
						),
					),
					'required'             => array( 'post_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'        => array( 'type' => 'boolean' ),
						'og_title'       => array( 'type' => 'string' ),
						'og_description' => array( 'type' => 'string' ),
						'og_image_id'    => array( 'type' => 'integer' ),
						'og_image_url'   => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_update_og_tags' ),
				'permission_callback' => YSOA_Helpers::post_permission(),
			)
		);

		YSOA_Helpers::register(
			'update-robots-meta',
			array(
				'label'               => __( 'Update Yoast Robots Meta', 'mosmcp-abilities' ),
				'description'         => __( 'Sets noindex/nofollow directives on a post.', 'mosmcp-abilities' ),
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id'  => array(
							'type'        => 'integer',
							'description' => __( 'ID of the post/page/CPT entry.', 'mosmcp-abilities' ),
						),
						'noindex'  => array(
							'type'        => 'boolean',
							'description' => __( 'If true, marks the post noindex. If false, explicitly marks it index (overriding the post type default).', 'mosmcp-abilities' ),
						),
						'nofollow' => array(
							'type'        => 'boolean',
							'description' => __( 'If true, marks the post nofollow.', 'mosmcp-abilities' ),
						),
					),
					'required'             => array( 'post_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'         => array( 'type' => 'boolean' ),
						'robots_noindex'  => array( 'type' => 'boolean' ),
						'robots_nofollow' => array( 'type' => 'boolean' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_update_robots_meta' ),
				'permission_callback' => YSOA_Helpers::post_permission(),
			)
		);

		YSOA_Helpers::register(
			'update-twitter-card',
			array(
				'label'               => __( 'Update Yoast Twitter/X Card', 'mosmcp-abilities' ),
				'description'         => __( 'Sets the title, description and image X (Twitter) uses when this post is shared, which can differ from the Open Graph values used by Facebook and LinkedIn. Any field left out is untouched; pass an empty string, or 0 for the image, to clear one and fall back to the Open Graph value. Only set these when the X preview needs to read differently — otherwise the Open Graph tags already cover it.', 'mosmcp-abilities' ),
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id'             => array(
							'type'        => 'integer',
							'description' => __( 'ID of the post/page/CPT entry.', 'mosmcp-abilities' ),
						),
						'twitter_title'       => array(
							'type'        => 'string',
							'description' => __( 'Title for the X card. Empty string clears it.', 'mosmcp-abilities' ),
						),
						'twitter_description' => array(
							'type'        => 'string',
							'description' => __( 'Description for the X card. Empty string clears it.', 'mosmcp-abilities' ),
						),
						'twitter_image_id'    => array(
							'type'        => 'integer',
							'description' => __( 'Media library ID of the card image. Pass 0 to clear it.', 'mosmcp-abilities' ),
						),
					),
					'required'             => array( 'post_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'             => array( 'type' => 'boolean' ),
						'twitter_title'       => array( 'type' => 'string' ),
						'twitter_description' => array( 'type' => 'string' ),
						'twitter_image_id'    => array( 'type' => 'integer' ),
						'twitter_image_url'   => array( 'type' => 'string' ),
						'updated_fields'      => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'warnings'            => self::warnings_schema(),
					),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( __CLASS__, 'execute_update_twitter_card' ),
				'permission_callback' => YSOA_Helpers::post_permission(),
			)
		);

		YSOA_Helpers::register(
			'update-robots-advanced',
			array(
				'label'               => __( 'Update Yoast Advanced Robots Directives', 'mosmcp-abilities' ),
				'description'         => __( 'Controls the advanced robots directives for a post: noimageindex (keep its images out of image search), noarchive (no cached copy) and nosnippet (no description excerpt in results). These are separate from noindex and nofollow, which the robots-meta ability handles. Any directive left out keeps its current setting.', 'mosmcp-abilities' ),
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id'      => array(
							'type'        => 'integer',
							'description' => __( 'ID of the post/page/CPT entry.', 'mosmcp-abilities' ),
						),
						'noimageindex' => array(
							'type'        => 'boolean',
							'description' => __( 'True to keep this post\'s images out of image search results.', 'mosmcp-abilities' ),
						),
						'noarchive'    => array(
							'type'        => 'boolean',
							'description' => __( 'True to stop search engines showing a cached copy of the page.', 'mosmcp-abilities' ),
						),
						'nosnippet'    => array(
							'type'        => 'boolean',
							'description' => __( 'True to stop search engines showing a text snippet or preview for the page.', 'mosmcp-abilities' ),
						),
					),
					'required'             => array( 'post_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'      => array( 'type' => 'boolean' ),
						'noimageindex' => array( 'type' => 'boolean' ),
						'noarchive'    => array( 'type' => 'boolean' ),
						'nosnippet'    => array( 'type' => 'boolean' ),
						'directives'   => array(
							'type'        => 'string',
							'description' => __( 'The stored value as Yoast keeps it: a comma-separated list, empty when none are set.', 'mosmcp-abilities' ),
						),
						'warnings'     => self::warnings_schema(),
					),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( __CLASS__, 'execute_update_robots_advanced' ),
				'permission_callback' => YSOA_Helpers::post_permission(),
			)
		);

		YSOA_Helpers::register(
			'update-cornerstone',
			array(
				'label'               => __( 'Mark Post as Cornerstone Content', 'mosmcp-abilities' ),
				'description'         => __( 'Marks a post as cornerstone content, meaning one of the most important pages on the site. Yoast then holds it to a stricter readability and SEO standard and prioritises it in internal-linking suggestions. This changes nothing a visitor sees.', 'mosmcp-abilities' ),
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id'        => array(
							'type'        => 'integer',
							'description' => __( 'ID of the post/page/CPT entry.', 'mosmcp-abilities' ),
						),
						'is_cornerstone' => array(
							'type'        => 'boolean',
							'description' => __( 'True to mark it as cornerstone content, false to unmark it.', 'mosmcp-abilities' ),
						),
					),
					'required'             => array( 'post_id', 'is_cornerstone' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'        => array( 'type' => 'boolean' ),
						'is_cornerstone' => array( 'type' => 'boolean' ),
						'changed'        => array( 'type' => 'boolean' ),
						'warnings'       => self::warnings_schema(),
					),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( __CLASS__, 'execute_update_cornerstone' ),
				'permission_callback' => YSOA_Helpers::post_permission(),
			)
		);

		YSOA_Helpers::register(
			'update-schema-types',
			array(
				'label'               => __( 'Update Yoast Schema Types', 'mosmcp-abilities' ),
				'description'         => __( 'Sets the schema.org page type and article type Yoast declares in its structured data, which is how search engines classify the page — a service page as an ItemPage, a help page as an FAQPage, a news story as a NewsArticle. Pass an empty string to clear either one and fall back to the default for the post type. Invalid values are refused and the response lists what Yoast accepts; use the read ability to see the current values and the full list.', 'mosmcp-abilities' ),
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id'      => array(
							'type'        => 'integer',
							'description' => __( 'ID of the post/page/CPT entry.', 'mosmcp-abilities' ),
						),
						'page_type'    => array(
							'type'        => 'string',
							'description' => __( 'Schema page type, for example "ItemPage", "AboutPage", "ContactPage" or "FAQPage". Empty string clears the override.', 'mosmcp-abilities' ),
						),
						'article_type' => array(
							'type'        => 'string',
							'description' => __( 'Schema article type, for example "BlogPosting", "NewsArticle" or "TechArticle". "None" means the post is not an article. Empty string clears the override.', 'mosmcp-abilities' ),
						),
					),
					'required'             => array( 'post_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'               => array( 'type' => 'boolean' ),
						'page_type'             => array( 'type' => 'string' ),
						'article_type'          => array( 'type' => 'string' ),
						'updated_fields'        => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'allowed_page_types'    => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'allowed_article_types' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'warnings'              => self::warnings_schema(),
					),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( __CLASS__, 'execute_update_schema_types' ),
				'permission_callback' => YSOA_Helpers::post_permission(),
			)
		);
	}

	/**
	 * The warnings array shape, which several of these abilities return.
	 *
	 * @return array<string, mixed>
	 */
	private static function warnings_schema() {
		return array(
			'type'  => 'array',
			'items' => array(
				'type'                 => 'object',
				'properties'           => array(
					'code'    => array( 'type' => 'string' ),
					'message' => array( 'type' => 'string' ),
				),
				'additionalProperties' => false,
			),
		);
	}

	/**
	 * 3. Update the meta description.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function execute_update_meta_description( $input ) {
		$post_id = YSOA_Helpers::require_post( $input );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$description = wp_strip_all_tags( (string) $input['meta_description'] );
		$success     = YSOA_Helpers::set_meta( 'metadesc', $description, $post_id );

		return array(
			'success'            => $success,
			'meta_description'   => YSOA_Helpers::get_meta( 'metadesc', $post_id ),
			'length'             => mb_strlen( $description ),
			'truncation_warning' => mb_strlen( $description ) > self::META_DESC_RECOMMENDED_LENGTH,
		);
	}

	/**
	 * 4. Update the focus keyword and (Premium-only) secondary keyphrases.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function execute_update_focus_keyword( $input ) {
		$post_id = YSOA_Helpers::require_post( $input );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$keyword = sanitize_text_field( (string) $input['keyword'] );
		$success = YSOA_Helpers::set_meta( 'focuskw', $keyword, $post_id );

		$secondary_saved = array();
		$ignored         = false;

		if ( array_key_exists( 'secondary_keywords', $input ) ) {
			if ( YSOA_Helpers::is_premium() ) {
				$secondary_saved = array_values(
					array_filter(
						array_map( 'sanitize_text_field', (array) $input['secondary_keywords'] )
					)
				);
				// wp_slash because the metadata API unslashes on the way in.
				update_post_meta( $post_id, YSOA_Helpers::SECONDARY_KEYWORDS_META, wp_slash( implode( ',', $secondary_saved ) ) );
			} else {
				$ignored = true;
			}
		}

		return array(
			'success'                    => $success,
			'focus_keyword'              => YSOA_Helpers::get_meta( 'focuskw', $post_id ),
			'secondary_keywords'         => $secondary_saved,
			'secondary_keywords_ignored' => $ignored,
		);
	}

	/**
	 * 5. Update Open Graph title, description, and image.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function execute_update_og_tags( $input ) {
		$post_id = YSOA_Helpers::require_post( $input );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		if ( array_key_exists( 'og_title', $input ) ) {
			YSOA_Helpers::set_meta( 'opengraph-title', wp_strip_all_tags( (string) $input['og_title'] ), $post_id );
		}
		if ( array_key_exists( 'og_description', $input ) ) {
			YSOA_Helpers::set_meta( 'opengraph-description', wp_strip_all_tags( (string) $input['og_description'] ), $post_id );
		}
		if ( array_key_exists( 'og_image_id', $input ) ) {
			$attachment_id = (int) $input['og_image_id'];
			if ( $attachment_id > 0 ) {
				if ( ! wp_attachment_is_image( $attachment_id ) ) {
					return new WP_Error( 'ysoa_invalid_attachment', __( 'og_image_id does not match an existing image attachment.', 'mosmcp-abilities' ) );
				}
				$url = wp_get_attachment_image_url( $attachment_id, 'full' );
				YSOA_Helpers::set_meta( 'opengraph-image-id', $attachment_id, $post_id );
				YSOA_Helpers::set_meta( 'opengraph-image', $url ? $url : '', $post_id );
			} else {
				YSOA_Helpers::delete_meta( 'opengraph-image-id', $post_id );
				YSOA_Helpers::delete_meta( 'opengraph-image', $post_id );
			}
		}

		return array(
			'success'        => true,
			'og_title'       => YSOA_Helpers::get_meta( 'opengraph-title', $post_id ),
			'og_description' => YSOA_Helpers::get_meta( 'opengraph-description', $post_id ),
			'og_image_id'    => (int) YSOA_Helpers::get_meta( 'opengraph-image-id', $post_id ),
			'og_image_url'   => YSOA_Helpers::get_meta( 'opengraph-image', $post_id ),
		);
	}

	/**
	 * 6. Update robots noindex/nofollow directives.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function execute_update_robots_meta( $input ) {
		$post_id = YSOA_Helpers::require_post( $input );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		if ( array_key_exists( 'noindex', $input ) ) {
			$noindex = filter_var( $input['noindex'], FILTER_VALIDATE_BOOLEAN );
			// Yoast options: '0' = post-type default, '1' = noindex, '2' = explicit index.
			YSOA_Helpers::set_meta( 'meta-robots-noindex', $noindex ? '1' : '2', $post_id );
		}
		if ( array_key_exists( 'nofollow', $input ) ) {
			$nofollow = filter_var( $input['nofollow'], FILTER_VALIDATE_BOOLEAN );
			YSOA_Helpers::set_meta( 'meta-robots-nofollow', $nofollow ? '1' : '0', $post_id );
		}

		return array(
			'success'         => true,
			'robots_noindex'  => '1' === YSOA_Helpers::get_meta( 'meta-robots-noindex', $post_id ),
			'robots_nofollow' => '1' === YSOA_Helpers::get_meta( 'meta-robots-nofollow', $post_id ),
		);
	}

	/**
	 * Update the SEO title (the <title> tag), separate from the post title.
	 *
	 * Yoast template variables are stored verbatim, so the stored value and the
	 * value a searcher sees are different strings. Both are returned, and the
	 * truncation warning is measured against the rendered one.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function execute_update_seo_title( $input ) {
		$post_id = YSOA_Helpers::require_post( $input );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Strip markup but preserve %%template%% variables, which contain no tags.
		$seo_title = wp_strip_all_tags( (string) $input['seo_title'] );
		$cleared   = '' === trim( $seo_title );

		if ( $cleared ) {
			YSOA_Helpers::delete_meta( 'title', $post_id );
			$success = true;
		} else {
			$success = YSOA_Helpers::set_meta( 'title', $seo_title, $post_id );
		}

		$stored   = (string) YSOA_Helpers::get_meta( 'title', $post_id );
		$rendered = self::render_template( $stored, $post_id );

		return array(
			'success'            => $success,
			'seo_title'          => $stored,
			'rendered_title'     => $rendered,
			'length'             => mb_strlen( $rendered ),
			'truncation_warning' => mb_strlen( $rendered ) > self::SEO_TITLE_RECOMMENDED_LENGTH,
			'cleared'            => $cleared,
		);
	}

	/**
	 * Set or clear the canonical URL for a post.
	 *
	 * Yoast silently discards a value it considers unsafe, which reads to a caller
	 * as a successful no-op. This validates first so an unusable URL comes back as
	 * an error, and flags the two shapes that are valid but usually mistakes.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function execute_update_canonical( $input ) {
		$post_id = YSOA_Helpers::require_post( $input );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$raw     = trim( (string) $input['canonical_url'] );
		$cleared = '' === $raw;

		if ( $cleared ) {
			YSOA_Helpers::delete_meta( 'canonical', $post_id );

			return array(
				'success'       => true,
				'canonical_url' => '',
				'cleared'       => true,
				'warnings'      => array(),
			);
		}

		$url = esc_url_raw( $raw );
		if ( '' === $url ) {
			return new WP_Error(
				'ysoa_invalid_canonical',
				__( 'canonical_url is not a usable URL. Pass an absolute http(s) URL, or an empty string to clear the canonical.', 'mosmcp-abilities' )
			);
		}

		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		$host   = (string) wp_parse_url( $url, PHP_URL_HOST );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || '' === $host ) {
			return new WP_Error(
				'ysoa_invalid_canonical',
				sprintf(
					/* translators: %s: the rejected URL */
					__( 'canonical_url must be an absolute http(s) URL including a host. Received: %s', 'mosmcp-abilities' ),
					$url
				)
			);
		}

		$warnings  = array();
		$site_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );

		if ( strtolower( $host ) !== strtolower( $site_host ) ) {
			$warnings[] = array(
				'code'    => 'cross_domain_canonical',
				'message' => sprintf(
					/* translators: 1: canonical host, 2: this site's host */
					__( 'The canonical points at %1$s, which is not this site (%2$s). Search engines will credit that URL instead of this post, so this post will normally stop appearing in results.', 'mosmcp-abilities' ),
					$host,
					$site_host
				),
			);
		} else {
			$target = url_to_postid( $url );
			if ( $target > 0 && $target !== (int) $post_id ) {
				$warnings[] = array(
					'code'    => 'canonical_points_to_other_post',
					'message' => sprintf(
						/* translators: %d: the post ID the canonical resolves to */
						__( 'The canonical resolves to post #%d on this site rather than this post. Search engines will treat that post as the original and drop this one from results. This is the usual cause of a duplicated post disappearing from search.', 'mosmcp-abilities' ),
						$target
					),
				);
			}
		}

		$success = YSOA_Helpers::set_meta( 'canonical', $url, $post_id );
		$stored  = (string) YSOA_Helpers::get_meta( 'canonical', $post_id );

		// Yoast drops values it deems unsafe. Surface that rather than reporting success.
		if ( '' === $stored ) {
			return new WP_Error(
				'ysoa_canonical_rejected',
				sprintf(
					/* translators: %s: the rejected URL */
					__( 'Yoast rejected the canonical URL and stored nothing. Received: %s', 'mosmcp-abilities' ),
					$url
				)
			);
		}

		return array(
			'success'       => $success,
			'canonical_url' => $stored,
			'cleared'       => false,
			'warnings'      => $warnings,
		);
	}

	/**
	 * Resolve Yoast template variables (%%title%%, %%sitename%%, …) for a post.
	 *
	 * Uses the global helper rather than the WPSEO_Replace_Vars class so there is
	 * no class reference to qualify, and degrades to the raw template when Yoast
	 * does not expose the helper.
	 *
	 * @param string $template Stored title template.
	 * @param int    $post_id  Post ID.
	 * @return string
	 */
	private static function render_template( $template, $post_id ) {
		if ( '' === $template ) {
			return '';
		}
		if ( ! function_exists( 'wpseo_replace_vars' ) ) {
			return $template;
		}
		$post = get_post( $post_id );
		if ( ! $post ) {
			return $template;
		}
		return (string) wpseo_replace_vars( $template, $post );
	}

	/**
	 * Sets or clears the breadcrumb title.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function execute_update_breadcrumb_title( $input ) {
		$post_id = YSOA_Helpers::require_post( $input );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		if ( ! array_key_exists( 'breadcrumb_title', $input ) ) {
			return new \WP_Error(
				'ysoa_missing_breadcrumb_title',
				__( 'Provide a breadcrumb_title, or an empty string to clear the override.', 'mosmcp-abilities' )
			);
		}

		$title    = sanitize_text_field( (string) $input['breadcrumb_title'] );
		$warnings = array();

		if ( '' === $title ) {
			YSOA_Helpers::delete_meta( 'bctitle', $post_id );

			return array(
				'success'          => true,
				'breadcrumb_title' => '',
				'cleared'          => true,
				'warnings'         => array(
					array(
						'code'    => 'fallback_to_post_title',
						'message' => __( 'The breadcrumb override was removed, so breadcrumbs will show the post title instead.', 'mosmcp-abilities' ),
					),
				),
			);
		}

		$success = YSOA_Helpers::set_meta( 'bctitle', $title, $post_id );

		/*
		 * Breadcrumb trails sit inline in a page, so an entry much longer than the
		 * others wraps or truncates depending on the theme. Yoast itself sets no
		 * limit, so this is advice rather than a rule.
		 */
		if ( strlen( $title ) > self::BREADCRUMB_ADVISORY_LENGTH ) {
			$warnings[] = array(
				'code'    => 'breadcrumb_title_long',
				'message' => sprintf(
					/* translators: 1: character count, 2: advisory limit */
					__( 'The breadcrumb text is %1$d characters. Breadcrumbs render on one line, so anything much over %2$d tends to wrap or be cut off by the theme.', 'mosmcp-abilities' ),
					strlen( $title ),
					self::BREADCRUMB_ADVISORY_LENGTH
				),
			);
		}

		/*
		 * Setting it to exactly the post title stores an override that does nothing,
		 * which is worth saying rather than silently accepting.
		 */
		$post = get_post( $post_id );
		if ( $post && $title === (string) $post->post_title ) {
			$warnings[] = array(
				'code'    => 'same_as_post_title',
				'message' => __( 'This matches the post title exactly, so it has the same effect as having no override at all. Clearing it instead keeps the setting tidy.', 'mosmcp-abilities' ),
			);
		}

		return array(
			'success'          => (bool) $success,
			'breadcrumb_title' => YSOA_Helpers::get_meta( 'bctitle', $post_id ),
			'cleared'          => false,
			'warnings'         => $warnings,
		);
	}

	/**
	 * Sets the X (Twitter) card fields.
	 *
	 * Deliberately partial: only the fields present in the input are written, so a
	 * caller can change the title without wiping a description it never mentioned.
	 * That matters more here than elsewhere, because these values are easy to lose
	 * by accident and nothing on the site shows they are gone.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function execute_update_twitter_card( $input ) {
		$post_id = YSOA_Helpers::require_post( $input );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$updated  = array();
		$warnings = array();
		$success  = true;

		if ( array_key_exists( 'twitter_title', $input ) ) {
			$title     = sanitize_text_field( (string) $input['twitter_title'] );
			$success   = YSOA_Helpers::set_meta( 'twitter-title', $title, $post_id ) && $success;
			$updated[] = 'twitter_title';
		}

		if ( array_key_exists( 'twitter_description', $input ) ) {
			$desc      = wp_strip_all_tags( (string) $input['twitter_description'] );
			$success   = YSOA_Helpers::set_meta( 'twitter-description', $desc, $post_id ) && $success;
			$updated[] = 'twitter_description';
		}

		if ( array_key_exists( 'twitter_image_id', $input ) ) {
			$image_id = absint( $input['twitter_image_id'] );

			if ( 0 === $image_id ) {
				YSOA_Helpers::set_meta( 'twitter-image-id', '', $post_id );
				YSOA_Helpers::set_meta( 'twitter-image', '', $post_id );
			} else {
				$url = wp_get_attachment_url( $image_id );

				if ( ! $url ) {
					return new \WP_Error(
						'ysoa_invalid_image',
						sprintf(
							/* translators: %d: attachment ID */
							__( 'No media library item found with ID %d. Add the image first, then pass the attachment_id it returns.', 'mosmcp-abilities' ),
							$image_id
						)
					);
				}

				if ( ! wp_attachment_is_image( $image_id ) && 0 !== strpos( (string) get_post_mime_type( $image_id ), 'image/' ) ) {
					$warnings[] = array(
						'code'    => 'attachment_may_not_be_an_image',
						'message' => __( 'That media item does not look like an image, so X may show no preview at all.', 'mosmcp-abilities' ),
					);
				}

				/*
				 * Yoast reads the URL and keeps the ID alongside it, so both are written:
				 * the ID is what its admin screen shows, the URL is what it renders.
				 */
				YSOA_Helpers::set_meta( 'twitter-image-id', (string) $image_id, $post_id );
				YSOA_Helpers::set_meta( 'twitter-image', (string) $url, $post_id );
			}

			$updated[] = 'twitter_image_id';
		}

		if ( ! $updated ) {
			return new \WP_Error(
				'ysoa_nothing_to_update',
				__( 'Provide at least one of twitter_title, twitter_description or twitter_image_id.', 'mosmcp-abilities' )
			);
		}

		/*
		 * X falls back to the Open Graph tags when the twitter: ones are absent, so a
		 * twitter value identical to the OG value is an override that does nothing.
		 */
		$og_pairs = array(
			'twitter_title'       => array( 'twitter-title', 'opengraph-title' ),
			'twitter_description' => array( 'twitter-description', 'opengraph-description' ),
		);

		foreach ( $og_pairs as $field => $keys ) {
			if ( ! in_array( $field, $updated, true ) ) {
				continue;
			}
			$twitter = (string) YSOA_Helpers::get_meta( $keys[0], $post_id );
			$og      = (string) YSOA_Helpers::get_meta( $keys[1], $post_id );
			if ( '' !== $twitter && $twitter === $og ) {
				$warnings[] = array(
					'code'    => 'same_as_open_graph',
					'message' => sprintf(
						/* translators: %s: the input field name */
						__( 'The %s matches the Open Graph value exactly. X already falls back to Open Graph, so this override changes nothing and clearing it keeps the settings tidy.', 'mosmcp-abilities' ),
						$field
					),
				);
			}
		}

		$stored_id = (int) YSOA_Helpers::get_meta( 'twitter-image-id', $post_id );

		return array(
			'success'             => (bool) $success,
			'twitter_title'       => (string) YSOA_Helpers::get_meta( 'twitter-title', $post_id ),
			'twitter_description' => (string) YSOA_Helpers::get_meta( 'twitter-description', $post_id ),
			'twitter_image_id'    => $stored_id,
			'twitter_image_url'   => (string) YSOA_Helpers::get_meta( 'twitter-image', $post_id ),
			'updated_fields'      => $updated,
			'warnings'            => $warnings,
		);
	}

	/**
	 * Sets the advanced robots directives.
	 *
	 * Yoast stores these as one comma-separated string, so the current value is read,
	 * only the named directives are changed, and the result is written back. Taking
	 * booleans rather than the raw string keeps a caller from destroying a directive
	 * it did not know was there.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function execute_update_robots_advanced( $input ) {
		$post_id = YSOA_Helpers::require_post( $input );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$allowed = YSOA_Helpers::allowed_values( 'meta-robots-adv' );
		if ( ! $allowed ) {
			$allowed = array( 'noimageindex', 'noarchive', 'nosnippet' );
		}

		$current = (string) YSOA_Helpers::get_meta( 'meta-robots-adv', $post_id );
		$active  = array_values( array_filter( array_map( 'trim', explode( ',', $current ) ) ) );

		$requested = array_intersect( $allowed, array( 'noimageindex', 'noarchive', 'nosnippet' ) );
		$touched   = false;

		foreach ( $requested as $directive ) {
			if ( ! array_key_exists( $directive, $input ) ) {
				continue;
			}

			$touched = true;
			$on      = (bool) $input[ $directive ];
			$active  = array_values( array_diff( $active, array( $directive ) ) );

			if ( $on ) {
				$active[] = $directive;
			}
		}

		if ( ! $touched ) {
			return new \WP_Error(
				'ysoa_nothing_to_update',
				sprintf(
					/* translators: %s: comma-separated list of directive names */
					__( 'Provide at least one directive to change. This site accepts: %s.', 'mosmcp-abilities' ),
					implode( ', ', $allowed )
				)
			);
		}

		/*
		 * Kept in the order Yoast declares them, so the stored string does not churn
		 * between calls that set the same directives in a different order.
		 */
		$ordered = array_values( array_intersect( $allowed, $active ) );
		$value   = implode( ',', $ordered );
		$success = YSOA_Helpers::set_meta( 'meta-robots-adv', $value, $post_id );

		$warnings = array();

		if ( in_array( 'nosnippet', $ordered, true ) ) {
			$warnings[] = array(
				'code'    => 'nosnippet_hides_description',
				'message' => __( 'With nosnippet set, search engines show no description under the link, so the meta description on this post will not appear in results.', 'mosmcp-abilities' ),
			);
		}

		$stored = (string) YSOA_Helpers::get_meta( 'meta-robots-adv', $post_id );
		$final  = array_values( array_filter( array_map( 'trim', explode( ',', $stored ) ) ) );

		return array(
			'success'      => (bool) $success,
			'noimageindex' => in_array( 'noimageindex', $final, true ),
			'noarchive'    => in_array( 'noarchive', $final, true ),
			'nosnippet'    => in_array( 'nosnippet', $final, true ),
			'directives'   => $stored,
			'warnings'     => $warnings,
		);
	}

	/**
	 * Marks a post as cornerstone content, or unmarks it.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function execute_update_cornerstone( $input ) {
		$post_id = YSOA_Helpers::require_post( $input );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		if ( ! array_key_exists( 'is_cornerstone', $input ) ) {
			return new \WP_Error(
				'ysoa_missing_is_cornerstone',
				__( 'Provide is_cornerstone as true or false.', 'mosmcp-abilities' )
			);
		}

		$wanted = (bool) $input['is_cornerstone'];
		$before = self::is_cornerstone( $post_id );

		/*
		 * Yoast's stored form is the string '1' when on. Its declared default is the
		 * string 'false', and WPSEO_Meta::set_value() removes the row when the value
		 * equals the default, so writing that is how the flag is cleared the way
		 * Yoast's own editor clears it.
		 */
		$success = YSOA_Helpers::set_meta( 'is_cornerstone', $wanted ? '1' : 'false', $post_id );
		$after   = self::is_cornerstone( $post_id );

		$warnings = array();

		if ( $wanted && $after ) {
			$warnings[] = array(
				'code'    => 'stricter_analysis_applies',
				'message' => __( 'Yoast now holds this post to its stricter cornerstone standard, so its readability and SEO scores may drop even though the content has not changed.', 'mosmcp-abilities' ),
			);
		}

		return array(
			'success'        => (bool) $success && $after === $wanted,
			'is_cornerstone' => $after,
			'changed'        => $before !== $after,
			'warnings'       => $warnings,
		);
	}

	/**
	 * Whether a post is currently flagged as cornerstone content.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private static function is_cornerstone( $post_id ) {
		return '1' === (string) YSOA_Helpers::get_meta( 'is_cornerstone', $post_id );
	}

	/**
	 * Sets the schema.org page and article types.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function execute_update_schema_types( $input ) {
		$post_id = YSOA_Helpers::require_post( $input );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$fields = array(
			'page_type'    => 'schema_page_type',
			'article_type' => 'schema_article_type',
		);

		$allowed_page    = YSOA_Helpers::allowed_values( 'schema_page_type' );
		$allowed_article = YSOA_Helpers::allowed_values( 'schema_article_type' );
		$allowed_map     = array(
			'page_type'    => $allowed_page,
			'article_type' => $allowed_article,
		);

		$updated  = array();
		$warnings = array();
		$success  = true;

		foreach ( $fields as $field => $meta_key ) {
			if ( ! array_key_exists( $field, $input ) ) {
				continue;
			}

			$value   = trim( (string) $input[ $field ] );
			$allowed = $allowed_map[ $field ];

			if ( '' !== $value && $allowed ) {
				/*
				 * Matched case-insensitively but stored in Yoast's own casing, because
				 * schema.org types are camel case and a caller writing "faqpage" means
				 * FAQPage rather than something invalid.
				 */
				$match = null;
				foreach ( $allowed as $candidate ) {
					if ( 0 === strcasecmp( $candidate, $value ) ) {
						$match = $candidate;
						break;
					}
				}

				if ( null === $match ) {
					return new \WP_Error(
						'ysoa_invalid_schema_type',
						sprintf(
							/* translators: 1: the value supplied, 2: the input field name, 3: comma-separated list of valid values */
							__( '"%1$s" is not a schema %2$s this site accepts. Valid values are: %3$s.', 'mosmcp-abilities' ),
							$value,
							$field,
							implode( ', ', $allowed )
						),
						array( 'allowed' => $allowed )
					);
				}

				$value = $match;
			}

			$success   = YSOA_Helpers::set_meta( $meta_key, $value, $post_id ) && $success;
			$updated[] = $field;

			if ( '' === $value ) {
				$warnings[] = array(
					'code'    => 'fallback_to_post_type_default',
					'message' => sprintf(
						/* translators: %s: the input field name */
						__( 'The %s override was cleared, so Yoast will use the default configured for this post type.', 'mosmcp-abilities' ),
						$field
					),
				);
			}
		}

		if ( ! $updated ) {
			return new \WP_Error(
				'ysoa_nothing_to_update',
				__( 'Provide page_type, article_type, or both.', 'mosmcp-abilities' )
			);
		}

		$stored_page    = (string) YSOA_Helpers::get_meta( 'schema_page_type', $post_id );
		$stored_article = (string) YSOA_Helpers::get_meta( 'schema_article_type', $post_id );

		/*
		 * "None" is not another kind of article — it removes the Article node from the
		 * structured data altogether, which is a bigger change than the name suggests.
		 */
		if ( in_array( 'article_type', $updated, true ) && 'None' === $stored_article ) {
			$warnings[] = array(
				'code'    => 'article_markup_removed',
				'message' => __( 'Setting the article type to "None" removes the Article structured data from this post entirely, rather than changing it to another type. Use a specific type such as BlogPosting if the post should still be described as an article.', 'mosmcp-abilities' ),
			);
		}

		return array(
			'success'               => (bool) $success,
			'page_type'             => $stored_page,
			'article_type'          => $stored_article,
			'updated_fields'        => $updated,
			'allowed_page_types'    => $allowed_page,
			'allowed_article_types' => $allowed_article,
			'warnings'              => $warnings,
		);
	}
}
