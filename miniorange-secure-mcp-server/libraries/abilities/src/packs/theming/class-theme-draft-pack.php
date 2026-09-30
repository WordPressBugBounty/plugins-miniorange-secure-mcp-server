<?php
/**
 * Theme-draft ability pack: see what is pending, preview it, test it, publish it,
 * discard it, and restore a previous publish.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Theming;

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
 * Class Theme_Draft_Pack
 */
class Theme_Draft_Pack extends Ability_Pack {

	/**
	 * Ability category slug.
	 */
	const CATEGORY = 'mosmcp-theme-draft';

	/**
	 * Capability every draft ability requires. Publishing and restoring file
	 * changes additionally require edit_themes, checked when they run.
	 */
	const CAPABILITY = 'edit_theme_options';

	/**
	 * Ability category.
	 *
	 * @return array Category definition.
	 */
	public function category() {
		return array(
			'slug'        => self::CATEGORY,
			'label'       => __( 'Theme: Draft and Publish', 'mosmcp-abilities' ),
			'description' => __( 'Every theme change waits in one draft. See it, preview it, test that the site still loads with it, publish it with an automatic backup and rollback, or throw it away — and restore a previous publish.', 'mosmcp-abilities' ),
		);
	}

	/**
	 * The abilities.
	 *
	 * @return Ability[]
	 */
	public function abilities() {
		return array(
			$this->status(),
			$this->preview(),
			$this->check(),
			$this->publish(),
			$this->discard(),
			$this->restore(),
		);
	}

	/**
	 * Defines mosmcp/theme-draft-status.
	 *
	 * @return Ability
	 */
	private function status() {
		return $this->ability(
			'mosmcp/theme-draft-status',
			__( 'Theme Draft Status', 'mosmcp-abilities' ),
			__( 'Shows every theme change waiting in the draft, whether publishing it would overwrite something changed on the live site since, whether this host supports theme editing and its safety checks, and the recent backups that can be restored. Read-only. Start here before publishing. Also known as: pending theme changes, unpublished changes, staging.', 'mosmcp-abilities' ),
			array( Theme_Draft_Provider::class, 'status' ),
			Site_Support::annotations( true, false, true, false ),
			Schema::object( array() ),
			Schema::object(
				array(
					'draft'       => Block_Theme_Pack::draft_summary_schema(),
					'stale'       => Schema::boolean( __( 'True when a draft exists for a theme that is no longer active.', 'mosmcp-abilities' ) ),
					'stale_theme' => Schema::str(),
					'conflicts'   => Schema::arr( Schema::str(), __( 'Live changes made since the draft started that publishing would overwrite.', 'mosmcp-abilities' ) ),
					'preflight'   => Schema::object(
						array(
							'theme'             => Schema::str(),
							'is_block_theme'    => Schema::boolean(),
							'is_child_theme'    => Schema::boolean(),
							'filesystem_method' => Schema::str(),
							'files_writable'    => Schema::boolean(),
							'file_editing'      => Schema::boolean(),
							'file_editing_note' => Schema::str(),
							'loopback_ok'       => Schema::boolean(),
							'loopback_note'     => Schema::str(),
							'receives_updates'  => Schema::boolean(),
						)
					),
					'backups'     => Schema::arr(
						Schema::object(
							array(
								'id'            => Schema::str(),
								'created'       => Schema::str(),
								'files'         => Schema::arr( Schema::str() ),
								'templates'     => Schema::arr( Schema::str() ),
								'global_styles' => Schema::boolean(),
								'patterns'      => Schema::arr( Schema::str() ),
								'restored'      => Schema::boolean(),
							)
						)
					),
					'notes'       => Schema::arr( Schema::str() ),
				),
				array( 'draft', 'preflight' )
			)
		);
	}

