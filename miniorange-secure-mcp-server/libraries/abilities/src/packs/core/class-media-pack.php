<?php
/**
 * Core Media ability pack: definitions for the mosmcp/media-* abilities.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Core;

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
 * Class Media_Pack
 *
 * Declares the media abilities and their governed contract. Execute logic lives
 * in Media_Provider and is preserved from the reviewed source.
 */
class Media_Pack extends Ability_Pack {

	/**
	 * Ability category slug.
	 */
	const CATEGORY = 'mosmcp-media';

	/**
	 * Ability category for media abilities.
	 *
	 * @return array Category definition.
	 */
	public function category() {
		return array(
			'slug'        => self::CATEGORY,
			'label'       => __( 'Media', 'mosmcp-abilities' ),
			'description' => __( 'Browse and manage the media library: list, inspect, retitle, and delete files.', 'mosmcp-abilities' ),
		);
	}

	/**
	 * The media abilities.
	 *
	 * @return Ability[]
	 */
	public function abilities() {
		return array(
			$this->count_by_type(),
			$this->find(),
			$this->get(),
			$this->list_all(),
			$this->list_archives(),
			$this->list_audio(),
			$this->list_documents(),
			$this->list_images(),
			$this->list_mine(),
			$this->list_spreadsheets(),
			$this->list_unattached(),
			$this->list_video(),
			$this->update_alt_text(),
			$this->update_caption(),
			$this->update_description(),
			$this->update_title(),
			$this->upload_from_url(),
			$this->upload_file(),
			$this->delete_permanently(),
		);
	}

