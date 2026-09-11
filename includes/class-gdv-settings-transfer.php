<?php
/** Settings portability for school deployments. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GDV_Settings_Transfer {
	public function __construct() {
		add_action( 'admin_post_gdv_export_settings', array( $this, 'export_settings' ) );
		add_action( 'admin_post_gdv_import_settings', array( $this, 'import_settings' ) );
	}

	private function authorize( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to transfer settings.', 'gdrive-folder-viewer' ) );
		}
		check_admin_referer( $action );
	}

	private function allowed_settings() {
		return array_filter( get_registered_settings(), function ( $setting, $name ) {
			return 0 === strpos( $name, 'gdv_' ) && in_array( $setting['group'], array( 'gdv_settings', 'gdv_design_settings', 'gdv_gemini_settings', 'gdv_whitelist_settings' ), true );
		}, ARRAY_FILTER_USE_BOTH );
	}

	public function export_settings() {
		$this->authorize( 'gdv_export_settings' );
		$settings = array();
		foreach ( $this->allowed_settings() as $name => $definition ) {
			$value = get_option( $name, null );
			if ( null !== $value ) {
				$settings[ $name ] = $value;
			}
		}
		$json = wp_json_encode( array(
			'plugin' => 'google-drive-policy-linker',
			'format_version' => 1,
			'plugin_version' => GDV_VERSION,
			'settings' => $settings,
		), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="gdv-settings-' . gmdate( 'Y-m-d' ) . '.json"' );
		header( 'X-Content-Type-Options: nosniff' );
		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON download.
		exit;
	}

	/** Validate the complete file before changing any options. */
	public function validate_import( $json ) {
		$data = json_decode( $json, true, 32 );
		if ( ! is_array( $data ) || 'google-drive-policy-linker' !== ( $data['plugin'] ?? '' ) || 1 !== ( $data['format_version'] ?? null ) || empty( $data['settings'] ) || ! is_array( $data['settings'] ) ) {
			return new WP_Error( 'invalid_settings', __( 'Choose a valid plugin settings export. No settings were changed.', 'gdrive-folder-viewer' ) );
		}
		$allowed = $this->allowed_settings();
		$clean = array();
		foreach ( $data['settings'] as $name => $value ) {
			if ( ! isset( $allowed[ $name ] ) || ( ! is_string( $value ) && ! is_int( $value ) ) ) {
				return new WP_Error( 'invalid_option', __( 'The file contains unsupported settings or values. No settings were changed.', 'gdrive-folder-viewer' ) );
			}
			if ( 'gdv_ip_whitelist' === $name ) {
				foreach ( preg_split( '/\R/', (string) $value ) as $line ) {
					$parts = explode( '|', $line, 2 );
					if ( '' !== trim( $line ) && ! filter_var( trim( $parts[0] ), FILTER_VALIDATE_IP ) ) {
						return new WP_Error( 'invalid_ip', __( 'The file contains an invalid whitelist IP address. No settings were changed.', 'gdrive-folder-viewer' ) );
					}
				}
			}
			$clean[ $name ] = call_user_func( $allowed[ $name ]['sanitize_callback'], $value );
		}
		return $clean;
	}

	public function import_settings() {
		$this->authorize( 'gdv_import_settings' );
		$file = $_FILES['gdv_settings_file'] ?? array();
		if ( ! isset( $file['error'], $file['tmp_name'] ) || UPLOAD_ERR_OK !== $file['error'] || ! is_string( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) || filesize( $file['tmp_name'] ) > 1048576 ) {
			wp_die( esc_html__( 'Upload a settings JSON file smaller than 1 MB. No settings were changed.', 'gdrive-folder-viewer' ) );
		}
		$json = file_get_contents( $file['tmp_name'] );
		$result = $this->validate_import( false === $json ? '' : $json );
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ) );
		}
		foreach ( $result as $name => $value ) {
			update_option( $name, $value );
		}
		// Cached folder responses may belong to the previous API configuration.
		$api = new GDV_Drive_API();
		foreach ( array_keys( (array) get_option( 'gdv_known_folders', array() ) ) as $folder_id ) {
			$api->clear_cache( $folder_id );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=gdv-settings&tab=tools&gdv_notice=settings_imported' ) );
		exit;
	}

	public static function render_controls() {
		?>
		<h2><?php esc_html_e( 'Export and import settings', 'gdrive-folder-viewer' ); ?></h2>
		<p><?php esc_html_e( 'Download saved general, design and Gemini settings, including IP exclusions. Save any pending changes before exporting. Click history, Gemini logs, site logos and page shortcodes are not included.', 'gdrive-folder-viewer' ); ?></p>
		<p><?php esc_html_e( 'The file includes your API keys. Keep it private. When importing to another school, review alert recipients, IP exclusions and any website restrictions on your API keys.', 'gdrive-folder-viewer' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="gdv_export_settings">
			<?php wp_nonce_field( 'gdv_export_settings' ); ?>
			<?php submit_button( __( 'Export Settings', 'gdrive-folder-viewer' ), 'secondary', 'submit', false ); ?>
		</form>
		<p><?php esc_html_e( 'Import replaces settings included in the file, including the IP whitelist. Settings absent from the file and existing activity history are kept.', 'gdrive-folder-viewer' ); ?></p>
		<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="gdv_import_settings">
			<?php wp_nonce_field( 'gdv_import_settings' ); ?>
			<label for="gdv_settings_file"><?php esc_html_e( 'Settings file (JSON, maximum 1 MB)', 'gdrive-folder-viewer' ); ?></label>
			<input id="gdv_settings_file" name="gdv_settings_file" type="file" accept=".json,application/json" required>
			<?php submit_button( __( 'Import Settings', 'gdrive-folder-viewer' ), 'primary' ); ?>
		</form>
		<?php
	}
}
