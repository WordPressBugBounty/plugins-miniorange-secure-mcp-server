<?php
/**
 * Write / update ACF abilities (11-18).
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
 * Class ACFA_Write_Abilities
 */
class ACFA_Write_Abilities {

	/**
	 * Register abilities 11-18.
	 */
	public static function register_all() {

		ACFA_Helpers::register(
			'update-field-value',
			array(
				'label'               => __( 'Update ACF Field Value', 'mosmcp-abilities' ),
				'description'         => __( 'Updates a single field value, dispatching correctly based on the field type (plain text vs array vs object payload). Optionally runs ACF validation first.', 'mosmcp-abilities' ),
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'field_key' => array(
							'type'        => 'string',
							'description' => __( 'Field name or field key to update.', 'mosmcp-abilities' ),
						),
						'post_id'   => array(
							'type'        => 'integer',
							'description' => __( 'Post/page/CPT entry to update.', 'mosmcp-abilities' ),
						),
						'value'     => ACFA_Helpers::mixed_value_schema( __( 'New value; shape depends on field type (string, number, array of IDs, etc.).', 'mosmcp-abilities' ) ),
						'validate'  => array(
							'type'        => 'boolean',
							'description' => __( 'Run ACF validation before saving.', 'mosmcp-abilities' ),
							'default'     => true,
						),
					),
					'required'             => array( 'field_key', 'post_id', 'value' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'     => array( 'type' => 'boolean' ),
						'saved_value' => array( 'type' => ACFA_Helpers::mixed_type() ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_update_field_value' ),
				'permission_callback' => ACFA_Helpers::post_permission(),
			)
		);

		ACFA_Helpers::register(
			'update-repeater-row',
			array(
				'label'               => __( 'Update ACF Repeater Row', 'mosmcp-abilities' ),
				'description'         => __( 'Updates or removes a specific row within a repeater field without rewriting the entire repeater. Row indexes are 0-based.', 'mosmcp-abilities' ),
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'field_key'  => array(
							'type'        => 'string',
							'description' => __( 'Repeater field name or key.', 'mosmcp-abilities' ),
						),
						'post_id'    => array(
							'type'        => 'integer',
							'description' => __( 'Post/page/CPT entry the repeater belongs to.', 'mosmcp-abilities' ),
						),
						'row_index'  => array(
							'type'        => 'integer',
							'description' => __( '0-based index of the row to update or remove.', 'mosmcp-abilities' ),
						),
						'action'     => array(
							'type'        => 'string',
							'enum'        => array( 'update', 'remove' ),
							'description' => __( 'Whether to update the row with new values or remove it.', 'mosmcp-abilities' ),
						),
						'row_values' => array(
							'type'        => 'object',
							'description' => __( 'Sub-field name/value pairs for the row. Required when action=update.', 'mosmcp-abilities' ),
						),
					),
					'required'             => array( 'field_key', 'post_id', 'row_index', 'action' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success' => array( 'type' => 'boolean' ),
						'rows'    => array( 'type' => array( 'array', 'null' ) ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_update_repeater_row' ),
				'permission_callback' => ACFA_Helpers::post_permission(),
			)
		);

		ACFA_Helpers::register(
			'append-repeater-row',
			array(
				'label'               => __( 'Append ACF Repeater Row', 'mosmcp-abilities' ),
				'description'         => __( 'Appends a new row to the end of a repeater field without touching existing rows.', 'mosmcp-abilities' ),
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'field_key'  => array(
							'type'        => 'string',
							'description' => __( 'Repeater field name or key.', 'mosmcp-abilities' ),
						),
						'post_id'    => array(
							'type'        => 'integer',
							'description' => __( 'Post/page/CPT entry the repeater belongs to.', 'mosmcp-abilities' ),
						),
						'row_values' => array(
							'type'        => 'object',
							'description' => __( 'Sub-field name/value pairs for the new row.', 'mosmcp-abilities' ),
						),
					),
					'required'             => array( 'field_key', 'post_id', 'row_values' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'   => array( 'type' => 'boolean' ),
						'row_index' => array( 'type' => 'integer' ),
						'rows'      => array( 'type' => array( 'array', 'null' ) ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_append_repeater_row' ),
				'permission_callback' => ACFA_Helpers::post_permission(),
			)
		);

		ACFA_Helpers::register(
			'update-relationship-field',
			array(
				'label'               => __( 'Update ACF Relationship Field', 'mosmcp-abilities' ),
				'description'         => __( 'Adds, removes, or replaces post/user IDs on a relationship, post-object, or user field.', 'mosmcp-abilities' ),
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'field_key'  => array(
							'type'        => 'string',
							'description' => __( 'Relationship / post_object / user field name or key.', 'mosmcp-abilities' ),
						),
						'post_id'    => array(
							'type'        => 'integer',
							'description' => __( 'Post/page/CPT entry the field belongs to.', 'mosmcp-abilities' ),
						),
						'action'     => array(
							'type'        => 'string',
							'enum'        => array( 'add', 'remove', 'replace' ),
							'description' => __( 'Whether to add the IDs, remove them, or use them as the full replacement set.', 'mosmcp-abilities' ),
						),
						'target_ids' => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
							'description' => __( 'IDs of posts/users to add, remove, or use as the full replacement set.', 'mosmcp-abilities' ),
						),
					),
					'required'             => array( 'field_key', 'post_id', 'action', 'target_ids' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'     => array( 'type' => 'boolean' ),
						'related_ids' => array( 'type' => 'array' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_update_relationship_field' ),
				'permission_callback' => ACFA_Helpers::post_permission(),
			)
		);

		ACFA_Helpers::register(
			'update-flexible-content-layout',
			array(
				'label'               => __( 'Update ACF Flexible Content Layout', 'mosmcp-abilities' ),
				'description'         => __( 'Adds a new layout block to a flexible content field, or reorders existing layout blocks.', 'mosmcp-abilities' ),
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'field_key'     => array(
							'type'        => 'string',
							'description' => __( 'Flexible content field name or key.', 'mosmcp-abilities' ),
						),
						'post_id'       => array(
							'type'        => 'integer',
							'description' => __( 'Post/page/CPT entry the field belongs to.', 'mosmcp-abilities' ),
						),
						'action'        => array(
							'type'        => 'string',
							'enum'        => array( 'add_layout', 'reorder' ),
							'description' => __( 'Operation to perform.', 'mosmcp-abilities' ),
						),
						'layout_name'   => array(
							'type'        => 'string',
							'description' => __( 'Name of the layout to insert, as defined in the field group. Required when action=add_layout.', 'mosmcp-abilities' ),
						),
						'layout_values' => array(
							'type'        => 'object',
							'description' => __( 'Sub-field values for the new layout block. Required when action=add_layout.', 'mosmcp-abilities' ),
						),
						'new_order'     => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
							'description' => __( 'Array of current layout indices (0-based) in the desired new order. Required when action=reorder.', 'mosmcp-abilities' ),
						),
						'position'      => array(
							'type'        => 'integer',
							'description' => __( 'Index at which to insert the new layout block. Default: end of list.', 'mosmcp-abilities' ),
						),
					),
					'required'             => array( 'field_key', 'post_id', 'action' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'      => array( 'type' => 'boolean' ),
						'layout_count' => array( 'type' => 'integer' ),
						'layouts'      => array( 'type' => array( 'array', 'null' ) ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_update_flexible_content_layout' ),
				'permission_callback' => ACFA_Helpers::post_permission(),
			)
		);

		ACFA_Helpers::register(
			'bulk-update-fields',
			array(
				'label'               => __( 'Bulk Update ACF Fields', 'mosmcp-abilities' ),
				'description'         => __( 'Updates multiple field values on a single post in one call. With atomic=true, no field is written unless every field passes validation.', 'mosmcp-abilities' ),
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id' => array(
							'type'        => 'integer',
							'description' => __( 'Post/page/CPT entry to update.', 'mosmcp-abilities' ),
						),
						'fields'  => array(
							'type'        => 'array',
							'description' => __( 'List of field/value pairs to apply.', 'mosmcp-abilities' ),
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'field_key' => array( 'type' => 'string' ),
									'value'     => ACFA_Helpers::mixed_value_schema( __( 'New value for the field.', 'mosmcp-abilities' ) ),
								),
								'required'   => array( 'field_key', 'value' ),
							),
						),
						'atomic'  => array(
							'type'        => 'boolean',
							'description' => __( 'If true, write nothing when any single field fails validation.', 'mosmcp-abilities' ),
							'default'     => true,
						),
					),
					'required'             => array( 'post_id', 'fields' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success' => array( 'type' => 'boolean' ),
						'results' => array( 'type' => 'array' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_bulk_update_fields' ),
				'permission_callback' => ACFA_Helpers::post_permission(),
			)
		);

		ACFA_Helpers::register(
			'update-options-page-field',
			array(
				'label'               => __( 'Update ACF Options Page Field', 'mosmcp-abilities' ),
				'description'         => __( 'Writes a value to a field on an ACF Options Page (site-wide setting not tied to a post). Requires site management capability.', 'mosmcp-abilities' ),
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'options_page' => array(
							'type'        => 'string',
							'description' => __( 'Options page slug.', 'mosmcp-abilities' ),
						),
						'field_key'    => array(
							'type'        => 'string',
							'description' => __( 'Field name or key on the options page.', 'mosmcp-abilities' ),
						),
						'value'        => ACFA_Helpers::mixed_value_schema( __( 'New value to save.', 'mosmcp-abilities' ) ),
					),
					'required'             => array( 'options_page', 'field_key', 'value' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'     => array( 'type' => 'boolean' ),
						'saved_value' => array( 'type' => ACFA_Helpers::mixed_type() ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_update_options_page_field' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);

		ACFA_Helpers::register(
			'clear-field-value',
			array(
				'label'               => __( 'Clear ACF Field Value', 'mosmcp-abilities' ),
				'description'         => __( 'Explicitly clears a field to empty, or resets it to the field\'s configured default value.', 'mosmcp-abilities' ),
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'field_key' => array(
							'type'        => 'string',
							'description' => __( 'Field name or key to clear.', 'mosmcp-abilities' ),
						),
						'post_id'   => array(
							'type'        => 'integer',
							'description' => __( 'Post/page/CPT entry the field belongs to.', 'mosmcp-abilities' ),
						),
						'reset_to'  => array(
							'type'        => 'string',
							'enum'        => array( 'empty', 'field_default' ),
							'description' => __( 'Whether to clear to empty or reset to the field default.', 'mosmcp-abilities' ),
							'default'     => 'empty',
						),
					),
					'required'             => array( 'field_key', 'post_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'         => array( 'type' => 'boolean' ),
						'resulting_value' => array( 'type' => ACFA_Helpers::mixed_type() ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_clear_field_value' ),
				'permission_callback' => ACFA_Helpers::post_permission(),
			)
		);
	}

	/**
	 * Resolve field + post and confirm edit permission for a write.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error [ $field, $post_id ] on success.
	 */
	private static function prepare_write( $input ) {
		$post_id = ACFA_Helpers::require_post( $input );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}
		$field = ACFA_Helpers::field_object( $input['field_key'], $post_id );
		if ( ! $field ) {
			return new WP_Error( 'acfa_field_not_found', __( 'No ACF field matches the given field key/name for this post.', 'mosmcp-abilities' ) );
		}
		return array( $field, $post_id );
	}

	/**
	 * 11. Update a single field value.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function execute_update_field_value( $input ) {
		$prepared = self::prepare_write( $input );
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}
		list( $field, $post_id ) = $prepared;
		$value                   = $input['value'];

		$validate = ! isset( $input['validate'] ) || filter_var( $input['validate'], FILTER_VALIDATE_BOOLEAN );
		if ( $validate ) {
			$validation = ACFA_Helpers::validate_value( $field, $value );
			if ( ! $validation['valid'] ) {
				return new WP_Error( 'acfa_validation_failed', implode( ' ', $validation['errors'] ), array( 'errors' => $validation['errors'] ) );
			}
		}

		$success = update_field( $field['key'], $value, $post_id );
		return array(
			'success'     => (bool) $success,
			'saved_value' => get_field( $field['key'], $post_id ),
		);
	}

	/**
	 * 12. Update or remove a specific repeater row.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function execute_update_repeater_row( $input ) {
		$prepared = self::prepare_write( $input );
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}
		list( $field, $post_id ) = $prepared;

		if ( ! in_array( $field['type'], array( 'repeater', 'flexible_content' ), true ) ) {
			return new WP_Error( 'acfa_not_repeater', __( 'The given field is not a repeater or flexible content field.', 'mosmcp-abilities' ) );
		}

		$rows      = get_field( $field['key'], $post_id, false );
		$row_count = is_array( $rows ) ? count( $rows ) : 0;
		$index     = (int) $input['row_index'];
		if ( $index < 0 || $index >= $row_count ) {
			return new WP_Error(
				'acfa_row_out_of_range',
				sprintf(
					/* translators: 1: requested index, 2: row count. */
					__( 'Row index %1$d is out of range; the field has %2$d rows.', 'mosmcp-abilities' ),
					$index,
					$row_count
				)
			);
		}

		$action = (string) $input['action'];
		// ACF's row API is 1-based; ability input is 0-based.
		$acf_index = $index + 1;

		if ( 'update' === $action ) {
			if ( empty( $input['row_values'] ) || ! is_array( $input['row_values'] ) ) {
				return new WP_Error( 'acfa_missing_row_values', __( 'row_values is required when action is "update".', 'mosmcp-abilities' ) );
			}
			$success = update_row( $field['key'], $acf_index, $input['row_values'], $post_id );
		} else {
			$success = delete_row( $field['key'], $acf_index, $post_id );
		}

		return array(
			'success' => (bool) $success,
			'rows'    => (array) get_field( $field['key'], $post_id ),
		);
	}

