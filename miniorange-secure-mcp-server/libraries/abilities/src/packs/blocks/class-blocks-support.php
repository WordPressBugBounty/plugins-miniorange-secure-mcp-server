<?php
/**
 * Post resolution, guards, saving and result shaping for the block abilities.
 *
 * Everything a block edit needs around the edit itself lives here, so every
 * ability gets the same answers: which content can be edited block by block, the
 * page-builder guard, the "changed since you read it" lock, a save that keeps
 * every backslash in block settings, and a result that tells the AI where to
 * look at the change and how to undo it.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Blocks;

use MoSMCP\Abilities\Config;
use MoSMCP\Abilities\Packs\Site\Site_Support;
use MoSMCP\Abilities\Support\Html_Policy;
use WP_Block_Type_Registry;
use WP_Error;
use WP_Post;

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
 * Class Blocks_Support
 */
class Blocks_Support {

	/**
	 * Attributes worth showing in a block outline, when present and scalar.
	 *
	 * @var string[]
	 */
	const KEY_ATTRS = array( 'level', 'ordered', 'align', 'textAlign', 'className', 'anchor', 'url', 'href', 'id', 'ref', 'slug', 'tagName', 'sizeSlug', 'linkDestination', 'layout', 'uniqueID' );

	/**
	 * Post types edited by other abilities, with the ability to use instead.
	 *
	 * @var array<string, string>
	 */
	const ELSEWHERE = array(
		'wp_template'         => 'mosmcp/theme-template-get',
		'wp_template_part'    => 'mosmcp/theme-part-get',
		'wp_global_styles'    => 'mosmcp/theme-styles-get',
		'wp_navigation'       => 'mosmcp/navigation-get',
		'nav_menu_item'       => 'mosmcp/menu-get',
		'attachment'          => 'mosmcp/media-get',
		'revision'            => 'mosmcp/revision-get',
		'customize_changeset' => '',
		'oembed_cache'        => '',
		'user_request'        => '',
		'wp_font_family'      => '',
		'wp_font_face'        => '',
	);

	/**
	 * Page builders that keep the real layout outside post_content, by detection meta.
	 *
	 * Each entry: meta key, value that means "built with it" (or '*' for any
	 * non-empty value), builder name, ability to use instead.
	 *
	 * @var array<int, array{0:string,1:string,2:string,3:string}>
	 */
	const BUILDERS = array(
		array( '_elementor_edit_mode', 'builder', 'Elementor', 'mosmcp/elementor-page-read' ),
		array( '_et_pb_use_builder', 'on', 'Divi', 'mosmcp/divi-page-read' ),
		array( '_fl_builder_enabled', '*', 'Beaver Builder', 'mosmcp/beaver-builder-read-layout' ),
		array( '_bricks_editor_mode', 'bricks', 'Bricks', 'mosmcp/bricks-read-page-structure' ),
		array( '_breakdance_data', '*', 'Breakdance', 'mosmcp/breakdance-read-page-layout' ),
		array( 'ct_builder_shortcodes', '*', 'Oxygen', 'mosmcp/oxygen-read-page-structure' ),
		array( '_wpb_vc_js_status', 'true', 'WPBakery', 'mosmcp/wpbakery-read-page-layout' ),
		array( '_seedprod_page', '*', 'SeedProd', '' ),
	);

