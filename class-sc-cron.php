<?php
/**
 * Cron functionality
 *
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wrap cron functionality
 */
class SC_Cron {

	/**
	 * Setup actions and filters
	 *
	 */
	private function setup() {

		add_action( 'sc_purge_cache', array( $this, 'purge_cache' ) );
		add_action( 'init', array( $this, 'schedule_events' ) );
		add_filter( 'cron_schedules', array( $this, 'filter_cron_schedules' ) );
	}

	/**
	 * Add custom cron schedule
	 *
	 * @param  array $schedules Current cron schedules.
	 * @return array
	 */
	public function filter_cron_schedules( $schedules ) {

		$config = SC_Config::factory()->get();

		$interval = DAY_IN_SECONDS;

		if ( ! empty( $config['page_cache_length'] ) && $config['page_cache_length'] > 0 ) {

			$interval = $config['page_cache_length'] * MINUTE_IN_SECONDS;

			if ( ! empty( $config['page_cache_length'] ) && 'hours' === $config['page_cache_length_unit'] ) {
				$interval = $config['page_cache_length'] * HOUR_IN_SECONDS;
			} elseif ( ! empty( $config['page_cache_length'] ) && 'days' === $config['page_cache_length_unit'] ) {
				$interval = $config['page_cache_length'] * DAY_IN_SECONDS;
			} elseif ( ! empty( $config['page_cache_length'] ) && 'weeks' === $config['page_cache_length_unit'] ) {
				$interval = $config['page_cache_length'] * WEEK_IN_SECONDS;
			}
		}

		$schedules['simple_cache'] = array(
			'interval' => apply_filters( 'sc_cache_purge_interval', $interval ),
			'display'  => esc_html__( 'Simple Cache Purge Interval', 'simple-cache' ),
		);
		return $schedules;
	}

	/**
	 * Unschedule events
	 *
	 */
	public function unschedule_events() {
		$timestamp = wp_next_scheduled( 'sc_purge_cache' );

		wp_unschedule_event( $timestamp, 'sc_purge_cache' );
	}

	/**
	 * Setup cron jobs
	 *
	 */
	public function schedule_events() {

		$config = SC_Config::factory()->get();

		$timestamp = wp_next_scheduled( 'sc_purge_cache' );

		// Expire cache never.
		if ( isset( $config['page_cache_length'] ) && 0 === $config['page_cache_length'] ) {
			wp_unschedule_event( $timestamp, 'sc_purge_cache' );
			return;
		}

		if ( ! $timestamp ) {
			wp_schedule_event( time(), 'simple_cache', 'sc_purge_cache' );
		}
	}

	/**
	 * Initiate a cache purge
	 *
	 */
	public function purge_cache() {

		sc_cache_flush();
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
