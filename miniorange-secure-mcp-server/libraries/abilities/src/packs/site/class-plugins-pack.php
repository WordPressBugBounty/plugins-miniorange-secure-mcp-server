<?php
/**
 * Plugins ability pack: definitions for the mosmcp/plugin-* abilities.
 *
 * Installing and deleting a plugin are both deliberately absent, for the same
 * reason: each is irreversible from here, and an input flag cannot stand in for a
 * person agreeing to it. Both wait for a real out-of-band approval step. See the
 * note above plugin-set-auto-updates for what testing showed about that.
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
 * Class Plugins_Pack
 *
 * Declares the plugin abilities. Execute logic lives in Plugins_Provider.
 */
class Plugins_Pack extends Ability_Pack {

	/**
	 * Ability category slug.
	 */
	const CATEGORY = 'mosmcp-plugins';

	/**
	 * Ability category for plugin abilities.
	 *
	 * @return array Category definition.
	 */
	public function category() {
		return array(
			'slug'        => self::CATEGORY,
			'label'       => __( 'Plugins', 'mosmcp-abilities' ),
			'description' => __( 'Inspect, activate, deactivate, update, and remove the plugins installed on this site.', 'mosmcp-abilities' ),
		);
	}

	/**
	 * The plugin abilities.
	 *
	 * @return Ability[]
	 */
	public function abilities() {
		return array(
			$this->plugin_list(),
			$this->plugin_get(),
			$this->plugin_count_by_status(),
			$this->plugin_list_updates(),
			$this->plugin_activate(),
			$this->plugin_deactivate(),
			$this->plugin_update(),
			$this->plugin_set_auto_updates(),
		);
	}

