<?php
/**
 * Holds functions that can be loaded in advanced-cache.php
 */
namespace mnmlcache;

defined( 'ABSPATH' ) || exit;

defined( 'MC_CACHE_DIR' ) || define( 'MC_CACHE_DIR', WP_CONTENT_DIR . '/cache/mnml-cache' );

function mnmlcache_debug( $content ) {
	if (empty($GLOBALS['mnmlcache_config']['enable_debugging'])) return;
	if ( !is_string ( $content ) ) $content = var_export( $content, true );
	error_log( $content . "\n", 3, MC_CACHE_DIR . '/debug.log' );
}


function main(){
	// https://github.com/Automattic/wp-super-cache/blob/88fc6d2b2b3800a34b42230b6b6796a2e6a9d95d/wp-cache-phase2.php#L1575

	// Don't cache non-GET requests.
	if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'GET' !== $_SERVER['REQUEST_METHOD'] ) {
		return;
	}

	$config = $GLOBALS['mnmlcache_config'];

	$requestUri = $_SERVER['REQUEST_URI'] ?? '';
	$path = parse_url($requestUri, PHP_URL_PATH) ?: ''; // Extract path, discard query string
    // $path = basename($requestUri);
	// Early exit for any file with an extension
    if (strpos($path, '.') !== false) {
		mnmlcache_debug('skip, file detected based on dot: ' . $path);
        return;// Covers robots.txt, favicon.ico, wp-login.php, xmlrpc.php, wp-cron.php, .xml, bull that bots are probing for
    }

	// https://github.com/Automattic/wp-super-cache/blob/88fc6d2b2b3800a34b42230b6b6796a2e6a9d95d/wp-cache-phase2.php#L790
	if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
		mnmlcache_debug('skipping: doing cron');
		return;
	}
	if ( PHP_SAPI == 'cli' || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
		mnmlcache_debug('skipping: wp-cli');
		return;
	}

	// mnmlcache_debug('mnmlcache_main - ' . $_SERVER['REQUEST_URI'] );
	// mnmlcache_debug(headers_list());

		// customizer, apparantly.  Don't we check this before saving buffer with "is_preview" ?
	if ( isset( $_GET['customize_changeset_uuid'] ) ) {
		mnmlcache_debug('skipping: customizer');
		return;
	}

	// Don't cache if logged in.
	// TODO: Why is theirs so complex, and 2 other cookies? https://github.com/Automattic/wp-super-cache/blob/88fc6d2b2b3800a34b42230b6b6796a2e6a9d95d/wp-cache-phase2.php#L445
	// logged in cookie is named 'wordpress_logged_in_{hash}' and value is: username|expiration|token|hmac
	// https://github.com/WordPress/WordPress/blob/5bcb08099305b4572d2aeb97548d6a514291cc33/wp-includes/default-constants.php#L275
	// https://github.com/WordPress/WordPress/blob/bc0c01b1ac747606882dad4a899a84f288ef6bde/wp-includes/user.php#L543
	// https://github.com/WordPress/WordPress/blob/2602d7802145ef0627d84b60ea0fdd2762a33864/wp-includes/pluggable.php#L693
	// Login cookie can be expired (the timestamp is the 2nd element if you explode on '|') but it's dangerous to cache when expired because:
	// a) two wordpress_logged_in_ cookies can be present, https and http, and one can have a past expiration time
	// b) there maybe be is not grace period and we'd cache a page with the admin bar
	if ( ! empty( $_COOKIE ) ) {
		foreach (array_keys($_COOKIE) as $key) {
			if ( strpos( $key, 'wordpress_logged_in_' ) === 0 ) {
				// mnmlcache_debug('logged in');
				return;
			}
		}
	}


	// Don't cache disallowed extensions. Prevents wp-cron.php, xmlrpc.php, etc. @TODO
	if ( strpos( $_SERVER['REQUEST_URI'], '.' ) && strpos( $_SERVER['REQUEST_URI'], 'index.php' ) === false ) {
		$file_extension = array_reverse( explode('.', explode('?', $_SERVER['REQUEST_URI'] )[0] ) )[0];
		if ( in_array( $file_extension, [ 'php', 'xml', 'xsl' ] ) ) {
			mnmlcache_debug( "skipping due to extension: " . $_SERVER['REQUEST_URI'] );
			return;
		}
	}

	// exceptions
	// people may want to add /cart/ or /checkout/ but those two are also handled in file_cache() when the woo conditionals are available
	if ( ! empty( $config['cache_only_urls'] ) ) {
		$exceptions = explode( "\n", $config['cache_only_urls'] );

		$regex = ! empty( $config['enable_url_exemption_regex'] );
		$matched = false;
		foreach ( $exceptions as $exception ) {
			if ( url_exception_match( $exception, $regex ) ) {
				$matched = true;
				break;
			}
		}
		if ( ! $matched ) {
			// mnmlcache_debug("skipping because not in the 'only cache' setting: " . $_SERVER['REQUEST_URI'] );
			return;
		}
	}
	elseif ( ! empty( $config['cache_exception_urls'] ) ) {
		$exceptions = explode( "\n", $config['cache_exception_urls'] );

		$regex = ! empty( $config['enable_url_exemption_regex'] );

		foreach ( $exceptions as $exception ) {
			if ( url_exception_match( $exception, $regex ) ) {
				mnmlcache_debug("skipping exception $exception");
				return;
			}
		}
	}

	serve();
	// mnmlcache_debug('ob_start');

	// OK we didn't have the file cached, time to load the other functions and let WP generate the page.
	// This is too soon to remove these actions... TODO
	// remove_action( 'template_redirect', 'rest_output_link_header', 11 );
	// remove_action( 'template_redirect', 'wp_shortlink_header', 11 );

	require_once __DIR__ . '/output.php';
	ob_start( __NAMESPACE__ . '\output_handler' );
	add_action('send_headers', function(){
		$ob = ob_get_contents();
		if ( '' !== $ob ) {
			mnmlcache_debug('!!! buffer was NOT empty at send_headers on '. $_SERVER['REQUEST_URI'] .'.  Buffer: ' . var_export($ob,1) );
		}
	});
}

