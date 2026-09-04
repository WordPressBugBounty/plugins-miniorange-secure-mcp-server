<?php
/**
 * REST controller for the Contact Us form submission.
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
 * Class Contact_Controller
 *
 * Handles POST /mosmcp/v1/contact — sanitizes the three contact fields and
 * forwards the query to the miniOrange notification API.
 */
class Contact_Controller extends Abstract_Contact_Controller {

	/**
	 * Handles the contact form submission.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function submit( WP_REST_Request $request ) {
		$email = sanitize_email( (string) $request->get_param( 'email' ) );
		$phone = sanitize_text_field( (string) $request->get_param( 'phone' ) );
		$query = sanitize_textarea_field( (string) $request->get_param( 'query' ) );

		if ( '' === $email || ! is_email( $email ) ) {
			return new WP_Error(
				'mosmcp_contact_invalid_email',
				__( 'A valid email address is required.', 'miniorange-secure-mcp-server' ),
				array( 'status' => 400 )
			);
		}

		if ( '' === $query ) {
			return new WP_Error(
				'mosmcp_contact_missing_query',
				__( 'Query is required.', 'miniorange-secure-mcp-server' ),
				array( 'status' => 400 )
			);
		}

		$current_user = wp_get_current_user();
		$site_url     = get_site_url();

		$subject = 'Query: Secure MCP Server - ' . MOSMCP_VERSION . ' Plugin - ' . $email;

		$content = sprintf(
			'<div>Hello,<br><br>
First Name : %s<br><br>
Last Name : %s<br><br>
Site : <a href="%s" target="_blank">%s</a><br><br>
Phone Number : %s<br><br>
Email : <a href="mailto:%s" target="_blank">%s</a><br><br>
WordPress Version : %s<br><br>
PHP Version : %s<br><br>
Database Version : %s<br><br>
Query : %s
</div>',
			esc_html( $current_user->first_name ),
			esc_html( $current_user->last_name ),
			esc_url( $site_url ),
			esc_html( $site_url ),
			esc_html( $phone ),
			esc_attr( $email ),
			esc_html( $email ),
			esc_html( get_bloginfo( 'version' ) ),
			esc_html( phpversion() ),
			esc_html( self::db_version() ),
			nl2br( esc_html( $query ) )
		);

		$result = self::notify( $email, $subject, $content );

		if ( is_wp_error( $result ) ) {
			return new WP_Error(
				'mosmcp_contact_api_error',
				__( 'Could not send your message. Please try again later.', 'miniorange-secure-mcp-server' ),
				array( 'status' => 502 )
			);
		}

		return new WP_REST_Response( array( 'sent' => true ), 200 );
	}
}
