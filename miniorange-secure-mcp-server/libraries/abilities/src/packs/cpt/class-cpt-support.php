<?php
/**
 * Shared foundation for the custom post type abilities.
 *
 * Three things live here, and they are the whole reason this pack can be generic
 * without being reckless.
 *
 * 1. Which post types the pack covers. Custom types registered by a site are in
 *    scope; the built-in types and the infrastructure types other packs already own
 *    are not, because two tools editing the same data with different assumptions is
 *    how data gets corrupted.
 *
 * 2. What a type actually is. A custom type is not "a post with a different label":
 *    its real content lives in custom fields and custom taxonomies, its capabilities
 *    may be its own, and the features it supports decide which fields mean anything
 *    at all. All of that is read from WordPress at runtime rather than assumed.
 *
 * 3. When a custom field may be written. WordPress has a policy for this and
 *    update_post_meta() ignores it completely, so every write in this pack is
 *    checked against that policy first.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Cpt;

use MoSMCP\Abilities\Config;
use WP_Error;
use WP_Post;

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
 * Class Cpt_Support
 */
class Cpt_Support {

	/**
	 * Custom post types this pack refuses to touch, and why.
	 *
	 * Every entry is either owned by a pack that understands its semantics, or is
	 * internal plumbing where a generic field write would corrupt a site. A generic
	 * "set this meta value" on a WooCommerce product can desynchronise price, stock
	 * and lookup tables; on an ACF field-group post it rewrites a schema.
	 *
	 * @var string[]
	 */
	const EXCLUDED_TYPES = array(
		// WooCommerce — the WooCommerce pack owns these, with real product semantics.
		'product',
		'product_variation',
		'shop_order',
		'shop_order_refund',
		'shop_coupon',
		'shop_subscription',
		// Elementor internals.
		'elementor_library',
		'e-floating-buttons',
		// ACF stores its own schema as posts; the ACF pack owns them.
		'acf-field',
		'acf-field-group',
		'acf-post-type',
		'acf-taxonomy',
		'acf-ui-options-page',
		// Forms plugins, owned by the forms pack.
		'wpforms',
		'wpforms_log',
		'wpcf7_contact_form',
		// Block theme plumbing.
		'wp_template',
		'wp_template_part',
		'wp_global_styles',
		'wp_navigation',
		'wp_block',
		'wp_font_family',
		'wp_font_face',
		// Yoast and SEO plumbing.
		'wpseo_redirect',
	);

	/**
	 * Meta keys never reported or written by this pack.
	 *
	 * These are WordPress and page-builder internals with their own abilities or
	 * their own invariants; a caller that wants to change a layout, a template or a
	 * featured image has a purpose-built ability for it.
	 *
	 * @var string[]
	 */
	const INTERNAL_META_PREFIXES = array(
		'_edit_',
		'_wp_',
		'_elementor',
		'_oembed_',
		'_wpas_',
		'_publicize_',
		'_thumbnail_id',
		'_pingme',
		'_encloseme',
	);

	/**
	 * The post types this pack operates on.
	 *
	 * @return array<string, \WP_Post_Type> Keyed by slug.
	 */
	public static function eligible_types() {
		$types = array();

		foreach ( get_post_types( array( 'show_ui' => true ), 'objects' ) as $slug => $object ) {
			if ( ! empty( $object->_builtin ) ) {
				continue;
			}
			if ( in_array( $slug, self::EXCLUDED_TYPES, true ) ) {
				continue;
			}
			$types[ $slug ] = $object;
		}

		/**
		 * Filters the custom post types the CPT abilities operate on.
		 *
		 * Add a slug to bring a type in scope — a WooCommerce product, say, if a site
		 * wants generic field access to it — or remove one to put a type off limits.
		 *
		 * @param string[] $slugs Eligible post type slugs.
		 */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Prefixed via Config::prefix(); the library's prefix is host-configurable and cannot be a literal.
		$allowed = apply_filters( Config::prefix() . '_cpt_post_types', array_keys( $types ) );

		if ( ! is_array( $allowed ) ) {
			return $types;
		}

		$filtered = array();
		foreach ( $allowed as $slug ) {
			$slug   = (string) $slug;
			$object = get_post_type_object( $slug );
			if ( $object ) {
				$filtered[ $slug ] = $object;
			}
		}

		return $filtered;
	}

