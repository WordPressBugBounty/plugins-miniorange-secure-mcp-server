<?php
/**
 * Fluent Forms ability pack.
 *
 * Gated on Fluent Forms being active, so nothing registers on a site without it.
 * Everything gates on manage_options, matching the WPForms and Gravity Forms packs
 * and reflecting that entries hold whatever personal information a visitor typed.
 *
 * @package Mosmcp_Abilities_Library
 */

namespace MoSMCP\Abilities\Packs\FluentForms;

use MoSMCP\Abilities\Ability;
use MoSMCP\Abilities\Ability_Pack;
use MoSMCP\Abilities\Packs\Core\Support\Core_Schema as Schema;
use MoSMCP\Abilities\Packs\Site\Site_Support;

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
 * Class Fluent_Forms_Pack
 *
 * Declares the Fluent Forms abilities. Execute logic lives in Fluent_Forms_Provider.
 */
class Fluent_Forms_Pack extends Ability_Pack {

	/**
	 * Ability category slug.
	 */
	const CATEGORY = 'mosmcp-fluent-forms';

	/**
	 * Fluent Forms defines this constant, so its absence means the plugin is inactive.
	 *
	 * @return string
	 */
	public function dependency() {
		return 'FLUENTFORM_VERSION';
	}

	/**
	 * Ability category for Fluent Forms abilities.
	 *
	 * @return array Category definition.
	 */
	public function category() {
		return array(
			'slug'        => self::CATEGORY,
			'label'       => __( 'Fluent Forms', 'mosmcp-abilities' ),
			'description' => __( 'Inspect Fluent Forms forms and their fields, manage email notifications and confirmation messages, and read submissions.', 'mosmcp-abilities' ),
		);
	}

	/**
	 * The Fluent Forms abilities.
	 *
	 * @return Ability[]
	 */
	public function abilities() {
		return array_merge( $this->form_abilities(), $this->notification_abilities(), $this->entry_abilities() );
	}

