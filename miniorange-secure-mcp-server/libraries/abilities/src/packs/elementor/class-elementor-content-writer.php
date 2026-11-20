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

use MoSMCP\Abilities\Support\Html_Policy;
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
	 * Slug of Elementor's HTML widget, which Elementor gates separately.
	 *
	 * @var string
	 */
	const HTML_WIDGET = 'html';

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

			$warnings = array();

			$plan = self::plan( $slug, $node, $input, $warnings );
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

			foreach ( $warnings as $warning ) {
				$result['warnings'][] = $warning;
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
	 * @param string                            $slug     Widget or element slug.
	 * @param array<string, mixed>              $node     The target node as currently stored.
	 * @param array<string, mixed>              $input    Ability input.
	 * @param array<int, array<string, string>> $warnings Warnings accumulator, modified in place.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	private static function plan( $slug, array $node, array $input, array &$warnings ) {
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

			$value = self::build_value( $role, $raw, $control, $settings, $is_atomic, $element, $slug, $warnings );
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
	 * @param string                            $role      Content role.
	 * @param mixed                             $raw       Caller-supplied value.
	 * @param string                            $control   Control name.
	 * @param array<string, mixed>              $settings  Existing node settings.
	 * @param bool                              $is_atomic Whether the element is atomic.
	 * @param object                            $element   Widget or element object.
	 * @param string                            $slug      Widget or element slug.
	 * @param array<int, array<string, string>> $warnings  Warnings accumulator, modified in place.
	 * @return mixed|WP_Error
	 */
	private static function build_value( $role, $raw, $control, array $settings, $is_atomic, $element, $slug, array &$warnings ) {
		if ( 'image' === $role ) {
			return self::build_image( $raw );
		}

		if ( 'link' === $role ) {
			return self::build_link( $raw, $control, $settings );
		}

		// Text roles: markup is handled exactly as Elementor's editor handles it for
		// this user, and never stripped silently. See filter_html().
		$text = self::filter_html( (string) $raw, $slug, $control, $is_atomic, $warnings );
		if ( is_wp_error( $text ) ) {
			return $text;
		}

		if ( ! $is_atomic ) {
			return $text;
		}

		return self::build_atomic_text( $text, $control, $settings, $element );
	}

	/**
	 * Applies the rule Elementor's own editor uses for markup in content.
	 *
	 * Elementor stores markup exactly as given for users with the unfiltered_html
	 * capability and runs everyone else's through wp_kses_post() when it saves
	 * (Document::save()), so the AI acting as a user can store exactly what that
	 * user could store by hand. Two Elementor rules narrow this further: the HTML
	 * widget also needs Elementor's HTML-widget permission, and v4 (atomic) elements
	 * are sanitised by Elementor for every user, so they are always filtered here.
	 *
	 * Filtering is never silent: whole elements that would be lost are refused, and
	 * removed attributes are reported. Html_Policy applies that; this method only
	 * decides which of Elementor's rules applies to the user and the widget.
	 *
	 * @param string                            $raw       Caller-supplied markup.
	 * @param string                            $slug      Widget or element slug.
	 * @param string                            $control   Control name.
	 * @param bool                              $is_atomic Whether the element is atomic.
	 * @param array<int, array<string, string>> $warnings  Warnings accumulator, modified in place.
	 * @return string|WP_Error
	 */
	private static function filter_html( $raw, $slug, $control, $is_atomic, array &$warnings ) {
		$detail = '';
		$reason = self::html_restriction( $slug, $is_atomic, $detail );

		return Html_Policy::apply(
			$raw,
			$reason,
			sprintf(
				/* translators: 1: setting name, 2: widget slug */
				__( 'the "%1$s" setting of this "%2$s" element', 'mosmcp-abilities' ),
				$control,
				$slug
			),
			$warnings,
			'' === $reason ? '' : self::restriction_message( $reason, $detail )
		);
	}

	/**
	 * Why the current user's markup must be filtered, or '' when it is stored as given.
	 *
	 * @param string $slug      Widget or element slug.
	 * @param bool   $is_atomic Whether the element is atomic.
	 * @param string $detail    Set to Elementor's error when its permission check fails.
	 * @return string 'atomic', 'unfiltered_html', 'html_widget', 'html_widget_unconfirmed',
	 *                or '' for no filtering.
	 */
	private static function html_restriction( $slug, $is_atomic, &$detail ) {
		if ( $is_atomic ) {
			return 'atomic';
		}

		if ( ! current_user_can( 'unfiltered_html' ) ) {
			return 'unfiltered_html';
		}

		if ( self::HTML_WIDGET === $slug && ! self::can_use_html_widget( $detail ) ) {
			return '' === $detail ? 'html_widget' : 'html_widget_unconfirmed';
		}

		return '';
	}

	/**
	 * Whether the current user may put code in Elementor's HTML widget.
	 *
	 * Elementor gates this widget separately from unfiltered_html: only users with
	 * manage_options can edit it, plus roles allowed under Elementor > Role Manager.
	 * Editors hold unfiltered_html on a single site, yet Elementor keeps them out of
	 * this widget by default, so the AI does too.
	 *
	 * If Elementor's own check fails, access is refused (fail closed), and the error
	 * is handed back so the caller is told the check failed rather than that the
	 * user lacks the permission.
	 *
	 * @param string $error Set to Elementor's error message when its check fails.
	 * @return bool
	 */
	private static function can_use_html_widget( &$error ) {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		$check = array( \Elementor\User::class, 'is_current_user_can_use_custom_html' );
		if ( ! is_callable( $check ) ) {
			return false;
		}

		try {
			return (bool) call_user_func( $check );
		} catch ( \Throwable $e ) {
			$error = '' !== $e->getMessage() ? $e->getMessage() : get_class( $e );
			return false;
		}
	}

	/**
	 * Explains a restriction to the caller, saying who is allowed.
	 *
	 * @param string $reason Restriction code from html_restriction().
	 * @param string $detail Elementor's error, for 'html_widget_unconfirmed'.
	 * @return string
	 */
	private static function restriction_message( $reason, $detail = '' ) {
		if ( 'atomic' === $reason ) {
			return __( 'Elementor v4 elements only keep basic text formatting; use a classic HTML widget for code.', 'mosmcp-abilities' );
		}

		if ( 'html_widget_unconfirmed' === $reason ) {
			return sprintf(
				/* translators: %s: error message from Elementor */
				__( 'Elementor\'s HTML-widget permission check failed, so this account\'s access could not be confirmed (Elementor said: %s). An administrator can still make this change.', 'mosmcp-abilities' ),
				$detail
			);
		}

		if ( 'html_widget' === $reason ) {
			return __( 'Elementor only allows code in an HTML widget for administrators and for roles given HTML-widget access under Elementor > Role Manager.', 'mosmcp-abilities' );
		}

		return __( 'WordPress and Elementor only allow this markup for users with the unfiltered_html capability: administrators and editors on a single site, super admins on multisite, and nobody when DISALLOW_UNFILTERED_HTML is set.', 'mosmcp-abilities' );
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
