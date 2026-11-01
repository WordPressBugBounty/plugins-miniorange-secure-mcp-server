<?php
/**
 * Execute logic for the site logo and site icon abilities.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Theming\Identity;

use MoSMCP\Abilities\Packs\Site\Site_Support;
use WP_Error;
use MoSMCP\Abilities\Packs\Theming\Blocktheme\Block_Theme_Provider;
use MoSMCP\Abilities\Packs\Theming\Draft\Theme_Draft;

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
 * Class Site_Identity_Provider
 */
class Site_Identity_Provider {

	/**
	 * Reports the logo and icon as they are and as the draft will make them.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>
	 */
	public static function get_branding( $input = array() ) {
		unset( $input );

		$draft   = Theme_Draft::current();
		$display = Site_Identity::logo_display( $draft );
		$notes   = array();
		$out     = array(
			'title'   => (string) get_bloginfo( 'name' ),
			'tagline' => (string) get_bloginfo( 'description' ),
		);

		foreach ( Site_Identity::KINDS as $kind ) {
			$live  = Site_Identity::live( $kind );
			$entry = Site_Identity::entry( $draft, $kind );
			$item  = array(
				'live'    => Site_Identity::describe( $live ),
				'pending' => null === $entry ? '' : ( (int) $entry['value'] > 0 ? 'set' : 'remove' ),
				'draft'   => Site_Identity::describe( Site_Identity::effective( $draft, $kind ) ),
			);
			if ( $live > 0 && 0 === $item['live']['id'] ) {
				$notes[] = sprintf(
					/* translators: 1: "logo" or "site icon", 2: attachment ID. */
					__( 'The %1$s setting points to media item %2$d, which no longer exists, so no %1$s shows.', 'mosmcp-abilities' ),
					'logo' === $kind ? __( 'logo', 'mosmcp-abilities' ) : __( 'site icon', 'mosmcp-abilities' ),
					$live
				);
			}
			if ( 'logo' === $kind ) {
				$item['shown']     = $display['shown'];
				$item['shown_in']  = $display['where'];
				$item['size_hint'] = $display['hint'];
			}
			$out[ $kind ] = $item;
		}

		$hidden = Site_Identity::hidden_logo_note( $display );
		if ( '' !== $hidden ) {
			$notes[] = $hidden;
		}
		if ( '' !== $out['logo']['pending'] || '' !== $out['icon']['pending'] ) {
			$notes[] = __( 'A change is waiting in the theme draft ("draft" shows the result). Visitors see it only once the draft is published with mosmcp/theme-draft-publish.', 'mosmcp-abilities' );
		}
		if ( 0 === $out['icon']['draft']['id'] ) {
			$notes[] = __( 'The site has no icon, so browser tabs and bookmarks show a generic one. Set one with mosmcp/site-set-icon.', 'mosmcp-abilities' );
		}
		$notes[] = __( 'The site title and tagline change immediately, outside the draft, with mosmcp/site-update-title and mosmcp/site-update-tagline.', 'mosmcp-abilities' );

		$out['notes'] = $notes;
		return $out;
	}