	/**
	 * Input fields both upload abilities share.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function upload_common_props() {
		return array(
			'title'             => Schema::str( __( 'Title for the media item. Defaults to the filename without its extension.', 'mosmcp-abilities' ) ),
			'alt_text'          => Schema::str( __( 'Alternative text, read aloud by screen readers and shown when an image cannot load. Always worth setting for images.', 'mosmcp-abilities' ) ),
			'caption'           => Schema::str( __( 'Caption, displayed beneath the image in most themes.', 'mosmcp-abilities' ) ),
			'description'       => Schema::str( __( 'Longer description, shown on the attachment page.', 'mosmcp-abilities' ) ),
			'attach_to_post_id' => Schema::int(
				__( 'Optional post, page or custom post type item to attach this file to. Attaching does not make it the featured image; use a set-featured-image ability with the returned attachment_id for that.', 'mosmcp-abilities' ),
				array( 'minimum' => 0 )
			),
		);
	}

	/**
	 * Defines the mosmcp/media-upload-from-url ability.
	 *
	 * @return Ability
	 */
	private function upload_from_url() {
		return new Ability(
			'mosmcp/media-upload-from-url',
			array(
				'label'         => __( 'Add Media From a URL', 'mosmcp-abilities' ),
				'description'   => __( 'Downloads a file from a public web address and adds it to the media library, optionally attaching it to a post. Returns the new attachment_id, which other abilities take to set a featured image or fill an image widget. Only public http and https addresses can be fetched, and the file type is checked against the contents rather than the extension.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'upload_files',

				/*
				 * Not idempotent: calling it twice with the same URL produces two
				 * separate media items, so the caller should not retry blindly.
				 */
				'annotations'   => self::annotations( false, false, false, true ),
				'execute'       => array( Media_Provider::class, 'upload_from_url' ),
				'input_schema'  => Schema::object(
					array_merge(
						array(
							'url'      => Schema::str( __( 'Public http or https address of the file to download.', 'mosmcp-abilities' ) ),
							'filename' => Schema::str( __( 'Filename to save it as, including the extension. Defaults to the name in the URL.', 'mosmcp-abilities' ) ),
						),
						self::upload_common_props()
					),
					array( 'url' )
				),
				'output_schema' => Schema::media_upload_output(),
			)
		);
	}

	/**
	 * Defines the mosmcp/media-upload-file ability.
	 *
	 * @return Ability
	 */
	private function upload_file() {
		return new Ability(
			'mosmcp/media-upload-file',
			array(
				'label'         => __( 'Add Media From File Contents', 'mosmcp-abilities' ),
				'description'   => __( 'Adds a file to the media library from base64-encoded contents, for when the file is not available at a URL. Returns the new attachment_id. The real file type is detected from the contents and must be one this site accepts, and the size must be within the site upload limit.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'upload_files',
				'annotations'   => self::annotations( false, false, false, false ),
				'execute'       => array( Media_Provider::class, 'upload_file' ),
				'input_schema'  => Schema::object(
					array_merge(
						array(
							'filename'       => Schema::str( __( 'Filename to save it as, including the extension, for example "team-photo.jpg".', 'mosmcp-abilities' ) ),
							'content_base64' => Schema::str( __( 'The file contents, base64-encoded. A "data:" prefix is accepted and ignored.', 'mosmcp-abilities' ) ),
						),
						self::upload_common_props()
					),
					array( 'filename', 'content_base64' )
				),
				'output_schema' => Schema::media_upload_output(),
			)
		);
	}

	/**
	 * Defines the mosmcp/media-count-by-type ability.
	 *
	 * @return Ability
	 */
	private function count_by_type() {
		return new Ability(
			'mosmcp/media-count-by-type',
			array(
				'label'         => __( 'Count Media by Type', 'mosmcp-abilities' ),
				'description'   => __( 'Returns how many items the media library contains in each category (all, images, audio, video, documents, spreadsheets, archives, unattached, mine) in a single call. Use this to answer questions like "what is in my media library?".', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'upload_files',
				'annotations'   => self::annotations( true, false, true, false ),
				'execute'       => array( Media_Provider::class, 'count_by_type' ),
				'input_schema'  => Schema::object( array() ),
				'output_schema' => Schema::object(
					array(
						'all'          => Schema::int(),
						'images'       => Schema::int(),
						'audio'        => Schema::int(),
						'video'        => Schema::int(),
						'documents'    => Schema::int(),
						'spreadsheets' => Schema::int(),
						'archives'     => Schema::int(),
						'unattached'   => Schema::int(),
						'mine'         => Schema::int(),
					),
					array( 'all', 'images', 'audio', 'video', 'documents', 'spreadsheets', 'archives', 'unattached', 'mine' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/media-find ability.
	 *
	 * @return Ability
	 */
	private function find() {
		return new Ability(
			'mosmcp/media-find',
			array(
				'label'         => __( 'Find Media (ID by Name / Name by ID)', 'mosmcp-abilities' ),
				'description'   => __( 'Looks up media items to resolve a media ID from a title or filename, or a title from a media ID. Use this FIRST whenever the user refers to a media file by its name and another ability requires a media ID. Provide "search" with the full or partial title/filename, or provide "id". Returns up to 10 matches plus the total.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'upload_files',
				'annotations'   => self::annotations( true, false, true, false ),
				'execute'       => array( Media_Provider::class, 'find' ),
				'input_schema'  => Schema::object(
					array(
						'search' => Schema::str( __( 'Full or partial media title or filename to search for. Provide either this or "id".', 'mosmcp-abilities' ), array( 'minLength' => 1 ) ),
						'id'     => Schema::int( __( 'A media ID to look up the name and details for. Provide either this or "search".', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
					)
				),
				'output_schema' => Schema::object(
					array(
						'showing' => Schema::int(),
						'total'   => Schema::int(),
						'matches' => Schema::arr(
							Schema::object(
								array(
									'id'        => Schema::int(),
									'title'     => Schema::str(),
									'filename'  => Schema::str(),
									'mime_type' => Schema::str(),
									'url'       => Schema::str(),
									'edit_url'  => Schema::str(),
								),
								array( 'id', 'title', 'mime_type' )
							)
						),
					),
					array( 'showing', 'total', 'matches' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/media-get ability.
	 *
	 * @return Ability
	 */
	private function get() {
		return new Ability(
			'mosmcp/media-get',
			array(
				'label'         => __( 'Get Media Details', 'mosmcp-abilities' ),
				'description'   => __( 'Gets the full details of a single media item by its ID: URL, filename, mime type, file size, uploader, upload date, what it is attached to, caption, description, and (for images) alt text and dimensions.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'upload_files',
				'annotations'   => self::annotations( true, false, true, false ),
				'execute'       => array( Media_Provider::class, 'get' ),
				'input_schema'  => Schema::object(
					array(
						'id' => Schema::int( __( 'The ID of the media item to retrieve.', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
					),
					array( 'id' )
				),
				'output_schema' => Schema::object(
					array(
						'id'                => Schema::int(),
						'title'             => Schema::str(),
						'filename'          => Schema::str(),
						'mime_type'         => Schema::str(),
						'file_size'         => Schema::str(),
						'url'               => Schema::str(),
						'uploaded_on'       => Schema::str(),
						'uploader'          => Schema::str(),
						'attached_to_id'    => Schema::int(),
						'attached_to_title' => Schema::str(),
						'edit_url'          => Schema::str(),
						'caption'           => Schema::str(),
						'description'       => Schema::str(),
						'alt_text'          => Schema::str(),
						'width'             => Schema::int(),
						'height'            => Schema::int(),
					),
					array( 'id', 'title', 'mime_type', 'url' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/media-list-all ability.
	 *
	 * @return Ability
	 */
	private function list_all() {
		return $this->media_list( 'mosmcp/media-list-all', __( 'List All Media', 'mosmcp-abilities' ), __( 'Lists all files in the media library (images, audio, video, documents, and every other type), newest first. Returns at most per_page items (default 20) plus the total.', 'mosmcp-abilities' ), 'list_all' );
	}

	/**
	 * Defines the mosmcp/media-list-archives ability.
	 *
	 * @return Ability
	 */
	private function list_archives() {
		return $this->media_list( 'mosmcp/media-list-archives', __( 'List Archives', 'mosmcp-abilities' ), __( 'Lists archive files in the media library (ZIP, RAR, 7z, TAR, GZIP, etc.), newest first. Returns at most per_page items (default 20) plus the total.', 'mosmcp-abilities' ), 'list_archives' );
	}

	/**
	 * Defines the mosmcp/media-list-audio ability.
	 *
	 * @return Ability
	 */
	private function list_audio() {
		return $this->media_list( 'mosmcp/media-list-audio', __( 'List Audio Files', 'mosmcp-abilities' ), __( 'Lists audio files in the media library (MP3, WAV, OGG, etc.), newest first. Returns at most per_page items (default 20) plus the total.', 'mosmcp-abilities' ), 'list_audio' );
	}

	/**
	 * Defines the mosmcp/media-list-documents ability.
	 *
	 * @return Ability
	 */
	private function list_documents() {
		return $this->media_list( 'mosmcp/media-list-documents', __( 'List Documents', 'mosmcp-abilities' ), __( 'Lists document files in the media library (PDF, Word, OpenDocument, RTF, plain text, etc.), newest first. Returns at most per_page items (default 20) plus the total.', 'mosmcp-abilities' ), 'list_documents' );
	}

	/**
	 * Defines the mosmcp/media-list-images ability.
	 *
	 * @return Ability
	 */
	private function list_images() {
		return $this->media_list( 'mosmcp/media-list-images', __( 'List Images', 'mosmcp-abilities' ), __( 'Lists image files in the media library (JPEG, PNG, GIF, WebP, SVG, etc.), newest first. Returns at most per_page items (default 20) plus the total.', 'mosmcp-abilities' ), 'list_images' );
	}

	/**
	 * Defines the mosmcp/media-list-mine ability.
	 *
	 * @return Ability
	 */
	private function list_mine() {
		return $this->media_list( 'mosmcp/media-list-mine', __( 'List My Media', 'mosmcp-abilities' ), __( 'Lists media files uploaded by the currently logged-in user only, newest first. Returns at most per_page items (default 20) plus the total.', 'mosmcp-abilities' ), 'list_mine' );
	}

	/**
	 * Defines the mosmcp/media-list-spreadsheets ability.
	 *
	 * @return Ability
	 */
	private function list_spreadsheets() {
		return $this->media_list( 'mosmcp/media-list-spreadsheets', __( 'List Spreadsheets', 'mosmcp-abilities' ), __( 'Lists spreadsheet files in the media library (Excel, OpenDocument Spreadsheet, CSV, Numbers, etc.), newest first. Returns at most per_page items (default 20) plus the total.', 'mosmcp-abilities' ), 'list_spreadsheets' );
	}

	/**
	 * Defines the mosmcp/media-list-unattached ability.
	 *
	 * @return Ability
	 */
	private function list_unattached() {
		return $this->media_list( 'mosmcp/media-list-unattached', __( 'List Unattached Media', 'mosmcp-abilities' ), __( 'Lists media files that are not attached to any post or page, newest first. Useful for finding unused files. Returns at most per_page items (default 20) plus the total.', 'mosmcp-abilities' ), 'list_unattached' );
	}

	/**
	 * Defines the mosmcp/media-list-video ability.
	 *
	 * @return Ability
	 */
	private function list_video() {
		return $this->media_list( 'mosmcp/media-list-video', __( 'List Video Files', 'mosmcp-abilities' ), __( 'Lists video files in the media library (MP4, WebM, MOV, etc.), newest first. Returns at most per_page items (default 20) plus the total.', 'mosmcp-abilities' ), 'list_video' );
	}

	/**
	 * Defines the mosmcp/media-update-alt-text ability.
	 *
	 * @return Ability
	 */
	private function update_alt_text() {
		return new Ability(
			'mosmcp/media-update-alt-text',
			array(
				'label'         => __( 'Update Image Alt Text', 'mosmcp-abilities' ),
				'description'   => __( 'Changes the alternative (alt) text of an image in the media library, used by screen readers and shown when the image cannot load. Only works on images.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'edit_post',
				'cap_args'      => self::id_args(),
				'annotations'   => self::annotations( false, false, true, true ),
				'execute'       => array( Media_Provider::class, 'update_alt_text' ),
				'input_schema'  => Schema::object(
					array(
						'id'       => Schema::int( __( 'The ID of the image to update.', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
						'alt_text' => Schema::str( __( 'The new alt text. May be an empty string to clear it.', 'mosmcp-abilities' ) ),
					),
					array( 'id', 'alt_text' )
				),
				'output_schema' => Schema::object(
					array(
						'id'       => Schema::int(),
						'title'    => Schema::str(),
						'alt_text' => Schema::str(),
						'edit_url' => Schema::str(),
					),
					array( 'id', 'title', 'alt_text' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/media-update-caption ability.
	 *
	 * @return Ability
	 */
	private function update_caption() {
		return new Ability(
			'mosmcp/media-update-caption',
			array(
				'label'         => __( 'Update Media Caption', 'mosmcp-abilities' ),
				'description'   => __( 'Changes the caption of a media item in the media library. The caption is the short text usually displayed below the media on the site.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'edit_post',
				'cap_args'      => self::id_args(),
				'annotations'   => self::annotations( false, false, false, true ),
				'execute'       => array( Media_Provider::class, 'update_caption' ),
				'input_schema'  => Schema::object(
					array(
						'id'      => Schema::int( __( 'The ID of the media item to update.', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
						'caption' => Schema::str( __( 'The new caption. May be an empty string to clear it.', 'mosmcp-abilities' ) ),
					),
					array( 'id', 'caption' )
				),
				'output_schema' => Schema::object(
					array(
						'id'       => Schema::int(),
						'title'    => Schema::str(),
						'caption'  => Schema::str(),
						'edit_url' => Schema::str(),
					),
					array( 'id', 'title', 'caption' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/media-update-description ability.
	 *
	 * @return Ability
	 */
	private function update_description() {
		return new Ability(
			'mosmcp/media-update-description',
			array(
				'label'         => __( 'Update Media Description', 'mosmcp-abilities' ),
				'description'   => __( 'Changes the description of a media item in the media library (the longer text shown on the attachment page, below the caption). Pass an empty string to clear it.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'edit_post',
				'cap_args'      => self::id_args(),
				'annotations'   => self::annotations( false, false, false, true ),
				'execute'       => array( Media_Provider::class, 'update_description' ),
				'input_schema'  => Schema::object(
					array(
						'id'          => Schema::int( __( 'The ID of the media item to update.', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
						'description' => Schema::str( __( 'The new description. May be an empty string to clear it.', 'mosmcp-abilities' ) ),
					),
					array( 'id', 'description' )
				),
				'output_schema' => Schema::object(
					array(
						'id'          => Schema::int(),
						'title'       => Schema::str(),
						'description' => Schema::str(),
						'edit_url'    => Schema::str(),
					),
					array( 'id', 'title', 'description' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/media-update-title ability.
	 *
	 * @return Ability
	 */
	private function update_title() {
		return new Ability(
			'mosmcp/media-update-title',
			array(
				'label'         => __( 'Update Media Title', 'mosmcp-abilities' ),
				'description'   => __( 'Changes the title of a media item in the media library.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'edit_post',
				'cap_args'      => self::id_args(),
				'annotations'   => self::annotations( false, false, false, true ),
				'execute'       => array( Media_Provider::class, 'update_title' ),
				'input_schema'  => Schema::object(
					array(
						'id'    => Schema::int( __( 'The ID of the media item to rename.', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
						'title' => Schema::str( __( 'The new title for the media item.', 'mosmcp-abilities' ), array( 'minLength' => 1 ) ),
					),
					array( 'id', 'title' )
				),
				'output_schema' => Schema::object(
					array(
						'id'       => Schema::int(),
						'title'    => Schema::str(),
						'edit_url' => Schema::str(),
					),
					array( 'id', 'title' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/media-delete-permanently ability.
	 *
	 * @return Ability
	 */
	private function delete_permanently() {
		return new Ability(
			'mosmcp/media-delete-permanently',
			array(
				'label'         => __( 'Delete Media Permanently', 'mosmcp-abilities' ),
				'description'   => __( 'Permanently deletes a media item, including the actual file on disk and all its generated thumbnail sizes. Media has no trash — this action is IRREVERSIBLE. Requires confirm=true.', 'mosmcp-abilities' ),
				'category'      => self::CATEGORY,
				'capability'    => 'delete_post',
				'cap_args'      => self::id_args(),
				'annotations'   => self::annotations( false, true, true, true ),
				'execute'       => array( Media_Provider::class, 'delete_permanently' ),
				'input_schema'  => Schema::object(
					array(
						'id'      => Schema::int( __( 'The ID of the media item to permanently delete.', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
						'confirm' => Schema::boolean( __( 'Must be true to confirm permanent, irreversible deletion of the file.', 'mosmcp-abilities' ) ),
					),
					array( 'id', 'confirm' )
				),
				'output_schema' => Schema::object(
					array(
						'id'       => Schema::int(),
						'title'    => Schema::str(),
						'filename' => Schema::str(),
						'deleted'  => Schema::boolean(),
					),
					array( 'id', 'title', 'deleted' )
				),
			)
		);
	}

	/**
	 * Builds a read-only media list ability sharing the standard list schemas.
	 *
	 * @param string $name   Ability name.
	 * @param string $label  Ability label.
	 * @param string $desc   Ability description.
	 * @param string $method Media_Provider method name.
	 * @return Ability
	 */
	private function media_list( $name, $label, $desc, $method ) {
		return new Ability(
			$name,
			array(
				'label'         => $label,
				'description'   => $desc,
				'category'      => self::CATEGORY,
				'capability'    => 'upload_files',
				'annotations'   => self::annotations( true, false, true, false ),
				'execute'       => array( Media_Provider::class, $method ),
				'input_schema'  => Schema::object( Schema::pagination_props( __( 'media items', 'mosmcp-abilities' ) ) ),
				'output_schema' => self::list_output(),
			)
		);
	}

	/**
	 * Standard media list output schema.
	 *
	 * @return array<string, mixed>
	 */
	private static function list_output() {
		return Schema::object(
			array(
				'showing' => Schema::int(),
				'total'   => Schema::int(),
				'items'   => Schema::arr(
					Schema::object(
						array(
							'id'                => Schema::int(),
							'title'             => Schema::str(),
							'filename'          => Schema::str(),
							'mime_type'         => Schema::str(),
							'file_size'         => Schema::str(),
							'url'               => Schema::str(),
							'uploaded_on'       => Schema::str(),
							'uploader'          => Schema::str(),
							'attached_to_id'    => Schema::int(),
							'attached_to_title' => Schema::str(),
							'edit_url'          => Schema::str(),
						),
						array( 'id', 'title', 'mime_type', 'url' )
					)
				),
			),
			array( 'showing', 'total', 'items' )
		);
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

	/**
	 * Resolves the media ID from input for object-level capability checks (cap_args).
	 *
	 * @return callable
	 */
	private static function id_args() {
		return static function ( $input ) {
			return array( isset( $input['id'] ) ? absint( $input['id'] ) : 0 );
		};
	}
}
