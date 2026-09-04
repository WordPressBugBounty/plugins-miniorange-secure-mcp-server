<?php
/**
 * Shared page/per_page parsing and clamping for first-party abilities.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Pagination
 *
 * A single implementation of the "page"/"per_page" input contract abilities
 * expose in their JSON schema (see {@see \MoSMCP\Abilities\Packs\Core\Support\Core_Schema::pagination_props()}).
 * Several providers previously re-implemented this clamp independently, and a
 * few of them clamped the lower bound but never the upper one — meaning a
 * caller-supplied per_page far beyond what the schema advertises as the
 * maximum was passed straight through to the underlying query. Centralizing
 * it here means every caller gets the same bounds enforced the same way.
 */
class Pagination {

	/**
	 * Default number of items per page when the caller doesn't specify one.
	 */
	const DEFAULT_PER_PAGE = 20;

	/**
	 * Largest per_page a caller may request.
	 */
	const MAX_PER_PAGE = 100;

	/**
	 * Parses and clamps page/per_page from ability input, deriving the
	 * number/offset pair most list queries (WP_Query, WP_User_Query, ...) need.
	 *
	 * @param array<string, mixed> $input            Ability input.
	 * @param int                  $default_per_page Per_page to use when the caller omits it.
	 * @param int                  $max_per_page     Largest per_page a caller may request.
	 * @return array{page:int,per_page:int,number:int,offset:int}
	 */
	public static function args( array $input, $default_per_page = self::DEFAULT_PER_PAGE, $max_per_page = self::MAX_PER_PAGE ) {
		$page     = isset( $input['page'] ) ? absint( $input['page'] ) : 1;
		$per_page = isset( $input['per_page'] ) ? absint( $input['per_page'] ) : $default_per_page;

		if ( $page < 1 ) {
			$page = 1;
		}
		if ( $per_page < 1 ) {
			$per_page = $default_per_page;
		}
		if ( $per_page > $max_per_page ) {
			$per_page = $max_per_page;
		}

		return array(
			'page'     => $page,
			'per_page' => $per_page,
			'number'   => $per_page,
			'offset'   => ( $page - 1 ) * $per_page,
		);
	}
}