	/**
	 * Stages a new logo, or removing it.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function set_logo( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$draft = Theme_Draft::open();
		if ( is_wp_error( $draft ) ) {
			return $draft;
		}

		$source = self::chosen( $input );
		if ( is_wp_error( $source ) ) {
			return $source;
		}

		$result = self::stage( $draft, 'logo', $source['id'], $source['id'], $source['created'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$display = Site_Identity::logo_display( $result['_draft'] );
		$hidden  = Site_Identity::hidden_logo_note( $display );
		if ( '' !== $hidden ) {
			$result['notes'][] = $hidden;
		} elseif ( $source['id'] > 0 ) {
			$result['notes'][] = sprintf(
				/* translators: %s: where the logo appears. */
				__( 'Once published it appears in %s.', 'mosmcp-abilities' ),
				wp_sprintf( '%l', $display['where'] )
			);
		}
		if ( $source['id'] > 0 && '' !== $display['hint'] ) {
			$result['notes'][] = $display['hint'];
		}

		unset( $result['_draft'] );
		$result['logo']     = Site_Identity::describe( $source['id'] );
		$result['shown']    = $display['shown'];
		$result['shown_in'] = $display['where'];

		return $result;
	}

	/**
	 * Stages a new site icon, or removing it.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function set_icon( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$draft = Theme_Draft::open();
		if ( is_wp_error( $draft ) ) {
			return $draft;
		}

		$focus   = isset( $input['focus'] ) && isset( Site_Identity::FOCUS[ (string) $input['focus'] ] ) ? (string) $input['focus'] : 'center';
		$cropped = false;
		$value   = 0;
		$note    = '';

		$source = self::chosen( $input );
		if ( is_wp_error( $source ) ) {
			return $source;
		}

		if ( $source['id'] > 0 ) {
			$square = Site_Identity::square_copy( get_post( $source['id'] ), $focus );
			if ( is_wp_error( $square ) ) {
				// Nothing was staged, so an image added just for this goes too.
				Site_Identity::remove_unused( $source['created'], $draft );
				return $square;
			}
			$value   = $square['id'];
			$cropped = $square['cropped'];
			$note    = $square['note'];
			if ( $cropped ) {
				$source['created'][] = $value;
			}
		}

		$result = self::stage( $draft, 'icon', $value, $source['id'], $source['created'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		unset( $result['_draft'] );

		if ( $cropped ) {
			$from              = Site_Identity::describe( $source['id'] );
			$result['notes'][] = $from['width'] === $from['height']
				? sprintf(
					/* translators: %d: icon size. */
					__( 'The image was copied at %1$d × %1$d pixels, with the smaller sizes browsers and phones ask for.', 'mosmcp-abilities' ),
					Site_Identity::ICON_SIZE
				)
				: sprintf(
					/* translators: 1: width, 2: height, 3: icon size, 4: focus position. */
					__( 'The image is %1$d × %2$d, so a %3$d × %3$d square copy was cut from it keeping the %4$s part. If the important part is elsewhere, call again with a different focus.', 'mosmcp-abilities' ),
					$from['width'],
					$from['height'],
					Site_Identity::ICON_SIZE,
					str_replace( '-', ' ', $focus )
				);
		}
		if ( '' !== $note ) {
			$result['notes'][] = $note;
		}
		if ( $value > 0 ) {
			$result['notes'][] = __( 'Browsers keep site icons for a while, so a tab may show the old icon until it is reloaded or opened in a private window.', 'mosmcp-abilities' );
		}

		$result['icon']      = Site_Identity::describe( $value );
		$result['source_id'] = (int) $source['id'];
		$result['cropped']   = $cropped;

		return $result;
	}

	/**
	 * The image the input chooses, or ID 0 when it asks for removal.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array{id:int,created:int[]}|WP_Error
	 */
	private static function chosen( array $input ) {
		$image = ! empty( $input['attachment_id'] ) || ( isset( $input['url'] ) && '' !== trim( (string) $input['url'] ) );

		if ( ! empty( $input['remove'] ) ) {
			if ( $image ) {
				return Site_Support::error(
					'mosmcp_image_source_conflict',
					__( 'Give either remove, or an image (attachment_id or url), not both.', 'mosmcp-abilities' ),
					Site_Support::CAUSE_INVALID_INPUT,
					false
				);
			}
			return array(
				'id'      => 0,
				'created' => array(),
			);
		}

		return Site_Identity::source( $input );
	}

	/**
	 * Puts a value in the draft, dropping the pending change when it matches the
	 * live site, and deletes images this draft added that are no longer needed.
	 *
	 * @param array<string, mixed> $draft   Draft.
	 * @param string               $kind    Kind.
	 * @param int                  $value   New value, 0 to remove.
	 * @param int                  $source  Image chosen (the icon's value is a copy of it).
	 * @param int[]                $created Images added to the media library by this call.
	 * @return array<string, mixed>|WP_Error Staged result, with the saved draft under "_draft".
	 */
	private static function stage( array $draft, $kind, $value, $source, array $created ) {
		$live     = Site_Identity::live( $kind );
		$existing = Site_Identity::entry( $draft, $kind );
		$earlier  = null === $existing ? array() : array_map( 'absint', (array) $existing['created'] );
		$target   = 'logo' === $kind ? __( 'the site logo', 'mosmcp-abilities' ) : __( 'the site icon', 'mosmcp-abilities' );

		// Images an earlier call in this draft added and this value still builds on stay with it.
		$carried = array_values( array_intersect( $earlier, array( $value, $source ) ) );

		if ( $value === $live ) {
			if ( null === $existing ) {
				Site_Identity::remove_unused( $created, $draft );
				if ( 0 === $live ) {
					$message = 'logo' === $kind
						? __( 'The site has no logo at the moment, so there is nothing to remove and nothing was staged.', 'mosmcp-abilities' )
						: __( 'The site has no icon at the moment, so there is nothing to remove and nothing was staged.', 'mosmcp-abilities' );
				} else {
					/* translators: %s: "the site logo" or "the site icon". */
					$message = sprintf( __( 'That image is already %s on the live site, so nothing was staged.', 'mosmcp-abilities' ), $target );
				}
				return Site_Support::error( 'mosmcp_no_change', $message, Site_Support::CAUSE_INVALID_INPUT, false );
			}
			unset( $draft['identity'][ $kind ] );
			Theme_Draft::save( $draft );
			Site_Identity::remove_unused( array_merge( $earlier, $created ), $draft );

			$result           = Block_Theme_Provider::staged_result( $draft, $target, 'discarded pending change', array( __( 'That is what the live site already has, so the pending change was simply dropped from the draft.', 'mosmcp-abilities' ) ) );
			$result['_draft'] = $draft;
			return $result;
		}

		$draft['identity'][ $kind ] = array(
			'value'    => $value,
			'label'    => Site_Identity::label( $kind, $value ),
			'baseline' => null === $existing ? (string) $live : (string) $existing['baseline'],
			'created'  => array_values( array_unique( array_merge( $created, $carried ) ) ),
		);
		Theme_Draft::save( $draft );
		Site_Identity::remove_unused( array_diff( $earlier, $carried ), $draft );

		$result           = Block_Theme_Provider::staged_result( $draft, $target, $value > 0 ? 'set' : 'removed', array() );
		$result['_draft'] = $draft;
		return $result;
	}
}
