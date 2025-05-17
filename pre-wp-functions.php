<?php
/**
 * Holds functions that can be loaded in advanced-cache.php
 *
 */
defined( 'SC_CACHE_DIR' ) || define( 'SC_CACHE_DIR', WP_CONTENT_DIR . '/cache/mnml-cache' );

/**
 * Cache output before it goes to the browser
 *
 * @param  string $buffer Page HTML.
 * @param  int    $flags OB flags to be passed through.
 * @return string
 */
function mc_file_cache( $buffer, $flags ) {

	// if ( ! defined( 'SC_FLAG_PAGE_DONE' ) || ! SC_FLAG_PAGE_DONE ) {
	// 	error_log('page didnt finish loading ' . $_SERVER['REQUEST_URI']);
	// 	return $buffer;
	// }

	if ( http_response_code() !== 200 ) {
		return $buffer;
	}

	$error = error_get_last();
	if ( $error && ( $error['type'] & ( E_ERROR | E_CORE_ERROR | E_PARSE | E_COMPILE_ERROR | E_USER_ERROR ) ) ) {
		return $buffer;
	}

	// error_log( "buffer connection_status: " . connection_status());

	// safety to really not cache logged in pages incase the pre-wp check is somehow faulty
	if ( empty( $GLOBALS['mc_config']['mc_cache_logged_in'] ) && is_user_logged_in() ) {
		error_log("!!! WOULD HAVE CACHED A LOGGED IN USER !!!");
		return $buffer;
	}

	global $post;

	// if we aren't saving 404... might be worth dropping them immediately so they don't always build fancy 404 pages
	// Don't cache search, 404, or password protected... TODO arent these handled before somewhere?
	if ( is_404() || is_search() || ! empty( $post->post_password ) ) {
		return $buffer;
	}

	// Do not cache the REST API if the user has not opted-in or it's an authenticated REST API request. TODO is this the best way to handle authenticated APPI calls?
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST && ( empty( $GLOBALS['mc_config']['page_cache_enable_rest_api_cache'] ) || ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) ) {
		return $buffer;
	}

	// Make sure we can read/write files to cache dir parent
	if ( ! file_exists( dirname( SC_CACHE_DIR ) ) ) {
		if ( ! @mkdir( dirname( SC_CACHE_DIR ) ) ) {
			// Can not cache!
			return $buffer;
		}
	}

	// Make sure we can read/write files to cache dir
	if ( ! file_exists( SC_CACHE_DIR ) ) {
		if ( ! @mkdir( SC_CACHE_DIR ) ) {
			// Can not cache!
			return $buffer;
		}
	}

	$url_path = mc_get_url_path();
	$dirs = explode( '/', $url_path );
	$file_name = array_pop( $dirs );
	$path = array_shift( $dirs );

	foreach ( $dirs as $dir ) {
		$path .= '/' . $dir;

		if ( ! file_exists( $path ) ) {
			if ( ! @mkdir( $path ) ) {
				// Can not cache!
				return $buffer;
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
		$buffer .= "\n<!-- cached by mnml cache at " . gmdate( 'd M Y H:i:s', $modified_time ) . " UTC -->";
	}

	if ( !empty( $GLOBALS['mc_cache_logged_in'] ) && $id = get_current_user_id() ) {
		$file_extension = ".{$id}{$file_extension}";
	}

	// Save the response body.
	if ( ! empty( $GLOBALS['mc_config']['enable_gzip_compression'] ) && function_exists( 'gzencode' ) ) {
		file_put_contents( $url_path . '.gzip' . $file_extension, gzencode( $buffer, 3 ) );
		touch( $url_path . '.gzip' . $file_extension, $modified_time );
	} else {
		file_put_contents( $url_path . $file_extension, $buffer );
		touch( $url_path . $file_extension, $modified_time );
	}

	header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $modified_time ) . ' GMT' );

	// Save the resonse headers.
	if ( ! empty( $GLOBALS['mc_config']['page_cache_restore_headers'] ) ) {
		file_put_contents( $url_path . '.headers.json', wp_json_encode( headers_list() ) );
	}

	// header( 'Cache-Control: no-cache' ); // Check back every time to see if re-download is necessary.
	header( 'Cache-Control: max-age=' . HOUR_IN_SECONDS );

	// header( 'X-Mnml-Cache: MISS' );// this ends up shoing on CDN results if they are the first to serve the file.  Better to just have HIT vs nothing


	if ( function_exists( 'ob_gzhandler' ) && ! empty( $GLOBALS['mc_config']['enable_gzip_compression'] ) ) {
		return ob_gzhandler( $buffer, $flags );
	} else {
		return $buffer;
	}
}

