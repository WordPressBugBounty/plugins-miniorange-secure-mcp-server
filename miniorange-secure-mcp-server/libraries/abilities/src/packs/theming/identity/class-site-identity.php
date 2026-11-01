<?php
/**
 * The site logo and site icon: reading them, staging them in the theme draft and
 * applying them the way WordPress itself does.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Theming\Identity;

use MoSMCP\Abilities\Packs\Core\Support\Media_Uploader;
use MoSMCP\Abilities\Packs\Site\Site_Support;
use MoSMCP\Abilities\Support\Image_Geometry;
use WP_Error;
use WP_Post;
use MoSMCP\Abilities\Packs\Theming\Blocktheme\Block_Templates;
use MoSMCP\Abilities\Packs\Theming\Theme_Support;

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
 * Class Site_Identity
 *
 * Two values, each an attachment ID (0 for none):
 *
 *  - logo: the custom_logo theme mod. Core mirrors it into the site_logo option,
 *    which the Site Logo block reads and which then wins for every theme, so it
 *    is always set through set_theme_mod() and never written directly.
 *  - icon: the site_icon option. WordPress expects a square image of at least
 *    512 pixels with its own set of small sizes, so a chosen image is copied and
 *    cropped exactly as the Customizer's cropper does.
 *
 * Staged values live in the theme draft under "identity", so they are previewed,
 * checked, published, backed up and rolled back with everything else.
 */
class Site_Identity {

	/**
	 * What can be changed.
	 *
	 * @var string[]
	 */
	const KINDS = array( 'logo', 'icon' );

	/**
	 * Smallest side, in pixels, a site icon may have, and the square it is cut to.
	 */
	const ICON_SIZE = 512;

	/**
	 * Named focus points: which part of an image to keep when it is cropped.
	 *
	 * @var array<string, float[]>
	 */
	const FOCUS = Image_Geometry::FOCUS;

	/*
	 * Values
	 */

	/**
	 * The live value.
	 *
	 * @param string $kind "logo" or "icon".
	 * @return int Attachment ID, or 0.
	 */
	public static function live( $kind ) {
		return 'logo' === $kind ? absint( get_theme_mod( 'custom_logo', 0 ) ) : absint( get_option( 'site_icon', 0 ) );
	}

	/**
	 * The pending change for a kind, or null.
	 *
	 * @param array<string, mixed>|null $draft Draft.
	 * @param string                    $kind  Kind.
	 * @return array<string, mixed>|null
	 */
	public static function entry( $draft, $kind ) {
		return is_array( $draft ) && isset( $draft['identity'][ $kind ] ) ? $draft['identity'][ $kind ] : null;
	}

	/**
	 * The value once the draft is published.
	 *
	 * @param array<string, mixed>|null $draft Draft.
	 * @param string                    $kind  Kind.
	 * @return int
	 */
	public static function effective( $draft, $kind ) {
		$entry = self::entry( $draft, $kind );
		return null === $entry ? self::live( $kind ) : (int) $entry['value'];
	}

	/**
	 * Makes a value live.
	 *
	 * @param string $kind Kind.
	 * @param int    $id   Attachment ID, or 0 to remove.
	 * @return void
	 */
	public static function apply( $kind, $id ) {
		$id = absint( $id );

		if ( 'logo' === $kind ) {
			if ( $id > 0 ) {
				// Core's pre_set_theme_mod_custom_logo filter mirrors this into site_logo.
				set_theme_mod( 'custom_logo', $id );
				return;
			}
			// Deleting site_logo also removes the theme mod (core hooks it), and the
			// theme mod is removed too in case only it was set.
			delete_option( 'site_logo' );
			remove_theme_mod( 'custom_logo' );
			return;
		}

		if ( $id > 0 ) {
			update_option( 'site_icon', $id );
		} else {
			delete_option( 'site_icon' );
		}
	}

	/*
	 * Images
	 */

	/**
	 * An image attachment for output; id 0 when there is none.
	 *
	 * @param int $id Attachment ID.
	 * @return array<string, mixed>
	 */
	public static function describe( $id ) {
		$id   = absint( $id );
		$post = $id > 0 ? get_post( $id ) : null;
		if ( ! $post instanceof WP_Post || 'attachment' !== $post->post_type ) {
			return array(
				'id'        => 0,
				'url'       => '',
				'width'     => 0,
				'height'    => 0,
				'mime_type' => '',
				'filename'  => '',
				'alt'       => '',
			);
		}

		$meta = wp_get_attachment_metadata( $id );

		return array(
			'id'        => $id,
			'url'       => (string) wp_get_attachment_url( $id ),
			'width'     => is_array( $meta ) && isset( $meta['width'] ) ? (int) $meta['width'] : 0,
			'height'    => is_array( $meta ) && isset( $meta['height'] ) ? (int) $meta['height'] : 0,
			'mime_type' => (string) $post->post_mime_type,
			'filename'  => wp_basename( (string) get_attached_file( $id ) ),
			'alt'       => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
		);
	}

