<?php
/**
 * A short-lived sign-in for the requests this site makes to itself.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Theming;

use WP_Session_Tokens;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Loopback_Session
 *
 * The theme checks load the site's own pages, and the Customizer bridge posts to
 * its own wp-admin, as the person who asked. They run inside an API request that
 * has no browser cookies, so they sign those loopback requests in with a session
 * made for that same person, the way WordPress's file editor sends the person's
 * cookies with its own fatal-error check.
 *
 * The cookies carry that person's full WordPress rights, so they must never
 * leave the site. Everything that makes such a session goes through this class:
 * cookies() hands them out only for an address on this site's own host, the
 * callers never follow redirects, and end() deletes the session as soon as the
 * requests are done.
 */
class Loopback_Session {

	/**
	 * User the session belongs to.
	 *
	 * @var int
	 */
	private $user_id;

	/**
	 * Session token; null once the session has ended.
	 *
	 * @var string|null
	 */
	private $token;

	/**
	 * Sign-in cookies, by cookie name.
	 *
	 * @var array<string, string>
	 */
	private $cookies;

	/**
	 * Use start().
	 *
	 * @param int                   $user_id User ID.
	 * @param string                $token   Session token.
	 * @param array<string, string> $cookies Sign-in cookies.
	 */
	private function __construct( $user_id, $token, array $cookies ) {
		$this->user_id = $user_id;
		$this->token   = $token;
		$this->cookies = $cookies;
	}

	/**
	 * Starts a session for the current user.
	 *
	 * @param int $seconds Longest the session may live; end() removes it sooner.
	 * @return self|null Null when nobody is signed in.
	 */
	public static function start( $seconds ) {
		$user_id = get_current_user_id();
		if ( $user_id < 1 ) {
			return null;
		}

		$expiration = time() + (int) $seconds;
		$token      = WP_Session_Tokens::get_instance( $user_id )->create( $expiration );

		return new self(
			$user_id,
			$token,
			array(
				LOGGED_IN_COOKIE   => wp_generate_auth_cookie( $user_id, $expiration, 'logged_in', $token ),
				AUTH_COOKIE        => wp_generate_auth_cookie( $user_id, $expiration, 'auth', $token ),
				SECURE_AUTH_COOKIE => wp_generate_auth_cookie( $user_id, $expiration, 'secure_auth', $token ),
			)
		);
	}

	/**
	 * The sign-in cookies for a request: all of them for an address on this site,
	 * none for anywhere else or once the session has ended.
	 *
	 * @param string $url Address the request goes to.
	 * @return array<string, string>
	 */
	public function cookies( $url ) {
		return null !== $this->token && self::is_own_url( $url ) ? $this->cookies : array();
	}

	/**
	 * The logged-in cookie, for a nonce tied to this session.
	 *
	 * @return string
	 */
	public function logged_in_cookie() {
		return $this->cookies[ LOGGED_IN_COOKIE ];
	}

	/**
	 * Deletes the session. Safe to call more than once.
	 *
	 * @return void
	 */
	public function end() {
		if ( null === $this->token ) {
			return;
		}
		WP_Session_Tokens::get_instance( $this->user_id )->destroy( $this->token );
		$this->token = null;
	}

	/**
	 * Whether an address is on this site's own host, over http or https.
	 *
	 * The hosts of the home page, the WordPress address and wp-admin count as the
	 * site's own. A plugin can point a post's address at another website (Page
	 * Links To, for one), so pages found through permalinks are checked too.
	 *
	 * @param string $url Address.
	 * @return bool
	 */
	public static function is_own_url( $url ) {
		$scheme = strtolower( (string) wp_parse_url( (string) $url, PHP_URL_SCHEME ) );
		$host   = strtolower( (string) wp_parse_url( (string) $url, PHP_URL_HOST ) );
		if ( '' === $host || ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return false;
		}

		foreach ( array( home_url( '/' ), site_url( '/' ), admin_url( '/' ) ) as $own ) {
			if ( strtolower( (string) wp_parse_url( $own, PHP_URL_HOST ) ) === $host ) {
				return true;
			}
		}
		return false;
	}
}
