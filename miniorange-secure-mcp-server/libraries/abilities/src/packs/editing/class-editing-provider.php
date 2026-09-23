<?php
/**
 * Execute callbacks for the content editing pack.
 *
 * Every write here follows the same shape: read the stored value, compute the
 * replacement, refuse if the result would be structurally damaged, write, then read
 * back and report what actually landed rather than what was sent.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Editing;

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
 * Class Editing_Provider
 *
 * Static execute callbacks for the content editing abilities.
 */
class Editing_Provider {

	/**
	 * Sentinel telling a missing option apart from one holding a falsy value.
	 */
	const OPTION_MISSING = "\x00mosmcp_option_missing\x00";

	/**
	 * Post meta keys whose structure this pack will not edit as text.
	 *
	 * Breakdance stores JSON inside a JSON string, so the outer value stays valid
	 * while the inner structure breaks, which is precisely the damage the integrity
	 * guard cannot see. Refusing beats writing something that only looks intact.
	 *
	 * @var string[]
	 */
	const NESTED_JSON_KEYS = array( '_breakdance_data', 'breakdance_data' );

	/**
	 * Finds text across posts.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function find_in_posts( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$text  = isset( $input['text'] ) ? (string) $input['text'] : '';

		if ( '' === trim( $text ) ) {
			return Site_Support::error(
				'mosmcp_search_text_required',
				__( 'No text to search for was given.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_INVALID_INPUT,
				true
			);
		}

		$per_page = isset( $input['per_page'] ) ? (int) $input['per_page'] : 10;
		$per_page = max( 1, min( 50, $per_page ) );

		$post_type = isset( $input['post_type'] ) ? (string) $input['post_type'] : 'any';
		$status    = isset( $input['post_status'] ) ? (string) $input['post_status'] : 'any';

		$query = new \WP_Query(
			array(
				'post_type'           => ( 'any' === $post_type ) ? array( 'post', 'page' ) : $post_type,
				'post_status'         => ( 'any' === $status ) ? array( 'publish', 'draft', 'pending', 'private' ) : $status,
				'posts_per_page'      => $per_page,
				's'                   => $text,
				'ignore_sticky_posts' => true,
				'no_found_rows'       => true,
				'suppress_filters'    => false,
			)
		);

		$items = array();

		foreach ( $query->posts as $post ) {
			if ( ! current_user_can( 'edit_post', $post->ID ) ) {
				continue;
			}

			$hits = array();

			foreach ( Editing_Support::EDITABLE_FIELDS as $field ) {
				$stored = (string) get_post_field( $field, $post->ID, 'raw' );

				if ( '' === $stored ) {
					continue;
				}

				$found = Content_Matcher::find_matches( $stored, $text, 3 );

				if ( 0 === $found['total'] ) {
					continue;
				}

				$first = $found['matches'][0];

				$hits[] = array(
					'field'       => $field,
					'occurrences' => (int) $found['total'],
					'match_mode'  => (string) $found['mode'],
					'stored_text' => (string) $first['matched_text'],
					'context'     => (string) $first['context'],
				);
			}

			if ( empty( $hits ) ) {
				continue;
			}

			$items[] = array(
				'id'         => (int) $post->ID,
				'title'      => (string) get_the_title( $post ),
				'post_type'  => (string) $post->post_type,
				'status'     => (string) $post->post_status,
				'edit_url'   => (string) get_edit_post_link( $post->ID, 'raw' ),
				'field_hits' => $hits,
			);
		}

		$notes = array();

		if ( empty( $items ) ) {
			$notes[] = __( 'Nothing matched in the post body, title or excerpt. If the text appears on the page but not here, it is probably held in a custom field by a page builder; try mosmcp/content-find-in-meta on that page.', 'mosmcp-abilities' );
		}

		return array(
			'searched_for' => $text,
			'items_found'  => count( $items ),
			'items'        => $items,
			'notes'        => $notes,
		);
	}

	/**
	 * Replaces text in one post field.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function replace_in_post( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$id    = isset( $input['id'] ) ? absint( $input['id'] ) : 0;
		$field = isset( $input['field'] ) ? (string) $input['field'] : 'post_content';

		$post = $id > 0 ? get_post( $id ) : null;

		if ( ! $post ) {
			return Site_Support::error(
				'mosmcp_post_not_found',
				sprintf(
					/* translators: %d: the post ID supplied. */
					__( 'No post or page with ID %d exists.', 'mosmcp-abilities' ),
					$id
				),
				Site_Support::CAUSE_NOT_FOUND,
				false
			);
		}

		if ( ! in_array( $field, Editing_Support::EDITABLE_FIELDS, true ) ) {
			return Site_Support::error(
				'mosmcp_field_not_editable',
				sprintf(
					/* translators: 1: the field name supplied, 2: the list of editable fields. */
					__( '"%1$s" is not a field this can edit. The editable fields are %2$s.', 'mosmcp-abilities' ),
					$field,
					implode( ', ', Editing_Support::EDITABLE_FIELDS )
				),
				Site_Support::CAUSE_INVALID_INPUT,
				true
			);
		}

		$stored  = (string) get_post_field( $field, $id, 'raw' );
		$outcome = Content_Matcher::compute_replacement(
			$stored,
			isset( $input['find_text'] ) ? (string) $input['find_text'] : '',
			isset( $input['replace_with'] ) ? (string) $input['replace_with'] : '',
			Site_Support::bool_input( $input, 'replace_all', false ),
			Site_Support::bool_input( $input, 'whole_word', false )
		);

		if ( empty( $outcome['ok'] ) ) {
			return Editing_Support::match_error(
				$outcome,
				$stored,
				isset( $input['find_text'] ) ? (string) $input['find_text'] : '',
				sprintf(
					/* translators: 1: field name, 2: post ID. */
					__( 'the %1$s of post %2$d', 'mosmcp-abilities' ),
					$field,
					$id
				)
			);
		}

		$integrity = Editing_Support::integrity_error( $stored, $outcome['result'], 'post_content' === $field );

		if ( $integrity instanceof WP_Error ) {
			return $integrity;
		}

		$updated = wp_update_post(
			array(
				'ID'   => $id,
				$field => wp_slash( $outcome['result'] ),
			),
			true
		);

		if ( is_wp_error( $updated ) ) {
			return Site_Support::error(
				'mosmcp_post_update_failed',
				sprintf(
					/* translators: %s: the reason WordPress gave. */
					__( 'WordPress refused the change: %s. Nothing was saved.', 'mosmcp-abilities' ),
					$updated->get_error_message()
				),
				Site_Support::CAUSE_WP_CORE,
				false
			);
		}

		// Read back: other plugins filter content on save, so what was sent is not
		// necessarily what is now stored.
		$after = (string) get_post_field( $field, $id, 'raw' );

		return self::change_result(
			sprintf(
				/* translators: 1: post ID, 2: field name. */
				__( 'post %1$d %2$s', 'mosmcp-abilities' ),
				$id,
				$field
			),
			$stored,
			$outcome,
			$after
		);
	}

	/**
	 * Finds text in the custom fields of one post.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function find_in_meta( $input = array() ) {
		$input   = is_array( $input ) ? $input : array();
		$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
		$text    = isset( $input['text'] ) ? (string) $input['text'] : '';
		$only    = isset( $input['meta_key'] ) ? (string) $input['meta_key'] : '';

		$post = $post_id > 0 ? get_post( $post_id ) : null;

		if ( ! $post ) {
			return Site_Support::error(
				'mosmcp_post_not_found',
				sprintf(
					/* translators: %d: the post ID supplied. */
					__( 'No post with ID %d exists.', 'mosmcp-abilities' ),
					$post_id
				),
				Site_Support::CAUSE_NOT_FOUND,
				false
			);
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return Site_Support::error(
				'mosmcp_cannot_read_post',
				__( 'You do not have permission to read the custom fields of that post.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_CAPABILITY,
				false
			);
		}

		$all    = get_post_meta( $post_id );
		$fields = array();
		$notes  = array();

		foreach ( (array) $all as $key => $values ) {
			$key = (string) $key;

			if ( '' !== $only && $key !== $only ) {
				continue;
			}

			$value = is_array( $values ) ? reset( $values ) : $values;

			if ( ! is_string( $value ) || '' === $value ) {
				continue;
			}

			$found = Content_Matcher::find_matches( $value, $text, 1 );

			if ( 0 === $found['total'] ) {
				continue;
			}

			$reason   = '';
			$editable = true;

			if ( Content_Matcher::is_serialized_value( $value ) ) {
				$editable = false;
				$reason   = __( 'This field holds a PHP-serialized value, which cannot be safely text-edited.', 'mosmcp-abilities' );
			} elseif ( in_array( $key, self::NESTED_JSON_KEYS, true ) ) {
				$editable = false;
				$reason   = __( 'This field holds JSON nested inside JSON, where a text edit can break the inner structure without breaking the outer one. Use the builder\'s own abilities.', 'mosmcp-abilities' );
			} elseif ( ! Editing_Support::can_write_meta( $post_id, $key ) ) {
				$editable = false;
				$reason   = __( 'This field is protected and your account cannot write it.', 'mosmcp-abilities' );
			}

			$first = $found['matches'][0];

			$fields[] = array(
				'meta_key'    => $key,
				'occurrences' => (int) $found['total'],
				'match_mode'  => (string) $found['mode'],
				'stored_text' => (string) $first['matched_text'],
				'context'     => (string) $first['context'],
				'editable'    => $editable,
				'reason'      => $reason,
			);
		}

		foreach ( $fields as $f ) {
			if ( '_elementor_data' === $f['meta_key'] ) {
				$notes[] = __( 'This text is inside an Elementor layout. The Elementor abilities edit it through the layout structure and cannot mistake a setting for visible text, so prefer those; editing this field as raw text is a fallback.', 'mosmcp-abilities' );
				break;
			}
		}

		return array(
			'post_id'      => $post_id,
			'searched_for' => $text,
			'fields_found' => count( $fields ),
			'fields'       => $fields,
			'notes'        => $notes,
		);
	}

	/**
	 * Replaces text in one custom field.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function replace_in_meta( $input = array() ) {
		$input   = is_array( $input ) ? $input : array();
		$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
		$key     = isset( $input['meta_key'] ) ? (string) $input['meta_key'] : '';

		$post = $post_id > 0 ? get_post( $post_id ) : null;

		if ( ! $post ) {
			return Site_Support::error(
				'mosmcp_post_not_found',
				sprintf(
					/* translators: %d: the post ID supplied. */
					__( 'No post with ID %d exists.', 'mosmcp-abilities' ),
					$post_id
				),
				Site_Support::CAUSE_NOT_FOUND,
				false
			);
		}

		if ( '' === $key ) {
			return Site_Support::error(
				'mosmcp_meta_key_required',
				__( 'No custom field name was given. Use mosmcp/content-find-in-meta to see which field holds the text.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_INVALID_INPUT,
				true,
				array( 'use_instead' => 'mosmcp/content-find-in-meta' )
			);
		}

		if ( in_array( $key, self::NESTED_JSON_KEYS, true ) ) {
			return Site_Support::error(
				'mosmcp_nested_json_refused',
				sprintf(
					/* translators: %s: the custom field name. */
					__( 'Refused: "%s" stores JSON inside a JSON string. A text edit there can break the inner structure while the outer one still looks valid, so the damage would pass every check and only show up as a broken page. Use the page builder\'s own abilities instead.', 'mosmcp-abilities' ),
					$key
				),
				Site_Support::CAUSE_CONFLICT,
				false
			);
		}

		if ( ! Editing_Support::can_write_meta( $post_id, $key ) ) {
			return Site_Support::error(
				'mosmcp_meta_protected',
				sprintf(
					/* translators: %s: the custom field name. */
					__( 'The field "%s" is protected, and your account is not permitted to write it.', 'mosmcp-abilities' ),
					$key
				),
				Site_Support::CAUSE_CAPABILITY,
				false
			);
		}

		$stored = get_post_meta( $post_id, $key, true );

		$serialized = Editing_Support::serialized_error(
			$stored,
			sprintf(
				/* translators: %s: the custom field name. */
				__( 'the field "%s"', 'mosmcp-abilities' ),
				$key
			)
		);

		if ( $serialized instanceof WP_Error ) {
			return $serialized;
		}

		if ( ! is_string( $stored ) ) {
			return Site_Support::error(
				'mosmcp_meta_not_text',
				sprintf(
					/* translators: %s: the custom field name. */
					__( 'The field "%s" does not hold plain text, so it cannot be edited by find and replace.', 'mosmcp-abilities' ),
					$key
				),
				Site_Support::CAUSE_CONFLICT,
				false
			);
		}

		$outcome = Content_Matcher::compute_replacement(
			$stored,
			isset( $input['find_text'] ) ? (string) $input['find_text'] : '',
			isset( $input['replace_with'] ) ? (string) $input['replace_with'] : '',
			Site_Support::bool_input( $input, 'replace_all', false )
		);

		if ( empty( $outcome['ok'] ) ) {
			return Editing_Support::match_error(
				$outcome,
				$stored,
				isset( $input['find_text'] ) ? (string) $input['find_text'] : '',
				sprintf(
					/* translators: 1: custom field name, 2: post ID. */
					__( 'the field "%1$s" on post %2$d', 'mosmcp-abilities' ),
					$key,
					$post_id
				)
			);
		}

		$integrity = Editing_Support::integrity_error( $stored, $outcome['result'], false );

		if ( $integrity instanceof WP_Error ) {
			return $integrity;
		}

		update_post_meta( $post_id, $key, wp_slash( $outcome['result'] ) );

		$after = get_post_meta( $post_id, $key, true );
		$after = is_string( $after ) ? $after : '';

		$result = self::change_result(
			sprintf(
				/* translators: 1: post ID, 2: custom field name. */
				__( 'post %1$d field "%2$s"', 'mosmcp-abilities' ),
				$post_id,
				$key
			),
			$stored,
			$outcome,
			$after
		);

		// A builder that caches generated CSS keeps serving the old text until its
		// cache is cleared, so the edit looks like it did not happen.
		$result['notes'] = array_merge( $result['notes'], self::refresh_builder_caches( $post_id, $key ) );

		return $result;
	}

	/**
	 * Clears generated assets a builder caches for a post after its data changed.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key that was written.
	 * @return string[] Notes describing what was refreshed.
	 */
	private static function refresh_builder_caches( $post_id, $key ) {
		$notes = array();

		if ( '_elementor_data' !== $key && '_elementor_page_settings' !== $key ) {
			return $notes;
		}

		delete_post_meta( $post_id, '_elementor_css' );
		delete_post_meta( $post_id, '_elementor_element_cache' );

		if ( class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
			try {
				\Elementor\Core\Files\CSS\Post::create( $post_id )->delete();
			} catch ( \Throwable $e ) {
				$notes[] = __( 'The Elementor layout was changed but its cached stylesheet could not be cleared, so the front end may show the old version until the page is saved in Elementor.', 'mosmcp-abilities' );
				return $notes;
			}
		}

		$notes[] = __( "Elementor's cached styles for this page were cleared, so the change shows on the front end straight away.", 'mosmcp-abilities' );

		return $notes;
	}

	/**
	 * Finds text in one named option.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function find_in_options( $input = array() ) {
		$input  = is_array( $input ) ? $input : array();
		$option = isset( $input['option'] ) ? (string) $input['option'] : '';
		$text   = isset( $input['text'] ) ? (string) $input['text'] : '';

		if ( '' === trim( $option ) ) {
			return Site_Support::error(
				'mosmcp_option_name_required',
				__( 'No setting name was given. Settings are not searched in bulk; name the one to look inside.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_INVALID_INPUT,
				true
			);
		}

		$stored = get_option( $option, self::OPTION_MISSING );

		if ( self::OPTION_MISSING === $stored ) {
			return array(
				'option'       => $option,
				'exists'       => false,
				'searched_for' => $text,
				'occurrences'  => 0,
				'match_mode'   => '',
				'stored_text'  => '',
				'context'      => '',
				'editable'     => false,
				'reason'       => __( 'No setting with that exact name exists on this site.', 'mosmcp-abilities' ),
			);
		}

		$blocked  = Editing_Support::blocked_option( $option );
		$editable = true;
		$reason   = '';

		if ( $blocked instanceof WP_Error ) {
			$editable = false;
			$reason   = $blocked->get_error_message();
		} elseif ( Content_Matcher::is_serialized_value( $stored ) ) {
			$editable = false;
			$reason   = __( 'This setting holds a PHP-serialized value, which cannot be safely text-edited.', 'mosmcp-abilities' );
		} elseif ( ! is_string( $stored ) ) {
			$editable = false;
			$reason   = __( 'This setting does not hold plain text.', 'mosmcp-abilities' );
		}

		$haystack = is_string( $stored ) ? $stored : '';
		$found    = Content_Matcher::find_matches( $haystack, $text, 1 );
		$first    = ( $found['total'] > 0 ) ? $found['matches'][0] : null;

		return array(
			'option'       => $option,
			'exists'       => true,
			'searched_for' => $text,
			'occurrences'  => (int) $found['total'],
			'match_mode'   => (string) $found['mode'],
			'stored_text'  => $first ? (string) $first['matched_text'] : '',
			'context'      => $first ? (string) $first['context'] : '',
			'editable'     => $editable,
			'reason'       => $reason,
		);
	}

	/**
	 * Replaces text in one named option.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function replace_in_option( $input = array() ) {
		$input  = is_array( $input ) ? $input : array();
		$option = isset( $input['option'] ) ? (string) $input['option'] : '';

		$blocked = Editing_Support::blocked_option( $option );

		if ( $blocked instanceof WP_Error ) {
			return $blocked;
		}

		$stored = get_option( $option, self::OPTION_MISSING );

		if ( self::OPTION_MISSING === $stored ) {
			return Site_Support::error(
				'mosmcp_option_not_found',
				sprintf(
					/* translators: %s: the setting name supplied. */
					__( 'No setting named "%s" exists on this site. Setting names are exact, so check the spelling.', 'mosmcp-abilities' ),
					$option
				),
				Site_Support::CAUSE_NOT_FOUND,
				false
			);
		}

		$serialized = Editing_Support::serialized_error(
			$stored,
			sprintf(
				/* translators: %s: the setting name. */
				__( 'the setting "%s"', 'mosmcp-abilities' ),
				$option
			)
		);

		if ( $serialized instanceof WP_Error ) {
			return $serialized;
		}

		if ( ! is_string( $stored ) ) {
			return Site_Support::error(
				'mosmcp_option_not_text',
				sprintf(
					/* translators: %s: the setting name. */
					__( 'The setting "%s" does not hold plain text, so it cannot be edited by find and replace.', 'mosmcp-abilities' ),
					$option
				),
				Site_Support::CAUSE_CONFLICT,
				false
			);
		}

		$outcome = Content_Matcher::compute_replacement(
			$stored,
			isset( $input['find_text'] ) ? (string) $input['find_text'] : '',
			isset( $input['replace_with'] ) ? (string) $input['replace_with'] : '',
			Site_Support::bool_input( $input, 'replace_all', false )
		);

		if ( empty( $outcome['ok'] ) ) {
			return Editing_Support::match_error(
				$outcome,
				$stored,
				isset( $input['find_text'] ) ? (string) $input['find_text'] : '',
				sprintf(
					/* translators: %s: the setting name. */
					__( 'the setting "%s"', 'mosmcp-abilities' ),
					$option
				)
			);
		}

		$integrity = Editing_Support::integrity_error( $stored, $outcome['result'], false );

		if ( $integrity instanceof WP_Error ) {
			return $integrity;
		}

		// No slashing here: update_option() does not unslash, so the value is stored
		// exactly as given.
		update_option( $option, $outcome['result'] );

		$after = get_option( $option, '' );
		$after = is_string( $after ) ? $after : '';

		return self::change_result(
			sprintf(
				/* translators: %s: the setting name. */
				__( 'setting "%s"', 'mosmcp-abilities' ),
				$option
			),
			$stored,
			$outcome,
			$after
		);
	}

	/**
	 * Shapes the result every replace ability returns.
	 *
	 * @param string               $target  Human description of what was edited.
	 * @param string               $before  Value before the write.
	 * @param array<string, mixed> $outcome Matcher outcome.
	 * @param string               $after   Value read back after the write.
	 * @return array<string, mixed>
	 */
	private static function change_result( $target, $before, array $outcome, $after ) {
		$notes    = array();
		$verbatim = ( $after === $outcome['result'] );

		if ( ! $verbatim ) {
			$notes[] = __( 'The stored value differs from what was sent, which means something on this site filtered it on the way in. The lengths reported are of what is actually stored now, so read the value back before making another edit against it.', 'mosmcp-abilities' );
		}

		if ( 'exact' !== $outcome['mode'] && '' !== $outcome['mode'] ) {
			$notes[] = __( 'The stored wording differed from the text supplied, and was matched using a tolerant rule. The change was applied to the stored wording, so it is worth checking the result reads as intended.', 'mosmcp-abilities' );
		}

		return array(
			'target'          => (string) $target,
			'changed'         => ( $after !== $before ),
			'occurrences'     => (int) $outcome['count'],
			'match_mode'      => (string) $outcome['mode'],
			'length_before'   => strlen( (string) $before ),
			'length_after'    => strlen( (string) $after ),
			'stored_verbatim' => $verbatim,
			'notes'           => $notes,
		);
	}
}
