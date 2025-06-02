<?php
/**
* Settings class
*/
namespace mnmlcache;

defined( 'ABSPATH' ) || exit;

/**
* Class containing settings hooks
*/
class MC_Settings {
	
	/**
	* Setup the plugin
	*/
	public function setup() {
		add_action( 'load-settings_page_mnml-cache', array( $this, 'update' ) );
		add_action( 'load-settings_page_mnml-cache', array( $this, 'purge_cache' ) );
		add_action( 'admin_menu', array( $this, 'action_admin_menu' ) );
		add_action( 'admin_bar_menu', array( $this, 'admin_bar_menu' ) );
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
	}
	
	/**
	* Define Settings
	*/
	public function get_options() {
		
		$main = [
			'enable_caching' => [],
			'private_cache' => [],
			'private_cache_max_age' => ['type' => 'number', 'show' => 'private_cache', 'desc' => 'in hours'],
			'enable_gzip_compression' => [ 'callback' => 'MC_Settings::settings_enable_gzip_compression' ],
			'enable_json_cache' => [],
			'restore_headers' => [ 'desc' => 'When enabled, the plugin will save the response headers present when the page is cached and it will send send them again when it serves the cached page. This is recommended when caching the REST API.'],
			'enable_url_exemption_regex' => [],
			'cache_exception_urls' => [ 'type' => 'code' ],
			'cache_only_urls' => [ 'type' => 'code' ],
			'storage_scheme' => [
				'type' => 'select',
				'options' => [
					'1-letter' => '1-Letter Shard (e.g., /path/s/slug/)',
					'2-letter' => '2-Letter Shard (e.g., /path/sl/slug/)',
					'md5' => 'Full MD5 (e.g., /path/c/c49a31dcdd/)',
				],
				'desc' => 'Naming scheme for cache directories.',
			],
			'enable_debugging' => [],
		];

		return $main;
	}
	
	/**
	* Load settings page builder
	*/
	public function settings_page() {

		$shards = count(glob(MC_CACHE_DIR . '/*/*', GLOB_ONLYDIR));
		$pages = count(glob(MC_CACHE_DIR . '/*/*/*', GLOB_ONLYDIR));
		// $files = count(glob(MC_CACHE_DIR . '/*/*/*/*'));
		echo "Cache Statistics: $shards directories, $pages pages";

		$options = [ 'mnmlcache' => $this->get_options() ];
		$options['mnmlcache_'] = [ 'cloudflare_api_token' => [ 'type' => 'text' ] ];
		
		/**
		*  Build Settings Page using framework in settings_page.php
		**/
		$values = $GLOBALS['mnmlcache_config'];
		$title = "Mnml Cache";
		require( __DIR__.'/settings-framework.php' );
	}

	public static function settings_enable_gzip_compression( $g, $k, $v, $f, $l ) {
		if ( ! function_exists( 'gzencode' ) ) {
			echo "<label for='{$g}-{$k}'>{$l}</label><td><a href='https://www.php.net/manual/en/function.gzencode.php'>gzencode</a> is not available on your PHP evironment.";
		} else {
			return "show it";
		}
	}
	
	/**
	* Add purge cache button to admin bar
	*/
	public function admin_bar_menu() {
		global $wp_admin_bar;
		
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// $current_url = rawurlencode( (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'] );
		$current_url = urlencode( $_SERVER['REQUEST_URI'] );

		$nonce = wp_create_nonce('mc_purge_cache');
    
		$wp_admin_bar->add_node([
			'id'     => 'mnml-cache',
			'parent' => 'top-secondary',
			'title'  => 'Cache',
			'href'   => admin_url( 'options-general.php?page=mnml-cache' ),
		]);

		if ( ! empty( $GLOBALS['mnmlcache_config']['enable_caching'] ) ) {

			$wp_admin_bar->add_node([
				'id' => 'mnml-cache-purge-page',
				'parent' => 'mnml-cache',
				'title' => 'Purge This Page',
				'href'   => admin_url( 'options-general.php?page=mnml-cache&url=' . rawurlencode( $_SERVER['REQUEST_URI'] ) . '&action=mc_purge_cache&current=1&nonce=' . $nonce ),// url_esc'd at render by core
			]);
			
			$wp_admin_bar->add_node([
				'id'     => 'mnml-cache-purge-all',
				'parent' => 'mnml-cache',
				'title'  => 'Purge All',
				'href'   => admin_url( 'options-general.php?page=mnml-cache&url=' . rawurlencode( $_SERVER['REQUEST_URI'] ) . '&action=mc_purge_cache&nonce=' . $nonce ),
			]);
		}
	}
	
