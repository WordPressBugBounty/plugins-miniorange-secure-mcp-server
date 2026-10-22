<?php
/**
 * Cryptographic helpers: token generation, hashing, and PKCE verification.
 *
 * @package Miniorange_Secure_MCP_Server
 */

namespace MoSMCP\Common\Services\OAuth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Tokens
 *
 * Generates opaque secrets and hashes them for storage. Tokens, authorization
 * codes, and client secrets are kept only as keyed hashes so that a database
 * disclosure does not reveal usable credentials.
 */
class Tokens {

	/**
	 * Access-token lifetime, in seconds (1 hour).
	 */
	const ACCESS_TTL = 3600;

	/**
	 * Refresh-token lifetime, in seconds (14 days).
	 */
	const REFRESH_TTL = 1209600;

	/**
	 * Authorization-code lifetime, in seconds (5 minutes). Single-use and PKCE-bound;
	 * a longer window tolerates slow browser round-trips without weakening security
	 * (RFC 6749 permits up to 10 minutes).
	 */
	const CODE_TTL = 300;

	/**
	 * Token type stored in the `type` column for an access token.
	 */
	const TYPE_ACCESS = 'access';

	/**
	 * Token type stored in the `type` column for a refresh token.
	 */
	const TYPE_REFRESH = 'refresh';

	/**
	 * Token type for a refresh token that has just been spent. The row stays for
	 * {@see REFRESH_GRACE_TTL} seconds so a retry of the same request (the reply was lost) can be
	 * answered; the `type` column is varchar(10), so this must stay within 10 characters.
	 */
	const TYPE_ROTATED = 'rotated';

	/**
	 * How long a spent refresh token is still answered, in seconds. Refresh tokens are single-use,
	 * so a reply that never reaches the caller (a timeout, a restart between our answer and theirs)
	 * would otherwise leave the caller holding a dead token and force the user to reconnect. A retry
	 * inside this window gets a fresh pair; after it the token is rejected as before.
	 *
	 * Sized to outlast the broker's own timeout on this call (15 s) with the same again for the AI
	 * client's retry: the broker only learns the reply was lost when that timeout fires, and never
	 * retries a refresh itself. A window shorter than the timeout would close before the retry could
	 * arrive. Longer than this only lengthens the time a leaked, already-spent token stays usable.
	 */
	const REFRESH_GRACE_TTL = 30;

	/**
	 * Generates a cryptographically secure, URL-safe opaque secret.
	 *
	 * @return string A 43-character base64url string (256 bits of entropy).
	 */
	public static function generate() {
		return self::base64url_encode( random_bytes( 32 ) );
	}

	/**
	 * Computes the stored hash of an opaque secret.
	 *
	 * @param string $secret The plaintext secret.
	 * @return string Hex-encoded HMAC-SHA256 digest (64 characters).
	 */
	public static function hash( $secret ) {
		return hash_hmac( 'sha256', $secret, self::get_salt() );
	}

	/**
	 * Verifies a PKCE code verifier against a stored S256 challenge.
	 *
	 * @param string $verifier  The code verifier supplied at the token endpoint.
	 * @param string $challenge The code challenge stored with the authorization code.
	 * @return bool True when the verifier matches the challenge.
	 */
	public static function verify_pkce( $verifier, $challenge ) {
		if ( '' === $verifier || '' === $challenge ) {
			return false;
		}

		$computed = self::base64url_encode( hash( 'sha256', $verifier, true ) );

		return hash_equals( $challenge, $computed );
	}

	/**
	 * Returns the keyed-hash salt, generating and persisting it on first use.
	 *
	 * @return string The salt.
	 */
	public static function get_salt() {
		self::ensure_salt();

		$settings = get_option( 'mosmcp_settings', array() );

		return isset( $settings['salt'] ) ? (string) $settings['salt'] : '';
	}

	/**
	 * Dedicated option used only to atomically claim the salt on first use.
	 *
	 * `add_option()` is a single INSERT against `wp_options.option_name`, which
	 * carries a UNIQUE index — so under two concurrent first-ever requests, the
	 * database itself guarantees only one INSERT can succeed. Storing the salt
	 * directly under `mosmcp_settings['salt']` (a read-modify-write of a
	 * multi-key array option) cannot offer that guarantee: two requests can
	 * both read an empty salt, each generate a different value, and each call
	 * `update_option()`, silently orphaning whichever token was hashed with the
	 * value that lost the race. This option exists purely so the race has a
	 * single, database-enforced winner.
	 */
	const SALT_CLAIM_OPTION = 'mosmcp_salt';

	/**
	 * Ensures a hash salt exists in the plugin settings option, generating one
	 * exactly once even under concurrent first-use requests.
	 *
	 * @return void
	 */
	public static function ensure_salt() {
		$settings = get_option( 'mosmcp_settings', array() );

		if ( ! empty( $settings['salt'] ) ) {
			return;
		}

		$candidate = self::base64url_encode( random_bytes( 32 ) );

		// Atomic claim: succeeds only for the first process to reach this line;
		// every other concurrent caller gets false and must adopt the winner's value.
		if ( add_option( self::SALT_CLAIM_OPTION, $candidate, '', false ) ) {
			$salt = $candidate;
		} else {
			$salt = (string) get_option( self::SALT_CLAIM_OPTION, $candidate );
		}

		$settings['salt'] = $salt;
		update_option( 'mosmcp_settings', $settings, false );
	}

	/**
	 * Encodes data using URL-safe base64 without padding.
	 *
	 * @param string $data Raw binary data.
	 * @return string The base64url-encoded string.
	 */
	public static function base64url_encode( $data ) {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- URL-safe encoding of random bytes, not obfuscation.
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}
}
