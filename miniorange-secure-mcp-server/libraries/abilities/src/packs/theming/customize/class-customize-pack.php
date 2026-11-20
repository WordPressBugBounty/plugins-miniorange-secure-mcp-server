<?php
/**
 * Additional CSS and theme settings ability pack: read them, and stage changes
 * in the theme draft.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Theming\Customize;

use MoSMCP\Abilities\Ability;
use MoSMCP\Abilities\Ability_Pack;
use MoSMCP\Abilities\Packs\Core\Support\Core_Schema as Schema;
use MoSMCP\Abilities\Packs\Site\Site_Support;
use MoSMCP\Abilities\Packs\Theming\Blocktheme\Block_Theme_Pack;

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
 * Class Customize_Pack
 */
class Customize_Pack extends Ability_Pack {

	/**
	 * Ability category slug.
	 */
	const CATEGORY = 'mosmcp-theme-customize';

	/**
	 * Ability category.
	 *
	 * @return array Category definition.
	 */
	public function category() {
		return array(
			'slug'        => self::CATEGORY,
			'label'       => __( 'Theme: Custom CSS and Settings', 'mosmcp-abilities' ),
			'description' => __( 'Add to or edit the site\'s Additional CSS, and change the settings a theme offers in the Customizer, such as layouts, colours and header or footer options. Every change is staged in the theme draft, can be previewed, and goes live only when the draft is published.', 'mosmcp-abilities' ),
		);
	}

	/**
	 * The abilities.
	 *
	 * @return Ability[]
	 */
	public function abilities() {
		return array(
			$this->css_get(),
			$this->css_update(),
			$this->settings_list(),
			$this->settings_update(),
		);
	}

	/**
	 * Defines mosmcp/site-css-get.
	 *
	 * @return Ability
	 */
	private function css_get() {
		return $this->ability(
			'mosmcp/site-css-get',
			__( 'Get Additional CSS', 'mosmcp-abilities' ),
			__( 'Shows the site\'s Additional CSS — the custom CSS added on top of the theme — as it will be once the theme draft is published, next to what visitors get now. On a block theme this is the Site Editor\'s Additional CSS, and any older Customizer CSS the theme still prints is shown too. Read-only. Also known as: custom CSS, extra CSS, style overrides.', 'mosmcp-abilities' ),
			array( Customize_Provider::class, 'css_get' ),
			'edit_theme_options',
			Site_Support::annotations( true, false, true, false ),
			Schema::object(
				array(
					'target' => self::target_input(),
				)
			),
			Schema::object(
				array(
					'target'   => Schema::str( __( '"global_styles" (Site Editor) or "customizer".', 'mosmcp-abilities' ) ),
					'css'      => Schema::str( __( 'The CSS once the draft is published.', 'mosmcp-abilities' ) ),
					'bytes'    => Schema::int(),
					'pending'  => Schema::boolean( __( 'Whether the theme draft changes it.', 'mosmcp-abilities' ) ),
					'live_css' => Schema::str( __( 'The CSS visitors get now.', 'mosmcp-abilities' ) ),
					'other'    => Schema::str( __( 'Older Customizer CSS a block theme still prints, if any.', 'mosmcp-abilities' ) ),
					'notes'    => Schema::arr( Schema::str() ),
				),
				array( 'target', 'css' )
			)
		);
	}

