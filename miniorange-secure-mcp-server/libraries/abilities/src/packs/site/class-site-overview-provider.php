<?php
/**
 * Execute logic for the site overview ability.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Site;

use MoSMCP\Abilities\Config;
use MoSMCP\Abilities\Support\Cache_Purger;

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
 * Class Site_Overview_Provider
 */
class Site_Overview_Provider {

	/**
	 * Page builders, by plugin folder: name and the prefix of the abilities that edit them.
	 *
	 * @var array<string, array{0:string,1:string}>
	 */
	const BUILDER_PLUGINS = array(
		'elementor'                     => array( 'Elementor', 'mosmcp/elementor-' ),
		'elementor-pro'                 => array( 'Elementor Pro', 'mosmcp/elementor-pro-' ),
		'divi-builder'                  => array( 'Divi Builder', 'mosmcp/divi' ),
		'beaver-builder-lite-version'   => array( 'Beaver Builder', 'mosmcp/beaver-builder-' ),
		'bb-plugin'                     => array( 'Beaver Builder', 'mosmcp/beaver-builder-' ),
		'breakdance'                    => array( 'Breakdance', 'mosmcp/breakdance-' ),
		'oxygen'                        => array( 'Oxygen', 'mosmcp/oxygen-' ),
		'js_composer'                   => array( 'WPBakery', 'mosmcp/wpbakery-' ),
		'kadence-blocks'                => array( 'Kadence Blocks', 'mosmcp/kadence-' ),
		'ultimate-addons-for-gutenberg' => array( 'Spectra', '' ),
		'generateblocks'                => array( 'GenerateBlocks', '' ),
		'coming-soon'                   => array( 'SeedProd', '' ),
	);

	/**
	 * Builders that add their own blocks to the block editor, rather than storing the
	 * layout outside the post. Their pages are edited with the block abilities.
	 *
	 * @var string[]
	 */
	const BLOCK_BASED = array( 'Kadence Blocks', 'Spectra', 'GenerateBlocks' );

	/**
	 * Page builders that come as a theme, by theme folder.
	 *
	 * @var array<string, array{0:string,1:string}>
	 */
	const BUILDER_THEMES = array(
		'divi'   => array( 'Divi', 'mosmcp/divi' ),
		'extra'  => array( 'Divi (Extra theme)', 'mosmcp/divi' ),
		'bricks' => array( 'Bricks', 'mosmcp/bricks-' ),
		'avada'  => array( 'Avada', 'mosmcp/avada-' ),
	);

	/**
	 * Notable integrations, by plugin folder: name and kind.
	 *
	 * @var array<string, array{0:string,1:string}>
	 */
	const INTEGRATIONS = array(
		'woocommerce'                 => array( 'WooCommerce', 'shop' ),
		'easy-digital-downloads'      => array( 'Easy Digital Downloads', 'shop' ),
		'wordpress-seo'               => array( 'Yoast SEO', 'seo' ),
		'wordpress-seo-premium'       => array( 'Yoast SEO Premium', 'seo' ),
		'seo-by-rank-math'            => array( 'Rank Math', 'seo' ),
		'seo-by-rank-math-pro'        => array( 'Rank Math Pro', 'seo' ),
		'all-in-one-seo-pack'         => array( 'All in One SEO', 'seo' ),
		'wp-seopress'                 => array( 'SEOPress', 'seo' ),
		'redirection'                 => array( 'Redirection', 'seo' ),
		'advanced-custom-fields'      => array( 'Advanced Custom Fields', 'fields' ),
		'advanced-custom-fields-pro'  => array( 'ACF Pro', 'fields' ),
		'meta-box'                    => array( 'Meta Box', 'fields' ),
		'jet-engine'                  => array( 'JetEngine', 'fields' ),
		'contact-form-7'              => array( 'Contact Form 7', 'forms' ),
		'wpforms-lite'                => array( 'WPForms', 'forms' ),
		'wpforms'                     => array( 'WPForms', 'forms' ),
		'gravityforms'                => array( 'Gravity Forms', 'forms' ),
		'fluentform'                  => array( 'Fluent Forms', 'forms' ),
		'formidable'                  => array( 'Formidable Forms', 'forms' ),
		'ninja-forms'                 => array( 'Ninja Forms', 'forms' ),
		'forminator'                  => array( 'Forminator', 'forms' ),
		'sitepress-multilingual-cms'  => array( 'WPML', 'languages' ),
		'polylang'                    => array( 'Polylang', 'languages' ),
		'polylang-pro'                => array( 'Polylang Pro', 'languages' ),
		'translatepress-multilingual' => array( 'TranslatePress', 'languages' ),
		'the-events-calendar'         => array( 'The Events Calendar', 'events' ),
		'sfwd-lms'                    => array( 'LearnDash', 'learning' ),
		'lifterlms'                   => array( 'LifterLMS', 'learning' ),
		'tutor'                       => array( 'Tutor LMS', 'learning' ),
		'memberpress'                 => array( 'MemberPress', 'membership' ),
		'paid-memberships-pro'        => array( 'Paid Memberships Pro', 'membership' ),
		'fluent-crm'                  => array( 'FluentCRM', 'email' ),
		'mailpoet'                    => array( 'MailPoet', 'email' ),
		'updraftplus'                 => array( 'UpdraftPlus', 'backup' ),
		'wordfence'                   => array( 'Wordfence', 'security' ),
		'jetpack'                     => array( 'Jetpack', 'suite' ),
		'code-snippets'               => array( 'Code Snippets', 'code' ),
		'insert-headers-and-footers'  => array( 'WPCode', 'code' ),
	);

