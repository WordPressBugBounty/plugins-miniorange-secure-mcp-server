<?php
/**
 * Maps failing check codes to plain-language, copyable fixes.
 *
 * @package Miniorange_Secure_MCP_Server
 */

namespace MoSMCP\Common\Services\Diagnostics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Fix_Catalog
 *
 * Single source for fix wording and snippets, so the UI and the support bundle never disagree.
 * Snippets are plain ASCII with straight quotes only: a curly quote pasted into .htaccess or a
 * Cloudflare rule silently does nothing. A fix is
 * `array{id:string, title:string, why:string, host_only:bool, steps:array<int, array{text:string, code?:string}>, rows:string[]}`.
 */
class Fix_Catalog {

	/**
	 * Failure code => fix ids, in display order. Auth-header and context-dependent codes are handled in {@see fixes_for_code()}.
	 */
	const CODE_FIXES = array(
		'cloudflare_challenge'     => array( 'cloudflare_skip_rule', 'egress_allowlist' ),
		'cloudflare_block'         => array( 'cloudflare_skip_rule', 'egress_allowlist' ),
		'wordfence_block'          => array( 'waf_exemption', 'egress_allowlist' ),
		'sucuri_block'             => array( 'waf_exemption', 'egress_allowlist' ),
		'modsecurity_block'        => array( 'waf_exemption', 'egress_allowlist' ),
		'imunify_block'            => array( 'waf_exemption', 'egress_allowlist' ),
		'waf_block'                => array( 'waf_exemption', 'egress_allowlist' ),
		'blocked_403'              => array( 'waf_exemption', 'egress_allowlist' ),
		'rate_limited'             => array( 'waf_exemption', 'egress_allowlist' ),
		'egress_blocked'           => array( 'egress_allowlist' ),
		'rest_restricted'          => array( 'rest_restricted' ),
		'wpjson_blocked'           => array( 'waf_exemption' ),
		'rest_route_blocked'       => array( 'waf_exemption' ),
		'redirect'                 => array( 'site_address' ),
		'html_response'            => array( 'api_cache' ),
		'basic_auth'               => array( 'basic_auth_fix' ),
		'server_error'             => array( 'server_error' ),
		'transport_dns'            => array( 'site_unreachable' ),
		'transport_refused'        => array( 'site_unreachable' ),
		'transport_timeout'        => array( 'site_unreachable' ),
		'transport_unknown'        => array( 'site_unreachable' ),
		'transport_tls'            => array( 'tls_certificate' ),
		'cert_expired'             => array( 'tls_certificate' ),
		'cert_name_mismatch'       => array( 'tls_certificate' ),
		'cert_expiring'            => array( 'tls_certificate' ),
		'dns_stray_records'        => array( 'dns_stray_records' ),
		'not_this_site'            => array( 'dns_stray_records' ),
		'tables_missing'           => array( 'tables_repair' ),
		'schema_missing'           => array( 'tables_repair' ),
		'schema_outdated'          => array( 'tables_repair' ),
		'no_agent'                 => array( 'create_agent' ),
		'agent_disabled'           => array( 'create_agent' ),
		'no_grants'                => array( 'grant_abilities' ),
		'role_has_no_abilities'    => array( 'grant_abilities' ),
		'zero_abilities'           => array( 'grant_abilities', 'count_mismatch' ),
		'no_abilities_registered'  => array( 'abilities_api' ),
		'count_mismatch'           => array( 'count_mismatch' ),
		'not_https'                => array( 'site_address' ),
		'localhost_site'           => array( 'site_address' ),
		'host_mismatch'            => array( 'site_address' ),
		'www_mismatch'             => array( 'site_address' ),
		'gateway_refused_target'   => array( 'site_address' ),
		'scheme_host_mismatch'     => array( 'site_address' ),
		'token_reauth'             => array( 'reconnect_client' ),
		'token_refresh_failed'     => array( 'reconnect_client' ),
		'token_expiring'           => array( 'reconnect_client' ),
		'token_none'               => array( 'reconnect_client' ),
		'gateway_auth_failed'      => array( 'site_address', 'auth_header' ),
		'cert_unreadable'          => array(),
		'gateway_unreachable'      => array( 'gateway_outbound' ),
		'unexpected_response'      => array( 'contact_support' ),
	);

