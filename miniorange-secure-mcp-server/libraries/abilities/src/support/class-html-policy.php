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
	 * Markup an edit adds that a user without unfiltered_html may not save.
	 *
	 * For in-place edits of stored values (custom fields, settings), which WordPress
	 * never filters itself even though page builders print some of them unescaped.
	 * Only what the edit adds is judged: markup already stored by someone allowed to
	 * store it does not block an unrelated edit, while changing it does, because
	 * each disallowed element is compared whole, attributes and contents included.
	 *
	 * JSON values, such as a page builder layout, are judged on their decoded text,
	 * so an escaped form like <script> is seen for what it is. Tags are
	 * read with WordPress's HTML tokenizer, which follows the browser's parsing
	 * rules. An element counts when wp_kses_post(), the rule WordPress applies to
	 * these users' posts, does not allow it; an attribute counts when it can run
	 * script (see rejected_attributes()).
	 *
	 * @param string $before Value before the edit.
	 * @param string $after  Value after the edit.
	 * @return string[] What the edit adds, such as "<script>"; empty when it adds nothing disallowed.
	 */
	public static function added_markup( $before, $after ) {
		$allowed = wp_kses_allowed_html( 'post' );
		$present = array();

		foreach ( self::disallowed_markup( (string) $before, $allowed ) as $item ) {
			$present[ $item['key'] ] = isset( $present[ $item['key'] ] ) ? $present[ $item['key'] ] + 1 : 1;
		}

		$added = array();

		foreach ( self::disallowed_markup( (string) $after, $allowed ) as $item ) {
			if ( ! empty( $present[ $item['key'] ] ) ) {
				--$present[ $item['key'] ];
				continue;
			}

			$added[ $item['label'] ] = true;
		}

		return array_keys( $added );
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
	 * Every piece of disallowed markup in a stored value, as comparable items.
	 *
	 * @param string                                   $value   Stored value, plain or JSON.
	 * @param array<string, array<string, mixed>|true> $allowed Allowed elements and attributes, from wp_kses_allowed_html().
	 * @return array<int, array<string, string>> Items with a 'key' that identifies the markup exactly and a 'label' for messages.
	 */
	private static function disallowed_markup( $value, array $allowed ) {
		$items = array();

		foreach ( self::text_values( $value ) as $path => $text ) {
			if ( false === strpos( $text, '<' ) ) {
				continue;
			}

			// Keyed by position too: moving existing code into another setting can put
			// it where it runs, such as from a code-display widget into an HTML widget.
			foreach ( self::disallowed_in( $text, $allowed ) as $item ) {
				$item['key'] = $path . '|' . $item['key'];
				$items[]     = $item;
			}
		}

		return $items;
	}

	/**
	 * The text a value holds: every string inside it when it is JSON, else the value itself.
	 *
	 * @param string $value Stored value.
	 * @return array<string, string> Text keyed by its position in the value ('' for a plain value).
	 */
	private static function text_values( $value ) {
		$start = substr( ltrim( $value ), 0, 1 );

		if ( '{' === $start || '[' === $start ) {
			$decoded = json_decode( $value, true );

			if ( is_array( $decoded ) ) {
				$texts = array();
				self::collect_strings( $decoded, '', $texts );
				return $texts;
			}
		}

		return array( '' => $value );
	}

	/**
	 * Collects the strings inside decoded JSON, keyed by their path.
	 *
	 * @param array<int|string, mixed> $node  Decoded JSON array or object.
	 * @param string                   $path  Path of this node.
	 * @param array<string, string>    $texts Accumulator, modified in place.
	 * @return void
	 */
	private static function collect_strings( array $node, $path, array &$texts ) {
		foreach ( $node as $key => $child ) {
			$at = $path . '/' . $key;

			if ( is_string( $child ) ) {
				$texts[ $at ] = $child;
			} elseif ( is_array( $child ) ) {
				self::collect_strings( $child, $at, $texts );
			}
		}
	}

	/**
	 * Disallowed elements and attributes in one piece of markup.
	 *
	 * A tag left unfinished at the end is judged as if it were closed, because the
	 * markup a builder prints after this text would close it in the browser; one
	 * that still cannot be read is refused outright.
	 *
	 * @param string                                   $html      Markup.
	 * @param array<string, array<string, mixed>|true> $allowed   Allowed elements and attributes.
	 * @param bool                                     $completed Whether a closing ">" was already appended.
	 * @return array<int, array<string, string>>
	 */
	private static function disallowed_in( $html, array $allowed, $completed = false ) {
		$items     = array();
		$processor = new \WP_HTML_Tag_Processor( $html );

		while ( $processor->next_token() ) {
			if ( '#tag' !== $processor->get_token_type() || $processor->is_tag_closer() ) {
				continue;
			}

			$tag        = strtolower( (string) $processor->get_tag() );
			$attributes = array();
			foreach ( (array) $processor->get_attribute_names_with_prefix( '' ) as $name ) {
				$attributes[ $name ] = $processor->get_attribute( $name );
			}

			if ( ! isset( $allowed[ $tag ] ) ) {
				$items[] = array(
					'key'   => 'element|' . $tag . '|' . wp_json_encode( $attributes ) . '|' . $processor->get_modifiable_text(),
					'label' => '<' . $tag . '>',
				);
				continue;
			}

			foreach ( self::rejected_attributes( $attributes ) as $name ) {
				$items[] = array(
					'key'   => 'attribute|' . $tag . '|' . $name . '|' . ( true === $attributes[ $name ] ? '' : (string) $attributes[ $name ] ),
					'label' => sprintf(
						/* translators: 1: HTML attribute name such as onclick, 2: HTML tag such as <a> */
						__( 'the %1$s attribute on %2$s', 'mosmcp-abilities' ),
						$name,
						'<' . $tag . '>'
					),
				);
			}
		}

		if ( $processor->paused_at_incomplete_token() ) {
			if ( ! $completed ) {
				return self::disallowed_in( $html . '>', $allowed, true );
			}

			$items[] = array(
				'key'   => 'unfinished|' . md5( $html ),
				'label' => __( 'an unfinished tag', 'mosmcp-abilities' ),
			);
		}

		return $items;
	}

	/**
	 * Attributes of an allowed element that can run script.
	 *
	 * Event handlers, and links or sources using a protocol WordPress does not allow
	 * (javascript:, data:), judged with WordPress's own lists of URL attributes and
	 * allowed protocols. Attributes kses merely does not recognise, such as srcset or
	 * a newer CSS property, cannot run anything and are left alone.
	 *
	 * @param array<string, string|true> $attributes Attribute values, true for a bare attribute.
	 * @return string[] Attribute names.
	 */
	private static function rejected_attributes( array $attributes ) {
		$uri_attributes = wp_kses_uri_attributes();
		$protocols      = wp_allowed_protocols();
		$rejected       = array();

		foreach ( $attributes as $name => $value ) {
			if ( 0 === strpos( $name, 'on' ) ) {
				$rejected[] = $name;
			} elseif ( true !== $value && in_array( $name, $uri_attributes, true ) && self::has_disallowed_protocol( (string) $value, $protocols ) ) {
				$rejected[] = $name;
			}
		}

		return $rejected;
	}

	/**
	 * Whether a URL names a protocol WordPress does not allow.
	 *
	 * The scheme is found the way a browser finds it, which wp_kses_bad_protocol()
	 * does not quite do: it also reads a colon in a relative link's query string
	 * ("/page?a=1:2") as a protocol. A URL with no scheme is relative and safe.
	 *
	 * @param string   $url       Attribute value, entities already decoded.
	 * @param string[] $protocols Allowed protocols, from wp_allowed_protocols().
	 * @return bool
	 */
	private static function has_disallowed_protocol( $url, array $protocols ) {
		// Browsers ignore spaces and control characters around a URL, and every tab or newline inside it.
		$url = (string) preg_replace( '/[\t\n\r]/', '', trim( $url, "\x00..\x20" ) );

		if ( ! preg_match( '/^([a-zA-Z][a-zA-Z0-9+.\-]*):/', $url, $match ) ) {
			return false;
		}

		return ! in_array( strtolower( $match[1] ), $protocols, true );
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
