<?php
/**
 * Changes a bounded set of visual styling properties on one Elementor element.
 *
 * Two things make this narrower than it might look, both deliberate.
 *
 * First, the properties are named in plain terms — text_color, font_size, padding —
 * and mapped here to Elementor's control names, which differ per widget: a heading's
 * colour is title_color, a text editor's is text_color, a button's is
 * button_text_color. Exposing the raw control names would push that knowledge onto
 * the caller and make a wrong guess look like a successful write.
 *
 * Second, the map is curated rather than discovered. Elementor does not list these
 * controls in get_controls(): they live inside popover and group controls that are
 * expanded at render time, so a heading reports 177 controls and title_color is not
 * among them — while writing it demonstrably produces the right CSS. Anyone tempted
 * to add a get_controls() existence check here should know it would refuse every
 * property in this class. What guards correctness instead is the write engine's
 * value verification plus the test suite, which asserts the CSS Elementor actually
 * generates for each property.
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
 * Class Elementor_Style_Writer
 */
class Elementor_Style_Writer {

	/**
	 * Devices a value can be scoped to, mapped to Elementor's control suffix.
	 *
	 * @var array<string, string>
	 */
	const DEVICES = array(
		'desktop' => '',
		'tablet'  => '_tablet',
		'mobile'  => '_mobile',
	);

	/**
	 * Units accepted for a length.
	 *
	 * @var string[]
	 */
	const UNITS = array( 'px', 'em', 'rem', '%', 'vw', 'vh' );

