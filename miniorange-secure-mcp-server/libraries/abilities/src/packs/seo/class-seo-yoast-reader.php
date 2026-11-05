<?php
/**
 * Yoast SEO's per-post SEO data.
 *
 * What search engines get comes from Yoast's meta surface for the post, which
 * runs the same presenters as the page head, so templates, replacement variables
 * and robots defaults are Yoast's own. If the surface cannot build the post's
 * data, Yoast's templates are resolved with its own replacement function instead.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Seo;

use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Seo_Yoast_Reader
 */
class Seo_Yoast_Reader extends Seo_Reader {

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function slug() {
		return 'yoast';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function name() {
		return 'Yoast SEO';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return bool
	 */
	public function is_active() {
		return class_exists( 'WPSEO_Meta' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function version() {
		return defined( 'WPSEO_VERSION' ) ? (string) WPSEO_VERSION : '';
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

		$out['title_saved']         = self::plain( \WPSEO_Meta::get_value( 'title', $id ) );
		$out['description_saved']   = self::plain( \WPSEO_Meta::get_value( 'metadesc', $id ) );
		$out['canonical_saved']     = (string) \WPSEO_Meta::get_value( 'canonical', $id );
		$out['focus_keywords']      = $this->keywords( $id );
		$out['og_image']            = (string) \WPSEO_Meta::get_value( 'opengraph-image', $id );
		$out['twitter_image']       = (string) \WPSEO_Meta::get_value( 'twitter-image', $id );
		$out['og_title']            = self::plain( \WPSEO_Meta::get_value( 'opengraph-title', $id ) );
		$out['og_description']      = self::plain( \WPSEO_Meta::get_value( 'opengraph-description', $id ) );
		$out['twitter_title']       = self::plain( \WPSEO_Meta::get_value( 'twitter-title', $id ) );
		$out['twitter_description'] = self::plain( \WPSEO_Meta::get_value( 'twitter-description', $id ) );

		$meta = $this->surface( $id );

		if ( $meta ) {
			$robots             = $this->field( $meta, 'robots' );
			$robots             = is_array( $robots ) ? array_map( 'strval', $robots ) : array_map( 'trim', explode( ',', (string) $robots ) );
			$out['title']       = self::plain( $this->field( $meta, 'title' ) );
			$out['description'] = self::plain( $this->field( $meta, 'meta_description' ) );
			$out['canonical']   = (string) $this->field( $meta, 'canonical' );
			$out['noindex']     = in_array( 'noindex', $robots, true );
			$out['nofollow']    = in_array( 'nofollow', $robots, true );

			foreach ( array(
				'og_title'            => 'open_graph_title',
				'og_description'      => 'open_graph_description',
				'twitter_title'       => 'twitter_title',
				'twitter_description' => 'twitter_description',
			) as $key => $field ) {
				$value = self::plain( $this->field( $meta, $field ) );
				if ( '' !== $value ) {
					$out[ $key ] = $value;
				}
			}

			return $out;
		}

		$type               = (string) $post->post_type;
		$noindex            = (string) \WPSEO_Meta::get_value( 'meta-robots-noindex', $id );
		$out['title']       = self::plain( $this->replace( '' !== $out['title_saved'] ? $out['title_saved'] : $this->option( 'title-' . $type ), $post ) );
		$out['description'] = self::plain( $this->replace( '' !== $out['description_saved'] ? $out['description_saved'] : $this->option( 'metadesc-' . $type ), $post ) );
		$out['canonical']   = '' !== $out['canonical_saved'] ? $out['canonical_saved'] : (string) get_permalink( $post );
		$out['noindex']     = '1' === $noindex || ( '2' !== $noindex && (bool) $this->option( 'noindex-' . $type ) ) || self::site_hidden();
		$out['nofollow']    = '1' === (string) \WPSEO_Meta::get_value( 'meta-robots-nofollow', $id );

		return $out;
	}

	/**
	 * Yoast's meta values for one post, or null when it cannot build them.
	 *
	 * @param int $post_id Post ID.
	 * @return object|null
	 */
	private function surface( $post_id ) {
		if ( ! function_exists( 'YoastSEO' ) ) {
			return null;
		}

		try {
			$yoast = YoastSEO();
			$meta  = isset( $yoast->meta ) && is_object( $yoast->meta ) && method_exists( $yoast->meta, 'for_post' ) ? $yoast->meta->for_post( (int) $post_id ) : false;
		} catch ( \Throwable $e ) {
			return null;
		}

		return is_object( $meta ) ? $meta : null;
	}

	/**
	 * One value from the meta surface; '' when Yoast fails to produce it.
	 *
	 * @param object $meta  Meta surface value object.
	 * @param string $field Field name.
	 * @return mixed
	 */
	private function field( $meta, $field ) {
		try {
			return $meta->{$field};
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	/**
	 * The focus keyphrase, then Yoast Premium's related keyphrases.
	 *
	 * @param int $post_id Post ID.
	 * @return string[]
	 */
	private function keywords( $post_id ) {
		$keywords = array();
		$focus    = self::plain( \WPSEO_Meta::get_value( 'focuskw', $post_id ) );

		if ( '' !== $focus ) {
			$keywords[] = $focus;
		}

		$related = json_decode( (string) get_post_meta( (int) $post_id, '_yoast_wpseo_focuskeywords', true ), true );
		foreach ( is_array( $related ) ? $related : array() as $entry ) {
			$keyword = is_array( $entry ) && isset( $entry['keyword'] ) ? self::plain( $entry['keyword'] ) : '';
			if ( '' !== $keyword ) {
				$keywords[] = $keyword;
			}
		}

		return $keywords;
	}

	/**
	 * A Yoast setting, or '' when it is not set.
	 *
	 * @param string $key Option key.
	 * @return mixed
	 */
	private function option( $key ) {
		return class_exists( 'WPSEO_Options' ) ? \WPSEO_Options::get( $key, '' ) : '';
	}

	/**
	 * Resolves Yoast's %%variables%% in the context of a post.
	 *
	 * @param mixed   $value Template or saved value.
	 * @param WP_Post $post  Post.
	 * @return string
	 */
	private function replace( $value, WP_Post $post ) {
		$value = is_scalar( $value ) ? (string) $value : '';

		return '' !== $value && function_exists( 'wpseo_replace_vars' ) ? (string) wpseo_replace_vars( $value, $post ) : $value;
	}
}
