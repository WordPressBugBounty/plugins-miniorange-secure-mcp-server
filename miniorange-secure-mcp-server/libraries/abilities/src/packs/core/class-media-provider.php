<?php
/**
 * Execute callbacks for the core Media ability pack.
 *
 * Business logic is preserved from the reviewed source collection; only naming,
 * text domain, and formatting were adapted. Authorization is enforced by the
 * Ability_Registrar wrapper (upload_files / edit_post / delete_post).
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Core;

use MoSMCP\Abilities\Packs\Core\Support\Media_Uploader;
use MoSMCP\Abilities\Support\Pagination;
use WP_Error;
use WP_Query;

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
 * Class Media_Provider
 *
 * Static execute callbacks for media (attachment) abilities.
 */
class Media_Provider {

	/**
	 * Returns media library counts per category.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public static function count_by_type( $input = array() ) {
		unset( $input );
		$counts = (array) wp_count_attachments();

		$sum_prefix = static function ( $prefix ) use ( $counts ) {
			$total = 0;
			foreach ( $counts as $mime => $count ) {
				if ( 0 === strpos( $mime, $prefix ) ) {
					$total += (int) $count;
				}
			}
			return $total;
		};

		$sum_group = static function ( $group ) use ( $counts ) {
			$total = 0;
			foreach ( self::mime_group( $group ) as $mime ) {
				if ( isset( $counts[ $mime ] ) ) {
					$total += (int) $counts[ $mime ];
				}
			}
			return $total;
		};

		$all = 0;
		foreach ( $counts as $mime => $count ) {
			if ( 'trash' !== $mime ) {
				$all += (int) $count;
			}
		}

		$count_query = static function ( $extra ) {
			$query = new WP_Query(
				array_merge(
					array(
						'post_type'      => 'attachment',
						'post_status'    => 'inherit',
						'posts_per_page' => 1,
						'fields'         => 'ids',
					),
					$extra
				)
			);
			return (int) $query->found_posts;
		};

		return array(
			'all'          => $all,
			'images'       => $sum_prefix( 'image/' ),
			'audio'        => $sum_prefix( 'audio/' ),
			'video'        => $sum_prefix( 'video/' ),
			'documents'    => $sum_group( 'document' ),
			'spreadsheets' => $sum_group( 'spreadsheet' ),
			'archives'     => $sum_group( 'archive' ),
			'unattached'   => $count_query( array( 'post_parent' => 0 ) ),
			'mine'         => $count_query( array( 'author' => get_current_user_id() ) ),
		);
	}

	/**
	 * Permanently deletes a media item (media has no trash).
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function delete_permanently( $input = array() ) {
		$att = self::require_attachment( $input );
		if ( $att instanceof WP_Error ) {
			return $att;
		}

		if ( empty( $input['confirm'] ) || true !== $input['confirm'] ) {
			return new WP_Error(
				'confirmation_required',
				__( 'Permanent deletion is irreversible and removes the file from disk. Set confirm to true to proceed.', 'mosmcp-abilities' )
			);
		}

		$title    = (string) get_the_title( $att->ID );
		$file     = get_attached_file( $att->ID );
		$filename = $file ? (string) wp_basename( $file ) : '';

		$deleted = wp_delete_attachment( $att->ID, true );

		if ( ! $deleted ) {
			return new WP_Error( 'delete_failed', __( 'The media item could not be deleted.', 'mosmcp-abilities' ) );
		}

		return array(
			'id'       => (int) $att->ID,
			'title'    => $title,
			'filename' => $filename,
			'deleted'  => true,
		);
	}

	/**
	 * Finds media by title/filename, or looks up a media item by ID.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function find( $input = array() ) {
		$id     = isset( $input['id'] ) ? absint( $input['id'] ) : 0;
		$search = isset( $input['search'] ) ? trim( (string) $input['search'] ) : '';

		if ( $id <= 0 && '' === $search ) {
			return new WP_Error( 'missing_input', __( 'Provide either "search" (a media title or filename) or "id" (a media ID).', 'mosmcp-abilities' ) );
		}

		if ( $id > 0 ) {
			$att = get_post( $id );

			if ( ! $att || 'attachment' !== $att->post_type ) {
				return array(
					'showing' => 0,
					'total'   => 0,
					'matches' => array(),
				);
			}

			return array(
				'showing' => 1,
				'total'   => 1,
				'matches' => array( self::format_find_match( $att ) ),
			);
		}

		$query = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				's'              => $search,
				'search_columns' => array( 'post_title' ),
				'posts_per_page' => 10,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		$posts = $query->posts;
		$total = (int) $query->found_posts;

		// No title match — fall back to searching the stored filename.
		if ( empty( $posts ) ) {
			$query = new WP_Query(
				array(
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
					'posts_per_page' => 10,
					'orderby'        => 'date',
					'order'          => 'DESC',
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Filename lookup fallback; the media library has no dedicated index for this.
						array(
							'key'     => '_wp_attached_file',
							'value'   => $search,
							'compare' => 'LIKE',
						),
					),
				)
			);
			$posts = $query->posts;
			$total = (int) $query->found_posts;
		}

		$matches = array();
		foreach ( $posts as $att ) {
			$matches[] = self::format_find_match( $att );
		}

		return array(
			'showing' => count( $matches ),
			'total'   => $total,
			'matches' => $matches,
		);
	}

	/**
	 * Gets full details of a single media item by ID.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function get( $input = array() ) {
		$att = self::require_attachment( $input );
		if ( $att instanceof WP_Error ) {
			return $att;
		}

		$item = self::format_item( $att );

		$item['caption']     = (string) $att->post_excerpt;
		$item['description'] = (string) $att->post_content;
		$item['alt_text']    = (string) get_post_meta( $att->ID, '_wp_attachment_image_alt', true );

		$meta           = wp_get_attachment_metadata( $att->ID );
		$item['width']  = isset( $meta['width'] ) ? (int) $meta['width'] : 0;
		$item['height'] = isset( $meta['height'] ) ? (int) $meta['height'] : 0;

		return $item;
	}

	/**
	 * Lists all media items.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public static function list_all( $input = array() ) {
		return self::run_list( $input );
	}

	/**
	 * Lists archive files.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public static function list_archives( $input = array() ) {
		return self::run_list( $input, array( 'post_mime_type' => self::mime_group( 'archive' ) ) );
	}

	/**
	 * Lists audio files.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public static function list_audio( $input = array() ) {
		return self::run_list( $input, array( 'post_mime_type' => self::mime_group( 'audio' ) ) );
	}

	/**
	 * Lists document files.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public static function list_documents( $input = array() ) {
		return self::run_list( $input, array( 'post_mime_type' => self::mime_group( 'document' ) ) );
	}

	/**
	 * Lists image files.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public static function list_images( $input = array() ) {
		return self::run_list( $input, array( 'post_mime_type' => self::mime_group( 'image' ) ) );
	}

	/**
	 * Lists media uploaded by the current user.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public static function list_mine( $input = array() ) {
		return self::run_list( $input, array( 'author' => get_current_user_id() ) );
	}

	/**
	 * Lists spreadsheet files.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public static function list_spreadsheets( $input = array() ) {
		return self::run_list( $input, array( 'post_mime_type' => self::mime_group( 'spreadsheet' ) ) );
	}

	/**
	 * Lists media not attached to any post or page.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public static function list_unattached( $input = array() ) {
		return self::run_list( $input, array( 'post_parent' => 0 ) );
	}

	/**
	 * Lists video files.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public static function list_video( $input = array() ) {
		return self::run_list( $input, array( 'post_mime_type' => self::mime_group( 'video' ) ) );
	}

	/**
	 * Changes an image's alt text.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function update_alt_text( $input = array() ) {
		$att = self::require_attachment( $input );
		if ( $att instanceof WP_Error ) {
			return $att;
		}

		if ( 0 !== strpos( (string) $att->post_mime_type, 'image/' ) ) {
			return new WP_Error( 'not_an_image', __( 'Alt text can only be set on images. This media item is not an image.', 'mosmcp-abilities' ) );
		}

		$alt = isset( $input['alt_text'] ) ? sanitize_text_field( $input['alt_text'] ) : '';

		// wp_slash because the metadata API unslashes on the way in; without it a
		// backslash in the alt text would be dropped silently.
		update_post_meta( $att->ID, '_wp_attachment_image_alt', wp_slash( $alt ) );

		return array(
			'id'       => (int) $att->ID,
			'title'    => (string) get_the_title( $att->ID ),
			'alt_text' => $alt,
			'edit_url' => self::edit_url( (int) $att->ID ),
		);
	}

	/**
	 * Changes a media item's caption.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function update_caption( $input = array() ) {
		$att = self::require_attachment( $input );
		if ( $att instanceof WP_Error ) {
			return $att;
		}

		$caption = isset( $input['caption'] ) ? sanitize_text_field( $input['caption'] ) : '';

		$result = wp_update_post(
			array(
				'ID'           => $att->ID,
				'post_excerpt' => $caption,
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'id'       => (int) $att->ID,
			'title'    => (string) get_the_title( $att->ID ),
			'caption'  => $caption,
			'edit_url' => self::edit_url( (int) $att->ID ),
		);
	}

	/**
	 * Changes a media item's description.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function update_description( $input = array() ) {
		$att = self::require_attachment( $input );
		if ( $att instanceof WP_Error ) {
			return $att;
		}

		$description = isset( $input['description'] ) ? sanitize_textarea_field( $input['description'] ) : '';

		$result = wp_update_post(
			array(
				'ID'           => $att->ID,
				'post_content' => $description,
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'id'          => (int) $att->ID,
			'title'       => (string) get_the_title( $att->ID ),
			'description' => $description,
			'edit_url'    => self::edit_url( (int) $att->ID ),
		);
	}

	/**
	 * Changes a media item's title.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function update_title( $input = array() ) {
		$att = self::require_attachment( $input );
		if ( $att instanceof WP_Error ) {
			return $att;
		}

		$title = isset( $input['title'] ) ? sanitize_text_field( $input['title'] ) : '';

		if ( '' === $title ) {
			return new WP_Error( 'missing_title', __( 'A new title is required.', 'mosmcp-abilities' ) );
		}

		$result = wp_update_post(
			array(
				'ID'         => $att->ID,
				'post_title' => $title,
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'id'       => (int) $att->ID,
			'title'    => (string) get_the_title( $att->ID ),
			'edit_url' => self::edit_url( (int) $att->ID ),
		);
	}

	/**
	 * Adds a file to the media library by downloading it from a URL.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function upload_from_url( $input = array() ) {
		return Media_Uploader::from_url( (array) $input );
	}

	/**
	 * Adds a file to the media library from base64-encoded contents.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function upload_file( $input = array() ) {
		return Media_Uploader::from_content( (array) $input );
	}

	/**
	 * Loads the target attachment or returns a WP_Error.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return \WP_Post|WP_Error
	 */
	private static function require_attachment( $input ) {
		$id  = isset( $input['id'] ) ? absint( $input['id'] ) : 0;
		$att = get_post( $id );

		if ( ! $att || 'attachment' !== $att->post_type ) {
			return new WP_Error( 'media_not_found', __( 'No media item found with that ID.', 'mosmcp-abilities' ) );
		}

		return $att;
	}

