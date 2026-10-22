<?php
/**
 * Content editing ability pack: find and replace inside stored values.
 *
 * Split by target rather than one ability with a mode switch, so a role can be
 * granted post editing without also being granted option editing. Post, page and
 * custom post types share one pair, because they share the same object-level
 * capability check; meta and options are separate because theirs genuinely differ.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Editing;

use MoSMCP\Abilities\Ability;
use MoSMCP\Abilities\Ability_Pack;
use MoSMCP\Abilities\Packs\Core\Support\Core_Schema as Schema;
use MoSMCP\Abilities\Packs\Site\Site_Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * This library authors every translatable string under its own fixed text domain
 * ('mosmcp-abilities'). The host plugin remaps them to its own text domain at
 * runtime via Abilities_Library::init(). The domain therefore intentionally will
 * not match any host plugin's slug, so the text-domain-mismatch check is disabled
 * for this file (the library's phpcs.xml.dist allows the domain on the CLI; this
 * directive covers IDE and Plugin Check runs that don't read that ruleset).
 */
// phpcs:disable WordPress.WP.I18n.TextDomainMismatch

/**
 * Class Editing_Pack
 *
 * Declares the content editing abilities. Execute logic lives in Editing_Provider.
 */
class Editing_Pack extends Ability_Pack {

	/**
	 * Ability category slug.
	 */
	const CATEGORY = 'mosmcp-content-editing';

	/**
	 * Ability category for content editing abilities.
	 *
	 * @return array Category definition.
	 */
	public function category() {
		return array(
			'slug'        => self::CATEGORY,
			'label'       => __( 'Content Editing', 'mosmcp-abilities' ),
			'description' => __( 'Change part of the stored text of a post, a custom field or a setting, without rewriting the whole thing.', 'mosmcp-abilities' ),
		);
	}

	/**
	 * The content editing abilities.
	 *
	 * @return Ability[]
	 */
	public function abilities() {
		return array(
			$this->find_in_posts(),
			$this->replace_in_post(),
			$this->find_in_meta(),
			$this->replace_in_meta(),
			$this->find_in_options(),
			$this->replace_in_option(),
		);
	}

