<?php
/**
 * Foliora_Viewer
 *
 * Registers the [foliora] shortcode and renders the front-end PDF viewer.
 * Rendering uses the PDF.js build bundled under assets/vendor/pdfjs —
 * never a CDN — so the plugin has no remote-code dependency (a WP.org
 * requirement) and keeps working on locked-down/offline sites.
 *
 * @package Foliora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Foliora_Viewer {

	/**
	 * Track whether we've already enqueued assets for this request, so
	 * multiple shortcodes on one page don't double-load PDF.js.
	 *
	 * @var bool
	 */
	private $assets_enqueued = false;

	public function init() {
		add_shortcode( 'foliora', array( $this, 'render_shortcode' ) );
	}

	/**
	 * [foliora file="https://example.com/file.pdf" width="100%" height="600px"]
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function render_shortcode( $atts ) {
		$settings = get_option( 'foliora_settings', array() );

		$atts = shortcode_atts(
			array(
				'file'     => '',
				'width'    => $settings['default_width'] ?? '100%',
				'height'   => $settings['default_height'] ?? '600px',
				'mode'     => 'pdf', // 'pdf' (free) or 'epub' (Pro-only).
				'page'     => '1',
				'title'    => '',
				'download' => '',
				'print'    => '',
				'hash'     => 'true',
				'view'     => 'page',
				'resume'   => 'true',
			),
			$atts,
			'foliora'
		);

		/**
		 * Filter: foliora/viewer_atts
		 *
		 * Override resolved shortcode/block attributes (file, width, height,
		 * mode, page, title, download, print, hash, view, resume) without replacing the
		 * entire viewer. Runs after defaults are applied and before the
		 * empty-file / EPUB-lock checks.
		 *
		 * @param array $atts Resolved attributes.
		 */
		$atts = apply_filters( 'foliora/viewer_atts', $atts );
		if ( ! is_array( $atts ) ) {
			$atts = array();
		}

		if ( empty( $atts['file'] ) ) {
			return $this->admin_notice( __( 'Foliora: no file URL was provided. Use [foliora file="https://example.com/document.pdf"].', 'foliora' ) );
		}

		// EPUB is a Pro-only rendering mode — free plugin ships zero EPUB
		// parsing code, just this lock screen.
		if ( 'epub' === $atts['mode'] && ! Foliora_Loader::is_feature_enabled( 'epub_reader' ) ) {
			return $this->locked_feature_notice( 'epub_reader' );
		}

		/**
		 * Filter: foliora/pre_render_viewer
		 *
		 * Lets Foliora Pro take over rendering completely — e.g. an EPUB
		 * viewer, or the same PDF viewer wrapped in its bookmark/theme
		 * toolbar — without this free class needing to know Pro's
		 * internals. Return any non-null value to short-circuit the
		 * default PDF.js output below.
		 *
		 * @param string|null $output Default null (let free plugin render).
		 * @param array       $atts   Resolved shortcode/block attributes.
		 */
		$pro_output = apply_filters( 'foliora/pre_render_viewer', null, $atts );
		if ( null !== $pro_output ) {
			return apply_filters( 'foliora/viewer_html', $pro_output, $atts );
		}

		$this->enqueue_assets();

		$instance_id = 'foliora-viewer-' . wp_unique_id();

		/**
		 * Action: foliora/before_viewer
		 *
		 * Foliora Pro can hook here to inject a toolbar (bookmarks, theme
		 * switcher, etc.) above the base viewer without free plugin code
		 * needing to know Pro exists beyond this hook.
		 */
		ob_start();
		do_action( 'foliora/before_viewer', $instance_id, $atts );
		$before = ob_get_clean();

		$toolbar = $this->render_toolbar( $instance_id, $atts, $settings );

		$caption = '';
		$title   = isset( $atts['title'] ) ? sanitize_text_field( (string) $atts['title'] ) : '';
		if ( '' !== $title ) {
			$caption = sprintf( '<p class="foliora-caption">%s</p>', esc_html( $title ) );
		}

		$start_page = max( 1, absint( $atts['page'] ?? 1 ) );
		$sync_hash  = $this->att_flag( $atts['hash'] ?? 'true', true );
		$resume     = $this->att_flag( $atts['resume'] ?? 'true', true );
		$view       = strtolower( (string) ( $atts['view'] ?? 'page' ) );
		if ( ! in_array( $view, array( 'page', 'scroll', 'spread' ), true ) ) {
			$view = 'page';
		}

		$branding = '';
		if ( apply_filters( 'foliora/show_branding', true ) ) {
			$branding = sprintf(
				'<p class="foliora-branding"><a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a></p>',
				esc_url( 'https://thereadscope.com/foliora' ),
				esc_html__( 'Powered by Foliora', 'foliora' )
			);
		}

		$container = sprintf(
			'<div class="foliora-wrap" style="width:%1$s;" role="region" aria-label="%7$s">%9$s<div class="foliora-shell" style="height:%2$s;"><div class="foliora-chrome" style="display:flex;flex-wrap:nowrap;align-items:center;">%3$s%4$s</div><div id="%5$s" class="foliora-viewer" data-file="%6$s" data-page="%10$s" data-hash="%11$s" data-view="%12$s" data-resume="%13$s" tabindex="0" role="document" aria-busy="true"></div></div>%8$s</div>',
			esc_attr( $atts['width'] ),
			esc_attr( $atts['height'] ),
			$before,
			$toolbar,
			esc_attr( $instance_id ),
			esc_url( $atts['file'] ),
			esc_attr__( 'PDF viewer', 'foliora' ),
			$branding,
			$caption,
			esc_attr( (string) $start_page ),
			$sync_hash ? '1' : '0',
			esc_attr( $view ),
			$resume ? '1' : '0'
		);

		/**
		 * Filter: foliora/viewer_html
		 *
		 * Last chance to wrap or replace the finished viewer markup.
		 * Also applied to non-null `foliora/pre_render_viewer` output.
		 *
		 * @param string $html Rendered viewer HTML.
		 * @param array  $atts Resolved attributes.
		 */
		return apply_filters( 'foliora/viewer_html', $container, $atts );
	}

	/**
	 * Chrome for the free PDF viewer. Pro's bookmark/theme bar is
	 * printed into the same .foliora-chrome row via foliora/before_viewer.
	 *
	 * @param string $instance_id Viewer element id.
	 * @param array  $atts        Resolved attributes.
	 * @param array  $settings    Plugin settings.
	 * @return string
	 */
	private function render_toolbar( $instance_id, $atts, $settings ) {
		$file_url = isset( $atts['file'] ) ? (string) $atts['file'] : '';
		$download = $this->att_flag(
			$atts['download'] ?? '',
			! isset( $settings['allow_download'] ) || ! empty( $settings['allow_download'] )
		);
		$print    = $this->att_flag(
			$atts['print'] ?? '',
			! isset( $settings['allow_print'] ) || ! empty( $settings['allow_print'] )
		);
		ob_start();
		?>
		<div class="foliora-toolbar" role="toolbar" data-target="<?php echo esc_attr( $instance_id ); ?>" aria-controls="<?php echo esc_attr( $instance_id ); ?>" aria-label="<?php esc_attr_e( 'PDF viewer', 'foliora' ); ?>" style="display:flex;flex-wrap:nowrap;align-items:center;">
			<div class="foliora-tool-group foliora-panel-group" hidden>
				<button type="button" class="foliora-btn foliora-toc" hidden aria-expanded="false" title="<?php esc_attr_e( 'Table of contents', 'foliora' ); ?>" aria-label="<?php esc_attr_e( 'Table of contents', 'foliora' ); ?>">
					<?php echo $this->toolbar_icon( 'toc' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?>
				</button>
				<button type="button" class="foliora-btn foliora-thumbs" hidden aria-expanded="false" title="<?php esc_attr_e( 'Page thumbnails', 'foliora' ); ?>" aria-label="<?php esc_attr_e( 'Page thumbnails', 'foliora' ); ?>">
					<?php echo $this->toolbar_icon( 'thumbs' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?>
				</button>
			</div>
			<div class="foliora-tool-group">
				<button type="button" class="foliora-btn foliora-prev" title="<?php esc_attr_e( 'Previous page', 'foliora' ); ?>" aria-label="<?php esc_attr_e( 'Previous page', 'foliora' ); ?>">
					<?php echo $this->toolbar_icon( 'prev' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?>
				</button>
				<span class="foliora-page-indicator">
					<input type="number" class="foliora-page-input" min="1" value="1" aria-label="<?php esc_attr_e( 'Page', 'foliora' ); ?>" />
					<span class="foliora-page-slash" aria-hidden="true">/</span>
					<span class="foliora-page-count">1</span>
				</span>
				<button type="button" class="foliora-btn foliora-next" title="<?php esc_attr_e( 'Next page', 'foliora' ); ?>" aria-label="<?php esc_attr_e( 'Next page', 'foliora' ); ?>">
					<?php echo $this->toolbar_icon( 'next' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?>
				</button>
			</div>
			<div class="foliora-tool-group">
				<button type="button" class="foliora-btn foliora-zoom-out" title="<?php esc_attr_e( 'Zoom out', 'foliora' ); ?>" aria-label="<?php esc_attr_e( 'Zoom out', 'foliora' ); ?>">
					<?php echo $this->toolbar_icon( 'minus' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?>
				</button>
				<span class="foliora-zoom-label" aria-live="polite">100%</span>
				<button type="button" class="foliora-btn foliora-zoom-in" title="<?php esc_attr_e( 'Zoom in', 'foliora' ); ?>" aria-label="<?php esc_attr_e( 'Zoom in', 'foliora' ); ?>">
					<?php echo $this->toolbar_icon( 'plus' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?>
				</button>
				<button type="button" class="foliora-btn foliora-fit" title="<?php esc_attr_e( 'Fit page', 'foliora' ); ?>" aria-label="<?php esc_attr_e( 'Fit page', 'foliora' ); ?>">
					<?php echo $this->toolbar_icon( 'fit' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?>
				</button>
				<button type="button" class="foliora-btn foliora-rotate" title="<?php esc_attr_e( 'Rotate', 'foliora' ); ?>" aria-label="<?php esc_attr_e( 'Rotate', 'foliora' ); ?>">
					<?php echo $this->toolbar_icon( 'rotate' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?>
				</button>
				<button type="button" class="foliora-btn foliora-view" title="<?php esc_attr_e( 'Single page', 'foliora' ); ?>" aria-label="<?php esc_attr_e( 'Single page', 'foliora' ); ?>">
					<?php echo $this->toolbar_icon( 'view' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?>
				</button>
				<button type="button" class="foliora-btn foliora-pan" aria-pressed="false" title="<?php esc_attr_e( 'Hand tool', 'foliora' ); ?>" aria-label="<?php esc_attr_e( 'Hand tool', 'foliora' ); ?>">
					<?php echo $this->toolbar_icon( 'pan' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?>
				</button>
			</div>
			<div class="foliora-tool-group foliora-find-group">
				<span class="foliora-find">
					<?php echo $this->toolbar_icon( 'search' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?>
					<input type="search" class="foliora-find-input" placeholder="<?php esc_attr_e( 'Find', 'foliora' ); ?>" aria-label="<?php esc_attr_e( 'Find in document', 'foliora' ); ?>" />
				</span>
				<span class="foliora-find-status" hidden aria-live="polite"></span>
				<button type="button" class="foliora-btn foliora-find-prev" title="<?php esc_attr_e( 'Previous match', 'foliora' ); ?>" aria-label="<?php esc_attr_e( 'Previous match', 'foliora' ); ?>">
					<?php echo $this->toolbar_icon( 'up' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?>
				</button>
				<button type="button" class="foliora-btn foliora-find-next" title="<?php esc_attr_e( 'Next match', 'foliora' ); ?>" aria-label="<?php esc_attr_e( 'Next match', 'foliora' ); ?>">
					<?php echo $this->toolbar_icon( 'down' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?>
				</button>
			</div>
			<div class="foliora-tool-group foliora-tool-group-end">
				<?php if ( $download ) : ?>
				<a class="foliora-btn foliora-download" href="<?php echo esc_url( $file_url ); ?>" download title="<?php esc_attr_e( 'Download', 'foliora' ); ?>" aria-label="<?php esc_attr_e( 'Download', 'foliora' ); ?>">
					<?php echo $this->toolbar_icon( 'download' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?>
				</a>
				<?php endif; ?>
				<?php if ( $print ) : ?>
				<button type="button" class="foliora-btn foliora-print" title="<?php esc_attr_e( 'Print', 'foliora' ); ?>" aria-label="<?php esc_attr_e( 'Print', 'foliora' ); ?>">
					<?php echo $this->toolbar_icon( 'print' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?>
				</button>
				<?php endif; ?>
				<button type="button" class="foliora-btn foliora-present" aria-pressed="false" title="<?php esc_attr_e( 'Presentation', 'foliora' ); ?>" aria-label="<?php esc_attr_e( 'Presentation', 'foliora' ); ?>">
					<?php echo $this->toolbar_icon( 'present' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?>
				</button>
				<button type="button" class="foliora-btn foliora-fs" title="<?php esc_attr_e( 'Full screen', 'foliora' ); ?>" aria-label="<?php esc_attr_e( 'Full screen', 'foliora' ); ?>">
					<?php echo $this->toolbar_icon( 'fs' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?>
				</button>
				<button type="button" class="foliora-btn foliora-more" aria-expanded="false" title="<?php esc_attr_e( 'More tools', 'foliora' ); ?>" aria-label="<?php esc_attr_e( 'More tools', 'foliora' ); ?>">
					<?php echo $this->toolbar_icon( 'more' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?>
				</button>
			</div>
			<span class="foliora-sr-only foliora-page-live" aria-live="polite"></span>
			<span class="foliora-sr-only"><?php esc_html_e( 'Keyboard: arrows or Page Up and Page Down change page, plus and minus zoom, R rotates, Home and End jump to first or last page, Ctrl or Command F finds text, Escape clears find or closes panels.', 'foliora' ); ?></span>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Inline 16px stroke icons for the viewer chrome.
	 *
	 * @param string $name Icon key.
	 * @return string
	 */
	private function toolbar_icon( $name ) {
		$paths = array(
			'prev'     => '<polyline points="15 18 9 12 15 6" />',
			'next'     => '<polyline points="9 18 15 12 9 6" />',
			'minus'    => '<line x1="5" y1="12" x2="19" y2="12" />',
			'plus'     => '<line x1="12" y1="5" x2="12" y2="19" /><line x1="5" y1="12" x2="19" y2="12" />',
			'fit'      => '<path d="M8 3H5a2 2 0 0 0-2 2v3" /><path d="M16 3h3a2 2 0 0 1 2 2v3" /><path d="M8 21H5a2 2 0 0 1-2-2v-3" /><path d="M16 21h3a2 2 0 0 0 2-2v-3" />',
			'rotate'   => '<polyline points="23 4 23 10 17 10" /><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10" />',
			'search'   => '<circle cx="11" cy="11" r="7" /><line x1="21" y1="21" x2="16.65" y2="16.65" />',
			'up'       => '<polyline points="18 15 12 9 6 15" />',
			'down'     => '<polyline points="6 9 12 15 18 9" />',
			'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" /><polyline points="7 10 12 15 17 10" /><line x1="12" y1="15" x2="12" y2="3" />',
			'print'    => '<polyline points="6 9 6 2 18 2 18 9" /><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2" /><rect x="6" y="14" width="12" height="8" />',
			'fs'       => '<polyline points="15 3 21 3 21 9" /><polyline points="9 21 3 21 3 15" /><line x1="21" y1="3" x2="14" y2="10" /><line x1="3" y1="21" x2="10" y2="14" />',
			'toc'      => '<line x1="8" y1="6" x2="21" y2="6" /><line x1="8" y1="12" x2="21" y2="12" /><line x1="8" y1="18" x2="21" y2="18" /><line x1="3" y1="6" x2="3.01" y2="6" /><line x1="3" y1="12" x2="3.01" y2="12" /><line x1="3" y1="18" x2="3.01" y2="18" />',
			'thumbs'   => '<rect x="3" y="3" width="7" height="7" /><rect x="14" y="3" width="7" height="7" /><rect x="3" y="14" width="7" height="7" /><rect x="14" y="14" width="7" height="7" />',
			'view'     => '<rect x="3" y="3" width="7" height="18" /><rect x="14" y="3" width="7" height="18" />',
			'pan'      => '<path d="M18 11V6a2 2 0 0 0-4 0v5" /><path d="M14 10V4a2 2 0 0 0-4 0v8" /><path d="M10 11V6a2 2 0 0 0-4 0v7" /><path d="M6 13v3a6 6 0 0 0 6 6h2a6 6 0 0 0 6-6v-5a2 2 0 0 0-4 0" />',
			'more'     => '<circle cx="12" cy="5" r="1.5" /><circle cx="12" cy="12" r="1.5" /><circle cx="12" cy="19" r="1.5" />',
			'present'  => '<rect x="2" y="3" width="20" height="14" rx="2" /><line x1="8" y1="21" x2="16" y2="21" /><line x1="12" y1="17" x2="12" y2="21" />',
		);
		$body = $paths[ $name ] ?? '';
		return '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $body . '</svg>';
	}

	/**
	 * Strings shared by the front-end viewer and the settings live preview.
	 *
	 * @return array
	 */
	public static function viewer_i18n() {
		return array(
			'loading'        => __( 'Loading document…', 'foliora' ),
			/* translators: %s: percent loaded. */
			'loadingPct'     => __( 'Loading %s…', 'foliora' ),
			'error'          => __( 'This document could not be loaded.', 'foliora' ),
			/* translators: 1: current page number, 2: total pages. */
			'pageStatus'     => __( 'Page %1$s of %2$s', 'foliora' ),
			'passwordPrompt' => __( 'This document is password-protected.', 'foliora' ),
			'passwordWrong'  => __( 'Incorrect password.', 'foliora' ),
			'passwordUnlock' => __( 'Unlock', 'foliora' ),
			'passwordLabel'  => __( 'Password', 'foliora' ),
			'toc'            => __( 'Table of contents', 'foliora' ),
			'thumbs'         => __( 'Page thumbnails', 'foliora' ),
			'outlineNav'     => __( 'Document outline', 'foliora' ),
			'thumbsNav'      => __( 'Page thumbnails', 'foliora' ),
			'closePanel'     => __( 'Close panel', 'foliora' ),
			'fitPage'        => __( 'Fit page', 'foliora' ),
			'fitWidth'       => __( 'Fit width', 'foliora' ),
			/* translators: 1: current match, 2: total matches. */
			'findStatus'     => __( '%1$s of %2$s', 'foliora' ),
			'findNone'       => __( 'No matches', 'foliora' ),
			'viewPage'       => __( 'Single page', 'foliora' ),
			'viewScroll'     => __( 'Continuous scroll', 'foliora' ),
			'viewSpread'     => __( 'Two-page spread', 'foliora' ),
			'pan'            => __( 'Hand tool', 'foliora' ),
			'select'         => __( 'Text select', 'foliora' ),
			'more'           => __( 'More tools', 'foliora' ),
			'present'        => __( 'Presentation', 'foliora' ),
		);
	}

	/**
	 * Enqueue the bundled PDF.js build + our thin wrapper script once per
	 * request.
	 */
	public function enqueue_assets() {
		if ( $this->assets_enqueued ) {
			return;
		}
		$this->assets_enqueued = true;

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
				'i18n'      => self::viewer_i18n(),
			)
		);
	}

	private function att_flag( $value, $default ) {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( null === $value || '' === $value ) {
			return (bool) $default;
		}
		$v = strtolower( (string) $value );
		if ( in_array( $v, array( '0', 'false', 'no', 'off' ), true ) ) {
			return false;
		}
		if ( in_array( $v, array( '1', 'true', 'yes', 'on' ), true ) ) {
			return true;
		}
		return (bool) $default;
	}

	/**
	 * Small inline "upgrade to unlock" block, shown in place of a locked
	 * feature. Deliberately plain-styled so it never looks broken even
	 * if foliora-viewer.css fails to load.
	 *
	 * @param string $feature_key
	 * @return string
	 */
	private function locked_feature_notice( $feature_key ) {
		$features = Foliora_Loader::pro_features();
		$label    = $features[ $feature_key ] ?? $feature_key;

		return sprintf(
			'<div class="foliora-locked"><p><strong>%1$s</strong> %2$s <a href="%3$s" target="_blank" rel="noopener noreferrer">%4$s</a></p></div>',
			esc_html( $label ),
			esc_html__( 'is a Foliora Pro feature.', 'foliora' ),
			esc_url( Foliora_Loader::upgrade_url() ),
			esc_html__( 'Upgrade to unlock', 'foliora' )
		);
	}

	private function admin_notice( $message ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return '';
		}
		return '<p class="foliora-admin-notice">' . esc_html( $message ) . '</p>';
	}
}
