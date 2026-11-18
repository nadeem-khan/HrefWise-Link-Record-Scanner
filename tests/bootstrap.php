<?php
/**
 * Load only an explicitly marked disposable WordPress test site.
 *
 * @package HrefWise
 */

if ( PHP_SAPI !== 'cli' ) {
	exit;
}

$wp_root = getenv( 'HREFWISE_WP_ROOT' );
if ( ! $wp_root || ! is_file( $wp_root . '/wp-load.php' ) ) {
	throw new RuntimeException( 'Set HREFWISE_WP_ROOT to a disposable WordPress installation.' );
}

define( 'WP_ADMIN', true );
define( 'WP_DISABLE_FATAL_ERROR_HANDLER', true );
$_SERVER['HTTP_HOST'] = '127.0.0.1:53320';
require rtrim( $wp_root, '/\\' ) . '/wp-load.php';
if ( ! defined( 'HREFWISE_TEST_SITE' ) || HREFWISE_TEST_SITE !== true ) {
	throw new RuntimeException( 'Database tests require HREFWISE_TEST_SITE=true in the disposable wp-config.php.' );
}
if ( ! function_exists( 'hrefwise_scan_batch' ) ) {
	throw new RuntimeException( 'Activate HrefWise in the disposable installation first.' );
}

require_once ABSPATH . 'wp-admin/includes/screen.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
