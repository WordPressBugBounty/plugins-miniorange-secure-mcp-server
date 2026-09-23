<?php
/**
 * The matching and integrity rules behind surgical content editing.
 *
 * Every method here is deliberately free of WordPress calls except where one is
 * feature-detected, so the rules can be exercised directly by tests without
 * bootstrapping WordPress. The correctness of this file is the whole safety case
 * for editing stored content in place, so it is kept separate from the abilities
 * that call it.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Editing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Content_Matcher
 *
 * Pure matching, replacement and integrity checks for stored text.
 */
class Content_Matcher {

	/**
	 * Shortest needle that may use whitespace-lenient matching.
	 *
	 * Below this a collapsed-whitespace pattern matches far too much, and the point
	 * of the ladder is to stay unambiguous rather than to match at any cost.
	 */
	const WHITESPACE_LENIENT_MIN = 12;

	/**
	 * How many characters of context a search result carries either side of a hit.
	 */
	const SNIPPET_RADIUS = 120;

	/**
	 * Computes the result of replacing one passage inside stored text.
	 *
	 * Tries byte-exact first, so an unambiguous exact hit can never be turned into
	 * an ambiguous one by a more forgiving rule. Only when exact finds nothing does
	 * it relax, first over quote and entity spelling, then over whitespace.
	 *
	 * @param string $stored      The value currently stored.
	 * @param string $old         Text to find.
	 * @param string $into        Replacement text.
	 * @param bool   $replace_all Replace every occurrence instead of requiring exactly one.
	 * @param bool   $whole_word  Require word boundaries around the match.
	 * @return array{ok:bool,code:string,result:string,count:int,mode:string,positions:int[]}
	 */
	public static function compute_replacement( $stored, $old, $into, $replace_all = false, $whole_word = false ) {
		if ( ! is_string( $stored ) ) {
			return self::outcome( false, 'not_text' );
		}

		$old  = self::desanitize( (string) $old );
		$into = self::strip_trailing_whitespace( self::desanitize( (string) $into ) );

		if ( '' === $old ) {
			return self::outcome( false, 'empty_old' );
		}

		if ( $old === $into ) {
			return self::outcome( false, 'no_change' );
		}

		$attempts = array();

		if ( $whole_word ) {
			$attempts['whole_word'] = '/\b' . preg_quote( $old, '/' ) . '\b/u';
		} else {
			$attempts['exact'] = '/' . preg_quote( $old, '/' ) . '/u';

			$lenient = self::lenient_pattern( $old );
			if ( null !== $lenient ) {
				$attempts['lenient'] = $lenient;
			}

			$escaped = self::json_escaped_pattern( $old );
			if ( null !== $escaped ) {
				$attempts['json_escaped'] = $escaped;
			}

			$loose = self::whitespace_lenient_pattern( $old );
			if ( null !== $loose ) {
				$attempts['whitespace_lenient'] = $loose;
			}
		}

		foreach ( $attempts as $mode => $pattern ) {
			$found = preg_match_all( $pattern, $stored, $matches, PREG_OFFSET_CAPTURE );

			// A pattern failure (malformed UTF-8 in the stored value, for instance)
			// must not read as "no matches"; move on to the next, less strict rule.
			if ( false === $found ) {
				continue;
			}

			if ( 0 === $found ) {
				continue;
			}

			if ( $found > 1 && ! $replace_all ) {
				$positions = array();
				foreach ( $matches[0] as $hit ) {
					$positions[] = (int) $hit[1];
				}

				return self::outcome( false, 'multiple_matches', '', $found, $mode, $positions );
			}

			// Matched in escaped form, so the replacement has to go in escaped too, or
			// a raw slash or quote lands inside a JSON string and breaks it.
			$substitute = ( 'json_escaped' === $mode ) ? self::json_inner( $into ) : $into;

			$limit  = $replace_all ? -1 : 1;
			$result = preg_replace_callback(
				$pattern,
				static function () use ( $substitute ) {
					// Returned from a callback so $ and \ in the replacement stay literal.
					return $substitute;
				},
				$stored,
				$limit
			);

			if ( null === $result ) {
				return self::outcome( false, 'replace_failed' );
			}

			return self::outcome( true, 'ok', $result, $found, $mode );
		}

		return self::outcome( false, 'no_match' );
	}

