<?php
/**
 * Execute callbacks for the core Tags ability pack.
 *
 * Business logic is preserved from the reviewed source collection; only naming,
 * text domain, and formatting were adapted. Authorization is enforced by the
 * Ability_Registrar wrapper (manage_categories / edit_posts).
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Core;

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
 * Class Tags_Provider
 *
 * Static execute callbacks for tag (taxonomy "post_tag") abilities.
 */
class Tags_Provider {

	/**
	 * Creates a new tag.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function create( $input = array() ) {
		$name = isset( $input['name'] ) ? sanitize_text_field( $input['name'] ) : '';

		if ( '' === $name ) {
			return new WP_Error( 'missing_name', __( 'A name is required to create a tag.', 'mosmcp-abilities' ) );
		}

		$existing = term_exists( $name, 'post_tag' );
		if ( $existing ) {
			$existing_id = is_array( $existing ) ? (int) $existing['term_id'] : (int) $existing;
			return new WP_Error(
				'tag_exists',
				sprintf(
					/* translators: 1: tag name, 2: existing tag ID. */
					__( 'A tag named "%1$s" already exists (ID %2$d).', 'mosmcp-abilities' ),
					$name,
					$existing_id
				)
			);
		}

		$args = array();

		if ( ! empty( $input['slug'] ) ) {
			$args['slug'] = sanitize_title( $input['slug'] );
		}
		if ( isset( $input['description'] ) && '' !== trim( (string) $input['description'] ) ) {
			$args['description'] = sanitize_textarea_field( $input['description'] );
		}

		$result = wp_insert_term( $name, 'post_tag', $args );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$term = get_term( (int) $result['term_id'], 'post_tag' );

		return array(
			'id'          => (int) $term->term_id,
			'name'        => (string) $term->name,
			'slug'        => (string) $term->slug,
			'description' => (string) $term->description,
			'edit_url'    => self::edit_url( (int) $term->term_id ),
		);
	}

	/**
	 * Deletes a tag (posts simply lose the tag).
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function delete( $input = array() ) {
		$term = self::require_term( $input );
		if ( $term instanceof WP_Error ) {
			return $term;
		}

		if ( empty( $input['confirm'] ) || true !== $input['confirm'] ) {
			return new WP_Error( 'confirmation_required', __( 'Deleting a tag cannot be undone. Set confirm to true to proceed.', 'mosmcp-abilities' ) );
		}

		$name   = (string) $term->name;
		$result = wp_delete_term( $term->term_id, 'post_tag' );

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( true !== $result ) {
			return new WP_Error( 'delete_failed', __( 'The tag could not be deleted.', 'mosmcp-abilities' ) );
		}

		return array(
			'id'      => (int) $term->term_id,
			'name'    => $name,
			'deleted' => true,
		);
	}

	/**
	 * Finds tags by name, or looks up a tag by ID.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function find( $input = array() ) {
		$id     = isset( $input['id'] ) ? absint( $input['id'] ) : 0;
		$search = isset( $input['search'] ) ? trim( (string) $input['search'] ) : '';

		if ( $id <= 0 && '' === $search ) {
			return new WP_Error( 'missing_input', __( 'Provide either "search" (a tag name) or "id" (a tag ID).', 'mosmcp-abilities' ) );
		}

		if ( $id > 0 ) {
			$term = get_term( $id, 'post_tag' );
			if ( ! $term || is_wp_error( $term ) ) {
				return array(
					'showing' => 0,
					'total'   => 0,
					'matches' => array(),
				);
			}
			return array(
				'showing' => 1,
				'total'   => 1,
				'matches' => array( self::format_tag( $term ) ),
			);
		}

		$terms = get_terms(
			array(
				'taxonomy'   => 'post_tag',
				'hide_empty' => false,
				'search'     => $search,
				'number'     => 10,
				'orderby'    => 'name',
			)
		);
		if ( is_wp_error( $terms ) ) {
			return $terms;
		}

		$total = (int) wp_count_terms(
			array(
				'taxonomy'   => 'post_tag',
				'hide_empty' => false,
				'search'     => $search,
			)
		);

		$matches = array();
		foreach ( $terms as $term ) {
			$matches[] = self::format_tag( $term );
		}

		return array(
			'showing' => count( $matches ),
			'total'   => $total,
			'matches' => $matches,
		);
	}

	/**
	 * Gets a single tag by ID.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function get( $input = array() ) {
		$term = self::require_term( $input );
		if ( $term instanceof WP_Error ) {
			return $term;
		}

		return self::format_tag( $term );
	}

	/**
	 * Lists all tags, including unused ones.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function list_all( $input = array() ) {
		$per_page = isset( $input['per_page'] ) ? absint( $input['per_page'] ) : 20;
		$offset   = isset( $input['offset'] ) ? absint( $input['offset'] ) : 0;

		$terms = get_terms(
			array(
				'taxonomy'   => 'post_tag',
				'hide_empty' => false,
				'number'     => $per_page,
				'offset'     => $offset,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);
		if ( is_wp_error( $terms ) ) {
			return $terms;
		}

		$total = (int) wp_count_terms(
			array(
				'taxonomy'   => 'post_tag',
				'hide_empty' => false,
			)
		);

		$tags = array();
		foreach ( $terms as $term ) {
			$tags[] = self::format_tag( $term );
		}

		return array(
			'showing' => count( $tags ),
			'total'   => $total,
			'tags'    => $tags,
		);
	}

	/**
	 * Lists posts that carry a given tag.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function list_posts( $input = array() ) {
		$term = self::require_term( $input );
		if ( $term instanceof WP_Error ) {
			return $term;
		}

		$per_page = isset( $input['per_page'] ) ? absint( $input['per_page'] ) : 20;
		$offset   = isset( $input['offset'] ) ? absint( $input['offset'] ) : 0;

		$args = array(
			'post_type'      => 'post',
			'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
			'tag_id'         => $term->term_id,
			'posts_per_page' => $per_page,
			'offset'         => $offset,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		if ( ! current_user_can( 'edit_others_posts' ) ) {
			$args['author'] = get_current_user_id();
		}

		$query = new WP_Query( $args );

		$posts = array();
		foreach ( $query->posts as $post ) {
			$posts[] = array(
				'id'       => (int) $post->ID,
				'title'    => (string) get_the_title( $post ),
				'status'   => (string) $post->post_status,
				'date'     => (string) $post->post_date,
				'edit_url' => (string) admin_url( 'post.php?post=' . $post->ID . '&action=edit' ),
			);
		}

		return array(
			'showing'  => count( $posts ),
			'total'    => (int) $query->found_posts,
			'tag_name' => (string) $term->name,
			'posts'    => $posts,
		);
	}

	/**
	 * Lists tags used by zero posts.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function list_unused( $input = array() ) {
		$per_page = isset( $input['per_page'] ) ? absint( $input['per_page'] ) : 20;
		$offset   = isset( $input['offset'] ) ? absint( $input['offset'] ) : 0;

		$terms = get_terms(
			array(
				'taxonomy'   => 'post_tag',
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);
		if ( is_wp_error( $terms ) ) {
			return $terms;
		}

		$unused = array();
		foreach ( $terms as $term ) {
			if ( 0 === (int) $term->count ) {
				$unused[] = self::format_tag( $term );
			}
		}

		$page = array_slice( $unused, $offset, $per_page );

		return array(
			'showing' => count( $page ),
			'total'   => count( $unused ),
			'tags'    => $page,
		);
	}

	/**
	 * Changes a tag's description.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function update_description( $input = array() ) {
		$term = self::require_term( $input );
		if ( $term instanceof WP_Error ) {
			return $term;
		}

		$description = isset( $input['description'] ) ? sanitize_textarea_field( $input['description'] ) : '';

		$result = wp_update_term( $term->term_id, 'post_tag', array( 'description' => $description ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$updated = get_term( $term->term_id, 'post_tag' );

		return array(
			'id'          => (int) $updated->term_id,
			'name'        => (string) $updated->name,
			'description' => (string) $updated->description,
			'edit_url'    => self::edit_url( (int) $updated->term_id ),
		);
	}

	/**
	 * Renames a tag.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function update_name( $input = array() ) {
		$term = self::require_term( $input );
		if ( $term instanceof WP_Error ) {
			return $term;
		}

		$name = isset( $input['name'] ) ? sanitize_text_field( $input['name'] ) : '';

		if ( '' === $name ) {
			return new WP_Error( 'missing_name', __( 'A new name is required.', 'mosmcp-abilities' ) );
		}

		$result = wp_update_term( $term->term_id, 'post_tag', array( 'name' => $name ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$updated = get_term( $term->term_id, 'post_tag' );

		return array(
			'id'       => (int) $updated->term_id,
			'name'     => (string) $updated->name,
			'slug'     => (string) $updated->slug,
			'edit_url' => self::edit_url( (int) $updated->term_id ),
		);
	}

	/**
	 * Changes a tag's URL slug.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function update_slug( $input = array() ) {
		$term = self::require_term( $input );
		if ( $term instanceof WP_Error ) {
			return $term;
		}

		$slug = isset( $input['slug'] ) ? sanitize_title( $input['slug'] ) : '';

		if ( '' === $slug ) {
			return new WP_Error( 'invalid_slug', __( 'The slug must contain at least one letter or number.', 'mosmcp-abilities' ) );
		}

		$existing = get_term_by( 'slug', $slug, 'post_tag' );

		if ( $existing && (int) $existing->term_id !== (int) $term->term_id ) {
			return new WP_Error(
				'slug_in_use',
				sprintf(
					/* translators: 1: slug, 2: tag name. */
					__( 'The slug "%1$s" is already used by the tag "%2$s".', 'mosmcp-abilities' ),
					$slug,
					$existing->name
				)
			);
		}

		$result = wp_update_term( $term->term_id, 'post_tag', array( 'slug' => $slug ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$updated = get_term( $term->term_id, 'post_tag' );

		return array(
			'id'       => (int) $updated->term_id,
			'name'     => (string) $updated->name,
			'slug'     => (string) $updated->slug,
			'edit_url' => self::edit_url( (int) $updated->term_id ),
		);
	}

	/**
	 * Loads the target tag term or returns a WP_Error.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return \WP_Term|WP_Error
	 */
	private static function require_term( $input ) {
		$id   = isset( $input['id'] ) ? absint( $input['id'] ) : 0;
		$term = $id > 0 ? get_term( $id, 'post_tag' ) : null;

		if ( ! $term || is_wp_error( $term ) ) {
			return new WP_Error( 'tag_not_found', __( 'No tag found with that ID.', 'mosmcp-abilities' ) );
		}

		return $term;
	}

	/**
	 * Formats one tag term as the standard tag item.
	 *
	 * @param \WP_Term $term Tag term.
	 * @return array<string, mixed>
	 */
	private static function format_tag( $term ) {
		return array(
			'id'          => (int) $term->term_id,
			'name'        => (string) $term->name,
			'slug'        => (string) $term->slug,
			'description' => (string) $term->description,
			'post_count'  => (int) $term->count,
			'edit_url'    => self::edit_url( (int) $term->term_id ),
		);
	}

	/**
	 * Admin edit URL for a tag term.
	 *
	 * @param int $term_id Term ID.
	 * @return string
	 */
	private static function edit_url( $term_id ) {
		return (string) admin_url( 'term.php?taxonomy=post_tag&tag_ID=' . $term_id );
	}
}
