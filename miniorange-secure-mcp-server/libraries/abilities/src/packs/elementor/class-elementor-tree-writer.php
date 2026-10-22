<?php
/**
 * Replacing a post's whole Elementor tree.
 *
 * This is the escape hatch: anything the content and structural abilities cannot
 * express — building a layout from nothing, restructuring one wholesale — happens
 * here by supplying the element tree directly.
 *
 * It is also the most dangerous ability in the pack, because it replaces rather
 * than edits. The guards are therefore where the work is:
 *
 *   - the tree is validated in full before anything is written, so a malformed
 *     node is a refusal rather than a broken page plus a rollback
 *   - an unregistered widget type is refused, because Elementor renders nothing
 *     for a widget it does not know and the page would silently lose that block
 *   - missing element IDs are minted and colliding ones reassigned, so a caller
 *     never has to invent Elementor's ID format and cannot create the duplicate-ID
 *     state that makes a page uneditable
 *   - clearing a layout is possible but must be asked for explicitly, so passing
 *     an empty array by accident cannot blank a page
 *   - the builder edit mode is set by default, because layout data on a post with
 *     no edit mode is stored but never rendered
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
 * Class Elementor_Tree_Writer
 */
class Elementor_Tree_Writer {

	/**
	 * Most elements a single write may contain.
	 *
	 * Well above any hand-authored layout, and low enough that a runaway generated
	 * tree is refused before it is encoded and stored.
	 *
	 * @var int
	 */
	const MAX_ELEMENTS = 2000;

	/**
	 * Deepest nesting a supplied tree may use.
	 *
	 * @var int
	 */
	const MAX_DEPTH = 12;

	/**
	 * Number of element IDs listed for post-save verification.
	 *
	 * @var int
	 */
	const MAX_VERIFY_IDS = 25;

	/**
	 * Builds the mutator for a whole-tree write.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return callable
	 */
	public static function mutator( array $input ) {
		return static function ( array &$tree, array &$result ) use ( $input ) {
			$supplied = isset( $input['elements'] ) ? $input['elements'] : null;

			if ( ! is_array( $supplied ) ) {
				return new WP_Error(
					'invalid_elements',
					__( 'elements must be an array of top-level Elementor elements. Read an existing layout to see the shape.', 'mosmcp-abilities' )
				);
			}

			if ( ! $supplied && empty( $input['allow_empty'] ) ) {
				return new WP_Error(
					'refusing_to_empty_layout',
					__( 'An empty elements array would remove the entire layout. If that is intended, pass allow_empty as true.', 'mosmcp-abilities' )
				);
			}

			$before = self::count_nodes( $tree );

			$report     = array(
				'minted'        => array(),
				'reassigned'    => array(),
				'unknown_types' => array(),
			);
			$normalized = self::normalize( $supplied, $report );
			if ( is_wp_error( $normalized ) ) {
				return $normalized;
			}

			$after = self::count_nodes( $normalized );

			$tree = $normalized;

			if ( $report['minted'] ) {
				$result['warnings'][] = array(
					'code'    => 'element_ids_minted',
					'message' => sprintf(
						/* translators: %d: number of elements */
						_n( '%d element had no ID, so one was generated for it.', '%d elements had no ID, so IDs were generated for them.', count( $report['minted'] ), 'mosmcp-abilities' ),
						count( $report['minted'] )
					),
					'context' => implode( ', ', array_slice( $report['minted'], 0, 20 ) ),
				);
			}

			if ( $report['reassigned'] ) {
				$result['warnings'][] = array(
					'code'    => 'element_ids_reassigned',
					'message' => sprintf(
						/* translators: %d: number of elements */
						_n( '%d element reused an ID already present in the layout and was given a new one, so every element stays individually addressable.', '%d elements reused IDs already present in the layout and were given new ones, so every element stays individually addressable.', count( $report['reassigned'] ), 'mosmcp-abilities' ),
						count( $report['reassigned'] )
					),
					'context' => implode( ', ', array_slice( $report['reassigned'], 0, 20 ) ),
				);
			}

			if ( $after < $before ) {
				$result['warnings'][] = array(
					'code'    => 'layout_shrank',
					'message' => sprintf(
						/* translators: 1: previous element count, 2: new element count */
						__( 'The layout went from %1$d elements to %2$d. This ability replaces the layout rather than adding to it, so anything not included has been removed.', 'mosmcp-abilities' ),
						$before,
						$after
					),
					'context' => 'before=' . $before . ' after=' . $after,
				);
			}

			$result['changes'][] = array(
				'action'      => 'write',
				'element_id'  => '',
				'widget_type' => '',
				'detail'      => sprintf(
					/* translators: 1: previous element count, 2: new element count */
					__( 'Replaced the layout: %1$d elements before, %2$d after.', 'mosmcp-abilities' ),
					$before,
					$after
				),
			);

			foreach ( array_slice( self::root_ids( $normalized ), 0, self::MAX_VERIFY_IDS ) as $root_id ) {
				$result['verify_ids'][] = $root_id;
			}

			return true;
		};
	}

