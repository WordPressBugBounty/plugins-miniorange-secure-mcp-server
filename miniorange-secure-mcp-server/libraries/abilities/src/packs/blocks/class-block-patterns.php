<?php
/**
 * Detaching synced patterns: turning a pattern block into the pattern's own blocks.
 *
 * A synced pattern appears in content as a reference, <!-- wp:block {"ref":12} /-->,
 * and renders whatever the pattern holds. Detaching swaps that reference for a copy
 * of the pattern's blocks, exactly as "Detach" does in the editor, so the copy can
 * be edited on its own and no longer follows the pattern.
 *
 * Since WordPress 6.6 a synced pattern can let individual pages override parts of
 * it (pattern overrides): the reference then carries the page's own text in its
 * "content" attribute, keyed by the inner block's name. Detaching must keep that
 * text, or the page would silently lose it, so overrides are applied to the copy;
 * an override that cannot be carried over safely refuses the detach instead.
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
 * Class Block_Patterns
 */
class Block_Patterns {

	/**
	 * Which Block_Text field each overridable attribute is, per block.
	 *
	 * Only text kept in saved HTML is listed: those are the overrides that can be
	 * written back into a copied block's markup exactly.
	 *
	 * @var array<string, array<string, string>>
	 */
	const OVERRIDE_FIELDS = array(
		'core/paragraph' => array( 'content' => 'text' ),
		'core/heading'   => array( 'content' => 'text' ),
		'core/button'    => array( 'text' => 'text' ),
		'core/image'     => array( 'caption' => 'caption' ),
	);

	/**
	 * Whether a node is a reference to a saved pattern.
	 *
	 * @param mixed $node Parsed node.
	 * @param int   $id   Pattern ID to match, or 0 for any pattern.
	 * @return bool
	 */
	public static function is_reference( $node, $id = 0 ) {
		if ( ! is_array( $node ) || ! isset( $node['blockName'] ) || 'core/block' !== $node['blockName'] ) {
			return false;
		}
		$ref = isset( $node['attrs']['ref'] ) ? (int) $node['attrs']['ref'] : 0;
		return $ref > 0 && ( 0 === (int) $id || (int) $id === $ref );
	}

	/**
	 * How many references to a pattern a tree holds, at any depth.
	 *
	 * @param array<int, array<string, mixed>> $tree Block tree.
	 * @param int                              $id   Pattern ID.
	 * @return int
	 */
	public static function count_references( array $tree, $id ) {
		$count = 0;
		Block_Tree::walk(
			$tree,
			static function ( $node ) use ( $id, &$count ) {
				if ( self::is_reference( $node, $id ) ) {
					++$count;
				}
			}
		);
		return $count;
	}

	/**
	 * The blocks a reference becomes when detached: a copy of the pattern's blocks
	 * with the reference's own overrides applied.
	 *
	 * @param array<string, mixed> $reference The core/block node.
	 * @param string               $content   The pattern's block markup.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public static function detached_nodes( array $reference, $content ) {
		$nodes     = array_values( array_filter( Block_Tree::parse( $content ), array( Block_Tree::class, 'is_real' ) ) );
		$overrides = isset( $reference['attrs']['content'] ) && is_array( $reference['attrs']['content'] ) ? $reference['attrs']['content'] : array();

		if ( empty( $nodes ) ) {
			return Site_Support::error(
				'mosmcp_pattern_empty',
				__( 'The pattern has no blocks, so detaching it would leave nothing in its place. Remove the pattern block instead with mosmcp/block-delete.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_INVALID_INPUT,
				false,
				array( 'use_instead' => 'mosmcp/block-delete' )
			);
		}

		if ( empty( $overrides ) ) {
			return $nodes;
		}

		$unused = $overrides;
		foreach ( $nodes as $index => $node ) {
			$applied = self::apply_overrides( $node, $overrides, $unused );
			if ( $applied instanceof WP_Error ) {
				return $applied;
			}
			$nodes[ $index ] = $node;
		}

		$left = array();
		foreach ( $unused as $name => $values ) {
			if ( is_array( $values ) && ! empty( $values ) ) {
				$left[] = (string) $name;
			}
		}
		if ( ! empty( $left ) ) {
			return self::override_error( implode( ', ', $left ) );
		}

		return $nodes;
	}

	/**
	 * Replaces every reference to a pattern in a tree with detached copies.
	 *
	 * @param array<int, array<string, mixed>> $tree    Block tree (by reference).
	 * @param int                              $id      Pattern ID.
	 * @param string                           $content The pattern's block markup.
	 * @return int|WP_Error How many references were detached.
	 */
	public static function detach_all( array &$tree, $id, $content ) {
		$paths = array();
		Block_Tree::walk(
			$tree,
			static function ( $node, $indices ) use ( $id, &$paths ) {
				if ( self::is_reference( $node, $id ) ) {
					$paths[] = $indices;
				}
			}
		);

		// Last first: replacing a block only shifts the blocks after it, which are
		// then already done.
		foreach ( array_reverse( $paths ) as $indices ) {
			$result = self::detach_at( $tree, $indices, $content );
			if ( $result instanceof WP_Error ) {
				return $result;
			}
		}

		return count( $paths );
	}

