<?php
/**
 * Centralized capability checks for the WooCommerce abilities.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Woocommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WooCommerce_Permissions
 *
 * Single source of truth for which WordPress/WooCommerce capability each
 * WooCommerce ability requires. Abilities that surface customer PII
 * (orders, customers) are deliberately gated behind the broader
 * manage_woocommerce capability rather than the narrower per-object caps
 * used for the catalog abilities, so a role granted product access does
 * not automatically see customer data.
 */
class WooCommerce_Permissions {

	const READ_PRODUCTS   = 'edit_products';
	const CREATE_PRODUCTS = 'publish_products';
	const EDIT_PRODUCTS   = 'edit_products';
	const DELETE_PRODUCTS = 'delete_products';

	/**
	 * WooCommerce registers the product_cat and product_tag taxonomies with
	 * identical 'capabilities' args, so these two apply to both.
	 */
	const MANAGE_PRODUCT_TERMS = 'manage_product_terms';
	const DELETE_PRODUCT_TERMS = 'delete_product_terms';

	const READ_ORDERS   = 'manage_woocommerce';
	const CREATE_ORDERS = 'publish_shop_orders';
	const EDIT_ORDERS   = 'edit_shop_orders';
	const DELETE_ORDERS = 'delete_shop_orders';

	const READ_CUSTOMERS = 'manage_woocommerce';

	/**
	 * A customer record is a full WordPress user account, so creating,
	 * editing, or deleting one requires core WP user-management capabilities
	 * (typically Administrator-only by default) rather than manage_woocommerce
	 * (which Shop Manager also holds) — matching the WooCommerce REST API's
	 * own customer permission checks.
	 */
	const CREATE_CUSTOMERS = 'create_users';
	const EDIT_CUSTOMERS   = 'edit_users';
	const DELETE_CUSTOMERS = 'delete_users';

	const READ_COUPONS   = 'edit_shop_coupons';
	const CREATE_COUPONS = 'publish_shop_coupons';
	const EDIT_COUPONS   = 'edit_shop_coupons';
	const DELETE_COUPONS = 'delete_shop_coupons';

	const VIEW_REPORTS = 'view_woocommerce_reports';

	/** @return bool */
	public static function can_read_products() {
		return current_user_can( self::READ_PRODUCTS );
	}

	/** @return bool */
	public static function can_manage_product_terms() {
		return current_user_can( self::MANAGE_PRODUCT_TERMS );
	}

	/** @return bool */
	public static function can_delete_product_terms() {
		return current_user_can( self::DELETE_PRODUCT_TERMS );
	}

	/** @return bool */
	public static function can_create_products() {
		return current_user_can( self::CREATE_PRODUCTS );
	}

	/** @return bool */
	public static function can_edit_products() {
		return current_user_can( self::EDIT_PRODUCTS );
	}

	/**
	 * @param int $product_id The product being edited.
	 * @return bool
	 */
	public static function can_edit_product( $product_id ) {
		return current_user_can( 'edit_product', $product_id );
	}

	/** @return bool */
	public static function can_delete_products() {
		return current_user_can( self::DELETE_PRODUCTS );
	}

	/**
	 * @param int $product_id The product being deleted.
	 * @return bool
	 */
	public static function can_delete_product( $product_id ) {
		return current_user_can( 'delete_product', $product_id );
	}

	/** @return bool */
	public static function can_read_orders() {
		return current_user_can( self::READ_ORDERS );
	}

	/** @return bool */
	public static function can_create_orders() {
		return current_user_can( self::CREATE_ORDERS );
	}

	/** @return bool */
	public static function can_edit_orders() {
		return current_user_can( self::EDIT_ORDERS );
	}

	/**
	 * @param int $order_id The order being edited.
	 * @return bool
	 */
	public static function can_edit_order( $order_id ) {
		return current_user_can( 'edit_shop_order', $order_id );
	}

	/** @return bool */
	public static function can_delete_orders() {
		return current_user_can( self::DELETE_ORDERS );
	}

	/**
	 * @param int $order_id The order being deleted.
	 * @return bool
	 */
	public static function can_delete_order( $order_id ) {
		return current_user_can( 'delete_shop_order', $order_id );
	}

	/** @return bool */
	public static function can_read_customers() {
		return current_user_can( self::READ_CUSTOMERS );
	}

	/** @return bool */
	public static function can_create_customers() {
		return current_user_can( self::CREATE_CUSTOMERS );
	}

	/** @return bool */
	public static function can_edit_customers() {
		return current_user_can( self::EDIT_CUSTOMERS );
	}

	/**
	 * Per-object check: WordPress's own map_meta_cap() for 'edit_user' also
	 * enforces role hierarchy (e.g. blocking a non-Administrator from editing
	 * a higher-privileged account even if they hold the base edit_users
	 * capability), which the coarse can_edit_customers() check alone does not.
	 *
	 * @param int $customer_id The customer being edited.
	 * @return bool
	 */
	public static function can_edit_customer( $customer_id ) {
		return current_user_can( 'edit_user', $customer_id );
	}

	/** @return bool */
	public static function can_delete_customers() {
		return current_user_can( self::DELETE_CUSTOMERS );
	}

	/**
	 * Per-object check — see {@see self::can_edit_customer()} for why this
	 * matters beyond the coarse can_delete_customers() check.
	 *
	 * @param int $customer_id The customer being deleted.
	 * @return bool
	 */
	public static function can_delete_customer( $customer_id ) {
		return current_user_can( 'delete_user', $customer_id );
	}

	/** @return bool */
	public static function can_read_coupons() {
		return current_user_can( self::READ_COUPONS );
	}

	/** @return bool */
	public static function can_create_coupons() {
		return current_user_can( self::CREATE_COUPONS );
	}

	/** @return bool */
	public static function can_edit_coupons() {
		return current_user_can( self::EDIT_COUPONS );
	}

	/**
	 * @param int $coupon_id The coupon being edited.
	 * @return bool
	 */
	public static function can_edit_coupon( $coupon_id ) {
		return current_user_can( 'edit_shop_coupon', $coupon_id );
	}

	/** @return bool */
	public static function can_delete_coupons() {
		return current_user_can( self::DELETE_COUPONS );
	}

	/**
	 * @param int $coupon_id The coupon being deleted.
	 * @return bool
	 */
	public static function can_delete_coupon( $coupon_id ) {
		return current_user_can( 'delete_shop_coupon', $coupon_id );
	}

	/** @return bool */
	public static function can_view_reports() {
		return current_user_can( self::VIEW_REPORTS );
	}
}
