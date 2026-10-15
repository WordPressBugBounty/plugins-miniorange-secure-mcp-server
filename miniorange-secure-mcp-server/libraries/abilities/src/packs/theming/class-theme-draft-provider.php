<?php
/**
 * Execute logic for the theme-draft lifecycle: status, preview, check, publish,
 * discard and restore.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Theming;

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
 * Class Theme_Draft_Provider
 */
class Theme_Draft_Provider {

	/**
	 * Reports the draft, whether publishing would conflict, host readiness and recent backups.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>
	 */
	public static function status( $input = array() ) {
		unset( $input );

		$raw   = Theme_Draft::raw();
		$draft = Theme_Draft::current();
		$stale = null !== $raw && null === $draft;
		$notes = array();

		if ( $stale ) {
			$notes[] = sprintf(
				/* translators: %s: theme slug. */
				__( 'A draft exists for the theme "%s", which is no longer active. Discard it before starting a new draft.', 'mosmcp-abilities' ),
				(string) $raw['stylesheet']
			);
		} elseif ( null === $draft || ! Theme_Draft::has_changes( $draft ) ) {
			$notes[] = __( 'There are no pending theme changes. Changes made with the theme abilities are collected in a draft here until published.', 'mosmcp-abilities' );
		} else {
			$notes[] = __( 'Visitors still see the live site. Preview with mosmcp/theme-draft-preview, test with mosmcp/theme-draft-check, then publish with mosmcp/theme-draft-publish.', 'mosmcp-abilities' );
		}

		$backups = array();
		foreach ( Theme_Publisher::backups() as $backup ) {
			$backups[] = array(
				'id'            => (string) $backup['id'],
				'created'       => gmdate( 'Y-m-d H:i:s', (int) $backup['created_gmt'] ) . ' UTC',
				'files'         => array_keys( (array) $backup['files'] ),
				'templates'     => array_map(
					static function ( $entry ) {
						return (string) $entry['slug'];
					},
					(array) $backup['templates']
				),
				'global_styles' => is_array( $backup['styles'] ),
				'patterns'      => array_map(
					static function ( $entry ) {
						$title = get_the_title( (int) $entry['id'] );
						return sprintf(
							'%1$s #%2$d (%3$s)',
							'' !== $title ? $title : __( 'pattern', 'mosmcp-abilities' ),
							(int) $entry['id'],
							'create' === $entry['action'] ? __( 'created by that publish', 'mosmcp-abilities' ) : __( 'updated', 'mosmcp-abilities' )
						);
					},
					isset( $backup['patterns'] ) ? (array) $backup['patterns'] : array()
				),
				'restored'      => ! empty( $backup['restored'] ),
			);
		}

		return array(
			'draft'       => Theme_Draft::summary( $draft ),
			'stale'       => $stale,
			'stale_theme' => $stale ? (string) $raw['stylesheet'] : '',
			'conflicts'   => ( null !== $draft && Theme_Draft::has_changes( $draft ) ) ? Theme_Publisher::conflicts( $draft ) : array(),
			'preflight'   => Theme_Support::preflight(),
			'backups'     => $backups,
			'notes'       => $notes,
		);
	}

