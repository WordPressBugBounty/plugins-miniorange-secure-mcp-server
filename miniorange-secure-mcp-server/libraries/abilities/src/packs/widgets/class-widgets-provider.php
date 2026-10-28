<?php
/**
 * Execute callbacks for the Widgets & Sidebars ability pack.
 *
 * Writes go through each widget's own update() method and the core
 * widget_update_callback filter, the same path WP_Widget::update_callback() takes
 * when the Widgets screen saves, so every widget sanitizes its own settings. They
 * deliberately do not go through the REST widget routes: those run
 * retrieve_widgets() first, which rewrites the stored widget areas as a side
 * effect. Every write is read back before it is reported.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Widgets;

use MoSMCP\Abilities\Packs\Site\Site_Support;
use MoSMCP\Abilities\Support\Cache_Purger;
use MoSMCP\Abilities\Support\Html_Policy;
use WP_Error;

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
 * Class Widgets_Provider
 *
 * Static execute callbacks for the widget abilities.
 */
class Widgets_Provider {

	/**
	 * Lists the widget areas.
	 *
	 * @param array<string, mixed> $input Ability input. Unused.
	 * @return array<string, mixed>
	 */
	public static function list_areas( $input = array() ) {
		unset( $input );

		$state    = Widgets_Support::theme_state();
		$areas    = Widgets_Support::registered_areas();
		$sidebars = Widgets_Support::sidebars();
		$rows     = array();

		foreach ( $areas as $id => $area ) {
			$ids    = isset( $sidebars[ $id ] ) ? $sidebars[ $id ] : array();
			$rows[] = array(
				'id'           => (string) $id,
				'name'         => $area['name'],
				'description'  => $area['description'],
				'widget_count' => count( $ids ),
				'widget_ids'   => $ids,
			);
		}

		$inactive   = isset( $sidebars[ Widgets_Support::INACTIVE ] ) ? $sidebars[ Widgets_Support::INACTIVE ] : array();
		$leftover   = array();
		$left_count = 0;

		foreach ( $sidebars as $id => $ids ) {
			if ( isset( $areas[ $id ] ) || Widgets_Support::INACTIVE === $id || empty( $ids ) ) {
				continue;
			}

			$leftover[]  = array(
				'id'           => (string) $id,
				'widget_count' => count( $ids ),
				'widget_ids'   => $ids,
			);
			$left_count += count( $ids );
		}

		$notes = array();

		if ( $state['has_widget_areas'] ) {
			$notes[] = $state['uses_block_widgets']
				? __( 'This site uses the block-based widget editor, so any block can be placed in an area: pass block markup to mosmcp/widget-add. Classic widget types work too.', 'mosmcp-abilities' )
				: __( 'This site uses the classic widget editor (block-based widgets are switched off, usually by the Classic Widgets plugin). Classic widget types are the normal choice; block markup still works and is added as a Block widget.', 'mosmcp-abilities' );
		}

		if ( ! empty( $inactive ) ) {
			$notes[] = sprintf(
				/* translators: %d: number of widgets. */
				_n(
					'%d widget is in the inactive-widgets bin: not shown on the site, but kept with its settings. mosmcp/widget-move can put it back in an area.',
					'%d widgets are in the inactive-widgets bin: not shown on the site, but kept with their settings. mosmcp/widget-move can put one back in an area.',
					count( $inactive ),
					'mosmcp-abilities'
				),
				count( $inactive )
			);
		}

		if ( $left_count > 0 ) {
			$notes[] = sprintf(
				/* translators: 1: number of widgets, 2: comma-separated area IDs. */
				_n(
					'%1$d widget is stored in an area the active theme does not register (%2$s), usually left over from a previous theme. It is not shown on the site, and comes back if a theme with that area is activated. mosmcp/widget-list shows it; mosmcp/widget-move can place it in a current area or the inactive bin, and mosmcp/widget-delete removes it.',
					'%1$d widgets are stored in areas the active theme does not register (%2$s), usually left over from a previous theme. They are not shown on the site, and come back if a theme with those areas is activated. mosmcp/widget-list shows them; mosmcp/widget-move can place one in a current area or the inactive bin, and mosmcp/widget-delete removes one.',
					$left_count,
					'mosmcp-abilities'
				),
				$left_count,
				implode( ', ', wp_list_pluck( $leftover, 'id' ) )
			);
		}

		$no_areas = ! $state['has_widget_areas'];

		return array(
			'theme'                  => $state['theme'],
			'is_block_theme'         => $state['is_block_theme'],
			'theme_supports_widgets' => $state['supports_widgets'],
			'uses_block_widgets'     => $state['uses_block_widgets'],
			'has_widget_areas'       => $state['has_widget_areas'],
			'areas'                  => $rows,
			'inactive_widget_count'  => count( $inactive ),
			'inactive_widget_ids'    => $inactive,
			'unregistered_areas'     => $leftover,
			'explanation'            => $no_areas ? Widgets_Support::no_areas_explanation() : '',
			'use_instead'            => ( $no_areas && $state['is_block_theme'] ) ? array( 'mosmcp/theme-part-list', 'mosmcp/theme-part-get' ) : array(),
			'notes'                  => $notes,
		);
	}

