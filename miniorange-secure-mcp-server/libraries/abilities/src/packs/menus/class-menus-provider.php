<?php
/**
 * Execute callbacks for the classic-menu abilities of the Menus & Navigation pack.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Menus;

use MoSMCP\Abilities\Packs\Site\Site_Support;
use WP_Error;
use WP_Term;

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
 * Class Menus_Provider
 *
 * Static execute callbacks for the classic (nav_menu) menu abilities.
 */
class Menus_Provider {

	/**
	 * Input keys that change an item's fields (as opposed to its place).
	 *
	 * @var string[]
	 */
	const FIELD_KEYS = array( 'label', 'url', 'post_id', 'term_id', 'archive_post_type', 'open_in_new_tab', 'css_classes', 'description', 'title_attribute', 'link_rel' );

	/**
	 * Lists every menu and every theme location.
	 *
	 * @param array<string, mixed> $input Ability input. Unused.
	 * @return array<string, mixed>
	 */
	public static function menu_list( $input = array() ) {
		unset( $input );

		$registered = Menus_Support::registered_locations();
		$assigned   = get_nav_menu_locations();
		$is_block   = function_exists( 'wp_is_block_theme' ) && wp_is_block_theme();
		$warnings   = array();
		$notes      = array();
		$menus      = array();

		foreach ( (array) wp_get_nav_menus() as $menu ) {
			if ( $menu instanceof WP_Term ) {
				$menus[] = Menus_Support::describe_menu( $menu );
			}
		}

		$locations = array();
		$empty     = array();

		foreach ( $registered as $slug => $label ) {
			$menu_id = isset( $assigned[ $slug ] ) ? (int) $assigned[ $slug ] : 0;
			$menu    = $menu_id > 0 ? wp_get_nav_menu_object( $menu_id ) : false;

			if ( $menu_id > 0 && ! $menu ) {
				$warnings[] = Menus_Support::warning(
					'location_menu_missing',
					sprintf(
						/* translators: 1: location label, 2: menu ID. */
						__( 'The location "%1$s" is still set to menu %2$d, which no longer exists, so nothing shows there. Assign a menu with mosmcp/menu-assign-location.', 'mosmcp-abilities' ),
						$label,
						$menu_id
					),
					'location ' . $slug
				);
			}

			if ( ! $menu ) {
				$empty[] = $label;
			}

			$locations[] = array(
				'location'  => (string) $slug,
				'label'     => (string) $label,
				'menu_id'   => $menu ? (int) $menu->term_id : 0,
				'menu_name' => $menu ? Menus_Support::name_of( $menu ) : '',
			);
		}

		if ( $is_block && empty( $registered ) ) {
			$notes[] = __( 'The active theme is a block theme with no classic menu locations. Its navigation is built from Navigation blocks, so classic menus are not displayed anywhere unless someone imports one into a Navigation block. To change what visitors see in the header or footer, use mosmcp/navigation-list.', 'mosmcp-abilities' );
		} elseif ( $is_block ) {
			$notes[] = __( 'The active theme is a block theme that also registers classic locations. Most of its navigation is likely built from Navigation blocks; use mosmcp/navigation-list to see those too.', 'mosmcp-abilities' );
		} elseif ( empty( $registered ) ) {
			$notes[] = __( 'The active theme registers no menu locations, so a menu only shows where a Navigation Menu widget or block displays it.', 'mosmcp-abilities' );
		}

		if ( empty( $menus ) ) {
			$notes[] = __( 'There are no classic menus yet. mosmcp/menu-create makes one.', 'mosmcp-abilities' );
		}

		if ( ! empty( $empty ) && ! empty( $menus ) ) {
			$notes[] = sprintf(
				/* translators: %s: comma-separated list of location labels. */
				__( 'These locations have no menu, so the theme shows its fallback (often a plain list of pages) or nothing: %s. Use mosmcp/menu-assign-location to fill one.', 'mosmcp-abilities' ),
				implode( ', ', $empty )
			);
		}

		return array(
			'is_block_theme'       => (bool) $is_block,
			'theme_supports_menus' => current_theme_supports( 'menus' ) || ! empty( $registered ),
			'menus'                => $menus,
			'locations'            => $locations,
			'navigation_count'     => Navigation_Provider::count_navigations(),
			'warnings'             => $warnings,
			'notes'                => $notes,
		);
	}

