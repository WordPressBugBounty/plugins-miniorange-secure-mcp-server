<?php
/**
 * WooCommerce Orders abilities: CRUD, status, notes, and refunds.
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

use WC_Order_Item_Fee;
use WC_Order_Item_Shipping;
use WP_Error;

/**
 * Class Orders_Abilities
 *
 * Registers mosmcp/list-orders, get-order, create-order,
 * update-order-status, update-order, create-order-note, list-order-notes,
 * delete-order, list-order-refunds, and create-order-refund. Field names
 * deliberately mirror the WooCommerce REST API v3 Order object
 * (https://developer.woocommerce.com/docs/apis/rest-api/v3/orders/),
 * including its billing/shipping/line_items/tax_lines/shipping_lines/
 * fee_lines/coupon_lines/refunds sub-objects.
 *
 * All ten abilities are gated behind manage_woocommerce or edit_shop_orders
 * (see {@see WooCommerce_Permissions}) because every order carries customer
 * billing/shipping PII. mosmcp/batch-update-orders is deliberately not
 * included yet — bulk operations are a separate, later phase.
 *
 * Two known limitations, not yet verified against a live store:
 * - The exact object shape returned by wc_get_order_notes() (used by
 *   list-order-notes) is based on WooCommerce's documented behavior, not a
 *   live test; the 'author' field in particular may need adjustment.
 * - delete-order's per-order capability check ('delete_shop_order' meta cap)
 *   has not been verified under HPOS (custom order tables) order storage.
 */
class Orders_Abilities {

	/**
	 * Standard WooCommerce order statuses (without the internal "wc-" prefix).
	 */
	const STATUSES = array( 'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed' );

	/**
	 * Note types accepted by list-order-notes.
	 */
	const NOTE_TYPES = array( 'any', 'customer', 'internal' );

	/**
	 * Tax status options for a fee line.
	 */
	const FEE_TAX_STATUSES = array( 'taxable', 'none' );

	/**
	 * Registers every Orders ability. Called from {@see WooCommerce_Abilities_Loader}.
	 *
	 * @return void
	 */
	public static function register() {
		$billing_schema = array(
			'type'                 => 'object',
			'properties'           => array(
				'first_name' => array( 'type' => 'string' ),
				'last_name'  => array( 'type' => 'string' ),
				'company'    => array( 'type' => 'string' ),
				'address_1'  => array( 'type' => 'string' ),
				'address_2'  => array( 'type' => 'string' ),
				'city'       => array( 'type' => 'string' ),
				'state'      => array( 'type' => 'string' ),
				'postcode'   => array( 'type' => 'string' ),
				'country'    => array( 'type' => 'string' ),
				'email'      => array( 'type' => 'string' ),
				'phone'      => array( 'type' => 'string' ),
			),
			'additionalProperties' => false,
		);

		$shipping_schema = array(
			'type'                 => 'object',
			'properties'           => array(
				'first_name' => array( 'type' => 'string' ),
				'last_name'  => array( 'type' => 'string' ),
				'company'    => array( 'type' => 'string' ),
				'address_1'  => array( 'type' => 'string' ),
				'address_2'  => array( 'type' => 'string' ),
				'city'       => array( 'type' => 'string' ),
				'state'      => array( 'type' => 'string' ),
				'postcode'   => array( 'type' => 'string' ),
				'country'    => array( 'type' => 'string' ),
			),
			'additionalProperties' => false,
		);

		$line_item_schema = array(
			'type'                 => 'object',
			'properties'           => array(
				'id'           => array( 'type' => 'integer' ),
				'name'         => array( 'type' => 'string' ),
				'parent_name'  => array( 'type' => array( 'string', 'null' ) ),
				'product_id'   => array( 'type' => 'integer' ),
				'variation_id' => array( 'type' => 'integer' ),
				'quantity'     => array( 'type' => 'integer' ),
				'tax_class'    => array( 'type' => 'string' ),
				'subtotal'     => array( 'type' => 'string' ),
				'subtotal_tax' => array( 'type' => 'string' ),
				'total'        => array( 'type' => 'string' ),
				'total_tax'    => array( 'type' => 'string' ),
				'sku'          => array( 'type' => 'string' ),
				'price'        => array( 'type' => 'string' ),
			),
			'additionalProperties' => false,
		);

		$tax_line_schema = array(
			'type'                 => 'object',
			'properties'           => array(
				'id'                 => array( 'type' => 'integer' ),
				'rate_code'          => array( 'type' => 'string' ),
				'rate_id'            => array( 'type' => 'integer' ),
				'label'              => array( 'type' => 'string' ),
				'compound'           => array( 'type' => 'boolean' ),
				'tax_total'          => array( 'type' => 'string' ),
				'shipping_tax_total' => array( 'type' => 'string' ),
			),
			'additionalProperties' => false,
		);

		$shipping_line_schema = array(
			'type'                 => 'object',
			'properties'           => array(
				'id'           => array( 'type' => 'integer' ),
				'method_title' => array( 'type' => 'string' ),
				'method_id'    => array( 'type' => 'string' ),
				'total'        => array( 'type' => 'string' ),
				'total_tax'    => array( 'type' => 'string' ),
			),
			'additionalProperties' => false,
		);

		$fee_line_schema = array(
			'type'                 => 'object',
			'properties'           => array(
				'id'         => array( 'type' => 'integer' ),
				'name'       => array( 'type' => 'string' ),
				'tax_class'  => array( 'type' => 'string' ),
				'tax_status' => array( 'type' => 'string' ),
				'total'      => array( 'type' => 'string' ),
				'total_tax'  => array( 'type' => 'string' ),
			),
			'additionalProperties' => false,
		);

		$coupon_line_schema = array(
			'type'                 => 'object',
			'properties'           => array(
				'id'           => array( 'type' => 'integer' ),
				'code'         => array( 'type' => 'string' ),
				'discount'     => array( 'type' => 'string' ),
				'discount_tax' => array( 'type' => 'string' ),
			),
			'additionalProperties' => false,
		);

		$refund_line_schema = array(
			'type'                 => 'object',
			'properties'           => array(
				'id'     => array( 'type' => 'integer' ),
				'reason' => array( 'type' => 'string' ),
				'total'  => array( 'type' => 'string' ),
			),
			'additionalProperties' => false,
		);

		$order_item_schema = array(
			'type'                 => 'object',
			'properties'           => array(
				'id'                   => array( 'type' => 'integer' ),
				'parent_id'            => array( 'type' => 'integer' ),
				'number'               => array( 'type' => 'string' ),
				'order_key'            => array( 'type' => 'string' ),
				'created_via'          => array( 'type' => 'string' ),
				'status'               => array( 'type' => 'string' ),
				'currency'             => array( 'type' => 'string' ),
				'date_created'         => array( 'type' => array( 'string', 'null' ) ),
				'date_modified'        => array( 'type' => array( 'string', 'null' ) ),
				'discount_total'       => array( 'type' => 'string' ),
				'discount_tax'         => array( 'type' => 'string' ),
				'shipping_total'       => array( 'type' => 'string' ),
				'shipping_tax'         => array( 'type' => 'string' ),
				'cart_tax'             => array( 'type' => 'string' ),
				'total'                => array( 'type' => 'string' ),
				'total_tax'            => array( 'type' => 'string' ),
				'prices_include_tax'   => array( 'type' => 'boolean' ),
				'customer_id'          => array( 'type' => 'integer' ),
				'customer_ip_address'  => array( 'type' => 'string' ),
				'customer_user_agent'  => array( 'type' => 'string' ),
				'customer_note'        => array( 'type' => 'string' ),
				'billing'              => $billing_schema,
				'shipping'             => $shipping_schema,
				'payment_method'       => array( 'type' => 'string' ),
				'payment_method_title' => array( 'type' => 'string' ),
				'transaction_id'       => array( 'type' => 'string' ),
				'date_paid'            => array( 'type' => array( 'string', 'null' ) ),
				'date_completed'       => array( 'type' => array( 'string', 'null' ) ),
				'line_items'           => array(
					'type'  => 'array',
					'items' => $line_item_schema,
				),
				'tax_lines'            => array(
					'type'  => 'array',
					'items' => $tax_line_schema,
				),
				'shipping_lines'       => array(
					'type'  => 'array',
					'items' => $shipping_line_schema,
				),
				'fee_lines'            => array(
					'type'  => 'array',
					'items' => $fee_line_schema,
				),
				'coupon_lines'         => array(
					'type'  => 'array',
					'items' => $coupon_line_schema,
				),
				'refunds'              => array(
					'type'  => 'array',
					'items' => $refund_line_schema,
				),
			),
			'additionalProperties' => false,
		);

		self::register_list( $order_item_schema );
		self::register_get( $order_item_schema );
		self::register_create( $order_item_schema, $billing_schema, $shipping_schema );
		self::register_update_status( $order_item_schema );
		self::register_update( $order_item_schema, $billing_schema, $shipping_schema );
		self::register_create_note();
		self::register_list_notes();
		self::register_delete();
		self::register_list_refunds();
		self::register_create_refund();
	}

