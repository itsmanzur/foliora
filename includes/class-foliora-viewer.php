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

	use Foliora_Att_Flag;

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

		$raw_atts = is_array( $atts ) ? $atts : array();
		if ( ! empty( $raw_atts['id'] ) ) {
			$embed_id     = absint( $raw_atts['id'] );
			$saved_config = Foliora_Embeds::get( $embed_id );
			if ( ! empty( $saved_config ) && is_array( $saved_config ) ) {
				// Saved config supplies defaults; explicit shortcode attributes take precedence.
				$atts = array_merge( $saved_config, $raw_atts );
			} else {
				return $this->admin_notice( sprintf( __( 'Foliora: Embed ID #%d not found.', 'foliora' ), $embed_id ) );
			}
		}

		$atts = shortcode_atts(
			array(
				'id'        => '',
				'file'      => '',
				'width'     => $settings['default_width'] ?? '100%',
				'height'    => $settings['default_height'] ?? '600px',
				'mode'      => 'pdf', // 'pdf' (free) or 'epub' (Pro-only).
				'page'      => '1',
				'title'     => '',
				'theme'     => $settings['default_theme'] ?? 'light',
				'download'  => '',
				'print'     => '',
				'search'    => '',
				'theme_btn' => '',
				'share'     => '',
				'shortcuts' => '',
				'presentation' => '',
				'fullscreen' => '',
				'loading'   => ! empty( $settings['lazy_loading'] ) ? 'lazy' : 'eager',
				'hide'      => '',
				'hash'      => 'true',
				'view'      => 'page',
				'resume'    => 'true',
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
		if ( ! in_array( $view, array( 'page', 'scroll', 'spread', 'flip' ), true ) ) {
			$view = 'page';
		}

		$initial_theme = strtolower( (string) ( $atts['theme'] ?? 'light' ) );
		if ( ! in_array( $initial_theme, array( 'light', 'dark', 'sepia' ), true ) ) {
			$initial_theme = 'light';
		}

		$is_lazy = 'lazy' === strtolower( (string) ( $atts['loading'] ?? 'eager' ) );

		$branding = '';
		if ( apply_filters( 'foliora/show_branding', true ) ) {
			$branding = sprintf(
				'<p class="foliora-branding"><a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a></p>',
				esc_url( 'https://thereadscope.com/foliora' ),
				esc_html__( 'Powered by Foliora', 'foliora' )
			);
		}

		$file_src = apply_filters( 'foliora/viewer_file_url', $atts['file'], $atts );

		$noscript = sprintf(
			'<noscript><div class="foliora-noscript"><p><a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a></p></div></noscript>',
			esc_url( $file_src ),
			sprintf(
				/* translators: %s: document title or fallback */
				esc_html__( 'Open document: %s (PDF)', 'foliora' ),
				esc_html( '' !== $title ? $title : wp_basename( (string) $file_src ) )
			)
		);

		$modals = $this->render_modals();

		$container = sprintf(
			'<div class="foliora-wrap" style="width:%1$s;" role="region" aria-label="%7$s">%9$s<div class="foliora-shell foliora-theme-%14$s" style="height:%2$s;"><div class="foliora-chrome" style="display:flex;flex-wrap:nowrap;align-items:center;">%3$s%4$s</div><div id="%5$s" class="foliora-viewer" data-file="%6$s" data-page="%10$s" data-hash="%11$s" data-view="%12$s" data-resume="%13$s" data-theme="%14$s" data-loading="%15$s" tabindex="0" role="document" aria-busy="true">%17$s</div>%16$s</div>%8$s</div>',
			esc_attr( $atts['width'] ),
			esc_attr( $atts['height'] ),
			$before,
			$toolbar,
			esc_attr( $instance_id ),
			esc_url( $file_src ),
			esc_attr__( 'PDF viewer', 'foliora' ),
			$branding,
			$caption,
			esc_attr( (string) $start_page ),
			$sync_hash ? '1' : '0',
			esc_attr( $view ),
			$resume ? '1' : '0',
			esc_attr( $initial_theme ),
			$is_lazy ? 'lazy' : 'eager',
			$modals,
			$noscript
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
		
		$hide_list = array_filter( array_map( 'trim', explode( ',', strtolower( (string) ( $atts['hide'] ?? '' ) ) ) ) );

		$show_nav    = ! in_array( 'nav', $hide_list, true );
		$show_zoom   = ! in_array( 'zoom', $hide_list, true );
		$show_fit    = ! in_array( 'fit', $hide_list, true );
		$show_rotate = ! in_array( 'rotate', $hide_list, true );
		$show_view   = ! in_array( 'view', $hide_list, true );
		$show_pan    = ! in_array( 'pan', $hide_list, true );

		$download = ! in_array( 'download', $hide_list, true ) && $this->att_flag(
			$atts['download'] ?? '',
			! isset( $settings['allow_download'] ) || ! empty( $settings['allow_download'] )
		);
		$print    = ! in_array( 'print', $hide_list, true ) && $this->att_flag(
			$atts['print'] ?? '',
			! isset( $settings['allow_print'] ) || ! empty( $settings['allow_print'] )
		);
		$search   = ! in_array( 'search', $hide_list, true ) && $this->att_flag(
			$atts['search'] ?? '',
			! isset( $settings['allow_search'] ) || ! empty( $settings['allow_search'] )
		);
		$theme_btn = ! in_array( 'theme', $hide_list, true ) && $this->att_flag(
			$atts['theme_btn'] ?? '',
			! isset( $settings['allow_theme'] ) || ! empty( $settings['allow_theme'] )
		);
		$share    = ! in_array( 'share', $hide_list, true ) && $this->att_flag(
			$atts['share'] ?? '',
			! isset( $settings['allow_share'] ) || ! empty( $settings['allow_share'] )
		);
		$shortcuts = ! in_array( 'shortcuts', $hide_list, true ) && $this->att_flag(
			$atts['shortcuts'] ?? '',
			! isset( $settings['allow_shortcuts'] ) || ! empty( $settings['allow_shortcuts'] )
		);
		$present  = ! in_array( 'presentation', $hide_list, true ) && $this->att_flag(
			$atts['presentation'] ?? '',
			! isset( $settings['allow_presentation'] ) || ! empty( $settings['allow_presentation'] )
		);
		$fullscreen = ! in_array( 'fullscreen', $hide_list, true ) && $this->att_flag(
			$atts['fullscreen'] ?? '',
			! isset( $settings['allow_fullscreen'] ) || ! empty( $settings['allow_fullscreen'] )
		);

		$download_url = apply_filters( 'foliora/download_url', $file_url, $atts );
		ob_start();
		?>
		<div class="foliora-toolbar" role="toolbar" data-target="<?php echo esc_attr( $instance_id ); ?>" aria-controls="<?php echo esc_attr( $instance_id ); ?>" aria-label="<?php esc_attr_e( 'PDF viewer', 'foliora' ); ?>">
			<div class="foliora-tool-group foliora-panel-group" hidden>
				<button type="button" class="foliora-btn foliora-toc" hidden aria-expanded="false" title="<?php esc_attr_e( 'Table of contents', 'foliora' ); ?>" aria-label="<?php esc_attr_e( 'Table of contents', 'foliora' ); ?>">
					<?php echo $this->toolbar_icon( 'toc' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</button>
				<button type="button" class="foliora-btn foliora-thumbs" hidden aria-expanded="false" title="<?php esc_attr_e( 'Page thumbnails', 'foliora' ); ?>" aria-label="<?php esc_attr_e( 'Page thumbnails', 'foliora' ); ?>">
					<?php echo $this->toolbar_icon( 'thumbs' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</button>
			</div>
			<?php if ( $show_nav ) : ?>
			<div class="foliora-tool-group foliora-nav-group">
				<button type="button" class="foliora-btn foliora-prev" title="<?php esc_attr_e( 'Previous page (←)', 'foliora' ); ?>" aria-label="<?php esc_attr_e( 'Previous page', 'foliora' ); ?>">
					<?php echo $this->toolbar_icon( 'prev' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</button>
				<span class="foliora-page-indicator">
					<input type="number" class="foliora-page-input" min="1" value="1" aria-label="<?php esc_attr_e( 'Page', 'foliora' ); ?>" />
					<span class="foliora-page-slash" aria-hidden="true">/</span>
					<span class="foliora-page-count">1</span>
				</span>
				<button type="button" class="foliora-btn foliora-next" title="<?php esc_attr_e( 'Next page (→)', 'foliora' ); ?>" aria-label="<?php esc_attr_e( 'Next page', 'foliora' ); ?>">
					<?php echo $this->toolbar_icon( 'next' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</button>
			</div>
			<?php endif; ?>
			<div class="foliora-tool-group foliora-zoom-group">
				<?php if ( $show_zoom ) : ?>
				<button type="button" class="foliora-btn foliora-zoom-out" title="<?php esc_attr_e( 'Zoom out (-)', 'foliora' ); ?>" aria-label="<?php esc_attr_e( 'Zoom out', 'foliora' ); ?>">
					<?php echo $this->toolbar_icon( 'minus' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</button>
				<span class="foliora-zoom-label" aria-live="polite">100%</span>
				<button type="button" class="foliora-btn foliora-zoom-in" title="<?php esc_attr_e( 'Zoom in (+)', 'foliora' ); ?>" aria-label="<?php esc_attr_e( 'Zoom in', 'foliora' ); ?>">
					<?php echo $this->toolbar_icon( 'plus' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</button>
				<?php endif; ?>
				<?php if ( $show_fit ) : ?>
				<button type="button" class="foliora-btn foliora-fit" title="<?php esc_attr_e( 'Fit page (0)', 'foliora' ); ?>" aria-label="<?php esc_attr_e( 'Fit page', 'foliora' ); ?>">
					<?php echo $this->toolbar_icon( 'fit' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</button>
				<?php endif; ?>
			</div>
			<?php if ( $search ) : ?>
			<div class="foliora-tool-group foliora-find-group">
				<span class="foliora-find">
					<?php echo $this->toolbar_icon( 'search' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<input type="search" class="foliora-find-input" placeholder="<?php esc_attr_e( 'Find', 'foliora' ); ?>" aria-label="<?php esc_attr_e( 'Find in document', 'foliora' ); ?>" />
				</span>
				<span class="foliora-find-status" hidden aria-live="polite"></span>
				<button type="button" class="foliora-btn foliora-find-prev" title="<?php esc_attr_e( 'Previous match', 'foliora' ); ?>" aria-label="<?php esc_attr_e( 'Previous match', 'foliora' ); ?>">
					<?php echo $this->toolbar_icon( 'up' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</button>
				<button type="button" class="foliora-btn foliora-find-next" title="<?php esc_attr_e( 'Next match', 'foliora' ); ?>" aria-label="<?php esc_attr_e( 'Next match', 'foliora' ); ?>">
					<?php echo $this->toolbar_icon( 'down' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</button>
			</div>
			<?php endif; ?>
			<div class="foliora-tool-group foliora-tool-group-end">
				<?php if ( $download ) : ?>
				<a class="foliora-btn foliora-download" href="<?php echo esc_url( $download_url ); ?>" download title="<?php esc_attr_e( 'Download', 'foliora' ); ?>" aria-label="<?php esc_attr_e( 'Download', 'foliora' ); ?>">
					<?php echo $this->toolbar_icon( 'download' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</a>
				<?php endif; ?>
				<?php if ( $fullscreen ) : ?>
				<button type="button" class="foliora-btn foliora-fs" title="<?php esc_attr_e( 'Full screen (F)', 'foliora' ); ?>" aria-label="<?php esc_attr_e( 'Full screen', 'foliora' ); ?>">
					<?php echo $this->toolbar_icon( 'fs' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</button>
				<?php endif; ?>
				<div class="foliora-more-wrap">
					<button type="button" class="foliora-btn foliora-more" aria-expanded="false" aria-haspopup="true" title="<?php esc_attr_e( 'More tools', 'foliora' ); ?>" aria-label="<?php esc_attr_e( 'More tools', 'foliora' ); ?>">
						<?php echo $this->toolbar_icon( 'more' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</button>
					<div class="foliora-more-menu" hidden role="menu">
						<?php if ( $show_rotate ) : ?>
						<button type="button" class="foliora-more-item foliora-rotate" role="menuitem">
							<?php echo $this->toolbar_icon( 'rotate' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<span><?php esc_html_e( 'Rotate', 'foliora' ); ?></span>
						</button>
						<?php endif; ?>
						<?php if ( $show_view ) : ?>
						<button type="button" class="foliora-more-item foliora-view" role="menuitem">
							<?php echo $this->toolbar_icon( 'view' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<span><?php esc_html_e( 'View Mode', 'foliora' ); ?></span>
						</button>
						<?php endif; ?>
						<?php if ( $show_pan ) : ?>
						<button type="button" class="foliora-more-item foliora-pan" role="menuitem" aria-pressed="false">
							<?php echo $this->toolbar_icon( 'pan' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<span><?php esc_html_e( 'Hand Tool', 'foliora' ); ?></span>
						</button>
						<?php endif; ?>
						<?php if ( $theme_btn ) : ?>
						<button type="button" class="foliora-more-item foliora-theme-toggle" role="menuitem">
							<?php echo $this->toolbar_icon( 'theme' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<span><?php esc_html_e( 'Switch Theme', 'foliora' ); ?></span>
						</button>
						<?php endif; ?>
						<?php if ( $share ) : ?>
						<button type="button" class="foliora-more-item foliora-share-btn" role="menuitem" aria-expanded="false">
							<?php echo $this->toolbar_icon( 'share' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<span><?php esc_html_e( 'Share Document', 'foliora' ); ?></span>
						</button>
						<?php endif; ?>
						<?php if ( $shortcuts ) : ?>
						<button type="button" class="foliora-more-item foliora-shortcuts-btn" role="menuitem" aria-expanded="false">
							<?php echo $this->toolbar_icon( 'shortcuts' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<span><?php esc_html_e( 'Shortcuts Guide', 'foliora' ); ?></span>
						</button>
						<?php endif; ?>
						<?php if ( $print ) : ?>
						<button type="button" class="foliora-more-item foliora-print" role="menuitem">
							<?php echo $this->toolbar_icon( 'print' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<span><?php esc_html_e( 'Print Document', 'foliora' ); ?></span>
						</button>
						<?php endif; ?>
						<?php if ( $present ) : ?>
						<button type="button" class="foliora-more-item foliora-present" role="menuitem" aria-pressed="false">
							<?php echo $this->toolbar_icon( 'present' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<span><?php esc_html_e( 'Presentation Mode', 'foliora' ); ?></span>
						</button>
						<?php endif; ?>
					</div>
				</div>
			</div>
			<span class="foliora-sr-only foliora-page-live" aria-live="polite"></span>
			<span class="foliora-sr-only"><?php esc_html_e( 'Keyboard: arrows or Page Up and Page Down change page, plus and minus zoom, R rotates, Home and End jump to first or last page, Ctrl or Command F finds text, D toggles reading theme, ? opens shortcuts guide.', 'foliora' ); ?></span>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Modals for keyboard shortcuts and sharing.
	 *
	 * @return string
	 */
	private function render_modals() {
		ob_start();
		?>
		<div class="foliora-modal foliora-shortcuts-modal" hidden role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'Keyboard shortcuts', 'foliora' ); ?>">
			<div class="foliora-modal-backdrop"></div>
			<div class="foliora-modal-card">
				<div class="foliora-modal-header">
					<h3 class="foliora-modal-title"><?php esc_html_e( 'Keyboard Shortcuts', 'foliora' ); ?></h3>
					<button type="button" class="foliora-modal-close" aria-label="<?php esc_attr_e( 'Close', 'foliora' ); ?>">
						<?php echo $this->toolbar_icon( 'close' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</button>
				</div>
				<div class="foliora-modal-body">
					<div class="foliora-shortcuts-grid">
						<div class="foliora-shortcut-row">
							<span class="foliora-shortcut-desc"><?php esc_html_e( 'Next / Previous Page', 'foliora' ); ?></span>
							<span class="foliora-shortcut-keys"><kbd>→</kbd> <kbd>←</kbd> / <kbd>J</kbd> <kbd>K</kbd></span>
						</div>
						<div class="foliora-shortcut-row">
							<span class="foliora-shortcut-desc"><?php esc_html_e( 'Zoom In / Out', 'foliora' ); ?></span>
							<span class="foliora-shortcut-keys"><kbd>+</kbd> <kbd>-</kbd></span>
						</div>
						<div class="foliora-shortcut-row">
							<span class="foliora-shortcut-desc"><?php esc_html_e( 'Fit Page / Width', 'foliora' ); ?></span>
							<span class="foliora-shortcut-keys"><kbd>0</kbd></span>
						</div>
						<div class="foliora-shortcut-row">
							<span class="foliora-shortcut-desc"><?php esc_html_e( 'Rotate Document', 'foliora' ); ?></span>
							<span class="foliora-shortcut-keys"><kbd>R</kbd></span>
						</div>
						<div class="foliora-shortcut-row">
							<span class="foliora-shortcut-desc"><?php esc_html_e( 'Toggle Theme (Dark/Sepia/Light)', 'foliora' ); ?></span>
							<span class="foliora-shortcut-keys"><kbd>D</kbd></span>
						</div>
						<div class="foliora-shortcut-row">
							<span class="foliora-shortcut-desc"><?php esc_html_e( 'Find in Document', 'foliora' ); ?></span>
							<span class="foliora-shortcut-keys"><kbd>/</kbd> or <kbd>Ctrl+F</kbd></span>
						</div>
						<div class="foliora-shortcut-row">
							<span class="foliora-shortcut-desc"><?php esc_html_e( 'Hand / Pan Tool', 'foliora' ); ?></span>
							<span class="foliora-shortcut-keys"><kbd>H</kbd></span>
						</div>
						<div class="foliora-shortcut-row">
							<span class="foliora-shortcut-desc"><?php esc_html_e( 'Full Screen', 'foliora' ); ?></span>
							<span class="foliora-shortcut-keys"><kbd>F</kbd></span>
						</div>
						<div class="foliora-shortcut-row">
							<span class="foliora-shortcut-desc"><?php esc_html_e( 'Presentation Mode', 'foliora' ); ?></span>
							<span class="foliora-shortcut-keys"><kbd>P</kbd></span>
						</div>
						<div class="foliora-shortcut-row">
							<span class="foliora-shortcut-desc"><?php esc_html_e( 'First / Last Page', 'foliora' ); ?></span>
							<span class="foliora-shortcut-keys"><kbd>Home</kbd> <kbd>End</kbd></span>
						</div>
						<div class="foliora-shortcut-row">
							<span class="foliora-shortcut-desc"><?php esc_html_e( 'Close Overlay / Clear Search', 'foliora' ); ?></span>
							<span class="foliora-shortcut-keys"><kbd>Esc</kbd></span>
						</div>
					</div>
				</div>
			</div>
		</div>

		<div class="foliora-modal foliora-share-modal" hidden role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'Share document', 'foliora' ); ?>">
			<div class="foliora-modal-backdrop"></div>
			<div class="foliora-modal-card">
				<div class="foliora-modal-header">
					<h3 class="foliora-modal-title"><?php esc_html_e( 'Share Document', 'foliora' ); ?></h3>
					<button type="button" class="foliora-modal-close" aria-label="<?php esc_attr_e( 'Close', 'foliora' ); ?>">
						<?php echo $this->toolbar_icon( 'close' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</button>
				</div>
				<div class="foliora-modal-body">
					<div class="foliora-share-link-group">
						<input type="text" class="foliora-share-input" readonly value="" />
						<button type="button" class="foliora-btn foliora-share-copy">
							<span class="foliora-share-copy-text"><?php esc_html_e( 'Copy', 'foliora' ); ?></span>
						</button>
					</div>
					<label class="foliora-share-page-option">
						<input type="checkbox" class="foliora-share-page-check" checked /> <?php esc_html_e( 'Link to current page (page ', 'foliora' ); ?><span class="foliora-share-curr-page">1</span>)
					</label>
					<div class="foliora-share-buttons">
						<a href="#" class="foliora-share-item foliora-share-x" target="_blank" rel="noopener noreferrer">X (Twitter)</a>
						<a href="#" class="foliora-share-item foliora-share-fb" target="_blank" rel="noopener noreferrer">Facebook</a>
						<a href="#" class="foliora-share-item foliora-share-linkedin" target="_blank" rel="noopener noreferrer">LinkedIn</a>
						<a href="#" class="foliora-share-item foliora-share-wa" target="_blank" rel="noopener noreferrer">WhatsApp</a>
						<a href="#" class="foliora-share-item foliora-share-email" target="_blank" rel="noopener noreferrer">Email</a>
					</div>
				</div>
			</div>
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
			'prev'      => '<polyline points="15 18 9 12 15 6" />',
			'next'      => '<polyline points="9 18 15 12 9 6" />',
			'minus'     => '<line x1="5" y1="12" x2="19" y2="12" />',
			'plus'      => '<line x1="12" y1="5" x2="12" y2="19" /><line x1="5" y1="12" x2="19" y2="12" />',
			'fit'       => '<path d="M8 3H5a2 2 0 0 0-2 2v3" /><path d="M16 3h3a2 2 0 0 1 2 2v3" /><path d="M8 21H5a2 2 0 0 1-2-2v-3" /><path d="M16 21h3a2 2 0 0 0 2-2v-3" />',
			'rotate'    => '<polyline points="23 4 23 10 17 10" /><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10" />',
			'search'    => '<circle cx="11" cy="11" r="7" /><line x1="21" y1="21" x2="16.65" y2="16.65" />',
			'up'        => '<polyline points="18 15 12 9 6 15" />',
			'down'      => '<polyline points="6 9 12 15 18 9" />',
			'download'  => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" /><polyline points="7 10 12 15 17 10" /><line x1="12" y1="15" x2="12" y2="3" />',
			'print'     => '<polyline points="6 9 6 2 18 2 18 9" /><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2" /><rect x="6" y="14" width="12" height="8" />',
			'fs'        => '<polyline points="15 3 21 3 21 9" /><polyline points="9 21 3 21 3 15" /><line x1="21" y1="3" x2="14" y2="10" /><line x1="3" y1="21" x2="10" y2="14" />',
			'toc'       => '<line x1="8" y1="6" x2="21" y2="6" /><line x1="8" y1="12" x2="21" y2="12" /><line x1="8" y1="18" x2="21" y2="18" /><line x1="3" y1="6" x2="3.01" y2="6" /><line x1="3" y1="12" x2="3.01" y2="12" /><line x1="3" y1="18" x2="3.01" y2="18" />',
			'thumbs'    => '<rect x="3" y="3" width="7" height="7" /><rect x="14" y="3" width="7" height="7" /><rect x="3" y="14" width="7" height="7" /><rect x="14" y="14" width="7" height="7" />',
			'view'      => '<rect x="3" y="3" width="7" height="18" /><rect x="14" y="3" width="7" height="18" />',
			'pan'       => '<path d="M18 11V6a2 2 0 0 0-4 0v5" /><path d="M14 10V4a2 2 0 0 0-4 0v8" /><path d="M10 11V6a2 2 0 0 0-4 0v7" /><path d="M6 13v3a6 6 0 0 0 6 6h2a6 6 0 0 0 6-6v-5a2 2 0 0 0-4 0" />',
			'more'      => '<circle cx="12" cy="5" r="1.5" /><circle cx="12" cy="12" r="1.5" /><circle cx="12" cy="19" r="1.5" />',
			'present'   => '<rect x="2" y="3" width="20" height="14" rx="2" /><line x1="8" y1="21" x2="16" y2="21" /><line x1="12" y1="17" x2="12" y2="21" />',
			'theme'     => '<path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" />',
			'share'     => '<circle cx="18" cy="5" r="3" /><circle cx="6" cy="12" r="3" /><circle cx="18" cy="19" r="3" /><line x1="8.59" y1="13.51" x2="15.42" y2="17.49" /><line x1="15.41" y1="6.51" x2="8.59" y2="10.49" />',
			'shortcuts' => '<circle cx="12" cy="12" r="10" /><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3" /><line x1="12" y1="17" x2="12.01" y2="17" />',
			'close'     => '<line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" />',
			'copy'      => '<rect x="9" y="9" width="13" height="13" rx="2" ry="2" /><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1" />',
			'check'     => '<polyline points="20 6 9 17 4 12" />',
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
			'viewFlip'       => __( '3D FlipBook', 'foliora' ),
			'pan'            => __( 'Hand tool', 'foliora' ),
			'select'         => __( 'Text select', 'foliora' ),
			'more'           => __( 'More tools', 'foliora' ),
			'present'        => __( 'Presentation', 'foliora' ),
			'copied'         => __( 'Link copied!', 'foliora' ),
			'clickToLoad'    => __( 'Click to load document', 'foliora' ),
			'corsTitle'      => __( 'Unable to load document (Cross-Origin Restriction)', 'foliora' ),
			/* translators: %s: remote host name */
			'corsDesc'       => __( 'This PDF is hosted on an external domain (%s) which restricts cross-origin access (CORS).', 'foliora' ),
			'corsDownload'   => __( 'Open / Download PDF directly', 'foliora' ),
			'corsAdminHint'  => __( 'Site Admin: To enable direct embedding, configure Access-Control-Allow-Origin headers on the host server or upload the PDF to your WordPress Media Library.', 'foliora' ),
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
