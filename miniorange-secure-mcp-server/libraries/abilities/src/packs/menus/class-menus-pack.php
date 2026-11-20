<?php
/**
 * Menus & Navigation ability pack: classic menus and block-theme Navigation menus.
 *
 * Classic themes show nav_menu menus in theme locations; block themes show
 * wp_navigation menus through Navigation blocks in their header and footer. Both
 * are covered, and every listing says which one the active theme actually uses,
 * so an assistant does not edit a menu that no visitor will ever see.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Menus;

use MoSMCP\Abilities\Ability;
use MoSMCP\Abilities\Ability_Pack;
use MoSMCP\Abilities\Packs\Core\Support\Core_Schema as Schema;
use MoSMCP\Abilities\Packs\Site\Site_Support;

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
 * Class Menus_Pack
 *
 * Declares the menu and navigation abilities. Execute logic lives in
 * Menus_Provider (classic menus) and Navigation_Provider (block themes).
 */
class Menus_Pack extends Ability_Pack {

	/**
	 * Ability category for menu and navigation abilities.
	 *
	 * @return array Category definition.
	 */
	public function category() {
		return array(
			'slug'        => Menus_Support::CATEGORY,
			'label'       => __( 'Menus & Navigation', 'mosmcp-abilities' ),
			'description' => __( 'Read and edit the site\'s navigation: classic menus and their theme locations, and the Navigation menus block themes show in their header and footer.', 'mosmcp-abilities' ),
		);
	}

	/**
	 * The menu and navigation abilities.
	 *
	 * @return Ability[]
	 */
	public function abilities() {
		return array(
			$this->menu_list(),
			$this->menu_get(),
			$this->menu_create(),
			$this->menu_rename(),
			$this->menu_delete(),
			$this->menu_add_item(),
			$this->menu_update_item(),
			$this->menu_remove_item(),
			$this->menu_reorder_items(),
			$this->menu_assign_location(),
			$this->navigation_list(),
			$this->navigation_get(),
			$this->navigation_add_link(),
			$this->navigation_update_link(),
			$this->navigation_remove_link(),
			$this->navigation_restore_revision(),
		);
	}

	/**
	 * Builds an ability with the pack's shared category and capability.
	 *
	 * @param string               $name Ability name.
	 * @param array<string, mixed> $args Ability definition.
	 * @return Ability
	 */
	private function ability( $name, array $args ) {
		return new Ability(
			$name,
			array_merge(
				array(
					'category'   => Menus_Support::CATEGORY,
					'capability' => Menus_Support::CAPABILITY,
				),
				$args
			)
		);
	}

	/**
	 * The fields shared by menu-add-item and menu-update-item.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function item_fields() {
		return array(
			'post_id'           => Schema::int( __( 'Link to this page, post or other public content, by its ID. The item follows the page if its address or title changes.', 'mosmcp-abilities' ) ),
			'term_id'           => Schema::int( __( 'Link to this category, tag or other term, by its ID.', 'mosmcp-abilities' ) ),
			'archive_post_type' => Schema::str( __( 'Link to the archive page of a content type, for example "product" for the shop page or "post" for the blog.', 'mosmcp-abilities' ) ),
			'url'               => Schema::str( __( 'Link to any other address: a full https:// address, a path on this site such as "/contact", an anchor such as "#pricing", or a mailto: or tel: link. Script addresses are refused.', 'mosmcp-abilities' ) ),
			'label'             => Schema::str( __( 'Text shown in the menu. Required for a url link. For page and category links it is optional: without one the item shows the page\'s own title and follows it when the page is renamed.', 'mosmcp-abilities' ) ),
			'open_in_new_tab'   => Schema::boolean(),
			'css_classes'       => array(
				'type'        => array( 'array', 'string' ),
				'items'       => array( 'type' => 'string' ),
				'description' => __( 'CSS classes for the item, as a list or a space-separated string. Replaces the current classes; pass an empty list to clear them.', 'mosmcp-abilities' ),
			),
			'description'       => Schema::str( __( 'Item description. Only some themes display it.', 'mosmcp-abilities' ) ),
			'title_attribute'   => Schema::str( __( 'Tooltip text shown when hovering the link.', 'mosmcp-abilities' ) ),
			'link_rel'          => Schema::str( __( 'Link relationship, for example "nofollow" or "noopener".', 'mosmcp-abilities' ) ),
		);
	}

	/**
	 * Output schema shared by the single-item menu writes.
	 *
	 * @param array<string, array<string, mixed>> $extra Extra properties.
	 * @return array<string, mixed>
	 */
	private function item_write_output( array $extra = array() ) {
		return Schema::object(
			array_merge(
				array(
					'menu'     => Menus_Support::menu_schema(),
					'item'     => Menus_Support::item_schema(),
					'outline'  => Menus_Support::outline_schema(),
					'warnings' => Schema::warnings(),
					'notes'    => Schema::arr( Schema::str() ),
				),
				$extra
			),
			array( 'menu', 'item' )
		);
	}

