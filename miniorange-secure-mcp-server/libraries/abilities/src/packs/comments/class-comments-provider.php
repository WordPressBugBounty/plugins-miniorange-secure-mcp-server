<?php
/**
 * Execute callbacks for the Comments ability pack.
 *
 * Business logic is preserved from the reviewed source collection; only naming,
 * text domain, and error-code prefixes were adapted. Authorization is enforced by
 * the Ability_Registrar wrapper (moderate_comments).
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Comments;

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

/*
 * These abilities intentionally query by post/user/comment meta or taxonomy
 * (and exclude specific IDs) — that is the tool surface the library exposes.
 * The queries are bounded and parameterized, so this performance advisory is
 * accepted here (the sniff is not part of the library's own phpcs.xml.dist; this
 * directive covers Plugin Check, which enforces its own broader standard).
 */
// phpcs:disable WordPress.DB.SlowDBQuery

/**
 * Class Comments_Provider
 *
 * Static execute callbacks for comment abilities.
 */
class Comments_Provider {

	/**
	 * Lists comments awaiting moderation.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public static function list_pending( $input = array() ) {
		return self::list_by_status( $input, 'hold' );
	}

	/**
	 * Lists approved comments.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public static function list_approved( $input = array() ) {
		return self::list_by_status( $input, 'approve' );
	}

	/**
	 * Lists spam comments.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public static function list_spam( $input = array() ) {
		return self::list_by_status( $input, 'spam' );
	}

	/**
	 * Lists trashed comments.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public static function list_trashed( $input = array() ) {
		return self::list_by_status( $input, 'trash' );
	}

	/**
	 * Gets a single comment by ID.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function get_comment_by_id( $input = array() ) {
		$input   = is_array( $input ) ? $input : array();
		$id      = isset( $input['id'] ) ? absint( $input['id'] ) : 0;
		$comment = $id > 0 ? get_comment( $id ) : null;

		if ( ! $comment ) {
			return new WP_Error( 'mosmcp_comment_not_found', __( 'No comment was found with that ID.', 'mosmcp-abilities' ) );
		}

		return self::comment_summary( $comment );
	}

	/**
	 * Searches comments by keyword.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function search_comments( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$term  = isset( $input['query'] ) ? trim( (string) $input['query'] ) : '';

		if ( '' === $term ) {
			return new WP_Error( 'mosmcp_missing_query', __( 'A search query is required.', 'mosmcp-abilities' ) );
		}

		$paginate = self::paginate_args( $input );

		$comments = get_comments(
			array(
				'search' => $term,
				'number' => $paginate['number'],
				'offset' => $paginate['offset'],
			)
		);

		return array(
			'comments' => array_values( array_map( array( __CLASS__, 'comment_summary' ), $comments ) ),
			'page'     => $paginate['page'],
			'per_page' => $paginate['per_page'],
		);
	}

	/**
	 * Lists comments on a specific post.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function get_comments_for_post( $input = array() ) {
		$input   = is_array( $input ) ? $input : array();
		$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;

		if ( $post_id <= 0 || ! get_post( $post_id ) ) {
			return new WP_Error( 'mosmcp_post_not_found', __( 'No post was found with that ID.', 'mosmcp-abilities' ) );
		}

		$paginate = self::paginate_args( $input );

		$comments = get_comments(
			array(
				'post_id' => $post_id,
				'number'  => $paginate['number'],
				'offset'  => $paginate['offset'],
			)
		);

		return array(
			'comments' => array_values( array_map( array( __CLASS__, 'comment_summary' ), $comments ) ),
			'page'     => $paginate['page'],
			'per_page' => $paginate['per_page'],
		);
	}

	/**
	 * Returns comment counts by status.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public static function count_comments( $input = array() ) {
		unset( $input );
		$counts = wp_count_comments();

		return array(
			'total'    => (int) $counts->total_comments,
			'approved' => (int) $counts->approved,
			'pending'  => (int) $counts->moderated,
			'spam'     => (int) $counts->spam,
			'trash'    => (int) $counts->trash,
		);
	}

	/**
	 * Approves a comment.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function approve_comment( $input = array() ) {
		$id = self::require_comment( $input );
		if ( $id instanceof WP_Error ) {
			return $id;
		}

		$result = wp_set_comment_status( $id, 'approve', true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'id'      => $id,
			'success' => true,
		);
	}

	/**
	 * Reverts an approved comment to pending.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function unapprove_comment( $input = array() ) {
		$id = self::require_comment( $input );
		if ( $id instanceof WP_Error ) {
			return $id;
		}

		$result = wp_set_comment_status( $id, 'hold', true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'id'      => $id,
			'success' => true,
		);
	}

	/**
	 * Marks a comment as spam.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function mark_comment_spam( $input = array() ) {
		$id = self::require_comment( $input );
		if ( $id instanceof WP_Error ) {
			return $id;
		}

		if ( ! wp_spam_comment( $id ) ) {
			return new WP_Error( 'mosmcp_spam_failed', __( 'Failed to mark the comment as spam.', 'mosmcp-abilities' ) );
		}

		return array(
			'id'      => $id,
			'success' => true,
		);
	}

	/**
	 * Restores a comment from spam.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function unmark_comment_spam( $input = array() ) {
		$id = self::require_comment( $input );
		if ( $id instanceof WP_Error ) {
			return $id;
		}

		if ( ! wp_unspam_comment( $id ) ) {
			return new WP_Error( 'mosmcp_unspam_failed', __( 'Failed to restore the comment from spam.', 'mosmcp-abilities' ) );
		}

		return array(
			'id'      => $id,
			'success' => true,
		);
	}

	/**
	 * Moves a comment to the trash.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function trash_comment( $input = array() ) {
		$id = self::require_comment( $input );
		if ( $id instanceof WP_Error ) {
			return $id;
		}

		if ( ! wp_trash_comment( $id ) ) {
			return new WP_Error( 'mosmcp_trash_failed', __( 'Failed to trash the comment.', 'mosmcp-abilities' ) );
		}

		return array(
			'id'      => $id,
			'success' => true,
		);
	}

	/**
	 * Permanently deletes a comment.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function permanently_delete_comment( $input = array() ) {
		$id = self::require_comment( $input );
		if ( $id instanceof WP_Error ) {
			return $id;
		}

		if ( ! wp_delete_comment( $id, true ) ) {
			return new WP_Error( 'mosmcp_delete_failed', __( 'Failed to delete the comment.', 'mosmcp-abilities' ) );
		}

		return array(
			'id'      => $id,
			'success' => true,
		);
	}

	/**
	 * Posts an admin reply to a comment.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function reply_to_comment( $input = array() ) {
		$id = self::require_comment( $input );
		if ( $id instanceof WP_Error ) {
			return $id;
		}

		$input   = is_array( $input ) ? $input : array();
		$content = isset( $input['content'] ) ? trim( (string) $input['content'] ) : '';
		if ( '' === $content ) {
			return new WP_Error( 'mosmcp_missing_content', __( 'Reply content is required.', 'mosmcp-abilities' ) );
		}

		$parent       = get_comment( $id );
		$current_user = wp_get_current_user();

		$new_id = wp_new_comment(
			array(
				'comment_post_ID'      => $parent->comment_post_ID,
				'comment_parent'       => $id,
				'comment_content'      => wp_kses_post( $content ),
				'comment_author'       => $current_user->display_name,
				'comment_author_email' => $current_user->user_email,
				'comment_author_url'   => $current_user->user_url,
				'user_id'              => $current_user->ID,
				'comment_approved'     => 1,
				'comment_type'         => '',
			),
			true
		);

		if ( is_wp_error( $new_id ) ) {
			return $new_id;
		}

		return self::comment_summary( get_comment( $new_id ) );
	}

	/**
	 * Returns metadata stored on a comment.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function get_comment_meta( $input = array() ) {
		$id = self::require_comment( $input );
		if ( $id instanceof WP_Error ) {
			return $id;
		}

		$input    = is_array( $input ) ? $input : array();
		$meta_key = isset( $input['meta_key'] ) ? sanitize_key( (string) $input['meta_key'] ) : '';

		if ( '' !== $meta_key ) {
			return array(
				'id'   => $id,
				'meta' => (object) array( $meta_key => get_comment_meta( $id, $meta_key, false ) ),
			);
		}

		return array(
			'id'   => $id,
			'meta' => (object) get_comment_meta( $id ),
		);
	}

	/**
	 * Sets a metadata key/value pair on a comment.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function update_comment_meta( $input = array() ) {
		$id = self::require_comment( $input );
		if ( $id instanceof WP_Error ) {
			return $id;
		}

		$input    = is_array( $input ) ? $input : array();
		$meta_key = isset( $input['meta_key'] ) ? sanitize_key( (string) $input['meta_key'] ) : '';
		if ( '' === $meta_key ) {
			return new WP_Error( 'mosmcp_missing_meta_key', __( 'A meta_key is required.', 'mosmcp-abilities' ) );
		}

		// wp_slash because the metadata API unslashes on the way in; a caller's value
		// would otherwise lose a level of backslashes before it is stored.
		update_comment_meta( $id, $meta_key, wp_slash( $input['meta_value'] ) );

		return array(
			'id'       => $id,
			'meta_key' => $meta_key,
			'updated'  => true,
		);
	}

	/**
	 * Returns the comment counts for a post.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function get_comment_count_by_post( $input = array() ) {
		$input   = is_array( $input ) ? $input : array();
		$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;

		if ( $post_id <= 0 || ! get_post( $post_id ) ) {
			return new WP_Error( 'mosmcp_post_not_found', __( 'No post was found with that ID.', 'mosmcp-abilities' ) );
		}

		$counts = wp_count_comments( $post_id );

		return array(
			'post_id'  => $post_id,
			'total'    => (int) $counts->total_comments,
			'approved' => (int) $counts->approved,
			'pending'  => (int) $counts->moderated,
			'spam'     => (int) $counts->spam,
			'trash'    => (int) $counts->trash,
		);
	}

	/**
	 * Returns the comment count for a single status.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function get_comment_count_by_status( $input = array() ) {
		$input  = is_array( $input ) ? $input : array();
		$status = isset( $input['status'] ) ? sanitize_key( (string) $input['status'] ) : '';

		if ( ! in_array( $status, array( 'hold', 'approve', 'spam', 'trash' ), true ) ) {
			return new WP_Error( 'mosmcp_invalid_status', __( 'Status must be one of hold, approve, spam, or trash.', 'mosmcp-abilities' ) );
		}

		$count = get_comments(
			array(
				'status' => $status,
				'count'  => true,
			)
		);

		return array(
			'status' => $status,
			'count'  => (int) $count,
		);
	}

	/**
	 * Returns how many comments a user has authored.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function get_comment_count_by_user( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$id    = isset( $input['id'] ) ? absint( $input['id'] ) : 0;

		if ( $id <= 0 || ! get_userdata( $id ) ) {
			return new WP_Error( 'mosmcp_user_not_found', __( 'No user was found with that ID.', 'mosmcp-abilities' ) );
		}

		$count = get_comments(
			array(
				'user_id' => $id,
				'count'   => true,
			)
		);

		return array(
			'id'    => $id,
			'count' => (int) $count,
		);
	}

	/**
	 * Lists comments authored by a user.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function list_user_authored_comments( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$id    = isset( $input['id'] ) ? absint( $input['id'] ) : 0;

		if ( $id <= 0 || ! get_userdata( $id ) ) {
			return new WP_Error( 'mosmcp_user_not_found', __( 'No user was found with that ID.', 'mosmcp-abilities' ) );
		}

		$paginate = self::paginate_args( $input );

		$comments = get_comments(
			array(
				'user_id' => $id,
				'number'  => $paginate['number'],
				'offset'  => $paginate['offset'],
			)
		);

		return array(
			'comments' => array_values( array_map( array( __CLASS__, 'comment_summary' ), $comments ) ),
			'page'     => $paginate['page'],
			'per_page' => $paginate['per_page'],
		);
	}

	/**
	 * Lists comments in a given status.
	 *
	 * @param array<string, mixed> $input  Ability input.
	 * @param string               $status Comment status.
	 * @return array<string, mixed>
	 */
	private static function list_by_status( $input, $status ) {
		$input    = is_array( $input ) ? $input : array();
		$paginate = self::paginate_args( $input );

		$comments = get_comments(
			array(
				'status' => $status,
				'number' => $paginate['number'],
				'offset' => $paginate['offset'],
			)
		);

		return array(
			'comments' => array_values( array_map( array( __CLASS__, 'comment_summary' ), $comments ) ),
			'page'     => $paginate['page'],
			'per_page' => $paginate['per_page'],
		);
	}

