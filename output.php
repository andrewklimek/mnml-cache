<?php
/**
 * This caches the output buffer and is technically the only additional thing that needs to be loaded for logged out users
 */
namespace mnmlcache;

// add_action('shutdown', function(){ mnmlcache_debug( "shutdown connection_status: " . connection_status());});

// add_action( 'wp_footer', 'mc_flag_page_done', 99999999 );
// function mc_flag_page_done() {
	// defined( 'MC_FLAG_PAGE_DONE' ) || define( 'MC_FLAG_PAGE_DONE', TRUE );
	// mnmlcache_debug('flag page finished loading ' . $_SERVER['REQUEST_URI']);
// }

/**
 * Cache output before it goes to the browser
 *
 * @param  string $buffer Page HTML.
 * @param  int    $flags OB flags to be passed through.
 * @return string
 */
function output_handler( $buffer, $flags ) {

	// mnmlcache_debug( __FUNCTION__ . ' - ' . $_SERVER['REQUEST_URI'] );

	$nocache_header = false;
	$headers = headers_list();
	// mnmlcache_debug($headers);
    foreach ($headers as $header) {
        if (stripos($header, 'Cache-Control:') === 0) {
            $cacheControl = trim(substr($header, strlen('Cache-Control:')));
            $directives = array_map('trim', explode(',', $cacheControl));
			if (
                in_array('no-cache', $directives, true) ||
                in_array('no-store', $directives, true) ||
                in_array('private', $directives, true) ||
                in_array('max-age=0', $directives, true) ||
                in_array('must-revalidate', $directives, true) ||
                in_array('proxy-revalidate', $directives, true) ||
                in_array('s-maxage=0', $directives, true)
            ) {	
				mnmlcache_debug("header had no-store or private");
				$nocache_header = true;
			}
        }
    }

	// https://github.com/Automattic/wp-super-cache/blob/88fc6d2b2b3800a34b42230b6b6796a2e6a9d95d/wp-cache-phase2.php#L2069

	if ( ! did_filter('wp_headers') ) {
		mnmlcache_debug("wp_headers didn't run so maybe this shouldn't be cached!?");
		// return $buffer;
	}

	// why do they have these 2 hooks to "catch" the code? https://github.com/Automattic/wp-super-cache/blob/88fc6d2b2b3800a34b42230b6b6796a2e6a9d95d/wp-cache-phase2.php#L1566
	if ( http_response_code() !== 200 ) {
		mnmlcache_debug("Non-200 HTTP response code: " . http_response_code());
		return $buffer;
	}

	$error = error_get_last();
	if ( $error && ( $error['type'] & ( E_ERROR | E_CORE_ERROR | E_PARSE | E_COMPILE_ERROR | E_USER_ERROR ) ) ) {
		mnmlcache_debug("Fatal error detected: " . json_encode($error));
		return $buffer;
	}

	if ( defined( 'MNML_DONTCACHE' ) && MNML_DONTCACHE ) {
		mnmlcache_debug("MNML_DONTCACHE is defined and true");
        return $buffer;
    }

	// safety to really not cache logged in pages incase the pre-wp check is somehow faulty
	if ( is_user_logged_in() ) {
		mnmlcache_debug("!!! WOULD HAVE CACHED A LOGGED IN USER !!!");
		return $buffer;
	}

	if ( isset( $_GET['preview'] ) ) {
		mnmlcache_debug("Preview mode detected");
		return $buffer;
	}
	
	// if we aren't saving 404... might be worth dropping them immediately so they don't always build fancy 404 pages
	// Don't cache search, 404, or password protected... TODO arent these handled before somewhere?
	if ( is_404() ) {
		mnmlcache_debug("Page is 404");
		return $buffer;
	}
	
	// search pages have no cache-control headers... but maybe we should set them?  Fine for CDNs to cache imo but probably no one wants to store them on disk
	if ( is_search() ) {
		mnmlcache_debug("Page is search");
		header( 'Cache-Control: max-age=900' );// 15 minutes for CDNs and private cache.  Perhaps this should be set earlier to allow templates to override.
		return $buffer;
	}
	
	global $post;
	if ( ! empty( $post->post_password ) && is_singular() ) {// this can technically trip up if its an index page and last post was password protected... why doesnt wp super cache check this?
		mnmlcache_debug("Page is password protected");
		return $buffer;
	}

	if (function_exists('is_woocommerce') && (is_cart() || is_checkout())) {// there's also is_account_page() but you'd have to be logged in no?
		mnmlcache_debug('Bypassing WooCommerce cart or checkout');
		header( 'X-Mnml-Cache: BYPASSING WOO!' );
		return $buffer;
	}

	// exclude authenticaed api calls but might not always be JSON
	if ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
		mnmlcache_debug("Authenticated API call detected");
		return $buffer;
	}

	// Do not cache the REST API if the user has not opted-in or it's an authenticated REST API request.
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST && empty( $GLOBALS['mnmlcache_config']['enable_json_cache'] ) ) {
		mnmlcache_debug("REST API request without JSON cache enabled");
		return $buffer;
	}

	if ( $nocache_header ) {
		return $buffer;
	}

	// one loop through to both prepare headers & do one more JSON check (custom JSON responses that don't use WP REST API)
	$headers = [];
	header_remove('X-Powered-By');
	foreach ( headers_list() as $header ) {
		if ( empty( $GLOBALS['mnmlcache_config']['enable_json_cache'] ) && stripos( $header, 'Content-Type: application/json' ) === 0 ) {
			mnmlcache_debug("JSON content type detected without JSON cache enabled");
			return $buffer;
		}
		// if ( substr( $header, 0, 2 ) === 'X-' ) continue;
		$headers[] = $header;
	}
	// mnmlcache_debug('file_cache ' . var_export($headers,1));

	// Make sure we can read/write files to cache dir parent
	if ( ! file_exists( dirname( MC_CACHE_DIR ) ) && ! @mkdir( dirname( MC_CACHE_DIR ) ) ) {
		mnmlcache_debug("Cannot create or access parent cache directory: " . dirname(MC_CACHE_DIR));
		return $buffer;
	}

	// Make sure we can read/write files to cache dir
	if ( ! file_exists( MC_CACHE_DIR ) && ! @mkdir( MC_CACHE_DIR ) ) {
		mnmlcache_debug("Cannot create or access cache directory: " . MC_CACHE_DIR);
		return $buffer;
	}

	$path = get_url_path();
    $dir_path = dirname($path);
	mnmlcache_debug( $path );
    if (!file_exists($dir_path) && !mkdir($dir_path, 0755, true)) {
        mnmlcache_debug("Cannot create directory in cache path: $dir_path");
        return $buffer;
    }

	// Save the response body.
	if ( ! empty( $GLOBALS['mnmlcache_config']['enable_gzip_compression'] ) && function_exists( 'gzencode' ) && strlen( $buffer ) > 999 ) {
		file_put_contents( $path . '.gzip', gzencode( $buffer, 3 ) );
	} else {
		file_put_contents( $path, $buffer );
	}

	if ( !empty( $GLOBALS['mnmlcache_config']['enable_debugging'] ) ) {
		file_put_contents( MC_CACHE_DIR . "/urls.tsv", "{$_SERVER['REQUEST_URI']}\t" . substr( $path, strlen( MC_CACHE_DIR ) ) . "\n", FILE_APPEND | LOCK_EX );
	}

	if ( ! empty( $GLOBALS['mnmlcache_config']['restore_headers'] ) ) {
		file_put_contents( $path . '.json', json_encode( $headers, JSON_UNESCAPED_SLASHES ) );
	}

	header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s' ) . ' GMT' );

	header( 'Cache-Control: public, stale-if-error=86400, max-age=' . HOUR_IN_SECONDS );// TODO this might want to be before saving to store the header and avoid having to re-check it at serve?

	// header( 'X-Mnml-Cache: MISS' );// this ends up shoing on CDN results if they are the first to serve the file.  Better to just have HIT vs nothing

	if ( function_exists( 'ob_gzhandler' ) && ! empty( $GLOBALS['mnmlcache_config']['enable_gzip_compression'] ) ) {
		return ob_gzhandler( $buffer, $flags );
	} else {
		return $buffer;
	}
}
