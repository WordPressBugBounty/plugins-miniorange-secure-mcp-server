<?php
/**
 * Shared write engine for every Elementor-mutating ability.
 *
 * Every mutation funnels through run() rather than implementing its own
 * read/modify/save flow, so each new write ability inherits the whole safety
 * contract instead of re-earning it:
 *
 *   - optimistic locking, so a concurrent human edit is never silently clobbered
 *   - an ambiguous-ID guard, because Elementor element IDs are unique in practice
 *     but nothing enforces it, and mutating "the first match" is a data-loss bug
 *   - a snapshot taken before the write and restored if the save throws
 *   - fatal containment around Document::save(), which compiles CSS inline and
 *     therefore raises real PHP errors from malformed element data
 *   - read-after-write verification, so success is observed rather than assumed
 *   - CSS regeneration, with failures surfaced as warnings rather than swallowed
 *
 * Saving goes through Elementor's own Document API rather than writing the meta
 * row directly. The document save stamps the version, invalidates the element
 * cache and rebuilds CSS; a direct meta write would leave all three stale. The
 * cost is that Elementor's save pipeline can throw, which is exactly why the
 * snapshot exists.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Elementor;

use WP_Error;
use WP_Post;

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
 * Class Elementor_Write_Engine
 */
class Elementor_Write_Engine {

	/**
	 * Runs a mutation against a post's Elementor tree, end to end.
	 *
	 * The mutator receives the decoded tree by reference and a result array by
	 * reference. It returns true on success or a WP_Error to abort before anything
	 * is written.
	 *
	 * @param array<string, mixed> $input   Ability input. Must carry 'id'.
	 * @param callable             $mutator function ( array &$tree, array &$result ): true|WP_Error.
	 * @param array<string, mixed> $options 'allow_missing_tree' to start from an empty
	 *                                      tree on a post that has no layout yet, which
	 *                                      is what creating a layout requires.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function run( array $input, callable $mutator, array $options = array() ) {
		if ( ! Elementor_Schema::available() ) {
			return Elementor_Schema::unavailable_error();
		}

		$post = Elementor_Document::require_post( isset( $input['id'] ) ? $input['id'] : 0 );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		/*
		 * Elementor only builds post types it has been enabled for, and its
		 * Document::save() is a silent no-op on any other type — it neither writes
		 * nor raises anything, so the caller is told the edit worked while the page
		 * is unchanged. Refuse here instead, and name the setting that fixes it.
		 */
		if ( ! post_type_supports( $post->post_type, 'elementor' ) ) {
			$type_object = get_post_type_object( $post->post_type );
			$label       = ( $type_object && isset( $type_object->labels->singular_name ) )
				? strtolower( (string) $type_object->labels->singular_name )
				: (string) $post->post_type;

			return new WP_Error(
				'post_type_not_builder_enabled',
				sprintf(
					/* translators: 1: post type label, 2: post type slug */
					__( 'Elementor is not enabled to build the %1$s post type on this site, so it silently discards layout changes to it. Enable "%2$s" under Elementor > Settings > General > Post Types, then try again.', 'mosmcp-abilities' ),
					$label,
					(string) $post->post_type
				)
			);
		}

		$lock = self::check_lock( $input, $post );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		$tree = Elementor_Document::tree( (int) $post->ID );
		if ( is_wp_error( $tree ) ) {
			/*
			 * Creating a layout necessarily starts from a post that has none, so
			 * that case is only an error for abilities which edit something that
			 * must already be there. A corrupt or oversized tree is still fatal:
			 * overwriting data we could not read is not a decision to make quietly.
			 */
			if ( empty( $options['allow_missing_tree'] ) || 'no_elementor_data' !== $tree->get_error_code() ) {
				return $tree;
			}
			$tree = array();
		}

		/*
		 * Snapshot the exact stored string, not the decoded tree. Restoring the
		 * original bytes cannot itself fail on a re-encode, which matters because
		 * the restore path only runs when something has already gone wrong.
		 */
		$snapshot = Elementor_Document::raw( (int) $post->ID );

