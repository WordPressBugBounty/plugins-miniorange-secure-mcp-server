<?php
/**
 * Execute callbacks for the Fluent Forms ability pack.
 *
 * Reads and writes Fluent Forms' own tables directly with prepared statements
 * rather than through its internal model classes, which are not a published API
 * and change between releases. The table names come from the WordPress prefix, and
 * every value is parameterised.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\FluentForms;

use MoSMCP\Abilities\Packs\Site\Site_Support;
use WP_Error;

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

/*
 * These queries read and write Fluent Forms' own tables, which have no WordPress
 * API wrapper and no object cache of their own. Direct prepared queries are the
 * only way to reach them, and caching them here would serve stale form
 * configuration straight after an edit.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery

/**
 * Class Fluent_Forms_Provider
 *
 * Static execute callbacks for the Fluent Forms abilities.
 */
class Fluent_Forms_Provider {

	/**
	 * Meta key Fluent Forms stores each email notification under.
	 */
	const NOTIFICATION_KEY = 'notifications';

	/**
	 * Meta key Fluent Forms stores general form settings under.
	 */
	const SETTINGS_KEY = 'formSettings';

	/**
	 * Forms table name.
	 *
	 * @return string
	 */
	private static function forms_table() {
		global $wpdb;
		return $wpdb->prefix . 'fluentform_forms';
	}

	/**
	 * Form meta table name.
	 *
	 * @return string
	 */
	private static function meta_table() {
		global $wpdb;
		return $wpdb->prefix . 'fluentform_form_meta';
	}

	/**
	 * Submissions table name.
	 *
	 * @return string
	 */
	private static function submissions_table() {
		global $wpdb;
		return $wpdb->prefix . 'fluentform_submissions';
	}

