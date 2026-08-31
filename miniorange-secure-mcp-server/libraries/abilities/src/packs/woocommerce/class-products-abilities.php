<?php
/**
 * WooCommerce Products abilities: products, categories, tags, and attributes.
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

/*
 * These abilities intentionally query by post/user/comment meta or taxonomy
 * (and exclude specific IDs) — that is the tool surface the library exposes.
 * The queries are bounded and parameterized, so this performance advisory is
 * accepted here (the sniff is not part of the library's own phpcs.xml.dist; this
 * directive covers Plugin Check, which enforces its own broader standard).
 */
// phpcs:disable WordPressVIPMinimum.Performance.WPQueryParams

use WC_Product_Attribute;
use WC_Product_External;
use WC_Product_Simple;
use WP_Error;

/**
 * Class Products_Abilities
 *
 * Registers mosmcp/list-products, get-product, create-product,
 * update-product, delete-product, list-product-categories,
 * create-product-category, delete-product-category, list-product-tags,
 * create-product-tag, list-product-attributes, get-product-attribute, and
 * set-product-attributes. Field names deliberately mirror the WooCommerce
 * REST API v3 Product object so anything already familiar with that API
 * maps directly onto these abilities.
 *
 * create-product only builds 'simple' and 'external' products. 'variable'
 * and 'grouped' are deliberately left out of the creatable set: a variable
 * product with no attributes can't have meaningful variations, and a
 * grouped product with no grouped_products list groups nothing. Assigning
 * attributes via set-product-attributes is supported (including the
 * variation flag), but there are no variation-object abilities yet
 * (create/update/delete a WC_Product_Variation) â so a variable product
 * can be given attributes but not actual variations, through this plugin
 * alone. list-products can still filter by any type, including
 * variable/grouped products that already exist (e.g. created in wp-admin).
 */
class Products_Abilities {

	/**
	 * Product statuses a client may set through create/update.
	 */
	const STATUSES = array( 'draft', 'pending', 'private', 'publish' );

	/**
	 * All product types, for filtering an existing catalog.
	 */
	const TYPES = array( 'simple', 'variable', 'grouped', 'external' );

	/**
	 * Product types create-product can actually build correctly. See class docblock.
	 */
	const CREATABLE_TYPES = array( 'simple', 'external' );

	/**
	 * Stock statuses a client may set or filter by.
	 */
	const STOCK_STATUSES = array( 'instock', 'outofstock', 'onbackorder' );

	/**
	 * Fields wc_get_products() accepts for orderby.
	 */
	const ORDERBY_FIELDS = array( 'date', 'id', 'include', 'title', 'slug', 'modified', 'menu_order', 'price', 'popularity', 'rating' );

	/**
	 * Catalog visibility options.
	 */
	const CATALOG_VISIBILITY = array( 'visible', 'catalog', 'search', 'hidden' );

	/**
	 * Backorder handling options.
	 */
	const BACKORDER_OPTIONS = array( 'no', 'notify', 'yes' );

	/**
	 * Tax status options.
	 */
	const TAX_STATUSES = array( 'taxable', 'shipping', 'none' );

