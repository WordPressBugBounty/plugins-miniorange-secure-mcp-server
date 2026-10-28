<?php
/**
 * Shared helpers for the Menus & Navigation ability pack: menu and location
 * resolution, the item tree, safe link targets, and the output schemas.
 *
 * Classic menus are stored as a nav_menu term with one nav_menu_item post per
 * entry, the nesting held in post meta and the order in menu_order. Almost every
 * mistake an editing tool makes with them comes from treating that storage as if
 * it were the menu: core's own reader hides broken items outside wp-admin, core's
 * item writer resets every field it is not handed, and core's item delete leaves
 * the children pointing at a parent that no longer exists. Everything here reads
 * the real storage and rebuilds the structure explicitly so none of that leaks
 * through to the caller.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Menus;

use MoSMCP\Abilities\Packs\Core\Support\Core_Schema as Schema;
use MoSMCP\Abilities\Packs\Site\Site_Support;
use MoSMCP\Abilities\Support\Html_Policy;
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
 * Class Menus_Support
 *
 * Static helpers shared by Menus_Provider, Navigation_Provider and Menus_Pack.
 */
class Menus_Support {

	/**
	 * Ability category slug.
	 */
	const CATEGORY = 'mosmcp-menus';

	/**
	 * Capability every menu and navigation ability requires. It is the one core
	 * uses for Appearance > Menus, the menu REST endpoints and wp_navigation posts.
	 */
	const CAPABILITY = 'edit_theme_options';

	/**
	 * Link schemes a menu link may use. Anything else (javascript:, data:,
	 * vbscript:, file: ...) is refused rather than silently emptied by core.
	 *
	 * @var string[]
	 */
	const ALLOWED_SCHEMES = array( 'http', 'https', 'mailto', 'tel' );

	/**
	 * How many levels of children the tree output schema spells out field by field.
	 * Deeper levels are still returned in full, with the same shape.
	 */
	const TREE_SCHEMA_LEVELS = 3;

	/**
	 * Largest nesting the structure walker follows, as a guard against corrupt data.
	 */
	const MAX_DEPTH = 50;

	/**
	 * Builds one entry of a warnings array.
	 *
	 * @param string $code    Machine-readable code.
	 * @param string $message What happened and what to do about it.
	 * @param string $context Optional pointer to the item concerned.
	 * @return array<string, string>
	 */
	public static function warning( $code, $message, $context = '' ) {
		return array(
			'code'    => (string) $code,
			'message' => (string) $message,
			'context' => (string) $context,
		);
	}

	/**
	 * Loads the wp-admin screen API when it is missing.
	 *
	 * Several popular theme and menu plugins hook menu saves and call
	 * get_current_screen(), which only exists inside wp-admin. Outside it, the
	 * first menu write would end in a fatal "undefined function" from someone
	 * else's code. Loading the screen API makes the function exist (it returns
	 * null outside an admin screen, which those plugins already handle).
	 *
	 * @return void
	 */
	public static function prepare_environment() {
		if ( ! function_exists( 'get_current_screen' ) ) {
			require_once ABSPATH . 'wp-admin/includes/screen.php';
		}
	}

