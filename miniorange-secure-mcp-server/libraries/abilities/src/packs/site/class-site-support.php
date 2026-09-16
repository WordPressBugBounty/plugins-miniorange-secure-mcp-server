<?php
/**
 * Shared guards, error shaping, and resolution helpers for the site-administration packs.
 *
 * Everything in this class exists to stop an ability reporting success for work that
 * did not happen, or sending the caller down the wrong diagnostic path. That is the
 * one failure an end user cannot detect on their own.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Site;

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
 * Class Site_Support
 *
 * Static helpers shared by the Site Settings, Plugins, Themes, and Site Health packs.
 */
class Site_Support {

	/**
	 * Cause: the caller lacks a WordPress capability. Reconnecting as a stronger
	 * account can help; retrying the same call cannot.
	 */
	const CAUSE_CAPABILITY = 'capability';

	/**
	 * Cause: the site or host forbids the operation (a constant, a locked filesystem,
	 * a network-level restriction). Nothing the caller does differently will help.
	 */
	const CAUSE_HOST_ENVIRONMENT = 'host_environment';

	/**
	 * Cause: the named thing does not exist on this site.
	 */
	const CAUSE_NOT_FOUND = 'not_found';

	/**
	 * Cause: the input was malformed or outside the permitted range.
	 */
	const CAUSE_INVALID_INPUT = 'invalid_input';

	/**
	 * Cause: the operation is irreversible and needs an explicit confirmation.
	 */
	const CAUSE_CONFIRMATION_REQUIRED = 'confirmation_required';

	/**
	 * Cause: the site is in a state that blocks the operation (plugin still active,
	 * theme still in use). Changing that state first makes the call succeed.
	 */
	const CAUSE_CONFLICT = 'conflict';

	/**
	 * Cause: the target is the plugin serving this very request.
	 */
	const CAUSE_SELF_PROTECTED = 'self_protected';

	/**
	 * Cause: WordPress itself failed the operation.
	 */
	const CAUSE_WP_CORE = 'wp_core';

	/**
	 * Builds the four MCP annotation hints.
	 *
	 * @param bool $read_only   Read-only hint.
	 * @param bool $destructive Destructive hint.
	 * @param bool $idempotent  Idempotent hint.
	 * @param bool $open_world  Open-world hint.
	 * @return array<string, bool>
	 */
	public static function annotations( $read_only, $destructive, $idempotent, $open_world ) {
		return array(
			'readonly'    => $read_only,
			'destructive' => $destructive,
			'idempotent'  => $idempotent,
			'open_world'  => $open_world,
		);
	}

	/**
	 * Builds a WP_Error carrying the model-facing error contract.
	 *
	 * Every refusal states three things a language model can act on: why it failed
	 * (`cause`), whether repeating the identical call could ever succeed (`retryable`),
	 * and, where one exists, the ability that does work instead (`use_instead`).
	 * Without these an agent retries the same refused call until it gives up.
	 *
	 * @param string               $code      Machine-readable error code.
	 * @param string               $message   Human-readable message.
	 * @param string               $cause     One of the CAUSE_* constants.
	 * @param bool                 $retryable Whether repeating the same call could succeed.
	 * @param array<string, mixed> $extra     Additional data merged into the error payload.
	 * @return WP_Error
	 */
	public static function error( $code, $message, $cause, $retryable = false, array $extra = array() ) {
		$data = array_merge(
			$extra,
			array(
				'cause'     => $cause,
				'retryable' => (bool) $retryable,
			)
		);

		return new WP_Error( $code, $message, $data );
	}

