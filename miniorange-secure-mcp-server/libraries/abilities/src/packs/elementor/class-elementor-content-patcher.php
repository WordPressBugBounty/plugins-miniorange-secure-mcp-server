<?php
/**
 * Small edits inside one Elementor element's text, without resending all of it.
 *
 * A large HTML widget or text block is where whole-value rewrites go wrong: the
 * caller has to send every byte back, and a model asked to reproduce forty
 * kilobytes of markup to change one line will drop or alter some of it. Here the
 * caller sends only the passages to change. Each must match exactly once (unless
 * every occurrence is meant), all of them apply or none do, and the result is
 * handed to the content writer, so it is saved under exactly the same rules as a
 * whole-value change: the same HTML rule for the user, the same v4 value shapes,
 * the same read-back check.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Elementor;

use MoSMCP\Abilities\Packs\Editing\Content_Matcher;
use MoSMCP\Abilities\Packs\Editing\Editing_Support;
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
 * Class Elementor_Content_Patcher
 */
class Elementor_Content_Patcher {

	/**
	 * Most edits one request may carry.
	 *
	 * @var int
	 */
	const MAX_EDITS = 25;

	/**
	 * Content roles that hold text and can be patched.
	 *
	 * @var string[]
	 */
	const TEXT_ROLES = array( 'text', 'body' );

	/**
	 * Widgets whose text is code, where a phrase is never matched through tags.
	 *
	 * @var string[]
	 */
	const CODE_WIDGETS = array( 'html', 'shortcode' );

	/**
	 * Characters of surrounding text shown either side of a change.
	 *
	 * @var int
	 */
	const CONTEXT_CHARS = 60;

	/**
	 * Builds the mutator for a patch.
	 *
	 * @param array<string, mixed> $input  Ability input.
	 * @param array<string, mixed> $report Filled with what each edit did, for the result.
	 * @return callable
	 */
	public static function mutator( array $input, array &$report ) {
		return static function ( array &$tree, array &$result ) use ( $input, &$report ) {
			$element_id = isset( $input['element_id'] ) ? (string) $input['element_id'] : '';

			$path = Elementor_Write_Engine::resolve_one( $tree, $element_id );
			if ( is_wp_error( $path ) ) {
				return $path;
			}

			$node = Elementor_Write_Engine::node_at( $tree, $path );
			if ( ! is_array( $node ) ) {
				return new WP_Error( 'element_not_found', __( 'The element could not be read from the layout.', 'mosmcp-abilities' ) );
			}

			$role    = isset( $input['field'] ) && '' !== (string) $input['field'] ? (string) $input['field'] : 'text';
			$current = self::text_of( $node, $role );
			if ( is_wp_error( $current ) ) {
				return $current;
			}

			$edits = self::edits( $input );
			if ( is_wp_error( $edits ) ) {
				return $edits;
			}

			$text          = $current['value'];
			$across_markup = ! in_array( $current['slug'], self::CODE_WIDGETS, true );
			$count         = count( $edits );
			$done          = array();

			foreach ( $edits as $index => $edit ) {
				$outcome = Content_Matcher::compute_replacement( $text, $edit['find'], $edit['replace'], $edit['replace_all'], false, $across_markup );

				if ( empty( $outcome['ok'] ) ) {
					return self::edit_error( $outcome, $text, $edit['find'], $index + 1, $count, $element_id, $current['control'] );
				}

				$done[] = array(
					'edit'       => $index + 1,
					'match_mode' => (string) $outcome['mode'],
					'replaced'   => (int) $outcome['count'],
					'excerpt'    => self::excerpt( $text, (string) $outcome['result'] ),
				);

				$text = (string) $outcome['result'];
			}

			if ( '' === trim( $text ) ) {
				return new WP_Error(
					'patch_empties_element',
					__( 'These edits would leave the element with no text at all, so nothing was saved. To remove the element, delete it; to replace its whole content, set it in full.', 'mosmcp-abilities' )
				);
			}

			$report = array(
				'role'          => $role,
				'control'       => $current['control'],
				'edits'         => $done,
				'length_before' => strlen( $current['value'] ),
				'length_after'  => strlen( $text ),
			);

			// Saved through the content writer, so the patched text meets exactly the
			// rules a whole-value change does.
			$write = Elementor_Content_Writer::mutator(
				array(
					'element_id' => $element_id,
					$role        => $text,
				)
			);

			return $write( $tree, $result );
		};
	}

