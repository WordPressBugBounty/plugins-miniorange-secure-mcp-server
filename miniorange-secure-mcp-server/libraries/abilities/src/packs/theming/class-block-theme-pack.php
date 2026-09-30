<?php
/**
 * Block-theme ability pack: templates, template parts, global styles, style
 * variations and patterns — every change staged in the theme draft.
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
 * Class Block_Theme_Pack
 */
class Block_Theme_Pack extends Ability_Pack {

	/**
	 * Ability category slug.
	 */
	const CATEGORY = 'mosmcp-theme-blocks';

	/**
	 * Capability every block-theme ability requires.
	 */
	const CAPABILITY = 'edit_theme_options';

	/**
	 * Ability category.
	 *
	 * @return array Category definition.
	 */
	public function category() {
		return array(
			'slug'        => self::CATEGORY,
			'label'       => __( 'Theme: Templates and Styles', 'mosmcp-abilities' ),
			'description' => __( 'Read and change a block theme\'s templates, template parts, global styles, style variations and patterns. Every change is staged in a draft and goes live only when the draft is published.', 'mosmcp-abilities' ),
		);
	}

	/**
	 * The abilities.
	 *
	 * @return Ability[]
	 */
	public function abilities() {
		return array(
			$this->template_list(),
			$this->template_get(),
			$this->part_list(),
			$this->part_get(),
			$this->styles_get(),
			$this->json_get(),
			$this->variation_list(),
			$this->pattern_list(),
			$this->pattern_get(),
			$this->template_update(),
			$this->template_reset(),
			$this->part_update(),
			$this->part_reset(),
			$this->styles_update(),
			$this->styles_reset(),
			$this->variation_apply(),
			$this->pattern_save(),
			$this->text_find(),
			$this->text_replace(),
		);
	}

	/*
	 * Reads
	 */

	/**
	 * Defines mosmcp/theme-template-list.
	 *
	 * @return Ability
	 */
	private function template_list() {
		return $this->ability(
			'mosmcp/theme-template-list',
			__( 'List Theme Templates', 'mosmcp-abilities' ),
			__( 'Lists a block theme\'s templates — the layouts WordPress uses for the home page, single posts, pages, archives, search, 404 and so on — with where each one\'s content currently comes from and whether a change to it is pending in the draft. Read-only. Also known as: page templates, site templates, block templates, full site editing templates.', 'mosmcp-abilities' ),
			array( Block_Theme_Provider::class, 'template_list' ),
			Site_Support::annotations( true, false, true, false ),
			Schema::object(
				array(
					'search' => Schema::str( __( 'Optional. Only templates whose slug or title contains this.', 'mosmcp-abilities' ) ),
				)
			),
			self::list_output()
		);
	}

	/**
	 * Defines mosmcp/theme-template-get.
	 *
	 * @return Ability
	 */
	private function template_get() {
		return $this->ability(
			'mosmcp/theme-template-get',
			__( 'Get Theme Template', 'mosmcp-abilities' ),
			__( 'Returns one block theme template\'s block markup. By default this is the draft version when a change is pending, so edits build on each other; ask for "live" to see what visitors get now, or "theme" for the theme\'s original. Read-only.', 'mosmcp-abilities' ),
			array( Block_Theme_Provider::class, 'template_get' ),
			Site_Support::annotations( true, false, true, false ),
			self::get_input( __( 'Template slug, for example "single", "page", "home" or "404".', 'mosmcp-abilities' ) ),
			self::get_output()
		);
	}

