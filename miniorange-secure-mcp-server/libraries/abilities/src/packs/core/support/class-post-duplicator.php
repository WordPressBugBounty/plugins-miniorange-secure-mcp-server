<?php
/**
 * Post duplication engine shared by the post-duplicate and page-duplicate abilities.
 *
 * The problem this solves: a post's appearance lives almost entirely in its
 * postmeta, not in its content. An Elementor layout, a theme's per-post sidebar
 * and content-width settings, ACF fields and SEO fields are all meta rows. A
 * "create post" call therefore produces correct text with none of the design,
 * which is exactly what customers report as "the new post didn't inherit our
 * layout".
 *
 * The copy strategy is deliberately an exclusion list rather than an inclusion
 * list. An inclusion list would have to enumerate every theme's layout keys,
 * every SEO plugin's fields and every ACF convention — unbounded, and it fails
 * silently on any theme we have not seen. Copying everything except a short,
 * principled exclusion list means an unknown theme works by default, which is
 * the only way this can be generic across customers.
 *
 * The exclusion rule is not an arbitrary list. Anything scoped to *this specific
 * post's identity* must not travel (its ID, its URL, its SKU, its history, its
 * display slot); anything describing *how the post looks* must. Every entry
 * below is justified against that rule in its group comment.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Core\Support;

use MoSMCP\Abilities\Config;
use WP_Error;
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
 * Class Post_Duplicator
 */
class Post_Duplicator {

	/**
	 * Meta keys never copied, matched exactly.
	 *
	 * @var string[]
	 */
	const EXCLUDED_META = array(
		/*
		 * Editor session state. Copying a lock makes WordPress believe someone is
		 * mid-edit on a post that was created a moment ago.
		 */
		'_edit_lock',
		'_edit_last',

		/*
		 * URL and status history. Two posts claiming the same historical slug make
		 * WordPress's 301 for that URL nondeterministic.
		 */
		'_wp_old_slug',
		'_wp_old_date',
		'_wp_desired_post_slug',
		'_wp_trash_meta_status',
		'_wp_trash_meta_time',
		'_wp_trash_meta_comments_status',

		/*
		 * Deferred-work flags. Copying these queues redundant pingback and
		 * enclosure processing for content that was already processed.
		 */
		'_pingme',
		'_encloseme',

		/*
		 * Elementor caches and indexes, all keyed to the source post ID.
		 * _elementor_css is the one that visibly breaks a clone: under the
		 * "Internal Embedding" CSS print method it holds real CSS whose selectors
		 * are scoped to the source post's ID, so a clone that inherits it renders
		 * with rules that can never match. Verified against Elementor 4.2.2.
		 */
		'_elementor_css',
		'_elementor_element_cache',
		'_elementor_page_assets',
		'_elementor_controls_usage',
		'_elementor_screenshot',
		'_elementor_global_class_usage_indexed',
		'_elementor_global_class_usage_indexed_preview',

		/*
		 * Elementor display conditions. Only present on library templates, where
		 * two templates claiming identical conditions leaves the winner undefined.
		 */
		'_elementor_conditions',

		/*
		 * Earned metrics. A duplicated product that inherits these advertises
		 * sales and star ratings it never had — fabricated data shown to buyers.
		 */
		'total_sales',
		'_wc_review_count',
		'_wc_rating_count',
		'_wc_average_rating',

		/*
		 * View counters from common stats plugins. Same reasoning as above:
		 * cosmetic, but dishonest.
		 */
		'post_views_count',
		'_post_views',
		'views',
		'_wpb_post_views',

		/*
		 * Syndication state. These record that *this* post was already pushed to
		 * social networks; the clone has not been.
		 */
		'_jetpack_related_posts_cache',
		'_jetpack_dont_email_post_to_subs',
	);

	/**
	 * Meta key prefixes never copied.
	 *
	 * @var string[]
	 */
	const EXCLUDED_META_PREFIXES = array(
		'_oembed_',    // Cached third-party embed markup, refetched on demand.
		'_wpas_',      // Jetpack Publicize per-connection "already shared" flags.
		'_publicize_', // Jetpack Publicize scheduling state.
	);

