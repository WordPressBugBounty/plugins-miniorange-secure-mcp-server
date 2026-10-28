<?php
/**
 * Changes the text of one block without touching its markup around it.
 *
 * A block's text lives inside its saved HTML: a paragraph's in its <p>, a
 * button's in its <a>, an image caption in its <figcaption>. Replacing that one
 * element's contents, and nothing else, keeps the block's classes, styles and
 * attributes exactly as the editor saved them, so the block stays valid.
 *
 * Text is allowed inline formatting (bold, italic, links, line breaks), the same
 * as typing in the editor. Block-level tags and HTML comments are refused: a
 * <div> inside a paragraph, or a block delimiter inside text, would change the
 * structure of the page rather than the words on it.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Blocks;

use MoSMCP\Abilities\Packs\Site\Site_Support;
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
 * Class Block_Text
 */
class Block_Text {

	/**
	 * Block-level tags that may not appear inside inline text.
	 *
	 * @var string[]
	 */
	const BLOCK_TAGS = array( 'address', 'article', 'aside', 'blockquote', 'details', 'div', 'dl', 'fieldset', 'figcaption', 'figure', 'footer', 'form', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'hr', 'li', 'main', 'nav', 'ol', 'p', 'pre', 'section', 'table', 'ul' );

	/**
	 * Where each core block keeps each editable text field: field => element tag(s).
	 *
	 * @var array<string, array<string, string>>
	 */
	const FIELDS = array(
		'core/paragraph'    => array( 'text' => 'p' ),
		'core/heading'      => array( 'text' => 'h1|h2|h3|h4|h5|h6' ),
		'core/list-item'    => array( 'text' => 'li' ),
		'core/button'       => array( 'text' => 'a|button' ),
		'core/preformatted' => array( 'text' => 'pre' ),
		'core/verse'        => array( 'text' => 'pre' ),
		'core/code'         => array( 'text' => 'code' ),
		'core/pullquote'    => array(
			'text'     => 'p',
			'citation' => 'cite',
		),
		'core/quote'        => array( 'citation' => 'cite' ),
		'core/details'      => array( 'text' => 'summary' ),
		'core/image'        => array( 'caption' => 'figcaption' ),
		'core/video'        => array( 'caption' => 'figcaption' ),
		'core/audio'        => array( 'caption' => 'figcaption' ),
		'core/embed'        => array( 'caption' => 'figcaption' ),
		'core/table'        => array( 'caption' => 'figcaption' ),
	);

	/**
	 * Blocks whose whole saved HTML is the editable text.
	 *
	 * @var string[]
	 */
	const RAW = array( 'core/html', 'core/shortcode' );

	/**
	 * The fields a block offers, for read abilities.
	 *
	 * @param array<string, mixed> $node Block node.
	 * @return string[]
	 */
	public static function fields_for( array $node ) {
		$name = isset( $node['blockName'] ) ? (string) $node['blockName'] : '';

		if ( in_array( $name, self::RAW, true ) ) {
			return array( 'text' );
		}

		if ( isset( self::FIELDS[ $name ] ) ) {
			return array_keys( self::FIELDS[ $name ] );
		}

		return null !== self::generic_target( $node ) ? array( 'text' ) : array();
	}

	/**
	 * Refuses text that would change structure rather than words.
	 *
	 * @param string $text Proposed text.
	 * @return true|WP_Error
	 */
	public static function check_inline( $text ) {
		$text = (string) $text;

		if ( false !== strpos( $text, '<!--' ) || false !== strpos( $text, '-->' ) ) {
			return self::comment_error();
		}

		if ( preg_match_all( '/<\/?([a-zA-Z][a-zA-Z0-9]*)\b/', $text, $matches ) ) {
			$block = array_values( array_intersect( array_unique( array_map( 'strtolower', $matches[1] ) ), self::BLOCK_TAGS ) );
			if ( $block ) {
				return Site_Support::error(
					'mosmcp_block_text_structure',
					sprintf(
						/* translators: %s: list of tags. */
						__( 'Text can carry inline formatting only (bold, italic, links, line breaks), but this contains %s, which would change the page structure. Insert separate blocks for separate paragraphs or sections with mosmcp/block-insert.', 'mosmcp-abilities' ),
						'<' . implode( '>, <', $block ) . '>'
					),
					Site_Support::CAUSE_INVALID_INPUT,
					true,
					array( 'use_instead' => 'mosmcp/block-insert' )
				);
			}
		}

		return true;
	}

