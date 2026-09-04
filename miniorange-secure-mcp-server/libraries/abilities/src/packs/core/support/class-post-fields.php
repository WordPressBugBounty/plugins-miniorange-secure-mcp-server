<?php
/**
 * The shared title/content/excerpt update used by posts, pages and custom types.
 *
 * Kept in one place so those abilities cannot drift apart in what they accept, what
 * they sanitize, or what they report back.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Core\Support;

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
 * Class Post_Fields
 */
class Post_Fields {

	/**
	 * Which post type feature backs each editable field.
	 *
	 * A post type that does not declare the feature still *stores* a value written
	 * to it — WordPress does not refuse — but nothing ever renders it and the editor
	 * never shows it. Writing content to a title-only type therefore looks like a
	 * success and changes nothing a visitor sees, so the callers use this map to
	 * refuse instead.
	 *
	 * @var array<string, string>
	 */
	const FIELD_SUPPORTS = array(
		'content' => 'editor',
		'excerpt' => 'excerpt',
	);

	/**
	 * Applies a title/content/excerpt update and reports what changed.
	 *
	 * @param WP_Post              $post           Target post.
	 * @param array<string, mixed> $input          Ability input.
	 * @param bool                 $check_supports Whether to refuse fields the post type does not support.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function apply_update( WP_Post $post, array $input, $check_supports = false ) {
		$update  = array( 'ID' => $post->ID );
		$changed = array();

		if ( $check_supports ) {
			$unsupported = self::unsupported_error( $post->post_type, $input );
			if ( $unsupported instanceof WP_Error ) {
				return $unsupported;
			}
		}

		$requested_slug = null;

		if ( isset( $input['slug'] ) ) {
			$requested_slug = self::prepare_slug( $post->post_type, (string) $input['slug'] );
			if ( $requested_slug instanceof WP_Error ) {
				return $requested_slug;
			}
			$update['post_name'] = $requested_slug;
			$changed[]           = 'slug';
		}

		if ( isset( $input['title'] ) && '' !== trim( (string) $input['title'] ) ) {
			$update['post_title'] = sanitize_text_field( $input['title'] );
			$changed[]            = 'title';
		}
		if ( isset( $input['content'] ) ) {
			$update['post_content'] = wp_kses_post( $input['content'] );
			$changed[]              = 'content';
		}

		/*
		 * Excerpt is settable to an empty string on purpose: clearing it is a
		 * legitimate edit, so presence of the key — not truthiness — decides.
		 */
		if ( isset( $input['excerpt'] ) ) {
			$update['post_excerpt'] = sanitize_textarea_field( (string) $input['excerpt'] );
			$changed[]              = 'excerpt';
		}

		if ( ! $changed ) {
			return new WP_Error(
				'nothing_to_update',
				__( 'Provide a new title, content and/or excerpt to update.', 'mosmcp-abilities' )
			);
		}

		$result = wp_update_post( $update, true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$updated = get_post( $post->ID );

		$warnings = Post_Display::content_visibility_warnings( (int) $updated->ID, $changed );

		if ( null !== $requested_slug ) {
			$warnings = array_merge(
				$warnings,
				self::slug_warnings( $post, $updated, $requested_slug )
			);
		}

		return array(
			'id'        => (int) $updated->ID,
			'post_type' => (string) $updated->post_type,
			'title'     => (string) get_the_title( $updated ),
			'slug'      => (string) $updated->post_name,
			'status'    => (string) $updated->post_status,
			'modified'  => (string) $updated->post_modified,
			'updated'   => $changed,
			'edit_url'  => (string) get_edit_post_link( $updated->ID, 'raw' ),
			'view_url'  => (string) get_permalink( $updated->ID ),
			'warnings'  => $warnings,
		);
	}

	/**
	 * Validates and normalises a requested slug.
	 *
	 * The value is only sanitised here, not made unique: wp_update_post() and
	 * wp_insert_post() both run wp_unique_post_slug() themselves, so letting
	 * WordPress resolve collisions keeps one source of truth for the rules. The
	 * caller reports the slug that was actually stored afterwards, which may differ.
	 *
	 * @param string $post_type Post type slug.
	 * @param string $raw       Requested slug.
	 * @return string|WP_Error
	 */
	public static function prepare_slug( $post_type, $raw ) {
		$slug = sanitize_title( $raw );

		if ( '' === $slug ) {
			return new WP_Error(
				'invalid_slug',
				sprintf(
					/* translators: %s: the rejected slug */
					__( '"%s" does not leave anything usable once reduced to a URL-safe form. A slug needs at least one letter or number.', 'mosmcp-abilities' ),
					$raw
				)
			);
		}

		/*
		 * A slug is the address a visitor uses. On a post type that is never served
		 * on the front end there is no address, so setting one would be storing a
		 * value with no effect.
		 */
		if ( ! is_post_type_viewable( $post_type ) ) {
			$type_object = get_post_type_object( $post_type );
			$label       = ( $type_object && isset( $type_object->labels->singular_name ) )
				? strtolower( (string) $type_object->labels->singular_name )
				: (string) $post_type;

			return new WP_Error(
				'slug_not_applicable',
				sprintf(
					/* translators: %s: post type label */
					__( 'A %s is not shown on the site as its own page, so it has no address and setting a slug would have no effect.', 'mosmcp-abilities' ),
					$label
				)
			);
		}

		return $slug;
	}

