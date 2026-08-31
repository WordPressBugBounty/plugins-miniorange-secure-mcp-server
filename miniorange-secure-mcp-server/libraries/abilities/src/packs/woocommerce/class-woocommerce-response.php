<?php
/**
 * Standard success/error envelope and pagination metadata for WooCommerce abilities.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Woocommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_Error;

/**
 * Class WooCommerce_Response
 *
 * Every ability's execute_callback returns its payload through
 * {@see self::success()} so every WooCommerce ability produces the same
 * envelope shape regardless of domain. Failures are still returned as
 * WP_Error (via {@see self::error()}, not the envelope): the Abilities API's
 * own WP_Ability::execute() treats a WP_Error return as the ability call's
 * failure signal, and any MCP server built on wp_get_abilities() (this
 * plugin has no dependency on, or knowledge of, a specific one) maps that to
 * its own protocol-level error. The envelope's error fields exist for
 * callers that inspect the ability layer directly, not the MCP wire format.
 */
class WooCommerce_Response {

	/**
	 * Builds a success envelope.
	 *
	 * @param string                    $ability    The ability name (e.g. mosmcp/list-products).
	 * @param mixed                     $data       The ability's result payload.
	 * @param string                    $message    Human-readable summary.
	 * @param array<string, mixed>|null $pagination Pagination metadata from {@see self::pagination_meta()}, or null when not applicable.
	 * @param float|null                $started_at microtime(true) captured at the start of execution.
	 * @return array<string, mixed>
	 */
	public static function success( $ability, $data, $message = '', array $pagination = null, $started_at = null ) {
		return array(
			'success' => true,
			'message' => $message,
			'data'    => $data,
			'meta'    => self::build_meta( $ability, $pagination, $started_at ),
		);
	}

	/**
	 * Builds a WP_Error for a failed ability call.
	 *
	 * @param string   $code    A wcab_* error code.
	 * @param string   $message Human-readable error message.
	 * @param string[] $errors  Optional list of granular validation error strings.
	 * @return WP_Error
	 */
	public static function error( $code, $message, array $errors = array() ) {
		return new WP_Error( $code, $message, array( 'errors' => $errors ) );
	}

	/**
	 * Builds pagination metadata from a total row count.
	 *
	 * @param int $total    Total matching rows across all pages.
	 * @param int $page     The current page number (1-based).
	 * @param int $per_page The page size.
	 * @return array{total:int, total_pages:int, current_page:int, has_next:bool, has_previous:bool}
	 */
	public static function pagination_meta( $total, $page, $per_page ) {
		$total_pages = $per_page > 0 ? (int) ceil( $total / $per_page ) : 0;

		return array(
			'total'        => (int) $total,
			'total_pages'  => $total_pages,
			'current_page' => (int) $page,
			'has_next'     => $page < $total_pages,
			'has_previous' => $page > 1,
		);
	}

	/**
	 * Wraps a data schema in the standard envelope's output_schema.
	 *
	 * @param array<string, mixed> $data_schema JSON schema for the "data" field.
	 * @param bool                 $paginated   Whether this ability returns pagination metadata.
	 * @return array<string, mixed>
	 */
	public static function envelope_schema( array $data_schema, $paginated = false ) {
		$meta_properties = array(
			'ability'        => array( 'type' => 'string' ),
			'timestamp'      => array( 'type' => 'string' ),
			'execution_time' => array( 'type' => array( 'string', 'null' ) ),
		);

		if ( $paginated ) {
			$meta_properties['pagination'] = array(
				'type'                 => 'object',
				'properties'           => array(
					'total'        => array( 'type' => 'integer' ),
					'total_pages'  => array( 'type' => 'integer' ),
					'current_page' => array( 'type' => 'integer' ),
					'has_next'     => array( 'type' => 'boolean' ),
					'has_previous' => array( 'type' => 'boolean' ),
				),
				'additionalProperties' => false,
			);
		}

		return array(
			'type'                 => 'object',
			'properties'           => array(
				'success' => array( 'type' => 'boolean' ),
				'message' => array( 'type' => 'string' ),
				'data'    => $data_schema,
				'meta'    => array(
					'type'                 => 'object',
					'properties'           => $meta_properties,
					'additionalProperties' => false,
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * @param string                    $ability    Ability name.
	 * @param array<string, mixed>|null $pagination Pagination metadata, or null when not applicable.
	 * @param float|null                $started_at microtime(true) captured at the start of execution.
	 * @return array<string, mixed>
	 */
	private static function build_meta( $ability, $pagination, $started_at ) {
		$meta = array(
			'ability'        => $ability,
			'timestamp'      => gmdate( 'c' ),
			'execution_time' => null !== $started_at ? round( ( microtime( true ) - $started_at ) * 1000, 2 ) . 'ms' : null,
		);

		if ( null !== $pagination ) {
			$meta['pagination'] = $pagination;
		}

		return $meta;
	}
}
