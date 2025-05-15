<?php
/**
 * Plugin Name: Mnml Cache
 * Plugin URI: 
 * Description: 
 * Author: 
 * Version: 1.0.0
 * Text Domain: simple-cache
 * Domain Path: /languages
 * Author URI: 
 *
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/pre-wp-functions.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/class-sc-notices.php';
require_once __DIR__ . '/class-sc-settings.php';
require_once __DIR__ . '/class-sc-config.php';
SC_Notices::factory();
SC_Settings::factory();
$config = SC_Config::factory()->get();

if ( ! empty( $config['enable_page_caching'] ) ) {
	require_once __DIR__ . '/class-sc-advanced-cache.php';
	require_once __DIR__ . '/class-sc-cron.php';	
	SC_Advanced_Cache::factory();
	SC_Cron::factory();
}

/**
 * Add settings link to plugin actions
 *
 * @param  array  $plugin_actions Each action is HTML.
 * @param  string $plugin_file Path to plugin file.
 * @return array
 */
function sc_filter_plugin_action_links( $links, $file ) {

	if ( 'simple-cache/simple-cache.php' === $file ) {// && current_user_can( 'manage_options' )// also could avoid hard-coding plugin name: basename( __DIR__ ) .'/'. basename( __FILE__ ) 
		$links = (array) $links;
		$links[] = '<a href="' . admin_url( 'options-general.php?page=simple-cache' ) . '">Settings</a>';
	}

	return $links;
}
add_filter( 'plugin_action_links', 'sc_filter_plugin_action_links', 10, 2 );

/**
 * Clean up necessary files
 */
function sc_deactivate() {
	sc_cache_flush();
	SC_Advanced_Cache::factory()->clean_up();
	SC_Advanced_Cache::factory()->toggle_caching( false );
	SC_Config::factory()->clean_up();
}
add_action( 'deactivate_' . plugin_basename( __FILE__ ), 'sc_deactivate' );

/**
 * Would prefer to only delete cache when uninstalling
 * But it would really be a good idea to flush cache after a day of being deactivated
 * and I dont think I can because the functions wouldn't be included
 * or... can I include like this in an anonymous function?
 * https://github.com/WordPress/wordpress-develop/blob/0cb8475c0d07d23893b1d73d755eda5f12024585/src/wp-admin/includes/plugin.php#L1252
 *  or even trigger that uninstall function
 */
function sc_uninstall() {
	// SC_Advanced_Cache::factory()->clean_up();
	// SC_Advanced_Cache::factory()->toggle_caching( false );
	SC_Config::factory()->clean_up();
	sc_cache_flush();
}
// register_uninstall_hook( __FILE__, 'sc_uninstall' );

/**
 * Create config file
 */
function sc_activate() {
	SC_Config::factory()->write( array() );
}
add_action( 'activate_' . plugin_basename( __FILE__ ), 'sc_activate' );
