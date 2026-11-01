<?php
/**
 * Runs the Customizer for the theme-settings abilities, in a request where the
 * theme loads it exactly as on Appearance > Customize.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\Theming\Customize;

use MoSMCP\Abilities\Config;
use MoSMCP\Abilities\Packs\Site\Site_Support;
use WP_Error;
use MoSMCP\Abilities\Packs\Theming\Loopback_Session;
use MoSMCP\Abilities\Packs\Theming\Theme_Support;

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
 * Class Customizer_Bridge
 *
 * Themes decide whether to load their Customizer code while their files load,
 * usually only for wp-admin or a Customizer preview, and some build none of their
 * settings otherwise. An ability runs in an API request, too late and in
 * the wrong context to change that. So the Customizer runs in a loopback request
 * to admin-ajax.php, signed in as the same person with a short-lived session,
 * and bootstrapped by core the way the Customizer itself is (wp_customize=on, a
 * fresh changeset UUID so nobody's unsaved Customizer session is ever picked up):
 *
 *  - reading and validating go to this library's own AJAX action, which accepts
 *    only a one-time job stored server-side for that person;
 *  - publishing posts exactly what the Customizer's Publish button posts
 *    (action customize_save, a real nonce, the changeset data, status publish),
 *    so the theme's own save code — dynamic CSS, caches, option clean-up — runs
 *    as it would for a person.
 */
class Customizer_Bridge {

	/**
	 * Seconds each loopback may take.
	 */
	const TIMEOUT = 45;

	/**
	 * Marker around the answer, so output a theme prints does not corrupt it.
	 */
	const MARK = '<<<MOSMCP_CUSTOMIZER>>>';

	/**
	 * Control types that only arrange the Customizer screen (headings, dividers,
	 * notes, links, groups) and hold no value a person would set.
	 */
	const UI_ONLY = '/(heading|divider|description|notice|upgrade|customizer-link|settings-group|builder-header-control|group-title)$/';

	/**
	 * Listens for the loopback. The AJAX action only answers a signed-in person
	 * holding a job this library stored for them moments earlier.
	 *
	 * @return void
	 */
	public static function boot() {
		add_action( 'wp_ajax_' . self::action(), array( __CLASS__, 'handle' ) );
	}

	/**
	 * The AJAX action name.
	 *
	 * @return string
	 */
	private static function action() {
		return Config::prefix() . '_customizer';
	}

	/*
	 * API side
	 */

	/**
	 * Every setting the theme offers that theme settings may change.
	 *
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public static function settings() {
		$answer = self::job( 'settings', array() );
		return is_wp_error( $answer ) ? $answer : (array) $answer['settings'];
	}

	/**
	 * Validates and sanitizes values with each setting's own rules.
	 *
	 * @param array<string, mixed> $values Setting ID => value.
	 * @return array<string, array<string, mixed>>|WP_Error Setting ID => result.
	 */
	public static function validate( array $values ) {
		$answer = self::job( 'validate', array( 'values' => $values ) );
		return is_wp_error( $answer ) ? $answer : (array) $answer['results'];
	}