	/**
	 * Resolves the target post and confirms its content is block-editable.
	 *
	 * @param array<string, mixed> $input  Ability input.
	 * @param bool                 $write  Whether the caller is about to write.
	 * @return WP_Post|WP_Error
	 */
	public static function require_post( array $input, $write ) {
		$id   = isset( $input['id'] ) ? absint( $input['id'] ) : 0;
		$post = $id > 0 ? get_post( $id ) : null;

		if ( ! $post instanceof WP_Post || 'auto-draft' === $post->post_status ) {
			return Site_Support::error(
				'mosmcp_block_post_not_found',
				__( 'No post, page or other content item has that ID. Find the right ID with mosmcp/post-find, mosmcp/page-list-all or mosmcp/cpt-list.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_NOT_FOUND,
				false,
				array( 'use_instead' => 'mosmcp/post-find' )
			);
		}

		if ( array_key_exists( $post->post_type, self::ELSEWHERE ) ) {
			$instead = self::ELSEWHERE[ $post->post_type ];
			return Site_Support::error(
				'mosmcp_block_post_type_elsewhere',
				'' !== $instead
					/* translators: 1: post type, 2: ability name. */
					? sprintf( __( 'Content of type %1$s is not edited block by block here. Use %2$s and the abilities next to it.', 'mosmcp-abilities' ), $post->post_type, $instead )
					/* translators: %s: post type. */
					: sprintf( __( 'Content of type %s is internal to WordPress and has no blocks to edit.', 'mosmcp-abilities' ), $post->post_type ),
				Site_Support::CAUSE_INVALID_INPUT,
				false,
				'' !== $instead ? array( 'use_instead' => $instead ) : array()
			);
		}

		if ( 'wp_block' !== $post->post_type && ! post_type_supports( $post->post_type, 'editor' ) ) {
			return Site_Support::error(
				'mosmcp_block_post_type_no_editor',
				sprintf(
					/* translators: %s: post type. */
					__( 'Items of type %s have no content editor, so there are no blocks to edit. Their data lives in fields; use mosmcp/cpt-get and mosmcp/cpt-set-field.', 'mosmcp-abilities' ),
					$post->post_type
				),
				Site_Support::CAUSE_INVALID_INPUT,
				false,
				array( 'use_instead' => 'mosmcp/cpt-get' )
			);
		}

		if ( $write ) {
			$builder = self::builder( $post );
			if ( null !== $builder ) {
				return Site_Support::error(
					'mosmcp_block_page_builder',
					sprintf(
						/* translators: %s: page builder name. */
						__( 'This page is built with %s, which stores the real layout separately from the block content. Editing its blocks here would not change what visitors see, or would be overwritten the next time the builder saves. Use the builder\'s own abilities instead.', 'mosmcp-abilities' ),
						$builder['name']
					),
					Site_Support::CAUSE_CONFLICT,
					false,
					'' !== $builder['instead'] ? array( 'use_instead' => $builder['instead'] ) : array()
				);
			}
		}

		return $post;
	}

	/**
	 * The page builder a post is built with, if any.
	 *
	 * @param WP_Post $post Post.
	 * @return array{name:string,instead:string}|null
	 */
	public static function builder( WP_Post $post ) {
		foreach ( self::BUILDERS as $builder ) {
			$value = get_post_meta( $post->ID, $builder[0], true );

			if ( '*' === $builder[1] ? ! empty( $value ) : (string) $builder[1] === (string) $value ) {
				return array(
					'name'    => $builder[2],
					'instead' => function_exists( 'wp_get_ability' ) && '' !== $builder[3] && wp_get_ability( $builder[3] ) ? $builder[3] : '',
				);
			}
		}

		return null;
	}

	/**
	 * Lets the plugin that owns a block refuse a placement the block abilities cannot make safely.
	 *
	 * The block abilities know nothing about individual block plugins. A plugin
	 * whose blocks need more than valid markup (Kadence keys its generated CSS to a
	 * per-block ID, so a second block with the same ID breaks both) hooks the filter
	 * and returns a WP_Error with the reason and the ability to use instead.
	 *
	 * @param array<int, array<string, mixed>> $nodes   Blocks about to be placed.
	 * @param array<int, array<string, mixed>> $tree    The rest of the page they are going into.
	 * @param string                           $context 'place' for new, replacement or pattern blocks; 'duplicate' for a copy of a block already on the page.
	 * @return true|WP_Error
	 */
	public static function plugin_check( array $nodes, array $tree, $context ) {
		/**
		 * Filters whether blocks may be placed on a page.
		 *
		 * @param true|WP_Error $allowed True to allow, or a WP_Error to refuse with.
		 * @param array         $nodes   Blocks about to be placed (parsed-block arrays).
		 * @param array         $tree    The rest of the page they are going into.
		 * @param string        $context 'place' or 'duplicate'.
		 */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Prefixed via Config::prefix(); the library's prefix is host-configurable and cannot be a literal.
		$allowed = apply_filters( Config::prefix() . '_blocks_check_placement', true, $nodes, $tree, (string) $context );

		return $allowed instanceof WP_Error ? $allowed : true;
	}

	/**
	 * Extra editing advice for a block type, from the plugin that owns it.
	 *
	 * @param string $block_name Block name, such as "core/heading".
	 * @return string[]
	 */
	public static function plugin_hints( $block_name ) {
		/**
		 * Filters the extra editing advice given for a block type.
		 *
		 * @param string[] $hints      Advice sentences, empty by default.
		 * @param string   $block_name Block name.
		 */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Prefixed via Config::prefix(); the library's prefix is host-configurable and cannot be a literal.
		$hints = apply_filters( Config::prefix() . '_blocks_editing_hints', array(), (string) $block_name );

		return array_values( array_filter( array_map( 'strval', (array) $hints ), 'strlen' ) );
	}

	/**
	 * Describes how a post's content is made, for read abilities.
	 *
	 * @param WP_Post                          $post Post.
	 * @param array<int, array<string, mixed>> $tree Parsed content.
	 * @return string 'empty', 'blocks', 'classic', 'mixed' or 'builder:<name>'.
	 */
	public static function editor_kind( WP_Post $post, array $tree ) {
		$builder = self::builder( $post );
		if ( null !== $builder ) {
			return 'builder:' . $builder['name'];
		}

		$named   = 0;
		$classic = 0;
		foreach ( $tree as $node ) {
			if ( Block_Tree::is_whitespace( $node ) ) {
				continue;
			}
			if ( null === $node['blockName'] ) {
				++$classic;
			} else {
				++$named;
			}
		}

		if ( 0 === $named && 0 === $classic ) {
			return 'empty';
		}
		if ( 0 === $named ) {
			return 'classic';
		}

		return 0 === $classic ? 'blocks' : 'mixed';
	}

	/**
	 * Refuses the write when the content changed since the caller read it.
	 *
	 * @param WP_Post              $post  Post.
	 * @param array<string, mixed> $input Ability input.
	 * @return true|WP_Error
	 */
	public static function check_lock( WP_Post $post, array $input ) {
		$expected = isset( $input['expected_modified'] ) ? trim( (string) $input['expected_modified'] ) : '';

		if ( '' === $expected || $expected === (string) $post->post_modified ) {
			return true;
		}

		return Site_Support::error(
			'mosmcp_block_stale',
			sprintf(
				/* translators: 1: modified time the caller read, 2: current modified time. */
				__( 'Nothing was changed: this content was modified at %2$s, after you read it at %1$s, so block paths may have moved. Read it again with mosmcp/block-list and retry with the current paths and modified time.', 'mosmcp-abilities' ),
				$expected,
				(string) $post->post_modified
			),
			Site_Support::CAUSE_CONFLICT,
			true,
			array( 'use_instead' => 'mosmcp/block-list' )
		);
	}

	/**
	 * Refuses when the block at the path is not the block the caller expected.
	 *
	 * @param array<string, mixed> $node  Block found at the path.
	 * @param array<string, mixed> $input Ability input.
	 * @param string               $path  Path, for the message.
	 * @return true|WP_Error
	 */
	public static function check_expected_block( array $node, array $input, $path ) {
		$expected = isset( $input['expected_block'] ) ? self::normalize_name( (string) $input['expected_block'] ) : '';
		$actual   = isset( $node['blockName'] ) ? (string) $node['blockName'] : 'core/freeform';

		if ( '' === $expected || $expected === $actual ) {
			return true;
		}

		return Site_Support::error(
			'mosmcp_block_unexpected',
			sprintf(
				/* translators: 1: path, 2: block found, 3: block expected. */
				__( 'Nothing was changed: the block at path %1$s is %2$s, not %3$s. The content has probably changed since it was read; re-read it with mosmcp/block-list.', 'mosmcp-abilities' ),
				$path,
				$actual,
				$expected
			),
			Site_Support::CAUSE_CONFLICT,
			true,
			array( 'use_instead' => 'mosmcp/block-list' )
		);
	}

	/**
	 * Expands a short block name ("heading") to its full name ("core/heading").
	 *
	 * @param string $name Block name as supplied.
	 * @return string
	 */
	public static function normalize_name( $name ) {
		$name = strtolower( trim( (string) $name ) );

		if ( '' === $name || false !== strpos( $name, '/' ) ) {
			return $name;
		}

		return 'core/' . $name;
	}

	/**
	 * Applies the HTML rule to markup being added, before anything is saved.
	 *
	 * Only the new markup is checked: the rest of the content was already
	 * accepted by WordPress when it was saved.
	 *
	 * @param string                            $markup   Markup being added.
	 * @param WP_Post                           $post     Target post.
	 * @param array<int, array<string, string>> $warnings Warnings accumulator.
	 * @return true|WP_Error
	 */
	public static function check_html( $markup, WP_Post $post, array &$warnings ) {
		$checked = Html_Policy::apply( (string) $markup, Html_Policy::restriction(), Html_Policy::field_label( 'content', $post->post_type ), $warnings );

		return $checked instanceof WP_Error ? $checked : true;
	}

	/**
	 * Saves a changed tree and verifies what was stored.
	 *
	 * @param WP_Post                           $post     Target post.
	 * @param array<int, array<string, mixed>>  $tree     Changed tree.
	 * @param array<int, array<string, string>> $warnings Warnings accumulator.
	 * @return WP_Post|WP_Error The reloaded post.
	 */
	public static function save( WP_Post $post, array $tree, array &$warnings ) {
		$content = Block_Tree::serialize( $tree );

		// Round-trip guard: markup PHP cannot parse back must never be stored.
		$expected = count( Block_Tree::real_offsets( $tree ) );
		$reparsed = count( Block_Tree::real_offsets( Block_Tree::parse( $content ) ) );
		if ( $expected !== $reparsed ) {
			return Site_Support::error(
				'mosmcp_block_round_trip',
				__( 'Nothing was saved: the change produced block markup that does not read back as the same blocks. This is a safety stop; read the block with mosmcp/block-get and use mosmcp/block-replace with complete markup instead.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_WP_CORE,
				false,
				array( 'use_instead' => 'mosmcp/block-replace' )
			);
		}

		/*
		 * wp_slash() because wp_update_post() unslashes what it is given. Block
		 * settings store characters such as &, <, > and " as JSON unicode escapes;
		 * unslashed, every one of those in the whole post would lose its backslash,
		 * breaking links and settings in blocks this edit never touched.
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
				'mosmcp_block_save_failed',
				sprintf(
					/* translators: %s: WordPress error message. */
					__( 'WordPress refused to save the change: %s', 'mosmcp-abilities' ),
					$saved->get_error_message()
				),
				Site_Support::CAUSE_WP_CORE,
				false
			);
		}