	/**
	 * Validates and normalizes a supplied tree.
	 *
	 * @param array<int, mixed>    $nodes  Supplied nodes.
	 * @param array<string, mixed> $report Accumulator for minted/reassigned/unknown, modified in place.
	 * @return array<int, mixed>|WP_Error
	 */
	private static function normalize( array $nodes, array &$report ) {
		$count = self::count_nodes( $nodes );

		if ( $count > self::MAX_ELEMENTS ) {
			return new WP_Error(
				'too_many_elements',
				sprintf(
					/* translators: 1: supplied count, 2: maximum */
					__( 'The supplied layout has %1$s elements, above the %2$s this tool will write in one call.', 'mosmcp-abilities' ),
					number_format_i18n( $count ),
					number_format_i18n( self::MAX_ELEMENTS )
				)
			);
		}

		$used = array();

		$normalized = self::normalize_level( $nodes, $used, $report, 0, '' );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}

		if ( $report['unknown_types'] ) {
			return new WP_Error(
				'unknown_widget_types',
				sprintf(
					/* translators: %s: comma-separated list of widget slugs */
					__( 'The layout uses widget types this site does not have: %s. Elementor renders nothing for an unknown widget, so the write was refused rather than silently dropping those blocks. List the available widget types to see what can be used.', 'mosmcp-abilities' ),
					implode( ', ', array_slice( array_unique( $report['unknown_types'] ), 0, 10 ) )
				)
			);
		}

