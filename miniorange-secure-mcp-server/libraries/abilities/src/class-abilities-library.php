<?php
/**
 * Public entry point for the abilities library.
 *
 * A host plugin calls {@see Abilities_Library::init()} once (typically from its
 * own hook bootstrap). That stores the host configuration and wires every pack
 * group to the WordPress Abilities API init hooks. Each pack group is still
 * dependency-gated internally, so groups whose plugin is inactive register
 * nothing.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities;

use MoSMCP\Abilities\Packs\Woocommerce\WooCommerce_Abilities_Loader;
use MoSMCP\Abilities\Packs\Acf\ACFA_Helpers;
use MoSMCP\Abilities\Packs\Yoast\YSOA_Helpers;
use MoSMCP\Abilities\Packs\Forms\Forms_Abilities;
use MoSMCP\Abilities\Packs\Kadence\Kadence_Helpers;
use MoSMCP\Abilities\Packs\Rankmath\Rankmath_Loader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Abilities_Library
 */
class Abilities_Library {

	/**
	 * Cached library version read from the VERSION file.
	 *
	 * @var string|null
	 */
	private static $version = null;

	/**
	 * Guards against double initialization.
	 *
	 * @var bool
	 */
	private static $booted = false;

	/**
	 * Initializes the library and registers its Abilities API hooks.
	 *
	 * @param array<string, mixed> $config {
	 *     Host configuration.
	 *
	 *     @type string        $text_domain Required. The host plugin's text domain. The library's strings
	 *                                      are re-routed to resolve against your .po/.mo files, so your
	 *                                      plugin owns their translations. Must be a non-empty string.
	 *     @type string        $prefix      Ability-name and category-slug prefix (no slash). Default "mosmcp".
	 *     @type string        $version     Version surfaced in developer notices. Default read from the VERSION file.
	 *     @type string[]|null $packs       Allow-list of pack groups (core, woocommerce, acf, yoast, forms, kadence). Null = all.
	 * }
	 * @return void
	 * @throws \InvalidArgumentException When the required 'text_domain' is missing or empty.
	 */
	public static function init( array $config = array() ) {
		if ( self::$booted ) {
			return;
		}

		if ( ! isset( $config['text_domain'] ) || '' === trim( (string) $config['text_domain'] ) ) {
			throw new \InvalidArgumentException(
				esc_html( 'Abilities_Library::init() requires a non-empty "text_domain" so the host plugin owns the library\'s translations.' )
			);
		}

		if ( empty( $config['version'] ) ) {
			$config['version'] = self::version();
		}
		Config::set( $config );

		if ( Config::needs_text_domain_remap() ) {
			add_filter( 'gettext', array( __CLASS__, 'remap_text_domain' ), 10, 3 );
		}

		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_categories' ) );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ) );

		self::$booted = true;
	}

	/**
	 * Returns the library version, read once from the VERSION file.
	 *
	 * The VERSION file is the single source of truth for the library version.
	 * Falls back to '0.0.0' if the file cannot be read.
	 *
	 * @return string
	 */
	public static function version() {
		if ( null === self::$version ) {
			$version_file  = dirname( __DIR__ ) . '/VERSION';
			$version       = is_readable( $version_file )
				? trim( (string) file_get_contents( $version_file ) )
				: '';
			self::$version = '' !== $version ? $version : '0.0.0';
		}

		return self::$version;
	}

	/**
	 * Reroutes the library's strings to the host-configured text domain.
	 *
	 * The library authors every string under one fixed literal domain because
	 * WordPress i18n forbids a variable domain argument. When the host asks for a
	 * different domain, this filter catches lookups against the source domain and
	 * re-translates the same string under the host's domain, so the host's own
	 * .po/.mo files apply. Lookups for any other domain pass straight through.
	 *
	 * Hooked to gettext only when a remap is actually configured.
	 *
	 * @param string $translation Translated text (or the original if untranslated).
	 * @param string $text        The original, untranslated text.
	 * @param string $domain      The text domain the lookup was made under.
	 * @return string
	 */
	public static function remap_text_domain( $translation, $text, $domain ) {
		if ( Config::SOURCE_TEXT_DOMAIN !== $domain ) {
			return $translation;
		}
		// translate() re-fires 'gettext' under the host domain, which this filter
		// ignores (domain no longer matches), so there is no recursion.
		return translate( $text, Config::text_domain() ); // phpcs:ignore WordPress.WP.I18n.LowLevelTranslationFunction,WordPress.WP.I18n.NonSingularStringLiteralDomain,WordPress.WP.I18n.NonSingularStringLiteralText -- Intentional runtime domain remap; translate() is the correct low-level call and $text is already a literal from the caller.
	}

	/**
	 * Registers the categories for every enabled pack group.
	 *
	 * Hooked to wp_abilities_api_categories_init.
	 *
	 * @return void
	 */
	public static function register_categories() {
		if ( self::enabled( 'core' ) ) {
			Pack_Registry::register_categories();
		}
		if ( self::enabled( 'woocommerce' ) ) {
			WooCommerce_Abilities_Loader::register_categories();
		}
		if ( self::enabled( 'acf' ) ) {
			ACFA_Helpers::register_categories();
		}
		if ( self::enabled( 'yoast' ) ) {
			YSOA_Helpers::register_categories();
		}
		if ( self::enabled( 'forms' ) ) {
			Forms_Abilities::register_categories();
		}
		if ( self::enabled( 'kadence' ) ) {
			Kadence_Helpers::register_categories();
		}
		if ( self::enabled( 'rankmath' ) ) {
			Rankmath_Loader::register_categories();
		}
	}

	/**
	 * Registers the abilities for every enabled pack group.
	 *
	 * Hooked to wp_abilities_api_init.
	 *
	 * @return void
	 */
	public static function register_abilities() {
		if ( self::enabled( 'core' ) ) {
			Pack_Registry::register_abilities();
		}
		if ( self::enabled( 'woocommerce' ) ) {
			WooCommerce_Abilities_Loader::register_abilities();
		}
		if ( self::enabled( 'acf' ) ) {
			ACFA_Helpers::register_abilities();
		}
		if ( self::enabled( 'yoast' ) ) {
			YSOA_Helpers::register_abilities();
		}
		if ( self::enabled( 'forms' ) ) {
			Forms_Abilities::register_abilities();
		}
		if ( self::enabled( 'kadence' ) ) {
			Kadence_Helpers::register_abilities();
		}
		if ( self::enabled( 'rankmath' ) ) {
			Rankmath_Loader::register_abilities();
		}
	}

	/**
	 * Whether a pack group is enabled by the host's allow-list.
	 *
	 * The framework packs (core content, users & roles, comments, CPT scaffold)
	 * register under the "core" group; the four vendor packs each have their own.
	 *
	 * @param string $group Pack group key.
	 * @return bool
	 */
	private static function enabled( $group ) {
		$packs = Config::packs();
		return null === $packs || in_array( $group, $packs, true );
	}
}
