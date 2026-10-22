<?php
/**
 * Detects which bundled, provider-gated ability sets are active on this site and
 * which are dormant because their companion plugin is not active, so the admin
 * SPA can name the live ones and advertise the rest.
 *
 * This is a server-side data provider only: which WordPress plugins are active
 * can only be determined in PHP, so the SPA receives the result through the
 * bootstrap payload and renders the discovery callout itself (matching the
 * plugin UI). There is deliberately no admin_notices rendering here — a core WP
 * notice does not belong on a React SPA screen.
 *
 * @package Miniorange_Secure_MCP_Server
 */

namespace MoSMCP\Common\Views\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Dormant_Abilities_Provider
 */
class Dormant_Abilities_Provider {

	/**
	 * The provider-gated packs that are not currently active on this site.
	 *
	 * @return array<int, array{label:string, count:int}>
	 */
	public static function packs() {
		return self::filtered( false );
	}

	/**
	 * The provider-gated packs that ARE active on this site.
	 *
	 * Their abilities are already registered and counted in the headline total,
	 * so the SPA names them rather than leaving them anonymous inside it.
	 *
	 * @return array<int, array{label:string, count:int}>
	 */
	public static function active_packs() {
		return self::filtered( true );
	}

	/**
	 * Providers whose activity matches the wanted state.
	 *
	 * @param bool $wanted Whether to return the active providers or the dormant ones.
	 * @return array<int, array{label:string, count:int}>
	 */
	private static function filtered( $wanted ) {
		$matched = array();

		foreach ( self::providers() as $provider ) {
			if ( (bool) call_user_func( $provider['active'] ) === (bool) $wanted ) {
				$matched[] = array(
					'label' => $provider['label'],
					'count' => $provider['count'],
				);
			}
		}

		return $matched;
	}

	/**
	 * Provider registry: label, ability count, and an activity detector matching
	 * the gate each pack registers behind.
	 *
	 * @return array<int, array{label:string, count:int, active:callable}>
	 */
	private static function providers() {
		return array(
			array(
				'label'  => __( 'WooCommerce', 'miniorange-secure-mcp-server' ),
				'count'  => 41,
				'active' => static function () {
					return class_exists( 'WooCommerce' );
				},
			),
			array(
				'label'  => __( 'Advanced Custom Fields', 'miniorange-secure-mcp-server' ),
				'count'  => 25,
				'active' => static function () {
					return function_exists( 'acf' );
				},
			),
			array(
				'label'  => __( 'Elementor', 'miniorange-secure-mcp-server' ),
				'count'  => 10,
				'active' => static function () {
					return class_exists( 'Elementor\Plugin' );
				},
			),
			array(
				'label'  => __( 'Yoast SEO', 'miniorange-secure-mcp-server' ),
				'count'  => 7,
				'active' => static function () {
					return function_exists( 'YoastSEO' ) || class_exists( 'WPSEO_Meta' );
				},
			),
			array(
				'label'  => __( 'Rank Math', 'miniorange-secure-mcp-server' ),
				'count'  => 1,
				'active' => static function () {
					return defined( 'RANK_MATH_VERSION' );
				},
			),
			array(
				'label'  => __( 'Contact Form 7', 'miniorange-secure-mcp-server' ),
				'count'  => 12,
				'active' => static function () {
					return class_exists( 'WPCF7_ContactForm' );
				},
			),
			array(
				'label'  => __( 'WPForms', 'miniorange-secure-mcp-server' ),
				'count'  => 14,
				'active' => static function () {
					return function_exists( 'wpforms' );
				},
			),
			array(
				'label'  => __( 'Gravity Forms', 'miniorange-secure-mcp-server' ),
				'count'  => 14,
				'active' => static function () {
					return class_exists( 'GFAPI' );
				},
			),
			array(
				'label'  => __( 'Fluent Forms', 'miniorange-secure-mcp-server' ),
				'count'  => 14,
				'active' => static function () {
					return defined( 'FLUENTFORM_VERSION' );
				},
			),
			array(
				'label'  => __( 'Kadence Blocks', 'miniorange-secure-mcp-server' ),
				'count'  => 15,
				'active' => static function () {
					return defined( 'KADENCE_BLOCKS_VERSION' ) || class_exists( 'Kadence_Blocks_Frontend' );
				},
			),
			array(
				'label'  => __( 'Kadence Theme', 'miniorange-secure-mcp-server' ),
				'count'  => 2,
				'active' => static function () {
					return defined( 'KADENCE_VERSION' ) || function_exists( 'kadence' );
				},
			),
		);
	}
}