	/**
	 * Builds the outcome array every path returns.
	 *
	 * @param bool   $ok        Whether a replacement was produced.
	 * @param string $code      Machine-readable outcome code.
	 * @param string $result    Resulting text.
	 * @param int    $count     Matches found.
	 * @param string $mode      Which rule matched.
	 * @param int[]  $positions Byte offsets of the matches.
	 * @return array{ok:bool,code:string,result:string,count:int,mode:string,positions:int[]}
	 */
	private static function outcome( $ok, $code, $result = '', $count = 0, $mode = '', array $positions = array() ) {
		return array(
			'ok'        => (bool) $ok,
			'code'      => (string) $code,
			'result'    => (string) $result,
			'count'     => (int) $count,
			'mode'      => (string) $mode,
			'positions' => $positions,
		);
	}

	/**
	 * Builds a pattern tolerant of quote and entity spelling.
	 *
	 * WordPress texturises quotes on output and stores entities for ampersands and
	 * hard spaces, so text copied from a rendered page routinely differs from what
	 * is stored by exactly these characters and nothing else.
	 *
	 * @param string $old Needle.
	 * @return string|null Pattern, or null when the needle contains nothing to relax.
	 */
	public static function lenient_pattern( $old ) {
		$quoted = preg_quote( $old, '/' );

		$single = "\x01SQ\x01";
		$double = "\x01DQ\x01";
		$amp    = "\x01AMP\x01";
		$space  = "\x01SP\x01";

		/*
		 * Every spelling of the same character, longest first so an entity is consumed
		 * whole before a bare "&" can split it. The entity forms matter as much as the
		 * literal ones: WordPress writes curly quotes into stored content as numeric
		 * entities at least as often as it writes the characters themselves, so a
		 * matcher that only knows the literal form misses most real content.
		 */
		$literals = array(
			'&amp;'        => $amp,
			'&nbsp;'       => $space,
			'&#8217;'      => $single,
			'&#8216;'      => $single,
			'&#039;'       => $single,
			'&#39;'        => $single,
			'&rsquo;'      => $single,
			'&lsquo;'      => $single,
			'&#8220;'      => $double,
			'&#8221;'      => $double,
			'&ldquo;'      => $double,
			'&rdquo;'      => $double,
			'&quot;'       => $double,
			"\xC2\xA0"     => $space,
			'&'            => $amp,
			"'"            => $single,
			"\xE2\x80\x98" => $single,
			"\xE2\x80\x99" => $single,
			'"'            => $double,
			"\xE2\x80\x9C" => $double,
			"\xE2\x80\x9D" => $double,
			' '            => $space,
		);

		/*
		 * Match against the QUOTED form, because preg_quote escapes some of these
		 * characters (# among them) and an unquoted key would then never be found.
		 */
		$tokens = array();
		foreach ( $literals as $literal => $placeholder ) {
			$tokens[ preg_quote( $literal, '/' ) ] = $placeholder;
		}

		$working = str_replace( array_keys( $tokens ), array_values( $tokens ), $quoted );

		if ( $working === $quoted ) {
			return null;
		}

		$expansions = array(
			$single => "(?:&\#8217;|&\#8216;|&\#039;|&\#39;|&rsquo;|&lsquo;|['\x{2018}\x{2019}])",
			$double => "(?:&\#8220;|&\#8221;|&ldquo;|&rdquo;|&quot;|[\"\x{201C}\x{201D}])",
			$amp    => '(?:&amp;|&)',
			$space  => '(?:&nbsp;|\x{00A0}|\s)',
		);

		return '/' . str_replace( array_keys( $expansions ), array_values( $expansions ), $working ) . '/u';
	}

