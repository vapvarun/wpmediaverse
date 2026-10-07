<?php
/**
 * A custom storage driver can be picked and survives a save of the Storage tab.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Admin\Settings\Sanitizers;
use WPMediaVerse\Admin\Settings\SettingsRegistrar;

/**
 * Basecamp 10374850600: the select and the sanitizer each had their own fixed
 * list, so a driver registered through `mvs_storage_driver` was dropped back to
 * local the next time an owner saved the Storage tab.
 */
class StorageDriverChoicesTest extends WP_UnitTestCase {

	public function tear_down(): void {
		remove_all_filters( 'mvs_storage_driver_choices' );
		delete_option( 'mvs_storage_driver' );
		parent::tear_down();
	}

	public function test_the_built_in_drivers_are_accepted(): void {
		foreach ( array( 'local', 's3', 'bunnycdn', 'r2', 'dospaces' ) as $slug ) {
			$this->assertSame( $slug, Sanitizers::sanitize_storage_driver( $slug ) );
		}
		$this->assertSame( 'local', Sanitizers::sanitize_storage_driver( 'nonsense' ) );
	}

	public function test_a_listed_custom_driver_survives_a_save(): void {
		$this->assertSame( 'local', Sanitizers::sanitize_storage_driver( 'my_host' ), 'not listed yet' );

		add_filter(
			'mvs_storage_driver_choices',
			static function ( array $choices ): array {
				$choices['my_host'] = 'My host';
				return $choices;
			}
		);

		$this->assertArrayHasKey( 'my_host', SettingsRegistrar::storage_driver_choices() );
		$this->assertSame( 'my_host', Sanitizers::sanitize_storage_driver( 'my_host' ) );
		$this->assertContains( 'my_host', Sanitizers::get_whitelist( 'mvs_storage_driver' ), 'the select and the save read one list' );
	}

	public function test_a_save_that_does_not_post_the_field_keeps_the_stored_driver(): void {
		update_option( 'mvs_storage_driver', 's3' );

		$this->assertSame( 's3', Sanitizers::sanitize_storage_driver( null ), 'the locked field posts nothing' );
	}
}
