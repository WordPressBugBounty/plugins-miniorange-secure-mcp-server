<?php
/**
 * Execute callbacks for the Site Settings ability pack.
 *
 * Every write reads the stored value back after saving and reports what is
 * actually there. update_option() returns false both when a write fails and when
 * the value was already correct, so trusting its return value is how an ability
 * ends up reporting a change that never happened.
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
 * Class Site_Settings_Provider
 *
 * Static execute callbacks for the site settings abilities.
 */
class Site_Settings_Provider {

	/**
	 * The tagline WordPress ships with, used to spot a site nobody has configured.
	 */
	const DEFAULT_TAGLINE = 'Just another WordPress site';

	/**
	 * Returns the site title.
	 *
	 * @param array<string, mixed> $input Ability input. Unused.
	 * @return array<string, mixed>
	 */
	public static function get_title( $input = array() ) {
		unset( $input );

		return array( 'title' => (string) get_option( 'blogname', '' ) );
	}

	/**
	 * Returns the site tagline.
	 *
	 * @param array<string, mixed> $input Ability input. Unused.
	 * @return array<string, mixed>
	 */
	public static function get_tagline( $input = array() ) {
		unset( $input );

		$tagline = (string) get_option( 'blogdescription', '' );

		return array(
			'tagline'    => $tagline,
			'is_default' => ( self::DEFAULT_TAGLINE === $tagline ),
		);
	}

	/**
	 * Returns the site addresses.
	 *
	 * @param array<string, mixed> $input Ability input. Unused.
	 * @return array<string, mixed>
	 */
	public static function get_url( $input = array() ) {
		unset( $input );

		$home  = home_url();
		$site  = site_url();
		$notes = array();

		if ( untrailingslashit( $home ) !== untrailingslashit( $site ) ) {
			$notes[] = __( 'The site address and the WordPress address differ, which means WordPress lives in a subdirectory. Build visitor-facing links from the site address, not the WordPress one.', 'mosmcp-abilities' );
		}

		$configured_scheme = (string) wp_parse_url( $home, PHP_URL_SCHEME );

		if ( 'https' === $configured_scheme && ! is_ssl() ) {
			$notes[] = __( 'The site is configured for HTTPS but this request did not arrive over it, which usually means a proxy or load balancer is not passing the protocol through. That can break logins and redirects.', 'mosmcp-abilities' );
		}

		return array(
			'home_url'     => $home,
			'site_url'     => $site,
			'admin_url'    => admin_url(),
			'scheme'       => $configured_scheme,
			'host'         => (string) wp_parse_url( $home, PHP_URL_HOST ),
			'is_ssl'       => is_ssl(),
			'in_subdir'    => untrailingslashit( $home ) !== untrailingslashit( $site ),
			'is_multisite' => is_multisite(),
			'notes'        => $notes,
		);
	}

	/**
	 * Returns the site timezone and current local time.
	 *
	 * @param array<string, mixed> $input Ability input. Unused.
	 * @return array<string, mixed>
	 */
	public static function get_timezone( $input = array() ) {
		unset( $input );

		$timezone_string = (string) get_option( 'timezone_string', '' );
		$gmt_offset      = (string) get_option( 'gmt_offset', '0' );
		$manual          = ( '' === $timezone_string );
		$notes           = array();

		if ( $manual ) {
			$notes[] = __( 'This site uses a fixed offset from UTC rather than a named timezone, so it will not follow daylight saving changes. Scheduled posts drift by an hour twice a year unless someone updates the offset by hand.', 'mosmcp-abilities' );
		}

		return array(
			'timezone_string'    => $timezone_string,
			'gmt_offset'         => $gmt_offset,
			'uses_manual_offset' => $manual,
			'current_local_time' => (string) current_time( 'Y-m-d H:i:s' ),
			'current_utc_time'   => (string) gmdate( 'Y-m-d H:i:s' ),
			'notes'              => $notes,
		);
	}

