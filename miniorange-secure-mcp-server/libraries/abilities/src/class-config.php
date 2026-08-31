<?php
/**
 * Runtime configuration for the abilities library.
 *
 * The host plugin sets this once via {@see Abilities_Library::init()}. Everything
 * the library needs to adapt to its host (the ability-name prefix, the version
 * surfaced in developer notices, and an optional pack allow-list) lives here.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Config
 */
class Config {

	/**
	 * The prefix every ability name and category slug is authored under in this
	 * library's source. At registration each authored name is re-prefixed to the
	 * host's configured prefix; if the host keeps "mosmcp" it is a no-op.
	 */
	const AUTHORING_PREFIX = 'mosmcp';

	/**
	 * The text domain every translatable string is authored under in this
	 * library's source.
	 *
	 * WordPress i18n requires a string literal as the domain argument (it cannot
	 * be a variable, or the string is invisible to translation tooling), so the
	 * source is fixed to this one domain. Hosts that want the library's strings
	 * translated under their own domain configure {@see self::text_domain()};
	 * {@see Abilities_Library::init()} then reroutes lookups at runtime.
	 */
	const SOURCE_TEXT_DOMAIN = 'mosmcp-abilities';

	/**
	 * Host prefix for ability names and category slugs (no slashes).
	 *
	 * @var string
	 */
	private static $prefix = self::AUTHORING_PREFIX;

	/**
	 * Text domain the host wants the library's strings translated under.
	 *
	 * Defaults to the source domain (no remap). When the host sets a different
	 * domain, its own .po/.mo files translate the library's strings.
	 *
	 * @var string
	 */
	private static $text_domain = self::SOURCE_TEXT_DOMAIN;

	/**
	 * Version surfaced in _doing_it_wrong() developer notices.
	 *
	 * @var string
	 */
	private static $version = '1.0.0';

	/**
	 * Optional allow-list of pack groups to register; null means all.
	 *
	 * @var string[]|null
	 */
	private static $packs = null;

	/**
	 * Applies host configuration. Unknown keys are ignored; missing keys keep
	 * their defaults.
	 *
	 * @param array<string, mixed> $config Host configuration.
	 * @return void
	 */
	public static function set( array $config ) {
		if ( isset( $config['prefix'] ) && '' !== (string) $config['prefix'] ) {
			self::$prefix = trim( (string) $config['prefix'], "/ \t\n\r" );
		}
		if ( isset( $config['version'] ) && '' !== (string) $config['version'] ) {
			self::$version = (string) $config['version'];
		}
		if ( isset( $config['text_domain'] ) && '' !== (string) $config['text_domain'] ) {
			self::$text_domain = (string) $config['text_domain'];
		}
		if ( array_key_exists( 'packs', $config ) ) {
			self::$packs = is_array( $config['packs'] ) ? array_map( 'strval', $config['packs'] ) : null;
		}
	}

	/**
	 * The host ability/category prefix.
	 *
	 * @return string
	 */
	public static function prefix() {
		return self::$prefix;
	}

	/**
	 * The configured version string.
	 *
	 * @return string
	 */
	public static function version() {
		return self::$version;
	}

	/**
	 * The pack allow-list, or null for all packs.
	 *
	 * @return string[]|null
	 */
	public static function packs() {
		return self::$packs;
	}

	/**
	 * The text domain the host wants the library's strings translated under.
	 *
	 * @return string
	 */
	public static function text_domain() {
		return self::$text_domain;
	}

	/**
	 * Whether the host configured a text domain different from the source one,
	 * i.e. whether a runtime translation remap is needed.
	 *
	 * @return bool
	 */
	public static function needs_text_domain_remap() {
		return self::SOURCE_TEXT_DOMAIN !== self::$text_domain;
	}
}
