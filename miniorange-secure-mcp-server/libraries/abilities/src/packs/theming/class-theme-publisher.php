<?php
/**
 * Applies a theme draft to the live site — safely — and restores a previous
 * publish on request.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Theming;

use MoSMCP\Abilities\Packs\Site\Site_Support;
use WP_Error;
use WP_REST_Request;

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
 * Class Theme_Publisher
 *
 * Publishing runs in a fixed order, and any failure after the backup rolls every
 * applied change back:
 *
 *   1. refuse if anything the draft touches changed on the live site since;
 *   2. check the site still loads with the draft applied (required when PHP changes);
 *   3. back up the live version of everything about to change;
 *   4. apply files, then templates, styles and patterns;
 *   5. check the live site loads; if it fatals, restore the backup.
 *
 * Only the files the draft actually touched are written. Swapping the whole theme
 * folder — the simpler approach — would silently revert anything that changed on
 * the live theme while the draft was open, a theme update included.
 */
class Theme_Publisher {

	/**
	 * Option listing recent backups, newest first. Never autoloaded.
	 */
	const BACKUPS_OPTION = 'mosmcp_theme_backups';

	/**
	 * Folder under wp-content holding backed-up files.
	 */
	const BACKUP_DIR = 'mosmcp-theme-backups';

	/**
	 * How many backups are kept.
	 */
	const KEEP = 5;

	/*
	 * Conflicts
	 */

	/**
	 * Everything the draft touches that changed on the live site since it did.
	 *
	 * @param array<string, mixed> $draft Draft.
	 * @return string[] One sentence per conflict.
	 */
	public static function conflicts( array $draft ) {
		$conflicts = array();

		if ( Theme_Draft::touches_files( $draft ) ) {
			$version = (string) wp_get_theme( Theme_Support::stylesheet() )->get( 'Version' );
			if ( $version !== (string) $draft['theme_version'] ) {
				$conflicts[] = sprintf(
					/* translators: 1: version when the draft was made, 2: current version. */
					__( 'The theme was updated from version %1$s to %2$s after the draft was made.', 'mosmcp-abilities' ),
					(string) $draft['theme_version'],
					$version
				);
			}

			$live = Theme_Support::live_dir();
			foreach ( array_keys( (array) $draft['files']['touched'] ) as $relative ) {
				$baseline = Theme_Draft::file_baseline( $draft, $relative );
				$path     = $live . '/' . $relative;
				$now      = file_exists( $path ) ? (string) md5_file( $path ) : null;

				if ( $now !== $baseline ) {
					$conflicts[] = null === $baseline
						? sprintf(
							/* translators: %s: file path. */
							__( 'The file "%s" was created on the live theme after the draft was made.', 'mosmcp-abilities' ),
							$relative
						)
						: sprintf(
							/* translators: %s: file path. */
							__( 'The file "%s" changed on the live theme after the draft was made.', 'mosmcp-abilities' ),
							$relative
						);
				}
			}
		}

		foreach ( (array) $draft['templates'] as $entry ) {
			$live = Block_Templates::live( $entry['type'], $entry['slug'] );
			if ( Block_Templates::baseline( $live ) !== (string) $entry['baseline'] ) {
				$conflicts[] = sprintf(
					/* translators: 1: "template" or "template part", 2: slug. */
					__( 'The %1$s "%2$s" was changed on the live site, probably in the Site Editor, after the draft was made.', 'mosmcp-abilities' ),
					'wp_template_part' === $entry['type'] ? __( 'template part', 'mosmcp-abilities' ) : __( 'template', 'mosmcp-abilities' ),
					$entry['slug']
				);
			}
		}

		if ( ! empty( $draft['styles'] ) && Theme_Draft::fingerprint( self::live_user_styles() ) !== (string) $draft['styles']['baseline'] ) {
			$conflicts[] = __( 'The site\'s global styles were changed on the live site, probably in the Site Editor, after the draft was made.', 'mosmcp-abilities' );
		}

		foreach ( (array) $draft['patterns'] as $entry ) {
			if ( 'update' !== $entry['action'] ) {
				continue;
			}
			$post = get_post( (int) $entry['id'] );
			$now  = $post ? Theme_Draft::fingerprint( $post->post_title . '|' . $post->post_content ) : 'missing';
			if ( $now !== (string) $entry['baseline'] ) {
				$conflicts[] = sprintf(
					/* translators: %s: pattern title. */
					__( 'The pattern "%s" was changed or deleted on the live site after the draft was made.', 'mosmcp-abilities' ),
					(string) $entry['title']
				);
			}
		}

		return $conflicts;
	}

