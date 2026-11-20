<?php
/**
 * Value object describing a single first-party ability before registration.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Ability
 *
 * An immutable description of one ability. Packs build these; the
 * Ability_Registrar validates them and registers the survivors with the
 * WordPress Abilities API.
 *
 * The developer-supplied execute callback is preserved verbatim — the framework
 * governs only the surrounding contract (capability gate, MCP annotations, schema
 * hygiene, reserved-key screening), never the business logic inside the callback.
 */
class Ability {

	/**
	 * Fully-qualified ability name, for example "mosmcp/post-create-draft".
	 *
	 * @var string
	 */
	private $name;

	/**
	 * Raw definition arguments as supplied by the pack.
	 *
	 * @var array<string, mixed>
	 */
	private $args;

	/**
	 * Constructor.
	 *
	 * @param string               $name Ability name. Must be namespaced under "mosmcp/".
	 * @param array<string, mixed> $args {
	 *     Ability definition.
	 *
	 *     @type string        $label           Human-readable label.
	 *     @type string        $description     Description surfaced to clients.
	 *     @type string        $category        Ability category slug.
	 *     @type array         $input_schema    JSON schema for the input object.
	 *     @type array         $output_schema   JSON schema for the output. Optional.
	 *     @type callable      $execute         Developer execute callback, preserved as-is.
	 *     @type string        $capability      WordPress capability required to run. Mandatory.
	 *     @type callable|null $cap_args        Maps input to extra current_user_can() args. Optional.
	 *     @type array         $annotations     The four MCP hints: readonly, destructive, idempotent, open_world.
	 *     @type string[]      $guard_meta_keys Input fields screened against the reserved-key blocklist. Optional.
	 *     @type string        $required_cap    Capability the admin matrix compares to the role.
	 *                                      Omit it and a meta capability such as delete_post is
	 *                                      surfaced as the primitive the role stores (delete_posts).
	 *                                      Set a non-empty value to gate on that capability instead.
	 *                                      Set an empty string to leave the matrix ungated; the
	 *                                      call-time check still applies. Custom post type abilities
	 *                                      whose target type is named in the call do that, because
	 *                                      no one primitive fits every type.
	 *     @type array         $meta            Extra meta merged into the registration. Optional.
	 * }
	 */
	public function __construct( $name, array $args ) {
		$this->name = (string) $name;
		$this->args = $args;
	}

	/**
	 * Reads a definition value with a fallback default.
	 *
	 * @param string $key      Definition key.
	 * @param mixed  $fallback Value returned when the key is absent.
	 * @return mixed
	 */
	private function arg( $key, $fallback = null ) {
		return array_key_exists( $key, $this->args ) ? $this->args[ $key ] : $fallback;
	}

	/**
	 * Ability name.
	 *
	 * @return string
	 */
	public function get_name() {
		return $this->name;
	}

	/**
	 * The raw definition arguments, for deriving a modified copy of this ability.
	 *
	 * @return array<string, mixed>
	 */
	public function to_args() {
		return $this->args;
	}

	/**
	 * Human-readable label.
	 *
	 * @return string
	 */
	public function get_label() {
		return (string) $this->arg( 'label', '' );
	}

	/**
	 * Description surfaced to clients.
	 *
	 * @return string
	 */
	public function get_description() {
		return (string) $this->arg( 'description', '' );
	}

	/**
	 * Ability category slug.
	 *
	 * @return string
	 */
	public function get_category() {
		return (string) $this->arg( 'category', '' );
	}

	/**
	 * Input JSON schema.
	 *
	 * @return array<string, mixed>
	 */
	public function get_input_schema() {
		$schema = $this->arg( 'input_schema', array() );
		return is_array( $schema ) ? $schema : array();
	}

	/**
	 * Output JSON schema. Empty when the ability declares none.
	 *
	 * @return array<string, mixed>
	 */
	public function get_output_schema() {
		$schema = $this->arg( 'output_schema', array() );
		return is_array( $schema ) ? $schema : array();
	}

	/**
	 * Developer execute callback.
	 *
	 * @return callable|null
	 */
	public function get_execute() {
		$execute = $this->arg( 'execute', null );
		return is_callable( $execute ) ? $execute : null;
	}

