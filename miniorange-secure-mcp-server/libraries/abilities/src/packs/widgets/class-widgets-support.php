<?php
/**
 * Storage, settings-form, masking and block-markup helpers for the Widgets & Sidebars pack.
 *
 * WordPress keeps widgets in two places. The sidebars_widgets option says which
 * widget sits in which area and in what order, and one widget_{id_base} option
 * per widget type holds every instance's settings under a numeric key. Everything
 * here reads that storage directly rather than through the REST widget routes,
 * because those routes run retrieve_widgets() on every request, reads included,
 * and that rewrites sidebars_widgets: areas the current theme does not register
 * are merged into ones it does, and unplaced widgets are moved to the inactive
 * bin. A read must not change the site, and a write should change only what it
 * was asked to change.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Widgets;

use MoSMCP\Abilities\Packs\Site\Site_Support;
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
 * Class Widgets_Support
 *
 * Static helpers shared by the widget abilities.
 */
class Widgets_Support {

	/**
	 * The area WordPress keeps removed-but-kept widgets in.
	 */
	const INACTIVE = 'wp_inactive_widgets';

	/**
	 * Placeholder returned in place of a secret setting value.
	 *
	 * Sending it back in an update keeps the stored value, so a settings object
	 * read with widget-get can be edited and written back without wiping the
	 * secret it carried.
	 */
	const MASK = '[masked]';

	/**
	 * Area status: an area the active theme registers, so its widgets are shown.
	 */
	const STATUS_ACTIVE = 'active';

	/**
	 * Area status: the inactive-widgets bin, whose widgets are kept but not shown.
	 */
	const STATUS_INACTIVE = 'inactive';

	/**
	 * Area status: stored widgets for an area the active theme does not register.
	 */
	const STATUS_UNREGISTERED = 'unregistered';

	/**
	 * Most characters of rendered widget HTML returned.
	 */
	const RENDER_LIMIT = 20000;

	/**
	 * The setting that holds HTML, per core widget type.
	 *
	 * These are the settings WordPress itself filters through wp_kses_post() for a
	 * user without unfiltered_html, so they are the ones the HTML policy guards.
	 *
	 * @var array<string, string>
	 */
	const HTML_FIELDS = array(
		'block'       => 'content',
		'custom_html' => 'content',
		'text'        => 'text',
	);

	/**
	 * The block that does the same job as each core classic widget.
	 *
	 * @var array<string, string>
	 */
	const BLOCK_EQUIVALENTS = array(
		'archives'        => 'core/archives',
		'calendar'        => 'core/calendar',
		'categories'      => 'core/categories',
		'custom_html'     => 'core/html',
		'media_audio'     => 'core/audio',
		'media_gallery'   => 'core/gallery',
		'media_image'     => 'core/image',
		'media_video'     => 'core/video',
		'nav_menu'        => 'core/navigation',
		'pages'           => 'core/page-list',
		'recent-comments' => 'core/latest-comments',
		'recent-posts'    => 'core/latest-posts',
		'rss'             => 'core/rss',
		'search'          => 'core/search',
		'tag_cloud'       => 'core/tag-cloud',
		'text'            => 'core/paragraph',
	);

	/**
	 * Setting names whose values are masked before being returned.
	 *
	 * Matched against the name split into words, so "author" or "bypass_cache" do
	 * not match while "api_key", "apiKey", "client_secret" and "password" do.
	 *
	 * @var string
	 */
	const SECRET_KEY_PATTERN = '/(^|_)(passwords?|passwd|pass|pwd|passphrase|secrets?|tokens?|bearer|salt|credentials?|auth|authorization|signature|apikey|(?:api|private|access|license|licence|consumer|client|app|secret)_?keys?)(_|$)/';

	/**
	 * WordPress's own block-delimiter pattern, from WP_Block_Parser.
	 *
	 * @var string
	 */
	const BLOCK_DELIMITER = '/<!--\s+(?P<closer>\/)?wp:(?P<namespace>[a-z][a-z0-9_-]*\/)?(?P<name>[a-z][a-z0-9_-]*)\s+(?P<attrs>{(?:(?:[^}]+|}+(?=})|(?!}\s+\/?-->).)*+)?}\s+)?(?P<void>\/)?-->/s';

	/**
	 * Describes the active theme's relationship with widgets.
	 *
	 * @return array<string, mixed>
	 */
	public static function theme_state() {
		$theme = wp_get_theme();

		return array(
			'theme'              => (string) $theme->get( 'Name' ),
			'is_block_theme'     => function_exists( 'wp_is_block_theme' ) && wp_is_block_theme(),
			'supports_widgets'   => (bool) current_theme_supports( 'widgets' ),
			'uses_block_widgets' => function_exists( 'wp_use_widgets_block_editor' ) && (bool) wp_use_widgets_block_editor(),
			'has_widget_areas'   => ! empty( self::registered_areas() ),
		);
	}

	/**
	 * The widget areas the active theme and plugins register, in registration order.
	 *
	 * @return array<string, array<string, string>> Area ID => name and description.
	 */
	public static function registered_areas() {
		global $wp_registered_sidebars;

		$areas = array();

		if ( ! is_array( $wp_registered_sidebars ) ) {
			return $areas;
		}

		foreach ( $wp_registered_sidebars as $id => $sidebar ) {
			$areas[ (string) $id ] = array(
				'name'        => isset( $sidebar['name'] ) ? (string) $sidebar['name'] : (string) $id,
				'description' => isset( $sidebar['description'] ) ? wp_strip_all_tags( (string) $sidebar['description'] ) : '',
			);
		}

		return $areas;
	}

	/**
	 * Every stored area and the widget IDs in it, exactly as saved.
	 *
	 * Read from the option rather than wp_get_sidebars_widgets(), which applies the
	 * sidebars_widgets filter. Widget-visibility plugins use that filter to hide
	 * widgets on particular pages, so the filtered list depends on the request and
	 * is not what is stored.
	 *
	 * @return array<string, string[]>
	 */
	public static function sidebars() {
		$raw   = get_option( 'sidebars_widgets', array() );
		$areas = array();

		if ( ! is_array( $raw ) ) {
			return $areas;
		}

		foreach ( $raw as $area => $ids ) {
			if ( 'array_version' === (string) $area ) {
				continue;
			}

			$clean = array();
			foreach ( is_array( $ids ) ? $ids : array() as $id ) {
				if ( is_string( $id ) && '' !== $id ) {
					$clean[] = $id;
				}
			}

			$areas[ (string) $area ] = $clean;
		}

		return $areas;
	}

	/**
	 * Finds which area a widget is in and at which position.
	 *
	 * @param string                       $widget_id Widget ID.
	 * @param array<string, string[]>|null $sidebars  Stored areas, or null to read them.
	 * @return array{0: string, 1: int}|null Area ID and 0-based position, or null when not placed.
	 */
	public static function locate( $widget_id, $sidebars = null ) {
		$sidebars = is_array( $sidebars ) ? $sidebars : self::sidebars();

		foreach ( $sidebars as $area => $ids ) {
			$index = array_search( (string) $widget_id, $ids, true );

			if ( false !== $index ) {
				return array( (string) $area, (int) $index );
			}
		}

		return null;
	}

