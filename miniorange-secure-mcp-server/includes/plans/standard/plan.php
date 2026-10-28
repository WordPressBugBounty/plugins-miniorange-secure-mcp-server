<?php
/**
 * Standard plan manifest: the free build published on WordPress.org.
 *
 * See {@see \MoSMCP\Common\Plans\Plan_Registry} for the keys.
 *
 * @package Miniorange_Secure_MCP_Server
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'id'       => 'standard',
	'rank'     => 10,
	'requires' => array(),
	'replaces' => array(),
);
