<?php
/**
 * Introspection, normalization and hard gating of the WordPress REST route table.
 *
 * @package Miniorange_Secure_MCP_Server
 */

namespace MoSMCP\Common\Services\Rest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Route_Registry
 *
 * Reads the live REST route table and normalizes each registered regex pattern into a
 * readable template ('/wp/v2/posts/(?P<id>[\d]+)' becomes '/wp/v2/posts/{id}') that
 * admins grant against and agents see. Also owns the hard blocklist: routes that no
 * grant may ever open, because reaching them would bypass this plugin's own governance.
 */
class Route_Registry {

	/**
	 * Transient holding the normalized route table.
	 */
	const CACHE_KEY = 'mosmcp_rest_routes';

	/**
	 * Route prefixes no grant may ever open, mapped to the reason each is unreachable.
	 */
	const BLOCKED_PREFIXES = array(
		'/mosmcp/v1'    => 'This plugin\'s own control plane: reaching it would let an agent mint NHIs, rewrite grants or read audit logs.',
		'/wp-abilities' => 'Core\'s abilities REST surface would let an agent execute abilities its NHI was never granted.',
		'/batch'        => 'Batching collapses many operations into a single audit record, destroying per-call attribution.',
		'/oembed'       => 'No agent value, and served unauthenticated by design.',
	);

	/**
	 * Route prefixes where every write method is blocked; reads stay grantable.
	 */
	const READ_ONLY_PREFIXES = array(
		'/wp/v2/plugins' => 'Installing or activating a plugin is arbitrary code execution.',
		'/wp/v2/themes'  => 'Installing or switching a theme is arbitrary code execution.',
	);

	/**
	 * Path segments that carry no agent value and are kept out of the route table.
	 *
	 * 'batch' catches per-resource bulk endpoints a plugin registers under its own
	 * namespace (e.g. /wc/v3/products/batch) the same way /batch/v1 is blocked above —
	 * matched as a path segment, not just a prefix, since these live anywhere in a
	 * namespace's own route tree, not at its root.
	 */
	const BLOCKED_SEGMENTS = array( 'autosaves', 'batch' );

	/**
	 * Write-method-only segment block. Anywhere, in any namespace, a path containing
	 * one of these words is almost certainly installing, removing, or (de)activating
	 * software — the same "arbitrary code execution" risk READ_ONLY_PREFIXES exists
	 * for on core's own /wp/v2/plugins and /wp/v2/themes, generalized to every plugin
	 * that registers its own installer-style route (WooCommerce's onboarding wizard,
	 * a forms plugin's "install this add-on" endpoint, and so on) rather than only the
	 * two core paths. Reads are left alone, same as READ_ONLY_PREFIXES, since a GET
	 * can't execute anything.
	 */
	const WRITE_BLOCKED_SEGMENTS = array(
		'install'    => 'Installing software through a REST route is arbitrary code execution, wherever the route lives.',
		'uninstall'  => 'Removing software through a REST route is arbitrary code execution, wherever the route lives.',
		'activate'   => 'Activating or switching software through a REST route is arbitrary code execution, wherever the route lives.',
		'deactivate' => 'Deactivating software through a REST route is arbitrary code execution, wherever the route lives.',
		// Deliberately narrower than a general "import" block: this plugin reasonably
		// expects to support legitimate content importers (CSV products, subscriber
		// lists, and the like), which a blanket "import" match would also catch.
		// "blueprint" specifically names the newer site-configuration-as-code pattern
		// (WooCommerce's Blueprint, WordPress Playground's own Blueprints) that can
		// install and configure plugins and themes as part of applying it — the same
		// risk class as 'install' above, just reached through a different verb.
		'blueprint'  => 'Applying a site blueprint can install and configure plugins and themes, the same risk as installing one directly.',
	);

	/**
	 * Grantable route prefixes an admin should think twice about, and why.
	 */
	const SENSITIVE_PREFIXES = array(
		'/wp/v2/settings' => 'Site-wide settings, including the site title, admin email and default roles.',
		'/wp/v2/users'    => 'Account data for every user on the site, including email addresses.',
		'/wp/v2/plugins'  => 'The list of installed plugins, which reveals the site\'s attack surface.',
		'/wp/v2/themes'   => 'The list of installed themes.',
	);

