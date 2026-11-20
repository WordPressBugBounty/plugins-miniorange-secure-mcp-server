<?php
/**
 * Block-tree engine for the block editor abilities.
 *
 * Content made in the block editor is stored as serialized block markup. The safe
 * way to change one block is to parse the whole post into a tree, change only the
 * target node, and serialize the tree back: every untouched block round-trips
 * byte for byte, so its settings, styles and saved HTML are exactly as they were.
 *
 * Blocks are addressed by a path of indices such as "2.0.1": the third top-level
 * block, its first inner block, that block's second inner block. Indices count
 * only real blocks, never the whitespace WordPress keeps between them, so a path
 * is what a person sees in the editor's list view.
 *
 * Structural changes inside a container keep the container's innerContent in
 * lockstep with its innerBlocks. innerContent interleaves the container's own
 * saved HTML with one null placeholder per inner block; a placeholder added or
 * removed in the wrong place moves the container's wrapper markup and produces a
 * block the editor reports as invalid.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Blocks;

use MoSMCP\Abilities\Packs\Site\Site_Support;
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
 * directive covers IDE and Plugin Check runs that don't read that ruleset).
 */
// phpcs:disable WordPress.WP.I18n.TextDomainMismatch

/**
 * Class Block_Tree
 */
class Block_Tree {

	/**
	 * Separator the block editor writes between sibling blocks.
	 */
	const SEPARATOR = "\n\n";

	/**
	 * Core blocks that hold inner blocks, used to decide where an inner block can go
	 * when a container is currently empty and has no placeholder to anchor on.
	 *
	 * @var string[]
	 */
	const CORE_CONTAINERS = array(
		'core/group',
		'core/columns',
		'core/column',
		'core/cover',
		'core/buttons',
		'core/list',
		'core/quote',
		'core/media-text',
		'core/details',
		'core/social-links',
		'core/gallery',
		'core/query',
		'core/post-template',
		'core/comments',
		'core/comment-template',
		'core/footnotes',
	);

	/**
	 * Parses block markup into a tree, keeping the whitespace nodes between blocks.
	 *
	 * @param string $content Serialized block markup.
	 * @return array<int, array<string, mixed>>
	 */
	public static function parse( $content ) {
		$blocks = parse_blocks( (string) $content );
		return is_array( $blocks ) ? $blocks : array();
	}

	/**
	 * Serializes a tree back to block markup.
	 *
	 * @param array<int, array<string, mixed>> $tree Block tree.
	 * @return string
	 */
	public static function serialize( array $tree ) {
		return serialize_blocks( $tree );
	}

	/**
	 * Whether a parsed node is only the whitespace WordPress keeps between blocks.
	 *
	 * @param mixed $node Parsed node.
	 * @return bool
	 */
	public static function is_whitespace( $node ) {
		if ( ! is_array( $node ) ) {
			return true;
		}

		$name = isset( $node['blockName'] ) ? $node['blockName'] : null;
		$html = isset( $node['innerHTML'] ) ? (string) $node['innerHTML'] : '';

		return null === $name && '' === trim( $html );
	}

	/**
	 * The raw array offsets of the real (non-whitespace) nodes in a list.
	 *
	 * @param array<int, mixed> $nodes Sibling nodes.
	 * @return int[] Offset of each real node, in order.
	 */
	public static function real_offsets( array $nodes ) {
		$offsets = array();

		foreach ( $nodes as $offset => $node ) {
			if ( ! self::is_whitespace( $node ) ) {
				$offsets[] = (int) $offset;
			}
		}

		return $offsets;
	}

	/**
	 * The real child nodes of a container (inner blocks are never whitespace).
	 *
	 * @param array<string, mixed> $node Parsed node.
	 * @return array<int, array<string, mixed>>
	 */
	public static function children( array $node ) {
		return ( isset( $node['innerBlocks'] ) && is_array( $node['innerBlocks'] ) ) ? array_values( $node['innerBlocks'] ) : array();
	}