	/**
	 * Meta keys carried over as an empty value rather than omitted, with the
	 * reason reported back to the caller.
	 *
	 * These are the only keys the engine rewrites rather than skips. Both name
	 * something globally unique to the source, so inheriting the value is worse
	 * than inheriting nothing: an inherited canonical tells search engines the
	 * source is the real page and the clone should not be indexed, and a
	 * duplicate SKU fails WooCommerce's uniqueness validation.
	 *
	 * @var array<string, string>
	 */
	const CLEARED_META = array(
		'_yoast_wpseo_canonical' => 'canonical',
		'_aioseo_canonical_url'  => 'canonical',
		'_sku'                   => 'sku',
	);

	/**
	 * Post fields copied verbatim from the source.
	 *
	 * post_name is deliberately absent: letting WordPress derive a fresh unique
	 * slug avoids colliding with the source.
	 *
	 * @var string[]
	 */
	const COPIED_POST_FIELDS = array(
		'post_excerpt',
		'post_parent',
		'menu_order',
		'comment_status',
		'ping_status',
		'post_password',
		'post_mime_type',
	);

	/**
	 * Statuses that require the post type's publish capability.
	 *
	 * @var string[]
	 */
	const PUBLISH_STATUSES = array( 'publish', 'private', 'future' );

	/**
	 * Statuses a clone may be created with.
	 *
	 * @var string[]
	 */
	const ALLOWED_STATUSES = array( 'draft', 'pending', 'private', 'publish' );

	/**
	 * Largest meta value scanned for source-ID self-references, in bytes.
	 *
	 * Structural blobs (a serialized Elementor tree, a page builder payload) are
	 * far larger than this and would produce noise rather than signal, because a
	 * long digit string will coincidentally contain almost any post ID.
	 *
	 * @var int
	 */
	const SCAN_MAX_BYTES = 512;

	/**
	 * Maximum number of suspect keys reported, to keep the response bounded.
	 *
	 * @var int
	 */
	const SCAN_MAX_REPORTED = 10;