	/**
	 * Defines mosmcp/theme-draft-preview.
	 *
	 * @return Ability
	 */
	private function preview() {
		return $this->ability(
			'mosmcp/theme-draft-preview',
			__( 'Preview Theme Draft', 'mosmcp-abilities' ),
			__( 'Returns a link that shows the whole site with the pending theme changes applied — templates, styles and theme files together — to a signed-in administrator, while visitors keep seeing the live site. The preview includes the site\'s existing Site Editor customisations, so it shows exactly what publishing will produce. Also known as: staging link, see changes before publishing, draft preview.', 'mosmcp-abilities' ),
			array( Theme_Draft_Provider::class, 'preview' ),
			Site_Support::annotations( false, false, true, false ),
			Schema::object(
				array(
					'path' => Schema::str( __( 'Optional page to open, for example "/about/" or "/?p=12". Defaults to the home page.', 'mosmcp-abilities' ) ),
				)
			),
			Schema::object(
				array(
					'url'          => Schema::str(),
					'expires'      => Schema::str(),
					'change_count' => Schema::int(),
					'draft'        => Block_Theme_Pack::draft_summary_schema(),
					'notes'        => Schema::arr( Schema::str() ),
				),
				array( 'url' )
			)
		);
	}

	/**
	 * Defines mosmcp/theme-draft-check.
	 *
	 * @return Ability
	 */
	private function check() {
		return $this->ability(
			'mosmcp/theme-draft-check',
			__( 'Check Theme Draft', 'mosmcp-abilities' ),
			__( 'Tests that the site still loads with the pending theme changes applied — requesting wp-admin, the home page and the newest post and page with the draft in place, and catching any fatal PHP error or page that comes back empty. Other page types (404, archives, shop pages) are not loaded — without publishing anything. Also reports anything on the live site that changed since the draft started. Publishing runs this check itself; this is for finding problems early.', 'mosmcp-abilities' ),
			array( Theme_Draft_Provider::class, 'check' ),
			Site_Support::annotations( true, false, true, false ),
			Schema::object( array() ),
			self::health_output(
				array(
					'conflicts' => Schema::arr( Schema::str() ),
					'warnings'  => Schema::arr( Schema::str( __( 'Problems a page load cannot reveal, such as an area left empty by a deleted pattern.', 'mosmcp-abilities' ) ) ),
				)
			)
		);
	}

	/**
	 * Defines mosmcp/theme-draft-publish.
	 *
	 * @return Ability
	 */
	private function publish() {
		return $this->ability(
			'mosmcp/theme-draft-publish',
			__( 'Publish Theme Draft', 'mosmcp-abilities' ),
			__( 'Makes the pending theme changes live. First it refuses if anything they touch changed on the live site since the draft started; then, when theme code changed, it proves the site loads with the draft before touching anything; then it backs up the live versions, applies the changes, and checks the live site (wp-admin, the home page and the newest post and page, signed in and as a visitor) — rolling every change back automatically if a page fails. The draft is shared by everyone who edits this site\'s theme, including other AI conversations, so check mosmcp/theme-draft-status and tell the person every pending change before publishing. Needs confirm set to true once the person has agreed.', 'mosmcp-abilities' ),
			array( Theme_Draft_Provider::class, 'publish' ),
			Site_Support::annotations( false, true, false, false ),
			Schema::object(
				array(
					'confirm' => Schema::boolean( __( 'Must be true, set only after the person has agreed to publish.', 'mosmcp-abilities' ) ),
				)
			),
			Schema::object(
				array(
					'published'      => Schema::boolean(),
					'change_count'   => Schema::int(),
					'templates'      => Schema::arr( Schema::str() ),
					'template_parts' => Schema::arr( Schema::str() ),
					'global_styles'  => Schema::str(),
					'patterns'       => Schema::arr( Schema::str() ),
					'files'          => Schema::arr( Schema::str() ),
					'backup_id'      => Schema::str( __( 'Pass to mosmcp/theme-restore-backup to undo this publish.', 'mosmcp-abilities' ) ),
					'checked_before' => Schema::str(),
					'checked_after'  => Schema::str(),
					'notes'          => Schema::arr( Schema::str() ),
				),
				array( 'published', 'backup_id' )
			)
		);
	}

