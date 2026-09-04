<?php
/**
 * Shared helpers for ACF abilities: registration wrapper, permission checks,
 * field resolution, value normalization, and conditional logic evaluation.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Acf;

use MoSMCP\Abilities\Naming;
use WP_Error;
use WP_User;

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
 * Class ACFA_Helpers
 */
class ACFA_Helpers {

	/**
	 * Ability name namespace.
	 */
	const NS = 'mosmcp';

	/**
	 * WP_Error codes shared across the ACF ability files (discovery, read, schema,
	 * write) so a single error condition always surfaces the same code.
	 */
	const ERR_FIELD_NOT_FOUND = 'acfa_field_not_found';
	const ERR_FORBIDDEN       = 'acfa_forbidden';
	const ERR_GROUP_NOT_FOUND = 'acfa_group_not_found';
	const ERR_LOCAL_GROUP     = 'acfa_local_group';
	const ERR_NOT_REPEATER    = 'acfa_not_repeater';
	const ERR_INVALID_POST    = 'acfa_invalid_post';

	/**
	 * Ability category slug.
	 */
	const CATEGORY = 'mosmcp-acf';

	/**
	 * Register one ability under the plugin namespace with shared defaults.
	 *
	 * @param string $key  Ability key (without namespace), e.g. 'get-field-value'.
	 * @param array  $args Ability args passed to Naming::register_ability().
	 */
	public static function register( $key, array $args ) {
		$args['category'] = self::CATEGORY;

		$meta = ( isset( $args['meta'] ) && is_array( $args['meta'] ) ) ? $args['meta'] : array();
		// MCP is the single governed door: never expose over the public REST
		// surface, and let the NHI/role policy — not a per-ability public flag —
		// decide exposure. The source set neither annotations nor show_in_rest:false.
		$meta['show_in_rest'] = false;
		unset( $meta['mcp'] );
		$meta['annotations'] = self::annotations_for( $key );
		$args['meta']        = $meta;

		Naming::register_ability( self::NS . '/' . $key, $args );
	}

