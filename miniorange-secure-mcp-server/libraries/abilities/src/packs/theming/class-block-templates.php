<?php
/**
 * Reads block templates and template parts in their three states: as the theme
 * ships them, as the live site serves them, and as the draft would make them.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Theming;

use MoSMCP\Abilities\Packs\Site\Site_Support;
use WP_Block_Template;
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
 * directive covers IDE and Plugin Check runs that don't read that ruleset).
 */
// phpcs:disable WordPress.WP.I18n.TextDomainMismatch

/**
 * Class Block_Templates
 *
 * A block template lives in a theme file until someone edits it in the Site
 * Editor; from then on WordPress serves a database copy that overrides the file,
 * and deleting that copy reverts to the file. Every ability that touches templates
 * has to respect that, or its edits silently do nothing on a customised site.
 */
class Block_Templates {

	/**
	 * Template post types this handles.
	 *
	 * @var string[]
	 */
	const TYPES = array( 'wp_template', 'wp_template_part' );

	/**
	 * Full template ID ("theme//slug") for the live theme.
	 *
	 * @param string $slug Slug.
	 * @return string
	 */
	public static function id( $slug ) {
		return Theme_Support::stylesheet() . '//' . $slug;
	}

	/**
	 * Every template or part the live site knows about.
	 *
	 * @param string $type Template type.
	 * @return WP_Block_Template[]
	 */
	public static function all( $type ) {
		$list = get_block_templates( array(), $type );
		return is_array( $list ) ? $list : array();
	}

	/**
	 * The template or part as the live site serves it.
	 *
	 * @param string $type Template type.
	 * @param string $slug Slug.
	 * @return WP_Block_Template|null
	 */
	public static function live( $type, $slug ) {
		$template = get_block_template( self::id( $slug ), $type );
		return $template instanceof WP_Block_Template ? $template : null;
	}

	/**
	 * The template or part exactly as the theme's own file ships it.
	 *
	 * @param string $type Template type.
	 * @param string $slug Slug.
	 * @return WP_Block_Template|null
	 */
	public static function theme_file( $type, $slug ) {
		$template = get_block_file_template( self::id( $slug ), $type );
		return $template instanceof WP_Block_Template ? $template : null;
	}

	/**
	 * Where a template's live content comes from.
	 *
	 * @param WP_Block_Template $template Template.
	 * @return string "theme", "customised", "user" or "plugin".
	 */
	public static function origin( WP_Block_Template $template ) {
		if ( 'plugin' === $template->source || ( isset( $template->origin ) && 'plugin' === $template->origin ) ) {
			return 'plugin';
		}
		if ( 'custom' === $template->source ) {
			return ! empty( $template->has_theme_file ) ? 'customised' : 'user';
		}
		return 'theme';
	}

	/**
	 * The content a template would have once the draft is published.
	 *
	 * @param array<string, mixed>|null $draft Draft.
	 * @param string                    $type  Template type.
	 * @param string                    $slug  Slug.
	 * @return string|null Null when the template does not exist at all.
	 */
	public static function effective_content( $draft, $type, $slug ) {
		$entry = Theme_Draft::template_entry( $draft, $type, $slug );

		if ( null !== $entry ) {
			if ( 'update' === $entry['action'] ) {
				return (string) $entry['content'];
			}
			$file = self::theme_file( $type, $slug );
			return $file ? (string) $file->content : null;
		}

		$live = self::live( $type, $slug );
		return $live ? (string) $live->content : null;
	}

	/**
	 * Templates and parts that place a pattern, as they will be once the draft is
	 * published — so deleting a pattern file can say what goes blank.
	 *
	 * @param array<string, mixed>|null $draft Draft.
	 * @param string                    $slug  Pattern slug, e.g. "twentytwentyfive/footer".
	 * @return string[] Labels such as 'the template part "footer"'.
	 */
	public static function pattern_users( $draft, $slug ) {
		$needle = '/<!--\s+wp:pattern\s+\{[^}]*"slug"\s*:\s*"' . preg_quote( $slug, '/' ) . '"/';
		$users  = array();
		foreach ( self::TYPES as $type ) {
			foreach ( self::all( $type ) as $template ) {
				if ( preg_match( $needle, (string) self::effective_content( $draft, $type, $template->slug ) ) ) {
					$users[] = sprintf( 'wp_template_part' === $type ? 'the template part "%s"' : 'the template "%s"', $template->slug );
				}
			}
		}
		return $users;
	}

	/**
	 * Warnings for pattern files the draft deletes while something still places them.
	 *
	 * @param array<string, mixed>|null $draft Draft.
	 * @return string[]
	 */
	public static function deleted_pattern_warnings( $draft ) {
		$warnings = array();
		if ( ! is_array( $draft ) || empty( $draft['files']['touched'] ) ) {
			return $warnings;
		}
		foreach ( (array) $draft['files']['touched'] as $relative => $action ) {
			if ( 'delete' !== $action || 0 !== strpos( $relative, 'patterns/' ) ) {
				continue;
			}
			$live = Theme_Support::live_dir() . '/' . $relative;
			$slug = is_file( $live ) ? (string) get_file_data( $live, array( 'slug' => 'Slug' ) )['slug'] : '';
			if ( '' === $slug ) {
				continue;
			}
			$users = self::pattern_users( $draft, $slug );
			if ( ! empty( $users ) ) {
				$warnings[] = sprintf(
					/* translators: 1: file, 2: pattern slug, 3: comma-separated list of templates. */
					__( 'Deleting %1$s removes the pattern %2$s, which %3$s still places, so that area will show nothing once published. Check the preview, or keep the file.', 'mosmcp-abilities' ),
					$relative,
					$slug,
					implode( ', ', $users )
				);
			}
		}
		return $warnings;
	}