	/**
	 * Finds a widget by ID, or explains why it cannot be found.
	 *
	 * @param string $widget_id Widget ID supplied by the caller.
	 * @return array{0: string, 1: int}|WP_Error Area and position, or an error.
	 */
	public static function find( $widget_id ) {
		$widget_id = trim( (string) $widget_id );

		if ( '' === $widget_id ) {
			return Site_Support::error(
				'mosmcp_widget_id_required',
				__( 'No widget was named. Pass a widget ID such as "block-3" or "text-2", as mosmcp/widget-list reports it.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_INVALID_INPUT,
				true,
				array( 'use_instead' => 'mosmcp/widget-list' )
			);
		}

		$sidebars = self::sidebars();
		$location = self::locate( $widget_id, $sidebars );

		if ( null !== $location ) {
			return $location;
		}

		$all = array();
		foreach ( $sidebars as $ids ) {
			$all = array_merge( $all, $ids );
		}

		$parsed  = wp_parse_widget_id( $widget_id );
		$similar = array();
		foreach ( $all as $id ) {
			if ( 0 === strpos( $id, $parsed['id_base'] . '-' ) ) {
				$similar[] = $id;
			}
		}
		$suggest = ! empty( $similar ) ? $similar : $all;
		$suggest = array_slice( $suggest, 0, 12 );

		$message = sprintf(
			/* translators: %s: the widget ID supplied. */
			__( 'No widget with the ID "%s" is placed in any widget area or in the inactive-widgets bin. Widget IDs look like "block-3" or "text-2".', 'mosmcp-abilities' ),
			$widget_id
		);

		if ( null !== self::stored_instance( $widget_id ) ) {
			$message .= ' ' . __( 'Settings for it are still stored, but it is not placed anywhere, so it is not part of the site. WordPress files such widgets into the inactive-widgets bin the next time the Widgets screen is opened.', 'mosmcp-abilities' );
		}

		$message .= ' ' . ( empty( $suggest )
			? __( 'This site has no widgets placed at all.', 'mosmcp-abilities' )
			: sprintf(
				/* translators: %s: comma-separated widget IDs. */
				__( 'Widgets on this site include: %s.', 'mosmcp-abilities' ),
				implode( ', ', $suggest )
			) );

		$message .= ' ' . __( 'Use mosmcp/widget-list to see every widget.', 'mosmcp-abilities' );

		return Site_Support::error(
			'mosmcp_widget_not_found',
			$message,
			Site_Support::CAUSE_NOT_FOUND,
			false,
			array(
				'use_instead' => 'mosmcp/widget-list',
				'candidates'  => $suggest,
			)
		);
	}

	/**
	 * Human name of an area.
	 *
	 * @param string $area Area ID.
	 * @return string
	 */
	public static function area_name( $area ) {
		$areas = self::registered_areas();

		if ( isset( $areas[ $area ] ) ) {
			return $areas[ $area ]['name'];
		}

		if ( self::INACTIVE === $area ) {
			return __( 'Inactive widgets', 'mosmcp-abilities' );
		}

		return (string) $area;
	}

	/**
	 * Whether an area's widgets are shown, kept aside, or left over from another theme.
	 *
	 * @param string $area Area ID.
	 * @return string One of the STATUS_* constants.
	 */
	public static function area_status( $area ) {
		if ( self::INACTIVE === $area ) {
			return self::STATUS_INACTIVE;
		}

		$areas = self::registered_areas();

		return isset( $areas[ $area ] ) ? self::STATUS_ACTIVE : self::STATUS_UNREGISTERED;
	}

	/**
	 * Explains why the site has no widget areas and what to use instead.
	 *
	 * @return string
	 */
	public static function no_areas_explanation() {
		$state = self::theme_state();

		if ( $state['is_block_theme'] ) {
			return sprintf(
				/* translators: %s: theme name. */
				__( 'The active theme, %s, is a block theme, and block themes have no widget areas. Their header, footer and any sidebar are template parts made of blocks, edited in the Site Editor rather than under Appearance then Widgets. Use mosmcp/theme-part-list to find them and mosmcp/theme-part-get to read one; mosmcp/theme-part-update stages a change to one as a draft.', 'mosmcp-abilities' ),
				$state['theme']
			);
		}

		return sprintf(
			/* translators: %s: theme name. */
			__( 'The active theme, %s, does not register any widget areas, so there is nowhere on the site to place a widget. Widget areas come from the theme; a theme that has them, or a plugin that adds them, is needed before widgets can be shown.', 'mosmcp-abilities' ),
			$state['theme']
		);
	}

	/**
	 * Refusal for an operation that needs a widget area when the theme has none.
	 *
	 * @return WP_Error
	 */
	public static function no_areas_error() {
		$state = self::theme_state();

		return Site_Support::error(
			'mosmcp_widget_no_areas',
			self::no_areas_explanation(),
			Site_Support::CAUSE_HOST_ENVIRONMENT,
			false,
			$state['is_block_theme'] ? array( 'use_instead' => 'mosmcp/theme-part-list' ) : array()
		);
	}

	/**
	 * Resolves a caller's area reference to an area ID.
	 *
	 * Accepts the forms people actually use: the ID ("sidebar-1"), the name
	 * ("Footer Area"), part of either ("footer"), and "inactive" for the bin. Each
	 * rule is a complete pass over every area, and a pass that matches more than
	 * one area refuses rather than picking one.
	 *
	 * @param string $reference   Area reference supplied by the caller.
	 * @param bool   $for_listing Whether stored areas the theme no longer registers are acceptable.
	 * @return string|WP_Error Area ID, or an error.
	 */
	public static function resolve_area( $reference, $for_listing = false ) {
		$reference = trim( (string) $reference );
		$areas     = self::registered_areas();

		if ( '' === $reference ) {
			return Site_Support::error(
				'mosmcp_widget_area_required',
				__( 'No widget area was named. Pass an area ID such as "sidebar-1", as mosmcp/widget-list-areas reports it, or "wp_inactive_widgets" for the inactive-widgets bin.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_INVALID_INPUT,
				true,
				array( 'use_instead' => 'mosmcp/widget-list-areas' )
			);
		}

		if ( isset( $areas[ $reference ] ) || self::INACTIVE === $reference ) {
			return $reference;
		}

		$needle = strtolower( $reference );

		if ( in_array( $needle, array( 'inactive', 'inactive widgets', 'inactive_widgets', 'inactive-widgets', self::INACTIVE ), true ) ) {
			return self::INACTIVE;
		}

		$stored = self::sidebars();

		if ( $for_listing && isset( $stored[ $reference ] ) ) {
			return $reference;
		}

		$rules = array(
			static function ( $candidate ) use ( $needle ) {
				return strtolower( $candidate['id'] ) === $needle;
			},
			static function ( $candidate ) use ( $needle ) {
				return strtolower( $candidate['name'] ) === $needle;
			},
			static function ( $candidate ) use ( $needle ) {
				return false !== strpos( strtolower( $candidate['name'] ), $needle ) || false !== strpos( strtolower( $candidate['id'] ), $needle );
			},
		);

		foreach ( $rules as $matches_rule ) {
			$hits = array();

			foreach ( $areas as $id => $area ) {
				$candidate = array(
					'id'   => (string) $id,
					'name' => $area['name'],
				);

				if ( $matches_rule( $candidate ) ) {
					$hits[] = (string) $id;
				}
			}

			if ( 1 === count( $hits ) ) {
				return $hits[0];
			}

			if ( count( $hits ) > 1 ) {
				return Site_Support::error(
					'mosmcp_widget_area_ambiguous',
					sprintf(
						/* translators: 1: the area reference supplied, 2: comma-separated area IDs. */
						__( '"%1$s" matches more than one widget area: %2$s. Pass the exact area ID so the widget does not land in the wrong place.', 'mosmcp-abilities' ),
						$reference,
						implode( ', ', $hits )
					),
					Site_Support::CAUSE_INVALID_INPUT,
					true,
					array( 'candidates' => $hits )
				);
			}
		}

		if ( empty( $areas ) ) {
			return self::no_areas_error();
		}

		$listed = array();
		foreach ( $areas as $id => $area ) {
			$listed[] = sprintf( '%1$s ("%2$s")', $id, $area['name'] );
		}

		$message = sprintf(
			/* translators: 1: the area reference supplied, 2: comma-separated list of areas. */
			__( 'No widget area matches "%1$s". The areas this theme registers are: %2$s. Use "wp_inactive_widgets" for the inactive-widgets bin.', 'mosmcp-abilities' ),
			$reference,
			implode( ', ', $listed )
		);

		if ( isset( $stored[ $reference ] ) ) {
			$message .= ' ' . sprintf(
				/* translators: %s: the area ID supplied. */
				__( '"%s" still holds widgets from an earlier theme, but the current theme does not register it, so nothing placed there would be shown.', 'mosmcp-abilities' ),
				$reference
			);
		}

		return Site_Support::error(
			'mosmcp_widget_area_not_found',
			$message,
			Site_Support::CAUSE_NOT_FOUND,
			false,
			array(
				'use_instead'     => 'mosmcp/widget-list-areas',
				'available_areas' => array_merge( array_keys( $areas ), array( self::INACTIVE ) ),
			)
		);
	}

	/**
	 * The registered widget object for a type, when the type extends WP_Widget.
	 *
	 * @param string $id_base Widget type.
	 * @return \WP_Widget|null
	 */
	public static function widget_object( $id_base ) {
		global $wp_widget_factory;

		if ( ! is_object( $wp_widget_factory ) || ! method_exists( $wp_widget_factory, 'get_widget_object' ) ) {
			return null;
		}

		$widget = $wp_widget_factory->get_widget_object( (string) $id_base );

		return $widget instanceof \WP_Widget ? $widget : null;
	}

	/**
	 * Every stored instance of a widget type, keyed by instance number.
	 *
	 * Read from the option directly rather than WP_Widget::get_settings(), which
	 * writes the option when it does not exist yet. A read must not write.
	 *
	 * @param \WP_Widget $widget Widget object.
	 * @return array<int|string, mixed>
	 */
	public static function stored_instances( $widget ) {
		$settings = get_option( $widget->option_name, array() );

		if ( ! is_array( $settings ) ) {
			return array();
		}

		unset( $settings['_multiwidget'], $settings['__i__'] );

		return $settings;
	}

	/**
	 * The option a widget type keeps its settings in.
	 *
	 * A registered type names it itself. A type that is not registered, usually
	 * because its plugin is inactive, is taken to use WordPress's default,
	 * widget_{id_base}, so its settings can still be read and deleted: left behind,
	 * they bring the widget back the next time the plugin is active.
	 *
	 * @param string $id_base Widget type.
	 * @return string
	 */
	public static function option_name( $id_base ) {
		$widget = self::widget_object( $id_base );

		return null !== $widget ? (string) $widget->option_name : 'widget_' . $id_base;
	}

	/**
	 * One widget's stored settings, whether or not its type is registered.
	 *
	 * @param string $widget_id Widget ID.
	 * @return array<string, mixed>|null Settings, or null when none are stored.
	 */
	public static function stored_instance( $widget_id ) {
		$parsed = wp_parse_widget_id( (string) $widget_id );

		if ( ! isset( $parsed['number'] ) ) {
			return null;
		}

		$all = get_option( self::option_name( $parsed['id_base'] ), array() );

		return ( is_array( $all ) && isset( $all[ $parsed['number'] ] ) && is_array( $all[ $parsed['number'] ] ) ) ? $all[ $parsed['number'] ] : null;
	}

	/**
	 * Deletes one widget's stored settings, whether or not its type is registered.
	 *
	 * @param string $widget_id Widget ID.
	 * @return bool Whether there were settings to delete.
	 */
	public static function forget_instance( $widget_id ) {
		$parsed = wp_parse_widget_id( (string) $widget_id );

		if ( ! isset( $parsed['number'] ) ) {
			return false;
		}

		$option = self::option_name( $parsed['id_base'] );
		$all    = get_option( $option, array() );

		if ( ! is_array( $all ) || ! array_key_exists( $parsed['number'], $all ) ) {
			return false;
		}

		unset( $all[ $parsed['number'] ] );
		update_option( $option, $all );

		return true;
	}

	/**
	 * Human name of a widget type, or empty when the type is not registered.
	 *
	 * @param string $id_base Widget type.
	 * @return string
	 */
	public static function type_name( $id_base ) {
		global $wp_registered_widgets;

		$widget = self::widget_object( $id_base );

		if ( null !== $widget ) {
			return (string) $widget->name;
		}

		if ( isset( $wp_registered_widgets[ $id_base ]['name'] ) ) {
			return (string) $wp_registered_widgets[ $id_base ]['name'];
		}

		return '';
	}

	/**
	 * Describes one placed widget in the shape every widget ability returns.
	 *
	 * @param string $widget_id Widget ID.
	 * @param string $area      Area the widget is in.
	 * @param int    $position  0-based position in that area.
	 * @param bool   $detail    Whether to include the full settings and block markup.
	 * @return array<string, mixed>
	 */
	public static function describe( $widget_id, $area, $position, $detail ) {
		$parsed   = wp_parse_widget_id( (string) $widget_id );
		$id_base  = (string) $parsed['id_base'];
		$widget   = self::widget_object( $id_base );
		$instance = self::stored_instance( $widget_id );
		$is_block = 'block' === $id_base;
		$markup   = ( $is_block && is_array( $instance ) && isset( $instance['content'] ) && is_string( $instance['content'] ) ) ? $instance['content'] : '';
		$tree     = $is_block ? parse_blocks( $markup ) : array();
		$names    = $is_block ? array_values( array_unique( self::block_names( $tree ) ) ) : array();
		$masked   = array();
		$visible  = is_array( $instance ) ? self::mask( $instance, $masked ) : array();

		$row = array(
			'widget_id'         => (string) $widget_id,
			'id_base'           => $id_base,
			'type_name'         => self::type_name( $id_base ),
			'area'              => (string) $area,
			'area_name'         => self::area_name( $area ),
			'area_status'       => self::area_status( $area ),
			'position'          => (int) $position,
			'title'             => self::title_of( $id_base, $instance, $tree ),
			'summary'           => self::summary_of( $id_base, $instance, $visible, $markup, $names ),
			'is_block_widget'   => $is_block,
			'blocks'            => $names,
			'settings_editable' => null !== $widget && isset( $parsed['number'] ),
		);

		if ( $detail ) {
			$row['settings']        = empty( $visible ) ? new \stdClass() : $visible;
			$row['masked_settings'] = $masked;
			$row['block_markup']    = $markup;
		}

		return $row;
	}

	/**
	 * The title a widget shows, where one can be determined.
	 *
	 * @param string                    $id_base  Widget type.
	 * @param array<string, mixed>|null $instance Stored settings.
	 * @param array<int, mixed>         $tree     Parsed blocks, for a block widget.
	 * @return string
	 */
	private static function title_of( $id_base, $instance, array $tree ) {
		if ( 'block' === $id_base ) {
			return self::block_title( $tree );
		}

		if ( is_array( $instance ) && isset( $instance['title'] ) && is_scalar( $instance['title'] ) ) {
			return wp_strip_all_tags( (string) $instance['title'] );
		}

		return '';
	}

	/**
	 * A block widget's title: a widget group's title, else its first heading.
	 *
	 * @param array<int, mixed> $blocks Parsed blocks.
	 * @return string
	 */
	private static function block_title( array $blocks ) {
		foreach ( $blocks as $block ) {
			$name = isset( $block['blockName'] ) ? $block['blockName'] : null;

			if ( 'core/widget-group' === $name && ! empty( $block['attrs']['title'] ) && is_string( $block['attrs']['title'] ) ) {
				return wp_strip_all_tags( $block['attrs']['title'] );
			}

			if ( 'core/heading' === $name && isset( $block['innerHTML'] ) ) {
				$text = trim( wp_strip_all_tags( (string) $block['innerHTML'] ) );
				if ( '' !== $text ) {
					return $text;
				}
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$inner = self::block_title( $block['innerBlocks'] );
				if ( '' !== $inner ) {
					return $inner;
				}
			}
		}

		return '';
	}

	/**
	 * A one-line description of what a widget shows.
	 *
	 * @param string                    $id_base  Widget type.
	 * @param array<string, mixed>|null $instance Stored settings.
	 * @param array<string, mixed>      $visible  Settings with secrets masked.
	 * @param string                    $markup   Block markup, for a block widget.
	 * @param string[]                  $names    Block names, for a block widget.
	 * @return string
	 */
	private static function summary_of( $id_base, $instance, array $visible, $markup, array $names ) {
		if ( null === self::widget_object( $id_base ) ) {
			return __( 'The widget type is not registered on this site, usually because the plugin that provided it is inactive. It shows nothing until that plugin is active again.', 'mosmcp-abilities' );
		}

		if ( ! is_array( $instance ) ) {
			return __( 'No stored settings were found for this widget.', 'mosmcp-abilities' );
		}

		if ( 'block' === $id_base ) {
			if ( empty( $names ) ) {
				return __( 'Empty block widget.', 'mosmcp-abilities' );
			}

			$text    = self::text_excerpt( $markup, 120 );
			$summary = sprintf(
				/* translators: %s: comma-separated block type names. */
				__( 'Blocks: %s.', 'mosmcp-abilities' ),
				implode( ', ', $names )
			);

			if ( '' !== $text ) {
				$summary .= ' ' . sprintf(
					/* translators: %s: excerpt of the widget's text. */
					__( 'Text: "%s"', 'mosmcp-abilities' ),
					$text
				);
			}

			return $summary;
		}

		if ( isset( self::HTML_FIELDS[ $id_base ] ) ) {
			$field = self::HTML_FIELDS[ $id_base ];
			$html  = ( isset( $visible[ $field ] ) && is_string( $visible[ $field ] ) ) ? $visible[ $field ] : '';
			$text  = self::text_excerpt( $html, 120 );

			if ( '' !== $text ) {
				return sprintf(
					/* translators: %s: excerpt of the widget's text. */
					__( 'Text: "%s"', 'mosmcp-abilities' ),
					$text
				);
			}

			$elements = Html_Policy::element_names( $html );

			if ( ! empty( $elements ) ) {
				return sprintf(
					/* translators: %s: list of HTML tags such as <div>, <script>. */
					__( 'Markup with no visible text: %s.', 'mosmcp-abilities' ),
					Html_Policy::tag_list( $elements )
				);
			}

			return __( 'No content yet.', 'mosmcp-abilities' );
		}

		$parts = array();

		foreach ( $visible as $key => $value ) {
			if ( 'title' === (string) $key || self::MASK === $value ) {
				continue;
			}

			if ( 'nav_menu' === $id_base && 'nav_menu' === (string) $key && is_numeric( $value ) ) {
				$menu    = wp_get_nav_menu_object( (int) $value );
				$parts[] = sprintf(
					/* translators: 1: menu name, 2: menu ID. */
					__( 'menu: %1$s (ID %2$d)', 'mosmcp-abilities' ),
					$menu ? $menu->name : __( 'missing menu', 'mosmcp-abilities' ),
					(int) $value
				);
				continue;
			}

			if ( is_bool( $value ) ) {
				$text = $value ? __( 'yes', 'mosmcp-abilities' ) : __( 'no', 'mosmcp-abilities' );
			} elseif ( is_scalar( $value ) ) {
				$text = wp_html_excerpt( (string) $value, 60, '...' );
			} elseif ( is_array( $value ) ) {
				$text = sprintf(
					/* translators: %d: number of items. */
					_n( '%d item', '%d items', count( $value ), 'mosmcp-abilities' ),
					count( $value )
				);
			} else {
				continue;
			}

			if ( '' === $text ) {
				continue;
			}

			$parts[] = $key . ': ' . $text;
		}

		return empty( $parts ) ? __( 'Default settings.', 'mosmcp-abilities' ) : wp_html_excerpt( implode( '; ', $parts ), 220, '...' );
	}

	/**
	 * Visible text of a piece of markup, collapsed to one line and shortened.
	 *
	 * @param string $html   Markup.
	 * @param int    $length Maximum characters.
	 * @return string
	 */
	public static function text_excerpt( $html, $length ) {
		// A space at each block-level boundary, so a heading and the paragraph after it do not run together.
		$html = (string) preg_replace( '#<(/?(?:p|div|h[1-6]|li|ul|ol|br|hr|section|figure|figcaption|blockquote|table|tr|td|th|header|footer|nav|article|aside|address|pre|dt|dd)\b)#i', ' <$1', (string) $html );
		$text = wp_strip_all_tags( $html );
		$text = trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( $text, ENT_QUOTES, 'UTF-8' ) ) );

		return wp_html_excerpt( $text, (int) $length, '...' );
	}

