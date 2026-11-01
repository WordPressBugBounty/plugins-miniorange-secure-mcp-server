<?php
/**
 * Execute logic for image editing, original restore, size regeneration and
 * upload links.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Core;

use MoSMCP\Abilities\Packs\Core\Support\Image_Edits;
use MoSMCP\Abilities\Packs\Core\Support\Upload_Requests;
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

/**
 * Class Media_Tools_Provider
 */
class Media_Tools_Provider {

	/**
	 * Crops, rotates, flips or resizes an image, into a copy by default.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function edit_image( $input = array() ) {
		$input  = is_array( $input ) ? $input : array();
		$source = Image_Edits::editable( isset( $input['id'] ) ? (int) $input['id'] : 0 );
		if ( is_wp_error( $source ) ) {
			return $source;
		}

		$meta = wp_get_attachment_metadata( $source->ID );
		$plan = Image_Edits::plan( $input, is_array( $meta ) && isset( $meta['width'] ) ? (int) $meta['width'] : 0, is_array( $meta ) && isset( $meta['height'] ) ? (int) $meta['height'] : 0 );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}

		$in_place = ! empty( $input['in_place'] );
		$notes    = array();

		if ( $in_place ) {
			// Undoable, so not core's "cannot be undone" wording.
			if ( ! isset( $input['confirm'] ) || true !== $input['confirm'] ) {
				return Site_Support::error(
					'mosmcp_confirmation_required',
					__( 'Editing in place changes the image itself everywhere WordPress builds it from the media library: featured images, logos, galleries. The original is kept, and mosmcp/media-restore-original puts it back. Tell the person, and call again with confirm set to true once they agree. To leave the original alone, leave out in_place: the edit is then saved as a new media item.', 'mosmcp-abilities' ),
					Site_Support::CAUSE_CONFIRMATION_REQUIRED,
					true
				);
			}
			$edited = Image_Edits::in_place( $source, $plan );
			if ( is_wp_error( $edited ) ) {
				return $edited;
			}
			$id      = (int) $source->ID;
			$notes[] = __( 'Pages that already show this image inside their content keep the version they had, because WordPress writes the image\'s address into the content; featured images, logos and galleries show the edited one. Put the original back with mosmcp/media-restore-original.', 'mosmcp-abilities' );
		} else {
			if ( ! current_user_can( 'upload_files' ) ) {
				return Site_Support::error( 'mosmcp_cannot_upload', __( 'Saving the edit as a new media item needs permission to upload files, which this account does not have.', 'mosmcp-abilities' ), Site_Support::CAUSE_CAPABILITY, false );
			}
			$id = Image_Edits::to_copy( $source, $plan, isset( $input['title'] ) ? (string) $input['title'] : '' );
			if ( is_wp_error( $id ) ) {
				return $id;
			}
			$notes[] = __( 'The edit is a new media item; the original is unchanged and everything that uses it still shows it. Use the new ID where the edited image should appear, for example as a featured image or logo.', 'mosmcp-abilities' );
		}

		$result          = self::describe( $id );
		$result['copy']  = ! $in_place;
		$result['from']  = (int) $source->ID;
		$result['steps'] = $plan['steps'];
		$result['notes'] = $notes;
		return $result;
	}

	/**
	 * Puts back the original of an image edited in place.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function restore_original( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$id    = isset( $input['id'] ) ? (int) $input['id'] : 0;
		$post  = get_post( $id );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return Site_Support::error( 'mosmcp_image_not_found', __( 'There is no media item with that ID.', 'mosmcp-abilities' ), Site_Support::CAUSE_NOT_FOUND, false );
		}
		if ( ! Image_Edits::has_original( $id ) ) {
			return Site_Support::error(
				'mosmcp_image_not_edited',
				__( 'This image has not been edited in place (or its original was already put back), so there is nothing to restore. An edited copy is a separate media item; the original was never changed.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_INVALID_INPUT,
				false
			);
		}
		if ( ! isset( $input['confirm'] ) || true !== $input['confirm'] ) {
			return Site_Support::error(
				'mosmcp_confirmation_required',
				__( 'Restoring puts the original back everywhere WordPress builds this image from the media library, dropping the in-place edits made since. Tell the person, and call again with confirm set to true once they agree.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_CONFIRMATION_REQUIRED,
				true
			);
		}

		Image_Edits::load();
		$result = wp_restore_image( $id );
		if ( ! empty( $result->error ) ) {
			return Site_Support::error(
				'mosmcp_image_restore_failed',
				/* translators: %s: WordPress's message. */
				sprintf( __( 'WordPress could not restore the original: %s', 'mosmcp-abilities' ), wp_strip_all_tags( (string) $result->error ) ),
				Site_Support::CAUSE_WP_CORE,
				false
			);
		}

		$out          = self::describe( $id );
		$out['notes'] = array( __( 'The original is back. The edited files stay in the uploads folder, as with WordPress\'s own "Restore original image".', 'mosmcp-abilities' ) );
		return $out;
	}

	/**
	 * Creates missing image sizes, a batch at a time.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function regenerate_sizes( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		if ( ! wp_image_editor_supports( array( 'mime_type' => 'image/jpeg' ) ) && ! wp_image_editor_supports( array( 'mime_type' => 'image/png' ) ) ) {
			return Site_Support::error(
				'mosmcp_no_image_library',
				__( 'This server has no image library (GD or Imagick), so WordPress cannot make image sizes here. The host can enable GD or Imagick, after which this can be run.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_HOST_ENVIRONMENT,
				false
			);
		}

		Image_Edits::load();
		$force  = ! empty( $input['force'] );
		$batch  = isset( $input['batch'] ) ? max( 1, min( 50, (int) $input['batch'] ) ) : 10;
		$cursor = isset( $input['cursor'] ) ? max( 0, (int) $input['cursor'] ) : 0;
		$only   = isset( $input['ids'] ) && is_array( $input['ids'] ) ? array_values( array_filter( array_map( 'absint', $input['ids'] ) ) ) : array();

		global $wpdb;
		$types = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/heic' );
		if ( ! empty( $only ) ) {
			// A short list given by ID is filtered here rather than in SQL.
			sort( $only );
			$todo      = array_values(
				array_filter(
					$only,
					static function ( $id ) use ( $cursor, $types ) {
						return $id > $cursor && in_array( get_post_mime_type( $id ), $types, true );
					}
				)
			);
			$ids       = array_slice( $todo, 0, $batch );
			$remaining = count( $todo );
		} else {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Keyset paging by ID over attachments, which WP_Query cannot express; every value is a placeholder.
			$ids       = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN (" . implode( ', ', array_fill( 0, count( $types ), '%s' ) ) . ') AND ID > %d ORDER BY ID ASC LIMIT %d',
					array_merge( $types, array( $cursor, $batch ) )
				)
			);
			$remaining = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN (" . implode( ', ', array_fill( 0, count( $types ), '%s' ) ) . ') AND ID > %d',
					array_merge( $types, array( $cursor ) )
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		}

		$done = array();
		foreach ( $ids as $id ) {
			$id    = (int) $id;
			$entry = array(
				'id'      => $id,
				'title'   => (string) get_the_title( $id ),
				'created' => array(),
				'error'   => '',
			);
			if ( ! current_user_can( 'edit_post', $id ) ) {
				$entry['error'] = __( 'not allowed to edit this item', 'mosmcp-abilities' );
				$done[]         = $entry;
				continue;
			}
			$before = wp_get_attachment_metadata( $id );
			$before = is_array( $before ) && isset( $before['sizes'] ) ? (array) $before['sizes'] : array();

			if ( $force ) {
				$file = wp_get_original_image_path( $id );
				$meta = $file ? wp_generate_attachment_metadata( $id, $file ) : new WP_Error( 'missing', __( 'the file is missing', 'mosmcp-abilities' ) );
				if ( ! is_wp_error( $meta ) && ! empty( $meta ) ) {
					wp_update_attachment_metadata( $id, $meta );
				}
			} else {
				$meta = wp_update_image_subsizes( $id );
			}

			if ( is_wp_error( $meta ) ) {
				$entry['error'] = $meta->get_error_message();
			} else {
				$after_sizes      = is_array( $meta ) && isset( $meta['sizes'] ) ? (array) $meta['sizes'] : array();
				$entry['created'] = $force ? array_keys( $after_sizes ) : array_values( array_diff( array_keys( $after_sizes ), array_keys( $before ) ) );
			}
			$done[] = $entry;
		}

		$next  = empty( $ids ) ? 0 : (int) end( $ids );
		$left  = max( 0, $remaining - count( $ids ) );
		$notes = array();
		if ( $left > 0 ) {
			/* translators: %d: number of images left. */
			$notes[] = sprintf( _n( '%d more image to go: call again with cursor set to next_cursor.', '%d more images to go: call again with cursor set to next_cursor.', $left, 'mosmcp-abilities' ), $left );
		} else {
			$notes[] = __( 'Every image has been checked.', 'mosmcp-abilities' );
		}
		if ( ! $force ) {
			$notes[] = __( 'Only missing sizes were made; existing ones were left alone. Pass force true to remake every size, for example after changing the size settings.', 'mosmcp-abilities' );
		}

		return array(
			'processed'   => $done,
			'next_cursor' => $left > 0 ? $next : 0,
			'remaining'   => $left,
			'done'        => 0 === $left,
			'notes'       => $notes,
		);
	}

	/**
	 * Makes an upload link for the person.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>
	 */
	public static function upload_link_create( $input = array() ) {
		$input   = is_array( $input ) ? $input : array();
		$created = Upload_Requests::create(
			isset( $input['purpose'] ) ? (string) $input['purpose'] : '',
			isset( $input['accept'] ) ? (string) $input['accept'] : 'image',
			isset( $input['max_files'] ) ? (int) $input['max_files'] : 1,
			isset( $input['expires_in_hours'] ) ? (int) $input['expires_in_hours'] : 24
		);
		$request = $created['request'];

		return array(
			'request_id' => (string) $request['id'],
			'url'        => $created['url'],
			'expires'    => gmdate( 'Y-m-d H:i:s', (int) $request['expires'] ) . ' UTC',
			'accept'     => (string) $request['accept'],
			'max_files'  => (int) $request['max_files'],
			'notes'      => array(
				__( 'Give the person this link. They open it in a browser where they are signed in to this site (it asks them to sign in otherwise), choose the file and upload it. Only this account can use it.', 'mosmcp-abilities' ),
				__( 'When they say it is done, call mosmcp/media-upload-link-status with request_id to get the new media IDs.', 'mosmcp-abilities' ),
			),
		);
	}

	/**
	 * Reports what was uploaded through a link.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function upload_link_status( $input = array() ) {
		$input  = is_array( $input ) ? $input : array();
		$status = Upload_Requests::status( isset( $input['request_id'] ) ? (string) $input['request_id'] : '' );
		if ( is_wp_error( $status ) ) {
			return $status;
		}

		$notes = array();
		if ( 'waiting' === $status['status'] ) {
			$notes[] = __( 'Nothing has been uploaded yet. Ask the person whether they opened the link, and check again once they say it is done.', 'mosmcp-abilities' );
		} elseif ( 'expired' === $status['status'] ) {
			$notes[] = __( 'The link expired before anything was uploaded. Make a new one with mosmcp/media-upload-link-create.', 'mosmcp-abilities' );
		} else {
			$notes[] = __( 'Use these media IDs with other abilities, for example mosmcp/site-set-logo or a featured image.', 'mosmcp-abilities' );
		}
		$status['notes'] = $notes;

		return $status;
	}

	/**
	 * An image for output.
	 *
	 * @param int $id Attachment ID.
	 * @return array<string, mixed>
	 */
	private static function describe( $id ) {
		$meta = wp_get_attachment_metadata( $id );
		return array(
			'id'       => (int) $id,
			'title'    => (string) get_the_title( $id ),
			'url'      => (string) wp_get_attachment_url( $id ),
			'filename' => wp_basename( (string) get_attached_file( $id ) ),
			'width'    => is_array( $meta ) && isset( $meta['width'] ) ? (int) $meta['width'] : 0,
			'height'   => is_array( $meta ) && isset( $meta['height'] ) ? (int) $meta['height'] : 0,
			'edit_url' => (string) get_edit_post_link( $id, 'raw' ),
		);
	}
}
