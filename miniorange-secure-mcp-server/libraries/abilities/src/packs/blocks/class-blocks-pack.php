<?php
/**
 * Block editor ability pack: read and change individual blocks in any post or page.
 *
 * Works on content made in the WordPress block editor (Gutenberg), at the level a
 * person works in the editor's list view: one heading, one paragraph, one button.
 * Untouched blocks are preserved byte for byte, every write can be guarded against
 * content that changed after it was read, and every write leaves a revision.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Blocks;

use MoSMCP\Abilities\Ability;
use MoSMCP\Abilities\Ability_Pack;
use MoSMCP\Abilities\Packs\Core\Support\Core_Schema as Schema;
use MoSMCP\Abilities\Packs\Site\Site_Support;
use MoSMCP\Abilities\Support\Html_Policy;

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
 * Class Blocks_Pack
 *
 * Declares the block editor abilities. Execute logic lives in Blocks_Provider.
 */
class Blocks_Pack extends Ability_Pack {

	/**
	 * Ability category slug.
	 */
	const CATEGORY = 'mosmcp-blocks';

	/**
	 * Ability category for block editor abilities.
	 *
	 * @return array Category definition.
	 */
	public function category() {
		return array(
			'slug'        => self::CATEGORY,
			'label'       => __( 'Block Editor', 'mosmcp-abilities' ),
			'description' => __( 'Read and change individual blocks in posts, pages and other content made with the block editor: text, settings, order, inserting, duplicating and deleting.', 'mosmcp-abilities' ),
		);
	}

	/**
	 * The block editor abilities.
	 *
	 * @return Ability[]
	 */
	public function abilities() {
		return array(
			$this->block_list(),
			$this->block_get(),
			$this->block_find(),
			$this->block_types_list(),
			$this->block_update_text(),
			$this->block_update_attributes(),
			$this->block_replace(),
			$this->block_insert(),
			$this->block_move(),
			$this->block_duplicate(),
			$this->block_delete(),
		);
	}

