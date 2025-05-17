<?php
/**
 * File based page cache drop in
 *
 */

defined( 'ABSPATH' ) || exit;

// https://github.com/Automattic/wp-super-cache/blob/88fc6d2b2b3800a34b42230b6b6796a2e6a9d95d/wp-cache-phase2.php#L1575

require_once __DIR__ . '/pre-wp-functions.php';

// Don't cache non-GET requests.
if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'GET' !== $_SERVER['REQUEST_METHOD'] ) {
	return;
}

// Don't cache if logged in.
if ( ! empty( $_COOKIE ) ) {
	// logged in cookie is named 'wordpress_logged_in_{hash}' and value is: username|expiration|token|hmac
	// https://github.com/WordPress/WordPress/blob/5bcb08099305b4572d2aeb97548d6a514291cc33/wp-includes/default-constants.php#L275
	// https://github.com/WordPress/WordPress/blob/bc0c01b1ac747606882dad4a899a84f288ef6bde/wp-includes/user.php#L543
	// https://github.com/WordPress/WordPress/blob/2602d7802145ef0627d84b60ea0fdd2762a33864/wp-includes/pluggable.php#L693
	foreach (array_keys($_COOKIE) as $key) {
			if ( strpos( $key, 'wordpress_logged_in_' ) !== false ) {
			// Login cookie can be expired (the timestamp is the 2nd element if you explode on '|') but it's dangerous to cache when expired because:
			// a) two wordpress_logged_in_ cookies can be present, https and http, and one can have a past expiration time
			// b) there maybe be is not grace period and we'd cache a page with the admin bar
			if ( empty( $GLOBALS['mc_config']['private_cache'] ) ) {
				// error_log('logged in and private cache is turned off');
				return;
			}
			$GLOBALS['mc_cache_logged_in'] = 0;// TODO I forget why I used 0 for this
			break;
		}
	}
}

// Don't cache robots.txt or htacesss.
if ( strpos( $_SERVER['REQUEST_URI'], 'robots.txt' ) !== false || strpos( $_SERVER['REQUEST_URI'], '.htaccess' ) !== false ) {
	return;
}

// Don't cache disallowed extensions. Prevents wp-cron.php, xmlrpc.php, etc. @TODO
if ( strpos( $_SERVER['REQUEST_URI'], '.' ) && strpos( $_SERVER['REQUEST_URI'], 'index.php' ) === false ) {
	$file_extension = array_reverse( explode('.', explode('?', $_SERVER['REQUEST_URI'] )[0] ) )[0];
	if ( in_array( $file_extension, [ 'php', 'xml', 'xsl' ] ) ) {
		error_log( "skipping due to extension: " . $_SERVER['REQUEST_URI'] );
		return;
	}
}

// exceptions
if ( ! empty( $GLOBALS['mc_config']['cache_only_urls'] ) ) {
	$exceptions = preg_split( '<[\r\n]>', $GLOBALS['mc_config']['cache_only_urls'], 0, PREG_SPLIT_NO_EMPTY );

	$regex = ! empty( $GLOBALS['mc_config']['enable_url_exemption_regex'] );
	$matched = false;
	foreach ( $exceptions as $exception ) {
		if ( mc_url_exception_match( $exception, $regex ) ) {
			$matched = true;
			break;
		}
	}
	if ( ! $matched ) {
		// error_log("skipping because not in the 'only cache' setting: " . $_SERVER['REQUEST_URI'] );
		return;
	}
}
elseif ( ! empty( $GLOBALS['mc_config']['cache_exception_urls'] ) ) {
	$exceptions = preg_split( '<[\r\n]>', $GLOBALS['mc_config']['cache_exception_urls'], 0, PREG_SPLIT_NO_EMPTY );

	$regex = ! empty( $GLOBALS['mc_config']['enable_url_exemption_regex'] );

	foreach ( $exceptions as $exception ) {
		if ( mc_url_exception_match( $exception, $regex ) ) {
			// error_log("skipping exception $exception");
			return;
		}
	}
}

if ( isset( $GLOBALS['mc_cache_logged_in'] ) ) $GLOBALS['mc_cache_logged_in'] = true;

mc_serve_file_cache();
// error_log('ob_start');
ob_start( 'mc_file_cache' );
