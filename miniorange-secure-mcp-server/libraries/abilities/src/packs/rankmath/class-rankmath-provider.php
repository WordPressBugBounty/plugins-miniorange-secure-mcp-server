<?php
/**
 * Execute callback for the Rank Math ability pack.
 *
 * The write goes through update_post_meta()/delete_post_meta() directly rather
 * than through Rank Math's own Meta trait. Rank Math's editor path
 * (RankMath\Rest\Shared::update_metadata()) opens with
 * remove_all_filters( 'is_protected_meta' ), which drops every other plugin's
 * filter on that hook for the rest of the request — a side effect no ability
 * should have. Rank Math's own set-homepage-seo and SEO-analysis fix runner also
 * write the meta directly, and update_post_meta() does not consult protected
 * meta at all, so nothing is gained by routing through it.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Rankmath;

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
 * Class Rankmath_Provider
 */
class Rankmath_Provider {

	/**
	 * Writes a post's Rank Math SEO title, meta description and focus keyword.
	 *
	 * Only the fields present in the input are touched, so a caller can change one
	 * field without knowing or resending the other two. A field sent as an empty
	 * string is removed rather than stored empty, which is what restores the
	 * site-wide template for that field.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function update_post_seo( $input = array() ) {
		$input = (array) $input;
		$post  = Rankmath_Support::writable_post_or_error( isset( $input['post_id'] ) ? $input['post_id'] : 0 );

		if ( $post instanceof WP_Error ) {
			return $post;
		}

		$submitted = array();
		foreach ( array_keys( Rankmath_Support::FIELDS ) as $field ) {
			if ( array_key_exists( $field, $input ) ) {
				$submitted[ $field ] = Rankmath_Support::sanitize( $field, $input[ $field ] );
			}
		}

		if ( empty( $submitted ) ) {
			return new WP_Error(
				'no_fields',
				sprintf(
					/* translators: %s: comma-separated list of field names. */
					__( 'Nothing to write. Send at least one of: %s. To remove a stored value and let the site-wide template apply again, send that field as an empty string.', 'mosmcp-abilities' ),
					implode( ', ', array_keys( Rankmath_Support::FIELDS ) )
				)
			);
		}

		/*
		 * Captured before the first write and returned unconditionally. Without it
		 * a caller has no record of what it replaced: the response would show the
		 * new state only, and a value overwritten by mistake — in particular a
		 * template that has now been frozen into a literal string — would be
		 * unrecoverable and invisible.
		 */
		$previous_raw = Rankmath_Support::raw_meta( (int) $post->ID );

		$updated   = array();
		$cleared   = array();
		$unchanged = array();
		$warnings  = array();

		foreach ( $submitted as $field => $value ) {
			$previous = isset( $previous_raw[ $field ] ) ? $previous_raw[ $field ] : '';

			if ( $value === $previous ) {
				$unchanged[] = $field;
				continue;
			}

			if ( '' === $value ) {
				Rankmath_Support::clear( (int) $post->ID, $field );
				$cleared[] = $field;
				continue;
			}

			Rankmath_Support::store( (int) $post->ID, $field, $value );
			$updated[] = $field;

			$warnings = array_merge( $warnings, self::warnings_for_write( $field, $value, $previous, $post ) );
		}

		$current_raw = Rankmath_Support::raw_meta( (int) $post->ID );

		$result = array(
			'saved'        => ! empty( $updated ) || ! empty( $cleared ),
			'post_id'      => (int) $post->ID,
			'updated'      => $updated,
			'cleared'      => $cleared,
			'unchanged'    => $unchanged,
			'previous_raw' => $previous_raw,
			'current_raw'  => $current_raw,
			'rendered'     => array(
				'title'       => Rankmath_Support::render( $current_raw['title'], $post ),
				'description' => Rankmath_Support::render( $current_raw['description'], $post ),
			),
			'warnings'     => array_merge( $warnings, self::warnings_for_result( $updated, $cleared, $post ) ),
		);

		return $result;
	}

	/**
	 * Warnings about one field's new value.
	 *
	 * @param string   $field    Input field name.
	 * @param string   $value    Clean value that was stored.
	 * @param string   $previous Value it replaced.
	 * @param \WP_Post $post     Context post.
	 * @return array<int, array<string, string>>
	 */
	private static function warnings_for_write( $field, $value, $previous, $post ) {
		$warnings = array();

		if ( Rankmath_Support::looks_resolved( $field, $value, $previous, $post ) ) {
			$warnings[] = Rankmath_Support::warning(
				'possible_resolved_value_write',
				// The %title% and %sep% quoted in this message are Rank Math template
				// variables shown to the reader as an example, not printf
				// placeholders: there is nothing to order and nothing for a
				// translators comment to describe.
				// phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment,WordPress.WP.I18n.UnorderedPlaceholdersText
				__( 'The value stored is identical to what Rank Math was already generating for this field from the site-wide template or the post content. If it was copied from a reader that returns finished output, this post has just stopped following that template: the field is now a fixed string and will not update when the post title or site name changes. Store the template itself (for example "%title% %sep% %sitename%") to keep it live, or send the field as an empty string to restore the site-wide default.', 'mosmcp-abilities' ),
				'field=' . $field
			);
		}

		if ( Rankmath_Support::has_variables( $value ) ) {
			$warnings[] = Rankmath_Support::warning(
				'template_variables_present',
				__( 'The value contains Rank Math template variables, which are stored as written and resolved when the page is served. The rendered field in this response shows what they currently produce.', 'mosmcp-abilities' ),
				'field=' . $field
			);
		}

		if ( 'title' === $field || 'description' === $field ) {
			$limit  = 'title' === $field ? Rankmath_Support::TITLE_RECOMMENDED_MAX : Rankmath_Support::DESCRIPTION_RECOMMENDED_MAX;
			$length = mb_strlen( Rankmath_Support::render( $value, $post ) );

			if ( $length > $limit ) {
				$warnings[] = Rankmath_Support::warning(
					'length_over_recommended',
					sprintf(
						/* translators: 1: field name, 2: rendered length in characters, 3: recommended maximum. */
						__( 'The %1$s renders to %2$d characters, over the %3$d search engines typically display; the rest is likely to be truncated in results.', 'mosmcp-abilities' ),
						$field,
						$length,
						$limit
					),
					'field=' . $field . ' length=' . $length
				);
			}
		}

		if ( 'focus_keyword' === $field && false !== strpos( $value, ',' ) ) {
			$warnings[] = Rankmath_Support::warning(
				'focus_keyword_multiple',
				__( 'Several focus keywords were stored as one comma-separated value, which is how Rank Math holds them. The first one is treated as the primary keyword; only that one is scored in the free version.', 'mosmcp-abilities' ),
				'field=focus_keyword'
			);
		}

		return $warnings;
	}

	/**
	 * Warnings about the write as a whole.
	 *
	 * @param string[] $updated  Fields whose value was replaced.
	 * @param string[] $cleared  Fields whose value was removed.
	 * @param \WP_Post $post     Context post.
	 * @return array<int, array<string, string>>
	 */
	private static function warnings_for_result( array $updated, array $cleared, $post ) {
		$warnings = array();
		$touched  = array_merge( $updated, $cleared );

		if ( empty( $touched ) ) {
			return $warnings;
		}

		$warnings[] = Rankmath_Support::warning(
			'seo_score_not_recalculated',
			__( 'Rank Math computes the SEO score in the editor as an author types, not when metadata is written, so the score shown for this post is now stale. Opening the post in the editor recalculates it.', 'mosmcp-abilities' ),
			'post_id=' . (int) $post->ID
		);

		if ( in_array( 'description', $touched, true ) ) {
			$warnings[] = Rankmath_Support::warning(
				'social_descriptions_unaffected',
				__( 'The Facebook and Twitter descriptions are stored under their own keys and were not changed. Rank Math does not fall back to the meta description for the Open Graph description — it uses the post excerpt — so what social networks show for this post is still unchanged.', 'mosmcp-abilities' ),
				'post_id=' . (int) $post->ID
			);
		}

		if ( ! Rankmath_Support::can_render() ) {
			$warnings[] = Rankmath_Support::warning(
				'render_preview_unavailable',
				__( 'The values were written, but Rank Math is not connected to a Rank Math account on this site, so its template engine is not running and no preview of the rendered output could be produced. The rendered field repeats the stored values as-is.', 'mosmcp-abilities' ),
				'post_id=' . (int) $post->ID
			);
		}

		return $warnings;
	}
}
