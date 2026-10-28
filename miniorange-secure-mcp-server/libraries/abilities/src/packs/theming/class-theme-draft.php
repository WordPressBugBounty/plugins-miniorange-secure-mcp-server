<?php
/**
 * The theme draft: every pending theme change, held until someone publishes it.
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
 * Class Theme_Draft
 *
 * One draft per site. It holds three kinds of pending change side by side:
 *
 *  - templates and template parts: the markup each should have, or a request to
 *    return it to the theme's own version;
 *  - global styles: the complete user styles the site should end up with;
 *  - theme files: a private copy of the theme, created only on the first file
 *    edit, plus a record of which files changed.
 *
 * Every entry keeps a baseline — a fingerprint of the live value at the moment the
 * draft first touched it — so that publishing can refuse rather than silently
 * overwrite something that changed on the live site in the meantime.
 *
 * Nothing here affects what visitors see. Only Theme_Publisher applies a draft.
 */
class Theme_Draft {

	/**
	 * Option holding the draft. Never autoloaded.
	 */
	const OPTION = 'mosmcp_theme_draft';

	/**
	 * How long a preview link stays valid.
	 */
	const TOKEN_TTL = DAY_IN_SECONDS;

	/**
	 * Folder under wp-content that holds file workspaces.
	 */
	const WORKSPACE_DIR = 'mosmcp-theme-drafts';

	/**
	 * Block attribute naming a pattern created in the draft, on a core/block
	 * placeholder that publishing turns into a real "ref".
	 */
	const PATTERN_REF_ATTR = 'mosmcpDraftPattern';

	/**
	 * The stored draft, whatever theme it belongs to.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function raw() {
		$draft = get_option( self::OPTION, null );
		return is_array( $draft ) && ! empty( $draft['id'] ) ? $draft : null;
	}

	/**
	 * The draft for the live theme, or null when there is none.
	 *
	 * A draft built against a different theme is not returned: its baselines and
	 * workspace describe a theme that is no longer active.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function current() {
		$draft = self::raw();
		if ( null === $draft ) {
			return null;
		}

		return ( isset( $draft['stylesheet'] ) && Theme_Support::stylesheet() === $draft['stylesheet'] ) ? $draft : null;
	}

	/**
	 * Refuses an edit while a draft for another theme is still stored.
	 *
	 * @return WP_Error|null
	 */
	public static function stale_error() {
		$draft = self::raw();
		if ( null === $draft || null !== self::current() ) {
			return null;
		}

		return Site_Support::error(
			'mosmcp_theme_draft_stale',
			sprintf(
				/* translators: 1: theme the draft was made for, 2: the active theme. */
				__( 'There is an unpublished theme draft made for "%1$s", but the active theme is now "%2$s", so that draft no longer applies. Discard it with mosmcp/theme-draft-discard before starting a new one.', 'mosmcp-abilities' ),
				(string) $draft['stylesheet'],
				Theme_Support::stylesheet()
			),
			Site_Support::CAUSE_CONFLICT,
			false,
			array( 'use_instead' => 'mosmcp/theme-draft-discard' )
		);
	}

	/**
	 * The draft for the live theme, creating an empty one if needed.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function open() {
		$stale = self::stale_error();
		if ( $stale instanceof WP_Error ) {
			return $stale;
		}

		$draft = self::current();
		if ( null !== $draft ) {
			return $draft;
		}

		$theme = wp_get_theme( Theme_Support::stylesheet() );

		return array(
			'id'            => wp_generate_uuid4(),
			'stylesheet'    => Theme_Support::stylesheet(),
			'template'      => Theme_Support::template(),
			'theme_version' => (string) $theme->get( 'Version' ),
			'created_gmt'   => time(),
			'updated_gmt'   => time(),
			'user_id'       => get_current_user_id(),
			'token'         => '',
			'token_expires' => 0,
			'templates'     => array(),
			'styles'        => null,
			'patterns'      => array(),
			'files'         => null,
		);
	}

	/**
	 * Stores a draft.
	 *
	 * @param array<string, mixed> $draft Draft.
	 * @return void
	 */
	public static function save( array $draft ) {
		$draft['updated_gmt']  = time();
		$draft['last_user_id'] = get_current_user_id();

		update_option( self::OPTION, $draft, false );
	}

