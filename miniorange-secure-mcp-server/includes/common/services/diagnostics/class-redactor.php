<?php
/**
 * Scrubs secrets from diagnostics before they reach the UI or the support bundle.
 *
 * @package Miniorange_Secure_MCP_Server
 */

namespace MoSMCP\Common\Services\Diagnostics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Redactor
 *
 * Three layers, because any single one can miss: exact known secrets (the minted test token),
 * secret-shaped keys, and secret-shaped text (bearer values, token-like runs, OAuth query params).
 */
class Redactor {

	const MASK = '[redacted]';

	/**
	 * Keys whose value is always masked, whatever it contains.
	 */
	const SECRET_KEYS = '/^(authorization|proxy-authorization|cookie|set-cookie|access_token|refresh_token|id_token|test_token|client_secret|password|code_verifier|auth_code|secret|nonce|x-wp-nonce|api_key|bearer)$/i';

	/**
	 * Masks secrets in a string.
	 *
	 * @param string   $text    The text.
	 * @param string[] $secrets Exact values to remove wherever they appear.
	 * @return string
	 */
	public static function redact_text( $text, array $secrets = array() ) {
		$text = (string) $text;

		foreach ( $secrets as $secret ) {
			if ( is_string( $secret ) && strlen( $secret ) >= 8 ) {
				$text = str_replace( $secret, self::MASK, $text );
			}
		}

		$patterns = array(
			'/(authorization\s*[:=]\s*)(?:bearer\s+|basic\s+)?[^\s"\',;]+/i'                     => '$1' . self::MASK,
			'/\b(bearer|basic)\s+[A-Za-z0-9._~+\/=-]{8,}/i'                                      => '$1 ' . self::MASK,
			'/([?&](?:code|access_token|refresh_token|id_token|token|client_secret|state|code_challenge|code_verifier)=)[^&\s"\']+/i' => '$1' . self::MASK,
			'/\b[A-Za-z0-9_-]{32,}\b/'                                                           => '[redacted-token]',
		);

		return (string) preg_replace( array_keys( $patterns ), array_values( $patterns ), $text );
	}

	/**
	 * Recursively masks secrets in an array, by key and by value.
	 *
	 * @param array    $data    The data.
	 * @param string[] $secrets Exact values to remove wherever they appear.
	 * @return array
	 */
	public static function redact_array( array $data, array $secrets = array() ) {
		$out = array();

		foreach ( $data as $key => $value ) {
			if ( is_string( $key ) && preg_match( self::SECRET_KEYS, $key ) ) {
				$out[ $key ] = self::MASK;
			} elseif ( is_array( $value ) ) {
				$out[ $key ] = self::redact_array( $value, $secrets );
			} elseif ( is_string( $value ) ) {
				$out[ $key ] = self::redact_text( $value, $secrets );
			} else {
				$out[ $key ] = $value;
			}
		}

		return $out;
	}
}
