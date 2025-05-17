<?php
/**
 * Utility functions for plugin
 */


// add_action('shutdown', function(){ error_log( "shutdown connection_status: " . connection_status());});

// add_action( 'wp_footer', 'sc_flag_page_done', 99999999 );
// function sc_flag_page_done() {
	// defined( 'SC_FLAG_PAGE_DONE' ) || define( 'SC_FLAG_PAGE_DONE', TRUE );
	// error_log('flag page finished loading ' . $_SERVER['REQUEST_URI']);
// }

if ( !empty( $GLOBALS['sc_cache_logged_in'] ) ) {
// 	add_filter( 'show_admin_bar', '__return_false' );
// 	add_action( 'wp_footer', 'sc_wp_admin_bar_placeholder', 1000 );
	add_action( 'template_redirect', 'sc_load_logged_in_cache', 1 );// admin bar gets initiated on 0
}

function sc_wp_admin_bar_placeholder() {
	echo "<!-- sc-cache--wp_admin_bar_placeholder -->";
}

function sc_load_logged_in_cache(){
	// add_filter( 'show_admin_bar', '__return_true', 11 );
	sc_serve_file_cache( get_current_user_id() );
}

/**
 * Clear the cache
 */
function sc_cache_flush() {

	sc_rrmdir( SC_CACHE_DIR );

	if ( function_exists( 'wp_cache_flush' ) ) {
		wp_cache_flush();
	}
}

/**
 * Clear one page
 */
function sc_cache_purge($url) {

	$url_path = sc_get_url_path($url);

    $is_api = strpos($url_path, 'wp-json/') === 0;
    $ext = $is_api ? '.json' : '.html';
    $cache_file = $url_path . $ext;
    $gzip_file = $url_path . ".gzip" . $ext;
    $header_file = $url_path . ".headers.json";
    if (file_exists($cache_file)) {
        unlink($cache_file);
    }
    if (file_exists($gzip_file)) {
        unlink($gzip_file);
    }
    if (file_exists($header_file)) {
        unlink($header_file);
    }
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log("mnml cache: Purged cache for URL: $url");
    }
}

/**
 * Verify we can write to the file system
 *
 * @return array|boolean
 */
function sc_verify_file_access() {
	if ( function_exists( 'clearstatcache' ) ) {
		@clearstatcache();
	}

	$errors = array();

	// First check wp-config.php.
	if ( ! @is_writable( ABSPATH . 'wp-config.php' ) && ! @is_writable( ABSPATH . '../wp-config.php' ) ) {
		$errors[] = 'wp-config';
	}

	// Now check wp-content
	if ( ! @is_writable( untrailingslashit( WP_CONTENT_DIR ) ) ) {
		$errors[] = 'wp-content';
	}

	// Make sure config directory or parent is writeable
	if ( file_exists( WP_CONTENT_DIR ) ) {
		if ( ! @is_writable( WP_CONTENT_DIR ) ) {
			$errors[] = 'config';
		}
	} else {
		if ( file_exists( dirname( WP_CONTENT_DIR ) ) ) {
			if ( ! @is_writable( dirname( WP_CONTENT_DIR ) ) ) {
				$errors[] = 'config';
			}
		} else {
			$errors[] = 'config';
		}
	}

	// Make sure cache directory or parent is writeable
	if ( file_exists( SC_CACHE_DIR ) ) {
		if ( ! @is_writable( SC_CACHE_DIR ) ) {
			$errors[] = 'cache';
		}
	} else {
		if ( file_exists( dirname( SC_CACHE_DIR ) ) ) {
			if ( ! @is_writable( dirname( SC_CACHE_DIR ) ) ) {
				$errors[] = 'cache';
			}
		} else {
			if ( file_exists( dirname( dirname( SC_CACHE_DIR ) ) ) ) {
				if ( ! @is_writable( dirname( dirname( SC_CACHE_DIR ) ) ) ) {
					$errors[] = 'cache';
				}
			} else {
				$errors[] = 'cache';
			}
		}
	}

	if ( ! empty( $errors ) ) {
		return $errors;
	}

	return true;
}

/**
 * Remove directory and all it's contents
 *
 * @param  string $dir Directory
 */
function sc_rrmdir($dir, $retain_root = true) {

	if (!is_string($dir) || empty($dir) || !is_dir($dir) || !is_readable($dir)) {
		return false;
	}

	$handle = opendir($dir);
	if ($handle === false) {
		return false;
	}

	while (false !== ($object = readdir($handle))) {
		if ($object === '.' || $object === '..') continue;
		$path = $dir . DIRECTORY_SEPARATOR . $object;
		if (is_dir($path)) {
			// Recursively clear subdirectory
			if (!sc_rrmdir($path, false)) {
				closedir($handle);
				return false;
			}
		} else {
			if (!@unlink($path)) {
				closedir($handle);
				return false;
			}
		}
	}

	closedir($handle);

	if ( ! $retain_root ) {
		return @rmdir($dir);
	}

	return true;
}