	/**
	 * Builds the overview.
	 *
	 * @param array<string, mixed> $input Ability input. Unused.
	 * @return array<string, mixed>
	 */
	public static function overview( $input = array() ) {
		unset( $input );

		$folders  = self::active_plugin_folders();
		$theme    = wp_get_theme();
		$template = strtolower( (string) $theme->get_template() );
		$access   = self::access();

		$builders = array();
		foreach ( self::BUILDER_PLUGINS as $folder => $builder ) {
			if ( isset( $folders[ $folder ] ) ) {
				$builders[ $builder[0] ] = self::builder_row( $builder, $access );
			}
		}
		if ( isset( self::BUILDER_THEMES[ $template ] ) ) {
			$builders[ self::BUILDER_THEMES[ $template ][0] ] = self::builder_row( self::BUILDER_THEMES[ $template ], $access );
		}

		$integrations = array();
		foreach ( self::INTEGRATIONS as $folder => $integration ) {
			if ( isset( $folders[ $folder ] ) ) {
				$integrations[ $integration[0] ] = array(
					'name' => $integration[0],
					'kind' => $integration[1],
				);
			}
		}

		$caches = array_keys( Cache_Purger::engines( false ) );

		return array(
			'site'               => self::site(),
			'theme'              => array(
				'name'           => (string) $theme->get( 'Name' ),
				'stylesheet'     => (string) $theme->get_stylesheet(),
				'parent'         => $theme->parent() ? (string) $theme->get_template() : '',
				'version'        => (string) $theme->get( 'Version' ),
				'is_block_theme' => (bool) wp_is_block_theme(),
				'has_menus'      => ! empty( get_registered_nav_menus() ),
				'has_widgets'    => ! empty( $GLOBALS['wp_registered_sidebars'] ),
			),
			'builders'           => array_values( $builders ),
			'integrations'       => array_values( $integrations ),
			'caches'             => array_map( 'strval', $caches ),
			'content'            => self::content( isset( $integrations['WooCommerce'] ) ),
			'ability_categories' => self::ability_categories( $access ),
			'recommendations'    => self::recommendations( array_values( $builders ), $integrations, $caches ),
		);
	}

	/**
	 * Basic facts about the site.
	 *
	 * @return array<string, mixed>
	 */
	private static function site() {
		$front = get_option( 'show_on_front' );
		$page  = 'page' === $front ? get_post( (int) get_option( 'page_on_front' ) ) : null;

		return array(
			'name'                => (string) get_bloginfo( 'name' ),
			'url'                 => (string) home_url( '/' ),
			'language'            => (string) get_locale(),
			'timezone'            => (string) wp_timezone_string(),
			'wordpress_version'   => (string) get_bloginfo( 'version' ),
			'multisite'           => is_multisite(),
			'search_engines'      => '1' === (string) get_option( 'blog_public' ),
			'permalink_structure' => (string) get_option( 'permalink_structure' ),
			'homepage'            => $page ? (string) get_the_title( $page ) : __( 'latest posts', 'mosmcp-abilities' ),
		);
	}

