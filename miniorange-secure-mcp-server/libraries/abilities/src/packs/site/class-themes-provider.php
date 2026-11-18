<?php
/**
 * Execute callbacks for the Themes ability pack.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Site;

use WP_Error;
use WP_Theme;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * This library authors every translatable string under its own fixed text domain
 * ('mosmcp-abilities'). The host plugin remaps them to its own text domain at
 * runtime via Abilities_Library::init(). The domain therefore intentionally will
 * not match any host plugin's slug, so the text-domain-mismatch check is disabled
 * for this file (the library's phpcs.xml.dist allows the domain on the CLI; this
 * directive covers IDE and Plugin Check runs that don't read that ruleset).
 */
// phpcs:disable WordPress.WP.I18n.TextDomainMismatch

/**
 * Class Themes_Provider
 *
 * Static execute callbacks for theme abilities.
 */
class Themes_Provider {

	/**
	 * Theme features worth reporting, in the order a caller is likely to care about them.
	 *
	 * @var string[]
	 */
	const REPORTED_FEATURES = array(
		'post-thumbnails',
		'title-tag',
		'custom-logo',
		'custom-header',
		'custom-background',
		'menus',
		'widgets',
		'html5',
		'editor-styles',
		'wp-block-styles',
		'responsive-embeds',
		'align-wide',
		'editor-color-palette',
		'editor-font-sizes',
		'block-templates',
		'appearance-tools',
	);

	/**
	 * Lists the themes installed on this site.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public static function theme_list( $input = array() ) {
		$input   = is_array( $input ) ? $input : array();
		$status  = isset( $input['status'] ) ? (string) $input['status'] : 'all';
		$search  = isset( $input['search'] ) ? strtolower( trim( (string) $input['search'] ) ) : '';
		$refresh = Site_Support::bool_input( $input, 'refresh_updates', false );

		$only_with_update = Site_Support::bool_input( $input, 'has_update', false );

		$themes  = wp_get_themes();
		$active  = get_stylesheet();
		$updates = Site_Support::theme_updates( $refresh );

		$rows = array();

		foreach ( $themes as $stylesheet => $theme ) {
			$stylesheet = (string) $stylesheet;
			$is_active  = ( $stylesheet === $active );

			if ( 'active' === $status && ! $is_active ) {
				continue;
			}

			if ( 'inactive' === $status && $is_active ) {
				continue;
			}

			$name = (string) $theme->get( 'Name' );

			if ( '' !== $search && false === strpos( strtolower( $name . ' ' . $stylesheet ), $search ) ) {
				continue;
			}

			// is_array is not redundant with the isset below it: indexing a stray
			// object with a string key is a fatal Error, not a null.
			$update      = ( isset( $updates[ $stylesheet ] ) && is_array( $updates[ $stylesheet ] ) ) ? $updates[ $stylesheet ] : null;
			$has_update  = null !== $update;
			$new_version = ( $has_update && isset( $update['new_version'] ) ) ? (string) $update['new_version'] : '';

			if ( $only_with_update && ! $has_update ) {
				continue;
			}

			$parent = $theme->parent();

			$rows[] = array(
				'stylesheet'       => $stylesheet,
				'name'             => $name,
				'version'          => (string) $theme->get( 'Version' ),
				'author'           => wp_strip_all_tags( (string) $theme->get( 'Author' ) ),
				'status'           => $is_active ? 'active' : 'inactive',
				'is_child'         => (bool) $parent,
				'parent'           => $parent instanceof WP_Theme ? (string) $parent->get_stylesheet() : '',
				'update_available' => $has_update,
				'new_version'      => $new_version,
			);
		}

		return array(
			'showing' => count( $rows ),
			'total'   => count( $themes ),
			'active'  => $active,
			'themes'  => $rows,
		);
	}

	/**
	 * Returns full detail about the active theme.
	 *
	 * @param array<string, mixed> $input Ability input. Unused.
	 * @return array<string, mixed>
	 */
	public static function theme_get_active( $input = array() ) {
		unset( $input );

		return self::describe( wp_get_theme(), true );
	}