	/**
	 * Publishes values through the Customizer, as its Publish button does.
	 *
	 * @param array<string, mixed> $values Setting ID => value.
	 * @return true|WP_Error
	 */
	public static function publish( array $values ) {
		if ( empty( $values ) ) {
			return true;
		}

		$data = array();
		foreach ( $values as $id => $value ) {
			$data[ (string) $id ] = array( 'value' => $value );
		}

		$stylesheet = Theme_Support::stylesheet();
		$response   = self::post(
			array(
				'action'                     => 'customize_save',
				'wp_customize'               => 'on',
				'customize_theme'            => $stylesheet,
				'customize_changeset_uuid'   => wp_generate_uuid4(),
				'customize_changeset_status' => 'publish',
				'customize_changeset_title'  => __( 'Theme settings changed through AI', 'mosmcp-abilities' ),
				'customize_changeset_data'   => (string) wp_json_encode( $data ),
			),
			'save-customize_' . $stylesheet
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$start  = strpos( $response['body'], '{' );
		$result = false === $start ? null : json_decode( substr( $response['body'], $start ), true );
		if ( is_array( $result ) && ! empty( $result['success'] ) ) {
			return true;
		}

		$why = is_array( $result ) && isset( $result['data'] ) ? $result['data'] : self::excerpt( $response['body'] );
		if ( is_array( $why ) ) {
			$messages = array();
			foreach ( isset( $why['setting_validities'] ) ? (array) $why['setting_validities'] : array() as $id => $validity ) {
				if ( is_array( $validity ) ) {
					foreach ( $validity as $error ) {
						$messages[] = $id . ': ' . ( isset( $error['message'] ) ? wp_strip_all_tags( (string) $error['message'] ) : '' );
					}
				}
			}
			$why = ! empty( $messages ) ? implode( '; ', $messages ) : ( isset( $why['message'] ) ? (string) $why['message'] : (string) wp_json_encode( $why ) );
		}
		return Site_Support::error(
			'mosmcp_customizer_publish_failed',
			sprintf(
				/* translators: %s: the Customizer's reason. */
				__( 'The Customizer did not save the theme settings: %s', 'mosmcp-abilities' ),
				(string) $why
			),
			Site_Support::CAUSE_WP_CORE,
			false
		);
	}

	/**
	 * Runs a job in the loopback and returns its answer.
	 *
	 * @param string               $op      "settings" or "validate".
	 * @param array<string, mixed> $payload Job data.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function job( $op, array $payload ) {
		$key = wp_generate_password( 32, false, false );
		set_transient(
			self::job_key( $key ),
			array(
				'user'    => get_current_user_id(),
				'op'      => $op,
				'payload' => $payload,
			),
			2 * MINUTE_IN_SECONDS
		);

		$response = self::post(
			array(
				'action'                   => self::action(),
				'wp_customize'             => 'on',
				'customize_theme'          => Theme_Support::stylesheet(),
				'customize_changeset_uuid' => wp_generate_uuid4(),
				'key'                      => $key,
			)
		);
		delete_transient( self::job_key( $key ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$parts  = explode( self::MARK, $response['body'] );
		$answer = count( $parts ) >= 3 ? json_decode( $parts[1], true ) : null;
		if ( ! is_array( $answer ) ) {
			return self::load_error( $response );
		}
		if ( ! empty( $answer['error'] ) ) {
			return Site_Support::error( 'mosmcp_customizer_unavailable', (string) $answer['error'], Site_Support::CAUSE_HOST_ENVIRONMENT, false );
		}
		return $answer;
	}

	/**
	 * Posts to admin-ajax.php as the current person, through a session that
	 * exists only for this request.
	 *
	 * @param array<string, string> $body         Form fields.
	 * @param string                $nonce_action Nonce action to add as "nonce", or empty.
	 * @return array{code:int,body:string}|WP_Error
	 */
	private static function post( array $body, $nonce_action = '' ) {
		$loopback = Theme_Support::loopback();
		if ( $loopback instanceof WP_Error ) {
			return Site_Support::error(
				'mosmcp_customizer_unreachable',
				__( 'Theme settings are read and saved through the Customizer, which runs in a request this site makes to its own wp-admin, and this site cannot reach itself over HTTP. The host can allow the server to request its own domain.', 'mosmcp-abilities' ) . ' ' . $loopback->get_error_message(),
				Site_Support::CAUSE_HOST_ENVIRONMENT,
				true
			);
		}

		$session = Loopback_Session::start( 5 * MINUTE_IN_SECONDS );
		if ( null === $session ) {
			return Site_Support::error( 'mosmcp_customizer_signed_out', __( 'The Customizer has to run as a signed-in person, and none is signed in.', 'mosmcp-abilities' ), Site_Support::CAUSE_CAPABILITY, false );
		}

		$url = admin_url( 'admin-ajax.php' );
		try {
			if ( '' !== $nonce_action ) {
				$body['nonce'] = self::nonce_for( $nonce_action, $session->logged_in_cookie() );
			}

			$response = wp_remote_post(
				$url,
				array(
					'timeout'     => self::TIMEOUT,
					'redirection' => 0,
					'cookies'     => $session->cookies( $url ),
					// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter.
					'sslverify'   => apply_filters( 'https_local_ssl_verify', false ),
					'headers'     => array( 'Cache-Control' => 'no-cache' ),
					'body'        => $body,
				)
			);
		} finally {
			$session->end();
		}

		if ( is_wp_error( $response ) ) {
			return Site_Support::error(
				'mosmcp_customizer_unreachable',
				sprintf(
					/* translators: %s: the error WordPress reported. */
					__( 'The Customizer could not be reached (%s).', 'mosmcp-abilities' ),
					$response->get_error_message()
				),
				Site_Support::CAUSE_HOST_ENVIRONMENT,
				true
			);
		}

		return array(
			'code' => (int) wp_remote_retrieve_response_code( $response ),
			'body' => (string) wp_remote_retrieve_body( $response ),
		);
	}

	/**
	 * A nonce for the loopback's session, made by the site's own nonce function.
	 *
	 * WordPress ties a nonce to the session in the logged-in cookie, and the API
	 * request making it has no such cookie, so the loopback's cookie is presented
	 * for the moment the nonce is made.
	 *
	 * @param string $action    Nonce action.
	 * @param string $logged_in Logged-in cookie value.
	 * @return string
	 */
	private static function nonce_for( $action, $logged_in ) {
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Saved to be put back byte for byte, so neither unslashed nor sanitized.
		$had      = isset( $_COOKIE[ LOGGED_IN_COOKIE ] );
		$previous = $had ? $_COOKIE[ LOGGED_IN_COOKIE ] : '';
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash

		$_COOKIE[ LOGGED_IN_COOKIE ] = $logged_in;
		try {
			return wp_create_nonce( $action );
		} finally {
			if ( $had ) {
				$_COOKIE[ LOGGED_IN_COOKIE ] = $previous;
			} else {
				unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
			}
		}
	}

	/**
	 * Transient holding a job.
	 *
	 * @param string $key Job key.
	 * @return string
	 */
	private static function job_key( $key ) {
		return Config::prefix() . '_cz_' . md5( (string) $key );
	}

	/**
	 * A refusal for a loopback that did not answer as expected.
	 *
	 * @param array{code:int,body:string} $response Response.
	 * @return WP_Error
	 */
	private static function load_error( array $response ) {
		return Site_Support::error(
			'mosmcp_customizer_failed',
			sprintf(
				/* translators: 1: HTTP status, 2: start of the response. */
				__( 'The Customizer did not load for theme settings (HTTP %1$d). Something on the site stops it, often a plugin or the theme failing inside the Customizer: %2$s', 'mosmcp-abilities' ),
				$response['code'],
				self::excerpt( $response['body'] )
			),
			Site_Support::CAUSE_HOST_ENVIRONMENT,
			false
		);
	}

	/**
	 * The readable start of a response body.
	 *
	 * @param string $body Body.
	 * @return string
	 */
	private static function excerpt( $body ) {
		$text = trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $body ) ) );
		return '' === $text ? __( '(empty response)', 'mosmcp-abilities' ) : ( strlen( $text ) > 300 ? substr( $text, 0, 297 ) . '…' : $text );
	}

	/*
	 * Loopback side
	 */

	/**
	 * Answers a job inside the Customizer.
	 *
	 * @return void
	 */
	public static function handle() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- The one-time job key, stored server-side for this signed-in person, is the credential.
		$key = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$job = '' !== $key ? get_transient( self::job_key( $key ) ) : false;
		if ( ! is_array( $job ) || get_current_user_id() !== (int) $job['user'] ) {
			self::answer( array( 'error' => __( 'The request to the Customizer expired or was not made by this site.', 'mosmcp-abilities' ) ) );
		}
		delete_transient( self::job_key( $key ) );

		global $wp_customize;
		if ( ! $wp_customize instanceof \WP_Customize_Manager ) {
			self::answer( array( 'error' => __( 'WordPress did not start the Customizer for this request, so theme settings cannot be read here.', 'mosmcp-abilities' ) ) );
		}
		if ( ! current_user_can( 'customize' ) ) {
			self::answer( array( 'error' => __( 'You are not allowed to use the Customizer on this site.', 'mosmcp-abilities' ) ) );
		}

		if ( 'validate' === $job['op'] ) {
			self::answer( array( 'results' => self::check_values( $wp_customize, (array) $job['payload']['values'] ) ) );
		}
		self::answer( array( 'settings' => self::describe_all( $wp_customize ) ) );
	}

	/**
	 * Prints the answer between markers and ends the request.
	 *
	 * @param array<string, mixed> $data Answer.
	 * @return void
	 */
	private static function answer( array $data ) {
		echo self::MARK . wp_json_encode( $data ) . self::MARK; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON for the loopback client, never shown in a browser.
		wp_die();
	}

	/**
	 * Every setting with a control that theme settings may change.
	 *
	 * @param \WP_Customize_Manager $manager Manager.
	 * @return array<int, array<string, mixed>>
	 */
	private static function describe_all( $manager ) {
		$index = self::index( $manager );
		$out   = array();
		foreach ( $index['controls'] as $control ) {
			$id = $control['id'];
			if ( isset( $out[ $id ] ) || preg_match( self::UI_ONLY, $control['control'] ) ) {
				continue;
			}
			$setting = self::setting( $manager, $id );
			if ( null === $setting || ! self::changeable( $setting ) ) {
				continue;
			}
			$section = isset( $index['sections'][ $control['section_id'] ] ) ? $index['sections'][ $control['section_id'] ] : array(
				'title' => '',
				'panel' => '',
			);

			$out[ $id ] = array(
				'id'          => $id,
				'label'       => $control['label'],
				'description' => $control['description'],
				'control'     => $control['control'],
				'section'     => $section['title'],
				'section_id'  => $control['section_id'],
				'panel'       => isset( $index['panels'][ $section['panel'] ] ) ? $index['panels'][ $section['panel'] ] : '',
				'type'        => (string) $setting->type,
				'value'       => $setting->value(),
				'default'     => $setting->default,
				'choices'     => $control['choices'],
				'input_attrs' => $control['input_attrs'],
			);
		}
		return array_values( $out );
	}

	/**
	 * Every control the Customizer shows, as plain data, with section and panel
	 * titles: core's control objects, plus controls a theme builds only in the
	 * Customizer's JavaScript (see extra_controls()).
	 *
	 * @param \WP_Customize_Manager $manager Manager.
	 * @return array{controls:array<int, array<string, mixed>>,sections:array<string, array{title:string,panel:string}>,panels:array<string, string>}
	 */
	private static function index( $manager ) {
		static $built = null;
		if ( null !== $built ) {
			return $built;
		}

		$extra    = self::extra_controls( $manager );
		$sections = array();
		$panels   = array();
		foreach ( $manager->panels() as $panel ) {
			$panels[ (string) $panel->id ] = self::text( $panel->title );
		}
		foreach ( $manager->sections() as $section ) {
			$sections[ (string) $section->id ] = array(
				'title' => self::text( $section->title ),
				'panel' => (string) $section->panel,
			);
		}

		$controls = array();
		foreach ( $manager->controls() as $control ) {
			if ( ! $control instanceof \WP_Customize_Control ) {
				continue;
			}
			foreach ( (array) $control->settings as $setting ) {
				if ( ! $setting instanceof \WP_Customize_Setting ) {
					continue;
				}
				$id         = (string) $setting->id;
				$controls[] = array(
					'id'          => $id,
					'label'       => self::text( $control->label ),
					'description' => self::text( $control->description ),
					'control'     => (string) $control->type,
					// A theme may park a control in another section for loading reasons.
					'section_id'  => isset( $extra['moved'][ $id ] ) ? $extra['moved'][ $id ] : (string) $control->section,
					'choices'     => self::choices( $control->choices ),
					'input_attrs' => is_array( $control->input_attrs ) ? $control->input_attrs : array(),
				);
			}
		}

		$built = array(
			'controls' => array_merge( $controls, $extra['controls'] ),
			'sections' => $sections + $extra['sections'],
			'panels'   => $panels + $extra['panels'],
		);
		return $built;
	}

	/**
	 * Controls a theme builds only in the Customizer's JavaScript, which core never
	 * sees as control objects; their settings exist only once asked for by ID.
	 *
	 * Themes that do this add theirs through the "{prefix}_customizer_extra_controls"
	 * filter (see Packs\Astra\Astra_Customizer_Adapter), as raw data:
	 *  - controls: control configurations (id or name, label or title, description,
	 *    type, section, choices, input_attrs);
	 *  - sections: section ID => array( 'title' => ..., 'panel' => panel ID );
	 *  - panels:   panel ID => title;
	 *  - moved:    setting ID => section ID, for core settings a theme shows elsewhere.
	 *
	 * Labels and titles are turned into plain text here, so a theme's markup never
	 * reaches the AI.
	 *
	 * @param \WP_Customize_Manager $manager Manager.
	 * @return array{controls:array<int, array<string, mixed>>,sections:array<string, array{title:string,panel:string}>,panels:array<string, string>,moved:array<string, string>}
	 */
	private static function extra_controls( $manager ) {
		$out = array(
			'controls' => array(),
			'sections' => array(),
			'panels'   => array(),
			'moved'    => array(),
		);

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Prefixed via Config::prefix(); the library's prefix is host-configurable and cannot be a literal.
		$extra = apply_filters( Config::prefix() . '_customizer_extra_controls', $out, $manager );
		$extra = is_array( $extra ) ? $extra + $out : $out;

		foreach ( (array) $extra['panels'] as $id => $title ) {
			$out['panels'][ (string) $id ] = self::text( $title );
		}
		foreach ( (array) $extra['sections'] as $id => $section ) {
			$out['sections'][ (string) $id ] = array(
				'title' => self::text( is_array( $section ) && isset( $section['title'] ) ? $section['title'] : '' ),
				'panel' => is_array( $section ) && isset( $section['panel'] ) ? (string) $section['panel'] : '',
			);
		}
		foreach ( (array) $extra['moved'] as $id => $section ) {
			$out['moved'][ (string) $id ] = (string) $section;
		}
		foreach ( (array) $extra['controls'] as $config ) {
			$control = self::from_config( $config, '' );
			if ( '' !== $control['id'] ) {
				$out['controls'][] = $control;
			}
		}

		return $out;
	}

	/**
	 * A control described in a theme's JavaScript configuration, as plain data.
	 *
	 * @param mixed  $config  Control configuration.
	 * @param string $section Section it sits in, when the configuration does not say.
	 * @return array<string, mixed>
	 */
	private static function from_config( $config, $section ) {
		$config = is_array( $config ) ? $config : array();
		$id     = isset( $config['id'] ) ? $config['id'] : ( isset( $config['name'] ) ? $config['name'] : '' );
		return array(
			'id'          => is_string( $id ) ? $id : '',
			'label'       => self::text( isset( $config['label'] ) ? $config['label'] : ( isset( $config['title'] ) ? $config['title'] : '' ) ),
			'description' => self::text( isset( $config['description'] ) ? $config['description'] : '' ),
			'control'     => isset( $config['type'] ) ? (string) $config['type'] : '',
			'section_id'  => isset( $config['section'] ) ? (string) $config['section'] : $section,
			'choices'     => self::choices( isset( $config['choices'] ) ? $config['choices'] : array() ),
			'input_attrs' => isset( $config['input_attrs'] ) && is_array( $config['input_attrs'] ) ? $config['input_attrs'] : array(),
		);
	}

	/**
	 * A setting by ID, creating it the way the Customizer does for settings a
	 * theme registers only on demand.
	 *
	 * @param \WP_Customize_Manager $manager Manager.
	 * @param string                $id      Setting ID.
	 * @return \WP_Customize_Setting|null
	 */
	private static function setting( $manager, $id ) {
		$setting = $manager->get_setting( $id );
		if ( ! $setting ) {
			$manager->add_dynamic_settings( array( $id ) );
			$setting = $manager->get_setting( $id );
		}
		return $setting instanceof \WP_Customize_Setting ? $setting : null;
	}

	/**
	 * Checks values with each setting's own validation and sanitizing.
	 *
	 * @param \WP_Customize_Manager $manager Manager.
	 * @param array<string, mixed>  $values  Setting ID => value; null means the default.
	 * @return array<string, array<string, mixed>>
	 */
	private static function check_values( $manager, array $values ) {
		$out  = array();
		$todo = array();

		foreach ( $values as $id => $value ) {
			$id      = (string) $id;
			$setting = self::setting( $manager, $id );
			if ( ! $setting ) {
				$out[ $id ] = array( 'ok' => false );
				continue;
			}
			$owner = Theme_Settings::owner( $setting->id_data()['base'] );
			if ( null !== $owner ) {
				$out[ $id ] = array(
					'ok'    => false,
					'owner' => $owner,
				);
				continue;
			}
			if ( ! self::changeable( $setting ) ) {
				$out[ $id ] = array(
					'ok'   => false,
					'type' => (string) $setting->type,
				);
				continue;
			}
			$todo[ $id ] = null === $value ? $setting->default : $value;
		}

		$validities = $manager->validate_setting_values(
			$todo,
			array(
				'validate_capability' => true,
				'validate_existence'  => true,
			)
		);

		foreach ( $todo as $id => $value ) {
			$setting  = $manager->get_setting( $id );
			$validity = isset( $validities[ $id ] ) ? $validities[ $id ] : true;
			if ( is_wp_error( $validity ) ) {
				$out[ $id ] = array(
					'ok'      => false,
					'message' => self::text( implode( ' ', $validity->get_error_messages() ) ),
				);
				continue;
			}
			$data       = $setting->id_data();
			$out[ $id ] = array(
				'ok'      => true,
				'value'   => $setting->sanitize( $value ),
				'type'    => (string) $setting->type,
				'base'    => (string) $data['base'],
				'keys'    => array_values( array_map( 'strval', (array) $data['keys'] ) ),
				'label'   => self::label_for( $manager, $setting ),
				'default' => $setting->default,
			);
		}

		return $out;
	}

	/**
	 * Whether theme settings may change a setting: stored as a theme mod or
	 * option (so it can be previewed and restored), not owned by another
	 * ability, and offered by the theme to this person.
	 *
	 * @param \WP_Customize_Setting $setting Setting.
	 * @return bool
	 */
	private static function changeable( $setting ) {
		return in_array( $setting->type, array( 'theme_mod', 'option' ), true )
			&& ! $setting instanceof \WP_Customize_Filter_Setting
			&& null === Theme_Settings::owner( $setting->id_data()['base'] )
			&& $setting->check_capabilities();
	}

	/**
	 * The label of the first control showing a setting.
	 *
	 * @param \WP_Customize_Manager $manager Manager.
	 * @param \WP_Customize_Setting $setting Setting.
	 * @return string
	 */
	private static function label_for( $manager, $setting ) {
		foreach ( self::index( $manager )['controls'] as $control ) {
			if ( $control['id'] === $setting->id && '' !== $control['label'] ) {
				return $control['label'];
			}
		}
		return '';
	}

	/**
	 * Choices as plain text, since themes often put icons or markup in them.
	 *
	 * @param mixed $choices Control choices.
	 * @return array<string, string>
	 */
	private static function choices( $choices ) {
		$out = array();
		foreach ( is_array( $choices ) ? $choices : array() as $value => $label ) {
			if ( is_array( $label ) ) {
				$label = isset( $label['label'] ) ? $label['label'] : ( isset( $label['name'] ) ? $label['name'] : $value );
			}
			$text                   = self::text( is_scalar( $label ) ? (string) $label : (string) $value );
			$out[ (string) $value ] = '' !== $text ? $text : (string) $value;
		}
		return $out;
	}

	/**
	 * Plain text from a label that may hold markup or entities.
	 *
	 * @param mixed $text Text.
	 * @return string
	 */
	private static function text( $text ) {
		$plain = trim( (string) preg_replace( '/\s+/', ' ', html_entity_decode( wp_strip_all_tags( is_scalar( $text ) ? (string) $text : '' ), ENT_QUOTES, 'UTF-8' ) ) );
		return strlen( $plain ) > 200 ? substr( $plain, 0, 197 ) . '…' : $plain;
	}
}