	/**
	 * The current text of one content role on a node.
	 *
	 * A control that was never edited is not stored at all, and Elementor shows its
	 * default instead, so that default is what a caller sees and patches.
	 *
	 * @param array<string, mixed> $node Node as stored.
	 * @param string               $role 'text' or 'body'.
	 * @return array<string, mixed>|WP_Error Keys 'value', 'control', 'slug', 'is_atomic'.
	 */
	public static function text_of( array $node, $role ) {
		$slug = isset( $node['widgetType'] ) && '' !== $node['widgetType']
			? (string) $node['widgetType']
			: ( isset( $node['elType'] ) ? (string) $node['elType'] : '' );

		$element = Elementor_Schema::element( $slug );
		if ( ! $element ) {
			return new WP_Error(
				'unknown_widget',
				sprintf(
					/* translators: %s: widget slug */
					__( 'This element is a "%s", which is not registered on this site — the widget or addon that created it may be deactivated. Its content cannot be read or changed until it is available again.', 'mosmcp-abilities' ),
					$slug
				)
			);
		}

		$roles = array_intersect_key( Elementor_Schema::content_controls( $slug, $element ), array_flip( self::TEXT_ROLES ) );

		if ( ! isset( $roles[ $role ] ) ) {
			return new WP_Error(
				'role_not_supported',
				$roles
					? sprintf(
						/* translators: 1: content field, 2: widget slug, 3: comma-separated fields it has */
						__( 'A "%1$s" field cannot be edited on a "%2$s" element. It has: %3$s.', 'mosmcp-abilities' ),
						$role,
						$slug,
						implode( ', ', array_keys( $roles ) )
					)
					: sprintf(
						/* translators: %s: widget slug */
						__( 'The "%s" element has no text to edit. Containers, images and purely decorative widgets fall into this group.', 'mosmcp-abilities' ),
						$slug
					)
			);
		}

		$control   = (string) $roles[ $role ];
		$is_atomic = Elementor_Schema::is_atomic( $element );
		$settings  = isset( $node['settings'] ) && is_array( $node['settings'] ) ? $node['settings'] : array();
		$value     = self::stored_text( $settings, $control, $is_atomic, $element );

		if ( null === $value ) {
			return new WP_Error(
				'value_not_text',
				sprintf(
					/* translators: %s: setting name */
					__( 'The "%s" setting of this element is not stored as plain text, so it cannot be edited in parts. Set it in full instead.', 'mosmcp-abilities' ),
					$control
				)
			);
		}

		return array(
			'value'     => $value,
			'control'   => $control,
			'slug'      => $slug,
			'is_atomic' => $is_atomic,
		);
	}

	/**
	 * Reads a text setting, unwrapping the value shapes v4 elements use.
	 *
	 * @param array<string, mixed> $settings  Node settings.
	 * @param string               $control   Control or prop name.
	 * @param bool                 $is_atomic Whether the element is atomic.
	 * @param object               $element   Widget or element object.
	 * @return string|null Null when the value is not text.
	 */
	private static function stored_text( array $settings, $control, $is_atomic, $element ) {
		if ( ! array_key_exists( $control, $settings ) ) {
			if ( $is_atomic || ! method_exists( $element, 'get_controls' ) ) {
				return '';
			}
			$controls = (array) $element->get_controls();
			return isset( $controls[ $control ]['default'] ) && is_string( $controls[ $control ]['default'] ) ? $controls[ $control ]['default'] : '';
		}

		$value = $settings[ $control ];

		if ( $is_atomic && is_array( $value ) && array_key_exists( 'value', $value ) ) {
			$value = $value['value'];

			// A nested shape such as html-v3 keeps its text one level further in.
			if ( is_array( $value ) && isset( $value['content'] ) && is_array( $value['content'] ) && array_key_exists( 'value', $value['content'] ) ) {
				$value = $value['content']['value'];
			}
		}

		if ( is_string( $value ) ) {
			return $value;
		}

		return is_int( $value ) || is_float( $value ) ? (string) $value : null;
	}

