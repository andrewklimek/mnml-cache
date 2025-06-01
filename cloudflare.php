<?php
/**
 * Cloudflare Integration
 * 
 * You really shouldn't need any specific setup on Cloudflare other than "enable everything" cache rule to enable HTML caching at all
 * The cache-control headers should be respected and that's the whole point of them.
 * So a rule like (not http.cookie contains "wordpress_logged_in_") works but shouldn't be required, nor any cart/checkout rules.
 * See https://developers.cloudflare.com/cache/concepts/cache-control/#directives
 */
function mnmlcache_cloudflare_purge_urls($urls) {
	$api_token = get_option('mnmlcache_cloudflare_api_token');
    $zone_id = mnmlcache_get_cloudflare_zone_id($api_token);
    if (!$api_token || !$zone_id) {
        mnmlcache_debug('Page Cache Plugin: Missing Cloudflare API token or zone ID');
        return false;
    }

    $response = wp_remote_post("https://api.cloudflare.com/client/v4/zones/$zone_id/purge_cache", [
        'headers' => [
            'Authorization' => "Bearer $api_token",
            'Content-Type' => 'application/json',
        ],
        'body' => json_encode(['files' => array_map('home_url', $urls)]),
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

function mnmlcache_cloudflare_purge_all() {
    $api_token = get_option('mnmlcache_cloudflare_api_token');
    $zone_id = mnmlcache_get_cloudflare_zone_id($api_token);
    if (!$api_token || !$zone_id) {
        mnmlcache_debug('Page Cache Plugin: Missing Cloudflare API token or zone ID');
        return false;
    }

    $response = wp_remote_post("https://api.cloudflare.com/client/v4/zones/$zone_id/purge_cache", [
        'headers' => [
            'Authorization' => "Bearer $api_token",
            'Content-Type' => 'application/json',
        ],
        'body' => json_encode(['purge_everything' => true]),
    ]);

    if (is_wp_error($response)) {
        mnmlcache_debug('Page Cache Plugin: Cloudflare purge all failed: ' . $response->get_error_message());
        return false;
    }
    if (wp_remote_retrieve_response_code($response) !== 200) {
        mnmlcache_debug('Page Cache Plugin: Cloudflare purge all failed: ' . wp_remote_retrieve_body($response));
        return false;
    }
    return true;
}

function mnmlcache_get_cloudflare_zone_id($api_token) {
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