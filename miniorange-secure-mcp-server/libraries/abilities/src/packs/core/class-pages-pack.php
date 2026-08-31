<?php
/**
 * Core Pages ability pack: definitions for the mosmcp/page-* abilities.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Core;

use MoSMCP\Abilities\Ability;
use MoSMCP\Abilities\Ability_Pack;
use MoSMCP\Abilities\Packs\Core\Support\Core_Schema as Schema;
use MoSMCP\Abilities\Packs\Core\Support\Post_Duplicator;

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
 * Class Pages_Pack
 *
 * Declares the page abilities and their governed contract. Execute logic lives in
 * Pages_Provider and is preserved from the reviewed source.
 */
class Pages_Pack extends Ability_Pack {

	/**
	 * Ability category for page abilities.
	 *
	 * @return array Category definition.
	 */
	public function category() {
		return array(
			'slug'        => 'mosmcp-pages',
			'label'       => __( 'Pages', 'mosmcp-abilities' ),
			'description' => __( 'Create, edit, publish, and manage hierarchical pages.', 'mosmcp-abilities' ),
		);
	}

	/**
	 * The page abilities.
	 *
	 * @return Ability[]
	 */
	public function abilities() {
		return array(
			$this->create_draft(),
			$this->duplicate(),
			$this->set_featured_image(),
			$this->template_get(),
			$this->template_set(),
			$this->update(),
			$this->update_own(),
			$this->publish(),
			$this->unpublish(),
			$this->schedule(),
			$this->set_private(),
			$this->set_pending(),
			$this->trash(),
			$this->restore(),
			$this->delete_permanently(),
			$this->find(),
			$this->get(),
			$this->list_all(),
			$this->list_drafts(),
			$this->list_own_drafts(),
			$this->list_pending(),
			$this->list_private(),
			$this->list_published(),
			$this->list_scheduled(),
			$this->list_trash(),
		);
	}