	/**
	 * Duplicates a post, including the metadata and taxonomy terms that carry its
	 * appearance.
	 *
	 * @param array<string, mixed> $input     Ability input.
	 * @param string               $post_type Post type this ability is scoped to.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function duplicate( array $input, $post_type ) {
		$source = self::require_source( $input, $post_type );
		if ( $source instanceof WP_Error ) {
			return $source;
		}

		$status = isset( $input['status'] ) ? sanitize_key( (string) $input['status'] ) : 'draft';
		if ( ! in_array( $status, self::ALLOWED_STATUSES, true ) ) {
			return new WP_Error(
				'invalid_status',
				sprintf(
					/* translators: 1: requested status, 2: comma-separated list of allowed statuses */
					__( 'Status "%1$s" is not supported for a duplicate. Use one of: %2$s.', 'mosmcp-abilities' ),
					$status,
					implode( ', ', self::ALLOWED_STATUSES )
				)
			);
		}

		$type_object = get_post_type_object( $post_type );
		if ( ! $type_object ) {
			return new WP_Error(
				'invalid_post_type',
				sprintf(
					/* translators: %s: post type slug */
					__( 'The post type "%s" is not registered on this site.', 'mosmcp-abilities' ),
					$post_type
				)
			);
		}

		/*
		 * wp_insert_post enforces no capabilities of its own, so the create and
		 * publish gates are checked here. The ability's own capability gate has
		 * already confirmed edit rights on the source.
		 */
		if ( ! current_user_can( $type_object->cap->create_posts ) ) {
			return new WP_Error(
				'cannot_create',
				sprintf(
					/* translators: 1: post type label, 2: capability name */
					__( 'You do not have permission to create %1$s. This requires the "%2$s" capability.', 'mosmcp-abilities' ),
					strtolower( (string) $type_object->labels->name ),
					(string) $type_object->cap->create_posts
				)
			);
		}

		if ( in_array( $status, self::PUBLISH_STATUSES, true ) && ! current_user_can( $type_object->cap->publish_posts ) ) {
			return new WP_Error(
				'cannot_publish',
				sprintf(
					/* translators: 1: requested status, 2: capability name */
					__( 'You do not have permission to create a duplicate with status "%1$s". This requires the "%2$s" capability. Create it as a draft instead.', 'mosmcp-abilities' ),
					$status,
					(string) $type_object->cap->publish_posts
				)
			);
		}

		$lock = self::check_lock( $input, $source );
		if ( $lock instanceof WP_Error ) {
			return $lock;
		}

		$title = isset( $input['title'] ) ? sanitize_text_field( (string) $input['title'] ) : '';
		if ( '' === $title ) {
			$title = sprintf(
				/* translators: %s: title of the post being duplicated */
				__( '%s (copy)', 'mosmcp-abilities' ),
				(string) $source->post_title
			);
		}

		$include_content = ! isset( $input['include_content'] ) || (bool) $input['include_content'];

		$postarr = array(
			'post_type'   => $source->post_type,
			'post_status' => $status,
			'post_title'  => $title,
			/*
			 * The clone is authored by the connected account, not the source's
			 * author. Ownership follows creation, and it keeps the clone editable
			 * by the account that made it.
			 */
			'post_author' => get_current_user_id(),
		);

		$postarr['post_content'] = $include_content ? $source->post_content : '';

		foreach ( self::COPIED_POST_FIELDS as $field ) {
			if ( isset( $source->{$field} ) && '' !== $source->{$field} ) {
				$postarr[ $field ] = $source->{$field};
			}
		}

		$new_id = wp_insert_post( wp_slash( $postarr ), true );
		if ( is_wp_error( $new_id ) ) {
			return $new_id;
		}
		$new_id = (int) $new_id;

		$report = array(
			'skipped'   => array(),
			'rewritten' => array(),
			'warnings'  => array(),
			'meta_rows' => 0,
		);

		self::copy_meta( (int) $source->ID, $new_id, $report );
		$taxonomies = self::copy_taxonomies( $source, $new_id, $report );
		self::verify_thumbnail( $new_id, $report );

		$elementor_bytes = strlen( (string) get_post_meta( $new_id, '_elementor_data', true ) );
		if ( $elementor_bytes > 0 ) {
			$intact = self::verify_elementor_copy( (int) $source->ID, $new_id, $report );
			if ( $intact instanceof WP_Error ) {
				return $intact;
			}

			self::refresh_elementor( $new_id, $report );
		}

		return array(
			'new_id'    => $new_id,
			'source_id' => (int) $source->ID,
			'title'     => (string) get_the_title( $new_id ),
			'status'    => (string) get_post_status( $new_id ),
			'edit_url'  => (string) get_edit_post_link( $new_id, 'raw' ),
			'view_url'  => (string) get_permalink( $new_id ),
			'copied'    => array(
				'meta_rows'       => (int) $report['meta_rows'],
				'taxonomies'      => $taxonomies,
				'thumbnail_id'    => (int) get_post_thumbnail_id( $new_id ),
				'has_elementor'   => $elementor_bytes > 0,
				'elementor_bytes' => $elementor_bytes,
			),
			'skipped'   => $report['skipped'],
			'rewritten' => $report['rewritten'],
			'warnings'  => $report['warnings'],
		);
	}

	/**
	 * Resolves and validates the source post.
	 *
	 * @param array<string, mixed> $input     Ability input.
	 * @param string               $post_type Expected post type.
	 * @return WP_Post|WP_Error
	 */
	private static function require_source( array $input, $post_type ) {
		$id     = isset( $input['source_id'] ) ? absint( $input['source_id'] ) : 0;
		$source = $id > 0 ? get_post( $id ) : null;

		if ( ! $source || $post_type !== $source->post_type ) {
			return new WP_Error(
				'source_not_found',
				sprintf(
					/* translators: %s: post type slug */
					__( 'No %s found with that ID to duplicate.', 'mosmcp-abilities' ),
					$post_type
				)
			);
		}

		if ( 'auto-draft' === $source->post_status ) {
			return new WP_Error(
				'source_not_saved',
				__( 'That post is an unsaved auto-draft and has no layout or metadata to copy yet.', 'mosmcp-abilities' )
			);
		}

		return $source;
	}

	/**
	 * Rejects the duplicate when the source changed since the caller last read it.
	 *
	 * @param array<string, mixed> $input  Ability input.
	 * @param WP_Post              $source Source post.
	 * @return true|WP_Error
	 */
	private static function check_lock( array $input, WP_Post $source ) {
		if ( ! isset( $input['expected_modified'] ) || '' === trim( (string) $input['expected_modified'] ) ) {
			return true;
		}

		$expected = strtotime( (string) $input['expected_modified'] );
		if ( false === $expected ) {
			return new WP_Error(
				'invalid_expected_modified',
				__( 'expected_modified must be a date string, for example the "modified" value from a previous read.', 'mosmcp-abilities' )
			);
		}

		$actual = strtotime( (string) $source->post_modified_gmt . ' UTC' );
		if ( false !== $actual && abs( $actual - $expected ) > 1 ) {
			return new WP_Error(
				'source_changed',
				sprintf(
					/* translators: 1: expected modified time, 2: actual modified time */
					__( 'The source post changed since you last read it (expected %1$s, found %2$s). Read it again before duplicating.', 'mosmcp-abilities' ),
					gmdate( 'Y-m-d H:i:s', $expected ),
					(string) $source->post_modified_gmt
				)
			);
		}

		return true;
	}

	/**
	 * Copies every meta row except the excluded set, preserving multi-value keys.
	 *
	 * @param int                  $source_id Source post ID.
	 * @param int                  $new_id    New post ID.
	 * @param array<string, mixed> $report    Report accumulator, modified in place.
	 * @return void
	 */
	private static function copy_meta( $source_id, $new_id, array &$report ) {
		$all = get_post_meta( $source_id );
		if ( ! is_array( $all ) ) {
			return;
		}

		$excluded = self::excluded_keys();
		$suspects = array();

		foreach ( $all as $key => $rows ) {
			$key = (string) $key;

			if ( in_array( $key, $excluded, true ) || self::has_excluded_prefix( $key ) ) {
				$report['skipped'][] = array(
					'key'    => $key,
					'reason' => self::skip_reason( $key ),
				);
				continue;
			}

			if ( isset( self::CLEARED_META[ $key ] ) ) {
				$report['rewritten'][] = array(
					'key'    => $key,
					'reason' => self::clear_reason( self::CLEARED_META[ $key ] ),
				);
				continue;
			}

			foreach ( (array) $rows as $raw ) {
				/*
				 * Two separate conversions are needed here, and missing either one
				 * corrupts the copy.
				 *
				 * get_post_meta() with no key returns the raw database strings, so an
				 * array-valued row arrives still serialized. add_post_meta() serializes
				 * again on the way in, so the value is unserialized first — otherwise
				 * every array field ends up double-serialized and reads back as a
				 * string.
				 *
				 * Then wp_slash(). add_post_meta() ultimately calls wp_unslash() on the
				 * value, because the metadata API is written for data arriving from a
				 * form submission, where it is already slashed. A value read back out
				 * of the database is not, so handing it over unslashed strips one level
				 * of backslashes on the way in. For most fields that quietly mangles
				 * text — a Windows path loses its separators, a regex loses its \d. For
				 * _elementor_data it is fatal: the layout is JSON, every HTML attribute
				 * inside it is an escaped \" quote, and removing those backslashes ends
				 * the JSON string early. The copy then fails to parse at all, and
				 * Elementor reads it as an empty layout.
				 *
				 * wp_slash() recurses into arrays and leaves non-strings alone, exactly
				 * mirroring the wp_unslash() that follows, so one call is right for
				 * both shapes.
				 */
				$value = maybe_unserialize( $raw );
				add_post_meta( $new_id, $key, wp_slash( $value ) );
				++$report['meta_rows'];

				if ( count( $suspects ) < self::SCAN_MAX_REPORTED
					&& ! isset( $suspects[ $key ] )
					&& self::references_source( $raw, $source_id ) ) {
					$suspects[ $key ] = true;
				}
			}
		}

		if ( $suspects ) {
			$report['warnings'][] = array(
				'code'    => 'meta_may_reference_source',
				'message' => __( 'Some copied fields contain the source post\'s ID or URL. If any of them is a cross-reference rather than a design setting, it still points at the original.', 'mosmcp-abilities' ),
				'context' => implode( ', ', array_keys( $suspects ) ),
			);
		}
	}

	/**
	 * Copies terms for every taxonomy registered to the post type.
	 *
	 * @param WP_Post              $source Source post.
	 * @param int                  $new_id New post ID.
	 * @param array<string, mixed> $report Report accumulator, modified in place.
	 * @return string[] Taxonomies that received terms.
	 */
	private static function copy_taxonomies( WP_Post $source, $new_id, array &$report ) {
		$copied = array();

		foreach ( get_object_taxonomies( $source->post_type ) as $taxonomy ) {
			$terms = wp_get_object_terms( (int) $source->ID, $taxonomy, array( 'fields' => 'ids' ) );
			if ( is_wp_error( $terms ) ) {
				$report['warnings'][] = array(
					'code'    => 'taxonomy_read_failed',
					'message' => $terms->get_error_message(),
					'context' => $taxonomy,
				);
				continue;
			}
			if ( ! $terms ) {
				continue;
			}

			$set = wp_set_object_terms( $new_id, array_map( 'intval', $terms ), $taxonomy, false );
			if ( is_wp_error( $set ) ) {
				$report['warnings'][] = array(
					'code'    => 'taxonomy_copy_failed',
					'message' => $set->get_error_message(),
					'context' => $taxonomy,
				);
				continue;
			}

			$copied[] = $taxonomy;
		}

		return $copied;
	}

	/**
	 * Warns when the copied featured image no longer resolves to an attachment.
	 *
	 * @param int                  $new_id New post ID.
	 * @param array<string, mixed> $report Report accumulator, modified in place.
	 * @return void
	 */
	private static function verify_thumbnail( $new_id, array &$report ) {
		$thumb = (int) get_post_thumbnail_id( $new_id );
		if ( $thumb <= 0 ) {
			return;
		}
		if ( 'attachment' !== get_post_type( $thumb ) ) {
			delete_post_meta( $new_id, '_thumbnail_id' );
			$report['warnings'][] = array(
				'code'    => 'featured_image_missing',
				'message' => __( 'The source post referenced a featured image that no longer exists, so the duplicate has none.', 'mosmcp-abilities' ),
				'context' => '_thumbnail_id=' . $thumb,
			);
		}
	}

	/**
	 * Confirms the copied Elementor layout is still readable, and refuses if not.
	 *
	 * A layout that does not parse is worse than a failed duplicate. Elementor reads
	 * unparseable data as a document with no elements, so the copy opens on an empty
	 * canvas and saving it writes that emptiness over the remaining data — meaning a
	 * silently broken duplicate invites the user to destroy it. There is also no
	 * dependable way to repair one after the fact: the damage is lost characters, and
	 * the only exact source of truth is the post it was copied from.
	 *
	 * So a copy that fails this check is removed completely and the whole duplication
	 * reported as failed, leaving the caller to try again against an intact source.
	 *
	 * A source that was already unreadable is a different matter: the copy is faithful
	 * to it, and refusing would make a damaged post impossible to duplicate at all.
	 * That case is reported as a warning and allowed through.
	 *
	 * @param int                  $source_id Source post ID.
	 * @param int                  $new_id    New post ID.
	 * @param array<string, mixed> $report    Report accumulator, modified in place.
	 * @return true|WP_Error
	 */
	private static function verify_elementor_copy( $source_id, $new_id, array &$report ) {
		$source_raw = (string) get_post_meta( $source_id, '_elementor_data', true );
		$copy_raw   = (string) get_post_meta( $new_id, '_elementor_data', true );

		json_decode( $source_raw, true );
		$source_ok = ( JSON_ERROR_NONE === json_last_error() );

		json_decode( $copy_raw, true );
		$copy_ok = ( JSON_ERROR_NONE === json_last_error() );

		if ( $copy_ok ) {
			/*
			 * Parsing is the floor, not the goal. A copy that parses but differs
			 * byte-for-byte has still lost something, so the mismatch is surfaced
			 * rather than assumed harmless.
			 */
			if ( $source_ok && $source_raw !== $copy_raw ) {
				$report['warnings'][] = array(
					'code'    => 'elementor_data_differs',
					'message' => __( 'The copied Elementor layout is readable but is not byte-for-byte identical to the original. The copy may render slightly differently, so it is worth comparing the two before relying on it.', 'mosmcp-abilities' ),
					'context' => sprintf( 'source_bytes=%d copy_bytes=%d', strlen( $source_raw ), strlen( $copy_raw ) ),
				);
			}

			return true;
		}

		if ( ! $source_ok ) {
			$report['warnings'][] = array(
				'code'    => 'elementor_data_unreadable_in_source',
				'message' => __( 'The original post\'s Elementor layout is not readable, and the copy inherits that. This was already the case before duplicating, so the copy is faithful — but neither post can be edited through the Elementor abilities until the original is rebuilt.', 'mosmcp-abilities' ),
				'context' => 'source_id=' . (int) $source_id,
			);

			return true;
		}

		// The source was fine and the copy is not, so nothing about this copy is trustworthy.
		self::discard( $new_id );

		return new WP_Error(
			'elementor_copy_corrupted',
			sprintf(
				/* translators: %d: source post ID */
				__( 'The duplicate was discarded: its Elementor layout did not survive the copy intact, and a post whose layout cannot be read would open on an empty canvas in Elementor. Nothing was left behind. Post %d itself is unaffected — try again, and report this if it repeats.', 'mosmcp-abilities' ),
				(int) $source_id
			),
			array( 'source_id' => (int) $source_id )
		);
	}

	/**
	 * Removes a half-made duplicate, leaving nothing behind.
	 *
	 * Force-deletes rather than trashing: a copy the caller never saw should not
	 * appear in the trash for someone to find later and wonder about.
	 *
	 * @param int $new_id New post ID.
	 * @return void
	 */
	private static function discard( $new_id ) {
		$new_id = (int) $new_id;

		// Detach the thumbnail relationship before the post goes, so no stray meta survives.
		delete_post_meta( $new_id, '_thumbnail_id' );
		wp_delete_object_term_relationships( $new_id, get_object_taxonomies( (string) get_post_type( $new_id ) ) );
		wp_delete_post( $new_id, true );
	}

	/**
	 * Rebuilds Elementor's per-post CSS for the clone.
	 *
	 * The cache keys are never copied, so the clone starts with none. Elementor
	 * regenerates lazily on the next front-end render, but doing it here means the
	 * first view is correct rather than uncached, and it surfaces a failure to the
	 * caller instead of silently producing an unstyled page.
	 *
	 * @param int                  $new_id New post ID.
	 * @param array<string, mixed> $report Report accumulator, modified in place.
	 * @return void
	 */
	private static function refresh_elementor( $new_id, array &$report ) {
		/*
		 * A layout only renders when the post is also flagged as built with the
		 * builder. Where the source carries layout data without that flag, the copy
		 * inherits data that will never be displayed — and duplication is faithful
		 * on purpose, so the state is reported rather than silently corrected:
		 * changing it would make the copy render differently from the original.
		 */
		if ( 'builder' !== (string) get_post_meta( (int) $new_id, '_elementor_edit_mode', true ) ) {
			$report['warnings'][] = array(
				'code'    => 'elementor_layout_will_not_render',
				'message' => __( 'The copy carries an Elementor layout, but neither it nor the original is switched into Elementor builder mode, so WordPress will show the post content instead of the layout. The original behaves the same way. Opening either in Elementor and saving once fixes it.', 'mosmcp-abilities' ),
				'context' => 'post_id=' . (int) $new_id,
			);
		}

		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			$report['warnings'][] = array(
				'code'    => 'elementor_inactive',
				'message' => __( 'The duplicate carries Elementor layout data, but Elementor is not active, so the layout will not render until it is enabled.', 'mosmcp-abilities' ),
				'context' => 'post_id=' . $new_id,
			);
			return;
		}

		if ( ! class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
			return;
		}

		try {
			\Elementor\Core\Files\CSS\Post::create( $new_id )->update();
		} catch ( \Throwable $e ) {
			$report['warnings'][] = array(
				'code'    => 'css_regen_failed',
				'message' => $e->getMessage(),
				'context' => 'post_id=' . $new_id,
			);
		}
	}

	/**
	 * The full exclusion list, filterable so a site can add its own keys.
	 *
	 * @return string[]
	 */
	private static function excluded_keys() {
		$keys = self::EXCLUDED_META;

		/**
		 * Filters the meta keys excluded when duplicating a post.
		 *
		 * The hook name carries the host plugin's prefix, but that prefix is
		 * configured at runtime because this library is reused across plugins, so
		 * the name cannot be written as a literal. On this plugin the hook is
		 * `mosmcp_post_duplicate_excluded_meta`.
		 *
		 * @param string[] $keys Meta keys that will not be copied.
		 */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Prefixed via Config::prefix(); the library's prefix is host-configurable and cannot be a literal.
		$filtered = apply_filters( Config::prefix() . '_post_duplicate_excluded_meta', $keys );

		return is_array( $filtered ) ? array_values( array_unique( array_map( 'strval', $filtered ) ) ) : $keys;
	}

	/**
	 * Whether a meta key matches one of the excluded prefixes.
	 *
	 * @param string $key Meta key.
	 * @return bool
	 */
	private static function has_excluded_prefix( $key ) {
		foreach ( self::EXCLUDED_META_PREFIXES as $prefix ) {
			if ( 0 === strpos( $key, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Human-readable reason a key was skipped.
	 *
	 * @param string $key Meta key.
	 * @return string
	 */
	private static function skip_reason( $key ) {
		if ( 0 === strpos( $key, '_elementor' ) ) {
			return __( 'Elementor cache keyed to the original post; regenerated for the duplicate.', 'mosmcp-abilities' );
		}
		if ( in_array( $key, array( 'total_sales', '_wc_review_count', '_wc_rating_count', '_wc_average_rating' ), true ) ) {
			return __( 'Metric earned by the original post; copying it would show figures the duplicate has not earned.', 'mosmcp-abilities' );
		}
		if ( in_array( $key, array( 'post_views_count', '_post_views', 'views', '_wpb_post_views' ), true ) ) {
			return __( 'View counter belonging to the original post.', 'mosmcp-abilities' );
		}
		if ( in_array( $key, array( '_edit_lock', '_edit_last' ), true ) ) {
			return __( 'Editor session state for the original post.', 'mosmcp-abilities' );
		}
		if ( 0 === strpos( $key, '_wp_old' ) || 0 === strpos( $key, '_wp_trash' ) || '_wp_desired_post_slug' === $key ) {
			return __( 'URL or status history unique to the original post.', 'mosmcp-abilities' );
		}
		if ( 0 === strpos( $key, '_oembed_' ) ) {
			return __( 'Cached embed markup; refetched on demand.', 'mosmcp-abilities' );
		}
		if ( 0 === strpos( $key, '_wpas_' ) || 0 === strpos( $key, '_publicize_' ) || 0 === strpos( $key, '_jetpack' ) ) {
			return __( 'Records that the original post was already shared to social networks.', 'mosmcp-abilities' );
		}
		return __( 'Scoped to the original post rather than to its appearance.', 'mosmcp-abilities' );
	}

	/**
	 * Human-readable reason a key was cleared rather than copied.
	 *
	 * @param string $kind Rewrite kind: 'canonical' or 'sku'.
	 * @return string
	 */
	private static function clear_reason( $kind ) {
		if ( 'canonical' === $kind ) {
			return __( 'Cleared: an inherited canonical URL tells search engines the original is the real page, which would keep the duplicate out of search results.', 'mosmcp-abilities' );
		}
		return __( 'Cleared: SKUs must be unique, and a duplicate SKU fails WooCommerce validation.', 'mosmcp-abilities' );
	}

	/**
	 * Whether a short meta value appears to reference the source post.
	 *
	 * Deliberately conservative. Only scalars that equal the source ID, and short
	 * strings containing the ID at a digit boundary, count — so post 40 is not
	 * matched by the value 405, and large structural blobs are not scanned at all.
	 *
	 * @param mixed $raw       Raw (still serialized) meta value.
	 * @param int   $source_id Source post ID.
	 * @return bool
	 */
	private static function references_source( $raw, $source_id ) {
		if ( ! is_scalar( $raw ) ) {
			return false;
		}

		$text = (string) $raw;
		if ( '' === $text || strlen( $text ) > self::SCAN_MAX_BYTES ) {
			return false;
		}

		$id = (string) (int) $source_id;

		if ( $text === $id ) {
			return true;
		}

		return 1 === preg_match( '/(?<!\d)' . preg_quote( $id, '/' ) . '(?!\d)/', $text );
	}
}