	/**
	 * Defines mosmcp/site-css-update.
	 *
	 * @return Ability
	 */
	private function css_update() {
		return $this->ability(
			'mosmcp/site-css-update',
			__( 'Update Additional CSS', 'mosmcp-abilities' ),
			__( 'Stages a change to the site\'s Additional CSS in the theme draft: "append" adds rules at the end (the safe default), "edit" swaps one exact piece of the existing CSS for new text, and "replace" sets the whole CSS. CSS that would break the page — HTML inside it, or an unclosed rule that would swallow every rule after it — is refused. Visitors see nothing until the draft is published; preview it first with mosmcp/theme-draft-preview. Also known as: add custom CSS, change site CSS, style tweak.', 'mosmcp-abilities' ),
			array( Customize_Provider::class, 'css_update' ),
			// The capability core requires to edit Additional CSS.
			'edit_css',
			Site_Support::annotations( false, false, false, false ),
			Schema::object(
				array(
					'mode'         => Schema::str(
						__( '"append" to add CSS at the end, "edit" to change one exact piece, "replace" to set all of it.', 'mosmcp-abilities' ),
						array(
							'enum'    => array( 'append', 'edit', 'replace' ),
							'default' => 'append',
						)
					),
					'css'          => Schema::str( __( 'For append and replace: the CSS.', 'mosmcp-abilities' ) ),
					'find'         => Schema::str( __( 'For edit: the exact text to change, copied from mosmcp/site-css-get.', 'mosmcp-abilities' ) ),
					'replace_with' => Schema::str( __( 'For edit: the new text; empty to delete the found text.', 'mosmcp-abilities' ) ),
					'all'          => Schema::boolean( __( 'For edit: change every occurrence when the text appears more than once.', 'mosmcp-abilities' ) ),
					'target'       => self::target_input(),
				)
			),
			Block_Theme_Pack::staged_output(
				array(
					'target'  => Schema::str(),
					'css'     => Schema::str( __( 'The whole Additional CSS once the draft is published.', 'mosmcp-abilities' ) ),
					'bytes'   => Schema::int(),
					'matches' => Schema::int( __( 'For edit: how many times the found text appeared.', 'mosmcp-abilities' ) ),
				)
			)
		);
	}

	/**
	 * Defines mosmcp/theme-settings-list.
	 *
	 * @return Ability
	 */
	private function settings_list() {
		return $this->ability(
			'mosmcp/theme-settings-list',
			__( 'List Theme Settings', 'mosmcp-abilities' ),
			__( 'Lists the settings the active theme offers in the Customizer — layouts, widths, colours, typography, header and footer options and so on — with each one\'s ID, label, section, current value, default and allowed choices, and any value waiting in the theme draft. The theme\'s Customizer is loaded exactly as on Appearance > Customize, so themes that build their settings only there (Astra, for example) are covered. Use search or section to narrow the list. Read-only. Also known as: Customizer options, theme options, theme mods.', 'mosmcp-abilities' ),
			array( Customize_Provider::class, 'settings_list' ),
			'edit_theme_options',
			Site_Support::annotations( true, false, true, false ),
			Schema::object(
				array(
					'search'   => Schema::str( __( 'Only settings whose ID, label, description, section or panel contains this.', 'mosmcp-abilities' ) ),
					'section'  => Schema::str( __( 'Only settings in this section, by ID or title, from "sections".', 'mosmcp-abilities' ) ),
					'per_page' => Schema::int(
						'',
						array(
							'minimum' => 1,
							'maximum' => 100,
							'default' => 40,
						)
					),
					'offset'   => Schema::int( '', array( 'minimum' => 0 ) ),
				)
			),
			Schema::object(
				array(
					'theme'    => Schema::str(),
					'total'    => Schema::int( __( 'Settings matching the search, before paging.', 'mosmcp-abilities' ) ),
					'settings' => Schema::arr(
						Schema::object(
							array(
								'id'          => Schema::str( __( 'Pass this to mosmcp/theme-settings-update.', 'mosmcp-abilities' ) ),
								'label'       => Schema::str(),
								'description' => Schema::str(),
								'control'     => Schema::str( __( 'Kind of control in the Customizer, such as text, checkbox, select, radio, color or a theme\'s own.', 'mosmcp-abilities' ) ),
								'section'     => Schema::str(),
								'panel'       => Schema::str(),
								'type'        => Schema::str( __( '"theme_mod" or "option".', 'mosmcp-abilities' ) ),
								'value'       => self::value_schema( __( 'Current value on the live site.', 'mosmcp-abilities' ) ),
								'default'     => self::value_schema(),
								'choices'     => Schema::map( __( 'Allowed values => their labels, for select and radio controls.', 'mosmcp-abilities' ) ),
								'input_attrs' => Schema::map( __( 'Limits such as min, max and step.', 'mosmcp-abilities' ) ),
								'pending'     => Schema::boolean( __( 'Whether the theme draft changes it.', 'mosmcp-abilities' ) ),
								'new_value'   => self::value_schema( __( 'The value waiting in the theme draft.', 'mosmcp-abilities' ) ),
							)
						)
					),
					'sections' => Schema::arr(
						Schema::object(
							array(
								'id'       => Schema::str(),
								'title'    => Schema::str(),
								'panel'    => Schema::str(),
								'settings' => Schema::int(),
							)
						)
					),
					'notes'    => Schema::arr( Schema::str() ),
				),
				array( 'settings', 'sections' )
			)
		);
	}