	/**
	 * Form structure and settings abilities.
	 *
	 * @return Ability[]
	 */
	private function form_abilities() {
		return array(
			new Ability(
				'mosmcp/fluent-list-forms',
				array(
					'label'         => __( 'List Fluent Forms', 'mosmcp-abilities' ),
					'description'   => __( 'Lists the Fluent Forms on this site with how many fields and submissions each has. Read-only. Start here to get the form ID every other Fluent Forms ability needs.', 'mosmcp-abilities' ),
					'category'      => self::CATEGORY,
					'capability'    => 'manage_options',
					'annotations'   => Site_Support::annotations( true, false, true, false ),
					'execute'       => array( Fluent_Forms_Provider::class, 'list_forms' ),
					'input_schema'  => Schema::object(
						array(
							'search'   => Schema::str( __( 'Optional keyword matched against the form title.', 'mosmcp-abilities' ) ),
							'status'   => Schema::str(
								__( 'Which forms to return.', 'mosmcp-abilities' ),
								array(
									'enum'    => array( 'all', 'published', 'unpublished' ),
									'default' => 'all',
								)
							),
							'per_page' => Schema::int(
								__( 'Maximum forms to return.', 'mosmcp-abilities' ),
								array(
									'default' => 25,
									'minimum' => 1,
									'maximum' => 100,
								)
							),
						)
					),
					'output_schema' => Schema::object(
						array(
							'showing' => Schema::int(),
							'total'   => Schema::int(),
							'forms'   => Schema::arr( self::form_summary() ),
						),
						array( 'showing', 'total', 'forms' )
					),
				)
			),
			new Ability(
				'mosmcp/fluent-get-form',
				array(
					'label'         => __( 'Get Fluent Form', 'mosmcp-abilities' ),
					'description'   => __( 'Returns one Fluent Form with its status, field count, submission count and how many email notifications it has. Read-only. For the fields themselves use mosmcp/fluent-get-form-fields.', 'mosmcp-abilities' ),
					'category'      => self::CATEGORY,
					'capability'    => 'manage_options',
					'annotations'   => Site_Support::annotations( true, false, true, false ),
					'execute'       => array( Fluent_Forms_Provider::class, 'get_form' ),
					'input_schema'  => Schema::object(
						array( 'form_id' => self::form_id_input() ),
						array( 'form_id' )
					),
					'output_schema' => self::form_summary(),
				)
			),
			new Ability(
				'mosmcp/fluent-get-form-fields',
				array(
					'label'         => __( 'Get Fluent Form Fields', 'mosmcp-abilities' ),
					'description'   => __( 'Lists the fields on a Fluent Form with each one\'s name, label, type and whether it is required. Read-only. The field names are what appear as keys in a submission, so read this before interpreting entries.', 'mosmcp-abilities' ),
					'category'      => self::CATEGORY,
					'capability'    => 'manage_options',
					'annotations'   => Site_Support::annotations( true, false, true, false ),
					'execute'       => array( Fluent_Forms_Provider::class, 'get_form_fields' ),
					'input_schema'  => Schema::object(
						array( 'form_id' => self::form_id_input() ),
						array( 'form_id' )
					),
					'output_schema' => Schema::object(
						array(
							'form_id' => Schema::int(),
							'title'   => Schema::str(),
							'count'   => Schema::int(),
							'fields'  => Schema::arr(
								Schema::object(
									array(
										'name'     => Schema::str( __( 'The key this field uses in a submission.', 'mosmcp-abilities' ) ),
										'label'    => Schema::str(),
										'type'     => Schema::str( __( 'Fluent Forms element type, for example input_text or input_email.', 'mosmcp-abilities' ) ),
										'required' => Schema::boolean(),
										'options'  => Schema::arr( Schema::str(), __( 'Choices, for fields that offer a fixed set.', 'mosmcp-abilities' ) ),
									)
								)
							),
						),
						array( 'form_id', 'count', 'fields' )
					),
				)
			),
			new Ability(
				'mosmcp/fluent-get-form-settings',
				array(
					'label'         => __( 'Get Fluent Form Settings', 'mosmcp-abilities' ),
					'description'   => __( 'Returns a Fluent Form\'s confirmation settings, which is what a visitor sees after submitting, plus its other stored configuration. Read-only.', 'mosmcp-abilities' ),
					'category'      => self::CATEGORY,
					'capability'    => 'manage_options',
					'annotations'   => Site_Support::annotations( true, false, true, false ),
					'execute'       => array( Fluent_Forms_Provider::class, 'get_form_settings' ),
					'input_schema'  => Schema::object(
						array( 'form_id' => self::form_id_input() ),
						array( 'form_id' )
					),
					'output_schema' => Schema::object(
						array(
							'form_id'        => Schema::int(),
							'confirmation'   => Schema::object(
								array(
									'behaviour'    => Schema::str( __( 'What happens after submitting: show a message, or redirect.', 'mosmcp-abilities' ) ),
									'message'      => Schema::str(),
									'redirect_url' => Schema::str(),
									'after_submit' => Schema::str( __( 'Whether the form is hidden or reset once submitted.', 'mosmcp-abilities' ) ),
								)
							),
							'other_settings' => Schema::arr( Schema::str(), __( 'Names of the other settings groups stored for this form.', 'mosmcp-abilities' ) ),
						),
						array( 'form_id', 'confirmation' )
					),
				)
			),
			new Ability(
				'mosmcp/fluent-update-confirmation',
				array(
					'label'         => __( 'Update Fluent Form Confirmation', 'mosmcp-abilities' ),
					'description'   => __( 'Changes what a visitor sees after submitting a Fluent Form: either the thank-you message, or the address to send them to. Returns the previous and new settings. Reversible by calling again with the old values.', 'mosmcp-abilities' ),
					'category'      => self::CATEGORY,
					'capability'    => 'manage_options',
					'annotations'   => Site_Support::annotations( false, false, true, false ),
					'execute'       => array( Fluent_Forms_Provider::class, 'update_confirmation' ),
					'input_schema'  => Schema::object(
						array(
							'form_id'      => self::form_id_input(),
							'behaviour'    => Schema::str(
								__( 'Either "message" to show a thank-you message in place, or "redirect" to send the visitor to another address.', 'mosmcp-abilities' ),
								array( 'enum' => array( 'message', 'redirect' ) )
							),
							'message'      => Schema::str( __( 'The thank-you message. Used when behaviour is "message".', 'mosmcp-abilities' ) ),
							'redirect_url' => Schema::str( __( 'Where to send the visitor. Used when behaviour is "redirect".', 'mosmcp-abilities' ) ),
						),
						array( 'form_id' )
					),
					'output_schema' => Schema::object(
						array(
							'form_id'  => Schema::int(),
							'previous' => Schema::map( __( 'The confirmation settings before the change.', 'mosmcp-abilities' ) ),
							'current'  => Schema::map( __( 'The confirmation settings now stored, read back after writing.', 'mosmcp-abilities' ) ),
							'changed'  => Schema::boolean(),
							'notes'    => Schema::arr( Schema::str() ),
						),
						array( 'form_id', 'changed' )
					),
				)
			),
		);
	}

