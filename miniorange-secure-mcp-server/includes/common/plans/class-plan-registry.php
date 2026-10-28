<?php
/**
 * Discovers the plans shipped in this build and loads them in order.
 *
 * @package Miniorange_Secure_MCP_Server
 */

namespace MoSMCP\Common\Plans;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Plan_Registry
 *
 * Each folder under includes/plans/ is a plan. Its plan.php manifest returns:
 *
 * - `id`       (string)   The folder name; also the id its React entry registers with.
 * - `version`  (string)   Optional release version of this plan's build, stamped by the
 *                         release build; without it the build uses the main file's.
 * - `rank`     (int)      Load order: lower ranks load first.
 * - `requires` (string[]) Plans this one builds on; it is skipped when any is absent.
 * - `replaces` (string[]) Plans this one takes the place of; they are skipped when it loads.
 * - `paths`    (string[]) Optional extra paths outside the conventional plan folders that
 *                         belong to this plan (read by the release build only).
 *
 * Common knows no plan by name: a build is whatever plan folders it ships, and a
 * new plan is a new folder with a manifest and a loader.
 */
class Plan_Registry {

	/**
	 * Optional wp-config.php constant naming the one plan to run, for testing a
	 * single tier from a source tree that holds every plan.
	 */
	const DEV_PLAN_CONSTANT = 'MOSMCP_DEV_PLAN';

	/**
	 * Manifests of the plans loaded on this request, in load order.
	 *
	 * @var array<string, array>
	 */
	private static $active = array();

	/**
	 * Resolves and requires every active plan's loader. Called once from the main plugin file.
	 *
	 * @return void
	 */
	public static function load() {
		self::$active = self::resolve( self::discover() );

		foreach ( self::$active as $manifest ) {
			require_once $manifest['dir'] . 'loader.php';
		}
	}

	/**
	 * Ids of the plans loaded on this request, in load order.
	 *
	 * @return string[]
	 */
	public static function active_ids() {
		return array_keys( self::$active );
	}

	/**
	 * Reads every plan manifest shipped in this build.
	 *
	 * A folder without a readable manifest and loader, or whose manifest is
	 * malformed, is not a plan and is ignored.
	 *
	 * @return array<string, array> Manifests keyed by plan id.
	 */
	private static function discover() {
		$found = array();
		$files = glob( MOSMCP_PLUGIN_DIR . 'includes/plans/*/plan.php' );

		foreach ( is_array( $files ) ? $files : array() as $file ) {
			$dir = trailingslashit( dirname( $file ) );
			if ( ! is_readable( $dir . 'loader.php' ) ) {
				continue;
			}

			$manifest = require $file;
			if ( ! is_array( $manifest ) || ! isset( $manifest['id'] ) || basename( $dir ) !== $manifest['id'] ) {
				continue;
			}

			$found[ $manifest['id'] ] = array(
				'id'       => (string) $manifest['id'],
				'rank'     => isset( $manifest['rank'] ) ? (int) $manifest['rank'] : 0,
				'requires' => isset( $manifest['requires'] ) ? array_map( 'strval', (array) $manifest['requires'] ) : array(),
				'replaces' => isset( $manifest['replaces'] ) ? array_map( 'strval', (array) $manifest['replaces'] ) : array(),
				'dir'      => $dir,
			);
		}

		return $found;
	}

	/**
	 * Picks the plans to load from those found, sorted by rank.
	 *
	 * With MOSMCP_DEV_PLAN set, only that plan and what it requires load.
	 * Otherwise every plan loads except those another present plan replaces.
	 * Either way a plan whose requirements are missing is skipped.
	 *
	 * @param array<string, array> $found Manifests keyed by plan id.
	 * @return array<string, array>
	 */
	private static function resolve( array $found ) {
		$selected = defined( self::DEV_PLAN_CONSTANT ) ? self::with_requirements( (string) constant( self::DEV_PLAN_CONSTANT ), $found ) : $found;

		$replaced = array();
		foreach ( $selected as $manifest ) {
			$replaced = array_merge( $replaced, $manifest['replaces'] );
		}
		$selected = array_diff_key( $selected, array_flip( $replaced ) );

		// Dropping one plan can strand another that requires it, so repeat until stable.
		do {
			$count    = count( $selected );
			$selected = array_filter(
				$selected,
				static function ( $manifest ) use ( $selected ) {
					return array() === array_diff( $manifest['requires'], array_keys( $selected ) );
				}
			);
		} while ( count( $selected ) !== $count );

		uasort(
			$selected,
			static function ( $a, $b ) {
				return $a['rank'] <=> $b['rank'];
			}
		);

		return $selected;
	}

	/**
	 * A plan and, recursively, every plan it requires.
	 *
	 * @param string               $id    Plan id.
	 * @param array<string, array> $found Manifests keyed by plan id.
	 * @return array<string, array>
	 */
	private static function with_requirements( $id, array $found ) {
		$selected = array();
		$queue    = array( $id );

		while ( $queue ) {
			$next = array_shift( $queue );
			if ( isset( $selected[ $next ] ) || ! isset( $found[ $next ] ) ) {
				continue;
			}
			$selected[ $next ] = $found[ $next ];
			$queue             = array_merge( $queue, $found[ $next ]['requires'] );
		}

		return $selected;
	}
}
