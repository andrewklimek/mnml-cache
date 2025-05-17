<?php
defined( 'ABSPATH' ) || exit;
define( 'SC_ADVANCED_CACHE', true );
if ( is_admin() ) return;
$GLOBALS['sc_config'] = include_once( WP_CONTENT_DIR . '/mnml-cache-config.php' );
if ( empty( $GLOBALS['sc_config'] ) || empty( $GLOBALS['sc_config']['enable_caching'] ) ) return;
$plugin_path = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : WP_CONTENT_DIR . '/plugins/';// not defined yet unless in wp-config
include_once( $plugin_path . '/mnml-cache/file-based-page-cache.php' );