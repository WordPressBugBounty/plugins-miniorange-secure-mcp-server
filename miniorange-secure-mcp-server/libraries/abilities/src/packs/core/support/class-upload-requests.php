<?php
/**
 * Upload links: a page in wp-admin, behind the person's normal sign-in, where
 * they upload a file their AI asked for.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Core\Support;

use MoSMCP\Abilities\Config;
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
 * Class Upload_Requests
 *
 * An AI app cannot hand a file from the person's computer to a tool call, and a
 * file pasted into the chat never reaches the site. So the AI asks for a link,
 * gives it to the person, and they upload on their own site: the page sits in
 * wp-admin behind their normal sign-in, accepts only what the request allows,
 * and only for the account that asked. The AI then reads the new media IDs.
 */
class Upload_Requests {

	/**
	 * Requests kept.
	 */
	const KEEP = 50;

	/**
	 * Longest a link stays open, in hours.
	 */
	const MAX_HOURS = 72;

	/**
	 * Hooks the upload page.
	 *
	 * @return void
	 */
	public static function boot() {
		add_action( 'admin_post_' . self::action(), array( __CLASS__, 'page' ) );
		add_action( 'admin_post_nopriv_' . self::action(), array( __CLASS__, 'sign_in' ) );
	}

	/**
	 * The admin-post action.
	 *
	 * @return string
	 */
	private static function action() {
		return Config::prefix() . '_upload';
	}

	/**
	 * Opens a request and returns its link.
	 *
	 * @param string $purpose   What the AI asked for, shown on the page.
	 * @param string $accept    "image" or "any".
	 * @param int    $max_files Most files.
	 * @param int    $hours     Hours the link stays open.
	 * @return array{request:array<string, mixed>,url:string}
	 */
	public static function create( $purpose, $accept, $max_files, $hours ) {
		$id      = strtolower( wp_generate_password( 12, false, false ) );
		$token   = wp_generate_password( 32, false, false );
		$request = array(
			'id'        => $id,
			'token'     => wp_hash( $token ),
			'user'      => get_current_user_id(),
			'created'   => time(),
			'expires'   => time() + max( 1, min( self::MAX_HOURS, (int) $hours ) ) * HOUR_IN_SECONDS,
			'purpose'   => sanitize_text_field( (string) $purpose ),
			'accept'    => 'any' === $accept ? 'any' : 'image',
			'max_files' => max( 1, min( 20, (int) $max_files ) ),
			'uploaded'  => array(),
		);

		$list        = self::all();
		$list[ $id ] = $request;
		self::store( $list );

		return array(
			'request' => $request,
			'url'     => add_query_arg(
				array(
					'action'  => self::action(),
					'request' => $id,
					'token'   => $token,
				),
				admin_url( 'admin-post.php' )
			),
		);
	}

	/**
	 * What became of a request, for the account that opened it.
	 *
	 * @param string $id Request ID.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function status( $id ) {
		$request = self::get( sanitize_key( (string) $id ) );
		if ( null === $request || get_current_user_id() !== (int) $request['user'] ) {
			return Site_Support::error(
				'mosmcp_upload_request_not_found',
				__( 'There is no upload link with that ID for this account. Make one with mosmcp/media-upload-link-create.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_NOT_FOUND,
				false,
				array( 'use_instead' => 'mosmcp/media-upload-link-create' )
			);
		}

		$files = array();
		foreach ( (array) $request['uploaded'] as $attachment_id ) {
			$post = get_post( (int) $attachment_id );
			if ( ! $post ) {
				continue;
			}
			$meta    = wp_get_attachment_metadata( $post->ID );
			$files[] = array(
				'id'        => (int) $post->ID,
				'title'     => (string) get_the_title( $post ),
				'url'       => (string) wp_get_attachment_url( $post->ID ),
				'filename'  => wp_basename( (string) get_attached_file( $post->ID ) ),
				'mime_type' => (string) $post->post_mime_type,
				'width'     => is_array( $meta ) && isset( $meta['width'] ) ? (int) $meta['width'] : 0,
				'height'    => is_array( $meta ) && isset( $meta['height'] ) ? (int) $meta['height'] : 0,
			);
		}

		$expired = time() > (int) $request['expires'];
		return array(
			'request_id' => (string) $request['id'],
			'status'     => ! empty( $files ) ? 'uploaded' : ( $expired ? 'expired' : 'waiting' ),
			'purpose'    => (string) $request['purpose'],
			'expires'    => gmdate( 'Y-m-d H:i:s', (int) $request['expires'] ) . ' UTC',
			'files'      => $files,
		);
	}

	/*
	 * The page
	 */

