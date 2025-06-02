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

function cloudflare_purge( $urls=false ) {
	$api_token = get_option('mnmlcache_cloudflare_api_token');
    $zone_id = get_cloudflare_zone_id($api_token);
    if (!$api_token || !$zone_id) {
        mnmlcache_debug('Page Cache Plugin: Missing Cloudflare API token or zone ID');
        return false;
    }

    $body = $urls ? ['files' => array_map('home_url', $urls)] : ['purge_everything' => true];

    $response = wp_remote_post("https://api.cloudflare.com/client/v4/zones/$zone_id/purge_cache", [
        'headers' => [
            'Authorization' => "Bearer $api_token",
            'Content-Type' => 'application/json',
        ],
        'body' => json_encode($body),
    ]);

    if (is_wp_error($response)) {
        mnmlcache_debug('Page Cache Plugin: Cloudflare purge failed: ' . $response->get_error_message());
        return false;
    }
    if (wp_remote_retrieve_response_code($response) !== 200) {
        mnmlcache_debug('Page Cache Plugin: Cloudflare purge failed: ' . wp_remote_retrieve_body($response));
        return false;
    }
    return true;
}

// TODO re-discover zone Id if it fails (if it has changed).
function get_cloudflare_zone_id($api_token) {
    $cached_zone_id = get_option('mnmlcache_cloudflare_zone_id');
    if ($cached_zone_id) {
        return $cached_zone_id;
    }

    $response = wp_remote_get('https://api.cloudflare.com/client/v4/zones', [
        'headers' => [
            'Authorization' => "Bearer $api_token",
            'Content-Type' => 'application/json',
        ],
    ]);

    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
        mnmlcache_debug('Page Cache Plugin: Failed to get Cloudflare zone ID: ' . wp_remote_retrieve_body($response));
        return false;
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);

    foreach ($body['result'] as $zone) {
        if ($zone['name'] === parse_url(home_url(), PHP_URL_HOST)) {
            update_option('mnmlcache_cloudflare_zone_id', $zone['id']);
            return $zone['id'];
        }
    }
    return false;
}