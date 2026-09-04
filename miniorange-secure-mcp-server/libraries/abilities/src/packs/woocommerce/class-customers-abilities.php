<?php
/**
 * WooCommerce Customers abilities: CRUD, purchase history, and segmentation.
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

use WC_Customer;
use WP_Error;
use WP_User_Query;

/**
 * Class Customers_Abilities
 *
 * Registers mosmcp/list-customers, get-customer, create-customer,
 * update-customer, delete-customer, get-customer-purchase-history, and
 * list-customer-segment. Field names deliberately mirror the WooCommerce
 * REST API v3 Customer object
 * (https://developer.woocommerce.com/docs/apis/rest-api/v3/customers/).
 *
 * Capability model differs from every other domain in this plugin: a
 * customer record IS a WordPress user account, so create/update/delete are
 * gated behind real WP user-management capabilities (create_users,
 * edit_users, delete_users — Administrator-only by default) rather than
 * manage_woocommerce (which Shop Manager also holds). Read abilities
 * (list/get/purchase-history/segment) stay at manage_woocommerce, matching
 * the rest of the customer-PII abilities in this plugin. This mirrors the
 * WooCommerce REST API's own customer permission checks.
 *
 * list-customer-segment computes total_spent/order_count live per customer
 * (via WC_Customer, not a single indexed query) because the underlying
 * cache meta may not exist yet for customers who've never had stats
 * computed — the safe query would risk silently excluding real customers.
 * That makes it heavier than the other list abilities; narrow by role or
 * expect it to be slower on stores with large customer counts.
 */
class Customers_Abilities {

	/**
	 * Validates $input['id'] and confirms it names an existing user.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return int|WP_Error
	 */
	private static function require_customer_id( $input ) {
		$customer_id = WooCommerce_Validator::validate_id( isset( $input['id'] ) ? $input['id'] : null, __( 'customer ID', 'mosmcp-abilities' ) );
		if ( is_wp_error( $customer_id ) ) {
			return $customer_id;
		}

		if ( ! get_userdata( $customer_id ) ) {
			return WooCommerce_Response::error( 'wcab_customer_not_found', __( 'No customer was found with that ID.', 'mosmcp-abilities' ) );
		}

		return $customer_id;
	}

	/**
	 * Registers every Customers ability. Called from {@see WooCommerce_Abilities_Loader}.
	 *
	 * @return void
	 */
	public static function register() {
		$address_schema = array(
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
				'phone'      => array( 'type' => 'string' ),
				'email'      => array( 'type' => 'string' ),
			),
			'additionalProperties' => false,
		);

		$customer_item_schema = array(
			'type'                 => 'object',
			'properties'           => array(
				'id'                 => array( 'type' => 'integer' ),
				'date_created'       => array( 'type' => array( 'string', 'null' ) ),
				'date_modified'      => array( 'type' => array( 'string', 'null' ) ),
				'email'              => array( 'type' => 'string' ),
				'first_name'         => array( 'type' => 'string' ),
				'last_name'          => array( 'type' => 'string' ),
				'role'               => array( 'type' => 'string' ),
				'username'           => array( 'type' => 'string' ),
				'billing'            => $address_schema,
				'shipping'           => $address_schema,
				'is_paying_customer' => array( 'type' => 'boolean' ),
				'avatar_url'         => array( 'type' => 'string' ),
				'order_count'        => array( 'type' => 'integer' ),
				'total_spent'        => array( 'type' => 'string' ),
			),
			'additionalProperties' => false,
		);