	/**
	 * Warnings that follow from changing a slug.
	 *
	 * @param WP_Post $before    The post as it was.
	 * @param WP_Post $after     The post as stored.
	 * @param string  $requested The slug that was asked for.
	 * @return array<int, array<string, string>>
	 */
	public static function slug_warnings( WP_Post $before, WP_Post $after, $requested ) {
		$warnings = array();
		$stored   = (string) $after->post_name;

		/*
		 * WordPress appends a suffix when the slug is already taken. Reporting the
		 * requested value as though it were stored would be a quiet lie, so the
		 * difference is called out explicitly.
		 */
		if ( $stored !== $requested ) {
			$warnings[] = array(
				'code'    => 'slug_adjusted_for_uniqueness',
				'message' => sprintf(
					/* translators: 1: requested slug, 2: stored slug */
					__( 'The address "%1$s" was already in use, so WordPress stored "%2$s" instead. Use the stored value when linking to this item.', 'mosmcp-abilities' ),
					$requested,
					$stored
				),
				'context' => 'requested=' . $requested . ' stored=' . $stored,
			);
		}

		/*
		 * Renaming a draft costs nothing. Renaming something already published moves a
		 * live URL, and what happens to the old one is not the same everywhere:
		 * wp_check_for_changed_slugs() records the previous slug, and
		 * wp_old_slug_redirect() sends visitors on to the new address — but both of
		 * them return early for hierarchical post types. So a post keeps a working
		 * redirect while a page, or a hierarchical custom type such as a Services
		 * type, simply starts returning 404 with nothing recorded. Saying "WordPress
		 * redirects it" for all of them would be reassuring and wrong, so the two
		 * cases are reported separately.
		 */
		$was_public = in_array( (string) $before->post_status, array( 'publish', 'private' ), true );
		$slug_moved = (string) $before->post_name !== $stored && '' !== (string) $before->post_name;

		if ( $was_public && $slug_moved ) {
			$hierarchical = is_post_type_hierarchical( (string) $after->post_type );

			$warnings[] = array(
				'code'    => $hierarchical ? 'published_url_changed_no_redirect' : 'published_url_changed',
				'message' => $hierarchical
					? sprintf(
						/* translators: 1: previous slug, 2: new slug, 3: post type name */
						__( 'This was already published, so its address changed from "%1$s" to "%2$s" — and the old address now returns "not found". WordPress only keeps old-address redirects for non-hierarchical content, and %3$s is hierarchical, so nothing forwards the previous URL. Add a redirect yourself if the old address is in use anywhere: a menu, an advert, a search result, or a link on another site.', 'mosmcp-abilities' ),
						(string) $before->post_name,
						$stored,
						(string) $after->post_type
					)
					: sprintf(
						/* translators: 1: previous slug, 2: new slug */
						__( 'This was already published, so its address changed from "%1$s" to "%2$s". WordPress recorded the previous address and will forward visitors from it, but any link written by hand elsewhere — in a menu, an advert, another site — is worth updating anyway.', 'mosmcp-abilities' ),
						(string) $before->post_name,
						$stored
					),
				'context' => 'previous=' . $before->post_name . ' current=' . $stored
					. ' redirect=' . ( $hierarchical ? 'none' : 'kept' ),
			);
		}

		return $warnings;
	}

	/**
	 * Refuses fields the post type does not declare support for.
	 *
	 * @param string               $post_type Post type slug.
	 * @param array<string, mixed> $input     Ability input.
	 * @return true|WP_Error
	 */
	public static function unsupported_error( $post_type, array $input ) {
		foreach ( self::FIELD_SUPPORTS as $field => $feature ) {
			if ( ! array_key_exists( $field, $input ) ) {
				continue;
			}
			if ( post_type_supports( $post_type, $feature ) ) {
				continue;
			}

			$type_object = get_post_type_object( $post_type );
			$label       = ( $type_object && isset( $type_object->labels->singular_name ) )
				? (string) $type_object->labels->singular_name
				: (string) $post_type;

			return new WP_Error(
				'field_not_supported',
				sprintf(
					/* translators: 1: field name, 2: post type label, 3: post type feature name */
					__( 'The %2$s type does not support %1$s, so a value written there would be stored but never displayed anywhere. It would need "%3$s" added to the type\'s supported features first.', 'mosmcp-abilities' ),
					$field,
					$label,
					$feature
				)
			);
		}

		return true;
	}
}
