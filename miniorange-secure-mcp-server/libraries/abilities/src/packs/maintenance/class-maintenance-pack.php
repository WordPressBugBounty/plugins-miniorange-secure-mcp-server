<?php
/**
 * Maintenance ability pack: scheduled events, permalinks, caches, and the error log.
 *
 * Everything here is administrator-level site plumbing. The error-log ability reads
 * only WordPress's own configured debug log and never a caller-supplied path, so it
 * cannot be turned into an arbitrary file reader.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Maintenance;

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
 * Class Maintenance_Pack
 *
 * Declares the maintenance abilities. Execute logic lives in Maintenance_Provider.
 */
class Maintenance_Pack extends Ability_Pack {

	/**
	 * Ability category slug.
	 */
	const CATEGORY = 'mosmcp-maintenance';

	/**
	 * Ability category for maintenance abilities.
	 *
	 * @return array Category definition.
	 */
	public function category() {
		return array(
			'slug'        => self::CATEGORY,
			'label'       => __( 'Maintenance', 'mosmcp-abilities' ),
			'description' => __( 'Inspect and run scheduled events, refresh permalinks, clear caches, and read the site error log.', 'mosmcp-abilities' ),
		);
	}

	/**
	 * The maintenance abilities.
	 *
	 * @return Ability[]
	 */
	public function abilities() {
		return array(
			$this->cron_list_events(),
			$this->cron_run_event(),
			$this->cron_delete_event(),
			$this->cron_test(),
			$this->flush_permalinks(),
			$this->list_rewrite_rules(),
			$this->flush_cache(),
			$this->read_error_log(),
		);
	}