	/**
	 * Returns why a route deserves a warning in the admin UI, if it does.
	 *
	 * @param string $route Route pattern or template.
	 * @return string Empty string when unremarkable, otherwise the reason to be careful.
	 */
	public static function sensitive( $route ) {
		foreach ( self::SENSITIVE_PREFIXES as $prefix => $why ) {
			if ( 0 === strpos( $route, $prefix ) ) {
				return $why;
			}
		}

		return '';
	}

	/**
	 * Returns the normalized, gated route table.
	 *
	 * @return list<array<string, mixed>> Entries of template, pattern, namespace, methods, accepts.
	 */
	public static function routes() {
		$signature = self::signature();
		$cached    = get_transient( self::CACHE_KEY );

		// Rebuild when the live route set differs, so a route added without an activation flush still shows up.
		if ( is_array( $cached ) && isset( $cached['signature'], $cached['entries'] ) && $cached['signature'] === $signature ) {
			return $cached['entries'];
		}

		$entries = self::build();
		set_transient(
			self::CACHE_KEY,
			array(
				'signature' => $signature,
				'entries'   => $entries,
			),
			HOUR_IN_SECONDS
		);

		return $entries;
	}

	/**
	 * Fingerprints the live route table: each pattern with the verbs its handlers answer to.
	 *
	 * @return string
	 */
	private static function signature() {
		$map = array();

		foreach ( rest_get_server()->get_routes() as $pattern => $handlers ) {
			$verbs = array();

			foreach ( $handlers as $handler ) {
				$verbs = array_merge( $verbs, array_keys( (array) $handler['methods'] ) );
			}

			sort( $verbs );
			$map[ $pattern ] = $verbs;
		}

		ksort( $map );

		return md5( (string) wp_json_encode( $map ) );
	}

