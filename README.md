# Mnml Cache

A minimal, high-performance WordPress page caching plugin with built-in Cloudflare integration.

## Why Mnml Cache?

- **Extremely lightweight** — No bloat, no heavy dependencies. Loads early via `advanced-cache.php`.
- **Smart early bypass** — Skips caching for logged-in users (via cookie), admins, cron, POST requests, etc.
- **Hybrid caching** — Disk cache + Cloudflare edge via Cache Everything + origin `Cache-Control` respect.
- **Last-Modified + 304 support** — Efficient revalidation without full page serves.
- **Automatic Cloudflare purging** — On post updates, comments, etc.
- **Private cache option** — Controlled `Cache-Control` for logged-in sessions.

Much lighter and more transparent than WP Super Cache, W3 Total Cache, or Cloudflare's APO.

## Setup

1. Install and activate the plugin.
2. Configure via Settings → Mnml Cache.
3. In Cloudflare: Add a **Cache Rule**:
   - Cache Everything for the whole site.
   - Bypass if Cookie contains `wordpress_logged_in_`.
4. Add your Cloudflare API token in settings for auto-purging.

Test with incognito vs logged-in sessions.

## Header Respect & Common Issues

Mnml Cache is obedient to your site's `Cache-Control` and `Expires` headers.  If a plugin adds `no-cache` or `no-store` headers, it will not be cached.

If you see a page isn't being cached (for a logged-out visitor) and you see `Expires: Thu, 19 Nov 1981 08:52:00 GMT` header (that specific date), it almost always means a PHP session was started on the frontend (via `session_start()` or `session_cache_limiter()`) by some plugin, likely one that tracks visitors.