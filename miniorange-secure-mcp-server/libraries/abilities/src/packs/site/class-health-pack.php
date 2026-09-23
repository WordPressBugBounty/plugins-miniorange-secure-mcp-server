<?php
/**
 * Updates and Site Health ability pack.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Site;

use MoSMCP\Abilities\Ability;
use MoSMCP\Abilities\Ability_Pack;
use MoSMCP\Abilities\Packs\Core\Support\Core_Schema as Schema;

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
 * Class Health_Pack
 *
 * Declares the update-check and site-health abilities. Execute logic lives in
 * Health_Provider.
 */
class Health_Pack extends Ability_Pack {

	/**
	 * Ability category slug.
	 */
	const CATEGORY = 'mosmcp-site-health';

	/**
	 * Ability category for update and health abilities.
	 *
	 * @return array Category definition.
	 */
	public function category() {
		return array(
			'slug'        => self::CATEGORY,
			'label'       => __( 'Updates & Site Health', 'mosmcp-abilities' ),
			'description' => __( 'Check for available WordPress, plugin, and theme updates, and inspect the environment this site runs on.', 'mosmcp-abilities' ),
		);
	}

	/**
	 * The update and health abilities.
	 *
	 * @return Ability[]
	 */
	public function abilities() {
		return array(
			$this->check_core_updates(),
			$this->check_plugin_updates(),
			$this->check_theme_updates(),
			$this->wordpress_version(),
			$this->server_info(),
			$this->health_status(),
		);
	}

