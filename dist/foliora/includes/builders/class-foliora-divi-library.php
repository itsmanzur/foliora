<?php
/**
 * Divi module: Foliora Library.
 *
 * @package Foliora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Foliora_Divi_Library extends ET_Builder_Module {

	public $slug       = 'foliora_library';
	public $vb_support = 'partial';

	protected $module_credits = array(
		'module_uri' => 'https://thereadscope.com/foliora',
		'author'     => 'The Read Scope',
		'author_uri' => 'https://thereadscope.com',
	);

	public function init() {
		$this->name = __( 'Foliora Library', 'foliora' );
		$this->settings_modal_toggles = array(
			'general' => array(
				'toggles' => array(
					'main_content' => __( 'Grid', 'foliora' ),
				),
			),
		);
	}

	public function get_fields() {
		return array(
			'columns'  => array(
				'label'           => __( 'Columns', 'foliora' ),
				'type'            => 'select',
				'options'         => array(
					'1' => '1',
					'2' => '2',
					'3' => '3',
					'4' => '4',
					'5' => '5',
					'6' => '6',
				),
				'default'         => '3',
				'option_category' => 'layout',
				'toggle_slug'     => 'main_content',
			),
			'per_page' => array(
				'label'           => __( 'Per page', 'foliora' ),
				'type'            => 'text',
				'default'         => '12',
				'option_category' => 'layout',
				'toggle_slug'     => 'main_content',
			),
			'orderby'  => array(
				'label'           => __( 'Order by', 'foliora' ),
				'type'            => 'select',
				'options'         => array(
					'date'     => __( 'Date', 'foliora' ),
					'title'    => __( 'Title', 'foliora' ),
					'modified' => __( 'Modified', 'foliora' ),
				),
				'default'         => 'date',
				'option_category' => 'layout',
				'toggle_slug'     => 'main_content',
			),
			'order'    => array(
				'label'           => __( 'Order', 'foliora' ),
				'type'            => 'select',
				'options'         => array(
					'DESC' => __( 'Newest first', 'foliora' ),
					'ASC'  => __( 'Oldest first', 'foliora' ),
				),
				'default'         => 'DESC',
				'option_category' => 'layout',
				'toggle_slug'     => 'main_content',
			),
			'search'   => array(
				'label'           => __( 'Search box', 'foliora' ),
				'type'            => 'yes_no_button',
				'options'         => array(
					'on'  => __( 'Show', 'foliora' ),
					'off' => __( 'Hide', 'foliora' ),
				),
				'default'         => 'on',
				'option_category' => 'layout',
				'toggle_slug'     => 'main_content',
			),
		);
	}

	public function render( $attrs, $content, $render_slug ) {
		unset( $attrs, $content, $render_slug );

		return Foliora::instance()->library->render_shortcode(
			array(
				'columns'  => isset( $this->props['columns'] ) ? $this->props['columns'] : '3',
				'per_page' => isset( $this->props['per_page'] ) ? $this->props['per_page'] : '12',
				'orderby'  => isset( $this->props['orderby'] ) ? $this->props['orderby'] : 'date',
				'order'    => isset( $this->props['order'] ) ? $this->props['order'] : 'DESC',
				'search'   => ( isset( $this->props['search'] ) && 'off' === $this->props['search'] ) ? 'false' : 'true',
			)
		);
	}
}
