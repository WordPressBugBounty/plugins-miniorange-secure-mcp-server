<?php
/**
 * Execute callbacks for the core Revisions ability pack.
 *
 * Business logic is preserved from the reviewed source collection; only naming,
 * text domain, and formatting were adapted. Reads verify read_post on the parent;
 * writes verify edit_post on the parent (also enforced by the registrar).
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Core;

use MoSMCP\Abilities\Support\Pagination;
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
 * Class Revisions_Provider
 *
 * Static execute callbacks for revision abilities.
 */
class Revisions_Provider {

	/**
	 * Counts the revisions of a post.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function count_for_post( $input = array() ) {
		$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
		$post    = self::revisionable_post_or_error( $post_id );

		if ( $post instanceof WP_Error ) {
			return $post;
		}

		$info = wp_get_latest_revision_id_and_total_count( $post->ID );

		if ( is_wp_error( $info ) ) {
			return $info;
		}

		$result = array(
			'post_id' => (int) $post->ID,
			'count'   => (int) $info['count'],
		);

		if ( (int) $info['count'] > 0 ) {
			$result['latest_revision_id'] = (int) $info['latest_id'];
		}

		return $result;
	}

	/**
	 * Permanently deletes a single revision.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function delete( $input = array() ) {
		$revision = self::revision_or_error( $input );
		if ( $revision instanceof WP_Error ) {
			return $revision;
		}

		if ( ! current_user_can( 'edit_post', $revision->post_parent ) ) {
			return new WP_Error( 'cannot_edit_post', __( 'You are not allowed to edit the post this revision belongs to.', 'mosmcp-abilities' ) );
		}

		if ( empty( $input['confirm'] ) || true !== $input['confirm'] ) {
			return new WP_Error( 'confirmation_required', __( 'Deleting a revision is irreversible. Set confirm to true to proceed.', 'mosmcp-abilities' ) );
		}

		$post_id = (int) $revision->post_parent;
		$deleted = wp_delete_post( $revision->ID, true );

		if ( ! $deleted ) {
			return new WP_Error( 'delete_failed', __( 'The revision could not be deleted.', 'mosmcp-abilities' ) );
		}

		return array(
			'id'      => (int) $revision->ID,
			'post_id' => $post_id,
			'deleted' => true,
		);
	}

	/**
	 * Gets the most recent revision of a post.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function get_latest( $input = array() ) {
		$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
		$post    = self::revisionable_post_or_error( $post_id );

		if ( $post instanceof WP_Error ) {
			return $post;
		}

		$info = wp_get_latest_revision_id_and_total_count( $post->ID );

		if ( is_wp_error( $info ) ) {
			return $info;
		}

		if ( 0 === (int) $info['count'] ) {
			return new WP_Error( 'no_revisions', __( 'This post has no revisions yet.', 'mosmcp-abilities' ) );
		}

		$revision = get_post( (int) $info['latest_id'] );
		$author   = get_userdata( (int) $revision->post_author );

		return array(
			'id'          => (int) $revision->ID,
			'post_id'     => (int) $revision->post_parent,
			'title'       => (string) $revision->post_title,
			'content'     => (string) $revision->post_content,
			'excerpt'     => (string) $revision->post_excerpt,
			'author_id'   => (int) $revision->post_author,
			'author_name' => $author ? (string) $author->display_name : '',
			'date'        => (string) $revision->post_date,
		);
	}

	/**
	 * Reports whether revisions are enabled and how many are kept.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function get_status( $input = array() ) {
		$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;

		if ( $post_id > 0 ) {
			$post = get_post( $post_id );
			if ( ! $post || ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
				return new WP_Error( 'post_not_found', __( 'No post or page found with that ID.', 'mosmcp-abilities' ) );
			}
		} else {
			$post = (object) array( 'post_type' => 'post' );
		}

		$enabled = wp_revisions_enabled( $post );
		$to_keep = wp_revisions_to_keep( $post );

		$result = array(
			'enabled'   => (bool) $enabled,
			'unlimited' => $enabled && $to_keep < 0,
		);

		if ( $enabled && $to_keep >= 0 ) {
			$result['max_kept'] = (int) $to_keep;
		}

		return $result;
	}

	/**
	 * Gets the full content of one revision by ID.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function get( $input = array() ) {
		$revision = self::revision_or_error( $input );
		if ( $revision instanceof WP_Error ) {
			return $revision;
		}

		$author = get_userdata( (int) $revision->post_author );

		return array(
			'id'          => (int) $revision->ID,
			'post_id'     => (int) $revision->post_parent,
			'title'       => (string) $revision->post_title,
			'content'     => (string) $revision->post_content,
			'excerpt'     => (string) $revision->post_excerpt,
			'author_id'   => (int) $revision->post_author,
			'author_name' => $author ? (string) $author->display_name : '',
			'date'        => (string) $revision->post_date,
			'is_autosave' => false !== strpos( (string) $revision->post_name, '-autosave' ),
		);
	}

	/**
	 * Lists the autosaves of a post.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function list_autosaves( $input = array() ) {
		$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
		$post    = self::revisionable_post_or_error( $post_id );

		if ( $post instanceof WP_Error ) {
			return $post;
		}

		$all = wp_get_post_revisions( $post, array( 'fields' => 'all' ) );

		$autosaves = array();
		foreach ( $all as $revision ) {
			if ( false !== strpos( (string) $revision->post_name, '-autosave' ) ) {
				$autosaves[] = self::format_summary( $revision );
			}
		}

		return array(
			'count'     => count( $autosaves ),
			'autosaves' => $autosaves,
		);
	}

	/**
	 * Lists a post's revisions made by a specific user.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function list_by_author( $input = array() ) {
		$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
		$post    = self::revisionable_post_or_error( $post_id );

		if ( $post instanceof WP_Error ) {
			return $post;
		}

		$author_id = isset( $input['author_id'] ) ? absint( $input['author_id'] ) : 0;

		if ( $author_id <= 0 ) {
			return new WP_Error( 'missing_author', __( 'An author_id is required.', 'mosmcp-abilities' ) );
		}

		$per_page = isset( $input['per_page'] ) ? absint( $input['per_page'] ) : 20;
		$per_page = min( $per_page, Pagination::MAX_PER_PAGE );
		$offset   = isset( $input['offset'] ) ? absint( $input['offset'] ) : 0;

		$all = wp_get_post_revisions(
			$post,
			array(
				'fields' => 'all',
				'author' => $author_id,
			)
		);
		$all = array_values(
			array_filter(
				$all,
				static function ( $revision ) {
					return false === strpos( (string) $revision->post_name, '-autosave' );
				}
			)
		);

		$page = array_slice( $all, $offset, $per_page );

		$revisions = array();
		foreach ( $page as $revision ) {
			$revisions[] = self::format_summary( $revision );
		}

		return array(
			'showing'   => count( $revisions ),
			'total'     => count( $all ),
			'revisions' => $revisions,
		);
	}

	/**
	 * Lists all revisions of a post (excluding autosaves).
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function list_for_post( $input = array() ) {
		$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
		$post    = self::revisionable_post_or_error( $post_id );

		if ( $post instanceof WP_Error ) {
			return $post;
		}

		$per_page = isset( $input['per_page'] ) ? absint( $input['per_page'] ) : 20;
		$per_page = min( $per_page, Pagination::MAX_PER_PAGE );
		$offset   = isset( $input['offset'] ) ? absint( $input['offset'] ) : 0;

		// Exclude autosaves from this list — see revision/list-autosaves for those.
		$all = wp_get_post_revisions( $post, array( 'fields' => 'all' ) );
		$all = array_values(
			array_filter(
				$all,
				static function ( $revision ) {
					return false === strpos( (string) $revision->post_name, '-autosave' );
				}
			)
		);

		$page = array_slice( $all, $offset, $per_page );

		$revisions = array();
		foreach ( $page as $revision ) {
			$revisions[] = self::format_summary( $revision );
		}

		return array(
			'showing'   => count( $revisions ),
			'total'     => count( $all ),
			'revisions' => $revisions,
		);
	}

	/**
	 * Restores a post to a chosen revision.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function restore( $input = array() ) {
		$revision = self::revision_or_error( $input );
		if ( $revision instanceof WP_Error ) {
			return $revision;
		}

		if ( ! current_user_can( 'edit_post', $revision->post_parent ) ) {
			return new WP_Error( 'cannot_edit_post', __( 'You are not allowed to edit the post this revision belongs to.', 'mosmcp-abilities' ) );
		}

		$post_id = wp_restore_post_revision( $revision->ID );

		if ( ! $post_id || is_wp_error( $post_id ) ) {
			return new WP_Error( 'restore_failed', __( 'The revision could not be restored.', 'mosmcp-abilities' ) );
		}

		return array(
			'post_id'                   => (int) $post_id,
			'title'                     => (string) get_the_title( $post_id ),
			'restored_from_revision_id' => (int) $revision->ID,
			'edit_url'                  => (string) admin_url( 'post.php?post=' . $post_id . '&action=edit' ),
		);
	}

	/**
	 * Loads the parent post for a revision request, or returns a WP_Error.
	 *
	 * @param int $post_id Post (or page) ID.
	 * @return \WP_Post|WP_Error
	 */
	private static function revisionable_post_or_error( $post_id ) {
		$post = $post_id > 0 ? get_post( $post_id ) : null;

		if ( ! $post || ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
			return new WP_Error( 'post_not_found', __( 'No post or page found with that ID.', 'mosmcp-abilities' ) );
		}

		if ( ! current_user_can( 'read_post', $post->ID ) ) {
			return new WP_Error( 'cannot_read_post', __( 'You are not allowed to view this post.', 'mosmcp-abilities' ) );
		}

		if ( ! wp_revisions_enabled( $post ) ) {
			return new WP_Error( 'revisions_disabled', __( 'Revisions are not enabled for this post.', 'mosmcp-abilities' ) );
		}

		return $post;
	}