	/**
	 * Defines mosmcp/theme-part-list.
	 *
	 * @return Ability
	 */
	private function part_list() {
		return $this->ability(
			'mosmcp/theme-part-list',
			__( 'List Theme Template Parts', 'mosmcp-abilities' ),
			__( 'Lists a block theme\'s template parts — the reusable pieces such as the header, footer and sidebar that templates include — with their area, where their content comes from, and any pending draft change. Read-only. Also known as: header, footer, site header, site footer, sidebar.', 'mosmcp-abilities' ),
			array( Block_Theme_Provider::class, 'part_list' ),
			Site_Support::annotations( true, false, true, false ),
			Schema::object(
				array(
					'search' => Schema::str( __( 'Optional. Only parts whose slug or title contains this.', 'mosmcp-abilities' ) ),
					'area'   => Schema::str(
						__( 'Optional. Only parts in this area.', 'mosmcp-abilities' ),
						array( 'enum' => array( 'header', 'footer', 'sidebar', 'uncategorized' ) )
					),
				)
			),
			self::list_output()
		);
	}

	/**
	 * Defines mosmcp/theme-part-get.
	 *
	 * @return Ability
	 */
	private function part_get() {
		return $this->ability(
			'mosmcp/theme-part-get',
			__( 'Get Theme Template Part', 'mosmcp-abilities' ),
			__( 'Returns one template part\'s block markup, such as the header or footer. By default this is the draft version when a change is pending; ask for "live" or "theme" to see the others. Read-only.', 'mosmcp-abilities' ),
			array( Block_Theme_Provider::class, 'part_get' ),
			Site_Support::annotations( true, false, true, false ),
			self::get_input( __( 'Template part slug, for example "header" or "footer".', 'mosmcp-abilities' ) ),
			self::get_output()
		);
	}

	/**
	 * Defines mosmcp/theme-styles-get.
	 *
	 * @return Ability
	 */
	private function styles_get() {
		return $this->ability(
			'mosmcp/theme-styles-get',
			__( 'Get Global Styles', 'mosmcp-abilities' ),
			__( 'Returns the site\'s global styles: the colour palette, font families, font sizes, spacing sizes, content widths, site-wide colour and typography, element styles such as links and headings, and the site\'s own overrides of the theme. Includes pending draft changes. Pass a dotted path to read any deeper setting. Read-only. Also known as: site colours, brand colours, fonts, typography, design system, theme styles.', 'mosmcp-abilities' ),
			array( Block_Theme_Provider::class, 'styles_get' ),
			Site_Support::annotations( true, false, true, false ),
			Schema::object(
				array(
					'path' => Schema::str( __( 'Optional dotted path into the merged styles, for example "styles.elements.link" or "settings.typography".', 'mosmcp-abilities' ) ),
				)
			),
			Schema::object(
				array(
					'theme'          => Schema::str(),
					'source'         => Schema::str( __( '"draft" when a style change is pending, otherwise "live".', 'mosmcp-abilities' ) ),
					'has_overrides'  => Schema::boolean(),
					'palette'        => self::preset_list( 'color' ),
					'font_families'  => self::preset_list( 'fontFamily' ),
					'font_sizes'     => self::preset_list( 'size' ),
					'spacing_sizes'  => self::preset_list( 'size' ),
					'content_width'  => Schema::str(),
					'wide_width'     => Schema::str(),
					'site_styles'    => Schema::map(),
					'element_styles' => Schema::map(),
					'overrides'      => Schema::map( __( 'Only what this site changed on top of the theme.', 'mosmcp-abilities' ) ),
					'path_value'     => self::anything(),
					'notes'          => Schema::arr( Schema::str() ),
				),
				array( 'theme', 'palette', 'overrides' )
			)
		);
	}

