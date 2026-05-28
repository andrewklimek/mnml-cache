<?php
/**
 * Cloudflare Integration
 * 
 * Enable HTML caching on Cloudflare by making a Cache Rule:
 * If incoming requests match… (not http.cookie contains "wordpress_logged_in_")
 * Then... Eligible for cache
 * This is so you won't be served public versions of the page when you're logged in (missing admin bar, etc)
 * Beyond that, cache-control headers should be respected and that's the whole point of them.
 * ie You shouldn't need any exception rules for cart or checkout pages because they should send no-store
 * See https://developers.cloudflare.com/cache/concepts/cache-control/#directives
 */
namespace mnmlcache;

function set_cloudflare_admin_notice($message) {
    update_option('mnmlcache_cloudflare_admin_notice', $message, false);
}

function clear_cloudflare_admin_notice() {
    delete_option('mnmlcache_cloudflare_admin_notice');
}

function render_cloudflare_admin_notice() {
    if (!current_user_can('manage_options')) {
        return;
    }

    $page = $_GET['page'] ?? '';
    if ($page !== 'mnml-cache') {
        return;
    }

    $message = get_option('mnmlcache_cloudflare_admin_notice');
    if (!$message) {
        return;
    }

    echo '<div class="notice notice-error"><p>' . esc_html($message) . '</p></div>';
}
add_action('admin_notices', __NAMESPACE__ . '\\render_cloudflare_admin_notice');

function cloudflare_purge( $urls=false ) {
	$api_token = get_option('mnmlcache_cloudflare_api_token');
    if (!$api_token) {
        set_cloudflare_admin_notice('Mnml Cache: Cloudflare API token is missing. Add a valid API token in settings to enable Cloudflare cache purges.');
        mnmlcache_debug('Missing Cloudflare API token');
        return false;
    }
    if ( $urls ) {
        $post_body = ['files' => array_map( function($url) {
            return substr( $url, 0, 4 ) === 'http' ? $url : home_url($url);
        }, $urls) ];
    } else {
        $post_body = ['purge_everything' => true];
    }

    for ($attempt = 0; $attempt < 2; $attempt++) {
        $zone_id = get_cloudflare_zone_id($api_token);
        if (!$zone_id) {
            mnmlcache_debug('Missing or invalid Cloudflare zone ID');
            return false;
        }

        $response = wp_remote_post("https://api.cloudflare.com/client/v4/zones/$zone_id/purge_cache", [
            'headers' => [
                'Authorization' => "Bearer $api_token",
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode($post_body),
        ]);

        // mnmlcache_debug('Cloudflare purge response: ' . var_export( $response, true ) );

        if (is_wp_error($response)) {
            mnmlcache_debug('Cloudflare purge failed: ' . $response->get_error_message());
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code === 200) {
            // clear_cloudflare_admin_notice();// I dont think its ever needed here.
            mnmlcache_debug('Cloudflare purged!');
            return true;
        }

        $resb = json_decode(wp_remote_retrieve_body($response), true);
        // $error_code = $resb['errors'][0]['code'] ?? null;
        // If the API key is wrong, we get 401 with error code 10000. 
        // If the zone ID is wrong, might get the same 401 (if its a legitimate zone ID) or we get 404 with error code 7003 if the zone ID doesn't exist at all.
        // In either case we want to clear the cached zone ID and try again once before giving up.
        // mnmlcache_debug('Cloudflare purge failed: ' . var_export( $resb, true ) );

        if ( $response_code === 401 || $response_code === 404 ) {

            if ($attempt === 0 ) {
                mnmlcache_debug('Cached Cloudflare zone ID could be invalid, clearing cache and retrying zone discovery');
                delete_option('mnmlcache_cloudflare_zone_id');
                continue;
            }
            // I don't think we ever get here, since if the API Key is wrong, it will error out when trying to refresh the Zone ID.
            set_cloudflare_admin_notice('Mnml Cache: Cloudflare authentication failed. Please verify the API token is valid and has Zone:Read and Cache Purge permissions.');
            mnmlcache_debug('Cloudflare auth failed while purging cache.  API token is likely wrong.');
            return false;
        }

        if ( !empty( $resb['errors'][0])) {
            set_cloudflare_admin_notice( "Error " . $resb['errors'][0]['code'] . ": " . $resb['errors'][0]['message'] );
            mnmlcache_debug('Cloudflare purge failed with error: ' . var_export( $resb['errors'], true ) );
        } else {
            set_cloudflare_admin_notice('Mnml Cache: Cloudflare purge failed with unknown error. Please check debug logs for details.');
            mnmlcache_debug('Cloudflare purge failed with unknown error: ' . var_export( $resb, true ) );
        }
        return false;
    }

    return false;
}

function get_cloudflare_zone_id($api_token) {
    $cached_zone_id = get_option('mnmlcache_cloudflare_zone_id');
    if ($cached_zone_id) {
        mnmlcache_debug('Using cached Cloudflare zone ID: ' . $cached_zone_id);
        return $cached_zone_id;
    }

    $hostname = parse_url(home_url(), PHP_URL_HOST);
    if (!$hostname) {
        mnmlcache_debug('Could not determine hostname for Cloudflare zone lookup');
        return false;
    }

    $labels = explode('.', $hostname);
    $zone_names = [];
    for ($index = 0; $index < count($labels) - 1; $index++) {
        $zone_names[] = implode('.', array_slice($labels, $index));
    }

    mnmlcache_debug('Fetching Cloudflare zone ID for host ' . $hostname . ' using candidates: ' . implode(', ', $zone_names));

    $results = [];
    foreach ($zone_names as $zone_name) {
        $response = wp_remote_get(add_query_arg([
            'name' => $zone_name,
            'per_page' => 1,
        ], 'https://api.cloudflare.com/client/v4/zones'), [
            'headers' => [
                'Authorization' => "Bearer $api_token",
                'Content-Type' => 'application/json',
            ],
        ]);

        if (is_wp_error($response)) {
            mnmlcache_debug('Cloudflare zone lookup failed: ' . $response->get_error_message());
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $resb = json_decode(wp_remote_retrieve_body($response), true);

        if ( $response_code === 200 ) {
            if (!empty($resb['result'][0]['id'])) {
                clear_cloudflare_admin_notice();// TODO this would be better done on settings save.
                update_option('mnmlcache_cloudflare_zone_id', $resb['result'][0]['id']);
                mnmlcache_debug('Matched Cloudflare zone ' . $zone_name . ' => ' . $resb['result'][0]['id']);
                return $resb['result'][0]['id'];
            }
        }

        // this was code 9109 - message 'Invalid access token'
        if ( $response_code === 403 ) {
            set_cloudflare_admin_notice('Mnml Cache: Invalid Cloudflare API token.');
            mnmlcache_debug('Cloudflare auth failed while looking up zone ID');
            return false;
        }
        // TODO I haven't confirmed this happens... it's probably a permission error.  is zone:read really something you have to pick?
        if ( $response_code === 401 ) {
            set_cloudflare_admin_notice('Mnml Cache: Cloudflare authentication failed. Please verify the API token has Zone:Read and Cache Purge permissions.');
            mnmlcache_debug('Cloudflare auth failed while looking up zone ID');
            return false;
        }
        $results[] = $resb;
    }

    mnmlcache_debug('Could not find matching Cloudflare zone for ' . $hostname);
    mnmlcache_debug($results);
    return false;
}