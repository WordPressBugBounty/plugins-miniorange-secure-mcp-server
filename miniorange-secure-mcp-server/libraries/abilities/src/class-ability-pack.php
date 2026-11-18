<?php
/**
 * Base class for a group of related first-party abilities.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Ability_Pack
 *
 * A pack owns one ability category and the abilities inside it, and may declare
 * a dependency so it self-disables when the plugin it integrates with is not
 * active. The Pack_Registry loads packs; the Ability_Registrar registers each
 * ability a pack provides.
 */
abstract class Ability_Pack {

	/**
	 * The ability category this pack registers.
	 *
	 * @return array Category definition with 'slug', 'label', and 'description' keys.
	 */
	abstract public function category();

	/**
	 * The abilities this pack provides.
	 *
	 * @return Ability[] Ability definitions.
	 */
	abstract public function abilities();

	/**
	 * Optional dependency guard.
	 *
	 * Return the name of a class, function, or constant that exists only when the
	 * integrated plugin is active (for example 'WooCommerce', 'get_field',
	 * 'WPSEO_VERSION'). Null means the pack is always available.
	 *
	 * @return string|null
	 */
	public function dependency() {
		return null;
	}

	/**
	 * Whether this pack's dependency is satisfied in the current request.
	 *
	 * @return bool
	 */
	public function is_available() {
		$dependency = $this->dependency();

		if ( empty( $dependency ) ) {
			return true;
		}

		return class_exists( $dependency ) || function_exists( $dependency ) || defined( $dependency );
	}
}
