<?php
/**
 * Shared helpers for the Rank Math ability pack: field map, sanitizers, post
 * resolution, template rendering, and the warning vocabulary.
 *
 * Rank Math never calls register_post_meta() for any rank_math_* key, so there is
 * no sanitize_callback and no schema behind these writes — sanitization is
 * entirely this pack's responsibility. The sanitizer per field mirrors Rank
 * Math's own metadata sanitizer (RankMath\Rest\Sanitize::sanitize()), which is
 * the path its block-editor sidebar writes through, rather than the different
 * sanitizers its site-level set-homepage-seo ability happens to use.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Rankmath;

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
 * directive covers IDE and Plugin Check runs that don't load that ruleset).
 */
// phpcs:disable WordPress.WP.I18n.TextDomainMismatch

/**
 * Class Rankmath_Support
 */
class Rankmath_Support {

	/**
	 * Input field name => Rank Math post meta key.
	 *
	 * The field names are the editorial names an AI client sees; the meta keys are
	 * Rank Math's storage. Only these three are in scope — canonical URL, robots,
	 * the Open Graph and Twitter overrides, and schema are deliberately excluded
	 * (see the pack's class docblock).
	 */
	const FIELDS = array(
		'title'         => 'rank_math_title',
		'description'   => 'rank_math_description',
		'focus_keyword' => 'rank_math_focus_keyword',
	);

	/**
	 * Post statuses this pack will write SEO metadata for.
	 *
	 * 'auto-draft' is refused: it is the placeholder WordPress creates when the
	 * editor is merely opened, it is garbage-collected after a week, and writing
	 * SEO metadata onto one would be silently discarded. 'trash' and 'inherit'
	 * (revisions, attachments' parents) are refused for the same futility.
	 *
	 * @var string[]
	 */
	const WRITABLE_STATUSES = array( 'publish', 'draft', 'private', 'pending', 'future' );

	/**
	 * Length past which a title is longer than search engines typically display.
	 */
	const TITLE_RECOMMENDED_MAX = 60;

	/**
	 * Length past which a meta description is longer than search engines typically display.
	 */
	const DESCRIPTION_RECOMMENDED_MAX = 160;

	/**
	 * The Rank Math capability that gates on-page SEO editing.
	 *
	 * Rank Math's Role Manager grants this separately from WordPress editing
	 * rights, so a user who can edit a post is not automatically allowed to edit
	 * its SEO metadata. Rank Math checks this capability in its own REST
	 * permission callbacks; this pack ANDs it after the edit_post gate rather
	 * than replacing it, so neither policy can be bypassed through the other.
	 */
	const ONPAGE_CAP = 'rank_math_onpage_general';

	/**
	 * Resolves a post ID to a post this pack may write SEO metadata for.
	 *
	 * The capability gate has already run by the time this is called (the
	 * registrar checks edit_post on this same ID, plus the Rank Math capability);
	 * this only rejects targets where a write would be meaningless.
	 *
	 * @param int $post_id Post ID.
	 * @return WP_Post|WP_Error
	 */
	public static function writable_post_or_error( $post_id ) {
		$post_id = absint( $post_id );
		$post    = $post_id > 0 ? get_post( $post_id ) : null;

		if ( ! $post instanceof WP_Post ) {
			return new WP_Error(
				'invalid_post',
				sprintf(
					/* translators: %d: post ID. */
					__( 'No post or page exists with the ID %d.', 'mosmcp-abilities' ),
					$post_id
				)
			);
		}

		if ( ! in_array( (string) $post->post_status, self::WRITABLE_STATUSES, true ) ) {
			return new WP_Error(
				'invalid_post',
				sprintf(
					/* translators: 1: post ID, 2: post status, 3: comma-separated list of writable statuses. */
					__( 'The post %1$d has the status "%2$s", so SEO metadata cannot be written to it. Writable statuses are: %3$s. A post left in "auto-draft" has never been saved, and anything written to it is discarded.', 'mosmcp-abilities' ),
					(int) $post->ID,
					(string) $post->post_status,
					implode( ', ', self::WRITABLE_STATUSES )
				)
			);
		}

		$type = get_post_type_object( (string) $post->post_type );
		if ( ! $type || empty( $type->public ) ) {
			return new WP_Error(
				'post_type_not_accessible',
				sprintf(
					/* translators: 1: post type slug, 2: post ID. */
					__( 'The type "%1$s" of post %2$d is not publicly viewable, so it has no search-engine presence for SEO metadata to affect.', 'mosmcp-abilities' ),
					(string) $post->post_type,
					(int) $post->ID
				)
			);
		}

		return $post;
	}

