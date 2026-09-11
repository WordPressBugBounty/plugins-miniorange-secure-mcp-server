<?php
/**
 * Per-post display settings: template assignment and featured image.
 *
 * These are the two levers that decide how a post renders but live outside its
 * content. Reading them answers "why does this post look different from that
 * one"; setting them is how a newly created post is made to match.
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
 * Class Post_Display
 */
class Post_Display {

	/**
	 * Substrings that mark a meta key as likely to affect layout.
	 *
	 * This is a reporting heuristic, never a copy rule. Themes name their per-post
	 * layout settings freely, so the duplicator copies everything and does not
	 * consult this list; it exists only so a caller diagnosing a mismatched post
	 * can be shown the handful of keys worth looking at instead of all of them.
	 *
	 * @var string[]
	 */
	const LAYOUT_KEY_HINTS = array(
		'layout',
		'sidebar',
		'width',
		'template',
		'header',
		'footer',
		'container',
		'content_style',
		'content-style',
		'transparent',
		'breadcrumb',
		'banner',
		'hero',
		'spacing',
		'padding',
		'title_visib',
		'hide_title',
		'disable_title',
		'post_title',
		'page_title',
	);

	/**
	 * The template slug WordPress treats as "the theme's default".
	 *
	 * @var string
	 */
	const DEFAULT_TEMPLATE = 'default';

	/**
	 * Reports the display settings that decide how a post renders.
	 *
	 * @param array<string, mixed> $input     Ability input.
	 * @param string               $post_type Post type this ability is scoped to.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function template_get( array $input, $post_type ) {
		$post = self::require_post( $input, $post_type );
		if ( $post instanceof WP_Error ) {
			return $post;
		}

		$current = (string) get_post_meta( $post->ID, '_wp_page_template', true );
		if ( '' === $current ) {
			$current = self::DEFAULT_TEMPLATE;
		}

		$available = self::available_templates( $post );

		$elementor_data = (string) get_post_meta( $post->ID, '_elementor_data', true );

		return array(
			'id'               => (int) $post->ID,
			'post_type'        => (string) $post->post_type,
			'title'            => (string) get_the_title( $post ),
			'page_template'    => array(
				'current'      => $current,
				'label'        => isset( $available[ $current ] ) ? $available[ $current ] : '',
				'is_available' => isset( $available[ $current ] ),
				'available'    => self::template_list( $available ),
			),
			'elementor'        => array(
				'plugin_active' => class_exists( '\Elementor\Plugin' ),
				'has_data'      => '' !== $elementor_data,
				'data_bytes'    => strlen( $elementor_data ),
				'edit_mode'     => (string) get_post_meta( $post->ID, '_elementor_edit_mode', true ),
				'template_type' => (string) get_post_meta( $post->ID, '_elementor_template_type', true ),
				'version'       => (string) get_post_meta( $post->ID, '_elementor_version', true ),
			),
			'display_settings' => self::layout_meta( (int) $post->ID ),
		);
	}

	/**
	 * Assigns a page template to a post.
	 *
	 * @param array<string, mixed> $input     Ability input.
	 * @param string               $post_type Post type this ability is scoped to.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function template_set( array $input, $post_type ) {
		$post = self::require_post( $input, $post_type );
		if ( $post instanceof WP_Error ) {
			return $post;
		}

		if ( ! isset( $input['page_template'] ) ) {
			return new WP_Error(
				'missing_page_template',
				__( 'Provide a page_template slug. Read the post first to see which templates the active theme offers.', 'mosmcp-abilities' )
			);
		}

		$requested = sanitize_text_field( (string) $input['page_template'] );
		if ( '' === $requested ) {
			$requested = self::DEFAULT_TEMPLATE;
		}

		$available = self::available_templates( $post );

		/*
		 * Refuse an unknown slug rather than storing it. WordPress silently falls
		 * back to the default template for a slug the theme does not register, so
		 * accepting it would report success while the post renders unchanged.
		 */
		if ( self::DEFAULT_TEMPLATE !== $requested && ! isset( $available[ $requested ] ) ) {
			return new WP_Error(
				'unknown_page_template',
				sprintf(
					/* translators: 1: requested template slug, 2: comma-separated list of valid slugs */
					__( 'The active theme and its plugins do not register a page template called "%1$s", and WordPress would silently render the default instead. Available templates: %2$s.', 'mosmcp-abilities' ),
					$requested,
					implode( ', ', array_merge( array( self::DEFAULT_TEMPLATE ), array_keys( $available ) ) )
				)
			);
		}

		$previous = (string) get_post_meta( $post->ID, '_wp_page_template', true );
		if ( '' === $previous ) {
			$previous = self::DEFAULT_TEMPLATE;
		}

		if ( self::DEFAULT_TEMPLATE === $requested ) {
			delete_post_meta( $post->ID, '_wp_page_template' );
		} else {
			update_post_meta( $post->ID, '_wp_page_template', wp_slash( $requested ) );
		}

