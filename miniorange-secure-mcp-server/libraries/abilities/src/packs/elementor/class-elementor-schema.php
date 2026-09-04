<?php
/**
 * Runtime introspection of Elementor's own widget and element registries.
 *
 * Every fact this pack needs about a widget — which controls it exposes, which of
 * them hold content rather than styling, whether it uses the classic control model
 * or the v4 atomic prop model — is already declared by Elementor and reachable
 * through its widgets and elements managers. Reading it at runtime keeps the pack
 * correct across Elementor releases and, just as importantly, means third-party
 * and Elementor Pro widgets are supported without being named here: whatever is in
 * the registry is what the pack can see.
 *
 * The alternative (hardcoded per-widget tables of control names) silently produces
 * wrong settings the moment Elementor or a third-party addon ships a change.
 *
 * Two things genuinely cannot be introspected and are therefore tabled below, each
 * marked as a maintained surface:
 *
 *   1. Group controls. Elementor's typography, border, box-shadow and background
 *      groups are expanded into flat control names at render time and never appear
 *      in get_controls(), so their field names and activator flags are listed here.
 *   2. Which control holds a widget's primary text. Elementor marks nothing as
 *      "this is the content", so the well-known widgets are mapped explicitly and
 *      everything else falls back to a conservative heuristic.
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
 * Class Elementor_Schema
 */
class Elementor_Schema {

	/**
	 * Control types that only draw editor chrome and carry no value.
	 *
	 * @var string[]
	 */
	const UI_ONLY_CONTROL_TYPES = array(
		'section',
		'tab',
		'tabs',
		'heading',
		'notice',
		'raw_html',
		'deprecated_notice',
		'alert',
		'divider',
		'button',
		'popover_toggle',
	);

	/**
	 * Control types whose value is editable content rather than styling.
	 *
	 * @var string[]
	 */
	const CONTENT_CONTROL_TYPES = array( 'text', 'textarea', 'wysiwyg', 'url', 'media' );

	/**
	 * Content-control name fragments that are styling or plumbing despite their type.
	 *
	 * Every Elementor widget inherits common controls named _element_id, _css_classes,
	 * _background_image and similar, all typed as text or media. Without this filter
	 * "set the heading text" would sometimes write into a background image slot.
	 *
	 * @var string[]
	 */
	const NON_CONTENT_FRAGMENTS = array(
		'background',
		'css',
		'_id',
		'class',
		'attribute',
		'custom_',
		'shape_divider',
		'motion_fx',
		'sticky',
		'mask_',
		'_animation',
		'transform',
	);

	/**
	 * Explicit content-control map for the classic widgets that matter most.
	 *
	 * Verified against the live registry on Elementor 4.2.2. Keys are logical roles;
	 * values are real control names. A widget absent from this table still works via
	 * heuristic_content_controls().
	 *
	 * @var array<string, array<string, string>>
	 */
	const CLASSIC_CONTENT = array(
		'heading'        => array(
			'text' => 'title',
			'link' => 'link',
		),
		'text-editor'    => array( 'text' => 'editor' ),
		'text-path'      => array( 'text' => 'text' ),
		'button'         => array(
			'text' => 'text',
			'link' => 'link',
		),
		'image'          => array(
			'image' => 'image',
			'text'  => 'caption',
			'link'  => 'link',
		),
		'image-box'      => array(
			'image' => 'image',
			'text'  => 'title_text',
			'body'  => 'description_text',
			'link'  => 'link',
		),
		'icon-box'       => array(
			'text' => 'title_text',
			'body' => 'description_text',
			'link' => 'link',
		),
		'testimonial'    => array(
			'image' => 'testimonial_image',
			'text'  => 'testimonial_name',
			'body'  => 'testimonial_content',
			'link'  => 'testimonial_link',
		),
		'video'          => array( 'link' => 'youtube_url' ),
		'html'           => array( 'text' => 'html' ),
		'shortcode'      => array( 'text' => 'shortcode' ),
		'alert'          => array(
			'text' => 'alert_title',
			'body' => 'alert_description',
		),
		'counter'        => array( 'text' => 'title' ),
		'progress'       => array( 'text' => 'title' ),
		'toggle'         => array( 'text' => 'tab_title' ),
		'call-to-action' => array(
			'image' => 'bg_image',
			'text'  => 'title',
			'body'  => 'description',
			'link'  => 'link',
		),
	);