	/**
	 * Fires core's "menu saved" action so page caches and menu plugins refresh.
	 *
	 * Item-level writes never fire it on their own; wp-admin and the core REST
	 * menus endpoint fire it with the menu ID alone after a save, exactly as here.
	 *
	 * @param int $menu_id Menu term ID.
	 * @return void
	 */
	public static function touch_menu( $menu_id ) {
		// A WordPress core action, fired exactly as wp-admin fires it after saving a menu.
		do_action( 'wp_update_nav_menu', (int) $menu_id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
	}

	/**
	 * Input schema for a menu reference: ID, slug, name, or a location holding it.
	 *
	 * @param string $description Field description.
	 * @return array<string, mixed>
	 */
	public static function menu_ref_input( $description = '' ) {
		return array(
			'type'        => array( 'integer', 'string' ),
			'description' => '' !== $description ? $description : __( 'The menu: its numeric ID, its slug, or its name (for example 7, "main-menu" or "Main Menu"). The name of a theme location that has a menu assigned also works, for example "primary".', 'mosmcp-abilities' ),
		);
	}

	/**
	 * Registered theme locations, slug => label.
	 *
	 * @return array<string, string>
	 */
	public static function registered_locations() {
		$registered = get_registered_nav_menus();
		$out        = array();

		foreach ( (array) $registered as $slug => $label ) {
			$out[ (string) $slug ] = (string) $label;
		}

		return $out;
	}

	/**
	 * Registered locations a menu is currently assigned to.
	 *
	 * @param int $menu_id Menu term ID.
	 * @return string[]
	 */
	public static function locations_of( $menu_id ) {
		$registered = self::registered_locations();
		$found      = array();

		foreach ( get_nav_menu_locations() as $slug => $assigned ) {
			if ( (int) $assigned === (int) $menu_id && isset( $registered[ (string) $slug ] ) ) {
				$found[] = (string) $slug;
			}
		}

		return $found;
	}

	/**
	 * Whether WordPress adds new top-level pages to a menu automatically.
	 *
	 * @param int $menu_id Menu term ID.
	 * @return bool
	 */
	public static function auto_add_pages( $menu_id ) {
		$options = (array) get_option( 'nav_menu_options', array() );
		$list    = isset( $options['auto_add'] ) ? array_map( 'intval', (array) $options['auto_add'] ) : array();

		return in_array( (int) $menu_id, $list, true );
	}

	/**
	 * Switches the "add new top-level pages automatically" option for a menu.
	 *
	 * @param int  $menu_id Menu term ID.
	 * @param bool $enabled Whether the option should be on.
	 * @return void
	 */
	public static function set_auto_add_pages( $menu_id, $enabled ) {
		$options = (array) get_option( 'nav_menu_options', array() );
		$list    = isset( $options['auto_add'] ) ? array_map( 'intval', (array) $options['auto_add'] ) : array();
		$list    = array_values( array_diff( $list, array( (int) $menu_id ) ) );

		if ( $enabled ) {
			$list[] = (int) $menu_id;
		}

		$options['auto_add'] = $list;
		update_option( 'nav_menu_options', $options );
	}

	/**
	 * A menu's name as people typed it.
	 *
	 * WordPress stores term names HTML-escaped ("Q&A" is saved as "Q&amp;A"), so
	 * the raw value neither matches what a person asks for nor reads well back.
	 *
	 * @param object $menu Menu term.
	 * @return string
	 */
	public static function name_of( $menu ) {
		return wp_specialchars_decode( (string) $menu->name, ENT_QUOTES );
	}

	/**
	 * Describes a menu in the shape every menu ability returns.
	 *
	 * @param WP_Term $menu Menu term.
	 * @return array<string, mixed>
	 */
	public static function describe_menu( WP_Term $menu ) {
		return array(
			'menu_id'        => (int) $menu->term_id,
			'name'           => self::name_of( $menu ),
			'slug'           => (string) $menu->slug,
			'description'    => (string) $menu->description,
			'item_count'     => (int) $menu->count,
			'locations'      => self::locations_of( (int) $menu->term_id ),
			'auto_add_pages' => self::auto_add_pages( (int) $menu->term_id ),
		);
	}

	/**
	 * Resolves a caller-supplied menu reference to exactly one menu.
	 *
	 * Each rule is a complete pass over every menu; a pass that matches more than
	 * one menu refuses and names the candidates instead of picking one, so an edit
	 * can never land on a menu the caller did not mean.
	 *
	 * @param mixed $reference Menu ID, slug, name, or location slug/label.
	 * @return WP_Term|WP_Error
	 */
	public static function resolve_menu( $reference ) {
		if ( is_array( $reference ) || is_object( $reference ) || is_bool( $reference ) || null === $reference ) {
			return self::menu_reference_empty();
		}

		$raw = trim( (string) $reference );

		if ( '' === $raw ) {
			return self::menu_reference_empty();
		}

		$menus = array();
		foreach ( (array) wp_get_nav_menus() as $menu ) {
			if ( $menu instanceof WP_Term ) {
				$menus[ (int) $menu->term_id ] = $menu;
			}
		}

		$lower = strtolower( $raw );

		// A JSON integer is unambiguously an ID: never fall through to name matching.
		if ( is_int( $reference ) ) {
			if ( isset( $menus[ $reference ] ) ) {
				return $menus[ $reference ];
			}

			return self::menu_id_not_found( (int) $reference, $menus );
		}

		/*
		 * An all-digits string is an ID as well. The loose rules below (any
		 * letter case, theme locations, partial names) must never apply to it:
		 * "2" for a deleted menu 2 would otherwise partially match "Main Menu 2"
		 * and an edit meant for one menu would land on another. Only a menu whose
		 * slug or name is exactly those digits may stand in, and if one does
		 * while a different menu has that ID, the call is refused as ambiguous.
		 */
		if ( ctype_digit( $raw ) ) {
			$hits = array_values(
				array_filter(
					$menus,
					static function ( WP_Term $menu ) use ( $raw ) {
						return (int) $raw === (int) $menu->term_id || $raw === $menu->slug || self::name_of( $menu ) === $raw;
					}
				)
			);
			$one  = self::single_hit( $raw, $hits );

			return null !== $one ? $one : self::menu_id_not_found( (int) $raw, $menus );
		}

		$rules = array(
			// Exact slug or name.
			static function ( WP_Term $menu ) use ( $raw ) {
				return $raw === $menu->slug || self::name_of( $menu ) === $raw;
			},
			// Name in any letter case, or the slug the name would produce.
			static function ( WP_Term $menu ) use ( $raw, $lower ) {
				return strtolower( self::name_of( $menu ) ) === $lower || sanitize_title( $raw ) === $menu->slug;
			},
		);

		foreach ( $rules as $matches_rule ) {
			$hits = array_values( array_filter( $menus, $matches_rule ) );
			$one  = self::single_hit( $raw, $hits );

			if ( null !== $one ) {
				return $one;
			}
		}

		// A theme location: people say "the primary menu" or "the footer menu".
		$by_location = self::menu_from_location( $raw, $menus );
		if ( $by_location instanceof WP_Term ) {
			return $by_location;
		}

		// Partial name, last, because it is the loosest.
		$partial = array_values(
			array_filter(
				$menus,
				static function ( WP_Term $menu ) use ( $lower ) {
					return false !== strpos( strtolower( self::name_of( $menu ) ), $lower );
				}
			)
		);
		$one     = self::single_hit( $raw, $partial );

		if ( null !== $one ) {
			return $one;
		}

		// The reference named an empty location: say that rather than "not found".
		if ( is_wp_error( $by_location ) ) {
			return $by_location;
		}

		return self::menu_not_found( $raw, $menus );
	}

	/**
	 * Returns the single hit of a resolution pass, an ambiguity error, or null.
	 *
	 * @param string    $raw  Reference as supplied.
	 * @param WP_Term[] $hits Menus the pass matched.
	 * @return WP_Term|WP_Error|null
	 */
	private static function single_hit( $raw, array $hits ) {
		if ( 1 === count( $hits ) ) {
			return $hits[0];
		}

		if ( count( $hits ) < 2 ) {
			return null;
		}

		$candidates = array();
		$labels     = array();

		foreach ( $hits as $menu ) {
			$candidates[] = array(
				'menu_id' => (int) $menu->term_id,
				'name'    => self::name_of( $menu ),
				'slug'    => (string) $menu->slug,
			);
			$labels[]     = sprintf( '%d "%s" (slug %s)', (int) $menu->term_id, self::name_of( $menu ), $menu->slug );
		}

		return Site_Support::error(
			'mosmcp_menu_ambiguous',
			sprintf(
				/* translators: 1: the menu reference supplied, 2: list of matching menus. */
				__( '"%1$s" matches more than one menu: %2$s. Call again with the numeric menu ID so the wrong menu is not changed.', 'mosmcp-abilities' ),
				$raw,
				implode( '; ', $labels )
			),
			Site_Support::CAUSE_INVALID_INPUT,
			true,
			array( 'candidates' => $candidates )
		);
	}

	/**
	 * Finds the menu assigned to a location named by slug or label.
	 *
	 * @param string             $raw   Reference as supplied.
	 * @param array<int,WP_Term> $menus Menus keyed by ID.
	 * @return WP_Term|WP_Error|null
	 */
	private static function menu_from_location( $raw, array $menus ) {
		$slug = self::match_location( $raw );

		if ( '' === $slug ) {
			return null;
		}

		$assigned = get_nav_menu_locations();
		$menu_id  = isset( $assigned[ $slug ] ) ? (int) $assigned[ $slug ] : 0;

		if ( $menu_id > 0 && isset( $menus[ $menu_id ] ) ) {
			return $menus[ $menu_id ];
		}

		return Site_Support::error(
			'mosmcp_menu_location_empty',
			sprintf(
				/* translators: %s: theme location slug. */
				__( 'No menu is called "%1$s", and the theme location "%1$s" has no menu assigned. Use mosmcp/menu-list to see the menus, then mosmcp/menu-assign-location to put one there.', 'mosmcp-abilities' ),
				$slug
			),
			Site_Support::CAUSE_NOT_FOUND,
			false,
			array( 'use_instead' => 'mosmcp/menu-list' )
		);
	}

	/**
	 * Matches a registered location by slug, then by label in any letter case.
	 *
	 * @param string $raw Location slug or label as supplied.
	 * @return string Location slug, or '' when nothing matches.
	 */
	public static function match_location( $raw ) {
		$raw        = trim( (string) $raw );
		$registered = self::registered_locations();

		if ( '' === $raw ) {
			return '';
		}

		if ( isset( $registered[ $raw ] ) ) {
			return $raw;
		}

		$lower = strtolower( $raw );
		$hits  = array();

		foreach ( $registered as $slug => $label ) {
			if ( strtolower( $slug ) === $lower || strtolower( $label ) === $lower ) {
				$hits[] = $slug;
			}
		}

		return 1 === count( $hits ) ? $hits[0] : '';
	}

	/**
	 * Error for a missing or empty menu reference.
	 *
	 * @return WP_Error
	 */
	private static function menu_reference_empty() {
		return Site_Support::error(
			'mosmcp_menu_reference_empty',
			__( 'No menu was named. Pass the menu ID, slug or name. Use mosmcp/menu-list to see every menu.', 'mosmcp-abilities' ),
			Site_Support::CAUSE_INVALID_INPUT,
			true,
			array( 'use_instead' => 'mosmcp/menu-list' )
		);
	}

	/**
	 * Error for a menu ID that does not exist, listing what does.
	 *
	 * @param int                $menu_id ID supplied.
	 * @param array<int,WP_Term> $menus   Every menu.
	 * @return WP_Error
	 */
	private static function menu_id_not_found( $menu_id, array $menus ) {
		$names = array();

		foreach ( array_slice( $menus, 0, 20, true ) as $menu ) {
			$names[] = sprintf( '%d "%s"', (int) $menu->term_id, self::name_of( $menu ) );
		}

		return Site_Support::error(
			'mosmcp_menu_not_found',
			sprintf(
				/* translators: 1: menu ID supplied, 2: list of existing menus. */
				__( 'There is no menu with ID %1$d (it may have been deleted). Menus on this site: %2$s. Nothing was changed; read the menus again with mosmcp/menu-list rather than guessing.', 'mosmcp-abilities' ),
				(int) $menu_id,
				empty( $names ) ? __( 'none', 'mosmcp-abilities' ) : implode( ', ', $names )
			),
			Site_Support::CAUSE_NOT_FOUND,
			false,
			array( 'use_instead' => 'mosmcp/menu-list' )
		);
	}

	/**
	 * Error for a menu reference that matches nothing, listing what exists.
	 *
	 * @param string             $raw   Reference as supplied.
	 * @param array<int,WP_Term> $menus Every menu.
	 * @return WP_Error
	 */
	private static function menu_not_found( $raw, array $menus ) {
		$names = array();

		foreach ( array_slice( $menus, 0, 20, true ) as $menu ) {
			$names[] = sprintf( '%d "%s"', (int) $menu->term_id, self::name_of( $menu ) );
		}

		$message = empty( $names )
			? sprintf(
				/* translators: %s: the menu reference supplied. */
				__( 'No menu matches "%s": this site has no classic menus at all. Create one with mosmcp/menu-create. If the site uses a block theme, its navigation lives in Navigation blocks instead; use mosmcp/navigation-list.', 'mosmcp-abilities' ),
				$raw
			)
			: sprintf(
				/* translators: 1: the menu reference supplied, 2: list of existing menus. */
				__( 'No menu matches "%1$s". Menus on this site: %2$s. Call again with one of these IDs or names.', 'mosmcp-abilities' ),
				$raw,
				implode( ', ', $names )
			);

		return Site_Support::error(
			'mosmcp_menu_not_found',
			$message,
			Site_Support::CAUSE_NOT_FOUND,
			false,
			array( 'use_instead' => empty( $names ) ? 'mosmcp/menu-create' : 'mosmcp/menu-list' )
		);
	}

	/**
	 * Every item of a menu, including the ones core hides outside wp-admin.
	 *
	 * Core's wp_get_nav_menu_items() drops items whose linked page or term is gone
	 * or trashed whenever is_admin() is false, which is always the case for an
	 * ability. Reading through it would make broken items invisible to the very
	 * tool asked to find them, and would renumber positions around the gaps. The
	 * items are therefore read from storage directly and set up the same way core
	 * does, without that filter.
	 *
	 * @param WP_Term $menu Menu term.
	 * @return array<int, object> Set-up menu items keyed by item ID, in stored order.
	 */
	public static function load_items( WP_Term $menu ) {
		$ids = get_objects_in_term( (int) $menu->term_id, 'nav_menu' );

		if ( is_wp_error( $ids ) || empty( $ids ) ) {
			return array();
		}

		$posts = get_posts(
			array(
				'post_type'     => 'nav_menu_item',
				'post__in'      => array_map( 'intval', (array) $ids ),
				'post_status'   => array( 'publish', 'draft' ),
				'numberposts'   => -1,
				'orderby'       => 'menu_order',
				'order'         => 'ASC',
				'no_found_rows' => true,
			)
		);

		if ( function_exists( 'update_menu_item_cache' ) ) {
			update_menu_item_cache( $posts );
		}

		usort(
			$posts,
			static function ( $a, $b ) {
				$order = (int) $a->menu_order <=> (int) $b->menu_order;
				return 0 !== $order ? $order : (int) $a->ID <=> (int) $b->ID;
			}
		);

		$items = array();

		foreach ( $posts as $post ) {
			$item = wp_setup_nav_menu_item( $post );

			if ( is_object( $item ) ) {
				$items[ (int) $post->ID ] = $item;
			}
		}

		return $items;
	}

	/**
	 * Builds the parent/children structure of a menu from its stored items.
	 *
	 * Items whose stored parent is missing (the state core leaves behind when a
	 * parent item is deleted) or that sit in a parent loop are placed at the top
	 * level, which is where WordPress renders them, and reported.
	 *
	 * @param array<int, object>                $items    Items from load_items().
	 * @param array<int, array<string, string>> $warnings Warnings accumulator.
	 * @return array{parent: array<int,int>, children: array<int,int[]>}
	 */
	public static function structure( array $items, array &$warnings ) {
		$parent = array();

		foreach ( $items as $id => $item ) {
			$stored = (int) $item->menu_item_parent;

			if ( 0 !== $stored && ( $stored === $id || ! isset( $items[ $stored ] ) ) ) {
				$warnings[] = self::warning(
					'orphaned_item',
					sprintf(
						/* translators: 1: menu item label, 2: menu item ID, 3: missing parent item ID. */
						__( 'The item "%1$s" (ID %2$d) is nested under item %3$d, which no longer exists in this menu, so WordPress shows it at the top level. It is treated as top level here; the next change to this menu stores it that way.', 'mosmcp-abilities' ),
						self::plain( $item->title ),
						$id,
						$stored
					),
					'item ' . $id
				);
				$stored = 0;
			}

			$parent[ $id ] = $stored;
		}

		foreach ( array_keys( $parent ) as $id ) {
			$seen    = array( $id => true );
			$current = $parent[ $id ];
			$steps   = 0;

			while ( 0 !== $current && $steps < self::MAX_DEPTH ) {
				if ( isset( $seen[ $current ] ) ) {
					$warnings[] = self::warning(
						'parent_loop',
						sprintf(
							/* translators: %d: menu item ID. */
							__( 'Item %d is part of a loop of items nested under each other, which WordPress cannot display. It is treated as top level here.', 'mosmcp-abilities' ),
							$id
						),
						'item ' . $id
					);
					$parent[ $id ] = 0;
					break;
				}
				$seen[ $current ] = true;
				$current          = isset( $parent[ $current ] ) ? $parent[ $current ] : 0;
				++$steps;
			}
		}

		$children = array( 0 => array() );

		foreach ( array_keys( $items ) as $id ) {
			$children[ $parent[ $id ] ][] = $id;
		}

		return array(
			'parent'   => $parent,
			'children' => $children,
		);
	}

	/**
	 * Walks a structure depth-first, top to bottom, the way the menu is displayed.
	 *
	 * @param array{parent: array<int,int>, children: array<int,int[]>} $structure Structure.
	 * @return array<int, array{id:int, parent:int, depth:int, position:int}>
	 */
	public static function flatten( array $structure ) {
		$rows = array();
		self::flatten_level( $structure, 0, 0, $rows );

		return $rows;
	}

	/**
	 * Recursive step of flatten().
	 *
	 * @param array<string, mixed>           $structure Structure.
	 * @param int                            $parent_id    Parent item ID, 0 for top level.
	 * @param int                            $depth     Depth of the children being walked.
	 * @param array<int, array<string, int>> $rows      Accumulator.
	 * @return void
	 */
	private static function flatten_level( array $structure, $parent_id, $depth, array &$rows ) {
		if ( $depth > self::MAX_DEPTH || empty( $structure['children'][ $parent_id ] ) ) {
			return;
		}

		foreach ( array_values( $structure['children'][ $parent_id ] ) as $index => $id ) {
			$rows[] = array(
				'id'       => (int) $id,
				'parent'   => (int) $parent_id,
				'depth'    => (int) $depth,
				'position' => $index + 1,
			);
			self::flatten_level( $structure, (int) $id, $depth + 1, $rows );
		}
	}

	/**
	 * Every descendant of an item, depth-first.
	 *
	 * @param array<string, mixed> $structure Structure.
	 * @param int                  $id        Item ID.
	 * @return int[]
	 */
	public static function descendants( array $structure, $id ) {
		$found = array();
		$queue = isset( $structure['children'][ $id ] ) ? array_values( $structure['children'][ $id ] ) : array();
		$guard = 0;

		while ( ! empty( $queue ) && $guard < 10000 ) {
			$next    = (int) array_shift( $queue );
			$found[] = $next;
			if ( ! empty( $structure['children'][ $next ] ) ) {
				$queue = array_merge( array_values( $structure['children'][ $next ] ), $queue );
			}
			++$guard;
		}

		return $found;
	}

	/**
	 * Removes an item from its parent's list of children.
	 *
	 * @param array<string, mixed> $structure Structure, modified in place.
	 * @param int                  $id        Item ID.
	 * @return void
	 */
	public static function detach( array &$structure, $id ) {
		$parent = isset( $structure['parent'][ $id ] ) ? (int) $structure['parent'][ $id ] : 0;

		if ( isset( $structure['children'][ $parent ] ) ) {
			$structure['children'][ $parent ] = array_values( array_diff( $structure['children'][ $parent ], array( (int) $id ) ) );
		}
	}

	/**
	 * Places an item under a parent at a 1-based position among its siblings.
	 *
	 * @param array<string, mixed> $structure Structure, modified in place.
	 * @param int                  $id        Item ID.
	 * @param int                  $parent_id    New parent item ID, 0 for top level.
	 * @param int                  $position  1-based position; 0 or past the end appends.
	 * @return int The 1-based position the item actually took.
	 */
	public static function attach( array &$structure, $id, $parent_id, $position = 0 ) {
		$parent_id = (int) $parent_id;

		if ( ! isset( $structure['children'][ $parent_id ] ) ) {
			$structure['children'][ $parent_id ] = array();
		}

		$siblings = array_values( $structure['children'][ $parent_id ] );
		$index    = ( $position >= 1 && $position <= count( $siblings ) ) ? $position - 1 : count( $siblings );

		array_splice( $siblings, $index, 0, array( (int) $id ) );

		$structure['children'][ $parent_id ] = $siblings;
		$structure['parent'][ (int) $id ]    = $parent_id;

		return $index + 1;
	}

	/**
	 * Stores a structure: parents and a 1..n depth-first menu_order, as wp-admin does.
	 *
	 * Only items whose parent or order actually differs are written, so an
	 * unchanged menu produces no writes and no modified-date churn.
	 *
	 * Only the two values that change are written: the parent meta, and the
	 * menu_order column directly. Going through wp_update_post() instead would
	 * re-save every item's title through the title filters, and for an account
	 * without unfiltered_html (a site administrator on multisite) that strips
	 * markup such as an icon from items the request never touched.
	 *
	 * @param array<int, object>   $items     Items from load_items(), keyed by ID.
	 * @param array<string, mixed> $structure Structure to store.
	 * @return array{written: int, failed: int[]} Items actually changed, and items a write failed for.
	 */
	public static function persist( array $items, array $structure ) {
		global $wpdb;

		$written = 0;
		$failed  = array();

		foreach ( self::flatten( $structure ) as $index => $row ) {
			$id = (int) $row['id'];

			if ( ! isset( $items[ $id ] ) ) {
				continue;
			}

			$order         = $index + 1;
			$stored_parent = (int) get_post_meta( $id, '_menu_item_menu_item_parent', true );
			$changed       = false;
			$ok            = true;

			if ( $stored_parent !== (int) $row['parent'] ) {
				update_post_meta( $id, '_menu_item_menu_item_parent', (int) $row['parent'] );
				$ok      = (int) get_post_meta( $id, '_menu_item_menu_item_parent', true ) === (int) $row['parent'];
				$changed = true;
			}

			if ( $ok && (int) $items[ $id ]->menu_order !== $order ) {
				// Direct on purpose (see above); the post cache is cleared right after.
				$updated = $wpdb->update( $wpdb->posts, array( 'menu_order' => $order ), array( 'ID' => $id ), array( '%d' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$ok      = false !== $updated;
				$changed = true;
			}

			if ( $changed ) {
				clean_post_cache( $id );
			}

			if ( ! $ok ) {
				$failed[] = $id;
			} elseif ( $changed ) {
				++$written;
			}
		}

		return array(
			'written' => $written,
			'failed'  => $failed,
		);
	}

	/**
	 * Whether the stored menu matches a target structure: same items, same parents, same order.
	 *
	 * @param WP_Term              $menu   Menu term.
	 * @param array<string, mixed> $target Structure that was meant to be stored.
	 * @return bool
	 */
	public static function structure_stored( WP_Term $menu, array $target ) {
		$ignored = array();
		$stored  = self::structure( self::load_items( $menu ), $ignored );
		$want    = array();
		$have    = array();

		foreach ( self::flatten( $target ) as $row ) {
			$want[] = $row['id'] . '>' . $row['parent'];
		}

		foreach ( self::flatten( $stored ) as $row ) {
			$have[] = $row['id'] . '>' . $row['parent'];
		}

		return $want === $have;
	}

	/**
	 * Error for a structure that did not store as intended.
	 *
	 * @param WP_Term $menu   Menu term.
	 * @param int[]   $failed Items whose write failed.
	 * @return WP_Error
	 */
	public static function structure_error( WP_Term $menu, array $failed ) {
		return Site_Support::error(
			'mosmcp_menu_order_not_saved',
			empty( $failed )
				? sprintf(
					/* translators: %s: menu name. */
					__( 'The order of the menu "%s" did not read back as intended after saving; another plugin may have changed it. Read the menu with mosmcp/menu-get and check it before trying again.', 'mosmcp-abilities' ),
					self::name_of( $menu )
				)
				: sprintf(
					/* translators: 1: menu name, 2: comma-separated item IDs. */
					__( 'WordPress could not save the new place of these items in the menu "%1$s": %2$s. Read the menu with mosmcp/menu-get to see where everything is now before trying again.', 'mosmcp-abilities' ),
					self::name_of( $menu ),
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

	/**
	 * Works out whether an item's link target still exists and is public.
	 *
	 * @param object $item Set-up menu item.
	 * @return array{status:string, shown:bool, reason:string}
	 */
	public static function linked_state( $item ) {
		$type   = (string) $item->type;
		$object = (string) $item->object;
		$shown  = 'publish' === $item->post_status && empty( $item->_invalid );

		if ( 'post_type' === $type ) {
			if ( ! post_type_exists( $object ) ) {
				return array(
					'status' => 'missing',
					'shown'  => false,
					'reason' => 'type_missing',
				);
			}

			$post = get_post( (int) $item->object_id );

			return array(
				'status' => $post ? (string) $post->post_status : 'missing',
				'shown'  => $shown,
				'reason' => '',
			);
		}

		if ( 'taxonomy' === $type ) {
			$term = taxonomy_exists( $object ) ? get_term( (int) $item->object_id, $object ) : null;
			$ok   = $term instanceof WP_Term;

			return array(
				'status' => $ok ? 'ok' : 'missing',
				'shown'  => $shown,
				'reason' => taxonomy_exists( $object ) ? '' : 'type_missing',
			);
		}

		if ( 'post_type_archive' === $type ) {
			$type_object = get_post_type_object( $object );
			$ok          = $type_object && ( 'post' === $object || ! empty( $type_object->has_archive ) );

			return array(
				'status' => $ok ? 'ok' : 'missing',
				'shown'  => $shown,
				'reason' => $type_object ? '' : 'type_missing',
			);
		}

		return array(
			'status' => '',
			'shown'  => $shown,
			'reason' => '',
		);
	}

	/**
	 * Adds the warnings an item's state deserves.
	 *
	 * @param object                            $item     Set-up menu item.
	 * @param array<string, mixed>              $state    Result of linked_state().
	 * @param array<int, array<string, string>> $warnings Warnings accumulator.
	 * @return void
	 */
	public static function item_warnings( $item, array $state, array &$warnings ) {
		$id      = (int) $item->ID;
		$label   = self::plain( $item->title );
		$context = 'item ' . $id;

		if ( 'draft' === $item->post_status ) {
			$warnings[] = self::warning(
				'item_not_saved',
				sprintf(
					/* translators: 1: menu item label, 2: menu item ID. */
					__( 'The item "%1$s" (ID %2$d) was added in Appearance > Menus but the menu was never saved there, so it is still a draft and visitors do not see it. These abilities keep it a draft; it appears once someone clicks Save Menu in Appearance > Menus, or it can be removed with mosmcp/menu-remove-item.', 'mosmcp-abilities' ),
					$label,
					$id
				),
				$context
			);
		}

		switch ( $state['status'] ) {
			case 'missing':
				if ( 'type_missing' === $state['reason'] ) {
					$message = sprintf(
						/* translators: 1: menu item label, 2: menu item ID, 3: post type or taxonomy name. */
						__( 'The item "%1$s" (ID %2$d) links to content of type "%3$s", which no longer exists on this site (usually because the plugin that provided it is switched off). WordPress hides the item from visitors. Switch that plugin back on, or remove the item with mosmcp/menu-remove-item.', 'mosmcp-abilities' ),
						$label,
						$id,
						(string) $item->object
					);
				} else {
					$message = sprintf(
						/* translators: 1: menu item label, 2: menu item ID. */
						__( 'The item "%1$s" (ID %2$d) links to something that no longer exists, so WordPress hides it from visitors. Remove it with mosmcp/menu-remove-item, or point it at another page with mosmcp/menu-update-item.', 'mosmcp-abilities' ),
						$label,
						$id
					);
				}
				$warnings[] = self::warning( 'link_target_missing', $message, $context );
				break;
			case 'trash':
				$warnings[] = self::warning(
					'link_target_trashed',
					sprintf(
						/* translators: 1: menu item label, 2: menu item ID. */
						__( 'The item "%1$s" (ID %2$d) links to a page that is in the trash, so WordPress hides it from visitors. Restore the page to bring the item back, or remove the item.', 'mosmcp-abilities' ),
						$label,
						$id
					),
					$context
				);
				break;
			case 'draft':
			case 'pending':
			case 'auto-draft':
			case 'future':
			case 'private':
				$warnings[] = self::warning(
					'link_target_not_public',
					sprintf(
						/* translators: 1: menu item label, 2: menu item ID, 3: post status such as draft or private. */
						__( 'The item "%1$s" (ID %2$d) is still shown in the menu, but it links to a page whose status is "%3$s", so visitors who click it reach a page they are not allowed to see ("not found"). Publish the page, or remove the item.', 'mosmcp-abilities' ),
						$label,
						$id,
						(string) $state['status']
					),
					$context
				);
				break;
		}

		if ( 'custom' === $item->type ) {
			if ( '' === trim( (string) $item->url ) ) {
				$warnings[] = self::warning(
					'custom_link_empty',
					sprintf(
						/* translators: 1: menu item label, 2: menu item ID. */
						__( 'The custom link "%1$s" (ID %2$d) has no address, so it goes nowhere. Give it one with mosmcp/menu-update-item.', 'mosmcp-abilities' ),
						$label,
						$id
					),
					$context
				);
			}

			if ( '' === trim( (string) $item->post_title ) ) {
				$warnings[] = self::warning(
					'custom_link_unlabelled',
					sprintf(
						/* translators: %d: menu item ID. */
						__( 'The custom link with ID %d has no label, so it shows as an empty space in the menu. Give it one with mosmcp/menu-update-item.', 'mosmcp-abilities' ),
						$id
					),
					$context
				);
			}
		}
	}

	/**
	 * Describes one menu item in the flat shape (no children).
	 *
	 * @param object               $item Set-up menu item.
	 * @param array<string, int>   $row  Structure row: parent, depth, position.
	 * @param array<string, mixed> $state Result of linked_state().
	 * @return array<string, mixed>
	 */
	public static function describe_item( $item, array $row, array $state ) {
		$type      = (string) $item->type;
		$classes   = array_values( array_filter( array_map( 'strval', (array) $item->classes ), 'strlen' ) );
		$is_linked = in_array( $type, array( 'post_type', 'taxonomy', 'post_type_archive' ), true );

		return array(
			'item_id'           => (int) $item->ID,
			'label'             => self::plain( $item->title ),
			'uses_linked_title' => $is_linked && '' === (string) $item->post_title,
			'type'              => $type,
			'type_label'        => isset( $item->type_label ) ? (string) $item->type_label : '',
			'object'            => (string) $item->object,
			'object_id'         => in_array( $type, array( 'post_type', 'taxonomy' ), true ) ? (int) $item->object_id : 0,
			'url'               => (string) $item->url,
			'open_in_new_tab'   => '_blank' === (string) $item->target,
			'css_classes'       => $classes,
			'description'       => trim( (string) $item->post_content ),
			'title_attribute'   => (string) $item->post_excerpt,
			'link_rel'          => (string) $item->xfn,
			'parent_item_id'    => (int) $row['parent'],
			'position'          => (int) $row['position'],
			'depth'             => (int) $row['depth'],
			'linked_status'     => (string) $state['status'],
			'shown_to_visitors' => (bool) $state['shown'],
		);
	}

	/**
	 * Builds the nested item tree, collecting item warnings on the way.
	 *
	 * @param array<int, object>                $items     Items keyed by ID.
	 * @param array<string, mixed>              $structure Structure.
	 * @param array<int, array<string, string>> $warnings  Warnings accumulator.
	 * @return array<int, array<string, mixed>>
	 */
	public static function tree( array $items, array $structure, array &$warnings ) {
		$rows = array();

		foreach ( self::flatten( $structure ) as $row ) {
			$rows[ $row['id'] ] = $row;
		}

		return self::tree_level( $items, $structure, $rows, 0, $warnings );
	}

	/**
	 * Recursive step of tree().
	 *
	 * @param array<int, object>                $items     Items keyed by ID.
	 * @param array<string, mixed>              $structure Structure.
	 * @param array<int, array<string, int>>    $rows      Structure rows keyed by ID.
	 * @param int                               $parent_id    Parent item ID.
	 * @param array<int, array<string, string>> $warnings  Warnings accumulator.
	 * @return array<int, array<string, mixed>>
	 */
	private static function tree_level( array $items, array $structure, array $rows, $parent_id, array &$warnings ) {
		$out = array();

		if ( empty( $structure['children'][ $parent_id ] ) ) {
			return $out;
		}

		foreach ( $structure['children'][ $parent_id ] as $id ) {
			if ( ! isset( $items[ $id ], $rows[ $id ] ) ) {
				continue;
			}

			$state = self::linked_state( $items[ $id ] );
			self::item_warnings( $items[ $id ], $state, $warnings );

			$node             = self::describe_item( $items[ $id ], $rows[ $id ], $state );
			$node['children'] = self::tree_level( $items, $structure, $rows, (int) $id, $warnings );
			$out[]            = $node;
		}

		return $out;
	}

	/**
	 * The whole menu as a compact top-to-bottom outline, for confirming a change.
	 *
	 * @param array<int, object>   $items     Items keyed by ID.
	 * @param array<string, mixed> $structure Structure.
	 * @return array<int, array<string, mixed>>
	 */
	public static function outline( array $items, array $structure ) {
		$out = array();

		foreach ( self::flatten( $structure ) as $row ) {
			if ( ! isset( $items[ $row['id'] ] ) ) {
				continue;
			}

			$out[] = array(
				'item_id'        => (int) $row['id'],
				'label'          => self::plain( $items[ $row['id'] ]->title ),
				'parent_item_id' => (int) $row['parent'],
				'position'       => (int) $row['position'],
				'depth'          => (int) $row['depth'],
			);
		}

		return $out;
	}

	/**
	 * Finds one item's structure row.
	 *
	 * @param array<string, mixed> $structure Structure.
	 * @param int                  $id        Item ID.
	 * @return array<string, int>
	 */
	public static function row_of( array $structure, $id ) {
		foreach ( self::flatten( $structure ) as $row ) {
			if ( (int) $row['id'] === (int) $id ) {
				return $row;
			}
		}

		return array(
			'id'       => (int) $id,
			'parent'   => 0,
			'depth'    => 0,
			'position' => 0,
		);
	}

	/**
	 * Plain-text version of a label for reporting.
	 *
	 * @param mixed $label Label as stored, possibly with markup or entities.
	 * @return string
	 */
	public static function plain( $label ) {
		return trim( html_entity_decode( wp_strip_all_tags( (string) $label ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}

	/**
	 * Applies the library's markup rule to a menu label.
	 *
	 * A label is shown as HTML on the site, so it gets the same treatment as any
	 * other markup the user could type: stored as given for users who may post
	 * unfiltered HTML, refused (not silently gutted) for anyone else whose label
	 * the library's markup rule or WordPress's own title filters would change.
	 * After this check passes, saving the label cannot alter it, which is what
	 * lets an update write the title directly.
	 *
	 * @param string                            $label    Label as supplied.
	 * @param array<int, array<string, string>> $warnings Warnings accumulator.
	 * @return string|WP_Error
	 */
	public static function clean_label( $label, array &$warnings ) {
		$label = trim( (string) $label );

		if ( false !== strpos( $label, '<' ) ) {
			$result = Html_Policy::apply( $label, Html_Policy::restriction(), __( 'a menu label', 'mosmcp-abilities' ), $warnings );

			if ( is_wp_error( $result ) ) {
				return Site_Support::error(
					'mosmcp_menu_label_markup',
					$result->get_error_message(),
					Site_Support::CAUSE_INVALID_INPUT,
					true
				);
			}

			$label = (string) $result;
		}

		// Exactly what WordPress would store as a post title for this account. A
		// difference in entity encoding only ("Q&A" kept as "Q&amp;A") is not a
		// change a visitor can see, so the encoded form is accepted and returned.
		$stored = trim( (string) wp_unslash( sanitize_post_field( 'post_title', wp_slash( $label ), 0, 'db' ) ) );

		if ( html_entity_decode( $stored, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) === html_entity_decode( $label, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) {
			return $stored;
		}

		if ( $stored !== $label ) {
			return Site_Support::error(
				'mosmcp_menu_label_markup',
				sprintf(
					/* translators: 1: label supplied, 2: label as WordPress would store it. */
					__( 'Nothing was saved: WordPress would store this label as "%2$s" instead of "%1$s", because this account may not use that markup in titles. Use a plain-text label, or ask someone allowed to post unfiltered HTML to set it.', 'mosmcp-abilities' ),
					$label,
					$stored
				),
				Site_Support::CAUSE_CAPABILITY,
				false,
				array( 'would_store' => $stored )
			);
		}

		return $label;
	}

	/**
	 * Validates and normalises an address for a custom link.
	 *
	 * Accepts http and https addresses, mailto: and tel: links, in-page anchors
	 * ("#contact"), and paths on this site ("/about", "?s=x"). A bare domain such
	 * as "example.com" gets https:// added (and says so), because core would
	 * otherwise turn it into "http://example.com" while turning a word such as
	 * "contact" into the broken "http://contact". Anything with another scheme is
	 * refused by name, including obfuscated forms like "Java&#83;cript:".
	 *
	 * @param mixed                             $raw      Address as supplied.
	 * @param array<int, array<string, string>> $warnings Warnings accumulator.
	 * @return string|WP_Error
	 */
	public static function clean_url( $raw, array &$warnings ) {
		$url = trim( (string) $raw );

		if ( '' === $url ) {
			return Site_Support::error(
				'mosmcp_menu_url_empty',
				__( 'The link address is empty. Pass a full address such as "https://example.com/page", a path on this site such as "/contact", or an anchor such as "#top".', 'mosmcp-abilities' ),
				Site_Support::CAUSE_INVALID_INPUT,
				true
			);
		}

		// Look at the address the way a browser will: entities decoded, and the
		// whitespace and control characters browsers ignore inside a scheme removed.
		$probe = (string) preg_replace( '/[\x00-\x20\x7F]+/', '', html_entity_decode( $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );

		if ( preg_match( '/^([a-z][a-z0-9+.\-]*):/i', $probe, $match ) && ! preg_match( '/^[a-z0-9.\-]+:\d+(?:[\/?#]|$)/i', $probe ) ) {
			$scheme = strtolower( $match[1] );

			if ( ! in_array( $scheme, self::ALLOWED_SCHEMES, true ) ) {
				return Site_Support::error(
					'mosmcp_menu_url_scheme',
					sprintf(
						/* translators: 1: the URL scheme found, 2: list of allowed schemes. */
						__( 'Refused: menu links may not use "%1$s:" addresses. Allowed are %2$s addresses, in-page anchors ("#section") and paths on this site ("/about").', 'mosmcp-abilities' ),
						$scheme,
						'http:, https:, mailto:, tel:'
					),
					Site_Support::CAUSE_INVALID_INPUT,
					false,
					array( 'scheme' => $scheme )
				);
			}
		} elseif ( ! preg_match( '#^(/|\#|\?|\./|\.\./)#', $probe ) ) {
			// No scheme and not a path: either a bare domain or a mistake.
			if ( preg_match( '/^(?:www\.)?[a-z0-9\-]+(?:\.[a-z0-9\-]+)+(?::\d+)?(?:[\/?#].*)?$/i', $probe ) ) {
				$url        = 'https://' . $url;
				$warnings[] = self::warning(
					'url_scheme_added',
					sprintf(
						/* translators: %s: the address as stored. */
						__( 'The address had no "https://" in front, so it was added: the link points to %s.', 'mosmcp-abilities' ),
						$url
					)
				);
			} else {
				return Site_Support::error(
					'mosmcp_menu_url_unclear',
					sprintf(
						/* translators: 1: the address supplied, 2: suggested path form. */
						__( '"%1$s" is neither a full address nor a path. Use a full address such as "https://example.com/%1$s", or a path on this site starting with a slash, such as "%2$s".', 'mosmcp-abilities' ),
						$url,
						'/' . ltrim( $url, '/' )
					),
					Site_Support::CAUSE_INVALID_INPUT,
					true
				);
			}
		}

		// Phone numbers are written with spaces and dashes; a tel: link wants digits.
		if ( 0 === stripos( $url, 'tel:' ) ) {
			$url = 'tel:' . (string) preg_replace( '/[^0-9+*#,;=pw]/i', '', substr( $url, 4 ) );
		}

		$clean = esc_url_raw( $url, self::ALLOWED_SCHEMES );

		if ( '' === $clean ) {
			return Site_Support::error(
				'mosmcp_menu_url_invalid',
				sprintf(
					/* translators: %s: the address supplied. */
					__( '"%s" is not a usable link address. Pass a full address such as "https://example.com/page", a path such as "/contact", or an anchor such as "#top".', 'mosmcp-abilities' ),
					$url
				),
				Site_Support::CAUSE_INVALID_INPUT,
				true
			);
		}

		if ( preg_match( '/^(mailto|tel):\s*$/i', $clean ) ) {
			return Site_Support::error(
				'mosmcp_menu_url_incomplete',
				__( 'The mailto: or tel: link has no address or number after the colon.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_INVALID_INPUT,
				true
			);
		}

		return $clean;
	}

	/**
	 * Suggests linking a page directly when a custom address is really a page here.
	 *
	 * A custom link to "/about" breaks the day the page's address changes; a page
	 * link follows it. This only advises; the custom link is still created.
	 *
	 * @param string                            $url      Cleaned address.
	 * @param array<int, array<string, string>> $warnings Warnings accumulator.
	 * @return void
	 */
	public static function suggest_page_link( $url, array &$warnings ) {
		if ( 0 === strpos( $url, '#' ) || 0 === stripos( $url, 'mailto:' ) || 0 === stripos( $url, 'tel:' ) ) {
			return;
		}

		$home_host = wp_parse_url( home_url(), PHP_URL_HOST );
		$host      = wp_parse_url( $url, PHP_URL_HOST );

		if ( null !== $host && false !== $host && strtolower( (string) $host ) !== strtolower( (string) $home_host ) ) {
			return;
		}

		$absolute = null === $host ? home_url( $url ) : $url;
		$post_id  = url_to_postid( $absolute );

		if ( $post_id > 0 && current_user_can( 'read_post', $post_id ) ) {
			$warnings[] = self::warning(
				'custom_link_to_own_page',
				sprintf(
					/* translators: 1: page title, 2: post ID. */
					__( 'This address is the page "%1$s" (ID %2$d) on this site. A custom link breaks if that page\'s address ever changes; linking the page itself with post_id %2$d keeps working.', 'mosmcp-abilities' ),
					self::safe_title( $post_id ),
					$post_id
				)
			);
		}
	}

	/**
	 * Plain title of a post, but only when the current user may read it.
	 *
	 * Error and summary messages name the post an ID points at. An ID can belong
	 * to any post type, including private records such as personal-data requests
	 * whose title is an email address, so the title is only included when the
	 * caller could read that post anyway.
	 *
	 * @param int|\WP_Post $post Post or post ID.
	 * @return string The title, or '' when it may not be shown.
	 */
	public static function safe_title( $post ) {
		$post = get_post( $post );

		if ( ! $post || ! current_user_can( 'read_post', (int) $post->ID ) ) {
			return '';
		}

		return self::plain( get_the_title( $post ) );
	}

	/**
	 * Normalises CSS class input to a list of safe class names.
	 *
	 * @param mixed                             $raw      Array of names, or a space-separated string.
	 * @param array<int, array<string, string>> $warnings Warnings accumulator.
	 * @return string[]
	 */
	public static function clean_classes( $raw, array &$warnings ) {
		$parts = is_array( $raw ) ? $raw : preg_split( '/[\s,]+/', (string) $raw );
		$out   = array();

		foreach ( (array) $parts as $part ) {
			if ( ! is_scalar( $part ) ) {
				continue;
			}

			foreach ( (array) preg_split( '/\s+/', trim( (string) $part ) ) as $piece ) {
				$piece = (string) $piece;
				if ( '' === $piece ) {
					continue;
				}

				$clean = sanitize_html_class( $piece );

				if ( $clean !== $piece ) {
					$warnings[] = self::warning(
						'css_class_changed',
						sprintf(
							/* translators: 1: class name supplied, 2: class name stored (may be empty). */
							__( 'The CSS class "%1$s" contains characters a class name cannot hold and was stored as "%2$s".', 'mosmcp-abilities' ),
							$piece,
							$clean
						)
					);
				}

				if ( '' !== $clean && ! in_array( $clean, $out, true ) ) {
					$out[] = $clean;
				}
			}
		}

		return $out;
	}

	/**
	 * Normalises a link-relationship value (for example "nofollow noopener").
	 *
	 * @param mixed $raw Value as supplied.
	 * @return string
	 */
	public static function clean_rel( $raw ) {
		$tokens = preg_split( '/[\s,]+/', strtolower( trim( (string) $raw ) ) );
		$out    = array();

		foreach ( (array) $tokens as $token ) {
			$token = sanitize_html_class( (string) $token );
			if ( '' !== $token && ! in_array( $token, $out, true ) ) {
				$out[] = $token;
			}
		}

		return implode( ' ', $out );
	}

	/**
	 * Resolves a post a menu or navigation link should point at.
	 *
	 * @param int $post_id Post ID.
	 * @return \WP_Post|WP_Error
	 */
	public static function link_post( $post_id ) {
		$post_id = absint( $post_id );
		$post    = $post_id > 0 ? get_post( $post_id ) : null;

		if ( ! $post ) {
			return Site_Support::error(
				'mosmcp_menu_post_not_found',
				sprintf(
					/* translators: %d: post ID supplied. */
					__( 'Nothing exists with ID %d, so there is nothing to link to. Use mosmcp/page-list-all or mosmcp/post-list-all to find the right ID.', 'mosmcp-abilities' ),
					$post_id
				),
				Site_Support::CAUSE_NOT_FOUND,
				false,
				array( 'use_instead' => 'mosmcp/page-list-all' )
			);
		}

		if ( 'nav_menu_item' === $post->post_type ) {
			return Site_Support::error(
				'mosmcp_menu_post_is_menu_item',
				sprintf(
					/* translators: %d: post ID supplied. */
					__( 'ID %d is a menu item, not a page or post. post_id must be the ID of the page or post the link should open; menu items are moved with mosmcp/menu-update-item or mosmcp/menu-reorder-items.', 'mosmcp-abilities' ),
					$post_id
				),
				Site_Support::CAUSE_INVALID_INPUT,
				true
			);
		}

		if ( ! is_post_type_viewable( $post->post_type ) || 'attachment' === $post->post_type ) {
			$type_object = get_post_type_object( $post->post_type );

			return Site_Support::error(
				'mosmcp_menu_post_not_linkable',
				sprintf(
					/* translators: 1: post ID, 2: post type label. */
					__( 'ID %1$d is a %2$s, which has no public page of its own, so a menu cannot link to it. Link a page, a post, or another public content type instead.', 'mosmcp-abilities' ),
					$post_id,
					$type_object ? (string) $type_object->labels->singular_name : (string) $post->post_type
				),
				Site_Support::CAUSE_INVALID_INPUT,
				false
			);
		}

		if ( in_array( $post->post_status, array( 'trash', 'auto-draft', 'inherit' ), true ) ) {
			return Site_Support::error(
				'mosmcp_menu_post_trashed',
				sprintf(
					/* translators: 1: post ID, 2: post status. */
					__( 'ID %1$d has the status "%2$s", and WordPress hides menu links to it from everyone. Restore or publish it first, then add the link.', 'mosmcp-abilities' ),
					$post_id,
					$post->post_status
				),
				Site_Support::CAUSE_CONFLICT,
				false
			);
		}

		// A draft, private or scheduled target is allowed; the link's own read-back
		// warns that visitors cannot open it yet.
		return $post;
	}

	/**
	 * Resolves a term a menu or navigation link should point at.
	 *
	 * @param int $term_id Term ID.
	 * @return WP_Term|WP_Error
	 */
	public static function link_term( $term_id ) {
		$term_id = absint( $term_id );
		$term    = $term_id > 0 ? get_term( $term_id ) : null;

		if ( ! $term instanceof WP_Term ) {
			return Site_Support::error(
				'mosmcp_menu_term_not_found',
				sprintf(
					/* translators: %d: term ID supplied. */
					__( 'No category, tag or other term exists with ID %d. Use mosmcp/category-list-all or mosmcp/tag-list-all to find the right ID.', 'mosmcp-abilities' ),
					$term_id
				),
				Site_Support::CAUSE_NOT_FOUND,
				false,
				array( 'use_instead' => 'mosmcp/category-list-all' )
			);
		}

		if ( ! is_taxonomy_viewable( $term->taxonomy ) || 'nav_menu' === $term->taxonomy ) {
			return Site_Support::error(
				'mosmcp_menu_term_not_linkable',
				sprintf(
					/* translators: 1: term ID, 2: taxonomy name. */
					__( 'Term %1$d belongs to "%2$s", which has no public archive page, so a menu cannot link to it.', 'mosmcp-abilities' ),
					$term_id,
					$term->taxonomy
				),
				Site_Support::CAUSE_INVALID_INPUT,
				false
			);
		}

		return $term;
	}

	/**
	 * Output schema for a menu.
	 *
	 * @return array<string, mixed>
	 */
	public static function menu_schema() {
		return Schema::object(
			array(
				'menu_id'        => Schema::int( __( 'Menu ID, accepted wherever a menu is asked for.', 'mosmcp-abilities' ) ),
				'name'           => Schema::str(),
				'slug'           => Schema::str(),
				'description'    => Schema::str(),
				'item_count'     => Schema::int( __( 'Published items in the menu.', 'mosmcp-abilities' ) ),
				'locations'      => Schema::arr( Schema::str(), __( 'Theme locations this menu is assigned to. Empty means the theme does not display it anywhere.', 'mosmcp-abilities' ) ),
				'auto_add_pages' => Schema::boolean( __( 'Whether WordPress adds every newly published top-level page to this menu automatically.', 'mosmcp-abilities' ) ),
			),
			array( 'menu_id', 'name' )
		);
	}

	/**
	 * Properties of a menu item in the flat shape.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function item_properties() {
		return array(
			'item_id'           => Schema::int( __( 'The menu item\'s own ID, which every other menu ability takes. It is not the ID of the linked page.', 'mosmcp-abilities' ) ),
			'label'             => Schema::str( __( 'Text shown in the menu.', 'mosmcp-abilities' ) ),
			'uses_linked_title' => Schema::boolean( __( 'True when the item has no label of its own and shows the linked page or category title, so renaming that page renames the item too.', 'mosmcp-abilities' ) ),
			'type'              => Schema::str( __( '"post_type" (a page, post or other content), "taxonomy" (a category, tag or other term), "post_type_archive", or "custom" (a typed address).', 'mosmcp-abilities' ) ),
			'type_label'        => Schema::str( __( 'What wp-admin calls it, for example "Page", "Category" or "Custom Link".', 'mosmcp-abilities' ) ),
			'object'            => Schema::str( __( 'The linked content type or taxonomy, for example "page", "post" or "category"; "custom" for custom links.', 'mosmcp-abilities' ) ),
			'object_id'         => Schema::int( __( 'ID of the linked page, post or term; 0 for custom links and archives.', 'mosmcp-abilities' ) ),
			'url'               => Schema::str(),
			'open_in_new_tab'   => Schema::boolean(),
			'css_classes'       => Schema::arr( Schema::str() ),
			'description'       => Schema::str( __( 'Item description; only some themes display it.', 'mosmcp-abilities' ) ),
			'title_attribute'   => Schema::str( __( 'Tooltip text shown when hovering the link.', 'mosmcp-abilities' ) ),
			'link_rel'          => Schema::str( __( 'Link relationship, for example "nofollow".', 'mosmcp-abilities' ) ),
			'parent_item_id'    => Schema::int( __( 'Item this one is nested under; 0 for the top level.', 'mosmcp-abilities' ) ),
			'position'          => Schema::int( __( '1-based position among the items that share the same parent.', 'mosmcp-abilities' ) ),
			'depth'             => Schema::int( __( '0 for top-level items, 1 for their children, and so on.', 'mosmcp-abilities' ) ),
			'linked_status'     => Schema::str( __( 'For page and post links, the linked item\'s status (publish, draft, private, trash...) or "missing"; for term and archive links "ok" or "missing"; empty for custom links.', 'mosmcp-abilities' ) ),
			'shown_to_visitors' => Schema::boolean( __( 'False when WordPress hides the item from visitors because what it links to is gone or trashed, or because the item itself was never saved.', 'mosmcp-abilities' ) ),
		);
	}

	/**
	 * Output schema for one menu item, flat.
	 *
	 * @return array<string, mixed>
	 */
	public static function item_schema() {
		return Schema::object( self::item_properties() );
	}

	/**
	 * Output schema for a menu item with its nested children.
	 *
	 * The schema spells out a few levels field by field; anything deeper is still
	 * returned in exactly the same shape, under a plain list.
	 *
	 * @param int $levels Levels of children to describe below this one.
	 * @return array<string, mixed>
	 */
	public static function tree_item_schema( $levels ) {
		$properties = self::item_properties();

		$properties['children'] = $levels > 0
			? Schema::arr( self::tree_item_schema( $levels - 1 ), __( 'Items nested under this one, in order.', 'mosmcp-abilities' ) )
			: array(
				'type'        => 'array',
				'description' => __( 'Items nested under this one, in the same shape as their parent.', 'mosmcp-abilities' ),
			);

		return Schema::object( $properties );
	}

	/**
	 * Output schema for the compact outline returned after every menu change.
	 *
	 * @return array<string, mixed>
	 */
	public static function outline_schema() {
		return Schema::arr(
			Schema::object(
				array(
					'item_id'        => Schema::int(),
					'label'          => Schema::str(),
					'parent_item_id' => Schema::int(),
					'position'       => Schema::int(),
					'depth'          => Schema::int(),
				)
			),
			__( 'The whole menu after the change, top to bottom, each child right after its parent.', 'mosmcp-abilities' )
		);
	}

	/**
	 * Output schema for one row of a Navigation block's link list.
	 *
	 * @return array<string, mixed>
	 */
	public static function nav_block_schema() {
		return Schema::object(
			array(
				'path'             => Schema::str( __( 'Address of this block: "0" is the first top-level entry, "2.1" the second entry inside the third. Pass it to the navigation edit abilities.', 'mosmcp-abilities' ) ),
				'depth'            => Schema::int(),
				'block'            => Schema::str( __( 'Block type, for example core/navigation-link, core/navigation-submenu, core/page-list.', 'mosmcp-abilities' ) ),
				'label'            => Schema::str(),
				'url'              => Schema::str(),
				'kind'             => Schema::str( __( '"post-type", "taxonomy" or "custom" for links; empty otherwise.', 'mosmcp-abilities' ) ),
				'type'             => Schema::str( __( 'Linked content type or taxonomy, for example "page" or "category".', 'mosmcp-abilities' ) ),
				'linked_id'        => Schema::int( __( 'ID of the linked page, post or term; 0 when none.', 'mosmcp-abilities' ) ),
				'opens_in_new_tab' => Schema::boolean(),
				'description'      => Schema::str(),
				'title_attribute'  => Schema::str(),
				'rel'              => Schema::str(),
				'linked_status'    => Schema::str( __( 'For page and post links, the linked item\'s status or "missing"; for term links "ok" or "missing"; empty otherwise.', 'mosmcp-abilities' ) ),
				'child_count'      => Schema::int(),
				'editable'         => Schema::boolean( __( 'True for links and submenus, which the navigation edit abilities can change.', 'mosmcp-abilities' ) ),
				'summary'          => Schema::str( __( 'Plain-language description of what this entry shows.', 'mosmcp-abilities' ) ),
			)
		);
	}

	/**
	 * Output schema for one place a Navigation menu is displayed.
	 *
	 * @return array<string, mixed>
	 */
	public static function nav_usage_schema() {
		return Schema::object(
			array(
				'where'             => Schema::str( __( '"template_part" (a header, footer or similar shared section) or "template".', 'mosmcp-abilities' ) ),
				'slug'              => Schema::str(),
				'title'             => Schema::str(),
				'area'              => Schema::str( __( 'Template part area, for example "header" or "footer".', 'mosmcp-abilities' ) ),
				'used_by_templates' => Schema::arr( Schema::str(), __( 'Templates that include this template part. Empty means the part is not currently shown anywhere.', 'mosmcp-abilities' ) ),
				'via'               => Schema::str( __( '"ref" when the Navigation block names this menu; "fallback" when the block names no menu and WordPress shows this one because it is the newest published menu.', 'mosmcp-abilities' ) ),
			)
		);
	}

	/**
	 * Output schema for a Navigation menu's identity after reads and writes.
	 *
	 * @return array<string, mixed>
	 */
	public static function nav_summary_schema() {
		return Schema::object(
			array(
				'navigation_id' => Schema::int(),
				'title'         => Schema::str(),
				'status'        => Schema::str(),
				'modified'      => Schema::str( __( 'Last-modified time; pass it as expected_modified to the next edit.', 'mosmcp-abilities' ) ),
				'link_count'    => Schema::int(),
				'used_in'       => Schema::arr( self::nav_usage_schema() ),
			),
			array( 'navigation_id', 'modified' )
		);
	}
}
