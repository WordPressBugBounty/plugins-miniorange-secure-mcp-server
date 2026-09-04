<?php
/**
 * Shared write/mutation engine for every Kadence block-writing ability.
 *
 * Every mutating block ability — text update, attribute update, delete,
 * duplicate, insert — funnels through this engine rather than implementing its
 * own read/mutate/save flow. The engine owns the safety contract: capability is
 * already gated by the ability's permission_callback, and here we add the
 * duplicate-uniqueID guard, optional optimistic locking, path-based in-place
 * mutation (with innerContent kept in lockstep with innerBlocks), a serialize →
 * reparse round-trip check, the save, and read-after-write verification. Any new
 * write ability inherits all of it by calling run().
 *
 * Round-trip validation catches this engine's own mutation bugs; it cannot detect
 * the block editor's "unexpected or invalid content" state, which is a browser-side
 * comparison of stored innerHTML against the block's JS save() output and has no
 * PHP equivalent. See the plan's §10/§17-A.
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
 * Class Kadence_Write_Engine
 */
class Kadence_Write_Engine {

	/**
	 * Runs a block mutation end to end against a post.
	 *
	 * @param array<string, mixed> $input   Ability input (must carry 'id'; may carry 'expected_modified').
	 * @param callable             $mutator function ( array &$blocks, array &$result ): true|WP_Error.
	 *                                       Receives the parsed block tree by reference to mutate in place,
	 *                                       and a result array by reference to populate for the caller.
	 * @return array<string, mixed>|WP_Error The populated result on success, or a structured error.
	 */
	public static function run( array $input, callable $mutator ) {
		$post = Kadence_Blocks_Helper::require_post( $input );
		if ( $post instanceof WP_Error ) {
			return $post;
		}

		$lock = self::check_optimistic_lock( $post, $input );
		if ( $lock instanceof WP_Error ) {
			return $lock;
		}

		$blocks = Kadence_Blocks_Helper::parse_post( $post );
		$result = array();

		$mutated = $mutator( $blocks, $result );
		if ( $mutated instanceof WP_Error ) {
			return $mutated;
		}

		$content    = Kadence_Blocks_Helper::serialize( $blocks );
		$validation = self::validate_round_trip( $content, $blocks );
		if ( $validation instanceof WP_Error ) {
			return $validation;
		}

		$saved = wp_update_post(
			array(
				'ID'           => (int) $post->ID,
				'post_content' => $content,
			),
			true
		);
		if ( is_wp_error( $saved ) ) {
			return self::error( 'validation_failed', $saved->get_error_message() );
		}

		$reloaded = get_post( (int) $post->ID );
		if ( $reloaded instanceof WP_Post ) {
			$result['verified'] = self::verify_saved( $reloaded, $result );
		}

		$result['id']       = (int) $post->ID;
		$result['modified'] = (string) get_post_field( 'post_modified', (int) $post->ID );

		return $result;
	}

	/**
	 * Locates the path to the first block with a given uniqueID.
	 *
	 * A path is a list of integer indices: [i0, i1, ...] means
	 * blocks[i0]->innerBlocks[i1]->... Returns null when not found.
	 *
	 * @param array<int, array<string, mixed>> $blocks    Block tree.
	 * @param string                           $unique_id Target uniqueID.
	 * @return int[]|null
	 */
	public static function locate( array $blocks, $unique_id ) {
		$unique_id = (string) $unique_id;
		if ( '' === $unique_id ) {
			return null;
		}

		foreach ( $blocks as $index => $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			if ( Kadence_Blocks_Helper::unique_id( $block ) === $unique_id ) {
				return array( (int) $index );
			}
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$deeper = self::locate( $block['innerBlocks'], $unique_id );
				if ( null !== $deeper ) {
					array_unshift( $deeper, (int) $index );
					return $deeper;
				}
			}
		}