	/**
	 * Resolves and validates the requested post type.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return \WP_Post_Type|WP_Error
	 */
	public static function require_type( array $input ) {
		$slug = isset( $input['post_type'] ) ? sanitize_key( (string) $input['post_type'] ) : '';

		if ( '' === $slug ) {
			return new WP_Error(
				'missing_post_type',
				sprintf(
					/* translators: %s: comma-separated list of available type slugs */
					__( 'Provide a post_type. This site\'s custom types are: %s.', 'mosmcp-abilities' ),
					self::available_list()
				)
			);
		}

		$eligible = self::eligible_types();

		if ( isset( $eligible[ $slug ] ) ) {
			return $eligible[ $slug ];
		}

		// A recognised type that this pack deliberately does not handle deserves a
		// different answer from one that does not exist at all.
		if ( post_type_exists( $slug ) ) {
			return new WP_Error(
				'post_type_not_covered',
				sprintf(
					/* translators: 1: requested type slug, 2: comma-separated list of available slugs */
					__( 'The "%1$s" type is not handled by these custom-content abilities, because another part of this plugin manages it with knowledge of its specific rules. Posts and pages have their own abilities, and so do WooCommerce products, ACF field groups and form entries. Types handled here: %2$s.', 'mosmcp-abilities' ),
					$slug,
					self::available_list()
				)
			);
		}

		return new WP_Error(
			'unknown_post_type',
			sprintf(
				/* translators: 1: requested type slug, 2: comma-separated list of available slugs */
				__( 'No post type called "%1$s" is registered on this site. Available custom types: %2$s.', 'mosmcp-abilities' ),
				$slug,
				self::available_list()
			)
		);
	}

	/**
	 * Resolves an item and confirms it belongs to the requested type.
	 *
	 * @param array<string, mixed> $input     Ability input.
	 * @param string               $id_field  Which input field carries the ID.
	 * @return WP_Post|WP_Error
	 */
	public static function require_item( array $input, $id_field = 'id' ) {
		$type = self::require_type( $input );
		if ( $type instanceof WP_Error ) {
			return $type;
		}

		$id   = isset( $input[ $id_field ] ) ? absint( $input[ $id_field ] ) : 0;
		$post = $id > 0 ? get_post( $id ) : null;

		if ( ! $post ) {
			return new WP_Error(
				'item_not_found',
				sprintf(
					/* translators: %d: requested ID */
					__( 'Nothing found with ID %d.', 'mosmcp-abilities' ),
					$id
				)
			);
		}

		if ( $post->post_type !== $type->name ) {
			return new WP_Error(
				'wrong_post_type',
				sprintf(
					/* translators: 1: ID, 2: the type it actually is, 3: the type that was requested */
					__( 'Item %1$d is a "%2$s", not a "%3$s". Pass the post_type it actually belongs to.', 'mosmcp-abilities' ),
					$id,
					(string) $post->post_type,
					(string) $type->name
				)
			);
		}

		return $post;
	}

	/**
	 * A readable list of the types this pack handles, for error messages.
	 *
	 * @return string
	 */
	public static function available_list() {
		$slugs = array_keys( self::eligible_types() );

		if ( ! $slugs ) {
			return __( 'none — this site has no custom post types', 'mosmcp-abilities' );
		}

		return implode( ', ', $slugs );
	}

	/**
	 * The features a post type declares, from the set that changes what can be edited.
	 *
	 * @param string $post_type Post type slug.
	 * @return string[]
	 */
	public static function supports( $post_type ) {
		$features = array(
			'title',
			'editor',
			'excerpt',
			'thumbnail',
			'page-attributes',
			'custom-fields',
			'comments',
			'revisions',
			'author',
		);

		$out = array();
		foreach ( $features as $feature ) {
			if ( post_type_supports( $post_type, $feature ) ) {
				$out[] = $feature;
			}
		}

		return $out;
	}

