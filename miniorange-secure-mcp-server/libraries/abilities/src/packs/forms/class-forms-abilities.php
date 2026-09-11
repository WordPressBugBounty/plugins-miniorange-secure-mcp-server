<?php
/**
 * Forms & leads abilities: Contact Form 7 (+ Flamingo), WPForms, and Gravity Forms.
 *
 * Ported from the reviewed mo-custom "Category 2" abilities. Each provider's
 * abilities register only when that provider is active; every execute callback
 * additionally re-checks provider availability and returns a clear WP_Error if it
 * is missing. CSV export is hardened against spreadsheet formula injection.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Forms;

use MoSMCP\Abilities\Naming;

use GFAPI;
use WP_Error;
use WPCF7_ContactForm;

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
// phpcs:disable WordPress.DB.SlowDBQuery

/**
 * Class Forms_Abilities
 *
 * Self-registering forms/leads domain module. Registration is gated per provider;
 * annotations, show_in_rest, and capability gates are supplied inline by each
 * registration (all reads/writes here are admin-domain: open_world is false, since
 * form definitions and private submissions are not public web content).
 */
class Forms_Abilities {

	/**
	 * Ability category slug.
	 */
	const CATEGORY_FORMS = 'mosmcp-forms';

	/**
	 * WP_Error codes shared across this file's CF7/WPForms/Gravity Forms abilities.
	 */
	const ERR_FORM_NOT_FOUND  = 'moca_form_not_found';
	const ERR_ENTRY_NOT_FOUND = 'moca_entry_not_found';
	const ERR_MISSING_FORM_ID = 'moca_missing_form_id';

	/**
	 * Flamingo (CF7's submission-log companion plugin) post type and meta keys.
	 */
	const FLAMINGO_POST_TYPE    = 'flamingo_inbound';
	const FLAMINGO_CHANNEL_META = '_channel';

	/**
	 * Meta key this pack uses to track whether a CF7/Flamingo entry has been read.
	 */
	const READ_META = '_moca_cf7_read';

