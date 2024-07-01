<?php
/**
 * Plugin Name: Simple Cache - AJK Mod
 * Plugin URI: https://taylorlovett.com
 * Description: A simple caching plugin that just works.
 * Author: Taylor Lovett
 * Version: 2.0.0
 * Text Domain: simple-cache
 * Domain Path: /languages
 * Author URI: https://taylorlovett.com
 *
 * @package  simple-cache
 */

defined( 'ABSPATH' ) || exit;

// I'm not using multisite and I'm not sure this is correct
// if ( is_multisite() ) {
// 	$active_plugins = get_site_option( 'active_sitewide_plugins' );
// 	if ( isset( $active_plugins[ plugin_basename( __FILE__ ) ] ) )
// 		define( 'SC_IS_NETWORK', true );
// }
defined( 'SC_IS_NETWORK' ) || define( 'SC_IS_NETWORK', false );

require_once __DIR__ . '/inc/pre-wp-functions.php';
require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/class-sc-notices.php';
require_once __DIR__ . '/inc/class-sc-settings.php';
require_once __DIR__ . '/inc/class-sc-config.php';
require_once __DIR__ . '/inc/class-sc-advanced-cache.php';
require_once __DIR__ . '/inc/class-sc-object-cache.php';
require_once __DIR__ . '/inc/class-sc-cron.php';

SC_Settings::factory();
SC_Advanced_Cache::factory();
SC_Object_Cache::factory();
SC_Cron::factory();
SC_Notices::factory();

/**
 * Add settings link to plugin actions
 *
 * @param  array  $plugin_actions Each action is HTML.
 * @param  string $plugin_file Path to plugin file.
 * @since  1.0
 * @return array
 */
function sc_filter_plugin_action_links( $links, $file ) {

	if ( 'simple-cache/simple-cache.php' === $file ) {// && current_user_can( 'manage_options' )// also could avoid hard-coding plugin name: basename( __DIR__ ) .'/'. basename( __FILE__ ) 
		$links = (array) $links;
		$links[] = '<a href="' . admin_url( 'options-general.php?page=simple-cache' ) . '">Settings</a>';// this isn't correct for network is it? I think it's network_admin_url('settings.php')
	}

	return $links;
}
add_filter( 'plugin_action_links', 'sc_filter_plugin_action_links', 10, 2 );

/**
 * Clean up necessary files
 *
 * @param  bool $network Whether the plugin is network wide
 * @since 1.0
 */
function sc_deactivate( $network ) {
	sc_cache_flush( $network );
	SC_Advanced_Cache::factory()->clean_up();
	SC_Advanced_Cache::factory()->toggle_caching( false );
	SC_Object_Cache::factory()->clean_up();
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
	// SC_Object_Cache::factory()->clean_up();
	SC_Config::factory()->clean_up();
	sc_cache_flush( true );// $network attribute?
}
// register_uninstall_hook( __FILE__, 'sc_uninstall' );

/**
 * Create config file
 *
 * @param  bool $network Whether the plugin is network wide
 * @since 1.0
 */
function sc_activate( $network ) {
	if ( $network ) {
		SC_Config::factory()->write( array(), true );
	} else {
		SC_Config::factory()->write( array() );
	}
}
add_action( 'activate_' . plugin_basename( __FILE__ ), 'sc_activate' );
