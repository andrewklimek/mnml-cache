<?php
/**
 * Utility functions for plugin
 */


// add_action('shutdown', function(){ error_log( "shutdown connection_status: " . connection_status());});

// add_action( 'wp_footer', 'mc_flag_page_done', 99999999 );
// function mc_flag_page_done() {
	// defined( 'SC_FLAG_PAGE_DONE' ) || define( 'SC_FLAG_PAGE_DONE', TRUE );
	// error_log('flag page finished loading ' . $_SERVER['REQUEST_URI']);
// }

if ( !empty( $GLOBALS['mc_cache_logged_in'] ) ) {
// 	add_filter( 'show_admin_bar', '__return_false' );
// 	add_action( 'wp_footer', 'mc_wp_admin_bar_placeholder', 1000 );
	add_action( 'template_redirect', 'mc_load_logged_in_cache', 1 );// admin bar gets initiated on 0
}

function mc_wp_admin_bar_placeholder() {
	echo "<!-- mnml-cache--wp_admin_bar_placeholder -->";
}

function mc_load_logged_in_cache(){
	// add_filter( 'show_admin_bar', '__return_true', 11 );
	mc_serve_file_cache( get_current_user_id() );
}

/**
 * Clear the cache
 */
function mc_cache_flush() {

	mc_rrmdir( SC_CACHE_DIR );

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

