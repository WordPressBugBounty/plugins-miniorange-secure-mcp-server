<?php
/**
 * Execute callbacks for the core Posts ability pack.
 *
 * The business logic here is preserved from the reviewed source collection; only
 * naming, text domain, and formatting were adapted. Authorization is enforced by
 * the Ability_Registrar wrapper (capability gate); these callbacks additionally
 * validate existence and state, exactly as written.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Core;

use MoSMCP\Abilities\Packs\Core\Support\Post_Display;
use MoSMCP\Abilities\Packs\Core\Support\Post_Duplicator;
use MoSMCP\Abilities\Packs\Core\Support\Post_Fields;
use MoSMCP\Abilities\Packs\Core\Support\Post_Meta_Diff;
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

/*
 * These abilities intentionally query by post/user/comment meta or taxonomy
 * (and exclude specific IDs) — that is the tool surface the library exposes.
 * The queries are bounded and parameterized, so this performance advisory is
 * accepted here (the sniff is not part of the library's own phpcs.xml.dist; this
 * directive covers Plugin Check, which enforces its own broader standard).
 */
// phpcs:disable WordPress.DB.SlowDBQuery

/**
 * Class Posts_Provider
 *
 * Static execute callbacks for post abilities. Each returns an array on success
 * or a WP_Error on failure (surfaced to the MCP client and the activity log).
 */
class Posts_Provider {

	/**
	 * Post statuses treated as the full set for "any"-style listings.
	 *
	 * @var string[]
	 */
	const ALL_STATUSES = array( 'publish', 'draft', 'pending', 'future', 'private', 'trash' );