	/**
	 * Shortens text without touching its markup, for quoting a value back.
	 *
	 * @param string $text   Text.
	 * @param int    $length Maximum characters.
	 * @return string
	 */
	private static function shorten( $text, $length ) {
		$text = (string) $text;

		return mb_strlen( $text, 'UTF-8' ) > $length ? mb_substr( $text, 0, $length, 'UTF-8' ) . '...' : $text;
	}

	/**
	 * Names of every block in a parsed tree, depth first, outer blocks first.
	 *
	 * @param array<int, mixed> $blocks Parsed blocks.
	 * @return string[]
	 */
	public static function block_names( array $blocks ) {
		$names = array();

		foreach ( $blocks as $block ) {
			if ( ! empty( $block['blockName'] ) ) {
				$names[] = (string) $block['blockName'];
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$names = array_merge( $names, self::block_names( $block['innerBlocks'] ) );
			}
		}

		return $names;
	}

	/**
	 * Replaces the values of secret-looking settings with the mask.
	 *
	 * Widget settings are returned to a language model and from there into a
	 * transcript, so a map widget's API key or a newsletter widget's token is
	 * masked here rather than left to the caller.
	 *
	 * @param array<string, mixed> $settings Settings.
	 * @param string[]             $masked   Dotted paths of masked settings, appended to.
	 * @param string               $prefix   Path prefix for nested settings.
	 * @return array<string, mixed>
	 */
	public static function mask( array $settings, array &$masked, $prefix = '' ) {
		foreach ( $settings as $key => $value ) {
			$path = '' === $prefix ? (string) $key : $prefix . '.' . $key;

			if ( self::is_secret_key( (string) $key ) && ! self::is_blank( $value ) ) {
				$settings[ $key ] = self::MASK;
				$masked[]         = $path;
				continue;
			}

			if ( is_array( $value ) ) {
				$settings[ $key ] = self::mask( $value, $masked, $path );
			}
		}

		return $settings;
	}