	/**
	 * Defines the mosmcp/cron-list-events ability.
	 *
	 * @return Ability
	 */
	private function cron_list_events() {
		return new Ability(
			'mosmcp/cron-list-events',
			array(
				'label'         => __( 'List Scheduled Events', 'mosmcp-abilities' ),
				'description'   => __( "Lists WordPress's scheduled tasks with when each next runs, how often it repeats, and which plugin registered it. Read-only. Start here when something that should happen automatically is not happening, such as scheduled posts not publishing or backups not running. Also known as: cron jobs, wp-cron, scheduled tasks, scheduled events, task scheduler.", 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'manage_options',
				'annotations'   => Site_Support::annotations( true, false, true, false ),
				'execute'       => array( Maintenance_Provider::class, 'cron_list_events' ),
				'input_schema'  => Schema::object(
					array(
						'hook'     => Schema::str( __( 'Optional. Return only events for this hook name.', 'mosmcp-abilities' ) ),
						'overdue'  => Schema::boolean(
							__( 'When true, returns only events whose scheduled time has already passed, which is what a stalled cron looks like.', 'mosmcp-abilities' ),
							array( 'default' => false )
						),
						'per_page' => Schema::int(
							__( 'Maximum events to return.', 'mosmcp-abilities' ),
							array(
								'default' => 50,
								'minimum' => 1,
								'maximum' => 200,
							)
						),
					)
				),
				'output_schema' => Schema::object(
					array(
						'showing'       => Schema::int(),
						'total'         => Schema::int(),
						'overdue_count' => Schema::int( __( 'How many events are past their scheduled time.', 'mosmcp-abilities' ) ),
						'events'        => Schema::arr(
							Schema::object(
								array(
									'hook'          => Schema::str(),
									'next_run'      => Schema::str( __( 'Next run time in site local time.', 'mosmcp-abilities' ) ),
									'next_run_gmt'  => Schema::str(),
									'seconds_until' => Schema::int( __( 'Negative when the event is overdue.', 'mosmcp-abilities' ) ),
									'is_overdue'    => Schema::boolean(),
									'schedule'      => Schema::str( __( 'Recurrence name, or empty for a one-off event.', 'mosmcp-abilities' ) ),
									'interval'      => Schema::int( __( 'Seconds between runs, 0 for a one-off event.', 'mosmcp-abilities' ) ),
									'args_count'    => Schema::int(),
									'source'        => Schema::str( __( 'Plugin or theme that registered the hook, where it can be determined.', 'mosmcp-abilities' ) ),
								)
							)
						),
						'notes'         => Schema::arr( Schema::str() ),
					),
					array( 'showing', 'total', 'events' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/cron-run-event ability.
	 *
	 * @return Ability
	 */
	private function cron_run_event() {
		return new Ability(
			'mosmcp/cron-run-event',
			array(
				'label'         => __( 'Run Scheduled Event Now', 'mosmcp-abilities' ),
				'description'   => __( 'Runs one already-scheduled task immediately instead of waiting for its time. Only hooks that are currently scheduled can be run, so this cannot be used to trigger arbitrary code. Whatever the task does is done for real, and that cannot be undone from here, so it needs confirm set to true. Useful for testing whether a stalled task works when run by hand. Also known as: run a cron job now, trigger a scheduled task, force a scheduled event.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'manage_options',
				'annotations'   => Site_Support::annotations( false, false, false, true ),
				'execute'       => array( Maintenance_Provider::class, 'cron_run_event' ),
				'input_schema'  => Schema::object(
					array(
						'hook'    => Schema::str( __( 'The hook name to run, exactly as mosmcp/cron-list-events reports it.', 'mosmcp-abilities' ) ),
						'confirm' => Schema::boolean(
							__( 'Must be true. The task does its real work, which may send email, change content or call external services.', 'mosmcp-abilities' ),
							array( 'default' => false )
						),
					),
					// `confirm` is deliberately NOT required here. Marking it required makes
					// the schema reject the call before the ability runs, so the caller gets
					// a generic "confirm is required" instead of the refusal naming what is
					// about to happen, which is the whole point of the gate. Optional here,
					// enforced in the provider, is what surfaces the warning.
					array( 'hook' )
				),
				'output_schema' => Schema::object(
					array(
						'hook'            => Schema::str(),
						'ran'             => Schema::boolean(),
						'duration_ms'     => Schema::int(),
						'was_rescheduled' => Schema::boolean( __( 'True when the event repeats and has been scheduled again for its next run.', 'mosmcp-abilities' ) ),
						'next_run'        => Schema::str( __( 'Next scheduled run after this one, or empty for a one-off event.', 'mosmcp-abilities' ) ),
						'notes'           => Schema::arr( Schema::str() ),
					),
					array( 'hook', 'ran' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/cron-delete-event ability.
	 *
	 * @return Ability
	 */
	private function cron_delete_event() {
		return new Ability(
			'mosmcp/cron-delete-event',
			array(
				'label'         => __( 'Unschedule Event', 'mosmcp-abilities' ),
				'description'   => __( 'Removes every scheduled run of one hook. The plugin that registered it will usually schedule it again on its next run, so this is a way to clear a stuck or duplicated event rather than to switch a feature off permanently. Needs confirm set to true. Also known as: unschedule a cron job, remove a scheduled task, cancel a scheduled event.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'manage_options',
				'annotations'   => Site_Support::annotations( false, true, true, false ),
				'execute'       => array( Maintenance_Provider::class, 'cron_delete_event' ),
				'input_schema'  => Schema::object(
					array(
						'hook'    => Schema::str( __( 'The hook name to unschedule, exactly as mosmcp/cron-list-events reports it.', 'mosmcp-abilities' ) ),
						'confirm' => Schema::boolean(
							__( 'Must be true. Anything the task would have done stops happening until something schedules it again.', 'mosmcp-abilities' ),
							array( 'default' => false )
						),
					),
					// See the note on cron-run-event: confirm stays optional in the schema
					// so the provider's explanatory refusal is what the caller receives.
					array( 'hook' )
				),
				'output_schema' => Schema::object(
					array(
						'hook'    => Schema::str(),
						'removed' => Schema::int( __( 'How many scheduled runs were removed.', 'mosmcp-abilities' ) ),
						'notes'   => Schema::arr( Schema::str() ),
					),
					array( 'hook', 'removed' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/cron-test ability.
	 *
	 * @return Ability
	 */
	private function cron_test() {
		return new Ability(
			'mosmcp/cron-test',
			array(
				'label'         => __( 'Test Whether Cron Runs', 'mosmcp-abilities' ),
				'description'   => __( "Checks whether WordPress's scheduler can actually run on this site, by looking at whether it is switched off in configuration and whether the site can reach itself. Read-only. This is the check to run when scheduled posts never publish or scheduled tasks pile up overdue, because on many hosts the scheduler simply never fires. Also known as: cron health, is cron working, wp-cron not running, cron jobs, scheduler check.", 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'manage_options',
				'annotations'   => Site_Support::annotations( true, false, true, true ),
				'execute'       => array( Maintenance_Provider::class, 'cron_test' ),
				'input_schema'  => Schema::object( array() ),
				'output_schema' => Schema::object(
					array(
						'can_run'            => Schema::boolean( __( 'Whether the scheduler appears able to run at all.', 'mosmcp-abilities' ) ),
						'disabled_by_config' => Schema::boolean( __( 'True when DISABLE_WP_CRON switches the built-in scheduler off, which usually means a real server cron runs it instead.', 'mosmcp-abilities' ) ),
						'alternate_cron'     => Schema::boolean(),
						'loopback_ok'        => Schema::boolean( __( 'Whether the site could reach its own scheduler over HTTP.', 'mosmcp-abilities' ) ),
						'loopback_detail'    => Schema::str(),
						'overdue_count'      => Schema::int( __( 'Events already past their time, which is the symptom of a scheduler that is not firing.', 'mosmcp-abilities' ) ),
						'notes'              => Schema::arr( Schema::str() ),
					),
					array( 'can_run', 'disabled_by_config', 'overdue_count' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/site-flush-permalinks ability.
	 *
	 * @return Ability
	 */
	private function flush_permalinks() {
		return new Ability(
			'mosmcp/site-flush-permalinks',
			array(
				'label'         => __( 'Refresh Permalinks', 'mosmcp-abilities' ),
				'description'   => __( 'Rebuilds the rules WordPress uses to turn addresses into pages. Safe and repeatable, and it changes no settings. This is the standard fix when a page, custom post type or category returns a 404 that it should not, which usually means the rules went stale after a plugin or theme change. Also known as: flush permalinks, refresh permalinks, reset permalinks, fix a 404.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'manage_options',
				'annotations'   => Site_Support::annotations( false, false, true, false ),
				'execute'       => array( Maintenance_Provider::class, 'flush_permalinks' ),
				'input_schema'  => Schema::object( array() ),
				'output_schema' => Schema::object(
					array(
						'flushed'             => Schema::boolean(),
						'rules_before'        => Schema::int(),
						'rules_after'         => Schema::int(),
						'permalink_structure' => Schema::str(),
						'notes'               => Schema::arr( Schema::str() ),
					),
					array( 'flushed', 'rules_after' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/site-list-rewrite-rules ability.
	 *
	 * @return Ability
	 */
	private function list_rewrite_rules() {
		return new Ability(
			'mosmcp/site-list-rewrite-rules',
			array(
				'label'         => __( 'List URL Rules', 'mosmcp-abilities' ),
				'description'   => __( 'Lists the rules WordPress uses to match addresses, optionally filtered by a keyword. Read-only. Useful for working out why one address resolves and a similar one does not, and for confirming a custom post type or taxonomy actually registered its addresses. Also known as: rewrite rules, url rules, permalink rules, routing rules.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'manage_options',
				'annotations'   => Site_Support::annotations( true, false, true, false ),
				'execute'       => array( Maintenance_Provider::class, 'list_rewrite_rules' ),
				'input_schema'  => Schema::object(
					array(
						'search'   => Schema::str( __( 'Optional keyword matched against the rule pattern and its target.', 'mosmcp-abilities' ) ),
						'per_page' => Schema::int(
							__( 'Maximum rules to return. Sites commonly have well over a hundred.', 'mosmcp-abilities' ),
							array(
								'default' => 50,
								'minimum' => 1,
								'maximum' => 200,
							)
						),
					)
				),
				'output_schema' => Schema::object(
					array(
						'showing'   => Schema::int(),
						'total'     => Schema::int(),
						'is_pretty' => Schema::boolean( __( 'False when the site uses plain query-string addresses, in which case there are no rules to list.', 'mosmcp-abilities' ) ),
						'rules'     => Schema::arr(
							Schema::object(
								array(
									'pattern' => Schema::str(),
									'target'  => Schema::str(),
								)
							)
						),
						'notes'     => Schema::arr( Schema::str() ),
					),
					array( 'showing', 'total', 'rules' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/site-flush-cache ability.
	 *
	 * @return Ability
	 */
	private function flush_cache() {
		return new Ability(
			'mosmcp/site-flush-cache',
			array(
				'label'         => __( 'Clear Caches', 'mosmcp-abilities' ),
				'description'   => __( "Clears WordPress's object cache and asks any detected page-caching plugin to clear its cache too. Safe and repeatable. Use it when a change has been saved but visitors still see the old version. The answer names each cache it cleared and each one it could not, rather than claiming success for all of them. Also known as: clear cache, clear the caches, flush cache, purge cache, empty the cache.", 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'manage_options',
				'annotations'   => Site_Support::annotations( false, false, true, false ),
				'execute'       => array( Maintenance_Provider::class, 'flush_cache' ),
				'input_schema'  => Schema::object( array() ),
				'output_schema' => Schema::object(
					array(
						'object_cache_flushed'    => Schema::boolean(),
						'persistent_object_cache' => Schema::boolean( __( 'Whether a persistent object cache was in use. When false, the object cache only ever held this one request.', 'mosmcp-abilities' ) ),
						'engines'                 => Schema::arr(
							Schema::object(
								array(
									'name'    => Schema::str(),
									'cleared' => Schema::boolean(),
									'detail'  => Schema::str( __( 'Why it could not be cleared, when it could not.', 'mosmcp-abilities' ) ),
								)
							),
							__( 'Page-caching plugins detected on this site.', 'mosmcp-abilities' )
						),
						'notes'                   => Schema::arr( Schema::str() ),
					),
					array( 'object_cache_flushed', 'engines' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/site-read-error-log ability.
	 *
	 * @return Ability
	 */
	private function read_error_log() {
		return new Ability(
			'mosmcp/site-read-error-log',
			array(
				'label'         => __( 'Read Site Error Log', 'mosmcp-abilities' ),
				'description'   => __( "Returns the most recent lines of WordPress's own debug log, newest last, optionally filtered by keyword or severity. Read-only, and it only ever reads the log WordPress is configured to write; it cannot be pointed at another file. Values that look like passwords, keys or tokens are masked before the text is returned. Use it to find the actual error behind a white screen or a failing feature. Also known as: error log, read the error log, debug log, php errors, site errors, fatal errors.", 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'manage_options',
				'annotations'   => Site_Support::annotations( true, false, true, false ),
				'execute'       => array( Maintenance_Provider::class, 'read_error_log' ),
				'input_schema'  => Schema::object(
					array(
						'lines'    => Schema::int(
							__( 'How many of the most recent lines to return.', 'mosmcp-abilities' ),
							array(
								'default' => 50,
								'minimum' => 1,
								'maximum' => 500,
							)
						),
						'search'   => Schema::str( __( 'Optional keyword; only lines containing it are returned.', 'mosmcp-abilities' ) ),
						'severity' => Schema::str(
							__( 'Optional filter: "fatal", "error", "warning", "notice" or "deprecated".', 'mosmcp-abilities' ),
							array( 'enum' => array( 'fatal', 'error', 'warning', 'notice', 'deprecated' ) )
						),
					)
				),
				'output_schema' => Schema::object(
					array(
						'logging_enabled' => Schema::boolean( __( 'False when WordPress is not configured to write a debug log, in which case there is nothing to read.', 'mosmcp-abilities' ) ),
						'log_exists'      => Schema::boolean(),
						'size_bytes'      => Schema::int(),
						'showing'         => Schema::int(),
						'truncated'       => Schema::boolean( __( 'True when older lines exist beyond the ones returned.', 'mosmcp-abilities' ) ),
						'lines'           => Schema::arr( Schema::str(), __( 'Log lines, oldest first, with secrets masked.', 'mosmcp-abilities' ) ),
						'notes'           => Schema::arr( Schema::str() ),
					),
					array( 'logging_enabled', 'log_exists', 'showing', 'lines' )
				),
			)
		);
	}
}
