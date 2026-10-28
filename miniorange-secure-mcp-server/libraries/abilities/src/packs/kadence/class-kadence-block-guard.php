<?php
/**
 * Keeps the generic block abilities from breaking Kadence blocks.
 *
 * Kadence blocks are ordinary editor blocks, so the block abilities (insert,
 * replace, duplicate) can write them too. Kadence keys its generated CSS to each
 * block's uniqueID, so a second block with the same ID takes the first one's
 * styling and both break. The block abilities stay builder-agnostic and ask
 * through their filters; this class answers for Kadence.
 *
 * It is booted with the block abilities, not with the Kadence abilities, so the
 * protection holds even where the Kadence abilities are switched off.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Kadence;

use MoSMCP\Abilities\Config;
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
 * Class Kadence_Block_Guard
 */
class Kadence_Block_Guard {

	/**
	 * Hooks into the block abilities' placement check and editing advice.
	 *
	 * @return void
	 */
	public static function boot() {
		add_filter( Config::prefix() . '_blocks_check_placement', array( __CLASS__, 'check_ids' ), 10, 4 );
		add_filter( Config::prefix() . '_blocks_editing_hints', array( __CLASS__, 'hints' ), 10, 2 );
	}

	/**
	 * Refuses blocks whose Kadence uniqueIDs already exist on the page.
	 *
	 * @param true|WP_Error                    $allowed True so far, or an earlier refusal.
	 * @param array<int, array<string, mixed>> $nodes   Blocks about to be placed.
	 * @param array<int, array<string, mixed>> $tree    The rest of the page they are going into.
	 * @param string                           $context 'place' or 'duplicate'.
	 * @return true|WP_Error
	 */
	public static function check_ids( $allowed, $nodes, $tree, $context ) {
		if ( $allowed instanceof WP_Error || ! is_array( $nodes ) || ! is_array( $tree ) ) {
			return $allowed;
		}

		$existing = array();
		Kadence_Blocks_Helper::walk(
			$tree,
			static function ( $block ) use ( &$existing ) {
				$id = Kadence_Blocks_Helper::is_kadence( $block ) ? Kadence_Blocks_Helper::unique_id( $block ) : '';
				if ( '' !== $id ) {
					$existing[ $id ] = true;
				}
			}
		);

		$clash = '';
		Kadence_Blocks_Helper::walk(
			$nodes,
			static function ( $block ) use ( $existing, &$clash ) {
				$id = Kadence_Blocks_Helper::is_kadence( $block ) ? Kadence_Blocks_Helper::unique_id( $block ) : '';
				if ( '' === $clash && '' !== $id && isset( $existing[ $id ] ) ) {
					$clash = $id;
				}
			}
		);

		if ( '' === $clash ) {
			return $allowed;
		}

		if ( 'duplicate' === $context ) {
			return Site_Support::error(
				'mosmcp_block_duplicate_kadence',
				__( 'This block contains Kadence blocks, which need fresh unique IDs when copied or the copy and the original break each other\'s styling. Use mosmcp/kadence-block-duplicate, which assigns new IDs.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_CONFLICT,
				false,
				array( 'use_instead' => 'mosmcp/kadence-block-duplicate' )
			);
		}

		return Site_Support::error(
			'mosmcp_block_kadence_id_clash',
			sprintf(
				/* translators: %s: Kadence uniqueID. */
				__( 'The markup reuses the Kadence block ID "%s", which a block on this page already has. Two blocks with one ID share and break each other\'s styling. Use the Kadence abilities (mosmcp/kadence-block-duplicate, mosmcp/kadence-insert-*) which assign fresh IDs, or remove the uniqueID attributes from the markup.', 'mosmcp-abilities' ),
				$clash
			),
			Site_Support::CAUSE_CONFLICT,
			true,
			array( 'use_instead' => 'mosmcp/kadence-block-duplicate' )
		);
	}

	/**
	 * Points the AI to the Kadence abilities for Kadence blocks.
	 *
	 * @param string[] $hints      Advice so far.
	 * @param string   $block_name Block name.
	 * @return string[]
	 */
	public static function hints( $hints, $block_name ) {
		$hints = (array) $hints;

		if ( 0 === strpos( (string) $block_name, Kadence_Blocks_Helper::PREFIX ) && Kadence_Helpers::blocks_active() ) {
			$hints[] = __( 'Kadence blocks also have their own abilities (mosmcp/kadence-*, such as mosmcp/kadence-block-update-attributes for settings), which keep their unique IDs and generated CSS correct.', 'mosmcp-abilities' );
		}

		return $hints;
	}
}