	/**
	 * Whether a setting name looks like it holds a credential.
	 *
	 * @param string $key Setting name.
	 * @return bool
	 */
	public static function is_secret_key( $key ) {
		$key = (string) preg_replace( '/([a-z0-9])([A-Z])/', '$1_$2', (string) $key );
		$key = strtolower( (string) preg_replace( '/[^A-Za-z0-9]+/', '_', $key ) );

		return 1 === preg_match( self::SECRET_KEY_PATTERN, $key );
	}

	/**
	 * Whether a value is empty enough that masking it would hide nothing.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function is_blank( $value ) {
		return null === $value || false === $value || '' === $value || array() === $value;
	}

	/**
	 * Puts stored secrets back where the caller sent the mask placeholder.
	 *
	 * @param array<string, mixed>              $incoming Settings sent by the caller.
	 * @param array<string, mixed>              $current  Settings currently stored.
	 * @param array<int, array<string, string>> $warnings Warnings accumulator.
	 * @param string                            $prefix   Path prefix for nested settings.
	 * @return array<string, mixed>
	 */
	public static function unmask( array $incoming, array $current, array &$warnings, $prefix = '' ) {
		foreach ( $incoming as $key => $value ) {
			$path = '' === $prefix ? (string) $key : $prefix . '.' . $key;

			if ( self::MASK === $value ) {
				if ( array_key_exists( $key, $current ) ) {
					$incoming[ $key ] = $current[ $key ];
				} else {
					unset( $incoming[ $key ] );
					$warnings[] = array(
						'code'    => 'masked_value_dropped',
						'message' => sprintf(
							/* translators: %s: setting name. */
							__( 'The setting "%s" was sent as the masked placeholder, which stands for a stored secret, but there is no stored value to keep, so it was left out. Send the real value to set it.', 'mosmcp-abilities' ),
							$path
						),
						'context' => $path,
					);
				}
				continue;
			}

			if ( is_array( $value ) && isset( $current[ $key ] ) && is_array( $current[ $key ] ) ) {
				$incoming[ $key ] = self::unmask( $value, $current[ $key ], $warnings, $path );
			}
		}

		return $incoming;
	}

