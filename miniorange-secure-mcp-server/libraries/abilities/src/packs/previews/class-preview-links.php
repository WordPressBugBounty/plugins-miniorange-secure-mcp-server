<?php
/**
 * Shareable, expiring preview links for unpublished content.
 *
 * WordPress's own preview only works for someone signed in who may edit the
 * post. A preview link made here lets anyone holding it view one draft until it
 * expires, so a person can check an AI's work from the chat, or send it to a
 * colleague or client, without an account.
 *
 * Only a hash of each token is stored, so the database never holds a working
 * link. A request is let through only when all of these hold: it is the main
 * front-end query for one post, that post is still unpublished, and the token
 * matches one of that post's unexpired links in constant time. The page is sent
 * with noindex and no-store headers so it is neither indexed nor cached.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Previews;

use MoSMCP\Abilities\Packs\Site\Site_Support;
use WP_Error;
use WP_Post;
use WP_Query;

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
 * Class Preview_Links
 */
class Preview_Links {

	/**
	 * Query argument carrying the token.
	 */
	const QUERY_VAR = 'mosmcp_preview';

	/**
	 * Post meta holding the link hashes and expiry times.
	 */
	const META_KEY = '_mosmcp_preview_links';

	/**
	 * Most links kept per post; the oldest are dropped first.
	 */
	const MAX_LINKS = 10;

	/**
	 * Default and maximum link lifetime, in hours.
	 */
	const DEFAULT_HOURS = 48;
	const MAX_HOURS     = 168;

	/**
	 * Statuses a preview link can be made for.
	 *
	 * @var string[]
	 */
	const STATUSES = array( 'draft', 'pending', 'future' );

	/**
	 * Whether this request has already been let through, so headers are sent once.
	 *
	 * @var bool
	 */
	private static $granted = false;

	/**
	 * Hooks the front-end check, only on requests that carry a token.
	 *
	 * @return void
	 */
	public static function boot() {
		if ( '' === self::request_token() ) {
			return;
		}

		add_filter( 'posts_results', array( __CLASS__, 'reveal' ), 10, 2 );
		add_filter( 'wp_robots', array( __CLASS__, 'robots' ) );
	}

	/**
	 * Creates a link for a post.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function create( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$post  = self::require_previewable( $input );
		if ( $post instanceof WP_Error ) {
			return $post;
		}

		$hours   = isset( $input['hours'] ) ? max( 1, min( self::MAX_HOURS, (int) $input['hours'] ) ) : self::DEFAULT_HOURS;
		$token   = wp_generate_password( 32, false, false );
		$expires = time() + $hours * HOUR_IN_SECONDS;
		$links   = self::live_links( $post->ID );

		$links[] = array(
			'hash'       => hash( 'sha256', $token ),
			'expires'    => $expires,
			'created'    => time(),
			'created_by' => get_current_user_id(),
		);

		if ( count( $links ) > self::MAX_LINKS ) {
			$links = array_slice( $links, -self::MAX_LINKS );
		}

		update_post_meta( $post->ID, self::META_KEY, wp_slash( $links ) );

		return array(
			'id'             => (int) $post->ID,
			'title'          => (string) get_the_title( $post ),
			'status'         => (string) $post->post_status,
			'url'            => self::url( $post, $token ),
			'expires_at'     => wp_date( 'Y-m-d H:i:s', $expires ),
			'expires_at_gmt' => gmdate( 'Y-m-d H:i:s', $expires ),
			'active_links'   => count( $links ),
			'notes'          => array(
				__( 'Anyone with this link can view this content, without signing in, until it expires or is revoked. It shows the latest saved version each time it is opened.', 'mosmcp-abilities' ),
				__( 'The link stops working once the content is published (its normal address works then) and can be cancelled at any time with mosmcp/preview-link-revoke.', 'mosmcp-abilities' ),
			),
		);
	}

	/**
	 * Revokes every link for a post.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function revoke( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$id    = isset( $input['id'] ) ? absint( $input['id'] ) : 0;
		$post  = $id > 0 ? get_post( $id ) : null;

		if ( ! $post instanceof WP_Post ) {
			return self::not_found();
		}

		$count = count( self::live_links( $post->ID ) );
		delete_post_meta( $post->ID, self::META_KEY );

		return array(
			'id'      => (int) $post->ID,
			'title'   => (string) get_the_title( $post ),
			'revoked' => $count,
			'notes'   => array(
				0 === $count
					? __( 'There were no active preview links for this content.', 'mosmcp-abilities' )
					: __( 'Every preview link for this content has stopped working. Anyone who opens one now gets a not-found page.', 'mosmcp-abilities' ),
			),
		);
	}

	/**
	 * Lets the main front-end query show one unpublished post to a valid token.
	 *
	 * Hooked to posts_results, which runs before WP_Query hides non-public posts
	 * from visitors; marking the one matching post as published in memory is what
	 * makes WordPress render it. Nothing is written to the database.
	 *
	 * @param WP_Post[] $posts Query results.
	 * @param WP_Query  $query The query.
	 * @return WP_Post[]
	 */
	public static function reveal( $posts, $query ) {
		if ( is_admin() || ! $query instanceof WP_Query || ! $query->is_main_query() || ! $query->is_singular() ) {
			return $posts;
		}

		if ( ! is_array( $posts ) || 1 !== count( $posts ) || ! $posts[0] instanceof WP_Post ) {
			return $posts;
		}

		$post = $posts[0];
		if ( ! in_array( $post->post_status, self::STATUSES, true ) || ! self::token_matches( $post->ID, self::request_token() ) ) {
			return $posts;
		}

		$post->post_status = 'publish';
		self::$granted     = true;

		/*
		 * Seeing the post as published, WordPress would redirect this ID address to
		 * the post's pretty permalink, dropping the token on the way, and the
		 * visitor would land on a not-found page.
		 */
		add_filter( 'redirect_canonical', '__return_false' );

		if ( ! headers_sent() ) {
			nocache_headers();
			header( 'X-Robots-Tag: noindex, nofollow' );
		}

		return $posts;
	}