	/*
	 * Publish
	 */

	/**
	 * Publishes a draft.
	 *
	 * @param array<string, mixed> $draft Draft.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function publish( array $draft ) {
		if ( ! Theme_Draft::has_changes( $draft ) ) {
			return Site_Support::error(
				'mosmcp_theme_draft_empty',
				__( 'The theme draft has no changes, so there is nothing to publish.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_INVALID_INPUT,
				false
			);
		}

		$conflicts = self::conflicts( $draft );
		if ( ! empty( $conflicts ) ) {
			return Site_Support::error(
				'mosmcp_theme_draft_conflict',
				__( 'Nothing was published, because part of the live site this draft changes has itself changed since the draft was made, and publishing would overwrite that work: ', 'mosmcp-abilities' ) . implode( ' ', $conflicts ) . ' ' . __( 'Discard the draft and redo the change against the current site.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_CONFLICT,
				false,
				array( 'conflicts' => $conflicts )
			);
		}

		$dangling = self::dangling_placeholders( $draft );
		if ( ! empty( $dangling ) ) {
			return Site_Support::error(
				'mosmcp_theme_draft_dangling_pattern',
				sprintf(
					/* translators: %s: comma-separated list of templates. */
					__( 'Nothing was published: %s places a new pattern that is no longer in the draft, so it would show nothing. Save the pattern again with mosmcp/theme-pattern-save and use the insert_markup it returns, or remove that block.', 'mosmcp-abilities' ),
					implode( ', ', $dangling )
				),
				Site_Support::CAUSE_INVALID_INPUT,
				true
			);
		}

		$notes = array();

		if ( Theme_Draft::touches_files( $draft ) ) {
			$blocked = Theme_Support::file_editing_blocked();
			if ( $blocked instanceof WP_Error ) {
				return $blocked;
			}
			$fs = Theme_Support::fs();
			if ( is_wp_error( $fs ) ) {
				return $fs;
			}
			$unwritable = self::unwritable_targets( $draft );
			if ( ! empty( $unwritable ) ) {
				return Site_Support::error(
					'mosmcp_theme_not_writable',
					sprintf(
						/* translators: %s: comma-separated list of paths. */
						__( 'Nothing was published: the server will not let WordPress write to %s in the live theme. The web server user needs write access there.', 'mosmcp-abilities' ),
						implode( ', ', $unwritable )
					),
					Site_Support::CAUSE_HOST_ENVIRONMENT,
					false
				);
			}
		}

		// 2. Prove the draft loads before anything goes live.
		$before = null;
		if ( Theme_Draft::touches_files( $draft ) ) {
			$token = Theme_Draft::ensure_token( $draft );
			Theme_Draft::save( $draft );

			$before = Theme_Health::run( $token );

			if ( ! $before['ok'] ) {
				$must_verify = Theme_Draft::touches_php( $draft );
				$fatal       = $before['conclusive'];

				if ( $fatal || $must_verify ) {
					return Site_Support::error(
						$fatal ? 'mosmcp_theme_draft_breaks_site' : 'mosmcp_theme_draft_unverifiable',
						$fatal
							? __( 'Nothing was published: with the draft applied, the site fails with a fatal error, so publishing it would take the site down. The live site is untouched. ', 'mosmcp-abilities' ) . self::describe_failures( $before )
							: __( 'Nothing was published: the draft changes PHP, and the check that proves it will not break the site could not complete, so it is not safe to publish blind. The live site is untouched. ', 'mosmcp-abilities' ) . $before['note'],
						$fatal ? Site_Support::CAUSE_INVALID_INPUT : Site_Support::CAUSE_HOST_ENVIRONMENT,
						! $fatal,
						array( 'health' => $before )
					);
				}

				$notes[] = __( 'The pre-publish check could not complete, but the draft changes no PHP, so it cannot cause a fatal error and was published anyway.', 'mosmcp-abilities' );
			}
		}

		// 3. Back up everything about to change.
		$backup = self::backup( $draft );
		if ( is_wp_error( $backup ) ) {
			return $backup;
		}

		// 4. Apply.
		$applied = self::apply( $draft, $backup );
		if ( is_wp_error( $applied ) ) {
			$rolled = self::rollback( $backup );
			self::store_backup( $backup );
			return Site_Support::error(
				'mosmcp_theme_publish_failed',
				sprintf(
					/* translators: 1: the failure, 2: rollback outcome. */
					__( 'Publishing stopped part way: %1$s %2$s', 'mosmcp-abilities' ),
					$applied->get_error_message(),
					$rolled
						? __( 'Everything already applied was rolled back, so the live site is as it was.', 'mosmcp-abilities' )
						: __( 'Rolling back also failed for at least one item; use mosmcp/theme-restore-backup to restore the backup that was taken.', 'mosmcp-abilities' )
				),
				Site_Support::CAUSE_WP_CORE,
				false,
				array( 'backup_id' => $backup['id'] )
			);
		}

		self::store_backup( $backup );

		// 5. Prove the live site still loads; undo everything if it does not.
		$after = null;
		if ( true === Theme_Support::loopback() ) {
			$after = Theme_Health::run( '' );

			if ( ! $after['ok'] && $after['conclusive'] ) {
				$rolled = self::rollback( $backup );
				self::mark_restored( $backup['id'] );

				return Site_Support::error(
					'mosmcp_theme_publish_rolled_back',
					__( 'The draft was published, but the live site then failed with a fatal error, so every change was rolled back automatically. ', 'mosmcp-abilities' )
					. self::describe_failures( $after )
					. ( $rolled ? ' ' . __( 'The live site is back to how it was, and the draft is kept so it can be fixed.', 'mosmcp-abilities' ) : ' ' . __( 'Rolling back failed for at least one item; restore the backup with mosmcp/theme-restore-backup.', 'mosmcp-abilities' ) ),
					Site_Support::CAUSE_INVALID_INPUT,
					false,
					array(
						'health'    => $after,
						'backup_id' => $backup['id'],
					)
				);
			}

			if ( ! $after['conclusive'] ) {
				$notes[] = __( 'The check after publishing could not complete; open the site to confirm it looks right. The backup can be restored with mosmcp/theme-restore-backup.', 'mosmcp-abilities' );
			}
		} else {
			$notes[] = __( 'This site cannot request its own pages, so the check after publishing could not run. Open the site to confirm it looks right; the backup can be restored with mosmcp/theme-restore-backup.', 'mosmcp-abilities' );
		}

		if ( Theme_Draft::touches_files( $draft ) && Theme_Support::receives_updates() ) {
			$notes[] = __( 'This theme receives updates. The next theme update will replace its files, including the ones just changed; templates, template parts and global styles are unaffected because they are stored in the database.', 'mosmcp-abilities' );
		}

		$summary = Theme_Draft::summary( $draft );
		Theme_Draft::discard();

		return array(
			'published'      => true,
			'change_count'   => (int) $summary['change_count'],
			'templates'      => $summary['templates'],
			'template_parts' => $summary['template_parts'],
			'global_styles'  => $summary['global_styles'],
			'patterns'       => $summary['patterns'],
			'files'          => $summary['files'],
			'backup_id'      => (string) $backup['id'],
			'checked_before' => is_array( $before ) ? (string) $before['note'] : '',
			'checked_after'  => is_array( $after ) ? (string) $after['note'] : '',
			'notes'          => $notes,
		);
	}

	/**
	 * Live paths the draft writes that the server will not let WordPress write.
	 *
	 * @param array<string, mixed> $draft Draft.
	 * @return string[] Relative paths.
	 */
	private static function unwritable_targets( array $draft ) {
		$live = Theme_Support::live_dir();
		$bad  = array();

		foreach ( array_keys( (array) $draft['files']['touched'] ) as $relative ) {
			$probe = Theme_Support::nearest_existing( $live . '/' . $relative );
			if ( ! wp_is_writable( $probe ) ) {
				$bad[] = $relative;
			}
		}

		return $bad;
	}

	/**
	 * Describes the failing pages of a health check in one sentence each.
	 *
	 * @param array<string, mixed> $health Health result.
	 * @return string
	 */
	private static function describe_failures( array $health ) {
		// The same error on several pages is reported once, naming every page.
		$groups = array();
		foreach ( (array) $health['checks'] as $check ) {
			if ( 'fatal' !== $check['status'] ) {
				continue;
			}
			$key = $check['message'] . '|' . $check['file'] . '|' . $check['line'];
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'check' => $check,
					'pages' => array(),
				);
			}
			$groups[ $key ]['pages'][] = $check['page'];
		}

		$lines = array();
		foreach ( $groups as $group ) {
			$check = $group['check'];
			$where = '' !== $check['file']
				? sprintf(
					/* translators: 1: file, 2: line. */
					__( ' (%1$s, line %2$d)', 'mosmcp-abilities' ),
					$check['file'],
					(int) $check['line']
				)
				: '';
			$lines[] = sprintf(
				/* translators: 1: page or pages, 2: error message, 3: location. */
				__( 'The %1$s failed with: %2$s%3$s.', 'mosmcp-abilities' ),
				wp_sprintf( '%l', $group['pages'] ),
				rtrim( (string) $check['message'], '.' ),
				$where
			);
		}
		return implode( ' ', $lines );
	}

	/*
	 * Backup
	 */

	/**
	 * Records the live state of everything the draft is about to change.
	 *
	 * @param array<string, mixed> $draft Draft.
	 * @return array<string, mixed>|WP_Error Backup manifest.
	 */
	private static function backup( array $draft ) {
		$id     = gmdate( 'Ymd-His' ) . '-' . strtolower( wp_generate_password( 6, false, false ) );
		$backup = array(
			'id'          => $id,
			'created_gmt' => time(),
			'user_id'     => get_current_user_id(),
			'stylesheet'  => Theme_Support::stylesheet(),
			'dir'         => '',
			'files'       => array(),
			'templates'   => array(),
			'styles'      => null,
			'patterns'    => array(),
			'restored'    => false,
		);

		if ( Theme_Draft::touches_files( $draft ) ) {
			$base = untrailingslashit( wp_normalize_path( WP_CONTENT_DIR ) ) . '/' . self::BACKUP_DIR;
			Theme_Support::protect_dir( $base );
			$dir = $base . '/' . $id . '-' . strtolower( wp_generate_password( 10, false, false ) );
			Theme_Support::protect_dir( $dir );
			$backup['dir'] = $dir;

			$live = Theme_Support::live_dir();
			foreach ( array_keys( (array) $draft['files']['touched'] ) as $relative ) {
				$path = $live . '/' . $relative;
				if ( file_exists( $path ) ) {
					$content = Theme_Support::read_file( $path );
					if ( false === $content ) {
						return Site_Support::error(
							'mosmcp_theme_backup_failed',
							sprintf(
								/* translators: %s: file path. */
								__( 'Nothing was published: the live copy of "%s" could not be read to back it up first.', 'mosmcp-abilities' ),
								$relative
							),
							Site_Support::CAUSE_HOST_ENVIRONMENT,
							false
						);
					}
					$written = Theme_Support::write_file( $dir . '/files/' . $relative, $content );
					if ( is_wp_error( $written ) ) {
						return $written;
					}
					$backup['files'][ $relative ] = true;
				} else {
					$backup['files'][ $relative ] = false;
				}
			}
		}

		foreach ( (array) $draft['templates'] as $entry ) {
			$live                  = Block_Templates::live( $entry['type'], $entry['slug'] );
			$backup['templates'][] = array(
				'type'    => $entry['type'],
				'slug'    => $entry['slug'],
				'origin'  => $live ? Block_Templates::origin( $live ) : 'missing',
				'content' => $live ? (string) $live->content : '',
			);
		}

		if ( ! empty( $draft['styles'] ) ) {
			$backup['styles'] = self::live_user_styles();
		}

		foreach ( (array) $draft['patterns'] as $key => $entry ) {
			if ( 'update' === $entry['action'] ) {
				$post                 = get_post( (int) $entry['id'] );
				$backup['patterns'][] = array(
					'action'  => 'update',
					'id'      => (int) $entry['id'],
					'title'   => $post ? $post->post_title : '',
					'content' => $post ? $post->post_content : '',
				);
			}
		}

		return $backup;
	}

	/**
	 * Saves a backup manifest and prunes old ones.
	 *
	 * @param array<string, mixed> $backup Backup.
	 * @return void
	 */
	private static function store_backup( array $backup ) {
		$list = self::backups();
		array_unshift( $list, $backup );

		$base = untrailingslashit( wp_normalize_path( WP_CONTENT_DIR ) ) . '/' . self::BACKUP_DIR . '/';
		foreach ( array_slice( $list, self::KEEP ) as $old ) {
			// Only ever delete inside our own backup folder.
			if ( ! empty( $old['dir'] ) && 0 === strpos( wp_normalize_path( (string) $old['dir'] ), $base ) ) {
				Theme_Support::delete_path( (string) $old['dir'] );
			}
		}
		$list = array_slice( $list, 0, self::KEEP );

		update_option( self::BACKUPS_OPTION, $list, false );
	}

	/**
	 * Recent backups, newest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function backups() {
		$list = get_option( self::BACKUPS_OPTION, array() );
		return is_array( $list ) ? array_values( $list ) : array();
	}

	/**
	 * Marks a backup as already restored.
	 *
	 * @param string $id Backup ID.
	 * @return void
	 */
	private static function mark_restored( $id ) {
		$list = self::backups();
		foreach ( $list as $index => $backup ) {
			if ( $backup['id'] === $id ) {
				$list[ $index ]['restored'] = true;
			}
		}
		update_option( self::BACKUPS_OPTION, $list, false );
	}

	/*
	 * Apply and roll back
	 */

	/**
	 * Applies every change in a draft.
	 *
	 * @param array<string, mixed> $draft  Draft.
	 * @param array<string, mixed> $backup Backup, updated with created pattern IDs.
	 * @return true|WP_Error
	 */
	private static function apply( array $draft, array &$backup ) {
		if ( Theme_Draft::touches_files( $draft ) ) {
			$live      = Theme_Support::live_dir();
			$workspace = Theme_Draft::workspace_path( $draft );

			foreach ( (array) $draft['files']['touched'] as $relative => $action ) {
				if ( 'delete' === $action ) {
					if ( ! Theme_Support::delete_path( $live . '/' . $relative ) ) {
						return self::apply_error( $relative );
					}
					continue;
				}

				$content = Theme_Support::read_file( $workspace . '/' . $relative );
				if ( false === $content ) {
					return self::apply_error( $relative );
				}
				$written = Theme_Support::write_file( $live . '/' . $relative, $content );
				if ( is_wp_error( $written ) ) {
					return $written;
				}
			}
		}

		// New patterns first, so templates placing them can be given their real IDs.
		$created = array();
		foreach ( (array) $draft['patterns'] as $key => $entry ) {
			if ( 'create' !== $entry['action'] ) {
				continue;
			}
			$id = wp_insert_post(
				wp_slash(
					array(
						'post_type'    => 'wp_block',
						'post_status'  => 'publish',
						'post_title'   => (string) $entry['title'],
						'post_content' => (string) $entry['content'],
					)
				),
				true
			);
			if ( is_wp_error( $id ) ) {
				return $id;
			}
			if ( empty( $entry['synced'] ) ) {
				update_post_meta( (int) $id, 'wp_pattern_sync_status', 'unsynced' );
			}
			$backup['patterns'][]         = array(
				'action' => 'create',
				'id'     => (int) $id,
			);
			$created[ substr( $key, 4 ) ] = (int) $id;
		}

		foreach ( (array) $draft['patterns'] as $key => $entry ) {
			$content = Theme_Draft::resolve_placeholders( (string) $entry['content'], $created );
			if ( 'create' === $entry['action'] ) {
				// A new pattern that places another new one.
				if ( $content !== (string) $entry['content'] ) {
					wp_update_post(
						wp_slash(
							array(
								'ID'           => $created[ substr( $key, 4 ) ],
								'post_content' => $content,
							)
						)
					);
				}
				continue;
			}
			$updated = wp_update_post(
				wp_slash(
					array(
						'ID'           => (int) $entry['id'],
						'post_title'   => (string) $entry['title'],
						'post_content' => $content,
					)
				),
				true
			);
			if ( is_wp_error( $updated ) ) {
				return $updated;
			}
		}

		foreach ( (array) $draft['templates'] as $entry ) {
			$result = 'reset' === $entry['action']
				? self::reset_template( $entry['type'], $entry['slug'] )
				: self::save_template( $entry['type'], $entry['slug'], Theme_Draft::resolve_placeholders( (string) $entry['content'], $created ) );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		if ( ! empty( $draft['styles'] ) ) {
			$result = self::save_styles( (array) $draft['styles']['user_data'] );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return true;
	}

	/**
	 * Templates and patterns that place a new pattern the draft no longer holds.
	 *
	 * @param array<string, mixed> $draft Draft.
	 * @return string[] Labels.
	 */
	private static function dangling_placeholders( array $draft ) {
		$bad    = array();
		$places = array();
		foreach ( (array) $draft['templates'] as $entry ) {
			if ( 'reset' !== $entry['action'] ) {
				$places[ ( 'wp_template_part' === $entry['type'] ? 'the template part "' : 'the template "' ) . $entry['slug'] . '"' ] = (string) $entry['content'];
			}
		}
		foreach ( (array) $draft['patterns'] as $entry ) {
			$places[ 'the pattern "' . $entry['title'] . '"' ] = (string) $entry['content'];
		}
		foreach ( $places as $label => $content ) {
			foreach ( Theme_Draft::placeholder_uuids( $content ) as $uuid ) {
				if ( ! isset( $draft['patterns'][ 'new:' . $uuid ] ) ) {
					$bad[] = $label;
					break;
				}
			}
		}
		return $bad;
	}

	/**
	 * Puts back everything a backup recorded.
	 *
	 * @param array<string, mixed> $backup Backup.
	 * @return bool True when every item was restored.
	 */
	public static function rollback( array $backup ) {
		$ok = true;

		if ( ! empty( $backup['files'] ) ) {
			$live = untrailingslashit( wp_normalize_path( wp_get_theme( (string) $backup['stylesheet'] )->get_stylesheet_directory() ) );
			foreach ( (array) $backup['files'] as $relative => $existed ) {
				if ( $existed ) {
					$content = Theme_Support::read_file( $backup['dir'] . '/files/' . $relative );
					$ok      = ( false !== $content && true === Theme_Support::write_file( $live . '/' . $relative, $content ) ) && $ok;
				} else {
					$ok = Theme_Support::delete_path( $live . '/' . $relative ) && $ok;
				}
			}
		}

		foreach ( (array) $backup['templates'] as $entry ) {
			$result = in_array( $entry['origin'], array( 'customised', 'user' ), true )
				? self::save_template( $entry['type'], $entry['slug'], (string) $entry['content'] )
				: self::reset_template( $entry['type'], $entry['slug'], true );
			$ok     = ! is_wp_error( $result ) && $ok;
		}

		if ( is_array( $backup['styles'] ) ) {
			$ok = ! is_wp_error( self::save_styles( $backup['styles'] ) ) && $ok;
		}

		foreach ( array_reverse( (array) $backup['patterns'] ) as $entry ) {
			if ( 'create' === $entry['action'] ) {
				$ok = (bool) wp_delete_post( (int) $entry['id'], true ) && $ok;
			} else {
				$result = wp_update_post(
					wp_slash(
						array(
							'ID'           => (int) $entry['id'],
							'post_title'   => (string) $entry['title'],
							'post_content' => (string) $entry['content'],
						)
					),
					true
				);
				$ok     = ! is_wp_error( $result ) && $ok;
			}
		}

		return $ok;
	}

	/**
	 * Restores a stored backup and checks the site afterwards.
	 *
	 * @param string $id Backup ID, or empty for the most recent.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function restore( $id = '' ) {
		$backups = self::backups();
		$target  = null;

		foreach ( $backups as $backup ) {
			if ( '' === $id ? empty( $backup['restored'] ) : $backup['id'] === $id ) {
				$target = $backup;
				break;
			}
		}

		if ( null === $target ) {
			return Site_Support::error(
				'mosmcp_theme_backup_not_found',
				'' === $id
					? __( 'There is no unrestored theme backup to restore. A backup is taken each time a theme draft is published.', 'mosmcp-abilities' )
					: sprintf(
						/* translators: %s: backup ID. */
						__( 'No theme backup with ID "%s" exists. Use mosmcp/theme-draft-status to list recent backups.', 'mosmcp-abilities' ),
						$id
					),
				Site_Support::CAUSE_NOT_FOUND,
				false,
				array( 'use_instead' => 'mosmcp/theme-draft-status' )
			);
		}

		if ( Theme_Support::stylesheet() !== (string) $target['stylesheet'] ) {
			return Site_Support::error(
				'mosmcp_theme_backup_other_theme',
				sprintf(
					/* translators: 1: theme the backup belongs to, 2: active theme. */
					__( 'That backup belongs to the theme "%1$s", but the active theme is "%2$s", so it was not restored.', 'mosmcp-abilities' ),
					(string) $target['stylesheet'],
					Theme_Support::stylesheet()
				),
				Site_Support::CAUSE_CONFLICT,
				false
			);
		}

		if ( ! empty( $target['files'] ) ) {
			$blocked = Theme_Support::file_editing_blocked();
			if ( $blocked instanceof WP_Error ) {
				return $blocked;
			}
		}

		$ok = self::rollback( $target );
		self::mark_restored( $target['id'] );

		$health = true === Theme_Support::loopback() ? Theme_Health::run( '' ) : null;

		return array(
			'restored'      => $ok,
			'backup_id'     => (string) $target['id'],
			'backup_taken'  => gmdate( 'Y-m-d H:i:s', (int) $target['created_gmt'] ) . ' UTC',
			'files'         => array_keys( (array) $target['files'] ),
			'templates'     => array_map(
				static function ( $entry ) {
					return $entry['slug'];
				},
				(array) $target['templates']
			),
			'global_styles' => is_array( $target['styles'] ),
			'health'        => is_array( $health ) ? (string) $health['note'] : __( 'This site cannot request its own pages, so the site was not re-checked. Open it to confirm it looks right.', 'mosmcp-abilities' ),
			'notes'         => $ok ? array() : array( __( 'At least one item could not be restored; check the site.', 'mosmcp-abilities' ) ),
		);
	}

	/*
	 * Storage through the Site Editor's own endpoints
	 */

	/**
	 * Saves a template or part exactly as the Site Editor does.
	 *
	 * For a template that only exists as a theme file this creates the database
	 * override; for one already customised it updates it.
	 *
	 * @param string $type    Template type.
	 * @param string $slug    Slug.
	 * @param string $content Block markup.
	 * @return true|WP_Error
	 */
	private static function save_template( $type, $slug, $content ) {
		$response = self::rest( 'POST', self::template_route( $type, $slug ), array( 'content' => $content ) );
		return is_wp_error( $response ) ? $response : true;
	}

	/**
	 * Returns a template or part to the theme's own version, as the Site Editor's
	 * "Reset" does, by deleting the database override.
	 *
	 * @param string $type          Template type.
	 * @param string $slug          Slug.
	 * @param bool   $missing_is_ok Treat "nothing to reset" as success.
	 * @return true|WP_Error
	 */
	private static function reset_template( $type, $slug, $missing_is_ok = false ) {
		$live = Block_Templates::live( $type, $slug );
		if ( ! $live || 'custom' !== $live->source ) {
			return $missing_is_ok || ( $live && 'theme' === Block_Templates::origin( $live ) )
				? true
				: Site_Support::error(
					'mosmcp_template_nothing_to_reset',
					sprintf(
						/* translators: %s: slug. */
						__( '"%s" has no customisation to reset.', 'mosmcp-abilities' ),
						$slug
					),
					Site_Support::CAUSE_CONFLICT,
					false
				);
		}

		$response = self::rest( 'DELETE', self::template_route( $type, $slug ), array( 'force' => true ) );
		return is_wp_error( $response ) ? $response : true;
	}

	/**
	 * REST route for a template or part.
	 *
	 * @param string $type Template type.
	 * @param string $slug Slug.
	 * @return string
	 */
	private static function template_route( $type, $slug ) {
		$base = 'wp_template_part' === $type ? '/wp/v2/template-parts/' : '/wp/v2/templates/';
		return $base . Block_Templates::id( $slug );
	}

	/**
	 * The site's current user global styles (settings and styles only).
	 *
	 * @return array<string, mixed>
	 */
	public static function live_user_styles() {
		if ( ! class_exists( '\WP_Theme_JSON_Resolver' ) ) {
			return array();
		}

		$post = \WP_Theme_JSON_Resolver::get_user_data_from_wp_global_styles( wp_get_theme( Theme_Support::stylesheet() ) );
		if ( empty( $post['post_content'] ) ) {
			return array();
		}

		$decoded = json_decode( (string) $post['post_content'], true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}

		$styles = array();
		foreach ( array( 'settings', 'styles' ) as $key ) {
			if ( ! empty( $decoded[ $key ] ) && is_array( $decoded[ $key ] ) ) {
				$styles[ $key ] = $decoded[ $key ];
			}
		}

		return $styles;
	}

	/**
	 * Saves the site's user global styles exactly as the Site Editor does.
	 *
	 * @param array<string, mixed> $user_data Settings and styles.
	 * @return true|WP_Error
	 */
	private static function save_styles( array $user_data ) {
		if ( ! class_exists( '\WP_Theme_JSON_Resolver' ) ) {
			return new WP_Error( 'mosmcp_global_styles_unavailable', __( 'Global styles are not available on this site.', 'mosmcp-abilities' ) );
		}

		$post_id = \WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
		if ( $post_id < 1 ) {
			return new WP_Error( 'mosmcp_global_styles_unavailable', __( 'The global styles record for this theme could not be created.', 'mosmcp-abilities' ) );
		}

		$response = self::rest(
			'POST',
			'/wp/v2/global-styles/' . (int) $post_id,
			array(
				'settings' => isset( $user_data['settings'] ) && is_array( $user_data['settings'] ) ? $user_data['settings'] : new \stdClass(),
				'styles'   => isset( $user_data['styles'] ) && is_array( $user_data['styles'] ) ? $user_data['styles'] : new \stdClass(),
			)
		);

		return is_wp_error( $response ) ? $response : true;
	}

	/**
	 * Dispatches an internal REST request as the current user.
	 *
	 * @param string               $method HTTP method.
	 * @param string               $route  Route.
	 * @param array<string, mixed> $params Body parameters.
	 * @return array<string, mixed>|WP_Error Response data.
	 */
	private static function rest( $method, $route, array $params ) {
		$request = new WP_REST_Request( $method, $route );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $params ) );
		$request->set_body_params( $params );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		$response = rest_do_request( $request );

		if ( $response->is_error() ) {
			$error = $response->as_error();
			return Site_Support::error(
				'mosmcp_theme_save_refused',
				sprintf(
					/* translators: %s: WordPress's reason. */
					__( 'WordPress refused the change: %s', 'mosmcp-abilities' ),
					$error->get_error_message()
				),
				Site_Support::CAUSE_WP_CORE,
				false
			);
		}

		$data = $response->get_data();
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Builds the refusal for a file that could not be applied.
	 *
	 * @param string $relative Path.
	 * @return WP_Error
	 */
	private static function apply_error( $relative ) {
		return Site_Support::error(
			'mosmcp_theme_apply_failed',
			sprintf(
				/* translators: %s: file path. */
				__( 'the file "%s" could not be written to the live theme.', 'mosmcp-abilities' ),
				$relative
			),
			Site_Support::CAUSE_HOST_ENVIRONMENT,
			false
		);
	}
}
