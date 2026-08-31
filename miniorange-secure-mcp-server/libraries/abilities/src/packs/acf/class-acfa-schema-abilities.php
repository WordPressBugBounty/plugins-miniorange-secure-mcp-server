<?php
/**
 * Structure / schema management ACF abilities (19-22).
 *
 * Abilities 19-21 change site-wide field configuration rather than content and
 * are gated behind ACF's admin capability (manage_options by default). They are
 * flagged with a destructive hint so agent hosts can require explicit
 * confirmation before executing them.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Acf;

use WP_Error;

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
 * Class ACFA_Schema_Abilities
 */
class ACFA_Schema_Abilities {

	/**
	 * Meta for structural abilities: REST-exposed but flagged destructive.
	 *
	 * @return array
	 */
	private static function structural_meta() {
		return array(
			'show_in_rest' => true,
			'mcp'          => array( 'public' => true ),
			'annotations'  => array(
				'destructiveHint' => true,
				'structural'      => true,
			),
		);
	}

	/**
	 * Permission callback for structural changes.
	 *
	 * @return callable
	 */
	private static function structural_permission() {
		return function () {
			return current_user_can( ACFA_Helpers::structural_cap() );
		};
	}

	/**
	 * Register abilities 19-22.
	 */
	public static function register_all() {

		ACFA_Helpers::register(
			'create-field-group',
			array(
				'label'               => __( 'Create ACF Field Group', 'mosmcp-abilities' ),
				'description'         => __( 'Programmatically registers a new ACF field group with initial fields and location rules. Structural change: alters site-wide content modeling.', 'mosmcp-abilities' ),
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'title'          => array(
							'type'        => 'string',
							'description' => __( 'Display title for the new field group.', 'mosmcp-abilities' ),
						),
						'fields'         => array(
							'type'        => 'array',
							'description' => __( 'Initial field definitions. Each item needs at least name, label, and type (e.g. text, number, select).', 'mosmcp-abilities' ),
							'items'       => array( 'type' => 'object' ),
						),
						'location_rules' => array(
							'type'        => 'array',
							'description' => __( 'ACF location rule groups (array of arrays of {param, operator, value}), e.g. [[{"param":"post_type","operator":"==","value":"page"}]].', 'mosmcp-abilities' ),
							'items'       => array( 'type' => 'array' ),
						),
						'menu_order'     => array(
							'type'        => 'integer',
							'description' => __( 'Sort order relative to other field groups.', 'mosmcp-abilities' ),
						),
					),
					'required'             => array( 'title', 'fields', 'location_rules' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'   => array( 'type' => 'boolean' ),
						'group_key' => array( 'type' => 'string' ),
						'group'     => array( 'type' => 'object' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_create_field_group' ),
				'permission_callback' => self::structural_permission(),
				'meta'                => self::structural_meta(),
			)
		);

		ACFA_Helpers::register(
			'add-field-to-group',
			array(
				'label'               => __( 'Add Field to ACF Field Group', 'mosmcp-abilities' ),
				'description'         => __( 'Adds a new field definition to an existing field group. Structural change: only works on database-stored groups, not groups registered in code or local JSON.', 'mosmcp-abilities' ),
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'group_key'        => array(
							'type'        => 'string',
							'description' => __( 'Target field group key.', 'mosmcp-abilities' ),
						),
						'field_definition' => array(
							'type'        => 'object',
							'description' => __( 'New field definition: name, label, type, and type-specific settings.', 'mosmcp-abilities' ),
						),
						'position'         => array(
							'type'        => 'integer',
							'description' => __( 'Index at which to insert the field. Default: end of group.', 'mosmcp-abilities' ),
						),
					),
					'required'             => array( 'group_key', 'field_definition' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'   => array( 'type' => 'boolean' ),
						'field_key' => array( 'type' => 'string' ),
						'field'     => array( 'type' => 'object' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_add_field_to_group' ),
				'permission_callback' => self::structural_permission(),
				'meta'                => self::structural_meta(),
			)
		);

		ACFA_Helpers::register(
			'update-field-location-rules',
			array(
				'label'               => __( 'Update ACF Field Group Location Rules', 'mosmcp-abilities' ),
				'description'         => __( 'Modifies which post types, templates, or taxonomies a field group applies to. Structural change: alters where content editors see fields.', 'mosmcp-abilities' ),
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'group_key'      => array(
							'type'        => 'string',
							'description' => __( 'Target field group key.', 'mosmcp-abilities' ),
						),
						'location_rules' => array(
							'type'        => 'array',
							'description' => __( 'New set of ACF location rule groups (array of arrays of {param, operator, value}).', 'mosmcp-abilities' ),
							'items'       => array( 'type' => 'array' ),
						),
					),
					'required'             => array( 'group_key', 'location_rules' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success' => array( 'type' => 'boolean' ),
						'group'   => array( 'type' => 'object' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_update_field_location_rules' ),
				'permission_callback' => self::structural_permission(),
				'meta'                => self::structural_meta(),
			)
		);

		ACFA_Helpers::register(
			'validate-field-value',
			array(
				'label'               => __( 'Validate ACF Field Value', 'mosmcp-abilities' ),
				'description'         => __( 'Runs ACF\'s built-in validation logic against a proposed value before committing it, without saving. Recommended pre-flight before update-field-value and bulk-update-fields.', 'mosmcp-abilities' ),
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'field_key' => array(
							'type'        => 'string',
							'description' => __( 'Field name or key to validate against.', 'mosmcp-abilities' ),
						),
						'value'     => ACFA_Helpers::mixed_value_schema( __( 'Proposed value to check.', 'mosmcp-abilities' ) ),
						'post_id'   => array(
							'type'        => 'integer',
							'description' => __( 'Optional post context, needed for conditional-logic-aware validation.', 'mosmcp-abilities' ),
						),
					),
					'required'             => array( 'field_key', 'value' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'valid'  => array( 'type' => 'boolean' ),
						'errors' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_validate_field_value' ),
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);
	}

	/**
	 * 19. Create a new field group with initial fields.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function execute_create_field_group( $input ) {
		if ( ! current_user_can( ACFA_Helpers::structural_cap() ) ) {
			return new WP_Error( 'acfa_forbidden', __( 'You do not have permission to modify field group structure.', 'mosmcp-abilities' ) );
		}

		$title = sanitize_text_field( $input['title'] );
		if ( '' === $title ) {
			return new WP_Error( 'acfa_missing_title', __( 'A non-empty title is required.', 'mosmcp-abilities' ) );
		}
		if ( empty( $input['fields'] ) || ! is_array( $input['fields'] ) ) {
			return new WP_Error( 'acfa_missing_fields', __( 'fields must be a non-empty array of field definitions.', 'mosmcp-abilities' ) );
		}
		if ( empty( $input['location_rules'] ) || ! is_array( $input['location_rules'] ) ) {
			return new WP_Error( 'acfa_missing_location', __( 'location_rules must be a non-empty array of rule groups.', 'mosmcp-abilities' ) );
		}

		foreach ( $input['fields'] as $index => $field ) {
			if ( ! is_array( $field ) || empty( $field['name'] ) || empty( $field['type'] ) ) {
				return new WP_Error(
					'acfa_invalid_field',
					sprintf(
						/* translators: %d: index in the fields array. */
						__( 'Field definition at index %d must include at least "name" and "type".', 'mosmcp-abilities' ),
						$index
					)
				);
			}
		}