	/**
	 * Describes one post type: shape, taxonomies, fields and access.
	 *
	 * @param \WP_Post_Type $type  Post type object.
	 * @param bool          $deep  Whether to include per-taxonomy term counts and full meta detail.
	 * @return array<string, mixed>
	 */
	public static function describe_type( $type, $deep = false ) {
		$slug = (string) $type->name;

		$counts = array();
		foreach ( (array) wp_count_posts( $slug ) as $status => $n ) {
			if ( (int) $n > 0 ) {
				$counts[ $status ] = (int) $n;
			}
		}

		$taxonomies = array();
		foreach ( get_object_taxonomies( $slug, 'objects' ) as $tax ) {
			$entry = array(
				'slug'         => (string) $tax->name,
				'label'        => (string) $tax->label,
				'hierarchical' => (bool) $tax->hierarchical,
			);
			if ( $deep ) {
				$entry['term_count'] = (int) wp_count_terms(
					array(
						'taxonomy'   => $tax->name,
						'hide_empty' => false,
					)
				);
			}
			$taxonomies[] = $entry;
		}

		$described = array(
			'post_type'         => $slug,
			'label'             => (string) $type->labels->name,
			'singular_label'    => (string) $type->labels->singular_name,
			'hierarchical'      => (bool) $type->hierarchical,
			'public'            => (bool) $type->public,
			'supports'          => self::supports( $slug ),
			'taxonomies'        => $taxonomies,
			'counts'            => $counts,
			'elementor_enabled' => post_type_supports( $slug, 'elementor' ),
			'access'            => self::access_for( $type ),
		);

		if ( $deep ) {
			$described['fields']     = self::describe_fields( $slug );
			$described['edit_notes'] = self::edit_notes( $type );
		}

		return $described;
	}

	/**
	 * What the connected account may do with this type.
	 *
	 * A custom type can declare its own capability_type, in which case its
	 * capabilities exist but may be granted to no role at all — leaving the type
	 * unreachable even for an administrator. That is a site configuration issue
	 * rather than a bug, and reporting it plainly is the fastest route to a fix.
	 *
	 * @param \WP_Post_Type $type Post type object.
	 * @return array<string, mixed>
	 */
	public static function access_for( $type ) {
		$caps = $type->cap;

		$can_create  = current_user_can( $caps->create_posts );
		$can_publish = current_user_can( $caps->publish_posts );
		$can_edit    = current_user_can( $caps->edit_posts );

		$access = array(
			'can_create'          => (bool) $can_create,
			'can_edit'            => (bool) $can_edit,
			'can_publish'         => (bool) $can_publish,
			'can_delete_others'   => current_user_can( $caps->delete_others_posts ),
			'create_capability'   => (string) $caps->create_posts,
			'publish_capability'  => (string) $caps->publish_posts,
			'uses_own_capabilities' => 'post' !== (string) $type->capability_type,
		);

		if ( $access['uses_own_capabilities'] && ! $can_edit && ! $can_create ) {
			$access['diagnosis'] = sprintf(
				/* translators: 1: post type label, 2: capability name */
				__( 'The %1$s type declares its own capabilities rather than reusing the built-in post ones, and the connected account holds none of them — it needs "%2$s" and its siblings granted to a role before this type can be read or edited. Until then even an administrator is refused, because WordPress grants custom capabilities to nobody by default.', 'mosmcp-abilities' ),
				(string) $type->labels->singular_name,
				(string) $caps->edit_posts
			);
		}

		return $access;
	}

	/**
	 * The custom fields a type declares, and how each may be written.
	 *
	 * @param string $post_type Post type slug.
	 * @return array<int, array<string, mixed>>
	 */
	public static function describe_fields( $post_type ) {
		$fields = array();

		foreach ( get_registered_meta_keys( 'post', $post_type ) as $key => $args ) {
			if ( self::is_internal_meta( (string) $key ) ) {
				continue;
			}

			$fields[] = array(
				'key'         => (string) $key,
				'type'        => isset( $args['type'] ) ? (string) $args['type'] : 'string',
				'repeatable'  => empty( $args['single'] ),
				'registered'  => true,
				'protected'   => is_protected_meta( (string) $key, 'post' ),
				'description' => isset( $args['description'] ) ? (string) $args['description'] : '',
				'managed_by'  => 'registered',
			);
		}

		foreach ( self::acf_fields_for( $post_type ) as $acf ) {
			$fields[] = $acf;
		}

		/*
		 * JetEngine last, and only for keys nothing has described yet. A site can
		 * both declare a key and build it in JetEngine, and the declared description
		 * is the one WordPress actually enforces.
		 */
		$described = wp_list_pluck( $fields, 'key' );

		foreach ( self::jetengine_fields_for( $post_type ) as $jet ) {
			if ( in_array( $jet['key'], $described, true ) ) {
				continue;
			}
			$fields[] = $jet;
		}

		usort(
			$fields,
			static function ( $a, $b ) {
				return strcmp( $a['key'], $b['key'] );
			}
		);

		return $fields;
	}