	/**
	 * One row of an outline, for lists of moved items.
	 *
	 * @return array<string, mixed>
	 */
	private function outline_row() {
		return Schema::object(
			array(
				'item_id'        => Schema::int(),
				'label'          => Schema::str(),
				'parent_item_id' => Schema::int(),
				'position'       => Schema::int(),
				'depth'          => Schema::int(),
			)
		);
	}

	/**
	 * Input schema for a Navigation menu reference.
	 *
	 * @return array<string, mixed>
	 */
	private function navigation_ref() {
		return array(
			'type'        => array( 'integer', 'string' ),
			'description' => __( 'The Navigation menu: its ID (navigation_id from mosmcp/navigation-list) or its exact title.', 'mosmcp-abilities' ),
		);
	}

	/**
	 * Optimistic-lock input for Navigation writes.
	 *
	 * @return array<string, mixed>
	 */
	private function expected_modified() {
		return Schema::str( __( 'Optional. The "modified" value from the last read. If the Navigation menu changed since (for example someone edited it in the Site Editor), the edit is refused instead of overwriting their work.', 'mosmcp-abilities' ) );
	}

	/**
	 * Output schema shared by the Navigation writes.
	 *
	 * @return array<string, mixed>
	 */
	private function navigation_write_output() {
		return Schema::object(
			array(
				'navigation'           => Menus_Support::nav_summary_schema(),
				'path'                 => Schema::str( __( 'Path of the entry that was added or changed (or removed).', 'mosmcp-abilities' ) ),
				'link'                 => Menus_Support::nav_block_schema(),
				'changed_fields'       => Schema::arr( Schema::str() ),
				'removed'              => Schema::arr( Menus_Support::nav_block_schema(), __( 'Entries removed, as they were before the change.', 'mosmcp-abilities' ) ),
				'previous_revision_id' => Schema::int( __( 'Revision holding the menu as it was before this change; mosmcp/navigation-restore-revision puts it back. 0 when revisions are off.', 'mosmcp-abilities' ) ),
				'revision_id'          => Schema::int( __( 'Revision holding the menu as it is now.', 'mosmcp-abilities' ) ),
				'blocks'               => Schema::arr( Menus_Support::nav_block_schema(), __( 'Every entry of the menu after the change, with fresh paths.', 'mosmcp-abilities' ) ),
				'warnings'             => Schema::warnings(),
				'notes'                => Schema::arr( Schema::str() ),
			),
			array( 'navigation', 'blocks' )
		);
	}