	/**
	 * Returns a link that shows the site with the draft applied.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function preview( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$draft = self::require_changes();
		if ( is_wp_error( $draft ) ) {
			return $draft;
		}

		$token = Theme_Draft::ensure_token( $draft );
		Theme_Draft::save( $draft );

		$path = isset( $input['path'] ) ? '/' . ltrim( (string) wp_parse_url( (string) $input['path'], PHP_URL_PATH ), '/' ) : '/';

		return array(
			'url'          => Theme_Preview::url( $token, $path ),
			'expires'      => gmdate( 'Y-m-d H:i:s', (int) $draft['token_expires'] ) . ' UTC',
			'change_count' => Theme_Draft::change_count( $draft ),
			'draft'        => Theme_Draft::summary( $draft ),
			'notes'        => array(
				__( 'Open the link in a browser where you are signed in to this site as an administrator. The preview follows you as you click around, including into wp-admin, until you choose "Exit preview" in the toolbar.', 'mosmcp-abilities' ),
				__( 'The link only works for signed-in administrators, and visitors keep seeing the live site throughout.', 'mosmcp-abilities' ),
			),
		);
	}

	/**
	 * Tests that the site loads with the draft applied, without publishing anything.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function check( $input = array() ) {
		unset( $input );
		$draft = self::require_changes();
		if ( is_wp_error( $draft ) ) {
			return $draft;
		}

		$token = Theme_Draft::ensure_token( $draft );
		Theme_Draft::save( $draft );

		$health              = Theme_Health::run( $token );
		$health['conflicts'] = Theme_Publisher::conflicts( $draft );
		// A page can load without a fatal and still lose a whole area.
		$health['warnings'] = Block_Templates::deleted_pattern_warnings( $draft );

		return $health;
	}

	/**
	 * Publishes the draft.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function publish( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$draft = self::require_changes();
		if ( is_wp_error( $draft ) ) {
			return $draft;
		}

		// Publishing is undoable (a backup is taken first), so it gets its own wording
		// rather than the generic "cannot be undone" confirmation.
		if ( ! isset( $input['confirm'] ) || true !== $input['confirm'] ) {
			$summary = Theme_Draft::summary( $draft );
			$lines   = array();
			foreach ( array(
				'templates'      => __( 'templates', 'mosmcp-abilities' ),
				'template_parts' => __( 'template parts', 'mosmcp-abilities' ),
				'patterns'       => __( 'patterns', 'mosmcp-abilities' ),
				'files'          => __( 'theme files', 'mosmcp-abilities' ),
			) as $key => $noun ) {
				if ( ! empty( $summary[ $key ] ) ) {
					$lines[] = $noun . ': ' . implode( ', ', $summary[ $key ] );
				}
			}
			if ( '' !== $summary['global_styles'] ) {
				$lines[] = __( 'global styles', 'mosmcp-abilities' ) . ': ' . $summary['global_styles'];
			}
			return Site_Support::error(
				'mosmcp_confirmation_required',
				sprintf(
					/* translators: 1: number of changes, 2: semicolon-separated list of the changes. */
					_n(
						'Publishing makes the draft\'s %1$d change live for every visitor: %2$s. The live versions are backed up first, so it can be undone with mosmcp/theme-restore-backup. Tell the person what will go live, and call again with confirm set to true once they agree.',
						'Publishing makes the draft\'s %1$d changes live for every visitor: %2$s. The live versions are backed up first, so it can be undone with mosmcp/theme-restore-backup. Tell the person what will go live, and call again with confirm set to true once they agree.',
						Theme_Draft::change_count( $draft ),
						'mosmcp-abilities'
					),
					Theme_Draft::change_count( $draft ),
					implode( '; ', $lines )
				),
				Site_Support::CAUSE_CONFIRMATION_REQUIRED,
				true
			);
		}

		return Theme_Publisher::publish( $draft );
	}

	/**
	 * Throws the draft away.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function discard( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$raw   = Theme_Draft::raw();

		if ( null === $raw ) {
			return Site_Support::error(
				'mosmcp_theme_draft_missing',
				__( 'There is no theme draft to discard.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_NOT_FOUND,
				false
			);
		}

		$count   = Theme_Draft::change_count( $raw );
		$confirm = Site_Support::require_confirm(
			$input,
			sprintf(
				/* translators: %d: number of changes. */
				_n( 'the draft\'s %d unpublished change is thrown away', 'the draft\'s %d unpublished changes are thrown away', $count, 'mosmcp-abilities' ),
				$count
			)
		);
		if ( $count > 0 && $confirm instanceof WP_Error ) {
			return $confirm;
		}

		$result = Theme_Draft::discard();

		return array(
			'discarded'     => (bool) $result['discarded'],
			'files_removed' => (bool) $result['files_removed'],
			'change_count'  => $count,
			'notes'         => array( __( 'The live site was never affected by the draft, so nothing visitors see has changed.', 'mosmcp-abilities' ) ),
		);
	}

	/**
	 * Restores the live site to how it was before a publish.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function restore( $input = array() ) {
		$input   = is_array( $input ) ? $input : array();
		$confirm = Site_Support::require_confirm(
			$input,
			__( 'the theme changes from that publish are reversed on the live site, and anything changed in the same places since is overwritten', 'mosmcp-abilities' )
		);
		if ( $confirm instanceof WP_Error ) {
			return $confirm;
		}

		return Theme_Publisher::restore( isset( $input['backup_id'] ) ? sanitize_text_field( (string) $input['backup_id'] ) : '' );
	}

	/**
	 * The current draft, refusing when there is nothing in it.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	private static function require_changes() {
		$stale = Theme_Draft::stale_error();
		if ( $stale instanceof WP_Error ) {
			return $stale;
		}

		$draft = Theme_Draft::current();
		if ( null === $draft || ! Theme_Draft::has_changes( $draft ) ) {
			return Site_Support::error(
				'mosmcp_theme_draft_empty',
				__( 'There are no pending theme changes. Make a change with one of the theme abilities first; it will be collected in the draft.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_NOT_FOUND,
				false
			);
		}

		return $draft;
	}
}