	/**
	 * Returns the post_mime_type query value for a media group.
	 *
	 * @param string $group One of image|audio|video|document|spreadsheet|archive.
	 * @return string|string[]
	 */
	private static function mime_group( $group ) {
		switch ( $group ) {
			case 'image':
			case 'audio':
			case 'video':
				return $group;
			case 'document':
				return array(
					'application/msword',
					'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
					'application/vnd.ms-word.document.macroEnabled.12',
					'application/vnd.ms-word.template.macroEnabled.12',
					'application/vnd.oasis.opendocument.text',
					'application/vnd.apple.pages',
					'application/pdf',
					'application/vnd.ms-xpsdocument',
					'application/oxps',
					'application/rtf',
					'application/wordperfect',
					'text/plain',
				);
			case 'spreadsheet':
				return array(
					'application/vnd.apple.numbers',
					'application/vnd.ms-excel',
					'application/vnd.ms-excel.sheet.macroEnabled.12',
					'application/vnd.ms-excel.sheet.binary.macroEnabled.12',
					'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
					'application/vnd.oasis.opendocument.spreadsheet',
					'text/csv',
				);
			case 'archive':
				return array(
					'application/zip',
					'application/x-zip-compressed',
					'application/gzip',
					'application/x-gzip',
					'application/rar',
					'application/x-rar-compressed',
					'application/7z',
					'application/x-7z-compressed',
					'application/x-tar',
					'application/x-bzip2',
				);
		}
		return '';
	}

