<?php
/**
 * Bootstraps the standard (free) plan on top of the common base.
 *
 * @package Miniorange_Secure_MCP_Server
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MoSMCP\Standard\Hooks\Hooks;

// A classmap built without this plan cannot load it.
if ( class_exists( Hooks::class ) ) {
	Hooks::init();
}
