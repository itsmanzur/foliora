<?php
/**
 * Foliora_SEO
 *
 * Discovers PDF contents for WordPress search and social cards.
 * Text is extracted once in wp-admin (PDF.js, same visit as thumbnails),
 * stored on the PDF attachment, then copied onto posts/pages that embed
 * that file so core search can match phrases inside the document.
 *
 * @package Foliora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Foliora_SEO {

	const META_TEXT     = '_foliora_extracted_text';
	const META_PAGES    = '_foliora_extracted_pages';
	const META_INDEXED  = '_foliora_text_indexed';
	const META_SEARCH   = '_foliora_search_index';
	const MAX_CHARS     = 80000;
	const MAX_INDEX     = 200000;

	public function init() {
		add_action( 'wp_ajax_foliora_save_extracted_text', array( $this, 'ajax_save_extracted_text' ) );
		add_action( 'save_post', array( $this, 'on_save_post' ), 20, 2 );
		add_filter( 'posts_join', array( $this, 'filter_posts_join' ), 10, 2 );
		add_filter( 'posts_search', array( $this, 'filter_posts_search' ), 10, 2 );
		add_filter( 'posts_distinct', array( $this, 'filter_posts_distinct' ), 10, 2 );
		add_action( 'wp_head', array( $this, 'output_social_meta' ), 5 );
		add_action( 'wp_head', array( $this, 'output_schema_jsonld' ), 10 );
		add_filter( 'wpseo_opengraph_image', array( $this, 'filter_seo_image' ) );
		add_filter( 'wpseo_twitter_image', array( $this, 'filter_seo_image' ) );
		add_filter( 'rank_math/opengraph/facebook/image', array( $this, 'filter_seo_image' ) );
		add_filter( 'rank_math/opengraph/twitter/image', array( $this, 'filter_seo_image' ) );
	}

	/**
	 * @param int $attachment_id PDF attachment ID.
	 * @return bool
	 */
	public static function is_indexed( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		if ( ! $attachment_id ) {
			return false;
		}
		return '1' === (string) get_post_meta( $attachment_id, self::META_INDEXED, true );
	}

	/**
	 * @param int $attachment_id PDF attachment ID.
	 * @return string
	 */
	public static function get_extracted_text( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		if ( ! $attachment_id ) {
			return '';
		}
		$text = get_post_meta( $attachment_id, self::META_TEXT, true );
		return is_string( $text ) ? $text : '';
	}

	public function ajax_save_extracted_text() {
		check_ajax_referer( 'foliora_admin', 'nonce' );

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden.', 'foliora' ) ), 403 );
		}

		if ( ! $this->indexing_enabled() ) {
			wp_send_json_error( array( 'message' => __( 'PDF text indexing is turned off.', 'foliora' ) ) );
		}

		$pdf_id = isset( $_POST['attachment_id'] ) ? absint( wp_unslash( $_POST['attachment_id'] ) ) : 0;
		$pdf    = $pdf_id ? get_post( $pdf_id ) : null;

		if ( ! $pdf || 'attachment' !== $pdf->post_type || 'application/pdf' !== $pdf->post_mime_type ) {
			wp_send_json_error( array( 'message' => __( 'That file is not a PDF attachment.', 'foliora' ) ) );
		}

		$raw = isset( $_POST['text'] ) ? wp_unslash( $_POST['text'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized below.
		if ( ! is_string( $raw ) ) {
			$raw = '';
		}

		$text  = $this->sanitize_extracted_text( $raw );
		$pages = isset( $_POST['pages'] ) ? absint( wp_unslash( $_POST['pages'] ) ) : 0;

		/**
		 * Filter: foliora/extracted_text
		 *
		 * Last chance to alter stored PDF text before it is saved on the
		 * attachment and copied onto embedding posts for site search.
		 *
		 * @param string $text  Sanitized plain text.
		 * @param int    $pdf_id Attachment ID.
		 */
		$filtered = apply_filters( 'foliora/extracted_text', $text, $pdf_id );
		if ( is_string( $filtered ) ) {
			$text = $this->clip( $filtered, $this->max_chars() );
		}

		update_post_meta( $pdf_id, self::META_TEXT, $text );
		update_post_meta( $pdf_id, self::META_PAGES, $pages );
		update_post_meta( $pdf_id, self::META_INDEXED, '1' );

		$this->rebuild_posts_embedding( $pdf_id );

		wp_send_json_success(
			array(
				'indexed' => true,
				'chars'   => strlen( $text ),
				'empty'   => ( '' === $text ),
			)
		);
	}

	/**
	 * @param int      $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public function on_save_post( $post_id, $post ) {
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! $post instanceof WP_Post || ! is_string( $post->post_content ) ) {
			return;
		}
		if ( in_array( $post->post_type, array( 'revision', 'nav_menu_item', 'attachment', 'custom_css', 'customize_changeset' ), true ) ) {
			return;
		}

		$this->rebuild_post_index( (int) $post_id, $post->post_content );
	}

	/**
	 * @param string   $join  JOIN clause.
	 * @param WP_Query $query Query.
	 * @return string
	 */
	public function filter_posts_join( $join, $query ) {
		if ( ! $this->should_extend_search( $query ) ) {
			return $join;
		}

		global $wpdb;
		$key   = $this->search_meta_key( $query );
		$join .= $wpdb->prepare(
			" LEFT JOIN {$wpdb->postmeta} AS foliora_search_idx ON ({$wpdb->posts}.ID = foliora_search_idx.post_id AND foliora_search_idx.meta_key = %s) ",
			$key
		);
		return $join;
	}

	/**
	 * @param string   $search Search SQL.
	 * @param WP_Query $query  Query.
	 * @return string
	 */
	public function filter_posts_search( $search, $query ) {
		if ( '' === $search || ! $this->should_extend_search( $query ) ) {
			return $search;
		}

		global $wpdb;
		$pattern = '/\(' . preg_quote( $wpdb->posts, '/' ) . '\.post_content LIKE (\'[^\']+\')\)/';
		$replaced = preg_replace(
			$pattern,
			'($0 OR (foliora_search_idx.meta_value LIKE $1))',
			$search
		);

		return is_string( $replaced ) ? $replaced : $search;
	}

	/**
	 * @param string   $distinct DISTINCT clause.
	 * @param WP_Query $query    Query.
	 * @return string
	 */
	public function filter_posts_distinct( $distinct, $query ) {
		if ( $this->should_extend_search( $query ) ) {
			return 'DISTINCT';
		}
		return $distinct;
	}

	public function output_social_meta() {
		if ( is_admin() || ! $this->social_enabled() || $this->seo_plugin_handles_og() ) {
			return;
		}
		if ( ! is_singular() ) {
			return;
		}

		$url = $this->get_singular_pdf_image_url();
		if ( '' === $url ) {
			return;
		}

		$alt = $this->get_singular_pdf_image_alt();
		$size = $this->get_singular_pdf_image_size();

		echo '<meta property="og:image" content="' . esc_url( $url ) . '" />' . "\n";
		if ( 0 === strpos( $url, 'https://' ) ) {
			echo '<meta property="og:image:secure_url" content="' . esc_url( $url ) . '" />' . "\n";
		}
		echo '<meta property="og:image:type" content="image/png" />' . "\n";
		if ( ! empty( $size['width'] ) ) {
			echo '<meta property="og:image:width" content="' . esc_attr( (string) $size['width'] ) . '" />' . "\n";
		}
		if ( ! empty( $size['height'] ) ) {
			echo '<meta property="og:image:height" content="' . esc_attr( (string) $size['height'] ) . '" />' . "\n";
		}
		if ( '' !== $alt ) {
			echo '<meta property="og:image:alt" content="' . esc_attr( $alt ) . '" />' . "\n";
		}
		echo '<meta name="twitter:card" content="summary_large_image" />' . "\n";
		echo '<meta name="twitter:image" content="' . esc_url( $url ) . '" />' . "\n";
		if ( '' !== $alt ) {
			echo '<meta name="twitter:image:alt" content="' . esc_attr( $alt ) . '" />' . "\n";
		}
	}

	/**
	 * Output Schema.org DigitalDocument JSON-LD structured data for Google Search.
	 */
	public function output_schema_jsonld() {
		if ( is_admin() || ! is_singular() ) {
			return;
		}

		$post = get_queried_object();
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		$pdf_ids = array();
		if ( 'attachment' === $post->post_type && 'application/pdf' === $post->post_mime_type ) {
			$pdf_ids[] = (int) $post->ID;
		} else {
			$pdf_ids = $this->find_pdf_ids_in_content( $post->post_content );
		}

		if ( empty( $pdf_ids ) ) {
			return;
		}

		$schemas = array();
		foreach ( $pdf_ids as $pdf_id ) {
			$url = wp_get_attachment_url( $pdf_id );
			if ( ! $url ) {
				continue;
			}
			$title = get_the_title( $pdf_id );
			if ( '' === $title ) {
				$title = wp_basename( $url );
			}
			$doc = array(
				'@context'       => 'https://schema.org',
				'@type'          => 'DigitalDocument',
				'name'           => $title,
				'url'            => $url,
				'encodingFormat' => 'application/pdf',
			);

			$pages = absint( get_post_meta( $pdf_id, self::META_PAGES, true ) );
			if ( $pages > 0 ) {
				$doc['numberOfPages'] = $pages;
			}

			$thumb_url = Foliora_Thumbnails::get_thumbnail_url( $pdf_id, 'large' );
			if ( $thumb_url ) {
				$doc['thumbnailUrl'] = $thumb_url;
			}

			$text = self::get_extracted_text( $pdf_id );
			if ( '' !== $text ) {
				$doc['description'] = wp_trim_words( $text, 35, '…' );
			}

			/**
			 * Filter: foliora/schema_digital_document
			 *
			 * Customize or extend the Schema.org DigitalDocument JSON-LD data.
			 *
			 * @param array $doc    Schema data array.
			 * @param int   $pdf_id PDF attachment post ID.
			 * @param int   $post_id Singular post ID embedding the PDF.
			 */
			$filtered = apply_filters( 'foliora/schema_digital_document', $doc, $pdf_id, (int) $post->ID );
			if ( is_array( $filtered ) ) {
				$schemas[] = $filtered;
			}
		}

		if ( empty( $schemas ) ) {
			return;
		}

		$json = count( $schemas ) === 1 ? $schemas[0] : $schemas;
		printf(
			"<script type=\"application/ld+json\">\n%s\n</script>\n",
			wp_json_encode( $json, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT )
		);
	}

	/**
	 * Yoast / Rank Math fallback when those plugins have no image yet.
	 *
	 * @param string $image Existing image URL.
	 * @return string
	 */
	public function filter_seo_image( $image ) {
		if ( ! empty( $image ) || ! $this->social_enabled() ) {
			return $image;
		}
		$url = $this->get_singular_pdf_image_url();
		return '' !== $url ? $url : $image;
	}

	/**
	 * @param WP_Query $query Query.
	 * @return bool
	 */
	private function should_extend_search( $query ) {
		if ( ! $query instanceof WP_Query ) {
			return false;
		}
		if ( $query->get( 'foliora_content_search' ) ) {
			return $this->indexing_enabled();
		}
		if ( is_admin() || ! $query->is_search() || ! $query->is_main_query() ) {
			return false;
		}
		return $this->indexing_enabled();
	}

	/**
	 * Site search indexes host posts via META_SEARCH. Library / Documents
	 * queries search the PDF attachment's extracted text instead.
	 *
	 * @param WP_Query $query Query.
	 * @return string
	 */
	private function search_meta_key( $query ) {
		if ( $query instanceof WP_Query && $query->get( 'foliora_content_search' ) ) {
			return self::META_TEXT;
		}
		return self::META_SEARCH;
	}

	/**
	 * @param int    $post_id Post ID.
	 * @param string $content Post content.
	 */
	private function rebuild_post_index( $post_id, $content ) {
		$post_id = absint( $post_id );
		if ( ! $post_id ) {
			return;
		}

		if ( ! $this->indexing_enabled() ) {
			delete_post_meta( $post_id, self::META_SEARCH );
			return;
		}

		$ids  = $this->find_pdf_ids_in_content( $content );
		$blob = '';
		foreach ( $ids as $pdf_id ) {
			$piece = self::get_extracted_text( $pdf_id );
			if ( '' !== $piece ) {
				$blob .= ' ' . $piece;
			}
		}
		$blob = trim( preg_replace( '/\s+/', ' ', $blob ) );
		$blob = $this->clip( $blob, self::MAX_INDEX );

		/**
		 * Filter: foliora/search_index
		 *
		 * Combined PDF text stored on a post/page for core search.
		 *
		 * @param string $blob    Combined plain text.
		 * @param int    $post_id Host post ID.
		 * @param int[]  $ids     Embedded PDF attachment IDs.
		 */
		$filtered = apply_filters( 'foliora/search_index', $blob, $post_id, $ids );
		if ( is_string( $filtered ) ) {
			$blob = $this->clip( $filtered, self::MAX_INDEX );
		}

		if ( '' === $blob ) {
			delete_post_meta( $post_id, self::META_SEARCH );
			return;
		}

		update_post_meta( $post_id, self::META_SEARCH, $blob );
	}

	/**
	 * @param int $attachment_id PDF attachment ID.
	 */
	private function rebuild_posts_embedding( $attachment_id ) {
		$url = wp_get_attachment_url( $attachment_id );
		if ( ! $url ) {
			return;
		}

		global $wpdb;
		$needles = array_unique(
			array_filter(
				array(
					$url,
					preg_replace( '#^https?:#i', '', $url ),
					wp_parse_url( $url, PHP_URL_PATH ),
				)
			)
		);

		// Page through matches instead of a flat LIMIT so a PDF embedded on more
		// than a handful of posts still gets every one reindexed. $max_rows is
		// a safety cap, not an expected ceiling, so a single stray PDF can't
		// run away on a very large site.
		$batch_size = 200;
		$max_rows   = 5000;

		$found = array();
		foreach ( $needles as $needle ) {
			$like      = '%' . $wpdb->esc_like( (string) $needle ) . '%';
			$cache_key = 'embed_posts_' . md5( $like );
			$rows      = wp_cache_get( $cache_key, 'foliora' );

			if ( false === $rows ) {
				$rows   = array();
				$offset = 0;
				do {
					$page = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off LIKE lookup of posts embedding this PDF; cached in the foliora group.
						$wpdb->prepare(
							"SELECT ID FROM {$wpdb->posts} WHERE post_type NOT IN ('revision','attachment','nav_menu_item') AND post_status IN ('publish','private','draft','pending') AND post_content LIKE %s LIMIT %d OFFSET %d",
							$like,
							$batch_size,
							$offset
						)
					);
					if ( is_array( $page ) ) {
						$rows = array_merge( $rows, $page );
					}
					$offset += $batch_size;
				} while ( is_array( $page ) && count( $page ) === $batch_size && count( $rows ) < $max_rows );

				wp_cache_set( $cache_key, $rows, 'foliora', HOUR_IN_SECONDS );
			}

			if ( is_array( $rows ) ) {
				$found = array_merge( $found, $rows );
			}
		}

		$found = array_unique( array_map( 'absint', $found ) );
		foreach ( $found as $post_id ) {
			$post = get_post( $post_id );
			if ( $post instanceof WP_Post ) {
				$this->rebuild_post_index( $post_id, $post->post_content );
			}
		}
	}

	/**
	 * @param string $content Post content.
	 * @return int[]
	 */
	private function find_pdf_ids_in_content( $content ) {
		$urls = array();

		if ( function_exists( 'has_shortcode' ) && has_shortcode( $content, 'foliora' ) ) {
			$pattern = get_shortcode_regex( array( 'foliora' ) );
			if ( preg_match_all( '/' . $pattern . '/s', $content, $matches, PREG_SET_ORDER ) ) {
				foreach ( $matches as $shortcode ) {
					$atts = shortcode_parse_atts( $shortcode[3] );
					if ( is_array( $atts ) && ! empty( $atts['file'] ) && is_string( $atts['file'] ) ) {
						$urls[] = $atts['file'];
					}
				}
			}
		}

		if ( preg_match_all( '/<!--\s+wp:foliora\/viewer\s+(\{.*?\})\s+\/?-->/s', $content, $blocks ) ) {
			foreach ( $blocks[1] as $json ) {
				$data = json_decode( $json, true );
				if ( is_array( $data ) && ! empty( $data['file'] ) && is_string( $data['file'] ) ) {
					$urls[] = $data['file'];
				}
			}
		}

		$ids = array();
		foreach ( $urls as $url ) {
			$id = $this->url_to_attachment_id( html_entity_decode( $url, ENT_QUOTES, 'UTF-8' ) );
			if ( $id ) {
				$ids[] = $id;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * @param string $url File URL.
	 * @return int
	 */
	private function url_to_attachment_id( $url ) {
		// Delegate to the shared helper in Foliora_Compat so the URL-to-ID
		// resolution logic lives in exactly one place.
		return Foliora_Compat::attachment_id_from_url( $url );
	}

	/**
	 * @return string
	 */
	private function get_singular_pdf_image_url() {
		$post = get_queried_object();
		if ( ! $post instanceof WP_Post ) {
			return '';
		}

		if ( 'attachment' === $post->post_type && 'application/pdf' === $post->post_mime_type ) {
			return (string) Foliora_Thumbnails::get_thumbnail_url( $post->ID, 'large' );
		}

		$ids = $this->find_pdf_ids_in_content( $post->post_content );
		if ( empty( $ids ) ) {
			return '';
		}

		$url = Foliora_Thumbnails::get_thumbnail_url( $ids[0], 'large' );

		/**
		 * Filter: foliora/og_image
		 *
		 * Social-card image URL for a singular post that embeds a PDF.
		 *
		 * @param string $url     Image URL or empty.
		 * @param int    $post_id Host post ID.
		 * @param int    $pdf_id  First embedded PDF attachment ID.
		 */
		$filtered = apply_filters( 'foliora/og_image', $url, (int) $post->ID, (int) $ids[0] );
		return is_string( $filtered ) ? $filtered : (string) $url;
	}

	/**
	 * @return string
	 */
	private function get_singular_pdf_image_alt() {
		$post = get_queried_object();
		if ( ! $post instanceof WP_Post ) {
			return '';
		}
		$ids = $this->find_pdf_ids_in_content( $post->post_content );
		if ( empty( $ids ) && 'attachment' === $post->post_type ) {
			$ids = array( (int) $post->ID );
		}
		if ( empty( $ids ) ) {
			return '';
		}
		$title = get_the_title( $ids[0] );
		return is_string( $title ) ? $title : '';
	}

	/**
	 * @return array{width:int,height:int}
	 */
	private function get_singular_pdf_image_size() {
		$post = get_queried_object();
		$out  = array(
			'width'  => 0,
			'height' => 0,
		);
		if ( ! $post instanceof WP_Post ) {
			return $out;
		}
		$pdf_id = 0;
		if ( 'attachment' === $post->post_type ) {
			$pdf_id = (int) $post->ID;
		} else {
			$ids = $this->find_pdf_ids_in_content( $post->post_content );
			$pdf_id = empty( $ids ) ? 0 : (int) $ids[0];
		}
		$thumb_id = Foliora_Thumbnails::get_thumbnail_id( $pdf_id );
		if ( ! $thumb_id ) {
			return $out;
		}
		$meta = wp_get_attachment_metadata( $thumb_id );
		if ( is_array( $meta ) ) {
			$out['width']  = isset( $meta['width'] ) ? absint( $meta['width'] ) : 0;
			$out['height'] = isset( $meta['height'] ) ? absint( $meta['height'] ) : 0;
		}
		return $out;
	}

	/**
	 * @param string $raw Incoming text.
	 * @return string
	 */
	private function sanitize_extracted_text( $raw ) {
		$raw = wp_check_invalid_utf8( $raw );
		$raw = wp_strip_all_tags( $raw );
		$raw = preg_replace( '/[ \t]+/', ' ', $raw );
		$raw = preg_replace( '/\n{3,}/', "\n\n", (string) $raw );
		$raw = trim( (string) $raw );
		return $this->clip( $raw, $this->max_chars() );
	}

	/**
	 * @param string $text Text.
	 * @param int    $max  Max characters.
	 * @return string
	 */
	private function clip( $text, $max ) {
		$max = absint( $max );
		if ( $max < 1 ) {
			return '';
		}
		if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
			if ( mb_strlen( $text, 'UTF-8' ) > $max ) {
				return mb_substr( $text, 0, $max, 'UTF-8' );
			}
			return $text;
		}
		if ( strlen( $text ) > $max ) {
			return substr( $text, 0, $max );
		}
		return $text;
	}

	/**
	 * @return int
	 */
	private function max_chars() {
		$max = absint( apply_filters( 'foliora/extracted_text_max_chars', self::MAX_CHARS ) );
		return $max > 0 ? $max : self::MAX_CHARS;
	}

	/**
	 * @return bool
	 */
	private function indexing_enabled() {
		$settings = get_option( 'foliora_settings', array() );
		return ! isset( $settings['index_pdf_text'] ) || ! empty( $settings['index_pdf_text'] );
	}

	/**
	 * @return bool
	 */
	private function social_enabled() {
		$settings = get_option( 'foliora_settings', array() );
		return ! isset( $settings['social_preview'] ) || ! empty( $settings['social_preview'] );
	}

	/**
	 * @return bool
	 */
	private function seo_plugin_handles_og() {
		return defined( 'WPSEO_VERSION' )                       // Yoast SEO
			|| defined( 'RANK_MATH_VERSION' )                   // Rank Math
			|| defined( 'SEOPRESS_VERSION' )                    // SEOPress
			|| defined( 'AIOSEO_VERSION' )                      // All in One SEO
			|| defined( 'SLIM_SEO_VER' )                        // Slim SEO
			|| defined( 'SCHEMA_PRO_VERSION' )                  // Schema Pro
			|| class_exists( '\The_SEO_Framework\Load' )        // The SEO Framework
			|| class_exists( 'BSF_AIOSRS_Pro' );                // Schema & Structured Data for WP
	}
}