	/**
	 * Explicit content-prop map for the v4 atomic widgets.
	 *
	 * Verified against the live registry: atomic widgets expose six or seven props
	 * each, so this table is complete rather than a best effort.
	 *
	 * @var array<string, array<string, string>>
	 */
	const ATOMIC_CONTENT = array(
		'e-heading'   => array(
			'text' => 'title',
			'link' => 'link',
		),
		'e-paragraph' => array(
			'text' => 'paragraph',
			'link' => 'link',
		),
		'e-button'    => array(
			'text' => 'text',
			'link' => 'link',
		),
		'e-image'     => array(
			'image' => 'image',
			'link'  => 'link',
		),
		'e-svg'       => array( 'image' => 'svg' ),
	);

	/**
	 * Group-control families, their activator control and their sub-fields.
	 *
	 * These are absent from get_controls() because Elementor flattens group controls
	 * at render time. Each family needs its activator set before any sub-field takes
	 * effect, which is the part that silently does nothing if missed.
	 *
	 * This is the pack's one hand-maintained surface. A CI check asserts the
	 * activator names still resolve against the live registry so drift fails loudly
	 * rather than quietly.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	const GROUP_CONTROLS = array(
		'typography'         => array(
			'activator' => 'typography_typography',
			'activate'  => 'custom',
			'fields'    => array(
				'typography_font_family',
				'typography_font_size',
				'typography_font_weight',
				'typography_text_transform',
				'typography_font_style',
				'typography_text_decoration',
				'typography_line_height',
				'typography_letter_spacing',
				'typography_word_spacing',
			),
		),
		'text_shadow'        => array(
			'activator' => 'text_shadow_text_shadow_type',
			'activate'  => 'yes',
			'fields'    => array( 'text_shadow_text_shadow' ),
		),
		'box_shadow'         => array(
			'activator' => 'box_shadow_box_shadow_type',
			'activate'  => 'yes',
			'fields'    => array( 'box_shadow_box_shadow', 'box_shadow_box_shadow_position' ),
		),
		'border'             => array(
			'activator' => 'border_border',
			'activate'  => 'solid',
			'fields'    => array( 'border_width', 'border_color' ),
		),
		'background'         => array(
			'activator' => 'background_background',
			'activate'  => 'classic',
			'fields'    => array( 'background_color', 'background_image', 'background_position', 'background_repeat', 'background_size' ),
		),
		'background_overlay' => array(
			'activator' => 'background_overlay_background',
			'activate'  => 'classic',
			'fields'    => array( 'background_overlay_color', 'background_overlay_image', 'background_overlay_opacity' ),
		),
	);

	/**
	 * Whether Elementor is active in this request.
	 *
	 * @return bool
	 */
	public static function available() {
		return class_exists( '\Elementor\Plugin' );
	}

	/**
	 * Elementor's version string, or an empty string when unavailable.
	 *
	 * @return string
	 */
	public static function version() {
		return defined( 'ELEMENTOR_VERSION' ) ? (string) ELEMENTOR_VERSION : '';
	}

	/**
	 * Whether Elementor Pro is active.
	 *
	 * @return bool
	 */
	public static function pro_available() {
		return class_exists( '\ElementorPro\Plugin' );
	}

	/**
	 * A WP_Error describing Elementor's absence, for guard clauses.
	 *
	 * @return WP_Error
	 */
	public static function unavailable_error() {
		return new WP_Error(
			'elementor_inactive',
			__( 'Elementor is not active on this site, so its layouts cannot be read or edited.', 'mosmcp-abilities' )
		);
	}

