<?php
/**
 * Shared summary builders and logging for the WooCommerce abilities.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Woocommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WC_Coupon;
use WC_Customer;
use WC_Order;
use WC_Product;

/**
 * Class WooCommerce_Helper
 *
 * Holds the object-to-array summary builders shared across ability classes,
 * plus a single logging hook every ability reports through, so ability
 * classes stay focused on request handling rather than data shaping.
 */
class WooCommerce_Helper {

	/**
	 * @return bool Whether WooCommerce is active.
	 */
	public static function is_active() {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Formats a monetary value to 2 decimal places, matching the official
	 * WooCommerce REST API's own money formatting (e.g. "30" -> "30.00").
	 * Plain (string) casts on WC getters drop trailing zeros, which this fixes.
	 *
	 * @param mixed $value
	 * @return string
	 */
	public static function money( $value ) {
		return wc_format_decimal( (string) $value, 2 );
	}

	/**
	 * Builds the full product summary returned by every product ability.
	 * Field names and semantics deliberately mirror the WooCommerce REST API
	 * v3 Product object (https://developer.woocommerce.com/docs/apis/rest-api/v3/products/)
	 * so anything already familiar with that API maps directly onto these abilities.
	 *
	 * @param WC_Product $product
	 * @return array<string, mixed>
	 */
	public static function product_summary( $product ) {
		$date_created  = $product->get_date_created();
		$date_modified = $product->get_date_modified();

		$image_ids = array();
		if ( $product->get_image_id() ) {
			$image_ids[] = (int) $product->get_image_id();
		}
		$image_ids = array_unique( array_merge( $image_ids, array_map( 'intval', $product->get_gallery_image_ids() ) ) );

		return array(
			'id'                 => $product->get_id(),
			'name'               => $product->get_name(),
			'slug'               => $product->get_slug(),
			'type'               => $product->get_type(),
			'status'             => $product->get_status(),
			'featured'           => $product->is_featured(),
			'catalog_visibility' => $product->get_catalog_visibility(),
			'description'        => $product->get_description(),
			'short_description'  => $product->get_short_description(),
			'sku'                => $product->get_sku(),
			'price'              => (string) $product->get_price(),
			'regular_price'      => (string) $product->get_regular_price(),
			'sale_price'         => (string) $product->get_sale_price(),
			'price_html'         => $product->get_price_html(),
			'on_sale'            => $product->is_on_sale(),
			'purchasable'        => $product->is_purchasable(),
			'virtual'            => $product->is_virtual(),
			'downloadable'       => $product->is_downloadable(),
			'manage_stock'       => $product->get_manage_stock(),
			'stock_quantity'     => $product->get_manage_stock() ? $product->get_stock_quantity() : null,
			'stock_status'       => $product->get_stock_status(),
			'backorders'         => $product->get_backorders(),
			'weight'             => (string) $product->get_weight(),
			'dimensions'         => array(
				'length' => (string) $product->get_length(),
				'width'  => (string) $product->get_width(),
				'height' => (string) $product->get_height(),
			),
			'tax_status'         => $product->get_tax_status(),
			'tax_class'          => $product->get_tax_class(),
			'shipping_class'     => $product->get_shipping_class(),
			'sold_individually'  => $product->get_sold_individually(),
			'upsell_ids'         => array_map( 'intval', $product->get_upsell_ids() ),
			'cross_sell_ids'     => array_map( 'intval', $product->get_cross_sell_ids() ),
			'purchase_note'      => $product->get_purchase_note(),
			'menu_order'         => (int) $product->get_menu_order(),
			'categories'         => self::term_summaries( $product->get_id(), 'product_cat' ),
			'tags'               => self::term_summaries( $product->get_id(), 'product_tag' ),
			'images'             => array_map( array( __CLASS__, 'image_summary' ), $image_ids ),
			'date_created'       => $date_created ? $date_created->date( 'c' ) : null,
			'date_modified'      => $date_modified ? $date_modified->date( 'c' ) : null,
			'permalink'          => (string) get_permalink( $product->get_id() ),
		);
	}

	/**
	 * @param int    $product_id
	 * @param string $taxonomy 'product_cat' or 'product_tag'.
	 * @return array<int, array{id:int, name:string, slug:string}>
	 */
	private static function term_summaries( $product_id, $taxonomy ) {
		$terms = function_exists( 'wc_get_product_terms' )
			? wc_get_product_terms( $product_id, $taxonomy, array( 'fields' => 'all' ) )
			: array();

		if ( ! is_array( $terms ) ) {
			return array();
		}

		return array_map(
			static function ( $term ) {
				return array(
					'id'   => (int) $term->term_id,
					'name' => $term->name,
					'slug' => $term->slug,
				);
			},
			$terms
		);
	}

	/**
	 * @param int $attachment_id
	 * @return array{id:int, src:string, alt:string}
	 */
	private static function image_summary( $attachment_id ) {
		return array(
			'id'  => $attachment_id,
			'src' => (string) wp_get_attachment_url( $attachment_id ),
			'alt' => (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
		);
	}

	/**
	 * Builds the full order summary returned by every order ability. Field
	 * names and semantics deliberately mirror the WooCommerce REST API v3
	 * Order object (https://developer.woocommerce.com/docs/apis/rest-api/v3/orders/),
	 * including its billing/shipping/line_items/tax_lines/shipping_lines/
	 * fee_lines/coupon_lines/refunds sub-objects.
	 *
	 * @param WC_Order $order
	 * @return array<string, mixed>
	 */
	public static function order_summary( $order ) {
		$date_created   = $order->get_date_created();
		$date_modified  = $order->get_date_modified();
		$date_paid      = $order->get_date_paid();
		$date_completed = $order->get_date_completed();

		return array(
			'id'                   => $order->get_id(),
			'parent_id'            => (int) $order->get_parent_id(),
			'number'               => $order->get_order_number(),
			'order_key'            => $order->get_order_key(),
			'created_via'          => $order->get_created_via(),
			'status'               => $order->get_status(),
			'currency'             => $order->get_currency(),
			'date_created'         => $date_created ? $date_created->date( 'c' ) : null,
			'date_modified'        => $date_modified ? $date_modified->date( 'c' ) : null,
			'discount_total'       => self::money( $order->get_discount_total() ),
			'discount_tax'         => self::money( $order->get_discount_tax() ),
			'shipping_total'       => self::money( $order->get_shipping_total() ),
			'shipping_tax'         => self::money( $order->get_shipping_tax() ),
			// Legacy REST API field: total tax minus shipping tax. WC_Order has no
			// dedicated getter for this; it's derived the same way WC's own REST
			// controller derives it for backward compatibility.
			'cart_tax'             => self::money( (float) $order->get_total_tax() - (float) $order->get_shipping_tax() ),
			'total'                => self::money( $order->get_total() ),
			'total_tax'            => self::money( $order->get_total_tax() ),
			'prices_include_tax'   => $order->get_prices_include_tax(),
			'customer_id'          => (int) $order->get_customer_id(),
			'customer_ip_address'  => $order->get_customer_ip_address(),
			'customer_user_agent'  => $order->get_customer_user_agent(),
			'customer_note'        => $order->get_customer_note(),
			'billing'              => self::order_address( $order, 'billing' ),
			'shipping'             => self::order_address( $order, 'shipping' ),
			'payment_method'       => $order->get_payment_method(),
			'payment_method_title' => $order->get_payment_method_title(),
			'transaction_id'       => $order->get_transaction_id(),
			'date_paid'            => $date_paid ? $date_paid->date( 'c' ) : null,
			'date_completed'       => $date_completed ? $date_completed->date( 'c' ) : null,
			'line_items'           => array_map( array( __CLASS__, 'line_item_summary' ), array_values( $order->get_items( 'line_item' ) ) ),
			'tax_lines'            => array_map( array( __CLASS__, 'tax_line_summary' ), array_values( $order->get_items( 'tax' ) ) ),
			'shipping_lines'       => array_map( array( __CLASS__, 'shipping_line_summary' ), array_values( $order->get_items( 'shipping' ) ) ),
			'fee_lines'            => array_map( array( __CLASS__, 'fee_line_summary' ), array_values( $order->get_items( 'fee' ) ) ),
			'coupon_lines'         => array_map( array( __CLASS__, 'coupon_line_summary' ), array_values( $order->get_items( 'coupon' ) ) ),
			'refunds'              => array_map( array( __CLASS__, 'refund_line_summary' ), $order->get_refunds() ),
		);
	}

	/**
	 * Core billing/shipping address fields common to both orders and customers.
	 *
	 * @var string[]
	 */
	const ADDRESS_CORE_FIELDS = array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country' );

	/**
	 * Reads a billing/shipping address off an order or customer object.
	 *
	 * The core field set is identical for both; what differs (and is the caller's
	 * responsibility to get right — see order_address()/customer_address() below)
	 * is which extra fields exist for which $type: orders only carry email/phone
	 * on billing, while customers carry both on billing AND shipping.
	 *
	 * @param WC_Order|WC_Customer  $object       The order or customer.
	 * @param string                $type         'billing' or 'shipping'.
	 * @param array<string, string> $extra_fields Extra field name => sanitizer tag
	 *                                            ('text' or 'email'), beyond the core set, for this $type.
	 * @return array<string, string>
	 */
	private static function read_address( $object, $type, array $extra_fields = array() ) {
		$field = static function ( $name ) use ( $object, $type ) {
			$method = 'get_' . $type . '_' . $name;
			return method_exists( $object, $method ) ? (string) $object->$method() : '';
		};

		$address = array();
		foreach ( self::ADDRESS_CORE_FIELDS as $name ) {
			$address[ $name ] = $field( $name );
		}
		foreach ( array_keys( $extra_fields ) as $name ) {
			$address[ $name ] = $field( $name );
		}

		return $address;
	}

	/**
	 * Applies a billing/shipping address onto an order or customer object.
	 *
	 * @param WC_Order|WC_Customer  $object       The order or customer.
	 * @param string                $type         'billing' or 'shipping'.
	 * @param array<string, mixed>  $address      Caller-supplied address fields.
	 * @param array<string, string> $extra_fields Extra field name => sanitizer tag
	 *                                            ('text' or 'email'), beyond the core set, for this $type.
	 * @return void
	 */
	public static function apply_address( $object, $type, array $address, array $extra_fields = array() ) {
		foreach ( self::ADDRESS_CORE_FIELDS as $name ) {
			if ( ! isset( $address[ $name ] ) ) {
				continue;
			}
			$method = 'set_' . $type . '_' . $name;
			if ( method_exists( $object, $method ) ) {
				$object->$method( sanitize_text_field( (string) $address[ $name ] ) );
			}
		}

		foreach ( $extra_fields as $name => $sanitizer ) {
			if ( ! isset( $address[ $name ] ) ) {
				continue;
			}
			$method = 'set_' . $type . '_' . $name;
			if ( ! method_exists( $object, $method ) ) {
				continue;
			}
			$value = 'email' === $sanitizer ? sanitize_email( (string) $address[ $name ] ) : sanitize_text_field( (string) $address[ $name ] );
			$object->$method( $value );
		}
	}

	/**
	 * @param WC_Order $order
	 * @param string   $type 'billing' or 'shipping'.
	 * @return array<string, string>
	 */
	private static function order_address( $order, $type ) {
		// Only billing carries email/phone in the official Order schema.
		$extra_fields = ( 'billing' === $type ) ? array(
			'email' => 'email',
			'phone' => 'text',
		) : array();

		return self::read_address( $order, $type, $extra_fields );
	}

	/**
	 * Public: also reused by Orders_Abilities::refund_detail(), since a
	 * refund's line items are the same WC_Order_Item_Product shape.
	 *
	 * @param \WC_Order_Item_Product $item
	 * @return array<string, mixed>
	 */
	public static function line_item_summary( $item ) {
		$product      = $item->get_product();
		$variation_id = (int) $item->get_variation_id();
		$quantity     = (int) $item->get_quantity();

		$parent_name = null;
		if ( $variation_id > 0 && $product ) {
			$parent = wc_get_product( $product->get_parent_id() );
			if ( $parent ) {
				$parent_name = $parent->get_name();
			}
		}

		return array(
			'id'           => $item->get_id(),
			'name'         => $item->get_name(),
			'parent_name'  => $parent_name,
			'product_id'   => (int) $item->get_product_id(),
			'variation_id' => $variation_id,
			'quantity'     => $quantity,
			'tax_class'    => $item->get_tax_class(),
			'subtotal'     => self::money( $item->get_subtotal() ),
			'subtotal_tax' => self::money( $item->get_subtotal_tax() ),
			'total'        => self::money( $item->get_total() ),
			'total_tax'    => self::money( $item->get_total_tax() ),
			'sku'          => $product ? $product->get_sku() : '',
			'price'        => self::money( $quantity > 0 ? ( (float) $item->get_total() / $quantity ) : $item->get_total() ),
		);
	}

	/**
	 * @param \WC_Order_Item_Tax $item
	 * @return array<string, mixed>
	 */
	private static function tax_line_summary( $item ) {
		return array(
			'id'                 => $item->get_id(),
			'rate_code'          => $item->get_rate_code(),
			'rate_id'            => (int) $item->get_rate_id(),
			'label'              => $item->get_label(),
			'compound'           => (bool) $item->get_compound(),
			'tax_total'          => self::money( $item->get_tax_total() ),
			'shipping_tax_total' => self::money( $item->get_shipping_tax_total() ),
		);
	}

	/**
	 * @param \WC_Order_Item_Shipping $item
	 * @return array<string, mixed>
	 */
	private static function shipping_line_summary( $item ) {
		return array(
			'id'           => $item->get_id(),
			'method_title' => $item->get_method_title(),
			'method_id'    => $item->get_method_id(),
			'total'        => self::money( $item->get_total() ),
			'total_tax'    => self::money( $item->get_total_tax() ),
		);
	}

	/**
	 * @param \WC_Order_Item_Fee $item
	 * @return array<string, mixed>
	 */
	private static function fee_line_summary( $item ) {
		return array(
			'id'         => $item->get_id(),
			'name'       => $item->get_name(),
			'tax_class'  => $item->get_tax_class(),
			'tax_status' => $item->get_tax_status(),
			'total'      => self::money( $item->get_total() ),
			'total_tax'  => self::money( $item->get_total_tax() ),
		);
	}

	/**
	 * @param \WC_Order_Item_Coupon $item
	 * @return array<string, mixed>
	 */
	private static function coupon_line_summary( $item ) {
		return array(
			'id'           => $item->get_id(),
			'code'         => $item->get_code(),
			'discount'     => self::money( $item->get_discount() ),
			'discount_tax' => self::money( $item->get_discount_tax() ),
		);
	}

	/**
	 * Lightweight refund summary embedded in order_summary(). See
	 * {@see Orders_Abilities::refund_detail()} for the fuller shape returned
	 * by list-order-refunds.
	 *
	 * @param \WC_Order_Refund $refund
	 * @return array<string, mixed>
	 */
	private static function refund_line_summary( $refund ) {
		return array(
			'id'     => $refund->get_id(),
			'reason' => $refund->get_reason(),
			'total'  => self::money( $refund->get_amount() ),
		);
	}

	/**
	 * Builds the full customer summary returned by every customer ability.
	 * Field names and semantics deliberately mirror the WooCommerce REST API
	 * v3 Customer object (https://developer.woocommerce.com/docs/apis/rest-api/v3/customers/).
	 * order_count/total_spent are additions beyond that official schema —
	 * not documented there, but cheaply available via WC_Customer and
	 * directly useful, so kept as a deliberate bonus rather than omitted.
	 *
	 * @param int $customer_id
	 * @return array<string, mixed>
	 */
	public static function customer_summary( $customer_id ) {
		$customer      = new WC_Customer( $customer_id );
		$date_created  = $customer->get_date_created();
		$date_modified = $customer->get_date_modified();

		return array(
			'id'                 => $customer->get_id(),
			'date_created'       => $date_created ? $date_created->date( 'c' ) : null,
			'date_modified'      => $date_modified ? $date_modified->date( 'c' ) : null,
			'email'              => $customer->get_email(),
			'first_name'         => $customer->get_first_name(),
			'last_name'          => $customer->get_last_name(),
			'role'               => $customer->get_role(),
			'username'           => $customer->get_username(),
			'billing'            => self::customer_address( $customer, 'billing' ),
			'shipping'           => self::customer_address( $customer, 'shipping' ),
			'is_paying_customer' => (bool) $customer->get_is_paying_customer(),
			'avatar_url'         => get_avatar_url( $customer->get_id() ),
			'order_count'        => (int) $customer->get_order_count(),
			'total_spent'        => self::money( $customer->get_total_spent() ),
		);
	}

	/**
	 * Unlike order addresses, the official Customer schema gives billing and
	 * shipping an identical shape — both carry phone and email.
	 *
	 * @param WC_Customer $customer
	 * @param string      $type 'billing' or 'shipping'.
	 * @return array<string, string>
	 */
	private static function customer_address( $customer, $type ) {
		// Unlike order addresses, the official Customer schema gives billing and
		// shipping an identical shape — both carry phone and email.
		return self::read_address(
			$customer,
			$type,
			array(
				'phone' => 'text',
				'email' => 'email',
			)
		);
	}

	/**
	 * Builds the full coupon summary returned by every coupon ability. Field
	 * names and semantics deliberately mirror the WooCommerce REST API v3
	 * Coupon object (https://developer.woocommerce.com/docs/apis/rest-api/v3/coupons/).
	 *
	 * @param int $coupon_id
	 * @return array<string, mixed>
	 */
	public static function coupon_summary( $coupon_id ) {
		$coupon        = new WC_Coupon( $coupon_id );
		$date_created  = $coupon->get_date_created();
		$date_modified = $coupon->get_date_modified();
		$date_expires  = $coupon->get_date_expires();

		return array(
			'id'                          => $coupon->get_id(),
			'code'                        => $coupon->get_code(),
			'amount'                      => self::money( $coupon->get_amount() ),
			'date_created'                => $date_created ? $date_created->date( 'c' ) : null,
			'date_modified'               => $date_modified ? $date_modified->date( 'c' ) : null,
			'discount_type'               => $coupon->get_discount_type(),
			'description'                 => $coupon->get_description(),
			'date_expires'                => $date_expires ? $date_expires->date( 'Y-m-d' ) : null,
			'usage_count'                 => (int) $coupon->get_usage_count(),
			'individual_use'              => (bool) $coupon->get_individual_use(),
			'product_ids'                 => array_map( 'intval', $coupon->get_product_ids() ),
			'excluded_product_ids'        => array_map( 'intval', $coupon->get_excluded_product_ids() ),
			'usage_limit'                 => $coupon->get_usage_limit() ? (int) $coupon->get_usage_limit() : null,
			'usage_limit_per_user'        => $coupon->get_usage_limit_per_user() ? (int) $coupon->get_usage_limit_per_user() : null,
			'limit_usage_to_x_items'      => $coupon->get_limit_usage_to_x_items(),
			'free_shipping'               => (bool) $coupon->get_free_shipping(),
			'product_categories'          => array_map( 'intval', $coupon->get_product_categories() ),
			'excluded_product_categories' => array_map( 'intval', $coupon->get_excluded_product_categories() ),
			'exclude_sale_items'          => (bool) $coupon->get_exclude_sale_items(),
			'minimum_amount'              => self::money( $coupon->get_minimum_amount() ),
			'maximum_amount'              => self::money( $coupon->get_maximum_amount() ),
			'email_restrictions'          => $coupon->get_email_restrictions(),
			'used_by'                     => array_map( 'strval', $coupon->get_used_by() ),
		);
	}

	/**
	 * Fires a logging action after a WooCommerce ability executes. Nothing
	 * subscribes to this by default; it exists so audit/telemetry integrations
	 * (in this plugin, a consuming MCP server, or a site-specific mu-plugin)
	 * can hook richer per-call detail than they'd otherwise have.
	 *
	 * @param string               $ability Ability name (e.g. mosmcp/list-products).
	 * @param string               $status  'success' or 'failure'.
	 * @param array<string, mixed> $context Arbitrary context: object id(s), counts, timing.
	 * @return void
	 */
	public static function log( $ability, $status, array $context = array() ) {
		/**
		 * Fires after a WooCommerce ability finishes executing.
		 *
		 * @param string               $ability Ability name.
		 * @param string               $status  'success' or 'failure'.
		 * @param array<string, mixed> $context Context: e.g. id, count, reason.
		 */
		do_action( 'mosmcp_wc_ability_executed', $ability, $status, $context );
	}
}
