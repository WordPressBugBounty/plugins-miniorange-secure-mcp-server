<?php
/**
 * Checks that the theme actually shows a menu location or widget area that was
 * just filled.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Theming;

use MoSMCP\Abilities\Config;

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
 * Class Display_Check
 *
 * A theme registers a menu location or widget area, but whether it prints it is
 * up to its layout: Astra's footer menu shows only once its footer builder holds
 * the menu element, and many sidebars appear only on layouts that have one. So
 * "assigned" is not "visible", and the commonest report after such a change is
 * "it didn't work".
 *
 * This loads a few real pages, signed in (so page caches and maintenance screens
 * are out of the way), with a one-time token. For that request only, the page
 * records which menu locations wp_nav_menu() was asked for and which areas
 * dynamic_sidebar() printed, and adds the record as an HTML comment. Reading the
 * record, not the markup, keeps the answer exact for every theme: a menu that
 * also sits in another location cannot make this one look visible.
 */
class Display_Check {

	/**
	 * Seconds each page may take.
	 */
	const TIMEOUT = 20;

	/**
	 * Query argument carrying the one-time token.
	 */
	const PARAM = 'mosmcp_display_check';

	/**
	 * Start of the comment holding the record.
	 */
	const MARK = 'mosmcp-rendered:';

	/**
	 * What this page rendered.
	 *
	 * @var array{locations:string[],sidebars:string[]}
	 */
	private static $seen = array(
		'locations' => array(),
		'sidebars'  => array(),
	);