		$stored = (string) get_post_meta( $post->ID, '_wp_page_template', true );
		if ( '' === $stored ) {
			$stored = self::DEFAULT_TEMPLATE;
		}

		$warnings = array();
		if ( $stored !== $requested ) {
			$warnings[] = array(
				'code'    => 'template_not_stored',
				'message' => __( 'The template was written but reads back as a different value, which usually means a plugin is filtering it.', 'mosmcp-abilities' ),
				'context' => 'requested=' . $requested . ' stored=' . $stored,
			);
		}

		return array(
			'id'            => (int) $post->ID,
			'previous'      => $previous,
			'current'       => $stored,
			'label'         => isset( $available[ $stored ] ) ? $available[ $stored ] : '',
			'changed'       => $previous !== $stored,
			'view_url'      => (string) get_permalink( $post->ID ),
			'warnings'      => $warnings,
		);
	}

	/**
	 * Sets or clears a post's featured image.
	 *
	 * @param array<string, mixed> $input     Ability input.
	 * @param string               $post_type Post type this ability is scoped to.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function set_featured_image( array $input, $post_type ) {
		$post = self::require_post( $input, $post_type );
		if ( $post instanceof WP_Error ) {
			return $post;
		}

		if ( ! array_key_exists( 'attachment_id', $input ) ) {
			return new WP_Error(
				'missing_attachment_id',
				__( 'Provide an attachment_id to set as the featured image, or 0 to remove the current one.', 'mosmcp-abilities' )
			);
		}

		$previous      = (int) get_post_thumbnail_id( $post->ID );
		$attachment_id = absint( $input['attachment_id'] );

		if ( 0 === $attachment_id ) {
			delete_post_meta( $post->ID, '_thumbnail_id' );
			return self::featured_result( $post, $previous, 0 );
		}

		$attachment = get_post( $attachment_id );
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return new WP_Error(
				'attachment_not_found',
				sprintf(
					/* translators: %d: attachment ID */
					__( 'No media library item found with ID %d. Find an image first, then pass its ID.', 'mosmcp-abilities' ),
					$attachment_id
				)
			);
		}

		/*
		 * Trust the recorded MIME type first and only fall back to
		 * wp_attachment_is_image(). That helper resolves the attachment's local
		 * file and returns false as soon as _wp_attached_file is missing, so on
		 * its own it rejects images that are genuinely images but whose files live
		 * elsewhere — anything served through an offload or CDN plugin, and media
		 * carried over by an importer that did not write that row.
		 */
		$mime = (string) get_post_mime_type( $attachment_id );
		if ( 0 !== strpos( $mime, 'image/' ) && ! wp_attachment_is_image( $attachment_id ) ) {
			return new WP_Error(
				'attachment_not_an_image',
				sprintf(
					/* translators: 1: attachment ID, 2: MIME type */
					__( 'Media item %1$d is not an image (its type is "%2$s"), and a featured image must be one.', 'mosmcp-abilities' ),
					$attachment_id,
					'' !== $mime ? $mime : __( 'unknown', 'mosmcp-abilities' )
				)
			);
		}

		/*
		 * Deliberately not set_post_thumbnail(): it calls wp_get_attachment_image()
		 * and, when that returns nothing (an attachment row whose file or image
		 * metadata is missing), DELETES _thumbnail_id instead of setting it and
		 * still returns a value the caller reads as success. Writing the row
		 * directly after validating the attachment means a broken image produces a
		 * warning the caller can act on rather than a silent no-op.
		 */
		update_post_meta( $post->ID, '_thumbnail_id', (int) $attachment_id );

		$result = self::featured_result( $post, $previous, $attachment_id );

		if ( ! wp_get_attachment_image( $attachment_id, 'thumbnail' ) ) {
			$result['warnings'][] = array(
				'code'    => 'attachment_file_missing',
				'message' => __( 'The featured image was assigned, but WordPress cannot render this attachment — its underlying file or image metadata is missing, so it may not display.', 'mosmcp-abilities' ),
				'context' => 'attachment_id=' . $attachment_id,
			);
		}

		return $result;
	}

	/**
	 * Builds the featured-image result payload.
	 *
	 * @param WP_Post $post     Target post.
	 * @param int     $previous Previous attachment ID.
	 * @param int     $current  New attachment ID.
	 * @return array<string, mixed>
	 */
	private static function featured_result( WP_Post $post, $previous, $current ) {
		return array(
			'id'           => (int) $post->ID,
			'previous_id'  => (int) $previous,
			'current_id'   => (int) $current,
			'changed'      => (int) $previous !== (int) $current,
			'image_url'    => $current > 0 ? (string) wp_get_attachment_image_url( $current, 'full' ) : '',
			'alt_text'     => $current > 0 ? (string) get_post_meta( $current, '_wp_attachment_image_alt', true ) : '',
			'edit_url'     => (string) get_edit_post_link( $post->ID, 'raw' ),
			'warnings'     => array(),
		);
	}

	/**
	 * Page templates the active theme and its plugins register for this post.
	 *
	 * WP_Theme::get_page_templates() applies the theme_page_templates filter, so
	 * templates contributed by plugins — Elementor's Canvas, Full Width and Theme
	 * entries among them — are included without being named here.
	 *
	 * @param WP_Post $post Target post.
	 * @return array<string, string> Slug => label.
	 */
	private static function available_templates( WP_Post $post ) {
		$templates = wp_get_theme()->get_page_templates( $post, $post->post_type );
		return is_array( $templates ) ? $templates : array();
	}

	/**
	 * Shapes the template map into a list for the output schema.
	 *
	 * @param array<string, string> $templates Slug => label.
	 * @return array<int, array<string, string>>
	 */
	private static function template_list( array $templates ) {
		$out = array(
			array(
				'slug'  => self::DEFAULT_TEMPLATE,
				'label' => __( 'Default template', 'mosmcp-abilities' ),
			),
		);
		foreach ( $templates as $slug => $label ) {
			$out[] = array(
				'slug'  => (string) $slug,
				'label' => (string) $label,
			);
		}
		return $out;
	}

	/**
	 * Meta rows whose key suggests they affect layout.
	 *
	 * @param int $post_id Post ID.
	 * @return array<int, array<string, string>>
	 */
	private static function layout_meta( $post_id ) {
		$out = array();

		foreach ( get_post_meta( $post_id ) as $key => $rows ) {
			$key = (string) $key;
			if ( '_wp_page_template' === $key || 0 === strpos( $key, '_elementor' ) ) {
				continue;
			}
			if ( ! self::looks_like_layout_key( $key ) ) {
				continue;
			}

			$values = array_values( (array) $rows );
			$value  = maybe_unserialize( isset( $values[0] ) ? $values[0] : '' );
			$out[]  = array(
				'key'   => $key,
				'value' => self::summarize( $value ),
			);
		}

		return $out;
	}

	/**
	 * Whether a meta key matches the layout-reporting heuristic.
	 *
	 * @param string $key Meta key.
	 * @return bool
	 */
	private static function looks_like_layout_key( $key ) {
		$needle = strtolower( $key );
		foreach ( self::LAYOUT_KEY_HINTS as $hint ) {
			if ( false !== strpos( $needle, $hint ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Renders a meta value as a short display string.
	 *
	 * @param mixed $value Meta value.
	 * @return string
	 */
	private static function summarize( $value ) {
		if ( is_scalar( $value ) || null === $value ) {
			$text = (string) $value;
		} else {
			$text = (string) wp_json_encode( $value );
		}
		if ( strlen( $text ) > 200 ) {
			return substr( $text, 0, 197 ) . '...';
		}
		return $text;
	}

	/**
	 * Warns when a content edit will not be visible because Elementor renders the post.
	 *
	 * A post built in Elementor renders from its stored layout, not from
	 * post_content. Writing post_content on such a post succeeds, changes the value,
	 * and changes nothing a visitor sees — which reads as "I updated the article and
	 * nothing happened". The update is still correct to perform, because post_content
	 * is what feeds, search indexing and non-Elementor contexts read, so this is a
	 * warning rather than a refusal.
	 *
	 * @param int      $post_id Post ID.
	 * @param string[] $changed Fields that were changed.
	 * @return array<int, array<string, string>> Zero or one warning.
	 */
	public static function content_visibility_warnings( $post_id, array $changed ) {
		if ( ! in_array( 'content', $changed, true ) ) {
			return array();
		}

		$post_id = (int) $post_id;

		if ( '' === (string) get_post_meta( $post_id, '_elementor_data', true ) ) {
			return array();
		}
		if ( 'builder' !== (string) get_post_meta( $post_id, '_elementor_edit_mode', true ) ) {
			return array();
		}

		return array(
			array(
				'code'    => 'content_hidden_by_elementor',
				'message' => __( 'The content was saved, but this post is built in Elementor, so visitors see the Elementor layout instead of the post content and the page will look unchanged. To change what people actually read, edit the text on the Elementor elements themselves — read the layout, then set the content of the element holding the text.', 'mosmcp-abilities' ),
				'context' => 'post_id=' . $post_id,
			),
		);
	}

	/**
	 * Resolves and validates the target post.
	 *
	 * @param array<string, mixed> $input     Ability input.
	 * @param string               $post_type Expected post type.
	 * @return WP_Post|WP_Error
	 */
	private static function require_post( array $input, $post_type ) {
		$id   = isset( $input['id'] ) ? absint( $input['id'] ) : 0;
		$post = $id > 0 ? get_post( $id ) : null;

		if ( ! $post || $post_type !== $post->post_type ) {
			return new WP_Error(
				'post_not_found',
				sprintf(
					/* translators: %s: post type slug */
					__( 'No %s found with that ID.', 'mosmcp-abilities' ),
					$post_type
				)
			);
		}

		return $post;
	}
}