	/**
	 * Returns the site language and formatting settings.
	 *
	 * @param array<string, mixed> $input Ability input. Unused.
	 * @return array<string, mixed>
	 */
	public static function get_language( $input = array() ) {
		unset( $input );

		$available = get_available_languages();
		$available = is_array( $available ) ? array_values( $available ) : array();

		// get_available_languages() lists installed translations only; the built-in
		// locale has no translation files, so it never appears there.
		if ( ! in_array( 'en_US', $available, true ) ) {
			array_unshift( $available, 'en_US' );
		}

		return array(
			'locale'              => (string) get_locale(),
			'available_languages' => $available,
			'is_rtl'              => (bool) is_rtl(),
			'date_format'         => (string) get_option( 'date_format', '' ),
			'time_format'         => (string) get_option( 'time_format', '' ),
			'start_of_week'       => (int) get_option( 'start_of_week', 1 ),
		);
	}

	/**
	 * Returns the permalink structure.
	 *
	 * @param array<string, mixed> $input Ability input. Unused.
	 * @return array<string, mixed>
	 */
	public static function get_permalink_structure( $input = array() ) {
		unset( $input );

		$structure = (string) get_option( 'permalink_structure', '' );
		$is_pretty = ( '' !== $structure );
		$notes     = array();

		if ( ! $is_pretty ) {
			$notes[] = __( 'This site uses plain query-string URLs, so a post or page slug does not appear in the visible address. Changing a slug here has no effect on the URL until pretty permalinks are switched on under Settings then Permalinks.', 'mosmcp-abilities' );
		}

		$sample = '';
		$recent = get_posts(
			array(
				'numberposts'      => 1,
				'post_status'      => 'publish',
				'suppress_filters' => false,
			)
		);

		if ( ! empty( $recent ) ) {
			$sample = (string) get_permalink( $recent[0] );
		}

		return array(
			'structure'     => $structure,
			'is_pretty'     => $is_pretty,
			'category_base' => (string) get_option( 'category_base', '' ),
			'tag_base'      => (string) get_option( 'tag_base', '' ),
			'sample_url'    => $sample,
			'notes'         => $notes,
		);
	}

	/**
	 * Returns the reading settings.
	 *
	 * @param array<string, mixed> $input Ability input. Unused.
	 * @return array<string, mixed>
	 */
	public static function get_posts_per_page( $input = array() ) {
		unset( $input );

		return array(
			'posts_per_page'  => (int) get_option( 'posts_per_page', 10 ),
			'posts_per_rss'   => (int) get_option( 'posts_per_rss', 10 ),
			'rss_use_excerpt' => (bool) get_option( 'rss_use_excerpt', false ),
		);
	}

	/**
	 * Returns the homepage settings.
	 *
	 * @param array<string, mixed> $input Ability input. Unused.
	 * @return array<string, mixed>
	 */
	public static function get_homepage( $input = array() ) {
		unset( $input );

		$state = self::homepage_state();
		$notes = array();

		if ( 'static_page' === $state['shows'] && 0 === $state['front_page']['id'] ) {
			$notes[] = __( 'The site is set to show a fixed page on the front, but no page has been chosen, so WordPress falls back to showing the latest posts.', 'mosmcp-abilities' );
		}

		if ( 'static_page' === $state['shows'] && 'missing' === $state['posts_page']['status'] ) {
			$notes[] = __( 'The page stored as the blog page no longer exists, so the blog has no home. Choose another with mosmcp/site-set-posts-page.', 'mosmcp-abilities' );
		}

		$state['notes'] = $notes;

		return $state;
	}

	/**
	 * Returns the privacy policy page setting.
	 *
	 * @param array<string, mixed> $input Ability input. Unused.
	 * @return array<string, mixed>
	 */
	public static function get_privacy_policy_page( $input = array() ) {
		unset( $input );

		$page_id = (int) get_option( 'wp_page_for_privacy_policy', 0 );
		$page    = Site_Support::describe_page( $page_id );
		$notes   = array();

		if ( $page_id > 0 && 'publish' !== $page['status'] && 'missing' !== $page['status'] ) {
			$notes[] = __( 'The privacy policy page is set but not published, so visitors cannot reach it.', 'mosmcp-abilities' );
		}

		if ( 'missing' === $page['status'] ) {
			$notes[] = __( 'The page stored as the privacy policy no longer exists.', 'mosmcp-abilities' );
		}

		return array(
			'is_set' => $page_id > 0,
			'page'   => $page,
			'notes'  => $notes,
		);
	}

