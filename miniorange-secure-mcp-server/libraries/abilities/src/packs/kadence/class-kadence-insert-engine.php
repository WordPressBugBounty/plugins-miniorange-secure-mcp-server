<?php
/**
 * Insert engine for the Kadence block-insert abilities (Slice ④).
 *
 * Builds well-formed Kadence block nodes (blockName + attrs with a fresh,
 * collision-checked uniqueID + innerBlocks + innerHTML/innerContent) and places
 * them into a page's block tree at a resolved target, routing the save through
 * Kadence_Write_Engine::run() so inserts inherit the same duplicate guard,
 * optimistic locking, serialize validation, and read-after-write verification as
 * edits.
 *
 * Markup fidelity caveat: this engine constructs each block's saved innerHTML from
 * a best-effort template, since Kadence's real markup is produced by a JS save()
 * that has no PHP equivalent. Structure, nesting, attributes, and uniqueIDs are
 * correct and the front end renders from these; a static-save block may still show
 * "Attempt Block Recovery" the first time it is opened in the editor, which
 * regenerates canonical markup from the (correct) attributes. Validate on a live
 * Kadence install before relying on inserts in production (plan §17-A/B).
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Kadence;

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
 * Class Kadence_Insert_Engine
 */
class Kadence_Insert_Engine {

	/**
	 * Kadence blocks that may receive inserted children.
	 *
	 * @var string[]
	 */
	const CONTAINERS = array( 'kadence/rowlayout', 'kadence/column', 'kadence/pane', 'kadence/tab' );

	/**
	 * Containers whose body is pure InnerBlocks and which bake chrome into markup:
	 * appending into them is only markup-safe when they already hold a child to
	 * anchor the insert position.
	 *
	 * Why ALL containers, not just pane/tab: a container built by this engine in the
	 * same request holds its wrapper as two separate innerContent chunks (lead,
	 * trail) with no children between them, so this class's own splice logic can
	 * find the seam. But once that markup round-trips through a save and is
	 * re-parsed from the database, WordPress's real parse_blocks() sees no comment
	 * boundary inside an empty wrapper and returns it as ONE combined chunk — there
	 * is no reliable, block-agnostic way to know where inside that raw HTML string
	 * the wrapper's own closing tags begin, so guessing produces content placed
	 * outside the wrapper's own divs (verified live: a heading inserted into an
	 * empty, previously-saved column landed after the column's closing tags
	 * instead of inside them). Requiring an existing child avoids the guess
	 * entirely, since a real child's own comment gives an unambiguous split point.
	 *
	 * @var string[]
	 */
	const REQUIRE_ANCHOR = array( 'kadence/rowlayout', 'kadence/column', 'kadence/pane', 'kadence/tab' );

	/**
	 * Builds a parsed-block node with correct innerContent structure.
	 *
	 * A block is "container-shaped" whenever lead or trail markup is given, even
	 * with zero children — Kadence's container save() functions (row, column,
	 * pane, …) unconditionally render their wrapper markup regardless of whether
	 * InnerBlocks.Content is empty (verified against column/save.js: the wrapper
	 * divs are emitted with or without children). Treating an empty container as
	 * a bare leaf drops that wrapper and serializes a self-closing tag with no
	 * markup at all, which cannot match the block's real save() output. For a true
	 * leaf (no lead/trail), innerContent is the single HTML chunk as before.
	 *
	 * @param string                           $name  Block name, e.g. 'kadence/advancedheading'.
	 * @param array<string, mixed>             $attrs Block attributes (must include uniqueID).
	 * @param array<int, array<string, mixed>> $inner Child blocks (for containers).
	 * @param string                           $leaf  Leaf innerHTML (when there is no lead/trail).
	 * @param string                           $lead  Container opening markup.
	 * @param string                           $trail Container closing markup.
	 * @return array<string, mixed>
	 */
	public static function node( $name, array $attrs, array $inner = array(), $leaf = '', $lead = '', $trail = '' ) {
		$node = array(
			'blockName'   => (string) $name,
			'attrs'       => $attrs,
			'innerBlocks' => array_values( $inner ),
		);

		if ( '' !== $lead || '' !== $trail ) {
			$content = array();
			if ( '' !== $lead ) {
				$content[] = $lead;
			}
			foreach ( $inner as $ignored ) {
				$content[] = null;
			}
			if ( '' !== $trail ) {
				$content[] = $trail;
			}
			$node['innerHTML']    = $lead . $trail;
			$node['innerContent'] = $content;
		} else {
			$node['innerHTML']    = (string) $leaf;
			$node['innerContent'] = '' === (string) $leaf ? array() : array( (string) $leaf );
		}

		return $node;
	}

