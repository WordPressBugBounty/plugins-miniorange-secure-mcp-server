<?php
/**
 * Widgets & Sidebars ability pack: widget areas, the widgets in them, and their settings.
 *
 * Covers classic widget areas (sidebars, footer columns) on themes that register
 * them, including block widgets. On a block theme there are no widget areas, and
 * every ability says so and points at the template-part abilities instead of
 * answering with an empty list.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Widgets;

use MoSMCP\Abilities\Ability;
use MoSMCP\Abilities\Ability_Pack;
use MoSMCP\Abilities\Packs\Core\Support\Core_Schema as Schema;
use MoSMCP\Abilities\Packs\Site\Site_Support;
use MoSMCP\Abilities\Support\Html_Policy;

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
 * Class Widgets_Pack
 *
 * Declares the widget abilities. Execute logic lives in Widgets_Provider.
 */
class Widgets_Pack extends Ability_Pack {

	/**
	 * Ability category slug.
	 */
	const CATEGORY = 'mosmcp-widgets';

	/**
	 * Capability every widget ability requires, the same one the Widgets screen requires.
	 */
	const CAPABILITY = 'edit_theme_options';

	/**
	 * Ability category for widget abilities.
	 *
	 * @return array Category definition.
	 */
	public function category() {
		return array(
			'slug'        => self::CATEGORY,
			'label'       => __( 'Widgets & Sidebars', 'mosmcp-abilities' ),
			'description' => __( 'List widget areas such as sidebars and footers, and add, change, reorder, park or delete the widgets in them, including block widgets.', 'mosmcp-abilities' ),
		);
	}

	/**
	 * The widget abilities.
	 *
	 * @return Ability[]
	 */
	public function abilities() {
		return array(
			$this->list_areas(),
			$this->list_widgets(),
			$this->get_widget(),
			$this->list_types(),
			$this->add_widget(),
			$this->update_widget(),
			$this->move_widget(),
			$this->delete_widget(),
		);
	}