	/**
	 * Returns whether the site discourages search engines.
	 *
	 * @param array<string, mixed> $input Ability input. Unused.
	 * @return array<string, mixed>
	 */
	public static function get_search_visibility( $input = array() ) {
		unset( $input );

		$discourage = ( '0' === (string) get_option( 'blog_public', '1' ) );
		$notes      = array();

		if ( $discourage ) {
			$notes[] = __( 'This site asks search engines not to index it. If someone is asking why their site does not appear in Google, this is almost always the reason. It is only a request, and not every crawler honours it.', 'mosmcp-abilities' );
		}

		return array(
			'discourage_search_engines' => $discourage,
			'notes'                     => $notes,
		);
	}

	/**
	 * Updates the site title.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function update_title( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$value = isset( $input['title'] ) ? sanitize_text_field( (string) $input['title'] ) : '';

		if ( '' === trim( $value ) ) {
			return Site_Support::error(
				'mosmcp_title_empty',
				__( 'The site title cannot be empty. Pass the new title as plain text.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_INVALID_INPUT,
				true
			);
		}

		return self::write_string_option( 'blogname', $value );
	}

	/**
	 * Updates the site tagline.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function update_tagline( $input = array() ) {
		$input = is_array( $input ) ? $input : array();

		if ( ! array_key_exists( 'tagline', $input ) ) {
			return Site_Support::error(
				'mosmcp_tagline_missing',
				__( 'No tagline was supplied. Pass the new tagline, or an empty string to clear it.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_INVALID_INPUT,
				true
			);
		}

		return self::write_string_option( 'blogdescription', sanitize_text_field( (string) $input['tagline'] ) );
	}

	/**
	 * Writes a plain-text option and reports what actually landed.
	 *
	 * @param string $option Option name.
	 * @param string $value  New value.
	 * @return array<string, mixed>
	 */
	private static function write_string_option( $option, $value ) {
		$previous = (string) get_option( $option, '' );
		$notes    = array();

		if ( $previous === $value ) {
			return array(
				'previous' => $previous,
				'current'  => $previous,
				'changed'  => false,
				'notes'    => array( __( 'The value was already what you asked for, so nothing was written.', 'mosmcp-abilities' ) ),
			);
		}

		update_option( $option, $value );

		// Read back rather than trusting the return value: update_option() answers
		// false both for a failed write and for a value that did not need changing.
		$current = (string) get_option( $option, '' );

		if ( $current !== $value ) {
			$notes[] = __( 'The stored value differs from what was sent, which means something on this site filtered it on the way in. The value shown as current is what is really stored.', 'mosmcp-abilities' );
		}

		return array(
			'previous' => $previous,
			'current'  => $current,
			'changed'  => ( $current !== $previous ),
			'notes'    => $notes,
		);
	}

