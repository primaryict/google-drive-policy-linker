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

		if ( $this->is_likely_bot_request() ) {
			wp_send_json_success( array( 'ignored' => 'bot' ) );
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
				'clicked_at' => $this->get_current_datetime(),
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
			$hours_since_last = $this->get_hours_since_datetime( $last_alert );
			if ( $hours_since_last < $cooldown ) {
				return; // Still within the cooldown period; don't send again.
			}
		}

		$analysis = $this->get_gemini_analysis_for_alert( $window_hours );

		if ( is_array( $analysis ) && empty( $analysis['send_alert'] ) ) {
			update_option( 'gdv_last_alert_sent', $this->get_current_datetime() );
			return;
		}

		$this->send_alert_email( $count, $window_hours, false, $analysis );
		update_option( 'gdv_last_alert_sent', $this->get_current_datetime() );
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
	 * Returns daily click series for the most-clicked documents.
	 *
	 * @param int $days  Number of days to include.
	 * @param int $limit Maximum documents to chart.
	 * @return array
	 */
	public function get_daily_file_click_series( $days = 14, $limit = 6 ) {
		global $wpdb;

		$table = $wpdb->prefix . GDV_TABLE_NAME;
		$days  = max( 1, (int) $days );
		$limit = max( 1, (int) $limit );
		$since = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT file_name, clicked_at FROM {$table} WHERE clicked_at >= %s ORDER BY clicked_at ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$since
			)
		);

		$dates      = array();
		$start_date = new DateTimeImmutable( 'today -' . ( $days - 1 ) . ' days', $this->get_display_timezone() );

		for ( $i = 0; $i < $days; $i++ ) {
			$date = $start_date->modify( '+' . $i . ' days' )->format( 'Y-m-d' );
			$dates[ $date ] = 0;
		}

		$totals = array();
		$counts = array();

		foreach ( $rows as $row ) {
			$file_name = ! empty( $row->file_name ) ? $row->file_name : __( 'Unknown document', 'gdrive-folder-viewer' );
			$date      = $this->convert_utc_to_display_datetime( $row->clicked_at )->format( 'Y-m-d' );

			if ( ! isset( $dates[ $date ] ) ) {
				continue;
			}

			if ( ! isset( $counts[ $file_name ] ) ) {
				$counts[ $file_name ] = 0;
				$totals[ $file_name ] = $dates;
			}

			$counts[ $file_name ]++;
			$totals[ $file_name ][ $date ]++;
		}

		arsort( $counts );
		$selected = array_slice( array_keys( $counts ), 0, $limit );
		$series   = array();

		foreach ( $selected as $file_name ) {
			$series[] = array(
				'name'   => $file_name,
				'counts' => array_values( $totals[ $file_name ] ),
				'total'  => (int) $counts[ $file_name ],
			);
		}

		return array(
			'labels' => array_map( array( $this, 'format_graph_label' ), array_keys( $dates ) ),
			'series' => $series,
		);
	}

	/**
	 * Builds an SVG line graph for daily document clicks.
	 *
	 * @param int $days   Number of days to include.
	 * @param int $limit  Maximum documents to chart.
	 * @param int $width  SVG width.
	 * @param int $height SVG height.
	 * @return string
	 */
	public function get_click_graph_svg( $days = 14, $limit = 6, $width = 900, $height = 360 ) {
		$data   = $this->get_daily_file_click_series( $days, $limit );
		$labels = $data['labels'];
		$series = $data['series'];
		$colors = array( '#00809b', '#e10713', '#193255', '#7c3aed', '#15803d', '#c2410c' );
		$max    = 1;

		foreach ( $series as $line ) {
			$max = max( $max, max( $line['counts'] ) );
		}

		$tick_count = 4;
		$tick_step  = max( 1, (int) ceil( $max / $tick_count ) );
		$axis_max   = $tick_step * $tick_count;

		$left = 50;
		$top = 28;
		$right = 24;
		$bottom = 82;
		$plot_width = $width - $left - $right;
		$plot_height = $height - $top - $bottom;
		$label_count = max( 1, count( $labels ) - 1 );
		$svg = '<svg class="gdv-click-graph" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . (int) $width . ' ' . (int) $height . '" role="img" aria-label="Policy document clicks by day">';
		$svg .= '<rect width="100%" height="100%" fill="#ffffff"/>';
		$svg .= '<line x1="' . $left . '" y1="' . ( $top + $plot_height ) . '" x2="' . ( $left + $plot_width ) . '" y2="' . ( $top + $plot_height ) . '" stroke="#d9e2ec"/>';
		$svg .= '<line x1="' . $left . '" y1="' . $top . '" x2="' . $left . '" y2="' . ( $top + $plot_height ) . '" stroke="#d9e2ec"/>';

		for ( $i = 0; $i <= $tick_count; $i++ ) {
			$value = $tick_step * $i;
			$y = $top + $plot_height - ( $plot_height * $i / $tick_count );
			$svg .= '<line x1="' . $left . '" y1="' . $y . '" x2="' . ( $left + $plot_width ) . '" y2="' . $y . '" stroke="#eef2f7"/>';
			$svg .= '<text x="' . ( $left - 10 ) . '" y="' . ( $y + 4 ) . '" text-anchor="end" font-size="11" fill="#526579">' . $value . '</text>';
		}

		foreach ( $labels as $i => $label ) {
			if ( 0 !== $i && $i !== count( $labels ) - 1 && 0 !== $i % max( 1, (int) ceil( count( $labels ) / 7 ) ) ) {
				continue;
			}
			$x = $left + ( $plot_width * $i / $label_count );
			$svg .= '<text x="' . $x . '" y="' . ( $top + $plot_height + 22 ) . '" text-anchor="middle" font-size="11" fill="#526579">' . esc_html( $label ) . '</text>';
		}

		foreach ( $series as $index => $line ) {
			$points = array();
			foreach ( $line['counts'] as $i => $count ) {
				$x = $left + ( $plot_width * $i / $label_count );
				$y = $top + $plot_height - ( $plot_height * (int) $count / $axis_max );
				$points[] = round( $x, 2 ) . ',' . round( $y, 2 );
			}
			$color = $colors[ $index % count( $colors ) ];
			$svg .= '<polyline fill="none" stroke="' . esc_attr( $color ) . '" stroke-width="3" points="' . esc_attr( implode( ' ', $points ) ) . '"/>';
			foreach ( $points as $point ) {
				list( $x, $y ) = array_map( 'floatval', explode( ',', $point ) );
				$svg .= '<circle cx="' . $x . '" cy="' . $y . '" r="3" fill="' . esc_attr( $color ) . '"/>';
			}
		}

		if ( empty( $series ) ) {
			$svg .= '<text x="' . ( $width / 2 ) . '" y="' . ( $height / 2 ) . '" text-anchor="middle" font-size="15" fill="#526579">No click data yet</text>';
		}

		$legend_y = $height - 38;
		foreach ( $series as $index => $line ) {
			$x = $left + ( $index % 2 ) * 420;
			$y = $legend_y + ( 18 * floor( $index / 2 ) );
			$label = strlen( $line['name'] ) > 46 ? substr( $line['name'], 0, 43 ) . '...' : $line['name'];
			$svg .= '<rect x="' . $x . '" y="' . ( $y - 10 ) . '" width="10" height="10" fill="' . esc_attr( $colors[ $index % count( $colors ) ] ) . '"/>';
			$svg .= '<text x="' . ( $x + 16 ) . '" y="' . $y . '" font-size="11" fill="#193255">' . esc_html( $label ) . '</text>';
		}

		$svg .= '</svg>';

		return $svg;
	}

	/**
	 * Sends the threshold-exceeded alert email to the configured recipients.
	 *
	 * @param int  $count        Number of clicks in the window.
	 * @param int  $window_hours The lookback window, in hours.
	 * @param bool  $is_test      Whether this is a manually triggered test email.
	 * @param array $analysis     Optional Gemini analysis.
	 * @return bool Whether wp_mail() reported success.
	 */
	public function send_alert_email( $count, $window_hours, $is_test = false, $analysis = array() ) {
		$top_files = $this->get_top_files( $window_hours, 10 );
		$site_name = get_bloginfo( 'name' );

		$subject = $is_test
			? sprintf( '[%s] TEST: policy document activity alert', $site_name )
			: sprintf( '[%s] Unusual policy document activity detected', $site_name );

		$graph_svg = $this->get_click_graph_svg( 14, 6, 900, 360 );
		$body      = $this->get_alert_email_body( $site_name, $count, $window_hours, $top_files, $graph_svg, $is_test, $analysis );

		$recipients = $this->get_recipient_emails();
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		$attachment = $this->create_graph_attachment( $graph_svg );
		$attachments = $attachment ? array( $attachment ) : array();

		$sent = wp_mail( $recipients, $subject, $body, $headers, $attachments );

		if ( $attachment ) {
			wp_delete_file( $attachment );
		}

		return $sent;
	}

	/**
	 * Builds the branded alert email body.
	 *
	 * @param string $site_name    Website name.
	 * @param int    $count        Number of clicks in the window.
	 * @param int    $window_hours The lookback window, in hours.
	 * @param array  $top_files    Most-clicked files.
	 * @param string $graph_svg    Inline SVG graph markup.
	 * @param bool   $is_test      Whether this is a test email.
	 * @param array  $analysis     Optional analysis result wrapper.
	 * @return string
	 */
	private function get_alert_email_body( $site_name, $count, $window_hours, $top_files, $graph_svg, $is_test, $analysis ) {
		$stats_url          = admin_url( 'admin.php?page=gdv-settings&tab=stats' );
		$assessment_summary = $this->get_alert_assessment_summary( $analysis );
		$test_banner        = $is_test ? '<div style="background:#fff8e5;border-left:4px solid #dba617;color:#7a5600;margin:0 0 18px;padding:12px 14px;"><strong>Test alert:</strong> This email was triggered manually, using the same recent click data and review process as a natural threshold alert.</div>' : '';

		$body  = '<div style="background:#f3f7fb;margin:0;padding:24px;font-family:Arial,Helvetica,sans-serif;color:#193255;">';
		$body .= '<div style="background:#ffffff;border-top:6px solid #00809b;margin:0 auto;max-width:760px;padding:0;">';
		$body .= '<div style="padding:24px 28px 18px;">';
		$body .= '<p style="color:#00809b;font-size:12px;font-weight:700;letter-spacing:0;margin:0 0 8px;text-transform:uppercase;">Primary ICT Support</p>';
		$body .= '<h1 style="color:#193255;font-size:24px;line-height:1.25;margin:0;">Policy document activity alert</h1>';
		$body .= '<p style="color:#526579;font-size:15px;line-height:1.6;margin:12px 0 0;">An unusual level of policy document activity has been detected on ' . esc_html( $site_name ) . '.</p>';
		$body .= '</div>';
		$body .= '<div style="padding:0 28px 24px;">';
		$body .= $test_banner;
		$body .= '<div style="background:#f3f7fb;border:1px solid #d9e2ec;margin:0 0 18px;padding:16px 18px;">';
		$body .= '<p style="font-size:15px;line-height:1.6;margin:0;">There were <strong style="color:#e10713;">' . esc_html( (string) (int) $count ) . '</strong> policy document click(s) in the last <strong>' . esc_html( (string) (int) $window_hours ) . ' hour(s)</strong>. ' . esc_html( $assessment_summary ) . '</p>';
		$body .= '</div>';
		$body .= '<h2 style="color:#193255;font-size:18px;margin:22px 0 10px;">Most viewed documents</h2>';
		$body .= $this->get_top_files_email_table( $top_files );
		$body .= '<h2 style="color:#193255;font-size:18px;margin:24px 0 10px;">Recent policy click activity</h2>';
		$body .= '<div style="border:1px solid #d9e2ec;margin:0 0 18px;overflow-x:auto;padding:8px;">' . $graph_svg . '</div>';
		$body .= '<p style="color:#526579;font-size:14px;line-height:1.6;margin:0 0 18px;">A sudden spike in policy document views can sometimes precede an Ofsted visit or inspection, as parents, staff or inspectors may review policies beforehand. It would be sensible to review the recent activity and check that published policies are up to date.</p>';
		$body .= '<p style="margin:0;"><a href="' . esc_url( $stats_url ) . '" style="background:#00809b;color:#ffffff;display:inline-block;font-weight:700;padding:10px 16px;text-decoration:none;">View detailed stats</a></p>';
		$body .= '</div>';
		$body .= '</div>';
		$body .= '</div>';

		return $body;
	}

	/**
	 * Builds a natural-language assessment summary for alert emails.
	 *
	 * @param array $analysis Optional analysis result wrapper.
	 * @return string
	 */
	private function get_alert_assessment_summary( $analysis ) {
		if ( ! empty( $analysis['analysis'] ) && is_array( $analysis['analysis'] ) ) {
			$result     = $analysis['analysis'];
			$confidence = isset( $result['confidence_score'] ) ? (int) $result['confidence_score'] : 0;
			$threshold  = max( 0, min( 100, (int) get_option( 'gdv_gemini_confidence_threshold', 75 ) ) );
			$reason     = ! empty( $result['reason'] ) ? $result['reason'] : __( 'the recent pattern is unusual enough to warrant a closer look', 'gdrive-folder-viewer' );
			$action     = ! empty( $result['recommended_action'] ) ? $result['recommended_action'] : __( 'Please review the latest click activity when convenient.', 'gdrive-folder-viewer' );

			if ( empty( $result['inspection_likely'] ) || $confidence < $threshold ) {
				return sprintf(
					'The activity pattern was reviewed and does not currently meet the configured alert confidence threshold. The review confidence was %1$d%% against a %2$d%% threshold: %3$s. %4$s',
					$confidence,
					$threshold,
					$reason,
					$action
				);
			}

			return sprintf(
				'The activity pattern was reviewed and is considered worth attention with %1$d%% confidence: %2$s. %3$s',
				$confidence,
				$reason,
				$action
			);
		}

		if ( ! empty( $analysis['error'] ) ) {
			return sprintf(
				'The follow-up activity review could not be completed, so this alert has been sent from the click threshold alone. Review detail: %s',
				$analysis['error']
			);
		}

		return 'This alert has been sent because the configured click threshold was reached.';
	}

	/**
	 * Builds the top files table for alert emails.
	 *
	 * @param array $top_files Most-clicked files.
	 * @return string
	 */
	private function get_top_files_email_table( $top_files ) {
		if ( empty( $top_files ) ) {
			return '<p style="color:#526579;font-size:14px;margin:0 0 18px;">No individual file data was available for this period.</p>';
		}

		$html  = '<table role="presentation" style="border-collapse:collapse;margin:0 0 18px;width:100%;">';
		$html .= '<thead><tr>';
		$html .= '<th align="left" style="background:#f3f7fb;border:1px solid #d9e2ec;color:#193255;padding:10px;">Document</th>';
		$html .= '<th align="left" style="background:#f3f7fb;border:1px solid #d9e2ec;color:#193255;padding:10px;width:90px;">Clicks</th>';
		$html .= '</tr></thead><tbody>';

		foreach ( $top_files as $row ) {
			$html .= '<tr>';
			$html .= '<td style="border:1px solid #d9e2ec;color:#193255;padding:10px;">' . esc_html( $row->file_name ) . '</td>';
			$html .= '<td style="border:1px solid #d9e2ec;color:#193255;padding:10px;">' . esc_html( (string) (int) $row->clicks ) . '</td>';
			$html .= '</tr>';
		}

		$html .= '</tbody></table>';

		return $html;
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
	 * Runs Gemini analysis when configured and decides whether to send alert.
	 *
	 * @param int $window_hours Lookback window.
	 * @return array
	 */
	public function get_gemini_analysis_for_alert( $window_hours ) {
		$analyzer = new GDV_Gemini_Analyzer();

		if ( ! $analyzer->is_configured() ) {
			return array(
				'send_alert' => true,
			);
		}

		$analysis = $analyzer->analyze_recent_activity( $window_hours );

		if ( is_wp_error( $analysis ) ) {
			return array(
				'send_alert' => true,
				'error'      => $analysis->get_error_message(),
			);
		}

		return array(
			'send_alert' => $analyzer->should_send_alert( $analysis ),
			'analysis'   => $analysis,
		);
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
	 * Returns whether the current click request appears to be from a bot.
	 *
	 * @return bool
	 */
	private function is_likely_bot_request() {
		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) ) : '';

		if ( '' === $user_agent ) {
			return false;
		}

		$needles = array( 'bot', 'crawl', 'spider', 'slurp', 'bingpreview', 'facebookexternalhit', 'whatsapp', 'telegrambot', 'discordbot', 'preview', 'scanner' );

		foreach ( $needles as $needle ) {
			if ( false !== strpos( $user_agent, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns the UTC datetime used for click-window comparisons.
	 *
	 * @param int $hours Lookback window, in hours.
	 * @return string
	 */
	private function get_since_datetime( $hours ) {
		return gmdate( 'Y-m-d H:i:s', time() - ( max( 1, (int) $hours ) * HOUR_IN_SECONDS ) );
	}

	/**
	 * Returns the current UTC datetime.
	 *
	 * @return string
	 */
	private function get_current_datetime() {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Returns hours elapsed since a stored site-local datetime.
	 *
	 * @param string $mysql_datetime Stored datetime.
	 * @return float
	 */
	private function get_hours_since_datetime( $mysql_datetime ) {
		$datetime = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $mysql_datetime, new DateTimeZone( 'UTC' ) );

		if ( false === $datetime ) {
			return PHP_INT_MAX;
		}

		return ( time() - $datetime->getTimestamp() ) / HOUR_IN_SECONDS;
	}

	/**
	 * Converts a stored UTC datetime to the display timezone.
	 *
	 * @param string $mysql_datetime Stored UTC datetime.
	 * @return DateTimeImmutable
	 */
	private function convert_utc_to_display_datetime( $mysql_datetime ) {
		$datetime = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $mysql_datetime, new DateTimeZone( 'UTC' ) );

		if ( false === $datetime ) {
			$datetime = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		}

		return $datetime->setTimezone( $this->get_display_timezone() );
	}

	/**
	 * Returns the UK display timezone used for client reporting.
	 *
	 * @return DateTimeZone
	 */
	private function get_display_timezone() {
		return new DateTimeZone( 'Europe/London' );
	}

	/**
	 * Creates a temporary SVG graph attachment for alert emails.
	 *
	 * @param string $svg SVG markup.
	 * @return string
	 */
	private function create_graph_attachment( $svg ) {
		$file = wp_tempnam( 'gdv-click-graph.svg' );

		if ( ! $file ) {
			return '';
		}

		$svg_file = $file . '.svg';

		if ( false === file_put_contents( $svg_file, $svg ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			wp_delete_file( $file );
			return '';
		}

		wp_delete_file( $file );

		return $svg_file;
	}

	/**
	 * Formats graph date labels.
	 *
	 * @param string $date Date in Y-m-d format.
	 * @return string
	 */
	private function format_graph_label( $date ) {
		$datetime = DateTimeImmutable::createFromFormat( 'Y-m-d', $date, $this->get_display_timezone() );

		return $datetime ? $datetime->format( 'j M' ) : $date;
	}
}
