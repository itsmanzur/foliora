<?php
/**
 * Foliora_Block
 *
 * Registers a dynamic Gutenberg block that wraps the [foliora] shortcode,
 * so editors get a Media Library picker, height slider, and a first-page
 * PDF preview instead of typing shortcode attributes by hand.
 *
 * Rendering stays server-side via Foliora_Viewer so block and shortcode
 * output cannot drift apart.
 *
 * @package Foliora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Foliora_Block {

	public function init() {
		add_action( 'init', array( $this, 'register_block' ) );
	}

	public function register_block() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		wp_register_script(
			'foliora-pdfjs',
			FOLIORA_URL . 'assets/vendor/pdfjs/pdf.min.js',
			array(),
			FOLIORA_VERSION,
			true
		);

		wp_register_script(
			'foliora-block-editor',
			FOLIORA_URL . 'assets/js/foliora-block.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'foliora-pdfjs' ),
			FOLIORA_VERSION,
			true
		);

		wp_register_style(
			'foliora-block-editor',
			FOLIORA_URL . 'assets/css/foliora-block-editor.css',
			array(),
			FOLIORA_VERSION
		);

		wp_localize_script(
			'foliora-block-editor',
			'FolioraBlock',
			array(
				'workerSrc' => FOLIORA_URL . 'assets/vendor/pdfjs/pdf.worker.min.js',
			)
		);

		wp_register_style(
			'foliora-library',
			FOLIORA_URL . 'assets/css/foliora-library.css',
			array(),
			FOLIORA_VERSION
		);

		wp_register_script(
			'foliora-library-block-editor',
			FOLIORA_URL . 'assets/js/foliora-library-block.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-server-side-render' ),
			FOLIORA_VERSION,
			true
		);

		register_block_type(
			FOLIORA_DIR . 'blocks/viewer',
			array(
				'render_callback' => array( $this, 'render_block' ),
			)
		);

		register_block_type(
			FOLIORA_DIR . 'blocks/library',
			array(
				'render_callback' => array( $this, 'render_library_block' ),
			)
		);
	}

	/**
	 * Delegates to the same shortcode renderer used by [foliora].
	 *
	 * @param array    $attributes Block attributes.
	 * @param string   $content    Block inner content (unused).
	 * @param WP_Block $block      Block instance when available.
	 * @return string
	 */
	public function render_block( $attributes, $content = '', $block = null ) {
		$html = Foliora::instance()->viewer->render_shortcode( $attributes );
		if ( function_exists( 'get_block_wrapper_attributes' ) ) {
			return '<div ' . get_block_wrapper_attributes( array( 'class' => 'foliora-block' ) ) . '>' . $html . '</div>';
		}
		return $html;
	}

	/**
	 * Delegates to the same shortcode renderer used by [foliora_library].
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public function render_library_block( $attributes, $content = '', $block = null ) {
		$html = Foliora::instance()->library->render_shortcode( $attributes );
		if ( function_exists( 'get_block_wrapper_attributes' ) ) {
			return '<div ' . get_block_wrapper_attributes( array( 'class' => 'foliora-library-block' ) ) . '>' . $html . '</div>';
		}
		return $html;
	}
}