	/**
	 * Lists placed widgets.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function list_widgets( $input = array() ) {
		$input  = is_array( $input ) ? $input : array();
		$state  = Widgets_Support::theme_state();
		$filter = '';

		if ( isset( $input['area'] ) && '' !== trim( (string) $input['area'] ) ) {
			$filter = Widgets_Support::resolve_area( (string) $input['area'], true );

			if ( is_wp_error( $filter ) ) {
				return $filter;
			}
		}

		$type             = isset( $input['type'] ) ? trim( (string) $input['type'] ) : '';
		$include_inactive = Site_Support::bool_input( $input, 'include_inactive', true );
		$sidebars         = Widgets_Support::sidebars();
		$registered       = Widgets_Support::registered_areas();

		// Shown areas in the theme's order first, then the bin, then left-over areas.
		$order = array_keys( $registered );
		if ( isset( $sidebars[ Widgets_Support::INACTIVE ] ) ) {
			$order[] = Widgets_Support::INACTIVE;
		}
		foreach ( array_keys( $sidebars ) as $id ) {
			if ( ! in_array( (string) $id, $order, true ) ) {
				$order[] = (string) $id;
			}
		}

		$rows          = array();
		$masked_any    = false;
		$from_leftover = 0;

		foreach ( $order as $area ) {
			if ( '' !== $filter && $area !== $filter ) {
				continue;
			}

			if ( '' === $filter && ! $include_inactive && Widgets_Support::INACTIVE === $area ) {
				continue;
			}

			$ids = isset( $sidebars[ $area ] ) ? $sidebars[ $area ] : array();

			foreach ( $ids as $position => $widget_id ) {
				$parsed = wp_parse_widget_id( $widget_id );

				if ( '' !== $type && $parsed['id_base'] !== $type ) {
					continue;
				}

				$row = Widgets_Support::describe( $widget_id, $area, $position, false );

				if ( Widgets_Support::STATUS_UNREGISTERED === $row['area_status'] ) {
					++$from_leftover;
				}

				$masked = array();
				$stored = Widgets_Support::stored_instance( $widget_id );
				if ( is_array( $stored ) ) {
					Widgets_Support::mask( $stored, $masked );
					$masked_any = $masked_any || ! empty( $masked );
				}

				$rows[] = $row;
			}
		}

		$notes = array();

		if ( empty( $rows ) && $state['has_widget_areas'] ) {
			$notes[] = '' !== $filter
				? __( 'No widgets are placed in this area yet. mosmcp/widget-add adds one.', 'mosmcp-abilities' )
				: __( 'No widgets are placed anywhere yet. mosmcp/widget-list-areas shows where widgets can go and mosmcp/widget-add adds one.', 'mosmcp-abilities' );
		}

		if ( $from_leftover > 0 ) {
			$notes[] = __( 'Widgets whose area_status is "unregistered" are stored for an area the active theme does not register, usually left from a previous theme. They are not shown on the site.', 'mosmcp-abilities' );
		}

		if ( $masked_any ) {
			$notes[] = __( 'Settings that look like passwords, API keys or tokens are masked and left out of the summaries.', 'mosmcp-abilities' );
		}

		$no_areas = ! $state['has_widget_areas'];

		return array(
			'widgets'            => $rows,
			'total'              => count( $rows ),
			'area'               => $filter,
			'has_widget_areas'   => $state['has_widget_areas'],
			'uses_block_widgets' => $state['uses_block_widgets'],
			'is_block_theme'     => $state['is_block_theme'],
			'explanation'        => $no_areas ? Widgets_Support::no_areas_explanation() : '',
			'use_instead'        => ( $no_areas && $state['is_block_theme'] ) ? array( 'mosmcp/theme-part-list', 'mosmcp/theme-part-get' ) : array(),
			'notes'              => $notes,
		);
	}

	/**
	 * Returns one widget in full.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function get_widget( $input = array() ) {
		$input     = is_array( $input ) ? $input : array();
		$widget_id = isset( $input['widget_id'] ) ? trim( (string) $input['widget_id'] ) : '';
		$location  = Widgets_Support::find( $widget_id );

		if ( is_wp_error( $location ) ) {
			return $location;
		}

		list( $area, $position ) = $location;

		$detail = Widgets_Support::describe( $widget_id, $area, $position, true );
		$notes  = array();

		if ( ! empty( $detail['masked_settings'] ) ) {
			$notes[] = sprintf(
				/* translators: 1: comma-separated setting names, 2: the mask placeholder. */
				__( 'These settings look like secrets and are shown as %2$s: %1$s. Sending %2$s back unchanged in mosmcp/widget-update keeps the stored value.', 'mosmcp-abilities' ),
				implode( ', ', $detail['masked_settings'] ),
				Widgets_Support::MASK
			);
		}

		if ( Widgets_Support::STATUS_ACTIVE !== $detail['area_status'] ) {
			$notes[] = Widgets_Support::STATUS_INACTIVE === $detail['area_status']
				? __( 'This widget is in the inactive-widgets bin, so it is not shown on the site. mosmcp/widget-move puts it back in an area.', 'mosmcp-abilities' )
				: __( 'This widget is stored for an area the active theme does not register, so it is not shown on the site.', 'mosmcp-abilities' );
		}

		if ( ! $detail['settings_editable'] ) {
			$notes[] = __( 'This widget\'s type is not registered on this site, or does not use the standard widget API, so its settings cannot be changed here. It can still be moved or deleted.', 'mosmcp-abilities' );
		}

		$rendered = '';
		if ( Site_Support::bool_input( $input, 'include_rendered', false ) && Widgets_Support::STATUS_ACTIVE === $detail['area_status'] ) {
			$rendered = Widgets_Support::render( $widget_id, $area, $notes );
		}

		$detail['rendered_html'] = $rendered;
		$detail['notes']         = $notes;

		return $detail;
	}

	/**
	 * Lists the widget types that can be added.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public static function list_types( $input = array() ) {
		global $wp_widget_factory, $wp_registered_widgets;

		$input            = is_array( $input ) ? $input : array();
		$search           = isset( $input['search'] ) ? strtolower( trim( (string) $input['search'] ) ) : '';
		$include_settings = Site_Support::bool_input( $input, 'include_settings', true );
		$state            = Widgets_Support::theme_state();

		$hidden = array();
		if ( function_exists( 'get_legacy_widget_block_editor_settings' ) ) {
			$editor = get_legacy_widget_block_editor_settings();
			$hidden = isset( $editor['widgetTypesToHideFromLegacyWidgetBlock'] ) && is_array( $editor['widgetTypesToHideFromLegacyWidgetBlock'] )
				? array_map( 'strval', $editor['widgetTypesToHideFromLegacyWidgetBlock'] )
				: array();
		}

		// How many of each type are placed where they are shown.
		$in_use   = array();
		$sidebars = Widgets_Support::sidebars();
		foreach ( array_keys( Widgets_Support::registered_areas() ) as $area ) {
			foreach ( isset( $sidebars[ $area ] ) ? $sidebars[ $area ] : array() as $widget_id ) {
				$parsed                       = wp_parse_widget_id( $widget_id );
				$in_use[ $parsed['id_base'] ] = ( isset( $in_use[ $parsed['id_base'] ] ) ? $in_use[ $parsed['id_base'] ] : 0 ) + 1;
			}
		}

		$types = array();
		$seen  = array();

		$objects = ( is_object( $wp_widget_factory ) && isset( $wp_widget_factory->widgets ) && is_array( $wp_widget_factory->widgets ) ) ? $wp_widget_factory->widgets : array();

		foreach ( $objects as $widget ) {
			if ( ! $widget instanceof \WP_Widget ) {
				continue;
			}

			$id_base = (string) $widget->id_base;
			$name    = (string) $widget->name;
			$about   = isset( $widget->widget_options['description'] ) ? html_entity_decode( wp_strip_all_tags( (string) $widget->widget_options['description'] ), ENT_QUOTES, 'UTF-8' ) : '';

			$seen[ $id_base ] = true;

			if ( '' !== $search && false === strpos( strtolower( $id_base . ' ' . $name . ' ' . $about ), $search ) ) {
				continue;
			}

			$types[] = array(
				'id_base'                => $id_base,
				'name'                   => $name,
				'description'            => $about,
				'settings_editable'      => true,
				// The Block widget is what the block editor itself saves, so it is never "hidden" from it.
				'hidden_in_block_editor' => 'block' !== $id_base && in_array( $id_base, $hidden, true ),
				'block_equivalent'       => isset( Widgets_Support::BLOCK_EQUIVALENTS[ $id_base ] ) ? Widgets_Support::BLOCK_EQUIVALENTS[ $id_base ] : '',
				'in_use_count'           => isset( $in_use[ $id_base ] ) ? $in_use[ $id_base ] : 0,
				'settings'               => $include_settings ? Widgets_Support::public_fields( Widgets_Support::form_fields( $widget, array() ) ) : array(),
			);
		}

		// Widgets registered the pre-2.8 way, without a WP_Widget class.
		foreach ( is_array( $wp_registered_widgets ) ? $wp_registered_widgets : array() as $id => $registered ) {
			$callback = isset( $registered['callback'] ) ? $registered['callback'] : null;

			if ( is_array( $callback ) && isset( $callback[0] ) && $callback[0] instanceof \WP_Widget ) {
				continue;
			}

			$id = (string) $id;
			if ( isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;

			$name  = isset( $registered['name'] ) ? (string) $registered['name'] : $id;
			$about = isset( $registered['description'] ) ? html_entity_decode( wp_strip_all_tags( (string) $registered['description'] ), ENT_QUOTES, 'UTF-8' ) : '';

			if ( '' !== $search && false === strpos( strtolower( $id . ' ' . $name . ' ' . $about ), $search ) ) {
				continue;
			}

			$types[] = array(
				'id_base'                => $id,
				'name'                   => $name,
				'description'            => $about,
				'settings_editable'      => false,
				'hidden_in_block_editor' => in_array( $id, $hidden, true ),
				'block_equivalent'       => '',
				'in_use_count'           => isset( $in_use[ $id ] ) ? $in_use[ $id ] : 0,
				'settings'               => array(),
			);
		}

		usort(
			$types,
			static function ( $a, $b ) {
				return strcasecmp( $a['name'], $b['name'] );
			}
		);

		$notes = array();

		if ( ! $state['has_widget_areas'] ) {
			$notes[] = Widgets_Support::no_areas_explanation();
		} else {
			$notes[] = $state['uses_block_widgets']
				? __( 'This site uses block-based widgets, so any block can be added as a widget: pass block markup to mosmcp/widget-add instead of a type. Types marked hidden_in_block_editor are not offered by the block widget editor because a block does the same job (block_equivalent names it); they still work and can still be added here.', 'mosmcp-abilities' )
				: __( 'Block-based widgets are switched off on this site (classic widget editor), so classic types are the normal choice. Block markup can still be added and is saved as a Block widget.', 'mosmcp-abilities' );
		}

		if ( $include_settings ) {
			$notes[] = __( 'Each type\'s settings are read from its own settings form. Pass them to mosmcp/widget-add as settings; any left out take the default shown. A checkbox with an empty default is unchecked; pass true or false. Settings of kind "hidden" are filled in by the widget\'s own editor and can be passed like any other.', 'mosmcp-abilities' );
		}

		return array(
			'types'              => $types,
			'total'              => count( $types ),
			'uses_block_widgets' => $state['uses_block_widgets'],
			'has_widget_areas'   => $state['has_widget_areas'],
			'notes'              => $notes,
		);
	}

	/**
	 * Adds a widget to an area.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function add_widget( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$state = Widgets_Support::theme_state();

		if ( ! $state['has_widget_areas'] ) {
			return Widgets_Support::no_areas_error();
		}

		$area = Widgets_Support::resolve_area( isset( $input['area'] ) ? (string) $input['area'] : '', false );

		if ( is_wp_error( $area ) ) {
			return $area;
		}

		$markup   = isset( $input['block_markup'] ) ? (string) $input['block_markup'] : '';
		$type     = isset( $input['type'] ) ? trim( (string) $input['type'] ) : '';
		$settings = ( isset( $input['settings'] ) && is_array( $input['settings'] ) ) ? $input['settings'] : array();
		$position = isset( $input['position'] ) ? max( 0, (int) $input['position'] ) : null;
		$warnings = array();
		$notes    = array();

		if ( '' !== $markup && '' !== $type && 'block' !== $type ) {
			return Site_Support::error(
				'mosmcp_widget_content_conflict',
				__( 'Pass either block_markup (for a block widget) or a classic widget type with its settings, not both.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_INVALID_INPUT,
				true
			);
		}

		if ( '' === $markup && 'block' === $type && isset( $settings['content'] ) ) {
			$markup = (string) $settings['content'];
		}

		if ( '' === $markup && ( '' === $type || 'block' === $type ) ) {
			return Site_Support::error(
				'mosmcp_widget_content_required',
				__( 'Say what the widget should be: pass block_markup to add a block widget, or a classic widget type (from mosmcp/widget-list-types) with its settings.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_INVALID_INPUT,
				true,
				array( 'use_instead' => 'mosmcp/widget-list-types' )
			);
		}

		if ( '' !== $markup ) {
			$names = Widgets_Support::check_block_markup( $markup, $warnings );

			if ( is_wp_error( $names ) ) {
				return $names;
			}

			$content = Widgets_Support::apply_html_policy( $markup, Widgets_Support::html_where( 'block', false ), $warnings );

			if ( is_wp_error( $content ) ) {
				return $content;
			}

			$widget  = Widgets_Support::widget_object( 'block' );
			$to_save = array( 'content' => $content );
			$sent    = array();

			if ( ! $state['uses_block_widgets'] ) {
				$notes[] = __( 'The classic widget editor shows this as a Block widget with its markup in a text box. It displays normally on the site.', 'mosmcp-abilities' );
			}
		} else {
			$widget = Widgets_Support::widget_object( $type );

			if ( null === $widget ) {
				return self::unknown_type( $type );
			}

			$fields  = Widgets_Support::form_fields( $widget, array() );
			$sent    = Widgets_Support::unmask( $settings, array(), $warnings );
			$to_save = Widgets_Support::normalize_checkboxes( array_merge( Widgets_Support::blank_values( $fields ), $sent ), $fields );
			$refusal = self::type_check( $type, $to_save, true );

			if ( null !== $refusal ) {
				return $refusal;
			}

			if ( isset( Widgets_Support::HTML_FIELDS[ $type ] ) ) {
				$field   = Widgets_Support::HTML_FIELDS[ $type ];
				$checked = Widgets_Support::apply_html_policy(
					isset( $to_save[ $field ] ) && is_scalar( $to_save[ $field ] ) ? (string) $to_save[ $field ] : '',
					Widgets_Support::html_where( $type, false ),
					$warnings
				);

				if ( is_wp_error( $checked ) ) {
					return $checked;
				}

				$to_save[ $field ] = $checked;
			}
		}

		if ( null === $widget ) {
			return self::unknown_type( 'block' );
		}

		$number = Widgets_Support::next_number( $widget );
		$stored = self::save_instance( $widget, $number, $to_save, array(), $warnings );

		if ( is_wp_error( $stored ) ) {
			return $stored;
		}

		$widget_id = $widget->id_base . '-' . $number;
		$index     = Widgets_Support::relocate( $widget_id, $area, $position );
		$location  = Widgets_Support::locate( $widget_id );

		if ( null === $location || $location[0] !== $area || $location[1] !== $index ) {
			// Do not leave settings behind for a widget that is not placed anywhere.
			Widgets_Support::forget_instance( $widget_id );

			return self::verify_failed( __( 'add the widget to the area', 'mosmcp-abilities' ) );
		}

		$warnings = array_merge( $warnings, self::save_report( $widget->id_base, $to_save, $sent, $stored, false ) );

		if ( null !== $position && $position > $index ) {
			$notes[] = sprintf(
				/* translators: 1: position asked for, 2: position used. */
				__( 'Position %1$d is past the end of the area, so the widget was added last, at position %2$d.', 'mosmcp-abilities' ),
				$position,
				$index
			);
		}

		if ( Widgets_Support::INACTIVE === $area ) {
			$notes[] = __( 'The widget was added to the inactive-widgets bin, so it is not shown on the site until it is moved into an area with mosmcp/widget-move.', 'mosmcp-abilities' );
		} else {
			$notes[] = __( 'The widget shows on every page that displays this area.', 'mosmcp-abilities' ) . ' ' . self::cache_note();
		}

		return self::write_result( $widget_id, $area, $index, $warnings, $notes );
	}

	/**
	 * Changes a widget's settings or block markup.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function update_widget( $input = array() ) {
		$input     = is_array( $input ) ? $input : array();
		$widget_id = isset( $input['widget_id'] ) ? trim( (string) $input['widget_id'] ) : '';
		$location  = Widgets_Support::find( $widget_id );

		if ( is_wp_error( $location ) ) {
			return $location;
		}

		list( $area, $position ) = $location;

		$parsed = wp_parse_widget_id( $widget_id );
		$widget = Widgets_Support::widget_object( $parsed['id_base'] );

		if ( null === $widget || ! isset( $parsed['number'] ) ) {
			return Site_Support::error(
				'mosmcp_widget_not_editable',
				sprintf(
					/* translators: %s: widget type. */
					__( 'The settings of this widget cannot be changed here: its type "%s" is not registered on this site (usually because the plugin that provided it is inactive), or it was built without the standard widget API. It can still be moved with mosmcp/widget-move or deleted with mosmcp/widget-delete.', 'mosmcp-abilities' ),
					$parsed['id_base']
				),
				Site_Support::CAUSE_HOST_ENVIRONMENT,
				false,
				array( 'use_instead' => 'mosmcp/widget-move' )
			);
		}

		$old = Widgets_Support::stored_instance( $widget_id );

		if ( null === $old ) {
			return Site_Support::error(
				'mosmcp_widget_settings_missing',
				__( 'This widget is placed in an area but has no stored settings, so there is nothing to update. Delete it with mosmcp/widget-delete and add a new one with mosmcp/widget-add.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_NOT_FOUND,
				false,
				array( 'use_instead' => 'mosmcp/widget-add' )
			);
		}

		$settings = ( isset( $input['settings'] ) && is_array( $input['settings'] ) ) ? $input['settings'] : array();
		$markup   = isset( $input['block_markup'] ) ? (string) $input['block_markup'] : '';
		$replace  = Site_Support::bool_input( $input, 'replace', false );
		$is_block = 'block' === $widget->id_base;
		$warnings = array();
		$notes    = array();

		if ( '' !== $markup && ! $is_block ) {
			return Site_Support::error(
				'mosmcp_widget_not_block',
				sprintf(
					/* translators: %s: widget type name. */
					__( 'block_markup only applies to block widgets, and this is a "%s" widget. Change its settings instead; mosmcp/widget-get shows them.', 'mosmcp-abilities' ),
					Widgets_Support::type_name( $widget->id_base )
				),
				Site_Support::CAUSE_INVALID_INPUT,
				true,
				array( 'use_instead' => 'mosmcp/widget-get' )
			);
		}

		if ( $is_block && '' === $markup && isset( $settings['content'] ) ) {
			$markup = (string) $settings['content'];
		}

		if ( empty( $settings ) && '' === $markup ) {
			return Site_Support::error(
				'mosmcp_widget_nothing_to_change',
				__( 'Nothing to change: pass settings (or block_markup for a block widget). To move the widget, use mosmcp/widget-move.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_INVALID_INPUT,
				true
			);
		}

		if ( $is_block ) {
			$names = Widgets_Support::check_block_markup( $markup, $warnings );

			if ( is_wp_error( $names ) ) {
				return $names;
			}

			$content = Widgets_Support::apply_html_policy( $markup, Widgets_Support::html_where( 'block', false ), $warnings );

			if ( is_wp_error( $content ) ) {
				return $content;
			}

			$to_save       = array( 'content' => $content );
			$sent          = array();
			$untouched     = false;
			$old_for_saved = $old;
		} else {
			$sent      = Widgets_Support::unmask( $settings, $old, $warnings );
			$fields    = Widgets_Support::form_fields( $widget, array() );
			$base      = $replace ? Widgets_Support::blank_values( $fields ) : $old;
			$to_save   = Widgets_Support::normalize_checkboxes( array_merge( $base, $sent ), $fields );
			$untouched = false;
			$refusal   = self::type_check( $widget->id_base, $to_save, $replace || array_key_exists( 'url', $sent ) );

			if ( null !== $refusal ) {
				return $refusal;
			}

			if ( isset( Widgets_Support::HTML_FIELDS[ $widget->id_base ] ) ) {
				$field     = Widgets_Support::HTML_FIELDS[ $widget->id_base ];
				$untouched = ! array_key_exists( $field, $sent );
				$checked   = Widgets_Support::apply_html_policy(
					isset( $to_save[ $field ] ) && is_scalar( $to_save[ $field ] ) ? (string) $to_save[ $field ] : '',
					Widgets_Support::html_where( $widget->id_base, $untouched ),
					$warnings
				);

				if ( is_wp_error( $checked ) ) {
					return $checked;
				}

				$to_save[ $field ] = $checked;
			}

			$old_for_saved = $replace ? array() : $old;
		}

		$stored = self::save_instance( $widget, (int) $parsed['number'], $to_save, $old_for_saved, $warnings );

		if ( is_wp_error( $stored ) ) {
			return $stored;
		}

		$warnings = array_merge( $warnings, self::save_report( $widget->id_base, $to_save, $sent, $stored, $untouched ) );

		$changed = array();
		foreach ( array_unique( array_merge( array_keys( $old ), array_keys( $stored ) ) ) as $key ) {
			if ( ! array_key_exists( $key, $old ) || ! array_key_exists( $key, $stored ) || wp_json_encode( $old[ $key ] ) !== wp_json_encode( $stored[ $key ] ) ) {
				$changed[] = (string) $key;
			}
		}

		if ( empty( $changed ) ) {
			$notes[] = __( 'The widget already had these settings, so nothing changed.', 'mosmcp-abilities' );
		} elseif ( Widgets_Support::STATUS_ACTIVE === Widgets_Support::area_status( $area ) ) {
			$notes[] = __( 'The change shows on every page that displays this area.', 'mosmcp-abilities' ) . ' ' . self::cache_note();
		}

		if ( Widgets_Support::STATUS_ACTIVE !== Widgets_Support::area_status( $area ) ) {
			$warnings[] = array(
				'code'    => 'widget_not_displayed',
				'message' => Widgets_Support::STATUS_INACTIVE === Widgets_Support::area_status( $area )
					? __( 'The settings were saved, but this widget is in the inactive-widgets bin, so the site does not show it. Use mosmcp/widget-move to put it in an area.', 'mosmcp-abilities' )
					: __( 'The settings were saved, but this widget is stored for an area the active theme does not register, so the site does not show it.', 'mosmcp-abilities' ),
				'context' => $area,
			);
		}

		$result                     = self::write_result( $widget_id, $area, $position, $warnings, $notes );
		$result['changed']          = ! empty( $changed );
		$result['changed_settings'] = $changed;

		return $result;
	}

	/**
	 * Moves a widget to another area and/or position.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function move_widget( $input = array() ) {
		$input     = is_array( $input ) ? $input : array();
		$widget_id = isset( $input['widget_id'] ) ? trim( (string) $input['widget_id'] ) : '';
		$location  = Widgets_Support::find( $widget_id );

		if ( is_wp_error( $location ) ) {
			return $location;
		}

		list( $from_area, $from_position ) = $location;

		$has_area     = isset( $input['area'] ) && '' !== trim( (string) $input['area'] );
		$has_position = isset( $input['position'] ) && null !== $input['position'];

		if ( ! $has_area && ! $has_position ) {
			return Site_Support::error(
				'mosmcp_widget_nothing_to_change',
				__( 'Nothing to change: pass area to move the widget to another area (use "wp_inactive_widgets" to take it off the site but keep it), position to reorder it, or both.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_INVALID_INPUT,
				true
			);
		}

		$target = $from_area;

		if ( $has_area ) {
			$target = Widgets_Support::resolve_area( (string) $input['area'], false );

			if ( is_wp_error( $target ) ) {
				return $target;
			}
		}

		$position = $has_position ? max( 0, (int) $input['position'] ) : null;
		$sidebars = Widgets_Support::sidebars();
		$notes    = array();
		$warnings = array();

		if ( $target === $from_area ) {
			$others   = count( $sidebars[ $from_area ] ) - 1;
			$expected = null === $position ? $from_position : min( $position, $others );

			if ( $expected === $from_position ) {
				$notes[] = __( 'The widget is already there, so nothing changed.', 'mosmcp-abilities' );

				return self::move_result( $widget_id, $from_area, $from_position, $from_area, $from_position, false, $warnings, $notes );
			}
		}

		$index    = Widgets_Support::relocate( $widget_id, $target, $position );
		$location = Widgets_Support::locate( $widget_id );

		if ( null === $location || $location[0] !== $target || $location[1] !== $index ) {
			return self::verify_failed( __( 'move the widget', 'mosmcp-abilities' ) );
		}

		if ( null !== $position && $position > $index ) {
			$notes[] = sprintf(
				/* translators: 1: position asked for, 2: position used. */
				__( 'Position %1$d is past the end of the area, so the widget was placed last, at position %2$d.', 'mosmcp-abilities' ),
				$position,
				$index
			);
		}

		if ( Widgets_Support::INACTIVE === $target ) {
			$notes[] = sprintf(
				/* translators: 1: area ID, 2: position. */
				__( 'The widget is off the site but keeps all its settings. To put it back where it was, call mosmcp/widget-move with area "%1$s" and position %2$d.', 'mosmcp-abilities' ),
				$from_area,
				$from_position
			);
		} elseif ( Widgets_Support::INACTIVE === $from_area ) {
			$notes[] = __( 'The widget is back on the site, with the settings it had.', 'mosmcp-abilities' );
		}

		if ( Widgets_Support::STATUS_ACTIVE === Widgets_Support::area_status( $target ) ) {
			$notes[] = self::cache_note();
		}

		return self::move_result( $widget_id, $target, $index, $from_area, $from_position, true, $warnings, $notes );
	}

	/**
	 * Permanently deletes a widget.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function delete_widget( $input = array() ) {
		$input     = is_array( $input ) ? $input : array();
		$widget_id = isset( $input['widget_id'] ) ? trim( (string) $input['widget_id'] ) : '';
		$location  = Widgets_Support::find( $widget_id );

		if ( is_wp_error( $location ) ) {
			return $location;
		}

		list( $area, $position ) = $location;

		$snapshot = Widgets_Support::describe( $widget_id, $area, $position, true );
		$label    = '' !== $snapshot['title'] ? sprintf( '%1$s "%2$s"', $snapshot['type_name'], $snapshot['title'] ) : $snapshot['type_name'];

		$unconfirmed = Site_Support::require_confirm(
			$input,
			sprintf(
				/* translators: 1: widget ID, 2: widget type and title, 3: area name. */
				__( 'the widget %1$s (%2$s) in "%3$s" and all its settings are deleted for good. To take it off the site but keep it, use mosmcp/widget-move with area "wp_inactive_widgets" instead, which can be undone', 'mosmcp-abilities' ),
				$widget_id,
				'' !== $label ? $label : $snapshot['id_base'],
				$snapshot['area_name']
			)
		);

		if ( $unconfirmed instanceof WP_Error ) {
			$data                = (array) $unconfirmed->get_error_data();
			$data['use_instead'] = 'mosmcp/widget-move';
			$unconfirmed->add_data( $data );

			return $unconfirmed;
		}

		$parsed   = wp_parse_widget_id( $widget_id );
		$option   = Widgets_Support::option_name( $parsed['id_base'] );
		$had      = null !== Widgets_Support::stored_instance( $widget_id );
		$warnings = array();

		/*
		 * The action WordPress fires when a widget is deleted from the Widgets screen
		 * and from its REST route. Plugins that keep data per widget, such as
		 * translation and visibility plugins, clean up on it.
		 */
		do_action( 'delete_widget', $widget_id, $area, $parsed['id_base'] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		/*
		 * Take the widget out of its area first and confirm that stuck before its
		 * settings are touched. The other order loses the settings for good when the
		 * area write fails, leaving a widget still placed but with nothing to show.
		 */
		Widgets_Support::relocate( $widget_id, '', null );

		if ( null !== Widgets_Support::locate( $widget_id ) ) {
			return self::verify_failed(
				__( 'take the widget out of its area', 'mosmcp-abilities' ),
				__( 'Nothing was deleted: the widget and its settings are as they were, and a copy of them is in this error\'s data.', 'mosmcp-abilities' ),
				array( 'widget' => $snapshot )
			);
		}

		if ( function_exists( 'wp_unregister_sidebar_widget' ) ) {
			wp_unregister_sidebar_widget( $widget_id );
		}

		/*
		 * Delete the settings by instance number straight from the option, whether
		 * or not the widget's type is registered. Settings left behind for a type
		 * whose plugin is inactive bring the "deleted" widget back into the inactive
		 * bin the next time that plugin is active.
		 */
		if ( $had ) {
			Widgets_Support::forget_instance( $widget_id );

			if ( null !== Widgets_Support::stored_instance( $widget_id ) ) {
				return Site_Support::error(
					'mosmcp_widget_delete_incomplete',
					sprintf(
						/* translators: 1: widget ID, 2: option name. */
						__( 'The widget %1$s was taken off the site, but WordPress did not delete its stored settings (in the %2$s option); another plugin filtering that option is the usual cause. Nothing is lost: a copy of the settings is in this error\'s data, and WordPress will list the widget in the inactive-widgets bin the next time the Widgets screen is opened, from where it can be deleted again.', 'mosmcp-abilities' ),
						$widget_id,
						$option
					),
					Site_Support::CAUSE_WP_CORE,
					false,
					array(
						'widget'      => $snapshot,
						'use_instead' => 'mosmcp/widget-list',
					)
				);
			}
		} elseif ( isset( $parsed['number'] ) ) {
			$warnings[] = array(
				'code'    => 'no_settings_found',
				'message' => sprintf(
					/* translators: %s: option name. */
					__( 'The widget was removed from its area, but no stored settings were found for it in the %s option. If the plugin that provided it keeps its settings somewhere else, they are still there.', 'mosmcp-abilities' ),
					$option
				),
				'context' => $option,
			);
		}

		$notes = array( __( 'The widget is gone for good. Its settings are included above (with secrets masked) in case it needs to be recreated with mosmcp/widget-add.', 'mosmcp-abilities' ) );

		if ( Widgets_Support::STATUS_ACTIVE === $snapshot['area_status'] ) {
			$notes[] = self::cache_note();
		}

		return array(
			'widget_id'         => $widget_id,
			'deleted'           => true,
			'previous_area'     => $area,
			'previous_position' => $position,
			'widget'            => $snapshot,
			'warnings'          => $warnings,
			'notes'             => $notes,
		);
	}

	/**
	 * Runs a widget's own update() and stores the result.
	 *
	 * This is WP_Widget::update_callback() without the $_POST plumbing: the
	 * widget's update() sanitizes the settings, the core widget_update_callback
	 * filter lets plugins adjust or veto them, and the result is saved and read
	 * back.
	 *
	 * @param \WP_Widget                        $widget       Widget object.
	 * @param int                               $number       Instance number.
	 * @param array<string, mixed>              $new_instance Settings to save.
	 * @param array<string, mixed>              $old_instance Settings before the change.
	 * @param array<int, array<string, string>> $warnings     Warnings accumulator.
	 * @return array<string, mixed>|WP_Error Settings as stored, or an error.
	 */
	private static function save_instance( $widget, $number, array $new_instance, array $old_instance, array &$warnings ) {
		$saved = Widgets_Support::quietly(
			static function () use ( $widget, $number, $new_instance, $old_instance ) {
				$widget->_set( $number );
				$instance = $widget->update( $new_instance, $old_instance );

				// WordPress core's own filter, applied exactly as WP_Widget::update_callback() applies it.
				return apply_filters( 'widget_update_callback', $instance, $new_instance, $old_instance, $widget ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			}
		);

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		if ( false === $saved ) {
			return Site_Support::error(
				'mosmcp_widget_save_refused',
				__( 'Another plugin on this site refused the change (it vetoed the save through the widget_update_callback filter), so nothing was saved.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_CONFLICT,
				false
			);
		}

		if ( ! is_array( $saved ) ) {
			return Site_Support::error(
				'mosmcp_widget_save_invalid',
				__( 'The widget\'s own code returned something other than a set of settings, so nothing was saved.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_WP_CORE,
				false
			);
		}

		$all            = $widget->get_settings();
		$all[ $number ] = $saved;
		$widget->save_settings( $all );

		// Register the instance for the rest of this request, as the Widgets screen does.
		$widget->_set( $number );
		$widget->_register_one( $number );
		$widget->updated = false;

		$stored = Widgets_Support::stored_instance( $widget->id_base . '-' . $number );

		if ( null === $stored ) {
			return self::verify_failed( __( 'save the widget\'s settings', 'mosmcp-abilities' ) );
		}

		if ( wp_json_encode( $stored ) !== wp_json_encode( $saved ) ) {
			$warnings[] = array(
				'code'    => 'settings_changed_on_save',
				'message' => __( 'The settings were saved, but another plugin changed them on the way into the database. The widget shown is what was actually stored.', 'mosmcp-abilities' ),
				'context' => '',
			);
		}

		return $stored;
	}

	/**
	 * What to say about page caches after a change visitors will see.
	 *
	 * Widget changes are option writes, which the library's cache purger treats as
	 * site-wide, so detected page caches are cleared once the ability succeeds.
	 *
	 * @return string
	 */
	private static function cache_note() {
		if ( class_exists( Cache_Purger::class ) && Cache_Purger::enabled() ) {
			return __( 'Page caches detected on this site are cleared automatically once the change is saved. If visitors still see the old version, the cache is at the host or a CDN; mosmcp/site-flush-cache reports which caches it can reach.', 'mosmcp-abilities' );
		}

		return __( 'If visitors still see the old version, clear the page cache with mosmcp/site-flush-cache.', 'mosmcp-abilities' );
	}

	/**
	 * Refuses settings a particular core widget cannot be saved with.
	 *
	 * The RSS widget fetches its feed whenever its address is saved, and with an
	 * empty address WordPress's own feed handling can spin without end, taking the
	 * request down with it. An RSS widget without a feed shows nothing anyway.
	 *
	 * @param string               $id_base     Widget type.
	 * @param array<string, mixed> $to_save     Settings about to be handed to the widget.
	 * @param bool                 $url_changes Whether this save sets the feed address.
	 * @return WP_Error|null
	 */
	private static function type_check( $id_base, array $to_save, $url_changes ) {
		if ( 'rss' !== $id_base || ! $url_changes ) {
			return null;
		}

		$url = isset( $to_save['url'] ) && is_scalar( $to_save['url'] ) ? trim( (string) $to_save['url'] ) : '';

		if ( '' !== $url && '' !== (string) sanitize_url( $url ) ) {
			return null;
		}

		return Site_Support::error(
			'mosmcp_widget_rss_url_required',
			__( 'An RSS widget needs the address of the feed to show, in settings.url, for example "https://example.com/feed/". Nothing was saved. Note that WordPress fetches the feed when the widget is saved, to check it.', 'mosmcp-abilities' ),
			Site_Support::CAUSE_INVALID_INPUT,
			true
		);
	}

	/**
	 * Warnings about what the widget changed while saving.
	 *
	 * @param string               $id_base   Widget type.
	 * @param array<string, mixed> $handed    Settings handed to the widget.
	 * @param array<string, mixed> $sent      Settings the caller supplied (classic widgets).
	 * @param array<string, mixed> $stored    Settings as stored.
	 * @param bool                 $untouched Whether the HTML setting was left as it was.
	 * @return array<int, array<string, string>>
	 */
	private static function save_report( $id_base, array $handed, array $sent, array $stored, $untouched ) {
		$warnings = array();
		$skip     = array();

		if ( isset( Widgets_Support::HTML_FIELDS[ $id_base ] ) ) {
			$field  = Widgets_Support::HTML_FIELDS[ $id_base ];
			$skip[] = $field;

			if ( isset( $handed[ $field ] ) && is_string( $handed[ $field ] ) ) {
				$warnings = Html_Policy::save_warnings(
					$handed[ $field ],
					isset( $stored[ $field ] ) && is_scalar( $stored[ $field ] ) ? (string) $stored[ $field ] : '',
					Widgets_Support::html_where( $id_base, $untouched )
				);
			}
		}

		return array_merge( $warnings, Widgets_Support::adjusted_warnings( $sent, $stored, $skip ) );
	}

	/**
	 * The result every add and update returns.
	 *
	 * @param string                            $widget_id Widget ID.
	 * @param string                            $area      Area it is in.
	 * @param int                               $position  Position in the area.
	 * @param array<int, array<string, string>> $warnings  Warnings.
	 * @param string[]                          $notes     Notes.
	 * @return array<string, mixed>
	 */
	private static function write_result( $widget_id, $area, $position, array $warnings, array $notes ) {
		$sidebars = Widgets_Support::sidebars();

		return array(
			'widget_id'    => $widget_id,
			'area'         => $area,
			'position'     => (int) $position,
			'widget'       => Widgets_Support::describe( $widget_id, $area, $position, true ),
			'area_widgets' => isset( $sidebars[ $area ] ) ? $sidebars[ $area ] : array(),
			'warnings'     => $warnings,
			'notes'        => $notes,
		);
	}

	/**
	 * The result a move returns.
	 *
	 * @param string                            $widget_id     Widget ID.
	 * @param string                            $area          Area it is in now.
	 * @param int                               $position      Position now.
	 * @param string                            $from_area     Area before.
	 * @param int                               $from_position Position before.
	 * @param bool                              $changed       Whether anything moved.
	 * @param array<int, array<string, string>> $warnings      Warnings.
	 * @param string[]                          $notes         Notes.
	 * @return array<string, mixed>
	 */
	private static function move_result( $widget_id, $area, $position, $from_area, $from_position, $changed, array $warnings, array $notes ) {
		$result                      = self::write_result( $widget_id, $area, $position, $warnings, $notes );
		$result['previous_area']     = $from_area;
		$result['previous_position'] = (int) $from_position;
		$result['changed']           = (bool) $changed;

		return $result;
	}

	/**
	 * Refusal for a widget type this site does not have.
	 *
	 * @param string $type Widget type supplied.
	 * @return WP_Error
	 */
	private static function unknown_type( $type ) {
		global $wp_widget_factory;

		$known = array();
		if ( is_object( $wp_widget_factory ) && isset( $wp_widget_factory->widgets ) && is_array( $wp_widget_factory->widgets ) ) {
			foreach ( $wp_widget_factory->widgets as $widget ) {
				if ( $widget instanceof \WP_Widget ) {
					$known[] = (string) $widget->id_base;
				}
			}
		}
		sort( $known );

		return Site_Support::error(
			'mosmcp_widget_type_not_found',
			sprintf(
				/* translators: 1: the widget type supplied, 2: comma-separated widget types. */
				__( 'This site has no widget type "%1$s". Widget types are named by their id_base, for example "custom_html", "text" or "recent-posts". The types available here are: %2$s. For any block, pass block_markup instead of a type.', 'mosmcp-abilities' ),
				$type,
				empty( $known ) ? __( 'none', 'mosmcp-abilities' ) : implode( ', ', $known )
			),
			Site_Support::CAUSE_NOT_FOUND,
			false,
			array( 'use_instead' => 'mosmcp/widget-list-types' )
		);
	}

	/**
	 * Refusal for a write that WordPress reported but that did not stick.
	 *
	 * @param string               $what    What was being attempted, for the message.
	 * @param string               $outcome Optional sentence saying what state things were left in.
	 * @param array<string, mixed> $extra   Extra error data, such as a copy of the widget.
	 * @return WP_Error
	 */
	private static function verify_failed( $what, $outcome = '', array $extra = array() ) {
		$message = sprintf(
			/* translators: %s: what was being attempted, such as "move the widget". */
			__( 'WordPress accepted the request to %s, but reading the widgets back shows the change was not stored. A plugin filtering the widget options, or a database problem, is the usual cause. Check with mosmcp/widget-list before trying again.', 'mosmcp-abilities' ),
			$what
		);

		if ( '' !== $outcome ) {
			$message .= ' ' . $outcome;
		}

		return Site_Support::error(
			'mosmcp_widget_save_failed',
			$message,
			Site_Support::CAUSE_WP_CORE,
			true,
			array_merge( $extra, array( 'use_instead' => 'mosmcp/widget-list' ) )
		);
	}
}
