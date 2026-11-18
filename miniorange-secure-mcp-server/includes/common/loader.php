<?php
/**
 * Common bootstrap: wires the shared (plan-independent) hooks.
 *
 * Required from the main plugin file after the Composer autoloader is available,
 * before {@see \MoSMCP\Common\Plans\Plan_Registry} loads the plans this build ships.
 *
 * @package Miniorange_Secure_MCP_Server
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MoSMCP\Common\Hooks\Hooks;

Hooks::init();