	/**
	* Add options page
	*/
	public function action_admin_menu() {
		add_submenu_page( 'options-general.php', 'Mnml Cache', 'Mnml Cache', 'manage_options', 'mnml-cache', array( $this, 'settings_page' ) );
	}
	
	/**
	* Purge cache manually
	*/
	public function purge_cache() {

		if ( empty( $_REQUEST['action'] ) || 'mc_purge_cache' !== $_REQUEST['action'] ) return;// but isnt this the only way we get here?

		if ( ! current_user_can( 'manage_options' ) || empty( $_REQUEST['nonce'] ) || ! wp_verify_nonce( $_REQUEST['nonce'], 'mc_purge_cache' ) ) {
			wp_die( 'You need a higher level of permission.' );
		}
		
		if ( empty( $_REQUEST['current'] ) ) {
			MC_Advanced_Cache::factory()->cache_purge();
		} elseif ( ! empty( $_REQUEST['url'] ) ) {
			MC_Advanced_Cache::factory()->cache_purge( $_REQUEST['url'] );
		}
		
		if ( ! empty( $_REQUEST['url'] ) ) {
			wp_safe_redirect( $_REQUEST['url'] );
			exit;
		}
	}
	
	/**
	* Handle setting changes
	*/
	public function update() {

		if ( empty( $_REQUEST['action'] ) || 'mc_update' !== $_REQUEST['action'] ) return;
		
		if ( ! current_user_can( 'manage_options' ) || empty( $_REQUEST['mc_settings_nonce'] ) || ! wp_verify_nonce( $_REQUEST['mc_settings_nonce'], 'mc_update_settings' ) ) {
			wp_die( 'You need a higher level of permission.' );
		}
		
		$verify_file_access = $this->verify_file_access();
		
		if ( is_array( $verify_file_access ) ) {
			update_option( 'mnmlcache_notices', array_map( 'sanitize_text_field', $verify_file_access ), true );
			
			if ( in_array( 'cache', $verify_file_access, true ) && ! empty( $_REQUEST['url'] ) ) {
				wp_safe_redirect( $_REQUEST['url'] );
				exit;
			}
		} else {
			delete_option( 'mnmlcache_notices' );
		}
		
		$config = $GLOBALS['mnmlcache_config'];

		$options = $_REQUEST['options'] ?? [];

		foreach( $options as $option => $value ) {

			if ( $option === 'mnmlcache' ) {
				$value = array_map( 'htmlspecialchars', $value );
				$config = $value;
				$this->write( $config );
			}
			update_option( $option, $value );
		}
		
		require_once __DIR__ . '/class-advanced-cache.php';

		if ( !empty( $config['enable_caching'] ) ) {
			MC_Advanced_Cache::factory()->write();
			MC_Advanced_Cache::factory()->toggle_caching( true );
		} else {
			MC_Advanced_Cache::factory()->toggle_caching( false );
		}
		
		if ( ! empty( $_REQUEST['url'] ) ) {
			wp_safe_redirect( $_REQUEST['url'] );
			exit;
		}

	}

