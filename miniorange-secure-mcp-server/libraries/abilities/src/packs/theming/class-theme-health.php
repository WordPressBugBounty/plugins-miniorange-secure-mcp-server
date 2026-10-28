<?php
/**
 * Checks that the site still loads — with the draft applied, or live after a
 * publish — using WordPress's own fatal-error capture.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Theming;

use WP_Error;
use WP_Session_Tokens;

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
 * Class Theme_Health
 *
 * WordPress's Theme File Editor proves an edit is safe by requesting the site's
 * own pages with a one-time "scrape" key: wp-settings.php registers a shutdown
 * handler for such requests that prints any fatal error between markers. This
 * class drives that same mechanism, for two reasons core's editor cannot serve:
 *
 *  - it must test a draft, which is not the active theme, so core's editor would
 *    skip the check entirely;
 *  - it runs inside an API request that has no browser cookies, so it signs the
 *    loopback in with a short-lived session for the same user and removes that
 *    session afterwards.
 */
class Theme_Health {

	/**
	 * Seconds allowed for each page request.
	 */
	const TIMEOUT = 45;

	/**
	 * Requests wp-admin and the home page and reports whether either fatals.
	 *
	 * @param string $token Preview token to test the draft; empty to test the live site.
	 * @return array{ok:bool,conclusive:bool,target:string,checks:array<int, array<string, mixed>>,note:string}
	 */
	public static function run( $token = '' ) {
		$target   = '' === $token ? 'live' : 'draft';
		$loopback = Theme_Support::loopback();

		if ( $loopback instanceof WP_Error ) {
			return array(
				'ok'         => false,
				'conclusive' => false,
				'target'     => $target,
				'checks'     => array(),
				'note'       => $loopback->get_error_message(),
			);
		}

		$user_id = get_current_user_id();
		if ( $user_id < 1 ) {
			return array(
				'ok'         => false,
				'conclusive' => false,
				'target'     => $target,
				'checks'     => array(),
				'note'       => __( 'The check has to run as a signed-in user, and none is signed in.', 'mosmcp-abilities' ),
			);
		}

		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 5 * MINUTE_IN_SECONDS ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Two loopback requests must finish before this request does.
		}

		$expiration = time() + 5 * MINUTE_IN_SECONDS;
		$manager    = WP_Session_Tokens::get_instance( $user_id );
		$session    = $manager->create( $expiration );

		$cookies = array(
			LOGGED_IN_COOKIE   => wp_generate_auth_cookie( $user_id, $expiration, 'logged_in', $session ),
			AUTH_COOKIE        => wp_generate_auth_cookie( $user_id, $expiration, 'auth', $session ),
			SECURE_AUTH_COOKIE => wp_generate_auth_cookie( $user_id, $expiration, 'secure_auth', $session ),
		);
		if ( '' !== $token ) {
			$cookies[ Theme_Preview::COOKIE ] = $token;
		}

		$scrape_key   = md5( wp_generate_password( 32, false, false ) );
		$scrape_nonce = wp_generate_password( 32, false, false );
		set_transient( 'scrape_key_' . $scrape_key, $scrape_nonce, 2 * MINUTE_IN_SECONDS );

		$query = array(
			'wp_scrape_key'   => $scrape_key,
			'wp_scrape_nonce' => $scrape_nonce,
		);
		if ( '' !== $token ) {
			$query[ Theme_Preview::QUERY_VAR ] = $token;
		}

		// Each page as the signed-in administrator; on the live site also as a
		// visitor, since a draft cannot be shown to visitors before it is live.
		$as_visitor = ( '' === $token );
		$pages      = array();
		foreach ( self::pages() as $label => $url ) {
			$pages[ $label ] = array(
				'url'     => add_query_arg( $query, $url ),
				'cookies' => $cookies,
			);
			if ( $as_visitor && in_array( $label, array( 'home page', 'newest post' ), true ) ) {
				$pages[ $label . ' (logged out)' ] = array(
					'url'     => add_query_arg( $query, $url ),
					'cookies' => array(),
				);
			}
		}

		$checks = array();
		foreach ( self::fetch_all( $pages ) as $label => $response ) {
			$checks[] = self::interpret( $label, $response, $scrape_key );
		}