	/**
	 * Sends a signed-out visitor to sign in, then back to the page.
	 *
	 * @return void
	 */
	public static function sign_in() {
		auth_redirect();
	}

	/**
	 * Shows the upload page, and receives the upload.
	 *
	 * @return void
	 */
	public static function page() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- The request token gates the page; the upload itself is nonce-checked below.
		$id    = isset( $_REQUEST['request'] ) ? sanitize_key( wp_unslash( $_REQUEST['request'] ) ) : '';
		$token = isset( $_REQUEST['token'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['token'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$request = '' !== $id ? self::get( $id ) : null;
		if ( null === $request || '' === $token || ! hash_equals( (string) $request['token'], wp_hash( $token ) ) ) {
			self::render_message( __( 'This upload link is not valid. Ask your AI for a new one.', 'mosmcp-abilities' ) );
		}
		if ( get_current_user_id() !== (int) $request['user'] ) {
			self::render_message( __( 'This upload link was made for a different account on this site. Sign in as that person, or ask your own AI for a link.', 'mosmcp-abilities' ) );
		}
		if ( ! current_user_can( 'upload_files' ) ) {
			self::render_message( __( 'Your account is not allowed to upload files to this site.', 'mosmcp-abilities' ) );
		}
		if ( time() > (int) $request['expires'] && empty( $request['uploaded'] ) ) {
			self::render_message( __( 'This upload link has expired. Ask your AI for a new one.', 'mosmcp-abilities' ) );
		}

		$problems = array();
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && \WpOrg\Requests\Requests::POST === $_SERVER['REQUEST_METHOD'] ) {
			check_admin_referer( self::action() . '_' . $id );
			$problems = self::receive( $request );
			$request  = self::get( $id );
		}

		self::render_page( $request, $token, $problems );
	}

	/**
	 * Takes the posted files into the media library, within the request's limits.
	 *
	 * @param array<string, mixed> $request Request.
	 * @return string[] Problems, one per file refused.
	 */
	private static function receive( array $request ) {
		$posted = isset( $_FILES['files'] ) && is_array( $_FILES['files'] ) && isset( $_FILES['files']['name'] ) ? $_FILES['files'] : null; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Nonce checked by the caller; WordPress validates uploaded files.
		if ( null === $posted ) {
			return array( __( 'Choose a file to upload first.', 'mosmcp-abilities' ) );
		}

		$files = array();
		foreach ( (array) $posted['name'] as $index => $name ) {
			$files[] = array(
				'name'     => (string) $name,
				'type'     => (string) $posted['type'][ $index ],
				'tmp_name' => (string) $posted['tmp_name'][ $index ],
				'error'    => (int) $posted['error'][ $index ],
				'size'     => (int) $posted['size'][ $index ],
			);
		}

		$accept = (string) $request['accept'];
		$check  = static function ( $file ) use ( $accept ) {
			if ( ! empty( $file['error'] ) || empty( $file['tmp_name'] ) ) {
				return $file;
			}
			$real = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] );
			if ( 'image' === $accept && 0 !== strpos( (string) $real['type'], 'image/' ) ) {
				$file['error'] = __( 'only images can be uploaded with this link', 'mosmcp-abilities' );
				return $file;
			}
			if ( 'image/svg+xml' === $real['type'] ) {
				$cleaned = Svg_Sanitizer::clean_file( $file['tmp_name'] );
				if ( is_wp_error( $cleaned ) ) {
					$file['error'] = $cleaned->get_error_message();
				}
			}
			return $file;
		};

