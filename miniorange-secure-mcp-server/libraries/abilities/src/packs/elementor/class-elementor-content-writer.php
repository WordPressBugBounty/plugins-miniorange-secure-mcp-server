<?php
/**
 * Changing the content of a single Elementor element without touching its design.
 *
 * The guarantee this class makes is structural, not a matter of care: it can only
 * ever write to a control that Elementor_Schema classifies as content for that
 * widget type. Styling controls are unreachable from here, so "change the heading
 * text" cannot alter a colour, a spacing value or a background — which is the whole
 * reason to expose this instead of a general settings patch.
 *
 * Both control models are supported. Classic widgets take plain values. Atomic (v4)
 * widgets wrap every value as {"$$type": "<type>", "value": ...}; where a wrapper is
 * already present its type is preserved rather than guessed, because the prop types
 * are unions and inventing the wrong one produces a layout Elementor cannot render.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Elementor;

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
 * Class Elementor_Content_Writer
 */
class Elementor_Content_Writer {

	/**
	 * Input fields this ability accepts, mapped to the content role each targets.
	 *
	 * @var array<string, string>
	 */
	const FIELD_ROLES = array(
		'text'     => 'text',
		'body'     => 'body',
		'image_id' => 'image',
		'link_url' => 'link',
	);

	/**
	 * Roles that can be written on an atomic element.
	 *
	 * Atomic image and link props are nested union shapes. Where no existing value
	 * is present there is nothing to mirror, and synthesising one would be a guess
	 * that renders as a broken element, so those roles are refused with a reason
	 * rather than attempted.
	 *
	 * @var string[]
	 */
	const ATOMIC_WRITABLE_ROLES = array( 'text', 'body' );

	/**
	 * Builds the mutator for a content change.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return callable
	 */
	public static function mutator( array $input ) {
		return static function ( array &$tree, array &$result ) use ( $input ) {
			$path = Elementor_Write_Engine::resolve_one( $tree, isset( $input['element_id'] ) ? $input['element_id'] : '' );
			if ( is_wp_error( $path ) ) {
				return $path;
			}

			$node = Elementor_Write_Engine::node_at( $tree, $path );
			if ( ! is_array( $node ) ) {
				return new WP_Error( 'element_not_found', __( 'The element could not be read from the layout.', 'mosmcp-abilities' ) );
			}

			$slug = isset( $node['widgetType'] ) && '' !== $node['widgetType']
				? (string) $node['widgetType']
				: ( isset( $node['elType'] ) ? (string) $node['elType'] : '' );

			$plan = self::plan( $slug, $node, $input );
			if ( is_wp_error( $plan ) ) {
				return $plan;
			}

			$changes = array();

			$applied = Elementor_Write_Engine::apply_at(
				$tree,
				$path,
				static function ( array &$target ) use ( $plan, $slug, &$changes ) {
					if ( ! isset( $target['settings'] ) || ! is_array( $target['settings'] ) ) {
						$target['settings'] = array();
					}

					foreach ( $plan as $step ) {
						$control = $step['control'];
						$before  = array_key_exists( $control, $target['settings'] ) ? $target['settings'][ $control ] : null;

						if ( $before === $step['value'] ) {
							continue;
						}

						$target['settings'][ $control ] = $step['value'];

						$changes[] = array(
							'element_id'  => isset( $target['id'] ) ? (string) $target['id'] : '',
							'widget_type' => $slug,
							'role'        => $step['role'],
							'control'     => $control,
							'previous'    => self::display( $before ),
							'current'     => self::display( $step['value'] ),
						);
					}

					return true;
				}
			);

			if ( is_wp_error( $applied ) ) {
				return $applied;
			}

			foreach ( $changes as $change ) {
				$result['changes'][] = $change;
			}

			/*
			 * Ask the engine to confirm the exact values, not just that the settings
			 * exist. Elementor's save is a silent no-op on post types it is not
			 * enabled to build, and the old value stays under the same key — so a
			 * presence check alone would report that no-op as a success.
			 */
			foreach ( $plan as $step ) {
				$result['verify_values'][] = array(
					'element_id' => isset( $node['id'] ) ? (string) $node['id'] : '',
					'control'    => $step['control'],
					'value'      => $step['value'],
				);
			}

			return true;
		};
	}