	/**
	 * Loads a revision post for a given revision ID, or returns a WP_Error.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return \WP_Post|WP_Error
	 */
	private static function revision_or_error( $input ) {
		$id       = isset( $input['id'] ) ? absint( $input['id'] ) : 0;
		$revision = $id > 0 ? get_post( $id ) : null;

		if ( ! $revision || 'revision' !== $revision->post_type ) {
			return new WP_Error( 'revision_not_found', __( 'No revision found with that ID.', 'mosmcp-abilities' ) );
		}

		if ( ! current_user_can( 'read_post', $revision->post_parent ) ) {
			return new WP_Error( 'cannot_read_post', __( 'You are not allowed to view this revision.', 'mosmcp-abilities' ) );
		}

		return $revision;
	}

	/**
	 * Formats one revision as a summary list item (no full content).
	 *
	 * @param \WP_Post $revision Revision post object.
	 * @return array<string, mixed>
	 */
	private static function format_summary( $revision ) {
		$author = get_userdata( (int) $revision->post_author );

		return array(
			'id'          => (int) $revision->ID,
			'post_id'     => (int) $revision->post_parent,
			'title'       => (string) $revision->post_title,
			'author_id'   => (int) $revision->post_author,
			'author_name' => $author ? (string) $author->display_name : '',
			'date'        => (string) $revision->post_date,
			'is_autosave' => false !== strpos( (string) $revision->post_name, '-autosave' ),
		);
	}
}