	/**
	 * Validates the requested edits.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	private static function edits( array $input ) {
		$raw = isset( $input['edits'] ) && is_array( $input['edits'] ) ? array_values( $input['edits'] ) : array();

		if ( ! $raw ) {
			return new WP_Error( 'no_edits', __( 'Provide at least one edit: the exact text to find and what to replace it with.', 'mosmcp-abilities' ) );
		}

		if ( count( $raw ) > self::MAX_EDITS ) {
			return new WP_Error(
				'too_many_edits',
				sprintf(
					/* translators: %d: maximum number of edits */
					__( 'At most %d edits can be made in one request. Split the change, or set the whole content if most of it changes.', 'mosmcp-abilities' ),
					self::MAX_EDITS
				)
			);
		}

		$edits = array();

		foreach ( $raw as $index => $edit ) {
			$find = is_array( $edit ) && isset( $edit['find'] ) ? (string) $edit['find'] : '';

			if ( '' === $find ) {
				return new WP_Error(
					'edit_missing_find',
					sprintf(
						/* translators: %d: edit number */
						__( 'Edit %d has no text to find. Pass the exact text to change, copied from the element.', 'mosmcp-abilities' ),
						$index + 1
					)
				);
			}

			$edits[] = array(
				'find'        => $find,
				'replace'     => isset( $edit['replace'] ) ? (string) $edit['replace'] : '',
				'replace_all' => ! empty( $edit['replace_all'] ),
			);
		}

		return $edits;
	}

	/**
	 * Turns a failed match into an error that names the edit and shows nearby text.
	 *
	 * @param array<string, mixed> $outcome    Matcher outcome.
	 * @param string               $text       Text the edit was applied to.
	 * @param string               $find       Text the edit looked for.
	 * @param int                  $number     1-based edit number.
	 * @param int                  $count      Number of edits in the request.
	 * @param string               $element_id Element ID.
	 * @param string               $control    Setting name.
	 * @return WP_Error
	 */
	private static function edit_error( array $outcome, $text, $find, $number, $count, $element_id, $control ) {
		$error = Editing_Support::match_error(
			$outcome,
			$text,
			$find,
			sprintf(
				/* translators: 1: setting name, 2: element ID */
				__( 'the "%1$s" setting of element %2$s', 'mosmcp-abilities' ),
				$control,
				$element_id
			)
		);

		$data = $error->get_error_data();
		$data = is_array( $data ) ? $data : array();

		$data['edit'] = $number;

		return new WP_Error(
			$error->get_error_code(),
			sprintf(
				/* translators: 1: edit number, 2: number of edits, 3: why it failed */
				__( 'Nothing was saved. Edit %1$d of %2$d failed: %3$s', 'mosmcp-abilities' ),
				$number,
				$count,
				$error->get_error_message()
			),
			$data
		);
	}

	/**
	 * The changed passage with a little text either side, for the caller to check.
	 *
	 * @param string $before Text before the edit.
	 * @param string $after  Text after the edit.
	 * @return string
	 */
	private static function excerpt( $before, $after ) {
		$before_length = strlen( $before );
		$after_length  = strlen( $after );
		$limit         = min( $before_length, $after_length );

		$prefix = 0;
		while ( $prefix < $limit && $before[ $prefix ] === $after[ $prefix ] ) {
			++$prefix;
		}

		$suffix = 0;
		while ( $suffix < $limit - $prefix && $before[ $before_length - 1 - $suffix ] === $after[ $after_length - 1 - $suffix ] ) {
			++$suffix;
		}

		$start = max( 0, $prefix - self::CONTEXT_CHARS );
		$end   = min( $after_length, $after_length - $suffix + self::CONTEXT_CHARS );

		// Never cut a multi-byte character in half.
		while ( $start > 0 && 0x80 === ( ord( $after[ $start ] ) & 0xC0 ) ) {
			--$start;
		}
		while ( $end < $after_length && 0x80 === ( ord( $after[ $end ] ) & 0xC0 ) ) {
			++$end;
		}

		$excerpt = substr( $after, $start, $end - $start );
		if ( mb_strlen( $excerpt, 'UTF-8' ) > 600 ) {
			$excerpt = mb_substr( $excerpt, 0, 300, 'UTF-8' ) . ' … ' . mb_substr( $excerpt, -280, null, 'UTF-8' );
		}

		return ( $start > 0 ? '…' : '' ) . $excerpt . ( $end < $after_length ? '…' : '' );
	}
}
