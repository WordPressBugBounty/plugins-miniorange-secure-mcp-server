<?php
/**
 * Read-only ACF abilities (1-10).
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
 * Class ACFA_Read_Abilities
 */
class ACFA_Read_Abilities {

	/**
	 * Register abilities 1-10.
	 */
	public static function register_all() {

		ACFA_Helpers::register(
			'get-field-group',
			array(
				'label'               => __( 'Get ACF Field Group', 'mosmcp-abilities' ),
				'description'         => __( 'Retrieves the full definition of an ACF field group by its key or title, including all field configs and location rules.', 'mosmcp-abilities' ),
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'group_key'      => array(
							'type'        => 'string',
							'description' => __( 'ACF field group key (e.g. group_64f1a2b3) or exact group title.', 'mosmcp-abilities' ),
						),
						'include_fields' => array(
							'type'        => 'boolean',
							'description' => __( 'Whether to include nested field definitions in the response.', 'mosmcp-abilities' ),
							'default'     => true,
						),
					),
					'required'             => array( 'group_key' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => __( 'Field group definition: key, title, location rules, menu order and (optionally) fields.', 'mosmcp-abilities' ),
				),
				'execute_callback'    => array( __CLASS__, 'execute_get_field_group' ),
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);

		ACFA_Helpers::register(
			'list-field-groups',
			array(
				'label'               => __( 'List ACF Field Groups', 'mosmcp-abilities' ),
				'description'         => __( 'Lists all registered ACF field groups with the location rules (post types, templates, taxonomies) that determine where they appear.', 'mosmcp-abilities' ),
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_type'   => array(
							'type'        => 'string',
							'description' => __( 'Filter to groups that apply to a given post type, e.g. "page" or "product".', 'mosmcp-abilities' ),
						),
						'active_only' => array(
							'type'        => 'boolean',
							'description' => __( 'If true, excludes disabled/inactive field groups.', 'mosmcp-abilities' ),
							'default'     => true,
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'properties'  => array(
						'groups' => array( 'type' => 'array' ),
						'count'  => array( 'type' => 'integer' ),
					),
					'description' => __( 'Array of field group summaries: key, title, location rules, field count.', 'mosmcp-abilities' ),
				),
				'execute_callback'    => array( __CLASS__, 'execute_list_field_groups' ),
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);

		ACFA_Helpers::register(
			'get-field-value',
			array(
				'label'               => __( 'Get ACF Field Value', 'mosmcp-abilities' ),
				'description'         => __( 'Gets the current value of a single ACF field for a specific post, typed according to the field type.', 'mosmcp-abilities' ),
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'field_key' => array(
							'type'        => 'string',
							'description' => __( 'Field name or field key, e.g. "subtitle" or field_64f1a2c4.', 'mosmcp-abilities' ),
						),
						'post_id'   => array(
							'type'        => 'integer',
							'description' => __( 'ID of the post/page/CPT entry the field is attached to.', 'mosmcp-abilities' ),
						),
					),
					'required'             => array( 'field_key', 'post_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'field_key'  => array( 'type' => 'string' ),
						'field_name' => array( 'type' => 'string' ),
						'field_type' => array( 'type' => 'string' ),
						'value'      => array( 'type' => ACFA_Helpers::mixed_type() ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_get_field_value' ),
				'permission_callback' => ACFA_Helpers::post_permission(),
			)
		);

		ACFA_Helpers::register(
			'get-all-fields-for-post',
			array(
				'label'               => __( 'Get All ACF Fields for Post', 'mosmcp-abilities' ),
				'description'         => __( 'Returns every ACF field value attached to a given post, resolved by type (flat values, repeater arrays, relationship objects) in one call.', 'mosmcp-abilities' ),
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id'       => array(
							'type'        => 'integer',
							'description' => __( 'ID of the post/page/CPT entry.', 'mosmcp-abilities' ),
						),
						'format_values' => array(
							'type'        => 'boolean',
							'description' => __( 'Whether to apply ACF format_value logic (e.g. resolving image arrays).', 'mosmcp-abilities' ),
							'default'     => true,
						),
					),
					'required'             => array( 'post_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => __( 'Object keyed by field name with type-appropriate values.', 'mosmcp-abilities' ),
				),
				'execute_callback'    => array( __CLASS__, 'execute_get_all_fields_for_post' ),
				'permission_callback' => ACFA_Helpers::post_permission(),
			)
		);