	/**
	 * Defines the mosmcp/page-create-draft ability.
	 *
	 * @return Ability
	 */
	private function create_draft() {
		return new Ability(
			'mosmcp/page-create-draft',
			array(
				'label'         => __( 'Create Draft Page', 'mosmcp-abilities' ),
				'description'   => __( 'Creates a new page as a draft, authored by the current user. The page is not published. Optionally set a parent page to create it as a sub-page.', 'mosmcp-abilities' ),
				'category'      => 'mosmcp-pages',
				'capability'    => 'edit_pages',
				'annotations'   => self::annotations( false, false, false, false ),
				'execute'       => array( Pages_Provider::class, 'create_draft' ),
				'input_schema'  => Schema::object(
					array(
						'title'     => Schema::str( __( 'The title of the new draft page.', 'mosmcp-abilities' ), array( 'minLength' => 1 ) ),
						'content'   => Schema::str( __( 'Optional content/body of the new draft page.', 'mosmcp-abilities' ), array( 'default' => '' ) ),
						'parent_id' => Schema::int( __( 'Optional ID of a parent page, to create this as a sub-page.', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
						'slug'      => Schema::str( __( 'New URL slug. Omit to leave the address unchanged. Reduced to a URL-safe form, and a suffix is added if it is already taken.', 'mosmcp-abilities' ) ),
					),
					array( 'title' )
				),
				'output_schema' => Schema::object(
					array(
						'id'        => Schema::int(),
						'title'     => Schema::str(),
						'status'    => Schema::str(),
						'slug'      => Schema::str( __( 'The URL slug as stored.', 'mosmcp-abilities' ) ),
						'parent_id' => Schema::int(),
						'edit_url'  => Schema::str(),
					),
					array( 'id', 'title', 'status' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/page-update ability.
	 *
	 * @return Ability
	 */
	private function update() {
		return new Ability(
			'mosmcp/page-update',
			array(
				'label'         => __( 'Update Page', 'mosmcp-abilities' ),
				'description'   => __( 'Edits the title, content and/or excerpt of an existing page, including pages authored by other users. Requires edit rights on that specific page, so a contributor still cannot edit a published page. Replaces the fields you pass and leaves the rest untouched; the page keeps its current status. If the post is built with Elementor, what visitors read comes from the Elementor layout rather than from this content, so change the text on the Elementor elements instead.', 'mosmcp-abilities' ),
				'category'      => 'mosmcp-pages',
				'capability'    => 'edit_page',
				'cap_args'      => self::id_args(),
				'annotations'   => self::annotations( false, false, true, true ),
				'execute'       => array( Pages_Provider::class, 'update' ),
				'input_schema'  => Schema::object(
					array(
						'id'      => Schema::int( __( 'The ID of the page to update.', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
						'title'   => Schema::str( __( 'New title for the page. Omit to keep the current title.', 'mosmcp-abilities' ) ),
						'content' => Schema::str( __( 'New content for the page. Omit to keep the current content.', 'mosmcp-abilities' ) ),
						'excerpt' => Schema::str( __( 'New excerpt for the page. Omit to keep the current excerpt; pass an empty string to clear it.', 'mosmcp-abilities' ) ),
						'slug'    => Schema::str( __( 'New URL slug. Omit to leave the address unchanged. Reduced to a URL-safe form, and a suffix is added if it is already taken.', 'mosmcp-abilities' ) ),
					),
					array( 'id' )
				),
				'output_schema' => Schema::object(
					array(
						'id'       => Schema::int(),
						'title'    => Schema::str(),
						'status'   => Schema::str(),
						'slug'     => Schema::str( __( 'The URL slug as stored, which may differ from a requested one.', 'mosmcp-abilities' ) ),
						'updated'  => Schema::arr( array( 'type' => 'string' ), __( 'Fields that were changed.', 'mosmcp-abilities' ) ),
						'warnings' => Schema::warnings( __( 'Notes about the update, such as the content not being visible because Elementor renders this post.', 'mosmcp-abilities' ) ),
						'modified' => Schema::str(),
						'edit_url' => Schema::str(),
						'view_url' => Schema::str(),
						'post_type' => Schema::str(),
					),
					array( 'id', 'title', 'status' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/page-duplicate ability.
	 *
	 * A page's design lives in its metadata, not its content: an Elementor layout,
	 * the assigned page template, the theme's per-page width and sidebar settings
	 * and any ACF fields are all meta rows. Duplicating a page that already looks
	 * right is the reliable way to author a new one that matches.
	 *
	 * @return Ability
	 */
	private function duplicate() {
		return new Ability(
			'mosmcp/page-duplicate',
			array(
				'label'            => __( 'Duplicate Page', 'mosmcp-abilities' ),
				'description'      => __( 'Copies an existing page as a new draft, including its Elementor layout, assigned page template, theme display settings, custom fields and SEO fields. Use this to create a page that matches the design of an existing one, then edit the copy. The duplicate is authored by the current user and is created as a draft unless another status is given.', 'mosmcp-abilities' ),
				'category'         => 'mosmcp-pages',
				'capability'       => 'edit_post',
				'cap_args'         => self::source_id_args(),
				'permission_extra' => self::can_create_pages(),
				'annotations'      => self::annotations( false, false, false, false ),
				'execute'          => array( Pages_Provider::class, 'duplicate' ),
				'input_schema'     => Schema::object(
					array(
						'source_id'         => Schema::int( __( 'The ID of the page to duplicate. Its layout and settings become the new page\'s.', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
						'title'             => Schema::str( __( 'Title for the duplicate. Omit to use the original title followed by "(copy)".', 'mosmcp-abilities' ) ),
						'status'            => Schema::str(
							__( 'Status for the duplicate. Defaults to draft so the result can be reviewed before publishing. Publishing requires the publish capability.', 'mosmcp-abilities' ),
							array(
								'enum'    => Post_Duplicator::ALLOWED_STATUSES,
								'default' => 'draft',
							)
						),
						'include_content'   => Schema::boolean( __( 'Whether to copy the page body. Set false to keep the layout and settings but start with empty content. Defaults to true.', 'mosmcp-abilities' ), array( 'default' => true ) ),
						'expected_modified' => Schema::str( __( 'Optional. The "modified" timestamp from a previous read of the source page. The duplicate is refused if the source changed since then.', 'mosmcp-abilities' ) ),
					),
					array( 'source_id' )
				),
				'output_schema'    => Schema::object(
					array(
						'new_id'    => Schema::int( __( 'ID of the newly created duplicate.', 'mosmcp-abilities' ) ),
						'source_id' => Schema::int(),
						'title'     => Schema::str(),
						'status'    => Schema::str(),
						'edit_url'  => Schema::str(),
						'view_url'  => Schema::str(),
						'copied'    => Schema::object(
							array(
								'meta_rows'       => Schema::int( __( 'Number of metadata rows copied.', 'mosmcp-abilities' ) ),
								'taxonomies'      => Schema::arr( array( 'type' => 'string' ), __( 'Taxonomies whose terms were copied.', 'mosmcp-abilities' ) ),
								'thumbnail_id'    => Schema::int( __( 'Featured image attachment ID on the duplicate, or 0.', 'mosmcp-abilities' ) ),
								'has_elementor'   => Schema::boolean( __( 'Whether the duplicate carries an Elementor layout.', 'mosmcp-abilities' ) ),
								'elementor_bytes' => Schema::int(),
							)
						),
						'skipped'   => Schema::arr(
							Schema::object(
								array(
									'key'    => Schema::str(),
									'reason' => Schema::str(),
								)
							),
							__( 'Metadata deliberately not copied, with the reason for each.', 'mosmcp-abilities' )
						),
						'rewritten' => Schema::arr(
							Schema::object(
								array(
									'key'    => Schema::str(),
									'reason' => Schema::str(),
								)
							),
							__( 'Metadata cleared rather than copied because the value is unique to the original.', 'mosmcp-abilities' )
						),
						'warnings'  => Schema::arr(
							Schema::object(
								array(
									'code'    => Schema::str(),
									'message' => Schema::str(),
									'context' => Schema::str(),
								)
							),
							__( 'Non-fatal issues encountered while duplicating.', 'mosmcp-abilities' )
						),
					),
					array( 'new_id', 'source_id', 'title', 'status' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/page-set-featured-image ability.
	 *
	 * @return Ability
	 */
	private function set_featured_image() {
		return new Ability(
			'mosmcp/page-set-featured-image',
			array(
				'label'            => __( 'Set Page Featured Image', 'mosmcp-abilities' ),
				'description'      => __( 'Sets the featured image of a page to an existing media library image, or removes it by passing 0. Many themes render the featured image as the page banner.', 'mosmcp-abilities' ),
				'category'         => 'mosmcp-pages',
				'capability'       => 'edit_post',
				'cap_args'         => self::id_args(),
				'permission_extra' => self::can_edit_page(),
				'annotations'      => self::annotations( false, false, true, true ),
				'execute'          => array( Pages_Provider::class, 'set_featured_image' ),
				'input_schema'     => Schema::object(
					array(
						'id'            => Schema::int( __( 'The ID of the page to update.', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
						'attachment_id' => Schema::int( __( 'Media library attachment ID of the image to use, or 0 to remove the current featured image.', 'mosmcp-abilities' ), array( 'minimum' => 0 ) ),
					),
					array( 'id', 'attachment_id' )
				),
				'output_schema'    => Schema::featured_image_output(),
			)
		);
	}

	/**
	 * Defines the mosmcp/page-template-get ability.
	 *
	 * @return Ability
	 */
	private function template_get() {
		return new Ability(
			'mosmcp/page-template-get',
			array(
				'label'         => __( 'Get Page Template And Display Settings', 'mosmcp-abilities' ),
				'description'   => __( 'Reports which page template a page uses, whether Elementor controls its layout, and the per-page theme settings (sidebar, content width, title visibility) that decide how it renders. Use this to find out why one page looks different from another.', 'mosmcp-abilities' ),
				'category'      => 'mosmcp-pages',
				'capability'    => 'edit_post',
				'cap_args'      => self::id_args(),
				'annotations'   => self::annotations( true, false, true, false ),
				'execute'       => array( Pages_Provider::class, 'template_get' ),
				'input_schema'  => Schema::object(
					array(
						'id' => Schema::int( __( 'The ID of the page to inspect.', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
					),
					array( 'id' )
				),
				'output_schema' => Schema::template_get_output(),
			)
		);
	}

	/**
	 * Defines the mosmcp/page-template-set ability.
	 *
	 * @return Ability
	 */
	private function template_set() {
		return new Ability(
			'mosmcp/page-template-set',
			array(
				'label'            => __( 'Set Page Template', 'mosmcp-abilities' ),
				'description'      => __( 'Assigns a page template to a page, for example one of Elementor\'s full-width or canvas templates. Only templates the active theme and its plugins actually register are accepted, so a mistyped slug is refused instead of silently falling back to the default.', 'mosmcp-abilities' ),
				'category'         => 'mosmcp-pages',
				'capability'       => 'edit_post',
				'cap_args'         => self::id_args(),
				'permission_extra' => self::can_edit_page(),
				'annotations'      => self::annotations( false, false, true, true ),
				'execute'          => array( Pages_Provider::class, 'template_set' ),
				'input_schema'     => Schema::object(
					array(
						'id'            => Schema::int( __( 'The ID of the page to update.', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
						'page_template' => Schema::str( __( 'Template slug to assign, or "default" for the theme default. Read the page first to list the slugs available on this site.', 'mosmcp-abilities' ), array( 'minLength' => 1 ) ),
					),
					array( 'id', 'page_template' )
				),
				'output_schema'    => Schema::template_set_output(),
			)
		);
	}

	/**
	 * Defines the mosmcp/page-update-own ability.
	 *
	 * @return Ability
	 */
	private function update_own() {
		return new Ability(
			'mosmcp/page-update-own',
			array(
				'label'         => __( 'Update Own Page', 'mosmcp-abilities' ),
				'description'   => __( 'Edits the title, content and/or excerpt of a page authored by the current user. Cannot edit pages written by other users. Only the fields you pass are changed; the page keeps its current status. If the post is built with Elementor, what visitors read comes from the Elementor layout rather than from this content, so change the text on the Elementor elements instead.', 'mosmcp-abilities' ),
				'category'      => 'mosmcp-pages',
				'capability'    => 'edit_page',
				'cap_args'      => self::id_args(),
				'annotations'   => self::annotations( false, false, false, true ),
				'execute'       => array( Pages_Provider::class, 'update_own' ),
				'input_schema'  => Schema::object(
					array(
						'id'      => Schema::int( __( 'The ID of the page to update.', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
						'title'   => Schema::str( __( 'New title for the page. Omit to keep the current title.', 'mosmcp-abilities' ) ),
						'content' => Schema::str( __( 'New content for the page. Omit to keep the current content.', 'mosmcp-abilities' ) ),
						'excerpt' => Schema::str( __( 'New excerpt for the page. Omit to keep the current excerpt; pass an empty string to clear it.', 'mosmcp-abilities' ) ),
						'slug'    => Schema::str( __( 'New URL slug. Omit to leave the address unchanged. Reduced to a URL-safe form, and a suffix is added if it is already taken.', 'mosmcp-abilities' ) ),
					),
					array( 'id' )
				),
				'output_schema' => Schema::object(
					array(
						'id'       => Schema::int(),
						'title'    => Schema::str(),
						'status'   => Schema::str(),
						'slug'     => Schema::str( __( 'The URL slug as stored, which may differ from a requested one.', 'mosmcp-abilities' ) ),
						'modified' => Schema::str(),
						'updated'  => Schema::arr( array( 'type' => 'string' ), __( 'Fields that were changed.', 'mosmcp-abilities' ) ),
						'warnings' => Schema::warnings( __( 'Notes about the update, such as the content not being visible because Elementor renders this post.', 'mosmcp-abilities' ) ),
						'edit_url' => Schema::str(),
						'view_url' => Schema::str(),
						'post_type' => Schema::str(),
					),
					array( 'id', 'title', 'status' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/page-publish ability.
	 *
	 * @return Ability
	 */
	private function publish() {
		return new Ability(
			'mosmcp/page-publish',
			array(
				'label'            => __( 'Publish Page', 'mosmcp-abilities' ),
				'description'      => __( 'Changes a draft or pending page to published, making it publicly visible immediately.', 'mosmcp-abilities' ),
				'category'         => 'mosmcp-pages',
				'capability'       => 'publish_pages',
				'permission_extra' => self::can_edit_page(),
				'annotations'      => self::annotations( false, false, false, true ),
				'execute'          => array( Pages_Provider::class, 'publish' ),
				'input_schema'     => Schema::object(
					array(
						'id' => Schema::int( __( 'The ID of the draft or pending page to publish.', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
					),
					array( 'id' )
				),
				'output_schema'    => Schema::object(
					array(
						'id'              => Schema::int(),
						'title'           => Schema::str(),
						'previous_status' => Schema::str(),
						'new_status'      => Schema::str(),
						'link'            => Schema::str(),
						'edit_url'        => Schema::str(),
					),
					array( 'id', 'title', 'previous_status', 'new_status' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/page-unpublish ability.
	 *
	 * @return Ability
	 */
	private function unpublish() {
		return new Ability(
			'mosmcp/page-unpublish',
			array(
				'label'         => __( 'Unpublish Page', 'mosmcp-abilities' ),
				'description'   => __( 'Reverts a published page back to draft status, removing it from public view.', 'mosmcp-abilities' ),
				'category'      => 'mosmcp-pages',
				'capability'    => 'edit_page',
				'cap_args'      => self::id_args(),
				'annotations'   => self::annotations( false, false, false, true ),
				'execute'       => array( Pages_Provider::class, 'unpublish' ),
				'input_schema'  => Schema::object(
					array(
						'id' => Schema::int( __( 'The ID of the published page to revert to draft.', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
					),
					array( 'id' )
				),
				'output_schema' => self::status_change_output(),
			)
		);
	}

	/**
	 * Defines the mosmcp/page-schedule ability.
	 *
	 * @return Ability
	 */
	private function schedule() {
		return new Ability(
			'mosmcp/page-schedule',
			array(
				'label'            => __( 'Schedule Page', 'mosmcp-abilities' ),
				'description'      => __( 'Schedules a draft or pending page to be published automatically at a future date and time (site timezone).', 'mosmcp-abilities' ),
				'category'         => 'mosmcp-pages',
				'capability'       => 'publish_pages',
				'permission_extra' => self::can_edit_page(),
				'annotations'      => self::annotations( false, false, false, true ),
				'execute'          => array( Pages_Provider::class, 'schedule' ),
				'input_schema'     => Schema::object(
					array(
						'id'   => Schema::int( __( 'The ID of the draft or pending page to schedule.', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
						'date' => Schema::str( __( 'Future date and time in the site timezone, format: YYYY-MM-DD HH:MM:SS (e.g. 2026-08-01 09:00:00).', 'mosmcp-abilities' ) ),
					),
					array( 'id', 'date' )
				),
				'output_schema'    => Schema::object(
					array(
						'id'              => Schema::int(),
						'title'           => Schema::str(),
						'previous_status' => Schema::str(),
						'new_status'      => Schema::str(),
						'scheduled_for'   => Schema::str(),
						'edit_url'        => Schema::str(),
					),
					array( 'id', 'title', 'new_status', 'scheduled_for' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/page-set-private ability.
	 *
	 * @return Ability
	 */
	private function set_private() {
		return new Ability(
			'mosmcp/page-set-private',
			array(
				'label'            => __( 'Set Page to Private', 'mosmcp-abilities' ),
				'description'      => __( 'Sets a page to private status, making it visible only to administrators and editors.', 'mosmcp-abilities' ),
				'category'         => 'mosmcp-pages',
				'capability'       => 'publish_pages',
				'permission_extra' => self::can_edit_page(),
				'annotations'      => self::annotations( false, false, false, true ),
				'execute'          => array( Pages_Provider::class, 'set_private' ),
				'input_schema'     => Schema::object(
					array(
						'id' => Schema::int( __( 'The ID of the page to make private.', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
					),
					array( 'id' )
				),
				'output_schema'    => self::status_change_output(),
			)
		);
	}

	/**
	 * Defines the mosmcp/page-set-pending ability.
	 *
	 * @return Ability
	 */
	private function set_pending() {
		return new Ability(
			'mosmcp/page-set-pending',
			array(
				'label'         => __( 'Set Page to Pending Review', 'mosmcp-abilities' ),
				'description'   => __( 'Sets a draft page to pending review, so an editor can review and publish it.', 'mosmcp-abilities' ),
				'category'      => 'mosmcp-pages',
				'capability'    => 'edit_page',
				'cap_args'      => self::id_args(),
				'annotations'   => self::annotations( false, false, false, true ),
				'execute'       => array( Pages_Provider::class, 'set_pending' ),
				'input_schema'  => Schema::object(
					array(
						'id' => Schema::int( __( 'The ID of the draft page to submit for review.', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
					),
					array( 'id' )
				),
				'output_schema' => self::status_change_output(),
			)
		);
	}

	/**
	 * Defines the mosmcp/page-trash ability.
	 *
	 * @return Ability
	 */
	private function trash() {
		return new Ability(
			'mosmcp/page-trash',
			array(
				'label'         => __( 'Trash Page', 'mosmcp-abilities' ),
				'description'   => __( 'Moves a page to the trash. The page is recoverable and can be restored later.', 'mosmcp-abilities' ),
				'category'      => 'mosmcp-pages',
				'capability'    => 'delete_page',
				'cap_args'      => self::id_args(),
				'annotations'   => self::annotations( false, true, true, true ),
				'execute'       => array( Pages_Provider::class, 'trash' ),
				'input_schema'  => Schema::object(
					array(
						'id' => Schema::int( __( 'The ID of the page to move to trash.', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
					),
					array( 'id' )
				),
				'output_schema' => Schema::object(
					array(
						'id'              => Schema::int(),
						'title'           => Schema::str(),
						'previous_status' => Schema::str(),
						'new_status'      => Schema::str(),
						'restorable'      => Schema::boolean(),
					),
					array( 'id', 'title', 'new_status', 'restorable' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/page-restore ability.
	 *
	 * @return Ability
	 */
	private function restore() {
		return new Ability(
			'mosmcp/page-restore',
			array(
				'label'         => __( 'Restore Page from Trash', 'mosmcp-abilities' ),
				'description'   => __( 'Restores a page from the trash back to its previous status.', 'mosmcp-abilities' ),
				'category'      => 'mosmcp-pages',
				'capability'    => 'delete_page',
				'cap_args'      => self::id_args(),
				'annotations'   => self::annotations( false, false, true, true ),
				'execute'       => array( Pages_Provider::class, 'restore' ),
				'input_schema'  => Schema::object(
					array(
						'id' => Schema::int( __( 'The ID of the trashed page to restore.', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
					),
					array( 'id' )
				),
				'output_schema' => Schema::object(
					array(
						'id'         => Schema::int(),
						'title'      => Schema::str(),
						'new_status' => Schema::str(),
						'edit_url'   => Schema::str(),
					),
					array( 'id', 'title', 'new_status' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/page-delete-permanently ability.
	 *
	 * @return Ability
	 */
	private function delete_permanently() {
		return new Ability(
			'mosmcp/page-delete-permanently',
			array(
				'label'         => __( 'Delete Page Permanently', 'mosmcp-abilities' ),
				'description'   => __( 'Permanently deletes a page, bypassing the trash. This action is IRREVERSIBLE. Requires confirm=true.', 'mosmcp-abilities' ),
				'category'      => 'mosmcp-pages',
				'capability'    => 'delete_page',
				'cap_args'      => self::id_args(),
				'annotations'   => self::annotations( false, true, true, true ),
				'execute'       => array( Pages_Provider::class, 'delete_permanently' ),
				'input_schema'  => Schema::object(
					array(
						'id'      => Schema::int( __( 'The ID of the page to permanently delete.', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
						'confirm' => Schema::boolean( __( 'Must be true to confirm permanent, irreversible deletion.', 'mosmcp-abilities' ) ),
					),
					array( 'id', 'confirm' )
				),
				'output_schema' => Schema::object(
					array(
						'id'      => Schema::int(),
						'title'   => Schema::str(),
						'deleted' => Schema::boolean(),
					),
					array( 'id', 'title', 'deleted' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/page-find ability.
	 *
	 * @return Ability
	 */
	private function find() {
		return new Ability(
			'mosmcp/page-find',
			array(
				'label'         => __( 'Find Page (ID by Name / Name by ID)', 'mosmcp-abilities' ),
				'description'   => __( 'Looks up pages to resolve a page ID from a title/name, or a title from a page ID. Use this FIRST whenever the user refers to a page by its name and another ability requires a page ID. Provide "search" with the full or partial page title, or provide "id" to get that page\'s details. Searches all statuses. Returns up to 10 matches plus the total number of matches.', 'mosmcp-abilities' ),
				'category'      => 'mosmcp-pages',
				'capability'    => 'edit_pages',
				'annotations'   => self::annotations( true, false, true, false ),
				'execute'       => array( Pages_Provider::class, 'find' ),
				'input_schema'  => Schema::object(
					array(
						'search' => Schema::str( __( 'Full or partial page title/name to search for. Provide either this or "id".', 'mosmcp-abilities' ), array( 'minLength' => 1 ) ),
						'id'     => Schema::int( __( 'A page ID to look up the title and details for. Provide either this or "search".', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
					)
				),
				'output_schema' => Schema::object(
					array(
						'showing' => Schema::int(),
						'total'   => Schema::int(),
						'matches' => Schema::arr(
							Schema::object(
								array(
									'id'          => Schema::int(),
									'title'       => Schema::str(),
									'status'      => Schema::str(),
									'author_name' => Schema::str(),
									'date'        => Schema::str(),
									'edit_url'    => Schema::str(),
								),
								array( 'id', 'title', 'status' )
							)
						),
					),
					array( 'showing', 'total', 'matches' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/page-get ability.
	 *
	 * @return Ability
	 */
	private function get() {
		return new Ability(
			'mosmcp/page-get',
			array(
				'label'         => __( 'Get Page', 'mosmcp-abilities' ),
				'description'   => __( 'Gets the full details of a single page by its ID, including content, status, author, dates, and parent page.', 'mosmcp-abilities' ),
				'category'      => 'mosmcp-pages',
				'capability'    => 'read',
				'annotations'   => self::annotations( true, false, true, false ),
				'execute'       => array( Pages_Provider::class, 'get' ),
				'input_schema'  => Schema::object(
					array(
						'id' => Schema::int( __( 'The ID of the page to retrieve.', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
					),
					array( 'id' )
				),
				'output_schema' => Schema::object(
					array(
						'id'           => Schema::int(),
						'title'        => Schema::str(),
						'content'      => Schema::str(),
						'excerpt'      => Schema::str(),
						'status'       => Schema::str(),
						'author_id'    => Schema::int(),
						'author_name'  => Schema::str(),
						'created'      => Schema::str(),
						'modified'     => Schema::str(),
						'link'         => Schema::str(),
						'edit_url'     => Schema::str(),
						'parent_id'    => Schema::int(),
						'parent_title' => Schema::str(),
					),
					array( 'id', 'title', 'content', 'status' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/page-list-all ability.
	 *
	 * @return Ability
	 */
	private function list_all() {
		$properties = array_merge(
			array(
				'status' => Schema::str(
					__( 'Filter by page status. Use "any" for all statuses.', 'mosmcp-abilities' ),
					array(
						'enum'    => array( 'any', 'publish', 'draft', 'pending', 'future', 'private', 'trash' ),
						'default' => 'any',
					)
				),
			),
			Schema::pagination_props( __( 'pages', 'mosmcp-abilities' ) )
		);

		return new Ability(
			'mosmcp/page-list-all',
			array(
				'label'         => __( 'List All Pages', 'mosmcp-abilities' ),
				'description'   => __( 'Lists all pages of every status (published, draft, pending, scheduled, private, trashed), with an optional status filter. Returns at most per_page pages (default 20) plus the total. Users who cannot edit others\' pages only see their own pages.', 'mosmcp-abilities' ),
				'category'      => 'mosmcp-pages',
				'capability'    => 'edit_pages',
				'annotations'   => self::annotations( true, false, true, false ),
				'execute'       => array( Pages_Provider::class, 'list_all' ),
				'input_schema'  => Schema::object( $properties ),
				'output_schema' => self::page_list_output(
					array(
						'id'          => Schema::int(),
						'title'       => Schema::str(),
						'status'      => Schema::str(),
						'author_name' => Schema::str(),
						'date'        => Schema::str(),
						'modified'    => Schema::str(),
						'edit_url'    => Schema::str(),
					),
					array( 'id', 'title', 'status' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/page-list-drafts ability.
	 *
	 * @return Ability
	 */
	private function list_drafts() {
		return $this->paginated_list(
			'mosmcp/page-list-drafts',
			__( 'List Draft Pages', 'mosmcp-abilities' ),
			__( 'Lists all draft pages on the site, from every author, newest-modified first. Users who cannot edit others\' pages only see their own drafts.', 'mosmcp-abilities' ),
			'edit_pages',
			'list_drafts',
			__( 'pages', 'mosmcp-abilities' ),
			array(
				'id'          => Schema::int(),
				'title'       => Schema::str(),
				'author_name' => Schema::str(),
				'excerpt'     => Schema::str(),
				'modified'    => Schema::str(),
				'edit_url'    => Schema::str(),
			),
			array( 'id', 'title', 'modified' )
		);
	}

	/**
	 * Defines the mosmcp/page-list-own-drafts ability.
	 *
	 * @return Ability
	 */
	private function list_own_drafts() {
		return $this->paginated_list(
			'mosmcp/page-list-own-drafts',
			__( 'List Own Draft Pages', 'mosmcp-abilities' ),
			__( 'Lists draft pages created by the currently logged-in user only, newest-modified first.', 'mosmcp-abilities' ),
			'edit_pages',
			'list_own_drafts',
			__( 'pages', 'mosmcp-abilities' ),
			array(
				'id'       => Schema::int(),
				'title'    => Schema::str(),
				'excerpt'  => Schema::str(),
				'modified' => Schema::str(),
				'edit_url' => Schema::str(),
			),
			array( 'id', 'title', 'modified' )
		);
	}

	/**
	 * Defines the mosmcp/page-list-pending ability.
	 *
	 * @return Ability
	 */
	private function list_pending() {
		return $this->paginated_list(
			'mosmcp/page-list-pending',
			__( 'List Pending Pages', 'mosmcp-abilities' ),
			__( 'Lists pages that are pending review (submitted for an editor to approve and publish), newest-modified first. Users who cannot edit others\' pages only see their own pending pages.', 'mosmcp-abilities' ),
			'edit_pages',
			'list_pending',
			__( 'pages', 'mosmcp-abilities' ),
			array(
				'id'          => Schema::int(),
				'title'       => Schema::str(),
				'author_name' => Schema::str(),
				'excerpt'     => Schema::str(),
				'modified'    => Schema::str(),
				'edit_url'    => Schema::str(),
			),
			array( 'id', 'title', 'modified' )
		);
	}

	/**
	 * Defines the mosmcp/page-list-private ability.
	 *
	 * @return Ability
	 */
	private function list_private() {
		return $this->paginated_list(
			'mosmcp/page-list-private',
			__( 'List Private Pages', 'mosmcp-abilities' ),
			__( 'Lists private pages, which are visible only to editors and administrators.', 'mosmcp-abilities' ),
			'read_private_pages',
			'list_private',
			__( 'pages', 'mosmcp-abilities' ),
			array(
				'id'       => Schema::int(),
				'title'    => Schema::str(),
				'excerpt'  => Schema::str(),
				'modified' => Schema::str(),
				'edit_url' => Schema::str(),
			),
			array( 'id', 'title', 'modified' )
		);
	}

	/**
	 * Defines the mosmcp/page-list-published ability.
	 *
	 * @return Ability
	 */
	private function list_published() {
		return $this->paginated_list(
			'mosmcp/page-list-published',
			__( 'List Published Pages', 'mosmcp-abilities' ),
			__( 'Lists published (publicly visible) pages, newest first.', 'mosmcp-abilities' ),
			'read',
			'list_published',
			__( 'pages', 'mosmcp-abilities' ),
			array(
				'id'          => Schema::int(),
				'title'       => Schema::str(),
				'author_name' => Schema::str(),
				'excerpt'     => Schema::str(),
				'date'        => Schema::str(),
				'link'        => Schema::str(),
				'edit_url'    => Schema::str(),
			),
			array( 'id', 'title', 'date', 'link' )
		);
	}

	/**
	 * Defines the mosmcp/page-list-scheduled ability.
	 *
	 * @return Ability
	 */
	private function list_scheduled() {
		return $this->paginated_list(
			'mosmcp/page-list-scheduled',
			__( 'List Scheduled Pages', 'mosmcp-abilities' ),
			__( 'Lists pages that are scheduled to be published automatically at a future date and time.', 'mosmcp-abilities' ),
			'edit_pages',
			'list_scheduled',
			__( 'pages', 'mosmcp-abilities' ),
			array(
				'id'            => Schema::int(),
				'title'         => Schema::str(),
				'scheduled_for' => Schema::str(),
				'edit_url'      => Schema::str(),
			),
			array( 'id', 'title', 'scheduled_for' )
		);
	}

	/**
	 * Defines the mosmcp/page-list-trash ability.
	 *
	 * @return Ability
	 */
	private function list_trash() {
		return $this->paginated_list(
			'mosmcp/page-list-trash',
			__( 'List Trashed Pages', 'mosmcp-abilities' ),
			__( 'Lists pages currently in the trash, newest-modified first. Every trashed page is restorable (via mosmcp/page-restore) or can be permanently deleted (via mosmcp/page-delete-permanently). Users who cannot edit others\' pages only see their own trashed pages.', 'mosmcp-abilities' ),
			'edit_pages',
			'list_trash',
			__( 'pages', 'mosmcp-abilities' ),
			array(
				'id'              => Schema::int(),
				'title'           => Schema::str(),
				'author_name'     => Schema::str(),
				'previous_status' => Schema::str( __( 'The status the page had before it was trashed (it returns to this on restore).', 'mosmcp-abilities' ) ),
				'trashed_on'      => Schema::str(),
				'restorable'      => Schema::boolean(),
			),
			array( 'id', 'title', 'restorable' )
		);
	}

	/**
	 * Builds a read-only, paginated list ability (output rows keyed "pages").
	 *
	 * @param string               $name       Ability name.
	 * @param string               $label      Ability label.
	 * @param string               $desc       Ability description.
	 * @param string               $capability Capability gate.
	 * @param string               $method     Pages_Provider method name.
	 * @param string               $noun       Plural noun for pagination descriptions.
	 * @param array<string, mixed> $item_props Output item properties.
	 * @param string[]             $item_req   Required output item fields.
	 * @return Ability
	 */
	private function paginated_list( $name, $label, $desc, $capability, $method, $noun, array $item_props, array $item_req ) {
		return new Ability(
			$name,
			array(
				'label'         => $label,
				'description'   => $desc,
				'category'      => 'mosmcp-pages',
				'capability'    => $capability,
				'annotations'   => self::annotations( true, false, true, false ),
				'execute'       => array( Pages_Provider::class, $method ),
				'input_schema'  => Schema::object( Schema::pagination_props( $noun ) ),
				'output_schema' => self::page_list_output( $item_props, $item_req ),
			)
		);
	}

	/**
	 * Standard { showing, total, pages } list output schema.
	 *
	 * @param array<string, mixed> $item_props Output item properties.
	 * @param string[]             $item_req   Required output item fields.
	 * @return array<string, mixed>
	 */
	private static function page_list_output( array $item_props, array $item_req ) {
		return Schema::object(
			array(
				'showing' => Schema::int(),
				'total'   => Schema::int(),
				'pages'   => Schema::arr( Schema::object( $item_props, $item_req ) ),
			),
			array( 'showing', 'total', 'pages' )
		);
	}

	/**
	 * Output schema shared by simple status-change abilities.
	 *
	 * @return array<string, mixed>
	 */
	private static function status_change_output() {
		return Schema::object(
			array(
				'id'              => Schema::int(),
				'title'           => Schema::str(),
				'previous_status' => Schema::str(),
				'new_status'      => Schema::str(),
				'edit_url'        => Schema::str(),
			),
			array( 'id', 'title', 'previous_status', 'new_status' )
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
	 * Resolves the page ID from input for object-level capability checks (cap_args).
	 *
	 * @return callable
	 */
	private static function id_args() {
		return static function ( $input ) {
			return array( isset( $input['id'] ) ? absint( $input['id'] ) : 0 );
		};
	}

	/**
	 * Resolves the source page ID for object-level capability checks (cap_args).
	 *
	 * Duplication reads the source, so the capability gate is edit_post against
	 * source_id rather than against a page the caller is about to create.
	 *
	 * @return callable
	 */
	private static function source_id_args() {
		return static function ( $input ) {
			return array( isset( $input['source_id'] ) ? absint( $input['source_id'] ) : 0 );
		};
	}

	/**
	 * Requires the page type's create capability in addition to edit_post on the
	 * source (permission_extra).
	 *
	 * wp_insert_post enforces no capabilities of its own, so without this a user
	 * who may edit a page could create new ones they have no right to.
	 *
	 * @return callable
	 */
	private static function can_create_pages() {
		return static function () {
			$type = get_post_type_object( 'page' );
			return $type ? current_user_can( $type->cap->create_posts ) : false;
		};
	}

	/**
	 * Requires edit rights on the specific page when an ID is given (permission_extra).
	 *
	 * @return callable
	 */
	private static function can_edit_page() {
		return static function ( $input ) {
			$id = isset( $input['id'] ) ? absint( $input['id'] ) : 0;
			return $id > 0 ? current_user_can( 'edit_page', $id ) : true;
		};
	}
}
