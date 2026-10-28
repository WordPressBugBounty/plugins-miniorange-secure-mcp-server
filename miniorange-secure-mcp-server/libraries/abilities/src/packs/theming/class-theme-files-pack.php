<?php
/**
 * Theme-file ability pack: read the active theme's files, and change them in the
 * theme draft.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Theming;

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
 * Class Theme_Files_Pack
 */
class Theme_Files_Pack extends Ability_Pack {

	/**
	 * Ability category slug.
	 */
	const CATEGORY = 'mosmcp-theme-files';

	/*
	 * The writing abilities are gated on edit_theme_options, and enforce
	 * edit_themes themselves (Theme_Support::file_editing_blocked()). Gating them
	 * on edit_themes directly would be no more secure, but on a site with
	 * DISALLOW_FILE_EDIT — which removes edit_themes — it would refuse with a
	 * generic permission error instead of saying that a site policy is the cause.
	 */

	/**
	 * Ability category.
	 *
	 * @return array Category definition.
	 */
	public function category() {
		return array(
			'slug'        => self::CATEGORY,
			'label'       => __( 'Theme: Files', 'mosmcp-abilities' ),
			'description' => __( 'Read the active theme\'s code — PHP, CSS, JavaScript, templates and theme.json — and change it in a private draft copy that is checked for errors, previewed, and published only on request.', 'mosmcp-abilities' ),
		);
	}

	/**
	 * The abilities.
	 *
	 * @return Ability[]
	 */
	public function abilities() {
		return array(
			$this->file_list(),
			$this->file_read(),
			$this->file_search(),
			$this->file_outline(),
			$this->file_edit(),
			$this->file_write(),
			$this->file_delete(),
		);
	}

	/**
	 * Defines mosmcp/theme-file-list.
	 *
	 * @return Ability
	 */
	private function file_list() {
		return $this->ability(
			'mosmcp/theme-file-list',
			__( 'List Theme Files', 'mosmcp-abilities' ),
			__( 'Lists the active theme\'s files with their size, whether each can be read and written, and any change pending in the draft. Shows the draft copy when theme files have been changed there, otherwise the live theme. Read-only. Also known as: theme code, theme source, functions.php, style.css, theme folder.', 'mosmcp-abilities' ),
			'edit_theme_options',
			array( Theme_Files_Provider::class, 'file_list' ),
			Site_Support::annotations( true, false, true, false ),
			Schema::object(
				array(
					'path'   => Schema::str( __( 'Optional sub-folder to list, for example "templates" or "assets/css".', 'mosmcp-abilities' ) ),
					'source' => self::source_input(),
				)
			),
			Schema::object(
				array(
					'theme'     => Schema::str(),
					'source'    => Schema::str(),
					'count'     => Schema::int(),
					'truncated' => Schema::boolean(),
					'files'     => Schema::arr(
						Schema::object(
							array(
								'path'      => Schema::str(),
								'size'      => Schema::int(),
								'type'      => Schema::str( __( '"text" or "binary".', 'mosmcp-abilities' ) ),
								'writable'  => Schema::boolean(),
								'protected' => Schema::boolean( __( 'The theme cannot work without this file, so it cannot be deleted. It can still be edited.', 'mosmcp-abilities' ) ),
								'pending'   => Schema::str(),
							)
						)
					),
					'notes'     => Schema::arr( Schema::str() ),
				),
				array( 'count', 'files' )
			)
		);
	}

