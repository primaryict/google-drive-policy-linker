<?php
/**
 * Logs clicks on policy documents and emails admins when activity within a
 * rolling time window crosses a configured threshold.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GDV_Click_Tracker {

	public function __construct() {
		add_action( 'wp_ajax_gdv_track_click', array( $this, 'handle_track_click' ) );
		add_action( 'wp_ajax_nopriv_gdv_track_click', array( $this, 'handle_track_click' ) );
		add_action( 'gdv_check_click_threshold', array( $this, 'check_threshold' ) );
	}

	/**
	 * AJAX handler: records a single click against the database.
	 *
	 * @return void
	 */
	public function handle_track_click() {
		check_ajax_referer( 'gdv_track_click', 'nonce' );

		$file_id   = isset( $_POST['file_id'] ) ? sanitize_text_field( wp_unslash( $_POST['file_id'] ) ) : '';
		$file_name = isset( $_POST['file_name'] ) ? sanitize_text_field( wp_unslash( $_POST['file_name'] ) ) : '';
		$folder_id = isset( $_POST['folder_id'] ) ? sanitize_text_field( wp_unslash( $_POST['folder_id'] ) ) : '';
		$ip_address = $this->get_visitor_ip_address();

		if ( empty( $file_id ) ) {
			wp_send_json_error( array( 'message' => 'missing_file_id' ) );
		}

		global $wpdb;
		$table = $wpdb->prefix . GDV_TABLE_NAME;
		$this->maybe_create_table();

		$inserted = $wpdb->insert(
			$table,
			array(
				'folder_id'  => $folder_id,
				'file_id'    => $file_id,
				'file_name'  => $file_name,
				'ip_address' => $ip_address,
				'clicked_at' => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			wp_send_json_error( array( 'message' => 'insert_failed' ) );
		}

		$this->check_threshold();

		wp_send_json_success();
	}

	/**
	 * Counts clicks within the configured rolling window and,
	 * if the threshold is met and we're outside the cooldown period, emails
	 * the configured admin addresses.
	 *
	 * @return void
	 */
	public function check_threshold() {
		$window_hours = max( 1, (int) get_option( 'gdv_threshold_window_hours', 24 ) );
		$threshold    = max( 1, (int) get_option( 'gdv_click_threshold', 20 ) );
		$cooldown     = max( 0, (int) get_option( 'gdv_alert_cooldown_hours', 24 ) );

		$count = $this->get_click_count( $window_hours );

		if ( $count < $threshold ) {
			return;
		}

		$last_alert = get_option( 'gdv_last_alert_sent', '' );
		if ( ! empty( $last_alert ) ) {
			$hours_since_last = ( current_time( 'timestamp' ) - strtotime( $last_alert ) ) / HOUR_IN_SECONDS;
			if ( $hours_since_last < $cooldown ) {
				return; // Still within the cooldown period; don't send again.
			}
		}

		$this->send_alert_email( $count, $window_hours );
		update_option( 'gdv_last_alert_sent', current_time( 'mysql' ) );
	}

	/**
	 * Counts clicks recorded within the last N hours.
	 *
	 * @param int $hours Lookback window, in hours.
	 * @return int
	 */
	public function get_click_count( $hours ) {
		global $wpdb;
		$table = $wpdb->prefix . GDV_TABLE_NAME;
		$since = $this->get_since_datetime( $hours );

		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE clicked_at >= %s", $since ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * Returns the most-clicked files within the last N hours.
	 *
	 * @param int $hours Lookback window, in hours.
	 * @param int $limit Maximum rows to return.
	 * @return array
	 */
	public function get_top_files( $hours, $limit = 10 ) {
		global $wpdb;
		$table = $wpdb->prefix . GDV_TABLE_NAME;
		$since = $this->get_since_datetime( $hours );

		$sql = $wpdb->prepare(
			"SELECT file_name, COUNT(*) as clicks FROM {$table} WHERE clicked_at >= %s GROUP BY file_id, file_name ORDER BY clicks DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$since,
			(int) $limit
		);

		return $wpdb->get_results( $sql );
	}

	/**
	 * Sends the threshold-exceeded alert email to the configured recipients.
	 *
	 * @param int  $count        Number of clicks in the window.
	 * @param int  $window_hours The lookback window, in hours.
	 * @param bool $is_test      Whether this is a manually triggered test email.
	 * @return bool Whether wp_mail() reported success.
	 */
	public function send_alert_email( $count, $window_hours, $is_test = false ) {
		$top_files = $this->get_top_files( $window_hours, 10 );
		$site_name = get_bloginfo( 'name' );

		$subject = $is_test
			? sprintf( '[%s] TEST: policy document activity alert', $site_name )
			: sprintf( '[%s] Unusual policy document activity detected', $site_name );

		$lines = array();
		if ( $is_test ) {
			$lines[] = 'This is a TEST email triggered manually from the plugin settings page.';
			$lines[] = '';
		}
		$lines[] = sprintf( 'An unusual level of activity has been detected on policy documents on %s.', $site_name );
		$lines[] = '';
		$lines[] = sprintf( 'Total clicks in the last %d hour(s): %d', $window_hours, $count );
		$lines[] = '';
		$lines[] = 'Most viewed documents in this period:';

		if ( ! empty( $top_files ) ) {
			foreach ( $top_files as $row ) {
				$lines[] = sprintf( '- %s: %d click(s)', $row->file_name, (int) $row->clicks );
			}
		} else {
			$lines[] = '(no individual file data available)';
		}

		$lines[] = '';
		$lines[] = 'A sudden spike in policy document views can sometimes precede an Ofsted visit or inspection, as parents, staff or inspectors often review policies beforehand. You may wish to review recent activity and double-check that your published policies are up to date.';
		$lines[] = '';
		$lines[] = 'View detailed stats: ' . admin_url( 'admin.php?page=gdv-settings&tab=stats' );

		$body = implode( "\n", $lines );

		$recipients = $this->get_recipient_emails();

		return wp_mail( $recipients, $subject, $body );
	}

	/**
	 * Returns the list of admin email addresses configured to receive alerts.
	 *
	 * @return array
	 */
	private function get_recipient_emails() {
		$setting = get_option( 'gdv_alert_emails', get_option( 'admin_email' ) );
		$emails  = array_filter( array_map( 'trim', explode( ',', (string) $setting ) ) );
		$emails  = array_filter( $emails, 'is_email' );

		if ( empty( $emails ) ) {
			$emails = array( get_option( 'admin_email' ) );
		}

		return $emails;
	}

	/**
	 * Creates or upgrades the click table if activation did not run cleanly.
	 *
	 * @return void
	 */
	private function maybe_create_table() {
		global $wpdb;

		$table = $wpdb->prefix . GDV_TABLE_NAME;
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		if ( $table === $found && $this->has_ip_address_column( $table ) ) {
			return;
		}

		GDV_Activator::activate();
	}

	/**
	 * Checks whether the click table has the IP address column.
	 *
	 * @param string $table Table name.
	 * @return bool
	 */
	private function has_ip_address_column( $table ) {
		global $wpdb;

		$column = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'ip_address' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return 'ip_address' === $column;
	}

	/**
	 * Returns the visitor IP address supplied by the web server.
	 *
	 * @return string
	 */
	private function get_visitor_ip_address() {
		$ip_address = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		return substr( $ip_address, 0, 45 );
	}

	/**
	 * Returns the site-local datetime used for click-window comparisons.
	 *
	 * @param int $hours Lookback window, in hours.
	 * @return string
	 */
	private function get_since_datetime( $hours ) {
		$timestamp = current_time( 'timestamp' ) - ( max( 1, (int) $hours ) * HOUR_IN_SECONDS );

		return date( 'Y-m-d H:i:s', $timestamp );
	}
}
