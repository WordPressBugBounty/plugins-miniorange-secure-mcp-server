<?php
/**
 * Shared guards for the theme abilities: policy checks, path sandboxing,
 * content validation, filesystem access and host preflight.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Theming;

use MoSMCP\Abilities\Packs\Editing\Content_Matcher;
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
 * Class Theme_Support
 *
 * Everything the theme abilities have in common. Nothing here changes the site;
 * it answers "may this happen, and is it safe to try".
 */
class Theme_Support {

	/**
	 * File types that can be read.
	 *
	 * @var string[]
	 */
	const READ_EXTENSIONS = array( 'php', 'css', 'scss', 'js', 'json', 'html', 'htm', 'txt', 'md', 'xml', 'svg' );

	/**
	 * File types that can be written into a draft.
	 *
	 * SVG is readable but not writable: it can carry script, and a theme rarely
	 * needs an AI to author one. Anything without an extension is refused, which
	 * keeps server configuration such as .htaccess out of reach.
	 *
	 * @var string[]
	 */
	const WRITE_EXTENSIONS = array( 'php', 'css', 'scss', 'js', 'json', 'html', 'txt', 'md' );

	/**
	 * Largest file returned whole by a read.
	 */
	const MAX_READ_BYTES = 524288;

	/**
	 * Largest file a single write may produce.
	 */
	const MAX_WRITE_BYTES = 1048576;

	/**
	 * Largest theme that will be copied into a draft workspace.
	 */
	const MAX_THEME_BYTES = 157286400;

	/**
	 * Directory names skipped when walking a theme: tooling and dependencies,
	 * never theme source anyone edits by hand.
	 *
	 * @var string[]
	 */
	const SKIP_DIRS = array( '.git', '.svn', '.hg', 'node_modules', 'vendor', '.github', '.idea', '.vscode' );

	/**
	 * Memoized loopback probe result for this request.
	 *
	 * @var true|WP_Error|null
	 */
	private static $loopback = null;

	/*
	 * Theme identity
	 */

	/**
	 * The live theme's slug, as stored — never affected by a draft preview.
	 *
	 * @return string
	 */
	public static function stylesheet() {
		return (string) get_option( 'stylesheet' );
	}

	/**
	 * The live theme's parent slug (equal to stylesheet() for a standalone theme).
	 *
	 * @return string
	 */
	public static function template() {
		return (string) get_option( 'template' );
	}

	/**
	 * The live theme's directory, unaffected by any preview filter.
	 *
	 * WP_Theme builds this from the theme root and slug directly, whereas
	 * get_stylesheet_directory() is filterable and is exactly what a draft preview
	 * redirects. Using it here would make a preview request read its own draft.
	 *
	 * @return string Absolute path without a trailing slash.
	 */
	public static function live_dir() {
		$theme = wp_get_theme( self::stylesheet() );
		return untrailingslashit( wp_normalize_path( $theme->get_stylesheet_directory() ) );
	}

	/**
	 * Whether the live theme is a block theme.
	 *
	 * @return bool
	 */
	public static function is_block_theme() {
		$theme = wp_get_theme( self::stylesheet() );
		return method_exists( $theme, 'is_block_theme' ) ? (bool) $theme->is_block_theme() : false;
	}

	/**
	 * Refuses a template or template-part ability on a classic theme.
	 *
	 * @return WP_Error|null
	 */
	public static function block_theme_required() {
		if ( self::is_block_theme() ) {
			return null;
		}

		return Site_Support::error(
			'mosmcp_not_a_block_theme',
			sprintf(
				/* translators: %s: the active theme name. */
				__( 'The active theme, %s, is a classic theme. Templates and template parts belong to block themes, so there are none to read or change here. A classic theme is edited through its files instead: use mosmcp/theme-file-list to see them.', 'mosmcp-abilities' ),
				wp_get_theme( self::stylesheet() )->get( 'Name' )
			),
			Site_Support::CAUSE_NOT_FOUND,
			false,
			array( 'use_instead' => 'mosmcp/theme-file-list' )
		);
	}

