<?php
/**
 * Structural changes to an Elementor tree: delete, duplicate and move.
 *
 * These share the write engine's safety contract and add the guards that only
 * matter when the shape of the tree changes rather than the contents of one node:
 *
 *   - a duplicated subtree gets fresh element IDs. Copying IDs verbatim would
 *     create the exact duplicate-ID condition the engine refuses to edit, so a
 *     naive copy quietly makes the page uneditable from that point on.
 *   - an element cannot be moved inside itself or inside one of its own
 *     descendants, which would detach the subtree from the document.
 *   - child arrays are reindexed after every removal, because a PHP array with
 *     gaps in its integer keys encodes as a JSON object and Elementor then reads
 *     the children as malformed.
 *
 * Positions are resolved by element ID rather than by index wherever possible.
 * Removing a node shifts its siblings, so an index captured beforehand may point
 * somewhere else by the time it is used; IDs stay stable across the operation.
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
 * Class Elementor_Structure_Writer
 */
class Elementor_Structure_Writer {

	/**
	 * Length of a generated element ID, matching Elementor's own format.
	 *
	 * @var int
	 */
	const ID_LENGTH = 7;

	/**
	 * Structural types that can hold children.
	 *
	 * @var string[]
	 */
	const CONTAINER_TYPES = array( 'container', 'section', 'column' );

	/**
	 * Builds the mutator for deleting an element and everything inside it.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return callable
	 */
	public static function delete_mutator( array $input ) {
		return static function ( array &$tree, array &$result ) use ( $input ) {
			$path = Elementor_Write_Engine::resolve_one( $tree, isset( $input['element_id'] ) ? $input['element_id'] : '' );
			if ( is_wp_error( $path ) ) {
				return $path;
			}

			$node = Elementor_Write_Engine::node_at( $tree, $path );
			if ( ! is_array( $node ) ) {
				return new WP_Error( 'element_not_found', __( 'The element could not be read from the layout.', 'mosmcp-abilities' ) );
			}

			$descendants = self::count_descendants( $node );

			$removed = self::remove( $tree, $path );
			if ( is_wp_error( $removed ) ) {
				return $removed;
			}

			$result['changes'][] = array(
				'action'      => 'delete',
				'element_id'  => isset( $node['id'] ) ? (string) $node['id'] : '',
				'widget_type' => self::slug_of( $node ),
				'detail'      => sprintf(
					/* translators: %d: number of nested elements removed with it */
					_n( 'Deleted, along with %d nested element.', 'Deleted, along with %d nested elements.', $descendants, 'mosmcp-abilities' ),
					$descendants
				),
			);

			if ( $descendants > 0 ) {
				$result['warnings'][] = array(
					'code'    => 'children_deleted',
					'message' => sprintf(
						/* translators: %d: number of nested elements */
						_n( 'This element contained %d nested element, which was deleted with it.', 'This element contained %d nested elements, which were deleted with it.', $descendants, 'mosmcp-abilities' ),
						$descendants
					),
					'context' => 'element_id=' . ( isset( $node['id'] ) ? $node['id'] : '' ),
				);
			}

			return true;
		};
	}

	/**
	 * Builds the mutator for duplicating an element in place.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return callable
	 */
	public static function duplicate_mutator( array $input ) {
		return static function ( array &$tree, array &$result ) use ( $input ) {
			$path = Elementor_Write_Engine::resolve_one( $tree, isset( $input['element_id'] ) ? $input['element_id'] : '' );
			if ( is_wp_error( $path ) ) {
				return $path;
			}

			$node = Elementor_Write_Engine::node_at( $tree, $path );
			if ( ! is_array( $node ) ) {
				return new WP_Error( 'element_not_found', __( 'The element could not be read from the layout.', 'mosmcp-abilities' ) );
			}

			$copy = $node;
			$used = self::collect_ids( $tree );
			self::regenerate_ids( $copy, $used );

			$index         = (int) $path[ count( $path ) - 1 ];
			$parent_path   = array_slice( $path, 0, -1 );
			$inserted      = self::insert( $tree, $parent_path, $index + 1, $copy );

			if ( is_wp_error( $inserted ) ) {
				return $inserted;
			}

			$result['changes'][] = array(
				'action'      => 'duplicate',
				'element_id'  => isset( $copy['id'] ) ? (string) $copy['id'] : '',
				'widget_type' => self::slug_of( $copy ),
				'detail'      => sprintf(
					/* translators: 1: source element ID, 2: new element ID */
					__( 'Copied element %1$s; the copy is %2$s and sits immediately after it.', 'mosmcp-abilities' ),
					isset( $node['id'] ) ? (string) $node['id'] : '',
					isset( $copy['id'] ) ? (string) $copy['id'] : ''
				),
			);

			return true;
		};
	}