	/**
	 * Content counts.
	 *
	 * @param bool $shop Whether WooCommerce is active.
	 * @return array<string, int>
	 */
	private static function content( $shop ) {
		$posts = wp_count_posts( 'post' );
		$pages = wp_count_posts( 'page' );

		return array(
			'posts_published'  => isset( $posts->publish ) ? (int) $posts->publish : 0,
			'posts_draft'      => isset( $posts->draft ) ? (int) $posts->draft : 0,
			'pages_published'  => isset( $pages->publish ) ? (int) $pages->publish : 0,
			'pages_draft'      => isset( $pages->draft ) ? (int) $pages->draft : 0,
			'products'         => $shop && post_type_exists( 'product' ) ? (int) wp_count_posts( 'product' )->publish : 0,
			'menus'            => count( wp_get_nav_menus() ),
			'navigation_menus' => post_type_exists( 'wp_navigation' ) ? (int) wp_count_posts( 'wp_navigation' )->publish : 0,
		);
	}

	/**
	 * The abilities the current connection may use, as a set, or null when unknown.
	 *
	 * @return array<string, int>|null
	 */
	private static function access() {
		/**
		 * Filters which abilities the current connection may use, for the overview.
		 *
		 * A host that restricts abilities per connection (by role, agent or client)
		 * returns the names here; null means the host does not say, and the overview
		 * then reports availability as unknown.
		 *
		 * @param string[]|null $names Ability names the current connection may use.
		 */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Prefixed via Config::prefix(); the library's prefix is host-configurable and cannot be a literal.
		$access = apply_filters( Config::prefix() . '_overview_ability_access', null );

		return is_array( $access ) ? array_flip( array_map( 'strval', $access ) ) : null;
	}

	/**
	 * Every ability group, with how many abilities it has and how many this connection may use.
	 *
	 * @param array<string, int>|null $access Abilities the connection may use, or null when unknown.
	 * @return array<int, array<string, mixed>>
	 */
	private static function ability_categories( $access ) {
		$abilities = function_exists( 'wp_get_abilities' ) ? wp_get_abilities() : array();

		$labels = array();
		if ( function_exists( 'wp_get_ability_categories' ) ) {
			foreach ( wp_get_ability_categories() as $category ) {
				if ( is_object( $category ) && method_exists( $category, 'get_slug' ) ) {
					$labels[ (string) $category->get_slug() ] = (string) $category->get_label();
				}
			}
		}

		$rows = array();
		foreach ( $abilities as $ability ) {
			$slug = (string) $ability->get_category();
			if ( ! isset( $rows[ $slug ] ) ) {
				$rows[ $slug ] = array(
					'slug'       => $slug,
					'label'      => isset( $labels[ $slug ] ) ? $labels[ $slug ] : $slug,
					'registered' => 0,
					'available'  => null === $access ? -1 : 0,
				);
			}
			++$rows[ $slug ]['registered'];
			if ( null !== $access && isset( $access[ (string) $ability->get_name() ] ) ) {
				++$rows[ $slug ]['available'];
			}
		}

		ksort( $rows );

		return array_values( $rows );
	}

	/**
	 * Advice specific to this site.
	 *
	 * @param array<int, array<string, string>>    $builders     Builders in use.
	 * @param array<string, array<string, string>> $integrations Integrations in use.
	 * @param string[]                             $caches       Caches detected.
	 * @return string[]
	 */
	private static function recommendations( array $builders, array $integrations, array $caches ) {
		$notes = array();

		foreach ( $builders as $builder ) {
			$notes[] = self::builder_note( $builder );
		}

		if ( wp_is_block_theme() ) {
			$notes[] = __( 'This is a block theme: headers, footers and page layouts are templates (mosmcp/theme-template-*, mosmcp/theme-part-*), and menus are Navigation blocks (mosmcp/navigation-*).', 'mosmcp-abilities' );
		} else {
			$notes[] = __( 'This is a classic theme: menus are edited with mosmcp/menu-* and sidebar or footer widgets with mosmcp/widget-*.', 'mosmcp-abilities' );
		}

		$notes[] = __( 'For content made in the block editor, change one block at a time with mosmcp/block-* (list the blocks first) rather than rewriting the whole page.', 'mosmcp-abilities' );

		if ( isset( $integrations['WooCommerce'] ) ) {
			$notes[] = __( 'WooCommerce is active: products, orders, customers and coupons have their own abilities; edit shop data with those rather than as plain posts.', 'mosmcp-abilities' );
		}

		if ( $caches ) {
			$notes[] = sprintf(
				/* translators: %s: comma-separated cache names. */
				__( 'Caches detected (%s) are cleared automatically after each change, so changes show straight away.', 'mosmcp-abilities' ),
				implode( ', ', $caches )
			);
		}

		return $notes;
	}

