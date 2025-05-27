<?php
/**
 * Handle all admin notices
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wrap notices functionality
 */
class MC_Notices {

	/**
	 * Setup actions and filters
	 */
	private function setup() {
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
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
		$config = MC_Config::factory()->get();

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

		$advanced_cache_broken = ! $wp_cache_broken && ! empty( $config['enable_caching'] ) && ( ! defined( 'MC_ADVANCED_CACHE' ) || ! MC_ADVANCED_CACHE );

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

		if ( in_array( 'config', $notices, true ) ) {
			echo '<p>' . wp_kses_post( sprintf( __( 'mnml cache could not create the necessary config file. Either click "Attempt Fix" or add the following code to <code>%s</code>:', 'mnml-cache' ), esc_html( WP_CONTENT_DIR . '/mnml-cache-config.php' ) ) );
			echo '<pre>' . esc_html( MC_Config::factory()->get_file_code() ) . '</pre>';
		}

		if ( in_array( 'wp-content', $notices, true ) || $advanced_cache_broken ) {
			echo '<p>' . wp_kses_post( sprintf( __( 'mnml cache could not write advanced-cache.php to your wp-content directory or the file has been tampered with. Either click "Attempt Fix" or add the following code manually to <code>wp-content/advanced-cache.php</code>:', 'mnml-cache' ), esc_html( WP_CONTENT_DIR . '/mnml-cache-config.php' ) ) );
			echo '<pre>' . esc_html( file_get_contents( __DIR__ . '/advanced-cache.php' ) ) . '</pre>';
		}

		echo '<p><a href="' . esc_attr( $setting_file ) . '?page=mnml-cache&url=' . esc_url( wp_unslash( $_SERVER['REQUEST_URI'] ) ) . '&action=mc_update&mc_settings_nonce=' . esc_attr( wp_create_nonce( 'mc_update_settings' ) ) . '" class="button button-primary" style="margin-left: 5px;">' . esc_html__( 'Attempt Fix', 'mnml-cache' ) . '</a>';
		echo '</div>';
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