	/**
	 * 13. Append a new repeater row.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function execute_append_repeater_row( $input ) {
		$prepared = self::prepare_write( $input );
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}
		list( $field, $post_id ) = $prepared;

		if ( 'repeater' !== $field['type'] ) {
			return new WP_Error( 'acfa_not_repeater', __( 'The given field is not a repeater field.', 'mosmcp-abilities' ) );
		}
		if ( empty( $input['row_values'] ) || ! is_array( $input['row_values'] ) ) {
			return new WP_Error( 'acfa_missing_row_values', __( 'row_values must be a non-empty object of sub-field name/value pairs.', 'mosmcp-abilities' ) );
		}

		$new_count = add_row( $field['key'], $input['row_values'], $post_id );
		if ( false === $new_count ) {
			return new WP_Error( 'acfa_add_row_failed', __( 'ACF failed to append the row.', 'mosmcp-abilities' ) );
		}

		return array(
			'success'   => true,
			'row_index' => max( 0, (int) $new_count - 1 ),
			'rows'      => (array) get_field( $field['key'], $post_id ),
		);
	}

	/**
	 * 14. Add/remove/replace IDs on a relationship-style field.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function execute_update_relationship_field( $input ) {
		$prepared = self::prepare_write( $input );
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}
		list( $field, $post_id ) = $prepared;

		if ( ! in_array( $field['type'], array( 'relationship', 'post_object', 'user', 'page_link' ), true ) ) {
			return new WP_Error( 'acfa_not_relationship', __( 'The given field is not a relationship, post_object, or user field.', 'mosmcp-abilities' ) );
		}

		$current    = get_field( $field['key'], $post_id, false );
		$current    = array_map( 'intval', array_filter( (array) $current ) );
		$target_ids = array_map( 'intval', (array) $input['target_ids'] );

		switch ( (string) $input['action'] ) {
			case 'add':
				$updated = array_values( array_unique( array_merge( $current, $target_ids ) ) );
				break;
			case 'remove':
				$updated = array_values( array_diff( $current, $target_ids ) );
				break;
			default: // replace.
				$updated = array_values( array_unique( $target_ids ) );
				break;
		}

		// Single-value fields (post_object/user without "multiple") store a scalar.
		$is_multi = ! empty( $field['multiple'] ) || 'relationship' === $field['type'];
		$to_save  = $is_multi ? $updated : ( $updated ? $updated[0] : '' );

		$success = update_field( $field['key'], $to_save, $post_id );
		return array(
			'success'     => (bool) $success,
			'related_ids' => $updated,
		);
	}

	/**
	 * 15. Add or reorder flexible content layout blocks.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function execute_update_flexible_content_layout( $input ) {
		$prepared = self::prepare_write( $input );
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}
		list( $field, $post_id ) = $prepared;

		if ( 'flexible_content' !== $field['type'] ) {
			return new WP_Error( 'acfa_not_flexible_content', __( 'The given field is not a flexible content field.', 'mosmcp-abilities' ) );
		}

		$rows = get_field( $field['key'], $post_id, false );
		$rows = is_array( $rows ) ? array_values( $rows ) : array();

		$action = (string) $input['action'];

		if ( 'add_layout' === $action ) {
			if ( empty( $input['layout_name'] ) ) {
				return new WP_Error( 'acfa_missing_layout_name', __( 'layout_name is required when action is "add_layout".', 'mosmcp-abilities' ) );
			}
			$layout_name = (string) $input['layout_name'];

			$known_layouts = array();
			foreach ( (array) ( isset( $field['layouts'] ) ? $field['layouts'] : array() ) as $layout ) {
				if ( is_array( $layout ) && isset( $layout['name'] ) ) {
					$known_layouts[] = $layout['name'];
				}
			}
			if ( $known_layouts && ! in_array( $layout_name, $known_layouts, true ) ) {
				return new WP_Error(
					'acfa_unknown_layout',
					sprintf(
						/* translators: 1: layout name, 2: available layout names. */
						__( 'Layout "%1$s" is not defined on this field. Available layouts: %2$s.', 'mosmcp-abilities' ),
						$layout_name,
						implode( ', ', $known_layouts )
					)
				);
			}

			$new_row = array( 'acf_fc_layout' => $layout_name );
			if ( ! empty( $input['layout_values'] ) && is_array( $input['layout_values'] ) ) {
				$new_row = array_merge( $new_row, $input['layout_values'] );
			}

			$position = isset( $input['position'] ) ? max( 0, min( (int) $input['position'], count( $rows ) ) ) : count( $rows );
			array_splice( $rows, $position, 0, array( $new_row ) );
		} else { // reorder.
			if ( empty( $input['new_order'] ) || ! is_array( $input['new_order'] ) ) {
				return new WP_Error( 'acfa_missing_new_order', __( 'new_order is required when action is "reorder".', 'mosmcp-abilities' ) );
			}
			$new_order = array_map( 'intval', $input['new_order'] );
			$count     = count( $rows );
			$sorted    = $new_order;
			sort( $sorted );
			if ( count( $new_order ) !== $count || range( 0, $count - 1 ) !== $sorted ) {
				return new WP_Error(
					'acfa_invalid_order',
					sprintf(
						/* translators: %d: layout count. */
						__( 'new_order must be a permutation of all current layout indices 0..%d.', 'mosmcp-abilities' ),
						max( 0, $count - 1 )
					)
				);
			}
			$reordered = array();
			foreach ( $new_order as $old_index ) {
				$reordered[] = $rows[ $old_index ];
			}
			$rows = $reordered;
		}

		$success = update_field( $field['key'], $rows, $post_id );
		$saved   = get_field( $field['key'], $post_id );
		$saved   = is_array( $saved ) ? array_values( $saved ) : array();

		return array(
			'success'      => (bool) $success,
			'layout_count' => count( $saved ),
			'layouts'      => $saved,
		);
	}

	/**
	 * 16. Bulk-update several fields on one post.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function execute_bulk_update_fields( $input ) {
		$post_id = ACFA_Helpers::require_post( $input );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}
		if ( empty( $input['fields'] ) || ! is_array( $input['fields'] ) ) {
			return new WP_Error( 'acfa_missing_fields', __( 'fields must be a non-empty array of {field_key, value} pairs.', 'mosmcp-abilities' ) );
		}
		$atomic = ! isset( $input['atomic'] ) || filter_var( $input['atomic'], FILTER_VALIDATE_BOOLEAN );

		// Pass 1: resolve and validate everything before writing anything.
		$queue   = array();
		$results = array();
		$all_ok  = true;

		foreach ( $input['fields'] as $index => $pair ) {
			$selector = is_array( $pair ) && isset( $pair['field_key'] ) ? (string) $pair['field_key'] : '';
			$value    = is_array( $pair ) && array_key_exists( 'value', $pair ) ? $pair['value'] : null;
			$entry    = array(
				'field_key' => $selector,
				'saved'     => false,
				'errors'    => array(),
			);

			$field = $selector ? ACFA_Helpers::field_object( $selector, $post_id ) : null;
			if ( ! $field ) {
				$entry['errors'][] = __( 'Field not found.', 'mosmcp-abilities' );
				$all_ok            = false;
			} else {
				$validation = ACFA_Helpers::validate_value( $field, $value );
				if ( ! $validation['valid'] ) {
					$entry['errors'] = $validation['errors'] ? $validation['errors'] : array( __( 'Validation failed.', 'mosmcp-abilities' ) );
					$all_ok          = false;
				} else {
					$queue[ $index ] = array( $field, $value );
				}
			}
			$results[ $index ] = $entry;
		}

		if ( $atomic && ! $all_ok ) {
			return array(
				'success' => false,
				'results' => array_values( $results ),
			);
		}

		// Pass 2: write the validated fields.
		foreach ( $queue as $index => $item ) {
			list( $field, $value )      = $item;
			$saved                      = update_field( $field['key'], $value, $post_id );
			$results[ $index ]['saved'] = (bool) $saved;
			$results[ $index ]['value'] = get_field( $field['key'], $post_id );
		}

		return array(
			'success' => $all_ok,
			'results' => array_values( $results ),
		);
	}

	/**
	 * 17. Update a field on an ACF options page.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function execute_update_options_page_field( $input ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'acfa_forbidden', __( 'You do not have permission to update options page fields.', 'mosmcp-abilities' ) );
		}
		$acf_post_id = ACFA_Helpers::options_post_id( (string) $input['options_page'] );

		$field = ACFA_Helpers::field_object( $input['field_key'], $acf_post_id );
		if ( ! $field ) {
			return new WP_Error( 'acfa_field_not_found', __( 'No ACF field matches the given field key/name on this options page.', 'mosmcp-abilities' ) );
		}

		$success = update_field( $field['key'], $input['value'], $acf_post_id );
		return array(
			'success'     => (bool) $success,
			'saved_value' => get_field( $field['key'], $acf_post_id ),
		);
	}

	/**
	 * 18. Clear a field or reset it to its default.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function execute_clear_field_value( $input ) {
		$prepared = self::prepare_write( $input );
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}
		list( $field, $post_id ) = $prepared;

		$reset_to = isset( $input['reset_to'] ) ? (string) $input['reset_to'] : 'empty';
		if ( 'field_default' === $reset_to ) {
			$value = isset( $field['default_value'] ) && '' !== $field['default_value'] && null !== $field['default_value']
				? $field['default_value']
				: ACFA_Helpers::empty_value_for_field( $field );
		} else {
			$value = ACFA_Helpers::empty_value_for_field( $field );
		}

		$success = update_field( $field['key'], $value, $post_id );
		return array(
			'success'         => (bool) $success,
			'resulting_value' => get_field( $field['key'], $post_id ),
		);
	}
}