	/**
	 * The style properties this ability will change.
	 *
	 * Each entry declares:
	 *   type       — how the caller's value is parsed and validated.
	 *   controls   — Elementor control name, per widget, with '*' as the fallback.
	 *   activator  — a control that must also be set before Elementor honours this
	 *                one. Group controls are inert without it: typography_font_size
	 *                alone produces no CSS at all until typography_typography is
	 *                'custom'. Verified, not assumed.
	 *   responsive — whether a _tablet / _mobile variant exists.
	 *   options    — the permitted values, for enumerated properties.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	const PROPERTIES = array(
		'text_color'       => array(
			'type'     => 'color',
			'controls' => array(
				'heading'   => 'title_color',
				'button'    => 'button_text_color',
				'icon-box'  => 'title_color',
				'image-box' => 'title_color',
				'*'         => 'text_color',
			),
		),
		'background_color' => array(
			'type'      => 'color',
			'controls'  => array(
				'button' => 'background_color',
				'*'      => '_background_color',
			),
			'activator' => array(
				'button' => array(),
				'*'      => array( '_background_background' => 'classic' ),
			),
		),
		'font_size'        => array(
			'type'       => 'length',
			'controls'   => array( '*' => 'typography_font_size' ),
			'activator'  => array( '*' => array( 'typography_typography' => 'custom' ) ),
			'responsive' => true,
		),
		'line_height'      => array(
			'type'       => 'length',
			'controls'   => array( '*' => 'typography_line_height' ),
			'activator'  => array( '*' => array( 'typography_typography' => 'custom' ) ),
			'responsive' => true,
		),
		'letter_spacing'   => array(
			'type'       => 'length',
			'controls'   => array( '*' => 'typography_letter_spacing' ),
			'activator'  => array( '*' => array( 'typography_typography' => 'custom' ) ),
			'responsive' => true,
		),
		'font_weight'      => array(
			'type'      => 'enum',
			'controls'  => array( '*' => 'typography_font_weight' ),
			'activator' => array( '*' => array( 'typography_typography' => 'custom' ) ),
			'options'   => array( '100', '200', '300', '400', '500', '600', '700', '800', '900', 'normal', 'bold' ),
		),
		'font_family'      => array(
			'type'      => 'font',
			'controls'  => array( '*' => 'typography_font_family' ),
			'activator' => array( '*' => array( 'typography_typography' => 'custom' ) ),
		),
		'text_transform'   => array(
			'type'      => 'enum',
			'controls'  => array( '*' => 'typography_text_transform' ),
			'activator' => array( '*' => array( 'typography_typography' => 'custom' ) ),
			'options'   => array( 'none', 'uppercase', 'lowercase', 'capitalize' ),
		),
		'text_align'       => array(
			'type'       => 'enum',
			'controls'   => array( '*' => 'align' ),
			'options'    => array( 'left', 'center', 'right', 'justify' ),
			'responsive' => true,
		),
		'padding'          => array(
			'type'       => 'box',
			'controls'   => array( '*' => '_padding' ),
			'responsive' => true,
		),
		'margin'           => array(
			'type'       => 'box',
			'controls'   => array( '*' => '_margin' ),
			'responsive' => true,
		),
	);

	/**
	 * Builds the mutator for a styling change.
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

			$plan = self::plan( $slug, $node, $input, $result );
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

						if ( null === $step['value'] ) {
							// Clearing: remove the key so the theme or kit default returns.
							if ( ! array_key_exists( $control, $target['settings'] ) ) {
								continue;
							}
							unset( $target['settings'][ $control ] );
						} else {
							if ( $before === $step['value'] ) {
								continue;
							}
							$target['settings'][ $control ] = $step['value'];
						}

						$changes[] = array(
							'element_id'  => isset( $target['id'] ) ? (string) $target['id'] : '',
							'widget_type' => $slug,
							'property'    => $step['property'],
							'control'     => $control,
							'previous'    => self::display( $before ),
							'current'     => self::display( $step['value'] ),
							// Tells the engine to verify the setting is gone, not present.
							'removed'     => null === $step['value'],
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
			 * Confirm the exact stored values rather than the presence of the keys.
			 * Elementor's save is a silent no-op on a post type it is not enabled to
			 * build, and the previous value stays under the same key, so a presence
			 * check would report that no-op as success.
			 */
			foreach ( $plan as $step ) {
				if ( null === $step['value'] ) {
					continue;
				}
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
	 * Everything refusable is refused here, before the tree is touched.
	 *
	 * @param string               $slug   Widget or element slug.
	 * @param array<string, mixed> $node   The target node as currently stored.
	 * @param array<string, mixed> $input  Ability input.
	 * @param array<string, mixed> $result Result accumulator, for warnings.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	private static function plan( $slug, array $node, array $input, array &$result ) {
		$device = isset( $input['device'] ) ? strtolower( trim( (string) $input['device'] ) ) : 'desktop';
		if ( ! array_key_exists( $device, self::DEVICES ) ) {
			return new WP_Error(
				'unknown_device',
				sprintf(
					/* translators: 1: supplied device, 2: comma-separated valid devices */
					__( '"%1$s" is not a device this site styles for. Use one of: %2$s.', 'mosmcp-abilities' ),
					$device,
					implode( ', ', array_keys( self::DEVICES ) )
				)
			);
		}

		$requested = array();
		foreach ( self::PROPERTIES as $property => $spec ) {
			if ( array_key_exists( $property, $input ) ) {
				$requested[ $property ] = $input[ $property ];
			}
		}

		if ( ! $requested ) {
			return new WP_Error(
				'nothing_to_style',
				sprintf(
					/* translators: %s: comma-separated property names */
					__( 'Provide at least one styling property. This ability understands: %s.', 'mosmcp-abilities' ),
					implode( ', ', array_keys( self::PROPERTIES ) )
				)
			);
		}

		$element = Elementor_Schema::element( $slug );
		if ( ! $element ) {
			return new WP_Error(
				'unknown_widget',
				sprintf(
					/* translators: %s: widget slug */
					__( 'This element is a "%s", which is not registered on this site — the widget or addon that created it may be deactivated. Its styling cannot be changed until it is available again.', 'mosmcp-abilities' ),
					$slug
				)
			);
		}

		if ( Elementor_Schema::is_atomic( $element ) ) {
			return new WP_Error(
				'atomic_styling_unsupported',
				sprintf(
					/* translators: %s: widget slug */
					__( 'The "%s" element is a v4 (atomic) element, which keeps its appearance in reusable style classes rather than in per-element settings. Writing settings on it would store values Elementor never reads. Change its styling in Elementor, or use a classic widget.', 'mosmcp-abilities' ),
					$slug
				)
			);
		}

		$settings = isset( $node['settings'] ) && is_array( $node['settings'] ) ? $node['settings'] : array();
		$suffix   = self::DEVICES[ $device ];
		$plan     = array();
		$seen     = array();

		foreach ( $requested as $property => $raw ) {
			$spec = self::PROPERTIES[ $property ];

			if ( '' !== $suffix && empty( $spec['responsive'] ) ) {
				return new WP_Error(
					'property_not_responsive',
					sprintf(
						/* translators: 1: property name, 2: device */
						__( 'The "%1$s" property applies to every screen size at once, so it cannot be set for %2$s alone. Set it without a device.', 'mosmcp-abilities' ),
						$property,
						$device
					)
				);
			}

			$control = self::control_for( $spec['controls'], $slug );
			if ( '' === $control ) {
				return new WP_Error(
					'property_not_supported',
					sprintf(
						/* translators: 1: property, 2: widget slug */
						__( 'The "%1$s" property cannot be set on a "%2$s" element.', 'mosmcp-abilities' ),
						$property,
						$slug
					)
				);
			}

			$value = self::build_value( $property, $spec, $raw );
			if ( is_wp_error( $value ) ) {
				return $value;
			}

			$plan[] = array(
				'property' => $property,
				'control'  => $control . $suffix,
				'value'    => $value,
			);

			// The activator has no responsive variant; it switches the group on wholesale.
			if ( null !== $value && ! empty( $spec['activator'] ) ) {
				foreach ( self::activator_for( $spec['activator'], $slug ) as $key => $on ) {
					if ( isset( $seen[ $key ] ) || ( isset( $settings[ $key ] ) && $on === $settings[ $key ] ) ) {
						continue;
					}
					$seen[ $key ] = true;
					$plan[]       = array(
						'property' => $property . ' (enabler)',
						'control'  => $key,
						'value'    => $on,
					);
				}
			}

			if ( 'margin' === $property && null !== $value ) {
				$result['warnings'][] = array(
					'code'    => 'margin_may_be_adjusted_by_theme',
					'message' => __( 'The margin was stored as asked, but Elementor combines the bottom margin with the theme kit\'s widget spacing in the generated CSS, so the rendered gap can differ from the number given here.', 'mosmcp-abilities' ),
					'context' => 'control=' . $control . $suffix,
				);
			}
		}

		if ( 'desktop' !== $device ) {
			$result['warnings'][] = array(
				'code'    => 'device_scoped_change',
				'message' => sprintf(
					/* translators: %s: device name */
					__( 'These values apply to %s and narrower only. The desktop appearance is unchanged, so the two can now differ.', 'mosmcp-abilities' ),
					$device
				),
				'context' => 'device=' . $device,
			);
		}

		return $plan;
	}

	/**
	 * The control name for a widget, falling back to the shared default.
	 *
	 * @param array<string, string> $controls Control map.
	 * @param string                $slug     Widget slug.
	 * @return string
	 */
	private static function control_for( array $controls, $slug ) {
		if ( isset( $controls[ $slug ] ) ) {
			return (string) $controls[ $slug ];
		}
		return isset( $controls['*'] ) ? (string) $controls['*'] : '';
	}

	/**
	 * The activator pairs for a widget.
	 *
	 * @param array<string, mixed> $activators Activator map.
	 * @param string               $slug       Widget slug.
	 * @return array<string, string>
	 */
	private static function activator_for( array $activators, $slug ) {
		if ( array_key_exists( $slug, $activators ) ) {
			return (array) $activators[ $slug ];
		}
		return isset( $activators['*'] ) ? (array) $activators['*'] : array();
	}

	/**
	 * Turns a caller's value into the shape Elementor stores, or refuses it.
	 *
	 * Returns null to mean "remove this setting", which is how a property is cleared
	 * back to the theme or kit default.
	 *
	 * @param string               $property Property name.
	 * @param array<string, mixed> $spec     Property spec.
	 * @param mixed                $raw      Caller-supplied value.
	 * @return mixed|WP_Error
	 */
	private static function build_value( $property, array $spec, $raw ) {
		$raw = is_string( $raw ) ? trim( $raw ) : $raw;

		if ( '' === $raw || null === $raw ) {
			return null;
		}

		switch ( $spec['type'] ) {
			case 'color':
				return self::parse_color( $property, (string) $raw );

			case 'length':
				return self::parse_length( $property, (string) $raw );

			case 'box':
				return self::parse_box( $property, (string) $raw );

			case 'enum':
				$options = (array) $spec['options'];
				$value   = strtolower( (string) $raw );
				if ( ! in_array( $value, $options, true ) ) {
					return new WP_Error(
						'invalid_style_value',
						sprintf(
							/* translators: 1: supplied value, 2: property, 3: comma-separated valid values */
							__( '"%1$s" is not a valid %2$s. Use one of: %3$s.', 'mosmcp-abilities' ),
							(string) $raw,
							$property,
							implode( ', ', $options )
						)
					);
				}
				return $value;

			case 'font':
				// A family name, not a URL or a stylesheet reference.
				if ( ! preg_match( '/^[A-Za-z0-9 \'\-]{1,64}$/', (string) $raw ) ) {
					return new WP_Error(
						'invalid_style_value',
						sprintf(
							/* translators: %s: supplied value */
							__( '"%s" does not look like a font family name. Give the family as it appears in Elementor, for example "Roboto" or "Playfair Display".', 'mosmcp-abilities' ),
							(string) $raw
						)
					);
				}
				return (string) $raw;
		}

		return new WP_Error( 'invalid_style_value', __( 'That property cannot be set.', 'mosmcp-abilities' ) );
	}

	/**
	 * Validates a colour.
	 *
	 * Restricted to hex and rgb/rgba on purpose: the value is written into generated
	 * CSS, so accepting arbitrary text would let a caller inject declarations of its
	 * own into the stylesheet.
	 *
	 * @param string $property Property name.
	 * @param string $raw      Supplied value.
	 * @return string|WP_Error
	 */
	private static function parse_color( $property, $raw ) {
		if ( preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $raw ) ) {
			return $raw;
		}

		if ( preg_match( '/^rgba?\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*(,\s*(0|1|0?\.\d+)\s*)?\)$/i', $raw ) ) {
			return $raw;
		}

		return new WP_Error(
			'invalid_style_value',
			sprintf(
				/* translators: 1: supplied value, 2: property name */
				__( '"%1$s" is not a colour this ability accepts for %2$s. Use a hex value such as #1A2B3C, or rgba(26, 43, 60, 0.5). Colour names and CSS variables are not accepted, because the value is written straight into the site\'s stylesheet.', 'mosmcp-abilities' ),
				$raw,
				$property
			)
		);
	}

