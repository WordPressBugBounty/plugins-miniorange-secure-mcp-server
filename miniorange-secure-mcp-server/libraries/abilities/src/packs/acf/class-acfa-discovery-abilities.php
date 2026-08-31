<?php
/**
 * Discovery / introspection ACF abilities (23-25).
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Acf;

use WP_Error;
use WP_Query;

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
 * Class ACFA_Discovery_Abilities
 */
class ACFA_Discovery_Abilities {

	/**
	 * Register abilities 23-25.
	 */
	public static function register_all() {

		ACFA_Helpers::register(
			'list-field-types-in-use',
			array(
				'label'               => __( 'List ACF Field Types in Use', 'mosmcp-abilities' ),
				'description'         => __( 'Returns a summary of which ACF field types (repeater, gallery, relationship, etc.) are used across the site, so an agent knows what it is working with before attempting operations.', 'mosmcp-abilities' ),
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_type' => array(
							'type'        => 'string',
							'description' => __( 'Restrict the scan to field groups attached to a given post type.', 'mosmcp-abilities' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'field_types' => array( 'type' => 'array' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_list_field_types_in_use' ),
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);

		ACFA_Helpers::register(
			'get-conditional-logic-rules',
			array(
				'label'               => __( 'Get ACF Conditional Logic Rules', 'mosmcp-abilities' ),
				'description'         => __( 'Returns conditional logic tied to a field, so an agent does not attempt to set a field that is currently hidden or disabled by another field\'s state.', 'mosmcp-abilities' ),
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'field_key' => array(
							'type'        => 'string',
							'description' => __( 'Field name or key to inspect.', 'mosmcp-abilities' ),
						),
						'post_id'   => array(
							'type'        => 'integer',
							'description' => __( 'Optional post context; when supplied, the rules are evaluated and an is_visible boolean is returned.', 'mosmcp-abilities' ),
						),
					),
					'required'             => array( 'field_key' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'has_conditional_logic' => array( 'type' => 'boolean' ),
						'rules'                 => array( 'type' => array( 'array', 'null' ) ),
						'is_visible'            => array( 'type' => array( 'boolean', 'null' ) ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_get_conditional_logic_rules' ),
				'permission_callback' => ACFA_Helpers::post_permission(),
			)
		);

		ACFA_Helpers::register(
			'search-fields-by-value',
			array(
				'label'               => __( 'Search Posts by ACF Field Value', 'mosmcp-abilities' ),
				'description'         => __( 'Searches across posts for a given field key/value match, e.g. find all posts where custom field "status" equals "pending".', 'mosmcp-abilities' ),
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'field_key' => array(
							'type'        => 'string',
							'description' => __( 'Field name or key to search on.', 'mosmcp-abilities' ),
						),
						'value'     => array(
							'type'        => array( 'string', 'number', 'boolean' ),
							'description' => __( 'Value or comparison target to match.', 'mosmcp-abilities' ),
						),
						'compare'   => array(
							'type'        => 'string',
							'enum'        => array( '=', '!=', '>', '<', 'LIKE' ),
							'description' => __( 'Comparison operator.', 'mosmcp-abilities' ),
							'default'     => '=',
						),
						'post_type' => array(
							'type'        => 'string',
							'description' => __( 'Restrict search to a given post type.', 'mosmcp-abilities' ),
						),
						'limit'     => array(
							'type'        => 'integer',
							'description' => __( 'Max number of results to return.', 'mosmcp-abilities' ),
							'default'     => 50,
							'minimum'     => 1,
							'maximum'     => 200,
						),
					),
					'required'             => array( 'field_key', 'value' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'matches' => array( 'type' => 'array' ),
						'count'   => array( 'type' => 'integer' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_search_fields_by_value' ),
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);
	}

	/**
	 * Recursively tally field types across a list of fields.
	 *
	 * @param array $fields Field definitions.
	 * @param array $tally  Accumulator: type => [count, example_field_keys].
	 * @return array
	 */
	private static function tally_field_types( array $fields, array $tally ) {
		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) || empty( $field['type'] ) ) {
				continue;
			}
			$type = $field['type'];
			if ( ! isset( $tally[ $type ] ) ) {
				$tally[ $type ] = array(
					'count'    => 0,
					'examples' => array(),
				);
			}
			++$tally[ $type ]['count'];
			if ( count( $tally[ $type ]['examples'] ) < 3 && ! empty( $field['key'] ) ) {
				$tally[ $type ]['examples'][] = $field['key'];
			}
			if ( ! empty( $field['sub_fields'] ) && is_array( $field['sub_fields'] ) ) {
				$tally = self::tally_field_types( $field['sub_fields'], $tally );
			}
			if ( ! empty( $field['layouts'] ) && is_array( $field['layouts'] ) ) {
				foreach ( $field['layouts'] as $layout ) {
					if ( is_array( $layout ) && ! empty( $layout['sub_fields'] ) && is_array( $layout['sub_fields'] ) ) {
						$tally = self::tally_field_types( $layout['sub_fields'], $tally );
					}
				}
			}
		}
		return $tally;
	}

	/**
	 * 23. Summarize field types used across the site.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function execute_list_field_types_in_use( $input ) {
		$filter = array();
		if ( ! empty( $input['post_type'] ) ) {
			$filter['post_type'] = sanitize_key( $input['post_type'] );
		}
		$groups = acf_get_field_groups( $filter );

		$tally = array();
		foreach ( $groups as $group ) {
			$fields = acf_get_fields( $group );
			if ( $fields ) {
				$tally = self::tally_field_types( $fields, $tally );
			}
		}

		$summary = array();
		foreach ( $tally as $type => $data ) {
			$summary[] = array(
				'field_type'         => $type,
				'count'              => $data['count'],
				'example_field_keys' => $data['examples'],
			);
		}
		usort(
			$summary,
			function ( $a, $b ) {
				return $b['count'] - $a['count'];
			}
		);

		return array( 'field_types' => $summary );
	}

	/**
	 * 24. Return (and optionally evaluate) a field's conditional logic.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function execute_get_conditional_logic_rules( $input ) {
		$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
		$field   = ACFA_Helpers::field_object( $input['field_key'], $post_id ? $post_id : null );
		if ( ! $field ) {
			return new WP_Error( 'acfa_field_not_found', __( 'No ACF field matches the given field key/name.', 'mosmcp-abilities' ) );
		}

		$logic = ! empty( $field['conditional_logic'] ) && is_array( $field['conditional_logic'] )
			? $field['conditional_logic']
			: null;

		$is_visible = null;
		if ( $post_id > 0 ) {
			if ( ! get_post( $post_id ) ) {
				return new WP_Error( 'acfa_invalid_post', __( 'The given post_id does not match an existing post.', 'mosmcp-abilities' ) );
			}
			$is_visible = ACFA_Helpers::evaluate_conditional_logic( $field, $post_id );
		}

		return array(
			'has_conditional_logic' => null !== $logic,
			'rules'                 => $logic,
			'is_visible'            => $is_visible,
		);
	}

	/**
	 * 25. Search posts by an ACF field value via meta query.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function execute_search_fields_by_value( $input ) {
		$selector = (string) $input['field_key'];

		// Meta rows are stored under the field NAME; translate a key to its name.
		$meta_key = $selector;
		if ( 0 === strpos( $selector, 'field_' ) ) {
			$field = acf_get_field( $selector );
			if ( $field && ! empty( $field['name'] ) ) {
				$meta_key = $field['name'];
			}
		}

		$compare = isset( $input['compare'] ) ? strtoupper( (string) $input['compare'] ) : '=';
		if ( ! in_array( $compare, array( '=', '!=', '>', '<', 'LIKE' ), true ) ) {
			$compare = '=';
		}

		$limit = isset( $input['limit'] ) ? (int) $input['limit'] : 50;
		$limit = max( 1, min( $limit, 200 ) );

		$query_args = array(
			'post_type'              => ! empty( $input['post_type'] ) ? sanitize_key( $input['post_type'] ) : 'any',
			'post_status'            => 'any',
			'posts_per_page'         => $limit,
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- this ability exists to search by meta value.
				array(
					'key'     => $meta_key,
					'value'   => $input['value'],
					'compare' => $compare,
				),
			),
		);

		$query   = new WP_Query( $query_args );
		$matches = array();
		foreach ( $query->posts as $post ) {
			if ( ! current_user_can( 'edit_post', $post->ID ) ) {
				continue;
			}
			$summary                        = ACFA_Helpers::post_summary( $post );
			$summary['matched_field_value'] = get_field( $meta_key, $post->ID );
			$matches[]                      = $summary;
		}

		return array(
			'matches' => $matches,
			'count'   => count( $matches ),
		);
	}
}
