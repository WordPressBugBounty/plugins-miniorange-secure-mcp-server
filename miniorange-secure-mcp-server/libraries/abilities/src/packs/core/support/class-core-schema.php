<?php
/**
 * JSON-schema construction helpers shared by the core ability pack.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Core\Support;

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
 * Class Core_Schema
 *
 * Small builders that keep ability schemas compact and, crucially, guarantee
 * every object schema declares additionalProperties => false (top-level and
 * nested). Field descriptions and constraints are passed through unchanged.
 */
class Core_Schema {

	/**
	 * Builds a closed object schema.
	 *
	 * @param array<string, mixed> $properties Property name => property schema.
	 * @param string[]             $required   Names of required properties.
	 * @return array<string, mixed>
	 */
	public static function object( array $properties, array $required = array() ) {
		$schema = array(
			'type'                 => 'object',
			'additionalProperties' => false,
		);

		/*
		 * An empty property set omits the key entirely rather than declaring an
		 * empty one. `'properties' => array()` would serialize as `[]`, which is
		 * not valid JSON Schema, and casting it to `(object) array()` to fix the
		 * serialization makes WordPress fatal: rest_validate_value_from_schema()
		 * indexes $args['properties'][ $key ] as an array, so a stdClass there
		 * throws "Cannot use object of type stdClass as array" the moment the
		 * value being validated has any keys at all. Omitting it is both valid
		 * and unambiguous — with additionalProperties false it means "no
		 * properties are permitted".
		 *
		 * For an object whose keys are genuinely open-ended, use map() instead.
		 */
		if ( ! empty( $properties ) ) {
			$schema['properties'] = $properties;
		}

		if ( ! empty( $required ) ) {
			$schema['required'] = array_values( $required );
		}

		return $schema;
	}

	/**
	 * Builds a string property.
	 *
	 * @param string               $description Human-readable description.
	 * @param array<string, mixed> $extra       Extra keywords (enum, default, minLength, ...).
	 * @return array<string, mixed>
	 */
	public static function str( $description = '', array $extra = array() ) {
		return self::field( 'string', $description, $extra );
	}

	/**
	 * Builds an integer property.
	 *
	 * @param string               $description Human-readable description.
	 * @param array<string, mixed> $extra       Extra keywords (minimum, maximum, default, ...).
	 * @return array<string, mixed>
	 */
	public static function int( $description = '', array $extra = array() ) {
		return self::field( 'integer', $description, $extra );
	}

	/**
	 * Builds a boolean property.
	 *
	 * @param string               $description Human-readable description.
	 * @param array<string, mixed> $extra       Extra keywords (default, ...).
	 * @return array<string, mixed>
	 */
	public static function boolean( $description = '', array $extra = array() ) {
		return self::field( 'boolean', $description, $extra );
	}

	/**
	 * Builds an array property.
	 *
	 * @param array<string, mixed> $items       Schema for each item.
	 * @param string               $description Human-readable description.
	 * @return array<string, mixed>
	 */
	public static function arr( array $items, $description = '' ) {
		$schema = array(
			'type'  => 'array',
			'items' => $items,
		);

		if ( '' !== $description ) {
			$schema['description'] = $description;
		}

		return $schema;
	}

	/**
	 * Standard pagination input properties (per_page + offset).
	 *
	 * @param string $noun Plural noun for the descriptions, for example "posts".
	 * @return array<string, mixed>
	 */
	public static function pagination_props( $noun ) {
		return array(
			'per_page' => self::int(
				sprintf(
					/* translators: %s: plural noun, for example "posts". */
					__( 'Maximum number of %s to return.', 'mosmcp-abilities' ),
					$noun
				),
				array(
					'default' => 20,
					'minimum' => 1,
					'maximum' => 100,
				)
			),
			'offset'   => self::int(
				sprintf(
					/* translators: %s: plural noun, for example "posts". */
					__( 'Number of %s to skip, for fetching the next page.', 'mosmcp-abilities' ),
					$noun
				),
				array(
					'default' => 0,
					'minimum' => 0,
				)
			),
		);
	}