	/**
	 * Defines mosmcp/theme-json-get.
	 *
	 * @return Ability
	 */
	private function json_get() {
		return $this->ability(
			'mosmcp/theme-json-get',
			__( 'Get Theme JSON', 'mosmcp-abilities' ),
			__( 'Returns the active theme\'s theme.json as the theme ships it — the settings it allows and the styles it defines — before any change made on this site. Pass a dotted path to read part of it. Read-only.', 'mosmcp-abilities' ),
			array( Block_Theme_Provider::class, 'json_get' ),
			Site_Support::annotations( true, false, true, false ),
			Schema::object(
				array(
					'path' => Schema::str( __( 'Optional dotted path, for example "settings.color" or "styles.blocks".', 'mosmcp-abilities' ) ),
				)
			),
			Schema::object(
				array(
					'theme'      => Schema::str(),
					'version'    => Schema::int(),
					'top_level'  => Schema::arr( Schema::str() ),
					'path'       => Schema::str(),
					'value'      => self::anything(),
					'truncated'  => Schema::boolean(),
					'size_bytes' => Schema::int(),
					'notes'      => Schema::arr( Schema::str() ),
				),
				array( 'theme', 'value' )
			)
		);
	}

	/**
	 * Defines mosmcp/theme-variation-list.
	 *
	 * @return Ability
	 */
	private function variation_list() {
		return $this->ability(
			'mosmcp/theme-variation-list',
			__( 'List Style Variations', 'mosmcp-abilities' ),
			__( 'Lists the ready-made looks the theme ships, with each one\'s colours and fonts and which is in use: whole styles, colour palettes and typography sets, as the Site Editor offers them. Read-only. Also known as: theme skins, colour schemes, dark mode, alternative styles.', 'mosmcp-abilities' ),
			array( Block_Theme_Provider::class, 'variation_list' ),
			Site_Support::annotations( true, false, true, false ),
			Schema::object( array() ),
			Schema::object(
				array(
					'theme'      => Schema::str(),
					'count'      => Schema::int(),
					'variations' => Schema::arr(
						Schema::object(
							array(
								'title'       => Schema::str(),
								'kind'        => Schema::str( __( 'style (a whole look), colours (a colour palette only) or typography (fonts only).', 'mosmcp-abilities' ) ),
								'description' => Schema::str(),
								'colours'     => Schema::arr( Schema::str() ),
								'fonts'       => Schema::arr( Schema::str() ),
								'is_current'  => Schema::boolean(),
							)
						)
					),
					'notes'      => Schema::arr( Schema::str() ),
				),
				array( 'count', 'variations' )
			)
		);
	}

	/**
	 * Defines mosmcp/theme-pattern-list.
	 *
	 * @return Ability
	 */
	private function pattern_list() {
		return $this->ability(
			'mosmcp/theme-pattern-list',
			__( 'List Patterns', 'mosmcp-abilities' ),
			__( 'Lists block patterns: the ready-made layouts registered by the theme, WordPress and plugins, and the patterns saved on this site (synced ones update everywhere they are used). Read-only. Also known as: reusable blocks, synced patterns, block patterns, section templates.', 'mosmcp-abilities' ),
			array( Block_Theme_Provider::class, 'pattern_list' ),
			Site_Support::annotations( true, false, true, false ),
			Schema::object(
				array(
					'search'   => Schema::str( __( 'Optional. Only patterns whose title, name or category contains this.', 'mosmcp-abilities' ) ),
					'source'   => Schema::str(
						__( 'Which patterns to list.', 'mosmcp-abilities' ),
						array(
							'enum'    => array( 'all', 'registered', 'synced' ),
							'default' => 'all',
						)
					),
					'per_page' => Schema::int(
						__( 'Maximum patterns to return.', 'mosmcp-abilities' ),
						array(
							'default' => 50,
							'minimum' => 1,
							'maximum' => 100,
						)
					),
					'offset'   => Schema::int( __( 'How many to skip, for paging.', 'mosmcp-abilities' ), array( 'minimum' => 0 ) ),
				)
			),
			Schema::object(
				array(
					'total'    => Schema::int(),
					'showing'  => Schema::int(),
					'patterns' => Schema::arr(
						Schema::object(
							array(
								'kind'       => Schema::str( __( '"registered" (read-only) or "saved" (on this site).', 'mosmcp-abilities' ) ),
								'name'       => Schema::str(),
								'id'         => Schema::int(),
								'title'      => Schema::str(),
								'categories' => Schema::arr( Schema::str() ),
								'synced'     => Schema::boolean(),
								'pending'    => Schema::str(),
							)
						)
					),
					'notes'    => Schema::arr( Schema::str() ),
				),
				array( 'total', 'patterns' )
			)
		);
	}

