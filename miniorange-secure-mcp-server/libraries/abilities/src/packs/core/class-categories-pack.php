<?php
/**
 * Core Categories ability pack: definitions for the mosmcp/category-* abilities.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Core;

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
 * Class Categories_Pack
 *
 * Declares the category abilities and their governed contract. Execute logic
 * lives in Categories_Provider and is preserved from the reviewed source.
 */
class Categories_Pack extends Ability_Pack {

	/**
	 * Ability category slug.
	 */
	const CATEGORY = 'mosmcp-categories';

	/**
	 * Ability category for category abilities.
	 *
	 * @return array Category definition.
	 */
	public function category() {
		return array(
			'slug'        => self::CATEGORY,
			'label'       => __( 'Categories', 'mosmcp-abilities' ),
			'description' => __( 'Create, edit, organize, and manage post categories.', 'mosmcp-abilities' ),
		);
	}

	/**
	 * The category abilities.
	 *
	 * @return Ability[]
	 */
	public function abilities() {
		return array(
			$this->create(),
			$this->delete(),
			$this->find(),
			$this->get(),
			$this->list_all(),
			$this->list_children(),
			$this->list_empty(),
			$this->list_posts(),
			$this->update_description(),
			$this->update_name(),
			$this->update_slug(),
		);
	}