		$problems = array();
		$room     = (int) $request['max_files'] - count( (array) $request['uploaded'] );
		add_filter( 'wp_handle_upload_prefilter', $check );
		foreach ( $files as $file ) {
			if ( UPLOAD_ERR_NO_FILE === $file['error'] ) {
				continue;
			}
			if ( $room < 1 ) {
				/* translators: %s: file name. */
				$problems[] = sprintf( __( '%s was not uploaded: this link takes no more files.', 'mosmcp-abilities' ), $file['name'] );
				continue;
			}
			$_FILES['mosmcp_upload_one'] = $file;
			$attachment_id               = media_handle_upload( 'mosmcp_upload_one', 0 );
			if ( is_wp_error( $attachment_id ) ) {
				/* translators: 1: file name, 2: reason. */
				$problems[] = sprintf( __( '%1$s was not uploaded: %2$s', 'mosmcp-abilities' ), $file['name'], wp_strip_all_tags( $attachment_id->get_error_message() ) );
				continue;
			}
			$request['uploaded'][] = (int) $attachment_id;
			--$room;
		}
		remove_filter( 'wp_handle_upload_prefilter', $check );
		unset( $_FILES['mosmcp_upload_one'] );

		$list                   = self::all();
		$list[ $request['id'] ] = $request;
		self::store( $list );