	/**
	 * Builds the mutator for moving an element within the tree.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return callable
	 */
	public static function move_mutator( array $input ) {
		return static function ( array &$tree, array &$result ) use ( $input ) {
			$element_id = isset( $input['element_id'] ) ? (string) $input['element_id'] : '';

			$path = Elementor_Write_Engine::resolve_one( $tree, $element_id );
			if ( is_wp_error( $path ) ) {
				return $path;
			}

			$node = Elementor_Write_Engine::node_at( $tree, $path );
			if ( ! is_array( $node ) ) {
				return new WP_Error( 'element_not_found', __( 'The element could not be read from the layout.', 'mosmcp-abilities' ) );
			}

			$target_id   = isset( $input['target_container_id'] ) ? trim( (string) $input['target_container_id'] ) : '';
			$has_position = array_key_exists( 'position', $input ) && '' !== trim( (string) $input['position'] );
			$position     = $has_position ? absint( $input['position'] ) : null;

			if ( '' === $target_id && null === $position ) {
				return new WP_Error(
					'nothing_to_move',
					__( 'Provide a position to reorder the element where it is, or a target_container_id to move it somewhere else.', 'mosmcp-abilities' )
				);
			}

			$origin_parent = array_slice( $path, 0, -1 );
			$origin_index  = (int) $path[ count( $path ) - 1 ];

			$target_parent = $origin_parent;

			if ( '' !== $target_id ) {
				$target_path = Elementor_Write_Engine::resolve_one( $tree, $target_id );
				if ( is_wp_error( $target_path ) ) {
					return $target_path;
				}

				$target_node = Elementor_Write_Engine::node_at( $tree, $target_path );
				if ( ! is_array( $target_node ) || ! self::accepts_children( $target_node ) ) {
					return new WP_Error(
						'target_cannot_hold_elements',
						sprintf(
							/* translators: %s: target element ID */
							__( 'Element "%s" cannot contain other elements. Move into a container, section or column instead.', 'mosmcp-abilities' ),
							$target_id
						)
					);
				}

				if ( $target_id === $element_id ) {
					return new WP_Error(
						'target_is_self',
						__( 'An element cannot be moved inside itself.', 'mosmcp-abilities' )
					);
				}

				/*
				 * A target whose path begins with the source path is inside the
				 * subtree being moved. Allowing it would detach that subtree from
				 * the document entirely.
				 */
				if ( self::is_descendant_path( $path, $target_path ) ) {
					return new WP_Error(
						'target_is_descendant',
						sprintf(
							/* translators: 1: element being moved, 2: target element */
							__( 'Element "%2$s" sits inside "%1$s", so "%1$s" cannot be moved into it.', 'mosmcp-abilities' ),
							$element_id,
							$target_id
						)
					);
				}

				$target_parent = $target_path;
			}

			$removed = self::remove( $tree, $path );
			if ( is_wp_error( $removed ) ) {
				return $removed;
			}

			/*
			 * Re-resolve the destination after the removal. Removing the source
			 * shifts every later sibling, so the path captured above may no longer
			 * point at the same node; IDs are stable, so resolve by ID instead.
			 */
			if ( '' !== $target_id ) {
				$target_parent = Elementor_Write_Engine::resolve_one( $tree, $target_id );
				if ( is_wp_error( $target_parent ) ) {
					return $target_parent;
				}
			}

			/*
			 * position is the index the element should end up at, counted in the
			 * destination as it looks after the element has been taken out. That is
			 * what a caller means by "put it third", and it makes the same number
			 * behave identically whether the element is being reordered in place or
			 * moved in from elsewhere. No adjustment for the removal is applied.
			 */

			$insert_at = null === $position ? self::child_count( $tree, $target_parent ) : $position;

			$inserted = self::insert( $tree, $target_parent, $insert_at, $removed );
			if ( is_wp_error( $inserted ) ) {
				return $inserted;
			}

			$result['changes'][] = array(
				'action'      => 'move',
				'element_id'  => $element_id,
				'widget_type' => self::slug_of( $node ),
				'detail'      => '' !== $target_id
					? sprintf(
						/* translators: 1: target container ID, 2: position index */
						__( 'Moved into container %1$s at position %2$d.', 'mosmcp-abilities' ),
						$target_id,
						$insert_at
					)
					: sprintf(
						/* translators: 1: old position, 2: new position */
						__( 'Reordered from position %1$d to position %2$d among its siblings.', 'mosmcp-abilities' ),
						$origin_index,
						$insert_at
					),
			);

			return true;
		};
	}

