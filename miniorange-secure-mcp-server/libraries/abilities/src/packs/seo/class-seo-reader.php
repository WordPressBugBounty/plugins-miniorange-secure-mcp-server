<?php
/**
 * One SEO plugin's per-post data, read the way that plugin outputs it.
 *
 * Each SEO plugin stores its fields under its own keys and fills empty ones from
 * its own site-wide templates, so a reader exists per plugin. Every reader returns
 * the same shape: what the author saved, and what search engines are actually
 * given once the plugin's templates and defaults are applied. Readers only read;
 * nothing here writes or calls a plugin ability that requires edit rights.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Seo;

use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Seo_Reader
 */
abstract class Seo_Reader {

	/**
	 * Stable slug reported to callers, such as "rank-math".
	 *
	 * @return string
	 */
	abstract public function slug();

	/**
	 * Display name, such as "Rank Math".
	 *
	 * @return string
	 */
	abstract public function name();

	/**
	 * Whether the plugin is active in this request.
	 *
	 * @return bool
	 */
	abstract public function is_active();

	/**
	 * Reads one post.
	 *
	 * @param WP_Post $post Post.
	 * @return array<string, mixed> The shape of {@see Seo_Reader::blank()}.
	 */
	abstract public function read( WP_Post $post );

	/**
	 * The plugin's version, when it publishes one.
	 *
	 * @return string
	 */
	public function version() {
		return '';
	}

	/**
	 * Whether this plugin has a focus keyword at all, so its absence is an issue.
	 *
	 * @return bool
	 */
	public function has_focus_keywords() {
		return true;
	}

	/**
	 * Every field a reader returns, empty.
	 *
	 * @return array<string, mixed>
	 */
	public static function blank() {
		return array(
			'title_saved'         => '',
			'title'               => '',
			'description_saved'   => '',
			'description'         => '',
			'focus_keywords'      => array(),
			'canonical_saved'     => '',
			'canonical'           => '',
			'noindex'             => false,
			'nofollow'            => false,
			'og_title'            => '',
			'og_description'      => '',
			'og_image'            => '',
			'twitter_title'       => '',
			'twitter_description' => '',
			'twitter_image'       => '',
			'notes'               => array(),
		);
	}

	/**
	 * Text as a reader shows it: no tags, entities decoded, spacing collapsed.
	 *
	 * @param mixed $value Stored or rendered value.
	 * @return string
	 */
	public static function plain( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$text = html_entity_decode( wp_strip_all_tags( (string) $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * A comma-separated keyword list as an array, first keyword first.
	 *
	 * @param mixed $value Stored value.
	 * @return string[]
	 */
	public static function keyword_list( $value ) {
		if ( ! is_scalar( $value ) ) {
			return array();
		}

		return array_values( array_filter( array_map( array( __CLASS__, 'plain' ), explode( ',', (string) $value ) ), 'strlen' ) );
	}

	/**
	 * Whether WordPress itself asks search engines not to index the site.
	 *
	 * @return bool
	 */
	public static function site_hidden() {
		return '0' === (string) get_option( 'blog_public' );
	}
}