		return $problems;
	}

	/**
	 * Prints the upload page and ends the request.
	 *
	 * @param array<string, mixed> $request  Request.
	 * @param string               $token    Token from the link.
	 * @param string[]             $problems Problems from the last upload.
	 * @return void
	 */
	private static function render_page( array $request, $token, array $problems ) {
		$done  = count( (array) $request['uploaded'] );
		$room  = (int) $request['max_files'] - $done;
		$image = 'image' === $request['accept'];

		ob_start();
		echo '<p class="lead">' . esc_html__( 'Your AI assistant asked you to upload:', 'mosmcp-abilities' ) . '</p>';
		echo '<p class="purpose">' . esc_html( '' !== (string) $request['purpose'] ? (string) $request['purpose'] : __( 'a file', 'mosmcp-abilities' ) ) . '</p>';

		foreach ( $problems as $problem ) {
			echo '<p class="problem">' . esc_html( $problem ) . '</p>';
		}

		if ( $done > 0 ) {
			echo '<div class="done"><p><strong>' . esc_html( _n( 'Uploaded.', 'Uploaded.', $done, 'mosmcp-abilities' ) ) . '</strong> ' . esc_html__( 'Go back to your AI chat and tell it the upload is done.', 'mosmcp-abilities' ) . '</p><ul>';
			foreach ( (array) $request['uploaded'] as $attachment_id ) {
				$thumb = wp_attachment_is_image( (int) $attachment_id ) ? wp_get_attachment_image_url( (int) $attachment_id, 'thumbnail' ) : '';
				echo '<li>' . ( $thumb ? '<img src="' . esc_url( $thumb ) . '" alt="" />' : '' ) . '<span>' . esc_html( wp_basename( (string) get_attached_file( (int) $attachment_id ) ) ) . '</span></li>';
			}
			echo '</ul></div>';
		}

		if ( $room > 0 && time() <= (int) $request['expires'] ) {
			$action = add_query_arg(
				array(
					'action'  => self::action(),
					'request' => (string) $request['id'],
					'token'   => $token,
				),
				admin_url( 'admin-post.php' )
			);
			echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( $action ) . '">';
			wp_nonce_field( self::action() . '_' . $request['id'] );
			echo '<label class="pick"><input type="file" name="files[]"' . ( $room > 1 ? ' multiple' : '' ) . ( $image ? ' accept="image/*"' : '' ) . ' required /></label>';
			echo '<p class="rules">' . esc_html(
				sprintf(
					/* translators: 1: what kind of file, 2: number of files, 3: size limit. */
					_n( '%1$s, %2$d file, up to %3$s.', '%1$s, up to %2$d files, each up to %3$s.', $room, 'mosmcp-abilities' ),
					$image ? __( 'Images', 'mosmcp-abilities' ) : __( 'Any file this site accepts', 'mosmcp-abilities' ),
					$room,
					size_format( wp_max_upload_size() )
				)
			) . '</p>';
			echo '<button type="submit">' . esc_html__( 'Upload', 'mosmcp-abilities' ) . '</button></form>';
		}
		$body = (string) ob_get_clean();

		self::render( $body );
	}

	/**
	 * Prints a page holding only a message, and ends the request.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	private static function render_message( $message ) {
		self::render( '<p class="lead">' . esc_html( $message ) . '</p>' );
	}

	/**
	 * Prints the page around its body, and ends the request.
	 *
	 * @param string $body Escaped body markup.
	 * @return void
	 */
	private static function render( $body ) {
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		$title = __( 'Upload for your AI', 'mosmcp-abilities' );
		echo '<!DOCTYPE html><html ' . get_language_attributes() . '><head><meta charset="utf-8" /><meta name="viewport" content="width=device-width, initial-scale=1" /><meta name="robots" content="noindex, nofollow" />'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_language_attributes() returns escaped attributes.
		echo '<title>' . esc_html( $title . ' – ' . get_bloginfo( 'name' ) ) . '</title><style>'
			. 'body{margin:0;background:#f0f0f1;color:#1d2327;font:15px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Oxygen-Sans,Ubuntu,Cantarell,"Helvetica Neue",sans-serif}'
			. '.card{max-width:520px;margin:48px auto;padding:28px 32px;background:#fff;border:1px solid #dcdcde;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,.04)}'
			. 'h1{margin:0 0 4px;font-size:20px}.site{margin:0 0 20px;color:#646970;font-size:13px}.lead{margin:0 0 6px}'
			. '.purpose{margin:0 0 20px;padding:10px 14px;background:#f6f7f7;border-left:4px solid #2271b1;font-weight:600}'
			. '.pick{display:block;margin:0 0 8px;padding:18px;border:2px dashed #c3c4c7;border-radius:6px;text-align:center}'
			. '.rules{margin:0 0 16px;color:#646970;font-size:13px}'
			. 'button{padding:9px 20px;background:#2271b1;border:0;border-radius:4px;color:#fff;font-size:15px;cursor:pointer}button:hover{background:#135e96}'
			. '.problem{padding:10px 14px;background:#fcf0f1;border-left:4px solid #d63638}'
			. '.done{margin:0 0 20px;padding:10px 14px;background:#edfaef;border-left:4px solid #00a32a}.done ul{margin:8px 0 0;padding:0;list-style:none}'
			. '.done li{display:flex;align-items:center;gap:10px;margin:6px 0}.done img{width:48px;height:48px;object-fit:cover;border-radius:4px}'
			. '@media(max-width:600px){.card{margin:16px;padding:20px}}'
			. '</style></head><body><div class="card">';
		echo '<h1>' . esc_html( $title ) . '</h1><p class="site">' . esc_html( get_bloginfo( 'name' ) ) . '</p>';
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts above.
		echo '</div></body></html>';
		exit;
	}

	/*
	 * Storage
	 */

	/**
	 * A request by ID.
	 *
	 * @param string $id Request ID.
	 * @return array<string, mixed>|null
	 */
	private static function get( $id ) {
		$list = self::all();
		return isset( $list[ $id ] ) && is_array( $list[ $id ] ) ? $list[ $id ] : null;
	}

	/**
	 * Every stored request.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function all() {
		$list = get_option( Config::prefix() . '_upload_requests', array() );
		return is_array( $list ) ? $list : array();
	}

	/**
	 * Stores requests, dropping ones closed for a week and keeping the newest.
	 *
	 * @param array<string, array<string, mixed>> $requests Requests.
	 * @return void
	 */
	private static function store( array $requests ) {
		$requests = array_filter(
			$requests,
			static function ( $request ) {
				return is_array( $request ) && (int) $request['expires'] > time() - WEEK_IN_SECONDS;
			}
		);
		uasort(
			$requests,
			static function ( $a, $b ) {
				return (int) $b['created'] - (int) $a['created'];
			}
		);
		update_option( Config::prefix() . '_upload_requests', array_slice( $requests, 0, self::KEEP, true ), false );
	}
}