	/**
	 * Email notification abilities.
	 *
	 * @return Ability[]
	 */
	private function notification_abilities() {
		return array(
			new Ability(
				'mosmcp/fluent-list-notifications',
				array(
					'label'         => __( 'List Fluent Form Notifications', 'mosmcp-abilities' ),
					'description'   => __( 'Lists the email notifications a Fluent Form sends when it is submitted, with who each goes to, its subject, and whether it is switched on. Read-only. This is where to look when a form is not emailing anyone.', 'mosmcp-abilities' ),
					'category'      => self::CATEGORY,
					'capability'    => 'manage_options',
					'annotations'   => Site_Support::annotations( true, false, true, false ),
					'execute'       => array( Fluent_Forms_Provider::class, 'list_notifications' ),
					'input_schema'  => Schema::object(
						array( 'form_id' => self::form_id_input() ),
						array( 'form_id' )
					),
					'output_schema' => Schema::object(
						array(
							'form_id'       => Schema::int(),
							'count'         => Schema::int(),
							'enabled_count' => Schema::int(),
							'notifications' => Schema::arr( self::notification_summary() ),
							'notes'         => Schema::arr( Schema::str() ),
						),
						array( 'form_id', 'count', 'notifications' )
					),
				)
			),
			new Ability(
				'mosmcp/fluent-get-notification',
				array(
					'label'         => __( 'Get Fluent Form Notification', 'mosmcp-abilities' ),
					'description'   => __( 'Returns one email notification in full, including its message body. Read-only.', 'mosmcp-abilities' ),
					'category'      => self::CATEGORY,
					'capability'    => 'manage_options',
					'annotations'   => Site_Support::annotations( true, false, true, false ),
					'execute'       => array( Fluent_Forms_Provider::class, 'get_notification' ),
					'input_schema'  => Schema::object(
						array( 'notification_id' => self::notification_id_input() ),
						array( 'notification_id' )
					),
					'output_schema' => self::notification_detail(),
				)
			),
			new Ability(
				'mosmcp/fluent-create-notification',
				array(
					'label'         => __( 'Create Fluent Form Notification', 'mosmcp-abilities' ),
					'description'   => __( 'Adds a new email notification to a Fluent Form, so submissions are emailed to someone. Placeholders such as {inputs.email} in the recipient, subject or message are filled in from the submission when it sends. New notifications are created switched off, so nothing is emailed until you enable it deliberately.', 'mosmcp-abilities' ),
					'category'      => self::CATEGORY,
					'capability'    => 'manage_options',
					'annotations'   => Site_Support::annotations( false, false, false, false ),
					'execute'       => array( Fluent_Forms_Provider::class, 'create_notification' ),
					'input_schema'  => Schema::object(
						array(
							'form_id' => self::form_id_input(),
							'name'    => Schema::str( __( 'A name for this notification, shown only in the admin.', 'mosmcp-abilities' ) ),
							'send_to' => Schema::str( __( 'Where the email goes. An address, or a placeholder such as {wp.admin_email} or {inputs.email}.', 'mosmcp-abilities' ) ),
							'subject' => Schema::str( __( 'The email subject.', 'mosmcp-abilities' ) ),
							'message' => Schema::str( __( 'The email body. Use {all_data} to include every submitted field.', 'mosmcp-abilities' ) ),
						),
						array( 'form_id', 'name', 'send_to', 'subject', 'message' )
					),
					'output_schema' => self::notification_detail(),
				)
			),
			new Ability(
				'mosmcp/fluent-update-notification',
				array(
					'label'         => __( 'Update Fluent Form Notification', 'mosmcp-abilities' ),
					'description'   => __( 'Changes an existing email notification, including switching it on or off. Only the values you pass are changed; anything omitted keeps its current value. Returns what changed.', 'mosmcp-abilities' ),
					'category'      => self::CATEGORY,
					'capability'    => 'manage_options',
					'annotations'   => Site_Support::annotations( false, false, true, false ),
					'execute'       => array( Fluent_Forms_Provider::class, 'update_notification' ),
					'input_schema'  => Schema::object(
						array(
							'notification_id' => self::notification_id_input(),
							'name'            => Schema::str( __( 'New name. Omit to keep the current one.', 'mosmcp-abilities' ) ),
							'send_to'         => Schema::str( __( 'New recipient. Omit to keep the current one.', 'mosmcp-abilities' ) ),
							'subject'         => Schema::str( __( 'New subject. Omit to keep the current one.', 'mosmcp-abilities' ) ),
							'message'         => Schema::str( __( 'New body. Omit to keep the current one.', 'mosmcp-abilities' ) ),
							'enabled'         => Schema::boolean( __( 'Switch the notification on or off. Omit to leave it as it is.', 'mosmcp-abilities' ) ),
						),
						array( 'notification_id' )
					),
					'output_schema' => Schema::object(
						array(
							'notification_id' => Schema::int(),
							'form_id'         => Schema::int(),
							'changed_fields'  => Schema::arr( Schema::str() ),
							'changed'         => Schema::boolean(),
							'notification'    => self::notification_summary(),
							'notes'           => Schema::arr( Schema::str() ),
						),
						array( 'notification_id', 'changed' )
					),
				)
			),
			new Ability(
				'mosmcp/fluent-delete-notification',
				array(
					'label'         => __( 'Delete Fluent Form Notification', 'mosmcp-abilities' ),
					'description'   => __( 'Permanently removes an email notification from a Fluent Form. This cannot be undone, so it needs confirm set to true. If the intention is only to stop it sending for now, switch it off with mosmcp/fluent-update-notification instead, which is reversible.', 'mosmcp-abilities' ),
					'category'      => self::CATEGORY,
					'capability'    => 'manage_options',
					'annotations'   => Site_Support::annotations( false, true, false, false ),
					'execute'       => array( Fluent_Forms_Provider::class, 'delete_notification' ),
					'input_schema'  => Schema::object(
						array(
							'notification_id' => self::notification_id_input(),
							'confirm'         => Schema::boolean(
								__( 'Must be true. The notification and its message are gone for good.', 'mosmcp-abilities' ),
								array( 'default' => false )
							),
						),
						// Confirm stays optional in the schema so the provider's refusal,
						// which names what is about to be lost, is what the caller receives.
						array( 'notification_id' )
					),
					'output_schema' => Schema::object(
						array(
							'notification_id' => Schema::int(),
							'form_id'         => Schema::int(),
							'name'            => Schema::str(),
							'deleted'         => Schema::boolean(),
							'notes'           => Schema::arr( Schema::str() ),
						),
						array( 'notification_id', 'deleted' )
					),
				)
			),
			new Ability(
				'mosmcp/fluent-test-notification',
				array(
					'label'         => __( 'Send a Test Notification', 'mosmcp-abilities' ),
					'description'   => __( 'Sends one real email using a notification\'s subject and message, to an address you name, so you can check whether the site can send mail at all. Placeholders are left as written rather than filled in, because there is no submission to fill them from. This sends a genuine email, so it needs confirm set to true.', 'mosmcp-abilities' ),
					'category'      => self::CATEGORY,
					'capability'    => 'manage_options',
					'annotations'   => Site_Support::annotations( false, false, false, true ),
					'execute'       => array( Fluent_Forms_Provider::class, 'test_notification' ),
					'input_schema'  => Schema::object(
						array(
							'notification_id' => self::notification_id_input(),
							'send_to'         => Schema::str( __( 'The address to send the test to. Must be a real address, not a placeholder.', 'mosmcp-abilities' ) ),
							'confirm'         => Schema::boolean(
								__( 'Must be true. A real email is sent to the address given.', 'mosmcp-abilities' ),
								array( 'default' => false )
							),
						),
						array( 'notification_id', 'send_to' )
					),
					'output_schema' => Schema::object(
						array(
							'notification_id' => Schema::int(),
							'sent_to'         => Schema::str(),
							'sent'            => Schema::boolean( __( 'Whether WordPress accepted the message for delivery. It cannot tell you whether it arrived.', 'mosmcp-abilities' ) ),
							'subject'         => Schema::str(),
							'notes'           => Schema::arr( Schema::str() ),
						),
						array( 'notification_id', 'sent' )
					),
				)
			),
		);
	}

