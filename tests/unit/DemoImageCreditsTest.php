<?php
/**
 * Every bundled demo photo has a recorded creator, source and a free licence.
 *
 * The seeder ships these to every customer and imports them into their sites, so a
 * photo without a licence record, or under a non-commercial licence, is a legal
 * problem we sell.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use PHPUnit\Framework\TestCase;

class DemoImageCreditsTest extends TestCase {

	private string $dir;

	protected function setUp(): void {
		$this->dir = dirname( __DIR__, 2 ) . '/assets/demo-images/';
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function credits(): array {
		$decoded = json_decode( (string) file_get_contents( $this->dir . 'CREDITS.json' ), true );
		$this->assertIsArray( $decoded, 'CREDITS.json must be valid JSON.' );

		return $decoded;
	}

	public function test_every_photo_has_a_credit_and_every_credit_has_a_photo(): void {
		$files   = array_map( 'basename', glob( $this->dir . '*.jpg' ) ?: array() );
		$entries = array_column( $this->credits(), 'file' );
		sort( $files );
		sort( $entries );

		$this->assertSame( $files, $entries );
	}

	public function test_every_credit_names_a_creator_a_source_page_and_a_free_licence(): void {
		foreach ( $this->credits() as $credit ) {
			$name = (string) ( $credit['file'] ?? '?' );
			$this->assertNotSame( '', trim( (string) ( $credit['creator'] ?? '' ) ), "$name has no creator." );
			$this->assertMatchesRegularExpression( '#^https://#', (string) ( $credit['source'] ?? '' ), "$name has no source page." );
			$this->assertMatchesRegularExpression( '/^CC0|public domain/i', (string) ( $credit['license'] ?? '' ), "$name is not CC0 or public domain." );
		}
	}

	public function test_photos_stay_small_enough_for_a_plugin_zip(): void {
		$total = 0;
		foreach ( glob( $this->dir . '*.jpg' ) ?: array() as $file ) {
			$size   = (int) filesize( $file );
			$total += $size;
			$this->assertLessThanOrEqual( 300 * 1024, $size, basename( $file ) . ' is over 300 KB: re-encode it.' );
			$dims = getimagesize( $file );
			$this->assertLessThanOrEqual( 1280, max( (int) $dims[0], (int) $dims[1] ), basename( $file ) . ' is larger than 1280 px.' );
		}
		$this->assertLessThanOrEqual( 4 * 1024 * 1024, $total, 'The demo set must stay under 4 MB.' );
	}
}