	/**
	 * WordPress capability required to run the ability.
	 *
	 * @return string
	 */
	public function get_capability() {
		return (string) $this->arg( 'capability', '' );
	}

	/**
	 * Callback mapping input to extra current_user_can() arguments.
	 *
	 * @return callable|null
	 */
	public function get_cap_args() {
		$resolver = $this->arg( 'cap_args', null );
		return is_callable( $resolver ) ? $resolver : null;
	}

	/**
	 * Optional extra permission check ANDed after the capability gate.
	 *
	 * Used for compound authorization an ability cannot express with a single
	 * capability — for example "publish_posts AND can edit this specific post".
	 * It can only further restrict access, never widen it.
	 *
	 * @return callable|null
	 */
	public function get_permission_extra() {
		$extra = $this->arg( 'permission_extra', null );
		return is_callable( $extra ) ? $extra : null;
	}

	/**
	 * The four MCP annotation hints.
	 *
	 * @return array<string, bool>
	 */
	public function get_annotations() {
		$annotations = $this->arg( 'annotations', array() );
		return is_array( $annotations ) ? $annotations : array();
	}

	/**
	 * Input field names screened against the reserved-key blocklist.
	 *
	 * @return string[]
	 */
	public function get_guard_meta_keys() {
		$keys = $this->arg( 'guard_meta_keys', array() );
		return is_array( $keys ) ? array_values( array_map( 'strval', $keys ) ) : array();
	}

	/**
	 * Capability the admin matrix compares against a role.
	 *
	 * A non-empty required_cap is returned unchanged, so an ability that names
	 * its own gate stays gated even when its runtime capability is a meta cap.
	 * An explicit empty string opts out of the static gate. That is how custom
	 * post type abilities are marked: their meta cap (edit_post, delete_post)
	 * resolves to a different primitive for each post type, and the type is an
	 * argument of the call, so the matrix cannot know it. Abilities in that
	 * pack which use a primitive already, such as edit_posts, omit the key and
	 * stay gated on it.
	 *
	 * When the key is omitted, a known meta capability is replaced with the
	 * primitive a role actually stores. delete_post becomes delete_posts. The
	 * runtime check is unchanged and still passes the meta capability and the
	 * object id to current_user_can().
	 *
	 * @return string Empty when the matrix should not gate the ability on a role capability.
	 */
	public function get_required_cap() {
		if ( array_key_exists( 'required_cap', $this->args ) ) {
			return (string) $this->args['required_cap'];
		}

		$capability = $this->get_capability();
		$role_caps  = self::meta_role_caps();

		return isset( $role_caps[ $capability ] ) ? $role_caps[ $capability ] : $capability;
	}

	/**
	 * Core meta capabilities mapped to the primitive stored on a WordPress role.
	 *
	 * This is the set WordPress itself derives through map_meta_cap() for posts,
	 * pages, comments and users. It is not discovered at runtime. A pack that
	 * introduces another meta capability has to add it here, or set required_cap
	 * on that ability, or the matrix will compare the meta name and disable the
	 * checkbox for every role.
	 *
	 * @return array<string, string>
	 */
	private static function meta_role_caps() {
		return array(
			'edit_post'        => 'edit_posts',
			'delete_post'      => 'delete_posts',
			'read_post'        => 'read',
			'publish_post'     => 'publish_posts',
			'edit_page'        => 'edit_pages',
			'delete_page'      => 'delete_pages',
			'read_page'        => 'read',
			'publish_page'     => 'publish_pages',
			'edit_post_meta'   => 'edit_posts',
			'delete_post_meta' => 'edit_posts',
			'read_post_meta'   => 'edit_posts',
			'edit_comment'     => 'moderate_comments',
			'edit_user'        => 'edit_users',
			'delete_user'      => 'delete_users',
		);
	}

	/**
	 * Extra meta merged into the registration.
	 *
	 * @return array<string, mixed>
	 */
	public function get_extra_meta() {
		$meta = $this->arg( 'meta', array() );
		return is_array( $meta ) ? $meta : array();
	}

	/**
	 * Whether the ability writes state (anything not annotated read-only).
	 *
	 * @return bool
	 */
	public function is_writer() {
		$annotations = $this->get_annotations();
		return empty( $annotations['readonly'] );
	}
}