	/**
	 * Fingerprint of a template's live state, used as a publish baseline.
	 *
	 * @param WP_Block_Template|null $template Live template.
	 * @return string
	 */
	public static function baseline( $template ) {
		if ( ! $template instanceof WP_Block_Template ) {
			return Theme_Draft::fingerprint( 'missing' );
		}
		return Theme_Draft::fingerprint( $template->source . '|' . (int) $template->wp_id . '|' . (string) $template->content );
	}

	/**
	 * Describes a template for ability output.
	 *
	 * @param WP_Block_Template         $template     Template.
	 * @param array<string, mixed>|null $draft        Draft.
	 * @param bool                      $with_content Include the effective content.
	 * @return array<string, mixed>
	 */
	public static function describe( WP_Block_Template $template, $draft, $with_content = false ) {
		$entry  = Theme_Draft::template_entry( $draft, $template->type, $template->slug );
		$origin = self::origin( $template );

		$described = array(
			'slug'           => (string) $template->slug,
			'id'             => (string) $template->id,
			'title'          => is_string( $template->title ) ? $template->title : '',
			'description'    => is_string( $template->description ) ? $template->description : '',
			'type'           => (string) $template->type,
			'area'           => isset( $template->area ) ? (string) $template->area : '',
			'origin'         => $origin,
			'can_reset'      => 'customised' === $origin,
			'modified'       => isset( $template->modified ) && $template->modified ? (string) $template->modified : '',
			'pending'        => null === $entry ? '' : (string) $entry['action'],
			'content_length' => strlen( (string) $template->content ),
		);

		if ( $with_content ) {
			$effective                   = self::effective_content( $draft, $template->type, $template->slug );
			$described['content']        = (string) $effective;
			$described['content_source'] = null === $entry ? 'live' : 'draft';
		}

		return $described;
	}

	/**
	 * Inlines the theme patterns a template or part references.
	 *
	 * Block themes often build a part out of nothing but a pattern reference —
	 * Twenty Twenty-Five's footer is the single line
	 * <!-- wp:pattern {"slug":"twentytwentyfive/footer"} /--> — so the wording a
	 * person wants to change is not in the part at all. The Site Editor resolves
	 * this by copying the pattern's blocks into the part when it is edited; this
	 * does the same. Only the reference comment itself is replaced, so every other
	 * byte of the content is untouched. Nested references are followed a few levels
	 * deep; unknown patterns are left as they are.
	 *
	 * @param string   $content Block markup.
	 * @param string[] $slugs   Filled with the slugs that were inlined.
	 * @return string
	 */
	public static function expand_patterns( $content, array &$slugs = array() ) {
		if ( ! class_exists( '\WP_Block_Patterns_Registry' ) || false === strpos( (string) $content, 'wp:pattern' ) ) {
			return (string) $content;
		}

		$registry = \WP_Block_Patterns_Registry::get_instance();
		$content  = (string) $content;

		for ( $depth = 0; $depth < 5; $depth++ ) {
			$changed = false;
			$content = (string) preg_replace_callback(
				'#<!--\s+wp:pattern\s+(\{.*?\})\s+/-->#s',
				static function ( $found ) use ( $registry, &$slugs, &$changed ) {
					$attrs = json_decode( $found[1], true );
					$slug  = is_array( $attrs ) && isset( $attrs['slug'] ) ? (string) $attrs['slug'] : '';
					if ( '' === $slug || ! $registry->is_registered( $slug ) ) {
						return $found[0];
					}
					$pattern = $registry->get_registered( $slug );
					if ( ! is_array( $pattern ) || ! isset( $pattern['content'] ) ) {
						return $found[0];
					}
					$slugs[] = $slug;
					$changed = true;
					return trim( (string) $pattern['content'] );
				},
				$content
			);
			if ( ! $changed ) {
				break;
			}
		}

		$slugs = array_values( array_unique( $slugs ) );

		return $content;
	}

	/**
	 * Finds a template or part by slug, with a helpful refusal when it is missing.
	 *
	 * @param string $type Template type.
	 * @param string $slug Slug as supplied.
	 * @return WP_Block_Template|WP_Error
	 */
	public static function require_template( $type, $slug ) {
		$slug     = sanitize_title( (string) $slug );
		$template = '' !== $slug ? self::live( $type, $slug ) : null;

		if ( $template instanceof WP_Block_Template ) {
			return $template;
		}

		$known = array();
		foreach ( self::all( $type ) as $candidate ) {
			$known[] = $candidate->slug;
		}
		sort( $known );

		$is_part = 'wp_template_part' === $type;

		return Site_Support::error(
			$is_part ? 'mosmcp_template_part_not_found' : 'mosmcp_template_not_found',
			sprintf(
				/* translators: 1: "template" or "template part", 2: the slug supplied, 3: comma-separated known slugs. */
				__( 'The active theme has no %1$s called "%2$s". The ones it has are: %3$s.', 'mosmcp-abilities' ),
				$is_part ? __( 'template part', 'mosmcp-abilities' ) : __( 'template', 'mosmcp-abilities' ),
				$slug,
				empty( $known ) ? __( 'none', 'mosmcp-abilities' ) : implode( ', ', $known )
			),
			Site_Support::CAUSE_NOT_FOUND,
			false,
			array( 'use_instead' => $is_part ? 'mosmcp/theme-part-list' : 'mosmcp/theme-template-list' )
		);
	}
}
