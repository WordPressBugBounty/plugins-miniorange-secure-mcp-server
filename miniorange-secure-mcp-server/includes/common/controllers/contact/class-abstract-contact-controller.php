<?php
/**
 * Shared miniOrange notification API logic for contact-type controllers.
 *
 * @package Miniorange_Secure_MCP_Server
 */

namespace MoSMCP\Common\Controllers\Contact;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MoSMCP\Common\Controllers\Abstract_Admin_Controller;
use MoSMCP\Common\Repositories\NHI_Store;
use MoSMCP\Common\Repositories\Store;

/**
 * Class Abstract_Contact_Controller
 *
 * Extends Abstract_Admin_Controller with the miniOrange notification API.
 * Extend this for any controller that dispatches email via the miniOrange API.
 */
abstract class Abstract_Contact_Controller extends Abstract_Admin_Controller {

	protected const NOTIFY_ENDPOINT = 'https://login.xecurify.com/moas/api/notify/send';
	protected const CUSTOMER_KEY    = '16555';
	protected const API_KEY         = 'fFd2XcvTGDemZvbw1bcUesNJWEqKbbUq';
	protected const TO_EMAIL        = 'aisupport@xecurify.com';
	protected const BCC_EMAIL       = 'info@xecurify.com';

	/**
	 * Renders the site's current agent/member/RBAC configuration as a
	 * formatted HTML fragment, for context in support emails. Lists every
	 * agent (NHI) with its full role => abilities grant matrix, not just counts.
	 *
	 * @return string HTML fragment.
	 */
	protected static function plugin_config_snapshot() {
		$agents         = NHI_Store::list_all();
		$agents_total   = count( $agents );
		$agents_enabled = NHI_Store::count_enabled();
		$members        = Store::count_connected_members();
		$clients        = Store::list_clients();

		$role_names = array();
		$wp_roles   = wp_roles();
		if ( $wp_roles && is_array( $wp_roles->roles ) ) {
			foreach ( $wp_roles->roles as $slug => $role ) {
				$role_names[ $slug ] = isset( $role['name'] ) ? (string) $role['name'] : (string) $slug;
			}
		}

		$stats_html = '<table cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;margin-bottom:16px;table-layout:fixed;">'
			. '<tr>'
			. self::stat_cell( 'Agents', $agents_enabled . ' / ' . $agents_total, true )
			. self::stat_cell( 'Connected Members', (string) $members, true )
			. self::stat_cell( 'Registered Clients', (string) count( $clients ), false )
			. '</tr></table>';

		$client_rows = array();
		foreach ( $clients as $client ) {
			$label         = '' !== (string) $client['client_name'] ? (string) $client['client_name'] : (string) $client['client_id'];
			$client_rows[] = array(
				esc_html( $label ),
				self::badge( $client['is_enabled'] ? 'Enabled' : 'Disabled', (bool) $client['is_enabled'] ),
				esc_html( (int) $client['active_token_count'] . ' active' ),
			);
		}
		$clients_html = self::section_heading( 'Clients' )
			. self::render_table( array( 'Name', 'Status', 'Sessions' ), $client_rows, 'No clients registered.' );

		$agent_blocks = array();
		foreach ( $agents as $agent ) {
			$role_rows = array();
			foreach ( $agent['role_ability_map'] as $role => $abilities ) {
				if ( empty( $abilities ) ) {
					continue;
				}
				$role_label  = '*' === $role ? 'Any role' : ( isset( $role_names[ $role ] ) ? $role_names[ $role ] : $role );
				$ab_labels   = array_map(
					static function ( $ability ) {
						return '*' === $ability ? 'All abilities' : $ability;
					},
					$abilities
				);
				$role_rows[] = array(
					'<strong style="font-size:12px;">' . esc_html( $role_label ) . '</strong>',
					'<span style="font-family:Consolas,monospace;font-size:11px;color:#444;">' . esc_html( implode( ', ', $ab_labels ) ) . '</span>',
				);
			}

			$agent_blocks[] = '<div style="margin-bottom:10px;padding:10px 12px;background:#fff;border:1px solid #e2e2e2;border-radius:6px;">'
				. '<div style="margin-bottom:6px;">'
				. '<strong style="font-size:13px;color:#222;">' . esc_html( $agent['name'] ) . '</strong>&nbsp;&nbsp;'
				. self::badge( $agent['is_enabled'] ? 'Enabled' : 'Disabled', (bool) $agent['is_enabled'] )
				. '</div>'
				. self::render_table( array( 'Role', 'Abilities' ), $role_rows, 'No roles configured.' )
				. '</div>';
		}
		$agents_html = self::section_heading( 'RBAC Matrix — Role &rarr; Abilities, per Agent' )
			. ( ! empty( $agent_blocks ) ? implode( '', $agent_blocks ) : '<div style="font-size:12px;color:#888;">No agents configured.</div>' );

		return '<div style="font-family:Arial,Helvetica,sans-serif;border:1px solid #dcdcdc;border-radius:8px;padding:16px;margin-top:12px;background:#fafafa;">'
			. '<div style="font-size:15px;font-weight:700;color:#111;margin:0 0 14px;padding-bottom:8px;border-bottom:2px solid #dcdcdc;">Plugin Config</div>'
			. $stats_html
			. $clients_html
			. $agents_html
			. '</div>';
	}