	/**
	 * Builds the de-duplicated fix list for a set of result rows.
	 *
	 * @param array[] $rows Result rows (id, status, code).
	 * @param array   $ctx  {auth_fix:string, server:string, sapi:string, layers:string[], egress_ips:string[], stray_ips:string[], htaccess_has_rule:bool, home_host:string}.
	 * @return array[]
	 */
	public static function for_rows( array $rows, array $ctx ) {
		$ctx   = array_merge(
			array(
				'auth_fix'          => 'host_only',
				'server'            => 'unknown',
				'sapi'              => '',
				'layers'            => array(),
				'egress_ips'        => array(),
				'stray_ips'         => array(),
				'htaccess_has_rule' => false,
				'home_host'         => '',
			),
			$ctx
		);
		$fixes = array();

		foreach ( $rows as $row ) {
			if ( ! in_array( $row['status'], array( Response_Classifier::FAIL, Response_Classifier::WARN ), true ) ) {
				continue;
			}

			foreach ( self::fixes_for_code( (string) $row['code'], $ctx ) as $id ) {
				if ( ! isset( $fixes[ $id ] ) ) {
					$fix = self::build( $id, $ctx );
					if ( null === $fix ) {
						continue;
					}
					$fixes[ $id ] = $fix;
				}
				$fixes[ $id ]['rows'][] = $row['id'];
			}
		}

		return array_values( $fixes );
	}

	/**
	 * Resolves the fix ids for one failure code.
	 *
	 * @param string $code Failure code.
	 * @param array  $ctx  Fix context.
	 * @return string[]
	 */
	public static function fixes_for_code( $code, array $ctx ) {
		if ( 'auth_header_stripped' === $code ) {
			return array( 'auth_header' );
		}

		$ids = isset( self::CODE_FIXES[ $code ] ) ? self::CODE_FIXES[ $code ] : array( 'contact_support' );

		// An allowlist only exists to hand over when the probe actually learned the IPs.
		if ( empty( $ctx['egress_ips'] ) ) {
			$ids = array_values( array_diff( $ids, array( 'egress_allowlist' ) ) );
		}

		return $ids;
	}

	/**
	 * The troubleshooting guide: entries by symptom, each with every fix that belongs to it and every
	 * platform variant shown (no run context), so the guide and the test results share one source of text.
	 *
	 * @return array[] Entries of {id, title, subtitle, icon, symptoms, fixes}.
	 */
	public static function guide() {
		$ctx = array(
			'guide'             => true,
			'auth_fix'          => 'all',
			'server'            => 'unknown',
			'sapi'              => '',
			'layers'            => array( 'cloudflare', 'litespeed', 'litespeed_cache', 'wordfence', 'sucuri', 'hostinger' ),
			'egress_ips'        => array(),
			'stray_ips'         => array(),
			'htaccess_has_rule' => false,
		);

		$entries = array();
		foreach ( self::entries() as $entry ) {
			$fixes = array();
			foreach ( $entry['fixes'] as $id ) {
				$fix = self::build( $id, $ctx );
				if ( null !== $fix ) {
					$fixes[] = $fix;
				}
			}
			$entry['fixes'] = $fixes;
			$entries[]      = $entry;
		}

		return $entries;
	}

	/**
	 * What is wrong, in a short plain sentence, per fix. The fix title says what to do; this says why you are here.
	 *
	 * @return array<string, string>
	 */
	private static function problems() {
		return array(
			'cloudflare_skip_rule'      => __( 'Cloudflare is blocking the AI', 'miniorange-secure-mcp-server' ),
			'waf_exemption'             => __( 'A firewall is blocking the AI', 'miniorange-secure-mcp-server' ),
			'egress_allowlist'          => __( 'Your firewall lets some of our servers in, not all', 'miniorange-secure-mcp-server' ),
			'rest_restricted'           => __( 'A setting or plugin is locking the REST API', 'miniorange-secure-mcp-server' ),
			'basic_auth_fix'            => __( 'A password prompt sits in front of your site', 'miniorange-secure-mcp-server' ),
			'auth_header'               => __( 'Your server is not passing the AI\'s sign-in to WordPress', 'miniorange-secure-mcp-server' ),
			'site_address'              => __( 'Your site address needs tidying up', 'miniorange-secure-mcp-server' ),
			'api_cache'                 => __( 'A cache or redirect answers instead of the plugin', 'miniorange-secure-mcp-server' ),
			'site_unreachable'          => __( 'Your site could not be reached', 'miniorange-secure-mcp-server' ),
			'tls_certificate'           => __( 'Your security certificate has a problem', 'miniorange-secure-mcp-server' ),
			'dns_stray_records'         => __( 'Your domain also points at the wrong server', 'miniorange-secure-mcp-server' ),
			'server_error'              => __( 'Your site is returning an error', 'miniorange-secure-mcp-server' ),
			'gateway_outbound'          => __( 'Your server cannot reach our test service', 'miniorange-secure-mcp-server' ),
			'tables_repair'             => __( 'The plugin\'s data is not set up', 'miniorange-secure-mcp-server' ),
			'create_agent'              => __( 'No AI agent is switched on', 'miniorange-secure-mcp-server' ),
			'grant_abilities'           => __( 'No abilities are shared with this role', 'miniorange-secure-mcp-server' ),
			'abilities_api'             => __( 'WordPress has no abilities to share', 'miniorange-secure-mcp-server' ),
			'count_mismatch'            => __( 'The AI sees a different number of abilities than you do', 'miniorange-secure-mcp-server' ),
			'reconnect_client'          => __( 'An AI app needs to sign in again', 'miniorange-secure-mcp-server' ),
			'contact_support'           => __( 'We need a closer look', 'miniorange-secure-mcp-server' ),
		);
	}

