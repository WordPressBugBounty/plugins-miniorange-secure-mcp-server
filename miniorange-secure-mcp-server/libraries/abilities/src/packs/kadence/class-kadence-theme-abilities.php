<?php
/**
 * Kadence theme per-page layout abilities (Slice ②).
 *
 * These operate on the Kadence theme's per-post meta (the "Kadence" panel on the
 * post edit screen), not on block content, so they are gated on the theme rather
 * than the blocks plugin and require only edit_post (no block markup, so the KSES
 * concern in §13 does not apply). Keys and value vocabularies are taken verbatim
 * from the Kadence theme source (inc/meta/class-theme-meta.php).
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Kadence;

use MoSMCP\Abilities\Packs\Core\Support\Core_Schema as Schema;
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
 * Class Kadence_Theme_Abilities
 */
class Kadence_Theme_Abilities {

	/**
	 * Friendly input name => [ meta key, allowed values (empty = free string) ].
	 *
	 * Values verified against the Kadence theme's per-post meta selects.
	 *
	 * @var array<string, array{0: string, 1: string[]}>
	 */
	const SETTINGS = array(
		'layout'             => array( '_kad_post_layout', array( 'default', 'normal', 'narrow', 'fullwidth', 'left', 'right' ) ),
		'content_style'      => array( '_kad_post_content_style', array( 'default', 'boxed', 'unboxed' ) ),
		'vertical_padding'   => array( '_kad_post_vertical_padding', array( 'default', 'show', 'hide', 'top', 'bottom' ) ),
		'title'              => array( '_kad_post_title', array( 'default', 'show', 'hide' ) ),
		'transparent_header' => array( '_kad_post_transparent', array( 'default', 'enable', 'disable' ) ),
		'header'             => array( '_kad_post_header', array( 'default', 'enable', 'disable' ) ),
		'footer'             => array( '_kad_post_footer', array( 'default', 'enable', 'disable' ) ),
		'sidebar_id'         => array( '_kad_post_sidebar_id', array() ),
		'css_class'          => array( '_kad_post_classname', array() ),
	);

	/**
	 * Registers the theme-layout abilities. Called only when the Kadence theme is active.
	 *
	 * @return void
	 */
	public static function register_all() {
		self::layout_get();
		self::layout_set();
	}

