<?php
/**
 * Which SEO plugin's reader applies on this site.
 *
 * The free library reads Rank Math and Yoast SEO; a host can add readers for
 * other SEO plugins through the {prefix}_seo_readers filter, which is how the
 * paid plans add All in One SEO and SEOPress. With none of them active, the
 * WordPress reader reports what WordPress itself outputs.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Seo;

use MoSMCP\Abilities\Config;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Seo_Readers
 */
class Seo_Readers {

	/**
	 * Every known reader, in order of preference.
	 *
	 * @return Seo_Reader[]
	 */
	public static function all() {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Prefixed via Config::prefix(); the library's prefix is host-configurable and cannot be a literal.
		$readers = apply_filters( Config::prefix() . '_seo_readers', array( new Seo_Rank_Math_Reader(), new Seo_Yoast_Reader() ) );

		return array_values(
			array_filter(
				(array) $readers,
				static function ( $reader ) {
					return $reader instanceof Seo_Reader;
				}
			)
		);
	}

	/**
	 * The readers whose plugin is active.
	 *
	 * @return Seo_Reader[]
	 */
	public static function active() {
		return array_values(
			array_filter(
				self::all(),
				static function ( Seo_Reader $reader ) {
					return $reader->is_active();
				}
			)
		);
	}

	/**
	 * The reader to use: the first active SEO plugin, else WordPress's own output.
	 *
	 * @return Seo_Reader
	 */
	public static function pick() {
		$active = self::active();

		return $active ? $active[0] : new Seo_Core_Reader();
	}

	/**
	 * The names of the SEO plugins this site can read, for descriptions.
	 *
	 * @return string[]
	 */
	public static function names() {
		return array_map(
			static function ( Seo_Reader $reader ) {
				return $reader->name();
			},
			self::all()
		);
	}
}