	/**
	 * Defines the mosmcp/block-list ability.
	 *
	 * @return Ability
	 */
	private function block_list() {
		return new Ability(
			'mosmcp/block-list',
			array(
				'label'         => __( 'List Blocks in Content', 'mosmcp-abilities' ),
				'description'   => __( 'Lists every block in a post, page, custom post item or synced pattern, in order, as a flat outline: each block\'s path (such as "2.1"), type, a short text excerpt, how many blocks are inside it, and which text fields can be changed. Read-only. Start here before changing a page: the paths it returns are how every other block ability finds a block, and "modified" is the value to pass as expected_modified so an edit is refused if the page changed meanwhile. Also known as: page structure, list view, outline, what is on this page, show the blocks, gutenberg blocks.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'edit_post',
				'cap_args'      => self::id_args(),
				'annotations'   => Site_Support::annotations( true, false, true, false ),
				'execute'       => array( Blocks_Provider::class, 'list_blocks' ),
				'input_schema'  => Schema::object(
					array(
						'id'        => self::id_prop(),
						'max_depth' => Schema::int(
							__( 'Deepest nesting level to include, where 0 is top-level blocks only.', 'mosmcp-abilities' ),
							array(
								'minimum' => 0,
								'maximum' => 50,
							)
						),
						'per_page'  => Schema::int(
							__( 'Maximum blocks to return.', 'mosmcp-abilities' ),
							array(
								'default' => 200,
								'minimum' => 1,
								'maximum' => 500,
							)
						),
						'offset'    => Schema::int(
							__( 'Blocks to skip, for the next page of a long outline.', 'mosmcp-abilities' ),
							array(
								'default' => 0,
								'minimum' => 0,
							)
						),
					),
					array( 'id' )
				),
				'output_schema' => Schema::object(
					array_merge(
						self::context_props(),
						array(
							'editor'       => Schema::str( __( 'How the content is made: "blocks", "classic" (one classic-editor block), "mixed", "empty", or "builder:<name>" for a page-builder page whose blocks are not its real layout.', 'mosmcp-abilities' ) ),
							'total_blocks' => Schema::int(),
							'showing'      => Schema::int(),
							'offset'       => Schema::int(),
							'truncated'    => Schema::boolean(),
							'blocks'       => Schema::arr( self::outline_item() ),
							'notes'        => Schema::arr( Schema::str() ),
						)
					),
					array( 'id', 'modified', 'editor', 'blocks' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/block-get ability.
	 *
	 * @return Ability
	 */
	private function block_get() {
		return new Ability(
			'mosmcp/block-get',
			array(
				'label'         => __( 'Get Block', 'mosmcp-abilities' ),
				'description'   => __( 'Returns one block in full: its type, every setting (attributes), its complete saved markup including inner blocks, its plain text, and which abilities can change it. Read-only. Use it before rewriting a block with mosmcp/block-replace, so the new markup keeps the settings and classes the block already has. Also known as: block details, block markup, block settings, inspect block.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'edit_post',
				'cap_args'      => self::id_args(),
				'annotations'   => Site_Support::annotations( true, false, true, false ),
				'execute'       => array( Blocks_Provider::class, 'get_block' ),
				'input_schema'  => Schema::object(
					array(
						'id'   => self::id_prop(),
						'path' => self::path_prop(),
					),
					array( 'id', 'path' )
				),
				'output_schema' => Schema::object(
					array_merge(
						self::context_props(),
						array(
							'path'            => Schema::str(),
							'block'           => Schema::str(),
							'block_title'     => Schema::str(),
							'attrs'           => Schema::map( __( 'Every setting stored on the block.', 'mosmcp-abilities' ) ),
							'markup'          => Schema::str( __( 'The block exactly as stored, including its inner blocks.', 'mosmcp-abilities' ) ),
							'text'            => Schema::str(),
							'fields'          => Schema::arr( Schema::str(), __( 'Text fields mosmcp/block-update-text can change on this block.', 'mosmcp-abilities' ) ),
							'is_dynamic'      => Schema::boolean( __( 'True when WordPress builds the block\'s output when the page is shown (latest posts, navigation, query loops).', 'mosmcp-abilities' ) ),
							'settings_only'   => Schema::boolean( __( 'True when the block saves no HTML of its own, so mosmcp/block-update-attributes can change it.', 'mosmcp-abilities' ) ),
							'inner_blocks'    => Schema::arr(
								Schema::object(
									array(
										'path'  => Schema::str(),
										'block' => Schema::str(),
										'text'  => Schema::str(),
									)
								)
							),
							'editing_options' => Schema::arr( Schema::str() ),
						)
					),
					array( 'id', 'path', 'block', 'markup' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/block-find ability.
	 *
	 * @return Ability
	 */
	private function block_find() {
		return new Ability(
			'mosmcp/block-find',
			array(
				'label'         => __( 'Find Blocks', 'mosmcp-abilities' ),
				'description'   => __( 'Finds blocks in one post or page by type (such as "heading", "image" or "core/button"), by words in their text, or by a setting, and returns their paths. Read-only. Faster than reading the whole outline when you know what you are looking for, such as "the heading that says Pricing" or "every image". Text matches the block that holds the words, not the group around it. Also known as: find a block, search the page, locate heading, which block says.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'edit_post',
				'cap_args'      => self::id_args(),
				'annotations'   => Site_Support::annotations( true, false, true, false ),
				'execute'       => array( Blocks_Provider::class, 'find_blocks' ),
				'input_schema'  => Schema::object(
					array(
						'id'              => self::id_prop(),
						'block'           => Schema::str( __( 'Block type to match, such as "heading", "paragraph", "image" or a full name like "core/cover" or one from a block plugin. A name without a slash means a core block.', 'mosmcp-abilities' ) ),
						'text'            => Schema::str( __( 'Words the block\'s own text contains, case-insensitive.', 'mosmcp-abilities' ) ),
						'attribute'       => Schema::str( __( 'A setting name the block must have, such as "anchor" or "level".', 'mosmcp-abilities' ) ),
						'attribute_value' => Schema::str( __( 'Optional value that setting must equal, compared as text.', 'mosmcp-abilities' ) ),
						'per_page'        => Schema::int(
							__( 'Maximum matches to return.', 'mosmcp-abilities' ),
							array(
								'default' => 50,
								'minimum' => 1,
								'maximum' => 200,
							)
						),
					),
					array( 'id' )
				),
				'output_schema' => Schema::object(
					array_merge(
						self::context_props(),
						array(
							'total'     => Schema::int(),
							'showing'   => Schema::int(),
							'truncated' => Schema::boolean(),
							'matches'   => Schema::arr( self::outline_item() ),
						)
					),
					array( 'id', 'total', 'matches' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/block-types-list ability.
	 *
	 * @return Ability
	 */
	private function block_types_list() {
		return new Ability(
			'mosmcp/block-types-list',
			array(
				'label'         => __( 'List Block Types', 'mosmcp-abilities' ),
				'description'   => __( 'Lists the block types this site can use, from WordPress and from plugins, with each one\'s description, where it may be placed (some blocks only go inside a particular parent), and optionally its settings with their types and allowed values. Read-only. Use it to find the right block before writing block markup, and to check a setting\'s name and allowed values. Pass "name" for one block\'s full settings. Also known as: available blocks, block library, what blocks can I use, block settings reference.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'edit_posts',
				'annotations'   => Site_Support::annotations( true, false, true, false ),
				'execute'       => array( Blocks_Provider::class, 'list_types' ),
				'input_schema'  => Schema::object(
					array(
						'search'             => Schema::str( __( 'Keyword matched against the name, title, description and keywords.', 'mosmcp-abilities' ) ),
						'category'           => Schema::str( __( 'Block category, such as "text", "media", "design", "widgets", "theme" or "embed".', 'mosmcp-abilities' ) ),
						'name'               => Schema::str( __( 'One exact block type, such as "core/cover"; returns its settings too.', 'mosmcp-abilities' ) ),
						'include_attributes' => Schema::boolean(
							__( 'Include every type\'s settings. Large; prefer "name" for one block.', 'mosmcp-abilities' ),
							array( 'default' => false )
						),
						'per_page'           => Schema::int(
							__( 'Maximum block types to return.', 'mosmcp-abilities' ),
							array(
								'default' => 50,
								'minimum' => 1,
								'maximum' => 200,
							)
						),
						'offset'             => Schema::int(
							__( 'Block types to skip, for the next page.', 'mosmcp-abilities' ),
							array(
								'default' => 0,
								'minimum' => 0,
							)
						),
					)
				),
				'output_schema' => Schema::object(
					array(
						'total'     => Schema::int(),
						'showing'   => Schema::int(),
						'offset'    => Schema::int(),
						'truncated' => Schema::boolean(),
						'types'     => Schema::arr(
							Schema::object(
								array(
									'name'           => Schema::str(),
									'title'          => Schema::str(),
									'description'    => Schema::str(),
									'category'       => Schema::str(),
									'is_dynamic'     => Schema::boolean(),
									'parent'         => Schema::arr( Schema::str(), __( 'When set, the block may only be placed directly inside one of these.', 'mosmcp-abilities' ) ),
									'ancestor'       => Schema::arr( Schema::str(), __( 'When set, the block may only be placed somewhere inside one of these.', 'mosmcp-abilities' ) ),
									'allowed_blocks' => Schema::arr( Schema::str(), __( 'When set, only these blocks may be placed inside it.', 'mosmcp-abilities' ) ),
									'attributes'     => Schema::arr(
										Schema::object(
											array(
												'name'    => Schema::str(),
												'type'    => Schema::str(),
												'enum'    => Schema::arr( Schema::str() ),
												'default' => Schema::str( __( 'Default value as JSON, or empty when there is none.', 'mosmcp-abilities' ) ),
												'in_markup' => Schema::boolean( __( 'True when the value lives in the block\'s saved HTML rather than its settings comment.', 'mosmcp-abilities' ) ),
											)
										)
									),
								)
							)
						),
						'notes'     => Schema::arr( Schema::str() ),
					),
					array( 'total', 'types' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/block-update-text ability.
	 *
	 * @return Ability
	 */
	private function block_update_text() {
		return new Ability(
			'mosmcp/block-update-text',
			array(
				'label'         => __( 'Update Block Text', 'mosmcp-abilities' ),
				'description'   => __( 'Changes the text of one block, such as a heading, paragraph, list item, button label, quote citation or image caption, keeping its styling, classes and settings exactly as they are. Inline formatting is allowed (bold, italic, links, line breaks); separate paragraphs need separate blocks. Every other block on the page is left byte for byte as it was, and WordPress keeps a revision of the previous version. Use the path from mosmcp/block-list or mosmcp/block-find. It changes words only: to change where a button or link goes, a heading\'s size, alignment or colour, rewrite the block with mosmcp/block-replace. Also known as: change heading, edit paragraph, rename button, change button text, edit text on page, update caption.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'edit_post',
				'cap_args'      => self::id_args(),
				'annotations'   => Site_Support::annotations( false, false, true, false ),
				'execute'       => array( Blocks_Provider::class, 'update_text' ),
				'input_schema'  => Schema::object(
					array_merge(
						array(
							'id'    => self::id_prop(),
							'path'  => self::path_prop(),
							'text'  => Schema::str( __( 'The new text. Inline HTML such as <strong>, <em>, <a href> and <br> is allowed. ', 'mosmcp-abilities' ) . Html_Policy::rule_summary() ),
							'field' => Schema::str(
								__( 'Which text to change: "text" (the main text), "caption" (images, video, audio, embeds, tables) or "citation" (quotes). mosmcp/block-list shows each block\'s fields.', 'mosmcp-abilities' ),
								array(
									'enum'    => array( 'text', 'caption', 'citation' ),
									'default' => 'text',
								)
							),
						),
						self::guard_props()
					),
					array( 'id', 'path', 'text' )
				),
				'output_schema' => self::write_output(
					array(
						'path'     => Schema::str(),
						'block'    => Schema::str(),
						'field'    => Schema::str(),
						'previous' => Schema::str( __( 'The text before the change.', 'mosmcp-abilities' ) ),
						'current'  => Schema::str(),
					),
					array( 'path', 'block', 'previous', 'current' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/block-update-attributes ability.
	 *
	 * @return Ability
	 */
	private function block_update_attributes() {
		return new Ability(
			'mosmcp/block-update-attributes',
			array(
				'label'         => __( 'Update Block Settings', 'mosmcp-abilities' ),
				'description'   => __( 'Changes the settings of a block whose output WordPress builds when the page is shown, such as Latest Posts (how many, which category), a query loop, an embedded template part or a synced pattern reference. Values are checked against the block type\'s own definition, so a misspelled setting or a wrong value is refused with the list of valid ones. Blocks that save their own HTML (paragraphs, groups, images) are refused, because changing a setting alone would put their HTML out of step; rewrite those with mosmcp/block-replace. Also known as: block settings, change number of posts shown, block options, change query loop.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'edit_post',
				'cap_args'      => self::id_args(),
				'annotations'   => Site_Support::annotations( false, false, true, false ),
				'execute'       => array( Blocks_Provider::class, 'update_attributes' ),
				'input_schema'  => Schema::object(
					array_merge(
						array(
							'id'         => self::id_prop(),
							'path'       => self::path_prop(),
							'attributes' => Schema::map( __( 'Settings to set, by name, merged into the existing ones. mosmcp/block-types-list with "name" lists every setting with its type and allowed values.', 'mosmcp-abilities' ) ),
							'remove'     => Schema::arr( Schema::str(), __( 'Setting names to clear, returning them to their defaults.', 'mosmcp-abilities' ) ),
						),
						self::guard_props()
					),
					array( 'id', 'path' )
				),
				'output_schema' => self::write_output(
					array(
						'path'     => Schema::str(),
						'block'    => Schema::str(),
						'previous' => Schema::map( __( 'Settings before the change.', 'mosmcp-abilities' ) ),
						'current'  => Schema::map( __( 'Settings after the change.', 'mosmcp-abilities' ) ),
					),
					array( 'path', 'block' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/block-replace ability.
	 *
	 * @return Ability
	 */
	private function block_replace() {
		return new Ability(
			'mosmcp/block-replace',
			array(
				'label'         => __( 'Replace Block', 'mosmcp-abilities' ),
				'description'   => __( 'Replaces one block, and everything inside it, with new block markup: one block or several. This is how to restyle a block, change its layout or rebuild a section, because a block\'s settings and its saved HTML must change together. Read the current markup with mosmcp/block-get first and change only what is needed. The markup is checked before anything is saved: block types must exist on the site, blocks must be allowed where they are placed, and HTML follows the same rule as the editor. Other blocks are not touched, and a revision keeps the old version. Also known as: rewrite block, restyle section, change block layout, replace section, swap block, change button link, change link address, make heading bigger, change heading level, change heading size, change alignment, center text, change colour, numbered list.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'edit_post',
				'cap_args'      => self::id_args(),
				'annotations'   => Site_Support::annotations( false, false, false, false ),
				'execute'       => array( Blocks_Provider::class, 'replace' ),
				'input_schema'  => Schema::object(
					array_merge(
						array(
							'id'     => self::id_prop(),
							'path'   => self::path_prop(),
							'markup' => Schema::str( __( 'Block markup to put in its place, in the format WordPress stores, for example <!-- wp:heading {"level":3} --><h3 class="wp-block-heading">Title</h3><!-- /wp:heading -->. ', 'mosmcp-abilities' ) . Html_Policy::rule_summary() ),
						),
						self::guard_props()
					),
					array( 'id', 'path', 'markup' )
				),
				'output_schema' => self::write_output(
					array(
						'path'           => Schema::str(),
						'block'          => Schema::str(),
						'replaced_block' => Schema::str(),
						'paths'          => Schema::arr( Schema::str(), __( 'Paths of the new blocks.', 'mosmcp-abilities' ) ),
					),
					array( 'path', 'block', 'paths' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/block-insert ability.
	 *
	 * @return Ability
	 */
	private function block_insert() {
		return new Ability(
			'mosmcp/block-insert',
			array(
				'label'         => __( 'Insert Blocks', 'mosmcp-abilities' ),
				'description'   => __( 'Adds new blocks to a post or page: at the start or end, before or after a block, or inside a container such as a group, column, list or buttons. Give simple blocks in "blocks" (paragraph, heading, list, quote, button, image from the media library, separator, spacer, custom HTML) and they are built exactly as the editor builds them; give any other block as "markup"; or insert a registered "pattern" or a synced pattern by ID. Checked before saving: block types must exist, blocks must be allowed where they go (a list item only in a list, a column only in columns), and HTML follows the editor\'s rule. Also known as: add a section, add paragraph, add heading, add image to page, insert pattern, add a button, add content to page.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'edit_post',
				'cap_args'      => self::id_args(),
				'annotations'   => Site_Support::annotations( false, false, false, false ),
				'execute'       => array( Blocks_Provider::class, 'insert' ),
				'input_schema'  => Schema::object(
					array_merge(
						array(
							'id'                => self::id_prop(),
							'blocks'            => Schema::arr(
								Schema::object(
									array(
										'type'          => Schema::str(
											__( 'Block to build.', 'mosmcp-abilities' ),
											array( 'enum' => Block_Markup::SHORTHAND_TYPES )
										),
										'text'          => Schema::str( __( 'Text for paragraph, heading, quote and button; raw HTML for html. Inline formatting allowed.', 'mosmcp-abilities' ) ),
										'level'         => Schema::int(
											__( 'Heading level, 1 to 6.', 'mosmcp-abilities' ),
											array(
												'minimum' => 1,
												'maximum' => 6,
											)
										),
										'items'         => Schema::arr( Schema::str(), __( 'List items.', 'mosmcp-abilities' ) ),
										'ordered'       => Schema::boolean( __( 'Numbered list instead of bullets.', 'mosmcp-abilities' ) ),
										'citation'      => Schema::str( __( 'Quote attribution.', 'mosmcp-abilities' ) ),
										'url'           => Schema::str( __( 'Button link.', 'mosmcp-abilities' ) ),
										'attachment_id' => Schema::int( __( 'Image: media library ID (upload first with mosmcp/media-upload-from-url).', 'mosmcp-abilities' ) ),
										'alt'           => Schema::str( __( 'Image alt text; defaults to the media item\'s own.', 'mosmcp-abilities' ) ),
										'size'          => Schema::str( __( 'Image size such as "large", "medium" or "full".', 'mosmcp-abilities' ) ),
										'height'        => Schema::int( __( 'Spacer height in pixels.', 'mosmcp-abilities' ) ),
									),
									array( 'type' )
								),
								__( 'Simple blocks to build, in order. Use this, "markup", "pattern" or "synced_pattern_id": exactly one.', 'mosmcp-abilities' )
							),
							'markup'            => Schema::str( __( 'Block markup for any block, in the format WordPress stores. ', 'mosmcp-abilities' ) . Html_Policy::rule_summary() ),
							'pattern'           => Schema::str( __( 'Name of a registered pattern to insert, such as "twentytwentyfive/cta-newsletter". List them with mosmcp/theme-pattern-list.', 'mosmcp-abilities' ) ),
							'synced_pattern_id' => Schema::int( __( 'ID of a synced pattern (reusable block) to insert as a reference, so later edits to the pattern show here too.', 'mosmcp-abilities' ) ),
						),
						self::position_props(),
						self::lock_props()
					),
					array( 'id' )
				),
				'output_schema' => self::write_output(
					array(
						'path'  => Schema::str( __( 'Path of the first inserted block.', 'mosmcp-abilities' ) ),
						'block' => Schema::str(),
						'paths' => Schema::arr( Schema::str(), __( 'Paths of every inserted block.', 'mosmcp-abilities' ) ),
					),
					array( 'path', 'paths' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/block-move ability.
	 *
	 * @return Ability
	 */
	private function block_move() {
		return new Ability(
			'mosmcp/block-move',
			array(
				'label'         => __( 'Move Block', 'mosmcp-abilities' ),
				'description'   => __( 'Moves one block, with everything inside it, to a new position: to the start or end of the content, before or after another block, or into a container. The block is moved as stored, so nothing about it changes but its position. Moving it somewhere it is not allowed (a column outside columns) or into itself is refused. Also known as: move section up, move block down, reorder blocks, reorder sections, swap sections, change the order of sections, rearrange page, put this above that.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'edit_post',
				'cap_args'      => self::id_args(),
				'annotations'   => Site_Support::annotations( false, false, false, false ),
				'execute'       => array( Blocks_Provider::class, 'move' ),
				'input_schema'  => Schema::object(
					array_merge(
						array(
							'id'   => self::id_prop(),
							'path' => self::path_prop( __( 'Path of the block to move.', 'mosmcp-abilities' ) ),
						),
						self::position_props(),
						self::guard_props()
					),
					array( 'id', 'path', 'position' )
				),
				'output_schema' => self::write_output(
					array(
						'path'      => Schema::str( __( 'Where the block is now.', 'mosmcp-abilities' ) ),
						'from_path' => Schema::str(),
						'block'     => Schema::str(),
					),
					array( 'path', 'from_path' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/block-duplicate ability.
	 *
	 * @return Ability
	 */
	private function block_duplicate() {
		return new Ability(
			'mosmcp/block-duplicate',
			array(
				'label'         => __( 'Duplicate Block', 'mosmcp-abilities' ),
				'description'   => __( 'Copies one block, with everything inside it, and places the copy directly after the original, the same as Duplicate in the editor. HTML anchors are cleared on the copy so the page never has two elements with the same ID. Blocks from a plugin that needs a fresh ID on every copy are refused, and the reply names that plugin\'s own duplicate ability. Also known as: copy block, duplicate section, clone block, repeat this section.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'edit_post',
				'cap_args'      => self::id_args(),
				'annotations'   => Site_Support::annotations( false, false, false, false ),
				'execute'       => array( Blocks_Provider::class, 'duplicate' ),
				'input_schema'  => Schema::object(
					array_merge(
						array(
							'id'   => self::id_prop(),
							'path' => self::path_prop(),
						),
						self::guard_props()
					),
					array( 'id', 'path' )
				),
				'output_schema' => self::write_output(
					array(
						'path'      => Schema::str( __( 'Path of the copy.', 'mosmcp-abilities' ) ),
						'from_path' => Schema::str(),
						'block'     => Schema::str(),
					),
					array( 'path', 'from_path' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/block-delete ability.
	 *
	 * @return Ability
	 */
	private function block_delete() {
		return new Ability(
			'mosmcp/block-delete',
			array(
				'label'         => __( 'Delete Block', 'mosmcp-abilities' ),
				'description'   => __( 'Removes one block and everything inside it from a post or page. Every other block stays as it was. WordPress keeps a revision of the previous version, so the deletion can be undone with mosmcp/revision-restore. Tell the person what is being removed (the result includes its text). Pass expected_block and expected_modified from your last read so the wrong block can never be removed if the page changed meanwhile. Also known as: remove block, delete section, remove paragraph, take this off the page.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'edit_post',
				'cap_args'      => self::id_args(),
				'annotations'   => Site_Support::annotations( false, true, false, false ),
				'execute'       => array( Blocks_Provider::class, 'delete' ),
				'input_schema'  => Schema::object(
					array_merge(
						array(
							'id'   => self::id_prop(),
							'path' => self::path_prop(),
						),
						self::guard_props()
					),
					array( 'id', 'path' )
				),
				'output_schema' => self::write_output(
					array(
						'path'           => Schema::str( __( 'Path the block had.', 'mosmcp-abilities' ) ),
						'block'          => Schema::str(),
						'removed_text'   => Schema::str(),
						'blocks_removed' => Schema::int( __( 'The block itself plus every block inside it.', 'mosmcp-abilities' ) ),
					),
					array( 'path', 'block', 'blocks_removed' )
				),
			)
		);
	}

	/**
	 * Maps input to the post ID for the object-level capability check.
	 *
	 * @return callable
	 */
	private static function id_args() {
		return static function ( $input ) {
			return array( isset( $input['id'] ) ? absint( $input['id'] ) : 0 );
		};
	}

	/**
	 * The post ID input.
	 *
	 * @return array<string, mixed>
	 */
	private static function id_prop() {
		return Schema::int(
			__( 'ID of the post, page, custom post item or synced pattern.', 'mosmcp-abilities' ),
			array( 'minimum' => 1 )
		);
	}

	/**
	 * The block path input.
	 *
	 * @param string $description Optional description override.
	 * @return array<string, mixed>
	 */
	private static function path_prop( $description = '' ) {
		return Schema::str( '' !== $description ? $description : __( 'The block\'s path, such as "0" (first block) or "2.1" (second block inside the third), from mosmcp/block-list or mosmcp/block-find.', 'mosmcp-abilities' ) );
	}

	/**
	 * The optimistic-lock input.
	 *
	 * @return array<string, mixed>
	 */
	private static function lock_props() {
		return array(
			'expected_modified' => Schema::str( __( 'The "modified" value from your last read. When given, the change is refused if the content has changed since, so a stale path can never hit the wrong block.', 'mosmcp-abilities' ) ),
		);
	}

	/**
	 * The lock input plus the expected-block check.
	 *
	 * @return array<string, mixed>
	 */
	private static function guard_props() {
		return array_merge(
			self::lock_props(),
			array(
				'expected_block' => Schema::str( __( 'The block type you expect at the path, such as "heading". When given, the change is refused if a different block is there.', 'mosmcp-abilities' ) ),
			)
		);
	}

	/**
	 * The position inputs shared by insert and move.
	 *
	 * @return array<string, mixed>
	 */
	private static function position_props() {
		return array(
			'position' => Schema::str(
				__( 'Where to put it: "start" or "end" of the content, "before" or "after" the target block, or "inside_start" or "inside_end" of the target container.', 'mosmcp-abilities' ),
				array(
					'enum'    => Blocks_Provider::POSITIONS,
					'default' => 'end',
				)
			),
			'target'   => Schema::str( __( 'Path of the block the position is relative to. Required for before, after, inside_start and inside_end.', 'mosmcp-abilities' ) ),
		);
	}

	/**
	 * The post context every block result starts with.
	 *
	 * @return array<string, mixed>
	 */
	private static function context_props() {
		return array(
			'id'          => Schema::int(),
			'post_type'   => Schema::str(),
			'title'       => Schema::str(),
			'status'      => Schema::str(),
			'modified'    => Schema::str( __( 'Last-modified time. Pass it as expected_modified on your next change.', 'mosmcp-abilities' ) ),
			'view_url'    => Schema::str( __( 'Public address, when published.', 'mosmcp-abilities' ) ),
			'preview_url' => Schema::str( __( 'Preview address for unpublished content, for signed-in editors.', 'mosmcp-abilities' ) ),
			'edit_url'    => Schema::str(),
		);
	}

	/**
	 * One row of a block outline.
	 *
	 * @return array<string, mixed>
	 */
	private static function outline_item() {
		return Schema::object(
			array(
				'path'        => Schema::str(),
				'depth'       => Schema::int(),
				'block'       => Schema::str(),
				'title'       => Schema::str(),
				'text'        => Schema::str(),
				'child_count' => Schema::int(),
				'fields'      => Schema::arr( Schema::str(), __( 'Text fields mosmcp/block-update-text can change.', 'mosmcp-abilities' ) ),
				'key_attrs'   => Schema::map( __( 'Notable settings, such as heading level, alignment, anchor or link.', 'mosmcp-abilities' ) ),
				'is_dynamic'  => Schema::boolean(),
			)
		);
	}

	/**
	 * The result every write returns, plus ability-specific fields.
	 *
	 * @param array<string, mixed> $extra    Ability-specific properties.
	 * @param string[]             $required Ability-specific required properties.
	 * @return array<string, mixed>
	 */
	private static function write_output( array $extra, array $required ) {
		return Schema::object(
			array_merge(
				self::context_props(),
				$extra,
				array(
					'changed'     => Schema::boolean( __( 'False when the content already matched and nothing was saved.', 'mosmcp-abilities' ) ),
					'revision_id' => Schema::int( __( 'Revision holding the previous version, for mosmcp/revision-restore; 0 when none was made.', 'mosmcp-abilities' ) ),
					'warnings'    => Schema::warnings(),
					'notes'       => Schema::arr( Schema::str() ),
				)
			),
			array_merge( array( 'id', 'modified', 'changed' ), $required )
		);
	}
}