	/**
	 * Lists forms.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public static function list_forms( $input = array() ) {
		global $wpdb;

		$input    = is_array( $input ) ? $input : array();
		$search   = isset( $input['search'] ) ? trim( (string) $input['search'] ) : '';
		$status   = isset( $input['status'] ) ? (string) $input['status'] : 'all';
		$per_page = isset( $input['per_page'] ) ? (int) $input['per_page'] : 25;
		$per_page = max( 1, min( 100, $per_page ) );

		$forms_table = self::forms_table();
		$total       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$forms_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL

		$where  = array( '1=1' );
		$params = array();

		if ( 'all' !== $status ) {
			$where[]  = 'status = %s';
			$params[] = $status;
		}

		if ( '' !== $search ) {
			$where[]  = 'title LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $search ) . '%';
		}

		$params[] = $per_page;

		$sql  = "SELECT * FROM {$forms_table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL

		$forms = array();

		foreach ( (array) $rows as $row ) {
			$forms[] = self::describe_form( $row );
		}

		return array(
			'showing' => count( $forms ),
			'total'   => $total,
			'forms'   => $forms,
		);
	}

	/**
	 * Returns one form.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function get_form( $input = array() ) {
		$row = self::form_row( isset( $input['form_id'] ) ? (int) $input['form_id'] : 0 );

		if ( $row instanceof WP_Error ) {
			return $row;
		}

		return self::describe_form( $row );
	}

	/**
	 * Returns a form's fields.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function get_form_fields( $input = array() ) {
		$row = self::form_row( isset( $input['form_id'] ) ? (int) $input['form_id'] : 0 );

		if ( $row instanceof WP_Error ) {
			return $row;
		}

		$fields = array();

		foreach ( self::parse_fields( $row ) as $field ) {
			$attributes = isset( $field['attributes'] ) && is_array( $field['attributes'] ) ? $field['attributes'] : array();
			$settings   = isset( $field['settings'] ) && is_array( $field['settings'] ) ? $field['settings'] : array();

			$options = array();

			if ( isset( $settings['advanced_options'] ) && is_array( $settings['advanced_options'] ) ) {
				foreach ( $settings['advanced_options'] as $option ) {
					if ( is_array( $option ) && isset( $option['label'] ) ) {
						$options[] = (string) $option['label'];
					}
				}
			}

			$fields[] = array(
				'name'     => isset( $attributes['name'] ) ? (string) $attributes['name'] : '',
				'label'    => isset( $settings['label'] ) ? (string) $settings['label'] : '',
				'type'     => isset( $field['element'] ) ? (string) $field['element'] : '',
				'required' => isset( $settings['validation_rules']['required']['value'] ) ? (bool) $settings['validation_rules']['required']['value'] : false,
				'options'  => $options,
			);
		}

		return array(
			'form_id' => (int) $row['id'],
			'title'   => (string) $row['title'],
			'count'   => count( $fields ),
			'fields'  => $fields,
		);
	}

	/**
	 * Returns a form's settings.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function get_form_settings( $input = array() ) {
		global $wpdb;

		$row = self::form_row( isset( $input['form_id'] ) ? (int) $input['form_id'] : 0 );

		if ( $row instanceof WP_Error ) {
			return $row;
		}

		$form_id  = (int) $row['id'];
		$settings = self::form_settings( $form_id );

		$meta_table = self::meta_table();
		$keys       = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT meta_key FROM {$meta_table} WHERE form_id = %d", $form_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL

		return array(
			'form_id'        => $form_id,
			'confirmation'   => self::describe_confirmation( $settings ),
			'other_settings' => array_values( array_diff( (array) $keys, array( self::SETTINGS_KEY ) ) ),
		);
	}

	/**
	 * Updates a form's confirmation settings.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function update_confirmation( $input = array() ) {
		$row = self::form_row( isset( $input['form_id'] ) ? (int) $input['form_id'] : 0 );

		if ( $row instanceof WP_Error ) {
			return $row;
		}

		$form_id  = (int) $row['id'];
		$settings = self::form_settings( $form_id );
		$previous = self::describe_confirmation( $settings );

		$behaviour = isset( $input['behaviour'] ) ? (string) $input['behaviour'] : '';
		$message   = isset( $input['message'] ) ? (string) $input['message'] : null;
		$redirect  = isset( $input['redirect_url'] ) ? (string) $input['redirect_url'] : null;
		$notes     = array();

		if ( '' === $behaviour && null === $message && null === $redirect ) {
			return Site_Support::error(
				'mosmcp_nothing_to_change',
				__( 'Nothing was supplied to change. Pass a behaviour, a message, or a redirect address.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_INVALID_INPUT,
				true
			);
		}

		if ( 'redirect' === $behaviour && ( null === $redirect || '' === trim( (string) $redirect ) ) ) {
			return Site_Support::error(
				'mosmcp_redirect_url_required',
				__( 'Setting the confirmation to redirect needs a redirect_url to send the visitor to.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_INVALID_INPUT,
				true
			);
		}

		if ( null !== $redirect && '' !== trim( $redirect ) ) {
			$clean = esc_url_raw( trim( $redirect ) );

			if ( '' === $clean ) {
				return Site_Support::error(
					'mosmcp_redirect_url_invalid',
					__( 'That redirect address is not a usable URL.', 'mosmcp-abilities' ),
					Site_Support::CAUSE_INVALID_INPUT,
					true
				);
			}

			$redirect = $clean;
		}

		if ( ! isset( $settings['confirmation'] ) || ! is_array( $settings['confirmation'] ) ) {
			$settings['confirmation'] = array();
		}

		if ( 'message' === $behaviour ) {
			$settings['confirmation']['redirectTo'] = 'samePage';
		} elseif ( 'redirect' === $behaviour ) {
			$settings['confirmation']['redirectTo'] = 'customUrl';
		}

		if ( null !== $message ) {
			$settings['confirmation']['messageToShow'] = wp_kses_post( $message );
		}

		if ( null !== $redirect ) {
			$settings['confirmation']['customUrl'] = $redirect;
		}

		self::write_form_settings( $form_id, $settings );

		// Read back rather than assuming the write landed as sent.
		$current = self::describe_confirmation( self::form_settings( $form_id ) );

		if ( 'customUrl' === ( isset( $settings['confirmation']['redirectTo'] ) ? $settings['confirmation']['redirectTo'] : '' ) && '' === $current['redirect_url'] ) {
			$notes[] = __( 'The form is set to redirect but no address is stored, so visitors will not be sent anywhere. Supply a redirect_url.', 'mosmcp-abilities' );
		}

		return array(
			'form_id'  => $form_id,
			'previous' => $previous,
			'current'  => $current,
			'changed'  => ( $previous !== $current ),
			'notes'    => $notes,
		);
	}

	/**
	 * Lists a form's notifications.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function list_notifications( $input = array() ) {
		$row = self::form_row( isset( $input['form_id'] ) ? (int) $input['form_id'] : 0 );

		if ( $row instanceof WP_Error ) {
			return $row;
		}

		$form_id = (int) $row['id'];
		$rows    = self::notification_rows( $form_id );
		$list    = array();
		$enabled = 0;

		foreach ( $rows as $meta ) {
			$summary = self::describe_notification( $meta, false );
			$list[]  = $summary;

			if ( ! empty( $summary['enabled'] ) ) {
				++$enabled;
			}
		}

		$notes = array();

		if ( empty( $list ) ) {
			$notes[] = __( 'This form has no email notifications at all, so submitting it emails nobody. Add one with mosmcp/fluent-create-notification.', 'mosmcp-abilities' );
		} elseif ( 0 === $enabled ) {
			$notes[] = __( 'Every notification on this form is switched off, so submitting it emails nobody. That is the usual reason a form appears to work but nothing arrives.', 'mosmcp-abilities' );
		}

		return array(
			'form_id'       => $form_id,
			'count'         => count( $list ),
			'enabled_count' => $enabled,
			'notifications' => $list,
			'notes'         => $notes,
		);
	}

	/**
	 * Returns one notification in full.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function get_notification( $input = array() ) {
		$meta = self::notification_row( isset( $input['notification_id'] ) ? (int) $input['notification_id'] : 0 );

		if ( $meta instanceof WP_Error ) {
			return $meta;
		}

		return self::describe_notification( $meta, true );
	}

	/**
	 * Adds a notification to a form.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function create_notification( $input = array() ) {
		global $wpdb;

		$row = self::form_row( isset( $input['form_id'] ) ? (int) $input['form_id'] : 0 );

		if ( $row instanceof WP_Error ) {
			return $row;
		}

		$form_id = (int) $row['id'];

		foreach ( array( 'name', 'send_to', 'subject', 'message' ) as $required ) {
			if ( ! isset( $input[ $required ] ) || '' === trim( (string) $input[ $required ] ) ) {
				return Site_Support::error(
					'mosmcp_notification_field_required',
					sprintf(
						/* translators: %s: the missing input name. */
						__( 'A notification needs %s.', 'mosmcp-abilities' ),
						$required
					),
					Site_Support::CAUSE_INVALID_INPUT,
					true
				);
			}
		}

		/*
		 * Created switched off on purpose. A notification that starts enabled begins
		 * emailing a real recipient the moment the next visitor submits, before anyone
		 * has seen what it says.
		 */
		$notification = array(
			'name'    => sanitize_text_field( (string) $input['name'] ),
			'sendTo'  => array(
				'type'  => 'email',
				'email' => (string) $input['send_to'],
			),
			'subject' => sanitize_text_field( (string) $input['subject'] ),
			'message' => wp_kses_post( (string) $input['message'] ),
			'enabled' => false,
		);

		$meta_table = self::meta_table();

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching
			$meta_table,
			array(
				'form_id'  => $form_id,
				'meta_key' => self::NOTIFICATION_KEY,
				'value'    => wp_json_encode( $notification ),
			),
			array( '%d', '%s', '%s' )
		);

		$new_id = (int) $wpdb->insert_id;

		if ( $new_id < 1 ) {
			return Site_Support::error(
				'mosmcp_notification_create_failed',
				__( 'The notification could not be saved.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_WP_CORE,
				false
			);
		}

		$meta   = self::notification_row( $new_id );
		$result = ( $meta instanceof WP_Error ) ? array() : self::describe_notification( $meta, true );

		$result['notes'] = array( __( 'The notification was created switched off, so nothing is emailed yet. Review what it says, then enable it with mosmcp/fluent-update-notification.', 'mosmcp-abilities' ) );

		return $result;
	}

	/**
	 * Updates an existing notification.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function update_notification( $input = array() ) {
		global $wpdb;

		$meta = self::notification_row( isset( $input['notification_id'] ) ? (int) $input['notification_id'] : 0 );

		if ( $meta instanceof WP_Error ) {
			return $meta;
		}

		$id      = (int) $meta['id'];
		$current = self::decode_notification( $meta );
		$changed = array();

		if ( isset( $input['name'] ) && '' !== trim( (string) $input['name'] ) ) {
			$current['name'] = sanitize_text_field( (string) $input['name'] );
			$changed[]       = 'name';
		}

		if ( isset( $input['send_to'] ) && '' !== trim( (string) $input['send_to'] ) ) {
			if ( ! isset( $current['sendTo'] ) || ! is_array( $current['sendTo'] ) ) {
				$current['sendTo'] = array( 'type' => 'email' );
			}
			$current['sendTo']['email'] = (string) $input['send_to'];
			$changed[]                  = 'send_to';
		}

		if ( isset( $input['subject'] ) && '' !== trim( (string) $input['subject'] ) ) {
			$current['subject'] = sanitize_text_field( (string) $input['subject'] );
			$changed[]          = 'subject';
		}

		if ( isset( $input['message'] ) && '' !== trim( (string) $input['message'] ) ) {
			$current['message'] = wp_kses_post( (string) $input['message'] );
			$changed[]          = 'message';
		}

		if ( array_key_exists( 'enabled', $input ) ) {
			$current['enabled'] = Site_Support::bool_input( $input, 'enabled', false );
			$changed[]          = 'enabled';
		}

		if ( empty( $changed ) ) {
			return array(
				'notification_id' => $id,
				'form_id'         => (int) $meta['form_id'],
				'changed_fields'  => array(),
				'changed'         => false,
				'notification'    => self::describe_notification( $meta, false ),
				'notes'           => array( __( 'Nothing was supplied to change, so the notification is untouched.', 'mosmcp-abilities' ) ),
			);
		}

		$meta_table = self::meta_table();

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching
			$meta_table,
			array( 'value' => wp_json_encode( $current ) ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);

		$after = self::notification_row( $id );
		$notes = array();

		if ( in_array( 'enabled', $changed, true ) ) {
			$notes[] = ! empty( $current['enabled'] )
				? __( 'This notification is now switched on, so the next submission will send a real email.', 'mosmcp-abilities' )
				: __( 'This notification is now switched off. It still exists and can be switched back on at any time.', 'mosmcp-abilities' );
		}

		return array(
			'notification_id' => $id,
			'form_id'         => (int) $meta['form_id'],
			'changed_fields'  => $changed,
			'changed'         => true,
			'notification'    => ( $after instanceof WP_Error ) ? array() : self::describe_notification( $after, false ),
			'notes'           => $notes,
		);
	}

	/**
	 * Deletes a notification.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function delete_notification( $input = array() ) {
		global $wpdb;

		$meta = self::notification_row( isset( $input['notification_id'] ) ? (int) $input['notification_id'] : 0 );

		if ( $meta instanceof WP_Error ) {
			return $meta;
		}

		$decoded = self::decode_notification( $meta );
		$name    = isset( $decoded['name'] ) ? (string) $decoded['name'] : '';

		$unconfirmed = Site_Support::require_confirm(
			$input,
			sprintf(
				/* translators: %s: the notification name. */
				__( 'the notification "%s" and its message are permanently removed', 'mosmcp-abilities' ),
				$name
			)
		);

		if ( $unconfirmed instanceof WP_Error ) {
			return $unconfirmed;
		}

		$id         = (int) $meta['id'];
		$form_id    = (int) $meta['form_id'];
		$meta_table = self::meta_table();

		$wpdb->delete( $meta_table, array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching

		// Confirm against the table rather than trusting the return value.
		$still = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$meta_table} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL

		return array(
			'notification_id' => $id,
			'form_id'         => $form_id,
			'name'            => $name,
			'deleted'         => ( 0 === (int) $still ),
			'notes'           => array( __( 'Submissions already received are unaffected; only the rule that emails them has gone.', 'mosmcp-abilities' ) ),
		);
	}

	/**
	 * Sends a test email using a notification's subject and message.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function test_notification( $input = array() ) {
		$meta = self::notification_row( isset( $input['notification_id'] ) ? (int) $input['notification_id'] : 0 );

		if ( $meta instanceof WP_Error ) {
			return $meta;
		}

		$to = isset( $input['send_to'] ) ? trim( (string) $input['send_to'] ) : '';

		if ( ! is_email( $to ) ) {
			return Site_Support::error(
				'mosmcp_test_recipient_invalid',
				__( 'A real email address is needed to send the test to. A placeholder such as {inputs.email} cannot be used, because there is no submission to take a value from.', 'mosmcp-abilities' ),
				Site_Support::CAUSE_INVALID_INPUT,
				true
			);
		}

		$decoded = self::decode_notification( $meta );
		$subject = isset( $decoded['subject'] ) ? (string) $decoded['subject'] : '';
		$message = isset( $decoded['message'] ) ? (string) $decoded['message'] : '';

		$unconfirmed = Site_Support::require_confirm(
			$input,
			sprintf(
				/* translators: %s: the recipient address. */
				__( 'a real email is sent to %s', 'mosmcp-abilities' ),
				$to
			)
		);

		if ( $unconfirmed instanceof WP_Error ) {
			return $unconfirmed;
		}

		$sent = wp_mail( // phpcs:ignore WordPress.WP.AlternativeFunctions.wp_mail_wp_mail
			$to,
			'[Test] ' . $subject,
			$message,
			array( 'Content-Type: text/html; charset=UTF-8' )
		);

		$notes = array(
			__( 'Placeholders such as {inputs.email} were left as written, because a test has no submission to fill them from. A real notification fills them in.', 'mosmcp-abilities' ),
		);

		$notes[] = $sent
			? __( 'WordPress accepted the message for delivery. That does not prove it arrived: if nothing turns up, the site most likely has no working mail configuration, which is the usual reason form notifications go missing.', 'mosmcp-abilities' )
			: __( 'WordPress could not send the message at all. That is almost certainly why this form\'s notifications never arrive, and it needs an SMTP plugin or host mail configuration to fix.', 'mosmcp-abilities' );

		return array(
			'notification_id' => (int) $meta['id'],
			'sent_to'         => $to,
			'sent'            => (bool) $sent,
			'subject'         => '[Test] ' . $subject,
			'notes'           => $notes,
		);
	}

	/**
	 * Lists submissions.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function list_entries( $input = array() ) {
		global $wpdb;

		$row = self::form_row( isset( $input['form_id'] ) ? (int) $input['form_id'] : 0 );

		if ( $row instanceof WP_Error ) {
			return $row;
		}

		$form_id  = (int) $row['id'];
		$status   = isset( $input['status'] ) ? (string) $input['status'] : 'all';
		$search   = isset( $input['search'] ) ? trim( (string) $input['search'] ) : '';
		$per_page = isset( $input['per_page'] ) ? (int) $input['per_page'] : 20;
		$per_page = max( 1, min( 100, $per_page ) );
		$page     = isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1;
		$offset   = ( $page - 1 ) * $per_page;

		$table  = self::submissions_table();
		$where  = array( 'form_id = %d' );
		$params = array( $form_id );

		if ( 'all' !== $status ) {
			$where[]  = 'status = %s';
			$params[] = $status;
		}

		if ( '' !== $search ) {
			$where[]  = 'response LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $search ) . '%';
		}

		$clause = implode( ' AND ', $where );

		// The placeholders live inside $clause, which is assembled above from fixed
		// fragments only, and every value is passed through $params. The sniff cannot
		// see into the built string, so it is suppressed rather than the query changed.
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$clause}", $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

		$params[] = $per_page;
		$params[] = $offset;

		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$clause} ORDER BY id DESC LIMIT %d OFFSET %d", $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

		$entries = array();

		foreach ( (array) $rows as $entry ) {
			$entries[] = self::describe_entry( $entry );
		}

		$notes = array();

		if ( $total > count( $entries ) ) {
			$notes[] = __( 'More submissions exist than are shown. Ask for the next page rather than assuming this is all of them.', 'mosmcp-abilities' );
		}

		return array(
			'form_id' => $form_id,
			'showing' => count( $entries ),
			'total'   => $total,
			'page'    => $page,
			'entries' => $entries,
			'notes'   => $notes,
		);
	}

	/**
	 * Returns one submission.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function get_entry( $input = array() ) {
		global $wpdb;

		$id    = isset( $input['entry_id'] ) ? (int) $input['entry_id'] : 0;
		$table = self::submissions_table();

		$row = $id > 0 ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ) : null; // phpcs:ignore WordPress.DB.PreparedSQL

		if ( empty( $row ) ) {
			return Site_Support::error(
				'mosmcp_entry_not_found',
				sprintf(
					/* translators: %d: the submission ID supplied. */
					__( 'No Fluent Forms submission with ID %d exists. Use mosmcp/fluent-list-entries to find valid IDs.', 'mosmcp-abilities' ),
					$id
				),
				Site_Support::CAUSE_NOT_FOUND,
				false,
				array( 'use_instead' => 'mosmcp/fluent-list-entries' )
			);
		}

		return self::describe_entry( $row );
	}

	/**
	 * Counts submissions by status.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function count_entries( $input = array() ) {
		global $wpdb;

		$row = self::form_row( isset( $input['form_id'] ) ? (int) $input['form_id'] : 0 );

		if ( $row instanceof WP_Error ) {
			return $row;
		}

		$form_id = (int) $row['id'];
		$since   = isset( $input['since'] ) ? trim( (string) $input['since'] ) : '';
		$table   = self::submissions_table();
		$notes   = array();

		$where  = 'form_id = %d';
		$params = array( $form_id );

		if ( '' !== $since ) {
			if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $since ) ) {
				return Site_Support::error(
					'mosmcp_since_invalid',
					__( 'The since date must be written as YYYY-MM-DD.', 'mosmcp-abilities' ),
					Site_Support::CAUSE_INVALID_INPUT,
					true
				);
			}

			$where   .= ' AND created_at >= %s';
			$params[] = $since . ' 00:00:00';
		}

		$counts = $wpdb->get_results( $wpdb->prepare( "SELECT status, COUNT(*) AS total FROM {$table} WHERE {$where} GROUP BY status", $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

		$by_status = array(
			'unread'  => 0,
			'read'    => 0,
			'trashed' => 0,
		);
		$total     = 0;

		foreach ( (array) $counts as $c ) {
			$status = (string) $c['status'];
			$n      = (int) $c['total'];
			$total += $n;

			if ( isset( $by_status[ $status ] ) ) {
				$by_status[ $status ] = $n;
			}
		}

		if ( 0 === $total ) {
			$notes[] = __( 'This form has no submissions in that range. If visitors say they submitted it, check the notifications with mosmcp/fluent-list-notifications, since a form can record nothing if something is intercepting it.', 'mosmcp-abilities' );
		}

		return array(
			'form_id' => $form_id,
			'total'   => $total,
			'unread'  => $by_status['unread'],
			'read'    => $by_status['read'],
			'trashed' => $by_status['trashed'],
			'since'   => $since,
			'notes'   => $notes,
		);
	}

	/**
	 * Fetches a form row or an error naming why it could not be found.
	 *
	 * @param int $form_id Form ID.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function form_row( $form_id ) {
		global $wpdb;

		$form_id = (int) $form_id;
		$table   = self::forms_table();

		$row = $form_id > 0 ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $form_id ), ARRAY_A ) : null; // phpcs:ignore WordPress.DB.PreparedSQL

		if ( empty( $row ) ) {
			return Site_Support::error(
				'mosmcp_form_not_found',
				sprintf(
					/* translators: %d: the form ID supplied. */
					__( 'No Fluent Form with ID %d exists on this site. Use mosmcp/fluent-list-forms to see the forms and their IDs.', 'mosmcp-abilities' ),
					$form_id
				),
				Site_Support::CAUSE_NOT_FOUND,
				false,
				array( 'use_instead' => 'mosmcp/fluent-list-forms' )
			);
		}

		return $row;
	}

	/**
	 * Fetches a notification meta row, or an error.
	 *
	 * @param int $id Meta row ID.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function notification_row( $id ) {
		global $wpdb;

		$id    = (int) $id;
		$table = self::meta_table();

		$row = $id > 0
			? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d AND meta_key = %s", $id, self::NOTIFICATION_KEY ), ARRAY_A ) // phpcs:ignore WordPress.DB.PreparedSQL
			: null;

		if ( empty( $row ) ) {
			return Site_Support::error(
				'mosmcp_notification_not_found',
				sprintf(
					/* translators: %d: the notification ID supplied. */
					__( 'No Fluent Forms notification with ID %d exists. Use mosmcp/fluent-list-notifications on the form to see its notifications and their IDs.', 'mosmcp-abilities' ),
					$id
				),
				Site_Support::CAUSE_NOT_FOUND,
				false,
				array( 'use_instead' => 'mosmcp/fluent-list-notifications' )
			);
		}

		return $row;
	}

	/**
	 * All notification meta rows for a form.
	 *
	 * @param int $form_id Form ID.
	 * @return array<int, array<string, mixed>>
	 */
	private static function notification_rows( $form_id ) {
		global $wpdb;

		$table = self::meta_table();

		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE form_id = %d AND meta_key = %s ORDER BY id ASC", (int) $form_id, self::NOTIFICATION_KEY ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Decodes a notification meta row.
	 *
	 * @param array<string, mixed> $meta Meta row.
	 * @return array<string, mixed>
	 */
	private static function decode_notification( array $meta ) {
		$decoded = json_decode( isset( $meta['value'] ) ? (string) $meta['value'] : '', true );

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Shapes a notification for output.
	 *
	 * @param array<string, mixed> $meta      Meta row.
	 * @param bool                 $with_body Whether to include the message body.
	 * @return array<string, mixed>
	 */
	private static function describe_notification( array $meta, $with_body ) {
		$decoded = self::decode_notification( $meta );

		$send_to = '';
		if ( isset( $decoded['sendTo']['email'] ) ) {
			$send_to = (string) $decoded['sendTo']['email'];
		}

		$out = array(
			'notification_id' => (int) $meta['id'],
			'form_id'         => (int) $meta['form_id'],
			'name'            => isset( $decoded['name'] ) ? (string) $decoded['name'] : '',
			'enabled'         => ! empty( $decoded['enabled'] ),
			'send_to'         => $send_to,
			'subject'         => isset( $decoded['subject'] ) ? (string) $decoded['subject'] : '',
		);

		if ( $with_body ) {
			$out['message']  = isset( $decoded['message'] ) ? (string) $decoded['message'] : '';
			$out['reply_to'] = isset( $decoded['replyTo'] ) ? (string) $decoded['replyTo'] : '';
			$out['notes']    = array();
		}

		return $out;
	}

	/**
	 * Shapes a form row for output.
	 *
	 * @param array<string, mixed> $row Form row.
	 * @return array<string, mixed>
	 */
	private static function describe_form( array $row ) {
		global $wpdb;

		$form_id    = (int) $row['id'];
		$subs_table = self::submissions_table();
		$meta_table = self::meta_table();

		$entries = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$subs_table} WHERE form_id = %d", $form_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$notifs  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$meta_table} WHERE form_id = %d AND meta_key = %s", $form_id, self::NOTIFICATION_KEY ) ); // phpcs:ignore WordPress.DB.PreparedSQL

		return array(
			'form_id'            => $form_id,
			'title'              => isset( $row['title'] ) ? (string) $row['title'] : '',
			'status'             => isset( $row['status'] ) ? (string) $row['status'] : '',
			'type'               => isset( $row['type'] ) ? (string) $row['type'] : '',
			'field_count'        => count( self::parse_fields( $row ) ),
			'entry_count'        => $entries,
			'notification_count' => $notifs,
			'has_payment'        => ! empty( $row['has_payment'] ),
			'created_at'         => isset( $row['created_at'] ) ? (string) $row['created_at'] : '',
			'edit_url'           => admin_url( 'admin.php?page=fluent_forms&form_id=' . $form_id . '&route=editor' ),
		);
	}

	/**
	 * Parses a form's field definitions.
	 *
	 * @param array<string, mixed> $row Form row.
	 * @return array<int, array<string, mixed>>
	 */
	private static function parse_fields( array $row ) {
		$decoded = json_decode( isset( $row['form_fields'] ) ? (string) $row['form_fields'] : '', true );

		if ( ! is_array( $decoded ) || ! isset( $decoded['fields'] ) || ! is_array( $decoded['fields'] ) ) {
			return array();
		}

		return self::flatten_fields( $decoded['fields'] );
	}

	/**
	 * Flattens container fields so nested inputs are reported too.
	 *
	 * @param array<int, mixed> $fields Field definitions.
	 * @return array<int, array<string, mixed>>
	 */
	private static function flatten_fields( array $fields ) {
		$flat = array();

		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			// Containers hold their inputs in columns rather than being inputs themselves.
			if ( isset( $field['columns'] ) && is_array( $field['columns'] ) ) {
				foreach ( $field['columns'] as $column ) {
					if ( isset( $column['fields'] ) && is_array( $column['fields'] ) ) {
						$flat = array_merge( $flat, self::flatten_fields( $column['fields'] ) );
					}
				}
				continue;
			}

			$flat[] = $field;
		}

		return $flat;
	}

	/**
	 * Reads a form's settings.
	 *
	 * @param int $form_id Form ID.
	 * @return array<string, mixed>
	 */
	private static function form_settings( $form_id ) {
		global $wpdb;

		$table = self::meta_table();

		$value = $wpdb->get_var( $wpdb->prepare( "SELECT value FROM {$table} WHERE form_id = %d AND meta_key = %s LIMIT 1", (int) $form_id, self::SETTINGS_KEY ) ); // phpcs:ignore WordPress.DB.PreparedSQL

		$decoded = json_decode( (string) $value, true );

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Writes a form's settings.
	 *
	 * @param int                  $form_id  Form ID.
	 * @param array<string, mixed> $settings Settings.
	 * @return void
	 */
	private static function write_form_settings( $form_id, array $settings ) {
		global $wpdb;

		$table   = self::meta_table();
		$form_id = (int) $form_id;

		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE form_id = %d AND meta_key = %s LIMIT 1", $form_id, self::SETTINGS_KEY ) ); // phpcs:ignore WordPress.DB.PreparedSQL

		if ( $existing ) {
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching
				$table,
				array( 'value' => wp_json_encode( $settings ) ),
				array( 'id' => (int) $existing ),
				array( '%s' ),
				array( '%d' )
			);
			return;
		}

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching
			$table,
			array(
				'form_id'  => $form_id,
				'meta_key' => self::SETTINGS_KEY,
				'value'    => wp_json_encode( $settings ),
			),
			array( '%d', '%s', '%s' )
		);
	}

	/**
	 * Shapes confirmation settings for output.
	 *
	 * @param array<string, mixed> $settings Form settings.
	 * @return array<string, mixed>
	 */
	private static function describe_confirmation( array $settings ) {
		$confirmation = isset( $settings['confirmation'] ) && is_array( $settings['confirmation'] ) ? $settings['confirmation'] : array();
		$redirect_to  = isset( $confirmation['redirectTo'] ) ? (string) $confirmation['redirectTo'] : 'samePage';

		return array(
			'behaviour'    => ( 'samePage' === $redirect_to ) ? 'message' : 'redirect',
			'message'      => isset( $confirmation['messageToShow'] ) ? (string) $confirmation['messageToShow'] : '',
			'redirect_url' => isset( $confirmation['customUrl'] ) ? (string) $confirmation['customUrl'] : '',
			'after_submit' => isset( $confirmation['samePageFormBehavior'] ) ? (string) $confirmation['samePageFormBehavior'] : '',
		);
	}

	/**
	 * Shapes a submission for output.
	 *
	 * @param array<string, mixed> $row Submission row.
	 * @return array<string, mixed>
	 */
	private static function describe_entry( array $row ) {
		$values = json_decode( isset( $row['response'] ) ? (string) $row['response'] : '', true );

		return array(
			'entry_id'      => (int) $row['id'],
			'form_id'       => (int) $row['form_id'],
			'serial_number' => isset( $row['serial_number'] ) ? (int) $row['serial_number'] : 0,
			'status'        => isset( $row['status'] ) ? (string) $row['status'] : '',
			'submitted_at'  => isset( $row['created_at'] ) ? (string) $row['created_at'] : '',
			'source_url'    => isset( $row['source_url'] ) ? (string) $row['source_url'] : '',
			'user_id'       => isset( $row['user_id'] ) ? (int) $row['user_id'] : 0,
			'values'        => is_array( $values ) ? $values : array(),
		);
	}
}