	/**
	 * Defines mosmcp/theme-settings-update.
	 *
	 * @return Ability
	 */
	private function settings_update() {
		return $this->ability(
			'mosmcp/theme-settings-update',
			__( 'Update Theme Settings', 'mosmcp-abilities' ),
			__( 'Stages new values for settings the active theme offers in the Customizer, by ID from mosmcp/theme-settings-list. Each value is checked by the theme\'s own rules; if any is refused, nothing is staged. Visitors see nothing until the theme draft is published, which saves the values through the Customizer exactly as its Publish button does. Pass null to return a setting to the theme\'s default. Settings other abilities manage (logo, icon, title, CSS, menus, widgets, home page) are refused with the ability to use instead. Also known as: change theme options, Customizer settings, header layout, footer layout, container width.', 'mosmcp-abilities' ),
			array( Customize_Provider::class, 'settings_update' ),
			'edit_theme_options',
			Site_Support::annotations( false, false, true, false ),
			Schema::object(
				array(
					'settings' => Schema::map( __( 'Setting ID => new value, for example {"astra-settings[site-content-width]": 1100}.', 'mosmcp-abilities' ) ),
				),
				array( 'settings' )
			),
			Block_Theme_Pack::staged_output(
				array(
					'settings'  => Schema::arr(
						Schema::object(
							array(
								'id'    => Schema::str(),
								'label' => Schema::str(),
								'value' => self::value_schema( __( 'The value staged, after the theme\'s own sanitizing.', 'mosmcp-abilities' ) ),
							)
						)
					),
					'unchanged' => Schema::arr( Schema::str(), __( 'Settings already set that way on the live site.', 'mosmcp-abilities' ) ),
				)
			)
		);
	}

	/**
	 * Input choosing where Additional CSS lives.
	 *
	 * @return array<string, mixed>
	 */
	private static function target_input() {
		return Schema::str(
			__( '"auto" (the active theme\'s own place), "global_styles" (the Site Editor, block themes) or "customizer".', 'mosmcp-abilities' ),
			array(
				'enum'    => array( 'auto', 'global_styles', 'customizer' ),
				'default' => 'auto',
			)
		);
	}

	/**
	 * Schema of a Customizer value, which can be any JSON value.
	 *
	 * @param string $description Description.
	 * @return array<string, mixed>
	 */
	private static function value_schema( $description = '' ) {
		$schema = array( 'type' => array( 'string', 'number', 'integer', 'boolean', 'array', 'object', 'null' ) );
		if ( '' !== $description ) {
			$schema['description'] = $description;
		}
		return $schema;
	}

	/**
	 * Builds an ability with the pack's shared settings.
	 *
	 * @param string               $name        Name.
	 * @param string               $label       Label.
	 * @param string               $description Description.
	 * @param callable             $execute     Execute callback.
	 * @param string               $capability  Capability required.
	 * @param array<string, bool>  $annotations Annotations.
	 * @param array<string, mixed> $input       Input schema.
	 * @param array<string, mixed> $output      Output schema.
	 * @return Ability
	 */
	private function ability( $name, $label, $description, $execute, $capability, array $annotations, array $input, array $output ) {
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
}
