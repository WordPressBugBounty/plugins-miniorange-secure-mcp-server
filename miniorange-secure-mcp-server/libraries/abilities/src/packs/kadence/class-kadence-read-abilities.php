<?php
/**
 * Read / discovery Kadence block abilities.
 *
 * These are the read-only half of the Kadence group: they let an agent inspect a
 * page's Kadence block structure before editing it, resolve a single block by its
 * uniqueID, search for blocks by type or text, and discover which Kadence block
 * types are registered. Every mutating ability in later slices depends on the
 * uniqueIDs these surface.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Kadence;

use MoSMCP\Abilities\Packs\Core\Support\Core_Schema as Schema;
use WP_Block_Type_Registry;
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
 * Class Kadence_Read_Abilities
 */
class Kadence_Read_Abilities {

	/**
	 * Registers every read/discovery ability. Called only when Kadence Blocks is active.
	 *
	 * @return void
	 */
	public static function register_all() {
		self::page_read();
		self::block_get();
		self::find_blocks();
		self::list_block_types();
	}

	/**
	 * mosmcp/kadence-page-read: outline a page's Kadence block structure.
	 *
	 * @return void
	 */
	private static function page_read() {
		Kadence_Helpers::register(
			'kadence-page-read',
			array(
				'label'               => __( 'Read Kadence Page Blocks', 'mosmcp-abilities' ),
				'description'         => __( 'Reads a page or post and returns a nested outline of its Kadence block layout: every block\'s type, uniqueID, a short text excerpt, and its children. Identify the page by numeric "id" OR by "search" (its title). Use this FIRST to understand a page before editing it, and again afterward to verify a change. Each block\'s uniqueID is the handle every Kadence edit ability requires; pass the returned "modified" value as expected_modified to edits for safe concurrent updates.', 'mosmcp-abilities' ),
				'input_schema'        => Schema::object(
					array(
						'id'           => Schema::int( __( 'The ID of the page or post to read. Provide this or "search".', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
						'search'       => Schema::str( __( 'The page/post title to look up when you do not have the id. Provide this or "id".', 'mosmcp-abilities' ) ),
						'kadence_only' => Schema::boolean( __( 'When true, restrict the outline to Kadence blocks (and their ancestors). Default false shows the full structure.', 'mosmcp-abilities' ), array( 'default' => false ) ),
					)
				),
				'output_schema'       => Schema::object(
					array(
						'id'          => Schema::int(),
						'title'       => Schema::str(),
						'status'      => Schema::str(),
						'modified'    => Schema::str(),
						'block_count' => Schema::int(),
						'has_kadence' => Schema::boolean(),
						'blocks'      => array( 'type' => 'array' ),
					),
					array( 'id', 'title', 'status', 'blocks' )
				),
				'execute_callback'    => array( __CLASS__, 'execute_page_read' ),
				'permission_callback' => Kadence_Helpers::can_edit_post(),
			),
			Kadence_Helpers::ann( true, false, true, false )
		);
	}

	/**
	 * mosmcp/kadence-block-get: fetch one block by uniqueID.
	 *
	 * @return void
	 */
	private static function block_get() {
		Kadence_Helpers::register(
			'kadence-block-get',
			array(
				'label'               => __( 'Get Kadence Block', 'mosmcp-abilities' ),
				'description'         => __( 'Returns full details of a single Kadence block identified by its uniqueID on a given page: its block type, attributes (all styling and responsive settings), text content, and a child outline. Look up the uniqueID first with mosmcp/kadence-page-read or mosmcp/kadence-find-blocks.', 'mosmcp-abilities' ),
				'input_schema'        => Schema::object(
					array(
						'id'        => Schema::int( __( 'The ID of the page or post containing the block.', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
						'unique_id' => Schema::str( __( 'The uniqueID of the Kadence block to retrieve.', 'mosmcp-abilities' ), array( 'minLength' => 1 ) ),
					),
					array( 'id', 'unique_id' )
				),
				'output_schema'       => Schema::object(
					array(
						'id'         => Schema::int(),
						'block'      => Schema::str(),
						'unique_id'  => Schema::str(),
						'text'       => Schema::str(),
						'attributes' => array( 'type' => 'object' ),
						'children'   => array( 'type' => 'array' ),
					),
					array( 'id', 'block', 'unique_id' )
				),
				'execute_callback'    => array( __CLASS__, 'execute_block_get' ),
				'permission_callback' => Kadence_Helpers::can_edit_post(),
			),
			Kadence_Helpers::ann( true, false, true, false )
		);
	}

	/**
	 * mosmcp/kadence-find-blocks: search a page for blocks by type and/or text.
	 *
	 * @return void
	 */
	private static function find_blocks() {
		Kadence_Helpers::register(
			'kadence-find-blocks',
			array(
				'label'               => __( 'Find Kadence Blocks', 'mosmcp-abilities' ),
				'description'         => __( 'Searches a page for Kadence blocks matching a block type (e.g. "kadence/advancedheading") and/or a text substring, and returns each match\'s uniqueID, block type, and text excerpt. Use this to locate the exact block to edit when you know its type or its text but not its uniqueID.', 'mosmcp-abilities' ),
				'input_schema'        => Schema::object(
					array(
						'id'     => Schema::int( __( 'The ID of the page or post to search. Provide this or "search".', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
						'search' => Schema::str( __( 'The page/post title to look up when you do not have the id. Provide this or "id".', 'mosmcp-abilities' ) ),
						'block'  => Schema::str( __( 'Optional block type to match, with or without the "kadence/" prefix (e.g. "advancedheading" or "kadence/advancedheading").', 'mosmcp-abilities' ) ),
						'text'   => Schema::str( __( 'Optional case-insensitive text substring the block must contain.', 'mosmcp-abilities' ) ),
					)
				),
				'output_schema'       => Schema::object(
					array(
						'id'      => Schema::int(),
						'total'   => Schema::int(),
						'matches' => Schema::arr(
							Schema::object(
								array(
									'unique_id' => Schema::str(),
									'block'     => Schema::str(),
									'text'      => Schema::str(),
								),
								array( 'block' )
							)
						),
					),
					array( 'id', 'total', 'matches' )
				),
				'execute_callback'    => array( __CLASS__, 'execute_find_blocks' ),
				'permission_callback' => Kadence_Helpers::can_edit_post(),
			),
			Kadence_Helpers::ann( true, false, true, false )
		);
	}

	/**
	 * mosmcp/kadence-list-block-types: discover Kadence block types.
	 *
	 * @return void
	 */
	private static function list_block_types() {
		Kadence_Helpers::register(
			'kadence-list-block-types',
			array(
				'label'               => __( 'List Kadence Block Types', 'mosmcp-abilities' ),
				'description'         => __( 'Lists the Kadence block types available on this site. With a page id, instead lists the Kadence block types actually used on that page and how many times each appears. Use this to learn the exact block names (e.g. "kadence/rowlayout") this Kadence version registers before inserting blocks.', 'mosmcp-abilities' ),
				'input_schema'        => Schema::object(
					array(
						'id' => Schema::int( __( 'Optional page/post ID. When given, returns the Kadence block types in use on that page with per-type counts, instead of all registered types.', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
					)
				),
				'output_schema'       => Schema::object(
					array(
						'scope'       => Schema::str( __( 'Either "registered" (all available) or "in_use" (present on the given page).', 'mosmcp-abilities' ) ),
						'total'       => Schema::int(),
						'block_types' => Schema::arr(
							Schema::object(
								array(
									'block' => Schema::str(),
									'title' => Schema::str(),
									'count' => Schema::int(),
								),
								array( 'block' )
							)
						),
					),
					array( 'scope', 'total', 'block_types' )
				),
				'execute_callback'    => array( __CLASS__, 'execute_list_block_types' ),
				'permission_callback' => Kadence_Helpers::can( 'edit_posts' ),
			),
			Kadence_Helpers::ann( true, false, true, false )
		);
	}

	/**
	 * Executes mosmcp/kadence-page-read.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function execute_page_read( $input = array() ) {
		$post = Kadence_Blocks_Helper::resolve_post( $input );
		if ( $post instanceof WP_Error ) {
			return $post;
		}

		$blocks       = Kadence_Blocks_Helper::parse_post( $post );
		$kadence_only = ! empty( $input['kadence_only'] );
		$outline      = Kadence_Blocks_Helper::outline( $blocks, $kadence_only );

		return array(
			'id'          => (int) $post->ID,
			'title'       => (string) get_the_title( $post ),
			'status'      => (string) get_post_status( $post ),
			'modified'    => (string) $post->post_modified,
			'block_count' => count( $blocks ),
			'has_kadence' => ! empty( Kadence_Blocks_Helper::count_kadence_types( $blocks ) ),
			'blocks'      => $outline,
		);
	}

	/**
	 * Executes mosmcp/kadence-block-get.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function execute_block_get( $input = array() ) {
		$post = Kadence_Blocks_Helper::require_post( $input );
		if ( $post instanceof WP_Error ) {
			return $post;
		}

		$unique_id = isset( $input['unique_id'] ) ? (string) $input['unique_id'] : '';
		$blocks    = Kadence_Blocks_Helper::parse_post( $post );
		$block     = Kadence_Blocks_Helper::find_by_unique_id( $blocks, $unique_id );

		if ( null === $block ) {
			return new WP_Error( 'block_not_found', __( 'No Kadence block with that uniqueID was found on this page.', 'mosmcp-abilities' ) );
		}

		$children = ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] )
			? Kadence_Blocks_Helper::outline( $block['innerBlocks'] )
			: array();

		return array(
			'id'         => (int) $post->ID,
			'block'      => (string) $block['blockName'],
			'unique_id'  => $unique_id,
			'text'       => Kadence_Blocks_Helper::text_excerpt( $block, 2000 ),
			'attributes' => isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array(),
			'children'   => $children,
		);
	}

	/**
	 * Executes mosmcp/kadence-find-blocks.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function execute_find_blocks( $input = array() ) {
		$post = Kadence_Blocks_Helper::resolve_post( $input );
		if ( $post instanceof WP_Error ) {
			return $post;
		}

		$block_filter = isset( $input['block'] ) ? trim( (string) $input['block'] ) : '';
		if ( '' !== $block_filter && false === strpos( $block_filter, '/' ) ) {
			$block_filter = Kadence_Blocks_Helper::PREFIX . $block_filter;
		}
		$text_filter = isset( $input['text'] ) ? trim( (string) $input['text'] ) : '';

		$matches = array();
		Kadence_Blocks_Helper::walk(
			Kadence_Blocks_Helper::parse_post( $post ),
			static function ( $block ) use ( $block_filter, $text_filter, &$matches ) {
				if ( ! Kadence_Blocks_Helper::is_kadence( $block ) ) {
					return;
				}
				if ( '' !== $block_filter && (string) $block['blockName'] !== $block_filter ) {
					return;
				}

				$excerpt = Kadence_Blocks_Helper::text_excerpt( $block );
				if ( '' !== $text_filter && false === stripos( $excerpt, $text_filter ) ) {
					return;
				}

				$matches[] = array(
					'unique_id' => Kadence_Blocks_Helper::unique_id( $block ),
					'block'     => (string) $block['blockName'],
					'text'      => $excerpt,
				);
			}
		);

		return array(
			'id'      => (int) $post->ID,
			'total'   => count( $matches ),
			'matches' => $matches,
		);
	}

	/**
	 * Executes mosmcp/kadence-list-block-types.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function execute_list_block_types( $input = array() ) {
		$id = isset( $input['id'] ) ? absint( $input['id'] ) : 0;

		if ( $id > 0 ) {
			$post = Kadence_Blocks_Helper::require_post( $input );
			if ( $post instanceof WP_Error ) {
				return $post;
			}

			$counts = Kadence_Blocks_Helper::count_kadence_types( Kadence_Blocks_Helper::parse_post( $post ) );
			$types  = array();
			foreach ( $counts as $name => $count ) {
				$types[] = array(
					'block' => (string) $name,
					'title' => self::block_title( (string) $name ),
					'count' => (int) $count,
				);
			}

			return array(
				'scope'       => 'in_use',
				'total'       => count( $types ),
				'block_types' => $types,
			);
		}

		$types = array();
		if ( class_exists( 'WP_Block_Type_Registry' ) ) {
			foreach ( WP_Block_Type_Registry::get_instance()->get_all_registered() as $name => $type ) {
				if ( 0 !== strpos( (string) $name, Kadence_Blocks_Helper::PREFIX ) ) {
					continue;
				}
				$types[] = array(
					'block' => (string) $name,
					'title' => isset( $type->title ) && '' !== (string) $type->title ? (string) $type->title : self::block_title( (string) $name ),
					'count' => 0,
				);
			}
		}

		usort(
			$types,
			static function ( $a, $b ) {
				return strcmp( $a['block'], $b['block'] );
			}
		);

		return array(
			'scope'       => 'registered',
			'total'       => count( $types ),
			'block_types' => $types,
		);
	}

	/**
	 * Derives a readable title from a block name when the registry offers none.
	 *
	 * @param string $name Block name, e.g. "kadence/advancedheading".
	 * @return string
	 */
	private static function block_title( $name ) {
		$slug = 0 === strpos( $name, Kadence_Blocks_Helper::PREFIX )
			? substr( $name, strlen( Kadence_Blocks_Helper::PREFIX ) )
			: $name;

		return ucwords( str_replace( array( '-', '_' ), ' ', $slug ) );
	}
}