	/**
	 * Replaces the reference at a path with a detached copy.
	 *
	 * @param array<int, array<string, mixed>> $tree    Block tree (by reference).
	 * @param int[]                            $indices Path of the reference.
	 * @param string                           $content The pattern's block markup.
	 * @return int|WP_Error How many blocks took its place.
	 */
	public static function detach_at( array &$tree, array $indices, $content ) {
		$node = Block_Tree::node_at( $tree, $indices );
		if ( ! self::is_reference( $node ) ) {
			return Site_Support::error(
				'mosmcp_block_not_pattern',
				__( 'That block is not a synced pattern, so there is nothing to detach. Find pattern blocks with mosmcp/block-find and block "core/block".', 'mosmcp-abilities' ),
				Site_Support::CAUSE_INVALID_INPUT,
				true,
				array( 'use_instead' => 'mosmcp/block-find' )
			);
		}

		$nodes = self::detached_nodes( $node, $content );
		if ( $nodes instanceof WP_Error ) {
			return $nodes;
		}

		Block_Tree::remove( $tree, $indices );
		$inserted = Block_Tree::insert( $tree, array_slice( $indices, 0, -1 ), (int) end( $indices ), $nodes );

		return $inserted instanceof WP_Error ? $inserted : count( $nodes );
	}

	/**
	 * Applies overrides to a node and its inner blocks, in place.
	 *
	 * @param array<string, mixed> $node      Node (by reference).
	 * @param array<string, mixed> $overrides Overrides keyed by block name.
	 * @param array<string, mixed> $unused    Overrides not yet applied (by reference).
	 * @return true|WP_Error
	 */
	private static function apply_overrides( array &$node, array $overrides, array &$unused ) {
		$name = isset( $node['attrs']['metadata']['name'] ) ? (string) $node['attrs']['metadata']['name'] : '';

		if ( '' !== $name && isset( $overrides[ $name ] ) && is_array( $overrides[ $name ] ) ) {
			$block  = isset( $node['blockName'] ) ? (string) $node['blockName'] : '';
			$fields = isset( self::OVERRIDE_FIELDS[ $block ] ) ? self::OVERRIDE_FIELDS[ $block ] : array();

			foreach ( $overrides[ $name ] as $attribute => $value ) {
				if ( ! isset( $fields[ $attribute ] ) || ! is_string( $value ) ) {
					return self::override_error( $name );
				}
				$set = Block_Text::set( $node, $fields[ $attribute ], $value );
				if ( $set instanceof WP_Error ) {
					return self::override_error( $name );
				}
				unset( $unused[ $name ][ $attribute ] );
			}
		}

		if ( ! empty( $node['innerBlocks'] ) && is_array( $node['innerBlocks'] ) ) {
			foreach ( $node['innerBlocks'] as $index => $child ) {
				$applied = self::apply_overrides( $child, $overrides, $unused );
				if ( $applied instanceof WP_Error ) {
					return $applied;
				}
				$node['innerBlocks'][ $index ] = $child;
			}
		}

		return true;
	}

	/**
	 * Refusal for page-specific overrides that cannot be carried over.
	 *
	 * @param string $names Names of the overridden blocks.
	 * @return WP_Error
	 */
	private static function override_error( $names ) {
		return Site_Support::error(
			'mosmcp_pattern_overrides_unsupported',
			sprintf(
				/* translators: %s: names of the overridden blocks inside the pattern. */
				__( 'This copy of the pattern has page-specific changes (pattern overrides on %s) that cannot be carried over safely, so it was not detached and nothing changed. Detach it in the block editor instead, which applies them.', 'mosmcp-abilities' ),
				$names
			),
			Site_Support::CAUSE_INVALID_INPUT,
			false
		);
	}
}