	/**
	 * The guide entry a fix belongs to.
	 *
	 * @param string $fix_id Fix id.
	 * @return string Entry id, or '' for a fix with no entry (the support fallback).
	 */
	private static function entry_of( $fix_id ) {
		foreach ( self::entries() as $entry ) {
			if ( in_array( $fix_id, $entry['fixes'], true ) ) {
				return $entry['id'];
			}
		}

		return '';
	}

	/**
	 * Guide entries in display order. Each fix id belongs to exactly one entry.
	 *
	 * @return array[]
	 */
	private static function entries() {
		return array(
			array(
				'id'       => 'scheme-host',
				'icon'     => 'link',
				'title'    => __( 'Every request fails after connecting', 'miniorange-secure-mcp-server' ),
				'subtitle' => __( 'It connects fine, then every request comes back unauthorized', 'miniorange-secure-mcp-server' ),
				'symptoms' => array(),
				'fixes'    => array( 'site_address' ),
			),
			array(
				'id'       => 'auth-header',
				'icon'     => 'key',
				'title'    => __( 'Everything looks connected, but nothing works', 'miniorange-secure-mcp-server' ),
				'subtitle' => __( 'It looks connected, but the AI is never recognized as signed in', 'miniorange-secure-mcp-server' ),
				'symptoms' => array(),
				'fixes'    => array( 'auth_header' ),
			),
			array(
				'id'       => 'firewall',
				'icon'     => 'shield',
				'title'    => __( 'A firewall or security plugin is blocking connection requests', 'miniorange-secure-mcp-server' ),
				'subtitle' => __( 'Requests are blocked outright, or only some of them get through', 'miniorange-secure-mcp-server' ),
				'symptoms' => array(
					__( 'Nothing works, no matter which connection method you try', 'miniorange-secure-mcp-server' ),
					__( 'The connection process starts but never finishes', 'miniorange-secure-mcp-server' ),
					__( 'It works for some people or some of the time, not others', 'miniorange-secure-mcp-server' ),
				),
				'fixes'    => array( 'cloudflare_skip_rule', 'waf_exemption', 'egress_allowlist', 'rest_restricted', 'basic_auth_fix', 'api_cache' ),
			),
			array(
				'id'       => 'unreachable',
				'icon'     => 'server',
				'title'    => __( 'We couldn\'t reach your site at all', 'miniorange-secure-mcp-server' ),
				'subtitle' => __( 'The site itself couldn\'t be reached, likely not AI-specific', 'miniorange-secure-mcp-server' ),
				'symptoms' => array(),
				'fixes'    => array( 'site_unreachable', 'tls_certificate', 'dns_stray_records', 'server_error', 'gateway_outbound' ),
			),
			array(
				'id'       => 'no-abilities',
				'icon'     => 'plug',
				'title'    => __( 'Connected, but no abilities', 'miniorange-secure-mcp-server' ),
				'subtitle' => __( 'The AI connects but lists no tools, or fewer than the dashboard shows', 'miniorange-secure-mcp-server' ),
				'symptoms' => array(),
				'fixes'    => array( 'tables_repair', 'create_agent', 'grant_abilities', 'abilities_api', 'count_mismatch', 'reconnect_client' ),
			),
		);
	}
	/**
	 * Builds one fix.
	 *
	 * @param string $id  Fix id.
	 * @param array  $ctx Fix context.
	 * @return array|null
	 */
	private static function build( $id, array $ctx ) {
		$method = 'fix_' . $id;
		if ( ! method_exists( __CLASS__, $method ) ) {
			return null;
		}

		$fix          = call_user_func( array( __CLASS__, $method ), $ctx );
		$fix['id']    = $id;
		$fix['entry'] = self::entry_of( $id );
		$problems     = self::problems();
		$fix['problem'] = isset( $problems[ $id ] ) ? $problems[ $id ] : $fix['title'];
		$fix['rows']  = array();
		if ( ! isset( $fix['host_only'] ) ) {
			$fix['host_only'] = false;
		}

		return $fix;
	}

