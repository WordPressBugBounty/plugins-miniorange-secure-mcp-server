<?php
/**
 * WooCommerce Coupons abilities: list, get, create, update, and delete.
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

use WC_Coupon;
use WP_Error;
use WP_Query;

/**
 * Class Coupons_Abilities
 *
 * Registers mosmcp/list-coupons, get-coupon, create-coupon,
 * update-coupon, and delete-coupon. Field names deliberately mirror the
 * WooCommerce REST API v3 Coupon object
 * (https://developer.woocommerce.com/docs/apis/rest-api/v3/coupons/).
 */
class Coupons_Abilities {

	/**
	 * Discount types the create/update abilities accept.
	 */
	const DISCOUNT_TYPES = array( 'percent', 'fixed_cart', 'fixed_product' );

	/**
	 * Coupon post type.
	 */
	const POST_TYPE = 'shop_coupon';

	/**
	 * Validates $input['id'] and confirms it names an existing coupon.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return int|WP_Error
	 */
	private static function require_coupon_id( $input ) {
		$coupon_id = WooCommerce_Validator::validate_id( isset( $input['id'] ) ? $input['id'] : null, __( 'coupon ID', 'mosmcp-abilities' ) );
		if ( is_wp_error( $coupon_id ) ) {
			return $coupon_id;
		}

		$post = get_post( $coupon_id );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return WooCommerce_Response::error( 'wcab_coupon_not_found', __( 'No coupon was found with that ID.', 'mosmcp-abilities' ) );
		}