	/**
	 * Deletes the draft and its file workspace.
	 *
	 * @return array{discarded:bool,files_removed:bool}
	 */
	public static function discard() {
		$draft   = self::raw();
		$removed = true;

		if ( null !== $draft && ! empty( $draft['files']['root'] ) ) {
			$root = (string) $draft['files']['root'];
			// Only ever delete inside our own workspace folder.
			if ( 0 === strpos( wp_normalize_path( $root ), wp_normalize_path( self::workspace_base() ) . '/' ) ) {
				$removed = Theme_Support::delete_path( $root );
			}
		}

		delete_option( self::OPTION );

		return array(
			'discarded'     => null !== $draft,
			'files_removed' => $removed,
		);
	}

	/**
	 * Whether a draft holds any change.
	 *
	 * @param array<string, mixed>|null $draft Draft.
	 * @return bool
	 */
	public static function has_changes( $draft ) {
		return self::change_count( $draft ) > 0;
	}

	/**
	 * Number of pending changes.
	 *
	 * @param array<string, mixed>|null $draft Draft.
	 * @return int
	 */
	public static function change_count( $draft ) {
		if ( ! is_array( $draft ) ) {
			return 0;
		}

		$count  = count( (array) $draft['templates'] );
		$count += count( (array) $draft['patterns'] );
		$count += empty( $draft['styles'] ) ? 0 : 1;
		$count += empty( $draft['files']['touched'] ) ? 0 : count( (array) $draft['files']['touched'] );

		return $count;
	}

	/**
	 * Whether a draft changes theme files (and so needs file permissions and a
	 * code health check to publish).
	 *
	 * @param array<string, mixed>|null $draft Draft.
	 * @return bool
	 */
	public static function touches_files( $draft ) {
		return is_array( $draft ) && ! empty( $draft['files']['touched'] );
	}