	/**
	 * Removes the node at a path and returns it.
	 *
	 * @param array<int, mixed> $tree Tree, modified in place.
	 * @param int[]             $path Index path.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function remove( array &$tree, array $path ) {
		if ( ! $path ) {
			return new WP_Error( 'invalid_path', __( 'Internal error: an empty element path was given.', 'mosmcp-abilities' ) );
		}

		$index       = (int) array_pop( $path );
		$parent_path = $path;

		if ( ! $parent_path ) {
			if ( ! isset( $tree[ $index ] ) ) {
				return new WP_Error( 'invalid_path', __( 'Internal error: the element path no longer resolves.', 'mosmcp-abilities' ) );
			}
			$node = $tree[ $index ];
			unset( $tree[ $index ] );
			// Reindex: gaps in integer keys encode as a JSON object, not an array.
			$tree = array_values( $tree );
			return $node;
		}

		$node = null;

		$applied = Elementor_Write_Engine::apply_at(
			$tree,
			$parent_path,
			static function ( array &$parent ) use ( $index, &$node ) {
				if ( ! isset( $parent['elements'] ) || ! is_array( $parent['elements'] ) || ! isset( $parent['elements'][ $index ] ) ) {
					return new WP_Error( 'invalid_path', __( 'Internal error: the element is no longer where it was found.', 'mosmcp-abilities' ) );
				}
				$node = $parent['elements'][ $index ];
				unset( $parent['elements'][ $index ] );
				$parent['elements'] = array_values( $parent['elements'] );
				return true;
			}
		);

		if ( is_wp_error( $applied ) ) {
			return $applied;
		}

		return is_array( $node ) ? $node : new WP_Error( 'invalid_path', __( 'Internal error: the element could not be removed.', 'mosmcp-abilities' ) );
	}

	/**
	 * Inserts a node into a container's children at a given index.
	 *
	 * @param array<int, mixed>    $tree           Tree, modified in place.
	 * @param int[]                $container_path Path of the container, or empty for top level.
	 * @param int                  $index          Insertion index, clamped to the child count.
	 * @param array<string, mixed> $node           Node to insert.
	 * @return true|WP_Error
	 */
	public static function insert( array &$tree, array $container_path, $index, array $node ) {
		$index = max( 0, (int) $index );

		if ( ! $container_path ) {
			$index = min( $index, count( $tree ) );
			array_splice( $tree, $index, 0, array( $node ) );
			return true;
		}

		return Elementor_Write_Engine::apply_at(
			$tree,
			$container_path,
			static function ( array &$parent ) use ( $index, $node ) {
				if ( ! isset( $parent['elements'] ) || ! is_array( $parent['elements'] ) ) {
					$parent['elements'] = array();
				}
				$at = min( $index, count( $parent['elements'] ) );
				array_splice( $parent['elements'], $at, 0, array( $node ) );
				return true;
			}
		);
	}

	/**
	 * How many children a container currently holds.
	 *
	 * @param array<int, mixed> $tree           Tree.
	 * @param int[]             $container_path Container path, or empty for top level.
	 * @return int
	 */
	private static function child_count( array $tree, array $container_path ) {
		if ( ! $container_path ) {
			return count( $tree );
		}
		$node = Elementor_Write_Engine::node_at( $tree, $container_path );
		return ( is_array( $node ) && isset( $node['elements'] ) && is_array( $node['elements'] ) ) ? count( $node['elements'] ) : 0;
	}

