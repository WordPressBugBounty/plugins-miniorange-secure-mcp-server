<?php
/**
 * Execute callbacks for the block-theme Navigation abilities of the Menus &
 * Navigation pack.
 *
 * A block theme's header and footer show a core/navigation block. That block
 * either points at a wp_navigation post through its "ref" attribute, or, with no
 * ref, falls back to the newest published wp_navigation post or a plain list of
 * pages. The links themselves live in that post's content as dynamic blocks:
 * comment delimiters carrying JSON attributes and nothing else. Editing them is
 * therefore block surgery on post content, done here with the same parser and
 * serializer the editor uses, verified by re-reading after every save.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Menus;

use MoSMCP\Abilities\Packs\Site\Site_Support;
use WP_Error;
use WP_Post;
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
 * Class Navigation_Provider
 *
 * Static execute callbacks for the wp_navigation abilities.
 */
class Navigation_Provider {

	/**
	 * Post type holding block-theme navigation menus.
	 */
	const POST_TYPE = 'wp_navigation';

	/**
	 * Block names.
	 */
	const LINK      = 'core/navigation-link';
	const SUBMENU   = 'core/navigation-submenu';
	const PAGE_LIST = 'core/page-list';
	const HOME_LINK = 'core/home-link';

	/**
	 * Statuses a navigation post can be listed, read and edited in.
	 *
	 * @var string[]
	 */
	const LIVE_STATUSES = array( 'publish', 'draft', 'pending', 'private', 'future' );

	/**
	 * Nesting the Navigation block lets people edit in the editor by default.
	 */
	const EDITOR_MAX_NESTING = 5;

	/**
	 * Most pages an automatic page list may be converted into links at once.
	 */
	const MAX_PAGE_LIST_CONVERSION = 100;

	/**
	 * Walk guard for nested blocks and patterns.
	 */
	const MAX_WALK_DEPTH = 12;

	/**
	 * Per-request cache of where each navigation is displayed.
	 *
	 * @var array<string, mixed>|null
	 */
	private static $usage = null;

	/**
	 * Number of non-trashed navigation menus.
	 *
	 * @return int
	 */
	public static function count_navigations() {
		if ( ! post_type_exists( self::POST_TYPE ) ) {
			return 0;
		}

		$counts = wp_count_posts( self::POST_TYPE );
		$total  = 0;

		foreach ( self::LIVE_STATUSES as $status ) {
			$total += isset( $counts->$status ) ? (int) $counts->$status : 0;
		}

		return $total;
	}

	/**
	 * Lists every navigation menu and where the theme displays it.
	 *
	 * @param array<string, mixed> $input Ability input. Unused.
	 * @return array<string, mixed>
	 */
	public static function navigation_list( $input = array() ) {
		unset( $input );

		$posts = get_posts(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => self::LIVE_STATUSES,
				'numberposts' => 100,
				'orderby'     => 'date',
				'order'       => 'DESC',
			)
		);

		$usage    = self::usage();
		$warnings = $usage['warnings'];
		$notes    = array();
		$rows     = array();

		foreach ( $posts as $post ) {
			$summary                    = self::summary( $post );
			$summary['top_level_count'] = count( self::real_keys( parse_blocks( (string) $post->post_content ) ) );
			$summary['is_fallback']     = (int) $usage['fallback_id'] === (int) $post->ID;
			$rows[]                     = $summary;
		}

		$is_block = function_exists( 'wp_is_block_theme' ) && wp_is_block_theme();

		if ( ! $is_block ) {
			$notes[] = __( 'The active theme is a classic theme. It displays classic menus in its menu locations; Navigation menus only appear where someone placed a Navigation block. Use mosmcp/menu-list for the classic menus.', 'mosmcp-abilities' );
		}

		if ( empty( $posts ) ) {
			$notes[] = __( 'There are no Navigation menus yet. A Navigation block with no menu shows a plain list of published pages until one is created in the Site Editor.', 'mosmcp-abilities' );
		}

		// Explain the fallback once, using the most visible place it applies to.
		$fallback_rows = array();
		$inline_rows   = array();

		foreach ( $usage['unbound'] as $unbound ) {
			if ( 'fallback' === $unbound['shows'] ) {
				$fallback_rows[] = $unbound;
			} elseif ( ! empty( $unbound['used_by_templates'] ) || 'template' === $unbound['where'] ) {
				$inline_rows[ $unbound['title'] ] = true;
			}
		}

		usort(
			$fallback_rows,
			static function ( $a, $b ) {
				return count( $b['used_by_templates'] ) <=> count( $a['used_by_templates'] );
			}
		);

		if ( ! empty( $fallback_rows ) ) {
			$unbound = $fallback_rows[0];
			$notes[] = $unbound['fallback_navigation_id'] > 0
				? sprintf(
					/* translators: 1: template part or template title, 2: navigation ID. */
					__( 'The Navigation block in "%1$s" names no menu, so it shows the newest published Navigation menu, which is currently ID %2$d. Editing that menu changes what visitors see there; publishing a newer Navigation menu would silently replace it. To pin it, choose the menu on that block in the Site Editor.', 'mosmcp-abilities' ),
					$unbound['title'],
					$unbound['fallback_navigation_id']
				)
				: sprintf(
					/* translators: %s: template part or template title. */
					__( 'The Navigation block in "%s" names no menu and no Navigation menu is published, so it shows an automatic list of published pages.', 'mosmcp-abilities' ),
					$unbound['title']
				);
		}

		if ( ! empty( $inline_rows ) ) {
			$notes[] = sprintf(
				/* translators: %s: comma-separated template part titles. */
				__( 'The Navigation blocks in %s keep their links inside the template part itself rather than in a Navigation menu, so the navigation abilities cannot reach them. Change them with the template-part abilities (mosmcp/theme-part-get, mosmcp/theme-part-update) or in the Site Editor.', 'mosmcp-abilities' ),
				'"' . implode( '", "', array_keys( $inline_rows ) ) . '"'
			);
		}

		if ( ! empty( $rows ) ) {
			$notes[] = __( 'Read one with mosmcp/navigation-get to see its links and their paths, then edit with mosmcp/navigation-add-link, mosmcp/navigation-update-link or mosmcp/navigation-remove-link.', 'mosmcp-abilities' );
		}