	/**
	 * Drops the cached route table.
	 *
	 * @return void
	 */
	public static function flush() {
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * Resolves a concrete request path to its registered route entry.
	 *
	 * Exact matches win over parameterized ones so '/wp/v2/posts' can never be
	 * captured by a pattern such as '/wp/v2/(?P<taxonomy>[\w-]+)'.
	 *
	 * @param string $path Request path, with or without a query string.
	 * @return array<string, mixed>|null The matching entry, or null when nothing matches.
	 */
	public static function resolve( $path ) {
		$path = self::normalize_path( $path );

		if ( '' === $path ) {
			return null;
		}

		$entries = self::routes();

		foreach ( $entries as $entry ) {
			if ( $entry['pattern'] === $path ) {
				return $entry;
			}
		}

		foreach ( $entries as $entry ) {
			if ( preg_match( '@^' . $entry['pattern'] . '$@i', $path ) ) {
				return $entry;
			}
		}

		return null;
	}

	/**
	 * Finds a route entry by its exact readable template, e.g. '/wp/v2/posts/{id}'.
	 *
	 * Unlike resolve(), this does not also match a pattern or a concrete path with
	 * placeholders filled in — it is for the few call sites that hold a template
	 * verbatim (a stored grant string, a promoted route's record) and need the entry
	 * it was built from, in one place rather than duplicated per caller.
	 *
	 * @param string $template Route template.
	 * @return array<string, mixed>|null
	 */
	public static function find_by_template( $template ) {
		foreach ( self::routes() as $entry ) {
			if ( $entry['template'] === $template ) {
				return $entry;
			}
		}

		return null;
	}

	/**
	 * Strips the query string and normalizes leading and trailing slashes.
	 *
	 * @param string $path Raw path.
	 * @return string Normalized path, or an empty string for the API root.
	 */
	public static function normalize_path( $path ) {
		$path = is_string( $path ) ? trim( $path ) : '';
		$path = strtok( $path, '?' );
		$path = is_string( $path ) ? $path : '';
		$path = '/' . ltrim( $path, '/' );

		return '/' === $path ? '' : untrailingslashit( $path );
	}

	/**
	 * Returns the raw endpoint handler registered for a route pattern and wire method.
	 *
	 * Read straight from the REST server rather than the cache: handler args are large
	 * and only the two introspection tools ever need them.
	 *
	 * @param string $pattern Registered route pattern.
	 * @param string $method  HTTP method as sent by the caller.
	 * @return array<string, mixed>|null The handler, or null when the pattern or method is unknown.
	 */
	public static function handler( $pattern, $method ) {
		$routes = rest_get_server()->get_routes();

		if ( ! isset( $routes[ $pattern ] ) ) {
			return null;
		}

		foreach ( $routes[ $pattern ] as $handler ) {
			if ( ! empty( $handler['methods'][ strtoupper( (string) $method ) ] ) ) {
				return $handler;
			}
		}

		return null;
	}

	/**
	 * Decides whether a route is permanently unreachable through the bridge.
	 *
	 * @param string $route  Registered route pattern.
	 * @param string $method HTTP method.
	 * @return string Empty string when allowed, otherwise the reason it is blocked.
	 */
	public static function blocked( $route, $method ) {
		$method = strtoupper( (string) $method );
		$reason = '';

		foreach ( self::BLOCKED_PREFIXES as $prefix => $why ) {
			if ( 0 === strpos( $route, $prefix ) ) {
				$reason = $why;
				break;
			}
		}

		if ( '' === $reason ) {
			foreach ( self::BLOCKED_SEGMENTS as $segment ) {
				if ( false !== strpos( $route, '/' . $segment ) ) {
					$reason = 'This route carries no agent value.';
					break;
				}
			}
		}

		if ( '' === $reason && 'GET' !== $method ) {
			foreach ( self::READ_ONLY_PREFIXES as $prefix => $why ) {
				if ( 0 === strpos( $route, $prefix ) ) {
					$reason = $why;
					break;
				}
			}
		}

		if ( '' === $reason && 'GET' !== $method ) {
			foreach ( self::WRITE_BLOCKED_SEGMENTS as $segment => $why ) {
				if ( false !== strpos( $route, $segment ) ) {
					$reason = $why;
					break;
				}
			}
		}

		if ( '' !== $reason ) {
			return $reason;
		}

		/**
		 * Filters whether a route is blocked from the REST bridge.
		 *
		 * Returning a non-empty string blocks the route with that reason. Because the hard
		 * blocklist is evaluated first, this filter can only add to it, never unblock.
		 *
		 * @param string $reason Empty string, meaning allowed so far.
		 * @param string $route  Registered route pattern.
		 * @param string $method HTTP method.
		 */
		$filtered = apply_filters( 'mosmcp_rest_route_blocked', '', $route, $method );

		return is_string( $filtered ) ? $filtered : '';
	}

	/**
	 * Builds the normalized route table from the live REST server.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function build() {
		$routes  = rest_get_server()->get_routes();
		$entries = array();

		foreach ( $routes as $pattern => $handlers ) {
			if ( '/' === $pattern ) {
				continue;
			}

			$methods = array();
			$accepts = array();

			foreach ( $handlers as $handler ) {
				$wire = array();

				foreach ( array_keys( (array) $handler['methods'] ) as $method ) {
					if ( 'HEAD' === $method || '' !== self::blocked( $pattern, $method ) ) {
						continue;
					}

					$wire[] = $method;
				}

				if ( empty( $wire ) ) {
					continue;
				}

				// One handler answers to several verbs (EDITABLE is POST, PUT and PATCH); grants
				// are stored against a single canonical verb so admins tick one box, not three.
				$canonical = self::canonical_method( $wire );
				$methods[] = $canonical;

				foreach ( $wire as $method ) {
					$accepts[ $method ] = $canonical;
				}
			}

			if ( empty( $methods ) ) {
				continue;
			}

			$entries[] = array(
				'template'  => self::to_template( $pattern ),
				'pattern'   => $pattern,
				'namespace' => self::namespace_of( $pattern ),
				'methods'   => array_values( array_unique( $methods ) ),
				'accepts'   => $accepts,
			);
		}

		usort(
			$entries,
			static function ( $a, $b ) {
				return strcmp( $a['template'], $b['template'] );
			}
		);

		return $entries;
	}

	/**
	 * Picks the verb a multi-method handler is granted and displayed under.
	 *
	 * @param list<string> $methods Wire methods the handler answers to.
	 * @return string The canonical method.
	 */
	private static function canonical_method( array $methods ) {
		foreach ( array( 'PATCH', 'POST', 'PUT' ) as $preferred ) {
			if ( in_array( $preferred, $methods, true ) ) {
				return $preferred;
			}
		}

		return (string) reset( $methods );
	}

	/**
	 * Rewrites a registered route regex into a readable template.
	 *
	 * Walks the string with a paren-depth counter rather than a regex, because core
	 * registers nested groups such as '(?P<plugin>[^.\/]+(?:\/[^.\/]+)?)'.
	 *
	 * @param string $pattern Registered route pattern.
	 * @return string Template form, for example '/wp/v2/posts/{id}'.
	 */
	private static function to_template( $pattern ) {
		$out    = '';
		$length = strlen( $pattern );

		for ( $i = 0; $i < $length; $i++ ) {
			if ( '(' !== $pattern[ $i ] ) {
				$out .= $pattern[ $i ];
				continue;
			}

			$depth = 0;
			$end   = $i;

			for ( ; $end < $length; $end++ ) {
				if ( '\\' === $pattern[ $end ] ) {
					++$end;
					continue;
				}

				if ( '(' === $pattern[ $end ] ) {
					++$depth;
				} elseif ( ')' === $pattern[ $end ] ) {
					--$depth;

					if ( 0 === $depth ) {
						break;
					}
				}
			}

			$group = substr( $pattern, $i, $end - $i + 1 );
			$out  .= preg_match( '/^\(\?P<(\w+)>/', $group, $matches ) ? '{' . $matches[1] . '}' : '';
			$i     = $end;
		}

		return $out;
	}

	/**
	 * Lists the named path parameters a registered route pattern captures.
	 *
	 * Used by route promotion, where a promoted ability's input is flat named
	 * parameters rather than a ready-made concrete path, so the path parameters
	 * need pulling out of the agent's input before the rest can be sent as the query
	 * or body. Shares the paren-walk in {@see to_template()} rather than a regex,
	 * for the same nested-group reason.
	 *
	 * @param string $pattern Registered route pattern.
	 * @return list<string> Parameter names, in path order.
	 */
	public static function path_param_names( $pattern ) {
		$names = array();

		foreach ( self::walk_groups( $pattern ) as $group ) {
			if ( preg_match( '/^\(\?P<(\w+)>/', $group, $matches ) ) {
				$names[] = $matches[1];
			}
		}

		return $names;
	}

	/**
	 * Substitutes path parameter values into a registered route pattern, producing a
	 * concrete request path.
	 *
	 * @param string                $pattern Registered route pattern.
	 * @param array<string, scalar> $values  Parameter name => value.
	 * @return string|null The concrete path, or null when a captured name has no value.
	 */
	public static function fill_pattern( $pattern, array $values ) {
		$out  = '';
		$cursor = 0;

		foreach ( self::walk_groups( $pattern, true ) as $span ) {
			list( $start, $end, $group ) = $span;
			$out .= substr( $pattern, $cursor, $start - $cursor );
			$cursor = $end + 1;

			if ( ! preg_match( '/^\(\?P<(\w+)>/', $group, $matches ) ) {
				continue;
			}

			$name = $matches[1];

			if ( ! isset( $values[ $name ] ) || '' === $values[ $name ] ) {
				return null;
			}

			$out .= rawurlencode( (string) $values[ $name ] );
		}

		$out .= substr( $pattern, $cursor );

		return $out;
	}

	/**
	 * Walks a registered route pattern's top-level parenthesized groups.
	 *
	 * @param string $pattern     Registered route pattern.
	 * @param bool   $with_spans  When true, yields [start, end, group] triples instead
	 *                            of bare group strings.
	 * @return list<string>|list<array{0:int,1:int,2:string}>
	 */
	private static function walk_groups( $pattern, $with_spans = false ) {
		$groups = array();
		$length = strlen( $pattern );

		for ( $i = 0; $i < $length; $i++ ) {
			if ( '(' !== $pattern[ $i ] ) {
				continue;
			}

			$depth = 0;
			$end   = $i;

			for ( ; $end < $length; $end++ ) {
				if ( '\\' === $pattern[ $end ] ) {
					++$end;
					continue;
				}

				if ( '(' === $pattern[ $end ] ) {
					++$depth;
				} elseif ( ')' === $pattern[ $end ] ) {
					--$depth;

					if ( 0 === $depth ) {
						break;
					}
				}
			}

			$group    = substr( $pattern, $i, $end - $i + 1 );
			$groups[] = $with_spans ? array( $i, $end, $group ) : $group;
			$i        = $end;
		}

		return $groups;
	}

	/**
	 * Returns the REST namespace a route belongs to.
	 *
	 * @param string $pattern Registered route pattern.
	 * @return string The namespace, or an empty string when the route has none.
	 */
	private static function namespace_of( $pattern ) {
		$namespaces = rest_get_server()->get_namespaces();
		$path       = ltrim( $pattern, '/' );
		$match      = '';

		foreach ( $namespaces as $namespace ) {
			if ( 0 === strpos( $path, $namespace ) && strlen( $namespace ) > strlen( $match ) ) {
				$match = $namespace;
			}
		}

		return $match;
	}
}
