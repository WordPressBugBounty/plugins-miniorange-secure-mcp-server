<?php
/**
 * Execute callbacks for the Plugins ability pack.
 *
 * Every mutation here verifies its own outcome against the site afterwards.
 * WordPress returns void from deactivate_plugins(), and an update can silently
 * leave a plugin switched off, so a caller that trusts the return value ends up
 * telling someone their site was updated while a feature of it is dead.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Site;

use Automatic_Upgrader_Skin;
use Plugin_Upgrader;
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
 * Class Plugins_Provider
 *
 * Static execute callbacks for the plugin abilities.
 */
class Plugins_Provider {

	/**
	 * Lists installed plugins.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public static function plugin_list( $input = array() ) {
		$input = is_array( $input ) ? $input : array();

		Site_Support::load_plugin_api();

		$status  = isset( $input['status'] ) ? (string) $input['status'] : 'all';
		$search  = isset( $input['search'] ) ? strtolower( trim( (string) $input['search'] ) ) : '';
		$refresh = Site_Support::bool_input( $input, 'refresh_updates', false );

		$only_with_update = Site_Support::bool_input( $input, 'has_update', false );

		$installed = get_plugins();
		$updates   = Site_Support::plugin_updates( $refresh );

		$rows         = array();
		$active_count = 0;

		foreach ( $installed as $file => $data ) {
			$described = Site_Support::describe_plugin( (string) $file, (array) $data, $updates );

			if ( 'active' === $described['status'] ) {
				++$active_count;
			}

			if ( 'active' === $status && 'active' !== $described['status'] ) {
				continue;
			}

			if ( 'inactive' === $status && 'inactive' !== $described['status'] ) {
				continue;
			}

			if ( $only_with_update && ! $described['update_available'] ) {
				continue;
			}

			if ( '' !== $search ) {
				$haystack = $described['name'] . ' ' . $described['slug'] . ' ' . $described['description'];

				if ( false === stripos( $haystack, $search ) ) {
					continue;
				}
			}

			$rows[] = $described;
		}

		return array(
			'showing' => count( $rows ),
			'total'   => count( $installed ),
			'active'  => $active_count,
			'plugins' => $rows,
		);
	}

	/**
	 * Returns full detail about one installed plugin.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function plugin_get( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$file  = Site_Support::resolve_plugin_file( isset( $input['plugin'] ) ? $input['plugin'] : '' );

		if ( $file instanceof WP_Error ) {
			return $file;
		}

		$installed = get_plugins();

		return Site_Support::describe_plugin( $file, (array) $installed[ $file ], Site_Support::plugin_updates( false ) );
	}

	/**
	 * Counts plugins by status.
	 *
	 * @param array<string, mixed> $input Ability input. Unused.
	 * @return array<string, mixed>
	 */
	public static function plugin_count_by_status( $input = array() ) {
		unset( $input );

		Site_Support::load_plugin_api();

		$installed = get_plugins();
		$updates   = Site_Support::plugin_updates( false );
		$auto      = (array) get_site_option( 'auto_update_plugins', array() );
		$must_use  = function_exists( 'get_mu_plugins' ) ? get_mu_plugins() : array();

		$active         = 0;
		$network_active = 0;

		foreach ( array_keys( $installed ) as $file ) {
			$file = (string) $file;

			if ( is_multisite() && is_plugin_active_for_network( $file ) ) {
				++$network_active;
				++$active;
				continue;
			}

			if ( is_plugin_active( $file ) ) {
				++$active;
			}
		}

		$total = count( $installed );

		return array(
			'total'            => $total,
			'active'           => $active,
			'inactive'         => $total - $active,
			'network_active'   => $network_active,
			'update_available' => count( array_intersect_key( $updates, $installed ) ),
			'auto_update_on'   => count( array_intersect( $auto, array_keys( $installed ) ) ),
			'must_use'         => count( $must_use ),
		);
	}

