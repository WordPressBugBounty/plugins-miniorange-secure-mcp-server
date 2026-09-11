<?php
/**
 * WooCommerce Reports abilities: sales, top sellers, orders, products, customers, coupon usage.
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

use WP_Error;

/**
 * Class Reports_Abilities
 *
 * Registers mosmcp/report-sales, report-top-sellers, report-orders,
 * report-products, report-customers, and report-coupon-usage. Field names
 * deliberately mirror the WooCommerce REST API v3 Reports endpoints
 * (https://developer.woocommerce.com/docs/apis/rest-api/v3/reports/) where
 * those endpoints exist, computed directly from wc_get_orders()/wc_get_products()
 * rather than WooCommerce's internal legacy report classes or the Analytics
 * package, so behavior is consistent across WooCommerce versions.
 *
 * Two deliberate departures from the official schema, both documented on
 * the relevant ability:
 * - report-orders/report-products/report-customers officially return
 *   all-time totals with no date/role filtering. Optional filters are added
 *   here as a bonus since an all-time-only breakdown is of limited use to
 *   an AI agent; omitting the filter reproduces the official all-time behavior.
 * - report-coupon-usage does not match the official "coupons/totals"
 *   endpoint (which only counts coupons per discount type). It instead
 *   reports actual usage — times used and amount discounted per coupon
 *   code — matching what "usage statistics" actually implies.
 */
class Reports_Abilities {

	/**
	 * Order statuses counted as sales/usage by default.
	 */
	const DEFAULT_STATUSES = array( 'completed', 'processing' );

	/**
	 * Periods report-sales/report-top-sellers accept as a shorthand for an
	 * explicit date_min/date_max range.
	 */
	const PERIODS = array( 'week', 'month', 'last_month', 'year' );

	/**
	 * Registers every Reports ability. Called from {@see WooCommerce_Abilities_Loader}.
	 *
	 * @return void
	 */
	public static function register() {
		self::register_sales();
		self::register_top_sellers();
		self::register_orders();
		self::register_products();
		self::register_customers();
		self::register_coupon_usage();
	}

	/**
	 * Shared date-range input properties for report-sales and report-top-sellers.
	 *
	 * @return array<string, mixed>
	 */
	private static function date_range_properties() {
		return array(
			'period'   => array(
				'type'        => 'string',
				'enum'        => self::PERIODS,
				'description' => __( 'A shorthand date range. Alternative to date_min/date_max.', 'mosmcp-abilities' ),
			),
			'date_min' => array(
				'type'        => 'string',
				'description' => __( 'Start date, inclusive (YYYY-MM-DD). Required with date_max unless period is given.', 'mosmcp-abilities' ),
			),
			'date_max' => array(
				'type'        => 'string',
				'description' => __( 'End date, inclusive (YYYY-MM-DD). Required with date_min unless period is given.', 'mosmcp-abilities' ),
			),
		);
	}

	/**
	 * Resolves period/date_min/date_max input into a concrete [date_min, date_max]
	 * pair. Exactly one of period, or the date_min+date_max pair, is required.
	 *
	 * @param array<string, mixed> $input
	 * @return array{0:string,1:string}|WP_Error
	 */
	private static function resolve_date_range( array $input ) {
		if ( ! empty( $input['period'] ) ) {
			$period = WooCommerce_Validator::validate_enum( $input['period'], self::PERIODS, __( 'period', 'mosmcp-abilities' ) );
			if ( is_wp_error( $period ) ) {
				return $period;
			}
			return self::period_to_range( $period );
		}

		$date_min = isset( $input['date_min'] ) ? WooCommerce_Validator::validate_date( $input['date_min'], __( 'date_min', 'mosmcp-abilities' ) ) : null;
		if ( is_wp_error( $date_min ) ) {
			return $date_min;
		}
		$date_max = isset( $input['date_max'] ) ? WooCommerce_Validator::validate_date( $input['date_max'], __( 'date_max', 'mosmcp-abilities' ) ) : null;
		if ( is_wp_error( $date_max ) ) {
			return $date_max;
		}

		if ( null === $date_min || null === $date_max ) {
			return WooCommerce_Response::error( 'wcab_missing_date_range', __( 'Provide either period, or both date_min and date_max.', 'mosmcp-abilities' ) );
		}

		return array( $date_min, $date_max );
	}