		return $coupon_id;
	}

	/**
	 * Registers every Coupons ability. Called from {@see WooCommerce_Abilities_Loader}.
	 *
	 * @return void
	 */
	public static function register() {
		$coupon_item_schema = array(
			'type'                 => 'object',
			'properties'           => array(
				'id'                          => array( 'type' => 'integer' ),
				'code'                        => array( 'type' => 'string' ),
				'amount'                      => array( 'type' => 'string' ),
				'date_created'                => array( 'type' => array( 'string', 'null' ) ),
				'date_modified'               => array( 'type' => array( 'string', 'null' ) ),
				'discount_type'               => array( 'type' => 'string' ),
				'description'                 => array( 'type' => 'string' ),
				'date_expires'                => array( 'type' => array( 'string', 'null' ) ),
				'usage_count'                 => array( 'type' => 'integer' ),
				'individual_use'              => array( 'type' => 'boolean' ),
				'product_ids'                 => array(
					'type'  => 'array',
					'items' => array( 'type' => 'integer' ),
				),
				'excluded_product_ids'        => array(
					'type'  => 'array',
					'items' => array( 'type' => 'integer' ),
				),
				'usage_limit'                 => array( 'type' => array( 'integer', 'null' ) ),
				'usage_limit_per_user'        => array( 'type' => array( 'integer', 'null' ) ),
				'limit_usage_to_x_items'      => array( 'type' => array( 'integer', 'null' ) ),
				'free_shipping'               => array( 'type' => 'boolean' ),
				'product_categories'          => array(
					'type'  => 'array',
					'items' => array( 'type' => 'integer' ),
				),
				'excluded_product_categories' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'integer' ),
				),
				'exclude_sale_items'          => array( 'type' => 'boolean' ),
				'minimum_amount'              => array( 'type' => 'string' ),
				'maximum_amount'              => array( 'type' => 'string' ),
				'email_restrictions'          => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'used_by'                     => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
			),
			'additionalProperties' => false,
		);

		self::register_list( $coupon_item_schema );
		self::register_get( $coupon_item_schema );
		self::register_create( $coupon_item_schema );
		self::register_update( $coupon_item_schema );
		self::register_delete();
	}

	/**
	 * Input schema properties shared by create-coupon and update-coupon —
	 * everything except code/amount (required on create) and id (update-only).
	 *
	 * @return array<string, mixed>
	 */
	private static function writable_field_properties() {
		return array(
			'discount_type'               => array(
				'type'        => 'string',
				'enum'        => self::DISCOUNT_TYPES,
				'default'     => 'percent',
				'description' => __( 'The discount type. Defaults to percent.', 'mosmcp-abilities' ),
			),
			'description'                 => array(
				'type'        => 'string',
				'description' => __( 'Coupon description.', 'mosmcp-abilities' ),
			),
			'date_expires'                => array(
				'type'        => 'string',
				'description' => __( 'Expiry date (YYYY-MM-DD). Pass an empty string to clear it.', 'mosmcp-abilities' ),
			),
			'individual_use'              => array(
				'type'        => 'boolean',
				'description' => __( 'Whether this coupon can be combined with other coupons.', 'mosmcp-abilities' ),
			),
			'product_ids'                 => array(
				'type'        => 'array',
				'items'       => array( 'type' => 'integer' ),
				'description' => __( 'Product IDs this coupon is restricted to. Replaces the existing set entirely; pass an empty array to remove the restriction.', 'mosmcp-abilities' ),
			),
			'excluded_product_ids'        => array(
				'type'        => 'array',
				'items'       => array( 'type' => 'integer' ),
				'description' => __( 'Product IDs this coupon cannot be used on. Replaces the existing set entirely.', 'mosmcp-abilities' ),
			),
			'usage_limit'                 => array(
				'type'        => 'integer',
				'description' => __( 'Maximum number of times the coupon can be used in total.', 'mosmcp-abilities' ),
			),
			'usage_limit_per_user'        => array(
				'type'        => 'integer',
				'description' => __( 'Maximum number of times a single customer can use the coupon.', 'mosmcp-abilities' ),
			),
			'limit_usage_to_x_items'      => array(
				'type'        => 'integer',
				'description' => __( 'Maximum number of items in the cart the coupon applies to.', 'mosmcp-abilities' ),
			),
			'free_shipping'               => array(
				'type'        => 'boolean',
				'description' => __( 'Whether this coupon grants free shipping.', 'mosmcp-abilities' ),
			),
			'product_categories'          => array(
				'type'        => 'array',
				'items'       => array( 'type' => 'integer' ),
				'description' => __( 'Category IDs this coupon applies to. Replaces the existing set entirely.', 'mosmcp-abilities' ),
			),
			'excluded_product_categories' => array(
				'type'        => 'array',
				'items'       => array( 'type' => 'integer' ),
				'description' => __( 'Category IDs this coupon does not apply to. Replaces the existing set entirely.', 'mosmcp-abilities' ),
			),
			'exclude_sale_items'          => array(
				'type'        => 'boolean',
				'description' => __( 'Whether this coupon is withheld from items already on sale.', 'mosmcp-abilities' ),
			),
			'minimum_amount'              => array(
				'type'        => 'string',
				'description' => __( 'Minimum cart subtotal required for this coupon to apply.', 'mosmcp-abilities' ),
			),
			'maximum_amount'              => array(
				'type'        => 'string',
				'description' => __( 'Maximum cart subtotal this coupon can apply to.', 'mosmcp-abilities' ),
			),
			'email_restrictions'          => array(
				'type'        => 'array',
				'items'       => array( 'type' => 'string' ),
				'description' => __( 'Email addresses (wildcards allowed, e.g. *@example.com) allowed to use this coupon. Replaces the existing set entirely.', 'mosmcp-abilities' ),
			),
		);
	}

	/**
	 * Applies whichever writable fields are present in $input to $coupon.
	 * Shared by create_coupon() and update_coupon().
	 *
	 * @param \WC_Coupon           $coupon
	 * @param array<string, mixed> $input
	 * @return true|WP_Error
	 */
	private static function apply_writable_fields( $coupon, array $input ) {
		if ( isset( $input['discount_type'] ) ) {
			$discount_type = WooCommerce_Validator::validate_enum( $input['discount_type'], self::DISCOUNT_TYPES, __( 'discount_type', 'mosmcp-abilities' ) );
			if ( is_wp_error( $discount_type ) ) {
				return $discount_type;
			}
			$coupon->set_discount_type( $discount_type );
		}
		if ( isset( $input['description'] ) ) {
			$coupon->set_description( sanitize_textarea_field( (string) $input['description'] ) );
		}
		if ( isset( $input['date_expires'] ) ) {
			$expires = sanitize_text_field( (string) $input['date_expires'] );
			if ( '' === $expires ) {
				$coupon->set_date_expires( null );
			} else {
				$validated = WooCommerce_Validator::validate_date( $expires, __( 'date_expires', 'mosmcp-abilities' ) );
				if ( is_wp_error( $validated ) ) {
					return $validated;
				}
				$coupon->set_date_expires( $validated );
			}
		}
		if ( isset( $input['individual_use'] ) ) {
			$coupon->set_individual_use( (bool) $input['individual_use'] );
		}
		if ( isset( $input['product_ids'] ) ) {
			$coupon->set_product_ids( WooCommerce_Validator::validate_id_array( $input['product_ids'] ) );
		}
		if ( isset( $input['excluded_product_ids'] ) ) {
			$coupon->set_excluded_product_ids( WooCommerce_Validator::validate_id_array( $input['excluded_product_ids'] ) );
		}
		if ( isset( $input['usage_limit'] ) ) {
			$coupon->set_usage_limit( absint( $input['usage_limit'] ) );
		}
		if ( isset( $input['usage_limit_per_user'] ) ) {
			$coupon->set_usage_limit_per_user( absint( $input['usage_limit_per_user'] ) );
		}
		if ( isset( $input['limit_usage_to_x_items'] ) ) {
			$coupon->set_limit_usage_to_x_items( absint( $input['limit_usage_to_x_items'] ) );
		}
		if ( isset( $input['free_shipping'] ) ) {
			$coupon->set_free_shipping( (bool) $input['free_shipping'] );
		}
		if ( isset( $input['product_categories'] ) ) {
			$coupon->set_product_categories( WooCommerce_Validator::validate_id_array( $input['product_categories'] ) );
		}
		if ( isset( $input['excluded_product_categories'] ) ) {
			$coupon->set_excluded_product_categories( WooCommerce_Validator::validate_id_array( $input['excluded_product_categories'] ) );
		}
		if ( isset( $input['exclude_sale_items'] ) ) {
			$coupon->set_exclude_sale_items( (bool) $input['exclude_sale_items'] );
		}
		if ( isset( $input['minimum_amount'] ) ) {
			$amount = sanitize_text_field( (string) $input['minimum_amount'] );
			if ( '' !== $amount && ! is_numeric( $amount ) ) {
				return WooCommerce_Response::error( 'wcab_invalid_amount', __( 'minimum_amount must be numeric, or an empty string to clear it.', 'mosmcp-abilities' ) );
			}
			$coupon->set_minimum_amount( $amount );
		}
		if ( isset( $input['maximum_amount'] ) ) {
			$amount = sanitize_text_field( (string) $input['maximum_amount'] );
			if ( '' !== $amount && ! is_numeric( $amount ) ) {
				return WooCommerce_Response::error( 'wcab_invalid_amount', __( 'maximum_amount must be numeric, or an empty string to clear it.', 'mosmcp-abilities' ) );
			}
			$coupon->set_maximum_amount( $amount );
		}
		if ( isset( $input['email_restrictions'] ) && is_array( $input['email_restrictions'] ) ) {
			$coupon->set_email_restrictions( array_map( 'sanitize_text_field', $input['email_restrictions'] ) );
		}

		return true;
	}

	/*
	-------------------------------------------------------------------- *
	 * List / Get
	 * -------------------------------------------------------------------- */

	/**
	 * @param array<string, mixed> $coupon_item_schema
	 * @return void
	 */
	private static function register_list( array $coupon_item_schema ) {
		Naming::register_ability(
			'mosmcp/list-coupons',
			array(
				'label'               => __( 'List Coupons', 'mosmcp-abilities' ),
				'description'         => __( 'Lists WooCommerce coupons with their discount type and usage stats. Supports an exact code lookup or a partial search.', 'mosmcp-abilities' ),
				'category'            => WooCommerce_Abilities_Loader::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'search'   => array(
							'type'        => 'string',
							'description' => __( 'Partial search term matched against coupon code.', 'mosmcp-abilities' ),
						),
						'code'     => array(
							'type'        => 'string',
							'description' => __( 'Exact coupon code lookup. Takes precedence over search.', 'mosmcp-abilities' ),
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
							'coupons' => array(
								'type'  => 'array',
								'items' => $coupon_item_schema,
							),
						),
						'additionalProperties' => false,
					),
					true
				),
				'execute_callback'    => array( __CLASS__, 'list_coupons' ),
				'permission_callback' => array( WooCommerce_Permissions::class, 'can_read_coupons' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
						'open_world'  => false,
					),
					'required_cap' => WooCommerce_Permissions::READ_COUPONS,
					'show_in_rest' => false,
				),
			)
		);
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	public static function list_coupons( $input = array() ) {
		$started_at = microtime( true );
		$input      = is_array( $input ) ? $input : array();

		if ( ! empty( $input['code'] ) ) {
			return self::list_by_exact_code( $input['code'], $started_at );
		}

		$pagination = WooCommerce_Validator::validate_pagination( $input );

		$query_args = array(
			'post_type'      => self::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => $pagination['per_page'],
			'paged'          => $pagination['page'],
			'fields'         => 'ids',
		);

		if ( ! empty( $input['search'] ) ) {
			$query_args['s'] = sanitize_text_field( (string) $input['search'] );
		}

		$query   = new WP_Query( $query_args );
		$coupons = array_map( array( WooCommerce_Helper::class, 'coupon_summary' ), $query->posts );
		$meta    = WooCommerce_Response::pagination_meta( (int) $query->found_posts, $pagination['page'], $pagination['per_page'] );

		WooCommerce_Helper::log( 'mosmcp/list-coupons', 'success', array( 'count' => count( $coupons ) ) );

		return WooCommerce_Response::success(
			'mosmcp/list-coupons',
			array( 'coupons' => $coupons ),
			sprintf(
				/* translators: %d: number of coupons returned */
				__( '%d coupon(s) retrieved.', 'mosmcp-abilities' ),
				count( $coupons )
			),
			$meta,
			$started_at
		);
	}

	/**
	 * @param mixed $raw_code
	 * @param float $started_at
	 * @return array<string, mixed>
	 */
	private static function list_by_exact_code( $raw_code, $started_at ) {
		$code      = sanitize_text_field( (string) $raw_code );
		$coupon_id = function_exists( 'wc_get_coupon_id_by_code' ) ? wc_get_coupon_id_by_code( $code ) : 0;
		$coupons   = $coupon_id ? array( WooCommerce_Helper::coupon_summary( $coupon_id ) ) : array();

		WooCommerce_Helper::log(
			'mosmcp/list-coupons',
			'success',
			array(
				'code'  => $code,
				'found' => (bool) $coupon_id,
			)
		);

		return WooCommerce_Response::success(
			'mosmcp/list-coupons',
			array( 'coupons' => $coupons ),
			sprintf(
				/* translators: %d: number of coupons returned */
				__( '%d coupon(s) retrieved.', 'mosmcp-abilities' ),
				count( $coupons )
			),
			WooCommerce_Response::pagination_meta( count( $coupons ), 1, max( 1, count( $coupons ) ) ),
			$started_at
		);
	}

	/**
	 * @param array<string, mixed> $coupon_item_schema
	 * @return void
	 */
	private static function register_get( array $coupon_item_schema ) {
		Naming::register_ability(
			'mosmcp/get-coupon',
			array(
				'label'               => __( 'Get Coupon', 'mosmcp-abilities' ),
				'description'         => __( 'Gets a single WooCommerce coupon by ID, including its restrictions and usage limits.', 'mosmcp-abilities' ),
				'category'            => WooCommerce_Abilities_Loader::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'id' ),
					'properties'           => array(
						'id' => array(
							'type'        => 'integer',
							'description' => __( 'The coupon ID (required).', 'mosmcp-abilities' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => WooCommerce_Response::envelope_schema( $coupon_item_schema ),
				'execute_callback'    => array( __CLASS__, 'get_coupon' ),
				'permission_callback' => array( WooCommerce_Permissions::class, 'can_read_coupons' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
						'open_world'  => false,
					),
					'required_cap' => WooCommerce_Permissions::READ_COUPONS,
					'show_in_rest' => false,
				),
			)
		);
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|WP_Error
	 */
	public static function get_coupon( $input = array() ) {
		$started_at = microtime( true );
		$input      = is_array( $input ) ? $input : array();

		$coupon_id = self::require_coupon_id( $input );
		if ( is_wp_error( $coupon_id ) ) {
			return $coupon_id;
		}

		WooCommerce_Helper::log( 'mosmcp/get-coupon', 'success', array( 'id' => $coupon_id ) );

		return WooCommerce_Response::success(
			'mosmcp/get-coupon',
			WooCommerce_Helper::coupon_summary( $coupon_id ),
			__( 'Coupon retrieved.', 'mosmcp-abilities' ),
			null,
			$started_at
		);
	}

	/*
	-------------------------------------------------------------------- *
	 * Create / Update
	 * -------------------------------------------------------------------- */

	/**
	 * @param array<string, mixed> $coupon_item_schema
	 * @return void
	 */
	private static function register_create( array $coupon_item_schema ) {
		$properties = array_merge(
			array(
				'code'   => array(
					'type'        => 'string',
					'description' => __( 'The coupon code (required).', 'mosmcp-abilities' ),
				),
				'amount' => array(
					'type'        => 'string',
					'description' => __( 'The discount amount (required).', 'mosmcp-abilities' ),
				),
			),
			self::writable_field_properties()
		);

		Naming::register_ability(
			'mosmcp/create-coupon',
			array(
				'label'               => __( 'Create Coupon', 'mosmcp-abilities' ),
				'description'         => __( 'Creates a WooCommerce discount coupon with a discount type, amount, and optional restrictions and usage limits.', 'mosmcp-abilities' ),
				'category'            => WooCommerce_Abilities_Loader::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'code', 'amount' ),
					'properties'           => $properties,
					'additionalProperties' => false,
				),
				'output_schema'       => WooCommerce_Response::envelope_schema( $coupon_item_schema ),
				'execute_callback'    => array( __CLASS__, 'create_coupon' ),
				'permission_callback' => array( WooCommerce_Permissions::class, 'can_create_coupons' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
						'open_world'  => false,
					),
					'required_cap' => WooCommerce_Permissions::CREATE_COUPONS,
					'show_in_rest' => false,
				),
			)
		);
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|WP_Error
	 */
	public static function create_coupon( $input = array() ) {
		$started_at = microtime( true );
		$input      = is_array( $input ) ? $input : array();

		$code = isset( $input['code'] ) ? sanitize_text_field( (string) $input['code'] ) : '';
		if ( '' === $code ) {
			return WooCommerce_Response::error( 'wcab_missing_code', __( 'A coupon code is required.', 'mosmcp-abilities' ) );
		}

		$amount = WooCommerce_Validator::validate_price( isset( $input['amount'] ) ? $input['amount'] : null, __( 'amount', 'mosmcp-abilities' ) );
		if ( is_wp_error( $amount ) ) {
			return $amount;
		}

		if ( function_exists( 'wc_get_coupon_id_by_code' ) && wc_get_coupon_id_by_code( $code ) ) {
			return WooCommerce_Response::error( 'wcab_coupon_exists', __( 'A coupon with that code already exists.', 'mosmcp-abilities' ) );
		}

		$coupon = new WC_Coupon();
		$coupon->set_code( $code );
		$coupon->set_amount( $amount );

		$result = self::apply_writable_fields( $coupon, $input );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! isset( $input['discount_type'] ) ) {
			$coupon->set_discount_type( 'percent' );
		}

		$coupon_id = $coupon->save();
		if ( ! $coupon_id ) {
			return WooCommerce_Response::error( 'wcab_coupon_create_failed', __( 'The coupon could not be created.', 'mosmcp-abilities' ) );
		}

		WooCommerce_Helper::log( 'mosmcp/create-coupon', 'success', array( 'id' => $coupon_id ) );

		return WooCommerce_Response::success(
			'mosmcp/create-coupon',
			WooCommerce_Helper::coupon_summary( (int) $coupon_id ),
			__( 'Coupon created.', 'mosmcp-abilities' ),
			null,
			$started_at
		);
	}

	/**
	 * @param array<string, mixed> $coupon_item_schema
	 * @return void
	 */
	private static function register_update( array $coupon_item_schema ) {
		$properties = array_merge(
			array(
				'id'     => array(
					'type'        => 'integer',
					'description' => __( 'The coupon ID to update (required).', 'mosmcp-abilities' ),
				),
				'code'   => array(
					'type'        => 'string',
					'description' => __( 'New coupon code.', 'mosmcp-abilities' ),
				),
				'amount' => array(
					'type'        => 'string',
					'description' => __( 'New discount amount.', 'mosmcp-abilities' ),
				),
			),
			self::writable_field_properties()
		);

		Naming::register_ability(
			'mosmcp/update-coupon',
			array(
				'label'               => __( 'Update Coupon', 'mosmcp-abilities' ),
				'description'         => __( 'Updates an existing coupon\'s amount, expiry, restrictions, and usage limits. Provide the coupon ID and at least one field to change.', 'mosmcp-abilities' ),
				'category'            => WooCommerce_Abilities_Loader::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'id' ),
					'properties'           => $properties,
					'additionalProperties' => false,
				),
				'output_schema'       => WooCommerce_Response::envelope_schema( $coupon_item_schema ),
				'execute_callback'    => array( __CLASS__, 'update_coupon' ),
				'permission_callback' => array( WooCommerce_Permissions::class, 'can_edit_coupons' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => true,
						'open_world'  => false,
					),
					'required_cap' => WooCommerce_Permissions::EDIT_COUPONS,
					'show_in_rest' => false,
				),
			)
		);
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|WP_Error
	 */
	public static function update_coupon( $input = array() ) {
		$started_at = microtime( true );
		$input      = is_array( $input ) ? $input : array();

		$coupon_id = self::require_coupon_id( $input );
		if ( is_wp_error( $coupon_id ) ) {
			return $coupon_id;
		}
		if ( ! WooCommerce_Permissions::can_edit_coupon( $coupon_id ) ) {
			return WooCommerce_Response::error( 'wcab_cannot_edit', __( 'You are not allowed to edit this coupon.', 'mosmcp-abilities' ) );
		}

		$fields = $input;
		unset( $fields['id'] );
		if ( empty( $fields ) ) {
			return WooCommerce_Response::nothing_to_update_error();
		}

		$coupon = new WC_Coupon( $coupon_id );

		if ( isset( $input['code'] ) ) {
			$code = sanitize_text_field( (string) $input['code'] );
			if ( '' === $code ) {
				return WooCommerce_Response::error( 'wcab_missing_code', __( 'code cannot be empty.', 'mosmcp-abilities' ) );
			}
			$existing_id = function_exists( 'wc_get_coupon_id_by_code' ) ? wc_get_coupon_id_by_code( $code ) : 0;
			if ( $existing_id && (int) $existing_id !== $coupon_id ) {
				return WooCommerce_Response::error( 'wcab_coupon_exists', __( 'Another coupon already uses that code.', 'mosmcp-abilities' ) );
			}
			$coupon->set_code( $code );
		}
		if ( isset( $input['amount'] ) ) {
			$amount = WooCommerce_Validator::validate_price( $input['amount'], __( 'amount', 'mosmcp-abilities' ) );
			if ( is_wp_error( $amount ) ) {
				return $amount;
			}
			$coupon->set_amount( $amount );
		}

		$result = self::apply_writable_fields( $coupon, $input );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$coupon->save();

		WooCommerce_Helper::log( 'mosmcp/update-coupon', 'success', array( 'id' => $coupon_id ) );

		return WooCommerce_Response::success(
			'mosmcp/update-coupon',
			WooCommerce_Helper::coupon_summary( $coupon_id ),
			__( 'Coupon updated.', 'mosmcp-abilities' ),
			null,
			$started_at
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
			'mosmcp/delete-coupon',
			array(
				'label'               => __( 'Delete Coupon', 'mosmcp-abilities' ),
				'description'         => __( 'Deletes a WooCommerce coupon. By default this moves it to the trash; pass force to delete it permanently, bypassing the trash.', 'mosmcp-abilities' ),
				'category'            => WooCommerce_Abilities_Loader::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'id' ),
					'properties'           => array(
						'id'    => array(
							'type'        => 'integer',
							'description' => __( 'The coupon ID to delete (required).', 'mosmcp-abilities' ),
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
				'execute_callback'    => array( __CLASS__, 'delete_coupon' ),
				'permission_callback' => array( WooCommerce_Permissions::class, 'can_delete_coupons' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => true,
						'open_world'  => false,
					),
					'required_cap' => WooCommerce_Permissions::DELETE_COUPONS,
					'show_in_rest' => false,
				),
			)
		);
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|WP_Error
	 */
	public static function delete_coupon( $input = array() ) {
		$started_at = microtime( true );
		$input      = is_array( $input ) ? $input : array();

		$coupon_id = self::require_coupon_id( $input );
		if ( is_wp_error( $coupon_id ) ) {
			return $coupon_id;
		}
		if ( ! WooCommerce_Permissions::can_delete_coupon( $coupon_id ) ) {
			return WooCommerce_Response::error( 'wcab_cannot_delete', __( 'You are not allowed to delete this coupon.', 'mosmcp-abilities' ) );
		}

		$coupon = new WC_Coupon( $coupon_id );
		$force  = ! empty( $input['force'] );
		$result = $coupon->delete( $force );

		if ( ! $result ) {
			return WooCommerce_Response::error( 'wcab_coupon_delete_failed', __( 'The coupon could not be deleted.', 'mosmcp-abilities' ) );
		}

		WooCommerce_Helper::log(
			'mosmcp/delete-coupon',
			'success',
			array(
				'id'    => $coupon_id,
				'force' => $force,
			)
		);

		return WooCommerce_Response::success(
			'mosmcp/delete-coupon',
			array(
				'id'     => $coupon_id,
				'status' => $force ? 'deleted' : 'trashed',
			),
			$force
				? __( 'Coupon permanently deleted.', 'mosmcp-abilities' )
				: __( 'Coupon moved to trash.', 'mosmcp-abilities' ),
			null,
			$started_at
		);
	}
}