	/**
	 * Formats one attachment as the standard media list/detail item.
	 *
	 * @param \WP_Post $att Attachment post object.
	 * @return array<string, mixed>
	 */
	private static function format_item( $att ) {
		$uploader = get_userdata( (int) $att->post_author );
		$file     = get_attached_file( $att->ID );
		$size     = ( $file && file_exists( $file ) ) ? (int) filesize( $file ) : 0;
		$parent   = $att->post_parent ? get_post( $att->post_parent ) : null;

		return array(
			'id'                => (int) $att->ID,
			'title'             => (string) get_the_title( $att ),
			'filename'          => $file ? (string) wp_basename( $file ) : '',
			'mime_type'         => (string) $att->post_mime_type,
			'file_size'         => $size > 0 ? (string) size_format( $size ) : 'unknown',
			'url'               => (string) wp_get_attachment_url( $att->ID ),
			'uploaded_on'       => (string) $att->post_date,
			'uploader'          => $uploader ? (string) $uploader->display_name : '',
			'attached_to_id'    => (int) $att->post_parent,
			'attached_to_title' => $parent ? (string) get_the_title( $parent ) : '',
			'edit_url'          => self::edit_url( (int) $att->ID ),
		);
	}

	/**
	 * Formats a single media search match.
	 *
	 * @param \WP_Post $att Attachment post object.
	 * @return array<string, mixed>
	 */
	private static function format_find_match( $att ) {
		$file = get_attached_file( $att->ID );
		return array(
			'id'        => (int) $att->ID,
			'title'     => (string) get_the_title( $att ),
			'filename'  => $file ? (string) wp_basename( $file ) : '',
			'mime_type' => (string) $att->post_mime_type,
			'url'       => (string) wp_get_attachment_url( $att->ID ),
			'edit_url'  => self::edit_url( (int) $att->ID ),
		);
	}