	/**
	 * Validates the request and produces the list of writes to perform.
	 *
	 * Everything that can be refused is refused here, before the tree is touched.
	 *
	 * @param string               $slug  Widget or element slug.
	 * @param array<string, mixed> $node  The target node as currently stored.
	 * @param array<string, mixed> $input Ability input.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	private static function plan( $slug, array $node, array $input ) {
		$requested = array();
		foreach ( self::FIELD_ROLES as $field => $role ) {
			if ( array_key_exists( $field, $input ) && '' !== trim( (string) $input[ $field ] ) ) {
				$requested[ $role ] = $input[ $field ];
			}
		}

		if ( ! $requested ) {
			return new WP_Error(
				'nothing_to_set',
				__( 'Provide at least one of text, body, image_id or link_url.', 'mosmcp-abilities' )
			);
		}

		$element = Elementor_Schema::element( $slug );
		if ( ! $element ) {
			return new WP_Error(
				'unknown_widget',
				sprintf(
					/* translators: %s: widget slug */
					__( 'This element is a "%s", which is not registered on this site — the widget or addon that created it may be deactivated. Its content cannot be changed until it is available again.', 'mosmcp-abilities' ),
					$slug
				)
			);
		}

		$is_atomic = Elementor_Schema::is_atomic( $element );
		$roles     = Elementor_Schema::content_controls( $slug, $element );

		if ( ! $roles ) {
			return new WP_Error(
				'element_has_no_content',
				sprintf(
					/* translators: %s: widget slug */
					__( 'The "%s" element has no editable text, image or link content. Containers and purely decorative widgets fall into this group.', 'mosmcp-abilities' ),
					$slug
				)
			);
		}

		$settings = isset( $node['settings'] ) && is_array( $node['settings'] ) ? $node['settings'] : array();
		$plan     = array();

		foreach ( $requested as $role => $raw ) {
			if ( ! isset( $roles[ $role ] ) ) {
				return new WP_Error(
					'role_not_supported',
					sprintf(
						/* translators: 1: content role, 2: widget slug, 3: comma-separated supported roles */
						__( 'A "%1$s" value cannot be set on a "%2$s" element. It supports: %3$s.', 'mosmcp-abilities' ),
						$role,
						$slug,
						implode( ', ', array_keys( $roles ) )
					)
				);
			}

			$control = (string) $roles[ $role ];

			$exists = self::control_exists( $element, $control, $is_atomic );
			if ( ! $exists ) {
				return new WP_Error(
					'control_missing',
					sprintf(
						/* translators: 1: control name, 2: widget slug */
						__( 'This version of Elementor does not expose a "%1$s" setting on the "%2$s" element, so the change was not attempted. Read the widget schema to see the settings it does expose.', 'mosmcp-abilities' ),
						$control,
						$slug
					)
				);
			}

			if ( $is_atomic && ! in_array( $role, self::ATOMIC_WRITABLE_ROLES, true ) ) {
				return new WP_Error(
					'atomic_role_unsupported',
					sprintf(
						/* translators: 1: content role, 2: widget slug */
						__( 'Setting the "%1$s" value on the v4 element "%2$s" is not supported yet, because its stored shape cannot be built reliably. Text content on v4 elements can be changed.', 'mosmcp-abilities' ),
						$role,
						$slug
					)
				);
			}

			$value = self::build_value( $role, $raw, $control, $settings, $is_atomic, $element );
			if ( is_wp_error( $value ) ) {
				return $value;
			}

			$plan[] = array(
				'role'    => $role,
				'control' => $control,
				'value'   => $value,
			);
		}

		return $plan;
	}

	/**
	 * Whether a control or prop actually exists on the element.
	 *
	 * Guards the role map against Elementor renaming something: a stale mapping is
	 * caught here instead of writing a setting the widget will ignore.
	 *
	 * @param object $element   Widget or element object.
	 * @param string $control   Control or prop name.
	 * @param bool   $is_atomic Whether the element is atomic.
	 * @return bool
	 */
	private static function control_exists( $element, $control, $is_atomic ) {
		if ( $is_atomic ) {
			return array_key_exists( $control, (array) $element::get_props_schema() );
		}
		if ( ! method_exists( $element, 'get_controls' ) ) {
			return false;
		}
		return array_key_exists( $control, (array) $element->get_controls() );
	}

	/**
	 * Builds the stored value for one role.
	 *
	 * @param string               $role      Content role.
	 * @param mixed                $raw       Caller-supplied value.
	 * @param string               $control   Control name.
	 * @param array<string, mixed> $settings  Existing node settings.
	 * @param bool                 $is_atomic Whether the element is atomic.
	 * @param object               $element   Widget or element object.
	 * @return mixed|WP_Error
	 */
	private static function build_value( $role, $raw, $control, array $settings, $is_atomic, $element ) {
		if ( 'image' === $role ) {
			return self::build_image( $raw );
		}

		if ( 'link' === $role ) {
			return self::build_link( $raw, $control, $settings );
		}

		// Text roles. wp_kses_post keeps the inline markup Elementor's editors
		// legitimately store while stripping scripts and event handlers.
		$text = wp_kses_post( (string) $raw );

		if ( ! $is_atomic ) {
			return $text;
		}

		return self::build_atomic_text( $text, $control, $settings, $element );
	}

	/**
	 * Builds an atomic prop value for a text role.
	 *
	 * Atomic props are unions and their stored shape is nested, not a flat wrapper.
	 * A heading's title, for example, is stored as
	 *
	 *   {"$$type":"html-v3","value":{"content":{"$$type":"string","value":"..."},"children":[]}}
	 *
	 * and Elementor rejects the whole document if that shape is wrong. Rather than
	 * hardcoding it per prop, the shape is taken from what is already stored, or
	 * failing that from the prop's own declared default — Elementor's own exemplar
	 * of a valid value. Only the innermost text is substituted, so anything else the
	 * value carries (rich-text children, dynamic-tag settings) survives untouched.
	 *
	 * @param string               $text     Sanitised text.
	 * @param string               $control  Prop name.
	 * @param array<string, mixed> $settings Existing node settings.
	 * @param object               $element  Widget object.
	 * @return mixed|WP_Error
	 */
	private static function build_atomic_text( $text, $control, array $settings, $element ) {
		$template = null;

		if ( isset( $settings[ $control ] ) && is_array( $settings[ $control ] ) && isset( $settings[ $control ]['$$type'] ) ) {
			$template = $settings[ $control ];
		} else {
			$props = (array) $element::get_props_schema();
			if ( isset( $props[ $control ] ) && is_object( $props[ $control ] ) && method_exists( $props[ $control ], 'jsonSerialize' ) ) {
				$serialized = (array) $props[ $control ]->jsonSerialize();
				if ( isset( $serialized['default'] ) && is_array( $serialized['default'] ) && isset( $serialized['default']['$$type'] ) ) {
					$template = $serialized['default'];
				}
			}
		}

		if ( null === $template ) {
			return new WP_Error(
				'atomic_shape_unknown',
				sprintf(
					/* translators: %s: prop name */
					__( 'The stored format of the "%s" setting on this v4 element could not be determined, so it was left unchanged rather than written in a format Elementor would reject.', 'mosmcp-abilities' ),
					$control
				)
			);
		}

		// A plain string union: replace the value directly.
		if ( ! is_array( $template['value'] ?? null ) ) {
			$template['value'] = $text;
			return $template;
		}

		// A nested shape such as html-v3: substitute only the innermost content.
		if ( isset( $template['value']['content'] ) && is_array( $template['value']['content'] )
			&& array_key_exists( 'value', $template['value']['content'] ) ) {
			$template['value']['content']['value'] = $text;
			if ( ! isset( $template['value']['children'] ) ) {
				$template['value']['children'] = array();
			}
			return $template;
		}

		return new WP_Error(
			'atomic_shape_unsupported',
			sprintf(
				/* translators: 1: prop name, 2: the value type Elementor expects */
				__( 'The "%1$s" setting on this v4 element is stored in a "%2$s" format this tool cannot write yet, so it was left unchanged. Its current value can still be read.', 'mosmcp-abilities' ),
				$control,
				(string) $template['$$type']
			)
		);
	}

	/**
	 * Builds a classic media control value from an attachment ID.
	 *
	 * @param mixed $raw Attachment ID.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function build_image( $raw ) {
		$attachment_id = absint( $raw );

		if ( $attachment_id <= 0 ) {
			return new WP_Error(
				'invalid_image_id',
				__( 'image_id must be the ID of an image in the media library.', 'mosmcp-abilities' )
			);
		}

		$attachment = get_post( $attachment_id );
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return new WP_Error(
				'attachment_not_found',
				sprintf(
					/* translators: %d: attachment ID */
					__( 'No media library item found with ID %d.', 'mosmcp-abilities' ),
					$attachment_id
				)
			);
		}

		/*
		 * Same reasoning as the featured-image ability: trust the recorded MIME type
		 * first, because wp_attachment_is_image() resolves the local file and
		 * returns false for images served through an offload or CDN plugin.
		 */
		$mime = (string) get_post_mime_type( $attachment_id );
		if ( 0 !== strpos( $mime, 'image/' ) && ! wp_attachment_is_image( $attachment_id ) ) {
			return new WP_Error(
				'attachment_not_an_image',
				sprintf(
					/* translators: 1: attachment ID, 2: MIME type */
					__( 'Media item %1$d is not an image (its type is "%2$s").', 'mosmcp-abilities' ),
					$attachment_id,
					'' !== $mime ? $mime : __( 'unknown', 'mosmcp-abilities' )
				)
			);
		}

		return array(
			'id'  => $attachment_id,
			'url' => (string) wp_get_attachment_image_url( $attachment_id, 'full' ),
		);
	}

	/**
	 * Builds a classic url control value, preserving the caller's other link options.
	 *
	 * @param mixed                $raw      Target URL.
	 * @param string               $control  Control name.
	 * @param array<string, mixed> $settings Existing node settings.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function build_link( $raw, $control, array $settings ) {
		$url = trim( (string) $raw );

		$safe = wp_http_validate_url( $url );
		if ( ! $safe ) {
			// Allow same-site relative links and anchors, which are legitimate and
			// which wp_http_validate_url() rejects because they are not absolute.
			if ( 1 !== preg_match( '#^(/|\#)[^\s"\'<>]*$#', $url ) ) {
				return new WP_Error(
					'invalid_link_url',
					sprintf(
						/* translators: %s: the rejected URL */
						__( '"%s" is not a usable link. Provide a full http(s) URL, a site-relative path beginning with /, or an anchor beginning with #.', 'mosmcp-abilities' ),
						$url
					)
				);
			}
			$safe = $url;
		}

		/*
		 * Elementor's url control stores is_external and nofollow alongside the URL.
		 * Preserving whatever is already there means changing a destination does not
		 * silently reset "open in new tab".
		 */
		$existing        = isset( $settings[ $control ] ) && is_array( $settings[ $control ] ) ? $settings[ $control ] : array();
		$existing['url'] = (string) $safe;

		return $existing;
	}

	/**
	 * Renders a stored value for the change report.
	 *
	 * @param mixed $value Stored value.
	 * @return string
	 */
	private static function display( $value ) {
		if ( null === $value ) {
			return '';
		}
		if ( is_array( $value ) ) {
			if ( array_key_exists( 'value', $value ) && is_scalar( $value['value'] ) ) {
				return (string) $value['value'];
			}
			foreach ( array( 'url', 'id' ) as $key ) {
				if ( isset( $value[ $key ] ) && is_scalar( $value[ $key ] ) ) {
					return (string) $value[ $key ];
				}
			}
			return (string) wp_json_encode( $value );
		}
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}

		$text = (string) $value;
		return strlen( $text ) > 300 ? substr( $text, 0, 297 ) . '...' : $text;
	}
}