	/**
	 * Defines the mosmcp/site-check-core-updates ability.
	 *
	 * @return Ability
	 */
	private function check_core_updates() {
		return new Ability(
			'mosmcp/site-check-core-updates',
			array(
				'label'         => __( 'Check WordPress Core Updates', 'mosmcp-abilities' ),
				'description'   => __( 'Reports whether a WordPress core update is available, which version, and whether it is a minor security release or a major one. Read-only; it checks but never installs. If another plugin on this site filters core update data, the answer says so rather than presenting it as certain.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'update_core',
				'annotations'   => Site_Support::annotations( true, false, true, true ),
				'execute'       => array( Health_Provider::class, 'check_core_updates' ),
				'input_schema'  => Schema::object(
					array(
						'refresh' => Schema::boolean(
							__( 'When true, asks WordPress.org for fresh data instead of using the cached answer. Slower.', 'mosmcp-abilities' ),
							array( 'default' => false )
						),
					)
				),
				'output_schema' => Schema::object(
					array(
						'current_version'  => Schema::str( __( 'The WordPress version this site runs now.', 'mosmcp-abilities' ) ),
						'update_available' => Schema::boolean(),
						'latest_version'   => Schema::str( __( 'Newest version offered, or the current one when already up to date.', 'mosmcp-abilities' ) ),
						'update_type'      => Schema::str( __( 'Either "none", "minor" for a patch or security release, or "major".', 'mosmcp-abilities' ) ),
						'requires_php'     => Schema::str( __( 'PHP version the offered update requires.', 'mosmcp-abilities' ) ),
						'requires_mysql'   => Schema::str(),
						'php_compatible'   => Schema::boolean( __( 'False when this server runs a PHP version older than the update needs.', 'mosmcp-abilities' ) ),
						'notes'            => Schema::arr( Schema::str(), __( 'Caveats that affect how much to trust this answer.', 'mosmcp-abilities' ) ),
					),
					array( 'current_version', 'update_available', 'update_type' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/site-check-plugin-updates ability.
	 *
	 * @return Ability
	 */
	private function check_plugin_updates() {
		return new Ability(
			'mosmcp/site-check-plugin-updates',
			array(
				'label'         => __( 'Check Plugin Updates', 'mosmcp-abilities' ),
				'description'   => __( 'Lists every installed plugin with an update available, showing the installed and offered version. Read-only; it checks but never installs. Use mosmcp/plugin-update to actually apply one.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'update_plugins',
				'annotations'   => Site_Support::annotations( true, false, true, true ),
				'execute'       => array( Health_Provider::class, 'check_plugin_updates' ),
				'input_schema'  => Schema::object(
					array(
						'refresh' => Schema::boolean(
							__( 'When true, asks WordPress.org for fresh data instead of using the cached answer. Slower.', 'mosmcp-abilities' ),
							array( 'default' => false )
						),
					)
				),
				'output_schema' => Schema::object(
					array(
						'count'   => Schema::int( __( 'How many plugins have an update available.', 'mosmcp-abilities' ) ),
						'total'   => Schema::int( __( 'How many plugins are installed in total.', 'mosmcp-abilities' ) ),
						'updates' => Schema::arr(
							Schema::object(
								array(
									'plugin'            => Schema::str(),
									'name'              => Schema::str(),
									'installed_version' => Schema::str(),
									'new_version'       => Schema::str(),
									'active'            => Schema::boolean(),
									'auto_update'       => Schema::boolean(),
									'requires_php'      => Schema::str(),
								)
							)
						),
					),
					array( 'count', 'total', 'updates' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/site-check-theme-updates ability.
	 *
	 * @return Ability
	 */
	private function check_theme_updates() {
		return new Ability(
			'mosmcp/site-check-theme-updates',
			array(
				'label'         => __( 'Check Theme Updates', 'mosmcp-abilities' ),
				'description'   => __( 'Lists every installed theme with an update available, showing the installed and offered version. Read-only; it checks but never installs.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'update_themes',
				'annotations'   => Site_Support::annotations( true, false, true, true ),
				'execute'       => array( Health_Provider::class, 'check_theme_updates' ),
				'input_schema'  => Schema::object(
					array(
						'refresh' => Schema::boolean(
							__( 'When true, asks WordPress.org for fresh data instead of using the cached answer. Slower.', 'mosmcp-abilities' ),
							array( 'default' => false )
						),
					)
				),
				'output_schema' => Schema::object(
					array(
						'count'   => Schema::int(),
						'total'   => Schema::int(),
						'updates' => Schema::arr(
							Schema::object(
								array(
									'stylesheet'        => Schema::str(),
									'name'              => Schema::str(),
									'installed_version' => Schema::str(),
									'new_version'       => Schema::str(),
									'active'            => Schema::boolean(),
								)
							)
						),
					),
					array( 'count', 'total', 'updates' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/site-get-wordpress-version ability.
	 *
	 * @return Ability
	 */
	private function wordpress_version() {
		return new Ability(
			'mosmcp/site-get-wordpress-version',
			array(
				'label'         => __( 'Get WordPress Version', 'mosmcp-abilities' ),
				'description'   => __( 'Returns the WordPress version this site runs, its database schema version, its locale, and whether it is part of a multisite network. Read-only. Useful for deciding whether a feature or block is available before trying to use it.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'edit_posts',
				'annotations'   => Site_Support::annotations( true, false, true, false ),
				'execute'       => array( Health_Provider::class, 'wordpress_version' ),
				'input_schema'  => Schema::object( array() ),
				'output_schema' => Schema::object(
					array(
						'version'      => Schema::str( __( 'WordPress version, for example "6.9.1".', 'mosmcp-abilities' ) ),
						'db_version'   => Schema::str( __( 'Database schema revision WordPress has applied.', 'mosmcp-abilities' ) ),
						'locale'       => Schema::str(),
						'is_multisite' => Schema::boolean(),
						'is_main_site' => Schema::boolean( __( 'On a network, whether this is the primary site. Always true on a single install.', 'mosmcp-abilities' ) ),
						'php_version'  => Schema::str(),
					),
					array( 'version', 'locale', 'is_multisite' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/site-get-server-info ability.
	 *
	 * @return Ability
	 */
	private function server_info() {
		return new Ability(
			'mosmcp/site-get-server-info',
			array(
				'label'         => __( 'Get Server Information', 'mosmcp-abilities' ),
				'description'   => __( 'Returns the environment this site runs on: PHP and database versions, memory and execution limits, upload limits, HTTPS state, and whether a persistent object cache is in use. Read-only. Returns no file paths, database credentials, or security keys. Useful for diagnosing why an upload, import, or long operation fails.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'manage_options',
				'annotations'   => Site_Support::annotations( true, false, true, false ),
				'execute'       => array( Health_Provider::class, 'server_info' ),
				'input_schema'  => Schema::object( array() ),
				'output_schema' => Schema::object(
					array(
						'php'       => Schema::object(
							array(
								'version'             => Schema::str(),
								'sapi'                => Schema::str( __( 'How PHP runs here, for example "fpm-fcgi" or "apache2handler".', 'mosmcp-abilities' ) ),
								'memory_limit'        => Schema::str(),
								'max_execution_time'  => Schema::str( __( 'Seconds a request may run. 0 means unlimited.', 'mosmcp-abilities' ) ),
								'upload_max_filesize' => Schema::str(),
								'post_max_size'       => Schema::str(),
								'max_input_vars'      => Schema::str(),
								'extensions'          => Schema::arr( Schema::str(), __( 'Presence of the extensions WordPress features commonly depend on.', 'mosmcp-abilities' ) ),
							)
						),
						'database'  => Schema::object(
							array(
								'server'  => Schema::str( __( 'Either "MySQL" or "MariaDB".', 'mosmcp-abilities' ) ),
								'version' => Schema::str(),
								'charset' => Schema::str(),
								'collate' => Schema::str(),
							)
						),
						'wordpress' => Schema::object(
							array(
								'memory_limit'            => Schema::str( __( 'The WP_MEMORY_LIMIT constant, which caps front-end requests.', 'mosmcp-abilities' ) ),
								'max_memory_limit'        => Schema::str( __( 'The WP_MAX_MEMORY_LIMIT constant, used for admin and image work.', 'mosmcp-abilities' ) ),
								'debug'                   => Schema::boolean(),
								'debug_log'               => Schema::boolean(),
								'debug_display'           => Schema::boolean(),
								'script_debug'            => Schema::boolean(),
								'file_mods_allowed'       => Schema::boolean( __( 'False when DISALLOW_FILE_MODS blocks installing and updating plugins and themes.', 'mosmcp-abilities' ) ),
								'file_edit_allowed'       => Schema::boolean( __( 'False when DISALLOW_FILE_EDIT blocks the built-in file editors.', 'mosmcp-abilities' ) ),
								'persistent_object_cache' => Schema::boolean(),
								'cron_disabled'           => Schema::boolean( __( 'True when DISABLE_WP_CRON stops WordPress running scheduled tasks on its own.', 'mosmcp-abilities' ) ),
							)
						),
						'server'    => Schema::object(
							array(
								'software'        => Schema::str( __( 'Web server software string, for example "nginx" or "Apache".', 'mosmcp-abilities' ) ),
								'is_ssl'          => Schema::boolean( __( 'Whether this request reached the site over HTTPS.', 'mosmcp-abilities' ) ),
								'home_url_scheme' => Schema::str( __( 'The scheme the site is configured to use. A mismatch with is_ssl usually means a proxy is not passing the protocol through.', 'mosmcp-abilities' ) ),
							)
						),
					),
					array( 'php', 'database', 'wordpress', 'server' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/site-get-health-status ability.
	 *
	 * @return Ability
	 */
	private function health_status() {
		return new Ability(
			'mosmcp/site-get-health-status',
			array(
				'label'         => __( 'Get Site Health Status', 'mosmcp-abilities' ),
				'description'   => __( "Runs WordPress's own Site Health checks and returns what passed, what is recommended, and what is critical, with the reason for anything that is not passing. Read-only. Runs only the fast, local tests, so the answer is a subset of the Site Health screen in wp-admin; the slower network tests are skipped to keep the call quick.", 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'view_site_health_checks',
				'annotations'   => Site_Support::annotations( true, false, true, false ),
				'execute'       => array( Health_Provider::class, 'health_status' ),
				'input_schema'  => Schema::object(
					array(
						'include_passing' => Schema::boolean(
							__( 'When true, also lists the tests that passed. Off by default, because the failures are what matter.', 'mosmcp-abilities' ),
							array( 'default' => false )
						),
					)
				),
				'output_schema' => Schema::object(
					array(
						'summary' => Schema::object(
							array(
								'good'        => Schema::int( __( 'Tests that passed.', 'mosmcp-abilities' ) ),
								'recommended' => Schema::int( __( 'Tests raising a non-urgent improvement.', 'mosmcp-abilities' ) ),
								'critical'    => Schema::int( __( 'Tests raising a problem that needs attention.', 'mosmcp-abilities' ) ),
								'total'       => Schema::int(),
							)
						),
						'tests'   => Schema::arr(
							Schema::object(
								array(
									'test'        => Schema::str(),
									'label'       => Schema::str(),
									'status'      => Schema::str( __( 'One of "good", "recommended", or "critical".', 'mosmcp-abilities' ) ),
									'category'    => Schema::str( __( 'Which area the test covers, for example "performance" or "security".', 'mosmcp-abilities' ) ),
									'description' => Schema::str( __( 'Plain-text explanation, with the formatting removed.', 'mosmcp-abilities' ) ),
								)
							)
						),
						'notes'   => Schema::arr( Schema::str() ),
					),
					array( 'summary', 'tests' )
				),
			)
		);
	}
}
