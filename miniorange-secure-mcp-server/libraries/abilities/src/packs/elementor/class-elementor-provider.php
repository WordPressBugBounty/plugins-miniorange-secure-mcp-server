<?php
/**
 * Execute callbacks for the Elementor read abilities.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Elementor;

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
 * Class Elementor_Provider
 */
class Elementor_Provider {

	/**
	 * Default/max "limit" for mosmcp/elementor-list-widget-types. Named so the
	 * runtime clamp below and the ability's input_schema (class-elementor-pack.php)
	 * can't silently drift from each other.
	 */
	const WIDGET_LIST_DEFAULT_LIMIT = 100;
	const WIDGET_LIST_MAX_LIMIT     = 300;

	/**
	 * Reads a post's Elementor layout as a flat, content-focused element list.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function page_read( $input = array() ) {
		$input = (array) $input;

		if ( ! Elementor_Schema::available() ) {
			return Elementor_Schema::unavailable_error();
		}

		$post = Elementor_Document::require_post( isset( $input['id'] ) ? $input['id'] : 0 );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$state = Elementor_Document::state( (int) $post->ID );

		$tree = Elementor_Document::tree( (int) $post->ID );
		if ( is_wp_error( $tree ) ) {
			/*
			 * A post with no layout is a legitimate answer to "read this post's
			 * layout", not a failure — the caller needs to know it is a block-editor
			 * post so it can stop looking. Genuine failures (oversized or corrupt
			 * data) still surface as errors.
			 */
			if ( 'no_elementor_data' === $tree->get_error_code() ) {
				return array(
					'id'              => (int) $post->ID,
					'post_type'       => (string) $post->post_type,
					'title'           => (string) get_the_title( $post ),
					'elementor'       => $state,
					'nodes'           => array(),
					'returned'        => 0,
					'matched'         => 0,
					'total_elements'  => 0,
					'has_more'        => false,
					'has_atomic'      => false,
					'element_counts'  => array(),
					'body_element_id' => '',
					'warnings'        => array(
						array(
							'code'    => 'no_elementor_data',
							'message' => $tree->get_error_message(),
							'context' => 'post_id=' . $post->ID,
						),
					),
				);
			}
			return $tree;
		}

		$summary = Elementor_Document::summarize(
			$tree,
			array(
				'limit'       => isset( $input['limit'] ) ? $input['limit'] : null,
				'offset'      => isset( $input['offset'] ) ? $input['offset'] : 0,
				'max_depth'   => isset( $input['max_depth'] ) ? $input['max_depth'] : null,
				'widget_type' => isset( $input['widget_type'] ) ? $input['widget_type'] : '',
				'search'      => isset( $input['search'] ) ? $input['search'] : '',
			)
		);

		$warnings = array();

		if ( $summary['has_atomic'] ) {
			$warnings[] = array(
				'code'    => 'contains_atomic_elements',
				'message' => __( 'This layout contains Elementor v4 atomic elements. They are listed and readable; editing is supported for the mapped atomic widget types only.', 'mosmcp-abilities' ),
				'context' => 'has_atomic=true',
			);
		}

		if ( $summary['has_more'] ) {
			$warnings[] = array(
				'code'    => 'more_elements_available',
				'message' => __( 'More elements matched than were returned. Increase limit, or page through with offset.', 'mosmcp-abilities' ),
				'context' => sprintf( 'returned=%d matched=%d', $summary['returned'], $summary['matched'] ),
			);
		}

		if ( '' === $state['edit_mode'] && $summary['total_elements'] > 0 ) {
			$warnings[] = array(
				'code'    => 'edit_mode_not_set',
				'message' => __( 'This post has Elementor layout data but is not switched into Elementor builder mode, so WordPress renders the post content instead of the layout and a preview will come back empty. Opening it in Elementor and saving once fixes it, as does writing the layout with the layout-write ability, which sets the mode.', 'mosmcp-abilities' ),
				'context' => '_elementor_edit_mode is empty',
			);
		}

		$result = array_merge(
			array(
				'id'        => (int) $post->ID,
				'post_type' => (string) $post->post_type,
				'title'     => (string) get_the_title( $post ),
				'elementor' => $state,
			),
			$summary,
			array(
				'body_element_id' => self::guess_body_element( $summary['nodes'] ),
				'warnings'        => $warnings,
			)
		);

		/*
		 * The summarised node list is what a caller wants almost always, but it
		 * deliberately drops styling — which makes it useless as input to the
		 * layout-write ability. Without a way to obtain the stored tree, that
		 * escape hatch cannot be used to restructure an existing layout at all, so
		 * the exact tree is available on request.
		 */
		if ( ! empty( $input['include_raw'] ) ) {
			$result['raw_elements'] = $tree;
		}

		return $result;
	}

	/**
	 * Which element most likely holds the article body.
	 *
	 * A layout has no notion of "the body": there is only a set of widgets, and an
	 * article's prose usually sits in one of possibly several text editors, next to
	 * a byline, a pull quote or a call to action. Without a hint, a caller wanting to
	 * rewrite the body has to guess, and guessing wrong overwrites the wrong widget.
	 *
	 * The longest run of text is a better signal than document order, because the
	 * shorter text widgets in an article template are the decorative ones. It is a
	 * hint and nothing more — the element IDs are all listed, so a caller that
	 * disagrees can pick another.
	 *
	 * @param array<int, array<string, mixed>> $nodes Summarised nodes.
	 * @return string Element ID, or an empty string when nothing looks like prose.
	 */
	private static function guess_body_element( array $nodes ) {
		$best    = '';
		$longest = 0;

		foreach ( $nodes as $node ) {
			$type = isset( $node['widget_type'] ) ? (string) $node['widget_type'] : '';

			if ( ! in_array( $type, array( 'text-editor', 'theme-post-content', 'paragraph', 'html-v3' ), true ) ) {
				continue;
			}

			$content = isset( $node['content'] ) && is_array( $node['content'] ) ? $node['content'] : array();
			$text    = '';
			foreach ( array( 'text', 'body' ) as $role ) {
				if ( isset( $content[ $role ]['value'] ) && is_scalar( $content[ $role ]['value'] ) ) {
					$text .= (string) $content[ $role ]['value'];
				}
			}

			$length = strlen( wp_strip_all_tags( $text ) );
			if ( $length > $longest ) {
				$longest = $length;
				$best    = isset( $node['element_id'] ) ? (string) $node['element_id'] : '';
			}
		}

		return $best;
	}

	/**
	 * Changes the text, image or link of one element in an Elementor layout.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function element_set_content( $input = array() ) {
		$input = (array) $input;

		return Elementor_Write_Engine::run( $input, Elementor_Content_Writer::mutator( $input ) );
	}

	/**
	 * Changes selected visual styling properties on one element.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function element_set_style( $input = array() ) {
		$input = (array) $input;

		return Elementor_Write_Engine::run( $input, Elementor_Style_Writer::mutator( $input ) );
	}

	/**
	 * Deletes one element and everything nested inside it.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function element_delete( $input = array() ) {
		$input = (array) $input;

		return Elementor_Write_Engine::run( $input, Elementor_Structure_Writer::delete_mutator( $input ) );
	}

	/**
	 * Duplicates one element in place, giving the copy fresh element IDs.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function element_duplicate( $input = array() ) {
		$input = (array) $input;

		return Elementor_Write_Engine::run( $input, Elementor_Structure_Writer::duplicate_mutator( $input ) );
	}

	/**
	 * Reorders an element among its siblings, or moves it into another container.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function element_move( $input = array() ) {
		$input = (array) $input;

		return Elementor_Write_Engine::run( $input, Elementor_Structure_Writer::move_mutator( $input ) );
	}

	/**
	 * Replaces a post's whole Elementor layout with a supplied element tree.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function page_write( $input = array() ) {
		$input = (array) $input;

		$result = Elementor_Write_Engine::run(
			$input,
			Elementor_Tree_Writer::mutator( $input ),
			array( 'allow_missing_tree' => true )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		/*
		 * Run after the save: Document::save() writes its own Elementor meta, so
		 * the edit mode is confirmed once the layout is actually in place.
		 */
		$set_mode           = ! isset( $input['set_edit_mode'] ) || (bool) $input['set_edit_mode'];
		$result['warnings'] = array_merge(
			$result['warnings'],
			Elementor_Tree_Writer::ensure_renderable( (int) $result['id'], $set_mode )
		);

		return $result;
	}

	/**
	 * Renders the layout, or one element of it, as a visitor would receive it.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function render_preview( $input = array() ) {
		return Elementor_Renderer::render( (array) $input );
	}

	/**
	 * Lists the widget and container types registered on this site.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function list_widget_types( $input = array() ) {
		$input = (array) $input;

		if ( ! Elementor_Schema::available() ) {
			return Elementor_Schema::unavailable_error();
		}

		$all = Elementor_Schema::widget_types(
			array(
				'search'      => isset( $input['search'] ) ? $input['search'] : '',
				'category'    => isset( $input['category'] ) ? $input['category'] : '',
				'atomic_only' => ! empty( $input['atomic_only'] ),
			)
		);

		$limit  = isset( $input['limit'] ) ? absint( $input['limit'] ) : self::WIDGET_LIST_DEFAULT_LIMIT;
		$limit  = max( 1, min( $limit, self::WIDGET_LIST_MAX_LIMIT ) );
		$offset = isset( $input['offset'] ) ? absint( $input['offset'] ) : 0;

		$page       = array_slice( $all, $offset, $limit );
		$categories = array();
		foreach ( $all as $entry ) {
			foreach ( $entry['categories'] as $cat ) {
				$categories[ $cat ] = true;
			}
		}
		ksort( $categories );

		return array(
			'elementor_version' => Elementor_Schema::version(),
			'pro_active'        => Elementor_Schema::pro_available(),
			'total'             => count( $all ),
			'returned'          => count( $page ),
			'has_more'          => count( $all ) > ( $offset + count( $page ) ),
			'categories'        => array_keys( $categories ),
			'widgets'           => $page,
		);
	}

	/**
	 * Reports the controls a widget accepts.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function widget_schema( $input = array() ) {
		$input = (array) $input;

		if ( ! Elementor_Schema::available() ) {
			return Elementor_Schema::unavailable_error();
		}

		$slug = isset( $input['widget_type'] ) ? sanitize_text_field( (string) $input['widget_type'] ) : '';
		if ( '' === $slug ) {
			return new WP_Error(
				'missing_widget_type',
				__( 'Provide a widget_type slug. List the available widget types first to see valid slugs.', 'mosmcp-abilities' )
			);
		}

		$names = array();
		if ( isset( $input['names'] ) ) {
			$raw   = is_array( $input['names'] ) ? $input['names'] : explode( ',', (string) $input['names'] );
			$names = array_values( array_filter( array_map( 'trim', array_map( 'strval', $raw ) ) ) );
		}

		$prefix = isset( $input['prefix'] ) ? trim( (string) $input['prefix'] ) : '';
		$mode   = Elementor_Schema::resolve_mode( $names, $prefix );

		return Elementor_Schema::describe( $slug, $mode, $names, $prefix );
	}
}