	/**
	 * Reads a widget type's settings from its own settings form.
	 *
	 * WordPress publishes no settings schema for a widget type; the form is the
	 * only description there is, and it is exactly what the Widgets screen
	 * submits. Rendering it once with blank settings gives every setting's name,
	 * kind and default, for core and plugin widgets alike.
	 *
	 * @param \WP_Widget           $widget   Widget object.
	 * @param array<string, mixed> $instance Settings to render the form with.
	 * @return array<int, array<string, mixed>> Fields in form order.
	 */
	public static function form_fields( $widget, array $instance = array() ) {
		if ( ! $widget instanceof \WP_Widget || ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			return array();
		}

		$previous = $widget->number;
		$html     = '';

		$rendered = self::quietly(
			static function () use ( $widget, $instance ) {
				$widget->_set( '__i__' );
				$widget->form( $instance );
				return true;
			},
			$html
		);

		if ( false !== $previous ) {
			$widget->_set( $previous );
		}

		if ( is_wp_error( $rendered ) || '' === trim( $html ) ) {
			return array();
		}

		return self::parse_form( $html, 'widget-' . $widget->id_base . '[__i__][' );
	}

	/**
	 * Extracts settings fields from a rendered widget form.
	 *
	 * @param string $html   Rendered form.
	 * @param string $prefix Field-name prefix every setting of this widget carries.
	 * @return array<int, array<string, mixed>>
	 */
	private static function parse_form( $html, $prefix ) {
		$processor = new \WP_HTML_Tag_Processor( $html );
		$fields    = array();
		$select    = '';

		while ( $processor->next_tag( array( 'tag_closers' => 'visit' ) ) ) {
			$tag = $processor->get_tag();

			if ( $processor->is_tag_closer() ) {
				if ( 'SELECT' === $tag ) {
					$select = '';
				}
				continue;
			}

			if ( 'OPTION' === $tag ) {
				if ( '' === $select || ! isset( $fields[ $select ] ) ) {
					continue;
				}

				$value = $processor->get_attribute( 'value' );
				$value = is_string( $value ) ? $value : '';

				$fields[ $select ]['options'][] = $value;

				if ( null !== $processor->get_attribute( 'selected' ) ) {
					$fields[ $select ]['default']  = $value;
					$fields[ $select ]['selected'] = true;
				} elseif ( null === $fields[ $select ]['default'] ) {
					$fields[ $select ]['default'] = $value;
				}
				continue;
			}

			if ( ! in_array( $tag, array( 'INPUT', 'SELECT', 'TEXTAREA' ), true ) ) {
				continue;
			}

			$name = $processor->get_attribute( 'name' );

			if ( ! is_string( $name ) || 0 !== strpos( $name, $prefix ) ) {
				continue;
			}

			$rest  = substr( $name, strlen( $prefix ) );
			$close = strpos( $rest, ']' );

			if ( false === $close || 0 === $close ) {
				continue;
			}

			$field  = substr( $rest, 0, $close );
			$suffix = substr( $rest, $close + 1 );
			$kind   = self::field_kind( $processor, $tag, $suffix );

			if ( '' === $kind ) {
				continue;
			}

			$existing = isset( $fields[ $field ] ) ? $fields[ $field ] : null;
			$entry    = null !== $existing ? $existing : array(
				'name'      => $field,
				'kind'      => $kind,
				'default'   => null,
				'options'   => array(),
				'on_value'  => '',
				'off_value' => null,
				'selected'  => false,
			);

			if ( 'checkbox' === $kind ) {
				$on = $processor->get_attribute( 'value' );
				$on = is_string( $on ) ? $on : 'on';

				// A hidden input before a checkbox of the same name is the value sent when it is unchecked.
				if ( null !== $existing && 'hidden' === $existing['kind'] ) {
					$entry['off_value'] = (string) $existing['default'];
				}

				$entry['kind']     = 'checkbox';
				$entry['on_value'] = $on;
				$entry['default']  = null !== $processor->get_attribute( 'checked' ) ? $on : '';
			} elseif ( 'radio' === $kind ) {
				$value              = $processor->get_attribute( 'value' );
				$value              = is_string( $value ) ? $value : 'on';
				$entry['kind']      = 'radio';
				$entry['options'][] = $value;

				if ( null !== $processor->get_attribute( 'checked' ) ) {
					$entry['default'] = $value;
				}
			} elseif ( 'select' === $kind || 'select_multiple' === $kind ) {
				$entry['kind'] = $kind;
				$select        = $field;
			} elseif ( 'textarea' === $kind ) {
				$entry['default'] = method_exists( $processor, 'get_modifiable_text' ) ? html_entity_decode( (string) $processor->get_modifiable_text(), ENT_QUOTES, 'UTF-8' ) : '';
			} elseif ( null === $existing ) {
				$value            = $processor->get_attribute( 'value' );
				$entry['default'] = is_string( $value ) ? $value : '';
			}

			$fields[ $field ] = $entry;
		}

		return array_values( $fields );
	}