	/**
	 * Resolves a widget or structural element object by slug.
	 *
	 * @param string $slug Widget type or element type slug.
	 * @return object|null
	 */
	public static function element( $slug ) {
		if ( ! self::available() ) {
			return null;
		}

		$slug   = (string) $slug;
		$plugin = \Elementor\Plugin::$instance;

		$widget = $plugin->widgets_manager->get_widget_types( $slug );
		if ( $widget ) {
			return $widget;
		}

		$element = $plugin->elements_manager->get_element_types( $slug );
		return $element ? $element : null;
	}

	/**
	 * Whether an element uses the v4 atomic prop model.
	 *
	 * @param object $element Widget or element object.
	 * @return bool
	 */
	public static function is_atomic( $element ) {
		return is_object( $element ) && method_exists( $element, 'get_props_schema' );
	}

	/**
	 * Whether a slug belongs to Elementor Pro.
	 *
	 * @param object $element Widget or element object.
	 * @return bool
	 */
	public static function is_pro( $element ) {
		return is_object( $element ) && 0 === strpos( get_class( $element ), 'ElementorPro\\' );
	}

	/**
	 * Every registered widget and the structural elements that can hold children.
	 *
	 * @param array<string, mixed> $filters Optional 'search', 'category', 'include_pro', 'atomic_only'.
	 * @return array<int, array<string, mixed>>
	 */
	public static function widget_types( array $filters = array() ) {
		if ( ! self::available() ) {
			return array();
		}

		$search      = isset( $filters['search'] ) ? strtolower( trim( (string) $filters['search'] ) ) : '';
		$category    = isset( $filters['category'] ) ? (string) $filters['category'] : '';
		$atomic_only = ! empty( $filters['atomic_only'] );
		$plugin      = \Elementor\Plugin::$instance;
		$out         = array();

		foreach ( $plugin->widgets_manager->get_widget_types() as $slug => $widget ) {
			$entry = self::describe_element( (string) $slug, $widget, 'widget' );
			if ( self::passes_filters( $entry, $search, $category, $atomic_only ) ) {
				$out[] = $entry;
			}
		}

		foreach ( $plugin->elements_manager->get_element_types() as $slug => $element ) {
			$slug   = (string) $slug;
			$atomic = self::is_atomic( $element );

			/*
			 * Only containers matter to a caller building or reading a tree. The
			 * legacy section/column pair is included because existing pages are full
			 * of them, but the rest of the element registry is editor plumbing.
			 */
			if ( ! $atomic && ! in_array( $slug, array( 'container', 'section', 'column' ), true ) ) {
				continue;
			}

			$entry = self::describe_element( $slug, $element, 'element' );
			if ( self::passes_filters( $entry, $search, $category, $atomic_only ) ) {
				$out[] = $entry;
			}
		}

		usort(
			$out,
			static function ( $a, $b ) {
				return strcmp( $a['slug'], $b['slug'] );
			}
		);

		return $out;
	}

	/**
	 * Describes one registry entry.
	 *
	 * @param string $slug    Slug.
	 * @param object $element Widget or element object.
	 * @param string $kind    'widget' or 'element'.
	 * @return array<string, mixed>
	 */
	private static function describe_element( $slug, $element, $kind ) {
		$atomic = self::is_atomic( $element );

		$categories = array();
		if ( method_exists( $element, 'get_categories' ) ) {
			$categories = (array) $element->get_categories();
		}

		$content_controls = self::content_controls( $slug, $element );

		return array(
			'slug'             => $slug,
			'title'            => method_exists( $element, 'get_title' ) ? (string) $element->get_title() : $slug,
			'kind'             => $kind,
			'categories'       => array_values( array_map( 'strval', $categories ) ),
			'is_pro'           => self::is_pro( $element ),
			'is_atomic'        => $atomic,
			'accepts_children' => 'element' === $kind,
			'content_controls' => array_keys( $content_controls ),
			'editable'         => (bool) $content_controls,
		);
	}