	/**
	 * Builds a step.
	 *
	 * @param string      $text     Instruction.
	 * @param string|null $code     Copyable snippet.
	 * @param string|null $platform Platform the step applies to; the guide groups these into tabs.
	 * @return array
	 */
	private static function step( $text, $code = null, $platform = null ) {
		$step = array( 'text' => $text );
		if ( null !== $code ) {
			$step['code'] = $code;
		}
		if ( null !== $platform ) {
			$step['platform'] = $platform;
		}

		return $step;
	}

	/**
	 * A platform label, only in the guide (a test result is already specific to this site).
	 *
	 * @param array  $ctx   Fix context.
	 * @param string $label Platform name.
	 * @return string|null
	 */
	private static function tag( array $ctx, $label ) {
		return ! empty( $ctx['guide'] ) ? $label : null;
	}

	/**
	 * Fix: Authorization header stripped, tailored to the web server. The guide ('all') lists every variant.
	 *
	 * @param array $ctx Fix context.
	 * @return array
	 */
	private static function fix_auth_header( array $ctx ) {
		$kind      = $ctx['auth_fix'];
		$all       = 'all' === $kind;
		$steps     = array();
		$host_only = false;

		if ( $all || 'htaccess' === $kind ) {
			if ( ! $all && ! empty( $ctx['htaccess_has_rule'] ) ) {
				$host_only = true;
				$steps[]   = self::step( __( 'The rule is already in your .htaccess and the header still does not arrive. Something above WordPress (a host proxy or ModSecurity) is removing it, so only your host can fix this. Send them the copied diagnostics.', 'miniorange-secure-mcp-server' ) );
			} else {
				$steps[] = self::step( __( 'Back up your .htaccess file, then add these two lines at the very top, above the "# BEGIN WordPress" block:', 'miniorange-secure-mcp-server' ), "RewriteEngine On\nRewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]", self::tag( $ctx, 'Apache' ) );
				$steps[] = self::step( __( 'If that does not resolve it, some hosts only accept this alternative:', 'miniorange-secure-mcp-server' ), 'SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1', self::tag( $ctx, 'Apache' ) );
			}
		}

		if ( $all || 'nginx' === $kind ) {
			$host_only = $host_only || ! $all;
			$steps[]   = self::step( __( 'This cannot be changed from WordPress or .htaccess on nginx. Add this inside the site\'s PHP location block, then reload nginx (on managed hosting, send it to your host):', 'miniorange-secure-mcp-server' ), 'fastcgi_param HTTP_AUTHORIZATION $http_authorization;', self::tag( $ctx, 'Nginx' ) );
		}

		if ( 'host_only' === $kind ) {
			$host_only = true;
			$steps[]   = self::step( __( 'Only a change on the server itself fixes this, and this server type is not one we can edit for you. Ask your host to pass the Authorization header through to PHP and send them the copied diagnostics.', 'miniorange-secure-mcp-server' ) );
		}

		return array(
			'title'     => __( 'Let the Authorization header reach WordPress', 'miniorange-secure-mcp-server' ),
			'why'       => __( 'Your server removes the sign-in headers before WordPress runs (common when PHP runs as CGI or FastCGI, such as cgi-fcgi). The gateway sends the token in two headers, Authorization and X-MO-Authorization, and both are being removed, so every request looks signed out.', 'miniorange-secure-mcp-server' ),
			'host_only' => $host_only,
			'steps'     => $steps,
		);
	}

	/**
	 * Fix: Cloudflare challenge.
	 *
	 * @return array
	 */
	private static function fix_cloudflare_skip_rule() {
		return array(
			'title' => __( 'Stop Cloudflare challenging the connection', 'miniorange-secure-mcp-server' ),
			'why'   => __( 'Cloudflare treats the AI gateway as a bot and answers with a challenge page instead of your site. A custom rule that skips bot checks for the MCP and OAuth paths fixes this.', 'miniorange-secure-mcp-server' ),
			'steps' => array(
				self::step( __( 'In Cloudflare open Security, then WAF, then Custom rules, and create a rule. Choose "Edit expression" and paste:', 'miniorange-secure-mcp-server' ), '(starts_with(http.request.uri.path, "/wp-json/mosmcp/v1/")) or (http.request.uri.query contains "rest_route=/mosmcp/")' ),
				self::step( __( 'Set the action to Skip. Tick Super Bot Fight Mode rules (or Bot Fight Mode where offered) and Security Level.', 'miniorange-secure-mcp-server' ) ),
				self::step( __( 'Save and drag the rule to the top of the list so it runs first.', 'miniorange-secure-mcp-server' ) ),
				self::step( __( 'On the Free plan Cloudflare may not let any rule skip Bot Fight Mode. If the challenge continues, turn Bot Fight Mode off under Security, then Bots, or allowlist the gateway IPs shown below.', 'miniorange-secure-mcp-server' ) ),
			),
		);
	}

