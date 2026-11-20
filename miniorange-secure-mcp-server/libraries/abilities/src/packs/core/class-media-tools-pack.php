<?php
/**
 * Media tools ability pack: edit images, put an original back, make missing
 * image sizes, and let the person upload a file their AI asked for.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Core;

use MoSMCP\Abilities\Ability;
use MoSMCP\Abilities\Ability_Pack;
use MoSMCP\Abilities\Packs\Core\Support\Core_Schema as Schema;
use MoSMCP\Abilities\Packs\Site\Site_Support;
use MoSMCP\Abilities\Support\Image_Geometry;

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
 * Class Media_Tools_Pack
 *
 * Shares the Media category with Media_Pack.
 */
class Media_Tools_Pack extends Ability_Pack {

	/**
	 * Ability category: the media library's.
	 *
	 * @return array Category definition.
	 */
	public function category() {
		return ( new Media_Pack() )->category();
	}

	/**
	 * The abilities.
	 *
	 * @return Ability[]
	 */
	public function abilities() {
		return array(
			$this->edit_image(),
			$this->restore_original(),
			$this->regenerate_sizes(),
			$this->upload_link_create(),
			$this->upload_link_status(),
		);
	}

	/**
	 * Defines mosmcp/media-edit-image.
	 *
	 * @return Ability
	 */
	private function edit_image() {
		return new Ability(
			'mosmcp/media-edit-image',
			array(
				'label'         => __( 'Edit Image', 'mosmcp-abilities' ),
				'description'   => __( 'Crops, rotates, flips or resizes an image, the way Media > Edit Image does. Crop by shape — aspect_ratio such as "16:9" or "1:1" plus focus, the part to keep ("top", "left", "center" and so on), which needs no pixel coordinates — or by an exact crop_box in pixels. The result is saved as a new media item and the original is untouched, unless in_place is set: then the image itself changes, its original is kept, and mosmcp/media-restore-original puts it back. Order: rotate, flip, crop, then resize; images are never enlarged. Also known as: crop photo, rotate picture, resize image, make square, banner crop.', 'mosmcp-abilities' ),
				'category'      => Media_Pack::CATEGORY,
				'capability'    => 'edit_post',
				'cap_args'      => self::id_args(),
				'annotations'   => Site_Support::annotations( false, false, false, false ),
				'execute'       => array( Media_Tools_Provider::class, 'edit_image' ),
				'input_schema'  => Schema::object(
					array(
						'id'           => Schema::int( __( 'The image to edit.', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
						'aspect_ratio' => Schema::str( __( 'Crop to this shape: "16:9", "4:3", "3:2", "1:1", "9:16" or "square". The largest such box is kept.', 'mosmcp-abilities' ) ),
						'focus'        => Schema::str(
							__( 'With aspect_ratio: which part of the image to keep.', 'mosmcp-abilities' ),
							array(
								'enum'    => array_keys( Image_Geometry::FOCUS ),
								'default' => 'center',
							)
						),
						'crop_box'     => Schema::object(
							array(
								'x'      => Schema::int( '', array( 'minimum' => 0 ) ),
								'y'      => Schema::int( '', array( 'minimum' => 0 ) ),
								'width'  => Schema::int( '', array( 'minimum' => 1 ) ),
								'height' => Schema::int( '', array( 'minimum' => 1 ) ),
							),
							array( 'x', 'y', 'width', 'height' )
						),
						'rotate'       => Schema::int(
							__( 'Degrees clockwise: 90, 180 or 270.', 'mosmcp-abilities' ),
							array( 'enum' => array( 90, 180, 270 ) )
						),
						'flip'         => Schema::str(
							__( '"horizontal" mirrors left to right, "vertical" turns upside down.', 'mosmcp-abilities' ),
							array( 'enum' => array( 'horizontal', 'vertical', 'both' ) )
						),
						'width'        => Schema::int( __( 'Resize to at most this width, keeping the proportions.', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
						'height'       => Schema::int( __( 'Resize to at most this height, keeping the proportions.', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
						'in_place'     => Schema::boolean( __( 'Change the image itself instead of saving a new media item. Needs confirm.', 'mosmcp-abilities' ) ),
						'confirm'      => Schema::boolean( __( 'With in_place: true once the person has agreed.', 'mosmcp-abilities' ) ),
						'title'        => Schema::str( __( 'Title for the new media item. Defaults to the original\'s, marked "(edited)".', 'mosmcp-abilities' ) ),
					),
					array( 'id' )
				),
				'output_schema' => Schema::object(
					array_merge(
						self::image_props(),
						array(
							'copy'  => Schema::boolean( __( 'True when the result is a new media item.', 'mosmcp-abilities' ) ),
							'from'  => Schema::int( __( 'The image it was made from.', 'mosmcp-abilities' ) ),
							'steps' => Schema::arr( Schema::str() ),
							'notes' => Schema::arr( Schema::str() ),
						)
					),
					array( 'id', 'url' )
				),
			)
		);
	}

	/**
	 * Defines mosmcp/media-restore-original.
	 *
	 * @return Ability
	 */
	private function restore_original() {
		return new Ability(
			'mosmcp/media-restore-original',
			array(
				'label'         => __( 'Restore Original Image', 'mosmcp-abilities' ),
				'description'   => __( 'Puts back the original of an image that was edited in place, by mosmcp/media-edit-image with in_place or in Media > Edit Image — the same as WordPress\'s "Restore original image". Needs confirm. Also known as: undo image edit, revert crop.', 'mosmcp-abilities' ),
				'category'      => Media_Pack::CATEGORY,
				'capability'    => 'edit_post',
				'cap_args'      => self::id_args(),
				'annotations'   => Site_Support::annotations( false, false, true, false ),
				'execute'       => array( Media_Tools_Provider::class, 'restore_original' ),
				'input_schema'  => Schema::object(
					array(
						'id'      => Schema::int( __( 'The image.', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
						'confirm' => Schema::boolean( __( 'True once the person has agreed.', 'mosmcp-abilities' ) ),
					),
					array( 'id' )
				),
				'output_schema' => Schema::object(
					array_merge(
						self::image_props(),
						array( 'notes' => Schema::arr( Schema::str() ) )
					),
					array( 'id', 'url' )
				),
			)
		);
	}

	/**
	 * Defines mosmcp/media-regenerate-sizes.
	 *
	 * @return Ability
	 */
	private function regenerate_sizes() {
		return new Ability(
			'mosmcp/media-regenerate-sizes',
			array(
				'label'         => __( 'Regenerate Image Sizes', 'mosmcp-abilities' ),
				'description'   => __( 'Makes the image sizes WordPress and the theme use (thumbnails, medium, large and any the theme adds) for images that are missing them — after a theme change adds new sizes, for example. Works through the media library a batch at a time: call again with next_cursor until done is true. Only missing sizes are made unless force is set. Also known as: regenerate thumbnails, rebuild image sizes.', 'mosmcp-abilities' ),
				'category'      => Media_Pack::CATEGORY,
				'capability'    => 'upload_files',
				'annotations'   => Site_Support::annotations( false, false, true, false ),
				'execute'       => array( Media_Tools_Provider::class, 'regenerate_sizes' ),
				'input_schema'  => Schema::object(
					array(
						'ids'    => Schema::arr( Schema::int(), __( 'Only these images; all images when left out.', 'mosmcp-abilities' ) ),
						'force'  => Schema::boolean( __( 'Remake every size, not only missing ones.', 'mosmcp-abilities' ) ),
						'batch'  => Schema::int(
							__( 'Images per call.', 'mosmcp-abilities' ),
							array(
								'minimum' => 1,
								'maximum' => 50,
								'default' => 10,
							)
						),
						'cursor' => Schema::int( __( 'next_cursor from the previous call; 0 to start.', 'mosmcp-abilities' ), array( 'minimum' => 0 ) ),
					)
				),
				'output_schema' => Schema::object(
					array(
						'processed'   => Schema::arr(
							Schema::object(
								array(
									'id'      => Schema::int(),
									'title'   => Schema::str(),
									'created' => Schema::arr( Schema::str(), __( 'Sizes made.', 'mosmcp-abilities' ) ),
									'error'   => Schema::str(),
								)
							)
						),
						'next_cursor' => Schema::int(),
						'remaining'   => Schema::int(),
						'done'        => Schema::boolean(),
						'notes'       => Schema::arr( Schema::str() ),
					),
					array( 'processed', 'done' )
				),
			)
		);
	}

	/**
	 * Defines mosmcp/media-upload-link-create.
	 *
	 * @return Ability
	 */
	private function upload_link_create() {
		return new Ability(
			'mosmcp/media-upload-link-create',
			array(
				'label'         => __( 'Create Upload Link', 'mosmcp-abilities' ),
				'description'   => __( 'Makes a link the person opens to upload a file you need — a logo, a photo, a PDF — straight into this site\'s media library. A file attached to the chat cannot reach the site, so this is how to get one. The page is in wp-admin behind their normal sign-in, works only for this account, and takes only what you allow. Then call mosmcp/media-upload-link-status for the new media IDs. Also known as: send me the file, upload from my computer, attach an image.', 'mosmcp-abilities' ),
				'category'      => Media_Pack::CATEGORY,
				'capability'    => 'upload_files',
				'annotations'   => Site_Support::annotations( false, false, false, false ),
				'execute'       => array( Media_Tools_Provider::class, 'upload_link_create' ),
				'input_schema'  => Schema::object(
					array(
						'purpose'          => Schema::str( __( 'What to upload, shown on the page, for example "Your logo (PNG or SVG)".', 'mosmcp-abilities' ) ),
						'accept'           => Schema::str(
							__( '"image" for images only, or "any" for any file this site accepts.', 'mosmcp-abilities' ),
							array(
								'enum'    => array( 'image', 'any' ),
								'default' => 'image',
							)
						),
						'max_files'        => Schema::int(
							'',
							array(
								'minimum' => 1,
								'maximum' => 20,
								'default' => 1,
							)
						),
						'expires_in_hours' => Schema::int(
							'',
							array(
								'minimum' => 1,
								'maximum' => 72,
								'default' => 24,
							)
						),
					)
				),
				'output_schema' => Schema::object(
					array(
						'request_id' => Schema::str( __( 'Pass to mosmcp/media-upload-link-status.', 'mosmcp-abilities' ) ),
						'url'        => Schema::str( __( 'The link to give the person.', 'mosmcp-abilities' ) ),
						'expires'    => Schema::str(),
						'accept'     => Schema::str(),
						'max_files'  => Schema::int(),
						'notes'      => Schema::arr( Schema::str() ),
					),
					array( 'request_id', 'url' )
				),
			)
		);
	}

	/**
	 * Defines mosmcp/media-upload-link-status.
	 *
	 * @return Ability
	 */
	private function upload_link_status() {
		return new Ability(
			'mosmcp/media-upload-link-status',
			array(
				'label'         => __( 'Check Upload Link', 'mosmcp-abilities' ),
				'description'   => __( 'Says whether the person has uploaded through a link from mosmcp/media-upload-link-create, and returns each new media item\'s ID, address and size. Read-only.', 'mosmcp-abilities' ),
				'category'      => Media_Pack::CATEGORY,
				'capability'    => 'upload_files',
				'annotations'   => Site_Support::annotations( true, false, true, false ),
				'execute'       => array( Media_Tools_Provider::class, 'upload_link_status' ),
				'input_schema'  => Schema::object(
					array(
						'request_id' => Schema::str( __( 'From mosmcp/media-upload-link-create.', 'mosmcp-abilities' ) ),
					),
					array( 'request_id' )
				),
				'output_schema' => Schema::object(
					array(
						'request_id' => Schema::str(),
						'status'     => Schema::str( __( '"waiting", "uploaded" or "expired".', 'mosmcp-abilities' ) ),
						'purpose'    => Schema::str(),
						'expires'    => Schema::str(),
						'files'      => Schema::arr(
							Schema::object(
								array(
									'id'        => Schema::int(),
									'title'     => Schema::str(),
									'url'       => Schema::str(),
									'filename'  => Schema::str(),
									'mime_type' => Schema::str(),
									'width'     => Schema::int(),
									'height'    => Schema::int(),
								)
							)
						),
						'notes'      => Schema::arr( Schema::str() ),
					),
					array( 'request_id', 'status' )
				),
			)
		);
	}

	/**
	 * Output properties describing an image.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function image_props() {
		return array(
			'id'       => Schema::int(),
			'title'    => Schema::str(),
			'url'      => Schema::str(),
			'filename' => Schema::str(),
			'width'    => Schema::int(),
			'height'   => Schema::int(),
			'edit_url' => Schema::str(),
		);
	}

	/**
	 * Resolves the media ID from input for object-level capability checks.
	 *
	 * @return callable
	 */
	private static function id_args() {
		return static function ( $input ) {
			return array( isset( $input['id'] ) ? absint( $input['id'] ) : 0 );
		};
	}
}