	/**
	 * Submission abilities.
	 *
	 * @return Ability[]
	 */
	private function entry_abilities() {
		return array(
			new Ability(
				'mosmcp/fluent-list-entries',
				array(
					'label'         => __( 'List Fluent Form Entries', 'mosmcp-abilities' ),
					'description'   => __( 'Lists submissions to a Fluent Form, newest first, with the submitted values. Read-only. Submissions contain whatever personal information a visitor typed, so treat what comes back accordingly and do not repeat it further than the person asking needs.', 'mosmcp-abilities' ),
					'category'      => self::CATEGORY,
					'capability'    => 'manage_options',
					'annotations'   => Site_Support::annotations( true, false, true, false ),
					'execute'       => array( Fluent_Forms_Provider::class, 'list_entries' ),
					'input_schema'  => Schema::object(
						array(
							'form_id'  => self::form_id_input(),
							'status'   => Schema::str(
								__( 'Which submissions to return.', 'mosmcp-abilities' ),
								array(
									'enum'    => array( 'all', 'unread', 'read', 'trashed' ),
									'default' => 'all',
								)
							),
							'search'   => Schema::str( __( 'Optional keyword matched against the submitted values.', 'mosmcp-abilities' ) ),
							'per_page' => Schema::int(
								__( 'Maximum submissions to return.', 'mosmcp-abilities' ),
								array(
									'default' => 20,
									'minimum' => 1,
									'maximum' => 100,
								)
							),
							'page'     => Schema::int(
								__( 'Page number, starting at 1.', 'mosmcp-abilities' ),
								array(
									'default' => 1,
									'minimum' => 1,
								)
							),
						),
						array( 'form_id' )
					),
					'output_schema' => Schema::object(
						array(
							'form_id' => Schema::int(),
							'showing' => Schema::int(),
							'total'   => Schema::int(),
							'page'    => Schema::int(),
							'entries' => Schema::arr( self::entry_summary() ),
							'notes'   => Schema::arr( Schema::str() ),
						),
						array( 'form_id', 'showing', 'total', 'entries' )
					),
				)
			),
			new Ability(
				'mosmcp/fluent-get-entry',
				array(
					'label'         => __( 'Get Fluent Form Entry', 'mosmcp-abilities' ),
					'description'   => __( 'Returns one submission in full, with every submitted value and where it came from. Read-only. Contains personal information.', 'mosmcp-abilities' ),
					'category'      => self::CATEGORY,
					'capability'    => 'manage_options',
					'annotations'   => Site_Support::annotations( true, false, true, false ),
					'execute'       => array( Fluent_Forms_Provider::class, 'get_entry' ),
					'input_schema'  => Schema::object(
						array(
							'entry_id' => Schema::int( __( 'The ID of the submission, as returned by mosmcp/fluent-list-entries.', 'mosmcp-abilities' ), array( 'minimum' => 1 ) ),
						),
						array( 'entry_id' )
					),
					'output_schema' => self::entry_summary(),
				)
			),
			new Ability(
				'mosmcp/fluent-count-entries',
				array(
					'label'         => __( 'Count Fluent Form Entries', 'mosmcp-abilities' ),
					'description'   => __( 'Returns how many submissions a form has, broken down by read, unread and trashed, without returning any of the submitted values. Read-only, and the cheap way to answer "how many enquiries did we get".', 'mosmcp-abilities' ),
					'category'      => self::CATEGORY,
					'capability'    => 'manage_options',
					'annotations'   => Site_Support::annotations( true, false, true, false ),
					'execute'       => array( Fluent_Forms_Provider::class, 'count_entries' ),
					'input_schema'  => Schema::object(
						array(
							'form_id' => self::form_id_input(),
							'since'   => Schema::str( __( 'Optional date, as YYYY-MM-DD. Only submissions from that date onward are counted.', 'mosmcp-abilities' ) ),
						),
						array( 'form_id' )
					),
					'output_schema' => Schema::object(
						array(
							'form_id' => Schema::int(),
							'total'   => Schema::int(),
							'unread'  => Schema::int(),
							'read'    => Schema::int(),
							'trashed' => Schema::int(),
							'since'   => Schema::str(),
							'notes'   => Schema::arr( Schema::str() ),
						),
						array( 'form_id', 'total' )
					),
				)
			),
		);
	}

