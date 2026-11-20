<?php
/**
 * Execute callbacks for the read-only SEO abilities.
 *
 * These only read, and only what the user may already read: published posts of
 * public types for any logged-in user, drafts and private posts under WordPress's
 * normal rules, and never a password-protected post's data to someone who cannot
 * edit it. No SEO plugin ability is called, since those require edit rights.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Seo;

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
 * directive covers IDE and Plugin Check runs that don't load that ruleset).
 */
// phpcs:disable WordPress.WP.I18n.TextDomainMismatch

/**
 * Class Seo_Provider
 */
class Seo_Provider {

	/**
	 * Lengths past which search engines usually cut a title or description off.
	 */
	const TITLE_MAX       = 60;
	const DESCRIPTION_MAX = 160;

	/**
	 * Issues a post can be flagged with, and filtered by.
	 *
	 * @var string[]
	 */
	const ISSUES = array( 'missing_title', 'title_too_long', 'missing_description', 'description_not_written', 'description_too_long', 'missing_focus_keyword', 'noindex' );

	/**
	 * Statuses read when "any" is asked for.
	 *
	 * @var string[]
	 */
	const STATUSES = array( 'publish', 'future', 'draft', 'pending', 'private' );

	/**
	 * Default and largest page size for the list.
	 */
	const DEFAULT_PER_PAGE = 20;
	const MAX_PER_PAGE     = 50;

	/**
	 * Most posts one list call reads, and how many it fetches at a time.
	 */
	const MAX_SCAN = 500;
	const BATCH    = 100;