	/**
	 * Defines the mosmcp/menu-list ability.
	 *
	 * @return Ability
	 */
	private function menu_list() {
		return $this->ability(
			'mosmcp/menu-list',
			array(
				'label'         => __( 'List Menus and Locations', 'mosmcp-abilities' ),
				'description'   => __( 'Lists every classic menu (with its item count and the theme locations it fills) and every menu location the active theme offers (with the menu shown there, if any). Read-only. Start here for any menu request: it also says whether the theme is a block theme, in which case the header and footer use Navigation menus instead and the next call should be mosmcp/navigation-list. Then use mosmcp/menu-get to see a menu\'s items. Also known as: menus, navigation menu, nav menu, header menu, footer menu, main menu, menu locations, which menu is where.', 'mosmcp-abilities' ),
				'annotations'   => Site_Support::annotations( true, false, true, false ),
				'execute'       => array( Menus_Provider::class, 'menu_list' ),
				'input_schema'  => Schema::object( array() ),
				'output_schema' => Schema::object(
					array(
						'is_block_theme'       => Schema::boolean( __( 'True when the active theme is a block theme, whose header and footer use Navigation menus rather than classic menus.', 'mosmcp-abilities' ) ),
						'theme_supports_menus' => Schema::boolean(),
						'menus'                => Schema::arr( Menus_Support::menu_schema() ),
						'locations'            => Schema::arr(
							Schema::object(
								array(
									'location'  => Schema::str( __( 'Location slug, for example "primary".', 'mosmcp-abilities' ) ),
									'label'     => Schema::str(),
									'menu_id'   => Schema::int( __( 'Menu shown there, 0 when none.', 'mosmcp-abilities' ) ),
									'menu_name' => Schema::str(),
								)
							)
						),
						'navigation_count'     => Schema::int( __( 'Number of block-theme Navigation menus; see mosmcp/navigation-list.', 'mosmcp-abilities' ) ),
						'warnings'             => Schema::warnings(),
						'notes'                => Schema::arr( Schema::str() ),
					),
					array( 'is_block_theme', 'menus', 'locations' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/menu-get ability.
	 *
	 * @return Ability
	 */
	private function menu_get() {
		return $this->ability(
			'mosmcp/menu-get',
			array(
				'label'         => __( 'Get Menu Items', 'mosmcp-abilities' ),
				'description'   => __( 'Returns one classic menu with all its items as a nested tree: each item\'s label, what it links to, its address, its position among its siblings and its children. Read-only. It also flags broken items: links to pages that were deleted, trashed or are still drafts, links to deleted categories, and items left orphaned by an earlier deletion, including items WordPress hides from visitors. Use the item_id values it returns with mosmcp/menu-update-item, mosmcp/menu-remove-item and mosmcp/menu-reorder-items. Also known as: show menu, menu items, what is in the menu, menu links, menu structure, broken menu links.', 'mosmcp-abilities' ),
				'annotations'   => Site_Support::annotations( true, false, true, false ),
				'execute'       => array( Menus_Provider::class, 'menu_get' ),
				'input_schema'  => Schema::object(
					array( 'menu' => Menus_Support::menu_ref_input() ),
					array( 'menu' )
				),
				'output_schema' => Schema::object(
					array(
						'menu'       => Menus_Support::menu_schema(),
						'item_count' => Schema::int( __( 'Every item, including ones hidden from visitors.', 'mosmcp-abilities' ) ),
						'max_depth'  => Schema::int(),
						'items'      => Schema::arr( Menus_Support::tree_item_schema( Menus_Support::TREE_SCHEMA_LEVELS ), __( 'Top-level items in display order, each with its children nested inside.', 'mosmcp-abilities' ) ),
						'warnings'   => Schema::warnings( __( 'Broken or hidden items, each naming the item and how to fix it.', 'mosmcp-abilities' ) ),
						'notes'      => Schema::arr( Schema::str() ),
					),
					array( 'menu', 'items' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/menu-create ability.
	 *
	 * @return Ability
	 */
	private function menu_create() {
		return $this->ability(
			'mosmcp/menu-create',
			array(
				'label'         => __( 'Create Menu', 'mosmcp-abilities' ),
				'description'   => __( 'Creates a new, empty classic menu, and optionally shows it in a theme location straight away (replacing whatever menu was there, which is kept). Menu names must be unique; a name already in use is refused with the existing menu\'s ID. On a block theme classic menus are not displayed, so use mosmcp/navigation-list instead there. Next, add items with mosmcp/menu-add-item. Also known as: new menu, make a menu, add a menu, create navigation menu, create footer menu.', 'mosmcp-abilities' ),
				'annotations'   => Site_Support::annotations( false, false, false, false ),
				'execute'       => array( Menus_Provider::class, 'menu_create' ),
				'input_schema'  => Schema::object(
					array(
						'name'           => Schema::str( __( 'Menu name, for example "Main Menu".', 'mosmcp-abilities' ) ),
						'location'       => Schema::str( __( 'Optional. Theme location to show the menu in, by slug or label as mosmcp/menu-list reports them.', 'mosmcp-abilities' ) ),
						'description'    => Schema::str(),
						'auto_add_pages' => Schema::boolean( __( 'Add every newly published top-level page to this menu automatically.', 'mosmcp-abilities' ), array( 'default' => false ) ),
					),
					array( 'name' )
				),
				'output_schema' => Schema::object(
					array(
						'menu'               => Menus_Support::menu_schema(),
						'location'           => Schema::str( __( 'Location the menu was assigned to, empty when none.', 'mosmcp-abilities' ) ),
						'replaced_menu_id'   => Schema::int( __( 'Menu that location showed before, 0 when none. That menu still exists.', 'mosmcp-abilities' ) ),
						'replaced_menu_name' => Schema::str(),
						'warnings'           => Schema::warnings(),
						'notes'              => Schema::arr( Schema::str() ),
					),
					array( 'menu' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/menu-rename ability.
	 *
	 * @return Ability
	 */
	private function menu_rename() {
		return $this->ability(
			'mosmcp/menu-rename',
			array(
				'label'         => __( 'Rename Menu', 'mosmcp-abilities' ),
				'description'   => __( 'Renames a classic menu and optionally changes its description. The menu\'s items and locations are untouched, its description is kept unless a new one is given, and its slug is kept by default so anything that displays the menu by slug keeps working (WordPress on its own would change it). Visitors never see a menu\'s name; this is for keeping menus recognisable in wp-admin. Also known as: rename menu, change menu name, edit menu name.', 'mosmcp-abilities' ),
				'annotations'   => Site_Support::annotations( false, false, true, false ),
				'execute'       => array( Menus_Provider::class, 'menu_rename' ),
				'input_schema'  => Schema::object(
					array(
						'menu'        => Menus_Support::menu_ref_input(),
						'name'        => Schema::str( __( 'The new name.', 'mosmcp-abilities' ) ),
						'description' => Schema::str( __( 'Optional new description. Omit to keep the current one.', 'mosmcp-abilities' ) ),
						'keep_slug'   => Schema::boolean( __( 'Keep the current slug (default). Set false to let the slug follow the new name.', 'mosmcp-abilities' ), array( 'default' => true ) ),
					),
					array( 'menu', 'name' )
				),
				'output_schema' => Schema::object(
					array(
						'menu'          => Menus_Support::menu_schema(),
						'previous_name' => Schema::str(),
						'changed'       => Schema::boolean(),
						'slug_changed'  => Schema::boolean(),
						'warnings'      => Schema::warnings(),
						'notes'         => Schema::arr( Schema::str() ),
					),
					array( 'menu', 'changed' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/menu-delete ability.
	 *
	 * @return Ability
	 */
	private function menu_delete() {
		return $this->ability(
			'mosmcp/menu-delete',
			array(
				'label'         => __( 'Delete Menu', 'mosmcp-abilities' ),
				'description'   => __( 'Permanently deletes a classic menu and all of its items. Classic menus have no trash and no revisions, so this cannot be undone; it needs confirm set to true, and without it the reply says exactly what would be lost, including the theme locations that would be left empty. The pages and posts the menu linked to are not touched. The result lists every removed item in order so the menu could be rebuilt. Also known as: delete menu, remove menu, get rid of a menu.', 'mosmcp-abilities' ),
				'annotations'   => Site_Support::annotations( false, true, true, false ),
				'execute'       => array( Menus_Provider::class, 'menu_delete' ),
				'input_schema'  => Schema::object(
					array(
						'menu'    => Menus_Support::menu_ref_input(),
						'confirm' => Schema::boolean(
							__( 'Must be true. The menu and every item in it are deleted for good.', 'mosmcp-abilities' ),
							array( 'default' => false )
						),
					),
					// `confirm` is deliberately NOT required here. Marking it required makes
					// the schema reject the call before the ability runs, so the caller gets
					// a generic "confirm is required" instead of the refusal naming what is
					// about to happen, which is the whole point of the gate. Optional here,
					// enforced in the provider, is what surfaces the warning.
					array( 'menu' )
				),
				'output_schema' => Schema::object(
					array(
						'deleted'           => Schema::boolean(),
						'menu_id'           => Schema::int(),
						'name'              => Schema::str(),
						'items_removed'     => Schema::int(),
						'locations_emptied' => Schema::arr( Schema::str(), __( 'Theme locations that now show no menu.', 'mosmcp-abilities' ) ),
						'removed_items'     => Schema::arr( Menus_Support::item_schema(), __( 'Every item that was in the menu, top to bottom, as it was before the delete.', 'mosmcp-abilities' ) ),
						'notes'             => Schema::arr( Schema::str() ),
					),
					array( 'deleted', 'menu_id' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/menu-add-item ability.
	 *
	 * @return Ability
	 */
	private function menu_add_item() {
		return $this->ability(
			'mosmcp/menu-add-item',
			array(
				'label'         => __( 'Add Menu Item', 'mosmcp-abilities' ),
				'description'   => __( 'Adds one item to a classic menu: a page or post (post_id), a category or tag (term_id), a content-type archive (archive_post_type), or any other address (url plus label). It can go under another item (parent_item_id) at a chosen position among its siblings. The target is checked first: a missing or trashed page is refused, a draft is added with a warning that visitors cannot open it yet, and unsafe addresses are refused. Returns the new item and the whole menu outline so the result can be confirmed. On a block theme, classic menus are not shown to visitors: add header links with mosmcp/navigation-add-link instead. Also known as: add to menu, add menu item, add link to menu, add page to menu, menu link, put in the navigation, add submenu item.', 'mosmcp-abilities' ),
				'annotations'   => Site_Support::annotations( false, false, false, false ),
				'execute'       => array( Menus_Provider::class, 'menu_add_item' ),
				'input_schema'  => Schema::object(
					array_merge(
						array( 'menu' => Menus_Support::menu_ref_input() ),
						$this->item_fields(),
						array(
							'parent_item_id' => Schema::int( __( 'Optional. item_id of an item in the same menu to nest this one under (a dropdown). Omit or 0 for the top level.', 'mosmcp-abilities' ) ),
							'position'       => Schema::int( __( 'Optional. 1-based place among the items with the same parent: 1 puts it first. Omit to add it last.', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
						)
					),
					array( 'menu' )
				),
				'output_schema' => $this->item_write_output(),
			)
		);
	}

	/**
	 * Defines the mosmcp/menu-update-item ability.
	 *
	 * @return Ability
	 */
	private function menu_update_item() {
		return $this->ability(
			'mosmcp/menu-update-item',
			array(
				'label'         => __( 'Update Menu Item', 'mosmcp-abilities' ),
				'description'   => __( 'Changes one classic menu item: its label, address, what it links to (post_id, term_id or archive_post_type to re-point it), new-tab setting, CSS classes, description, tooltip or rel, and optionally moves it (parent_item_id, position). Only the fields passed change; everything else on the item is kept exactly as it was. A label of "" makes a page link show the page\'s own title again. Passing url on a page link turns it into a custom link, and says so. item_id must be a menu item ID from mosmcp/menu-get, not a page ID. On a block theme, change header links with mosmcp/navigation-update-link instead. Also known as: rename menu item, change menu link, edit menu item, open in new tab, move menu item, make submenu.', 'mosmcp-abilities' ),
				'annotations'   => Site_Support::annotations( false, false, true, false ),
				'execute'       => array( Menus_Provider::class, 'menu_update_item' ),
				'input_schema'  => Schema::object(
					array_merge(
						array( 'item_id' => Schema::int( __( 'The menu item to change: an item_id from mosmcp/menu-get (not the ID of the page it links to).', 'mosmcp-abilities' ) ) ),
						$this->item_fields(),
						array(
							'parent_item_id' => Schema::int( __( 'Optional. Move the item under this item of the same menu, or 0 to move it to the top level.', 'mosmcp-abilities' ) ),
							'position'       => Schema::int( __( 'Optional. 1-based place among the items with the same (new) parent.', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
						)
					),
					array( 'item_id' )
				),
				'output_schema' => $this->item_write_output(
					array( 'changed_fields' => Schema::arr( Schema::str(), __( 'Fields whose stored value actually changed.', 'mosmcp-abilities' ) ) )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/menu-remove-item ability.
	 *
	 * @return Ability
	 */
	private function menu_remove_item() {
		return $this->ability(
			'mosmcp/menu-remove-item',
			array(
				'label'         => __( 'Remove Menu Item', 'mosmcp-abilities' ),
				'description'   => __( 'Removes one item from a classic menu. Its child items are not left orphaned (as WordPress itself leaves them): they move up into its place, in order. Set remove_children to true to remove the whole branch instead, which needs confirm set to true. Classic menus have no revisions, so this cannot be undone automatically; the result lists everything removed with its link and position so it can be added back. The linked page itself is not touched. Also known as: remove from menu, delete menu item, take out of the menu, remove menu link, remove submenu.', 'mosmcp-abilities' ),
				'annotations'   => Site_Support::annotations( false, true, true, false ),
				'execute'       => array( Menus_Provider::class, 'menu_remove_item' ),
				'input_schema'  => Schema::object(
					array(
						'item_id'         => Schema::int( __( 'The menu item to remove: an item_id from mosmcp/menu-get.', 'mosmcp-abilities' ) ),
						'remove_children' => Schema::boolean( __( 'Also remove every item nested under it. Default false: they move up instead.', 'mosmcp-abilities' ), array( 'default' => false ) ),
						'confirm'         => Schema::boolean( __( 'Must be true when more than one item would be removed.', 'mosmcp-abilities' ), array( 'default' => false ) ),
					),
					array( 'item_id' )
				),
				'output_schema' => Schema::object(
					array(
						'menu'                 => Menus_Support::menu_schema(),
						'removed'              => Schema::arr( Menus_Support::item_schema(), __( 'Items removed, as they were before.', 'mosmcp-abilities' ) ),
						'children_moved'       => Schema::arr( $this->outline_row(), __( 'Child items that moved up into the removed item\'s place.', 'mosmcp-abilities' ) ),
						'remaining_item_count' => Schema::int(),
						'outline'              => Menus_Support::outline_schema(),
						'warnings'             => Schema::warnings(),
						'notes'                => Schema::arr( Schema::str() ),
					),
					array( 'menu', 'removed' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/menu-reorder-items ability.
	 *
	 * @return Ability
	 */
	private function menu_reorder_items() {
		return $this->ability(
			'mosmcp/menu-reorder-items',
			array(
				'label'         => __( 'Reorder Menu Items', 'mosmcp-abilities' ),
				'description'   => __( 'Changes the order, and optionally the nesting, of a classic menu\'s items in one step. Either pass order (item IDs in the order they should appear at one level, with parent_item_id naming that level, 0 for the top), or items (a list of {item_id, parent_item_id} in display order, to re-nest several items at once). Listed items go first in the order given; unlisted ones keep their place after them. Everything is checked before anything is written: IDs from another menu, repeated IDs and loops (an item inside its own children) are refused with nothing changed. Positions are then stored the way the Menus screen stores them. Also known as: reorder menu, move menu item up, change menu order, sort menu, rearrange navigation, nest menu items.', 'mosmcp-abilities' ),
				'annotations'   => Site_Support::annotations( false, false, true, false ),
				'execute'       => array( Menus_Provider::class, 'menu_reorder_items' ),
				'input_schema'  => Schema::object(
					array(
						'menu'           => Menus_Support::menu_ref_input(),
						'order'          => Schema::arr( Schema::int(), __( 'Item IDs in the order they should appear at one level. Items not currently at that level move there.', 'mosmcp-abilities' ) ),
						'parent_item_id' => Schema::int( __( 'With order: the item whose children are being ordered, or 0 (default) for the top level.', 'mosmcp-abilities' ) ),
						'items'          => Schema::arr(
							Schema::object(
								array(
									'item_id'        => Schema::int( __( 'A menu item ID from mosmcp/menu-get.', 'mosmcp-abilities' ) ),
									'parent_item_id' => Schema::int( __( 'The item it should sit under, or 0 for the top level. Omit to keep its current parent.', 'mosmcp-abilities' ) ),
								),
								array( 'item_id' )
							),
							__( 'Alternative to order: items in the order they should appear, each with the parent it should have.', 'mosmcp-abilities' )
						),
					),
					array( 'menu' )
				),
				'output_schema' => Schema::object(
					array(
						'menu'          => Menus_Support::menu_schema(),
						'changed_count' => Schema::int( __( 'Items whose stored order or parent was written.', 'mosmcp-abilities' ) ),
						'moved'         => Schema::arr( $this->outline_row(), __( 'Items whose position or parent changed.', 'mosmcp-abilities' ) ),
						'outline'       => Menus_Support::outline_schema(),
						'warnings'      => Schema::warnings(),
						'notes'         => Schema::arr( Schema::str() ),
					),
					array( 'menu', 'changed_count', 'outline' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/menu-assign-location ability.
	 *
	 * @return Ability
	 */
	private function menu_assign_location() {
		return $this->ability(
			'mosmcp/menu-assign-location',
			array(
				'label'         => __( 'Assign Menu to Location', 'mosmcp-abilities' ),
				'description'   => __( 'Shows a classic menu in one of the theme\'s menu locations (for example the header or footer), or clears the location when menu is 0 or empty. The menu that was there before is kept, just no longer shown there. An unknown location is refused with the list of locations the theme offers; a block theme has none and is pointed at mosmcp/navigation-list instead. Also known as: set menu location, display menu, show menu in header, primary menu, footer menu, change which menu shows.', 'mosmcp-abilities' ),
				'annotations'   => Site_Support::annotations( false, false, true, false ),
				'execute'       => array( Menus_Provider::class, 'menu_assign_location' ),
				'input_schema'  => Schema::object(
					array(
						'location' => Schema::str( __( 'The theme location, by slug or label as mosmcp/menu-list reports them, for example "primary".', 'mosmcp-abilities' ) ),
						'menu'     => array(
							'type'        => array( 'integer', 'string', 'null' ),
							'description' => __( 'The menu to show there (ID, slug or name). 0, null or "" clears the location.', 'mosmcp-abilities' ),
						),
					),
					array( 'location' )
				),
				'output_schema' => Schema::object(
					array(
						'location'           => Schema::str(),
						'location_label'     => Schema::str(),
						'menu_id'            => Schema::int( __( 'Menu now shown there, 0 when cleared.', 'mosmcp-abilities' ) ),
						'menu_name'          => Schema::str(),
						'previous_menu_id'   => Schema::int(),
						'previous_menu_name' => Schema::str(),
						'changed'            => Schema::boolean(),
						'notes'              => Schema::arr( Schema::str() ),
					),
					array( 'location', 'menu_id', 'changed' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/navigation-list ability.
	 *
	 * @return Ability
	 */
	private function navigation_list() {
		$usage = Menus_Support::nav_usage_schema();

		return $this->ability(
			'mosmcp/navigation-list',
			array(
				'label'         => __( 'List Navigation Menus', 'mosmcp-abilities' ),
				'description'   => __( 'Lists the Navigation menus a block theme uses (the menus shown by Navigation blocks in the header, footer and templates) and where each one is actually displayed, found by reading the active theme\'s templates and template parts. It also reports Navigation blocks that name no menu: those show the newest published menu or a list of pages, so editing the right menu matters. Read-only. Use this instead of mosmcp/menu-list on block themes such as Twenty Twenty-Five, then mosmcp/navigation-get to see the links. Also known as: navigation, site navigation, header navigation, header menu, footer links, navigation block, block theme menu, wp_navigation.', 'mosmcp-abilities' ),
				'annotations'   => Site_Support::annotations( true, false, true, false ),
				'execute'       => array( Navigation_Provider::class, 'navigation_list' ),
				'input_schema'  => Schema::object( array() ),
				'output_schema' => Schema::object(
					array(
						'is_block_theme' => Schema::boolean(),
						'navigations'    => Schema::arr(
							Schema::object(
								array(
									'navigation_id'   => Schema::int(),
									'title'           => Schema::str(),
									'status'          => Schema::str( __( 'Only "publish" menus are shown to visitors.', 'mosmcp-abilities' ) ),
									'modified'        => Schema::str(),
									'link_count'      => Schema::int(),
									'top_level_count' => Schema::int(),
									'is_fallback'     => Schema::boolean( __( 'True for the newest published menu, which Navigation blocks that name no menu display.', 'mosmcp-abilities' ) ),
									'used_in'         => Schema::arr( $usage, __( 'Where the theme displays this menu. Empty means nowhere: editing it changes nothing visitors see.', 'mosmcp-abilities' ) ),
								)
							)
						),
						'unbound_blocks' => Schema::arr(
							Schema::object(
								array(
									'where'             => Schema::str(),
									'slug'              => Schema::str(),
									'title'             => Schema::str(),
									'area'              => Schema::str(),
									'used_by_templates' => Schema::arr( Schema::str() ),
									'occurrence'        => Schema::int( __( 'Which Navigation block of that template part this is (1 for the first), when it has several.', 'mosmcp-abilities' ) ),
									'shows'             => Schema::str( __( '"fallback" (the newest published menu, or a page list when there is none) or "inline_links" (links stored inside the template itself).', 'mosmcp-abilities' ) ),
									'inline_link_count' => Schema::int(),
									'fallback_navigation_id' => Schema::int( __( 'Menu shown as the fallback, 0 when a page list is shown instead.', 'mosmcp-abilities' ) ),
								)
							),
							__( 'Navigation blocks in the theme that do not name a menu.', 'mosmcp-abilities' )
						),
						'warnings'       => Schema::warnings(),
						'notes'          => Schema::arr( Schema::str() ),
					),
					array( 'is_block_theme', 'navigations' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/navigation-get ability.
	 *
	 * @return Ability
	 */
	private function navigation_get() {
		return $this->ability(
			'mosmcp/navigation-get',
			array(
				'label'         => __( 'Get Navigation Menu', 'mosmcp-abilities' ),
				'description'   => __( 'Returns every entry of one block-theme Navigation menu in display order, each with a path ("0", "2.1") that the navigation edit abilities take, its label, where it links and what kind of entry it is. Automatic page lists, home links, search boxes and social icons are described in plain words. Read-only. Flags links to deleted, draft or trashed pages, links still pointing at a page\'s old address, and links with no label. Also returns the modified value to pass as expected_modified to the next edit. Also known as: show navigation, navigation links, header links, what is in the navigation, navigation block contents.', 'mosmcp-abilities' ),
				'annotations'   => Site_Support::annotations( true, false, true, false ),
				'execute'       => array( Navigation_Provider::class, 'navigation_get' ),
				'input_schema'  => Schema::object(
					array( 'navigation' => $this->navigation_ref() ),
					array( 'navigation' )
				),
				'output_schema' => Schema::object(
					array(
						'navigation' => Menus_Support::nav_summary_schema(),
						'blocks'     => Schema::arr( Menus_Support::nav_block_schema(), __( 'Every entry, top to bottom, children right after their parent.', 'mosmcp-abilities' ) ),
						'warnings'   => Schema::warnings(),
						'notes'      => Schema::arr( Schema::str() ),
					),
					array( 'navigation', 'blocks' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/navigation-add-link ability.
	 *
	 * @return Ability
	 */
	private function navigation_add_link() {
		return $this->ability(
			'mosmcp/navigation-add-link',
			array(
				'label'         => __( 'Add Navigation Link', 'mosmcp-abilities' ),
				'description'   => __( 'Adds a link to a block-theme Navigation menu: to a page or post (post_id), a category or tag (term_id), or any other address (url plus label). It can go inside a dropdown (parent_path); pointing parent_path at a plain link turns that link into a dropdown. index places it among its siblings (counted from 0). The change is live, verified by reading it back, and saved as a revision so mosmcp/navigation-restore-revision can undo it. Pass expected_modified from the last read to avoid overwriting someone else\'s edit. Also known as: add to navigation, add link to header, add menu item, add page to navigation, navigation link, add dropdown item.', 'mosmcp-abilities' ),
				'annotations'   => Site_Support::annotations( false, false, false, false ),
				'execute'       => array( Navigation_Provider::class, 'navigation_add_link' ),
				'input_schema'  => Schema::object(
					array(
						'navigation'        => $this->navigation_ref(),
						'post_id'           => Schema::int( __( 'Link to this page or post, by ID.', 'mosmcp-abilities' ) ),
						'term_id'           => Schema::int( __( 'Link to this category or tag, by ID.', 'mosmcp-abilities' ) ),
						'url'               => Schema::str( __( 'Link to any other address: https://..., a path such as "/contact", an anchor such as "#pricing", mailto: or tel:.', 'mosmcp-abilities' ) ),
						'label'             => Schema::str( __( 'Text shown. Required for url links; page and category links default to their title. Plain text.', 'mosmcp-abilities' ) ),
						'parent_path'       => Schema::str( __( 'Optional. Path of the dropdown (or plain link, which becomes a dropdown) to add the link inside. Omit for the top level.', 'mosmcp-abilities' ) ),
						'index'             => Schema::int( __( 'Optional. 0-based place among its siblings, matching the last number of the paths: 0 puts it first. Omit to add it last.', 'mosmcp-abilities' ), array( 'minimum' => 0 ) ),
						'open_in_new_tab'   => Schema::boolean(),
						'description'       => Schema::str(),
						'title_attribute'   => Schema::str( __( 'Tooltip text shown when hovering the link.', 'mosmcp-abilities' ) ),
						'rel'               => Schema::str( __( 'Link relationship, for example "nofollow".', 'mosmcp-abilities' ) ),
						'expected_modified' => $this->expected_modified(),
					),
					array( 'navigation' )
				),
				'output_schema' => $this->navigation_write_output(),
			)
		);
	}

	/**
	 * Defines the mosmcp/navigation-update-link ability.
	 *
	 * @return Ability
	 */
	private function navigation_update_link() {
		return $this->ability(
			'mosmcp/navigation-update-link',
			array(
				'label'         => __( 'Update Navigation Link', 'mosmcp-abilities' ),
				'description'   => __( 'Changes one link or dropdown in a block-theme Navigation menu, addressed by its path from mosmcp/navigation-get: its label, address, the page or category it points at (post_id, term_id), new-tab setting, tooltip, description or rel. Only the fields passed change, and a page link\'s stored address is refreshed if the page has moved. Pointed at an automatic page list with convert_page_list true, it replaces the list with one ordinary link per page, so single pages can then be removed or reordered. Live, verified, saved as a revision (undo with mosmcp/navigation-restore-revision). Also known as: rename navigation link, rename menu item, change link, edit header link, change menu label, change menu link, open in new tab, fix navigation link.', 'mosmcp-abilities' ),
				'annotations'   => Site_Support::annotations( false, false, true, false ),
				'execute'       => array( Navigation_Provider::class, 'navigation_update_link' ),
				'input_schema'  => Schema::object(
					array(
						'navigation'        => $this->navigation_ref(),
						'path'              => Schema::str( __( 'Path of the entry to change, exactly as mosmcp/navigation-get reports it, for example "2" or "1.0".', 'mosmcp-abilities' ) ),
						'label'             => Schema::str( __( 'New text shown. Plain text.', 'mosmcp-abilities' ) ),
						'url'               => Schema::str( __( 'New address. On a page or category link this turns it into a plain address link.', 'mosmcp-abilities' ) ),
						'post_id'           => Schema::int( __( 'Point the link at this page or post instead.', 'mosmcp-abilities' ) ),
						'term_id'           => Schema::int( __( 'Point the link at this category or tag instead.', 'mosmcp-abilities' ) ),
						'open_in_new_tab'   => Schema::boolean(),
						'description'       => Schema::str(),
						'title_attribute'   => Schema::str(),
						'rel'               => Schema::str(),
						'convert_page_list' => Schema::boolean( __( 'Only for a path that is an automatic page list: replace it with one editable link per published page.', 'mosmcp-abilities' ), array( 'default' => false ) ),
						'expected_modified' => $this->expected_modified(),
					),
					array( 'navigation', 'path' )
				),
				'output_schema' => $this->navigation_write_output(),
			)
		);
	}

	/**
	 * Defines the mosmcp/navigation-remove-link ability.
	 *
	 * @return Ability
	 */
	private function navigation_remove_link() {
		return $this->ability(
			'mosmcp/navigation-remove-link',
			array(
				'label'         => __( 'Remove Navigation Link', 'mosmcp-abilities' ),
				'description'   => __( 'Removes one entry from a block-theme Navigation menu, addressed by its path from mosmcp/navigation-get: a link, a dropdown, or another block such as a search box or page list. Removing a dropdown removes the links inside it too, unless keep_children is true, in which case they move up into its place. A dropdown left empty becomes a plain link again. The change is saved as a revision, so mosmcp/navigation-restore-revision can undo it; the result also lists exactly what was removed. Paths shift afterwards, so read the menu again before the next edit. Also known as: remove from navigation, delete navigation link, remove header link, remove menu item, remove dropdown.', 'mosmcp-abilities' ),
				'annotations'   => Site_Support::annotations( false, true, false, false ),
				'execute'       => array( Navigation_Provider::class, 'navigation_remove_link' ),
				'input_schema'  => Schema::object(
					array(
						'navigation'        => $this->navigation_ref(),
						'path'              => Schema::str( __( 'Path of the entry to remove, exactly as mosmcp/navigation-get reports it.', 'mosmcp-abilities' ) ),
						'keep_children'     => Schema::boolean( __( 'For a dropdown: move its links up into its place instead of removing them.', 'mosmcp-abilities' ), array( 'default' => false ) ),
						'expected_modified' => $this->expected_modified(),
					),
					array( 'navigation', 'path' )
				),
				'output_schema' => $this->navigation_write_output(),
			)
		);
	}

	/**
	 * Defines the mosmcp/navigation-restore-revision ability.
	 *
	 * @return Ability
	 */
	private function navigation_restore_revision() {
		return $this->ability(
			'mosmcp/navigation-restore-revision',
			array(
				'label'         => __( 'Undo Navigation Change', 'mosmcp-abilities' ),
				'description'   => __( 'Puts a block-theme Navigation menu back to an earlier saved version. Without revision_id it undoes the most recent change (it restores the newest revision that differs from the current menu); with revision_id (for example previous_revision_id from an edit\'s result) it restores that version. The current version is kept as a revision first, so a restore can itself be undone. Also known as: undo navigation change, revert navigation, restore menu, put the menu back, undo last menu edit.', 'mosmcp-abilities' ),
				'annotations'   => Site_Support::annotations( false, false, true, false ),
				'execute'       => array( Navigation_Provider::class, 'navigation_restore_revision' ),
				'input_schema'  => Schema::object(
					array(
						'navigation'        => $this->navigation_ref(),
						'revision_id'       => Schema::int( __( 'Optional. The revision to restore. Omit to undo the most recent change.', 'mosmcp-abilities' ) ),
						'expected_modified' => $this->expected_modified(),
					),
					array( 'navigation' )
				),
				'output_schema' => Schema::object(
					array(
						'navigation'           => Menus_Support::nav_summary_schema(),
						'restored_revision_id' => Schema::int(),
						'restored_from'        => Schema::str( __( 'When the restored version was saved.', 'mosmcp-abilities' ) ),
						'previous_revision_id' => Schema::int( __( 'Revision holding the menu as it was just before this restore.', 'mosmcp-abilities' ) ),
						'revision_id'          => Schema::int(),
						'blocks'               => Schema::arr( Menus_Support::nav_block_schema() ),
						'warnings'             => Schema::warnings(),
						'notes'                => Schema::arr( Schema::str() ),
					),
					array( 'navigation', 'restored_revision_id' )
				),
			)
		);
	}
}