		delete_transient( 'scrape_key_' . $scrape_key );
		$manager->destroy( $session );

		$ok         = true;
		$conclusive = true;
		foreach ( $checks as $check ) {
			if ( 'fatal' === $check['status'] ) {
				$ok = false;
			}
			if ( 'unverified' === $check['status'] ) {
				$conclusive = false;
			}
		}

		return array(
			'ok'         => $ok && $conclusive,
			'conclusive' => $conclusive,
			'target'     => $target,
			'checks'     => $checks,
			'note'       => self::note(
				$ok,
				$conclusive,
				$target,
				array_unique(
					array_map(
						static function ( $check ) {
							return str_replace( ' (logged out)', '', $check['page'] ); },
						$checks
					)
				)
			),
		);
	}

	/**
	 * The pages a check loads: wp-admin, the home page, and the newest published
	 * post and page, so the templates and hooks for posts and pages are exercised
	 * as well as the home page. Other page types (404, archives, shop pages) are
	 * not loaded; the ability descriptions say so.
	 *
	 * @return array<string, string> Label => URL.
	 */
	private static function pages() {
		$pages = array(
			'wp-admin'  => admin_url( '/' ),
			'home page' => home_url( '/' ),
		);
		foreach ( array(
			'newest post' => 'post',
			'newest page' => 'page',
		) as $label => $type ) {
			$ids = get_posts(
				array(
					'post_type'    => $type,
					'post_status'  => 'publish',
					'has_password' => false,
					'numberposts'  => 1,
					'fields'       => 'ids',
					'orderby'      => 'date',
					'order'        => 'DESC',
				)
			);
			$url = empty( $ids ) ? '' : (string) get_permalink( (int) $ids[0] );
			// The front page is already covered by "home page".
			if ( '' !== $url && untrailingslashit( $url ) !== untrailingslashit( home_url( '/' ) ) ) {
				$pages[ $label ] = $url;
			}
		}
		return $pages;
	}

	/**
	 * Requests every page, in parallel where possible.
	 *
	 * A page stuck in a loop holds its request until PHP's time limit, so one
	 * after the other the check took that long per page. In parallel it takes it
	 * once, which keeps a check inside the time an AI client waits for a tool
	 * call. When anything intercepts outgoing requests (pre_http_request) or the
	 * library is missing, the pages are requested one by one through the
	 * WordPress HTTP API, so hosts that customise HTTP are unaffected.
	 *
	 * @param array<string, array{url:string,cookies:array<string,string>}> $pages Label => URL and cookies.
	 * @return array<string, array{code:int,body:string,location:string}|WP_Error> Label => response.
	 */
	private static function fetch_all( array $pages ) {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter.
		$verify = (bool) apply_filters( 'https_local_ssl_verify', false );

		if ( has_filter( 'pre_http_request' ) || ! class_exists( '\WpOrg\Requests\Requests' ) ) {
			$out = array();
			foreach ( $pages as $label => $page ) {
				$response      = wp_remote_get(
					$page['url'],
					array(
						'timeout'     => self::TIMEOUT,
						'redirection' => 0,
						'cookies'     => $page['cookies'],
						'sslverify'   => $verify,
						'headers'     => array( 'Cache-Control' => 'no-cache' ),
					)
				);
				$out[ $label ] = is_wp_error( $response ) ? $response : array(
					'code'     => (int) wp_remote_retrieve_response_code( $response ),
					'body'     => (string) wp_remote_retrieve_body( $response ),
					'location' => (string) wp_remote_retrieve_header( $response, 'location' ),
				);
			}
			return $out;
		}

		$options = array(
			'timeout'          => self::TIMEOUT,
			'connect_timeout'  => 10,
			'follow_redirects' => false,
			'useragent'        => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url( '/' ),
			'verify'           => $verify ? ABSPATH . WPINC . '/certificates/ca-bundle.crt' : false,
		);
		// Same rule as WP_Http: requests back to this site never go through a proxy.
		$proxy = new \WP_HTTP_Proxy();
		$first = reset( $pages );
		if ( $proxy->is_enabled() && $proxy->send_through_proxy( (string) $first['url'] ) ) {
			$options['proxy'] = new \WpOrg\Requests\Proxy\Http( array( $proxy->host() . ':' . $proxy->port(), $proxy->username(), $proxy->password() ) );
		}

		$requests = array();
		foreach ( $pages as $label => $page ) {
			$cookie_header = array();
			foreach ( $page['cookies'] as $name => $value ) {
				$cookie_header[] = ( new \WP_Http_Cookie(
					array(
						'name'  => $name,
						'value' => $value,
					)
				) )->getHeaderValue();
			}
			$headers = array( 'Cache-Control' => 'no-cache' );
			if ( ! empty( $cookie_header ) ) {
				$headers['Cookie'] = implode( '; ', $cookie_header );
			}
			$requests[ $label ] = array(
				'url'     => $page['url'],
				'type'    => \WpOrg\Requests\Requests::GET,
				'headers' => $headers,
			);
		}

		$responses = \WpOrg\Requests\Requests::request_multiple( $requests, $options );
		$out       = array();
		// Report pages in the order asked, not the order they happened to finish.
		foreach ( array_keys( $pages ) as $label ) {
			$response = isset( $responses[ $label ] ) ? $responses[ $label ] : null;
			if ( $response instanceof \WpOrg\Requests\Response ) {
				$out[ $label ] = array(
					'code'     => (int) $response->status_code,
					'body'     => (string) $response->body,
					'location' => isset( $response->headers['location'] ) ? (string) $response->headers['location'] : '',
				);
			} else {
				$out[ $label ] = new WP_Error( 'http_request_failed', $response instanceof \Exception ? $response->getMessage() : __( 'The request failed.', 'mosmcp-abilities' ) );
			}
		}
		return $out;
	}

	/**
	 * Reads one page's scrape result.
	 *
	 * @param string                                               $label      Human label for the page.
	 * @param array{code:int,body:string,location:string}|WP_Error $response   Fetched page.
	 * @param string                                               $scrape_key Scrape key.
	 * @return array<string, mixed>
	 */
	private static function interpret( $label, $response, $scrape_key ) {
		$check = array(
			'page'        => $label,
			'status'      => 'unverified',
			'http_status' => 0,
			'message'     => '',
			'file'        => '',
			'line'        => 0,
		);

		if ( is_wp_error( $response ) ) {
			$check['message'] = $response->get_error_message();
			return $check;
		}

		$code                 = (int) $response['code'];
		$body                 = (string) $response['body'];
		$check['http_status'] = $code;

		$start = "###### wp_scraping_result_start:$scrape_key ######";
		$end   = "###### wp_scraping_result_end:$scrape_key ######";
		$from  = strpos( $body, $start );

		if ( false !== $from ) {
			$chunk  = substr( $body, $from + strlen( $start ) );
			$to     = strpos( $chunk, $end );
			$result = json_decode( trim( false === $to ? $chunk : substr( $chunk, 0, $to ) ), true );

			if ( true === $result ) {
				// No fatal is not the same as working: an exit() or die() in a hook
				// leaves a 200 with nothing but the scrape report. A real page, even a
				// maintenance page, has an <html> or <body> tag.
				if ( $code >= 200 && $code < 300 && ! preg_match( '/<(?:html|body)[\s>]/i', substr( $body, 0, $from ) ) ) {
					$check['status']  = 'fatal';
					$check['message'] = __( 'The page came back empty: the request finished without an error but produced no page, which usually means code called exit or die before the theme rendered anything.', 'mosmcp-abilities' );
					return $check;
				}
				$check['status'] = 'ok';
				return $check;
			}

			if ( is_array( $result ) && isset( $result['message'] ) && ! isset( $result['code'] ) ) {
				$check['status']  = 'fatal';
				$check['file']    = self::readable_path( isset( $result['file'] ) ? (string) $result['file'] : '' );
				$check['line']    = isset( $result['line'] ) ? (int) $result['line'] : 0;
				$check['message'] = self::readable_message( wp_strip_all_tags( (string) $result['message'] ) );
				return $check;
			}

			$check['message'] = is_array( $result ) && isset( $result['message'] ) ? (string) $result['message'] : __( 'The page returned an unreadable result.', 'mosmcp-abilities' );
			return $check;
		}

		if ( $code >= 500 ) {
			$check['status']  = 'fatal';
			$check['message'] = sprintf(
				/* translators: %d: HTTP status code. */
				__( 'The page failed with HTTP %d.', 'mosmcp-abilities' ),
				$code
			);
			return $check;
		}

		if ( $code >= 300 && $code < 400 ) {
			$location         = (string) $response['location'];
			$check['message'] = false !== strpos( $location, 'wp-login.php' )
				? __( 'The request was sent to the login page, so it could not be checked signed in.', 'mosmcp-abilities' )
				: sprintf(
					/* translators: %s: redirect target. */
					__( 'The page redirected to %s instead of loading.', 'mosmcp-abilities' ),
					$location
				);
			return $check;
		}

		$check['message'] = __( 'The page loaded, but something on this site stripped WordPress\'s error report from it (often a caching or minification plugin), so a fatal error could not be ruled out.', 'mosmcp-abilities' );

		return $check;
	}

	/**
	 * A path inside the draft copy, shown as the theme file it stands for, so
	 * "wp-content/mosmcp-theme-drafts/Xy12…/twentytwentyfive/functions.php"
	 * reads as "functions.php".
	 *
	 * @param string $path Path as reported by PHP.
	 * @return string
	 */
	public static function readable_path( $path ) {
		$path = wp_normalize_path( (string) $path );
		if ( preg_match( '#(?:^|/)' . preg_quote( Theme_Draft::WORKSPACE_DIR, '#' ) . '/[^/]+/[^/]+/(.+)$#', $path, $m ) ) {
			return $m[1];
		}
		// The live copy of the active theme reads the same way.
		foreach ( array_unique( array( Theme_Support::stylesheet(), Theme_Support::template() ) ) as $slug ) {
			if ( preg_match( '#(?:^|/)themes/' . preg_quote( $slug, '#' ) . '/(.+)$#', $path, $m ) ) {
				return $m[1];
			}
		}
		return $path;
	}

	/**
	 * The error's first line, without the stack trace or the "in file:line" tail
	 * (file and line are reported separately), with draft paths made readable.
	 *
	 * @param string $message Error message.
	 * @return string
	 */
	public static function readable_message( $message ) {
		$message = (string) preg_replace( '/\s*Stack trace:.*$/s', '', (string) $message );
		$message = (string) preg_replace( '/ in \S+:\d+$/', '', trim( $message ) );
		return (string) preg_replace_callback(
			'#\S*' . preg_quote( Theme_Draft::WORKSPACE_DIR, '#' ) . '/\S+#',
			static function ( $found ) {
				return self::readable_path( $found[0] );
			},
			$message
		);
	}

	/**
	 * Summarizes a check for a person.
	 *
	 * @param bool     $ok         No fatal found.
	 * @param bool     $conclusive Every page reported back.
	 * @param string   $target     "draft" or "live".
	 * @param string[] $pages      Pages that were loaded.
	 * @return string
	 */
	private static function note( $ok, $conclusive, $target, array $pages ) {
		if ( ! $ok ) {
			return 'draft' === $target
				? __( 'The draft breaks the site: at least one page fails with a fatal error when the draft is applied. Fix the file named in the result before publishing.', 'mosmcp-abilities' )
				: __( 'The live site fails with a fatal error.', 'mosmcp-abilities' );
		}

		if ( ! $conclusive ) {
			return __( 'No fatal error was seen, but at least one page could not be checked properly, so the result is not conclusive. See each page for the reason.', 'mosmcp-abilities' );
		}

		$names = wp_sprintf( '%l', array_values( $pages ) );
		return 'draft' === $target
			? sprintf(
				/* translators: %s: comma-separated list of the pages checked. */
				__( 'The site loads normally with the draft applied: %s all render without a fatal error. Other page types (such as 404, archive or shop pages) were not loaded.', 'mosmcp-abilities' ),
				$names
			)
			: sprintf(
				/* translators: %s: comma-separated list of the pages checked. */
				__( 'The live site loads normally: %s all render without a fatal error, signed in and for visitors.', 'mosmcp-abilities' ),
				$names
			);
	}
}
