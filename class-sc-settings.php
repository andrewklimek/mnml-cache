<?php
/**
* Settings class
*/

defined( 'ABSPATH' ) || exit;

/**
* Class containing settings hooks
*/
class SC_Settings {
	
	/**
	* Setup the plugin
	*/
	public function setup() {
		
		add_action( 'rest_api_init', array( $this, 'register_api_endpoint' ) );
		add_action( 'load-settings_page_simple-cache', array( $this, 'purge_cache' ) );
		add_action( 'admin_menu', array( $this, 'action_admin_menu' ) );
		add_action( 'admin_bar_menu', array( $this, 'admin_bar_menu' ) );
	}
	
	public function register_api_endpoint() {
		register_rest_route( 'mnmlcache/v1', '/settings', [ 'methods' => 'POST', 'callback' => array( $this, 'update' ),
		'permission_callback' => function(){ return current_user_can('manage_options');}
		] );
	}
	
	/**
	* Define Settings
	*/
	public function get_options() {
		
		$main = [
			'enable_page_caching' => [],
			'private_cache' => [],
			'enable_gzip_compression' => [ 'callback' => 'SC_Settings::settings_enable_gzip_compression' ],
			'page_cache_length' => [ 'type' => 'number' ],
			'page_cache_length_unit' => [ 'options' => ['minutes','hours','days','weeks'], 'type' => 'select' ],
			'advanced_mode' => [],
			'advanced' => ['type' => 'section', 'show' => 'advanced_mode'],
			'cache_exception_urls' => [ 'type' => 'code' ],
			'cache_only_urls' => [ 'type' => 'code' ],
			'enable_url_exemption_regex' => [],
			'page_cache_enable_rest_api_cache' => [],
			'page_cache_restore_headers' => [ 'desc' => 'When enabled, the plugin will save the response headers present when the page is cached and it will send send them again when it serves the cached page. This is recommended when caching the REST API.'],
			'advanced_end' => ['type' => 'section_end' ],
		];

		return $main;
	}
	
	/**
	* Load settings page builder
	*/
	public function settings_page() {

		$options = [ 'sc_simple_cache' => self::get_options() ];
		
		/**
		*  Build Settings Page using framework in settings_page.php
		**/
		$values = SC_Config::factory()->get();
		$endpoint = rest_url('mnmlcache/v1/settings');
		$title = "Simple Cache";
		require( __DIR__.'/settings-page.php' );// needs $options, $endpoint, $title
		
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
		
		$wp_admin_bar->add_menu(
			array(
				'id'     => 'sc-purge-cache',
				'parent' => 'top-secondary',
				'href'   => esc_url( admin_url( 'options-general.php?page=simple-cache&amp;wp_http_referer=' . esc_url( wp_unslash( $_SERVER['REQUEST_URI'] ) ) . '&amp;action=sc_purge_cache&amp;sc_cache_nonce=' . wp_create_nonce( 'sc_purge_cache' ) ) ),
				'title'  => 'Purge Cache',
				)
			);
		}
		
		/**
		* Add options page
		*/
		public function action_admin_menu() {
			add_submenu_page( 'options-general.php', 'Simple Cache', 'Simple Cache', 'manage_options', 'simple-cache', array( $this, 'settings_page' ) );
		}
		
		/**
		* Purge cache manually
		*/
		public function purge_cache() {
			
			if ( ! empty( $_REQUEST['action'] ) && 'sc_purge_cache' === $_REQUEST['action'] ) {
				if ( ! current_user_can( 'manage_options' ) || empty( $_REQUEST['sc_cache_nonce'] ) || ! wp_verify_nonce( $_REQUEST['sc_cache_nonce'], 'sc_purge_cache' ) ) {
					wp_die( 'You need a higher level of permission.' );
				}
				
				sc_cache_flush();
				
				if ( ! empty( $_REQUEST['wp_http_referer'] ) ) {
					wp_safe_redirect( $_REQUEST['wp_http_referer'] );
					exit;
				}
			}
		}
		
		/**
		* Handle setting changes
		*/
		public function update( $request ) {
			
			if ( empty( $request['sc_simple_cache'] ) ) return;
			
			// if ( empty( $_REQUEST['action'] ) || 'sc_update' !== $_REQUEST['action'] ) return;
			
			// if ( ! current_user_can( 'manage_options' ) || empty( $_REQUEST['sc_settings_nonce'] ) || ! wp_verify_nonce( $_REQUEST['sc_settings_nonce'], 'sc_update_settings' ) ) {
			// 	wp_die( 'You need a higher level of permission.' );
			// }
			
			$verify_file_access = sc_verify_file_access();
			
			if ( is_array( $verify_file_access ) ) {
				update_option( 'sc_cant_write', array_map( 'sanitize_text_field', $verify_file_access ) );
				
				if ( in_array( 'cache', $verify_file_access, true ) ) {
					wp_safe_redirect( $_REQUEST['wp_http_referer'] );
					exit;
				}
			} else {
				delete_option( 'sc_cant_write' );
			}
			
			$new_settings = $request['sc_simple_cache'];
			$clean_config = [];
			
			foreach ( $new_settings as $key => $value ) {
				$clean_config[ $key ] = isset( $value ) ? htmlspecialchars( $value ) : '';
			}
			
			// Back up configration in options.
			update_option( 'sc_simple_cache', $clean_config );
			
			SC_Config::factory()->write( $clean_config );
			
			SC_Advanced_Cache::factory()->write();
			
			if ( $clean_config['enable_page_caching'] ) {
				SC_Advanced_Cache::factory()->toggle_caching( true );
			} else {
				SC_Advanced_Cache::factory()->toggle_caching( false );
			}
			
			// Reschedule cron events.
			SC_Cron::factory()->unschedule_events();
			SC_Cron::factory()->schedule_events();
			
			// TODO is this used, and works with SEST?
			if ( ! empty( $_REQUEST['wp_http_referer'] ) ) {
				wp_safe_redirect( $_REQUEST['wp_http_referer'] );
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
	