	/**
	 * Defines mosmcp/theme-pattern-get.
	 *
	 * @return Ability
	 */
	private function pattern_get() {
		return $this->ability(
			'mosmcp/theme-pattern-get',
			__( 'Get Pattern', 'mosmcp-abilities' ),
			__( 'Returns one pattern\'s block markup: a registered pattern by name, or a pattern saved on this site by ID. Read-only.', 'mosmcp-abilities' ),
			array( Block_Theme_Provider::class, 'pattern_get' ),
			Site_Support::annotations( true, false, true, false ),
			Schema::object(
				array(
					'name' => Schema::str( __( 'Registered pattern name, for example "twentytwentyfive/hero". Use this or "id".', 'mosmcp-abilities' ) ),
					'id'   => Schema::int( __( 'Saved pattern ID. Use this or "name".', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
				)
			),
			Schema::object(
				array(
					'kind'    => Schema::str(),
					'name'    => Schema::str(),
					'id'      => Schema::int(),
					'title'   => Schema::str(),
					'synced'  => Schema::boolean(),
					'content' => Schema::str(),
					'pending' => Schema::str(),
					'notes'   => Schema::arr( Schema::str() ),
				),
				array( 'title', 'content' )
			)
		);
	}

	/*
	 * Draft edits
	 */

	/**
	 * Defines mosmcp/theme-template-update.
	 *
	 * @return Ability
	 */
	private function template_update() {
		return $this->ability(
			'mosmcp/theme-template-update',
			__( 'Update Theme Template', 'mosmcp-abilities' ),
			__( 'Replaces a block theme template\'s markup, staged in the theme draft — nothing changes for visitors until the draft is published. Works whether the template is still the theme\'s file or was already customised in the Site Editor. For a small wording change, mosmcp/theme-text-replace is safer than rewriting the whole template.', 'mosmcp-abilities' ),
			array( Block_Theme_Provider::class, 'template_update' ),
			Site_Support::annotations( false, false, true, false ),
			self::update_input( __( 'Template slug, for example "single" or "home".', 'mosmcp-abilities' ) ),
			self::staged_output()
		);
	}

	/**
	 * Defines mosmcp/theme-template-reset.
	 *
	 * @return Ability
	 */
	private function template_reset() {
		return $this->ability(
			'mosmcp/theme-template-reset',
			__( 'Reset Theme Template', 'mosmcp-abilities' ),
			__( 'Stages a return to the theme\'s own version of a template that was customised in the Site Editor, as the Site Editor\'s "Reset" does. Staged in the draft; nothing changes until the draft is published, and the customisation is backed up then. Also known as: revert template, undo template changes, restore default template.', 'mosmcp-abilities' ),
			array( Block_Theme_Provider::class, 'template_reset' ),
			Site_Support::annotations( false, false, true, false ),
			self::slug_input( __( 'Template slug.', 'mosmcp-abilities' ) ),
			self::staged_output()
		);
	}

	/**
	 * Defines mosmcp/theme-part-update.
	 *
	 * @return Ability
	 */
	private function part_update() {
		return $this->ability(
			'mosmcp/theme-part-update',
			__( 'Update Theme Template Part', 'mosmcp-abilities' ),
			__( 'Replaces a template part\'s markup — the header, footer or another reusable part — staged in the theme draft. Nothing changes for visitors until the draft is published. For a small wording change, such as the footer copyright line, use mosmcp/theme-text-replace instead. Also known as: edit header, edit footer, change site header, change footer.', 'mosmcp-abilities' ),
			array( Block_Theme_Provider::class, 'part_update' ),
			Site_Support::annotations( false, false, true, false ),
			self::update_input( __( 'Template part slug, for example "header" or "footer".', 'mosmcp-abilities' ) ),
			self::staged_output()
		);
	}

	/**
	 * Defines mosmcp/theme-part-reset.
	 *
	 * @return Ability
	 */
	private function part_reset() {
		return $this->ability(
			'mosmcp/theme-part-reset',
			__( 'Reset Theme Template Part', 'mosmcp-abilities' ),
			__( 'Stages a return to the theme\'s own version of a template part that was customised, such as a header or footer edited in the Site Editor. Staged in the draft; nothing changes until the draft is published.', 'mosmcp-abilities' ),
			array( Block_Theme_Provider::class, 'part_reset' ),
			Site_Support::annotations( false, false, true, false ),
			self::slug_input( __( 'Template part slug.', 'mosmcp-abilities' ) ),
			self::staged_output()
		);
	}

	/**
	 * Defines mosmcp/theme-styles-update.
	 *
	 * @return Ability
	 */
	private function styles_update() {
		return $this->ability(
			'mosmcp/theme-styles-update',
			__( 'Update Global Styles', 'mosmcp-abilities' ),
			__( 'Changes the site\'s global styles — background and text colours, fonts, font sizes, spacing, link and heading styles, the colour palette — staged in the theme draft. For headings only, use styles.elements.heading (or h1 to h6); styles.typography changes the text of the whole site. Pass only what should change: objects merge into the current styles, lists such as a palette are replaced whole, and null removes a setting. WordPress\'s own validator runs on the result, and anything it does not recognise is reported rather than silently kept. Also known as: change site colours, change fonts, rebrand, restyle, change typography, dark background.', 'mosmcp-abilities' ),
			array( Block_Theme_Provider::class, 'styles_update' ),
			Site_Support::annotations( false, false, true, false ),
			Schema::object(
				array(
					'styles'   => Schema::map( __( 'Style changes in theme.json form, for example {"color":{"background":"#0b1f3a","text":"#ffffff"},"elements":{"link":{"color":{"text":"var:preset|color|accent-1"}}}}.', 'mosmcp-abilities' ) ),
					'settings' => Schema::map( __( 'Setting changes in theme.json form, for example {"color":{"palette":[{"slug":"brand","name":"Brand","color":"#e4572e"}]}}.', 'mosmcp-abilities' ) ),
				)
			),
			self::staged_output( array( 'ignored' => Schema::arr( Schema::str(), __( 'Paths WordPress did not recognise and dropped.', 'mosmcp-abilities' ) ) ) )
		);
	}

	/**
	 * Defines mosmcp/theme-styles-reset.
	 *
	 * @return Ability
	 */
	private function styles_reset() {
		return $this->ability(
			'mosmcp/theme-styles-reset',
			__( 'Reset Global Styles', 'mosmcp-abilities' ),
			__( 'Stages removing every global-style change made on this site, so the theme\'s own colours and typography apply again. Staged in the draft; nothing changes until it is published, and the previous styles are backed up then.', 'mosmcp-abilities' ),
			array( Block_Theme_Provider::class, 'styles_reset' ),
			Site_Support::annotations( false, false, true, false ),
			Schema::object( array() ),
			self::staged_output()
		);
	}

	/**
	 * Defines mosmcp/theme-variation-apply.
	 *
	 * @return Ability
	 */
	private function variation_apply() {
		return $this->ability(
			'mosmcp/theme-variation-apply',
			__( 'Apply Style Variation', 'mosmcp-abilities' ),
			__( 'Stages switching the site to one of the theme\'s ready-made style variations, as choosing one in the Site Editor does. A whole style replaces the site\'s current global-style overrides; a colour palette or typography set changes only colours or only fonts. Staged in the draft; preview it before publishing. Also known as: change theme skin, switch colour scheme, change fonts, apply a look.', 'mosmcp-abilities' ),
			array( Block_Theme_Provider::class, 'variation_apply' ),
			Site_Support::annotations( false, false, true, false ),
			Schema::object(
				array(
					'title' => Schema::str( __( 'Variation title exactly as listed by mosmcp/theme-variation-list.', 'mosmcp-abilities' ) ),
					'kind'  => Schema::str(
						__( 'Optional. Which variation to use when a title appears more than once: style, colours or typography. Defaults to the whole style.', 'mosmcp-abilities' ),
						array( 'enum' => Block_Theme_Provider::VARIATION_KINDS )
					),
				),
				array( 'title' )
			),
			self::staged_output()
		);
	}

	/**
	 * Defines mosmcp/theme-pattern-save.
	 *
	 * @return Ability
	 */
	private function pattern_save() {
		return $this->ability(
			'mosmcp/theme-pattern-save',
			__( 'Save Pattern', 'mosmcp-abilities' ),
			__( 'Creates a new saved pattern, or updates a saved one by ID, staged in the theme draft. A synced pattern shows the same content everywhere it is used, so updating one changes every page that includes it — which is why it waits for the draft to be published. Returns insert_markup to place the pattern in a template or part within the same draft. Registered patterns from the theme or plugins cannot be changed. Also known as: reusable block, reusable section, banner, call to action.', 'mosmcp-abilities' ),
			array( Block_Theme_Provider::class, 'pattern_save' ),
			Site_Support::annotations( false, false, false, false ),
			Schema::object(
				array(
					'id'      => Schema::int( __( 'ID of the saved pattern to update. Omit to create a new one.', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
					'title'   => Schema::str( __( 'Pattern title. Required for a new pattern.', 'mosmcp-abilities' ) ),
					'content' => Schema::str( __( 'Block markup. Required for a new pattern; omit to keep the current content when only renaming.', 'mosmcp-abilities' ) ),
					'synced'  => Schema::boolean( __( 'For a new pattern: whether it stays in sync everywhere it is used.', 'mosmcp-abilities' ), array( 'default' => true ) ),
				)
			),
			self::staged_output(
				array(
					'insert_markup' => Schema::str( __( 'Block markup that places this pattern in a template or part.', 'mosmcp-abilities' ) ),
				)
			)
		);
	}

	/**
	 * Defines mosmcp/theme-text-find.
	 *
	 * @return Ability
	 */
	private function text_find() {
		return $this->ability(
			'mosmcp/theme-text-find',
			__( 'Find Text in Templates', 'mosmcp-abilities' ),
			__( 'Searches every template and template part for some wording — for example to find where the footer copyright line or a phone number lives — including pending draft changes. Quotes, ampersands and spacing are matched forgivingly. Read-only.', 'mosmcp-abilities' ),
			array( Block_Theme_Provider::class, 'text_find' ),
			Site_Support::annotations( true, false, true, false ),
			Schema::object(
				array(
					'text'  => Schema::str( __( 'Wording to find.', 'mosmcp-abilities' ) ),
					'scope' => Schema::str(
						__( 'Where to look.', 'mosmcp-abilities' ),
						array(
							'enum'    => array( 'all', 'templates', 'parts' ),
							'default' => 'all',
						)
					),
				),
				array( 'text' )
			),
			Schema::object(
				array(
					'searched_for' => Schema::str(),
					'items_found'  => Schema::int(),
					'items'        => Schema::arr(
						Schema::object(
							array(
								'type'          => Schema::str( __( '"template" or "part".', 'mosmcp-abilities' ) ),
								'slug'          => Schema::str(),
								'title'         => Schema::str(),
								'occurrences'   => Schema::int(),
								'match_mode'    => Schema::str(),
								'snippets'      => Schema::arr( Schema::str() ),
								'from_patterns' => Schema::arr( Schema::str(), __( 'Theme patterns the wording actually lives in, when the template only references them.', 'mosmcp-abilities' ) ),
							)
						)
					),
					'notes'        => Schema::arr( Schema::str() ),
				),
				array( 'items_found', 'items' )
			)
		);
	}

	/**
	 * Defines mosmcp/theme-text-replace.
	 *
	 * @return Ability
	 */
	private function text_replace() {
		return $this->ability(
			'mosmcp/theme-text-replace',
			__( 'Replace Text in a Template', 'mosmcp-abilities' ),
			__( 'Changes one passage of wording inside a template or template part — such as the footer copyright line — without rewriting the rest, staged in the theme draft. Refuses when the text is missing or appears more than once unless replace_all is set, and refuses any change that would unbalance the block markup. Also known as: change footer text, edit copyright, change header wording.', 'mosmcp-abilities' ),
			array( Block_Theme_Provider::class, 'text_replace' ),
			Site_Support::annotations( false, false, false, false ),
			Schema::object(
				array(
					'type'         => Schema::str(
						__( 'Whether slug names a template or a template part.', 'mosmcp-abilities' ),
						array( 'enum' => array( 'template', 'part' ) )
					),
					'slug'         => Schema::str( __( 'Template or part slug, for example "footer".', 'mosmcp-abilities' ) ),
					'find_text'    => Schema::str( __( 'The exact wording to change.', 'mosmcp-abilities' ) ),
					'replace_with' => Schema::str( __( 'The new wording.', 'mosmcp-abilities' ) ),
					'replace_all'  => Schema::boolean( __( 'Change every occurrence instead of requiring exactly one.', 'mosmcp-abilities' ), array( 'default' => false ) ),
					'whole_word'   => Schema::boolean( __( 'Only match whole words.', 'mosmcp-abilities' ), array( 'default' => false ) ),
				),
				array( 'type', 'slug', 'find_text', 'replace_with' )
			),
			self::staged_output(
				array(
					'occurrences' => Schema::int(),
					'match_mode'  => Schema::str(),
				)
			)
		);
	}

	/*
	 * Schema builders
	 */

	/**
	 * Builds an ability with the pack's shared settings.
	 *
	 * @param string               $name        Name.
	 * @param string               $label       Label.
	 * @param string               $description Description.
	 * @param callable             $execute     Execute callback.
	 * @param array<string, bool>  $annotations Annotations.
	 * @param array<string, mixed> $input       Input schema.
	 * @param array<string, mixed> $output      Output schema.
	 * @return Ability
	 */
	private function ability( $name, $label, $description, $execute, array $annotations, array $input, array $output ) {
		return new Ability(
			$name,
			array(
				'label'         => $label,
				'description'   => $description,
				'category'      => self::CATEGORY,
				'capability'    => self::CAPABILITY,
				'annotations'   => $annotations,
				'execute'       => $execute,
				'input_schema'  => $input,
				'output_schema' => $output,
			)
		);
	}

	/**
	 * Input for the template/part get abilities.
	 *
	 * @param string $slug_description Slug description.
	 * @return array<string, mixed>
	 */
	private static function get_input( $slug_description ) {
		return Schema::object(
			array(
				'slug'    => Schema::str( $slug_description ),
				'version' => Schema::str(
					__( '"draft" (the default: the pending version when there is one), "live" (what visitors get now) or "theme" (as the theme ships it).', 'mosmcp-abilities' ),
					array(
						'enum'    => array( 'draft', 'live', 'theme' ),
						'default' => 'draft',
					)
				),
			),
			array( 'slug' )
		);
	}

	/**
	 * Input with only a slug.
	 *
	 * @param string $slug_description Slug description.
	 * @return array<string, mixed>
	 */
	private static function slug_input( $slug_description ) {
		return Schema::object( array( 'slug' => Schema::str( $slug_description ) ), array( 'slug' ) );
	}

	/**
	 * Input for the template/part update abilities.
	 *
	 * @param string $slug_description Slug description.
	 * @return array<string, mixed>
	 */
	private static function update_input( $slug_description ) {
		return Schema::object(
			array(
				'slug'    => Schema::str( $slug_description ),
				'content' => Schema::str( __( 'The complete new block markup.', 'mosmcp-abilities' ) ),
			),
			array( 'slug', 'content' )
		);
	}

	/**
	 * Properties describing one template or part.
	 *
	 * @return array<string, mixed>
	 */
	private static function template_properties() {
		return array(
			'slug'           => Schema::str(),
			'id'             => Schema::str(),
			'title'          => Schema::str(),
			'description'    => Schema::str(),
			'type'           => Schema::str(),
			'area'           => Schema::str(),
			'origin'         => Schema::str( __( '"theme", "customised", "user" or "plugin".', 'mosmcp-abilities' ) ),
			'can_reset'      => Schema::boolean(),
			'modified'       => Schema::str(),
			'pending'        => Schema::str( __( 'Pending draft change: "update", "reset" or empty.', 'mosmcp-abilities' ) ),
			'content_length' => Schema::int(),
		);
	}

	/**
	 * Output of the list abilities.
	 *
	 * @return array<string, mixed>
	 */
	private static function list_output() {
		return Schema::object(
			array(
				'theme'      => Schema::str(),
				'count'      => Schema::int(),
				'customised' => Schema::int(),
				'items'      => Schema::arr( Schema::object( self::template_properties() ) ),
				'notes'      => Schema::arr( Schema::str() ),
			),
			array( 'count', 'items' )
		);
	}

	/**
	 * Output of the get abilities.
	 *
	 * @return array<string, mixed>
	 */
	private static function get_output() {
		return Schema::object(
			array_merge(
				self::template_properties(),
				array(
					'content'        => Schema::str(),
					'content_source' => Schema::str( __( '"draft", "live" or "theme".', 'mosmcp-abilities' ) ),
					'notes'          => Schema::arr( Schema::str() ),
				)
			),
			array( 'slug', 'content' )
		);
	}

	/**
	 * Output of every staged edit.
	 *
	 * @param array<string, mixed> $extra Additional properties.
	 * @return array<string, mixed>
	 */
	public static function staged_output( array $extra = array() ) {
		return Schema::object(
			array_merge(
				array(
					'staged'    => Schema::boolean(),
					'target'    => Schema::str(),
					'change'    => Schema::str(),
					'draft'     => self::draft_summary_schema(),
					'next_step' => Schema::str(),
					'notes'     => Schema::arr( Schema::str() ),
				),
				$extra
			),
			array( 'staged', 'draft' )
		);
	}

	/**
	 * Schema of a draft summary.
	 *
	 * @return array<string, mixed>
	 */
	public static function draft_summary_schema() {
		return Schema::object(
			array(
				'exists'         => Schema::boolean(),
				'change_count'   => Schema::int(),
				'templates'      => Schema::arr( Schema::str() ),
				'template_parts' => Schema::arr( Schema::str() ),
				'global_styles'  => Schema::str(),
				'patterns'       => Schema::arr( Schema::str() ),
				'files'          => Schema::arr( Schema::str() ),
				'created'        => Schema::str(),
				'updated'        => Schema::str(),
				'preview_ready'  => Schema::boolean(),
			)
		);
	}

	/**
	 * A list of presets.
	 *
	 * @param string $value_key Name of the value field.
	 * @return array<string, mixed>
	 */
	private static function preset_list( $value_key ) {
		return Schema::arr(
			Schema::object(
				array(
					'slug'     => Schema::str(),
					'name'     => Schema::str(),
					$value_key => Schema::str(),
				)
			)
		);
	}

	/**
	 * A value of any JSON type.
	 *
	 * @return array<string, mixed>
	 */
	private static function anything() {
		return array( 'type' => array( 'object', 'array', 'string', 'number', 'integer', 'boolean', 'null' ) );
	}
}