	/**
	 * Returns one menu with its items as a nested tree.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function menu_get( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$menu  = Menus_Support::resolve_menu( isset( $input['menu'] ) ? $input['menu'] : '' );

		if ( is_wp_error( $menu ) ) {
			return $menu;
		}

		$items     = Menus_Support::load_items( $menu );
		$warnings  = array();
		$structure = Menus_Support::structure( $items, $warnings );
		$tree      = Menus_Support::tree( $items, $structure, $warnings );
		$max_depth = 0;

		foreach ( Menus_Support::flatten( $structure ) as $row ) {
			$max_depth = max( $max_depth, (int) $row['depth'] );
		}

		$notes = array();

		if ( empty( $items ) ) {
			$notes[] = __( 'This menu has no items. Add some with mosmcp/menu-add-item.', 'mosmcp-abilities' );
		} else {
			$notes[] = __( 'item_id is the handle the other menu abilities take. position is counted from 1 among items with the same parent.', 'mosmcp-abilities' );
		}

		if ( empty( Menus_Support::locations_of( (int) $menu->term_id ) ) ) {
			$notes[] = ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() )
				? __( 'This menu is not assigned to any location, and the active block theme does not display classic menus. Visitors only see it if it is imported into a Navigation block.', 'mosmcp-abilities' )
				: __( 'This menu is not assigned to any theme location, so the theme does not display it. Use mosmcp/menu-assign-location to show it.', 'mosmcp-abilities' );
		}

		return array(
			'menu'       => Menus_Support::describe_menu( $menu ),
			'item_count' => count( $items ),
			'max_depth'  => $max_depth,
			'items'      => $tree,
			'warnings'   => $warnings,
			'notes'      => $notes,
		);
	}

	/**
	 * Creates a menu, optionally assigning it to a location.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function menu_create( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$name  = isset( $input['name'] ) ? trim( sanitize_text_field( (string) $input['name'] ) ) : '';

		if ( '' === $name ) {
			return Site_Support::error(
				'mosmcp_menu_name_required',
				__( 'The menu needs a name, for example "Main Menu".', 'mosmcp-abilities' ),
				Site_Support::CAUSE_INVALID_INPUT,
				true
			);
		}

		$location = '';
		if ( isset( $input['location'] ) && '' !== trim( (string) $input['location'] ) ) {
			$location = self::require_location( (string) $input['location'] );
			if ( is_wp_error( $location ) ) {
				return $location;
			}
		}

		$existing = get_term_by( 'name', $name, 'nav_menu' );
		if ( $existing instanceof WP_Term ) {
			return Site_Support::error(
				'mosmcp_menu_exists',
				sprintf(
					/* translators: 1: menu name, 2: existing menu ID. */
					__( 'A menu called "%1$s" already exists (ID %2$d), and menu names must be unique. Add items to that menu with mosmcp/menu-add-item, or choose a different name.', 'mosmcp-abilities' ),
					Menus_Support::name_of( $existing ),
					(int) $existing->term_id
				),
				Site_Support::CAUSE_CONFLICT,
				false,
				array(
					'menu_id'     => (int) $existing->term_id,
					'use_instead' => 'mosmcp/menu-get',
				)
			);
		}

		$description = isset( $input['description'] ) ? sanitize_textarea_field( (string) $input['description'] ) : '';

		Menus_Support::prepare_environment();

		// wp_update_nav_menu_object() expects slashed data, as it would arrive from a form.
		$menu_id = wp_update_nav_menu_object(
			0,
			wp_slash(
				array(
					'menu-name'   => $name,
					'description' => $description,
				)
			)
		);

		if ( is_wp_error( $menu_id ) ) {
			return Site_Support::error(
				'mosmcp_menu_create_failed',
				wp_strip_all_tags( $menu_id->get_error_message() ),
				'menu_exists' === $menu_id->get_error_code() ? Site_Support::CAUSE_CONFLICT : Site_Support::CAUSE_WP_CORE,
				false
			);
		}

		$menu_id = (int) $menu_id;

		if ( Site_Support::bool_input( $input, 'auto_add_pages', false ) ) {
			Menus_Support::set_auto_add_pages( $menu_id, true );
		}

		$previous = array(
			'menu_id' => 0,
			'name'    => '',
		);

		$warnings = array();

		if ( '' !== $location ) {
			$previous = self::assign( $location, $menu_id );
			$stored   = get_nav_menu_locations();

			// Read back, as menu-assign-location does: a filtered theme setting can refuse it.
			if ( ( isset( $stored[ $location ] ) ? (int) $stored[ $location ] : 0 ) !== $menu_id ) {
				$warnings[] = Menus_Support::warning(
					'location_not_saved',
					sprintf(
						/* translators: %s: location slug. */
						__( 'The menu was created, but assigning it to the location "%s" did not stick; another plugin may be filtering theme settings. It is not shown there. Try mosmcp/menu-assign-location, or set it in Appearance > Menus > Manage Locations.', 'mosmcp-abilities' ),
						$location
					),
					'location ' . $location
				);
				$location = '';
				$previous = array(
					'menu_id' => 0,
					'name'    => '',
				);
			}
		}

		$menu  = wp_get_nav_menu_object( $menu_id );
		$notes = array( __( 'The menu is empty. Add items with mosmcp/menu-add-item.', 'mosmcp-abilities' ) );

		if ( ! $menu instanceof WP_Term ) {
			return Site_Support::error(
				'mosmcp_menu_create_unverified',
				__( 'WordPress reported the menu as created, but it cannot be read back. Check Appearance > Menus before trying again.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_WP_CORE,
				false
			);
		}

		if ( Menus_Support::name_of( $menu ) !== $name ) {
			$warnings[] = Menus_Support::warning(
				'name_changed_on_save',
				sprintf(
					/* translators: 1: requested name, 2: stored name. */
					__( 'The menu was saved as "%2$s" rather than "%1$s"; WordPress or a plugin altered the name.', 'mosmcp-abilities' ),
					$name,
					Menus_Support::name_of( $menu )
				)
			);
		}

		if ( '' === $location ) {
			$notes[] = ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() && empty( Menus_Support::registered_locations() ) )
				? __( 'The active theme is a block theme and does not display classic menus. To change the site navigation visitors see, use mosmcp/navigation-list and the navigation abilities instead.', 'mosmcp-abilities' )
				: __( 'The menu is not shown anywhere until it is assigned to a theme location with mosmcp/menu-assign-location.', 'mosmcp-abilities' );
		} elseif ( $previous['menu_id'] > 0 && $previous['menu_id'] !== $menu_id ) {
			$notes[] = sprintf(
				/* translators: 1: location slug, 2: previous menu name. */
				__( 'The location "%1$s" previously showed "%2$s"; that menu still exists, it is just no longer shown there.', 'mosmcp-abilities' ),
				$location,
				$previous['name']
			);
		}

		return array(
			'menu'               => Menus_Support::describe_menu( $menu ),
			'location'           => $location,
			'replaced_menu_id'   => (int) $previous['menu_id'],
			'replaced_menu_name' => (string) $previous['name'],
			'warnings'           => $warnings,
			'notes'              => $notes,
		);
	}

	/**
	 * Renames a menu without changing its slug.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function menu_rename( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$menu  = Menus_Support::resolve_menu( isset( $input['menu'] ) ? $input['menu'] : '' );

		if ( is_wp_error( $menu ) ) {
			return $menu;
		}

		$name = isset( $input['name'] ) ? trim( sanitize_text_field( (string) $input['name'] ) ) : '';

		if ( '' === $name ) {
			return Site_Support::error(
				'mosmcp_menu_name_required',
				__( 'Pass the new name for the menu.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_INVALID_INPUT,
				true
			);
		}

		$clash = get_term_by( 'name', $name, 'nav_menu' );
		if ( $clash instanceof WP_Term && (int) $clash->term_id !== (int) $menu->term_id ) {
			return Site_Support::error(
				'mosmcp_menu_exists',
				sprintf(
					/* translators: 1: menu name, 2: existing menu ID. */
					__( 'Another menu is already called "%1$s" (ID %2$d), and menu names must be unique. Choose a different name.', 'mosmcp-abilities' ),
					Menus_Support::name_of( $clash ),
					(int) $clash->term_id
				),
				Site_Support::CAUSE_CONFLICT,
				false,
				array( 'menu_id' => (int) $clash->term_id )
			);
		}

		$old_name    = Menus_Support::name_of( $menu );
		$old_slug    = (string) $menu->slug;
		$old_desc    = (string) $menu->description;
		$description = array_key_exists( 'description', $input ) ? sanitize_textarea_field( (string) $input['description'] ) : $old_desc;
		$keep_slug   = Site_Support::bool_input( $input, 'keep_slug', true );

		if ( $name === $old_name && $description === $old_desc ) {
			return array(
				'menu'          => Menus_Support::describe_menu( $menu ),
				'previous_name' => $old_name,
				'changed'       => false,
				'slug_changed'  => false,
				'warnings'      => array(),
				'notes'         => array( __( 'The menu already has that name and description, so nothing was changed.', 'mosmcp-abilities' ) ),
			);
		}

		Menus_Support::prepare_environment();

		/*
		 * The description is passed back explicitly: wp_update_nav_menu_object()
		 * blanks any field it is not given, so a plain rename would silently wipe
		 * the menu's description.
		 */
		$result = wp_update_nav_menu_object(
			(int) $menu->term_id,
			wp_slash(
				array(
					'menu-name'   => $name,
					'description' => $description,
				)
			)
		);

		if ( is_wp_error( $result ) ) {
			return Site_Support::error(
				'mosmcp_menu_rename_failed',
				wp_strip_all_tags( $result->get_error_message() ),
				'menu_exists' === $result->get_error_code() ? Site_Support::CAUSE_CONFLICT : Site_Support::CAUSE_WP_CORE,
				false
			);
		}

		$notes = array();
		$fresh = get_term( (int) $menu->term_id, 'nav_menu' );

		/*
		 * Core also regenerates the slug from the new name. Themes and code that
		 * display a menu by slug (wp_nav_menu( array( 'menu' => 'main-menu' ) ))
		 * would lose it, so the old slug is put back unless asked otherwise.
		 */
		if ( $fresh instanceof WP_Term && $keep_slug && (string) $fresh->slug !== $old_slug ) {
			wp_update_term( (int) $menu->term_id, 'nav_menu', array( 'slug' => $old_slug ) );
			$fresh = get_term( (int) $menu->term_id, 'nav_menu' );
		}

		if ( ! $fresh instanceof WP_Term ) {
			return Site_Support::error(
				'mosmcp_menu_rename_unverified',
				__( 'WordPress reported the rename as done, but the menu cannot be read back. Check Appearance > Menus.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_WP_CORE,
				false
			);
		}

		$slug_changed = (string) $fresh->slug !== $old_slug;

		if ( $slug_changed ) {
			$notes[] = sprintf(
				/* translators: 1: old slug, 2: new slug. */
				__( 'The slug changed from "%1$s" to "%2$s". Anything that displays this menu by its old slug will no longer find it.', 'mosmcp-abilities' ),
				$old_slug,
				(string) $fresh->slug
			);
		} else {
			$notes[] = __( 'The slug was kept, so code or settings that refer to the menu by slug keep working.', 'mosmcp-abilities' );
		}

		Menus_Support::touch_menu( (int) $menu->term_id );

		return array(
			'menu'          => Menus_Support::describe_menu( $fresh ),
			'previous_name' => $old_name,
			'changed'       => true,
			'slug_changed'  => $slug_changed,
			'warnings'      => array(),
			'notes'         => $notes,
		);
	}

	/**
	 * Deletes a menu and all its items.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function menu_delete( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$menu  = Menus_Support::resolve_menu( isset( $input['menu'] ) ? $input['menu'] : '' );

		if ( is_wp_error( $menu ) ) {
			return $menu;
		}

		$menu_id   = (int) $menu->term_id;
		$items     = Menus_Support::load_items( $menu );
		$ignored   = array();
		$structure = Menus_Support::structure( $items, $ignored );
		$locations = Menus_Support::locations_of( $menu_id );
		$widgets   = self::widgets_showing( $menu_id );

		$where = '';
		if ( ! empty( $locations ) ) {
			$where = sprintf(
				/* translators: %s: comma-separated location slugs. */
				__( ', and these theme locations are left with no menu: %s', 'mosmcp-abilities' ),
				implode( ', ', $locations )
			);
		}

		$what = empty( $items )
			? sprintf(
				/* translators: 1: menu name, 2: menu ID, 3: list of emptied locations or empty. */
				__( 'the empty menu "%1$s" (ID %2$d) is deleted permanently. Classic menus have no trash and no revisions%3$s', 'mosmcp-abilities' ),
				Menus_Support::name_of( $menu ),
				$menu_id,
				$where
			)
			: sprintf(
				/* translators: 1: menu name, 2: menu ID, 3: number of items, 4: list of emptied locations or empty. */
				_n( 'the menu "%1$s" (ID %2$d) and its %3$d item are deleted permanently. Classic menus have no trash and no revisions%4$s', 'the menu "%1$s" (ID %2$d) and all %3$d of its items are deleted permanently. Classic menus have no trash and no revisions%4$s', count( $items ), 'mosmcp-abilities' ),
				Menus_Support::name_of( $menu ),
				$menu_id,
				count( $items ),
				$where
			);

		$unconfirmed = Site_Support::require_confirm( $input, $what );

		if ( $unconfirmed instanceof WP_Error ) {
			return $unconfirmed;
		}

		// Recorded before the delete: it is the only way to rebuild the menu later.
		$removed = array();
		foreach ( Menus_Support::flatten( $structure ) as $row ) {
			if ( isset( $items[ $row['id'] ] ) ) {
				$removed[] = Menus_Support::describe_item( $items[ $row['id'] ], $row, Menus_Support::linked_state( $items[ $row['id'] ] ) );
			}
		}

		Menus_Support::prepare_environment();

		$result = wp_delete_nav_menu( $menu_id );

		if ( is_wp_error( $result ) || ! $result || wp_get_nav_menu_object( $menu_id ) ) {
			return Site_Support::error(
				'mosmcp_menu_delete_failed',
				is_wp_error( $result )
					? wp_strip_all_tags( $result->get_error_message() )
					: __( 'WordPress did not delete the menu. Nothing reports why; check Appearance > Menus.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_WP_CORE,
				false
			);
		}

		Menus_Support::set_auto_add_pages( $menu_id, false );

		$notes = array(
			__( 'Only the menu and its entries were deleted. The pages, posts and categories it linked to are untouched.', 'mosmcp-abilities' ),
			__( 'Classic menus have no revisions. removed_items lists every entry in order, so the menu can be rebuilt with mosmcp/menu-create and mosmcp/menu-add-item if the person changes their mind.', 'mosmcp-abilities' ),
		);

		if ( $widgets > 0 ) {
			$notes[] = sprintf(
				/* translators: %d: number of widgets. */
				_n( '%d Navigation Menu widget was showing this menu and is now empty.', '%d Navigation Menu widgets were showing this menu and are now empty.', $widgets, 'mosmcp-abilities' ),
				$widgets
			);
		}

		return array(
			'deleted'           => true,
			'menu_id'           => $menu_id,
			'name'              => Menus_Support::name_of( $menu ),
			'items_removed'     => count( $items ),
			'locations_emptied' => $locations,
			'removed_items'     => $removed,
			'notes'             => $notes,
		);
	}

	/**
	 * Adds an item to a menu.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function menu_add_item( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$menu  = Menus_Support::resolve_menu( isset( $input['menu'] ) ? $input['menu'] : '' );

		if ( is_wp_error( $menu ) ) {
			return $menu;
		}

		$warnings = array();
		$notes    = array();
		$ignored  = array();
		$items    = Menus_Support::load_items( $menu );

		$link = self::link_args( $input, $warnings, true );
		if ( is_wp_error( $link ) ) {
			return $link;
		}

		$args = array_merge(
			$link,
			array(
				'menu-item-status'      => 'publish',
				'menu-item-title'       => '',
				'menu-item-description' => '',
				'menu-item-attr-title'  => '',
				'menu-item-target'      => '',
				'menu-item-classes'     => '',
				'menu-item-xfn'         => '',
				'menu-item-parent-id'   => 0,
			)
		);

		if ( isset( $input['label'] ) && '' !== trim( (string) $input['label'] ) ) {
			$label = Menus_Support::clean_label( (string) $input['label'], $warnings );
			if ( is_wp_error( $label ) ) {
				return $label;
			}
			$args['menu-item-title'] = $label;
		} elseif ( 'custom' === $args['menu-item-type'] ) {
			return Site_Support::error(
				'mosmcp_menu_label_required',
				__( 'A custom link needs a label: the text shown in the menu. Pass label, for example "Contact us".', 'mosmcp-abilities' ),
				Site_Support::CAUSE_INVALID_INPUT,
				true
			);
		}

		$parent = isset( $input['parent_item_id'] ) ? absint( $input['parent_item_id'] ) : 0;
		if ( $parent > 0 && ! isset( $items[ $parent ] ) ) {
			return self::parent_not_in_menu( $parent, $menu );
		}

		$position = isset( $input['position'] ) ? (int) $input['position'] : 0;
		if ( isset( $input['position'] ) && $position < 1 ) {
			return Site_Support::error(
				'mosmcp_menu_position_invalid',
				__( 'position counts from 1 (the first place). Omit it to add the item at the end.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_INVALID_INPUT,
				true
			);
		}

		$args                        = array_merge( $args, self::display_args( $input, $warnings ) );
		$args['menu-item-parent-id'] = $parent;

		self::duplicate_warning( $items, $args, $warnings );

		Menus_Support::prepare_environment();

		// wp_update_nav_menu_item() expects slashed data, as the Menus screen and the core REST endpoint send it.
		$new_id = wp_update_nav_menu_item( (int) $menu->term_id, 0, wp_slash( $args ) );

		if ( is_wp_error( $new_id ) || ! $new_id ) {
			return Site_Support::error(
				'mosmcp_menu_item_create_failed',
				is_wp_error( $new_id ) ? wp_strip_all_tags( $new_id->get_error_message() ) : __( 'WordPress did not create the menu item.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_WP_CORE,
				false
			);
		}

		$new_id = (int) $new_id;

		// Place it: under the parent, at the requested position, then renumber the menu.
		$items     = Menus_Support::load_items( $menu );
		$structure = Menus_Support::structure( $items, $ignored );
		Menus_Support::detach( $structure, $new_id );
		$siblings = isset( $structure['children'][ $parent ] ) ? count( $structure['children'][ $parent ] ) : 0;
		$taken    = Menus_Support::attach( $structure, $new_id, $parent, $position );

		if ( $position > $siblings + 1 ) {
			$notes[] = sprintf(
				/* translators: 1: requested position, 2: actual position. */
				__( 'Position %1$d is past the end of that level, so the item was placed last, at position %2$d.', 'mosmcp-abilities' ),
				$position,
				$taken
			);
		}

		$stored = Menus_Support::persist( $items, $structure );
		Menus_Support::touch_menu( (int) $menu->term_id );

		// The item exists either way; if its place did not stick, say so plainly.
		if ( ! empty( $stored['failed'] ) || ! Menus_Support::structure_stored( $menu, $structure ) ) {
			$warnings[] = Menus_Support::warning(
				'order_not_saved',
				sprintf(
					/* translators: %d: new item ID. */
					__( 'The item was created (ID %d), but the menu order did not read back as requested, so it may not be in the place asked for. Check the outline below and fix it with mosmcp/menu-update-item or mosmcp/menu-reorder-items.', 'mosmcp-abilities' ),
					$new_id
				),
				'item ' . $new_id
			);
		}

		$result = self::item_result( $menu, $new_id, $warnings );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		self::label_check( $args['menu-item-title'], $result['item'], $warnings );
		$result['warnings'] = $warnings;

		if ( empty( Menus_Support::locations_of( (int) $menu->term_id ) ) ) {
			$notes[] = __( 'This menu is not assigned to a theme location, so visitors will not see the new item until it is (mosmcp/menu-assign-location).', 'mosmcp-abilities' );
		}

		$result['notes'] = $notes;

		return $result;
	}

	/**
	 * Updates fields and/or the place of one menu item, leaving every other field as it was.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function menu_update_item( $input = array() ) {
		$input   = is_array( $input ) ? $input : array();
		$context = self::item_context( isset( $input['item_id'] ) ? $input['item_id'] : 0 );

		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$menu    = $context['menu'];
		$items   = $context['items'];
		$item    = $context['item'];
		$item_id = (int) $item->ID;

		$supplied     = array_filter(
			$input,
			static function ( $value ) {
				return null !== $value;
			}
		);
		$wants_fields = array_intersect( self::FIELD_KEYS, array_keys( $supplied ) );
		$wants_parent = array_key_exists( 'parent_item_id', $input ) && null !== $input['parent_item_id'];
		$wants_pos    = array_key_exists( 'position', $input ) && null !== $input['position'];

		if ( empty( $wants_fields ) && ! $wants_parent && ! $wants_pos ) {
			return Site_Support::error(
				'mosmcp_menu_item_nothing_to_update',
				__( 'Nothing to change was passed. Pass at least one of: label, url, post_id, term_id, archive_post_type, open_in_new_tab, css_classes, description, title_attribute, link_rel, parent_item_id, position.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_INVALID_INPUT,
				true
			);
		}

		$warnings  = array();
		$notes     = array();
		$ignored   = array();
		$structure = Menus_Support::structure( $items, $ignored );
		$row       = Menus_Support::row_of( $structure, $item_id );

		/*
		 * wp_update_nav_menu_item() resets every field it is not handed to its
		 * default: an update that sends only a new label would wipe the link, its
		 * target, its classes and its nesting. The item's current stored values are
		 * therefore read first and only the requested fields are replaced.
		 */
		$args     = self::existing_args( $item_id );
		$original = $args;
		$type     = $args['menu-item-type'];

		$relink = self::link_args( $input, $warnings, false );
		if ( is_wp_error( $relink ) ) {
			return $relink;
		}

		$has_label = array_key_exists( 'label', $input ) && null !== $input['label'];

		if ( ! empty( $relink ) ) {
			$was_custom_label = '' !== $args['menu-item-title'];
			$from_custom      = 'custom' === $type;
			$args             = array_merge( $args, $relink );

			if ( 'custom' === $relink['menu-item-type'] && ! $from_custom ) {
				// A linked item turned into a typed address must keep a visible label.
				if ( ! $has_label && ! $was_custom_label ) {
					$kept = Menus_Support::clean_label( Menus_Support::plain( $item->title ), $warnings );
					if ( is_wp_error( $kept ) ) {
						return $kept;
					}
					$args['menu-item-title'] = $kept;
				}
				$warnings[] = Menus_Support::warning(
					'converted_to_custom_link',
					sprintf(
						/* translators: 1: item label, 2: previous link description. */
						__( 'The item "%1$s" linked to %2$s; it is now a custom link to the address given, and it no longer follows that page if its address changes.', 'mosmcp-abilities' ),
						Menus_Support::plain( $item->title ),
						self::describe_link_target( $item )
					),
					'item ' . $item_id
				);
			} elseif ( 'custom' !== $relink['menu-item-type'] && ! $has_label && ( $was_custom_label || $from_custom ) ) {
				$notes[] = sprintf(
					/* translators: %s: item label. */
					__( 'The item kept its own label "%s". Pass label as an empty string to show the newly linked page\'s title instead.', 'mosmcp-abilities' ),
					Menus_Support::plain( $item->title )
				);
			}
		}

		if ( $has_label ) {
			$label = trim( (string) $input['label'] );

			if ( '' === $label ) {
				if ( 'custom' === $args['menu-item-type'] ) {
					return Site_Support::error(
						'mosmcp_menu_label_required',
						__( 'A custom link cannot have an empty label; it would show as a blank space. Pass the text to show instead.', 'mosmcp-abilities' ),
						Site_Support::CAUSE_INVALID_INPUT,
						true
					);
				}
				$args['menu-item-title'] = '';
			} else {
				$label = Menus_Support::clean_label( $label, $warnings );
				if ( is_wp_error( $label ) ) {
					return $label;
				}
				$args['menu-item-title'] = $label;
			}
		}

		$args = array_merge( $args, self::display_args( $input, $warnings ) );

		// Validate the move before writing anything, so a refusal changes nothing.
		$new_parent = $wants_parent ? absint( $input['parent_item_id'] ) : (int) $row['parent'];
		$position   = $wants_pos ? (int) $input['position'] : 0;

		if ( $wants_pos && $position < 1 ) {
			return Site_Support::error(
				'mosmcp_menu_position_invalid',
				__( 'position counts from 1 (the first place among the items with the same parent).', 'mosmcp-abilities' ),
				Site_Support::CAUSE_INVALID_INPUT,
				true
			);
		}

		if ( $wants_parent && $new_parent > 0 ) {
			if ( $new_parent === $item_id ) {
				return Site_Support::error(
					'mosmcp_menu_parent_self',
					__( 'An item cannot be nested under itself. Pass the item_id of a different item, or 0 for the top level.', 'mosmcp-abilities' ),
					Site_Support::CAUSE_INVALID_INPUT,
					true
				);
			}
			if ( ! isset( $items[ $new_parent ] ) ) {
				return self::parent_not_in_menu( $new_parent, $menu );
			}
			if ( in_array( $new_parent, Menus_Support::descendants( $structure, $item_id ), true ) ) {
				return Site_Support::error(
					'mosmcp_menu_parent_cycle',
					sprintf(
						/* translators: 1: item ID, 2: requested parent ID. */
						__( 'Item %2$d is nested inside item %1$d, so item %1$d cannot also go inside it; that would make a loop. Move item %2$d out first, or choose another parent.', 'mosmcp-abilities' ),
						$item_id,
						$new_parent
					),
					Site_Support::CAUSE_INVALID_INPUT,
					true
				);
			}
		}

		$before_snapshot = self::snapshot( $item_id );

		Menus_Support::prepare_environment();

		if ( ! empty( $wants_fields ) ) {
			$saved = self::write_fields( $menu, $item_id, $original, $args );

			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
		}

		if ( $wants_parent || $wants_pos ) {
			$items     = Menus_Support::load_items( $menu );
			$structure = Menus_Support::structure( $items, $ignored );
			Menus_Support::detach( $structure, $item_id );
			$level = isset( $structure['children'][ $new_parent ] ) ? count( $structure['children'][ $new_parent ] ) : 0;
			$taken = Menus_Support::attach( $structure, $item_id, $new_parent, $position );

			if ( $position > $level + 1 ) {
				$notes[] = sprintf(
					/* translators: 1: requested position, 2: actual position. */
					__( 'Position %1$d is past the end of that level, so the item was placed last, at position %2$d.', 'mosmcp-abilities' ),
					$position,
					$taken
				);
			}

			$stored = Menus_Support::persist( $items, $structure );

			if ( ! empty( $stored['failed'] ) || ! Menus_Support::structure_stored( $menu, $structure ) ) {
				Menus_Support::touch_menu( (int) $menu->term_id );
				$error = Menus_Support::structure_error( $menu, $stored['failed'] );
				$error->add_data( array_merge( (array) $error->get_error_data(), array( 'fields_saved' => ! empty( $wants_fields ) ) ) );

				return $error;
			}
		}

		Menus_Support::touch_menu( (int) $menu->term_id );

		$after_snapshot = self::snapshot( $item_id );
		$result         = self::item_result( $menu, $item_id, $warnings );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$changed = array();
		foreach ( $before_snapshot as $field => $value ) {
			if ( in_array( $field, array( 'menu_order', 'status', 'date' ), true ) ) {
				continue;
			}
			if ( $after_snapshot[ $field ] !== $value ) {
				$changed[] = $field;
			}
		}

		if ( (int) $result['item']['parent_item_id'] !== (int) $row['parent'] ) {
			$changed[] = 'parent_item_id';
		}
		if ( (int) $result['item']['position'] !== (int) $row['position'] || in_array( 'parent_item_id', $changed, true ) ) {
			$changed[] = 'position';
		}

		$changed = array_values( array_unique( $changed ) );

		// Anything that changed without being asked for is reported, never hidden.
		$asked = self::asked_fields( $input );
		foreach ( array_diff( $changed, $asked ) as $unexpected ) {
			if ( in_array( $unexpected, array( 'position' ), true ) ) {
				continue;
			}
			$warnings[] = Menus_Support::warning(
				'unexpected_change',
				sprintf(
					/* translators: %s: field name. */
					__( 'The field "%s" changed although it was not part of this request; another plugin may have altered the item while it was saved.', 'mosmcp-abilities' ),
					$unexpected
				),
				'item ' . $item_id
			);
		}

		if ( $before_snapshot['date'] !== $after_snapshot['date'] || $before_snapshot['status'] !== $after_snapshot['status'] ) {
			$warnings[] = Menus_Support::warning(
				'unexpected_change',
				__( 'The item\'s publication date or status changed during the save, which this ability does not ask for; another plugin may have altered it.', 'mosmcp-abilities' ),
				'item ' . $item_id
			);
		}

		if ( $has_label && '' !== trim( (string) $input['label'] ) ) {
			self::label_check( (string) $args['menu-item-title'], $result['item'], $warnings );
		}

		if ( empty( $changed ) ) {
			$notes[] = __( 'The item already had those values, so nothing visible changed.', 'mosmcp-abilities' );
		}

		$result['changed_fields'] = $changed;
		$result['warnings']       = $warnings;
		$result['notes']          = $notes;

		return $result;
	}

	/**
	 * Removes one item (or a whole branch) without orphaning anything.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function menu_remove_item( $input = array() ) {
		$input   = is_array( $input ) ? $input : array();
		$context = self::item_context( isset( $input['item_id'] ) ? $input['item_id'] : 0 );

		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$menu      = $context['menu'];
		$items     = $context['items'];
		$item_id   = (int) $context['item']->ID;
		$warnings  = array();
		$structure = Menus_Support::structure( $items, $warnings );
		$row       = Menus_Support::row_of( $structure, $item_id );
		$branch    = Site_Support::bool_input( $input, 'remove_children', false );
		$children  = isset( $structure['children'][ $item_id ] ) ? array_values( $structure['children'][ $item_id ] ) : array();
		$doomed    = $branch ? array_merge( array( $item_id ), Menus_Support::descendants( $structure, $item_id ) ) : array( $item_id );

		if ( count( $doomed ) > 1 ) {
			$labels = array();
			foreach ( $doomed as $id ) {
				$labels[] = '"' . Menus_Support::plain( $items[ $id ]->title ) . '"';
			}

			$unconfirmed = Site_Support::require_confirm(
				$input,
				sprintf(
					/* translators: 1: number of items, 2: list of item labels. */
					__( '%1$d menu items are removed together (%2$s). Classic menus have no revisions', 'mosmcp-abilities' ),
					count( $doomed ),
					implode( ', ', $labels )
				)
			);

			if ( $unconfirmed instanceof WP_Error ) {
				return $unconfirmed;
			}
		}

		// Recorded before anything changes, so the result can say how to put it back.
		$removed = array();
		foreach ( $doomed as $id ) {
			$removed[] = Menus_Support::describe_item( $items[ $id ], Menus_Support::row_of( $structure, $id ), Menus_Support::linked_state( $items[ $id ] ) );
		}

		$parent = (int) $row['parent'];

		if ( ! $branch && ! empty( $children ) ) {
			/*
			 * Core deletes the item and leaves its children pointing at an ID that
			 * no longer exists. Instead they take the removed item's place, in
			 * order, under its parent, which is what wp-admin does on screen.
			 */
			$siblings = $structure['children'][ $parent ];
			$index    = array_search( $item_id, $siblings, true );
			array_splice( $siblings, false === $index ? count( $siblings ) : (int) $index, 1, $children );
			$structure['children'][ $parent ] = array_values( $siblings );

			foreach ( $children as $child ) {
				$structure['parent'][ $child ] = $parent;
			}
			unset( $structure['children'][ $item_id ] );
		} else {
			Menus_Support::detach( $structure, $item_id );
		}

		foreach ( $doomed as $id ) {
			unset( $structure['parent'][ $id ], $structure['children'][ $id ] );
		}

		$remaining = array_diff_key( $items, array_flip( $doomed ) );

		Menus_Support::prepare_environment();

		// Children are moved first; if that cannot be stored, nothing is deleted.
		$stored = Menus_Support::persist( $remaining, $structure );

		if ( ! empty( $stored['failed'] ) ) {
			Menus_Support::touch_menu( (int) $menu->term_id );

			return Site_Support::error(
				'mosmcp_menu_item_remove_failed',
				sprintf(
					/* translators: %s: comma-separated item IDs. */
					__( 'Nothing was removed: WordPress could not first move these items into their new places, so deleting would have risked orphaning them: %s. Read the menu with mosmcp/menu-get before retrying.', 'mosmcp-abilities' ),
					implode( ', ', $stored['failed'] )
				),
				Site_Support::CAUSE_WP_CORE,
				true,
				array(
					'not_saved'   => $stored['failed'],
					'use_instead' => 'mosmcp/menu-get',
				)
			);
		}

		/*
		 * Deleted deepest first, so every item goes before its parent, and the
		 * first failure stops the run: the failed item's parent and the rest of
		 * the branch stay, so nothing left behind points at a deleted parent.
		 */
		$deleted_ids = array();
		$failed_id   = 0;
		foreach ( array_reverse( $doomed ) as $id ) {
			$deleted = wp_delete_post( $id, true );
			if ( ! $deleted || get_post( $id ) ) {
				$failed_id = (int) $id;
				break;
			}
			$deleted_ids[] = (int) $id;
		}

		if ( $failed_id > 0 ) {
			// Renumber around whatever is left so the menu stays consistent.
			$left      = Menus_Support::load_items( $menu );
			$ignored   = array();
			$left_tree = Menus_Support::structure( $left, $ignored );
			Menus_Support::persist( $left, $left_tree );
			Menus_Support::touch_menu( (int) $menu->term_id );

			$not_removed = array_values( array_diff( array_map( 'intval', $doomed ), $deleted_ids ) );

			return Site_Support::error(
				'mosmcp_menu_item_remove_failed',
				sprintf(
					/* translators: 1: item ID that failed, 2: removed item IDs or "none", 3: item IDs still in the menu. */
					__( 'WordPress did not delete menu item %1$d, so the removal stopped there. Removed: %2$s. Still in the menu: %3$s. Nothing was left orphaned. Read the menu with mosmcp/menu-get before retrying.', 'mosmcp-abilities' ),
					$failed_id,
					empty( $deleted_ids ) ? __( 'none', 'mosmcp-abilities' ) : implode( ', ', $deleted_ids ),
					implode( ', ', $not_removed )
				),
				Site_Support::CAUSE_WP_CORE,
				true,
				array(
					'removed'     => $deleted_ids,
					'not_removed' => $not_removed,
					'use_instead' => 'mosmcp/menu-get',
				)
			);
		}

		Menus_Support::touch_menu( (int) $menu->term_id );

		$ignored     = array();
		$fresh_items = Menus_Support::load_items( $menu );
		$fresh       = Menus_Support::structure( $fresh_items, $ignored );
		$moved       = array();

		if ( ! $branch ) {
			foreach ( $children as $child ) {
				$child_row = Menus_Support::row_of( $fresh, $child );
				$moved[]   = array(
					'item_id'        => (int) $child,
					'label'          => isset( $fresh_items[ $child ] ) ? Menus_Support::plain( $fresh_items[ $child ]->title ) : '',
					'parent_item_id' => (int) $child_row['parent'],
					'position'       => (int) $child_row['position'],
					'depth'          => (int) $child_row['depth'],
				);
			}
		}

		$notes = array(
			__( 'Only the menu entry was removed; the page, post or category it linked to is untouched.', 'mosmcp-abilities' ),
			__( 'Classic menus have no revisions. removed lists everything that was taken out, with its link and position, so it can be put back with mosmcp/menu-add-item if needed.', 'mosmcp-abilities' ),
		);

		if ( ! empty( $moved ) ) {
			$notes[] = sprintf(
				/* translators: %d: number of child items. */
				_n( '%d child item moved up into the removed item\'s place instead of being left orphaned.', '%d child items moved up into the removed item\'s place instead of being left orphaned.', count( $moved ), 'mosmcp-abilities' ),
				count( $moved )
			);
		}

		return array(
			'menu'                 => Menus_Support::describe_menu( self::fresh_menu( $menu ) ),
			'removed'              => $removed,
			'children_moved'       => $moved,
			'remaining_item_count' => count( $fresh_items ),
			'outline'              => Menus_Support::outline( $fresh_items, $fresh ),
			'warnings'             => $warnings,
			'notes'                => $notes,
		);
	}

	/**
	 * Reorders (and optionally re-nests) the items of a menu.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function menu_reorder_items( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$menu  = Menus_Support::resolve_menu( isset( $input['menu'] ) ? $input['menu'] : '' );

		if ( is_wp_error( $menu ) ) {
			return $menu;
		}

		$has_order = isset( $input['order'] ) && is_array( $input['order'] ) && ! empty( $input['order'] );
		$has_items = isset( $input['items'] ) && is_array( $input['items'] ) && ! empty( $input['items'] );

		if ( $has_order === $has_items ) {
			return Site_Support::error(
				'mosmcp_menu_reorder_mode',
				__( 'Pass exactly one of: order (a list of item IDs for one level, with parent_item_id naming that level, 0 for the top), or items (a list of {item_id, parent_item_id} in the order they should appear).', 'mosmcp-abilities' ),
				Site_Support::CAUSE_INVALID_INPUT,
				true
			);
		}

		$items     = Menus_Support::load_items( $menu );
		$warnings  = array();
		$structure = Menus_Support::structure( $items, $warnings );
		$original  = $structure;

		// Desired parent for every listed item, in list order.
		$wanted = array();

		if ( $has_order ) {
			$level = isset( $input['parent_item_id'] ) ? absint( $input['parent_item_id'] ) : 0;

			if ( $level > 0 && ! isset( $items[ $level ] ) ) {
				return self::parent_not_in_menu( $level, $menu );
			}

			foreach ( $input['order'] as $raw ) {
				$wanted[] = array(
					'id'     => is_numeric( $raw ) ? (int) $raw : 0,
					'parent' => $level,
				);
			}
		} else {
			foreach ( $input['items'] as $entry ) {
				if ( ! is_array( $entry ) || ! isset( $entry['item_id'] ) ) {
					return Site_Support::error(
						'mosmcp_menu_reorder_entry',
						__( 'Each entry in items must be an object with item_id and parent_item_id (0 for the top level).', 'mosmcp-abilities' ),
						Site_Support::CAUSE_INVALID_INPUT,
						true
					);
				}

				$id       = (int) $entry['item_id'];
				$wanted[] = array(
					'id'     => $id,
					'parent' => isset( $entry['parent_item_id'] ) ? absint( $entry['parent_item_id'] ) : ( isset( $structure['parent'][ $id ] ) ? (int) $structure['parent'][ $id ] : 0 ),
				);
			}
		}

		$check = self::validate_reorder( $wanted, $items, $structure, $menu );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		// Apply: listed items move to their parent's list in list order, ahead of
		// the unlisted siblings, which keep their current relative order.
		$listed = array();
		foreach ( $wanted as $want ) {
			$listed[ $want['id'] ] = $want['parent'];
		}

		$new_parent = $structure['parent'];
		foreach ( $listed as $id => $parent ) {
			$new_parent[ $id ] = $parent;
		}

		$children = array();
		foreach ( $wanted as $want ) {
			$children[ $want['parent'] ][] = $want['id'];
		}

		foreach ( Menus_Support::flatten( $structure ) as $row ) {
			if ( ! isset( $listed[ $row['id'] ] ) ) {
				$children[ $new_parent[ $row['id'] ] ][] = $row['id'];
			}
		}

		$target = array(
			'parent'   => $new_parent,
			'children' => $children + array( 0 => array() ),
		);

		if ( count( Menus_Support::flatten( $target ) ) !== count( $items ) ) {
			return Site_Support::error(
				'mosmcp_menu_reorder_unreachable',
				__( 'That arrangement would leave some items unreachable from the top of the menu, so nothing was changed. Check that every parent_item_id belongs to this menu and that no item is placed inside its own children.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_INVALID_INPUT,
				true
			);
		}

		Menus_Support::prepare_environment();

		$stored  = Menus_Support::persist( $items, $target );
		$written = (int) $stored['written'];

		if ( $written > 0 || ! empty( $stored['failed'] ) ) {
			Menus_Support::touch_menu( (int) $menu->term_id );
		}

		// Read back: report success only when the stored order is the one asked for.
		if ( ! empty( $stored['failed'] ) || ! Menus_Support::structure_stored( $menu, $target ) ) {
			return Menus_Support::structure_error( $menu, $stored['failed'] );
		}

		$fresh_items = Menus_Support::load_items( $menu );
		$ignored     = array();
		$fresh       = Menus_Support::structure( $fresh_items, $ignored );
		$moved       = array();

		foreach ( Menus_Support::flatten( $fresh ) as $row ) {
			$was = Menus_Support::row_of( $original, $row['id'] );
			if ( $was['parent'] !== $row['parent'] || $was['position'] !== $row['position'] ) {
				$moved[] = array(
					'item_id'        => (int) $row['id'],
					'label'          => Menus_Support::plain( $fresh_items[ $row['id'] ]->title ),
					'parent_item_id' => (int) $row['parent'],
					'position'       => (int) $row['position'],
					'depth'          => (int) $row['depth'],
				);
			}
		}

		$notes = array();

		if ( 0 === $written ) {
			$notes[] = __( 'The menu was already in that order, so nothing was written.', 'mosmcp-abilities' );
		}

		$unlisted = count( $items ) - count( $listed );
		if ( $unlisted > 0 && $written > 0 ) {
			$notes[] = sprintf(
				/* translators: %d: number of items not listed. */
				_n( '%d item was not listed; it kept its parent and now follows the listed items at its level, in its previous order.', '%d items were not listed; they kept their parents and now follow the listed items at their level, in their previous order.', $unlisted, 'mosmcp-abilities' ),
				$unlisted
			);
		}

		return array(
			'menu'          => Menus_Support::describe_menu( self::fresh_menu( $menu ) ),
			'changed_count' => (int) $written,
			'moved'         => $moved,
			'outline'       => Menus_Support::outline( $fresh_items, $fresh ),
			'warnings'      => $warnings,
			'notes'         => $notes,
		);
	}

	/**
	 * Assigns a menu to a theme location, or clears the location.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function menu_assign_location( $input = array() ) {
		$input    = is_array( $input ) ? $input : array();
		$location = self::require_location( isset( $input['location'] ) ? (string) $input['location'] : '' );

		if ( is_wp_error( $location ) ) {
			return $location;
		}

		$raw_menu = array_key_exists( 'menu', $input ) ? $input['menu'] : null;
		$clearing = null === $raw_menu || 0 === $raw_menu || '0' === $raw_menu || '' === $raw_menu || false === $raw_menu;
		$menu     = null;

		if ( ! $clearing ) {
			$menu = Menus_Support::resolve_menu( $raw_menu );
			if ( is_wp_error( $menu ) ) {
				return $menu;
			}
		}

		$labels   = Menus_Support::registered_locations();
		$new_id   = $menu instanceof WP_Term ? (int) $menu->term_id : 0;
		$current  = get_nav_menu_locations();
		$prev_id  = isset( $current[ $location ] ) ? (int) $current[ $location ] : 0;
		$prev     = $prev_id > 0 ? wp_get_nav_menu_object( $prev_id ) : false;
		$changed  = $prev_id !== $new_id;
		$notes    = array();
		$previous = array(
			'menu_id' => $prev ? (int) $prev->term_id : 0,
			'name'    => $prev ? Menus_Support::name_of( $prev ) : '',
		);

		if ( $changed ) {
			$previous = self::assign( $location, $new_id );
		}

		$stored = get_nav_menu_locations();
		if ( ( isset( $stored[ $location ] ) ? (int) $stored[ $location ] : 0 ) !== $new_id ) {
			return Site_Support::error(
				'mosmcp_menu_location_unverified',
				__( 'The location setting did not stick; another plugin may be filtering theme settings. Check Appearance > Menus > Manage Locations.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_WP_CORE,
				false
			);
		}

		if ( ! $changed ) {
			$notes[] = __( 'The location already had that setting, so nothing was changed.', 'mosmcp-abilities' );
		} elseif ( 0 === $new_id ) {
			$notes[] = __( 'The location now has no menu, so the theme shows its fallback there (often a plain list of pages) or nothing. The menu itself was not deleted.', 'mosmcp-abilities' );
		} elseif ( $previous['menu_id'] > 0 ) {
			$notes[] = sprintf(
				/* translators: %s: previous menu name. */
				__( 'It replaced "%s" in that location; that menu still exists, it is just no longer shown there.', 'mosmcp-abilities' ),
				$previous['name']
			);
		}

		return array(
			'location'           => $location,
			'location_label'     => isset( $labels[ $location ] ) ? $labels[ $location ] : '',
			'menu_id'            => $new_id,
			'menu_name'          => $menu instanceof WP_Term ? Menus_Support::name_of( $menu ) : '',
			'previous_menu_id'   => (int) $previous['menu_id'],
			'previous_menu_name' => (string) $previous['name'],
			'changed'            => $changed,
			'notes'              => $notes,
		);
	}

	/**
	 * Resolves a location reference, refusing with the registered list when unknown.
	 *
	 * @param string $raw Location slug or label.
	 * @return string|WP_Error Location slug.
	 */
	private static function require_location( $raw ) {
		$registered = Menus_Support::registered_locations();

		if ( empty( $registered ) ) {
			$block = function_exists( 'wp_is_block_theme' ) && wp_is_block_theme();

			return Site_Support::error(
				'mosmcp_menu_no_locations',
				$block
					? __( 'The active theme is a block theme and registers no classic menu locations; its header and footer navigation are Navigation blocks. Use mosmcp/navigation-list to see and edit them.', 'mosmcp-abilities' )
					: __( 'The active theme registers no menu locations, so there is nowhere to assign a menu. A menu can still be shown with a Navigation Menu widget.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_CONFLICT,
				false,
				$block ? array( 'use_instead' => 'mosmcp/navigation-list' ) : array()
			);
		}

		$slug = Menus_Support::match_location( $raw );

		if ( '' !== $slug ) {
			return $slug;
		}

		$list = array();
		foreach ( $registered as $key => $label ) {
			$list[] = sprintf( '"%s" (%s)', $key, $label );
		}

		return Site_Support::error(
			'mosmcp_menu_location_unknown',
			sprintf(
				/* translators: 1: location supplied, 2: list of registered locations. */
				__( 'The active theme has no menu location called "%1$s". Its locations are: %2$s.', 'mosmcp-abilities' ),
				trim( $raw ),
				implode( ', ', $list )
			),
			Site_Support::CAUSE_NOT_FOUND,
			true,
			array( 'locations' => array_keys( $registered ) )
		);
	}

	/**
	 * Stores a location assignment and reports what it replaced.
	 *
	 * @param string $location Location slug.
	 * @param int    $menu_id  Menu ID, 0 to clear.
	 * @return array{menu_id:int, name:string} The menu previously assigned.
	 */
	private static function assign( $location, $menu_id ) {
		$locations = get_nav_menu_locations();
		$prev_id   = isset( $locations[ $location ] ) ? (int) $locations[ $location ] : 0;
		$prev      = $prev_id > 0 ? wp_get_nav_menu_object( $prev_id ) : false;

		$locations[ $location ] = (int) $menu_id;
		set_theme_mod( 'nav_menu_locations', $locations );

		return array(
			'menu_id' => $prev ? (int) $prev->term_id : 0,
			'name'    => $prev ? Menus_Support::name_of( $prev ) : '',
		);
	}

	/**
	 * Builds the link-target part of the item arguments from post_id, term_id,
	 * archive_post_type or url, of which at most one may be given.
	 *
	 * @param array<string, mixed>              $input    Ability input.
	 * @param array<int, array<string, string>> $warnings Warnings accumulator.
	 * @param bool                              $required Whether one target must be given.
	 * @return array<string, mixed>|WP_Error Empty array when none was given and none is required.
	 */
	private static function link_args( array $input, array &$warnings, $required ) {
		$given = array();

		foreach ( array( 'post_id', 'term_id', 'archive_post_type', 'url' ) as $key ) {
			if ( isset( $input[ $key ] ) && '' !== $input[ $key ] && 0 !== $input[ $key ] ) {
				$given[] = $key;
			}
		}

		if ( count( $given ) > 1 ) {
			return Site_Support::error(
				'mosmcp_menu_link_ambiguous',
				sprintf(
					/* translators: %s: the fields that were given together. */
					__( 'Pass only one link target, not %s together: post_id for a page or post, term_id for a category or tag, archive_post_type for a content-type archive, or url for any other address.', 'mosmcp-abilities' ),
					implode( ' and ', $given )
				),
				Site_Support::CAUSE_INVALID_INPUT,
				true
			);
		}

		if ( empty( $given ) ) {
			if ( ! $required ) {
				return array();
			}

			return Site_Support::error(
				'mosmcp_menu_link_required',
				__( 'Say what the item links to: post_id for a page or post, term_id for a category or tag, archive_post_type for a content-type archive (for example "product"), or url plus label for any other address.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_INVALID_INPUT,
				true
			);
		}

		switch ( $given[0] ) {
			case 'post_id':
				$post = Menus_Support::link_post( $input['post_id'] );
				if ( is_wp_error( $post ) ) {
					return $post;
				}

				return array(
					'menu-item-type'      => 'post_type',
					'menu-item-object'    => (string) $post->post_type,
					'menu-item-object-id' => (int) $post->ID,
					'menu-item-url'       => '',
				);

			case 'term_id':
				$term = Menus_Support::link_term( $input['term_id'] );
				if ( is_wp_error( $term ) ) {
					return $term;
				}

				return array(
					'menu-item-type'      => 'taxonomy',
					'menu-item-object'    => (string) $term->taxonomy,
					'menu-item-object-id' => (int) $term->term_id,
					'menu-item-url'       => '',
				);

			case 'archive_post_type':
				$type        = sanitize_key( (string) $input['archive_post_type'] );
				$type_object = get_post_type_object( $type );

				if ( ! $type_object || ( 'post' !== $type && empty( $type_object->has_archive ) ) ) {
					return Site_Support::error(
						'mosmcp_menu_archive_missing',
						sprintf(
							/* translators: %s: post type supplied. */
							__( '"%s" is not a content type with an archive page on this site, so there is no archive to link to. Use url for a custom address instead.', 'mosmcp-abilities' ),
							$type
						),
						Site_Support::CAUSE_NOT_FOUND,
						false
					);
				}

				return array(
					'menu-item-type'      => 'post_type_archive',
					'menu-item-object'    => $type,
					'menu-item-object-id' => 0,
					'menu-item-url'       => '',
				);
		}

		$url = Menus_Support::clean_url( $input['url'], $warnings );
		if ( is_wp_error( $url ) ) {
			return $url;
		}

		Menus_Support::suggest_page_link( $url, $warnings );

		return array(
			'menu-item-type'      => 'custom',
			'menu-item-object'    => 'custom',
			'menu-item-object-id' => 0,
			'menu-item-url'       => $url,
		);
	}

	/**
	 * Builds the display-field part of the item arguments from what was supplied.
	 *
	 * @param array<string, mixed>              $input    Ability input.
	 * @param array<int, array<string, string>> $warnings Warnings accumulator.
	 * @return array<string, string>
	 */
	private static function display_args( array $input, array &$warnings ) {
		$args = array();

		if ( array_key_exists( 'open_in_new_tab', $input ) && null !== $input['open_in_new_tab'] ) {
			$args['menu-item-target'] = Site_Support::bool_input( $input, 'open_in_new_tab', false ) ? '_blank' : '';
		}

		if ( array_key_exists( 'css_classes', $input ) && null !== $input['css_classes'] ) {
			$args['menu-item-classes'] = implode( ' ', Menus_Support::clean_classes( $input['css_classes'], $warnings ) );
		}

		if ( array_key_exists( 'description', $input ) && null !== $input['description'] ) {
			$args['menu-item-description'] = sanitize_textarea_field( (string) $input['description'] );
		}

		if ( array_key_exists( 'title_attribute', $input ) && null !== $input['title_attribute'] ) {
			$args['menu-item-attr-title'] = sanitize_text_field( (string) $input['title_attribute'] );
		}

		if ( array_key_exists( 'link_rel', $input ) && null !== $input['link_rel'] ) {
			$args['menu-item-xfn'] = Menus_Support::clean_rel( $input['link_rel'] );
		}

		return $args;
	}

	/**
	 * Writes only the fields of an item that actually change.
	 *
	 * Core's wp_update_nav_menu_item() re-saves the whole item, title included,
	 * through the post filters. For an account without unfiltered_html that strips markup
	 * (such as an icon) from the title even when the request only changed the
	 * new-tab setting. Here each changed value is written on its own: post
	 * columns directly (the title only when it changes, and only after
	 * clean_label() has confirmed WordPress would store it unchanged), link
	 * settings as the same meta keys core uses. Core's own "menu item updated"
	 * action is then fired so plugins that follow menu saves still see it.
	 *
	 * @param WP_Term              $menu     Menu term.
	 * @param int                  $item_id  Menu item ID.
	 * @param array<string, mixed> $original Stored values before the change (existing_args()).
	 * @param array<string, mixed> $args     Values after the change, same shape.
	 * @return true|WP_Error
	 */
	private static function write_fields( WP_Term $menu, $item_id, array $original, array $args ) {
		global $wpdb;

		$type = sanitize_key( (string) $args['menu-item-type'] );

		// Core's rule: a linked item whose label equals the linked title keeps no
		// label of its own, so it goes on following that title when it is renamed.
		$linked_title = self::linked_original_title( $args );
		if ( 'custom' !== $type && '' !== (string) $args['menu-item-title'] && $linked_title === (string) $args['menu-item-title'] ) {
			$args['menu-item-title'] = '';
		}

		if ( 'custom' === $type ) {
			$args['menu-item-object']    = 'custom';
			$args['menu-item-object-id'] = (int) $item_id;
		} else {
			$args['menu-item-url'] = '';
		}

		$columns = array();
		$map     = array(
			'menu-item-title'       => 'post_title',
			'menu-item-description' => 'post_content',
			'menu-item-attr-title'  => 'post_excerpt',
		);

		foreach ( $map as $key => $column ) {
			if ( (string) $args[ $key ] !== (string) $original[ $key ] ) {
				$columns[ $column ] = (string) $args[ $key ];
			}
		}

		$meta   = array(
			'_menu_item_type'      => $type,
			'_menu_item_object'    => sanitize_key( (string) $args['menu-item-object'] ),
			'_menu_item_object_id' => (string) (int) $args['menu-item-object-id'],
			'_menu_item_target'    => sanitize_key( (string) $args['menu-item-target'] ),
			'_menu_item_classes'   => array_values( array_filter( array_map( 'sanitize_html_class', explode( ' ', (string) $args['menu-item-classes'] ) ), 'strlen' ) ),
			// Normalised exactly as core does, so an untouched value compares equal.
			'_menu_item_xfn'       => implode( ' ', array_map( 'sanitize_html_class', explode( ' ', (string) $args['menu-item-xfn'] ) ) ),
			'_menu_item_url'       => '' === (string) $args['menu-item-url'] ? '' : sanitize_url( (string) $args['menu-item-url'] ),
		);
		$failed = array();

		if ( ! empty( $columns ) ) {
			$columns['post_modified']     = current_time( 'mysql' );
			$columns['post_modified_gmt'] = current_time( 'mysql', true );

			// Direct on purpose (see above); the post cache is cleared right after.
			$updated = $wpdb->update( $wpdb->posts, $columns, array( 'ID' => (int) $item_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			if ( false === $updated ) {
				$failed = array_merge( $failed, array_keys( $columns ) );
			}
		}

		foreach ( $meta as $key => $value ) {
			$current = get_post_meta( $item_id, $key, true );

			if ( '_menu_item_classes' === $key ) {
				$current = array_values( array_filter( array_map( 'strval', (array) $current ), 'strlen' ) );
			} else {
				$current = (string) $current;
			}

			if ( $current === $value ) {
				continue;
			}

			// The classes are stored as a list exactly as core stores them; an empty list keeps core's [""] shape.
			$store = ( '_menu_item_classes' === $key && empty( $value ) ) ? array( '' ) : $value;

			if ( ! update_post_meta( $item_id, $key, wp_slash( $store ) ) ) {
				$failed[] = $key;
			}
		}

		clean_post_cache( (int) $item_id );

		if ( ! empty( $failed ) ) {
			return Site_Support::error(
				'mosmcp_menu_item_update_failed',
				sprintf(
					/* translators: %s: comma-separated stored field names. */
					__( 'WordPress did not save part of the change (%s). Read the item again with mosmcp/menu-get to see what it holds now before retrying.', 'mosmcp-abilities' ),
					implode( ', ', $failed )
				),
				Site_Support::CAUSE_WP_CORE,
				true,
				array(
					'not_saved'   => $failed,
					'use_instead' => 'mosmcp/menu-get',
				)
			);
		}

		// The WordPress core action fired after a menu item is saved, with core's arguments.
		do_action( 'wp_update_nav_menu_item', (int) $menu->term_id, (int) $item_id, $args ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		return true;
	}

	/**
	 * Title of what a linked item points at, exactly as core compares it.
	 *
	 * @param array<string, mixed> $args Item values in wp_update_nav_menu_item() shape.
	 * @return string
	 */
	private static function linked_original_title( array $args ) {
		$type = (string) $args['menu-item-type'];
		$id   = (int) $args['menu-item-object-id'];

		if ( 'post_type' === $type ) {
			$post = get_post( $id );
			return $post ? (string) $post->post_title : '';
		}

		if ( 'taxonomy' === $type ) {
			$name = get_term_field( 'name', $id, (string) $args['menu-item-object'], 'raw' );
			return is_string( $name ) ? $name : '';
		}

		if ( 'post_type_archive' === $type ) {
			$type_object = get_post_type_object( (string) $args['menu-item-object'] );
			return $type_object ? (string) $type_object->labels->archives : '';
		}

		return '';
	}

	/**
	 * The item's current stored values, in the shape wp_update_nav_menu_item() takes.
	 *
	 * Read from the raw post and meta, not from the set-up item: the set-up title
	 * is the linked page's title when the item has none of its own, and writing
	 * that back would freeze the label so it stops following page renames.
	 *
	 * @param int $item_id Menu item ID.
	 * @return array<string, mixed>
	 */
	private static function existing_args( $item_id ) {
		clean_post_cache( $item_id );
		$post = get_post( $item_id );
		$type = (string) get_post_meta( $item_id, '_menu_item_type', true );

		return array(
			'menu-item-db-id'         => (int) $item_id,
			'menu-item-object-id'     => (int) get_post_meta( $item_id, '_menu_item_object_id', true ),
			'menu-item-object'        => (string) get_post_meta( $item_id, '_menu_item_object', true ),
			'menu-item-parent-id'     => (int) get_post_meta( $item_id, '_menu_item_menu_item_parent', true ),
			'menu-item-position'      => max( 1, (int) $post->menu_order ),
			'menu-item-type'          => '' !== $type ? $type : 'custom',
			'menu-item-title'         => (string) $post->post_title,
			'menu-item-url'           => (string) get_post_meta( $item_id, '_menu_item_url', true ),
			'menu-item-description'   => (string) $post->post_content,
			'menu-item-attr-title'    => (string) $post->post_excerpt,
			'menu-item-target'        => (string) get_post_meta( $item_id, '_menu_item_target', true ),
			'menu-item-classes'       => implode( ' ', array_filter( array_map( 'strval', (array) get_post_meta( $item_id, '_menu_item_classes', true ) ), 'strlen' ) ),
			'menu-item-xfn'           => (string) get_post_meta( $item_id, '_menu_item_xfn', true ),
			'menu-item-status'        => (string) $post->post_status,
			'menu-item-post-date'     => (string) $post->post_date,
			'menu-item-post-date-gmt' => (string) $post->post_date_gmt,
		);
	}

	/**
	 * Stored field values of an item, for working out what a save changed.
	 *
	 * @param int $item_id Menu item ID.
	 * @return array<string, string>
	 */
	private static function snapshot( $item_id ) {
		$args = self::existing_args( $item_id );

		return array(
			'label'           => $args['menu-item-title'],
			'url'             => $args['menu-item-url'],
			'link_target'     => $args['menu-item-type'] . '|' . $args['menu-item-object'] . '|' . ( 'custom' === $args['menu-item-type'] ? '' : (string) $args['menu-item-object-id'] ),
			'open_in_new_tab' => $args['menu-item-target'],
			'css_classes'     => $args['menu-item-classes'],
			'link_rel'        => $args['menu-item-xfn'],
			'description'     => trim( $args['menu-item-description'] ),
			'title_attribute' => $args['menu-item-attr-title'],
			'menu_order'      => (string) $args['menu-item-position'],
			'status'          => $args['menu-item-status'],
			'date'            => $args['menu-item-post-date'],
		);
	}

	/**
	 * Snapshot field names a request asked to change.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return string[]
	 */
	private static function asked_fields( array $input ) {
		$map   = array(
			'label'             => array( 'label' ),
			'url'               => array( 'url', 'link_target', 'label' ),
			'post_id'           => array( 'link_target', 'url', 'label' ),
			'term_id'           => array( 'link_target', 'url', 'label' ),
			'archive_post_type' => array( 'link_target', 'url', 'label' ),
			'open_in_new_tab'   => array( 'open_in_new_tab' ),
			'css_classes'       => array( 'css_classes' ),
			'description'       => array( 'description' ),
			'title_attribute'   => array( 'title_attribute' ),
			'link_rel'          => array( 'link_rel' ),
			'parent_item_id'    => array( 'parent_item_id', 'position' ),
			'position'          => array( 'position' ),
		);
		$asked = array();

		foreach ( $map as $key => $fields ) {
			if ( array_key_exists( $key, $input ) && null !== $input[ $key ] ) {
				$asked = array_merge( $asked, $fields );
			}
		}

		return array_values( array_unique( $asked ) );
	}

	/**
	 * Loads an item and its menu, refusing anything that is not a menu item.
	 *
	 * @param mixed $raw_id Item ID as supplied.
	 * @return array{menu: WP_Term, items: array<int, object>, item: object}|WP_Error
	 */
	private static function item_context( $raw_id ) {
		$item_id = absint( $raw_id );
		$post    = $item_id > 0 ? get_post( $item_id ) : null;

		if ( ! $post ) {
			return Site_Support::error(
				'mosmcp_menu_item_not_found',
				sprintf(
					/* translators: %d: item ID supplied. */
					__( 'No menu item exists with ID %d. Use mosmcp/menu-get to see each item\'s item_id.', 'mosmcp-abilities' ),
					$item_id
				),
				Site_Support::CAUSE_NOT_FOUND,
				false,
				array( 'use_instead' => 'mosmcp/menu-get' )
			);
		}

		if ( 'nav_menu_item' !== $post->post_type ) {
			$type_object = get_post_type_object( $post->post_type );
			$type_label  = $type_object ? strtolower( (string) $type_object->labels->singular_name ) : (string) $post->post_type;

			// No title here: the ID can belong to any record, including private ones.
			return Site_Support::error(
				'mosmcp_menu_item_wrong_type',
				is_post_type_viewable( $post->post_type )
					? sprintf(
						/* translators: 1: ID supplied, 2: content type label. */
						__( 'ID %1$d is a %2$s, not a menu item. Menu items have their own IDs, shown as item_id by mosmcp/menu-get. To put this %2$s in a menu, use mosmcp/menu-add-item with post_id %1$d.', 'mosmcp-abilities' ),
						$item_id,
						$type_label
					)
					: sprintf(
						/* translators: %d: ID supplied. */
						__( 'ID %d is not a menu item. Menu items have their own IDs, shown as item_id by mosmcp/menu-get.', 'mosmcp-abilities' ),
						$item_id
					),
				Site_Support::CAUSE_INVALID_INPUT,
				false,
				array( 'use_instead' => 'mosmcp/menu-get' )
			);
		}

		$terms = wp_get_object_terms( $item_id, 'nav_menu' );
		$menu  = ( ! is_wp_error( $terms ) && ! empty( $terms ) && $terms[0] instanceof WP_Term ) ? $terms[0] : null;

		if ( ! $menu ) {
			return Site_Support::error(
				'mosmcp_menu_item_orphan',
				sprintf(
					/* translators: %d: item ID. */
					__( 'Menu item %d does not belong to any menu. It is a leftover from an unsaved edit in Appearance > Menus that WordPress removes on its own, so it cannot be changed here.', 'mosmcp-abilities' ),
					$item_id
				),
				Site_Support::CAUSE_CONFLICT,
				false
			);
		}

		$items = Menus_Support::load_items( $menu );

		if ( ! isset( $items[ $item_id ] ) ) {
			return Site_Support::error(
				'mosmcp_menu_item_unavailable',
				sprintf(
					/* translators: 1: item ID, 2: status. */
					__( 'Menu item %1$d has the status "%2$s" and is not part of the live menu, so it cannot be changed here.', 'mosmcp-abilities' ),
					$item_id,
					$post->post_status
				),
				Site_Support::CAUSE_CONFLICT,
				false
			);
		}

		return array(
			'menu'  => $menu,
			'items' => $items,
			'item'  => $items[ $item_id ],
		);
	}

	/**
	 * Builds the shared write result for one item: the menu, the item, the outline.
	 *
	 * @param WP_Term                           $menu     Menu term.
	 * @param int                               $item_id  Item ID.
	 * @param array<int, array<string, string>> $warnings Warnings accumulator.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function item_result( WP_Term $menu, $item_id, array &$warnings ) {
		$items     = Menus_Support::load_items( $menu );
		$ignored   = array();
		$structure = Menus_Support::structure( $items, $ignored );

		if ( ! isset( $items[ $item_id ] ) ) {
			return Site_Support::error(
				'mosmcp_menu_item_unverified',
				__( 'WordPress reported the change as saved, but the item cannot be found in the menu afterwards. Read the menu with mosmcp/menu-get before retrying.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_WP_CORE,
				false
			);
		}

		$state = Menus_Support::linked_state( $items[ $item_id ] );
		Menus_Support::item_warnings( $items[ $item_id ], $state, $warnings );

		return array(
			'menu'     => Menus_Support::describe_menu( self::fresh_menu( $menu ) ),
			'item'     => Menus_Support::describe_item( $items[ $item_id ], Menus_Support::row_of( $structure, $item_id ), $state ),
			'outline'  => Menus_Support::outline( $items, $structure ),
			'warnings' => $warnings,
			'notes'    => array(),
		);
	}

	/**
	 * Warns when the stored label differs from the one requested.
	 *
	 * @param string                            $requested Label as sent to WordPress.
	 * @param array<string, mixed>              $item      Described item.
	 * @param array<int, array<string, string>> $warnings  Warnings accumulator.
	 * @return void
	 */
	private static function label_check( $requested, array $item, array &$warnings ) {
		$requested = Menus_Support::plain( $requested );

		if ( '' === $requested || $requested === $item['label'] ) {
			return;
		}

		$warnings[] = Menus_Support::warning(
			'label_changed_on_save',
			sprintf(
				/* translators: 1: requested label, 2: label as stored. */
				__( 'The label was saved as "%2$s" rather than "%1$s"; WordPress or a plugin filtered it.', 'mosmcp-abilities' ),
				$requested,
				$item['label']
			),
			'item ' . (int) $item['item_id']
		);
	}

	/**
	 * Warns when the menu already links to the same target.
	 *
	 * @param array<int, object>                $items    Current items.
	 * @param array<string, mixed>              $args     New item arguments.
	 * @param array<int, array<string, string>> $warnings Warnings accumulator.
	 * @return void
	 */
	private static function duplicate_warning( array $items, array $args, array &$warnings ) {
		foreach ( $items as $item ) {
			$same = ( 'custom' === $args['menu-item-type'] )
				? ( 'custom' === $item->type && untrailingslashit( (string) $item->url ) === untrailingslashit( (string) $args['menu-item-url'] ) )
				: ( (string) $item->type === $args['menu-item-type'] && (string) $item->object === $args['menu-item-object'] && (int) $item->object_id === (int) $args['menu-item-object-id'] );

			if ( $same ) {
				$warnings[] = Menus_Support::warning(
					'already_in_menu',
					sprintf(
						/* translators: 1: existing item label, 2: existing item ID. */
						__( 'The menu already links to the same place as "%1$s" (item %2$d), so it now appears twice. Remove one with mosmcp/menu-remove-item if that was not intended.', 'mosmcp-abilities' ),
						Menus_Support::plain( $item->title ),
						(int) $item->ID
					),
					'item ' . (int) $item->ID
				);
				return;
			}
		}
	}

	/**
	 * Validates a reorder request before anything is written.
	 *
	 * @param array<int, array{id:int, parent:int}> $wanted    Listed items and desired parents.
	 * @param array<int, object>                    $items     Items in the menu.
	 * @param array<string, mixed>                  $structure Current structure.
	 * @param WP_Term                               $menu      Menu term.
	 * @return true|WP_Error
	 */
	private static function validate_reorder( array $wanted, array $items, array $structure, WP_Term $menu ) {
		$seen    = array();
		$foreign = array();
		$parents = $structure['parent'];

		foreach ( $wanted as $want ) {
			if ( $want['id'] < 1 || ! isset( $items[ $want['id'] ] ) ) {
				$foreign[] = $want['id'];
				continue;
			}
			if ( isset( $seen[ $want['id'] ] ) ) {
				return Site_Support::error(
					'mosmcp_menu_reorder_duplicate',
					sprintf(
						/* translators: %d: item ID. */
						__( 'Item %d is listed more than once. List each item once, in the order it should appear.', 'mosmcp-abilities' ),
						$want['id']
					),
					Site_Support::CAUSE_INVALID_INPUT,
					true
				);
			}
			if ( $want['parent'] > 0 && ! isset( $items[ $want['parent'] ] ) ) {
				return self::parent_not_in_menu( $want['parent'], $menu );
			}
			if ( $want['parent'] === $want['id'] ) {
				return Site_Support::error(
					'mosmcp_menu_parent_self',
					sprintf(
						/* translators: %d: item ID. */
						__( 'Item %d cannot be nested under itself.', 'mosmcp-abilities' ),
						$want['id']
					),
					Site_Support::CAUSE_INVALID_INPUT,
					true
				);
			}
			$seen[ $want['id'] ]    = true;
			$parents[ $want['id'] ] = $want['parent'];
		}

		if ( ! empty( $foreign ) ) {
			return Site_Support::error(
				'mosmcp_menu_reorder_foreign',
				sprintf(
					/* translators: 1: comma-separated item IDs, 2: menu name. */
					__( 'These IDs are not items of the menu "%2$s": %1$s. Use the item_id values that mosmcp/menu-get reports for this menu. Nothing was changed.', 'mosmcp-abilities' ),
					implode( ', ', $foreign ),
					Menus_Support::name_of( $menu )
				),
				Site_Support::CAUSE_INVALID_INPUT,
				true,
				array(
					'not_in_menu' => $foreign,
					'use_instead' => 'mosmcp/menu-get',
				)
			);
		}

		$limit = count( $parents );

		foreach ( array_keys( $parents ) as $id ) {
			$current = $parents[ $id ];
			$steps   = 0;

			while ( 0 !== $current && $steps <= $limit ) {
				if ( $current === $id ) {
					return Site_Support::error(
						'mosmcp_menu_parent_cycle',
						sprintf(
							/* translators: %d: item ID. */
							__( 'That arrangement puts item %d inside its own children, which would make a loop. Nothing was changed.', 'mosmcp-abilities' ),
							$id
						),
						Site_Support::CAUSE_INVALID_INPUT,
						true
					);
				}
				$current = isset( $parents[ $current ] ) ? $parents[ $current ] : 0;
				++$steps;
			}
		}

		return true;
	}

	/**
	 * Error for a parent item that is not in the menu.
	 *
	 * @param int     $parent_id Parent item ID supplied.
	 * @param WP_Term $menu   Menu term.
	 * @return WP_Error
	 */
	private static function parent_not_in_menu( $parent_id, WP_Term $menu ) {
		return Site_Support::error(
			'mosmcp_menu_parent_not_in_menu',
			sprintf(
				/* translators: 1: parent item ID, 2: menu name. */
				__( 'Item %1$d is not an item of the menu "%2$s", so nothing can be nested under it there. Use an item_id from mosmcp/menu-get for this menu, or 0 for the top level.', 'mosmcp-abilities' ),
				(int) $parent_id,
				Menus_Support::name_of( $menu )
			),
			Site_Support::CAUSE_INVALID_INPUT,
			true,
			array( 'use_instead' => 'mosmcp/menu-get' )
		);
	}

	/**
	 * Describes what an item links to, for messages.
	 *
	 * @param object $item Set-up menu item.
	 * @return string
	 */
	private static function describe_link_target( $item ) {
		if ( 'post_type' === $item->type ) {
			/* translators: 1: content type, 2: post ID. */
			return sprintf( __( 'the %1$s with ID %2$d', 'mosmcp-abilities' ), (string) $item->object, (int) $item->object_id );
		}

		if ( 'taxonomy' === $item->type ) {
			/* translators: 1: taxonomy, 2: term ID. */
			return sprintf( __( 'the %1$s with ID %2$d', 'mosmcp-abilities' ), (string) $item->object, (int) $item->object_id );
		}

		/* translators: %s: content type. */
		return sprintf( __( 'the %s archive', 'mosmcp-abilities' ), (string) $item->object );
	}

	/**
	 * Counts classic Navigation Menu widgets that display a menu.
	 *
	 * @param int $menu_id Menu ID.
	 * @return int
	 */
	private static function widgets_showing( $menu_id ) {
		$count = 0;

		foreach ( (array) get_option( 'widget_nav_menu', array() ) as $instance ) {
			if ( is_array( $instance ) && isset( $instance['nav_menu'] ) && (int) $instance['nav_menu'] === (int) $menu_id ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Re-reads a menu so counts reflect the change just made.
	 *
	 * @param WP_Term $menu Menu term.
	 * @return WP_Term
	 */
	private static function fresh_menu( WP_Term $menu ) {
		clean_term_cache( (int) $menu->term_id, 'nav_menu' );
		$fresh = get_term( (int) $menu->term_id, 'nav_menu' );

		return $fresh instanceof WP_Term ? $fresh : $menu;
	}
}
