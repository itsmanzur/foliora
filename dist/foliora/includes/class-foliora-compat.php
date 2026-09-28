<?php
/**
 * Foliora_Compat
 *
 * Ecosystem glue that must not load page-builder classes: WPML/Polylang
 * swap the embedded PDF to the current language, and caching plugins are
 * told not to minify/combine/delay Foliora's viewer scripts (PDF.js
 * breaks when concatenated).
 *
 * @package Foliora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Foliora_Compat {

	public function init() {
		add_filter( 'foliora/viewer_atts', array( $this, 'localize_viewer_atts' ), 5 );
		add_filter( 'script_loader_tag', array( $this, 'script_loader_tag' ), 10, 2 );

		add_filter( 'rocket_exclude_js', array( $this, 'exclude_js_paths' ) );
		add_filter( 'rocket_exclude_defer_js', array( $this, 'exclude_js_paths' ) );
		add_filter( 'rocket_delay_js_exclusions', array( $this, 'exclude_js_paths' ) );
		add_filter( 'rocket_exclude_css', array( $this, 'exclude_css_paths' ) );

		add_filter( 'w3tc_minify_js_do_tag_minification', array( $this, 'w3tc_skip_js' ), 10, 3 );
		add_filter( 'w3tc_minify_css_do_tag_minification', array( $this, 'w3tc_skip_css' ), 10, 3 );

		add_filter( 'autoptimize_filter_js_exclude', array( $this, 'autoptimize_js_exclude' ) );
		add_filter( 'autoptimize_filter_css_exclude', array( $this, 'autoptimize_css_exclude' ) );

		add_filter( 'litespeed_optimize_js_excludes', array( $this, 'exclude_js_paths' ) );
		add_filter( 'litespeed_optm_js_defer_exc', array( $this, 'exclude_js_paths' ) );
		add_filter( 'litespeed_optm_js_delay_exc', array( $this, 'exclude_js_paths' ) );
		add_filter( 'litespeed_optimize_css_excludes', array( $this, 'exclude_css_paths' ) );

		add_filter( 'sgo_js_minify_exclude', array( $this, 'sg_exclude_handles' ) );
		add_filter( 'sgo_javascript_combine_exclude', array( $this, 'sg_exclude_handles' ) );
		add_filter( 'sgo_js_async_exclude', array( $this, 'sg_exclude_handles' ) );
		add_filter( 'sgo_css_combine_exclude', array( $this, 'sg_exclude_css_handles' ) );
	}

	/**
	 * If the file is a Media Library PDF that has a translation in the
	 * current language, use that translation's URL. External URLs pass
	 * through unchanged.
	 *
	 * @param array $atts Viewer attributes.
	 * @return array
	 */
	public function localize_viewer_atts( $atts ) {
		if ( ! is_array( $atts ) || empty( $atts['file'] ) || ! is_string( $atts['file'] ) ) {
			return $atts;
		}

		$id = self::attachment_id_from_url( $atts['file'] );
		if ( ! $id ) {
			return $atts;
		}

		$translated = self::translate_attachment_id( $id );
		if ( $translated && $translated !== $id ) {
			$url = wp_get_attachment_url( $translated );
			if ( $url ) {
				$atts['file'] = $url;
			}
		}

		return $atts;
	}

	/**
	 * @param string $url File URL.
	 * @return int
	 */
	public static function attachment_id_from_url( $url ) {
		$url = esc_url_raw( $url );
		if ( '' === $url ) {
			return 0;
		}
		$url = strtok( $url, '?' );
		$id  = absint( attachment_url_to_postid( $url ) );
		if ( $id ) {
			return $id;
		}

		$uploads  = wp_get_upload_dir();
		$baseurl  = isset( $uploads['baseurl'] ) ? (string) $uploads['baseurl'] : '';
		$path     = (string) wp_parse_url( $url, PHP_URL_PATH );
		$basepath = $baseurl ? (string) wp_parse_url( $baseurl, PHP_URL_PATH ) : '';
		if ( '' === $path || '' === $basepath || 0 !== strpos( $path, $basepath ) ) {
			return 0;
		}

		$relative = ltrim( substr( $path, strlen( $basepath ) ), '/' );
		if ( '' === $relative ) {
			return 0;
		}

		$cache_key = 'attach_file_' . md5( $relative );
		$found     = wp_cache_get( $cache_key, 'foliora' );
		if ( false === $found ) {
			global $wpdb;
			$found = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- fallback when attachment_url_to_postid misses; cached in the foliora group.
				$wpdb->prepare(
					"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1",
					$relative
				)
			);
			wp_cache_set( $cache_key, $found, 'foliora' );
		}

		return absint( $found );
	}

	/**
	 * @param int $attachment_id Attachment ID.
	 * @return int
	 */
	public static function translate_attachment_id( $attachment_id ) {
		$original      = absint( $attachment_id );
		$attachment_id = $original;
		if ( ! $attachment_id ) {
			return 0;
		}

		if ( has_filter( 'wpml_object_id' ) ) {
			$translated = absint( apply_filters( 'wpml_object_id', $attachment_id, 'attachment', true ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML public API.
			if ( $translated ) {
				$attachment_id = $translated;
			}
		} elseif ( function_exists( 'pll_get_post' ) ) {
			$translated = absint( pll_get_post( $attachment_id ) );
			if ( $translated ) {
				$attachment_id = $translated;
			}
		}

		/**
		 * Filter: foliora/translate_attachment_id
		 *
		 * Override which Media Library attachment is used for the current
		 * language after WPML / Polylang have had a chance to swap it.
		 *
		 * @param int $attachment_id Resolved attachment ID.
		 * @param int $original      ID parsed from the file URL.
		 */
		$filtered = apply_filters( 'foliora/translate_attachment_id', $attachment_id, $original );
		return absint( $filtered ) ? absint( $filtered ) : $attachment_id;
	}

	/**
	 * @param string $tag    Script tag.
	 * @param string $handle Script handle.
	 * @return string
	 */
	public function script_loader_tag( $tag, $handle ) {
		if ( ! in_array( $handle, $this->js_handles(), true ) ) {
			return $tag;
		}
		if ( false !== strpos( $tag, 'data-no-optimize' ) ) {
			return $tag;
		}
		return preg_replace(
			'/<script\s/i',
			'<script data-no-optimize="1" data-no-minify="1" data-no-defer="1" data-no-delay="1" data-cfasync="false" nowprocket ',
			$tag,
			1
		);
	}

	/**
	 * @param string[] $excluded Paths or patterns.
	 * @return string[]
	 */
	public function exclude_js_paths( $excluded ) {
		if ( ! is_array( $excluded ) ) {
			$excluded = array();
		}
		return array_values( array_unique( array_merge( $excluded, $this->js_path_patterns() ) ) );
	}

	/**
	 * @param string[] $excluded Paths or patterns.
	 * @return string[]
	 */
	public function exclude_css_paths( $excluded ) {
		if ( ! is_array( $excluded ) ) {
			$excluded = array();
		}
		return array_values( array_unique( array_merge( $excluded, $this->css_path_patterns() ) ) );
	}

	/**
	 * @param bool   $do     Whether to minify.
	 * @param string $tag    Tag HTML.
	 * @param string $file   File URL or path.
	 * @return bool
	 */
	public function w3tc_skip_js( $do, $tag, $file ) {
		unset( $tag );
		if ( is_string( $file ) && false !== strpos( $file, '/plugins/foliora/' ) ) {
			return false;
		}
		return $do;
	}

	/**
	 * @param bool   $do   Whether to minify.
	 * @param string $tag  Tag HTML.
	 * @param string $file File URL or path.
	 * @return bool
	 */
	public function w3tc_skip_css( $do, $tag, $file ) {
		unset( $tag );
		if ( is_string( $file ) && false !== strpos( $file, '/plugins/foliora/assets/css/' ) ) {
			return false;
		}
		return $do;
	}

	/**
	 * @param string $exclude Comma-separated filenames.
	 * @return string
	 */
	public function autoptimize_js_exclude( $exclude ) {
		$exclude = is_string( $exclude ) ? $exclude : '';
		$extra   = 'foliora-viewer.js, foliora-library.js, pdf.min.js, pdf.worker.min.js';
		return $exclude ? ( $exclude . ', ' . $extra ) : $extra;
	}

	/**
	 * @param string $exclude Comma-separated filenames.
	 * @return string
	 */
	public function autoptimize_css_exclude( $exclude ) {
		$exclude = is_string( $exclude ) ? $exclude : '';
		$extra   = 'foliora-viewer.css, foliora-library.css';
		return $exclude ? ( $exclude . ', ' . $extra ) : $extra;
	}

	/**
	 * @param string[] $handles Script handles.
	 * @return string[]
	 */
	public function sg_exclude_handles( $handles ) {
		if ( ! is_array( $handles ) ) {
			$handles = array();
		}
		return array_values( array_unique( array_merge( $handles, $this->js_handles() ) ) );
	}

	/**
	 * @param string[] $handles Style handles.
	 * @return string[]
	 */
	public function sg_exclude_css_handles( $handles ) {
		if ( ! is_array( $handles ) ) {
			$handles = array();
		}
		return array_values( array_unique( array_merge( $handles, array( 'foliora-viewer', 'foliora-library' ) ) ) );
	}

	/**
	 * @return string[]
	 */
	private function js_handles() {
		return array(
			'foliora-pdfjs',
			'foliora-viewer',
			'foliora-library',
			'foliora-thumbnail-gen',
		);
	}

	/**
	 * @return string[]
	 */
	private function js_path_patterns() {
		return array(
			'wp-content/plugins/foliora/assets/js/foliora-viewer.js',
			'wp-content/plugins/foliora/assets/js/foliora-library.js',
			'wp-content/plugins/foliora/assets/vendor/pdfjs/pdf.min.js',
			'wp-content/plugins/foliora/assets/vendor/pdfjs/pdf.worker.min.js',
			'(.*)foliora/assets/js/(.*)',
			'(.*)foliora/assets/vendor/pdfjs/(.*)',
		);
	}

	/**
	 * @return string[]
	 */
	private function css_path_patterns() {
		return array(
			'wp-content/plugins/foliora/assets/css/foliora-viewer.css',
			'wp-content/plugins/foliora/assets/css/foliora-library.css',
			'(.*)foliora/assets/css/(.*)',
		);
	}
}
