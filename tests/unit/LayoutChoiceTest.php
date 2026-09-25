<?php
/**
 * The one "Layout" setting on Settings > Display (2.6.0).
 *
 * The mvs_layout_choice option is only a transport: a Free layout lands in
 * mvs_thumbnail_style, the option every grid reads, and mvs_layout_saved
 * tells Pro. An unknown value changes nothing.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Admin\Settings\Sanitizers;
use WPMediaVerse\Core\SettingsHelper;

class LayoutChoiceTest extends WP_UnitTestCase {

	public function tear_down(): void {
		delete_option( 'mvs_thumbnail_style' );
		parent::tear_down();
	}

	public function test_choices_are_the_free_layouts_and_the_whitelist(): void {
		$this->assertSame( array( 'square', 'original', 'list' ), array_keys( SettingsHelper::layout_choices() ) );
		$this->assertSame( array_keys( SettingsHelper::layout_choices() ), Sanitizers::get_whitelist( 'mvs_layout_choice' ) );
	}

	public function test_a_free_layout_is_written_where_grids_read_it(): void {
		$saved = array();
		$spy   = static function ( $value ) use ( &$saved ) {
			$saved[] = $value;
		};
		add_action( 'mvs_layout_saved', $spy );

		$this->assertSame( 'square', Sanitizers::sanitize_layout_choice( 'square' ) );
		$this->assertSame( 'square', SettingsHelper::get_thumbnail_style() );
		$this->assertSame( 'square', SettingsHelper::selected_layout() );
		$this->assertSame( array( 'square' ), $saved );

		remove_action( 'mvs_layout_saved', $spy );
	}

	public function test_an_unknown_layout_changes_nothing(): void {
		update_option( 'mvs_thumbnail_style', 'list' );
		$fired = false;
		$spy   = static function () use ( &$fired ) {
			$fired = true;
		};
		add_action( 'mvs_layout_saved', $spy );

		$this->assertSame( 'list', Sanitizers::sanitize_layout_choice( 'carousel' ) );
		$this->assertSame( 'list', get_option( 'mvs_thumbnail_style' ) );
		$this->assertFalse( $fired, 'A rejected value was announced as saved.' );

		remove_action( 'mvs_layout_saved', $spy );
	}

	public function test_default_follows_the_escape_hatch_filter(): void {
		$square = static function () {
			return 'square';
		};
		add_filter( 'mvs_default_thumbnail_style', $square );
		$this->assertSame( 'square', SettingsHelper::default_thumbnail_style() );
		$this->assertSame( 'square', SettingsHelper::get_thumbnail_style() );
		remove_filter( 'mvs_default_thumbnail_style', $square );

		$bogus = static function () {
			return 'carousel';
		};
		add_filter( 'mvs_default_thumbnail_style', $bogus );
		$this->assertSame( 'original', SettingsHelper::default_thumbnail_style() );
		remove_filter( 'mvs_default_thumbnail_style', $bogus );
	}
}
