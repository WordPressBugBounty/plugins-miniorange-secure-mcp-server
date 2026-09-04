<?php
/**
 * Adding files to the media library, from a URL or from supplied bytes.
 *
 * Uploading is the one place in this library where a caller can make the server
 * reach out to an address of their choosing, and where a file of their choosing
 * lands on disk. Both are worth treating carefully, so three things are enforced
 * rather than assumed.
 *
 * 1. Where the server may connect. WordPress's own wp_http_validate_url() already
 *    refuses non-http schemes, credentials in the URL, odd ports and the private
 *    IPv4 and IPv6 ranges. It does not refuse 169.254.0.0/16 — which is where
 *    every major cloud serves instance metadata, including IAM credentials — nor
 *    the metadata hostnames, nor a hex-encoded address such as 0x7f000001. Those
 *    gaps are closed here, verified against the running WordPress rather than
 *    assumed.
 *
 * 2. What the file actually is. The declared type and the extension are both
 *    caller-controlled, so neither decides anything: wp_check_filetype_and_ext()
 *    inspects the contents, and the result must be a type the site permits. A PHP
 *    script named innocent.jpg fails this, which is the point.
 *
 * 3. How much of it there is. The response is capped while downloading rather than
 *    after, so an endpoint that streams indefinitely cannot fill the disk.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Core\Support;

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
 * directive covers IDE and Plugin Check runs that don't load that ruleset).
 */
// phpcs:disable WordPress.WP.I18n.TextDomainMismatch

/**
 * Class Media_Uploader
 */
class Media_Uploader {

	/**
	 * Seconds to wait for the remote file.
	 *
	 * @var int
	 */
	const FETCH_TIMEOUT = 20;

	/**
	 * Redirects followed before giving up.
	 *
	 * Kept low: a legitimate image URL redirects once or twice, and every hop is
	 * another address the caller did not necessarily name.
	 *
	 * @var int
	 */
	const MAX_REDIRECTS = 3;

	/**
	 * Ports a fetch may use.
	 *
	 * WordPress also permits 8080, which is a common place for internal services to
	 * listen. A public image served on 8080 is rare enough that refusing it costs
	 * little next to what it protects.
	 *
	 * @var int[]
	 */
	const ALLOWED_PORTS = array( 80, 443 );

	/**
	 * Hostnames that serve cloud instance metadata.
	 *
	 * Blocked by name as well as by resolved address, because the address check
	 * depends on resolution succeeding the same way twice.
	 *
	 * @var string[]
	 */
	const BLOCKED_HOSTS = array(
		'metadata.google.internal',
		'metadata.goog',
		'metadata',
		'instance-data',
		'169.254.169.254',
	);

	/**
	 * IPv4 ranges the server may not be pointed at, as [network, prefix length].
	 *
	 * WordPress covers most of these already; 169.254.0.0/16 and the carrier and
	 * benchmarking ranges are the additions that matter.
	 *
	 * @var array<int, array{0:string,1:int}>
	 */
	const BLOCKED_V4 = array(
		array( '0.0.0.0', 8 ),        // "this host".
		array( '10.0.0.0', 8 ),       // Private.
		array( '100.64.0.0', 10 ),    // Carrier-grade NAT.
		array( '127.0.0.0', 8 ),      // Loopback.
		array( '169.254.0.0', 16 ),   // Link-local: cloud instance metadata.
		array( '172.16.0.0', 12 ),    // Private.
		array( '192.0.0.0', 24 ),     // IETF protocol assignments.
		array( '192.168.0.0', 16 ),   // Private.
		array( '198.18.0.0', 15 ),    // Benchmarking.
		array( '224.0.0.0', 4 ),      // Multicast.
		array( '240.0.0.0', 4 ),      // Reserved.
	);

	/**
	 * Loads the upload helpers WordPress only includes for admin requests.
	 *
	 * wp_tempnam(), wp_handle_sideload() and wp_generate_attachment_metadata() all
	 * live under wp-admin/includes and are absent during a REST request, which is
	 * how every call here arrives. Loading them first is what makes these abilities
	 * work over the API rather than only under WP-CLI, where they happen to already
	 * be present.
	 *
	 * @return void
	 */
	private static function load_upload_helpers() {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
	}

