<?php
/**
 * Rank Math ability pack for per-post SEO metadata.
 *
 * Provides a narrow write ability for a post's SEO title, meta description,
 * and focus keyword. Other Rank Math fields are intentionally excluded because
 * they require separate handling or are not directly writable.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Rankmath;

use MoSMCP\Abilities\Ability;
use MoSMCP\Abilities\Ability_Pack;
use MoSMCP\Abilities\Packs\Core\Support\Core_Schema as Schema;

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
 * Class Rankmath_Pack
 */
class Rankmath_Pack extends Ability_Pack {

	/**
	 * Ability category slug.
	 */
	const CATEGORY = 'mosmcp-rankmath';

	/**
	 * Ability category for Rank Math abilities.
	 *
	 * @return array Category definition.
	 */
	public function category() {
		return array(
			'slug'        => self::CATEGORY,
			'label'       => __( 'Rank Math SEO', 'mosmcp-abilities' ),
			'description' => __( 'Write a single post\'s Rank Math SEO metadata: SEO title, meta description, and focus keyword.', 'mosmcp-abilities' ),
		);
	}

	/**
	 * Whether Rank Math is installed.
	 *
	 * RANK_MATH_VERSION is defined as the plugin file loads, before its product
	 * registration is checked, which is the right gate here: the write itself is
	 * a post meta write and works on an unregistered site. Only the rendered
	 * preview needs Rank Math's template engine, and that degrades to a warning
	 * ({@see Rankmath_Support::can_render()}).
	 *
	 * @return string
	 */
	public function dependency() {
		return 'RANK_MATH_VERSION';
	}

	/**
	 * The Rank Math abilities.
	 *
	 * @return Ability[]
	 */
	public function abilities() {
		return array(
			$this->update_post_seo(),
		);
	}

	/**
	 * Defines the mosmcp/rankmath-update-post-seo ability.
	 *
	 * Not named rank-math/set-post-seo-meta: that squats Rank Math's own
	 * namespace and would collide if Rank Math ships its own writer later. The
	 * library forces the host's prefix onto every authored name in any case.
	 *
	 * @return Ability
	 */
	private function update_post_seo() {
		return new Ability(
			'mosmcp/rankmath-update-post-seo',
			array(
				'label'            => __( 'Update Post SEO Metadata (Rank Math)', 'mosmcp-abilities' ),
				// The %title% and %sep% in this description are Rank Math template
				// variables quoted verbatim for the reader, not printf placeholders,
				// so there is nothing for a translators comment to explain.
				// phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment
				'description'      => __( 'Writes a post\'s Rank Math SEO title, meta description and focus keyword. Send only the fields to change; each field sent replaces what is stored, and a field sent as an empty string removes the stored value so Rank Math falls back to the site-wide template for it. Do not copy values out of Rank Math\'s own "Get post SEO metadata" ability into this one: that ability returns the finished text a search engine sees, with the site-wide template already filled in and an excerpt substituted for a missing description, so writing it back freezes this post\'s SEO title into a fixed string that stops following the post title. The response reports the previous stored values, so an unintended overwrite can be undone. Template variables such as %title% and %sep% are stored as written and resolved when the page is served. The SEO score is not recalculated by this write.', 'mosmcp-abilities' ),
				'category'         => self::CATEGORY,
				'capability'       => 'edit_post',
				'cap_args'         => self::post_id_args(),
				'permission_extra' => self::onpage_cap_check(),
				'annotations'      => self::annotations( false, false, true, true ),
				'execute'          => array( Rankmath_Provider::class, 'update_post_seo' ),
				'input_schema'     => Schema::object(
					array(
						'post_id'       => Schema::int( __( 'The ID of the post or page whose SEO metadata to write.', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
						'title'         => Schema::str( __( 'The SEO title, which is what search engines display as the clickable headline — not the post title shown on the page. May contain Rank Math template variables. Send an empty string to remove it and use the site-wide title template again. Omit to leave unchanged.', 'mosmcp-abilities' ) ),
						'description'   => Schema::str( __( 'The meta description shown under the headline in search results. May contain Rank Math template variables. Send an empty string to remove it and use the site-wide description template again. Omit to leave unchanged.', 'mosmcp-abilities' ) ),
						'focus_keyword' => Schema::str( __( 'The focus keyword this post targets, used by Rank Math\'s own content analysis and not shown to visitors. Several keywords go in one comma-separated string, the first being the primary. Send an empty string to remove it. Omit to leave unchanged.', 'mosmcp-abilities' ) ),
					),
					array( 'post_id' )
				),
				'output_schema'    => Schema::object(
					array(
						'saved'        => Schema::boolean( __( 'Whether anything was actually written. False when every field sent already held the value sent.', 'mosmcp-abilities' ) ),
						'post_id'      => Schema::int(),
						'updated'      => Schema::arr( Schema::str(), __( 'Fields whose stored value was replaced.', 'mosmcp-abilities' ) ),
						'cleared'      => Schema::arr( Schema::str(), __( 'Fields whose stored value was removed, restoring the site-wide template.', 'mosmcp-abilities' ) ),
						'unchanged'    => Schema::arr( Schema::str(), __( 'Fields that were sent but already held the value sent.', 'mosmcp-abilities' ) ),
						'previous_raw' => Schema::map( __( 'All three fields exactly as stored before this write, empty where nothing was stored. This is what to send back to undo the write.', 'mosmcp-abilities' ) ),
						'current_raw'  => Schema::map( __( 'All three fields exactly as stored after this write.', 'mosmcp-abilities' ) ),
						'rendered'     => Schema::map( __( 'What the stored title and description currently resolve to once Rank Math replaces the template variables.', 'mosmcp-abilities' ) ),
						'warnings'     => Schema::warnings(),
					),
					array( 'saved', 'post_id', 'updated', 'cleared', 'previous_raw' )
				),
			)
		);
	}

	/**
	 * Resolves the post_id input for the object-level edit_post check (cap_args).
	 *
	 * @return callable
	 */
	private static function post_id_args() {
		return static function ( $input ) {
			return array( isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0 );
		};
	}

	/**
	 * The extra permission check ANDed after edit_post.
	 *
	 * Rank Math's Role Manager grants on-page SEO editing separately from
	 * WordPress editing rights, so gating on edit_post alone would let this
	 * ability write SEO metadata for a user that Rank Math's own policy blocks
	 * from touching it. Administrators hold both by default.
	 *
	 * @return callable
	 */
	private static function onpage_cap_check() {
		return static function ( $input ) {
			unset( $input );

			return current_user_can( Rankmath_Support::ONPAGE_CAP );
		};
	}

	/**
	 * Builds the four MCP annotation hints.
	 *
	 * @param bool $read_only   Read-only hint.
	 * @param bool $destructive Destructive hint.
	 * @param bool $idempotent  Idempotent hint.
	 * @param bool $open_world  Open-world hint.
	 * @return array<string, bool>
	 */
	private static function annotations( $read_only, $destructive, $idempotent, $open_world ) {
		return array(
			'readonly'    => $read_only,
			'destructive' => $destructive,
			'idempotent'  => $idempotent,
			'open_world'  => $open_world,
		);
	}
}
