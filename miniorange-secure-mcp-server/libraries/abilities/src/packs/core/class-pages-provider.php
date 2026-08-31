<?php
/**
 * Execute callbacks for the core Pages ability pack.
 *
 * Business logic is preserved from the reviewed source collection; only naming,
 * text domain, and formatting were adapted. Authorization is enforced by the
 * Ability_Registrar wrapper; these callbacks additionally validate existence and
 * state exactly as written.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Core;

use MoSMCP\Abilities\Packs\Core\Support\Post_Display;
use MoSMCP\Abilities\Packs\Core\Support\Post_Duplicator;
use MoSMCP\Abilities\Packs\Core\Support\Post_Fields;
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
 * Class Pages_Provider
 *
 * Static execute callbacks for page abilities.
 */
class Pages_Provider {

	/**
	 * Post statuses treated as the full set for "any"-style listings.
	 *
	 * @var string[]
	 */
	const ALL_STATUSES = array( 'publish', 'draft', 'pending', 'future', 'private', 'trash' );

	/**
	 * Creates a new draft page, optionally under a parent page.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function create_draft( $input = array() ) {
		$title = isset( $input['title'] ) ? sanitize_text_field( $input['title'] ) : '';

		if ( '' === $title ) {
			return new WP_Error( 'missing_title', __( 'A title is required to create a draft page.', 'mosmcp-abilities' ) );
		}

		$parent_id = isset( $input['parent_id'] ) ? absint( $input['parent_id'] ) : 0;

		if ( $parent_id > 0 ) {
			$parent = get_post( $parent_id );
			if ( ! $parent || 'page' !== $parent->post_type ) {
				return new WP_Error( 'parent_not_found', __( 'No page found with the given parent_id.', 'mosmcp-abilities' ) );
			}
		}

		$postarr = array(
			'post_type'    => 'page',
			'post_status'  => 'draft',
			'post_title'   => $title,
			'post_content' => isset( $input['content'] ) ? wp_kses_post( $input['content'] ) : '',
			'post_parent'  => $parent_id,
			'post_author'  => get_current_user_id(),
		);

		if ( isset( $input['slug'] ) ) {
			$slug = Post_Fields::prepare_slug( 'page', (string) $input['slug'] );
			if ( is_wp_error( $slug ) ) {
				return $slug;
			}
			$postarr['post_name'] = $slug;
		}

		$page_id = wp_insert_post( $postarr, true );

		if ( is_wp_error( $page_id ) ) {
			return $page_id;
		}

		return array(
			'id'        => (int) $page_id,
			'title'     => (string) get_the_title( $page_id ),
			'slug'      => (string) get_post_field( 'post_name', $page_id ),
			'status'    => (string) get_post_status( $page_id ),
			'parent_id' => $parent_id,
			'edit_url'  => self::edit_url( (int) $page_id ),
		);
	}

	/**
	 * Edits the title and/or content of any page the current user may edit.
	 *
	 * Unlike update_own() this places no author restriction on the page: the
	 * object-level 'edit_page' gate applied by the Ability_Registrar is the whole
	 * authorization story, so map_meta_cap decides via edit_others_pages and
	 * edit_published_pages exactly as the block editor would.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function update( $input = array() ) {
		$page = self::require_page( $input );
		if ( $page instanceof WP_Error ) {
			return $page;
		}

		return self::apply_update( $page, $input );
	}

	/**
	 * Duplicates a page together with the metadata that carries its appearance.
	 *
	 * Delegates to the shared duplicator so posts and pages behave identically;
	 * only the post type differs.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function duplicate( $input = array() ) {
		return Post_Duplicator::duplicate( (array) $input, 'page' );
	}

	/**
	 * Sets or clears the page's featured image.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function set_featured_image( $input = array() ) {
		return Post_Display::set_featured_image( (array) $input, 'page' );
	}

	/**
	 * Reports the page's template and per-page display settings.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function template_get( $input = array() ) {
		return Post_Display::template_get( (array) $input, 'page' );
	}

	/**
	 * Assigns a page template to the page.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function template_set( $input = array() ) {
		return Post_Display::template_set( (array) $input, 'page' );
	}

	/**
	 * Edits the title and/or content of a page authored by the current user.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function update_own( $input = array() ) {
		$page = self::require_page( $input );
		if ( $page instanceof WP_Error ) {
			return $page;
		}

		if ( get_current_user_id() !== (int) $page->post_author ) {
			return new WP_Error( 'not_own_page', __( 'This ability can only edit pages you authored yourself.', 'mosmcp-abilities' ) );
		}

		return self::apply_update( $page, $input );
	}

	/**
	 * Writes the title and/or content fields from input onto an already-resolved page.
	 *
	 * Shared by update() and update_own() so the two abilities differ only in the
	 * authorship rule each applies before calling this.
	 *
	 * @param \WP_Post             $page  The page to edit.
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function apply_update( $page, $input ) {
		/*
		 * Delegated so posts, pages and custom types cannot drift apart. See the
		 * matching note in Posts_Provider for why support checking stays off for the
		 * built-in types.
		 */
		return Post_Fields::apply_update( $page, (array) $input, false );
	}

	/**
	 * Publishes a draft or pending page.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function publish( $input = array() ) {
		$page = self::require_page( $input );
		if ( $page instanceof WP_Error ) {
			return $page;
		}

		$previous_status = (string) $page->post_status;

		if ( 'publish' === $previous_status ) {
			return new WP_Error( 'already_published', __( 'This page is already published.', 'mosmcp-abilities' ) );
		}

		if ( ! in_array( $previous_status, array( 'draft', 'pending' ), true ) ) {
			return new WP_Error(
				'invalid_status',
				sprintf(
					/* translators: %s: current page status. */
					__( 'Only draft or pending pages can be published. This page has status "%s".', 'mosmcp-abilities' ),
					$previous_status
				)
			);
		}

		$result = wp_update_post(
			array(
				'ID'          => $page->ID,
				'post_status' => 'publish',
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'id'              => (int) $page->ID,
			'title'           => (string) get_the_title( $page->ID ),
			'previous_status' => $previous_status,
			'new_status'      => (string) get_post_status( $page->ID ),
			'link'            => (string) get_permalink( $page->ID ),
			'edit_url'        => self::edit_url( (int) $page->ID ),
		);
	}

	/**
	 * Reverts a published page to draft.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function unpublish( $input = array() ) {
		$page = self::require_page( $input );
		if ( $page instanceof WP_Error ) {
			return $page;
		}

		$previous_status = (string) $page->post_status;

		if ( 'publish' !== $previous_status ) {
			return new WP_Error(
				'not_published',
				sprintf(
					/* translators: %s: current page status. */
					__( 'Only published pages can be unpublished. This page has status "%s".', 'mosmcp-abilities' ),
					$previous_status
				)
			);
		}

		$result = wp_update_post(
			array(
				'ID'          => $page->ID,
				'post_status' => 'draft',
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'id'              => (int) $page->ID,
			'title'           => (string) get_the_title( $page->ID ),
			'previous_status' => $previous_status,
			'new_status'      => (string) get_post_status( $page->ID ),
			'edit_url'        => self::edit_url( (int) $page->ID ),
		);
	}

	/**
	 * Schedules a draft or pending page to publish at a future date.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function schedule( $input = array() ) {
		$page = self::require_page( $input );
		if ( $page instanceof WP_Error ) {
			return $page;
		}

		$previous_status = (string) $page->post_status;

		if ( ! in_array( $previous_status, array( 'draft', 'pending', 'future' ), true ) ) {
			return new WP_Error(
				'invalid_status',
				sprintf(
					/* translators: %s: current page status. */
					__( 'Only draft, pending, or already-scheduled pages can be scheduled. This page has status "%s".', 'mosmcp-abilities' ),
					$previous_status
				)
			);
		}

		$date = isset( $input['date'] ) ? trim( (string) $input['date'] ) : '';

		// Accept "YYYY-MM-DD HH:MM" and normalize to "YYYY-MM-DD HH:MM:SS".
		if ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $date ) ) {
			$date .= ':00';
		}

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $date ) ) {
			return new WP_Error( 'invalid_date', __( 'Invalid date format. Use YYYY-MM-DD HH:MM:SS in the site timezone.', 'mosmcp-abilities' ) );
		}

		$gmt_timestamp = (int) get_gmt_from_date( $date, 'U' );

		if ( $gmt_timestamp <= 0 ) {
			return new WP_Error( 'invalid_date', __( 'That date could not be parsed. Use YYYY-MM-DD HH:MM:SS in the site timezone.', 'mosmcp-abilities' ) );
		}

		if ( $gmt_timestamp <= time() ) {
			return new WP_Error( 'date_not_future', __( 'The scheduled date must be in the future.', 'mosmcp-abilities' ) );
		}

		$result = wp_update_post(
			array(
				'ID'            => $page->ID,
				'post_status'   => 'future',
				'post_date'     => $date,
				'post_date_gmt' => get_gmt_from_date( $date ),
				'edit_date'     => true,
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$updated = get_post( $page->ID );

		return array(
			'id'              => (int) $updated->ID,
			'title'           => (string) get_the_title( $updated ),
			'previous_status' => $previous_status,
			'new_status'      => (string) $updated->post_status,
			'scheduled_for'   => (string) $updated->post_date,
			'edit_url'        => self::edit_url( (int) $updated->ID ),
		);
	}

	/**
	 * Sets a page to private visibility.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function set_private( $input = array() ) {
		$page = self::require_page( $input );
		if ( $page instanceof WP_Error ) {
			return $page;
		}

		$previous_status = (string) $page->post_status;

		if ( 'private' === $previous_status ) {
			return new WP_Error( 'already_private', __( 'This page is already private.', 'mosmcp-abilities' ) );
		}

		if ( 'trash' === $previous_status ) {
			return new WP_Error( 'page_trashed', __( 'This page is in the trash. Restore it before changing its visibility.', 'mosmcp-abilities' ) );
		}

		$result = wp_update_post(
			array(
				'ID'          => $page->ID,
				'post_status' => 'private',
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'id'              => (int) $page->ID,
			'title'           => (string) get_the_title( $page->ID ),
			'previous_status' => $previous_status,
			'new_status'      => (string) get_post_status( $page->ID ),
			'edit_url'        => self::edit_url( (int) $page->ID ),
		);
	}

	/**
	 * Submits a draft page for review (pending).
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function set_pending( $input = array() ) {
		$page = self::require_page( $input );
		if ( $page instanceof WP_Error ) {
			return $page;
		}

		$previous_status = (string) $page->post_status;

		if ( 'pending' === $previous_status ) {
			return new WP_Error( 'already_pending', __( 'This page is already pending review.', 'mosmcp-abilities' ) );
		}

		if ( 'draft' !== $previous_status ) {
			return new WP_Error(
				'invalid_status',
				sprintf(
					/* translators: %s: current page status. */
					__( 'Only draft pages can be set to pending review. This page has status "%s".', 'mosmcp-abilities' ),
					$previous_status
				)
			);
		}

		$result = wp_update_post(
			array(
				'ID'          => $page->ID,
				'post_status' => 'pending',
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'id'              => (int) $page->ID,
			'title'           => (string) get_the_title( $page->ID ),
			'previous_status' => $previous_status,
			'new_status'      => (string) get_post_status( $page->ID ),
			'edit_url'        => self::edit_url( (int) $page->ID ),
		);
	}

	/**
	 * Moves a page to the trash.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function trash( $input = array() ) {
		$page = self::require_page( $input );
		if ( $page instanceof WP_Error ) {
			return $page;
		}

		$previous_status = (string) $page->post_status;

		if ( 'trash' === $previous_status ) {
			return new WP_Error( 'already_trashed', __( 'This page is already in the trash.', 'mosmcp-abilities' ) );
		}

		$trashed = wp_trash_post( $page->ID );

		if ( ! $trashed ) {
			return new WP_Error( 'trash_failed', __( 'The page could not be moved to the trash.', 'mosmcp-abilities' ) );
		}

		return array(
			'id'              => (int) $page->ID,
			'title'           => (string) get_the_title( $page->ID ),
			'previous_status' => $previous_status,
			'new_status'      => (string) get_post_status( $page->ID ),
			'restorable'      => true,
		);
	}

	/**
	 * Restores a page from the trash.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function restore( $input = array() ) {
		$page = self::require_page( $input );
		if ( $page instanceof WP_Error ) {
			return $page;
		}

		if ( 'trash' !== $page->post_status ) {
			return new WP_Error(
				'not_in_trash',
				sprintf(
					/* translators: %s: current page status. */
					__( 'Only trashed pages can be restored. This page has status "%s".', 'mosmcp-abilities' ),
					$page->post_status
				)
			);
		}

		$restored = wp_untrash_post( $page->ID );

		if ( ! $restored ) {
			return new WP_Error( 'restore_failed', __( 'The page could not be restored from the trash.', 'mosmcp-abilities' ) );
		}

		return array(
			'id'         => (int) $page->ID,
			'title'      => (string) get_the_title( $page->ID ),
			'new_status' => (string) get_post_status( $page->ID ),
			'edit_url'   => self::edit_url( (int) $page->ID ),
		);
	}

	/**
	 * Permanently deletes a page. Requires explicit confirmation.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function delete_permanently( $input = array() ) {
		$page = self::require_page( $input );
		if ( $page instanceof WP_Error ) {
			return $page;
		}

		if ( empty( $input['confirm'] ) || true !== $input['confirm'] ) {
			return new WP_Error(
				'confirmation_required',
				__( 'Permanent deletion is irreversible. Set confirm to true to proceed.', 'mosmcp-abilities' )
			);
		}

		$title   = (string) get_the_title( $page->ID );
		$deleted = wp_delete_post( $page->ID, true );

		if ( ! $deleted ) {
			return new WP_Error( 'delete_failed', __( 'The page could not be deleted.', 'mosmcp-abilities' ) );
		}

		return array(
			'id'      => (int) $page->ID,
			'title'   => $title,
			'deleted' => true,
		);
	}

	/**
	 * Finds pages by title/name, or looks up a page by ID.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function find( $input = array() ) {
		$id     = isset( $input['id'] ) ? absint( $input['id'] ) : 0;
		$search = isset( $input['search'] ) ? trim( (string) $input['search'] ) : '';

		if ( $id <= 0 && '' === $search ) {
			return new WP_Error( 'missing_input', __( 'Provide either "search" (a page title/name) or "id" (a page ID).', 'mosmcp-abilities' ) );
		}

		if ( $id > 0 ) {
			$page = get_post( $id );

			if ( ! $page || 'page' !== $page->post_type ) {
				return array(
					'showing' => 0,
					'total'   => 0,
					'matches' => array(),
				);
			}

			return array(
				'showing' => 1,
				'total'   => 1,
				'matches' => array( self::format_find_match( $page ) ),
			);
		}

		$args = array(
			'post_type'      => 'page',
			'post_status'    => self::ALL_STATUSES,
			's'              => $search,
			'search_columns' => array( 'post_title' ),
			'posts_per_page' => 10,
			'orderby'        => 'modified',
			'order'          => 'DESC',
		);

		if ( ! current_user_can( 'edit_others_pages' ) ) {
			$args['author'] = get_current_user_id();
		}

		$query = new WP_Query( $args );

		$matches = array();
		foreach ( $query->posts as $page ) {
			$matches[] = self::format_find_match( $page );
		}

		return array(
			'showing' => count( $matches ),
			'total'   => (int) $query->found_posts,
			'matches' => $matches,
		);
	}

	/**
	 * Gets the full details of a single page by ID.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function get( $input = array() ) {
		$page = self::require_page( $input );
		if ( $page instanceof WP_Error ) {
			return $page;
		}

		// read_page handles every status: published, private, draft, pending, future.
		if ( ! current_user_can( 'read_page', $page->ID ) ) {
			return new WP_Error( 'cannot_read_page', __( 'You are not allowed to read this page.', 'mosmcp-abilities' ) );
		}

		$author = get_userdata( (int) $page->post_author );

		return array(
			'id'           => (int) $page->ID,
			'title'        => (string) get_the_title( $page ),
			'content'      => (string) $page->post_content,
			'excerpt'      => (string) wp_trim_words( $page->post_content, 40 ),
			'status'       => (string) $page->post_status,
			'author_id'    => (int) $page->post_author,
			'author_name'  => $author ? (string) $author->display_name : '',
			'created'      => (string) $page->post_date,
			'modified'     => (string) $page->post_modified,
			'link'         => (string) get_permalink( $page ),
			'edit_url'     => self::edit_url( (int) $page->ID ),
			'parent_id'    => (int) $page->post_parent,
			'parent_title' => $page->post_parent ? (string) get_the_title( $page->post_parent ) : '',
		);
	}

	/**
	 * Lists pages of any status, with an optional status filter.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function list_all( $input = array() ) {
		$status = isset( $input['status'] ) ? sanitize_key( $input['status'] ) : 'any';

		if ( 'any' !== $status && ! in_array( $status, self::ALL_STATUSES, true ) ) {
			return new WP_Error( 'invalid_status', __( 'Invalid status filter.', 'mosmcp-abilities' ) );
		}

		$args = self::list_args(
			$input,
			array(
				'post_status' => ( 'any' === $status ) ? self::ALL_STATUSES : $status,
				'orderby'     => 'modified',
			)
		);
		self::scope_to_author( $args );

		$query = new WP_Query( $args );

		$pages = array();
		foreach ( $query->posts as $page ) {
			$pages[] = array(
				'id'          => (int) $page->ID,
				'title'       => (string) get_the_title( $page ),
				'status'      => (string) $page->post_status,
				'author_name' => self::author_name( $page ),
				'date'        => (string) $page->post_date,
				'modified'    => (string) $page->post_modified,
				'edit_url'    => self::edit_url( (int) $page->ID ),
			);
		}

		return self::list_result( $pages, $query );
	}

	/**
	 * Lists draft pages from every author.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function list_drafts( $input = array() ) {
		$args = self::list_args(
			$input,
			array(
				'post_status' => 'draft',
				'orderby'     => 'modified',
			)
		);
		self::scope_to_author( $args );

		$query = new WP_Query( $args );

		$drafts = array();
		foreach ( $query->posts as $page ) {
			$drafts[] = array(
				'id'          => (int) $page->ID,
				'title'       => (string) get_the_title( $page ),
				'author_name' => self::author_name( $page ),
				'excerpt'     => (string) wp_trim_words( $page->post_content, 20 ),
				'modified'    => (string) $page->post_modified,
				'edit_url'    => self::edit_url( (int) $page->ID ),
			);
		}

		return self::list_result( $drafts, $query, 'drafts' );
	}

	/**
	 * Lists draft pages authored by the current user only.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function list_own_drafts( $input = array() ) {
		$user_id = get_current_user_id();

		if ( 0 === $user_id ) {
			return new WP_Error( 'not_logged_in', __( 'You must be logged in to list your draft pages.', 'mosmcp-abilities' ) );
		}

		$args           = self::list_args(
			$input,
			array(
				'post_status' => 'draft',
				'orderby'     => 'modified',
			)
		);
		$args['author'] = $user_id;

		$query = new WP_Query( $args );

		$drafts = array();
		foreach ( $query->posts as $page ) {
			$drafts[] = array(
				'id'       => (int) $page->ID,
				'title'    => (string) get_the_title( $page ),
				'excerpt'  => (string) wp_trim_words( $page->post_content, 20 ),
				'modified' => (string) $page->post_modified,
				'edit_url' => self::edit_url( (int) $page->ID ),
			);
		}

		return self::list_result( $drafts, $query, 'drafts' );
	}

	/**
	 * Lists pages pending review.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function list_pending( $input = array() ) {
		$args = self::list_args(
			$input,
			array(
				'post_status' => 'pending',
				'orderby'     => 'modified',
			)
		);
		self::scope_to_author( $args );

		$query = new WP_Query( $args );

		$pages = array();
		foreach ( $query->posts as $page ) {
			$pages[] = array(
				'id'          => (int) $page->ID,
				'title'       => (string) get_the_title( $page ),
				'author_name' => self::author_name( $page ),
				'excerpt'     => (string) wp_trim_words( $page->post_content, 20 ),
				'modified'    => (string) $page->post_modified,
				'edit_url'    => self::edit_url( (int) $page->ID ),
			);
		}

		return self::list_result( $pages, $query );
	}

	/**
	 * Lists private pages.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function list_private( $input = array() ) {
		$args = self::list_args(
			$input,
			array(
				'post_status' => 'private',
				'orderby'     => 'modified',
			)
		);

		$query = new WP_Query( $args );

		$pages = array();
		foreach ( $query->posts as $page ) {
			$pages[] = array(
				'id'       => (int) $page->ID,
				'title'    => (string) get_the_title( $page ),
				'excerpt'  => (string) wp_trim_words( $page->post_content, 20 ),
				'modified' => (string) $page->post_modified,
				'edit_url' => self::edit_url( (int) $page->ID ),
			);
		}

		return self::list_result( $pages, $query );
	}

	/**
	 * Lists published pages, newest first.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function list_published( $input = array() ) {
		$args = self::list_args(
			$input,
			array(
				'post_status' => 'publish',
				'orderby'     => 'date',
			)
		);

		$query = new WP_Query( $args );

		$pages = array();
		foreach ( $query->posts as $page ) {
			$pages[] = array(
				'id'          => (int) $page->ID,
				'title'       => (string) get_the_title( $page ),
				'author_name' => self::author_name( $page ),
				'excerpt'     => (string) wp_trim_words( $page->post_content, 20 ),
				'date'        => (string) $page->post_date,
				'link'        => (string) get_permalink( $page ),
				'edit_url'    => self::edit_url( (int) $page->ID ),
			);
		}

		return self::list_result( $pages, $query );
	}

	/**
	 * Lists pages scheduled for future publishing.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function list_scheduled( $input = array() ) {
		$args          = self::list_args(
			$input,
			array(
				'post_status' => 'future',
				'orderby'     => 'date',
			)
		);
		$args['order'] = 'ASC';
		self::scope_to_author( $args );

		$query = new WP_Query( $args );

		$pages = array();
		foreach ( $query->posts as $page ) {
			$pages[] = array(
				'id'            => (int) $page->ID,
				'title'         => (string) get_the_title( $page ),
				'scheduled_for' => (string) $page->post_date,
				'edit_url'      => self::edit_url( (int) $page->ID ),
			);
		}

		return self::list_result( $pages, $query );
	}

	/**
	 * Lists trashed pages.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function list_trash( $input = array() ) {
		$args = self::list_args(
			$input,
			array(
				'post_status' => 'trash',
				'orderby'     => 'modified',
			)
		);
		self::scope_to_author( $args );

		$query = new WP_Query( $args );

		$pages = array();
		foreach ( $query->posts as $page ) {
			$previous_status = (string) get_post_meta( $page->ID, '_wp_trash_meta_status', true );
			$pages[]         = array(
				'id'              => (int) $page->ID,
				'title'           => (string) get_the_title( $page ),
				'author_name'     => self::author_name( $page ),
				'previous_status' => '' !== $previous_status ? $previous_status : 'draft',
				'trashed_on'      => (string) $page->post_modified,
				'restorable'      => true,
			);
		}

		return self::list_result( $pages, $query );
	}

	/**
	 * Resolves and validates the target page from input.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return \WP_Post|WP_Error
	 */
	private static function require_page( $input ) {
		$id   = isset( $input['id'] ) ? absint( $input['id'] ) : 0;
		$page = get_post( $id );

		if ( ! $page || 'page' !== $page->post_type ) {
			return new WP_Error( 'page_not_found', __( 'No page found with that ID.', 'mosmcp-abilities' ) );
		}

		return $page;
	}

	/**
	 * Builds base WP_Query args from pagination input plus per-list overrides.
	 *
	 * @param array<string, mixed> $input     Ability input.
	 * @param array<string, mixed> $overrides Query args to merge on top.
	 * @return array<string, mixed>
	 */
	private static function list_args( $input, array $overrides ) {
		$per_page = isset( $input['per_page'] ) ? absint( $input['per_page'] ) : 20;
		$offset   = isset( $input['offset'] ) ? absint( $input['offset'] ) : 0;

		return array_merge(
			array(
				'post_type'      => 'page',
				'posts_per_page' => $per_page,
				'offset'         => $offset,
				'order'          => 'DESC',
			),
			$overrides
		);
	}

	/**
	 * Restricts a query to the current user's own pages when they cannot edit others'.
	 *
	 * @param array<string, mixed> $args Query args, modified in place.
	 * @return void
	 */
	private static function scope_to_author( array &$args ) {
		if ( ! current_user_can( 'edit_others_pages' ) ) {
			$args['author'] = get_current_user_id();
		}
	}

	/**
	 * Assembles the standard { showing, total, <key> } list envelope.
	 *
	 * @param array<int, array<string, mixed>> $items Result rows.
	 * @param WP_Query                         $query Executed query.
	 * @param string                           $key   Output key for the rows.
	 * @return array<string, mixed>
	 */
	private static function list_result( array $items, WP_Query $query, $key = 'pages' ) {
		return array(
			'showing' => count( $items ),
			'total'   => (int) $query->found_posts,
			$key      => $items,
		);
	}

	/**
	 * Formats a single search match.
	 *
	 * @param \WP_Post $page Page object.
	 * @return array<string, mixed>
	 */
	private static function format_find_match( $page ) {
		return array(
			'id'          => (int) $page->ID,
			'title'       => (string) get_the_title( $page ),
			'status'      => (string) $page->post_status,
			'author_name' => self::author_name( $page ),
			'date'        => (string) $page->post_date,
			'edit_url'    => self::edit_url( (int) $page->ID ),
		);
	}

	/**
	 * Display name of a page's author, or an empty string.
	 *
	 * @param \WP_Post $page Page object.
	 * @return string
	 */
	private static function author_name( $page ) {
		$author = get_userdata( (int) $page->post_author );
		return $author ? (string) $author->display_name : '';
	}

	/**
	 * Admin edit URL for a page.
	 *
	 * @param int $page_id Page ID.
	 * @return string
	 */
	private static function edit_url( $page_id ) {
		return (string) admin_url( 'post.php?post=' . $page_id . '&action=edit' );
	}
}