	/**
	 * The kind of setting a form control represents, or '' to ignore it.
	 *
	 * @param \WP_HTML_Tag_Processor $processor Processor positioned on the control.
	 * @param string                 $tag       Upper-case tag name.
	 * @param string                 $suffix    Whatever follows the setting name in the field name.
	 * @return string
	 */
	private static function field_kind( $processor, $tag, $suffix ) {
		if ( '[]' === $suffix ) {
			return 'list';
		}

		if ( '' !== $suffix ) {
			return 'group';
		}

		if ( 'TEXTAREA' === $tag ) {
			return 'textarea';
		}

		if ( 'SELECT' === $tag ) {
			return null !== $processor->get_attribute( 'multiple' ) ? 'select_multiple' : 'select';
		}

		$type = $processor->get_attribute( 'type' );
		$type = is_string( $type ) ? strtolower( $type ) : 'text';

		if ( in_array( $type, array( 'submit', 'button', 'image', 'reset', 'file' ), true ) ) {
			return '';
		}

		return $type;
	}

	/**
	 * The values a blank copy of the widget's form would submit.
	 *
	 * Unchecked checkboxes are left out, exactly as a browser leaves them out.
	 *
	 * @param array<int, array<string, mixed>> $fields Fields from form_fields().
	 * @return array<string, mixed>
	 */
	public static function blank_values( array $fields ) {
		$values = array();

		foreach ( $fields as $field ) {
			$name = $field['name'];

			if ( in_array( $field['kind'], array( 'list', 'group', 'select_multiple' ), true ) ) {
				continue;
			}

			if ( 'checkbox' === $field['kind'] ) {
				if ( '' !== $field['default'] ) {
					$values[ $name ] = $field['default'];
				} elseif ( null !== $field['off_value'] ) {
					$values[ $name ] = $field['off_value'];
				}
				continue;
			}

			if ( null !== $field['default'] ) {
				$values[ $name ] = $field['default'];
			}
		}

		return $values;
	}

	/**
	 * Makes checkbox settings look the way the widget's form submits them.
	 *
	 * A form leaves an unchecked checkbox out entirely and sends a checked one as
	 * its value attribute. Widget code is written against that, and some of it
	 * tests only whether the key is present, so a stored false or a caller's
	 * "false" passed through unchanged would switch the option on.
	 *
	 * @param array<string, mixed>             $values Settings about to be saved.
	 * @param array<int, array<string, mixed>> $fields Fields from form_fields().
	 * @return array<string, mixed>
	 */
	public static function normalize_checkboxes( array $values, array $fields ) {
		foreach ( $fields as $field ) {
			$name = $field['name'];

			if ( 'checkbox' !== $field['kind'] || ! array_key_exists( $name, $values ) ) {
				continue;
			}

			if ( self::is_truthy( $values[ $name ] ) ) {
				$values[ $name ] = $field['on_value'];
			} elseif ( null !== $field['off_value'] ) {
				$values[ $name ] = $field['off_value'];
			} else {
				unset( $values[ $name ] );
			}
		}

		return $values;
	}

	/**
	 * Whether a checkbox-like value means "on".
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function is_truthy( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_array( $value ) || null === $value ) {
			return ! empty( $value );
		}

		$parsed = filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );

		return null === $parsed ? '' !== trim( (string) $value ) : $parsed;
	}

	/**
	 * Whether a value is one of the ways an on/off setting is written.
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	private static function is_switch_value( $value ) {
		return in_array( strtolower( trim( (string) $value ) ), array( '', '0', '1', 'true', 'false', 'on', 'off', 'yes', 'no' ), true );
	}

	/**
	 * Fields as returned to callers: without the parser's bookkeeping.
	 *
	 * @param array<int, array<string, mixed>> $fields Fields from form_fields().
	 * @return array<int, array<string, mixed>>
	 */
	public static function public_fields( array $fields ) {
		$out = array();

		foreach ( $fields as $field ) {
			if ( self::is_secret_key( $field['name'] ) && '' !== (string) $field['default'] ) {
				$field['default'] = self::MASK;
			}

			$out[] = array(
				'name'    => (string) $field['name'],
				'kind'    => (string) $field['kind'],
				'default' => null === $field['default'] ? '' : (string) $field['default'],
				'options' => array_values( array_map( 'strval', $field['options'] ) ),
			);
		}

		return $out;
	}

