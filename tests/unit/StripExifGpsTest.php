<?php
/**
 * Stripping EXIF removes the location and nothing else.
 *
 * The regression this guards: taking the whole APP1 segment removed the
 * camera, lens and the copyright/credit fields along with the coordinates,
 * and skipping the segment entirely left the coordinates on the photo. Both
 * were shipped behaviours at different times.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\Plugin;

class StripExifGpsTest extends WP_UnitTestCase {

	/**
	 * Build a JPEG carrying GPS plus camera and copyright metadata.
	 *
	 * Written by hand rather than committed as a binary fixture so the
	 * structure under test is visible to whoever reads the failure.
	 *
	 * @return string Path to a temp JPEG.
	 */
	private function jpeg_with_gps_and_camera(): string {
		$u16 = static function ( $v ) {
			return pack( 'n', $v );
		};
		$u32 = static function ( $v ) {
			return pack( 'N', $v );
		};
		$entry = static function ( $tag, $type, $count, $value ) use ( $u16, $u32 ) {
			return $u16( $tag ) . $u16( $type ) . $u32( $count ) . $value;
		};

		$make      = "Canon\x00";
		$model     = "EOS R5\x00";
		$copyright = "(c) 2026 Test\x00";

		$gps_entries = array(
			$entry( 0x0000, 1, 4, "\x02\x02\x00\x00" ),
			$entry( 0x0001, 2, 2, "N\x00\x00\x00" ),
			$entry( 0x0002, 5, 3, $u32( 0 ) ),
			$entry( 0x0003, 2, 2, "E\x00\x00\x00" ),
			$entry( 0x0004, 5, 3, $u32( 0 ) ),
		);

		$lat = $u32( 51 ) . $u32( 1 ) . $u32( 30 ) . $u32( 1 ) . $u32( 0 ) . $u32( 1 );
		$lon = $u32( 0 ) . $u32( 1 ) . $u32( 7 ) . $u32( 1 ) . $u32( 0 ) . $u32( 1 );

		$ifd0_count = 4; // Make, Model, Copyright, GPS pointer.
		$gps_ifd_at = 8 + 2 + ( $ifd0_count * 12 ) + 4;
		$gps_size   = 2 + ( count( $gps_entries ) * 12 ) + 4;
		$strings_at = $gps_ifd_at + $gps_size;

		$make_at  = $strings_at;
		$model_at = $make_at + strlen( $make );
		$copy_at  = $model_at + strlen( $model );
		$lat_at   = $copy_at + strlen( $copyright );
		$lon_at   = $lat_at + strlen( $lat );

		$gps_entries[2] = $entry( 0x0002, 5, 3, $u32( $lat_at ) );
		$gps_entries[4] = $entry( 0x0004, 5, 3, $u32( $lon_at ) );

		$ifd0 = $u16( $ifd0_count )
			. $entry( 0x010F, 2, strlen( $make ), $u32( $make_at ) )
			. $entry( 0x0110, 2, strlen( $model ), $u32( $model_at ) )
			. $entry( 0x8298, 2, strlen( $copyright ), $u32( $copy_at ) )
			. $entry( 0x8825, 4, 1, $u32( $gps_ifd_at ) )
			. $u32( 0 );

		$gps_ifd = $u16( count( $gps_entries ) ) . implode( '', $gps_entries ) . $u32( 0 );
		$tiff    = "MM\x00\x2a" . $u32( 8 ) . $ifd0 . $gps_ifd . $make . $model . $copyright . $lat . $lon;
		$payload = "Exif\x00\x00" . $tiff;
		$app1    = "\xFF\xE1" . $u16( strlen( $payload ) + 2 ) . $payload;

		$image = imagecreatetruecolor( 40, 30 );
		imagefill( $image, 0, 0, imagecolorallocate( $image, 10, 120, 200 ) );
		ob_start();
		imagejpeg( $image, null, 90 );
		$jpeg = ob_get_clean();

		$path = wp_tempnam( 'mvs-gps-test' );
		file_put_contents( $path, substr( $jpeg, 0, 2 ) . $app1 . substr( $jpeg, 2 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		return $path;
	}

	/**
	 * The location goes; the photographer's metadata stays.
	 */
	public function test_strip_exif_removes_gps_and_keeps_camera_and_copyright(): void {
		if ( ! function_exists( 'exif_read_data' ) || ! function_exists( 'imagejpeg' ) ) {
			$this->markTestSkipped( 'Needs the exif and gd extensions.' );
		}

		$path = $this->jpeg_with_gps_and_camera();

		$before = exif_read_data( $path, 'ANY_TAG', true );
		$this->assertArrayHasKey( 'GPS', $before, 'fixture must start with GPS' );
		$this->assertSame( 'Canon', $before['IFD0']['Make'] );

		Plugin::container()->get( 'upload' )->strip_exif( $path );

		clearstatcache();
		$after = exif_read_data( $path, 'ANY_TAG', true );

		// A `false` here means the whole EXIF block was taken, which is the
		// over-broad behaviour this test exists to prevent - say so, rather
		// than letting the next assertion fail on a non-array.
		$this->assertIsArray( $after, 'the entire EXIF block was removed; only GPS should have been' );
		$this->assertArrayNotHasKey( 'GPS', $after, 'coordinates must be gone' );
		$this->assertSame( 'Canon', $after['IFD0']['Make'], 'camera make must survive' );
		$this->assertSame( 'EOS R5', $after['IFD0']['Model'], 'camera model must survive' );
		$this->assertSame( '(c) 2026 Test', $after['IFD0']['Copyright'], 'copyright must survive' );

		$this->assertNotFalse( getimagesize( $path ), 'file must still be a valid image' );

		unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink
	}
}
