<?php
/**
 * Execute callbacks for the custom post type abilities.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Cpt;

use MoSMCP\Abilities\Packs\Core\Support\Post_Display;
use MoSMCP\Abilities\Packs\Core\Support\Post_Duplicator;
use MoSMCP\Abilities\Packs\Core\Support\Post_Fields;
use WP_Error;
use WP_Post;
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
 * These abilities query and filter by taxonomy term and by custom field, which is
 * the tool surface this pack exists to provide. The queries are bounded and
 * parameterized, so the performance advisory is accepted here.
 */
// phpcs:disable WordPress.DB.SlowDBQuery

/**
 * Class Cpt_Provider
 */
class Cpt_Provider {

	/* ------------------------------------------------------------- discovery */

	/**
	 * Lists the custom post types this site has.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public static function list_types( $input = array() ) {
		$types = array();

		foreach ( Cpt_Support::eligible_types() as $object ) {
			$types[] = Cpt_Support::describe_type( $object, false );
		}

		return array(
			'total' => count( $types ),
			'types' => $types,
			'note'  => $types
				? __( 'Read a type in detail to see its fields, taxonomies and access before editing it.', 'mosmcp-abilities' )
				: __( 'This site has no custom post types that these abilities handle. Posts, pages, WooCommerce products and form entries each have their own abilities.', 'mosmcp-abilities' ),
		);
	}

	/**
	 * Describes one custom post type in full.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function describe_type( $input = array() ) {
		$type = Cpt_Support::require_type( (array) $input );
		if ( $type instanceof WP_Error ) {
			return $type;
		}

		return Cpt_Support::describe_type( $type, true );
	}

	/* ------------------------------------------------------------------ read */