	/**
	 * ACF fields attached to a post type, described the same way as registered meta.
	 *
	 * ACF does not use register_post_meta(), so its fields are invisible to the
	 * registered-meta list even though they are the fields a site actually edits.
	 * Reporting them — and marking who manages them — is what stops a caller
	 * reaching for the generic field abilities and breaking ACF's storage.
	 *
	 * @param string $post_type Post type slug.
	 * @return array<int, array<string, mixed>>
	 */
	public static function acf_fields_for( $post_type ) {
		if ( ! function_exists( 'acf_get_field_groups' ) || ! function_exists( 'acf_get_fields' ) ) {
			return array();
		}

		/*
		 * Memoised for the request. Reading one item asks this question once per field
		 * that has no ACF sibling row, so an item with forty fields was interrogating
		 * ACF's field groups eighty times to receive the same answer. The list cannot
		 * change within a request — editing a field group is a separate one.
		 */
		static $memo = array();

		$post_type = (string) $post_type;

		if ( isset( $memo[ $post_type ] ) ) {
			return $memo[ $post_type ];
		}

		$out = array();

		foreach ( acf_get_field_groups( array( 'post_type' => $post_type ) ) as $group ) {
			foreach ( (array) acf_get_fields( $group ) as $field ) {
				if ( empty( $field['name'] ) ) {
					continue;
				}
				$out[] = array(
					'key'         => (string) $field['name'],
					'type'        => isset( $field['type'] ) ? (string) $field['type'] : 'string',
					'repeatable'  => in_array( ( $field['type'] ?? '' ), array( 'repeater', 'flexible_content' ), true ),
					'registered'  => false,
					'protected'   => false,
					'description' => isset( $field['label'] ) ? (string) $field['label'] : '',
					'managed_by'  => 'acf',
					'acf_key'     => isset( $field['key'] ) ? (string) $field['key'] : '',
				);
			}
		}

		$memo[ $post_type ] = $out;

		return $out;
	}

