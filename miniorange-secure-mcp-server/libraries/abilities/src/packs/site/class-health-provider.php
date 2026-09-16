<?php
/**
 * Execute callbacks for the Updates and Site Health ability pack.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Site;

use WP_Site_Health;
use WP_Theme;

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
 * Class Health_Provider
 *
 * Static execute callbacks for the update-check and site-health abilities.
 */
class Health_Provider {

	/**
	 * PHP extensions worth reporting, because a WordPress feature commonly depends on each.
	 *
	 * @var string[]
	 */
	const REPORTED_EXTENSIONS = array(
		'curl',
		'openssl',
		'mbstring',
		'gd',
		'imagick',
		'zip',
		'intl',
		'json',
		'dom',
		'xml',
		'exif',
		'sodium',
		'zlib',
		'opcache',
	);

	/**
	 * Site Health tests that reach out over the network and can be slow.
	 *
	 * These are skipped so a health check stays fast enough to answer inside a
	 * tool call. The response says they were skipped rather than implying the
	 * site passed them.
	 *
	 * @var string[]
	 */
	const SLOW_TESTS = array(
		'rest_availability',
		'dotorg_communication',
		'background_updates',
		'loopback_requests',
		'https_status',
		'authorization_header',
		'page_cache',
	);

	/**
	 * Reports whether a WordPress core update is available.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public static function check_core_updates( $input = array() ) {
		$input = is_array( $input ) ? $input : array();

		require_once ABSPATH . 'wp-admin/includes/update.php';

		if ( Site_Support::bool_input( $input, 'refresh', false ) ) {
			wp_version_check( array(), true );
		}

		$current = (string) get_bloginfo( 'version' );
		$notes   = array();

		$filter_note = Site_Support::core_update_filter_note();
		if ( '' !== $filter_note ) {
			$notes[] = $filter_note;
		}

		$offers = function_exists( 'get_core_updates' ) ? get_core_updates() : array();
		$offer  = null;

		if ( is_array( $offers ) ) {
			foreach ( $offers as $candidate ) {
				if ( isset( $candidate->response ) && 'upgrade' === $candidate->response ) {
					$offer = $candidate;
					break;
				}
			}
		}

		if ( null === $offer ) {
			$notes[] = __( 'WordPress reports this site is running the newest version available to it.', 'mosmcp-abilities' );

			return array(
				'current_version'  => $current,
				'update_available' => false,
				'latest_version'   => $current,
				'update_type'      => 'none',
				'requires_php'     => '',
				'requires_mysql'   => '',
				'php_compatible'   => true,
				'notes'            => $notes,
			);
		}

		$latest       = isset( $offer->current ) ? (string) $offer->current : '';
		$requires_php = isset( $offer->php_version ) ? (string) $offer->php_version : '';
		$compatible   = '' === $requires_php || version_compare( PHP_VERSION, $requires_php, '>=' );

		if ( ! $compatible ) {
			$notes[] = sprintf(
				/* translators: 1: PHP version the update requires, 2: PHP version running now. */
				__( 'This update needs PHP %1$s or newer and this server runs PHP %2$s, so WordPress will refuse to install it until the host upgrades PHP.', 'mosmcp-abilities' ),
				$requires_php,
				PHP_VERSION
			);
		}

		return array(
			'current_version'  => $current,
			'update_available' => true,
			'latest_version'   => $latest,
			'update_type'      => self::update_type( $current, $latest ),
			'requires_php'     => $requires_php,
			'requires_mysql'   => isset( $offer->mysql_version ) ? (string) $offer->mysql_version : '',
			'php_compatible'   => $compatible,
			'notes'            => $notes,
		);
	}

	/**
	 * Classifies a version change as a minor or major WordPress update.
	 *
	 * @param string $current Version running now.
	 * @param string $latest  Version being offered.
	 * @return string One of "none", "minor", or "major".
	 */
	private static function update_type( $current, $latest ) {
		if ( '' === $latest || version_compare( $current, $latest, '>=' ) ) {
			return 'none';
		}

		$current_parts = explode( '.', $current );
		$latest_parts  = explode( '.', $latest );

		$current_branch = ( isset( $current_parts[0] ) ? $current_parts[0] : '0' ) . '.' . ( isset( $current_parts[1] ) ? $current_parts[1] : '0' );
		$latest_branch  = ( isset( $latest_parts[0] ) ? $latest_parts[0] : '0' ) . '.' . ( isset( $latest_parts[1] ) ? $latest_parts[1] : '0' );

		return $current_branch === $latest_branch ? 'minor' : 'major';
	}

	/**
	 * Lists installed plugins with an update available.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public static function check_plugin_updates( $input = array() ) {
		$input = is_array( $input ) ? $input : array();

		Site_Support::load_plugin_api();

		$refresh   = Site_Support::bool_input( $input, 'refresh', false );
		$updates   = Site_Support::plugin_updates( $refresh );
		$installed = get_plugins();
		$auto      = (array) get_site_option( 'auto_update_plugins', array() );

		$rows = array();

		foreach ( $updates as $file => $update ) {
			if ( ! isset( $installed[ $file ] ) ) {
				continue;
			}

			$data = $installed[ $file ];

			$rows[] = array(
				'plugin'            => (string) $file,
				'name'              => isset( $data['Name'] ) ? (string) $data['Name'] : '',
				'installed_version' => isset( $data['Version'] ) ? (string) $data['Version'] : '',
				'new_version'       => isset( $update->new_version ) ? (string) $update->new_version : '',
				'active'            => is_plugin_active( $file ),
				'auto_update'       => in_array( $file, $auto, true ),
				'requires_php'      => isset( $update->requires_php ) ? (string) $update->requires_php : '',
			);
		}

		return array(
			'count'   => count( $rows ),
			'total'   => count( $installed ),
			'updates' => $rows,
		);
	}

	/**
	 * Lists installed themes with an update available.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public static function check_theme_updates( $input = array() ) {
		$input = is_array( $input ) ? $input : array();

		$refresh = Site_Support::bool_input( $input, 'refresh', false );
		$updates = Site_Support::theme_updates( $refresh );
		$themes  = wp_get_themes();
		$active  = get_stylesheet();

		$rows = array();

		foreach ( $updates as $stylesheet => $update ) {
			if ( ! isset( $themes[ $stylesheet ] ) || ! is_array( $update ) ) {
				continue;
			}

			$theme = $themes[ $stylesheet ];

			$rows[] = array(
				'stylesheet'        => (string) $stylesheet,
				'name'              => $theme instanceof WP_Theme ? (string) $theme->get( 'Name' ) : '',
				'installed_version' => $theme instanceof WP_Theme ? (string) $theme->get( 'Version' ) : '',
				'new_version'       => isset( $update['new_version'] ) ? (string) $update['new_version'] : '',
				'active'            => ( (string) $stylesheet === $active ),
			);
		}

		return array(
			'count'   => count( $rows ),
			'total'   => count( $themes ),
			'updates' => $rows,
		);
	}

	/**
	 * Returns the WordPress version and related identity information.
	 *
	 * @param array<string, mixed> $input Ability input. Unused.
	 * @return array<string, mixed>
	 */
	public static function wordpress_version( $input = array() ) {
		unset( $input );

		return array(
			'version'      => (string) get_bloginfo( 'version' ),
			'db_version'   => (string) get_option( 'db_version', '' ),
			'locale'       => (string) get_locale(),
			'is_multisite' => is_multisite(),
			'is_main_site' => is_multisite() ? is_main_site() : true,
			'php_version'  => PHP_VERSION,
		);
	}

	/**
	 * Returns the environment this site runs on.
	 *
	 * @param array<string, mixed> $input Ability input. Unused.
	 * @return array<string, mixed>
	 */
	public static function server_info( $input = array() ) {
		unset( $input );

		global $wpdb;

		$server_info = method_exists( $wpdb, 'db_server_info' ) ? (string) $wpdb->db_server_info() : '';
		$is_mariadb  = false !== stripos( $server_info, 'mariadb' );

		$software = '';
		if ( isset( $_SERVER['SERVER_SOFTWARE'] ) ) {
			$software = sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) );
		}

		return array(
			'php'       => array(
				'version'             => PHP_VERSION,
				'sapi'                => PHP_SAPI,
				'memory_limit'        => (string) ini_get( 'memory_limit' ),
				'max_execution_time'  => (string) ini_get( 'max_execution_time' ),
				'upload_max_filesize' => (string) ini_get( 'upload_max_filesize' ),
				'post_max_size'       => (string) ini_get( 'post_max_size' ),
				'max_input_vars'      => (string) ini_get( 'max_input_vars' ),
				'extensions'          => self::loaded_extensions(),
			),
			'database'  => array(
				'server'  => $is_mariadb ? 'MariaDB' : 'MySQL',
				'version' => method_exists( $wpdb, 'db_version' ) ? (string) $wpdb->db_version() : '',
				'charset' => (string) $wpdb->charset,
				'collate' => (string) $wpdb->collate,
			),
			'wordpress' => array(
				'memory_limit'            => defined( 'WP_MEMORY_LIMIT' ) ? (string) WP_MEMORY_LIMIT : '',
				'max_memory_limit'        => defined( 'WP_MAX_MEMORY_LIMIT' ) ? (string) WP_MAX_MEMORY_LIMIT : '',
				'debug'                   => defined( 'WP_DEBUG' ) && WP_DEBUG,
				'debug_log'               => defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG,
				'debug_display'           => defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY,
				'script_debug'            => defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG,
				'file_mods_allowed'       => ! ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ),
				'file_edit_allowed'       => ! ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ),
				'persistent_object_cache' => wp_using_ext_object_cache(),
				'cron_disabled'           => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			),
			'server'    => array(
				'software'        => $software,
				'is_ssl'          => is_ssl(),
				'home_url_scheme' => (string) wp_parse_url( home_url(), PHP_URL_SCHEME ),
			),
		);
	}

	/**
	 * Lists which of the reported PHP extensions are loaded.
	 *
	 * @return string[]
	 */
	private static function loaded_extensions() {
		$loaded = array();

		foreach ( self::REPORTED_EXTENSIONS as $extension ) {
			if ( extension_loaded( $extension ) ) {
				$loaded[] = $extension;
			}
		}

		return $loaded;
	}

	/**
	 * Runs the fast Site Health tests and summarises the result.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public static function health_status( $input = array() ) {
		$input           = is_array( $input ) ? $input : array();
		$include_passing = Site_Support::bool_input( $input, 'include_passing', false );

		if ( ! class_exists( 'WP_Site_Health' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
		}

		$notes = array(
			__( 'Only the fast local tests were run. The slower tests that make network requests, such as loopback and WordPress.org connectivity, were skipped, so this is a subset of the Site Health screen rather than the whole of it.', 'mosmcp-abilities' ),
		);

		if ( ! class_exists( 'WP_Site_Health' ) ) {
			return array(
				'summary' => array(
					'good'        => 0,
					'recommended' => 0,
					'critical'    => 0,
					'total'       => 0,
				),
				'tests'   => array(),
				'notes'   => array( __( 'Site Health is not available on this WordPress version.', 'mosmcp-abilities' ) ),
			);
		}

		$health     = WP_Site_Health::get_instance();
		$registered = WP_Site_Health::get_tests();
		$direct     = isset( $registered['direct'] ) && is_array( $registered['direct'] ) ? $registered['direct'] : array();

		$summary = array(
			'good'        => 0,
			'recommended' => 0,
			'critical'    => 0,
			'total'       => 0,
		);
		$rows    = array();
		$skipped = 0;

		foreach ( $direct as $key => $test ) {
			$name = isset( $test['test'] ) ? $test['test'] : $key;

			if ( is_string( $name ) && in_array( $name, self::SLOW_TESTS, true ) ) {
				++$skipped;
				continue;
			}

			$result = self::run_test( $health, $name );

			if ( null === $result ) {
				continue;
			}

			$status = isset( $result['status'] ) ? (string) $result['status'] : 'recommended';

			if ( ! isset( $summary[ $status ] ) ) {
				$status = 'recommended';
			}

			++$summary[ $status ];
			++$summary['total'];

			if ( 'good' === $status && ! $include_passing ) {
				continue;
			}

			$rows[] = array(
				'test'        => is_string( $name ) ? $name : (string) $key,
				'label'       => isset( $result['label'] ) ? wp_strip_all_tags( (string) $result['label'] ) : '',
				'status'      => $status,
				'category'    => isset( $result['badge']['label'] ) ? wp_strip_all_tags( (string) $result['badge']['label'] ) : '',
				'description' => isset( $result['description'] ) ? self::plain_text( (string) $result['description'] ) : '',
			);
		}

		if ( $skipped > 0 ) {
			$notes[] = sprintf(
				/* translators: %d: number of Site Health tests that were skipped. */
				__( '%d slower network test was skipped and is not counted in the totals.', 'mosmcp-abilities' ),
				$skipped
			);
		}

		return array(
			'summary' => $summary,
			'tests'   => $rows,
			'notes'   => $notes,
		);
	}

	/**
	 * Runs a single Site Health test, tolerating a test that errors.
	 *
	 * A third-party plugin can register a Site Health test, and a fatal in one of
	 * those must not take down the whole answer, so each is run defensively and a
	 * failing test is simply omitted.
	 *
	 * @param WP_Site_Health  $health The Site Health instance.
	 * @param string|callable $name   Test name or callable, as registered.
	 * @return array<string, mixed>|null The test result, or null when it could not run.
	 */
	private static function run_test( WP_Site_Health $health, $name ) {
		$callback = null;

		if ( is_string( $name ) ) {
			$method = 'get_test_' . $name;

			if ( method_exists( $health, $method ) ) {
				$callback = array( $health, $method );
			}
		} elseif ( is_callable( $name ) ) {
			$callback = $name;
		}

		if ( null === $callback ) {
			return null;
		}

		try {
			$result = call_user_func( $callback );
		} catch ( \Throwable $e ) {
			return null;
		}

		return is_array( $result ) ? $result : null;
	}

	/**
	 * Converts a Site Health description to plain text.
	 *
	 * @param string $html Description markup as the test returned it.
	 * @return string
	 */
	private static function plain_text( $html ) {
		$text = wp_strip_all_tags( $html );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );

		/*
		 * The /u modifier makes this pattern fail outright on malformed UTF-8, and
		 * these descriptions come from third-party Site Health tests, so that input
		 * is not hypothetical. preg_replace() answers null in that case; casting it
		 * to string would silently discard the entire description. Collapsing
		 * whitespace is cosmetic, the text itself is not, so keep the uncollapsed
		 * version instead of losing it.
		 */
		$collapsed = preg_replace( '/\s+/u', ' ', $text );

		return trim( null === $collapsed ? $text : $collapsed );
	}
}
