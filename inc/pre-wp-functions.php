<?php
/**
 * Holds functions that can be loaded in advanced-cache.php
 *
 * @since  1.7
 * @package  simple-cache
 */

/**
 * Cache output before it goes to the browser
 *
 * @param  string $buffer Page HTML.
 * @param  int    $flags OB flags to be passed through.
 * @since  1.7
 * @return string
 */
function sc_file_cache( $buffer, $flags ) {

	if ( ! defined( 'SC_FLAG_PAGE_DONE' ) || ! SC_FLAG_PAGE_DONE ) {
		error_log('page didnt finish loading ' . $_SERVER['REQUEST_URI']);
		return $buffer;
	}

	global $post;


	// error_log( "buffer connection_status: " . connection_status());

	$cache_dir = sc_get_cache_dir();

	// Don't cache small requests unless it's a REST API request.
	if ( mb_strlen( $buffer ) < 255 && ( ! defined( 'REST_REQUEST' ) || ! mb_strlen( $buffer ) > 0 ) ) {
		return $buffer;
	}

	// Don't cache search, 404, or password protected.
	if ( is_404() || is_search() || ! empty( $post->post_password ) ) {
		return $buffer;
	}

	// Do not cache the REST API if the user has not opted-in or it's an authenticated REST API request.
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST && ( empty( $GLOBALS['sc_config']['page_cache_enable_rest_api_cache'] ) || ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) ) {
		return $buffer;
	}

	// Make sure we can read/write files to cache dir parent
	if ( ! file_exists( dirname( $cache_dir ) ) ) {
		if ( ! @mkdir( dirname( $cache_dir ) ) ) {
			// Can not cache!
			return $buffer;
		}
	}

	// Make sure we can read/write files to cache dir
	if ( ! file_exists( $cache_dir ) ) {
		if ( ! @mkdir( $cache_dir ) ) {
			// Can not cache!
			return $buffer;
		}
	} else {
		$buffer = apply_filters( 'sc_pre_cache_buffer', $buffer );
	}

	$url_path = sc_get_url_path();

	$dirs = explode( '/', $url_path );

	$path = $cache_dir;

	foreach ( $dirs as $dir ) {
		if ( ! empty( $dir ) ) {
			$path .= '/' . $dir;

			if ( ! file_exists( $path ) ) {
				if ( ! @mkdir( $path ) ) {
					// Can not cache!
					return $buffer;
				}
			}
		}
	}

	$modified_time = time(); // Make sure modified time is consistent.

	$file_extension = '.html';

	// Store JSON files for the REST API.
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		$file_extension = '.json';
	}

	// Prevent mixed content when there's an http request but the site URL uses https.
	$home_url = get_home_url();

	// phpcs:disable
	if ( ! is_ssl() && 'https' === strtolower( parse_url( $home_url, PHP_URL_SCHEME ) ) ) {
		// phpcs:enable
		$https_home_url = $home_url;
		$http_home_url  = str_replace( 'https://', 'http://', $https_home_url );
		$buffer         = str_replace( esc_url( $http_home_url ), esc_url( $https_home_url ), $buffer );
	}

	if ( $file_extension == '.html' ) {
		$buffer .= "\n<!-- cached by Simple Cache at " . gmdate( 'd M Y H:i:s', $modified_time ) . " UTC -->";
	}

	if ( !empty( $GLOBALS['sc_cache_logged_in'] ) && $id = get_current_user_id() ) {
		$file_extension = ".{$id}{$file_extension}";
	}

	// Save the response body.
	if ( ! empty( $GLOBALS['sc_config']['enable_gzip_compression'] ) && function_exists( 'gzencode' ) ) {
		file_put_contents( $path . '/index.gzip' . $file_extension, gzencode( $buffer, 3 ) );
		touch( $path . '/index.gzip' . $file_extension, $modified_time );
	} else {
		file_put_contents( $path . '/index' . $file_extension, $buffer );
		touch( $path . '/index' . $file_extension, $modified_time );
	}

	header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $modified_time ) . ' GMT' );

	// Save the resonse headers.
	if ( ! empty( $GLOBALS['sc_config']['page_cache_restore_headers'] ) ) {
		file_put_contents( $path . '/headers.json', wp_json_encode( headers_list() ) );
	}

	header( 'Cache-Control: no-cache' ); // Check back every time to see if re-download is necessary.

	header( 'X-Simple-Cache: MISS' );


	if ( function_exists( 'ob_gzhandler' ) && ! empty( $GLOBALS['sc_config']['enable_gzip_compression'] ) ) {
		return ob_gzhandler( $buffer, $flags );
	} else {
		return $buffer;
	}
}

/**
 * Get URL path for caching
 *
 * @since  1.0
 * @return string
 */
function sc_get_url_path() {

	$host = ( isset( $_SERVER['HTTP_HOST'] ) ) ? $_SERVER['HTTP_HOST'] : '';

	return rtrim( $host, '/' ) . $_SERVER['REQUEST_URI'];
}

/**
 * Get URL path for caching
 *
 * @return string
 */
function sc_get_cache_path() {
	return rtrim( WP_CONTENT_DIR, '/' ) . '/cache/simple-cache';
}

/**
 * Optionally serve cache and exit
 *
 * @since 1.0
 */