	/**
	 * Runs the standard media list query and returns the standard envelope.
	 *
	 * @param array<string, mixed> $input      Ability input (per_page, offset).
	 * @param array<string, mixed> $extra_args Additional WP_Query args.
	 * @return array<string, mixed>
	 */
	private static function run_list( $input, $extra_args = array() ) {
		$per_page = isset( $input['per_page'] ) ? absint( $input['per_page'] ) : 20;
		$per_page = min( $per_page, Pagination::MAX_PER_PAGE );
		$offset   = isset( $input['offset'] ) ? absint( $input['offset'] ) : 0;

		$args = array_merge(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => $per_page,
				'offset'         => $offset,
				'orderby'        => 'date',
				'order'          => 'DESC',
			),
			$extra_args
		);

		$query = new WP_Query( $args );

		$items = array();
		foreach ( $query->posts as $att ) {
			$items[] = self::format_item( $att );
		}

		return array(
			'showing' => count( $items ),
			'total'   => (int) $query->found_posts,
			'items'   => $items,
		);
	}

	/**
	 * Admin edit URL for a media item.
	 *
	 * @param int $att_id Attachment ID.
	 * @return string
	 */
	private static function edit_url( $att_id ) {
		return (string) admin_url( 'post.php?post=' . $att_id . '&action=edit' );
	}
}
