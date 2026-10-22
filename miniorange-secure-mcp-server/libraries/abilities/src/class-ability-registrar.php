<?php
/**
 * Validates and registers first-party abilities with the WordPress Abilities API.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities;

use MoSMCP\Abilities\Support\Permissions;
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
 * Class Ability_Registrar
 *
 * The single door through which every first-party ability is registered. It is
 * the enforcement layer: an ability that fails validation is never registered,
 * so a non-compliant definition cannot reach a client. The developer execute
 * callback is wrapped, never rewritten — the registrar governs the capability
 * gate, the MCP annotations, the schema contract, and REST exposure around it.
 */
class Ability_Registrar {

	/**
	 * Capabilities too weak to gate a writing ability.
	 *
	 * @var string[]
	 */
	const WEAK_CAPS = array( 'read', 'exist', 'true', '1' );

	/**
	 * Kinds of object an object-level capability can be checked against.
	 *
	 * @var string
	 */
	const SUBJECT_POST    = 'post';
	const SUBJECT_COMMENT = 'comment';
	const SUBJECT_USER    = 'user';

	/**
	 * Object-level capabilities, mapped to the kind of object they address and
	 * the broader capability that governs that kind.
	 *
	 * Used to tell "this object does not exist" apart from "you may not touch
	 * this object", which current_user_can() reports identically.
	 *
	 * @var array<string, string[]>
	 */
	const OBJECT_CAP_SUBJECTS = array(
		'edit_post'         => array( self::SUBJECT_POST, 'edit_posts' ),
		'delete_post'       => array( self::SUBJECT_POST, 'edit_posts' ),
		'read_post'         => array( self::SUBJECT_POST, 'edit_posts' ),
		'publish_post'      => array( self::SUBJECT_POST, 'edit_posts' ),
		'edit_post_meta'    => array( self::SUBJECT_POST, 'edit_posts' ),
		'delete_post_meta'  => array( self::SUBJECT_POST, 'edit_posts' ),
		'read_post_meta'    => array( self::SUBJECT_POST, 'edit_posts' ),
		'edit_comment'      => array( self::SUBJECT_COMMENT, 'moderate_comments' ),
		'moderate_comments' => array( self::SUBJECT_COMMENT, 'moderate_comments' ),
		'edit_user'         => array( self::SUBJECT_USER, 'edit_users' ),
		'delete_user'       => array( self::SUBJECT_USER, 'edit_users' ),
	);

	/**
	 * Validates and registers a single ability.
	 *
	 * @param Ability $ability Ability definition.
	 * @return bool True when the ability was registered.
	 */
	public static function register( Ability $ability ) {
		$errors = self::validate( $ability );

		if ( ! empty( $errors ) ) {
			self::reject( $ability, $errors );
			return false;
		}

		if ( ! function_exists( 'wp_register_ability' ) ) {
			return false;
		}

		Naming::register_ability( $ability->get_name(), self::build_args( $ability ) );
		return true;
	}

	/**
	 * Collects every reason an ability must not be registered.
	 *
	 * @param Ability $ability Ability definition.
	 * @return string[] Human-readable validation errors; empty when valid.
	 */
	private static function validate( Ability $ability ) {
		$errors = array();

		$name   = $ability->get_name();
		$prefix = Config::AUTHORING_PREFIX . '/';
		if ( '' === $name || 0 !== strpos( $name, $prefix ) ) {
			$errors[] = sprintf(
				/* translators: %s: required ability-name prefix, e.g. "mosmcp/". */
				__( 'the name must be namespaced under "%s"', 'mosmcp-abilities' ),
				$prefix
			);
		}
		if ( '' === $ability->get_label() ) {
			$errors[] = __( 'a label is required', 'mosmcp-abilities' );
		}
		if ( '' === $ability->get_description() ) {
			$errors[] = __( 'a description is required', 'mosmcp-abilities' );
		}
		if ( '' === $ability->get_category() ) {
			$errors[] = __( 'a category is required', 'mosmcp-abilities' );
		}
		if ( null === $ability->get_execute() ) {
			$errors[] = __( 'a callable execute callback is required', 'mosmcp-abilities' );
		}

		$errors = array_merge( $errors, self::validate_capability( $ability ) );
		$errors = array_merge( $errors, self::validate_annotations( $ability ) );
		$errors = array_merge( $errors, self::validate_schema( $ability->get_input_schema(), 'input_schema', true ) );

		$output_schema = $ability->get_output_schema();
		if ( ! empty( $output_schema ) ) {
			$errors = array_merge( $errors, self::validate_schema( $output_schema, 'output_schema', false ) );
		}

		return $errors;
	}

