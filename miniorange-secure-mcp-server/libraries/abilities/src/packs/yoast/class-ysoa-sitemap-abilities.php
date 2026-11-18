<?php
/**
 * Sitemap management ability (7).
 *
 * Yoast's free sitemap does not expose a stock per-post "exclude from sitemap"
 * checkbox (that toggle only exists in Premium's Advanced tab); free-tier
 * per-post/term exclusion is done via the `wpseo_exclude_from_sitemap_by_post_ids`
 * filter. This class maintains that exclusion list as its own option and hooks
 * the filter so the setting actually affects sitemap generation, independent
 * of noindex (excluding from the sitemap should not be conflated with removing
 * the post from search engines entirely).
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

/*
 * These abilities intentionally query by post/user/comment meta or taxonomy
 * (and exclude specific IDs) — that is the tool surface the library exposes.
 * The queries are bounded and parameterized, so this performance advisory is
 * accepted here (the sniff is not part of the library's own phpcs.xml.dist; this
 * directive covers Plugin Check, which enforces its own broader standard).
 */
// phpcs:disable WordPressVIPMinimum.Performance.WPQueryParams

/**
 * Class YSOA_Sitemap_Abilities
 */
class YSOA_Sitemap_Abilities {

	/**
	 * Option name storing the array of excluded post IDs.
	 */
	const OPTION_NAME = 'ysoa_sitemap_excluded_posts';

	/**
	 * Register ability 7.
	 */
	public static function register_all() {

		YSOA_Helpers::register(
			'update-sitemap-inclusion',
			array(
				'label'               => __( 'Update Yoast Sitemap Inclusion', 'mosmcp-abilities' ),
				'description'         => __( 'Includes or excludes a specific post/page from Yoast\'s XML sitemap. Note: sitemap inclusion is also controlled at the post-type level in Yoast\'s global settings; this ability only affects the individual post regardless of that setting.', 'mosmcp-abilities' ),
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id' => array(
							'type'        => 'integer',
							'description' => __( 'ID of the post/page/CPT entry.', 'mosmcp-abilities' ),
						),
						'exclude' => array(
							'type'        => 'boolean',
							'description' => __( 'If true, removes the entry from the XML sitemap; if false, ensures it\'s included.', 'mosmcp-abilities' ),
						),
					),
					'required'             => array( 'post_id', 'exclude' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'  => array( 'type' => 'boolean' ),
						'post_id'  => array( 'type' => 'integer' ),
						'excluded' => array( 'type' => 'boolean' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_update_sitemap_inclusion' ),
				'permission_callback' => YSOA_Helpers::post_permission(),
			)
		);
	}

	/**
	 * 7. Add or remove a post from the sitemap-exclusion list.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function execute_update_sitemap_inclusion( $input ) {
		$post_id = YSOA_Helpers::require_post( $input );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}
		$exclude = filter_var( $input['exclude'], FILTER_VALIDATE_BOOLEAN );

		$excluded_ids = self::get_excluded_ids();
		if ( $exclude ) {
			$excluded_ids[ $post_id ] = $post_id;
		} else {
			unset( $excluded_ids[ $post_id ] );
		}
		update_option( self::OPTION_NAME, array_values( $excluded_ids ), false );

		return array(
			'success'  => true,
			'post_id'  => $post_id,
			'excluded' => $exclude,
		);
	}

	/**
	 * Read the excluded-post-ID list, keyed by ID for fast add/remove.
	 *
	 * @return array
	 */
	private static function get_excluded_ids() {
		$ids = get_option( self::OPTION_NAME, array() );
		$ids = is_array( $ids ) ? array_values( array_unique( array_map( 'intval', $ids ) ) ) : array();
		return array_combine( $ids, $ids );
	}

	/**
	 * Filter callback for `wpseo_exclude_from_sitemap_by_post_ids`: merges our
	 * stored exclusion list into whatever Yoast (or other plugins) already exclude.
	 *
	 * @param array $excluded_posts_ids Post IDs already excluded.
	 * @return array
	 */
	public static function filter_excluded_posts( $excluded_posts_ids ) {
		$ids = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $ids ) || empty( $ids ) ) {
			return $excluded_posts_ids;
		}
		return array_values(
			array_unique(
				array_merge( (array) $excluded_posts_ids, array_map( 'intval', $ids ) )
			)
		);
	}
}
