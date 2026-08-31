<?php
/**
 * Custom post type ability pack.
 *
 * Abilities are parameterised by post_type rather than generated per type. That
 * keeps their names identical on every site, so a role's granted abilities keep
 * working when a customer registers a new type — with per-type generation, every
 * grant would be site-specific and a newly registered type would silently fall
 * outside all of them. WordPress capabilities still enforce per-type access, so a
 * generic ability grants nothing the connected account could not already do.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Cpt;

use MoSMCP\Abilities\Ability;
use MoSMCP\Abilities\Ability_Pack;
use MoSMCP\Abilities\Packs\Core\Support\Core_Schema as Schema;

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
 * Class Cpt_Pack
 */
class Cpt_Pack extends Ability_Pack {

	/**
	 * The ability category this pack registers.
	 *
	 * @return array Category definition with 'slug', 'label', and 'description' keys.
	 */
	public function category() {
		return array(
			'slug'        => 'mosmcp-cpt',
			'label'       => __( 'Custom Content', 'mosmcp-abilities' ),
			'description' => __( 'Read and manage custom content types a site has registered — services, team members, portfolios and the like — including their custom fields and taxonomies.', 'mosmcp-abilities' ),
		);
	}

	/**
	 * The abilities this pack provides.
	 *
	 * @return Ability[]
	 */
	public function abilities() {
		return array(
			$this->list_types(),
			$this->describe_type(),
			$this->list_items(),
			$this->get_item(),
			$this->create_item(),
			$this->update_item(),
			$this->duplicate_item(),
			$this->set_field(),
			$this->delete_field(),
			$this->assign_terms(),
			$this->remove_terms(),
			$this->set_featured_image(),
			$this->set_parent(),
			$this->set_status(),
			$this->trash_item(),
			$this->restore_item(),
			$this->delete_item(),
			$this->template_get(),
			$this->template_set(),
		);
	}

	/* ------------------------------------------------------------- discovery */

	/**
	 * Defines mosmcp/cpt-list-types.
	 *
	 * @return Ability
	 */
	private function list_types() {
		return new Ability(
			'mosmcp/cpt-list-types',
			array(
				'label'         => __( 'List Custom Content Types', 'mosmcp-abilities' ),
				'description'   => __( 'Lists the custom content types this site has registered — things like Services, Team Members or Portfolio items — with what each supports, its taxonomies, how many items it holds, and whether the current user may create or publish them. Start here before working with custom content. Posts, pages, WooCommerce products and form entries are handled by their own abilities and are not listed.', 'mosmcp-abilities' ),
				'category'      => 'mosmcp-cpt',
				'capability'    => 'edit_posts',
				'annotations'   => self::annotations( true, false, true, false ),
				'execute'       => array( Cpt_Provider::class, 'list_types' ),
				'input_schema'  => Schema::object( array() ),
				'output_schema' => Schema::object(
					array(
						'total' => Schema::int(),
						'types' => Schema::arr( self::type_summary_schema(), __( 'The custom content types available.', 'mosmcp-abilities' ) ),
						'note'  => Schema::str(),
					),
					array( 'total', 'types' )
				),
			)
		);
	}

	/**
	 * Defines mosmcp/cpt-describe-type.
	 *
	 * @return Ability
	 */
	private function describe_type() {
		return new Ability(
			'mosmcp/cpt-describe-type',
			array(
				'label'         => __( 'Describe Custom Content Type', 'mosmcp-abilities' ),
				'description'   => __( 'Describes one custom content type in full: which fields it has and their types, which taxonomies it uses and how many terms each holds, what it supports, and exactly what the current user is permitted to do with it. Read this before creating or editing items of a type you have not worked with, since a custom type\'s real content usually lives in its fields rather than its body text.', 'mosmcp-abilities' ),
				'category'      => 'mosmcp-cpt',
				'capability'    => 'edit_posts',
				'annotations'   => self::annotations( true, false, true, false ),
				'execute'       => array( Cpt_Provider::class, 'describe_type' ),
				'input_schema'  => Schema::object(
					array( 'post_type' => self::post_type_property() ),
					array( 'post_type' )
				),
				'output_schema' => self::type_detail_schema(),
			)
		);
	}

	/* ------------------------------------------------------------------ read */