	/**
	 * Validates a single length such as "42px" or "1.4em".
	 *
	 * @param string $property Property name.
	 * @param string $raw      Supplied value.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function parse_length( $property, $raw ) {
		$units = implode( '|', self::UNITS );

		if ( ! preg_match( '/^(-?\d+(?:\.\d+)?)(' . $units . ')$/i', $raw, $m ) ) {
			return new WP_Error(
				'invalid_style_value',
				sprintf(
					/* translators: 1: supplied value, 2: property, 3: comma-separated units */
					__( '"%1$s" is not a length this ability accepts for %2$s. Give a number followed by a unit, for example "42px" or "1.4em". Units: %3$s.', 'mosmcp-abilities' ),
					$raw,
					$property,
					implode( ', ', self::UNITS )
				)
			);
		}

		$size = (float) $m[1];

		return array(
			'unit'  => strtolower( $m[2] ),
			'size'  => (float) (int) $size === $size ? (int) $size : $size,
			'sizes' => array(),
		);
	}

	/**
	 * Validates a box value written as CSS shorthand: "10px", "10px 20px", or all four.
	 *
	 * @param string $property Property name.
	 * @param string $raw      Supplied value.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function parse_box( $property, $raw ) {
		$parts = preg_split( '/\s+/', trim( $raw ) );
		$count = is_array( $parts ) ? count( $parts ) : 0;

		if ( $count < 1 || $count > 4 ) {
			return new WP_Error(
				'invalid_style_value',
				sprintf(
					/* translators: 1: supplied value, 2: property name */
					__( '"%1$s" is not a valid %2$s. Use CSS shorthand with a unit on each value: "20px", "10px 20px", or "10px 20px 10px 20px".', 'mosmcp-abilities' ),
					$raw,
					$property
				)
			);
		}

		$units = implode( '|', self::UNITS );
		$sizes = array();
		$unit  = '';

		foreach ( $parts as $part ) {
			if ( ! preg_match( '/^(-?\d+(?:\.\d+)?)(' . $units . ')$/i', $part, $m ) ) {
				return new WP_Error(
					'invalid_style_value',
					sprintf(
						/* translators: 1: the offending value, 2: property, 3: comma-separated units */
						__( '"%1$s" in the %2$s is not a length. Each value needs a unit, for example "10px". Units: %3$s.', 'mosmcp-abilities' ),
						$part,
						$property,
						implode( ', ', self::UNITS )
					)
				);
			}

			$this_unit = strtolower( $m[2] );
			if ( '' === $unit ) {
				$unit = $this_unit;
			} elseif ( $unit !== $this_unit ) {
				return new WP_Error(
					'invalid_style_value',
					sprintf(
						/* translators: %s: property name */
						__( 'Elementor stores one unit for all four sides, so every value in the %s must use the same unit.', 'mosmcp-abilities' ),
						$property
					)
				);
			}

			$sizes[] = $m[1];
		}

		// CSS shorthand expansion: 1 value all sides, 2 vertical/horizontal, 3 adds bottom.
		switch ( $count ) {
			case 1:
				$box = array( $sizes[0], $sizes[0], $sizes[0], $sizes[0] );
				break;
			case 2:
				$box = array( $sizes[0], $sizes[1], $sizes[0], $sizes[1] );
				break;
			case 3:
				$box = array( $sizes[0], $sizes[1], $sizes[2], $sizes[1] );
				break;
			default:
				$box = array( $sizes[0], $sizes[1], $sizes[2], $sizes[3] );
				break;
		}

		return array(
			'unit'     => $unit,
			'top'      => (string) $box[0],
			'right'    => (string) $box[1],
			'bottom'   => (string) $box[2],
			'left'     => (string) $box[3],
			'isLinked' => count( array_unique( $box ) ) === 1,
		);
	}

	/**
	 * A short readable form of a stored value, for the change report.
	 *
	 * @param mixed $value Stored value.
	 * @return string
	 */
	private static function display( $value ) {
		if ( null === $value ) {
			return '';
		}
		if ( is_scalar( $value ) ) {
			return (string) $value;
		}
		if ( is_array( $value ) && isset( $value['size'], $value['unit'] ) ) {
			return $value['size'] . $value['unit'];
		}
		if ( is_array( $value ) && isset( $value['top'], $value['unit'] ) ) {
			return sprintf(
				'%s%s %s%s %s%s %s%s',
				$value['top'],
				$value['unit'],
				$value['right'],
				$value['unit'],
				$value['bottom'],
				$value['unit'],
				$value['left'],
				$value['unit']
			);
		}
		return (string) wp_json_encode( $value );
	}
}