	/**
	 * Creates a new draft post authored by the current user.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function create_draft( $input = array() ) {
		$title = isset( $input['title'] ) ? sanitize_text_field( $input['title'] ) : '';

		if ( '' === $title ) {
			return new WP_Error( 'missing_title', __( 'A title is required to create a draft.', 'mosmcp-abilities' ) );
		}

		$postarr = array(
			'post_type'    => 'post',
			'post_status'  => 'draft',
			'post_title'   => $title,
			'post_content' => isset( $input['content'] ) ? wp_kses_post( $input['content'] ) : '',
			'post_excerpt' => isset( $input['excerpt'] ) ? sanitize_text_field( $input['excerpt'] ) : '',
			'post_author'  => get_current_user_id(),
		);

		if ( isset( $input['slug'] ) ) {
			$slug = Post_Fields::prepare_slug( 'post', (string) $input['slug'] );
			if ( is_wp_error( $slug ) ) {
				return $slug;
			}
			$postarr['post_name'] = $slug;
		}

		$post_id = wp_insert_post( $postarr, true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		return array(
			'id'       => (int) $post_id,
			'title'    => (string) get_the_title( $post_id ),
			'slug'     => (string) get_post_field( 'post_name', $post_id ),
			'status'   => (string) get_post_status( $post_id ),
			'edit_url' => self::edit_url( (int) $post_id ),
		);
	}

	/**
	 * Duplicates a post together with the metadata that carries its appearance.
	 *
	 * Delegates to the shared duplicator so posts and pages behave identically;
	 * only the post type differs.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function duplicate( $input = array() ) {
		return Post_Duplicator::duplicate( (array) $input, 'post' );
	}

	/**
	 * Edits the title, content and/or excerpt of any post the user may edit.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function update( $input = array() ) {
		$post = self::require_post( $input );
		if ( $post instanceof WP_Error ) {
			return $post;
		}

		return self::apply_field_update( $post, (array) $input );
	}

	/**
	 * Sets or clears the post's featured image.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function set_featured_image( $input = array() ) {
		return Post_Display::set_featured_image( (array) $input, 'post' );
	}

	/**
	 * Reports the post's template and per-post display settings.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function template_get( $input = array() ) {
		return Post_Display::template_get( (array) $input, 'post' );
	}

	/**
	 * Assigns a page template to the post.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function template_set( $input = array() ) {
		return Post_Display::template_set( (array) $input, 'post' );
	}

	/**
	 * Compares the stored settings of two posts or pages.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function compare_meta( $input = array() ) {
		return Post_Meta_Diff::compare( (array) $input );
	}

	/**
	 * Applies a title/content/excerpt update and reports which fields changed.
	 *
	 * Shared by post-update and post-update-own so the two cannot drift apart;
	 * they differ only in the ownership check their callers apply first.
	 *
	 * @param \WP_Post             $post  Target post.
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function apply_field_update( $post, array $input ) {
		/*
		 * Delegated so posts, pages and custom types cannot drift apart in what they
		 * accept or how they sanitise it. Support checking is deliberately off here:
		 * WordPress stores and themes read post_excerpt on posts and pages whether or
		 * not the type declares editor support for it, so refusing on that basis
		 * would break behaviour these abilities have always had. For an arbitrary
		 * custom type the support flag is the only signal available, so the custom
		 * content pack turns it on.
		 */
		return Post_Fields::apply_update( $post, $input, false );
	}

	/**
	 * Edits the title and/or content of a post authored by the current user.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function update_own( $input = array() ) {
		$post = self::require_post( $input );
		if ( $post instanceof WP_Error ) {
			return $post;
		}

		if ( get_current_user_id() !== (int) $post->post_author ) {
			return new WP_Error( 'not_own_post', __( 'This ability can only edit posts you authored yourself.', 'mosmcp-abilities' ) );
		}

		return self::apply_field_update( $post, (array) $input );
	}

	/**
	 * Publishes a draft or pending post.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function publish( $input = array() ) {
		$post = self::require_post( $input );
		if ( $post instanceof WP_Error ) {
			return $post;
		}

		$previous_status = (string) $post->post_status;

		if ( 'publish' === $previous_status ) {
			return new WP_Error( 'already_published', __( 'This post is already published.', 'mosmcp-abilities' ) );
		}

		if ( ! in_array( $previous_status, array( 'draft', 'pending' ), true ) ) {
			return new WP_Error(
				'invalid_status',
				sprintf(
					/* translators: %s: current post status. */
					__( 'Only draft or pending posts can be published. This post has status "%s".', 'mosmcp-abilities' ),
					$previous_status
				)
			);
		}

		$result = wp_update_post(
			array(
				'ID'          => $post->ID,
				'post_status' => 'publish',
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'id'              => (int) $post->ID,
			'title'           => (string) get_the_title( $post->ID ),
			'previous_status' => $previous_status,
			'new_status'      => (string) get_post_status( $post->ID ),
			'link'            => (string) get_permalink( $post->ID ),
			'edit_url'        => self::edit_url( (int) $post->ID ),
		);
	}

	/**
	 * Reverts a published post to draft.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function unpublish( $input = array() ) {
		$post = self::require_post( $input );
		if ( $post instanceof WP_Error ) {
			return $post;
		}

		$previous_status = (string) $post->post_status;

		if ( 'publish' !== $previous_status ) {
			return new WP_Error(
				'not_published',
				sprintf(
					/* translators: %s: current post status. */
					__( 'Only published posts can be unpublished. This post has status "%s".', 'mosmcp-abilities' ),
					$previous_status
				)
			);
		}

		$result = wp_update_post(
			array(
				'ID'          => $post->ID,
				'post_status' => 'draft',
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'id'              => (int) $post->ID,
			'title'           => (string) get_the_title( $post->ID ),
			'previous_status' => $previous_status,
			'new_status'      => (string) get_post_status( $post->ID ),
			'edit_url'        => self::edit_url( (int) $post->ID ),
		);
	}

	/**
	 * Schedules a draft or pending post to publish at a future date.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function schedule( $input = array() ) {
		$post = self::require_post( $input );
		if ( $post instanceof WP_Error ) {
			return $post;
		}

		$previous_status = (string) $post->post_status;

		if ( ! in_array( $previous_status, array( 'draft', 'pending', 'future' ), true ) ) {
			return new WP_Error(
				'invalid_status',
				sprintf(
					/* translators: %s: current post status. */
					__( 'Only draft, pending, or already-scheduled posts can be scheduled. This post has status "%s".', 'mosmcp-abilities' ),
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
				'ID'            => $post->ID,
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

		$updated = get_post( $post->ID );

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
	 * Sets a post to private visibility.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function set_private( $input = array() ) {
		$post = self::require_post( $input );
		if ( $post instanceof WP_Error ) {
			return $post;
		}

		$previous_status = (string) $post->post_status;

		if ( 'private' === $previous_status ) {
			return new WP_Error( 'already_private', __( 'This post is already private.', 'mosmcp-abilities' ) );
		}

		if ( 'trash' === $previous_status ) {
			return new WP_Error( 'post_trashed', __( 'This post is in the trash. Restore it before changing its visibility.', 'mosmcp-abilities' ) );
		}

		$result = wp_update_post(
			array(
				'ID'          => $post->ID,
				'post_status' => 'private',
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'id'              => (int) $post->ID,
			'title'           => (string) get_the_title( $post->ID ),
			'previous_status' => $previous_status,
			'new_status'      => (string) get_post_status( $post->ID ),
			'edit_url'        => self::edit_url( (int) $post->ID ),
		);
	}

	/**
	 * Submits a draft post for review (pending).
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function set_pending( $input = array() ) {
		$post = self::require_post( $input );
		if ( $post instanceof WP_Error ) {
			return $post;
		}

		$previous_status = (string) $post->post_status;

		if ( 'pending' === $previous_status ) {
			return new WP_Error( 'already_pending', __( 'This post is already pending review.', 'mosmcp-abilities' ) );
		}

		if ( 'draft' !== $previous_status ) {
			return new WP_Error(
				'invalid_status',
				sprintf(
					/* translators: %s: current post status. */
					__( 'Only draft posts can be set to pending review. This post has status "%s".', 'mosmcp-abilities' ),
					$previous_status
				)
			);
		}

		$result = wp_update_post(
			array(
				'ID'          => $post->ID,
				'post_status' => 'pending',
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'id'              => (int) $post->ID,
			'title'           => (string) get_the_title( $post->ID ),
			'previous_status' => $previous_status,
			'new_status'      => (string) get_post_status( $post->ID ),
			'edit_url'        => self::edit_url( (int) $post->ID ),
		);
	}

	/**
	 * Moves a post to the trash.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function trash( $input = array() ) {
		$post = self::require_post( $input );
		if ( $post instanceof WP_Error ) {
			return $post;
		}

		$previous_status = (string) $post->post_status;

		if ( 'trash' === $previous_status ) {
			return new WP_Error( 'already_trashed', __( 'This post is already in the trash.', 'mosmcp-abilities' ) );
		}

		$trashed = wp_trash_post( $post->ID );

		if ( ! $trashed ) {
			return new WP_Error( 'trash_failed', __( 'The post could not be moved to the trash.', 'mosmcp-abilities' ) );
		}

		return array(
			'id'              => (int) $post->ID,
			'title'           => (string) get_the_title( $post->ID ),
			'previous_status' => $previous_status,
			'new_status'      => (string) get_post_status( $post->ID ),
			'restorable'      => true,
		);
	}

	/**
	 * Restores a post from the trash.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function restore( $input = array() ) {
		$post = self::require_post( $input );
		if ( $post instanceof WP_Error ) {
			return $post;
		}

		if ( 'trash' !== $post->post_status ) {
			return new WP_Error(
				'not_in_trash',
				sprintf(
					/* translators: %s: current post status. */
					__( 'Only trashed posts can be restored. This post has status "%s".', 'mosmcp-abilities' ),
					$post->post_status
				)
			);
		}

		$restored = wp_untrash_post( $post->ID );

		if ( ! $restored ) {
			return new WP_Error( 'restore_failed', __( 'The post could not be restored from the trash.', 'mosmcp-abilities' ) );
		}

		return array(
			'id'         => (int) $post->ID,
			'title'      => (string) get_the_title( $post->ID ),
			'new_status' => (string) get_post_status( $post->ID ),
			'edit_url'   => self::edit_url( (int) $post->ID ),
		);
	}

	/**
	 * Permanently deletes a post. Requires explicit confirmation.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function delete_permanently( $input = array() ) {
		$post = self::require_post( $input );
		if ( $post instanceof WP_Error ) {
			return $post;
		}

		if ( empty( $input['confirm'] ) || true !== $input['confirm'] ) {
			return new WP_Error(
				'confirmation_required',
				__( 'Permanent deletion is irreversible. Set confirm to true to proceed.', 'mosmcp-abilities' )
			);
		}

		$title   = (string) get_the_title( $post->ID );
		$deleted = wp_delete_post( $post->ID, true );

		if ( ! $deleted ) {
			return new WP_Error( 'delete_failed', __( 'The post could not be deleted.', 'mosmcp-abilities' ) );
		}

		return array(
			'id'      => (int) $post->ID,
			'title'   => $title,
			'deleted' => true,
		);
	}

	/**
	 * Adds a category to a post, keeping existing categories.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function assign_category( $input = array() ) {
		$post = self::require_post( $input );
		if ( $post instanceof WP_Error ) {
			return $post;
		}

		$cat_id = isset( $input['category_id'] ) ? absint( $input['category_id'] ) : 0;
		$term   = $cat_id > 0 ? get_term( $cat_id, 'category' ) : null;

		if ( ! $term || is_wp_error( $term ) ) {
			return new WP_Error( 'category_not_found', __( 'No category found with that ID.', 'mosmcp-abilities' ) );
		}

		$result = wp_set_object_terms( $post->ID, (int) $term->term_id, 'category', true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'id'         => (int) $post->ID,
			'title'      => (string) get_the_title( $post->ID ),
			'categories' => self::category_names( (int) $post->ID ),
		);
	}

	/**
	 * Adds a tag to a post by name, creating the tag if it does not exist.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function assign_tag( $input = array() ) {
		$post = self::require_post( $input );
		if ( $post instanceof WP_Error ) {
			return $post;
		}

		$tag = isset( $input['tag'] ) ? sanitize_text_field( $input['tag'] ) : '';

		if ( '' === $tag ) {
			return new WP_Error( 'missing_tag', __( 'A tag name is required.', 'mosmcp-abilities' ) );
		}

		$result = wp_set_post_tags( $post->ID, array( $tag ), true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'id'    => (int) $post->ID,
			'title' => (string) get_the_title( $post->ID ),
			'tags'  => self::tag_names( (int) $post->ID ),
		);
	}

	/**
	 * Removes a category from a post, restoring the default if none remain.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function remove_category( $input = array() ) {
		$post = self::require_post( $input );
		if ( $post instanceof WP_Error ) {
			return $post;
		}

		$cat_id = isset( $input['category_id'] ) ? absint( $input['category_id'] ) : 0;
		$term   = $cat_id > 0 ? get_term( $cat_id, 'category' ) : null;

		if ( ! $term || is_wp_error( $term ) ) {
			return new WP_Error( 'category_not_found', __( 'No category found with that ID.', 'mosmcp-abilities' ) );
		}

		if ( ! has_category( $term->term_id, $post ) ) {
			return new WP_Error( 'category_not_on_post', __( 'This post is not in that category.', 'mosmcp-abilities' ) );
		}

		$result = wp_remove_object_terms( $post->ID, (int) $term->term_id, 'category' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// WordPress requires every post to have a category â restore the default if none remain.
		if ( empty( wp_get_post_categories( $post->ID ) ) ) {
			wp_set_post_categories( $post->ID, array() );
		}

		return array(
			'id'         => (int) $post->ID,
			'title'      => (string) get_the_title( $post->ID ),
			'categories' => self::category_names( (int) $post->ID ),
		);
	}

	/**
	 * Removes a tag from a post by name. The tag itself is not deleted.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function remove_tag( $input = array() ) {
		$post = self::require_post( $input );
		if ( $post instanceof WP_Error ) {
			return $post;
		}

		$tag = isset( $input['tag'] ) ? sanitize_text_field( $input['tag'] ) : '';

		if ( '' === $tag ) {
			return new WP_Error( 'missing_tag', __( 'A tag name is required.', 'mosmcp-abilities' ) );
		}

		$existing = term_exists( $tag, 'post_tag' );

		if ( ! $existing ) {
			return new WP_Error( 'tag_not_found', __( 'No tag with that name exists.', 'mosmcp-abilities' ) );
		}

		$term_id = is_array( $existing ) ? (int) $existing['term_id'] : (int) $existing;

		if ( ! has_tag( $term_id, $post ) ) {
			return new WP_Error( 'tag_not_on_post', __( 'This post does not have that tag.', 'mosmcp-abilities' ) );
		}

		$result = wp_remove_object_terms( $post->ID, $term_id, 'post_tag' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'id'    => (int) $post->ID,
			'title' => (string) get_the_title( $post->ID ),
			'tags'  => self::tag_names( (int) $post->ID ),
		);
	}

	/**
	 * Finds posts by title/name, or looks up a post by ID.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function find( $input = array() ) {
		$id     = isset( $input['id'] ) ? absint( $input['id'] ) : 0;
		$search = isset( $input['search'] ) ? trim( (string) $input['search'] ) : '';

		if ( $id <= 0 && '' === $search ) {
			return new WP_Error( 'missing_input', __( 'Provide either "search" (a post title/name) or "id" (a post ID).', 'mosmcp-abilities' ) );
		}

		if ( $id > 0 ) {
			$post = get_post( $id );

			// read_post handles every status: published, private, draft, pending, future.
			if ( ! $post || 'post' !== $post->post_type || ! current_user_can( 'read_post', $post->ID ) ) {
				return array(
					'showing' => 0,
					'total'   => 0,
					'matches' => array(),
				);
			}

			return array(
				'showing' => 1,
				'total'   => 1,
				'matches' => array( self::format_find_match( $post ) ),
			);
		}

		$args = array(
			'post_type'      => 'post',
			'post_status'    => self::ALL_STATUSES,
			's'              => $search,
			'search_columns' => array( 'post_title' ),
			'posts_per_page' => 10,
			'orderby'        => 'modified',
			'order'          => 'DESC',
		);

		if ( ! current_user_can( 'edit_others_posts' ) ) {
			$args['author'] = get_current_user_id();
		}

		$query = new WP_Query( $args );

		$matches = array();
		foreach ( $query->posts as $post ) {
			$matches[] = self::format_find_match( $post );
		}

		return array(
			'showing' => count( $matches ),
			'total'   => (int) $query->found_posts,
			'matches' => $matches,
		);
	}

	/**
	 * Gets the full details of a single post by ID.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function get( $input = array() ) {
		$post = self::require_post( $input );
		if ( $post instanceof WP_Error ) {
			return $post;
		}

		// read_post handles every status: published, private, draft, pending, future.
		if ( ! current_user_can( 'read_post', $post->ID ) ) {
			return new WP_Error( 'cannot_read_post', __( 'You are not allowed to read this post.', 'mosmcp-abilities' ) );
		}

		$author = get_userdata( (int) $post->post_author );

		return array(
			'id'          => (int) $post->ID,
			'title'       => (string) get_the_title( $post ),
			'content'     => (string) $post->post_content,
			'excerpt'     => (string) wp_trim_words( $post->post_content, 40 ),
			'status'      => (string) $post->post_status,
			'author_id'   => (int) $post->post_author,
			'author_name' => $author ? (string) $author->display_name : '',
			'created'     => (string) $post->post_date,
			'modified'    => (string) $post->post_modified,
			'link'        => (string) get_permalink( $post ),
			'edit_url'    => self::edit_url( (int) $post->ID ),
			'categories'  => self::category_names( (int) $post->ID ),
			'tags'        => self::tag_names( (int) $post->ID ),
		);
	}

	/**
	 * Lists posts of any status, with an optional status filter.
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

		$posts = array();
		foreach ( $query->posts as $post ) {
			$posts[] = array(
				'id'          => (int) $post->ID,
				'title'       => (string) get_the_title( $post ),
				'status'      => (string) $post->post_status,
				'author_name' => self::author_name( $post ),
				'date'        => (string) $post->post_date,
				'modified'    => (string) $post->post_modified,
				'edit_url'    => self::edit_url( (int) $post->ID ),
			);
		}

		return self::list_result( $posts, $query, 'posts' );
	}

	/**
	 * Lists draft posts from every author.
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
		foreach ( $query->posts as $post ) {
			$drafts[] = array(
				'id'          => (int) $post->ID,
				'title'       => (string) get_the_title( $post ),
				'author_name' => self::author_name( $post ),
				'excerpt'     => (string) wp_trim_words( $post->post_content, 20 ),
				'modified'    => (string) $post->post_modified,
				'edit_url'    => self::edit_url( (int) $post->ID ),
			);
		}

		return self::list_result( $drafts, $query, 'drafts' );
	}

	/**
	 * Lists draft posts authored by the current user only.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function list_own_drafts( $input = array() ) {
		$user_id = get_current_user_id();

		if ( 0 === $user_id ) {
			return new WP_Error( 'not_logged_in', __( 'You must be logged in to list your drafts.', 'mosmcp-abilities' ) );
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
		foreach ( $query->posts as $post ) {
			$drafts[] = array(
				'id'       => (int) $post->ID,
				'title'    => (string) get_the_title( $post ),
				'excerpt'  => (string) wp_trim_words( $post->post_content, 20 ),
				'modified' => (string) $post->post_modified,
				'edit_url' => self::edit_url( (int) $post->ID ),
			);
		}

		return self::list_result( $drafts, $query, 'drafts' );
	}

	/**
	 * Lists posts pending review.
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

		$posts = array();
		foreach ( $query->posts as $post ) {
			$posts[] = array(
				'id'          => (int) $post->ID,
				'title'       => (string) get_the_title( $post ),
				'author_name' => self::author_name( $post ),
				'excerpt'     => (string) wp_trim_words( $post->post_content, 20 ),
				'modified'    => (string) $post->post_modified,
				'edit_url'    => self::edit_url( (int) $post->ID ),
			);
		}

		return self::list_result( $posts, $query, 'posts' );
	}

	/**
	 * Lists private posts.
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

		$posts = array();
		foreach ( $query->posts as $post ) {
			$posts[] = array(
				'id'       => (int) $post->ID,
				'title'    => (string) get_the_title( $post ),
				'excerpt'  => (string) wp_trim_words( $post->post_content, 20 ),
				'modified' => (string) $post->post_modified,
				'edit_url' => self::edit_url( (int) $post->ID ),
			);
		}

		return self::list_result( $posts, $query, 'posts' );
	}

	/**
	 * Lists published posts, newest first.
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

		$posts = array();
		foreach ( $query->posts as $post ) {
			$posts[] = array(
				'id'          => (int) $post->ID,
				'title'       => (string) get_the_title( $post ),
				'author_name' => self::author_name( $post ),
				'excerpt'     => (string) wp_trim_words( $post->post_content, 20 ),
				'date'        => (string) $post->post_date,
				'link'        => (string) get_permalink( $post ),
				'edit_url'    => self::edit_url( (int) $post->ID ),
			);
		}

		return self::list_result( $posts, $query, 'posts' );
	}

	/**
	 * Lists posts scheduled for future publishing.
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

		$posts = array();
		foreach ( $query->posts as $post ) {
			$posts[] = array(
				'id'            => (int) $post->ID,
				'title'         => (string) get_the_title( $post ),
				'scheduled_for' => (string) $post->post_date,
				'edit_url'      => self::edit_url( (int) $post->ID ),
			);
		}

		return self::list_result( $posts, $query, 'posts' );
	}

	/**
	 * Lists trashed posts.
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

		$posts = array();
		foreach ( $query->posts as $post ) {
			$previous_status = (string) get_post_meta( $post->ID, '_wp_trash_meta_status', true );
			$posts[]         = array(
				'id'              => (int) $post->ID,
				'title'           => (string) get_the_title( $post ),
				'author_name'     => self::author_name( $post ),
				'previous_status' => '' !== $previous_status ? $previous_status : 'draft',
				'trashed_on'      => (string) $post->post_modified,
				'restorable'      => true,
			);
		}

		return self::list_result( $posts, $query, 'posts' );
	}

	/**
	 * Lists posts that have no tags.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function list_untagged( $input = array() ) {
		$args                = self::list_args( $input, array( 'orderby' => 'date' ) );
		$args['post_status'] = array( 'publish', 'draft', 'pending', 'future', 'private' );
		$args['tax_query']   = array(
			array(
				'taxonomy' => 'post_tag',
				'operator' => 'NOT EXISTS',
			),
		);
		self::scope_to_author( $args );

		$query = new WP_Query( $args );

		$posts = array();
		foreach ( $query->posts as $post ) {
			$posts[] = array(
				'id'       => (int) $post->ID,
				'title'    => (string) get_the_title( $post ),
				'status'   => (string) $post->post_status,
				'date'     => (string) $post->post_date,
				'edit_url' => self::edit_url( (int) $post->ID ),
			);
		}

		return self::list_result( $posts, $query, 'posts' );
	}

	/**
	 * Resolves and validates the target post from input.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return \WP_Post|WP_Error
	 */
	private static function require_post( $input ) {
		$id   = isset( $input['id'] ) ? absint( $input['id'] ) : 0;
		$post = get_post( $id );

		if ( ! $post || 'post' !== $post->post_type ) {
			return new WP_Error( 'post_not_found', __( 'No post found with that ID.', 'mosmcp-abilities' ) );
		}

		return $post;
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
		$per_page = min( $per_page, Pagination::MAX_PER_PAGE );
		$offset   = isset( $input['offset'] ) ? absint( $input['offset'] ) : 0;

		return array_merge(
			array(
				'post_type'      => 'post',
				'posts_per_page' => $per_page,
				'offset'         => $offset,
				'order'          => 'DESC',
			),
			$overrides
		);
	}

	/**
	 * Restricts a query to the current user's own posts when they cannot edit others'.
	 *
	 * @param array<string, mixed> $args Query args, modified in place.
	 * @return void
	 */
	private static function scope_to_author( array &$args ) {
		if ( ! current_user_can( 'edit_others_posts' ) ) {
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
	private static function list_result( array $items, WP_Query $query, $key ) {
		return array(
			'showing' => count( $items ),
			'total'   => (int) $query->found_posts,
			$key      => $items,
		);
	}

	/**
	 * Formats a single search match.
	 *
	 * @param \WP_Post $post Post object.
	 * @return array<string, mixed>
	 */
	private static function format_find_match( $post ) {
		return array(
			'id'          => (int) $post->ID,
			'title'       => (string) get_the_title( $post ),
			'status'      => (string) $post->post_status,
			'author_name' => self::author_name( $post ),
			'date'        => (string) $post->post_date,
			'edit_url'    => self::edit_url( (int) $post->ID ),
		);
	}

	/**
	 * Display name of a post's author, or an empty string.
	 *
	 * @param \WP_Post $post Post object.
	 * @return string
	 */
	private static function author_name( $post ) {
		$author = get_userdata( (int) $post->post_author );
		return $author ? (string) $author->display_name : '';
	}

	/**
	 * Category names assigned to a post.
	 *
	 * @param int $post_id Post ID.
	 * @return string[]
	 */
	private static function category_names( $post_id ) {
		$names = array();
		foreach ( get_the_category( $post_id ) as $term ) {
			$names[] = (string) $term->name;
		}
		return $names;
	}

	/**
	 * Tag names assigned to a post.
	 *
	 * @param int $post_id Post ID.
	 * @return string[]
	 */
	private static function tag_names( $post_id ) {
		$names = array();
		foreach ( wp_get_post_tags( $post_id, array( 'fields' => 'names' ) ) as $name ) {
			$names[] = (string) $name;
		}
		return $names;
	}

	/**
	 * Admin edit URL for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private static function edit_url( $post_id ) {
		return (string) admin_url( 'post.php?post=' . $post_id . '&action=edit' );
	}
}
