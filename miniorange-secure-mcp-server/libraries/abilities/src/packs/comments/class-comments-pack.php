<?php
/**
 * Comments ability pack: definitions for the mosmcp comment abilities.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Comments;

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

/*
 * These abilities intentionally query by post/user/comment meta or taxonomy
 * (and exclude specific IDs) — that is the tool surface the library exposes.
 * The queries are bounded and parameterized, so this performance advisory is
 * accepted here (the sniff is not part of the library's own phpcs.xml.dist; this
 * directive covers Plugin Check, which enforces its own broader standard).
 */
// phpcs:disable WordPress.DB.SlowDBQuery

/**
 * Class Comments_Pack
 *
 * Declares comment read, moderation, and content abilities. Execute logic lives
 * in Comments_Provider and is preserved from the reviewed source. Every ability
 * gates on moderate_comments.
 */
class Comments_Pack extends Ability_Pack {

	/**
	 * Ability category slug.
	 */
	const CATEGORY = 'mosmcp-comments';

	/**
	 * Ability category for comment abilities.
	 *
	 * @return array Category definition.
	 */
	public function category() {
		return array(
			'slug'        => self::CATEGORY,
			'label'       => __( 'Comments', 'mosmcp-abilities' ),
			'description' => __( 'Read, moderate, reply to, and manage comments.', 'mosmcp-abilities' ),
		);
	}

	/**
	 * The comment abilities.
	 *
	 * @return Ability[]
	 */
	public function abilities() {
		return array_merge( $this->read_abilities(), $this->write_abilities() );
	}