	/**
	 * Lists plugins with an update available.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public static function plugin_list_updates( $input = array() ) {
		$input = is_array( $input ) ? $input : array();

		Site_Support::load_plugin_api();

		$updates   = Site_Support::plugin_updates( Site_Support::bool_input( $input, 'refresh', false ) );
		$installed = get_plugins();
		$auto      = (array) get_site_option( 'auto_update_plugins', array() );

		$rows = array();

		foreach ( $updates as $file => $update ) {
			$file = (string) $file;

			if ( ! isset( $installed[ $file ] ) ) {
				continue;
			}

			$data = (array) $installed[ $file ];

			$rows[] = array(
				'plugin'            => $file,
				'name'              => isset( $data['Name'] ) ? (string) $data['Name'] : '',
				'installed_version' => isset( $data['Version'] ) ? (string) $data['Version'] : '',
				'new_version'       => isset( $update->new_version ) ? (string) $update->new_version : '',
				'active'            => is_plugin_active( $file ),
				'auto_update'       => in_array( $file, $auto, true ),
			);
		}

		return array(
			'count'   => count( $rows ),
			'updates' => $rows,
		);
	}

	/**
	 * Activates an installed plugin.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function plugin_activate( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$file  = Site_Support::resolve_plugin_file( isset( $input['plugin'] ) ? $input['plugin'] : '' );

		if ( $file instanceof WP_Error ) {
			return $file;
		}

		$name  = self::plugin_name( $file );
		$notes = array();

		if ( is_plugin_active( $file ) ) {
			return array(
				'plugin'   => $file,
				'name'     => $name,
				'previous' => 'active',
				'current'  => 'active',
				'changed'  => false,
				'notes'    => array( __( 'That plugin was already active, so nothing was changed.', 'mosmcp-abilities' ) ),
			);
		}

		$result = activate_plugin( $file );

		if ( is_wp_error( $result ) ) {
			return Site_Support::error(
				'mosmcp_plugin_activation_failed',
				sprintf(
					/* translators: 1: plugin name, 2: the reason WordPress gave. */
					__( 'WordPress refused to activate %1$s: %2$s. The plugin is still switched off and the site is unchanged.', 'mosmcp-abilities' ),
					$name,
					$result->get_error_message()
				),
				Site_Support::CAUSE_WP_CORE,
				false,
				array( 'plugin' => $file )
			);
		}

		// Read the state back: activate_plugin() answers null on success, which says
		// nothing about whether the plugin survived its own activation hook.
		$now = is_plugin_active( $file );

		if ( ! $now ) {
			$notes[] = __( 'WordPress reported no error but the plugin is still not active, which usually means it deactivated itself during startup. Check the site error log.', 'mosmcp-abilities' );
		}

		return array(
			'plugin'   => $file,
			'name'     => $name,
			'previous' => 'inactive',
			'current'  => $now ? 'active' : 'inactive',
			'changed'  => $now,
			'notes'    => $notes,
		);
	}

	/**
	 * Deactivates an installed plugin.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function plugin_deactivate( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$file  = Site_Support::resolve_plugin_file( isset( $input['plugin'] ) ? $input['plugin'] : '' );

		if ( $file instanceof WP_Error ) {
			return $file;
		}

		$self = Site_Support::self_protection( $file, __( 'deactivate', 'mosmcp-abilities' ) );

		if ( $self instanceof WP_Error ) {
			return $self;
		}

		$name  = self::plugin_name( $file );
		$notes = array();

		if ( ! is_plugin_active( $file ) ) {
			return array(
				'plugin'   => $file,
				'name'     => $name,
				'previous' => 'inactive',
				'current'  => 'inactive',
				'changed'  => false,
				'notes'    => array( __( 'That plugin was already inactive, so nothing was changed.', 'mosmcp-abilities' ) ),
			);
		}

		if ( is_multisite() && is_plugin_active_for_network( $file ) ) {
			return Site_Support::error(
				'mosmcp_plugin_network_active',
				sprintf(
					/* translators: %s: plugin name. */
					__( '%s is activated across the whole network, so it cannot be switched off for this site alone. A network administrator has to do it under Network Admin then Plugins.', 'mosmcp-abilities' ),
					$name
				),
				Site_Support::CAUSE_HOST_ENVIRONMENT,
				false,
				array( 'plugin' => $file )
			);
		}

		deactivate_plugins( $file );

		// deactivate_plugins() returns void, so the only way to know is to look.
		$still_active = is_plugin_active( $file );

		if ( $still_active ) {
			$notes[] = __( 'The plugin is still active after the request to switch it off, which usually means something on this site re-activates it. A security or management plugin can do that.', 'mosmcp-abilities' );
		} else {
			$notes[] = __( 'The plugin files and its stored settings are untouched, so it can be switched back on at any time.', 'mosmcp-abilities' );
		}

		return array(
			'plugin'   => $file,
			'name'     => $name,
			'previous' => 'active',
			'current'  => $still_active ? 'active' : 'inactive',
			'changed'  => ! $still_active,
			'notes'    => $notes,
		);
	}

	/**
	 * Updates an installed plugin to the newest available version.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function plugin_update( $input = array() ) {
		$input = is_array( $input ) ? $input : array();

		$locked = Site_Support::file_mods_locked();

		if ( $locked instanceof WP_Error ) {
			return $locked;
		}

		$network = Site_Support::multisite_guard( __( 'update', 'mosmcp-abilities' ) );

		if ( $network instanceof WP_Error ) {
			return $network;
		}

		$file = Site_Support::resolve_plugin_file( isset( $input['plugin'] ) ? $input['plugin'] : '' );

		if ( $file instanceof WP_Error ) {
			return $file;
		}

		$self = Site_Support::self_protection( $file, __( 'update', 'mosmcp-abilities' ) );

		if ( $self instanceof WP_Error ) {
			return $self;
		}

		$name = self::plugin_name( $file );

		$unconfirmed = Site_Support::require_confirm(
			$input,
			sprintf(
				/* translators: %s: plugin name. */
				__( 'the files of %s are replaced with a newer version and the old ones are gone', 'mosmcp-abilities' ),
				$name
			)
		);

		if ( $unconfirmed instanceof WP_Error ) {
			return $unconfirmed;
		}

		Site_Support::load_upgrade_api();

		$previous_version = self::version_on_disk( $file );
		$updates          = Site_Support::plugin_updates( true );

		if ( ! isset( $updates[ $file ] ) ) {
			return array(
				'plugin'           => $file,
				'name'             => $name,
				'previous_version' => $previous_version,
				'current_version'  => $previous_version,
				'updated'          => false,
				'was_active'       => is_plugin_active( $file ),
				'is_active'        => is_plugin_active( $file ),
				'notes'            => array( __( 'No update is available for that plugin, so nothing was changed. It is already on the newest version WordPress knows about.', 'mosmcp-abilities' ) ),
			);
		}

		$offered = isset( $updates[ $file ]->new_version ) ? (string) $updates[ $file ]->new_version : '';
		$expect  = isset( $input['expect_version'] ) ? trim( (string) $input['expect_version'] ) : '';

		if ( '' !== $expect && $expect !== $offered ) {
			return Site_Support::error(
				'mosmcp_plugin_version_mismatch',
				sprintf(
					/* translators: 1: version the caller expected, 2: version actually offered, 3: plugin name. */
					__( 'Refused: you expected version %1$s but %2$s is what is now on offer for %3$s. A newer release landed since you checked. Look at the new version and call again once it is the one you mean to install.', 'mosmcp-abilities' ),
					$expect,
					$offered,
					$name
				),
				Site_Support::CAUSE_CONFLICT,
				true,
				array(
					'expected' => $expect,
					'offered'  => $offered,
				)
			);
		}

		$was_active = is_plugin_active( $file );
		$notes      = array();

		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );

		/*
		 * bulk_upgrade() rather than upgrade(): upgrade() deactivates the plugin via
		 * deactivate_plugin_before_upgrade and leaves reactivation to the caller, which
		 * is how a routine update silently switches a plugin off. Core's own dashboard
		 * updater uses bulk_upgrade for a single plugin for exactly this reason.
		 */
		$result = $upgrader->bulk_upgrade( array( $file ) );

		if ( is_wp_error( $result ) ) {
			return self::upgrade_failure( $file, $name, $previous_version, $result->get_error_message(), $skin, $was_active );
		}

		if ( isset( $result[ $file ] ) && is_wp_error( $result[ $file ] ) ) {
			return self::upgrade_failure( $file, $name, $previous_version, $result[ $file ]->get_error_message(), $skin, $was_active );
		}

		wp_clean_plugins_cache( false );

		$current_version = self::version_on_disk( $file );
		$updated         = ( '' !== $current_version && $current_version !== $previous_version );

		if ( ! $updated ) {
			$notes[] = __( 'WordPress reported no error, but the version on disk did not change, so the files were not actually replaced. Treat this plugin as still on its old version.', 'mosmcp-abilities' );
		}

		$is_active = is_plugin_active( $file );

		if ( $was_active && ! $is_active ) {
			$reactivated = activate_plugin( $file );
			$is_active   = is_plugin_active( $file );

			if ( is_wp_error( $reactivated ) || ! $is_active ) {
				$notes[] = __( 'The plugin was active before the update and is switched off now, and it could not be switched back on. The site is running without it until someone activates it from the Plugins screen.', 'mosmcp-abilities' );
			} else {
				$notes[] = __( 'WordPress switched the plugin off to replace its files and it has been switched back on.', 'mosmcp-abilities' );
			}
		}

		return array(
			'plugin'           => $file,
			'name'             => $name,
			'previous_version' => $previous_version,
			'current_version'  => $current_version,
			'updated'          => $updated,
			'was_active'       => $was_active,
			'is_active'        => $is_active,
			'notes'            => $notes,
		);
	}

	/**
	 * Builds the error returned when an update attempt fails.
	 *
	 * @param string                  $file             Plugin file.
	 * @param string                  $name             Plugin display name.
	 * @param string                  $previous_version Version before the attempt.
	 * @param string                  $reason           Reason WordPress gave.
	 * @param Automatic_Upgrader_Skin $skin            Upgrader skin holding the run log.
	 * @param bool                    $was_active       Whether the plugin was active beforehand.
	 * @return WP_Error
	 */
	private static function upgrade_failure( $file, $name, $previous_version, $reason, Automatic_Upgrader_Skin $skin, $was_active ) {
		$log = $skin->get_upgrade_messages();
		$log = is_array( $log ) ? implode( ' ', array_map( 'wp_strip_all_tags', $log ) ) : '';

		// Name a misconfiguration that produces an unreadable failure. WordPress empties
		// the upgrade folder before unpacking, so a temp directory pointing inside it has
		// the downloaded archive deleted underneath it, and the only symptom is
		// "Missing archive file". Every update on such a site fails, from wp-admin too.
		$log .= self::temp_dir_collision_note();

		$still_active = is_plugin_active( $file );
		$extra        = '';

		if ( $was_active && ! $still_active ) {
			$extra = ' ' . __( 'The plugin was also left switched off and needs re-activating.', 'mosmcp-abilities' );
		}

		return Site_Support::error(
			'mosmcp_plugin_update_failed',
			sprintf(
				/* translators: 1: plugin name, 2: reason from WordPress, 3: upgrader log, 4: extra warning about active state. */
				__( 'Updating %1$s failed: %2$s. %3$s%4$s', 'mosmcp-abilities' ),
				$name,
				$reason,
				$log,
				$extra
			),
			Site_Support::CAUSE_WP_CORE,
			false,
			array(
				'plugin'           => $file,
				'previous_version' => $previous_version,
				'is_active'        => $still_active,
			)
		);
	}


	/**
	 * Turns automatic updates on or off for one plugin.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function plugin_set_auto_updates( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$file  = Site_Support::resolve_plugin_file( isset( $input['plugin'] ) ? $input['plugin'] : '' );

		if ( $file instanceof WP_Error ) {
			return $file;
		}

		if ( ! array_key_exists( 'enabled', $input ) ) {
			return Site_Support::error(
				'mosmcp_auto_update_missing_value',
				__( 'Pass enabled as true to let WordPress update this plugin automatically, or false to stop it.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_INVALID_INPUT,
				true
			);
		}

		$enabled = Site_Support::bool_input( $input, 'enabled', false );
		$name    = self::plugin_name( $file );
		$notes   = array();

		/*
		 * Auto-updates live in a SITE option, which on multisite is network-wide.
		 * Reading it with get_option() instead silently misses the value there.
		 */
		$list     = (array) get_site_option( 'auto_update_plugins', array() );
		$previous = in_array( $file, $list, true );

		if ( $enabled ) {
			$list[] = $file;
		} else {
			$list = array_diff( $list, array( $file ) );
		}

		update_site_option( 'auto_update_plugins', array_values( array_unique( $list ) ) );

		$current = in_array( $file, (array) get_site_option( 'auto_update_plugins', array() ), true );

		if ( $enabled && $current ) {
			if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
				$notes[] = __( 'The setting was saved, but this site blocks plugin file changes, so WordPress will not actually be able to run the automatic update.', 'mosmcp-abilities' );
			}

			if ( function_exists( 'wp_is_auto_update_enabled_for_type' ) && ! wp_is_auto_update_enabled_for_type( 'plugin' ) ) {
				$notes[] = __( 'The setting was saved, but automatic plugin updates are switched off for the whole site, so it will have no effect until that changes.', 'mosmcp-abilities' );
			}
		}

		if ( $previous === $current ) {
			$notes[] = __( 'That was already the setting, so nothing was changed.', 'mosmcp-abilities' );
		}

		return array(
			'plugin'   => $file,
			'name'     => $name,
			'previous' => $previous,
			'current'  => $current,
			'changed'  => ( $previous !== $current ),
			'notes'    => $notes,
		);
	}

	/**
	 * Names the temp-directory misconfiguration that makes every update fail.
	 *
	 * WP_Upgrader empties wp-content/upgrade before unpacking a package. When
	 * WP_TEMP_DIR points at that same folder, the archive WordPress just downloaded
	 * is deleted in that sweep, and the failure surfaces only as a missing archive
	 * file with no hint of the cause. It is a site configuration problem rather than
	 * anything to do with the plugin being updated, and it affects the Plugins
	 * screen in wp-admin exactly as much as it affects this ability.
	 *
	 * @return string Empty when the configuration is fine, otherwise a sentence naming it.
	 */
	private static function temp_dir_collision_note() {
		if ( ! defined( 'WP_TEMP_DIR' ) || '' === (string) WP_TEMP_DIR ) {
			return '';
		}

		$temp    = rtrim( wp_normalize_path( (string) WP_TEMP_DIR ), '/' );
		$upgrade = rtrim( wp_normalize_path( WP_CONTENT_DIR . '/upgrade' ), '/' );

		if ( '' === $temp || '' === $upgrade ) {
			return '';
		}

		if ( $temp !== $upgrade && 0 !== strpos( $temp . '/', $upgrade . '/' ) ) {
			return '';
		}

		return ' ' . sprintf(
			/* translators: %s: the configured WP_TEMP_DIR path. */
			__( 'Likely cause: this site sets WP_TEMP_DIR to "%s", which is inside the folder WordPress empties before unpacking an update, so the downloaded file is deleted before it can be used. Every plugin and theme update on this site will fail the same way, including from the Plugins screen. Point WP_TEMP_DIR at a different directory in wp-config.php.', 'mosmcp-abilities' ),
			(string) WP_TEMP_DIR
		);
	}

	/**
	 * Reads a plugin's display name.
	 *
	 * @param string $file Plugin file relative to the plugins directory.
	 * @return string
	 */
	private static function plugin_name( $file ) {
		Site_Support::load_plugin_api();

		$installed = get_plugins();

		if ( isset( $installed[ $file ]['Name'] ) ) {
			return (string) $installed[ $file ]['Name'];
		}

		return (string) $file;
	}

	/**
	 * Reads a plugin's version straight from its file on disk.
	 *
	 * The running process still holds whatever version was loaded at the start of
	 * the request, so an in-memory lookup cannot tell whether an update landed.
	 *
	 * @param string $file Plugin file relative to the plugins directory.
	 * @return string Version string, or empty when the file is gone.
	 */
	private static function version_on_disk( $file ) {
		$path = WP_PLUGIN_DIR . '/' . $file;

		if ( ! file_exists( $path ) ) {
			return '';
		}

		$data = get_plugin_data( $path, false, false );

		return isset( $data['Version'] ) ? (string) $data['Version'] : '';
	}
}
