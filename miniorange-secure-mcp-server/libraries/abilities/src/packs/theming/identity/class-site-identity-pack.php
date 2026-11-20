<?php
/**
 * Site logo and site icon ability pack: see them, and stage new ones in the
 * theme draft.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Theming\Identity;

use MoSMCP\Abilities\Ability;
use MoSMCP\Abilities\Ability_Pack;
use MoSMCP\Abilities\Packs\Core\Support\Core_Schema as Schema;
use MoSMCP\Abilities\Packs\Site\Site_Support;
use MoSMCP\Abilities\Packs\Theming\Blocktheme\Block_Theme_Pack;

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
 * Class Site_Identity_Pack
 */
class Site_Identity_Pack extends Ability_Pack {

	/**
	 * Ability category slug.
	 */
	const CATEGORY = 'mosmcp-site-identity';

	/**
	 * Ability category.
	 *
	 * @return array Category definition.
	 */
	public function category() {
		return array(
			'slug'        => self::CATEGORY,
			'label'       => __( 'Theme: Logo and Site Icon', 'mosmcp-abilities' ),
			'description' => __( 'See and change the site logo and the site icon (favicon). Every change is staged in the theme draft, can be previewed, and goes live only when the draft is published.', 'mosmcp-abilities' ),
		);
	}

	/**
	 * The abilities.
	 *
	 * @return Ability[]
	 */
	public function abilities() {
		return array(
			$this->get_branding(),
			$this->set_logo(),
			$this->set_icon(),
		);
	}

	/**
	 * Defines mosmcp/site-get-branding.
	 *
	 * @return Ability
	 */
	private function get_branding() {
		return $this->ability(
			'mosmcp/site-get-branding',
			__( 'Get Site Logo and Icon', 'mosmcp-abilities' ),
			__( 'Shows the site title, tagline, logo and site icon (favicon) as visitors see them now and as they will be once the theme draft is published, with each image\'s ID, address and size. Also says whether the active theme displays a logo at all and where, so a logo is never set somewhere it cannot appear. Read-only. Also known as: branding, brand assets, site identity, favicon.', 'mosmcp-abilities' ),
			array( Site_Identity_Provider::class, 'get_branding' ),
			'edit_theme_options',
			Site_Support::annotations( true, false, true, false ),
			Schema::object( array() ),
			Schema::object(
				array(
					'title'   => Schema::str(),
					'tagline' => Schema::str(),
					'logo'    => Schema::object(
						array(
							'live'      => self::image_schema( __( 'The logo visitors see now; id 0 when there is none.', 'mosmcp-abilities' ) ),
							'pending'   => self::pending_schema(),
							'draft'     => self::image_schema( __( 'The logo once the theme draft is published.', 'mosmcp-abilities' ) ),
							'shown'     => Schema::boolean( __( 'Whether the theme displays a logo, once the draft is published.', 'mosmcp-abilities' ) ),
							'shown_in'  => Schema::arr( Schema::str(), __( 'Where it appears.', 'mosmcp-abilities' ) ),
							'size_hint' => Schema::str(),
						)
					),
					'icon'    => Schema::object(
						array(
							'live'    => self::image_schema( __( 'The site icon visitors see now; id 0 when there is none.', 'mosmcp-abilities' ) ),
							'pending' => self::pending_schema(),
							'draft'   => self::image_schema( __( 'The site icon once the theme draft is published.', 'mosmcp-abilities' ) ),
						)
					),
					'notes'   => Schema::arr( Schema::str() ),
				),
				array( 'logo', 'icon' )
			)
		);
	}

	/**
	 * Defines mosmcp/site-set-logo.
	 *
	 * @return Ability
	 */
	private function set_logo() {
		return $this->ability(
			'mosmcp/site-set-logo',
			__( 'Set Site Logo', 'mosmcp-abilities' ),
			__( 'Stages a new site logo in the theme draft, from an image already in the media library (attachment_id) or from a public image address (url), which is added to the media library first. Or stages removing the logo. Visitors keep seeing the current logo until the draft is published, and it can be previewed first with mosmcp/theme-draft-preview. Says whether the active theme shows a logo and where; when a block theme has no place for one, explains how to add a Site Logo block to its header in the same draft. Also known as: change logo, upload logo, brand logo, header logo, custom logo.', 'mosmcp-abilities' ),
			array( Site_Identity_Provider::class, 'set_logo' ),
			'edit_theme_options',
			Site_Support::annotations( false, false, false, true ),
			Schema::object( self::image_input() ),
			Block_Theme_Pack::staged_output(
				array(
					'logo'     => self::image_schema( __( 'The staged logo; id 0 when the logo is being removed.', 'mosmcp-abilities' ) ),
					'shown'    => Schema::boolean( __( 'Whether the theme will display it.', 'mosmcp-abilities' ) ),
					'shown_in' => Schema::arr( Schema::str() ),
				)
			)
		);
	}

