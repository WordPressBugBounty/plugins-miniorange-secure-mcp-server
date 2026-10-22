<?php
/**
 * Renders the site with the theme draft applied — for the signed-in administrator
 * holding the preview link, and for no one else.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Theming;

use WP_Block_Template;
use WP_Post;
use WP_Query;

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
 * Class Theme_Preview
 *
 * Two independent layers, both off unless a valid preview is active:
 *
 * 1. Files. The theme *folder* is redirected to the draft's copy through the
 *    stylesheet_directory / template_directory filters, which WordPress consults
 *    before it loads functions.php and when it resolves templates and theme.json.
 *    The theme *slug* is left alone. That matters: Site Editor customisations are
 *    stored against the slug, so keeping it means they still apply in preview and
 *    the preview shows what will actually go live. Swapping the slug instead —
 *    the obvious approach — silently drops every customisation from the preview.
 *
 * 2. Blocks. Pending template, template-part, global-styles and synced-pattern
 *    changes are overlaid on top of the live values as pages render.
 *
 * Block overlays apply to rendered pages only, never to REST or admin requests,
 * so an administrator who opens the Site Editor while previewing sees and saves
 * the live site, and draft content can never leak into the database that way.
 */
class Theme_Preview {

	/**
	 * Query argument carrying the preview token.
	 */
	const QUERY_VAR = 'mosmcp_theme_preview';

	/**
	 * Cookie that keeps a preview active while navigating.
	 */
	const COOKIE = 'mosmcp_theme_preview';

	/**
	 * Query argument that ends a preview.
	 */
	const EXIT_VAR = 'mosmcp_theme_preview_exit';

	/**
	 * Whether hooks are registered.
	 *
	 * @var bool
	 */
	private static $booted = false;

	/**
	 * The active draft for this request, false when not previewing, null until resolved.
	 *
	 * @var array<string, mixed>|false|null
	 */
	private static $active = null;

	/**
	 * Re-entrancy guard while resolving the active draft.
	 *
	 * @var bool
	 */
	private static $resolving = false;

	/**
	 * Nesting depth while rendering pending synced patterns.
	 *
	 * @var int
	 */
	private static $pattern_depth = 0;