	/**
	 * Fix: firewall/WAF exemption.
	 *
	 * @param array $ctx Fix context.
	 * @return array
	 */
	private static function fix_waf_exemption( array $ctx ) {
		$steps = array(
			self::step( __( 'Check your firewall or security plugin\'s log for blocked requests to /wp-json/mosmcp/v1/mcp, /register or /token, and allow all three.', 'miniorange-secure-mcp-server' ) ),
			self::step( __( 'In your firewall, security plugin or host panel, exempt these paths from blocking and bot checks:', 'miniorange-secure-mcp-server' ), "/wp-json/mosmcp/*\n/index.php?rest_route=/mosmcp/*" ),
		);

		$hints = array(
			'wordfence' => array( 'Wordfence', __( 'Open Tools, then Live Traffic, find the blocked requests to /wp-json/mosmcp/ and allowlist them.', 'miniorange-secure-mcp-server' ) ),
			'sucuri'    => array( 'Sucuri', __( 'Allowlist these paths in the firewall settings of the Sucuri dashboard.', 'miniorange-secure-mcp-server' ) ),
			'hostinger' => array( 'Hostinger', __( 'If the block page came from the host firewall, contact support with these paths and the gateway IPs.', 'miniorange-secure-mcp-server' ) ),
		);
		foreach ( $hints as $layer => $hint ) {
			if ( in_array( $layer, $ctx['layers'], true ) ) {
				$steps[] = self::step( $hint[1], null, self::tag( $ctx, $hint[0] ) );
			}
		}

		if ( ! empty( $ctx['guide'] ) ) {
			$steps[] = self::step( __( 'Only if you manage ModSecurity directly (VPS or dedicated hosting): this main-server-config rule turns it off for the MCP endpoint, as a temporary test to confirm the cause, not a permanent fix. Work with your host on a proper exception afterwards.', 'miniorange-secure-mcp-server' ), "<IfModule mod_security.c>\n<LocationMatch \"^/wp-json/mosmcp/v1/mcp\">\n    SecRuleEngine Off\n</LocationMatch>\n</IfModule>", 'ModSecurity' );
		}

		$steps[] = self::step( __( 'Run the connection test again.', 'miniorange-secure-mcp-server' ) );

		return array(
			'title' => __( 'Exempt the MCP URLs from your firewall', 'miniorange-secure-mcp-server' ),
			'why'   => __( 'A firewall or security rule is blocking requests to the plugin before WordPress can answer them. AI clients then see an error or an empty list. If you are not on a self-managed server there is no universal snippet: it is a rule in that product or your host\'s panel.', 'miniorange-secure-mcp-server' ),
			'steps' => $steps,
		);
	}

	/**
	 * Fix: gateway IP allowlist.
	 *
	 * @param array $ctx Fix context.
	 * @return array
	 */
	private static function fix_egress_allowlist( array $ctx ) {
		$steps = array();

		if ( empty( $ctx['egress_ips'] ) ) {
			$steps[] = self::step( __( 'Run the connection test: it lists the exact gateway IP addresses to allow. Allow them (not just skip logging) in every firewall or CDN in front of the site.', 'miniorange-secure-mcp-server' ) );
		} else {
			$steps[] = self::step( __( 'Allowlist these gateway IP addresses (allow, not just skip logging) in every firewall or CDN in front of the site:', 'miniorange-secure-mcp-server' ), implode( "\n", array_map( 'strval', $ctx['egress_ips'] ) ) );
		}

		if ( in_array( 'cloudflare', $ctx['layers'], true ) ) {
			$steps[] = self::step( __( 'Security, then WAF, then Tools, then IP Access Rules. Add each IP with the action Allow.', 'miniorange-secure-mcp-server' ), null, self::tag( $ctx, 'Cloudflare' ) );
		}
		if ( in_array( 'wordfence', $ctx['layers'], true ) ) {
			$steps[] = self::step( __( 'Add each IP under Firewall, then All Firewall Options, in the allowlisted IPs list.', 'miniorange-secure-mcp-server' ), null, self::tag( $ctx, 'Wordfence' ) );
		}
		$steps[] = self::step( __( 'On shared hosting, send the list to your host and ask them to allowlist it in their firewall.', 'miniorange-secure-mcp-server' ), null, self::tag( $ctx, 'Host' ) );

		return array(
			'title' => __( 'Allowlist the gateway IP addresses', 'miniorange-secure-mcp-server' ),
			'why'   => __( 'Your site accepts some gateway addresses and rejects others. Allowlisting all of them removes the random failures.', 'miniorange-secure-mcp-server' ),
			'steps' => $steps,
		);
	}

