<?php
/**
 * Block-inserting Kadence abilities (Slice ④): create a layout and insert rows,
 * columns, headings, images, buttons, and icons.
 *
 * Each ability builds a Kadence block via Kadence_Insert_Engine and places it in
 * the page through the shared Write Engine (except layout-create, which spins up a
 * new draft page). See the insert engine's markup-fidelity caveat.
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
 * Class Kadence_Insert_Abilities
 */
class Kadence_Insert_Abilities {

	/**
	 * Registers every insert ability. Called only when Kadence Blocks is active.
	 *
	 * @return void
	 */
	public static function register_all() {
		self::layout_create();
		self::block_create();
		self::insert_row();
		self::insert_column();
		self::insert_heading();
		self::insert_image();
		self::insert_button();
		self::insert_icon();
	}

	/**
	 * Shared placement + optimistic-lock input properties for insert-into-page abilities.
	 *
	 * @return array<string, mixed>
	 */
	private static function placement_props() {
		return array(
			'id'                => Schema::int( __( 'The ID of the page or post to insert into.', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
			'parent_id'         => Schema::str( __( 'Optional uniqueID of a Kadence Row or Column to insert inside. Omit to append at the page root.', 'mosmcp-abilities' ) ),
			'after_id'          => Schema::str( __( 'Optional uniqueID of a sibling block to insert immediately after (ignored if parent_id is given).', 'mosmcp-abilities' ) ),
			'expected_modified' => Schema::str( __( 'Optional optimistic lock: the page\'s "modified" value as read. If the page changed since, the insert is refused with stale_revision.', 'mosmcp-abilities' ) ),
		);
	}

	/**
	 * Standard output for an insert-into-page ability.
	 *
	 * @return array<string, mixed>
	 */
	private static function insert_output() {
		return Schema::object(
			array(
				'id'        => Schema::int(),
				'unique_id' => Schema::str(),
				'block'     => Schema::str(),
				'modified'  => Schema::str(),
				'verified'  => Schema::boolean(),
			),
			array( 'id', 'unique_id', 'block' )
		);
	}

	// ---------------------------------------------------------------------
	// Ability definitions.
	// ---------------------------------------------------------------------

	/**
	 * mosmcp/kadence-layout-create: new draft page seeded with a Row → Columns scaffold.
	 *
	 * @return void
	 */
	private static function layout_create() {
		Kadence_Helpers::register(
			'kadence-layout-create',
			array(
				'label'               => __( 'Create Kadence Layout Page', 'mosmcp-abilities' ),
				'description'         => __( 'Creates a new DRAFT page seeded with a Kadence Row Layout containing the requested number of equal columns, ready to fill with headings, images, and buttons. Returns the new page ID and the Row/Column uniqueIDs to insert into.', 'mosmcp-abilities' ),
				'input_schema'        => Schema::object(
					array(
						'title'   => Schema::str( __( 'Title of the new page.', 'mosmcp-abilities' ), array( 'minLength' => 1 ) ),
						'columns' => Schema::int(
							__( 'Number of equal columns in the seed row (1-6).', 'mosmcp-abilities' ),
							array(
								'minimum' => 1,
								'maximum' => 6,
								'default' => 1,
							)
						),
					),
					array( 'title' )
				),
				'output_schema'       => Schema::object(
					array(
						'id'         => Schema::int(),
						'title'      => Schema::str(),
						'status'     => Schema::str(),
						'row_id'     => Schema::str(),
						'column_ids' => Schema::arr( Schema::str() ),
						'edit_url'   => Schema::str(),
					),
					array( 'id', 'row_id' )
				),
				'execute_callback'    => array( __CLASS__, 'execute_layout_create' ),
				'permission_callback' => Kadence_Helpers::can( 'edit_pages' ),
			),
			Kadence_Helpers::ann( false, false, false, false )
		);
	}

	/**
	 * mosmcp/kadence-block-create: generic create-any-Kadence-block.
	 *
	 * @return void
	 */
	private static function block_create() {
		Kadence_Helpers::register(
			'kadence-block-create',
			array(
				'label'               => __( 'Create Kadence Block', 'mosmcp-abilities' ),
				'description'         => __( 'Creates ANY Kadence block and inserts it into a page. Give the block name (e.g. "kadence/advancedheading"), its attributes, optional text content, and optional nested children (for containers like Row → Columns). Fresh uniqueIDs are assigned to the block and every child. Use kadence-list-block-types for valid names and kadence-block-get to copy an existing block\'s attributes. For known blocks (row, column, heading) correct markup is generated; other blocks are attributes-only and may need "Attempt Block Recovery" on first editor open.', 'mosmcp-abilities' ),
				'input_schema'        => Schema::object(
					array_merge(
						self::placement_props(),
						array(
							'block'      => Schema::str( __( 'Kadence block name, with or without the "kadence/" prefix (e.g. "advancedheading").', 'mosmcp-abilities' ), array( 'minLength' => 1 ) ),
							'attributes' => array(
								'type'                 => 'object',
								'description'          => __( 'Block attributes. uniqueID is generated automatically and any value you pass for it is ignored.', 'mosmcp-abilities' ),
								'additionalProperties' => true,
							),
							'content'    => Schema::str( __( 'Optional inner text for leaf blocks (mapped to the block\'s text attribute where known, e.g. heading content, button text).', 'mosmcp-abilities' ) ),
							'children'   => array(
								'type'        => 'array',
								'description' => __( 'Optional nested block specs for containers. Each item has the same shape: { block, attributes?, content?, children? }.', 'mosmcp-abilities' ),
								'items'       => array( 'type' => 'object' ),
							),
						)
					),
					array( 'id', 'block' )
				),
				'output_schema'       => self::insert_output(),
				'execute_callback'    => array( __CLASS__, 'execute_block_create' ),
				'permission_callback' => Kadence_Helpers::can_edit_post(),
			),
			Kadence_Helpers::ann( false, false, false, true )
		);
	}

	/**
	 * mosmcp/kadence-insert-row.
	 *
	 * @return void
	 */
	private static function insert_row() {
		Kadence_Helpers::register(
			'kadence-insert-row',
			array(
				'label'               => __( 'Insert Kadence Row', 'mosmcp-abilities' ),
				'description'         => __( 'Inserts a Kadence Row Layout with the requested number of equal columns. Appends at the page root by default, or after a sibling block via after_id.', 'mosmcp-abilities' ),
				'input_schema'        => Schema::object(
					array_merge(
						self::placement_props(),
						array(
							'columns' => Schema::int(
								__( 'Number of equal columns (1-6).', 'mosmcp-abilities' ),
								array(
									'minimum' => 1,
									'maximum' => 6,
									'default' => 2,
								)
							),
						)
					),
					array( 'id' )
				),
				'output_schema'       => Schema::object(
					array(
						'id'         => Schema::int(),
						'unique_id'  => Schema::str(),
						'block'      => Schema::str(),
						'column_ids' => Schema::arr( Schema::str() ),
						'modified'   => Schema::str(),
						'verified'   => Schema::boolean(),
					),
					array( 'id', 'unique_id' )
				),
				'execute_callback'    => array( __CLASS__, 'execute_insert_row' ),
				'permission_callback' => Kadence_Helpers::can_edit_post(),
			),
			Kadence_Helpers::ann( false, false, false, true )
		);
	}

	/**
	 * mosmcp/kadence-insert-column.
	 *
	 * @return void
	 */
	private static function insert_column() {
		Kadence_Helpers::register(
			'kadence-insert-column',
			array(
				'label'               => __( 'Insert Kadence Column', 'mosmcp-abilities' ),
				'description'         => __( 'Inserts an empty Kadence Column inside an existing Row (parent_id must be a Row\'s uniqueID). Note: Kadence tracks a Row\'s column count in its attributes; after adding a column you may need to update the Row\'s layout in the editor.', 'mosmcp-abilities' ),
				'input_schema'        => Schema::object( self::placement_props(), array( 'id', 'parent_id' ) ),
				'output_schema'       => self::insert_output(),
				'execute_callback'    => array( __CLASS__, 'execute_insert_column' ),
				'permission_callback' => Kadence_Helpers::can_edit_post(),
			),
			Kadence_Helpers::ann( false, false, false, true )
		);
	}

	/**
	 * mosmcp/kadence-insert-heading.
	 *
	 * @return void
	 */
	private static function insert_heading() {
		Kadence_Helpers::register(
			'kadence-insert-heading',
			array(
				'label'               => __( 'Insert Kadence Heading', 'mosmcp-abilities' ),
				'description'         => __( 'Inserts a Kadence Advanced Heading with the given text and level (h1-h6).', 'mosmcp-abilities' ),
				'input_schema'        => Schema::object(
					array_merge(
						self::placement_props(),
						array(
							'text'  => Schema::str( __( 'The heading text.', 'mosmcp-abilities' ), array( 'minLength' => 1 ) ),
							'level' => Schema::int(
								__( 'Heading level, 1-6.', 'mosmcp-abilities' ),
								array(
									'minimum' => 1,
									'maximum' => 6,
									'default' => 2,
								)
							),
						)
					),
					array( 'id', 'text' )
				),
				'output_schema'       => self::insert_output(),
				'execute_callback'    => array( __CLASS__, 'execute_insert_heading' ),
				'permission_callback' => Kadence_Helpers::can_edit_post(),
			),
			Kadence_Helpers::ann( false, false, false, true )
		);
	}

	/**
	 * mosmcp/kadence-insert-image.
	 *
	 * @return void
	 */
	private static function insert_image() {
		Kadence_Helpers::register(
			'kadence-insert-image',
			array(
				'label'               => __( 'Insert Kadence Image', 'mosmcp-abilities' ),
				'description'         => __( 'Inserts a Kadence Image block from a media-library attachment ID (not a raw URL, so the correct source, alt text, and responsive sizes are used).', 'mosmcp-abilities' ),
				'input_schema'        => Schema::object(
					array_merge(
						self::placement_props(),
						array(
							'attachment_id' => Schema::int( __( 'Media library attachment ID of the image to insert.', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
							'alt'           => Schema::str( __( 'Optional alt text override; defaults to the attachment\'s alt text.', 'mosmcp-abilities' ) ),
						)
					),
					array( 'id', 'attachment_id' )
				),
				'output_schema'       => self::insert_output(),
				'execute_callback'    => array( __CLASS__, 'execute_insert_image' ),
				'permission_callback' => Kadence_Helpers::can_edit_post(),
			),
			Kadence_Helpers::ann( false, false, false, true )
		);
	}

	/**
	 * mosmcp/kadence-insert-button.
	 *
	 * @return void
	 */
	private static function insert_button() {
		Kadence_Helpers::register(
			'kadence-insert-button',
			array(
				'label'               => __( 'Insert Kadence Button', 'mosmcp-abilities' ),
				'description'         => __( 'Inserts a Kadence Buttons block containing a single button with the given label and link.', 'mosmcp-abilities' ),
				'input_schema'        => Schema::object(
					array_merge(
						self::placement_props(),
						array(
							'text' => Schema::str( __( 'The button label.', 'mosmcp-abilities' ), array( 'minLength' => 1 ) ),
							'link' => Schema::str( __( 'The button URL.', 'mosmcp-abilities' ) ),
						)
					),
					array( 'id', 'text' )
				),
				'output_schema'       => self::insert_output(),
				'execute_callback'    => array( __CLASS__, 'execute_insert_button' ),
				'permission_callback' => Kadence_Helpers::can_edit_post(),
			),
			Kadence_Helpers::ann( false, false, false, true )
		);
	}

	/**
	 * mosmcp/kadence-insert-icon.
	 *
	 * @return void
	 */
	private static function insert_icon() {
		Kadence_Helpers::register(
			'kadence-insert-icon',
			array(
				'label'               => __( 'Insert Kadence Icon', 'mosmcp-abilities' ),
				'description'         => __( 'Inserts a Kadence Icon block with the given icon name (e.g. "fe_star", "fas_heart"). Use kadence-list-block-types and the Kadence icon library for valid names.', 'mosmcp-abilities' ),
				'input_schema'        => Schema::object(
					array_merge(
						self::placement_props(),
						array(
							'icon' => Schema::str(
								__( 'Kadence icon identifier, e.g. "fe_star".', 'mosmcp-abilities' ),
								array(
									'minLength' => 1,
									'default'   => 'fe_star',
								)
							),
							'size' => Schema::int(
								__( 'Icon size in pixels.', 'mosmcp-abilities' ),
								array(
									'minimum' => 8,
									'maximum' => 400,
									'default' => 48,
								)
							),
						)
					),
					array( 'id' )
				),
				'output_schema'       => self::insert_output(),
				'execute_callback'    => array( __CLASS__, 'execute_insert_icon' ),
				'permission_callback' => Kadence_Helpers::can_edit_post(),
			),
			Kadence_Helpers::ann( false, false, false, true )
		);
	}

	// ---------------------------------------------------------------------
	// Execute callbacks.
	// ---------------------------------------------------------------------

	/**
	 * Executes mosmcp/kadence-layout-create.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function execute_layout_create( $input = array() ) {
		$title = isset( $input['title'] ) ? sanitize_text_field( (string) $input['title'] ) : '';
		if ( '' === $title ) {
			return Kadence_Write_Engine::error( 'validation_failed', __( 'A title is required.', 'mosmcp-abilities' ) );
		}

		$columns = isset( $input['columns'] ) ? max( 1, min( 6, absint( $input['columns'] ) ) ) : 1;

		$page_id = wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'draft',
				'post_title'  => $title,
				'post_author' => get_current_user_id(),
			),
			true
		);
		if ( is_wp_error( $page_id ) ) {
			return $page_id;
		}

		$row = self::build_row( array(), (int) $page_id, $columns );

		$content = Kadence_Blocks_Helper::serialize( array( $row ) );
		wp_update_post(
			array(
				'ID'           => (int) $page_id,
				'post_content' => $content,
			)
		);

		$column_ids = array();
		foreach ( $row['innerBlocks'] as $col ) {
			$column_ids[] = Kadence_Blocks_Helper::unique_id( $col );
		}

		return array(
			'id'         => (int) $page_id,
			'title'      => (string) get_the_title( (int) $page_id ),
			'status'     => (string) get_post_status( (int) $page_id ),
			'row_id'     => Kadence_Blocks_Helper::unique_id( $row ),
			'column_ids' => $column_ids,
			'edit_url'   => (string) get_edit_post_link( (int) $page_id, 'raw' ),
		);
	}

	/**
	 * Executes mosmcp/kadence-block-create.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function execute_block_create( $input = array() ) {
		$spec = array(
			'block'      => isset( $input['block'] ) ? (string) $input['block'] : '',
			'attributes' => isset( $input['attributes'] ) && is_array( $input['attributes'] ) ? $input['attributes'] : array(),
			'children'   => isset( $input['children'] ) && is_array( $input['children'] ) ? $input['children'] : array(),
		);
		if ( array_key_exists( 'content', $input ) ) {
			$spec['content'] = wp_kses_post( (string) $input['content'] );
		}

		return Kadence_Insert_Engine::insert(
			$input,
			static function ( array $blocks, $post_id ) use ( $spec ) {
				return Kadence_Insert_Engine::build( $spec, $blocks, $post_id );
			}
		);
	}

	/**
	 * Executes mosmcp/kadence-insert-row.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function execute_insert_row( $input = array() ) {
		$columns = isset( $input['columns'] ) ? max( 1, min( 6, absint( $input['columns'] ) ) ) : 2;

		$result = Kadence_Insert_Engine::insert(
			$input,
			static function ( array $blocks, $post_id ) use ( $columns ) {
				return self::build_row( $blocks, $post_id, $columns );
			}
		);

		if ( is_array( $result ) && isset( $result['unique_id'] ) ) {
			$result['column_ids'] = self::$last_row_column_ids;
		}

		return $result;
	}

	/**
	 * Column IDs from the most recent build_row(), surfaced to insert-row output.
	 *
	 * @var string[]
	 */
	private static $last_row_column_ids = array();

	/**
	 * Executes mosmcp/kadence-insert-column.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function execute_insert_column( $input = array() ) {
		return Kadence_Insert_Engine::insert(
			$input,
			static function ( array $blocks, $post_id ) {
				return self::build_column( $blocks, $post_id );
			}
		);
	}

	/**
	 * Executes mosmcp/kadence-insert-heading.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function execute_insert_heading( $input = array() ) {
		$text  = isset( $input['text'] ) ? wp_kses_post( (string) $input['text'] ) : '';
		$level = isset( $input['level'] ) ? max( 1, min( 6, absint( $input['level'] ) ) ) : 2;

		return Kadence_Insert_Engine::insert(
			$input,
			static function ( array $blocks, $post_id ) use ( $text, $level ) {
				$uid = Kadence_Insert_Engine::new_id( $blocks, $post_id );
				$tag = 'h' . $level;

				list( $html ) = Kadence_Insert_Engine::markup( 'kadence/advancedheading', $uid, array( 'level' => $level ), $text, false );

				return Kadence_Insert_Engine::node(
					'kadence/advancedheading',
					array(
						'uniqueID' => $uid,
						'content'  => $text,
						'level'    => $level,
						'htmlTag'  => $tag,
					),
					array(),
					$html
				);
			}
		);
	}

	/**
	 * Executes mosmcp/kadence-insert-image.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function execute_insert_image( $input = array() ) {
		$attachment_id = isset( $input['attachment_id'] ) ? absint( $input['attachment_id'] ) : 0;

		if ( 'attachment' !== get_post_type( $attachment_id ) || ! wp_attachment_is_image( $attachment_id ) ) {
			return Kadence_Write_Engine::error( 'validation_failed', __( 'attachment_id is not a valid image in the media library.', 'mosmcp-abilities' ) );
		}

		$src = wp_get_attachment_image_url( $attachment_id, 'full' );
		$alt = isset( $input['alt'] ) && '' !== trim( (string) $input['alt'] )
			? sanitize_text_field( (string) $input['alt'] )
			: (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );

		return Kadence_Insert_Engine::insert(
			$input,
			static function ( array $blocks, $post_id ) use ( $attachment_id, $src, $alt ) {
				$uid  = Kadence_Insert_Engine::new_id( $blocks, $post_id );
				$html = sprintf(
					'<figure class="wp-block-kadence-image kb-image%1$s"><img src="%2$s" alt="%3$s" class="kb-img wp-image-%4$d"/></figure>',
					$uid,
					esc_url( (string) $src ),
					esc_attr( $alt ),
					$attachment_id
				);

				return Kadence_Insert_Engine::node(
					'kadence/image',
					array(
						'uniqueID' => $uid,
						'id'       => $attachment_id,
						'alt'      => $alt,
					),
					array(),
					$html
				);
			}
		);
	}

	/**
	 * Executes mosmcp/kadence-insert-button.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function execute_insert_button( $input = array() ) {
		$text = isset( $input['text'] ) ? wp_kses_post( (string) $input['text'] ) : '';
		$link = isset( $input['link'] ) ? esc_url_raw( (string) $input['link'] ) : '';

		return Kadence_Insert_Engine::insert(
			$input,
			static function ( array $blocks, $post_id ) use ( $text, $link ) {
				$btn_uid = Kadence_Insert_Engine::new_id( $blocks, $post_id );
				// singlebtn is dynamic-render: no saved innerHTML needed.
				$single = Kadence_Insert_Engine::node(
					'kadence/singlebtn',
					array(
						'uniqueID' => $btn_uid,
						'text'     => $text,
						'link'     => $link,
					)
				);

				$wrap_uid = Kadence_Insert_Engine::new_id( array_merge( $blocks, array( $single ) ), $post_id );
				return Kadence_Insert_Engine::node(
					'kadence/advancedbtn',
					array( 'uniqueID' => $wrap_uid ),
					array( $single ),
					'',
					sprintf( '<div class="wp-block-kadence-advancedbtn kb-buttons-wrap kb-btns%s">', $wrap_uid ),
					'</div>'
				);
			}
		);
	}

	/**
	 * Executes mosmcp/kadence-insert-icon.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function execute_insert_icon( $input = array() ) {
		$icon = isset( $input['icon'] ) ? sanitize_text_field( (string) $input['icon'] ) : 'fe_star';
		$size = isset( $input['size'] ) ? max( 8, min( 400, absint( $input['size'] ) ) ) : 48;

		return Kadence_Insert_Engine::insert(
			$input,
			static function ( array $blocks, $post_id ) use ( $icon, $size ) {
				$icon_uid = Kadence_Insert_Engine::new_id( $blocks, $post_id );
				$single   = Kadence_Insert_Engine::node(
					'kadence/single-icon',
					array(
						'uniqueID' => $icon_uid,
						'icon'     => $icon,
						'size'     => $size,
					),
					array(),
					sprintf( '<div class="kt-svg-icon-wrap kt-svg-icon-%s"></div>', esc_attr( $icon ) )
				);

				$wrap_uid = Kadence_Insert_Engine::new_id( array_merge( $blocks, array( $single ) ), $post_id );
				return Kadence_Insert_Engine::node(
					'kadence/icon',
					array( 'uniqueID' => $wrap_uid ),
					array( $single ),
					'',
					sprintf( '<div class="wp-block-kadence-icon kt-svg-icons kt-svg-icons-%s">', $wrap_uid ),
					'</div>'
				);
			}
		);
	}

	// ---------------------------------------------------------------------
	// Block builders.
	// ---------------------------------------------------------------------

	/**
	 * Builds a Row Layout block with N equal empty columns.
	 *
	 * @param array<int, array<string, mixed>> $blocks  Current tree (for uniqueID uniqueness).
	 * @param int                              $post_id Post the block will live on.
	 * @param int                              $columns Column count (1-6).
	 * @return array<string, mixed>
	 */
	private static function build_row( array $blocks, $post_id, $columns ) {
		$row_uid = Kadence_Insert_Engine::new_id( $blocks, $post_id );

		$cols       = array();
		$working    = $blocks;
		$column_ids = array();
		for ( $i = 1; $i <= $columns; $i++ ) {
			$col          = self::build_column( $working, $post_id, $i );
			$cols[]       = $col;
			$working[]    = $col;
			$column_ids[] = Kadence_Blocks_Helper::unique_id( $col );
		}
		self::$last_row_column_ids = $column_ids;

		list( , $lead, $trail ) = Kadence_Insert_Engine::markup( 'kadence/rowlayout', $row_uid, array( 'columns' => $columns ), null, true );

		return Kadence_Insert_Engine::node(
			'kadence/rowlayout',
			array(
				'uniqueID' => $row_uid,
				'columns'  => (int) $columns,
			),
			$cols,
			'',
			$lead,
			$trail
		);
	}

	/**
	 * Builds an empty Column block.
	 *
	 * @param array<int, array<string, mixed>> $blocks   Current tree (for uniqueID uniqueness).
	 * @param int                              $post_id  Post the block will live on.
	 * @param int                              $position 1-based column position, for Kadence's id attr.
	 * @return array<string, mixed>
	 */
	private static function build_column( array $blocks, $post_id, $position = 1 ) {
		$uid = Kadence_Insert_Engine::new_id( $blocks, $post_id );

		list( , $lead, $trail ) = Kadence_Insert_Engine::markup( 'kadence/column', $uid, array(), null, false );

		return Kadence_Insert_Engine::node(
			'kadence/column',
			array(
				'uniqueID' => $uid,
				'id'       => (int) $position,
			),
			array(),
			'',
			$lead,
			$trail
		);
	}
}