		$result = array(
			'warnings'      => array(),
			'changes'       => array(),
			/*
			 * Element IDs that must exist after the save. A mutation which replaces
			 * a whole subtree has one readable change entry rather than one per
			 * element, so it lists the IDs to confirm here instead — verification
			 * stays thorough without the change report becoming a wall of noise.
			 */
			'verify_ids'    => array(),
			/*
			 * Exact values that must be readable back after the save, as
			 * { element_id, control, value }. Confirming the setting merely exists
			 * is not enough: a save that silently fails to persist leaves the old
			 * value in place under the same key, and would otherwise be reported as
			 * a success.
			 */
			'verify_values' => array(),
		);

		$mutated = call_user_func_array( $mutator, array( &$tree, &$result ) );
		if ( is_wp_error( $mutated ) ) {
			return $mutated;
		}

		if ( empty( $result['changes'] ) ) {
			return new WP_Error(
				'nothing_changed',
				__( 'Nothing was changed: the requested values already match what the element holds.', 'mosmcp-abilities' )
			);
		}

		$saved = self::save( $post, $tree, $snapshot );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		$result['warnings'] = array_merge( $result['warnings'], $saved['warnings'] );

		$verified = self::verify( (int) $post->ID, $result['changes'], $result['verify_ids'], $result['verify_values'] );
		if ( is_wp_error( $verified ) ) {
			return $verified;
		}

		$fresh = get_post( (int) $post->ID );

