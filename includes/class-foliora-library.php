<?php
/**
 * Foliora_Library
 *
 * Front-end PDF gallery: [foliora_library] shortcode. Grid of Media
 * Library PDFs with stored thumbnails; clicking a card expands the
 * existing Foliora viewer in place.
 *
 * @package Foliora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Foliora_Library {

	use Foliora_Att_Flag;

	/**
	 * @var bool
	 */
	private $assets_enqueued = false;

	/**
	 * Per-request counter so pagination query vars stay stable when
	 * nested viewer unique IDs increment inside each grid item.
	 *
	 * @var int
	 */
	private static $instance_n = 0;

	public function init() {
		add_shortcode( 'foliora_library', array( $this, 'render_shortcode' ) );
	}

	/**
	 * [foliora_library columns="3" per_page="12" orderby="date" order="DESC"]
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function render_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'columns'  => '3',
				'per_page' => '12',
				'orderby'  => 'date',
				'order'    => 'DESC',
				'search'   => 'true',
			),
			$atts,
			'foliora_library'
		);

		$columns  = $this->clamp_int( $atts['columns'], 1, 6, 3 );
		$per_page = $this->clamp_int( $atts['per_page'], 1, 48, 12 );
		$orderby  = $this->sanitize_orderby( $atts['orderby'] );
		$order    = ( 'ASC' === strtoupper( $atts['order'] ) ) ? 'ASC' : 'DESC';
		$search_on = $this->att_flag( $atts['search'] ?? 'true', true );

		$uid         = ++self::$instance_n;
		$instance_id = 'foliora-library-' . $uid;
		$query_var   = 'foliora_page_' . $uid;
		$search_var  = 'foliora_q_' . $uid;
		$paged = 1;
		if ( isset( $_GET[ $query_var ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public pagination query var.
			$paged = max( 1, absint( wp_unslash( $_GET[ $query_var ] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public pagination query var.
		}
		$search = '';
		if ( $search_on && isset( $_GET[ $search_var ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public search query var.
			$search = sanitize_text_field( wp_unslash( $_GET[ $search_var ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public search query var.
		}

		$query = new WP_Query(
			array(
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				'post_mime_type'         => 'application/pdf',
				'posts_per_page'         => $per_page,
				'paged'                  => $paged,
				'orderby'                => $orderby,
				'order'                  => $order,
				's'                      => $search,
				'no_found_rows'          => false,
				'foliora_content_search' => ( '' !== $search ),
			)
		);

		if ( ! $query->have_posts() ) {
			wp_enqueue_style(
				'foliora-library',
				FOLIORA_URL . 'assets/css/foliora-library.css',
				array(),
				FOLIORA_VERSION
			);
			wp_reset_postdata();
			$html  = '<div class="foliora-library">';
			$html .= $this->render_search_form( $search_on, $search, $search_var, $query_var );
			$html .= $this->render_empty( $search );
			$html .= '</div>';
			return $html;
		}

		$this->enqueue_assets();

		ob_start();
		?>
		<div id="<?php echo esc_attr( $instance_id ); ?>" class="foliora-library" data-columns="<?php echo esc_attr( (string) $columns ); ?>">
			<?php echo $this->render_search_form( $search_on, $search, $search_var, $query_var ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helper. ?>
			<ul class="foliora-library-grid" style="--foliora-cols: <?php echo esc_attr( (string) $columns ); ?>;">
				<?php foreach ( $query->posts as $attachment ) : ?>
					<?php echo $this->render_item( $attachment ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with escaped helpers. ?>
				<?php endforeach; ?>
			</ul>
			<?php echo $this->render_pagination( $query, $paged, $query_var, $search_var, $search ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- paginate_links + kses. ?>
		</div>
		<?php
		wp_reset_postdata();
		return ob_get_clean();
	}

	private function render_item( $attachment ) {
		$id    = (int) $attachment->ID;
		$url   = wp_get_attachment_url( $id );
		if ( ! $url ) {
			return '';
		}

		$title = get_the_title( $attachment );
		if ( '' === $title ) {
			$title = wp_basename( $url );
		}

		$embed_id = 'foliora-library-embed-' . wp_unique_id();
		$thumb_id = Foliora_Thumbnails::get_thumbnail_id( $id );
		$image    = '';
		if ( $thumb_id ) {
			$image = wp_get_attachment_image(
				$thumb_id,
				'medium',
				false,
				array(
					'class'    => 'foliora-library-image',
					'loading'  => 'lazy',
					'alt'      => $title,
				)
			);
		}
		if ( ! $image ) {
			$image = '<span class="foliora-library-placeholder" aria-hidden="true">' . $this->placeholder_icon() . '</span>';
		}

		$viewer = Foliora::instance()->viewer->render_shortcode(
			array(
				'file'  => $url,
				'width' => '100%',
				'hash'  => 'false',
			)
		);
		$viewer = str_replace( ' class="foliora-viewer"', ' class="foliora-viewer" data-foliora-defer="1"', $viewer );

		ob_start();
		?>
		<li class="foliora-library-item">
			<button type="button" class="foliora-library-card" aria-expanded="false" aria-controls="<?php echo esc_attr( $embed_id ); ?>">
				<span class="foliora-library-thumb"><?php echo $image; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_get_attachment_image or static SVG. ?></span>
				<span class="foliora-library-title"><?php echo esc_html( $title ); ?></span>
			</button>
			<div id="<?php echo esc_attr( $embed_id ); ?>" class="foliora-library-embed" hidden>
				<?php echo $viewer; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Foliora_Viewer output. ?>
			</div>
		</li>
		<?php
		return ob_get_clean();
	}

	private function render_search_form( $enabled, $search, $search_var, $query_var ) {
		if ( ! $enabled ) {
			return '';
		}

		ob_start();
		?>
		<form class="foliora-library-search" method="get" role="search">
			<?php
			if ( isset( $_GET ) && is_array( $_GET ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- preserve public query args.
				foreach ( wp_unslash( $_GET ) as $key => $value ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- preserve public query args.
					$key = sanitize_key( $key );
					if ( ! $key || $key === $search_var || $key === $query_var || ! is_scalar( $value ) ) {
						continue;
					}
					printf(
						'<input type="hidden" name="%1$s" value="%2$s" />',
						esc_attr( $key ),
						esc_attr( sanitize_text_field( (string) $value ) )
					);
				}
			}
			?>
			<label class="screen-reader-text" for="<?php echo esc_attr( $search_var ); ?>"><?php esc_html_e( 'Search documents', 'foliora' ); ?></label>
			<input
				type="search"
				id="<?php echo esc_attr( $search_var ); ?>"
				name="<?php echo esc_attr( $search_var ); ?>"
				value="<?php echo esc_attr( $search ); ?>"
				placeholder="<?php esc_attr_e( 'Search documents…', 'foliora' ); ?>"
			/>
			<button type="submit" class="foliora-library-search-submit"><?php esc_html_e( 'Search', 'foliora' ); ?></button>
		</form>
		<?php
		return ob_get_clean();
	}

	private function render_pagination( $query, $paged, $query_var, $search_var = '', $search = '' ) {
		if ( (int) $query->max_num_pages < 2 ) {
			return '';
		}

		$add_args = array();
		if ( '' !== $search_var && '' !== $search ) {
			$add_args[ $search_var ] = $search;
		}

		$links = paginate_links(
			array(
				'base'      => add_query_arg( $query_var, '%#%' ),
				'format'    => '',
				'total'     => (int) $query->max_num_pages,
				'current'   => $paged,
				'type'      => 'list',
				'add_args'  => $add_args,
				'prev_text' => __( 'Previous', 'foliora' ),
				'next_text' => __( 'Next', 'foliora' ),
			)
		);

		if ( ! $links ) {
			return '';
		}

		return '<nav class="foliora-library-pager" aria-label="' . esc_attr__( 'Document library pages', 'foliora' ) . '">' . wp_kses_post( $links ) . '</nav>';
	}

	private function render_empty( $search = '' ) {
		$message = '' !== $search
			? __( 'No PDFs matched that search.', 'foliora' )
			: __( 'No PDFs found.', 'foliora' );
		$html    = '<div class="foliora-library-empty"><p>' . esc_html( $message ) . '</p>';
		if ( current_user_can( 'upload_files' ) ) {
			$html .= '<p><a href="' . esc_url( admin_url( 'media-new.php' ) ) . '">' . esc_html__( 'Upload a PDF', 'foliora' ) . '</a></p>';
		}
		$html .= '</div>';
		return $html;
	}

	private function enqueue_assets() {
		if ( $this->assets_enqueued ) {
			return;
		}
		$this->assets_enqueued = true;

		Foliora::instance()->viewer->enqueue_assets();

		wp_enqueue_style(
			'foliora-library',
			FOLIORA_URL . 'assets/css/foliora-library.css',
			array( 'foliora-viewer' ),
			FOLIORA_VERSION
		);

		wp_enqueue_script(
			'foliora-library',
			FOLIORA_URL . 'assets/js/foliora-library.js',
			array( 'foliora-viewer' ),
			FOLIORA_VERSION,
			true
		);
	}

	private function placeholder_icon() {
		return '<svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" /><polyline points="14 2 14 8 20 8" /><line x1="8" y1="13" x2="16" y2="13" /><line x1="8" y1="17" x2="12" y2="17" /></svg>';
	}


	private function clamp_int( $value, $min, $max, $default ) {
		$n = absint( $value );
		if ( $n < $min || $n > $max ) {
			return $default;
		}
		return $n;
	}

	private function sanitize_orderby( $value ) {
		$value = sanitize_key( $value );
		$allowed = array( 'date', 'title', 'modified' );
		return in_array( $value, $allowed, true ) ? $value : 'date';
	}
}