	/**
	 * Enforces a real, sufficiently strong capability gate.
	 *
	 * @param Ability $ability Ability definition.
	 * @return string[]
	 */
	private static function validate_capability( Ability $ability ) {
		$errors     = array();
		$capability = $ability->get_capability();

		if ( '' === $capability ) {
			$errors[] = __( 'a capability is required (an ability may not be public or logged-in-only)', 'mosmcp-abilities' );
			return $errors;
		}

		if ( $ability->is_writer() && in_array( strtolower( $capability ), self::WEAK_CAPS, true ) ) {
			$errors[] = __( 'a writing ability must require a capability stronger than read/exist', 'mosmcp-abilities' );
		}

		return $errors;
	}

	/**
	 * Enforces the four MCP annotation hints as explicit booleans.
	 *
	 * @param Ability $ability Ability definition.
	 * @return string[]
	 */
	private static function validate_annotations( Ability $ability ) {
		$errors      = array();
		$annotations = $ability->get_annotations();

		foreach ( array( 'readonly', 'destructive', 'idempotent', 'open_world' ) as $key ) {
			if ( ! array_key_exists( $key, $annotations ) ) {
				$errors[] = sprintf(
					/* translators: %s: annotation name. */
					__( 'the "%s" annotation is missing', 'mosmcp-abilities' ),
					$key
				);
				continue;
			}
			if ( ! is_bool( $annotations[ $key ] ) ) {
				$errors[] = sprintf(
					/* translators: %s: annotation name. */
					__( 'the "%s" annotation must be a boolean', 'mosmcp-abilities' ),
					$key
				);
			}
		}

		return $errors;
	}

	/**
	 * Applies object-schema hygiene: additionalProperties and described required fields.
	 *
	 * @param array<string, mixed> $schema               Schema to check.
	 * @param string               $label                Schema label used in error messages.
	 * @param bool                 $require_descriptions Whether required fields must be described.
	 * @return string[]
	 */
	private static function validate_schema( array $schema, $label, $require_descriptions ) {
		$errors = array();

		$type = isset( $schema['type'] ) ? $schema['type'] : '';
		if ( 'object' !== $type ) {
			return $errors;
		}

		if ( ! array_key_exists( 'additionalProperties', $schema ) ) {
			$errors[] = sprintf(
				/* translators: %s: schema name (input_schema or output_schema). */
				__( '%s must declare additionalProperties', 'mosmcp-abilities' ),
				$label
			);
		}

		if ( ! $require_descriptions ) {
			return $errors;
		}

		$properties = ( isset( $schema['properties'] ) && is_array( $schema['properties'] ) ) ? $schema['properties'] : array();
		$required   = ( isset( $schema['required'] ) && is_array( $schema['required'] ) ) ? $schema['required'] : array();

		foreach ( $required as $field ) {
			if ( empty( $properties[ $field ]['description'] ) ) {
				$errors[] = sprintf(
					/* translators: 1: schema name, 2: required field name. */
					__( '%1$s required field "%2$s" needs a description', 'mosmcp-abilities' ),
					$label,
					$field
				);
			}
		}

		return $errors;
	}

	/**
	 * Builds the WordPress registration arguments for a validated ability.
	 *
	 * @param Ability $ability Ability definition.
	 * @return array<string, mixed>
	 */
	private static function build_args( Ability $ability ) {
		$meta = array_merge(
			$ability->get_extra_meta(),
			array(
				'annotations'  => self::normalize_annotations( $ability->get_annotations() ),
				'required_cap' => $ability->get_required_cap(),
				'object_level' => ( null !== $ability->get_cap_args() ),
				'show_in_rest' => false,
			)
		);

		$args = array(
			'label'               => $ability->get_label(),
			'description'         => $ability->get_description(),
			'category'            => $ability->get_category(),
			'input_schema'        => $ability->get_input_schema(),
			'execute_callback'    => self::wrap_execute( $ability ),
			'permission_callback' => self::wrap_permission( $ability ),
			'meta'                => $meta,
		);

		$output_schema = $ability->get_output_schema();
		if ( ! empty( $output_schema ) ) {
			$args['output_schema'] = $output_schema;
		}

		return $args;
	}

	/**
	 * Canonicalises the four annotations to explicit booleans.
	 *
	 * @param array<string, mixed> $annotations Declared annotations.
	 * @return array<string, bool>
	 */
	private static function normalize_annotations( array $annotations ) {
		return array(
			'readonly'    => ! empty( $annotations['readonly'] ),
			'destructive' => ! empty( $annotations['destructive'] ),
			'idempotent'  => ! empty( $annotations['idempotent'] ),
			'open_world'  => ! empty( $annotations['open_world'] ),
		);
	}

