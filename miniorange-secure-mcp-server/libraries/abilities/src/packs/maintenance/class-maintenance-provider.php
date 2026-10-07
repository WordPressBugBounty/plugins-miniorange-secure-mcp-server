<?php
/**
 * Execute callbacks for the Maintenance ability pack.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Maintenance;

use MoSMCP\Abilities\Packs\Site\Site_Support;
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
 * Class Maintenance_Provider
 *
 * Static execute callbacks for the maintenance abilities.
 */
class Maintenance_Provider {

	/**
	 * Largest slice of the log file read from disk, regardless of the line count asked for.
	 */
	const LOG_READ_BYTES = 262144;

	/**
	 * Patterns whose values are masked before any log line is returned.
	 *
	 * Stack traces and query dumps routinely carry credentials. The log is returned
	 * to a language model and from there into a transcript, so the masking happens
	 * here rather than being left to the caller.
	 *
	 * @var string[]
	 */
	const SECRET_PATTERNS = array(
		// The optional "word" is non-capturing on purpose: every pattern here must
		// expose exactly two groups (name, separator) so one replacement string works.
		'/(pass(?:word)?|pwd)([\'"\s:=>\]]+)([^\s,;\'")]{3,})/i',
		'/(secret|api[_-]?key|apikey|token|auth|bearer|nonce|salt|private[_-]?key)([\'"\s:=>\]]+)([^\s,;\'")]{6,})/i',
		'/(DB_PASSWORD|AUTH_KEY|SECURE_AUTH_KEY|LOGGED_IN_KEY|NONCE_KEY|AUTH_SALT|SECURE_AUTH_SALT|LOGGED_IN_SALT|NONCE_SALT)([\'"\s:=>,\]]+)([^\s,;\'")]+)/i',
	);

	/**
	 * Lists scheduled events.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public static function cron_list_events( $input = array() ) {
		$input     = is_array( $input ) ? $input : array();
		$hook_only = isset( $input['hook'] ) ? trim( (string) $input['hook'] ) : '';
		$per_page  = isset( $input['per_page'] ) ? (int) $input['per_page'] : 50;
		$per_page  = max( 1, min( 200, $per_page ) );
		$overdue   = Site_Support::bool_input( $input, 'overdue', false );

		$events        = self::collect_events();
		$total         = count( $events );
		$overdue_count = 0;

		foreach ( $events as $event ) {
			if ( $event['is_overdue'] ) {
				++$overdue_count;
			}
		}

		$rows = array();

		foreach ( $events as $event ) {
			if ( '' !== $hook_only && $event['hook'] !== $hook_only ) {
				continue;
			}
			if ( $overdue && ! $event['is_overdue'] ) {
				continue;
			}
			$rows[] = $event;
			if ( count( $rows ) >= $per_page ) {
				break;
			}
		}

		$notes = array();

		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			$notes[] = __( "WordPress's own scheduler is switched off by the DISABLE_WP_CRON setting. Events only run if a server-level scheduled task calls wp-cron.php. Use mosmcp/cron-test to check.", 'mosmcp-abilities' );
		}

		if ( $overdue_count > 5 ) {
			$notes[] = __( 'Several events are past their scheduled time, which usually means the scheduler is not running rather than that any individual task is broken. Run mosmcp/cron-test.', 'mosmcp-abilities' );
		}

		return array(
			'showing'       => count( $rows ),
			'total'         => $total,
			'overdue_count' => $overdue_count,
			'events'        => $rows,
			'notes'         => $notes,
		);
	}

	/**
	 * Flattens the cron array into one row per hook occurrence.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function collect_events() {
		$cron = _get_cron_array();

		if ( ! is_array( $cron ) ) {
			return array();
		}

		$schedules = wp_get_schedules();
		$now       = time();
		$rows      = array();

		foreach ( $cron as $timestamp => $hooks ) {
			if ( ! is_array( $hooks ) ) {
				continue;
			}

			foreach ( $hooks as $hook => $instances ) {
				if ( ! is_array( $instances ) ) {
					continue;
				}

				foreach ( $instances as $instance ) {
					$schedule = ( is_array( $instance ) && ! empty( $instance['schedule'] ) ) ? (string) $instance['schedule'] : '';
					$interval = ( is_array( $instance ) && isset( $instance['interval'] ) ) ? (int) $instance['interval'] : 0;

					if ( '' !== $schedule && isset( $schedules[ $schedule ]['interval'] ) ) {
						$interval = (int) $schedules[ $schedule ]['interval'];
					}

					$rows[] = array(
						'hook'          => (string) $hook,
						'next_run'      => (string) get_date_from_gmt( gmdate( 'Y-m-d H:i:s', (int) $timestamp ), 'Y-m-d H:i:s' ),
						'next_run_gmt'  => (string) gmdate( 'Y-m-d H:i:s', (int) $timestamp ),
						'seconds_until' => (int) $timestamp - $now,
						'is_overdue'    => ( (int) $timestamp < $now ),
						'schedule'      => $schedule,
						'interval'      => $interval,
						'args_count'    => ( is_array( $instance ) && isset( $instance['args'] ) && is_array( $instance['args'] ) ) ? count( $instance['args'] ) : 0,
						'source'        => self::hook_source( (string) $hook ),
					);
				}
			}
		}

		usort(
			$rows,
			static function ( $a, $b ) {
				return $a['seconds_until'] <=> $b['seconds_until'];
			}
		);

		return $rows;
	}

	/**
	 * Names the plugin or theme that registered a hook, where it can be determined.
	 *
	 * Without this a list of hook names is close to meaningless to anyone who did
	 * not write them, and "which plugin is doing this" is the actual question.
	 *
	 * @param string $hook Hook name.
	 * @return string
	 */
	private static function hook_source( $hook ) {
		global $wp_filter;

		if ( ! isset( $wp_filter[ $hook ] ) || empty( $wp_filter[ $hook ]->callbacks ) ) {
			return '';
		}

		foreach ( $wp_filter[ $hook ]->callbacks as $callbacks ) {
			foreach ( (array) $callbacks as $registered ) {
				$file = self::callback_file( isset( $registered['function'] ) ? $registered['function'] : null );

				if ( '' === $file ) {
					continue;
				}

				$plugins = wp_normalize_path( WP_PLUGIN_DIR );
				$themes  = wp_normalize_path( get_theme_root() );
				$file    = wp_normalize_path( $file );

				if ( 0 === strpos( $file, $plugins . '/' ) ) {
					$rest = substr( $file, strlen( $plugins ) + 1 );
					return 'plugin: ' . strtok( $rest, '/' );
				}

				if ( 0 === strpos( $file, $themes . '/' ) ) {
					$rest = substr( $file, strlen( $themes ) + 1 );
					return 'theme: ' . strtok( $rest, '/' );
				}

				return 'WordPress core';
			}
		}

		return '';
	}