	/**
	 * Fix: security plugin restricting the REST API.
	 *
	 * @return array
	 */
	private static function fix_rest_restricted() {
		return array(
			'title' => __( 'Allow the plugin through your REST API restriction', 'miniorange-secure-mcp-server' ),
			'why'   => __( 'WordPress or a security plugin only lets logged-in users use the REST API, so the AI client is turned away before it can sign in.', 'miniorange-secure-mcp-server' ),
			'steps' => array(
				self::step( __( 'In the plugin that restricts the REST API (for example a "disable REST API" option in a security plugin), add this namespace to its allowlist:', 'miniorange-secure-mcp-server' ), 'mosmcp/v1' ),
				self::step( __( 'Or turn the restriction off for unauthenticated requests, then run the connection test again.', 'miniorange-secure-mcp-server' ) ),
			),
		);
	}

	/**
	 * Fix: a cache or redirect answers the plugin's API with a stored web page.
	 *
	 * @param array $ctx Fix context.
	 * @return array
	 */
	private static function fix_api_cache( array $ctx ) {
		$steps = array();

		if ( in_array( 'litespeed', $ctx['layers'], true ) || in_array( 'litespeed_cache', $ctx['layers'], true ) ) {
			$steps[] = self::step( __( 'LiteSpeed Cache: open Cache, then Excludes, and add this under Do Not Cache URIs:', 'miniorange-secure-mcp-server' ), '/wp-json/mosmcp/', self::tag( $ctx, 'LiteSpeed' ) );
		}
		if ( in_array( 'cloudflare', $ctx['layers'], true ) ) {
			$steps[] = self::step( __( 'Cloudflare: add a Cache Rule that bypasses the cache when the URI path starts with:', 'miniorange-secure-mcp-server' ), '/wp-json/mosmcp/', self::tag( $ctx, 'Cloudflare' ) );
		}
		if ( empty( $steps ) || ! empty( $ctx['guide'] ) ) {
			$steps[] = self::step( __( 'In your cache plugin or CDN, exclude this path from caching and from any redirect rule:', 'miniorange-secure-mcp-server' ), '/wp-json/mosmcp/', self::tag( $ctx, 'Other cache or CDN' ) );
		}
		$steps[] = self::step( __( 'Purge the whole cache, then run the connection test again.', 'miniorange-secure-mcp-server' ) );

		return array(
			'title' => __( 'Stop caching the plugin\'s API', 'miniorange-secure-mcp-server' ),
			'why'   => __( 'A cache or redirect rule answers requests to the plugin with a stored web page, so the gateway never reaches WordPress.', 'miniorange-secure-mcp-server' ),
			'steps' => $steps,
		);
	}

	/**
	 * Fix: HTTP Basic authentication in front of the site.
	 *
	 * @return array
	 */
	private static function fix_basic_auth_fix() {
		return array(
			'title' => __( 'Remove the password prompt in front of the site', 'miniorange-secure-mcp-server' ),
			'why'   => __( 'The site asks for a username and password (HTTP Basic authentication) before WordPress runs, and an AI client cannot answer it.', 'miniorange-secure-mcp-server' ),
			'steps' => array(
				self::step( __( 'Remove the password protection from the site, or exempt these paths from it (host panel "Password protected directories", or .htpasswd rules):', 'miniorange-secure-mcp-server' ), "/wp-json/mosmcp/*\n/index.php?rest_route=/mosmcp/*" ),
			),
		);
	}

	/**
	 * Fix: server error.
	 *
	 * @return array
	 */
	private static function fix_server_error() {
		return array(
			'title' => __( 'Fix the server error on the site', 'miniorange-secure-mcp-server' ),
			'why'   => __( 'The site answered with a 5xx error, so the problem is on the server or in a plugin, not in the AI client.', 'miniorange-secure-mcp-server' ),
			'steps' => array(
				self::step( __( 'Check the PHP error log or turn on WP_DEBUG_LOG in wp-config.php and look for a fatal error when this test runs.', 'miniorange-secure-mcp-server' ) ),
				self::step( __( 'Make sure the site is not in maintenance mode and the host is not overloaded.', 'miniorange-secure-mcp-server' ) ),
			),
		);
	}

	/**
	 * Fix: site unreachable.
	 *
	 * @return array
	 */
	private static function fix_site_unreachable() {
		return array(
			'title' => __( 'Make the site reachable', 'miniorange-secure-mcp-server' ),
			'why'   => __( 'The request never got an answer from the site, so this is not specific to AI connections.', 'miniorange-secure-mcp-server' ),
			'steps' => array(
				self::step( __( 'Open your site address in a private browser window over https and confirm it loads.', 'miniorange-secure-mcp-server' ) ),
				self::step( __( 'Check your DNS records point at the right server and that the host or a firewall is not dropping requests that come from data centers.', 'miniorange-secure-mcp-server' ) ),
				self::step( __( 'If the site is slow, a timeout can happen under load. Run the test again in a minute.', 'miniorange-secure-mcp-server' ) ),
			),
		);
	}

