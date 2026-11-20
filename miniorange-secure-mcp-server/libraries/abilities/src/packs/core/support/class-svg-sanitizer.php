<?php
/**
 * Strict SVG sanitizing for uploads, on sites that already accept SVG files.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Core\Support;

use DOMDocument;
use DOMElement;
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
 * directive covers IDE and Plugin Check runs that don't load that ruleset).
 */
// phpcs:disable WordPress.WP.I18n.TextDomainMismatch

// PHP's DOM extension names its properties in camelCase (documentElement, localName…).
// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

/**
 * Class Svg_Sanitizer
 *
 * An SVG is an XML document a browser runs, so it can carry scripts, event
 * handlers and links to other sites. WordPress refuses SVG uploads unless a
 * plugin allows them, and those plugins vary in how much they clean. Whatever
 * the site's plugin does, a file added through these abilities is also passed
 * through a strict allow-list here: only drawing elements and presentation
 * attributes survive, references may only point inside the file, and documents
 * with a DOCTYPE or entities (the route to XML entity attacks) are refused.
 *
 * It also closes the tricks enshrined/svg-sanitize (the library Safe SVG uses)
 * guards against: only UTF-8 files are read, so another encoding cannot hide a
 * DOCTYPE; CSS may not use escapes, comments-split names, at-rules that load
 * files or quoted addresses; and <use> elements may not multiply into more
 * copies than a browser can draw.
 */
class Svg_Sanitizer {

	/**
	 * Largest SVG accepted, in bytes.
	 */
	const MAX_BYTES = 524288;

	/**
	 * Most <use> copies a drawing may make, counting copies of copies.
	 */
	const MAX_USE_COPIES = 1000;

	/**
	 * Longest chain of <use> elements pointing at shapes that hold another <use>.
	 */
	const MAX_USE_DEPTH = 15;

	/**
	 * At-rules CSS may hold: none of them loads anything.
	 *
	 * @var string[]
	 */
	const AT_RULES = array( 'media', 'supports', 'keyframes' );

	/**
	 * Elements kept (lowercase local names).
	 *
	 * @var string[]
	 */
	const ELEMENTS = array(
		'svg',
		'g',
		'path',
		'rect',
		'circle',
		'ellipse',
		'line',
		'polyline',
		'polygon',
		'text',
		'tspan',
		'textpath',
		'defs',
		'lineargradient',
		'radialgradient',
		'stop',
		'clippath',
		'mask',
		'pattern',
		'symbol',
		'use',
		'title',
		'desc',
		'style',
		'marker',
		'filter',
		'feblend',
		'fecolormatrix',
		'fecomposite',
		'fedropshadow',
		'feflood',
		'fegaussianblur',
		'femerge',
		'femergenode',
		'femorphology',
		'feoffset',
	);

	/**
	 * Attributes kept (lowercase, with any namespace prefix).
	 *
	 * @var string[]
	 */
	const ATTRIBUTES = array(
		'id',
		'class',
		'style',
		'x',
		'y',
		'x1',
		'x2',
		'y1',
		'y2',
		'cx',
		'cy',
		'r',
		'rx',
		'ry',
		'fx',
		'fy',
		'dx',
		'dy',
		'width',
		'height',
		'viewbox',
		'preserveaspectratio',
		'd',
		'points',
		'transform',
		'fill',
		'fill-opacity',
		'fill-rule',
		'clip-rule',
		'clip-path',
		'clippathunits',
		'mask',
		'maskunits',
		'maskcontentunits',
		'stroke',
		'stroke-width',
		'stroke-linecap',
		'stroke-linejoin',
		'stroke-miterlimit',
		'stroke-dasharray',
		'stroke-dashoffset',
		'stroke-opacity',
		'opacity',
		'offset',
		'stop-color',
		'stop-opacity',
		'gradientunits',
		'gradienttransform',
		'spreadmethod',
		'patternunits',
		'patterncontentunits',
		'patterntransform',
		'font-family',
		'font-size',
		'font-weight',
		'font-style',
		'text-anchor',
		'dominant-baseline',
		'letter-spacing',
		'word-spacing',
		'text-decoration',
		'textlength',
		'lengthadjust',
		'rotate',
		'display',
		'visibility',
		'overflow',
		'color',
		'xmlns',
		'xmlns:xlink',
		'version',
		'href',
		'xlink:href',
		'filter',
		'filterunits',
		'primitiveunits',
		'stddeviation',
		'in',
		'in2',
		'result',
		'mode',
		'values',
		'type',
		'operator',
		'k1',
		'k2',
		'k3',
		'k4',
		'flood-color',
		'flood-opacity',
		'radius',
		'markerwidth',
		'markerheight',
		'markerunits',
		'refx',
		'refy',
		'orient',
		'marker-start',
		'marker-mid',
		'marker-end',
		'vector-effect',
		'shape-rendering',
		'paint-order',
		'mix-blend-mode',
		'isolation',
		'aria-hidden',
		'aria-label',
		'role',
		'focusable',
		'xml:space',
	);