	/**
	 * Listens for a check. Inert unless the request carries the query argument.
	 *
	 * @return void
	 */
	public static function boot() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only detects the argument; listen() checks the token.
		if ( isset( $_GET[ self::PARAM ] ) ) {
			add_action( 'init', array( __CLASS__, 'listen' ) );
		}
	}

	/**
	 * Starts recording, for a valid token and a person who may see the record.
	 *
	 * @return void
	 */
	public static function listen() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The one-time token, stored server-side for this check, is the credential.
		$token = isset( $_GET[ self::PARAM ] ) ? sanitize_key( wp_unslash( $_GET[ self::PARAM ] ) ) : '';
		if ( '' === $token || ! get_transient( self::key( $token ) ) || ! current_user_can( 'edit_theme_options' ) ) {
			return;
		}
		add_filter( 'wp_nav_menu_args', array( __CLASS__, 'saw_menu' ), PHP_INT_MAX );
		add_action( 'dynamic_sidebar_before', array( __CLASS__, 'saw_area' ) );
		add_action( 'shutdown', array( __CLASS__, 'report' ), 0 );
	}

	/**
	 * Records a menu location the page asked for.
	 *
	 * @param array<string, mixed> $args wp_nav_menu() arguments.
	 * @return array<string, mixed>
	 */
	public static function saw_menu( $args ) {
		if ( is_array( $args ) && ! empty( $args['theme_location'] ) ) {
			self::$seen['locations'][] = (string) $args['theme_location'];
		}
		return $args;
	}

	/**
	 * Records a widget area the page printed.
	 *
	 * @param int|string $index Area ID.
	 * @return void
	 */
	public static function saw_area( $index ) {
		self::$seen['sidebars'][] = (string) $index;
	}

	/**
	 * Adds the record to the page.
	 *
	 * @return void
	 */
	public static function report() {
		$record = wp_json_encode(
			array(
				'locations' => array_values( array_unique( self::$seen['locations'] ) ),
				'sidebars'  => array_values( array_unique( self::$seen['sidebars'] ) ),
			)
		);
		echo "\n<!-- " . esc_html( self::MARK ) . $record . " -->\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON of location and area IDs inside an HTML comment, read by this library's own check.
	}

	/**
	 * A note when none of the checked pages prints a menu location.
	 *
	 * @param string $location Location slug.
	 * @param string $label    Location label.
	 * @return string Empty when a page prints it, or when it cannot be checked.
	 */
	public static function menu_note( $location, $label ) {
		$checked = array();
		if ( false !== self::rendered( 'locations', (string) $location, $checked ) ) {
			return '';
		}
		return sprintf(
			/* translators: 1: pages checked, 2: menu location. */
			__( 'Checked %1$s: none of them prints the "%2$s" location, so the menu will not appear there yet. Many themes show a location only when their header or footer layout includes it (Astra\'s footer builder, for one). Look for that setting with mosmcp/theme-settings-list, searching for the location\'s name, "header" or "footer".', 'mosmcp-abilities' ),
			wp_sprintf( '%l', $checked ),
			$label
		);
	}

	/**
	 * A note when none of the checked pages prints a widget area.
	 *
	 * @param string $area  Area ID.
	 * @param string $label Area name.
	 * @return string Empty when a page prints it, or when it cannot be checked.
	 */
	public static function widget_note( $area, $label ) {
		$checked = array();
		if ( false !== self::rendered( 'sidebars', (string) $area, $checked ) ) {
			return '';
		}
		return sprintf(
			/* translators: 1: pages checked, 2: widget area. */
			__( 'Checked %1$s: none of them prints the "%2$s" area, so the widget will not appear there yet. Themes often show a sidebar only on layouts that have one, or a footer widget area only when the footer layout includes it. Look for that setting with mosmcp/theme-settings-list, searching for "sidebar", "footer" or the area\'s name.', 'mosmcp-abilities' ),
			wp_sprintf( '%l', $checked ),
			$label
		);
	}

	/**
	 * Whether any checked page rendered a location or area.
	 *
	 * @param string   $kind    "locations" or "sidebars".
	 * @param string   $value   Location slug or area ID.
	 * @param string[] $checked Filled with the names of the pages that reported.
	 * @return bool|null Null when no page could be checked.
	 */
	private static function rendered( $kind, $value, array &$checked ) {
		if ( true !== Theme_Support::loopback() ) {
			return null;
		}
		$session = Loopback_Session::start( 2 * MINUTE_IN_SECONDS );
		if ( null === $session ) {
			return null;
		}

		$token = strtolower( wp_generate_password( 24, false, false ) );
		set_transient( self::key( $token ), 1, 2 * MINUTE_IN_SECONDS );

		$found = false;
		try {
			foreach ( self::pages() as $label => $url ) {
				$response = wp_remote_get(
					add_query_arg( self::PARAM, $token, $url ),
					array(
						'timeout'     => self::TIMEOUT,
						'redirection' => 0,
						'cookies'     => $session->cookies( $url ),
						// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter.
						'sslverify'   => apply_filters( 'https_local_ssl_verify', false ),
						'headers'     => array( 'Cache-Control' => 'no-cache' ),
					)
				);
				$body     = is_wp_error( $response ) ? '' : (string) wp_remote_retrieve_body( $response );
				if ( ! preg_match( '/<!-- ' . preg_quote( self::MARK, '/' ) . '(\{.*?\}) -->/s', $body, $match ) ) {
					continue;
				}
				$checked[] = $label;
				$seen      = json_decode( $match[1], true );
				if ( is_array( $seen ) && in_array( $value, isset( $seen[ $kind ] ) ? (array) $seen[ $kind ] : array(), true ) ) {
					$found = true;
					break;
				}
			}
		} finally {
			delete_transient( self::key( $token ) );
			$session->end();
		}

		return empty( $checked ) ? null : $found;
	}

	/**
	 * The pages to look at: the home page and the newest post and page, when their
	 * address is on this site (see Loopback_Session::is_own_url()).
	 *
	 * @return array<string, string> Name => URL.
	 */
	private static function pages() {
		$pages = array( __( 'the home page', 'mosmcp-abilities' ) => home_url( '/' ) );
		foreach ( array(
			'post' => __( 'the newest post', 'mosmcp-abilities' ),
			'page' => __( 'the newest page', 'mosmcp-abilities' ),
		) as $type => $label ) {
			$ids = get_posts(
				array(
					'post_type'    => $type,
					'post_status'  => 'publish',
					'has_password' => false,
					'numberposts'  => 1,
					'fields'       => 'ids',
				)
			);
			$url = empty( $ids ) ? '' : (string) get_permalink( (int) $ids[0] );
			if ( '' !== $url && untrailingslashit( $url ) !== untrailingslashit( home_url( '/' ) ) && Loopback_Session::is_own_url( $url ) ) {
				$pages[ $label ] = $url;
			}
		}
		return $pages;
	}

	/**
	 * Transient marking a token valid.
	 *
	 * @param string $token Token.
	 * @return string
	 */
	private static function key( $token ) {
		return Config::prefix() . '_dc_' . md5( (string) $token );
	}
}