	/**
	 * @param string $period One of self::PERIODS.
	 * @return array{0:string,1:string}
	 */
	private static function period_to_range( $period ) {
		$now = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested

		switch ( $period ) {
			case 'month':
				$start = strtotime( 'first day of this month', $now );
				$end   = $now;
				break;
			case 'last_month':
				$start = strtotime( 'first day of last month', $now );
				$end   = strtotime( 'last day of last month', $now );
				break;
			case 'year':
				$start = strtotime( 'first day of january this year', $now );
				$end   = $now;
				break;
			case 'week':
			default:
				$start = strtotime( 'monday this week', $now );
				$end   = $now;
				break;
		}

		return array( gmdate( 'Y-m-d', $start ), gmdate( 'Y-m-d', $end ) );
	}

	/*
	-------------------------------------------------------------------- *
	 * Sales
	 * -------------------------------------------------------------------- */

	/**
	 * @return void
	 */
	private static function register_sales() {
		Naming::register_ability(
			'mosmcp/report-sales',
			array(
				'label'               => __( 'Sales Report', 'mosmcp-abilities' ),
				'description'         => __( 'Gets a sales summary for a date range or period: gross/net sales, order and item counts, tax, shipping, refunds, and discounts. Optionally scoped to a single product or category.', 'mosmcp-abilities' ),
				'category'            => WooCommerce_Abilities_Loader::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array_merge(
						self::date_range_properties(),
						array(
							'status'   => array(
								'type'        => 'array',
								'items'       => array( 'type' => 'string' ),
								'description' => __( 'Order statuses to count as sales. Defaults to completed and processing.', 'mosmcp-abilities' ),
							),
							'category' => array(
								'type'        => 'integer',
								'description' => __( 'Restrict to line items whose product is in this category ID.', 'mosmcp-abilities' ),
							),
							'product'  => array(
								'type'        => 'integer',
								'description' => __( 'Restrict to line items for this product ID.', 'mosmcp-abilities' ),
							),
						)
					),
					'additionalProperties' => false,
				),
				'output_schema'       => WooCommerce_Response::envelope_schema(
					array(
						'type'                 => 'object',
						'properties'           => array(
							'total_sales'         => array( 'type' => 'string' ),
							'net_sales'           => array( 'type' => 'string' ),
							'average_sales'       => array( 'type' => 'string' ),
							'total_orders'        => array( 'type' => 'integer' ),
							'total_items'         => array( 'type' => 'integer' ),
							'total_tax'           => array( 'type' => 'string' ),
							'total_shipping'      => array( 'type' => 'string' ),
							'total_refunds'       => array( 'type' => 'integer' ),
							'total_refund_amount' => array( 'type' => 'string' ),
							'total_discount'      => array( 'type' => 'integer' ),
						),
						'additionalProperties' => false,
					)
				),
				'execute_callback'    => array( __CLASS__, 'report_sales' ),
				'permission_callback' => array( WooCommerce_Permissions::class, 'can_view_reports' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
						'open_world'  => false,
					),
					'required_cap' => WooCommerce_Permissions::VIEW_REPORTS,
					'show_in_rest' => false,
				),
			)
		);
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|WP_Error
	 */
	public static function report_sales( $input = array() ) {
		$started_at = microtime( true );
		$input      = is_array( $input ) ? $input : array();

		$range = self::resolve_date_range( $input );
		if ( is_wp_error( $range ) ) {
			return $range;
		}
		list( $date_min, $date_max ) = $range;

		$statuses = ! empty( $input['status'] ) && is_array( $input['status'] )
			? array_values( array_filter( array_map( 'sanitize_key', $input['status'] ) ) )
			: self::DEFAULT_STATUSES;

		$product_filter  = ! empty( $input['product'] ) ? absint( $input['product'] ) : 0;
		$category_filter = ! empty( $input['category'] ) ? absint( $input['category'] ) : 0;

		$orders = wc_get_orders(
			array(
				'status'       => $statuses,
				'date_created' => $date_min . '...' . $date_max,
				'limit'        => -1,
				'return'       => 'objects',
			)
		);

		// Resolved once, up front: the alternative (a wc_get_product() call per line
		// item inside aggregate_sales()'s per-order loop, to read its category IDs)
		// is an N+1 that scales with every order in the date range.
		$category_product_ids = $category_filter ? self::product_ids_in_category( $category_filter ) : null;

		$totals = self::aggregate_sales( $orders, $product_filter, $category_filter, $category_product_ids );

		$days          = max( 1, ( strtotime( $date_max ) - strtotime( $date_min ) ) / DAY_IN_SECONDS + 1 );
		$net_sales     = $totals['total_sales'] - $totals['total_tax'] - $totals['total_shipping'] - $totals['total_refund_amount'];
		$average_sales = $net_sales / $days;

		WooCommerce_Helper::log(
			'mosmcp/report-sales',
			'success',
			array(
				'date_min' => $date_min,
				'date_max' => $date_max,
				'orders'   => $totals['total_orders'],
			)
		);

		return WooCommerce_Response::success(
			'mosmcp/report-sales',
			array(
				'total_sales'         => WooCommerce_Helper::money( $totals['total_sales'] ),
				'net_sales'           => WooCommerce_Helper::money( $net_sales ),
				'average_sales'       => WooCommerce_Helper::money( $average_sales ),
				'total_orders'        => $totals['total_orders'],
				'total_items'         => $totals['total_items'],
				'total_tax'           => WooCommerce_Helper::money( $totals['total_tax'] ),
				'total_shipping'      => WooCommerce_Helper::money( $totals['total_shipping'] ),
				'total_refunds'       => $totals['total_refunds'],
				'total_refund_amount' => WooCommerce_Helper::money( $totals['total_refund_amount'] ),
				'total_discount'      => $totals['total_discount'],
			),
			__( 'Sales report generated.', 'mosmcp-abilities' ),
			null,
			$started_at
		);
	}

	/**
	 * Builds the `date_created` query value `wc_get_orders()` expects, honoring
	 * a partial range (either bound alone) rather than requiring both — a
	 * caller-supplied `date_min` with no `date_max` means "since date_min",
	 * not "ignore the filter and return all-time totals".
	 *
	 * @param string|null $date_min Start date (Y-m-d), or null.
	 * @param string|null $date_max End date (Y-m-d), or null.
	 * @return string
	 */
	private static function date_range_query( $date_min, $date_max ) {
		if ( $date_min && $date_max ) {
			return $date_min . '...' . $date_max;
		}
		if ( $date_min ) {
			return '>=' . $date_min;
		}
		return '<=' . $date_max;
	}

	/**
	 * @param \WC_Order[]          $orders
	 * @param int                  $product_filter        Product ID to restrict to, or 0 for no filter.
	 * @param int                  $category_filter       Category ID to restrict to, or 0 for no filter.
	 * @param array<int, int>|null $category_product_ids  Product IDs in $category_filter, keyed by ID for O(1)
	 *                                                     lookup (see {@see product_ids_in_category()}); null
	 *                                                     when $category_filter is 0.
	 * @return array<string, mixed>
	 */
	private static function aggregate_sales( array $orders, $product_filter, $category_filter, $category_product_ids = null ) {
		$total_orders        = 0;
		$total_items         = 0;
		$total_sales         = 0.0;
		$total_tax           = 0.0;
		$total_shipping      = 0.0;
		$total_refunds       = 0;
		$total_refund_amount = 0.0;
		$total_discount      = 0;
		$scoped              = ( $product_filter || $category_filter );

		foreach ( $orders as $order ) {
			if ( $scoped ) {
				$matched = false;
				foreach ( $order->get_items( 'line_item' ) as $item ) {
					if ( ! self::line_item_matches( $item, $product_filter, $category_filter, $category_product_ids ) ) {
						continue;
					}
					$matched      = true;
					$total_items += (int) $item->get_quantity();
					$total_sales += (float) $item->get_total();
				}
				if ( $matched ) {
					++$total_orders;
				}
				continue;
			}

			++$total_orders;
			$total_items    += $order->get_item_count();
			$total_sales    += (float) $order->get_total();
			$total_tax      += (float) $order->get_total_tax();
			$total_shipping += (float) $order->get_shipping_total();

			$refunded = (float) $order->get_total_refunded();
			if ( $refunded > 0 ) {
				++$total_refunds;
				$total_refund_amount += $refunded;
			}

			$total_discount += count( $order->get_items( 'coupon' ) );
		}

		return array(
			'total_orders'        => $total_orders,
			'total_items'         => $total_items,
			'total_sales'         => $total_sales,
			'total_tax'           => $total_tax,
			'total_shipping'      => $total_shipping,
			'total_refunds'       => $total_refunds,
			'total_refund_amount' => $total_refund_amount,
			'total_discount'      => $total_discount,
		);
	}

	/**
	 * @param \WC_Order_Item_Product $item                 The line item.
	 * @param int                    $product_filter       Product ID to match, or 0 for no filter.
	 * @param int                    $category_filter      Category ID to match, or 0 for no filter.
	 * @param array<int, int>|null   $category_product_ids Product IDs in $category_filter (see
	 *                                                      {@see product_ids_in_category()}); null when
	 *                                                      $category_filter is 0.
	 * @return bool
	 */
	private static function line_item_matches( $item, $product_filter, $category_filter, $category_product_ids = null ) {
		$product_id = (int) $item->get_product_id();

		if ( $product_filter && $product_id !== $product_filter ) {
			return false;
		}

		if ( $category_filter && ! isset( $category_product_ids[ $product_id ] ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Product IDs belonging to a product category, keyed by ID for O(1)
	 * "is this product in the category" lookups.
	 *
	 * @param int $category_id Product category term ID.
	 * @return array<int, int>
	 */
	private static function product_ids_in_category( $category_id ) {
		$ids = get_objects_in_term( $category_id, 'product_cat' );
		if ( is_wp_error( $ids ) || empty( $ids ) ) {
			return array();
		}

		return array_flip( array_map( 'intval', $ids ) );
	}

	/*
	-------------------------------------------------------------------- *
	 * Top sellers
	 * -------------------------------------------------------------------- */

	/**
	 * @return void
	 */
	private static function register_top_sellers() {
		Naming::register_ability(
			'mosmcp/report-top-sellers',
			array(
				'label'               => __( 'Top Sellers Report', 'mosmcp-abilities' ),
				'description'         => __( 'Gets the top-selling products by quantity for a date range or period.', 'mosmcp-abilities' ),
				'category'            => WooCommerce_Abilities_Loader::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array_merge(
						self::date_range_properties(),
						array(
							'status' => array(
								'type'        => 'array',
								'items'       => array( 'type' => 'string' ),
								'description' => __( 'Order statuses to count. Defaults to completed and processing.', 'mosmcp-abilities' ),
							),
							'limit'  => array(
								'type'        => 'integer',
								'default'     => 10,
								'description' => __( 'Maximum number of products to return (1-100).', 'mosmcp-abilities' ),
							),
						)
					),
					'additionalProperties' => false,
				),
				'output_schema'       => WooCommerce_Response::envelope_schema(
					array(
						'type'                 => 'object',
						'properties'           => array(
							'products' => array(
								'type'  => 'array',
								'items' => array(
									'type'                 => 'object',
									'properties'           => array(
										'title'      => array( 'type' => 'string' ),
										'product_id' => array( 'type' => 'integer' ),
										'quantity'   => array( 'type' => 'integer' ),
									),
									'additionalProperties' => false,
								),
							),
						),
						'additionalProperties' => false,
					)
				),
				'execute_callback'    => array( __CLASS__, 'report_top_sellers' ),
				'permission_callback' => array( WooCommerce_Permissions::class, 'can_view_reports' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
						'open_world'  => false,
					),
					'required_cap' => WooCommerce_Permissions::VIEW_REPORTS,
					'show_in_rest' => false,
				),
			)
		);
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|WP_Error
	 */
	public static function report_top_sellers( $input = array() ) {
		$started_at = microtime( true );
		$input      = is_array( $input ) ? $input : array();

		$range = self::resolve_date_range( $input );
		if ( is_wp_error( $range ) ) {
			return $range;
		}
		list( $date_min, $date_max ) = $range;

		$statuses = ! empty( $input['status'] ) && is_array( $input['status'] )
			? array_values( array_filter( array_map( 'sanitize_key', $input['status'] ) ) )
			: self::DEFAULT_STATUSES;

		$limit = isset( $input['limit'] ) ? max( 1, min( 100, absint( $input['limit'] ) ) ) : 10;

		$orders = wc_get_orders(
			array(
				'status'       => $statuses,
				'date_created' => $date_min . '...' . $date_max,
				'limit'        => -1,
				'return'       => 'objects',
			)
		);

		$quantities = array();
		foreach ( $orders as $order ) {
			foreach ( $order->get_items( 'line_item' ) as $item ) {
				$product_id = (int) $item->get_product_id();
				if ( ! isset( $quantities[ $product_id ] ) ) {
					$quantities[ $product_id ] = array(
						'title'      => $item->get_name(),
						'product_id' => $product_id,
						'quantity'   => 0,
					);
				}
				$quantities[ $product_id ]['quantity'] += (int) $item->get_quantity();
			}
		}

		usort(
			$quantities,
			static function ( $a, $b ) {
				return $b['quantity'] <=> $a['quantity'];
			}
		);

		$products = array_slice( array_values( $quantities ), 0, $limit );

		WooCommerce_Helper::log( 'mosmcp/report-top-sellers', 'success', array( 'count' => count( $products ) ) );

		return WooCommerce_Response::success(
			'mosmcp/report-top-sellers',
			array( 'products' => $products ),
			sprintf(
				/* translators: %d: number of products returned */
				__( '%d product(s) retrieved.', 'mosmcp-abilities' ),
				count( $products )
			),
			null,
			$started_at
		);
	}

	/*
	-------------------------------------------------------------------- *
	 * Orders totals
	 * -------------------------------------------------------------------- */

	/**
	 * @return void
	 */
	private static function register_orders() {
		Naming::register_ability(
			'mosmcp/report-orders',
			array(
				'label'               => __( 'Orders Report', 'mosmcp-abilities' ),
				'description'         => __( 'Gets order counts broken down by status. Defaults to all-time totals, matching the official WooCommerce Orders Totals report; optionally scope to a date range.', 'mosmcp-abilities' ),
				'category'            => WooCommerce_Abilities_Loader::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'date_min' => array(
							'type'        => 'string',
							'description' => __( 'Start date, inclusive (YYYY-MM-DD). Omit for all-time totals.', 'mosmcp-abilities' ),
						),
						'date_max' => array(
							'type'        => 'string',
							'description' => __( 'End date, inclusive (YYYY-MM-DD). Omit for all-time totals.', 'mosmcp-abilities' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => WooCommerce_Response::envelope_schema(
					array(
						'type'                 => 'object',
						'properties'           => array(
							'totals' => array(
								'type'  => 'array',
								'items' => array(
									'type'                 => 'object',
									'properties'           => array(
										'slug'  => array( 'type' => 'string' ),
										'name'  => array( 'type' => 'string' ),
										'total' => array( 'type' => 'integer' ),
									),
									'additionalProperties' => false,
								),
							),
						),
						'additionalProperties' => false,
					)
				),
				'execute_callback'    => array( __CLASS__, 'report_orders' ),
				'permission_callback' => array( WooCommerce_Permissions::class, 'can_view_reports' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
						'open_world'  => false,
					),
					'required_cap' => WooCommerce_Permissions::VIEW_REPORTS,
					'show_in_rest' => false,
				),
			)
		);
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|WP_Error
	 */
	public static function report_orders( $input = array() ) {
		$started_at = microtime( true );
		$input      = is_array( $input ) ? $input : array();

		$date_min = null;
		$date_max = null;
		if ( ! empty( $input['date_min'] ) || ! empty( $input['date_max'] ) ) {
			$date_min = isset( $input['date_min'] ) ? WooCommerce_Validator::validate_date( $input['date_min'], __( 'date_min', 'mosmcp-abilities' ) ) : null;
			if ( is_wp_error( $date_min ) ) {
				return $date_min;
			}
			$date_max = isset( $input['date_max'] ) ? WooCommerce_Validator::validate_date( $input['date_max'], __( 'date_max', 'mosmcp-abilities' ) ) : null;
			if ( is_wp_error( $date_max ) ) {
				return $date_max;
			}
		}

		$totals = array();
		foreach ( wc_get_order_statuses() as $status_key => $label ) {
			$slug = str_replace( 'wc-', '', $status_key );

			if ( $date_min || $date_max ) {
				$result = wc_get_orders(
					array(
						'status'       => array( $slug ),
						'date_created' => self::date_range_query( $date_min, $date_max ),
						'limit'        => 1,
						'paginate'     => true,
						'return'       => 'ids',
					)
				);
				$count  = $result->total;
			} else {
				$count = function_exists( 'wc_orders_count' ) ? wc_orders_count( $slug ) : 0;
			}

			$totals[] = array(
				'slug'  => $slug,
				'name'  => $label,
				'total' => (int) $count,
			);
		}

		WooCommerce_Helper::log( 'mosmcp/report-orders', 'success', array() );

		return WooCommerce_Response::success(
			'mosmcp/report-orders',
			array( 'totals' => $totals ),
			__( 'Order report generated.', 'mosmcp-abilities' ),
			null,
			$started_at
		);
	}

	/*
	-------------------------------------------------------------------- *
	 * Products totals
	 * -------------------------------------------------------------------- */

	/**
	 * @return void
	 */
	private static function register_products() {
		Naming::register_ability(
			'mosmcp/report-products',
			array(
				'label'               => __( 'Products Report', 'mosmcp-abilities' ),
				'description'         => __( 'Gets product counts broken down by type (simple, variable, grouped, external), matching the official WooCommerce Products Totals report.', 'mosmcp-abilities' ),
				'category'            => WooCommerce_Abilities_Loader::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(),
					'additionalProperties' => false,
				),
				'output_schema'       => WooCommerce_Response::envelope_schema(
					array(
						'type'                 => 'object',
						'properties'           => array(
							'totals' => array(
								'type'  => 'array',
								'items' => array(
									'type'                 => 'object',
									'properties'           => array(
										'slug'  => array( 'type' => 'string' ),
										'name'  => array( 'type' => 'string' ),
										'total' => array( 'type' => 'integer' ),
									),
									'additionalProperties' => false,
								),
							),
						),
						'additionalProperties' => false,
					)
				),
				'execute_callback'    => array( __CLASS__, 'report_products' ),
				'permission_callback' => array( WooCommerce_Permissions::class, 'can_view_reports' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
						'open_world'  => false,
					),
					'required_cap' => WooCommerce_Permissions::VIEW_REPORTS,
					'show_in_rest' => false,
				),
			)
		);
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	public static function report_products( $input = array() ) {
		unset( $input ); // This report takes no input filters.
		$started_at = microtime( true );

		$labels = function_exists( 'wc_get_product_types' ) ? wc_get_product_types() : array();

		$totals = array();
		foreach ( Products_Abilities::TYPES as $type ) {
			$result   = wc_get_products(
				array(
					'type'     => $type,
					'limit'    => 1,
					'paginate' => true,
					'return'   => 'ids',
				)
			);
			$totals[] = array(
				'slug'  => $type,
				'name'  => isset( $labels[ $type ] ) ? $labels[ $type ] : ucfirst( $type ),
				'total' => (int) $result->total,
			);
		}

		WooCommerce_Helper::log( 'mosmcp/report-products', 'success', array() );

		return WooCommerce_Response::success(
			'mosmcp/report-products',
			array( 'totals' => $totals ),
			__( 'Product report generated.', 'mosmcp-abilities' ),
			null,
			$started_at
		);
	}

	/*
	-------------------------------------------------------------------- *
	 * Customers totals
	 * -------------------------------------------------------------------- */

	/**
	 * @return void
	 */
	private static function register_customers() {
		Naming::register_ability(
			'mosmcp/report-customers',
			array(
				'label'               => __( 'Customers Report', 'mosmcp-abilities' ),
				'description'         => __( "Gets customer counts broken down by WordPress role. The official WooCommerce Customers Totals report's exact 'customer type' grouping isn't fully documented; role is the closest well-defined, verifiable interpretation.", 'mosmcp-abilities' ),
				'category'            => WooCommerce_Abilities_Loader::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(),
					'additionalProperties' => false,
				),
				'output_schema'       => WooCommerce_Response::envelope_schema(
					array(
						'type'                 => 'object',
						'properties'           => array(
							'totals' => array(
								'type'  => 'array',
								'items' => array(
									'type'                 => 'object',
									'properties'           => array(
										'slug'  => array( 'type' => 'string' ),
										'name'  => array( 'type' => 'string' ),
										'total' => array( 'type' => 'integer' ),
									),
									'additionalProperties' => false,
								),
							),
						),
						'additionalProperties' => false,
					)
				),
				'execute_callback'    => array( __CLASS__, 'report_customers' ),
				'permission_callback' => array( WooCommerce_Permissions::class, 'can_view_reports' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
						'open_world'  => false,
					),
					'required_cap' => WooCommerce_Permissions::VIEW_REPORTS,
					'show_in_rest' => false,
				),
			)
		);
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	public static function report_customers( $input = array() ) {
		unset( $input ); // This report takes no input filters.
		$started_at = microtime( true );

		$counts   = count_users();
		$wp_roles = wp_roles();

		$totals = array();
		foreach ( $counts['avail_roles'] as $role => $count ) {
			$name     = ( $wp_roles && isset( $wp_roles->roles[ $role ]['name'] ) ) ? $wp_roles->roles[ $role ]['name'] : ucfirst( $role );
			$totals[] = array(
				'slug'  => $role,
				'name'  => $name,
				'total' => (int) $count,
			);
		}

		WooCommerce_Helper::log( 'mosmcp/report-customers', 'success', array() );

		return WooCommerce_Response::success(
			'mosmcp/report-customers',
			array( 'totals' => $totals ),
			__( 'Customer report generated.', 'mosmcp-abilities' ),
			null,
			$started_at
		);
	}

	/*
	-------------------------------------------------------------------- *
	 * Coupon usage
	 * -------------------------------------------------------------------- */

	/**
	 * @return void
	 */
	private static function register_coupon_usage() {
		Naming::register_ability(
			'mosmcp/report-coupon-usage',
			array(
				'label'               => __( 'Coupon Usage Report', 'mosmcp-abilities' ),
				'description'         => __( 'Gets how many times each coupon was used and the total amount discounted, optionally within a date range. This intentionally differs from the official WooCommerce "coupons/totals" report, which only counts coupons per discount type — this reports actual redemption activity instead, matching what "usage statistics" implies.', 'mosmcp-abilities' ),
				'category'            => WooCommerce_Abilities_Loader::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'date_min' => array(
							'type'        => 'string',
							'description' => __( 'Start date, inclusive (YYYY-MM-DD). Omit for all-time.', 'mosmcp-abilities' ),
						),
						'date_max' => array(
							'type'        => 'string',
							'description' => __( 'End date, inclusive (YYYY-MM-DD). Omit for all-time.', 'mosmcp-abilities' ),
						),
						'limit'    => array(
							'type'        => 'integer',
							'default'     => 20,
							'description' => __( 'Maximum number of coupons to return (1-100), ranked by times used.', 'mosmcp-abilities' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => WooCommerce_Response::envelope_schema(
					array(
						'type'                 => 'object',
						'properties'           => array(
							'coupons' => array(
								'type'  => 'array',
								'items' => array(
									'type'                 => 'object',
									'properties'           => array(
										'code'           => array( 'type' => 'string' ),
										'times_used'     => array( 'type' => 'integer' ),
										'total_discount' => array( 'type' => 'string' ),
									),
									'additionalProperties' => false,
								),
							),
						),
						'additionalProperties' => false,
					)
				),
				'execute_callback'    => array( __CLASS__, 'report_coupon_usage' ),
				'permission_callback' => array( WooCommerce_Permissions::class, 'can_view_reports' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
						'open_world'  => false,
					),
					'required_cap' => WooCommerce_Permissions::VIEW_REPORTS,
					'show_in_rest' => false,
				),
			)
		);
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|WP_Error
	 */
	public static function report_coupon_usage( $input = array() ) {
		$started_at = microtime( true );
		$input      = is_array( $input ) ? $input : array();

		$date_min = null;
		$date_max = null;
		if ( ! empty( $input['date_min'] ) || ! empty( $input['date_max'] ) ) {
			$date_min = isset( $input['date_min'] ) ? WooCommerce_Validator::validate_date( $input['date_min'], __( 'date_min', 'mosmcp-abilities' ) ) : null;
			if ( is_wp_error( $date_min ) ) {
				return $date_min;
			}
			$date_max = isset( $input['date_max'] ) ? WooCommerce_Validator::validate_date( $input['date_max'], __( 'date_max', 'mosmcp-abilities' ) ) : null;
			if ( is_wp_error( $date_max ) ) {
				return $date_max;
			}
		}

		$limit = isset( $input['limit'] ) ? max( 1, min( 100, absint( $input['limit'] ) ) ) : 20;

		$order_args = array(
			'status' => self::DEFAULT_STATUSES,
			'limit'  => -1,
			'return' => 'objects',
		);
		if ( $date_min || $date_max ) {
			$order_args['date_created'] = self::date_range_query( $date_min, $date_max );
		}

		$orders = wc_get_orders( $order_args );

		$usage = array();
		foreach ( $orders as $order ) {
			foreach ( $order->get_items( 'coupon' ) as $item ) {
				$code = $item->get_code();
				if ( ! isset( $usage[ $code ] ) ) {
					$usage[ $code ] = array(
						'code'           => $code,
						'times_used'     => 0,
						'total_discount' => 0.0,
					);
				}
				++$usage[ $code ]['times_used'];
				$usage[ $code ]['total_discount'] += (float) $item->get_discount();
			}
		}

		usort(
			$usage,
			static function ( $a, $b ) {
				return $b['times_used'] <=> $a['times_used'];
			}
		);

		$usage = array_slice( array_values( $usage ), 0, $limit );
		foreach ( $usage as &$row ) {
			$row['total_discount'] = WooCommerce_Helper::money( $row['total_discount'] );
		}
		unset( $row );

		WooCommerce_Helper::log( 'mosmcp/report-coupon-usage', 'success', array( 'count' => count( $usage ) ) );

		return WooCommerce_Response::success(
			'mosmcp/report-coupon-usage',
			array( 'coupons' => $usage ),
			sprintf(
				/* translators: %d: number of coupons returned */
				__( '%d coupon(s) retrieved.', 'mosmcp-abilities' ),
				count( $usage )
			),
			null,
			$started_at
		);
	}
}