	/**
	 * Sanitizes one field's value, returning the clean (unslashed) result.
	 *
	 * The per-field choice matches RankMath\Rest\Sanitize::sanitize(): the title
	 * and description strip all HTML through wp_filter_nohtml_kses(), and the
	 * focus keyword falls to that method's default text-field treatment.
	 *
	 * wp_filter_nohtml_kses() returns an addslashes()'d string — it is written for
	 * form input that will be unslashed again downstream. That is unslashed back
	 * here so every value this method returns is clean, which is what callers need
	 * for comparing against get_post_meta() and for the response body. Adding the
	 * slashes back for storage happens in exactly one place, {@see store()}.
	 *
	 * @param string $field Input field name.
	 * @param mixed  $value Raw input value.
	 * @return string Clean sanitized value.
	 */
	public static function sanitize( $field, $value ) {
		$value = is_scalar( $value ) ? (string) $value : '';

		if ( 'focus_keyword' === $field ) {
			return sanitize_text_field( $value );
		}

		return (string) wp_unslash( wp_filter_nohtml_kses( $value ) );
	}

	/**
	 * Writes one clean value to its Rank Math meta key.
	 *
	 * The value is slashed because the metadata API unslashes on the way in: it
	 * is written for values arriving from a form submission, which are already
	 * slashed. A value that came over MCP is not, so writing it directly loses a
	 * level of backslashes — a title containing C:\path would be stored as
	 * C:path.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $field   Input field name.
	 * @param string $value   Clean sanitized value.
	 * @return void
	 */
	public static function store( $post_id, $field, $value ) {
		update_post_meta( (int) $post_id, self::FIELDS[ $field ], wp_slash( $value ) );
	}

	/**
	 * Removes a Rank Math meta key from a post.
	 *
	 * Deleting the row is not the same as storing an empty string. Rank Math
	 * decides whether to fall through to the site-wide template for a field by
	 * testing '' !== $value, so a leftover empty string does not mean "no value
	 * set" — it permanently suppresses the global fallback for that post. Rank
	 * Math's own editor path deletes for the same reason
	 * (RankMath\Rest\Shared::update_metadata()).
	 *
	 * @param int    $post_id Post ID.
	 * @param string $field   Input field name.
	 * @return void
	 */
	public static function clear( $post_id, $field ) {
		delete_post_meta( (int) $post_id, self::FIELDS[ $field ] );
	}

	/**
	 * Reads all three fields straight from post meta, as stored.
	 *
	 * Deliberately not routed through any Rank Math reader:
	 * rank-math/get-post-seo-meta renders through the frontend path
	 * (RankMath\Paper\Singular::get_seo_meta()), so it resolves template
	 * variables and substitutes site-wide defaults for fields that are not
	 * stored at all. This returns the empty strings that render as those
	 * defaults, which is what a caller needs in order to know what it is
	 * about to overwrite.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, string> Field name => stored value ('' when no row exists).
	 */
	public static function raw_meta( $post_id ) {
		$raw = array();

		foreach ( self::FIELDS as $field => $meta_key ) {
			$stored        = get_post_meta( (int) $post_id, $meta_key, true );
			$raw[ $field ] = is_scalar( $stored ) ? (string) $stored : '';
		}

		return $raw;
	}

	/**
	 * Whether Rank Math's replace-variables engine can resolve variables in this
	 * request, setting it up if it has not been already.
	 *
	 * Two separate things can stop it, and both are silent:
	 *
	 * 1. RANK_MATH_VERSION is defined as soon as the plugin file loads, but the
	 *    replace-variables manager is only built after Rank Math's product
	 *    registration check passes (rank-math.php, instantiate()). On a site not
	 *    connected to a Rank Math account, rank_math()->variables is never set,
	 *    and Replacer::set_up_replacements() dereferences it without guarding —
	 *    so calling Helper::replace_vars() there is a fatal error, not a failed
	 *    lookup.
	 * 2. The manager registers its variables on 'admin_enqueue_scripts' or 'wp'
	 *    (Replace_Variables\Manager::__construct()). An MCP request is neither an
	 *    admin page load nor a front-end query, so neither hook fires and the
	 *    replacement set stays empty. Helper::replace_vars() does not report
	 *    that: with nothing registered it strips every %variable% and returns the
	 *    remains, so "%title% %sep% %sitename%" renders as " " and a caller is
	 *    shown a preview that looks like a real, nearly-empty title.
	 *
	 * Calling setup() ourselves is what the manager's own hooks would have done;
	 * it guards against running twice internally, and the emptiness check below
	 * means a future change in how it initialises degrades to a warning rather
	 * than to a wrong preview.
	 *
	 * @return bool
	 */
	public static function can_render() {
		if ( ! class_exists( '\RankMath\Helper' ) || ! function_exists( 'rank_math' ) ) {
			return false;
		}

		$container = rank_math();
		if ( ! is_object( $container ) || ! isset( $container->variables ) ) {
			return false;
		}

		$manager = $container->variables;
		if ( ! is_object( $manager ) || ! method_exists( $manager, 'get_replacements' ) ) {
			return false;
		}

		if ( empty( $manager->get_replacements() ) && method_exists( $manager, 'setup' ) ) {
			$manager->setup();
		}

		return ! empty( $manager->get_replacements() );
	}

