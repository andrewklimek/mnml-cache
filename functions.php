<?php
/**
 * Utility functions for plugin
 *
 * @package  simple-cache
 */


// add_action('shutdown', function(){ error_log( "shutdown connection_status: " . connection_status());});

add_action( 'wp_footer', 'sc_flag_page_done', 99999999 );
function sc_flag_page_done() {
	defined( 'SC_FLAG_PAGE_DONE' ) || define( 'SC_FLAG_PAGE_DONE', TRUE );
	// error_log('flag page finished loading ' . $_SERVER['REQUEST_URI']);
}

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
 *
 * @since  1.4
 */
function sc_cache_flush() {
	$paths = array();

	$url_parts = wp_parse_url( home_url() );

	$path = sc_get_cache_dir() . '/' . untrailingslashit( $url_parts['host'] ) . '/';// TODO host can be undefined...

	if ( ! empty( $url_parts['path'] ) && '/' !== $url_parts['path'] ) {
		$path .= trim( $url_parts['path'], '/' );
	}

	$paths[] = $path;

	foreach ( $paths as $rm_path ) {
		sc_rrmdir( $rm_path );
	}

	if ( function_exists( 'wp_cache_flush' ) ) {
		wp_cache_flush();
	}
}

/**
 * Verify we can write to the file system
 *
 * @since  1.7
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
	if ( file_exists( sc_get_config_dir() ) ) {
		if ( ! @is_writable( sc_get_config_dir() ) ) {
			$errors[] = 'config';
		}
	} else {
		if ( file_exists( dirname( sc_get_config_dir() ) ) ) {
			if ( ! @is_writable( dirname( sc_get_config_dir() ) ) ) {
				$errors[] = 'config';
			}
		} else {
			$errors[] = 'config';
		}
	}

	// Make sure cache directory or parent is writeable
	if ( file_exists( sc_get_cache_dir() ) ) {
		if ( ! @is_writable( sc_get_cache_dir() ) ) {
			$errors[] = 'cache';
		}
	} else {
		if ( file_exists( dirname( sc_get_cache_dir() ) ) ) {
			if ( ! @is_writable( dirname( sc_get_cache_dir() ) ) ) {
				$errors[] = 'cache';
			}
		} else {
			if ( file_exists( dirname( dirname( sc_get_cache_dir() ) ) ) ) {
				if ( ! @is_writable( dirname( dirname( sc_get_cache_dir() ) ) ) ) {
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
 * @since  1.7
 */
function sc_rrmdir( $dir ) {
	if ( is_dir( $dir ) ) {
		$objects = scandir( $dir );

		foreach ( $objects as $object ) {
			if ( '.' !== $object && '..' !== $object ) {
				if ( is_dir( $dir . '/' . $object ) ) {
					sc_rrmdir( $dir . '/' . $object );
				} else {
					unlink( $dir . '/' . $object );
				}
			}
		}

		rmdir( $dir );
	}
}