	/**
	 * Refuses when the site forbids plugin, theme, and core file changes.
	 *
	 * Managed hosts commonly define DISALLOW_FILE_MODS. Without naming the constant,
	 * the underlying failure surfaces from deep inside WordPress as a permissions
	 * error, which sends people checking folder ownership or opening a host ticket
	 * when the cause is a one-line site policy.
	 *
	 * @return WP_Error|null WP_Error when file modifications are locked, null otherwise.
	 */
	public static function file_mods_locked() {
		if ( ! defined( 'DISALLOW_FILE_MODS' ) || ! DISALLOW_FILE_MODS ) {
			return null;
		}

		return self::error(
			'mosmcp_file_mods_disabled',
			__( 'This site has installing, updating, and deleting plugins and themes switched off, via the DISALLOW_FILE_MODS constant in wp-config.php or a host-level setting. This is a deliberate site policy, not a permissions problem, and no account can override it from here. Reading plugin and theme information still works.', 'mosmcp-abilities' ),
			self::CAUSE_HOST_ENVIRONMENT,
			false,
			array( 'constant' => 'DISALLOW_FILE_MODS' )
		);
	}

	/**
	 * Whether a plugin file belongs to the plugin that bundles this library.
	 *
	 * Determined by containment rather than by name: if this library file lives
	 * inside the candidate plugin's own directory, that plugin is the host. This
	 * keeps the library host-agnostic while still letting it refuse to deactivate,
	 * update, or delete the plugin serving the current request.
	 *
	 * @param string $plugin_file Plugin file, relative to the plugins directory.
	 * @return bool
	 */
	public static function is_host_plugin( $plugin_file ) {
		$dir = dirname( (string) $plugin_file );

		// A single-file plugin has no directory of its own and cannot contain this library.
		if ( '' === $dir || '.' === $dir ) {
			return false;
		}

		$plugin_root = realpath( WP_PLUGIN_DIR . '/' . $dir );
		$self        = realpath( __DIR__ );

		if ( false === $plugin_root || false === $self ) {
			return false;
		}

		$plugin_root = rtrim( wp_normalize_path( $plugin_root ), '/' );
		$self        = wp_normalize_path( $self );

		return 0 === strpos( $self, $plugin_root . '/' );
	}

	/**
	 * Refuses an operation that targets the plugin serving this request.
	 *
	 * Deactivating, updating, or deleting that plugin severs the connection the
	 * caller is speaking through, mid-call. The response never arrives, and the
	 * site is left with no MCP server, so the caller cannot undo it either.
	 *
	 * @param string $plugin_file Plugin file, relative to the plugins directory.
	 * @param string $verb        Operation being attempted, for the message.
	 * @return WP_Error|null WP_Error when the target is the host plugin, null otherwise.
	 */
	public static function self_protection( $plugin_file, $verb ) {
		if ( ! self::is_host_plugin( $plugin_file ) ) {
			return null;
		}

		return self::error(
			'mosmcp_self_protected',
			sprintf(
				/* translators: %s: the operation being attempted, for example "deactivate". */
				__( 'Refused: that is the plugin providing this connection, and to %s it would cut the connection you are using, mid-request. The response would never reach you and the site would be left with no MCP server, so you could not undo it from here either. A person can do it from the Plugins screen in wp-admin.', 'mosmcp-abilities' ),
				$verb
			),
			self::CAUSE_SELF_PROTECTED,
			false,
			array( 'plugin' => (string) $plugin_file )
		);
	}

	/**
	 * Refuses an irreversible operation that was not explicitly confirmed.
	 *
	 * @param array<string, mixed> $input  Ability input.
	 * @param string               $what   Short description of what would be lost.
	 * @return WP_Error|null WP_Error when confirmation is absent, null otherwise.
	 */
	public static function require_confirm( array $input, $what ) {
		if ( isset( $input['confirm'] ) && true === $input['confirm'] ) {
			return null;
		}

		return self::error(
			'mosmcp_confirmation_required',
			sprintf(
				/* translators: %s: description of what the operation destroys. */
				__( 'This cannot be undone: %s. Tell the person exactly what will be removed, and call again with confirm set to true only once they have agreed.', 'mosmcp-abilities' ),
				$what
			),
			self::CAUSE_CONFIRMATION_REQUIRED,
			true
		);
	}