	/**
	 * Resolves and validates the target comment ID.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return int|WP_Error
	 */
	private static function require_comment( $input ) {
		$input = is_array( $input ) ? $input : array();
		$id    = isset( $input['id'] ) ? absint( $input['id'] ) : 0;
		if ( $id <= 0 || ! get_comment( $id ) ) {
			return new WP_Error( 'mosmcp_comment_not_found', __( 'No comment was found with that ID.', 'mosmcp-abilities' ) );
		}
		return $id;
	}

	/**
	 * Standard pagination args (page, per_page clamped to 1..100, number, offset).
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, int>
	 */
	private static function paginate_args( $input ) {
		$page     = isset( $input['page'] ) ? absint( $input['page'] ) : 1;
		$per_page = isset( $input['per_page'] ) ? absint( $input['per_page'] ) : 20;

		if ( $page < 1 ) {
			$page = 1;
		}
		if ( $per_page < 1 ) {
			$per_page = 20;
		}
		if ( $per_page > 100 ) {
			$per_page = 100;
		}

		return array(
			'page'     => $page,
			'per_page' => $per_page,
			'number'   => $per_page,
			'offset'   => ( $page - 1 ) * $per_page,
		);
	}

	/**
	 * Slim comment representation.
	 *
	 * @param \WP_Comment $comment Comment object.
	 * @return array<string, mixed>
	 */
	private static function comment_summary( $comment ) {
		if ( ! $comment instanceof \WP_Comment ) {
			return array();
		}
		return array(
			'id'           => (int) $comment->comment_ID,
			'post_id'      => (int) $comment->comment_post_ID,
			'parent_id'    => (int) $comment->comment_parent,
			'author_name'  => (string) $comment->comment_author,
			'author_email' => (string) $comment->comment_author_email,
			'author_url'   => (string) $comment->comment_author_url,
			'content'      => (string) $comment->comment_content,
			'status'       => (string) wp_get_comment_status( $comment ),
			'date'         => (string) $comment->comment_date,
		);
	}
}