	/**
	 * Returns full detail about one installed theme.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function theme_get( $input = array() ) {
		$input      = is_array( $input ) ? $input : array();
		$stylesheet = isset( $input['stylesheet'] ) ? trim( (string) $input['stylesheet'] ) : '';

		if ( '' === $stylesheet ) {
			return Site_Support::error(
				'mosmcp_theme_reference_empty',
				__( 'No theme was named. Pass the theme folder name, which mosmcp/theme-list returns as "stylesheet".', 'mosmcp-abilities' ),
				Site_Support::CAUSE_INVALID_INPUT,
				true,
				array( 'use_instead' => 'mosmcp/theme-list' )
			);
		}

		$theme = wp_get_theme( $stylesheet );

		if ( ! $theme->exists() ) {
			return Site_Support::error(
				'mosmcp_theme_not_found',
				sprintf(
					/* translators: %s: the theme folder name supplied by the caller. */
					__( 'No theme is installed in a folder named "%s". Use mosmcp/theme-list to see the folder names on this site; the folder rarely matches the display name exactly.', 'mosmcp-abilities' ),
					$stylesheet
				),
				Site_Support::CAUSE_NOT_FOUND,
				false,
				array( 'use_instead' => 'mosmcp/theme-list' )
			);
		}

		return self::describe( $theme, get_stylesheet() === $theme->get_stylesheet() );
	}

	/**
	 * Builds the full description of a theme.
	 *
	 * Page templates and supported features are reported only for the active theme.
	 * WordPress resolves both from the theme currently in use, so returning them for
	 * an inactive theme would describe capabilities the site is not actually offering.
	 *
	 * @param WP_Theme $theme     The theme to describe.
	 * @param bool     $is_active Whether this theme is the one currently active.
	 * @return array<string, mixed>
	 */
	private static function describe( WP_Theme $theme, $is_active ) {
		$stylesheet = (string) $theme->get_stylesheet();
		$parent     = $theme->parent();
		$updates    = Site_Support::theme_updates( false );
		$update     = ( isset( $updates[ $stylesheet ] ) && is_array( $updates[ $stylesheet ] ) ) ? $updates[ $stylesheet ] : null;
		$screenshot = $theme->get_screenshot();
		$tags       = $theme->get( 'Tags' );
		$notes      = array();

		if ( $theme->errors() instanceof WP_Error ) {
			$notes[] = sprintf(
				/* translators: %s: the problem WordPress reported with the theme. */
				__( 'WordPress reports a problem with this theme: %s', 'mosmcp-abilities' ),
				implode( ' ', $theme->errors()->get_error_messages() )
			);
		}

		if ( $parent instanceof WP_Theme && ! $parent->exists() ) {
			$notes[] = __( 'This is a child theme whose parent theme is not installed, so the site cannot render it correctly until the parent is restored.', 'mosmcp-abilities' );
		}

		if ( ! $is_active ) {
			$notes[] = __( 'This theme is not active, so its page templates and supported features are not listed. WordPress resolves both from the theme currently in use.', 'mosmcp-abilities' );
		}

		$detail = array(
			'stylesheet'       => $stylesheet,
			'template'         => (string) $theme->get_template(),
			'name'             => (string) $theme->get( 'Name' ),
			'version'          => (string) $theme->get( 'Version' ),
			'author'           => wp_strip_all_tags( (string) $theme->get( 'Author' ) ),
			'description'      => wp_strip_all_tags( (string) $theme->get( 'Description' ) ),
			'theme_uri'        => (string) $theme->get( 'ThemeURI' ),
			'status'           => $is_active ? 'active' : 'inactive',
			'is_child'         => (bool) $parent,
			'parent'           => $parent instanceof WP_Theme
				? array(
					'stylesheet' => (string) $parent->get_stylesheet(),
					'name'       => (string) $parent->get( 'Name' ),
					'version'    => (string) $parent->get( 'Version' ),
				)
				: array(),
			'is_block_theme'   => method_exists( $theme, 'is_block_theme' ) ? (bool) $theme->is_block_theme() : false,
			'requires_wp'      => (string) $theme->get( 'RequiresWP' ),
			'requires_php'     => (string) $theme->get( 'RequiresPHP' ),
			'tags'             => is_array( $tags ) ? array_values( array_map( 'strval', $tags ) ) : array(),
			'screenshot'       => is_string( $screenshot ) ? $screenshot : '',
			'update_available' => null !== $update,
			'new_version'      => ( null !== $update && isset( $update['new_version'] ) ) ? (string) $update['new_version'] : '',
			'page_templates'   => array(),
			'supports'         => array(),
			'notes'            => $notes,
		);

		if ( $is_active ) {
			$detail['page_templates'] = self::page_templates( $theme );
			$detail['supports']       = self::supported_features();
		}

		return $detail;
	}

	/**
	 * Lists the page templates a theme registers.
	 *
	 * @param WP_Theme $theme The theme to inspect.
	 * @return array<int, array<string, string>>
	 */
	private static function page_templates( WP_Theme $theme ) {
		$templates = $theme->get_page_templates( null, 'page' );
		$rows      = array(
			array(
				'slug'  => 'default',
				'label' => __( 'Default template', 'mosmcp-abilities' ),
			),
		);

		if ( ! is_array( $templates ) ) {
			return $rows;
		}

		foreach ( $templates as $slug => $label ) {
			$rows[] = array(
				'slug'  => (string) $slug,
				'label' => (string) $label,
			);
		}

		return $rows;
	}

	/**
	 * Lists the reported theme features the active theme supports.
	 *
	 * @return string[]
	 */
	private static function supported_features() {
		$supported = array();

		foreach ( self::REPORTED_FEATURES as $feature ) {
			if ( current_theme_supports( $feature ) ) {
				$supported[] = $feature;
			}
		}

		return $supported;
	}
}