	/**
	 * Defines mosmcp/theme-draft-discard.
	 *
	 * @return Ability
	 */
	private function discard() {
		return $this->ability(
			'mosmcp/theme-draft-discard',
			__( 'Discard Theme Draft', 'mosmcp-abilities' ),
			__( 'Throws away every pending theme change. The live site is untouched, because the draft never affected it. Needs confirm set to true when the draft holds changes.', 'mosmcp-abilities' ),
			array( Theme_Draft_Provider::class, 'discard' ),
			Site_Support::annotations( false, true, true, false ),
			Schema::object(
				array(
					'confirm' => Schema::boolean( __( 'Must be true when the draft holds changes, set only after the person has agreed.', 'mosmcp-abilities' ) ),
				)
			),
			Schema::object(
				array(
					'discarded'     => Schema::boolean(),
					'files_removed' => Schema::boolean(),
					'change_count'  => Schema::int(),
					'notes'         => Schema::arr( Schema::str() ),
				),
				array( 'discarded' )
			)
		);
	}

	/**
	 * Defines mosmcp/theme-restore-backup.
	 *
	 * @return Ability
	 */
	private function restore() {
		return $this->ability(
			'mosmcp/theme-restore-backup',
			__( 'Restore Theme Backup', 'mosmcp-abilities' ),
			__( 'Undoes a theme publish: puts back the files, templates, styles and patterns exactly as they were before it, from the backup taken at the time, then checks the site loads. Defaults to the most recent publish. Needs confirm set to true. Also known as: undo theme changes, roll back, revert publish.', 'mosmcp-abilities' ),
			array( Theme_Draft_Provider::class, 'restore' ),
			Site_Support::annotations( false, true, false, false ),
			Schema::object(
				array(
					'backup_id' => Schema::str( __( 'Backup to restore, from mosmcp/theme-draft-status. Defaults to the most recent unrestored one.', 'mosmcp-abilities' ) ),
					'confirm'   => Schema::boolean( __( 'Must be true, set only after the person has agreed.', 'mosmcp-abilities' ) ),
				)
			),
			Schema::object(
				array(
					'restored'      => Schema::boolean(),
					'backup_id'     => Schema::str(),
					'backup_taken'  => Schema::str(),
					'files'         => Schema::arr( Schema::str() ),
					'templates'     => Schema::arr( Schema::str() ),
					'global_styles' => Schema::boolean(),
					'health'        => Schema::str(),
					'notes'         => Schema::arr( Schema::str() ),
				),
				array( 'restored', 'backup_id' )
			)
		);
	}

	/**
	 * Output of a health check.
	 *
	 * @param array<string, mixed> $extra Additional properties.
	 * @return array<string, mixed>
	 */
	private static function health_output( array $extra = array() ) {
		return Schema::object(
			array_merge(
				array(
					'ok'         => Schema::boolean( __( 'True when every page loaded without a fatal error.', 'mosmcp-abilities' ) ),
					'conclusive' => Schema::boolean( __( 'False when at least one page could not be checked properly.', 'mosmcp-abilities' ) ),
					'target'     => Schema::str(),
					'checks'     => Schema::arr(
						Schema::object(
							array(
								'page'        => Schema::str(),
								'status'      => Schema::str( __( '"ok", "fatal" or "unverified".', 'mosmcp-abilities' ) ),
								'http_status' => Schema::int(),
								'message'     => Schema::str(),
								'file'        => Schema::str(),
								'line'        => Schema::int(),
							)
						)
					),
					'note'       => Schema::str(),
				),
				$extra
			),
			array( 'ok', 'checks' )
		);
	}

	/**
	 * Builds an ability with the pack's shared settings.
	 *
	 * @param string               $name        Name.
	 * @param string               $label       Label.
	 * @param string               $description Description.
	 * @param callable             $execute     Execute callback.
	 * @param array<string, bool>  $annotations Annotations.
	 * @param array<string, mixed> $input       Input schema.
	 * @param array<string, mixed> $output      Output schema.
	 * @return Ability
	 */
	private function ability( $name, $label, $description, $execute, array $annotations, array $input, array $output ) {
		return new Ability(
			$name,
			array(
				'label'         => $label,
				'description'   => $description,
				'category'      => self::CATEGORY,
				'capability'    => self::CAPABILITY,
				'annotations'   => $annotations,
				'execute'       => $execute,
				'input_schema'  => $input,
				'output_schema' => $output,
			)
		);
	}
}