	/**
	 * Fix: certificate trouble.
	 *
	 * @return array
	 */
	private static function fix_tls_certificate() {
		return array(
			'title' => __( 'Fix the HTTPS certificate', 'miniorange-secure-mcp-server' ),
			'why'   => __( 'AI clients refuse a site whose certificate is expired, does not cover the address, or is missing its chain.', 'miniorange-secure-mcp-server' ),
			'steps' => array(
				self::step( __( 'Renew or reissue the certificate so it covers both the bare domain and the www host, and install the full chain.', 'miniorange-secure-mcp-server' ) ),
				self::step( __( 'Most hosts renew automatically under SSL or Security in their panel. Run the connection test again after renewing.', 'miniorange-secure-mcp-server' ) ),
			),
		);
	}

	/**
	 * Fix: stray DNS records.
	 *
	 * @param array $ctx Fix context.
	 * @return array
	 */
	private static function fix_dns_stray_records( array $ctx ) {
		$steps = array();
		if ( ! empty( $ctx['stray_ips'] ) ) {
			$steps[] = self::step( __( 'These addresses answered but do not serve this site. Delete the A or AAAA records that point at them:', 'miniorange-secure-mcp-server' ), implode( "\n", array_map( 'strval', $ctx['stray_ips'] ) ) );
		} else {
			$steps[] = self::step( __( 'Delete any A or AAAA record that points at a server which does not host this site.', 'miniorange-secure-mcp-server' ) );
		}
		$steps[] = self::step( __( 'Wait for DNS to refresh (often under an hour), then run the connection test again.', 'miniorange-secure-mcp-server' ) );

		return array(
			'title' => __( 'Remove DNS records that point at the wrong server', 'miniorange-secure-mcp-server' ),
			'why'   => __( 'Your domain resolves to more than one address and some of them are not your site, so connections succeed for some clients and fail at random for others.', 'miniorange-secure-mcp-server' ),
			'steps' => $steps,
		);
	}

	/**
	 * Fix: plugin tables or schema.
	 *
	 * @return array
	 */
	private static function fix_tables_repair() {
		return array(
			'title' => __( 'Repair the plugin database tables', 'miniorange-secure-mcp-server' ),
			'why'   => __( 'The plugin could not create or upgrade its tables, so agents, tokens and permissions cannot be saved.', 'miniorange-secure-mcp-server' ),
			'steps' => array(
				self::step( __( 'Deactivate the plugin and activate it again on the Plugins page. This re-runs the install step.', 'miniorange-secure-mcp-server' ) ),
				self::step( __( 'If the tables are still missing, the database user probably cannot create or alter tables. Ask your host to grant CREATE and ALTER to the WordPress database user.', 'miniorange-secure-mcp-server' ) ),
			),
		);
	}

	/**
	 * Fix: no agent.
	 *
	 * @return array
	 */
	private static function fix_create_agent() {
		return array(
			'title' => __( 'Create and enable an agent', 'miniorange-secure-mcp-server' ),
			'why'   => __( 'An agent decides which abilities AI clients may use. With no enabled agent every client is refused.', 'miniorange-secure-mcp-server' ),
			'steps' => array(
				self::step( __( 'Open the Agents page, create an agent and make sure it is enabled.', 'miniorange-secure-mcp-server' ) ),
			),
		);
	}

	/**
	 * Fix: no abilities granted.
	 *
	 * @return array
	 */
	private static function fix_grant_abilities() {
		return array(
			'title' => __( 'Grant abilities to a role', 'miniorange-secure-mcp-server' ),
			'why'   => __( 'Abilities are granted per role inside an agent. A role with no grants sees an empty list.', 'miniorange-secure-mcp-server' ),
			'steps' => array(
				self::step( __( 'Open the Agents page, edit the agent, pick the role of the person who will connect, and tick the abilities to allow. Save.', 'miniorange-secure-mcp-server' ) ),
				self::step( __( 'Make sure the agent is enabled, then run the connection test again.', 'miniorange-secure-mcp-server' ) ),
			),
		);
	}

	/**
	 * Fix: Abilities API unavailable.
	 *
	 * @return array
	 */
	private static function fix_abilities_api() {
		return array(
			'title' => __( 'Make WordPress abilities available', 'miniorange-secure-mcp-server' ),
			'why'   => __( 'WordPress reports no registered abilities, so there is nothing to give AI clients.', 'miniorange-secure-mcp-server' ),
			'steps' => array(
				self::step( __( 'Update WordPress to the version this plugin requires, and make sure no plugin unregisters abilities.', 'miniorange-secure-mcp-server' ) ),
			),
		);
	}

