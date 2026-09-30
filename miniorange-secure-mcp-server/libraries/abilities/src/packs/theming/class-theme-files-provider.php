<?php
/**
 * Execute logic for the theme-file abilities. Reads work on the live theme or the
 * draft; every write goes into the draft's private copy of the theme.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Theming;

use MoSMCP\Abilities\Packs\Editing\Content_Matcher;
use MoSMCP\Abilities\Packs\Editing\Editing_Support;
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
 * Class Theme_Files_Provider
 */
class Theme_Files_Provider {

	/**
	 * Most files a listing returns.
	 */
	const MAX_LIST = 1000;

	/**
	 * Most matches a search returns.
	 */
	const MAX_MATCHES = 200;

	/**
	 * Most lines a single read returns.
	 */
	const MAX_LINES = 2000;

	/*
	 * Reads
	 */

	/**
	 * Lists the theme's files.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function file_list( $input = array() ) {
		$input  = is_array( $input ) ? $input : array();
		$draft  = Theme_Draft::current();
		$source = '';
		$root   = self::root( $input, $draft, $source );
		$base   = $root;
		$prefix = '';

		if ( ! empty( $input['path'] ) ) {
			$relative = Theme_Support::clean_relative_path( $input['path'] );
			if ( is_wp_error( $relative ) ) {
				return $relative;
			}
			$resolved = Theme_Support::resolve( $root, $relative );
			if ( is_wp_error( $resolved ) ) {
				return $resolved;
			}
			if ( ! is_dir( $resolved ) ) {
				return self::not_found( $relative, $source, true );
			}
			$base   = $resolved;
			$prefix = $relative . '/';
		}

		$touched   = self::touched( $draft );
		$all       = Theme_Support::walk( $base, false, self::MAX_LIST + 1 );
		$files     = array();
		$protected = Theme_Support::protected_files();

		foreach ( array_slice( $all, 0, self::MAX_LIST ) as $relative ) {
			$full    = $prefix . $relative;
			$ext     = Theme_Support::extension( $full );
			$files[] = array(
				'path'      => $full,
				'size'      => (int) filesize( $base . '/' . $relative ),
				'type'      => in_array( $ext, Theme_Support::READ_EXTENSIONS, true ) ? 'text' : 'binary',
				'writable'  => in_array( $ext, Theme_Support::WRITE_EXTENSIONS, true ),
				'protected' => in_array( $full, $protected, true ),
				'pending'   => ( 'draft' === $source && isset( $touched[ $full ] ) ) ? (string) $touched[ $full ] : '',
			);
		}

		if ( 'draft' === $source ) {
			foreach ( $touched as $relative => $action ) {
				if ( 'delete' === $action && ( '' === $prefix || 0 === strpos( $relative, $prefix ) ) ) {
					$files[] = array(
						'path'      => $relative,
						'size'      => 0,
						'type'      => 'text',
						'writable'  => true,
						'protected' => false,
						'pending'   => 'delete',
					);
				}
			}
		}

		$notes = array();
		if ( Theme_Support::stylesheet() !== Theme_Support::template() ) {
			$notes[] = sprintf(
				/* translators: %s: parent theme slug. */
				__( 'This is a child theme. Only its own files are listed and can be changed; the parent theme ("%s") is left alone.', 'mosmcp-abilities' ),
				Theme_Support::template()
			);
		}
		if ( 'draft' !== $source && null !== $draft && '' === Theme_Draft::workspace_path( $draft ) ) {
			$notes[] = __( 'No theme file has been changed in the draft yet, so this is the live theme.', 'mosmcp-abilities' );
		}

		return array(
			'theme'     => Theme_Support::stylesheet(),
			'source'    => $source,
			'count'     => count( $files ),
			'truncated' => count( $all ) > self::MAX_LIST,
			'files'     => $files,
			'notes'     => $notes,
		);
	}

	/**
	 * Reads one file.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function file_read( $input = array() ) {
		$input  = is_array( $input ) ? $input : array();
		$draft  = Theme_Draft::current();
		$source = '';
		$root   = self::root( $input, $draft, $source );

		$relative = Theme_Support::clean_relative_path( isset( $input['path'] ) ? $input['path'] : '' );
		if ( is_wp_error( $relative ) ) {
			return $relative;
		}

		$type = Theme_Support::readable_type( $relative );
		if ( $type instanceof WP_Error ) {
			return $type;
		}

		$path = Theme_Support::resolve( $root, $relative );
		if ( is_wp_error( $path ) ) {
			return $path;
		}

		if ( ! is_file( $path ) ) {
			$touched = self::touched( $draft );
			if ( 'draft' === $source && isset( $touched[ $relative ] ) && 'delete' === $touched[ $relative ] ) {
				return Site_Support::error(
					'mosmcp_theme_file_pending_delete',
					sprintf(
						/* translators: %s: file path. */
						__( '"%s" is deleted in the draft. Pass source "live" to read the version visitors still get.', 'mosmcp-abilities' ),
						$relative
					),
					Site_Support::CAUSE_NOT_FOUND,
					false
				);
			}
			return self::not_found( $relative, $source, false );
		}

		$content = Theme_Support::read_file( $path );
		if ( false === $content ) {
			return self::not_found( $relative, $source, false );
		}

		$lines = preg_split( "/\r\n|\n|\r/", (string) $content );
		$lines = is_array( $lines ) ? $lines : array();
		$total = count( $lines );

		$start = isset( $input['start_line'] ) ? max( 1, (int) $input['start_line'] ) : 1;
		$count = isset( $input['line_count'] ) ? max( 1, min( self::MAX_LINES, (int) $input['line_count'] ) ) : self::MAX_LINES;

		$sliced    = array_slice( $lines, $start - 1, $count );
		$end       = $start + count( $sliced ) - 1;
		$truncated = $start > 1 || $end < $total;

		$touched = self::touched( $draft );

		return array(
			'path'       => $relative,
			'source'     => $source,
			'size'       => strlen( (string) $content ),
			'lines'      => $total,
			'start_line' => $start,
			'end_line'   => max( $start - 1, $end ),
			'truncated'  => $truncated,
			'pending'    => ( 'draft' === $source && isset( $touched[ $relative ] ) ) ? (string) $touched[ $relative ] : '',
			'content'    => implode( "\n", $sliced ),
			'notes'      => $truncated
				? array(
					sprintf(
						/* translators: 1: first line, 2: last line, 3: total lines. */
						__( 'Showing lines %1$d to %2$d of %3$d. Pass start_line to read further.', 'mosmcp-abilities' ),
						$start,
						max( $start - 1, $end ),
						$total
					),
				)
				: array(),
		);
	}

	/**
	 * Searches the theme's text files for some wording.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function file_search( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$text  = isset( $input['text'] ) ? (string) $input['text'] : '';

		if ( '' === trim( $text ) ) {
			return Site_Support::error( 'mosmcp_empty_search_text', __( 'No text to find was given.', 'mosmcp-abilities' ), Site_Support::CAUSE_INVALID_INPUT, true );
		}

		$draft  = Theme_Draft::current();
		$source = '';
		$root   = self::root( $input, $draft, $source );

		$sensitive = Site_Support::bool_input( $input, 'case_sensitive', false );
		$types     = array();
		if ( ! empty( $input['types'] ) && is_array( $input['types'] ) ) {
			foreach ( $input['types'] as $ext ) {
				$types[] = strtolower( ltrim( (string) $ext, '.' ) );
			}
		}

		$matches  = array();
		$searched = 0;
		$more     = false;

		foreach ( Theme_Support::walk( $root, true ) as $relative ) {
			if ( ! empty( $types ) && ! in_array( Theme_Support::extension( $relative ), $types, true ) ) {
				continue;
			}
			$path = $root . '/' . $relative;
			if ( filesize( $path ) > Theme_Support::MAX_READ_BYTES * 4 ) {
				continue;
			}
			$content = Theme_Support::read_file( $path );
			if ( false === $content ) {
				continue;
			}
			++$searched;

			foreach ( preg_split( "/\r\n|\n|\r/", (string) $content ) as $index => $line ) {
				$hit = $sensitive ? strpos( $line, $text ) : stripos( $line, $text );
				if ( false === $hit ) {
					continue;
				}
				if ( count( $matches ) >= self::MAX_MATCHES ) {
					$more = true;
					break 2;
				}
				$matches[] = array(
					'path' => $relative,
					'line' => $index + 1,
					'text' => trim( mb_substr( (string) $line, 0, 300 ) ),
				);
			}
		}

		return array(
			'searched_for'   => $text,
			'source'         => $source,
			'files_searched' => $searched,
			'match_count'    => count( $matches ),
			'truncated'      => $more,
			'matches'        => $matches,
			'notes'          => $more
				? array( __( 'There are more matches than returned. Narrow the search with types or a longer phrase.', 'mosmcp-abilities' ) )
				: array(),
		);
	}

	/**
	 * Summarizes a file's structure without returning all of it.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function file_outline( $input = array() ) {
		$read = self::file_read(
			array(
				'path'       => isset( $input['path'] ) ? $input['path'] : '',
				'source'     => isset( $input['source'] ) ? $input['source'] : 'draft',
				'line_count' => PHP_INT_MAX,
			)
		);
		if ( is_wp_error( $read ) ) {
			return $read;
		}

		$draft   = Theme_Draft::current();
		$source  = '';
		$root    = self::root( is_array( $input ) ? $input : array(), $draft, $source );
		$content = (string) Theme_Support::read_file( $root . '/' . $read['path'] );
		$ext     = Theme_Support::extension( $read['path'] );

		$outline = array(
			'path'           => $read['path'],
			'source'         => $read['source'],
			'language'       => $ext,
			'lines'          => $read['lines'],
			'functions'      => array(),
			'classes'        => array(),
			'hooks'          => array(),
			'enqueues'       => array(),
			'blocks'         => array(),
			'selector_count' => 0,
			'selectors'      => array(),
			'json_keys'      => array(),
			'notes'          => array(),
		);

		if ( 'php' === $ext ) {
			$outline = array_merge( $outline, self::php_outline( $content ) );
		} elseif ( in_array( $ext, array( 'css', 'scss' ), true ) ) {
			preg_match_all( '/([^{}]+)\{/', (string) preg_replace( '#/\*.*?\*/#s', '', $content ), $found );
			$selectors = array();
			foreach ( $found[1] as $selector ) {
				$selector = trim( preg_replace( '/\s+/', ' ', $selector ) );
				if ( '' !== $selector && 0 !== strpos( $selector, '@' ) ) {
					$selectors[] = $selector;
				}
			}
			$outline['selector_count'] = count( $selectors );
			$outline['selectors']      = array_slice( $selectors, 0, 60 );
		} elseif ( 'json' === $ext ) {
			$decoded              = json_decode( $content, true );
			$outline['json_keys'] = is_array( $decoded ) ? array_map( 'strval', array_keys( $decoded ) ) : array();
		} elseif ( in_array( $ext, array( 'html', 'htm' ), true ) ) {
			$counts = array();
			foreach ( self::block_names( parse_blocks( $content ) ) as $name ) {
				$counts[ $name ] = isset( $counts[ $name ] ) ? $counts[ $name ] + 1 : 1;
			}
			arsort( $counts );
			foreach ( $counts as $name => $count ) {
				$outline['blocks'][] = array(
					'name'  => (string) $name,
					'count' => (int) $count,
				);
			}
		} else {
			$outline['notes'][] = __( 'No structural outline is available for this file type; read it instead.', 'mosmcp-abilities' );
		}

		return $outline;
	}

	/*
	 * Draft writes
	 */

	/**
	 * Changes one passage inside a theme file, in the draft.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function file_edit( $input = array() ) {
		$input   = is_array( $input ) ? $input : array();
		$prepare = self::prepare_write( $input );
		if ( is_wp_error( $prepare ) ) {
			return $prepare;
		}
		list( $draft, $relative, $path ) = $prepare;

		if ( ! is_file( $path ) ) {
			return Site_Support::error(
				'mosmcp_theme_file_not_found',
				sprintf(
					/* translators: %s: file path. */
					__( 'The theme has no file "%s" to edit. To create it, use mosmcp/theme-file-write.', 'mosmcp-abilities' ),
					$relative
				),
				Site_Support::CAUSE_NOT_FOUND,
				false,
				array( 'use_instead' => 'mosmcp/theme-file-write' )
			);
		}

		$before  = (string) Theme_Support::read_file( $path );
		$find    = isset( $input['find_text'] ) ? (string) $input['find_text'] : '';
		$outcome = Content_Matcher::compute_replacement(
			$before,
			$find,
			isset( $input['replace_with'] ) ? (string) $input['replace_with'] : '',
			Site_Support::bool_input( $input, 'replace_all', false ),
			Site_Support::bool_input( $input, 'whole_word', false ),
			false // Code is edited exactly; never read through markup.
		);

		if ( empty( $outcome['ok'] ) ) {
			return Editing_Support::match_error( $outcome, $before, $find, sprintf( 'the theme file "%s"', $relative ) );
		}

		$invalid = Theme_Support::content_error( $relative, $outcome['result'], $before );
		if ( $invalid instanceof WP_Error ) {
			return $invalid;
		}

		$path = self::draft_path( $draft, $relative );
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		$written = Theme_Support::write_file( $path, $outcome['result'] );
		if ( is_wp_error( $written ) ) {
			return $written;
		}

		Theme_Draft::record_file( $draft, $relative, 'write' );
		Theme_Draft::save( $draft );

		$result                = Block_Theme_Provider::staged_result( $draft, sprintf( 'the theme file "%s"', $relative ), 'edited', self::check_notes( $relative ) );
		$result['occurrences'] = (int) $outcome['count'];
		$result['match_mode']  = (string) $outcome['mode'];

		return $result;
	}

	/**
	 * Writes a whole theme file, creating it if needed, in the draft.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function file_write( $input = array() ) {
		$input   = is_array( $input ) ? $input : array();
		$prepare = self::prepare_write( $input );
		if ( is_wp_error( $prepare ) ) {
			return $prepare;
		}
		list( $draft, $relative, $path ) = $prepare;

		$exists  = is_file( $path );
		$content = isset( $input['content'] ) ? (string) $input['content'] : '';

		if ( $exists && Site_Support::bool_input( $input, 'create_only', false ) ) {
			return Site_Support::error(
				'mosmcp_theme_file_exists',
				sprintf(
					/* translators: %s: file path. */
					__( '"%s" already exists, and create_only was set, so it was not overwritten. Use mosmcp/theme-file-edit to change part of it.', 'mosmcp-abilities' ),
					$relative
				),
				Site_Support::CAUSE_CONFLICT,
				false,
				array( 'use_instead' => 'mosmcp/theme-file-edit' )
			);
		}

		$before = $exists ? (string) Theme_Support::read_file( $path ) : null;
		if ( null !== $before && $before === $content ) {
			return Site_Support::error(
				'mosmcp_no_change',
				sprintf(
					/* translators: %s: file path. */
					__( 'That content is identical to what "%s" already holds, so nothing was staged.', 'mosmcp-abilities' ),
					$relative
				),
				Site_Support::CAUSE_INVALID_INPUT,
				false
			);
		}

		$invalid = Theme_Support::content_error( $relative, $content, $before );
		if ( $invalid instanceof WP_Error ) {
			return $invalid;
		}

		$path = self::draft_path( $draft, $relative );
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		$written = Theme_Support::write_file( $path, $content );
		if ( is_wp_error( $written ) ) {
			return $written;
		}

		Theme_Draft::record_file( $draft, $relative, 'write' );
		Theme_Draft::save( $draft );

		$notes = self::check_notes( $relative );
		if ( ! $exists ) {
			$notes[] = __( 'This is a new file. WordPress only uses it if the theme includes or references it — for example a PHP file needs require_once from functions.php, and a stylesheet needs enqueuing.', 'mosmcp-abilities' );
		}

		return Block_Theme_Provider::staged_result( $draft, sprintf( 'the theme file "%s"', $relative ), $exists ? 'rewritten' : 'created', $notes );
	}

	/**
	 * Deletes a theme file in the draft.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function file_delete( $input = array() ) {
		$input   = is_array( $input ) ? $input : array();
		$prepare = self::prepare_write( $input, false );
		if ( is_wp_error( $prepare ) ) {
			return $prepare;
		}
		list( $draft, $relative, $path ) = $prepare;

		if ( in_array( $relative, Theme_Support::protected_files(), true ) ) {
			return Site_Support::error(
				'mosmcp_theme_file_protected',
				sprintf(
					/* translators: %s: file path. */
					__( '"%s" cannot be deleted: WordPress stops recognising the theme without it.', 'mosmcp-abilities' ),
					$relative
				),
				Site_Support::CAUSE_SELF_PROTECTED,
				false
			);
		}

		if ( ! is_file( $path ) ) {
			return self::not_found( $relative, 'draft', false );
		}

		$path = self::draft_path( $draft, $relative );
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		if ( ! Theme_Support::delete_path( $path ) ) {
			return Site_Support::error(
				'mosmcp_theme_write_failed',
				sprintf(
					/* translators: %s: file path. */
					__( 'The draft copy of "%s" could not be deleted.', 'mosmcp-abilities' ),
					$relative
				),
				Site_Support::CAUSE_HOST_ENVIRONMENT,
				false
			);
		}

		Theme_Draft::record_file( $draft, $relative, 'delete' );
		Theme_Draft::save( $draft );

		$notes = Block_Templates::deleted_pattern_warnings(
			array(
				'files'     => array( 'touched' => array( $relative => 'delete' ) ),
				'templates' => $draft['templates'],
			)
		);
		if ( empty( $notes ) ) {
			$notes[] = __( 'If the theme still includes or references this file, the draft health check will catch it before anything is published.', 'mosmcp-abilities' );
		}

		return Block_Theme_Provider::staged_result( $draft, sprintf( 'the theme file "%s"', $relative ), 'deleted', $notes );
	}

	/*
	 * Helpers
	 */

	/**
	 * Shared preconditions for every file write.
	 *
	 * @param array<string, mixed> $input      Input.
	 * @param bool                 $check_type Whether to enforce the writable-type list.
	 * @return array{0: array<string, mixed>, 1: string, 2: string}|WP_Error Draft, relative path, draft path.
	 */
	private static function prepare_write( array $input, $check_type = true ) {
		$blocked = Theme_Support::file_editing_blocked();
		if ( $blocked instanceof WP_Error ) {
			return $blocked;
		}

		$relative = Theme_Support::clean_relative_path( isset( $input['path'] ) ? $input['path'] : '' );
		if ( is_wp_error( $relative ) ) {
			return $relative;
		}

		if ( $check_type ) {
			$type = Theme_Support::writable_type( $relative );
			if ( $type instanceof WP_Error ) {
				return $type;
			}
		}

		$fs = Theme_Support::fs();
		if ( is_wp_error( $fs ) ) {
			return $fs;
		}

		$draft = Theme_Draft::open();
		if ( is_wp_error( $draft ) ) {
			return $draft;
		}

		// Until a file is actually written the draft copy is identical to the live
		// theme, so the edit is checked against the live file and the copy is only
		// made once something passes (see draft_path()).
		$workspace = Theme_Draft::workspace_path( $draft );
		$path      = Theme_Support::resolve( '' !== $workspace ? $workspace : Theme_Support::live_dir(), $relative );
		if ( is_wp_error( $path ) ) {
			return $path;
		}

		return array( $draft, $relative, $path );
	}

	/**
	 * The file's path inside the draft copy, making the copy on first write.
	 *
	 * @param array<string, mixed> $draft    Draft, updated in place.
	 * @param string               $relative Path inside the theme.
	 * @return string|WP_Error
	 */
	private static function draft_path( array &$draft, $relative ) {
		$workspace = Theme_Draft::workspace( $draft );
		if ( is_wp_error( $workspace ) ) {
			return $workspace;
		}
		Theme_Draft::save( $draft );
		return Theme_Support::resolve( $workspace, $relative );
	}

	/**
	 * The root to read from, and which one it is.
	 *
	 * @param array<string, mixed>      $input  Input.
	 * @param array<string, mixed>|null $draft  Draft.
	 * @param string                    $source Set to "draft" or "live".
	 * @return string
	 */
	private static function root( array $input, $draft, &$source ) {
		$want      = isset( $input['source'] ) ? (string) $input['source'] : 'draft';
		$workspace = Theme_Draft::workspace_path( $draft );

		if ( 'live' === $want || '' === $workspace ) {
			$source = 'live';
			return Theme_Support::live_dir();
		}

		$source = 'draft';
		return $workspace;
	}

	/**
	 * Files the draft changed.
	 *
	 * @param array<string, mixed>|null $draft Draft.
	 * @return array<string, string>
	 */
	private static function touched( $draft ) {
		return is_array( $draft ) && ! empty( $draft['files']['touched'] ) ? (array) $draft['files']['touched'] : array();
	}

	/**
	 * What was checked before a write was accepted.
	 *
	 * @param string $relative Path.
	 * @return string[]
	 */
	private static function check_notes( $relative ) {
		switch ( Theme_Support::extension( $relative ) ) {
			case 'php':
				return array( __( 'The PHP was checked for syntax errors before it was accepted. Run mosmcp/theme-draft-check to test that the site actually loads with it.', 'mosmcp-abilities' ) );
			case 'json':
				return array( __( 'The JSON was checked for validity before it was accepted.', 'mosmcp-abilities' ) );
			case 'html':
				$notes = array( __( 'The block markup was checked for balance before it was accepted.', 'mosmcp-abilities' ) );
				// A template saved in the Site Editor wins over the theme's file.
				if ( preg_match( '#^(templates|parts)/(.+)\.html$#', $relative, $m ) ) {
					$type     = 'parts' === $m[1] ? 'wp_template_part' : 'wp_template';
					$template = Block_Templates::live( $type, $m[2] );
					if ( null !== $template && 'customised' === Block_Templates::origin( $template ) ) {
						$notes[] = sprintf(
							/* translators: 1: "template" or "template part", 2: slug, 3: ability name. */
							__( 'The %1$s "%2$s" has been customised in the Site Editor, and WordPress uses that saved version instead of this file, so this edit will not show on the site. Change it with the template abilities instead, or reset it to the theme file with %3$s.', 'mosmcp-abilities' ),
							'wp_template_part' === $type ? __( 'template part', 'mosmcp-abilities' ) : __( 'template', 'mosmcp-abilities' ),
							$m[2],
							'wp_template_part' === $type ? 'mosmcp/theme-part-reset' : 'mosmcp/theme-template-reset'
						);
					}
				}
				return $notes;
			default:
				return array();
		}
	}

	/**
	 * Refusal for a missing file or folder.
	 *
	 * @param string $relative Path.
	 * @param string $source   "draft" or "live".
	 * @param bool   $is_dir   Whether a folder was expected.
	 * @return WP_Error
	 */
	private static function not_found( $relative, $source, $is_dir ) {
		return Site_Support::error(
			'mosmcp_theme_file_not_found',
			sprintf(
				/* translators: 1: the word file or folder, 2: path, 3: the word draft or live. */
				__( 'The %3$s theme has no %1$s "%2$s". Use mosmcp/theme-file-list to see what exists.', 'mosmcp-abilities' ),
				$is_dir ? __( 'folder', 'mosmcp-abilities' ) : __( 'file', 'mosmcp-abilities' ),
				$relative,
				$source
			),
			Site_Support::CAUSE_NOT_FOUND,
			false,
			array( 'use_instead' => 'mosmcp/theme-file-list' )
		);
	}

	/**
	 * Functions, classes and hook registrations in PHP source.
	 *
	 * @param string $source PHP source.
	 * @return array<string, mixed>
	 */
	private static function php_outline( $source ) {
		$functions = array();
		$classes   = array();
		$hooks     = array();
		$enqueues  = array();

		$tokens = token_get_all( (string) $source );
		$count  = count( $tokens );
		$class  = -1;
		$depth  = 0;
		$bodies = array();

		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[ $i ];

			if ( ! is_array( $token ) ) {
				if ( '{' === $token ) {
					++$depth;
				} elseif ( '}' === $token ) {
					--$depth;
					if ( ! empty( $bodies ) && end( $bodies ) === $depth ) {
						array_pop( $bodies );
						$class = -1;
					}
				}
				continue;
			}

			if ( T_CURLY_OPEN === $token[0] || T_DOLLAR_OPEN_CURLY_BRACES === $token[0] ) {
				++$depth;
				continue;
			}

			$next = self::next_string( $tokens, $i );

			if ( T_CLASS === $token[0] && null !== $next && ! self::is_anonymous_class( $tokens, $i ) ) {
				$classes[] = array(
					'name'    => $next[1],
					'line'    => (int) $token[2],
					'methods' => array(),
				);
				$class     = count( $classes ) - 1;
				$bodies[]  = $depth;
				continue;
			}

			if ( T_FUNCTION === $token[0] && null !== $next ) {
				if ( $class >= 0 ) {
					$classes[ $class ]['methods'][] = $next[1];
				} else {
					$functions[] = array(
						'name' => $next[1],
						'line' => (int) $token[2],
					);
				}
				continue;
			}

			if ( T_STRING === $token[0] ) {
				$name = strtolower( $token[1] );
				if ( in_array( $name, array( 'add_action', 'add_filter', 'remove_action', 'remove_filter' ), true ) ) {
					$args = self::string_args( $tokens, $i, 2 );
					if ( ! empty( $args ) ) {
						$hooks[] = array(
							'kind'     => $name,
							'hook'     => $args[0],
							'callback' => isset( $args[1] ) ? $args[1] : '',
							'line'     => (int) $token[2],
						);
					}
				} elseif ( in_array( $name, array( 'wp_enqueue_style', 'wp_enqueue_script', 'wp_register_style', 'wp_register_script' ), true ) ) {
					$args = self::string_args( $tokens, $i, 1 );
					if ( ! empty( $args ) ) {
						$enqueues[] = array(
							'kind'   => $name,
							'handle' => $args[0],
							'line'   => (int) $token[2],
						);
					}
				}
			}
		}

		return array(
			'functions' => $functions,
			'classes'   => $classes,
			'hooks'     => $hooks,
			'enqueues'  => $enqueues,
		);
	}

	/**
	 * The next T_STRING token after position $i, skipping whitespace and "&".
	 *
	 * @param array<int, mixed> $tokens Tokens.
	 * @param int               $i      Position.
	 * @return array<int, mixed>|null
	 */
	private static function next_string( array $tokens, $i ) {
		$count = count( $tokens );
		for ( $j = $i + 1; $j < $count; $j++ ) {
			$token = $tokens[ $j ];
			if ( is_array( $token ) && T_WHITESPACE === $token[0] ) {
				continue;
			}
			if ( '&' === $token ) {
				continue;
			}
			return ( is_array( $token ) && T_STRING === $token[0] ) ? $token : null;
		}
		return null;
	}

	/**
	 * Whether a class token starts an anonymous class ("new class").
	 *
	 * @param array<int, mixed> $tokens Tokens.
	 * @param int               $i      Position of the class token.
	 * @return bool
	 */
	private static function is_anonymous_class( array $tokens, $i ) {
		for ( $j = $i - 1; $j >= 0; $j-- ) {
			$token = $tokens[ $j ];
			if ( is_array( $token ) && T_WHITESPACE === $token[0] ) {
				continue;
			}
			return is_array( $token ) && T_NEW === $token[0];
		}
		return false;
	}

	/**
	 * Leading literal string arguments of a call at position $i.
	 *
	 * @param array<int, mixed> $tokens Tokens.
	 * @param int               $i      Position of the function name.
	 * @param int               $max    Most arguments to read.
	 * @return string[]
	 */
	private static function string_args( array $tokens, $i, $max ) {
		$args  = array();
		$count = count( $tokens );
		$open  = false;

		for ( $j = $i + 1; $j < $count; $j++ ) {
			if ( count( $args ) >= $max ) {
				break;
			}
			$token = $tokens[ $j ];
			if ( ! $open ) {
				if ( '(' === $token ) {
					$open = true;
					continue;
				}
				if ( is_array( $token ) && T_WHITESPACE === $token[0] ) {
					continue;
				}
				return array();
			}
			if ( is_array( $token ) && T_CONSTANT_ENCAPSED_STRING === $token[0] ) {
				$args[] = trim( $token[1], '\'"' );
				continue;
			}
			if ( is_array( $token ) && in_array( $token[0], array( T_WHITESPACE, T_COMMENT ), true ) ) {
				continue;
			}
			if ( ',' === $token ) {
				continue;
			}
			break;
		}

		return $args;
	}

	/**
	 * Every block name in a parsed tree.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @return string[]
	 */
	private static function block_names( array $blocks ) {
		$names = array();
		foreach ( $blocks as $block ) {
			if ( ! empty( $block['blockName'] ) ) {
				$names[] = (string) $block['blockName'];
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				$names = array_merge( $names, self::block_names( $block['innerBlocks'] ) );
			}
		}
		return $names;
	}
}