	/**
	 * Whether $candidate lies at or below $ancestor in the tree.
	 *
	 * @param int[] $ancestor  Ancestor path.
	 * @param int[] $candidate Candidate path.
	 * @return bool
	 */
	private static function is_descendant_path( array $ancestor, array $candidate ) {
		if ( count( $candidate ) < count( $ancestor ) ) {
			return false;
		}
		foreach ( $ancestor as $depth => $index ) {
			if ( (int) $candidate[ $depth ] !== (int) $index ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether a node can hold children.
	 *
	 * @param array<string, mixed> $node Node.
	 * @return bool
	 */
	private static function accepts_children( array $node ) {
		$el_type = isset( $node['elType'] ) ? (string) $node['elType'] : '';

		if ( in_array( $el_type, self::CONTAINER_TYPES, true ) ) {
			return true;
		}

		// Atomic containers register as element types rather than widgets, so ask
		// the registry rather than maintaining a second list of v4 container slugs.
		if ( 'widget' !== $el_type && '' !== $el_type ) {
			$element = Elementor_Schema::element( $el_type );
			if ( $element && Elementor_Schema::is_atomic( $element ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Every element ID currently present in the tree.
	 *
	 * @param array<int, mixed> $tree Tree.
	 * @return array<string, bool>
	 */
	public static function collect_ids( array $tree ) {
		$ids = array();

		Elementor_Document::walk(
			$tree,
			static function ( $node ) use ( &$ids ) {
				if ( isset( $node['id'] ) && '' !== $node['id'] ) {
					$ids[ (string) $node['id'] ] = true;
				}
				return true;
			}
		);

		return $ids;
	}

	/**
	 * Gives a node and all its descendants fresh, unused element IDs.
	 *
	 * @param array<string, mixed> $node Node, modified in place.
	 * @param array<string, bool>  $used IDs already taken, added to as IDs are minted.
	 * @return void
	 */
	public static function regenerate_ids( array &$node, array &$used ) {
		$id           = self::mint_id( $used );
		$used[ $id ]  = true;
		$node['id']   = $id;

		if ( ! empty( $node['elements'] ) && is_array( $node['elements'] ) ) {
			foreach ( $node['elements'] as $index => $child ) {
				if ( is_array( $child ) ) {
					self::regenerate_ids( $child, $used );
					$node['elements'][ $index ] = $child;
				}
			}
		}
	}

	/**
	 * Mints an element ID in Elementor's format that is not already in use.
	 *
	 * @param array<string, bool> $used IDs already taken.
	 * @return string
	 */
	private static function mint_id( array $used ) {
		$alphabet = '0123456789abcdef';

		for ( $attempt = 0; $attempt < 50; $attempt++ ) {
			$id = '';
			for ( $i = 0; $i < self::ID_LENGTH; $i++ ) {
				$id .= $alphabet[ wp_rand( 0, strlen( $alphabet ) - 1 ) ];
			}
			if ( ! isset( $used[ $id ] ) ) {
				return $id;
			}
		}

		// Practically unreachable; keeps the contract that an ID is always returned.
		return substr( str_replace( '-', '', wp_generate_uuid4() ), 0, self::ID_LENGTH );
	}

	/**
	 * Counts every element nested inside a node.
	 *
	 * @param array<string, mixed> $node Node.
	 * @return int
	 */
	private static function count_descendants( array $node ) {
		if ( empty( $node['elements'] ) || ! is_array( $node['elements'] ) ) {
			return 0;
		}

		$count = 0;
		Elementor_Document::walk(
			$node['elements'],
			static function () use ( &$count ) {
				++$count;
				return true;
			}
		);

		return $count;
	}

	/**
	 * The widget or element slug of a node.
	 *
	 * @param array<string, mixed> $node Node.
	 * @return string
	 */
	private static function slug_of( array $node ) {
		if ( isset( $node['widgetType'] ) && '' !== $node['widgetType'] ) {
			return (string) $node['widgetType'];
		}
		return isset( $node['elType'] ) ? (string) $node['elType'] : '';
	}
}