	/**
	 * Comment read abilities.
	 *
	 * @return Ability[]
	 */
	private function read_abilities() {
		$read = self::annotations( true, false, true, false );

		return array(
			$this->status_list( 'list-pending-comments', __( 'List Pending Comments', 'mosmcp-abilities' ), __( 'Lists comments awaiting moderation. Read-only.', 'mosmcp-abilities' ), 'list_pending' ),
			$this->status_list( 'list-approved-comments', __( 'List Approved Comments', 'mosmcp-abilities' ), __( 'Lists approved/published comments. Read-only.', 'mosmcp-abilities' ), 'list_approved' ),
			$this->status_list( 'list-spam-comments', __( 'List Spam Comments', 'mosmcp-abilities' ), __( 'Lists comments marked as spam. Read-only.', 'mosmcp-abilities' ), 'list_spam' ),
			$this->status_list( 'list-trashed-comments', __( 'List Trashed Comments', 'mosmcp-abilities' ), __( 'Lists comments currently in the trash. Read-only.', 'mosmcp-abilities' ), 'list_trashed' ),
			$this->ability(
				'get-comment-by-id',
				__( 'Get Comment by ID', 'mosmcp-abilities' ),
				__( 'Retrieves a single comment by ID, in any status. Read-only.', 'mosmcp-abilities' ),
				$read,
				'get_comment_by_id',
				self::id_input( __( 'The comment ID to look up (required).', 'mosmcp-abilities' ) ),
				self::comment_schema()
			),
			$this->ability(
				'search-comments',
				__( 'Search Comments', 'mosmcp-abilities' ),
				__( 'Searches comments by a keyword matched against content and author, paginated. Read-only.', 'mosmcp-abilities' ),
				$read,
				'search_comments',
				Schema::object(
					array_merge(
						array( 'query' => Schema::str( __( 'The keyword to search for (required).', 'mosmcp-abilities' ) ) ),
						self::pagination()
					),
					array( 'query' )
				),
				self::paginated_output()
			),
			$this->ability(
				'get-comments-for-post',
				__( 'Get Comments for Post', 'mosmcp-abilities' ),
				__( 'Lists all comments left on a specific post, given the post ID, paginated. Read-only.', 'mosmcp-abilities' ),
				$read,
				'get_comments_for_post',
				Schema::object(
					array_merge(
						array( 'post_id' => Schema::int( __( 'The post ID whose comments to list (required).', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ) ),
						self::pagination()
					),
					array( 'post_id' )
				),
				self::paginated_output()
			),
			$this->ability(
				'count-comments',
				__( 'Count Comments', 'mosmcp-abilities' ),
				__( 'Returns comment counts broken down by status: total, approved, pending, spam, and trash. Read-only.', 'mosmcp-abilities' ),
				$read,
				'count_comments',
				Schema::object( array() ),
				self::counts_schema( array( 'total', 'approved', 'pending', 'spam', 'trash' ) )
			),
			$this->ability(
				'get-comment-meta',
				__( 'Get Comment Metadata', 'mosmcp-abilities' ),
				__( 'Returns custom metadata stored on a comment, given its comment ID. Pass meta_key for a single key, or omit it for all metadata. Read-only.', 'mosmcp-abilities' ),
				$read,
				'get_comment_meta',
				self::id_input(
					__( 'The comment ID (required).', 'mosmcp-abilities' ),
					array( 'meta_key' => Schema::str( __( 'Optional. A single meta key to fetch.', 'mosmcp-abilities' ) ) )
				),
				Schema::object(
					array(
						'id'   => Schema::int(),
						'meta' => array( 'type' => 'object' ),
					),
					array( 'id', 'meta' )
				)
			),
			$this->ability(
				'get-comment-count-by-post',
				__( 'Get Comment Count by Post', 'mosmcp-abilities' ),
				__( 'Returns the comment count for a specific post, broken down by status, given the post ID. Read-only.', 'mosmcp-abilities' ),
				$read,
				'get_comment_count_by_post',
				Schema::object(
					array( 'post_id' => Schema::int( __( 'The post ID (required).', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ) ),
					array( 'post_id' )
				),
				self::counts_schema( array( 'post_id', 'total', 'approved', 'pending', 'spam', 'trash' ) )
			),
			$this->ability(
				'get-comment-count-by-status',
				__( 'Get Comment Count by Status', 'mosmcp-abilities' ),
				__( 'Returns the number of comments in one specific status, given the status (hold, approve, spam, or trash). For all statuses at once use mosmcp/count-comments.', 'mosmcp-abilities' ),
				$read,
				'get_comment_count_by_status',
				Schema::object(
					array(
						'status' => Schema::str(
							__( 'The comment status to count (required).', 'mosmcp-abilities' ),
							array( 'enum' => array( 'hold', 'approve', 'spam', 'trash' ) )
						),
					),
					array( 'status' )
				),
				Schema::object(
					array(
						'status' => Schema::str(),
						'count'  => Schema::int(),
					),
					array( 'status', 'count' )
				)
			),
			$this->ability(
				'get-comment-count-by-user',
				__( 'Get Comment Count by User', 'mosmcp-abilities' ),
				__( 'Returns how many comments a specific user has authored, given their user ID. For the actual list, use mosmcp/list-user-authored-comments.', 'mosmcp-abilities' ),
				$read,
				'get_comment_count_by_user',
				self::id_input( __( 'The user ID (required).', 'mosmcp-abilities' ) ),
				Schema::object(
					array(
						'id'    => Schema::int(),
						'count' => Schema::int(),
					),
					array( 'id', 'count' )
				)
			),
			$this->ability(
				'list-user-authored-comments',
				__( 'List Comments Authored by User', 'mosmcp-abilities' ),
				__( 'Lists comments authored by a given user, given their user ID, paginated. Read-only. For just the count, use mosmcp/get-comment-count-by-user.', 'mosmcp-abilities' ),
				$read,
				'list_user_authored_comments',
				self::id_input( __( 'The author user ID (required).', 'mosmcp-abilities' ), self::pagination() ),
				self::paginated_output()
			),
		);
	}

	/**
	 * Comment moderation and content abilities.
	 *
	 * @return Ability[]
	 */
	private function write_abilities() {
		$id_input = self::id_input( __( 'The comment ID (required).', 'mosmcp-abilities' ) );

		return array(
			$this->status_change( 'approve-comment', __( 'Approve Comment', 'mosmcp-abilities' ), __( 'Approves a pending comment, given its comment ID, making it publicly visible.', 'mosmcp-abilities' ), 'approve_comment', self::annotations( false, false, true, true ), $id_input ),
			$this->status_change( 'unapprove-comment', __( 'Unapprove Comment', 'mosmcp-abilities' ), __( 'Reverts an approved comment back to pending, given its comment ID.', 'mosmcp-abilities' ), 'unapprove_comment', self::annotations( false, false, true, true ), $id_input ),
			$this->status_change( 'mark-comment-spam', __( 'Mark Comment as Spam', 'mosmcp-abilities' ), __( 'Marks a comment as spam, given its comment ID.', 'mosmcp-abilities' ), 'mark_comment_spam', self::annotations( false, true, true, true ), $id_input ),
			$this->status_change( 'unmark-comment-spam', __( 'Unmark Comment as Spam', 'mosmcp-abilities' ), __( 'Restores a comment from spam back to pending, given its comment ID.', 'mosmcp-abilities' ), 'unmark_comment_spam', self::annotations( false, false, true, false ), $id_input ),
			$this->status_change( 'trash-comment', __( 'Trash Comment', 'mosmcp-abilities' ), __( 'Moves a comment to the trash, given its comment ID. Recoverable from the trash.', 'mosmcp-abilities' ), 'trash_comment', self::annotations( false, true, true, true ), $id_input ),
			$this->status_change( 'permanently-delete-comment', __( 'Permanently Delete Comment', 'mosmcp-abilities' ), __( 'Irreversibly deletes a comment, given its comment ID, bypassing the trash entirely.', 'mosmcp-abilities' ), 'permanently_delete_comment', self::annotations( false, true, false, true ), $id_input ),
			$this->ability(
				'reply-to-comment',
				__( 'Reply to Comment', 'mosmcp-abilities' ),
				__( 'Posts an admin reply to an existing comment, given the parent comment ID and the reply content. The reply is authored as the current user and auto-approved.', 'mosmcp-abilities' ),
				self::annotations( false, false, false, true ),
				'reply_to_comment',
				self::id_input(
					__( 'The parent comment ID to reply to (required).', 'mosmcp-abilities' ),
					array( 'content' => Schema::str( __( 'The reply text (required).', 'mosmcp-abilities' ) ) ),
					array( 'content' )
				),
				self::comment_schema()
			),
			$this->ability(
				'update-comment-meta',
				__( 'Update Comment Metadata', 'mosmcp-abilities' ),
				__( 'Sets a custom metadata key/value pair on a comment, given its comment ID, the meta_key, and the meta_value.', 'mosmcp-abilities' ),
				self::annotations( false, false, true, false ),
				'update_comment_meta',
				self::id_input(
					__( 'The comment ID (required).', 'mosmcp-abilities' ),
					array(
						'meta_key'   => Schema::str( __( 'The meta key to set (required).', 'mosmcp-abilities' ) ),
						'meta_value' => array( 'description' => __( 'The value to store (required).', 'mosmcp-abilities' ) ),
					),
					array( 'meta_key', 'meta_value' )
				),
				Schema::object(
					array(
						'id'       => Schema::int(),
						'meta_key' => Schema::str(),
						'updated'  => Schema::boolean(),
					),
					array( 'id', 'meta_key', 'updated' )
				)
			),
		);
	}

	/**
	 * Builds a paginated list-by-status read ability.
	 *
	 * @param string $name   Action slug.
	 * @param string $label  Ability label.
	 * @param string $desc   Ability description.
	 * @param string $method Comments_Provider method name.
	 * @return Ability
	 */
	private function status_list( $name, $label, $desc, $method ) {
		return $this->ability(
			$name,
			$label,
			$desc,
			self::annotations( true, false, true, false ),
			$method,
			Schema::object( self::pagination() ),
			self::paginated_output()
		);
	}

	/**
	 * Builds a comment status-change ability (id in, {id, success} out).
	 *
	 * @param string               $name        Action slug.
	 * @param string               $label       Ability label.
	 * @param string               $desc        Ability description.
	 * @param string               $method      Comments_Provider method name.
	 * @param array<string, bool>  $annotations Annotation hints.
	 * @param array<string, mixed> $id_input    Shared id-only input schema.
	 * @return Ability
	 */
	private function status_change( $name, $label, $desc, $method, array $annotations, array $id_input ) {
		return $this->ability(
			$name,
			$label,
			$desc,
			$annotations,
			$method,
			$id_input,
			Schema::object(
				array(
					'id'      => Schema::int(),
					'success' => Schema::boolean(),
				),
				array( 'id', 'success' )
			)
		);
	}

	/**
	 * Builds one comment ability with the standard wiring (moderate_comments gate).
	 *
	 * @param string               $name          Action slug (becomes mosmcp/<name>).
	 * @param string               $label         Ability label.
	 * @param string               $description   Ability description.
	 * @param array<string, bool>  $annotations   Annotation hints.
	 * @param string               $method        Comments_Provider method name.
	 * @param array<string, mixed> $input_schema  Input schema.
	 * @param array<string, mixed> $output_schema Output schema.
	 * @return Ability
	 */
	private function ability( $name, $label, $description, array $annotations, $method, array $input_schema, array $output_schema ) {
		return new Ability(
			'mosmcp/' . $name,
			array(
				'label'         => $label,
				'description'   => $description,
				'category'      => self::CATEGORY,
				'capability'    => 'moderate_comments',
				'annotations'   => $annotations,
				'execute'       => array( Comments_Provider::class, $method ),
				'input_schema'  => $input_schema,
				'output_schema' => $output_schema,
			)
		);
	}

	/**
	 * Shared pagination input properties.
	 *
	 * @return array<string, mixed>
	 */
	private static function pagination() {
		return array(
			'page'     => Schema::int(
				__( 'Page number, starting at 1.', 'mosmcp-abilities' ),
				array(
					'default' => 1,
					'minimum' => 1,
				)
			),
			'per_page' => Schema::int(
				__( 'Comments per page (max 100).', 'mosmcp-abilities' ),
				array(
					'default' => 20,
					'minimum' => 1,
					'maximum' => 100,
				)
			),
		);
	}

	/**
	 * Input schema with a required integer `id` plus optional extra properties.
	 *
	 * @param string               $id_desc        Description for the id property.
	 * @param array<string, mixed> $extra_props    Extra input properties.
	 * @param string[]             $extra_required Extra required property names.
	 * @return array<string, mixed>
	 */
	private static function id_input( $id_desc, array $extra_props = array(), array $extra_required = array() ) {
		$props = array_merge(
			array( 'id' => Schema::int( $id_desc, array( 'minimum' => 1 ) ) ),
			$extra_props
		);
		return Schema::object( $props, array_merge( array( 'id' ), $extra_required ) );
	}

	/**
	 * Comment output item schema.
	 *
	 * @return array<string, mixed>
	 */
	private static function comment_schema() {
		return Schema::object(
			array(
				'id'           => Schema::int(),
				'post_id'      => Schema::int(),
				'parent_id'    => Schema::int(),
				'author_name'  => Schema::str(),
				'author_email' => Schema::str(),
				'author_url'   => Schema::str(),
				'content'      => Schema::str(),
				'status'       => Schema::str(),
				'date'         => Schema::str(),
			),
			array( 'id', 'post_id', 'content', 'status' )
		);
	}

	/**
	 * Paginated { comments, page, per_page } output schema.
	 *
	 * @return array<string, mixed>
	 */
	private static function paginated_output() {
		return Schema::object(
			array(
				'comments' => Schema::arr( self::comment_schema() ),
				'page'     => Schema::int(),
				'per_page' => Schema::int(),
			),
			array( 'comments', 'page', 'per_page' )
		);
	}

	/**
	 * Builds a counts output schema from a list of integer fields.
	 *
	 * @param string[] $fields Field names.
	 * @return array<string, mixed>
	 */
	private static function counts_schema( array $fields ) {
		$props = array();
		foreach ( $fields as $field ) {
			$props[ $field ] = Schema::int();
		}
		return Schema::object( $props, $fields );
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