	/**
	 * The refusal for HTML comments inside text.
	 *
	 * @return WP_Error
	 */
	public static function comment_error() {
		return Site_Support::error(
			'mosmcp_block_text_comment',
			__( 'Text may not contain HTML comments ("<!--" or "-->"). WordPress stores block boundaries as comments, so one inside text would split or corrupt the page.', 'mosmcp-abilities' ),
			Site_Support::CAUSE_INVALID_INPUT,
			true
		);
	}

	/**
	 * Replaces one text field of a block, in place.
	 *
	 * @param array<string, mixed> $node  Block node (by reference).
	 * @param string               $field 'text', 'caption' or 'citation'.
	 * @param string               $text  New text (inline HTML allowed).
	 * @return array{previous:string}|WP_Error The previous text on success.
	 */
	public static function set( array &$node, $field, $text ) {
		$name  = isset( $node['blockName'] ) ? (string) $node['blockName'] : '';
		$field = '' === (string) $field ? 'text' : (string) $field;

		if ( '' === $name || 'core/freeform' === $name ) {
			return Site_Support::error(
				'mosmcp_block_text_classic',
				__( 'This is classic-editor content (one "Classic" block holding plain HTML), so it has no separate blocks to edit. Change its wording with mosmcp/content-replace-in-post, which edits part of the text in place.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_INVALID_INPUT,
				false,
				array( 'use_instead' => 'mosmcp/content-replace-in-post' )
			);
		}

		if ( in_array( $name, self::RAW, true ) ) {
			if ( 'text' !== $field ) {
				return self::field_error( $name, $field, array( 'text' ) );
			}
			if ( false !== strpos( (string) $text, '<!--' ) ) {
				return self::comment_error();
			}
			$previous             = Block_Tree::html_of( Block_Tree::inner_content( $node ) );
			$node['innerHTML']    = "\n" . trim( (string) $text ) . "\n";
			$node['innerContent'] = array( $node['innerHTML'] );
			return array( 'previous' => trim( $previous ) );
		}

		$check = self::check_inline( $text );
		if ( $check instanceof WP_Error ) {
			return $check;
		}

		if ( isset( self::FIELDS[ $name ] ) ) {
			if ( ! isset( self::FIELDS[ $name ][ $field ] ) ) {
				return self::field_error( $name, $field, array_keys( self::FIELDS[ $name ] ) );
			}
			$tags = self::FIELDS[ $name ][ $field ];
		} else {
			$tags = 'text' === $field ? self::generic_target( $node ) : null;
			if ( null === $tags ) {
				return Site_Support::error(
					'mosmcp_block_text_unsupported',
					sprintf(
						/* translators: %s: block name. */
						__( 'The %s block does not keep its text in one simple element that can be changed safely on its own. Rewrite the block with mosmcp/block-replace (read its current markup with mosmcp/block-get first), or edit it with that plugin\'s own abilities if it has them.', 'mosmcp-abilities' ),
						$name
					),
					Site_Support::CAUSE_INVALID_INPUT,
					false,
					array( 'use_instead' => 'mosmcp/block-replace' )
				);
			}
		}

		$content = Block_Tree::inner_content( $node );

		foreach ( $content as $i => $chunk ) {
			if ( ! is_string( $chunk ) ) {
				continue;
			}

			$swapped = self::swap_element( $chunk, $tags, (string) $text );
			if ( null !== $swapped ) {
				$content[ $i ]        = $swapped['html'];
				$node['innerContent'] = $content;
				$node['innerHTML']    = Block_Tree::html_of( $content );
				return array( 'previous' => $swapped['previous'] );
			}
		}

		// A caption the block does not have yet is added before its closing </figure>.
		if ( 'caption' === $field ) {
			foreach ( $content as $i => $chunk ) {
				$at = is_string( $chunk ) ? strrpos( $chunk, '</figure>' ) : false;
				if ( false !== $at ) {
					$content[ $i ]        = substr( $chunk, 0, $at ) . '<figcaption class="wp-element-caption">' . (string) $text . '</figcaption>' . substr( $chunk, $at );
					$node['innerContent'] = $content;
					$node['innerHTML']    = Block_Tree::html_of( $content );
					return array( 'previous' => '' );
				}
			}
		}

		return Site_Support::error(
			'mosmcp_block_text_not_found',
			sprintf(
				/* translators: 1: field name, 2: block name. */
				__( 'This %2$s block has no %1$s in its saved markup to change. Read it with mosmcp/block-get to see its markup, and use mosmcp/block-replace to rewrite it.', 'mosmcp-abilities' ),
				$field,
				$name
			),
			Site_Support::CAUSE_INVALID_INPUT,
			false,
			array( 'use_instead' => 'mosmcp/block-replace' )
		);
	}

	/**
	 * Replaces the contents of the first element matching one of the tags.
	 *
	 * The element's own opening tag (classes, styles, attributes) and closing tag
	 * are kept. Nested elements with the same tag name are counted, so the right
	 * closing tag is found.
	 *
	 * @param string $html Markup chunk.
	 * @param string $tags Tag names separated by "|".
	 * @param string $text Replacement contents.
	 * @return array{html:string,previous:string}|null Null when no such element is in the chunk.
	 */
	private static function swap_element( $html, $tags, $text ) {
		if ( ! preg_match( '/<(' . $tags . ')(\s[^>]*)?>/i', $html, $open, PREG_OFFSET_CAPTURE ) ) {
			return null;
		}

		$tag   = strtolower( $open[1][0] );
		$start = (int) $open[0][1] + strlen( $open[0][0] );
		$end   = self::closing_offset( $html, $tag, $start );

		if ( null === $end ) {
			// An element closed in a later chunk (a list item with a nested list):
			// its own text runs to the end of this chunk.
			$end = strlen( $html );
		}

		return array(
			'html'     => substr( $html, 0, $start ) . $text . substr( $html, $end ),
			'previous' => substr( $html, $start, $end - $start ),
		);
	}

	/**
	 * Offset of the closing tag that matches an element opened before $from.
	 *
	 * @param string $html Markup.
	 * @param string $tag  Tag name.
	 * @param int    $from Offset just after the opening tag.
	 * @return int|null
	 */
	private static function closing_offset( $html, $tag, $from ) {
		$depth = 1;

		if ( ! preg_match_all( '/<(\/?)' . preg_quote( $tag, '/' ) . '(\s[^>]*)?>/i', $html, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER, $from ) ) {
			return null;
		}

		foreach ( $matches as $match ) {
			$depth += '/' === $match[1][0] ? -1 : 1;
			if ( 0 === $depth ) {
				return (int) $match[0][1];
			}
		}

		return null;
	}

	/**
	 * The tag holding a third-party block's text, when its markup is one simple element.
	 *
	 * Only a leaf block saved as a single element whose contents are inline text
	 * qualifies; anything richer is refused rather than guessed at.
	 *
	 * @param array<string, mixed> $node Block node.
	 * @return string|null
	 */
	private static function generic_target( array $node ) {
		if ( ! empty( $node['innerBlocks'] ) ) {
			return null;
		}

		$html = trim( Block_Tree::html_of( Block_Tree::inner_content( $node ) ) );

		if ( ! preg_match( '/^<([a-zA-Z][a-zA-Z0-9]*)(\s[^>]*)?>(.*)<\/\1>$/s', $html, $m ) ) {
			return null;
		}

		if ( preg_match_all( '/<\/?([a-zA-Z][a-zA-Z0-9]*)\b/', $m[3], $inner ) && array_intersect( array_map( 'strtolower', $inner[1] ), self::BLOCK_TAGS ) ) {
			return null;
		}

		return strtolower( $m[1] );
	}

	/**
	 * Refusal for a field the block does not have.
	 *
	 * @param string   $name   Block name.
	 * @param string   $field  Requested field.
	 * @param string[] $fields Fields it does have.
	 * @return WP_Error
	 */
	private static function field_error( $name, $field, array $fields ) {
		if ( 'core/quote' === $name && 'text' === $field ) {
			return Site_Support::error(
				'mosmcp_block_text_container',
				__( 'A quote holds its words in paragraph blocks inside it. Change those with the path of the inner paragraph (shown by mosmcp/block-list), or set field "citation" to change the attribution.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_INVALID_INPUT,
				true
			);
		}

		return Site_Support::error(
			'mosmcp_block_text_field',
			sprintf(
				/* translators: 1: block name, 2: requested field, 3: available fields. */
				__( 'A %1$s block has no "%2$s" field. Its editable fields are: %3$s.', 'mosmcp-abilities' ),
				$name,
				$field,
				empty( $fields ) ? __( 'none', 'mosmcp-abilities' ) : implode( ', ', $fields )
			),
			Site_Support::CAUSE_INVALID_INPUT,
			true
		);
	}
}
