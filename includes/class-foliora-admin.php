<?php
/**
 * Foliora_Admin
 *
 * Top-level Foliora menu: Dashboard (home) + Settings. Dashboard is
 * the interactive shell — setup checklist, embed builder, Pro
 * feature list. Settings still owns Viewer Defaults via the Settings
 * API (PAGE_SLUG), so Foliora Pro can keep adding fields there.
 *
 * PAGE_SLUG stays `foliora-settings` because Pro's Protected Links
 * submenu and settings sections parent off it.
 *
 * @package Foliora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Foliora_Admin {

	/**
	 * Settings API option group for this screen. Add-ons (Foliora Pro)
	 * must register_setting() against this same group so the single
	 * settings_fields() call in render_settings_page() covers every
	 * option on the page. Store add-on data under a *different* option
	 * name — this class's sanitize_settings() only ever writes
	 * OPTION_NAME and must not see or clobber add-on options.
	 */
	const OPTION_GROUP  = 'foliora_settings_group';
	const OPTION_NAME   = 'foliora_settings';
	const PAGE_SLUG     = 'foliora-settings';
	const SETTINGS_SLUG = 'foliora-viewer-settings';
	const DOCS_SLUG     = 'foliora-documents';

	public function init() {
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'wp_ajax_foliora_mark_setup_copied', array( $this, 'ajax_mark_setup_copied' ) );
		add_action( 'wp_ajax_foliora_create_test_page', array( $this, 'ajax_create_test_page' ) );
		add_action( 'wp_ajax_foliora_dismiss_welcome', array( $this, 'ajax_dismiss_welcome' ) );
		// Invalidate the embed-count cache whenever a post is saved, trashed,
		// restored, or deleted so the Dashboard metric stays accurate without
		// waiting for the 5-minute TTL. count_embeds() only counts
		// publish/draft/private posts, so trash/untrash need their own hooks —
		// save_post does not reliably fire for those status transitions.
		add_action( 'save_post', array( $this, 'flush_embed_count_cache' ) );
		add_action( 'delete_post', array( $this, 'flush_embed_count_cache' ) );
		add_action( 'trashed_post', array( $this, 'flush_embed_count_cache' ) );
		add_action( 'untrashed_post', array( $this, 'flush_embed_count_cache' ) );
	}

	/**
	 * Delete the cached embed count so the Dashboard stat is recalculated on
	 * the next page load. Called on save_post and delete_post.
	 */
	public function flush_embed_count_cache() {
		wp_cache_delete( 'embed_count', 'foliora' );
	}

	public function add_settings_page() {
		add_menu_page(
			__( 'Foliora', 'foliora' ),
			__( 'Foliora', 'foliora' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_dashboard' ),
			'dashicons-media-document',
			58
		);

		add_submenu_page(
			self::PAGE_SLUG,
			__( 'Foliora Dashboard', 'foliora' ),
			__( 'Dashboard', 'foliora' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_dashboard' )
		);

		add_submenu_page(
			self::PAGE_SLUG,
			__( 'Foliora Docs & Features', 'foliora' ),
			__( 'Docs & Features', 'foliora' ),
			'manage_options',
			self::DOCS_SLUG,
			array( $this, 'render_documents_page' )
		);

		add_submenu_page(
			self::PAGE_SLUG,
			__( 'Foliora Settings', 'foliora' ),
			__( 'Settings', 'foliora' ),
			'manage_options',
			self::SETTINGS_SLUG,
			array( $this, 'render_settings_page' )
		);
	}

	public function enqueue_admin_assets( $hook ) {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- admin screen routing.
		$is_dashboard = ( self::PAGE_SLUG === $page || 'toplevel_page_' . self::PAGE_SLUG === $hook );
		$is_settings  = ( self::SETTINGS_SLUG === $page || self::PAGE_SLUG . '_page_' . self::SETTINGS_SLUG === $hook );
		$is_docs      = ( self::DOCS_SLUG === $page || self::PAGE_SLUG . '_page_' . self::DOCS_SLUG === $hook || 'foliora_page_' . self::DOCS_SLUG === $hook );

		if ( ! $is_dashboard && ! $is_settings && ! $is_docs ) {
			return;
		}

		wp_enqueue_style(
			'foliora-admin',
			FOLIORA_URL . 'assets/css/foliora-admin.css',
			array(),
			FOLIORA_VERSION
		);

		if ( $is_settings ) {
			wp_enqueue_style(
				'foliora-viewer',
				FOLIORA_URL . 'assets/css/foliora-viewer.css',
				array(),
				FOLIORA_VERSION
			);
			wp_enqueue_script(
				'foliora-pdfjs',
				FOLIORA_URL . 'assets/vendor/pdfjs/pdf.min.js',
				array(),
				FOLIORA_VERSION,
				true
			);
			wp_enqueue_script(
				'foliora-viewer',
				FOLIORA_URL . 'assets/js/foliora-viewer.js',
				array( 'foliora-pdfjs' ),
				FOLIORA_VERSION,
				true
			);
			wp_localize_script(
				'foliora-viewer',
				'FolioraConfig',
				array(
					'workerSrc' => FOLIORA_URL . 'assets/vendor/pdfjs/pdf.worker.min.js',
					'isPro'     => Foliora_Loader::is_pro_active(),
					'i18n'      => Foliora_Viewer::viewer_i18n(),
				)
			);
		}

		if ( $is_docs && current_user_can( 'upload_files' ) ) {
			wp_enqueue_script(
				'foliora-pdfjs',
				FOLIORA_URL . 'assets/vendor/pdfjs/pdf.min.js',
				array(),
				FOLIORA_VERSION,
				true
			);
			wp_enqueue_script(
				'foliora-thumbnail-gen',
				FOLIORA_URL . 'assets/js/foliora-thumbnail-gen.js',
				array( 'foliora-pdfjs' ),
				FOLIORA_VERSION,
				true
			);
			wp_localize_script(
				'foliora-thumbnail-gen',
				'FolioraThumbGen',
				array(
					'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
					'nonce'     => wp_create_nonce( 'foliora_admin' ),
					'workerSrc' => FOLIORA_URL . 'assets/vendor/pdfjs/pdf.worker.min.js',
					'maxPages'  => 40,
					'maxChars'  => 80000,
					'i18n'      => array(
						'indexed'      => __( 'Indexed', 'foliora' ),
						'noText'       => __( 'No text', 'foliora' ),
						'tagged'       => __( 'Tagged', 'foliora' ),
						'untagged'     => __( 'Untagged', 'foliora' ),
						'untaggedHint' => __( 'This PDF has no accessibility tags. Export it from Word, InDesign, or Acrobat as a tagged PDF so screen readers can follow headings and reading order.', 'foliora' ),
					),
				)
			);
		}

		if ( $is_dashboard || $is_docs ) {
			wp_enqueue_media();
		}

		wp_enqueue_script(
			'foliora-admin',
			FOLIORA_URL . 'assets/js/foliora-admin.js',
			array(),
			FOLIORA_VERSION,
			true
		);

		wp_localize_script(
			'foliora-admin',
			'FolioraAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'foliora_admin' ),
				'i18n'    => array(
					'selectPdf'     => __( 'Select PDF', 'foliora' ),
					'usePdf'        => __( 'Use this PDF', 'foliora' ),
					'copied'        => __( 'Copied!', 'foliora' ),
					'copiedToast'   => __( 'Shortcode copied', 'foliora' ),
					'dismissed'     => __( 'Dismissed', 'foliora' ),
					/* translators: 1: completed steps, 2: total steps. */
					'stepsComplete' => __( '%1$s of %2$s steps complete', 'foliora' ),
					'setupDone'     => __( 'You are set. New documents go in Documents or the Media Library.', 'foliora' ),
					'dropHint'      => __( 'Drop a PDF to embed it', 'foliora' ),
					'uploading'     => __( 'Uploading…', 'foliora' ),
					'creating'      => __( 'Creating page…', 'foliora' ),
					'notPdf'        => __( 'Please drop a PDF file.', 'foliora' ),
					'uploadFail'    => __( 'Upload failed. Try the Media Library.', 'foliora' ),
				),
				'uploadNonce' => wp_create_nonce( 'media-form' ),
				'canUpload'   => current_user_can( 'upload_files' ),
			)
		);
	}

	public function register_settings() {
		register_setting( self::OPTION_GROUP, self::OPTION_NAME, array( $this, 'sanitize_settings' ) );

		add_settings_section(
			'foliora_general',
			__( 'Viewer Defaults', 'foliora' ),
			'__return_false',
			self::PAGE_SLUG
		);

		add_settings_field(
			'default_width',
			__( 'Default width', 'foliora' ),
			array( $this, 'field_default_width' ),
			self::PAGE_SLUG,
			'foliora_general'
		);

		add_settings_field(
			'default_height',
			__( 'Default height', 'foliora' ),
			array( $this, 'field_default_height' ),
			self::PAGE_SLUG,
			'foliora_general'
		);

		add_settings_field(
			'allow_download',
			__( 'Download button', 'foliora' ),
			array( $this, 'field_allow_download' ),
			self::PAGE_SLUG,
			'foliora_general'
		);

		add_settings_field(
			'allow_print',
			__( 'Print button', 'foliora' ),
			array( $this, 'field_allow_print' ),
			self::PAGE_SLUG,
			'foliora_general'
		);

		add_settings_field(
			'default_theme',
			__( 'Default theme', 'foliora' ),
			array( $this, 'field_default_theme' ),
			self::PAGE_SLUG,
			'foliora_general'
		);

		add_settings_field(
			'lazy_loading',
			__( 'Lazy loading', 'foliora' ),
			array( $this, 'field_lazy_loading' ),
			self::PAGE_SLUG,
			'foliora_general'
		);

		add_settings_section(
			'foliora_toolbar',
			__( 'Toolbar Controls', 'foliora' ),
			array( $this, 'section_toolbar' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'allow_search',
			__( 'Search in document', 'foliora' ),
			array( $this, 'field_allow_search' ),
			self::PAGE_SLUG,
			'foliora_toolbar'
		);

		add_settings_field(
			'allow_theme',
			__( 'Theme switcher', 'foliora' ),
			array( $this, 'field_allow_theme' ),
			self::PAGE_SLUG,
			'foliora_toolbar'
		);

		add_settings_field(
			'allow_share',
			__( 'Share button', 'foliora' ),
			array( $this, 'field_allow_share' ),
			self::PAGE_SLUG,
			'foliora_toolbar'
		);

		add_settings_field(
			'allow_shortcuts',
			__( 'Keyboard shortcuts guide', 'foliora' ),
			array( $this, 'field_allow_shortcuts' ),
			self::PAGE_SLUG,
			'foliora_toolbar'
		);

		add_settings_field(
			'allow_fullscreen',
			__( 'Full screen button', 'foliora' ),
			array( $this, 'field_allow_fullscreen' ),
			self::PAGE_SLUG,
			'foliora_toolbar'
		);

		add_settings_field(
			'allow_presentation',
			__( 'Presentation button', 'foliora' ),
			array( $this, 'field_allow_presentation' ),
			self::PAGE_SLUG,
			'foliora_toolbar'
		);

		add_settings_section(
			'foliora_discover',
			__( 'Search and sharing', 'foliora' ),
			array( $this, 'section_discover' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'index_pdf_text',
			__( 'Site search', 'foliora' ),
			array( $this, 'field_index_pdf_text' ),
			self::PAGE_SLUG,
			'foliora_discover'
		);

		add_settings_field(
			'social_preview',
			__( 'Social preview', 'foliora' ),
			array( $this, 'field_social_preview' ),
			self::PAGE_SLUG,
			'foliora_discover'
		);

		add_settings_field(
			'check_pdf_a11y',
			__( 'Accessibility tags', 'foliora' ),
			array( $this, 'field_check_pdf_a11y' ),
			self::PAGE_SLUG,
			'foliora_discover'
		);

		add_settings_field(
			'embed_attachment_pages',
			__( 'Attachment pages', 'foliora' ),
			array( $this, 'field_embed_attachment_pages' ),
			self::PAGE_SLUG,
			'foliora_discover'
		);
	}

	/**
	 * Bound only to OPTION_NAME (`foliora_settings`). Returning a fresh
	 * array of free-plugin keys means extra POST keys cannot persist
	 * here, and a separately registered option such as
	 * `foliora_pro_settings` is never read or written.
	 */
	public function sanitize_settings( $input ) {
		$existing = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}
		if ( ! is_array( $input ) ) {
			$input = array();
		}

		$copied = array_key_exists( 'setup_copied_shortcode', $input )
			? ! empty( $input['setup_copied_shortcode'] )
			: ! empty( $existing['setup_copied_shortcode'] );

		$form = array_key_exists( 'default_width', $input );

		$valid_themes = array( 'light', 'dark', 'sepia' );
		$theme = isset( $input['default_theme'] ) && in_array( $input['default_theme'], $valid_themes, true )
			? $input['default_theme']
			: ( $existing['default_theme'] ?? 'light' );

		return array(
			'default_width'          => isset( $input['default_width'] ) ? $this->sanitize_css_dimension( $input['default_width'], $existing['default_width'] ?? '100%' ) : ( $existing['default_width'] ?? '100%' ),
			'default_height'         => isset( $input['default_height'] ) ? $this->sanitize_css_dimension( $input['default_height'], $existing['default_height'] ?? '600px' ) : ( $existing['default_height'] ?? '600px' ),
			'default_theme'          => $theme,
			'lazy_loading'           => $form ? ! empty( $input['lazy_loading'] ) : ( $existing['lazy_loading'] ?? false ),
			'allow_download'         => $form ? ! empty( $input['allow_download'] ) : ( $existing['allow_download'] ?? true ),
			'allow_print'            => $form ? ! empty( $input['allow_print'] ) : ( $existing['allow_print'] ?? true ),
			'allow_search'           => $form ? ! empty( $input['allow_search'] ) : ( $existing['allow_search'] ?? true ),
			'allow_theme'            => $form ? ! empty( $input['allow_theme'] ) : ( $existing['allow_theme'] ?? true ),
			'allow_share'            => $form ? ! empty( $input['allow_share'] ) : ( $existing['allow_share'] ?? true ),
			'allow_shortcuts'        => $form ? ! empty( $input['allow_shortcuts'] ) : ( $existing['allow_shortcuts'] ?? true ),
			'allow_fullscreen'       => $form ? ! empty( $input['allow_fullscreen'] ) : ( $existing['allow_fullscreen'] ?? true ),
			'allow_presentation'     => $form ? ! empty( $input['allow_presentation'] ) : ( $existing['allow_presentation'] ?? true ),
			'index_pdf_text'         => $form ? ! empty( $input['index_pdf_text'] ) : ( $existing['index_pdf_text'] ?? true ),
			'social_preview'         => $form ? ! empty( $input['social_preview'] ) : ( $existing['social_preview'] ?? true ),
			'check_pdf_a11y'         => $form ? ! empty( $input['check_pdf_a11y'] ) : ( $existing['check_pdf_a11y'] ?? true ),
			'embed_attachment_pages' => $form ? ! empty( $input['embed_attachment_pages'] ) : ( $existing['embed_attachment_pages'] ?? true ),
			'setup_copied_shortcode' => $copied,
		);
	}

	/**
	 * Validate a user-supplied CSS dimension value. Accepts common units:
	 * px, %, em, rem, vh, vw, svh, dvh, lvh. Returns $default on failure.
	 *
	 * @param string $value   Raw value from the settings form.
	 * @param string $default Fallback if the value is not a valid CSS dimension.
	 * @return string
	 */
	private function sanitize_css_dimension( $value, $default ) {
		$value = trim( sanitize_text_field( (string) $value ) );
		// Allow a positive number followed by a recognised CSS length unit.
		if ( preg_match( '/^\d+(\.\d+)?(px|%|em|rem|vh|vw|svh|dvh|lvh)$/', $value ) ) {
			return $value;
		}
		// Also allow bare "0" (no unit required for zero).
		if ( '0' === $value ) {
			return $value;
		}
		return $default;
	}

	public function section_toolbar() {
		echo '<p class="description">' . esc_html__( 'Enable or disable specific buttons across all embedded PDF viewers. Individual embeds can also customize controls via the "hide" attribute.', 'foliora' ) . '</p>';
	}

	public function field_default_theme() {
		$settings = get_option( self::OPTION_NAME, array() );
		$current  = $settings['default_theme'] ?? 'light';
		?>
		<select name="<?php echo esc_attr( self::OPTION_NAME ); ?>[default_theme]" id="foliora-default-theme">
			<option value="light" <?php selected( $current, 'light' ); ?>><?php esc_html_e( 'Light (Default)', 'foliora' ); ?></option>
			<option value="dark" <?php selected( $current, 'dark' ); ?>><?php esc_html_e( 'Dark', 'foliora' ); ?></option>
			<option value="sepia" <?php selected( $current, 'sepia' ); ?>><?php esc_html_e( 'Sepia (Warm)', 'foliora' ); ?></option>
		</select>
		<p class="description"><?php esc_html_e( 'Default reader color scheme for visitors.', 'foliora' ); ?></p>
		<?php
	}

	public function field_lazy_loading() {
		$settings = get_option( self::OPTION_NAME, array() );
		$checked  = ! empty( $settings['lazy_loading'] );
		printf(
			'<label><input type="checkbox" name="%1$s[lazy_loading]" value="1" %2$s /> %3$s</label>',
			esc_attr( self::OPTION_NAME ),
			checked( $checked, true, false ),
			esc_html__( 'Only load PDF documents when they scroll into the visitor viewport (faster initial page load).', 'foliora' )
		);
	}

	public function field_allow_search() {
		$settings = get_option( self::OPTION_NAME, array() );
		$checked  = ! isset( $settings['allow_search'] ) || ! empty( $settings['allow_search'] );
		printf(
			'<label><input type="checkbox" name="%1$s[allow_search]" value="1" %2$s /> %3$s</label>',
			esc_attr( self::OPTION_NAME ),
			checked( $checked, true, false ),
			esc_html__( 'Show the search/find input on the toolbar (or hide via hide="search").', 'foliora' )
		);
	}

	public function field_allow_theme() {
		$settings = get_option( self::OPTION_NAME, array() );
		$checked  = ! isset( $settings['allow_theme'] ) || ! empty( $settings['allow_theme'] );
		printf(
			'<label><input type="checkbox" name="%1$s[allow_theme]" value="1" %2$s /> %3$s</label>',
			esc_attr( self::OPTION_NAME ),
			checked( $checked, true, false ),
			esc_html__( 'Show the Dark / Light / Sepia theme toggle button.', 'foliora' )
		);
	}

	public function field_allow_share() {
		$settings = get_option( self::OPTION_NAME, array() );
		$checked  = ! isset( $settings['allow_share'] ) || ! empty( $settings['allow_share'] );
		printf(
			'<label><input type="checkbox" name="%1$s[allow_share]" value="1" %2$s /> %3$s</label>',
			esc_attr( self::OPTION_NAME ),
			checked( $checked, true, false ),
			esc_html__( 'Show the share and copy page link button.', 'foliora' )
		);
	}

	public function field_allow_shortcuts() {
		$settings = get_option( self::OPTION_NAME, array() );
		$checked  = ! isset( $settings['allow_shortcuts'] ) || ! empty( $settings['allow_shortcuts'] );
		printf(
			'<label><input type="checkbox" name="%1$s[allow_shortcuts]" value="1" %2$s /> %3$s</label>',
			esc_attr( self::OPTION_NAME ),
			checked( $checked, true, false ),
			esc_html__( 'Show the keyboard shortcuts guide button (?) and enable keyboard navigation.', 'foliora' )
		);
	}

	public function field_allow_fullscreen() {
		$settings = get_option( self::OPTION_NAME, array() );
		$checked  = ! isset( $settings['allow_fullscreen'] ) || ! empty( $settings['allow_fullscreen'] );
		printf(
			'<label><input type="checkbox" name="%1$s[allow_fullscreen]" value="1" %2$s /> %3$s</label>',
			esc_attr( self::OPTION_NAME ),
			checked( $checked, true, false ),
			esc_html__( 'Show the full screen toggle button.', 'foliora' )
		);
	}

	public function field_allow_presentation() {
		$settings = get_option( self::OPTION_NAME, array() );
		$checked  = ! isset( $settings['allow_presentation'] ) || ! empty( $settings['allow_presentation'] );
		printf(
			'<label><input type="checkbox" name="%1$s[allow_presentation]" value="1" %2$s /> %3$s</label>',
			esc_attr( self::OPTION_NAME ),
			checked( $checked, true, false ),
			esc_html__( 'Show the distraction-free presentation mode button.', 'foliora' )
		);

	}

	public function field_default_width() {
		$settings = get_option( self::OPTION_NAME, array() );
		printf(
			'<input type="text" id="foliora-default-width" name="%1$s[default_width]" value="%2$s" class="regular-text" placeholder="100%%" />',
			esc_attr( self::OPTION_NAME ),
			esc_attr( $settings['default_width'] ?? '100%' )
		);
	}

	public function field_default_height() {
		$settings = get_option( self::OPTION_NAME, array() );
		printf(
			'<input type="text" id="foliora-default-height" name="%1$s[default_height]" value="%2$s" class="regular-text" placeholder="600px" />',
			esc_attr( self::OPTION_NAME ),
			esc_attr( $settings['default_height'] ?? '600px' )
		);
	}

	public function field_allow_download() {
		$settings = get_option( self::OPTION_NAME, array() );
		$checked  = ! isset( $settings['allow_download'] ) || ! empty( $settings['allow_download'] );
		printf(
			'<label><input type="checkbox" name="%1$s[allow_download]" value="1" %2$s /> %3$s</label>',
			esc_attr( self::OPTION_NAME ),
			checked( $checked, true, false ),
			esc_html__( 'Show the download button on the viewer toolbar. Individual embeds can override this with download="false".', 'foliora' )
		);
	}

	public function field_allow_print() {
		$settings = get_option( self::OPTION_NAME, array() );
		$checked  = ! isset( $settings['allow_print'] ) || ! empty( $settings['allow_print'] );
		printf(
			'<label><input type="checkbox" name="%1$s[allow_print]" value="1" %2$s /> %3$s</label>',
			esc_attr( self::OPTION_NAME ),
			checked( $checked, true, false ),
			esc_html__( 'Show the print button on the viewer toolbar. Individual embeds can override this with print="false".', 'foliora' )
		);
	}

	public function section_discover() {
		echo '<p class="description">' . esc_html__( 'Make embedded PDFs findable in WordPress search and nicer when a page is shared.', 'foliora' ) . '</p>';
	}

	public function field_index_pdf_text() {
		$settings = get_option( self::OPTION_NAME, array() );
		$checked  = ! isset( $settings['index_pdf_text'] ) || ! empty( $settings['index_pdf_text'] );
		printf(
			'<label><input type="checkbox" name="%1$s[index_pdf_text]" value="1" %2$s /> %3$s</label>',
			esc_attr( self::OPTION_NAME ),
			checked( $checked, true, false ),
			esc_html__( 'Include text from embedded PDFs in WordPress site search (and in the document library search). Visit Foliora → Documents to extract text.', 'foliora' )
		);
	}

	public function field_social_preview() {
		$settings = get_option( self::OPTION_NAME, array() );
		$checked  = ! isset( $settings['social_preview'] ) || ! empty( $settings['social_preview'] );
		printf(
			'<label><input type="checkbox" name="%1$s[social_preview]" value="1" %2$s /> %3$s</label>',
			esc_attr( self::OPTION_NAME ),
			checked( $checked, true, false ),
			esc_html__( 'Use the PDF first-page thumbnail as the Open Graph / Twitter image when a post or page embeds Foliora and no SEO plugin already set an image.', 'foliora' )
		);
	}

	public function field_check_pdf_a11y() {
		$settings = get_option( self::OPTION_NAME, array() );
		$checked  = ! isset( $settings['check_pdf_a11y'] ) || ! empty( $settings['check_pdf_a11y'] );
		printf(
			'<label><input type="checkbox" name="%1$s[check_pdf_a11y]" value="1" %2$s /> %3$s</label>',
			esc_attr( self::OPTION_NAME ),
			checked( $checked, true, false ),
			esc_html__( 'Warn on Foliora → Documents when a PDF is not tagged. Tagged PDFs include structure (headings, reading order) that screen readers can follow.', 'foliora' )
		);
	}

	public function field_embed_attachment_pages() {
		$settings = get_option( self::OPTION_NAME, array() );
		$checked  = ! isset( $settings['embed_attachment_pages'] ) || ! empty( $settings['embed_attachment_pages'] );
		printf(
			'<label><input type="checkbox" name="%1$s[embed_attachment_pages]" value="1" %2$s /> %3$s</label>',
			esc_attr( self::OPTION_NAME ),
			checked( $checked, true, false ),
			esc_html__( 'Automatically display the interactive Foliora PDF viewer on standard WordPress PDF attachment pages.', 'foliora' )
		);
	}

	public function render_dashboard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$is_pro      = Foliora_Loader::is_pro_active();
		$pretty      = (bool) get_option( 'permalink_structure' );
		$pdf_count   = $this->count_pdfs();
		$embed_count = $this->count_embeds();
		$settings    = get_option( self::OPTION_NAME, array() );
		$copied      = ! empty( $settings['setup_copied_shortcode'] );
		$recent      = $this->get_recent_pdfs( 4 );
		$prefill     = ! empty( $recent[0] ) ? $recent[0] : null;
		$prefill_url = $prefill ? $prefill['url'] : '';
		$prefill_code = $prefill_url ? sprintf( '[foliora file="%s"]', esc_url_raw( $prefill_url ) ) : '';

		$steps = array(
			'uploaded' => $pdf_count > 0,
			'copied'   => $copied,
			'embedded' => $embed_count > 0,
		);
		$done         = count( array_filter( $steps ) );
		$total_steps  = count( $steps );
		$percent      = (int) round( ( $done / $total_steps ) * 100 );
		$setup_done   = $done === $total_steps;
		$docs_url     = admin_url( 'admin.php?page=' . self::DOCS_SLUG );
		$settings_url = admin_url( 'admin.php?page=' . self::SETTINGS_SLUG );
		$show_welcome = ! $setup_done && ! get_user_meta( get_current_user_id(), 'foliora_welcome_dismissed', true );
		?>
		<div class="wrap foliora-dash">
			<div class="foliora-dash-hero">
				<div class="foliora-dash-hero-copy">
					<div class="foliora-dash-kicker">
						<span class="foliora-mark dashicons dashicons-book-alt" aria-hidden="true"></span>
						<h1><?php esc_html_e( 'Foliora', 'foliora' ); ?></h1>
					</div>
					<p class="foliora-dash-lede">
						<?php esc_html_e( 'Embed PDFs with a bundled viewer. No CDN, no account, no API keys.', 'foliora' ); ?>
					</p>
					<?php if ( $is_pro ) : ?>
						<span class="foliora-chip is-ok"><?php esc_html_e( 'Pro active', 'foliora' ); ?></span>
					<?php endif; ?>
					<?php if ( ! $pretty ) : ?>
						<span class="foliora-chip is-warn"><?php esc_html_e( 'Plain permalinks — share links need Post name', 'foliora' ); ?></span>
					<?php endif; ?>
				</div>
				<div class="foliora-dash-hero-side">
					<div class="foliora-ring-wrap<?php echo $setup_done ? ' is-complete' : ''; ?>" id="foliora-ring-wrap">
						<svg class="foliora-ring" viewBox="0 0 36 36" aria-hidden="true">
							<circle class="foliora-ring-bg" cx="18" cy="18" r="15.5" pathLength="100" />
							<circle class="foliora-ring-fg" id="foliora-ring-fg" cx="18" cy="18" r="15.5" pathLength="100" stroke-dasharray="<?php echo esc_attr( (string) $percent ); ?> 100" />
						</svg>
						<span class="foliora-ring-label" id="foliora-ring-label"><?php echo esc_html( $done . '/' . $total_steps ); ?></span>
					</div>
					<div class="foliora-dash-hero-actions">
						<a class="button button-primary" href="<?php echo esc_url( $docs_url ); ?>">
							<?php esc_html_e( 'Documents', 'foliora' ); ?>
						</a>
						<?php if ( ! $is_pro ) : ?>
							<a class="button" href="<?php echo esc_url( Foliora_Loader::upgrade_url() ); ?>" target="_blank" rel="noopener noreferrer">
								<?php esc_html_e( 'Upgrade to Pro', 'foliora' ); ?>
							</a>
						<?php endif; ?>
					</div>
				</div>
			</div>

			<?php if ( $show_welcome ) : ?>
				<div class="foliora-dash-banner" id="foliora-welcome" role="status">
					<p>
						<?php esc_html_e( 'Pick a cover on the right — or drop a PDF — then copy the shortcode.', 'foliora' ); ?>
					</p>
					<button type="button" class="foliora-banner-dismiss" id="foliora-welcome-dismiss">
						<span class="screen-reader-text"><?php esc_html_e( 'Dismiss this notice.', 'foliora' ); ?></span>
						<span aria-hidden="true">&times;</span>
					</button>
				</div>
			<?php endif; ?>

			<div class="foliora-metrics" role="list">
				<a class="foliora-metric" role="listitem" href="<?php echo esc_url( $docs_url ); ?>">
					<span class="foliora-metric-icon is-docs"><span class="dashicons dashicons-media-document" aria-hidden="true"></span></span>
					<span class="foliora-metric-value" data-count="<?php echo esc_attr( (string) $pdf_count ); ?>"><?php echo esc_html( (string) $pdf_count ); ?></span>
					<span class="foliora-metric-label"><?php esc_html_e( 'PDFs', 'foliora' ); ?></span>
				</a>
				<div class="foliora-metric" role="listitem" id="foliora-metric-embeds">
					<span class="foliora-metric-icon is-pages"><span class="dashicons dashicons-admin-page" aria-hidden="true"></span></span>
					<span class="foliora-metric-value" data-count="<?php echo esc_attr( (string) $embed_count ); ?>"><?php echo esc_html( (string) $embed_count ); ?></span>
					<span class="foliora-metric-label"><?php esc_html_e( 'Embeds', 'foliora' ); ?></span>
				</div>
				<div class="foliora-metric" role="listitem" id="foliora-metric-setup" data-total="<?php echo esc_attr( (string) $total_steps ); ?>">
					<span class="foliora-metric-icon is-setup"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span></span>
					<span class="foliora-metric-value"><?php echo esc_html( $done . '/' . $total_steps ); ?></span>
					<span class="foliora-metric-label"><?php esc_html_e( 'Setup', 'foliora' ); ?></span>
				</div>
			</div>

			<div class="foliora-workspace">
				<section class="foliora-panel<?php echo $setup_done ? ' is-complete' : ''; ?>" id="foliora-setup-panel" aria-labelledby="foliora-setup-heading">
					<div class="foliora-panel-head">
						<div>
							<h2 id="foliora-setup-heading"><?php esc_html_e( 'Get started', 'foliora' ); ?></h2>
							<p class="foliora-card-help" id="foliora-setup-status">
								<?php
								if ( $setup_done ) {
									esc_html_e( 'You are set. New documents go in Documents or the Media Library.', 'foliora' );
								} else {
									/* translators: 1: completed steps, 2: total steps. */
									echo esc_html( sprintf( __( '%1$s of %2$s steps complete', 'foliora' ), (string) $done, (string) $total_steps ) );
								}
								?>
							</p>
						</div>
					</div>
					<div class="foliora-progress" aria-hidden="true">
						<span style="width: <?php echo esc_attr( (string) $percent ); ?>%;"></span>
					</div>
					<ol class="foliora-setup">
						<li class="<?php echo $steps['uploaded'] ? 'is-done' : ''; ?>" data-setup-step="uploaded">
							<div class="foliora-setup-copy">
								<span class="foliora-setup-mark" aria-hidden="true"></span>
								<div>
									<span class="foliora-setup-title"><?php esc_html_e( 'Upload a PDF', 'foliora' ); ?></span>
									<span class="foliora-setup-help"><?php esc_html_e( 'Add a file, or drop one on the stage.', 'foliora' ); ?></span>
								</div>
							</div>
							<a class="button" href="<?php echo esc_url( admin_url( 'media-new.php' ) ); ?>"><?php esc_html_e( 'Upload', 'foliora' ); ?></a>
						</li>
						<li class="<?php echo $steps['copied'] ? 'is-done' : ''; ?>" data-setup-step="copied">
							<div class="foliora-setup-copy">
								<span class="foliora-setup-mark" aria-hidden="true"></span>
								<div>
									<span class="foliora-setup-title"><?php esc_html_e( 'Copy a shortcode', 'foliora' ); ?></span>
									<span class="foliora-setup-help"><?php esc_html_e( 'Click the code on the stage to copy.', 'foliora' ); ?></span>
								</div>
							</div>
							<button type="button" class="button" data-foliora-focus="embed"><?php esc_html_e( 'Show stage', 'foliora' ); ?></button>
						</li>
						<li class="<?php echo $steps['embedded'] ? 'is-done' : ''; ?>" data-setup-step="embedded">
							<div class="foliora-setup-copy">
								<span class="foliora-setup-mark" aria-hidden="true"></span>
								<div>
									<span class="foliora-setup-title"><?php esc_html_e( 'Put it on a page', 'foliora' ); ?></span>
									<span class="foliora-setup-help"><?php esc_html_e( 'Publish a test page, or use the viewer block.', 'foliora' ); ?></span>
								</div>
							</div>
							<button type="button" class="button" data-foliora-focus="embed-create"><?php esc_html_e( 'Create page', 'foliora' ); ?></button>
						</li>
					</ol>
				</section>

				<section class="foliora-panel foliora-panel-embed" id="foliora-embed-panel" aria-labelledby="foliora-embed-heading">
					<div class="foliora-panel-head">
						<div>
							<h2 id="foliora-embed-heading"><?php esc_html_e( 'Embed a document', 'foliora' ); ?></h2>
							<p class="foliora-card-help"><?php esc_html_e( 'Drop a PDF, pick a cover, or browse the library.', 'foliora' ); ?></p>
						</div>
					</div>

					<div class="foliora-stage<?php echo $prefill ? '' : ' is-empty'; ?>" id="foliora-stage">
						<div class="foliora-stage-cover" aria-hidden="true">
							<img id="foliora-stage-thumb" alt="" <?php
							if ( $prefill && ! empty( $prefill['thumb'] ) ) {
								echo ' src="' . esc_url( $prefill['thumb'] ) . '"';
							} else {
								echo ' hidden';
							}
							?> />
							<span class="foliora-stage-fallback dashicons dashicons-media-document" id="foliora-stage-fallback"<?php echo ( $prefill && ! empty( $prefill['thumb'] ) ) ? ' hidden' : ''; ?>></span>
						</div>
						<div class="foliora-stage-meta">
							<p class="foliora-stage-title" id="foliora-selected-file"><?php echo $prefill ? esc_html( $prefill['title'] ) : esc_html__( 'No document selected', 'foliora' ); ?></p>
							<label class="screen-reader-text" for="foliora-shortcode-output"><?php esc_html_e( 'Shortcode', 'foliora' ); ?></label>
							<div class="foliora-code" id="foliora-code" title="<?php esc_attr_e( 'Click to copy', 'foliora' ); ?>">
								<input
									type="text"
									id="foliora-shortcode-output"
									readonly
									value="<?php echo esc_attr( $prefill_code ); ?>"
									placeholder='[foliora file="https://example.com/document.pdf"]'
								/>
								<button type="button" class="button button-primary" id="foliora-copy-shortcode" <?php disabled( ! $prefill_code ); ?>>
									<?php esc_html_e( 'Copy', 'foliora' ); ?>
								</button>
							</div>
							<div class="foliora-stage-actions">
								<button type="button" class="button" id="foliora-create-test-page" <?php disabled( ! $prefill_code ); ?>>
									<?php esc_html_e( 'Create test page', 'foliora' ); ?>
								</button>
								<button type="button" class="button-link" id="foliora-pick-pdf"><?php esc_html_e( 'Browse Media Library', 'foliora' ); ?></button>
							</div>
						</div>
						<div class="foliora-drop" id="foliora-drop" hidden>
							<span class="dashicons dashicons-upload" aria-hidden="true"></span>
							<span><?php esc_html_e( 'Drop a PDF to embed it', 'foliora' ); ?></span>
						</div>
					</div>

					<?php if ( $recent ) : ?>
						<p class="foliora-covers-label"><?php esc_html_e( 'Recent', 'foliora' ); ?></p>
						<div class="foliora-covers" role="list">
							<?php foreach ( $recent as $index => $item ) : ?>
								<button
									type="button"
									class="foliora-cover foliora-recent-item<?php echo ( 0 === $index ) ? ' is-selected' : ''; ?>"
									role="listitem"
									data-url="<?php echo esc_url( $item['url'] ); ?>"
									data-title="<?php echo esc_attr( $item['title'] ); ?>"
									data-thumb="<?php echo esc_url( $item['thumb'] ); ?>"
									aria-pressed="<?php echo 0 === $index ? 'true' : 'false'; ?>"
								>
									<?php if ( $item['thumb'] ) : ?>
										<img src="<?php echo esc_url( $item['thumb'] ); ?>" alt="" />
									<?php else : ?>
										<span class="foliora-cover-fallback dashicons dashicons-media-document" aria-hidden="true"></span>
									<?php endif; ?>
									<span class="foliora-cover-name"><?php echo esc_html( $item['title'] ); ?></span>
								</button>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>

					<p class="foliora-quiet-links">
						<a href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'Viewer defaults', 'foliora' ); ?></a>
					</p>
				</section>
			</div>

			<section class="foliora-panel foliora-panel-pro" aria-labelledby="foliora-pro-heading">
				<div class="foliora-panel-head">
					<div>
						<h2 id="foliora-pro-heading"><?php esc_html_e( 'Foliora Pro', 'foliora' ); ?></h2>
						<p class="foliora-card-help">
							<?php
							echo $is_pro
								? esc_html__( 'Unlocked on this site. Open a tile for details.', 'foliora' )
								: esc_html__( 'Optional add-on. Open a tile to see what it adds.', 'foliora' );
							?>
						</p>
					</div>
					<?php if ( ! $is_pro ) : ?>
						<a class="button" href="<?php echo esc_url( Foliora_Loader::upgrade_url() ); ?>" target="_blank" rel="noopener noreferrer">
							<?php esc_html_e( 'Upgrade to Pro', 'foliora' ); ?>
						</a>
					<?php endif; ?>
				</div>
				<div class="foliora-pro-tiles">
					<?php foreach ( Foliora_Loader::pro_features() as $key => $label ) : ?>
						<?php $enabled = Foliora_Loader::is_feature_enabled( $key ); ?>
						<details class="foliora-pro-tile">
							<summary>
								<span class="foliora-pro-icon dashicons <?php echo esc_attr( $this->pro_feature_icon( $key ) ); ?>" aria-hidden="true"></span>
								<span class="foliora-pro-row-title"><?php echo esc_html( $label ); ?></span>
								<span class="foliora-badge <?php echo $enabled ? 'is-active' : 'is-locked'; ?>">
									<?php echo $enabled ? esc_html__( 'Active', 'foliora' ) : esc_html__( 'Locked', 'foliora' ); ?>
								</span>
							</summary>
							<p><?php echo esc_html( $this->pro_feature_blurb( $key ) ); ?></p>
						</details>
					<?php endforeach; ?>
				</div>
			</section>
			<div class="foliora-toast" id="foliora-toast" hidden role="status"></div>
		</div>
		<?php
	}

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings    = get_option( self::OPTION_NAME, array() );
		$preview_url = $this->get_latest_pdf_url();
		$preview     = '';

		if ( $preview_url ) {
			$preview = Foliora::instance()->viewer->render_shortcode(
				array(
					'file'   => $preview_url,
					'width'  => $settings['default_width'] ?? '100%',
					'height' => $settings['default_height'] ?? '420px',
				)
			);
		}
		?>
		<div class="wrap foliora-settings-wrap">
			<h1><?php esc_html_e( 'Foliora Settings', 'foliora' ); ?></h1>
			<p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>">&larr; <?php esc_html_e( 'Back to Dashboard', 'foliora' ); ?></a>
			</p>

			<div class="foliora-settings-layout">
				<form method="post" action="options.php">
					<?php
					settings_fields( self::OPTION_GROUP );
					do_settings_sections( self::PAGE_SLUG );
					submit_button();
					?>
				</form>

				<div class="foliora-live-preview">
					<h2><?php esc_html_e( 'Live preview', 'foliora' ); ?></h2>
					<p class="foliora-card-help">
						<?php esc_html_e( 'Width and height update this viewer immediately. Save to store them as site defaults.', 'foliora' ); ?>
					</p>
					<?php if ( $preview ) : ?>
						<div class="foliora-live-preview-stage">
							<?php echo $preview; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- viewer HTML is built with escaped atts. ?>
						</div>
						<p class="description">
							<?php esc_html_e( 'Preview uses the most recently uploaded PDF in the Media Library.', 'foliora' ); ?>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::DOCS_SLUG ) ); ?>"><?php esc_html_e( 'Open Documents', 'foliora' ); ?></a>
						</p>
					<?php else : ?>
						<div class="foliora-live-preview-empty">
							<p><?php esc_html_e( 'Upload a PDF to see a live preview of these defaults.', 'foliora' ); ?></p>
							<a class="button" href="<?php echo esc_url( admin_url( 'media-new.php' ) ); ?>"><?php esc_html_e( 'Upload PDF', 'foliora' ); ?></a>
						</div>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	public function render_documents_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$current_tab = isset( $_GET['tab'] ) && 'features' === $_GET['tab'] ? 'features' : 'documents'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged       = max( 1, absint( wp_unslash( $_GET['paged'] ?? 1 ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search      = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$settings    = get_option( self::OPTION_NAME, array() );
		$index_on    = ! isset( $settings['index_pdf_text'] ) || ! empty( $settings['index_pdf_text'] );
		$a11y_on     = Foliora_A11y::checking_enabled();
		$is_pro      = Foliora_Loader::is_pro_active();

		$query = new WP_Query(
			array(
				'post_type'               => 'attachment',
				'post_status'             => 'inherit',
				'post_mime_type'          => 'application/pdf',
				'posts_per_page'          => 20,
				'paged'                   => $paged,
				's'                       => $search,
				'orderby'                 => 'date',
				'order'                   => 'DESC',
				'foliora_content_search'  => ( '' !== $search ),
			)
		);

		$total = (int) $query->found_posts;
		?>
		<div class="wrap foliora-docs">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Docs & Features', 'foliora' ); ?></h1>
			<?php if ( 'documents' === $current_tab ) : ?>
			<a href="<?php echo esc_url( admin_url( 'media-new.php' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Upload PDF', 'foliora' ); ?></a>
			<?php endif; ?>
			<hr class="wp-header-end" />

			<nav class="nav-tab-wrapper foliora-docs-tabs">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::DOCS_SLUG ) ); ?>" class="nav-tab <?php echo 'documents' === $current_tab ? 'nav-tab-active' : ''; ?>">
					<span class="dashicons dashicons-media-document" aria-hidden="true"></span> <?php esc_html_e( 'Documents', 'foliora' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::DOCS_SLUG . '&tab=features' ) ); ?>" class="nav-tab <?php echo 'features' === $current_tab ? 'nav-tab-active' : ''; ?>">
					<span class="dashicons dashicons-star-filled" aria-hidden="true"></span> <?php esc_html_e( 'Features & Capabilities', 'foliora' ); ?>
				</a>
			</nav>

			<?php if ( 'features' === $current_tab ) : ?>
				<?php $this->render_features_tab( $is_pro ); ?>
			<?php else : ?>

			<p class="foliora-card-help">
				<?php esc_html_e( 'PDFs already in the Media Library. Copy a shortcode to embed one with Foliora — files stay in Media, not in a separate library. Opening this screen generates first-page thumbnails, extracts PDF text for WordPress search, and checks whether each file is a tagged (accessible) PDF.', 'foliora' ); ?>
			</p>
			<p class="foliora-thumb-status" hidden><?php esc_html_e( 'Generating thumbnails, indexing PDF text, and checking accessibility tags…', 'foliora' ); ?></p>

			<form method="get" class="foliora-docs-search">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::DOCS_SLUG ); ?>" />
				<p class="search-box">
					<label class="screen-reader-text" for="foliora-docs-search"><?php esc_html_e( 'Search documents', 'foliora' ); ?></label>
					<input type="search" id="foliora-docs-search" name="s" value="<?php echo esc_attr( $search ); ?>" />
					<?php submit_button( __( 'Search', 'foliora' ), 'secondary', '', false, array( 'id' => 'search-submit' ) ); ?>
				</p>
			</form>

			<?php
			$untagged_n = 0;
			if ( $a11y_on && $query->have_posts() ) {
				foreach ( $query->posts as $attachment ) {
					if ( Foliora_A11y::is_checked( $attachment->ID ) && ! Foliora_A11y::is_tagged( $attachment->ID ) ) {
						++$untagged_n;
					}
				}
			}
			if ( $untagged_n ) :
				?>
			<div class="notice notice-warning inline foliora-a11y-notice">
				<p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: number of untagged PDFs on this page. */
							_n(
								'%s PDF on this page is not tagged. Screen readers may not follow the intended reading order. Export tagged PDFs from Word, InDesign, or Acrobat.',
								'%s PDFs on this page are not tagged. Screen readers may not follow the intended reading order. Export tagged PDFs from Word, InDesign, or Acrobat.',
								$untagged_n,
								'foliora'
							),
							number_format_i18n( $untagged_n )
						)
					);
					?>
				</p>
			</div>
			<?php endif; ?>

			<?php if ( $query->have_posts() ) : ?>
				<table class="wp-list-table widefat striped foliora-docs-table">
					<thead>
						<tr>
							<th scope="col" class="foliora-docs-thumb-col"><?php esc_html_e( 'Preview', 'foliora' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Document', 'foliora' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Search', 'foliora' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Accessibility', 'foliora' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Size', 'foliora' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Uploaded', 'foliora' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Shortcode', 'foliora' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $query->posts as $attachment ) : ?>
							<?php
							$url   = wp_get_attachment_url( $attachment->ID );
							$path  = get_attached_file( $attachment->ID );
							$bytes = ( $path && file_exists( $path ) ) ? filesize( $path ) : 0;
							$size  = $bytes ? size_format( $bytes ) : '—';
							$code  = sprintf( '[foliora file="%s"]', esc_url_raw( $url ) );
							$edit  = admin_url( 'upload.php?item=' . (int) $attachment->ID );
							$title = get_the_title( $attachment );
							if ( '' === $title ) {
								$title = wp_basename( $url );
							}
							$thumb_id   = Foliora_Thumbnails::get_thumbnail_id( $attachment->ID );
							$indexed    = Foliora_SEO::is_indexed( $attachment->ID );
							$has_text   = $indexed && '' !== Foliora_SEO::get_extracted_text( $attachment->ID );
							$a11y_done  = Foliora_A11y::is_checked( $attachment->ID );
							$a11y_tagged = $a11y_done && Foliora_A11y::is_tagged( $attachment->ID );
							?>
							<tr
								data-pdf-id="<?php echo esc_attr( (string) $attachment->ID ); ?>"
								data-pdf-url="<?php echo esc_url( $url ); ?>"
								data-pdf-title="<?php echo esc_attr( $title ); ?>"
								data-needs-thumb="<?php echo $thumb_id ? '0' : '1'; ?>"
								data-needs-index="<?php echo ( $index_on && ! $indexed ) ? '1' : '0'; ?>"
								data-needs-a11y="<?php echo ( $a11y_on && ! $a11y_done ) ? '1' : '0'; ?>"
							>
								<td class="foliora-docs-thumb-col">
									<?php if ( $thumb_id ) : ?>
										<?php
										echo wp_get_attachment_image(
											$thumb_id,
											'thumbnail',
											false,
											array(
												'class' => 'foliora-docs-thumb-img',
												'alt'   => $title,
											)
										);
										?>
									<?php else : ?>
										<span
											class="foliora-thumb-placeholder"
											data-pdf-id="<?php echo esc_attr( (string) $attachment->ID ); ?>"
											data-pdf-url="<?php echo esc_url( $url ); ?>"
											data-pdf-title="<?php echo esc_attr( $title ); ?>"
										></span>
									<?php endif; ?>
								</td>
								<td>
									<strong><a href="<?php echo esc_url( $edit ); ?>"><?php echo esc_html( $title ); ?></a></strong>
									<div class="row-actions">
										<span class="edit"><a href="<?php echo esc_url( $edit ); ?>"><?php esc_html_e( 'Media', 'foliora' ); ?></a> | </span>
										<span class="view"><a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open file', 'foliora' ); ?></a> | </span>
										<span class="foliora-test-page"><button type="button" class="button-link foliora-doc-create-test-btn" data-pdf-url="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Create test page', 'foliora' ); ?></button></span>
									</div>
								</td>
								<td>
									<?php if ( $indexed && $has_text ) : ?>
										<span class="foliora-index-status is-ready"><?php esc_html_e( 'Indexed', 'foliora' ); ?></span>
									<?php elseif ( $indexed ) : ?>
										<span class="foliora-index-status is-empty"><?php esc_html_e( 'No text', 'foliora' ); ?></span>
									<?php else : ?>
										<span class="foliora-index-status is-pending"><?php esc_html_e( 'Pending', 'foliora' ); ?></span>
									<?php endif; ?>
								</td>
								<td>
									<?php if ( $a11y_done && $a11y_tagged ) : ?>
										<span class="foliora-a11y-status is-ready"><?php esc_html_e( 'Tagged', 'foliora' ); ?></span>
									<?php elseif ( $a11y_done ) : ?>
										<span
											class="foliora-a11y-status is-warn"
											title="<?php esc_attr_e( 'This PDF has no accessibility tags. Export it from Word, InDesign, or Acrobat as a tagged PDF so screen readers can follow headings and reading order.', 'foliora' ); ?>"
										><?php esc_html_e( 'Untagged', 'foliora' ); ?></span>
									<?php else : ?>
										<span class="foliora-a11y-status is-pending"><?php esc_html_e( 'Pending', 'foliora' ); ?></span>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( $size ); ?></td>
								<td><?php echo esc_html( get_the_date( '', $attachment ) ); ?></td>
								<td>
									<div class="foliora-shortcode-row">
										<input type="text" class="foliora-docs-shortcode" readonly value="<?php echo esc_attr( $code ); ?>" />
										<button type="button" class="button foliora-copy-btn" data-foliora-copy="<?php echo esc_attr( $code ); ?>"><?php esc_html_e( 'Copy', 'foliora' ); ?></button>
									</div>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<?php if ( $query->max_num_pages > 1 ) : ?>
					<div class="tablenav bottom">
						<div class="tablenav-pages">
							<span class="displaying-num">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %s: number of PDFs */
										_n( '%s document', '%s documents', $total, 'foliora' ),
										number_format_i18n( $total )
									)
								);
								?>
							</span>
							<?php
							echo wp_kses_post(
								paginate_links(
									array(
										'base'      => add_query_arg( 'paged', '%#%' ),
										'format'    => '',
										'total'     => (int) $query->max_num_pages,
										'current'   => $paged,
										'prev_text' => '&laquo;',
										'next_text' => '&raquo;',
									)
								)
							);
							?>
						</div>
					</div>
				<?php endif; ?>
			<?php else : ?>
				<div class="foliora-docs-empty">
					<p>
						<?php
						echo $search
							? esc_html__( 'No PDFs matched that search.', 'foliora' )
							: esc_html__( 'No PDFs in the Media Library yet.', 'foliora' );
						?>
					</p>
					<?php if ( ! $search ) : ?>
						<a class="button button-primary" href="<?php echo esc_url( admin_url( 'media-new.php' ) ); ?>"><?php esc_html_e( 'Upload a PDF', 'foliora' ); ?></a>
					<?php endif; ?>
				</div>
			<?php endif; ?>
			<?php endif; ?>
			<div class="foliora-toast" id="foliora-toast" hidden role="status"></div>
		</div>
		<?php
		// Custom WP_Query does not change $wp_query, so wp_reset_postdata() is
		// not needed here. Explicitly unset to release the object from memory.
		unset( $query );
	}

	/**
	 * Render the Features Showcase tab on the Docs & Features page.
	 *
	 * @param bool $is_pro
	 */
	public function render_features_tab( $is_pro ) {
		?>
		<div class="foliora-features-showcase">
			<div class="foliora-features-hero">
				<div class="foliora-features-hero-content">
					<h2><?php esc_html_e( 'Everything Foliora Can Do', 'foliora' ); ?></h2>
					<p><?php esc_html_e( 'Foliora gives you a fast, accessible, and locally-rendered PDF reading experience with zero external dependencies, complete with powerful builder widgets, SEO integration, and optional Pro superpowers.', 'foliora' ); ?></p>
				</div>
				<?php if ( ! $is_pro ) : ?>
				<div class="foliora-features-hero-action">
					<a class="button button-primary button-hero" href="<?php echo esc_url( Foliora_Loader::upgrade_url() ); ?>" target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'Get Foliora Pro', 'foliora' ); ?> &rarr;
					</a>
				</div>
				<?php endif; ?>
			</div>

			<div class="foliora-features-grid">
				<!-- Card 1: Reader & 3D FlipBook -->
				<div class="foliora-feature-card">
					<div class="foliora-feature-card-header">
						<span class="dashicons dashicons-book-alt foliora-feature-icon"></span>
						<h3><?php esc_html_e( 'Reading Modes & 3D FlipBook', 'foliora' ); ?></h3>
						<span class="foliora-badge is-active"><?php esc_html_e( 'Included Free', 'foliora' ); ?></span>
					</div>
					<ul class="foliora-feature-list">
						<li><strong><?php esc_html_e( '3D Realistic FlipBook:', 'foliora' ); ?></strong> <?php esc_html_e( 'Turn pages with realistic 3D perspective and Web Audio paper rustle sound.', 'foliora' ); ?></li>
						<li><strong><?php esc_html_e( 'Multiple Layouts:', 'foliora' ); ?></strong> <?php esc_html_e( 'Single Page, Continuous Vertical Scroll, and Two-Page Spread.', 'foliora' ); ?></li>
						<li><strong><?php esc_html_e( 'Zoom & Pan:', 'foliora' ); ?></strong> <?php esc_html_e( 'Hardware-accelerated pinch-to-zoom, Fit-to-Page, Fit-to-Width, and Hand pan tool.', 'foliora' ); ?></li>
						<li><strong><?php esc_html_e( 'Text & Links:', 'foliora' ); ?></strong> <?php esc_html_e( 'Selectable text layer, copy-paste, internal table of contents, and clickable annotations.', 'foliora' ); ?></li>
						<li><strong><?php esc_html_e( 'Deep Linking:', 'foliora' ); ?></strong> <?php esc_html_e( 'Direct link to any page via #page=X URL hash.', 'foliora' ); ?></li>
					</ul>
				</div>

				<!-- Card 2: CMS & Page Builders -->
				<div class="foliora-feature-card">
					<div class="foliora-feature-card-header">
						<span class="dashicons dashicons-layout foliora-feature-icon"></span>
						<h3><?php esc_html_e( 'Page Builders & Embedding', 'foliora' ); ?></h3>
						<span class="foliora-badge is-active"><?php esc_html_e( 'Included Free', 'foliora' ); ?></span>
					</div>
					<ul class="foliora-feature-list">
						<li><strong><?php esc_html_e( 'Gutenberg Native Block:', 'foliora' ); ?></strong> <?php esc_html_e( 'Foliora Viewer block with Media picker & live first-page preview.', 'foliora' ); ?></li>
						<li><strong><?php esc_html_e( 'Auto oEmbed:', 'foliora' ); ?></strong> <?php esc_html_e( 'Simply paste any direct .pdf link on a new line to embed instantly.', 'foliora' ); ?></li>
						<li><strong><?php esc_html_e( 'Page Builders:', 'foliora' ); ?></strong> <?php esc_html_e( 'Native Elementor widget, Divi module, and Beaver Builder module.', 'foliora' ); ?></li>
						<li><strong><?php esc_html_e( 'Document Library Grid:', 'foliora' ); ?></strong> <?php esc_html_e( '[foliora_library] shortcode for searchable PDF galleries.', 'foliora' ); ?></li>
						<li><strong><?php esc_html_e( 'Attachment Pages:', 'foliora' ); ?></strong> <?php esc_html_e( 'Automatic interactive reader on standard WordPress attachment pages.', 'foliora' ); ?></li>
					</ul>
				</div>

				<!-- Card 3: SEO, Social & A11y -->
				<div class="foliora-feature-card">
					<div class="foliora-feature-card-header">
						<span class="dashicons dashicons-search foliora-feature-icon"></span>
						<h3><?php esc_html_e( 'SEO, Search & Accessibility', 'foliora' ); ?></h3>
						<span class="foliora-badge is-active"><?php esc_html_e( 'Included Free', 'foliora' ); ?></span>
					</div>
					<ul class="foliora-feature-list">
						<li><strong><?php esc_html_e( 'Site Search Indexing:', 'foliora' ); ?></strong> <?php esc_html_e( 'Extracts PDF text so WordPress search finds content inside documents.', 'foliora' ); ?></li>
						<li><strong><?php esc_html_e( 'Schema.org JSON-LD:', 'foliora' ); ?></strong> <?php esc_html_e( 'Automatic DigitalDocument rich snippets for Google search results.', 'foliora' ); ?></li>
						<li><strong><?php esc_html_e( 'Social Card Previews:', 'foliora' ); ?></strong> <?php esc_html_e( 'Open Graph & Twitter Cards cover preview generated from the PDF.', 'foliora' ); ?></li>
						<li><strong><?php esc_html_e( 'Accessibility Diagnostics:', 'foliora' ); ?></strong> <?php esc_html_e( 'Detects untagged PDFs and provides WCAG / PDF-UA guidance.', 'foliora' ); ?></li>
						<li><strong><?php esc_html_e( 'Semantic Fallback:', 'foliora' ); ?></strong> <?php esc_html_e( 'Accessible <noscript> fallback links for search engines and screen readers.', 'foliora' ); ?></li>
					</ul>
				</div>

				<!-- Card 4: Performance & Developer API -->
				<div class="foliora-feature-card">
					<div class="foliora-feature-card-header">
						<span class="dashicons dashicons-performance foliora-feature-icon"></span>
						<h3><?php esc_html_e( 'Performance & Developer API', 'foliora' ); ?></h3>
						<span class="foliora-badge is-active"><?php esc_html_e( 'Included Free', 'foliora' ); ?></span>
					</div>
					<ul class="foliora-feature-list">
						<li><strong><?php esc_html_e( 'Zero CDN Dependency:', 'foliora' ); ?></strong> <?php esc_html_e( 'Bundled locally hosted PDF.js, no third-party tracking or remote downtime.', 'foliora' ); ?></li>
						<li><strong><?php esc_html_e( 'IntersectionObserver Lazy Load:', 'foliora' ); ?></strong> <?php esc_html_e( 'Offscreen viewers only load scripts when approaching viewport.', 'foliora' ); ?></li>
						<li><strong><?php esc_html_e( 'iOS Memory Virtualization:', 'foliora' ); ?></strong> <?php esc_html_e( 'Releases distant canvas memory to prevent mobile Safari crashes.', 'foliora' ); ?></li>
						<li><strong><?php esc_html_e( 'CORS Diagnostics:', 'foliora' ); ?></strong> <?php esc_html_e( 'Smart detection and fallback UI for cross-origin PDF assets.', 'foliora' ); ?></li>
						<li><strong><?php esc_html_e( 'GA4 & Custom Events:', 'foliora' ); ?></strong> <?php esc_html_e( 'foliora:ready, foliora:pagechange, foliora:zoom, foliora:error DOM events.', 'foliora' ); ?></li>
					</ul>
				</div>

				<!-- Card 5: Foliora Pro Superpowers -->
				<div class="foliora-feature-card foliora-feature-card-pro">
					<div class="foliora-feature-card-header">
						<span class="dashicons dashicons-awards foliora-feature-icon-pro"></span>
						<h3><?php esc_html_e( 'Foliora Pro Superpowers', 'foliora' ); ?></h3>
						<span class="foliora-badge <?php echo $is_pro ? 'is-active' : 'is-pro'; ?>">
							<?php echo $is_pro ? esc_html__( 'Unlocked', 'foliora' ) : esc_html__( 'Pro Upgrade', 'foliora' ); ?>
						</span>
					</div>
					<ul class="foliora-feature-list">
						<li><strong><?php esc_html_e( 'EPUB 3 Reader Mode:', 'foliora' ); ?></strong> <?php esc_html_e( 'Read digital EPUB ebooks directly inside WordPress with custom font controls.', 'foliora' ); ?></li>
						<li><strong><?php esc_html_e( 'Cloud Reading Progress & Bookmarks:', 'foliora' ); ?></strong> <?php esc_html_e( 'Sync last-read page and user bookmarks across devices for logged-in users.', 'foliora' ); ?></li>
						<li><strong><?php esc_html_e( 'WooCommerce Paywall / Content Gating:', 'foliora' ); ?></strong> <?php esc_html_e( 'Lock PDF/EPUB chapters or entire files behind WooCommerce product purchases.', 'foliora' ); ?></li>
						<li><strong><?php esc_html_e( 'Expiring & Protected Share Links:', 'foliora' ); ?></strong> <?php esc_html_e( 'Create secure, time-limited, password-protected sharing links for clients.', 'foliora' ); ?></li>
						<li><strong><?php esc_html_e( 'Reading Analytics Dashboard:', 'foliora' ); ?></strong> <?php esc_html_e( 'Track completion rates, time spent per page, and drop-off rates.', 'foliora' ); ?></li>
						<li><strong><?php esc_html_e( 'White-Label Branding:', 'foliora' ); ?></strong> <?php esc_html_e( 'Remove the "Powered by Foliora" credit for total brand customization.', 'foliora' ); ?></li>
					</ul>
					<?php if ( ! $is_pro ) : ?>
					<div class="foliora-feature-card-footer">
						<a class="button button-primary" href="<?php echo esc_url( Foliora_Loader::upgrade_url() ); ?>" target="_blank" rel="noopener noreferrer">
							<?php esc_html_e( 'Upgrade to Pro to Unlock', 'foliora' ); ?> &rarr;
						</a>
					</div>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	public function ajax_mark_setup_copied() {
		check_ajax_referer( 'foliora_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden.', 'foliora' ) ), 403 );
		}

		$settings = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		$settings['setup_copied_shortcode'] = true;
		update_option( self::OPTION_NAME, $settings );

		wp_send_json_success();
	}

	public function ajax_create_test_page() {
		check_ajax_referer( 'foliora_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden.', 'foliora' ) ), 403 );
		}

		$file = isset( $_POST['file'] ) ? esc_url_raw( wp_unslash( $_POST['file'] ) ) : '';
		if ( '' === $file ) {
			wp_send_json_error( array( 'message' => __( 'Select a PDF first.', 'foliora' ) ) );
		}

		// Ensure the URL resolves to a PDF attachment in this site's Media Library
		// so an arbitrary external URL cannot be published via this AJAX endpoint.
		$attachment_id = Foliora_Compat::attachment_id_from_url( $file );
		if ( ! $attachment_id ) {
			wp_send_json_error( array( 'message' => __( 'Only PDFs from the Media Library can be used here.', 'foliora' ) ) );
		}
		$mime = get_post_mime_type( $attachment_id );
		if ( 'application/pdf' !== $mime ) {
			wp_send_json_error( array( 'message' => __( 'That file is not a PDF.', 'foliora' ) ) );
		}

		$content  = '[foliora file="' . $file . '"]';
		$existing = get_page_by_path( 'foliora-test' );

		if ( $existing ) {
			$result = wp_update_post(
				array(
					'ID'           => $existing->ID,
					'post_content' => $content,
					'post_status'  => 'publish',
				),
				true
			);
			if ( is_wp_error( $result ) || ! $result ) {
				wp_send_json_error( array( 'message' => is_wp_error( $result ) ? $result->get_error_message() : __( 'Could not update the test page.', 'foliora' ) ) );
			}
			$page_id = $existing->ID;
		} else {
			$page_id = wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => __( 'Foliora Test', 'foliora' ),
					'post_name'    => 'foliora-test',
					'post_content' => $content,
				),
				true
			);
			if ( is_wp_error( $page_id ) ) {
				wp_send_json_error( array( 'message' => $page_id->get_error_message() ) );
			}
		}

		wp_send_json_success(
			array(
				'id'  => $page_id,
				'url' => get_permalink( $page_id ),
			)
		);
	}

	public function ajax_dismiss_welcome() {
		check_ajax_referer( 'foliora_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden.', 'foliora' ) ), 403 );
		}

		update_user_meta( get_current_user_id(), 'foliora_welcome_dismissed', 1 );
		wp_send_json_success();
	}

	private function get_latest_pdf_url() {
		$query = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => 'application/pdf',
				'posts_per_page' => 1,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		if ( empty( $query->posts ) ) {
			return '';
		}
		$url = wp_get_attachment_url( (int) $query->posts[0] );
		return $url ? $url : '';
	}

	/**
	 * Most recently uploaded PDFs for the dashboard picker.
	 *
	 * @param int $limit Number of attachments.
	 * @return array<int,array{id:int,url:string,title:string,thumb:string}>
	 */
	private function get_recent_pdfs( $limit = 4 ) {
		$query = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => 'application/pdf',
				'posts_per_page' => max( 1, absint( $limit ) ),
				'orderby'        => 'date',
				'order'          => 'DESC',
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		$items = array();
		foreach ( $query->posts as $id ) {
			$id  = (int) $id;
			$url = wp_get_attachment_url( $id );
			if ( ! $url ) {
				continue;
			}
			$title = get_the_title( $id );
			if ( '' === $title ) {
				$title = wp_basename( $url );
			}
			$items[] = array(
				'id'    => $id,
				'url'   => $url,
				'title' => $title,
				'thumb' => Foliora_Thumbnails::get_thumbnail_url( $id, 'thumbnail' ),
			);
		}

		return $items;
	}

	private function count_pdfs() {
		// wp_count_attachments() returns a cached stdClass keyed by post_status.
		// PDFs in the Media Library always have post_status 'inherit'.
		$counts = wp_count_attachments( 'application/pdf' );
		return isset( $counts->inherit ) ? (int) $counts->inherit : 0;
	}

	private function count_embeds() {
		$cached = wp_cache_get( 'embed_count', 'foliora' );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		global $wpdb;

		$like_shortcode = '%' . $wpdb->esc_like( '[foliora' ) . '%';
		$like_block     = '%' . $wpdb->esc_like( 'wp:foliora/' ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- LIKE across post_content is not available via WP_Query; result is cached.
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_status IN ('publish','draft','private') AND post_type IN ('post','page') AND (post_content LIKE %s OR post_content LIKE %s)",
				$like_shortcode,
				$like_block
			)
		);

		wp_cache_set( 'embed_count', $count, 'foliora', 5 * MINUTE_IN_SECONDS );

		return $count;
	}

	private function pro_feature_icon( $key ) {
		$icons = array(
			'epub_reader'      => 'dashicons-book',
			'flipbook_3d'      => 'dashicons-images-alt2',
			'bookmarks'        => 'dashicons-flag',
			'reader_themes'    => 'dashicons-art',
			'protected_links'  => 'dashicons-lock',
			'woocommerce_gate' => 'dashicons-cart',
			'analytics'        => 'dashicons-chart-area',
			'remove_branding'  => 'dashicons-hidden',
		);

		return $icons[ $key ] ?? 'dashicons-star-filled';
	}

	/**
	 * One-line blurbs for dashboard cards. Labels stay in
	 * Foliora_Loader::pro_features() so lock UI cannot drift.
	 *
	 * @param string $key Feature key.
	 * @return string
	 */
	private function pro_feature_blurb( $key ) {
		$blurbs = array(
			'epub_reader'      => __( 'Open EPUB files in the same embed, not just PDF.', 'foliora' ),
			'flipbook_3d'      => __( 'Page-turn animation for a magazine-style read.', 'foliora' ),
			'bookmarks'        => __( 'Logged-in readers can resume where they left off.', 'foliora' ),
			'reader_themes'    => __( 'Light, sepia, and dark themes for long reading.', 'foliora' ),
			'protected_links'  => __( 'Password, expiry, and view-capped share URLs.', 'foliora' ),
			'woocommerce_gate' => __( 'Limit documents to paying WooCommerce customers.', 'foliora' ),
			'analytics'        => __( 'See which documents get opened and how far.', 'foliora' ),
			'remove_branding'  => __( 'Hide “Powered by Foliora” on the public viewer.', 'foliora' ),
		);

		return $blurbs[ $key ] ?? '';
	}
}