		self::register_list( $customer_item_schema );
		self::register_get( $customer_item_schema );
		self::register_create( $customer_item_schema, $address_schema );
		self::register_update( $customer_item_schema, $address_schema );
		self::register_delete();
		self::register_purchase_history();
		self::register_segment();
	}

	/*
	-------------------------------------------------------------------- *
	 * List / Get
	 * -------------------------------------------------------------------- */

	/**
	 * @param array<string, mixed> $customer_item_schema
	 * @return void
	 */
	private static function register_list( array $customer_item_schema ) {
		Naming::register_ability(
			'mosmcp/list-customers',
			array(
				'label'               => __( 'List Customers', 'mosmcp-abilities' ),
				'description'         => __( 'Lists WooCommerce customers, filterable by search term, email, and role.', 'mosmcp-abilities' ),
				'category'            => WooCommerce_Abilities_Loader::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'search'   => array(
							'type'        => 'string',
							'description' => __( 'Search term matched against name, login, or email.', 'mosmcp-abilities' ),
						),
						'email'    => array(
							'type'        => 'string',
							'description' => __( 'Search term matched specifically against email. Takes precedence over search.', 'mosmcp-abilities' ),
						),
						'role'     => array(
							'type'        => 'string',
							'default'     => 'customer',
							'description' => __( 'WordPress role to filter by. Defaults to customer.', 'mosmcp-abilities' ),
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
							'customers' => array(
								'type'  => 'array',
								'items' => $customer_item_schema,
							),
						),
						'additionalProperties' => false,
					),
					true
				),
				'execute_callback'    => array( __CLASS__, 'list_customers' ),
				'permission_callback' => array( WooCommerce_Permissions::class, 'can_read_customers' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
						'open_world'  => false,
					),
					'required_cap' => WooCommerce_Permissions::READ_CUSTOMERS,
					'show_in_rest' => false,
				),
			)
		);
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	public static function list_customers( $input = array() ) {
		$started_at = microtime( true );
		$input      = is_array( $input ) ? $input : array();

		$pagination = WooCommerce_Validator::validate_pagination( $input );

		$args = array(
			'role'        => ! empty( $input['role'] ) ? sanitize_key( (string) $input['role'] ) : 'customer',
			'number'      => $pagination['per_page'],
			'paged'       => $pagination['page'],
			'count_total' => true,
		);

		if ( ! empty( $input['email'] ) ) {
			$args['search']         = '*' . sanitize_text_field( (string) $input['email'] ) . '*';
			$args['search_columns'] = array( 'user_email' );
		} elseif ( ! empty( $input['search'] ) ) {
			$args['search'] = '*' . sanitize_text_field( (string) $input['search'] ) . '*';
		}

		$query     = new WP_User_Query( $args );
		$customers = array_map(
			static function ( $user ) {
				return WooCommerce_Helper::customer_summary( $user->ID );
			},
			$query->get_results()
		);

		$meta = WooCommerce_Response::pagination_meta( (int) $query->get_total(), $pagination['page'], $pagination['per_page'] );

		WooCommerce_Helper::log( 'mosmcp/list-customers', 'success', array( 'count' => count( $customers ) ) );

		return WooCommerce_Response::success(
			'mosmcp/list-customers',
			array( 'customers' => $customers ),
			sprintf(
				/* translators: %d: number of customers returned */
				__( '%d customer(s) retrieved.', 'mosmcp-abilities' ),
				count( $customers )
			),
			$meta,
			$started_at
		);
	}

	/**
	 * @param array<string, mixed> $customer_item_schema
	 * @return void
	 */
	private static function register_get( array $customer_item_schema ) {
		Naming::register_ability(
			'mosmcp/get-customer',
			array(
				'label'               => __( 'Get Customer', 'mosmcp-abilities' ),
				'description'         => __( 'Gets a single WooCommerce customer by ID, including billing, shipping, and order stats.', 'mosmcp-abilities' ),
				'category'            => WooCommerce_Abilities_Loader::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'id' ),
					'properties'           => array(
						'id' => array(
							'type'        => 'integer',
							'description' => __( 'The customer (WordPress user) ID (required).', 'mosmcp-abilities' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => WooCommerce_Response::envelope_schema( $customer_item_schema ),
				'execute_callback'    => array( __CLASS__, 'get_customer' ),
				'permission_callback' => array( WooCommerce_Permissions::class, 'can_read_customers' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
						'open_world'  => false,
					),
					'required_cap' => WooCommerce_Permissions::READ_CUSTOMERS,
					'show_in_rest' => false,
				),
			)
		);
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|WP_Error
	 */
	public static function get_customer( $input = array() ) {
		$started_at = microtime( true );
		$input      = is_array( $input ) ? $input : array();

		$customer_id = self::require_customer_id( $input );
		if ( is_wp_error( $customer_id ) ) {
			return $customer_id;
		}

		WooCommerce_Helper::log( 'mosmcp/get-customer', 'success', array( 'id' => $customer_id ) );

		return WooCommerce_Response::success(
			'mosmcp/get-customer',
			WooCommerce_Helper::customer_summary( $customer_id ),
			__( 'Customer retrieved.', 'mosmcp-abilities' ),
			null,
			$started_at
		);
	}

	/*
	-------------------------------------------------------------------- *
	 * Create / Update
	 * -------------------------------------------------------------------- */

	/**
	 * @param array<string, mixed> $customer_item_schema
	 * @param array<string, mixed> $address_schema
	 * @return void
	 */
	private static function register_create( array $customer_item_schema, array $address_schema ) {
		Naming::register_ability(
			'mosmcp/create-customer',
			array(
				'label'               => __( 'Create Customer', 'mosmcp-abilities' ),
				'description'         => __( 'Creates a new WooCommerce customer account. Generates a username from the email and a random password when either is omitted.', 'mosmcp-abilities' ),
				'category'            => WooCommerce_Abilities_Loader::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'email' ),
					'properties'           => array(
						'email'      => array(
							'type'        => 'string',
							'description' => __( 'The customer email address (required).', 'mosmcp-abilities' ),
						),
						'username'   => array(
							'type'        => 'string',
							'description' => __( 'Login username. Auto-generated from the email if omitted.', 'mosmcp-abilities' ),
						),
						'password'   => array(
							'type'        => 'string',
							'description' => __( 'Account password. Auto-generated if omitted.', 'mosmcp-abilities' ),
						),
						'first_name' => array(
							'type'        => 'string',
							'description' => __( 'First name.', 'mosmcp-abilities' ),
						),
						'last_name'  => array(
							'type'        => 'string',
							'description' => __( 'Last name.', 'mosmcp-abilities' ),
						),
						'billing'    => $address_schema,
						'shipping'   => $address_schema,
					),
					'additionalProperties' => false,
				),
				'output_schema'       => WooCommerce_Response::envelope_schema( $customer_item_schema ),
				'execute_callback'    => array( __CLASS__, 'create_customer' ),
				'permission_callback' => array( WooCommerce_Permissions::class, 'can_create_customers' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
						'open_world'  => false,
					),
					'required_cap' => WooCommerce_Permissions::CREATE_CUSTOMERS,
					'show_in_rest' => false,
				),
			)
		);
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|WP_Error
	 */
	public static function create_customer( $input = array() ) {
		$started_at = microtime( true );
		$input      = is_array( $input ) ? $input : array();

		$email = isset( $input['email'] ) ? sanitize_email( (string) $input['email'] ) : '';
		if ( '' === $email || ! is_email( $email ) ) {
			return WooCommerce_Response::error( 'wcab_invalid_email', __( 'A valid email address is required.', 'mosmcp-abilities' ) );
		}
		if ( email_exists( $email ) ) {
			return WooCommerce_Response::error( 'wcab_customer_exists', __( 'A customer with that email already exists.', 'mosmcp-abilities' ) );
		}

		$username = isset( $input['username'] ) ? sanitize_user( (string) $input['username'] ) : '';
		$password = isset( $input['password'] ) ? (string) $input['password'] : '';

		$customer_id = wc_create_new_customer(
			$email,
			$username,
			$password,
			array(
				'first_name' => isset( $input['first_name'] ) ? sanitize_text_field( (string) $input['first_name'] ) : '',
				'last_name'  => isset( $input['last_name'] ) ? sanitize_text_field( (string) $input['last_name'] ) : '',
			)
		);

		if ( is_wp_error( $customer_id ) ) {
			return WooCommerce_Response::error( 'wcab_customer_create_failed', $customer_id->get_error_message() );
		}

		$customer = new WC_Customer( $customer_id );
		if ( isset( $input['billing'] ) && is_array( $input['billing'] ) ) {
			self::apply_customer_address( $customer, 'billing', $input['billing'] );
		}
		if ( isset( $input['shipping'] ) && is_array( $input['shipping'] ) ) {
			self::apply_customer_address( $customer, 'shipping', $input['shipping'] );
		}
		$customer->save();

		WooCommerce_Helper::log( 'mosmcp/create-customer', 'success', array( 'id' => $customer_id ) );

		return WooCommerce_Response::success(
			'mosmcp/create-customer',
			WooCommerce_Helper::customer_summary( $customer_id ),
			__( 'Customer created.', 'mosmcp-abilities' ),
			null,
			$started_at
		);
	}

	/**
	 * @param array<string, mixed> $customer_item_schema
	 * @param array<string, mixed> $address_schema
	 * @return void
	 */
	private static function register_update( array $customer_item_schema, array $address_schema ) {
		Naming::register_ability(
			'mosmcp/update-customer',
			array(
				'label'               => __( 'Update Customer', 'mosmcp-abilities' ),
				'description'         => __( "Updates a customer's email, name, password, billing, or shipping details. Provide the customer ID and at least one field to change. Changing the password signs the customer out of all active sessions. The login username cannot be changed once created — that's a WordPress limitation, not one of this ability.", 'mosmcp-abilities' ),
				'category'            => WooCommerce_Abilities_Loader::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'id' ),
					'properties'           => array(
						'id'         => array(
							'type'        => 'integer',
							'description' => __( 'The customer ID to update (required).', 'mosmcp-abilities' ),
						),
						'email'      => array(
							'type'        => 'string',
							'description' => __( 'New email address.', 'mosmcp-abilities' ),
						),
						'first_name' => array(
							'type'        => 'string',
							'description' => __( 'New first name.', 'mosmcp-abilities' ),
						),
						'last_name'  => array(
							'type'        => 'string',
							'description' => __( 'New last name.', 'mosmcp-abilities' ),
						),
						'password'   => array(
							'type'        => 'string',
							'description' => __( 'New password. Signs the customer out of all sessions.', 'mosmcp-abilities' ),
						),
						'billing'    => $address_schema,
						'shipping'   => $address_schema,
					),
					'additionalProperties' => false,
				),
				'output_schema'       => WooCommerce_Response::envelope_schema( $customer_item_schema ),
				'execute_callback'    => array( __CLASS__, 'update_customer' ),
				'permission_callback' => array( WooCommerce_Permissions::class, 'can_edit_customers' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => true,
						'open_world'  => false,
					),
					'required_cap' => WooCommerce_Permissions::EDIT_CUSTOMERS,
					'show_in_rest' => false,
				),
			)
		);
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|WP_Error
	 */
	public static function update_customer( $input = array() ) {
		$started_at = microtime( true );
		$input      = is_array( $input ) ? $input : array();

		$customer_id = self::require_customer_id( $input );
		if ( is_wp_error( $customer_id ) ) {
			return $customer_id;
		}
		if ( ! WooCommerce_Permissions::can_edit_customer( $customer_id ) ) {
			return WooCommerce_Response::error( 'wcab_cannot_edit', __( 'You are not allowed to edit this customer.', 'mosmcp-abilities' ) );
		}

		$fields = $input;
		unset( $fields['id'] );
		if ( empty( $fields ) ) {
			return WooCommerce_Response::nothing_to_update_error();
		}

		$customer = new WC_Customer( $customer_id );

		if ( isset( $input['email'] ) ) {
			$email = sanitize_email( (string) $input['email'] );
			if ( '' === $email || ! is_email( $email ) ) {
				return WooCommerce_Response::error( 'wcab_invalid_email', __( 'A valid email address is required.', 'mosmcp-abilities' ) );
			}
			$existing_id = email_exists( $email );
			if ( $existing_id && (int) $existing_id !== $customer_id ) {
				return WooCommerce_Response::error( 'wcab_email_in_use', __( 'Another customer already uses that email.', 'mosmcp-abilities' ) );
			}
			$customer->set_email( $email );
		}
		if ( isset( $input['first_name'] ) ) {
			$customer->set_first_name( sanitize_text_field( (string) $input['first_name'] ) );
		}
		if ( isset( $input['last_name'] ) ) {
			$customer->set_last_name( sanitize_text_field( (string) $input['last_name'] ) );
		}
		if ( isset( $input['billing'] ) && is_array( $input['billing'] ) ) {
			self::apply_customer_address( $customer, 'billing', $input['billing'] );
		}
		if ( isset( $input['shipping'] ) && is_array( $input['shipping'] ) ) {
			self::apply_customer_address( $customer, 'shipping', $input['shipping'] );
		}

		$customer->save();

		if ( isset( $input['password'] ) && '' !== $input['password'] ) {
			wp_set_password( (string) $input['password'], $customer_id );
		}

		WooCommerce_Helper::log( 'mosmcp/update-customer', 'success', array( 'id' => $customer_id ) );

		return WooCommerce_Response::success(
			'mosmcp/update-customer',
			WooCommerce_Helper::customer_summary( $customer_id ),
			__( 'Customer updated.', 'mosmcp-abilities' ),
			null,
			$started_at
		);
	}

	/**
	 * @param WC_Customer          $customer
	 * @param string               $type 'billing' or 'shipping'.
	 * @param array<string, mixed> $address
	 * @return void
	 */
	private static function apply_customer_address( $customer, $type, array $address ) {
		// Unlike order addresses, the official Customer schema gives billing and
		// shipping an identical shape — both carry phone and email. Matches
		// WooCommerce_Helper::customer_address()'s read side.
		WooCommerce_Helper::apply_address(
			$customer,
			$type,
			$address,
			array(
				'phone' => 'text',
				'email' => 'email',
			)
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
			'mosmcp/delete-customer',
			array(
				'label'               => __( 'Delete Customer', 'mosmcp-abilities' ),
				'description'         => __( "Deletes a WooCommerce customer's WordPress user account. Unlike products/orders there is no trash — this is immediate and permanent. Optionally reassign their content to another user ID.", 'mosmcp-abilities' ),
				'category'            => WooCommerce_Abilities_Loader::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'id' ),
					'properties'           => array(
						'id'       => array(
							'type'        => 'integer',
							'description' => __( 'The customer ID to delete (required).', 'mosmcp-abilities' ),
						),
						'reassign' => array(
							'type'        => 'integer',
							'description' => __( 'User ID to reassign this customer\'s content to. Omit to leave it unassigned.', 'mosmcp-abilities' ),
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
				'execute_callback'    => array( __CLASS__, 'delete_customer' ),
				'permission_callback' => array( WooCommerce_Permissions::class, 'can_delete_customers' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => true,
						'open_world'  => false,
					),
					'required_cap' => WooCommerce_Permissions::DELETE_CUSTOMERS,
					'show_in_rest' => false,
				),
			)
		);
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|WP_Error
	 */
	public static function delete_customer( $input = array() ) {
		$started_at = microtime( true );
		$input      = is_array( $input ) ? $input : array();

		$customer_id = self::require_customer_id( $input );
		if ( is_wp_error( $customer_id ) ) {
			return $customer_id;
		}
		if ( ! WooCommerce_Permissions::can_delete_customer( $customer_id ) ) {
			return WooCommerce_Response::error( 'wcab_cannot_delete', __( 'You are not allowed to delete this customer.', 'mosmcp-abilities' ) );
		}

		// wp_delete_user() lives in an admin-only file not loaded on the
		// REST/MCP request path this ability runs on.
		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}

		$reassign = ! empty( $input['reassign'] ) ? absint( $input['reassign'] ) : null;

		$result = wp_delete_user( $customer_id, $reassign );
		if ( ! $result ) {
			return WooCommerce_Response::error( 'wcab_customer_delete_failed', __( 'The customer could not be deleted.', 'mosmcp-abilities' ) );
		}

		WooCommerce_Helper::log( 'mosmcp/delete-customer', 'success', array( 'id' => $customer_id ) );

		return WooCommerce_Response::success(
			'mosmcp/delete-customer',
			array(
				'id'     => $customer_id,
				'status' => 'deleted',
			),
			__( 'Customer account deleted.', 'mosmcp-abilities' ),
			null,
			$started_at
		);
	}

	/*
	-------------------------------------------------------------------- *
	 * Purchase history
	 * -------------------------------------------------------------------- */

	/**
	 * @return void
	 */
	private static function register_purchase_history() {
		Naming::register_ability(
			'mosmcp/get-customer-purchase-history',
			array(
				'label'               => __( 'Get Customer Purchase History', 'mosmcp-abilities' ),
				'description'         => __( "Gets a customer's order history: lifetime order count and spend, plus a paginated list of their orders. For full detail on any one order, pass its ID to get-order.", 'mosmcp-abilities' ),
				'category'            => WooCommerce_Abilities_Loader::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'id' ),
					'properties'           => array(
						'id'       => array(
							'type'        => 'integer',
							'description' => __( 'The customer ID (required).', 'mosmcp-abilities' ),
						),
						'page'     => array(
							'type'        => 'integer',
							'default'     => 1,
							'description' => __( 'Page number, for pagination.', 'mosmcp-abilities' ),
						),
						'per_page' => array(
							'type'        => 'integer',
							'default'     => 20,
							'description' => __( 'Orders per page (1-100).', 'mosmcp-abilities' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => WooCommerce_Response::envelope_schema(
					array(
						'type'                 => 'object',
						'properties'           => array(
							'customer_id' => array( 'type' => 'integer' ),
							'order_count' => array( 'type' => 'integer' ),
							'total_spent' => array( 'type' => 'string' ),
							'orders'      => array(
								'type'  => 'array',
								'items' => array(
									'type'                 => 'object',
									'properties'           => array(
										'id'           => array( 'type' => 'integer' ),
										'status'       => array( 'type' => 'string' ),
										'date_created' => array( 'type' => array( 'string', 'null' ) ),
										'total'        => array( 'type' => 'string' ),
										'item_count'   => array( 'type' => 'integer' ),
									),
									'additionalProperties' => false,
								),
							),
						),
						'additionalProperties' => false,
					),
					true
				),
				'execute_callback'    => array( __CLASS__, 'get_customer_purchase_history' ),
				'permission_callback' => array( WooCommerce_Permissions::class, 'can_read_customers' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
						'open_world'  => false,
					),
					'required_cap' => WooCommerce_Permissions::READ_CUSTOMERS,
					'show_in_rest' => false,
				),
			)
		);
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|WP_Error
	 */
	public static function get_customer_purchase_history( $input = array() ) {
		$started_at = microtime( true );
		$input      = is_array( $input ) ? $input : array();

		$customer_id = self::require_customer_id( $input );
		if ( is_wp_error( $customer_id ) ) {
			return $customer_id;
		}

		$pagination = WooCommerce_Validator::validate_pagination( $input );

		$result = wc_get_orders(
			array(
				'customer' => $customer_id,
				'page'     => $pagination['page'],
				'limit'    => $pagination['per_page'],
				'orderby'  => 'date',
				'order'    => 'DESC',
				'paginate' => true,
				'return'   => 'objects',
			)
		);

		$orders = array_map(
			static function ( $order ) {
				$date_created = $order->get_date_created();
				return array(
					'id'           => $order->get_id(),
					'status'       => $order->get_status(),
					'date_created' => $date_created ? $date_created->date( 'c' ) : null,
					'total'        => WooCommerce_Helper::money( $order->get_total() ),
					'item_count'   => (int) $order->get_item_count(),
				);
			},
			$result->orders
		);

		$customer = new WC_Customer( $customer_id );
		$meta     = WooCommerce_Response::pagination_meta( $result->total, $pagination['page'], $pagination['per_page'] );

		WooCommerce_Helper::log( 'mosmcp/get-customer-purchase-history', 'success', array( 'id' => $customer_id ) );

		return WooCommerce_Response::success(
			'mosmcp/get-customer-purchase-history',
			array(
				'customer_id' => $customer_id,
				'order_count' => (int) $customer->get_order_count(),
				'total_spent' => WooCommerce_Helper::money( $customer->get_total_spent() ),
				'orders'      => $orders,
			),
			__( 'Purchase history retrieved.', 'mosmcp-abilities' ),
			$meta,
			$started_at
		);
	}

	/*
	-------------------------------------------------------------------- *
	 * Segmentation
	 * -------------------------------------------------------------------- */

	/**
	 * @return void
	 */
	private static function register_segment() {
		Naming::register_ability(
			'mosmcp/list-customer-segment',
			array(
				'label'               => __( 'List Customer Segment', 'mosmcp-abilities' ),
				'description'         => __( "Lists customers matching spend and/or order-count thresholds, sorted by spend or order count — for targeting or export. Computes stats live per customer rather than via a single indexed query, so it's slower than the other list abilities; narrow with role and thresholds on stores with many customers.", 'mosmcp-abilities' ),
				'category'            => WooCommerce_Abilities_Loader::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'min_orders' => array(
							'type'        => 'integer',
							'description' => __( 'Only include customers with at least this many orders.', 'mosmcp-abilities' ),
						),
						'min_spent'  => array(
							'type'        => 'number',
							'description' => __( 'Only include customers who have spent at least this amount.', 'mosmcp-abilities' ),
						),
						'role'       => array(
							'type'        => 'string',
							'default'     => 'customer',
							'description' => __( 'WordPress role to consider. Defaults to customer.', 'mosmcp-abilities' ),
						),
						'orderby'    => array(
							'type'        => 'string',
							'enum'        => array( 'total_spent', 'order_count' ),
							'default'     => 'total_spent',
							'description' => __( 'Field to sort by.', 'mosmcp-abilities' ),
						),
						'order'      => array(
							'type'        => 'string',
							'enum'        => array( 'ASC', 'DESC' ),
							'default'     => 'DESC',
							'description' => __( 'Sort direction.', 'mosmcp-abilities' ),
						),
						'page'       => array(
							'type'        => 'integer',
							'default'     => 1,
							'description' => __( 'Page number, for pagination.', 'mosmcp-abilities' ),
						),
						'per_page'   => array(
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
							'customers' => array(
								'type'  => 'array',
								'items' => array(
									'type'                 => 'object',
									'properties'           => array(
										'customer_id' => array( 'type' => 'integer' ),
										'email'       => array( 'type' => 'string' ),
										'first_name'  => array( 'type' => 'string' ),
										'last_name'   => array( 'type' => 'string' ),
										'order_count' => array( 'type' => 'integer' ),
										'total_spent' => array( 'type' => 'string' ),
									),
									'additionalProperties' => false,
								),
							),
						),
						'additionalProperties' => false,
					),
					true
				),
				'execute_callback'    => array( __CLASS__, 'list_customer_segment' ),
				'permission_callback' => array( WooCommerce_Permissions::class, 'can_read_customers' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
						'open_world'  => false,
					),
					'required_cap' => WooCommerce_Permissions::READ_CUSTOMERS,
					'show_in_rest' => false,
				),
			)
		);
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|WP_Error
	 */
	public static function list_customer_segment( $input = array() ) {
		$started_at = microtime( true );
		$input      = is_array( $input ) ? $input : array();

		$pagination = WooCommerce_Validator::validate_pagination( $input );

		$role       = ! empty( $input['role'] ) ? sanitize_key( (string) $input['role'] ) : 'customer';
		$min_orders = isset( $input['min_orders'] ) ? absint( $input['min_orders'] ) : 0;
		$min_spent  = isset( $input['min_spent'] ) && is_numeric( $input['min_spent'] ) ? (float) $input['min_spent'] : 0.0;

		$orderby = WooCommerce_Validator::validate_enum( isset( $input['orderby'] ) ? $input['orderby'] : null, array( 'total_spent', 'order_count' ), __( 'orderby', 'mosmcp-abilities' ), 'total_spent' );
		if ( is_wp_error( $orderby ) ) {
			return $orderby;
		}
		$order_dir = isset( $input['order'] ) && 'ASC' === strtoupper( (string) $input['order'] ) ? 'ASC' : 'DESC';

		$user_ids = get_users(
			array(
				'role'   => $role,
				'fields' => 'ID',
			)
		);

		$segment = array();
		foreach ( $user_ids as $user_id ) {
			$customer    = new WC_Customer( (int) $user_id );
			$order_count = (int) $customer->get_order_count();
			$total_spent = (float) $customer->get_total_spent();

			if ( $order_count < $min_orders || $total_spent < $min_spent ) {
				continue;
			}

			$segment[] = array(
				'customer_id' => $customer->get_id(),
				'email'       => $customer->get_email(),
				'first_name'  => $customer->get_first_name(),
				'last_name'   => $customer->get_last_name(),
				'order_count' => $order_count,
				'total_spent' => WooCommerce_Helper::money( $total_spent ),
			);
		}

		usort(
			$segment,
			static function ( $a, $b ) use ( $orderby, $order_dir ) {
				$field_a = 'total_spent' === $orderby ? (float) $a['total_spent'] : $a['order_count'];
				$field_b = 'total_spent' === $orderby ? (float) $b['total_spent'] : $b['order_count'];
				if ( $field_a === $field_b ) {
					return 0;
				}
				$comparison = ( $field_a < $field_b ) ? -1 : 1;
				return 'ASC' === $order_dir ? $comparison : -$comparison;
			}
		);

		$total  = count( $segment );
		$offset = ( $pagination['page'] - 1 ) * $pagination['per_page'];
		$page   = array_slice( $segment, $offset, $pagination['per_page'] );
		$meta   = WooCommerce_Response::pagination_meta( $total, $pagination['page'], $pagination['per_page'] );

		WooCommerce_Helper::log( 'mosmcp/list-customer-segment', 'success', array( 'matched' => $total ) );

		return WooCommerce_Response::success(
			'mosmcp/list-customer-segment',
			array( 'customers' => $page ),
			sprintf(
				/* translators: %d: number of customers matched */
				__( '%d customer(s) matched the segment.', 'mosmcp-abilities' ),
				$total
			),
			$meta,
			$started_at
		);
	}
}