	/**
	 * Lists items of a custom post type.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function list_items( $input = array() ) {
		$input = (array) $input;

		$type = Cpt_Support::require_type( $input );
		if ( $type instanceof WP_Error ) {
			return $type;
		}

		$per_page = isset( $input['per_page'] ) ? absint( $input['per_page'] ) : 20;
		$per_page = max( 1, min( $per_page, 100 ) );

		$args = array(
			'post_type'      => $type->name,
			'post_status'    => isset( $input['status'] ) && '' !== $input['status']
				? sanitize_key( (string) $input['status'] )
				: array( 'publish', 'draft', 'pending', 'future', 'private' ),
			'posts_per_page' => $per_page,
			'offset'         => isset( $input['offset'] ) ? absint( $input['offset'] ) : 0,
			'orderby'        => isset( $input['order_by'] ) && 'title' === $input['order_by'] ? 'title' : 'modified',
			'order'          => isset( $input['order'] ) && 'ASC' === strtoupper( (string) $input['order'] ) ? 'ASC' : 'DESC',
		);

		if ( isset( $input['search'] ) && '' !== trim( (string) $input['search'] ) ) {
			$args['s'] = sanitize_text_field( (string) $input['search'] );
		}

		if ( isset( $input['parent_id'] ) && absint( $input['parent_id'] ) > 0 ) {
			$args['post_parent'] = absint( $input['parent_id'] );
		}

		$taxonomy = isset( $input['taxonomy'] ) ? sanitize_key( (string) $input['taxonomy'] ) : '';
		$term     = isset( $input['term'] ) ? sanitize_text_field( (string) $input['term'] ) : '';

		if ( '' !== $taxonomy || '' !== $term ) {
			if ( '' === $taxonomy || '' === $term ) {
				return new WP_Error(
					'incomplete_taxonomy_filter',
					__( 'Filtering by term needs both taxonomy and term.', 'mosmcp-abilities' )
				);
			}
			if ( ! in_array( $taxonomy, get_object_taxonomies( $type->name ), true ) ) {
				return new WP_Error(
					'taxonomy_not_on_type',
					sprintf(
						/* translators: 1: taxonomy slug, 2: post type slug, 3: comma-separated taxonomies */
						__( 'The "%1$s" taxonomy is not attached to the "%2$s" type. It has: %3$s.', 'mosmcp-abilities' ),
						$taxonomy,
						$type->name,
						implode( ', ', get_object_taxonomies( $type->name ) ) ? implode( ', ', get_object_taxonomies( $type->name ) ) : __( 'none', 'mosmcp-abilities' )
					)
				);
			}
			$args['tax_query'] = array(
				array(
					'taxonomy' => $taxonomy,
					'field'    => is_numeric( $term ) ? 'term_id' : 'slug',
					'terms'    => is_numeric( $term ) ? (int) $term : $term,
				),
			);
		}

		$query = new WP_Query( $args );
		$items = array();

		foreach ( $query->posts as $post ) {
			$items[] = self::summarize( $post );
		}

		return array(
			'post_type' => (string) $type->name,
			'showing'   => count( $items ),
			'total'     => (int) $query->found_posts,
			'has_more'  => ( $args['offset'] + count( $items ) ) < (int) $query->found_posts,
			'items'     => $items,
		);
	}

	/**
	 * Reads one item in full, including its fields and taxonomy terms.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function get_item( $input = array() ) {
		$post = Cpt_Support::require_item( (array) $input );
		if ( $post instanceof WP_Error ) {
			return $post;
		}

		$terms = array();
		foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
			$assigned = wp_get_object_terms( (int) $post->ID, $taxonomy );
			if ( is_wp_error( $assigned ) || ! $assigned ) {
				continue;
			}
			$terms[] = array(
				'taxonomy' => (string) $taxonomy,
				'terms'    => array_map(
					static function ( $t ) {
						return array(
							'term_id' => (int) $t->term_id,
							'name'    => (string) $t->name,
							'slug'    => (string) $t->slug,
						);
					},
					$assigned
				),
			);
		}

		$children = 0;
		if ( is_post_type_hierarchical( $post->post_type ) ) {
			$children = count(
				get_children(
					array(
						'post_parent' => (int) $post->ID,
						'post_type'   => $post->post_type,
						'post_status' => 'any',
						'fields'      => 'ids',
					)
				)
			);
		}

		return array(
			'id'         => (int) $post->ID,
			'post_type'  => (string) $post->post_type,
			'title'      => (string) get_the_title( $post ),
			'status'     => (string) $post->post_status,
			'slug'       => (string) $post->post_name,
			'content'    => post_type_supports( $post->post_type, 'editor' ) ? (string) $post->post_content : '',
			'excerpt'    => post_type_supports( $post->post_type, 'excerpt' ) ? (string) $post->post_excerpt : '',
			'modified'   => (string) $post->post_modified,
			'hierarchy'  => array(
				'parent_id'    => (int) $post->post_parent,
				'menu_order'   => (int) $post->menu_order,
				'child_count'  => (int) $children,
				'hierarchical' => is_post_type_hierarchical( $post->post_type ),
			),
			'featured_image' => array(
				'supported'     => post_type_supports( $post->post_type, 'thumbnail' ),
				'attachment_id' => (int) get_post_thumbnail_id( $post->ID ),
				'url'           => (string) get_the_post_thumbnail_url( $post->ID, 'full' ),
			),
			'terms'      => $terms,
			'fields'     => self::read_fields( $post ),
			'elementor'  => array(
				'enabled'  => post_type_supports( $post->post_type, 'elementor' ),
				'has_data' => '' !== (string) get_post_meta( $post->ID, '_elementor_data', true ),
			),
			'page_template' => (string) get_post_meta( $post->ID, '_wp_page_template', true ),
			'edit_url'   => (string) get_edit_post_link( $post->ID, 'raw' ),
			'view_url'   => (string) get_permalink( $post->ID ),
		);
	}

	/* ----------------------------------------------------------------- write */

	/**
	 * Creates an item of a custom post type.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function create_item( $input = array() ) {
		$input = (array) $input;

		$type = Cpt_Support::require_type( $input );
		if ( $type instanceof WP_Error ) {
			return $type;
		}

		if ( ! current_user_can( $type->cap->create_posts ) ) {
			return new WP_Error(
				'cannot_create',
				sprintf(
					/* translators: 1: post type label, 2: capability name */
					__( 'You do not have permission to create %1$s. This needs the "%2$s" capability.', 'mosmcp-abilities' ),
					strtolower( (string) $type->labels->name ),
					(string) $type->cap->create_posts
				)
			);
		}

		$title = isset( $input['title'] ) ? sanitize_text_field( (string) $input['title'] ) : '';
		if ( '' === $title ) {
			return new WP_Error(
				'missing_title',
				__( 'A title is required.', 'mosmcp-abilities' )
			);
		}

		$unsupported = Post_Fields::unsupported_error( $type->name, $input );
		if ( $unsupported instanceof WP_Error ) {
			return $unsupported;
		}

		$status = isset( $input['status'] ) ? sanitize_key( (string) $input['status'] ) : 'draft';
		$status_check = self::check_status( $type, $status );
		if ( $status_check instanceof WP_Error ) {
			return $status_check;
		}

		$postarr = array(
			'post_type'   => $type->name,
			'post_status' => $status,
			'post_title'  => $title,
			'post_author' => get_current_user_id(),
		);

		if ( isset( $input['content'] ) ) {
			$postarr['post_content'] = wp_kses_post( (string) $input['content'] );
		}
		if ( isset( $input['excerpt'] ) ) {
			$postarr['post_excerpt'] = sanitize_textarea_field( (string) $input['excerpt'] );
		}
		if ( isset( $input['parent_id'] ) && absint( $input['parent_id'] ) > 0 ) {
			$parent = self::check_parent( $type, 0, absint( $input['parent_id'] ) );
			if ( $parent instanceof WP_Error ) {
				return $parent;
			}
			$postarr['post_parent'] = absint( $input['parent_id'] );
		}

		if ( isset( $input['slug'] ) ) {
			$slug = Post_Fields::prepare_slug( (string) $type->name, (string) $input['slug'] );
			if ( $slug instanceof WP_Error ) {
				return $slug;
			}
			$postarr['post_name'] = $slug;
		}

		$id = wp_insert_post( wp_slash( $postarr ), true );
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		$warnings = array();
		$stored   = (string) get_post_field( 'post_name', $id );
		if ( isset( $slug ) && is_string( $slug ) && $stored !== $slug ) {
			$warnings[] = array(
				'code'    => 'slug_adjusted_for_uniqueness',
				'message' => sprintf(
					/* translators: 1: requested slug, 2: stored slug */
					__( 'The address "%1$s" was already in use, so WordPress stored "%2$s" instead.', 'mosmcp-abilities' ),
					$slug,
					$stored
				),
				'context' => 'requested=' . $slug . ' stored=' . $stored,
			);
		}

		return array(
			'id'        => (int) $id,
			'post_type' => (string) $type->name,
			'title'     => (string) get_the_title( $id ),
			'slug'      => $stored,
			'status'    => (string) get_post_status( $id ),
			'edit_url'  => (string) get_edit_post_link( $id, 'raw' ),
			'view_url'  => (string) get_permalink( $id ),
			'warnings'  => $warnings,
		);
	}

	/**
	 * Updates an item's title, content and/or excerpt.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function update_item( $input = array() ) {
		$post = Cpt_Support::require_item( (array) $input );
		if ( $post instanceof WP_Error ) {
			return $post;
		}

		return Post_Fields::apply_update( $post, (array) $input, true );
	}

	/**
	 * Duplicates an item with its fields, terms and layout.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function duplicate_item( $input = array() ) {
		$input = (array) $input;

		$type = Cpt_Support::require_type( $input );
		if ( $type instanceof WP_Error ) {
			return $type;
		}

		return Post_Duplicator::duplicate( $input, (string) $type->name );
	}

	/* ---------------------------------------------------------- custom fields */

	/**
	 * Writes a custom field.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function set_field( $input = array() ) {
		$input = (array) $input;

		$post = Cpt_Support::require_item( $input );
		if ( $post instanceof WP_Error ) {
			return $post;
		}

		$field = isset( $input['field'] ) ? (string) $input['field'] : '';
		$check = Cpt_Support::check_meta_writable( $post, $field );
		if ( $check instanceof WP_Error ) {
			return $check;
		}

		if ( ! array_key_exists( 'value', $input ) ) {
			return new WP_Error(
				'missing_value',
				__( 'Provide a value. To remove the field entirely, use the delete-field ability.', 'mosmcp-abilities' )
			);
		}

		$values  = is_array( $input['value'] ) ? array_values( $input['value'] ) : array( $input['value'] );
		$coerced = array();

		foreach ( $values as $raw ) {
			$value = Cpt_Support::coerce( $raw, $check['type'] );
			if ( $value instanceof WP_Error ) {
				return $value;
			}
			$coerced[] = $value;
		}

		if ( ! $check['repeatable'] && count( $coerced ) > 1 ) {
			return new WP_Error(
				'field_not_repeatable',
				sprintf(
					/* translators: %s: field name */
					__( 'The field "%s" holds a single value, so it cannot be given a list.', 'mosmcp-abilities' ),
					$field
				)
			);
		}

		$previous = get_post_meta( (int) $post->ID, $field, ! $check['repeatable'] );

		/*
		 * wp_slash() because the metadata API unslashes on the way in: it is written
		 * for values arriving from a form submission, which are already slashed. A
		 * value that came over the API is not, so writing it directly loses a level of
		 * backslashes — a caller sending a Windows path C:\tmp would find C:tmp stored,
		 * and a regex would quietly lose its \d. Slashing here makes what is stored
		 * match what was sent.
		 */
		if ( $check['repeatable'] ) {
			delete_post_meta( (int) $post->ID, $field );
			foreach ( $coerced as $value ) {
				add_post_meta( (int) $post->ID, $field, wp_slash( $value ) );
			}
		} else {
			update_post_meta( (int) $post->ID, $field, wp_slash( $coerced[0] ) );
		}

		$stored = get_post_meta( (int) $post->ID, $field, ! $check['repeatable'] );

		$warnings = array();
		if ( ! $check['registered'] ) {
			$warnings[] = array(
				'code'    => 'field_not_declared',
				'message' => __( 'No plugin or theme declares this field, so nothing validates its value or displays it automatically. It was written as given.', 'mosmcp-abilities' ),
				'context' => 'field=' . $field,
			);
		}

		return array(
			'id'         => (int) $post->ID,
			'post_type'  => (string) $post->post_type,
			'field'      => $field,
			'registered' => (bool) $check['registered'],
			'repeatable' => (bool) $check['repeatable'],
			'previous'   => self::render_value( $previous ),
			'current'    => self::render_value( $stored ),
			'changed'    => $previous !== $stored,
			'warnings'   => $warnings,
		);
	}

	/**
	 * Removes a custom field.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function delete_field( $input = array() ) {
		$input = (array) $input;

		$post = Cpt_Support::require_item( $input );
		if ( $post instanceof WP_Error ) {
			return $post;
		}

		$field = isset( $input['field'] ) ? (string) $input['field'] : '';
		$check = Cpt_Support::check_meta_writable( $post, $field );
		if ( $check instanceof WP_Error ) {
			return $check;
		}

		$previous = get_post_meta( (int) $post->ID, $field, ! $check['repeatable'] );
		$existed  = metadata_exists( 'post', (int) $post->ID, $field );

		delete_post_meta( (int) $post->ID, $field );

		return array(
			'id'        => (int) $post->ID,
			'post_type' => (string) $post->post_type,
			'field'     => $field,
			'existed'   => (bool) $existed,
			'previous'  => self::render_value( $previous ),
			'warnings'  => array(),
		);
	}

	/* -------------------------------------------------------------- taxonomy */

	/**
	 * Assigns terms in one of the type's taxonomies.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function assign_terms( $input = array() ) {
		return self::change_terms( (array) $input, 'assign' );
	}

	/**
	 * Removes terms in one of the type's taxonomies.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function remove_terms( $input = array() ) {
		return self::change_terms( (array) $input, 'remove' );
	}

	/**
	 * Shared term assignment and removal.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @param string               $mode  'assign' or 'remove'.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function change_terms( array $input, $mode ) {
		$post = Cpt_Support::require_item( $input );
		if ( $post instanceof WP_Error ) {
			return $post;
		}

		$taxonomy = isset( $input['taxonomy'] ) ? sanitize_key( (string) $input['taxonomy'] ) : '';
		$attached = get_object_taxonomies( $post->post_type );

		if ( '' === $taxonomy || ! in_array( $taxonomy, $attached, true ) ) {
			return new WP_Error(
				'taxonomy_not_on_type',
				sprintf(
					/* translators: 1: post type slug, 2: comma-separated taxonomy list */
					__( 'Provide a taxonomy attached to the "%1$s" type. It has: %2$s.', 'mosmcp-abilities' ),
					(string) $post->post_type,
					$attached ? implode( ', ', $attached ) : __( 'none', 'mosmcp-abilities' )
				)
			);
		}

		$tax_object = get_taxonomy( $taxonomy );
		if ( $tax_object && ! current_user_can( $tax_object->cap->assign_terms ) ) {
			return new WP_Error(
				'cannot_assign_terms',
				sprintf(
					/* translators: 1: taxonomy label, 2: capability name */
					__( 'You do not have permission to change %1$s. This needs the "%2$s" capability.', 'mosmcp-abilities' ),
					(string) $tax_object->label,
					(string) $tax_object->cap->assign_terms
				)
			);
		}

		$requested = isset( $input['terms'] ) ? $input['terms'] : array();
		$requested = is_array( $requested ) ? $requested : array_map( 'trim', explode( ',', (string) $requested ) );
		$requested = array_values( array_filter( array_map( 'strval', $requested ), 'strlen' ) );

		if ( ! $requested ) {
			return new WP_Error(
				'missing_terms',
				__( 'Provide one or more terms, by name, slug or ID.', 'mosmcp-abilities' )
			);
		}

		$create_missing = ! empty( $input['create_missing'] );

		$ids      = array();
		$created  = array();
		$unknown  = array();

		foreach ( $requested as $needle ) {
			$term = is_numeric( $needle )
				? get_term( (int) $needle, $taxonomy )
				: ( get_term_by( 'slug', $needle, $taxonomy ) ?: get_term_by( 'name', $needle, $taxonomy ) );

			if ( $term && ! is_wp_error( $term ) ) {
				$ids[] = (int) $term->term_id;
				continue;
			}

			if ( 'assign' === $mode && $create_missing ) {
				if ( ! current_user_can( $tax_object->cap->edit_terms ) ) {
					return new WP_Error(
						'cannot_create_terms',
						sprintf(
							/* translators: 1: taxonomy label, 2: capability name */
							__( 'Creating new %1$s needs the "%2$s" capability, which the connected account does not have.', 'mosmcp-abilities' ),
							(string) $tax_object->label,
							(string) $tax_object->cap->edit_terms
						)
					);
				}
				$new = wp_insert_term( $needle, $taxonomy );
				if ( is_wp_error( $new ) ) {
					return $new;
				}
				$ids[]     = (int) $new['term_id'];
				$created[] = $needle;
				continue;
			}

			$unknown[] = $needle;
		}

		if ( $unknown ) {
			return new WP_Error(
				'terms_not_found',
				sprintf(
					/* translators: 1: comma-separated term list, 2: taxonomy slug */
					__( 'These do not exist in "%2$s": %1$s. Pass create_missing to add them, or use existing terms.', 'mosmcp-abilities' ),
					implode( ', ', $unknown ),
					$taxonomy
				)
			);
		}

		$before = wp_get_object_terms( (int) $post->ID, $taxonomy, array( 'fields' => 'ids' ) );
		$before = is_wp_error( $before ) ? array() : array_map( 'intval', $before );

		if ( 'assign' === $mode ) {
			$append = ! isset( $input['replace'] ) || ! $input['replace'];
			$result = wp_set_object_terms( (int) $post->ID, $ids, $taxonomy, $append );
		} else {
			$result = wp_remove_object_terms( (int) $post->ID, $ids, $taxonomy );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$after = wp_get_object_terms( (int) $post->ID, $taxonomy );
		$after = is_wp_error( $after ) ? array() : $after;

		return array(
			'id'            => (int) $post->ID,
			'post_type'     => (string) $post->post_type,
			'taxonomy'      => $taxonomy,
			'action'        => $mode,
			'created_terms' => $created,
			'terms'         => array_map(
				static function ( $t ) {
					return array(
						'term_id' => (int) $t->term_id,
						'name'    => (string) $t->name,
						'slug'    => (string) $t->slug,
					);
				},
				$after
			),
			'changed'       => count( $before ) !== count( $after ) || array_diff( $before, wp_list_pluck( $after, 'term_id' ) ),
			'warnings'      => array(),
		);
	}

	/* ------------------------------------------------- structure and status */

	/**
	 * Sets or clears the featured image.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function set_featured_image( $input = array() ) {
		$input = (array) $input;

		$post = Cpt_Support::require_item( $input );
		if ( $post instanceof WP_Error ) {
			return $post;
		}

		if ( ! post_type_supports( $post->post_type, 'thumbnail' ) ) {
			return new WP_Error(
				'thumbnail_not_supported',
				sprintf(
					/* translators: %s: post type slug */
					__( 'The "%s" type has no featured image, so one would never be displayed.', 'mosmcp-abilities' ),
					(string) $post->post_type
				)
			);
		}

		return Post_Display::set_featured_image( $input, (string) $post->post_type );
	}

	/**
	 * Moves an item within the hierarchy.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function set_parent( $input = array() ) {
		$input = (array) $input;

		$post = Cpt_Support::require_item( $input );
		if ( $post instanceof WP_Error ) {
			return $post;
		}

		$type = get_post_type_object( $post->post_type );

		if ( ! is_post_type_hierarchical( $post->post_type ) ) {
			return new WP_Error(
				'type_not_hierarchical',
				sprintf(
					/* translators: %s: post type slug */
					__( 'The "%s" type is a flat list rather than a hierarchy, so its items cannot have a parent.', 'mosmcp-abilities' ),
					(string) $post->post_type
				)
			);
		}

		$parent_id = isset( $input['parent_id'] ) ? absint( $input['parent_id'] ) : 0;

		$check = self::check_parent( $type, (int) $post->ID, $parent_id );
		if ( $check instanceof WP_Error ) {
			return $check;
		}

		$previous = (int) $post->post_parent;
		$update   = array(
			'ID'          => (int) $post->ID,
			'post_parent' => $parent_id,
		);

		if ( isset( $input['menu_order'] ) ) {
			$update['menu_order'] = (int) $input['menu_order'];
		}

		$result = wp_update_post( $update, true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$fresh = get_post( (int) $post->ID );

		return array(
			'id'          => (int) $post->ID,
			'post_type'   => (string) $post->post_type,
			'previous_parent_id' => $previous,
			'parent_id'   => (int) $fresh->post_parent,
			'menu_order'  => (int) $fresh->menu_order,
			'changed'     => $previous !== (int) $fresh->post_parent,
			'warnings'    => array(),
		);
	}

	/**
	 * Changes an item's status.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function set_status( $input = array() ) {
		$input = (array) $input;

		$post = Cpt_Support::require_item( $input );
		if ( $post instanceof WP_Error ) {
			return $post;
		}

		$type   = get_post_type_object( $post->post_type );
		$status = isset( $input['status'] ) ? sanitize_key( (string) $input['status'] ) : '';

		$check = self::check_status( $type, $status );
		if ( $check instanceof WP_Error ) {
			return $check;
		}

		$previous = (string) $post->post_status;
		if ( $previous === $status ) {
			return new WP_Error(
				'status_unchanged',
				sprintf(
					/* translators: %s: status */
					__( 'This item is already "%s".', 'mosmcp-abilities' ),
					$status
				)
			);
		}

		$result = wp_update_post(
			array(
				'ID'          => (int) $post->ID,
				'post_status' => $status,
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'id'              => (int) $post->ID,
			'post_type'       => (string) $post->post_type,
			'previous_status' => $previous,
			'status'          => (string) get_post_status( $post->ID ),
			'view_url'        => (string) get_permalink( $post->ID ),
			'warnings'        => array(),
		);
	}

	/**
	 * Moves an item to the trash.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function trash_item( $input = array() ) {
		$post = Cpt_Support::require_item( (array) $input );
		if ( $post instanceof WP_Error ) {
			return $post;
		}

		if ( 'trash' === $post->post_status ) {
			return new WP_Error( 'already_trashed', __( 'This item is already in the trash.', 'mosmcp-abilities' ) );
		}

		$result = wp_trash_post( (int) $post->ID );
		if ( ! $result ) {
			return new WP_Error( 'trash_failed', __( 'WordPress could not move this item to the trash.', 'mosmcp-abilities' ) );
		}

		return array(
			'id'        => (int) $post->ID,
			'post_type' => (string) $post->post_type,
			'status'    => (string) get_post_status( $post->ID ),
			'warnings'  => array(),
		);
	}

	/**
	 * Restores an item from the trash.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function restore_item( $input = array() ) {
		$post = Cpt_Support::require_item( (array) $input );
		if ( $post instanceof WP_Error ) {
			return $post;
		}

		if ( 'trash' !== $post->post_status ) {
			return new WP_Error( 'not_trashed', __( 'This item is not in the trash.', 'mosmcp-abilities' ) );
		}

		if ( ! wp_untrash_post( (int) $post->ID ) ) {
			return new WP_Error( 'restore_failed', __( 'WordPress could not restore this item.', 'mosmcp-abilities' ) );
		}

		return array(
			'id'        => (int) $post->ID,
			'post_type' => (string) $post->post_type,
			'status'    => (string) get_post_status( $post->ID ),
			'warnings'  => array(),
		);
	}

	/**
	 * Permanently deletes an item.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function delete_item( $input = array() ) {
		$input = (array) $input;

		$post = Cpt_Support::require_item( $input );
		if ( $post instanceof WP_Error ) {
			return $post;
		}

		if ( empty( $input['confirm'] ) ) {
			return new WP_Error(
				'confirmation_required',
				sprintf(
					/* translators: 1: item title, 2: item ID */
					__( 'Permanently deleting "%1$s" (#%2$d) cannot be undone. Pass confirm as true to proceed, or move it to the trash instead.', 'mosmcp-abilities' ),
					(string) get_the_title( $post ),
					(int) $post->ID
				)
			);
		}

		$title = (string) get_the_title( $post );
		$id    = (int) $post->ID;
		$type  = (string) $post->post_type;

		if ( ! wp_delete_post( $id, true ) ) {
			return new WP_Error( 'delete_failed', __( 'WordPress could not delete this item.', 'mosmcp-abilities' ) );
		}

		return array(
			'id'        => $id,
			'post_type' => $type,
			'title'     => $title,
			'deleted'   => true,
			'warnings'  => array(),
		);
	}

	/* -------------------------------------------------------------- template */

	/**
	 * Reports the template and display settings for an item.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function template_get( $input = array() ) {
		$post = Cpt_Support::require_item( (array) $input );
		if ( $post instanceof WP_Error ) {
			return $post;
		}

		return Post_Display::template_get( (array) $input, (string) $post->post_type );
	}

	/**
	 * Assigns a template to an item.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function template_set( $input = array() ) {
		$post = Cpt_Support::require_item( (array) $input );
		if ( $post instanceof WP_Error ) {
			return $post;
		}

		return Post_Display::template_set( (array) $input, (string) $post->post_type );
	}

	/* --------------------------------------------------------------- helpers */

	/**
	 * Validates a requested status against the type's publish capability.
	 *
	 * @param \WP_Post_Type $type   Post type object.
	 * @param string        $status Requested status.
	 * @return true|WP_Error
	 */
	private static function check_status( $type, $status ) {
		$allowed = array( 'draft', 'pending', 'private', 'publish' );

		if ( ! in_array( $status, $allowed, true ) ) {
			return new WP_Error(
				'invalid_status',
				sprintf(
					/* translators: 1: requested status, 2: comma-separated allowed statuses */
					__( 'Status "%1$s" is not supported here. Use one of: %2$s.', 'mosmcp-abilities' ),
					$status,
					implode( ', ', $allowed )
				)
			);
		}

		if ( in_array( $status, array( 'publish', 'private' ), true ) && ! current_user_can( $type->cap->publish_posts ) ) {
			return new WP_Error(
				'cannot_publish',
				sprintf(
					/* translators: 1: status, 2: capability name */
					__( 'Setting the status to "%1$s" needs the "%2$s" capability, which the connected account does not have. Leave it as a draft instead.', 'mosmcp-abilities' ),
					$status,
					(string) $type->cap->publish_posts
				)
			);
		}

		return true;
	}

	/**
	 * Validates a proposed parent: same type, exists, and no cycle.
	 *
	 * @param \WP_Post_Type $type      Post type object.
	 * @param int           $post_id   The item being moved, or 0 when creating.
	 * @param int           $parent_id Proposed parent, or 0 for top level.
	 * @return true|WP_Error
	 */
	private static function check_parent( $type, $post_id, $parent_id ) {
		if ( 0 === $parent_id ) {
			return true;
		}

		$parent = get_post( $parent_id );

		if ( ! $parent || $parent->post_type !== $type->name ) {
			return new WP_Error(
				'invalid_parent',
				sprintf(
					/* translators: 1: parent ID, 2: post type slug */
					__( 'Item %1$d is not an existing "%2$s", so it cannot be the parent.', 'mosmcp-abilities' ),
					$parent_id,
					(string) $type->name
				)
			);
		}

		if ( $post_id > 0 && $parent_id === $post_id ) {
			return new WP_Error(
				'parent_is_self',
				__( 'An item cannot be its own parent.', 'mosmcp-abilities' )
			);
		}

		/*
		 * Walking up from the proposed parent catches the case that would otherwise
		 * detach a whole branch from the tree: making an item a child of one of its
		 * own descendants.
		 */
		if ( $post_id > 0 ) {
			$seen   = array();
			$cursor = $parent;
			while ( $cursor && (int) $cursor->post_parent > 0 ) {
				if ( isset( $seen[ (int) $cursor->ID ] ) ) {
					break;
				}
				$seen[ (int) $cursor->ID ] = true;

				if ( (int) $cursor->post_parent === $post_id ) {
					return new WP_Error(
						'parent_is_descendant',
						sprintf(
							/* translators: 1: proposed parent ID, 2: item ID */
							__( 'Item %1$d sits below item %2$d already, so making it the parent would detach the branch.', 'mosmcp-abilities' ),
							$parent_id,
							$post_id
						)
					);
				}
				$cursor = get_post( (int) $cursor->post_parent );
			}
		}

		return true;
	}

	/**
	 * A short description of one item, for listings.
	 *
	 * @param WP_Post $post Post.
	 * @return array<string, mixed>
	 */
	private static function summarize( WP_Post $post ) {
		return array(
			'id'         => (int) $post->ID,
			'title'      => (string) get_the_title( $post ),
			'status'     => (string) $post->post_status,
			'slug'       => (string) $post->post_name,
			'parent_id'  => (int) $post->post_parent,
			'menu_order' => (int) $post->menu_order,
			'modified'   => (string) $post->post_modified,
			'edit_url'   => (string) get_edit_post_link( $post->ID, 'raw' ),
		);
	}

	/**
	 * The custom fields on an item, with who manages each.
	 *
	 * @param WP_Post $post Post.
	 * @return array<int, array<string, mixed>>
	 */
	private static function read_fields( WP_Post $post ) {
		$out = array();

		foreach ( get_post_meta( (int) $post->ID ) as $key => $rows ) {
			$key = (string) $key;

			if ( Cpt_Support::is_internal_meta( $key ) ) {
				continue;
			}
			// The sibling key row that ACF stores alongside each value is plumbing.
			if ( 0 === strpos( $key, '_' ) && '' !== Cpt_Support::acf_field_key( (int) $post->ID, ltrim( $key, '_' ) ) ) {
				continue;
			}

			$acf_key    = Cpt_Support::acf_field_key( (int) $post->ID, $key );
			$registered = Cpt_Support::registration_for( (string) $post->post_type, $key );

			$values = array_map( 'maybe_unserialize', array_values( (array) $rows ) );

			/*
			 * Asked of the same function the write path calls, rather than re-deriving
			 * the answer from a subset of its rules. A field can also be refused by its
			 * own auth_callback, or by a post type whose capabilities no role holds,
			 * and neither is visible from the ACF and protected-key checks alone. A
			 * caller that reads writable=true and is then refused has been misled,
			 * which is worse than being told no up front.
			 */
			$gate = Cpt_Support::check_meta_writable( $post, $key );

			$out[] = array(
				'key'                 => $key,
				'value'               => self::render_value( count( $values ) > 1 ? $values : reset( $values ) ),
				'repeatable'          => count( $values ) > 1,
				'registered'          => (bool) $registered,
				'managed_by'          => '' !== $acf_key ? 'acf' : ( $registered ? 'registered' : 'unregistered' ),
				'writable'            => ! ( $gate instanceof WP_Error ),
				'not_writable_reason' => $gate instanceof WP_Error ? (string) $gate->get_error_code() : '',
			);
		}

		usort(
			$out,
			static function ( $a, $b ) {
				return strcmp( $a['key'], $b['key'] );
			}
		);

		return $out;
	}

	/**
	 * Renders a field value for display.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function render_value( $value ) {
		if ( null === $value || false === $value ) {
			return '';
		}
		if ( is_scalar( $value ) ) {
			$text = (string) $value;
		} else {
			$text = (string) wp_json_encode( $value );
		}
		return strlen( $text ) > 500 ? substr( $text, 0, 497 ) . '...' : $text;
	}
}
