<?php
/**
 * Registers the WooCommerce ability category and delegates to each domain's registrar.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Woocommerce;

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
 * Class WooCommerce_Abilities_Loader
 *
 * Entry point wired to the Abilities API hooks in the plugin bootstrap file.
 * Both methods no-op when WooCommerce is not active. Adding a new WooCommerce
 * ability domain means adding one *_Abilities::register() call here — each
 * domain class owns its own ability definitions, schemas, and callbacks.
 */
class WooCommerce_Abilities_Loader {

	/**
	 * Ability category slug shared by every ability this plugin registers.
	 */
	const CATEGORY = 'mosmcp-woocommerce';

	/**
	 * Registers the ability category. No-op when WooCommerce is not active.
	 *
	 * @return void
	 */
	public static function register_categories() {
		if ( ! function_exists( 'wp_register_ability_category' ) || ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		Naming::register_category(
			self::CATEGORY,
			array(
				'label'       => __( 'WooCommerce', 'mosmcp-abilities' ),
				'description' => __( 'Abilities that read and manage WooCommerce products, orders, customers, coupons, and reports.', 'mosmcp-abilities' ),
			)
		);
	}

	/**
	 * Registers every WooCommerce ability domain. No-op when WooCommerce is not active.
	 *
	 * @return void
	 */
	public static function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) || ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		Products_Abilities::register();
		Orders_Abilities::register();
		Customers_Abilities::register();
		Coupons_Abilities::register();
		Reports_Abilities::register();
	}
}