	/**
	 * Cleans an SVG file in place.
	 *
	 * @param string $path File path.
	 * @return array{removed:string[]}|WP_Error What was removed.
	 */
	public static function clean_file( $path ) {
		$size = (int) filesize( $path );
		if ( $size > self::MAX_BYTES ) {
			return self::refuse(
				sprintf(
					/* translators: 1: file size in KB, 2: limit in KB. */
					__( 'That SVG is %1$d KB; SVG files over %2$d KB are refused, because a logo or icon is far smaller and a large SVG is usually an embedded picture or something worse.', 'mosmcp-abilities' ),
					(int) ceil( $size / 1024 ),
					(int) ( self::MAX_BYTES / 1024 )
				)
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local temporary upload, not a remote URL.
		$clean = self::clean( (string) file_get_contents( $path ) );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Rewriting the private temporary upload before WordPress moves it.
		if ( false === file_put_contents( $path, $clean['svg'] ) ) {
			return self::refuse( __( 'The cleaned SVG could not be written.', 'mosmcp-abilities' ) );
		}
		return array( 'removed' => $clean['removed'] );
	}

	/**
	 * Cleans SVG markup.
	 *
	 * @param string $svg SVG markup.
	 * @return array{svg:string,removed:string[]}|WP_Error
	 */
	public static function clean( $svg ) {
		// The DOCTYPE check below reads bytes, so a UTF-16 or EBCDIC file, which the
		// XML parser would still read, could hide a DOCTYPE from it.
		if ( false !== strpos( $svg, "\0" ) || ! preg_match( '//u', $svg ) ) {
			return self::refuse( __( 'That SVG is not saved as UTF-8 text. Save it again as UTF-8, the usual setting, and try once more.', 'mosmcp-abilities' ) );
		}
		if ( preg_match( '/<!(DOCTYPE|ENTITY)/i', $svg ) ) {
			return self::refuse( self::doctype_message() );
		}

		$dom      = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$loaded   = $dom->loadXML( $svg, LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( $loaded && null !== $dom->doctype ) {
			return self::refuse( self::doctype_message() );
		}

		$root = $loaded ? $dom->documentElement : null;
		if ( ! $root instanceof DOMElement || 'svg' !== strtolower( $root->localName ) ) {
			return self::refuse( __( 'That file is not a valid SVG image.', 'mosmcp-abilities' ) );
		}

		$removed = array();
		self::walk( $root, $removed );

		$uses = self::check_uses( $dom );
		if ( is_wp_error( $uses ) ) {
			return $uses;
		}

		// Comments and processing instructions carry nothing a drawing needs.
		$xpath = new \DOMXPath( $dom );
		foreach ( iterator_to_array( $xpath->query( '//comment() | //processing-instruction()' ) ) as $node ) {
			$node->parentNode->removeChild( $node );
		}

		return array(
			'svg'     => '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . $dom->saveXML( $root ),
			'removed' => array_values( array_unique( $removed ) ),
		);
	}

	/**
	 * Removes every element and attribute outside the allow-list, depth first.
	 *
	 * @param DOMElement $element Element.
	 * @param string[]   $removed Names of what was removed.
	 * @return void
	 */
	private static function walk( DOMElement $element, array &$removed ) {
		foreach ( iterator_to_array( $element->attributes ) as $attribute ) {
			$name  = strtolower( $attribute->nodeName );
			$value = (string) $attribute->value;
			if ( ! in_array( $name, self::ATTRIBUTES, true ) || ! self::safe_value( $name, $value ) ) {
				$element->removeAttributeNode( $attribute );
				$removed[] = '@' . $name;
			}
		}

		foreach ( iterator_to_array( $element->childNodes ) as $child ) {
			if ( ! $child instanceof DOMElement ) {
				continue;
			}
			if ( ! in_array( strtolower( $child->localName ), self::ELEMENTS, true ) ) {
				$element->removeChild( $child );
				$removed[] = '<' . strtolower( $child->localName ) . '>';
				continue;
			}
			if ( 'style' === strtolower( $child->localName ) && ! self::safe_css( (string) $child->textContent ) ) {
				$element->removeChild( $child );
				$removed[] = '<style>';
				continue;
			}
			self::walk( $child, $removed );
		}
	}

	/**
	 * Whether an attribute value is safe: links only inside the file, no script
	 * and no outside resources.
	 *
	 * @param string $name  Attribute name.
	 * @param string $value Value.
	 * @return bool
	 */
	private static function safe_value( $name, $value ) {
		// Presentation attributes are read as CSS, where an escape such as u\72l(
		// is a url( that the checks below would not see.
		if ( false !== strpos( $value, '\\' ) ) {
			return false;
		}
		$compact = strtolower( (string) preg_replace( '/\s+/', '', $value ) );
		if ( 'href' === $name || 'xlink:href' === $name ) {
			return 0 === strpos( $compact, '#' );
		}
		if ( 'style' === $name ) {
			return self::safe_css( $value );
		}
		if ( false !== strpos( $compact, 'javascript:' ) || false !== strpos( $compact, 'data:' ) ) {
			return false;
		}
		return self::local_urls( $compact );
	}

	/**
	 * Whether CSS stays inside the file: no imports, no script, no outside urls.
	 *
	 * CSS escapes (u\72l, @\69mport) would turn names the checks look for into
	 * names they do not see, so CSS with a backslash is refused outright; comments
	 * are dropped before checking, so they cannot split a name either.
	 *
	 * @param string $css CSS.
	 * @return bool
	 */
	private static function safe_css( $css ) {
		if ( false !== strpos( $css, '\\' ) ) {
			return false;
		}

		$css     = strtolower( (string) preg_replace( '#/\*.*?(\*/|$)#s', '', $css ) );
		$compact = (string) preg_replace( '/\s+/', '', $css );
		foreach ( array( 'javascript:', 'expression(', 'behavior:', '-moz-binding', '</', 'image-set(', 'image(', 'src(' ) as $bad ) {
			if ( false !== strpos( $compact, $bad ) ) {
				return false;
			}
		}

		// At-rules that load something (@import, @font-face and the rest) are refused.
		// Read before whitespace is dropped, which would run "@media screen" together.
		preg_match_all( '/@(-[a-z]+-)?([a-z-]+)/', $css, $rules );
		foreach ( $rules[2] as $rule ) {
			if ( ! in_array( $rule, self::AT_RULES, true ) ) {
				return false;
			}
		}

		// Some properties load a quoted address without url().
		if ( preg_match( '/["\'][^"\']*(\/\/|[a-z][a-z0-9+.-]*:)/', $compact ) ) {
			return false;
		}

		return self::local_urls( $compact );
	}

	/**
	 * Refuses a drawing whose <use> elements multiply. Each <use> draws a copy of
	 * what it points at, so a few nested ones can ask a browser for millions of
	 * shapes and freeze whoever opens the image. Same limits as enshrined/svg-sanitize.
	 *
	 * @param DOMDocument $dom Document, already cleaned.
	 * @return true|WP_Error
	 */
	private static function check_uses( DOMDocument $dom ) {
		$xpath = new \DOMXPath( $dom );
		$ids   = array();
		foreach ( $xpath->query( '//*[@id]' ) as $node ) {
			$id = $node->getAttribute( 'id' );
			if ( ! isset( $ids[ $id ] ) ) {
				// Browsers follow the first element with an ID.
				$ids[ $id ] = $node;
			}
		}

		$memo = array();
		if ( self::copies( $dom->documentElement, $ids, $xpath, $memo, 0 ) > self::MAX_USE_COPIES ) {
			return self::refuse(
				sprintf(
					/* translators: 1: most copies allowed, 2: deepest nesting allowed. */
					__( 'That SVG repeats shapes through <use> elements so heavily (more than %1$d copies, or chains more than %2$d deep) that it could freeze the browser of anyone who opens it, so it was refused.', 'mosmcp-abilities' ),
					self::MAX_USE_COPIES,
					self::MAX_USE_DEPTH
				)
			);
		}
		return true;
	}

	/**
	 * How many <use> copies an element draws, counting the copies inside what each
	 * one points at.
	 *
	 * @param DOMElement               $element Element.
	 * @param array<string,DOMElement> $ids     Elements by ID.
	 * @param \DOMXPath                $xpath   XPath on the document.
	 * @param array<int,int>           $memo    Counts already worked out, by element.
	 * @param int                      $depth   How many <use> led here.
	 * @return int More than MAX_USE_COPIES when the chain is too deep or loops.
	 */
	private static function copies( DOMElement $element, array $ids, \DOMXPath $xpath, array &$memo, $depth ) {
		$key = spl_object_id( $element );
		if ( isset( $memo[ $key ] ) ) {
			return $memo[ $key ];
		}
		if ( $depth > self::MAX_USE_DEPTH ) {
			return self::MAX_USE_COPIES + 1;
		}

		// While this element is being counted, a <use> leading back to it is a loop.
		$memo[ $key ] = self::MAX_USE_COPIES + 1;

		$count = 0;
		$uses  = 'use' === strtolower( $element->localName ) ? array( $element ) : $xpath->query( './/*[local-name()="use"]', $element );
		foreach ( $uses as $use ) {
			$ref    = '' !== $use->getAttribute( 'href' ) ? $use->getAttribute( 'href' ) : $use->getAttribute( 'xlink:href' );
			$target = '#' === substr( $ref, 0, 1 ) && isset( $ids[ substr( $ref, 1 ) ] ) ? $ids[ substr( $ref, 1 ) ] : null;
			$count += 1 + ( null !== $target ? self::copies( $target, $ids, $xpath, $memo, $depth + 1 ) : 0 );
			if ( $count > self::MAX_USE_COPIES ) {
				break;
			}
		}

		$memo[ $key ] = $count;
		return $count;
	}

	/**
	 * Whether every url() in a value points inside the file.
	 *
	 * @param string $compact Lowercase value without whitespace.
	 * @return bool
	 */
	private static function local_urls( $compact ) {
		preg_match_all( '/url\(([^)]*)\)/', $compact, $found );
		foreach ( $found[1] as $target ) {
			if ( 0 !== strpos( trim( $target, '\'"' ), '#' ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Why a file with a DOCTYPE is refused.
	 *
	 * @return string
	 */
	private static function doctype_message() {
		return __( 'That SVG declares a DOCTYPE or entities, which a drawing never needs and which can be used to attack the server, so it was refused.', 'mosmcp-abilities' );
	}

	/**
	 * A refusal.
	 *
	 * @param string $message Message.
	 * @return WP_Error
	 */
	private static function refuse( $message ) {
		return Site_Support::error( 'mosmcp_svg_refused', $message, Site_Support::CAUSE_INVALID_INPUT, false );
	}
}