		ACFA_Helpers::register(
			'get-field-object',
			array(
				'label'               => __( 'Get ACF Field Object', 'mosmcp-abilities' ),
				'description'         => __( 'Gets field metadata (type, choices, sub-fields, conditional logic, default value) rather than the stored value — needed before attempting a write.', 'mosmcp-abilities' ),
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'field_key' => array(
							'type'        => 'string',
							'description' => __( 'Field name or field key.', 'mosmcp-abilities' ),
						),
						'post_id'   => array(
							'type'        => 'integer',
							'description' => __( 'Optional post context, required for fields with per-post conditional logic.', 'mosmcp-abilities' ),
						),
					),
					'required'             => array( 'field_key' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => __( 'Field definition: type, label, instructions, choices, sub_fields, conditional_logic rules.', 'mosmcp-abilities' ),
				),
				'execute_callback'    => array( __CLASS__, 'execute_get_field_object' ),
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);

		ACFA_Helpers::register(
			'get-hidden-fields',
			array(
				'label'               => __( 'Get Hidden ACF Fields', 'mosmcp-abilities' ),
				'description'         => __( 'Surfaces ACF fields attached to a post that are not visible in the editor UI — fields in inactive field groups or currently hidden by conditional logic — but are still readable through the API. Also reports WP screen elements hidden by field group "Hide on screen" settings.', 'mosmcp-abilities' ),
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id'   => array(
							'type'        => 'integer',
							'description' => __( 'Post/page/CPT entry to inspect.', 'mosmcp-abilities' ),
						),
						'group_key' => array(
							'type'        => 'string',
							'description' => __( 'Restrict to a single field group; otherwise scans all groups attached to the post.', 'mosmcp-abilities' ),
						),
					),
					'required'             => array( 'post_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'hidden_fields'          => array( 'type' => 'array' ),
						'hidden_screen_elements' => array( 'type' => 'array' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_get_hidden_fields' ),
				'permission_callback' => ACFA_Helpers::post_permission(),
			)
		);

