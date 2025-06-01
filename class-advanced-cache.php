<?php
/**
 * Page caching functionality
 */

namespace mnmlcache;

defined('ABSPATH') || exit;

class MC_Advanced_Cache {

	public function setup() {
		add_action('pre_post_update', [$this, 'purge_post_on_update'], 10, 1);
		add_action('save_post', [$this, 'purge_post_on_update'], 10, 1);
		add_action('wp_trash_post', [$this, 'purge_post_on_update'], 10, 1);
		add_action('wp_set_comment_status', [$this, 'purge_post_on_comment_status_change'], 10);
	}


	/**
	 * Clear whole cache or one url
	 */
	public function cache_purge( $url = false ) {

		if ( false === $url ) {

			$this->empty_dir( MC_CACHE_DIR );

			wp_cache_flush();

			mnmlcache_cloudflare_purge_all();

		} else {
			
			$url_path = get_url_path($url);
			
			if (file_exists($url_path)) unlink($url_path);
			if (file_exists($url_path . ".gzip")) unlink($url_path . ".gzip");
			if (file_exists($url_path . ".json")) unlink($url_path . ".json");
			
			if (defined('WP_DEBUG') && WP_DEBUG) {
				mnmlcache_debug("mnml cache: Purged cache for URL: $url");
			}
			mnmlcache_cloudflare_purge_urls([$url]);
		}
	}

	/**
	 * Empty a directory, optionally delete it too.
	 *
	 * @param  string $dir Directory
	 * @param    bool $retain_root Should root directory itself be delete or retained
	 */
	public function empty_dir($dir, $retain_root = true) {

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
				if (!$this->empty_dir($path, false)) {
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
	 * Every time a comments status changes, purge it's parent posts cache
	 *
	 * @param  int $comment_id Comment ID.
	 */
	public function purge_post_on_comment_status_change($comment_id) {

		$comment = get_comment($comment_id);
		$post_id = $comment->comment_post_ID;
		$self->cache_purge( get_permalink( $post_id ) );
	}

	/**
	 * Automatically purge all file based page cache on post changes
	 *
	 * @param  int $post_id Post id.
	 */
	public function purge_post_on_update($post_id) {

		$post = get_post($post_id);

		// Do not purge the cache if it's an autosave or it is updating a revision.
		if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || 'revision' === $post->post_type) {
			return;

			// Do not purge the cache if the user cannot edit the post.
		} elseif (! current_user_can('edit_post', $post_id) && (! defined('DOING_CRON') || ! DOING_CRON)) {
			return;

			// Do not purge the cache if the user is editing an unpublished post.
		} elseif ('draft' === $post->post_status) {
			return;
		}

		$self->cache_purge();
	}

	/**
	 * Delete file for clean up
	 *
	 * @return bool
	 */
	public function clean_up() {

		$ret = true;

		if (! @unlink(WP_CONTENT_DIR . '/advanced-cache.php')) {
			$ret = false;
		}

		if (! @rmdir(WP_CONTENT_DIR . '/cache/mnml-cache')) {
			$ret = false;
		}

		return $ret;
	}


	/**
	 * Write advanced-cache.php
	 *
	 * @return bool
	 */
	public function write() {

		$source_file      = __DIR__ . '/advanced-cache.php';
		$destination_file = untrailingslashit(WP_CONTENT_DIR) . '/advanced-cache.php';

		// Check if the source file exists
		if (! file_exists($source_file)) {
			return false;
		}

		// Copy the file
		if (! copy($source_file, $destination_file)) {
			return false;
		}

		// Verify the file was copied correctly
		if (! file_exists($destination_file) || filesize($destination_file) === 0) {
			return false;
		}

		return true;
	}

	/**
	 * Toggle WP_CACHE on or off in wp-config.php
	 *
	 * @param  boolean $status Status of cache.
	 * @return boolean
	 */
	public function toggle_caching($status) {
		$status = (bool) $status;

		if (defined('WP_CACHE') && (bool) WP_CACHE === $status) {
			return true;
		}

		if (file_exists(ABSPATH . 'wp-config.php')) {
			$config_path = ABSPATH . 'wp-config.php';
		} elseif (@file_exists(dirname(ABSPATH) . '/wp-config.php') && ! @file_exists(dirname(ABSPATH) . '/wp-settings.php')) {
			/** The config file resides one level above ABSPATH but is not part of another installation */
			$config_path = dirname(ABSPATH) . '/wp-config.php';
		} else {
			// A config file doesn't exist.
			return false;
		}

		// Check if file exists and is writable
		if (! is_writable($config_path)) {
			return false;
		}

		// Read the file
		$config_content = file_get_contents($config_path);
		if ($config_content === false || empty($config_content)) {
			return false;
		}

		// Backup the original file
		$backup_path = $config_path . '.bak';
		if (! copy($config_path, $backup_path)) {
			return false; // Backup failed
		}

		// Split into lines, preserving original line endings
		$lines = file($config_path, FILE_IGNORE_NEW_LINES);
		$line_key = false;

		// Look for existing WP_CACHE definition
		foreach ($lines as $key => $line) {
			if (preg_match("/^\s*define\s*\(\s*['\"]WP_CACHE['\"]\s*,\s*[^)]+\)\s*;/i", $line, $match)) {
				$line_key = $key;
				break;
			}
		}

		// Prepare the new WP_CACHE line with proper formatting
		$new_line = "define('WP_CACHE', " . ($status ? 'true' : 'false') . "); // added by mnml cache";

		// Modify the file content
		if ($line_key !== false) {
			// Replace existing WP_CACHE line
			$lines[$line_key] = $new_line;
		} else {
			// Insert after <?php or at the start of the file
			$inserted = false;
			foreach ($lines as $key => $line) {
				if (preg_match('/^\s*<\?php/i', $line)) {
					array_splice($lines, $key + 1, 0, ['', $new_line] );
					$inserted = true;
					break;
				}
			}
			// If no <?php found, prepend <?php and the new line
			if (!$inserted) {
				array_unshift($lines, '<?php', '', $new_line);
			}
		}

		$new_content = implode(PHP_EOL, $lines);

		// Write the modified content
		if (file_put_contents($config_path, $new_content) === false) {
			return false;
		}
		
		return true;
	}

	/**
	 * Return an instance of the current class, create one if it doesn't exist
	 *
	 * @return object
	 */
	public static function factory() {

		static $instance;

		if (! $instance) {
			$instance = new self();
			$instance->setup();
		}

		return $instance;
	}
}
