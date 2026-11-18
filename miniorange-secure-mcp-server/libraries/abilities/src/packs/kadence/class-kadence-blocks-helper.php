<?php
/**
 * Block-tree engine shared by every Kadence block ability.
 *
 * All Kadence content lives as serialized Gutenberg block markup in post_content.
 * The safe way to read or change it is to parse_blocks() into a tree, operate on
 * the tree, and serialize_blocks() back: untouched blocks round-trip losslessly,
 * so an edit that rewrites only its target leaves every other block's responsive
 * settings and styling exactly as they were. This class owns that round-trip plus
 * the traversal, lookup, summarization, and uniqueID primitives the abilities use.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Kadence;

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
 * Class Kadence_Blocks_Helper
 */
class Kadence_Blocks_Helper {

	/**
	 * Prefix every Kadence block name carries.
	 */
	const PREFIX = 'kadence/';

	/**
	 * Resolves the target post from input['id'] and confirms it exists.
	 *
	 * Authorization is enforced by each ability's permission_callback; this only
	 * validates existence so callbacks can return a clean domain error.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return WP_Post|WP_Error
	 */
	public static function require_post( $input ) {
		$id   = isset( $input['id'] ) ? absint( $input['id'] ) : 0;
		$post = $id > 0 ? get_post( $id ) : null;

		if ( ! $post instanceof WP_Post ) {
			return new WP_Error( 'post_not_found', __( 'No page or post found with the given id.', 'mosmcp-abilities' ) );
		}

		return $post;
	}

	/**
	 * Resolves the target post from input by 'id' or, failing that, a 'search' title.
	 *
	 * For read abilities only — writes stay id-only so an edit can never land on the
	 * wrong page. When 'search' resolves to several posts the caller gets an
	 * ambiguous-match error listing the candidates so it can retry with a specific id.
	 *
	 * @param array<string, mixed> $input Ability input (accepts 'id' and/or 'search').
	 * @return WP_Post|WP_Error
	 */
	public static function resolve_post( $input ) {
		$id = isset( $input['id'] ) ? absint( $input['id'] ) : 0;
		if ( $id > 0 ) {
			return self::require_post( $input );
		}

		$search = isset( $input['search'] ) ? trim( (string) $input['search'] ) : '';
		if ( '' === $search ) {
			return new WP_Error( 'missing_target', __( 'Provide either "id" or "search" (a page title).', 'mosmcp-abilities' ) );
		}

		$matches = self::search_posts( $search );

		if ( empty( $matches ) ) {
			return new WP_Error( 'post_not_found', __( 'No page or post title matched the search.', 'mosmcp-abilities' ) );
		}
		if ( count( $matches ) > 1 ) {
			$list = array();
			foreach ( $matches as $m ) {
				$list[] = $m['id'] . ' (' . $m['title'] . ')';
			}
			return new WP_Error(
				'ambiguous_search',
				sprintf(
					/* translators: 1: search term, 2: candidate list "id (title)". */
					__( 'Several pages match "%1$s". Re-call with a specific id — candidates: %2$s.', 'mosmcp-abilities' ),
					$search,
					implode( '; ', $list )
				),
				array( 'matches' => $matches )
			);
		}

		$post = get_post( $matches[0]['id'] );
		return $post instanceof WP_Post ? $post : new WP_Error( 'post_not_found', __( 'The matched post could not be loaded.', 'mosmcp-abilities' ) );
	}

	/**
	 * Finds up to 10 posts by title (exact first, then broadened), across pages and posts.
	 *
	 * @param string $search Title or partial title.
	 * @return array<int, array{id:int,title:string,status:string}>
	 */
	private static function search_posts( $search ) {
		$base = array(
			'post_type'      => array( 'page', 'post' ),
			'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
			'posts_per_page' => 10,
			'no_found_rows'  => true,
		);

		$query = new \WP_Query( array_merge( $base, array( 'title' => $search ) ) );
		$posts = $query->posts;

		if ( empty( $posts ) ) {
			$query = new \WP_Query( array_merge( $base, array( 's' => $search ) ) );
			$posts = $query->posts;
		}

		$out = array();
		foreach ( $posts as $post ) {
			$out[] = array(
				'id'     => (int) $post->ID,
				'title'  => (string) get_the_title( $post ),
				'status' => (string) get_post_status( $post ),
			);
		}

		return $out;
	}

	/**
	 * Parses a post's content into a block tree, dropping the empty "whitespace"
	 * nodes parse_blocks() emits between blocks.
	 *
	 * @param WP_Post $post Post whose content to parse.
	 * @return array<int, array<string, mixed>> Block nodes.
	 */
	public static function parse_post( WP_Post $post ) {
		return self::strip_empty( parse_blocks( (string) $post->post_content ) );
	}