		ACFA_Helpers::register(
			'get-repeater-rows',
			array(
				'label'               => __( 'Get ACF Repeater Rows', 'mosmcp-abilities' ),
				'description'         => __( 'Returns repeater or flexible-content field rows as structured arrays, each row keyed by sub-field name.', 'mosmcp-abilities' ),
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'field_key' => array(
							'type'        => 'string',
							'description' => __( 'Repeater or flexible content field name/key.', 'mosmcp-abilities' ),
						),
						'post_id'   => array(
							'type'        => 'integer',
							'description' => __( 'Post/page/CPT entry the repeater belongs to.', 'mosmcp-abilities' ),
						),
						'row_index' => array(
							'type'        => 'integer',
							'description' => __( 'If supplied, returns only that single row (0-based) instead of all rows.', 'mosmcp-abilities' ),
						),
					),
					'required'             => array( 'field_key', 'post_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'field_type' => array( 'type' => 'string' ),
						'row_count'  => array( 'type' => 'integer' ),
						'rows'       => array( 'type' => array( 'array', 'object', 'null' ) ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_get_repeater_rows' ),
				'permission_callback' => ACFA_Helpers::post_permission(),
			)
		);

		ACFA_Helpers::register(
			'get-relationship-field',
			array(
				'label'               => __( 'Get ACF Relationship Field', 'mosmcp-abilities' ),
				'description'         => __( 'Resolves a relationship, post-object, or user field into full referenced objects (title, ID, permalink) instead of raw IDs.', 'mosmcp-abilities' ),
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'field_key'     => array(
							'type'        => 'string',
							'description' => __( 'Relationship / post_object / user field name or key.', 'mosmcp-abilities' ),
						),
						'post_id'       => array(
							'type'        => 'integer',
							'description' => __( 'Post/page/CPT entry the field belongs to.', 'mosmcp-abilities' ),
						),
						'resolve_depth' => array(
							'type'        => 'integer',
							'description' => __( '1 = referenced object summaries only; 2 = also include each object\'s own ACF fields.', 'mosmcp-abilities' ),
							'enum'        => array( 1, 2 ),
							'default'     => 1,
						),
					),
					'required'             => array( 'field_key', 'post_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'field_type' => array( 'type' => 'string' ),
						'items'      => array( 'type' => 'array' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_get_relationship_field' ),
				'permission_callback' => ACFA_Helpers::post_permission(),
			)
		);

		ACFA_Helpers::register(
			'get-options-page-fields',
			array(
				'label'               => __( 'Get ACF Options Page Fields', 'mosmcp-abilities' ),
				'description'         => __( 'Reads values from ACF Options Pages — site-wide field values that are not tied to any individual post.', 'mosmcp-abilities' ),
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'options_page' => array(
							'type'        => 'string',
							'description' => __( 'Options page slug, e.g. "theme-general-settings".', 'mosmcp-abilities' ),
						),
						'field_key'    => array(
							'type'        => 'string',
							'description' => __( 'If supplied, returns only that field; otherwise returns all fields on the options page.', 'mosmcp-abilities' ),
						),
					),
					'required'             => array( 'options_page' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => __( 'Object of field name/value pairs for the specified options page.', 'mosmcp-abilities' ),
				),
				'execute_callback'    => array( __CLASS__, 'execute_get_options_page_fields' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);

		ACFA_Helpers::register(
			'get-field-by-taxonomy',
			array(
				'label'               => __( 'Get ACF Fields by Taxonomy Term', 'mosmcp-abilities' ),
				'description'         => __( 'Fetches ACF field values attached to a taxonomy term (e.g. custom fields on a product category or tag).', 'mosmcp-abilities' ),
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'taxonomy'  => array(
							'type'        => 'string',
							'description' => __( 'Taxonomy slug, e.g. "category" or "product_cat".', 'mosmcp-abilities' ),
						),
						'term_id'   => array(
							'type'        => 'integer',
							'description' => __( 'ID of the taxonomy term.', 'mosmcp-abilities' ),
						),
						'field_key' => array(
							'type'        => 'string',
							'description' => __( 'If supplied, returns only that field; otherwise returns all ACF fields on the term.', 'mosmcp-abilities' ),
						),
					),
					'required'             => array( 'taxonomy', 'term_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => __( 'Object of field name/value pairs scoped to the given taxonomy term.', 'mosmcp-abilities' ),
				),
				'execute_callback'    => array( __CLASS__, 'execute_get_field_by_taxonomy' ),
				'permission_callback' => function () {
					// Reading ACF fields stored on taxonomy terms requires term-management
					// capability. The original also allowed edit_posts, which let
					// contributors read term meta — too permissive, so it is removed.
					return current_user_can( 'manage_categories' );
				},
			)
		);
	}

	/**
	 * 1. Get a field group definition by key or title.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function execute_get_field_group( $input ) {
		$key   = (string) $input['group_key'];
		$group = acf_get_field_group( $key );

		if ( ! $group ) {
			foreach ( acf_get_field_groups() as $candidate ) {
				if ( $candidate['key'] === $key || $candidate['title'] === $key ) {
					$group = $candidate;
					break;
				}
			}
		}
		if ( ! $group ) {
			return new WP_Error( 'acfa_group_not_found', __( 'No field group matches the given key or title.', 'mosmcp-abilities' ) );
		}

		$include_fields = ! isset( $input['include_fields'] ) || filter_var( $input['include_fields'], FILTER_VALIDATE_BOOLEAN );
		if ( $include_fields ) {
			$fields          = acf_get_fields( $group );
			$group['fields'] = $fields ? array_map( array( 'ACFA_Helpers', 'field_definition' ), $fields ) : array();
		}
		return $group;
	}

	/**
	 * 2. List field groups, optionally filtered by post type / active status.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function execute_list_field_groups( $input ) {
		$filter = array();
		if ( ! empty( $input['post_type'] ) ) {
			$filter['post_type'] = sanitize_key( $input['post_type'] );
		}
		$groups = acf_get_field_groups( $filter );

		$active_only = ! isset( $input['active_only'] ) || filter_var( $input['active_only'], FILTER_VALIDATE_BOOLEAN );
		if ( $active_only ) {
			$groups = array_filter(
				$groups,
				function ( $group ) {
					return ! empty( $group['active'] );
				}
			);
		}

		$summaries = array_values( array_map( array( 'ACFA_Helpers', 'group_summary' ), $groups ) );
		return array(
			'groups' => $summaries,
			'count'  => count( $summaries ),
		);
	}

	/**
	 * 3. Get a single field value for a post.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function execute_get_field_value( $input ) {
		$post_id = ACFA_Helpers::require_post( $input );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}
		$field = ACFA_Helpers::field_object( $input['field_key'], $post_id );
		if ( ! $field ) {
			return new WP_Error( 'acfa_field_not_found', __( 'No ACF field matches the given field key/name for this post.', 'mosmcp-abilities' ) );
		}
		return array(
			'field_key'  => $field['key'],
			'field_name' => $field['name'],
			'field_type' => $field['type'],
			'value'      => get_field( $field['key'], $post_id ),
		);
	}

	/**
	 * 4. Get all field values for a post.
	 *
	 * @param array $input Ability input.
	 * @return array|object|WP_Error
	 */
	public static function execute_get_all_fields_for_post( $input ) {
		$post_id = ACFA_Helpers::require_post( $input );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}
		$format = ! isset( $input['format_values'] ) || filter_var( $input['format_values'], FILTER_VALIDATE_BOOLEAN );
		$fields = get_fields( $post_id, $format );
		return $fields ? $fields : new stdClass();
	}