	/**
	 * Registers every Products ability. Called from {@see WooCommerce_Abilities_Loader}.
	 *
	 * @return void
	 */
	public static function register() {
		$term_schema = array(
			'type'                 => 'object',
			'properties'           => array(
				'id'   => array( 'type' => 'integer' ),
				'name' => array( 'type' => 'string' ),
				'slug' => array( 'type' => 'string' ),
			),
			'additionalProperties' => false,
		);

		$product_item_schema = array(
			'type'                 => 'object',
			'properties'           => array(
				'id'                 => array( 'type' => 'integer' ),
				'name'               => array( 'type' => 'string' ),
				'slug'               => array( 'type' => 'string' ),
				'type'               => array( 'type' => 'string' ),
				'status'             => array( 'type' => 'string' ),
				'featured'           => array( 'type' => 'boolean' ),
				'catalog_visibility' => array( 'type' => 'string' ),
				'description'        => array( 'type' => 'string' ),
				'short_description'  => array( 'type' => 'string' ),
				'sku'                => array( 'type' => 'string' ),
				'price'              => array( 'type' => 'string' ),
				'regular_price'      => array( 'type' => 'string' ),
				'sale_price'         => array( 'type' => 'string' ),
				'price_html'         => array( 'type' => 'string' ),
				'on_sale'            => array( 'type' => 'boolean' ),
				'purchasable'        => array( 'type' => 'boolean' ),
				'virtual'            => array( 'type' => 'boolean' ),
				'downloadable'       => array( 'type' => 'boolean' ),
				'manage_stock'       => array( 'type' => 'boolean' ),
				'stock_quantity'     => array( 'type' => array( 'integer', 'null' ) ),
				'stock_status'       => array( 'type' => 'string' ),
				'backorders'         => array( 'type' => 'string' ),
				'weight'             => array( 'type' => 'string' ),
				'dimensions'         => array(
					'type'                 => 'object',
					'properties'           => array(
						'length' => array( 'type' => 'string' ),
						'width'  => array( 'type' => 'string' ),
						'height' => array( 'type' => 'string' ),
					),
					'additionalProperties' => false,
				),
				'tax_status'         => array( 'type' => 'string' ),
				'tax_class'          => array( 'type' => 'string' ),
				'shipping_class'     => array( 'type' => 'string' ),
				'sold_individually'  => array( 'type' => 'boolean' ),
				'upsell_ids'         => array(
					'type'  => 'array',
					'items' => array( 'type' => 'integer' ),
				),
				'cross_sell_ids'     => array(
					'type'  => 'array',
					'items' => array( 'type' => 'integer' ),
				),
				'purchase_note'      => array( 'type' => 'string' ),
				'menu_order'         => array( 'type' => 'integer' ),
				'categories'         => array(
					'type'  => 'array',
					'items' => $term_schema,
				),
				'tags'               => array(
					'type'  => 'array',
					'items' => $term_schema,
				),
				'images'             => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'properties'           => array(
							'id'  => array( 'type' => 'integer' ),
							'src' => array( 'type' => 'string' ),
							'alt' => array( 'type' => 'string' ),
						),
						'additionalProperties' => false,
					),
				),
				'date_created'       => array( 'type' => array( 'string', 'null' ) ),
				'date_modified'      => array( 'type' => array( 'string', 'null' ) ),
				'permalink'          => array( 'type' => 'string' ),
			),
			'additionalProperties' => false,
		);

		self::register_list( $product_item_schema );
		self::register_get( $product_item_schema );
		self::register_create( $product_item_schema );
		self::register_update( $product_item_schema );
		self::register_delete();
		self::register_list_categories();
		self::register_create_category();
		self::register_delete_category();
		self::register_list_tags();
		self::register_create_tag();
		self::register_list_attributes();
		self::register_get_attribute();
		self::register_set_attributes();
	}

	/**
	 * Input schema properties shared by create-product and update-product â
	 * everything except name/type (create-only) and id (update-only).
	 *
	 * @return array<string, mixed>
	 */
	private static function writable_field_properties() {
		return array(
			'slug'               => array(
				'type'        => 'string',
				'description' => __( 'URL slug.', 'mosmcp-abilities' ),
			),
			'regular_price'      => array(
				'type'        => 'string',
				'description' => __( 'Regular price.', 'mosmcp-abilities' ),
			),
			'sale_price'         => array(
				'type'        => 'string',
				'description' => __( 'Sale price. Pass an empty string to clear it.', 'mosmcp-abilities' ),
			),
			'sku'                => array(
				'type'        => 'string',
				'description' => __( 'Stock keeping unit.', 'mosmcp-abilities' ),
			),
			'description'        => array(
				'type'        => 'string',
				'description' => __( 'Full product description. Basic HTML is allowed.', 'mosmcp-abilities' ),
			),
			'short_description'  => array(
				'type'        => 'string',
				'description' => __( 'Short product description. Basic HTML is allowed.', 'mosmcp-abilities' ),
			),
			'featured'           => array(
				'type'        => 'boolean',
				'description' => __( 'Whether the product is featured.', 'mosmcp-abilities' ),
			),
			'catalog_visibility' => array(
				'type'        => 'string',
				'enum'        => self::CATALOG_VISIBILITY,
				'description' => __( 'Catalog visibility.', 'mosmcp-abilities' ),
			),
			'virtual'            => array(
				'type'        => 'boolean',
				'description' => __( 'Whether the product is virtual (no shipping required).', 'mosmcp-abilities' ),
			),
			'downloadable'       => array(
				'type'        => 'boolean',
				'description' => __( 'Whether the product is downloadable.', 'mosmcp-abilities' ),
			),
			'manage_stock'       => array(
				'type'        => 'boolean',
				'description' => __( 'Whether WooCommerce should track stock for this product.', 'mosmcp-abilities' ),
			),
			'stock_quantity'     => array(
				'type'        => 'integer',
				'description' => __( 'Stock quantity. Only used when manage_stock is true.', 'mosmcp-abilities' ),
			),
			'stock_status'       => array(
				'type'        => 'string',
				'enum'        => self::STOCK_STATUSES,
				'description' => __( 'Stock status.', 'mosmcp-abilities' ),
			),
			'backorders'         => array(
				'type'        => 'string',
				'enum'        => self::BACKORDER_OPTIONS,
				'description' => __( 'Backorder handling.', 'mosmcp-abilities' ),
			),
			'sold_individually'  => array(
				'type'        => 'boolean',
				'description' => __( 'Limit purchases to one item per order.', 'mosmcp-abilities' ),
			),
			'weight'             => array(
				'type'        => 'string',
				'description' => __( "Weight, in the store's configured weight unit.", 'mosmcp-abilities' ),
			),
			'dimensions'         => array(
				'type'                 => 'object',
				'properties'           => array(
					'length' => array( 'type' => 'string' ),
					'width'  => array( 'type' => 'string' ),
					'height' => array( 'type' => 'string' ),
				),
				'additionalProperties' => false,
				'description'          => __( "Dimensions, in the store's configured dimension unit.", 'mosmcp-abilities' ),
			),
			'tax_status'         => array(
				'type'        => 'string',
				'enum'        => self::TAX_STATUSES,
				'description' => __( 'Tax status.', 'mosmcp-abilities' ),
			),
			'tax_class'          => array(
				'type'        => 'string',
				'description' => __( 'Tax class slug. Empty string for the standard class.', 'mosmcp-abilities' ),
			),
			'category_ids'       => array(
				'type'        => 'array',
				'items'       => array( 'type' => 'integer' ),
				'description' => __( 'Product category term IDs to assign. Replaces the existing set entirely; pass an empty array to remove all categories.', 'mosmcp-abilities' ),
			),
			'tag_ids'            => array(
				'type'        => 'array',
				'items'       => array( 'type' => 'integer' ),
				'description' => __( 'Product tag term IDs to assign. Replaces the existing set entirely; pass an empty array to remove all tags.', 'mosmcp-abilities' ),
			),
			'image_ids'          => array(
				'type'        => 'array',
				'items'       => array( 'type' => 'integer' ),
				'description' => __( 'Media library attachment IDs. The first is used as the featured image, the rest as the gallery. Replaces the existing set entirely; pass an empty array to remove the featured image and gallery.', 'mosmcp-abilities' ),
			),
			'upsell_ids'         => array(
				'type'        => 'array',
				'items'       => array( 'type' => 'integer' ),
				'description' => __( 'Upsell product IDs.', 'mosmcp-abilities' ),
			),
			'cross_sell_ids'     => array(
				'type'        => 'array',
				'items'       => array( 'type' => 'integer' ),
				'description' => __( 'Cross-sell product IDs.', 'mosmcp-abilities' ),
			),
			'purchase_note'      => array(
				'type'        => 'string',
				'description' => __( 'Note shown to the customer after purchase.', 'mosmcp-abilities' ),
			),
			'menu_order'         => array(
				'type'        => 'integer',
				'description' => __( 'Custom sort order.', 'mosmcp-abilities' ),
			),
			'external_url'       => array(
				'type'        => 'string',
				'description' => __( 'Product URL. Applies to external products only.', 'mosmcp-abilities' ),
			),
			'button_text'        => array(
				'type'        => 'string',
				'description' => __( 'Buy button label. Applies to external products only.', 'mosmcp-abilities' ),
			),
		);
	}

	/**
	 * @param array<string, mixed> $product_item_schema
	 * @return void
	 */
	private static function register_list( array $product_item_schema ) {
		Naming::register_ability(
			'mosmcp/list-products',
			array(
				'label'               => __( 'List Products', 'mosmcp-abilities' ),
				'description'         => __( 'Lists WooCommerce products with filtering by search, SKU, status, featured flag, type, category, tag, stock status, price range, sale status, virtual/downloadable flags, slug, IDs, and modified-date range.', 'mosmcp-abilities' ),
				'category'            => WooCommerce_Abilities_Loader::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'search'       => array(
							'type'        => 'string',
							'description' => __( 'Search term matched against product title.', 'mosmcp-abilities' ),
						),
						'slug'         => array(
							'type'        => 'string',
							'description' => __( 'Exact product slug.', 'mosmcp-abilities' ),
						),
						'sku'          => array(
							'type'        => 'string',
							'description' => __( 'Search term matched against SKU.', 'mosmcp-abilities' ),
						),
						'status'       => array(
							'type'        => 'string',
							'default'     => 'publish',
							'description' => __( 'Product status to filter by, or "any".', 'mosmcp-abilities' ),
						),
						'featured'     => array(
							'type'        => 'boolean',
							'description' => __( 'Filter to only featured (or only non-featured) products.', 'mosmcp-abilities' ),
						),
						'on_sale'      => array(
							'type'        => 'boolean',
							'description' => __( 'Filter to only products currently on sale.', 'mosmcp-abilities' ),
						),
						'virtual'      => array(
							'type'        => 'boolean',
							'description' => __( 'Filter by the virtual flag.', 'mosmcp-abilities' ),
						),
						'downloadable' => array(
							'type'        => 'boolean',
							'description' => __( 'Filter by the downloadable flag.', 'mosmcp-abilities' ),
						),
						'type'         => array(
							'type'        => 'string',
							'enum'        => self::TYPES,
							'description' => __( 'Filter by product type.', 'mosmcp-abilities' ),
						),
						'category'     => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => __( 'Product category slugs to filter by.', 'mosmcp-abilities' ),
						),
						'tag'          => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => __( 'Product tag slugs to filter by.', 'mosmcp-abilities' ),
						),
						'stock_status' => array(
							'type'        => 'string',
							'enum'        => self::STOCK_STATUSES,
							'description' => __( 'Filter by stock status.', 'mosmcp-abilities' ),
						),
						'price_min'    => array(
							'type'        => 'number',
							'description' => __( 'Minimum price (inclusive).', 'mosmcp-abilities' ),
						),
						'price_max'    => array(
							'type'        => 'number',
							'description' => __( 'Maximum price (inclusive).', 'mosmcp-abilities' ),
						),
						'include'      => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
							'description' => __( 'Limit results to these specific product IDs.', 'mosmcp-abilities' ),
						),
						'exclude'      => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
							'description' => __( 'Exclude these specific product IDs from the results.', 'mosmcp-abilities' ),
						),
						'after'        => array(
							'type'        => 'string',
							'description' => __( 'Only include products created on or after this date (YYYY-MM-DD).', 'mosmcp-abilities' ),
						),
						'before'       => array(
							'type'        => 'string',
							'description' => __( 'Only include products created on or before this date (YYYY-MM-DD).', 'mosmcp-abilities' ),
						),
						'orderby'      => array(
							'type'        => 'string',
							'enum'        => self::ORDERBY_FIELDS,
							'default'     => 'date',
							'description' => __( 'Field to order results by.', 'mosmcp-abilities' ),
						),
						'order'        => array(
							'type'        => 'string',
							'enum'        => array( 'ASC', 'DESC' ),
							'default'     => 'DESC',
							'description' => __( 'Sort direction.', 'mosmcp-abilities' ),
						),
						'page'         => array(
							'type'        => 'integer',
							'default'     => 1,
							'description' => __( 'Page number, for pagination.', 'mosmcp-abilities' ),
						),
						'per_page'     => array(
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
							'products' => array(
								'type'  => 'array',
								'items' => $product_item_schema,
							),
						),
						'additionalProperties' => false,
					),
					true
				),
				'execute_callback'    => array( __CLASS__, 'list_products' ),
				'permission_callback' => array( WooCommerce_Permissions::class, 'can_read_products' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
						'open_world'  => false,
					),
					'required_cap' => WooCommerce_Permissions::READ_PRODUCTS,
					'show_in_rest' => false,
				),
			)
		);
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|WP_Error
	 */
	public static function list_products( $input = array() ) {
		$started_at = microtime( true );
		$input      = is_array( $input ) ? $input : array();

		$pagination = WooCommerce_Validator::validate_pagination( $input );

		$args = array(
			'status'   => isset( $input['status'] ) ? sanitize_key( (string) $input['status'] ) : 'publish',
			'page'     => $pagination['page'],
			'limit'    => $pagination['per_page'],
			'orderby'  => isset( $input['orderby'] ) ? sanitize_key( (string) $input['orderby'] ) : 'date',
			'order'    => isset( $input['order'] ) && 'ASC' === strtoupper( (string) $input['order'] ) ? 'ASC' : 'DESC',
			'paginate' => true,
			'return'   => 'objects',
		);

		if ( ! empty( $input['category'] ) ) {
			$args['category'] = WooCommerce_Validator::validate_slug_array( $input['category'] );
		}
		if ( ! empty( $input['tag'] ) ) {
			$args['tag'] = WooCommerce_Validator::validate_slug_array( $input['tag'] );
		}
		if ( ! empty( $input['type'] ) ) {
			$args['type'] = sanitize_key( (string) $input['type'] );
		}
		if ( ! empty( $input['stock_status'] ) ) {
			$args['stock_status'] = sanitize_key( (string) $input['stock_status'] );
		}
		if ( isset( $input['featured'] ) ) {
			$args['featured'] = (bool) $input['featured'];
		}
		if ( ! empty( $input['search'] ) ) {
			$args['s'] = sanitize_text_field( (string) $input['search'] );
		}
		if ( ! empty( $input['include'] ) ) {
			$args['include'] = WooCommerce_Validator::validate_id_array( $input['include'] );
		}
		if ( ! empty( $input['exclude'] ) ) {
			$args['exclude'] = WooCommerce_Validator::validate_id_array( $input['exclude'] );
		}
		// Relies on wc_get_products() passing 'on_sale' and 'name' (slug) through
		// to the underlying product query; verify against a live store if either
		// filter appears to have no effect.
		if ( isset( $input['on_sale'] ) ) {
			$args['on_sale'] = (bool) $input['on_sale'];
		}
		if ( ! empty( $input['slug'] ) ) {
			$args['name'] = sanitize_title( (string) $input['slug'] );
		}

		$date_range = WooCommerce_Validator::validate_date_range( $input );
		if ( is_wp_error( $date_range ) ) {
			return $date_range;
		}
		if ( '' !== $date_range ) {
			$args['date_created'] = $date_range;
		}

		$meta_query = self::build_meta_query_filters( $input );
		if ( ! empty( $meta_query ) ) {
			$args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}

		$result   = wc_get_products( $args );
		$products = array_map( array( WooCommerce_Helper::class, 'product_summary' ), $result->products );
		$meta     = WooCommerce_Response::pagination_meta( $result->total, $pagination['page'], $pagination['per_page'] );

		WooCommerce_Helper::log( 'mosmcp/list-products', 'success', array( 'count' => count( $products ) ) );

		return WooCommerce_Response::success(
			'mosmcp/list-products',
			array( 'products' => $products ),
			sprintf(
				/* translators: %d: number of products returned */
				__( '%d product(s) retrieved.', 'mosmcp-abilities' ),
				count( $products )
			),
			$meta,
			$started_at
		);
	}

	/**
	 * Builds the meta_query for sku, price_min/price_max, virtual, and
	 * downloadable â filters built on plain postmeta rather than a wc_get_products()
	 * native query var, since _virtual/_downloadable/_sku/_price are guaranteed
	 * WooCommerce postmeta keys regardless of query-var support across versions.
	 *
	 * @param array<string, mixed> $input
	 * @return array<int, array<string, mixed>>
	 */
	private static function build_meta_query_filters( array $input ) {
		$meta_query = array();

		if ( ! empty( $input['sku'] ) ) {
			$meta_query[] = array(
				'key'     => '_sku',
				'value'   => sanitize_text_field( (string) $input['sku'] ),
				'compare' => 'LIKE',
			);
		}

		if ( isset( $input['virtual'] ) ) {
			$meta_query[] = array(
				'key'   => '_virtual',
				'value' => $input['virtual'] ? 'yes' : 'no',
			);
		}
		if ( isset( $input['downloadable'] ) ) {
			$meta_query[] = array(
				'key'   => '_downloadable',
				'value' => $input['downloadable'] ? 'yes' : 'no',
			);
		}

		$has_min = isset( $input['price_min'] ) && is_numeric( $input['price_min'] );
		$has_max = isset( $input['price_max'] ) && is_numeric( $input['price_max'] );

		if ( $has_min && $has_max ) {
			$meta_query[] = array(
				'key'     => '_price',
				'value'   => array( (float) $input['price_min'], (float) $input['price_max'] ),
				'compare' => 'BETWEEN',
				'type'    => 'NUMERIC',
			);
		} elseif ( $has_min ) {
			$meta_query[] = array(
				'key'     => '_price',
				'value'   => (float) $input['price_min'],
				'compare' => '>=',
				'type'    => 'NUMERIC',
			);
		} elseif ( $has_max ) {
			$meta_query[] = array(
				'key'     => '_price',
				'value'   => (float) $input['price_max'],
				'compare' => '<=',
				'type'    => 'NUMERIC',
			);
		}

		return $meta_query;
	}

	/**
	 * @param array<string, mixed> $product_item_schema
	 * @return void
	 */
	private static function register_get( array $product_item_schema ) {
		Naming::register_ability(
			'mosmcp/get-product',
			array(
				'label'               => __( 'Get Product', 'mosmcp-abilities' ),
				'description'         => __( 'Gets a single WooCommerce product by ID, including price, stock, images, categories/tags, and shipping detail.', 'mosmcp-abilities' ),
				'category'            => WooCommerce_Abilities_Loader::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'id' ),
					'properties'           => array(
						'id' => array(
							'type'        => 'integer',
							'description' => __( 'The product ID (required).', 'mosmcp-abilities' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => WooCommerce_Response::envelope_schema( $product_item_schema ),
				'execute_callback'    => array( __CLASS__, 'get_product' ),
				'permission_callback' => array( WooCommerce_Permissions::class, 'can_read_products' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
						'open_world'  => false,
					),
					'required_cap' => WooCommerce_Permissions::READ_PRODUCTS,
					'show_in_rest' => false,
				),
			)
		);
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|WP_Error
	 */
	public static function get_product( $input = array() ) {
		$started_at = microtime( true );
		$input      = is_array( $input ) ? $input : array();

		$product_id = WooCommerce_Validator::validate_id( isset( $input['id'] ) ? $input['id'] : null, __( 'product ID', 'mosmcp-abilities' ) );
		if ( is_wp_error( $product_id ) ) {
			return $product_id;
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			WooCommerce_Helper::log( 'mosmcp/get-product', 'failure', array( 'id' => $product_id ) );
			return WooCommerce_Response::error( 'wcab_product_not_found', __( 'No product was found with that ID.', 'mosmcp-abilities' ) );
		}

		WooCommerce_Helper::log( 'mosmcp/get-product', 'success', array( 'id' => $product_id ) );

		return WooCommerce_Response::success(
			'mosmcp/get-product',
			WooCommerce_Helper::product_summary( $product ),
			__( 'Product retrieved.', 'mosmcp-abilities' ),
			null,
			$started_at
		);
	}

	/**
	 * @param array<string, mixed> $product_item_schema
	 * @return void
	 */
	private static function register_create( array $product_item_schema ) {
		$properties = array_merge(
			array(
				'name'   => array(
					'type'        => 'string',
					'description' => __( 'The product name (required).', 'mosmcp-abilities' ),
				),
				'type'   => array(
					'type'        => 'string',
					'enum'        => self::CREATABLE_TYPES,
					'default'     => 'simple',
					'description' => __( 'Product type. Defaults to simple. Variable and grouped products are not yet supported by this ability.', 'mosmcp-abilities' ),
				),
				'status' => array(
					'type'        => 'string',
					'enum'        => self::STATUSES,
					'default'     => 'draft',
					'description' => __( 'Product status. Defaults to draft.', 'mosmcp-abilities' ),
				),
			),
			self::writable_field_properties()
		);

		Naming::register_ability(
			'mosmcp/create-product',
			array(
				'label'               => __( 'Create Product', 'mosmcp-abilities' ),
				'description'         => __( 'Creates a new simple or external WooCommerce product. Defaults to a draft simple product.', 'mosmcp-abilities' ),
				'category'            => WooCommerce_Abilities_Loader::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'name' ),
					'properties'           => $properties,
					'additionalProperties' => false,
				),
				'output_schema'       => WooCommerce_Response::envelope_schema( $product_item_schema ),
				'execute_callback'    => array( __CLASS__, 'create_product' ),
				'permission_callback' => array( WooCommerce_Permissions::class, 'can_create_products' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
						'open_world'  => true,
					),
					'required_cap' => WooCommerce_Permissions::CREATE_PRODUCTS,
					'show_in_rest' => false,
				),
			)
		);
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|WP_Error
	 */
	public static function create_product( $input = array() ) {
		$started_at = microtime( true );
		$input      = is_array( $input ) ? $input : array();

		$name = isset( $input['name'] ) ? sanitize_text_field( (string) $input['name'] ) : '';
		if ( '' === $name ) {
			return WooCommerce_Response::error( 'wcab_missing_name', __( 'A product name is required.', 'mosmcp-abilities' ) );
		}

		$type = WooCommerce_Validator::validate_enum( isset( $input['type'] ) ? $input['type'] : null, self::CREATABLE_TYPES, __( 'type', 'mosmcp-abilities' ), 'simple' );
		if ( is_wp_error( $type ) ) {
			return $type;
		}

		$product = 'external' === $type ? new WC_Product_External() : new WC_Product_Simple();
		$product->set_name( $name );

		if ( 'external' === $type ) {
			$external_url = isset( $input['external_url'] ) ? esc_url_raw( (string) $input['external_url'] ) : '';
			if ( '' === $external_url ) {
				return WooCommerce_Response::error( 'wcab_missing_external_url', __( 'external_url is required for external products.', 'mosmcp-abilities' ) );
			}
			$product->set_product_url( $external_url );
		}

		$result = self::apply_writable_fields( $product, $input );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$status = WooCommerce_Validator::validate_enum( isset( $input['status'] ) ? $input['status'] : null, self::STATUSES, __( 'status', 'mosmcp-abilities' ), 'draft' );
		if ( is_wp_error( $status ) ) {
			return $status;
		}
		if ( 'publish' === $status && ! current_user_can( WooCommerce_Permissions::CREATE_PRODUCTS ) ) {
			return WooCommerce_Response::error( 'wcab_cannot_publish', __( 'You are not allowed to publish products.', 'mosmcp-abilities' ) );
		}
		$product->set_status( $status );

		$product_id = $product->save();
		if ( ! $product_id ) {
			WooCommerce_Helper::log( 'mosmcp/create-product', 'failure', array() );
			return WooCommerce_Response::error( 'wcab_product_create_failed', __( 'The product could not be created.', 'mosmcp-abilities' ) );
		}

		WooCommerce_Helper::log( 'mosmcp/create-product', 'success', array( 'id' => $product_id ) );

		return WooCommerce_Response::success(
			'mosmcp/create-product',
			WooCommerce_Helper::product_summary( wc_get_product( $product_id ) ),
			__( 'Product created.', 'mosmcp-abilities' ),
			null,
			$started_at
		);
	}

	/**
	 * @param array<string, mixed> $product_item_schema
	 * @return void
	 */
	private static function register_update( array $product_item_schema ) {
		$properties = array_merge(
			array(
				'id'     => array(
					'type'        => 'integer',
					'description' => __( 'The product ID to update (required).', 'mosmcp-abilities' ),
				),
				'name'   => array(
					'type'        => 'string',
					'description' => __( 'New product name.', 'mosmcp-abilities' ),
				),
				'status' => array(
					'type'        => 'string',
					'enum'        => self::STATUSES,
					'description' => __( 'New product status.', 'mosmcp-abilities' ),
				),
			),
			self::writable_field_properties()
		);

		Naming::register_ability(
			'mosmcp/update-product',
			array(
				'label'               => __( 'Update Product', 'mosmcp-abilities' ),
				'description'         => __( "Updates an existing product's name, description, pricing, stock, shipping, taxonomy, or status. Provide the product ID and at least one field to change.", 'mosmcp-abilities' ),
				'category'            => WooCommerce_Abilities_Loader::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'id' ),
					'properties'           => $properties,
					'additionalProperties' => false,
				),
				'output_schema'       => WooCommerce_Response::envelope_schema( $product_item_schema ),
				'execute_callback'    => array( __CLASS__, 'update_product' ),
				'permission_callback' => array( WooCommerce_Permissions::class, 'can_edit_products' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => true,
						'open_world'  => true,
					),
					'required_cap' => WooCommerce_Permissions::EDIT_PRODUCTS,
					'show_in_rest' => false,
				),
			)
		);
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|WP_Error
	 */
	public static function update_product( $input = array() ) {
		$started_at = microtime( true );
		$input      = is_array( $input ) ? $input : array();

		$product_id = WooCommerce_Validator::validate_id( isset( $input['id'] ) ? $input['id'] : null, __( 'product ID', 'mosmcp-abilities' ) );
		if ( is_wp_error( $product_id ) ) {
			return $product_id;
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return WooCommerce_Response::error( 'wcab_product_not_found', __( 'No product was found with that ID.', 'mosmcp-abilities' ) );
		}
		if ( ! WooCommerce_Permissions::can_edit_product( $product_id ) ) {
			return WooCommerce_Response::error( 'wcab_cannot_edit', __( 'You are not allowed to edit this product.', 'mosmcp-abilities' ) );
		}

		$fields = $input;
		unset( $fields['id'], $fields['status'] );
		if ( empty( $fields ) && ! isset( $input['status'] ) ) {
			return WooCommerce_Response::error( 'wcab_nothing_to_update', __( 'Provide at least one field to update.', 'mosmcp-abilities' ) );
		}

		$result = self::apply_writable_fields( $product, $fields );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( isset( $input['status'] ) ) {
			$status = WooCommerce_Validator::validate_enum( $input['status'], self::STATUSES, __( 'status', 'mosmcp-abilities' ) );
			if ( is_wp_error( $status ) ) {
				return $status;
			}
			if ( 'publish' === $status && ! current_user_can( WooCommerce_Permissions::CREATE_PRODUCTS ) ) {
				return WooCommerce_Response::error( 'wcab_cannot_publish', __( 'You are not allowed to publish products.', 'mosmcp-abilities' ) );
			}
			$product->set_status( $status );
		}

		$product->save();

		WooCommerce_Helper::log( 'mosmcp/update-product', 'success', array( 'id' => $product_id ) );

		return WooCommerce_Response::success(
			'mosmcp/update-product',
			WooCommerce_Helper::product_summary( $product ),
			__( 'Product updated.', 'mosmcp-abilities' ),
			null,
			$started_at
		);
	}

	/**
	 * Applies whichever writable fields are present in $input to $product.
	 * Shared by create_product() and update_product() â every field here maps
	 * 1:1 onto a WooCommerce REST API v3 Product property, so the WC_Product
	 * getter/setter pair is guaranteed to exist for each one.
	 *
	 * @param \WC_Product          $product The product being written to.
	 * @param array<string, mixed> $input   The ability input (writable fields only).
	 * @return true|WP_Error
	 */
	private static function apply_writable_fields( $product, array $input ) {
		if ( isset( $input['slug'] ) ) {
			$product->set_slug( sanitize_title( (string) $input['slug'] ) );
		}
		if ( isset( $input['sku'] ) ) {
			$product->set_sku( sanitize_text_field( (string) $input['sku'] ) );
		}
		if ( isset( $input['description'] ) ) {
			$product->set_description( wp_kses_post( (string) $input['description'] ) );
		}
		if ( isset( $input['short_description'] ) ) {
			$product->set_short_description( wp_kses_post( (string) $input['short_description'] ) );
		}
		if ( isset( $input['regular_price'] ) ) {
			$price = WooCommerce_Validator::validate_price( $input['regular_price'], __( 'regular_price', 'mosmcp-abilities' ) );
			if ( is_wp_error( $price ) ) {
				return $price;
			}
			$product->set_regular_price( $price );
		}
		if ( isset( $input['sale_price'] ) ) {
			$sale_price = sanitize_text_field( (string) $input['sale_price'] );
			if ( '' !== $sale_price && ! is_numeric( $sale_price ) ) {
				return WooCommerce_Response::error( 'wcab_invalid_price', __( 'sale_price must be numeric, or an empty string to clear it.', 'mosmcp-abilities' ) );
			}
			$product->set_sale_price( $sale_price );
		}
		if ( isset( $input['featured'] ) ) {
			$product->set_featured( (bool) $input['featured'] );
		}
		if ( isset( $input['catalog_visibility'] ) ) {
			$visibility = WooCommerce_Validator::validate_enum( $input['catalog_visibility'], self::CATALOG_VISIBILITY, __( 'catalog_visibility', 'mosmcp-abilities' ) );
			if ( is_wp_error( $visibility ) ) {
				return $visibility;
			}
			$product->set_catalog_visibility( $visibility );
		}
		if ( isset( $input['virtual'] ) ) {
			$product->set_virtual( (bool) $input['virtual'] );
		}
		if ( isset( $input['downloadable'] ) ) {
			$product->set_downloadable( (bool) $input['downloadable'] );
		}
		if ( isset( $input['manage_stock'] ) ) {
			$product->set_manage_stock( (bool) $input['manage_stock'] );
		}
		if ( isset( $input['stock_quantity'] ) ) {
			$product->set_stock_quantity( (int) $input['stock_quantity'] );
		}
		if ( isset( $input['stock_status'] ) ) {
			$stock_status = WooCommerce_Validator::validate_enum( $input['stock_status'], self::STOCK_STATUSES, __( 'stock_status', 'mosmcp-abilities' ) );
			if ( is_wp_error( $stock_status ) ) {
				return $stock_status;
			}
			$product->set_stock_status( $stock_status );
		}
		if ( isset( $input['backorders'] ) ) {
			$backorders = WooCommerce_Validator::validate_enum( $input['backorders'], self::BACKORDER_OPTIONS, __( 'backorders', 'mosmcp-abilities' ) );
			if ( is_wp_error( $backorders ) ) {
				return $backorders;
			}
			$product->set_backorders( $backorders );
		}
		if ( isset( $input['sold_individually'] ) ) {
			$product->set_sold_individually( (bool) $input['sold_individually'] );
		}
		if ( isset( $input['weight'] ) ) {
			$product->set_weight( sanitize_text_field( (string) $input['weight'] ) );
		}
		if ( isset( $input['dimensions'] ) && is_array( $input['dimensions'] ) ) {
			if ( isset( $input['dimensions']['length'] ) ) {
				$product->set_length( sanitize_text_field( (string) $input['dimensions']['length'] ) );
			}
			if ( isset( $input['dimensions']['width'] ) ) {
				$product->set_width( sanitize_text_field( (string) $input['dimensions']['width'] ) );
			}
			if ( isset( $input['dimensions']['height'] ) ) {
				$product->set_height( sanitize_text_field( (string) $input['dimensions']['height'] ) );
			}
		}
		if ( isset( $input['tax_status'] ) ) {
			$tax_status = WooCommerce_Validator::validate_enum( $input['tax_status'], self::TAX_STATUSES, __( 'tax_status', 'mosmcp-abilities' ) );
			if ( is_wp_error( $tax_status ) ) {
				return $tax_status;
			}
			$product->set_tax_status( $tax_status );
		}
		if ( isset( $input['tax_class'] ) ) {
			$product->set_tax_class( sanitize_text_field( (string) $input['tax_class'] ) );
		}
		if ( isset( $input['category_ids'] ) ) {
			$product->set_category_ids( WooCommerce_Validator::validate_id_array( $input['category_ids'] ) );
		}
		if ( isset( $input['tag_ids'] ) ) {
			$product->set_tag_ids( WooCommerce_Validator::validate_id_array( $input['tag_ids'] ) );
		}
		if ( isset( $input['image_ids'] ) ) {
			// An explicit empty array clears both the featured image and gallery,
			// not just a no-op â needed so update-product can remove images, not
			// only add/replace them.
			$image_ids = WooCommerce_Validator::validate_id_array( $input['image_ids'] );
			$product->set_image_id( ! empty( $image_ids ) ? array_shift( $image_ids ) : 0 );
			$product->set_gallery_image_ids( $image_ids );
		}
		if ( isset( $input['upsell_ids'] ) ) {
			$product->set_upsell_ids( WooCommerce_Validator::validate_id_array( $input['upsell_ids'] ) );
		}
		if ( isset( $input['cross_sell_ids'] ) ) {
			$product->set_cross_sell_ids( WooCommerce_Validator::validate_id_array( $input['cross_sell_ids'] ) );
		}
		if ( isset( $input['purchase_note'] ) ) {
			$product->set_purchase_note( wp_kses_post( (string) $input['purchase_note'] ) );
		}
		if ( isset( $input['menu_order'] ) ) {
			$product->set_menu_order( (int) $input['menu_order'] );
		}
		// set_product_url()/set_button_text() only exist on WC_Product_External.
		if ( isset( $input['external_url'] ) && method_exists( $product, 'set_product_url' ) ) {
			$product->set_product_url( esc_url_raw( (string) $input['external_url'] ) );
		}
		if ( isset( $input['button_text'] ) && method_exists( $product, 'set_button_text' ) ) {
			$product->set_button_text( sanitize_text_field( (string) $input['button_text'] ) );
		}

		return true;
	}

	/**
	 * @return void
	 */
	private static function register_delete() {
		Naming::register_ability(
			'mosmcp/delete-product',
			array(
				'label'               => __( 'Delete Product', 'mosmcp-abilities' ),
				'description'         => __( 'Deletes a WooCommerce product. By default this moves it to the trash; pass force to delete it permanently, bypassing the trash.', 'mosmcp-abilities' ),
				'category'            => WooCommerce_Abilities_Loader::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'id' ),
					'properties'           => array(
						'id'    => array(
							'type'        => 'integer',
							'description' => __( 'The product ID to delete (required).', 'mosmcp-abilities' ),
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
				'execute_callback'    => array( __CLASS__, 'delete_product' ),
				'permission_callback' => array( WooCommerce_Permissions::class, 'can_delete_products' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => true,
						'open_world'  => true,
					),
					'required_cap' => WooCommerce_Permissions::DELETE_PRODUCTS,
					'show_in_rest' => false,
				),
			)
		);
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|WP_Error
	 */
	public static function delete_product( $input = array() ) {
		$started_at = microtime( true );
		$input      = is_array( $input ) ? $input : array();

		$product_id = WooCommerce_Validator::validate_id( isset( $input['id'] ) ? $input['id'] : null, __( 'product ID', 'mosmcp-abilities' ) );
		if ( is_wp_error( $product_id ) ) {
			return $product_id;
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return WooCommerce_Response::error( 'wcab_product_not_found', __( 'No product was found with that ID.', 'mosmcp-abilities' ) );
		}
		if ( ! WooCommerce_Permissions::can_delete_product( $product_id ) ) {
			return WooCommerce_Response::error( 'wcab_cannot_delete', __( 'You are not allowed to delete this product.', 'mosmcp-abilities' ) );
		}

		$force  = ! empty( $input['force'] );
		$result = $product->delete( $force );

		if ( ! $result ) {
			WooCommerce_Helper::log( 'mosmcp/delete-product', 'failure', array( 'id' => $product_id ) );
			return WooCommerce_Response::error( 'wcab_product_delete_failed', __( 'The product could not be deleted.', 'mosmcp-abilities' ) );
		}

		WooCommerce_Helper::log(
			'mosmcp/delete-product',
			'success',
			array(
				'id'    => $product_id,
				'force' => $force,
			)
		);

		return WooCommerce_Response::success(
			'mosmcp/delete-product',
			array(
				'id'     => $product_id,
				'status' => $force ? 'deleted' : 'trashed',
			),
			$force
				? __( 'Product permanently deleted.', 'mosmcp-abilities' )
				: __( 'Product moved to trash.', 'mosmcp-abilities' ),
			null,
			$started_at
		);
	}

	/**
	 * @return void
	 */
	private static function register_list_categories() {
		Naming::register_ability(
			'mosmcp/list-product-categories',
			array(
				'label'               => __( 'List Product Categories', 'mosmcp-abilities' ),
				'description'         => __( 'Lists WooCommerce product categories with their IDs, slugs, and product counts.', 'mosmcp-abilities' ),
				'category'            => WooCommerce_Abilities_Loader::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'hide_empty' => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'Whether to omit categories with no products.', 'mosmcp-abilities' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => WooCommerce_Response::envelope_schema(
					array(
						'type'                 => 'object',
						'properties'           => array(
							'categories' => array(
								'type'  => 'array',
								'items' => array(
									'type'                 => 'object',
									'properties'           => array(
										'id'     => array( 'type' => 'integer' ),
										'name'   => array( 'type' => 'string' ),
										'slug'   => array( 'type' => 'string' ),
										'parent' => array( 'type' => 'integer' ),
										'count'  => array( 'type' => 'integer' ),
									),
									'additionalProperties' => false,
								),
							),
						),
						'additionalProperties' => false,
					)
				),
				'execute_callback'    => array( __CLASS__, 'list_product_categories' ),
				'permission_callback' => array( WooCommerce_Permissions::class, 'can_read_products' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
						'open_world'  => false,
					),
					'required_cap' => WooCommerce_Permissions::READ_PRODUCTS,
					'show_in_rest' => false,
				),
			)
		);
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	public static function list_product_categories( $input = array() ) {
		$started_at = microtime( true );
		$input      = is_array( $input ) ? $input : array();

		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => ! empty( $input['hide_empty'] ),
			)
		);

		$categories = array();
		if ( ! is_wp_error( $terms ) ) {
			$categories = array_map( array( __CLASS__, 'term_summary' ), $terms );
		}

		WooCommerce_Helper::log( 'mosmcp/list-product-categories', 'success', array( 'count' => count( $categories ) ) );

		return WooCommerce_Response::success(
			'mosmcp/list-product-categories',
			array( 'categories' => $categories ),
			sprintf(
				/* translators: %d: number of categories returned */
				__( '%d categories retrieved.', 'mosmcp-abilities' ),
				count( $categories )
			),
			null,
			$started_at
		);
	}

	/**
	 * @return void
	 */
	private static function register_create_category() {
		Naming::register_ability(
			'mosmcp/create-product-category',
			array(
				'label'               => __( 'Create Product Category', 'mosmcp-abilities' ),
				'description'         => __( 'Creates a new WooCommerce product category, optionally nested under a parent category.', 'mosmcp-abilities' ),
				'category'            => WooCommerce_Abilities_Loader::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'name' ),
					'properties'           => array(
						'name'        => array(
							'type'        => 'string',
							'description' => __( 'The category name (required).', 'mosmcp-abilities' ),
						),
						'slug'        => array(
							'type'        => 'string',
							'description' => __( 'URL slug. Auto-generated from the name if omitted.', 'mosmcp-abilities' ),
						),
						'parent'      => array(
							'type'        => 'integer',
							'description' => __( 'Parent category term ID, to create this as a subcategory.', 'mosmcp-abilities' ),
						),
						'description' => array(
							'type'        => 'string',
							'description' => __( 'Category description.', 'mosmcp-abilities' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => WooCommerce_Response::envelope_schema(
					array(
						'type'                 => 'object',
						'properties'           => array(
							'id'     => array( 'type' => 'integer' ),
							'name'   => array( 'type' => 'string' ),
							'slug'   => array( 'type' => 'string' ),
							'parent' => array( 'type' => 'integer' ),
							'count'  => array( 'type' => 'integer' ),
						),
						'additionalProperties' => false,
					)
				),
				'execute_callback'    => array( __CLASS__, 'create_product_category' ),
				'permission_callback' => array( WooCommerce_Permissions::class, 'can_manage_product_terms' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
						'open_world'  => true,
					),
					'required_cap' => WooCommerce_Permissions::MANAGE_PRODUCT_TERMS,
					'show_in_rest' => false,
				),
			)
		);
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|WP_Error
	 */
	public static function create_product_category( $input = array() ) {
		$started_at = microtime( true );
		$input      = is_array( $input ) ? $input : array();

		$name = isset( $input['name'] ) ? sanitize_text_field( (string) $input['name'] ) : '';
		if ( '' === $name ) {
			return WooCommerce_Response::error( 'wcab_missing_name', __( 'A category name is required.', 'mosmcp-abilities' ) );
		}

		$args = array();

		if ( ! empty( $input['slug'] ) ) {
			$args['slug'] = sanitize_title( (string) $input['slug'] );
		}
		if ( ! empty( $input['description'] ) ) {
			$args['description'] = sanitize_textarea_field( (string) $input['description'] );
		}
		if ( ! empty( $input['parent'] ) ) {
			$parent_id = absint( $input['parent'] );
			if ( ! term_exists( $parent_id, 'product_cat' ) ) {
				return WooCommerce_Response::error( 'wcab_parent_category_not_found', __( 'No category was found with that parent ID.', 'mosmcp-abilities' ) );
			}
			$args['parent'] = $parent_id;
		}

		$result = wp_insert_term( $name, 'product_cat', $args );
		if ( is_wp_error( $result ) ) {
			$code = 'term_exists' === $result->get_error_code() ? 'wcab_category_exists' : 'wcab_category_create_failed';
			return WooCommerce_Response::error( $code, $result->get_error_message() );
		}

		$term = get_term( $result['term_id'], 'product_cat' );

		WooCommerce_Helper::log( 'mosmcp/create-product-category', 'success', array( 'id' => $term->term_id ) );

		return WooCommerce_Response::success(
			'mosmcp/create-product-category',
			self::term_summary( $term ),
			__( 'Category created.', 'mosmcp-abilities' ),
			null,
			$started_at
		);
	}

	/**
	 * Shared summary shape for a product_cat or product_tag term.
	 *
	 * @param \WP_Term $term
	 * @return array<string, mixed>
	 */
	private static function term_summary( $term ) {
		return array(
			'id'     => (int) $term->term_id,
			'name'   => $term->name,
			'slug'   => $term->slug,
			'parent' => (int) $term->parent,
			'count'  => (int) $term->count,
		);
	}

	/**
	 * @return void
	 */
	private static function register_delete_category() {
		Naming::register_ability(
			'mosmcp/delete-product-category',
			array(
				'label'               => __( 'Delete Product Category', 'mosmcp-abilities' ),
				'description'         => __( 'Deletes a WooCommerce product category. Any child categories are reparented to top-level, matching how WordPress handles term deletion.', 'mosmcp-abilities' ),
				'category'            => WooCommerce_Abilities_Loader::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'id' ),
					'properties'           => array(
						'id' => array(
							'type'        => 'integer',
							'description' => __( 'The category term ID to delete (required).', 'mosmcp-abilities' ),
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
				'execute_callback'    => array( __CLASS__, 'delete_product_category' ),
				'permission_callback' => array( WooCommerce_Permissions::class, 'can_delete_product_terms' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => true,
						'open_world'  => true,
					),
					'required_cap' => WooCommerce_Permissions::DELETE_PRODUCT_TERMS,
					'show_in_rest' => false,
				),
			)
		);
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|WP_Error
	 */
	public static function delete_product_category( $input = array() ) {
		$started_at = microtime( true );
		$input      = is_array( $input ) ? $input : array();

		$term_id = WooCommerce_Validator::validate_id( isset( $input['id'] ) ? $input['id'] : null, __( 'category ID', 'mosmcp-abilities' ) );
		if ( is_wp_error( $term_id ) ) {
			return $term_id;
		}

		$term = get_term( $term_id, 'product_cat' );
		if ( ! $term || is_wp_error( $term ) ) {
			return WooCommerce_Response::error( 'wcab_category_not_found', __( 'No category was found with that ID.', 'mosmcp-abilities' ) );
		}

		$result = wp_delete_term( $term_id, 'product_cat' );
		if ( is_wp_error( $result ) || ! $result ) {
			return WooCommerce_Response::error( 'wcab_category_delete_failed', __( 'The category could not be deleted.', 'mosmcp-abilities' ) );
		}

		WooCommerce_Helper::log( 'mosmcp/delete-product-category', 'success', array( 'id' => $term_id ) );

		return WooCommerce_Response::success(
			'mosmcp/delete-product-category',
			array(
				'id'     => $term_id,
				'status' => 'deleted',
			),
			__( 'Category deleted.', 'mosmcp-abilities' ),
			null,
			$started_at
		);
	}

	/**
	 * @return void
	 */
	private static function register_list_tags() {
		Naming::register_ability(
			'mosmcp/list-product-tags',
			array(
				'label'               => __( 'List Product Tags', 'mosmcp-abilities' ),
				'description'         => __( 'Lists WooCommerce product tags with their IDs, slugs, and product counts.', 'mosmcp-abilities' ),
				'category'            => WooCommerce_Abilities_Loader::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'search'     => array(
							'type'        => 'string',
							'description' => __( 'Search term matched against tag name.', 'mosmcp-abilities' ),
						),
						'hide_empty' => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'Whether to omit tags with no products.', 'mosmcp-abilities' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => WooCommerce_Response::envelope_schema(
					array(
						'type'                 => 'object',
						'properties'           => array(
							'tags' => array(
								'type'  => 'array',
								'items' => array(
									'type'                 => 'object',
									'properties'           => array(
										'id'     => array( 'type' => 'integer' ),
										'name'   => array( 'type' => 'string' ),
										'slug'   => array( 'type' => 'string' ),
										'parent' => array( 'type' => 'integer' ),
										'count'  => array( 'type' => 'integer' ),
									),
									'additionalProperties' => false,
								),
							),
						),
						'additionalProperties' => false,
					)
				),
				'execute_callback'    => array( __CLASS__, 'list_product_tags' ),
				'permission_callback' => array( WooCommerce_Permissions::class, 'can_read_products' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
						'open_world'  => false,
					),
					'required_cap' => WooCommerce_Permissions::READ_PRODUCTS,
					'show_in_rest' => false,
				),
			)
		);
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	public static function list_product_tags( $input = array() ) {
		$started_at = microtime( true );
		$input      = is_array( $input ) ? $input : array();

		$args = array(
			'taxonomy'   => 'product_tag',
			'hide_empty' => ! empty( $input['hide_empty'] ),
		);
		if ( ! empty( $input['search'] ) ) {
			$args['search'] = sanitize_text_field( (string) $input['search'] );
		}

		$terms = get_terms( $args );

		$tags = array();
		if ( ! is_wp_error( $terms ) ) {
			$tags = array_map( array( __CLASS__, 'term_summary' ), $terms );
		}

		WooCommerce_Helper::log( 'mosmcp/list-product-tags', 'success', array( 'count' => count( $tags ) ) );

		return WooCommerce_Response::success(
			'mosmcp/list-product-tags',
			array( 'tags' => $tags ),
			sprintf(
				/* translators: %d: number of tags returned */
				__( '%d tag(s) retrieved.', 'mosmcp-abilities' ),
				count( $tags )
			),
			null,
			$started_at
		);
	}

	/**
	 * @return void
	 */
	private static function register_create_tag() {
		Naming::register_ability(
			'mosmcp/create-product-tag',
			array(
				'label'               => __( 'Create Product Tag', 'mosmcp-abilities' ),
				'description'         => __( 'Creates a new WooCommerce product tag.', 'mosmcp-abilities' ),
				'category'            => WooCommerce_Abilities_Loader::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'name' ),
					'properties'           => array(
						'name'        => array(
							'type'        => 'string',
							'description' => __( 'The tag name (required).', 'mosmcp-abilities' ),
						),
						'slug'        => array(
							'type'        => 'string',
							'description' => __( 'URL slug. Auto-generated from the name if omitted.', 'mosmcp-abilities' ),
						),
						'description' => array(
							'type'        => 'string',
							'description' => __( 'Tag description.', 'mosmcp-abilities' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => WooCommerce_Response::envelope_schema(
					array(
						'type'                 => 'object',
						'properties'           => array(
							'id'     => array( 'type' => 'integer' ),
							'name'   => array( 'type' => 'string' ),
							'slug'   => array( 'type' => 'string' ),
							'parent' => array( 'type' => 'integer' ),
							'count'  => array( 'type' => 'integer' ),
						),
						'additionalProperties' => false,
					)
				),
				'execute_callback'    => array( __CLASS__, 'create_product_tag' ),
				'permission_callback' => array( WooCommerce_Permissions::class, 'can_manage_product_terms' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
						'open_world'  => true,
					),
					'required_cap' => WooCommerce_Permissions::MANAGE_PRODUCT_TERMS,
					'show_in_rest' => false,
				),
			)
		);
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|WP_Error
	 */
	public static function create_product_tag( $input = array() ) {
		$started_at = microtime( true );
		$input      = is_array( $input ) ? $input : array();

		$name = isset( $input['name'] ) ? sanitize_text_field( (string) $input['name'] ) : '';
		if ( '' === $name ) {
			return WooCommerce_Response::error( 'wcab_missing_name', __( 'A tag name is required.', 'mosmcp-abilities' ) );
		}

		$args = array();
		if ( ! empty( $input['slug'] ) ) {
			$args['slug'] = sanitize_title( (string) $input['slug'] );
		}
		if ( ! empty( $input['description'] ) ) {
			$args['description'] = sanitize_textarea_field( (string) $input['description'] );
		}

		$result = wp_insert_term( $name, 'product_tag', $args );
		if ( is_wp_error( $result ) ) {
			$code = 'term_exists' === $result->get_error_code() ? 'wcab_tag_exists' : 'wcab_tag_create_failed';
			return WooCommerce_Response::error( $code, $result->get_error_message() );
		}

		$term = get_term( $result['term_id'], 'product_tag' );

		WooCommerce_Helper::log( 'mosmcp/create-product-tag', 'success', array( 'id' => $term->term_id ) );

		return WooCommerce_Response::success(
			'mosmcp/create-product-tag',
			self::term_summary( $term ),
			__( 'Tag created.', 'mosmcp-abilities' ),
			null,
			$started_at
		);
	}

	/**
	 * Summary shape for a global attribute definition (a row from
	 * wc_get_attribute_taxonomies()), mirroring the WooCommerce REST API's
	 * /products/attributes/<id> shape.
	 *
	 * @param \stdClass $attribute
	 * @return array<string, mixed>
	 */
	private static function attribute_summary( $attribute ) {
		return array(
			'id'           => (int) $attribute->attribute_id,
			'name'         => $attribute->attribute_label,
			'slug'         => wc_attribute_taxonomy_name( $attribute->attribute_name ),
			'type'         => $attribute->attribute_type,
			'order_by'     => $attribute->attribute_orderby,
			'has_archives' => (bool) $attribute->attribute_public,
		);
	}

	/**
	 * Finds a global attribute definition by ID. Filters the full
	 * wc_get_attribute_taxonomies() list rather than relying on a
	 * by-ID lookup function, since that list call is the one behavior
	 * guaranteed stable across WooCommerce versions.
	 *
	 * @param int $attribute_id
	 * @return \stdClass|null
	 */
	private static function find_attribute_taxonomy( $attribute_id ) {
		foreach ( wc_get_attribute_taxonomies() as $attribute ) {
			if ( (int) $attribute->attribute_id === $attribute_id ) {
				return $attribute;
			}
		}
		return null;
	}

	/**
	 * @return void
	 */
	private static function register_list_attributes() {
		Naming::register_ability(
			'mosmcp/list-product-attributes',
			array(
				'label'               => __( 'List Product Attributes', 'mosmcp-abilities' ),
				'description'         => __( 'Lists all global WooCommerce product attributes (e.g. Color, Size) with their type and archive settings.', 'mosmcp-abilities' ),
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
							'attributes' => array(
								'type'  => 'array',
								'items' => array(
									'type'                 => 'object',
									'properties'           => array(
										'id'           => array( 'type' => 'integer' ),
										'name'         => array( 'type' => 'string' ),
										'slug'         => array( 'type' => 'string' ),
										'type'         => array( 'type' => 'string' ),
										'order_by'     => array( 'type' => 'string' ),
										'has_archives' => array( 'type' => 'boolean' ),
									),
									'additionalProperties' => false,
								),
							),
						),
						'additionalProperties' => false,
					)
				),
				'execute_callback'    => array( __CLASS__, 'list_product_attributes' ),
				'permission_callback' => array( WooCommerce_Permissions::class, 'can_read_products' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
						'open_world'  => false,
					),
					'required_cap' => WooCommerce_Permissions::READ_PRODUCTS,
					'show_in_rest' => false,
				),
			)
		);
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	public static function list_product_attributes( $input = array() ) {
		unset( $input ); // This listing takes no input filters.
		$started_at = microtime( true );

		$attributes = array_map( array( __CLASS__, 'attribute_summary' ), wc_get_attribute_taxonomies() );

		WooCommerce_Helper::log( 'mosmcp/list-product-attributes', 'success', array( 'count' => count( $attributes ) ) );

		return WooCommerce_Response::success(
			'mosmcp/list-product-attributes',
			array( 'attributes' => $attributes ),
			sprintf(
				/* translators: %d: number of attributes returned */
				__( '%d attribute(s) retrieved.', 'mosmcp-abilities' ),
				count( $attributes )
			),
			null,
			$started_at
		);
	}

	/**
	 * @return void
	 */
	private static function register_get_attribute() {
		$attribute_item_schema = array(
			'type'                 => 'object',
			'properties'           => array(
				'id'           => array( 'type' => 'integer' ),
				'name'         => array( 'type' => 'string' ),
				'slug'         => array( 'type' => 'string' ),
				'type'         => array( 'type' => 'string' ),
				'order_by'     => array( 'type' => 'string' ),
				'has_archives' => array( 'type' => 'boolean' ),
			),
			'additionalProperties' => false,
		);

		Naming::register_ability(
			'mosmcp/get-product-attribute',
			array(
				'label'               => __( 'Get Product Attribute', 'mosmcp-abilities' ),
				'description'         => __( 'Gets a single global WooCommerce product attribute by ID.', 'mosmcp-abilities' ),
				'category'            => WooCommerce_Abilities_Loader::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'id' ),
					'properties'           => array(
						'id' => array(
							'type'        => 'integer',
							'description' => __( 'The global attribute ID (required).', 'mosmcp-abilities' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => WooCommerce_Response::envelope_schema( $attribute_item_schema ),
				'execute_callback'    => array( __CLASS__, 'get_product_attribute' ),
				'permission_callback' => array( WooCommerce_Permissions::class, 'can_read_products' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
						'open_world'  => false,
					),
					'required_cap' => WooCommerce_Permissions::READ_PRODUCTS,
					'show_in_rest' => false,
				),
			)
		);
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|WP_Error
	 */
	public static function get_product_attribute( $input = array() ) {
		$started_at = microtime( true );
		$input      = is_array( $input ) ? $input : array();

		$attribute_id = WooCommerce_Validator::validate_id( isset( $input['id'] ) ? $input['id'] : null, __( 'attribute ID', 'mosmcp-abilities' ) );
		if ( is_wp_error( $attribute_id ) ) {
			return $attribute_id;
		}

		$attribute = self::find_attribute_taxonomy( $attribute_id );
		if ( ! $attribute ) {
			return WooCommerce_Response::error( 'wcab_attribute_not_found', __( 'No global attribute was found with that ID.', 'mosmcp-abilities' ) );
		}

		WooCommerce_Helper::log( 'mosmcp/get-product-attribute', 'success', array( 'id' => $attribute_id ) );

		return WooCommerce_Response::success(
			'mosmcp/get-product-attribute',
			self::attribute_summary( $attribute ),
			__( 'Attribute retrieved.', 'mosmcp-abilities' ),
			null,
			$started_at
		);
	}

	/**
	 * @return void
	 */
	private static function register_set_attributes() {
		Naming::register_ability(
			'mosmcp/set-product-attributes',
			array(
				'label'               => __( 'Set Product Attributes', 'mosmcp-abilities' ),
				'description'         => __( "Replaces a product's attribute list. Each attribute is either global (attribute_id from list/get-product-attribute, with options as existing term IDs in that attribute's own taxonomy) or custom (attribute_id 0, with a free-form name and text options). Pass an empty attributes array to remove all attributes.", 'mosmcp-abilities' ),
				'category'            => WooCommerce_Abilities_Loader::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'id', 'attributes' ),
					'properties'           => array(
						'id'         => array(
							'type'        => 'integer',
							'description' => __( 'The product ID (required).', 'mosmcp-abilities' ),
						),
						'attributes' => array(
							'type'        => 'array',
							'description' => __( 'The full replacement attribute list. An empty array clears all attributes.', 'mosmcp-abilities' ),
							'items'       => array(
								'type'                 => 'object',
								'required'             => array( 'options' ),
								'properties'           => array(
									'attribute_id' => array(
										'type'        => 'integer',
										'default'     => 0,
										'description' => __( 'A global attribute ID (from list/get-product-attribute), or 0 for a custom, product-specific attribute. Defaults to 0.', 'mosmcp-abilities' ),
									),
									'name'         => array(
										'type'        => 'string',
										'description' => __( 'Required when attribute_id is 0: the custom attribute label (e.g. "Material"). Ignored for global attributes, whose own name is used.', 'mosmcp-abilities' ),
									),
									'options'      => array(
										'type'        => 'array',
										'items'       => array( 'type' => 'string' ),
										'description' => __( 'For a global attribute: existing term IDs within that attribute\'s own taxonomy. For a custom attribute: free-form text values, e.g. ["Cotton", "Wool"].', 'mosmcp-abilities' ),
									),
									'visible'      => array(
										'type'        => 'boolean',
										'default'     => true,
										'description' => __( 'Whether the attribute is shown on the product page.', 'mosmcp-abilities' ),
									),
									'variation'    => array(
										'type'        => 'boolean',
										'default'     => false,
										'description' => __( 'Whether this attribute is used to define variations.', 'mosmcp-abilities' ),
									),
								),
								'additionalProperties' => false,
							),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => WooCommerce_Response::envelope_schema(
					array(
						'type'                 => 'object',
						'properties'           => array(
							'id'         => array( 'type' => 'integer' ),
							'attributes' => array(
								'type'  => 'array',
								'items' => array(
									'type'                 => 'object',
									'properties'           => array(
										'attribute_id' => array( 'type' => 'integer' ),
										'name'         => array( 'type' => 'string' ),
										'options'      => array(
											'type'  => 'array',
											'items' => array( 'type' => 'string' ),
										),
										'visible'      => array( 'type' => 'boolean' ),
										'variation'    => array( 'type' => 'boolean' ),
									),
									'additionalProperties' => false,
								),
							),
						),
						'additionalProperties' => false,
					)
				),
				'execute_callback'    => array( __CLASS__, 'set_product_attributes' ),
				'permission_callback' => array( WooCommerce_Permissions::class, 'can_edit_products' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => true,
						'open_world'  => true,
					),
					'required_cap' => WooCommerce_Permissions::EDIT_PRODUCTS,
					'show_in_rest' => false,
				),
			)
		);
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|WP_Error
	 */
	public static function set_product_attributes( $input = array() ) {
		$started_at = microtime( true );
		$input      = is_array( $input ) ? $input : array();

		$product_id = WooCommerce_Validator::validate_id( isset( $input['id'] ) ? $input['id'] : null, __( 'product ID', 'mosmcp-abilities' ) );
		if ( is_wp_error( $product_id ) ) {
			return $product_id;
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return WooCommerce_Response::error( 'wcab_product_not_found', __( 'No product was found with that ID.', 'mosmcp-abilities' ) );
		}
		if ( ! WooCommerce_Permissions::can_edit_product( $product_id ) ) {
			return WooCommerce_Response::error( 'wcab_cannot_edit', __( 'You are not allowed to edit this product.', 'mosmcp-abilities' ) );
		}

		$raw_attributes = isset( $input['attributes'] ) && is_array( $input['attributes'] ) ? $input['attributes'] : array();

		$attributes = array();
		$position   = 0;
		foreach ( $raw_attributes as $raw_attribute ) {
			if ( ! is_array( $raw_attribute ) ) {
				continue;
			}

			$built = self::build_product_attribute( $raw_attribute, $position );
			if ( is_wp_error( $built ) ) {
				return $built;
			}

			$attributes[] = $built;
			++$position;
		}

		$product->set_attributes( $attributes );
		$product->save();

		WooCommerce_Helper::log(
			'mosmcp/set-product-attributes',
			'success',
			array(
				'id'    => $product_id,
				'count' => count( $attributes ),
			)
		);

		return WooCommerce_Response::success(
			'mosmcp/set-product-attributes',
			array(
				'id'         => $product_id,
				'attributes' => array_map( array( __CLASS__, 'product_attribute_summary' ), $product->get_attributes() ),
			),
			__( 'Product attributes updated.', 'mosmcp-abilities' ),
			null,
			$started_at
		);
	}

	/**
	 * Builds one WC_Product_Attribute from a single raw input entry.
	 *
	 * @param array<string, mixed> $raw_attribute
	 * @param int                  $position
	 * @return WC_Product_Attribute|WP_Error
	 */
	private static function build_product_attribute( array $raw_attribute, $position ) {
		$attribute_id = isset( $raw_attribute['attribute_id'] ) ? absint( $raw_attribute['attribute_id'] ) : 0;
		$options      = isset( $raw_attribute['options'] ) ? $raw_attribute['options'] : array();

		$attribute = new WC_Product_Attribute();

		if ( $attribute_id > 0 ) {
			$taxonomy = function_exists( 'wc_attribute_taxonomy_name_by_id' ) ? wc_attribute_taxonomy_name_by_id( $attribute_id ) : '';
			if ( '' === $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
				return WooCommerce_Response::error( 'wcab_attribute_not_found', __( 'No global attribute was found with that attribute_id.', 'mosmcp-abilities' ) );
			}
			$attribute->set_id( $attribute_id );
			$attribute->set_name( $taxonomy );
			$attribute->set_options( WooCommerce_Validator::validate_id_array( $options ) );
		} else {
			$name = isset( $raw_attribute['name'] ) ? sanitize_text_field( (string) $raw_attribute['name'] ) : '';
			if ( '' === $name ) {
				return WooCommerce_Response::error( 'wcab_missing_attribute_name', __( 'name is required for a custom attribute (attribute_id 0).', 'mosmcp-abilities' ) );
			}
			$attribute->set_id( 0 );
			$attribute->set_name( $name );
			$attribute->set_options( is_array( $options ) ? array_map( 'sanitize_text_field', $options ) : array() );
		}

		$attribute->set_position( $position );
		$attribute->set_visible( isset( $raw_attribute['visible'] ) ? (bool) $raw_attribute['visible'] : true );
		$attribute->set_variation( isset( $raw_attribute['variation'] ) ? (bool) $raw_attribute['variation'] : false );

		return $attribute;
	}

	/**
	 * @param WC_Product_Attribute $attribute
	 * @return array<string, mixed>
	 */
	private static function product_attribute_summary( $attribute ) {
		return array(
			'attribute_id' => (int) $attribute->get_id(),
			'name'         => $attribute->get_name(),
			'options'      => array_map( 'strval', $attribute->get_options() ),
			'visible'      => $attribute->get_visible(),
			'variation'    => $attribute->get_variation(),
		);
	}
}
