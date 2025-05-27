<?php
/**
* Settings class
*/

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
		];

		return $main;
	}
	
	/**
	* Load settings page builder
	*/
	public function settings_page() {

		$options = [ 'mnmlcache' => self::get_options() ];
		$options['mnmlcache_'] = [ 'cloudflare_api_token' => [ 'type' => 'text' ] ];
		
		/**
		*  Build Settings Page using framework in settings_page.php
		**/
		$values = MC_Config::factory()->get();
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
			mc_cache_flush();
		} elseif ( ! empty( $_REQUEST['url'] ) ) {
			mc_cache_purge( $_REQUEST['url'] );
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
		
		$verify_file_access = mc_verify_file_access();
		
		if ( is_array( $verify_file_access ) ) {
			update_option( 'mnmlcache_notices', array_map( 'sanitize_text_field', $verify_file_access ), true );
			
			if ( in_array( 'cache', $verify_file_access, true ) ) {
				wp_safe_redirect( $_REQUEST['url'] );
				exit;
			}
		} else {
			delete_option( 'mnmlcache_notices' );
		}
		
		$config = MC_Config::factory()->get();

		$options = $_REQUEST['options'] ?? [];

		foreach( $options as $option => $value ) {

			if ( $option === 'mnmlcache' ) {
				$value = array_map( 'htmlspecialchars', $value );
				$config = $value;
				MC_Config::factory()->write( $config );
			}
			update_option( $option, $value );
		}
		
		require_once __DIR__ . '/class-mc-cache.php';
		
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
		
		return "Saved";
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
