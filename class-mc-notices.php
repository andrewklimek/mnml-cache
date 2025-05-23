<?php
/**
 * Handle all admin notices
 *
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wrap notices functionality
 */
class MC_Notices {

	/**
	 * Setup actions and filters
	 *
	 */
	private function setup() {
		add_action( 'admin_notices', array( $this, 'error_notice' ) );
		add_action( 'admin_notices', array( $this, 'setup_notice' ) );
	}

	/**
	 * Output turn on notice
	 *
	 */
	public function setup_notice() {

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$cant_write = get_option( 'mc_cant_write', false );

		if ( $cant_write ) {
			return;
		}

		$config = MC_Config::factory()->get();

		if ( ! empty( $config['enable_caching'] ) ) {
			return;
		}

		?>
		<div class="notice notice-warning">
			<p>
				<?php esc_html_e( "mnml cache won't work until you turn it on.", 'mnml-cache' ); ?>
				<a href="options-general.php?page=mnml-cache&amp;url=<?php echo esc_url( wp_unslash( $_SERVER['REQUEST_URI'] ) ); ?>&amp;action=mc_update&amp;mc_settings_nonce=<?php echo esc_attr( wp_create_nonce( 'mc_update_settings' ) ); ?>&amp;mnmlcache[enable_caching]=1" class="button button-primary" style="margin-left: 5px;"><?php esc_html_e( 'Turn On Caching', 'mnml-cache' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Output error notices
	 *
	 */
	public function error_notice() {

		$setting_file = 'options-general.php';
		$cant_write   = get_option( 'mc_cant_write', array() );

		$config = MC_Config::factory()->get();

		$wp_cache_broken = ! empty( $config['enable_caching'] ) && ( ! defined( 'WP_CACHE' ) || ! WP_CACHE );

		$advanced_cache_broken = ! $wp_cache_broken && ! empty( $config['enable_caching'] ) && ( ! defined( 'MC_ADVANCED_CACHE' ) || ! MC_ADVANCED_CACHE );

		if ( empty( $cant_write ) && ! $wp_cache_broken && ! $advanced_cache_broken ) {
			return;
		}

		// TODO
		// Attempt to fix actions below don't work because settings page actions are not performed on page load now, but are an API endpoint...
		?>
		<div class="error sc-notice">
			<p><?php esc_html_e( 'mnml cache has encountered the following error(s):', 'mnml-cache' ); ?></p>
			<ol>
				<?php if ( in_array( 'cache', $cant_write, true ) ) : ?>
					<li>
						<?php esc_html_e( 'mnml cache is not able to write data to the cache directory.', 'mnml-cache' ); ?>
					</li>
				<?php endif; ?>

				<?php if ( in_array( 'wp-config', $cant_write, true ) || $wp_cache_broken ) : ?>
					<li>
						<?php echo wp_kses_post( __( '<code>define( "WP_CACHE", true );</code> is not in wp-config.php. Either click "Attempt Fix" or add the code manually.', 'mnml-cache' ) ); ?>
					</li>
				<?php endif; ?>

				<?php if ( in_array( 'config', $cant_write, true ) ) : ?>
					<li>
						<?php echo wp_kses_post( sprintf( __( 'mnml cache could not create the necessary config file. Either click "Attempt Fix" or add the following code to <code>%s</code>:', 'mnml-cache' ), esc_html( WP_CONTENT_DIR . '/mnml-cache-config.php' ) ) ); ?>

						<pre><?php echo esc_html( MC_Config::factory()->get_file_code() ); ?></pre>
					</li>
				<?php endif; ?>

				<?php if ( in_array( 'wp-content', $cant_write, true ) || $advanced_cache_broken ) : ?>
					<li>
						<?php echo wp_kses_post( sprintf( __( 'mnml cache could not write advanced-cache.php to your wp-content directory or the file has been tampered with. Either click "Attempt Fix" or add the following code manually to <code>wp-content/advanced-cache.php</code>:', 'mnml-cache' ), esc_html( WP_CONTENT_DIR . '/mnml-cache-config.php' ) ) ); ?>

						<pre><?php echo esc_html( file_get_contents( __DIR__ . '/advanced-cache.php' ) ); ?></pre>
					</li>
				<?php endif; ?>
			</ol>

			<p>
				<a href="<?php echo esc_attr( $setting_file ); ?>?page=mnml-cache&amp;url=<?php echo esc_url( wp_unslash( $_SERVER['REQUEST_URI'] ) ); ?>&amp;action=mc_update&amp;mc_settings_nonce=<?php echo esc_attr( wp_create_nonce( 'mc_update_settings' ) ); ?>" class="button button-primary" style="margin-left: 5px;"><?php esc_html_e( 'Attempt Fix', 'mnml-cache' ); ?></a>
			</p>
		</div>
		<?php
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
