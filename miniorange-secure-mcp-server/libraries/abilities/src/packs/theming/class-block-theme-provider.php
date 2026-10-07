<?php
/**
 * Execute logic for the block-theme abilities: templates, template parts, global
 * styles, style variations and patterns.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Theming;

use MoSMCP\Abilities\Packs\Editing\Content_Matcher;
use MoSMCP\Abilities\Packs\Editing\Editing_Support;
use MoSMCP\Abilities\Packs\Site\Site_Support;
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
 * Class Block_Theme_Provider
 *
 * Reads always show the draft's view where one exists, so a sequence of edits
 * builds on itself. Every change is staged in the draft; none reaches visitors
 * until mosmcp/theme-draft-publish.
 */
class Block_Theme_Provider {

	/**
	 * Largest theme.json subtree returned whole.
	 */
	const MAX_JSON_BYTES = 65536;

	/**
	 * Style variation kinds, in the order they are listed: a whole look, a colour
	 * palette only, a typography set only (theme styles/colors and styles/typography).
	 */
	const VARIATION_KINDS = array( 'style', 'colours', 'typography' );

	/*
	 * Templates and parts: read
	 */

	/**
	 * Lists templates.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function template_list( $input = array() ) {
		return self::list_templates( 'wp_template', is_array( $input ) ? $input : array() );
	}

	/**
	 * Lists template parts.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function part_list( $input = array() ) {
		return self::list_templates( 'wp_template_part', is_array( $input ) ? $input : array() );
	}

	/**
	 * Gets a template.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function template_get( $input = array() ) {
		return self::get_template( 'wp_template', is_array( $input ) ? $input : array() );
	}

	/**
	 * Gets a template part.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function part_get( $input = array() ) {
		return self::get_template( 'wp_template_part', is_array( $input ) ? $input : array() );
	}

	/**
	 * Shared list implementation.
	 *
	 * @param string               $type  Template type.
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function list_templates( $type, array $input ) {
		$guard = Theme_Support::block_theme_required();
		if ( $guard instanceof WP_Error ) {
			return $guard;
		}

		$search = isset( $input['search'] ) ? strtolower( trim( (string) $input['search'] ) ) : '';
		$area   = isset( $input['area'] ) ? sanitize_key( (string) $input['area'] ) : '';
		$draft  = Theme_Draft::current();
		$items  = array();

		foreach ( Block_Templates::all( $type ) as $template ) {
			$described = Block_Templates::describe( $template, $draft );
			if ( '' !== $search && false === strpos( strtolower( $described['slug'] . ' ' . $described['title'] ), $search ) ) {
				continue;
			}
			if ( '' !== $area && $area !== $described['area'] ) {
				continue;
			}
			$items[] = $described;
		}

		usort(
			$items,
			static function ( $a, $b ) {
				return strcmp( $a['slug'], $b['slug'] );
			}
		);

		$customised = 0;
		foreach ( $items as $item ) {
			if ( 'customised' === $item['origin'] ) {
				++$customised;
			}
		}

		return array(
			'theme'      => Theme_Support::stylesheet(),
			'count'      => count( $items ),
			'customised' => $customised,
			'items'      => $items,
			'notes'      => array( __( 'origin "theme" means the theme file is used as shipped; "customised" means an edit made in the Site Editor overrides the theme file and can be reset; "user" means the template was created on this site and has no theme file.', 'mosmcp-abilities' ) ),
		);
	}

	/**
	 * Shared get implementation.
	 *
	 * @param string               $type  Template type.
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function get_template( $type, array $input ) {
		$guard = Theme_Support::block_theme_required();
		if ( $guard instanceof WP_Error ) {
			return $guard;
		}

		$template = Block_Templates::require_template( $type, isset( $input['slug'] ) ? $input['slug'] : '' );
		if ( is_wp_error( $template ) ) {
			return $template;
		}

		$version = isset( $input['version'] ) ? (string) $input['version'] : 'draft';
		$draft   = Theme_Draft::current();
		$result  = Block_Templates::describe( $template, $draft, true );
		$notes   = array();

		if ( 'live' === $version ) {
			$result['content']        = (string) $template->content;
			$result['content_source'] = 'live';
		} elseif ( 'theme' === $version ) {
			$file                     = Block_Templates::theme_file( $type, $template->slug );
			$result['content']        = $file ? (string) $file->content : '';
			$result['content_source'] = 'theme';
			if ( ! $file ) {
				$notes[] = __( 'This template was created on this site, so the theme has no version of it.', 'mosmcp-abilities' );
			}
		}

		if ( '' !== $result['pending'] && 'draft' === $version ) {
			$notes[] = __( 'This shows the draft version, which is not live yet. Pass version "live" to see what visitors currently get.', 'mosmcp-abilities' );
		}

		// A part that is nothing but pattern references hides its real wording.
		if ( preg_match_all( '/<!--\s+wp:pattern\s+\{"slug":"([^"]+)"[^}]*\}\s*\/-->/', (string) $result['content'], $refs ) && '' === trim( (string) preg_replace( '/<!--\s+wp:pattern\s+\{[^}]*\}\s*\/-->/', '', (string) $result['content'] ) ) ) {
			$notes[] = sprintf(
				/* translators: %s: comma-separated pattern names. */
				__( 'This only references the theme pattern %s, so its wording and layout live there. mosmcp/theme-text-find and mosmcp/theme-text-replace look inside it, and mosmcp/theme-pattern-get shows its markup.', 'mosmcp-abilities' ),
				implode( ', ', $refs[1] )
			);
		}

		$result['notes'] = $notes;

		return $result;
	}

	/*
	 * Templates and parts: draft edits
	 */

	/**
	 * Stages new content for a template.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function template_update( $input = array() ) {
		return self::stage_update( 'wp_template', is_array( $input ) ? $input : array() );
	}

	/**
	 * Stages new content for a template part.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function part_update( $input = array() ) {
		return self::stage_update( 'wp_template_part', is_array( $input ) ? $input : array() );
	}

	/**
	 * Stages a return to the theme's own version of a template.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function template_reset( $input = array() ) {
		return self::stage_reset( 'wp_template', is_array( $input ) ? $input : array() );
	}

	/**
	 * Stages a return to the theme's own version of a template part.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function part_reset( $input = array() ) {
		return self::stage_reset( 'wp_template_part', is_array( $input ) ? $input : array() );
	}

	/**
	 * Shared update staging.
	 *
	 * @param string               $type  Template type.
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function stage_update( $type, array $input ) {
		$guard = Theme_Support::block_theme_required();
		if ( $guard instanceof WP_Error ) {
			return $guard;
		}

		$template = Block_Templates::require_template( $type, isset( $input['slug'] ) ? $input['slug'] : '' );
		if ( is_wp_error( $template ) ) {
			return $template;
		}

		$draft = Theme_Draft::open();
		if ( is_wp_error( $draft ) ) {
			return $draft;
		}

		$content  = isset( $input['content'] ) ? (string) $input['content'] : '';
		$previous = Block_Templates::effective_content( $draft, $type, $template->slug );
		$label    = self::label( $type, $template->slug );

		$invalid = Theme_Support::block_markup_error( $content, $previous, $label );
		if ( $invalid instanceof WP_Error ) {
			return $invalid;
		}

		if ( $content === $previous ) {
			return self::no_change( $label );
		}

		return self::store_template_entry( $draft, $type, $template, 'update', $content, $label );
	}

	/**
	 * Shared reset staging.
	 *
	 * @param string               $type  Template type.
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function stage_reset( $type, array $input ) {
		$guard = Theme_Support::block_theme_required();
		if ( $guard instanceof WP_Error ) {
			return $guard;
		}

		$template = Block_Templates::require_template( $type, isset( $input['slug'] ) ? $input['slug'] : '' );
		if ( is_wp_error( $template ) ) {
			return $template;
		}

		$draft = Theme_Draft::open();
		if ( is_wp_error( $draft ) ) {
			return $draft;
		}

		$origin = Block_Templates::origin( $template );
		$label  = self::label( $type, $template->slug );
		$key    = Theme_Draft::template_key( $type, $template->slug );

		if ( 'user' === $origin || 'plugin' === $origin ) {
			return Site_Support::error(
				'mosmcp_template_no_theme_version',
				sprintf(
					/* translators: %s: what is being reset. */
					__( '%s has no theme version to go back to — it was created on this site or by a plugin, so resetting it would delete it outright. Edit its content instead.', 'mosmcp-abilities' ),
					ucfirst( $label )
				),
				Site_Support::CAUSE_INVALID_INPUT,
				false
			);
		}

		if ( 'theme' === $origin ) {
			// Nothing is customised live. A pending update is the only thing to undo.
			if ( isset( $draft['templates'][ $key ] ) ) {
				unset( $draft['templates'][ $key ] );
				Theme_Draft::save( $draft );
				return self::staged_result( $draft, $label, 'discarded pending change', array( __( 'It is already the theme\'s own version on the live site, so the pending change was simply dropped from the draft.', 'mosmcp-abilities' ) ) );
			}

			return Site_Support::error(
				'mosmcp_template_already_default',
				sprintf(
					/* translators: %s: what is being reset. */
					__( '%s is already the theme\'s own version, so there is nothing to reset.', 'mosmcp-abilities' ),
					ucfirst( $label )
				),
				Site_Support::CAUSE_INVALID_INPUT,
				false
			);
		}

		return self::store_template_entry( $draft, $type, $template, 'reset', '', $label );
	}

	/**
	 * Records a template entry in the draft and returns the staged result.
	 *
	 * @param array<string, mixed> $draft    Draft.
	 * @param string               $type     Template type.
	 * @param \WP_Block_Template   $template Live template.
	 * @param string               $action   "update" or "reset".
	 * @param string               $content  New content for an update.
	 * @param string               $label    Human label.
	 * @return array<string, mixed>
	 */
	private static function store_template_entry( array $draft, $type, $template, $action, $content, $label ) {
		$key      = Theme_Draft::template_key( $type, $template->slug );
		$existing = isset( $draft['templates'][ $key ] ) ? $draft['templates'][ $key ] : null;

		$draft['templates'][ $key ] = array(
			'type'     => $type,
			'slug'     => (string) $template->slug,
			'title'    => is_string( $template->title ) ? $template->title : (string) $template->slug,
			'action'   => $action,
			'content'  => $content,
			// Keep the first baseline: it is what the live site looked like when the draft started changing this.
			'baseline' => null !== $existing ? (string) $existing['baseline'] : Block_Templates::baseline( $template ),
		);

		Theme_Draft::save( $draft );

		return self::staged_result( $draft, $label, 'reset' === $action ? 'reset to theme version' : 'content updated', array() );
	}

	/*
	 * Find and replace text inside templates and parts
	 */

	/**
	 * Finds text across every template and part, draft-aware.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function text_find( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$guard = Theme_Support::block_theme_required();
		if ( $guard instanceof WP_Error ) {
			return $guard;
		}

		$text = isset( $input['text'] ) ? (string) $input['text'] : '';
		if ( '' === trim( $text ) ) {
			return Site_Support::error( 'mosmcp_empty_search_text', __( 'No text to find was given.', 'mosmcp-abilities' ), Site_Support::CAUSE_INVALID_INPUT, true );
		}

		$scope = isset( $input['scope'] ) ? (string) $input['scope'] : 'all';
		$types = 'templates' === $scope ? array( 'wp_template' ) : ( 'parts' === $scope ? array( 'wp_template_part' ) : Block_Templates::TYPES );
		$draft = Theme_Draft::current();
		$found = array();

		foreach ( $types as $type ) {
			foreach ( Block_Templates::all( $type ) as $template ) {
				$content  = (string) Block_Templates::effective_content( $draft, $type, $template->slug );
				$result   = Content_Matcher::find_matches( $content, $text, 5 );
				$patterns = array();

				// The wording may live in a theme pattern the template only references.
				if ( $result['total'] < 1 ) {
					$expanded = Block_Templates::expand_patterns( $content, $patterns );
					if ( $expanded !== $content ) {
						$result = Content_Matcher::find_matches( $expanded, $text, 5 );
					}
				}
				if ( $result['total'] < 1 ) {
					continue;
				}
				$snippets = array();
				foreach ( $result['matches'] as $match ) {
					$snippets[] = (string) $match['context'];
				}
				$found[] = array(
					'type'          => 'wp_template_part' === $type ? 'part' : 'template',
					'slug'          => (string) $template->slug,
					'title'         => is_string( $template->title ) ? $template->title : '',
					'occurrences'   => (int) $result['total'],
					'match_mode'    => (string) $result['mode'],
					'snippets'      => $snippets,
					'from_patterns' => $patterns,
				);
			}
		}

		return array(
			'searched_for' => $text,
			'items_found'  => count( $found ),
			'items'        => $found,
			'notes'        => empty( $found )
				? array( __( 'No template or template part contains that text. Page and post content is searched with mosmcp/content-find-in-posts instead.', 'mosmcp-abilities' ) )
				: array(),
		);
	}

	/**
	 * Replaces text inside one template or part, staged in the draft.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function text_replace( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$guard = Theme_Support::block_theme_required();
		if ( $guard instanceof WP_Error ) {
			return $guard;
		}

		$type     = ( isset( $input['type'] ) && 'part' === $input['type'] ) ? 'wp_template_part' : 'wp_template';
		$template = Block_Templates::require_template( $type, isset( $input['slug'] ) ? $input['slug'] : '' );
		if ( is_wp_error( $template ) ) {
			return $template;
		}

		$draft = Theme_Draft::open();
		if ( is_wp_error( $draft ) ) {
			return $draft;
		}

		$label   = self::label( $type, $template->slug );
		$find    = isset( $input['find_text'] ) ? (string) $input['find_text'] : '';
		$stored  = (string) Block_Templates::effective_content( $draft, $type, $template->slug );
		$outcome = Content_Matcher::compute_replacement(
			$stored,
			$find,
			isset( $input['replace_with'] ) ? (string) $input['replace_with'] : '',
			Site_Support::bool_input( $input, 'replace_all', false ),
			Site_Support::bool_input( $input, 'whole_word', false )
		);

		// Not in the markup itself? It may be in a theme pattern the part only references.
		$inlined = array();
		$base    = $stored;
		if ( empty( $outcome['ok'] ) && 'no_match' === $outcome['code'] ) {
			$expanded = Block_Templates::expand_patterns( $stored, $inlined );
			if ( $expanded !== $stored ) {
				$retry = Content_Matcher::compute_replacement(
					$expanded,
					$find,
					isset( $input['replace_with'] ) ? (string) $input['replace_with'] : '',
					Site_Support::bool_input( $input, 'replace_all', false ),
					Site_Support::bool_input( $input, 'whole_word', false )
				);
				if ( ! empty( $retry['ok'] ) || 'no_match' !== $retry['code'] ) {
					$outcome = $retry;
					$base    = $expanded;
				}
			}
		}

		if ( empty( $outcome['ok'] ) ) {
			// Suggest near misses from what visitors actually see, patterns included.
			$context = ( 'no_match' === $outcome['code'] && isset( $expanded ) ) ? $expanded : $base;
			return Editing_Support::match_error( $outcome, $context, $find, $label );
		}

		// Integrity is judged against what the replacement actually edited.
		$integrity = Editing_Support::integrity_error( $base, $outcome['result'], true );
		if ( $integrity instanceof WP_Error ) {
			return $integrity;
		}

		$result                = self::store_template_entry( $draft, $type, $template, 'update', (string) $outcome['result'], $label );
		$result['occurrences'] = (int) $outcome['count'];
		$result['match_mode']  = (string) $outcome['mode'];

		if ( $base !== $stored ) {
			$result['notes'][] = sprintf(
				/* translators: 1: what was changed, 2: comma-separated pattern slugs. */
				__( 'That wording was not in %1$s itself but in the theme pattern %2$s, which it only referenced. The pattern\'s blocks were copied into it so the wording could be changed — exactly what the Site Editor does when you edit it. The theme\'s own pattern file is untouched.', 'mosmcp-abilities' ),
				$label,
				implode( ', ', $inlined )
			);
		}

		if ( Content_Matcher::MODE_ACROSS_MARKUP === $outcome['mode'] ) {
			$result['notes'][] = __( 'The wording ran through formatting such as a link or bold text, so the whole passage, including that formatting, was replaced with the new text. To keep a link, include it in the replacement as HTML.', 'mosmcp-abilities' );
		} elseif ( 'exact' !== $outcome['mode'] && '' !== $outcome['mode'] ) {
			$result['notes'][] = __( 'The stored wording differed slightly from the text supplied and was matched using a tolerant rule. Check the preview reads as intended.', 'mosmcp-abilities' );
		}

		return $result;
	}

	/*
	 * Global styles
	 */

	/**
	 * Reads global styles: the presets people usually want, plus the site's own overrides.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function styles_get( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$guard = Theme_Support::global_styles_required();
		if ( $guard instanceof WP_Error ) {
			return $guard;
		}

		$draft   = Theme_Draft::current();
		$pending = ! empty( $draft['styles'] );
		$user    = $pending ? (array) $draft['styles']['user_data'] : Theme_Publisher::live_user_styles();
		$merged  = self::merged_data( $user );

		$settings = isset( $merged['settings'] ) && is_array( $merged['settings'] ) ? $merged['settings'] : array();
		$styles   = isset( $merged['styles'] ) && is_array( $merged['styles'] ) ? $merged['styles'] : array();

		$result = array(
			'theme'          => Theme_Support::stylesheet(),
			'source'         => $pending ? 'draft' : 'live',
			'has_overrides'  => ! empty( $user ),
			'palette'        => self::presets( $settings, array( 'color', 'palette' ), array( 'slug', 'name', 'color' ) ),
			'font_families'  => self::presets( $settings, array( 'typography', 'fontFamilies' ), array( 'slug', 'name', 'fontFamily' ) ),
			'font_sizes'     => self::presets( $settings, array( 'typography', 'fontSizes' ), array( 'slug', 'name', 'size' ) ),
			'spacing_sizes'  => self::presets( $settings, array( 'spacing', 'spacingSizes' ), array( 'slug', 'name', 'size' ) ),
			'content_width'  => isset( $settings['layout']['contentSize'] ) ? (string) $settings['layout']['contentSize'] : '',
			'wide_width'     => isset( $settings['layout']['wideSize'] ) ? (string) $settings['layout']['wideSize'] : '',
			'site_styles'    => self::pick( $styles, array( 'color', 'typography', 'spacing' ) ),
			'element_styles' => isset( $styles['elements'] ) && is_array( $styles['elements'] ) ? $styles['elements'] : new \stdClass(),
			'overrides'      => empty( $user ) ? new \stdClass() : $user,
			'notes'          => array(),
		);

		if ( ! empty( $input['path'] ) ) {
			$subtree = self::at_path( $merged, (string) $input['path'] );
			if ( is_wp_error( $subtree ) ) {
				return $subtree;
			}
			$result['path_value'] = $subtree;
		}

		if ( $pending ) {
			$result['notes'][] = __( 'This includes pending global-style changes that are not live yet.', 'mosmcp-abilities' );
		}
		$result['notes'][] = __( 'Colours and sizes are usually referenced by preset, for example "var:preset|color|primary" in styles or "var(--wp--preset--color--primary)" in CSS.', 'mosmcp-abilities' );

		return $result;
	}

	/**
	 * Reads the theme's own theme.json, as shipped.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function json_get( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$guard = Theme_Support::global_styles_required();
		if ( $guard instanceof WP_Error ) {
			return $guard;
		}

		$raw  = \WP_Theme_JSON_Resolver::get_theme_data()->get_raw_data();
		$path = isset( $input['path'] ) ? trim( (string) $input['path'] ) : '';

		$value = $raw;
		if ( '' !== $path ) {
			$value = self::at_path( $raw, $path );
			if ( is_wp_error( $value ) ) {
				return $value;
			}
		}

		$encoded   = (string) wp_json_encode( $value );
		$truncated = strlen( $encoded ) > self::MAX_JSON_BYTES;

		return array(
			'theme'      => Theme_Support::stylesheet(),
			'version'    => isset( $raw['version'] ) ? (int) $raw['version'] : 0,
			'top_level'  => array_keys( $raw ),
			'path'       => $path,
			'value'      => $truncated ? new \stdClass() : $value,
			'truncated'  => $truncated,
			'size_bytes' => strlen( $encoded ),
			'notes'      => $truncated
				? array( __( 'That part of theme.json is too large to return whole. Ask for a narrower path, such as "settings.color" or "styles.elements".', 'mosmcp-abilities' ) )
				: array( __( 'This is the theme as shipped. Changes made on this site are layered on top; mosmcp/theme-styles-get shows the result.', 'mosmcp-abilities' ) ),
		);
	}

	/**
	 * Stages a change to global styles.
	 *
	 * The patch is merged into the current styles: objects merge key by key, lists
	 * (such as a palette) are replaced whole, and a null removes a key.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function styles_update( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$guard = Theme_Support::global_styles_required();
		if ( $guard instanceof WP_Error ) {
			return $guard;
		}

		$patch = array();
		foreach ( array( 'settings', 'styles' ) as $key ) {
			if ( isset( $input[ $key ] ) && is_array( $input[ $key ] ) && ! empty( $input[ $key ] ) ) {
				$patch[ $key ] = $input[ $key ];
			}
		}
		if ( empty( $patch ) ) {
			return Site_Support::error(
				'mosmcp_styles_patch_empty',
				__( 'No change was given. Pass "styles" (for example {"color":{"background":"#0b1f3a"}}) and/or "settings" (for example a new colour palette).', 'mosmcp-abilities' ),
				Site_Support::CAUSE_INVALID_INPUT,
				true
			);
		}

		$draft = Theme_Draft::open();
		if ( is_wp_error( $draft ) ) {
			return $draft;
		}

		$live    = Theme_Publisher::live_user_styles();
		$current = ! empty( $draft['styles'] ) ? (array) $draft['styles']['user_data'] : $live;

		// A font the theme already offers is stored as its preset, as the Site Editor does.
		$merged_settings = self::merged_data( $current );
		$fonts           = self::presets( isset( $merged_settings['settings'] ) ? (array) $merged_settings['settings'] : array(), array( 'typography', 'fontFamilies' ), array( 'slug', 'name', 'fontFamily' ) );
		$linked          = array();
		$patch           = self::link_font_presets( $patch, $fonts, $linked );

		// WordPress keeps any string as a colour; a browser silently drops one that is not.
		$bad_colours = array();
		$patch       = self::drop_invalid_colours( $patch, '', $bad_colours );

		$wanted = self::merge( $current, $patch );
		$clean  = self::sanitize_styles( $wanted );

		// WordPress files preset lists under their origin ("palette.custom"); compare without it.
		$kept    = preg_replace( '/\.(palette|gradients|duotone|fontFamilies|fontSizes|spacingSizes|presets)\.(?:custom|theme|default)(?=\.|$)/', '.$1', self::leaf_paths( $clean ) );
		$empty   = self::empty_paths( $patch );
		$ignored = array_values( array_diff( self::leaf_paths( $patch ), (array) $kept, self::null_paths( $patch ), $empty ) );
		if ( ! empty( $empty ) ) {
			$colour_empty = sprintf(
				/* translators: %s: comma-separated property paths. */
				__( '%s was given an empty value, which changes nothing. To remove a setting, pass null for it instead.', 'mosmcp-abilities' ),
				implode( ', ', $empty )
			);
		}

		$colour_note = empty( $bad_colours ) ? '' : sprintf(
			/* translators: %s: comma-separated list of path = value pairs. */
			__( 'Not valid CSS colours, so they were not staged: %s. Use a hex value such as #1f1f1f, rgb()/hsl(), a CSS colour name, or one of the theme\'s palette colours from mosmcp/theme-styles-get.', 'mosmcp-abilities' ),
			implode( ', ', $bad_colours )
		);
		if ( isset( $colour_empty ) ) {
			$colour_note = trim( $colour_note . ' ' . $colour_empty );
		}

		if ( Theme_Draft::fingerprint( $clean ) === Theme_Draft::fingerprint( $current ) ) {
			if ( ! empty( $ignored ) ) {
				$message = sprintf(
					/* translators: %s: comma-separated property paths. */
					__( 'Nothing changed: WordPress does not recognise %s as global-style properties, so they were dropped. Use mosmcp/theme-styles-get to see the structure it expects.', 'mosmcp-abilities' ),
					implode( ', ', $ignored )
				);
			} elseif ( '' !== $colour_note ) {
				$message = __( 'Nothing changed.', 'mosmcp-abilities' );
			} else {
				$message = __( 'Those values are already what the global styles hold, so nothing changed.', 'mosmcp-abilities' );
			}
			return Site_Support::error(
				'mosmcp_styles_no_change',
				trim( $message . ' ' . $colour_note ),
				Site_Support::CAUSE_INVALID_INPUT,
				true,
				array(
					'ignored'         => $ignored,
					'invalid_colours' => $bad_colours,
				)
			);
		}

		$draft['styles'] = array(
			'action'    => 'update',
			'variation' => '',
			'user_data' => $clean,
			'baseline'  => ! empty( $draft['styles'] ) ? (string) $draft['styles']['baseline'] : Theme_Draft::fingerprint( $live ),
			'changes'   => self::add_changes( $draft, self::style_labels( $patch, $ignored ) ),
		);
		Theme_Draft::save( $draft );

		$notes = array();
		if ( ! empty( $ignored ) ) {
			$notes[] = sprintf(
				/* translators: %s: comma-separated property paths. */
				__( 'WordPress does not recognise %s as global-style properties, so those were dropped. Everything else was staged.', 'mosmcp-abilities' ),
				implode( ', ', $ignored )
			);
		}
		if ( '' !== $colour_note ) {
			$notes[] = $colour_note;
		}
		if ( ! empty( $linked ) ) {
			$notes[] = sprintf(
				/* translators: %s: comma-separated font names. */
				__( 'Linked to the theme\'s own font presets (%s), as the Site Editor does, so they keep working if the theme changes how the font is loaded.', 'mosmcp-abilities' ),
				implode( ', ', array_unique( $linked ) )
			);
		}

		$result            = self::staged_result( $draft, __( 'the global styles', 'mosmcp-abilities' ), 'updated', $notes );
		$result['ignored'] = array_merge( $ignored, array_keys( $bad_colours ) );

		return $result;
	}

	/**
	 * Stages a return to the theme's own styles, dropping every site override.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function styles_reset( $input = array() ) {
		unset( $input );
		$guard = Theme_Support::global_styles_required();
		if ( $guard instanceof WP_Error ) {
			return $guard;
		}

		$draft = Theme_Draft::open();
		if ( is_wp_error( $draft ) ) {
			return $draft;
		}

		$live = Theme_Publisher::live_user_styles();

		if ( empty( $live ) ) {
			if ( ! empty( $draft['styles'] ) ) {
				$draft['styles'] = null;
				Theme_Draft::save( $draft );
				return self::staged_result( $draft, __( 'the global styles', 'mosmcp-abilities' ), 'discarded pending change', array( __( 'The live site already uses the theme\'s own styles, so the pending style change was simply dropped from the draft.', 'mosmcp-abilities' ) ) );
			}
			return Site_Support::error(
				'mosmcp_styles_already_default',
				__( 'The site already uses the theme\'s own global styles, with no overrides, so there is nothing to reset.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_INVALID_INPUT,
				false
			);
		}

		$draft['styles'] = array(
			'action'    => 'reset',
			'variation' => '',
			'user_data' => array(),
			'baseline'  => ! empty( $draft['styles'] ) ? (string) $draft['styles']['baseline'] : Theme_Draft::fingerprint( $live ),
			'changes'   => array( __( 'back to the theme\'s own styles', 'mosmcp-abilities' ) ),
		);
		Theme_Draft::save( $draft );

		return self::staged_result( $draft, __( 'the global styles', 'mosmcp-abilities' ), 'reset to theme styles', array() );
	}

	/**
	 * Lists the theme's style variations.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function variation_list( $input = array() ) {
		unset( $input );
		$guard = Theme_Support::global_styles_required();
		if ( $guard instanceof WP_Error ) {
			return $guard;
		}

		$draft   = Theme_Draft::current();
		$current = ! empty( $draft['styles'] ) ? (array) $draft['styles']['user_data'] : Theme_Publisher::live_user_styles();
		$items   = array();

		foreach ( self::variations() as $variation ) {
			$kind     = self::variation_kind( $variation );
			$settings = isset( $variation['settings'] ) && is_array( $variation['settings'] ) ? $variation['settings'] : array();
			$colours  = array();
			foreach ( self::presets( $settings, array( 'color', 'palette' ), array( 'slug', 'name', 'color' ) ) as $preset ) {
				$colours[] = $preset['name'] . ' ' . $preset['color'];
				if ( count( $colours ) >= 6 ) {
					break;
				}
			}
			$fonts = array();
			foreach ( self::presets( $settings, array( 'typography', 'fontFamilies' ), array( 'slug', 'name', 'fontFamily' ) ) as $preset ) {
				$fonts[] = $preset['name'];
			}

			$items[] = array(
				'title'       => (string) $variation['title'],
				'kind'        => $kind,
				'description' => isset( $variation['description'] ) ? (string) $variation['description'] : '',
				'colours'     => $colours,
				'fonts'       => $fonts,
				'is_current'  => Theme_Draft::fingerprint( self::variation_result( $variation, $kind, $current ) ) === Theme_Draft::fingerprint( $current ),
			);
		}

		// Whole looks first, then colour palettes, then typography sets, as the Site Editor groups them.
		$order = array_flip( self::VARIATION_KINDS );
		usort(
			$items,
			static function ( $a, $b ) use ( $order ) {
				return $order[ $a['kind'] ] - $order[ $b['kind'] ];
			}
		);

		$notes = array();
		if ( empty( $items ) ) {
			$notes[] = __( 'This theme ships no style variations.', 'mosmcp-abilities' );
		} else {
			$notes[] = __( 'Apply one with mosmcp/theme-variation-apply. A "style" replaces the site\'s current global-style overrides; a "colours" palette or a "typography" set changes only colours or only fonts and keeps everything else, as in the Site Editor.', 'mosmcp-abilities' );
			$titles  = array_count_values( wp_list_pluck( $items, 'title' ) );
			if ( max( $titles ) > 1 ) {
				$notes[] = __( 'Some titles appear more than once, as a whole style and as a colour palette or typography set. Pass kind to mosmcp/theme-variation-apply to choose which one.', 'mosmcp-abilities' );
			}
		}

		return array(
			'theme'      => Theme_Support::stylesheet(),
			'count'      => count( $items ),
			'variations' => $items,
			'notes'      => $notes,
		);
	}

	/**
	 * Stages applying a style variation.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function variation_apply( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$guard = Theme_Support::global_styles_required();
		if ( $guard instanceof WP_Error ) {
			return $guard;
		}

		$wanted  = isset( $input['title'] ) ? strtolower( trim( (string) $input['title'] ) ) : '';
		$kind    = isset( $input['kind'] ) ? (string) $input['kind'] : '';
		$titles  = array();
		$matches = array();

		foreach ( self::variations() as $variation ) {
			$this_kind = self::variation_kind( $variation );
			$titles[]  = 'style' === $this_kind ? (string) $variation['title'] : $variation['title'] . ' (' . $this_kind . ')';
			if ( strtolower( (string) $variation['title'] ) === $wanted && ( '' === $kind || $kind === $this_kind ) ) {
				$matches[ $this_kind ] = $variation;
			}
		}

		// With no kind given, a whole style wins over a palette or font set of the same name.
		$chosen = null;
		foreach ( self::VARIATION_KINDS as $candidate ) {
			if ( isset( $matches[ $candidate ] ) ) {
				$chosen = $matches[ $candidate ];
				$kind   = $candidate;
				break;
			}
		}

		if ( null === $chosen ) {
			return Site_Support::error(
				'mosmcp_variation_not_found',
				sprintf(
					/* translators: 1: title supplied, 2: available titles. */
					__( 'This theme has no style variation called "%1$s". Its variations are: %2$s.', 'mosmcp-abilities' ),
					isset( $input['title'] ) ? (string) $input['title'] : '',
					empty( $titles ) ? __( 'none', 'mosmcp-abilities' ) : implode( ', ', $titles )
				),
				Site_Support::CAUSE_NOT_FOUND,
				false,
				array( 'use_instead' => 'mosmcp/theme-variation-list' )
			);
		}

		$draft = Theme_Draft::open();
		if ( is_wp_error( $draft ) ) {
			return $draft;
		}

		$live    = Theme_Publisher::live_user_styles();
		$current = ! empty( $draft['styles'] ) ? (array) $draft['styles']['user_data'] : $live;
		$label   = 'style' === $kind ? (string) $chosen['title'] : $chosen['title'] . ' (' . $kind . ')';
		$what    = 'style' === $kind
			/* translators: %s: variation title. */
			? sprintf( __( 'the "%s" style', 'mosmcp-abilities' ), (string) $chosen['title'] )
			/* translators: 1: variation title, 2: "colours" or "typography". */
			: sprintf( __( 'the "%1$s" %2$s set', 'mosmcp-abilities' ), (string) $chosen['title'], 'colours' === $kind ? __( 'colour', 'mosmcp-abilities' ) : __( 'font', 'mosmcp-abilities' ) );
		$draft['styles'] = array(
			'action'    => 'variation',
			'variation' => $label,
			'user_data' => self::variation_result( $chosen, $kind, $current ),
			'baseline'  => ! empty( $draft['styles'] ) ? (string) $draft['styles']['baseline'] : Theme_Draft::fingerprint( $live ),
			// A whole style replaces every earlier override, so it replaces the list too.
			'changes'   => 'style' === $kind ? array( $what ) : self::add_changes( $draft, array( $what ) ),
		);
		Theme_Draft::save( $draft );

		$notes = array();
		if ( 'style' === $kind && ! empty( $current ) ) {
			$notes[] = __( 'Applying a whole style replaces the site\'s previous global-style overrides, as it does in the Site Editor.', 'mosmcp-abilities' );
		} elseif ( 'style' !== $kind ) {
			$notes[] = 'colours' === $kind
				? __( 'Only the colours changed; fonts, spacing and other style changes were kept.', 'mosmcp-abilities' )
				: __( 'Only the fonts changed; colours, spacing and other style changes were kept.', 'mosmcp-abilities' );
		}
		if ( '' === ( isset( $input['kind'] ) ? (string) $input['kind'] : '' ) && count( $matches ) > 1 ) {
			$notes[] = sprintf(
				/* translators: %s: variation title. */
				__( 'The theme also has a colour palette or typography set called "%s". This applied the whole style; pass kind to apply only the palette or the fonts.', 'mosmcp-abilities' ),
				(string) $chosen['title']
			);
		}

		return self::staged_result(
			$draft,
			sprintf(
				/* translators: %s: variation title. */
				__( 'the "%s" style variation', 'mosmcp-abilities' ),
				$label
			),
			'applied',
			$notes
		);
	}

	/*
	 * Patterns
	 */

	/**
	 * Lists patterns: those registered by the theme and plugins, and synced ones saved on the site.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>
	 */
	public static function pattern_list( $input = array() ) {
		$input    = is_array( $input ) ? $input : array();
		$search   = isset( $input['search'] ) ? strtolower( trim( (string) $input['search'] ) ) : '';
		$source   = isset( $input['source'] ) ? (string) $input['source'] : 'all';
		$per_page = isset( $input['per_page'] ) ? max( 1, min( 100, (int) $input['per_page'] ) ) : 50;
		$offset   = isset( $input['offset'] ) ? max( 0, (int) $input['offset'] ) : 0;
		$draft    = Theme_Draft::current();
		$items    = array();

		if ( 'synced' !== $source && class_exists( '\WP_Block_Patterns_Registry' ) ) {
			foreach ( \WP_Block_Patterns_Registry::get_instance()->get_all_registered() as $pattern ) {
				$items[] = array(
					'kind'       => 'registered',
					'name'       => (string) $pattern['name'],
					'id'         => 0,
					'title'      => isset( $pattern['title'] ) ? (string) $pattern['title'] : '',
					'categories' => isset( $pattern['categories'] ) ? array_values( array_map( 'strval', (array) $pattern['categories'] ) ) : array(),
					'synced'     => false,
					'pending'    => '',
				);
			}
		}

		if ( 'registered' !== $source ) {
			$posts = get_posts(
				array(
					'post_type'      => 'wp_block',
					'post_status'    => array( 'publish', 'draft' ),
					'posts_per_page' => 100,
					'orderby'        => 'title',
					'order'          => 'ASC',
					'no_found_rows'  => true,
				)
			);
			foreach ( $posts as $post ) {
				$entry   = Theme_Draft::pattern_entry( $draft, (int) $post->ID );
				$items[] = array(
					'kind'       => 'saved',
					'name'       => '',
					'id'         => (int) $post->ID,
					'title'      => (string) $post->post_title,
					'categories' => wp_get_post_terms( $post->ID, 'wp_pattern_category', array( 'fields' => 'names' ) ),
					'synced'     => 'unsynced' !== get_post_meta( $post->ID, 'wp_pattern_sync_status', true ),
					'pending'    => null === $entry ? '' : 'update',
				);
			}
			if ( is_array( $draft ) ) {
				foreach ( (array) $draft['patterns'] as $entry ) {
					if ( 'create' === $entry['action'] ) {
						$items[] = array(
							'kind'       => 'saved',
							'name'       => '',
							'id'         => 0,
							'title'      => (string) $entry['title'],
							'categories' => array(),
							'synced'     => ! empty( $entry['synced'] ),
							'pending'    => 'create',
						);
					}
				}
			}
		}

		if ( '' !== $search ) {
			$items = array_values(
				array_filter(
					$items,
					static function ( $item ) use ( $search ) {
						return false !== strpos( strtolower( $item['title'] . ' ' . $item['name'] . ' ' . implode( ' ', (array) $item['categories'] ) ), $search );
					}
				)
			);
		}

		foreach ( $items as $index => $item ) {
			if ( ! is_array( $item['categories'] ) ) {
				$items[ $index ]['categories'] = array();
			}
		}

		return array(
			'total'    => count( $items ),
			'showing'  => count( array_slice( $items, $offset, $per_page ) ),
			'patterns' => array_slice( $items, $offset, $per_page ),
			'notes'    => array( __( 'Registered patterns come from the theme, WordPress and plugins and are read-only; insert their content into a page or template. Saved patterns live on this site and can be changed with mosmcp/theme-pattern-save; a synced one updates everywhere it is used.', 'mosmcp-abilities' ) ),
		);
	}

	/**
	 * Gets one pattern's content.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function pattern_get( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$id    = isset( $input['id'] ) ? absint( $input['id'] ) : 0;
		$name  = isset( $input['name'] ) ? trim( (string) $input['name'] ) : '';

		if ( $id > 0 ) {
			$post = get_post( $id );
			if ( ! $post || 'wp_block' !== $post->post_type ) {
				return self::pattern_not_found( (string) $id );
			}
			$entry = Theme_Draft::pattern_entry( Theme_Draft::current(), $id );
			return array(
				'kind'    => 'saved',
				'name'    => '',
				'id'      => $id,
				'title'   => null !== $entry ? (string) $entry['title'] : (string) $post->post_title,
				'synced'  => 'unsynced' !== get_post_meta( $id, 'wp_pattern_sync_status', true ),
				'content' => null !== $entry ? (string) $entry['content'] : (string) $post->post_content,
				'pending' => null === $entry ? '' : 'update',
				'notes'   => null === $entry ? array() : array( __( 'This shows the draft version, which is not live yet.', 'mosmcp-abilities' ) ),
			);
		}

		if ( '' !== $name && class_exists( '\WP_Block_Patterns_Registry' ) ) {
			$pattern = \WP_Block_Patterns_Registry::get_instance()->get_registered( $name );
			if ( is_array( $pattern ) ) {
				return array(
					'kind'    => 'registered',
					'name'    => $name,
					'id'      => 0,
					'title'   => isset( $pattern['title'] ) ? (string) $pattern['title'] : '',
					'synced'  => false,
					'content' => isset( $pattern['content'] ) ? (string) $pattern['content'] : '',
					'pending' => '',
					'notes'   => array( __( 'Registered patterns are read-only. To use it, insert this content into a page or template.', 'mosmcp-abilities' ) ),
				);
			}
		}

		return self::pattern_not_found( '' !== $name ? $name : (string) $id );
	}

	/**
	 * Stages creating a saved pattern or updating one.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function pattern_save( $input = array() ) {
		$input   = is_array( $input ) ? $input : array();
		$id      = isset( $input['id'] ) ? absint( $input['id'] ) : 0;
		$title   = isset( $input['title'] ) ? sanitize_text_field( (string) $input['title'] ) : '';
		$content = isset( $input['content'] ) ? (string) $input['content'] : '';

		$draft = Theme_Draft::open();
		if ( is_wp_error( $draft ) ) {
			return $draft;
		}

		if ( $id > 0 ) {
			$post = get_post( $id );
			if ( ! $post || 'wp_block' !== $post->post_type ) {
				return self::pattern_not_found( (string) $id );
			}
			$existing = Theme_Draft::pattern_entry( $draft, $id );
			$previous = null !== $existing ? (string) $existing['content'] : (string) $post->post_content;
			$title    = '' !== $title ? $title : ( null !== $existing ? (string) $existing['title'] : (string) $post->post_title );
			$content  = '' !== $content ? $content : $previous;

			$invalid = Theme_Support::block_markup_error( $content, $previous, sprintf( 'the pattern "%s"', $title ) );
			if ( $invalid instanceof WP_Error ) {
				return $invalid;
			}

			$draft['patterns'][ 'id:' . $id ] = array(
				'action'   => 'update',
				'id'       => $id,
				'title'    => $title,
				'content'  => $content,
				'synced'   => 'unsynced' !== get_post_meta( $id, 'wp_pattern_sync_status', true ),
				'baseline' => null !== $existing ? (string) $existing['baseline'] : Theme_Draft::fingerprint( $post->post_title . '|' . $post->post_content ),
			);
			Theme_Draft::save( $draft );

			$notes  = array();
			$synced = 'unsynced' !== get_post_meta( $id, 'wp_pattern_sync_status', true );
			if ( $synced ) {
				$notes[] = __( 'This pattern is synced: once published, the change appears everywhere it is used.', 'mosmcp-abilities' );
			}

			$result                  = self::staged_result( $draft, sprintf( 'the pattern "%s"', $title ), 'updated', $notes );
			$result['insert_markup'] = $synced ? '<!-- wp:block {"ref":' . $id . '} /-->' : $content;
			return $result;
		}

		if ( '' === $title ) {
			return Site_Support::error( 'mosmcp_pattern_title_required', __( 'A new pattern needs a title.', 'mosmcp-abilities' ), Site_Support::CAUSE_INVALID_INPUT, true );
		}

		$invalid = Theme_Support::block_markup_error( $content, null, sprintf( 'the pattern "%s"', $title ) );
		if ( $invalid instanceof WP_Error ) {
			return $invalid;
		}

		$uuid   = wp_generate_uuid4();
		$synced = Site_Support::bool_input( $input, 'synced', true );

		$draft['patterns'][ 'new:' . $uuid ] = array(
			'action'   => 'create',
			'id'       => 0,
			'title'    => $title,
			'content'  => $content,
			'synced'   => $synced,
			'baseline' => '',
		);
		Theme_Draft::save( $draft );

		$result = self::staged_result(
			$draft,
			sprintf( 'the new pattern "%s"', $title ),
			'created',
			array(
				$synced
					? __( 'To place it, put insert_markup into a template or template part (for example with mosmcp/theme-part-update) in this same draft. It shows in the preview, and publishing creates the pattern and links it there.', 'mosmcp-abilities' )
					: __( 'An unsynced pattern is copied, not linked, wherever it is used: insert_markup is its content, ready to put into a template or part.', 'mosmcp-abilities' ),
			)
		);

		$result['insert_markup'] = $synced ? Theme_Draft::pattern_placeholder( $uuid ) : $content;
		return $result;
	}

	/*
	 * Helpers
	 */

	/**
	 * Standard result for a staged change.
	 *
	 * @param array<string, mixed> $draft  Draft.
	 * @param string               $target What changed.
	 * @param string               $change What happened to it.
	 * @param string[]             $notes  Extra notes.
	 * @return array<string, mixed>
	 */
	public static function staged_result( array $draft, $target, $change, array $notes ) {
		return array(
			'staged'    => true,
			'target'    => (string) $target,
			'change'    => (string) $change,
			'draft'     => Theme_Draft::summary( $draft ),
			'next_step' => __( 'Nothing has changed on the live site yet. Preview the draft with mosmcp/theme-draft-preview, then publish it with mosmcp/theme-draft-publish.', 'mosmcp-abilities' ),
			'notes'     => $notes,
		);
	}

	/**
	 * Refusal for an edit that changes nothing.
	 *
	 * @param string $label What was being changed.
	 * @return WP_Error
	 */
	private static function no_change( $label ) {
		return Site_Support::error(
			'mosmcp_no_change',
			sprintf(
				/* translators: %s: what was being changed. */
				__( 'That content is identical to what %s already holds, so nothing was staged.', 'mosmcp-abilities' ),
				$label
			),
			Site_Support::CAUSE_INVALID_INPUT,
			false
		);
	}

	/**
	 * Human label for a template or part.
	 *
	 * @param string $type Template type.
	 * @param string $slug Slug.
	 * @return string
	 */
	private static function label( $type, $slug ) {
		return sprintf(
			/* translators: 1: "template" or "template part", 2: slug. */
			__( 'the %1$s "%2$s"', 'mosmcp-abilities' ),
			'wp_template_part' === $type ? __( 'template part', 'mosmcp-abilities' ) : __( 'template', 'mosmcp-abilities' ),
			$slug
		);
	}

	/**
	 * Refusal for a pattern that does not exist.
	 *
	 * @param string $ref What was asked for.
	 * @return WP_Error
	 */
	private static function pattern_not_found( $ref ) {
		return Site_Support::error(
			'mosmcp_pattern_not_found',
			sprintf(
				/* translators: %s: the pattern name or ID supplied. */
				__( 'No pattern "%s" exists. Use mosmcp/theme-pattern-list to find it.', 'mosmcp-abilities' ),
				$ref
			),
			Site_Support::CAUSE_NOT_FOUND,
			false,
			array( 'use_instead' => 'mosmcp/theme-pattern-list' )
		);
	}

	/**
	 * The theme's style variations.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function variations() {
		if ( ! class_exists( '\WP_Theme_JSON_Resolver' ) ) {
			return array();
		}
		$list = \WP_Theme_JSON_Resolver::get_style_variations();
		return is_array( $list ) ? array_values( array_filter( $list, 'is_array' ) ) : array();
	}

	/**
	 * The user-styles payload a variation corresponds to.
	 *
	 * @param array<string, mixed> $variation Variation.
	 * @return array<string, mixed>
	 */
	private static function variation_user_data( array $variation ) {
		$data = array();
		foreach ( array( 'settings', 'styles' ) as $key ) {
			if ( ! empty( $variation[ $key ] ) && is_array( $variation[ $key ] ) ) {
				$data[ $key ] = $variation[ $key ];
			}
		}
		return $data;
	}

	/**
	 * Whether a variation is a whole style, a colour palette or a typography set,
	 * judged the way the Site Editor groups them: by what the file touches.
	 *
	 * @param array<string, mixed> $variation Variation.
	 * @return string One of VARIATION_KINDS.
	 */
	private static function variation_kind( array $variation ) {
		$paths = self::leaf_paths( self::variation_user_data( $variation ) );
		if ( empty( $paths ) ) {
			return 'style';
		}
		foreach ( array(
			'colours'    => 'color',
			'typography' => 'typography',
		) as $kind => $segment ) {
			$only = true;
			foreach ( $paths as $path ) {
				if ( ! in_array( $segment, explode( '.', $path ), true ) ) {
					$only = false;
					break;
				}
			}
			if ( $only ) {
				return $kind;
			}
		}
		return 'style';
	}

	/**
	 * The user styles that result from applying a variation on top of the current
	 * ones: a whole style replaces them; a palette or font set swaps only its aspect.
	 *
	 * @param array<string, mixed> $variation Variation.
	 * @param string               $kind      One of VARIATION_KINDS.
	 * @param array<string, mixed> $current   Current user styles.
	 * @return array<string, mixed>
	 */
	private static function variation_result( array $variation, $kind, array $current ) {
		$data = self::variation_user_data( $variation );
		if ( 'style' === $kind ) {
			return self::sanitize_styles( $data );
		}
		$kept = self::without_segment( $current, 'colours' === $kind ? 'color' : 'typography' );
		return self::sanitize_styles( self::merge( $kept, $data ) );
	}

	/**
	 * Removes every key with the given name, at any depth, pruning emptied branches.
	 *
	 * @param array<string, mixed> $data    Data.
	 * @param string               $segment Key name, e.g. "color".
	 * @return array<string, mixed>
	 */
	private static function without_segment( array $data, $segment ) {
		unset( $data[ $segment ] );
		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) && ! self::is_list( $value ) ) {
				$data[ $key ] = self::without_segment( $value, $segment );
				if ( empty( $data[ $key ] ) ) {
					unset( $data[ $key ] );
				}
			}
		}
		return $data;
	}

	/**
	 * Theme and core styles merged with a given set of user styles.
	 *
	 * @param array<string, mixed> $user User styles.
	 * @return array<string, mixed>
	 */
	private static function merged_data( array $user ) {
		$base = \WP_Theme_JSON_Resolver::get_merged_data( 'theme' );
		if ( ! empty( $user ) ) {
			$user['version'] = \WP_Theme_JSON::LATEST_SCHEMA;
			$base->merge( new \WP_Theme_JSON( $user, 'custom' ) );
		}
		return $base->get_raw_data();
	}

	/**
	 * Passes user styles through WordPress's own sanitizer.
	 *
	 * @param array<string, mixed> $user User styles.
	 * @return array<string, mixed> Sanitized settings and styles.
	 */
	private static function sanitize_styles( array $user ) {
		$user['version'] = \WP_Theme_JSON::LATEST_SCHEMA;
		$raw             = ( new \WP_Theme_JSON( $user, 'custom' ) )->get_raw_data();
		$clean           = array();
		foreach ( array( 'settings', 'styles' ) as $key ) {
			if ( ! empty( $raw[ $key ] ) && is_array( $raw[ $key ] ) ) {
				$clean[ $key ] = $raw[ $key ];
			}
		}
		return $clean;
	}

	/**
	 * Merges a patch: objects key by key, lists replaced whole, null removes.
	 *
	 * @param array<string, mixed> $base  Base.
	 * @param array<string, mixed> $patch Patch.
	 * @return array<string, mixed>
	 */
	private static function merge( array $base, array $patch ) {
		foreach ( $patch as $key => $value ) {
			if ( null === $value ) {
				unset( $base[ $key ] );
				continue;
			}
			// An empty "{}" arrives as an empty array, indistinguishable from "[]".
			// Removing is what null is for, so an empty value changes nothing
			// rather than silently wiping a whole group.
			if ( array() === $value ) {
				continue;
			}
			if ( is_array( $value ) && ! self::is_list( $value ) && isset( $base[ $key ] ) && is_array( $base[ $key ] ) && ! self::is_list( $base[ $key ] ) ) {
				$base[ $key ] = self::merge( $base[ $key ], $value );
				if ( empty( $base[ $key ] ) ) {
					unset( $base[ $key ] );
				}
				continue;
			}
			$base[ $key ] = $value;
		}
		return $base;
	}

	/**
	 * Whether an array is a list (sequential integer keys from 0).
	 *
	 * @param array<mixed> $value Array.
	 * @return bool
	 */
	private static function is_list( array $value ) {
		return array() === $value || array_keys( $value ) === range( 0, count( $value ) - 1 );
	}

	/**
	 * Dotted paths to every leaf of a nested array (lists count as leaves).
	 *
	 * @param array<string, mixed> $data   Data.
	 * @param string               $prefix Path so far.
	 * @return string[]
	 */
	private static function leaf_paths( array $data, $prefix = '' ) {
		$paths = array();
		foreach ( $data as $key => $value ) {
			$path = '' === $prefix ? (string) $key : $prefix . '.' . $key;
			if ( is_array( $value ) && ! self::is_list( $value ) && ! empty( $value ) ) {
				$paths = array_merge( $paths, self::leaf_paths( $value, $path ) );
			} else {
				$paths[] = $path;
			}
		}
		return $paths;
	}

	/**
	 * Dotted paths the patch sets to null (removals, never "ignored").
	 *
	 * @param array<string, mixed> $data   Data.
	 * @param string               $prefix Path so far.
	 * @return string[]
	 */
	private static function null_paths( array $data, $prefix = '' ) {
		$paths = array();
		foreach ( $data as $key => $value ) {
			$path = '' === $prefix ? (string) $key : $prefix . '.' . $key;
			if ( null === $value ) {
				$paths[] = $path;
			} elseif ( is_array( $value ) && ! self::is_list( $value ) ) {
				$paths = array_merge( $paths, self::null_paths( $value, $path ) );
			}
		}
		return $paths;
	}

	/**
	 * Reads a dotted path out of nested data.
	 *
	 * @param array<string, mixed> $data Data.
	 * @param string               $path Dotted path, e.g. "styles.elements.link".
	 * @return mixed|WP_Error
	 */
	private static function at_path( array $data, $path ) {
		$node = $data;
		foreach ( explode( '.', trim( $path, '. ' ) ) as $segment ) {
			if ( is_array( $node ) && array_key_exists( $segment, $node ) ) {
				$node = $node[ $segment ];
				continue;
			}
			return Site_Support::error(
				'mosmcp_styles_path_not_found',
				sprintf(
					/* translators: 1: the path supplied, 2: the segment that was missing. */
					__( 'The path "%1$s" does not exist: there is nothing at "%2$s". Ask without a path to see the top-level structure.', 'mosmcp-abilities' ),
					$path,
					$segment
				),
				Site_Support::CAUSE_NOT_FOUND,
				true
			);
		}
		return is_array( $node ) && empty( $node ) ? new \stdClass() : $node;
	}

	/**
	 * Flattens a preset list (which theme.json keys by origin) into one list.
	 *
	 * @param array<string, mixed> $settings Settings.
	 * @param string[]             $path     Path to the preset, e.g. array( 'color', 'palette' ).
	 * @param string[]             $fields   Fields to keep.
	 * @return array<int, array<string, string>>
	 */
	private static function presets( array $settings, array $path, array $fields ) {
		$node = $settings;
		foreach ( $path as $segment ) {
			if ( ! isset( $node[ $segment ] ) || ! is_array( $node[ $segment ] ) ) {
				return array();
			}
			$node = $node[ $segment ];
		}

		// Merged data keys presets by origin (default, theme, custom); raw data does not.
		$lists = self::is_list( $node ) ? array( $node ) : array_values( $node );
		$out   = array();
		$seen  = array();

		foreach ( array_reverse( $lists ) as $list ) {
			if ( ! is_array( $list ) ) {
				continue;
			}
			foreach ( $list as $preset ) {
				if ( ! is_array( $preset ) || empty( $preset['slug'] ) || isset( $seen[ $preset['slug'] ] ) ) {
					continue;
				}
				$seen[ $preset['slug'] ] = true;
				$row                     = array();
				foreach ( $fields as $field ) {
					$row[ $field ] = isset( $preset[ $field ] ) && is_scalar( $preset[ $field ] ) ? (string) $preset[ $field ] : '';
				}
				$out[] = $row;
			}
		}

		return $out;
	}

	/**
	 * The draft's list of style changes with new ones added, without repeats.
	 *
	 * @param array<string, mixed> $draft Draft.
	 * @param string[]             $labels Labels to add.
	 * @return string[]
	 */
	private static function add_changes( array $draft, array $labels ) {
		$list = ! empty( $draft['styles']['changes'] ) ? (array) $draft['styles']['changes'] : array();
		return array_values( array_unique( array_merge( $list, $labels ) ) );
	}

	/**
	 * Plain-language names for what a styles patch touches: "background colour",
	 * "heading font", "button block text colour", "colour palette".
	 *
	 * @param array<string, mixed> $patch   Styles patch.
	 * @param string[]             $ignored Paths WordPress dropped, left unnamed.
	 * @return string[]
	 */
	private static function style_labels( array $patch, array $ignored = array() ) {
		$properties = array(
			'color.background'         => __( 'background colour', 'mosmcp-abilities' ),
			'color.text'               => __( 'text colour', 'mosmcp-abilities' ),
			'color.gradient'           => __( 'background gradient', 'mosmcp-abilities' ),
			'typography.fontFamily'    => __( 'font', 'mosmcp-abilities' ),
			'typography.fontSize'      => __( 'font size', 'mosmcp-abilities' ),
			'typography.fontWeight'    => __( 'font weight', 'mosmcp-abilities' ),
			'typography.lineHeight'    => __( 'line height', 'mosmcp-abilities' ),
			'typography.fontStyle'     => __( 'font style', 'mosmcp-abilities' ),
			'typography.textTransform' => __( 'text case', 'mosmcp-abilities' ),
			'typography.letterSpacing' => __( 'letter spacing', 'mosmcp-abilities' ),
		);
		$settings   = array(
			'color.palette'           => __( 'colour palette', 'mosmcp-abilities' ),
			'color.gradients'         => __( 'gradient presets', 'mosmcp-abilities' ),
			'typography.fontFamilies' => __( 'available fonts', 'mosmcp-abilities' ),
			'typography.fontSizes'    => __( 'font size presets', 'mosmcp-abilities' ),
			'layout.contentSize'      => __( 'content width', 'mosmcp-abilities' ),
			'layout.wideSize'         => __( 'wide width', 'mosmcp-abilities' ),
		);

		$labels = array();
		foreach ( array_diff( array_merge( self::leaf_paths( $patch ), self::null_paths( $patch ) ), $ignored ) as $path ) {
			$parts = explode( '.', $path );
			$root  = array_shift( $parts );
			if ( 'settings' === $root ) {
				$key      = implode( '.', array_slice( $parts, 0, 2 ) );
				$labels[] = isset( $settings[ $key ] ) ? $settings[ $key ] : __( 'style settings', 'mosmcp-abilities' );
				continue;
			}
			// Who it applies to: the whole site, an element ("heading", "link") or a block.
			$owner = '';
			if ( isset( $parts[0] ) && 'elements' === $parts[0] && isset( $parts[1] ) ) {
				$owner = $parts[1];
				$parts = array_slice( $parts, 2 );
			} elseif ( isset( $parts[0] ) && 'blocks' === $parts[0] && isset( $parts[1] ) ) {
				$owner = str_replace( array( 'core/', '-' ), array( '', ' ' ), $parts[1] ) . ' ' . __( 'block', 'mosmcp-abilities' );
				$parts = array_slice( $parts, 2 );
			}
			$key      = implode( '.', array_slice( $parts, 0, 2 ) );
			$property = isset( $properties[ $key ] ) ? $properties[ $key ] : ( isset( $parts[0] ) ? $parts[0] : __( 'styles', 'mosmcp-abilities' ) );
			$labels[] = trim( $owner . ' ' . $property );
		}
		return array_values( array_unique( $labels ) );
	}

	/**
	 * Dotted paths the patch gives an empty value ("{}" or "[]"), which merge() skips.
	 *
	 * @param array<string, mixed> $data   Data.
	 * @param string               $prefix Path so far.
	 * @return string[]
	 */
	private static function empty_paths( array $data, $prefix = '' ) {
		$paths = array();
		foreach ( $data as $key => $value ) {
			$path = '' === $prefix ? (string) $key : $prefix . '.' . $key;
			if ( array() === $value ) {
				$paths[] = $path;
			} elseif ( is_array( $value ) && ! self::is_list( $value ) ) {
				$paths = array_merge( $paths, self::empty_paths( $value, $path ) );
			}
		}
		return $paths;
	}

	/**
	 * Removes colour values a browser would ignore, recording each one.
	 *
	 * A colour is a string under a "color" object (text, background, gradient) or a
	 * "color" key inside a border, or a palette entry's colour.
	 *
	 * @param array<string, mixed> $data   Patch (or part of it).
	 * @param string               $prefix Dotted path so far.
	 * @param array<string,string> $bad    Path => "path = value", appended to.
	 * @return array<string, mixed>
	 */
	private static function drop_invalid_colours( array $data, $prefix, array &$bad ) {
		$parent = (string) substr( (string) strrchr( '.' . $prefix, '.' ), 1 );
		foreach ( $data as $key => $value ) {
			$path = '' === $prefix ? (string) $key : $prefix . '.' . $key;

			if ( 'palette' === $key && is_array( $value ) && self::is_list( $value ) ) {
				foreach ( $value as $index => $entry ) {
					if ( is_array( $entry ) && isset( $entry['color'] ) && ! self::is_css_colour( (string) $entry['color'], false ) ) {
						$bad[ $path . '.' . $index ] = $path . '.' . $index . ' = "' . $entry['color'] . '"';
						unset( $value[ $index ] );
					}
				}
				$data[ $key ] = array_values( $value );
				continue;
			}

			if ( is_array( $value ) ) {
				$data[ $key ] = self::drop_invalid_colours( $value, $path, $bad );
				// A group emptied by this check must vanish, not merge in as "{}" and
				// wipe the whole group already in the draft.
				if ( ! empty( $value ) && empty( $data[ $key ] ) ) {
					unset( $data[ $key ] );
				}
				continue;
			}

			$is_colour = ( 'color' === $parent && in_array( $key, array( 'text', 'background', 'gradient' ), true ) ) || ( 'color' === $key && 'color' !== $parent );
			if ( $is_colour && is_string( $value ) && ! self::is_css_colour( $value, 'text' !== $key ) ) {
				$bad[ $path ] = $path . ' = "' . $value . '"';
				unset( $data[ $key ] );
			}
		}
		return $data;
	}

	/**
	 * Whether a value is something a browser accepts as a colour.
	 *
	 * @param string $value          Value.
	 * @param bool   $allow_gradient Also accept CSS gradients.
	 * @return bool
	 */
	private static function is_css_colour( $value, $allow_gradient ) {
		$value = strtolower( trim( $value ) );
		if ( '' === $value ) {
			return false;
		}
		if ( 0 === strpos( $value, 'var:preset|' ) || 0 === strpos( $value, 'var(' ) ) {
			return true;
		}
		if ( preg_match( '/^#(?:[0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/', $value ) ) {
			return true;
		}
		if ( preg_match( '/^(?:rgba?|hsla?|hwb|lab|lch|oklab|oklch|color|color-mix)\(.+\)$/', $value ) ) {
			return true;
		}
		if ( $allow_gradient && preg_match( '/^(?:repeating-)?(?:linear|radial|conic)-gradient\(.+\)$/', $value ) ) {
			return true;
		}
		$keywords = 'transparent currentcolor inherit initial unset revert aliceblue antiquewhite aqua aquamarine azure beige bisque black blanchedalmond blue blueviolet brown burlywood cadetblue chartreuse chocolate coral cornflowerblue cornsilk crimson cyan darkblue darkcyan darkgoldenrod darkgray darkgreen darkgrey darkkhaki darkmagenta darkolivegreen darkorange darkorchid darkred darksalmon darkseagreen darkslateblue darkslategray darkslategrey darkturquoise darkviolet deeppink deepskyblue dimgray dimgrey dodgerblue firebrick floralwhite forestgreen fuchsia gainsboro ghostwhite gold goldenrod gray green greenyellow grey honeydew hotpink indianred indigo ivory khaki lavender lavenderblush lawngreen lemonchiffon lightblue lightcoral lightcyan lightgoldenrodyellow lightgray lightgreen lightgrey lightpink lightsalmon lightseagreen lightskyblue lightslategray lightslategrey lightsteelblue lightyellow lime limegreen linen magenta maroon mediumaquamarine mediumblue mediumorchid mediumpurple mediumseagreen mediumslateblue mediumspringgreen mediumturquoise mediumvioletred midnightblue mintcream mistyrose moccasin navajowhite navy oldlace olive olivedrab orange orangered orchid palegoldenrod palegreen paleturquoise palevioletred papayawhip peachpuff peru pink plum powderblue purple rebeccapurple red rosybrown royalblue saddlebrown salmon sandybrown seagreen seashell sienna silver skyblue slateblue slategray slategrey snow springgreen steelblue tan teal thistle tomato turquoise violet wheat white whitesmoke yellow yellowgreen';
		return in_array( $value, explode( ' ', $keywords ), true );
	}

	/**
	 * Turns every fontFamily in a styles patch that names one of the theme's font
	 * presets — by name ("Cardo"), slug ("cardo") or its full stack — into that
	 * preset's reference ("var:preset|font-family|cardo").
	 *
	 * @param array<string, mixed>              $patch  Styles patch.
	 * @param array<int, array<string, string>> $fonts  Font presets.
	 * @param string[]                          $linked Names linked, appended to.
	 * @return array<string, mixed>
	 */
	private static function link_font_presets( array $patch, array $fonts, array &$linked ) {
		foreach ( $patch as $key => $value ) {
			if ( is_array( $value ) ) {
				$patch[ $key ] = self::link_font_presets( $value, $fonts, $linked );
				continue;
			}
			if ( 'fontFamily' !== $key || ! is_string( $value ) || 0 === strpos( $value, 'var' ) ) {
				continue;
			}
			$wanted = strtolower( trim( $value, " \t\"'" ) );
			foreach ( $fonts as $font ) {
				$candidates = array( strtolower( $font['name'] ), strtolower( $font['slug'] ), strtolower( trim( $font['fontFamily'], " \t\"'" ) ) );
				if ( in_array( $wanted, $candidates, true ) ) {
					$patch[ $key ] = 'var:preset|font-family|' . $font['slug'];
					$linked[]      = $font['name'];
					break;
				}
			}
		}
		return $patch;
	}

	/**
	 * Keeps only some keys of an array.
	 *
	 * @param array<string, mixed> $data Data.
	 * @param string[]             $keys Keys.
	 * @return array<string, mixed>|\stdClass
	 */
	private static function pick( array $data, array $keys ) {
		$out = array_intersect_key( $data, array_flip( $keys ) );
		return empty( $out ) ? new \stdClass() : $out;
	}
}
