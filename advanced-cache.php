<?php
defined( 'ABSPATH' ) || exit;

$GLOBALS['mnmlcache_config'] = include( WP_CONTENT_DIR . '/mnml-cache-config.php' );

if ( is_admin() ) return;
if ( empty( $GLOBALS['mnmlcache_config'] ) || empty( $GLOBALS['mnmlcache_config']['enable_caching'] ) ) return;

$plugin_path = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : WP_CONTENT_DIR . '/plugins/';
$serve_file = $plugin_path . '/mnml-cache/serve.php';

if ( ! file_exists( $serve_file ) ) {
	return;
}

require $serve_file;  // Changed from include_once to require for critical early loading
\mnmlcache\main();