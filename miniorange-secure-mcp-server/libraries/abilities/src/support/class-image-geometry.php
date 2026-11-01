<?php
/**
 * Where to cut an image, in terms a model can ask for without seeing it.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Image_Geometry
 *
 * A model cannot see the pixels, so it asks for a shape ("16:9", "square") and a
 * named part to keep ("top", "left"); this turns that into the exact box.
 */
class Image_Geometry {

	/**
	 * Named focus points as fractions across and down.
	 *
	 * @var array<string, float[]>
	 */
	const FOCUS = array(
		'center'       => array( 0.5, 0.5 ),
		'top'          => array( 0.5, 0.0 ),
		'bottom'       => array( 0.5, 1.0 ),
		'left'         => array( 0.0, 0.5 ),
		'right'        => array( 1.0, 0.5 ),
		'top-left'     => array( 0.0, 0.0 ),
		'top-right'    => array( 1.0, 0.0 ),
		'bottom-left'  => array( 0.0, 1.0 ),
		'bottom-right' => array( 1.0, 1.0 ),
	);

	/**
	 * The largest box of a given shape that fits the image, placed as near the
	 * focus point as the edges allow.
	 *
	 * @param int    $width  Image width.
	 * @param int    $height Image height.
	 * @param float  $ratio  Box width divided by height.
	 * @param string $focus  Key of FOCUS; unknown keys mean the centre.
	 * @return array{x:int,y:int,width:int,height:int}
	 */
	public static function box( $width, $height, $ratio, $focus ) {
		$width  = max( 1, (int) $width );
		$height = max( 1, (int) $height );
		$ratio  = $ratio > 0 ? (float) $ratio : 1.0;

		if ( $width / $height > $ratio ) {
			$box_h = $height;
			$box_w = max( 1, min( $width, (int) round( $height * $ratio ) ) );
		} else {
			$box_w = $width;
			$box_h = max( 1, min( $height, (int) round( $width / $ratio ) ) );
		}

		$point = isset( self::FOCUS[ $focus ] ) ? self::FOCUS[ $focus ] : self::FOCUS['center'];

		return array(
			'x'      => (int) round( max( 0, min( $width - $box_w, $point[0] * $width - $box_w / 2 ) ) ),
			'y'      => (int) round( max( 0, min( $height - $box_h, $point[1] * $height - $box_h / 2 ) ) ),
			'width'  => $box_w,
			'height' => $box_h,
		);
	}

	/**
	 * A shape given as "16:9", "16/9", "1.5" or "square", as width over height.
	 *
	 * @param string $shape Shape.
	 * @return float 0 when it cannot be read.
	 */
	public static function ratio( $shape ) {
		$shape = strtolower( trim( (string) $shape ) );
		if ( 'square' === $shape ) {
			return 1.0;
		}
		if ( preg_match( '#^(\d+(?:\.\d+)?)\s*[:/x]\s*(\d+(?:\.\d+)?)$#', $shape, $parts ) ) {
			return (float) $parts[2] > 0 ? (float) $parts[1] / (float) $parts[2] : 0.0;
		}
		return is_numeric( $shape ) && (float) $shape > 0 ? (float) $shape : 0.0;
	}
}
