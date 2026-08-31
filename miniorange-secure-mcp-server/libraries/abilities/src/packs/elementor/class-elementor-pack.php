<?php
/**
 * Elementor ability pack: definitions for the mosmcp/elementor-* abilities.
 *
 * Registered through Pack_Registry rather than a bespoke registration helper, so
 * every ability goes through Ability_Registrar's validation and gets its
 * object-level capability metadata derived from cap_args automatically.
 *
 * The whole pack is gated on Elementor being active; where Elementor is absent it
 * registers nothing at all, including its category.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Elementor;

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
 * Class Elementor_Pack
 */
class Elementor_Pack extends Ability_Pack {

	/**
	 * Only register when Elementor is active.
	 *
	 * @return string|null
	 */
	public function dependency() {
		return 'Elementor\Plugin';
	}

	/**
	 * Ability category for Elementor abilities.
	 *
	 * @return array Category definition.
	 */
	public function category() {
		return array(
			'slug'        => 'mosmcp-elementor',
			'label'       => __( 'Elementor', 'mosmcp-abilities' ),
			'description' => __( 'Read and edit Elementor page layouts: inspect the elements on a page, and change their text, images and links without disturbing the design.', 'mosmcp-abilities' ),
		);
	}

	/**
	 * The Elementor abilities.
	 *
	 * @return Ability[]
	 */
	public function abilities() {
		return array(
			$this->page_read(),
			$this->element_set_content(),
			$this->element_set_style(),
			$this->element_duplicate(),
			$this->element_move(),
			$this->element_delete(),
			$this->render_preview(),
			$this->page_write(),
			$this->list_widget_types(),
			$this->widget_schema(),
		);
	}