		return $normalized;
	}

	/**
	 * Normalizes one level of the tree, recursing into children.
	 *
	 * @param array<int, mixed>    $nodes  Nodes at this level.
	 * @param array<string, bool>  $used   Element IDs already taken, added to as they are seen.
	 * @param array<string, mixed> $report Accumulator, modified in place.
	 * @param int                  $depth  Current depth.
	 * @param string               $path   Human-readable path, for error messages.
	 * @return array<int, mixed>|WP_Error
	 */
	private static function normalize_level( array $nodes, array &$used, array &$report, $depth, $path ) {
		if ( $depth > self::MAX_DEPTH ) {
			return new WP_Error(
				'layout_too_deep',
				sprintf(
					/* translators: %d: maximum depth */
					__( 'The supplied layout nests more than %d levels deep, which is beyond anything Elementor produces and suggests the structure is malformed.', 'mosmcp-abilities' ),
					self::MAX_DEPTH
				)
			);
		}

		$out = array();

		foreach ( $nodes as $index => $node ) {
			$here = '' === $path ? (string) $index : $path . '.' . $index;

			if ( ! is_array( $node ) ) {
				return new WP_Error(
					'invalid_element',
					sprintf(
						/* translators: %s: position in the supplied tree */
						__( 'The element at position %s is not an object.', 'mosmcp-abilities' ),
						$here
					)
				);
			}

			$el_type = isset( $node['elType'] ) ? (string) $node['elType'] : '';
			if ( '' === $el_type ) {
				return new WP_Error(
					'missing_el_type',
					sprintf(
						/* translators: %s: position in the supplied tree */
						__( 'The element at position %s has no elType. Use "container" for a layout box, or "widget" together with a widgetType.', 'mosmcp-abilities' ),
						$here
					)
				);
			}

			$widget_type = isset( $node['widgetType'] ) ? (string) $node['widgetType'] : '';

			if ( 'widget' === $el_type ) {
				if ( '' === $widget_type ) {
					return new WP_Error(
						'missing_widget_type',
						sprintf(
							/* translators: %s: position in the supplied tree */
							__( 'The widget at position %s has no widgetType, so Elementor would not know what to render.', 'mosmcp-abilities' ),
							$here
						)
					);
				}
				if ( ! Elementor_Schema::element( $widget_type ) ) {
					$report['unknown_types'][] = $widget_type;
				}
			} elseif ( ! Elementor_Schema::element( $el_type ) ) {
				$report['unknown_types'][] = $el_type;
			}

			$clean = array(
				'id'       => '',
				'elType'   => $el_type,
				'settings' => ( isset( $node['settings'] ) && is_array( $node['settings'] ) ) ? $node['settings'] : array(),
				'elements' => array(),
			);

			if ( '' !== $widget_type ) {
				$clean['widgetType'] = $widget_type;
			}

			// isInner is meaningful to Elementor's legacy section/column layout.
			if ( isset( $node['isInner'] ) ) {
				$clean['isInner'] = (bool) $node['isInner'];
			}

			$supplied_id = isset( $node['id'] ) ? trim( (string) $node['id'] ) : '';

			if ( '' === $supplied_id ) {
				$clean['id']        = self::mint( $used );
				$report['minted'][] = $clean['id'];
			} elseif ( isset( $used[ $supplied_id ] ) ) {
				$clean['id']            = self::mint( $used );
				$report['reassigned'][] = $supplied_id . ' -> ' . $clean['id'];
			} else {
				$clean['id'] = $supplied_id;
			}

			$used[ $clean['id'] ] = true;

			if ( isset( $node['elements'] ) && is_array( $node['elements'] ) && $node['elements'] ) {
				$children = self::normalize_level( $node['elements'], $used, $report, $depth + 1, $here );
				if ( is_wp_error( $children ) ) {
					return $children;
				}
				$clean['elements'] = $children;
			}

			$out[] = $clean;
		}

		return $out;
	}

	/**
	 * Mints an unused element ID in Elementor's format.
	 *
	 * @param array<string, bool> $used IDs already taken.
	 * @return string
	 */
	private static function mint( array &$used ) {
		$node = array(
			'id'       => '',
			'elType'   => 'widget',
			'elements' => array(),
		);
		Elementor_Structure_Writer::regenerate_ids( $node, $used );
		return (string) $node['id'];
	}

	/**
	 * Counts every node in a tree.
	 *
	 * @param array<int, mixed> $nodes Tree.
	 * @return int
	 */
	private static function count_nodes( array $nodes ) {
		$count = 0;

		Elementor_Document::walk(
			$nodes,
			static function () use ( &$count ) {
				++$count;
				return true;
			}
		);

		return $count;
	}

	/**
	 * The IDs of the top-level elements.
	 *
	 * @param array<int, mixed> $nodes Tree.
	 * @return string[]
	 */
	private static function root_ids( array $nodes ) {
		$ids = array();

		foreach ( $nodes as $node ) {
			if ( is_array( $node ) && ! empty( $node['id'] ) ) {
				$ids[] = (string) $node['id'];
			}
		}

		return $ids;
	}

	/**
	 * Ensures the post is set up so Elementor actually renders the layout.
	 *
	 * Layout data alone is not enough: without the builder edit mode WordPress
	 * renders the post content and the layout is stored but invisible, which is a
	 * confusing outcome for a caller that has just written one successfully.
	 *
	 * @param int  $post_id   Post ID.
	 * @param bool $set_mode  Whether to set the edit mode.
	 * @return array<int, array<string, mixed>> Warnings.
	 */
	public static function ensure_renderable( $post_id, $set_mode ) {
		$post_id  = (int) $post_id;
		$warnings = array();

		$edit_mode = (string) get_post_meta( $post_id, Elementor_Document::EDIT_MODE_KEY, true );

		if ( ! $set_mode ) {
			if ( 'builder' !== $edit_mode ) {
				$warnings[] = array(
					'code'    => 'edit_mode_not_set',
					'message' => __( 'The layout was written but this post is not in Elementor builder mode, so WordPress will render its post content instead. Set the builder edit mode to make the layout visible.', 'mosmcp-abilities' ),
					'context' => Elementor_Document::EDIT_MODE_KEY . '=' . $edit_mode,
				);
			}
			return $warnings;
		}

		if ( 'builder' !== $edit_mode ) {
			update_post_meta( $post_id, Elementor_Document::EDIT_MODE_KEY, 'builder' );
		}

		if ( '' === (string) get_post_meta( $post_id, Elementor_Document::TEMPLATE_TYPE_KEY, true ) ) {
			$post_type = (string) get_post_type( $post_id );
			update_post_meta( $post_id, Elementor_Document::TEMPLATE_TYPE_KEY, wp_slash( 'page' === $post_type ? 'wp-page' : 'wp-post' ) );
		}

		return $warnings;
	}
}
