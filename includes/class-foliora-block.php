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

		$this->register_block_patterns();
	}

	/**
	 * Register curated block patterns for document layouts.
	 */
	public function register_block_patterns() {
		if ( ! function_exists( 'register_block_pattern_category' ) || ! function_exists( 'register_block_pattern' ) ) {
			return;
		}

		register_block_pattern_category(
			'foliora',
			array(
				'label' => __( 'Foliora Documents', 'foliora' ),
			)
		);

		register_block_pattern(
			'foliora/lead-magnet',
			array(
				'title'       => __( 'Whitepaper / Lead Magnet', 'foliora' ),
				'description' => __( 'Two-column document showcase with title, summary, and PDF viewer.', 'foliora' ),
				'categories'  => array( 'foliora', 'featured' ),
				'content'     => '<!-- wp:columns {"verticalAlignment":"center"} -->
<div class="wp-block-columns are-vertically-aligned-center"><!-- wp:column {"verticalAlignment":"center","width":"40%"} -->
<div class="wp-block-column is-vertically-aligned-center" style="flex-basis:40%"><!-- wp:heading {"level":2} -->
<h2 class="wp-block-heading">' . esc_html__( 'Free Research Report', 'foliora' ) . '</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>' . esc_html__( 'Explore key insights, comprehensive findings, and actionable recommendations in our latest publication.', 'foliora' ) . '</p>
<!-- /wp:paragraph -->

<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button {"className":"is-style-fill"} -->
<div class="wp-block-button is-style-fill"><a class="wp-block-button__link wp-element-button">' . esc_html__( 'Download PDF', 'foliora' ) . '</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons --></div>
<!-- /wp:column -->

<!-- wp:column {"verticalAlignment":"center","width":"60%"} -->
<div class="wp-block-column is-vertically-aligned-center" style="flex-basis:60%"><!-- wp:foliora/viewer {"height":"480px"} /--></div>
<!-- /wp:column --></div>
<!-- /wp:columns -->',
			)
		);

		register_block_pattern(
			'foliora/research-paper',
			array(
				'title'       => __( 'Academic / Research Document', 'foliora' ),
				'description' => __( 'Formal document layout with metadata, abstract callout, and full-width PDF viewer.', 'foliora' ),
				'categories'  => array( 'foliora' ),
				'content'     => '<!-- wp:group {"layout":{"type":"constrained"}} -->
<div class="wp-block-group"><!-- wp:heading {"textAlign":"center","level":1} -->
<h1 class="wp-block-heading has-text-align-center">' . esc_html__( 'Document Title & Research Paper', 'foliora' ) . '</h1>
<!-- /wp:heading -->

<!-- wp:paragraph {"align":"center","style":{"typography":{"fontStyle":"italic"}}} -->
<p class="has-text-align-center" style="font-style:italic">' . esc_html__( 'Published by Author / Institution — Last Updated: 2026', 'foliora' ) . '</p>
<!-- /wp:paragraph -->

<!-- wp:quote {"className":"is-style-plain"} -->
<blockquote class="wp-block-quote is-style-plain"><p><strong>' . esc_html__( 'Abstract:', 'foliora' ) . '</strong> ' . esc_html__( 'This publication examines core methodologies, experimental data, and concluding outcomes.', 'foliora' ) . '</p></blockquote>
<!-- /wp:quote -->

<!-- wp:foliora/viewer {"width":"100%","height":"650px"} /--></div>
<!-- /wp:group -->',
			)
		);

		register_block_pattern(
			'foliora/annual-report',
			array(
				'title'       => __( 'Annual Report / Catalog Hero', 'foliora' ),
				'description' => __( 'Wide annual report showcase with key metrics and wide-format PDF reader.', 'foliora' ),
				'categories'  => array( 'foliora' ),
				'content'     => '<!-- wp:group {"align":"wide","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignwide"><!-- wp:heading {"textAlign":"center"} -->
<h2 class="wp-block-heading has-text-align-center">' . esc_html__( 'Annual Report & Highlights', 'foliora' ) . '</h2>
<!-- /wp:heading -->

<!-- wp:paragraph {"align":"center"} -->
<p class="has-text-align-center">' . esc_html__( 'Browse through our full annual review, financial statistics, and milestones.', 'foliora' ) . '</p>
<!-- /wp:paragraph -->

<!-- wp:foliora/viewer {"width":"100%","height":"600px"} /--></div>
<!-- /wp:group -->',
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
		unset( $content, $block ); // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
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
		unset( $content, $block ); // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$html = Foliora::instance()->library->render_shortcode( $attributes );
		if ( function_exists( 'get_block_wrapper_attributes' ) ) {
			return '<div ' . get_block_wrapper_attributes( array( 'class' => 'foliora-library-block' ) ) . '>' . $html . '</div>';
		}
		return $html;
	}
}