		return array(
			'is_block_theme' => (bool) $is_block,
			'navigations'    => $rows,
			'unbound_blocks' => $usage['unbound'],
			'warnings'       => $warnings,
			'notes'          => $notes,
		);
	}

	/**
	 * Returns one navigation menu's entries with their paths.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function navigation_get( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$post  = self::resolve( isset( $input['navigation'] ) ? $input['navigation'] : '', false );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$blocks   = parse_blocks( (string) $post->post_content );
		$warnings = array();
		$rows     = array();
		self::describe_level( $blocks, '', 0, $rows, $warnings );

		$notes   = array( __( 'path addresses an entry: "0" is the first top-level entry, "2.1" the second entry inside the third. Paths shift when entries are added or removed, so read again after each change.', 'mosmcp-abilities' ) );
		$summary = self::summary( $post );

		if ( 'trash' === $post->post_status ) {
			$notes[] = __( 'This navigation menu is in the trash. It cannot be edited until it is restored in the Site Editor.', 'mosmcp-abilities' );
		} elseif ( 'publish' !== $post->post_status ) {
			$notes[] = sprintf(
				/* translators: %s: post status. */
				__( 'This navigation menu\'s status is "%s". Navigation blocks that point to it show the fallback navigation to visitors until it is published.', 'mosmcp-abilities' ),
				$post->post_status
			);
		}

		if ( empty( $summary['used_in'] ) ) {
			$notes[] = __( 'No header, footer or template of the active theme shows this navigation menu, so changing it changes nothing visitors see.', 'mosmcp-abilities' );
		}

		if ( empty( $rows ) ) {
			$notes[] = __( 'This navigation menu is empty. Where it is used, WordPress shows nothing (or the fallback). Add links with mosmcp/navigation-add-link.', 'mosmcp-abilities' );
		}

		$notes[] = __( 'Every edit saves a revision, so changes to a Navigation menu can be undone with mosmcp/navigation-restore-revision.', 'mosmcp-abilities' );

		return array(
			'navigation' => $summary,
			'blocks'     => $rows,
			'warnings'   => $warnings,
			'notes'      => $notes,
		);
	}

	/**
	 * Adds a link to a navigation menu.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function navigation_add_link( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$post  = self::writable( $input );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$warnings = array();
		$notes    = array();
		$target   = self::link_target( $input, $warnings, true );

		if ( is_wp_error( $target ) ) {
			return $target;
		}

		$label = isset( $input['label'] ) ? trim( (string) $input['label'] ) : '';

		if ( '' === $label ) {
			$label = $target['default_label'];
		}

		if ( '' === $label ) {
			return Site_Support::error(
				'mosmcp_navigation_label_required',
				__( 'A link needs a label: the text shown in the navigation. Pass label, for example "Contact us". WordPress does not display links without one.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_INVALID_INPUT,
				true
			);
		}

		$attrs = array_merge( array( 'label' => self::label_markup( $label ) ), $target['attrs'] );
		$attrs = self::apply_display_attrs( $attrs, $input );
		$new   = self::make_block( self::LINK, $attrs );

		$blocks      = parse_blocks( (string) $post->post_content );
		$parent_keys = array();
		$parent_path = isset( $input['parent_path'] ) ? trim( (string) $input['parent_path'] ) : '';
		$converted   = false;
		$located     = array();

		if ( '' !== $parent_path ) {
			$located = self::locate( $blocks, $parent_path );
			if ( is_wp_error( $located ) ) {
				return $located;
			}

			$parent_path = $located['path'];
			$parent_name = (string) $located['block']['blockName'];
			if ( ! in_array( $parent_name, array( self::LINK, self::SUBMENU ), true ) ) {
				return Site_Support::error(
					'mosmcp_navigation_parent_not_menu',
					sprintf(
						/* translators: 1: path, 2: block name. */
						__( 'The entry at path %1$s is a %2$s, which cannot hold links. parent_path must point at a link (it becomes a dropdown) or an existing submenu. Omit parent_path to add at the top level.', 'mosmcp-abilities' ),
						$parent_path,
						$parent_name
					),
					Site_Support::CAUSE_INVALID_INPUT,
					true
				);
			}

			$converted   = self::LINK === $parent_name;
			$parent_keys = $located['keys'];
		}

		$siblings = '' === $parent_path ? self::real_keys( $blocks ) : self::real_keys( isset( $located['block']['innerBlocks'] ) ? $located['block']['innerBlocks'] : array() );
		$count    = count( $siblings );
		$index    = isset( $input['index'] ) ? (int) $input['index'] : $count;

		if ( $index < 0 ) {
			return Site_Support::error(
				'mosmcp_navigation_index_invalid',
				__( 'index counts from 0 (the first place). Omit it to add the link at the end.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_INVALID_INPUT,
				true
			);
		}

		if ( $index > $count ) {
			$notes[] = sprintf(
				/* translators: 1: requested index, 2: actual index. */
				__( 'Index %1$d is past the end of that level, so the link was added last, at index %2$d.', 'mosmcp-abilities' ),
				$index,
				$count
			);
			$index = $count;
		}

		self::duplicate_warning( $blocks, $attrs, $warnings );

		$blocks = self::edit_level(
			$blocks,
			$parent_keys,
			static function ( array $children, $holder ) use ( $new, $index ) {
				if ( is_array( $holder ) && self::LINK === $holder['blockName'] ) {
					$holder['blockName'] = self::SUBMENU;
				}

				$real = self::real_keys( $children );
				$at   = isset( $real[ $index ] ) ? $real[ $index ] : count( $children );
				array_splice( $children, $at, 0, array( $new ) );

				return array( $children, $holder );
			}
		);

		$new_path = '' === $parent_path ? (string) $index : $parent_path . '.' . $index;
		$depth    = substr_count( $new_path, '.' );

		if ( $depth >= self::EDITOR_MAX_NESTING ) {
			$warnings[] = Menus_Support::warning(
				'deeply_nested',
				sprintf(
					/* translators: %d: nesting depth. */
					__( 'The link is nested %d levels deep. The Site Editor only lets people edit Navigation links a few levels deep, so it may be hard to change by hand later.', 'mosmcp-abilities' ),
					$depth
				)
			);
		}

		if ( $converted ) {
			$notes[] = sprintf(
				/* translators: %s: parent path. */
				__( 'The link at path %s became a dropdown (submenu) holding the new link. It still links where it did.', 'mosmcp-abilities' ),
				$parent_path
			);
		}

		return self::finish_write( $post, $blocks, $new_path, $attrs, $warnings, $notes, array() );
	}

	/**
	 * Updates one link, or converts an automatic page list into editable links.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function navigation_update_link( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$post  = self::writable( $input );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$path    = isset( $input['path'] ) ? trim( (string) $input['path'] ) : '';
		$blocks  = parse_blocks( (string) $post->post_content );
		$located = self::locate( $blocks, $path );

		if ( is_wp_error( $located ) ) {
			return $located;
		}

		$path     = $located['path'];
		$block    = $located['block'];
		$name     = (string) $block['blockName'];
		$warnings = array();
		$notes    = array();

		if ( self::PAGE_LIST === $name ) {
			if ( ! Site_Support::bool_input( $input, 'convert_page_list', false ) ) {
				return Site_Support::error(
					'mosmcp_navigation_page_list',
					sprintf(
						/* translators: %s: path. */
						__( 'The entry at path %s is an automatic list of pages, not a single link, so it has no label or address to change. To control which pages appear and in what order, call again with convert_page_list set to true: it is replaced by one ordinary link per page, which can then be edited, reordered or removed individually.', 'mosmcp-abilities' ),
						$path
					),
					Site_Support::CAUSE_INVALID_INPUT,
					true
				);
			}

			return self::convert_page_list( $post, $blocks, $located, $path );
		}

		if ( ! in_array( $name, array( self::LINK, self::SUBMENU ), true ) ) {
			return Site_Support::error(
				'mosmcp_navigation_not_a_link',
				sprintf(
					/* translators: 1: path, 2: block name. */
					__( 'The entry at path %1$s is a %2$s, not a link, so it has no label or address to change here. Only links and submenus can be edited; it can be removed with mosmcp/navigation-remove-link.', 'mosmcp-abilities' ),
					$path,
					'' !== $name ? $name : 'piece of loose HTML'
				),
				Site_Support::CAUSE_INVALID_INPUT,
				false
			);
		}

		$keys = array( 'label', 'url', 'post_id', 'term_id', 'open_in_new_tab', 'description', 'title_attribute', 'rel' );
		$any  = false;

		foreach ( $keys as $key ) {
			if ( array_key_exists( $key, $input ) && null !== $input[ $key ] ) {
				$any = true;
				break;
			}
		}

		if ( ! $any ) {
			return Site_Support::error(
				'mosmcp_navigation_nothing_to_update',
				__( 'Nothing to change was passed. Pass at least one of: label, url, post_id, term_id, open_in_new_tab, description, title_attribute, rel.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_INVALID_INPUT,
				true
			);
		}

		$attrs  = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
		$before = $attrs;
		$target = self::link_target( $input, $warnings, false );

		if ( is_wp_error( $target ) ) {
			return $target;
		}

		$has_label = array_key_exists( 'label', $input ) && null !== $input['label'];

		if ( ! empty( $target['attrs'] ) ) {
			$old_label  = Menus_Support::plain( isset( $attrs['label'] ) ? $attrs['label'] : '' );
			$old_linked = self::linked_title( $attrs );
			$was_linked = isset( $attrs['kind'] ) && 'custom' !== $attrs['kind'] && ! empty( $attrs['id'] );

			unset( $attrs['id'], $attrs['type'] );
			$attrs = array_merge( $attrs, $target['attrs'] );

			if ( 'custom' === $target['attrs']['kind'] && $was_linked ) {
				$warnings[] = Menus_Support::warning(
					'converted_to_custom_link',
					sprintf(
						/* translators: %s: link label. */
						__( 'The link "%s" pointed at a page or category; it now points at the address given and will no longer follow that page if its address changes.', 'mosmcp-abilities' ),
						$old_label
					),
					'path ' . $path
				);
			}

			if ( ! $has_label && 'custom' !== $target['attrs']['kind'] ) {
				if ( '' !== $old_linked && $old_label === $old_linked && '' !== $target['default_label'] ) {
					$attrs['label'] = self::label_markup( $target['default_label'] );
					$notes[]        = __( 'The label followed the old page\'s title, so it was changed to the new page\'s title.', 'mosmcp-abilities' );
				} else {
					$notes[] = sprintf(
						/* translators: %s: label. */
						__( 'The link kept its label "%s". Pass label to change it too.', 'mosmcp-abilities' ),
						$old_label
					);
				}
			}
		}

		if ( $has_label ) {
			$label = trim( (string) $input['label'] );

			if ( '' === $label ) {
				return Site_Support::error(
					'mosmcp_navigation_label_required',
					__( 'A link cannot have an empty label: WordPress does not display links without one. To hide the link, remove it with mosmcp/navigation-remove-link.', 'mosmcp-abilities' ),
					Site_Support::CAUSE_INVALID_INPUT,
					true
				);
			}

			$attrs['label'] = self::label_markup( $label );
		}

		$attrs = self::apply_display_attrs( $attrs, $input );

		// A link to a page stores the page's address at the time it was made; refresh it.
		$fresh_url = self::current_url( $attrs );
		if ( '' !== $fresh_url && ( ! isset( $attrs['url'] ) || $attrs['url'] !== $fresh_url ) ) {
			$current_url = isset( $attrs['url'] ) ? $attrs['url'] : '';
			if ( isset( $before['url'] ) && $current_url === $before['url'] ) {
				$notes[] = sprintf(
					/* translators: %s: new address. */
					__( 'The stored address was out of date and was refreshed to %s.', 'mosmcp-abilities' ),
					$fresh_url
				);
			}
			$attrs['url'] = $fresh_url;
		}

		// Reported under the names the caller used, not the block attribute names.
		$names   = array(
			'id'            => 'link_target',
			'kind'          => 'link_target',
			'type'          => 'link_target',
			'opensInNewTab' => 'open_in_new_tab',
			'title'         => 'title_attribute',
		);
		$changed = array();
		foreach ( array_unique( array_merge( array_keys( $before ), array_keys( $attrs ) ) ) as $key ) {
			$old = isset( $before[ $key ] ) ? $before[ $key ] : null;
			$new = isset( $attrs[ $key ] ) ? $attrs[ $key ] : null;
			if ( $old !== $new ) {
				$changed[] = isset( $names[ $key ] ) ? $names[ $key ] : (string) $key;
			}
		}
		$changed = array_values( array_unique( $changed ) );

		if ( empty( $changed ) ) {
			$notes[] = __( 'The link already had those values, so nothing was saved.', 'mosmcp-abilities' );

			$rows = array();
			$ign  = array();
			self::describe_level( $blocks, '', 0, $rows, $ign );

			$unchanged = array(
				'navigation'           => self::summary( $post ),
				'path'                 => $path,
				'changed_fields'       => array(),
				'previous_revision_id' => 0,
				'revision_id'          => 0,
				'blocks'               => $rows,
				'warnings'             => $warnings,
				'notes'                => $notes,
			);
			$link      = self::row_for_path( $rows, $path );

			if ( null !== $link ) {
				$unchanged['link'] = $link;
			}

			return $unchanged;
		}

		$parent_keys = $located['keys'];
		$last        = array_pop( $parent_keys );

		$blocks = self::edit_level(
			$blocks,
			$parent_keys,
			static function ( array $children, $holder ) use ( $last, $attrs ) {
				$children[ $last ]['attrs'] = $attrs;
				return array( $children, $holder );
			}
		);

		return self::finish_write( $post, $blocks, $path, $attrs, $warnings, $notes, array( 'changed_fields' => $changed ) );
	}

	/**
	 * Removes an entry, moving a submenu's children up when asked.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function navigation_remove_link( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$post  = self::writable( $input );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$path    = isset( $input['path'] ) ? trim( (string) $input['path'] ) : '';
		$blocks  = parse_blocks( (string) $post->post_content );
		$located = self::locate( $blocks, $path );

		if ( is_wp_error( $located ) ) {
			return $located;
		}

		$path     = $located['path'];
		$block    = $located['block'];
		$keep     = Site_Support::bool_input( $input, 'keep_children', false );
		$inner    = isset( $block['innerBlocks'] ) ? $block['innerBlocks'] : array();
		$warnings = array();
		$notes    = array();

		if ( $keep && self::SUBMENU !== $block['blockName'] ) {
			return Site_Support::error(
				'mosmcp_navigation_keep_children_invalid',
				sprintf(
					/* translators: %s: path. */
					__( 'keep_children only applies to a submenu (a link with a dropdown). The entry at path %s is not one; call again without keep_children.', 'mosmcp-abilities' ),
					$path
				),
				Site_Support::CAUSE_INVALID_INPUT,
				true
			);
		}

		// Recorded before the change, so the result names exactly what went.
		$before_rows = array();
		$ignored     = array();
		self::describe_level( $blocks, '', 0, $before_rows, $ignored );
		$removed = array();

		foreach ( $before_rows as $row ) {
			if ( $row['path'] === $path || ( ! $keep && 0 === strpos( $row['path'], $path . '.' ) ) ) {
				$removed[] = $row;
			}
		}

		$parent_keys = $located['keys'];
		$last        = array_pop( $parent_keys );
		$emptied     = false;

		$blocks = self::edit_level(
			$blocks,
			$parent_keys,
			static function ( array $children, $holder ) use ( $last, $keep, $inner, &$emptied ) {
				array_splice( $children, $last, 1, $keep ? $inner : array() );

				// A dropdown with nothing left in it goes back to being a plain link.
				if ( is_array( $holder ) && self::SUBMENU === $holder['blockName'] && empty( self::real_keys( $children ) ) ) {
					$holder['blockName'] = self::LINK;
					$children            = array();
					$emptied             = true;
				}

				return array( $children, $holder );
			}
		);

		if ( $keep && ! empty( $inner ) ) {
			$notes[] = sprintf(
				/* translators: %d: number of entries. */
				_n( '%d entry from the removed submenu moved up into its place.', '%d entries from the removed submenu moved up into its place.', count( self::real_keys( $inner ) ), 'mosmcp-abilities' ),
				count( self::real_keys( $inner ) )
			);
		} elseif ( count( $removed ) > 1 ) {
			$notes[] = sprintf(
				/* translators: %d: number of entries. */
				_n( 'The submenu\'s %d child entry was removed with it.', 'The submenu\'s %d child entries were removed with it.', count( $removed ) - 1, 'mosmcp-abilities' ),
				count( $removed ) - 1
			);
		}

		if ( $emptied ) {
			$notes[] = __( 'Its parent dropdown had nothing left in it, so it became a plain link again.', 'mosmcp-abilities' );
		}

		return self::finish_write(
			$post,
			$blocks,
			'',
			array(),
			$warnings,
			$notes,
			array(
				'path'    => $path,
				'removed' => $removed,
			)
		);
	}

	/**
	 * Restores a navigation menu to an earlier revision.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function navigation_restore_revision( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$post  = self::writable( $input );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		if ( ! wp_revisions_enabled( $post ) ) {
			return Site_Support::error(
				'mosmcp_navigation_revisions_disabled',
				__( 'Revisions are switched off on this site (WP_POST_REVISIONS), so there is no earlier version to restore.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_HOST_ENVIRONMENT,
				false
			);
		}

		$revisions = wp_get_post_revisions( (int) $post->ID );
		$chosen    = null;

		if ( ! empty( $input['revision_id'] ) ) {
			// wp_get_post_revision() takes its argument by reference, so it must be a variable.
			$revision_id = absint( $input['revision_id'] );
			$candidate   = wp_get_post_revision( $revision_id );

			if ( ! $candidate || (int) $candidate->post_parent !== (int) $post->ID ) {
				$ids = array_slice( array_map( 'intval', array_keys( $revisions ) ), 0, 10 );

				return Site_Support::error(
					'mosmcp_navigation_revision_not_found',
					sprintf(
						/* translators: 1: revision ID supplied, 2: list of revision IDs. */
						__( 'Revision %1$d is not a revision of this navigation menu. Its most recent revisions are: %2$s.', 'mosmcp-abilities' ),
						absint( $input['revision_id'] ),
						empty( $ids ) ? __( 'none', 'mosmcp-abilities' ) : implode( ', ', $ids )
					),
					Site_Support::CAUSE_NOT_FOUND,
					false,
					array( 'revision_ids' => $ids )
				);
			}

			$chosen = $candidate;
		} else {
			foreach ( $revisions as $revision ) {
				if ( (string) $revision->post_content !== (string) $post->post_content ) {
					$chosen = $revision;
					break;
				}
			}
		}

		if ( ! $chosen ) {
			return Site_Support::error(
				'mosmcp_navigation_nothing_to_undo',
				__( 'No earlier revision differs from the current navigation menu, so there is nothing to undo.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_NOT_FOUND,
				false
			);
		}

		$previous = self::snapshot_revision( $post );
		$restored = wp_restore_post_revision( (int) $chosen->ID );

		if ( ! $restored || is_wp_error( $restored ) ) {
			return Site_Support::error(
				'mosmcp_navigation_restore_failed',
				__( 'WordPress did not restore the revision. Nothing reports why; try the Site Editor.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_WP_CORE,
				false
			);
		}

		clean_post_cache( (int) $post->ID );
		$fresh = get_post( (int) $post->ID );
		$rows  = array();
		$warns = array();
		self::describe_level( parse_blocks( (string) $fresh->post_content ), '', 0, $rows, $warns );

		$latest = self::latest_revision_id( $fresh );

		return array(
			'navigation'           => self::summary( $fresh ),
			'restored_revision_id' => (int) $chosen->ID,
			'restored_from'        => (string) $chosen->post_modified,
			'previous_revision_id' => $previous,
			'revision_id'          => $latest,
			'blocks'               => $rows,
			'warnings'             => $warns,
			'notes'                => array(
				sprintf(
					/* translators: %d: revision ID. */
					__( 'The version before this restore is kept as revision %d, so the restore can itself be undone by restoring that revision.', 'mosmcp-abilities' ),
					$previous
				),
			),
		);
	}

	/**
	 * Replaces an automatic page list with one ordinary link per page.
	 *
	 * @param WP_Post              $post    Navigation post.
	 * @param array<int, mixed>    $blocks  Parsed blocks.
	 * @param array<string, mixed> $located Result of locate() for the page list.
	 * @param string               $path    Path of the page list.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function convert_page_list( WP_Post $post, array $blocks, array $located, $path ) {
		$attrs     = isset( $located['block']['attrs'] ) ? $located['block']['attrs'] : array();
		$parent_id = isset( $attrs['parentPageID'] ) ? (int) $attrs['parentPageID'] : 0;
		$pages     = get_pages(
			array(
				'sort_column' => 'menu_order,post_title',
				'sort_order'  => 'asc',
				'post_status' => 'publish',
			)
		);

		if ( count( $pages ) > self::MAX_PAGE_LIST_CONVERSION ) {
			return Site_Support::error(
				'mosmcp_navigation_page_list_too_big',
				sprintf(
					/* translators: %d: number of published pages. */
					__( 'The page list currently shows %d pages, which is too many to turn into individual links sensibly. Remove the page list with mosmcp/navigation-remove-link and add the pages that matter with mosmcp/navigation-add-link instead.', 'mosmcp-abilities' ),
					count( $pages )
				),
				Site_Support::CAUSE_CONFLICT,
				false
			);
		}

		$by_parent = array();
		foreach ( $pages as $page ) {
			$by_parent[ (int) $page->post_parent ][] = $page;
		}

		$links = self::page_links( $by_parent, $parent_id, 0 );

		if ( empty( $links ) ) {
			return Site_Support::error(
				'mosmcp_navigation_page_list_empty',
				__( 'The page list currently shows no pages, so there is nothing to convert. Remove it with mosmcp/navigation-remove-link if it is not wanted.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_CONFLICT,
				false
			);
		}

		$parent_keys = $located['keys'];
		$last        = array_pop( $parent_keys );

		$blocks = self::edit_level(
			$blocks,
			$parent_keys,
			static function ( array $children, $holder ) use ( $last, $links ) {
				array_splice( $children, $last, 1, $links );
				return array( $children, $holder );
			}
		);

		$notes = array(
			sprintf(
				/* translators: 1: number of links, 2: path. */
				__( 'The automatic page list at path %2$s was replaced by %1$d ordinary links, one per published page, in the same order and nesting. New pages will no longer be added automatically; each link can now be edited, moved or removed on its own.', 'mosmcp-abilities' ),
				count( $links ),
				$path
			),
		);
		$warns = array();

		return self::finish_write( $post, $blocks, $path, array(), $warns, $notes, array( 'changed_fields' => array( 'page_list_converted' ) ) );
	}

	/**
	 * Builds link blocks for a branch of the page tree, as the page list shows it.
	 *
	 * @param array<int, WP_Post[]> $by_parent Published pages grouped by parent ID.
	 * @param int                   $parent_id    Parent page ID.
	 * @param int                   $depth     Recursion depth.
	 * @return array<int, array<string, mixed>>
	 */
	private static function page_links( array $by_parent, $parent_id, $depth ) {
		$out = array();

		if ( empty( $by_parent[ $parent_id ] ) || $depth > self::MAX_WALK_DEPTH ) {
			return $out;
		}

		foreach ( $by_parent[ $parent_id ] as $page ) {
			$attrs = array(
				'label' => self::label_markup( Menus_Support::plain( $page->post_title ) ),
				'type'  => 'page',
				'id'    => (int) $page->ID,
				'url'   => (string) get_permalink( $page ),
				'kind'  => 'post-type',
			);

			$children = self::page_links( $by_parent, (int) $page->ID, $depth + 1 );
			$block    = self::make_block( empty( $children ) ? self::LINK : self::SUBMENU, $attrs );

			if ( ! empty( $children ) ) {
				$block['innerBlocks']  = $children;
				$block['innerContent'] = array_fill( 0, count( $children ), null );
			}

			$out[] = $block;
		}

		return $out;
	}

	/**
	 * Saves new content and builds the shared write result.
	 *
	 * @param WP_Post                           $post     Navigation post.
	 * @param array<int, mixed>                 $blocks   New block list.
	 * @param string                            $path     Path of the affected entry, '' when it was removed.
	 * @param array<string, mixed>              $expected Attributes the entry at $path should have.
	 * @param array<int, array<string, string>> $warnings Warnings accumulator.
	 * @param string[]                          $notes    Notes accumulator.
	 * @param array<string, mixed>              $extra    Extra result keys.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function finish_write( WP_Post $post, array $blocks, $path, array $expected, array $warnings, array $notes, array $extra ) {
		$previous = self::snapshot_revision( $post );
		$content  = serialize_blocks( $blocks );

		/*
		 * wp_slash() because wp_update_post() unslashes what it is given. Block
		 * attributes are stored as JSON in which characters such as <, >, & and
		 * quotes are written as \u escapes; unslashed, every one of them in the
		 * whole menu would lose its backslash and corrupt labels and addresses this
		 * edit never touched.
		 */
		$saved = wp_update_post(
			wp_slash(
				array(
					'ID'           => (int) $post->ID,
					'post_content' => $content,
				)
			),
			true
		);

		if ( is_wp_error( $saved ) ) {
			return Site_Support::error(
				'mosmcp_navigation_save_failed',
				wp_strip_all_tags( $saved->get_error_message() ),
				Site_Support::CAUSE_WP_CORE,
				false
			);
		}

		clean_post_cache( (int) $post->ID );
		self::$usage = null;
		$fresh       = get_post( (int) $post->ID );

		if ( ! $fresh instanceof WP_Post ) {
			return Site_Support::error(
				'mosmcp_navigation_save_unverified',
				__( 'WordPress reported the navigation menu as saved, but it cannot be read back.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_WP_CORE,
				false
			);
		}

		$stored_blocks = parse_blocks( (string) $fresh->post_content );

		if ( (string) $fresh->post_content !== $content ) {
			$warnings[] = Menus_Support::warning(
				'content_changed_on_save',
				__( 'The navigation menu was saved, but WordPress or a plugin altered its content on the way in. The entries below are read back from what was actually stored.', 'mosmcp-abilities' )
			);
		}

		$rows  = array();
		$state = array();
		self::describe_level( $stored_blocks, '', 0, $rows, $state );

		$link = null;
		if ( '' !== $path ) {
			$link  = self::row_for_path( $rows, $path );
			$found = self::locate( $stored_blocks, $path );

			if ( ! empty( $expected ) && ( is_wp_error( $found ) || ! self::attrs_match( $expected, isset( $found['block']['attrs'] ) ? $found['block']['attrs'] : array() ) ) ) {
				$warnings[] = Menus_Support::warning(
					'verify_mismatch',
					sprintf(
						/* translators: %s: path. */
						__( 'After saving, the entry at path %s does not read back exactly as written. Check it with mosmcp/navigation-get.', 'mosmcp-abilities' ),
						$path
					),
					'path ' . $path
				);
			}
		}

		foreach ( $state as $warning ) {
			if ( '' === $path || 'path ' . $path === $warning['context'] ) {
				$warnings[] = $warning;
			}
		}

		$latest = self::latest_revision_id( $fresh );

		if ( $latest > 0 && $previous > 0 ) {
			$notes[] = sprintf(
				/* translators: %d: revision ID. */
				__( 'WordPress saved a revision. The version before this change is revision %d: mosmcp/navigation-restore-revision puts it back if the person wants to undo.', 'mosmcp-abilities' ),
				$previous
			);
		} else {
			$notes[] = __( 'Revisions are switched off on this site, so this change cannot be undone automatically.', 'mosmcp-abilities' );
		}

		$summary = self::summary( $fresh );

		if ( empty( $summary['used_in'] ) ) {
			$notes[] = __( 'No header, footer or template of the active theme shows this navigation menu, so visitors will not see the change.', 'mosmcp-abilities' );
		} elseif ( 'publish' !== $fresh->post_status ) {
			$notes[] = __( 'This navigation menu is not published, so visitors keep seeing the fallback navigation until it is.', 'mosmcp-abilities' );
		}

		$result = array(
			'navigation'           => $summary,
			'path'                 => (string) $path,
			'previous_revision_id' => (int) $previous,
			'revision_id'          => (int) $latest,
			'blocks'               => $rows,
			'warnings'             => $warnings,
			'notes'                => $notes,
		);

		if ( null !== $link ) {
			$result['link'] = $link;
		}

		return array_merge( $result, $extra );
	}

	/**
	 * Resolves the navigation input for a write and applies the write guards.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return WP_Post|WP_Error
	 */
	private static function writable( array $input ) {
		$post = self::resolve( isset( $input['navigation'] ) ? $input['navigation'] : '', true );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$expected = isset( $input['expected_modified'] ) ? trim( (string) $input['expected_modified'] ) : '';

		if ( '' !== $expected && $expected !== (string) $post->post_modified && $expected !== (string) $post->post_modified_gmt ) {
			return Site_Support::error(
				'mosmcp_navigation_stale',
				sprintf(
					/* translators: 1: modified time read by the caller, 2: current modified time. */
					__( 'The navigation menu changed after it was read (read at %1$s, now %2$s), so this edit was not applied. Read it again with mosmcp/navigation-get, check the change still makes sense, and retry with the new modified value.', 'mosmcp-abilities' ),
					$expected,
					(string) $post->post_modified
				),
				Site_Support::CAUSE_CONFLICT,
				true,
				array(
					'modified'    => (string) $post->post_modified,
					'use_instead' => 'mosmcp/navigation-get',
				)
			);
		}

		return $post;
	}

	/**
	 * Resolves a navigation reference (ID, title or slug) to one wp_navigation post.
	 *
	 * @param mixed $reference Reference as supplied.
	 * @param bool  $for_write Whether the caller intends to change it.
	 * @return WP_Post|WP_Error
	 */
	private static function resolve( $reference, $for_write ) {
		$raw = ( is_scalar( $reference ) && ! is_bool( $reference ) ) ? trim( (string) $reference ) : '';

		if ( '' === $raw ) {
			return Site_Support::error(
				'mosmcp_navigation_reference_empty',
				__( 'No navigation menu was named. Pass its ID or title; mosmcp/navigation-list shows them.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_INVALID_INPUT,
				true,
				array( 'use_instead' => 'mosmcp/navigation-list' )
			);
		}

		$post = null;

		if ( ctype_digit( $raw ) ) {
			$post = get_post( (int) $raw );

			if ( $post && self::POST_TYPE !== $post->post_type ) {
				return Site_Support::error(
					'mosmcp_navigation_wrong_type',
					sprintf(
						/* translators: 1: ID, 2: content type. */
						__( 'ID %1$d is a %2$s, not a Navigation menu. Use mosmcp/navigation-list to find Navigation menu IDs. Classic menus are edited with the mosmcp/menu-* abilities.', 'mosmcp-abilities' ),
						(int) $raw,
						(string) $post->post_type
					),
					Site_Support::CAUSE_INVALID_INPUT,
					false,
					array( 'use_instead' => 'mosmcp/navigation-list' )
				);
			}
		} else {
			$lower = strtolower( $raw );
			$hits  = array();

			foreach ( get_posts(
				array(
					'post_type'   => self::POST_TYPE,
					'post_status' => array_merge( self::LIVE_STATUSES, array( 'trash' ) ),
					'numberposts' => 100,
				)
			) as $candidate ) {
				if ( strtolower( (string) $candidate->post_title ) === $lower || $candidate->post_name === $raw ) {
					$hits[] = $candidate;
				}
			}

			if ( count( $hits ) > 1 ) {
				$labels = array();
				foreach ( $hits as $hit ) {
					$labels[] = sprintf( '%d "%s" (%s)', (int) $hit->ID, self::title_of( $hit ), $hit->post_status );
				}

				return Site_Support::error(
					'mosmcp_navigation_ambiguous',
					sprintf(
						/* translators: 1: reference supplied, 2: list of matches. */
						__( '"%1$s" matches more than one Navigation menu: %2$s. Call again with the numeric ID.', 'mosmcp-abilities' ),
						$raw,
						implode( '; ', $labels )
					),
					Site_Support::CAUSE_INVALID_INPUT,
					true,
					array( 'candidates' => wp_list_pluck( $hits, 'ID' ) )
				);
			}

			$post = empty( $hits ) ? null : $hits[0];
		}

		if ( ! $post ) {
			return Site_Support::error(
				'mosmcp_navigation_not_found',
				sprintf(
					/* translators: %s: reference supplied. */
					__( 'No Navigation menu matches "%s". Use mosmcp/navigation-list to see them.', 'mosmcp-abilities' ),
					$raw
				),
				Site_Support::CAUSE_NOT_FOUND,
				false,
				array( 'use_instead' => 'mosmcp/navigation-list' )
			);
		}

		if ( ! $for_write ) {
			return $post;
		}

		if ( 'trash' === $post->post_status || 'auto-draft' === $post->post_status ) {
			return Site_Support::error(
				'mosmcp_navigation_trashed',
				sprintf(
					/* translators: %d: navigation ID. */
					__( 'Navigation menu %d is in the trash. Restore it in the Site Editor before editing it.', 'mosmcp-abilities' ),
					(int) $post->ID
				),
				Site_Support::CAUSE_CONFLICT,
				false
			);
		}

		if ( ! current_user_can( 'edit_post', (int) $post->ID ) ) {
			return Site_Support::error(
				'mosmcp_navigation_forbidden',
				__( 'This account is not allowed to edit that Navigation menu. Reconnect with an account that can edit the site\'s theme and navigation.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_CAPABILITY,
				false
			);
		}

		return $post;
	}

	/**
	 * Works out what a new or relinked link points at.
	 *
	 * @param array<string, mixed>              $input    Ability input.
	 * @param array<int, array<string, string>> $warnings Warnings accumulator.
	 * @param bool                              $required Whether a target must be given.
	 * @return array{attrs: array<string, mixed>, default_label: string}|WP_Error
	 */
	private static function link_target( array $input, array &$warnings, $required ) {
		$given = array();

		foreach ( array( 'post_id', 'term_id', 'url' ) as $key ) {
			if ( isset( $input[ $key ] ) && '' !== $input[ $key ] && 0 !== $input[ $key ] ) {
				$given[] = $key;
			}
		}

		if ( count( $given ) > 1 ) {
			return Site_Support::error(
				'mosmcp_navigation_link_ambiguous',
				sprintf(
					/* translators: %s: fields given together. */
					__( 'Pass only one link target, not %s together: post_id for a page or post, term_id for a category or tag, or url for any other address.', 'mosmcp-abilities' ),
					implode( ' and ', $given )
				),
				Site_Support::CAUSE_INVALID_INPUT,
				true
			);
		}

		if ( empty( $given ) ) {
			if ( $required ) {
				return Site_Support::error(
					'mosmcp_navigation_link_required',
					__( 'Say what the link opens: post_id for a page or post, term_id for a category or tag, or url (with label) for any other address.', 'mosmcp-abilities' ),
					Site_Support::CAUSE_INVALID_INPUT,
					true
				);
			}

			return array(
				'attrs'         => array(),
				'default_label' => '',
			);
		}

		if ( 'post_id' === $given[0] ) {
			$post = Menus_Support::link_post( $input['post_id'] );
			if ( is_wp_error( $post ) ) {
				return $post;
			}

			return array(
				'attrs'         => array(
					'type' => (string) $post->post_type,
					'id'   => (int) $post->ID,
					'url'  => (string) get_permalink( $post ),
					'kind' => 'post-type',
				),
				'default_label' => Menus_Support::plain( $post->post_title ),
			);
		}

		if ( 'term_id' === $given[0] ) {
			$term = Menus_Support::link_term( $input['term_id'] );
			if ( is_wp_error( $term ) ) {
				return $term;
			}

			$link = get_term_link( $term );

			return array(
				'attrs'         => array(
					'type' => 'post_tag' === $term->taxonomy ? 'tag' : (string) $term->taxonomy,
					'id'   => (int) $term->term_id,
					'url'  => is_wp_error( $link ) ? '' : (string) $link,
					'kind' => 'taxonomy',
				),
				'default_label' => Menus_Support::plain( $term->name ),
			);
		}

		$url = Menus_Support::clean_url( $input['url'], $warnings );
		if ( is_wp_error( $url ) ) {
			return $url;
		}

		Menus_Support::suggest_page_link( $url, $warnings );

		return array(
			'attrs'         => array(
				'url'  => $url,
				'kind' => 'custom',
			),
			'default_label' => '',
		);
	}

	/**
	 * Applies the optional display fields to a link's attributes.
	 *
	 * @param array<string, mixed> $attrs Attributes.
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	private static function apply_display_attrs( array $attrs, array $input ) {
		if ( array_key_exists( 'open_in_new_tab', $input ) && null !== $input['open_in_new_tab'] ) {
			if ( Site_Support::bool_input( $input, 'open_in_new_tab', false ) ) {
				$attrs['opensInNewTab'] = true;
			} else {
				unset( $attrs['opensInNewTab'] );
			}
		}

		$text = array(
			'description'     => 'description',
			'title_attribute' => 'title',
		);

		foreach ( $text as $key => $attr ) {
			if ( array_key_exists( $key, $input ) && null !== $input[ $key ] ) {
				$value = sanitize_text_field( (string) $input[ $key ] );
				if ( '' === $value ) {
					unset( $attrs[ $attr ] );
				} else {
					$attrs[ $attr ] = $value;
				}
			}
		}

		if ( array_key_exists( 'rel', $input ) && null !== $input['rel'] ) {
			$rel = Menus_Support::clean_rel( $input['rel'] );
			if ( '' === $rel ) {
				unset( $attrs['rel'] );
			} else {
				$attrs['rel'] = $rel;
			}
		}

		return $attrs;
	}

	/**
	 * A link label as the editor stores it: plain text with HTML special characters escaped.
	 *
	 * @param string $text Plain-text label.
	 * @return string
	 */
	private static function label_markup( $text ) {
		return htmlspecialchars( (string) $text, ENT_NOQUOTES, 'UTF-8', false );
	}

	/**
	 * A parsed-block array for a new navigation block.
	 *
	 * @param string               $name  Block name.
	 * @param array<string, mixed> $attrs Attributes.
	 * @return array<string, mixed>
	 */
	private static function make_block( $name, array $attrs ) {
		return array(
			'blockName'    => $name,
			'attrs'        => $attrs,
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
		);
	}

	/**
	 * Whether a parsed block is a real entry rather than whitespace between blocks.
	 *
	 * @param mixed $block Parsed block.
	 * @return bool
	 */
	private static function is_real( $block ) {
		if ( ! is_array( $block ) ) {
			return false;
		}

		if ( ! empty( $block['blockName'] ) ) {
			return true;
		}

		return '' !== trim( isset( $block['innerHTML'] ) ? (string) $block['innerHTML'] : '' );
	}

	/**
	 * Raw array keys of the real entries in a block list, in order.
	 *
	 * @param array<int, mixed> $blocks Block list.
	 * @return int[]
	 */
	private static function real_keys( array $blocks ) {
		$keys = array();

		foreach ( $blocks as $key => $block ) {
			if ( self::is_real( $block ) ) {
				$keys[] = (int) $key;
			}
		}

		return $keys;
	}

	/**
	 * Finds the block at a path, returning it and the raw keys leading to it.
	 *
	 * @param array<int, mixed> $blocks Block list.
	 * @param string            $path   Path such as "2.1".
	 * @return array{keys: int[], block: array<string, mixed>}|WP_Error
	 */
	private static function locate( array $blocks, $path ) {
		$path = trim( (string) $path );

		if ( ! preg_match( '/^\d+(\.\d+)*$/', $path ) ) {
			return Site_Support::error(
				'mosmcp_navigation_path_invalid',
				sprintf(
					/* translators: %s: path supplied. */
					__( '"%s" is not a path. Paths are numbers separated by dots, counted from 0, exactly as mosmcp/navigation-get reports them: "0" is the first entry, "2.1" the second entry inside the third.', 'mosmcp-abilities' ),
					$path
				),
				Site_Support::CAUSE_INVALID_INPUT,
				true,
				array( 'use_instead' => 'mosmcp/navigation-get' )
			);
		}

		$keys  = array();
		$list  = $blocks;
		$block = array();
		$walk  = '';

		foreach ( array_map( 'intval', explode( '.', $path ) ) as $index ) {
			$real = self::real_keys( $list );

			if ( ! isset( $real[ $index ] ) ) {
				$where = '' === $walk ? __( 'at the top level', 'mosmcp-abilities' ) : sprintf(
					/* translators: %s: path. */
					__( 'inside %s', 'mosmcp-abilities' ),
					$walk
				);

				return Site_Support::error(
					'mosmcp_navigation_path_not_found',
					sprintf(
						/* translators: 1: path supplied, 2: where, 3: number of entries. */
						__( 'There is no entry at path %1$s: %2$s there are %3$d entries (counted from 0). Read the menu again with mosmcp/navigation-get; paths shift after every change.', 'mosmcp-abilities' ),
						$path,
						$where,
						count( $real )
					),
					Site_Support::CAUSE_NOT_FOUND,
					true,
					array( 'use_instead' => 'mosmcp/navigation-get' )
				);
			}

			$keys[] = $real[ $index ];
			$block  = $list[ $real[ $index ] ];
			$list   = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : array();
			$walk   = '' === $walk ? (string) $index : $walk . '.' . $index;
		}

		// $walk is the canonical form ("01.00" becomes "1.0"); callers use it from here on.
		return array(
			'keys'  => $keys,
			'block' => $block,
			'path'  => $walk,
		);
	}

	/**
	 * Applies an edit to the list of children at a place in the tree.
	 *
	 * The callback receives the children (the top-level list when $parent_keys is
	 * empty) and their parent block (null at the top level), and returns both,
	 * changed. The parent's innerContent is rebuilt to match its new children:
	 * serialize_block() writes inner blocks only where innerContent has a
	 * placeholder for them, so a child added without one would silently vanish.
	 *
	 * @param array<int, mixed> $blocks      Block list.
	 * @param int[]             $parent_keys Raw keys leading to the parent block.
	 * @param callable          $edit        Edit callback.
	 * @return array<int, mixed>
	 */
	private static function edit_level( array $blocks, array $parent_keys, callable $edit ) {
		if ( empty( $parent_keys ) ) {
			$result = $edit( $blocks, null );
			return array_values( $result[0] );
		}

		$key   = array_shift( $parent_keys );
		$block = $blocks[ $key ];

		if ( empty( $parent_keys ) ) {
			$result               = $edit( isset( $block['innerBlocks'] ) ? $block['innerBlocks'] : array(), $block );
			$block                = $result[1];
			$block['innerBlocks'] = array_values( $result[0] );
			$block                = self::sync_inner_content( $block );
		} else {
			$block['innerBlocks'] = self::edit_level( $block['innerBlocks'], $parent_keys, $edit );
		}

		$blocks[ $key ] = $block;

		return $blocks;
	}

	/**
	 * Rebuilds a block's innerContent around its current inner blocks.
	 *
	 * Wrapper markup before the first child and after the last (as a static parent
	 * block has) is kept, and so is the separator between children.
	 *
	 * @param array<string, mixed> $block Parsed block.
	 * @return array<string, mixed>
	 */
	private static function sync_inner_content( array $block ) {
		$content = isset( $block['innerContent'] ) && is_array( $block['innerContent'] ) ? $block['innerContent'] : array();
		$count   = count( $block['innerBlocks'] );
		$nulls   = array_keys( $content, null, true );
		$lead    = '';
		$trail   = '';
		$sep     = '';

		if ( empty( $nulls ) ) {
			$lead = implode( '', array_filter( $content, 'is_string' ) );
		} else {
			$first = (int) reset( $nulls );
			$last  = (int) end( $nulls );

			$lead  = implode( '', array_filter( array_slice( $content, 0, $first ), 'is_string' ) );
			$trail = implode( '', array_filter( array_slice( $content, $last + 1 ), 'is_string' ) );

			if ( count( $nulls ) > 1 ) {
				$between = array_filter( array_slice( $content, $first + 1, $nulls[1] - $first - 1 ), 'is_string' );
				$sep     = implode( '', $between );
			}
		}

		// Whitespace-only wrappers carry nothing; dynamic blocks such as submenus have none.
		if ( '' === trim( $lead . $trail ) ) {
			$lead  = '';
			$trail = '';
		}

		$rebuilt = array();

		if ( '' !== $lead ) {
			$rebuilt[] = $lead;
		}

		for ( $i = 0; $i < $count; $i++ ) {
			if ( $i > 0 && '' !== $sep ) {
				$rebuilt[] = $sep;
			}
			$rebuilt[] = null;
		}

		if ( '' !== $trail ) {
			$rebuilt[] = $trail;
		}

		$block['innerContent'] = $rebuilt;
		$block['innerHTML']    = implode( '', array_filter( $rebuilt, 'is_string' ) );

		return $block;
	}

	/**
	 * Describes every entry at one level, recursing into children.
	 *
	 * @param array<int, mixed>                 $blocks   Block list.
	 * @param string                            $prefix   Path of the parent, '' at the top.
	 * @param int                               $depth    Depth of this level.
	 * @param array<int, array<string, mixed>>  $rows     Accumulator.
	 * @param array<int, array<string, string>> $warnings Warnings accumulator.
	 * @return void
	 */
	private static function describe_level( array $blocks, $prefix, $depth, array &$rows, array &$warnings ) {
		if ( $depth > self::MAX_WALK_DEPTH ) {
			return;
		}

		$index = 0;

		foreach ( $blocks as $block ) {
			if ( ! self::is_real( $block ) ) {
				continue;
			}

			$path   = '' === $prefix ? (string) $index : $prefix . '.' . $index;
			$rows[] = self::describe_block( $block, $path, $depth, $warnings );

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				self::describe_level( $block['innerBlocks'], $path, $depth + 1, $rows, $warnings );
			}

			++$index;
		}
	}

	/**
	 * Describes one entry of a navigation menu.
	 *
	 * @param array<string, mixed>              $block    Parsed block.
	 * @param string                            $path     Its path.
	 * @param int                               $depth    Its depth.
	 * @param array<int, array<string, string>> $warnings Warnings accumulator.
	 * @return array<string, mixed>
	 */
	private static function describe_block( array $block, $path, $depth, array &$warnings ) {
		$name  = ! empty( $block['blockName'] ) ? (string) $block['blockName'] : 'core/freeform';
		$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
		$inner = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? count( self::real_keys( $block['innerBlocks'] ) ) : 0;

		$row = array(
			'path'             => (string) $path,
			'depth'            => (int) $depth,
			'block'            => $name,
			'label'            => '',
			'url'              => '',
			'kind'             => '',
			'type'             => '',
			'linked_id'        => 0,
			'opens_in_new_tab' => false,
			'description'      => '',
			'title_attribute'  => '',
			'rel'              => '',
			'linked_status'    => '',
			'child_count'      => $inner,
			'editable'         => false,
			'summary'          => '',
		);

		if ( in_array( $name, array( self::LINK, self::SUBMENU ), true ) ) {
			$row['label']            = Menus_Support::plain( isset( $attrs['label'] ) ? $attrs['label'] : '' );
			$row['url']              = isset( $attrs['url'] ) ? (string) $attrs['url'] : '';
			$row['kind']             = isset( $attrs['kind'] ) ? (string) $attrs['kind'] : '';
			$row['type']             = isset( $attrs['type'] ) ? (string) $attrs['type'] : '';
			$row['linked_id']        = isset( $attrs['id'] ) && is_numeric( $attrs['id'] ) ? (int) $attrs['id'] : 0;
			$row['opens_in_new_tab'] = ! empty( $attrs['opensInNewTab'] );
			$row['description']      = isset( $attrs['description'] ) ? (string) $attrs['description'] : '';
			$row['title_attribute']  = isset( $attrs['title'] ) ? (string) $attrs['title'] : '';
			$row['rel']              = isset( $attrs['rel'] ) ? (string) $attrs['rel'] : '';
			$row['linked_status']    = self::linked_status( $attrs );
			$row['editable']         = true;
			$row['summary']          = self::link_summary( $row, self::SUBMENU === $name );

			self::link_warnings( $row, $attrs, $warnings );

			return $row;
		}

		$type = \WP_Block_Type_Registry::get_instance()->get_registered( $name );
		$what = $type && ! empty( $type->title ) ? (string) $type->title : $name;

		switch ( $name ) {
			case self::PAGE_LIST:
				$parent         = isset( $attrs['parentPageID'] ) ? (int) $attrs['parentPageID'] : 0;
				$row['summary'] = $parent > 0
					? sprintf(
						/* translators: %s: parent page title. */
						__( 'Automatic list of the published child pages of "%s". Pages cannot be removed or reordered one by one here; navigation-update-link with convert_page_list turns it into individual links.', 'mosmcp-abilities' ),
						Menus_Support::safe_title( $parent )
					)
					: sprintf(
						/* translators: %d: number of published pages. */
						__( 'Automatic list of every published page (%d right now), with child pages as dropdowns. New pages appear in it automatically. Pages cannot be removed or reordered one by one here; navigation-update-link with convert_page_list turns it into individual links.', 'mosmcp-abilities' ),
						(int) wp_count_posts( 'page' )->publish
					);
				break;
			case self::HOME_LINK:
				$row['label']   = Menus_Support::plain( isset( $attrs['label'] ) && '' !== $attrs['label'] ? $attrs['label'] : __( 'Home', 'mosmcp-abilities' ) );
				$row['url']     = (string) home_url( '/' );
				$row['summary'] = __( 'Link to the site\'s home page.', 'mosmcp-abilities' );
				break;
			case 'core/freeform':
				$row['summary'] = __( 'Loose HTML that is not a block. It can only be removed, not edited, here.', 'mosmcp-abilities' );
				break;
			default:
				$row['summary'] = $inner > 0
					? sprintf(
						/* translators: 1: block title, 2: number of inner entries. */
						__( '%1$s (holds %2$d entries). It is not a link, so it can only be removed, not edited, here.', 'mosmcp-abilities' ),
						$what,
						$inner
					)
					: sprintf(
						/* translators: %s: block title. */
						__( '%s. It is not a link, so it can only be removed, not edited, here.', 'mosmcp-abilities' ),
						$what
					);
		}

		return $row;
	}

	/**
	 * Plain-language summary of a link row.
	 *
	 * @param array<string, mixed> $row        Described row.
	 * @param bool                 $is_submenu Whether it is a submenu.
	 * @return string
	 */
	private static function link_summary( array $row, $is_submenu ) {
		if ( 'missing' === $row['linked_status'] ) {
			$target = sprintf(
				/* translators: %d: ID of the missing page or term. */
				__( 'something that no longer exists (ID %d)', 'mosmcp-abilities' ),
				$row['linked_id']
			);
		} elseif ( 'post-type' === $row['kind'] && $row['linked_id'] > 0 ) {
			$target = sprintf(
				/* translators: 1: content type, 2: title, 3: ID. */
				__( 'the %1$s "%2$s" (ID %3$d)', 'mosmcp-abilities' ),
				'' !== $row['type'] ? $row['type'] : 'page',
				Menus_Support::safe_title( $row['linked_id'] ),
				$row['linked_id']
			);
		} elseif ( 'taxonomy' === $row['kind'] && $row['linked_id'] > 0 ) {
			$term   = get_term( $row['linked_id'] );
			$target = sprintf(
				/* translators: 1: taxonomy, 2: term name, 3: ID. */
				__( 'the %1$s "%2$s" (ID %3$d)', 'mosmcp-abilities' ),
				'' !== $row['type'] ? $row['type'] : 'category',
				$term instanceof WP_Term ? $term->name : '',
				$row['linked_id']
			);
		} else {
			$target = '' !== $row['url'] ? $row['url'] : __( 'nowhere', 'mosmcp-abilities' );
		}

		if ( $is_submenu ) {
			return sprintf(
				/* translators: 1: label, 2: link target, 3: number of entries. */
				_n( 'Dropdown "%1$s" linking to %2$s, holding %3$d entry.', 'Dropdown "%1$s" linking to %2$s, holding %3$d entries.', (int) $row['child_count'], 'mosmcp-abilities' ),
				$row['label'],
				$target,
				$row['child_count']
			);
		}

		return sprintf(
			/* translators: 1: label, 2: link target. */
			__( 'Link "%1$s" to %2$s.', 'mosmcp-abilities' ),
			$row['label'],
			$target
		);
	}

	/**
	 * Status of what a link points at.
	 *
	 * @param array<string, mixed> $attrs Link attributes.
	 * @return string
	 */
	private static function linked_status( array $attrs ) {
		$kind = isset( $attrs['kind'] ) ? (string) $attrs['kind'] : '';
		$id   = isset( $attrs['id'] ) && is_numeric( $attrs['id'] ) ? (int) $attrs['id'] : 0;

		if ( $id < 1 || 'custom' === $kind || '' === $kind ) {
			return '';
		}

		if ( 'taxonomy' === $kind ) {
			return get_term( $id ) instanceof WP_Term ? 'ok' : 'missing';
		}

		$post = get_post( $id );

		return $post ? (string) $post->post_status : 'missing';
	}

	/**
	 * Adds the warnings a link deserves.
	 *
	 * @param array<string, mixed>              $row      Described row.
	 * @param array<string, mixed>              $attrs    Link attributes.
	 * @param array<int, array<string, string>> $warnings Warnings accumulator.
	 * @return void
	 */
	private static function link_warnings( array $row, array $attrs, array &$warnings ) {
		$context = 'path ' . $row['path'];
		$label   = '' !== $row['label'] ? $row['label'] : $row['url'];

		if ( '' === $row['label'] ) {
			$warnings[] = Menus_Support::warning(
				'link_unlabelled',
				sprintf(
					/* translators: %s: path. */
					__( 'The link at path %s has no label, and WordPress does not display links without one. Give it a label with mosmcp/navigation-update-link or remove it.', 'mosmcp-abilities' ),
					$row['path']
				),
				$context
			);
		}

		switch ( $row['linked_status'] ) {
			case 'missing':
				$warnings[] = Menus_Support::warning(
					'link_target_missing',
					sprintf(
						/* translators: 1: label, 2: path. */
						__( 'The link "%1$s" (path %2$s) points at something that no longer exists, so visitors who click it get a "not found" page. Re-link it with mosmcp/navigation-update-link or remove it.', 'mosmcp-abilities' ),
						$label,
						$row['path']
					),
					$context
				);
				break;
			case 'trash':
			case 'draft':
			case 'pending':
			case 'future':
			case 'private':
			case 'auto-draft':
				$warnings[] = Menus_Support::warning(
					'link_target_not_public',
					sprintf(
						/* translators: 1: label, 2: path, 3: status. */
						__( 'The link "%1$s" (path %2$s) is shown to visitors, but the page it opens has the status "%3$s", so they reach a "not found" page. Publish that page, or remove or re-link the link.', 'mosmcp-abilities' ),
						$label,
						$row['path'],
						$row['linked_status']
					),
					$context
				);
				break;
		}

		$fresh = self::current_url( $attrs );
		if ( 'publish' === $row['linked_status'] && '' !== $fresh && $fresh !== $row['url'] ) {
			$warnings[] = Menus_Support::warning(
				'stale_url',
				sprintf(
					/* translators: 1: label, 2: path, 3: stored address, 4: current address. */
					__( 'The link "%1$s" (path %2$s) still stores the page\'s old address %3$s; the page now lives at %4$s. Visitors may hit a redirect or a "not found" page. Any mosmcp/navigation-update-link call on this link refreshes it.', 'mosmcp-abilities' ),
					$label,
					$row['path'],
					$row['url'],
					$fresh
				),
				$context
			);
		}

		if ( 'custom' === $row['kind'] || '' === $row['kind'] ) {
			if ( '' === trim( $row['url'] ) ) {
				$warnings[] = Menus_Support::warning(
					'link_url_empty',
					sprintf(
						/* translators: 1: label, 2: path. */
						__( 'The link "%1$s" (path %2$s) has no address, so it goes nowhere.', 'mosmcp-abilities' ),
						$label,
						$row['path']
					),
					$context
				);
			} elseif ( preg_match( '/^\s*(javascript|data|vbscript):/i', html_entity_decode( $row['url'], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ) {
				$warnings[] = Menus_Support::warning(
					'link_url_unsafe',
					sprintf(
						/* translators: 1: label, 2: path. */
						__( 'The link "%1$s" (path %2$s) uses a script address, which WordPress strips when it displays the link. Give it a real address or remove it.', 'mosmcp-abilities' ),
						$label,
						$row['path']
					),
					$context
				);
			}
		}
	}

	/**
	 * Current address of what a page or term link points at, or '' for other links.
	 *
	 * @param array<string, mixed> $attrs Link attributes.
	 * @return string
	 */
	private static function current_url( array $attrs ) {
		$kind = isset( $attrs['kind'] ) ? (string) $attrs['kind'] : '';
		$id   = isset( $attrs['id'] ) && is_numeric( $attrs['id'] ) ? (int) $attrs['id'] : 0;

		if ( $id < 1 ) {
			return '';
		}

		if ( 'post-type' === $kind ) {
			$post = get_post( $id );
			return ( $post && 'publish' === $post->post_status ) ? (string) get_permalink( $post ) : '';
		}

		if ( 'taxonomy' === $kind ) {
			$term = get_term( $id );
			$link = $term instanceof WP_Term ? get_term_link( $term ) : '';
			return is_string( $link ) ? $link : '';
		}

		return '';
	}

	/**
	 * Title of the page or term a link points at, for label comparisons.
	 *
	 * @param array<string, mixed> $attrs Link attributes.
	 * @return string
	 */
	private static function linked_title( array $attrs ) {
		$kind = isset( $attrs['kind'] ) ? (string) $attrs['kind'] : '';
		$id   = isset( $attrs['id'] ) && is_numeric( $attrs['id'] ) ? (int) $attrs['id'] : 0;

		if ( $id < 1 ) {
			return '';
		}

		if ( 'post-type' === $kind ) {
			$post = get_post( $id );
			return $post ? Menus_Support::plain( $post->post_title ) : '';
		}

		if ( 'taxonomy' === $kind ) {
			$term = get_term( $id );
			return $term instanceof WP_Term ? Menus_Support::plain( $term->name ) : '';
		}

		return '';
	}

	/**
	 * Warns when the navigation menu already links to the same place.
	 *
	 * @param array<int, mixed>                 $blocks   Block list.
	 * @param array<string, mixed>              $attrs    New link attributes.
	 * @param array<int, array<string, string>> $warnings Warnings accumulator.
	 * @return void
	 */
	private static function duplicate_warning( array $blocks, array $attrs, array &$warnings ) {
		$rows = array();
		$ign  = array();
		self::describe_level( $blocks, '', 0, $rows, $ign );

		foreach ( $rows as $row ) {
			if ( self::PAGE_LIST === $row['block'] && 'post-type' === $attrs['kind'] && 'page' === $attrs['type'] ) {
				$warnings[] = Menus_Support::warning(
					'page_list_duplicate',
					sprintf(
						/* translators: %s: path of the page list. */
						__( 'This menu also has an automatic list of pages at path %s, which already shows every published page, so the page may now appear twice. navigation-update-link with convert_page_list on that path turns the list into individual links that can be removed one by one.', 'mosmcp-abilities' ),
						$row['path']
					),
					'path ' . $row['path']
				);
				continue;
			}

			if ( ! $row['editable'] ) {
				continue;
			}

			$same = ( 'custom' === $attrs['kind'] )
				? ( 'custom' === $row['kind'] && untrailingslashit( $row['url'] ) === untrailingslashit( (string) $attrs['url'] ) )
				: ( $row['kind'] === $attrs['kind'] && $row['linked_id'] === (int) $attrs['id'] );

			if ( $same ) {
				$warnings[] = Menus_Support::warning(
					'already_in_navigation',
					sprintf(
						/* translators: 1: existing label, 2: path. */
						__( 'The menu already links to the same place as "%1$s" (path %2$s), so it now appears twice. Remove one with mosmcp/navigation-remove-link if that was not intended.', 'mosmcp-abilities' ),
						$row['label'],
						$row['path']
					),
					'path ' . $row['path']
				);
				return;
			}
		}
	}

	/**
	 * Whether the stored attributes carry every value that was written.
	 *
	 * @param array<string, mixed> $expected Attributes written.
	 * @param array<string, mixed> $stored   Attributes read back.
	 * @return bool
	 */
	private static function attrs_match( array $expected, array $stored ) {
		foreach ( array( 'label', 'url', 'id', 'kind', 'type', 'opensInNewTab', 'title', 'description', 'rel' ) as $key ) {
			$want = isset( $expected[ $key ] ) ? $expected[ $key ] : null;
			$have = isset( $stored[ $key ] ) ? $stored[ $key ] : null;

			if ( ( is_scalar( $want ) ? (string) $want : $want ) !== ( is_scalar( $have ) ? (string) $have : $have ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * The described row at a (canonical) path, or null when there is none.
	 *
	 * Null rather than an empty array: an empty PHP array serialises as a JSON
	 * list, which is not the object the output schema promises. Callers leave
	 * the key out instead.
	 *
	 * @param array<int, array<string, mixed>> $rows Described rows.
	 * @param string                           $path Path.
	 * @return array<string, mixed>|null
	 */
	private static function row_for_path( array $rows, $path ) {
		foreach ( $rows as $row ) {
			if ( $row['path'] === (string) $path ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * Makes sure the current content is kept as a revision before it is overwritten.
	 *
	 * WordPress saves a revision of the new content on every update, but a
	 * navigation menu created by the Site Editor's fallback, or by code, may have
	 * no revision of its current content at all, which would make the first change
	 * impossible to undo. Saving one first (a no-op when it already exists) makes
	 * every change through these abilities reversible.
	 *
	 * @param WP_Post $post Navigation post.
	 * @return int ID of the revision holding the pre-change content, 0 when revisions are off.
	 */
	private static function snapshot_revision( WP_Post $post ) {
		if ( ! wp_revisions_enabled( $post ) ) {
			return 0;
		}

		$latest = self::latest_revision( $post );

		if ( $latest && (string) $latest->post_content === (string) $post->post_content && (string) $latest->post_title === (string) $post->post_title ) {
			return (int) $latest->ID;
		}

		$saved = wp_save_post_revision( (int) $post->ID );

		if ( $saved && ! is_wp_error( $saved ) ) {
			return (int) $saved;
		}

		$latest = self::latest_revision( $post );

		return $latest ? (int) $latest->ID : 0;
	}

	/**
	 * Newest revision of a post.
	 *
	 * @param WP_Post $post Post.
	 * @return WP_Post|null
	 */
	private static function latest_revision( WP_Post $post ) {
		$revisions = wp_get_post_revisions(
			(int) $post->ID,
			array(
				'posts_per_page' => 1,
				'orderby'        => 'ID',
				'order'          => 'DESC',
			)
		);

		$latest = empty( $revisions ) ? null : reset( $revisions );

		return $latest instanceof WP_Post ? $latest : null;
	}

	/**
	 * ID of the newest revision of a post, 0 when none.
	 *
	 * @param WP_Post $post Post.
	 * @return int
	 */
	private static function latest_revision_id( WP_Post $post ) {
		$latest = self::latest_revision( $post );

		return $latest ? (int) $latest->ID : 0;
	}

	/**
	 * Navigation menu identity, counts and where it is shown.
	 *
	 * @param WP_Post $post Navigation post.
	 * @return array<string, mixed>
	 */
	private static function summary( WP_Post $post ) {
		$rows = array();
		$ign  = array();
		self::describe_level( parse_blocks( (string) $post->post_content ), '', 0, $rows, $ign );

		$links = 0;
		foreach ( $rows as $row ) {
			if ( $row['editable'] ) {
				++$links;
			}
		}

		$usage = self::usage();

		return array(
			'navigation_id' => (int) $post->ID,
			'title'         => self::title_of( $post ),
			'status'        => (string) $post->post_status,
			'modified'      => (string) $post->post_modified,
			'link_count'    => $links,
			'used_in'       => isset( $usage['refs'][ (int) $post->ID ] ) ? $usage['refs'][ (int) $post->ID ] : array(),
		);
	}

	/**
	 * Display title of a navigation post.
	 *
	 * @param WP_Post $post Navigation post.
	 * @return string
	 */
	private static function title_of( WP_Post $post ) {
		$title = Menus_Support::plain( $post->post_title );

		return '' !== $title ? $title : sprintf(
			/* translators: %d: navigation ID. */
			__( 'Navigation #%d (no title)', 'mosmcp-abilities' ),
			(int) $post->ID
		);
	}

	/**
	 * Works out where the active theme displays each navigation menu.
	 *
	 * Every template and template part is scanned (theme files and Site Editor
	 * customisations alike), following patterns and synced patterns, for
	 * core/navigation blocks. A block naming a menu by ref is recorded against
	 * that menu; a block naming none shows the newest published menu (or a list of
	 * pages), exactly as core renders it, and is recorded as such.
	 *
	 * @return array{refs: array<int, array<int, array<string, mixed>>>, unbound: array<int, array<string, mixed>>, warnings: array<int, array<string, string>>, fallback_id: int}
	 */
	private static function usage() {
		if ( null !== self::$usage ) {
			return self::$usage;
		}

		$refs       = array();
		$unbound    = array();
		$warnings   = array();
		$found      = array();
		$part_users = array();

		if ( function_exists( 'get_block_templates' ) && function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
			foreach ( (array) get_block_templates( array(), 'wp_template' ) as $template ) {
				$navs  = array();
				$parts = array();
				$seen  = array();
				self::scan( parse_blocks( (string) $template->content ), 0, $navs, $parts, $seen );

				foreach ( array_unique( $parts ) as $part ) {
					$part_users[ $part ][] = (string) $template->slug;
				}

				foreach ( $navs as $nav ) {
					$found[] = array( 'template', $template, $nav );
				}
			}

			foreach ( (array) get_block_templates( array(), 'wp_template_part' ) as $part ) {
				$navs  = array();
				$parts = array();
				$seen  = array();
				self::scan( parse_blocks( (string) $part->content ), 0, $navs, $parts, $seen );

				foreach ( $navs as $nav ) {
					$found[] = array( 'template_part', $part, $nav );
				}
			}
		}

		$fallback    = self::fallback_navigation_id();
		$occurrences = array();

		foreach ( $found as $entry ) {
			list( $where, $template, $nav ) = $entry;

			$counter                 = $where . ':' . (string) $template->slug;
			$occurrences[ $counter ] = isset( $occurrences[ $counter ] ) ? $occurrences[ $counter ] + 1 : 1;

			$attrs = isset( $nav['attrs'] ) && is_array( $nav['attrs'] ) ? $nav['attrs'] : array();
			$ref   = 0;

			if ( isset( $attrs['ref'] ) && is_numeric( $attrs['ref'] ) ) {
				$ref = (int) $attrs['ref'];
			} elseif ( isset( $attrs['navigationMenuId'] ) && is_numeric( $attrs['navigationMenuId'] ) ) {
				$ref = (int) $attrs['navigationMenuId'];
			}

			$slug  = (string) $template->slug;
			$title = '' !== (string) $template->title ? (string) $template->title : $slug;
			$base  = array(
				'where'             => $where,
				'slug'              => $slug,
				'title'             => $title,
				'area'              => 'template_part' === $where && isset( $template->area ) ? (string) $template->area : '',
				'used_by_templates' => 'template_part' === $where && isset( $part_users[ $slug ] ) ? array_values( array_unique( $part_users[ $slug ] ) ) : array(),
			);

			if ( $ref > 0 ) {
				$target = get_post( $ref );

				if ( $target && self::POST_TYPE === $target->post_type && 'publish' === $target->post_status ) {
					$refs[ $ref ][] = array_merge( $base, array( 'via' => 'ref' ) );
					continue;
				}

				$warnings[] = ( ! $target || self::POST_TYPE !== $target->post_type )
					? Menus_Support::warning(
						'navigation_ref_broken',
						sprintf(
							/* translators: 1: template or part title, 2: navigation ID. */
							__( 'The Navigation block in "%1$s" points at Navigation menu %2$d, which no longer exists, so visitors see the fallback navigation there instead. Choose another menu on that block in the Site Editor.', 'mosmcp-abilities' ),
							$title,
							$ref
						),
						$where . ' ' . $slug
					)
					: Menus_Support::warning(
						'navigation_not_published',
						sprintf(
							/* translators: 1: template or part title, 2: navigation ID, 3: post status. */
							__( 'The Navigation block in "%1$s" shows Navigation menu %2$d, but that menu\'s status is "%3$s", so visitors see the fallback navigation there instead. Publish that menu in the Site Editor, or choose another one on that block.', 'mosmcp-abilities' ),
							$title,
							$ref,
							$target->post_status
						),
						$where . ' ' . $slug
					);

				if ( $target && self::POST_TYPE === $target->post_type ) {
					$refs[ $ref ][] = array_merge( $base, array( 'via' => 'ref' ) );
				}
			}

			$inline = isset( $nav['innerBlocks'] ) && is_array( $nav['innerBlocks'] ) ? count( self::real_keys( $nav['innerBlocks'] ) ) : 0;

			if ( 0 === $ref ) {
				$unbound[] = array_merge(
					$base,
					array(
						'occurrence'             => $occurrences[ $counter ],
						'shows'                  => $inline > 0 ? 'inline_links' : 'fallback',
						'inline_link_count'      => $inline,
						'fallback_navigation_id' => $inline > 0 ? 0 : $fallback,
					)
				);
			}

			if ( ( 0 === $ref && 0 === $inline ) || $ref > 0 ) {
				if ( $fallback > 0 && $fallback !== $ref ) {
					$refs[ $fallback ][] = array_merge( $base, array( 'via' => 'fallback' ) );
				}
			}
		}

		// One row per place (a part with two such blocks is still one place), and
		// places visitors actually see (parts used by templates) first.
		foreach ( $refs as $nav_id => $places ) {
			$unique = array();
			foreach ( $places as $place ) {
				$unique[ $place['where'] . '|' . $place['slug'] . '|' . $place['via'] ] = $place;
			}
			$places = array_values( $unique );
			usort(
				$places,
				static function ( $a, $b ) {
					return count( $b['used_by_templates'] ) <=> count( $a['used_by_templates'] );
				}
			);
			$refs[ $nav_id ] = $places;
		}

		self::$usage = array(
			'refs'        => $refs,
			'unbound'     => $unbound,
			'warnings'    => $warnings,
			'fallback_id' => $fallback,
		);

		return self::$usage;
	}

	/**
	 * Collects core/navigation blocks and template-part references from a block tree.
	 *
	 * @param array<int, mixed>   $blocks Block list.
	 * @param int                 $depth  Recursion depth.
	 * @param array<int, mixed>   $navs   Navigation blocks found.
	 * @param string[]            $parts  Template part slugs referenced.
	 * @param array<string, bool> $seen   Patterns and synced patterns already followed.
	 * @return void
	 */
	private static function scan( array $blocks, $depth, array &$navs, array &$parts, array &$seen ) {
		if ( $depth > self::MAX_WALK_DEPTH ) {
			return;
		}

		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) || empty( $block['blockName'] ) ) {
				continue;
			}

			$name  = (string) $block['blockName'];
			$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();

			if ( 'core/navigation' === $name ) {
				$navs[] = $block;
				continue;
			}

			if ( 'core/template-part' === $name && ! empty( $attrs['slug'] ) ) {
				$parts[] = (string) $attrs['slug'];
			}

			if ( 'core/pattern' === $name && ! empty( $attrs['slug'] ) && empty( $seen[ 'p:' . $attrs['slug'] ] ) ) {
				$seen[ 'p:' . $attrs['slug'] ] = true;
				$pattern                       = \WP_Block_Patterns_Registry::get_instance()->get_registered( (string) $attrs['slug'] );

				if ( is_array( $pattern ) && isset( $pattern['content'] ) ) {
					self::scan( parse_blocks( (string) $pattern['content'] ), $depth + 1, $navs, $parts, $seen );
				}
			}

			if ( 'core/block' === $name && ! empty( $attrs['ref'] ) && empty( $seen[ 'b:' . $attrs['ref'] ] ) ) {
				$seen[ 'b:' . $attrs['ref'] ] = true;
				$synced                       = get_post( (int) $attrs['ref'] );

				if ( $synced && 'wp_block' === $synced->post_type ) {
					self::scan( parse_blocks( (string) $synced->post_content ), $depth + 1, $navs, $parts, $seen );
				}
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				self::scan( $block['innerBlocks'], $depth + 1, $navs, $parts, $seen );
			}
		}
	}

	/**
	 * The navigation menu a ref-less Navigation block shows: the newest published one.
	 *
	 * Mirrors core's choice without calling it, because core's own fallback lookup
	 * creates a new navigation menu when none exists, and a read must not write.
	 *
	 * @return int Navigation post ID, 0 when there is none (a page list is shown).
	 */
	private static function fallback_navigation_id() {
		$query = new \WP_Query(
			array(
				'post_type'              => self::POST_TYPE,
				'post_status'            => 'publish',
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'posts_per_page'         => 1,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'fields'                 => 'ids',
			)
		);

		return empty( $query->posts ) ? 0 : (int) $query->posts[0];
	}
}
