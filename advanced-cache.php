<?php
defined( 'ABSPATH' ) || exit;
define( 'MC_ADVANCED_CACHE', true );
if ( is_admin() ) return;
$GLOBALS['mc_config'] = include_once( WP_CONTENT_DIR . '/mnml-cache-config.php' );
if ( empty( $GLOBALS['mc_config'] ) || empty( $GLOBALS['mc_config']['enable_caching'] ) ) return;
$plugin_path = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : WP_CONTENT_DIR . '/plugins/';// not defined yet unless in wp-config
include_once( $plugin_path . '/mnml-cache/serve.php' );
mnmlcache_main();