	/**
	 * How a pending change reads in the draft summary.
	 *
	 * @param string $kind Kind.
	 * @param int    $id   Attachment ID, or 0 for removal.
	 * @return string
	 */
	public static function label( $kind, $id ) {
		$noun = 'logo' === $kind ? __( 'logo', 'mosmcp-abilities' ) : __( 'site icon', 'mosmcp-abilities' );
		if ( $id < 1 ) {
			/* translators: %s: "logo" or "site icon". */
			return sprintf( __( '%s (remove)', 'mosmcp-abilities' ), $noun );
		}
		$image = self::describe( $id );
		/* translators: 1: "logo" or "site icon", 2: file name, 3: width, 4: height. */
		return sprintf( __( '%1$s (set to "%2$s", %3$d × %4$d)', 'mosmcp-abilities' ), $noun, $image['filename'], $image['width'], $image['height'] );
	}

	/**
	 * The image an ability names: a media library item, or a public URL that is
	 * added to the library first.
	 *
	 * @param array<string, mixed> $input Input with attachment_id or url.
	 * @return array{id:int,created:int[]}|WP_Error
	 */
	public static function source( array $input ) {
		$id  = isset( $input['attachment_id'] ) ? absint( $input['attachment_id'] ) : 0;
		$url = isset( $input['url'] ) ? trim( (string) $input['url'] ) : '';

		if ( ( $id > 0 ) === ( '' !== $url ) ) {
			return Site_Support::error(
				'mosmcp_image_source_required',
				__( 'Give exactly one of attachment_id (an image already in the media library) or url (a public image address to add), or set remove to true.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_INVALID_INPUT,
				false
			);
		}

		if ( $id > 0 ) {
			$image = self::image( $id );
			return is_wp_error( $image ) ? $image : array(
				'id'      => $id,
				'created' => array(),
			);
		}

		$uploaded = Media_Uploader::from_url( array( 'url' => $url ) );
		if ( is_wp_error( $uploaded ) ) {
			return $uploaded;
		}
		$id    = (int) $uploaded['attachment_id'];
		$image = self::image( $id );
		if ( is_wp_error( $image ) ) {
			wp_delete_attachment( $id, true );
			return $image;
		}

		return array(
			'id'      => $id,
			'created' => array( $id ),
		);
	}

	/**
	 * An attachment that is an image, or a refusal.
	 *
	 * @param int $id Attachment ID.
	 * @return WP_Post|WP_Error
	 */
	public static function image( $id ) {
		$post = get_post( absint( $id ) );
		if ( ! $post instanceof WP_Post || 'attachment' !== $post->post_type ) {
			return Site_Support::error(
				'mosmcp_image_not_found',
				sprintf(
					/* translators: %d: attachment ID. */
					__( 'There is no media item with ID %d. Find images with mosmcp/media-list-images.', 'mosmcp-abilities' ),
					absint( $id )
				),
				Site_Support::CAUSE_NOT_FOUND,
				false,
				array( 'use_instead' => 'mosmcp/media-list-images' )
			);
		}
		if ( ! wp_attachment_is_image( $post ) ) {
			return Site_Support::error(
				'mosmcp_not_an_image',
				sprintf(
					/* translators: 1: file name, 2: MIME type. */
					__( '"%1$s" is not an image (it is %2$s), so it cannot be a logo or an icon.', 'mosmcp-abilities' ),
					wp_basename( (string) get_attached_file( $post->ID ) ),
					(string) $post->post_mime_type
				),
				Site_Support::CAUSE_INVALID_INPUT,
				false
			);
		}
		return $post;
	}

	/**
	 * The square site icon for an image: the image itself when it already is one,
	 * otherwise a 512-pixel square copy cut around the focus point, made and
	 * stored exactly as the Customizer's cropper makes it (same file, same
	 * attachment properties, same extra icon sizes).
	 *
	 * @param WP_Post $source Source image.
	 * @param string  $focus  Key of self::FOCUS.
	 * @return array{id:int,cropped:bool,note:string}|WP_Error
	 */
	public static function square_copy( WP_Post $source, $focus ) {
		$meta   = wp_get_attachment_metadata( $source->ID );
		$width  = is_array( $meta ) && isset( $meta['width'] ) ? (int) $meta['width'] : 0;
		$height = is_array( $meta ) && isset( $meta['height'] ) ? (int) $meta['height'] : 0;
		$square = $width === $height && $width >= self::ICON_SIZE;

		if ( $square && 'site-icon' === get_post_meta( $source->ID, '_wp_attachment_context', true ) ) {
			return array(
				'id'      => (int) $source->ID,
				'cropped' => false,
				'note'    => '',
			);
		}

		if ( ! wp_image_editor_supports( array( 'mime_type' => $source->post_mime_type ) ) ) {
			$library = wp_image_editor_supports( array( 'mime_type' => 'image/png' ) ) || wp_image_editor_supports( array( 'mime_type' => 'image/jpeg' ) );
			if ( $square && ! $library ) {
				// Nothing can resize here, but a big enough square still works as an icon.
				return array(
					'id'      => (int) $source->ID,
					'cropped' => false,
					'note'    => __( 'This server has no image library (GD or Imagick), so WordPress cannot make the small icon sizes; the whole image is used, and browsers download it at full size. The host can enable GD or Imagick.', 'mosmcp-abilities' ),
				);
			}
			return Site_Support::error(
				'mosmcp_icon_unsupported_type',
				$library
					? sprintf(
						/* translators: %s: MIME type. */
						__( 'This server cannot resize %s images, and a site icon needs resized copies. Use a PNG, JPEG or WebP image at least 512 × 512 pixels.', 'mosmcp-abilities' ),
						(string) $source->post_mime_type
					)
					: __( 'This server has no image library (GD or Imagick) to crop or resize images with, so the site icon must already be a square image at least 512 × 512 pixels. Use a square image, or ask the host to enable GD or Imagick.', 'mosmcp-abilities' ),
				$library ? Site_Support::CAUSE_INVALID_INPUT : Site_Support::CAUSE_HOST_ENVIRONMENT,
				false
			);
		}

		$side = min( $width, $height );
		if ( $side < self::ICON_SIZE ) {
			return Site_Support::error(
				'mosmcp_icon_too_small',
				sprintf(
					/* translators: 1: width, 2: height, 3: minimum size. */
					__( 'That image is %1$d × %2$d pixels. A site icon must be at least %3$d pixels on its shorter side, or it looks blurry on phones and in bookmarks. Use a larger version of the image.', 'mosmcp-abilities' ),
					$width,
					$height,
					self::ICON_SIZE
				),
				Site_Support::CAUSE_INVALID_INPUT,
				false
			);
		}

		$box = Image_Geometry::box( $width, $height, 1.0, $focus );

		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-site-icon.php';

		$cropped = wp_crop_image( $source->ID, $box['x'], $box['y'], $box['width'], $box['height'], self::ICON_SIZE, self::ICON_SIZE );
		if ( ! $cropped || is_wp_error( $cropped ) ) {
			return Site_Support::error(
				'mosmcp_icon_crop_failed',
				__( 'The image could not be cropped into a square icon on this server.', 'mosmcp-abilities' ) . ( is_wp_error( $cropped ) ? ' ' . $cropped->get_error_message() : '' ),
				Site_Support::CAUSE_HOST_ENVIRONMENT,
				false
			);
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's filter, applied exactly where core applies it to a cropped site icon (for file replication plugins).
		$cropped    = apply_filters( 'wp_create_file_in_uploads', $cropped, $source->ID );
		$attachment = wp_copy_parent_attachment_properties( $cropped, $source->ID, 'site-icon' );
		$site_icon  = new \WP_Site_Icon();

		add_filter( 'intermediate_image_sizes_advanced', array( $site_icon, 'additional_sizes' ) );
		$id = $site_icon->insert_attachment( $attachment, $cropped );
		remove_filter( 'intermediate_image_sizes_advanced', array( $site_icon, 'additional_sizes' ) );

		if ( ! $id || is_wp_error( $id ) ) {
			wp_delete_file( $cropped );
			return Site_Support::error(
				'mosmcp_icon_crop_failed',
				__( 'The cropped icon could not be added to the media library.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_WP_CORE,
				false
			);
		}

		return array(
			'id'      => (int) $id,
			'cropped' => true,
			'note'    => '',
		);
	}

	/*
	 * Where the logo appears
	 */

	/**
	 * Whether the theme shows a logo once the draft is published, and where.
	 *
	 * A classic theme shows one only if it declares custom-logo support; a block
	 * theme only where a template or part (with its patterns expanded) holds a
	 * Site Logo block. Setting a logo the theme never shows is the commonest
	 * "it didn't work" there is, so this is reported every time.
	 *
	 * @param array<string, mixed>|null $draft Draft.
	 * @return array{shown:bool,where:string[],hint:string}
	 */
	public static function logo_display( $draft ) {
		if ( ! Theme_Support::is_block_theme() ) {
			$support = get_theme_support( 'custom-logo' );
			$args    = is_array( $support ) && isset( $support[0] ) && is_array( $support[0] ) ? $support[0] : array();
			$hint    = '';
			if ( ! empty( $args['width'] ) && ! empty( $args['height'] ) ) {
				$hint = sprintf(
					/* translators: 1: width, 2: height. */
					__( 'The theme is designed for a logo about %1$d × %2$d pixels.', 'mosmcp-abilities' ),
					(int) $args['width'],
					(int) $args['height']
				);
			}
			return array(
				'shown' => false !== $support,
				'where' => false !== $support ? array( __( 'the theme header', 'mosmcp-abilities' ) ) : array(),
				'hint'  => $hint,
			);
		}

		$where = array();
		foreach ( Block_Templates::TYPES as $type ) {
			foreach ( Block_Templates::all( $type ) as $template ) {
				$content = Block_Templates::expand_patterns( (string) Block_Templates::effective_content( $draft, $type, $template->slug ) );
				if ( false !== strpos( $content, '<!-- wp:site-logo' ) ) {
					$where[] = sprintf( 'wp_template_part' === $type ? 'the template part "%s"' : 'the template "%s"', $template->slug );
				}
			}
		}

		return array(
			'shown' => ! empty( $where ),
			'where' => $where,
			'hint'  => __( 'The Site Logo block sets how wide the logo appears; an image at least twice that width stays sharp on high-density screens.', 'mosmcp-abilities' ),
		);
	}

	/**
	 * What to tell the AI when the theme will not show the logo.
	 *
	 * @param array{shown:bool,where:string[],hint:string} $display From logo_display().
	 * @return string Empty when the logo is shown.
	 */
	public static function hidden_logo_note( array $display ) {
		if ( $display['shown'] ) {
			return '';
		}
		if ( ! Theme_Support::is_block_theme() ) {
			return __( 'The active theme does not display a site logo at all (it has no logo support), so the logo will be stored but not shown. A theme that supports a logo would show it.', 'mosmcp-abilities' );
		}
		$header = Block_Templates::live( 'wp_template_part', 'header' ) ? ' "header"' : '';
		return sprintf(
			/* translators: %s: ' "header"' or empty. */
			__( 'No template or template part of this block theme contains a Site Logo block, so the logo will not appear anywhere yet. To show it, add <!-- wp:site-logo /--> to the header template part%s, for example just before the Site Title block: read it with mosmcp/theme-part-get and stage the change with mosmcp/theme-part-update, in this same draft.', 'mosmcp-abilities' ),
			$header
		);
	}

	/*
	 * Clean-up
	 */

	/**
	 * Deletes images the draft added to the media library that nothing uses.
	 *
	 * Only images this draft created are ever passed here, and each is kept if it
	 * is the live logo or icon, staged in the draft, a featured image, or named
	 * in any content.
	 *
	 * @param int[]                     $ids   Attachment IDs the draft created.
	 * @param array<string, mixed>|null $draft Draft as it now stands.
	 * @return void
	 */
	public static function remove_unused( array $ids, $draft ) {
		foreach ( array_unique( array_map( 'absint', $ids ) ) as $id ) {
			if ( $id > 0 && ! self::in_use( $id, $draft ) ) {
				wp_delete_attachment( $id, true );
			}
		}
	}

	/**
	 * Whether anything uses an image.
	 *
	 * @param int                       $id    Attachment ID.
	 * @param array<string, mixed>|null $draft Draft.
	 * @return bool
	 */
	private static function in_use( $id, $draft ) {
		global $wpdb;

		$post = get_post( $id );
		if ( ! $post instanceof WP_Post || 'attachment' !== $post->post_type ) {
			return true;
		}
		foreach ( self::KINDS as $kind ) {
			if ( self::live( $kind ) === $id || self::effective( $draft, $kind ) === $id ) {
				return true;
			}
		}

		$name = (string) preg_replace( '/\.[^.]+$/', '', wp_basename( (string) get_attached_file( $id ) ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A one-off check before deleting a file; no API searches every post's content or every featured image.
		$featured = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %s LIMIT 1", (string) $id ) );
		$named    = '' !== $name ? $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type NOT IN ('revision', 'attachment') AND ( post_content LIKE %s OR post_content LIKE %s ) LIMIT 1",
				'%' . $wpdb->esc_like( 'wp-image-' . $id ) . '%',
				'%' . $wpdb->esc_like( $name ) . '%'
			)
		) : null;
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return ! empty( $featured ) || ! empty( $named );
	}
}
