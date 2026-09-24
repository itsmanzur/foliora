<?php
/**
 * Foliora_Loader tests.
 *
 * @package Foliora
 */

class Test_Foliora_Loader extends Foliora_TestCase {

	public function test_pro_is_inactive_by_default() {
		$this->assertFalse( Foliora_Loader::is_pro_active() );
	}

	public function test_is_pro_active_filter_can_force_true() {
		add_filter( 'foliora/is_pro_active', '__return_true' );
		$this->reset_loader_cache();
		$this->assertTrue( Foliora_Loader::is_pro_active() );
		remove_filter( 'foliora/is_pro_active', '__return_true' );
	}

	public function test_is_pro_active_result_is_cached() {
		add_filter( 'foliora/is_pro_active', '__return_true' );
		$this->reset_loader_cache();
		$this->assertTrue( Foliora_Loader::is_pro_active() );
		remove_filter( 'foliora/is_pro_active', '__return_true' );
		$this->assertTrue( Foliora_Loader::is_pro_active(), 'Cached result should survive removing the filter' );
	}

	public function test_feature_enabled_is_false_when_pro_inactive() {
		$this->assertFalse( Foliora_Loader::is_feature_enabled( 'epub_reader' ) );
		$this->assertFalse( Foliora_Loader::is_feature_enabled( 'remove_branding' ) );
	}

	public function test_feature_enabled_defaults_true_when_pro_forced() {
		add_filter( 'foliora/is_pro_active', '__return_true' );
		$this->reset_loader_cache();
		$this->assertTrue( Foliora_Loader::is_feature_enabled( 'epub_reader' ) );
		remove_filter( 'foliora/is_pro_active', '__return_true' );
	}

	public function test_feature_enabled_respects_per_key_filter() {
		add_filter( 'foliora/is_pro_active', '__return_true' );
		$this->reset_loader_cache();
		add_filter(
			'foliora/feature_enabled',
			static function ( $enabled, $key ) {
				return 'bookmarks' === $key;
			},
			10,
			2
		);
		$this->assertTrue( Foliora_Loader::is_feature_enabled( 'bookmarks' ) );
		$this->assertFalse( Foliora_Loader::is_feature_enabled( 'epub_reader' ) );
		remove_all_filters( 'foliora/feature_enabled' );
		remove_filter( 'foliora/is_pro_active', '__return_true' );
	}

	public function test_upgrade_url_default_and_filter() {
		$this->assertSame( 'https://thereadscope.com/foliora/pricing', Foliora_Loader::upgrade_url() );
		add_filter(
			'foliora/upgrade_url',
			static function () {
				return 'https://example.com/upgrade';
			}
		);
		$this->assertSame( 'https://example.com/upgrade', Foliora_Loader::upgrade_url() );
		remove_all_filters( 'foliora/upgrade_url' );
	}

	public function test_pro_features_contains_expected_keys() {
		$features = Foliora_Loader::pro_features();
		$this->assertArrayHasKey( 'epub_reader', $features );
		$this->assertArrayHasKey( 'bookmarks', $features );
		$this->assertArrayHasKey( 'remove_branding', $features );
		$this->assertNotEmpty( $features['epub_reader'] );
	}
}