	/**
	 * Adds a file to the media library from a URL.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function from_url( array $input ) {
		self::load_upload_helpers();

		$gate = self::check_upload_permission();
		if ( $gate instanceof WP_Error ) {
			return $gate;
		}

		$url = isset( $input['url'] ) ? trim( (string) $input['url'] ) : '';
		if ( '' === $url ) {
			return new WP_Error( 'missing_url', __( 'Provide the URL of the file to add.', 'mosmcp-abilities' ) );
		}

		$safe = self::validate_fetch_url( $url );
		if ( $safe instanceof WP_Error ) {
			return $safe;
		}

		$fetched = self::fetch_to_temp( $url );
		if ( $fetched instanceof WP_Error ) {
			return $fetched;
		}

		$filename = isset( $input['filename'] ) && '' !== trim( (string) $input['filename'] )
			? (string) $input['filename']
			: self::filename_from_url( $url );

		return self::sideload( $fetched['path'], $filename, $input, $fetched['warnings'] );
	}

	/**
	 * Adds a file to the media library from base64 content.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function from_content( array $input ) {
		self::load_upload_helpers();

		$gate = self::check_upload_permission();
		if ( $gate instanceof WP_Error ) {
			return $gate;
		}

		$filename = isset( $input['filename'] ) ? trim( (string) $input['filename'] ) : '';
		if ( '' === $filename ) {
			return new WP_Error(
				'missing_filename',
				__( 'Provide a filename, including its extension, so the file type can be checked.', 'mosmcp-abilities' )
			);
		}

		$encoded = isset( $input['content_base64'] ) ? (string) $input['content_base64'] : '';
		if ( '' === $encoded ) {
			return new WP_Error( 'missing_content', __( 'Provide the file contents as base64.', 'mosmcp-abilities' ) );
		}

		// Strip a data: prefix if one was passed, since callers often include it.
		$encoded = (string) preg_replace( '#^data:[^;]+;base64,#i', '', $encoded );

		$max = self::max_bytes();

		/*
		 * Base64 inflates by roughly a third, so the encoded length gives a cheap
		 * upper bound before allocating the decoded string.
		 */
		if ( strlen( $encoded ) > (int) ceil( $max * 1.4 ) ) {
			return self::too_large_error( (int) ( strlen( $encoded ) * 0.75 ), $max );
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Base64 is the transport encoding for the caller's file; strict mode rejects anything that is not valid base64.
		$bytes = base64_decode( $encoded, true );
		if ( false === $bytes || '' === $bytes ) {
			return new WP_Error(
				'invalid_base64',
				__( 'The content could not be decoded as base64.', 'mosmcp-abilities' )
			);
		}

		if ( strlen( $bytes ) > $max ) {
			return self::too_large_error( strlen( $bytes ), $max );
		}

		$tmp = wp_tempnam( sanitize_file_name( $filename ) );
		if ( ! $tmp ) {
			return new WP_Error( 'temp_file_failed', __( 'A temporary file could not be created for the upload.', 'mosmcp-abilities' ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing to a private temp file before wp_handle_sideload() takes over; WP_Filesystem has no temp-file equivalent.
		if ( false === file_put_contents( $tmp, $bytes ) ) {
			wp_delete_file( $tmp );
			return new WP_Error( 'temp_write_failed', __( 'The upload could not be written to a temporary file.', 'mosmcp-abilities' ) );
		}

		return self::sideload( $tmp, $filename, $input, array() );
	}

	/* --------------------------------------------------------------- guards */

	/**
	 * Confirms the account may add files at all.
	 *
	 * @return true|WP_Error
	 */
	private static function check_upload_permission() {
		if ( ! current_user_can( 'upload_files' ) ) {
			return new WP_Error(
				'cannot_upload',
				__( 'The connected account is not permitted to add files to the media library. This requires the "upload_files" capability, which Authors and above have by default.', 'mosmcp-abilities' )
			);
		}
		return true;
	}

	/**
	 * Decides whether the server may fetch a URL.
	 *
	 * @param string $url Candidate URL.
	 * @return true|WP_Error
	 */
	public static function validate_fetch_url( $url ) {
		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) ) {
			return new WP_Error( 'invalid_url', __( 'That is not a complete URL.', 'mosmcp-abilities' ) );
		}

		/*
		 * The scheme is checked before the host, so that file:///etc/passwd — which
		 * has no host at all — is refused for the reason that actually matters
		 * rather than as a malformed URL.
		 */
		if ( ! empty( $parts['scheme'] ) && ! in_array( strtolower( (string) $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return new WP_Error(
				'unsupported_scheme',
				sprintf(
					/* translators: %s: the scheme that was supplied */
					__( 'Files can only be fetched over http or https, not "%s".', 'mosmcp-abilities' ),
					(string) $parts['scheme']
				)
			);
		}

		if ( empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			return new WP_Error( 'invalid_url', __( 'That is not a complete URL.', 'mosmcp-abilities' ) );
		}

		if ( isset( $parts['port'] ) && ! in_array( (int) $parts['port'], self::ALLOWED_PORTS, true ) ) {
			return new WP_Error(
				'unsupported_port',
				sprintf(
					/* translators: %d: the port that was supplied */
					__( 'Files can only be fetched from the standard web ports, not port %d.', 'mosmcp-abilities' ),
					(int) $parts['port']
				)
			);
		}

		$host = strtolower( trim( (string) $parts['host'], '.[]' ) );

		if ( in_array( $host, self::BLOCKED_HOSTS, true ) ) {
			return self::internal_address_error( $host );
		}

		/*
		 * Anything that is neither a valid address literal nor a plausible public
		 * hostname is refused outright. This is what catches 0x7f000001 and its
		 * relatives: they are not valid IPs as far as filter_var is concerned, so
		 * without this they would fall through to name resolution — and a resolver
		 * that answers NXDOMAIN with a parking address (many ISP and captive-portal
		 * resolvers do) would report them as public. Deciding on the syntax does not
		 * depend on trusting the resolver.
		 */
		if ( ! filter_var( $host, FILTER_VALIDATE_IP ) && ! self::is_plausible_public_hostname( $host ) ) {
			return self::internal_address_error( $host );
		}

		/*
		 * Core's check comes first so its protections apply, then the ranges it does
		 * not cover are checked here. The site's own host is exempt from core's
		 * private-address rule, which is deliberate on its part and reasonable: a
		 * site re-adding a file from its own media URL is a normal thing to do.
		 */
		$home_host = strtolower( (string) wp_parse_url( get_option( 'home' ), PHP_URL_HOST ) );
		$is_own    = ( '' !== $home_host && $host === $home_host );

		if ( ! $is_own && false === wp_http_validate_url( $url ) ) {
			return self::internal_address_error( $host );
		}

		if ( $is_own ) {
			return true;
		}

		foreach ( self::resolve( $host ) as $ip ) {
			if ( self::is_blocked_ip( $ip ) ) {
				return self::internal_address_error( $host, $ip );
			}
		}

		return true;
	}

	/**
	 * Whether a host could be a public DNS name.
	 *
	 * Two requirements, both of which every real public hostname meets:
	 *
	 * - It contains a dot. A single-label name such as "intranet", "gitlab" or
	 *   "metadata" only means something on the local network, so nothing legitimate
	 *   is lost by refusing it.
	 * - Its last label is a real top-level domain: alphabetic, or an internationalised
	 *   one in its xn-- form. That is what rules out 0x7f000001, 2130706433 and
	 *   0177.0.0.1, none of which are valid IPs to filter_var but all of which many
	 *   resolvers and HTTP clients will happily read as 127.0.0.1.
	 *
	 * @param string $host Lowercased host.
	 * @return bool
	 */
	public static function is_plausible_public_hostname( $host ) {
		$host = (string) $host;

		if ( '' === $host || false === strpos( $host, '.' ) ) {
			return false;
		}

		$labels = explode( '.', $host );
		$tld    = (string) end( $labels );

		foreach ( $labels as $label ) {
			if ( '' === $label || ! preg_match( '/^[a-z0-9_-]+$/', $label ) ) {
				return false;
			}
		}

		if ( 0 === strpos( $tld, 'xn--' ) ) {
			return true;
		}

		return (bool) preg_match( '/^[a-z]{2,}$/', $tld );
	}

	/**
	 * Every address a host is known to resolve to.
	 *
	 * Both resolvers are consulted and the results combined, because they do not
	 * always agree: on a machine whose DNS answers unknown names with a parking
	 * address, dns_get_record() reports that address for "0177.0.0.1" while
	 * gethostbyname() reads the same string as 127.0.0.1. Whichever one an HTTP
	 * client would end up using, taking the union means an internal address in
	 * either answer is enough to refuse the fetch.
	 *
	 * @param string $host Hostname or address literal.
	 * @return string[]
	 */
	private static function resolve( $host ) {
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return array( $host );
		}

		$found = array();

		/*
		 * Silenced deliberately: a host that does not resolve makes dns_get_record()
		 * emit a warning, and that is an ordinary outcome here rather than a fault.
		 * The false return is handled below.
		 */
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A non-resolving host is an expected input, not an error worth surfacing.
		$records = @dns_get_record( $host, DNS_A | DNS_AAAA );
		if ( is_array( $records ) ) {
			foreach ( $records as $record ) {
				if ( ! empty( $record['ip'] ) ) {
					$found[] = (string) $record['ip'];
				}
				if ( ! empty( $record['ipv6'] ) ) {
					$found[] = (string) $record['ipv6'];
				}
			}
		}

		$ip = gethostbyname( $host );
		if ( $ip && $ip !== $host ) {
			$found[] = $ip;
		}

		return array_unique( $found );
	}

	/**
	 * Whether an address is one the server must not be pointed at.
	 *
	 * @param string $ip Address.
	 * @return bool
	 */
	public static function is_blocked_ip( $ip ) {
		$ip = (string) $ip;

		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$long = ip2long( $ip );
			if ( false === $long ) {
				return true;
			}
			foreach ( self::BLOCKED_V4 as $range ) {
				$mask = -1 << ( 32 - $range[1] );
				if ( ( $long & $mask ) === ( ip2long( $range[0] ) & $mask ) ) {
					return true;
				}
			}
			return false;
		}

		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			/*
			 * Anything not in the global unicast range is either loopback, link-local,
			 * unique-local or otherwise not a public destination. FILTER_FLAG_NO_PRIV_RANGE
			 * and NO_RES_RANGE cover those, so an address that fails them is refused.
			 */
			$public = filter_var(
				$ip,
				FILTER_VALIDATE_IP,
				FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
			);
			return false === $public;
		}

		// Not a recognisable address at all.
		return true;
	}