	/**
	 * Defines the mosmcp/plugin-list ability.
	 *
	 * @return Ability
	 */
	private function plugin_list() {
		return new Ability(
			'mosmcp/plugin-list',
			array(
				'label'         => __( 'List Plugins', 'mosmcp-abilities' ),
				'description'   => __( 'Lists the plugins installed on this site, with version, author, whether each is active, and whether an update is available. Read-only. This is the place to start for any question about what a site is running.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'activate_plugins',
				'annotations'   => Site_Support::annotations( true, false, true, false ),
				'execute'       => array( Plugins_Provider::class, 'plugin_list' ),
				'input_schema'  => Schema::object(
					array(
						'status'          => Schema::str(
							__( 'Which plugins to return: "all", "active", or "inactive".', 'mosmcp-abilities' ),
							array(
								'enum'    => array( 'all', 'active', 'inactive' ),
								'default' => 'all',
							)
						),
						'search'          => Schema::str( __( 'Optional keyword matched against the plugin name, folder, and description.', 'mosmcp-abilities' ) ),
						'has_update'      => Schema::boolean( __( 'When true, returns only plugins with an update available.', 'mosmcp-abilities' ) ),
						'refresh_updates' => Schema::boolean(
							__( 'When true, asks WordPress.org for fresh update data first. Slower; leave false unless the answer looks stale.', 'mosmcp-abilities' ),
							array( 'default' => false )
						),
					)
				),
				'output_schema' => Schema::object(
					array(
						'showing' => Schema::int(),
						'total'   => Schema::int( __( 'Total installed plugins before any filter was applied.', 'mosmcp-abilities' ) ),
						'active'  => Schema::int( __( 'How many of the installed plugins are active.', 'mosmcp-abilities' ) ),
						'plugins' => Schema::arr( self::plugin_shape() ),
					),
					array( 'showing', 'total', 'plugins' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/plugin-get ability.
	 *
	 * @return Ability
	 */
	private function plugin_get() {
		return new Ability(
			'mosmcp/plugin-get',
			array(
				'label'         => __( 'Get Plugin Details', 'mosmcp-abilities' ),
				'description'   => __( 'Returns everything known about one installed plugin: name, version, author, description, whether it is active, whether an update is waiting, and the WordPress and PHP versions it needs. Read-only. Name the plugin by its folder, its file, or its display name; all three work.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'activate_plugins',
				'annotations'   => Site_Support::annotations( true, false, true, false ),
				'execute'       => array( Plugins_Provider::class, 'plugin_get' ),
				'input_schema'  => Schema::object(
					array( 'plugin' => self::plugin_input() ),
					array( 'plugin' )
				),
				'output_schema' => self::plugin_shape(),
			)
		);
	}

	/**
	 * Defines the mosmcp/plugin-count-by-status ability.
	 *
	 * @return Ability
	 */
	private function plugin_count_by_status() {
		return new Ability(
			'mosmcp/plugin-count-by-status',
			array(
				'label'         => __( 'Count Plugins by Status', 'mosmcp-abilities' ),
				'description'   => __( 'Returns how many plugins are installed, active, inactive, and waiting for an update, without listing them. Read-only. Cheaper than mosmcp/plugin-list when only the numbers matter.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'activate_plugins',
				'annotations'   => Site_Support::annotations( true, false, true, false ),
				'execute'       => array( Plugins_Provider::class, 'plugin_count_by_status' ),
				'input_schema'  => Schema::object( array() ),
				'output_schema' => Schema::object(
					array(
						'total'            => Schema::int(),
						'active'           => Schema::int(),
						'inactive'         => Schema::int(),
						'network_active'   => Schema::int( __( 'Plugins active across a whole multisite network. Always 0 on a single site.', 'mosmcp-abilities' ) ),
						'update_available' => Schema::int(),
						'auto_update_on'   => Schema::int(),
						'must_use'         => Schema::int( __( 'Plugins in the mu-plugins folder, which are always on and cannot be deactivated.', 'mosmcp-abilities' ) ),
					),
					array( 'total', 'active', 'inactive' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/plugin-list-updates ability.
	 *
	 * @return Ability
	 */
	private function plugin_list_updates() {
		return new Ability(
			'mosmcp/plugin-list-updates',
			array(
				'label'         => __( 'List Plugin Updates', 'mosmcp-abilities' ),
				'description'   => __( 'Lists just the plugins with an update available, with the installed and offered version and whether each is active. Read-only. Use mosmcp/plugin-update to apply one.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'update_plugins',
				'annotations'   => Site_Support::annotations( true, false, true, true ),
				'execute'       => array( Plugins_Provider::class, 'plugin_list_updates' ),
				'input_schema'  => Schema::object(
					array(
						'refresh' => Schema::boolean(
							__( 'When true, asks WordPress.org for fresh data first. Slower.', 'mosmcp-abilities' ),
							array( 'default' => false )
						),
					)
				),
				'output_schema' => Schema::object(
					array(
						'count'   => Schema::int(),
						'updates' => Schema::arr(
							Schema::object(
								array(
									'plugin'            => Schema::str(),
									'name'              => Schema::str(),
									'installed_version' => Schema::str(),
									'new_version'       => Schema::str(),
									'active'            => Schema::boolean(),
									'auto_update'       => Schema::boolean(),
								)
							)
						),
					),
					array( 'count', 'updates' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/plugin-activate ability.
	 *
	 * @return Ability
	 */
	private function plugin_activate() {
		return new Ability(
			'mosmcp/plugin-activate',
			array(
				'label'         => __( 'Activate Plugin', 'mosmcp-abilities' ),
				'description'   => __( 'Switches on a plugin that is already installed. Confirms afterwards that it really is active, because a plugin can fail during activation and WordPress does not always say so. Does nothing and reports no change if it was already active. This cannot install a plugin that is not on the site yet.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'activate_plugins',
				'annotations'   => Site_Support::annotations( false, false, true, false ),
				'execute'       => array( Plugins_Provider::class, 'plugin_activate' ),
				'input_schema'  => Schema::object(
					array( 'plugin' => self::plugin_input() ),
					array( 'plugin' )
				),
				'output_schema' => self::change_shape(),
			)
		);
	}

	/**
	 * Defines the mosmcp/plugin-deactivate ability.
	 *
	 * @return Ability
	 */
	private function plugin_deactivate() {
		return new Ability(
			'mosmcp/plugin-deactivate',
			array(
				'label'         => __( 'Deactivate Plugin', 'mosmcp-abilities' ),
				'description'   => __( 'Switches off a plugin without removing it, so its settings and data are kept and it can be switched back on. Confirms afterwards that it really is inactive. Does nothing and reports no change if it was already off.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'activate_plugins',
				'annotations'   => Site_Support::annotations( false, false, true, false ),
				'execute'       => array( Plugins_Provider::class, 'plugin_deactivate' ),
				'input_schema'  => Schema::object(
					array( 'plugin' => self::plugin_input() ),
					array( 'plugin' )
				),
				'output_schema' => self::change_shape(),
			)
		);
	}

	/**
	 * Defines the mosmcp/plugin-update ability.
	 *
	 * @return Ability
	 */
	private function plugin_update() {
		return new Ability(
			'mosmcp/plugin-update',
			array(
				'label'         => __( 'Update Plugin', 'mosmcp-abilities' ),
				'description'   => __( 'Updates one installed plugin to the newest version available. Replaces the plugin files, which cannot be undone from here, so it needs confirm set to true. A plugin that was active stays active, and the result is verified by reading the version back from disk rather than assuming the update worked. Check mosmcp/plugin-list-updates first to see what is on offer.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'update_plugins',
				'annotations'   => Site_Support::annotations( false, false, false, true ),
				'execute'       => array( Plugins_Provider::class, 'plugin_update' ),
				'input_schema'  => Schema::object(
					array(
						'plugin'         => self::plugin_input(),
						'confirm'        => Schema::boolean(
							__( 'Must be true. Replacing plugin files cannot be undone from here, so tell the person which plugin and which version change first.', 'mosmcp-abilities' ),
							array( 'default' => false )
						),
						'expect_version' => Schema::str( __( 'Optional. The version you expect to install. If a different version is on offer, the update is refused rather than installing something nobody reviewed.', 'mosmcp-abilities' ) ),
					),
					array( 'plugin', 'confirm' )
				),
				'output_schema' => Schema::object(
					array(
						'plugin'           => Schema::str(),
						'name'             => Schema::str(),
						'previous_version' => Schema::str(),
						'current_version'  => Schema::str( __( 'Version now on disk, read back after the update.', 'mosmcp-abilities' ) ),
						'updated'          => Schema::boolean( __( 'True only when the version on disk actually changed.', 'mosmcp-abilities' ) ),
						'was_active'       => Schema::boolean(),
						'is_active'        => Schema::boolean( __( 'Active state after the update, checked rather than assumed.', 'mosmcp-abilities' ) ),
						'notes'            => Schema::arr( Schema::str() ),
					),
					array( 'plugin', 'previous_version', 'current_version', 'updated' )
				),
			)
		);
	}

	/*
	 * There is deliberately no plugin-delete ability.
	 *
	 * It shipped in an earlier draft of this pack behind a `confirm` input, and
	 * testing showed why that is not a safeguard: asked to delete a plugin, the
	 * model received the confirmation refusal, set confirm to true itself, and
	 * called again in the same turn. The person was never asked. Every field of a
	 * tool call is under the model's control, so no input flag can stand in for
	 * human agreement — only an out-of-band approval can. Deleting a plugin is
	 * irreversible and often destroys its stored settings too, so it waits for that
	 * approval step rather than shipping with a guard that does not hold.
	 *
	 * Deactivating a plugin is reversible and remains available.
	 */

	/**
	 * Defines the mosmcp/plugin-set-auto-updates ability.
	 *
	 * @return Ability
	 */
	private function plugin_set_auto_updates() {
		return new Ability(
			'mosmcp/plugin-set-auto-updates',
			array(
				'label'         => __( 'Set Plugin Auto-Updates', 'mosmcp-abilities' ),
				'description'   => __( 'Turns WordPress automatic updates on or off for one plugin, so WordPress keeps it current on its own. Reversible at any time by calling again with the opposite value.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'update_plugins',
				'annotations'   => Site_Support::annotations( false, false, true, false ),
				'execute'       => array( Plugins_Provider::class, 'plugin_set_auto_updates' ),
				'input_schema'  => Schema::object(
					array(
						'plugin'  => self::plugin_input(),
						'enabled' => Schema::boolean( __( 'True to let WordPress update this plugin automatically, false to stop it.', 'mosmcp-abilities' ) ),
					),
					array( 'plugin', 'enabled' )
				),
				'output_schema' => Schema::object(
					array(
						'plugin'   => Schema::str(),
						'name'     => Schema::str(),
						'previous' => Schema::boolean(),
						'current'  => Schema::boolean(),
						'changed'  => Schema::boolean(),
						'notes'    => Schema::arr( Schema::str() ),
					),
					array( 'plugin', 'previous', 'current', 'changed' )
				),
			)
		);
	}

	/**
	 * The shared plugin-reference input property.
	 *
	 * @return array<string, mixed>
	 */
	private static function plugin_input() {
		return Schema::str( __( 'Which plugin. Accepts the folder name ("wordpress-seo"), the plugin file ("wordpress-seo/wp-seo.php"), or the display name ("Yoast SEO").', 'mosmcp-abilities' ) );
	}

	/**
	 * The shared per-plugin output shape.
	 *
	 * @return array<string, mixed>
	 */
	private static function plugin_shape() {
		return Schema::object(
			array(
				'plugin'           => Schema::str( __( 'Plugin file relative to the plugins folder. This is the identifier every other plugin ability accepts.', 'mosmcp-abilities' ) ),
				'slug'             => Schema::str( __( 'Plugin folder name.', 'mosmcp-abilities' ) ),
				'name'             => Schema::str(),
				'version'          => Schema::str(),
				'author'           => Schema::str(),
				'description'      => Schema::str(),
				'plugin_uri'       => Schema::str(),
				'requires_wp'      => Schema::str(),
				'requires_php'     => Schema::str(),
				'status'           => Schema::str( __( 'Either "active" or "inactive".', 'mosmcp-abilities' ) ),
				'network_active'   => Schema::boolean(),
				'update_available' => Schema::boolean(),
				'new_version'      => Schema::str(),
				'auto_update'      => Schema::boolean(),
				'is_host_plugin'   => Schema::boolean( __( 'True for the plugin providing this connection, which cannot be deactivated, updated, or deleted from here.', 'mosmcp-abilities' ) ),
			),
			array( 'plugin', 'name', 'status' )
		);
	}

	/**
	 * The shared output shape for activate and deactivate.
	 *
	 * @return array<string, mixed>
	 */
	private static function change_shape() {
		return Schema::object(
			array(
				'plugin'   => Schema::str(),
				'name'     => Schema::str(),
				'previous' => Schema::str( __( 'Status before the call: "active" or "inactive".', 'mosmcp-abilities' ) ),
				'current'  => Schema::str( __( 'Status afterwards, checked against the site rather than assumed.', 'mosmcp-abilities' ) ),
				'changed'  => Schema::boolean(),
				'notes'    => Schema::arr( Schema::str() ),
			),
			array( 'plugin', 'previous', 'current', 'changed' )
		);
	}
}
