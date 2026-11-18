<?php
/**
 * Rendering an Elementor layout to the markup a visitor would actually receive.
 *
 * This closes the loop that schema validation cannot. Validating a tree proves the
 * data is well formed; it says nothing about whether the page reads correctly. With
 * this, a caller can write a change, look at the result, and correct it — the same
 * loop a person has in the editor.
 *
 * Rendering runs Elementor's real front-end pipeline, which means shortcodes and
 * theme hooks execute. That is the point: the output is what the site will serve.
 * It also means the work is bounded here — output is capped, the buffer is always
 * released even when a widget throws, and the global post state is restored
 * afterwards so nothing downstream inherits a half-set-up loop.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Elementor;

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
 * Class Elementor_Renderer
 */
class Elementor_Renderer {

	/**
	 * Default and maximum output size, in bytes.
	 *
	 * A rendered page routinely runs to tens of kilobytes, and a long one to
	 * hundreds. The default keeps a whole-page render usable in a single response;
	 * the ceiling stops one call from swallowing a caller's entire context.
	 *
	 * @var int
	 */
	const DEFAULT_MAX_BYTES = 120000;
	const LIMIT_MAX_BYTES   = 500000;

	/**
	 * Renders a whole post, or one element within it.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function render( array $input ) {
		if ( ! Elementor_Schema::available() ) {
			return Elementor_Schema::unavailable_error();
		}

		$post = Elementor_Document::require_post( isset( $input['id'] ) ? $input['id'] : 0 );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$tree = Elementor_Document::tree( (int) $post->ID );
		if ( is_wp_error( $tree ) ) {
			return $tree;
		}

		$element_id  = isset( $input['element_id'] ) ? trim( (string) $input['element_id'] ) : '';
		$format      = isset( $input['format'] ) && 'text' === $input['format'] ? 'text' : 'html';
		$include_css = ! empty( $input['include_css'] );

		$max_bytes = isset( $input['max_bytes'] ) ? absint( $input['max_bytes'] ) : self::DEFAULT_MAX_BYTES;
		$max_bytes = max( 1000, min( $max_bytes, self::LIMIT_MAX_BYTES ) );

		$warnings = array();

		if ( '' !== $element_id ) {
			$rendered = self::render_element( $post, $tree, $element_id, $warnings );
		} else {
			$rendered = self::render_post( $post, $include_css, $warnings );
		}

		if ( is_wp_error( $rendered ) ) {
			return $rendered;
		}

		$raw = (string) $rendered;

		$element_ids = self::element_ids_in( $raw );

		if ( 'text' === $format ) {
			$body = self::to_text( $raw );
		} else {
			$body = $raw;
		}

		$full_bytes = strlen( $body );
		$truncated  = false;

		if ( $full_bytes > $max_bytes ) {
			$body      = substr( $body, 0, $max_bytes );
			$truncated = true;
			$warnings[] = array(
				'code'    => 'output_truncated',
				'message' => sprintf(
					/* translators: 1: returned bytes, 2: full bytes */
					__( 'The rendered output was cut off at %1$s of %2$s bytes. Render a single element, or raise max_bytes, to see the rest.', 'mosmcp-abilities' ),
					number_format_i18n( $max_bytes ),
					number_format_i18n( $full_bytes )
				),
				'context' => 'max_bytes=' . $max_bytes,
			);
		}

		if ( '' === trim( wp_strip_all_tags( $raw ) ) && '' !== $raw ) {
			$warnings[] = array(
				'code'    => 'rendered_markup_only',
				'message' => __( 'The layout rendered markup but no visible text. That is normal for a purely decorative element, and a sign of missing content otherwise.', 'mosmcp-abilities' ),
				'context' => 'bytes=' . strlen( $raw ),
			);
		}

		if ( '' === $raw ) {
			$warnings[] = array(
				'code'    => 'rendered_empty',
				'message' => __( 'Elementor produced no output for this request. If the post has layout data, check that its builder edit mode is set.', 'mosmcp-abilities' ),
				'context' => 'post_id=' . $post->ID,
			);
		}

		return array(
			'id'                  => (int) $post->ID,
			'title'               => (string) get_the_title( $post ),
			'element_id'          => $element_id,
			'scope'               => '' !== $element_id ? 'element' : 'post',
			'format'              => $format,
			'output'              => $body,
			'bytes'               => strlen( $body ),
			'full_bytes'          => $full_bytes,
			'truncated'           => $truncated,
			'includes_css'        => $include_css && '' === $element_id,
			'rendered_element_ids' => $element_ids,
			'view_url'            => (string) get_permalink( $post->ID ),
			'warnings'            => $warnings,
		);
	}

	/**
	 * Renders the whole post through Elementor's front-end pipeline.
	 *
	 * @param WP_Post              $post        Target post.
	 * @param bool                 $include_css Whether to include the generated CSS.
	 * @param array<int, mixed>    $warnings    Warnings accumulator, modified in place.
	 * @return string|WP_Error
	 */
	private static function render_post( WP_Post $post, $include_css, array &$warnings ) {
		$frontend = \Elementor\Plugin::$instance->frontend;

		if ( ! method_exists( $frontend, 'get_builder_content' ) ) {
			return new WP_Error(
				'render_unsupported',
				__( 'This version of Elementor does not expose a way to render a layout outside the browser.', 'mosmcp-abilities' )
			);
		}

		/*
		 * Widgets and theme hooks read the global post, so it is set up for the
		 * duration of the render and restored afterwards. Without this, a widget
		 * that calls the_title() or a conditional hook renders against whatever
		 * post happened to be current.
		 */
		$previous        = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;
		$GLOBALS['post'] = $post;
		setup_postdata( $post );

		$html = '';

		try {
			$html = (string) $frontend->get_builder_content( (int) $post->ID, (bool) $include_css );
		} catch ( \Throwable $e ) {
			$warnings[] = array(
				'code'    => 'render_error',
				'message' => __( 'Something in the layout raised an error while rendering, so the output below may be incomplete.', 'mosmcp-abilities' ),
				'context' => $e->getMessage(),
			);
		} finally {
			wp_reset_postdata();
			$GLOBALS['post'] = $previous;
		}

		return $html;
	}

	/**
	 * Renders one element on its own.
	 *
	 * Useful after editing a single widget: the response is small enough to read in
	 * full, rather than a whole page to search through.
	 *
	 * @param WP_Post           $post       Target post.
	 * @param array<int, mixed> $tree       Decoded tree.
	 * @param string            $element_id Element to render.
	 * @param array<int, mixed> $warnings   Warnings accumulator, modified in place.
	 * @return string|WP_Error
	 */
	private static function render_element( WP_Post $post, array $tree, $element_id, array &$warnings ) {
		$path = Elementor_Write_Engine::resolve_one( $tree, $element_id );
		if ( is_wp_error( $path ) ) {
			return $path;
		}

		$node = Elementor_Write_Engine::node_at( $tree, $path );
		if ( ! is_array( $node ) ) {
			return new WP_Error( 'element_not_found', __( 'The element could not be read from the layout.', 'mosmcp-abilities' ) );
		}

		$manager = \Elementor\Plugin::$instance->elements_manager;
		if ( ! method_exists( $manager, 'create_element_instance' ) ) {
			return new WP_Error(
				'render_unsupported',
				__( 'This version of Elementor does not expose a way to render a single element.', 'mosmcp-abilities' )
			);
		}

		$previous        = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;
		$GLOBALS['post'] = $post;
		setup_postdata( $post );

		$html    = '';
		$buffered = false;

		try {
			$instance = $manager->create_element_instance( $node );

			if ( ! $instance ) {
				return new WP_Error(
					'element_not_renderable',
					sprintf(
						/* translators: %s: widget slug */
						__( 'Elementor could not build a renderable instance of this "%s" element. The widget or addon that provides it may be deactivated.', 'mosmcp-abilities' ),
						isset( $node['widgetType'] ) ? (string) $node['widgetType'] : (string) $node['elType']
					)
				);
			}

			ob_start();
			$buffered = true;
			$instance->print_element();
			$html     = (string) ob_get_clean();
			$buffered = false;
		} catch ( \Throwable $e ) {
			$warnings[] = array(
				'code'    => 'render_error',
				'message' => __( 'This element raised an error while rendering, so the output below may be incomplete.', 'mosmcp-abilities' ),
				'context' => $e->getMessage(),
			);
		} finally {
			// Never leave a buffer open: an unbalanced buffer swallows everything
			// written after it, including the response itself.
			if ( $buffered ) {
				ob_end_clean();
			}
			wp_reset_postdata();
			$GLOBALS['post'] = $previous;
		}

		return $html;
	}

	/**
	 * Reduces rendered markup to readable text.
	 *
	 * @param string $html Rendered HTML.
	 * @return string
	 */
	private static function to_text( $html ) {
		// Drop script and style bodies outright; their contents are not content.
		$html = preg_replace( '#<(script|style)\b[^>]*>.*?</\1>#is', ' ', (string) $html );

		// Turn block boundaries into line breaks so the text keeps its structure.
		$html = preg_replace( '#</(p|div|section|h[1-6]|li|tr|blockquote)>#i', "\n", (string) $html );
		$html = preg_replace( '#<br\s*/?>#i', "\n", (string) $html );

		$text = wp_strip_all_tags( (string) $html );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// Collapse runs of blank space but keep paragraph separation.
		$text = preg_replace( '/[ \t]+/', ' ', $text );
		$text = preg_replace( '/\n{3,}/', "\n\n", $text );
		$text = preg_replace( '/^[ \t]+|[ \t]+$/m', '', $text );

		return trim( (string) $text );
	}

	/**
	 * Element IDs present in the rendered markup.
	 *
	 * Elementor stamps every element with data-id, so this maps the output back to
	 * the IDs the editing abilities take — a caller can confirm the element it just
	 * changed is the one that appeared.
	 *
	 * @param string $html Rendered HTML.
	 * @return string[]
	 */
	private static function element_ids_in( $html ) {
		if ( ! preg_match_all( '/data-id="([0-9a-zA-Z_-]+)"/', (string) $html, $matches ) ) {
			return array();
		}

		return array_values( array_unique( $matches[1] ) );
	}
}
