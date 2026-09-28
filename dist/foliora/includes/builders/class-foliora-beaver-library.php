<?php
/**
 * Beaver Builder module: Foliora Library.
 *
 * @package Foliora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Foliora_Beaver_Library extends FLBuilderModule {

	public function __construct() {
		parent::__construct(
			array(
				'name'            => __( 'Foliora Library', 'foliora' ),
				'description'     => __( 'A grid of Media Library PDFs.', 'foliora' ),
				'group'           => __( 'Foliora', 'foliora' ),
				'category'        => __( 'Media', 'foliora' ),
				'dir'             => FOLIORA_DIR . 'includes/builders/beaver/library/',
				'url'             => FOLIORA_URL . 'includes/builders/beaver/library/',
				'editor_export'   => true,
				'enabled'         => true,
				'partial_refresh' => true,
			)
		);
	}
}
