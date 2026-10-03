<?php
/**
 * Foliora
 *
 * Thin singleton that boots the free plugin's pieces. Deliberately does
 * almost nothing itself — it exists so `foliora_run()` has one obvious
 * place to call, and so Foliora Pro (or a future admin dashboard widget,
 * WP-CLI command, etc.) has a stable `Foliora::instance()` to hook into.
 *
 * @package Foliora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Foliora {

	/**
	 * @var Foliora|null
	 */
	private static $instance = null;

	/**
	 * @var Foliora_Viewer
	 */
	public $viewer;

	/**
	 * @var Foliora_Thumbnails
	 */
	public $thumbnails;

	/**
	 * @var Foliora_SEO
	 */
	public $seo;

	/**
	 * @var Foliora_A11y
	 */
	public $a11y;

	/**
	 * @var Foliora_Compat
	 */
	public $compat;

	/**
	 * @var Foliora_Builders
	 */
	public $builders;

	/**
	 * @var Foliora_Library
	 */
	public $library;

	/**
	 * @var Foliora_Embeds
	 */
	public $embeds;

	/**
	 * @var Foliora_Block
	 */
	public $block;

	/**
	 * @var Foliora_Admin
	 */
	public $admin;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->viewer     = new Foliora_Viewer();
		$this->thumbnails = new Foliora_Thumbnails();
		$this->seo        = new Foliora_SEO();
		$this->a11y       = new Foliora_A11y();
		$this->compat     = new Foliora_Compat();
		$this->builders   = new Foliora_Builders();
		$this->library    = new Foliora_Library();
		$this->embeds     = new Foliora_Embeds();
		$this->block      = new Foliora_Block();
		$this->admin      = new Foliora_Admin();
	}

	/**
	 * Wire everything up. Runs on `plugins_loaded` (see foliora.php) so
	 * that by this point Foliora Pro — if installed — has already fired
	 * `foliora/pro_loaded` and Foliora_Loader::is_pro_active() is
	 * reliable everywhere below.
	 */
	public function run() {
		$this->embeds->init();
		$this->viewer->init();
		$this->thumbnails->init();
		$this->seo->init();
		$this->a11y->init();
		$this->compat->init();
		$this->builders->init();
		$this->library->init();
		$this->block->init();

		if ( is_admin() ) {
			$this->admin->init();
		}

		/**
		 * Action: foliora/loaded
		 *
		 * Fires once the free plugin has fully booted. Foliora Pro can
		 * use this instead of its own plugins_loaded if it needs
		 * Foliora's classes to already exist.
		 */
		do_action( 'foliora/loaded' );
	}
}