	/**
	 * Parses a path string such as "2.0.1" into indices.
	 *
	 * @param mixed $path Path supplied by the caller.
	 * @return int[]|WP_Error
	 */
	public static function parse_path( $path ) {
		$path = trim( (string) $path );

		if ( '' === $path || ! preg_match( '/^\d+(\.\d+)*$/', $path ) ) {
			return Site_Support::error(
				'mosmcp_block_path_invalid',
				sprintf(
					/* translators: %s: the path supplied. */
					__( '"%s" is not a block path. A path is the block\'s position as dot-separated numbers counted from 0, such as "0" for the first block or "2.1" for the second block inside the third. Get paths from mosmcp/block-list or mosmcp/block-find.', 'mosmcp-abilities' ),
					$path
				),
				Site_Support::CAUSE_INVALID_INPUT,
				true,
				array( 'use_instead' => 'mosmcp/block-list' )
			);
		}

		return array_map( 'intval', explode( '.', $path ) );
	}

	/**
	 * Formats indices as a path string.
	 *
	 * @param int[] $indices Path indices.
	 * @return string
	 */
	public static function path_string( array $indices ) {
		return implode( '.', array_map( 'intval', $indices ) );
	}

	/**
	 * Returns the node at a path, or null when the path does not exist.
	 *
	 * @param array<int, array<string, mixed>> $tree    Block tree.
	 * @param int[]                            $indices Path indices.
	 * @return array<string, mixed>|null
	 */
	public static function node_at( array $tree, array $indices ) {
		if ( empty( $indices ) ) {
			return null;
		}

		$offsets = self::real_offsets( $tree );
		$first   = (int) $indices[0];

		if ( ! isset( $offsets[ $first ] ) ) {
			return null;
		}

		$node = $tree[ $offsets[ $first ] ];

		foreach ( array_slice( $indices, 1 ) as $index ) {
			$children = self::children( $node );
			if ( ! isset( $children[ (int) $index ] ) ) {
				return null;
			}
			$node = $children[ (int) $index ];
		}

		return $node;
	}