	/**
	 * A value whose type depends on the data rather than the schema.
	 *
	 * Custom fields hold strings, numbers, booleans and lists, and which one is
	 * correct is a property of the field being written, not of the ability. Declaring
	 * such an input as a string would reject the numeric value a price field needs,
	 * so the permitted types are listed and the ability validates against the field's
	 * own declared type once it knows which field is being written.
	 *
	 * @param string $description Human-readable description.
	 * @return array<string, mixed>
	 */
	public static function any_value( $description = '' ) {
		$schema = array( 'type' => array( 'string', 'number', 'integer', 'boolean', 'array' ) );

		if ( '' !== $description ) {
			$schema['description'] = $description;
		}

		return $schema;
	}

	/**
	 * An object whose keys are not known in advance — a map rather than a record.
	 *
	 * Use this for payloads like "a count per widget type" or "the content values
	 * this widget happens to expose", where the keys are data. Declaring such a
	 * payload with object() would be wrong twice over: it would claim through
	 * additionalProperties false that no keys are allowed, and it would leave
	 * WordPress validating real keys against a property list that does not
	 * describe them.
	 *
	 * @param string $description Human-readable description.
	 * @return array<string, mixed>
	 */
	public static function map( $description = '' ) {
		$schema = array(
			'type'                 => 'object',
			'additionalProperties' => true,
		);

		if ( '' !== $description ) {
			$schema['description'] = $description;
		}

		return $schema;
	}

	/**
	 * Standard warnings array: non-fatal issues an ability wants to surface.
	 *
	 * @param string $description Description for the array.
	 * @return array<string, mixed>
	 */
	public static function warnings( $description = '' ) {
		return self::arr(
			self::object(
				array(
					'code'    => self::str(),
					'message' => self::str(),
					'context' => self::str(),
				)
			),
			'' !== $description ? $description : __( 'Non-fatal issues encountered while running.', 'mosmcp-abilities' )
		);
	}

	/**
	 * Output schema shared by the media upload abilities.
	 *
	 * The dimensions are returned as 0 for anything that is not an image, and the
	 * caller usually wants attachment_id above all: it is what the featured-image
	 * and Elementor image abilities take.
	 *
	 * @return array<string, mixed>
	 */
	public static function media_upload_output() {
		return self::object(
			array(
				'attachment_id' => self::int( __( 'ID of the newly created media item. Pass this to any ability that takes an image ID.', 'mosmcp-abilities' ) ),
				'url'           => self::str( __( 'Public URL of the uploaded file.', 'mosmcp-abilities' ) ),
				'filename'      => self::str( __( 'Filename as finally stored, which may differ from the one requested.', 'mosmcp-abilities' ) ),
				'mime_type'     => self::str( __( 'MIME type detected from the file contents.', 'mosmcp-abilities' ) ),
				'filesize'      => self::int( __( 'Size in bytes.', 'mosmcp-abilities' ) ),
				'width'         => self::int( __( 'Pixel width for images, 0 otherwise.', 'mosmcp-abilities' ) ),
				'height'        => self::int( __( 'Pixel height for images, 0 otherwise.', 'mosmcp-abilities' ) ),
				'title'         => self::str(),
				'alt_text'      => self::str(),
				'attached_to'   => self::int( __( 'ID of the post this file was attached to, or 0 when unattached.', 'mosmcp-abilities' ) ),
				'edit_url'      => self::str(),
				'warnings'      => self::warnings(),
			),
			array( 'attachment_id', 'url', 'mime_type' )
		);
	}

	/**
	 * Output schema shared by the post/page set-featured-image abilities.
	 *
	 * @return array<string, mixed>
	 */
	public static function featured_image_output() {
		return self::object(
			array(
				'id'          => self::int(),
				'previous_id' => self::int( __( 'Featured image attachment ID before the change, or 0.', 'mosmcp-abilities' ) ),
				'current_id'  => self::int( __( 'Featured image attachment ID after the change, or 0.', 'mosmcp-abilities' ) ),
				'changed'     => self::boolean( __( 'Whether the featured image actually changed.', 'mosmcp-abilities' ) ),
				'image_url'   => self::str( __( 'URL of the new featured image, or empty when cleared.', 'mosmcp-abilities' ) ),
				'alt_text'    => self::str(),
				'edit_url'    => self::str(),
				'warnings'    => self::warnings(),
			),
			array( 'id', 'current_id', 'changed' )
		);
	}

