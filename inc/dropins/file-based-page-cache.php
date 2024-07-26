<?php
/**
 * File based page cache drop in
 *
 * @package  simple-cache
 */

defined( 'ABSPATH' ) || exit;

// if ( $_SERVER['REMOTE_ADDR'] != '76.73.242.188' ) return;// only work for AJK

if ( ! function_exists( 'sc_serve_file_cache' ) ) {
	return;
}

// Don't cache robots.txt or htacesss.
if ( strpos( $_SERVER['REQUEST_URI'], 'robots.txt' ) !== false || strpos( $_SERVER['REQUEST_URI'], '.htaccess' ) !== false ) {
	return;
}

// Don't cache non-GET requests.
if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'GET' !== $_SERVER['REQUEST_METHOD'] ) {
	return;
}

// Don't cache if logged in.
if ( ! empty( $_COOKIE ) ) {
	// logged in cookie is named 'wordpress_logged_in_{hash}' and value is: username|expiration|token|hmac
	// https://github.com/WordPress/WordPress/blob/5bcb08099305b4572d2aeb97548d6a514291cc33/wp-includes/default-constants.php#L247
	// https://github.com/WordPress/WordPress/blob/bc0c01b1ac747606882dad4a899a84f288ef6bde/wp-includes/user.php#L543
	foreach ( $_COOKIE as $key => $value ) {
		if ( strpos( $key, 'wordpress_logged_in_' ) !== false ) {
			// found logged in cookie... is it expired?
			$cparts = explode( '|', $value );
			if ( count( $cparts ) === 4 && is_numeric( $cparts[1] ) && $cparts[1] > time() ) {
				// still logged in!
				$GLOBALS['sc_cache_logged_in'] = 0;
			}
			break;
		}
	}

	if ( ! empty( $_COOKIE['sc_commented_posts'] ) ) {
		foreach ( $_COOKIE['sc_commented_posts'] as $path ) {
			if ( rtrim( $path, '/' ) === rtrim( $_SERVER['REQUEST_URI'], '/' ) ) {
				// User commented on this post.
				return;
			}
		}
	}
}

$only_cache = [
	// '/account-stats/',
	// '/expense-reports/',
	// '/all-team-credit-card-amounts/',
	// '/meals-and-travel-all-ambass/',
];
// error_log(var_export($_SERVER, true));
if ( $only_cache ) {
	if ( in_array( explode('?', $_SERVER['REQUEST_URI'] )[0], $only_cache) ) {
		if ( isset( $GLOBALS['sc_cache_logged_in'] ) ) $GLOBALS['sc_cache_logged_in'] = 1;
		sc_serve_file_cache();
		// error_log('ob_start - only cache');
		ob_start( 'sc_file_cache' );
		return;
	} else {
		return;
	}
}


$file_extension = $_SERVER['REQUEST_URI'];
$file_extension = preg_replace( '<^(.*?)\?.*$>', '$1', $file_extension );
$file_extension = trim( preg_replace( '<^.*\.(.*)$>', '$1', $file_extension ) );

// Don't cache disallowed extensions. Prevents wp-cron.php, xmlrpc.php, etc.
if ( ! preg_match( '<index\.php$>i', $_SERVER['REQUEST_URI'] ) && in_array( $file_extension, [ 'php', 'xml', 'xsl' ] ) ) {
	return;
}

// Deal with optional cache exceptions.
if ( ! empty( $GLOBALS['sc_config']['advanced_mode'] ) && ! empty( $GLOBALS['sc_config']['cache_exception_urls'] ) ) {
	$exceptions = preg_split( '<[\r\n]>', $GLOBALS['sc_config']['cache_exception_urls'], 0, PREG_SPLIT_NO_EMPTY );

	$regex = ! empty( $GLOBALS['sc_config']['enable_url_exemption_regex'] );

	foreach ( $exceptions as $exception ) {
		if ( sc_url_exception_match( $exception, $regex ) ) {
			// error_log("skipping exception $exception");
			return;
		}
	}
}

if ( isset( $GLOBALS['sc_cache_logged_in'] ) ) $GLOBALS['sc_cache_logged_in'] = true;

sc_serve_file_cache();
// error_log('ob_start');
ob_start( 'sc_file_cache' );