	/**
	 * Applies the widget-list filters to one entry.
	 *
	 * @param array<string, mixed> $entry       Entry.
	 * @param string               $search      Lowercased search term.
	 * @param string               $category    Category slug.
	 * @param bool                 $atomic_only Whether to keep only atomic entries.
	 * @return bool
	 */
	private static function passes_filters( array $entry, $search, $category, $atomic_only ) {
		if ( $atomic_only && empty( $entry['is_atomic'] ) ) {
			return false;
		}
		if ( '' !== $category && ! in_array( $category, $entry['categories'], true ) ) {
			return false;
		}
		if ( '' !== $search ) {
			$haystack = strtolower( $entry['slug'] . ' ' . $entry['title'] );
			if ( false === strpos( $haystack, $search ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The content-bearing controls for a widget type, as role => control name.
	 *
	 * Explicit table first, conservative heuristic second. The heuristic exists so
	 * that a widget nobody has mapped — a third-party addon, a Pro widget, a new
	 * core widget — still reports something useful instead of nothing.
	 *
	 * @param string      $slug    Widget or element slug.
	 * @param object|null $element The already-resolved widget/element object, if the
	 *                             caller has one in hand (see {@see Elementor_Schema::element()}).
	 *                             Passing it avoids a second registry lookup for unmapped
	 *                             widgets, which fall through to the heuristic below.
	 * @return array<string, string>
	 */
	public static function content_controls( $slug, $element = null ) {
		$slug = (string) $slug;

		if ( isset( self::ATOMIC_CONTENT[ $slug ] ) ) {
			return self::ATOMIC_CONTENT[ $slug ];
		}
		if ( isset( self::CLASSIC_CONTENT[ $slug ] ) ) {
			return self::CLASSIC_CONTENT[ $slug ];
		}

		return self::heuristic_content_controls( $slug, $element );
	}

	/**
	 * Best-effort content controls for an unmapped widget.
	 *
	 * @param string      $slug    Widget slug.
	 * @param object|null $element The already-resolved widget/element object, or null
	 *                             to resolve it here.
	 * @return array<string, string>
	 */
	private static function heuristic_content_controls( $slug, $element = null ) {
		$element = $element ?? self::element( $slug );
		if ( ! $element ) {
			return array();
		}

		if ( self::is_atomic( $element ) ) {
			return self::heuristic_atomic_props( $element );
		}

		if ( ! method_exists( $element, 'get_controls' ) ) {
			return array();
		}

		$found = array();

		foreach ( (array) $element->get_controls() as $name => $control ) {
			$name = (string) $name;
			$type = isset( $control['type'] ) ? (string) $control['type'] : '';

			if ( ! in_array( $type, self::CONTENT_CONTROL_TYPES, true ) ) {
				continue;
			}
			if ( self::is_non_content_name( $name ) ) {
				continue;
			}

			if ( 'media' === $type && ! isset( $found['image'] ) ) {
				$found['image'] = $name;
				continue;
			}
			if ( 'url' === $type && ! isset( $found['link'] ) ) {
				$found['link'] = $name;
				continue;
			}
			if ( in_array( $type, array( 'text', 'textarea', 'wysiwyg' ), true ) ) {
				if ( ! isset( $found['text'] ) ) {
					$found['text'] = $name;
				} elseif ( ! isset( $found['body'] ) ) {
					$found['body'] = $name;
				}
			}
		}

		return $found;
	}

	/**
	 * Best-effort content props for an unmapped atomic widget.
	 *
	 * @param object $element Atomic widget object.
	 * @return array<string, string>
	 */
	private static function heuristic_atomic_props( $element ) {
		$found = array();

		foreach ( array_keys( (array) $element::get_props_schema() ) as $name ) {
			$name = (string) $name;
			if ( self::is_non_content_name( $name ) || 'classes' === $name ) {
				continue;
			}
			if ( 'link' === $name ) {
				$found['link'] = $name;
				continue;
			}
			if ( in_array( $name, array( 'title', 'text', 'paragraph', 'heading' ), true ) && ! isset( $found['text'] ) ) {
				$found['text'] = $name;
				continue;
			}
			if ( in_array( $name, array( 'image', 'svg' ), true ) && ! isset( $found['image'] ) ) {
				$found['image'] = $name;
			}
		}

		return $found;
	}

	/**
	 * Whether a control name is styling or plumbing rather than content.
	 *
	 * @param string $name Control name.
	 * @return bool
	 */
	public static function is_non_content_name( $name ) {
		$name = strtolower( (string) $name );

		// Underscore-prefixed controls are Elementor's inherited common controls.
		if ( 0 === strpos( $name, '_' ) ) {
			return true;
		}

		foreach ( self::NON_CONTENT_FRAGMENTS as $fragment ) {
			if ( false !== strpos( $name, $fragment ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The control or prop schema for one widget, filtered by mode.
	 *
	 * A single classic widget exposes 170 to 230 controls, so returning them all
	 * unfiltered would exhaust a caller's context on one request. 'discovery' gives
	 * names and types only, 'prefix' narrows to a family, and 'targeted' returns the
	 * full detail for named controls.
	 *
	 * @param string   $slug   Widget slug.
	 * @param string   $mode   'discovery', 'prefix' or 'targeted'.
	 * @param string[] $names  Control names for 'targeted'.
	 * @param string   $prefix Name prefix for 'prefix'.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function describe( $slug, $mode = 'discovery', array $names = array(), $prefix = '' ) {
		if ( ! self::available() ) {
			return self::unavailable_error();
		}

		$element = self::element( $slug );
		if ( ! $element ) {
			return new WP_Error(
				'unknown_widget',
				sprintf(
					/* translators: %s: requested widget slug */
					__( 'No Elementor widget or element is registered under the slug "%s". List the available widget types to see valid slugs.', 'mosmcp-abilities' ),
					$slug
				)
			);
		}

		$atomic  = self::is_atomic( $element );
		$entries = $atomic
			? self::atomic_entries( $element, $mode, $names, $prefix )
			: self::classic_entries( $element, $mode, $names, $prefix );

		return array(
			'slug'              => (string) $slug,
			'title'             => method_exists( $element, 'get_title' ) ? (string) $element->get_title() : (string) $slug,
			'kind'              => method_exists( $element, 'get_categories' ) ? 'widget' : 'element',
			'is_pro'            => self::is_pro( $element ),
			'is_atomic'         => $atomic,
			'elementor_version' => self::version(),
			'mode'              => $mode,
			'count'             => count( $entries ),
			'content_controls'  => self::content_controls( $slug ),
			'controls'          => $entries,
			'group_controls'    => 'discovery' === $mode ? array() : self::group_control_info(),
			'note'              => $atomic
				? __( 'Atomic (v4) element. Values are wrapped: {"$$type": "<type>", "value": ...}. Visual styling belongs in the element\'s style classes, not in settings.', 'mosmcp-abilities' )
				: __( 'Classic element. Group-control sub-fields (typography, border, box shadow, background) are not part of get_controls() and are listed separately under group_controls; each needs its activator set before any sub-field applies.', 'mosmcp-abilities' ),
		);
	}

	/**
	 * Serializes classic controls.
	 *
	 * @param object   $element Element object.
	 * @param string   $mode    Mode.
	 * @param string[] $names   Targeted names.
	 * @param string   $prefix  Prefix filter.
	 * @return array<int, array<string, mixed>>
	 */
	private static function classic_entries( $element, $mode, array $names, $prefix ) {
		if ( ! method_exists( $element, 'get_controls' ) ) {
			return array();
		}

		$out = array();

		foreach ( (array) $element->get_controls() as $name => $control ) {
			$name = (string) $name;
			$type = isset( $control['type'] ) ? (string) $control['type'] : '';

			if ( '' === $type || in_array( $type, self::UI_ONLY_CONTROL_TYPES, true ) ) {
				continue;
			}
			if ( ! self::in_mode( $name, $mode, $names, $prefix ) ) {
				continue;
			}

			$entry = array(
				'name'       => $name,
				'type'       => $type,
				'is_content' => ! self::is_non_content_name( $name ) && in_array( $type, self::CONTENT_CONTROL_TYPES, true ),
			);

			if ( 'discovery' !== $mode ) {
				if ( isset( $control['default'] ) && '' !== $control['default'] && array() !== $control['default'] ) {
					$entry['default'] = $control['default'];
				}
				if ( ! empty( $control['responsive'] ) ) {
					$entry['responsive'] = true;
				}
				if ( ! empty( $control['options'] ) && is_array( $control['options'] ) ) {
					$entry['options'] = array_values( array_filter( array_keys( $control['options'] ), 'strlen' ) );
				}
				if ( ! empty( $control['condition'] ) && is_array( $control['condition'] ) ) {
					$entry['condition'] = $control['condition'];
				}
			}

			$out[] = $entry;
		}

		return $out;
	}

	/**
	 * Serializes atomic props.
	 *
	 * @param object   $element Element object.
	 * @param string   $mode    Mode.
	 * @param string[] $names   Targeted names.
	 * @param string   $prefix  Prefix filter.
	 * @return array<int, array<string, mixed>>
	 */
	private static function atomic_entries( $element, $mode, array $names, $prefix ) {
		$out = array();

		foreach ( (array) $element::get_props_schema() as $name => $prop ) {
			$name = (string) $name;
			if ( ! self::in_mode( $name, $mode, $names, $prefix ) ) {
				continue;
			}

			$entry = array(
				'name'       => $name,
				'type'       => is_object( $prop ) && method_exists( $prop, 'get_key' ) ? (string) $prop::get_key() : 'unknown',
				'is_content' => ! self::is_non_content_name( $name ) && 'classes' !== $name,
			);

			if ( 'discovery' !== $mode && is_object( $prop ) ) {
				if ( method_exists( $prop, 'get_prop_types' ) ) {
					$members = (array) $prop->get_prop_types();
					if ( $members ) {
						$entry['union_of'] = array_keys( $members );
					}
				}
				if ( method_exists( $prop, 'jsonSerialize' ) ) {
					$serialized = (array) $prop->jsonSerialize();
					if ( isset( $serialized['default'] ) && null !== $serialized['default'] ) {
						$entry['default'] = $serialized['default'];
					}
					$settings = isset( $serialized['settings'] ) ? (array) $serialized['settings'] : array();
					if ( ! empty( $settings['enum'] ) ) {
						$entry['options'] = array_values( (array) $settings['enum'] );
					}
					if ( ! empty( $settings['required'] ) ) {
						$entry['required'] = true;
					}
				}
			}

			$out[] = $entry;
		}

		return $out;
	}

	/**
	 * Whether a control name survives the current filter mode.
	 *
	 * @param string   $name   Control name.
	 * @param string   $mode   Mode.
	 * @param string[] $names  Targeted names.
	 * @param string   $prefix Prefix filter.
	 * @return bool
	 */
	private static function in_mode( $name, $mode, array $names, $prefix ) {
		if ( 'targeted' === $mode ) {
			return in_array( $name, $names, true );
		}
		if ( 'prefix' === $mode ) {
			return '' === $prefix || 0 === strpos( $name, $prefix );
		}
		return true;
	}

	/**
	 * The group-control table, shaped for the response.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function group_control_info() {
		$out = array();

		foreach ( self::GROUP_CONTROLS as $family => $spec ) {
			$out[] = array(
				'family'        => (string) $family,
				'activator'     => (string) $spec['activator'],
				'activate_with' => (string) $spec['activate'],
				'fields'        => array_values( (array) $spec['fields'] ),
			);
		}

		return $out;
	}

	/**
	 * Resolves the mode from the raw names/prefix inputs.
	 *
	 * @param string[] $names  Targeted names.
	 * @param string   $prefix Prefix.
	 * @return string
	 */
	public static function resolve_mode( array $names, $prefix ) {
		if ( $names ) {
			return 'targeted';
		}
		if ( '' !== trim( (string) $prefix ) ) {
			return 'prefix';
		}
		return 'discovery';
	}
}
