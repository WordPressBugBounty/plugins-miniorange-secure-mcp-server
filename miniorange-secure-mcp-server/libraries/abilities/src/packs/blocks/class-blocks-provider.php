<?php
/**
 * Execute logic for the block editor abilities.
 *
 * Every write follows one sequence: resolve the post and refuse what cannot be
 * edited block by block, check the "changed since you read it" lock, parse the
 * content, change only the target, check the new markup against the HTML rule,
 * save with a round-trip guard, and read back what WordPress stored. The result
 * always says where to see the change and which revision holds the old version.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Blocks;

use MoSMCP\Abilities\Packs\Site\Site_Support;
use WP_Block_Type_Registry;
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
 * directive covers IDE and Plugin Check runs that don't read that ruleset).
 */
// phpcs:disable WordPress.WP.I18n.TextDomainMismatch

/**
 * Class Blocks_Provider
 */
class Blocks_Provider {

	/**
	 * Where a block can be put, relative to a target block.
	 *
	 * @var string[]
	 */
	const POSITIONS = array( 'start', 'end', 'before', 'after', 'inside_start', 'inside_end' );

	/**
	 * Lists the blocks of a post as a flat outline with paths.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function list_blocks( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$post  = Blocks_Support::require_post( $input, false );
		if ( $post instanceof WP_Error ) {
			return $post;
		}

		$tree      = Block_Tree::parse( $post->post_content );
		$max_depth = isset( $input['max_depth'] ) ? max( 0, min( 50, (int) $input['max_depth'] ) ) : 50;
		$per_page  = isset( $input['per_page'] ) ? max( 1, min( 500, (int) $input['per_page'] ) ) : 200;
		$offset    = isset( $input['offset'] ) ? max( 0, (int) $input['offset'] ) : 0;
		$rows      = array();

		Block_Tree::walk(
			$tree,
			static function ( $node, $indices ) use ( &$rows, $max_depth ) {
				if ( count( $indices ) - 1 <= $max_depth ) {
					$rows[] = Blocks_Support::outline_row( $node, $indices );
				}
			}
		);

		$kind  = Blocks_Support::editor_kind( $post, $tree );
		$page  = array_slice( $rows, $offset, $per_page );
		$notes = self::kind_notes( $kind );

		if ( $offset + count( $page ) < count( $rows ) ) {
			$notes[] = sprintf(
				/* translators: %d: offset for the next page. */
				__( 'More blocks follow. Call again with offset %d to continue.', 'mosmcp-abilities' ),
				$offset + count( $page )
			);
		}

		return array_merge(
			Blocks_Support::post_context( $post ),
			array(
				'editor'       => $kind,
				'total_blocks' => count( $rows ),
				'showing'      => count( $page ),
				'offset'       => $offset,
				'truncated'    => $offset + count( $page ) < count( $rows ),
				'blocks'       => $page,
				'notes'        => $notes,
			)
		);
	}

	/**
	 * Returns one block in full: attributes, markup and text.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function get_block( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$post  = Blocks_Support::require_post( $input, false );
		if ( $post instanceof WP_Error ) {
			return $post;
		}

		$indices = Block_Tree::parse_path( isset( $input['path'] ) ? $input['path'] : '' );
		if ( $indices instanceof WP_Error ) {
			return $indices;
		}

		$tree = Block_Tree::parse( $post->post_content );
		$node = Block_Tree::require_node( $tree, $indices );
		if ( $node instanceof WP_Error ) {
			return $node;
		}

		$row   = Blocks_Support::outline_row( $node, $indices );
		$inner = array();

		foreach ( Block_Tree::children( $node ) as $i => $child ) {
			$child_path   = $indices;
			$child_path[] = (int) $i;
			$inner[]      = array(
				'path'  => Block_Tree::path_string( $child_path ),
				'block' => isset( $child['blockName'] ) ? (string) $child['blockName'] : 'core/freeform',
				'text'  => Block_Tree::text( $child, 140 ),
			);
		}

		return array_merge(
			Blocks_Support::post_context( $post ),
			array(
				'path'            => $row['path'],
				'block'           => $row['block'],
				'block_title'     => $row['title'],
				'attrs'           => isset( $node['attrs'] ) && is_array( $node['attrs'] ) ? (object) $node['attrs'] : new \stdClass(),
				'markup'          => Block_Tree::serialize( array( $node ) ),
				'text'            => Block_Tree::text( $node ),
				'fields'          => $row['fields'],
				'is_dynamic'      => $row['is_dynamic'],
				'settings_only'   => Block_Tree::is_comment_only( $node ),
				'inner_blocks'    => $inner,
				'editing_options' => self::editing_options( $node ),
			)
		);
	}

	/**
	 * Finds blocks by type, text or attribute.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function find_blocks( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$post  = Blocks_Support::require_post( $input, false );
		if ( $post instanceof WP_Error ) {
			return $post;
		}

		$type       = isset( $input['block'] ) ? Blocks_Support::normalize_name( (string) $input['block'] ) : '';
		$text       = isset( $input['text'] ) ? strtolower( trim( (string) $input['text'] ) ) : '';
		$attr       = isset( $input['attribute'] ) ? trim( (string) $input['attribute'] ) : '';
		$attr_value = isset( $input['attribute_value'] ) ? (string) $input['attribute_value'] : null;
		$limit      = isset( $input['per_page'] ) ? max( 1, min( 200, (int) $input['per_page'] ) ) : 50;

		if ( '' === $type && '' === $text && '' === $attr ) {
			return Site_Support::error(
				'mosmcp_block_find_empty',
				__( 'Say what to look for: a block type in "block" (such as "heading" or "core/image"), words in "text", or an attribute name in "attribute".', 'mosmcp-abilities' ),
				Site_Support::CAUSE_INVALID_INPUT,
				true
			);
		}

		$tree    = Block_Tree::parse( $post->post_content );
		$matches = array();
		$total   = 0;

		Block_Tree::walk(
			$tree,
			static function ( $node, $indices ) use ( $type, $text, $attr, $attr_value, $limit, &$matches, &$total ) {
				$name = isset( $node['blockName'] ) && null !== $node['blockName'] ? (string) $node['blockName'] : 'core/freeform';

				if ( '' !== $type && $name !== $type ) {
					return;
				}

				if ( '' !== $attr ) {
					$attrs = isset( $node['attrs'] ) && is_array( $node['attrs'] ) ? $node['attrs'] : array();
					if ( ! array_key_exists( $attr, $attrs ) ) {
						return;
					}
					if ( null !== $attr_value && ( ! is_scalar( $attrs[ $attr ] ) || (string) $attrs[ $attr ] !== $attr_value ) ) {
						return;
					}
				}

				if ( '' !== $text ) {
					// Match the block's own text, not its inner blocks', so a match
					// points at the block that holds the words.
					$own = $node;
					if ( ! empty( $own['innerBlocks'] ) ) {
						$own['innerBlocks']  = array();
						$own['innerContent'] = array_values( array_filter( Block_Tree::inner_content( $own ), 'is_string' ) );
					}
					if ( false === strpos( strtolower( Block_Tree::text( $own ) ), $text ) ) {
						return;
					}
				}

				++$total;
				if ( count( $matches ) < $limit ) {
					$matches[] = Blocks_Support::outline_row( $node, $indices );
				}
			}
		);

		return array_merge(
			Blocks_Support::post_context( $post ),
			array(
				'total'     => $total,
				'showing'   => count( $matches ),
				'truncated' => $total > count( $matches ),
				'matches'   => $matches,
			)
		);
	}

	/**
	 * Lists the block types registered on the site.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public static function list_types( $input = array() ) {
		$input    = is_array( $input ) ? $input : array();
		$search   = isset( $input['search'] ) ? strtolower( trim( (string) $input['search'] ) ) : '';
		$category = isset( $input['category'] ) ? sanitize_key( (string) $input['category'] ) : '';
		$name     = isset( $input['name'] ) ? Blocks_Support::normalize_name( (string) $input['name'] ) : '';
		$with     = '' !== $name || Site_Support::bool_input( $input, 'include_attributes', false );
		$per_page = isset( $input['per_page'] ) ? max( 1, min( 200, (int) $input['per_page'] ) ) : 50;
		$offset   = isset( $input['offset'] ) ? max( 0, (int) $input['offset'] ) : 0;
		$rows     = array();

		foreach ( WP_Block_Type_Registry::get_instance()->get_all_registered() as $type_name => $type ) {
			if ( '' !== $name && $type_name !== $name ) {
				continue;
			}
			if ( '' !== $category && (string) $type->category !== $category ) {
				continue;
			}
			if ( '' !== $search ) {
				$hay = strtolower( $type_name . ' ' . $type->title . ' ' . $type->description . ' ' . implode( ' ', (array) $type->keywords ) );
				if ( false === strpos( $hay, $search ) ) {
					continue;
				}
			}

			$rows[] = self::describe_type( $type, $with );
		}

		usort(
			$rows,
			static function ( $a, $b ) {
				return strcmp( $a['name'], $b['name'] );
			}
		);

		$page  = array_slice( $rows, $offset, $per_page );
		$notes = array();

		if ( '' !== $name && empty( $rows ) ) {
			$notes[] = sprintf(
				/* translators: %s: block name. */
				__( 'No block type named %s is registered here. Search by keyword with "search" instead.', 'mosmcp-abilities' ),
				$name
			);
		}

		return array(
			'total'     => count( $rows ),
			'showing'   => count( $page ),
			'offset'    => $offset,
			'truncated' => $offset + count( $page ) < count( $rows ),
			'types'     => $page,
			'notes'     => $notes,
		);
	}

	/**
	 * Changes one text field of a block.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function update_text( $input = array() ) {
		$input = is_array( $input ) ? $input : array();

		return self::write(
			$input,
			static function ( array &$tree, WP_Post $post, array &$warnings ) use ( $input ) {
				$indices = self::target( $tree, $input );
				if ( $indices instanceof WP_Error ) {
					return $indices;
				}

				$field = isset( $input['field'] ) ? (string) $input['field'] : 'text';
				$text  = isset( $input['text'] ) ? (string) $input['text'] : '';

				$html = Blocks_Support::check_html( $text, $post, $warnings );
				if ( $html instanceof WP_Error ) {
					return $html;
				}

				$previous = '';
				$failure  = null;
				Block_Tree::mutate(
					$tree,
					$indices,
					static function ( array &$node ) use ( $field, $text, &$previous, &$failure ) {
						$set = Block_Text::set( $node, $field, $text );
						if ( $set instanceof WP_Error ) {
							$failure = $set;
							return;
						}
						$previous = $set['previous'];
					}
				);

				if ( $failure instanceof WP_Error ) {
					return $failure;
				}

				$node = Block_Tree::node_at( $tree, $indices );

				return array(
					'_changed' => trim( $previous ) !== trim( $text ),
					'path'     => Block_Tree::path_string( $indices ),
					'block'    => (string) $node['blockName'],
					'field'    => '' === $field ? 'text' : $field,
					'previous' => $previous,
					'current'  => $text,
				);
			}
		);
	}

	/**
	 * Changes the settings of a block whose output is built from settings alone.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function update_attributes( $input = array() ) {
		$input = is_array( $input ) ? $input : array();

		return self::write(
			$input,
			static function ( array &$tree, WP_Post $post, array &$warnings ) use ( $input ) {
				unset( $post, $warnings );

				$indices = self::target( $tree, $input );
				if ( $indices instanceof WP_Error ) {
					return $indices;
				}

				$node = Block_Tree::node_at( $tree, $indices );
				$name = isset( $node['blockName'] ) ? (string) $node['blockName'] : '';
				$type = '' !== $name ? WP_Block_Type_Registry::get_instance()->get_registered( $name ) : null;

				if ( ! $type ) {
					return Site_Support::error(
						'mosmcp_block_attrs_unknown_type',
						__( 'This block\'s type is not registered on the site, so its settings cannot be checked. Rewrite it with mosmcp/block-replace.', 'mosmcp-abilities' ),
						Site_Support::CAUSE_INVALID_INPUT,
						false,
						array( 'use_instead' => 'mosmcp/block-replace' )
					);
				}

				if ( ! Block_Tree::is_comment_only( $node ) ) {
					return Site_Support::error(
						'mosmcp_block_attrs_has_markup',
						trim(
							sprintf(
								/* translators: %s: block name. */
								__( 'The %s block saves HTML built from its settings (classes, styles), so changing a setting alone would leave that HTML out of step and the editor would report the block as invalid. Change it with mosmcp/block-replace, writing the new settings and matching HTML together (read the current markup with mosmcp/block-get).', 'mosmcp-abilities' ),
								$name
							) . ' ' . implode( ' ', Blocks_Support::plugin_hints( $name ) )
						),
						Site_Support::CAUSE_INVALID_INPUT,
						false,
						array( 'use_instead' => 'mosmcp/block-replace' )
					);
				}

				$set    = isset( $input['attributes'] ) && is_array( $input['attributes'] ) ? $input['attributes'] : array();
				$remove = isset( $input['remove'] ) && is_array( $input['remove'] ) ? array_map( 'strval', $input['remove'] ) : array();

				if ( empty( $set ) && empty( $remove ) ) {
					return Site_Support::error(
						'mosmcp_block_attrs_empty',
						__( 'Pass the settings to change in "attributes", or the names to clear in "remove".', 'mosmcp-abilities' ),
						Site_Support::CAUSE_INVALID_INPUT,
						true
					);
				}

				$defs    = is_array( $type->attributes ) ? $type->attributes : array();
				$allowed = array();
				foreach ( $defs as $attr_name => $def ) {
					if ( empty( $def['source'] ) ) {
						$allowed[] = (string) $attr_name;
					}
				}

				foreach ( array_merge( array_keys( $set ), $remove ) as $key ) {
					if ( ! in_array( (string) $key, $allowed, true ) ) {
						return Site_Support::error(
							'mosmcp_block_attrs_unknown',
							sprintf(
								/* translators: 1: attribute name, 2: block name, 3: allowed attribute names. */
								__( '"%1$s" is not a setting of the %2$s block. Its settings are: %3$s.', 'mosmcp-abilities' ),
								(string) $key,
								$name,
								implode( ', ', $allowed )
							),
							Site_Support::CAUSE_INVALID_INPUT,
							true
						);
					}
				}

				foreach ( $set as $key => $value ) {
					$valid = rest_validate_value_from_schema( $value, $defs[ $key ], (string) $key );
					if ( is_wp_error( $valid ) ) {
						return Site_Support::error(
							'mosmcp_block_attrs_invalid',
							sprintf(
								/* translators: 1: attribute name, 2: validation message. */
								__( 'The value for "%1$s" is not valid: %2$s', 'mosmcp-abilities' ),
								(string) $key,
								$valid->get_error_message()
							),
							Site_Support::CAUSE_INVALID_INPUT,
							true
						);
					}
				}

				$previous = isset( $node['attrs'] ) && is_array( $node['attrs'] ) ? $node['attrs'] : array();

				Block_Tree::mutate(
					$tree,
					$indices,
					static function ( array &$target ) use ( $set, $remove ) {
						$attrs = isset( $target['attrs'] ) && is_array( $target['attrs'] ) ? $target['attrs'] : array();
						foreach ( $set as $key => $value ) {
							$attrs[ $key ] = $value;
						}
						foreach ( $remove as $key ) {
							unset( $attrs[ $key ] );
						}
						$target['attrs'] = $attrs;
					}
				);

				$after = Block_Tree::node_at( $tree, $indices );

				return array(
					'_changed' => $previous !== $after['attrs'],
					'path'     => Block_Tree::path_string( $indices ),
					'block'    => $name,
					'previous' => empty( $previous ) ? new \stdClass() : (object) $previous,
					'current'  => empty( $after['attrs'] ) ? new \stdClass() : (object) $after['attrs'],
				);
			}
		);
	}

	/**
	 * Replaces one block with new markup.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function replace( $input = array() ) {
		$input = is_array( $input ) ? $input : array();

		return self::write(
			$input,
			static function ( array &$tree, WP_Post $post, array &$warnings ) use ( $input ) {
				$indices = self::target( $tree, $input );
				if ( $indices instanceof WP_Error ) {
					return $indices;
				}

				$nodes = Block_Markup::parse_supplied( isset( $input['markup'] ) ? (string) $input['markup'] : '' );
				if ( $nodes instanceof WP_Error ) {
					return $nodes;
				}

				$old         = Block_Tree::node_at( $tree, $indices );
				$parent_path = array_slice( $indices, 0, -1 );
				$check       = self::check_new_nodes( $tree, $nodes, $parent_path, $post, $warnings, $indices );
				if ( $check instanceof WP_Error ) {
					return $check;
				}

				Block_Tree::remove( $tree, $indices );
				$inserted = Block_Tree::insert( $tree, $parent_path, (int) end( $indices ), $nodes );
				if ( $inserted instanceof WP_Error ) {
					return $inserted;
				}

				return array(
					'_changed'       => Block_Tree::serialize( array( $old ) ) !== Block_Tree::serialize( $nodes ),
					'path'           => Block_Tree::path_string( $indices ),
					'block'          => (string) $nodes[0]['blockName'],
					'replaced_block' => (string) $old['blockName'],
					'paths'          => self::sibling_paths( $parent_path, (int) end( $indices ), count( $nodes ) ),
				);
			}
		);
	}

	/**
	 * Inserts new blocks.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function insert( $input = array() ) {
		$input = is_array( $input ) ? $input : array();

		return self::write(
			$input,
			static function ( array &$tree, WP_Post $post, array &$warnings ) use ( $input ) {
				$nodes = self::nodes_from_input( $input );
				if ( $nodes instanceof WP_Error ) {
					return $nodes;
				}

				$dest = self::destination( $tree, $input );
				if ( $dest instanceof WP_Error ) {
					return $dest;
				}

				$check = self::check_new_nodes( $tree, $nodes, $dest['parent'], $post, $warnings, null );
				if ( $check instanceof WP_Error ) {
					return $check;
				}

				$inserted = Block_Tree::insert( $tree, $dest['parent'], $dest['index'], $nodes );
				if ( $inserted instanceof WP_Error ) {
					return $inserted;
				}

				$paths = self::sibling_paths( $dest['parent'], $dest['index'], count( $nodes ) );

				return array(
					'_changed' => true,
					'path'     => $paths[0],
					'block'    => (string) $nodes[0]['blockName'],
					'paths'    => $paths,
				);
			}
		);
	}

	/**
	 * Moves a block to another position.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function move( $input = array() ) {
		$input = is_array( $input ) ? $input : array();

		return self::write(
			$input,
			static function ( array &$tree, WP_Post $post, array &$warnings ) use ( $input ) {
				unset( $post, $warnings );

				$source = self::target( $tree, $input );
				if ( $source instanceof WP_Error ) {
					return $source;
				}

				$dest = self::destination( $tree, $input );
				if ( $dest instanceof WP_Error ) {
					return $dest;
				}

				if ( Block_Tree::is_within( $dest['parent'], $source ) ) {
					return Site_Support::error(
						'mosmcp_block_move_into_self',
						__( 'A block cannot be moved inside itself or inside one of its own inner blocks.', 'mosmcp-abilities' ),
						Site_Support::CAUSE_INVALID_INPUT,
						true
					);
				}

				$node      = Block_Tree::node_at( $tree, $source );
				$ancestors = Blocks_Support::ancestor_names( $tree, $dest['parent'] );
				$placement = Block_Markup::check_placement( array( $node ), empty( $ancestors ) ? null : $ancestors[0], $ancestors );
				if ( $placement instanceof WP_Error ) {
					return $placement;
				}

				$target   = $dest['parent'];
				$target[] = $dest['index'];
				$target   = Block_Tree::shift_after_removal( $target, $source );

				Block_Tree::remove( $tree, $source );

				$index    = (int) array_pop( $target );
				$inserted = Block_Tree::insert( $tree, $target, $index, array( $node ) );
				if ( $inserted instanceof WP_Error ) {
					return $inserted;
				}

				$now   = $target;
				$now[] = $index;

				return array(
					'_changed'  => $now !== $source,
					'path'      => Block_Tree::path_string( $now ),
					'from_path' => Block_Tree::path_string( $source ),
					'block'     => (string) $node['blockName'],
				);
			}
		);
	}

	/**
	 * Duplicates a block directly after itself.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function duplicate( $input = array() ) {
		$input = is_array( $input ) ? $input : array();

		return self::write(
			$input,
			static function ( array &$tree, WP_Post $post, array &$warnings ) use ( $input ) {
				unset( $post );

				$indices = self::target( $tree, $input );
				if ( $indices instanceof WP_Error ) {
					return $indices;
				}

				$node = Block_Tree::node_at( $tree, $indices );

				// The copy is identical to the original, so a plugin that keys anything to a per-block ID refuses here.
				$allowed = Blocks_Support::plugin_check( array( $node ), $tree, 'duplicate' );
				if ( $allowed instanceof WP_Error ) {
					return $allowed;
				}

				$anchors = 0;
				$copy    = self::without_anchors( $node, $anchors );
				if ( $anchors > 0 ) {
					$warnings[] = array(
						'code'    => 'anchors_cleared',
						'message' => __( 'The copy\'s HTML anchors were cleared so the page does not end up with two elements sharing one ID. Set a new anchor on the copy if links point to it.', 'mosmcp-abilities' ),
						'context' => 'anchors=' . $anchors,
					);
				}

				$parent_path = array_slice( $indices, 0, -1 );
				$position    = (int) end( $indices ) + 1;
				$inserted    = Block_Tree::insert( $tree, $parent_path, $position, array( $copy ) );
				if ( $inserted instanceof WP_Error ) {
					return $inserted;
				}

				$new   = $parent_path;
				$new[] = $position;

				return array(
					'_changed'  => true,
					'path'      => Block_Tree::path_string( $new ),
					'from_path' => Block_Tree::path_string( $indices ),
					'block'     => (string) $node['blockName'],
				);
			}
		);
	}

	/**
	 * Deletes a block and its inner blocks.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function delete( $input = array() ) {
		$input = is_array( $input ) ? $input : array();

		return self::write(
			$input,
			static function ( array &$tree, WP_Post $post, array &$warnings ) use ( $input ) {
				unset( $post, $warnings );

				$indices = self::target( $tree, $input );
				if ( $indices instanceof WP_Error ) {
					return $indices;
				}

				$node  = Block_Tree::node_at( $tree, $indices );
				$inner = 0;
				Block_Tree::walk(
					array( $node ),
					static function () use ( &$inner ) {
						++$inner;
					}
				);

				Block_Tree::remove( $tree, $indices );

				return array(
					'_changed'       => true,
					'path'           => Block_Tree::path_string( $indices ),
					'block'          => null !== $node['blockName'] ? (string) $node['blockName'] : 'core/freeform',
					'removed_text'   => Block_Tree::text( $node, 200 ),
					'blocks_removed' => $inner,
				);
			}
		);
	}

	/**
	 * Runs one write: guards, change, save, verification and result.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @param callable             $op    function ( array &$tree, WP_Post $post, array &$warnings ): array|WP_Error.
	 *                                    Returns the ability-specific result fields, with '_changed' saying
	 *                                    whether anything needs saving.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function write( array $input, callable $op ) {
		$post = Blocks_Support::require_post( $input, true );
		if ( $post instanceof WP_Error ) {
			return $post;
		}

		$lock = Blocks_Support::check_lock( $post, $input );
		if ( $lock instanceof WP_Error ) {
			return $lock;
		}

		$tree     = Block_Tree::parse( $post->post_content );
		$warnings = array();
		$result   = $op( $tree, $post, $warnings );

		if ( $result instanceof WP_Error ) {
			return $result;
		}

		$changed = ! empty( $result['_changed'] );
		unset( $result['_changed'] );

		if ( $changed ) {
			// Captured before saving: it is the version an undo restores.
			$revision = Blocks_Support::snapshot_revision( $post );

			$post = Blocks_Support::save( $post, $tree, $warnings );
			if ( $post instanceof WP_Error ) {
				return $post;
			}
			$notes = Blocks_Support::write_notes( $post, $revision );
		} else {
			$revision = 0;
			$notes    = array( __( 'Nothing needed changing: the block already had that content, so nothing was saved.', 'mosmcp-abilities' ) );
		}

		return array_merge(
			Blocks_Support::post_context( $post ),
			$result,
			array(
				'changed'     => $changed,
				'revision_id' => $revision,
				'warnings'    => $warnings,
				'notes'       => $notes,
			)
		);
	}

	/**
	 * Resolves input['path'] to an existing block and applies the expected-block check.
	 *
	 * @param array<int, array<string, mixed>> $tree  Block tree.
	 * @param array<string, mixed>             $input Ability input.
	 * @return int[]|WP_Error
	 */
	private static function target( array $tree, array $input ) {
		$indices = Block_Tree::parse_path( isset( $input['path'] ) ? $input['path'] : '' );
		if ( $indices instanceof WP_Error ) {
			return $indices;
		}

		$node = Block_Tree::require_node( $tree, $indices );
		if ( $node instanceof WP_Error ) {
			return $node;
		}

		$expected = Blocks_Support::check_expected_block( $node, $input, Block_Tree::path_string( $indices ) );

		return $expected instanceof WP_Error ? $expected : $indices;
	}

	/**
	 * Works out where new or moved blocks go.
	 *
	 * @param array<int, array<string, mixed>> $tree  Block tree.
	 * @param array<string, mixed>             $input Ability input.
	 * @return array{parent:int[],index:int}|WP_Error
	 */
	private static function destination( array $tree, array $input ) {
		$position = isset( $input['position'] ) ? (string) $input['position'] : 'end';

		if ( ! in_array( $position, self::POSITIONS, true ) ) {
			return Site_Support::error(
				'mosmcp_block_position_invalid',
				sprintf(
					/* translators: %s: list of positions. */
					__( 'Unknown position. Use one of: %s.', 'mosmcp-abilities' ),
					implode( ', ', self::POSITIONS )
				),
				Site_Support::CAUSE_INVALID_INPUT,
				true
			);
		}

		if ( 'start' === $position ) {
			return array(
				'parent' => array(),
				'index'  => 0,
			);
		}

		if ( 'end' === $position ) {
			return array(
				'parent' => array(),
				'index'  => count( Block_Tree::real_offsets( $tree ) ),
			);
		}

		if ( empty( $input['target'] ) && '0' !== (string) ( isset( $input['target'] ) ? $input['target'] : '' ) ) {
			return Site_Support::error(
				'mosmcp_block_target_missing',
				sprintf(
					/* translators: %s: position name. */
					__( 'Position "%s" is relative to another block: pass that block\'s path in "target".', 'mosmcp-abilities' ),
					$position
				),
				Site_Support::CAUSE_INVALID_INPUT,
				true
			);
		}

		$target = Block_Tree::parse_path( $input['target'] );
		if ( $target instanceof WP_Error ) {
			return $target;
		}

		$node = Block_Tree::require_node( $tree, $target );
		if ( $node instanceof WP_Error ) {
			return $node;
		}

		switch ( $position ) {
			case 'before':
				return array(
					'parent' => array_slice( $target, 0, -1 ),
					'index'  => (int) end( $target ),
				);
			case 'after':
				return array(
					'parent' => array_slice( $target, 0, -1 ),
					'index'  => (int) end( $target ) + 1,
				);
			case 'inside_start':
				return array(
					'parent' => $target,
					'index'  => 0,
				);
			default:
				return array(
					'parent' => $target,
					'index'  => count( Block_Tree::children( $node ) ),
				);
		}
	}

	/**
	 * Builds the blocks to insert from whichever source the caller gave.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	private static function nodes_from_input( array $input ) {
		$sources = array();
		foreach ( array( 'markup', 'blocks', 'pattern', 'synced_pattern_id' ) as $key ) {
			if ( isset( $input[ $key ] ) && '' !== $input[ $key ] && array() !== $input[ $key ] ) {
				$sources[] = $key;
			}
		}

		if ( 1 !== count( $sources ) ) {
			return Site_Support::error(
				'mosmcp_block_insert_source',
				__( 'Give exactly one thing to insert: "blocks" (simple paragraphs, headings, lists, buttons, images and so on, built for you), "markup" (block markup for any block), "pattern" (a registered pattern name) or "synced_pattern_id".', 'mosmcp-abilities' ),
				Site_Support::CAUSE_INVALID_INPUT,
				true
			);
		}

		switch ( $sources[0] ) {
			case 'blocks':
				return Block_Markup::from_shorthand( $input['blocks'] );
			case 'pattern':
				return Block_Markup::from_pattern( (string) $input['pattern'] );
			case 'synced_pattern_id':
				return Block_Markup::from_synced_pattern( (int) $input['synced_pattern_id'] );
			default:
				return Block_Markup::parse_supplied( (string) $input['markup'] );
		}
	}

	/**
	 * Checks blocks about to be placed: placement rules, the owning plugins' own rules, and HTML.
	 *
	 * @param array<int, array<string, mixed>>  $tree      Block tree.
	 * @param array<int, array<string, mixed>>  $nodes     Blocks being placed.
	 * @param int[]                             $parent_path    Parent path, or [] for the top level.
	 * @param WP_Post                           $post      Target post.
	 * @param array<int, array<string, string>> $warnings  Warnings accumulator.
	 * @param int[]|null                        $replacing Path of a block being replaced, excluded from the ID check.
	 * @return true|WP_Error
	 */
	private static function check_new_nodes( array $tree, array $nodes, array $parent_path, WP_Post $post, array &$warnings, $replacing ) {
		$ancestors = Blocks_Support::ancestor_names( $tree, $parent_path );
		$placement = Block_Markup::check_placement( $nodes, empty( $ancestors ) ? null : $ancestors[0], $ancestors );
		if ( $placement instanceof WP_Error ) {
			return $placement;
		}

		$others = $tree;
		if ( null !== $replacing ) {
			Block_Tree::remove( $others, $replacing );
		}

		$allowed = Blocks_Support::plugin_check( $nodes, $others, 'place' );
		if ( $allowed instanceof WP_Error ) {
			return $allowed;
		}

		return Blocks_Support::check_html( Block_Tree::serialize( $nodes ), $post, $warnings );
	}

	/**
	 * Paths of consecutive siblings.
	 *
	 * @param int[] $parent_path Parent path.
	 * @param int   $first  Index of the first one.
	 * @param int   $count  How many.
	 * @return string[]
	 */
	private static function sibling_paths( array $parent_path, $first, $count ) {
		$paths = array();

		for ( $i = 0; $i < $count; $i++ ) {
			$path    = $parent_path;
			$path[]  = $first + $i;
			$paths[] = Block_Tree::path_string( $path );
		}

		return $paths;
	}

	/**
	 * A copy of a block with its HTML anchors cleared, recursively.
	 *
	 * @param array<string, mixed> $node  Block node.
	 * @param int                  $count Anchors cleared (by reference).
	 * @return array<string, mixed>
	 */
	private static function without_anchors( array $node, &$count ) {
		if ( ! empty( $node['attrs']['anchor'] ) ) {
			$anchor = (string) $node['attrs']['anchor'];
			unset( $node['attrs']['anchor'] );
			++$count;

			$content = Block_Tree::inner_content( $node );
			foreach ( $content as $i => $chunk ) {
				if ( is_string( $chunk ) ) {
					$content[ $i ] = str_replace( ' id="' . $anchor . '"', '', $chunk );
				}
			}
			$node['innerContent'] = $content;
			$node['innerHTML']    = Block_Tree::html_of( $content );
		}

		foreach ( Block_Tree::children( $node ) as $i => $child ) {
			$node['innerBlocks'][ $i ] = self::without_anchors( $child, $count );
		}

		return $node;
	}

	/**
	 * Describes a block type for list_types().
	 *
	 * @param \WP_Block_Type $type Block type.
	 * @param bool           $with Whether to include attributes.
	 * @return array<string, mixed>
	 */
	private static function describe_type( $type, $with ) {
		$row = array(
			'name'           => (string) $type->name,
			'title'          => (string) $type->title,
			'description'    => (string) $type->description,
			'category'       => (string) $type->category,
			'is_dynamic'     => (bool) $type->is_dynamic(),
			'parent'         => is_array( $type->parent ) ? array_values( array_map( 'strval', $type->parent ) ) : array(),
			'ancestor'       => is_array( $type->ancestor ) ? array_values( array_map( 'strval', $type->ancestor ) ) : array(),
			'allowed_blocks' => isset( $type->allowed_blocks ) && is_array( $type->allowed_blocks ) ? array_values( array_map( 'strval', $type->allowed_blocks ) ) : array(),
			'attributes'     => array(),
		);

		if ( $with && is_array( $type->attributes ) ) {
			foreach ( $type->attributes as $name => $def ) {
				$kind                = isset( $def['type'] ) ? $def['type'] : '';
				$row['attributes'][] = array(
					'name'      => (string) $name,
					'type'      => is_array( $kind ) ? implode( '|', $kind ) : (string) $kind,
					'enum'      => isset( $def['enum'] ) && is_array( $def['enum'] ) ? array_values( array_map( 'strval', $def['enum'] ) ) : array(),
					'default'   => array_key_exists( 'default', $def ) ? (string) wp_json_encode( $def['default'] ) : '',
					'in_markup' => ! empty( $def['source'] ),
				);
			}
		}

		return $row;
	}

	/**
	 * How a block can be edited, for block-get.
	 *
	 * @param array<string, mixed> $node Block node.
	 * @return string[]
	 */
	private static function editing_options( array $node ) {
		$name    = isset( $node['blockName'] ) && null !== $node['blockName'] ? (string) $node['blockName'] : '';
		$options = array();

		if ( '' === $name ) {
			return array( __( 'Classic content: change wording with mosmcp/content-replace-in-post, or replace it with blocks using mosmcp/block-replace.', 'mosmcp-abilities' ) );
		}

		$fields = Block_Text::fields_for( $node );
		if ( $fields ) {
			/* translators: %s: comma-separated field names. */
			$options[] = sprintf( __( 'Change its text with mosmcp/block-update-text (fields: %s).', 'mosmcp-abilities' ), implode( ', ', $fields ) );
		}

		if ( Block_Tree::is_comment_only( $node ) ) {
			$options[] = __( 'Its output is built from its settings alone, so change them with mosmcp/block-update-attributes.', 'mosmcp-abilities' );
		} else {
			$options[] = __( 'Change its settings or styling with mosmcp/block-replace, editing the markup shown here (keep the settings in the comment and the HTML in step).', 'mosmcp-abilities' );
		}

		return array_merge( $options, Blocks_Support::plugin_hints( $name ) );
	}

	/**
	 * Notes explaining what kind of content a post has.
	 *
	 * @param string $kind Result of Blocks_Support::editor_kind().
	 * @return string[]
	 */
	private static function kind_notes( $kind ) {
		if ( 'classic' === $kind ) {
			return array( __( 'This content was written in the classic editor: it is one "Classic" block. Change its wording with mosmcp/content-replace-in-post; new blocks can still be inserted before or after it.', 'mosmcp-abilities' ) );
		}

		if ( 'empty' === $kind ) {
			return array( __( 'This content has no blocks yet. Add some with mosmcp/block-insert.', 'mosmcp-abilities' ) );
		}

		if ( 0 === strpos( $kind, 'builder:' ) ) {
			return array(
				sprintf(
					/* translators: %s: page builder name. */
					__( 'This page is built with %s. The blocks listed are what is stored in the post body, which the builder does not use for its layout; edit it with the builder\'s own abilities. Block edits are refused here.', 'mosmcp-abilities' ),
					substr( $kind, 8 )
				),
			);
		}

		return array();
	}
}