	/**
	 * Defines mosmcp/theme-file-read.
	 *
	 * @return Ability
	 */
	private function file_read() {
		return $this->ability(
			'mosmcp/theme-file-read',
			__( 'Read Theme File', 'mosmcp-abilities' ),
			__( 'Returns a theme file\'s contents — functions.php, style.css, a template, theme.json, a script. By default this is the draft copy when one exists, so edits build on each other; pass source "live" for what visitors get now. Long files come back in line ranges. Read-only.', 'mosmcp-abilities' ),
			'edit_theme_options',
			array( Theme_Files_Provider::class, 'file_read' ),
			Site_Support::annotations( true, false, true, false ),
			Schema::object(
				array(
					'path'       => Schema::str( __( 'Path inside the theme, for example "functions.php" or "templates/single.html".', 'mosmcp-abilities' ) ),
					'source'     => self::source_input(),
					'start_line' => Schema::int( __( 'First line to return.', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
					'line_count' => Schema::int( __( 'How many lines to return, up to 2000.', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
				),
				array( 'path' )
			),
			Schema::object(
				array(
					'path'       => Schema::str(),
					'source'     => Schema::str(),
					'size'       => Schema::int(),
					'lines'      => Schema::int(),
					'start_line' => Schema::int(),
					'end_line'   => Schema::int(),
					'truncated'  => Schema::boolean(),
					'pending'    => Schema::str(),
					'content'    => Schema::str(),
					'notes'      => Schema::arr( Schema::str() ),
				),
				array( 'path', 'content' )
			)
		);
	}

	/**
	 * Defines mosmcp/theme-file-search.
	 *
	 * @return Ability
	 */
	private function file_search() {
		return $this->ability(
			'mosmcp/theme-file-search',
			__( 'Search Theme Files', 'mosmcp-abilities' ),
			__( 'Searches every text file in the theme for some wording, a function name or a CSS selector, returning each matching line with its file and line number. Read-only. Also known as: grep theme, find in theme code, where is this defined.', 'mosmcp-abilities' ),
			'edit_theme_options',
			array( Theme_Files_Provider::class, 'file_search' ),
			Site_Support::annotations( true, false, true, false ),
			Schema::object(
				array(
					'text'           => Schema::str( __( 'Text to find.', 'mosmcp-abilities' ) ),
					'source'         => self::source_input(),
					'case_sensitive' => Schema::boolean( __( 'Match letter case exactly.', 'mosmcp-abilities' ), array( 'default' => false ) ),
					'types'          => Schema::arr( Schema::str(), __( 'Optional file extensions to search, for example ["php","css"].', 'mosmcp-abilities' ) ),
				),
				array( 'text' )
			),
			Schema::object(
				array(
					'searched_for'   => Schema::str(),
					'source'         => Schema::str(),
					'files_searched' => Schema::int(),
					'match_count'    => Schema::int(),
					'truncated'      => Schema::boolean(),
					'matches'        => Schema::arr(
						Schema::object(
							array(
								'path' => Schema::str(),
								'line' => Schema::int(),
								'text' => Schema::str(),
							)
						)
					),
					'notes'          => Schema::arr( Schema::str() ),
				),
				array( 'match_count', 'matches' )
			)
		);
	}

	/**
	 * Defines mosmcp/theme-file-outline.
	 *
	 * @return Ability
	 */
	private function file_outline() {
		return $this->ability(
			'mosmcp/theme-file-outline',
			__( 'Outline Theme File', 'mosmcp-abilities' ),
			__( 'Summarizes a theme file\'s structure without returning all of it: for PHP its functions, classes and methods, the hooks it registers and the scripts and styles it enqueues; for CSS its selectors; for theme.json its sections; for a template the blocks it uses. The quickest way to understand functions.php before changing it. Read-only.', 'mosmcp-abilities' ),
			'edit_theme_options',
			array( Theme_Files_Provider::class, 'file_outline' ),
			Site_Support::annotations( true, false, true, false ),
			Schema::object(
				array(
					'path'   => Schema::str( __( 'Path inside the theme.', 'mosmcp-abilities' ) ),
					'source' => self::source_input(),
				),
				array( 'path' )
			),
			Schema::object(
				array(
					'path'           => Schema::str(),
					'source'         => Schema::str(),
					'language'       => Schema::str(),
					'lines'          => Schema::int(),
					'functions'      => Schema::arr(
						Schema::object(
							array(
								'name' => Schema::str(),
								'line' => Schema::int(),
							)
						)
					),
					'classes'        => Schema::arr(
						Schema::object(
							array(
								'name'    => Schema::str(),
								'line'    => Schema::int(),
								'methods' => Schema::arr( Schema::str() ),
							)
						)
					),
					'hooks'          => Schema::arr(
						Schema::object(
							array(
								'kind'     => Schema::str(),
								'hook'     => Schema::str(),
								'callback' => Schema::str(),
								'line'     => Schema::int(),
							)
						)
					),
					'enqueues'       => Schema::arr(
						Schema::object(
							array(
								'kind'   => Schema::str(),
								'handle' => Schema::str(),
								'line'   => Schema::int(),
							)
						)
					),
					'blocks'         => Schema::arr(
						Schema::object(
							array(
								'name'  => Schema::str(),
								'count' => Schema::int(),
							)
						)
					),
					'selector_count' => Schema::int(),
					'selectors'      => Schema::arr( Schema::str() ),
					'json_keys'      => Schema::arr( Schema::str() ),
					'notes'          => Schema::arr( Schema::str() ),
				),
				array( 'path', 'language' )
			)
		);
	}

	/**
	 * Defines mosmcp/theme-file-edit.
	 *
	 * @return Ability
	 */
	private function file_edit() {
		return $this->ability(
			'mosmcp/theme-file-edit',
			__( 'Edit Theme File', 'mosmcp-abilities' ),
			__( 'Changes one passage inside a theme file — for example a function in functions.php or a rule in style.css — without rewriting the rest. The change goes into the theme draft, never the live theme. Refuses when the text is missing or appears more than once unless replace_all is set; PHP is checked for syntax errors, JSON for validity and templates for balanced blocks before the change is accepted. Also known as: edit functions.php, change CSS, modify theme code, add custom code.', 'mosmcp-abilities' ),
			'edit_theme_options',
			array( Theme_Files_Provider::class, 'file_edit' ),
			Site_Support::annotations( false, false, false, false ),
			Schema::object(
				array(
					'path'         => Schema::str( __( 'Path inside the theme, for example "functions.php".', 'mosmcp-abilities' ) ),
					'find_text'    => Schema::str( __( 'The exact text to change. Include enough surrounding lines to make it unique.', 'mosmcp-abilities' ) ),
					'replace_with' => Schema::str( __( 'The new text.', 'mosmcp-abilities' ) ),
					'replace_all'  => Schema::boolean( __( 'Change every occurrence instead of requiring exactly one.', 'mosmcp-abilities' ), array( 'default' => false ) ),
					'whole_word'   => Schema::boolean( __( 'Only match whole words.', 'mosmcp-abilities' ), array( 'default' => false ) ),
				),
				array( 'path', 'find_text', 'replace_with' )
			),
			Block_Theme_Pack::staged_output(
				array(
					'occurrences' => Schema::int(),
					'match_mode'  => Schema::str(),
				)
			)
		);
	}

	/**
	 * Defines mosmcp/theme-file-write.
	 *
	 * @return Ability
	 */
	private function file_write() {
		return $this->ability(
			'mosmcp/theme-file-write',
			__( 'Write Theme File', 'mosmcp-abilities' ),
			__( 'Writes a whole theme file, creating it if it does not exist — for example a new stylesheet or an include for functions.php. The file goes into the theme draft, never the live theme, after the same syntax, JSON and block checks as an edit. To change part of an existing file, mosmcp/theme-file-edit is safer.', 'mosmcp-abilities' ),
			'edit_theme_options',
			array( Theme_Files_Provider::class, 'file_write' ),
			Site_Support::annotations( false, false, true, false ),
			Schema::object(
				array(
					'path'        => Schema::str( __( 'Path inside the theme.', 'mosmcp-abilities' ) ),
					'content'     => Schema::str( __( 'The complete file contents.', 'mosmcp-abilities' ) ),
					'create_only' => Schema::boolean( __( 'Refuse instead of overwriting when the file already exists.', 'mosmcp-abilities' ), array( 'default' => false ) ),
				),
				array( 'path', 'content' )
			),
			Block_Theme_Pack::staged_output()
		);
	}

	/**
	 * Defines mosmcp/theme-file-delete.
	 *
	 * @return Ability
	 */
	private function file_delete() {
		return $this->ability(
			'mosmcp/theme-file-delete',
			__( 'Delete Theme File', 'mosmcp-abilities' ),
			__( 'Deletes a theme file in the theme draft; the live theme keeps it until the draft is published. The files a theme cannot work without, such as style.css, are refused.', 'mosmcp-abilities' ),
			'edit_theme_options',
			array( Theme_Files_Provider::class, 'file_delete' ),
			Site_Support::annotations( false, false, true, false ),
			Schema::object(
				array(
					'path' => Schema::str( __( 'Path inside the theme.', 'mosmcp-abilities' ) ),
				),
				array( 'path' )
			),
			Block_Theme_Pack::staged_output()
		);
	}

	/**
	 * Builds an ability with the pack's shared settings.
	 *
	 * @param string               $name        Name.
	 * @param string               $label       Label.
	 * @param string               $description Description.
	 * @param string               $capability  Capability.
	 * @param callable             $execute     Execute callback.
	 * @param array<string, bool>  $annotations Annotations.
	 * @param array<string, mixed> $input       Input schema.
	 * @param array<string, mixed> $output      Output schema.
	 * @return Ability
	 */
	private function ability( $name, $label, $description, $capability, $execute, array $annotations, array $input, array $output ) {
		return new Ability(
			$name,
			array(
				'label'         => $label,
				'description'   => $description,
				'category'      => self::CATEGORY,
				'capability'    => $capability,
				'annotations'   => $annotations,
				'execute'       => $execute,
				'input_schema'  => $input,
				'output_schema' => $output,
			)
		);
	}

	/**
	 * The shared "source" input.
	 *
	 * @return array<string, mixed>
	 */
	private static function source_input() {
		return Schema::str(
			__( '"draft" (the default: the draft copy when theme files have been changed there) or "live".', 'mosmcp-abilities' ),
			array(
				'enum'    => array( 'draft', 'live' ),
				'default' => 'draft',
			)
		);
	}
}