	/**
	 * Updates the site timezone.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function update_timezone( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$raw   = isset( $input['timezone'] ) ? trim( (string) $input['timezone'] ) : '';

		if ( '' === $raw ) {
			return Site_Support::error(
				'mosmcp_timezone_empty',
				__( 'No timezone was supplied. Pass a named zone such as "Europe/London", or a fixed offset such as "+05:30".', 'mosmcp-abilities' ),
				Site_Support::CAUSE_INVALID_INPUT,
				true
			);
		}

		$previous = self::timezone_label();
		$notes    = array();

		if ( in_array( $raw, timezone_identifiers_list(), true ) ) {
			update_option( 'timezone_string', $raw );
			update_option( 'gmt_offset', '' );
		} else {
			$offset = self::parse_offset( $raw );

			if ( null === $offset ) {
				return Site_Support::error(
					'mosmcp_timezone_invalid',
					sprintf(
						/* translators: %s: the timezone value supplied by the caller. */
						__( '"%s" is not a timezone WordPress recognises. Use a named zone from the IANA list such as "Europe/London", "America/New_York", or "Asia/Kolkata", or a fixed offset such as "+05:30" or "-8". A city name on its own, or an abbreviation like "EST", will not work.', 'mosmcp-abilities' ),
						$raw
					),
					Site_Support::CAUSE_INVALID_INPUT,
					true
				);
			}

			update_option( 'gmt_offset', $offset );
			update_option( 'timezone_string', '' );

			$notes[] = __( 'A fixed offset was set rather than a named zone, so this site will not follow daylight saving changes automatically. A named zone is usually the better choice.', 'mosmcp-abilities' );
		}

		$current = self::timezone_label();

		return array(
			'previous'           => $previous,
			'current'            => $current,
			'changed'            => ( $current !== $previous ),
			'uses_manual_offset' => ( '' === (string) get_option( 'timezone_string', '' ) ),
			'current_local_time' => (string) current_time( 'Y-m-d H:i:s' ),
			'notes'              => $notes,
		);
	}

	/**
	 * Describes the current timezone as a single comparable string.
	 *
	 * @return string
	 */
	private static function timezone_label() {
		$timezone_string = (string) get_option( 'timezone_string', '' );

		if ( '' !== $timezone_string ) {
			return $timezone_string;
		}

		return 'UTC' . self::format_offset( (float) get_option( 'gmt_offset', 0 ) );
	}

	/**
	 * Formats a numeric UTC offset for display.
	 *
	 * @param float $offset Hours ahead of UTC.
	 * @return string
	 */
	private static function format_offset( $offset ) {
		$sign    = $offset < 0 ? '-' : '+';
		$offset  = abs( $offset );
		$hours   = (int) floor( $offset );
		$minutes = (int) round( ( $offset - $hours ) * 60 );

		return sprintf( '%s%d:%02d', $sign, $hours, $minutes );
	}

	/**
	 * Parses a fixed UTC offset supplied as text.
	 *
	 * Accepts the forms a person or a model actually writes: "+05:30", "-8",
	 * "UTC+5.5", "GMT-3".
	 *
	 * @param string $value Offset text.
	 * @return float|null Hours ahead of UTC, or null when unparseable.
	 */
	private static function parse_offset( $value ) {
		$value = strtoupper( trim( $value ) );
		$value = preg_replace( '/^(UTC|GMT)\s*/', '', $value );
		$value = (string) $value;

		if ( '' === $value ) {
			return 0.0;
		}

		if ( 1 === preg_match( '/^([+-]?)(\d{1,2}):([0-5]\d)$/', $value, $matches ) ) {
			$hours   = (int) $matches[2];
			$minutes = (int) $matches[3];
			$offset  = $hours + ( $minutes / 60 );

			return '-' === $matches[1] ? -$offset : $offset;
		}

		if ( 1 === preg_match( '/^[+-]?\d{1,2}(\.\d+)?$/', $value ) ) {
			$offset = (float) $value;

			if ( $offset >= -12 && $offset <= 14 ) {
				return $offset;
			}
		}

		return null;
	}

	/**
	 * Updates how many posts appear per page.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function update_posts_per_page( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$value = isset( $input['posts_per_page'] ) ? (int) $input['posts_per_page'] : 0;

		if ( $value < 1 || $value > 100 ) {
			return Site_Support::error(
				'mosmcp_posts_per_page_out_of_range',
				__( 'Posts per page must be between 1 and 100.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_INVALID_INPUT,
				true
			);
		}

		$previous = (int) get_option( 'posts_per_page', 10 );
		$notes    = array();

		if ( $previous === $value ) {
			return array(
				'previous' => $previous,
				'current'  => $previous,
				'changed'  => false,
				'notes'    => array( __( 'The value was already what you asked for, so nothing was written.', 'mosmcp-abilities' ) ),
			);
		}

		update_option( 'posts_per_page', $value );

		$current = (int) get_option( 'posts_per_page', 10 );

		if ( $value > 50 ) {
			$notes[] = __( 'Showing more than fifty posts on one page makes archive pages noticeably slower to load on most hosting.', 'mosmcp-abilities' );
		}

		return array(
			'previous' => $previous,
			'current'  => $current,
			'changed'  => ( $current !== $previous ),
			'notes'    => $notes,
		);
	}

	/**
	 * Sets what the front page shows.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function set_homepage( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$shows = isset( $input['shows'] ) ? (string) $input['shows'] : '';

		if ( ! in_array( $shows, array( 'latest_posts', 'static_page' ), true ) ) {
			return Site_Support::error(
				'mosmcp_homepage_mode_invalid',
				__( 'The shows value must be either "latest_posts" or "static_page".', 'mosmcp-abilities' ),
				Site_Support::CAUSE_INVALID_INPUT,
				true
			);
		}

		$previous = self::homepage_state();
		$notes    = array();

		if ( 'latest_posts' === $shows ) {
			update_option( 'show_on_front', 'posts' );

			$current = self::homepage_state();
			$notes[] = __( 'The front page now shows the latest posts. The pages previously used as the front page and blog page still exist and are unchanged.', 'mosmcp-abilities' );

			return self::homepage_result( $previous, $current, $notes );
		}

		$front_id = isset( $input['front_page_id'] ) ? absint( $input['front_page_id'] ) : 0;

		if ( $front_id < 1 ) {
			return Site_Support::error(
				'mosmcp_front_page_required',
				__( 'Setting a fixed front page needs front_page_id, the ID of the page to show. Use mosmcp/page-list-all to find it, or mosmcp/page-create-draft to make one.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_INVALID_INPUT,
				true,
				array( 'use_instead' => 'mosmcp/page-list-all' )
			);
		}

		$invalid = Site_Support::validate_page( $front_id, __( 'front page', 'mosmcp-abilities' ) );

		if ( $invalid instanceof WP_Error ) {
			return $invalid;
		}

		$posts_page_id = (int) get_option( 'page_for_posts', 0 );

		if ( $posts_page_id > 0 && $posts_page_id === $front_id ) {
			return Site_Support::error(
				'mosmcp_homepage_conflict',
				__( 'That page is already set as the blog page, and WordPress cannot use one page as both the front page and the blog page. Choose a different page, or clear the blog page first with mosmcp/site-set-posts-page.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_CONFLICT,
				true,
				array( 'use_instead' => 'mosmcp/site-set-posts-page' )
			);
		}

		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $front_id );

		$current = self::homepage_state();

		if ( 'publish' !== $current['front_page']['status'] ) {
			$notes[] = __( 'The page chosen as the front page is not published yet, so visitors will not see it until it is.', 'mosmcp-abilities' );
		}

		if ( 0 === $current['posts_page']['id'] ) {
			$notes[] = __( 'No blog page is set, so the site currently has nowhere listing its posts. Set one with mosmcp/site-set-posts-page if the site has a blog.', 'mosmcp-abilities' );
		}

		return self::homepage_result( $previous, $current, $notes );
	}

	/**
	 * Sets which page lists blog posts.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function set_posts_page( $input = array() ) {
		$input   = is_array( $input ) ? $input : array();
		$page_id = isset( $input['page_id'] ) ? absint( $input['page_id'] ) : 0;

		$previous = self::homepage_state();
		$notes    = array();

		if ( $page_id > 0 ) {
			$invalid = Site_Support::validate_page( $page_id, __( 'blog page', 'mosmcp-abilities' ) );

			if ( $invalid instanceof WP_Error ) {
				return $invalid;
			}

			$front_id = (int) get_option( 'page_on_front', 0 );

			if ( $front_id > 0 && $front_id === $page_id ) {
				return Site_Support::error(
					'mosmcp_posts_page_conflict',
					__( 'That page is already the front page, and WordPress cannot use one page as both the front page and the blog page. Choose a different page for the blog.', 'mosmcp-abilities' ),
					Site_Support::CAUSE_CONFLICT,
					true
				);
			}
		}

		update_option( 'page_for_posts', $page_id );

		$current = self::homepage_state();

		if ( 'static_page' !== $current['shows'] ) {
			$notes[] = __( 'This setting is stored but has no effect yet, because the front page is currently showing the latest posts. Call mosmcp/site-set-homepage with shows set to "static_page" for the blog page to take effect.', 'mosmcp-abilities' );
		}

		if ( $page_id > 0 && 'publish' !== $current['posts_page']['status'] ) {
			$notes[] = __( 'The page chosen as the blog page is not published yet, so visitors will not reach it until it is.', 'mosmcp-abilities' );
		}

		if ( 0 === $page_id ) {
			$notes[] = __( 'The blog page setting was cleared. The page itself still exists and was not deleted.', 'mosmcp-abilities' );
		}

		return self::homepage_result( $previous, $current, $notes );
	}

	/**
	 * Sets the privacy policy page.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function set_privacy_policy_page( $input = array() ) {
		$input   = is_array( $input ) ? $input : array();
		$page_id = isset( $input['page_id'] ) ? absint( $input['page_id'] ) : 0;

		$previous = Site_Support::describe_page( (int) get_option( 'wp_page_for_privacy_policy', 0 ) );
		$notes    = array();

		if ( $page_id > 0 ) {
			$invalid = Site_Support::validate_page( $page_id, __( 'privacy policy page', 'mosmcp-abilities' ) );

			if ( $invalid instanceof WP_Error ) {
				return $invalid;
			}
		}

		update_option( 'wp_page_for_privacy_policy', $page_id );

		$current = Site_Support::describe_page( (int) get_option( 'wp_page_for_privacy_policy', 0 ) );

		if ( $page_id > 0 && 'publish' !== $current['status'] ) {
			$notes[] = __( 'The page chosen as the privacy policy is not published, so visitors cannot reach it. Publish it for the setting to be meaningful.', 'mosmcp-abilities' );
		}

		if ( 0 === $page_id ) {
			$notes[] = __( 'The privacy policy setting was cleared. The page itself still exists and was not deleted.', 'mosmcp-abilities' );
		}

		return array(
			'previous' => $previous,
			'current'  => $current,
			'changed'  => ( $current['id'] !== $previous['id'] ),
			'notes'    => $notes,
		);
	}

	/**
	 * Reads the current homepage configuration.
	 *
	 * @return array<string, mixed>
	 */
	private static function homepage_state() {
		$shows = ( 'page' === (string) get_option( 'show_on_front', 'posts' ) ) ? 'static_page' : 'latest_posts';

		return array(
			'shows'      => $shows,
			'front_page' => Site_Support::describe_page( (int) get_option( 'page_on_front', 0 ) ),
			'posts_page' => Site_Support::describe_page( (int) get_option( 'page_for_posts', 0 ) ),
		);
	}

	/**
	 * Shapes the before-and-after result the homepage write abilities return.
	 *
	 * @param array<string, mixed> $previous State before the write.
	 * @param array<string, mixed> $current  State after the write.
	 * @param string[]             $notes    Notes to surface.
	 * @return array<string, mixed>
	 */
	private static function homepage_result( array $previous, array $current, array $notes ) {
		$changed = (
			$previous['shows'] !== $current['shows']
			|| $previous['front_page']['id'] !== $current['front_page']['id']
			|| $previous['posts_page']['id'] !== $current['posts_page']['id']
		);

		if ( ! $changed ) {
			$notes[] = __( 'Everything was already set this way, so nothing was written.', 'mosmcp-abilities' );
		}

		return array(
			'previous' => $previous,
			'current'  => $current,
			'changed'  => $changed,
			'notes'    => $notes,
		);
	}
}
