<?php
/**
 * Entry point for the abilities library.
 *
 * This is the ONLY file a host plugin requires directly:
 *
 *     require_once plugin_dir_path( __FILE__ ) . 'libraries/abilities/bootstrap.php';
 *
 * Then, once (typically deferred to plugins_loaded so arbitration has run):
 *
 *     \MoSMCP\Abilities\Abilities_Library::init( array( 'prefix' => 'acme' ) );
 *
 * It performs no class loading of its own. It registers this copy's version and
 * path into a process-global registry, then schedules a single arbitration
 * callback that — once, per request, regardless of how many copies of this
 * library different plugins have bundled — determines the highest-versioned copy
 * present and lets ONLY that copy's classes autoload. It never assumes a Composer
 * autoloader is present, and is safe to `require_once` more than once per request
 * (each bundling plugin does so independently).
 *
 * Because every copy is authored under the same `MoSMCP\Abilities\` namespace,
 * PHP can hold only one set of these class definitions per request; arbitration
 * guarantees the set that loads is the newest bundled copy's, regardless of
 * plugin activation order. See VERSION and the library readme for the multi-copy
 * contract (highest version wins; a second host's prefix is not separately
 * registered).
 *
 * @package Mosmcp_Abilities_Library
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// --------------------------------------------------------------------------
// 1. Register this copy into the process-global version registry.
// --------------------------------------------------------------------------

if ( ! isset( $GLOBALS['mosmcp_abilities_registry'] ) ) {
	$GLOBALS['mosmcp_abilities_registry'] = array();
}

$mosmcp_abilities_version = is_readable( __DIR__ . '/VERSION' )
	? trim( (string) file_get_contents( __DIR__ . '/VERSION' ) )
	: '';
$mosmcp_abilities_dir = __DIR__;

// First registrant for a given version wins a tie; later identical-version
// requires from the same or another plugin are no-ops here.
if ( '' !== $mosmcp_abilities_version && ! isset( $GLOBALS['mosmcp_abilities_registry'][ $mosmcp_abilities_version ] ) ) {
	$GLOBALS['mosmcp_abilities_registry'][ $mosmcp_abilities_version ] = $mosmcp_abilities_dir;
}

unset( $mosmcp_abilities_version, $mosmcp_abilities_dir );

// --------------------------------------------------------------------------
// 2. Schedule arbitration exactly once, before any consumer touches a class.
// --------------------------------------------------------------------------

if ( ! function_exists( 'mosmcp_abilities_arbitrate' ) ) {

	/**
	 * Picks the highest-versioned bundled copy of the abilities library present
	 * in this request and registers a namespace-scoped autoloader that resolves
	 * ONLY that copy's classes. Runs once per request no matter how many plugins
	 * bundle (and therefore require) this same function definition.
	 *
	 * It does not call Abilities_Library::init() — that stays the host's
	 * responsibility, so each host controls its own prefix and pack allow-list.
	 *
	 * @return void
	 */
	function mosmcp_abilities_arbitrate() {
		if ( did_action( 'mosmcp_abilities_loaded' ) ) {
			return;
		}

		$registry = $GLOBALS['mosmcp_abilities_registry'];
		if ( empty( $registry ) ) {
			return;
		}

		uksort( $registry, 'version_compare' );
		$winner_path = (string) end( $registry );

		// Namespace-scoped autoloader for ONLY the winning copy. Prepended so it
		// resolves MoSMCP\Abilities\* ahead of any host plugin's Composer
		// classmap, without affecting any other namespace.
		spl_autoload_register(
			function ( $class ) use ( $winner_path ) {
				$prefix = 'MoSMCP\\Abilities\\';
				if ( 0 !== strpos( $class, $prefix ) ) {
					return;
				}

				$relative = substr( $class, strlen( $prefix ) );
				$parts    = explode( '\\', $relative );
				$class_nm = array_pop( $parts );

				// Directory segments: lowercased, underscores -> hyphens.
				$dir = '';
				foreach ( $parts as $segment ) {
					$dir .= strtolower( str_replace( '_', '-', $segment ) ) . '/';
				}

				// WPCS file-naming: class-/interface-/trait- prefix + kebab-case name.
				$base = strtolower( str_replace( '_', '-', $class_nm ) ) . '.php';
				foreach ( array( 'class-', 'interface-', 'trait-' ) as $file_prefix ) {
					$file = $winner_path . '/src/' . $dir . $file_prefix . $base;
					if ( is_file( $file ) ) {
						require $file;
						return;
					}
				}
			},
			true,
			true
		);

		do_action( 'mosmcp_abilities_loaded' );
	}
}

if ( ! has_action( 'plugins_loaded', 'mosmcp_abilities_arbitrate' ) ) {
	// Very low priority: run before any consumer's own plugins_loaded work (the
	// host's Abilities_Library::init() call should be scheduled at a later
	// priority), and before other plugins' bundled copies reference a class.
	add_action( 'plugins_loaded', 'mosmcp_abilities_arbitrate', -PHP_INT_MAX );
}

// If plugins_loaded has already fired (this file was required late), arbitrate
// immediately instead of waiting for a hook that already happened.
if ( did_action( 'plugins_loaded' ) && ! did_action( 'mosmcp_abilities_loaded' ) ) {
	mosmcp_abilities_arbitrate();
}