	/**
	 * Whether a draft changes any PHP file.
	 *
	 * @param array<string, mixed>|null $draft Draft.
	 * @return bool
	 */
	public static function touches_php( $draft ) {
		if ( ! self::touches_files( $draft ) ) {
			return false;
		}
		foreach ( array_keys( (array) $draft['files']['touched'] ) as $relative ) {
			if ( 'php' === Theme_Support::extension( $relative ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A compact description of a draft for ability output.
	 *
	 * @param array<string, mixed>|null $draft Draft.
	 * @return array<string, mixed>
	 */
	public static function summary( $draft ) {
		if ( ! is_array( $draft ) ) {
			return array(
				'exists'         => false,
				'change_count'   => 0,
				'templates'      => array(),
				'template_parts' => array(),
				'global_styles'  => '',
				'patterns'       => array(),
				'files'          => array(),
				'created'        => '',
				'updated'        => '',
				'preview_ready'  => false,
			);
		}

		$templates = array();
		$parts     = array();
		foreach ( (array) $draft['templates'] as $entry ) {
			$line = $entry['slug'] . ' (' . $entry['action'] . ')';
			if ( 'wp_template_part' === $entry['type'] ) {
				$parts[] = $line;
			} else {
				$templates[] = $line;
			}
		}

		$patterns = array();
		foreach ( (array) $draft['patterns'] as $entry ) {
			$patterns[] = $entry['title'] . ' (' . $entry['action'] . ')';
		}

		$files = array();
		if ( ! empty( $draft['files']['touched'] ) ) {
			foreach ( (array) $draft['files']['touched'] as $relative => $action ) {
				$files[] = $relative . ' (' . $action . ')';
			}
		}

		$styles = '';
		if ( ! empty( $draft['styles'] ) ) {
			// What exactly changed, in words ("background colour, heading font"), so a
			// confirmation shows more than "update" — the draft is shared, and a person
			// should recognise every change before it goes live.
			if ( ! empty( $draft['styles']['changes'] ) ) {
				$styles = implode( ', ', (array) $draft['styles']['changes'] );
			} else {
				$styles = 'variation' === $draft['styles']['action']
					? sprintf( 'apply variation "%s"', (string) $draft['styles']['variation'] )
					: (string) $draft['styles']['action'];
			}
		}

		return array(
			'exists'         => true,
			'change_count'   => self::change_count( $draft ),
			'templates'      => $templates,
			'template_parts' => $parts,
			'global_styles'  => $styles,
			'patterns'       => $patterns,
			'files'          => $files,
			'created'        => gmdate( 'Y-m-d H:i:s', (int) $draft['created_gmt'] ) . ' UTC',
			'updated'        => gmdate( 'Y-m-d H:i:s', (int) $draft['updated_gmt'] ) . ' UTC',
			'preview_ready'  => ! empty( $draft['token'] ) && (int) $draft['token_expires'] > time(),
		);
	}

	/*
	 * Preview token
	 */

	/**
	 * Returns a valid preview token, issuing a fresh one if needed.
	 *
	 * @param array<string, mixed> $draft Draft, updated in place.
	 * @return string
	 */
	public static function ensure_token( array &$draft ) {
		if ( empty( $draft['token'] ) || (int) $draft['token_expires'] <= time() ) {
			$draft['token']         = wp_generate_password( 40, false, false );
			$draft['token_expires'] = time() + self::TOKEN_TTL;
		}

		return (string) $draft['token'];
	}

	/*
	 * File workspace
	 */

	/**
	 * Folder that holds every file workspace.
	 *
	 * @return string
	 */
	public static function workspace_base() {
		return untrailingslashit( wp_normalize_path( WP_CONTENT_DIR ) ) . '/' . self::WORKSPACE_DIR;
	}

	/**
	 * The draft's copy of the theme, or '' when no file has been edited yet.
	 *
	 * @param array<string, mixed>|null $draft Draft.
	 * @return string Absolute directory.
	 */
	public static function workspace_path( $draft ) {
		if ( ! is_array( $draft ) || empty( $draft['files']['dir'] ) || ! is_dir( (string) $draft['files']['dir'] ) ) {
			return '';
		}
		return (string) $draft['files']['dir'];
	}

	/**
	 * Deletes draft copies no draft points at any more — left by a database restore,
	 * a request that died mid-copy, or an option removed by hand. Only folders idle
	 * for an hour are touched, so a copy another request is making right now is safe.
	 *
	 * @param string $base Workspace base directory.
	 * @return void
	 */
	private static function sweep_orphans( $base ) {
		$current = self::raw();
		$keep    = is_array( $current ) && ! empty( $current['files']['root'] ) ? wp_normalize_path( (string) $current['files']['root'] ) : '';
		$entries = is_dir( $base ) ? scandir( $base ) : false;
		if ( ! is_array( $entries ) ) {
			return;
		}
		foreach ( $entries as $entry ) {
			$path = $base . '/' . $entry;
			if ( '.' === $entry[0] || ! is_dir( $path ) || $path === $keep ) {
				continue;
			}
			$modified = filemtime( $path );
			if ( false !== $modified && ( time() - $modified ) > HOUR_IN_SECONDS ) {
				Theme_Support::delete_path( $path );
			}
		}
	}

	/**
	 * Returns the draft's copy of the theme, making it on first use.
	 *
	 * The copy lives under wp-content in a randomly named folder, not in the themes
	 * folder, so it never appears in Appearance > Themes and cannot be activated by
	 * accident. A fingerprint of every text file is recorded at copy time: that is
	 * the baseline publishing compares the live theme against.
	 *
	 * @param array<string, mixed> $draft Draft, updated in place.
	 * @return string|WP_Error Absolute directory.
	 */
	public static function workspace( array &$draft ) {
		// Only one draft exists at a time, so a new copy is only ever made when no
		// workspace belongs to the draft.
		$existing = self::workspace_path( $draft );
		if ( '' !== $existing ) {
			return $existing;
		}

		$live = Theme_Support::live_dir();
		$size = Theme_Support::tree_size( $live );
		if ( $size > Theme_Support::MAX_THEME_BYTES ) {
			return Site_Support::error(
				'mosmcp_theme_too_large_to_draft',
				sprintf(
					/* translators: %d: the theme size in megabytes. */
					__( 'The theme is %d MB, too large to copy into a draft safely. Theme files cannot be edited through a draft on this site.', 'mosmcp-abilities' ),
					(int) ( $size / 1048576 )
				),
				Site_Support::CAUSE_HOST_ENVIRONMENT,
				false
			);
		}

		$base = self::workspace_base();
		self::sweep_orphans( $base );
		$root = $base . '/' . wp_generate_password( 20, false, false );
		$dir  = $root . '/' . Theme_Support::stylesheet();

		Theme_Support::protect_dir( $base );
		Theme_Support::protect_dir( $root );

		$copied = Theme_Support::copy_tree( $live, $dir );
		if ( is_wp_error( $copied ) ) {
			Theme_Support::delete_path( $root );
			return $copied;
		}

		$manifest = array();
		foreach ( Theme_Support::walk( $live, true ) as $relative ) {
			$manifest[ $relative ] = (string) md5_file( $live . '/' . $relative );
		}

		$draft['files'] = array(
			'root'     => $root,
			'dir'      => $dir,
			'manifest' => $manifest,
			'touched'  => array(),
		);

		return $dir;
	}

	/**
	 * Fingerprint of a live file when the workspace was made, or null if it did
	 * not exist then.
	 *
	 * @param array<string, mixed> $draft    Draft.
	 * @param string               $relative Relative path.
	 * @return string|null
	 */
	public static function file_baseline( array $draft, $relative ) {
		return isset( $draft['files']['manifest'][ $relative ] ) ? (string) $draft['files']['manifest'][ $relative ] : null;
	}

	/**
	 * Records that a file changed in the workspace.
	 *
	 * A file created and then deleted inside the same draft never existed live, so
	 * it drops out of the record entirely rather than becoming a delete.
	 *
	 * @param array<string, mixed> $draft    Draft, updated in place.
	 * @param string               $relative Relative path.
	 * @param string               $action   "write" or "delete".
	 * @return void
	 */
	public static function record_file( array &$draft, $relative, $action ) {
		if ( 'delete' === $action && null === self::file_baseline( $draft, $relative ) ) {
			unset( $draft['files']['touched'][ $relative ] );
			return;
		}

		$draft['files']['touched'][ $relative ] = $action;
	}

	/*
	 * Block entries
	 */

	/**
	 * Key a template or part is stored under.
	 *
	 * @param string $type "wp_template" or "wp_template_part".
	 * @param string $slug Slug.
	 * @return string
	 */
	public static function template_key( $type, $slug ) {
		return $type . ':' . $slug;
	}

	/**
	 * A pending template or part change, or null.
	 *
	 * @param array<string, mixed>|null $draft Draft.
	 * @param string                    $type  Type.
	 * @param string                    $slug  Slug.
	 * @return array<string, mixed>|null
	 */
	public static function template_entry( $draft, $type, $slug ) {
		if ( ! is_array( $draft ) ) {
			return null;
		}
		$key = self::template_key( $type, $slug );
		return isset( $draft['templates'][ $key ] ) ? $draft['templates'][ $key ] : null;
	}

	/**
	 * The block that places a pattern created in this draft, before it has an ID.
	 * It renders in the preview, and publishing swaps it for the real reference;
	 * on its own ("ref":0) it renders nothing, so it can never show a wrong block.
	 *
	 * @param string $uuid UUID from the draft key "new:<uuid>".
	 * @return string
	 */
	public static function pattern_placeholder( $uuid ) {
		return '<!-- wp:block {"ref":0,"' . self::PATTERN_REF_ATTR . '":"' . $uuid . '"} /-->';
	}

	/**
	 * Draft keys ("new:<uuid>") of the new patterns a piece of markup places.
	 *
	 * @param string $markup Block markup.
	 * @return string[] UUIDs.
	 */
	public static function placeholder_uuids( $markup ) {
		preg_match_all( '/"' . self::PATTERN_REF_ATTR . '"\s*:\s*"([0-9a-f-]{36})"/', (string) $markup, $m );
		return array_values( array_unique( $m[1] ) );
	}

	/**
	 * Swaps placeholders for the real pattern IDs once the patterns exist.
	 *
	 * @param string            $markup Block markup.
	 * @param array<string,int> $ids  UUID => post ID.
	 * @return string
	 */
	public static function resolve_placeholders( $markup, array $ids ) {
		return (string) preg_replace_callback(
			'/"ref"\s*:\s*0\s*,\s*"' . self::PATTERN_REF_ATTR . '"\s*:\s*"([0-9a-f-]{36})"|"' . self::PATTERN_REF_ATTR . '"\s*:\s*"([0-9a-f-]{36})"\s*,\s*"ref"\s*:\s*0/',
			static function ( $found ) use ( $ids ) {
				$uuid = '' !== $found[1] ? $found[1] : $found[2];
				return isset( $ids[ $uuid ] ) ? '"ref":' . (int) $ids[ $uuid ] : $found[0];
			},
			(string) $markup
		);
	}

	/**
	 * A pending synced-pattern change for a pattern post, or null.
	 *
	 * @param array<string, mixed>|null $draft Draft.
	 * @param int                       $id    Pattern post ID.
	 * @return array<string, mixed>|null
	 */
	public static function pattern_entry( $draft, $id ) {
		if ( ! is_array( $draft ) || $id < 1 ) {
			return null;
		}
		$key = 'id:' . (int) $id;
		return isset( $draft['patterns'][ $key ] ) ? $draft['patterns'][ $key ] : null;
	}

	/**
	 * Fingerprint of a value, for baselines.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public static function fingerprint( $value ) {
		return md5( is_string( $value ) ? $value : (string) wp_json_encode( $value ) );
	}
}
