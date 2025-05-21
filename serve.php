<?php
/**
 * Holds functions that can be loaded in advanced-cache.php
 */
defined( 'ABSPATH' ) || exit;

defined( 'MC_CACHE_DIR' ) || define( 'MC_CACHE_DIR', WP_CONTENT_DIR . '/cache/mnml-cache' );

function mnmlcache_debug( $content ) {
	if ( !is_string ( $content ) ) $content = var_export( $content, true );
	error_log( $content . "\n", 3, __DIR__ . '/_debug.log' );
}


function mnmlcache_main(){
	// https://github.com/Automattic/wp-super-cache/blob/88fc6d2b2b3800a34b42230b6b6796a2e6a9d95d/wp-cache-phase2.php#L1575

	// Don't cache non-GET requests.
	if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'GET' !== $_SERVER['REQUEST_METHOD'] ) {
		return;
	}

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
				mnmlcache_debug('logged in');
				if ( !empty( $GLOBALS['mc_config']['private_cache'] ) ) {
					// set cache control
					// $nonce_life = apply_filters('nonce_life', DAY_IN_SECONDS);// maybe show this value in settings UI and let them know what to do if its short.
					// $max_age = min($GLOBALS['mc_config']['browser_cache_max_age'] ?? 3600, $nonce_life / 2);
					$max_age = $GLOBALS['mc_config']['browser_cache_max_age'] ?? 3600;
					// header("Cache-Control: private, max-age=$max_age");
					// header('Vary: Cookie');
					// add_filter('nocache_headers', function() use ( $max_age ){// TODO this might want some conditional tags. check in what places nocache_headers is used
					// 	return [
					// 		'Cache-Control' => "private, max-age=$max_age",
					// 		'Vary' => 'Cookie',
					// 	];
					// });
					add_filter( 'wp_headers', function( $headers, $wp ) use ( $max_age ) {
						
						mnmlcache_debug("did_filter(nocache_headers): " . did_filter('nocache_headers') );// returns 2 for password protected, 1 for anythign WP added these to
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
						mnmlcache_debug($headers);

						return $headers;
					}, 2, 100 );
					add_action('shutdown', function(){
						mnmlcache_debug('headers at shutdown: ' . var_export(headers_list(),1));
						mnmlcache_debug("did_filter(nocache_headers): " . did_filter('nocache_headers') );// returns 2 for password protected, 1 for anythign WP added these to
					});
				}
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
	// people may want to add /cart/ or /checkout/ but those two are also handled in mc_file_cache() when the woo conditionals are available
	if ( ! empty( $GLOBALS['mc_config']['cache_only_urls'] ) ) {
		$exceptions = explode( "\n", $GLOBALS['mc_config']['cache_only_urls'] );

		$regex = ! empty( $GLOBALS['mc_config']['enable_url_exemption_regex'] );
		$matched = false;
		foreach ( $exceptions as $exception ) {
			if ( mc_url_exception_match( $exception, $regex ) ) {
				$matched = true;
				break;
			}
		}
		if ( ! $matched ) {
			// mnmlcache_debug("skipping because not in the 'only cache' setting: " . $_SERVER['REQUEST_URI'] );
			return;
		}
	}
	elseif ( ! empty( $GLOBALS['mc_config']['cache_exception_urls'] ) ) {
		$exceptions = explode( "\n", $GLOBALS['mc_config']['cache_exception_urls'] );

		$regex = ! empty( $GLOBALS['mc_config']['enable_url_exemption_regex'] );

		foreach ( $exceptions as $exception ) {
			if ( mc_url_exception_match( $exception, $regex ) ) {
				mnmlcache_debug("skipping exception $exception");
				return;
			}
		}
	}

	mc_serve_file_cache();
	// mnmlcache_debug('ob_start');

	// OK we didn't have the file cached, time to load the other functions and let WP generate the page.
	// This is too soon to remove these actions... TODO
	// remove_action( 'template_redirect', 'rest_output_link_header', 11 );
	// remove_action( 'template_redirect', 'wp_shortlink_header', 11 );

	require_once __DIR__ . '/functions.php';
	ob_start( 'mc_file_cache' );
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
function mc_serve_file_cache() {

	$path = mc_get_url_path();
	$meta_path = $path . '.json';

	$using_gzip = !empty( $GLOBALS['mc_config']['enable_gzip_compression'] ) && function_exists('gzencode');
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
		// header( $_SERVER['SERVER_PROTOCOL'] . ' 304 Not Modified', true, 304 );
		http_response_code( 304 );
		header( 'Cache-Control: max-age=' . HOUR_IN_SECONDS );// Why?
		header( 'X-Mnml-Cache: HIT' );
		exit;
	}

	$meta = [];
	if ( ! empty( $GLOBALS['mc_config']['restore_headers'] ) ) {
		if ( @file_exists( $meta_path ) && @is_readable( $meta_path ) ) {
			$meta = json_decode( @file_get_contents( $meta_path ) );
		}
	}
	if ( $meta ) {
		foreach ( $meta as $header ) {
			header( $header );
		}
		header( 'Cache-Control: max-age=' . HOUR_IN_SECONDS );// Why?
	} else {
		header( 'Cache-Control: max-age=' . HOUR_IN_SECONDS );// Why?
		// header( 'Cache-Control: no-cache' );
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
	return MC_CACHE_DIR . "/$url_dir/$shard/$file_name";
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

