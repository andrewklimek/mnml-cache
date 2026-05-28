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

## Key Files

- `advanced-cache.php` — Early bootstrap
- `serve.php` — Core caching logic
- `cloudflare.php` — Purge integration