	/**
	 * The refusal used for every internal-address case, worded the same way.
	 *
	 * Deliberately does not confirm whether anything is listening there: the caller
	 * learns that the address is off limits, not what the network looks like.
	 *
	 * @param string $host Hostname as supplied.
	 * @param string $ip   Resolved address, when known.
	 * @return WP_Error
	 */
	private static function internal_address_error( $host, $ip = '' ) {
		return new WP_Error(
			'address_not_permitted',
			sprintf(
				/* translators: %s: the host that was supplied */
				__( 'Files cannot be fetched from "%s". Only public web addresses are allowed, so that this site cannot be used to reach services on its own network.', 'mosmcp-abilities' ),
				$host
			),
			'' !== $ip ? array( 'resolved_to' => $ip ) : array()
		);
	}

	/**
	 * The largest upload permitted, respecting the server's own limits.
	 *
	 * @return int
	 */
	public static function max_bytes() {
		$server = (int) wp_max_upload_size();
		return $server > 0 ? $server : 2097152;
	}

	/**
	 * The refusal used when something is over the size limit.
	 *
	 * @param int $actual Size in bytes.
	 * @param int $max    Limit in bytes.
	 * @return WP_Error
	 */
	private static function too_large_error( $actual, $max ) {
		return new WP_Error(
			'file_too_large',
			sprintf(
				/* translators: 1: actual size, 2: maximum size */
				__( 'That file is %1$s, above this site\'s %2$s upload limit.', 'mosmcp-abilities' ),
				size_format( max( 0, $actual ) ),
				size_format( $max )
			)
		);
	}

