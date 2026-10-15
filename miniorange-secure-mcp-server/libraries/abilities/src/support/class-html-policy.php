<?php
/**
 * The rule for markup an ability saves on a user's behalf.
 *
 * WordPress lets a user with the unfiltered_html capability save any markup â€”
 * forms, scripts, styles, embeds â€” and runs everyone else's through
 * wp_kses_post(). An ability acting as a user follows the same rule, so the AI
 * can store exactly what that user could store by hand in the editor: no more,
 * and no less.
 *
 * Where WordPress filters silently, this does it loudly. Removing a whole
 * element leaves part of the page missing, or the body of a <script> or <style>
 * on the page as visible text, so when a user's markup would lose an element
 * the write is refused with the reason instead of saved. Attributes removed
 * from an element that is kept are saved and reported as a warning.
 *
 * It also checks what was actually stored. WordPress unslashes post fields on
 * save, and a plugin can rewrite content on the way in; either one can quietly
 * break code or block markup, so a save that did not survive intact is
 * reported rather than presented as a clean success.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Support;

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
 * Class Html_Policy
 */
final class Html_Policy {

	/**
	 * Restriction code for a user without the unfiltered_html capability.
	 *
	 * @var string
	 */
	const NO_UNFILTERED_HTML = 'unfiltered_html';

	/**
	 * Why the current user's markup must be filtered, or '' when it is stored as given.
	 *
	 * The capability is checked through current_user_can(), so WordPress's own
	 * rules apply: on multisite only super admins hold it, and nobody does when
	 * DISALLOW_UNFILTERED_HTML is set.
	 *
	 * @return string
	 */
	public static function restriction() {
		return current_user_can( 'unfiltered_html' ) ? '' : self::NO_UNFILTERED_HTML;
	}

	/**
	 * Applies the rule to markup that is about to be saved.
	 *
	 * @param string                            $raw         Markup as supplied.
	 * @param string                            $restriction '' to store the markup as given; otherwise a
	 *                                                       restriction code, returned in the error data.
	 * @param string                            $where       What is being written, for messages, such as
	 *                                                       "the content of this page".
	 * @param array<int, array<string, string>> $warnings    Warnings accumulator, modified in place.
	 * @param string                            $why         Who may store this markup. Defaults to WordPress's
	 *                                                       unfiltered_html rule.
	 * @return string|WP_Error The value to save, or a refusal when whole elements would be lost.
	 */
	public static function apply( $raw, $restriction, $where, array &$warnings, $why = '' ) {
		$raw = (string) $raw;

		if ( '' === (string) $restriction ) {
			return $raw;
		}

		if ( '' === $why ) {
			$why = self::unfiltered_html_reason();
		}

		$filtered = wp_kses_post( $raw );
		$removed  = self::removed( $raw, $filtered );

		if ( $removed['elements'] ) {
			return new WP_Error(
				'html_not_allowed',
				sprintf(
					/* translators: 1: list of HTML tags such as <script>, <style>, 2: what is being written, such as "the content of this page", 3: who is allowed to store the markup */
					__( 'Nothing was saved: this markup (%1$s) cannot be stored in %2$s, and removing it silently could break the page. %3$s Remove it and try again.', 'mosmcp-abilities' ),
					self::tag_list( $removed['elements'] ),
					$where,
					$why
				),
				array(
					'reason'          => (string) $restriction,
					'disallowed_tags' => $removed['elements'],
				)
			);
		}

		if ( $removed['attributes'] ) {
			$warnings[] = array(
				'code'    => 'html_attributes_removed',
				'message' => sprintf(
					/* translators: 1: what was written, such as "the content of this page", 2: comma-separated attribute names, 3: who is allowed to store the markup */
					__( 'The change was saved, but these attributes were removed from %1$s: %2$s. %3$s', 'mosmcp-abilities' ),
					$where,
					implode( ', ', $removed['attributes'] ),
					$why
				),
				'context' => 'reason=' . (string) $restriction,
			);
		}

		return $filtered;
	}

	/**
	 * Warnings for a value that did not survive saving intact.
	 *
	 * Only losses are reported. WordPress and plugins legitimately add to content
	 * on save (a rel attribute on a link, a closing tag), and that is not worth a
	 * warning; markup or backslashes going missing is, because it breaks code,
	 * file paths and the escaped settings inside block markup.
	 *
	 * @param string $sent   The value that was handed to WordPress.
	 * @param string $stored The value WordPress stored.
	 * @param string $where  What was written, for messages, such as "the content of this page".
	 * @return array<int, array<string, string>>
	 */
	public static function save_warnings( $sent, $stored, $where ) {
		$sent     = (string) $sent;
		$stored   = (string) $stored;
		$warnings = array();

		if ( $sent === $stored ) {
			return $warnings;
		}

		$lost = array_values( array_diff( self::element_names( $sent ), self::element_names( $stored ) ) );
		if ( $lost ) {
			$warnings[] = array(
				'code'    => 'markup_removed_on_save',
				'message' => sprintf(
					/* translators: 1: what was written, such as "the content of this page", 2: list of HTML tags */
					__( 'The change was saved, but WordPress or another plugin removed this markup from %1$s while saving: %2$s. Check the page before relying on it.', 'mosmcp-abilities' ),
					$where,
					self::tag_list( $lost )
				),
				'context' => 'removed=' . implode( ',', $lost ),
			);
		}

		$sent_slashes   = substr_count( $sent, '\\' );
		$stored_slashes = substr_count( $stored, '\\' );
		if ( $stored_slashes < $sent_slashes ) {
			$warnings[] = array(
				'code'    => 'backslashes_removed_on_save',
				'message' => sprintf(
					/* translators: %s: what was written, such as "the content of this page" */
					__( 'The change was saved, but some backslashes were removed from %s while saving, so code, file paths or block settings in it may not work as sent.', 'mosmcp-abilities' ),
					$where
				),
				'context' => 'sent=' . $sent_slashes . ' stored=' . $stored_slashes,
			);
		}

		return $warnings;
	}

