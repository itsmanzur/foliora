<?php
/**
 * Beaver Builder module: Foliora Viewer.
 *
 * @package Foliora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Foliora_Beaver_Viewer extends FLBuilderModule {

	public function __construct() {
		parent::__construct(
			array(
				'name'            => __( 'Foliora Viewer', 'foliora' ),
				'description'     => __( 'Embed a PDF with the Foliora viewer.', 'foliora' ),
				'group'           => __( 'Foliora', 'foliora' ),
				'category'        => __( 'Media', 'foliora' ),
				'dir'             => FOLIORA_DIR . 'includes/builders/beaver/viewer/',
				'url'             => FOLIORA_URL . 'includes/builders/beaver/viewer/',
				'editor_export'   => true,
				'enabled'         => true,
				'partial_refresh' => true,
			)
		);
	}
}
