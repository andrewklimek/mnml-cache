<?php
/**
 * Handle plugin config
 *
 * @package  simple-cache
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class wrapping config functionality
 */
class SC_Config {

	/**
	 * Return defaults
	 *
	 * @since  1.0
	 * @return array
	 */
	public function get_defaults() {

		$options = SC_Settings::factory()->get_options();

		$defaults = [];

		foreach ( $options as $key => $value ) {
			if ( isset( $value['default'] ) ) {
				$defaults[ $key ] = $value['default'];
			} elseif ( isset( $value['options'] ) ) {
				$defaults[ $key ] = current( $value['options'] );
			} else {
				$defaults[ $key ] = '';
			}
		}

		return $defaults;
	}

	/**
	 * Get config file name
	 *
	 * @since  1.7
	 * @return string
	 */
	private function get_config_file_name() {

		$home_url_parts = wp_parse_url( home_url() );

		return 'config-' . $home_url_parts['host'] . '.php';
	}

	/**
	 * Get contents of config file
	 *
	 * @since  1.7
	 * @param  array $config Config array to use
	 * @return string
	 */
	public function get_file_code( $config = null ) {
		if ( empty( $config ) ) {
			$config = $this->get();
		}

		// phpcs:disable
		return "<?php\r\ndefined( 'ABSPATH' ) || exit;\r\nreturn " . var_export( $config, true ) . ";";
		// phpcs:enable
	}

	/**
	 * Write config to file
	 *
	 * @since  1.0
	 * @param  array $config Configuration array.
	 * @return bool
	 */
	public function write( $config ) {

		$config_dir = sc_get_config_dir();

		$file_name = $this->get_config_file_name();

		$config = wp_parse_args( $config, $this->get_defaults() );

		@mkdir( $config_dir );

		$config_file_string = $this->get_file_code( $config );

		if ( ! file_put_contents( $config_dir . '/' . $file_name, $config_file_string ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Get config from cache
	 *
	 * @since  1.0
	 * @return array
	 */
	public function get() {

		$config = get_option( 'sc_simple_cache', $this->get_defaults() );

		return wp_parse_args( $config, $this->get_defaults() );
	}

	/**
	 * Delete files and option for clean up
	 *
	 * @since  1.2.2
	 * @return bool
	 */
	public function clean_up() {

		$config_dir = sc_get_config_dir();

		delete_option( 'sc_simple_cache' );

		if ( ! @unlink( $config_dir . '/' . $this->get_config_file_name() ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Return an instance of the current class, create one if it doesn't exist
	 *
	 * @since  1.0
	 * @return SC_Config
	 */
	public static function factory() {

		static $instance;

		if ( ! $instance ) {
			$instance = new self();
		}

		return $instance;
	}
}