	/**
	 * Fix: ability count differs.
	 *
	 * @return array
	 */
	private static function fix_count_mismatch() {
		return array(
			'title' => __( 'Make the gateway see the same abilities as the dashboard', 'miniorange-secure-mcp-server' ),
			'why'   => __( 'The gateway and this dashboard disagree on how many abilities exist. The usual causes are a stale cache or a connection signed in as a different role.', 'miniorange-secure-mcp-server' ),
			'steps' => array(
				self::step( __( 'Wait a minute (the gateway caches the list briefly), then run the test again.', 'miniorange-secure-mcp-server' ) ),
				self::step( __( 'Disconnect and reconnect the connector in the AI app so it fetches a fresh list.', 'miniorange-secure-mcp-server' ) ),
				self::step( __( 'Check that the connecting WordPress user has the role you granted abilities to, and that no MOSMCP_EXPOSED_PREFIXES constant in wp-config.php trims the list.', 'miniorange-secure-mcp-server' ) ),
			),
		);
	}

	/**
	 * Fix: one public https address, no redirects on the protocol URLs, and https behind a proxy.
	 *
	 * @return array
	 */
	private static function fix_site_address() {
		return array(
			'title' => __( 'Use one public https address for the site', 'miniorange-secure-mcp-server' ),
			'why'   => __( 'AI clients need a single public https address. Plain http, mixed www and non-www hosts, a private address, or a proxy that makes WordPress think it is on http all make sign-in fail, or make every request come back unauthorized after connecting.', 'miniorange-secure-mcp-server' ),
			'steps' => array(
				self::step( __( 'In Settings, then General, set WordPress Address and Site Address to the same public https address, with one canonical host (all www or all non-www).', 'miniorange-secure-mcp-server' ) ),
				self::step( __( 'Redirect the other host to it, but never for /wp-json/: the gateway does not follow redirects there, and a redirected POST loses its body and sign-in header. A local address such as localhost cannot be reached from outside your computer.', 'miniorange-secure-mcp-server' ) ),
				self::step( __( 'If HTTPS ends at a proxy or load balancer, add this near the top of wp-config.php so WordPress knows it is on https:', 'miniorange-secure-mcp-server' ), "if ( isset( \$_SERVER['HTTP_X_FORWARDED_PROTO'] ) && 'https' === strtolower( \$_SERVER['HTTP_X_FORWARDED_PROTO'] ) ) {\n\t\$_SERVER['HTTPS'] = 'on';\n}" ),
			),
		);
	}

	/**
	 * Fix: a linked client must reconnect.
	 *
	 * @return array
	 */
	private static function fix_reconnect_client() {
		return array(
			'title' => __( 'Reconnect the AI client', 'miniorange-secure-mcp-server' ),
			'why'   => __( 'The saved sign-in for this client is expired, rejected or never finished, so it cannot refresh itself.', 'miniorange-secure-mcp-server' ),
			'steps' => array(
				self::step( __( 'In the AI app (Claude, ChatGPT), remove the connector and add it again to sign in fresh.', 'miniorange-secure-mcp-server' ) ),
			),
		);
	}

	/**
	 * Fix: this server cannot reach the gateway.
	 *
	 * @return array
	 */
	private static function fix_gateway_outbound() {
		return array(
			'title' => __( 'Let this server reach the miniOrange gateway', 'miniorange-secure-mcp-server' ),
			'why'   => __( 'The test from outside runs on the miniOrange gateway, and this server could not reach it. Either the gateway is briefly unavailable or outgoing HTTPS is blocked.', 'miniorange-secure-mcp-server' ),
			'steps' => array(
				self::step( __( 'Allow outgoing HTTPS from this server to this host in your firewall or host panel:', 'miniorange-secure-mcp-server' ), 'gateway.miniorange.ai' ),
				self::step( __( 'Try again in a few minutes in case it was a brief outage.', 'miniorange-secure-mcp-server' ) ),
			),
		);
	}

	/**
	 * Fix: nothing automatic applies.
	 *
	 * @return array
	 */
	private static function fix_contact_support() {
		return array(
			'title' => __( 'Send the diagnostics to support', 'miniorange-secure-mcp-server' ),
			'why'   => __( 'This result does not match a known cause, so we need the details to find it.', 'miniorange-secure-mcp-server' ),
			'steps' => array(
				self::step( __( 'Choose "Copy diagnostics" and paste the result into your support request. Secrets are removed from it.', 'miniorange-secure-mcp-server' ) ),
			),
		);
	}
}
