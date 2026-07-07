<?php
/**
 * Admin settings and reporting screens.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GDV_Admin {

	const PAGE_SLUG = 'gdv-settings';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_gdv_clear_cache', array( $this, 'handle_clear_cache' ) );
		add_action( 'admin_post_gdv_send_test_alert', array( $this, 'handle_send_test_alert' ) );
		add_action( 'admin_post_gdv_delete_clicks', array( $this, 'handle_delete_clicks' ) );
		add_action( 'wp_dashboard_setup', array( $this, 'add_dashboard_widget' ) );
	}

	/**
	 * Adds the plugin page under the Primary ICT Support menu.
	 *
	 * @return void
	 */
	public function add_settings_page() {
		Primary_ICT_Support_Admin_Menu::register_plugin(
			array(
				'page_title' => __( 'Drive Folder Viewer', 'gdrive-folder-viewer' ),
				'menu_title' => __( 'Drive Folder Viewer', 'gdrive-folder-viewer' ),
				'description' => __( 'Display Google Drive policy folders, track document clicks, and review activity reports.', 'gdrive-folder-viewer' ),
				'slug'       => self::PAGE_SLUG,
				'callback'   => array( $this, 'render_page' ),
				'capability' => 'manage_options',
				'icon_url'   => GDV_PRIMARY_ICT_ICON_URL,
				'logo_url'   => GDV_PRIMARY_ICT_LOGO_URL,
			)
		);
	}

	/**
	 * Registers settings saved by options.php.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting( 'gdv_settings', 'gdv_api_key', array( $this, 'sanitize_text' ) );
		register_setting( 'gdv_settings', 'gdv_cache_hours', array( $this, 'sanitize_positive_int' ) );
		register_setting( 'gdv_settings', 'gdv_click_threshold', array( $this, 'sanitize_positive_int' ) );
		register_setting( 'gdv_settings', 'gdv_threshold_window_hours', array( $this, 'sanitize_positive_int' ) );
		register_setting( 'gdv_settings', 'gdv_alert_cooldown_hours', array( $this, 'sanitize_non_negative_int' ) );
		register_setting( 'gdv_settings', 'gdv_alert_emails', array( $this, 'sanitize_email_list' ) );
		register_setting( 'gdv_settings', 'gdv_gemini_api_key', array( $this, 'sanitize_text' ) );
		register_setting( 'gdv_settings', 'gdv_gemini_model', array( $this, 'sanitize_gemini_model' ) );
		register_setting( 'gdv_settings', 'gdv_gemini_confidence_threshold', array( $this, 'sanitize_percentage' ) );
		register_setting( 'gdv_settings', 'gdv_delete_data_on_uninstall', array( $this, 'sanitize_checkbox' ) );
		register_setting( 'gdv_design_settings', 'gdv_design_bg_color', array( $this, 'sanitize_color' ) );
		register_setting( 'gdv_design_settings', 'gdv_design_hover_bg_color', array( $this, 'sanitize_color' ) );
		register_setting( 'gdv_design_settings', 'gdv_design_text_color', array( $this, 'sanitize_color' ) );
		register_setting( 'gdv_design_settings', 'gdv_design_hover_text_color', array( $this, 'sanitize_color' ) );
		register_setting( 'gdv_design_settings', 'gdv_design_meta_color', array( $this, 'sanitize_color' ) );
		register_setting( 'gdv_design_settings', 'gdv_design_border_color', array( $this, 'sanitize_color' ) );
		register_setting( 'gdv_design_settings', 'gdv_design_text_size', array( $this, 'sanitize_positive_int' ) );
		register_setting( 'gdv_design_settings', 'gdv_design_meta_size', array( $this, 'sanitize_positive_int' ) );
		register_setting( 'gdv_design_settings', 'gdv_design_icon_size', array( $this, 'sanitize_positive_int' ) );
		register_setting( 'gdv_design_settings', 'gdv_design_row_padding', array( $this, 'sanitize_positive_int' ) );
		register_setting( 'gdv_design_settings', 'gdv_design_border_radius', array( $this, 'sanitize_non_negative_int' ) );
		register_setting( 'gdv_design_settings', 'gdv_design_font_family', array( $this, 'sanitize_font_family' ) );
	}

	/**
	 * Renders the admin page shell.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings';
		$tab = in_array( $tab, array( 'settings', 'design', 'stats', 'gemini' ), true ) ? $tab : 'settings';

		echo '<div class="wrap picts-dashboard picts-plugin-page">';
		echo '<div class="picts-plugin-page__hero">';
		echo '<div>';
		echo '<p class="picts-plugin-page__eyebrow">' . esc_html__( 'Primary ICT Support Plugin', 'gdrive-folder-viewer' ) . '</p>';
		echo '<h1>' . esc_html__( 'Google Drive Folder Viewer', 'gdrive-folder-viewer' ) . '</h1>';
		echo '<p class="picts-plugin-page__intro">' . esc_html__( 'Display Google Drive policy folders, customise the document list, track clicks, and review activity from one place.', 'gdrive-folder-viewer' ) . '</p>';
		echo '</div>';
		echo '<img class="picts-plugin-page__logo" src="' . esc_url( GDV_PRIMARY_ICT_LOGO_URL ) . '" alt="' . esc_attr__( 'Primary ICT Support', 'gdrive-folder-viewer' ) . '">';
		echo '</div>';
		$this->render_tabs( $tab );

		echo '<div class="picts-plugin-page__panel">';
		if ( 'stats' === $tab ) {
			$this->render_stats_tab();
		} elseif ( 'gemini' === $tab ) {
			$this->render_gemini_tab();
		} elseif ( 'design' === $tab ) {
			$this->render_design_tab();
		} else {
			$this->render_settings_tab();
		}
		echo '</div>';

		echo '</div>';
	}

	/**
	 * Clears cached Google Drive folder responses.
	 *
	 * @return void
	 */
	public function handle_clear_cache() {
		$this->require_admin_action( 'gdv_clear_cache' );

		$known = get_option( 'gdv_known_folders', array() );
		$known = is_array( $known ) ? $known : array();
		$api   = new GDV_Drive_API();

		foreach ( array_keys( $known ) as $folder_id ) {
			$api->clear_cache( $folder_id );
		}

		$this->redirect_with_notice( 'cache_cleared' );
	}

	/**
	 * Sends a test alert email to configured recipients.
	 *
	 * @return void
	 */
	public function handle_send_test_alert() {
		$this->require_admin_action( 'gdv_send_test_alert' );

		$tracker      = new GDV_Click_Tracker();
		$window_hours = max( 1, (int) get_option( 'gdv_threshold_window_hours', 24 ) );
		$count        = $tracker->get_click_count( $window_hours );
		$sent         = $tracker->send_alert_email( $count, $window_hours, true );

		$this->redirect_with_notice( $sent ? 'test_sent' : 'test_failed' );
	}

	/**
	 * Deletes all stored click logs.
	 *
	 * @return void
	 */
	public function handle_delete_clicks() {
		$this->require_admin_action( 'gdv_delete_clicks' );

		global $wpdb;
		$table = $wpdb->prefix . GDV_TABLE_NAME;
		$wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$this->redirect_with_notice( 'clicks_deleted', 'stats' );
	}

	/**
	 * Adds a quick stats widget to the WordPress dashboard.
	 *
	 * @return void
	 */
	public function add_dashboard_widget() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_add_dashboard_widget(
			'gdv_policy_clicks_widget',
			__( 'Policy Document Clicks', 'gdrive-folder-viewer' ),
			array( $this, 'render_dashboard_widget' )
		);
	}

	/**
	 * Renders the WordPress dashboard widget.
	 *
	 * @return void
	 */
	public function render_dashboard_widget() {
		$tracker = new GDV_Click_Tracker();
		echo '<div class="gdv-dashboard-widget">';
		echo $tracker->get_click_graph_svg( 7, 4, 700, 300 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<p><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=stats' ) ) . '">' . esc_html__( 'View full stats', 'gdrive-folder-viewer' ) . '</a></p>';
		echo '</div>';
	}

	/**
	 * Text field sanitizer.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public function sanitize_text( $value ) {
		return sanitize_text_field( (string) $value );
	}

	/**
	 * Positive integer sanitizer.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	public function sanitize_positive_int( $value ) {
		return max( 1, absint( $value ) );
	}

	/**
	 * Non-negative integer sanitizer.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	public function sanitize_non_negative_int( $value ) {
		return max( 0, absint( $value ) );
	}

	/**
	 * Checkbox sanitizer.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	public function sanitize_checkbox( $value ) {
		return empty( $value ) ? 0 : 1;
	}

	/**
	 * Percentage sanitizer.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	public function sanitize_percentage( $value ) {
		return max( 0, min( 100, absint( $value ) ) );
	}

	/**
	 * Sanitizes a date field in Y-m-d format.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private function sanitize_date_input( $value ) {
		$value = sanitize_text_field( (string) $value );
		$date  = DateTimeImmutable::createFromFormat( 'Y-m-d', $value, wp_timezone() );

		if ( false === $date || $date->format( 'Y-m-d' ) !== $value ) {
			return '';
		}

		return $value;
	}

	/**
	 * Sanitizes a Gemini model identifier.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public function sanitize_gemini_model( $value ) {
		$model = sanitize_text_field( (string) $value );

		if ( ! preg_match( '/^[a-z0-9._-]+$/', $model ) ) {
			return GDV_Gemini_Analyzer::DEFAULT_MODEL;
		}

		return '' !== $model ? $model : GDV_Gemini_Analyzer::DEFAULT_MODEL;
	}

	/**
	 * Sanitizes a comma-separated recipient list.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public function sanitize_email_list( $value ) {
		$emails = array_filter( array_map( 'trim', explode( ',', (string) $value ) ) );
		$emails = array_filter( array_map( 'sanitize_email', $emails ), 'is_email' );

		return implode( ', ', $emails );
	}

	/**
	 * Hex colour sanitizer.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public function sanitize_color( $value ) {
		$color = sanitize_hex_color( (string) $value );

		return $color ? $color : '';
	}

	/**
	 * Allows a curated set of broadly available font stacks.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public function sanitize_font_family( $value ) {
		$value   = sanitize_key( (string) $value );
		$allowed = array_keys( $this->get_font_choices() );

		return in_array( $value, $allowed, true ) ? $value : 'inherit';
	}

	/**
	 * Renders the tab navigation.
	 *
	 * @param string $active_tab Current tab.
	 * @return void
	 */
	private function render_tabs( $active_tab ) {
		$settings_url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		$design_url   = admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=design' );
		$stats_url    = admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=stats' );
		$gemini_url   = admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=gemini' );

		echo '<h2 class="nav-tab-wrapper">';
		echo '<a class="nav-tab ' . esc_attr( 'settings' === $active_tab ? 'nav-tab-active' : '' ) . '" href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings', 'gdrive-folder-viewer' ) . '</a>';
		echo '<a class="nav-tab ' . esc_attr( 'design' === $active_tab ? 'nav-tab-active' : '' ) . '" href="' . esc_url( $design_url ) . '">' . esc_html__( 'Design', 'gdrive-folder-viewer' ) . '</a>';
		echo '<a class="nav-tab ' . esc_attr( 'stats' === $active_tab ? 'nav-tab-active' : '' ) . '" href="' . esc_url( $stats_url ) . '">' . esc_html__( 'Click Stats', 'gdrive-folder-viewer' ) . '</a>';
		echo '<a class="nav-tab ' . esc_attr( 'gemini' === $active_tab ? 'nav-tab-active' : '' ) . '" href="' . esc_url( $gemini_url ) . '">' . esc_html__( 'Gemini AI', 'gdrive-folder-viewer' ) . '</a>';
		echo '</h2>';
	}

	/**
	 * Renders the plugin settings form.
	 *
	 * @return void
	 */
	private function render_settings_tab() {
		$this->render_admin_notice();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
			<?php settings_fields( 'gdv_settings' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="gdv_api_key"><?php esc_html_e( 'Google API key', 'gdrive-folder-viewer' ); ?></label></th>
					<td>
						<input name="gdv_api_key" id="gdv_api_key" type="text" class="regular-text" value="<?php echo esc_attr( get_option( 'gdv_api_key', '' ) ); ?>" autocomplete="off">
						<p class="description"><?php esc_html_e( 'Use an API key with Google Drive API access. Folders must be shared publicly or with anyone who has the link.', 'gdrive-folder-viewer' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="gdv_cache_hours"><?php esc_html_e( 'Cache duration', 'gdrive-folder-viewer' ); ?></label></th>
					<td><input name="gdv_cache_hours" id="gdv_cache_hours" type="number" min="1" class="small-text" value="<?php echo esc_attr( get_option( 'gdv_cache_hours', 6 ) ); ?>"> <?php esc_html_e( 'hours', 'gdrive-folder-viewer' ); ?></td>
				</tr>
				<tr>
					<th scope="row"><label for="gdv_click_threshold"><?php esc_html_e( 'Alert threshold', 'gdrive-folder-viewer' ); ?></label></th>
					<td><input name="gdv_click_threshold" id="gdv_click_threshold" type="number" min="1" class="small-text" value="<?php echo esc_attr( get_option( 'gdv_click_threshold', 20 ) ); ?>"> <?php esc_html_e( 'clicks', 'gdrive-folder-viewer' ); ?></td>
				</tr>
				<tr>
					<th scope="row"><label for="gdv_threshold_window_hours"><?php esc_html_e( 'Alert window', 'gdrive-folder-viewer' ); ?></label></th>
					<td><input name="gdv_threshold_window_hours" id="gdv_threshold_window_hours" type="number" min="1" class="small-text" value="<?php echo esc_attr( get_option( 'gdv_threshold_window_hours', 24 ) ); ?>"> <?php esc_html_e( 'hours', 'gdrive-folder-viewer' ); ?></td>
				</tr>
				<tr>
					<th scope="row"><label for="gdv_alert_cooldown_hours"><?php esc_html_e( 'Alert cooldown', 'gdrive-folder-viewer' ); ?></label></th>
					<td><input name="gdv_alert_cooldown_hours" id="gdv_alert_cooldown_hours" type="number" min="0" class="small-text" value="<?php echo esc_attr( get_option( 'gdv_alert_cooldown_hours', 24 ) ); ?>"> <?php esc_html_e( 'hours', 'gdrive-folder-viewer' ); ?></td>
				</tr>
				<tr>
					<th scope="row"><label for="gdv_alert_emails"><?php esc_html_e( 'Alert recipients', 'gdrive-folder-viewer' ); ?></label></th>
					<td>
						<input name="gdv_alert_emails" id="gdv_alert_emails" type="text" class="regular-text" value="<?php echo esc_attr( get_option( 'gdv_alert_emails', get_option( 'admin_email' ) ) ); ?>">
						<p class="description"><?php esc_html_e( 'Separate multiple email addresses with commas.', 'gdrive-folder-viewer' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="gdv_gemini_api_key"><?php esc_html_e( 'Gemini API key', 'gdrive-folder-viewer' ); ?></label></th>
					<td>
						<input name="gdv_gemini_api_key" id="gdv_gemini_api_key" type="password" class="regular-text" value="<?php echo esc_attr( get_option( 'gdv_gemini_api_key', '' ) ); ?>" autocomplete="off">
						<p class="description"><?php esc_html_e( 'Optional. When set, the click threshold triggers Gemini analysis before alert emails are sent.', 'gdrive-folder-viewer' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="gdv_gemini_model"><?php esc_html_e( 'Gemini model', 'gdrive-folder-viewer' ); ?></label></th>
					<td>
						<input name="gdv_gemini_model" id="gdv_gemini_model" type="text" class="regular-text" value="<?php echo esc_attr( get_option( 'gdv_gemini_model', GDV_Gemini_Analyzer::DEFAULT_MODEL ) ); ?>" autocomplete="off">
						<p class="description"><?php esc_html_e( 'Default: gemini-3.5-flash. Change this if Google recommends a newer Gemini API model.', 'gdrive-folder-viewer' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="gdv_gemini_confidence_threshold"><?php esc_html_e( 'Gemini confidence threshold', 'gdrive-folder-viewer' ); ?></label></th>
					<td>
						<input name="gdv_gemini_confidence_threshold" id="gdv_gemini_confidence_threshold" type="number" min="0" max="100" class="small-text" value="<?php echo esc_attr( get_option( 'gdv_gemini_confidence_threshold', 75 ) ); ?>"> %
						<p class="description"><?php esc_html_e( 'Gemini must mark inspection activity as likely and meet this confidence score before the alert email is sent.', 'gdrive-folder-viewer' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Delete data on uninstall', 'gdrive-folder-viewer' ); ?></th>
					<td>
						<label>
							<input name="gdv_delete_data_on_uninstall" type="checkbox" value="1" <?php checked( 1, (int) get_option( 'gdv_delete_data_on_uninstall', 0 ) ); ?>>
							<?php esc_html_e( 'Remove plugin settings and click logs when the plugin is deleted.', 'gdrive-folder-viewer' ); ?>
						</label>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>

		<hr>

		<h2><?php esc_html_e( 'Tools', 'gdrive-folder-viewer' ); ?></h2>
		<p><?php esc_html_e( 'Shortcode example:', 'gdrive-folder-viewer' ); ?> <code>[gdrive_folder id="GOOGLE_DRIVE_FOLDER_ID" title="Policies"]</code></p>
		<?php $this->render_known_folders(); ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:8px;">
			<input type="hidden" name="action" value="gdv_clear_cache">
			<?php wp_nonce_field( 'gdv_clear_cache' ); ?>
			<?php submit_button( __( 'Clear Folder Cache', 'gdrive-folder-viewer' ), 'secondary', 'submit', false ); ?>
		</form>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;">
			<input type="hidden" name="action" value="gdv_send_test_alert">
			<?php wp_nonce_field( 'gdv_send_test_alert' ); ?>
			<?php submit_button( __( 'Send Test Alert', 'gdrive-folder-viewer' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php
	}

	/**
	 * Renders design controls for the front-end list.
	 *
	 * @return void
	 */
	private function render_design_tab() {
		$this->render_admin_notice();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
			<?php settings_fields( 'gdv_design_settings' ); ?>
			<table class="form-table" role="presentation">
				<?php
				$this->render_color_field( 'gdv_design_bg_color', __( 'Background colour', 'gdrive-folder-viewer' ), '#ffffff' );
				$this->render_color_field( 'gdv_design_hover_bg_color', __( 'Hover background colour', 'gdrive-folder-viewer' ), '#f3f7fb' );
				$this->render_color_field( 'gdv_design_text_color', __( 'Text colour', 'gdrive-folder-viewer' ), '#1f2937' );
				$this->render_color_field( 'gdv_design_hover_text_color', __( 'Hover text colour', 'gdrive-folder-viewer' ), '#0b5cab' );
				$this->render_color_field( 'gdv_design_meta_color', __( 'Date and size colour', 'gdrive-folder-viewer' ), '#646970' );
				$this->render_color_field( 'gdv_design_border_color', __( 'Border colour', 'gdrive-folder-viewer' ), '#d9e2ec' );
				?>
				<tr>
					<th scope="row"><label for="gdv_design_font_family"><?php esc_html_e( 'Font', 'gdrive-folder-viewer' ); ?></label></th>
					<td>
						<select name="gdv_design_font_family" id="gdv_design_font_family">
							<?php foreach ( $this->get_font_choices() as $key => $label ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $key, get_option( 'gdv_design_font_family', 'inherit' ) ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<?php
				$this->render_number_field( 'gdv_design_text_size', __( 'Text size', 'gdrive-folder-viewer' ), 16, 'px' );
				$this->render_number_field( 'gdv_design_meta_size', __( 'Date and size text', 'gdrive-folder-viewer' ), 13, 'px' );
				$this->render_number_field( 'gdv_design_icon_size', __( 'Icon size', 'gdrive-folder-viewer' ), 38, 'px' );
				$this->render_number_field( 'gdv_design_row_padding', __( 'Row padding', 'gdrive-folder-viewer' ), 14, 'px' );
				$this->render_number_field( 'gdv_design_border_radius', __( 'Corner radius', 'gdrive-folder-viewer' ), 8, 'px' );
				?>
			</table>
			<?php submit_button( __( 'Save Design Settings', 'gdrive-folder-viewer' ) ); ?>
		</form>
		<?php
	}

	/**
	 * Renders a colour picker row.
	 *
	 * @param string $option  Option name.
	 * @param string $label   Field label.
	 * @param string $default Default colour.
	 * @return void
	 */
	private function render_color_field( $option, $label, $default ) {
		$value = get_option( $option, $default );
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $option ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td><input name="<?php echo esc_attr( $option ); ?>" id="<?php echo esc_attr( $option ); ?>" type="color" value="<?php echo esc_attr( $value ); ?>"></td>
		</tr>
		<?php
	}

	/**
	 * Renders a numeric design field row.
	 *
	 * @param string $option  Option name.
	 * @param string $label   Field label.
	 * @param int    $default Default value.
	 * @param string $suffix  Unit label.
	 * @return void
	 */
	private function render_number_field( $option, $label, $default, $suffix ) {
		$value = get_option( $option, $default );
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $option ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td><input name="<?php echo esc_attr( $option ); ?>" id="<?php echo esc_attr( $option ); ?>" type="number" min="0" class="small-text" value="<?php echo esc_attr( $value ); ?>"> <?php echo esc_html( $suffix ); ?></td>
		</tr>
		<?php
	}

	/**
	 * Returns selectable font choices.
	 *
	 * @return array
	 */
	private function get_font_choices() {
		return array(
			'inherit' => __( 'Use theme font', 'gdrive-folder-viewer' ),
			'system'  => __( 'System sans-serif', 'gdrive-folder-viewer' ),
			'serif'   => __( 'Serif', 'gdrive-folder-viewer' ),
			'mono'    => __( 'Monospace', 'gdrive-folder-viewer' ),
		);
	}

	/**
	 * Renders click reporting.
	 *
	 * @return void
	 */
	private function render_stats_tab() {
		$this->render_admin_notice();
		GDV_Activator::activate();

		$tracker      = new GDV_Click_Tracker();
		$window_hours = max( 1, (int) get_option( 'gdv_threshold_window_hours', 24 ) );
		$total        = $tracker->get_click_count( $window_hours );
		$top_files    = $tracker->get_top_files( $window_hours, 10 );
		$ip_search    = isset( $_GET['gdv_ip_search'] ) ? sanitize_text_field( wp_unslash( $_GET['gdv_ip_search'] ) ) : '';
		$recent       = $this->get_recent_clicks( 25, $ip_search );
		?>
		<h2><?php esc_html_e( 'Recent Activity', 'gdrive-folder-viewer' ); ?></h2>
		<p>
			<?php
			printf(
				/* translators: 1: click count, 2: number of hours */
				esc_html__( '%1$d click(s) in the last %2$d hour(s).', 'gdrive-folder-viewer' ),
				(int) $total,
				(int) $window_hours
			);
			?>
		</p>

		<h3><?php esc_html_e( 'Daily Policy Clicks', 'gdrive-folder-viewer' ); ?></h3>
		<div class="gdv-admin-graph-wrap">
			<?php echo $tracker->get_click_graph_svg( 14, 6, 900, 360 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</div>

		<h3><?php esc_html_e( 'Top Documents', 'gdrive-folder-viewer' ); ?></h3>
		<?php $this->render_top_files_table( $top_files ); ?>

		<h3><?php esc_html_e( 'Latest Clicks', 'gdrive-folder-viewer' ); ?></h3>
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="margin: 0 0 12px;">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
			<input type="hidden" name="tab" value="stats">
			<label for="gdv_ip_search" class="screen-reader-text"><?php esc_html_e( 'Search by IP address', 'gdrive-folder-viewer' ); ?></label>
			<input name="gdv_ip_search" id="gdv_ip_search" type="search" value="<?php echo esc_attr( $ip_search ); ?>" placeholder="<?php esc_attr_e( 'Search IP address', 'gdrive-folder-viewer' ); ?>" class="regular-text">
			<?php submit_button( __( 'Search', 'gdrive-folder-viewer' ), 'secondary', 'submit', false ); ?>
			<?php if ( '' !== $ip_search ) : ?>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=stats' ) ); ?>"><?php esc_html_e( 'Clear', 'gdrive-folder-viewer' ); ?></a>
			<?php endif; ?>
		</form>
		<?php $this->render_recent_clicks_table( $recent ); ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="gdv_delete_clicks">
			<?php wp_nonce_field( 'gdv_delete_clicks' ); ?>
			<?php submit_button( __( 'Delete All Click Logs', 'gdrive-folder-viewer' ), 'delete' ); ?>
		</form>
		<?php
	}

	/**
	 * Renders Gemini manual testing tools.
	 *
	 * @return void
	 */
	private function render_gemini_tab() {
		$this->render_admin_notice();
		GDV_Activator::activate();

		$timezone          = wp_timezone();
		$today             = wp_date( 'Y-m-d', null, $timezone );
		$default_start     = wp_date( 'Y-m-d', time() - ( 6 * DAY_IN_SECONDS ), $timezone );
		$start_date        = isset( $_POST['gdv_gemini_start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['gdv_gemini_start_date'] ) ) : $default_start;
		$end_date          = isset( $_POST['gdv_gemini_end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['gdv_gemini_end_date'] ) ) : $today;
		$analysis          = null;
		$analysis_error    = null;
		$analyzer          = new GDV_Gemini_Analyzer();
		$is_configured     = $analyzer->is_configured();
		$is_manual_request = isset( $_POST['gdv_run_gemini_check'] );
		$model             = $analyzer->get_model();

		if ( $is_manual_request && check_admin_referer( 'gdv_manual_gemini_check', 'gdv_manual_gemini_nonce' ) ) {
			$start_date = $this->sanitize_date_input( $start_date );
			$end_date   = $this->sanitize_date_input( $end_date );

			if ( '' === $start_date || '' === $end_date ) {
				$analysis_error = new WP_Error( 'gdv_gemini_invalid_admin_dates', __( 'Please choose a valid start and end date.', 'gdrive-folder-viewer' ) );
			} else {
				$analysis = $analyzer->analyze_date_range( $start_date, $end_date );

				if ( is_wp_error( $analysis ) ) {
					$analysis_error = $analysis;
					$analysis       = null;
				}
			}
		}
		?>
		<h2><?php esc_html_e( 'Gemini AI Manual Check', 'gdrive-folder-viewer' ); ?></h2>
		<p><?php esc_html_e( 'Run a one-off check for unusual policy click activity. The result is shown here only and no alert email is sent.', 'gdrive-folder-viewer' ); ?></p>

		<div class="gdv-gemini-status <?php echo esc_attr( $is_configured ? 'gdv-gemini-status--ready' : 'gdv-gemini-status--missing' ); ?>">
			<strong><?php esc_html_e( 'Connection status:', 'gdrive-folder-viewer' ); ?></strong>
			<?php if ( $is_configured ) : ?>
				<?php
				printf(
					/* translators: %s: Gemini model name */
					esc_html__( 'Gemini API key saved. Using model: %s.', 'gdrive-folder-viewer' ),
					esc_html( $model )
				);
				?>
			<?php else : ?>
				<?php esc_html_e( 'Gemini API key missing. Add it in Settings before running a check.', 'gdrive-folder-viewer' ); ?>
			<?php endif; ?>
		</div>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=gemini' ) ); ?>" class="gdv-gemini-form">
			<?php wp_nonce_field( 'gdv_manual_gemini_check', 'gdv_manual_gemini_nonce' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="gdv_gemini_start_date"><?php esc_html_e( 'Start date', 'gdrive-folder-viewer' ); ?></label></th>
					<td><input name="gdv_gemini_start_date" id="gdv_gemini_start_date" type="date" value="<?php echo esc_attr( $start_date ); ?>" max="<?php echo esc_attr( $today ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="gdv_gemini_end_date"><?php esc_html_e( 'End date', 'gdrive-folder-viewer' ); ?></label></th>
					<td><input name="gdv_gemini_end_date" id="gdv_gemini_end_date" type="date" value="<?php echo esc_attr( $end_date ); ?>" max="<?php echo esc_attr( $today ); ?>"></td>
				</tr>
			</table>
			<?php submit_button( __( 'Run Gemini Check', 'gdrive-folder-viewer' ), 'primary', 'gdv_run_gemini_check', true, $is_configured ? array() : array( 'disabled' => 'disabled' ) ); ?>
		</form>

		<p class="description"><?php esc_html_e( 'Click logs are grouped by anonymous user labels before being sent to Gemini. Raw IP addresses are not included in the request.', 'gdrive-folder-viewer' ); ?></p>

		<?php
		if ( $analysis_error ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $analysis_error->get_error_message() ) . '</p></div>';
		}

		if ( $analysis ) {
			$this->render_gemini_analysis_result( $analysis );
		}
	}

	/**
	 * Displays a Gemini analysis result.
	 *
	 * @param array $analysis Analysis result.
	 * @return void
	 */
	private function render_gemini_analysis_result( $analysis ) {
		$threshold   = max( 0, min( 100, (int) get_option( 'gdv_gemini_confidence_threshold', 75 ) ) );
		$confidence  = isset( $analysis['confidence_score'] ) ? (int) $analysis['confidence_score'] : 0;
		$would_alert = ! empty( $analysis['inspection_likely'] ) && $confidence >= $threshold;
		?>
		<div class="gdv-gemini-result">
			<h3><?php esc_html_e( 'Gemini Response', 'gdrive-folder-viewer' ); ?></h3>
			<table class="widefat striped">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Inspection activity likely', 'gdrive-folder-viewer' ); ?></th>
						<td><?php echo esc_html( ! empty( $analysis['inspection_likely'] ) ? __( 'Yes', 'gdrive-folder-viewer' ) : __( 'No', 'gdrive-folder-viewer' ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Confidence score', 'gdrive-folder-viewer' ); ?></th>
						<td><?php echo esc_html( $confidence ); ?>%</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Would trigger alert email', 'gdrive-folder-viewer' ); ?></th>
						<td>
							<?php
							printf(
								/* translators: 1: yes/no, 2: confidence percentage threshold */
								esc_html__( '%1$s, based on the current %2$d%% confidence threshold.', 'gdrive-folder-viewer' ),
								esc_html( $would_alert ? __( 'Yes', 'gdrive-folder-viewer' ) : __( 'No', 'gdrive-folder-viewer' ) ),
								(int) $threshold
							);
							?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Triggering user', 'gdrive-folder-viewer' ); ?></th>
						<td><?php echo esc_html( ! empty( $analysis['triggering_user'] ) ? $analysis['triggering_user'] : __( 'None reported', 'gdrive-folder-viewer' ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Reason', 'gdrive-folder-viewer' ); ?></th>
						<td><?php echo esc_html( ! empty( $analysis['reason'] ) ? $analysis['reason'] : __( 'No reason returned.', 'gdrive-folder-viewer' ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Recommended action', 'gdrive-folder-viewer' ); ?></th>
						<td><?php echo esc_html( ! empty( $analysis['recommended_action'] ) ? $analysis['recommended_action'] : __( 'No action returned.', 'gdrive-folder-viewer' ) ); ?></td>
					</tr>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Shows known folder IDs captured from rendered shortcodes.
	 *
	 * @return void
	 */
	private function render_known_folders() {
		$known = get_option( 'gdv_known_folders', array() );
		$known = is_array( $known ) ? $known : array();

		if ( empty( $known ) ) {
			echo '<p>' . esc_html__( 'No folder IDs have been recorded yet. They are recorded when a shortcode renders on the front end.', 'gdrive-folder-viewer' ) . '</p>';
			return;
		}

		echo '<p>' . esc_html__( 'Recorded folder IDs:', 'gdrive-folder-viewer' ) . '</p>';
		echo '<ul>';
		foreach ( $known as $folder_id => $timestamp ) {
			echo '<li><code>' . esc_html( $folder_id ) . '</code>';
			if ( ! empty( $timestamp ) ) {
				echo ' <span class="description">' . esc_html( date_i18n( get_option( 'date_format' ), (int) $timestamp ) ) . '</span>';
			}
			echo '</li>';
		}
		echo '</ul>';
	}

	/**
	 * Renders the top files table.
	 *
	 * @param array $rows Database rows.
	 * @return void
	 */
	private function render_top_files_table( $rows ) {
		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No click data is available for this window yet.', 'gdrive-folder-viewer' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Document', 'gdrive-folder-viewer' ) . '</th>';
		echo '<th>' . esc_html__( 'Clicks', 'gdrive-folder-viewer' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			echo '<tr>';
			echo '<td>' . esc_html( $row->file_name ) . '</td>';
			echo '<td>' . esc_html( (int) $row->clicks ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Renders the latest click log rows.
	 *
	 * @param array $rows Database rows.
	 * @return void
	 */
	private function render_recent_clicks_table( $rows ) {
		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No clicks have been logged yet.', 'gdrive-folder-viewer' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Document', 'gdrive-folder-viewer' ) . '</th>';
		echo '<th>' . esc_html__( 'Folder ID', 'gdrive-folder-viewer' ) . '</th>';
		echo '<th>' . esc_html__( 'IP Address', 'gdrive-folder-viewer' ) . '</th>';
		echo '<th>' . esc_html__( 'Clicked At', 'gdrive-folder-viewer' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			echo '<tr>';
			echo '<td>' . esc_html( $row->file_name ) . '</td>';
			echo '<td><code>' . esc_html( $row->folder_id ) . '</code></td>';
			echo '<td><code>' . esc_html( $row->ip_address ) . '</code></td>';
			echo '<td>' . esc_html( $this->format_click_datetime( $row->clicked_at ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Fetches latest click rows.
	 *
	 * @param int    $limit     Maximum rows.
	 * @param string $ip_search Optional IP address search term.
	 * @return array
	 */
	private function get_recent_clicks( $limit, $ip_search = '' ) {
		global $wpdb;
		$table = $wpdb->prefix . GDV_TABLE_NAME;
		$limit = max( 1, (int) $limit );

		if ( '' !== $ip_search ) {
			$like = '%' . $wpdb->esc_like( $ip_search ) . '%';

			return $wpdb->get_results(
				$wpdb->prepare(
					"SELECT folder_id, file_name, ip_address, clicked_at FROM {$table} WHERE ip_address LIKE %s ORDER BY clicked_at DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$like,
					$limit
				)
			);
		}

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT folder_id, file_name, ip_address, clicked_at FROM {$table} ORDER BY clicked_at DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$limit
			)
		);
	}

	/**
	 * Formats stored click times using the WordPress timezone, including DST.
	 *
	 * @param string $mysql_datetime Stored datetime.
	 * @return string
	 */
	private function format_click_datetime( $mysql_datetime ) {
		$timezone = new DateTimeZone( 'Europe/London' );
		$datetime = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $mysql_datetime, new DateTimeZone( 'UTC' ) );

		if ( false === $datetime ) {
			return $mysql_datetime;
		}

		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $datetime->getTimestamp(), $timezone );
	}

	/**
	 * Displays action notices after redirects.
	 *
	 * @return void
	 */
	private function render_admin_notice() {
		if ( empty( $_GET['gdv_notice'] ) ) {
			return;
		}

		$notice = sanitize_key( wp_unslash( $_GET['gdv_notice'] ) );
		$type   = 'notice-success';
		$text   = '';

		switch ( $notice ) {
			case 'cache_cleared':
				$text = __( 'Folder cache cleared.', 'gdrive-folder-viewer' );
				break;
			case 'test_sent':
				$text = __( 'Test alert email sent.', 'gdrive-folder-viewer' );
				break;
			case 'test_failed':
				$type = 'notice-error';
				$text = __( 'The test alert email could not be sent. Check your WordPress mail configuration.', 'gdrive-folder-viewer' );
				break;
			case 'clicks_deleted':
				$text = __( 'Click logs deleted.', 'gdrive-folder-viewer' );
				break;
		}

		if ( empty( $text ) ) {
			return;
		}

		echo '<div class="notice ' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $text ) . '</p></div>';
	}

	/**
	 * Verifies permission and nonce for admin-post actions.
	 *
	 * @param string $nonce_action Nonce action.
	 * @return void
	 */
	private function require_admin_action( $nonce_action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'gdrive-folder-viewer' ) );
		}

		check_admin_referer( $nonce_action );
	}

	/**
	 * Redirects back to the plugin page with a status notice.
	 *
	 * @param string $notice Notice key.
	 * @param string $tab    Target tab.
	 * @return void
	 */
	private function redirect_with_notice( $notice, $tab = 'settings' ) {
		$url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );

		if ( 'settings' !== $tab ) {
			$url = add_query_arg( 'tab', $tab, $url );
		}

		wp_safe_redirect( add_query_arg( 'gdv_notice', $notice, $url ) );
		exit;
	}
}
