<?php
/**
 * Block-editing Kadence abilities (Slice ③): update text, update styling
 * attributes, delete, and duplicate a block.
 *
 * Every ability here is a thin wrapper that builds a mutator closure and hands it
 * to Kadence_Write_Engine::run(), which owns the duplicate-uniqueID guard,
 * optional optimistic locking, path-based in-place mutation, serialize round-trip
 * validation, save, and read-after-write verification. The abilities themselves
 * only decide *what* to change.
 *
 * Edit-safety is block-mode aware (plan §17-B):
 *  - Text on dynamic-render blocks (singlebtn) is a plain attribute write — safe.
 *  - Text on static-save blocks (advancedheading) writes the attribute AND syncs
 *    the saved innerHTML text node.
 *  - update-attributes is deliberately limited to styling attributes that Kadence
 *    renders through its uniqueID-keyed CSS (regenerated on render), so they never
 *    change the saved markup and cannot trip the editor's "invalid content" state.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Kadence;

use MoSMCP\Abilities\Packs\Core\Support\Core_Schema as Schema;
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
 * Class Kadence_Block_Write_Abilities
 */
class Kadence_Block_Write_Abilities {

	/**
	 * Blocks whose editable text lives in a single attribute, and which attribute.
	 *
	 * @var array<string, string>
	 */
	const TEXT_ATTR = array(
		'kadence/advancedheading' => 'content',
		'kadence/singlebtn'       => 'text',
	);

	/**
	 * Blocks that render their content dynamically in PHP (empty saved innerHTML),
	 * so a text/content attribute write needs no markup sync.
	 *
	 * @var string[]
	 */
	const DYNAMIC_BLOCKS = array( 'kadence/singlebtn' );

	/**
	 * Blocks whose editable text is an attribute AND lives inside one specific
	 * nested tag within a larger wrapper, so it needs a surgical span replace
	 * rather than the whole-wrapper swap TEXT_ATTR blocks use.
	 *
	 * Verified against real Kadence 3.7.8.2 output: an accordion pane's title
	 * renders as `<span class="kt-blocks-accordion-title">…</span>` nested inside
	 * the pane's header/button/panel wrapper divs.
	 *
	 * @var array<string, array{attr: string, anchor_class: string}>
	 */
	const ANCHORED_TEXT = array(
		'kadence/pane' => array(
			'attr'         => 'title',
			'anchor_class' => 'kt-blocks-accordion-title',
		),
	);

	/**
	 * Per-block whitelist of styling attributes safe to set via update-attributes.
	 *
	 * These are rendered through Kadence's per-block CSS (keyed to uniqueID and
	 * regenerated on render), not baked into saved markup, so writing them cannot
	 * cause stale front-end HTML or an "invalid content" mismatch. Scalars only in
	 * V1 — responsive array attributes (padding/size/…) are intentionally excluded
	 * until a responsive-aware slice handles their [desktop, tablet, mobile] shape.
	 *
	 * @var array<string, string[]>
	 */
	const STYLE_ATTRS = array(
		'kadence/advancedheading' => array( 'color', 'background' ),
		'kadence/singlebtn'       => array( 'color', 'background', 'colorHover', 'backgroundHover', 'link', 'target' ),
		'kadence/infobox'         => array( 'containerBackground', 'containerBorderColor' ),
	);

	/**
	 * Registers every block-edit ability. Called only when Kadence Blocks is active.
	 *
	 * @return void
	 */
	public static function register_all() {
		self::update_text();
		self::update_attributes();
		self::delete_block();
		self::duplicate_block();
	}