	/**
	 * Defines mosmcp/site-set-icon.
	 *
	 * @return Ability
	 */
	private function set_icon() {
		return $this->ability(
			'mosmcp/site-set-icon',
			__( 'Set Site Icon', 'mosmcp-abilities' ),
			__( 'Stages a new site icon, the small image shown in browser tabs and bookmarks and used by phones for home-screen shortcuts, from an image already in the media library (attachment_id) or a public image address (url). WordPress needs a square image at least 512 pixels wide, so the image is copied as a 512 × 512 square, keeping the part named by focus when it is not square (the centre unless told otherwise). Or stages removing the icon. Visitors keep the current icon until the theme draft is published. Also known as: favicon, browser tab icon, app icon, apple touch icon, bookmark icon.', 'mosmcp-abilities' ),
			array( Site_Identity_Provider::class, 'set_icon' ),
			// The capability core requires for the site icon in the Customizer.
			'manage_options',
			Site_Support::annotations( false, false, false, true ),
			Schema::object(
				array_merge(
					self::image_input(),
					array(
						'focus' => Schema::str(
							__( 'Which part of a non-square image to keep in the square icon.', 'mosmcp-abilities' ),
							array(
								'enum'    => array_keys( Site_Identity::FOCUS ),
								'default' => 'center',
							)
						),
					)
				)
			),
			Block_Theme_Pack::staged_output(
				array(
					'icon'      => self::image_schema( __( 'The staged icon: the square copy, or id 0 when the icon is being removed.', 'mosmcp-abilities' ) ),
					'source_id' => Schema::int( __( 'The image the icon was made from.', 'mosmcp-abilities' ) ),
					'cropped'   => Schema::boolean( __( 'Whether a square copy was made.', 'mosmcp-abilities' ) ),
				)
			)
		);
	}

	/**
	 * Input naming an image, or removal.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function image_input() {
		return array(
			'attachment_id' => Schema::int( __( 'An image already in the media library, for example from mosmcp/media-list-images.', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
			'url'           => Schema::str( __( 'Instead of attachment_id: a public http or https address of an image, added to the media library first.', 'mosmcp-abilities' ) ),
			'remove'        => Schema::boolean( __( 'True to stage removing it instead, with no attachment_id or url.', 'mosmcp-abilities' ) ),
		);
	}

	/**
	 * Schema of an image.
	 *
	 * @param string $description Description.
	 * @return array<string, mixed>
	 */
	private static function image_schema( $description = '' ) {
		$schema = Schema::object(
			array(
				'id'        => Schema::int(),
				'url'       => Schema::str(),
				'width'     => Schema::int(),
				'height'    => Schema::int(),
				'mime_type' => Schema::str(),
				'filename'  => Schema::str(),
				'alt'       => Schema::str(),
			)
		);
		if ( '' !== $description ) {
			$schema['description'] = $description;
		}
		return $schema;
	}

	/**
	 * Schema of a pending change.
	 *
	 * @return array<string, mixed>
	 */
	private static function pending_schema() {
		return Schema::str( __( '"set" or "remove" when the theme draft changes it, empty otherwise.', 'mosmcp-abilities' ) );
	}

	/**
	 * Builds an ability with the pack's shared settings.
	 *
	 * @param string               $name        Name.
	 * @param string               $label       Label.
	 * @param string               $description Description.
	 * @param callable             $execute     Execute callback.
	 * @param string               $capability  Capability required.
	 * @param array<string, bool>  $annotations Annotations.
	 * @param array<string, mixed> $input       Input schema.
	 * @param array<string, mixed> $output      Output schema.
	 * @return Ability
	 */
	private function ability( $name, $label, $description, $execute, $capability, array $annotations, array $input, array $output ) {
		return new Ability(
			$name,
			array(
				'label'         => $label,
				'description'   => $description,
				'category'      => self::CATEGORY,
				'capability'    => $capability,
				'annotations'   => $annotations,
				'execute'       => $execute,
				'input_schema'  => $input,
				'output_schema' => $output,
			)
		);
	}
}