	/**
	 * Inserts a freshly built block into a post at a resolved target.
	 *
	 * @param array<string, mixed> $input   Ability input. Recognizes 'id' (required),
	 *                                       'parent_id' (container uniqueID to insert into),
	 *                                       'after_id' (sibling uniqueID to insert after),
	 *                                       'expected_modified' (optimistic lock).
	 * @param callable             $builder function ( array $blocks, int $post_id ): array|WP_Error
	 *                                       — returns the block node to insert (with a fresh uniqueID).
	 * @return array<string, mixed>|WP_Error
	 */
	public static function insert( array $input, callable $builder ) {
		$post_id = isset( $input['id'] ) ? absint( $input['id'] ) : 0;

		return Kadence_Write_Engine::run(
			$input,
			static function ( array &$blocks, array &$result ) use ( $input, $builder, $post_id ) {
				$block = $builder( $blocks, $post_id );
				if ( $block instanceof WP_Error ) {
					return $block;
				}

				$target = self::resolve_target( $blocks, $input );
				if ( $target instanceof WP_Error ) {
					return $target;
				}

				list( $parent_path, $index ) = $target;
				Kadence_Write_Engine::splice_children( $blocks, $parent_path, $index, 0, array( $block ) );

				$result['unique_id'] = Kadence_Blocks_Helper::unique_id( $block );
				$result['block']     = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';

				return true;
			}
		);
	}

	/**
	 * Generates a fresh uniqueID against the current tree.
	 *
	 * @param array<int, array<string, mixed>> $blocks  Current block tree.
	 * @param int                              $post_id Post the block will live on.
	 * @return string
	 */
	public static function new_id( array $blocks, $post_id ) {
		return Kadence_Blocks_Helper::generate_unique_id( $blocks, $post_id );
	}

	/**
	 * Dynamic-render blocks whose content lives only in attributes (no saved markup).
	 *
	 * @var string[]
	 */
	const DYNAMIC = array( 'kadence/singlebtn' );

	/**
	 * Blocks whose text/content maps to a single attribute, and which attribute.
	 *
	 * @var array<string, string>
	 */
	const TEXT_ATTR = array(
		'kadence/advancedheading' => 'content',
		'kadence/singlebtn'       => 'text',
	);