	/**
	 * Registers the ACF ability category. No-op unless ACF is active.
	 *
	 * @return void
	 */
	public static function register_categories() {
		if ( ! function_exists( 'wp_register_ability_category' ) || ! function_exists( 'acf' ) ) {
			return;
		}
		Naming::register_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Advanced Custom Fields', 'mosmcp-abilities' ),
				'description' => __( 'Read, write, and manage Advanced Custom Fields data and field group schemas.', 'mosmcp-abilities' ),
			)
		);
	}

	/**
	 * Registers every ACF ability domain. No-op unless ACF is active.
	 *
	 * @return void
	 */
	public static function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) || ! function_exists( 'acf' ) ) {
			return;
		}
		ACFA_Read_Abilities::register_all();
		ACFA_Write_Abilities::register_all();
		ACFA_Schema_Abilities::register_all();
		ACFA_Discovery_Abilities::register_all();
	}

	/**
	 * The four MCP annotation hints for an ability, keyed by ability key.
	 *
	 * The source registered no annotations at all; they are supplied here so every
	 * ACF ability carries correct, explicit hints. Value writes are open_world
	 * because ACF field data can render on the public site; structural (schema)
	 * changes are not, as they only alter admin-side field definitions.
	 *
	 * @param string $key Ability key.
	 * @return array<string, bool>
	 */
	private static function annotations_for( $key ) {
		$read = self::ann( true, false, true, false );

		$map = array(
			'get-conditional-logic-rules'    => $read,
			'list-field-types-in-use'        => $read,
			'search-fields-by-value'         => $read,
			'get-all-fields-for-post'        => $read,
			'get-field-by-taxonomy'          => $read,
			'get-field-group'                => $read,
			'get-field-object'               => $read,
			'get-field-value'                => $read,
			'get-hidden-fields'              => $read,
			'get-options-page-fields'        => $read,
			'get-relationship-field'         => $read,
			'get-repeater-rows'              => $read,
			'list-field-groups'              => $read,
			'validate-field-value'           => $read,
			'add-field-to-group'             => self::ann( false, false, false, false ),
			'create-field-group'             => self::ann( false, false, false, false ),
			'update-field-location-rules'    => self::ann( false, false, true, false ),
			'append-repeater-row'            => self::ann( false, false, false, true ),
			'bulk-update-fields'             => self::ann( false, false, true, true ),
			'clear-field-value'              => self::ann( false, false, true, true ),
			'update-field-value'             => self::ann( false, false, true, true ),
			'update-flexible-content-layout' => self::ann( false, false, true, true ),
			'update-options-page-field'      => self::ann( false, false, true, true ),
			'update-relationship-field'      => self::ann( false, false, true, true ),
			'update-repeater-row'            => self::ann( false, false, true, true ),
		);

		return isset( $map[ $key ] ) ? $map[ $key ] : $read;
	}

	/**
	 * Builds the four annotation hints.
	 *
	 * @param bool $read_only   Read-only hint.
	 * @param bool $destructive Destructive hint.
	 * @param bool $idempotent  Idempotent hint.
	 * @param bool $open_world  Open-world hint.
	 * @return array<string, bool>
	 */
	private static function ann( $read_only, $destructive, $idempotent, $open_world ) {
		return array(
			'readonly'    => $read_only,
			'destructive' => $destructive,
			'idempotent'  => $idempotent,
			'open_world'  => $open_world,
		);
	}

	/**
	 * JSON Schema type list for a mixed/any value. Safe for output schemas.
	 *
	 * @return array
	 */
	public static function mixed_type() {
		return array( 'string', 'number', 'integer', 'boolean', 'array', 'object', 'null' );
	}

	/**
	 * Input schema for a mixed/any value property. Uses anyOf instead of a
	 * multi-type "type" array because AI-client compatibility filters on this
	 * site (wp_register_ability_args) collapse type arrays to a single type.
	 *
	 * @param string $description Property description.
	 * @param array  $types       Optional. Allowed primitive types.
	 * @return array
	 */
	public static function mixed_value_schema( $description, $types = null ) {
		$types  = $types ? $types : array( 'string', 'number', 'boolean', 'array', 'object', 'null' );
		$any_of = array();
		foreach ( $types as $type ) {
			$any_of[] = array( 'type' => $type );
		}
		return array(
			'description' => $description,
			'anyOf'       => $any_of,
		);
	}

	/**
	 * Capability required for structural (schema-level) changes.
	 *
	 * @return string
	 */
	public static function structural_cap() {
		$cap = function_exists( 'acf_get_setting' ) ? acf_get_setting( 'capability' ) : '';
		return $cap ? $cap : 'manage_options';
	}

	/**
	 * Permission callback factory for post-scoped abilities.
	 *
	 * @param string $cap Meta capability to check against the post. Default 'edit_post'.
	 * @return callable
	 */
	public static function post_permission( $cap = 'edit_post' ) {
		return function ( $input = null ) use ( $cap ) {
			$post_id = is_array( $input ) && isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
			if ( $post_id > 0 ) {
				return current_user_can( $cap, $post_id );
			}
			return current_user_can( 'edit_posts' );
		};
	}

	/**
	 * Validate the post_id input and the current user's capability on it.
	 *
	 * @param array  $input Ability input.
	 * @param string $cap   Meta capability. Default 'edit_post'.
	 * @return int|WP_Error Post ID on success.
	 */
	public static function require_post( $input, $cap = 'edit_post' ) {
		$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
		if ( $post_id <= 0 || ! get_post( $post_id ) ) {
			return new WP_Error( self::ERR_INVALID_POST, __( 'The given post_id does not match an existing post.', 'mosmcp-abilities' ) );
		}
		if ( ! current_user_can( $cap, $post_id ) ) {
			return new WP_Error( self::ERR_FORBIDDEN, __( 'You do not have permission to access this post.', 'mosmcp-abilities' ) );
		}
		return $post_id;
	}

	/**
	 * Resolve an ACF field object from a field key or field name.
	 *
	 * @param string     $selector Field key (field_xxx) or field name.
	 * @param int|string $post_id  Optional post context.
	 * @return array|null Field array, or null when not found.
	 */
	public static function field_object( $selector, $post_id = null ) {
		$field = get_field_object( $selector, $post_id, false, false );
		if ( ! $field && function_exists( 'acf_maybe_get_field' ) ) {
			$field = acf_maybe_get_field( $selector, $post_id, false );
		}
		return is_array( $field ) ? $field : null;
	}

	/**
	 * Strip the stored value and internal keys from a field object for output.
	 *
	 * @param array $field ACF field array.
	 * @return array
	 */
	public static function field_definition( array $field ) {
		unset( $field['value'], $field['prefix'], $field['_name'], $field['_valid'] );
		return $field;
	}

	/**
	 * ACF post_id string for a taxonomy term.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @param int    $term_id  Term ID.
	 * @return string
	 */
	public static function term_post_id( $taxonomy, $term_id ) {
		return $taxonomy . '_' . (int) $term_id;
	}

	/**
	 * ACF post_id for an options page slug. Falls back to the shared 'option'
	 * store when ACF PRO options pages are unavailable or the slug is unknown.
	 *
	 * @param string $slug Options page slug.
	 * @return string
	 */
	public static function options_post_id( $slug ) {
		if ( function_exists( 'acf_get_options_page' ) ) {
			$page = acf_get_options_page( $slug );
			if ( is_array( $page ) && ! empty( $page['post_id'] ) ) {
				return (string) $page['post_id'];
			}
		}
		return 'option';
	}

	/**
	 * Compact summary of a post for ability output.
	 *
	 * @param WP_Post|int $post Post or post ID.
	 * @return array|null
	 */
	public static function post_summary( $post ) {
		$post = get_post( $post );
		if ( ! $post ) {
			return null;
		}
		return array(
			'id'        => (int) $post->ID,
			'title'     => get_the_title( $post ),
			'post_type' => $post->post_type,
			'status'    => $post->post_status,
			'permalink' => get_permalink( $post ),
		);
	}

	/**
	 * Compact summary of a user for ability output.
	 *
	 * @param WP_User|int $user User or user ID.
	 * @return array|null
	 */
	public static function user_summary( $user ) {
		$user = is_numeric( $user ) ? get_user_by( 'id', (int) $user ) : $user;
		if ( ! $user instanceof WP_User ) {
			return null;
		}
		return array(
			'id'           => (int) $user->ID,
			'display_name' => $user->display_name,
			'user_login'   => $user->user_login,
			'roles'        => array_values( $user->roles ),
		);
	}

	/**
	 * Compact summary of a field group for ability output.
	 *
	 * @param array $group ACF field group array.
	 * @return array
	 */
	public static function group_summary( array $group ) {
		return array(
			'key'         => $group['key'],
			'title'       => $group['title'],
			'active'      => ! empty( $group['active'] ),
			'location'    => isset( $group['location'] ) ? $group['location'] : array(),
			'field_count' => function_exists( 'acf_get_field_count' ) ? (int) acf_get_field_count( $group ) : count( (array) acf_get_fields( $group ) ),
			'is_local'    => empty( $group['ID'] ),
		);
	}

	/**
	 * Resolve a relationship-style raw value (IDs) into object summaries.
	 *
	 * @param mixed $raw           Raw (unformatted) field value.
	 * @param array $field         ACF field object.
	 * @param int   $resolve_depth 1 = summaries, 2 = include each object's own ACF fields.
	 * @return array
	 */
	public static function resolve_related( $raw, array $field, $resolve_depth = 1 ) {
		if ( null === $raw || '' === $raw || false === $raw ) {
			return array();
		}
		$ids      = array_map( 'intval', (array) $raw );
		$is_user  = ( 'user' === $field['type'] );
		$resolved = array();

		// At depth 2, every non-user ID below triggers its own get_fields() call,
		// which reads postmeta under the hood — priming the cache for all of them
		// in one query here means those per-ID calls hit cache instead of each
		// issuing their own round-trip.
		if ( 2 === (int) $resolve_depth && ! $is_user ) {
			update_meta_cache(
				'post',
				array_filter(
					$ids,
					static function ( $id ) {
						return $id > 0;
					}
				)
			);
		}

		foreach ( $ids as $id ) {
			if ( $id <= 0 ) {
				continue;
			}
			$summary = $is_user ? self::user_summary( $id ) : self::post_summary( $id );
			if ( ! $summary ) {
				continue;
			}
			if ( 2 === (int) $resolve_depth && ! $is_user ) {
				$acf_fields            = get_fields( $id );
				$summary['acf_fields'] = $acf_fields ? $acf_fields : new stdClass();
			}
			$resolved[] = $summary;
		}
		return $resolved;
	}

	/**
	 * Run ACF's validation logic against a proposed value without saving.
	 *
	 * @param array $field ACF field object.
	 * @param mixed $value Proposed value.
	 * @return array { valid: bool, errors: string[] }
	 */
	public static function validate_value( array $field, $value ) {
		acf_reset_validation_errors();
		$valid  = acf_validate_value( $value, $field, 'acfa_' . $field['name'] );
		$errors = acf_get_validation_errors();
		$msgs   = array();
		if ( is_array( $errors ) ) {
			foreach ( $errors as $error ) {
				if ( ! empty( $error['message'] ) ) {
					$msgs[] = wp_strip_all_tags( $error['message'] );
				}
			}
		}
		acf_reset_validation_errors();
		return array(
			'valid'  => (bool) $valid && empty( $msgs ),
			'errors' => $msgs,
		);
	}

	/**
	 * Evaluate a field's conditional logic rules in the context of a post.
	 *
	 * @param array      $field   ACF field object.
	 * @param int|string $post_id Post context.
	 * @return bool Whether the field is currently visible.
	 */
	public static function evaluate_conditional_logic( array $field, $post_id ) {
		$logic = isset( $field['conditional_logic'] ) ? $field['conditional_logic'] : false;
		if ( empty( $logic ) || ! is_array( $logic ) ) {
			return true;
		}
		foreach ( $logic as $rule_group ) {
			$group_passes = true;
			foreach ( (array) $rule_group as $rule ) {
				if ( empty( $rule['field'] ) ) {
					continue;
				}
				$target = get_field_object( $rule['field'], $post_id, false, true );
				$value  = $target && array_key_exists( 'value', $target ) ? $target['value'] : null;
				if ( ! self::rule_matches( $value, $rule ) ) {
					$group_passes = false;
					break;
				}
			}
			if ( $group_passes ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Evaluate one conditional logic rule against a current value.
	 *
	 * @param mixed $value Current value of the governing field.
	 * @param array $rule  Rule with operator and expected value.
	 * @return bool
	 */
	private static function rule_matches( $value, array $rule ) {
		$operator = isset( $rule['operator'] ) ? $rule['operator'] : '==';
		$expected = isset( $rule['value'] ) ? $rule['value'] : '';
		$is_empty = ( null === $value || '' === $value || array() === $value || false === $value );

		switch ( $operator ) {
			case '==empty':
				return $is_empty;
			case '!=empty':
				return ! $is_empty;
			case '==':
				return is_array( $value ) ? in_array( $expected, $value ) : ( (string) $value === (string) $expected ); // phpcs:ignore WordPress.PHP.StrictInArray.MissingTrueStrict -- ACF stores mixed scalar types.
			case '!=':
				return is_array( $value ) ? ! in_array( $expected, $value ) : ( (string) $value !== (string) $expected ); // phpcs:ignore WordPress.PHP.StrictInArray.MissingTrueStrict
			case '==contains':
				return is_array( $value ) ? in_array( $expected, $value ) : ( is_string( $value ) && false !== strpos( $value, (string) $expected ) ); // phpcs:ignore WordPress.PHP.StrictInArray.MissingTrueStrict
			case '==pattern':
				return is_scalar( $value ) && (bool) preg_match( '/' . str_replace( '/', '\/', (string) $expected ) . '/', (string) $value );
			case '>':
				return is_numeric( $value ) && (float) $value > (float) $expected;
			case '<':
				return is_numeric( $value ) && (float) $value < (float) $expected;
			default:
				return true;
		}
	}

	/**
	 * Recursively assign generated field keys to field definitions missing one.
	 *
	 * @param array $fields Array of field definitions.
	 * @return array
	 */
	public static function generate_field_keys( array $fields ) {
		foreach ( $fields as &$field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			if ( empty( $field['key'] ) ) {
				$field['key'] = uniqid( 'field_' );
			}
			if ( ! empty( $field['sub_fields'] ) && is_array( $field['sub_fields'] ) ) {
				$field['sub_fields'] = self::generate_field_keys( $field['sub_fields'] );
			}
			if ( ! empty( $field['layouts'] ) && is_array( $field['layouts'] ) ) {
				foreach ( $field['layouts'] as &$layout ) {
					if ( is_array( $layout ) && ! empty( $layout['sub_fields'] ) && is_array( $layout['sub_fields'] ) ) {
						$layout['sub_fields'] = self::generate_field_keys( $layout['sub_fields'] );
					}
				}
				unset( $layout );
			}
		}
		unset( $field );
		return $fields;
	}

	/**
	 * Type-appropriate "empty" value for a field.
	 *
	 * @param array $field ACF field object.
	 * @return mixed
	 */
	public static function empty_value_for_field( array $field ) {
		$array_types = array( 'checkbox', 'relationship', 'gallery', 'repeater', 'flexible_content', 'user', 'post_object', 'taxonomy', 'select' );
		if ( in_array( $field['type'], $array_types, true ) && ! empty( $field['multiple'] ) ) {
			return array();
		}
		if ( in_array( $field['type'], array( 'checkbox', 'relationship', 'gallery', 'repeater', 'flexible_content' ), true ) ) {
			return array();
		}
		return '';
	}
}
