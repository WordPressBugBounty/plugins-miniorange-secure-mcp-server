<?php
/**
 * Validates block markup an AI supplies, and builds markup for common blocks.
 *
 * Markup from a caller is parsed and checked before it goes anywhere near a
 * post: every block must be one this site has registered (a mistyped name would
 * otherwise be stored and shown in the editor as a broken block), stray text
 * outside blocks is refused, and each block must be allowed where it is being
 * put (a list item only inside a list, a column only inside columns).
 *
 * The shorthand builders produce exactly what the block editor itself saves for
 * those blocks, so a block inserted here opens in the editor as a normal,
 * valid block rather than one it offers to "attempt recovery" on.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Blocks;

use MoSMCP\Abilities\Packs\Site\Site_Support;
use WP_Block_Patterns_Registry;
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
 * directive covers IDE and Plugin Check runs that don't read that ruleset).
 */
// phpcs:disable WordPress.WP.I18n.TextDomainMismatch

/**
 * Class Block_Markup
 */
class Block_Markup {

	/**
	 * Largest markup accepted in one call, in bytes.
	 */
	const MAX_BYTES = 262144;

	/**
	 * Block types the shorthand can build.
	 *
	 * @var string[]
	 */
	const SHORTHAND_TYPES = array( 'paragraph', 'heading', 'list', 'quote', 'button', 'image', 'separator', 'spacer', 'html' );