	/**
	 * Refuses plugin mutation on multisite, where it is a network-level action.
	 *
	 * On a network install, plugins are installed, updated, and deleted for every
	 * site at once, so the action belongs to a network administrator working in the
	 * network admin screens. Attempting it from a subsite either fails obscurely or
	 * affects sites the caller never intended to touch.
	 *
	 * @param string $verb Operation being attempted, for the message.
	 * @return WP_Error|null WP_Error on multisite, null otherwise.
	 */
	public static function multisite_guard( $verb ) {
		if ( ! is_multisite() ) {
			return null;
		}

		return self::error(
			'mosmcp_multisite_network_action',
			sprintf(
				/* translators: %s: the operation being attempted, for example "update". */
				__( 'This is a multisite network, where plugin files are shared by every site, so to %s a plugin is a network-wide action rather than a per-site one. It has to be done by a network administrator under My Sites then Network Admin then Plugins. Activating and deactivating a plugin for this individual site still works here.', 'mosmcp-abilities' ),
				$verb
			),
			self::CAUSE_HOST_ENVIRONMENT,
			false
		);
	}

	/**
	 * Loads the wp-admin plugin API, which is not present on front-end requests.
	 *
	 * @return void
	 */
	public static function load_plugin_api() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
	}

	/**
	 * Loads the wp-admin upgrade API used by the update abilities.
	 *
	 * @return void
	 */
	public static function load_upgrade_api() {
		self::load_plugin_api();

		if ( ! class_exists( 'Plugin_Upgrader' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		}

		if ( ! function_exists( 'request_filesystem_credentials' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if ( ! function_exists( 'get_plugin_updates' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}
	}

	/**
	 * Resolves a caller-supplied plugin reference to an installed plugin file.
	 *
	 * Accepts the three forms a person or a model actually uses: the full plugin
	 * file ("wordpress-seo/wp-seo.php"), the containing folder ("wordpress-seo"),
	 * or the display name ("Yoast SEO"). A single-file plugin's own basename works
	 * too. Without this, two of those three forms fail and the caller retries in a
	 * loop rather than correcting itself.
	 *
	 * @param string $reference Plugin reference supplied by the caller.
	 * @return string|WP_Error The plugin file relative to the plugins directory, or an error.
	 */
	public static function resolve_plugin_file( $reference ) {
		self::load_plugin_api();

		$reference = trim( (string) $reference );

		if ( '' === $reference ) {
			return self::error(
				'mosmcp_plugin_reference_empty',
				__( 'No plugin was named. Pass the plugin folder ("wordpress-seo"), its file ("wordpress-seo/wp-seo.php"), or its display name ("Yoast SEO").', 'mosmcp-abilities' ),
				self::CAUSE_INVALID_INPUT,
				true
			);
		}

		$installed = get_plugins();

		// Exact plugin file.
		if ( isset( $installed[ $reference ] ) ) {
			return $reference;
		}

		$needle = strtolower( $reference );

		// Folder name, single-file basename, or basename without the .php suffix.
		foreach ( array_keys( $installed ) as $file ) {
			$dir  = dirname( $file );
			$base = basename( $file );

			if ( '.' !== $dir && strtolower( $dir ) === $needle ) {
				return $file;
			}

			if ( strtolower( $base ) === $needle || strtolower( $base ) === $needle . '.php' ) {
				return $file;
			}
		}

		// Display name, case-insensitive.
		foreach ( $installed as $file => $data ) {
			if ( isset( $data['Name'] ) && strtolower( (string) $data['Name'] ) === $needle ) {
				return $file;
			}
		}

		return self::error(
			'mosmcp_plugin_not_found',
			sprintf(
				/* translators: 1: the plugin reference supplied, 2: comma-separated list of similar plugin names. */
				__( 'No installed plugin matches "%1$s". Installed plugins with a similar name: %2$s. Use mosmcp/plugin-list to see everything installed. Note that this ability only sees plugins already on the site; it cannot install new ones.', 'mosmcp-abilities' ),
				$reference,
				self::suggest_plugins( $needle, $installed )
			),
			self::CAUSE_NOT_FOUND,
			false,
			array( 'use_instead' => 'mosmcp/plugin-list' )
		);
	}

	/**
	 * Builds a short list of installed plugins whose name or folder resembles a reference.
	 *
	 * @param string                       $needle    Lower-cased reference to match against.
	 * @param array<string, array<string>> $installed Result of get_plugins().
	 * @return string Comma-separated suggestion list, or a "none" phrase.
	 */
	private static function suggest_plugins( $needle, array $installed ) {
		/*
		 * Cast both results: preg_replace() returns null on a PCRE failure, and a
		 * null token passes an "is it empty" test while making strpos() match every
		 * plugin, so the suggestion list would silently become "the first five
		 * plugins installed" rather than the ones that resemble the request.
		 */
		$token = (string) preg_replace( '/[^a-z0-9]+/', '', $needle );

		// Nothing alphanumeric to match on, so there is nothing to suggest.
		if ( '' === $token ) {
			return __( 'none', 'mosmcp-abilities' );
		}

		$matches = array();

		foreach ( $installed as $file => $data ) {
			$name = isset( $data['Name'] ) ? (string) $data['Name'] : (string) $file;
			$hay  = (string) preg_replace( '/[^a-z0-9]+/', '', strtolower( $name . ' ' . dirname( (string) $file ) ) );

			if ( false !== strpos( $hay, $token ) ) {
				$matches[] = $name;
			}

			if ( count( $matches ) >= 5 ) {
				break;
			}
		}

		if ( empty( $matches ) ) {
			return __( 'none', 'mosmcp-abilities' );
		}

		return implode( ', ', $matches );
	}

	/**
	 * Describes one installed plugin in the shape every plugin ability returns.
	 *
	 * @param string               $file    Plugin file relative to the plugins directory.
	 * @param array<string, mixed> $data    Plugin header data from get_plugins().
	 * @param array<string, mixed> $updates Update payload keyed by plugin file, from the update transient.
	 * @return array<string, mixed>
	 */
	public static function describe_plugin( $file, array $data, array $updates = array() ) {
		$network_active = is_multisite() && is_plugin_active_for_network( $file );
		$active         = $network_active || is_plugin_active( $file );
		$update         = isset( $updates[ $file ] ) ? $updates[ $file ] : null;

		$auto_updates = (array) get_site_option( 'auto_update_plugins', array() );

		return array(
			'plugin'           => (string) $file,
			'slug'             => '.' === dirname( $file ) ? basename( $file, '.php' ) : dirname( $file ),
			'name'             => isset( $data['Name'] ) ? (string) $data['Name'] : '',
			'version'          => isset( $data['Version'] ) ? (string) $data['Version'] : '',
			'author'           => isset( $data['Author'] ) ? wp_strip_all_tags( (string) $data['Author'] ) : '',
			'description'      => isset( $data['Description'] ) ? wp_strip_all_tags( (string) $data['Description'] ) : '',
			'plugin_uri'       => isset( $data['PluginURI'] ) ? (string) $data['PluginURI'] : '',
			'requires_wp'      => isset( $data['RequiresWP'] ) ? (string) $data['RequiresWP'] : '',
			'requires_php'     => isset( $data['RequiresPHP'] ) ? (string) $data['RequiresPHP'] : '',
			'status'           => $active ? 'active' : 'inactive',
			'network_active'   => (bool) $network_active,
			'update_available' => null !== $update,
			'new_version'      => ( null !== $update && isset( $update->new_version ) ) ? (string) $update->new_version : '',
			'auto_update'      => in_array( $file, $auto_updates, true ),
			'is_host_plugin'   => self::is_host_plugin( $file ),
		);
	}

	/**
	 * Returns the available plugin updates, keyed by plugin file.
	 *
	 * WordPress stores each plugin update as an OBJECT. Entries of any other type
	 * are dropped, because a caller that reported them would say an update is
	 * available while being unable to name a version.
	 *
	 * @param bool $refresh Whether to ask WordPress.org for fresh data first.
	 * @return array<string, object>
	 */
	public static function plugin_updates( $refresh = false ) {
		if ( $refresh ) {
			wp_update_plugins();
		}

		$transient = get_site_transient( 'update_plugins' );

		if ( ! is_object( $transient ) || ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			return array();
		}

		return array_filter( $transient->response, 'is_object' );
	}

	/**
	 * Returns the available theme updates, keyed by theme stylesheet.
	 *
	 * WordPress stores each theme update as an ARRAY, unlike the plugin transient
	 * above, which stores objects. Both transients are filterable, so a plugin can
	 * put anything into either one. Entries that are not arrays are dropped here
	 * rather than guarded at each call site, because indexing a stray object with
	 * ['new_version'] raises a fatal Error instead of simply returning null, and
	 * isset() does not protect against it the way it does for a scalar.
	 *
	 * @param bool $refresh Whether to ask WordPress.org for fresh data first.
	 * @return array<string, array<string, mixed>>
	 */
	public static function theme_updates( $refresh = false ) {
		if ( $refresh ) {
			wp_update_themes();
		}

		$transient = get_site_transient( 'update_themes' );

		if ( ! is_object( $transient ) || ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			return array();
		}

		return array_filter( $transient->response, 'is_array' );
	}

	/**
	 * Names any plugin that filters the core update feed.
	 *
	 * A security or maintenance plugin can suppress core update data entirely. Without
	 * this note, "no update available" reads as a fact about WordPress.org when it is
	 * really a fact about this site's filters, and the caller reports a site as current
	 * when it is not.
	 *
	 * @return string Empty string when nothing filters the feed, otherwise a warning sentence.
	 */
	public static function core_update_filter_note() {
		global $wp_filter;

		$hooks = array( 'pre_site_transient_update_core', 'site_transient_update_core' );

		foreach ( $hooks as $hook ) {
			if ( isset( $wp_filter[ $hook ] ) && ! empty( $wp_filter[ $hook ]->callbacks ) ) {
				return __( 'Another plugin on this site filters WordPress core update data, so this answer may not match what WordPress.org actually offers. Treat "no update available" as uncertain and check Dashboard then Updates in wp-admin to confirm.', 'mosmcp-abilities' );
			}
		}

		return '';
	}

	/**
	 * Reads a boolean ability input with an explicit default.
	 *
	 * @param array<string, mixed> $input    Ability input.
	 * @param string               $key      Input key.
	 * @param bool                 $fallback Value used when the key is absent or unparseable.
	 * @return bool
	 */
	public static function bool_input( array $input, $key, $fallback = false ) {
		if ( ! array_key_exists( $key, $input ) ) {
			return (bool) $fallback;
		}

		return filter_var( $input[ $key ], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) ?? (bool) $fallback;
	}

	/**
	 * Describes a page by ID in the compact shape the settings abilities return.
	 *
	 * @param int $page_id Page ID, or 0 when unset.
	 * @return array<string, mixed>
	 */
	public static function describe_page( $page_id ) {
		$page_id = absint( $page_id );

		if ( $page_id < 1 ) {
			return array(
				'id'     => 0,
				'title'  => '',
				'url'    => '',
				'status' => '',
			);
		}

		$page = get_post( $page_id );

		if ( ! $page ) {
			return array(
				'id'     => $page_id,
				'title'  => '',
				'url'    => '',
				'status' => 'missing',
			);
		}

		return array(
			'id'     => (int) $page->ID,
			'title'  => (string) get_the_title( $page ),
			'url'    => (string) get_permalink( $page ),
			'status' => (string) $page->post_status,
		);
	}

	/**
	 * Validates that a page ID refers to an existing, usable page.
	 *
	 * @param int    $page_id Page ID supplied by the caller.
	 * @param string $label   What the page is being used for, for the message.
	 * @return WP_Error|null WP_Error when the page is unusable, null otherwise.
	 */
	public static function validate_page( $page_id, $label ) {
		$page_id = absint( $page_id );
		$page    = $page_id > 0 ? get_post( $page_id ) : null;

		if ( ! $page || 'page' !== $page->post_type ) {
			return self::error(
				'mosmcp_page_not_found',
				sprintf(
					/* translators: 1: page ID supplied, 2: what the page would be used for. */
					__( 'No page with ID %1$d exists, so it cannot be used as the %2$s. Use mosmcp/page-list-all to find the right page ID, or mosmcp/page-create-draft to make one first.', 'mosmcp-abilities' ),
					$page_id,
					$label
				),
				self::CAUSE_NOT_FOUND,
				false,
				array( 'use_instead' => 'mosmcp/page-list-all' )
			);
		}

		return null;
	}
}
