<?php
/**
 * REST controller for the deactivation feedback form.
 *
 * @package Miniorange_Secure_MCP_Server
 */

namespace MoSMCP\Common\Controllers\Contact;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Class Deactivation_Controller
 *
 * Handles POST /mosmcp/v1/deactivation-feedback — forwards the reason and
 * optional comments to the miniOrange notification API (non-blocking).
 */
class Deactivation_Controller extends Abstract_Contact_Controller {

	/**
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function submit( WP_REST_Request $request ) {
		$reason     = sanitize_text_field( (string) $request->get_param( 'reason' ) );
		$comments   = sanitize_textarea_field( (string) $request->get_param( 'comments' ) );
		$contact_ok = (bool) $request->get_param( 'contact_ok' );

		if ( '' === $reason ) {
			return new WP_Error(
				'mosmcp_feedback_missing_reason',
				__( 'Reason is required.', 'miniorange-secure-mcp-server' ),
				array( 'status' => 400 )
			);
		}

		$labels = array(
			'not_working'        => 'Not working as expected',
			'missing_features'   => 'Missing features I need',
			'better_alternative' => 'Found a better alternative',
			'temporary'          => 'Temporary deactivation',
			'other'              => 'Other',
			'skipped'            => 'Skipped (no reason given)',
		);

		$reason_label = isset( $labels[ $reason ] ) ? $labels[ $reason ] : ucfirst( $reason );

		$current_user = wp_get_current_user();
		$site_url     = get_site_url();

		$subject = '[Secure MCP Server : ' . MOSMCP_VERSION . '] ' . $reason_label . ' - ' . $current_user->user_email;

		$query = 'Reason :' . $reason_label;
		if ( '' !== $comments ) {
			$query .= '<br><br>Comments :' . nl2br( esc_html( $comments ) );
		}
		$query = '[Secure MCP Server : ' . MOSMCP_VERSION . '] ' . $query;

		$content = '<div >Hello, <br><br>'
			. 'First Name :' . esc_html( $current_user->user_firstname ) . '<br><br>'
			. 'Last  Name :' . esc_html( $current_user->user_lastname ) . '   <br><br>'
			. 'Company :<a href="' . esc_url( $site_url ) . '" target="_blank" >' . esc_html( $site_url ) . '</a><br><br>'
			. 'Phone Number :<br><br>'
			. 'Email :<a href="mailto:' . esc_attr( $current_user->user_email ) . '" target="_blank">' . esc_html( $current_user->user_email ) . '</a><br><br>'
			. 'Follow-up OK :' . ( $contact_ok ? 'Yes' : 'No' ) . '<br><br>'
			. 'Query :' . $query . '<br><br>'
			. self::plugin_config_snapshot()
			. '</div>';

		self::notify( $current_user->user_email, $subject, $content, false );

		return new WP_REST_Response( array( 'received' => true ), 200 );
	}
}