		$group = array(
			'key'      => uniqid( 'group_' ),
			'title'    => $title,
			'fields'   => ACFA_Helpers::generate_field_keys( $input['fields'] ),
			'location' => $input['location_rules'],
			'active'   => true,
		);
		if ( isset( $input['menu_order'] ) ) {
			$group['menu_order'] = (int) $input['menu_order'];
		}

		$saved = acf_import_field_group( $group );
		if ( ! $saved || empty( $saved['ID'] ) ) {
			return new WP_Error( 'acfa_create_failed', __( 'ACF failed to create the field group.', 'mosmcp-abilities' ) );
		}

		$saved['fields'] = (array) acf_get_fields( $saved );
		return array(
			'success'   => true,
			'group_key' => $saved['key'],
			'group'     => $saved,
		);
	}

	/**
	 * 20. Add a field to an existing (database-stored) field group.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function execute_add_field_to_group( $input ) {
		if ( ! current_user_can( ACFA_Helpers::structural_cap() ) ) {
			return new WP_Error( 'acfa_forbidden', __( 'You do not have permission to modify field group structure.', 'mosmcp-abilities' ) );
		}

		$group = acf_get_field_group( (string) $input['group_key'] );
		if ( ! $group ) {
			return new WP_Error( 'acfa_group_not_found', __( 'No field group matches the given key.', 'mosmcp-abilities' ) );
		}
		if ( empty( $group['ID'] ) ) {
			return new WP_Error( 'acfa_local_group', __( 'This field group is registered in code or local JSON and cannot be modified through the database.', 'mosmcp-abilities' ) );
		}

		$definition = $input['field_definition'];
		if ( ! is_array( $definition ) || empty( $definition['name'] ) || empty( $definition['type'] ) ) {
			return new WP_Error( 'acfa_invalid_field', __( 'field_definition must include at least "name" and "type".', 'mosmcp-abilities' ) );
		}

		$existing    = (array) acf_get_fields( $group );
		$field_count = count( $existing );
		$position    = isset( $input['position'] ) ? max( 0, min( (int) $input['position'], $field_count ) ) : $field_count;

		$definition = ACFA_Helpers::generate_field_keys( array( $definition ) );
		$definition = $definition[0];

		$definition['parent']     = $group['ID'];
		$definition['menu_order'] = $position;
		if ( empty( $definition['label'] ) ) {
			$definition['label'] = ucwords( str_replace( array( '_', '-' ), ' ', $definition['name'] ) );
		}

		$saved = acf_update_field( $definition );
		if ( ! $saved ) {
			return new WP_Error( 'acfa_add_field_failed', __( 'ACF failed to add the field to the group.', 'mosmcp-abilities' ) );
		}

		// Shift menu_order of existing fields at/after the insert position.
		if ( $position < $field_count ) {
			foreach ( $existing as $index => $sibling ) {
				if ( $index >= $position ) {
					$sibling['menu_order'] = $index + 1;
					acf_update_field( $sibling );
				}
			}
		}

		return array(
			'success'   => true,
			'field_key' => $saved['key'],
			'field'     => ACFA_Helpers::field_definition( $saved ),
		);
	}

	/**
	 * 21. Replace a field group's location rules.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function execute_update_field_location_rules( $input ) {
		if ( ! current_user_can( ACFA_Helpers::structural_cap() ) ) {
			return new WP_Error( 'acfa_forbidden', __( 'You do not have permission to modify field group structure.', 'mosmcp-abilities' ) );
		}

		$group = acf_get_field_group( (string) $input['group_key'] );
		if ( ! $group ) {
			return new WP_Error( 'acfa_group_not_found', __( 'No field group matches the given key.', 'mosmcp-abilities' ) );
		}
		if ( empty( $group['ID'] ) ) {
			return new WP_Error( 'acfa_local_group', __( 'This field group is registered in code or local JSON and cannot be modified through the database.', 'mosmcp-abilities' ) );
		}
		if ( empty( $input['location_rules'] ) || ! is_array( $input['location_rules'] ) ) {
			return new WP_Error( 'acfa_missing_location', __( 'location_rules must be a non-empty array of rule groups.', 'mosmcp-abilities' ) );
		}

		$group['location'] = $input['location_rules'];
		$saved             = acf_update_field_group( $group );
		if ( ! $saved ) {
			return new WP_Error( 'acfa_update_failed', __( 'ACF failed to update the field group location rules.', 'mosmcp-abilities' ) );
		}

		return array(
			'success' => true,
			'group'   => ACFA_Helpers::group_summary( $saved ),
		);
	}

	/**
	 * 22. Validate a proposed value without saving.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function execute_validate_field_value( $input ) {
		$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : null;
		$field   = ACFA_Helpers::field_object( $input['field_key'], $post_id );
		if ( ! $field ) {
			return new WP_Error( 'acfa_field_not_found', __( 'No ACF field matches the given field key/name.', 'mosmcp-abilities' ) );
		}
		return ACFA_Helpers::validate_value( $field, $input['value'] );
	}
}