	/**
	 * Write config to file
	 *
	 * @param  array $config Configuration array.
	 * @return bool
	 */
	public function write( $config ) {

		$options = $this->get_options();

		foreach ( $options as $key => $value ) {
			if ( ! isset( $config[ $key ] ) ) {
				$config[ $key ] = $value['default'] ?? '';
			}
		}

		$config_file_string = "<?php" . PHP_EOL . "defined( 'ABSPATH' ) || exit;" . PHP_EOL . "return " . var_export( $config, true ) . ";";

		if ( ! file_put_contents( WP_CONTENT_DIR . '/mnml-cache-config.php', $config_file_string ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Delete files and option for clean up
	 *
	 * @return bool
	 */
	public function clean_up() {

		delete_option( 'mnmlcache' );

		if ( ! @unlink( WP_CONTENT_DIR . '/mnml-cache-config.php' ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Output error notices
	 */
	public function admin_notices() {

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$setting_file = 'options-general.php';
		$notices = get_option( 'mnmlcache_notices', [] );
		$config = $GLOBALS['mnmlcache_config'];

		if ( !empty( $notices['welcome'] ) && empty( $config['enable_caching'] ) ) {

			
			$notices['welcome'] = (int) $notices['welcome'] - 1;

			echo "<div class='notice notice-warning'>";
			echo "<p>You've activated mnml cache, now you need to enable caching!";
			echo "<p>You can get to the settings by clicking the “cache” menu item in the admin toolbar (upper right).";
			echo "<p>This tip will be shown {$notices['welcome']} more times.";
			echo "</div>";

			if ( $notices['welcome'] < 1 ) {
				unset ( $notices['welcome'] );
			}
			if ( empty( $notices ) ) {
				delete_option( 'mnmlcache_notices' );
			} else {
				update_option( 'mnmlcache_notices', $notices, true );
			}

			return;
		}

		$wp_cache_broken = ! empty( $config['enable_caching'] ) && ( ! defined( 'WP_CACHE' ) || ! WP_CACHE );

		$advanced_cache_broken = ! $wp_cache_broken && ! empty( $config['enable_caching'] ) && ! empty( $config['advanced_cache_missing'] );

		// prevent warning for initial load after enabling.. 
		// this still shows if you reload the page quickly after save.
		// and of course it doesn't guarantee that options was just toggled
		if ( $wp_cache_broken ) {
			$maybe_just_enabled_cache = $_POST['options']['mnmlcache']['enable_caching'] ?? false;
			if ( $maybe_just_enabled_cache ) $wp_cache_broken = false;
		}

		if ( empty( $notices ) && ! $wp_cache_broken && ! $advanced_cache_broken ) {
			return;
		}
		echo '<div class="notice notice-error">';
		echo '<p>' . esc_html__( 'mnml cache has encountered the following error(s):', 'mnml-cache' );

		if ( in_array( 'cache', $notices, true ) ) {
			echo '<p>' . esc_html__( 'mnml cache is not able to write data to the cache directory.', 'mnml-cache' );
		}

		if ( in_array( 'wp-config', $notices, true ) || $wp_cache_broken ) {
			echo '<p>' . wp_kses_post( __( '<code>define( "WP_CACHE", true );</code> is not in wp-config.php. Either click "Attempt Fix" or add the code manually.', 'mnml-cache' ) );
		}

		if ( in_array( 'wp-content', $notices, true ) || $advanced_cache_broken ) {
			echo '<p>' . wp_kses_post( sprintf( __( 'mnml cache could not write advanced-cache.php to your wp-content directory or the file has been tampered with. Either click "Attempt Fix" or add the following code manually to <code>wp-content/advanced-cache.php</code>:', 'mnml-cache' ), esc_html( WP_CONTENT_DIR . '/mnml-cache-config.php' ) ) );
			echo '<pre>' . esc_html( file_get_contents( __DIR__ . '/advanced-cache.php' ) ) . '</pre>';
		}

		echo '<p><a href="' . esc_attr( $setting_file ) . '?page=mnml-cache&url=' . esc_url( wp_unslash( $_SERVER['REQUEST_URI'] ) ) . '&action=mc_update&mc_settings_nonce=' . esc_attr( wp_create_nonce( 'mc_update_settings' ) ) . '" class="button button-primary" style="margin-left: 5px;">' . esc_html__( 'Attempt Fix', 'mnml-cache' ) . '</a>';
		echo '</div>';
	}

	/**
	 * Verify we can write to the file system
	 *
	 * @return array|boolean
	 */
	public function verify_file_access() {
		if ( function_exists( 'clearstatcache' ) ) @clearstatcache();

		$errors = [];

		// Check wp-config.php
		if ( ! @is_writable( ABSPATH . 'wp-config.php' ) && ! @is_writable( ABSPATH . '../wp-config.php' ) ) {
			$errors[] = 'wp-config';
		}

		// Check wp-content
		if ( ! @is_writable( WP_CONTENT_DIR ) ) {
			$errors[] = 'wp-content';
		}

		// Check cache directory, parent, or grandparent
		if ( ! @is_writable( MC_CACHE_DIR ) && ! @is_writable( dirname( MC_CACHE_DIR ) ) && ! @is_writable( dirname( dirname( MC_CACHE_DIR ) ) ) ) {
			$errors[] = 'cache';
		}

		return $errors ?: true;
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