	/**
	 * mosmcp/kadence-page-layout-get.
	 *
	 * @return void
	 */
	private static function layout_get() {
		Kadence_Helpers::register(
			'kadence-page-layout-get',
			array(
				'label'               => __( 'Get Kadence Page Layout', 'mosmcp-abilities' ),
				'description'         => __( 'Reads the Kadence theme per-page layout settings for a page or post: layout, sidebar, content style, vertical padding, title visibility, transparent header, header/footer visibility, and custom class. Also returns every raw _kad_post_* meta value present, so nothing is hidden.', 'mosmcp-abilities' ),
				'input_schema'        => Schema::object(
					array(
						'id' => Schema::int( __( 'The ID of the page or post.', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
					),
					array( 'id' )
				),
				'output_schema'       => Schema::object(
					array(
						'id'       => Schema::int(),
						'settings' => array( 'type' => 'object' ),
						'raw_meta' => array( 'type' => 'object' ),
					),
					array( 'id', 'settings' )
				),
				'execute_callback'    => array( __CLASS__, 'execute_layout_get' ),
				'permission_callback' => Kadence_Helpers::can_edit_post(),
			),
			Kadence_Helpers::ann( true, false, true, false )
		);
	}

	/**
	 * mosmcp/kadence-page-layout-set.
	 *
	 * @return void
	 */
	private static function layout_set() {
		Kadence_Helpers::register(
			'kadence-page-layout-set',
			array(
				'label'               => __( 'Set Kadence Page Layout', 'mosmcp-abilities' ),
				'description'         => __( 'Sets one or more Kadence theme per-page layout settings. Only the settings you supply are changed. Valid values: layout = default|normal|narrow|fullwidth|left|right; content_style = default|boxed|unboxed; vertical_padding = default|show|hide|top|bottom; title = default|show|hide; transparent_header/header/footer = default|enable|disable; sidebar_id and css_class are free text.', 'mosmcp-abilities' ),
				'input_schema'        => Schema::object(
					array(
						'id'                 => Schema::int( __( 'The ID of the page or post.', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
						'layout'             => Schema::str( __( 'Content layout.', 'mosmcp-abilities' ), array( 'enum' => self::SETTINGS['layout'][1] ) ),
						'content_style'      => Schema::str( __( 'Boxed or unboxed content.', 'mosmcp-abilities' ), array( 'enum' => self::SETTINGS['content_style'][1] ) ),
						'vertical_padding'   => Schema::str( __( 'Content vertical padding.', 'mosmcp-abilities' ), array( 'enum' => self::SETTINGS['vertical_padding'][1] ) ),
						'title'              => Schema::str( __( 'Show or hide the page title.', 'mosmcp-abilities' ), array( 'enum' => self::SETTINGS['title'][1] ) ),
						'transparent_header' => Schema::str( __( 'Transparent header.', 'mosmcp-abilities' ), array( 'enum' => self::SETTINGS['transparent_header'][1] ) ),
						'header'             => Schema::str( __( 'Show or hide the header.', 'mosmcp-abilities' ), array( 'enum' => self::SETTINGS['header'][1] ) ),
						'footer'             => Schema::str( __( 'Show or hide the footer.', 'mosmcp-abilities' ), array( 'enum' => self::SETTINGS['footer'][1] ) ),
						'sidebar_id'         => Schema::str( __( 'Sidebar identifier to use.', 'mosmcp-abilities' ) ),
						'css_class'          => Schema::str( __( 'Custom CSS class for the page wrapper.', 'mosmcp-abilities' ) ),
					),
					array( 'id' )
				),
				'output_schema'       => Schema::object(
					array(
						'id'       => Schema::int(),
						'updated'  => array( 'type' => 'object' ),
						'settings' => array( 'type' => 'object' ),
					),
					array( 'id', 'updated' )
				),
				'execute_callback'    => array( __CLASS__, 'execute_layout_set' ),
				'permission_callback' => Kadence_Helpers::can_edit_post(),
			),
			Kadence_Helpers::ann( false, false, true, true )
		);
	}

	/**
	 * Executes mosmcp/kadence-page-layout-get.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function execute_layout_get( $input = array() ) {
		$post = Kadence_Blocks_Helper::require_post( $input );
		if ( $post instanceof WP_Error ) {
			return $post;
		}

		return array(
			'id'       => (int) $post->ID,
			'settings' => self::read_settings( (int) $post->ID ),
			'raw_meta' => self::read_raw_meta( (int) $post->ID ),
		);
	}

	/**
	 * Executes mosmcp/kadence-page-layout-set.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function execute_layout_set( $input = array() ) {
		$post = Kadence_Blocks_Helper::require_post( $input );
		if ( $post instanceof WP_Error ) {
			return $post;
		}

		$updated = array();

		foreach ( self::SETTINGS as $name => $config ) {
			if ( ! array_key_exists( $name, $input ) ) {
				continue;
			}

			list( $meta_key, $allowed ) = $config;
			$value                      = (string) $input[ $name ];

			if ( ! empty( $allowed ) ) {
				$value = sanitize_key( $value );
				if ( ! in_array( $value, $allowed, true ) ) {
					return Kadence_Write_Engine::error(
						'validation_failed',
						sprintf(
							/* translators: 1: setting name, 2: allowed values. */
							__( 'Invalid value for "%1$s". Allowed: %2$s.', 'mosmcp-abilities' ),
							$name,
							implode( ', ', $allowed )
						)
					);
				}
			} elseif ( 'css_class' === $name ) {
				$value = sanitize_html_class( $value );
			} else {
				$value = sanitize_text_field( $value );
			}

			update_post_meta( (int) $post->ID, $meta_key, $value );
			$updated[ $name ] = $value;
		}

		if ( empty( $updated ) ) {
			return Kadence_Write_Engine::error(
				'validation_failed',
				__( 'No layout settings were supplied to change.', 'mosmcp-abilities' )
			);
		}

		return array(
			'id'       => (int) $post->ID,
			'updated'  => $updated,
			'settings' => self::read_settings( (int) $post->ID ),
		);
	}

	/**
	 * Reads the friendly-named settings from post meta.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, string>
	 */
	private static function read_settings( $post_id ) {
		$settings = array();
		foreach ( self::SETTINGS as $name => $config ) {
			$settings[ $name ] = (string) get_post_meta( $post_id, $config[0], true );
		}
		return $settings;
	}

	/**
	 * Reads every _kad_post_* meta value present on the post (self-documenting).
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed>
	 */
	private static function read_raw_meta( $post_id ) {
		$all = get_post_meta( $post_id );
		$out = array();

		if ( is_array( $all ) ) {
			foreach ( $all as $key => $values ) {
				if ( 0 === strpos( (string) $key, '_kad_post_' ) ) {
					$out[ $key ] = is_array( $values ) && 1 === count( $values ) ? $values[0] : $values;
				}
			}
		}

		return $out;
	}
}