	/**
	 * 5. Get field metadata (definition) without the stored value.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function execute_get_field_object( $input ) {
		$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : null;
		$field   = ACFA_Helpers::field_object( $input['field_key'], $post_id );
		if ( ! $field ) {
			return new WP_Error( 'acfa_field_not_found', __( 'No ACF field matches the given field key/name.', 'mosmcp-abilities' ) );
		}
		return ACFA_Helpers::field_definition( $field );
	}

	/**
	 * 6. Surface fields not visible in the editor UI but readable via the API.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function execute_get_hidden_fields( $input ) {
		$post_id = ACFA_Helpers::require_post( $input );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		if ( ! empty( $input['group_key'] ) ) {
			$group  = acf_get_field_group( (string) $input['group_key'] );
			$groups = $group ? array( $group ) : array();
		} else {
			// Include inactive groups too: query without visibility filtering, then match location.
			$groups = acf_get_field_groups( array( 'post_id' => $post_id ) );
			foreach ( acf_get_field_groups() as $candidate ) {
				if ( empty( $candidate['active'] ) ) {
					$groups[] = $candidate;
				}
			}
		}

		$hidden_fields   = array();
		$hidden_elements = array();
		$seen            = array();

		foreach ( $groups as $group ) {
			if ( isset( $seen[ $group['key'] ] ) ) {
				continue;
			}
			$seen[ $group['key'] ] = true;

			if ( ! empty( $group['hide_on_screen'] ) && is_array( $group['hide_on_screen'] ) ) {
				$hidden_elements = array_merge( $hidden_elements, $group['hide_on_screen'] );
			}

			$fields = acf_get_fields( $group );
			if ( ! $fields ) {
				continue;
			}
			foreach ( $fields as $field ) {
				$reason = '';
				if ( empty( $group['active'] ) ) {
					$reason = 'inactive_field_group';
				} elseif ( ! ACFA_Helpers::evaluate_conditional_logic( $field, $post_id ) ) {
					$reason = 'conditional_logic';
				}
				if ( '' === $reason ) {
					continue;
				}
				$hidden_fields[] = array(
					'field_key'  => $field['key'],
					'field_name' => $field['name'],
					'field_type' => $field['type'],
					'group_key'  => $group['key'],
					'reason'     => $reason,
					'value'      => get_field( $field['key'], $post_id ),
				);
			}
		}

		return array(
			'hidden_fields'          => $hidden_fields,
			'hidden_screen_elements' => array_values( array_unique( $hidden_elements ) ),
		);
	}

	/**
	 * 7. Get repeater / flexible content rows.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function execute_get_repeater_rows( $input ) {
		$post_id = ACFA_Helpers::require_post( $input );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}
		$field = ACFA_Helpers::field_object( $input['field_key'], $post_id );
		if ( ! $field ) {
			return new WP_Error( 'acfa_field_not_found', __( 'No ACF field matches the given field key/name for this post.', 'mosmcp-abilities' ) );
		}
		if ( ! in_array( $field['type'], array( 'repeater', 'flexible_content', 'group' ), true ) ) {
			return new WP_Error(
				'acfa_not_repeater',
				sprintf(
					/* translators: %s: actual field type. */
					__( 'Field is of type "%s", not a repeater, flexible content, or group field.', 'mosmcp-abilities' ),
					$field['type']
				)
			);
		}

		$rows = get_field( $field['key'], $post_id );
		$rows = is_array( $rows ) ? array_values( $rows ) : array();

		if ( isset( $input['row_index'] ) && '' !== $input['row_index'] ) {
			$index = (int) $input['row_index'];
			if ( $index < 0 || $index >= count( $rows ) ) {
				return new WP_Error(
					'acfa_row_out_of_range',
					sprintf(
						/* translators: 1: requested index, 2: row count. */
						__( 'Row index %1$d is out of range; the field has %2$d rows.', 'mosmcp-abilities' ),
						$index,
						count( $rows )
					)
				);
			}
			return array(
				'field_type' => $field['type'],
				'row_count'  => count( $rows ),
				'rows'       => $rows[ $index ],
			);
		}

		return array(
			'field_type' => $field['type'],
			'row_count'  => count( $rows ),
			'rows'       => $rows,
		);
	}

	/**
	 * 8. Resolve a relationship-style field into object summaries.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function execute_get_relationship_field( $input ) {
		$post_id = ACFA_Helpers::require_post( $input );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}
		$field = ACFA_Helpers::field_object( $input['field_key'], $post_id );
		if ( ! $field ) {
			return new WP_Error( 'acfa_field_not_found', __( 'No ACF field matches the given field key/name for this post.', 'mosmcp-abilities' ) );
		}
		if ( ! in_array( $field['type'], array( 'relationship', 'post_object', 'user', 'page_link' ), true ) ) {
			return new WP_Error(
				'acfa_not_relationship',
				sprintf(
					/* translators: %s: actual field type. */
					__( 'Field is of type "%s", not a relationship, post_object, or user field.', 'mosmcp-abilities' ),
					$field['type']
				)
			);
		}

		$raw   = get_field( $field['key'], $post_id, false );
		$depth = isset( $input['resolve_depth'] ) ? (int) $input['resolve_depth'] : 1;

		return array(
			'field_type' => $field['type'],
			'items'      => ACFA_Helpers::resolve_related( $raw, $field, $depth ),
		);
	}

	/**
	 * 9. Read options page field values.
	 *
	 * @param array $input Ability input.
	 * @return array|object|WP_Error
	 */
	public static function execute_get_options_page_fields( $input ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'acfa_forbidden', __( 'You do not have permission to read options page fields.', 'mosmcp-abilities' ) );
		}
		$acf_post_id = ACFA_Helpers::options_post_id( (string) $input['options_page'] );

		if ( ! empty( $input['field_key'] ) ) {
			$field = ACFA_Helpers::field_object( $input['field_key'], $acf_post_id );
			if ( ! $field ) {
				return new WP_Error( 'acfa_field_not_found', __( 'No ACF field matches the given field key/name on this options page.', 'mosmcp-abilities' ) );
			}
			return array(
				'field_name' => $field['name'],
				'field_type' => $field['type'],
				'value'      => get_field( $field['key'], $acf_post_id ),
			);
		}

		$fields = get_fields( $acf_post_id );
		return $fields ? $fields : new stdClass();
	}

	/**
	 * 10. Read ACF fields attached to a taxonomy term.
	 *
	 * @param array $input Ability input.
	 * @return array|object|WP_Error
	 */
	public static function execute_get_field_by_taxonomy( $input ) {
		$taxonomy = sanitize_key( $input['taxonomy'] );
		$term_id  = (int) $input['term_id'];

		$term = get_term( $term_id, $taxonomy );
		if ( ! $term || is_wp_error( $term ) ) {
			return new WP_Error( 'acfa_term_not_found', __( 'The given term_id does not match an existing term in that taxonomy.', 'mosmcp-abilities' ) );
		}

		$acf_post_id = ACFA_Helpers::term_post_id( $taxonomy, $term_id );

		if ( ! empty( $input['field_key'] ) ) {
			$field = ACFA_Helpers::field_object( $input['field_key'], $acf_post_id );
			if ( ! $field ) {
				return new WP_Error( 'acfa_field_not_found', __( 'No ACF field matches the given field key/name on this term.', 'mosmcp-abilities' ) );
			}
			return array(
				'field_name' => $field['name'],
				'field_type' => $field['type'],
				'value'      => get_field( $field['key'], $acf_post_id ),
			);
		}

		$fields = get_fields( $acf_post_id );
		return $fields ? $fields : new stdClass();
	}
}
