<?php
/**
 * Theme settings: the values a theme offers in the Customizer, as stored and as
 * the theme draft will make them.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Theming\Customize;

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
 * Class Theme_Settings
 *
 * A Customizer setting is stored either as a theme mod or as an option, often
 * nested: "astra-settings[header-desktop-items]" is the "header-desktop-items"
 * key of the "astra-settings" option. Each staged value keeps its storage
 * (type, base, keys) so the preview can lay it over exactly that spot, the way
 * WP_Customize_Setting::preview() does.
 */
class Theme_Settings {

	/**
	 * Settings that other abilities own, by base, with the ability to use.
	 *
	 * @var array<string, string>
	 */
	const OWNED = array(
		'blogname'                => 'mosmcp/site-update-title',
		'blogdescription'         => 'mosmcp/site-update-tagline',
		'site_icon'               => 'mosmcp/site-set-icon',
		'custom_logo'             => 'mosmcp/site-set-logo',
		'site_logo'               => 'mosmcp/site-set-logo',
		'custom_css'              => 'mosmcp/site-css-update',
		'custom_css_post_id'      => 'mosmcp/site-css-update',
		'nav_menu_locations'      => 'mosmcp/menu-assign-location',
		'nav_menu'                => 'mosmcp/menu-rename',
		'nav_menu_item'           => 'mosmcp/menu-update-item',
		'nav_menus_created_posts' => 'mosmcp/menu-add-item',
		'sidebars_widgets'        => 'mosmcp/widget-move',
		'show_on_front'           => 'mosmcp/site-set-homepage',
		'page_on_front'           => 'mosmcp/site-set-homepage',
		'page_for_posts'          => 'mosmcp/site-set-posts-page',
	);

	/**
	 * The ability that owns a setting, or null when theme settings may change it.
	 *
	 * @param string $base Setting ID base.
	 * @return string|null
	 */
	public static function owner( $base ) {
		if ( isset( self::OWNED[ $base ] ) ) {
			return self::OWNED[ $base ];
		}
		return 0 === strpos( (string) $base, 'widget_' ) ? 'mosmcp/widget-update' : null;
	}

	/**
	 * A setting ID's storage base and nested keys, parsed as WP_Customize_Setting does.
	 *
	 * @param string $id Setting ID.
	 * @return array{base:string,keys:string[]}
	 */
	public static function id_data( $id ) {
		$keys = preg_split( '/\[/', str_replace( ']', '', (string) $id ) );
		$base = (string) array_shift( $keys );
		return array(
			'base' => $base,
			'keys' => array_values( array_map( 'strval', (array) $keys ) ),
		);
	}

	/**
	 * The value stored for a setting, or null when nothing is stored.
	 *
	 * @param string   $type "theme_mod" or "option".
	 * @param string   $base Base.
	 * @param string[] $keys Nested keys.
	 * @return mixed
	 */
	public static function stored( $type, $base, array $keys ) {
		if ( 'theme_mod' === $type ) {
			$mods = get_theme_mods();
			$node = is_array( $mods ) && array_key_exists( $base, $mods ) ? $mods[ $base ] : null;
		} else {
			$node = get_option( $base, null );
		}
		foreach ( $keys as $key ) {
			if ( ! is_array( $node ) || ! array_key_exists( $key, $node ) ) {
				return null;
			}
			$node = $node[ $key ];
		}
		return $node;
	}

	/**
	 * Removes a stored value, so the setting falls back to the theme's default
	 * exactly as it did before anything was stored for it.
	 *
	 * @param string   $type "theme_mod" or "option".
	 * @param string   $base Base.
	 * @param string[] $keys Nested keys.
	 * @return void
	 */
	public static function forget( $type, $base, array $keys ) {
		if ( empty( $keys ) ) {
			if ( 'theme_mod' === $type ) {
				remove_theme_mod( $base );
			} else {
				delete_option( $base );
			}
			return;
		}

		$root = 'theme_mod' === $type ? get_theme_mod( $base, null ) : get_option( $base, null );
		if ( ! is_array( $root ) ) {
			return;
		}
		$root = self::without( $root, $keys );
		if ( 'theme_mod' === $type ) {
			set_theme_mod( $base, $root );
		} else {
			update_option( $base, $root );
		}
	}

	/**
	 * A nested array without one nested key.
	 *
	 * @param array<string, mixed> $root Array.
	 * @param string[]             $keys Path to the key.
	 * @return array<string, mixed>
	 */
	private static function without( array $root, array $keys ) {
		$key = array_shift( $keys );
		if ( ! array_key_exists( $key, $root ) ) {
			return $root;
		}
		if ( empty( $keys ) ) {
			unset( $root[ $key ] );
		} elseif ( is_array( $root[ $key ] ) ) {
			$root[ $key ] = self::without( $root[ $key ], $keys );
		}
		return $root;
	}

	/**
	 * Lays a value over a nested spot in a stored value.
	 *
	 * @param mixed    $root  Stored value.
	 * @param string[] $keys  Nested keys; empty for the root itself.
	 * @param mixed    $value Value.
	 * @return mixed
	 */
	public static function place( $root, array $keys, $value ) {
		if ( empty( $keys ) ) {
			return $value;
		}
		$root  = is_array( $root ) ? $root : array();
		$key   = array_shift( $keys );
		$child = array_key_exists( $key, $root ) ? $root[ $key ] : null;

		$root[ $key ] = self::place( $child, $keys, $value );
		return $root;
	}

	/**
	 * A value short enough for a summary line.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public static function short( $value ) {
		if ( is_bool( $value ) ) {
			$text = $value ? 'true' : 'false';
		} elseif ( is_scalar( $value ) ) {
			$text = '"' . (string) $value . '"';
		} else {
			$text = (string) wp_json_encode( $value );
		}
		return strlen( $text ) > 60 ? substr( $text, 0, 57 ) . '…' : $text;
	}

	/**
	 * How a staged setting reads in the draft summary.
	 *
	 * @param array<string, mixed> $entry Staged entry.
	 * @return string
	 */
	public static function label( array $entry ) {
		$name = '' !== (string) $entry['label'] ? (string) $entry['label'] : (string) $entry['id'];
		/* translators: 1: setting name, 2: new value. */
		return sprintf( __( '%1$s set to %2$s', 'mosmcp-abilities' ), $name, self::short( $entry['value'] ) );
	}
}