	/**
	 * Resolves the file a callback is defined in.
	 *
	 * @param mixed $callback Callback of any supported shape.
	 * @return string File path, or empty when it cannot be resolved.
	 */
	private static function callback_file( $callback ) {
		try {
			if ( is_string( $callback ) && function_exists( $callback ) ) {
				$ref = new \ReflectionFunction( $callback );
				return (string) $ref->getFileName();
			}

			if ( $callback instanceof \Closure ) {
				$ref = new \ReflectionFunction( $callback );
				return (string) $ref->getFileName();
			}

			if ( is_array( $callback ) && isset( $callback[0], $callback[1] ) ) {
				$ref = new \ReflectionMethod( is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0], (string) $callback[1] );
				return (string) $ref->getFileName();
			}
		} catch ( \Throwable $e ) {
			return '';
		}

		return '';
	}

	/**
	 * Runs one scheduled event immediately.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function cron_run_event( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$hook  = isset( $input['hook'] ) ? trim( (string) $input['hook'] ) : '';

		if ( '' === $hook ) {
			return Site_Support::error(
				'mosmcp_cron_hook_required',
				__( 'No hook was named. Use mosmcp/cron-list-events to see the scheduled hooks on this site.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_INVALID_INPUT,
				true,
				array( 'use_instead' => 'mosmcp/cron-list-events' )
			);
		}

		/*
		 * Only a hook that is actually scheduled may be run. Without this the ability
		 * would be a way to fire any action registered anywhere on the site, which is
		 * a far larger surface than "run a scheduled task early".
		 */
		$next = wp_next_scheduled( $hook );

		if ( false === $next ) {
			return Site_Support::error(
				'mosmcp_cron_hook_not_scheduled',
				sprintf(
					/* translators: %s: the hook name supplied. */
					__( 'No scheduled event exists for the hook "%s", so there is nothing to run. Only hooks that are already scheduled can be run this way. Use mosmcp/cron-list-events to see what is scheduled.', 'mosmcp-abilities' ),
					$hook
				),
				Site_Support::CAUSE_NOT_FOUND,
				false,
				array( 'use_instead' => 'mosmcp/cron-list-events' )
			);
		}

		$unconfirmed = Site_Support::require_confirm(
			$input,
			sprintf(
				/* translators: %s: the hook name. */
				__( 'the task "%s" does its real work now, which may send email, change content, or call an external service', 'mosmcp-abilities' ),
				$hook
			)
		);

		if ( $unconfirmed instanceof WP_Error ) {
			return $unconfirmed;
		}

		$notes = array();
		$start = microtime( true );

		try {
			// The hook is another plugin's, not ours, and firing it is the entire
			// purpose of this ability. It is already constrained above to hooks that
			// WordPress currently has scheduled.
			do_action_ref_array( $hook, array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound
			$ran = true;
		} catch ( \Throwable $e ) {
			$ran     = false;
			$notes[] = sprintf(
				/* translators: %s: the error message the task produced. */
				__( 'The task threw an error while running: %s', 'mosmcp-abilities' ),
				$e->getMessage()
			);
		}

		$duration = (int) round( ( microtime( true ) - $start ) * 1000 );

		// Read the schedule back rather than assuming: a repeating event reschedules
		// itself, a one-off does not, and which happened is what the caller needs.
		$following = wp_next_scheduled( $hook );

		return array(
			'hook'            => $hook,
			'ran'             => $ran,
			'duration_ms'     => $duration,
			'was_rescheduled' => ( false !== $following && $following !== $next ),
			'next_run'        => false !== $following ? (string) get_date_from_gmt( gmdate( 'Y-m-d H:i:s', (int) $following ), 'Y-m-d H:i:s' ) : '',
			'notes'           => $notes,
		);
	}

	/**
	 * Unschedules every run of one hook.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function cron_delete_event( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$hook  = isset( $input['hook'] ) ? trim( (string) $input['hook'] ) : '';

		if ( '' === $hook ) {
			return Site_Support::error(
				'mosmcp_cron_hook_required',
				__( 'No hook was named. Use mosmcp/cron-list-events to see the scheduled hooks on this site.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_INVALID_INPUT,
				true,
				array( 'use_instead' => 'mosmcp/cron-list-events' )
			);
		}

		if ( false === wp_next_scheduled( $hook ) ) {
			return array(
				'hook'    => $hook,
				'removed' => 0,
				'notes'   => array( __( 'That hook has no scheduled runs, so nothing was changed.', 'mosmcp-abilities' ) ),
			);
		}

		$unconfirmed = Site_Support::require_confirm(
			$input,
			sprintf(
				/* translators: %s: the hook name. */
				__( 'every scheduled run of "%s" is removed, so whatever it does stops happening until something schedules it again', 'mosmcp-abilities' ),
				$hook
			)
		);

		if ( $unconfirmed instanceof WP_Error ) {
			return $unconfirmed;
		}

		$removed = wp_unschedule_hook( $hook );
		$removed = is_int( $removed ) ? $removed : 0;

		$notes = array( __( 'The plugin that registered this task will usually schedule it again the next time it runs, so this clears a stuck or duplicated event rather than switching a feature off.', 'mosmcp-abilities' ) );

		if ( false !== wp_next_scheduled( $hook ) ) {
			$notes[] = __( 'The hook is already scheduled again, which means something re-registered it immediately.', 'mosmcp-abilities' );
		}

		return array(
			'hook'    => $hook,
			'removed' => $removed,
			'notes'   => $notes,
		);
	}

	/**
	 * Reports whether the scheduler can run.
	 *
	 * @param array<string, mixed> $input Ability input. Unused.
	 * @return array<string, mixed>
	 */
	public static function cron_test( $input = array() ) {
		unset( $input );

		$disabled  = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$alternate = defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON;
		$notes     = array();

		$overdue = 0;
		foreach ( self::collect_events() as $event ) {
			if ( $event['is_overdue'] ) {
				++$overdue;
			}
		}

		$response = wp_remote_post(
			site_url( 'wp-cron.php?doing_wp_cron=' . sprintf( '%.22F', microtime( true ) ) ),
			array(
				'timeout'   => 10,
				'blocking'  => true,
				// A WordPress core filter, applied here exactly as core applies it for
				// its own loopback requests; it is not a hook this library owns.
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
				'body'      => array( 'doing_wp_cron' => sprintf( '%.22F', microtime( true ) ) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			$loopback_ok     = false;
			$loopback_detail = $response->get_error_message();
		} else {
			$code            = (int) wp_remote_retrieve_response_code( $response );
			$loopback_ok     = ( $code >= 200 && $code < 400 );
			$loopback_detail = 'HTTP ' . $code;
		}

		if ( $disabled ) {
			$notes[] = __( "WordPress's built-in scheduler is switched off by DISABLE_WP_CRON. That is a normal setup when the host runs a real server scheduled task instead, but if nobody set one up, nothing scheduled ever runs.", 'mosmcp-abilities' );
		}

		if ( ! $loopback_ok ) {
			$notes[] = __( 'The site could not reach its own scheduler over HTTP. That commonly means a firewall, password protection, or a hosts-file entry is blocking the site from calling itself, and it stops scheduled tasks running.', 'mosmcp-abilities' );
		}

		if ( $overdue > 0 && $loopback_ok && ! $disabled ) {
			$notes[] = __( 'The scheduler is reachable but events are overdue. They normally run on the next visit to the site, so a site with very little traffic can look stalled when it is only quiet.', 'mosmcp-abilities' );
		}

		return array(
			'can_run'            => ( $loopback_ok && ! $disabled ) || $disabled,
			'disabled_by_config' => $disabled,
			'alternate_cron'     => $alternate,
			'loopback_ok'        => $loopback_ok,
			'loopback_detail'    => (string) $loopback_detail,
			'overdue_count'      => $overdue,
			'notes'              => $notes,
		);
	}

	/**
	 * Rebuilds the rewrite rules.
	 *
	 * @param array<string, mixed> $input Ability input. Unused.
	 * @return array<string, mixed>
	 */
	public static function flush_permalinks( $input = array() ) {
		unset( $input );

		global $wp_rewrite;

		$before = count( (array) get_option( 'rewrite_rules', array() ) );

		flush_rewrite_rules( false );

		$after = count( (array) get_option( 'rewrite_rules', array() ) );
		$notes = array();

		$structure = (string) get_option( 'permalink_structure', '' );

		if ( '' === $structure ) {
			$notes[] = __( 'This site uses plain query-string addresses, so there are no rules to rebuild. Nothing was broken by running this, but it will not fix a 404 either.', 'mosmcp-abilities' );
		}

		if ( isset( $wp_rewrite ) && method_exists( $wp_rewrite, 'using_mod_rewrite_permalinks' ) && ! $wp_rewrite->using_mod_rewrite_permalinks() && '' !== $structure ) {
			$notes[] = __( 'The rules were rebuilt in the database, but this server may also need its own configuration updated, which WordPress cannot do on Nginx.', 'mosmcp-abilities' );
		}

		return array(
			'flushed'             => true,
			'rules_before'        => $before,
			'rules_after'         => $after,
			'permalink_structure' => $structure,
			'notes'               => $notes,
		);
	}

	/**
	 * Lists the rewrite rules.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public static function list_rewrite_rules( $input = array() ) {
		$input    = is_array( $input ) ? $input : array();
		$search   = isset( $input['search'] ) ? strtolower( trim( (string) $input['search'] ) ) : '';
		$per_page = isset( $input['per_page'] ) ? (int) $input['per_page'] : 50;
		$per_page = max( 1, min( 200, $per_page ) );

		$rules = (array) get_option( 'rewrite_rules', array() );
		$rows  = array();
		$notes = array();

		foreach ( $rules as $pattern => $target ) {
			if ( '' !== $search && false === stripos( (string) $pattern . ' ' . (string) $target, $search ) ) {
				continue;
			}

			$rows[] = array(
				'pattern' => (string) $pattern,
				'target'  => (string) $target,
			);

			if ( count( $rows ) >= $per_page ) {
				break;
			}
		}

		$is_pretty = ( '' !== (string) get_option( 'permalink_structure', '' ) );

		if ( ! $is_pretty ) {
			$notes[] = __( 'This site uses plain query-string addresses, so it has no rewrite rules.', 'mosmcp-abilities' );
		} elseif ( empty( $rules ) ) {
			$notes[] = __( 'Pretty addresses are switched on but no rules are stored, which is exactly the state that causes site-wide 404s. Run mosmcp/site-flush-permalinks.', 'mosmcp-abilities' );
		}

		return array(
			'showing'   => count( $rows ),
			'total'     => count( $rules ),
			'is_pretty' => $is_pretty,
			'rules'     => $rows,
			'notes'     => $notes,
		);
	}

	/**
	 * Clears the object cache and any detected page cache.
	 *
	 * @param array<string, mixed> $input Ability input. Unused.
	 * @return array<string, mixed>
	 */
	public static function flush_cache( $input = array() ) {
		unset( $input );

		$persistent = (bool) wp_using_ext_object_cache();
		$flushed    = (bool) wp_cache_flush();
		$engines    = array();
		$notes      = array();

		foreach ( self::cache_engines() as $name => $callback ) {
			$result = null;

			try {
				$result = $callback();
			} catch ( \Throwable $e ) {
				$result = $e->getMessage();
			}

			$engines[] = array(
				'name'    => $name,
				'cleared' => ( true === $result ),
				'detail'  => is_string( $result ) ? $result : '',
			);
		}

		if ( ! $persistent ) {
			$notes[] = __( 'This site has no persistent object cache, so the object cache only ever held data for a single request and clearing it changes nothing visible.', 'mosmcp-abilities' );
		}

		if ( empty( $engines ) ) {
			$notes[] = __( 'No page-caching plugin was detected. If visitors still see stale content, the cache is likely at the host or a CDN rather than in WordPress.', 'mosmcp-abilities' );
		}

		return array(
			'object_cache_flushed'    => $flushed,
			'persistent_object_cache' => $persistent,
			'engines'                 => $engines,
			'notes'                   => $notes,
		);
	}

	/**
	 * The page-cache engines this site has, each with a closure that clears it.
	 *
	 * Ordered origin-first so a CDN is cleared last and cannot re-cache stale HTML
	 * from an origin that has not been cleared yet.
	 *
	 * @return array<string, callable>
	 */
	private static function cache_engines() {
		$engines = array();

		if ( function_exists( 'rocket_clean_domain' ) ) {
			$engines['WP Rocket'] = static function () {
				rocket_clean_domain();
				return true;
			};
		}

		if ( class_exists( '\LiteSpeed\Purge' ) ) {
			$engines['LiteSpeed Cache'] = static function () {
				// LiteSpeed Cache's own published action; this is how that plugin
				// documents clearing its cache from third-party code.
				do_action( 'litespeed_purge_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
				return true;
			};
		}

		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			$engines['WP Super Cache'] = static function () {
				wp_cache_clear_cache();
				return true;
			};
		}

		if ( function_exists( 'w3tc_flush_all' ) ) {
			$engines['W3 Total Cache'] = static function () {
				w3tc_flush_all();
				return true;
			};
		}

		if ( class_exists( '\Breeze_PurgeCache' ) ) {
			$engines['Breeze'] = static function () {
				\Breeze_PurgeCache::breeze_cache_flush();
				return true;
			};
		}

		if ( class_exists( '\SiteGround_Optimizer\Supercacher\Supercacher' ) ) {
			$engines['SiteGround Optimizer'] = static function () {
				\SiteGround_Optimizer\Supercacher\Supercacher::purge_cache();
				return true;
			};
		}

		if ( class_exists( '\Elementor\Plugin' ) ) {
			$engines['Elementor generated CSS'] = static function () {
				\Elementor\Plugin::$instance->files_manager->clear_cache();
				return true;
			};
		}

		return $engines;
	}

	/**
	 * Returns the tail of the WordPress debug log.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public static function read_error_log( $input = array() ) {
		$input    = is_array( $input ) ? $input : array();
		$want     = isset( $input['lines'] ) ? (int) $input['lines'] : 50;
		$want     = max( 1, min( 500, $want ) );
		$search   = isset( $input['search'] ) ? (string) $input['search'] : '';
		$severity = isset( $input['severity'] ) ? strtolower( (string) $input['severity'] ) : '';

		$enabled = defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG;
		$path    = self::log_path();
		$notes   = array();

		if ( ! $enabled ) {
			$notes[] = __( 'WordPress is not configured to write a debug log. Add WP_DEBUG and WP_DEBUG_LOG to wp-config.php to start recording errors. Nothing is being lost meanwhile; PHP may still be logging to the server error log, which this ability cannot read.', 'mosmcp-abilities' );
		}

		if ( '' === $path || ! is_readable( $path ) ) {
			return array(
				'logging_enabled' => $enabled,
				'log_exists'      => false,
				'size_bytes'      => 0,
				'showing'         => 0,
				'truncated'       => false,
				'lines'           => array(),
				'notes'           => array_merge( $notes, array( __( 'No readable debug log was found at the configured location.', 'mosmcp-abilities' ) ) ),
			);
		}

		$size = (int) filesize( $path );

		// Read only the tail. A debug log on a busy site reaches hundreds of megabytes,
		// and loading it whole would exhaust memory before anything could be returned.
		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( false === $handle ) {
			return array(
				'logging_enabled' => $enabled,
				'log_exists'      => true,
				'size_bytes'      => $size,
				'showing'         => 0,
				'truncated'       => false,
				'lines'           => array(),
				'notes'           => array_merge( $notes, array( __( 'The debug log exists but could not be opened for reading.', 'mosmcp-abilities' ) ) ),
			);
		}

		$offset = max( 0, $size - self::LOG_READ_BYTES );
		fseek( $handle, $offset );
		$raw = (string) stream_get_contents( $handle );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		// A non-zero offset almost certainly lands mid-line; drop that partial line.
		if ( $offset > 0 ) {
			$newline = strpos( $raw, "\n" );
			$raw     = ( false === $newline ) ? '' : substr( $raw, $newline + 1 );
		}

		$all = preg_split( '/\r\n|\r|\n/', $raw );
		$all = is_array( $all ) ? array_values(
			array_filter(
				$all,
				static function ( $l ) {
					return '' !== trim( (string) $l );
				}
			)
		) : array();

		$matched = array();

		foreach ( $all as $line ) {
			if ( '' !== $search && false === stripos( $line, $search ) ) {
				continue;
			}
			if ( '' !== $severity && ! self::line_matches_severity( $line, $severity ) ) {
				continue;
			}
			$matched[] = self::redact( $line );
		}

		$truncated = count( $matched ) > $want || $offset > 0;
		$lines     = array_slice( $matched, -$want );

		if ( $offset > 0 ) {
			$notes[] = __( 'Only the most recent part of the log was read, because the file is large. Older entries exist before what is shown.', 'mosmcp-abilities' );
		}

		return array(
			'logging_enabled' => $enabled,
			'log_exists'      => true,
			'size_bytes'      => $size,
			'showing'         => count( $lines ),
			'truncated'       => $truncated,
			'lines'           => array_values( $lines ),
			'notes'           => $notes,
		);
	}

	/**
	 * Resolves the configured debug log path.
	 *
	 * Deliberately derived from configuration only. A caller-supplied path would make
	 * this an arbitrary file reader, which is not what it is for.
	 *
	 * @return string Absolute path, or empty when none is configured.
	 */
	private static function log_path() {
		if ( defined( 'WP_DEBUG_LOG' ) && is_string( WP_DEBUG_LOG ) && '' !== WP_DEBUG_LOG ) {
			return (string) WP_DEBUG_LOG;
		}

		$default = rtrim( WP_CONTENT_DIR, '/\\' ) . '/debug.log';

		return file_exists( $default ) ? $default : '';
	}

	/**
	 * Whether a log line matches a severity filter.
	 *
	 * @param string $line     Log line.
	 * @param string $severity One of fatal, error, warning, notice, deprecated.
	 * @return bool
	 */
	private static function line_matches_severity( $line, $severity ) {
		$map = array(
			'fatal'      => 'fatal error',
			'error'      => 'error',
			'warning'    => 'warning',
			'notice'     => 'notice',
			'deprecated' => 'deprecated',
		);

		if ( ! isset( $map[ $severity ] ) ) {
			return true;
		}

		return false !== stripos( $line, $map[ $severity ] );
	}

	/**
	 * Masks anything in a log line that looks like a credential.
	 *
	 * @param string $line Log line.
	 * @return string
	 */
	private static function redact( $line ) {
		$line = (string) $line;

		foreach ( self::SECRET_PATTERNS as $pattern ) {
			$masked = preg_replace( $pattern, '$1$2[redacted]', $line );

			/*
			 * Keep the previous value when the engine fails rather than casting a null
			 * to an empty string. Failing open on a redaction is the wrong trade, but a
			 * null here blanks the whole line, which destroys the log entry the caller
			 * asked for; the remaining patterns still run against the unmasked text.
			 */
			if ( null !== $masked ) {
				$line = $masked;
			}
		}

		return $line;
	}
}
