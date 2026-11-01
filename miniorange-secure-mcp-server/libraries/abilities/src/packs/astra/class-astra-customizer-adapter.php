<?php
/**
 * Hands Astra's Customizer settings to the theme-settings abilities.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Astra;

use MoSMCP\Abilities\Config;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Astra_Customizer_Adapter
 *
 * Astra builds most of its Customizer controls only in the Customizer's
 * JavaScript, so WordPress never sees them as control objects and theme settings
 * would list only a handful of Astra's settings. Astra hands the full list to its
 * Customizer screen through Astra_Customizer::get_js_configs(); this passes the
 * same list to theme settings through the "{prefix}_customizer_extra_controls"
 * filter, so the generic Customizer code holds nothing Astra-specific.
 *
 * Inert unless Astra's Customizer is loaded, which is only the case in the
 * Customizer request theme settings run.
 */
class Astra_Customizer_Adapter {

	/**
	 * Adds Astra's controls to theme settings.
	 *
	 * @return void
	 */
	public static function boot() {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Prefixed via Config::prefix(); the library's prefix is host-configurable and cannot be a literal.
		add_filter( Config::prefix() . '_customizer_extra_controls', array( __CLASS__, 'controls' ) );
	}

	/**
	 * Adds Astra's panels, sections and controls, in the filter's shape.
	 *
	 * @param array<string, array<mixed>> $extra Controls, sections, panels and moved settings so far.
	 * @return array<string, array<mixed>>
	 */
	public static function controls( $extra ) {
		$js = class_exists( '\Astra_Customizer' ) && method_exists( '\Astra_Customizer', 'get_js_configs' ) ? \Astra_Customizer::get_js_configs() : null;
		if ( ! is_array( $js ) || ! is_array( $extra ) ) {
			return $extra;
		}

		foreach ( isset( $js['panels'] ) ? (array) $js['panels'] : array() as $id => $panel ) {
			$extra['panels'][ (string) $id ] = isset( $panel['title'] ) ? $panel['title'] : '';
		}
		foreach ( isset( $js['sections'] ) ? (array) $js['sections'] : array() as $id => $section ) {
			$extra['sections'][ (string) $id ] = array(
				'title' => isset( $section['title'] ) ? $section['title'] : '',
				'panel' => isset( $section['panel'] ) ? (string) $section['panel'] : '',
			);
		}
		// Core settings Astra shows in one of its own sections.
		foreach ( isset( $js['wp_defaults'] ) ? (array) $js['wp_defaults'] : array() as $id => $section ) {
			$extra['moved'][ (string) $id ] = (string) $section;
		}

		// Controls are listed by section; a group's sub-controls sit in the group's section.
		$parents = array();
		foreach ( isset( $js['controls'] ) ? (array) $js['controls'] : array() as $section => $list ) {
			foreach ( (array) $list as $config ) {
				$extra['controls'][] = self::in_section( $config, (string) $section );
				if ( is_array( $config ) && isset( $config['id'] ) ) {
					$parents[ (string) $config['id'] ] = (string) $section;
				}
			}
		}
		foreach ( isset( $js['sub_controls'] ) ? (array) $js['sub_controls'] : array() as $parent => $list ) {
			foreach ( (array) $list as $config ) {
				$extra['controls'][] = self::in_section( $config, isset( $parents[ $parent ] ) ? $parents[ $parent ] : '' );
			}
		}

		return $extra;
	}

	/**
	 * A control's configuration, with the section it is listed under when it names none.
	 *
	 * @param mixed  $config  Control configuration.
	 * @param string $section Section it is listed under.
	 * @return array<string, mixed>
	 */
	private static function in_section( $config, $section ) {
		$config = is_array( $config ) ? $config : array();
		if ( ! isset( $config['section'] ) ) {
			$config['section'] = $section;
		}
		return $config;
	}
}