	/**
	 * Whether a meta key is WordPress or page-builder internals.
	 *
	 * @param string $key Meta key.
	 * @return bool
	 */
	public static function is_internal_meta( $key ) {
		foreach ( self::INTERNAL_META_PREFIXES as $prefix ) {
			if ( 0 === strpos( $key, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a meta key on a post is managed by ACF.
	 *
	 * ACF stores every field as a pair: the value under `name`, and the field key
	 * under `_name`. Writing the value row without ACF's own handling leaves the
	 * pair inconsistent and breaks how ACF reads and formats the field, so the
	 * generic field abilities refuse these and defer to the ACF abilities.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key.
	 * @return string ACF field key when managed by ACF, empty string otherwise.
	 */
	public static function acf_field_key( $post_id, $key ) {
		$key = (string) $key;

		if ( '' === $key || 0 === strpos( $key, '_' ) ) {
			return '';
		}

		$sibling = get_post_meta( (int) $post_id, '_' . $key, true );
		if ( is_string( $sibling ) && 0 === strpos( $sibling, 'field_' ) ) {
			return $sibling;
		}

		// A field defined for the type but not yet saved on this item has no sibling
		// row, so fall back to the field group definitions.
		if ( function_exists( 'acf_get_field' ) ) {
			$post = get_post( (int) $post_id );
			if ( $post ) {
				foreach ( self::acf_fields_for( $post->post_type ) as $field ) {
					if ( $field['key'] === $key && ! empty( $field['acf_key'] ) ) {
						return (string) $field['acf_key'];
					}
				}
			}
		}

		return '';
	}

	/**
	 * Get JetEngine meta fields registered for a post type.
	 *
	 * JetEngine stores its own fields outside of register_post_meta() and ACF,
	 * so this reaches into JetEngine's internal meta_boxes object to list them.
	 * Results are memoised per request. Returns an empty array if JetEngine
	 * isn't active or its internals don't match what we expect.
	 *
	 * @param string $post_type Post type slug.
	 * @return array<int, array<string, mixed>> List of field definitions, or empty array.
	 */
	public static function jetengine_fields_for( $post_type ) {
		// Memoised for the request, as acf_fields_for() is and for the same reason:
		// reading one item asks this question once per field.
		static $memo = array();

		$post_type = (string) $post_type;

		if ( isset( $memo[ $post_type ] ) ) {
			return $memo[ $post_type ];
		}

		$memo[ $post_type ] = array();

		if ( ! function_exists( 'jet_engine' ) ) {
			return $memo[ $post_type ];
		}

		try {
			$engine = jet_engine();

			if ( ! is_object( $engine ) || ! isset( $engine->meta_boxes ) || ! is_object( $engine->meta_boxes ) ) {
				return $memo[ $post_type ];
			}

			$boxes  = $engine->meta_boxes;
			$object = 'post_type::' . $post_type;
			$raw    = array();

			foreach ( array( 'get_meta_fields_for_object', 'get_fields_for_object' ) as $method ) {
				if ( ! method_exists( $boxes, $method ) ) {
					continue;
				}
				$result = $boxes->$method( $object );
				if ( is_array( $result ) && $result ) {
					$raw = $result;
					break;
				}
			}

			if ( ! $raw && method_exists( $boxes, 'get_registered_fields' ) ) {
				$all = $boxes->get_registered_fields();
				if ( is_array( $all ) ) {
					foreach ( array( $object, $post_type ) as $lookup ) {
						if ( ! empty( $all[ $lookup ] ) && is_array( $all[ $lookup ] ) ) {
							$raw = $all[ $lookup ];
							break;
						}
					}
				}
			}

			$memo[ $post_type ] = self::normalise_jetengine_fields( $raw );
		} catch ( \Throwable $e ) {
			$memo[ $post_type ] = array();
		}

		return $memo[ $post_type ];
	}

	/**
	 * Turns JetEngine's field descriptors into this pack's field shape.
	 *
	 * Written to tolerate a descriptor that is missing anything but its name, since
	 * the source is another plugin's internal structure.
	 *
	 * @param mixed $raw Whatever JetEngine returned.
	 * @return array<int, array<string, mixed>>
	 */
	private static function normalise_jetengine_fields( $raw ) {
		$out = array();

		if ( ! is_array( $raw ) ) {
			return $out;
		}

		foreach ( $raw as $field ) {
			if ( ! is_array( $field ) || empty( $field['name'] ) ) {
				continue;
			}

			$key = (string) $field['name'];
			if ( self::is_internal_meta( $key ) ) {
				continue;
			}

			$out[] = array(
				'key'         => $key,
				'type'        => self::jetengine_storage_type( $field ),
				/*
				 * False even for a repeater. "Repeatable" here means several postmeta
				 * rows under one key, which is what the write path acts on; JetEngine
				 * keeps a repeater as one serialised array in a single row, so saying
				 * true would make the write path delete and re-add rows and destroy
				 * the value.
				 */
				'repeatable'  => false,
				'registered'  => false,
				'protected'   => false,
				'description' => isset( $field['title'] ) ? (string) $field['title'] : '',
				'managed_by'  => 'jetengine',
			);
		}

		return $out;
	}

	/**
	 * The storage format JetEngine uses for one of its fields.
	 *
	 * @param array<string, mixed> $field JetEngine field descriptor.
	 * @return string One of this pack's coercion types.
	 */
	private static function jetengine_storage_type( array $field ) {
		$type = isset( $field['type'] ) ? (string) $field['type'] : 'text';

		// A JetEngine date or datetime field stores a Unix timestamp only when its
		// "Save as timestamp" box is ticked; otherwise it keeps a formatted string.
		$timestamp = ! empty( $field['is_timestamp'] );

		switch ( $type ) {
			case 'date':
				return $timestamp ? 'timestamp' : 'date';

			case 'datetime':
			case 'datetime-local':
				return $timestamp ? 'timestamp' : 'datetime';

			case 'time':
				return 'time';

			case 'switcher':
				return 'boolean';

			case 'number':
				return 'number';

			case 'checkbox':
			case 'repeater':
				return 'array';

			default:
				return 'string';
		}
	}

	/**
	 * One JetEngine field on a type, by key.
	 *
	 * @param string $post_type Post type slug.
	 * @param string $key       Meta key.
	 * @return array<string, mixed>|null
	 */
	public static function jetengine_field( $post_type, $key ) {
		foreach ( self::jetengine_fields_for( $post_type ) as $field ) {
			if ( $field['key'] === (string) $key ) {
				return $field;
			}
		}
		return null;
	}

	/**
	 * Whether anything on this site claims a custom field.
	 *
	 * Consulted only for types that provide no custom-fields editor of their own, to
	 * separate "a plugin owns this field and renders it in its own screens" from
	 * "this key exists nowhere and nothing would ever read it".
	 *
	 * @param WP_Post $post Target post.
	 * @param string  $key  Meta key.
	 * @return bool
	 */
	public static function field_has_owner( WP_Post $post, $key ) {
		$post_type = (string) $post->post_type;
		$key       = (string) $key;

		if ( self::registration_for( $post_type, $key ) ) {
			return true;
		}

		foreach ( self::acf_fields_for( $post_type ) as $field ) {
			if ( $field['key'] === $key ) {
				return true;
			}
		}

		if ( self::jetengine_field( $post_type, $key ) ) {
			return true;
		}

		// Already stored on this item: something wrote it, so something reads it.
		// This is also the cheap answer for every field arriving from a read, which
		// is the path that asks this question most often.
		if ( metadata_exists( 'post', (int) $post->ID, $key ) ) {
			return true;
		}

		return self::type_stores_field( $post_type, $key );
	}

	/**
	 * Whether any item of a type already stores a meta key.
	 *
	 * The last resort behind field_has_owner(). A field plugin this pack cannot
	 * introspect still leaves its values in postmeta, so a key the type's existing
	 * items carry is plainly a real field of that type — that is how a field can be
	 * set on a newly created item before anything has been written to it. One
	 * bounded query per key per request.
	 *
	 * @param string $post_type Post type slug.
	 * @param string $key       Meta key.
	 * @return bool
	 */
	private static function type_stores_field( $post_type, $key ) {
		static $memo = array();

		$cache_key = $post_type . '|' . $key;

		if ( isset( $memo[ $cache_key ] ) ) {
			return $memo[ $cache_key ];
		}

		$found = get_posts(
			array(
				'post_type'              => $post_type,
				'post_status'            => 'any',
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_term_cache' => false,
				'update_post_meta_cache' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_key'               => $key,
				'meta_compare'           => 'EXISTS',
			)
		);

		$memo[ $cache_key ] = ! empty( $found );

		return $memo[ $cache_key ];
	}

	/**
	 * Decides whether a custom field may be written, and how.
	 *
	 * WordPress already has a policy here, expressed through the `edit_post_meta`
	 * meta capability: it consults the key's registered auth_callback and refuses
	 * unregistered protected keys. update_post_meta() bypasses that entirely, so
	 * this is checked before every write rather than trusted afterwards.
	 *
	 * @param WP_Post $post Target post.
	 * @param string  $key  Meta key.
	 * @return array<string, mixed>|WP_Error Registration detail on success.
	 */
	public static function check_meta_writable( WP_Post $post, $key ) {
		$key = (string) $key;

		if ( '' === $key ) {
			return new WP_Error(
				'missing_field',
				__( 'Provide the name of the field to write.', 'mosmcp-abilities' )
			);
		}

		/*
		 * 'custom-fields' support only controls whether core shows its own Custom
         * Fields metabox — it's not a storage or permission rule, and neither
         * update_post_meta() nor field plugins (JetEngine, Pods, Meta Box, CMB2,
         * Toolset) consult it, since they render their own meta boxes. Refusing
         * writes just because it's off used to lock those plugins' fields
         * permanently.
         *
         * So we only refuse when no core editor AND nothing else owns the field
         * (no plugin, no prior stored value) — a write would go somewhere unreadable.
         * Everything else falls through to the edit_post_meta check below.
		 */
		if ( ! post_type_supports( $post->post_type, 'custom-fields' ) && ! self::field_has_owner( $post, $key ) ) {
			return new WP_Error(
				'custom_fields_not_supported',
				sprintf(
					/* translators: 1: meta key, 2: post type slug */
					__( 'Nothing manages the field "%1$s" on the "%2$s" type: no plugin or theme declares it, no field plugin owns it, no item of this type has ever stored it, and the type has no custom-fields editor of its own. A value written there could never be read back or displayed. Check the name against the describe-type ability, or have the plugin that owns the field declare it.', 'mosmcp-abilities' ),
					$key,
					(string) $post->post_type
				)
			);
		}

		if ( self::is_internal_meta( $key ) ) {
			return new WP_Error(
				'field_is_internal',
				sprintf(
					/* translators: %s: meta key */
					__( '"%s" is WordPress or page-builder internal storage, not a content field. There are purpose-built abilities for featured images, templates and Elementor layouts.', 'mosmcp-abilities' ),
					$key
				)
			);
		}

		$acf_key = self::acf_field_key( (int) $post->ID, $key );
		if ( '' !== $acf_key ) {
			return new WP_Error(
				'field_managed_by_acf',
				sprintf(
					/* translators: 1: field name, 2: ACF field key */
					__( 'The field "%1$s" is managed by Advanced Custom Fields (%2$s). ACF stores each field as a value plus a separate key row, and writing only the value leaves the pair inconsistent and breaks how ACF reads it. Use the ACF abilities to change this field instead.', 'mosmcp-abilities' ),
					$key,
					$acf_key
				)
			);
		}

		$registered = self::registration_for( $post->post_type, $key );

		if ( ! $registered && is_protected_meta( $key, 'post' ) ) {
			return new WP_Error(
				'field_protected_and_unregistered',
				sprintf(
					/* translators: %s: meta key */
					__( '"%s" starts with an underscore, which marks it as private storage, and no plugin or theme has declared it as an editable field. Writing an undeclared private key is how plugin data gets corrupted, so it is refused.', 'mosmcp-abilities' ),
					$key
				)
			);
		}

		/*
		 * The decisive check. For a registered key this runs its auth_callback; for
		 * anything protected it refuses. It is also the check that reveals a type
		 * whose capabilities were never granted to any role.
		 */
		if ( ! current_user_can( 'edit_post_meta', (int) $post->ID, $key ) ) {
			return new WP_Error(
				'field_not_writable',
				sprintf(
					/* translators: 1: meta key, 2: post ID */
					__( 'The connected account is not permitted to write the field "%1$s" on item %2$d. Fields can carry their own permission rules, and a custom post type can declare capabilities that no role has been granted.', 'mosmcp-abilities' ),
					$key,
					(int) $post->ID
				)
			);
		}

		/*
		 * A declared type wins, because it is the one WordPress will validate
		 * against. Failing that, a field plugin's own definition is far better than
		 * assuming text: writing "2026-08-20" into a JetEngine date field that holds
		 * a Unix timestamp stores something its templates cannot read.
		 */
		$jet = $registered ? null : self::jetengine_field( (string) $post->post_type, $key );

		if ( $registered && isset( $registered['type'] ) ) {
			$type = (string) $registered['type'];
		} elseif ( $jet ) {
			$type = (string) $jet['type'];
		} else {
			$type = 'string';
		}

		return array(
			'key'        => $key,
			'registered' => (bool) $registered,
			'type'       => $type,
			'repeatable' => $registered ? empty( $registered['single'] ) : false,
			'managed_by' => $registered ? 'registered' : ( $jet ? 'jetengine' : 'unregistered' ),
		);
	}

	/**
	 * The registration arguments for a meta key on a post type, if any.
	 *
	 * @param string $post_type Post type slug.
	 * @param string $key       Meta key.
	 * @return array<string, mixed>|null
	 */
	public static function registration_for( $post_type, $key ) {
		foreach ( array( $post_type, '' ) as $subtype ) {
			$keys = get_registered_meta_keys( 'post', $subtype );
			if ( isset( $keys[ $key ] ) ) {
				return $keys[ $key ];
			}
		}
		return null;
	}

	/**
	 * Coerces a supplied value to a registered field's declared type.
	 *
	 * @param mixed  $value Supplied value.
	 * @param string $type  Declared type.
	 * @return mixed|WP_Error
	 */
	public static function coerce( $value, $type ) {
		switch ( $type ) {
			case 'integer':
				if ( ! is_numeric( $value ) ) {
					return new WP_Error(
						'field_type_mismatch',
						__( 'This field is declared as a whole number, so it needs a numeric value.', 'mosmcp-abilities' )
					);
				}
				return (int) $value;

			case 'number':
				if ( ! is_numeric( $value ) ) {
					return new WP_Error(
						'field_type_mismatch',
						__( 'This field is declared as a number, so it needs a numeric value.', 'mosmcp-abilities' )
					);
				}
				return (float) $value;

			case 'boolean':
				return (bool) filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );

			/*
			 * These date types cover plugin-defined fields (JetEngine's, today), so a
             * human-readable value like "20 Aug 2026 at 7pm" gets converted to whatever
             * format the field actually stores (e.g. a Unix timestamp).
             *
             * strtotime()/gmdate() are paired deliberately: WordPress fixes PHP's
             * timezone to UTC, so parsing and formatting round-trip without drift.
			 */
			case 'timestamp':
				if ( is_numeric( $value ) ) {
					return (string) (int) $value;
				}

				$parsed = strtotime( (string) $value );
				if ( false === $parsed ) {
					return new WP_Error(
						'field_type_mismatch',
						__( 'This field stores a date as a Unix timestamp. Send a timestamp, or a date this site can read such as "2026-08-20 19:00".', 'mosmcp-abilities' )
					);
				}
				return (string) $parsed;

			case 'date':
			case 'datetime':
				$parsed = is_numeric( $value ) ? (int) $value : strtotime( (string) $value );
				if ( false === $parsed ) {
					return new WP_Error(
						'field_type_mismatch',
						__( 'This field stores a date. Send one this site can read, such as "2026-08-20 19:00".', 'mosmcp-abilities' )
					);
				}
				return gmdate( 'datetime' === $type ? 'Y-m-d H:i' : 'Y-m-d', $parsed );

			case 'time':
				$raw = trim( (string) $value );

				// Already a clock time. Matched first so "19:00" is never sent
				// through strtotime(), which would read it as a moment today.
				if ( preg_match( '/^([01]?[0-9]|2[0-3]):([0-5][0-9])(:[0-5][0-9])?$/', $raw, $parts ) ) {
					return sprintf( '%02d:%02d', (int) $parts[1], (int) $parts[2] );
				}

				$parsed = is_numeric( $raw ) ? (int) $raw : strtotime( $raw );
				if ( false === $parsed ) {
					return new WP_Error(
						'field_type_mismatch',
						__( 'This field stores a time of day. Send it as "19:00".', 'mosmcp-abilities' )
					);
				}
				return gmdate( 'H:i', $parsed );

			case 'array':
			case 'object':
				if ( is_string( $value ) ) {
					$decoded = json_decode( $value, true );
					if ( null === $decoded && JSON_ERROR_NONE !== json_last_error() ) {
						return new WP_Error(
							'field_type_mismatch',
							__( 'This field holds structured data, so it needs valid JSON.', 'mosmcp-abilities' )
						);
					}
					return $decoded;
				}
				return $value;

			default:
				return is_scalar( $value ) ? (string) $value : wp_json_encode( $value );
		}
	}

	/**
	 * Notes about editing this type that a caller would otherwise learn by failing.
	 *
	 * @param \WP_Post_Type $type Post type object.
	 * @return string[]
	 */
	private static function edit_notes( $type ) {
		$slug  = (string) $type->name;
		$notes = array();

		if ( ! post_type_supports( $slug, 'editor' ) ) {
			$notes[] = __( 'This type has no content editor, so the content field cannot be used. Its information lives in its custom fields.', 'mosmcp-abilities' );
		}
		if ( ! post_type_supports( $slug, 'excerpt' ) ) {
			$notes[] = __( 'This type has no excerpt field.', 'mosmcp-abilities' );
		}
		if ( ! post_type_supports( $slug, 'thumbnail' ) ) {
			$notes[] = __( 'This type has no featured image.', 'mosmcp-abilities' );
		}
		if ( ! post_type_supports( $slug, 'page-attributes' ) && $type->hierarchical ) {
			$notes[] = __( 'This type is hierarchical but does not expose page attributes, so ordering cannot be set.', 'mosmcp-abilities' );
		}
		if ( post_type_supports( $slug, 'elementor' ) ) {
			$notes[] = __( 'Elementor can build this type, so its layout can be read and edited with the Elementor abilities.', 'mosmcp-abilities' );
		} else {
			$notes[] = __( 'Elementor is not enabled for this type, so layout editing is unavailable until it is enabled in Elementor\'s settings.', 'mosmcp-abilities' );
		}
		if ( self::acf_fields_for( $slug ) ) {
			$notes[] = __( 'Some of this type\'s fields are managed by Advanced Custom Fields and must be changed with the ACF abilities rather than the generic field abilities.', 'mosmcp-abilities' );
		}
		if ( self::jetengine_fields_for( $slug ) ) {
			$notes[] = __( 'Some of this type\'s fields are defined in JetEngine. The generic field abilities read and write them, and a value is converted to the format JetEngine stores for that field — a date field set up to hold a Unix timestamp receives one.', 'mosmcp-abilities' );
		}
		if ( ! post_type_supports( $slug, 'custom-fields' ) ) {
			$notes[] = __( 'This type has no custom-fields editor of its own, which is normal for a type built by a field plugin. Its fields can still be read and written; a field nothing manages and nothing has ever stored is refused, because a value there could never be read back.', 'mosmcp-abilities' );
		}

		return $notes;
	}
}