/**
 * Optionally serve cache and exit
 */
function serve() {

	$config = $GLOBALS['mnmlcache_config'];

	$path = get_url_path();
	$meta_path = $path . '.json';

	$using_gzip = !empty( $config['enable_gzip_compression'] ) && function_exists('gzencode');
	if ( $using_gzip ) {
		$reg_path = $path;
		$path .= '.gzip';
		$using_gzip = true;
		if ( ! @file_exists( $path ) || ! @is_readable( $path ) ) {
			$path = $reg_path;// try the unzipped version because small files arent zipped
			$using_gzip = false;
			if ( ! @file_exists( $path ) || ! @is_readable( $path ) ) {
				return;
			}
		}
	} elseif ( ! @file_exists( $path ) || ! @is_readable( $path ) ) {
        return;// Not cached
    }

	$modified_time = @filemtime( $path );

	if ( $modified_time && ! empty( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) && strtotime( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) === $modified_time ) {
		if ( $using_gzip ) {
			header( 'Content-Encoding: gzip' );
		}
		http_response_code( 304 );
		header( 'Cache-Control: public, stale-if-error=86400, max-age=' . HOUR_IN_SECONDS );// Might not need this... does a 304 reset expires timer?
		header( 'X-Mnml-Cache: HIT' );
		exit;
	}

	$meta = [];
	if ( ! empty( $config['restore_headers'] ) ) {
		if ( @file_exists( $meta_path ) && @is_readable( $meta_path ) ) {
			$meta = json_decode( @file_get_contents( $meta_path ) );
		}
	}
	if ( $meta ) {
		$cache_control = false;
		foreach ( $meta as $header ) {
			header( $header );
			if (stripos($header, 'Cache-Control:') === 0) $cache_control = true;
		}
		if ( ! $cache_control ) header( 'Cache-Control: public, stale-if-error=86400, max-age=' . HOUR_IN_SECONDS );// Do we store this?
	} else {
		header( 'Cache-Control: public, stale-if-error=86400, max-age=' . HOUR_IN_SECONDS );
	}

	if ( $modified_time ) {
		header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $modified_time ) . ' GMT' );
	}

	// Set the GZIP header if we are serving gzipped content.
	if ( $using_gzip ) {
		header( 'Content-Encoding: gzip' );
	}

	header( 'X-Mnml-Cache: HIT' );

	@readfile( $path );
	exit;
}