	/**
	 * Defines the mosmcp/elementor-page-write ability.
	 *
	 * The general escape hatch, for building or restructuring a layout that the
	 * content and structural abilities cannot express. It replaces the layout rather
	 * than editing it, which is why it is annotated destructive and why the tree is
	 * validated in full before anything is written.
	 *
	 * @return Ability
	 */
	private function page_write() {
		return new Ability(
			'mosmcp/elementor-page-write',
			array(
				'label'         => __( 'Write Elementor Layout', 'mosmcp-abilities' ),
				'description'   => __( 'Replaces a post or page\'s entire Elementor layout with the element tree you supply, and can build one on a post that has none. Anything not included is removed, so prefer the targeted editing abilities for ordinary changes. Element IDs may be omitted and will be generated. Unknown widget types are refused rather than silently dropped.', 'mosmcp-abilities' ),
				'category'      => 'mosmcp-elementor',
				'capability'    => 'edit_post',
				'cap_args'      => self::id_args(),
				'annotations'   => self::annotations( false, true, false, true ),
				'execute'       => array( Elementor_Provider::class, 'page_write' ),
				'input_schema'  => Schema::object(
					array(
						'id'                => Schema::int( __( 'The ID of the post or page whose layout should be replaced.', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
						'elements'          => Schema::arr(
							Schema::map( __( 'One Elementor element: elType, optional widgetType, settings and nested elements.', 'mosmcp-abilities' ) ),
							__( 'The top-level elements of the layout. Each needs an elType of "container" or "widget"; a widget also needs a widgetType. Settings and nested elements are optional, and ids are generated when omitted.', 'mosmcp-abilities' )
						),
						'allow_empty'       => Schema::boolean( __( 'Required to be true if elements is empty, since that removes the whole layout.', 'mosmcp-abilities' ), array( 'default' => false ) ),
						'set_edit_mode'     => Schema::boolean( __( 'Put the post into Elementor builder mode so the layout renders. Defaults to true; without it a written layout is stored but never displayed.', 'mosmcp-abilities' ), array( 'default' => true ) ),
						'expected_modified' => Schema::str( __( 'Optional. The "modified" value from a previous read. The write is refused if the post changed since then.', 'mosmcp-abilities' ) ),
					),
					array( 'id', 'elements' )
				),
				'output_schema' => self::structure_output(),
			)
		);
	}

	/**
	 * Defines the mosmcp/elementor-render-preview ability.
	 *
	 * Reading the stored layout proves the data is well formed. Only rendering it
	 * shows whether the page actually reads correctly, which is what makes a
	 * write-look-correct loop possible instead of writing blind.
	 *
	 * @return Ability
	 */
	private function render_preview() {
		return new Ability(
			'mosmcp/elementor-render-preview',
			array(
				'label'         => __( 'Preview Rendered Elementor Layout', 'mosmcp-abilities' ),
				'description'   => __( 'Renders an Elementor layout to the markup a visitor would receive, so a change can be checked rather than assumed. Render the whole post, or pass an element_id to render just that element. Ask for the text format to read the copy without the markup around it.', 'mosmcp-abilities' ),
				'category'      => 'mosmcp-elementor',
				'capability'    => 'edit_post',
				'cap_args'      => self::id_args(),
				/*
				 * Read-only in the sense that matters: it changes no content. Marked
				 * open-world because rendering executes shortcodes and third-party
				 * widgets, some of which reach out to external services.
				 */
				'annotations'   => self::annotations( true, false, true, true ),
				'execute'       => array( Elementor_Provider::class, 'render_preview' ),
				'input_schema'  => Schema::object(
					array(
						'id'          => Schema::int( __( 'The ID of the post or page to render.', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
						'element_id'  => Schema::str( __( 'Optional. Render only this element instead of the whole layout, which keeps the response small when checking a single edit.', 'mosmcp-abilities' ) ),
						'format'      => Schema::str(
							__( 'Return the rendered HTML, or plain text with the markup stripped for reading the copy.', 'mosmcp-abilities' ),
							array(
								'enum'    => array( 'html', 'text' ),
								'default' => 'html',
							)
						),
						'include_css' => Schema::boolean( __( 'Include the layout\'s generated CSS alongside the markup. Only applies when rendering a whole post.', 'mosmcp-abilities' ), array( 'default' => false ) ),
						'max_bytes'   => Schema::int( __( 'Maximum output size. Larger output is cut off and flagged as truncated.', 'mosmcp-abilities' ), array( 'default' => 120000, 'minimum' => 1000, 'maximum' => 500000 ) ),
					),
					array( 'id' )
				),
				'output_schema' => Schema::object(
					array(
						'id'                   => Schema::int(),
						'title'                => Schema::str(),
						'element_id'           => Schema::str( __( 'The element rendered, or empty when the whole post was rendered.', 'mosmcp-abilities' ) ),
						'scope'                => Schema::str( __( 'Either "post" or "element".', 'mosmcp-abilities' ) ),
						'format'               => Schema::str(),
						'output'               => Schema::str( __( 'The rendered markup or text.', 'mosmcp-abilities' ) ),
						'bytes'                => Schema::int(),
						'full_bytes'           => Schema::int( __( 'Size before any truncation.', 'mosmcp-abilities' ) ),
						'truncated'            => Schema::boolean(),
						'includes_css'         => Schema::boolean(),
						'rendered_element_ids' => Schema::arr( array( 'type' => 'string' ), __( 'Element IDs found in the output, for matching the rendering back to the elements that produced it.', 'mosmcp-abilities' ) ),
						'view_url'             => Schema::str(),
						'warnings'             => Schema::warnings(),
					),
					array( 'id', 'scope', 'format', 'output', 'bytes' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/elementor-element-duplicate ability.
	 *
	 * @return Ability
	 */
	private function element_duplicate() {
		return new Ability(
			'mosmcp/elementor-element-duplicate',
			array(
				'label'         => __( 'Duplicate Elementor Element', 'mosmcp-abilities' ),
				'description'   => __( 'Copies one element of an Elementor layout, along with anything nested inside it, and places the copy immediately after the original. The copy is given new element IDs so it can be edited independently. This is the reliable way to extend a repeating section such as a list of cards.', 'mosmcp-abilities' ),
				'category'      => 'mosmcp-elementor',
				'capability'    => 'edit_post',
				'cap_args'      => self::id_args(),
				'annotations'   => self::annotations( false, false, false, true ),
				'execute'       => array( Elementor_Provider::class, 'element_duplicate' ),
				'input_schema'  => Schema::object(
					array(
						'id'                => Schema::int( __( 'The ID of the post or page whose layout should be edited.', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
						'element_id'        => Schema::str( __( 'The Elementor element ID to copy, as reported when reading the layout.', 'mosmcp-abilities' ), array( 'minLength' => 1 ) ),
						'expected_modified' => Schema::str( __( 'Optional. The "modified" value from a previous read. The edit is refused if the post changed since then.', 'mosmcp-abilities' ) ),
					),
					array( 'id', 'element_id' )
				),
				'output_schema' => self::structure_output(),
			)
		);
	}

	/**
	 * Defines the mosmcp/elementor-element-move ability.
	 *
	 * @return Ability
	 */
	private function element_move() {
		return new Ability(
			'mosmcp/elementor-element-move',
			array(
				'label'         => __( 'Move Elementor Element', 'mosmcp-abilities' ),
				'description'   => __( 'Changes where an element sits in an Elementor layout: pass a position to reorder it among its current siblings, or a target container ID to move it into a different container. An element cannot be moved inside itself.', 'mosmcp-abilities' ),
				'category'      => 'mosmcp-elementor',
				'capability'    => 'edit_post',
				'cap_args'      => self::id_args(),
				'annotations'   => self::annotations( false, false, false, true ),
				'execute'       => array( Elementor_Provider::class, 'element_move' ),
				'input_schema'  => Schema::object(
					array(
						'id'                  => Schema::int( __( 'The ID of the post or page whose layout should be edited.', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
						'element_id'          => Schema::str( __( 'The Elementor element ID to move.', 'mosmcp-abilities' ), array( 'minLength' => 1 ) ),
						'position'            => Schema::int( __( 'Zero-based position the element should end up at among the destination\'s children. Omit to place it last. A position beyond the end is treated as last.', 'mosmcp-abilities' ), array( 'minimum' => 0 ) ),
						'target_container_id' => Schema::str( __( 'Optional. Element ID of the container to move into. Omit to reorder within the current container.', 'mosmcp-abilities' ) ),
						'expected_modified'   => Schema::str( __( 'Optional. The "modified" value from a previous read. The edit is refused if the post changed since then.', 'mosmcp-abilities' ) ),
					),
					array( 'id', 'element_id' )
				),
				'output_schema' => self::structure_output(),
			)
		);
	}

	/**
	 * Defines the mosmcp/elementor-element-delete ability.
	 *
	 * @return Ability
	 */
	private function element_delete() {
		return new Ability(
			'mosmcp/elementor-element-delete',
			array(
				'label'         => __( 'Delete Elementor Element', 'mosmcp-abilities' ),
				'description'   => __( 'Removes one element from an Elementor layout. Anything nested inside it is removed with it, and the response reports how many nested elements that was. This cannot be undone from here; the post\'s revision history is the way back.', 'mosmcp-abilities' ),
				'category'      => 'mosmcp-elementor',
				'capability'    => 'edit_post',
				'cap_args'      => self::id_args(),
				'annotations'   => self::annotations( false, true, false, true ),
				'execute'       => array( Elementor_Provider::class, 'element_delete' ),
				'input_schema'  => Schema::object(
					array(
						'id'                => Schema::int( __( 'The ID of the post or page whose layout should be edited.', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
						'element_id'        => Schema::str( __( 'The Elementor element ID to delete.', 'mosmcp-abilities' ), array( 'minLength' => 1 ) ),
						'expected_modified' => Schema::str( __( 'Optional. The "modified" value from a previous read. The deletion is refused if the post changed since then.', 'mosmcp-abilities' ) ),
					),
					array( 'id', 'element_id' )
				),
				'output_schema' => self::structure_output(),
			)
		);
	}

	/**
	 * Output schema shared by the structural editing abilities.
	 *
	 * @return array<string, mixed>
	 */
	private static function structure_output() {
		return Schema::object(
			array(
				'id'            => Schema::int(),
				'title'         => Schema::str(),
				'status'        => Schema::str( __( 'The post status, which these abilities never change.', 'mosmcp-abilities' ) ),
				'modified'      => Schema::str( __( 'New modified timestamp, usable as expected_modified on a following edit.', 'mosmcp-abilities' ) ),
				'changes'       => Schema::arr(
					Schema::object(
						array(
							'action'      => Schema::str( __( 'Which structural change was made: delete, duplicate or move.', 'mosmcp-abilities' ) ),
							'element_id'  => Schema::str( __( 'The element affected. For a duplicate this is the new copy\'s ID.', 'mosmcp-abilities' ) ),
							'widget_type' => Schema::str(),
							'detail'      => Schema::str( __( 'Plain-language description of what happened.', 'mosmcp-abilities' ) ),
						)
					),
					__( 'What actually changed.', 'mosmcp-abilities' )
				),
				'view_url'      => Schema::str(),
				'elementor_url' => Schema::str(),
				'warnings'      => Schema::warnings(),
			),
			array( 'id', 'changes' )
		);
	}

	/**
	 * Defines the mosmcp/elementor-element-set-content ability.
	 *
	 * Deliberately narrower than a general settings patch: it can only reach the
	 * controls this pack classifies as content for the target widget, so it cannot
	 * alter colours, spacing or any other part of the design.
	 *
	 * @return Ability
	 */
	private function element_set_content() {
		return new Ability(
			'mosmcp/elementor-element-set-content',
			array(
				'label'         => __( 'Edit Elementor Element Content', 'mosmcp-abilities' ),
				'description'   => __( 'Changes the text, image or link of a single element inside an Elementor layout, leaving the design untouched. Only content settings can be reached, so this cannot alter colours, fonts or spacing. Read the layout first to get the element_id you want to change.', 'mosmcp-abilities' ),
				'category'      => 'mosmcp-elementor',
				'capability'    => 'edit_post',
				'cap_args'      => self::id_args(),
				'annotations'   => self::annotations( false, false, true, true ),
				'execute'       => array( Elementor_Provider::class, 'element_set_content' ),
				'input_schema'  => Schema::object(
					array(
						'id'                => Schema::int( __( 'The ID of the post or page whose layout should be edited.', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
						'element_id'        => Schema::str( __( 'The Elementor element ID to change, as reported when reading the layout.', 'mosmcp-abilities' ), array( 'minLength' => 1 ) ),
						'text'              => Schema::str( __( 'New primary text — a heading\'s title, a button\'s label, a text block\'s body.', 'mosmcp-abilities' ) ),
						'body'              => Schema::str( __( 'New secondary text, for widgets that have both a title and a description.', 'mosmcp-abilities' ) ),
						'image_id'          => Schema::int( __( 'Media library ID of an image to use for this element.', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
						'link_url'          => Schema::str( __( 'New link target: a full http(s) URL, a site-relative path starting with /, or an anchor starting with #.', 'mosmcp-abilities' ) ),
						'expected_modified' => Schema::str( __( 'Optional. The "modified" value from a previous read. The edit is refused if the post changed since then, so a concurrent edit is never overwritten.', 'mosmcp-abilities' ) ),
					),
					array( 'id', 'element_id' )
				),
				'output_schema' => Schema::object(
					array(
						'id'            => Schema::int(),
						'title'         => Schema::str(),
						'status'        => Schema::str( __( 'The post status, which this ability never changes.', 'mosmcp-abilities' ) ),
						'modified'      => Schema::str( __( 'New modified timestamp, usable as expected_modified on a following edit.', 'mosmcp-abilities' ) ),
						'changes'       => Schema::arr(
							Schema::object(
								array(
									'element_id'  => Schema::str(),
									'widget_type' => Schema::str(),
									'role'        => Schema::str( __( 'Which content role was written: text, body, image or link.', 'mosmcp-abilities' ) ),
									'control'     => Schema::str( __( 'The Elementor setting that was written.', 'mosmcp-abilities' ) ),
									'previous'    => Schema::str(),
									'current'     => Schema::str(),
								)
							),
							__( 'What actually changed. Empty is never returned; a no-op request is refused instead.', 'mosmcp-abilities' )
						),
						'view_url'      => Schema::str(),
						'elementor_url' => Schema::str(),
						'warnings'      => Schema::warnings(),
					),
					array( 'id', 'changes' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/elementor-element-set-style ability.
	 *
	 * The companion to set-content: that one reaches only content and cannot touch
	 * the design, this one reaches only a fixed list of appearance properties and
	 * cannot touch content or structure.
	 *
	 * @return Ability
	 */
	private function element_set_style() {
		return new Ability(
			'mosmcp/elementor-element-set-style',
			array(
				'label'         => __( 'Edit Elementor Element Styling', 'mosmcp-abilities' ),
				'description'   => __( 'Changes the appearance of a single element inside an Elementor layout: text colour, font size, weight, family, line height, letter spacing, text transform, alignment, padding, margin and background colour. Nothing else is reachable, so content and layout structure cannot be altered. Lengths take a unit ("42px", "1.4em"); padding and margin take CSS shorthand ("10px 20px"); colours must be hex or rgba. Pass an empty string to clear a property and fall back to the theme default. Read the layout first to get the element_id.', 'mosmcp-abilities' ),
				'category'      => 'mosmcp-elementor',
				'capability'    => 'edit_post',
				'cap_args'      => self::id_args(),
				'annotations'   => self::annotations( false, false, true, true ),
				'execute'       => array( Elementor_Provider::class, 'element_set_style' ),
				'input_schema'  => Schema::object(
					array(
						'id'                => Schema::int( __( 'The ID of the post or page whose layout should be restyled.', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
						'element_id'        => Schema::str( __( 'The Elementor element ID to restyle, as reported when reading the layout.', 'mosmcp-abilities' ), array( 'minLength' => 1 ) ),
						'device'            => Schema::str(
							__( 'Which screen size the values apply to. "tablet" and "mobile" leave the desktop appearance untouched. Defaults to desktop.', 'mosmcp-abilities' ),
							array(
								'enum'    => array( 'desktop', 'tablet', 'mobile' ),
								'default' => 'desktop',
							)
						),
						'text_color'        => Schema::str( __( 'Text colour as hex or rgba, for example "#1A2B3C".', 'mosmcp-abilities' ) ),
						'background_color'  => Schema::str( __( 'Background colour as hex or rgba.', 'mosmcp-abilities' ) ),
						'font_size'         => Schema::str( __( 'Font size with a unit, for example "42px" or "2.5rem".', 'mosmcp-abilities' ) ),
						'line_height'       => Schema::str( __( 'Line height with a unit, for example "1.4em".', 'mosmcp-abilities' ) ),
						'letter_spacing'    => Schema::str( __( 'Letter spacing with a unit, for example "-0.5px".', 'mosmcp-abilities' ) ),
						'font_weight'       => Schema::str(
							__( 'Font weight.', 'mosmcp-abilities' ),
							array( 'enum' => array( '100', '200', '300', '400', '500', '600', '700', '800', '900', 'normal', 'bold' ) )
						),
						'font_family'       => Schema::str( __( 'Font family name as it appears in Elementor, for example "Roboto".', 'mosmcp-abilities' ) ),
						'text_transform'    => Schema::str(
							__( 'Letter casing.', 'mosmcp-abilities' ),
							array( 'enum' => array( 'none', 'uppercase', 'lowercase', 'capitalize' ) )
						),
						'text_align'        => Schema::str(
							__( 'Text alignment.', 'mosmcp-abilities' ),
							array( 'enum' => array( 'left', 'center', 'right', 'justify' ) )
						),
						'padding'           => Schema::str( __( 'Padding as CSS shorthand with units, for example "20px" or "10px 20px 10px 20px". All values must share one unit.', 'mosmcp-abilities' ) ),
						'margin'            => Schema::str( __( 'Margin as CSS shorthand with units. All values must share one unit.', 'mosmcp-abilities' ) ),
						'expected_modified' => Schema::str( __( 'Optional. The "modified" value from a previous read. The edit is refused if the post changed since then, so a concurrent edit is never overwritten.', 'mosmcp-abilities' ) ),
					),
					array( 'id', 'element_id' )
				),
				'output_schema' => Schema::object(
					array(
						'id'            => Schema::int(),
						'title'         => Schema::str(),
						'status'        => Schema::str( __( 'The post status, which this ability never changes.', 'mosmcp-abilities' ) ),
						'modified'      => Schema::str( __( 'New modified timestamp, usable as expected_modified on a following edit.', 'mosmcp-abilities' ) ),
						'changes'       => Schema::arr(
							Schema::object(
								array(
									'element_id'  => Schema::str(),
									'widget_type' => Schema::str(),
									'property'    => Schema::str( __( 'Which styling property was written. An entry marked "(enabler)" is the switch Elementor requires before a group of properties takes effect.', 'mosmcp-abilities' ) ),
									'control'     => Schema::str( __( 'The Elementor setting that was written.', 'mosmcp-abilities' ) ),
									'previous'    => Schema::str(),
									'current'     => Schema::str(),
									'removed'     => Schema::boolean( __( 'True when the property was cleared rather than given a value, so the theme or kit default applies again.', 'mosmcp-abilities' ) ),
								)
							),
							__( 'What actually changed. A request that would change nothing is refused rather than reported as empty.', 'mosmcp-abilities' )
						),
						'view_url'      => Schema::str(),
						'elementor_url' => Schema::str(),
						'warnings'      => Schema::warnings(),
					),
					array( 'id', 'changes' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/elementor-page-read ability.
	 *
	 * @return Ability
	 */
	private function page_read() {
		return new Ability(
			'mosmcp/elementor-page-read',
			array(
				'label'         => __( 'Read Elementor Layout', 'mosmcp-abilities' ),
				'description'   => __( 'Lists the elements that make up a post or page built with Elementor, with each element\'s ID and its text, image and link values. Styling is omitted to keep the response small. Use the element IDs from this list to edit specific parts of the layout.', 'mosmcp-abilities' ),
				'category'      => 'mosmcp-elementor',
				'capability'    => 'edit_post',
				'cap_args'      => self::id_args(),
				'annotations'   => self::annotations( true, false, true, false ),
				'execute'       => array( Elementor_Provider::class, 'page_read' ),
				'input_schema'  => Schema::object(
					array(
						'id'          => Schema::int( __( 'The ID of the post or page whose Elementor layout should be read.', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
						'widget_type' => Schema::str( __( 'Optional. Return only elements of this widget or container type, for example "heading" or "container".', 'mosmcp-abilities' ) ),
						'search'      => Schema::str( __( 'Optional. Return only elements whose text, image or link values contain this string.', 'mosmcp-abilities' ) ),
						'max_depth'   => Schema::int( __( 'Optional. Return only elements at or above this nesting depth. Top-level containers are depth 0.', 'mosmcp-abilities' ), array( 'minimum' => 0 ) ),
						'limit'       => Schema::int( __( 'Maximum number of elements to return.', 'mosmcp-abilities' ), array( 'default' => 150, 'minimum' => 1, 'maximum' => 1000 ) ),
						'offset'      => Schema::int( __( 'Number of matching elements to skip, for paging through a large layout.', 'mosmcp-abilities' ), array( 'default' => 0, 'minimum' => 0 ) ),
						'include_raw' => Schema::boolean( __( 'Also return the layout exactly as stored, including all styling. Needed only when the whole layout is going to be rewritten, since the summarised list above omits styling and cannot be written back. Large.', 'mosmcp-abilities' ), array( 'default' => false ) ),
					),
					array( 'id' )
				),
				'output_schema' => Schema::object(
					array(
						'id'              => Schema::int(),
						'post_type'       => Schema::str(),
						'title'           => Schema::str(),
						'elementor'       => Schema::object(
							array(
								'plugin_active' => Schema::boolean(),
								'pro_active'    => Schema::boolean(),
								'version'       => Schema::str(),
								'has_data'      => Schema::boolean( __( 'Whether this post carries an Elementor layout at all.', 'mosmcp-abilities' ) ),
								'data_bytes'    => Schema::int(),
								'edit_mode'     => Schema::str( __( 'Empty means Elementor will not render the layout even though data exists.', 'mosmcp-abilities' ) ),
								'template_type' => Schema::str(),
								'saved_version' => Schema::str(),
								'page_template' => Schema::str(),
								'edit_url'      => Schema::str(),
							)
						),
						'nodes'           => Schema::arr(
							Schema::object(
								array(
									'element_id'  => Schema::str( __( 'Elementor\'s own ID for this element. Pass it to the editing abilities.', 'mosmcp-abilities' ) ),
									'el_type'     => Schema::str( __( 'Structural type: container, section, column or widget.', 'mosmcp-abilities' ) ),
									'widget_type' => Schema::str( __( 'Widget slug when this element is a widget, otherwise empty.', 'mosmcp-abilities' ) ),
									'title'       => Schema::str( __( 'Human-readable name of the widget type.', 'mosmcp-abilities' ) ),
									'path'        => Schema::str( __( 'Dot-separated index path of this element within the tree.', 'mosmcp-abilities' ) ),
									'depth'       => Schema::int(),
									'child_count' => Schema::int(),
									'is_atomic'   => Schema::boolean( __( 'True for Elementor v4 atomic elements.', 'mosmcp-abilities' ) ),
									'editable'    => Schema::boolean( __( 'Whether this pack can change this element\'s content.', 'mosmcp-abilities' ) ),
									'content'     => Schema::map( __( 'Content values on this element, keyed by role: text, body, image, link.', 'mosmcp-abilities' ) ),
								)
							),
							__( 'The matching elements, in document order.', 'mosmcp-abilities' )
						),
						'returned'        => Schema::int(),
						'matched'         => Schema::int( __( 'Elements matching the filters, which may exceed the number returned.', 'mosmcp-abilities' ) ),
						'total_elements'  => Schema::int( __( 'Total elements in the layout, before filtering.', 'mosmcp-abilities' ) ),
						'has_more'        => Schema::boolean(),
						'has_atomic'      => Schema::boolean(),
						'element_counts'  => Schema::map( __( 'How many of each widget or container type the layout contains.', 'mosmcp-abilities' ) ),
						'body_element_id' => Schema::str( __( 'The element that most likely holds the article body — the text widget with the most prose in it. A hint for "rewrite the article text": use it as the element_id, or pick another from the list if it looks wrong. Empty when the layout has no text widget.', 'mosmcp-abilities' ) ),
						'raw_elements'    => Schema::arr( Schema::map(), __( 'The layout exactly as stored, present only when include_raw was set. Suitable as input to the layout-write ability.', 'mosmcp-abilities' ) ),
						'warnings'        => Schema::warnings(),
					),
					array( 'id', 'nodes', 'returned', 'total_elements' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/elementor-list-widget-types ability.
	 *
	 * @return Ability
	 */
	private function list_widget_types() {
		return new Ability(
			'mosmcp/elementor-list-widget-types',
			array(
				'label'         => __( 'List Elementor Widget Types', 'mosmcp-abilities' ),
				'description'   => __( 'Lists the Elementor widgets and containers available on this site, including any added by Elementor Pro or third-party addons, and which of them this plugin can edit the content of.', 'mosmcp-abilities' ),
				'category'      => 'mosmcp-elementor',
				'capability'    => 'edit_posts',
				'annotations'   => self::annotations( true, false, true, false ),
				'execute'       => array( Elementor_Provider::class, 'list_widget_types' ),
				'input_schema'  => Schema::object(
					array(
						'search'      => Schema::str( __( 'Optional. Match widgets whose slug or name contains this string.', 'mosmcp-abilities' ) ),
						'category'    => Schema::str( __( 'Optional. Match widgets in this Elementor panel category, for example "basic" or "pro-elements".', 'mosmcp-abilities' ) ),
						'atomic_only' => Schema::boolean( __( 'Optional. Return only Elementor v4 atomic widgets.', 'mosmcp-abilities' ), array( 'default' => false ) ),
						'limit'       => Schema::int( __( 'Maximum number of widget types to return.', 'mosmcp-abilities' ), array( 'default' => 100, 'minimum' => 1, 'maximum' => 300 ) ),
						'offset'      => Schema::int( __( 'Number of widget types to skip.', 'mosmcp-abilities' ), array( 'default' => 0, 'minimum' => 0 ) ),
					)
				),
				'output_schema' => Schema::object(
					array(
						'elementor_version' => Schema::str(),
						'pro_active'        => Schema::boolean(),
						'total'             => Schema::int(),
						'returned'          => Schema::int(),
						'has_more'          => Schema::boolean(),
						'categories'        => Schema::arr( array( 'type' => 'string' ), __( 'Every panel category present in the result set.', 'mosmcp-abilities' ) ),
						'widgets'           => Schema::arr(
							Schema::object(
								array(
									'slug'             => Schema::str(),
									'title'            => Schema::str(),
									'kind'             => Schema::str( __( '"widget" or "element" (a container).', 'mosmcp-abilities' ) ),
									'categories'       => Schema::arr( array( 'type' => 'string' ) ),
									'is_pro'           => Schema::boolean(),
									'is_atomic'        => Schema::boolean(),
									'accepts_children' => Schema::boolean(),
									'content_controls' => Schema::arr( array( 'type' => 'string' ), __( 'Content roles this widget exposes, such as text, body, image or link.', 'mosmcp-abilities' ) ),
									'editable'         => Schema::boolean(),
								)
							)
						),
					),
					array( 'total', 'returned', 'widgets' )
				),
			)
		);
	}

	/**
	 * Defines the mosmcp/elementor-widget-schema ability.
	 *
	 * @return Ability
	 */
	private function widget_schema() {
		return new Ability(
			'mosmcp/elementor-widget-schema',
			array(
				'label'         => __( 'Get Elementor Widget Schema', 'mosmcp-abilities' ),
				'description'   => __( 'Reports the settings a given Elementor widget accepts. Widgets expose well over a hundred settings each, so by default only names and types are returned; pass a prefix to narrow to one family, or names to get full detail for specific settings.', 'mosmcp-abilities' ),
				'category'      => 'mosmcp-elementor',
				'capability'    => 'edit_posts',
				'annotations'   => self::annotations( true, false, true, false ),
				'execute'       => array( Elementor_Provider::class, 'widget_schema' ),
				'input_schema'  => Schema::object(
					array(
						'widget_type' => Schema::str( __( 'The widget or container slug to describe, for example "heading" or "container".', 'mosmcp-abilities' ), array( 'minLength' => 1 ) ),
						'names'       => Schema::str( __( 'Optional. Comma-separated setting names to return in full detail.', 'mosmcp-abilities' ) ),
						'prefix'      => Schema::str( __( 'Optional. Return only settings whose name starts with this prefix, for example "typography_".', 'mosmcp-abilities' ) ),
					),
					array( 'widget_type' )
				),
				'output_schema' => Schema::object(
					array(
						'slug'              => Schema::str(),
						'title'             => Schema::str(),
						'kind'              => Schema::str(),
						'is_pro'            => Schema::boolean(),
						'is_atomic'         => Schema::boolean(),
						'elementor_version' => Schema::str(),
						'mode'              => Schema::str( __( 'Which filter was applied: discovery, prefix or targeted.', 'mosmcp-abilities' ) ),
						'count'             => Schema::int(),
						'content_controls'  => Schema::map( __( 'Content roles this widget exposes, mapped to the setting that holds each.', 'mosmcp-abilities' ) ),
						'controls'          => Schema::arr(
							Schema::object(
								array(
									'name'       => Schema::str(),
									'type'       => Schema::str(),
									'is_content' => Schema::boolean( __( 'True when this setting holds content rather than styling.', 'mosmcp-abilities' ) ),
								)
							)
						),
						'group_controls'    => Schema::arr(
							Schema::object(
								array(
									'family'        => Schema::str(),
									'activator'     => Schema::str( __( 'Setting that must be set before any field in this family applies.', 'mosmcp-abilities' ) ),
									'activate_with' => Schema::str(),
									'fields'        => Schema::arr( array( 'type' => 'string' ) ),
								)
							),
							__( 'Group-control families, which Elementor does not expose through its own control list.', 'mosmcp-abilities' )
						),
						'note'              => Schema::str(),
					),
					array( 'slug', 'mode', 'count', 'controls' )
				),
			)
		);
	}

	/**
	 * Resolves the post ID from input for object-level capability checks (cap_args).
	 *
	 * @return callable
	 */
	private static function id_args() {
		return static function ( $input ) {
			return array( isset( $input['id'] ) ? absint( $input['id'] ) : 0 );
		};
	}

	/**
	 * Builds the four annotation hints.
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