function sc_serve_file_cache($do_logged_in=false) {

	if ( false === $do_logged_in && !empty( $GLOBALS['sc_cache_logged_in'] ) ) {
		return;
	}

	$cache_dir = ( defined( 'SC_CACHE_DIR' ) ) ? rtrim( SC_CACHE_DIR, '/' ) : rtrim( WP_CONTENT_DIR, '/' ) . '/cache/simple-cache';

	$file_name = 'index.';

	if ( ! empty( $GLOBALS['sc_config']['enable_gzip_compression'] ) && function_exists( 'gzencode' ) ) {
		$file_name = 'index.gzip.';
	}

	if ( $do_logged_in ) {// could be set to user ID 0 
		$file_name .= "{$do_logged_in}.";
	}

	$html_path   = $cache_dir . '/' . rtrim( sc_get_url_path(), '/' ) . '/' . $file_name . 'html';
	$json_path   = $cache_dir . '/' . rtrim( sc_get_url_path(), '/' ) . '/' . $file_name . 'json';
	$header_path = $cache_dir . '/' . rtrim( sc_get_url_path(), '/' ) . '/headers.json';

	if ( @file_exists( $html_path ) && @is_readable( $html_path ) ) {
		$path = $html_path;
	} elseif ( @file_exists( $json_path ) && @is_readable( $json_path ) ) {
		$path = $json_path;
		header( 'Content-Type: application/json; charset=UTF-8' );
	} else {
		return;// not cached
	}

	$modified_time = @filemtime( $path );

	if ( $modified_time && ! empty( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) && strtotime( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) === $modified_time ) {
		if ( function_exists( 'gzencode' ) && ! empty( $GLOBALS['sc_config']['enable_gzip_compression'] ) ) {
			header( 'Content-Encoding: gzip' );
		}

		header( $_SERVER['SERVER_PROTOCOL'] . ' 304 Not Modified', true, 304 );
		exit;
	}

	// Restore the headers if a `header.json` file is found.
	if ( @file_exists( $header_path ) && @is_readable( $header_path ) ) {
		$headers = json_decode( @file_get_contents( $header_path ) );
		foreach ( $headers as $header ) {
			header( $header );
		}
	} else {
		header( 'Cache-Control: max-age=' . DAY_IN_SECONDS );
		// header( 'Cache-Control: no-cache' );

		if ( $modified_time ) {
			header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $modified_time ) . ' GMT' );
		}
	}

	// Set the GZIP header if we are serving gzipped content.
	if ( function_exists( 'gzencode' ) && ! empty( $GLOBALS['sc_config']['enable_gzip_compression'] ) ) {
		header( 'Content-Encoding: gzip' );
	}

	header( 'X-Simple-Cache: HIT' );

	// if ( ! $do_logged_in ) {// could be set to user ID 0 
		@readfile( $path );
		exit;
	// }

	// Add Admin Bar
	// ob_start();
	// wp_admin_bar_render();
	// $admin_bar = ob_get_clean();
	// if ( ! $admin_bar ) {
	// 	@readfile( $path );
	// 	exit;
	// }
	// $content = @file_get_contents( $path );
	// str_replace( '<!-- sc-cache--wp_admin_bar_placeholder -->', $admin_bar, $content );
	// print $content;
	// wp_admin_bar_render();
	// exit;

}

/**
 * Get cache directory
 *
 * @since  1.7
 * @return string
 */
function sc_get_cache_dir() {
	return ( defined( 'SC_CACHE_DIR' ) ) ? rtrim( SC_CACHE_DIR, '/' ) : rtrim( WP_CONTENT_DIR, '/' ) . '/cache/simple-cache';
}

/**
 * Get config directory
 *
 * @since 1.7
 * @return string
 */
function sc_get_config_dir() {
	return ( defined( 'SC_CONFIG_DIR' ) ) ? rtrim( SC_CONFIG_DIR, '/' ) : rtrim( WP_CONTENT_DIR, '/' ) . '/sc-config';
	// 	return ( defined( 'SC_CONFIG_DIR' ) ? SC_CONFIG_DIR : WP_CONTENT_DIR );
}

/**
 * Gets name of the config file.
 *
 * @since  1.7
 * @return string
 */
function sc_get_config_file_name() {
	return 'config-' . $_SERVER['HTTP_HOST'] . '.php';
}

/**
 * Load config. Use network if it exists. Only intended to be used pre-wp.
 *
 * @since  1.7
 * @return bool|array
 */
function sc_load_config() {
	if ( @file_exists( sc_get_config_dir() . '/config-network.php' ) ) {
		return include sc_get_config_dir() . '/config-network.php';
	} elseif ( @file_exists( sc_get_config_dir() . '/' . sc_get_config_file_name() ) ) {
		return include sc_get_config_dir() . '/' . sc_get_config_file_name();
	}

	return false;
}

/**
 * Return true of exception url matches current url
 *
 * @param  string $rule Exceptions to check URL against.
 * @param  bool   $regex Whether to check with regex or not.
 * @since  1.6
 * @return boolean
 */
function sc_url_exception_match( $rule, $regex = false ) {

	$rule = trim( $rule );

	if ( ! $rule ) return false;

	$path = $_SERVER['REQUEST_URI'];

	if ( $regex ) {
		return (bool) preg_match( '<' . $rule . '>', $path );
	}
	
	$begins_with = ( '*' === substr( $rule, -1 ) );
	
	$rule = strtolower( trim( $rule, '*/' ) );
	$path = strtolower( trim( $path, '/' ) );

	if ( $begins_with ) {
		return ( 0 === stripos( $path, $rule ) );
	} else {
		return ( $rule === $path );
	}

}