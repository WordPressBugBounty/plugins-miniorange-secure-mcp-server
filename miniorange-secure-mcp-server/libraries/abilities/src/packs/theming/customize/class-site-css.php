<?php
/**
 * Additional CSS: where it lives for the active theme, what it will be once the
 * theme draft is published, and whether a new version is safe to stage.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Theming\Customize;

use MoSMCP\Abilities\Packs\Site\Site_Support;
use WP_Error;
use MoSMCP\Abilities\Packs\Theming\Draft\Theme_Publisher;
use MoSMCP\Abilities\Packs\Theming\Theme_Support;

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
 * Class Site_Css
 *
 * A site can hold Additional CSS in two places, and WordPress prints both:
 *
 *  - global_styles: the Site Editor's Additional CSS (styles.css in the user's
 *    global styles). Only block themes print it. It is staged inside the draft's
 *    global-styles change, so it previews and publishes with the other styles.
 *  - customizer: the Customizer's Additional CSS (a custom_css post per theme),
 *    printed for every theme. It is staged in the draft under "css".
 */
class Site_Css {

	/**
	 * Largest CSS accepted, in bytes.
	 */
	const MAX_BYTES = 512000;

	/**
	 * The place a request means; "auto" is the active theme's own place.
	 *
	 * @param string $requested "auto", "global_styles" or "customizer".
	 * @return string|WP_Error
	 */
	public static function target( $requested ) {
		$block = Theme_Support::is_block_theme();
		if ( 'customizer' === $requested ) {
			return 'customizer';
		}
		if ( 'global_styles' === $requested && ! $block ) {
			return Site_Support::error(
				'mosmcp_css_target_unavailable',
				__( 'The active theme is a classic theme, which does not print the Site Editor\'s Additional CSS, so CSS there would have no effect. Leave target as "auto" to use the Customizer\'s Additional CSS, which this theme does print.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_INVALID_INPUT,
				false
			);
		}
		return $block ? 'global_styles' : 'customizer';
	}

	/**
	 * The live CSS in a place.
	 *
	 * @param string $target Place.
	 * @return string
	 */
	public static function live( $target ) {
		if ( 'customizer' === $target ) {
			$post = wp_get_custom_css_post( Theme_Support::stylesheet() );
			return $post ? (string) $post->post_content : '';
		}
		$user = Theme_Publisher::live_user_styles();
		return isset( $user['styles']['css'] ) ? (string) $user['styles']['css'] : '';
	}

	/**
	 * The CSS in a place once the draft is published.
	 *
	 * @param array<string, mixed>|null $draft  Draft.
	 * @param string                    $target Place.
	 * @return string
	 */
	public static function effective( $draft, $target ) {
		if ( 'customizer' === $target ) {
			return is_array( $draft ) && ! empty( $draft['css'] ) ? (string) $draft['css']['value'] : self::live( $target );
		}
		if ( is_array( $draft ) && ! empty( $draft['styles'] ) ) {
			$user = (array) $draft['styles']['user_data'];
			return isset( $user['styles']['css'] ) ? (string) $user['styles']['css'] : '';
		}
		return self::live( $target );
	}

	/**
	 * Whether the draft changes the CSS in a place.
	 *
	 * @param array<string, mixed>|null $draft  Draft.
	 * @param string                    $target Place.
	 * @return bool
	 */
	public static function pending( $draft, $target ) {
		return self::effective( $draft, $target ) !== self::live( $target );
	}

	/**
	 * Refuses CSS WordPress would refuse, or that would break the rules after it.
	 *
	 * The markup rule is core's own (the Customizer and the Site Editor apply it):
	 * the CSS is printed inside a STYLE element, so it must not close one, nor end
	 * with the start of a closing tag. An unbalanced brace is accepted by
	 * WordPress but silently swallows every rule that follows, so it is refused
	 * unless the CSS was already unbalanced before this change.
	 *
	 * @param string $css      CSS.
	 * @param string $previous CSS before the change.
	 * @return true|WP_Error
	 */
	public static function check( $css, $previous ) {
		if ( strlen( $css ) > self::MAX_BYTES ) {
			return Site_Support::error(
				'mosmcp_css_too_large',
				sprintf(
					/* translators: %d: size limit in kilobytes. */
					__( 'That CSS is larger than %d KB, too large to stage as Additional CSS. Move most of it into a theme stylesheet instead.', 'mosmcp-abilities' ),
					(int) ( self::MAX_BYTES / 1000 )
				),
				Site_Support::CAUSE_INVALID_INPUT,
				false
			);
		}

		$tail = strtolower( substr( $css, -7 ) );
		for ( $length = 1; $length <= 7; $length++ ) {
			if ( substr( $tail, -$length ) === substr( '</style', 0, $length ) ) {
				return self::markup_error();
			}
		}
		if ( false !== stripos( $css, '</style' ) ) {
			return self::markup_error();
		}

		$balance = self::brace_balance( $css );
		if ( 0 !== $balance && 0 === self::brace_balance( $previous ) ) {
			return Site_Support::error(
				'mosmcp_css_unbalanced',
				$balance > 0
					/* translators: %d: number of braces. */
					? sprintf( _n( 'The CSS would have %d "{" without a matching "}", which makes browsers ignore every rule after it. Close each rule before staging it.', 'The CSS would have %d "{" without a matching "}", which makes browsers ignore every rule after it. Close each rule before staging it.', $balance, 'mosmcp-abilities' ), $balance )
					/* translators: %d: number of braces. */
					: sprintf( _n( 'The CSS would have %d "}" without a matching "{", which makes browsers misread the rules around it. Remove the extra brace before staging it.', 'The CSS would have %d "}" without a matching "{", which makes browsers misread the rules around it. Remove the extra brace before staging it.', -$balance, 'mosmcp-abilities' ), -$balance ),
				Site_Support::CAUSE_INVALID_INPUT,
				true
			);
		}

		return true;
	}

	/**
	 * Unclosed "{" (positive) or unopened "}" (negative), ignoring comments and strings.
	 *
	 * @param string $css CSS.
	 * @return int
	 */
	public static function brace_balance( $css ) {
		$code  = (string) preg_replace( '#/\*.*?(\*/|$)#s', '', (string) $css );
		$code  = (string) preg_replace( '/"(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\'/s', '', $code );
		$depth = 0;
		$low   = 0;
		foreach ( str_split( $code ) as $char ) {
			if ( '{' === $char ) {
				++$depth;
			} elseif ( '}' === $char ) {
				--$depth;
				$low = min( $low, $depth );
			}
		}
		return $low < 0 ? $low : $depth;
	}

	/**
	 * Applies an edit to CSS.
	 *
	 * @param string               $current Current CSS.
	 * @param array<string, mixed> $input   Input: mode, css, find, replace_with, all.
	 * @return array{css:string,label:string,matches:int}|WP_Error
	 */
	public static function change( $current, array $input ) {
		$mode = isset( $input['mode'] ) ? (string) $input['mode'] : 'append';
		$css  = isset( $input['css'] ) ? str_replace( "\r\n", "\n", (string) $input['css'] ) : '';

		if ( 'replace' === $mode ) {
			return array(
				'css'     => trim( $css ),
				'label'   => '' === trim( $css ) ? __( 'additional CSS removed', 'mosmcp-abilities' ) : sprintf(
					/* translators: %d: number of lines. */
					_n( 'additional CSS replaced (%d line)', 'additional CSS replaced (%d lines)', self::lines( $css ), 'mosmcp-abilities' ),
					self::lines( $css )
				),
				'matches' => 0,
			);
		}

		if ( 'edit' === $mode ) {
			$find = isset( $input['find'] ) ? str_replace( "\r\n", "\n", (string) $input['find'] ) : '';
			if ( '' === $find ) {
				return Site_Support::error( 'mosmcp_css_find_required', __( 'Edit mode needs "find": the exact CSS text to change, as mosmcp/site-css-get shows it.', 'mosmcp-abilities' ), Site_Support::CAUSE_INVALID_INPUT, false );
			}
			$count = substr_count( $current, $find );
			if ( 0 === $count ) {
				return Site_Support::error(
					'mosmcp_css_find_missing',
					__( 'That text does not appear in the Additional CSS exactly as given (spaces and line breaks count). Read the current CSS with mosmcp/site-css-get and copy the part to change from it.', 'mosmcp-abilities' ),
					Site_Support::CAUSE_INVALID_INPUT,
					false,
					array( 'use_instead' => 'mosmcp/site-css-get' )
				);
			}
			if ( $count > 1 && empty( $input['all'] ) ) {
				return Site_Support::error(
					'mosmcp_css_find_ambiguous',
					sprintf(
						/* translators: %d: number of matches. */
						__( 'That text appears %d times in the Additional CSS, so it is unclear which one to change. Include more of the surrounding CSS so it matches once, or set all to true to change every occurrence.', 'mosmcp-abilities' ),
						$count
					),
					Site_Support::CAUSE_INVALID_INPUT,
					false
				);
			}
			$with = isset( $input['replace_with'] ) ? str_replace( "\r\n", "\n", (string) $input['replace_with'] ) : '';
			$new  = empty( $input['all'] ) ? (string) substr_replace( $current, $with, (int) strpos( $current, $find ), strlen( $find ) ) : str_replace( $find, $with, $current );
			return array(
				'css'     => trim( $new ),
				'label'   => sprintf(
					/* translators: %d: number of places changed. */
					_n( 'additional CSS edited (%d place)', 'additional CSS edited (%d places)', $count, 'mosmcp-abilities' ),
					empty( $input['all'] ) ? 1 : $count
				),
				'matches' => $count,
			);
		}

		if ( '' === trim( $css ) ) {
			return Site_Support::error( 'mosmcp_css_required', __( 'Give the CSS to add in "css".', 'mosmcp-abilities' ), Site_Support::CAUSE_INVALID_INPUT, false );
		}
		return array(
			'css'     => trim( '' === trim( $current ) ? $css : rtrim( $current ) . "\n\n" . trim( $css ) ),
			'label'   => sprintf(
				/* translators: %d: number of lines. */
				_n( 'additional CSS added (%d line)', 'additional CSS added (%d lines)', self::lines( $css ), 'mosmcp-abilities' ),
				self::lines( $css )
			),
			'matches' => 0,
		);
	}

	/**
	 * Number of non-empty lines.
	 *
	 * @param string $css CSS.
	 * @return int
	 */
	private static function lines( $css ) {
		return count( array_filter( array_map( 'trim', explode( "\n", trim( (string) $css ) ) ), 'strlen' ) );
	}

	/**
	 * Core's refusal for CSS that could close its STYLE element.
	 *
	 * @return WP_Error
	 */
	private static function markup_error() {
		return Site_Support::error(
			'mosmcp_css_markup',
			__( 'Markup is not allowed in CSS: it must not contain "</style", nor end with the start of it. Remove the HTML tag.', 'mosmcp-abilities' ),
			Site_Support::CAUSE_INVALID_INPUT,
			false
		);
	}
}