	/**
	 * Defines the mosmcp/content-find-in-posts ability.
	 *
	 * @return Ability
	 */
	private function find_in_posts() {
		return new Ability(
			'mosmcp/content-find-in-posts',
			array(
				'label'         => __( 'Find Text in Posts', 'mosmcp-abilities' ),
				'description'   => __( 'Finds where a phrase appears inside the title, body or excerpt of posts and pages, and returns the exact stored wording with the text around it. Read-only. Always call this before mosmcp/content-replace-in-post: what is stored often differs from what the page displays, because WordPress turns quotes into curly ones and stores ampersands and hard spaces as codes. The wording this returns is guaranteed to work as the text to replace. Also known as: find text, search content, where does it say, locate wording.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'edit_posts',
				'annotations'   => Site_Support::annotations( true, false, true, false ),
				'execute'       => array( Editing_Provider::class, 'find_in_posts' ),
				'input_schema'  => Schema::object(
					array(
						'text'        => Schema::str( __( 'The phrase to look for. Matching tolerates curly quotes, ampersand codes and spacing differences.', 'mosmcp-abilities' ) ),
						'post_type'   => Schema::str(
							__( 'Which type to search. Defaults to posts and pages together.', 'mosmcp-abilities' ),
							array( 'default' => 'any' )
						),
						'post_status' => Schema::str(
							__( 'Which status to search.', 'mosmcp-abilities' ),
							array(
								'enum'    => array( 'any', 'publish', 'draft', 'pending', 'private' ),
								'default' => 'any',
							)
						),
						'per_page'    => Schema::int(
							__( 'Maximum number of items to return matches from.', 'mosmcp-abilities' ),
							array(
								'default' => 10,
								'minimum' => 1,
								'maximum' => 50,
							)
						),
					),
					array( 'text' )
				),
				'output_schema' => Schema::object(
					array(
						'searched_for' => Schema::str(),
						'items_found'  => Schema::int( __( 'How many posts or pages contain the phrase.', 'mosmcp-abilities' ) ),
						'items'        => Schema::arr(
							Schema::object(
								array(
									'id'         => Schema::int(),
									'title'      => Schema::str(),
									'post_type'  => Schema::str(),
									'status'     => Schema::str(),
									'edit_url'   => Schema::str(),
									'field_hits' => Schema::arr(
										Schema::object(
											array(
												'field'   => Schema::str( __( 'Which field holds it: post_title, post_content or post_excerpt.', 'mosmcp-abilities' ) ),
												'occurrences' => Schema::int(),
												'match_mode' => Schema::str( __( 'How it matched: exact, or a tolerant mode when the stored wording differs.', 'mosmcp-abilities' ) ),
												'stored_text' => Schema::str( __( 'The wording exactly as stored. Pass this as find_text when replacing.', 'mosmcp-abilities' ) ),
												'context' => Schema::str( __( 'The surrounding text, so the right occurrence can be identified.', 'mosmcp-abilities' ) ),
											)
										)
									),
								)
							)
						),
						'notes'        => Schema::arr( Schema::str() ),
					),
					array( 'searched_for', 'items_found', 'items' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/content-replace-in-post ability.
	 *
	 * @return Ability
	 */
	private function replace_in_post() {
		return new Ability(
			'mosmcp/content-replace-in-post',
			array(
				'label'         => __( 'Replace Text in a Post', 'mosmcp-abilities' ),
				'description'   => __( 'Changes one passage of text inside a post or page, leaving every other byte untouched. Use this whenever you are changing part of the text: it is far cheaper than rewriting the whole post, and it cannot lose formatting, shortcodes or block markup the way a full rewrite can. Use mosmcp/post-update instead only when you are deliberately replacing the entire content with new text you already have in full. The text must appear exactly once, or the change is refused and the alternatives are listed, so nothing is ever changed in the wrong place. Also known as: find and replace, change wording, edit text, reword, update copy, change a phrase.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'edit_post',
				'cap_args'      => self::post_id_args(),
				'annotations'   => Site_Support::annotations( false, false, false, false ),
				'execute'       => array( Editing_Provider::class, 'replace_in_post' ),
				'input_schema'  => Schema::object(
					array(
						'id'           => Schema::int( __( 'The ID of the post or page to edit.', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
						'field'        => Schema::str(
							__( 'Which field to edit.', 'mosmcp-abilities' ),
							array(
								'enum'    => array( 'post_content', 'post_excerpt', 'post_title' ),
								'default' => 'post_content',
							)
						),
						'find_text'    => Schema::str( __( 'The existing wording to replace. Get this from mosmcp/content-find-in-posts rather than from the visible page, so it matches what is stored.', 'mosmcp-abilities' ) ),
						'replace_with' => Schema::str( __( 'The new wording. Pass an empty string to delete the found text.', 'mosmcp-abilities' ) ),
						'replace_all'  => Schema::boolean(
							__( 'Change every occurrence instead of requiring the text to appear exactly once. Leave false unless every occurrence really should change.', 'mosmcp-abilities' ),
							array( 'default' => false )
						),
						'whole_word'   => Schema::boolean(
							__( 'Only match the text when it stands as a whole word, so "cat" does not match inside "catalogue".', 'mosmcp-abilities' ),
							array( 'default' => false )
						),
					),
					array( 'id', 'find_text', 'replace_with' )
				),
				'output_schema' => self::change_output(),
			)
		);
	}

	/**
	 * Defines the mosmcp/content-find-in-meta ability.
	 *
	 * @return Ability
	 */
	private function find_in_meta() {
		return new Ability(
			'mosmcp/content-find-in-meta',
			array(
				'label'         => __( 'Find Text in Custom Fields', 'mosmcp-abilities' ),
				'description'   => __( 'Finds where a phrase appears in the custom fields of one post, and returns the exact stored wording. Read-only. Custom fields are where page builders keep their layouts, so this is how to locate text that appears on a page but is not in the post body. For an Elementor page, prefer the Elementor abilities, which understand the layout structure; use this only when those cannot reach what you need. Also known as: find text in custom fields, search post meta, search builder content.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'edit_posts',
				'annotations'   => Site_Support::annotations( true, false, true, false ),
				'execute'       => array( Editing_Provider::class, 'find_in_meta' ),
				'input_schema'  => Schema::object(
					array(
						'post_id'  => Schema::int( __( 'The ID of the post whose custom fields should be searched.', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
						'text'     => Schema::str( __( 'The phrase to look for.', 'mosmcp-abilities' ) ),
						'meta_key' => Schema::str( __( 'Optional. Search only this one custom field.', 'mosmcp-abilities' ) ),
					),
					array( 'post_id', 'text' )
				),
				'output_schema' => Schema::object(
					array(
						'post_id'      => Schema::int(),
						'searched_for' => Schema::str(),
						'fields_found' => Schema::int(),
						'fields'       => Schema::arr(
							Schema::object(
								array(
									'meta_key'    => Schema::str(),
									'occurrences' => Schema::int(),
									'match_mode'  => Schema::str(),
									'stored_text' => Schema::str( __( 'The wording exactly as stored. Pass this as find_text when replacing.', 'mosmcp-abilities' ) ),
									'context'     => Schema::str(),
									'editable'    => Schema::boolean( __( 'False when the value cannot safely be text-edited, for example because it is PHP-serialized.', 'mosmcp-abilities' ) ),
									'reason'      => Schema::str( __( 'Why it is not editable, when it is not.', 'mosmcp-abilities' ) ),
								)
							)
						),
						'notes'        => Schema::arr( Schema::str() ),
					),
					array( 'post_id', 'searched_for', 'fields_found', 'fields' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/content-replace-in-meta ability.
	 *
	 * @return Ability
	 */
	private function replace_in_meta() {
		return new Ability(
			'mosmcp/content-replace-in-meta',
			array(
				'label'         => __( 'Replace Text in a Custom Field', 'mosmcp-abilities' ),
				'description'   => __( 'Changes one passage of text inside a single custom field, leaving the rest of the value untouched. This is how to edit text that a page builder stores outside the post body. For Elementor pages prefer the Elementor abilities, which edit the layout through its own structure and cannot mistake a setting for visible text; use this when those cannot reach what you need. Refused outright when the value is PHP-serialized, or when the change would break JSON that is currently valid. Also known as: find and replace in custom fields, edit post meta text, change builder text.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'edit_post',
				'cap_args'      => self::post_id_args( 'post_id' ),
				'annotations'   => Site_Support::annotations( false, false, false, false ),
				'execute'       => array( Editing_Provider::class, 'replace_in_meta' ),
				'input_schema'  => Schema::object(
					array(
						'post_id'      => Schema::int( __( 'The ID of the post that holds the field.', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
						'meta_key'     => Schema::str( __( 'The name of the custom field to edit.', 'mosmcp-abilities' ) ),
						'find_text'    => Schema::str( __( 'The existing wording to replace. Get this from mosmcp/content-find-in-meta.', 'mosmcp-abilities' ) ),
						'replace_with' => Schema::str( __( 'The new wording. Pass an empty string to delete the found text.', 'mosmcp-abilities' ) ),
						'replace_all'  => Schema::boolean(
							__( 'Change every occurrence instead of requiring exactly one.', 'mosmcp-abilities' ),
							array( 'default' => false )
						),
					),
					array( 'post_id', 'meta_key', 'find_text', 'replace_with' )
				),
				'output_schema' => self::change_output(),
			)
		);
	}

	/**
	 * Defines the mosmcp/content-find-in-options ability.
	 *
	 * @return Ability
	 */
	private function find_in_options() {
		return new Ability(
			'mosmcp/content-find-in-options',
			array(
				'label'         => __( 'Find Text in a Setting', 'mosmcp-abilities' ),
				'description'   => __( 'Finds where a phrase appears inside one named site setting, and returns the exact stored wording. Read-only. The setting has to be named; settings are not searched in bulk, because reading every setting on a site is slow and exposes far more than was asked for. Also known as: find text in settings, search options, where is that wording set.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'manage_options',
				'annotations'   => Site_Support::annotations( true, false, true, false ),
				'execute'       => array( Editing_Provider::class, 'find_in_options' ),
				'input_schema'  => Schema::object(
					array(
						'option' => Schema::str( __( 'The exact name of the setting to search.', 'mosmcp-abilities' ) ),
						'text'   => Schema::str( __( 'The phrase to look for.', 'mosmcp-abilities' ) ),
					),
					array( 'option', 'text' )
				),
				'output_schema' => Schema::object(
					array(
						'option'       => Schema::str(),
						'exists'       => Schema::boolean(),
						'searched_for' => Schema::str(),
						'occurrences'  => Schema::int(),
						'match_mode'   => Schema::str(),
						'stored_text'  => Schema::str( __( 'The wording exactly as stored.', 'mosmcp-abilities' ) ),
						'context'      => Schema::str(),
						'editable'     => Schema::boolean( __( 'False when this setting cannot be text-edited, because it is protected or not plain text.', 'mosmcp-abilities' ) ),
						'reason'       => Schema::str(),
					),
					array( 'option', 'exists', 'searched_for', 'occurrences' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/content-replace-in-option ability.
	 *
	 * @return Ability
	 */
	private function replace_in_option() {
		return new Ability(
			'mosmcp/content-replace-in-option',
			array(
				'label'         => __( 'Replace Text in a Setting', 'mosmcp-abilities' ),
				'description'   => __( 'Changes one passage of text inside a named site setting. Settings that control how the site loads, who may do what, or how sessions are signed can never be changed this way and are refused by name. Refused too when the value is PHP-serialized, or when the change would break JSON that is currently valid. Prefer the ability that owns a setting where one exists, for example the site settings abilities for the title or tagline. Also known as: find and replace in settings, change the wording of a setting, edit option text.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'manage_options',
				'annotations'   => Site_Support::annotations( false, false, false, false ),
				'execute'       => array( Editing_Provider::class, 'replace_in_option' ),
				'input_schema'  => Schema::object(
					array(
						'option'       => Schema::str( __( 'The exact name of the setting to edit.', 'mosmcp-abilities' ) ),
						'find_text'    => Schema::str( __( 'The existing wording to replace. Get this from mosmcp/content-find-in-options.', 'mosmcp-abilities' ) ),
						'replace_with' => Schema::str( __( 'The new wording. Pass an empty string to delete the found text.', 'mosmcp-abilities' ) ),
						'replace_all'  => Schema::boolean(
							__( 'Change every occurrence instead of requiring exactly one.', 'mosmcp-abilities' ),
							array( 'default' => false )
						),
					),
					array( 'option', 'find_text', 'replace_with' )
				),
				'output_schema' => self::change_output(),
			)
		);
	}

	/**
	 * Resolves a post id input for the object-level capability check.
	 *
	 * @param string $key Input key holding the post id.
	 * @return callable
	 */
	private static function post_id_args( $key = 'id' ) {
		return static function ( $input ) use ( $key ) {
			return array( isset( $input[ $key ] ) ? absint( $input[ $key ] ) : 0 );
		};
	}

	/**
	 * The shape every replace ability returns.
	 *
	 * @return array<string, mixed>
	 */
	private static function change_output() {
		return Schema::object(
			array(
				'target'          => Schema::str( __( 'What was edited, for example "post 12 post_content".', 'mosmcp-abilities' ) ),
				'changed'         => Schema::boolean(),
				'occurrences'     => Schema::int( __( 'How many occurrences were replaced.', 'mosmcp-abilities' ) ),
				'match_mode'      => Schema::str( __( 'How the text was matched. Anything other than "exact" means the stored wording differed from what was asked for, so the result is worth checking.', 'mosmcp-abilities' ) ),
				'length_before'   => Schema::int(),
				'length_after'    => Schema::int(),
				'stored_verbatim' => Schema::boolean( __( 'False when the site altered the text on the way in, which means the stored value now differs from what was sent.', 'mosmcp-abilities' ) ),
				'notes'           => Schema::arr( Schema::str() ),
			),
			array( 'target', 'changed', 'occurrences' )
		);
	}
}