	/**
	 * Shared input properties: the target page, block, and optional optimistic lock.
	 *
	 * @return array<string, mixed>
	 */
	private static function target_props() {
		return array(
			'id'                => Schema::int( __( 'The ID of the page or post containing the block.', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
			'unique_id'         => Schema::str( __( 'The uniqueID of the Kadence block to change (from kadence-page-read / kadence-find-blocks).', 'mosmcp-abilities' ), array( 'minLength' => 1 ) ),
			'expected_modified' => Schema::str( __( 'Optional optimistic lock: the page\'s "modified" value as read. If the page changed since, the edit is refused with stale_revision.', 'mosmcp-abilities' ) ),
		);
	}

	/**
	 * mosmcp/kadence-block-update-text.
	 *
	 * @return void
	 */
	private static function update_text() {
		$supported = implode( ', ', array_merge( array_keys( self::TEXT_ATTR ), array_keys( self::ANCHORED_TEXT ) ) );

		Kadence_Helpers::register(
			'kadence-block-update-text',
			array(
				'label'               => __( 'Update Kadence Block Text', 'mosmcp-abilities' ),
				'description'         => sprintf(
					/* translators: %s: comma-separated list of supported block names. */
					__( 'Replaces the text of a single Kadence block without disturbing its layout, styling, or responsive settings. Currently supports: %s (for kadence/pane, this sets the accordion/toggle tab title). Look up the block\'s uniqueID first. For static-save blocks the saved markup is kept in sync automatically.', 'mosmcp-abilities' ),
					$supported
				),
				'input_schema'        => Schema::object(
					array_merge(
						self::target_props(),
						array(
							'text' => Schema::str( __( 'The new text for the block.', 'mosmcp-abilities' ) ),
						)
					),
					array( 'id', 'unique_id', 'text' )
				),
				'output_schema'       => Schema::object(
					array(
						'id'        => Schema::int(),
						'unique_id' => Schema::str(),
						'block'     => Schema::str(),
						'old_text'  => Schema::str(),
						'new_text'  => Schema::str(),
						'modified'  => Schema::str(),
						'verified'  => Schema::boolean(),
					),
					array( 'id', 'unique_id', 'new_text' )
				),
				'execute_callback'    => array( __CLASS__, 'execute_update_text' ),
				'permission_callback' => Kadence_Helpers::can_edit_post(),
			),
			Kadence_Helpers::ann( false, false, true, true )
		);
	}

	/**
	 * mosmcp/kadence-block-update-attributes.
	 *
	 * @return void
	 */
	private static function update_attributes() {
		Kadence_Helpers::register(
			'kadence-block-update-attributes',
			array(
				'label'               => __( 'Update Kadence Block Styling', 'mosmcp-abilities' ),
				'description'         => __( 'Sets one or more whitelisted styling attributes (e.g. color, background, link) on a single Kadence block. Only styling attributes rendered through Kadence\'s CSS are allowed, so the change never breaks the block\'s markup or its responsive settings; every other attribute is preserved. Unknown or non-whitelisted attributes are rejected.', 'mosmcp-abilities' ),
				'input_schema'        => Schema::object(
					array_merge(
						self::target_props(),
						array(
							'attributes' => array(
								'type'                 => 'object',
								'description'          => __( 'Map of attribute name to new value. Allowed names depend on the block type; use kadence-block-get to inspect current values.', 'mosmcp-abilities' ),
								'additionalProperties' => true,
							),
						)
					),
					array( 'id', 'unique_id', 'attributes' )
				),
				'output_schema'       => Schema::object(
					array(
						'id'        => Schema::int(),
						'unique_id' => Schema::str(),
						'block'     => Schema::str(),
						'updated'   => Schema::arr( Schema::str() ),
						'modified'  => Schema::str(),
						'verified'  => Schema::boolean(),
					),
					array( 'id', 'unique_id', 'updated' )
				),
				'execute_callback'    => array( __CLASS__, 'execute_update_attributes' ),
				'permission_callback' => Kadence_Helpers::can_edit_post(),
			),
			Kadence_Helpers::ann( false, false, true, true )
		);
	}

	/**
	 * mosmcp/kadence-block-delete.
	 *
	 * @return void
	 */
	private static function delete_block() {
		Kadence_Helpers::register(
			'kadence-block-delete',
			array(
				'label'               => __( 'Delete Kadence Block', 'mosmcp-abilities' ),
				'description'         => __( 'Removes a single Kadence block (and its children) from a page by uniqueID. The rest of the layout is preserved. This is reversible only via the page\'s revision history.', 'mosmcp-abilities' ),
				'input_schema'        => Schema::object( self::target_props(), array( 'id', 'unique_id' ) ),
				'output_schema'       => Schema::object(
					array(
						'id'        => Schema::int(),
						'unique_id' => Schema::str(),
						'block'     => Schema::str(),
						'deleted'   => Schema::boolean(),
						'modified'  => Schema::str(),
					),
					array( 'id', 'unique_id', 'deleted' )
				),
				'execute_callback'    => array( __CLASS__, 'execute_delete' ),
				'permission_callback' => Kadence_Helpers::can_edit_post(),
			),
			Kadence_Helpers::ann( false, true, true, true )
		);
	}

	/**
	 * mosmcp/kadence-block-duplicate.
	 *
	 * @return void
	 */
	private static function duplicate_block() {
		Kadence_Helpers::register(
			'kadence-block-duplicate',
			array(
				'label'               => __( 'Duplicate Kadence Block', 'mosmcp-abilities' ),
				'description'         => __( 'Clones a Kadence block (and all its children) directly after the original, assigning fresh uniqueIDs to the copy and every descendant so styling and anchors do not collide. Returns the new block\'s uniqueID.', 'mosmcp-abilities' ),
				'input_schema'        => Schema::object( self::target_props(), array( 'id', 'unique_id' ) ),
				'output_schema'       => Schema::object(
					array(
						'id'            => Schema::int(),
						'source_id'     => Schema::str(),
						'new_unique_id' => Schema::str(),
						'block'         => Schema::str(),
						'modified'      => Schema::str(),
						'verified'      => Schema::boolean(),
					),
					array( 'id', 'new_unique_id' )
				),
				'execute_callback'    => array( __CLASS__, 'execute_duplicate' ),
				'permission_callback' => Kadence_Helpers::can_edit_post(),
			),
			Kadence_Helpers::ann( false, false, false, true )
		);
	}

	/**
	 * Executes mosmcp/kadence-block-update-text.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function execute_update_text( $input = array() ) {
		$raw_text   = isset( $input['text'] ) ? (string) $input['text'] : '';
		$rich_text  = wp_kses_post( $raw_text );
		$plain_text = sanitize_text_field( $raw_text );

		return Kadence_Write_Engine::run(
			$input,
			static function ( array &$blocks, array &$result ) use ( $input, $rich_text, $plain_text ) {
				$path = Kadence_Write_Engine::require_single( $blocks, isset( $input['unique_id'] ) ? $input['unique_id'] : '' );
				if ( $path instanceof WP_Error ) {
					return $path;
				}

				$node  = Kadence_Write_Engine::node_at( $blocks, $path );
				$block = isset( $node['blockName'] ) ? (string) $node['blockName'] : '';

				if ( isset( self::TEXT_ATTR[ $block ] ) ) {
					return self::apply_simple_text( $blocks, $path, $node, $block, $rich_text, $input, $result );
				}

				if ( isset( self::ANCHORED_TEXT[ $block ] ) ) {
					return self::apply_anchored_text( $blocks, $path, $node, $block, $plain_text, $input, $result );
				}

				return Kadence_Write_Engine::error(
					'unsupported_block',
					__( 'This block type does not support text editing yet.', 'mosmcp-abilities' )
				);
			}
		);
	}

	/**
	 * Applies a whole-wrapper text swap for TEXT_ATTR blocks (advancedheading, singlebtn).
	 *
	 * @param array<int, array<string, mixed>> $blocks   Block tree (by reference).
	 * @param int[]                            $path     Path to the target node.
	 * @param array<string, mixed>             $node     A copy of the target node (for reading old text).
	 * @param string                           $block    Block name.
	 * @param string                           $new_text New (rich-sanitized) text.
	 * @param array<string, mixed>             $input    Ability input.
	 * @param array<string, mixed>             $result   Result array (by reference).
	 * @return true
	 */
	private static function apply_simple_text( array &$blocks, array $path, $node, $block, $new_text, array $input, array &$result ) {
		$attr    = self::TEXT_ATTR[ $block ];
		$old     = isset( $node['attrs'][ $attr ] ) ? (string) $node['attrs'][ $attr ] : '';
		$dynamic = in_array( $block, self::DYNAMIC_BLOCKS, true );

		Kadence_Write_Engine::mutate_at(
			$blocks,
			$path,
			static function ( array &$n ) use ( $attr, $new_text, $dynamic ) {
				if ( ! isset( $n['attrs'] ) || ! is_array( $n['attrs'] ) ) {
					$n['attrs'] = array();
				}
				$n['attrs'][ $attr ] = $new_text;

				if ( ! $dynamic ) {
					$synced            = Kadence_Blocks_Helper::replace_text_node( isset( $n['innerHTML'] ) ? $n['innerHTML'] : '', $new_text );
					$n['innerHTML']    = $synced;
					$n['innerContent'] = array( $synced );
				}
			}
		);

		$result['unique_id'] = (string) $input['unique_id'];
		$result['block']     = $block;
		$result['old_text']  = $old;
		$result['new_text']  = $new_text;

		return true;
	}

	/**
	 * Applies a surgical span-text swap for ANCHORED_TEXT blocks (accordion pane title).
	 *
	 * Fails closed with 'validation_failed' if the expected anchor span is not
	 * found in the block's markup, rather than silently updating the attribute and
	 * leaving the front-end title stale.
	 *
	 * @param array<int, array<string, mixed>> $blocks   Block tree (by reference).
	 * @param int[]                            $path     Path to the target node.
	 * @param array<string, mixed>             $node     A copy of the target node (for reading old text).
	 * @param string                           $block    Block name.
	 * @param string                           $new_text New (plain-sanitized) text.
	 * @param array<string, mixed>             $input    Ability input.
	 * @param array<string, mixed>             $result   Result array (by reference).
	 * @return true|WP_Error
	 */
	private static function apply_anchored_text( array &$blocks, array $path, $node, $block, $new_text, array $input, array &$result ) {
		$config = self::ANCHORED_TEXT[ $block ];
		$attr   = $config['attr'];
		$old    = isset( $node['attrs'][ $attr ] ) ? (string) $node['attrs'][ $attr ] : '';
		$html   = isset( $node['innerHTML'] ) ? (string) $node['innerHTML'] : '';

		$synced = Kadence_Blocks_Helper::replace_span_text( $html, $config['anchor_class'], esc_html( $new_text ) );
		if ( null === $synced ) {
			return Kadence_Write_Engine::error(
				'validation_failed',
				sprintf(
					/* translators: %s: CSS class name the engine expected to find. */
					__( 'Could not find the expected "%s" markup on this block, so its saved HTML was left untouched to avoid producing invalid content. The block may use an unsupported Kadence version or configuration.', 'mosmcp-abilities' ),
					$config['anchor_class']
				)
			);
		}

		Kadence_Write_Engine::mutate_at(
			$blocks,
			$path,
			static function ( array &$n ) use ( $attr, $new_text, $synced ) {
				if ( ! isset( $n['attrs'] ) || ! is_array( $n['attrs'] ) ) {
					$n['attrs'] = array();
				}
				$n['attrs'][ $attr ] = $new_text;
				$n['innerHTML']      = $synced;
				$n['innerContent']   = array( $synced );
			}
		);

		$result['unique_id'] = (string) $input['unique_id'];
		$result['block']     = $block;
		$result['old_text']  = $old;
		$result['new_text']  = $new_text;

		return true;
	}

	/**
	 * Executes mosmcp/kadence-block-update-attributes.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function execute_update_attributes( $input = array() ) {
		$attributes = isset( $input['attributes'] ) && is_array( $input['attributes'] ) ? $input['attributes'] : array();

		return Kadence_Write_Engine::run(
			$input,
			static function ( array &$blocks, array &$result ) use ( $input, $attributes ) {
				$path = Kadence_Write_Engine::require_single( $blocks, isset( $input['unique_id'] ) ? $input['unique_id'] : '' );
				if ( $path instanceof WP_Error ) {
					return $path;
				}

				$node  = Kadence_Write_Engine::node_at( $blocks, $path );
				$block = isset( $node['blockName'] ) ? (string) $node['blockName'] : '';

				$allowed = isset( self::STYLE_ATTRS[ $block ] ) ? self::STYLE_ATTRS[ $block ] : array();
				if ( empty( $allowed ) ) {
					return Kadence_Write_Engine::error(
						'unsupported_block',
						__( 'This block type has no editable styling attributes exposed yet.', 'mosmcp-abilities' )
					);
				}

				$rejected = array_diff( array_keys( $attributes ), $allowed );
				if ( ! empty( $rejected ) ) {
					return Kadence_Write_Engine::error(
						'validation_failed',
						sprintf(
							/* translators: 1: rejected attribute names, 2: allowed attribute names. */
							__( 'Attribute(s) not editable for this block: %1$s. Allowed: %2$s.', 'mosmcp-abilities' ),
							implode( ', ', $rejected ),
							implode( ', ', $allowed )
						)
					);
				}

				$updated = array_values( array_intersect( array_keys( $attributes ), $allowed ) );
				if ( empty( $updated ) ) {
					return Kadence_Write_Engine::error(
						'validation_failed',
						__( 'No editable attributes were supplied.', 'mosmcp-abilities' )
					);
				}

				foreach ( $updated as $key ) {
					if ( ! self::is_valid_attribute_value( $key, $attributes[ $key ] ) ) {
						return Kadence_Write_Engine::error(
							'validation_failed',
							sprintf(
								/* translators: %s: attribute name. */
								__( 'Invalid value for attribute "%s".', 'mosmcp-abilities' ),
								$key
							)
						);
					}
				}

				Kadence_Write_Engine::mutate_at(
					$blocks,
					$path,
					static function ( array &$n ) use ( $attributes, $updated ) {
						if ( ! isset( $n['attrs'] ) || ! is_array( $n['attrs'] ) ) {
							$n['attrs'] = array();
						}
						foreach ( $updated as $key ) {
							$n['attrs'][ $key ] = $attributes[ $key ];
						}
					}
				);

				$result['unique_id'] = (string) $input['unique_id'];
				$result['block']     = $block;
				$result['updated']   = $updated;

				return true;
			}
		);
	}

	/**
	 * Style attribute names whose values are a link target and must be validated
	 * as a safe URL rather than accepted verbatim.
	 *
	 * @var string[]
	 */
	const LINK_ATTRS = array( 'link' );

	/**
	 * Style attribute names whose values must be a CSS color.
	 *
	 * @var string[]
	 */
	const COLOR_ATTRS = array( 'color', 'background', 'colorHover', 'backgroundHover', 'containerBackground', 'containerBorderColor' );

	/**
	 * Validates a single style-attribute value by its expected shape, so
	 * update-attributes cannot be used to smuggle an unsafe URL scheme (e.g.
	 * `javascript:`) into a link attribute or an arbitrary string into a color.
	 *
	 * @param string $key   Attribute name.
	 * @param mixed  $value Caller-supplied value.
	 * @return bool
	 */
	private static function is_valid_attribute_value( $key, $value ) {
		if ( 'target' === $key ) {
			return in_array( $value, array( '', '_self', '_blank' ), true );
		}

		if ( in_array( $key, self::LINK_ATTRS, true ) ) {
			if ( ! is_string( $value ) ) {
				return false;
			}
			if ( '' === $value ) {
				return true;
			}
			$sanitized = esc_url_raw( $value, array( 'http', 'https', 'mailto', 'tel' ) );
			return '' !== $sanitized;
		}

		if ( in_array( $key, self::COLOR_ATTRS, true ) ) {
			if ( ! is_string( $value ) ) {
				return false;
			}
			if ( '' === $value || 'transparent' === $value || 'inherit' === $value ) {
				return true;
			}
			return 1 === preg_match( '/^#[0-9a-fA-F]{3,8}$/', $value )
				|| 1 === preg_match( '/^rgba?\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*(,\s*(0|1|0?\.\d+)\s*)?\)$/', $value );
		}

		return is_scalar( $value );
	}

	/**
	 * Executes mosmcp/kadence-block-delete.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function execute_delete( $input = array() ) {
		return Kadence_Write_Engine::run(
			$input,
			static function ( array &$blocks, array &$result ) use ( $input ) {
				$path = Kadence_Write_Engine::require_single( $blocks, isset( $input['unique_id'] ) ? $input['unique_id'] : '' );
				if ( $path instanceof WP_Error ) {
					return $path;
				}

				$node  = Kadence_Write_Engine::node_at( $blocks, $path );
				$block = isset( $node['blockName'] ) ? (string) $node['blockName'] : '';

				$index       = (int) $path[ count( $path ) - 1 ];
				$parent_path = array_slice( $path, 0, count( $path ) - 1 );

				Kadence_Write_Engine::splice_children( $blocks, $parent_path, $index, 1 );

				$result['unique_id'] = (string) $input['unique_id'];
				$result['block']     = $block;
				$result['deleted']   = true;

				return true;
			}
		);
	}

	/**
	 * Executes mosmcp/kadence-block-duplicate.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function execute_duplicate( $input = array() ) {
		$post_id = isset( $input['id'] ) ? absint( $input['id'] ) : 0;

		return Kadence_Write_Engine::run(
			$input,
			static function ( array &$blocks, array &$result ) use ( $input, $post_id ) {
				$path = Kadence_Write_Engine::require_single( $blocks, isset( $input['unique_id'] ) ? $input['unique_id'] : '' );
				if ( $path instanceof WP_Error ) {
					return $path;
				}

				$node  = Kadence_Write_Engine::node_at( $blocks, $path );
				$block = isset( $node['blockName'] ) ? (string) $node['blockName'] : '';
				$copy  = Kadence_Write_Engine::regenerate_ids( $node, $blocks, $post_id );

				$index       = (int) $path[ count( $path ) - 1 ];
				$parent_path = array_slice( $path, 0, count( $path ) - 1 );

				Kadence_Write_Engine::splice_children( $blocks, $parent_path, $index + 1, 0, array( $copy ) );

				$result['source_id']     = (string) $input['unique_id'];
				$result['new_unique_id'] = Kadence_Blocks_Helper::unique_id( $copy );
				$result['unique_id']     = $result['new_unique_id'];
				$result['block']         = $block;

				return true;
			}
		);
	}
}