	/**
	 * Registers the forms ability category. No-op unless a supported form plugin is active.
	 *
	 * @return void
	 */
	public static function register_categories() {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}
		if ( ! ( self::has_cf7() || self::has_wpforms() || self::has_gravityforms() ) ) {
			return;
		}
		Naming::register_category(
			self::CATEGORY_FORMS,
			array(
				'label'       => __( 'Forms & Leads', 'mosmcp-abilities' ),
				'description' => __( 'Read and manage Contact Form 7, WPForms, and Gravity Forms forms and submissions.', 'mosmcp-abilities' ),
			)
		);
	}

	/**
	 * Registers each active provider's abilities. Providers that are not active
	 * register nothing, so no dead tools appear for an uninstalled plugin.
	 *
	 * @return void
	 */
	public static function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		if ( self::has_cf7() ) {
			self::register_cf7_abilities();
		}
		if ( self::has_wpforms() ) {
			self::register_wpforms_abilities();
		}
		if ( self::has_gravityforms() ) {
			self::register_gravityforms_abilities();
		}
	}

	/**
	 * @return bool
	 */
	private static function has_cf7() {
		return class_exists( 'WPCF7_ContactForm' );
	}

	/**
	 * @return bool
	 */
	private static function has_flamingo() {
		return class_exists( 'Flamingo_Inbound_Message' );
	}

	/**
	 * @return bool
	 */
	private static function has_wpforms() {
		return function_exists( 'wpforms' );
	}

	/**
	 * @return bool
	 */
	private static function has_gravityforms() {
		return class_exists( 'GFAPI' );
	}

	/**
	 * @return true|WP_Error
	 */
	private static function require_cf7() {
		if ( ! self::has_cf7() ) {
			return new WP_Error( 'mosmcp_plugin_not_active', __( 'Contact Form 7 is not installed or active on this site.', 'mosmcp-abilities' ) );
		}
		return true;
	}

	/**
	 * @return true|WP_Error
	 */
	private static function require_flamingo() {
		if ( ! self::has_flamingo() ) {
			return new WP_Error( 'mosmcp_plugin_not_active', __( 'The Flamingo plugin (required for Contact Form 7 submission storage) is not installed or active on this site.', 'mosmcp-abilities' ) );
		}
		return true;
	}

	/**
	 * @return true|WP_Error
	 */
	private static function require_wpforms() {
		if ( ! self::has_wpforms() ) {
			return new WP_Error( 'mosmcp_plugin_not_active', __( 'WPForms is not installed or active on this site.', 'mosmcp-abilities' ) );
		}
		return true;
	}

	/**
	 * @return true|WP_Error
	 */
	private static function require_wpforms_entries() {
		$check = self::require_wpforms();
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		if ( ! isset( wpforms()->entry ) || ! is_object( wpforms()->entry ) ) {
			return new WP_Error( 'mosmcp_feature_unavailable', __( 'WPForms entry storage is not available on this site (this typically requires WPForms Pro or an entries add-on).', 'mosmcp-abilities' ) );
		}
		return true;
	}

	/**
	 * @return true|WP_Error
	 */
	private static function require_gravityforms() {
		if ( ! self::has_gravityforms() ) {
			return new WP_Error( 'mosmcp_plugin_not_active', __( 'Gravity Forms is not installed or active on this site.', 'mosmcp-abilities' ) );
		}
		return true;
	}

	/**
	 * Forms/leads abilities all require manage_options uniformly.
	 *
	 * @return bool
	 */
	public static function can_manage_forms() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Shared input schema: a single required `id` plus optional extra properties.
	 *
	 * @param string               $id_description   Description for the id property.
	 * @param array<string, mixed> $extra_properties Additional input properties.
	 * @param string[]             $extra_required   Additional required property names.
	 * @return array<string, mixed>
	 */
	private static function id_input_schema( $id_description, array $extra_properties = array(), array $extra_required = array() ) {
		return array(
			'type'                 => 'object',
			'required'             => array_merge( array( 'id' ), $extra_required ),
			'properties'           => array_merge(
				array(
					'id' => array(
						'type'        => 'integer',
						'description' => $id_description,
					),
				),
				$extra_properties
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Normalizes page/per_page input into page/per_page/number/offset.
	 *
	 * @param array<string, mixed> $input Raw ability input.
	 * @return array<string, int>
	 */
	private static function paginate_args( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$page     = isset( $input['page'] ) ? absint( $input['page'] ) : 1;
		$per_page = isset( $input['per_page'] ) ? absint( $input['per_page'] ) : 20;

		if ( $page < 1 ) {
			$page = 1;
		}
		if ( $per_page < 1 ) {
			$per_page = 20;
		}
		if ( $per_page > 100 ) {
			$per_page = 100;
		}

		return array(
			'page'     => $page,
			'per_page' => $per_page,
			'number'   => $per_page,
			'offset'   => ( $page - 1 ) * $per_page,
		);
	}

	/**
	 * Neutralizes spreadsheet formula injection in a CSV cell. A cell whose first
	 * character is one a spreadsheet may treat as a formula (= + - @) or a control
	 * character (tab, carriage return) is prefixed with a single quote so Excel and
	 * Google Sheets render it as literal text instead of executing it.
	 *
	 * @param string $cell Cell value.
	 * @return string
	 */
	private static function csv_escape_formula( $cell ) {
		$cell = (string) $cell;
		if ( '' !== $cell && in_array( $cell[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			return "'" . $cell;
		}
		return $cell;
	}

	// ---- Ported provider abilities (register methods + callbacks + summary/CSV helpers) ----
	private static function register_cf7_abilities() {

		Naming::register_ability(
			'mosmcp/cf7-list-forms',
			array(
				'label'               => __( 'List Contact Form 7 Forms', 'mosmcp-abilities' ),
				'description'         => __( 'Lists all Contact Form 7 forms on the site (ID and title), paginated. Read-only. Requires Contact Form 7 only — not Flamingo.', 'mosmcp-abilities' ),
				'category'            => self::CATEGORY_FORMS,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'page'     => array(
							'type'    => 'integer',
							'default' => 1,
						),
						'per_page' => array(
							'type'    => 'integer',
							'default' => 20,
						),
					),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'forms', 'page', 'per_page' ),
					'properties'           => array(
						'forms'    => array( 'type' => 'array' ),
						'page'     => array( 'type' => 'integer' ),
						'per_page' => array( 'type' => 'integer' ),
					),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( __CLASS__, 'cf7_list_forms' ),
				'permission_callback' => array( __CLASS__, 'can_manage_forms' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
						'open_world'  => false,
					),
					'required_cap' => 'manage_options',
					'show_in_rest' => false,
				),
			)
		);

		Naming::register_ability(
			'mosmcp/cf7-get-form-by-id',
			array(
				'label'               => __( 'Get Contact Form 7 Form by ID', 'mosmcp-abilities' ),
				'description'         => __( "Retrieves a single Contact Form 7 form's full configuration (title, form template markup, mail settings, additional settings) by form ID. Read-only.", 'mosmcp-abilities' ),
				'category'            => self::CATEGORY_FORMS,
				'input_schema'        => self::id_input_schema( __( 'The Contact Form 7 form ID (required).', 'mosmcp-abilities' ) ),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'                  => array( 'type' => 'integer' ),
						'title'               => array( 'type' => 'string' ),
						'form'                => array( 'type' => 'string' ),
						'mail'                => array( 'type' => 'object' ),
						'additional_settings' => array( 'type' => 'string' ),
					),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( __CLASS__, 'cf7_get_form_by_id' ),
				'permission_callback' => array( __CLASS__, 'can_manage_forms' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
						'open_world'  => false,
					),
					'required_cap' => 'manage_options',
					'show_in_rest' => false,
				),
			)
		);

		Naming::register_ability(
			'mosmcp/cf7-get-form-fields',
			array(
				'label'               => __( 'Get Contact Form 7 Form Fields', 'mosmcp-abilities' ),
				'description'         => __( 'Returns the field/tag structure (name, type, required) of a Contact Form 7 form, given its form ID. Read-only.', 'mosmcp-abilities' ),
				'category'            => self::CATEGORY_FORMS,
				'input_schema'        => self::id_input_schema( __( 'The Contact Form 7 form ID (required).', 'mosmcp-abilities' ) ),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'id', 'fields' ),
					'properties'           => array(
						'id'     => array( 'type' => 'integer' ),
						'fields' => array( 'type' => 'array' ),
					),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( __CLASS__, 'cf7_get_form_fields' ),
				'permission_callback' => array( __CLASS__, 'can_manage_forms' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
						'open_world'  => false,
					),
					'required_cap' => 'manage_options',
					'show_in_rest' => false,
				),
			)
		);

		Naming::register_ability(
			'mosmcp/cf7-get-mail-settings',
			array(
				'label'               => __( 'Get Contact Form 7 Mail Settings', 'mosmcp-abilities' ),
				'description'         => __( 'Returns the mail configuration (to/from/subject/body) for a Contact Form 7 form, given its form ID. Read-only.', 'mosmcp-abilities' ),
				'category'            => self::CATEGORY_FORMS,
				'input_schema'        => self::id_input_schema( __( 'The Contact Form 7 form ID (required).', 'mosmcp-abilities' ) ),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'id', 'mail' ),
					'properties'           => array(
						'id'   => array( 'type' => 'integer' ),
						'mail' => array( 'type' => 'object' ),
					),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( __CLASS__, 'cf7_get_mail_settings' ),
				'permission_callback' => array( __CLASS__, 'can_manage_forms' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
						'open_world'  => false,
					),
					'required_cap' => 'manage_options',
					'show_in_rest' => false,
				),
			)
		);

		$flamingo_entry_schema = array(
			'type'       => 'object',
			'properties' => array(
				'id'      => array( 'type' => 'integer' ),
				'subject' => array( 'type' => 'string' ),
				'date'    => array( 'type' => 'string' ),
				'fields'  => array( 'type' => 'object' ),
				'read'    => array( 'type' => 'boolean' ),
			),
		);

		Naming::register_ability(
			'mosmcp/cf7-flamingo-list-entries',
			array(
				'label'               => __( 'List Contact Form 7 Submissions (Flamingo)', 'mosmcp-abilities' ),
				'description'         => __( 'Lists Contact Form 7 submissions captured by the Flamingo companion plugin, optionally filtered by form_id, paginated. Read-only. Requires Flamingo (CF7 itself does not store submissions).', 'mosmcp-abilities' ),
				'category'            => self::CATEGORY_FORMS,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'form_id'  => array(
							'type'        => 'integer',
							'description' => __( 'Optional Contact Form 7 form ID to filter by.', 'mosmcp-abilities' ),
						),
						'page'     => array(
							'type'    => 'integer',
							'default' => 1,
						),
						'per_page' => array(
							'type'    => 'integer',
							'default' => 20,
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'entries', 'page', 'per_page' ),
					'properties'           => array(
						'entries'  => array(
							'type'  => 'array',
							'items' => $flamingo_entry_schema,
						),
						'page'     => array( 'type' => 'integer' ),
						'per_page' => array( 'type' => 'integer' ),
					),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( __CLASS__, 'cf7_flamingo_list_entries' ),
				'permission_callback' => array( __CLASS__, 'can_manage_forms' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
						'open_world'  => false,
					),
					'required_cap' => 'manage_options',
					'show_in_rest' => false,
				),
			)
		);

		Naming::register_ability(
			'mosmcp/cf7-flamingo-get-entry-by-id',
			array(
				'label'               => __( 'Get Contact Form 7 Submission by ID (Flamingo)', 'mosmcp-abilities' ),
				'description'         => __( 'Retrieves a single Contact Form 7 submission captured by Flamingo, given its Flamingo entry ID. Read-only. Requires Flamingo.', 'mosmcp-abilities' ),
				'category'            => self::CATEGORY_FORMS,
				'input_schema'        => self::id_input_schema( __( 'The Flamingo submission ID (required).', 'mosmcp-abilities' ) ),
				'output_schema'       => $flamingo_entry_schema,
				'execute_callback'    => array( __CLASS__, 'cf7_flamingo_get_entry_by_id' ),
				'permission_callback' => array( __CLASS__, 'can_manage_forms' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
						'open_world'  => false,
					),
					'required_cap' => 'manage_options',
					'show_in_rest' => false,
				),
			)
		);

		Naming::register_ability(
			'mosmcp/cf7-flamingo-count-entries',
			array(
				'label'               => __( 'Count Contact Form 7 Submissions (Flamingo)', 'mosmcp-abilities' ),
				'description'         => __( 'Returns the total count of Flamingo-captured submissions, optionally filtered by form_id. Read-only. Requires Flamingo.', 'mosmcp-abilities' ),
				'category'            => self::CATEGORY_FORMS,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'form_id' => array(
							'type'        => 'integer',
							'description' => __( 'Optional Contact Form 7 form ID to filter by.', 'mosmcp-abilities' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'count' ),
					'properties'           => array(
						'count' => array( 'type' => 'integer' ),
					),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( __CLASS__, 'cf7_flamingo_count_entries' ),
				'permission_callback' => array( __CLASS__, 'can_manage_forms' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
						'open_world'  => false,
					),
					'required_cap' => 'manage_options',
					'show_in_rest' => false,
				),
			)
		);

		Naming::register_ability(
			'mosmcp/cf7-flamingo-export-entries-csv',
			array(
				'label'               => __( 'Export Contact Form 7 Submissions as CSV (Flamingo)', 'mosmcp-abilities' ),
				'description'         => __( 'Exports Flamingo-captured submissions to CSV text, optionally filtered by form_id. Returns the CSV content inline. Requires Flamingo.', 'mosmcp-abilities' ),
				'category'            => self::CATEGORY_FORMS,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'form_id' => array(
							'type'        => 'integer',
							'description' => __( 'Optional Contact Form 7 form ID to filter by.', 'mosmcp-abilities' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'csv', 'row_count' ),
					'properties'           => array(
						'csv'       => array( 'type' => 'string' ),
						'row_count' => array( 'type' => 'integer' ),
					),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( __CLASS__, 'cf7_flamingo_export_entries_csv' ),
				'permission_callback' => array( __CLASS__, 'can_manage_forms' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
						'open_world'  => false,
					),
					'required_cap' => 'manage_options',
					'show_in_rest' => false,
				),
			)
		);

		Naming::register_ability(
			'mosmcp/cf7-flamingo-mark-entry-read',
			array(
				'label'               => __( 'Mark Contact Form 7 Submission as Read (Flamingo)', 'mosmcp-abilities' ),
				'description'         => __( 'Marks a Flamingo-captured submission as read, given its entry ID. Requires Flamingo.', 'mosmcp-abilities' ),
				'category'            => self::CATEGORY_FORMS,
				'input_schema'        => self::id_input_schema( __( 'The Flamingo submission ID (required).', 'mosmcp-abilities' ) ),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'id', 'read' ),
					'properties'           => array(
						'id'   => array( 'type' => 'integer' ),
						'read' => array( 'type' => 'boolean' ),
					),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( __CLASS__, 'cf7_flamingo_mark_entry_read' ),
				'permission_callback' => array( __CLASS__, 'can_manage_forms' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
						'open_world'  => false,
					),
					'required_cap' => 'manage_options',
					'show_in_rest' => false,
				),
			)
		);

		Naming::register_ability(
			'mosmcp/cf7-flamingo-mark-entry-unread',
			array(
				'label'               => __( 'Mark Contact Form 7 Submission as Unread (Flamingo)', 'mosmcp-abilities' ),
				'description'         => __( 'Marks a Flamingo-captured submission as unread, given its entry ID. Requires Flamingo.', 'mosmcp-abilities' ),
				'category'            => self::CATEGORY_FORMS,
				'input_schema'        => self::id_input_schema( __( 'The Flamingo submission ID (required).', 'mosmcp-abilities' ) ),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'id', 'read' ),
					'properties'           => array(
						'id'   => array( 'type' => 'integer' ),
						'read' => array( 'type' => 'boolean' ),
					),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( __CLASS__, 'cf7_flamingo_mark_entry_unread' ),
				'permission_callback' => array( __CLASS__, 'can_manage_forms' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
						'open_world'  => false,
					),
					'required_cap' => 'manage_options',
					'show_in_rest' => false,
				),
			)
		);

		Naming::register_ability(
			'mosmcp/cf7-flamingo-delete-entry',
			array(
				'label'               => __( 'Delete Contact Form 7 Submission (Flamingo)', 'mosmcp-abilities' ),
				'description'         => __( 'Moves a Flamingo-captured submission to trash (recoverable), given its entry ID. For irreversible deletion use cf7-flamingo-permanently-delete-entry. Requires Flamingo.', 'mosmcp-abilities' ),
				'category'            => self::CATEGORY_FORMS,
				'input_schema'        => self::id_input_schema( __( 'The Flamingo submission ID (required).', 'mosmcp-abilities' ) ),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'id', 'trashed' ),
					'properties'           => array(
						'id'      => array( 'type' => 'integer' ),
						'trashed' => array( 'type' => 'boolean' ),
					),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( __CLASS__, 'cf7_flamingo_delete_entry' ),
				'permission_callback' => array( __CLASS__, 'can_manage_forms' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => true,
						'open_world'  => false,
					),
					'required_cap' => 'manage_options',
					'show_in_rest' => false,
				),
			)
		);

		Naming::register_ability(
			'mosmcp/cf7-flamingo-permanently-delete-entry',
			array(
				'label'               => __( 'Permanently Delete Contact Form 7 Submission (Flamingo)', 'mosmcp-abilities' ),
				'description'         => __( 'Irreversibly deletes a Flamingo-captured submission, given its entry ID, bypassing trash. Destructive — used for removing sensitive lead data entirely. Requires Flamingo.', 'mosmcp-abilities' ),
				'category'            => self::CATEGORY_FORMS,
				'input_schema'        => self::id_input_schema( __( 'The Flamingo submission ID (required).', 'mosmcp-abilities' ) ),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'id', 'deleted' ),
					'properties'           => array(
						'id'      => array( 'type' => 'integer' ),
						'deleted' => array( 'type' => 'boolean' ),
					),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( __CLASS__, 'cf7_flamingo_permanently_delete_entry' ),
				'permission_callback' => array( __CLASS__, 'can_manage_forms' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => false,
						'open_world'  => false,
					),
					'required_cap' => 'manage_options',
					'show_in_rest' => false,
				),
			)
		);
	}

	public static function cf7_list_forms( $input = array() ) {
		$check = self::require_cf7();
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$input    = is_array( $input ) ? $input : array();
		$paginate = self::paginate_args( $input );

		$forms = WPCF7_ContactForm::find(
			array(
				'posts_per_page' => $paginate['per_page'],
				'offset'         => $paginate['offset'],
			)
		);

		return array(
			'forms'    => array_map(
				static function ( $form ) {
					return array(
						'id'    => (int) $form->id(),
						'title' => (string) $form->title(),
					);
				},
				$forms
			),
			'page'     => $paginate['page'],
			'per_page' => $paginate['per_page'],
		);
	}

	public static function cf7_get_form_by_id( $input = array() ) {
		$check = self::require_cf7();
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$input = is_array( $input ) ? $input : array();
		$id    = isset( $input['id'] ) ? absint( $input['id'] ) : 0;

		$form = $id > 0 ? WPCF7_ContactForm::get_instance( $id ) : null;
		if ( ! $form ) {
			return new WP_Error( self::ERR_FORM_NOT_FOUND, __( 'No Contact Form 7 form was found with that ID.', 'mosmcp-abilities' ) );
		}

		return array(
			'id'                  => (int) $form->id(),
			'title'               => (string) $form->title(),
			'form'                => (string) $form->prop( 'form' ),
			'mail'                => (object) (array) $form->prop( 'mail' ),
			'additional_settings' => (string) $form->prop( 'additional_settings' ),
		);
	}

	public static function cf7_get_form_fields( $input = array() ) {
		$check = self::require_cf7();
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$input = is_array( $input ) ? $input : array();
		$id    = isset( $input['id'] ) ? absint( $input['id'] ) : 0;

		$form = $id > 0 ? WPCF7_ContactForm::get_instance( $id ) : null;
		if ( ! $form ) {
			return new WP_Error( self::ERR_FORM_NOT_FOUND, __( 'No Contact Form 7 form was found with that ID.', 'mosmcp-abilities' ) );
		}

		$tags   = $form->scan_form_tags();
		$fields = array_map(
			static function ( $tag ) {
				return array(
					'name'     => (string) $tag->name,
					'type'     => (string) $tag->basetype,
					'required' => (bool) ( false !== strpos( (string) $tag->type, '*' ) ),
				);
			},
			$tags
		);

		return array(
			'id'     => $id,
			'fields' => $fields,
		);
	}

	public static function cf7_get_mail_settings( $input = array() ) {
		$check = self::require_cf7();
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$input = is_array( $input ) ? $input : array();
		$id    = isset( $input['id'] ) ? absint( $input['id'] ) : 0;

		$form = $id > 0 ? WPCF7_ContactForm::get_instance( $id ) : null;
		if ( ! $form ) {
			return new WP_Error( self::ERR_FORM_NOT_FOUND, __( 'No Contact Form 7 form was found with that ID.', 'mosmcp-abilities' ) );
		}

		return array(
			'id'   => $id,
			'mail' => (object) (array) $form->prop( 'mail' ),
		);
	}

	/**
	 * Shapes a Flamingo `flamingo_inbound` post into the ability's entry schema.
	 *
	 * @param WP_Post $post
	 * @return array<string, mixed>
	 */
	private static function flamingo_entry_summary( $post ) {
		$fields = get_post_meta( $post->ID, '_fields', true );

		return array(
			'id'      => (int) $post->ID,
			'subject' => (string) $post->post_title,
			'date'    => (string) $post->post_date,
			'fields'  => (object) ( is_array( $fields ) ? $fields : array() ),
			'read'    => (bool) get_post_meta( $post->ID, self::READ_META, true ),
		);
	}

	public static function cf7_flamingo_list_entries( $input = array() ) {
		$check = self::require_flamingo();
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$input    = is_array( $input ) ? $input : array();
		$paginate = self::paginate_args( $input );

		$args = array(
			'post_type'      => self::FLAMINGO_POST_TYPE,
			'posts_per_page' => $paginate['per_page'],
			'paged'          => $paginate['page'],
		);
		if ( ! empty( $input['form_id'] ) ) {
			$args['meta_query'] = array(
				array(
					'key'   => self::FLAMINGO_CHANNEL_META,
					'value' => absint( $input['form_id'] ),
				),
			);
		}

		$posts = get_posts( $args );

		return array(
			'entries'  => array_map( array( __CLASS__, 'flamingo_entry_summary' ), $posts ),
			'page'     => $paginate['page'],
			'per_page' => $paginate['per_page'],
		);
	}

	public static function cf7_flamingo_get_entry_by_id( $input = array() ) {
		$check = self::require_flamingo();
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$input = is_array( $input ) ? $input : array();
		$id    = isset( $input['id'] ) ? absint( $input['id'] ) : 0;

		$post = $id > 0 ? get_post( $id ) : null;
		if ( ! $post || self::FLAMINGO_POST_TYPE !== $post->post_type ) {
			return new WP_Error( self::ERR_ENTRY_NOT_FOUND, __( 'No Flamingo submission was found with that ID.', 'mosmcp-abilities' ) );
		}

		return self::flamingo_entry_summary( $post );
	}

	public static function cf7_flamingo_count_entries( $input = array() ) {
		$check = self::require_flamingo();
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$input = is_array( $input ) ? $input : array();

		$args = array(
			'post_type'      => self::FLAMINGO_POST_TYPE,
			'posts_per_page' => -1,
			'fields'         => 'ids',
		);
		if ( ! empty( $input['form_id'] ) ) {
			$args['meta_query'] = array(
				array(
					'key'   => self::FLAMINGO_CHANNEL_META,
					'value' => absint( $input['form_id'] ),
				),
			);
		}

		$posts = get_posts( $args );

		return array( 'count' => count( $posts ) );
	}

	public static function cf7_flamingo_export_entries_csv( $input = array() ) {
		$check = self::require_flamingo();
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$input = is_array( $input ) ? $input : array();

		$args = array(
			'post_type'      => self::FLAMINGO_POST_TYPE,
			'posts_per_page' => -1,
		);
		if ( ! empty( $input['form_id'] ) ) {
			$args['meta_query'] = array(
				array(
					'key'   => self::FLAMINGO_CHANNEL_META,
					'value' => absint( $input['form_id'] ),
				),
			);
		}

		$posts = get_posts( $args );
		$rows  = array_map( array( __CLASS__, 'flamingo_entry_summary' ), $posts );

		return array(
			'csv'       => self::csv_from_rows( $rows ),
			'row_count' => count( $rows ),
		);
	}

	public static function cf7_flamingo_mark_entry_read( $input = array() ) {
		return self::cf7_flamingo_set_read( $input, true );
	}

	public static function cf7_flamingo_mark_entry_unread( $input = array() ) {
		return self::cf7_flamingo_set_read( $input, false );
	}

	private static function cf7_flamingo_set_read( $input, $read ) {
		$check = self::require_flamingo();
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$input = is_array( $input ) ? $input : array();
		$id    = isset( $input['id'] ) ? absint( $input['id'] ) : 0;

		$post = $id > 0 ? get_post( $id ) : null;
		if ( ! $post || self::FLAMINGO_POST_TYPE !== $post->post_type ) {
			return new WP_Error( self::ERR_ENTRY_NOT_FOUND, __( 'No Flamingo submission was found with that ID.', 'mosmcp-abilities' ) );
		}

		update_post_meta( $id, self::READ_META, (int) $read );

		return array(
			'id'   => $id,
			'read' => $read,
		);
	}

	public static function cf7_flamingo_delete_entry( $input = array() ) {
		$check = self::require_flamingo();
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$input = is_array( $input ) ? $input : array();
		$id    = isset( $input['id'] ) ? absint( $input['id'] ) : 0;

		$post = $id > 0 ? get_post( $id ) : null;
		if ( ! $post || self::FLAMINGO_POST_TYPE !== $post->post_type ) {
			return new WP_Error( self::ERR_ENTRY_NOT_FOUND, __( 'No Flamingo submission was found with that ID.', 'mosmcp-abilities' ) );
		}

		if ( ! wp_trash_post( $id ) ) {
			return new WP_Error( 'moca_trash_failed', __( 'Failed to trash the submission.', 'mosmcp-abilities' ) );
		}

		return array(
			'id'      => $id,
			'trashed' => true,
		);
	}

	public static function cf7_flamingo_permanently_delete_entry( $input = array() ) {
		$check = self::require_flamingo();
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$input = is_array( $input ) ? $input : array();
		$id    = isset( $input['id'] ) ? absint( $input['id'] ) : 0;

		$post = $id > 0 ? get_post( $id ) : null;
		if ( ! $post || self::FLAMINGO_POST_TYPE !== $post->post_type ) {
			return new WP_Error( self::ERR_ENTRY_NOT_FOUND, __( 'No Flamingo submission was found with that ID.', 'mosmcp-abilities' ) );
		}

		if ( ! wp_delete_post( $id, true ) ) {
			return new WP_Error( 'moca_delete_failed', __( 'Failed to delete the submission.', 'mosmcp-abilities' ) );
		}

		return array(
			'id'      => $id,
			'deleted' => true,
		);
	}

	/**
	 * Builds a CSV string (with header row) from an array of associative rows.
	 * Nested values (e.g. the `fields`/`meta` object) are JSON-encoded per cell.
	 *
	 * @param array<int, array<string, mixed>> $rows
	 * @return string
	 */
	private static function csv_from_rows( array $rows ) {
		if ( empty( $rows ) ) {
			return '';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- In-memory php://temp stream for building a CSV string; not a filesystem operation.
		$handle = fopen( 'php://temp', 'r+' );

		$headers = array_keys( $rows[0] );
		fputcsv( $handle, $headers );

		foreach ( $rows as $row ) {
			$line = array();
			foreach ( $headers as $header ) {
				$value  = $row[ $header ] ?? '';
				$cell   = is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value );
				$line[] = self::csv_escape_formula( $cell );
			}
			fputcsv( $handle, $line );
		}

		rewind( $handle );
		$csv = stream_get_contents( $handle );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the in-memory php://temp stream.
		fclose( $handle );

		return (string) $csv;
	}

	/*
	==================================================================== *
	 * WPFORMS (14)
	 *
	 * NOTE: WPForms is not installed on the site this was built on. Entry
	 * storage/CSV export in particular are WPForms Pro (or add-on) features
	 * in some WPForms configurations — verify wpforms()->form / wpforms()->entry
	 * availability once WPForms is actually installed.
	 * ==================================================================== */

	private static function register_wpforms_abilities() {

		Naming::register_ability(
			'mosmcp/wpforms-list-forms',
			array(
				'label'               => __( 'List WPForms', 'mosmcp-abilities' ),
				'description'         => __( 'Lists all WPForms on the site (ID and title), paginated. Read-only.', 'mosmcp-abilities' ),
				'category'            => self::CATEGORY_FORMS,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'page'     => array(
							'type'    => 'integer',
							'default' => 1,
						),
						'per_page' => array(
							'type'    => 'integer',
							'default' => 20,
						),
					),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'forms', 'page', 'per_page' ),
					'properties'           => array(
						'forms'    => array( 'type' => 'array' ),
						'page'     => array( 'type' => 'integer' ),
						'per_page' => array( 'type' => 'integer' ),
					),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( __CLASS__, 'wpforms_list_forms' ),
				'permission_callback' => array( __CLASS__, 'can_manage_forms' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
						'open_world'  => false,
					),
					'required_cap' => 'manage_options',
					'show_in_rest' => false,
				),
			)
		);

		Naming::register_ability(
			'mosmcp/wpforms-get-form-by-id',
			array(
				'label'               => __( 'Get WPForms Form by ID', 'mosmcp-abilities' ),
				'description'         => __( "Retrieves a single WPForms form's full configuration (fields, settings, notifications, confirmations) by form ID. Read-only.", 'mosmcp-abilities' ),
				'category'            => self::CATEGORY_FORMS,
				'input_schema'        => self::id_input_schema( __( 'The WPForms form ID (required).', 'mosmcp-abilities' ) ),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'            => array( 'type' => 'integer' ),
						'title'         => array( 'type' => 'string' ),
						'fields'        => array( 'type' => 'array' ),
						'settings'      => array( 'type' => 'object' ),
						'notifications' => array( 'type' => 'object' ),
						'confirmations' => array( 'type' => 'object' ),
					),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( __CLASS__, 'wpforms_get_form_by_id' ),
				'permission_callback' => array( __CLASS__, 'can_manage_forms' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
						'open_world'  => false,
					),
					'required_cap' => 'manage_options',
					'show_in_rest' => false,
				),
			)
		);

		Naming::register_ability(
			'mosmcp/wpforms-get-form-fields',
			array(
				'label'               => __( 'Get WPForms Form Fields', 'mosmcp-abilities' ),
				'description'         => __( 'Returns the field structure of a WPForms form, given its form ID. Read-only.', 'mosmcp-abilities' ),
				'category'            => self::CATEGORY_FORMS,
				'input_schema'        => self::id_input_schema( __( 'The WPForms form ID (required).', 'mosmcp-abilities' ) ),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'id', 'fields' ),
					'properties'           => array(
						'id'     => array( 'type' => 'integer' ),
						'fields' => array( 'type' => 'array' ),
					),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( __CLASS__, 'wpforms_get_form_fields' ),
				'permission_callback' => array( __CLASS__, 'can_manage_forms' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
						'open_world'  => false,
					),
					'required_cap' => 'manage_options',
					'show_in_rest' => false,
				),
			)
		);

		Naming::register_ability(
			'mosmcp/wpforms-get-form-settings',
			array(
				'label'               => __( 'Get WPForms Form Settings', 'mosmcp-abilities' ),
				'description'         => __( 'Returns the general settings configured for a WPForms form, given its form ID. Read-only.', 'mosmcp-abilities' ),
				'category'            => self::CATEGORY_FORMS,
				'input_schema'        => self::id_input_schema( __( 'The WPForms form ID (required).', 'mosmcp-abilities' ) ),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'id', 'settings' ),
					'properties'           => array(
						'id'       => array( 'type' => 'integer' ),
						'settings' => array( 'type' => 'object' ),
					),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( __CLASS__, 'wpforms_get_form_settings' ),
				'permission_callback' => array( __CLASS__, 'can_manage_forms' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
						'open_world'  => false,
					),
					'required_cap' => 'manage_options',
					'show_in_rest' => false,
				),
			)
		);

		Naming::register_ability(
			'mosmcp/wpforms-get-form-notifications',
			array(
				'label'               => __( 'Get WPForms Form Notifications', 'mosmcp-abilities' ),
				'description'         => __( 'Returns the configured email notifications for a WPForms form, given its form ID. Read-only.', 'mosmcp-abilities' ),
				'category'            => self::CATEGORY_FORMS,
				'input_schema'        => self::id_input_schema( __( 'The WPForms form ID (required).', 'mosmcp-abilities' ) ),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'id', 'notifications' ),
					'properties'           => array(
						'id'            => array( 'type' => 'integer' ),
						'notifications' => array( 'type' => 'object' ),
					),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( __CLASS__, 'wpforms_get_form_notifications' ),
				'permission_callback' => array( __CLASS__, 'can_manage_forms' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
						'open_world'  => false,
					),
					'required_cap' => 'manage_options',
					'show_in_rest' => false,
				),
			)
		);

		$wpforms_entry_schema = array(
			'type'       => 'object',
			'properties' => array(
				'id'      => array( 'type' => 'integer' ),
				'form_id' => array( 'type' => 'integer' ),
				'date'    => array( 'type' => 'string' ),
				'status'  => array( 'type' => 'string' ),
				'viewed'  => array( 'type' => 'boolean' ),
				'fields'  => array( 'type' => 'object' ),
			),
		);

		Naming::register_ability(
			'mosmcp/wpforms-list-entries',
			array(
				'label'               => __( 'List WPForms Entries', 'mosmcp-abilities' ),
				'description'         => __( 'Lists submitted entries for a WPForms form, given its form ID, paginated. Read-only.', 'mosmcp-abilities' ),
				'category'            => self::CATEGORY_FORMS,
				'input_schema'        => self::id_input_schema(
					__( 'The WPForms form ID (required).', 'mosmcp-abilities' ),
					array(
						'page'     => array(
							'type'    => 'integer',
							'default' => 1,
						),
						'per_page' => array(
							'type'    => 'integer',
							'default' => 20,
						),
					)
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'entries', 'page', 'per_page' ),
					'properties'           => array(
						'entries'  => array(
							'type'  => 'array',
							'items' => $wpforms_entry_schema,
						),
						'page'     => array( 'type' => 'integer' ),
						'per_page' => array( 'type' => 'integer' ),
					),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( __CLASS__, 'wpforms_list_entries' ),
				'permission_callback' => array( __CLASS__, 'can_manage_forms' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
						'open_world'  => false,
					),
					'required_cap' => 'manage_options',
					'show_in_rest' => false,
				),
			)
		);

		Naming::register_ability(
			'mosmcp/wpforms-get-entry-by-id',
			array(
				'label'               => __( 'Get WPForms Entry by ID', 'mosmcp-abilities' ),
				'description'         => __( 'Retrieves a single WPForms entry by ID. Read-only.', 'mosmcp-abilities' ),
				'category'            => self::CATEGORY_FORMS,
				'input_schema'        => self::id_input_schema( __( 'The WPForms entry ID (required).', 'mosmcp-abilities' ) ),
				'output_schema'       => $wpforms_entry_schema,
				'execute_callback'    => array( __CLASS__, 'wpforms_get_entry_by_id' ),
				'permission_callback' => array( __CLASS__, 'can_manage_forms' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
						'open_world'  => false,
					),
					'required_cap' => 'manage_options',
					'show_in_rest' => false,
				),
			)
		);

		Naming::register_ability(
			'mosmcp/wpforms-count-entries',
			array(
				'label'               => __( 'Count WPForms Entries', 'mosmcp-abilities' ),
				'description'         => __( 'Returns the total entry count for a WPForms form, given its form ID. Read-only.', 'mosmcp-abilities' ),
				'category'            => self::CATEGORY_FORMS,
				'input_schema'        => self::id_input_schema( __( 'The WPForms form ID (required).', 'mosmcp-abilities' ) ),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'id', 'count' ),
					'properties'           => array(
						'id'    => array( 'type' => 'integer' ),
						'count' => array( 'type' => 'integer' ),
					),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( __CLASS__, 'wpforms_count_entries' ),
				'permission_callback' => array( __CLASS__, 'can_manage_forms' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
						'open_world'  => false,
					),
					'required_cap' => 'manage_options',
					'show_in_rest' => false,
				),
			)
		);

		Naming::register_ability(
			'mosmcp/wpforms-export-entries-csv',
			array(
				'label'               => __( 'Export WPForms Entries as CSV', 'mosmcp-abilities' ),
				'description'         => __( "Exports a WPForms form's entries to CSV text, given its form ID. Returns the CSV content inline.", 'mosmcp-abilities' ),
				'category'            => self::CATEGORY_FORMS,
				'input_schema'        => self::id_input_schema( __( 'The WPForms form ID (required).', 'mosmcp-abilities' ) ),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'csv', 'row_count' ),
					'properties'           => array(
						'csv'       => array( 'type' => 'string' ),
						'row_count' => array( 'type' => 'integer' ),
					),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( __CLASS__, 'wpforms_export_entries_csv' ),
				'permission_callback' => array( __CLASS__, 'can_manage_forms' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
						'open_world'  => false,
					),
					'required_cap' => 'manage_options',
					'show_in_rest' => false,
				),
			)
		);

		Naming::register_ability(
			'mosmcp/wpforms-mark-entry-read',
			array(
				'label'               => __( 'Mark WPForms Entry as Read', 'mosmcp-abilities' ),
				'description'         => __( 'Marks a WPForms entry as read (viewed), given its entry ID.', 'mosmcp-abilities' ),
				'category'            => self::CATEGORY_FORMS,
				'input_schema'        => self::id_input_schema( __( 'The WPForms entry ID (required).', 'mosmcp-abilities' ) ),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'id', 'viewed' ),
					'properties'           => array(
						'id'     => array( 'type' => 'integer' ),
						'viewed' => array( 'type' => 'boolean' ),
					),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( __CLASS__, 'wpforms_mark_entry_read' ),
				'permission_callback' => array( __CLASS__, 'can_manage_forms' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
						'open_world'  => false,
					),
					'required_cap' => 'manage_options',
					'show_in_rest' => false,
				),
			)
		);

		Naming::register_ability(
			'mosmcp/wpforms-mark-entry-unread',
			array(
				'label'               => __( 'Mark WPForms Entry as Unread', 'mosmcp-abilities' ),
				'description'         => __( 'Marks a WPForms entry as unread, given its entry ID.', 'mosmcp-abilities' ),
				'category'            => self::CATEGORY_FORMS,
				'input_schema'        => self::id_input_schema( __( 'The WPForms entry ID (required).', 'mosmcp-abilities' ) ),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'id', 'viewed' ),
					'properties'           => array(
						'id'     => array( 'type' => 'integer' ),
						'viewed' => array( 'type' => 'boolean' ),
					),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( __CLASS__, 'wpforms_mark_entry_unread' ),
				'permission_callback' => array( __CLASS__, 'can_manage_forms' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
						'open_world'  => false,
					),
					'required_cap' => 'manage_options',
					'show_in_rest' => false,
				),
			)
		);

		Naming::register_ability(
			'mosmcp/wpforms-trash-entry',
			array(
				'label'               => __( 'Trash WPForms Entry', 'mosmcp-abilities' ),
				'description'         => __( 'Moves a WPForms entry to trash (recoverable), given its entry ID. To undo, use wpforms-restore-entry.', 'mosmcp-abilities' ),
				'category'            => self::CATEGORY_FORMS,
				'input_schema'        => self::id_input_schema( __( 'The WPForms entry ID (required).', 'mosmcp-abilities' ) ),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'id', 'trashed' ),
					'properties'           => array(
						'id'      => array( 'type' => 'integer' ),
						'trashed' => array( 'type' => 'boolean' ),
					),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( __CLASS__, 'wpforms_trash_entry' ),
				'permission_callback' => array( __CLASS__, 'can_manage_forms' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => true,
						'open_world'  => false,
					),
					'required_cap' => 'manage_options',
					'show_in_rest' => false,
				),
			)
		);

		Naming::register_ability(
			'mosmcp/wpforms-restore-entry',
			array(
				'label'               => __( 'Restore WPForms Entry', 'mosmcp-abilities' ),
				'description'         => __( 'Restores a WPForms entry from trash, given its entry ID.', 'mosmcp-abilities' ),
				'category'            => self::CATEGORY_FORMS,
				'input_schema'        => self::id_input_schema( __( 'The WPForms entry ID (required).', 'mosmcp-abilities' ) ),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'id', 'restored' ),
					'properties'           => array(
						'id'       => array( 'type' => 'integer' ),
						'restored' => array( 'type' => 'boolean' ),
					),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( __CLASS__, 'wpforms_restore_entry' ),
				'permission_callback' => array( __CLASS__, 'can_manage_forms' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
						'open_world'  => false,
					),
					'required_cap' => 'manage_options',
					'show_in_rest' => false,
				),
			)
		);

		Naming::register_ability(
			'mosmcp/wpforms-delete-entry-permanently',
			array(
				'label'               => __( 'Permanently Delete WPForms Entry', 'mosmcp-abilities' ),
				'description'         => __( 'Irreversibly deletes a WPForms entry, given its entry ID, bypassing trash. Destructive — used for removing sensitive lead data entirely.', 'mosmcp-abilities' ),
				'category'            => self::CATEGORY_FORMS,
				'input_schema'        => self::id_input_schema( __( 'The WPForms entry ID (required).', 'mosmcp-abilities' ) ),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'id', 'deleted' ),
					'properties'           => array(
						'id'      => array( 'type' => 'integer' ),
						'deleted' => array( 'type' => 'boolean' ),
					),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( __CLASS__, 'wpforms_delete_entry_permanently' ),
				'permission_callback' => array( __CLASS__, 'can_manage_forms' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => false,
						'open_world'  => false,
					),
					'required_cap' => 'manage_options',
					'show_in_rest' => false,
				),
			)
		);
	}

	/**
	 * Fetches a WPForms form's decoded content (fields/settings/notifications/confirmations).
	 *
	 * @param int $id Form ID.
	 * @return array<string, mixed>|null
	 */
	private static function wpforms_form_content( $id ) {
		$check = self::require_wpforms();
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$content = wpforms()->form->get( $id, array( 'content_only' => true ) );
		return is_array( $content ) ? $content : null;
	}

	public static function wpforms_list_forms( $input = array() ) {
		$check = self::require_wpforms();
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$input    = is_array( $input ) ? $input : array();
		$paginate = self::paginate_args( $input );

		$forms = wpforms()->form->get(
			'',
			array(
				'posts_per_page' => $paginate['per_page'],
				'offset'         => $paginate['offset'],
			)
		);
		$forms = is_array( $forms ) ? $forms : array();

		return array(
			'forms'    => array_map(
				static function ( $form ) {
					return array(
						'id'    => (int) $form->ID,
						'title' => (string) $form->post_title,
					);
				},
				$forms
			),
			'page'     => $paginate['page'],
			'per_page' => $paginate['per_page'],
		);
	}

	public static function wpforms_get_form_by_id( $input = array() ) {
		$input   = is_array( $input ) ? $input : array();
		$id      = isset( $input['id'] ) ? absint( $input['id'] ) : 0;
		$content = $id > 0 ? self::wpforms_form_content( $id ) : null;

		if ( is_wp_error( $content ) ) {
			return $content;
		}
		if ( ! $content ) {
			return new WP_Error( self::ERR_FORM_NOT_FOUND, __( 'No WPForms form was found with that ID.', 'mosmcp-abilities' ) );
		}

		return array(
			'id'            => $id,
			'title'         => isset( $content['settings']['form_title'] ) ? (string) $content['settings']['form_title'] : '',
			'fields'        => isset( $content['fields'] ) && is_array( $content['fields'] ) ? array_values( $content['fields'] ) : array(),
			'settings'      => (object) ( isset( $content['settings'] ) && is_array( $content['settings'] ) ? $content['settings'] : array() ),
			'notifications' => (object) ( isset( $content['settings']['notifications'] ) && is_array( $content['settings']['notifications'] ) ? $content['settings']['notifications'] : array() ),
			'confirmations' => (object) ( isset( $content['settings']['confirmations'] ) && is_array( $content['settings']['confirmations'] ) ? $content['settings']['confirmations'] : array() ),
		);
	}

	public static function wpforms_get_form_fields( $input = array() ) {
		$input   = is_array( $input ) ? $input : array();
		$id      = isset( $input['id'] ) ? absint( $input['id'] ) : 0;
		$content = $id > 0 ? self::wpforms_form_content( $id ) : null;

		if ( is_wp_error( $content ) ) {
			return $content;
		}
		if ( ! $content ) {
			return new WP_Error( self::ERR_FORM_NOT_FOUND, __( 'No WPForms form was found with that ID.', 'mosmcp-abilities' ) );
		}

		return array(
			'id'     => $id,
			'fields' => isset( $content['fields'] ) && is_array( $content['fields'] ) ? array_values( $content['fields'] ) : array(),
		);
	}

	public static function wpforms_get_form_settings( $input = array() ) {
		$input   = is_array( $input ) ? $input : array();
		$id      = isset( $input['id'] ) ? absint( $input['id'] ) : 0;
		$content = $id > 0 ? self::wpforms_form_content( $id ) : null;

		if ( is_wp_error( $content ) ) {
			return $content;
		}
		if ( ! $content ) {
			return new WP_Error( self::ERR_FORM_NOT_FOUND, __( 'No WPForms form was found with that ID.', 'mosmcp-abilities' ) );
		}

		return array(
			'id'       => $id,
			'settings' => (object) ( isset( $content['settings'] ) && is_array( $content['settings'] ) ? $content['settings'] : array() ),
		);
	}

	public static function wpforms_get_form_notifications( $input = array() ) {
		$input   = is_array( $input ) ? $input : array();
		$id      = isset( $input['id'] ) ? absint( $input['id'] ) : 0;
		$content = $id > 0 ? self::wpforms_form_content( $id ) : null;

		if ( is_wp_error( $content ) ) {
			return $content;
		}
		if ( ! $content ) {
			return new WP_Error( self::ERR_FORM_NOT_FOUND, __( 'No WPForms form was found with that ID.', 'mosmcp-abilities' ) );
		}

		return array(
			'id'            => $id,
			'notifications' => (object) ( isset( $content['settings']['notifications'] ) && is_array( $content['settings']['notifications'] ) ? $content['settings']['notifications'] : array() ),
		);
	}

	/**
	 * Shapes a WPForms entry row (object/array from wpforms()->entry) into the ability's entry schema.
	 *
	 * @param object $entry
	 * @return array<string, mixed>
	 */
	private static function wpforms_entry_summary( $entry ) {
		$fields = isset( $entry->fields ) ? json_decode( (string) $entry->fields, true ) : array();

		return array(
			'id'      => (int) $entry->entry_id,
			'form_id' => (int) $entry->form_id,
			'date'    => (string) $entry->date,
			'status'  => (string) ( $entry->status ?? '' ),
			'viewed'  => (bool) ( $entry->viewed ?? false ),
			'fields'  => (object) ( is_array( $fields ) ? $fields : array() ),
		);
	}

	public static function wpforms_list_entries( $input = array() ) {
		$check = self::require_wpforms_entries();
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$input   = is_array( $input ) ? $input : array();
		$form_id = isset( $input['id'] ) ? absint( $input['id'] ) : 0;

		if ( $form_id <= 0 ) {
			return new WP_Error( self::ERR_MISSING_FORM_ID, __( 'A WPForms form ID is required.', 'mosmcp-abilities' ) );
		}

		$paginate = self::paginate_args( $input );

		$entries = wpforms()->entry->get_entries(
			array(
				'form_id' => $form_id,
				'number'  => $paginate['per_page'],
				'offset'  => $paginate['offset'],
			)
		);
		$entries = is_array( $entries ) ? $entries : array();

		return array(
			'entries'  => array_map( array( __CLASS__, 'wpforms_entry_summary' ), $entries ),
			'page'     => $paginate['page'],
			'per_page' => $paginate['per_page'],
		);
	}

	public static function wpforms_get_entry_by_id( $input = array() ) {
		$check = self::require_wpforms_entries();
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$input = is_array( $input ) ? $input : array();
		$id    = isset( $input['id'] ) ? absint( $input['id'] ) : 0;

		$entry = $id > 0 ? wpforms()->entry->get( $id ) : null;
		if ( ! $entry ) {
			return new WP_Error( self::ERR_ENTRY_NOT_FOUND, __( 'No WPForms entry was found with that ID.', 'mosmcp-abilities' ) );
		}

		return self::wpforms_entry_summary( $entry );
	}

	public static function wpforms_count_entries( $input = array() ) {
		$check = self::require_wpforms_entries();
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$input   = is_array( $input ) ? $input : array();
		$form_id = isset( $input['id'] ) ? absint( $input['id'] ) : 0;

		if ( $form_id <= 0 ) {
			return new WP_Error( self::ERR_MISSING_FORM_ID, __( 'A WPForms form ID is required.', 'mosmcp-abilities' ) );
		}

		$count = wpforms()->entry->get_entries( array( 'form_id' => $form_id ), true );

		return array(
			'id'    => $form_id,
			'count' => (int) $count,
		);
	}

	public static function wpforms_export_entries_csv( $input = array() ) {
		$check = self::require_wpforms_entries();
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$input   = is_array( $input ) ? $input : array();
		$form_id = isset( $input['id'] ) ? absint( $input['id'] ) : 0;

		if ( $form_id <= 0 ) {
			return new WP_Error( self::ERR_MISSING_FORM_ID, __( 'A WPForms form ID is required.', 'mosmcp-abilities' ) );
		}

		$entries = wpforms()->entry->get_entries( array( 'form_id' => $form_id ) );
		$entries = is_array( $entries ) ? $entries : array();
		$rows    = array_map( array( __CLASS__, 'wpforms_entry_summary' ), $entries );

		return array(
			'csv'       => self::csv_from_rows( $rows ),
			'row_count' => count( $rows ),
		);
	}

	public static function wpforms_mark_entry_read( $input = array() ) {
		return self::wpforms_set_viewed( $input, true );
	}

	public static function wpforms_mark_entry_unread( $input = array() ) {
		return self::wpforms_set_viewed( $input, false );
	}

	private static function wpforms_set_viewed( $input, $viewed ) {
		$check = self::require_wpforms_entries();
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$input = is_array( $input ) ? $input : array();
		$id    = isset( $input['id'] ) ? absint( $input['id'] ) : 0;

		if ( $id <= 0 || ! wpforms()->entry->get( $id ) ) {
			return new WP_Error( self::ERR_ENTRY_NOT_FOUND, __( 'No WPForms entry was found with that ID.', 'mosmcp-abilities' ) );
		}

		wpforms()->entry->update( $id, array( 'viewed' => $viewed ? 1 : 0 ) );

		return array(
			'id'     => $id,
			'viewed' => $viewed,
		);
	}

	public static function wpforms_trash_entry( $input = array() ) {
		$check = self::require_wpforms_entries();
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$input = is_array( $input ) ? $input : array();
		$id    = isset( $input['id'] ) ? absint( $input['id'] ) : 0;

		if ( $id <= 0 || ! wpforms()->entry->get( $id ) ) {
			return new WP_Error( self::ERR_ENTRY_NOT_FOUND, __( 'No WPForms entry was found with that ID.', 'mosmcp-abilities' ) );
		}

		wpforms()->entry->update( $id, array( 'status' => 'trash' ) );

		return array(
			'id'      => $id,
			'trashed' => true,
		);
	}

	public static function wpforms_restore_entry( $input = array() ) {
		$check = self::require_wpforms_entries();
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$input = is_array( $input ) ? $input : array();
		$id    = isset( $input['id'] ) ? absint( $input['id'] ) : 0;

		if ( $id <= 0 || ! wpforms()->entry->get( $id ) ) {
			return new WP_Error( self::ERR_ENTRY_NOT_FOUND, __( 'No WPForms entry was found with that ID.', 'mosmcp-abilities' ) );
		}

		wpforms()->entry->update( $id, array( 'status' => '' ) );

		return array(
			'id'       => $id,
			'restored' => true,
		);
	}

	public static function wpforms_delete_entry_permanently( $input = array() ) {
		$check = self::require_wpforms_entries();
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$input = is_array( $input ) ? $input : array();
		$id    = isset( $input['id'] ) ? absint( $input['id'] ) : 0;

		if ( $id <= 0 || ! wpforms()->entry->get( $id ) ) {
			return new WP_Error( self::ERR_ENTRY_NOT_FOUND, __( 'No WPForms entry was found with that ID.', 'mosmcp-abilities' ) );
		}

		if ( ! wpforms()->entry->delete( $id ) ) {
			return new WP_Error( 'moca_delete_failed', __( 'Failed to delete the entry.', 'mosmcp-abilities' ) );
		}

		return array(
			'id'      => $id,
			'deleted' => true,
		);
	}

	/*
	==================================================================== *
	 * GRAVITY FORMS (14)
	 *
	 * Built against GFAPI, Gravity Forms' officially documented and stable
	 * public API — highest-confidence provider integration in this file.
	 * Gravity Forms is not installed on the site this was built on, so it
	 * is still unverified against real running code.
	 * ==================================================================== */

	private static function register_gravityforms_abilities() {

		Naming::register_ability(
			'mosmcp/gf-list-forms',
			array(
				'label'               => __( 'List Gravity Forms', 'mosmcp-abilities' ),
				'description'         => __( 'Lists all Gravity Forms on the site, including inactive ones (ID, title, is_active). Read-only. To list only active forms, use gf-list-active-forms.', 'mosmcp-abilities' ),
				'category'            => self::CATEGORY_FORMS,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => new \stdClass(),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'forms' ),
					'properties'           => array(
						'forms' => array( 'type' => 'array' ),
					),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( __CLASS__, 'gf_list_forms' ),
				'permission_callback' => array( __CLASS__, 'can_manage_forms' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
						'open_world'  => false,
					),
					'required_cap' => 'manage_options',
					'show_in_rest' => false,
				),
			)
		);

		Naming::register_ability(
			'mosmcp/gf-list-active-forms',
			array(
				'label'               => __( 'List Active Gravity Forms', 'mosmcp-abilities' ),
				'description'         => __( 'Lists only active Gravity Forms (ID, title). Read-only. To include inactive forms too, use gf-list-forms.', 'mosmcp-abilities' ),
				'category'            => self::CATEGORY_FORMS,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => new \stdClass(),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'forms' ),
					'properties'           => array(
						'forms' => array( 'type' => 'array' ),
					),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( __CLASS__, 'gf_list_active_forms' ),
				'permission_callback' => array( __CLASS__, 'can_manage_forms' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
						'open_world'  => false,
					),
					'required_cap' => 'manage_options',
					'show_in_rest' => false,
				),
			)
		);

		Naming::register_ability(
			'mosmcp/gf-get-form-by-id',
			array(
				'label'               => __( 'Get Gravity Form by ID', 'mosmcp-abilities' ),
				'description'         => __( "Retrieves a single Gravity Form's full configuration by form ID.", 'mosmcp-abilities' ),
				'category'            => self::CATEGORY_FORMS,
				'input_schema'        => self::id_input_schema( __( 'The Gravity Forms form ID (required).', 'mosmcp-abilities' ) ),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'           => array( 'type' => 'integer' ),
						'title'        => array( 'type' => 'string' ),
						'description'  => array( 'type' => 'string' ),
						'is_active'    => array( 'type' => 'boolean' ),
						'fields'       => array( 'type' => 'array' ),
						'date_created' => array( 'type' => 'string' ),
					),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( __CLASS__, 'gf_get_form_by_id' ),
				'permission_callback' => array( __CLASS__, 'can_manage_forms' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
						'open_world'  => false,
					),
					'required_cap' => 'manage_options',
					'show_in_rest' => false,
				),
			)
		);

		Naming::register_ability(
			'mosmcp/gf-get-form-fields',
			array(
				'label'               => __( 'Get Gravity Form Fields', 'mosmcp-abilities' ),
				'description'         => __( 'Returns the field structure (ID, label, type, required) of a Gravity Form, given its form ID. Read-only.', 'mosmcp-abilities' ),
				'category'            => self::CATEGORY_FORMS,
				'input_schema'        => self::id_input_schema( __( 'The Gravity Forms form ID (required).', 'mosmcp-abilities' ) ),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'id', 'fields' ),
					'properties'           => array(
						'id'     => array( 'type' => 'integer' ),
						'fields' => array( 'type' => 'array' ),
					),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( __CLASS__, 'gf_get_form_fields' ),
				'permission_callback' => array( __CLASS__, 'can_manage_forms' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
						'open_world'  => false,
					),
					'required_cap' => 'manage_options',
					'show_in_rest' => false,
				),
			)
		);

		Naming::register_ability(
			'mosmcp/gf-get-form-settings',
			array(
				'label'               => __( 'Get Gravity Form Settings', 'mosmcp-abilities' ),
				'description'         => __( 'Returns general settings configured for a Gravity Form (notifications, confirmations, entry limits), given its form ID. Read-only.', 'mosmcp-abilities' ),
				'category'            => self::CATEGORY_FORMS,
				'input_schema'        => self::id_input_schema( __( 'The Gravity Forms form ID (required).', 'mosmcp-abilities' ) ),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'id', 'settings' ),
					'properties'           => array(
						'id'       => array( 'type' => 'integer' ),
						'settings' => array( 'type' => 'object' ),
					),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( __CLASS__, 'gf_get_form_settings' ),
				'permission_callback' => array( __CLASS__, 'can_manage_forms' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
						'open_world'  => false,
					),
					'required_cap' => 'manage_options',
					'show_in_rest' => false,
				),
			)
		);

		$gf_entry_schema = array(
			'type'       => 'object',
			'properties' => array(
				'id'      => array( 'type' => 'integer' ),
				'form_id' => array( 'type' => 'integer' ),
				'date'    => array( 'type' => 'string' ),
				'status'  => array( 'type' => 'string' ),
				'is_read' => array( 'type' => 'boolean' ),
				'fields'  => array( 'type' => 'object' ),
			),
		);

		Naming::register_ability(
			'mosmcp/gf-list-entries',
			array(
				'label'               => __( 'List Gravity Forms Entries', 'mosmcp-abilities' ),
				'description'         => __( 'Lists submitted entries for a Gravity Form, given its form ID, paginated. Read-only.', 'mosmcp-abilities' ),
				'category'            => self::CATEGORY_FORMS,
				'input_schema'        => self::id_input_schema(
					__( 'The Gravity Forms form ID (required).', 'mosmcp-abilities' ),
					array(
						'page'     => array(
							'type'    => 'integer',
							'default' => 1,
						),
						'per_page' => array(
							'type'    => 'integer',
							'default' => 20,
						),
					)
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'entries', 'page', 'per_page' ),
					'properties'           => array(
						'entries'  => array(
							'type'  => 'array',
							'items' => $gf_entry_schema,
						),
						'page'     => array( 'type' => 'integer' ),
						'per_page' => array( 'type' => 'integer' ),
					),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( __CLASS__, 'gf_list_entries' ),
				'permission_callback' => array( __CLASS__, 'can_manage_forms' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
						'open_world'  => false,
					),
					'required_cap' => 'manage_options',
					'show_in_rest' => false,
				),
			)
		);

		Naming::register_ability(
			'mosmcp/gf-get-entry-by-id',
			array(
				'label'               => __( 'Get Gravity Forms Entry by ID', 'mosmcp-abilities' ),
				'description'         => __( 'Retrieves a single Gravity Forms entry by ID. Read-only.', 'mosmcp-abilities' ),
				'category'            => self::CATEGORY_FORMS,
				'input_schema'        => self::id_input_schema( __( 'The Gravity Forms entry ID (required).', 'mosmcp-abilities' ) ),
				'output_schema'       => $gf_entry_schema,
				'execute_callback'    => array( __CLASS__, 'gf_get_entry_by_id' ),
				'permission_callback' => array( __CLASS__, 'can_manage_forms' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
						'open_world'  => false,
					),
					'required_cap' => 'manage_options',
					'show_in_rest' => false,
				),
			)
		);

		Naming::register_ability(
			'mosmcp/gf-count-entries',
			array(
				'label'               => __( 'Count Gravity Forms Entries', 'mosmcp-abilities' ),
				'description'         => __( 'Returns the total entry count for a Gravity Form, given its form ID. Read-only.', 'mosmcp-abilities' ),
				'category'            => self::CATEGORY_FORMS,
				'input_schema'        => self::id_input_schema( __( 'The Gravity Forms form ID (required).', 'mosmcp-abilities' ) ),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'id', 'count' ),
					'properties'           => array(
						'id'    => array( 'type' => 'integer' ),
						'count' => array( 'type' => 'integer' ),
					),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( __CLASS__, 'gf_count_entries' ),
				'permission_callback' => array( __CLASS__, 'can_manage_forms' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
						'open_world'  => false,
					),
					'required_cap' => 'manage_options',
					'show_in_rest' => false,
				),
			)
		);

		Naming::register_ability(
			'mosmcp/gf-export-entries-csv',
			array(
				'label'               => __( 'Export Gravity Forms Entries as CSV', 'mosmcp-abilities' ),
				'description'         => __( "Exports a Gravity Form's entries to CSV text, given its form ID. Returns the CSV content inline.", 'mosmcp-abilities' ),
				'category'            => self::CATEGORY_FORMS,
				'input_schema'        => self::id_input_schema( __( 'The Gravity Forms form ID (required).', 'mosmcp-abilities' ) ),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'csv', 'row_count' ),
					'properties'           => array(
						'csv'       => array( 'type' => 'string' ),
						'row_count' => array( 'type' => 'integer' ),
					),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( __CLASS__, 'gf_export_entries_csv' ),
				'permission_callback' => array( __CLASS__, 'can_manage_forms' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
						'open_world'  => false,
					),
					'required_cap' => 'manage_options',
					'show_in_rest' => false,
				),
			)
		);

		Naming::register_ability(
			'mosmcp/gf-mark-entry-read',
			array(
				'label'               => __( 'Mark Gravity Forms Entry as Read', 'mosmcp-abilities' ),
				'description'         => __( 'Marks a Gravity Forms entry as read, given its entry ID.', 'mosmcp-abilities' ),
				'category'            => self::CATEGORY_FORMS,
				'input_schema'        => self::id_input_schema( __( 'The Gravity Forms entry ID (required).', 'mosmcp-abilities' ) ),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'id', 'is_read' ),
					'properties'           => array(
						'id'      => array( 'type' => 'integer' ),
						'is_read' => array( 'type' => 'boolean' ),
					),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( __CLASS__, 'gf_mark_entry_read' ),
				'permission_callback' => array( __CLASS__, 'can_manage_forms' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
						'open_world'  => false,
					),
					'required_cap' => 'manage_options',
					'show_in_rest' => false,
				),
			)
		);

		Naming::register_ability(
			'mosmcp/gf-mark-entry-unread',
			array(
				'label'               => __( 'Mark Gravity Forms Entry as Unread', 'mosmcp-abilities' ),
				'description'         => __( 'Marks a Gravity Forms entry as unread, given its entry ID.', 'mosmcp-abilities' ),
				'category'            => self::CATEGORY_FORMS,
				'input_schema'        => self::id_input_schema( __( 'The Gravity Forms entry ID (required).', 'mosmcp-abilities' ) ),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'id', 'is_read' ),
					'properties'           => array(
						'id'      => array( 'type' => 'integer' ),
						'is_read' => array( 'type' => 'boolean' ),
					),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( __CLASS__, 'gf_mark_entry_unread' ),
				'permission_callback' => array( __CLASS__, 'can_manage_forms' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
						'open_world'  => false,
					),
					'required_cap' => 'manage_options',
					'show_in_rest' => false,
				),
			)
		);

		Naming::register_ability(
			'mosmcp/gf-trash-entry',
			array(
				'label'               => __( 'Trash Gravity Forms Entry', 'mosmcp-abilities' ),
				'description'         => __( 'Moves a Gravity Forms entry to trash (recoverable), given its entry ID. To undo, use gf-restore-entry-from-trash.', 'mosmcp-abilities' ),
				'category'            => self::CATEGORY_FORMS,
				'input_schema'        => self::id_input_schema( __( 'The Gravity Forms entry ID (required).', 'mosmcp-abilities' ) ),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'id', 'trashed' ),
					'properties'           => array(
						'id'      => array( 'type' => 'integer' ),
						'trashed' => array( 'type' => 'boolean' ),
					),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( __CLASS__, 'gf_trash_entry' ),
				'permission_callback' => array( __CLASS__, 'can_manage_forms' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => true,
						'open_world'  => false,
					),
					'required_cap' => 'manage_options',
					'show_in_rest' => false,
				),
			)
		);

		Naming::register_ability(
			'mosmcp/gf-restore-entry-from-trash',
			array(
				'label'               => __( 'Restore Gravity Forms Entry from Trash', 'mosmcp-abilities' ),
				'description'         => __( 'Restores a Gravity Forms entry from trash, given its entry ID.', 'mosmcp-abilities' ),
				'category'            => self::CATEGORY_FORMS,
				'input_schema'        => self::id_input_schema( __( 'The Gravity Forms entry ID (required).', 'mosmcp-abilities' ) ),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'id', 'restored' ),
					'properties'           => array(
						'id'       => array( 'type' => 'integer' ),
						'restored' => array( 'type' => 'boolean' ),
					),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( __CLASS__, 'gf_restore_entry_from_trash' ),
				'permission_callback' => array( __CLASS__, 'can_manage_forms' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
						'open_world'  => false,
					),
					'required_cap' => 'manage_options',
					'show_in_rest' => false,
				),
			)
		);

		Naming::register_ability(
			'mosmcp/gf-permanently-delete-entry',
			array(
				'label'               => __( 'Permanently Delete Gravity Forms Entry', 'mosmcp-abilities' ),
				'description'         => __( 'Irreversibly deletes a Gravity Forms entry, given its entry ID, bypassing trash. Destructive — used for removing sensitive lead data entirely.', 'mosmcp-abilities' ),
				'category'            => self::CATEGORY_FORMS,
				'input_schema'        => self::id_input_schema( __( 'The Gravity Forms entry ID (required).', 'mosmcp-abilities' ) ),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'id', 'deleted' ),
					'properties'           => array(
						'id'      => array( 'type' => 'integer' ),
						'deleted' => array( 'type' => 'boolean' ),
					),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( __CLASS__, 'gf_permanently_delete_entry' ),
				'permission_callback' => array( __CLASS__, 'can_manage_forms' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => false,
						'open_world'  => false,
					),
					'required_cap' => 'manage_options',
					'show_in_rest' => false,
				),
			)
		);
	}

	public static function gf_list_forms() {
		$check = self::require_gravityforms();
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$forms = GFAPI::get_forms( null );

		return array(
			'forms' => array_map(
				static function ( $form ) {
					return array(
						'id'        => (int) $form['id'],
						'title'     => (string) $form['title'],
						'is_active' => (bool) ( '1' === (string) ( $form['is_active'] ?? '1' ) ),
					);
				},
				$forms
			),
		);
	}

	public static function gf_list_active_forms() {
		$check = self::require_gravityforms();
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$forms = GFAPI::get_forms( true );

		return array(
			'forms' => array_map(
				static function ( $form ) {
					return array(
						'id'    => (int) $form['id'],
						'title' => (string) $form['title'],
					);
				},
				$forms
			),
		);
	}

	public static function gf_get_form_by_id( $input = array() ) {
		$check = self::require_gravityforms();
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$input = is_array( $input ) ? $input : array();
		$id    = isset( $input['id'] ) ? absint( $input['id'] ) : 0;

		$form = $id > 0 ? GFAPI::get_form( $id ) : false;
		if ( ! $form ) {
			return new WP_Error( self::ERR_FORM_NOT_FOUND, __( 'No Gravity Forms form was found with that ID.', 'mosmcp-abilities' ) );
		}

		return array(
			'id'           => (int) $form['id'],
			'title'        => (string) $form['title'],
			'description'  => (string) ( $form['description'] ?? '' ),
			'is_active'    => (bool) ( '1' === (string) ( $form['is_active'] ?? '1' ) ),
			'fields'       => self::gf_fields_summary( $form ),
			'date_created' => (string) ( $form['date_created'] ?? '' ),
		);
	}

	/**
	 * @param array<string, mixed> $form GFAPI form array.
	 * @return array<int, array<string, mixed>>
	 */
	private static function gf_fields_summary( array $form ) {
		$fields = isset( $form['fields'] ) && is_array( $form['fields'] ) ? $form['fields'] : array();

		return array_map(
			static function ( $field ) {
				$field = (array) $field;
				return array(
					'id'       => isset( $field['id'] ) ? (int) $field['id'] : 0,
					'label'    => isset( $field['label'] ) ? (string) $field['label'] : '',
					'type'     => isset( $field['type'] ) ? (string) $field['type'] : '',
					'required' => ! empty( $field['isRequired'] ),
				);
			},
			$fields
		);
	}

	public static function gf_get_form_fields( $input = array() ) {
		$check = self::require_gravityforms();
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$input = is_array( $input ) ? $input : array();
		$id    = isset( $input['id'] ) ? absint( $input['id'] ) : 0;

		$form = $id > 0 ? GFAPI::get_form( $id ) : false;
		if ( ! $form ) {
			return new WP_Error( self::ERR_FORM_NOT_FOUND, __( 'No Gravity Forms form was found with that ID.', 'mosmcp-abilities' ) );
		}

		return array(
			'id'     => $id,
			'fields' => self::gf_fields_summary( $form ),
		);
	}

	public static function gf_get_form_settings( $input = array() ) {
		$check = self::require_gravityforms();
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$input = is_array( $input ) ? $input : array();
		$id    = isset( $input['id'] ) ? absint( $input['id'] ) : 0;

		$form = $id > 0 ? GFAPI::get_form( $id ) : false;
		if ( ! $form ) {
			return new WP_Error( self::ERR_FORM_NOT_FOUND, __( 'No Gravity Forms form was found with that ID.', 'mosmcp-abilities' ) );
		}

		return array(
			'id'       => $id,
			'settings' => (object) array(
				'notifications' => $form['notifications'] ?? array(),
				'confirmations' => $form['confirmations'] ?? array(),
				'limitEntries'  => $form['limitEntries'] ?? false,
				'is_active'     => (bool) ( '1' === (string) ( $form['is_active'] ?? '1' ) ),
			),
		);
	}

	/**
	 * @param array<string, mixed> $entry GFAPI entry array.
	 * @return array<string, mixed>
	 */
	private static function gf_entry_summary( array $entry ) {
		$fields = array();
		foreach ( $entry as $key => $value ) {
			if ( is_numeric( $key ) ) {
				$fields[ $key ] = $value;
			}
		}

		return array(
			'id'      => (int) ( $entry['id'] ?? 0 ),
			'form_id' => (int) ( $entry['form_id'] ?? 0 ),
			'date'    => (string) ( $entry['date_created'] ?? '' ),
			'status'  => (string) ( $entry['status'] ?? '' ),
			'is_read' => (bool) ( '1' === (string) ( $entry['is_read'] ?? '0' ) ),
			'fields'  => (object) $fields,
		);
	}

	public static function gf_list_entries( $input = array() ) {
		$check = self::require_gravityforms();
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$input   = is_array( $input ) ? $input : array();
		$form_id = isset( $input['id'] ) ? absint( $input['id'] ) : 0;

		if ( $form_id <= 0 ) {
			return new WP_Error( self::ERR_MISSING_FORM_ID, __( 'A Gravity Forms form ID is required.', 'mosmcp-abilities' ) );
		}

		$paginate = self::paginate_args( $input );

		$entries = GFAPI::get_entries(
			$form_id,
			array(),
			array(),
			array(
				'offset'    => $paginate['offset'],
				'page_size' => $paginate['per_page'],
			)
		);

		return array(
			'entries'  => array_map( array( __CLASS__, 'gf_entry_summary' ), $entries ),
			'page'     => $paginate['page'],
			'per_page' => $paginate['per_page'],
		);
	}

	public static function gf_get_entry_by_id( $input = array() ) {
		$check = self::require_gravityforms();
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$input = is_array( $input ) ? $input : array();
		$id    = isset( $input['id'] ) ? absint( $input['id'] ) : 0;

		$entry = $id > 0 ? GFAPI::get_entry( $id ) : new WP_Error();
		if ( is_wp_error( $entry ) ) {
			return new WP_Error( self::ERR_ENTRY_NOT_FOUND, __( 'No Gravity Forms entry was found with that ID.', 'mosmcp-abilities' ) );
		}

		return self::gf_entry_summary( $entry );
	}

	public static function gf_count_entries( $input = array() ) {
		$check = self::require_gravityforms();
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$input   = is_array( $input ) ? $input : array();
		$form_id = isset( $input['id'] ) ? absint( $input['id'] ) : 0;

		if ( $form_id <= 0 ) {
			return new WP_Error( self::ERR_MISSING_FORM_ID, __( 'A Gravity Forms form ID is required.', 'mosmcp-abilities' ) );
		}

		$count = GFAPI::count_entries( $form_id );

		return array(
			'id'    => $form_id,
			'count' => (int) $count,
		);
	}

	public static function gf_export_entries_csv( $input = array() ) {
		$check = self::require_gravityforms();
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$input   = is_array( $input ) ? $input : array();
		$form_id = isset( $input['id'] ) ? absint( $input['id'] ) : 0;

		if ( $form_id <= 0 ) {
			return new WP_Error( self::ERR_MISSING_FORM_ID, __( 'A Gravity Forms form ID is required.', 'mosmcp-abilities' ) );
		}

		$entries = GFAPI::get_entries( $form_id );
		$rows    = array_map( array( __CLASS__, 'gf_entry_summary' ), $entries );

		return array(
			'csv'       => self::csv_from_rows( $rows ),
			'row_count' => count( $rows ),
		);
	}

	private static function gf_require_entry( $input ) {
		$check = self::require_gravityforms();
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$id    = isset( $input['id'] ) ? absint( $input['id'] ) : 0;
		$entry = $id > 0 ? GFAPI::get_entry( $id ) : new WP_Error();
		if ( is_wp_error( $entry ) ) {
			return new WP_Error( self::ERR_ENTRY_NOT_FOUND, __( 'No Gravity Forms entry was found with that ID.', 'mosmcp-abilities' ) );
		}
		return $id;
	}

	public static function gf_mark_entry_read( $input = array() ) {
		return self::gf_set_read( $input, true );
	}

	public static function gf_mark_entry_unread( $input = array() ) {
		return self::gf_set_read( $input, false );
	}

	private static function gf_set_read( $input, $read ) {
		$input = is_array( $input ) ? $input : array();
		$id    = self::gf_require_entry( $input );
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		$result = GFAPI::update_entry_property( $id, 'is_read', $read ? 1 : 0 );
		if ( is_wp_error( $result ) || false === $result ) {
			return new WP_Error( 'moca_update_failed', __( 'Failed to update the entry.', 'mosmcp-abilities' ) );
		}

		return array(
			'id'      => $id,
			'is_read' => $read,
		);
	}

	public static function gf_trash_entry( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$id    = self::gf_require_entry( $input );
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		$result = GFAPI::update_entry_property( $id, 'status', 'trash' );
		if ( is_wp_error( $result ) || false === $result ) {
			return new WP_Error( 'moca_trash_failed', __( 'Failed to trash the entry.', 'mosmcp-abilities' ) );
		}

		return array(
			'id'      => $id,
			'trashed' => true,
		);
	}

	public static function gf_restore_entry_from_trash( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$id    = self::gf_require_entry( $input );
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		$result = GFAPI::update_entry_property( $id, 'status', 'active' );
		if ( is_wp_error( $result ) || false === $result ) {
			return new WP_Error( 'moca_restore_failed', __( 'Failed to restore the entry.', 'mosmcp-abilities' ) );
		}

		return array(
			'id'       => $id,
			'restored' => true,
		);
	}

	public static function gf_permanently_delete_entry( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$id    = self::gf_require_entry( $input );
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		$result = GFAPI::delete_entry( $id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'id'      => $id,
			'deleted' => true,
		);
	}
}
