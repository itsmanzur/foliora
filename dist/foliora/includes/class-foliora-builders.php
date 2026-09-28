<?php
/**
 * Foliora_Builders
 *
 * Registers native Elementor, Divi, and Beaver Builder widgets only when
 * that builder is active. Rendering always delegates to Foliora_Viewer /
 * Foliora_Library so shortcode, block, and builder output stay aligned.
 *
 * @package Foliora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Foliora_Builders {

	public function init() {
		add_action( 'elementor/widgets/register', array( $this, 'register_elementor_widgets' ) );
		add_action( 'elementor/widgets/widgets_registered', array( $this, 'register_elementor_widgets_legacy' ) );
		add_action( 'elementor/elements/categories_registered', array( $this, 'register_elementor_category' ) );
		add_action( 'et_builder_ready', array( $this, 'register_divi_modules' ) );
		add_action( 'init', array( $this, 'register_beaver_modules' ), 11 );
	}

	/**
	 * @param \Elementor\Elements_Manager $elements_manager Manager.
	 */
	public function register_elementor_category( $elements_manager ) {
		if ( ! is_object( $elements_manager ) || ! method_exists( $elements_manager, 'add_category' ) ) {
			return;
		}
		$elements_manager->add_category(
			'foliora',
			array(
				'title' => __( 'Foliora', 'foliora' ),
				'icon'  => 'eicon-document-file',
			)
		);
	}

	/**
	 * Elementor < 3.5 used widgets_registered. 3.5+ also fires that
	 * hook as a deprecated alias, so skip when the new hook already ran.
	 *
	 * @param \Elementor\Widgets_Manager $widgets_manager Manager.
	 */
	public function register_elementor_widgets_legacy( $widgets_manager ) {
		if ( defined( 'ELEMENTOR_VERSION' ) && version_compare( ELEMENTOR_VERSION, '3.5.0', '>=' ) ) {
			return;
		}
		$this->register_elementor_widgets( $widgets_manager );
	}

	/**
	 * @param \Elementor\Widgets_Manager $widgets_manager Manager.
	 */
	public function register_elementor_widgets( $widgets_manager ) {
		if ( ! class_exists( '\Elementor\Widget_Base' ) ) {
			return;
		}

		require_once FOLIORA_DIR . 'includes/builders/class-foliora-elementor-viewer.php';
		require_once FOLIORA_DIR . 'includes/builders/class-foliora-elementor-library.php';

		if ( method_exists( $widgets_manager, 'register' ) ) {
			$widgets_manager->register( new Foliora_Elementor_Viewer() );
			$widgets_manager->register( new Foliora_Elementor_Library() );
			return;
		}

		if ( method_exists( $widgets_manager, 'register_widget_type' ) ) {
			$widgets_manager->register_widget_type( new Foliora_Elementor_Viewer() );
			$widgets_manager->register_widget_type( new Foliora_Elementor_Library() );
		}
	}

	public function register_divi_modules() {
		if ( ! class_exists( 'ET_Builder_Module' ) ) {
			return;
		}
		require_once FOLIORA_DIR . 'includes/builders/class-foliora-divi-viewer.php';
		require_once FOLIORA_DIR . 'includes/builders/class-foliora-divi-library.php';
		new Foliora_Divi_Viewer();
		new Foliora_Divi_Library();
	}

	public function register_beaver_modules() {
		if ( ! class_exists( 'FLBuilder' ) ) {
			return;
		}

		require_once FOLIORA_DIR . 'includes/builders/class-foliora-beaver-viewer.php';
		require_once FOLIORA_DIR . 'includes/builders/class-foliora-beaver-library.php';

		FLBuilder::register_module(
			'Foliora_Beaver_Viewer',
			array(
				'general' => array(
					'title'    => __( 'PDF', 'foliora' ),
					'sections' => array(
						'file'   => array(
							'title'  => __( 'Document', 'foliora' ),
							'fields' => array(
								'file'  => array(
									'type'        => 'text',
									'label'       => __( 'PDF URL', 'foliora' ),
									'connections' => array( 'url', 'string' ),
									'help'        => __( 'Paste a Media Library PDF URL, or copy one from Foliora → Documents.', 'foliora' ),
								),
								'title' => array(
									'type'    => 'text',
									'label'   => __( 'Title caption', 'foliora' ),
									'default' => '',
								),
								'page'  => array(
									'type'    => 'unit',
									'label'   => __( 'Start page', 'foliora' ),
									'default' => '1',
									'slider'  => array(
										'min'  => 1,
										'max'  => 50,
										'step' => 1,
									),
								),
							),
						),
						'layout' => array(
							'title'  => __( 'Layout', 'foliora' ),
							'fields' => array(
								'width'    => array(
									'type'    => 'text',
									'label'   => __( 'Width', 'foliora' ),
									'default' => '100%',
								),
								'height'   => array(
									'type'    => 'text',
									'label'   => __( 'Height', 'foliora' ),
									'default' => '600px',
								),
								'download' => array(
									'type'    => 'select',
									'label'   => __( 'Download button', 'foliora' ),
									'default' => 'true',
									'options' => array(
										'true'  => __( 'Show', 'foliora' ),
										'false' => __( 'Hide', 'foliora' ),
									),
								),
								'print'    => array(
									'type'    => 'select',
									'label'   => __( 'Print button', 'foliora' ),
									'default' => 'true',
									'options' => array(
										'true'  => __( 'Show', 'foliora' ),
										'false' => __( 'Hide', 'foliora' ),
									),
								),
							),
						),
					),
				),
			)
		);

		FLBuilder::register_module(
			'Foliora_Beaver_Library',
			array(
				'general' => array(
					'title'    => __( 'Library', 'foliora' ),
					'sections' => array(
						'grid' => array(
							'title'  => __( 'Grid', 'foliora' ),
							'fields' => array(
								'columns'  => array(
									'type'    => 'select',
									'label'   => __( 'Columns', 'foliora' ),
									'default' => '3',
									'options' => array(
										'1' => '1',
										'2' => '2',
										'3' => '3',
										'4' => '4',
										'5' => '5',
										'6' => '6',
									),
								),
								'per_page' => array(
									'type'    => 'unit',
									'label'   => __( 'Per page', 'foliora' ),
									'default' => '12',
								),
								'orderby'  => array(
									'type'    => 'select',
									'label'   => __( 'Order by', 'foliora' ),
									'default' => 'date',
									'options' => array(
										'date'     => __( 'Date', 'foliora' ),
										'title'    => __( 'Title', 'foliora' ),
										'modified' => __( 'Modified', 'foliora' ),
									),
								),
								'order'    => array(
									'type'    => 'select',
									'label'   => __( 'Order', 'foliora' ),
									'default' => 'DESC',
									'options' => array(
										'DESC' => __( 'Newest first', 'foliora' ),
										'ASC'  => __( 'Oldest first', 'foliora' ),
									),
								),
								'search'   => array(
									'type'    => 'select',
									'label'   => __( 'Search box', 'foliora' ),
									'default' => 'true',
									'options' => array(
										'true'  => __( 'Show', 'foliora' ),
										'false' => __( 'Hide', 'foliora' ),
									),
								),
							),
						),
					),
				),
			)
		);
	}
}
