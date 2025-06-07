<?php
/*
Plugin Name: Mnml Cache
Plugin URI:  https://github.com/andrewklimek/mnml-contact/
Description: 
Author:      Andrew J Klimek
Author URI:  https://andrewklimek.com
License:     GPL2
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Version: 1.0.0
Text Domain: mnml-cache
Domain Path: /languages
*/

namespace mnmlcache;

defined( 'ABSPATH' ) || exit;

if ( !isset( $GLOBALS['mnmlcache_config'] ) ) {
	$GLOBALS['mnmlcache_config'] = include( WP_CONTENT_DIR . '/mnml-cache-config.php' );
	$GLOBALS['mnmlcache_config']['advanced_cache_missing'] = true;
}

require_once __DIR__ . '/class-settings.php';
MC_Settings::factory();

require_once __DIR__ . '/serve.php';// TODO this could maybe be in the conditional below but it throws error on enabling cache right now.

if ( ! empty( $GLOBALS['mnmlcache_config']['enable_caching'] ) ) {
	require_once __DIR__ . '/cloudflare.php';
	require_once __DIR__ . '/class-advanced-cache.php';
	MC_Advanced_Cache::factory();
}

/**
 * Add settings link to plugin actions
 */
function filter_plugin_action_links( $links, $file ) {

	if ( 'mnml-cache/mnml-cache.php' === $file ) {// && current_user_can( 'manage_options' )// also could avoid hard-coding plugin name: basename( __DIR__ ) .'/'. basename( __FILE__ ) 
		$links = (array) $links;
		$links[] = '<a href="' . admin_url( 'options-general.php?page=mnml-cache' ) . '">Settings</a>';
	}

	return $links;
}
add_filter( 'plugin_action_links', __NAMESPACE__ . '\filter_plugin_action_links', 10, 2 );

/**
 * Clean up necessary files
 */
function deactivate() {
	require_once __DIR__ . '/class-advanced-cache.php';
	require_once __DIR__ . '/cloudflare.php';
	MC_Advanced_Cache::factory()->cache_purge();
	MC_Advanced_Cache::factory()->clean_up();
	MC_Advanced_Cache::factory()->toggle_caching( false );
	MC_Settings::factory()->clean_up();
}
add_action( 'deactivate_' . plugin_basename( __FILE__ ), __NAMESPACE__ . '\deactivate' );

/**
 * Would prefer to only delete cache when uninstalling
 * But it would really be a good idea to flush cache after a day of being deactivated
 * and I dont think I can because the functions wouldn't be included
 * or... can I include like this in an anonymous function?
 * https://github.com/WordPress/wordpress-develop/blob/0cb8475c0d07d23893b1d73d755eda5f12024585/src/wp-admin/includes/plugin.php#L1252
 *  or even trigger that uninstall function
 */
function uninstall() {
	require_once __DIR__ . '/class-advanced-cache.php';
	require_once __DIR__ . '/cloudflare.php';
	// MC_Advanced_Cache::factory()->clean_up();
	// MC_Advanced_Cache::factory()->toggle_caching( false );
	MC_Settings::factory()->clean_up();
	MC_Advanced_Cache::factory()->cache_purge();
}
// register_uninstall_hook( __FILE__, 'uninstall' );

/**
 * Create config file
 */
function activate() {
	MC_Settings::factory()->write( array() );
	update_option( 'mnmlcache_notices', [ 'welcome' => 3 ], true );
}
add_action( 'activate_' . plugin_basename( __FILE__ ), __NAMESPACE__ . '\activate' );


/**
 * Broswer Cache for logged-in users
 */
if ( !empty( $GLOBALS['mnmlcache_config']['private_cache'] ) ) {
	// set cache control
	// $nonce_life = apply_filters('nonce_life', DAY_IN_SECONDS);// maybe show this value in settings UI and let them know what to do if its short.
	// $max_age = min($GLOBALS['mnmlcache_config']['private_cache_max_age'] ?? 3600, $nonce_life / 2);
	$max_age = 3600 * ( $GLOBALS['mnmlcache_config']['private_cache_max_age'] ?? 1 );// setting is in hours
	// header("Cache-Control: private, max-age=$max_age");
	// header('Vary: Cookie');
	// add_filter('nocache_headers', function() use ( $max_age ){// TODO this might want some conditional tags. check in what places nocache_headers is used
	// 	return [
	// 		'Cache-Control' => "private, max-age=$max_age",
	// 		'Vary' => 'Cookie',
	// 	];
	// });
	add_filter( 'wp_headers', function( $headers, $wp ) use ( $max_age ) {
		
		// mnmlcache_debug("did_filter(nocache_headers): " . did_filter('nocache_headers') );// returns 2 for password protected, 1 for anythign WP added these to
		// mnmlcache_debug($_SERVER['REQUEST_URI']);
		// mnmlcache_debug($headers);
		
		// this applies to previews....

		// it affects password protected posts, but testing i think youc an review them anyway once you put the password in once. and logging out seemed to work... is that from the vary cookies?

		// This does affect Woo Cart, but they have a safety hook on 'wp' to set no-store again...
		// https://github.com/woocommerce/woocommerce/blob/0d01426dca020bde95275f90002c4f412709269a/plugins/woocommerce/includes/class-wc-cache-helper.php#L149
		// See https://github.com/woocommerce/woocommerce/blob/0d01426dca020bde95275f90002c4f412709269a/plugins/woocommerce/includes/class-wc-cache-helper.php#L46
		if ( isset( $headers['Cache-Control'] ) && false !== strpos( $headers['Cache-Control'], 'no-cache' ) ) {
			$headers['Cache-Control'] = "private, max-age=$max_age";
			$headers['Vary'] = "Cookie";
			unset( $headers['Expires']);
		}
		// mnmlcache_debug($headers);

		return $headers;
	}, 2, 100 );
	// add_action('shutdown', function(){
	// 	mnmlcache_debug('headers at shutdown: ' . var_export(headers_list(),1));
	// 	mnmlcache_debug("did_filter(nocache_headers): " . did_filter('nocache_headers') );// returns 2 for password protected, 1 for anythign WP added these to
	// });
}