	/**
	 * Parses and validates caller-supplied block markup.
	 *
	 * @param string $markup Block markup.
	 * @return array<int, array<string, mixed>>|WP_Error The real top-level blocks it contains.
	 */
	public static function parse_supplied( $markup ) {
		$markup = (string) $markup;

		if ( strlen( $markup ) > self::MAX_BYTES ) {
			return Site_Support::error(
				'mosmcp_block_markup_too_large',
				sprintf(
					/* translators: %d: maximum size in kilobytes. */
					__( 'The markup is larger than %d KB. Insert it in smaller parts.', 'mosmcp-abilities' ),
					(int) ( self::MAX_BYTES / 1024 )
				),
				Site_Support::CAUSE_INVALID_INPUT,
				true
			);
		}

		if ( '' === trim( $markup ) ) {
			return Site_Support::error(
				'mosmcp_block_markup_empty',
				__( 'No block markup was supplied.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_INVALID_INPUT,
				true
			);
		}

		$nodes = array_values( array_filter( Block_Tree::parse( $markup ), array( Block_Tree::class, 'is_real' ) ) );

		foreach ( $nodes as $node ) {
			if ( null === $node['blockName'] ) {
				return Site_Support::error(
					'mosmcp_block_markup_not_blocks',
					sprintf(
						/* translators: %s: start of the stray text. */
						__( 'The markup contains text or HTML outside any block ("%s"). Wrap every piece in block comments, for example <!-- wp:paragraph --><p>Text</p><!-- /wp:paragraph -->, or pass simple blocks through the "blocks" input instead and let them be built for you.', 'mosmcp-abilities' ),
						self::snippet( (string) $node['innerHTML'] )
					),
					Site_Support::CAUSE_INVALID_INPUT,
					true
				);
			}
		}

		if ( empty( $nodes ) ) {
			return Site_Support::error(
				'mosmcp_block_markup_empty',
				__( 'The markup contains no blocks.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_INVALID_INPUT,
				true
			);
		}

		$unknown = array();
		foreach ( $nodes as $node ) {
			Block_Tree::walk(
				array( $node ),
				static function ( $block ) use ( &$unknown ) {
					$name = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';
					if ( '' !== $name && ! WP_Block_Type_Registry::get_instance()->is_registered( $name ) ) {
						$unknown[ $name ] = true;
					}
				}
			);
		}

		if ( $unknown ) {
			$names = array_keys( $unknown );
			return Site_Support::error(
				'mosmcp_block_type_unknown',
				sprintf(
					/* translators: 1: comma-separated block names, 2: suggestions. */
					__( 'This site has no block type named %1$s, so the editor would show it as broken. %2$s Use mosmcp/block-types-list to see the block types available here.', 'mosmcp-abilities' ),
					implode( ', ', $names ),
					self::suggest( $names[0] )
				),
				Site_Support::CAUSE_INVALID_INPUT,
				true,
				array( 'use_instead' => 'mosmcp/block-types-list' )
			);
		}

		return $nodes;
	}

	/**
	 * Builds blocks from the shorthand list.
	 *
	 * @param mixed $items Shorthand items.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public static function from_shorthand( $items ) {
		if ( ! is_array( $items ) || empty( $items ) ) {
			return Site_Support::error(
				'mosmcp_block_shorthand_empty',
				__( 'The "blocks" input must be a non-empty list.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_INVALID_INPUT,
				true
			);
		}

		$markup = array();

		foreach ( array_values( $items ) as $i => $item ) {
			$built = self::shorthand_markup( is_array( $item ) ? $item : array(), $i );
			if ( $built instanceof WP_Error ) {
				return $built;
			}
			$markup[] = $built;
		}

		return self::parse_supplied( implode( Block_Tree::SEPARATOR, $markup ) );
	}

	/**
	 * Returns the blocks of a registered pattern.
	 *
	 * @param string $name Pattern name.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public static function from_pattern( $name ) {
		$name     = trim( (string) $name );
		$registry = WP_Block_Patterns_Registry::get_instance();

		if ( '' === $name || ! $registry->is_registered( $name ) ) {
			return Site_Support::error(
				'mosmcp_pattern_not_found',
				sprintf(
					/* translators: %s: pattern name. */
					__( 'No pattern named "%s" is registered on this site. List the available ones with mosmcp/theme-pattern-list.', 'mosmcp-abilities' ),
					$name
				),
				Site_Support::CAUSE_NOT_FOUND,
				false,
				array( 'use_instead' => 'mosmcp/theme-pattern-list' )
			);
		}

		$pattern = $registry->get_registered( $name );
		$content = is_array( $pattern ) && isset( $pattern['content'] ) ? (string) $pattern['content'] : '';

		return self::parse_supplied( $content );
	}

	/**
	 * Returns a reference block for a synced pattern.
	 *
	 * @param int $id Synced pattern (wp_block) post ID.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public static function from_synced_pattern( $id ) {
		$post = get_post( absint( $id ) );

		if ( ! $post || 'wp_block' !== $post->post_type ) {
			return Site_Support::error(
				'mosmcp_synced_pattern_not_found',
				__( 'No synced pattern (reusable block) has that ID. Synced patterns are posts of type wp_block; find their IDs with mosmcp/cpt-list using post_type "wp_block".', 'mosmcp-abilities' ),
				Site_Support::CAUSE_NOT_FOUND,
				false
			);
		}

		if ( 'publish' !== $post->post_status ) {
			return Site_Support::error(
				'mosmcp_synced_pattern_unpublished',
				__( 'That synced pattern is not published, so it would render nothing for visitors. Publish it first.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_CONFLICT,
				true
			);
		}

		return self::parse_supplied( '<!-- wp:block ' . wp_json_encode( array( 'ref' => (int) $post->ID ) ) . ' /-->' );
	}

	/**
	 * Refuses blocks that may not sit inside the given parent.
	 *
	 * Block types declare `parent` (must be a direct child of one of these) and
	 * `ancestor` (must be somewhere inside one of these). A list item outside a
	 * list, or a column outside columns, is markup the editor cannot display.
	 *
	 * @param array<int, array<string, mixed>> $nodes     Blocks being placed.
	 * @param string|null                      $parent_name    Name of the direct parent, or null at the top level.
	 * @param string[]                         $ancestors Names of every ancestor, nearest first.
	 * @return true|WP_Error
	 */
	public static function check_placement( array $nodes, $parent_name, array $ancestors ) {
		$registry = WP_Block_Type_Registry::get_instance();

		if ( null !== $parent_name ) {
			$parent_type = $registry->get_registered( (string) $parent_name );
			if ( $parent_type && isset( $parent_type->allowed_blocks ) && is_array( $parent_type->allowed_blocks ) && ! empty( $parent_type->allowed_blocks ) ) {
				foreach ( $nodes as $node ) {
					if ( ! in_array( (string) $node['blockName'], $parent_type->allowed_blocks, true ) ) {
						return self::placement_error( (string) $node['blockName'], $parent_name, $parent_type->allowed_blocks, 'allowed' );
					}
				}
			}
		}

		foreach ( $nodes as $node ) {
			$name = (string) $node['blockName'];
			$type = $registry->get_registered( $name );

			if ( ! $type ) {
				continue;
			}

			if ( ! empty( $type->parent ) && is_array( $type->parent ) && ( null === $parent_name || ! in_array( $parent_name, $type->parent, true ) ) ) {
				return self::placement_error( $name, $parent_name, $type->parent, 'parent' );
			}

			if ( ! empty( $type->ancestor ) && is_array( $type->ancestor ) && ! array_intersect( $type->ancestor, $ancestors ) ) {
				return self::placement_error( $name, $parent_name, $type->ancestor, 'ancestor' );
			}
		}

		return true;
	}

	/**
	 * Builds the editor's own markup for one shorthand block.
	 *
	 * @param array<string, mixed> $item  Shorthand item.
	 * @param int                  $index Position in the list, for messages.
	 * @return string|WP_Error
	 */
	private static function shorthand_markup( array $item, $index ) {
		$type = isset( $item['type'] ) ? strtolower( trim( (string) $item['type'] ) ) : '';
		$text = isset( $item['text'] ) ? (string) $item['text'] : '';

		if ( ! in_array( $type, self::SHORTHAND_TYPES, true ) ) {
			return Site_Support::error(
				'mosmcp_block_shorthand_type',
				sprintf(
					/* translators: 1: item number, 2: list of supported types. */
					__( 'Item %1$d has no supported "type". The shorthand builds: %2$s. For any other block, pass its block markup in "markup" instead.', 'mosmcp-abilities' ),
					$index + 1,
					implode( ', ', self::SHORTHAND_TYPES )
				),
				Site_Support::CAUSE_INVALID_INPUT,
				true
			);
		}

		if ( 'html' !== $type ) {
			$bad = Block_Text::check_inline( $text );
			if ( $bad instanceof WP_Error ) {
				return $bad;
			}
		}

		switch ( $type ) {
			case 'paragraph':
				return "<!-- wp:paragraph -->\n<p>" . $text . "</p>\n<!-- /wp:paragraph -->";

			case 'heading':
				$level = isset( $item['level'] ) ? (int) $item['level'] : 2;
				$level = max( 1, min( 6, $level ) );
				$attrs = 2 === $level ? '' : ' ' . wp_json_encode( array( 'level' => $level ) );
				return '<!-- wp:heading' . $attrs . " -->\n<h" . $level . ' class="wp-block-heading">' . $text . '</h' . $level . ">\n<!-- /wp:heading -->";

			case 'list':
				$items = isset( $item['items'] ) && is_array( $item['items'] ) ? array_values( $item['items'] ) : array();
				if ( empty( $items ) ) {
					return Site_Support::error(
						'mosmcp_block_shorthand_list',
						__( 'A list needs a non-empty "items" array of strings.', 'mosmcp-abilities' ),
						Site_Support::CAUSE_INVALID_INPUT,
						true
					);
				}
				$ordered = ! empty( $item['ordered'] );
				$tag     = $ordered ? 'ol' : 'ul';
				$lis     = array();
				foreach ( $items as $li ) {
					$bad = Block_Text::check_inline( (string) $li );
					if ( $bad instanceof WP_Error ) {
						return $bad;
					}
					$lis[] = "<!-- wp:list-item -->\n<li>" . (string) $li . "</li>\n<!-- /wp:list-item -->";
				}
				$attrs = $ordered ? ' ' . wp_json_encode( array( 'ordered' => true ) ) : '';
				return '<!-- wp:list' . $attrs . " -->\n<" . $tag . ' class="wp-block-list">' . implode( Block_Tree::SEPARATOR, $lis ) . '</' . $tag . ">\n<!-- /wp:list -->";

			case 'quote':
				$cite  = isset( $item['citation'] ) ? (string) $item['citation'] : '';
				$bad   = Block_Text::check_inline( $cite );
				$inner = "<!-- wp:paragraph -->\n<p>" . $text . "</p>\n<!-- /wp:paragraph -->";
				if ( $bad instanceof WP_Error ) {
					return $bad;
				}
				return "<!-- wp:quote -->\n<blockquote class=\"wp-block-quote\">" . $inner . ( '' !== $cite ? '<cite>' . $cite . '</cite>' : '' ) . "</blockquote>\n<!-- /wp:quote -->";

			case 'button':
				$url  = isset( $item['url'] ) ? esc_url_raw( (string) $item['url'] ) : '';
				$href = '' !== $url ? ' href="' . esc_url( $url ) . '"' : '';
				return "<!-- wp:buttons -->\n<div class=\"wp-block-buttons\"><!-- wp:button -->\n<div class=\"wp-block-button\"><a class=\"wp-block-button__link wp-element-button\"" . $href . '>' . $text . "</a></div>\n<!-- /wp:button --></div>\n<!-- /wp:buttons -->";

			case 'image':
				return self::image_markup( $item );

			case 'separator':
				return "<!-- wp:separator -->\n<hr class=\"wp-block-separator has-alpha-channel-opacity\"/>\n<!-- /wp:separator -->";

			case 'spacer':
				$height = isset( $item['height'] ) ? max( 1, min( 1000, (int) $item['height'] ) ) : 40;
				return '<!-- wp:spacer ' . wp_json_encode( array( 'height' => $height . 'px' ) ) . " -->\n<div style=\"height:" . $height . 'px" aria-hidden="true" class="wp-block-spacer"></div>' . "\n<!-- /wp:spacer -->";

			case 'html':
				if ( false !== strpos( $text, '<!--' ) ) {
					return Block_Text::comment_error();
				}
				return "<!-- wp:html -->\n" . $text . "\n<!-- /wp:html -->";
		}

		return '';
	}

	/**
	 * Builds an image block from a media library item.
	 *
	 * @param array<string, mixed> $item Shorthand item.
	 * @return string|WP_Error
	 */
	private static function image_markup( array $item ) {
		$id   = isset( $item['attachment_id'] ) ? absint( $item['attachment_id'] ) : 0;
		$size = isset( $item['size'] ) ? sanitize_key( (string) $item['size'] ) : 'large';

		if ( $id < 1 || ! wp_attachment_is_image( $id ) ) {
			return Site_Support::error(
				'mosmcp_block_shorthand_image',
				__( 'An image needs "attachment_id", the ID of an image in the media library. Upload one first with mosmcp/media-upload-from-url and use the attachment_id it returns.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_INVALID_INPUT,
				true,
				array( 'use_instead' => 'mosmcp/media-upload-from-url' )
			);
		}

		$src = wp_get_attachment_image_url( $id, $size );
		if ( ! $src ) {
			$size = 'full';
			$src  = wp_get_attachment_image_url( $id, 'full' );
		}

		$alt = isset( $item['alt'] ) ? (string) $item['alt'] : (string) get_post_meta( $id, '_wp_attachment_image_alt', true );

		$attrs = array(
			'id'              => $id,
			'sizeSlug'        => $size,
			'linkDestination' => 'none',
		);

		return '<!-- wp:image ' . wp_json_encode( $attrs ) . " -->\n<figure class=\"wp-block-image size-" . esc_attr( $size ) . '"><img src="' . esc_url( (string) $src ) . '" alt="' . esc_attr( $alt ) . '" class="wp-image-' . $id . "\"/></figure>\n<!-- /wp:image -->";
	}

	/**
	 * A placement refusal naming where the block may go.
	 *
	 * @param string      $name    Block being placed.
	 * @param string|null $parent_name  Where it was going.
	 * @param string[]    $allowed Allowed parents, ancestors or children.
	 * @param string      $rule    'parent', 'ancestor' or 'allowed'.
	 * @return WP_Error
	 */
	private static function placement_error( $name, $parent_name, array $allowed, $rule ) {
		$where = null === $parent_name ? __( 'the top level of the content', 'mosmcp-abilities' ) : $parent_name;

		if ( 'allowed' === $rule ) {
			/* translators: 1: parent block, 2: child block, 3: allowed child blocks. */
			$message = sprintf( __( 'A %1$s block only accepts %3$s inside it, so %2$s cannot go there.', 'mosmcp-abilities' ), $where, $name, implode( ', ', $allowed ) );
		} elseif ( 'parent' === $rule ) {
			/* translators: 1: block, 2: where it was going, 3: allowed parents. */
			$message = sprintf( __( 'A %1$s block can only be placed directly inside %3$s, not in %2$s. Insert it inside one of those (position "inside_start" or "inside_end" with that block as target).', 'mosmcp-abilities' ), $name, $where, implode( ', ', $allowed ) );
		} else {
			/* translators: 1: block, 2: where it was going, 3: allowed ancestors. */
			$message = sprintf( __( 'A %1$s block can only be used somewhere inside %3$s, not in %2$s.', 'mosmcp-abilities' ), $name, $where, implode( ', ', $allowed ) );
		}

		return Site_Support::error( 'mosmcp_block_placement', $message, Site_Support::CAUSE_INVALID_INPUT, true );
	}

	/**
	 * Suggests registered block names close to an unknown one.
	 *
	 * @param string $name Unknown block name.
	 * @return string Sentence, or ''.
	 */
	private static function suggest( $name ) {
		$short = strtolower( (string) preg_replace( '#^.*/#', '', (string) $name ) );
		$hits  = array();

		foreach ( array_keys( WP_Block_Type_Registry::get_instance()->get_all_registered() ) as $registered ) {
			if ( '' !== $short && false !== strpos( (string) $registered, $short ) ) {
				$hits[] = (string) $registered;
			}
			if ( count( $hits ) >= 5 ) {
				break;
			}
		}

		if ( empty( $hits ) ) {
			return '';
		}

		/* translators: %s: comma-separated block names. */
		return sprintf( __( 'Similar block types here: %s.', 'mosmcp-abilities' ), implode( ', ', $hits ) );
	}

	/**
	 * A short, single-line extract of some markup for messages.
	 *
	 * @param string $html Markup.
	 * @return string
	 */
	private static function snippet( $html ) {
		$text = trim( (string) preg_replace( '/\s+/', ' ', (string) $html ) );
		return strlen( $text ) > 60 ? substr( $text, 0, 60 ) . '…' : $text;
	}
}
