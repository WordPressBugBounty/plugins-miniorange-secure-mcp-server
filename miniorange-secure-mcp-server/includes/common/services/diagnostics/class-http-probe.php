<?php
/**
 * Runs the loopback requests of a connection test concurrently with short timeouts.
 *
 * @package Miniorange_Secure_MCP_Server
 */

namespace MoSMCP\Common\Services\Diagnostics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WpOrg\Requests\Requests;

/**
 * Class Http_Probe
 *
 * One request_multiple() call, so a hanging endpoint costs only its own timeout instead of
 * stalling every check queued behind it. Responses come back in the normalized shape that
 * {@see Response_Classifier} expects.
 */
class Http_Probe {

	/**
	 * Per-request timeout, in seconds.
	 */
	const TIMEOUT = 5;

	/**
	 * Largest body kept per response, in bytes. Enough to fingerprint a block page.
	 */
	const MAX_BYTES = 131072;

	/**
	 * Sends the requests concurrently.
	 *
	 * @param array<string, array{url:string, method?:string, headers?:array, body?:string|array}> $requests Requests keyed by name.
	 * @return array<string, array> Normalized responses keyed by the same names.
	 */
	public static function batch( array $requests ) {
		$prepared = array();
		foreach ( $requests as $key => $req ) {
			$prepared[ $key ] = array(
				'url'     => $req['url'],
				'type'    => isset( $req['method'] ) ? $req['method'] : 'GET',
				'headers' => array_merge( array( 'User-Agent' => self::user_agent() ), isset( $req['headers'] ) ? $req['headers'] : array() ),
				'data'    => isset( $req['body'] ) ? $req['body'] : array(),
			);
		}

		$options = array(
			'timeout'          => self::TIMEOUT,
			'connect_timeout'  => self::TIMEOUT,
			'follow_redirects' => false,
			'verify'           => ABSPATH . WPINC . '/certificates/ca-bundle.crt',
			'max_bytes'        => self::MAX_BYTES,
		);

		try {
			$raw = Requests::request_multiple( $prepared, $options );
		} catch ( \Throwable $e ) {
			$raw = array();
			foreach ( $requests as $key => $req ) {
				$raw[ $key ] = $e;
			}
		}

		$out = array();
		foreach ( $requests as $key => $req ) {
			$out[ $key ] = self::normalize( isset( $raw[ $key ] ) ? $raw[ $key ] : null );
		}

		return $out;
	}

	/**
	 * Converts a Requests response or exception into the classifier's response shape.
	 *
	 * @param mixed $raw A Requests Response, an Exception, or null.
	 * @return array
	 */
	private static function normalize( $raw ) {
		if ( $raw instanceof \Throwable ) {
			return Response_Classifier::response( 0, array(), '', $raw->getMessage() );
		}

		if ( ! is_object( $raw ) || ! isset( $raw->status_code ) ) {
			return Response_Classifier::response( 0, array(), '', 'no response' );
		}

		$headers = array();
		if ( isset( $raw->headers ) && is_object( $raw->headers ) && method_exists( $raw->headers, 'getAll' ) ) {
			foreach ( $raw->headers->getAll() as $name => $values ) {
				$headers[ $name ] = implode( ', ', (array) $values );
			}
		}

		return Response_Classifier::response( (int) $raw->status_code, $headers, (string) $raw->body );
	}

	/**
	 * The User-Agent WordPress itself sends, so a firewall treats the probe like a normal loopback.
	 *
	 * @return string
	 */
	private static function user_agent() {
		return 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url();
	}
}
