<?php
/**
 * Rank Math's per-post SEO data.
 *
 * What search engines get comes from Rank Math's own Singular paper
 * (get_seo_meta(), Rank Math 1.0.272 and later), the same code its front end and
 * its own read ability use, so templates, the excerpt fallback and the effective
 * robots rules are Rank Math's and not a copy of them. Rank Math's own ability is
 * not called, because it requires edit rights on the post.
 *
 * Rank Math loads nothing, front end included, until the site is connected to a
 * Rank Math account or its setup is skipped. Until then search engines get
 * WordPress's own title, and that is what is reported, with a note saying why.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Seo;

use MoSMCP\Abilities\Packs\Rankmath\Rankmath_Support;
use WP_Post;

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
 * Class Seo_Rank_Math_Reader
 */
class Seo_Rank_Math_Reader extends Seo_Reader {

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function slug() {
		return 'rank-math';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function name() {
		return 'Rank Math';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return bool
	 */
	public function is_active() {
		return defined( 'RANK_MATH_VERSION' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function version() {
		return defined( 'RANK_MATH_VERSION' ) ? (string) RANK_MATH_VERSION : '';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param WP_Post $post Post.
	 * @return array<string, mixed>
	 */
	public function read( WP_Post $post ) {
		$out = self::blank();
		$id  = (int) $post->ID;

		$out['title_saved']       = self::plain( get_post_meta( $id, 'rank_math_title', true ) );
		$out['description_saved'] = self::plain( get_post_meta( $id, 'rank_math_description', true ) );
		$out['focus_keywords']    = self::keyword_list( get_post_meta( $id, 'rank_math_focus_keyword', true ) );
		$out['canonical_saved']   = (string) get_post_meta( $id, 'rank_math_canonical_url', true );
		$out['og_title']          = self::plain( get_post_meta( $id, 'rank_math_facebook_title', true ) );
		$out['og_description']    = self::plain( get_post_meta( $id, 'rank_math_facebook_description', true ) );
		$out['og_image']          = (string) get_post_meta( $id, 'rank_math_facebook_image', true );
		$separate_twitter         = 'off' === get_post_meta( $id, 'rank_math_twitter_use_facebook', true );
		$out['twitter_image']     = $separate_twitter ? (string) get_post_meta( $id, 'rank_math_twitter_image', true ) : $out['og_image'];

		if ( ! Rankmath_Support::can_render() ) {
			// Rank Math is installed but not set up, so it outputs nothing yet.
			$core = ( new Seo_Core_Reader() )->read( $post );
			foreach ( array( 'title', 'description', 'canonical', 'noindex', 'nofollow' ) as $key ) {
				$out[ $key ] = $core[ $key ];
			}
			$out['notes'][] = __( 'Rank Math is installed but not set up on this site (it is not connected to a Rank Math account and its setup was not skipped), so it outputs nothing yet and search engines get WordPress\'s own title. The values saved in Rank Math are shown as saved, and will apply once Rank Math is set up.', 'mosmcp-abilities' );
			return $out;
		}

		$meta = $this->seo_meta( $post );

		if ( is_array( $meta ) ) {
			$robots                     = isset( $meta['robots'] ) ? array_map( 'strval', (array) $meta['robots'] ) : array();
			$out['title']               = self::plain( isset( $meta['title'] ) ? $meta['title'] : '' );
			$out['description']         = self::plain( isset( $meta['description'] ) ? $meta['description'] : '' );
			$out['canonical']           = isset( $meta['canonical'] ) ? (string) $meta['canonical'] : '';
			$out['noindex']             = in_array( 'noindex', $robots, true );
			$out['nofollow']            = in_array( 'nofollow', $robots, true );
			$out['og_title']            = self::plain( isset( $meta['og_title'] ) ? $meta['og_title'] : '' );
			$out['og_description']      = self::plain( isset( $meta['og_description'] ) ? $meta['og_description'] : '' );
			$out['twitter_title']       = self::plain( isset( $meta['twitter_title'] ) ? $meta['twitter_title'] : '' );
			$out['twitter_description'] = self::plain( isset( $meta['twitter_description'] ) ? $meta['twitter_description'] : '' );
			return $out;
		}

		// A Rank Math older than 1.0.272: its templates, resolved by its own engine.
		$robots             = $this->robots( $post );
		$out['title']       = '' !== $out['title_saved'] ? $out['title_saved'] : self::plain( Rankmath_Support::render( $this->template( $post, 'title', '%title% %sep% %sitename%' ), $post ) );
		$out['description'] = '' !== $out['description_saved'] ? $out['description_saved'] : self::plain( '' !== $post->post_excerpt ? $post->post_excerpt : Rankmath_Support::render( $this->template( $post, 'description', '' ), $post ) );
		$out['canonical']   = '' !== $out['canonical_saved'] ? $out['canonical_saved'] : (string) get_permalink( $post );
		$out['noindex']     = in_array( 'noindex', $robots, true ) || 'private' === $post->post_status || self::site_hidden();
		$out['nofollow']    = in_array( 'nofollow', $robots, true );

		return $out;
	}

	/**
	 * Rank Math's own computation of what search engines get, or null.
	 *
	 * @param WP_Post $post Post.
	 * @return array<string, mixed>|null
	 */
	private function seo_meta( WP_Post $post ) {
		if ( ! class_exists( '\RankMath\Paper\Singular' ) || ! method_exists( '\RankMath\Paper\Singular', 'get_seo_meta' ) ) {
			return null;
		}

		try {
			$paper = new \RankMath\Paper\Singular();
			$meta  = $paper->get_seo_meta( (int) $post->ID );
		} catch ( \Throwable $e ) {
			return null;
		}

		return is_array( $meta ) && ! isset( $meta['error'] ) ? $meta : null;
	}

	/**
	 * A post type's title or description template from Rank Math's settings.
	 *
	 * @param WP_Post $post     Post.
	 * @param string  $field    'title' or 'description'.
	 * @param string  $fallback Rank Math's own default.
	 * @return string
	 */
	private function template( WP_Post $post, $field, $fallback ) {
		$value = \RankMath\Helper::get_settings( 'titles.pt_' . $post->post_type . '_' . $field );

		return is_string( $value ) && '' !== $value ? $value : $fallback;
	}

	/**
	 * The robots values that apply to a post: its own, its type's, or the site's.
	 *
	 * @param WP_Post $post Post.
	 * @return string[]
	 */
	private function robots( WP_Post $post ) {
		$robots = get_post_meta( (int) $post->ID, 'rank_math_robots', true );

		if ( empty( $robots ) ) {
			$robots = \RankMath\Helper::get_settings( 'titles.pt_' . $post->post_type . '_custom_robots' )
				? \RankMath\Helper::get_settings( 'titles.pt_' . $post->post_type . '_robots' )
				: \RankMath\Helper::get_settings( 'titles.robots_global' );
		}

		return array_map( 'strval', (array) $robots );
	}
}