	/**
	 * Renders a single stat cell for the summary row at the top of the snapshot.
	 *
	 * @param string $label     Stat label.
	 * @param string $value     Stat value.
	 * @param bool   $has_right Whether to draw a right divider (false for the last cell).
	 * @return string
	 */
	private static function stat_cell( $label, $value, $has_right ) {
		$border = $has_right ? 'border-right:1px solid #e2e2e2;' : '';
		return '<td style="text-align:center;padding:8px 6px;' . $border . '">'
			. '<div style="font-size:10.5px;color:#888;text-transform:uppercase;letter-spacing:.05em;">' . esc_html( $label ) . '</div>'
			. '<div style="font-size:17px;font-weight:700;color:#222;margin-top:3px;">' . esc_html( $value ) . '</div>'
			. '</td>';
	}

	/**
	 * Renders an "Enabled"/"Disabled"-style status pill.
	 *
	 * @param string $label Pill text.
	 * @param bool   $ok    True for the "good" (green) variant, false for "muted" (red).
	 * @return string
	 */
	private static function badge( $label, $ok ) {
		$bg = $ok ? '#e6f4ea' : '#fdecea';
		$fg = $ok ? '#137333' : '#c5221f';
		return '<span style="display:inline-block;padding:2px 8px;border-radius:10px;font-size:10.5px;font-weight:600;background:' . $bg . ';color:' . $fg . ';">' . esc_html( $label ) . '</span>';
	}

	/**
	 * Renders a section sub-heading used above each snapshot block.
	 *
	 * @param string $title Heading text (may contain HTML entities).
	 * @return string
	 */
	private static function section_heading( $title ) {
		return '<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#666;margin:14px 0 6px;">' . $title . '</div>';
	}

	/**
	 * Renders a simple bordered data table. Cell values are inserted as-is
	 * (callers are responsible for escaping/marking up their own cell content);
	 * headers are escaped here.
	 *
	 * @param string[]        $headers   Column headers.
	 * @param list<string[]>  $rows      Row data, each an array of cell HTML matching $headers.
	 * @param string          $empty_text Text shown when $rows is empty.
	 * @return string
	 */
	private static function render_table( array $headers, array $rows, $empty_text ) {
		if ( empty( $rows ) ) {
			return '<div style="font-size:12px;color:#888;padding:2px 0 4px;">' . esc_html( $empty_text ) . '</div>';
		}

		$thead = '';
		foreach ( $headers as $header ) {
			$thead .= '<th style="text-align:left;padding:5px 8px;font-size:10.5px;color:#666;border-bottom:1px solid #ddd;">' . esc_html( $header ) . '</th>';
		}

		$tbody = '';
		foreach ( $rows as $row ) {
			$cells = '';
			foreach ( $row as $cell ) {
				$cells .= '<td style="padding:6px 8px;border-bottom:1px solid #eee;vertical-align:top;">' . $cell . '</td>';
			}
			$tbody .= '<tr>' . $cells . '</tr>';
		}

		return '<table cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;margin-bottom:4px;">'
			. '<tr>' . $thead . '</tr>' . $tbody
			. '</table>';
	}

	/**
	 * Sends an email via the miniOrange notification API.
	 *
	 * @param string $from_email Sender / reply-to address.
	 * @param string $subject    Email subject line.
	 * @param string $content    HTML email body.
	 * @param bool   $blocking   Whether to wait for the API response (default true).
	 * @return array|null Decoded response array, or null when $blocking is false.
	 */
	protected static function notify( $from_email, $subject, $content, $blocking = true ) {
		$timestamp = (string) time();
		$hash      = hash( 'sha512', self::CUSTOMER_KEY . $timestamp . self::API_KEY );

		$response = wp_remote_post(
			self::NOTIFY_ENDPOINT,
			array(
				'headers'     => array(
					'Content-Type'  => 'application/json',
					'Customer-Key'  => self::CUSTOMER_KEY,
					'Timestamp'     => $timestamp,
					'Authorization' => $hash,
				),
				'body'        => wp_json_encode(
					array(
						'customerKey' => self::CUSTOMER_KEY,
						'sendEmail'   => true,
						'email'       => array(
							'customerKey' => self::CUSTOMER_KEY,
							'fromEmail'   => $from_email,
							'bccEmail'    => self::BCC_EMAIL,
							'fromName'    => 'miniOrange',
							'toEmail'     => self::TO_EMAIL,
							'toName'      => self::TO_EMAIL,
							'subject'     => $subject,
							'content'     => $content,
						),
					)
				),
				'timeout'     => $blocking ? 20 : 10,
				'redirection' => 5,
				'httpversion' => '1.0',
				'blocking'    => $blocking,
				'sslverify'   => true,
			)
		);

		if ( ! $blocking ) {
			return null;
		}

		if ( is_wp_error( $response ) ) {
			return null;
		}

		return json_decode( wp_remote_retrieve_body( $response ), true );
	}
}