	/**
	 * Defines mosmcp/cpt-list.
	 *
	 * @return Ability
	 */
	private function list_items() {
		return new Ability(
			'mosmcp/cpt-list',
			array(
				'label'         => __( 'List Custom Content', 'mosmcp-abilities' ),
				'description'   => __( 'Lists items of a custom content type, optionally filtered by status, by a taxonomy term, by parent, or by a search of the title and body.', 'mosmcp-abilities' ),
				'category'      => 'mosmcp-cpt',
				'capability'    => 'edit_posts',
				'annotations'   => self::annotations( true, false, true, false ),
				'execute'       => array( Cpt_Provider::class, 'list_items' ),
				'input_schema'  => Schema::object(
					array(
						'post_type' => self::post_type_property(),
						'status'    => Schema::str( __( 'Only items with this status. Omit for everything except trashed items.', 'mosmcp-abilities' ) ),
						'search'    => Schema::str( __( 'Only items whose title or body contains this text.', 'mosmcp-abilities' ) ),
						'taxonomy'  => Schema::str( __( 'Filter by a taxonomy attached to this type. Requires term.', 'mosmcp-abilities' ) ),
						'term'      => Schema::str( __( 'The term to filter by, as a slug, name or ID. Requires taxonomy.', 'mosmcp-abilities' ) ),
						'parent_id' => Schema::int( __( 'Only direct children of this item, for hierarchical types.', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
						'order_by'  => Schema::str( __( 'Sort by "modified" (default) or "title".', 'mosmcp-abilities' ), array( 'enum' => array( 'modified', 'title' ), 'default' => 'modified' ) ),
						'order'     => Schema::str( __( 'Sort direction.', 'mosmcp-abilities' ), array( 'enum' => array( 'ASC', 'DESC' ), 'default' => 'DESC' ) ),
						'per_page'  => Schema::int( __( 'How many items to return.', 'mosmcp-abilities' ), array( 'default' => 20, 'minimum' => 1, 'maximum' => 100 ) ),
						'offset'    => Schema::int( __( 'How many items to skip.', 'mosmcp-abilities' ), array( 'default' => 0, 'minimum' => 0 ) ),
					),
					array( 'post_type' )
				),
				'output_schema' => Schema::object(
					array(
						'post_type' => Schema::str(),
						'showing'   => Schema::int(),
						'total'     => Schema::int(),
						'has_more'  => Schema::boolean(),
						'items'     => Schema::arr(
							Schema::object(
								array(
									'id'         => Schema::int(),
									'title'      => Schema::str(),
									'status'     => Schema::str(),
									'slug'       => Schema::str(),
									'parent_id'  => Schema::int(),
									'menu_order' => Schema::int(),
									'modified'   => Schema::str(),
									'edit_url'   => Schema::str(),
								)
							)
						),
					),
					array( 'post_type', 'showing', 'total', 'items' )
				),
			)
		);
	}

	/**
	 * Defines mosmcp/cpt-get.
	 *
	 * @return Ability
	 */
	private function get_item() {
		return new Ability(
			'mosmcp/cpt-get',
			array(
				'label'         => __( 'Get Custom Content Item', 'mosmcp-abilities' ),
				'description'   => __( 'Reads one item of a custom content type in full: its text, every custom field with who manages it, its taxonomy terms, its place in the hierarchy, its featured image and its Elementor status. Fields managed by Advanced Custom Fields are labelled as such, because those must be changed with the ACF abilities.', 'mosmcp-abilities' ),
				'category'      => 'mosmcp-cpt',
				'capability'    => 'edit_post',
				'cap_args'      => self::id_args(),
				'annotations'   => self::annotations( true, false, true, false ),
				'execute'       => array( Cpt_Provider::class, 'get_item' ),
				'input_schema'  => Schema::object(
					array(
						'post_type' => self::post_type_property(),
						'id'        => self::id_property(),
					),
					array( 'post_type', 'id' )
				),
				'output_schema' => self::item_detail_schema(),
			)
		);
	}

	/* ----------------------------------------------------------------- write */

	/**
	 * Defines mosmcp/cpt-create.
	 *
	 * @return Ability
	 */
	private function create_item() {
		return new Ability(
			'mosmcp/cpt-create',
			array(
				'label'            => __( 'Create Custom Content Item', 'mosmcp-abilities' ),
				'description'      => __( 'Creates a new item of a custom content type, as a draft unless another status is given. Fields the type does not support are refused rather than stored where nothing would ever show them. Set the item\'s custom fields and taxonomy terms afterwards, or duplicate an existing item to inherit its design.', 'mosmcp-abilities' ),
				'category'         => 'mosmcp-cpt',
				'capability'       => 'edit_posts',
				'permission_extra' => self::can_create_any(),
				'annotations'      => self::annotations( false, false, false, false ),
				'execute'          => array( Cpt_Provider::class, 'create_item' ),
				'input_schema'     => Schema::object(
					array(
						'post_type' => self::post_type_property(),
						'title'     => Schema::str( __( 'Title for the new item.', 'mosmcp-abilities' ), array( 'minLength' => 1 ) ),
						'content'   => Schema::str( __( 'Body text. Only for types that support an editor.', 'mosmcp-abilities' ) ),
						'excerpt'   => Schema::str( __( 'Excerpt. Only for types that support one.', 'mosmcp-abilities' ) ),
						'status'    => Schema::str( __( 'Status for the new item. Publishing requires the type\'s publish capability.', 'mosmcp-abilities' ), array( 'enum' => array( 'draft', 'pending', 'private', 'publish' ), 'default' => 'draft' ) ),
						'parent_id' => Schema::int( __( 'Parent item, for hierarchical types.', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
						'slug'      => Schema::str( __( 'New URL slug. Omit to leave the address unchanged. Reduced to a URL-safe form, and a suffix is added if it is already taken.', 'mosmcp-abilities' ) ),
					),
					array( 'post_type', 'title' )
				),
				'output_schema'    => Schema::object(
					array(
						'id'        => Schema::int(),
						'post_type' => Schema::str(),
						'title'     => Schema::str(),
						'slug'      => Schema::str( __( 'The URL slug as stored.', 'mosmcp-abilities' ) ),
						'status'    => Schema::str(),
						'edit_url'  => Schema::str(),
						'view_url'  => Schema::str(),
						'warnings'  => Schema::warnings(),
					),
					array( 'id', 'post_type', 'title', 'status' )
				),
			)
		);
	}

	/**
	 * Defines mosmcp/cpt-update.
	 *
	 * @return Ability
	 */
	private function update_item() {
		return new Ability(
			'mosmcp/cpt-update',
			array(
				'label'         => __( 'Update Custom Content Item', 'mosmcp-abilities' ),
				'description'   => __( 'Edits the title, body and/or excerpt of a custom content item. Fields the type does not support are refused rather than written where nothing would display them. A custom type\'s real information usually lives in its custom fields, so use the field abilities for those.', 'mosmcp-abilities' ),
				'category'      => 'mosmcp-cpt',
				'capability'    => 'edit_post',
				'cap_args'      => self::id_args(),
				'annotations'   => self::annotations( false, false, true, true ),
				'execute'       => array( Cpt_Provider::class, 'update_item' ),
				'input_schema'  => Schema::object(
					array(
						'post_type' => self::post_type_property(),
						'id'        => self::id_property(),
						'title'     => Schema::str( __( 'New title. Omit to keep the current one.', 'mosmcp-abilities' ) ),
						'content'   => Schema::str( __( 'New body text. Omit to keep the current text.', 'mosmcp-abilities' ) ),
						'excerpt'   => Schema::str( __( 'New excerpt. Pass an empty string to clear it.', 'mosmcp-abilities' ) ),
						'slug'      => Schema::str( __( 'New URL slug. Omit to leave the address unchanged. Reduced to a URL-safe form, and a suffix is added if it is already taken.', 'mosmcp-abilities' ) ),
					),
					array( 'post_type', 'id' )
				),
				'output_schema' => Schema::object(
					array(
						'id'        => Schema::int(),
						'post_type' => Schema::str(),
						'title'     => Schema::str(),
						'status'    => Schema::str(),
						'slug'      => Schema::str( __( 'The URL slug as stored, which may differ from a requested one.', 'mosmcp-abilities' ) ),
						'modified'  => Schema::str(),
						'view_url'  => Schema::str(),
						'updated'   => Schema::arr( array( 'type' => 'string' ), __( 'Fields that were changed.', 'mosmcp-abilities' ) ),
						'edit_url'  => Schema::str(),
						'warnings'  => Schema::warnings(),
					),
					array( 'id', 'title', 'status' )
				),
			)
		);
	}

	/**
	 * Defines mosmcp/cpt-duplicate.
	 *
	 * @return Ability
	 */
	private function duplicate_item() {
		return new Ability(
			'mosmcp/cpt-duplicate',
			array(
				'label'            => __( 'Duplicate Custom Content Item', 'mosmcp-abilities' ),
				'description'      => __( 'Copies a custom content item as a new draft, carrying its custom fields, taxonomy terms, featured image, template and Elementor layout across. This is the reliable way to create an item that matches an existing one, because a custom type\'s appearance and data live in its fields and settings rather than its body text.', 'mosmcp-abilities' ),
				'category'         => 'mosmcp-cpt',
				'capability'       => 'edit_post',
				'cap_args'         => self::source_id_args(),
				'permission_extra' => self::can_create_any(),
				'annotations'      => self::annotations( false, false, false, false ),
				'execute'          => array( Cpt_Provider::class, 'duplicate_item' ),
				'input_schema'     => Schema::object(
					array(
						'post_type'         => self::post_type_property(),
						'source_id'         => Schema::int( __( 'The item to copy. Its fields and settings become the new item\'s.', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
						'title'             => Schema::str( __( 'Title for the copy. Omit to use the original followed by "(copy)".', 'mosmcp-abilities' ) ),
						'status'            => Schema::str( __( 'Status for the copy. Defaults to draft so it can be reviewed first.', 'mosmcp-abilities' ), array( 'enum' => array( 'draft', 'pending', 'private', 'publish' ), 'default' => 'draft' ) ),
						'include_content'   => Schema::boolean( __( 'Whether to copy the body text. Set false to keep the fields and settings but start with empty text.', 'mosmcp-abilities' ), array( 'default' => true ) ),
						'expected_modified' => Schema::str( __( 'Optional. The "modified" value from a previous read; the copy is refused if the source changed since.', 'mosmcp-abilities' ) ),
					),
					array( 'post_type', 'source_id' )
				),
				'output_schema'    => Schema::object(
					array(
						'new_id'    => Schema::int(),
						'source_id' => Schema::int(),
						'title'     => Schema::str(),
						'status'    => Schema::str(),
						'edit_url'  => Schema::str(),
						'view_url'  => Schema::str(),
						'copied'    => Schema::object(
							array(
								'meta_rows'       => Schema::int(),
								'taxonomies'      => Schema::arr( array( 'type' => 'string' ) ),
								'thumbnail_id'    => Schema::int(),
								'has_elementor'   => Schema::boolean(),
								'elementor_bytes' => Schema::int(),
							)
						),
						'skipped'   => Schema::arr( self::key_reason_schema(), __( 'Data deliberately not copied.', 'mosmcp-abilities' ) ),
						'rewritten' => Schema::arr( self::key_reason_schema(), __( 'Data cleared because it is unique to the original.', 'mosmcp-abilities' ) ),
						'warnings'  => Schema::warnings(),
					),
					array( 'new_id', 'source_id', 'title', 'status' )
				),
			)
		);
	}

	/* --------------------------------------------------------- custom fields */

	/**
	 * Defines mosmcp/cpt-set-field.
	 *
	 * @return Ability
	 */
	private function set_field() {
		return new Ability(
			'mosmcp/cpt-set-field',
			array(
				'label'         => __( 'Set Custom Field', 'mosmcp-abilities' ),
				'description'   => __( 'Writes one custom field on a custom content item — a price, a duration, a list of features. Values are checked against the field\'s declared type. Fields managed by Advanced Custom Fields are refused, because ACF stores each field as a pair of rows and writing only one breaks it; use the ACF abilities for those. Private fields no plugin has declared are also refused. Read the item first to see which fields it has and which are writable.', 'mosmcp-abilities' ),
				'category'      => 'mosmcp-cpt',
				'capability'    => 'edit_post',
				'cap_args'      => self::id_args(),
				'annotations'   => self::annotations( false, false, true, false ),
				'execute'       => array( Cpt_Provider::class, 'set_field' ),
				'guard_meta_keys' => array( 'field' ),
				'input_schema'  => Schema::object(
					array(
						'post_type' => self::post_type_property(),
						'id'        => self::id_property(),
						'field'     => Schema::str( __( 'Name of the field to write, as reported when reading the item.', 'mosmcp-abilities' ), array( 'minLength' => 1 ) ),
						'value'     => Schema::any_value( __( 'The value to store, matching the field\'s declared type: text, a number, true/false, or a list for a repeatable field.', 'mosmcp-abilities' ) ),
					),
					array( 'post_type', 'id', 'field', 'value' )
				),
				'output_schema' => Schema::object(
					array(
						'id'         => Schema::int(),
						'post_type'  => Schema::str(),
						'field'      => Schema::str(),
						'registered' => Schema::boolean( __( 'Whether a plugin or theme declares this field.', 'mosmcp-abilities' ) ),
						'repeatable' => Schema::boolean(),
						'previous'   => Schema::str(),
						'current'    => Schema::str(),
						'changed'    => Schema::boolean(),
						'warnings'   => Schema::warnings(),
					),
					array( 'id', 'field', 'current' )
				),
			)
		);
	}

	/**
	 * Defines mosmcp/cpt-delete-field.
	 *
	 * @return Ability
	 */
	private function delete_field() {
		return new Ability(
			'mosmcp/cpt-delete-field',
			array(
				'label'         => __( 'Delete Custom Field', 'mosmcp-abilities' ),
				'description'   => __( 'Removes a custom field from a custom content item entirely, rather than setting it empty. The same protections apply as when writing one.', 'mosmcp-abilities' ),
				'category'      => 'mosmcp-cpt',
				'capability'    => 'edit_post',
				'cap_args'      => self::id_args(),
				'annotations'   => self::annotations( false, true, true, false ),
				'execute'       => array( Cpt_Provider::class, 'delete_field' ),
				'guard_meta_keys' => array( 'field' ),
				'input_schema'  => Schema::object(
					array(
						'post_type' => self::post_type_property(),
						'id'        => self::id_property(),
						'field'     => Schema::str( __( 'Name of the field to remove.', 'mosmcp-abilities' ), array( 'minLength' => 1 ) ),
					),
					array( 'post_type', 'id', 'field' )
				),
				'output_schema' => Schema::object(
					array(
						'id'        => Schema::int(),
						'post_type' => Schema::str(),
						'field'     => Schema::str(),
						'existed'   => Schema::boolean(),
						'previous'  => Schema::str(),
						'warnings'  => Schema::warnings(),
					),
					array( 'id', 'field', 'existed' )
				),
			)
		);
	}

	/* -------------------------------------------------------------- taxonomy */

	/**
	 * Defines mosmcp/cpt-assign-terms.
	 *
	 * @return Ability
	 */
	private function assign_terms() {
		return new Ability(
			'mosmcp/cpt-assign-terms',
			array(
				'label'         => __( 'Assign Terms To Custom Content', 'mosmcp-abilities' ),
				'description'   => __( 'Adds terms from one of a type\'s taxonomies to an item — a service category, a department. Terms must already exist unless create_missing is set. By default terms are added to whatever is already assigned; set replace to swap the whole set.', 'mosmcp-abilities' ),
				'category'      => 'mosmcp-cpt',
				'capability'    => 'edit_post',
				'cap_args'      => self::id_args(),
				'annotations'   => self::annotations( false, false, true, false ),
				'execute'       => array( Cpt_Provider::class, 'assign_terms' ),
				'input_schema'  => Schema::object(
					array(
						'post_type'      => self::post_type_property(),
						'id'             => self::id_property(),
						'taxonomy'       => Schema::str( __( 'Taxonomy to assign in, as reported when describing the type.', 'mosmcp-abilities' ), array( 'minLength' => 1 ) ),
						'terms'          => Schema::arr( array( 'type' => 'string' ), __( 'Terms to assign, by slug, name or ID.', 'mosmcp-abilities' ) ),
						'replace'        => Schema::boolean( __( 'Replace the item\'s existing terms in this taxonomy instead of adding to them.', 'mosmcp-abilities' ), array( 'default' => false ) ),
						'create_missing' => Schema::boolean( __( 'Create terms that do not exist yet. Requires permission to manage the taxonomy.', 'mosmcp-abilities' ), array( 'default' => false ) ),
					),
					array( 'post_type', 'id', 'taxonomy', 'terms' )
				),
				'output_schema' => self::terms_output_schema(),
			)
		);
	}

	/**
	 * Defines mosmcp/cpt-remove-terms.
	 *
	 * @return Ability
	 */
	private function remove_terms() {
		return new Ability(
			'mosmcp/cpt-remove-terms',
			array(
				'label'         => __( 'Remove Terms From Custom Content', 'mosmcp-abilities' ),
				'description'   => __( 'Removes specific terms from an item in one of its taxonomies, leaving the terms themselves intact for other items.', 'mosmcp-abilities' ),
				'category'      => 'mosmcp-cpt',
				'capability'    => 'edit_post',
				'cap_args'      => self::id_args(),
				'annotations'   => self::annotations( false, false, true, false ),
				'execute'       => array( Cpt_Provider::class, 'remove_terms' ),
				'input_schema'  => Schema::object(
					array(
						'post_type' => self::post_type_property(),
						'id'        => self::id_property(),
						'taxonomy'  => Schema::str( __( 'Taxonomy to remove from.', 'mosmcp-abilities' ), array( 'minLength' => 1 ) ),
						'terms'     => Schema::arr( array( 'type' => 'string' ), __( 'Terms to remove, by slug, name or ID.', 'mosmcp-abilities' ) ),
					),
					array( 'post_type', 'id', 'taxonomy', 'terms' )
				),
				'output_schema' => self::terms_output_schema(),
			)
		);
	}

	/* ------------------------------------------------- structure and status */

	/**
	 * Defines mosmcp/cpt-set-featured-image.
	 *
	 * @return Ability
	 */
	private function set_featured_image() {
		return new Ability(
			'mosmcp/cpt-set-featured-image',
			array(
				'label'         => __( 'Set Custom Content Featured Image', 'mosmcp-abilities' ),
				'description'   => __( 'Sets or clears the featured image of a custom content item. Refused for types that have no featured image, since one would never be displayed.', 'mosmcp-abilities' ),
				'category'      => 'mosmcp-cpt',
				'capability'    => 'edit_post',
				'cap_args'      => self::id_args(),
				'annotations'   => self::annotations( false, false, true, true ),
				'execute'       => array( Cpt_Provider::class, 'set_featured_image' ),
				'input_schema'  => Schema::object(
					array(
						'post_type'     => self::post_type_property(),
						'id'            => self::id_property(),
						'attachment_id' => Schema::int( __( 'Media library ID of the image, or 0 to remove the current one.', 'mosmcp-abilities' ), array( 'minimum' => 0 ) ),
					),
					array( 'post_type', 'id', 'attachment_id' )
				),
				'output_schema' => Schema::featured_image_output(),
			)
		);
	}

	/**
	 * Defines mosmcp/cpt-set-parent.
	 *
	 * @return Ability
	 */
	private function set_parent() {
		return new Ability(
			'mosmcp/cpt-set-parent',
			array(
				'label'         => __( 'Move Custom Content In Hierarchy', 'mosmcp-abilities' ),
				'description'   => __( 'Sets the parent of an item in a hierarchical custom type, and optionally its order among siblings. Pass 0 to move it to the top level. Moving an item beneath one of its own descendants is refused, since that would detach the branch.', 'mosmcp-abilities' ),
				'category'      => 'mosmcp-cpt',
				'capability'    => 'edit_post',
				'cap_args'      => self::id_args(),
				'annotations'   => self::annotations( false, false, true, false ),
				'execute'       => array( Cpt_Provider::class, 'set_parent' ),
				'input_schema'  => Schema::object(
					array(
						'post_type'  => self::post_type_property(),
						'id'         => self::id_property(),
						'parent_id'  => Schema::int( __( 'The new parent item, or 0 for the top level.', 'mosmcp-abilities' ), array( 'minimum' => 0 ) ),
						'menu_order' => Schema::int( __( 'Optional position among siblings; lower sorts first.', 'mosmcp-abilities' ) ),
					),
					array( 'post_type', 'id', 'parent_id' )
				),
				'output_schema' => Schema::object(
					array(
						'id'                 => Schema::int(),
						'post_type'          => Schema::str(),
						'previous_parent_id' => Schema::int(),
						'parent_id'          => Schema::int(),
						'menu_order'         => Schema::int(),
						'changed'            => Schema::boolean(),
						'warnings'           => Schema::warnings(),
					),
					array( 'id', 'parent_id', 'changed' )
				),
			)
		);
	}

	/**
	 * Defines mosmcp/cpt-set-status.
	 *
	 * @return Ability
	 */
	private function set_status() {
		return new Ability(
			'mosmcp/cpt-set-status',
			array(
				'label'         => __( 'Set Custom Content Status', 'mosmcp-abilities' ),
				'description'   => __( 'Publishes a custom content item, or returns it to draft, pending review or private. Publishing requires the type\'s own publish capability, which a custom type may define separately from posts.', 'mosmcp-abilities' ),
				'category'      => 'mosmcp-cpt',
				'capability'    => 'edit_post',
				'cap_args'      => self::id_args(),
				'annotations'   => self::annotations( false, false, true, true ),
				'execute'       => array( Cpt_Provider::class, 'set_status' ),
				'input_schema'  => Schema::object(
					array(
						'post_type' => self::post_type_property(),
						'id'        => self::id_property(),
						'status'    => Schema::str( __( 'The status to set.', 'mosmcp-abilities' ), array( 'enum' => array( 'draft', 'pending', 'private', 'publish' ) ) ),
					),
					array( 'post_type', 'id', 'status' )
				),
				'output_schema' => Schema::object(
					array(
						'id'              => Schema::int(),
						'post_type'       => Schema::str(),
						'previous_status' => Schema::str(),
						'status'          => Schema::str(),
						'view_url'        => Schema::str(),
						'warnings'        => Schema::warnings(),
					),
					array( 'id', 'status' )
				),
			)
		);
	}

	/**
	 * Defines mosmcp/cpt-trash.
	 *
	 * @return Ability
	 */
	private function trash_item() {
		return new Ability(
			'mosmcp/cpt-trash',
			array(
				'label'         => __( 'Trash Custom Content Item', 'mosmcp-abilities' ),
				'description'   => __( 'Moves a custom content item to the trash, where it can be restored.', 'mosmcp-abilities' ),
				'category'      => 'mosmcp-cpt',
				'capability'    => 'delete_post',
				'cap_args'      => self::id_args(),
				'annotations'   => self::annotations( false, true, true, false ),
				'execute'       => array( Cpt_Provider::class, 'trash_item' ),
				'input_schema'  => Schema::object(
					array(
						'post_type' => self::post_type_property(),
						'id'        => self::id_property(),
					),
					array( 'post_type', 'id' )
				),
				'output_schema' => self::status_only_schema(),
			)
		);
	}

	/**
	 * Defines mosmcp/cpt-restore.
	 *
	 * @return Ability
	 */
	private function restore_item() {
		return new Ability(
			'mosmcp/cpt-restore',
			array(
				'label'         => __( 'Restore Custom Content Item', 'mosmcp-abilities' ),
				'description'   => __( 'Restores a custom content item from the trash to its previous status.', 'mosmcp-abilities' ),
				'category'      => 'mosmcp-cpt',
				'capability'    => 'delete_post',
				'cap_args'      => self::id_args(),
				'annotations'   => self::annotations( false, false, true, false ),
				'execute'       => array( Cpt_Provider::class, 'restore_item' ),
				'input_schema'  => Schema::object(
					array(
						'post_type' => self::post_type_property(),
						'id'        => self::id_property(),
					),
					array( 'post_type', 'id' )
				),
				'output_schema' => self::status_only_schema(),
			)
		);
	}

	/**
	 * Defines mosmcp/cpt-delete-permanently.
	 *
	 * @return Ability
	 */
	private function delete_item() {
		return new Ability(
			'mosmcp/cpt-delete-permanently',
			array(
				'label'         => __( 'Permanently Delete Custom Content Item', 'mosmcp-abilities' ),
				'description'   => __( 'Permanently deletes a custom content item and its fields. This cannot be undone and requires explicit confirmation. Prefer moving it to the trash.', 'mosmcp-abilities' ),
				'category'      => 'mosmcp-cpt',
				'capability'    => 'delete_post',
				'cap_args'      => self::id_args(),
				'annotations'   => self::annotations( false, true, false, false ),
				'execute'       => array( Cpt_Provider::class, 'delete_item' ),
				'input_schema'  => Schema::object(
					array(
						'post_type' => self::post_type_property(),
						'id'        => self::id_property(),
						'confirm'   => Schema::boolean( __( 'Must be true. Deleting permanently cannot be undone.', 'mosmcp-abilities' ), array( 'default' => false ) ),
					),
					array( 'post_type', 'id', 'confirm' )
				),
				'output_schema' => Schema::object(
					array(
						'id'        => Schema::int(),
						'post_type' => Schema::str(),
						'title'     => Schema::str(),
						'deleted'   => Schema::boolean(),
						'warnings'  => Schema::warnings(),
					),
					array( 'id', 'deleted' )
				),
			)
		);
	}

	/* -------------------------------------------------------------- template */

	/**
	 * Defines mosmcp/cpt-template-get.
	 *
	 * @return Ability
	 */
	private function template_get() {
		return new Ability(
			'mosmcp/cpt-template-get',
			array(
				'label'         => __( 'Get Custom Content Template', 'mosmcp-abilities' ),
				'description'   => __( 'Reports which page template a custom content item uses, whether Elementor controls its layout, and the per-item theme display settings that decide how it renders.', 'mosmcp-abilities' ),
				'category'      => 'mosmcp-cpt',
				'capability'    => 'edit_post',
				'cap_args'      => self::id_args(),
				'annotations'   => self::annotations( true, false, true, false ),
				'execute'       => array( Cpt_Provider::class, 'template_get' ),
				'input_schema'  => Schema::object(
					array(
						'post_type' => self::post_type_property(),
						'id'        => self::id_property(),
					),
					array( 'post_type', 'id' )
				),
				'output_schema' => Schema::template_get_output(),
			)
		);
	}

	/**
	 * Defines mosmcp/cpt-template-set.
	 *
	 * @return Ability
	 */
	private function template_set() {
		return new Ability(
			'mosmcp/cpt-template-set',
			array(
				'label'         => __( 'Set Custom Content Template', 'mosmcp-abilities' ),
				'description'   => __( 'Assigns a page template to a custom content item. Only templates the active theme and its plugins register are accepted, so a mistyped name is refused rather than silently falling back to the default.', 'mosmcp-abilities' ),
				'category'      => 'mosmcp-cpt',
				'capability'    => 'edit_post',
				'cap_args'      => self::id_args(),
				'annotations'   => self::annotations( false, false, true, true ),
				'execute'       => array( Cpt_Provider::class, 'template_set' ),
				'input_schema'  => Schema::object(
					array(
						'post_type'     => self::post_type_property(),
						'id'            => self::id_property(),
						'page_template' => Schema::str( __( 'Template to assign, or "default" for the theme default.', 'mosmcp-abilities' ), array( 'minLength' => 1 ) ),
					),
					array( 'post_type', 'id', 'page_template' )
				),
				'output_schema' => Schema::template_set_output(),
			)
		);
	}

	/* -------------------------------------------------- shared schema pieces */

	/**
	 * The post_type input property, shared by every ability in the pack.
	 *
	 * @return array<string, mixed>
	 */
	private static function post_type_property() {
		return Schema::str(
			__( 'Which custom content type to work with, as reported by the list-types ability — for example "service" or "team_member".', 'mosmcp-abilities' ),
			array( 'minLength' => 1 )
		);
	}

	/**
	 * The item id input property.
	 *
	 * @return array<string, mixed>
	 */
	private static function id_property() {
		return Schema::int( __( 'The ID of the item.', 'mosmcp-abilities' ), array( 'minimum' => 1 ) );
	}

	/**
	 * Summary schema for one post type.
	 *
	 * @return array<string, mixed>
	 */
	private static function type_summary_schema() {
		return Schema::object(
			array(
				'post_type'         => Schema::str(),
				'label'             => Schema::str(),
				'singular_label'    => Schema::str(),
				'hierarchical'      => Schema::boolean( __( 'Whether items can have parents, like pages.', 'mosmcp-abilities' ) ),
				'public'            => Schema::boolean(),
				'supports'          => Schema::arr( array( 'type' => 'string' ), __( 'Features the type declares, which decides which fields mean anything.', 'mosmcp-abilities' ) ),
				'taxonomies'        => Schema::arr(
					Schema::object(
						array(
							'slug'         => Schema::str(),
							'label'        => Schema::str(),
							'hierarchical' => Schema::boolean(),
							'term_count'   => Schema::int(),
						)
					)
				),
				'counts'            => Schema::map( __( 'How many items exist, keyed by status.', 'mosmcp-abilities' ) ),
				'elementor_enabled' => Schema::boolean( __( 'Whether Elementor can build this type, and so whether the Elementor abilities work on it.', 'mosmcp-abilities' ) ),
				'access'            => self::access_schema(),
			)
		);
	}

	/**
	 * Full detail schema for one post type.
	 *
	 * @return array<string, mixed>
	 */
	private static function type_detail_schema() {
		$summary = self::type_summary_schema();

		$summary['properties']['fields'] = Schema::arr(
			Schema::object(
				array(
					'key'         => Schema::str(),
					'type'        => Schema::str( __( 'The value type the field expects.', 'mosmcp-abilities' ) ),
					'repeatable'  => Schema::boolean( __( 'Whether the field holds a list rather than one value.', 'mosmcp-abilities' ) ),
					'registered'  => Schema::boolean( __( 'Whether a plugin or theme declares the field, which is what allows it to be validated.', 'mosmcp-abilities' ) ),
					'protected'   => Schema::boolean(),
					'description' => Schema::str(),
					'managed_by'  => Schema::str( __( '"registered" for a declared field, or "acf" when Advanced Custom Fields owns it and the ACF abilities must be used.', 'mosmcp-abilities' ) ),
					'acf_key'     => Schema::str(),
				)
			),
			__( 'The custom fields this type has.', 'mosmcp-abilities' )
		);

		$summary['properties']['edit_notes'] = Schema::arr(
			array( 'type' => 'string' ),
			__( 'Things worth knowing before editing this type, such as fields it does not support.', 'mosmcp-abilities' )
		);

		$summary['required'] = array( 'post_type', 'supports', 'access' );

		return $summary;
	}

	/**
	 * Access reporting schema.
	 *
	 * @return array<string, mixed>
	 */
	private static function access_schema() {
		return Schema::object(
			array(
				'can_create'            => Schema::boolean(),
				'can_edit'              => Schema::boolean(),
				'can_publish'           => Schema::boolean(),
				'can_delete_others'     => Schema::boolean(),
				'create_capability'     => Schema::str(),
				'publish_capability'    => Schema::str(),
				'uses_own_capabilities' => Schema::boolean( __( 'True when the type defines its own capabilities rather than reusing the post ones.', 'mosmcp-abilities' ) ),
				'diagnosis'             => Schema::str( __( 'Present when the type is unreachable because its capabilities were never granted to any role.', 'mosmcp-abilities' ) ),
			)
		);
	}

	/**
	 * Item detail schema.
	 *
	 * @return array<string, mixed>
	 */
	private static function item_detail_schema() {
		return Schema::object(
			array(
				'id'             => Schema::int(),
				'post_type'      => Schema::str(),
				'title'          => Schema::str(),
				'status'         => Schema::str(),
				'slug'           => Schema::str(),
				'content'        => Schema::str( __( 'Body text, empty for types with no editor.', 'mosmcp-abilities' ) ),
				'excerpt'        => Schema::str(),
				'modified'       => Schema::str(),
				'hierarchy'      => Schema::object(
					array(
						'parent_id'    => Schema::int(),
						'menu_order'   => Schema::int(),
						'child_count'  => Schema::int(),
						'hierarchical' => Schema::boolean(),
					)
				),
				'featured_image' => Schema::object(
					array(
						'supported'     => Schema::boolean(),
						'attachment_id' => Schema::int(),
						'url'           => Schema::str(),
					)
				),
				'terms'          => Schema::arr(
					Schema::object(
						array(
							'taxonomy' => Schema::str(),
							'terms'    => Schema::arr(
								Schema::object(
									array(
										'term_id' => Schema::int(),
										'name'    => Schema::str(),
										'slug'    => Schema::str(),
									)
								)
							),
						)
					)
				),
				'fields'         => Schema::arr(
					Schema::object(
						array(
							'key'                 => Schema::str(),
							'value'               => Schema::str(),
							'repeatable'          => Schema::boolean(),
							'registered'          => Schema::boolean(),
							'managed_by'          => Schema::str( __( '"registered", "unregistered", or "acf" when Advanced Custom Fields owns the field.', 'mosmcp-abilities' ) ),
							'writable'            => Schema::boolean( __( 'Whether the generic field abilities may change this field. Answers the same check the write ability performs, so a field reported writable here will not be refused there.', 'mosmcp-abilities' ) ),
							'not_writable_reason' => Schema::str( __( 'Why the field cannot be written, when it cannot: for example field_managed_by_acf, field_not_writable or field_protected_and_unregistered. Empty when it is writable.', 'mosmcp-abilities' ) ),
						)
					),
					__( 'The item\'s custom fields, which is where a custom type\'s real information lives.', 'mosmcp-abilities' )
				),
				'elementor'      => Schema::object(
					array(
						'enabled'  => Schema::boolean(),
						'has_data' => Schema::boolean(),
					)
				),
				'page_template'  => Schema::str(),
				'edit_url'       => Schema::str(),
				'view_url'       => Schema::str(),
			),
			array( 'id', 'post_type', 'title', 'status', 'fields' )
		);
	}

	/**
	 * Shared term-change output schema.
	 *
	 * @return array<string, mixed>
	 */
	private static function terms_output_schema() {
		return Schema::object(
			array(
				'id'            => Schema::int(),
				'post_type'     => Schema::str(),
				'taxonomy'      => Schema::str(),
				'action'        => Schema::str(),
				'created_terms' => Schema::arr( array( 'type' => 'string' ), __( 'Terms created because they did not exist.', 'mosmcp-abilities' ) ),
				'terms'         => Schema::arr(
					Schema::object(
						array(
							'term_id' => Schema::int(),
							'name'    => Schema::str(),
							'slug'    => Schema::str(),
						)
					),
					__( 'The item\'s terms in this taxonomy after the change.', 'mosmcp-abilities' )
				),
				'changed'       => Schema::boolean(),
				'warnings'      => Schema::warnings(),
			),
			array( 'id', 'taxonomy', 'terms' )
		);
	}

	/**
	 * Shared key/reason list schema.
	 *
	 * @return array<string, mixed>
	 */
	private static function key_reason_schema() {
		return Schema::object(
			array(
				'key'    => Schema::str(),
				'reason' => Schema::str(),
			)
		);
	}

	/**
	 * Shared status-only output schema.
	 *
	 * @return array<string, mixed>
	 */
	private static function status_only_schema() {
		return Schema::object(
			array(
				'id'        => Schema::int(),
				'post_type' => Schema::str(),
				'status'    => Schema::str(),
				'warnings'  => Schema::warnings(),
			),
			array( 'id', 'status' )
		);
	}

	/* ------------------------------------------------------------- callbacks */

	/**
	 * Resolves the item ID for object-level capability checks.
	 *
	 * @return callable
	 */
	private static function id_args() {
		return static function ( $input ) {
			return array( isset( $input['id'] ) ? absint( $input['id'] ) : 0 );
		};
	}

	/**
	 * Resolves the source item ID for object-level capability checks.
	 *
	 * @return callable
	 */
	private static function source_id_args() {
		return static function ( $input ) {
			return array( isset( $input['source_id'] ) ? absint( $input['source_id'] ) : 0 );
		};
	}

	/**
	 * Requires create rights on the requested type.
	 *
	 * The ability's own gate covers the source item; creating a new one is a
	 * separate permission that neither wp_insert_post() nor the gate enforces.
	 *
	 * @return callable
	 */
	private static function can_create_any() {
		return static function ( $input ) {
			$slug = isset( $input['post_type'] ) ? sanitize_key( (string) $input['post_type'] ) : '';
			if ( '' === $slug ) {
				return false;
			}
			$type = get_post_type_object( $slug );
			return $type ? current_user_can( $type->cap->create_posts ) : false;
		};
	}

	/**
	 * Builds the four annotation hints.
	 *
	 * @param bool $read_only   Read-only hint.
	 * @param bool $destructive Destructive hint.
	 * @param bool $idempotent  Idempotent hint.
	 * @param bool $open_world  Open-world hint.
	 * @return array<string, bool>
	 */
	private static function annotations( $read_only, $destructive, $idempotent, $open_world ) {
		return array(
			'readonly'    => $read_only,
			'destructive' => $destructive,
			'idempotent'  => $idempotent,
			'open_world'  => $open_world,
		);
	}
}