	/**
	 * The shared form id input.
	 *
	 * @return array<string, mixed>
	 */
	private static function form_id_input() {
		return Schema::int( __( 'The ID of the Fluent Form, as returned by mosmcp/fluent-list-forms.', 'mosmcp-abilities' ), array( 'minimum' => 1 ) );
	}

	/**
	 * The shared notification id input.
	 *
	 * @return array<string, mixed>
	 */
	private static function notification_id_input() {
		return Schema::int( __( 'The ID of the notification, as returned by mosmcp/fluent-list-notifications.', 'mosmcp-abilities' ), array( 'minimum' => 1 ) );
	}

	/**
	 * The per-form summary shape.
	 *
	 * @return array<string, mixed>
	 */
	private static function form_summary() {
		return Schema::object(
			array(
				'form_id'            => Schema::int(),
				'title'              => Schema::str(),
				'status'             => Schema::str(),
				'type'               => Schema::str(),
				'field_count'        => Schema::int(),
				'entry_count'        => Schema::int(),
				'notification_count' => Schema::int(),
				'has_payment'        => Schema::boolean(),
				'created_at'         => Schema::str(),
				'edit_url'           => Schema::str(),
			),
			array( 'form_id', 'title', 'status' )
		);
	}

	/**
	 * The per-notification summary shape.
	 *
	 * @return array<string, mixed>
	 */
	private static function notification_summary() {
		return Schema::object(
			array(
				'notification_id' => Schema::int(),
				'form_id'         => Schema::int(),
				'name'            => Schema::str(),
				'enabled'         => Schema::boolean(),
				'send_to'         => Schema::str(),
				'subject'         => Schema::str(),
			),
			array( 'notification_id', 'name', 'enabled' )
		);
	}