	/* --------------------------------------------------------------- fetch */

	/**
	 * Downloads a URL to a temporary file, bounded by size and time.
	 *
	 * @param string $url Validated URL.
	 * @return array{path:string,warnings:array}|WP_Error
	 */
	private static function fetch_to_temp( $url ) {
		$max      = self::max_bytes();
		$warnings = array();

		/*
		 * A HEAD first is cheap and lets an oversized file be refused before any of
		 * it is transferred. Not every server answers HEAD or sends a length, so
		 * this is an optimisation rather than the enforcement — the real check is on
		 * the downloaded file.
		 */
		$head = wp_safe_remote_head(
			$url,
			array(
				'timeout'     => self::FETCH_TIMEOUT,
				'redirection' => self::MAX_REDIRECTS,
			)
		);

		if ( ! is_wp_error( $head ) ) {
			$declared = (int) wp_remote_retrieve_header( $head, 'content-length' );
			if ( $declared > $max ) {
				return self::too_large_error( $declared, $max );
			}
		}

		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'     => self::FETCH_TIMEOUT,
				'redirection' => self::MAX_REDIRECTS,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'fetch_failed',
				sprintf(
					/* translators: %s: the underlying error */
					__( 'The file could not be downloaded: %s', 'mosmcp-abilities' ),
					$response->get_error_message()
				)
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code > 299 ) {
			return new WP_Error(
				'fetch_failed',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'The address returned HTTP %d rather than a file.', 'mosmcp-abilities' ),
					$code
				)
			);
		}

		$body = wp_remote_retrieve_body( $response );
		if ( '' === $body ) {
			return new WP_Error( 'fetch_empty', __( 'The address returned no content.', 'mosmcp-abilities' ) );
		}

		if ( strlen( $body ) > $max ) {
			return self::too_large_error( strlen( $body ), $max );
		}

		$tmp = wp_tempnam( self::filename_from_url( $url ) );
		if ( ! $tmp ) {
			return new WP_Error( 'temp_file_failed', __( 'A temporary file could not be created for the download.', 'mosmcp-abilities' ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing to a private temp file before wp_handle_sideload() takes over; WP_Filesystem has no temp-file equivalent.
		if ( false === file_put_contents( $tmp, $body ) ) {
			wp_delete_file( $tmp );
			return new WP_Error( 'temp_write_failed', __( 'The download could not be written to a temporary file.', 'mosmcp-abilities' ) );
		}

		return array(
			'path'     => $tmp,
			'warnings' => $warnings,
		);
	}

	/**
	 * A filename derived from a URL path.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function filename_from_url( $url ) {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$name = sanitize_file_name( basename( $path ) );

		return '' !== $name ? $name : 'upload';
	}

	/* ------------------------------------------------------------ sideload */

	/**
	 * Validates a temporary file's real type and moves it into the media library.
	 *
	 * @param string               $tmp      Temporary file path.
	 * @param string               $filename Intended filename.
	 * @param array<string, mixed> $input    Ability input.
	 * @param array<int, mixed>    $warnings Warnings accumulated so far.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function sideload( $tmp, $filename, array $input, array $warnings ) {
		self::load_upload_helpers();

		$filename = sanitize_file_name( $filename );

		/*
		 * The decisive check. Neither the extension nor any declared type is trusted:
		 * the file's own contents decide, and the result must be a type this site
		 * permits. A script renamed to .jpg fails here.
		 */
		$checked = wp_check_filetype_and_ext( $tmp, $filename );

		if ( empty( $checked['type'] ) || empty( $checked['ext'] ) ) {
			wp_delete_file( $tmp );
			return new WP_Error(
				'file_type_not_recognised',
				sprintf(
					/* translators: %s: filename */
					__( 'The contents of "%s" are not a file type this site accepts. The real contents are checked rather than the extension, so renaming a file does not help.', 'mosmcp-abilities' ),
					$filename
				)
			);
		}

		if ( ! empty( $checked['proper_filename'] ) ) {
			$warnings[] = array(
				'code'    => 'filename_corrected',
				'message' => sprintf(
					/* translators: 1: supplied filename, 2: corrected filename */
					__( 'The extension of "%1$s" did not match the file\'s contents, so it was saved as "%2$s".', 'mosmcp-abilities' ),
					$filename,
					(string) $checked['proper_filename']
				),
				'context' => 'detected=' . (string) $checked['type'],
			);
			$filename   = (string) $checked['proper_filename'];
		}

		$allowed = get_allowed_mime_types();
		if ( ! in_array( (string) $checked['type'], array_values( $allowed ), true ) ) {
			wp_delete_file( $tmp );
			return new WP_Error(
				'mime_type_not_allowed',
				sprintf(
					/* translators: %s: detected MIME type */
					__( 'This site does not accept "%s" files.', 'mosmcp-abilities' ),
					(string) $checked['type']
				)
			);
		}

		// wp_handle_sideload() takes its first argument by reference, so it needs a variable.
		$file = array(
			'name'     => $filename,
			'type'     => (string) $checked['type'],
			'tmp_name' => $tmp,
			'error'    => 0,
			'size'     => (int) filesize( $tmp ),
		);

		$sideloaded = wp_handle_sideload(
			$file,
			array(
				'test_form' => false,
				'mimes'     => $allowed,
			)
		);

		if ( ! empty( $sideloaded['error'] ) ) {
			// wp_handle_sideload removes the temp file itself on failure.
			return new WP_Error( 'sideload_failed', (string) $sideloaded['error'] );
		}

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => (string) $sideloaded['type'],
				'post_title'     => isset( $input['title'] ) && '' !== trim( (string) $input['title'] )
					? sanitize_text_field( (string) $input['title'] )
					: preg_replace( '/\.[^.]+$/', '', $filename ),
				'post_content'   => isset( $input['description'] ) ? wp_kses_post( (string) $input['description'] ) : '',
				'post_excerpt'   => isset( $input['caption'] ) ? sanitize_textarea_field( (string) $input['caption'] ) : '',
				'post_status'    => 'inherit',
			),
			$sideloaded['file'],
			self::resolve_parent( $input, $warnings )
		);

		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $sideloaded['file'] );
			return $attachment_id;
		}

		$attachment_id = (int) $attachment_id;

		wp_update_attachment_metadata(
			$attachment_id,
			wp_generate_attachment_metadata( $attachment_id, $sideloaded['file'] )
		);

		if ( isset( $input['alt_text'] ) ) {
			// wp_slash because the metadata API unslashes on the way in.
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', wp_slash( sanitize_text_field( (string) $input['alt_text'] ) ) );
		} elseif ( 0 === strpos( (string) $sideloaded['type'], 'image/' ) ) {
			$warnings[] = array(
				'code'    => 'no_alt_text',
				'message' => __( 'No alt text was given. Screen readers will announce nothing for this image, and it is worth setting.', 'mosmcp-abilities' ),
				'context' => 'attachment_id=' . $attachment_id,
			);
		}

		$meta = (array) wp_get_attachment_metadata( $attachment_id );

		return array(
			'attachment_id' => $attachment_id,
			'url'           => (string) wp_get_attachment_url( $attachment_id ),
			'filename'      => (string) basename( (string) $sideloaded['file'] ),
			'mime_type'     => (string) $sideloaded['type'],
			'filesize'      => (int) ( $meta['filesize'] ?? filesize( $sideloaded['file'] ) ),
			'width'         => (int) ( $meta['width'] ?? 0 ),
			'height'        => (int) ( $meta['height'] ?? 0 ),
			'title'         => (string) get_the_title( $attachment_id ),
			'alt_text'      => (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
			'attached_to'   => (int) get_post_field( 'post_parent', $attachment_id ),
			'edit_url'      => (string) get_edit_post_link( $attachment_id, 'raw' ),
			'warnings'      => $warnings,
		);
	}

	/**
	 * The post an upload should be attached to, if the caller may edit it.
	 *
	 * @param array<string, mixed> $input    Ability input.
	 * @param array<int, mixed>    $warnings Warnings, modified in place.
	 * @return int
	 */
	private static function resolve_parent( array $input, array &$warnings ) {
		$parent = isset( $input['attach_to_post_id'] ) ? absint( $input['attach_to_post_id'] ) : 0;

		if ( $parent <= 0 ) {
			return 0;
		}

		if ( ! get_post( $parent ) ) {
			$warnings[] = array(
				'code'    => 'attach_target_missing',
				'message' => __( 'The post to attach this file to does not exist, so it was added to the library unattached.', 'mosmcp-abilities' ),
				'context' => 'attach_to_post_id=' . $parent,
			);
			return 0;
		}

		if ( ! current_user_can( 'edit_post', $parent ) ) {
			$warnings[] = array(
				'code'    => 'attach_target_forbidden',
				'message' => __( 'The file was added to the library, but not attached to that post, because the connected account cannot edit it.', 'mosmcp-abilities' ),
				'context' => 'attach_to_post_id=' . $parent,
			);
			return 0;
		}

		return $parent;
	}
}
