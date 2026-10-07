<?php
/**
 * Core Revisions ability pack: definitions for the mosmcp/revision-* abilities.
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
 * Class Revisions_Pack
 *
 * Declares the revision abilities and their governed contract. Execute logic
 * lives in Revisions_Provider and is preserved from the reviewed source.
 */
class Revisions_Pack extends Ability_Pack {

	/**
	 * Ability category slug.
	 */
	const CATEGORY = 'mosmcp-revisions';

	/**
	 * Ability category for revision abilities.
	 *
	 * @return array Category definition.
	 */
	public function category() {
		return array(
			'slug'        => self::CATEGORY,
			'label'       => __( 'Revisions', 'mosmcp-abilities' ),
			'description' => __( 'Inspect, compare, restore, and manage post and page revisions.', 'mosmcp-abilities' ),
		);
	}

	/**
	 * The revision abilities.
	 *
	 * @return Ability[]
	 */
	public function abilities() {
		return array(
			$this->count_for_post(),
			$this->get(),
			$this->get_latest(),
			$this->get_status(),
			$this->list_autosaves(),
			$this->list_by_author(),
			$this->list_for_post(),
			$this->restore(),
			$this->delete(),
		);
	}

	/**
	 * Defines the mosmcp/revision-count-for-post ability.
	 *
	 * @return Ability
	 */
	private function count_for_post() {
		return new Ability(
			'mosmcp/revision-count-for-post',
			array(
				'label'         => __( 'Count Revisions for Post', 'mosmcp-abilities' ),
				'description'   => __( 'Returns how many revisions a post or page has, and the ID of the latest one. Does not count autosaves.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'read_post',
				'cap_args'      => self::post_id_args(),
				'annotations'   => self::annotations( true, false, true, false ),
				'execute'       => array( Revisions_Provider::class, 'count_for_post' ),
				'input_schema'  => Schema::object(
					array(
						'post_id' => Schema::int( __( 'The ID of the post or page.', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
					),
					array( 'post_id' )
				),
				'output_schema' => Schema::object(
					array(
						'post_id'            => Schema::int(),
						'count'              => Schema::int(),
						'latest_revision_id' => Schema::int(),
					),
					array( 'post_id', 'count' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/revision-get ability.
	 *
	 * @return Ability
	 */
	private function get() {
		return new Ability(
			'mosmcp/revision-get',
			array(
				'label'         => __( 'Get Revision', 'mosmcp-abilities' ),
				'description'   => __( 'Gets the full content of a single revision by its ID: title, content, excerpt, author, and date.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'edit_posts',
				'annotations'   => self::annotations( true, false, true, false ),
				'execute'       => array( Revisions_Provider::class, 'get' ),
				'input_schema'  => Schema::object(
					array(
						'id' => Schema::int( __( 'The ID of the revision to retrieve.', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
					),
					array( 'id' )
				),
				'output_schema' => Schema::object(
					array(
						'id'          => Schema::int(),
						'post_id'     => Schema::int(),
						'title'       => Schema::str(),
						'content'     => Schema::str(),
						'excerpt'     => Schema::str(),
						'author_id'   => Schema::int(),
						'author_name' => Schema::str(),
						'date'        => Schema::str(),
						'is_autosave' => Schema::boolean(),
					),
					array( 'id', 'post_id', 'content', 'date' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/revision-get-latest ability.
	 *
	 * @return Ability
	 */
	private function get_latest() {
		return new Ability(
			'mosmcp/revision-get-latest',
			array(
				'label'         => __( 'Get Latest Revision', 'mosmcp-abilities' ),
				'description'   => __( 'Gets the full content of the most recent revision of a post or page.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'read_post',
				'cap_args'      => self::post_id_args(),
				'annotations'   => self::annotations( true, false, true, false ),
				'execute'       => array( Revisions_Provider::class, 'get_latest' ),
				'input_schema'  => Schema::object(
					array(
						'post_id' => Schema::int( __( 'The ID of the post or page.', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
					),
					array( 'post_id' )
				),
				'output_schema' => Schema::object(
					array(
						'id'          => Schema::int(),
						'post_id'     => Schema::int(),
						'title'       => Schema::str(),
						'content'     => Schema::str(),
						'excerpt'     => Schema::str(),
						'author_id'   => Schema::int(),
						'author_name' => Schema::str(),
						'date'        => Schema::str(),
					),
					array( 'id', 'post_id', 'content', 'date' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/revision-get-status ability.
	 *
	 * @return Ability
	 */
	private function get_status() {
		return new Ability(
			'mosmcp/revision-get-status',
			array(
				'label'         => __( 'Get Revision Status', 'mosmcp-abilities' ),
				'description'   => __( 'Checks whether revisions are enabled for a post (or in general), and the maximum number of revisions WordPress will keep (unlimited unless configured otherwise in wp-config.php). This is site configuration, read-only — it cannot be changed by this ability.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'edit_posts',
				'annotations'   => self::annotations( true, false, true, false ),
				'execute'       => array( Revisions_Provider::class, 'get_status' ),
				'input_schema'  => Schema::object(
					array(
						'post_id' => Schema::int( __( 'Optional post or page ID, to check the setting as it applies to that specific post. If omitted, checks the general "post" post type.', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
					)
				),
				'output_schema' => Schema::object(
					array(
						'enabled'   => Schema::boolean(),
						'unlimited' => Schema::boolean(),
						'max_kept'  => Schema::int( __( 'Maximum number of revisions kept. Meaningless when unlimited is true.', 'mosmcp-abilities' ) ),
					),
					array( 'enabled', 'unlimited' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/revision-list-autosaves ability.
	 *
	 * @return Ability
	 */
	private function list_autosaves() {
		return new Ability(
			'mosmcp/revision-list-autosaves',
			array(
				'label'         => __( 'List Autosaves for Post', 'mosmcp-abilities' ),
				'description'   => __( 'Lists the autosaves of a post or page. WordPress keeps only the single most recent autosave per user, so this typically returns at most one item per user currently editing the post — it is not a full history like mosmcp/revision-list-for-post.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'read_post',
				'cap_args'      => self::post_id_args(),
				'annotations'   => self::annotations( true, false, true, false ),
				'execute'       => array( Revisions_Provider::class, 'list_autosaves' ),
				'input_schema'  => Schema::object(
					array(
						'post_id' => Schema::int( __( 'The ID of the post or page.', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
					),
					array( 'post_id' )
				),
				'output_schema' => Schema::object(
					array(
						'count'     => Schema::int(),
						'autosaves' => Schema::arr( self::summary_item() ),
					),
					array( 'count', 'autosaves' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/revision-list-by-author ability.
	 *
	 * @return Ability
	 */
	private function list_by_author() {
		$properties = array_merge(
			array(
				'post_id'   => Schema::int( __( 'The ID of the post or page.', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
				'author_id' => Schema::int( __( 'The ID of the user whose revisions to list.', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
			),
			Schema::pagination_props( __( 'revisions', 'mosmcp-abilities' ) )
		);

		return new Ability(
			'mosmcp/revision-list-by-author',
			array(
				'label'         => __( 'List Revisions by Author', 'mosmcp-abilities' ),
				'description'   => __( 'Lists the revisions of a post or page that were made by a specific user, newest first. Does not include autosaves.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'read_post',
				'cap_args'      => self::post_id_args(),
				'annotations'   => self::annotations( true, false, true, false ),
				'execute'       => array( Revisions_Provider::class, 'list_by_author' ),
				'input_schema'  => Schema::object( $properties, array( 'post_id', 'author_id' ) ),
				'output_schema' => self::revisions_output(),
			)
		);
	}

	/**
	 * Defines the mosmcp/revision-list-for-post ability.
	 *
	 * @return Ability
	 */
	private function list_for_post() {
		$properties = array_merge(
			array(
				'post_id' => Schema::int( __( 'The ID of the post or page.', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
			),
			Schema::pagination_props( __( 'revisions', 'mosmcp-abilities' ) )
		);

		return new Ability(
			'mosmcp/revision-list-for-post',
			array(
				'label'         => __( 'List Revisions for Post', 'mosmcp-abilities' ),
				'description'   => __( 'Lists all revisions of a post or page, newest first, with author and date for each. Does not include the full content of each revision — use mosmcp/revision-get for that. Returns at most per_page revisions (default 20) plus the total.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'read_post',
				'cap_args'      => self::post_id_args(),
				'annotations'   => self::annotations( true, false, true, false ),
				'execute'       => array( Revisions_Provider::class, 'list_for_post' ),
				'input_schema'  => Schema::object( $properties, array( 'post_id' ) ),
				'output_schema' => self::revisions_output(),
			)
		);
	}

	/**
	 * Defines the mosmcp/revision-restore ability.
	 *
	 * @return Ability
	 */
	private function restore() {
		return new Ability(
			'mosmcp/revision-restore',
			array(
				'label'         => __( 'Restore Revision', 'mosmcp-abilities' ),
				'description'   => __( 'Restores a post or page to a chosen revision, replacing its current title, content, and excerpt with that revision\'s. This itself creates a new revision capturing what the post looked like just before the restore, so the restore can always be undone.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'edit_post',
				'cap_args'      => self::parent_args(),
				'annotations'   => self::annotations( false, false, false, true ),
				'execute'       => array( Revisions_Provider::class, 'restore' ),
				'input_schema'  => Schema::object(
					array(
						'id' => Schema::int( __( 'The ID of the revision to restore.', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
					),
					array( 'id' )
				),
				'output_schema' => Schema::object(
					array(
						'post_id'                   => Schema::int(),
						'title'                     => Schema::str(),
						'restored_from_revision_id' => Schema::int(),
						'edit_url'                  => Schema::str(),
					),
					array( 'post_id', 'title', 'restored_from_revision_id' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/revision-delete ability.
	 *
	 * @return Ability
	 */
	private function delete() {
		return new Ability(
			'mosmcp/revision-delete',
			array(
				'label'         => __( 'Delete Revision', 'mosmcp-abilities' ),
				'description'   => __( 'Permanently deletes a single revision by ID. This does not affect the current post — only that one historical snapshot is removed. There is no undo and no wp-admin screen for this action. Requires confirm=true.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'edit_post',
				'cap_args'      => self::parent_args(),
				'annotations'   => self::annotations( false, true, true, false ),
				'execute'       => array( Revisions_Provider::class, 'delete' ),
				'input_schema'  => Schema::object(
					array(
						'id'      => Schema::int( __( 'The ID of the revision to delete.', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
						'confirm' => Schema::boolean( __( 'Must be true to confirm permanent, irreversible deletion.', 'mosmcp-abilities' ) ),
					),
					array( 'id', 'confirm' )
				),
				'output_schema' => Schema::object(
					array(
						'id'      => Schema::int(),
						'post_id' => Schema::int(),
						'deleted' => Schema::boolean(),
					),
					array( 'id', 'post_id', 'deleted' )
				),
			)
		);
	}

	/**
	 * Standard revision summary output item.
	 *
	 * @return array<string, mixed>
	 */
	private static function summary_item() {
		return Schema::object(
			array(
				'id'          => Schema::int(),
				'post_id'     => Schema::int(),
				'title'       => Schema::str(),
				'author_id'   => Schema::int(),
				'author_name' => Schema::str(),
				'date'        => Schema::str(),
				'is_autosave' => Schema::boolean(),
			),
			array( 'id', 'post_id', 'date', 'is_autosave' )
		);
	}

	/**
	 * { showing, total, revisions } output schema.
	 *
	 * @return array<string, mixed>
	 */
	private static function revisions_output() {
		return Schema::object(
			array(
				'showing'   => Schema::int(),
				'total'     => Schema::int(),
				'revisions' => Schema::arr( self::summary_item() ),
			),
			array( 'showing', 'total', 'revisions' )
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

	/**
	 * Resolves the post_id input for read_post capability checks (cap_args).
	 *
	 * @return callable
	 */
	private static function post_id_args() {
		return static function ( $input ) {
			return array( isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0 );
		};
	}

	/**
	 * Resolves the parent post ID of the target revision (cap_args).
	 *
	 * @return callable
	 */
	private static function parent_args() {
		return static function ( $input ) {
			$id       = isset( $input['id'] ) ? absint( $input['id'] ) : 0;
			$revision = $id > 0 ? get_post( $id ) : null;
			$parent   = ( $revision && 'revision' === $revision->post_type ) ? (int) $revision->post_parent : 0;
			return array( $parent );
		};
	}
}