	/**
	 * Builds a pattern matching the needle as it would appear inside a JSON string.
	 *
	 * Page builders store their layouts as JSON in post meta, and JSON escapes
	 * forward slashes and double quotes. A URL a person reads as
	 * "https://example.com/page" is stored as "https:\/\/example.com\/page", and
	 * quoted text is stored with backslashes before every quote. Without this rung
	 * the editor works on plain sentences and silently cannot touch any link or any
	 * quoted phrase, which is most of what people actually want to change.
	 *
	 * @param string $old Needle.
	 * @return string|null Pattern, or null when escaping would change nothing.
	 */
	public static function json_escaped_pattern( $old ) {
		$inner = self::json_inner( $old );

		if ( '' === $inner || $inner === (string) $old ) {
			return null;
		}

		return '/' . preg_quote( $inner, '/' ) . '/u';
	}

	/**
	 * Returns a string as it would be written inside a JSON string, without quotes.
	 *
	 * Used for both halves of an escaped match. Matching the escaped needle but
	 * substituting the raw replacement would write an unescaped slash or quote into
	 * the middle of a JSON string and break the very structure the integrity guard
	 * is there to protect, so the replacement is escaped the same way.
	 *
	 * @param string $text Text.
	 * @return string Escaped inner form, or an empty string when it cannot be encoded.
	 */
	public static function json_inner( $text ) {
		$encoded = json_encode( (string) $text ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode

		if ( ! is_string( $encoded ) || strlen( $encoded ) < 2 ) {
			return '';
		}

		// Drop the wrapping quotes JSON encoding adds; the inner form is what appears
		// inside a stored value.
		return substr( $encoded, 1, -1 );
	}

	/**
	 * Builds a pattern that treats any run of whitespace as equivalent.
	 *
	 * Restricted to needles long enough to stay specific once whitespace stops
	 * mattering; a short phrase matched this way would hit half the document.
	 *
	 * @param string $old Needle.
	 * @return string|null Pattern, or null when the needle does not qualify.
	 */
	public static function whitespace_lenient_pattern( $old ) {
		$trimmed = trim( $old );

		if ( strlen( $trimmed ) < self::WHITESPACE_LENIENT_MIN ) {
			return null;
		}

		if ( ! preg_match( '/\s/u', $trimmed ) ) {
			return null;
		}

		$parts = preg_split( '/\s+/u', $trimmed );

		if ( ! is_array( $parts ) || count( $parts ) < 2 ) {
			return null;
		}

		$quoted = array_map(
			static function ( $part ) {
				return preg_quote( (string) $part, '/' );
			},
			$parts
		);

		return '/' . implode( '\s+', $quoted ) . '/u';
	}

	/**
	 * Reverses the token substitutions an assistant's own transport may apply.
	 *
	 * Text that arrives having passed through a model's tooling can carry shortened
	 * stand-ins for markup-like sequences. Left in place they guarantee a no-match
	 * against what is really stored.
	 *
	 * @param string $text Text as supplied.
	 * @return string
	 */
	public static function desanitize( $text ) {
		$map = array(
			'<fnr>'  => '<function_results>',
			'</fnr>' => '</function_results>',
			'<n>'    => '<name>',
			'</n>'   => '</name>',
			'<o>'    => '<output>',
			'</o>'   => '</output>',
			'<e>'    => '<error>',
			'</e>'   => '</error>',
			"\n\nH:" => "\n\nHuman:",
			"\n\nA:" => "\n\nAssistant:",
		);

		return str_replace( array_keys( $map ), array_values( $map ), (string) $text );
	}

	/**
	 * Removes trailing whitespace from each line without touching line endings.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function strip_trailing_whitespace( $text ) {
		$out = preg_replace( '/[ \t]+(?=\r?\n|$)/', '', (string) $text );

		return null === $out ? (string) $text : $out;
	}

	/**
	 * Whether a value is PHP-serialized and therefore unsafe to text-replace.
	 *
	 * A serialized string encodes the byte length of every string it contains, so
	 * changing the text without rewriting those lengths produces a value that can no
	 * longer be read back at all.
	 *
	 * @param mixed $value Stored value.
	 * @return bool
	 */
	public static function is_serialized_value( $value ) {
		if ( ! is_string( $value ) ) {
			return false;
		}

		$value = trim( $value );

		if ( strlen( $value ) < 4 ) {
			return false;
		}

		if ( ':' !== substr( $value, 1, 1 ) ) {
			return false;
		}

		return (bool) preg_match( '/^[aOsbdi]:/', $value );
	}

	/**
	 * Whether an edit would turn valid JSON into invalid JSON.
	 *
	 * @param string $before Value before the edit.
	 * @param string $after  Value after the edit.
	 * @return bool True when the edit breaks JSON that previously parsed.
	 */
	public static function json_broken_by_edit( $before, $after ) {
		$before = trim( (string) $before );

		if ( '' === $before || ( '{' !== $before[0] && '[' !== $before[0] ) ) {
			return false;
		}

		json_decode( $before );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return false;
		}

		json_decode( trim( (string) $after ) );

		return JSON_ERROR_NONE !== json_last_error();
	}

