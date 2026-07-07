<?php
/**
 * Registers and renders the [gdrive_folder] shortcode.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GDV_Shortcode {

	public function __construct() {
		add_shortcode( 'gdrive_folder', array( $this, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
	}

	/**
	 * Registers front-end CSS and loads the click tracker script.
	 *
	 * @return void
	 */
	public function register_assets() {
		wp_register_style( 'gdv-style', GDV_PLUGIN_URL . 'assets/css/gdv-style.css', array(), GDV_VERSION );
		wp_register_script( 'gdv-script', GDV_PLUGIN_URL . 'assets/js/gdv-script.js', array(), GDV_VERSION, false );

		wp_localize_script(
			'gdv-script',
			'gdvData',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'gdv_track_click' ),
			)
		);

		wp_enqueue_script( 'gdv-script' );
	}

	/**
	 * Shortcode callback.
	 *
	 * Usage: [gdrive_folder id="FOLDER_ID" cache="6" title="Our Policies" limit="0"]
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function render( $atts ) {
		$atts = shortcode_atts(
			array(
				'id'    => '',
				'cache' => '',
				'title' => '',
				'limit' => 0,
			),
			$atts,
			'gdrive_folder'
		);

		$folder_id = trim( (string) $atts['id'] );

		if ( empty( $folder_id ) ) {
			return $this->maybe_admin_notice(
				__( 'Google Drive Folder Viewer: please add an "id" attribute containing a valid Google Drive folder ID, e.g. [gdrive_folder id="1AbCDeFGhijkLmNoPQrstuVWxyz"]', 'gdrive-folder-viewer' )
			);
		}

		$this->remember_folder( $folder_id );

		$cache_hours = ( '' !== $atts['cache'] && is_numeric( $atts['cache'] ) )
			? (int) $atts['cache']
			: (int) get_option( 'gdv_cache_hours', 6 );
		$cache_hours = max( 1, $cache_hours );

		$api   = new GDV_Drive_API();
		$files = $api->get_folder_contents( $folder_id, $cache_hours );

		if ( is_wp_error( $files ) ) {
			return $this->maybe_admin_notice(
				sprintf(
					/* translators: %s: error message returned by the API */
					__( 'Google Drive Folder Viewer error: %s', 'gdrive-folder-viewer' ),
					$files->get_error_message()
				),
				__( 'Sorry, the document list could not be loaded right now. Please try again later.', 'gdrive-folder-viewer' )
			);
		}

		if ( empty( $files ) ) {
			return '<p class="gdv-empty">' . esc_html__( 'No documents were found in this folder.', 'gdrive-folder-viewer' ) . '</p>';
		}

		if ( (int) $atts['limit'] > 0 ) {
			$files = array_slice( $files, 0, (int) $atts['limit'] );
		}

		$this->enqueue_front_end_assets();

		ob_start();

		echo '<div class="gdv-folder-wrapper">';

		if ( ! empty( $atts['title'] ) ) {
			echo '<h3 class="gdv-title">' . esc_html( $atts['title'] ) . '</h3>';
		}

		echo '<ul class="gdv-file-list">';
		foreach ( $files as $file ) {
			$this->render_file_item( $file, $folder_id );
		}
		echo '</ul>';

		echo '</div>';

		return ob_get_clean();
	}

	/**
	 * Enqueues front-end styling when the shortcode actually renders.
	 *
	 * @return void
	 */
	private function enqueue_front_end_assets() {
		wp_enqueue_style( 'dashicons' );
		wp_enqueue_style( 'gdv-style' );
		wp_add_inline_style( 'gdv-style', $this->get_design_css() );
	}

	/**
	 * Outputs a single file/folder list item.
	 *
	 * @param array  $file      Google Drive file resource.
	 * @param string $folder_id Parent folder ID (used for click tracking context).
	 * @return void
	 */
	private function render_file_item( $file, $folder_id ) {
		$name = isset( $file['name'] ) ? $file['name'] : '';
		$link = isset( $file['webViewLink'] ) ? $file['webViewLink'] : '#';
		$mime = isset( $file['mimeType'] ) ? $file['mimeType'] : '';
		$id   = isset( $file['id'] ) ? $file['id'] : '';
		$type = $this->get_file_type( $mime, $name );

		$modified_label = '';
		if ( ! empty( $file['modifiedTime'] ) ) {
			$timestamp      = strtotime( $file['modifiedTime'] );
			$modified_label = $timestamp ? date_i18n( get_option( 'date_format' ), $timestamp ) : '';
		}

		$size_label = '';
		if ( ! empty( $file['size'] ) && is_numeric( $file['size'] ) ) {
			$size_label = size_format( (int) $file['size'] );
		}

		echo '<li class="gdv-file-item">';

		echo '<a href="' . esc_url( $link ) . '" target="_blank" rel="nofollow noopener noreferrer" class="gdv-policy-link gdv-file-type-' . esc_attr( $type['class'] ) . '" ';
		echo 'data-file-id="' . esc_attr( $id ) . '" data-file-name="' . esc_attr( $name ) . '" data-folder-id="' . esc_attr( $folder_id ) . '">';
		echo '<span class="gdv-file-main">';
		echo '<span class="gdv-file-icon" aria-hidden="true">' . esc_html( $type['label'] ) . '</span>';
		echo '<span class="gdv-file-name">' . esc_html( $name ) . '</span>';
		echo '</span>';

		$meta_parts = array_filter( array( $modified_label, $size_label ) );
		echo '<span class="gdv-file-meta">' . esc_html( implode( ' | ', $meta_parts ) ) . '</span>';
		echo '</a>';

		echo '</li>';
	}

	/**
	 * Maps a Google Drive mime type and filename to a display type.
	 *
	 * @param string $mime Mime type string.
	 * @param string $name File name.
	 * @return array
	 */
	private function get_file_type( $mime, $name ) {
		$extension = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );

		if ( false !== strpos( $mime, 'folder' ) ) {
			return array( 'class' => 'folder', 'label' => 'DIR' );
		}
		if ( false !== strpos( $mime, 'pdf' ) || 'pdf' === $extension ) {
			return array( 'class' => 'pdf', 'label' => 'PDF' );
		}
		if ( false !== strpos( $mime, 'spreadsheet' ) || in_array( $extension, array( 'xls', 'xlsx', 'csv' ), true ) ) {
			return array( 'class' => 'spreadsheet', 'label' => 'XLS' );
		}
		if ( false !== strpos( $mime, 'document' ) || false !== strpos( $mime, 'word' ) || in_array( $extension, array( 'doc', 'docx' ), true ) ) {
			return array( 'class' => 'document', 'label' => 'DOC' );
		}
		if ( false !== strpos( $mime, 'presentation' ) || in_array( $extension, array( 'ppt', 'pptx' ), true ) ) {
			return array( 'class' => 'presentation', 'label' => 'PPT' );
		}
		if ( false !== strpos( $mime, 'image' ) || in_array( $extension, array( 'jpg', 'jpeg', 'png', 'gif', 'webp' ), true ) ) {
			return array( 'class' => 'image', 'label' => 'IMG' );
		}

		return array( 'class' => 'file', 'label' => 'FILE' );
	}

	/**
	 * Builds front-end CSS variables from saved design settings.
	 *
	 * @return string
	 */
	private function get_design_css() {
		$fonts = array(
			'inherit' => 'inherit',
			'system'  => 'Arial, Helvetica, sans-serif',
			'serif'   => 'Georgia, serif',
			'mono'    => 'Consolas, Monaco, monospace',
		);

		$font_key = get_option( 'gdv_design_font_family', 'inherit' );
		$font     = isset( $fonts[ $font_key ] ) ? $fonts[ $font_key ] : $fonts['inherit'];

		$values = array(
			'--gdv-bg'             => get_option( 'gdv_design_bg_color', '#ffffff' ),
			'--gdv-hover-bg'       => get_option( 'gdv_design_hover_bg_color', '#f3f7fb' ),
			'--gdv-text'           => get_option( 'gdv_design_text_color', '#1f2937' ),
			'--gdv-hover-text'     => get_option( 'gdv_design_hover_text_color', '#0b5cab' ),
			'--gdv-meta'           => get_option( 'gdv_design_meta_color', '#646970' ),
			'--gdv-border'         => get_option( 'gdv_design_border_color', '#d9e2ec' ),
			'--gdv-text-size'      => max( 1, (int) get_option( 'gdv_design_text_size', 16 ) ) . 'px',
			'--gdv-meta-size'      => max( 1, (int) get_option( 'gdv_design_meta_size', 13 ) ) . 'px',
			'--gdv-icon-size'      => max( 1, (int) get_option( 'gdv_design_icon_size', 38 ) ) . 'px',
			'--gdv-row-padding'    => max( 1, (int) get_option( 'gdv_design_row_padding', 14 ) ) . 'px',
			'--gdv-border-radius'  => max( 0, (int) get_option( 'gdv_design_border_radius', 8 ) ) . 'px',
			'--gdv-font-family'    => $font,
		);

		$css = '.gdv-folder-wrapper{';
		foreach ( $values as $property => $value ) {
			$css .= $property . ':' . esc_attr( $value ) . ';';
		}
		$css .= '}';

		return $css;
	}

	/**
	 * Records that a folder ID has been used, so the admin "clear cache"
	 * tool knows which transients to look for.
	 *
	 * @param string $folder_id Google Drive folder ID.
	 * @return void
	 */
	private function remember_folder( $folder_id ) {
		$known = get_option( 'gdv_known_folders', array() );
		if ( ! is_array( $known ) ) {
			$known = array();
		}
		$known[ $folder_id ] = time();
		update_option( 'gdv_known_folders', $known, false );
	}

	/**
	 * Returns an admin-only detailed error message, or a generic visitor-facing
	 * message, depending on the current user's capabilities.
	 *
	 * @param string $admin_message    Detailed message shown to administrators.
	 * @param string $public_message   Generic message shown to everyone else.
	 * @return string
	 */
	private function maybe_admin_notice( $admin_message, $public_message = '' ) {
		if ( current_user_can( 'manage_options' ) ) {
			return '<p class="gdv-error">' . esc_html( $admin_message ) . '</p>';
		}
		if ( empty( $public_message ) ) {
			return '';
		}
		return '<p class="gdv-error">' . esc_html( $public_message ) . '</p>';
	}
}
