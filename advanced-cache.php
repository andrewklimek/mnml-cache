<?php
defined( 'ABSPATH' ) || exit;
$GLOBALS['mnmlcache_config'] = include( WP_CONTENT_DIR . '/mnml-cache-config.php' );
if ( is_admin() ) return;
if ( empty( $GLOBALS['mnmlcache_config'] ) || empty( $GLOBALS['mnmlcache_config']['enable_caching'] ) ) return;
$plugin_path = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : WP_CONTENT_DIR . '/plugins/';// not defined yet unless in wp-config
include_once( $plugin_path . '/mnml-cache/serve.php' );
\mnmlcache\main();