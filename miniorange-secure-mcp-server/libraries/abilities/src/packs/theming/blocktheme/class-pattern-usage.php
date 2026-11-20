<?php
/**
 * Where a saved (synced) pattern is used across the site.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Theming\Blocktheme;

use MoSMCP\Abilities\Packs\Blocks\Block_Patterns;
use MoSMCP\Abilities\Packs\Blocks\Block_Tree;
use MoSMCP\Abilities\Packs\Site\Site_Support;
use MoSMCP\Abilities\Packs\Widgets\Widgets_Support;
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
 * Class Pattern_Usage
 *
 * A synced pattern is placed by reference, <!-- wp:block {"ref":12} /-->, which
 * can sit in any block content: pages and posts, templates and template parts
 * customised in the Site Editor, Navigation menus, other patterns, and block
 * widgets. Changing or deleting the pattern changes all of them, so this finds
 * every one before that happens.
 */
class Pattern_Usage {

	/**
	 * Post statuses whose content can be seen or published.
	 *
	 * @var string[]
	 */
	const STATUSES = array( 'publish', 'future', 'draft', 'pending', 'private' );

	/**
	 * Most places reported.
	 */
	const LIMIT = 200;

	/**
	 * Most bytes of content one publish may back up and rewrite to detach a
	 * deleted pattern: the backup is a single database write, which must stay
	 * under the smallest common MySQL packet limit (4 MB) once escaped.
	 */
	const MAX_DETACH_BYTES = 3 * 1024 * 1024;

	/**
	 * Every place a pattern is used.
	 *
	 * @param int $id Pattern post ID.
	 * @return array{places:array<int, array<string, mixed>>,total:int,truncated:bool}
	 */
	public static function find( $id ) {
		global $wpdb;

		$id     = (int) $id;
		$places = array();
		$like   = '%' . $wpdb->esc_like( '"ref":' . $id ) . '%';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A substring search across every post type's content has no WordPress API. Results are verified by parsing below.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_content LIKE %s AND post_type NOT IN ('revision', 'customize_changeset') AND post_status IN (" . implode( ', ', array_fill( 0, count( self::STATUSES ), '%s' ) ) . ') AND ID <> %d ORDER BY post_modified DESC LIMIT %d',
				array_merge( array( $like ), self::STATUSES, array( $id, self::LIMIT * 2 ) )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		foreach ( (array) $rows as $row ) {
			$post = get_post( (int) $row->ID );
			if ( ! $post ) {
				continue;
			}
			// "ref":12 also matches "ref":123; only a parsed reference counts.
			$count = Block_Patterns::count_references( Block_Tree::parse( $post->post_content ), $id );
			if ( $count < 1 ) {
				continue;
			}
			$places[] = self::post_place( $post, $count );
		}

		$widgets = self::widgets( $id );
		$total   = count( $places ) + count( $widgets );

		if ( count( (array) $rows ) >= self::LIMIT * 2 ) {
			// More matches than were read: count the rest without parsing them.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Same query as above, counted.
			$all = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_content LIKE %s AND post_type NOT IN ('revision', 'customize_changeset') AND post_status IN (" . implode( ', ', array_fill( 0, count( self::STATUSES ), '%s' ) ) . ') AND ID <> %d',
					array_merge( array( $like ), self::STATUSES, array( $id ) )
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$total = max( $total, $all + count( $widgets ) );
		}

		foreach ( $widgets as $place ) {
			$places[] = $place;
		}

		return array(
			'places'    => array_slice( $places, 0, self::LIMIT ),
			'total'     => $total,
			'truncated' => $total > self::LIMIT,
		);
	}

	/**
	 * Refuses turning every use of a pattern into ordinary blocks when there are
	 * more than one publish can back up and rewrite safely.
	 *
	 * @param string                                                                  $title Pattern title.
	 * @param array{places:array<int, array<string, mixed>>,total:int,truncated:bool} $usage Usage from find().
	 * @return true|WP_Error
	 */
	public static function detachable( $title, array $usage ) {
		if ( $usage['truncated'] ) {
			return Site_Support::error(
				'mosmcp_pattern_too_widely_used',
				sprintf(
					/* translators: 1: pattern title, 2: number of places. */
					__( 'The pattern "%1$s" is used in %2$d places. Turning that many into ordinary blocks is more than one publish can do safely, because every place is backed up first so the publish can be undone. Detach it in some places first with mosmcp/block-detach-pattern, or keep the pattern.', 'mosmcp-abilities' ),
					$title,
					(int) $usage['total']
				),
				Site_Support::CAUSE_CONFLICT,
				true,
				array( 'use_instead' => 'mosmcp/block-detach-pattern' )
			);
		}

		$bytes = 0;
		foreach ( $usage['places'] as $place ) {
			$post   = 'widget' === $place['type'] ? null : get_post( (int) $place['id'] );
			$bytes += $post ? strlen( $post->post_content ) : 0;
		}
		if ( $bytes > self::MAX_DETACH_BYTES ) {
			return Site_Support::error(
				'mosmcp_pattern_too_widely_used',
				sprintf(
					/* translators: 1: pattern title, 2: number of places. */
					__( 'The %2$d places that use the pattern "%1$s" hold more content than one publish can back up, and each place is backed up before it becomes ordinary blocks so the publish can be undone. Detach it in some places first with mosmcp/block-detach-pattern, or keep the pattern.', 'mosmcp-abilities' ),
					$title,
					(int) $usage['total']
				),
				Site_Support::CAUSE_CONFLICT,
				true,
				array( 'use_instead' => 'mosmcp/block-detach-pattern' )
			);
		}

		return true;
	}

