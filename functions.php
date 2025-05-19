<?php
/**
 * Utility functions for plugin
 */


// add_action('shutdown', function(){ error_log( "shutdown connection_status: " . connection_status());});

// add_action( 'wp_footer', 'mc_flag_page_done', 99999999 );
// function mc_flag_page_done() {
	// defined( 'MC_FLAG_PAGE_DONE' ) || define( 'MC_FLAG_PAGE_DONE', TRUE );
	// error_log('flag page finished loading ' . $_SERVER['REQUEST_URI']);
// }

/**
 * Cache output before it goes to the browser
 *
 * @param  string $buffer Page HTML.
 * @param  int    $flags OB flags to be passed through.
 * @return string
 */
function mc_file_cache( $buffer, $flags ) {

	// https://github.com/Automattic/wp-super-cache/blob/88fc6d2b2b3800a34b42230b6b6796a2e6a9d95d/wp-cache-phase2.php#L2069

	// if ( ! defined( 'MC_FLAG_PAGE_DONE' ) || ! MC_FLAG_PAGE_DONE ) {
	// 	error_log('page didnt finish loading ' . $_SERVER['REQUEST_URI']);
	// 	return $buffer;
	// }

	// why do they have these 2 hooks to "catch" the code? https://github.com/Automattic/wp-super-cache/blob/88fc6d2b2b3800a34b42230b6b6796a2e6a9d95d/wp-cache-phase2.php#L1566
	if ( http_response_code() !== 200 ) {
		return $buffer;
	}

	$error = error_get_last();
	if ( $error && ( $error['type'] & ( E_ERROR | E_CORE_ERROR | E_PARSE | E_COMPILE_ERROR | E_USER_ERROR ) ) ) {
		return $buffer;
	}

	if ( defined( 'MNML_DONTCACHE' ) && MNML_DONTCACHE ) {
        return $buffer;
    }

	// error_log( "buffer connection_status: " . connection_status());

	// safety to really not cache logged in pages incase the pre-wp check is somehow faulty
	if ( is_user_logged_in() ) {
		error_log("!!! WOULD HAVE CACHED A LOGGED IN USER !!!");
		return $buffer;
	}

	if ( isset( $_GET['preview'] ) ) {
		return $buffer;
	}
	
	// if we aren't saving 404... might be worth dropping them immediately so they don't always build fancy 404 pages
	// Don't cache search, 404, or password protected... TODO arent these handled before somewhere?
	global $post;
	if ( is_404() || is_search() || ! empty( $post->post_password ) ) {
		return $buffer;
	}

	if (function_exists('is_woocommerce') && (is_cart() || is_checkout())) {
		// error_log('is_cart');
		header( 'X-Mnml-Cache: BYPASSING WOO!' );
		// header('Cache-Control: no-cache, no-store, must-revalidate');
		return $buffer;
	}

	// exclude authenticaed api calls but might not always be JSON
	if ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
		return $buffer;
	}

	// Do not cache the REST API if the user has not opted-in or it's an authenticated REST API request.
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST && empty( $GLOBALS['mc_config']['enable_json_cache'] ) ) {
		return $buffer;
	}

	// one loop through to both prepare headers & do one more JSON check (custom JSON responses that don't use WP REST API)
	$headers = [];
	header_remove('X-Powered-By');
	foreach ( headers_list() as $header ) {
		if ( empty( $GLOBALS['mc_config']['enable_json_cache'] ) && stripos( $header, 'Content-Type: application/json' ) === 0 ) {
			return $buffer;
		}
		// if ( substr( $header, 0, 2 ) === 'X-' ) continue;
		$headers[] = $header;
	}
	error_log('mc_file_cache ' . var_export($headers,1));


	// Make sure we can read/write files to cache dir parent
	if ( ! file_exists( dirname( MC_CACHE_DIR ) ) && ! @mkdir( dirname( MC_CACHE_DIR ) ) ) {
		return $buffer;
	}

	// Make sure we can read/write files to cache dir
	if ( ! file_exists( MC_CACHE_DIR ) && ! @mkdir( MC_CACHE_DIR ) ) {
		return $buffer;
	}

	$path = mc_get_url_path();
	$dirs = explode( '/', $path );
	$file_name = array_pop( $dirs );
	$dir_path = array_shift( $dirs );

	foreach ( $dirs as $dir ) {
		$dir_path .= '/' . $dir;
		if ( ! file_exists( $dir_path ) && ! @mkdir( $dir_path ) ) {
			return $buffer;
		}
	}

	// Save the response body.
	if ( ! empty( $GLOBALS['mc_config']['enable_gzip_compression'] ) && function_exists( 'gzencode' ) && strlen( $buffer ) > 999 ) {
		file_put_contents( $path . '.gzip', gzencode( $buffer, 3 ) );
	} else {
		file_put_contents( $path, $buffer );
	}

	if ( ! empty( $GLOBALS['mc_config']['restore_headers'] ) ) {
		file_put_contents( $path . '.json', json_encode( $headers, JSON_UNESCAPED_SLASHES ) );
	}


	header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s' ) . ' GMT' );

	// TODO why either one of these?
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
 * Clear the cache
 */
function mc_cache_flush() {

	mc_rrmdir( MC_CACHE_DIR );

	if ( function_exists( 'wp_cache_flush' ) ) {
		wp_cache_flush();
	}

	mnmlcache_cloudflare_purge_all();
	
	// mc_crawl();// need to schedule and batch this
}

