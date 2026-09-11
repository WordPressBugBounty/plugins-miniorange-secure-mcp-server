<?php
/**
 * Metadata comparison between two posts.
 *
 * This exists so a site can answer its own question. When a newly created post
 * does not look like an existing one, the cause is almost always metadata the new
 * post lacks — an Elementor layout, a theme's sidebar or width setting, an
 * assigned page template. Diffing the two posts names the missing keys directly,
 * so nobody has to inspect a customer's database to find out which theme keys
 * matter on their site.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Core\Support;

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
 * Class Post_Meta_Diff
 */
class Post_Meta_Diff {

	/**
	 * Longest value rendered inline before it is summarised by size.
	 *
	 * @var int
	 */
	const MAX_VALUE_CHARS = 300;

	/**
	 * Compares the metadata of two posts.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function compare( array $input ) {
		$a = self::require_post( $input, 'post_a' );
		if ( $a instanceof WP_Error ) {
			return $a;
		}
		$b = self::require_post( $input, 'post_b' );
		if ( $b instanceof WP_Error ) {
			return $b;
		}

		if ( (int) $a->ID === (int) $b->ID ) {
			return new WP_Error(
				'same_post',
				__( 'post_a and post_b are the same post, so there is nothing to compare.', 'mosmcp-abilities' )
			);
		}

		$include_values = ! isset( $input['include_values'] ) || (bool) $input['include_values'];

		$meta_a = self::flatten( (int) $a->ID );
		$meta_b = self::flatten( (int) $b->ID );

		$only_a    = array();
		$only_b    = array();
		$different = array();
		$identical = 0;

		foreach ( $meta_a as $key => $value ) {
			if ( ! array_key_exists( $key, $meta_b ) ) {
				$only_a[] = self::entry( $key, $value, null, $include_values, 'a' );
				continue;
			}
			if ( $value === $meta_b[ $key ] ) {
				++$identical;
				continue;
			}
			$different[] = self::entry( $key, $value, $meta_b[ $key ], $include_values, 'both' );
		}

		foreach ( $meta_b as $key => $value ) {
			if ( ! array_key_exists( $key, $meta_a ) ) {
				$only_b[] = self::entry( $key, null, $value, $include_values, 'b' );
			}
		}

		$layout_keys = self::layout_relevant(
			array_merge(
				wp_list_pluck( $only_a, 'key' ),
				wp_list_pluck( $only_b, 'key' ),
				wp_list_pluck( $different, 'key' )
			)
		);

		return array(
			'post_a'                    => self::describe( $a ),
			'post_b'                    => self::describe( $b ),
			'identical_keys'            => $identical,
			'only_in_a'                 => $only_a,
			'only_in_b'                 => $only_b,
			'different'                 => $different,
			'likely_layout_difference'  => $layout_keys,
			'summary'                   => self::summary_line( $a, $b, $only_a, $only_b, $different, $layout_keys ),
		);
	}

	/**
	 * Reduces a post's meta to key => canonical string, so values compare cleanly.
	 *
	 * Multi-value keys are joined in a stable order: two posts carrying the same
	 * set of rows should compare equal even if the rows were inserted in a
	 * different order.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, string>
	 */
	private static function flatten( $post_id ) {
		$out = array();

		foreach ( get_post_meta( $post_id ) as $key => $rows ) {
			$parts = array();
			foreach ( (array) $rows as $raw ) {
				$value   = maybe_unserialize( $raw );
				$parts[] = is_scalar( $value ) || null === $value
					? (string) $value
					: (string) wp_json_encode( $value );
			}
			sort( $parts );
			$out[ (string) $key ] = implode( "\x1f", $parts );
		}

		return $out;
	}

	/**
	 * Builds one diff entry.
	 *
	 * @param string      $key            Meta key.
	 * @param string|null $value_a        Value on post A, or null when absent.
	 * @param string|null $value_b        Value on post B, or null when absent.
	 * @param bool        $include_values Whether to render values.
	 * @param string      $present_on     'a', 'b' or 'both'.
	 * @return array<string, mixed>
	 */
	private static function entry( $key, $value_a, $value_b, $include_values, $present_on ) {
		$entry = array(
			'key'        => (string) $key,
			'present_on' => $present_on,
			'bytes_a'    => null === $value_a ? 0 : strlen( $value_a ),
			'bytes_b'    => null === $value_b ? 0 : strlen( $value_b ),
		);

		if ( $include_values ) {
			$entry['value_a'] = self::render( $value_a );
			$entry['value_b'] = self::render( $value_b );
		}

		return $entry;
	}

	/**
	 * Renders a value for display, replacing large blobs with their size.
	 *
	 * A serialized page-builder tree is worth knowing about but not worth
	 * printing, and printing it would crowd out the keys the caller needs to see.
	 *
	 * @param string|null $value Canonical value.
	 * @return string
	 */
	private static function render( $value ) {
		if ( null === $value ) {
			return '';
		}
		$value = str_replace( "\x1f", ' | ', $value );
		if ( strlen( $value ) > self::MAX_VALUE_CHARS ) {
			return sprintf(
				/* translators: 1: size in bytes, 2: first characters of the value */
				__( '[%1$s bytes] %2$s...', 'mosmcp-abilities' ),
				number_format_i18n( strlen( $value ) ),
				substr( $value, 0, 120 )
			);
		}
		return $value;
	}

	/**
	 * Narrows a key list to those that plausibly affect layout.
	 *
	 * @param string[] $keys Meta keys.
	 * @return array<int, array<string, string>>
	 */
	private static function layout_relevant( array $keys ) {
		$out = array();

		foreach ( array_unique( $keys ) as $key ) {
			$reason = self::layout_reason( (string) $key );
			if ( '' === $reason ) {
				continue;
			}
			$out[] = array(
				'key'    => (string) $key,
				'reason' => $reason,
			);
		}

		return $out;
	}