	/*
	-------------------------------------------------------------------- *
	 * List / Get
	 * -------------------------------------------------------------------- */

	/**
	 * @param array<string, mixed> $order_item_schema
	 * @return void
	 */
	private static function register_list( array $order_item_schema ) {
		Naming::register_ability(
			'mosmcp/list-orders',
			array(
				'label'               => __( 'List Orders', 'mosmcp-abilities' ),
				'description'         => __( 'Lists WooCommerce orders, filterable by status, customer, date range, or a text search across order number, billing name, email, and phone. Results include customer billing details.', 'mosmcp-abilities' ),
				'category'            => WooCommerce_Abilities_Loader::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'search'   => array(
							'type'        => 'string',
							'description' => __( 'Search term matched against order number, billing name, email, or phone. Use this to find an order by customer name/email when you don\'t already have an order ID or a numeric customer ID (e.g. guest checkouts have no customer ID).', 'mosmcp-abilities' ),
						),
						'status'   => array(
							'type'        => 'array',
							'items'       => array(
								'type' => 'string',
								'enum' => self::STATUSES,
							),
							'description' => __( 'Order statuses to filter by. Omit for any status.', 'mosmcp-abilities' ),
						),
						'customer' => array(
							'type'        => 'integer',
							'description' => __( 'Filter to orders placed by this customer (WordPress user ID). Not useful for guest orders — use search instead.', 'mosmcp-abilities' ),
						),
						'after'    => array(
							'type'        => 'string',
							'description' => __( 'Only include orders created on or after this date (YYYY-MM-DD).', 'mosmcp-abilities' ),
						),
						'before'   => array(
							'type'        => 'string',
							'description' => __( 'Only include orders created on or before this date (YYYY-MM-DD).', 'mosmcp-abilities' ),
						),
						'page'     => array(
							'type'        => 'integer',
							'default'     => 1,
							'description' => __( 'Page number, for pagination.', 'mosmcp-abilities' ),
						),
						'per_page' => array(
							'type'        => 'integer',
							'default'     => 20,
							'description' => __( 'Results per page (1-100).', 'mosmcp-abilities' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => WooCommerce_Response::envelope_schema(
					array(
						'type'                 => 'object',
						'properties'           => array(
							'orders' => array(
								'type'  => 'array',
								'items' => $order_item_schema,
							),
						),
						'additionalProperties' => false,
					),
					true
				),
				'execute_callback'    => array( __CLASS__, 'list_orders' ),
				'permission_callback' => array( WooCommerce_Permissions::class, 'can_read_orders' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
						'open_world'  => false,
					),
					// Stricter than the catalog read abilities: this ability surfaces customer PII.
					'required_cap' => WooCommerce_Permissions::READ_ORDERS,
					'show_in_rest' => false,
				),
			)
		);
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|WP_Error
	 */
	public static function list_orders( $input = array() ) {
		$started_at = microtime( true );
		$input      = is_array( $input ) ? $input : array();

		$pagination = WooCommerce_Validator::validate_pagination( $input );

		$args = array(
			'page'     => $pagination['page'],
			'limit'    => $pagination['per_page'],
			'orderby'  => 'date',
			'order'    => 'DESC',
			'paginate' => true,
			'return'   => 'objects',
		);

		if ( ! empty( $input['search'] ) ) {
			$args['s'] = sanitize_text_field( (string) $input['search'] );
		}

		if ( ! empty( $input['status'] ) && is_array( $input['status'] ) ) {
			$args['status'] = array_values( array_intersect( array_map( 'sanitize_key', $input['status'] ), self::STATUSES ) );
		}

		if ( ! empty( $input['customer'] ) ) {
			$customer_id = WooCommerce_Validator::validate_id( $input['customer'], __( 'customer ID', 'mosmcp-abilities' ) );
			if ( is_wp_error( $customer_id ) ) {
				return $customer_id;
			}
			$args['customer'] = $customer_id;
		}

		$date_range = WooCommerce_Validator::validate_date_range( $input );
		if ( is_wp_error( $date_range ) ) {
			return $date_range;
		}
		if ( '' !== $date_range ) {
			$args['date_created'] = $date_range;
		}

		$result = wc_get_orders( $args );
		$orders = array_map( array( WooCommerce_Helper::class, 'order_summary' ), $result->orders );
		$meta   = WooCommerce_Response::pagination_meta( $result->total, $pagination['page'], $pagination['per_page'] );

		WooCommerce_Helper::log( 'mosmcp/list-orders', 'success', array( 'count' => count( $orders ) ) );

		return WooCommerce_Response::success(
			'mosmcp/list-orders',
			array( 'orders' => $orders ),
			sprintf(
				/* translators: %d: number of orders returned */
				__( '%d order(s) retrieved.', 'mosmcp-abilities' ),
				count( $orders )
			),
			$meta,
			$started_at
		);
	}

	/**
	 * @param array<string, mixed> $order_item_schema
	 * @return void
	 */
	private static function register_get( array $order_item_schema ) {
		Naming::register_ability(
			'mosmcp/get-order',
			array(
				'label'               => __( 'Get Order', 'mosmcp-abilities' ),
				'description'         => __( 'Gets a single WooCommerce order by ID, including line items, billing, shipping, taxes, shipping/fee/coupon lines, and refunds.', 'mosmcp-abilities' ),
				'category'            => WooCommerce_Abilities_Loader::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'id' ),
					'properties'           => array(
						'id' => array(
							'type'        => 'integer',
							'description' => __( 'The order ID (required).', 'mosmcp-abilities' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => WooCommerce_Response::envelope_schema( $order_item_schema ),
				'execute_callback'    => array( __CLASS__, 'get_order' ),
				'permission_callback' => array( WooCommerce_Permissions::class, 'can_read_orders' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
						'open_world'  => false,
					),
					'required_cap' => WooCommerce_Permissions::READ_ORDERS,
					'show_in_rest' => false,
				),
			)
		);
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|WP_Error
	 */
	public static function get_order( $input = array() ) {
		$started_at = microtime( true );
		$input      = is_array( $input ) ? $input : array();

		$order_id = WooCommerce_Validator::validate_id( isset( $input['id'] ) ? $input['id'] : null, __( 'order ID', 'mosmcp-abilities' ) );
		if ( is_wp_error( $order_id ) ) {
			return $order_id;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return WooCommerce_Response::error( 'wcab_order_not_found', __( 'No order was found with that ID.', 'mosmcp-abilities' ) );
		}

		WooCommerce_Helper::log( 'mosmcp/get-order', 'success', array( 'id' => $order_id ) );

		return WooCommerce_Response::success(
			'mosmcp/get-order',
			WooCommerce_Helper::order_summary( $order ),
			__( 'Order retrieved.', 'mosmcp-abilities' ),
			null,
			$started_at
		);
	}

	/*
	-------------------------------------------------------------------- *
	 * Create
	 * -------------------------------------------------------------------- */

	/**
	 * @param array<string, mixed> $order_item_schema
	 * @param array<string, mixed> $billing_schema
	 * @param array<string, mixed> $shipping_schema
	 * @return void
	 */
	private static function register_create( array $order_item_schema, array $billing_schema, array $shipping_schema ) {
		Naming::register_ability(
			'mosmcp/create-order',
			array(
				'label'               => __( 'Create Order', 'mosmcp-abilities' ),
				'description'         => __( 'Creates a new WooCommerce order with one or more line items, and optional billing/shipping, coupons, shipping cost, and fees. Tax and totals are calculated automatically from the line items.', 'mosmcp-abilities' ),
				'category'            => WooCommerce_Abilities_Loader::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'line_items' ),
					'properties'           => array(
						'status'               => array(
							'type'        => 'string',
							'enum'        => self::STATUSES,
							'default'     => 'pending',
							'description' => __( 'Initial order status. Defaults to pending.', 'mosmcp-abilities' ),
						),
						'customer_id'          => array(
							'type'        => 'integer',
							'description' => __( 'The customer (WordPress user) ID. Omit or 0 for a guest order.', 'mosmcp-abilities' ),
						),
						'customer_note'        => array(
							'type'        => 'string',
							'description' => __( 'Note visible to the customer.', 'mosmcp-abilities' ),
						),
						'billing'              => $billing_schema,
						'shipping'             => $shipping_schema,
						'payment_method'       => array(
							'type'        => 'string',
							'description' => __( 'Payment method ID.', 'mosmcp-abilities' ),
						),
						'payment_method_title' => array(
							'type'        => 'string',
							'description' => __( 'Payment method display name.', 'mosmcp-abilities' ),
						),
						'transaction_id'       => array(
							'type'        => 'string',
							'description' => __( 'Payment gateway transaction ID.', 'mosmcp-abilities' ),
						),
						'set_paid'             => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'Whether to immediately mark the order as paid.', 'mosmcp-abilities' ),
						),
						'line_items'           => array(
							'type'        => 'array',
							'description' => __( 'The products in this order (required, at least one).', 'mosmcp-abilities' ),
							'items'       => array(
								'type'                 => 'object',
								'properties'           => array(
									'product_id'   => array(
										'type'        => 'integer',
										'description' => __( 'The product ID. Required unless variation_id is given.', 'mosmcp-abilities' ),
									),
									'variation_id' => array(
										'type'        => 'integer',
										'description' => __( 'A specific variation ID, if this line item is a variable product variation. Takes precedence over product_id.', 'mosmcp-abilities' ),
									),
									'quantity'     => array(
										'type'        => 'integer',
										'default'     => 1,
										'description' => __( 'Quantity. Defaults to 1.', 'mosmcp-abilities' ),
									),
								),
								'additionalProperties' => false,
							),
						),
						'shipping_lines'       => array(
							'type'        => 'array',
							'description' => __( 'Shipping costs to add to the order.', 'mosmcp-abilities' ),
							'items'       => array(
								'type'                 => 'object',
								'properties'           => array(
									'method_id'    => array( 'type' => 'string' ),
									'method_title' => array( 'type' => 'string' ),
									'total'        => array( 'type' => 'string' ),
								),
								'additionalProperties' => false,
							),
						),
						'fee_lines'            => array(
							'type'        => 'array',
							'description' => __( 'Additional fees to add to the order.', 'mosmcp-abilities' ),
							'items'       => array(
								'type'                 => 'object',
								'required'             => array( 'name', 'total' ),
								'properties'           => array(
									'name'       => array( 'type' => 'string' ),
									'total'      => array( 'type' => 'string' ),
									'tax_class'  => array( 'type' => 'string' ),
									'tax_status' => array(
										'type' => 'string',
										'enum' => self::FEE_TAX_STATUSES,
									),
								),
								'additionalProperties' => false,
							),
						),
						'coupon_lines'         => array(
							'type'        => 'array',
							'description' => __( 'Coupon codes to apply. An invalid or expired code fails the whole request rather than being silently skipped.', 'mosmcp-abilities' ),
							'items'       => array(
								'type'                 => 'object',
								'required'             => array( 'code' ),
								'properties'           => array(
									'code' => array( 'type' => 'string' ),
								),
								'additionalProperties' => false,
							),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => WooCommerce_Response::envelope_schema( $order_item_schema ),
				'execute_callback'    => array( __CLASS__, 'create_order' ),
				'permission_callback' => array( WooCommerce_Permissions::class, 'can_create_orders' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
						'open_world'  => false,
					),
					'required_cap' => WooCommerce_Permissions::CREATE_ORDERS,
					'show_in_rest' => false,
				),
			)
		);
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|WP_Error
	 */
	public static function create_order( $input = array() ) {
		$started_at = microtime( true );
		$input      = is_array( $input ) ? $input : array();

		$line_items = isset( $input['line_items'] ) && is_array( $input['line_items'] ) ? $input['line_items'] : array();
		if ( empty( $line_items ) ) {
			return WooCommerce_Response::error( 'wcab_missing_line_items', __( 'At least one line item is required.', 'mosmcp-abilities' ) );
		}

		$status = WooCommerce_Validator::validate_enum( isset( $input['status'] ) ? $input['status'] : null, self::STATUSES, __( 'status', 'mosmcp-abilities' ), 'pending' );
		if ( is_wp_error( $status ) ) {
			return $status;
		}

		$order = wc_create_order(
			array(
				'status'      => $status,
				'customer_id' => isset( $input['customer_id'] ) ? absint( $input['customer_id'] ) : 0,
				'created_via' => 'mosmcp-woocommerce',
			)
		);
		if ( is_wp_error( $order ) ) {
			return WooCommerce_Response::error( 'wcab_order_create_failed', $order->get_error_message() );
		}

		$items_result = self::add_line_items( $order, $line_items );
		if ( is_wp_error( $items_result ) ) {
			$order->delete( true );
			return $items_result;
		}

		if ( ! empty( $input['shipping_lines'] ) && is_array( $input['shipping_lines'] ) ) {
			self::add_shipping_lines( $order, $input['shipping_lines'] );
		}
		if ( ! empty( $input['fee_lines'] ) && is_array( $input['fee_lines'] ) ) {
			self::add_fee_lines( $order, $input['fee_lines'] );
		}
		if ( ! empty( $input['coupon_lines'] ) && is_array( $input['coupon_lines'] ) ) {
			$coupon_result = self::add_coupon_lines( $order, $input['coupon_lines'] );
			if ( is_wp_error( $coupon_result ) ) {
				$order->delete( true );
				return $coupon_result;
			}
		}

		if ( isset( $input['billing'] ) && is_array( $input['billing'] ) ) {
			self::apply_address( $order, 'billing', $input['billing'] );
		}
		if ( isset( $input['shipping'] ) && is_array( $input['shipping'] ) ) {
			self::apply_address( $order, 'shipping', $input['shipping'] );
		}
		self::apply_order_meta_fields( $order, $input );

		$order->calculate_totals();

		if ( ! empty( $input['set_paid'] ) ) {
			$order->payment_complete( isset( $input['transaction_id'] ) ? sanitize_text_field( (string) $input['transaction_id'] ) : '' );
		}

		$order->save();

		WooCommerce_Helper::log( 'mosmcp/create-order', 'success', array( 'id' => $order->get_id() ) );

		return WooCommerce_Response::success(
			'mosmcp/create-order',
			WooCommerce_Helper::order_summary( $order ),
			__( 'Order created.', 'mosmcp-abilities' ),
			null,
			$started_at
		);
	}

	/**
	 * @param \WC_Order         $order
	 * @param array<int, mixed> $line_items
	 * @return true|WP_Error
	 */
	private static function add_line_items( $order, array $line_items ) {
		foreach ( $line_items as $line_item ) {
			if ( ! is_array( $line_item ) ) {
				continue;
			}

			$variation_id = isset( $line_item['variation_id'] ) ? absint( $line_item['variation_id'] ) : 0;
			$product_id   = isset( $line_item['product_id'] ) ? absint( $line_item['product_id'] ) : 0;
			$quantity     = isset( $line_item['quantity'] ) ? max( 1, absint( $line_item['quantity'] ) ) : 1;

			$product = wc_get_product( $variation_id > 0 ? $variation_id : $product_id );
			if ( ! $product ) {
				return WooCommerce_Response::error( 'wcab_line_item_product_not_found', __( 'No product or variation was found for one of the line items.', 'mosmcp-abilities' ) );
			}

			$order->add_product( $product, $quantity );
		}

		return true;
	}

	/**
	 * @param \WC_Order         $order
	 * @param array<int, mixed> $shipping_lines
	 * @return void
	 */
	private static function add_shipping_lines( $order, array $shipping_lines ) {
		foreach ( $shipping_lines as $shipping_line ) {
			if ( ! is_array( $shipping_line ) ) {
				continue;
			}

			$item = new WC_Order_Item_Shipping();
			$item->set_method_title( isset( $shipping_line['method_title'] ) ? sanitize_text_field( (string) $shipping_line['method_title'] ) : '' );
			$item->set_method_id( isset( $shipping_line['method_id'] ) ? sanitize_text_field( (string) $shipping_line['method_id'] ) : '' );
			$item->set_total( isset( $shipping_line['total'] ) ? wc_format_decimal( $shipping_line['total'] ) : 0 );
			$order->add_item( $item );
		}
	}

	/**
	 * @param \WC_Order         $order
	 * @param array<int, mixed> $fee_lines
	 * @return void
	 */
	private static function add_fee_lines( $order, array $fee_lines ) {
		foreach ( $fee_lines as $fee_line ) {
			if ( ! is_array( $fee_line ) || empty( $fee_line['name'] ) ) {
				continue;
			}

			$item = new WC_Order_Item_Fee();
			$item->set_name( sanitize_text_field( (string) $fee_line['name'] ) );
			$item->set_total( isset( $fee_line['total'] ) ? wc_format_decimal( $fee_line['total'] ) : 0 );
			if ( isset( $fee_line['tax_class'] ) ) {
				$item->set_tax_class( sanitize_text_field( (string) $fee_line['tax_class'] ) );
			}
			if ( isset( $fee_line['tax_status'] ) && in_array( $fee_line['tax_status'], self::FEE_TAX_STATUSES, true ) ) {
				$item->set_tax_status( $fee_line['tax_status'] );
			}
			$order->add_item( $item );
		}
	}

	/**
	 * @param \WC_Order         $order
	 * @param array<int, mixed> $coupon_lines
	 * @return true|WP_Error
	 */
	private static function add_coupon_lines( $order, array $coupon_lines ) {
		foreach ( $coupon_lines as $coupon_line ) {
			$code = is_array( $coupon_line ) && isset( $coupon_line['code'] ) ? sanitize_text_field( (string) $coupon_line['code'] ) : '';
			if ( '' === $code ) {
				continue;
			}

			$applied = $order->apply_coupon( $code );
			if ( is_wp_error( $applied ) ) {
				return WooCommerce_Response::error( 'wcab_invalid_coupon', $applied->get_error_message() );
			}
		}

		return true;
	}

	/**
	 * @param \WC_Order            $order
	 * @param array<string, mixed> $input
	 * @return void
	 */
	private static function apply_order_meta_fields( $order, array $input ) {
		if ( isset( $input['customer_note'] ) ) {
			$order->set_customer_note( sanitize_textarea_field( (string) $input['customer_note'] ) );
		}
		if ( isset( $input['payment_method'] ) ) {
			$order->set_payment_method( sanitize_text_field( (string) $input['payment_method'] ) );
		}
		if ( isset( $input['payment_method_title'] ) ) {
			$order->set_payment_method_title( sanitize_text_field( (string) $input['payment_method_title'] ) );
		}
		if ( isset( $input['transaction_id'] ) ) {
			$order->set_transaction_id( sanitize_text_field( (string) $input['transaction_id'] ) );
		}
	}

	/**
	 * @param \WC_Order            $order
	 * @param string               $type 'billing' or 'shipping'.
	 * @param array<string, mixed> $address
	 * @return void
	 */
	private static function apply_address( $order, $type, array $address ) {
		foreach ( array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country' ) as $field ) {
			if ( ! isset( $address[ $field ] ) ) {
				continue;
			}
			$method = 'set_' . $type . '_' . $field;
			if ( method_exists( $order, $method ) ) {
				$order->$method( sanitize_text_field( (string) $address[ $field ] ) );
			}
		}

		if ( 'billing' === $type ) {
			if ( isset( $address['email'] ) ) {
				$order->set_billing_email( sanitize_email( (string) $address['email'] ) );
			}
			if ( isset( $address['phone'] ) ) {
				$order->set_billing_phone( sanitize_text_field( (string) $address['phone'] ) );
			}
		}
	}

	/*
	-------------------------------------------------------------------- *
	 * Update status / generic update
	 * -------------------------------------------------------------------- */

	/**
	 * @param array<string, mixed> $order_item_schema
	 * @return void
	 */
	private static function register_update_status( array $order_item_schema ) {
		Naming::register_ability(
			'mosmcp/update-order-status',
			array(
				'label'               => __( 'Update Order Status', 'mosmcp-abilities' ),
				'description'         => __( 'Transitions a WooCommerce order to a new status (e.g. processing to completed, or to cancelled/refunded).', 'mosmcp-abilities' ),
				'category'            => WooCommerce_Abilities_Loader::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'id', 'status' ),
					'properties'           => array(
						'id'     => array(
							'type'        => 'integer',
							'description' => __( 'The order ID (required).', 'mosmcp-abilities' ),
						),
						'status' => array(
							'type'        => 'string',
							'enum'        => self::STATUSES,
							'description' => __( 'The new order status (required).', 'mosmcp-abilities' ),
						),
						'note'   => array(
							'type'        => 'string',
							'description' => __( 'Optional private note to attach to the status change.', 'mosmcp-abilities' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => WooCommerce_Response::envelope_schema( $order_item_schema ),
				'execute_callback'    => array( __CLASS__, 'update_order_status' ),
				'permission_callback' => array( WooCommerce_Permissions::class, 'can_edit_orders' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => true,
						'open_world'  => false,
					),
					'required_cap' => WooCommerce_Permissions::EDIT_ORDERS,
					'show_in_rest' => false,
				),
			)
		);
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|WP_Error
	 */
	public static function update_order_status( $input = array() ) {
		$started_at = microtime( true );
		$input      = is_array( $input ) ? $input : array();

		$order_id = WooCommerce_Validator::validate_id( isset( $input['id'] ) ? $input['id'] : null, __( 'order ID', 'mosmcp-abilities' ) );
		if ( is_wp_error( $order_id ) ) {
			return $order_id;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return WooCommerce_Response::error( 'wcab_order_not_found', __( 'No order was found with that ID.', 'mosmcp-abilities' ) );
		}
		if ( ! WooCommerce_Permissions::can_edit_order( $order_id ) ) {
			return WooCommerce_Response::error( 'wcab_cannot_edit', __( 'You are not allowed to edit this order.', 'mosmcp-abilities' ) );
		}

		$status = WooCommerce_Validator::validate_enum( isset( $input['status'] ) ? $input['status'] : null, self::STATUSES, __( 'status', 'mosmcp-abilities' ) );
		if ( is_wp_error( $status ) ) {
			return $status;
		}

		$note = isset( $input['note'] ) ? sanitize_text_field( (string) $input['note'] ) : '';

		$order->update_status( $status, $note, true );

		WooCommerce_Helper::log(
			'mosmcp/update-order-status',
			'success',
			array(
				'id'     => $order_id,
				'status' => $status,
			)
		);

		return WooCommerce_Response::success(
			'mosmcp/update-order-status',
			WooCommerce_Helper::order_summary( $order ),
			__( 'Order status updated.', 'mosmcp-abilities' ),
			null,
			$started_at
		);
	}

	/**
	 * @param array<string, mixed> $order_item_schema
	 * @param array<string, mixed> $billing_schema
	 * @param array<string, mixed> $shipping_schema
	 * @return void
	 */
	private static function register_update( array $order_item_schema, array $billing_schema, array $shipping_schema ) {
		Naming::register_ability(
			'mosmcp/update-order',
			array(
				'label'               => __( 'Update Order', 'mosmcp-abilities' ),
				'description'         => __( "Updates an order's billing/shipping address, customer note, payment details, or status. Provide the order ID and at least one field to change. For status transitions with a note, prefer update-order-status.", 'mosmcp-abilities' ),
				'category'            => WooCommerce_Abilities_Loader::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'id' ),
					'properties'           => array(
						'id'                   => array(
							'type'        => 'integer',
							'description' => __( 'The order ID to update (required).', 'mosmcp-abilities' ),
						),
						'status'               => array(
							'type'        => 'string',
							'enum'        => self::STATUSES,
							'description' => __( 'New order status.', 'mosmcp-abilities' ),
						),
						'customer_note'        => array(
							'type'        => 'string',
							'description' => __( 'New customer-visible note.', 'mosmcp-abilities' ),
						),
						'billing'              => $billing_schema,
						'shipping'             => $shipping_schema,
						'payment_method'       => array(
							'type'        => 'string',
							'description' => __( 'New payment method ID.', 'mosmcp-abilities' ),
						),
						'payment_method_title' => array(
							'type'        => 'string',
							'description' => __( 'New payment method display name.', 'mosmcp-abilities' ),
						),
						'transaction_id'       => array(
							'type'        => 'string',
							'description' => __( 'New payment gateway transaction ID.', 'mosmcp-abilities' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => WooCommerce_Response::envelope_schema( $order_item_schema ),
				'execute_callback'    => array( __CLASS__, 'update_order' ),
				'permission_callback' => array( WooCommerce_Permissions::class, 'can_edit_orders' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => true,
						'open_world'  => false,
					),
					'required_cap' => WooCommerce_Permissions::EDIT_ORDERS,
					'show_in_rest' => false,
				),
			)
		);
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|WP_Error
	 */
	public static function update_order( $input = array() ) {
		$started_at = microtime( true );
		$input      = is_array( $input ) ? $input : array();

		$order_id = WooCommerce_Validator::validate_id( isset( $input['id'] ) ? $input['id'] : null, __( 'order ID', 'mosmcp-abilities' ) );
		if ( is_wp_error( $order_id ) ) {
			return $order_id;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return WooCommerce_Response::error( 'wcab_order_not_found', __( 'No order was found with that ID.', 'mosmcp-abilities' ) );
		}
		if ( ! WooCommerce_Permissions::can_edit_order( $order_id ) ) {
			return WooCommerce_Response::error( 'wcab_cannot_edit', __( 'You are not allowed to edit this order.', 'mosmcp-abilities' ) );
		}

		$fields = $input;
		unset( $fields['id'] );
		if ( empty( $fields ) ) {
			return WooCommerce_Response::error( 'wcab_nothing_to_update', __( 'Provide at least one field to update.', 'mosmcp-abilities' ) );
		}

		if ( isset( $input['status'] ) ) {
			$status = WooCommerce_Validator::validate_enum( $input['status'], self::STATUSES, __( 'status', 'mosmcp-abilities' ) );
			if ( is_wp_error( $status ) ) {
				return $status;
			}
			$order->set_status( $status );
		}
		if ( isset( $input['billing'] ) && is_array( $input['billing'] ) ) {
			self::apply_address( $order, 'billing', $input['billing'] );
		}
		if ( isset( $input['shipping'] ) && is_array( $input['shipping'] ) ) {
			self::apply_address( $order, 'shipping', $input['shipping'] );
		}
		self::apply_order_meta_fields( $order, $input );

		$order->save();

		WooCommerce_Helper::log( 'mosmcp/update-order', 'success', array( 'id' => $order_id ) );

		return WooCommerce_Response::success(
			'mosmcp/update-order',
			WooCommerce_Helper::order_summary( $order ),
			__( 'Order updated.', 'mosmcp-abilities' ),
			null,
			$started_at
		);
	}

	/*
	-------------------------------------------------------------------- *
	 * Notes
	 * -------------------------------------------------------------------- */

	/**
	 * @return void
	 */
	private static function register_create_note() {
		Naming::register_ability(
			'mosmcp/create-order-note',
			array(
				'label'               => __( 'Add Order Note', 'mosmcp-abilities' ),
				'description'         => __( 'Adds a note to a WooCommerce order, either private (staff-only) or customer-visible.', 'mosmcp-abilities' ),
				'category'            => WooCommerce_Abilities_Loader::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'id', 'note' ),
					'properties'           => array(
						'id'            => array(
							'type'        => 'integer',
							'description' => __( 'The order ID (required).', 'mosmcp-abilities' ),
						),
						'note'          => array(
							'type'        => 'string',
							'description' => __( 'The note text (required).', 'mosmcp-abilities' ),
						),
						'customer_note' => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'Whether the note is visible to the customer.', 'mosmcp-abilities' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => WooCommerce_Response::envelope_schema(
					array(
						'type'                 => 'object',
						'required'             => array( 'note_id', 'order_id' ),
						'properties'           => array(
							'note_id'  => array( 'type' => 'integer' ),
							'order_id' => array( 'type' => 'integer' ),
						),
						'additionalProperties' => false,
					)
				),
				'execute_callback'    => array( __CLASS__, 'create_order_note' ),
				'permission_callback' => array( WooCommerce_Permissions::class, 'can_edit_orders' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
						'open_world'  => false,
					),
					'required_cap' => WooCommerce_Permissions::EDIT_ORDERS,
					'show_in_rest' => false,
				),
			)
		);
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|WP_Error
	 */
	public static function create_order_note( $input = array() ) {
		$started_at = microtime( true );
		$input      = is_array( $input ) ? $input : array();

		$order_id = WooCommerce_Validator::validate_id( isset( $input['id'] ) ? $input['id'] : null, __( 'order ID', 'mosmcp-abilities' ) );
		if ( is_wp_error( $order_id ) ) {
			return $order_id;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return WooCommerce_Response::error( 'wcab_order_not_found', __( 'No order was found with that ID.', 'mosmcp-abilities' ) );
		}
		if ( ! WooCommerce_Permissions::can_edit_order( $order_id ) ) {
			return WooCommerce_Response::error( 'wcab_cannot_edit', __( 'You are not allowed to edit this order.', 'mosmcp-abilities' ) );
		}

		$note = isset( $input['note'] ) ? sanitize_text_field( (string) $input['note'] ) : '';
		if ( '' === $note ) {
			return WooCommerce_Response::error( 'wcab_missing_note', __( 'Note text is required.', 'mosmcp-abilities' ) );
		}

		$note_id = $order->add_order_note( $note, ! empty( $input['customer_note'] ), true );
		if ( ! $note_id ) {
			return WooCommerce_Response::error( 'wcab_note_failed', __( 'The note could not be added.', 'mosmcp-abilities' ) );
		}

		WooCommerce_Helper::log(
			'mosmcp/create-order-note',
			'success',
			array(
				'id'      => $order_id,
				'note_id' => $note_id,
			)
		);

		return WooCommerce_Response::success(
			'mosmcp/create-order-note',
			array(
				'note_id'  => (int) $note_id,
				'order_id' => $order_id,
			),
			__( 'Order note added.', 'mosmcp-abilities' ),
			null,
			$started_at
		);
	}

	/**
	 * @return void
	 */
	private static function register_list_notes() {
		Naming::register_ability(
			'mosmcp/list-order-notes',
			array(
				'label'               => __( 'List Order Notes', 'mosmcp-abilities' ),
				'description'         => __( 'Lists the notes attached to an order — private (staff-only) and/or customer-visible.', 'mosmcp-abilities' ),
				'category'            => WooCommerce_Abilities_Loader::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'id' ),
					'properties'           => array(
						'id'   => array(
							'type'        => 'integer',
							'description' => __( 'The order ID (required).', 'mosmcp-abilities' ),
						),
						'type' => array(
							'type'        => 'string',
							'enum'        => self::NOTE_TYPES,
							'default'     => 'any',
							'description' => __( 'Limit to customer or internal notes. Defaults to any.', 'mosmcp-abilities' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => WooCommerce_Response::envelope_schema(
					array(
						'type'                 => 'object',
						'properties'           => array(
							'notes' => array(
								'type'  => 'array',
								'items' => array(
									'type'                 => 'object',
									'properties'           => array(
										'id'            => array( 'type' => 'integer' ),
										'author'        => array( 'type' => 'string' ),
										'date_created'  => array( 'type' => array( 'string', 'null' ) ),
										'note'          => array( 'type' => 'string' ),
										'customer_note' => array( 'type' => 'boolean' ),
										'added_by_user' => array( 'type' => 'boolean' ),
									),
									'additionalProperties' => false,
								),
							),
						),
						'additionalProperties' => false,
					)
				),
				'execute_callback'    => array( __CLASS__, 'list_order_notes' ),
				'permission_callback' => array( WooCommerce_Permissions::class, 'can_read_orders' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
						'open_world'  => false,
					),
					'required_cap' => WooCommerce_Permissions::READ_ORDERS,
					'show_in_rest' => false,
				),
			)
		);
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|WP_Error
	 */
	public static function list_order_notes( $input = array() ) {
		$started_at = microtime( true );
		$input      = is_array( $input ) ? $input : array();

		$order_id = WooCommerce_Validator::validate_id( isset( $input['id'] ) ? $input['id'] : null, __( 'order ID', 'mosmcp-abilities' ) );
		if ( is_wp_error( $order_id ) ) {
			return $order_id;
		}

		if ( ! wc_get_order( $order_id ) ) {
			return WooCommerce_Response::error( 'wcab_order_not_found', __( 'No order was found with that ID.', 'mosmcp-abilities' ) );
		}

		$type = WooCommerce_Validator::validate_enum( isset( $input['type'] ) ? $input['type'] : null, self::NOTE_TYPES, __( 'type', 'mosmcp-abilities' ), 'any' );
		if ( is_wp_error( $type ) ) {
			return $type;
		}

		$raw_notes = wc_get_order_notes(
			array(
				'order_id' => $order_id,
				'type'     => $type,
			)
		);
		$notes     = array_map( array( __CLASS__, 'note_summary' ), $raw_notes );

		WooCommerce_Helper::log(
			'mosmcp/list-order-notes',
			'success',
			array(
				'id'    => $order_id,
				'count' => count( $notes ),
			)
		);

		return WooCommerce_Response::success(
			'mosmcp/list-order-notes',
			array( 'notes' => $notes ),
			sprintf(
				/* translators: %d: number of notes returned */
				__( '%d note(s) retrieved.', 'mosmcp-abilities' ),
				count( $notes )
			),
			null,
			$started_at
		);
	}

	/**
	 * Shape not independently verified against a live store — see the class
	 * docblock's known-limitations note.
	 *
	 * @param object $note A raw note object from wc_get_order_notes().
	 * @return array<string, mixed>
	 */
	private static function note_summary( $note ) {
		$date_created = isset( $note->date_created ) ? $note->date_created : null;

		return array(
			'id'            => isset( $note->id ) ? (int) $note->id : 0,
			'author'        => ( ! empty( $note->added_by_user ) && isset( $note->author ) )
				? $note->author
				: __( 'WooCommerce', 'mosmcp-abilities' ),
			'date_created'  => ( $date_created && is_object( $date_created ) && method_exists( $date_created, 'date' ) )
				? $date_created->date( 'c' )
				: null,
			'note'          => isset( $note->content ) ? $note->content : '',
			'customer_note' => ! empty( $note->customer_note ),
			'added_by_user' => ! empty( $note->added_by_user ),
		);
	}

	/*
	-------------------------------------------------------------------- *
	 * Delete
	 * -------------------------------------------------------------------- */

	/**
	 * @return void
	 */
	private static function register_delete() {
		Naming::register_ability(
			'mosmcp/delete-order',
			array(
				'label'               => __( 'Delete Order', 'mosmcp-abilities' ),
				'description'         => __( 'Deletes a WooCommerce order. By default this moves it to the trash; pass force to delete it permanently, bypassing the trash.', 'mosmcp-abilities' ),
				'category'            => WooCommerce_Abilities_Loader::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'id' ),
					'properties'           => array(
						'id'    => array(
							'type'        => 'integer',
							'description' => __( 'The order ID to delete (required).', 'mosmcp-abilities' ),
						),
						'force' => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'Permanently delete instead of moving to trash. Defaults to false.', 'mosmcp-abilities' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => WooCommerce_Response::envelope_schema(
					array(
						'type'                 => 'object',
						'required'             => array( 'id', 'status' ),
						'properties'           => array(
							'id'     => array( 'type' => 'integer' ),
							'status' => array( 'type' => 'string' ),
						),
						'additionalProperties' => false,
					)
				),
				'execute_callback'    => array( __CLASS__, 'delete_order' ),
				'permission_callback' => array( WooCommerce_Permissions::class, 'can_delete_orders' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => true,
						'open_world'  => false,
					),
					'required_cap' => WooCommerce_Permissions::DELETE_ORDERS,
					'show_in_rest' => false,
				),
			)
		);
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|WP_Error
	 */
	public static function delete_order( $input = array() ) {
		$started_at = microtime( true );
		$input      = is_array( $input ) ? $input : array();

		$order_id = WooCommerce_Validator::validate_id( isset( $input['id'] ) ? $input['id'] : null, __( 'order ID', 'mosmcp-abilities' ) );
		if ( is_wp_error( $order_id ) ) {
			return $order_id;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return WooCommerce_Response::error( 'wcab_order_not_found', __( 'No order was found with that ID.', 'mosmcp-abilities' ) );
		}
		if ( ! WooCommerce_Permissions::can_delete_order( $order_id ) ) {
			return WooCommerce_Response::error( 'wcab_cannot_delete', __( 'You are not allowed to delete this order.', 'mosmcp-abilities' ) );
		}

		$force  = ! empty( $input['force'] );
		$result = $order->delete( $force );

		if ( ! $result ) {
			return WooCommerce_Response::error( 'wcab_order_delete_failed', __( 'The order could not be deleted.', 'mosmcp-abilities' ) );
		}

		WooCommerce_Helper::log(
			'mosmcp/delete-order',
			'success',
			array(
				'id'    => $order_id,
				'force' => $force,
			)
		);

		return WooCommerce_Response::success(
			'mosmcp/delete-order',
			array(
				'id'     => $order_id,
				'status' => $force ? 'deleted' : 'trashed',
			),
			$force
				? __( 'Order permanently deleted.', 'mosmcp-abilities' )
				: __( 'Order moved to trash.', 'mosmcp-abilities' ),
			null,
			$started_at
		);
	}

	/*
	-------------------------------------------------------------------- *
	 * Refunds
	 * -------------------------------------------------------------------- */

	/**
	 * @return void
	 */
	private static function register_list_refunds() {
		Naming::register_ability(
			'mosmcp/list-order-refunds',
			array(
				'label'               => __( 'List Order Refunds', 'mosmcp-abilities' ),
				'description'         => __( 'Lists the refunds issued for an order, including refunded line items.', 'mosmcp-abilities' ),
				'category'            => WooCommerce_Abilities_Loader::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'id' ),
					'properties'           => array(
						'id' => array(
							'type'        => 'integer',
							'description' => __( 'The order ID (required).', 'mosmcp-abilities' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => WooCommerce_Response::envelope_schema(
					array(
						'type'                 => 'object',
						'properties'           => array(
							'refunds' => array(
								'type'  => 'array',
								'items' => self::refund_detail_schema(),
							),
						),
						'additionalProperties' => false,
					)
				),
				'execute_callback'    => array( __CLASS__, 'list_order_refunds' ),
				'permission_callback' => array( WooCommerce_Permissions::class, 'can_read_orders' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
						'open_world'  => false,
					),
					'required_cap' => WooCommerce_Permissions::READ_ORDERS,
					'show_in_rest' => false,
				),
			)
		);
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|WP_Error
	 */
	public static function list_order_refunds( $input = array() ) {
		$started_at = microtime( true );
		$input      = is_array( $input ) ? $input : array();

		$order_id = WooCommerce_Validator::validate_id( isset( $input['id'] ) ? $input['id'] : null, __( 'order ID', 'mosmcp-abilities' ) );
		if ( is_wp_error( $order_id ) ) {
			return $order_id;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return WooCommerce_Response::error( 'wcab_order_not_found', __( 'No order was found with that ID.', 'mosmcp-abilities' ) );
		}

		$refunds = array_map( array( __CLASS__, 'refund_detail' ), $order->get_refunds() );

		WooCommerce_Helper::log(
			'mosmcp/list-order-refunds',
			'success',
			array(
				'id'    => $order_id,
				'count' => count( $refunds ),
			)
		);

		return WooCommerce_Response::success(
			'mosmcp/list-order-refunds',
			array( 'refunds' => $refunds ),
			sprintf(
				/* translators: %d: number of refunds returned */
				__( '%d refund(s) retrieved.', 'mosmcp-abilities' ),
				count( $refunds )
			),
			null,
			$started_at
		);
	}

	/**
	 * @return void
	 */
	private static function register_create_refund() {
		Naming::register_ability(
			'mosmcp/create-order-refund',
			array(
				'label'               => __( 'Create Order Refund', 'mosmcp-abilities' ),
				'description'         => __( "Creates a full or partial refund for an order. Provide 'amount' for a simple refund, or 'line_items' (order line item IDs with a refund_total each) for an itemized refund — at least one is required. Unlike the WooCommerce REST API, api_refund defaults to false here: set it to true explicitly to also call the payment gateway's refund API and actually return funds; otherwise this only records the refund in WooCommerce without moving money. Stock is not automatically restocked.", 'mosmcp-abilities' ),
				'category'            => WooCommerce_Abilities_Loader::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'id' ),
					'properties'           => array(
						'id'         => array(
							'type'        => 'integer',
							'description' => __( 'The order ID (required).', 'mosmcp-abilities' ),
						),
						'amount'     => array(
							'type'        => 'string',
							'description' => __( 'Total refund amount. Takes precedence over line_items totals when both are given.', 'mosmcp-abilities' ),
						),
						'reason'     => array(
							'type'        => 'string',
							'description' => __( 'Refund reason.', 'mosmcp-abilities' ),
						),
						'line_items' => array(
							'type'        => 'array',
							'description' => __( 'Itemized refund lines. Each id is an order line item ID (from get-order\'s line_items[].id, not a product ID).', 'mosmcp-abilities' ),
							'items'       => array(
								'type'                 => 'object',
								'required'             => array( 'id', 'refund_total' ),
								'properties'           => array(
									'id'           => array( 'type' => 'integer' ),
									'refund_total' => array( 'type' => 'number' ),
								),
								'additionalProperties' => false,
							),
						),
						'api_refund' => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'Whether to also call the payment gateway to actually process the refund. Defaults to false (records the refund only).', 'mosmcp-abilities' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => WooCommerce_Response::envelope_schema( self::refund_detail_schema() ),
				'execute_callback'    => array( __CLASS__, 'create_order_refund' ),
				'permission_callback' => array( WooCommerce_Permissions::class, 'can_edit_orders' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => false,
						'open_world'  => false,
					),
					'required_cap' => WooCommerce_Permissions::EDIT_ORDERS,
					'show_in_rest' => false,
				),
			)
		);
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|WP_Error
	 */
	public static function create_order_refund( $input = array() ) {
		$started_at = microtime( true );
		$input      = is_array( $input ) ? $input : array();

		$order_id = WooCommerce_Validator::validate_id( isset( $input['id'] ) ? $input['id'] : null, __( 'order ID', 'mosmcp-abilities' ) );
		if ( is_wp_error( $order_id ) ) {
			return $order_id;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return WooCommerce_Response::error( 'wcab_order_not_found', __( 'No order was found with that ID.', 'mosmcp-abilities' ) );
		}
		if ( ! WooCommerce_Permissions::can_edit_order( $order_id ) ) {
			return WooCommerce_Response::error( 'wcab_cannot_edit', __( 'You are not allowed to refund this order.', 'mosmcp-abilities' ) );
		}

		$raw_line_items = isset( $input['line_items'] ) && is_array( $input['line_items'] ) ? $input['line_items'] : array();
		$line_items_arg = array();
		foreach ( $raw_line_items as $raw_line_item ) {
			if ( ! is_array( $raw_line_item ) || ! isset( $raw_line_item['id'] ) ) {
				continue;
			}
			$item_id                    = absint( $raw_line_item['id'] );
			$line_items_arg[ $item_id ] = array(
				'refund_total' => isset( $raw_line_item['refund_total'] ) ? wc_format_decimal( $raw_line_item['refund_total'] ) : 0,
			);
		}

		$has_amount     = isset( $input['amount'] ) && '' !== $input['amount'];
		$has_line_items = ! empty( $line_items_arg );

		if ( ! $has_amount && ! $has_line_items ) {
			return WooCommerce_Response::error( 'wcab_missing_refund_amount', __( 'Provide either amount or line_items to refund.', 'mosmcp-abilities' ) );
		}

		$args = array(
			'order_id'       => $order_id,
			'reason'         => isset( $input['reason'] ) ? sanitize_text_field( (string) $input['reason'] ) : '',
			'refund_payment' => ! empty( $input['api_refund'] ),
			'line_items'     => $line_items_arg,
		);
		if ( $has_amount ) {
			$args['amount'] = wc_format_decimal( $input['amount'] );
		}

		$refund = wc_create_refund( $args );
		if ( is_wp_error( $refund ) ) {
			return WooCommerce_Response::error( 'wcab_refund_failed', $refund->get_error_message() );
		}

		WooCommerce_Helper::log(
			'mosmcp/create-order-refund',
			'success',
			array(
				'order_id'  => $order_id,
				'refund_id' => $refund->get_id(),
			)
		);

		return WooCommerce_Response::success(
			'mosmcp/create-order-refund',
			self::refund_detail( $refund ),
			__( 'Refund created.', 'mosmcp-abilities' ),
			null,
			$started_at
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function refund_detail_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id'           => array( 'type' => 'integer' ),
				'date_created' => array( 'type' => array( 'string', 'null' ) ),
				'amount'       => array( 'type' => 'string' ),
				'reason'       => array( 'type' => 'string' ),
				'refunded_by'  => array( 'type' => 'integer' ),
				'line_items'   => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'properties'           => array(
							'id'           => array( 'type' => 'integer' ),
							'name'         => array( 'type' => 'string' ),
							'parent_name'  => array( 'type' => array( 'string', 'null' ) ),
							'product_id'   => array( 'type' => 'integer' ),
							'variation_id' => array( 'type' => 'integer' ),
							'quantity'     => array( 'type' => 'integer' ),
							'tax_class'    => array( 'type' => 'string' ),
							'subtotal'     => array( 'type' => 'string' ),
							'subtotal_tax' => array( 'type' => 'string' ),
							'total'        => array( 'type' => 'string' ),
							'total_tax'    => array( 'type' => 'string' ),
							'sku'          => array( 'type' => 'string' ),
							'price'        => array( 'type' => 'string' ),
						),
						'additionalProperties' => false,
					),
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * @param \WC_Order_Refund $refund
	 * @return array<string, mixed>
	 */
	private static function refund_detail( $refund ) {
		$date_created = $refund->get_date_created();

		return array(
			'id'           => $refund->get_id(),
			'date_created' => $date_created ? $date_created->date( 'c' ) : null,
			'amount'       => WooCommerce_Helper::money( $refund->get_amount() ),
			'reason'       => $refund->get_reason(),
			'refunded_by'  => (int) $refund->get_refunded_by(),
			'line_items'   => array_map( array( WooCommerce_Helper::class, 'line_item_summary' ), array_values( $refund->get_items( 'line_item' ) ) ),
		);
	}
}
