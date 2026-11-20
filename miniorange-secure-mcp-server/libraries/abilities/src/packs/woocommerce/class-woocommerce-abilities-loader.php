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

		// WooCommerce's own abilities (woocommerce/product-delete and the rest)
		// check a capability but do not publish required_cap. Supply it for the
		// admin matrix. Another pack can do the same on this filter.
		add_filter( 'mosmcp_ability_required_cap', array( __CLASS__, 'fallback_required_cap' ), 10, 2 );

		Products_Abilities::register();
		Orders_Abilities::register();
		Customers_Abilities::register();
		Coupons_Abilities::register();
		Reports_Abilities::register();
	}

	/**
	 * Role capability for a WooCommerce core ability that publishes none.
	 *
	 * The names are the ones WooCommerce registers. The values are the primitive
	 * capabilities behind their permission callbacks, which is what a role stores.
	 * A non-empty value passed in is left alone, so an ability that already
	 * declared required_cap is not overwritten.
	 *
	 * @param string $required_cap Capability already resolved, or empty.
	 * @param string $name         Ability name.
	 * @return string
	 */
	public static function fallback_required_cap( $required_cap, $name ) {
		if ( '' !== (string) $required_cap ) {
			return (string) $required_cap;
		}

		$map = array(
			'woocommerce/product-delete'      => 'delete_products',
			'woocommerce/product-create'      => 'publish_products',
			'woocommerce/product-update'      => 'edit_products',
			'woocommerce/products-query'      => 'read_private_products',
			'woocommerce/orders-query'        => 'read_private_shop_orders',
			'woocommerce/order-add-note'      => 'edit_shop_orders',
			'woocommerce/order-update-status' => 'edit_shop_orders',
		);

		return isset( $map[ $name ] ) ? $map[ $name ] : '';
	}
}