	/**
	 * Output schema shared by the post/page template-get abilities.
	 *
	 * @return array<string, mixed>
	 */
	public static function template_get_output() {
		return self::object(
			array(
				'id'               => self::int(),
				'post_type'        => self::str(),
				'title'            => self::str(),
				'page_template'    => self::object(
					array(
						'current'      => self::str( __( 'Assigned template slug, or "default".', 'mosmcp-abilities' ) ),
						'label'        => self::str(),
						'is_available' => self::boolean( __( 'False when the stored template is not registered by the active theme, which means WordPress renders the default instead.', 'mosmcp-abilities' ) ),
						'available'    => self::arr(
							self::object(
								array(
									'slug'  => self::str(),
									'label' => self::str(),
								)
							),
							__( 'Templates the active theme and its plugins register for this post type.', 'mosmcp-abilities' )
						),
					)
				),
				'elementor'        => self::object(
					array(
						'plugin_active' => self::boolean(),
						'has_data'      => self::boolean( __( 'Whether this post carries an Elementor layout.', 'mosmcp-abilities' ) ),
						'data_bytes'    => self::int(),
						'edit_mode'     => self::str(),
						'template_type' => self::str(),
						'version'       => self::str(),
					)
				),
				'display_settings' => self::arr(
					self::object(
						array(
							'key'   => self::str(),
							'value' => self::str(),
						)
					),
					__( 'Per-post theme and plugin settings whose names suggest they affect layout.', 'mosmcp-abilities' )
				),
			),
			array( 'id', 'post_type', 'page_template' )
		);
	}

	/**
	 * Output schema shared by the post/page template-set abilities.
	 *
	 * @return array<string, mixed>
	 */
	public static function template_set_output() {
		return self::object(
			array(
				'id'       => self::int(),
				'previous' => self::str( __( 'Template slug before the change.', 'mosmcp-abilities' ) ),
				'current'  => self::str( __( 'Template slug after the change.', 'mosmcp-abilities' ) ),
				'label'    => self::str(),
				'changed'  => self::boolean(),
				'view_url' => self::str(),
				'warnings' => self::warnings(),
			),
			array( 'id', 'previous', 'current', 'changed' )
		);
	}

	/**
	 * Output schema for the metadata comparison ability.
	 *
	 * @return array<string, mixed>
	 */
	public static function meta_compare_output() {
		$post_ref = self::object(
			array(
				'id'        => self::int(),
				'post_type' => self::str(),
				'status'    => self::str(),
				'title'     => self::str(),
			)
		);

		$entry = self::object(
			array(
				'key'        => self::str(),
				'present_on' => self::str( __( 'Which post carries the key: "a", "b" or "both".', 'mosmcp-abilities' ) ),
				'bytes_a'    => self::int(),
				'bytes_b'    => self::int(),
				'value_a'    => self::str(),
				'value_b'    => self::str(),
			)
		);

		return self::object(
			array(
				'post_a'                   => $post_ref,
				'post_b'                   => $post_ref,
				'identical_keys'           => self::int( __( 'Number of settings that match on both posts.', 'mosmcp-abilities' ) ),
				'only_in_a'                => self::arr( $entry, __( 'Settings present on the first post only.', 'mosmcp-abilities' ) ),
				'only_in_b'                => self::arr( $entry, __( 'Settings present on the second post only.', 'mosmcp-abilities' ) ),
				'different'                => self::arr( $entry, __( 'Settings present on both posts with different values.', 'mosmcp-abilities' ) ),
				'likely_layout_difference' => self::arr(
					self::object(
						array(
							'key'    => self::str(),
							'reason' => self::str( __( 'Why this setting can change how the post looks.', 'mosmcp-abilities' ) ),
						)
					),
					__( 'The subset of differences that plausibly explain an appearance mismatch.', 'mosmcp-abilities' )
				),
				'summary'                  => self::str( __( 'One-line conclusion suitable for showing to a person.', 'mosmcp-abilities' ) ),
			),
			array( 'post_a', 'post_b', 'summary' )
		);
	}

	/**
	 * Builds a typed property with an optional description and extra keywords.
	 *
	 * @param string               $type        JSON type.
	 * @param string               $description Human-readable description.
	 * @param array<string, mixed> $extra       Extra keywords.
	 * @return array<string, mixed>
	 */
	private static function field( $type, $description, array $extra ) {
		$field = array( 'type' => $type );

		if ( '' !== $description ) {
			$field['description'] = $description;
		}

		return array_merge( $field, $extra );
	}
}
