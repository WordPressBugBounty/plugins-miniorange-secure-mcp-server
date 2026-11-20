<?php
/**
 * Crops, rotates, flips and resizes images the way WordPress's own image editor
 * does, either into a new media item or in place with the original kept.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Core\Support;

use MoSMCP\Abilities\Packs\Site\Site_Support;
use MoSMCP\Abilities\Support\Image_Geometry;
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
 * Class Image_Edits
 *
 * Edits are expressed in WordPress's own image-edit history (rotate "r", flip
 * "f", crop "c") and applied by core's image_edit_apply_changes(), so they mean
 * exactly what they mean in Media > Edit Image. Editing in place goes through
 * core's wp_save_image(), which keeps the original file and records it, so
 * WordPress's own "Restore original image" button works on it afterwards.
 */
class Image_Edits {

	/**
	 * Loads core's image-edit functions, absent in API requests.
	 *
	 * @return void
	 */
	public static function load() {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/image-edit.php';
	}

	/**
	 * An image attachment this server can edit, or a refusal.
	 *
	 * @param int $id Attachment ID.
	 * @return WP_Post|WP_Error
	 */
	public static function editable( $id ) {
		$post = get_post( absint( $id ) );
		if ( ! $post instanceof WP_Post || 'attachment' !== $post->post_type || ! wp_attachment_is_image( $post ) ) {
			return Site_Support::error(
				'mosmcp_image_not_found',
				__( 'There is no image with that ID in the media library. Find images with mosmcp/media-list-images.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_NOT_FOUND,
				false,
				array( 'use_instead' => 'mosmcp/media-list-images' )
			);
		}
		if ( ! wp_image_editor_supports( array( 'mime_type' => $post->post_mime_type ) ) ) {
			$library = wp_image_editor_supports( array( 'mime_type' => 'image/png' ) ) || wp_image_editor_supports( array( 'mime_type' => 'image/jpeg' ) );
			return Site_Support::error(
				'mosmcp_image_not_editable',
				$library
					? sprintf(
						/* translators: %s: MIME type. */
						__( 'This server cannot edit %s images. JPEG, PNG and WebP images can be edited.', 'mosmcp-abilities' ),
						(string) $post->post_mime_type
					)
					: __( 'This server has no image library (GD or Imagick), so WordPress cannot crop, rotate or resize images here. The host can enable GD or Imagick.', 'mosmcp-abilities' ),
				$library ? Site_Support::CAUSE_INVALID_INPUT : Site_Support::CAUSE_HOST_ENVIRONMENT,
				false
			);
		}
		return $post;
	}

	/**
	 * Turns the ability's input into core image-edit history plus a final size,
	 * for an image of the given size. Order: rotate, flip, crop, resize.
	 *
	 * @param array<string, mixed> $input  Input.
	 * @param int                  $width  Image width.
	 * @param int                  $height Image height.
	 * @return array{history:array<int, object>,resize:int[]|null,steps:string[],width:int,height:int}|WP_Error
	 */
	public static function plan( array $input, $width, $height ) {
		$history = array();
		$steps   = array();
		$width   = (int) $width;
		$height  = (int) $height;

		if ( isset( $input['rotate'] ) && 0 !== ( (int) $input['rotate'] % 360 ) ) {
			$degrees = ( ( (int) $input['rotate'] % 360 ) + 360 ) % 360;
			if ( ! in_array( $degrees, array( 90, 180, 270 ), true ) ) {
				return self::input_error( __( 'rotate must be 90, 180 or 270 (degrees clockwise).', 'mosmcp-abilities' ) );
			}
			// Core rotates counter-clockwise for a positive angle.
			$history[] = (object) array( 'r' => -$degrees );
			if ( 180 !== $degrees ) {
				list( $width, $height ) = array( $height, $width );
			}
			/* translators: %d: degrees. */
			$steps[] = sprintf( __( 'rotated %d° clockwise', 'mosmcp-abilities' ), $degrees );
		}

		if ( ! empty( $input['flip'] ) ) {
			$axes = array(
				'vertical'   => 1,
				'horizontal' => 2,
				'both'       => 3,
			);
			if ( ! isset( $axes[ $input['flip'] ] ) ) {
				return self::input_error( __( 'flip must be "horizontal" (mirror left to right), "vertical" (upside down) or "both".', 'mosmcp-abilities' ) );
			}
			$history[] = (object) array( 'f' => $axes[ $input['flip'] ] );
			/* translators: %s: horizontal, vertical or both. */
			$steps[] = sprintf( __( 'flipped %s', 'mosmcp-abilities' ), (string) $input['flip'] );
		}

		$box   = null;
		$label = '';
		if ( ! empty( $input['aspect_ratio'] ) ) {
			$ratio = Image_Geometry::ratio( (string) $input['aspect_ratio'] );
			if ( $ratio <= 0 ) {
				return self::input_error( __( 'aspect_ratio must look like "16:9", "4:3", "1:1" or "square".', 'mosmcp-abilities' ) );
			}
			$focus = isset( $input['focus'] ) && isset( Image_Geometry::FOCUS[ $input['focus'] ] ) ? (string) $input['focus'] : 'center';
			$box   = Image_Geometry::box( $width, $height, $ratio, $focus );
			/* translators: 1: aspect ratio, 2: focus position. */
			$label = sprintf( __( 'cropped to %1$s keeping the %2$s', 'mosmcp-abilities' ), (string) $input['aspect_ratio'], str_replace( '-', ' ', $focus ) );
		} elseif ( ! empty( $input['crop_box'] ) && is_array( $input['crop_box'] ) ) {
			$given = $input['crop_box'];
			$box   = array(
				'x'      => isset( $given['x'] ) ? (int) $given['x'] : 0,
				'y'      => isset( $given['y'] ) ? (int) $given['y'] : 0,
				'width'  => isset( $given['width'] ) ? (int) $given['width'] : 0,
				'height' => isset( $given['height'] ) ? (int) $given['height'] : 0,
			);
			if ( $box['x'] < 0 || $box['y'] < 0 || $box['width'] < 1 || $box['height'] < 1 || $box['x'] + $box['width'] > $width || $box['y'] + $box['height'] > $height ) {
				return self::input_error(
					sprintf(
						/* translators: 1: width, 2: height. */
						__( 'crop_box must fit inside the image, which is %1$d × %2$d pixels at that point (after any rotation).', 'mosmcp-abilities' ),
						$width,
						$height
					)
				);
			}
			/* translators: 1: width, 2: height. */
			$label = sprintf( __( 'cropped to a %1$d × %2$d box', 'mosmcp-abilities' ), $box['width'], $box['height'] );
		}
		if ( null !== $box ) {
			if ( $box['width'] < $width || $box['height'] < $height ) {
				$steps[]   = $label;
				$history[] = (object) array(
					'c' => (object) array(
						'x' => $box['x'],
						'y' => $box['y'],
						'w' => $box['width'],
						'h' => $box['height'],
						// Full-size pixels, not the editor's preview scale.
						'r' => 1,
					),
				);
			}
			$width  = $box['width'];
			$height = $box['height'];
		}

		$resize = null;
		$max_w  = isset( $input['width'] ) ? (int) $input['width'] : 0;
		$max_h  = isset( $input['height'] ) ? (int) $input['height'] : 0;
		if ( $max_w > 0 || $max_h > 0 ) {
			$scale = min( $max_w > 0 ? $max_w / $width : INF, $max_h > 0 ? $max_h / $height : INF );
			if ( $scale < 1 ) {
				$resize = array( max( 1, (int) round( $width * $scale ) ), max( 1, (int) round( $height * $scale ) ) );
				$width  = $resize[0];
				$height = $resize[1];
				/* translators: 1: width, 2: height. */
				$steps[] = sprintf( __( 'resized to %1$d × %2$d', 'mosmcp-abilities' ), $width, $height );
			}
		}

		if ( empty( $history ) && null === $resize ) {
			return self::input_error( __( 'Nothing to change: give rotate, flip, aspect_ratio or crop_box, or a width or height smaller than the image (images are never enlarged).', 'mosmcp-abilities' ) );
		}

		return array(
			'history' => $history,
			'resize'  => $resize,
			'steps'   => $steps,
			'width'   => $width,
			'height'  => $height,
		);
	}

	/**
	 * Saves the edited image as a new media item, leaving the original untouched.
	 *
	 * @param WP_Post              $source Source image.
	 * @param array<string, mixed> $plan   From plan().
	 * @param string               $title  Title for the copy, or empty.
	 * @return int|WP_Error New attachment ID.
	 */
	public static function to_copy( WP_Post $source, array $plan, $title ) {
		self::load();
		$file   = get_attached_file( $source->ID );
		$editor = wp_get_image_editor( $file );
		if ( is_wp_error( $editor ) ) {
			return self::editor_error( $editor );
		}
		if ( ! empty( $plan['history'] ) ) {
			$editor = image_edit_apply_changes( $editor, $plan['history'] );
		}
		if ( null !== $plan['resize'] ) {
			$resized = $editor->resize( $plan['resize'][0], $plan['resize'][1], false );
			if ( is_wp_error( $resized ) ) {
				return self::editor_error( $resized );
			}
		}

		$dir   = dirname( $file );
		$name  = (string) preg_replace( '/-(scaled|e\d+)$/', '', pathinfo( $file, PATHINFO_FILENAME ) );
		$ext   = pathinfo( $file, PATHINFO_EXTENSION );
		$saved = $editor->save( $dir . '/' . wp_unique_filename( $dir, $name . '-edited.' . $ext ) );
		if ( is_wp_error( $saved ) ) {
			return self::editor_error( $saved );
		}

		$id = wp_insert_attachment(
			wp_slash(
				array(
					'post_mime_type' => (string) $saved['mime-type'],
					'post_title'     => '' !== trim( (string) $title ) ? sanitize_text_field( (string) $title ) : sprintf(
						/* translators: %s: original title. */
						__( '%s (edited)', 'mosmcp-abilities' ),
						get_the_title( $source )
					),
					'post_content'   => (string) $source->post_content,
					'post_excerpt'   => (string) $source->post_excerpt,
					'post_status'    => 'inherit',
				)
			),
			$saved['path'],
			(int) $source->post_parent,
			true
		);
		if ( is_wp_error( $id ) ) {
			wp_delete_file( $saved['path'] );
			return $id;
		}

		wp_update_attachment_metadata( (int) $id, wp_generate_attachment_metadata( (int) $id, $saved['path'] ) );
		$alt = (string) get_post_meta( $source->ID, '_wp_attachment_image_alt', true );
		if ( '' !== $alt ) {
			update_post_meta( (int) $id, '_wp_attachment_image_alt', wp_slash( $alt ) );
		}

		return (int) $id;
	}

	/**
	 * Edits the image itself through core's wp_save_image(), which keeps the
	 * original file and records it for "Restore original image".
	 *
	 * @param WP_Post              $source Image.
	 * @param array<string, mixed> $plan   From plan().
	 * @return true|WP_Error
	 */
	public static function in_place( WP_Post $source, array $plan ) {
		self::load();

		// wp_save_image() reads its instructions from the request, as the media editor sends them.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Saved and restored around the call; never read as input.
		$saved_request = $_REQUEST;
		$runs          = array();
		if ( ! empty( $plan['history'] ) ) {
			$runs[] = array(
				'do'      => 'save',
				'target'  => 'all',
				'history' => wp_slash( (string) wp_json_encode( $plan['history'] ) ),
			);
		}
		if ( null !== $plan['resize'] ) {
			$runs[] = array(
				'do'      => 'scale',
				'target'  => 'all',
				'fwidth'  => (string) $plan['resize'][0],
				'fheight' => (string) $plan['resize'][1],
			);
		}

		$error = null;
		foreach ( $runs as $run ) {
			$_REQUEST = array_merge( $saved_request, $run );
			$result   = wp_save_image( $source->ID );
			if ( ! empty( $result->error ) ) {
				$error = Site_Support::error(
					'mosmcp_image_edit_failed',
					/* translators: %s: WordPress's message. */
					sprintf( __( 'WordPress could not save the edited image: %s', 'mosmcp-abilities' ), wp_strip_all_tags( (string) $result->error ) ),
					Site_Support::CAUSE_WP_CORE,
					false
				);
				break;
			}
		}
		$_REQUEST = $saved_request;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return null === $error ? true : $error;
	}

	/**
	 * Whether an image was edited in place and its original can be restored.
	 *
	 * WordPress keeps the record of the original after restoring it, so the
	 * current file is compared with it too.
	 *
	 * @param int $id Attachment ID.
	 * @return bool
	 */
	public static function has_original( $id ) {
		$backup = get_post_meta( (int) $id, '_wp_attachment_backup_sizes', true );
		return is_array( $backup ) && ! empty( $backup['full-orig']['file'] )
			&& wp_basename( (string) get_attached_file( (int) $id ) ) !== (string) $backup['full-orig']['file'];
	}

	/**
	 * Refusal for input that cannot be applied.
	 *
	 * @param string $message Message.
	 * @return WP_Error
	 */
	private static function input_error( $message ) {
		return Site_Support::error( 'mosmcp_image_edit_invalid', $message, Site_Support::CAUSE_INVALID_INPUT, false );
	}

	/**
	 * Refusal for an image editor failure.
	 *
	 * @param WP_Error $error Editor error.
	 * @return WP_Error
	 */
	private static function editor_error( WP_Error $error ) {
		return Site_Support::error(
			'mosmcp_image_edit_failed',
			/* translators: %s: the image editor's message. */
			sprintf( __( 'The image could not be edited on this server: %s', 'mosmcp-abilities' ), $error->get_error_message() ),
			Site_Support::CAUSE_HOST_ENVIRONMENT,
			false
		);
	}
}