	/**
	 * Why a key is called out as layout-relevant, or '' when it is not.
	 *
	 * @param string $key Meta key.
	 * @return string
	 */
	private static function layout_reason( $key ) {
		/*
		 * Anything the duplicator deliberately refuses to copy is a cache, a
		 * counter or a piece of per-post history — never a reason two posts look
		 * different. Excluding that set here keeps the "likely cause" list short
		 * enough to act on: without it, a post with no layout reports a dozen
		 * Elementor cache keys alongside the handful that actually explain the
		 * mismatch, and the signal is lost in the noise.
		 */
		if ( in_array( $key, Post_Duplicator::EXCLUDED_META, true ) ) {
			return '';
		}
		foreach ( Post_Duplicator::EXCLUDED_META_PREFIXES as $prefix ) {
			if ( 0 === strpos( $key, $prefix ) ) {
				return '';
			}
		}
		// Elementor's own internal bookkeeping, which regenerates on save.
		if ( 0 === strpos( $key, '_elementor_migrations_state' ) || '_elementor_version' === $key ) {
			return '';
		}

		if ( '_elementor_data' === $key ) {
			return __( 'The Elementor layout itself. If one post has it and the other does not, that alone explains a completely different appearance.', 'mosmcp-abilities' );
		}
		if ( '_elementor_edit_mode' === $key ) {
			return __( 'Controls whether Elementor renders the post at all. Without it the layout data is ignored.', 'mosmcp-abilities' );
		}
		if ( '_elementor_template_type' === $key ) {
			return __( 'Determines which Elementor document type loads the layout.', 'mosmcp-abilities' );
		}
		if ( '_wp_page_template' === $key ) {
			return __( 'The assigned page template, which decides the surrounding header, footer and content width.', 'mosmcp-abilities' );
		}
		if ( '_thumbnail_id' === $key ) {
			return __( 'The featured image, which many themes render as the post banner.', 'mosmcp-abilities' );
		}
		if ( '_elementor_page_settings' === $key ) {
			return __( 'Page-level Elementor settings such as the content width and custom CSS.', 'mosmcp-abilities' );
		}

		$needle = strtolower( $key );
		foreach ( Post_Display::LAYOUT_KEY_HINTS as $hint ) {
			if ( false !== strpos( $needle, $hint ) ) {
				return __( 'Named like a theme or plugin display setting (sidebar, width, template or title visibility).', 'mosmcp-abilities' );
			}
		}

		return '';
	}

	/**
	 * One-line, human-readable conclusion.
	 *
	 * @param WP_Post $a           Post A.
	 * @param WP_Post $b           Post B.
	 * @param array   $only_a      Keys only on A.
	 * @param array   $only_b      Keys only on B.
	 * @param array   $different   Keys differing.
	 * @param array   $layout_keys Layout-relevant subset.
	 * @return string
	 */
	private static function summary_line( WP_Post $a, WP_Post $b, array $only_a, array $only_b, array $different, array $layout_keys ) {
		if ( ! $only_a && ! $only_b && ! $different ) {
			return __( 'Both posts carry identical metadata, so a visual difference is not caused by per-post settings.', 'mosmcp-abilities' );
		}

		if ( ! $layout_keys ) {
			return sprintf(
				/* translators: 1: count only on A, 2: count only on B, 3: count differing */
				__( 'The posts differ in metadata (%1$d only on the first, %2$d only on the second, %3$d with different values), but none of the differing keys look layout-related.', 'mosmcp-abilities' ),
				count( $only_a ),
				count( $only_b ),
				count( $different )
			);
		}

		return sprintf(
			/* translators: 1: number of layout-relevant keys, 2: comma-separated key names */
			__( '%1$d of the differing keys look layout-related and are the likely cause of the appearance mismatch: %2$s. Duplicating the correctly styled post rather than creating a new one carries all of them across.', 'mosmcp-abilities' ),
			count( $layout_keys ),
			implode( ', ', array_slice( wp_list_pluck( $layout_keys, 'key' ), 0, 8 ) )
		);
	}

	/**
	 * Describes a post for the response.
	 *
	 * @param WP_Post $post Post.
	 * @return array<string, mixed>
	 */
	private static function describe( WP_Post $post ) {
		return array(
			'id'        => (int) $post->ID,
			'post_type' => (string) $post->post_type,
			'status'    => (string) $post->post_status,
			'title'     => (string) get_the_title( $post ),
		);
	}

	/**
	 * Resolves and authorizes one of the two posts.
	 *
	 * Both posts are read, so edit rights are required on each; the ability's own
	 * capability gate can only cover one of them.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @param string               $field Input field name.
	 * @return WP_Post|WP_Error
	 */
	private static function require_post( array $input, $field ) {
		$id   = isset( $input[ $field ] ) ? absint( $input[ $field ] ) : 0;
		$post = $id > 0 ? get_post( $id ) : null;

		if ( ! $post ) {
			return new WP_Error(
				'post_not_found',
				sprintf(
					/* translators: %s: input field name */
					__( 'No post found for %s.', 'mosmcp-abilities' ),
					$field
				)
			);
		}

		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return new WP_Error(
				'forbidden',
				sprintf(
					/* translators: %d: post ID */
					__( 'You do not have permission to read the metadata of post #%d.', 'mosmcp-abilities' ),
					$post->ID
				)
			);
		}

		return $post;
	}
}