	/**
	 * Measures the structural health of block markup.
	 *
	 * Three independent signals, because each catches damage the others miss. A
	 * block whose delimiter parses but whose attributes no longer do is invisible to
	 * a delimiter count; a delimiter mangled badly enough to stop being a block at
	 * all is invisible to the parser.
	 *
	 * @param string $content Post content.
	 * @return array{openers:int,closers:int,voids:int,imbalance:int,broken_attrs:int,unparsed:int}
	 */
	public static function block_health( $content ) {
		$content = (string) $content;

		$openers = preg_match_all( '#<!--\s*wp:#i', $content );
		$closers = preg_match_all( '#<!--\s*/wp:#i', $content );
		$voids   = preg_match_all( '#/\s*-->#', $content );

		$openers = is_int( $openers ) ? $openers : 0;
		$closers = is_int( $closers ) ? $closers : 0;
		$voids   = is_int( $voids ) ? $voids : 0;

		$broken_attrs = 0;
		$parsed       = 0;

		if ( function_exists( 'parse_blocks' ) ) {
			foreach ( self::flatten_blocks( parse_blocks( $content ) ) as $block ) {
				if ( empty( $block['blockName'] ) ) {
					continue;
				}

				++$parsed;

				if ( ! isset( $block['attrs'] ) || ! is_array( $block['attrs'] ) ) {
					++$broken_attrs;
				}
			}
		} else {
			$parsed = $openers;
		}

		return array(
			'openers'      => $openers,
			'closers'      => $closers,
			'voids'        => $voids,
			'imbalance'    => abs( ( $openers - $voids ) - $closers ),
			'broken_attrs' => $broken_attrs,
			'unparsed'     => max( 0, $openers - $parsed ),
		);
	}

	/**
	 * Flattens nested blocks into one list.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @return array<int, array<string, mixed>>
	 */
	private static function flatten_blocks( array $blocks ) {
		$flat = array();

		foreach ( $blocks as $block ) {
			$flat[] = $block;

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$flat = array_merge( $flat, self::flatten_blocks( $block['innerBlocks'] ) );
			}
		}