		clean_post_cache( (int) $post->ID );
		$reloaded = get_post( (int) $post->ID );

		if ( ! $reloaded instanceof WP_Post ) {
			return Site_Support::error(
				'mosmcp_block_save_failed',
				__( 'The content could not be read back after saving.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_WP_CORE,
				false
			);
		}

		foreach ( Html_Policy::save_warnings( $content, (string) $reloaded->post_content, Html_Policy::field_label( 'content', $reloaded->post_type ) ) as $warning ) {
			$warnings[] = $warning;
		}

		return $reloaded;
	}

	/**
	 * Links and context every block result carries.
	 *
	 * @param WP_Post $post Post after the change.
	 * @return array<string, mixed>
	 */
	public static function post_context( WP_Post $post ) {
		$published = in_array( $post->post_status, array( 'publish', 'private' ), true );

		return array(
			'id'          => (int) $post->ID,
			'post_type'   => (string) $post->post_type,
			'title'       => (string) get_the_title( $post ),
			'status'      => (string) $post->post_status,
			'modified'    => (string) $post->post_modified,
			'view_url'    => $published ? (string) get_permalink( $post ) : '',
			'preview_url' => $published ? '' : (string) get_preview_post_link( $post ),
			'edit_url'    => (string) get_edit_post_link( $post->ID, 'raw' ),
		);
	}