	/**
	 * Defines the mosmcp/widget-list-areas ability.
	 *
	 * @return Ability
	 */
	private function list_areas() {
		return new Ability(
			'mosmcp/widget-list-areas',
			array(
				'label'         => __( 'List Widget Areas', 'mosmcp-abilities' ),
				'description'   => __( 'Lists the places the theme lets widgets go, such as sidebars and footer columns, with how many widgets each holds and their IDs, plus the inactive-widgets bin, whether the site uses the block-based widget editor, and whether the theme is a block theme. Read-only. Start here before adding or moving a widget, to get the area IDs; then use mosmcp/widget-list to see the widgets. A block theme has no widget areas: its header, footer and sidebar are template parts, and the answer explains this and names mosmcp/theme-part-list and mosmcp/theme-part-get instead. Also known as: widget areas, sidebars, footer widgets, sidebar list, where can I put a widget, widget locations.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => self::CAPABILITY,
				'annotations'   => Site_Support::annotations( true, false, true, false ),
				'execute'       => array( Widgets_Provider::class, 'list_areas' ),
				'input_schema'  => Schema::object( array() ),
				'output_schema' => Schema::object(
					array(
						'theme'                  => Schema::str(),
						'is_block_theme'         => Schema::boolean( __( 'True for a block theme, which has template parts instead of widget areas.', 'mosmcp-abilities' ) ),
						'theme_supports_widgets' => Schema::boolean(),
						'uses_block_widgets'     => Schema::boolean( __( 'Whether the block-based widget editor is in use. When true, any block can be placed in an area.', 'mosmcp-abilities' ) ),
						'has_widget_areas'       => Schema::boolean( __( 'False when the theme registers no widget areas; explanation then says what to use instead.', 'mosmcp-abilities' ) ),
						'areas'                  => Schema::arr(
							Schema::object(
								array(
									'id'           => Schema::str( __( 'Area ID to pass to the other widget abilities, for example "sidebar-1".', 'mosmcp-abilities' ) ),
									'name'         => Schema::str(),
									'description'  => Schema::str(),
									'widget_count' => Schema::int(),
									'widget_ids'   => Schema::arr( Schema::str(), __( 'Widgets in display order, top first.', 'mosmcp-abilities' ) ),
								)
							),
							__( 'Areas the active theme registers, whose widgets are shown on the site.', 'mosmcp-abilities' )
						),
						'inactive_widget_count'  => Schema::int( __( 'Widgets in the inactive-widgets bin: kept with their settings but not shown.', 'mosmcp-abilities' ) ),
						'inactive_widget_ids'    => Schema::arr( Schema::str() ),
						'unregistered_areas'     => Schema::arr(
							Schema::object(
								array(
									'id'           => Schema::str(),
									'widget_count' => Schema::int(),
									'widget_ids'   => Schema::arr( Schema::str() ),
								)
							),
							__( 'Stored areas the active theme does not register, usually left from a previous theme. Their widgets are not shown.', 'mosmcp-abilities' )
						),
						'explanation'            => Schema::str( __( 'Why there are no widget areas and what to use instead. Empty when the theme has widget areas.', 'mosmcp-abilities' ) ),
						'use_instead'            => Schema::arr( Schema::str(), __( 'Abilities to use instead when the theme has no widget areas.', 'mosmcp-abilities' ) ),
						'notes'                  => Schema::arr( Schema::str() ),
					),
					array( 'has_widget_areas', 'areas', 'inactive_widget_count', 'uses_block_widgets', 'is_block_theme' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/widget-list ability.
	 *
	 * @return Ability
	 */
	private function list_widgets() {
		return new Ability(
			'mosmcp/widget-list',
			array(
				'label'         => __( 'List Widgets', 'mosmcp-abilities' ),
				'description'   => __( 'Lists the widgets in one widget area, or in all of them, in display order: widget ID (such as "block-3" or "text-2"), area, position (0 is the top), type, title, and a one-line summary of what it shows. Block widgets are summarised by their block types and text, classic widgets by their key settings, and values that look like passwords, API keys or tokens are never shown. Read-only. Also lists the inactive-widgets bin, and widgets left in areas the current theme no longer registers, each marked so it is clear they are not on the site. Next: mosmcp/widget-get for one widget\'s full settings, mosmcp/widget-update or mosmcp/widget-move to change it. Also known as: sidebar widgets, footer widgets, list widgets, what is in my sidebar, what widgets are on my site.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => self::CAPABILITY,
				'annotations'   => Site_Support::annotations( true, false, true, false ),
				'execute'       => array( Widgets_Provider::class, 'list_widgets' ),
				'input_schema'  => Schema::object(
					array(
						'area'             => Schema::str( __( 'Optional. Only widgets in this area: its ID ("sidebar-1"), its name ("Footer"), or "wp_inactive_widgets" for the inactive bin. Omit for every area.', 'mosmcp-abilities' ) ),
						'type'             => Schema::str( __( 'Optional. Only widgets of this type, by id_base, for example "block", "text" or "custom_html".', 'mosmcp-abilities' ) ),
						'include_inactive' => Schema::boolean(
							__( 'When listing every area, whether to include the inactive-widgets bin.', 'mosmcp-abilities' ),
							array( 'default' => true )
						),
					)
				),
				'output_schema' => Schema::object(
					array(
						'widgets'            => Schema::arr( self::summary_schema() ),
						'total'              => Schema::int(),
						'area'               => Schema::str( __( 'The area listed, or empty when every area was listed.', 'mosmcp-abilities' ) ),
						'has_widget_areas'   => Schema::boolean(),
						'uses_block_widgets' => Schema::boolean(),
						'is_block_theme'     => Schema::boolean(),
						'explanation'        => Schema::str( __( 'Why there are no widget areas and what to use instead. Empty when the theme has widget areas.', 'mosmcp-abilities' ) ),
						'use_instead'        => Schema::arr( Schema::str() ),
						'notes'              => Schema::arr( Schema::str() ),
					),
					array( 'widgets', 'total', 'has_widget_areas' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/widget-get ability.
	 *
	 * @return Ability
	 */
	private function get_widget() {
		return new Ability(
			'mosmcp/widget-get',
			array(
				'label'         => __( 'Get Widget', 'mosmcp-abilities' ),
				'description'   => __( 'Returns one widget in full: all its settings or, for a block widget, its complete block markup, with its area and position. Settings that look like passwords, API keys or tokens are shown masked; sending the mask back unchanged in mosmcp/widget-update keeps the stored value. Can also return the HTML the widget outputs on the site. Read-only. Read a widget with this before changing it with mosmcp/widget-update. Also known as: widget settings, widget content, show widget, custom html widget content, text widget content, footer widget text.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => self::CAPABILITY,
				'annotations'   => Site_Support::annotations( true, false, true, false ),
				'execute'       => array( Widgets_Provider::class, 'get_widget' ),
				'input_schema'  => Schema::object(
					array(
						'widget_id'        => Schema::str( __( 'The widget ID, such as "block-3" or "text-2", as mosmcp/widget-list reports it.', 'mosmcp-abilities' ) ),
						'include_rendered' => Schema::boolean(
							__( 'Also return the HTML the widget outputs on the site.', 'mosmcp-abilities' ),
							array( 'default' => false )
						),
					),
					array( 'widget_id' )
				),
				'output_schema' => Schema::object(
					array_merge(
						self::detail_properties(),
						array(
							'rendered_html' => Schema::str( __( 'The widget\'s front-end HTML, when asked for and the widget is in a shown area. Empty otherwise.', 'mosmcp-abilities' ) ),
							'notes'         => Schema::arr( Schema::str() ),
						)
					),
					array( 'widget_id', 'id_base', 'area', 'position', 'settings', 'block_markup' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/widget-list-types ability.
	 *
	 * @return Ability
	 */
	private function list_types() {
		return new Ability(
			'mosmcp/widget-list-types',
			array(
				'label'         => __( 'List Widget Types', 'mosmcp-abilities' ),
				'description'   => __( 'Lists the kinds of widget that can be added on this site, both WordPress\'s own (Search, Recent Posts, Custom HTML, Text, Navigation Menu and so on) and any added by plugins, with each type\'s settings and their defaults, read from the widget\'s own settings form, so it is clear what to pass to mosmcp/widget-add. Marks the types the block-based widget editor hides because a block does the same job, and names that block. When the site uses block widgets, any block can also be added as a widget by passing block markup to mosmcp/widget-add instead of a type. Read-only. Also known as: widget types, available widgets, what widgets can I add, widget catalog.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => self::CAPABILITY,
				'annotations'   => Site_Support::annotations( true, false, true, false ),
				'execute'       => array( Widgets_Provider::class, 'list_types' ),
				'input_schema'  => Schema::object(
					array(
						'search'           => Schema::str( __( 'Optional. Only types whose ID, name or description contains this.', 'mosmcp-abilities' ) ),
						'include_settings' => Schema::boolean(
							__( 'Whether to include each type\'s settings and defaults.', 'mosmcp-abilities' ),
							array( 'default' => true )
						),
					)
				),
				'output_schema' => Schema::object(
					array(
						'types'              => Schema::arr(
							Schema::object(
								array(
									'id_base'           => Schema::str( __( 'The type to pass to mosmcp/widget-add.', 'mosmcp-abilities' ) ),
									'name'              => Schema::str(),
									'description'       => Schema::str(),
									'settings_editable' => Schema::boolean( __( 'False for widgets built without the standard widget API, whose settings cannot be set here.', 'mosmcp-abilities' ) ),
									'hidden_in_block_editor' => Schema::boolean( __( 'True when the block-based widget editor does not offer this type because a block does the same job. It still works.', 'mosmcp-abilities' ) ),
									'block_equivalent'  => Schema::str( __( 'The block that does the same job, if any.', 'mosmcp-abilities' ) ),
									'in_use_count'      => Schema::int( __( 'How many widgets of this type are in shown areas.', 'mosmcp-abilities' ) ),
									'settings'          => Schema::arr(
										Schema::object(
											array(
												'name'    => Schema::str(),
												'kind'    => Schema::str( __( 'The form control: text, textarea, checkbox, select, number, hidden, radio and so on.', 'mosmcp-abilities' ) ),
												'default' => Schema::str( __( 'Value used when the setting is left out. For a checkbox, empty means unchecked.', 'mosmcp-abilities' ) ),
												'options' => Schema::arr( Schema::str(), __( 'Allowed values, for a select or radio setting.', 'mosmcp-abilities' ) ),
											)
										)
									),
								)
							)
						),
						'total'              => Schema::int(),
						'uses_block_widgets' => Schema::boolean(),
						'has_widget_areas'   => Schema::boolean(),
						'notes'              => Schema::arr( Schema::str() ),
					),
					array( 'types', 'total', 'uses_block_widgets' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/widget-add ability.
	 *
	 * @return Ability
	 */
	private function add_widget() {
		return new Ability(
			'mosmcp/widget-add',
			array(
				'label'         => __( 'Add Widget', 'mosmcp-abilities' ),
				'description'   => __( 'Adds a widget to a widget area, at an optional position (0 is the top; leave it out to add at the bottom). Give either block_markup, saved as a block widget and checked first to contain at least one block this site has, or a classic widget type from mosmcp/widget-list-types with its settings; settings left out take the defaults the widget\'s own form uses. The widget\'s own validation runs exactly as on the Widgets screen, and the result is read back, so the answer reports anything WordPress changed while saving. The new widget shows on every page that displays the area. Refused with an explanation on a block theme, which has no widget areas. Also known as: add a widget, add to sidebar, add to footer, new widget, custom html widget, put a widget in the sidebar.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => self::CAPABILITY,
				'annotations'   => Site_Support::annotations( false, false, false, false ),
				'execute'       => array( Widgets_Provider::class, 'add_widget' ),
				'input_schema'  => Schema::object(
					array(
						'area'         => Schema::str( __( 'The area to add to: its ID ("sidebar-1") or name ("Footer"), from mosmcp/widget-list-areas.', 'mosmcp-abilities' ) ),
						'position'     => Schema::int(
							__( 'Optional 0-based position in the area: 0 puts it at the top. Leave out to add it at the bottom.', 'mosmcp-abilities' ),
							array( 'minimum' => 0 )
						),
						'block_markup' => Schema::str( __( 'Block markup for a block widget, for example <!-- wp:heading --><h2>Opening hours</h2><!-- /wp:heading --><!-- wp:paragraph --><p>Monday to Friday, 9 to 5.</p><!-- /wp:paragraph -->. Leave out when adding a classic widget type.', 'mosmcp-abilities' ) . ' ' . Html_Policy::rule_summary() ),
						'type'         => Schema::str( __( 'A classic widget type by id_base, such as "custom_html", "text", "recent-posts" or "nav_menu", from mosmcp/widget-list-types. Leave out when passing block_markup.', 'mosmcp-abilities' ) ),
						'settings'     => Schema::map( __( 'Settings for a classic widget type, by name, for example {"title": "Latest news", "number": 5}. mosmcp/widget-list-types lists each type\'s settings. HTML in a Custom HTML or Text widget follows the same rule as block_markup.', 'mosmcp-abilities' ) ),
					),
					array( 'area' )
				),
				'output_schema' => self::write_output(),
			)
		);
	}

	/**
	 * Defines the mosmcp/widget-update ability.
	 *
	 * @return Ability
	 */
	private function update_widget() {
		return new Ability(
			'mosmcp/widget-update',
			array(
				'label'         => __( 'Update Widget', 'mosmcp-abilities' ),
				'description'   => __( 'Changes a widget\'s settings or, for a block widget, its block markup. Settings are merged into the ones it has, so anything not mentioned keeps its value; set replace to true to reset everything not mentioned to the widget\'s defaults instead. Saved through the widget\'s own validation, exactly as on the Widgets screen, and read back: the answer lists which settings changed and anything WordPress adjusted on the way in. A masked secret sent back unchanged keeps the stored value. Does not move the widget; use mosmcp/widget-move for that. Read the widget with mosmcp/widget-get first. Also known as: edit widget, change widget text, change widget title, update sidebar widget, edit custom html widget, edit footer widget.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => self::CAPABILITY,
				'annotations'   => Site_Support::annotations( false, false, true, false ),
				'execute'       => array( Widgets_Provider::class, 'update_widget' ),
				'input_schema'  => Schema::object(
					array(
						'widget_id'    => Schema::str( __( 'The widget ID, such as "text-2" or "block-3", as mosmcp/widget-list reports it.', 'mosmcp-abilities' ) ),
						'settings'     => Schema::map( __( 'Settings to change, by name, for example {"title": "Contact us"}. Merged into the existing settings unless replace is true. Not used for block widgets, which take block_markup.', 'mosmcp-abilities' ) ),
						'block_markup' => Schema::str( __( 'The complete new block markup, for a block widget only. It replaces the widget\'s markup.', 'mosmcp-abilities' ) . ' ' . Html_Policy::rule_summary() ),
						'replace'      => Schema::boolean(
							__( 'When true, settings not mentioned go back to the widget\'s defaults instead of keeping their values.', 'mosmcp-abilities' ),
							array( 'default' => false )
						),
					),
					array( 'widget_id' )
				),
				'output_schema' => self::write_output(
					array(
						'changed'          => Schema::boolean( __( 'False when the widget already had these settings.', 'mosmcp-abilities' ) ),
						'changed_settings' => Schema::arr( Schema::str(), __( 'Names of the settings whose stored values changed.', 'mosmcp-abilities' ) ),
					),
					array( 'changed' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/widget-move ability.
	 *
	 * @return Ability
	 */
	private function move_widget() {
		return new Ability(
			'mosmcp/widget-move',
			array(
				'label'         => __( 'Move Widget', 'mosmcp-abilities' ),
				'description'   => __( 'Moves a widget to another widget area, to a different position in its area (0 is the top), or both. Moving it to the inactive-widgets bin, area "wp_inactive_widgets", takes it off the site while keeping every setting, and moving it back restores it exactly: this is the safe, reversible way to remove a widget, and the answer says how to undo it. The widget\'s settings are not changed. Also known as: reorder widgets, move widget to footer, remove widget from sidebar, hide widget, deactivate widget, put a widget back, restore widget.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => self::CAPABILITY,
				'annotations'   => Site_Support::annotations( false, false, true, false ),
				'execute'       => array( Widgets_Provider::class, 'move_widget' ),
				'input_schema'  => Schema::object(
					array(
						'widget_id' => Schema::str( __( 'The widget ID, such as "block-3", as mosmcp/widget-list reports it.', 'mosmcp-abilities' ) ),
						'area'      => Schema::str( __( 'Area to move it to: an ID ("footer-1"), a name ("Footer"), or "wp_inactive_widgets" to take it off the site but keep it. Leave out to reorder within its current area.', 'mosmcp-abilities' ) ),
						'position'  => Schema::int(
							__( 'Optional 0-based position in the destination area: 0 puts it at the top. Leave out to put it at the bottom of a new area, or to keep its place when only the area matters.', 'mosmcp-abilities' ),
							array( 'minimum' => 0 )
						),
					),
					array( 'widget_id' )
				),
				'output_schema' => self::write_output(
					array(
						'previous_area'     => Schema::str(),
						'previous_position' => Schema::int(),
						'changed'           => Schema::boolean( __( 'False when the widget was already where it was asked to go.', 'mosmcp-abilities' ) ),
					),
					array( 'previous_area', 'previous_position', 'changed' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/widget-delete ability.
	 *
	 * @return Ability
	 */
	private function delete_widget() {
		return new Ability(
			'mosmcp/widget-delete',
			array(
				'label'         => __( 'Delete Widget', 'mosmcp-abilities' ),
				'description'   => __( 'Permanently deletes a widget and its settings. This cannot be undone, so it needs confirm set to true. To take a widget off the site but keep it, use mosmcp/widget-move with area "wp_inactive_widgets" instead, which is reversible. The answer includes the deleted widget\'s settings or block markup, so it could be recreated with mosmcp/widget-add. Also known as: delete widget, remove widget permanently, delete sidebar widget, delete footer widget.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => self::CAPABILITY,
				'annotations'   => Site_Support::annotations( false, true, true, false ),
				'execute'       => array( Widgets_Provider::class, 'delete_widget' ),
				'input_schema'  => Schema::object(
					array(
						'widget_id' => Schema::str( __( 'The widget ID to delete, such as "text-2", as mosmcp/widget-list reports it.', 'mosmcp-abilities' ) ),
						'confirm'   => Schema::boolean(
							__( 'Must be true. The widget and its settings are gone for good; moving it to "wp_inactive_widgets" with mosmcp/widget-move is the reversible alternative.', 'mosmcp-abilities' ),
							array( 'default' => false )
						),
					),
					// `confirm` is deliberately NOT required here. Marking it required makes
					// the schema reject the call before the ability runs, so the caller gets
					// a generic "confirm is required" instead of the refusal naming the widget
					// about to be deleted and the reversible alternative. Optional here,
					// enforced in the provider, is what surfaces that explanation.
					array( 'widget_id' )
				),
				'output_schema' => Schema::object(
					array(
						'widget_id'         => Schema::str(),
						'deleted'           => Schema::boolean(),
						'previous_area'     => Schema::str(),
						'previous_position' => Schema::int(),
						'widget'            => Schema::object( self::detail_properties(), array( 'widget_id', 'id_base' ) ),
						'warnings'          => Schema::warnings(),
						'notes'             => Schema::arr( Schema::str() ),
					),
					array( 'widget_id', 'deleted', 'previous_area', 'previous_position' )
				),
			)
		);
	}

	/**
	 * Properties of a widget as every listing returns it.
	 *
	 * @return array<string, mixed>
	 */
	private static function summary_properties() {
		return array(
			'widget_id'         => Schema::str( __( 'The widget ID, for example "block-3" or "text-2".', 'mosmcp-abilities' ) ),
			'id_base'           => Schema::str( __( 'The widget type, for example "block", "text" or "custom_html".', 'mosmcp-abilities' ) ),
			'type_name'         => Schema::str(),
			'area'              => Schema::str( __( 'Area ID the widget is in.', 'mosmcp-abilities' ) ),
			'area_name'         => Schema::str(),
			'area_status'       => Schema::str(
				__( '"active" when the area is shown on the site, "inactive" for the inactive-widgets bin, "unregistered" for an area the active theme does not register.', 'mosmcp-abilities' ),
				array( 'enum' => array( 'active', 'inactive', 'unregistered' ) )
			),
			'position'          => Schema::int( __( '0-based position in its area; 0 is the top.', 'mosmcp-abilities' ) ),
			'title'             => Schema::str(),
			'summary'           => Schema::str( __( 'One line describing what the widget shows. Secrets are never included.', 'mosmcp-abilities' ) ),
			'is_block_widget'   => Schema::boolean(),
			'blocks'            => Schema::arr( Schema::str(), __( 'Block types in a block widget.', 'mosmcp-abilities' ) ),
			'settings_editable' => Schema::boolean( __( 'False when the widget\'s type is missing or not built with the standard widget API; it can still be moved or deleted.', 'mosmcp-abilities' ) ),
		);
	}

	/**
	 * Schema of a widget as every listing returns it.
	 *
	 * @return array<string, mixed>
	 */
	private static function summary_schema() {
		return Schema::object( self::summary_properties(), array( 'widget_id', 'id_base', 'area', 'position' ) );
	}

	/**
	 * Properties of a widget returned in full.
	 *
	 * @return array<string, mixed>
	 */
	private static function detail_properties() {
		return array_merge(
			self::summary_properties(),
			array(
				'settings'        => Schema::map( __( 'All the widget\'s settings, with secret-looking values masked. For a block widget, its content setting holds the block markup.', 'mosmcp-abilities' ) ),
				'masked_settings' => Schema::arr( Schema::str(), __( 'Names of settings whose values are masked.', 'mosmcp-abilities' ) ),
				'block_markup'    => Schema::str( __( 'The block markup of a block widget. Empty for other widgets.', 'mosmcp-abilities' ) ),
			)
		);
	}

	/**
	 * Output schema shared by the writing abilities.
	 *
	 * @param array<string, mixed> $extra          Extra properties.
	 * @param string[]             $extra_required Extra required properties.
	 * @return array<string, mixed>
	 */
	private static function write_output( array $extra = array(), array $extra_required = array() ) {
		return Schema::object(
			array_merge(
				array(
					'widget_id'    => Schema::str(),
					'area'         => Schema::str( __( 'Area the widget is in now.', 'mosmcp-abilities' ) ),
					'position'     => Schema::int( __( '0-based position in that area now.', 'mosmcp-abilities' ) ),
					'widget'       => Schema::object( self::detail_properties(), array( 'widget_id', 'id_base' ) ),
					'area_widgets' => Schema::arr( Schema::str(), __( 'Every widget in that area, in display order.', 'mosmcp-abilities' ) ),
					'warnings'     => Schema::warnings(),
					'notes'        => Schema::arr( Schema::str() ),
				),
				$extra
			),
			array_merge( array( 'widget_id', 'area', 'position', 'widget', 'warnings' ), $extra_required )
		);
	}
}