	/**
	 * Serializes a block tree back to post_content markup.
	 *
	 * @param array<int, array<string, mixed>> $blocks Block tree.
	 * @return string
	 */
	public static function serialize( array $blocks ) {
		return serialize_blocks( $blocks );
	}

	/**
	 * Whether a parsed block is a Kadence block.
	 *
	 * @param array<string, mixed> $block Parsed block node.
	 * @return bool
	 */
	public static function is_kadence( array $block ) {
		return isset( $block['blockName'] ) && is_string( $block['blockName'] )
			&& 0 === strpos( $block['blockName'], self::PREFIX );
	}

	/**
	 * The uniqueID attribute Kadence assigns each block, or '' when absent.
	 *
	 * @param array<string, mixed> $block Parsed block node.
	 * @return string
	 */
	public static function unique_id( array $block ) {
		return isset( $block['attrs']['uniqueID'] ) ? (string) $block['attrs']['uniqueID'] : '';
	}

	/**
	 * Finds the first block matching a uniqueID anywhere in the tree.
	 *
	 * @param array<int, array<string, mixed>> $blocks Block tree.
	 * @param string                           $unique_id Target uniqueID.
	 * @return array<string, mixed>|null The matched block node (a copy), or null.
	 */
	public static function find_by_unique_id( array $blocks, $unique_id ) {
		$unique_id = (string) $unique_id;
		$found     = null;

		self::walk(
			$blocks,
			static function ( $block ) use ( $unique_id, &$found ) {
				if ( null === $found && self::unique_id( $block ) === $unique_id && '' !== $unique_id ) {
					$found = $block;
				}
			}
		);

		return $found;
	}

