<?php
/**
 * Execute logic for the Additional CSS and theme settings abilities.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Theming\Customize;

use MoSMCP\Abilities\Packs\Site\Site_Support;
use WP_Error;
use MoSMCP\Abilities\Packs\Theming\Blocktheme\Block_Theme_Provider;
use MoSMCP\Abilities\Packs\Theming\Draft\Theme_Draft;
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
 * Class Customize_Provider
 */
class Customize_Provider {

	/**
	 * Most CSS returned in one answer, in bytes.
	 */
	const MAX_CSS_OUTPUT = 60000;

	/*
	 * Additional CSS
	 */

	/**
	 * Reads the Additional CSS, live and as the draft will make it.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function css_get( $input = array() ) {
		$input  = is_array( $input ) ? $input : array();
		$target = Site_Css::target( isset( $input['target'] ) ? (string) $input['target'] : 'auto' );
		if ( is_wp_error( $target ) ) {
			return $target;
		}

		$draft = Theme_Draft::current();
		$css   = Site_Css::effective( $draft, $target );
		$notes = array();
		$out   = array(
			'target'   => $target,
			'css'      => self::clip( $css ),
			'bytes'    => strlen( $css ),
			'pending'  => Site_Css::pending( $draft, $target ),
			'live_css' => self::clip( Site_Css::live( $target ) ),
			'other'    => '',
		);

		// A block theme also prints the Customizer's CSS, often left over from a classic theme.
		if ( 'global_styles' === $target ) {
			$other = Site_Css::effective( $draft, 'customizer' );
			if ( '' !== trim( $other ) ) {
				$out['other'] = self::clip( $other );
				$notes[]      = __( '"other" is older Additional CSS from the Customizer. This block theme still prints it, before the CSS above; change it by passing target "customizer".', 'mosmcp-abilities' );
			}
		}
		if ( $out['pending'] ) {
			$notes[] = __( '"css" includes a change waiting in the theme draft; "live_css" is what visitors get now.', 'mosmcp-abilities' );
		}
		if ( strlen( $css ) > self::MAX_CSS_OUTPUT ) {
			$notes[] = __( 'The CSS is long, so only its start is shown. Edit it with mosmcp/site-css-update in "edit" mode using text from the part shown, or "append".', 'mosmcp-abilities' );
		}
		$notes[]      = 'global_styles' === $target
			? __( 'This is the Site Editor\'s Additional CSS (Styles > Additional CSS). It loads after the theme\'s own styles.', 'mosmcp-abilities' )
			: __( 'This is the Customizer\'s Additional CSS (Appearance > Customize > Additional CSS). It loads after the theme\'s own styles.', 'mosmcp-abilities' );
		$out['notes'] = $notes;

		return $out;
	}

	/**
	 * Stages a change to the Additional CSS.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function css_update( $input = array() ) {
		$input  = is_array( $input ) ? $input : array();
		$target = Site_Css::target( isset( $input['target'] ) ? (string) $input['target'] : 'auto' );
		if ( is_wp_error( $target ) ) {
			return $target;
		}

		$draft = Theme_Draft::open();
		if ( is_wp_error( $draft ) ) {
			return $draft;
		}

		$current = Site_Css::effective( $draft, $target );
		$change  = Site_Css::change( $current, $input );
		if ( is_wp_error( $change ) ) {
			return $change;
		}
		if ( trim( $current ) === $change['css'] ) {
			return Site_Support::error( 'mosmcp_no_change', __( 'That leaves the Additional CSS exactly as it is, so nothing was staged.', 'mosmcp-abilities' ), Site_Support::CAUSE_INVALID_INPUT, false );
		}
		$checked = Site_Css::check( $change['css'], $current );
		if ( is_wp_error( $checked ) ) {
			return $checked;
		}

		$live    = Site_Css::live( $target );
		$dropped = false;
		if ( 'customizer' === $target ) {
			if ( trim( $live ) === $change['css'] ) {
				$draft['css'] = null;
				$dropped      = true;
			} else {
				$draft['css'] = array(
					'value'    => $change['css'],
					'label'    => $change['label'],
					'baseline' => ! empty( $draft['css'] ) ? (string) $draft['css']['baseline'] : Theme_Draft::fingerprint( $live ),
				);
			}
		} else {
			$styles = Theme_Publisher::live_user_styles();
			$user   = ! empty( $draft['styles'] ) ? (array) $draft['styles']['user_data'] : $styles;
			if ( '' === $change['css'] ) {
				unset( $user['styles']['css'] );
				if ( empty( $user['styles'] ) ) {
					unset( $user['styles'] );
				}
			} else {
				$user['styles']['css'] = $change['css'];
			}
			$changes         = ! empty( $draft['styles']['changes'] ) ? (array) $draft['styles']['changes'] : array();
			$draft['styles'] = array(
				'action'    => ! empty( $draft['styles'] ) && 'variation' === $draft['styles']['action'] ? 'variation' : 'update',
				'variation' => ! empty( $draft['styles'] ) ? (string) $draft['styles']['variation'] : '',
				'user_data' => $user,
				'baseline'  => ! empty( $draft['styles'] ) ? (string) $draft['styles']['baseline'] : Theme_Draft::fingerprint( $styles ),
				'changes'   => array_values( array_unique( array_merge( $changes, array( $change['label'] ) ) ) ),
			);
		}
		Theme_Draft::save( $draft );

		$notes = array();
		if ( 0 !== Site_Css::brace_balance( $change['css'] ) ) {
			$notes[] = __( 'The CSS already had unbalanced braces before this change, which makes browsers skip some rules. Check it with mosmcp/site-css-get.', 'mosmcp-abilities' );
		}
		if ( 'global_styles' === $target && '' !== trim( Site_Css::live( 'customizer' ) ) ) {
			$notes[] = __( 'This site also has older Customizer CSS, which loads before this CSS; mosmcp/site-css-get shows it.', 'mosmcp-abilities' );
		}

		if ( $dropped ) {
			$notes[] = __( 'That is exactly the CSS the live site has, so the pending CSS change was simply dropped from the draft.', 'mosmcp-abilities' );
		}

		$result            = Block_Theme_Provider::staged_result( $draft, __( 'the Additional CSS', 'mosmcp-abilities' ), $dropped ? 'discarded pending change' : $change['label'], $notes );
		$result['target']  = $target;
		$result['css']     = self::clip( $change['css'] );
		$result['bytes']   = strlen( $change['css'] );
		$result['matches'] = $change['matches'];

		return $result;
	}

	/*
	 * Theme settings
	 */

