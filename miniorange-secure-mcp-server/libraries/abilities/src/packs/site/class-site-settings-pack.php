<?php
/**
 * Site Settings ability pack: definitions for the mosmcp/site-get-* and
 * mosmcp/site-update-* / mosmcp/site-set-* abilities.
 *
 * Reads gate on edit_posts because every value they return is already visible to
 * anyone who can view the site; writes gate on manage_options, which is the real
 * boundary. The site address and home URL are deliberately not writable: changing
 * either severs the connection this request arrived on, mid-call, and is the
 * commonest way a WordPress site is made unreachable.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Site;

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
 * directive covers IDE and Plugin Check runs that don't read that ruleset).
 */
// phpcs:disable WordPress.WP.I18n.TextDomainMismatch

/**
 * Class Site_Settings_Pack
 *
 * Declares the site settings abilities. Execute logic lives in Site_Settings_Provider.
 */
class Site_Settings_Pack extends Ability_Pack {

	/**
	 * Ability category slug.
	 */
	const CATEGORY = 'mosmcp-site-settings';

	/**
	 * Ability category for site settings abilities.
	 *
	 * @return array Category definition.
	 */
	public function category() {
		return array(
			'slug'        => self::CATEGORY,
			'label'       => __( 'Site Settings', 'mosmcp-abilities' ),
			'description' => __( 'Read and change the site title, tagline, timezone, language, reading settings, homepage, and privacy policy page.', 'mosmcp-abilities' ),
		);
	}

	/**
	 * The site settings abilities.
	 *
	 * @return Ability[]
	 */
	public function abilities() {
		return array_merge( $this->read_abilities(), $this->write_abilities() );
	}

	/**
	 * The ten read abilities.
	 *
	 * @return Ability[]
	 */
	private function read_abilities() {
		return array(
			$this->get_title(),
			$this->get_tagline(),
			$this->get_url(),
			$this->get_timezone(),
			$this->get_language(),
			$this->get_permalink_structure(),
			$this->get_posts_per_page(),
			$this->get_homepage(),
			$this->get_privacy_policy_page(),
			$this->get_search_visibility(),
		);
	}

	/**
	 * The seven write abilities.
	 *
	 * @return Ability[]
	 */
	private function write_abilities() {
		return array(
			$this->update_title(),
			$this->update_tagline(),
			$this->update_timezone(),
			$this->update_posts_per_page(),
			$this->set_homepage(),
			$this->set_posts_page(),
			$this->set_privacy_policy_page(),
		);
	}