	/**
	 * Refuses a global-styles ability when the theme has no theme.json.
	 *
	 * @return WP_Error|null
	 */
	public static function global_styles_required() {
		if ( function_exists( 'wp_theme_has_theme_json' ) && wp_theme_has_theme_json() ) {
			return null;
		}

		return Site_Support::error(
			'mosmcp_no_global_styles',
			sprintf(
				/* translators: %s: the active theme name. */
				__( 'The active theme, %s, has no theme.json, so it does not use global styles. Its appearance is set in its stylesheet: use mosmcp/theme-file-read on style.css.', 'mosmcp-abilities' ),
				wp_get_theme( self::stylesheet() )->get( 'Name' )
			),
			Site_Support::CAUSE_NOT_FOUND,
			false,
			array( 'use_instead' => 'mosmcp/theme-file-read' )
		);
	}

	/**
	 * Refuses a file write when the site forbids editing theme code.
	 *
	 * DISALLOW_FILE_EDIT removes the edit_themes capability, so the capability gate
	 * already stops the call, but it does so with a generic permission refusal.
	 * Naming the constant here tells the person it is a site policy they chose,
	 * not a fault in their account.
	 *
	 * @return WP_Error|null
	 */
	public static function file_editing_blocked() {
		$locked = Site_Support::file_mods_locked();
		if ( $locked instanceof WP_Error ) {
			return $locked;
		}

		if ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) {
			return Site_Support::error(
				'mosmcp_file_edit_disabled',
				__( 'This site has theme file editing switched off, via the DISALLOW_FILE_EDIT constant in wp-config.php. That is a deliberate site policy, and no account can override it from here. Templates, template parts and global styles can still be changed, because they are stored in the database rather than in files.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_HOST_ENVIRONMENT,
				false,
				array( 'constant' => 'DISALLOW_FILE_EDIT' )
			);
		}

		if ( ! current_user_can( 'edit_themes' ) ) {
			return Site_Support::error(
				'mosmcp_cannot_edit_theme_files',
				__( 'This account cannot edit theme files. On a normal site that is an administrator permission; if the account is an administrator, a security plugin has most likely removed it. Templates, template parts and global styles can still be changed.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_CAPABILITY,
				false
			);
		}

		return null;
	}

	/*
	 * Paths
	 */

	/**
	 * Normalizes and validates a theme-relative path.
	 *
	 * Rejects anything that could reach outside the theme: parent-directory
	 * segments, absolute paths, NUL bytes, and stream wrappers. The result uses
	 * forward slashes and has no leading or trailing slash.
	 *
	 * @param string $path Path as supplied.
	 * @return string|WP_Error
	 */
	public static function clean_relative_path( $path ) {
		$path = trim( str_replace( '\\', '/', (string) $path ) );

		if ( '' === $path ) {
			return self::path_error( $path, __( 'No file path was given.', 'mosmcp-abilities' ) );
		}

		if ( false !== strpos( $path, "\0" ) || preg_match( '#^[a-z][a-z0-9+.-]*://#i', $path ) || preg_match( '#^[a-z]:#i', $path ) ) {
			return self::path_error( $path, __( 'That is not a path inside the theme.', 'mosmcp-abilities' ) );
		}

		$path     = ltrim( $path, '/' );
		$segments = array();

		foreach ( explode( '/', $path ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				return self::path_error( $path, __( 'Paths may not step outside the theme with "..".', 'mosmcp-abilities' ) );
			}
			$segments[] = $segment;
		}

		if ( empty( $segments ) ) {
			return self::path_error( $path, __( 'No file path was given.', 'mosmcp-abilities' ) );
		}

		return implode( '/', $segments );
	}

	/**
	 * Resolves a relative path inside a root directory, proving containment.
	 *
	 * For an existing file the real path must sit inside the root, which defeats
	 * symlinks pointing elsewhere. For a file that does not exist yet, the nearest
	 * existing ancestor is checked instead.
	 *
	 * @param string $root     Absolute root directory.
	 * @param string $relative Clean relative path.
	 * @return string|WP_Error Absolute path.
	 */
	public static function resolve( $root, $relative ) {
		$root_real = realpath( $root );
		if ( false === $root_real ) {
			return Site_Support::error(
				'mosmcp_theme_root_missing',
				__( 'The theme folder could not be found on disk.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_HOST_ENVIRONMENT,
				false
			);
		}
		$root_real = wp_normalize_path( $root_real );
		$target    = wp_normalize_path( $root_real . '/' . $relative );

		$probe = self::nearest_existing( $target );

		$probe_real = realpath( $probe );
		if ( false === $probe_real ) {
			return self::path_error( $relative, __( 'That path could not be resolved.', 'mosmcp-abilities' ) );
		}
		$probe_real = wp_normalize_path( $probe_real );

		if ( $probe_real !== $root_real && 0 !== strpos( $probe_real . '/', $root_real . '/' ) ) {
			return self::path_error( $relative, __( 'That path resolves outside the theme, so it cannot be used.', 'mosmcp-abilities' ) );
		}

		return $target;
	}

	/**
	 * The path itself if it exists, otherwise its nearest existing ancestor.
	 *
	 * @param string $path Absolute path.
	 * @return string
	 */
	public static function nearest_existing( $path ) {
		$probe = (string) $path;
		while ( ! file_exists( $probe ) ) {
			$parent = dirname( $probe );
			if ( $parent === $probe ) {
				break;
			}
			$probe = $parent;
		}
		return $probe;
	}

	/**
	 * Lower-case extension of a path.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	public static function extension( $path ) {
		return strtolower( (string) pathinfo( (string) $path, PATHINFO_EXTENSION ) );
	}

	/**
	 * Refuses a file type that may not be read.
	 *
	 * @param string $relative Relative path.
	 * @return WP_Error|null
	 */
	public static function readable_type( $relative ) {
		if ( in_array( self::extension( $relative ), self::READ_EXTENSIONS, true ) ) {
			return null;
		}

		return Site_Support::error(
			'mosmcp_theme_file_type_unreadable',
			sprintf(
				/* translators: 1: path, 2: list of readable file types. */
				__( '"%1$s" is not a text file this can read. Readable types are: %2$s. Images, fonts and other binary assets are listed but not returned.', 'mosmcp-abilities' ),
				$relative,
				implode( ', ', self::READ_EXTENSIONS )
			),
			Site_Support::CAUSE_INVALID_INPUT,
			false
		);
	}

	/**
	 * Refuses a file type that may not be written.
	 *
	 * @param string $relative Relative path.
	 * @return WP_Error|null
	 */
	public static function writable_type( $relative ) {
		if ( in_array( self::extension( $relative ), self::WRITE_EXTENSIONS, true ) ) {
			return null;
		}

		return Site_Support::error(
			'mosmcp_theme_file_type_unwritable',
			sprintf(
				/* translators: 1: path, 2: list of writable file types. */
				__( '"%1$s" is not a file type this can write. Writable types are: %2$s. Files with no extension, such as .htaccess, are refused because they configure the server rather than the theme.', 'mosmcp-abilities' ),
				$relative,
				implode( ', ', self::WRITE_EXTENSIONS )
			),
			Site_Support::CAUSE_INVALID_INPUT,
			false
		);
	}

	/**
	 * Files that must never be deleted, because the theme stops working without them.
	 *
	 * @return string[] Relative paths.
	 */
	public static function protected_files() {
		$files = array( 'style.css' );

		if ( self::is_block_theme() ) {
			$files[] = 'templates/index.html';
		} else {
			$files[] = 'index.php';
		}

		if ( self::stylesheet() !== self::template() ) {
			// A child theme cannot stand without the header naming its parent.
			$files = array( 'style.css' );
		}

		return $files;
	}

	/*
	 * Content validation
	 */

	/**
	 * Validates PHP source without executing it.
	 *
	 * The tokenizer in TOKEN_PARSE mode runs PHP's own parser, so a syntax error
	 * throws exactly as it would at include time, in-process, with no shell.
	 *
	 * @param string $source   PHP source.
	 * @param string $relative Path, for the message.
	 * @return WP_Error|null
	 */
	public static function php_syntax_error( $source, $relative ) {
		if ( ! defined( 'TOKEN_PARSE' ) ) {
			return null;
		}

		try {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Compile warnings would otherwise print; the thrown ParseError is what is inspected.
			@token_get_all( (string) $source, TOKEN_PARSE );
		} catch ( \ParseError $e ) {
			return self::syntax_error( $relative, $e->getMessage(), $e->getLine() );
		} catch ( \CompileError $e ) {
			return self::syntax_error( $relative, $e->getMessage(), $e->getLine() );
		}

		return null;
	}

	/**
	 * Validates content by file type before it is written into a draft.
	 *
	 * PHP must parse; JSON must decode; an HTML template must not lose or gain an
	 * unbalanced block delimiter compared to the version it replaces.
	 *
	 * @param string      $relative Relative path.
	 * @param string      $content  New content.
	 * @param string|null $previous Content it replaces, or null for a new file.
	 * @return WP_Error|null
	 */
	public static function content_error( $relative, $content, $previous = null ) {
		if ( strlen( (string) $content ) > self::MAX_WRITE_BYTES ) {
			return Site_Support::error(
				'mosmcp_theme_file_too_large',
				sprintf(
					/* translators: 1: path, 2: size limit in kilobytes. */
					__( 'The new content for "%1$s" is larger than %2$d KB, which is far beyond what a theme source file needs. Nothing was written.', 'mosmcp-abilities' ),
					$relative,
					(int) ( self::MAX_WRITE_BYTES / 1024 )
				),
				Site_Support::CAUSE_INVALID_INPUT,
				false
			);
		}

		$ext = self::extension( $relative );

		if ( 'php' === $ext ) {
			return self::php_syntax_error( $content, $relative );
		}

		if ( 'json' === $ext && '' !== trim( (string) $content ) ) {
			json_decode( (string) $content );
			if ( JSON_ERROR_NONE !== json_last_error() ) {
				return Site_Support::error(
					'mosmcp_theme_json_invalid',
					sprintf(
						/* translators: 1: path, 2: the JSON parser's message. */
						__( 'The new content for "%1$s" is not valid JSON (%2$s), and WordPress would silently ignore a broken file there. Nothing was written.', 'mosmcp-abilities' ),
						$relative,
						json_last_error_msg()
					),
					Site_Support::CAUSE_INVALID_INPUT,
					true
				);
			}
		}

		if ( 'style.css' === $relative ) {
			$header = self::theme_header_error( (string) $content );
			if ( $header instanceof WP_Error ) {
				return $header;
			}
		}

		if ( 'html' === $ext && null !== $previous && Content_Matcher::blocks_broken_by_edit( (string) $previous, (string) $content ) ) {
			return Site_Support::error(
				'mosmcp_theme_blocks_broken',
				sprintf(
					/* translators: %s: path. */
					__( 'The new content for "%s" would leave a block unbalanced — an opening block comment without its closing one, or the reverse — and the Site Editor would show the template as broken. Nothing was written. Keep every <!-- wp:... --> paired with its <!-- /wp:... -->.', 'mosmcp-abilities' ),
					$relative
				),
				Site_Support::CAUSE_INVALID_INPUT,
				true
			);
		}

		return null;
	}

	/**
	 * Refuses a style.css that WordPress would no longer recognise as this theme.
	 *
	 * The CSS itself may change freely; the header comment may not lose its
	 * Theme Name, and a child theme's Template must still name the live parent.
	 *
	 * @param string $content New style.css content.
	 * @return WP_Error|null
	 */
	public static function theme_header_error( $content ) {
		$keep = __( 'Keep the header comment at the top of style.css as it is and change only the CSS below it.', 'mosmcp-abilities' );

		if ( '' === self::theme_header( $content, 'Theme Name' ) ) {
			return Site_Support::error(
				'mosmcp_theme_header_missing',
				__( 'The new style.css has no "Theme Name:" header, and WordPress would stop recognising the theme and switch the site to a default theme. Nothing was written.', 'mosmcp-abilities' ) . ' ' . $keep,
				Site_Support::CAUSE_INVALID_INPUT,
				true
			);
		}

		$parent = self::template();
		if ( self::stylesheet() !== $parent && self::theme_header( $content, 'Template' ) !== $parent ) {
			return Site_Support::error(
				'mosmcp_theme_header_parent',
				sprintf(
					/* translators: %s: parent theme slug. */
					__( 'This is a child theme, and the new style.css no longer has "Template: %s" in its header. Without it WordPress cannot find the parent theme and the site would break. Nothing was written.', 'mosmcp-abilities' ),
					$parent
				) . ' ' . $keep,
				Site_Support::CAUSE_INVALID_INPUT,
				true,
				array( 'expected_template' => $parent )
			);
		}

		return null;
	}

	/**
	 * Reads one header field the way WordPress's get_file_data() does.
	 *
	 * @param string $content File content.
	 * @param string $field   Header name, e.g. "Theme Name".
	 * @return string Trimmed value, or '' when absent.
	 */
	private static function theme_header( $content, $field ) {
		$head = substr( str_replace( "\r", "\n", (string) $content ), 0, 8192 );
		if ( ! preg_match( '/^(?:[ \t]*<\?php)?[ \t\/*#@]*' . preg_quote( $field, '/' ) . ':(.*)$/mi', $head, $match ) ) {
			return '';
		}

		return trim( preg_replace( '/\s*(?:\*\/|\?>).*/', '', $match[1] ) );
	}

	/**
	 * Validates block markup destined for a template, part or pattern.
	 *
	 * @param string      $content  New block markup.
	 * @param string|null $previous Markup it replaces, or null.
	 * @param string      $label    What is being changed, for the message.
	 * @return WP_Error|null
	 */
	public static function block_markup_error( $content, $previous, $label ) {
		if ( '' === trim( (string) $content ) ) {
			return Site_Support::error(
				'mosmcp_block_markup_empty',
				sprintf(
					/* translators: %s: what is being changed. */
					__( 'No content was given for %s. An empty template renders a blank page, so it is refused. To go back to the theme\'s own version, use the matching reset ability.', 'mosmcp-abilities' ),
					$label
				),
				Site_Support::CAUSE_INVALID_INPUT,
				true
			);
		}

		if ( null === $previous ) {
			$health = Content_Matcher::block_health( (string) $content );
			$broken = $health['imbalance'] > 0 || $health['broken_attrs'] > 0 || $health['unparsed'] > 0;
		} else {
			// Judged as a delta, so markup that was already imperfect can still be edited.
			$broken = Content_Matcher::blocks_broken_by_edit( (string) $previous, (string) $content );
		}

		if ( $broken ) {
			return Site_Support::error(
				'mosmcp_block_markup_broken',
				sprintf(
					/* translators: %s: what is being changed. */
					__( 'The content for %s has unbalanced block comments — an opening <!-- wp:... --> without its closing <!-- /wp:... -->, or the reverse — so the Site Editor would show it as broken. Nothing was changed.', 'mosmcp-abilities' ),
					$label
				),
				Site_Support::CAUSE_INVALID_INPUT,
				true
			);
		}

		return null;
	}

	/*
	 * Filesystem
	 */

	/**
	 * Returns WordPress's filesystem object, only when it writes directly.
	 *
	 * On hosts where the web server does not own the files, WordPress falls back to
	 * FTP or SSH and asks for credentials interactively, which an AI request cannot
	 * supply. Detecting that up front turns a confusing mid-write failure into one
	 * clear sentence.
	 *
	 * @return \WP_Filesystem_Base|WP_Error
	 */
	public static function fs() {
		if ( ! function_exists( 'get_filesystem_method' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$method = get_filesystem_method( array(), WP_CONTENT_DIR );

		if ( 'direct' !== $method ) {
			return Site_Support::error(
				'mosmcp_filesystem_not_direct',
				sprintf(
					/* translators: %s: the filesystem method WordPress chose, such as "ftpext". */
					__( 'WordPress cannot write files directly on this server: the web server does not own the site\'s files, so WordPress would fall back to "%s" and ask for connection details, which cannot happen from here. The usual fixes are to make the web server user own wp-content, or to add define( \'FS_METHOD\', \'direct\' ); to wp-config.php when that user can already write to it. Reading files still works.', 'mosmcp-abilities' ),
					$method
				),
				Site_Support::CAUSE_HOST_ENVIRONMENT,
				false,
				array( 'filesystem_method' => $method )
			);
		}

		global $wp_filesystem;
		if ( ! $wp_filesystem instanceof \WP_Filesystem_Base ) {
			WP_Filesystem();
		}

		if ( ! $wp_filesystem instanceof \WP_Filesystem_Base ) {
			return Site_Support::error(
				'mosmcp_filesystem_unavailable',
				__( 'WordPress could not start its filesystem layer, so no file can be written.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_HOST_ENVIRONMENT,
				false
			);
		}

		return $wp_filesystem;
	}

	/**
	 * Reads a file's contents.
	 *
	 * @param string $path Absolute path.
	 * @return string|false
	 */
	public static function read_file( $path ) {
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local theme file, never a URL; path already proven inside the theme.
		return file_get_contents( $path );
	}

	/**
	 * Writes a file, creating parent directories as needed.
	 *
	 * @param string $path    Absolute path.
	 * @param string $content Content.
	 * @return true|WP_Error
	 */
	public static function write_file( $path, $content ) {
		$fs = self::fs();
		if ( is_wp_error( $fs ) ) {
			return $fs;
		}

		if ( file_exists( $path ) ) {
			/*
			 * An existing file keeps its ownership and mode. WordPress's filesystem
			 * layer would also chmod it after writing, which fails — with a PHP
			 * warning on every write — whenever the web server can write the file
			 * through its group but does not own it, a common shared-hosting setup.
			 */
			if ( ! wp_is_writable( $path ) ) {
				return self::write_error( $path );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Direct method already confirmed by fs(); an in-place write avoids a chmod the host may forbid.
			if ( false === file_put_contents( $path, (string) $content ) ) {
				return self::write_error( $path );
			}
		} else {
			$dir = dirname( $path );
			if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
				return self::write_error( $path );
			}

			$mode = defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644;
			if ( ! $fs->put_contents( $path, (string) $content, $mode ) ) {
				return self::write_error( $path );
			}
		}

		if ( 'php' === self::extension( $path ) ) {
			wp_opcache_invalidate( $path, true );
		}

		return true;
	}

	/**
	 * Deletes a file or a directory tree.
	 *
	 * @param string $path Absolute path.
	 * @return bool
	 */
	public static function delete_path( $path ) {
		$fs = self::fs();
		if ( is_wp_error( $fs ) || ! file_exists( $path ) ) {
			return ! file_exists( $path );
		}

		return (bool) $fs->delete( $path, true );
	}

	/**
	 * Copies a directory tree, skipping tooling folders.
	 *
	 * @param string $source      Absolute source directory.
	 * @param string $destination Absolute destination directory.
	 * @return true|WP_Error
	 */
	public static function copy_tree( $source, $destination ) {
		$fs = self::fs();
		if ( is_wp_error( $fs ) ) {
			return $fs;
		}

		if ( ! wp_mkdir_p( $destination ) ) {
			return self::write_error( $destination );
		}

		foreach ( self::walk( $source, false ) as $relative ) {
			$from = $source . '/' . $relative;
			$to   = $destination . '/' . $relative;

			if ( ! is_dir( dirname( $to ) ) && ! wp_mkdir_p( dirname( $to ) ) ) {
				return self::write_error( $to );
			}
			if ( ! $fs->copy( $from, $to, true, defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644 ) ) {
				return self::write_error( $to );
			}
		}

		return true;
	}

	/**
	 * Lists every file under a directory, as relative paths, skipping tooling.
	 *
	 * @param string $root      Absolute directory.
	 * @param bool   $text_only Only file types that can be read.
	 * @param int    $limit     Stop after this many files; 0 for no limit.
	 * @return string[] Sorted relative paths.
	 */
	public static function walk( $root, $text_only = false, $limit = 0 ) {
		$root = untrailingslashit( wp_normalize_path( (string) $root ) );
		if ( ! is_dir( $root ) ) {
			return array();
		}

		$files    = array();
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveCallbackFilterIterator(
				new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ),
				static function ( $current ) {
					if ( $current->isDir() ) {
						return ! in_array( $current->getFilename(), self::SKIP_DIRS, true ) && ! $current->isLink();
					}
					return true;
				}
			)
		);

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}
			$relative = ltrim( substr( wp_normalize_path( $file->getPathname() ), strlen( $root ) ), '/' );
			if ( $text_only && ! in_array( self::extension( $relative ), self::READ_EXTENSIONS, true ) ) {
				continue;
			}
			$files[] = $relative;
			if ( $limit > 0 && count( $files ) >= $limit ) {
				break;
			}
		}

		sort( $files );

		return $files;
	}

	/**
	 * Total size in bytes of a directory tree, skipping tooling folders.
	 *
	 * @param string $root Absolute directory.
	 * @return int
	 */
	public static function tree_size( $root ) {
		$total = 0;
		foreach ( self::walk( $root ) as $relative ) {
			$total += (int) filesize( $root . '/' . $relative );
		}
		return $total;
	}

	/**
	 * Writes the protective files every private workspace directory gets.
	 *
	 * Static assets must stay reachable so a draft preview can load its own CSS,
	 * fonts and scripts, but PHP inside must never be requested directly. The
	 * .htaccess rule covers Apache; the empty index.php stops directory listings
	 * elsewhere; the random folder name makes the path unguessable.
	 *
	 * @param string $dir Absolute directory.
	 * @return void
	 */
	public static function protect_dir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$htaccess = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			self::write_file(
				$htaccess,
				"# Written by the theme abilities. PHP here is never requested directly.\n<FilesMatch \"\\.(php|phtml|phar)$\">\n\tRequire all denied\n</FilesMatch>\nOptions -Indexes\n"
			);
		}

