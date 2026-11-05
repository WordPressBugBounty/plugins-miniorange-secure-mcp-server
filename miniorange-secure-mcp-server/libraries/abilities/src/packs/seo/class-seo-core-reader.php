<?php
/**
 * What WordPress itself gives search engines when no SEO plugin is active.
 *
 * WordPress prints a document title and, for public sites, a canonical link and
 * nothing else: no meta description, no focus keyword, no social tags.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Seo;

use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Seo_Core_Reader
 */
class Seo_Core_Reader extends Seo_Reader {

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function slug() {
		return 'none';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function name() {
		return 'WordPress (no SEO plugin)';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return bool
	 */
	public function is_active() {
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return bool
	 */
	public function has_focus_keywords() {
		return false;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param WP_Post $post Post.
	 * @return array<string, mixed>
	 */
	public function read( WP_Post $post ) {
		$out = self::blank();

		// The same parts and filters wp_get_document_title() uses for a single post.
		$parts = apply_filters(
			'document_title_parts', // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter.
			array(
				'title' => get_the_title( $post ),
				'site'  => get_bloginfo( 'name', 'display' ),
			)
		);
		$sep   = apply_filters( 'document_title_separator', '-' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter.

		$out['title']     = self::plain( wptexturize( implode( ' ' . $sep . ' ', array_filter( (array) $parts ) ) ) );
		$out['canonical'] = 'publish' === $post->post_status ? (string) wp_get_canonical_url( $post ) : (string) get_permalink( $post );
		$out['noindex']   = self::site_hidden();

		return $out;
	}
}