	/**
	 * Defines the mosmcp/site-get-title ability.
	 *
	 * @return Ability
	 */
	private function get_title() {
		return new Ability(
			'mosmcp/site-get-title',
			array(
				'label'         => __( 'Get Site Title', 'mosmcp-abilities' ),
				'description'   => __( 'Returns the site title, the name shown in the browser tab and usually in the site header. Read-only.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'edit_posts',
				'annotations'   => Site_Support::annotations( true, false, true, false ),
				'execute'       => array( Site_Settings_Provider::class, 'get_title' ),
				'input_schema'  => Schema::object( array() ),
				'output_schema' => Schema::object(
					array( 'title' => Schema::str( __( 'The site title.', 'mosmcp-abilities' ) ) ),
					array( 'title' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/site-get-tagline ability.
	 *
	 * @return Ability
	 */
	private function get_tagline() {
		return new Ability(
			'mosmcp/site-get-tagline',
			array(
				'label'         => __( 'Get Site Tagline', 'mosmcp-abilities' ),
				'description'   => __( 'Returns the site tagline, the short description shown under the site title by most themes. Read-only.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'edit_posts',
				'annotations'   => Site_Support::annotations( true, false, true, false ),
				'execute'       => array( Site_Settings_Provider::class, 'get_tagline' ),
				'input_schema'  => Schema::object( array() ),
				'output_schema' => Schema::object(
					array(
						'tagline'    => Schema::str( __( 'The site tagline.', 'mosmcp-abilities' ) ),
						'is_default' => Schema::boolean( __( 'True when the tagline is still the WordPress default, which usually means nobody has set one.', 'mosmcp-abilities' ) ),
					),
					array( 'tagline' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/site-get-url ability.
	 *
	 * @return Ability
	 */
	private function get_url() {
		return new Ability(
			'mosmcp/site-get-url',
			array(
				'label'         => __( 'Get Site URL', 'mosmcp-abilities' ),
				'description'   => __( 'Returns the site address visitors use, the WordPress address where the files live, and the admin URL. Read-only. These two addresses differ on a site where WordPress is installed in a subdirectory, which is a common source of confusion when building links.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'edit_posts',
				'annotations'   => Site_Support::annotations( true, false, true, false ),
				'execute'       => array( Site_Settings_Provider::class, 'get_url' ),
				'input_schema'  => Schema::object( array() ),
				'output_schema' => Schema::object(
					array(
						'home_url'     => Schema::str( __( 'The address visitors use to reach the site.', 'mosmcp-abilities' ) ),
						'site_url'     => Schema::str( __( 'The address where the WordPress files live.', 'mosmcp-abilities' ) ),
						'admin_url'    => Schema::str(),
						'scheme'       => Schema::str( __( 'Either "http" or "https".', 'mosmcp-abilities' ) ),
						'host'         => Schema::str(),
						'is_ssl'       => Schema::boolean( __( 'Whether this request reached the site over HTTPS.', 'mosmcp-abilities' ) ),
						'in_subdir'    => Schema::boolean( __( 'True when WordPress is installed in a subdirectory of the site address.', 'mosmcp-abilities' ) ),
						'is_multisite' => Schema::boolean(),
						'notes'        => Schema::arr( Schema::str() ),
					),
					array( 'home_url', 'site_url' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/site-get-timezone ability.
	 *
	 * @return Ability
	 */
	private function get_timezone() {
		return new Ability(
			'mosmcp/site-get-timezone',
			array(
				'label'         => __( 'Get Site Timezone', 'mosmcp-abilities' ),
				'description'   => __( 'Returns the timezone the site uses and what its local time is right now. Read-only. Check this before scheduling a post, because a scheduled time is interpreted in the site timezone rather than the reader\'s.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'edit_posts',
				'annotations'   => Site_Support::annotations( true, false, true, false ),
				'execute'       => array( Site_Settings_Provider::class, 'get_timezone' ),
				'input_schema'  => Schema::object( array() ),
				'output_schema' => Schema::object(
					array(
						'timezone_string'    => Schema::str( __( 'Timezone identifier such as "Europe/London", or empty when the site uses a fixed offset instead.', 'mosmcp-abilities' ) ),
						'gmt_offset'         => Schema::str( __( 'Hours ahead of UTC, as a number.', 'mosmcp-abilities' ) ),
						'uses_manual_offset' => Schema::boolean( __( 'True when the site is set to a fixed offset rather than a named zone, which means daylight saving is never applied automatically.', 'mosmcp-abilities' ) ),
						'current_local_time' => Schema::str( __( 'The site\'s local time now, as Y-m-d H:i:s.', 'mosmcp-abilities' ) ),
						'current_utc_time'   => Schema::str(),
						'notes'              => Schema::arr( Schema::str() ),
					),
					array( 'timezone_string', 'gmt_offset', 'current_local_time' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/site-get-language ability.
	 *
	 * @return Ability
	 */
	private function get_language() {
		return new Ability(
			'mosmcp/site-get-language',
			array(
				'label'         => __( 'Get Site Language', 'mosmcp-abilities' ),
				'description'   => __( 'Returns the site language, the languages installed on it, the date and time formats, and which day the week starts on. Read-only. Write content in the site language unless told otherwise, and match these date formats when producing dates for display.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'edit_posts',
				'annotations'   => Site_Support::annotations( true, false, true, false ),
				'execute'       => array( Site_Settings_Provider::class, 'get_language' ),
				'input_schema'  => Schema::object( array() ),
				'output_schema' => Schema::object(
					array(
						'locale'              => Schema::str( __( 'Active locale, for example "en_GB".', 'mosmcp-abilities' ) ),
						'available_languages' => Schema::arr( Schema::str(), __( 'Locales installed on this site and available to switch to.', 'mosmcp-abilities' ) ),
						'is_rtl'              => Schema::boolean( __( 'True when the active language reads right to left.', 'mosmcp-abilities' ) ),
						'date_format'         => Schema::str( __( 'PHP date format the site displays dates in.', 'mosmcp-abilities' ) ),
						'time_format'         => Schema::str(),
						'start_of_week'       => Schema::int( __( 'Day the week starts on, where 0 is Sunday.', 'mosmcp-abilities' ) ),
					),
					array( 'locale' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/site-get-permalink-structure ability.
	 *
	 * @return Ability
	 */
	private function get_permalink_structure() {
		return new Ability(
			'mosmcp/site-get-permalink-structure',
			array(
				'label'         => __( 'Get Permalink Structure', 'mosmcp-abilities' ),
				'description'   => __( 'Returns how the site builds its URLs, plus the category and tag bases. Read-only. Worth checking before changing a slug, because on a site using plain permalinks a slug has no effect on the visible URL at all.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'edit_posts',
				'annotations'   => Site_Support::annotations( true, false, true, false ),
				'execute'       => array( Site_Settings_Provider::class, 'get_permalink_structure' ),
				'input_schema'  => Schema::object( array() ),
				'output_schema' => Schema::object(
					array(
						'structure'     => Schema::str( __( 'The permalink structure string, or empty when the site uses plain query-string URLs.', 'mosmcp-abilities' ) ),
						'is_pretty'     => Schema::boolean( __( 'True when the site uses readable URLs rather than query-string ones.', 'mosmcp-abilities' ) ),
						'category_base' => Schema::str(),
						'tag_base'      => Schema::str(),
						'sample_url'    => Schema::str( __( 'A real post URL from this site, showing the structure in use.', 'mosmcp-abilities' ) ),
						'notes'         => Schema::arr( Schema::str() ),
					),
					array( 'structure', 'is_pretty' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/site-get-posts-per-page ability.
	 *
	 * @return Ability
	 */
	private function get_posts_per_page() {
		return new Ability(
			'mosmcp/site-get-posts-per-page',
			array(
				'label'         => __( 'Get Posts Per Page', 'mosmcp-abilities' ),
				'description'   => __( 'Returns how many posts the site shows on a blog or archive page, how many appear in its feeds, and whether feeds carry full text or an excerpt. Read-only.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'edit_posts',
				'annotations'   => Site_Support::annotations( true, false, true, false ),
				'execute'       => array( Site_Settings_Provider::class, 'get_posts_per_page' ),
				'input_schema'  => Schema::object( array() ),
				'output_schema' => Schema::object(
					array(
						'posts_per_page'  => Schema::int( __( 'Posts shown per page on the blog and archives.', 'mosmcp-abilities' ) ),
						'posts_per_rss'   => Schema::int(),
						'rss_use_excerpt' => Schema::boolean( __( 'True when feeds carry a summary rather than the full post.', 'mosmcp-abilities' ) ),
					),
					array( 'posts_per_page' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/site-get-homepage ability.
	 *
	 * @return Ability
	 */
	private function get_homepage() {
		return new Ability(
			'mosmcp/site-get-homepage',
			array(
				'label'         => __( 'Get Homepage Settings', 'mosmcp-abilities' ),
				'description'   => __( 'Returns whether the front page shows the latest posts or a fixed page, and which pages are used as the front page and the blog. Read-only.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'edit_posts',
				'annotations'   => Site_Support::annotations( true, false, true, false ),
				'execute'       => array( Site_Settings_Provider::class, 'get_homepage' ),
				'input_schema'  => Schema::object( array() ),
				'output_schema' => Schema::object(
					array(
						'shows'      => Schema::str( __( 'Either "latest_posts" or "static_page".', 'mosmcp-abilities' ) ),
						'front_page' => self::page_ref(),
						'posts_page' => self::page_ref(),
						'notes'      => Schema::arr( Schema::str() ),
					),
					array( 'shows' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/site-get-privacy-policy-page ability.
	 *
	 * @return Ability
	 */
	private function get_privacy_policy_page() {
		return new Ability(
			'mosmcp/site-get-privacy-policy-page',
			array(
				'label'         => __( 'Get Privacy Policy Page', 'mosmcp-abilities' ),
				'description'   => __( 'Returns which page is set as the site privacy policy and whether it is published. Read-only.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'edit_posts',
				'annotations'   => Site_Support::annotations( true, false, true, false ),
				'execute'       => array( Site_Settings_Provider::class, 'get_privacy_policy_page' ),
				'input_schema'  => Schema::object( array() ),
				'output_schema' => Schema::object(
					array(
						'is_set' => Schema::boolean( __( 'False when no privacy policy page has been chosen.', 'mosmcp-abilities' ) ),
						'page'   => self::page_ref(),
						'notes'  => Schema::arr( Schema::str() ),
					),
					array( 'is_set' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/site-get-search-visibility ability.
	 *
	 * @return Ability
	 */
	private function get_search_visibility() {
		return new Ability(
			'mosmcp/site-get-search-visibility',
			array(
				'label'         => __( 'Get Search Engine Visibility', 'mosmcp-abilities' ),
				'description'   => __( 'Returns whether the site asks search engines not to index it. Read-only. Worth checking first whenever someone reports that their site is not appearing in search results, because this one setting explains it more often than anything else.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'edit_posts',
				'annotations'   => Site_Support::annotations( true, false, true, false ),
				'execute'       => array( Site_Settings_Provider::class, 'get_search_visibility' ),
				'input_schema'  => Schema::object( array() ),
				'output_schema' => Schema::object(
					array(
						'discourage_search_engines' => Schema::boolean( __( 'True when the site asks search engines to stay away.', 'mosmcp-abilities' ) ),
						'notes'                     => Schema::arr( Schema::str() ),
					),
					array( 'discourage_search_engines' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/site-update-title ability.
	 *
	 * @return Ability
	 */
	private function update_title() {
		return new Ability(
			'mosmcp/site-update-title',
			array(
				'label'         => __( 'Update Site Title', 'mosmcp-abilities' ),
				'description'   => __( 'Changes the site title. Returns the previous and new value so the change can be verified rather than assumed. Reversible by calling again with the old title.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'manage_options',
				'annotations'   => Site_Support::annotations( false, false, true, false ),
				'execute'       => array( Site_Settings_Provider::class, 'update_title' ),
				'input_schema'  => Schema::object(
					array(
						'title' => Schema::str(
							__( 'The new site title. Plain text; any HTML is stripped.', 'mosmcp-abilities' ),
							array(
								'minLength' => 1,
								'maxLength' => 200,
							)
						),
					),
					array( 'title' )
				),
				'output_schema' => self::string_change_output(),
			)
		);
	}

	/**
	 * Defines the mosmcp/site-update-tagline ability.
	 *
	 * @return Ability
	 */
	private function update_tagline() {
		return new Ability(
			'mosmcp/site-update-tagline',
			array(
				'label'         => __( 'Update Site Tagline', 'mosmcp-abilities' ),
				'description'   => __( 'Changes the site tagline. Pass an empty string to clear it. Returns the previous and new value. Reversible by calling again with the old tagline.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'manage_options',
				'annotations'   => Site_Support::annotations( false, false, true, false ),
				'execute'       => array( Site_Settings_Provider::class, 'update_tagline' ),
				'input_schema'  => Schema::object(
					array(
						'tagline' => Schema::str(
							__( 'The new tagline. Plain text; any HTML is stripped. Pass an empty string to remove it.', 'mosmcp-abilities' ),
							array( 'maxLength' => 300 )
						),
					),
					array( 'tagline' )
				),
				'output_schema' => self::string_change_output(),
			)
		);
	}

	/**
	 * Defines the mosmcp/site-update-timezone ability.
	 *
	 * @return Ability
	 */
	private function update_timezone() {
		return new Ability(
			'mosmcp/site-update-timezone',
			array(
				'label'         => __( 'Update Site Timezone', 'mosmcp-abilities' ),
				'description'   => __( 'Sets the site timezone. Prefer a named zone such as "Europe/London", which follows daylight saving automatically. A fixed offset such as "+05:30" is accepted but never adjusts for daylight saving. Returns the previous and new value.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'manage_options',
				'annotations'   => Site_Support::annotations( false, false, true, false ),
				'execute'       => array( Site_Settings_Provider::class, 'update_timezone' ),
				'input_schema'  => Schema::object(
					array(
						'timezone' => Schema::str( __( 'A timezone identifier such as "Europe/London" or "America/New_York", or a fixed offset such as "+05:30" or "-8".', 'mosmcp-abilities' ) ),
					),
					array( 'timezone' )
				),
				'output_schema' => Schema::object(
					array(
						'previous'           => Schema::str(),
						'current'            => Schema::str(),
						'changed'            => Schema::boolean(),
						'uses_manual_offset' => Schema::boolean(),
						'current_local_time' => Schema::str(),
						'notes'              => Schema::arr( Schema::str() ),
					),
					array( 'previous', 'current', 'changed' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/site-update-posts-per-page ability.
	 *
	 * @return Ability
	 */
	private function update_posts_per_page() {
		return new Ability(
			'mosmcp/site-update-posts-per-page',
			array(
				'label'         => __( 'Update Posts Per Page', 'mosmcp-abilities' ),
				'description'   => __( 'Sets how many posts the site shows on a blog or archive page. Returns the previous and new value. Reversible by calling again with the old number.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'manage_options',
				'annotations'   => Site_Support::annotations( false, false, true, false ),
				'execute'       => array( Site_Settings_Provider::class, 'update_posts_per_page' ),
				'input_schema'  => Schema::object(
					array(
						'posts_per_page' => Schema::int(
							__( 'Posts to show per page, between 1 and 100.', 'mosmcp-abilities' ),
							array(
								'minimum' => 1,
								'maximum' => 100,
							)
						),
					),
					array( 'posts_per_page' )
				),
				'output_schema' => Schema::object(
					array(
						'previous' => Schema::int(),
						'current'  => Schema::int(),
						'changed'  => Schema::boolean(),
						'notes'    => Schema::arr( Schema::str() ),
					),
					array( 'previous', 'current', 'changed' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/site-set-homepage ability.
	 *
	 * @return Ability
	 */
	private function set_homepage() {
		return new Ability(
			'mosmcp/site-set-homepage',
			array(
				'label'         => __( 'Set Homepage', 'mosmcp-abilities' ),
				'description'   => __( 'Chooses what the front page shows: either the latest posts, or a fixed page. When setting a fixed page, pass the ID of an existing page. Use mosmcp/site-set-posts-page to choose where the blog then lives. Returns the previous and new setting.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'manage_options',
				'annotations'   => Site_Support::annotations( false, false, true, false ),
				'execute'       => array( Site_Settings_Provider::class, 'set_homepage' ),
				'input_schema'  => Schema::object(
					array(
						'shows'         => Schema::str(
							__( 'Either "latest_posts" to show the blog on the front page, or "static_page" to show a fixed page.', 'mosmcp-abilities' ),
							array( 'enum' => array( 'latest_posts', 'static_page' ) )
						),
						'front_page_id' => Schema::int(
							__( 'ID of the page to use as the front page. Required when shows is "static_page", ignored otherwise.', 'mosmcp-abilities' ),
							array( 'minimum' => 1 )
						),
					),
					array( 'shows' )
				),
				'output_schema' => self::homepage_change_output(),
			)
		);
	}

	/**
	 * Defines the mosmcp/site-set-posts-page ability.
	 *
	 * @return Ability
	 */
	private function set_posts_page() {
		return new Ability(
			'mosmcp/site-set-posts-page',
			array(
				'label'         => __( 'Set Posts Page', 'mosmcp-abilities' ),
				'description'   => __( 'Chooses which page lists the blog posts, for a site whose front page is a fixed page. Pass 0 to clear it. This setting only takes effect while the front page is set to a static page, and the answer says so if it is not. Returns the previous and new setting.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'manage_options',
				'annotations'   => Site_Support::annotations( false, false, true, false ),
				'execute'       => array( Site_Settings_Provider::class, 'set_posts_page' ),
				'input_schema'  => Schema::object(
					array(
						'page_id' => Schema::int(
							__( 'ID of the page that should list blog posts, or 0 to clear the setting.', 'mosmcp-abilities' ),
							array( 'minimum' => 0 )
						),
					),
					array( 'page_id' )
				),
				'output_schema' => self::homepage_change_output(),
			)
		);
	}

	/**
	 * Defines the mosmcp/site-set-privacy-policy-page ability.
	 *
	 * @return Ability
	 */
	private function set_privacy_policy_page() {
		return new Ability(
			'mosmcp/site-set-privacy-policy-page',
			array(
				'label'         => __( 'Set Privacy Policy Page', 'mosmcp-abilities' ),
				'description'   => __( 'Chooses which page is the site privacy policy. Pass 0 to clear it. Returns the previous and new setting, and warns when the chosen page is not published, because an unpublished policy is not reachable by visitors.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'manage_options',
				'annotations'   => Site_Support::annotations( false, false, true, false ),
				'execute'       => array( Site_Settings_Provider::class, 'set_privacy_policy_page' ),
				'input_schema'  => Schema::object(
					array(
						'page_id' => Schema::int(
							__( 'ID of the page to use as the privacy policy, or 0 to clear the setting.', 'mosmcp-abilities' ),
							array( 'minimum' => 0 )
						),
					),
					array( 'page_id' )
				),
				'output_schema' => Schema::object(
					array(
						'previous' => self::page_ref(),
						'current'  => self::page_ref(),
						'changed'  => Schema::boolean(),
						'notes'    => Schema::arr( Schema::str() ),
					),
					array( 'previous', 'current', 'changed' )
				),
			)
		);
	}

	/**
	 * The compact page reference shape used across the settings abilities.
	 *
	 * @return array<string, mixed>
	 */
	private static function page_ref() {
		return Schema::object(
			array(
				'id'     => Schema::int( __( 'Page ID, or 0 when unset.', 'mosmcp-abilities' ) ),
				'title'  => Schema::str(),
				'url'    => Schema::str(),
				'status' => Schema::str( __( 'Publication status, or "missing" when the stored page no longer exists.', 'mosmcp-abilities' ) ),
			)
		);
	}

	/**
	 * Output shape shared by the two plain-string write abilities.
	 *
	 * @return array<string, mixed>
	 */
	private static function string_change_output() {
		return Schema::object(
			array(
				'previous' => Schema::str( __( 'Value before the change.', 'mosmcp-abilities' ) ),
				'current'  => Schema::str( __( 'Value now stored, read back after writing.', 'mosmcp-abilities' ) ),
				'changed'  => Schema::boolean( __( 'False when the new value matched the old one and nothing was written.', 'mosmcp-abilities' ) ),
				'notes'    => Schema::arr( Schema::str() ),
			),
			array( 'previous', 'current', 'changed' )
		);
	}

	/**
	 * Output shape shared by the homepage and posts-page write abilities.
	 *
	 * @return array<string, mixed>
	 */
	private static function homepage_change_output() {
		return Schema::object(
			array(
				'previous' => Schema::object(
					array(
						'shows'      => Schema::str(),
						'front_page' => self::page_ref(),
						'posts_page' => self::page_ref(),
					)
				),
				'current'  => Schema::object(
					array(
						'shows'      => Schema::str(),
						'front_page' => self::page_ref(),
						'posts_page' => self::page_ref(),
					)
				),
				'changed'  => Schema::boolean(),
				'notes'    => Schema::arr( Schema::str() ),
			),
			array( 'previous', 'current', 'changed' )
		);
	}
}
