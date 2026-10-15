<?php
/**
 * Core Tags ability pack: definitions for the mosmcp/tag-* abilities.
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
 * Class Tags_Pack
 *
 * Declares the tag abilities and their governed contract. Execute logic lives in
 * Tags_Provider and is preserved from the reviewed source.
 */
class Tags_Pack extends Ability_Pack {

	/**
	 * Ability category slug.
	 */
	const CATEGORY = 'mosmcp-tags';

	/**
	 * Ability category for tag abilities.
	 *
	 * @return array Category definition.
	 */
	public function category() {
		return array(
			'slug'        => self::CATEGORY,
			'label'       => __( 'Tags', 'mosmcp-abilities' ),
			'description' => __( 'Create, edit, and manage post tags.', 'mosmcp-abilities' ),
		);
	}

	/**
	 * The tag abilities.
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
			$this->list_posts(),
			$this->list_unused(),
			$this->update_description(),
			$this->update_name(),
			$this->update_slug(),
		);
	}

	/**
	 * Defines the mosmcp/tag-create ability.
	 *
	 * @return Ability
	 */
	private function create() {
		return new Ability(
			'mosmcp/tag-create',
			array(
				'label'         => __( 'Create Tag', 'mosmcp-abilities' ),
				'description'   => __( 'Creates a new post tag. Optionally set a custom slug and a description. Fails with a clear error if a tag with the same name already exists.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'manage_categories',
				'annotations'   => self::annotations( false, false, false, false ),
				'execute'       => array( Tags_Provider::class, 'create' ),
				'input_schema'  => Schema::object(
					array(
						'name'        => Schema::str( __( 'The name of the new tag.', 'mosmcp-abilities' ), array( 'minLength' => 1 ) ),
						'slug'        => Schema::str( __( 'Optional custom URL slug. If omitted, WordPress generates one from the name.', 'mosmcp-abilities' ), array( 'minLength' => 1 ) ),
						'description' => Schema::str( __( 'Optional description of the tag.', 'mosmcp-abilities' ) ),
					),
					array( 'name' )
				),
				'output_schema' => Schema::object(
					array(
						'id'          => Schema::int(),
						'name'        => Schema::str(),
						'slug'        => Schema::str(),
						'description' => Schema::str(),
						'edit_url'    => Schema::str(),
					),
					array( 'id', 'name' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/tag-delete ability.
	 *
	 * @return Ability
	 */
	private function delete() {
		return new Ability(
			'mosmcp/tag-delete',
			array(
				'label'         => __( 'Delete Tag', 'mosmcp-abilities' ),
				'description'   => __( 'Deletes a post tag. The posts using it are NOT deleted — they simply lose the tag. Requires confirm=true.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'manage_categories',
				'annotations'   => self::annotations( false, true, true, true ),
				'execute'       => array( Tags_Provider::class, 'delete' ),
				'input_schema'  => Schema::object(
					array(
						'id'      => Schema::int( __( 'The ID of the tag to delete.', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
						'confirm' => Schema::boolean( __( 'Must be true to confirm the deletion.', 'mosmcp-abilities' ) ),
					),
					array( 'id', 'confirm' )
				),
				'output_schema' => Schema::object(
					array(
						'id'      => Schema::int(),
						'name'    => Schema::str(),
						'deleted' => Schema::boolean(),
					),
					array( 'id', 'name', 'deleted' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/tag-find ability.
	 *
	 * @return Ability
	 */
	private function find() {
		return new Ability(
			'mosmcp/tag-find',
			array(
				'label'         => __( 'Find Tag (ID by Name / Name by ID)', 'mosmcp-abilities' ),
				'description'   => __( 'Looks up post tags to resolve a tag ID from a name, or a name from an ID. Use this FIRST whenever the user refers to a tag by name and another ability requires a tag ID. Provide "search" with the full or partial tag name, or provide "id".', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'edit_posts',
				'annotations'   => self::annotations( true, false, true, false ),
				'execute'       => array( Tags_Provider::class, 'find' ),
				'input_schema'  => Schema::object(
					array(
						'search' => Schema::str( __( 'Full or partial tag name to search for. Provide either this or "id".', 'mosmcp-abilities' ), array( 'minLength' => 1 ) ),
						'id'     => Schema::int( __( 'A tag ID to look up. Provide either this or "search".', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
					)
				),
				'output_schema' => self::matches_output(),
			)
		);
	}

	/**
	 * Defines the mosmcp/tag-get ability.
	 *
	 * @return Ability
	 */
	private function get() {
		return new Ability(
			'mosmcp/tag-get',
			array(
				'label'         => __( 'Get Tag', 'mosmcp-abilities' ),
				'description'   => __( 'Gets the details of a single post tag by its ID: name, slug, description, and how many posts use it.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'edit_posts',
				'annotations'   => self::annotations( true, false, true, false ),
				'execute'       => array( Tags_Provider::class, 'get' ),
				'input_schema'  => Schema::object(
					array(
						'id' => Schema::int( __( 'The ID of the tag to retrieve.', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
					),
					array( 'id' )
				),
				'output_schema' => self::tag_item(),
			)
		);
	}

	/**
	 * Defines the mosmcp/tag-list-all ability.
	 *
	 * @return Ability
	 */
	private function list_all() {
		return new Ability(
			'mosmcp/tag-list-all',
			array(
				'label'         => __( 'List All Tags', 'mosmcp-abilities' ),
				'description'   => __( 'Lists all post tags, including unused ones, with their post counts. Returns at most per_page tags (default 20) plus the total.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'edit_posts',
				'annotations'   => self::annotations( true, false, true, false ),
				'execute'       => array( Tags_Provider::class, 'list_all' ),
				'input_schema'  => Schema::object( Schema::pagination_props( __( 'tags', 'mosmcp-abilities' ) ) ),
				'output_schema' => self::tags_output(),
			)
		);
	}

	/**
	 * Defines the mosmcp/tag-list-posts ability.
	 *
	 * @return Ability
	 */
	private function list_posts() {
		$properties = array_merge(
			array(
				'id' => Schema::int( __( 'The ID of the tag.', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
			),
			Schema::pagination_props( __( 'posts', 'mosmcp-abilities' ) )
		);

		return new Ability(
			'mosmcp/tag-list-posts',
			array(
				'label'         => __( 'List Posts with Tag', 'mosmcp-abilities' ),
				'description'   => __( 'Lists the posts that carry a given tag, any status except trash, newest first. Returns at most per_page posts (default 20) plus the total.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'edit_posts',
				'annotations'   => self::annotations( true, false, true, false ),
				'execute'       => array( Tags_Provider::class, 'list_posts' ),
				'input_schema'  => Schema::object( $properties, array( 'id' ) ),
				'output_schema' => Schema::object(
					array(
						'showing'  => Schema::int(),
						'total'    => Schema::int(),
						'tag_name' => Schema::str(),
						'posts'    => Schema::arr( self::term_post_item() ),
					),
					array( 'showing', 'total', 'posts' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/tag-list-unused ability.
	 *
	 * @return Ability
	 */
	private function list_unused() {
		return new Ability(
			'mosmcp/tag-list-unused',
			array(
				'label'         => __( 'List Unused Tags', 'mosmcp-abilities' ),
				'description'   => __( 'Lists post tags that are not used by any post — useful for finding tags that could be cleaned up or deleted.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'edit_posts',
				'annotations'   => self::annotations( true, false, true, false ),
				'execute'       => array( Tags_Provider::class, 'list_unused' ),
				'input_schema'  => Schema::object( Schema::pagination_props( __( 'tags', 'mosmcp-abilities' ) ) ),
				'output_schema' => self::tags_output(),
			)
		);
	}

	/**
	 * Defines the mosmcp/tag-update-description ability.
	 *
	 * @return Ability
	 */
	private function update_description() {
		return new Ability(
			'mosmcp/tag-update-description',
			array(
				'label'         => __( 'Update Tag Description', 'mosmcp-abilities' ),
				'description'   => __( 'Changes the description of an existing post tag. The name and slug are not affected. Pass an empty string to clear the description.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'manage_categories',
				'annotations'   => self::annotations( false, false, true, true ),
				'execute'       => array( Tags_Provider::class, 'update_description' ),
				'input_schema'  => Schema::object(
					array(
						'id'          => Schema::int( __( 'The ID of the tag to update.', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
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
	 * Defines the mosmcp/tag-update-name ability.
	 *
	 * @return Ability
	 */
	private function update_name() {
		return new Ability(
			'mosmcp/tag-update-name',
			array(
				'label'         => __( 'Rename Tag', 'mosmcp-abilities' ),
				'description'   => __( 'Changes the name of an existing post tag. Posts using the tag are not affected.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'manage_categories',
				'annotations'   => self::annotations( false, false, true, true ),
				'execute'       => array( Tags_Provider::class, 'update_name' ),
				'input_schema'  => Schema::object(
					array(
						'id'   => Schema::int( __( 'The ID of the tag to rename.', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
						'name' => Schema::str( __( 'The new name for the tag.', 'mosmcp-abilities' ), array( 'minLength' => 1 ) ),
					),
					array( 'id', 'name' )
				),
				'output_schema' => self::term_output(),
			)
		);
	}

	/**
	 * Defines the mosmcp/tag-update-slug ability.
	 *
	 * @return Ability
	 */
	private function update_slug() {
		return new Ability(
			'mosmcp/tag-update-slug',
			array(
				'label'         => __( 'Update Tag Slug', 'mosmcp-abilities' ),
				'description'   => __( 'Changes the URL slug of an existing post tag. The name and description are not affected. Note: this changes the tag archive URL, so old links to it will stop working.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'manage_categories',
				'annotations'   => self::annotations( false, false, true, true ),
				'execute'       => array( Tags_Provider::class, 'update_slug' ),
				'input_schema'  => Schema::object(
					array(
						'id'   => Schema::int( __( 'The ID of the tag to update.', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
						'slug' => Schema::str( __( 'The new URL slug (lowercase letters, numbers, and dashes).', 'mosmcp-abilities' ), array( 'minLength' => 1 ) ),
					),
					array( 'id', 'slug' )
				),
				'output_schema' => self::term_slug_output(),
			)
		);
	}

	/**
	 * Standard tag output item schema.
	 *
	 * @return array<string, mixed>
	 */
	private static function tag_item() {
		return Schema::object(
			array(
				'id'          => Schema::int(),
				'name'        => Schema::str(),
				'slug'        => Schema::str(),
				'description' => Schema::str(),
				'post_count'  => Schema::int(),
				'edit_url'    => Schema::str(),
			),
			array( 'id', 'name', 'post_count' )
		);
	}

	/**
	 * Output item for posts listed under a tag.
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
				'matches' => Schema::arr( self::tag_item() ),
			),
			array( 'showing', 'total', 'matches' )
		);
	}

	/**
	 * { showing, total, tags } output schema.
	 *
	 * @return array<string, mixed>
	 */
	private static function tags_output() {
		return Schema::object(
			array(
				'showing' => Schema::int(),
				'total'   => Schema::int(),
				'tags'    => Schema::arr( self::tag_item() ),
			),
			array( 'showing', 'total', 'tags' )
		);
	}

	/**
	 * Output schema for rename (id, name, slug, edit_url).
	 *
	 * @return array<string, mixed>
	 */
	private static function term_output() {
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