	/**
	 * The full notification shape.
	 *
	 * @return array<string, mixed>
	 */
	private static function notification_detail() {
		return Schema::object(
			array(
				'notification_id' => Schema::int(),
				'form_id'         => Schema::int(),
				'name'            => Schema::str(),
				'enabled'         => Schema::boolean(),
				'send_to'         => Schema::str(),
				'subject'         => Schema::str(),
				'message'         => Schema::str(),
				'reply_to'        => Schema::str(),
				'notes'           => Schema::arr( Schema::str() ),
			),
			array( 'notification_id', 'name', 'enabled' )
		);
	}

	/**
	 * The per-entry shape.
	 *
	 * @return array<string, mixed>
	 */
	private static function entry_summary() {
		return Schema::object(
			array(
				'entry_id'      => Schema::int(),
				'form_id'       => Schema::int(),
				'serial_number' => Schema::int( __( 'The per-form submission number shown in the admin.', 'mosmcp-abilities' ) ),
				'status'        => Schema::str(),
				'submitted_at'  => Schema::str(),
				'source_url'    => Schema::str( __( 'The page the form was submitted from.', 'mosmcp-abilities' ) ),
				'user_id'       => Schema::int( __( 'The logged-in user who submitted it, or 0 for a visitor.', 'mosmcp-abilities' ) ),
				'values'        => Schema::map( __( 'The submitted values, keyed by field name.', 'mosmcp-abilities' ) ),
			),
			array( 'entry_id', 'form_id', 'status' )
		);
	}
}