	/**
	 * Makes sure a revision holds the content as it is now, before it is changed,
	 * and returns its ID: the version to restore to undo the coming change.
	 *
	 * WordPress saves a revision AFTER each update, holding the new content, so
	 * the newest revision once a change is saved is the changed version, and
	 * restoring it undoes nothing. The undo point has to be captured first.
	 * wp_save_post_revision() only adds a revision when the content differs from
	 * the newest one, so this never duplicates a revision that already matches.
	 *
	 * @param WP_Post $post Post, before the change.
	 * @return int Revision ID, or 0 when revisions are off for this content.
	 */
	public static function snapshot_revision( WP_Post $post ) {
		if ( ! wp_revisions_enabled( $post ) ) {
			return 0;
		}

		$made = wp_save_post_revision( $post->ID );
		if ( $made && ! is_wp_error( $made ) ) {
			return (int) $made;
		}

		$revisions = wp_get_post_revisions(
			$post->ID,
			array(
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);

		return $revisions ? (int) reset( $revisions ) : 0;
	}

	/**
	 * Notes every write result includes.
	 *
	 * @param WP_Post $post        Post after the change.
	 * @param int     $revision_id Revision that holds the version before the change, or 0.
	 * @return string[]
	 */
	public static function write_notes( WP_Post $post, $revision_id ) {
		$notes = array();

		if ( $revision_id > 0 ) {
			$notes[] = sprintf(
				/* translators: %d: revision ID. */
				__( 'The version before this change is saved as revision %d. To undo the change, restore that revision with mosmcp/revision-restore.', 'mosmcp-abilities' ),
				$revision_id
			);
		} else {
			$notes[] = __( 'Revisions are switched off for this content, so this change cannot be undone from a revision.', 'mosmcp-abilities' );
		}

		if ( 'wp_block' === $post->post_type ) {
			$notes[] = __( 'This is a synced pattern: the change shows everywhere the pattern is used.', 'mosmcp-abilities' );
		}

		if ( ! in_array( $post->post_status, array( 'publish', 'private' ), true ) ) {
			$notes[] = __( 'The content is not published, so visitors do not see it yet. The preview link works for signed-in editors; mosmcp/preview-link-create makes a link anyone can open.', 'mosmcp-abilities' );
		}

		return $notes;
	}

	/**
	 * One outline row for a block.
	 *
	 * @param array<string, mixed> $node    Block node.
	 * @param int[]                $indices Its path.
	 * @return array<string, mixed>
	 */
	public static function outline_row( array $node, array $indices ) {
		$name = isset( $node['blockName'] ) && null !== $node['blockName'] ? (string) $node['blockName'] : 'core/freeform';
		$type = WP_Block_Type_Registry::get_instance()->get_registered( $name );

		return array(
			'path'        => Block_Tree::path_string( $indices ),
			'depth'       => count( $indices ) - 1,
			'block'       => $name,
			'title'       => $type && ! empty( $type->title ) ? (string) $type->title : ( 'core/freeform' === $name ? __( 'Classic', 'mosmcp-abilities' ) : $name ),
			'text'        => Block_Tree::text( $node, 140 ),
			'child_count' => count( Block_Tree::children( $node ) ),
			'fields'      => Block_Text::fields_for( $node ),
			'key_attrs'   => self::key_attrs( $node ),
			// Built entirely when the page is shown: a render callback AND no saved HTML.
			'is_dynamic'  => $type && $type->is_dynamic() && Block_Tree::is_comment_only( $node ),
		);
	}

	/**
	 * The attributes worth showing in an outline, as strings.
	 *
	 * @param array<string, mixed> $node Block node.
	 * @return array<string, string>|\stdClass An object when empty, so it encodes as {} not [].
	 */
	public static function key_attrs( array $node ) {
		$attrs = isset( $node['attrs'] ) && is_array( $node['attrs'] ) ? $node['attrs'] : array();
		$out   = array();

		foreach ( self::KEY_ATTRS as $key ) {
			if ( ! array_key_exists( $key, $attrs ) ) {
				continue;
			}
			$value = $attrs[ $key ];
			if ( is_bool( $value ) ) {
				$out[ $key ] = $value ? 'true' : 'false';
			} elseif ( is_scalar( $value ) ) {
				$out[ $key ] = (string) $value;
			} elseif ( 'layout' === $key && is_array( $value ) && isset( $value['type'] ) ) {
				$out[ $key ] = (string) $value['type'];
			}
		}

		return empty( $out ) ? new \stdClass() : $out;
	}

	/**
	 * Names of every ancestor of a path, nearest first.
	 *
	 * @param array<int, array<string, mixed>> $tree    Block tree.
	 * @param int[]                            $indices Path of the parent (the ancestors start there).
	 * @return string[]
	 */
	public static function ancestor_names( array $tree, array $indices ) {
		$names = array();

		while ( ! empty( $indices ) ) {
			$node = Block_Tree::node_at( $tree, $indices );
			if ( $node && ! empty( $node['blockName'] ) ) {
				$names[] = (string) $node['blockName'];
			}
			array_pop( $indices );
		}

		return $names;
	}
}