		return $flat;
	}

	/**
	 * Whether an edit damages block markup that was previously intact.
	 *
	 * Compared as a delta rather than an absolute, so content that was already
	 * malformed before the edit can still be edited, and so deleting a whole block
	 * (which removes a matched opener and closer together) stays legal.
	 *
	 * @param string $before Content before the edit.
	 * @param string $after  Content after the edit.
	 * @return bool True when any structural signal got worse.
	 */
	public static function blocks_broken_by_edit( $before, $after ) {
		$b = self::block_health( $before );

		// Nothing block-shaped to damage.
		if ( 0 === $b['openers'] ) {
			return false;
		}

		$a = self::block_health( $after );

		return $a['broken_attrs'] > $b['broken_attrs']
			|| $a['unparsed'] > $b['unparsed']
			|| $a['imbalance'] > $b['imbalance'];
	}

	/**
	 * Finds every occurrence of a pattern, with surrounding context.
	 *
	 * Uses the same ladder as the replacement path, so anything this returns is
	 * guaranteed to be something the replacement can act on.
	 *
	 * @param string $haystack Text to search.
	 * @param string $needle   Text to find.
	 * @param int    $max      Maximum matches to return.
	 * @return array{mode:string,total:int,matches:array<int, array<string, mixed>>}
	 */
	public static function find_matches( $haystack, $needle, $max = 20 ) {
		$haystack = (string) $haystack;
		$needle   = self::desanitize( (string) $needle );
		$max      = max( 1, (int) $max );

		if ( '' === $needle ) {
			return array(
				'mode'    => '',
				'total'   => 0,
				'matches' => array(),
			);
		}

		$patterns = array( 'exact' => '/' . preg_quote( $needle, '/' ) . '/u' );

		$lenient = self::lenient_pattern( $needle );
		if ( null !== $lenient ) {
			$patterns['lenient'] = $lenient;
		}

		$loose = self::whitespace_lenient_pattern( $needle );
		if ( null !== $loose ) {
			$patterns['whitespace_lenient'] = $loose;
		}

		foreach ( $patterns as $mode => $pattern ) {
			$found = preg_match_all( $pattern, $haystack, $hits, PREG_OFFSET_CAPTURE );

			if ( false === $found || 0 === $found ) {
				continue;
			}

			$matches = array();

			foreach ( array_slice( $hits[0], 0, $max ) as $hit ) {
				$offset  = (int) $hit[1];
				$text    = (string) $hit[0];
				$start   = max( 0, $offset - self::SNIPPET_RADIUS );
				$snippet = substr( $haystack, $start, strlen( $text ) + ( self::SNIPPET_RADIUS * 2 ) );

				$matches[] = array(
					'offset'          => $offset,
					'matched_text'    => $text,
					'context'         => (string) $snippet,
					'context_trimmed' => ( $start > 0 || ( $start + strlen( (string) $snippet ) ) < strlen( $haystack ) ),
				);
			}

			return array(
				'mode'    => $mode,
				'total'   => $found,
				'matches' => $matches,
			);
		}

		return array(
			'mode'    => '',
			'total'   => 0,
			'matches' => array(),
		);
	}

	/**
	 * Suggests passages that resemble a needle which did not match.
	 *
	 * A bare "not found" leaves a caller guessing at the stored wording, which is
	 * how an edit loop starts. Anchoring on the head and tail of the needle usually
	 * lands on the real text and shows exactly how it differs.
	 *
	 * @param string $haystack Text that was searched.
	 * @param string $needle   Text that was not found.
	 * @param int    $max      Maximum suggestions.
	 * @return string[] Excerpts of the stored text.
	 */
	public static function near_misses( $haystack, $needle, $max = 3 ) {
		$haystack = (string) $haystack;
		$needle   = trim( (string) $needle );
		$out      = array();

		if ( '' === $haystack || strlen( $needle ) < 8 ) {
			return $out;
		}

		$anchors = array(
			substr( $needle, 0, 30 ),
			substr( $needle, 0, 16 ),
			substr( $needle, -30 ),
			substr( $needle, -16 ),
		);

		$seen = array();

		foreach ( $anchors as $anchor ) {
			$anchor = trim( (string) $anchor );

			if ( strlen( $anchor ) < 6 ) {
				continue;
			}

			$pattern = self::lenient_pattern( $anchor );
			$pattern = ( null === $pattern ) ? '/' . preg_quote( $anchor, '/' ) . '/u' : $pattern;

			if ( ! preg_match( $pattern, $haystack, $m, PREG_OFFSET_CAPTURE ) ) {
				continue;
			}

			$offset = (int) $m[0][1];
			$bucket = (int) floor( $offset / 160 );

			if ( isset( $seen[ $bucket ] ) ) {
				continue;
			}

			$seen[ $bucket ] = true;
			$start           = max( 0, $offset - 40 );
			$out[]           = (string) substr( $haystack, $start, 200 );

			if ( count( $out ) >= $max ) {
				break;
			}
		}

		return $out;
	}
}
