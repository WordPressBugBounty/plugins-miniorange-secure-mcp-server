<?php
/**
 * Clears page caches after an ability changes the site.
 *
 * The most common way an AI edit looks broken is a page cache still serving the
 * old version: the change was saved, but the person checking it sees nothing
 * different. Many cache plugins clear a post's page when it is saved, but not
 * when only its settings, a menu, a widget or a site option changes, and host or
 * CDN caches often clear nothing on their own.
 *
 * While a writing ability runs, this records what it touched: individual posts,
 * or something that appears on every page (menus, widgets, theme settings,
 * options, templates). When the ability succeeds, exactly those pages are
 * cleared in every cache detected on the site, or the whole page cache when a
 * site-wide change was made. Purging never fails the ability: a cache that
 * cannot be cleared is skipped.
 *
 * Clearing one page is cheap, so it always happens straight away. Clearing a
 * whole cache makes every page rebuild on its next visit, so it runs at most once
 * a minute: a change made sooner than that after the last full clear clears what
 * it can page by page now, and one full clear is queued for the end of the minute
 * to cover everything changed in between.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Support;

use MoSMCP\Abilities\Config;

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
 * Class Cache_Purger
 */
class Cache_Purger {

	/**
	 * Post types whose content appears across the site rather than on one page.
	 *
	 * @var string[]
	 */
	const SITE_WIDE_TYPES = array( 'nav_menu_item', 'wp_navigation', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_block', 'custom_css' );

	/**
	 * Option name prefixes that never affect what visitors see.
	 *
	 * @var string[]
	 */
	const QUIET_OPTIONS = array( '_transient', '_site_transient', 'cron', 'mosmcp_', 'recently_edited', 'recently_activated', 'auto_updater', 'can_compress_scripts', 'db_upgraded', 'fresh_site', 'uninstall_plugins' );

	/**
	 * Post meta key prefixes that never affect what visitors see.
	 *
	 * @var string[]
	 */
	const QUIET_META = array( '_edit_', '_wp_old_', '_encloseme', '_pingme', '_mosmcp_' );

	/**
	 * Nesting depth of tracked ability calls (an ability may run another).
	 *
	 * @var int
	 */
	private static $depth = 0;

	/**
	 * Posts changed during the outermost tracked call.
	 *
	 * @var array<int, bool> Post ID => whether it was published before the change.
	 */
	private static $posts = array();

	/**
	 * Whether the outermost tracked call changed something shown on every page.
	 *
	 * @var bool
	 */
	private static $site_wide = false;

	/**
	 * Whether caches were already cleared in full during the tracked call.
	 *
	 * @var bool
	 */
	private static $purged = false;

	/**
	 * A note for the AI when the last call's full clear was queued.
	 *
	 * @var string
	 */
	private static $note = '';

	/**
	 * Listens for a queued full clear, which runs in a later request.
	 *
	 * @return void
	 */
	public static function boot() {
		add_action( Config::prefix() . '_deferred_cache_purge', array( __CLASS__, 'run_deferred' ) );
	}

	/**
	 * Whether automatic purging after writes is on.
	 *
	 * @return bool
	 */
	public static function enabled() {
		/**
		 * Filters whether page caches are cleared automatically after an ability
		 * changes the site.
		 *
		 * @param bool $enabled Default true.
		 */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Prefixed via Config::prefix(); the library's prefix is host-configurable and cannot be a literal.
		return (bool) apply_filters( Config::prefix() . '_auto_purge_cache', true );
	}

	/**
	 * Starts recording what the running ability changes.
	 *
	 * @return void
	 */
	public static function begin() {
		++self::$depth;

		if ( 1 !== self::$depth ) {
			return;
		}

		self::$posts     = array();
		self::$site_wide = false;
		self::$purged    = false;
		self::$note      = '';

		add_action( 'save_post', array( __CLASS__, 'on_save_post' ), 99, 2 );
		add_action( 'transition_post_status', array( __CLASS__, 'on_transition' ), 99, 3 );
		add_action( 'deleted_post', array( __CLASS__, 'on_post_id' ), 99 );
		add_action( 'added_post_meta', array( __CLASS__, 'on_post_meta' ), 99, 3 );
		add_action( 'updated_post_meta', array( __CLASS__, 'on_post_meta' ), 99, 3 );
		add_action( 'deleted_post_meta', array( __CLASS__, 'on_post_meta' ), 99, 3 );
		add_action( 'set_object_terms', array( __CLASS__, 'on_post_id' ), 99 );
		add_action( 'added_option', array( __CLASS__, 'on_option' ), 99 );
		add_action( 'updated_option', array( __CLASS__, 'on_option' ), 99 );
		add_action( 'deleted_option', array( __CLASS__, 'on_option' ), 99 );
		add_action( 'wp_update_nav_menu', array( __CLASS__, 'on_site_wide' ), 99 );
		add_action( 'wp_delete_nav_menu', array( __CLASS__, 'on_site_wide' ), 99 );
		add_action( 'edited_term', array( __CLASS__, 'on_site_wide' ), 99 );
		add_action( 'delete_term', array( __CLASS__, 'on_site_wide' ), 99 );
		add_action( 'switch_theme', array( __CLASS__, 'on_site_wide' ), 99 );
		add_action( 'customize_save_after', array( __CLASS__, 'on_site_wide' ), 99 );
	}

	/**
	 * Stops recording and, for the outermost call that succeeded, clears caches.
	 *
	 * @param bool $succeeded Whether the ability succeeded.
	 * @return void
	 */
	public static function end( $succeeded ) {
		self::$depth = max( 0, self::$depth - 1 );

		if ( 0 !== self::$depth ) {
			return;
		}

		remove_action( 'save_post', array( __CLASS__, 'on_save_post' ), 99 );
		remove_action( 'transition_post_status', array( __CLASS__, 'on_transition' ), 99 );
		remove_action( 'deleted_post', array( __CLASS__, 'on_post_id' ), 99 );
		remove_action( 'added_post_meta', array( __CLASS__, 'on_post_meta' ), 99 );
		remove_action( 'updated_post_meta', array( __CLASS__, 'on_post_meta' ), 99 );
		remove_action( 'deleted_post_meta', array( __CLASS__, 'on_post_meta' ), 99 );
		remove_action( 'set_object_terms', array( __CLASS__, 'on_post_id' ), 99 );
		remove_action( 'added_option', array( __CLASS__, 'on_option' ), 99 );
		remove_action( 'updated_option', array( __CLASS__, 'on_option' ), 99 );
		remove_action( 'deleted_option', array( __CLASS__, 'on_option' ), 99 );
		remove_action( 'wp_update_nav_menu', array( __CLASS__, 'on_site_wide' ), 99 );
		remove_action( 'wp_delete_nav_menu', array( __CLASS__, 'on_site_wide' ), 99 );
		remove_action( 'edited_term', array( __CLASS__, 'on_site_wide' ), 99 );
		remove_action( 'delete_term', array( __CLASS__, 'on_site_wide' ), 99 );
		remove_action( 'switch_theme', array( __CLASS__, 'on_site_wide' ), 99 );
		remove_action( 'customize_save_after', array( __CLASS__, 'on_site_wide' ), 99 );

		if ( $succeeded && ! self::$purged ) {
			self::purge_changes();
		}

		self::$posts     = array();
		self::$site_wide = false;
	}

	/**
	 * Records a saved post.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post.
	 * @return void
	 */
	public static function on_save_post( $post_id, $post ) {
		if ( ! $post instanceof \WP_Post || 'revision' === $post->post_type || 'auto-draft' === $post->post_status ) {
			return;
		}

		self::record_post( (int) $post_id, $post->post_type );
	}

	/**
	 * Records a post leaving the published state, so its cached page is cleared.
	 *
	 * @param string   $new_status New status.
	 * @param string   $old_status Old status.
	 * @param \WP_Post $post       Post.
	 * @return void
	 */
	public static function on_transition( $new_status, $old_status, $post ) {
		if ( 'publish' === $old_status && 'publish' !== $new_status && $post instanceof \WP_Post ) {
			self::$posts[ (int) $post->ID ] = true;
		}
	}

	/**
	 * Records a change to one post by ID.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function on_post_id( $post_id ) {
		$type = get_post_type( (int) $post_id );
		if ( $type && 'revision' !== $type ) {
			self::record_post( (int) $post_id, $type );
		}
	}

	/**
	 * Records a post meta change that can affect the page.
	 *
	 * @param mixed  $meta_ids  Meta ID(s).
	 * @param int    $object_id Post ID.
	 * @param string $meta_key  Meta key.
	 * @return void
	 */
	public static function on_post_meta( $meta_ids, $object_id, $meta_key ) {
		unset( $meta_ids );

		foreach ( self::QUIET_META as $prefix ) {
			if ( 0 === strpos( (string) $meta_key, $prefix ) ) {
				return;
			}
		}

		self::on_post_id( $object_id );
	}

	/**
	 * Records an option change, which can show on any page.
	 *
	 * @param string $option Option name.
	 * @return void
	 */
	public static function on_option( $option ) {
		$option = (string) $option;

		foreach ( self::QUIET_OPTIONS as $prefix ) {
			if ( 0 === strpos( $option, $prefix ) ) {
				return;
			}
		}

		if ( 0 === strpos( $option, Config::prefix() . '_' ) ) {
			return;
		}

		self::$site_wide = true;
	}

	/**
	 * Records a change that shows on every page.
	 *
	 * @return void
	 */
	public static function on_site_wide() {
		self::$site_wide = true;
	}

	/**
	 * Notes that every cache was just cleared in full, so the automatic clear-up
	 * after the running ability is skipped rather than repeated.
	 *
	 * @return void
	 */
	public static function mark_purged() {
		self::$purged = true;
		self::remember_full_purge();
	}

	/**
	 * Returns, and forgets, the note about a queued full clear for the last call.
	 *
	 * @return string Empty when the call's caches were cleared straight away.
	 */
	public static function take_note() {
		$note       = self::$note;
		self::$note = '';

		return $note;
	}

	/**
	 * Runs a queued full clear.
	 *
	 * @return void
	 */
	public static function run_deferred() {
		if ( self::enabled() ) {
			self::purge_all( false );
		}
	}

	/**
	 * Clears every detected cache in full.
	 *
	 * @param bool $include_assets Also clear generated assets (Elementor CSS) and the object cache.
	 * @return array<string, mixed> 'object_cache_flushed' and per-engine results under 'engines'.
	 */
	public static function purge_all( $include_assets = true ) {
		self::$purged = true;
		self::remember_full_purge();
		$flushed = $include_assets ? (bool) wp_cache_flush() : false;
		$engines = array();

		foreach ( self::engines( $include_assets ) as $name => $engine ) {
			$engines[] = self::run( $name, $engine['all'] );
		}

		return array(
			'object_cache_flushed' => $flushed,
			'engines'              => $engines,
		);
	}

	/**
	 * Clears the pages of specific posts in every detected cache.
	 *
	 * An engine that cannot clear a single page is cleared in full instead, so
	 * the change is never left stale; within a minute of the last full clear, that
	 * full clear is queued rather than repeated.
	 *
	 * @param int[] $post_ids Post IDs.
	 * @return array<int, array<string, mixed>> Per-engine results.
	 */
	public static function purge_posts( array $post_ids ) {
		$post_ids = array_values( array_unique( array_filter( array_map( 'absint', $post_ids ) ) ) );
		$results  = array();

		if ( empty( $post_ids ) ) {
			return $results;
		}

		$wait = self::full_purge_wait();
		$full = false;

		foreach ( self::engines( false ) as $name => $engine ) {
			if ( empty( $engine['post'] ) ) {
				if ( $wait > 0 ) {
					self::queue_full_purge( $wait );
					continue;
				}

				$results[] = self::run( $name, $engine['all'] );
				$full      = true;
				continue;
			}

			$per_post  = $engine['post'];
			$results[] = self::run(
				$name,
				static function () use ( $per_post, $post_ids ) {
					foreach ( $post_ids as $id ) {
						$per_post( $id );
					}
					return true;
				}
			);
		}

		if ( $full ) {
			self::remember_full_purge();
		}

		return $results;
	}

	/**
	 * Clears what the tracked call changed.
	 *
	 * @return void
	 */
	private static function purge_changes() {
		if ( ! self::enabled() ) {
			return;
		}

		$wait = self::$site_wide ? self::full_purge_wait() : 0;

		if ( self::$site_wide && 0 === $wait ) {
			self::purge_all( false );
			return;
		}

		$targets = array();
		foreach ( self::$posts as $id => $was_public ) {
			if ( $was_public || 'publish' === get_post_status( $id ) ) {
				$targets[] = (int) $id;
			}
		}

		if ( $targets ) {
			self::purge_posts( $targets );
		}

		if ( $wait > 0 ) {
			self::queue_full_purge( $wait );
		}
	}

	/**
	 * Queues one full clear for when the interval since the last one is up.
	 *
	 * One queued clear covers every change made until it runs, however many.
	 *
	 * @param int $wait Seconds until a full clear may run.
	 * @return void
	 */
	private static function queue_full_purge( $wait ) {
		$hook = Config::prefix() . '_deferred_cache_purge';

		if ( ! wp_next_scheduled( $hook ) ) {
			wp_schedule_single_event( time() + (int) $wait, $hook );
		}

		self::$note = sprintf(
			/* translators: %d: number of seconds. */
			__( 'The page cache was cleared in full moments ago, so to keep the site fast it will be cleared in full again within %d seconds. Until then, visitors may still see the old version of some pages.', 'mosmcp-abilities' ),
			(int) $wait
		);
	}

	/**
	 * Minimum seconds between two automatic full clears; 0 turns the limit off.
	 *
	 * @return int
	 */
	private static function interval() {
		/**
		 * Filters the minimum number of seconds between two automatic full clears
		 * of the page cache. Return 0 to clear in full after every change that needs it.
		 *
		 * @param int $seconds Default 60.
		 */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Prefixed via Config::prefix(); the library's prefix is host-configurable and cannot be a literal.
		return max( 0, (int) apply_filters( Config::prefix() . '_full_purge_interval', MINUTE_IN_SECONDS ) );
	}

	/**
	 * Notes that a full clear just ran, so the next one waits out the interval.
	 *
	 * @return void
	 */
	private static function remember_full_purge() {
		$interval = self::interval();

		if ( $interval > 0 ) {
			set_transient( Config::prefix() . '_full_purge_at', time(), $interval );
		}
	}

	/**
	 * Seconds until another full clear may run, or 0 when it may run now.
	 *
	 * @return int
	 */
	private static function full_purge_wait() {
		$interval = self::interval();
		$last     = $interval > 0 ? (int) get_transient( Config::prefix() . '_full_purge_at' ) : 0;

		return $last > 0 ? max( 0, $last + $interval - time() ) : 0;
	}

	/**
	 * Records a changed post, or a site-wide change for site-wide content.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $post_type Post type.
	 * @return void
	 */
	private static function record_post( $post_id, $post_type ) {
		if ( in_array( (string) $post_type, self::SITE_WIDE_TYPES, true ) ) {
			self::$site_wide = true;
			return;
		}

		if ( ! isset( self::$posts[ $post_id ] ) ) {
			self::$posts[ $post_id ] = false;
		}
	}

	/**
	 * Runs one engine's purge, never letting it break the caller.
	 *
	 * @param string   $name     Engine name.
	 * @param callable $callback Purge callback; returns true, or a reason string.
	 * @return array{name:string,cleared:bool,detail:string}
	 */
	private static function run( $name, $callback ) {
		$result = null;

		try {
			$result = $callback();
		} catch ( \Throwable $e ) {
			$result = $e->getMessage();
		}

		return array(
			'name'    => (string) $name,
			'cleared' => ( true === $result ),
			'detail'  => is_string( $result ) ? $result : '',
		);
	}

	/**
	 * The caches present on this site, each with a full purge and, where the
	 * cache supports it, a single-page purge.
	 *
	 * Ordered origin first, CDN last, so a CDN never re-caches a stale page from
	 * an origin cache that has not been cleared yet. Each entry is detected by the
	 * function or class its plugin or host defines, and calls that cache's own
	 * published purge API.
	 *
	 * @param bool $include_assets Include generated-asset caches (Elementor CSS).
	 * @return array<string, array{all:callable,post:callable|null}>
	 */
	public static function engines( $include_assets = true ) {
		$engines = array();

		if ( function_exists( 'rocket_clean_domain' ) ) {
			$engines['WP Rocket'] = array(
				'all'  => static function () {
					rocket_clean_domain();
					return true;
				},
				'post' => function_exists( 'rocket_clean_post' ) ? static function ( $id ) {
					rocket_clean_post( $id );
				} : null,
			);
		}

		if ( class_exists( '\LiteSpeed\Purge' ) ) {
			// LiteSpeed Cache's own published actions for third-party code.
			$engines['LiteSpeed Cache'] = array(
				'all'  => static function () {
					do_action( 'litespeed_purge_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
					return true;
				},
				'post' => static function ( $id ) {
					do_action( 'litespeed_purge_post', $id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
				},
			);
		}

		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			$engines['WP Super Cache'] = array(
				'all'  => static function () {
					wp_cache_clear_cache();
					return true;
				},
				'post' => function_exists( 'wp_cache_post_change' ) ? static function ( $id ) {
					wp_cache_post_change( $id );
				} : null,
			);
		}

		if ( function_exists( 'w3tc_flush_all' ) ) {
			$engines['W3 Total Cache'] = array(
				'all'  => static function () {
					w3tc_flush_all();
					return true;
				},
				'post' => function_exists( 'w3tc_flush_post' ) ? static function ( $id ) {
					w3tc_flush_post( $id );
				} : null,
			);
		}

		if ( class_exists( '\Breeze_PurgeCache' ) ) {
			$engines['Breeze'] = array(
				'all'  => static function () {
					\Breeze_PurgeCache::breeze_cache_flush();
					return true;
				},
				'post' => null,
			);
		}

		if ( class_exists( '\SiteGround_Optimizer\Supercacher\Supercacher' ) ) {
			$engines['SiteGround Optimizer'] = array(
				'all'  => static function () {
					\SiteGround_Optimizer\Supercacher\Supercacher::purge_cache();
					return true;
				},
				'post' => function_exists( 'sg_cachepress_purge_cache' ) ? static function ( $id ) {
					sg_cachepress_purge_cache( get_permalink( $id ) );
				} : null,
			);
		}

		if ( function_exists( 'wpfc_clear_all_cache' ) ) {
			$engines['WP Fastest Cache'] = array(
				'all'  => static function () {
					wpfc_clear_all_cache( true );
					return true;
				},
				'post' => function_exists( 'wpfc_clear_post_cache_by_id' ) ? static function ( $id ) {
					wpfc_clear_post_cache_by_id( $id );
				} : null,
			);
		}

		if ( defined( 'WPHB_VERSION' ) ) {
			// Hummingbird's documented action; with a post ID it clears that page only.
			$engines['Hummingbird'] = array(
				'all'  => static function () {
					do_action( 'wphb_clear_page_cache' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
					return true;
				},
				'post' => static function ( $id ) {
					do_action( 'wphb_clear_page_cache', $id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
				},
			);
		}

		if ( class_exists( '\Cache_Enabler' ) ) {
			$engines['Cache Enabler'] = array(
				'all'  => static function () {
					do_action( 'cache_enabler_clear_complete_cache' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
					return true;
				},
				'post' => static function ( $id ) {
					do_action( 'cache_enabler_clear_page_cache_by_post', $id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
				},
			);
		}

		if ( class_exists( '\comet_cache' ) && method_exists( '\comet_cache', 'clear' ) ) {
			$engines['Comet Cache'] = array(
				'all'  => static function () {
					\comet_cache::clear();
					return true;
				},
				'post' => null,
			);
		}

		if ( defined( 'NGINX_HELPER_BASENAME' ) ) {
			$engines['Nginx Helper'] = array(
				'all'  => static function () {
					do_action( 'rt_nginx_helper_purge_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
					return true;
				},
				'post' => null,
			);
		}

		// Managed hosts.
		if ( class_exists( '\WpeCommon' ) ) {
			$engines['WP Engine'] = array(
				'all'  => static function () {
					if ( method_exists( '\WpeCommon', 'purge_memcached' ) ) {
						\WpeCommon::purge_memcached();
					}
					if ( method_exists( '\WpeCommon', 'purge_varnish_cache' ) ) {
						\WpeCommon::purge_varnish_cache();
					}
					return true;
				},
				'post' => method_exists( '\WpeCommon', 'purge_varnish_cache' ) ? static function ( $id ) {
					\WpeCommon::purge_varnish_cache( $id );
				} : null,
			);
		}

		if ( isset( $GLOBALS['kinsta_cache'] ) && is_object( $GLOBALS['kinsta_cache'] ) && isset( $GLOBALS['kinsta_cache']->kinsta_cache_purge ) ) {
			$engines['Kinsta'] = array(
				'all'  => static function () {
					$purge = $GLOBALS['kinsta_cache']->kinsta_cache_purge;
					if ( ! method_exists( $purge, 'purge_complete_caches' ) ) {
						return __( 'This version of the Kinsta cache plugin offers no purge method.', 'mosmcp-abilities' );
					}
					$purge->purge_complete_caches();
					return true;
				},
				'post' => null,
			);
		}

		if ( function_exists( 'pantheon_wp_clear_edge_all' ) ) {
			$engines['Pantheon'] = array(
				'all'  => static function () {
					pantheon_wp_clear_edge_all();
					return true;
				},
				'post' => function_exists( 'pantheon_wp_clear_edge_paths' ) ? static function ( $id ) {
					$path = wp_parse_url( (string) get_permalink( $id ), PHP_URL_PATH );
					pantheon_wp_clear_edge_paths( array( $path ? $path : '/' ) );
				} : null,
			);
		}

		// CDNs last.
		if ( function_exists( 'nitropack_purge' ) ) {
			$engines['NitroPack'] = array(
				'all'  => static function () {
					nitropack_purge( null, null, 'Content changed through the MCP server' );
					return true;
				},
				'post' => static function ( $id ) {
					nitropack_purge( (string) get_permalink( $id ), null, 'Content changed through the MCP server' );
				},
			);
		}

		if ( class_exists( '\CF\WordPress\Hooks' ) ) {
			$engines['Cloudflare'] = array(
				'all'  => static function () {
					$hooks = new \CF\WordPress\Hooks();
					if ( ! method_exists( $hooks, 'purgeCacheEverything' ) ) {
						return __( 'This version of the Cloudflare plugin offers no purge method.', 'mosmcp-abilities' );
					}
					$hooks->purgeCacheEverything();
					return true;
				},
				'post' => static function ( $id ) {
					$hooks = new \CF\WordPress\Hooks();
					if ( method_exists( $hooks, 'purgeCacheByRelevantURLs' ) ) {
						$hooks->purgeCacheByRelevantURLs( $id );
					}
				},
			);
		}

		if ( class_exists( '\SW_CLOUDFLARE_PAGECACHE' ) ) {
			$engines['Super Page Cache'] = array(
				'all'  => static function () {
					do_action( 'swcfpc_purge_cache' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
					return true;
				},
				'post' => static function ( $id ) {
					do_action( 'swcfpc_purge_cache', array( (string) get_permalink( $id ) ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
				},
			);
		}

		if ( $include_assets && class_exists( '\Elementor\Plugin' ) ) {
			$engines['Elementor generated CSS'] = array(
				'all'  => static function () {
					\Elementor\Plugin::$instance->files_manager->clear_cache();
					return true;
				},
				'post' => null,
			);
		}

		/**
		 * Filters the caches cleared after changes. Each entry is keyed by a display
		 * name and holds 'all' (a callable clearing everything, returning true or a
		 * reason string) and 'post' (a callable taking a post ID, or null).
		 *
		 * @param array<string, array> $engines        Detected caches.
		 * @param bool                 $include_assets Whether asset caches are included.
		 */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Prefixed via Config::prefix(); the library's prefix is host-configurable and cannot be a literal.
		return (array) apply_filters( Config::prefix() . '_cache_engines', $engines, $include_assets );
	}
}