	/**
	 * Registers the preview hooks. Safe to call more than once.
	 *
	 * Every hook returns immediately when the request carries no preview token, so
	 * the cost on a normal page view is one array lookup.
	 *
	 * @return void
	 */
	public static function boot() {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		add_filter( 'stylesheet_directory', array( __CLASS__, 'stylesheet_directory' ), 20, 2 );
		add_filter( 'template_directory', array( __CLASS__, 'template_directory' ), 20, 2 );
		add_filter( 'stylesheet_directory_uri', array( __CLASS__, 'stylesheet_directory_uri' ), 20, 2 );
		add_filter( 'template_directory_uri', array( __CLASS__, 'template_directory_uri' ), 20, 2 );

		add_filter( 'get_block_templates', array( __CLASS__, 'overlay_templates' ), 20 );
		add_filter( 'get_block_template', array( __CLASS__, 'overlay_template' ), 20 );
		add_filter( 'get_block_file_template', array( __CLASS__, 'overlay_file_template' ), 20 );
		add_filter( 'the_posts', array( __CLASS__, 'overlay_part_posts' ), 20, 2 );
		add_filter( 'wp_theme_json_data_user', array( __CLASS__, 'overlay_styles' ), 20 );
		add_filter( 'pre_render_block', array( __CLASS__, 'overlay_synced_pattern' ), 20, 2 );
		// Right after core registers theme patterns (init, 10) from the live folder.
		add_action( 'init', array( __CLASS__, 'overlay_file_patterns' ), 11 );

		// Runs after every plugin has loaded but before WordPress resolves the theme
		// and includes functions.php, so it still works when the draft is broken.
		add_action( 'plugins_loaded', array( __CLASS__, 'early_request' ), PHP_INT_MAX );
		add_action( 'init', array( __CLASS__, 'handle_request' ), 1 );
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar' ), 999 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'admin_bar_style' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_bar_style' ) );
	}

	/**
	 * The preview link for a token.
	 *
	 * @param string $token Preview token.
	 * @param string $path  Site path to open.
	 * @return string
	 */
	public static function url( $token, $path = '/' ) {
		return add_query_arg( self::QUERY_VAR, rawurlencode( (string) $token ), home_url( $path ) );
	}

	/**
	 * The draft being previewed in this request, or false.
	 *
	 * A preview needs all of: a token matching the stored draft, an unexpired
	 * token, a signed-in user who may change the theme, and — when the draft edits
	 * files — a user who may edit theme files. A leaked link or cookie is inert
	 * on its own.
	 *
	 * @return array<string, mixed>|false
	 */
	public static function active() {
		if ( null !== self::$active ) {
			return self::$active;
		}
		if ( self::$resolving ) {
			return false;
		}

		$token = self::request_token();
		if ( '' === $token || self::is_api_request() ) {
			self::$active = false;
			return false;
		}

		self::$resolving = true;

		$draft = Theme_Draft::current();
		$valid = is_array( $draft )
			&& ! empty( $draft['token'] )
			&& hash_equals( (string) $draft['token'], $token )
			&& (int) $draft['token_expires'] > time()
			&& function_exists( 'is_user_logged_in' )
			&& is_user_logged_in()
			&& current_user_can( 'edit_theme_options' );

		if ( $valid && Theme_Draft::touches_files( $draft ) && ! current_user_can( 'edit_themes' ) ) {
			$valid = false;
		}

		self::$resolving = false;
		self::$active    = $valid ? $draft : false;

		return self::$active;
	}

	/**
	 * Whether this request is API traffic rather than a page someone is looking at.
	 *
	 * A preview is for people. Applying it to REST (which includes the MCP endpoint
	 * the AI itself talks through), AJAX, cron or XML-RPC would let a broken draft
	 * take down the very connection used to fix it, and would feed draft content to
	 * the Site Editor. This has to be decided before WordPress loads the theme,
	 * which is too early for its own REST_REQUEST flag, so the REST URL is
	 * recognised directly.
	 *
	 * @return bool
	 */
	public static function is_api_request() {
		if ( wp_doing_ajax() || wp_doing_cron() ) {
			return true;
		}
		foreach ( array( 'REST_REQUEST', 'XMLRPC_REQUEST', 'WP_CLI' ) as $flag ) {
			if ( defined( $flag ) && constant( $flag ) ) {
				return true;
			}
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only inspected, never used or output.
		if ( isset( $_GET['rest_route'] ) ) {
			return true;
		}
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$prefix = '/' . trim( rest_get_url_prefix(), '/' ) . '/';

		return false !== strpos( $uri, $prefix ) || false !== strpos( $uri, 'xmlrpc.php' );
	}

	/**
	 * Whether block overlays apply to this request.
	 *
	 * @return bool
	 */
	private static function blocks_apply() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return false;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}
		return false !== self::active();
	}

	/**
	 * The preview token supplied with this request.
	 *
	 * @return string
	 */
	private static function request_token() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- A preview token is itself the credential, and it is compared in constant time against the stored draft.
		$raw = '';
		if ( isset( $_GET[ self::QUERY_VAR ] ) ) {
			$raw = sanitize_text_field( wp_unslash( $_GET[ self::QUERY_VAR ] ) );
		} elseif ( isset( $_COOKIE[ self::COOKIE ] ) ) {
			$raw = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return (string) preg_replace( '/[^A-Za-z0-9]/', '', $raw );
	}

	/*
	 * Request handling
	 */

	/**
	 * Handles what must happen before the theme loads: ending a preview, and
	 * arming a way out if the draft being previewed fails.
	 *
	 * @return void
	 */
	public static function early_request() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Ending a preview only clears the caller's own cookie.
		if ( isset( $_GET[ self::EXIT_VAR ] ) ) {
			self::set_cookie( '', time() - YEAR_IN_SECONDS );
			wp_safe_redirect( remove_query_arg( array( self::EXIT_VAR, self::QUERY_VAR ) ) );
			exit;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( false !== self::active() ) {
			register_shutdown_function( array( __CLASS__, 'fatal_notice' ) );
		}
	}

	/**
	 * Offers a way out when a previewed page dies with a fatal error.
	 *
	 * Without this an administrator previewing a broken draft would be stuck in
	 * it: the toolbar never renders on a fatal, and the cookie lasts a day.
	 *
	 * @return void
	 */
	public static function fatal_notice() {
		$error = error_get_last();
		if ( empty( $error ) || ! in_array( (int) $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR ), true ) ) {
			return;
		}

		// Only an administrator holding this draft's token gets here, so naming the
		// error is safe, and it is what they need to fix it.
		$where = sprintf(
			/* translators: 1: file inside the theme, 2: line number. */
			__( '%1$s, line %2$d', 'mosmcp-abilities' ),
			Theme_Health::readable_path( (string) $error['file'] ),
			(int) $error['line']
		);

		echo '<div style="font:15px/1.5 sans-serif;margin:24px auto;max-width:640px;padding:12px 16px;background:#fff7ed;border:1px solid #fdba74;border-radius:6px;color:#7c2d12">'
			. '<p style="margin:0 0 8px">' . esc_html__( 'You are previewing a theme draft, and it fails with a fatal error. Visitors are not affected: they still see the live site.', 'mosmcp-abilities' ) . '</p>'
			. '<p style="margin:0 0 8px"><code>' . esc_html( Theme_Health::readable_message( (string) $error['message'] ) ) . '</code> (' . esc_html( $where ) . ')</p>'
			. '<p style="margin:0"><a href="' . esc_url( add_query_arg( self::EXIT_VAR, '1', home_url( '/' ) ) ) . '">' . esc_html__( 'Exit preview', 'mosmcp-abilities' ) . '</a></p></div>';
	}

	/**
	 * Pins a preview to a cookie and keeps previews out of caches.
	 *
	 * @return void
	 */
	public static function handle_request() {
		// phpcs:disable WordPress.Security.NonceVerification -- A preview token is itself the credential (see request_token()).
		$draft = self::active();
		if ( false === $draft ) {
			return;
		}

		if ( isset( $_GET[ self::QUERY_VAR ] ) ) {
			self::set_cookie( (string) $draft['token'], (int) $draft['token_expires'] );
		}
		// phpcs:enable WordPress.Security.NonceVerification

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Honoured by page-caching plugins.
		}
		if ( ! headers_sent() ) {
			nocache_headers();
			header( 'X-Robots-Tag: noindex, nofollow' );
		}
	}

	/**
	 * Writes or clears the preview cookie.
	 *
	 * @param string $value   Token, or empty to clear.
	 * @param int    $expires Expiry timestamp.
	 * @return void
	 */
	private static function set_cookie( $value, $expires ) {
		if ( headers_sent() ) {
			return;
		}
		setcookie(
			self::COOKIE,
			$value,
			array(
				'expires'  => $expires,
				'path'     => defined( 'SITECOOKIEPATH' ) && SITECOOKIEPATH ? SITECOOKIEPATH : '/',
				'domain'   => defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}

	/*
	 * Layer 1: files
	 */

	/**
	 * The draft's copy of the theme, when previewing a draft that edits files.
	 *
	 * @param string $stylesheet Theme slug the filter is resolving.
	 * @return string
	 */
	private static function workspace_for( $stylesheet ) {
		$draft = self::active();
		if ( false === $draft || (string) $stylesheet !== (string) $draft['stylesheet'] ) {
			return '';
		}
		return Theme_Draft::workspace_path( $draft );
	}

	/**
	 * Redirects the active theme's folder to the draft copy.
	 *
	 * @param string $dir        Directory.
	 * @param string $stylesheet Theme slug.
	 * @return string
	 */
	public static function stylesheet_directory( $dir, $stylesheet = '' ) {
		$workspace = self::workspace_for( $stylesheet );
		return '' !== $workspace ? $workspace : $dir;
	}

	/**
	 * Redirects the parent-theme folder too, but only for a standalone theme.
	 *
	 * For a child theme the draft copies the child; the parent stays live.
	 *
	 * @param string $dir      Directory.
	 * @param string $template Parent theme slug.
	 * @return string
	 */
	public static function template_directory( $dir, $template = '' ) {
		$draft = self::active();
		if ( false === $draft || (string) $draft['stylesheet'] !== (string) $draft['template'] ) {
			return $dir;
		}
		$workspace = self::workspace_for( $template );
		return '' !== $workspace ? $workspace : $dir;
	}

	/**
	 * URL of the draft copy, so the preview loads the draft's own CSS and scripts.
	 *
	 * @param string $uri        URI.
	 * @param string $stylesheet Theme slug.
	 * @return string
	 */
	public static function stylesheet_directory_uri( $uri, $stylesheet = '' ) {
		$workspace = self::workspace_for( $stylesheet );
		return '' !== $workspace ? self::workspace_uri( $workspace ) : $uri;
	}

	/**
	 * URL of the draft copy for a standalone theme's template directory.
	 *
	 * @param string $uri      URI.
	 * @param string $template Parent theme slug.
	 * @return string
	 */
	public static function template_directory_uri( $uri, $template = '' ) {
		$draft = self::active();
		if ( false === $draft || (string) $draft['stylesheet'] !== (string) $draft['template'] ) {
			return $uri;
		}
		$workspace = self::workspace_for( $template );
		return '' !== $workspace ? self::workspace_uri( $workspace ) : $uri;
	}

	/**
	 * Converts a path under wp-content to its URL.
	 *
	 * @param string $path Absolute path.
	 * @return string
	 */
	private static function workspace_uri( $path ) {
		$content = untrailingslashit( wp_normalize_path( WP_CONTENT_DIR ) );
		return content_url( ltrim( substr( wp_normalize_path( $path ), strlen( $content ) ), '/' ) );
	}

	/*
	 * Layer 2: blocks
	 */

	/**
	 * Applies a pending template change to one template object.
	 *
	 * @param WP_Block_Template    $template Template.
	 * @param array<string, mixed> $draft    Draft.
	 * @return WP_Block_Template
	 */
	private static function apply_entry( WP_Block_Template $template, array $draft ) {
		if ( (string) $template->theme !== (string) $draft['stylesheet'] ) {
			return $template;
		}

		$entry = Theme_Draft::template_entry( $draft, $template->type, $template->slug );
		if ( null === $entry ) {
			return $template;
		}

		if ( 'update' === $entry['action'] ) {
			$clone          = clone $template;
			$clone->content = (string) $entry['content'];
			return $clone;
		}

		$file = Block_Templates::theme_file( $template->type, $template->slug );
		return $file instanceof WP_Block_Template ? $file : $template;
	}

	/**
	 * Overlays pending changes on a list of templates (how pages resolve theirs).
	 *
	 * @param WP_Block_Template[] $templates Templates.
	 * @return WP_Block_Template[]
	 */
	public static function overlay_templates( $templates ) {
		if ( ! is_array( $templates ) || ! self::blocks_apply() ) {
			return $templates;
		}
		$draft = self::active();
		if ( empty( $draft['templates'] ) ) {
			return $templates;
		}
		foreach ( $templates as $index => $template ) {
			if ( $template instanceof WP_Block_Template ) {
				$templates[ $index ] = self::apply_entry( $template, $draft );
			}
		}
		return $templates;
	}

	/**
	 * Overlays a pending change on a single template lookup.
	 *
	 * @param WP_Block_Template|null $template Template.
	 * @return WP_Block_Template|null
	 */
	public static function overlay_template( $template ) {
		if ( ! $template instanceof WP_Block_Template || ! self::blocks_apply() ) {
			return $template;
		}
		$draft = self::active();
		return empty( $draft['templates'] ) ? $template : self::apply_entry( $template, $draft );
	}

	/**
	 * Overlays a pending update on a template read straight from the theme file.
	 *
	 * This is how a template part that has never been customised is rendered. A
	 * pending reset is deliberately left alone: the theme file is the reset target.
	 *
	 * @param WP_Block_Template|null $template Template.
	 * @return WP_Block_Template|null
	 */
	public static function overlay_file_template( $template ) {
		if ( ! $template instanceof WP_Block_Template || ! self::blocks_apply() ) {
			return $template;
		}
		$draft = self::active();
		$entry = Theme_Draft::template_entry( $draft, $template->type, $template->slug );
		if ( null === $entry || 'update' !== $entry['action'] || (string) $template->theme !== (string) $draft['stylesheet'] ) {
			return $template;
		}
		$clone          = clone $template;
		$clone->content = (string) $entry['content'];
		return $clone;
	}

	/**
	 * Overlays a pending change on a customised template part as it renders.
	 *
	 * The template-part block does not ask the template API for its content; it
	 * queries the database directly and falls back to the theme file. So a pending
	 * update replaces the queried post's content, and a pending reset hides the
	 * post so the block falls through to the theme file — exactly what publishing
	 * the reset will do.
	 *
	 * @param WP_Post[] $posts Posts.
	 * @param WP_Query  $query Query.
	 * @return WP_Post[]
	 */
	public static function overlay_part_posts( $posts, $query ) {
		if ( ! is_array( $posts ) || ! $query instanceof WP_Query || 'wp_template_part' !== $query->get( 'post_type' ) ) {
			return $posts;
		}
		if ( ! self::blocks_apply() ) {
			return $posts;
		}

		$slugs = (array) $query->get( 'post_name__in' );
		if ( 1 !== count( $slugs ) ) {
			return $posts;
		}

		$draft = self::active();
		$entry = Theme_Draft::template_entry( $draft, 'wp_template_part', (string) reset( $slugs ) );
		if ( null === $entry ) {
			return $posts;
		}

		if ( 'reset' === $entry['action'] ) {
			return array();
		}

		foreach ( $posts as $index => $post ) {
			if ( $post instanceof WP_Post ) {
				$clone               = new WP_Post( (object) get_object_vars( $post ) );
				$clone->post_content = (string) $entry['content'];
				$posts[ $index ]     = $clone;
			}
		}

		return $posts;
	}

	/**
	 * Replaces the site's user global styles with the draft's.
	 *
	 * @param \WP_Theme_JSON_Data $theme_json User styles.
	 * @return \WP_Theme_JSON_Data
	 */
	public static function overlay_styles( $theme_json ) {
		if ( ! self::blocks_apply() || ! class_exists( '\WP_Theme_JSON_Data' ) || ! class_exists( '\WP_Theme_JSON' ) ) {
			return $theme_json;
		}
		$draft = self::active();
		if ( empty( $draft['styles'] ) ) {
			return $theme_json;
		}

		$data            = isset( $draft['styles']['user_data'] ) && is_array( $draft['styles']['user_data'] ) ? $draft['styles']['user_data'] : array();
		$data['version'] = \WP_Theme_JSON::LATEST_SCHEMA;

		return new \WP_Theme_JSON_Data( $data, 'custom' );
	}

	/**
	 * Renders a pending synced-pattern update wherever the pattern appears.
	 *
	 * @param string|null          $pre_render Short-circuited output, or null.
	 * @param array<string, mixed> $parsed     Parsed block.
	 * @return string|null
	 */
	public static function overlay_synced_pattern( $pre_render, $parsed ) {
		if ( null !== $pre_render || ! is_array( $parsed ) || empty( $parsed['blockName'] ) || 'core/block' !== $parsed['blockName'] ) {
			return $pre_render;
		}
		if ( ! self::blocks_apply() ) {
			return $pre_render;
		}

		$draft = self::active();
		$ref   = isset( $parsed['attrs']['ref'] ) ? absint( $parsed['attrs']['ref'] ) : 0;
		$uuid  = isset( $parsed['attrs'][ Theme_Draft::PATTERN_REF_ATTR ] ) ? (string) $parsed['attrs'][ Theme_Draft::PATTERN_REF_ATTR ] : '';

		if ( '' !== $uuid ) {
			// A pattern created in this draft, placed before it has an ID.
			$entry = is_array( $draft ) && isset( $draft['patterns'][ 'new:' . $uuid ] ) ? $draft['patterns'][ 'new:' . $uuid ] : null;
		} else {
			$entry = Theme_Draft::pattern_entry( $draft, $ref );
			if ( null !== $entry && 'update' !== $entry['action'] ) {
				$entry = null;
			}
		}
		if ( null === $entry ) {
			return $pre_render;
		}

		// A pattern that contains itself would recurse forever.
		if ( self::$pattern_depth > 5 ) {
			return '';
		}

		++self::$pattern_depth;
		$html = do_blocks( (string) $entry['content'] );
		--self::$pattern_depth;

		return $html;
	}

	/**
	 * Registers the theme's patterns from the draft copy instead of the live folder.
	 *
	 * Core reads pattern files through the WP_Theme object, whose paths ignore the
	 * directory filters the preview relies on. Without this a draft that edits,
	 * adds or deletes files in patterns/ would preview (and be health-checked)
	 * with the live patterns. The draft copy is parsed with core's own reader, so
	 * headers are handled exactly as core handles them.
	 *
	 * @return void
	 */
	public static function overlay_file_patterns() {
		$draft = self::active();
		if ( false === $draft || ! Theme_Draft::touches_files( $draft ) || ! class_exists( '\WP_Block_Patterns_Registry' ) ) {
			return;
		}
		$workspace = Theme_Draft::workspace_path( $draft );
		if ( '' === $workspace ) {
			return;
		}

		$registry = \WP_Block_Patterns_Registry::get_instance();
		$live     = wp_get_theme( Theme_Support::stylesheet() );

		// Drop what core registered from the live folder...
		foreach ( (array) $live->get_block_patterns() as $data ) {
			if ( ! empty( $data['slug'] ) && $registry->is_registered( $data['slug'] ) ) {
				$registry->unregister( $data['slug'] );
			}
		}

		// ...and register the draft copy's instead. Its pattern cache is thrown away
		// straight after, so a later edit to the draft is never read stale.
		$copy     = new \WP_Theme( basename( $workspace ), dirname( $workspace ) );
		$patterns = (array) $copy->get_block_patterns();
		$copy->delete_pattern_cache();

		$dirpath     = trailingslashit( $workspace ) . 'patterns/';
		$text_domain = $live->get( 'TextDomain' );
		foreach ( $patterns as $file => $data ) {
			if ( empty( $data['slug'] ) || $registry->is_registered( $data['slug'] ) || ! file_exists( $dirpath . $file ) ) {
				continue;
			}
			$data['filePath'] = $dirpath . $file;
			// phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText,WordPress.WP.I18n.NonSingularStringLiteralDomain,WordPress.WP.I18n.LowLevelTranslationFunction -- Same translation core applies to theme patterns.
			$data['title'] = translate_with_gettext_context( $data['title'], 'Pattern title', $text_domain );
			if ( ! empty( $data['description'] ) ) {
				// phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText,WordPress.WP.I18n.NonSingularStringLiteralDomain,WordPress.WP.I18n.LowLevelTranslationFunction -- Same translation core applies to theme patterns.
				$data['description'] = translate_with_gettext_context( $data['description'], 'Pattern description', $text_domain );
			}
			register_block_pattern( $data['slug'], $data );
		}
	}

	/*
	 * Admin bar
	 */

	/**
	 * Tells the administrator they are looking at a draft, with a way out.
	 *
	 * @param \WP_Admin_Bar $bar Admin bar.
	 * @return void
	 */
	public static function admin_bar( $bar ) {
		$draft = self::active();
		if ( false === $draft || ! $bar instanceof \WP_Admin_Bar ) {
			return;
		}

		$count = Theme_Draft::change_count( $draft );

		$bar->add_node(
			array(
				'id'    => 'mosmcp-theme-preview',
				'title' => esc_html(
					sprintf(
						/* translators: %d: number of pending changes. */
						_n( 'Previewing theme draft: %d change', 'Previewing theme draft: %d changes', $count, 'mosmcp-abilities' ),
						$count
					)
				),
				'href'  => false,
				'meta'  => array( 'class' => 'mosmcp-theme-preview' ),
			)
		);

		$lines = array(
			'heading' => __( 'Draft mode', 'mosmcp-abilities' ),
			'note'    => __( 'Only you can see this. Visitors see the live site.', 'mosmcp-abilities' ),
			/* translators: the quoted phrase is what to type to the AI. */
			'publish' => __( 'To make it live, ask your AI: "Publish the theme draft"', 'mosmcp-abilities' ),
			/* translators: the quoted phrase is what to type to the AI. */
			'discard' => __( 'To throw it away, ask your AI: "Discard the theme draft"', 'mosmcp-abilities' ),
		);
		foreach ( $lines as $key => $text ) {
			$bar->add_node(
				array(
					'parent' => 'mosmcp-theme-preview',
					'id'     => 'mosmcp-theme-preview-' . $key,
					'title'  => esc_html( $text ),
				)
			);
		}

		$bar->add_node(
			array(
				'parent' => 'mosmcp-theme-preview',
				'id'     => 'mosmcp-theme-preview-exit',
				'title'  => esc_html__( 'Exit preview', 'mosmcp-abilities' ),
				'href'   => esc_url( add_query_arg( self::EXIT_VAR, '1' ) ),
			)
		);
	}

	/**
	 * Makes the preview notice impossible to miss.
	 *
	 * @return void
	 */
	public static function admin_bar_style() {
		if ( false === self::active() || ! is_admin_bar_showing() ) {
			return;
		}
		// Information reads as plain text; the one action (exit) reads as a button.
		$menu = '#wpadminbar #wp-admin-bar-mosmcp-theme-preview';
		wp_add_inline_style(
			'admin-bar',
			'#wpadminbar .mosmcp-theme-preview > .ab-item{background:#b45309;color:#fff;font-weight:600}'
			. $menu . ' .ab-sub-wrapper{min-width:320px}'
			. $menu . ' .ab-submenu .ab-empty-item{height:auto;padding:3px 12px;line-height:1.5;white-space:normal;font-size:12px;color:#c3c4c7;cursor:default}'
			. $menu . ' #wp-admin-bar-mosmcp-theme-preview-heading .ab-empty-item{color:#fff;font-weight:600;font-size:13px;padding-top:8px}'
			. $menu . ' #wp-admin-bar-mosmcp-theme-preview-publish .ab-empty-item{color:#dcdcde;padding-top:8px}'
			. $menu . ' #wp-admin-bar-mosmcp-theme-preview-discard .ab-empty-item{color:#dcdcde}'
			. $menu . ' #wp-admin-bar-mosmcp-theme-preview-exit{margin-top:6px;padding-top:8px;border-top:1px solid rgba(255,255,255,.15)}'
			. $menu . ' #wp-admin-bar-mosmcp-theme-preview-exit > .ab-item{display:block;height:auto;margin:0 12px 8px;padding:5px 10px;line-height:1.6;background:#b45309;color:#fff;font-weight:600;text-align:center;border-radius:3px}'
			. $menu . ' #wp-admin-bar-mosmcp-theme-preview-exit > .ab-item:hover,' . $menu . ' #wp-admin-bar-mosmcp-theme-preview-exit > .ab-item:focus{background:#92400e;color:#fff}'
		);
	}
}
