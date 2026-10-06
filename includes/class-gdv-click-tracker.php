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
		add_action( 'gdv_retry_review', array( $this, 'check_threshold' ) );
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

		if ( self::is_whitelisted_ip( $ip_address ) ) {
			wp_send_json_success( array( 'ignored' => 'whitelisted_ip' ) );
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
		$lock = GDV_Request_Guard::acquire( 'gdv_review_lock', 300 );
		if ( ! $lock ) { return; }
		try {
			$this->process_threshold_review();
		} finally {
			GDV_Request_Guard::release( 'gdv_review_lock', $lock );
		}
	}

	private function schedule_review( $when ) {
		wp_clear_scheduled_hook( 'gdv_retry_review' );
		wp_schedule_single_event( $when, 'gdv_retry_review' );
	}

	private function process_threshold_review() {
		$pending = get_option( 'gdv_pending_review', array() );
		if ( $pending && (int) $pending['next'] > time() ) {
			if ( ! wp_next_scheduled( 'gdv_retry_review' ) ) { $this->schedule_review( (int) $pending['next'] ); }
			return;
		}
		$window_hours = max( 1, (int) get_option( 'gdv_threshold_window_hours', 24 ) );
		$threshold    = max( 1, (int) get_option( 'gdv_click_threshold', 20 ) );
		$cooldown     = max( 0, (int) get_option( 'gdv_alert_cooldown_hours', 24 ) );

		$count = $this->get_click_count( $window_hours );

		if ( ! $pending && $count < $threshold ) {
			return;
		}

		$last_alert = get_option( 'gdv_last_alert_sent', '' );
		if ( ! $pending && ! empty( $last_alert ) ) {
			$hours_since_last = $this->get_hours_since_datetime( $last_alert );
			if ( $hours_since_last < $cooldown ) {
				return; // Still within the cooldown period; don't send again.
			}
		}

		if ( ! $pending ) { $pending = array( 'attempts' => 0, 'count' => $count, 'window' => $window_hours ); }
		$count = $pending['count'];
		$window_hours = $pending['window'];
		// Save a watchdog before network I/O so a crashed worker can recover.
		$pending['attempts']++;
		$pending['next'] = time() + 310;
		update_option( 'gdv_pending_review', $pending, false );
		$this->schedule_review( $pending['next'] );
		$analysis = $pending['attempts'] <= 3
			? $this->get_gemini_analysis_for_alert( $window_hours, 'automatic', $count )
			: array( 'error' => 'The previous review attempts did not complete.', 'error_data' => array( 'terminal' => true ) );
		$data = $analysis['error_data'] ?? array();
		if ( ! empty( $data['wait'] ) ) { $pending['attempts']--; }
		if ( ! empty( $analysis['error'] ) && empty( $data['terminal'] ) && $pending['attempts'] < 3 ) {
			$pending['next'] = time() + max( 60 * max( 1, $pending['attempts'] ), (int) ( $data['delay'] ?? 60 ) ) + wp_rand( 5, 15 );
			update_option( 'gdv_pending_review', $pending, false );
			$this->schedule_review( $pending['next'] );
			return;
		}
		// Claim completion before mail I/O; parallel checks must not resend it.
		update_option( 'gdv_last_alert_sent', $this->get_current_datetime() );
		delete_option( 'gdv_pending_review' );
		wp_clear_scheduled_hook( 'gdv_retry_review' );
		if ( ! empty( $analysis['error'] ) ) {
			$analysis['error'] = sprintf( 'The automated review could not be completed after %d attempt(s). No assessment of this activity is available. Please review the click stats manually. %s', min( 3, $pending['attempts'] ), $analysis['error'] );
			$analysis['review_failed'] = true;
			$analysis['send_alert'] = true;
		}
		if ( ! empty( $analysis['send_alert'] ) ) {
			$sent = $this->send_alert_email( $count, $window_hours, false, $analysis );
			GDV_Gemini_Log::insert( array( 'source' => 'automatic', 'status' => $sent ? 'complete' : 'error', 'trigger_count' => $count, 'trigger_window_hours' => $window_hours, 'alert_sent' => $sent, 'error_message' => $analysis['error'] ?? ( $sent ? '' : 'WordPress could not send the alert email.' ) ) );
		}
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
	 * Creates a PNG graph in uploads and returns its public URL for email use.
	 *
	 * @param int $days   Number of days to include.
	 * @param int $limit  Maximum documents to chart.
	 * @param int $width  Image width.
	 * @param int $height Image height.
	 * @return string
	 */
	public function get_click_graph_image_url( $days = 14, $limit = 6, $width = 900, $height = 360 ) {
		if ( ! function_exists( 'imagecreatetruecolor' ) || ! function_exists( 'imagepng' ) ) {
			return '';
		}

		$upload = wp_upload_dir();
		if ( ! empty( $upload['error'] ) ) {
			return '';
		}

		$dir = trailingslashit( $upload['basedir'] ) . 'gdv-email-graphs';
		$url = trailingslashit( $upload['baseurl'] ) . 'gdv-email-graphs';

		if ( ! wp_mkdir_p( $dir ) ) {
			return '';
		}

		$this->cleanup_old_graph_images( $dir );

		$file_name = 'policy-clicks-' . time() . '-' . wp_generate_password( 8, false, false ) . '.png';
		$file_path = trailingslashit( $dir ) . $file_name;
		$image     = $this->create_click_graph_png_resource( $days, $limit, $width, $height );

		if ( ! $image ) {
			return '';
		}

		$saved = imagepng( $image, $file_path );
		imagedestroy( $image );

		if ( ! $saved ) {
			return '';
		}

		return trailingslashit( $url ) . $file_name;
	}

	/**
	 * Draws the click graph as a PNG image resource.
	 *
	 * @param int $days   Number of days to include.
	 * @param int $limit  Maximum documents to chart.
	 * @param int $width  Image width.
	 * @param int $height Image height.
	 * @return resource|GdImage|false
	 */
	private function create_click_graph_png_resource( $days, $limit, $width, $height ) {
		$data   = $this->get_daily_file_click_series( $days, $limit );
		$labels = $data['labels'];
		$series = $data['series'];
		$image  = imagecreatetruecolor( $width, $height );

		if ( ! $image ) {
			return false;
		}

		$white  = imagecolorallocate( $image, 255, 255, 255 );
		$navy   = imagecolorallocate( $image, 25, 50, 85 );
		$muted  = imagecolorallocate( $image, 82, 101, 121 );
		$border = imagecolorallocate( $image, 217, 226, 236 );
		$grid   = imagecolorallocate( $image, 238, 242, 247 );
		$colors = array(
			imagecolorallocate( $image, 0, 128, 155 ),
			imagecolorallocate( $image, 225, 7, 19 ),
			imagecolorallocate( $image, 25, 50, 85 ),
			imagecolorallocate( $image, 124, 58, 237 ),
			imagecolorallocate( $image, 21, 128, 61 ),
			imagecolorallocate( $image, 194, 65, 12 ),
		);

		imagefill( $image, 0, 0, $white );

		$max = 1;
		foreach ( $series as $line ) {
			$max = max( $max, max( $line['counts'] ) );
		}

		$tick_count  = 4;
		$tick_step   = max( 1, (int) ceil( $max / $tick_count ) );
		$axis_max    = $tick_step * $tick_count;
		$left        = 50;
		$top         = 28;
		$right       = 24;
		$bottom      = 82;
		$plot_width  = $width - $left - $right;
		$plot_height = $height - $top - $bottom;
		$label_count = max( 1, count( $labels ) - 1 );

		imageline( $image, $left, $top + $plot_height, $left + $plot_width, $top + $plot_height, $border );
		imageline( $image, $left, $top, $left, $top + $plot_height, $border );

		for ( $i = 0; $i <= $tick_count; $i++ ) {
			$value = $tick_step * $i;
			$y     = (int) round( $top + $plot_height - ( $plot_height * $i / $tick_count ) );
			imageline( $image, $left, $y, $left + $plot_width, $y, $grid );
			imagestring( $image, 2, 8, $y - 7, (string) $value, $muted );
		}

		foreach ( $labels as $i => $label ) {
			if ( 0 !== $i && $i !== count( $labels ) - 1 && 0 !== $i % max( 1, (int) ceil( count( $labels ) / 7 ) ) ) {
				continue;
			}

			$x = (int) round( $left + ( $plot_width * $i / $label_count ) );
			imagestring( $image, 2, max( 0, $x - 14 ), $top + $plot_height + 12, $label, $muted );
		}

		foreach ( $series as $index => $line ) {
			$points = array();
			foreach ( $line['counts'] as $i => $count ) {
				$points[] = array(
					'x' => (int) round( $left + ( $plot_width * $i / $label_count ) ),
					'y' => (int) round( $top + $plot_height - ( $plot_height * (int) $count / $axis_max ) ),
				);
			}

			$color = $colors[ $index % count( $colors ) ];
			for ( $i = 1; $i < count( $points ); $i++ ) {
				$this->draw_thick_line( $image, $points[ $i - 1 ]['x'], $points[ $i - 1 ]['y'], $points[ $i ]['x'], $points[ $i ]['y'], $color, 3 );
			}

			foreach ( $points as $point ) {
				imagefilledellipse( $image, $point['x'], $point['y'], 7, 7, $color );
			}
		}

		if ( empty( $series ) ) {
			imagestring( $image, 4, (int) ( $width / 2 - 65 ), (int) ( $height / 2 ), 'No click data yet', $muted );
		}

		$legend_y = $height - 42;
		foreach ( $series as $index => $line ) {
			$x     = $left + ( $index % 2 ) * 420;
			$y     = $legend_y + ( 18 * floor( $index / 2 ) );
			$label = strlen( $line['name'] ) > 46 ? substr( $line['name'], 0, 43 ) . '...' : $line['name'];
			imagefilledrectangle( $image, $x, $y - 10, $x + 10, $y, $colors[ $index % count( $colors ) ] );
			imagestring( $image, 2, $x + 16, $y - 12, $label, $navy );
		}

		return $image;
	}

	/**
	 * Draws a thicker anti-simple line for the PNG graph.
	 *
	 * @param resource|GdImage $image Image resource.
	 * @param int              $x1    Start x.
	 * @param int              $y1    Start y.
	 * @param int              $x2    End x.
	 * @param int              $y2    End y.
	 * @param int              $color GD colour.
	 * @param int              $width Line width.
	 * @return void
	 */
	private function draw_thick_line( $image, $x1, $y1, $x2, $y2, $color, $width ) {
		for ( $offset = -floor( $width / 2 ); $offset <= floor( $width / 2 ); $offset++ ) {
			imageline( $image, $x1, $y1 + $offset, $x2, $y2 + $offset, $color );
			imageline( $image, $x1 + $offset, $y1, $x2 + $offset, $y2, $color );
		}
	}

	/**
	 * Deletes old generated email graph images.
	 *
	 * @param string $dir Directory path.
	 * @return void
	 */
	private function cleanup_old_graph_images( $dir ) {
		$files = glob( trailingslashit( $dir ) . 'policy-clicks-*.png' );

		if ( empty( $files ) ) {
			return;
		}

		$cutoff = time() - ( 14 * DAY_IN_SECONDS );

		foreach ( $files as $file ) {
			if ( is_file( $file ) && filemtime( $file ) < $cutoff ) {
				wp_delete_file( $file );
			}
		}
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
		if ( ! empty( $analysis['review_failed'] ) ) { $subject = sprintf( '[%s] Policy activity review unavailable', $site_name ); }

		$graph_url = $this->get_click_graph_image_url( 14, 6, 900, 360 );
		$body      = $this->get_alert_email_body( $site_name, $count, $window_hours, $top_files, $graph_url, $is_test, $analysis );

		$recipients = $this->get_recipient_emails();
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		return wp_mail( $recipients, $subject, $body, $headers );
	}

	/**
	 * Builds the branded alert email body.
	 *
	 * @param string $site_name    Website name.
	 * @param int    $count        Number of clicks in the window.
	 * @param int    $window_hours The lookback window, in hours.
	 * @param array  $top_files    Most-clicked files.
	 * @param string $graph_url    Graph image URL.
	 * @param bool   $is_test      Whether this is a test email.
	 * @param array  $analysis     Optional analysis result wrapper.
	 * @return string
	 */
	private function get_alert_email_body( $site_name, $count, $window_hours, $top_files, $graph_url, $is_test, $analysis ) {
		$stats_url          = admin_url( 'admin.php?page=gdv-settings&tab=stats' );
		$assessment_summary = $this->get_alert_assessment_summary( $analysis );
		$test_banner        = $is_test ? '<div style="background:#fff8e5;border-left:4px solid #dba617;color:#7a5600;margin:0 0 18px;padding:12px 14px;"><strong>Test alert:</strong> This email was triggered manually, using the same recent click data and review process as a natural threshold alert.</div>' : '';

		$body  = '<div style="background:#f3f7fb;margin:0;padding:24px;font-family:Arial,Helvetica,sans-serif;color:#193255;">';
		$body .= '<div style="background:#ffffff;border-top:6px solid #00809b;margin:0 auto;max-width:760px;padding:0;">';
		$body .= '<div style="padding:24px 28px 18px;">';
		$logo_id = (int) get_theme_mod( 'custom_logo' );
		$logo_url = $logo_id ? wp_get_attachment_image_url( $logo_id, 'medium' ) : false;
		if ( $logo_url ) {
			$body .= '<img src="' . esc_url( $logo_url ) . '" alt="' . esc_attr( $site_name ) . '" width="200" style="display:block;width:200px;max-width:100%;height:auto;margin:0 0 18px;">';
		} else {
			$body .= '<p style="color:#00809b;font-size:12px;font-weight:700;letter-spacing:0;margin:0 0 8px;text-transform:uppercase;">Primary ICT Support</p>';
		}
		$body .= '<h1 style="color:#193255;font-size:24px;line-height:1.25;margin:0;">Policy document activity alert</h1>';
		$body .= '<p style="color:#526579;font-size:15px;line-height:1.6;margin:12px 0 0;">The policy activity review threshold was reached on ' . esc_html( $site_name ) . '.</p>';
		$body .= '</div>';
		$body .= '<div style="padding:0 28px 24px;">';
		$body .= $test_banner;
		$body .= '<div style="background:#f3f7fb;border:1px solid #d9e2ec;margin:0 0 18px;padding:16px 18px;">';
		$body .= '<p style="font-size:15px;line-height:1.6;margin:0;">There were <strong style="color:#e10713;">' . esc_html( (string) (int) $count ) . '</strong> policy document click(s) in the last <strong>' . esc_html( (string) (int) $window_hours ) . ' hour(s)</strong>. ' . esc_html( $assessment_summary ) . '</p>';
		$body .= '</div>';
		$body .= '<h2 style="color:#193255;font-size:18px;margin:22px 0 10px;">Most viewed documents</h2>';
		$body .= $this->get_top_files_email_table( $top_files );
		$body .= '<h2 style="color:#193255;font-size:18px;margin:24px 0 10px;">Recent policy click activity</h2>';
		if ( '' !== $graph_url ) {
			$body .= '<div style="border:1px solid #d9e2ec;margin:0 0 18px;padding:8px;"><img src="' . esc_url( $graph_url ) . '" alt="Recent policy click activity graph" style="display:block;height:auto;max-width:100%;width:100%;"></div>';
		} else {
			$body .= '<p style="background:#f3f7fb;border:1px solid #d9e2ec;color:#526579;font-size:14px;margin:0 0 18px;padding:12px;">The activity graph could not be generated on this site.</p>';
		}
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
			$data_days  = ! empty( $analysis['data_days'] ) ? max( 1, (int) $analysis['data_days'] ) : max( 1, (int) get_option( 'gdv_gemini_data_days', 14 ) );
			$reason     = ! empty( $result['reason'] ) ? $result['reason'] : __( 'the recent pattern is unusual enough to warrant a closer look', 'gdrive-folder-viewer' );
			$action     = ! empty( $result['recommended_action'] ) ? $result['recommended_action'] : __( 'Please review the latest click activity when convenient.', 'gdrive-folder-viewer' );

			if ( empty( $result['inspection_likely'] ) || $confidence < $threshold ) {
				return sprintf(
					'The last %1$d day(s) of activity were reviewed and do not currently meet the configured alert confidence threshold. The review confidence was %2$d%% against a %3$d%% threshold: %4$s. %5$s',
					$data_days,
					$confidence,
					$threshold,
					$reason,
					$action
				);
			}

			return sprintf(
				'The last %1$d day(s) of activity were reviewed and the pattern is considered worth attention with %2$d%% confidence: %3$s. %4$s',
				$data_days,
				$confidence,
				$reason,
				$action
			);
		}

		if ( ! empty( $analysis['error'] ) ) {
			return sprintf(
				'The follow-up activity review could not be completed. Review detail: %s',
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
	 * @param int $window_hours Trigger lookback window.
	 * @return array
	 */
	public function get_gemini_analysis_for_alert( $window_hours, $source = 'automatic', $trigger_count = 0 ) {
		$analyzer = new GDV_Gemini_Analyzer();
		$data_days = max( 1, (int) get_option( 'gdv_gemini_data_days', 14 ) );

		if ( ! $analyzer->is_configured() ) {
			GDV_Gemini_Log::insert(
				array(
					'source'               => $source,
					'status'               => 'skipped',
					'trigger_count'        => $trigger_count,
					'trigger_window_hours' => $window_hours,
					'data_window_days'     => $data_days,
					'model'                => $analyzer->get_model(),
						'alert_sent'           => false,
					'error_message'        => __( 'Gemini API key is not configured.', 'gdrive-folder-viewer' ),
				)
			);

			return array(
				'send_alert' => true,
			);
		}

		$analysis = $analyzer->analyze_recent_days( $data_days );

			if ( is_wp_error( $analysis ) ) {
				$pending_review = get_option( 'gdv_pending_review', array() );
				$attempt_note = 'automatic' === $source ? sprintf( 'Attempt %d of 3. ', min( 3, (int) ( $pending_review['attempts'] ?? 1 ) ) ) : '';
				GDV_Gemini_Log::insert(
				array(
					'source'               => $source,
					'status'               => 'error',
					'trigger_count'        => $trigger_count,
					'trigger_window_hours' => $window_hours,
					'data_window_days'     => $data_days,
					'model'                => $analyzer->get_model(),
						'alert_sent'           => false,
						'error_message'        => $attempt_note . $analysis->get_error_message(),
				)
			);

			return array(
				'send_alert' => true,
					'error'      => $analysis->get_error_message(),
					'error_data' => (array) $analysis->get_error_data(),
			);
		}

		$send_alert = $analyzer->should_send_alert( $analysis );

		GDV_Gemini_Log::insert(
			array(
				'source'               => $source,
				'status'               => 'complete',
				'trigger_count'        => $trigger_count,
				'trigger_window_hours' => $window_hours,
				'data_window_days'     => $data_days,
				'model'                => $analyzer->get_model(),
						'alert_sent'           => false,
				'analysis'             => $analysis,
			)
		);

		return array(
			'send_alert' => $send_alert,
			'analysis'   => $analysis,
			'data_days'  => $data_days,
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
	public static function get_visitor_ip_address() {
		$ip_address = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		return filter_var( $ip_address, FILTER_VALIDATE_IP ) ? $ip_address : '';
	}

	public static function is_whitelisted_ip( $ip_address ) {
		if ( ! filter_var( $ip_address, FILTER_VALIDATE_IP ) ) {
			return false;
		}
		foreach ( preg_split( '/\R/', (string) get_option( 'gdv_ip_whitelist', '' ) ) as $line ) {
			$parts = explode( '|', $line, 2 );
			$excluded = trim( $parts[0] );
			// Compare packed addresses so equivalent IPv6 spellings match.
			if ( filter_var( $excluded, FILTER_VALIDATE_IP ) && inet_pton( $excluded ) === inet_pton( $ip_address ) ) {
				return true;
			}
		}
		return false;
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