	/**
	 * Block widgets that use a pattern.
	 *
	 * @param int $id Pattern post ID.
	 * @return array<int, array<string, mixed>>
	 */
	public static function widgets( $id ) {
		$places    = array();
		$instances = get_option( 'widget_block', array() );
		if ( ! is_array( $instances ) ) {
			return $places;
		}

		$where = self::widget_areas();
		foreach ( $instances as $number => $instance ) {
			if ( ! is_array( $instance ) || empty( $instance['content'] ) || ! is_numeric( $number ) ) {
				continue;
			}
			$count = Block_Patterns::count_references( Block_Tree::parse( (string) $instance['content'] ), $id );
			if ( $count < 1 ) {
				continue;
			}
			$widget   = 'block-' . (int) $number;
			$places[] = array(
				'type'       => 'widget',
				'id'         => 0,
				'widget_id'  => $widget,
				'title'      => isset( $where[ $widget ] ) ? $where[ $widget ] : __( 'Inactive widgets', 'mosmcp-abilities' ),
				'status'     => isset( $where[ $widget ] ) ? 'active' : 'inactive',
				'count'      => $count,
				'edit_url'   => admin_url( 'widgets.php' ),
				'view_url'   => '',
				'type_label' => __( 'Block widget', 'mosmcp-abilities' ),
			);
		}

		return $places;
	}

	/**
	 * Builds the row for a post that uses the pattern.
	 *
	 * @param \WP_Post $post  Post.
	 * @param int      $count References in it.
	 * @return array<string, mixed>
	 */
	private static function post_place( $post, $count ) {
		$type   = get_post_type_object( $post->post_type );
		$public = $type && ! empty( $type->public ) && 'publish' === $post->post_status;
		$title  = (string) get_the_title( $post );
		if ( '' === $title ) {
			$title = in_array( $post->post_type, array( 'wp_template', 'wp_template_part' ), true ) ? (string) $post->post_name : __( '(no title)', 'mosmcp-abilities' );
		}

		return array(
			'type'       => (string) $post->post_type,
			'id'         => (int) $post->ID,
			'widget_id'  => '',
			'title'      => $title,
			'status'     => (string) $post->post_status,
			'count'      => (int) $count,
			'edit_url'   => current_user_can( 'edit_post', $post->ID ) ? (string) get_edit_post_link( $post->ID, 'raw' ) : '',
			'view_url'   => $public ? (string) get_permalink( $post ) : '',
			'type_label' => $type && isset( $type->labels->singular_name ) ? (string) $type->labels->singular_name : (string) $post->post_type,
		);
	}

	/**
	 * Which area each active widget sits in.
	 *
	 * @return array<string, string> Widget ID => area name.
	 */
	private static function widget_areas() {
		global $wp_registered_sidebars;

		$map = array();
		// The stored areas, read as the widget abilities read them.
		foreach ( Widgets_Support::sidebars() as $area => $widgets ) {
			if ( 'wp_inactive_widgets' === $area ) {
				continue;
			}
			$name = isset( $wp_registered_sidebars[ $area ]['name'] ) ? (string) $wp_registered_sidebars[ $area ]['name'] : (string) $area;
			foreach ( $widgets as $widget ) {
				$map[ (string) $widget ] = $name;
			}
		}
		return $map;
	}

	/**
	 * A one-line description of where a pattern is used.
	 *
	 * @param array<int, array<string, mixed>> $places Places.
	 * @param int                              $limit  How many to name.
	 * @return string
	 */
	public static function describe( array $places, $limit = 5 ) {
		$names = array();
		foreach ( array_slice( $places, 0, $limit ) as $place ) {
			$names[] = sprintf( '%1$s "%2$s"', strtolower( (string) $place['type_label'] ), (string) $place['title'] );
		}
		$more = count( $places ) - count( $names );
		if ( $more > 0 ) {
			/* translators: %d: number of further places. */
			$names[] = sprintf( _n( '%d more', '%d more', $more, 'mosmcp-abilities' ), $more );
		}
		return wp_sprintf( '%l', $names );
	}
}
