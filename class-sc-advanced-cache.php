<?php
/**
 * Page caching functionality
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wrapper for advanced cache functionality
 */
class SC_Advanced_Cache {

	/**
	 * Setup hooks/filters
	 */
	public function setup() {
		add_action( 'pre_post_update', array( $this, 'purge_post_on_update' ), 10, 1 );
		add_action( 'save_post', array( $this, 'purge_post_on_update' ), 10, 1 );
		add_action( 'wp_trash_post', array( $this, 'purge_post_on_update' ), 10, 1 );
		add_action( 'wp_set_comment_status', array( $this, 'purge_post_on_comment_status_change' ), 10 );
	}

	/**
	 * Every time a comments status changes, purge it's parent posts cache
	 * TODO doesn't this run when the approved code below runs too?  or is it not considered a change if it goes straight to approved?
	 *
	 * @param  int $comment_id Comment ID.
	 */
	public function purge_post_on_comment_status_change( $comment_id ) {

		$comment = get_comment( $comment_id );
		$post_id = $comment->comment_post_ID;

		$path = sc_get_cache_path() . '/' . preg_replace( '#https?://#i', '', get_permalink( $post_id ) );

		@unlink( untrailingslashit( $path ) . '/index.html' );
		@unlink( untrailingslashit( $path ) . '/index.gzip.html' );
	}

	/**
	 * Purge post cache when there is a new approved comment
	 *
	 * @param  int   $comment_id Comment ID.
	 * @param  int   $approved Comment approved status.
	 * @param  array $commentdata Comment data array.
	 */
	public function purge_post_on_comment( $comment_id, $approved, $commentdata ) {
		if ( empty( $approved ) ) {
			return;
		}

		$post_id = $commentdata['comment_post_ID'];

		$path = sc_get_cache_path() . '/' . preg_replace( '#https?://#i', '', get_permalink( $post_id ) );

		@unlink( untrailingslashit( $path ) . '/index.html' );
		@unlink( untrailingslashit( $path ) . '/index.gzip.html' );
	}

	/**
	 * Automatically purge all file based page cache on post changes
	 *
	 * @param  int $post_id Post id.
	 */
	public function purge_post_on_update( $post_id ) {
		error_log("this ran");
		$post = get_post( $post_id );

		// Do not purge the cache if it's an autosave or it is updating a revision.
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || 'revision' === $post->post_type ) {
			return;

			// Do not purge the cache if the user cannot edit the post.
		} elseif ( ! current_user_can( 'edit_post', $post_id ) && ( ! defined( 'DOING_CRON' ) || ! DOING_CRON ) ) {
			return;

			// Do not purge the cache if the user is editing an unpublished post.
		} elseif ( 'draft' === $post->post_status ) {
			return;
		}

		sc_cache_flush();
	}

	/**
	 * Delete file for clean up
	 *
	 * @return bool
	 */
	public function clean_up() {

		$ret = true;

		if ( ! @unlink( WP_CONTENT_DIR . '/advanced-cache.php' ) ) {
			$ret = false;
		}

		if ( ! @rmdir( WP_CONTENT_DIR . '/cache/simple-cache' ) ) {
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

		$source_file = __DIR__ . '/advanced-cache.php';
        $destination_file = untrailingslashit( WP_CONTENT_DIR ) . '/advanced-cache.php';

        // Check if the source file exists
        if ( ! file_exists( $source_file ) ) {
            return false;
        }

        // Copy the file
        if ( ! copy( $source_file, $destination_file ) ) {
            return false;
        }

        // Verify the file was copied correctly
        if ( ! file_exists( $destination_file ) || filesize( $destination_file ) === 0 ) {
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
	public function toggle_caching( $status ) {

		if ( defined( 'WP_CACHE' ) && WP_CACHE === $status ) {
			return;
		}

		$file        = '/wp-config.php';
		$config_path = false;

		for ( $i = 1; $i <= 3; $i++ ) {
			if ( $i > 1 ) {
				$file = '/..' . $file;
			}

			if ( file_exists( untrailingslashit( ABSPATH ) . $file ) ) {
				$config_path = untrailingslashit( ABSPATH ) . $file;
				break;
			}
		}

		// Couldn't find wp-config.php.
		if ( ! $config_path ) {
			return false;
		}

		$config_file_string = file_get_contents( $config_path );

		// Config file is empty. Maybe couldn't read it?
		if ( empty( $config_file_string ) ) {
			return false;
		}

		$config_file = preg_split( "#(\n|\r)#", $config_file_string );
		$line_key    = false;

		foreach ( $config_file as $key => $line ) {
			if ( ! preg_match( '/^\s*define\(\s*(\'|")([A-Z_]+)(\'|")(.*)/', $line, $match ) ) {
				continue;
			}

			if ( 'WP_CACHE' === $match[2] ) {
				$line_key = $key;
			}
		}

		if ( false !== $line_key ) {
			unset( $config_file[ $line_key ] );
		}

		$status_string = ( $status ) ? 'true' : 'false';

		array_shift( $config_file );
		array_unshift( $config_file, '<?php', "define( 'WP_CACHE', $status_string ); // Simple Cache" );

		foreach ( $config_file as $key => $line ) {
			if ( '' === $line ) {
				unset( $config_file[ $key ] );
			}
		}

		if ( ! file_put_contents( $config_path, implode( "\r\n", $config_file ) ) ) {
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

		if ( ! $instance ) {
			$instance = new self();
			$instance->setup();
		}

		return $instance;
	}
}