	/**
	 * Wraps the permission check with the ability's declared capability gate.
	 *
	 * @param Ability $ability Ability definition.
	 * @return callable
	 */
	private static function wrap_permission( Ability $ability ) {
		$capability = $ability->get_capability();
		$resolver   = $ability->get_cap_args();
		$extra      = $ability->get_permission_extra();

		return static function ( $input = array() ) use ( $capability, $resolver, $extra ) {
			$input = is_array( $input ) ? $input : array();
			$args  = array();

			if ( is_callable( $resolver ) ) {
				$resolved = call_user_func( $resolver, $input );
				if ( is_array( $resolved ) ) {
					$args = array_values( $resolved );
				}
			}

			if ( ! current_user_can( $capability, ...$args ) ) {
				/*
				 * An object-level capability also fails when the object does not
				 * exist at all. Refusing here reports a stale or mistyped ID as a
				 * permission problem, which sends the caller looking at their
				 * grants instead of at the ID -- and stops an assistant retrying
				 * with a correct one. Let the execute callback answer instead: it
				 * already reports "no post with that ID" precisely. Deferral is
				 * limited to callers who hold the capability generally, so a
				 * caller without it still learns nothing about which IDs exist.
				 */
				if ( self::object_is_absent( $capability, $args ) ) {
					return true;
				}

				return false;
			}

			if ( is_callable( $extra ) ) {
				return (bool) call_user_func( $extra, $input );
			}

			return true;
		};
	}

	/**
	 * Whether a failed object-level capability check failed because the target
	 * object is absent rather than because the caller lacks permission.
	 *
	 * Only object capabilities whose subject can be looked up cheaply are
	 * considered, and only for a caller who holds the broader capability that
	 * governs that kind of object. Anything else returns false, so the ordinary
	 * permission refusal stands.
	 *
	 * @param string       $capability The declared capability.
	 * @param array<mixed> $args       Resolved current_user_can() arguments.
	 * @return bool True when the object does not exist and the caller may be told so.
	 */
	private static function object_is_absent( $capability, array $args ) {
		if ( ! isset( self::OBJECT_CAP_SUBJECTS[ $capability ] ) || empty( $args ) ) {
			return false;
		}

		list( $kind, $general ) = self::OBJECT_CAP_SUBJECTS[ $capability ];

		$id = $args[0];
		if ( ! is_numeric( $id ) || (int) $id <= 0 ) {
			return false;
		}
		$id = (int) $id;

		switch ( $kind ) {
			case self::SUBJECT_POST:
				$exists = null !== get_post( $id );
				break;
			case self::SUBJECT_COMMENT:
				$exists = null !== get_comment( $id );
				break;
			case self::SUBJECT_USER:
				$exists = false !== get_userdata( $id );
				break;
			default:
				return false;
		}

		if ( $exists ) {
			// The object is there, so the refusal really is about permission.
			return false;
		}

		return current_user_can( $general );
	}

	/**
	 * Wraps the developer execute callback, preserving its logic untouched.
	 *
	 * Input guards (for example reserved-key screening) will be attached here as
	 * packs that need them are merged; the callback itself is never modified.
	 *
	 * @param Ability $ability Ability definition.
	 * @return callable
	 */
	private static function wrap_execute( Ability $ability ) {
		$execute    = $ability->get_execute();
		$guard_keys = $ability->get_guard_meta_keys();

		return static function ( $input = array() ) use ( $execute, $guard_keys ) {
			$input = is_array( $input ) ? $input : array();

			if ( ! empty( $guard_keys ) ) {
				$violation = Permissions::screen_meta_keys( $input, $guard_keys );
				if ( $violation instanceof WP_Error ) {
					return $violation;
				}
			}

			return call_user_func( $execute, $input );
		};
	}

	/**
	 * Signals a rejected ability to developers without breaking the request.
	 *
	 * @param Ability  $ability Ability that failed validation.
	 * @param string[] $errors  Validation errors.
	 * @return void
	 */
	private static function reject( Ability $ability, array $errors ) {
		$version = Config::version();

		_doing_it_wrong(
			__METHOD__,
			sprintf(
				/* translators: 1: ability name, 2: semicolon-separated list of validation errors. */
				esc_html__( 'The ability "%1$s" was not registered: %2$s.', 'mosmcp-abilities' ),
				esc_html( $ability->get_name() ),
				esc_html( implode( '; ', $errors ) )
			),
			esc_html( $version )
		);
	}
}