	/**
	 * Checks block markup before it is saved as a block widget.
	 *
	 * The block widget stores whatever it is given, so junk saved here shows on
	 * every page as stray text or as nothing at all. Refused: markup with no block
	 * in it, unbalanced block delimiters, attributes that are not valid JSON, and
	 * block types this site does not have.
	 *
	 * @param string                            $markup   Block markup.
	 * @param array<int, array<string, string>> $warnings Warnings accumulator.
	 * @return string[]|WP_Error Block names found, or an error.
	 */
	public static function check_block_markup( $markup, array &$warnings ) {
		$markup = (string) $markup;

		if ( '' === trim( $markup ) ) {
			return Site_Support::error(
				'mosmcp_widget_block_markup_empty',
				__( 'The block markup is empty. Pass at least one block, for example <!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_INVALID_INPUT,
				true
			);
		}

		if ( preg_match_all( self::BLOCK_DELIMITER, $markup, $delimiters, PREG_SET_ORDER ) ) {
			$open = array();

			foreach ( $delimiters as $delimiter ) {
				if ( ! empty( $delimiter['void'] ) ) {
					continue;
				}

				$name          = ( ! empty( $delimiter['namespace'] ) ? $delimiter['namespace'] : 'core/' ) . $delimiter['name'];
				$open[ $name ] = ( isset( $open[ $name ] ) ? $open[ $name ] : 0 ) + ( empty( $delimiter['closer'] ) ? 1 : -1 );
			}

			$unbalanced = array_keys( array_filter( $open ) );

			if ( ! empty( $unbalanced ) ) {
				return Site_Support::error(
					'mosmcp_widget_block_markup_unbalanced',
					sprintf(
						/* translators: %s: comma-separated block names. */
						__( 'The block markup is broken: these blocks are opened and closed a different number of times: %s. Every <!-- wp:name --> needs a matching <!-- /wp:name -->, or must be self-closing as <!-- wp:name /-->. Nothing was saved.', 'mosmcp-abilities' ),
						implode( ', ', $unbalanced )
					),
					Site_Support::CAUSE_INVALID_INPUT,
					true
				);
			}
		}

		$scan = array(
			'names'     => array(),
			'unknown'   => array(),
			'bad_attrs' => array(),
			'loose'     => false,
		);
		self::scan_blocks( parse_blocks( $markup ), $scan );

		if ( empty( $scan['names'] ) ) {
			return Site_Support::error(
				'mosmcp_widget_block_markup_no_blocks',
				__( 'The block markup contains no blocks; it looks like plain text or HTML. Wrap it in block comments, for example <!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->. For raw HTML use <!-- wp:html -->...<!-- /wp:html -->, or add a Custom HTML widget with type "custom_html" instead. Nothing was saved.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_INVALID_INPUT,
				true
			);
		}

		if ( ! empty( $scan['unknown'] ) ) {
			$unknown = array_values( array_unique( $scan['unknown'] ) );

			return Site_Support::error(
				'mosmcp_widget_block_type_unknown',
				sprintf(
					/* translators: 1: comma-separated unknown block names, 2: suggestions sentence (may be empty). */
					__( 'These block types are not registered on this site, so they would show nothing: %1$s.%2$s Use mosmcp/block-types-list to see the blocks this site has. Nothing was saved.', 'mosmcp-abilities' ),
					implode( ', ', $unknown ),
					self::block_suggestions( $unknown )
				),
				Site_Support::CAUSE_INVALID_INPUT,
				true,
				array( 'unknown_blocks' => $unknown )
			);
		}

		if ( ! empty( $scan['bad_attrs'] ) ) {
			return Site_Support::error(
				'mosmcp_widget_block_attributes_invalid',
				sprintf(
					/* translators: %s: comma-separated block names. */
					__( 'The settings inside these block comments are not valid JSON: %s. Block settings are written as a JSON object, for example <!-- wp:heading {"level":3} -->. Nothing was saved.', 'mosmcp-abilities' ),
					implode( ', ', array_values( array_unique( $scan['bad_attrs'] ) ) )
				),
				Site_Support::CAUSE_INVALID_INPUT,
				true
			);
		}

		if ( $scan['loose'] ) {
			$warnings[] = array(
				'code'    => 'content_outside_blocks',
				'message' => __( 'Some of the markup sits outside any block. It is saved and shown as classic HTML, but the block editor will show it as a Classic block rather than as separate blocks.', 'mosmcp-abilities' ),
				'context' => '',
			);
		}

		return array_values( array_unique( $scan['names'] ) );
	}

	/**
	 * Walks parsed blocks collecting names, unknown types and broken attributes.
	 *
	 * @param array<int, mixed>    $blocks Parsed blocks.
	 * @param array<string, mixed> $scan   Accumulator.
	 * @return void
	 */
	private static function scan_blocks( array $blocks, array &$scan ) {
		$registry = \WP_Block_Type_Registry::get_instance();

		foreach ( $blocks as $block ) {
			$name = isset( $block['blockName'] ) ? $block['blockName'] : null;

			if ( null === $name ) {
				if ( isset( $block['innerHTML'] ) && '' !== trim( (string) $block['innerHTML'] ) ) {
					$scan['loose'] = true;
				}
				continue;
			}

			$scan['names'][] = (string) $name;

			if ( ! $registry->is_registered( $name ) ) {
				$scan['unknown'][] = (string) $name;
			}

			if ( ! isset( $block['attrs'] ) || ! is_array( $block['attrs'] ) ) {
				$scan['bad_attrs'][] = (string) $name;
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				self::scan_blocks( $block['innerBlocks'], $scan );
			}
		}
	}

	/**
	 * Suggests registered block names close to mistyped ones.
	 *
	 * @param string[] $unknown Unknown block names.
	 * @return string A leading-space sentence, or ''.
	 */
	private static function block_suggestions( array $unknown ) {
		$registered  = array_keys( \WP_Block_Type_Registry::get_instance()->get_all_registered() );
		$suggestions = array();

		foreach ( $unknown as $name ) {
			$best     = '';
			$distance = 4;

			foreach ( $registered as $candidate ) {
				$score = levenshtein( $name, (string) $candidate );
				if ( $score < $distance ) {
					$distance = $score;
					$best     = (string) $candidate;
				}
			}

			if ( '' !== $best ) {
				$suggestions[] = sprintf( '%1$s -> %2$s', $name, $best );
			}
		}

		if ( empty( $suggestions ) ) {
			return '';
		}

		return ' ' . sprintf(
			/* translators: %s: comma-separated "typed -> suggested" pairs. */
			__( 'Did you mean: %s?', 'mosmcp-abilities' ),
			implode( ', ', $suggestions )
		);
	}

	/**
	 * Describes a widget's HTML setting for messages.
	 *
	 * @param string $id_base   Widget type.
	 * @param bool   $untouched Whether the caller left this setting unchanged.
	 * @return string
	 */
	public static function html_where( $id_base, $untouched ) {
		if ( 'block' === $id_base ) {
			$where = __( 'the content of this block widget', 'mosmcp-abilities' );
		} elseif ( 'custom_html' === $id_base ) {
			$where = __( 'the content of this Custom HTML widget', 'mosmcp-abilities' );
		} else {
			$where = __( 'the text of this Text widget', 'mosmcp-abilities' );
		}

		if ( $untouched ) {
			$where = sprintf(
				/* translators: %s: what is being saved, such as "the content of this Custom HTML widget". */
				__( '%s (which this change did not touch, but which the widget saves again with every change)', 'mosmcp-abilities' ),
				$where
			);
		}

		return $where;
	}

	/**
	 * Applies the library's HTML rule to markup a widget is about to store.
	 *
	 * @param string                            $value    Markup.
	 * @param string                            $where    What is being written, for messages.
	 * @param array<int, array<string, string>> $warnings Warnings accumulator.
	 * @return string|WP_Error The value to save, or a refusal carrying the error contract.
	 */
	public static function apply_html_policy( $value, $where, array &$warnings ) {
		$result = Html_Policy::apply( (string) $value, Html_Policy::restriction(), $where, $warnings );

		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();

			return Site_Support::error(
				$result->get_error_code(),
				$result->get_error_message(),
				Site_Support::CAUSE_CAPABILITY,
				false,
				is_array( $data ) ? $data : array()
			);
		}

		return $result;
	}

	/**
	 * Runs widget code with its output and notices kept out of the response.
	 *
	 * Widget forms print HTML, and widget update() methods commonly read settings
	 * keys without checking them, which raises notices. Either one printed on a
	 * site with errors displayed would land in the middle of the JSON response.
	 * An exception thrown by widget code is returned as an error rather than
	 * ending the request.
	 *
	 * @param callable $callback Code to run.
	 * @param string   $output   Whatever the code printed.
	 * @return mixed|WP_Error The callback's return value, or an error.
	 */
	public static function quietly( callable $callback, &$output = null ) {
		$level = ob_get_level();
		ob_start();

		try {
			$result = call_user_func( $callback );
		} catch ( \Throwable $e ) {
			$result = Site_Support::error(
				'mosmcp_widget_code_failed',
				sprintf(
					/* translators: %s: the error message. */
					__( 'The widget\'s own code failed while running, so nothing was changed: %s', 'mosmcp-abilities' ),
					$e->getMessage()
				),
				Site_Support::CAUSE_WP_CORE,
				false
			);
		}

		$output = '';
		while ( ob_get_level() > $level ) {
			$output = (string) ob_get_clean() . $output;
		}

		return $result;
	}

	/**
	 * The next free instance number for a widget type.
	 *
	 * Looks at stored settings, registered widgets and placed widget IDs alike, so
	 * a number still referenced anywhere is never reused.
	 *
	 * @param \WP_Widget $widget Widget object.
	 * @return int
	 */
	public static function next_number( $widget ) {
		global $wp_registered_widgets;

		$highest = 1;

		foreach ( array_keys( self::stored_instances( $widget ) ) as $number ) {
			if ( is_numeric( $number ) ) {
				$highest = max( $highest, (int) $number );
			}
		}

		$ids = is_array( $wp_registered_widgets ) ? array_map( 'strval', array_keys( $wp_registered_widgets ) ) : array();
		foreach ( self::sidebars() as $area_ids ) {
			$ids = array_merge( $ids, $area_ids );
		}

		$pattern = '/^' . preg_quote( $widget->id_base, '/' ) . '-(\d+)$/';
		foreach ( $ids as $id ) {
			if ( preg_match( $pattern, $id, $matches ) ) {
				$highest = max( $highest, (int) $matches[1] );
			}
		}

		return $highest + 1;
	}

	/**
	 * Takes a widget out of every area and, optionally, puts it in one.
	 *
	 * Only the widget being moved changes. Other areas, including ones the theme
	 * no longer registers, are written back exactly as they were stored.
	 *
	 * @param string   $widget_id Widget ID.
	 * @param string   $target    Area to place it in, or '' to remove it from every area.
	 * @param int|null $position  0-based position, or null for the end of the area.
	 * @return int The position it was placed at (0 when removed).
	 */
	public static function relocate( $widget_id, $target, $position ) {
		$raw = get_option( 'sidebars_widgets', array() );
		$raw = is_array( $raw ) ? $raw : array();

		foreach ( $raw as $area => $ids ) {
			if ( 'array_version' === (string) $area || ! is_array( $ids ) || ! in_array( $widget_id, $ids, true ) ) {
				continue;
			}

			$kept = array();
			foreach ( $ids as $id ) {
				if ( $id !== $widget_id ) {
					$kept[] = $id;
				}
			}
			$raw[ $area ] = $kept;
		}

		$index = 0;

		if ( '' !== $target ) {
			$ids   = ( isset( $raw[ $target ] ) && is_array( $raw[ $target ] ) ) ? array_values( $raw[ $target ] ) : array();
			$index = ( null === $position ) ? count( $ids ) : max( 0, min( (int) $position, count( $ids ) ) );

			array_splice( $ids, $index, 0, array( $widget_id ) );
			$raw[ $target ] = $ids;
		}

		wp_set_sidebars_widgets( $raw );

		return $index;
	}

	/**
	 * Warnings for settings the widget changed or dropped while saving.
	 *
	 * Widgets sanitize silently: a title loses its markup, an out-of-range number
	 * is replaced, and a setting the widget does not have is simply not kept.
	 * Reporting the difference is what stops "saved" being read as "saved as sent".
	 *
	 * @param array<string, mixed> $sent   Settings the caller supplied.
	 * @param array<string, mixed> $stored Settings as stored.
	 * @param string[]             $skip   Settings checked elsewhere.
	 * @return array<int, array<string, string>>
	 */
	public static function adjusted_warnings( array $sent, array $stored, array $skip ) {
		$warnings = array();
		$dropped  = array();
		$adjusted = array();

		foreach ( $sent as $key => $value ) {
			$key = (string) $key;

			if ( in_array( $key, $skip, true ) ) {
				continue;
			}

			if ( ! array_key_exists( $key, $stored ) ) {
				$dropped[] = $key;
				continue;
			}

			$after = $stored[ $key ];

			if ( ! is_string( $value ) || ! is_scalar( $after ) || is_bool( $after ) || self::is_secret_key( $key ) ) {
				continue;
			}

			// An on/off value saved in the widget's own on/off form ("false" stored as 0) is not an adjustment.
			if ( self::is_switch_value( $value ) && self::is_switch_value( (string) $after ) && self::is_truthy( $value ) === self::is_truthy( $after ) ) {
				continue;
			}

			if ( (string) $after !== $value ) {
				$adjusted[] = sprintf( '%1$s: sent "%2$s", saved "%3$s"', $key, self::shorten( $value, 60 ), self::shorten( (string) $after, 60 ) );
			}
		}

		if ( ! empty( $dropped ) ) {
			$warnings[] = array(
				'code'    => 'settings_not_kept',
				'message' => sprintf(
					/* translators: %s: comma-separated setting names. */
					__( 'The widget did not keep these settings, usually because it has no setting by that name or rejected the value: %s. mosmcp/widget-list-types lists the settings each widget type has.', 'mosmcp-abilities' ),
					implode( ', ', $dropped )
				),
				'context' => implode( ',', $dropped ),
			);
		}

		if ( ! empty( $adjusted ) ) {
			$warnings[] = array(
				'code'    => 'settings_adjusted_on_save',
				'message' => sprintf(
					/* translators: %s: semicolon-separated list of changes. */
					__( 'The widget\'s own validation changed these values while saving: %s.', 'mosmcp-abilities' ),
					implode( '; ', $adjusted )
				),
				'context' => '',
			);
		}

		return $warnings;
	}

	/**
	 * The HTML a widget outputs on the site.
	 *
	 * @param string   $widget_id Widget ID.
	 * @param string   $area      Area the widget is in.
	 * @param string[] $notes     Notes accumulator.
	 * @return string
	 */
	public static function render( $widget_id, $area, array &$notes ) {
		if ( self::STATUS_ACTIVE !== self::area_status( $area ) ) {
			$notes[] = __( 'This widget is not in an area the theme shows, so it produces no output on the site.', 'mosmcp-abilities' );
			return '';
		}

		if ( ! function_exists( 'wp_render_widget' ) ) {
			return '';
		}

		$printed  = '';
		$rendered = self::quietly(
			static function () use ( $widget_id, $area ) {
				return wp_render_widget( $widget_id, $area );
			},
			$printed
		);

		if ( ! is_string( $rendered ) ) {
			$notes[] = __( 'The widget could not be rendered here.', 'mosmcp-abilities' );
			return '';
		}

		if ( strlen( $rendered ) > self::RENDER_LIMIT ) {
			// Drop any trailing bytes of a multi-byte character the cut split, so the JSON stays valid.
			$rendered = (string) preg_replace( '/[\x80-\xFF]+$/', '', substr( $rendered, 0, self::RENDER_LIMIT ) );
			$notes[]  = sprintf(
				/* translators: %d: number of characters. */
				__( 'The rendered HTML was cut to its first %d characters.', 'mosmcp-abilities' ),
				self::RENDER_LIMIT
			);
		}

		return $rendered;
	}
}