		$index = $dir . '/index.php';
		if ( ! file_exists( $index ) ) {
			self::write_file( $index, "<?php\n// Silence is golden.\n" );
		}
	}

	/*
	 * Host preflight
	 */

	/**
	 * Whether this site can make an HTTP request to itself.
	 *
	 * The health checks work by requesting the site's own pages. Some hosts block
	 * that, in which case a check would report every change as broken; better to
	 * know first and say so.
	 *
	 * @return true|WP_Error
	 */
	public static function loopback() {
		if ( null !== self::$loopback ) {
			return self::$loopback;
		}

		$response = wp_remote_get(
			add_query_arg( 'mosmcp_loopback', (string) time(), home_url( '/' ) ),
			array(
				'timeout'   => 15,
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter.
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
				'headers'   => array( 'Cache-Control' => 'no-cache' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			self::$loopback = Site_Support::error(
				'mosmcp_loopback_failed',
				sprintf(
					/* translators: %s: the error WordPress reported. */
					__( 'This site cannot reach itself over HTTP (%s). The safety checks that test a theme change before and after it goes live work by requesting the site\'s own pages, so they cannot run here. Many hosts block this by accident; the host can allow the server to request its own domain.', 'mosmcp-abilities' ),
					$response->get_error_message()
				),
				Site_Support::CAUSE_HOST_ENVIRONMENT,
				true
			);
			return self::$loopback;
		}

		self::$loopback = true;

		return true;
	}

	/**
	 * A one-glance report of whether theme editing will work on this host.
	 *
	 * @return array<string, mixed>
	 */
	public static function preflight() {
		if ( ! function_exists( 'get_filesystem_method' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$method   = get_filesystem_method( array(), WP_CONTENT_DIR );
		$loopback = self::loopback();
		$live     = self::live_dir();

		$blocked = self::file_editing_blocked();

		return array(
			'theme'             => self::stylesheet(),
			'is_block_theme'    => self::is_block_theme(),
			'is_child_theme'    => self::stylesheet() !== self::template(),
			'filesystem_method' => (string) $method,
			'files_writable'    => ( 'direct' === $method ) && wp_is_writable( $live ),
			'file_editing'      => ! ( $blocked instanceof WP_Error ),
			'file_editing_note' => $blocked instanceof WP_Error ? $blocked->get_error_message() : '',
			'loopback_ok'       => true === $loopback,
			'loopback_note'     => $loopback instanceof WP_Error ? $loopback->get_error_message() : '',
			'receives_updates'  => self::receives_updates(),
		);
	}

	/**
	 * Whether the live theme is one WordPress updates automatically from
	 * wordpress.org or a commercial updater — in which case an update will replace
	 * its files, including any edited ones.
	 *
	 * @return bool
	 */
	public static function receives_updates() {
		$slug    = self::stylesheet();
		$updates = get_site_transient( 'update_themes' );

		if ( is_object( $updates ) ) {
			foreach ( array( 'response', 'no_update' ) as $bucket ) {
				if ( isset( $updates->{$bucket} ) && is_array( $updates->{$bucket} ) && isset( $updates->{$bucket}[ $slug ] ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/*
	 * Errors
	 */

	/**
	 * Builds the refusal for an unusable path.
	 *
	 * @param string $path    Path as supplied.
	 * @param string $message Explanation.
	 * @return WP_Error
	 */
	private static function path_error( $path, $message ) {
		return Site_Support::error(
			'mosmcp_theme_path_invalid',
			$message,
			Site_Support::CAUSE_INVALID_INPUT,
			false,
			array( 'path' => (string) $path )
		);
	}

	/**
	 * Builds the refusal for a PHP syntax error.
	 *
	 * @param string $relative Path.
	 * @param string $message  Parser message.
	 * @param int    $line     Line number.
	 * @return WP_Error
	 */
	private static function syntax_error( $relative, $message, $line ) {
		return Site_Support::error(
			'mosmcp_theme_php_syntax',
			sprintf(
				/* translators: 1: path, 2: line number, 3: the parser's message. */
				__( 'The new content for "%1$s" has a PHP syntax error on line %2$d: %3$s. It would take the site down if it went live, so nothing was written.', 'mosmcp-abilities' ),
				$relative,
				(int) $line,
				$message
			),
			Site_Support::CAUSE_INVALID_INPUT,
			true,
			array(
				'path' => $relative,
				'line' => (int) $line,
			)
		);
	}

	/**
	 * Builds the refusal for a failed write.
	 *
	 * @param string $path Absolute path.
	 * @return WP_Error
	 */
	private static function write_error( $path ) {
		return Site_Support::error(
			'mosmcp_theme_write_failed',
			sprintf(
				/* translators: %s: the folder or file that could not be written. */
				__( 'The server refused to write "%s". The web server user needs write access to that folder.', 'mosmcp-abilities' ),
				str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( $path ) )
			),
			Site_Support::CAUSE_HOST_ENVIRONMENT,
			false
		);
	}
}