	/**
	 * Describes a post field for messages, such as "the content of this page".
	 *
	 * @param string $field     'content', 'title' or 'excerpt'.
	 * @param string $post_type Post type slug.
	 * @return string
	 */
	public static function field_label( $field, $post_type ) {
		$object = get_post_type_object( (string) $post_type );
		$name   = ( $object && isset( $object->labels->singular_name ) && '' !== (string) $object->labels->singular_name )
			? strtolower( (string) $object->labels->singular_name )
			: __( 'post', 'mosmcp-abilities' );

		if ( 'title' === $field ) {
			/* translators: %s: post type name, such as "page" */
			return sprintf( __( 'the title of this %s', 'mosmcp-abilities' ), $name );
		}

		if ( 'excerpt' === $field ) {
			/* translators: %s: post type name, such as "page" */
			return sprintf( __( 'the excerpt of this %s', 'mosmcp-abilities' ), $name );
		}

		/* translators: %s: post type name, such as "page" */
		return sprintf( __( 'the content of this %s', 'mosmcp-abilities' ), $name );
	}

	/**
	 * One sentence describing the rule, for ability input descriptions.
	 *
	 * @return string
	 */
	public static function rule_summary() {
		return __( 'HTML is saved the way the WordPress editor saves it: a user with the unfiltered_html capability can save any markup, including forms, scripts and styles, and markup a user is not allowed to save is refused with the reason rather than silently removed. Backslashes are kept.', 'mosmcp-abilities' );
	}

	/**
	 * Explains WordPress's unfiltered_html rule, saying who is allowed.
	 *
	 * @return string
	 */
	public static function unfiltered_html_reason() {
		return __( 'WordPress only allows this markup for users with the unfiltered_html capability: administrators and editors on a single site, super admins on multisite, and nobody when DISALLOW_UNFILTERED_HTML is set.', 'mosmcp-abilities' );
	}

	/**
	 * What filtering removed: whole elements, and attributes of elements it kept.
	 *
	 * Compared by name rather than as strings, because kses also rewrites text it
	 * keeps (a bare & becomes &amp;), which is not a removal worth reporting.
	 *
	 * @param string $raw      Markup as supplied.
	 * @param string $filtered The same markup after filtering.
	 * @return array<string, string[]> Keys 'elements' and 'attributes'.
	 */
	public static function removed( $raw, $filtered ) {
		$kept = self::element_names( $filtered );

		return array(
			'elements'   => array_values( array_diff( self::element_names( $raw ), $kept ) ),
			'attributes' => array_values( array_diff( self::attribute_names( $raw, $kept ), self::attribute_names( $filtered, $kept ) ) ),
		);
	}

	/**
	 * Lower-cased names of the elements opened in a piece of markup.
	 *
	 * Comments and block delimiters (<!-- ... -->) are not elements and are not
	 * matched.
	 *
	 * @param string $html Markup.
	 * @return string[]
	 */
	public static function element_names( $html ) {
		if ( ! preg_match_all( '/<([a-zA-Z][a-zA-Z0-9:-]*)/', (string) $html, $matches ) ) {
			return array();
		}

		return array_values( array_unique( array_map( 'strtolower', $matches[1] ) ) );
	}

	/**
	 * Lower-cased attribute names used on the given elements in a piece of markup.
	 *
	 * Attribute values are dropped first, so words inside a value are never taken
	 * for attribute names.
	 *
	 * @param string   $html     Markup.
	 * @param string[] $elements Element names to look at.
	 * @return string[]
	 */
	public static function attribute_names( $html, array $elements ) {
		$names = array();

		if ( ! preg_match_all( '/<([a-zA-Z][a-zA-Z0-9:-]*)(\s[^>]*)?>/', (string) $html, $tags, PREG_SET_ORDER ) ) {
			return $names;
		}

		foreach ( $tags as $tag ) {
			if ( empty( $tag[2] ) || ! in_array( strtolower( $tag[1] ), $elements, true ) ) {
				continue;
			}

			$bare = (string) preg_replace( '/=\s*(?:"[^"]*"|\'[^\']*\'|[^\s"\'>]+)/', '', $tag[2] );
			if ( preg_match_all( '/[a-zA-Z_:][-a-zA-Z0-9_:.]*/', $bare, $found ) ) {
				foreach ( $found[0] as $name ) {
					$names[ strtolower( $name ) ] = true;
				}
			}
		}

		return array_keys( $names );
	}

	/**
	 * Formats element names as tags for a message, such as "<script>, <style>".
	 *
	 * @param string[] $names Element names.
	 * @return string
	 */
	public static function tag_list( array $names ) {
		$tags = array();
		foreach ( $names as $name ) {
			$tags[] = '<' . $name . '>';
		}

		return implode( ', ', $tags );
	}
}