	/**
	 * Resolves a path to its node, or explains which part of it does not exist.
	 *
	 * @param array<int, array<string, mixed>> $tree    Block tree.
	 * @param int[]                            $indices Path indices.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function require_node( array $tree, array $indices ) {
		$node = self::node_at( $tree, $indices );

		if ( null !== $node ) {
			return $node;
		}

		return Site_Support::error(
			'mosmcp_block_not_found',
			sprintf(
				/* translators: %s: block path. */
				__( 'No block exists at path %s in this content. The content may have changed since it was read, which shifts paths. Re-read it with mosmcp/block-list and use the path it reports now.', 'mosmcp-abilities' ),
				self::path_string( $indices )
			),
			Site_Support::CAUSE_NOT_FOUND,
			false,
			array( 'use_instead' => 'mosmcp/block-list' )
		);
	}

	/**
	 * Depth-first walk over every real block, passing its path.
	 *
	 * @param array<int, array<string, mixed>> $tree     Block tree (root list, whitespace allowed).
	 * @param callable                         $callback Receives ( array $node, int[] $indices ).
	 * @return void
	 */
	public static function walk( array $tree, callable $callback ) {
		$index = 0;

		foreach ( $tree as $node ) {
			if ( self::is_whitespace( $node ) ) {
				continue;
			}
			self::walk_node( $node, array( $index ), $callback );
			++$index;
		}
	}

	/**
	 * Recursive part of walk().
	 *
	 * @param array<string, mixed> $node     Node.
	 * @param int[]                $indices  Its path.
	 * @param callable             $callback Visitor.
	 * @return void
	 */
	private static function walk_node( array $node, array $indices, callable $callback ) {
		$callback( $node, $indices );

		foreach ( self::children( $node ) as $i => $child ) {
			$child_path   = $indices;
			$child_path[] = (int) $i;
			self::walk_node( $child, $child_path, $callback );
		}
	}

	/**
	 * Applies a mutator to the node at a path, in place.
	 *
	 * The mutator may change the node's attrs, innerHTML and innerContent strings,
	 * but must not add or remove inner blocks: structural changes go through
	 * insert() and remove(), which keep innerContent's placeholders consistent.
	 *
	 * @param array<int, array<string, mixed>> $tree    Block tree (by reference).
	 * @param int[]                            $indices Path indices.
	 * @param callable                         $mutator function ( array &$node ): void.
	 * @return bool Whether the node existed.
	 */
	public static function mutate( array &$tree, array $indices, callable $mutator ) {
		$ref =& self::node_ref( $tree, $indices );

		if ( null === $ref ) {
			unset( $ref );
			return false;
		}

		$mutator( $ref );
		unset( $ref );

		return true;
	}

	/**
	 * Removes the node at a path and returns it.
	 *
	 * @param array<int, array<string, mixed>> $tree    Block tree (by reference).
	 * @param int[]                            $indices Path indices.
	 * @return array<string, mixed>|null The removed node, or null when absent.
	 */
	public static function remove( array &$tree, array $indices ) {
		$indices = array_values( $indices );
		$last    = (int) array_pop( $indices );

		if ( empty( $indices ) ) {
			$real = self::real_nodes( $tree );
			if ( ! isset( $real[ $last ] ) ) {
				return null;
			}
			$removed = $real[ $last ];
			array_splice( $real, $last, 1 );
			$tree = self::join_root( $real );
			return $removed;
		}

		$container =& self::node_ref( $tree, $indices );
		if ( null === $container ) {
			unset( $container );
			return null;
		}

		$children = self::children( $container );
		if ( ! isset( $children[ $last ] ) ) {
			unset( $container );
			return null;
		}

		$removed = $children[ $last ];
		array_splice( $children, $last, 1 );
		$container['innerBlocks']  = $children;
		$container['innerContent'] = self::content_without_child( self::inner_content( $container ), $last );
		$container['innerHTML']    = self::html_of( $container['innerContent'] );
		unset( $container );

		return $removed;
	}

	/**
	 * Inserts nodes as children of a parent at an index.
	 *
	 * @param array<int, array<string, mixed>> $tree           Block tree (by reference).
	 * @param int[]                            $parent_indices Parent path, or an empty array for the top level.
	 * @param int                              $index          Position among the parent's children (0..count).
	 * @param array<int, array<string, mixed>> $nodes          Real nodes to insert, in order.
	 * @return true|WP_Error
	 */
	public static function insert( array &$tree, array $parent_indices, $index, array $nodes ) {
		$index = (int) $index;
		$nodes = array_values( array_filter( $nodes, array( __CLASS__, 'is_real' ) ) );

		if ( empty( $nodes ) ) {
			return true;
		}

		if ( empty( $parent_indices ) ) {
			$real  = self::real_nodes( $tree );
			$index = max( 0, min( $index, count( $real ) ) );
			array_splice( $real, $index, 0, $nodes );
			$tree = self::join_root( $real );
			return true;
		}

		$container =& self::node_ref( $tree, $parent_indices );
		if ( null === $container ) {
			unset( $container );
			return self::require_node( $tree, $parent_indices );
		}

		$children = self::children( $container );
		$index    = max( 0, min( $index, count( $children ) ) );
		$content  = self::content_with_children( $container, $index, count( $nodes ) );

		if ( $content instanceof WP_Error ) {
			unset( $container );
			return $content;
		}

		array_splice( $children, $index, 0, $nodes );
		$container['innerBlocks']  = $children;
		$container['innerContent'] = $content;
		$container['innerHTML']    = self::html_of( $content );
		unset( $container );

		return true;
	}

	/**
	 * Adjusts a path that was resolved before a block was removed, so it still
	 * points at the same block afterwards.
	 *
	 * Removing a block shifts every later sibling, and every block nested under a
	 * later sibling, one place earlier.
	 *
	 * @param int[] $path    Path resolved before the removal.
	 * @param int[] $removed Path of the removed block.
	 * @return int[]
	 */
	public static function shift_after_removal( array $path, array $removed ) {
		$path    = array_values( $path );
		$removed = array_values( $removed );
		$depth   = count( $removed );

		if ( count( $path ) < $depth ) {
			return $path;
		}

		if ( array_slice( $path, 0, $depth - 1 ) !== array_slice( $removed, 0, $depth - 1 ) ) {
			return $path;
		}

		if ( $path[ $depth - 1 ] > $removed[ $depth - 1 ] ) {
			--$path[ $depth - 1 ];
		}

		return $path;
	}

	/**
	 * Whether one path lies inside (or is) another.
	 *
	 * @param int[] $path     Candidate descendant.
	 * @param int[] $ancestor Candidate ancestor.
	 * @return bool
	 */
	public static function is_within( array $path, array $ancestor ) {
		$ancestor = array_values( $ancestor );
		return count( $path ) >= count( $ancestor ) && array_slice( array_values( $path ), 0, count( $ancestor ) ) === $ancestor;
	}

	/**
	 * Plain text of a block and its inner blocks.
	 *
	 * @param array<string, mixed> $node  Node.
	 * @param int                  $limit Maximum characters; 0 for no limit.
	 * @return string
	 */
	public static function text( array $node, $limit = 0 ) {
		$html = self::serialize( array( $node ) );
		$html = (string) preg_replace( '/<!--.*?-->/s', ' ', $html );
		// Block-level tags separate words: without a space, "<h2>Title</h2><p>Text" reads "TitleText".
		$html = (string) preg_replace( '/<\/?(?:p|h[1-6]|li|ul|ol|div|br|hr|figure|figcaption|blockquote|pre|table|tr|td|th|section|header|footer|summary|details|cite)\b[^>]*>/i', ' ', $html );
		$text = trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' ) ) );

		if ( $limit > 0 && function_exists( 'mb_strlen' ) && mb_strlen( $text ) > $limit ) {
			return rtrim( mb_substr( $text, 0, $limit ) ) . '…';
		}

		if ( $limit > 0 && strlen( $text ) > $limit ) {
			return rtrim( substr( $text, 0, $limit ) ) . '…';
		}

		return $text;
	}

	/**
	 * Whether a node's saved markup is nothing but its comment delimiters, meaning
	 * every setting lives in the block's attributes and none in saved HTML.
	 *
	 * @param array<string, mixed> $node Node.
	 * @return bool
	 */
	public static function is_comment_only( array $node ) {
		foreach ( self::inner_content( $node ) as $chunk ) {
			if ( is_string( $chunk ) && '' !== trim( $chunk ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether a node is a real block (not inter-block whitespace).
	 *
	 * @param mixed $node Node.
	 * @return bool
	 */
	public static function is_real( $node ) {
		return ! self::is_whitespace( $node );
	}

	/**
	 * The node's innerContent, falling back to its innerHTML.
	 *
	 * @param array<string, mixed> $node Node.
	 * @return array<int, string|null>
	 */
	public static function inner_content( array $node ) {
		if ( isset( $node['innerContent'] ) && is_array( $node['innerContent'] ) ) {
			return array_values( $node['innerContent'] );
		}

		return array( isset( $node['innerHTML'] ) ? (string) $node['innerHTML'] : '' );
	}

	/**
	 * Joins the string chunks of innerContent, which is what innerHTML holds.
	 *
	 * @param array<int, string|null> $content innerContent.
	 * @return string
	 */
	public static function html_of( array $content ) {
		$html = '';

		foreach ( $content as $chunk ) {
			if ( is_string( $chunk ) ) {
				$html .= $chunk;
			}
		}

		return $html;
	}

	/**
	 * Returns a reference to the node at a path, or a reference to null.
	 *
	 * @param array<int, array<string, mixed>> $tree    Block tree (by reference).
	 * @param int[]                            $indices Path indices.
	 * @return array<string, mixed>|null
	 */
	private static function &node_ref( array &$tree, array $indices ) {
		$null    = null;
		$offsets = self::real_offsets( $tree );

		if ( empty( $indices ) || ! isset( $offsets[ (int) $indices[0] ] ) ) {
			return $null;
		}

		$node =& $tree[ $offsets[ (int) $indices[0] ] ];

		foreach ( array_slice( $indices, 1 ) as $index ) {
			if ( ! isset( $node['innerBlocks'][ (int) $index ] ) || ! is_array( $node['innerBlocks'][ (int) $index ] ) ) {
				return $null;
			}
			$node =& $node['innerBlocks'][ (int) $index ];
		}

		return $node;
	}

	/**
	 * The real top-level nodes of a tree.
	 *
	 * @param array<int, mixed> $tree Block tree.
	 * @return array<int, array<string, mixed>>
	 */
	private static function real_nodes( array $tree ) {
		return array_values( array_filter( $tree, array( __CLASS__, 'is_real' ) ) );
	}

	/**
	 * Rebuilds a top-level list with the editor's separator between blocks.
	 *
	 * Only the whitespace between top-level blocks is normalized; it carries no
	 * content and the block editor writes the same separator on every save.
	 *
	 * @param array<int, array<string, mixed>> $real Real top-level nodes.
	 * @return array<int, array<string, mixed>>
	 */
	private static function join_root( array $real ) {
		$tree = array();

		foreach ( array_values( $real ) as $i => $node ) {
			if ( $i > 0 ) {
				$tree[] = self::whitespace_node();
			}
			$tree[] = $node;
		}

		return $tree;
	}

	/**
	 * A whitespace node as parse_blocks() produces between top-level blocks.
	 *
	 * @return array<string, mixed>
	 */
	private static function whitespace_node() {
		return array(
			'blockName'    => null,
			'attrs'        => array(),
			'innerBlocks'  => array(),
			'innerHTML'    => self::SEPARATOR,
			'innerContent' => array( self::SEPARATOR ),
		);
	}

	/**
	 * Offsets of the null placeholders in innerContent.
	 *
	 * @param array<int, string|null> $content innerContent.
	 * @return int[]
	 */
	private static function placeholder_offsets( array $content ) {
		$offsets = array();

		foreach ( $content as $offset => $chunk ) {
			if ( null === $chunk ) {
				$offsets[] = (int) $offset;
			}
		}

		return $offsets;
	}

	/**
	 * Returns innerContent with the placeholder of one child (and one separator) removed.
	 *
	 * @param array<int, string|null> $content innerContent.
	 * @param int                     $child   Index of the removed child.
	 * @return array<int, string|null>
	 */
	private static function content_without_child( array $content, $child ) {
		$placeholders = self::placeholder_offsets( $content );

		if ( ! isset( $placeholders[ $child ] ) ) {
			return $content;
		}

		$at = $placeholders[ $child ];
		array_splice( $content, $at, 1 );

		/*
		 * Drop one whitespace separator next to the removed placeholder, preferring
		 * the one before it, so siblings keep a single separator between them and
		 * the container's own wrapper markup is never touched.
		 */
		if ( $at > 0 && self::is_separator( $content, $at - 1 ) && ( $at - 1 > 0 && null === $content[ $at - 2 ] ) ) {
			array_splice( $content, $at - 1, 1 );
		} elseif ( isset( $content[ $at ] ) && self::is_separator( $content, $at ) && isset( $content[ $at + 1 ] ) && null === $content[ $at + 1 ] ) {
			array_splice( $content, $at, 1 );
		}

		return self::merge_strings( $content );
	}

	/**
	 * Returns innerContent with placeholders for new children added at an index.
	 *
	 * @param array<string, mixed> $container Parent node.
	 * @param int                  $index  Child index the new children start at.
	 * @param int                  $count  How many children are added.
	 * @return array<int, string|null>|WP_Error
	 */
	private static function content_with_children( array $container, $index, $count ) {
		$content      = self::inner_content( $container );
		$placeholders = self::placeholder_offsets( $content );
		$total        = count( $placeholders );
		$add          = array();

		if ( $total > 0 ) {
			if ( $index < $total ) {
				// Before the existing child at $index: [new, sep, new, sep, existing].
				for ( $i = 0; $i < $count; $i++ ) {
					$add[] = null;
					$add[] = self::SEPARATOR;
				}
				array_splice( $content, $placeholders[ $index ], 0, $add );
			} else {
				// After the last child: [last, sep, new, sep, new].
				for ( $i = 0; $i < $count; $i++ ) {
					$add[] = self::SEPARATOR;
					$add[] = null;
				}
				array_splice( $content, $placeholders[ $total - 1 ] + 1, 0, $add );
			}

			return self::merge_strings( $content );
		}

		// The container is empty: find the slot its children go into.
		$name = isset( $container['blockName'] ) ? (string) $container['blockName'] : '';
		$html = self::html_of( $content );

		if ( 0 === strpos( $name, 'core/' ) && ! in_array( $name, self::CORE_CONTAINERS, true ) ) {
			return Site_Support::error(
				'mosmcp_block_not_container',
				sprintf(
					/* translators: %s: block name. */
					__( 'A %s block cannot hold other blocks. Insert before or after it instead (position "before" or "after").', 'mosmcp-abilities' ),
					$name
				),
				Site_Support::CAUSE_INVALID_INPUT,
				true
			);
		}

		if ( ! preg_match_all( '/<([a-zA-Z][a-zA-Z0-9-]*)\b[^>]*>(\s*)<\/\1>/', $html, $matches, PREG_OFFSET_CAPTURE ) ) {
			if ( '' === trim( $html ) ) {
				// A container with no wrapper markup of its own (InnerBlocks only).
				$content = array();
				for ( $i = 0; $i < $count; $i++ ) {
					if ( $i > 0 ) {
						$content[] = self::SEPARATOR;
					}
					$content[] = null;
				}
				return $content;
			}

			return Site_Support::error(
				'mosmcp_block_no_slot',
				sprintf(
					/* translators: %s: block name. */
					__( 'This %s block is empty and its saved markup has no place for inner blocks that can be identified safely. Replace the whole block with mosmcp/block-replace, writing the new inner blocks into its markup.', 'mosmcp-abilities' ),
					'' !== $name ? $name : __( 'container', 'mosmcp-abilities' )
				),
				Site_Support::CAUSE_INVALID_INPUT,
				false,
				array( 'use_instead' => 'mosmcp/block-replace' )
			);
		}

		// The innermost empty element is where InnerBlocks rendered its (empty) output.
		$last   = end( $matches[2] );
		$offset = (int) $last[1];
		$before = substr( $html, 0, $offset );
		$after  = substr( $html, $offset + strlen( (string) $last[0] ) );

		$content = array( $before );
		for ( $i = 0; $i < $count; $i++ ) {
			if ( $i > 0 ) {
				$content[] = self::SEPARATOR;
			}
			$content[] = null;
		}
		$content[] = $after;

		return $content;
	}

	/**
	 * Whether the chunk at an offset is a whitespace-only string.
	 *
	 * @param array<int, string|null> $content innerContent.
	 * @param int                     $offset  Offset.
	 * @return bool
	 */
	private static function is_separator( array $content, $offset ) {
		return isset( $content[ $offset ] ) && is_string( $content[ $offset ] ) && '' === trim( $content[ $offset ] );
	}

	/**
	 * Merges adjacent string chunks, as parse_blocks() would produce them.
	 *
	 * @param array<int, string|null> $content innerContent.
	 * @return array<int, string|null>
	 */
	private static function merge_strings( array $content ) {
		$merged = array();

		foreach ( $content as $chunk ) {
			$last = count( $merged ) - 1;
			if ( is_string( $chunk ) && $last >= 0 && is_string( $merged[ $last ] ) ) {
				$merged[ $last ] .= $chunk;
				continue;
			}
			$merged[] = $chunk;
		}

		return $merged;
	}
}