	/**
	 * What to tell the AI about one builder.
	 *
	 * @param array<string, mixed> $builder Builder row.
	 * @return string
	 */
	private static function builder_note( array $builder ) {
		$name    = (string) $builder['name'];
		$prefix  = (string) $builder['abilities'];
		$enabled = ! empty( $builder['abilities_enabled'] );
		$off     = __( 'They exist on this site but are not switched on for this connection: tell the person a site administrator can enable them for their role under AI Agents, then the agent\'s Access tab.', 'mosmcp-abilities' );

		if ( ! empty( $builder['edits_with_blocks'] ) ) {
			if ( '' === $prefix ) {
				/* translators: %s: builder name. */
				return sprintf( __( '%s adds its own blocks to the block editor, so its pages are edited with the mosmcp/block-* abilities like any other block content.', 'mosmcp-abilities' ), $name );
			}
			/* translators: 1: builder name, 2: ability prefix. */
			$note = sprintf( __( '%1$s adds its own blocks to the block editor, so its pages are edited with the mosmcp/block-* abilities like any other block content; its own %2$s* abilities handle its blocks\' settings and layouts and keep its unique IDs and generated CSS correct.', 'mosmcp-abilities' ), $name, $prefix );
			return $enabled ? $note : $note . ' ' . $off;
		}

		if ( '' === $prefix ) {
			/* translators: %s: page builder name. */
			return sprintf( __( '%s is active, but there are no abilities for it on this site; pages built with it can be read, but edit them in its own editor. The block editor abilities refuse those pages, because the builder keeps the real layout elsewhere.', 'mosmcp-abilities' ), $name );
		}

		/* translators: 1: page builder name, 2: ability prefix. */
		$note = sprintf( __( 'Pages built with %1$s are edited with the %2$s* abilities. The block editor abilities refuse those pages, because the builder keeps the real layout elsewhere.', 'mosmcp-abilities' ), $name, $prefix );

		return $enabled ? $note : $note . ' ' . $off;
	}

	/**
	 * A builder row: whether abilities for it exist on this site, and whether this
	 * connection may use them.
	 *
	 * @param array{0:string,1:string} $builder Name and ability prefix.
	 * @param array<string, int>|null  $access  Abilities the connection may use, or null when unknown.
	 * @return array<string, mixed>
	 */
	private static function builder_row( array $builder, $access ) {
		$prefix  = (string) $builder[1];
		$enabled = false;

		if ( '' !== $prefix && function_exists( 'wp_get_abilities' ) ) {
			$found = false;
			foreach ( array_keys( wp_get_abilities() ) as $name ) {
				if ( 0 === strpos( (string) $name, $prefix ) ) {
					$found = true;
					if ( null === $access || isset( $access[ (string) $name ] ) ) {
						$enabled = true;
						break;
					}
				}
			}
			if ( ! $found ) {
				$prefix = '';
			}
		}

		return array(
			'name'              => (string) $builder[0],
			'abilities'         => $prefix,
			'abilities_enabled' => $enabled,
			'edits_with_blocks' => in_array( (string) $builder[0], self::BLOCK_BASED, true ),
		);
	}

	/**
	 * Folder names of active plugins (site and network), as a set.
	 *
	 * @return array<string, bool>
	 */
	private static function active_plugin_folders() {
		$active = (array) get_option( 'active_plugins', array() );

		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}

		$folders = array();
		foreach ( $active as $file ) {
			$dir = dirname( (string) $file );
			$folders[ '.' === $dir ? basename( (string) $file, '.php' ) : $dir ] = true;
		}

		return $folders;
	}
}