	/**
	 * Resolves Rank Math's %variables% in a value, in the context of one post.
	 *
	 * @param string  $value Stored value, possibly containing %variables%.
	 * @param WP_Post $post  Context post.
	 * @return string Rendered value, or the input unchanged when rendering is unavailable.
	 */
	public static function render( $value, WP_Post $post ) {
		if ( '' === $value || ! self::can_render() ) {
			return $value;
		}

		return (string) \RankMath\Helper::replace_vars( $value, $post );
	}

	/**
	 * The site-wide title/description template configured for a post's type.
	 *
	 * Used to detect a caller writing back a value that Rank Math generated
	 * rather than one an author wrote. Returns '' for anything but the two
	 * templated fields, or when Rank Math's settings are unavailable.
	 *
	 * @param string  $field Input field name.
	 * @param WP_Post $post  Context post.
	 * @return string
	 */
	public static function global_template( $field, WP_Post $post ) {
		if ( 'focus_keyword' === $field || ! class_exists( '\RankMath\Helper' ) ) {
			return '';
		}

		$setting = 'titles.pt_' . (string) $post->post_type . '_' . $field;
		$value   = \RankMath\Helper::get_settings( $setting );

		return is_string( $value ) ? $value : '';
	}

	/**
	 * Whether a value looks like Rank Math's own rendered output rather than
	 * something an author wrote.
	 *
	 * This is the hazard the pack exists to flag. Rank Math's read ability returns
	 * the finished text a search engine sees — the site-wide template already
	 * filled in, or an excerpt generated from the post content. A caller that
	 * reads that, edits it, and writes it back turns a live template into a frozen
	 * string: the post's SEO title stops tracking its real title, forever, with no
	 * error anywhere. It cannot be detected with certainty (an author is entitled
	 * to type the same words), so it is reported as a warning rather than refused.
	 *
	 * A value containing %variables% is never flagged — that is a template being
	 * written deliberately.
	 *
	 * @param string  $field    Input field name.
	 * @param string  $value    Clean incoming value.
	 * @param string  $previous Previously stored value for the field.
	 * @param WP_Post $post     Context post.
	 * @return bool
	 */
	public static function looks_resolved( $field, $value, $previous, WP_Post $post ) {
		if ( '' === $value || 'focus_keyword' === $field || self::has_variables( $value ) ) {
			return false;
		}

		$candidates = array();

		// What the previously stored value renders to — the exact string Rank
		// Math's read ability would have returned when a value was stored.
		if ( '' !== $previous ) {
			$candidates[] = self::render( $previous, $post );
		}

		// What the site-wide template renders to — the string that ability returns
		// when nothing is stored, which is the common case for an untouched post.
		$template = self::global_template( $field, $post );
		if ( '' !== $template ) {
			$candidates[] = self::render( $template, $post );
		}

		// Rank Math falls back to the post's excerpt for a missing description.
		if ( 'description' === $field ) {
			$candidates[] = (string) get_the_excerpt( $post );
		}

		$needle = self::normalize( $value );

		foreach ( $candidates as $candidate ) {
			if ( '' !== $candidate && self::normalize( $candidate ) === $needle ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a value contains a Rank Math %variable%.
	 *
	 * @param string $value Value to test.
	 * @return bool
	 */
	public static function has_variables( $value ) {
		return 1 === preg_match( '/%[a-z0-9_-]+(\(.*?\))?%/i', (string) $value );
	}

	/**
	 * Collapses whitespace and case for comparing two rendered strings.
	 *
	 * @param string $value Value to normalize.
	 * @return string
	 */
	private static function normalize( $value ) {
		$value = wp_strip_all_tags( (string) $value );
		$value = preg_replace( '/\s+/u', ' ', $value );

		return strtolower( trim( (string) $value ) );
	}

	/**
	 * Builds one warning entry.
	 *
	 * @param string $code    Warning code.
	 * @param string $message Human-readable message.
	 * @param string $context Machine-readable context.
	 * @return array<string, string>
	 */
	public static function warning( $code, $message, $context = '' ) {
		return array(
			'code'    => (string) $code,
			'message' => (string) $message,
			'context' => (string) $context,
		);
	}
}