		return array(
			'id'            => (int) $post->ID,
			'title'         => (string) get_the_title( $fresh ),
			'status'        => (string) $fresh->post_status,
			'modified'      => (string) $fresh->post_modified_gmt,
			'changes'       => array_values( $result['changes'] ),
			'view_url'      => (string) get_permalink( $post->ID ),
			'elementor_url' => admin_url( 'post.php?post=' . $post->ID . '&action=elementor' ),
			'warnings'      => $result['warnings'],
		);
	}

	/**
	 * Rejects the write when the post changed since the caller last read it.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @param WP_Post              $post  Target post.
	 * @return true|WP_Error
	 */
	private static function check_lock( array $input, WP_Post $post ) {
		if ( ! isset( $input['expected_modified'] ) || '' === trim( (string) $input['expected_modified'] ) ) {
			return true;
		}

		$expected = strtotime( (string) $input['expected_modified'] );
		if ( false === $expected ) {
			return new WP_Error(
				'invalid_expected_modified',
				__( 'expected_modified must be a date string, for example the "modified" value from a previous read of this post.', 'mosmcp-abilities' )
			);
		}

		$actual = strtotime( (string) $post->post_modified_gmt . ' UTC' );
		if ( false !== $actual && abs( $actual - $expected ) > 1 ) {
			return new WP_Error(
				'post_changed',
				sprintf(
					/* translators: 1: expected time, 2: actual stored time */
					__( 'This post changed since you last read it (you expected %1$s, it is now %2$s). Read the layout again so you are editing the current version.', 'mosmcp-abilities' ),
					gmdate( 'Y-m-d H:i:s', $expected ),
					(string) $post->post_modified_gmt
				)
			);
		}

		return true;
	}

	/**
	 * Locates every path whose node carries the given element ID.
	 *
	 * Returns all matches rather than the first, so callers can refuse an ambiguous
	 * target instead of guessing which duplicate was meant.
	 *
	 * @param array<int, mixed> $tree       Decoded tree.
	 * @param string            $element_id Target element ID.
	 * @return array<int, int[]>
	 */
	public static function locate( array $tree, $element_id ) {
		$element_id = (string) $element_id;
		$found      = array();

		Elementor_Document::walk(
			$tree,
			static function ( $node, $path ) use ( $element_id, &$found ) {
				if ( isset( $node['id'] ) && (string) $node['id'] === $element_id ) {
					$found[] = $path;
				}
				return true;
			}
		);

		return $found;
	}

	/**
	 * Resolves an element ID to exactly one path, or explains why it cannot.
	 *
	 * @param array<int, mixed> $tree       Decoded tree.
	 * @param string            $element_id Target element ID.
	 * @return int[]|WP_Error
	 */
	public static function resolve_one( array $tree, $element_id ) {
		$element_id = trim( (string) $element_id );

		if ( '' === $element_id ) {
			return new WP_Error(
				'missing_element_id',
				__( 'Provide the element_id of the element to change. Read the layout first to get the IDs.', 'mosmcp-abilities' )
			);
		}

		$found = self::locate( $tree, $element_id );

		if ( ! $found ) {
			return new WP_Error(
				'element_not_found',
				sprintf(
					/* translators: %s: element ID */
					__( 'No element with the ID "%s" exists in this layout. Read the layout again — element IDs change when a layout is rebuilt.', 'mosmcp-abilities' ),
					$element_id
				)
			);
		}

		if ( count( $found ) > 1 ) {
			return new WP_Error(
				'element_id_ambiguous',
				sprintf(
					/* translators: 1: element ID, 2: number of matches */
					__( 'The ID "%1$s" matches %2$d elements in this layout, so it is not clear which one to change. This usually follows a copy-paste inside Elementor; open the layout in Elementor and re-save it to give each element a unique ID.', 'mosmcp-abilities' ),
					$element_id,
					count( $found )
				)
			);
		}

		return $found[0];
	}

	/**
	 * Applies a callback to the node at a given index path, by reference.
	 *
	 * @param array<int, mixed> $nodes  Tree, modified in place.
	 * @param int[]             $path   Index path.
	 * @param callable          $mutate function ( array &$node ): true|WP_Error.
	 * @return true|WP_Error
	 */
	public static function apply_at( array &$nodes, array $path, callable $mutate ) {
		if ( ! $path ) {
			return new WP_Error( 'invalid_path', __( 'Internal error: an empty element path was given.', 'mosmcp-abilities' ) );
		}

		$index = (int) array_shift( $path );

		if ( ! isset( $nodes[ $index ] ) || ! is_array( $nodes[ $index ] ) ) {
			return new WP_Error( 'invalid_path', __( 'Internal error: the element path no longer resolves.', 'mosmcp-abilities' ) );
		}

		if ( ! $path ) {
			return call_user_func_array( $mutate, array( &$nodes[ $index ] ) );
		}

		if ( ! isset( $nodes[ $index ]['elements'] ) || ! is_array( $nodes[ $index ]['elements'] ) ) {
			return new WP_Error( 'invalid_path', __( 'Internal error: the element path descends into a node with no children.', 'mosmcp-abilities' ) );
		}

		return self::apply_at( $nodes[ $index ]['elements'], $path, $mutate );
	}

	/**
	 * Reads the node at a path without modifying it.
	 *
	 * @param array<int, mixed> $nodes Tree.
	 * @param int[]             $path  Index path.
	 * @return array<string, mixed>|null
	 */
	public static function node_at( array $nodes, array $path ) {
		$current = $nodes;

		foreach ( $path as $depth => $index ) {
			if ( ! isset( $current[ (int) $index ] ) || ! is_array( $current[ (int) $index ] ) ) {
				return null;
			}
			$node = $current[ (int) $index ];
			if ( $depth === count( $path ) - 1 ) {
				return $node;
			}
			$current = isset( $node['elements'] ) && is_array( $node['elements'] ) ? $node['elements'] : array();
		}

		return null;
	}

	/**
	 * Saves the tree through Elementor's Document API, restoring on failure.
	 *
	 * @param WP_Post           $post     Target post.
	 * @param array<int, mixed> $tree     Mutated tree.
	 * @param string            $snapshot Raw stored value before the mutation.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function save( WP_Post $post, array $tree, $snapshot ) {
		$post_id  = (int) $post->ID;
		$warnings = array();

		$document = \Elementor\Plugin::$instance->documents->get( $post_id, false );
		if ( ! $document ) {
			return new WP_Error(
				'document_unavailable',
				__( 'Elementor could not open this post as an editable document, so the change was not saved.', 'mosmcp-abilities' )
			);
		}

		/*
		 * Only 'elements' is passed. Document::save() replaces page settings
		 * wholesale when given a 'settings' key, which would wipe per-page
		 * Elementor settings that have nothing to do with this edit.
		 */
		try {
			$document->save( array( 'elements' => $tree ) );
		} catch ( \Throwable $e ) {
			self::restore( $post_id, $snapshot );

			return new WP_Error(
				'elementor_save_failed',
				sprintf(
					/* translators: %s: exception message from Elementor */
					__( 'Elementor raised an error while saving the layout, so the change was rolled back and the post still holds its previous version. Elementor said: %s', 'mosmcp-abilities' ),
					$e->getMessage()
				)
			);
		}

		$stored = Elementor_Document::raw( $post_id );
		if ( '' === $stored ) {
			self::restore( $post_id, $snapshot );
			return new WP_Error(
				'elementor_save_empty',
				__( 'The layout was empty after saving, which means the save did not take effect. The previous version has been restored.', 'mosmcp-abilities' )
			);
		}

		if ( class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
			try {
				\Elementor\Core\Files\CSS\Post::create( $post_id )->update();
			} catch ( \Throwable $e ) {
				$warnings[] = array(
					'code'    => 'css_regen_failed',
					'message' => __( 'The change was saved, but Elementor could not rebuild this post\'s stylesheet. It will be rebuilt the next time the page is viewed.', 'mosmcp-abilities' ),
					'context' => $e->getMessage(),
				);
			}
		}

		return array( 'warnings' => $warnings );
	}

	/**
	 * Puts the pre-mutation layout back.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $snapshot Raw stored value.
	 * @return void
	 */
	private static function restore( $post_id, $snapshot ) {
		if ( '' === (string) $snapshot ) {
			// The post had no layout before this request, so restoring its previous
			// state means removing the row rather than storing an empty one.
			delete_post_meta( (int) $post_id, Elementor_Document::DATA_KEY );
			return;
		}
		// wp_slash because update_post_meta unslashes on the way in, and the
		// snapshot is already in its stored (slashed) form.
		update_post_meta( (int) $post_id, Elementor_Document::DATA_KEY, wp_slash( $snapshot ) );
	}

	/**
	 * Confirms the recorded changes are actually present after the save.
	 *
	 * @param int                              $post_id       Post ID.
	 * @param array<int, array<string, mixed>> $changes       Recorded changes.
	 * @param string[]                         $verify_ids    Element IDs that must be present.
	 * @param array<int, array<string, mixed>> $verify_values Exact values that must be readable back.
	 * @return true|WP_Error
	 */
	private static function verify( $post_id, array $changes, array $verify_ids = array(), array $verify_values = array() ) {
		$tree = Elementor_Document::tree( $post_id );
		if ( is_wp_error( $tree ) ) {
			return new WP_Error(
				'verify_failed',
				__( 'The layout could not be read back after saving, so the change cannot be confirmed.', 'mosmcp-abilities' )
			);
		}

		foreach ( $verify_values as $expect ) {
			$path = self::locate( $tree, (string) $expect['element_id'] );
			if ( ! $path ) {
				return new WP_Error(
					'verify_failed',
					sprintf(
						/* translators: %s: element ID */
						__( 'After saving, element "%s" is no longer in the layout.', 'mosmcp-abilities' ),
						(string) $expect['element_id']
					)
				);
			}

			$node     = self::node_at( $tree, $path[0] );
			$settings = ( $node && isset( $node['settings'] ) && is_array( $node['settings'] ) ) ? $node['settings'] : array();
			$control  = (string) $expect['control'];

			if ( ! array_key_exists( $control, $settings ) || $settings[ $control ] !== $expect['value'] ) {
				return new WP_Error(
					'change_did_not_persist',
					sprintf(
						/* translators: 1: setting name, 2: element ID */
						__( 'The change to "%1$s" on element "%2$s" did not persist — reading the layout back shows the previous value. Elementor accepts saves silently for post types it is not enabled to build, so check that this post type is enabled under Elementor\'s settings.', 'mosmcp-abilities' ),
						$control,
						(string) $expect['element_id']
					)
				);
			}
		}

		foreach ( $verify_ids as $expected_id ) {
			if ( ! self::locate( $tree, (string) $expected_id ) ) {
				return new WP_Error(
					'verify_failed',
					sprintf(
						/* translators: %s: element ID */
						__( 'After saving, element "%s" is missing from the layout, so the write did not fully persist.', 'mosmcp-abilities' ),
						(string) $expected_id
					)
				);
			}
		}

		foreach ( $changes as $change ) {
			if ( empty( $change['element_id'] ) ) {
				continue;
			}

			/*
			 * Structural changes are verified by presence rather than by setting:
			 * a delete must leave nothing behind, and a duplicate or move must
			 * leave the element findable. Without this, a structural mutation that
			 * silently failed to persist would still be reported as a success.
			 */
			if ( isset( $change['action'] ) ) {
				$found  = self::locate( $tree, (string) $change['element_id'] );
				$expect = 'delete' !== $change['action'];

				if ( $expect && ! $found ) {
					return new WP_Error(
						'verify_failed',
						sprintf(
							/* translators: 1: action name, 2: element ID */
							__( 'After saving, the %1$s of element "%2$s" is not reflected in the layout, so the change did not persist.', 'mosmcp-abilities' ),
							(string) $change['action'],
							(string) $change['element_id']
						)
					);
				}

				if ( ! $expect && $found ) {
					return new WP_Error(
						'verify_failed',
						sprintf(
							/* translators: %s: element ID */
							__( 'After saving, element "%s" is still present, so the deletion did not persist.', 'mosmcp-abilities' ),
							(string) $change['element_id']
						)
					);
				}

				continue;
			}

			if ( ! isset( $change['control'] ) ) {
				continue;
			}

			$path = self::locate( $tree, (string) $change['element_id'] );
			if ( ! $path ) {
				return new WP_Error(
					'verify_failed',
					sprintf(
						/* translators: %s: element ID */
						__( 'After saving, the element "%s" is no longer in the layout. The change may not have applied as expected.', 'mosmcp-abilities' ),
						(string) $change['element_id']
					)
				);
			}

			$node     = self::node_at( $tree, $path[0] );
			$settings = ( $node && isset( $node['settings'] ) && is_array( $node['settings'] ) ) ? $node['settings'] : array();
			$present  = array_key_exists( (string) $change['control'], $settings );

			/*
			 * A change may have removed a setting rather than written one — that is how
			 * a styling property is cleared so the theme's own value applies again. For
			 * those the setting must be absent afterwards, and requiring presence would
			 * report a successful clear as a failure.
			 */
			if ( ! empty( $change['removed'] ) ) {
				if ( $present ) {
					return new WP_Error(
						'verify_failed',
						sprintf(
							/* translators: 1: setting name, 2: element ID */
							__( 'After saving, the setting "%1$s" is still present on element "%2$s", so clearing it did not persist.', 'mosmcp-abilities' ),
							(string) $change['control'],
							(string) $change['element_id']
						)
					);
				}
				continue;
			}

			if ( ! $present ) {
				return new WP_Error(
					'verify_failed',
					sprintf(
						/* translators: 1: setting name, 2: element ID */
						__( 'After saving, the setting "%1$s" is missing from element "%2$s", so the change did not persist.', 'mosmcp-abilities' ),
						(string) $change['control'],
						(string) $change['element_id']
					)
				);
			}
		}

		return true;
	}
}
