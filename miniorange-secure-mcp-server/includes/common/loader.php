<?php
/**
 * Common bootstrap: wires the shared (plan-independent) hooks.
 *
 * Required from the main plugin file after the Composer autoloader is available.
 * When plan-specific functionality is added, a matching plans/<plan>/loader.php
 * will be required alongside this one.
 *
 * @package Miniorange_Secure_MCP_Server
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MoSMCP\Common\Hooks\Hooks;

Hooks::init();