		return null;
	}

	/**
	 * Counts how many blocks in the tree carry a given uniqueID.
	 *
	 * Used by the duplicate-uniqueID guard: a target that resolves to more than
	 * one block is ambiguous and every mutation refuses it.
	 *
	 * @param array<int, array<string, mixed>> $blocks    Block tree.
	 * @param string                           $unique_id Target uniqueID.
	 * @return int
	 */
	public static function count_unique_id( array $blocks, $unique_id ) {
		$unique_id = (string) $unique_id;
		$count     = 0;

		Kadence_Blocks_Helper::walk(
			$blocks,
			static function ( $block ) use ( $unique_id, &$count ) {
				if ( Kadence_Blocks_Helper::unique_id( $block ) === $unique_id ) {
					++$count;
				}
			}
		);

		return $count;
	}

	/**
	 * Resolves a target uniqueID to a single unambiguous path, or a structured error.
	 *
	 * @param array<int, array<string, mixed>> $blocks    Block tree.
	 * @param string                           $unique_id Target uniqueID.
	 * @return int[]|WP_Error
	 */
	public static function require_single( array $blocks, $unique_id ) {
		$count = self::count_unique_id( $blocks, $unique_id );

		if ( 0 === $count ) {
			return self::error(
				'unsupported_block',
				__( 'No Kadence block with that uniqueID was found on this page.', 'mosmcp-abilities' )
			);
		}

		if ( $count > 1 ) {
			return self::error(
				'duplicate_unique_id',
				sprintf(
					/* translators: %s: the ambiguous uniqueID. */
					__( 'The uniqueID "%s" appears on more than one block on this page (a known Kadence copy/paste quirk). Refusing to guess which one to change; resolve the duplicate in the editor first.', 'mosmcp-abilities' ),
					(string) $unique_id
				)
			);
		}

		return self::locate( $blocks, $unique_id );
	}

	/**
	 * Reads a copy of the block node at a path.
	 *
	 * @param array<int, array<string, mixed>> $blocks Block tree.
	 * @param int[]                            $path   Path to the node.
	 * @return array<string, mixed>|null
	 */
	public static function node_at( array $blocks, array $path ) {
		$node = null;
		$cur  = $blocks;

		foreach ( $path as $depth => $idx ) {
			if ( ! isset( $cur[ $idx ] ) || ! is_array( $cur[ $idx ] ) ) {
				return null;
			}
			if ( $depth === count( $path ) - 1 ) {
				$node = $cur[ $idx ];
			} else {
				$cur = isset( $cur[ $idx ]['innerBlocks'] ) && is_array( $cur[ $idx ]['innerBlocks'] )
					? $cur[ $idx ]['innerBlocks']
					: array();
			}
		}

		return $node;
	}

	/**
	 * Applies a mutator to the node at a path, in place on the tree.
	 *
	 * The mutator receives the node array by reference and may change its attrs,
	 * innerHTML, or innerContent. It must NOT add or remove innerBlocks — use
	 * {@see self::splice_children()} for structural changes so innerContent stays
	 * in lockstep.
	 *
	 * @param array<int, array<string, mixed>> $blocks  Block tree (by reference).
	 * @param int[]                            $path    Path to the node.
	 * @param callable                         $mutator function ( array &$node ): void.
	 * @return bool Whether the node was found and mutated.
	 */
	public static function mutate_at( array &$blocks, array $path, callable $mutator ) {
		$container =& self::container_ref( $blocks, $path, $last );
		if ( ! isset( $container[ $last ] ) || ! is_array( $container[ $last ] ) ) {
			return false;
		}

		$node =& $container[ $last ];
		$mutator( $node );
		unset( $node, $container );

		return true;
	}

	/**
	 * Splices children of the parent node at a path: removes and/or inserts inner
	 * blocks while keeping the parent's innerContent null-markers consistent.
	 *
	 * @param array<int, array<string, mixed>>  $blocks       Block tree (by reference).
	 * @param int[]                             $parent_path  Path to the parent block, or [] for the root.
	 * @param int                               $offset       Child index at which to splice.
	 * @param int                               $remove       Number of children to remove.
	 * @param array<int, array<string, mixed>>  $insert       New child blocks to insert at the offset.
	 * @return bool Whether the splice was applied.
	 */
	public static function splice_children( array &$blocks, array $parent_path, $offset, $remove, array $insert = array() ) {
		if ( empty( $parent_path ) ) {
			// Root level: no parent innerContent to maintain.
			array_splice( $blocks, (int) $offset, (int) $remove, $insert );
			return true;
		}

		$container =& self::container_ref( $blocks, $parent_path, $last );
		if ( ! isset( $container[ $last ] ) || ! is_array( $container[ $last ] ) ) {
			return false;
		}

		$node =& $container[ $last ];
		$kids = isset( $node['innerBlocks'] ) && is_array( $node['innerBlocks'] ) ? $node['innerBlocks'] : array();
		array_splice( $kids, (int) $offset, (int) $remove, $insert );
		$node['innerBlocks'] = $kids;
		self::resync_inner_content( $node );
		unset( $node, $container );

		return true;
	}

	/**
	 * Recursively assigns a fresh, page-unique uniqueID to a block and every
	 * descendant, mutating the given subtree in place.
	 *
	 * Server-side duplication must re-ID the whole subtree — a single fresh ID on
	 * the top block still leaves nested children colliding with the original,
	 * which is exactly the CSS/anchor breakage of the known duplicate-ID bug.
	 *
	 * @param array<string, mixed>             $block    The block subtree to re-ID (by value; returned re-IDed).
	 * @param array<int, array<string, mixed>> $existing The full tree to check IDs against.
	 * @param int                              $post_id  Post the blocks live on.
	 * @return array<string, mixed> The re-IDed block.
	 */
	public static function regenerate_ids( array $block, array $existing, $post_id ) {
		if ( isset( $block['attrs']['uniqueID'] ) ) {
			$old_id                     = (string) $block['attrs']['uniqueID'];
			$new_id                     = Kadence_Blocks_Helper::generate_unique_id( $existing, $post_id );
			$block['attrs']['uniqueID'] = $new_id;

			// Kadence bakes the uniqueID into each block's own saved markup (class
			// names like kt-pane<id>, kt-adv-heading<id>). Re-IDing only the
			// attribute would leave the old id in the HTML, which both collides with
			// the original's CSS and fails block validation. The uniqueID is a
			// distinctive token, so a scoped replace on this block's own markup is
			// safe (children carry their own ids and are handled by recursion).
			if ( '' !== $old_id ) {
				if ( isset( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ) {
					$block['innerHTML'] = str_replace( $old_id, $new_id, $block['innerHTML'] );
				}
				if ( isset( $block['innerContent'] ) && is_array( $block['innerContent'] ) ) {
					foreach ( $block['innerContent'] as $ci => $chunk ) {
						if ( is_string( $chunk ) ) {
							$block['innerContent'][ $ci ] = str_replace( $old_id, $new_id, $chunk );
						}
					}
				}
			}

			// Seed the new ID into a throwaway node so sibling regenerations in the
			// same pass cannot collide with it.
			$existing[] = array( 'attrs' => array( 'uniqueID' => $new_id ) );
		}

		if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
			foreach ( $block['innerBlocks'] as $i => $child ) {
				if ( is_array( $child ) ) {
					$block['innerBlocks'][ $i ] = self::regenerate_ids( $child, $existing, $post_id );
					$existing[]                 = $block['innerBlocks'][ $i ];
				}
			}
		}

		return $block;
	}

	/**
	 * Builds a structured WP_Error using the plan's error taxonomy (§13.1).
	 *
	 * @param string $code    One of the taxonomy codes.
	 * @param string $message Human-readable message.
	 * @return WP_Error
	 */
	public static function error( $code, $message ) {
		return new WP_Error( 'kadence_' . (string) $code, (string) $message, array( 'reason' => (string) $code ) );
	}

	/**
	 * Rejects the write when the caller's expected_modified no longer matches the post.
	 *
	 * Optimistic locking is opt-in: callers that omit expected_modified get the
	 * last-writer-wins behavior; callers that pass the value they read are protected
	 * against lost updates.
	 *
	 * @param WP_Post              $post  Target post.
	 * @param array<string, mixed> $input Ability input.
	 * @return true|WP_Error
	 */
	private static function check_optimistic_lock( WP_Post $post, array $input ) {
		$expected = isset( $input['expected_modified'] ) ? trim( (string) $input['expected_modified'] ) : '';
		if ( '' === $expected ) {
			return true;
		}

		if ( $expected !== (string) $post->post_modified ) {
			return self::error(
				'stale_revision',
				__( 'The page has changed since it was read (expected_modified does not match). Re-read the page and retry.', 'mosmcp-abilities' )
			);
		}

		return true;
	}

	/**
	 * Confirms the serialized content reparses into the same number of top-level
	 * blocks it was built from — a cheap guard against a mutation that produced
	 * markup PHP can no longer parse back.
	 *
	 * @param string                           $content Serialized block markup.
	 * @param array<int, array<string, mixed>> $blocks  The tree it was serialized from.
	 * @return true|WP_Error
	 */
	private static function validate_round_trip( $content, array $blocks ) {
		$reparsed = Kadence_Blocks_Helper::strip_parsed( parse_blocks( (string) $content ) );
		$expected = 0;
		foreach ( $blocks as $b ) {
			if ( is_array( $b ) && ! ( null === ( isset( $b['blockName'] ) ? $b['blockName'] : null ) && '' === trim( isset( $b['innerHTML'] ) ? (string) $b['innerHTML'] : '' ) ) ) {
				++$expected;
			}
		}

		if ( count( $reparsed ) !== $expected ) {
			return self::error(
				'serialization_failed',
				__( 'The edit produced block markup that did not round-trip cleanly and was not saved.', 'mosmcp-abilities' )
			);
		}

		return true;
	}

	/**
	 * Read-after-write check: re-reads the post and confirms the targeted uniqueID
	 * still resolves (best-effort; the block editor's own validation cannot be
	 * reproduced here).
	 *
	 * @param WP_Post              $post   Reloaded post.
	 * @param array<string, mixed> $result Result array carrying an optional 'unique_id'.
	 * @return bool
	 */
	private static function verify_saved( WP_Post $post, array $result ) {
		$unique_id = isset( $result['unique_id'] ) ? (string) $result['unique_id'] : '';
		if ( '' === $unique_id ) {
			return true;
		}

		$blocks = Kadence_Blocks_Helper::parse_post( $post );
		return null !== self::locate( $blocks, $unique_id );
	}

	/**
	 * Returns a reference to the parent innerBlocks array (or the root) that holds
	 * the node at $path, and sets $last to the node's index within it.
	 *
	 * @param array<int, array<string, mixed>> $blocks Block tree (by reference).
	 * @param int[]                            $path   Path to the node.
	 * @param int|null                         $last   Out: index of the node in the returned container.
	 * @return array<int, array<string, mixed>> Reference to the container array.
	 */
	private static function &container_ref( array &$blocks, array $path, &$last ) {
		$path      = array_values( $path );
		$last      = (int) array_pop( $path );
		$container =& $blocks;

		foreach ( $path as $idx ) {
			if ( ! isset( $container[ $idx ]['innerBlocks'] ) || ! is_array( $container[ $idx ]['innerBlocks'] ) ) {
				$container[ $idx ]['innerBlocks'] = array();
			}
			$container =& $container[ $idx ]['innerBlocks'];
		}

		return $container;
	}

	/**
	 * Rebuilds a parent node's innerContent so its null markers match its current
	 * innerBlocks count, preserving the leading and trailing wrapper HTML.
	 *
	 * Kadence container blocks (rowlayout, column, …) wrap their children in a
	 * single open/close markup pair with the child null-markers consecutively
	 * between them and no string chunks interleaved between children, so keeping
	 * the lead (before the first null) and trail (after the last null) and
	 * regenerating one null per child is faithful. If string chunks were ever
	 * interleaved between children they would be dropped here — acceptable for the
	 * container blocks this engine mutates; see the plan's §17-D.
	 *
	 * @param array<string, mixed> $node Parent block node (by reference).
	 * @return void
	 */
	private static function resync_inner_content( array &$node ) {
		$ic    = isset( $node['innerContent'] ) && is_array( $node['innerContent'] ) ? $node['innerContent'] : array();
		$count = isset( $node['innerBlocks'] ) && is_array( $node['innerBlocks'] ) ? count( $node['innerBlocks'] ) : 0;

		$first_null = null;
		$last_null  = null;
		foreach ( $ic as $i => $chunk ) {
			if ( null === $chunk ) {
				if ( null === $first_null ) {
					$first_null = $i;
				}
				$last_null = $i;
			}
		}

		if ( null !== $first_null ) {
			// Already has at least one child: everything before the first null is the
			// lead wrapper, everything after the last is the trail wrapper.
			$lead  = array_slice( $ic, 0, $first_null );
			$trail = array_slice( $ic, $last_null + 1 );
		} else {
			// No children yet. A bare leaf has 0 non-null chunks (nothing to preserve).
			// A container built with wrapper markup but 0 children (see node(), which
			// now always emits containers as [lead, trail] even when empty) has
			// exactly 2: the first is the lead, the last is the trail — there is no
			// null to split on yet because nothing has been placed inside it.
			$non_null = array_values( array_filter( $ic, static function ( $chunk ) {
				return null !== $chunk;
			} ) );

			if ( count( $non_null ) >= 2 ) {
				$lead  = array( $non_null[0] );
				$trail = array( $non_null[ count( $non_null ) - 1 ] );
			} elseif ( 1 === count( $non_null ) ) {
				$lead  = array( $non_null[0] );
				$trail = array();
			} else {
				$lead  = array();
				$trail = array();
			}
		}

		$middle = $count > 0 ? array_fill( 0, $count, null ) : array();

		$node['innerContent'] = array_merge( $lead, $middle, $trail );
	}
}