	/**
	 * Depth-first visits every block in the tree, passing each node and its depth.
	 *
	 * @param array<int, array<string, mixed>> $blocks   Block tree.
	 * @param callable                         $callback Receives ( array $block, int $depth ).
	 * @param int                              $depth    Current depth (internal).
	 * @return void
	 */
	public static function walk( array $blocks, callable $callback, $depth = 0 ) {
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$callback( $block, $depth );
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				self::walk( $block['innerBlocks'], $callback, $depth + 1 );
			}
		}
	}

	/**
	 * Builds a compact, nested outline of a block tree for read abilities.
	 *
	 * Each node reports its block name, uniqueID, a short text excerpt, its child
	 * count, and its children (recursively). Non-Kadence blocks are included so the
	 * outline reflects the true page structure, but flagged via the "kadence" key.
	 *
	 * @param array<int, array<string, mixed>> $blocks    Block tree.
	 * @param bool                             $kadence_only Restrict the outline to Kadence blocks.
	 * @return array<int, array<string, mixed>>
	 */
	public static function outline( array $blocks, $kadence_only = false ) {
		$out = array();

		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) || empty( $block['blockName'] ) ) {
				continue;
			}

			$is_kadence = self::is_kadence( $block );
			$children   = ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] )
				? self::outline( $block['innerBlocks'], $kadence_only )
				: array();

			if ( $kadence_only && ! $is_kadence && empty( $children ) ) {
				continue;
			}

			$out[] = array(
				'block'      => (string) $block['blockName'],
				'unique_id'  => self::unique_id( $block ),
				'kadence'    => $is_kadence,
				'text'       => self::text_excerpt( $block ),
				'child_count' => count( $children ),
				'children'   => $children,
			);
		}

		return $out;
	}

	/**
	 * Extracts a short plain-text excerpt from a block's rendered inner HTML.
	 *
	 * @param array<string, mixed> $block Parsed block node.
	 * @param int                  $limit Maximum characters.
	 * @return string
	 */
	public static function text_excerpt( array $block, $limit = 200 ) {
		$html = isset( $block['innerHTML'] ) ? (string) $block['innerHTML'] : '';
		$text = trim( wp_strip_all_tags( $html ) );

		if ( function_exists( 'mb_strlen' ) && mb_strlen( $text ) > $limit ) {
			return rtrim( mb_substr( $text, 0, $limit ) ) . '…';
		}
		if ( strlen( $text ) > $limit ) {
			return rtrim( substr( $text, 0, $limit ) ) . '…';
		}

		return $text;
	}

	/**
	 * Tallies how many times each Kadence block type appears in a tree.
	 *
	 * @param array<int, array<string, mixed>> $blocks Block tree.
	 * @return array<string, int> Block name => count, for Kadence blocks only.
	 */
	public static function count_kadence_types( array $blocks ) {
		$counts = array();

		self::walk(
			$blocks,
			static function ( $block ) use ( &$counts ) {
				if ( self::is_kadence( $block ) ) {
					$name            = (string) $block['blockName'];
					$counts[ $name ] = isset( $counts[ $name ] ) ? $counts[ $name ] + 1 : 1;
				}
			}
		);

		ksort( $counts );

		return $counts;
	}

	/**
	 * Generates a page-unique uniqueID for a newly inserted Kadence block.
	 *
	 * Kadence keys its generated CSS to each block's uniqueID, so a duplicate id
	 * causes style bleed between blocks. This produces a value in Kadence's own
	 * "postId_suffix" shape and verifies it collides with nothing already in the
	 * tree before returning it.
	 *
	 * @param array<int, array<string, mixed>> $blocks  Existing block tree to avoid colliding with.
	 * @param int                              $post_id Post the block will live on.
	 * @return string
	 */
	public static function generate_unique_id( array $blocks, $post_id ) {
		$existing = array();
		self::walk(
			$blocks,
			static function ( $block ) use ( &$existing ) {
				$uid = self::unique_id( $block );
				if ( '' !== $uid ) {
					$existing[ $uid ] = true;
				}
			}
		);

		do {
			$suffix    = substr( str_replace( array( '.', ' ' ), '', uniqid( '', true ) ), -7 );
			$candidate = absint( $post_id ) . '_' . $suffix;
		} while ( isset( $existing[ $candidate ] ) );

		return $candidate;
	}

	/**
	 * Swaps the inner text of a single-element HTML wrapper, preserving the opening
	 * tag (and its classes/attributes) and the closing tag.
	 *
	 * Used to keep a static-save block's saved innerHTML in sync when its text
	 * attribute changes (the markup-sync step, plan §17-B). Best-effort: it targets
	 * the region between the first '>' and the last '<', so it is faithful for a
	 * simple wrapper like <h2 class="…">text</h2> and is intentionally conservative
	 * — if the wrapper cannot be identified it returns the original HTML unchanged.
	 *
	 * @param string $html     Existing block innerHTML.
	 * @param string $new_html New inner HTML/text to place inside the wrapper.
	 * @return string
	 */
	public static function replace_text_node( $html, $new_html ) {
		$html = (string) $html;
		$open = strpos( $html, '>' );
		$close = strrpos( $html, '<' );

		if ( false === $open || false === $close || $close <= $open ) {
			return $html;
		}

		return substr( $html, 0, $open + 1 ) . (string) $new_html . substr( $html, $close );
	}

	/**
	 * Replaces the text inside one specific `<span class="…">…</span>` occurrence,
	 * matched by class name, leaving the rest of the markup untouched.
	 *
	 * For blocks whose text lives inside a small tagged element nested deep in a
	 * larger wrapper (e.g. a Kadence accordion pane's title span, verified against
	 * real Kadence 3.7.8.2 output: `<span class="kt-blocks-accordion-title">…
	 * </span>`), {@see self::replace_text_node()}'s whole-wrapper "first > … last <"
	 * approach would wipe out sibling markup instead. This targets only the named
	 * span. Returns null (rather than the unmodified HTML) when the class is not
	 * found, so callers can fail closed instead of silently leaving stale text.
	 *
	 * @param string $html       Existing block innerHTML.
	 * @param string $class_name The exact class name identifying the span.
	 * @param string $new_text   Escaped replacement text (caller's responsibility).
	 * @return string|null
	 */
	public static function replace_span_text( $html, $class_name, $new_text ) {
		$pattern = '/(<span class="' . preg_quote( (string) $class_name, '/' ) . '">)(.*?)(<\/span>)/s';

		if ( ! preg_match( $pattern, (string) $html ) ) {
			return null;
		}

		$replacement = '$1' . str_replace( '$', '\\$', (string) $new_text ) . '$3';
		return preg_replace( $pattern, $replacement, (string) $html, 1 );
	}

	/**
	 * Public wrapper over {@see self::strip_empty()} for callers that parse block
	 * markup themselves (e.g. the write engine's round-trip check).
	 *
	 * @param array<int, array<string, mixed>> $blocks Raw parse_blocks() output.
	 * @return array<int, array<string, mixed>>
	 */
	public static function strip_parsed( array $blocks ) {
		return self::strip_empty( $blocks );
	}

	/**
	 * Removes the empty freeform nodes parse_blocks() inserts for inter-block whitespace.
	 *
	 * @param array<int, array<string, mixed>> $blocks Raw parse_blocks() output.
	 * @return array<int, array<string, mixed>>
	 */
	private static function strip_empty( array $blocks ) {
		$clean = array();

		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$name = isset( $block['blockName'] ) ? $block['blockName'] : null;
			$html = isset( $block['innerHTML'] ) ? trim( (string) $block['innerHTML'] ) : '';

			// A null-named node with no HTML is pure whitespace between blocks.
			if ( null === $name && '' === $html ) {
				continue;
			}

			$clean[] = $block;
		}

		return $clean;
	}
}
