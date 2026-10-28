<?php
/**
 * Preview links ability pack: let someone view unpublished content without signing in.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Previews;

use MoSMCP\Abilities\Ability;
use MoSMCP\Abilities\Ability_Pack;
use MoSMCP\Abilities\Packs\Core\Support\Core_Schema as Schema;
use MoSMCP\Abilities\Packs\Site\Site_Support;

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
 * Class Previews_Pack
 *
 * Declares the preview link abilities. Logic lives in Preview_Links.
 */
class Previews_Pack extends Ability_Pack {

	/**
	 * Ability category slug.
	 */
	const CATEGORY = 'mosmcp-previews';

	/**
	 * Ability category for preview links.
	 *
	 * @return array Category definition.
	 */
	public function category() {
		return array(
			'slug'        => self::CATEGORY,
			'label'       => __( 'Preview Links', 'mosmcp-abilities' ),
			'description' => __( 'Create and cancel expiring links that let anyone view a draft without signing in.', 'mosmcp-abilities' ),
		);
	}

	/**
	 * The preview link abilities.
	 *
	 * @return Ability[]
	 */
	public function abilities() {
		return array(
			new Ability(
				'mosmcp/preview-link-create',
				array(
					'label'         => __( 'Create Preview Link', 'mosmcp-abilities' ),
					'description'   => __( 'Makes a private, expiring link to a draft, pending or scheduled post, page or other public content, which anyone holding it can open without signing in. Use it so the person can check your work straight from the chat, or share a draft with a colleague or client before it goes live. The link shows the latest saved version, is hidden from search engines and caches, expires after 48 hours by default (up to 7 days), and can be cancelled with mosmcp/preview-link-revoke. Published content does not need one. Also known as: share draft, preview link, show me the draft, send draft for review, public preview.', 'mosmcp-abilities' ),
					'category'      => self::CATEGORY,
					'capability'    => 'edit_post',
					'cap_args'      => self::id_args(),
					'annotations'   => Site_Support::annotations( false, false, false, false ),
					'execute'       => array( Preview_Links::class, 'create' ),
					'input_schema'  => Schema::object(
						array(
							'id'    => Schema::int(
								__( 'ID of the draft post, page or other content item.', 'mosmcp-abilities' ),
								array( 'minimum' => 1 )
							),
							'hours' => Schema::int(
								__( 'How long the link works, in hours.', 'mosmcp-abilities' ),
								array(
									'default' => Preview_Links::DEFAULT_HOURS,
									'minimum' => 1,
									'maximum' => Preview_Links::MAX_HOURS,
								)
							),
						),
						array( 'id' )
					),
					'output_schema' => Schema::object(
						array(
							'id'             => Schema::int(),
							'title'          => Schema::str(),
							'status'         => Schema::str(),
							'url'            => Schema::str( __( 'The link to share.', 'mosmcp-abilities' ) ),
							'expires_at'     => Schema::str( __( 'Expiry in the site\'s time zone.', 'mosmcp-abilities' ) ),
							'expires_at_gmt' => Schema::str(),
							'active_links'   => Schema::int( __( 'Unexpired links for this content, including this one.', 'mosmcp-abilities' ) ),
							'notes'          => Schema::arr( Schema::str() ),
						),
						array( 'id', 'url', 'expires_at' )
					),
				)
			),
			new Ability(
				'mosmcp/preview-link-revoke',
				array(
					'label'         => __( 'Revoke Preview Links', 'mosmcp-abilities' ),
					'description'   => __( 'Cancels every preview link made for one post, page or other content item, so anyone who opens one gets a not-found page. Use it when a draft was shared with the wrong person or should no longer be seen. Safe to repeat. Also known as: cancel preview link, stop sharing draft, revoke share link.', 'mosmcp-abilities' ),
					'category'      => self::CATEGORY,
					'capability'    => 'edit_post',
					'cap_args'      => self::id_args(),
					'annotations'   => Site_Support::annotations( false, false, true, false ),
					'execute'       => array( Preview_Links::class, 'revoke' ),
					'input_schema'  => Schema::object(
						array(
							'id' => Schema::int(
								__( 'ID of the content whose preview links should stop working.', 'mosmcp-abilities' ),
								array( 'minimum' => 1 )
							),
						),
						array( 'id' )
					),
					'output_schema' => Schema::object(
						array(
							'id'      => Schema::int(),
							'title'   => Schema::str(),
							'revoked' => Schema::int( __( 'How many active links were cancelled.', 'mosmcp-abilities' ) ),
							'notes'   => Schema::arr( Schema::str() ),
						),
						array( 'id', 'revoked' )
					),
				)
			),
		);
	}

	/**
	 * Maps input to the post ID for the object-level capability check.
	 *
	 * @return callable
	 */
	private static function id_args() {
		return static function ( $input ) {
			return array( isset( $input['id'] ) ? absint( $input['id'] ) : 0 );
		};
	}
}