/**
 * Get URL path for caching
 *
 * @return string
 */
function mc_get_url_path( $url='' ) {

	// return $_SERVER['REQUEST_URI'];
	$url = $url ?: $_SERVER['REQUEST_URI'];
    $parsed = parse_url($url);
    $path = $file_name = isset($parsed['path']) ? trim($parsed['path'], '/') : '';
	$segments = explode('/', $path);
    $page = array_pop($segments);
	$url_dir = $segments ? implode('/', $segments) : '_root';
	// if (strlen($url_dir) > 100) // could truncate or limit depth
	if (isset($parsed['query'])) {
		$params = [];
        parse_str($parsed['query'], $params);
		ksort($params);
		// might need to handle wildcards like utm*
        // $whitelist = !empty($GLOBALS['mc_config']['param_whitelist']) ? array_map('trim', explode(',', $GLOBALS['mc_config']['param_whitelist'])) : [];
        // $filtered_params = array_intersect_key($params, array_flip($whitelist));
        $blacklist = !empty($GLOBALS['mc_config']['param_blacklist']) ? array_map('trim', explode(',', $GLOBALS['mc_config']['param_blacklist'])) : ['utm','fbclid','gclid','_ga'];
        $filtered_params = array_diff_key($params, array_flip($blacklist));
        if ($filtered_params) {
            $file_name .= '_' . http_build_query( $filtered_params );
        }
    }
	$file_name = md5( $file_name );// new
	$shard = $page === '' ? '_home' : substr( $file_name, 0, 1 );// put home page in special _root/_home/ dir
	// skip for md5
	// if ($shard === '') {
	// 	$shard = '_';
	// 	$file_name = 'index';
    // }
	// if ($url_dir === '') {
    //     $url_dir = '_root';
    // }
	// if ( isset($parsed['query']) ) {
	// 	$file_name .= '_' . preg_replace('/[^a-zA-Z0-9-]/', '_', $parsed['query'] );
	// }
	return SC_CACHE_DIR . "/$url_dir/$shard/$file_name";
}

/**
 * Optionally serve cache and exit
 */
function mc_serve_file_cache($do_logged_in=false) {

	if ( false === $do_logged_in && !empty( $GLOBALS['mc_cache_logged_in'] ) ) {
		return;
	}

	$file_name = '';

	if ( ! empty( $GLOBALS['mc_config']['enable_gzip_compression'] ) && function_exists( 'gzencode' ) ) {
		$file_name = '.gzip';
	}

	if ( $do_logged_in ) {// could be set to user ID 0 
		$file_name .= "{$do_logged_in}.";
	}

	$url_path = mc_get_url_path();
	$html_path   = $url_path . $file_name . '.html';
	$json_path   = $url_path . $file_name . '.json';
	$header_path = $url_path . $file_name . '.headers.json';

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
		if ( function_exists( 'gzencode' ) && ! empty( $GLOBALS['mc_config']['enable_gzip_compression'] ) ) {
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
		header( 'Cache-Control: max-age=' . HOUR_IN_SECONDS );
		// header( 'Cache-Control: no-cache' );

		if ( $modified_time ) {
			header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $modified_time ) . ' GMT' );
		}
	}

	// Set the GZIP header if we are serving gzipped content.
	if ( function_exists( 'gzencode' ) && ! empty( $GLOBALS['mc_config']['enable_gzip_compression'] ) ) {
		header( 'Content-Encoding: gzip' );
	}

	header( 'X-Mnml-Cache: HIT' );

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
 * Return true of exception url matches current url
 *
 * @param  string $rule Exceptions to check URL against.
 * @param  bool   $regex Whether to check with regex or not.
 * @return boolean
 */
function mc_url_exception_match( $rule, $regex = false ) {

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