	/**
	 * Recursively builds a Kadence block node from a generic spec.
	 *
	 * A spec is: { block: string, attributes?: object, content?: string,
	 * children?: spec[] }. Each node gets a fresh uniqueID (its own, and one per
	 * descendant). Markup is templated for the blocks we know (row, column,
	 * heading) and falls back to attributes-only for the rest — same fidelity
	 * caveat as the rest of the insert engine.
	 *
	 * @param array<string, mixed>             $spec     The block spec.
	 * @param array<int, array<string, mixed>> $existing Current tree (+ siblings built so far), for uniqueID uniqueness.
	 * @param int                              $post_id  Post the block will live on.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function build( array $spec, array $existing, $post_id ) {
		$name = self::normalize_name( isset( $spec['block'] ) ? (string) $spec['block'] : '' );

		if ( '' === $name || 0 !== strpos( $name, Kadence_Blocks_Helper::PREFIX ) ) {
			return Kadence_Write_Engine::error( 'unsupported_block', __( 'The "block" must be a Kadence block name, e.g. "kadence/advancedheading".', 'mosmcp-abilities' ) );
		}
		if ( class_exists( 'WP_Block_Type_Registry' ) && ! \WP_Block_Type_Registry::get_instance()->is_registered( $name ) ) {
			return Kadence_Write_Engine::error( 'unsupported_block', sprintf( /* translators: %s: block name. */ __( 'Unknown block "%s". Use kadence-list-block-types to see valid names.', 'mosmcp-abilities' ), $name ) );
		}

		$attrs = isset( $spec['attributes'] ) && is_array( $spec['attributes'] ) ? $spec['attributes'] : array();
		$uid   = self::new_id( $existing, $post_id );

		$attrs['uniqueID'] = $uid;

		$content = array_key_exists( 'content', $spec ) ? (string) $spec['content'] : null;
		if ( null !== $content && isset( self::TEXT_ATTR[ $name ] ) ) {
			$attrs[ self::TEXT_ATTR[ $name ] ] = $content;
		}

		// Build children first, threading a growing set so their uniqueIDs never collide.
		$children  = array();
		$working   = $existing;
		$working[] = array( 'attrs' => array( 'uniqueID' => $uid ) );

		if ( ! empty( $spec['children'] ) && is_array( $spec['children'] ) ) {
			foreach ( $spec['children'] as $child_spec ) {
				if ( ! is_array( $child_spec ) ) {
					continue;
				}
				$child = self::build( $child_spec, $working, $post_id );
				if ( $child instanceof \WP_Error ) {
					return $child;
				}
				$children[] = $child;
				$working[]  = $child;
			}
		}

		list( $leaf, $lead, $trail ) = self::markup( $name, $uid, $attrs, $content, ! empty( $children ) );

		return self::node( $name, $attrs, $children, $leaf, $lead, $trail );
	}

	/**
	 * Normalizes a block name to its "kadence/…" form.
	 *
	 * @param string $block Block name with or without the prefix.
	 * @return string
	 */
	public static function normalize_name( $block ) {
		$block = trim( (string) $block );
		if ( '' === $block ) {
			return '';
		}
		return false === strpos( $block, '/' ) ? Kadence_Blocks_Helper::PREFIX . $block : $block;
	}

	/**
	 * Returns [ leaf_html, container_lead, container_trail ] for a block.
	 *
	 * @param string               $name         Block name.
	 * @param string               $uid          The block's uniqueID.
	 * @param array<string, mixed> $attrs        The block's attributes.
	 * @param string|null          $content      Optional inner text.
	 * @param bool                 $has_children Whether the block has child blocks.
	 * @return array{0: string, 1: string, 2: string}
	 */
	public static function markup( $name, $uid, array $attrs, $content, $has_children ) {
		switch ( $name ) {
			case 'kadence/rowlayout':
				$cols = isset( $attrs['columns'] ) ? max( 1, (int) $attrs['columns'] ) : 1;
				return array(
					'',
					sprintf( '<div class="kt-row-layout-wrap kt-layout-id%1$s wp-block-kadence-rowlayout"><div class="kt-row-column-wrap kt-has-%2$d-columns kt-gutter-default kt-v-gutter-default">', $uid, $cols ),
					'</div></div>',
				);
			case 'kadence/column':
				return array(
					'',
					sprintf( '<div class="wp-block-kadence-column kadence-column%s"><div class="kt-inside-inner-col">', $uid ),
					'</div></div>',
				);
			case 'kadence/advancedheading':
				$level = isset( $attrs['level'] ) ? max( 1, min( 6, (int) $attrs['level'] ) ) : 2;
				$tag   = 'h' . $level;
				$text  = null !== $content ? $content : ( isset( $attrs['content'] ) ? (string) $attrs['content'] : '' );
				return array(
					sprintf( '<%1$s class="kt-adv-heading%2$s wp-block-kadence-advancedheading" data-kb-block="kb-adv-heading%2$s">%3$s</%1$s>', $tag, $uid, $text ),
					'',
					'',
				);
			default:
				if ( in_array( $name, self::DYNAMIC, true ) ) {
					return array( '', '', '' );
				}
				if ( $has_children ) {
					return array( '', '', '' );
				}
				return array( null !== $content ? (string) $content : '', '', '' );
		}
	}

	/**
	 * Resolves where to insert, returning [ parent_path, index ] or a structured error.
	 *
	 * @param array<int, array<string, mixed>> $blocks Block tree.
	 * @param array<string, mixed>             $input  Ability input.
	 * @return array{0: int[], 1: int}|WP_Error
	 */
	private static function resolve_target( array $blocks, array $input ) {
		$parent_id = isset( $input['parent_id'] ) ? (string) $input['parent_id'] : '';
		$after_id  = isset( $input['after_id'] ) ? (string) $input['after_id'] : '';

		if ( '' !== $parent_id ) {
			$path = Kadence_Write_Engine::require_single( $blocks, $parent_id );
			if ( $path instanceof WP_Error ) {
				return $path;
			}

			$parent = Kadence_Write_Engine::node_at( $blocks, $path );
			$name   = isset( $parent['blockName'] ) ? (string) $parent['blockName'] : '';
			if ( ! in_array( $name, self::CONTAINERS, true ) ) {
				return Kadence_Write_Engine::error(
					'unsupported_container',
					__( 'The parent_id block cannot contain other blocks; insert into a Kadence Row or Column.', 'mosmcp-abilities' )
				);
			}

			$count = isset( $parent['innerBlocks'] ) && is_array( $parent['innerBlocks'] ) ? count( $parent['innerBlocks'] ) : 0;

			if ( 0 === $count && in_array( $name, self::REQUIRE_ANCHOR, true ) ) {
				return Kadence_Write_Engine::error(
					'unsupported_container',
					__( 'This container is empty, so an insert cannot be placed correctly in its saved markup. Add one block to it in the editor first, or clone a populated one, then insert.', 'mosmcp-abilities' )
				);
			}

			return array( $path, $count );
		}

		if ( '' !== $after_id ) {
			$path = Kadence_Write_Engine::require_single( $blocks, $after_id );
			if ( $path instanceof WP_Error ) {
				return $path;
			}
			$index       = (int) $path[ count( $path ) - 1 ];
			$parent_path = array_slice( $path, 0, count( $path ) - 1 );
			return array( $parent_path, $index + 1 );
		}

		// Default: append to the page root.
		return array( array(), count( $blocks ) );
	}
}