	/**
	 * Lists the settings the theme offers in the Customizer.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function settings_list( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$all   = Customizer_Bridge::settings();
		if ( is_wp_error( $all ) ) {
			return $all;
		}

		$draft    = Theme_Draft::current();
		$search   = isset( $input['search'] ) ? strtolower( trim( (string) $input['search'] ) ) : '';
		$section  = isset( $input['section'] ) ? strtolower( trim( (string) $input['section'] ) ) : '';
		$per_page = isset( $input['per_page'] ) ? max( 1, min( 100, (int) $input['per_page'] ) ) : 40;
		$offset   = isset( $input['offset'] ) ? max( 0, (int) $input['offset'] ) : 0;

		$sections = array();
		$matched  = array();
		foreach ( $all as $item ) {
			$key = (string) $item['section_id'];
			if ( ! isset( $sections[ $key ] ) ) {
				$sections[ $key ] = array(
					'id'       => $key,
					'title'    => (string) $item['section'],
					'panel'    => (string) $item['panel'],
					'settings' => 0,
				);
			}
			++$sections[ $key ]['settings'];

			if ( '' !== $section && strtolower( $key ) !== $section && strtolower( (string) $item['section'] ) !== $section ) {
				continue;
			}
			if ( '' !== $search && false === strpos( strtolower( implode( ' ', array( $item['id'], $item['label'], $item['description'], $item['section'], $item['panel'] ) ) ), $search ) ) {
				continue;
			}
			$entry             = is_array( $draft ) && isset( $draft['settings'][ $item['id'] ] ) ? $draft['settings'][ $item['id'] ] : null;
			$item['pending']   = null !== $entry;
			$item['new_value'] = null !== $entry ? $entry['value'] : null;
			unset( $item['section_id'] );
			$matched[] = $item;
		}

		$notes = array();
		if ( Theme_Support::is_block_theme() ) {
			$notes[] = __( 'This is a block theme: its colours, fonts and layout are global styles (mosmcp/theme-styles-get and mosmcp/theme-styles-update), so the Customizer offers few settings here.', 'mosmcp-abilities' );
		}
		if ( empty( $matched ) && ( '' !== $search || '' !== $section ) ) {
			$notes[] = __( 'Nothing matched. "sections" lists every section with its number of settings; search by a word from a section title or a setting label.', 'mosmcp-abilities' );
		}
		$notes[] = __( 'Change values with mosmcp/theme-settings-update. Each value is checked by the theme\'s own rules and waits in the theme draft until it is published.', 'mosmcp-abilities' );

		return array(
			'theme'    => Theme_Support::stylesheet(),
			'total'    => count( $matched ),
			'settings' => array_slice( $matched, $offset, $per_page ),
			'sections' => array_values( $sections ),
			'notes'    => $notes,
		);
	}

	/**
	 * Stages new values for theme settings, all or none.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function settings_update( $input = array() ) {
		$input  = is_array( $input ) ? $input : array();
		$values = isset( $input['settings'] ) && is_array( $input['settings'] ) ? $input['settings'] : array();
		if ( empty( $values ) ) {
			return Site_Support::error(
				'mosmcp_settings_required',
				__( 'Give "settings": an object of setting ID to new value, for example {"astra-settings[site-content-width]": 1100}. Find IDs with mosmcp/theme-settings-list.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_INVALID_INPUT,
				false,
				array( 'use_instead' => 'mosmcp/theme-settings-list' )
			);
		}

		$draft = Theme_Draft::open();
		if ( is_wp_error( $draft ) ) {
			return $draft;
		}

		$results = Customizer_Bridge::validate( $values );
		if ( is_wp_error( $results ) ) {
			return $results;
		}

		$problems = array();
		foreach ( array_keys( $values ) as $id ) {
			$result = isset( $results[ $id ] ) ? $results[ $id ] : array( 'ok' => false );
			if ( ! empty( $result['ok'] ) ) {
				continue;
			}
			if ( isset( $result['owner'] ) ) {
				/* translators: 1: setting ID, 2: ability name. */
				$problems[] = sprintf( __( '"%1$s" is changed with %2$s instead', 'mosmcp-abilities' ), $id, $result['owner'] );
			} elseif ( isset( $result['type'] ) ) {
				/* translators: 1: setting ID, 2: storage type. */
				$problems[] = sprintf( __( '"%1$s" is stored by its own code (type "%2$s"), so it can only be changed in the Customizer itself', 'mosmcp-abilities' ), $id, $result['type'] );
			} elseif ( isset( $result['message'] ) ) {
				/* translators: 1: setting ID, 2: the theme's reason. */
				$problems[] = sprintf( __( '"%1$s" was refused: %2$s', 'mosmcp-abilities' ), $id, $result['message'] );
			} else {
				/* translators: %s: setting ID. */
				$problems[] = sprintf( __( '"%s" is not a setting this theme offers', 'mosmcp-abilities' ), $id );
			}
		}
		if ( ! empty( $problems ) ) {
			return Site_Support::error(
				'mosmcp_settings_refused',
				__( 'Nothing was staged: ', 'mosmcp-abilities' ) . implode( '; ', $problems ) . '. ' . __( 'mosmcp/theme-settings-list shows each setting\'s ID, current value and allowed choices.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_INVALID_INPUT,
				false,
				array( 'use_instead' => 'mosmcp/theme-settings-list' )
			);
		}

		$staged    = array();
		$unchanged = array();
		$dropped   = 0;
		foreach ( array_keys( $values ) as $id ) {
			$result   = $results[ $id ];
			$stored   = Theme_Settings::stored( $result['type'], $result['base'], $result['keys'] );
			$existing = isset( $draft['settings'][ $id ] ) ? $draft['settings'][ $id ] : null;

			if ( Theme_Draft::fingerprint( $result['value'] ) === Theme_Draft::fingerprint( null === $stored ? $result['default'] : $stored ) ) {
				// Back to what the live site has: nothing to publish for it.
				if ( null !== $existing ) {
					unset( $draft['settings'][ $id ] );
					++$dropped;
				}
				$unchanged[] = '' !== $result['label'] ? $result['label'] : $id;
				continue;
			}

			$entry                    = array(
				'id'       => $id,
				'value'    => $result['value'],
				'type'     => $result['type'],
				'base'     => $result['base'],
				'keys'     => $result['keys'],
				'label'    => $result['label'],
				'default'  => $result['default'],
				'baseline' => null !== $existing ? (string) $existing['baseline'] : Theme_Draft::fingerprint( $stored ),
			);
			$entry['summary']         = Theme_Settings::label( $entry );
			$draft['settings'][ $id ] = $entry;
			$staged[]                 = array(
				'id'    => $id,
				'label' => $result['label'],
				'value' => $result['value'],
			);
		}
		if ( empty( $staged ) && 0 === $dropped ) {
			return Site_Support::error(
				'mosmcp_no_change',
				__( 'Those settings already have those values on the live site, so nothing was staged.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_INVALID_INPUT,
				false
			);
		}
		Theme_Draft::save( $draft );

		$notes = array();
		if ( ! empty( $unchanged ) ) {
			$notes[] = sprintf(
				/* translators: %s: comma-separated setting names. */
				__( 'Already set that way on the live site, so nothing is pending for: %s.', 'mosmcp-abilities' ),
				implode( ', ', $unchanged )
			);
		}
		$notes[] = __( 'Publishing saves these through the Customizer, so the theme updates its own generated styles exactly as when a person clicks Publish there.', 'mosmcp-abilities' );

		$result              = Block_Theme_Provider::staged_result( $draft, __( 'the theme settings', 'mosmcp-abilities' ), 'updated', $notes );
		$result['settings']  = $staged;
		$result['unchanged'] = $unchanged;

		return $result;
	}

	/**
	 * CSS short enough to return.
	 *
	 * @param string $css CSS.
	 * @return string
	 */
	private static function clip( $css ) {
		return strlen( $css ) > self::MAX_CSS_OUTPUT ? substr( $css, 0, self::MAX_CSS_OUTPUT ) . "\n/* … */" : $css;
	}
}