	/**
	 * Keeps a previewed draft out of search engines.
	 *
	 * @param array<string, bool> $robots Robots directives.
	 * @return array<string, bool>
	 */
	public static function robots( $robots ) {
		if ( self::$granted ) {
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
		}

		return $robots;
	}

	/**
	 * Whether a token matches one of a post's unexpired links.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $token   Token from the request.
	 * @return bool
	 */
	private static function token_matches( $post_id, $token ) {
		if ( '' === $token ) {
			return false;
		}

		$hash = hash( 'sha256', $token );

		foreach ( self::live_links( $post_id ) as $link ) {
			if ( hash_equals( (string) $link['hash'], $hash ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A post's links that have not expired.
	 *
	 * @param int $post_id Post ID.
	 * @return array<int, array<string, mixed>>
	 */
	private static function live_links( $post_id ) {
		$links = get_post_meta( (int) $post_id, self::META_KEY, true );
		$now   = time();

		if ( ! is_array( $links ) ) {
			return array();
		}

		return array_values(
			array_filter(
				$links,
				static function ( $link ) use ( $now ) {
					return is_array( $link ) && ! empty( $link['hash'] ) && isset( $link['expires'] ) && (int) $link['expires'] > $now;
				}
			)
		);
	}

	/**
	 * The token supplied with this request, or ''.
	 *
	 * @return string
	 */
	private static function request_token() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- The token is itself the credential and is compared in constant time against a stored hash.
		if ( ! isset( $_GET[ self::QUERY_VAR ] ) || ! is_string( $_GET[ self::QUERY_VAR ] ) ) {
			return '';
		}

		$token = sanitize_text_field( wp_unslash( $_GET[ self::QUERY_VAR ] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return preg_match( '/^[A-Za-z0-9]{32}$/', $token ) ? $token : '';
	}

	/**
	 * The address a link opens.
	 *
	 * Built from the post ID rather than its pretty permalink: an unpublished post
	 * may have no permalink yet, and an ID query is the form WordPress resolves
	 * for any status.
	 *
	 * @param WP_Post $post  Post.
	 * @param string  $token Token.
	 * @return string
	 */
	private static function url( WP_Post $post, $token ) {
		if ( 'page' === $post->post_type ) {
			$args = array( 'page_id' => (int) $post->ID );
		} elseif ( 'post' === $post->post_type ) {
			$args = array( 'p' => (int) $post->ID );
		} else {
			$args = array(
				'post_type' => $post->post_type,
				'p'         => (int) $post->ID,
			);
		}

		$args[ self::QUERY_VAR ] = $token;

		return add_query_arg( $args, home_url( '/' ) );
	}

	/**
	 * Resolves the post and confirms a link can be made for it.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return WP_Post|WP_Error
	 */
	private static function require_previewable( array $input ) {
		$id   = isset( $input['id'] ) ? absint( $input['id'] ) : 0;
		$post = $id > 0 ? get_post( $id ) : null;

		if ( ! $post instanceof WP_Post || 'auto-draft' === $post->post_status ) {
			return self::not_found();
		}

		$type = get_post_type_object( $post->post_type );
		if ( ! $type || ! $type->public ) {
			return Site_Support::error(
				'mosmcp_preview_not_public_type',
				sprintf(
					/* translators: %s: post type. */
					__( 'Content of type %s is never shown on the front end, so there is nothing to preview.', 'mosmcp-abilities' ),
					$post->post_type
				),
				Site_Support::CAUSE_INVALID_INPUT,
				false
			);
		}

		if ( 'publish' === $post->post_status ) {
			return Site_Support::error(
				'mosmcp_preview_already_published',
				sprintf(
					/* translators: %s: public URL. */
					__( 'This is already published, so its normal address works for everyone: %s', 'mosmcp-abilities' ),
					(string) get_permalink( $post )
				),
				Site_Support::CAUSE_CONFLICT,
				false,
				array( 'view_url' => (string) get_permalink( $post ) )
			);
		}

		if ( ! in_array( $post->post_status, self::STATUSES, true ) ) {
			return Site_Support::error(
				'mosmcp_preview_status',
				sprintf(
					/* translators: %s: post status. */
					__( 'A preview link cannot be made for content with status "%s". Links are for drafts, pending and scheduled content; private and trashed content stays private.', 'mosmcp-abilities' ),
					$post->post_status
				),
				Site_Support::CAUSE_CONFLICT,
				false
			);
		}

		return $post;
	}

	/**
	 * Not-found refusal.
	 *
	 * @return WP_Error
	 */
	private static function not_found() {
		return Site_Support::error(
			'mosmcp_preview_post_not_found',
			__( 'No post, page or other content item has that ID.', 'mosmcp-abilities' ),
			Site_Support::CAUSE_NOT_FOUND,
			false
		);
	}
}