	/**
	 * Reads one post's SEO data.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function get_post( $input = array() ) {
		$input = (array) $input;
		$post  = self::readable_post( isset( $input['id'] ) ? $input['id'] : 0 );

		if ( $post instanceof WP_Error ) {
			return $post;
		}

		$reader = Seo_Readers::pick();
		$data   = $reader->read( $post );

		return array(
			'id'               => (int) $post->ID,
			'title'            => Seo_Reader::plain( get_the_title( $post ) ),
			'post_type'        => (string) $post->post_type,
			'status'           => (string) $post->post_status,
			'url'              => (string) get_permalink( $post ),
			'seo_plugin'       => self::plugin( $reader ),
			'seo_title'        => array(
				'saved'     => $data['title_saved'],
				'effective' => $data['title'],
				'length'    => self::length( $data['title'] ),
			),
			'meta_description' => array(
				'saved'     => $data['description_saved'],
				'effective' => $data['description'],
				'length'    => self::length( $data['description'] ),
			),
			'focus_keywords'   => $data['focus_keywords'],
			'canonical'        => array(
				'saved'     => $data['canonical_saved'],
				'effective' => $data['canonical'],
			),
			'robots'           => array(
				'noindex'  => (bool) $data['noindex'],
				'nofollow' => (bool) $data['nofollow'],
			),
			'social'           => array(
				'og_title'            => $data['og_title'],
				'og_description'      => $data['og_description'],
				'og_image'            => $data['og_image'],
				'twitter_title'       => $data['twitter_title'],
				'twitter_description' => $data['twitter_description'],
				'twitter_image'       => $data['twitter_image'],
			),
			'issues'           => self::issues( $reader, $data ),
			'notes'            => self::notes( $reader, $data, $post ),
		);
	}

	/**
	 * Lists posts with their SEO data, optionally only those with one issue.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function list_posts( $input = array() ) {
		$input = (array) $input;
		$types = self::post_types( isset( $input['post_type'] ) ? (string) $input['post_type'] : '' );

		if ( $types instanceof WP_Error ) {
			return $types;
		}

		$status   = isset( $input['status'] ) && '' !== (string) $input['status'] ? (string) $input['status'] : 'publish';
		$statuses = 'any' === $status ? self::STATUSES : array( $status );
		$issue    = isset( $input['issue'] ) ? (string) $input['issue'] : '';
		$search   = isset( $input['search'] ) ? trim( (string) $input['search'] ) : '';
		$per_page = isset( $input['per_page'] ) ? absint( $input['per_page'] ) : self::DEFAULT_PER_PAGE;
		$per_page = max( 1, min( $per_page, self::MAX_PER_PAGE ) );
		$page     = isset( $input['page'] ) ? max( 1, absint( $input['page'] ) ) : 1;
		$skip     = ( $page - 1 ) * $per_page;
		$reader   = Seo_Readers::pick();

		$items      = array();
		$matched    = 0;
		$scanned    = 0;
		$has_more   = false;
		$limited    = false;
		$query_page = 1;

		while ( true ) {
			$args = array(
				'post_type'              => $types,
				'post_status'            => $statuses,
				'perm'                   => 'readable',
				'posts_per_page'         => self::BATCH,
				'paged'                  => $query_page,
				'orderby'                => array(
					'date' => 'DESC',
					'ID'   => 'DESC',
				),
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
			);
			if ( '' !== $search ) {
				$args['s'] = $search;
			}

			$query = new WP_Query( $args );

			foreach ( $query->posts as $post ) {
				if ( $scanned >= self::MAX_SCAN ) {
					$limited = true;
					break 2;
				}
				++$scanned;

				if ( ! self::can_read( $post ) ) {
					continue;
				}

				$data   = $reader->read( $post );
				$issues = self::issues( $reader, $data );

				if ( '' !== $issue && ! in_array( $issue, $issues, true ) ) {
					continue;
				}

				++$matched;
				if ( $matched <= $skip ) {
					continue;
				}

				if ( count( $items ) >= $per_page ) {
					$has_more = true;
					break 2;
				}

				$items[] = array(
					'id'                      => (int) $post->ID,
					'title'                   => Seo_Reader::plain( get_the_title( $post ) ),
					'url'                     => (string) get_permalink( $post ),
					'post_type'               => (string) $post->post_type,
					'status'                  => (string) $post->post_status,
					'seo_title'               => $data['title'],
					'seo_title_length'        => self::length( $data['title'] ),
					'meta_description'        => $data['description'],
					'meta_description_length' => self::length( $data['description'] ),
					'focus_keyword'           => $data['focus_keywords'] ? (string) $data['focus_keywords'][0] : '',
					'noindex'                 => (bool) $data['noindex'],
					'issues'                  => $issues,
				);
			}

			if ( count( $query->posts ) < self::BATCH ) {
				break;
			}
			++$query_page;
		}

		$notes = self::notes( $reader, Seo_Reader::blank(), null );
		if ( $limited ) {
			$notes[] = sprintf(
				/* translators: %d: number of posts read */
				__( 'Only the newest %d matching posts were checked in this call. Narrow it with post_type, status or search to reach older ones.', 'mosmcp-abilities' ),
				self::MAX_SCAN
			);
		}

		return array(
			'seo_plugin'   => self::plugin( $reader ),
			'page'         => $page,
			'per_page'     => $per_page,
			'returned'     => count( $items ),
			'has_more'     => $has_more,
			'scanned'      => $scanned,
			'scan_limited' => $limited,
			'items'        => $items,
			'notes'        => $notes,
		);
	}

	/**
	 * The post, if it is public content the current user may read the SEO data of.
	 *
	 * @param mixed $id Post ID.
	 * @return WP_Post|WP_Error
	 */
	private static function readable_post( $id ) {
		$id   = absint( $id );
		$post = $id > 0 ? get_post( $id ) : null;

		if ( ! $post ) {
			return Site_Support::error(
				'mosmcp_post_not_found',
				sprintf(
					/* translators: %d: the post ID supplied. */
					__( 'No post with ID %d exists.', 'mosmcp-abilities' ),
					$id
				),
				Site_Support::CAUSE_NOT_FOUND,
				false
			);
		}

		if ( ! is_post_type_viewable( $post->post_type ) ) {
			return Site_Support::error(
				'mosmcp_seo_not_public_type',
				sprintf(
					/* translators: %s: post type slug. */
					__( 'Items of type "%s" are not public pages, so they have no SEO data to read.', 'mosmcp-abilities' ),
					$post->post_type
				),
				Site_Support::CAUSE_INVALID_INPUT,
				false
			);
		}

		if ( ! self::can_read( $post ) ) {
			return Site_Support::error(
				'mosmcp_seo_cannot_read',
				'' !== $post->post_password
					? __( 'This post is password protected, so its SEO data is only shown to people who can edit it.', 'mosmcp-abilities' )
					: __( 'Your account cannot read this post, so its SEO data cannot be shown.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_CAPABILITY,
				false
			);
		}

		return $post;
	}

	/**
	 * Whether the current user may read a post's SEO data.
	 *
	 * @param WP_Post $post Post.
	 * @return bool
	 */
	private static function can_read( WP_Post $post ) {
		if ( ! current_user_can( 'read_post', $post->ID ) ) {
			return false;
		}

		return '' === $post->post_password || current_user_can( 'edit_post', $post->ID );
	}

	/**
	 * The post types to list: the one asked for, or every public type but media.
	 *
	 * @param string $type Requested post type, or '' for all.
	 * @return string[]|WP_Error
	 */
	private static function post_types( $type ) {
		if ( '' === $type ) {
			$types = array_values( array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment' ) ) );
			return array_values( array_filter( $types, 'is_post_type_viewable' ) );
		}

		if ( ! post_type_exists( $type ) || ! is_post_type_viewable( $type ) ) {
			return Site_Support::error(
				'mosmcp_seo_not_public_type',
				sprintf(
					/* translators: %s: post type slug. */
					__( '"%s" is not a public content type on this site.', 'mosmcp-abilities' ),
					$type
				),
				Site_Support::CAUSE_INVALID_INPUT,
				true
			);
		}

		return array( $type );
	}

	/**
	 * What is worth fixing about a post's SEO data.
	 *
	 * @param Seo_Reader           $reader Reader used.
	 * @param array<string, mixed> $data   Reader output.
	 * @return string[]
	 */
	private static function issues( Seo_Reader $reader, array $data ) {
		$issues = array();

		if ( '' === $data['title'] ) {
			$issues[] = 'missing_title';
		} elseif ( self::length( $data['title'] ) > self::TITLE_MAX ) {
			$issues[] = 'title_too_long';
		}

		if ( '' === $data['description'] ) {
			$issues[] = 'missing_description';
		} elseif ( self::length( $data['description'] ) > self::DESCRIPTION_MAX ) {
			$issues[] = 'description_too_long';
		}

		if ( 'none' !== $reader->slug() && '' === $data['description_saved'] ) {
			$issues[] = 'description_not_written';
		}

		if ( $reader->has_focus_keywords() && empty( $data['focus_keywords'] ) ) {
			$issues[] = 'missing_focus_keyword';
		}

		if ( $data['noindex'] ) {
			$issues[] = 'noindex';
		}

		return $issues;
	}

	/**
	 * Notes that explain the values: the reader's own, and site-wide conditions.
	 *
	 * @param Seo_Reader           $reader Reader used.
	 * @param array<string, mixed> $data   Reader output.
	 * @param WP_Post|null         $post   Post, for a single read.
	 * @return string[]
	 */
	private static function notes( Seo_Reader $reader, array $data, $post ) {
		$notes = (array) $data['notes'];

		if ( 'none' === $reader->slug() ) {
			$notes[] = __( 'No supported SEO plugin is active, so this is what WordPress itself gives search engines: the document title and, on public sites, the canonical link. WordPress prints no meta description or focus keyword of its own.', 'mosmcp-abilities' );
		}

		$active = Seo_Readers::active();
		if ( count( $active ) > 1 ) {
			$notes[] = sprintf(
				/* translators: 1: comma-separated SEO plugin names, 2: the one whose data is shown. */
				__( 'More than one SEO plugin is active (%1$s), which usually means both print their own tags. The data shown is %2$s\'s.', 'mosmcp-abilities' ),
				implode(
					', ',
					array_map(
						static function ( Seo_Reader $r ) {
							return $r->name();
						},
						$active
					)
				),
				$reader->name()
			);
		}

		if ( Seo_Reader::site_hidden() ) {
			$notes[] = __( 'WordPress is set to discourage search engines (Settings > Reading), so every page is noindex whatever the SEO plugin\'s settings say.', 'mosmcp-abilities' );
		}

		if ( $post instanceof WP_Post && 'publish' !== $post->post_status ) {
			$notes[] = sprintf(
				/* translators: %s: post status. */
				__( 'This item is "%s", not published, so search engines do not see it yet; the effective values are what will be output once it is published.', 'mosmcp-abilities' ),
				$post->post_status
			);
		}

		return $notes;
	}

	/**
	 * The SEO plugin a result came from.
	 *
	 * @param Seo_Reader $reader Reader used.
	 * @return array<string, string>
	 */
	private static function plugin( Seo_Reader $reader ) {
		return array(
			'slug'    => $reader->slug(),
			'name'    => $reader->name(),
			'version' => $reader->version(),
		);
	}

	/**
	 * Length in characters, as search engines count it.
	 *
	 * @param string $text Text.
	 * @return int
	 */
	private static function length( $text ) {
		return (int) mb_strlen( (string) $text, 'UTF-8' );
	}
}
