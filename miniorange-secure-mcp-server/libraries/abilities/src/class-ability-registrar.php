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

		$name = $ability->get_name();
		if ( '' === $name || 0 !== strpos( $name, 'mosmcp/' ) ) {
			$errors[] = __( 'the name must be namespaced under "mosmcp/"', 'mosmcp-abilities' );
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
				return false;
			}

			if ( is_callable( $extra ) ) {
				return (bool) call_user_func( $extra, $input );
			}

			return true;
		};
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