/**
 * Get URL path for caching
 *
 * @return string
 */
function get_url_path($url = '', $skip_file_name = false) {
    $config = $GLOBALS['mnmlcache_config'];
    $url = $url ?: $_SERVER['REQUEST_URI'];
    $parsed = parse_url($url);
    $path = isset($parsed['path']) ? trim($parsed['path'], '/') : '';
	if ( ! $path ) {
		$page = '_home';
		$shard = '_home';
		$url_dir = '_root';
	} else {
		$path = mnmlcache_sanitize_path( $path );
		$segments = explode('/', $path);
		$page = array_pop($segments);
		$url_dir = $segments ? implode('/', $segments) : '_root';
		if ($config['storage_scheme'] === 'md5') {
			$page = md5($page); // Use full MD5 for page name
			$shard = substr($page, 0, 1); // Shard is first character of MD5
		} else {
			$shard = strlen($page) >= 2 && $config['storage_scheme'] === '2-letter' ? substr($page, 0, 2) : substr($page, 0, 1);
			if (strlen($page) > 50) {
				$page = substr( $page, 0, 16 ) . substr( md5( $page ), 0, 16 ); // Truncate long slugs
			}
		}
	}
	if ( $skip_file_name ) {
	    return MC_CACHE_DIR . "/$url_dir/$shard/$page";	
	}
    $file_name = 'index'; // Default to 'index' for no query strings
    if (isset($parsed['query'])) {
        $params = [];
        parse_str($parsed['query'], $params);
        ksort($params);
        $blacklist = !empty($config['param_blacklist']) ? array_map('trim', explode(',', $config['param_blacklist'])) : ['utm', 'fbclid', 'gclid', '_ga'];
        $filtered_params = array_diff_key($params, array_flip($blacklist));
        if ($filtered_params) {
            $file_name = md5( http_build_query($filtered_params) ); // Hash query params
        }
    }
    return MC_CACHE_DIR . "/$url_dir/$shard/$page/$file_name";
}

/**
 * This sanitizes URLs to be safe directory paths
 * It's largely un-neccessary as Wordpress already sanitizes slugs, and if the requested URL is wrong, it will just be a 404 and bypassed anyway
 * This is mainly helful for custom setups... and I think it's just good practice (WP Super Cache has a very heavy sanitization function)
 * Made to be fairly similar to this code run by sanitize_title() on slugs:
 * https://github.com/WordPress/wordpress-develop/blob/d56d51dd5b25b599050a7e5ef317875f7b2254a4/src/wp-includes/formatting.php#L2261
 */
function mnmlcache_sanitize_path( $path ) {
    if (!$path) return '';
	$original = $path;
	$path = rawurldecode($path);
	if ( $path !== $original ) {
		mnmlcache_debug("FORMATTING: path had % encoded characters: $original");
		$original = $path;
	}
	$path = str_replace('//', '/', $path);// preg_replace('/\/+/', '/', $path)
	if ( $path !== $original ) {
		mnmlcache_debug("FORMATTING: path had double slashes: $original");
		$original = $path;
	}
	$path = strtolower( $path );
	if ( $path !== $original ) {
		mnmlcache_debug("FORMATTING: path had upper case: $original");
		$original = $path;
	}
	// $path = str_replace( '.', '-', $path );
	// $path = preg_replace( '|\s+|', '-', $path );
	$path = preg_replace( '|[^a-z0-9_/]|', '-', $path );
	$path = preg_replace( '|-+|', '-', trim( $path, '-' ) );
	
    return $path ?: 'unnamed';
}

/**
 * Return true of exception url matches current url
 *
 * @param  string $rule Exceptions to check URL against.
 * @param  bool   $regex Whether to check with regex or not.
 * @return boolean
 */
function url_exception_match( $rule, $regex = false ) {

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

