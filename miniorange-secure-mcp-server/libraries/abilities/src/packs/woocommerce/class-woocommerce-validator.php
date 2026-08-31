<?php
/**
 * Centralized input validation for the WooCommerce abilities.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Woocommerce;

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

use DateTime;
use WP_Error;

/**
 * Class WooCommerce_Validator
 *
 * Every WooCommerce ability validates its input through this class before
 * touching a WooCommerce API, so invalid values are rejected consistently
 * and with the same error shape across products, orders, customers,
 * coupons, and reports.
 */
class WooCommerce_Validator {

	/**
	 * Validates a required positive integer ID.
	 *
	 * @param mixed  $value The raw input value.
	 * @param string $label Human-readable field name, used in the error message.
	 * @return int|WP_Error
	 */
	public static function validate_id( $value, $label ) {
		$id = absint( $value );
		if ( $id <= 0 ) {
			return new WP_Error(
				'wcab_invalid_id',
				/* translators: %s: field label, e.g. "product ID" */
				sprintf( __( 'A valid %s is required.', 'mosmcp-abilities' ), $label )
			);
		}
		return $id;
	}

	/**
	 * Validates pagination input, clamping per_page to a safe maximum.
	 *
	 * @param array<string, mixed> $input            The raw ability input.
	 * @param int                  $default_per_page Default page size when omitted.
	 * @param int                  $max_per_page     Hard ceiling on page size.
	 * @return array{page:int, per_page:int}
	 */
	public static function validate_pagination( array $input, $default_per_page = 20, $max_per_page = 100 ) {
		$page     = isset( $input['page'] ) ? absint( $input['page'] ) : 1;
		$per_page = isset( $input['per_page'] ) ? absint( $input['per_page'] ) : $default_per_page;

		return array(
			'page'     => max( 1, $page ),
			'per_page' => max( 1, min( $max_per_page, $per_page ) ),
		);
	}

	/**
	 * Validates a value against an enum of allowed values.
	 *
	 * @param mixed       $value   The raw input value.
	 * @param string[]    $allowed Allowed values.
	 * @param string      $label   Human-readable field name.
	 * @param string|null $fallback Value to fall back to when input is empty; null makes the field required.
	 * @return string|WP_Error
	 */
	public static function validate_enum( $value, array $allowed, $label, $fallback = null ) {
		if ( null === $value || '' === $value ) {
			if ( null !== $fallback ) {
				return $fallback;
			}
			/* translators: %s: field label */
			return new WP_Error( 'wcab_missing_field', sprintf( __( '%s is required.', 'mosmcp-abilities' ), $label ) );
		}

		$value = sanitize_key( (string) $value );
		if ( ! in_array( $value, $allowed, true ) ) {
			return new WP_Error(
				'wcab_invalid_enum',
				sprintf(
					/* translators: 1: field label, 2: comma-separated allowed values */
					__( '%1$s must be one of: %2$s.', 'mosmcp-abilities' ),
					$label,
					implode( ', ', $allowed )
				)
			);
		}

		return $value;
	}

	/**
	 * Validates an email address.
	 *
	 * @param mixed $value The raw input value.
	 * @return string|WP_Error
	 */
	public static function validate_email( $value ) {
		$email = sanitize_email( (string) $value );
		if ( '' === $email || ! is_email( $email ) ) {
			return new WP_Error( 'wcab_invalid_email', __( 'A valid email address is required.', 'mosmcp-abilities' ) );
		}
		return $email;
	}

	/**
	 * Validates a YYYY-MM-DD date string.
	 *
	 * @param mixed  $value The raw input value.
	 * @param string $label Human-readable field name.
	 * @return string|WP_Error
	 */
	public static function validate_date( $value, $label ) {
		$value = sanitize_text_field( (string) $value );
		$date  = DateTime::createFromFormat( 'Y-m-d', $value );
		if ( ! $date || $date->format( 'Y-m-d' ) !== $value ) {
			return new WP_Error(
				'wcab_invalid_date',
				/* translators: %s: field label */
				sprintf( __( '%s must be a valid date in YYYY-MM-DD format.', 'mosmcp-abilities' ), $label )
			);
		}
		return $value;
	}

	/**
	 * Validates a non-negative numeric price/amount string.
	 *
	 * @param mixed  $value The raw input value.
	 * @param string $label Human-readable field name.
	 * @return string|WP_Error
	 */
	public static function validate_price( $value, $label ) {
		$value = sanitize_text_field( (string) $value );
		if ( '' === $value || ! is_numeric( $value ) || (float) $value < 0 ) {
			return new WP_Error(
				'wcab_invalid_price',
				/* translators: %s: field label */
				sprintf( __( '%s must be a non-negative number.', 'mosmcp-abilities' ), $label )
			);
		}
		return $value;
	}

	/**
	 * Sanitizes an array of string slugs (e.g. category/tag slugs).
	 *
	 * @param mixed $value The raw input value.
	 * @return string[]
	 */
	public static function validate_slug_array( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'sanitize_title', $value ) ) );
	}

	/**
	 * Sanitizes an array of positive integer IDs.
	 *
	 * @param mixed $value The raw input value.
	 * @return int[]
	 */
	public static function validate_id_array( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'absint', $value ) ) );
	}

	/**
	 * Builds a wc_get_orders()/wc_get_products() date-range query string from
	 * a pair of YYYY-MM-DD input bounds (shared by Orders_Abilities and
	 * Products_Abilities, which both filter on date_created).
	 *
	 * @param array<string, mixed> $input      The raw ability input.
	 * @param string               $after_key  Input key for the lower bound.
	 * @param string               $before_key Input key for the upper bound.
	 * @return string|WP_Error Empty string when neither bound is set.
	 */
	public static function validate_date_range( array $input, $after_key = 'after', $before_key = 'before' ) {
		$after  = '';
		$before = '';

		if ( ! empty( $input[ $after_key ] ) ) {
			$after = self::validate_date( $input[ $after_key ], $after_key );
			if ( is_wp_error( $after ) ) {
				return $after;
			}
		}
		if ( ! empty( $input[ $before_key ] ) ) {
			$before = self::validate_date( $input[ $before_key ], $before_key );
			if ( is_wp_error( $before ) ) {
				return $before;
			}
		}

		if ( '' !== $after && '' !== $before ) {
			return $after . '...' . $before;
		}
		if ( '' !== $after ) {
			return '>' . $after;
		}
		if ( '' !== $before ) {
			return '<' . $before;
		}

		return '';
	}
}