/**
 * Clear one page
 */
function mc_cache_purge($url) {

	$url_path = mc_get_url_path($url);

    $cache_file = $url_path;
    $gzip_file = $url_path . ".gzip";
    $header_file = $url_path . ".json";
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
	mnmlcache_cloudflare_purge_urls([$url]);
}

/**
 * Verify we can write to the file system
 *
 * @return array|boolean
 */
function mc_verify_file_access() {
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
	if ( file_exists( MC_CACHE_DIR ) ) {
		if ( ! @is_writable( MC_CACHE_DIR ) ) {
			$errors[] = 'cache';
		}
	} else {
		if ( file_exists( dirname( MC_CACHE_DIR ) ) ) {
			if ( ! @is_writable( dirname( MC_CACHE_DIR ) ) ) {
				$errors[] = 'cache';
			}
		} else {
			if ( file_exists( dirname( dirname( MC_CACHE_DIR ) ) ) ) {
				if ( ! @is_writable( dirname( dirname( MC_CACHE_DIR ) ) ) ) {
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
function mc_rrmdir($dir, $retain_root = true) {

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
			if (!mc_rrmdir($path, false)) {
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


/**
 * The Crawling Script
 */

function mc_crawl() {
	$base_url = rtrim( home_url(), '/' );
	$sitemap_urls = get_sitemap_urls_from_robots( $base_url );

	if (empty($sitemap_urls)) {
		// Fallback to default sitemap
		$sitemap_urls = [ "$base_url/wp-sitemap.xml", "$base_url/sitemap.xml", "$base_url/sitemaps.xml" ];
		error_log("No sitemap found in robots.txt, trying defaults.");
	}

	$all_urls = [];
	foreach ($sitemap_urls as $sitemap_url) {
		$urls = get_urls_from_sitemap($sitemap_url);
		$all_urls = array_merge($all_urls, $urls);
	}

	$all_urls = array_unique($all_urls);// Remove duplicates
	if (!empty($all_urls)) {
		error_log("Crawling " . count($all_urls) . " URLs...");
		crawl_urls($all_urls);
		error_log("Done!");
	} else {
		error_log("No URLs found in sitemap(s).");
	}
}

// Get sitemap URLs from robots.txt
function get_sitemap_urls_from_robots($base_url) {
    $robots_url = rtrim($base_url, '/') . '/robots.txt';
    $sitemap_urls = [];

    // Fetch robots.txt
    if (function_exists('wp_remote_get')) {
        $response = wp_remote_get($robots_url, ['timeout' => 10]);
        if (is_wp_error($response)) {
            error_log("Failed to fetch robots.txt: $robots_url - " . $response->get_error_message());
            return $sitemap_urls;
        }
        $body = wp_remote_retrieve_body($response);
    }
	
    // Parse robots.txt for Sitemap lines
    $lines = explode("\n", $body);
    foreach ($lines as $line) {
        $line = trim($line);
        if (stripos($line, 'Sitemap:') === 0) {
            $sitemap_url = trim(substr($line, strlen('Sitemap:')));
            if (filter_var($sitemap_url, FILTER_VALIDATE_URL)) {
                $sitemap_urls[] = $sitemap_url;
            }
        }
    }

    if (empty($sitemap_urls)) {
        error_log("No sitemap URLs found in robots.txt: $robots_url");
    } else {
		error_log("Got sitemaps from robots.txt: " . var_export( $sitemap_urls, 1 ) );
	}

    return $sitemap_urls;
}

// Parse sitemap XML to extract URLs
function get_urls_from_sitemap($sitemap_url) {
    $urls = [];

    // Fetch the sitemap
	$response = wp_remote_get($sitemap_url, ['timeout' => 10]);
	if (is_wp_error($response)) {
		error_log("Failed to fetch sitemap: $sitemap_url - " . $response->get_error_message());
		return $urls;
	}
	$body = wp_remote_retrieve_body($response);

    // Parse XML
    $xml = simplexml_load_string($body);
    if ($xml === false) {
        error_log("Failed to parse sitemap XML: $sitemap_url");
        return $urls;
    }

    // Check if it's a sitemap index
    if ($xml->getName() === 'sitemapindex') {
        foreach ($xml->sitemap as $sitemap) {
            $sub_sitemap_url = (string)$sitemap->loc;
            $urls = array_merge($urls, get_urls_from_sitemap($sub_sitemap_url));
        }
    } elseif ($xml->getName() === 'urlset') {
        foreach ($xml->url as $url) {
            $urls[] = (string)$url->loc;
        }
    }

    return $urls;
}

// Crawl URLs to populate cache
function crawl_urls($urls) {
    $headers = ['User-Agent' => 'Mozilla/5.0 (compatible; CacheWarmer/1.0)'];

    foreach ($urls as $index => $url) {
        try {
			$response = wp_remote_get($url, ['timeout' => 10, 'headers' => $headers, 'blocking' => false]);
			if (is_wp_error($response)) {
				error_log("Error fetching $url: " . $response->get_error_message());
				continue;
			}
			$status = wp_remote_retrieve_response_code($response);
            
            error_log("[{$index}/" . count($urls) . "] $url: $status");
            sleep(1); // Avoid overwhelming the server
        } catch (Exception $e) {
            error_log("Error fetching $url: " . $e->getMessage());
        }
    }
}