	/**
	 * Defines the mosmcp/category-create ability.
	 *
	 * @return Ability
	 */
	private function create() {
		return new Ability(
			'mosmcp/category-create',
			array(
				'label'         => __( 'Create Category', 'mosmcp-abilities' ),
				'description'   => __( 'Creates a new post category. Optionally set a parent category (to create a sub-category), a custom slug, and a description. Fails with a clear error if a category with the same name already exists.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'manage_categories',
				'annotations'   => self::annotations( false, false, false, false ),
				'execute'       => array( Categories_Provider::class, 'create' ),
				'input_schema'  => Schema::object(
					array(
						'name'        => Schema::str( __( 'The name of the new category.', 'mosmcp-abilities' ), array( 'minLength' => 1 ) ),
						'parent_id'   => Schema::int( __( 'Optional ID of a parent category, to create this as a sub-category.', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
						'slug'        => Schema::str( __( 'Optional custom URL slug. If omitted, WordPress generates one from the name.', 'mosmcp-abilities' ), array( 'minLength' => 1 ) ),
						'description' => Schema::str( __( 'Optional description of the category.', 'mosmcp-abilities' ) ),
					),
					array( 'name' )
				),
				'output_schema' => Schema::object(
					array(
						'id'          => Schema::int(),
						'name'        => Schema::str(),
						'slug'        => Schema::str(),
						'description' => Schema::str(),
						'parent_id'   => Schema::int(),
						'edit_url'    => Schema::str(),
					),
					array( 'id', 'name' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/category-delete ability.
	 *
	 * @return Ability
	 */
	private function delete() {
		return new Ability(
			'mosmcp/category-delete',
			array(
				'label'         => __( 'Delete Category', 'mosmcp-abilities' ),
				'description'   => __( 'Deletes a post category. The posts in it are NOT deleted — they move to the default category. The default category itself cannot be deleted. Requires confirm=true.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'manage_categories',
				'annotations'   => self::annotations( false, true, true, true ),
				'execute'       => array( Categories_Provider::class, 'delete' ),
				'input_schema'  => Schema::object(
					array(
						'id'      => Schema::int( __( 'The ID of the category to delete.', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
						'confirm' => Schema::boolean( __( 'Must be true to confirm the deletion.', 'mosmcp-abilities' ) ),
					),
					array( 'id', 'confirm' )
				),
				'output_schema' => Schema::object(
					array(
						'id'             => Schema::int(),
						'name'           => Schema::str(),
						'deleted'        => Schema::boolean(),
						'posts_moved_to' => Schema::str(),
					),
					array( 'id', 'name', 'deleted' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/category-find ability.
	 *
	 * @return Ability
	 */
	private function find() {
		return new Ability(
			'mosmcp/category-find',
			array(
				'label'         => __( 'Find Category (ID by Name / Name by ID)', 'mosmcp-abilities' ),
				'description'   => __( 'Looks up post categories to resolve a category ID from a name, or a name from an ID. Use this FIRST whenever the user refers to a category by name and another ability requires a category ID. Provide "search" with the full or partial category name, or provide "id".', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'edit_posts',
				'annotations'   => self::annotations( true, false, true, false ),
				'execute'       => array( Categories_Provider::class, 'find' ),
				'input_schema'  => Schema::object(
					array(
						'search' => Schema::str( __( 'Full or partial category name to search for. Provide either this or "id".', 'mosmcp-abilities' ), array( 'minLength' => 1 ) ),
						'id'     => Schema::int( __( 'A category ID to look up. Provide either this or "search".', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
					)
				),
				'output_schema' => self::matches_output(),
			)
		);
	}

	/**
	 * Defines the mosmcp/category-get ability.
	 *
	 * @return Ability
	 */
	private function get() {
		return new Ability(
			'mosmcp/category-get',
			array(
				'label'         => __( 'Get Category', 'mosmcp-abilities' ),
				'description'   => __( 'Gets the details of a single post category by its ID: name, slug, description, parent category, and how many posts it contains.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'edit_posts',
				'annotations'   => self::annotations( true, false, true, false ),
				'execute'       => array( Categories_Provider::class, 'get' ),
				'input_schema'  => Schema::object(
					array(
						'id' => Schema::int( __( 'The ID of the category to retrieve.', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
					),
					array( 'id' )
				),
				'output_schema' => self::category_item(),
			)
		);
	}

	/**
	 * Defines the mosmcp/category-list-all ability.
	 *
	 * @return Ability
	 */
	private function list_all() {
		return new Ability(
			'mosmcp/category-list-all',
			array(
				'label'         => __( 'List All Categories', 'mosmcp-abilities' ),
				'description'   => __( 'Lists all post categories, including empty ones, with their post counts and hierarchy (each item includes its parent category, if any). Returns at most per_page categories (default 20) plus the total.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'edit_posts',
				'annotations'   => self::annotations( true, false, true, false ),
				'execute'       => array( Categories_Provider::class, 'list_all' ),
				'input_schema'  => Schema::object( Schema::pagination_props( __( 'categories', 'mosmcp-abilities' ) ) ),
				'output_schema' => self::categories_output(),
			)
		);
	}

	/**
	 * Defines the mosmcp/category-list-children ability.
	 *
	 * @return Ability
	 */
	private function list_children() {
		return new Ability(
			'mosmcp/category-list-children',
			array(
				'label'         => __( 'List Sub-Categories', 'mosmcp-abilities' ),
				'description'   => __( 'Lists the direct sub-categories (children) of a given post category, including empty ones.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'edit_posts',
				'annotations'   => self::annotations( true, false, true, false ),
				'execute'       => array( Categories_Provider::class, 'list_children' ),
				'input_schema'  => Schema::object(
					array(
						'id' => Schema::int( __( 'The ID of the parent category.', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
					),
					array( 'id' )
				),
				'output_schema' => Schema::object(
					array(
						'showing'     => Schema::int(),
						'total'       => Schema::int(),
						'parent_name' => Schema::str(),
						'categories'  => Schema::arr( self::category_item() ),
					),
					array( 'showing', 'total', 'categories' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/category-list-empty ability.
	 *
	 * @return Ability
	 */
	private function list_empty() {
		return new Ability(
			'mosmcp/category-list-empty',
			array(
				'label'         => __( 'List Empty Categories', 'mosmcp-abilities' ),
				'description'   => __( 'Lists post categories that contain no posts — useful for finding categories that could be cleaned up or deleted.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'edit_posts',
				'annotations'   => self::annotations( true, false, true, false ),
				'execute'       => array( Categories_Provider::class, 'list_empty' ),
				'input_schema'  => Schema::object( Schema::pagination_props( __( 'categories', 'mosmcp-abilities' ) ) ),
				'output_schema' => self::categories_output(),
			)
		);
	}

	/**
	 * Defines the mosmcp/category-list-posts ability.
	 *
	 * @return Ability
	 */
	private function list_posts() {
		$properties = array_merge(
			array(
				'id' => Schema::int( __( 'The ID of the category.', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
			),
			Schema::pagination_props( __( 'posts', 'mosmcp-abilities' ) )
		);

		return new Ability(
			'mosmcp/category-list-posts',
			array(
				'label'         => __( 'List Posts in Category', 'mosmcp-abilities' ),
				'description'   => __( 'Lists the posts in a given category (including posts in its sub-categories), any status except trash, newest first. Returns at most per_page posts (default 20) plus the total.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'edit_posts',
				'annotations'   => self::annotations( true, false, true, false ),
				'execute'       => array( Categories_Provider::class, 'list_posts' ),
				'input_schema'  => Schema::object( $properties, array( 'id' ) ),
				'output_schema' => Schema::object(
					array(
						'showing'       => Schema::int(),
						'total'         => Schema::int(),
						'category_name' => Schema::str(),
						'posts'         => Schema::arr( self::term_post_item() ),
					),
					array( 'showing', 'total', 'posts' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/category-update-description ability.
	 *
	 * @return Ability
	 */
	private function update_description() {
		return new Ability(
			'mosmcp/category-update-description',
			array(
				'label'         => __( 'Update Category Description', 'mosmcp-abilities' ),
				'description'   => __( 'Changes the description of an existing post category. The name and slug are not affected. Pass an empty string to clear the description.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'manage_categories',
				'annotations'   => self::annotations( false, false, true, true ),
				'execute'       => array( Categories_Provider::class, 'update_description' ),
				'input_schema'  => Schema::object(
					array(
						'id'          => Schema::int( __( 'The ID of the category to update.', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
						'description' => Schema::str( __( 'The new description. May be an empty string to clear it.', 'mosmcp-abilities' ) ),
					),
					array( 'id', 'description' )
				),
				'output_schema' => Schema::object(
					array(
						'id'          => Schema::int(),
						'name'        => Schema::str(),
						'description' => Schema::str(),
						'edit_url'    => Schema::str(),
					),
					array( 'id', 'name', 'description' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/category-update-name ability.
	 *
	 * @return Ability
	 */
	private function update_name() {
		return new Ability(
			'mosmcp/category-update-name',
			array(
				'label'         => __( 'Rename Category', 'mosmcp-abilities' ),
				'description'   => __( 'Changes the name of an existing post category. Posts in the category are not affected.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'manage_categories',
				'annotations'   => self::annotations( false, false, true, true ),
				'execute'       => array( Categories_Provider::class, 'update_name' ),
				'input_schema'  => Schema::object(
					array(
						'id'   => Schema::int( __( 'The ID of the category to rename.', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
						'name' => Schema::str( __( 'The new name for the category.', 'mosmcp-abilities' ), array( 'minLength' => 1 ) ),
					),
					array( 'id', 'name' )
				),
				'output_schema' => self::term_name_output(),
			)
		);
	}

	/**
	 * Defines the mosmcp/category-update-slug ability.
	 *
	 * @return Ability
	 */
	private function update_slug() {
		return new Ability(
			'mosmcp/category-update-slug',
			array(
				'label'         => __( 'Update Category Slug', 'mosmcp-abilities' ),
				'description'   => __( 'Changes the URL slug of an existing post category. The name and description are not affected. Note: this changes the category archive URL, so old links to it will stop working.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'manage_categories',
				'annotations'   => self::annotations( false, false, true, true ),
				'execute'       => array( Categories_Provider::class, 'update_slug' ),
				'input_schema'  => Schema::object(
					array(
						'id'   => Schema::int( __( 'The ID of the category to update.', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
						'slug' => Schema::str( __( 'The new URL slug (lowercase letters, numbers, and dashes).', 'mosmcp-abilities' ), array( 'minLength' => 1 ) ),
					),
					array( 'id', 'slug' )
				),
				'output_schema' => self::term_slug_output(),
			)
		);
	}

	/**
	 * Standard category output item schema.
	 *
	 * @return array<string, mixed>
	 */
	private static function category_item() {
		return Schema::object(
			array(
				'id'          => Schema::int(),
				'name'        => Schema::str(),
				'slug'        => Schema::str(),
				'description' => Schema::str(),
				'parent_id'   => Schema::int(),
				'parent_name' => Schema::str(),
				'post_count'  => Schema::int(),
				'edit_url'    => Schema::str(),
			),
			array( 'id', 'name', 'post_count' )
		);
	}

	/**
	 * Output item for posts listed under a category.
	 *
	 * @return array<string, mixed>
	 */
	private static function term_post_item() {
		return Schema::object(
			array(
				'id'       => Schema::int(),
				'title'    => Schema::str(),
				'status'   => Schema::str(),
				'date'     => Schema::str(),
				'edit_url' => Schema::str(),
			),
			array( 'id', 'title', 'status' )
		);
	}

	/**
	 * { showing, total, matches } output schema.
	 *
	 * @return array<string, mixed>
	 */
	private static function matches_output() {
		return Schema::object(
			array(
				'showing' => Schema::int(),
				'total'   => Schema::int(),
				'matches' => Schema::arr( self::category_item() ),
			),
			array( 'showing', 'total', 'matches' )
		);
	}

	/**
	 * { showing, total, categories } output schema.
	 *
	 * @return array<string, mixed>
	 */
	private static function categories_output() {
		return Schema::object(
			array(
				'showing'    => Schema::int(),
				'total'      => Schema::int(),
				'categories' => Schema::arr( self::category_item() ),
			),
			array( 'showing', 'total', 'categories' )
		);
	}

	/**
	 * Output schema for rename (id, name, slug, edit_url).
	 *
	 * @return array<string, mixed>
	 */
	private static function term_name_output() {
		return Schema::object(
			array(
				'id'       => Schema::int(),
				'name'     => Schema::str(),
				'slug'     => Schema::str(),
				'edit_url' => Schema::str(),
			),
			array( 'id', 'name' )
		);
	}

	/**
	 * Output schema for slug change (id, name, slug, edit_url).
	 *
	 * @return array<string, mixed>
	 */
	private static function term_slug_output() {
		return Schema::object(
			array(
				'id'       => Schema::int(),
				'name'     => Schema::str(),
				'slug'     => Schema::str(),
				'edit_url' => Schema::str(),
			),
			array( 'id', 'name', 'slug' )
		);
	}

	/**
	 * Builds the four MCP annotation hints.
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
