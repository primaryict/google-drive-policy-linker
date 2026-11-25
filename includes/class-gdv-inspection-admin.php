<?php
/** Inspection setup using public data, without Google authorisation. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
class GDV_Inspection_Admin {
	public function __construct() {
		foreach ( array( 'detect', 'refresh' ) as $action ) { add_action( 'admin_post_gdv_inspection_' . $action, array( $this, $action ) ); }
	}
	private function authorize( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Administrator access is required.' ); }
		check_admin_referer( 'gdv_inspection_' . $action );
	}
	private function finish( $message ) {
		set_transient( 'gdv_inspection_notice_' . get_current_user_id(), $message, 120 );
		wp_safe_redirect( admin_url( 'admin.php?page=gdv-settings&tab=inspection' ) ); exit;
	}
	public function detect() {
		$this->authorize( 'detect' );
		$urn = GDV_Inspection::detect();
		if ( is_wp_error( $urn ) ) { $this->finish( $urn->get_error_message() ); }
		$row = GDV_Inspection::record( $urn );
		if ( is_wp_error( $row ) ) { $this->finish( $row->get_error_message() ); }
		set_transient( 'gdv_inspection_candidate_' . get_current_user_id(), array( 'urn' => $urn, 'name' => $row['School name'], 'site' => home_url( '/' ) ), 600 );
		$this->finish( 'School found. Check the suggestion below and save to confirm. Your saved URN has not changed.' );
	}
	public function refresh() {
		$this->authorize( 'refresh' );
		$row = GDV_Inspection::record( GDV_Inspection::urn( get_option( 'gdv_inspection_urn', '' ) ), true );
		$this->finish( is_wp_error( $row ) ? $row->get_error_message() : 'Public Ofsted data refreshed. No Gemini request or email was sent.' );
	}
	private static function button( $action, $label ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin:0 8px 12px 0;">';
		echo '<input type="hidden" name="action" value="gdv_inspection_' . esc_attr( $action ) . '">';
		wp_nonce_field( 'gdv_inspection_' . $action );
		submit_button( $label, 'secondary', 'submit', false ); echo '</form>';
	}
	public static function render() {
		settings_errors();
		$key = 'gdv_inspection_notice_' . get_current_user_id(); $notice = get_transient( $key );
		if ( $notice ) { echo '<div class="notice notice-info"><p>' . esc_html( $notice ) . '</p></div>'; delete_transient( $key ); }
		$urn = get_option( 'gdv_inspection_urn', '' );
		$candidate = get_transient( 'gdv_inspection_candidate_' . get_current_user_id() );
		delete_transient( 'gdv_inspection_candidate_' . get_current_user_id() );
		if ( $candidate && $candidate['site'] !== home_url( '/' ) ) { $candidate = false; }
		?>
		<h2>Inspection window</h2>
		<p>When enabled, inspection alerts require an eligible inspection window and a successful Gemini review meeting your confidence threshold. Public Ofsted data replaces the spreadsheet connection; no Google sign-in or API key is needed for this check.</p>
		<h3>School identification</h3>
		<p>Website: <strong style="overflow-wrap:anywhere"><?php echo esc_html( home_url( '/' ) ); ?></strong></p>
		<?php self::button( 'detect', 'Detect School' ); self::button( 'refresh', 'Refresh Ofsted Data' ); ?>
		<p class="description">Detection looks for a school-specific Ofsted or Get Information About Schools link on your homepage. If no unique match is available, enter the URN below. Local and staging sites will usually need a manual URN.</p>
		<?php if ( $candidate ) : ?>
		<p role="status"><strong>Suggested school:</strong> <?php echo esc_html( $candidate['name'] . ' (URN ' . $candidate['urn'] . ')' ); ?>. Check this is your school before saving.</p>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
			<?php settings_fields( 'gdv_inspection_settings' ); ?>
			<table class="form-table" role="presentation">
				<tr><th scope="row">Inspection check</th><td><label><input type="checkbox" name="gdv_inspection_enabled" value="1" <?php checked( GDV_Inspection::enabled() ); ?>> Require inspection window eligibility</label></td></tr>
				<tr><th scope="row"><label for="gdv_inspection_urn">School URN</label></th><td><input type="text" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" class="regular-text" style="max-width:100%" name="gdv_inspection_urn" id="gdv_inspection_urn" value="<?php echo esc_attr( $candidate ? $candidate['urn'] : $urn ); ?>" aria-describedby="gdv-urn-help"><p class="description" id="gdv-urn-help">Six-digit unique reference number. Use the current school URN, not a predecessor school.</p></td></tr>
				<tr><th scope="row"><label for="gdv_inspection_expected">Expected date override</label></th><td><input type="date" name="gdv_inspection_expected" id="gdv_inspection_expected" value="<?php echo esc_attr( get_option( 'gdv_inspection_expected', '' ) ); ?>" aria-describedby="gdv-date-help"><p class="description" id="gdv-date-help">Optional verified expected inspection date, for a shorter cycle, new school or recent inspection not yet published. Overrides public data, including download failures. Clear to restore automatic estimates.</p></td></tr>
			</table>
			<?php submit_button( 'Save Inspection Settings' ); ?>
		</form>
		<?php
		$cache = get_transient( 'gdv_ofsted_record' );
		if ( $urn && is_array( $cache ) && $urn === $cache['urn'] && ! is_wp_error( $cache['result'] ) ) {
			$row = $cache['result'];
			echo '<p><strong>Saved school:</strong> ' . esc_html( $row['School name'] . ' (URN ' . $urn . ')' ) . '</p><p><strong>Ofsted data as at:</strong> ' . esc_html( $row['_as_at'] ) . '</p>';
		}
		// Rendering settings never initiates a large CSV download.
		if ( ! GDV_Inspection::enabled() ) { $check = array( 'status' => 'disabled', 'reason' => 'Inspection window check is disabled.' ); }
		elseif ( get_option( 'gdv_inspection_expected', '' ) && $urn ) { $check = GDV_Inspection::evaluate( array(), null, get_option( 'gdv_inspection_expected' ) ); }
		elseif ( is_array( $cache ) && $urn === $cache['urn'] ) { $check = GDV_Inspection::evaluate( $cache['result'] ); }
		else { $check = array( 'status' => 'unknown', 'reason' => 'Save the school URN, then refresh Ofsted data to preview eligibility.' ); }
		echo '<h3>Current eligibility</h3><p role="status"><strong>' . esc_html( ucfirst( $check['status'] ) ) . ':</strong> ' . esc_html( $check['reason'] ) . '</p>';
		foreach ( array( 'latest' => 'Latest routine inspection', 'expected' => 'Estimated next inspection', 'from' => 'Alert eligibility starts' ) as $field => $label ) {
			if ( isset( $check[ $field ] ) ) { echo '<p><strong>' . esc_html( $label ) . ':</strong> ' . esc_html( $check[ $field ] ) . '</p>'; }
		}
		?>
		<p class="description">The estimate is four calendar years after the latest qualifying routine inspection, with a six-week (42-day) lead-in. Overdue schools remain eligible. Monitoring, concern outcomes, predecessor records and unclear data require manual review. This is an alert filter, not an Ofsted schedule: inspections can happen earlier.</p>
		<p class="description">Ofsted publishes monthly, so recent inspections may not appear immediately. Records are cached for 24 hours; failed downloads pause inspection alerts and are cached for five minutes. Manual Gemini reviews remain available. Existing Gemini review-failure notifications still apply to eligible schools.</p>
		<p><a href="<?php echo esc_url( GDV_Inspection::SOURCE ); ?>" target="_blank" rel="noopener noreferrer">Ofsted public data source</a></p>
		<?php
	}
}
