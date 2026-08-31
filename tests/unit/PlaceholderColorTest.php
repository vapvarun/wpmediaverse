<?php
/**
 * Regression guard for the media lazy-load placeholder colour.
 *
 * Basecamp 9667082287: the placeholder was the MEAN colour (a 1px downscale),
 * so a two-tone image — half rgb(200,40,40), half rgb(40,60,200) — resolved to
 * a purple (#793278) that appears nowhere in it. It is now the DOMINANT colour:
 * the most-populated quantised bucket's own mean, a colour the image actually
 * contains. These pin that the two-tone case returns one of its real colours
 * (never the misleading average) and that an undecodable file yields ''.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use ReflectionMethod;
use WPMediaVerse\Services\UploadService;

/**
 * @coversDefaultClass \WPMediaVerse\Services\UploadService
 */
class PlaceholderColorTest extends WP_UnitTestCase {

	/**
	 * Write a two-tone PNG (left half red, right half blue) to a temp file.
	 *
	 * @return string Absolute path to the PNG.
	 */
	private function two_tone_png(): string {
		$w    = 64;
		$h    = 64;
		$img  = imagecreatetruecolor( $w, $h );
		$red  = imagecolorallocate( $img, 200, 40, 40 );
		$blue = imagecolorallocate( $img, 40, 60, 200 );
		imagefilledrectangle( $img, 0, 0, (int) ( $w / 2 ) - 1, $h - 1, $red );
		imagefilledrectangle( $img, (int) ( $w / 2 ), 0, $w - 1, $h - 1, $blue );
		$path = tempnam( sys_get_temp_dir(), 'mvs-phc' ) . '.png';
		imagepng( $img, $path );
		return $path;
	}

	/**
	 * Invoke a private static UploadService method by reflection.
	 *
	 * @param string $method Method name.
	 * @param string $path   Image path argument.
	 * @return string
	 */
	private function invoke( string $method, string $path ): string {
		$ref = new ReflectionMethod( UploadService::class, $method );
		$ref->setAccessible( true );
		return (string) $ref->invoke( null, $path );
	}

	/**
	 * The two-tone image must resolve to a colour it actually contains
	 * (red- or blue-dominant), not the mean purple the old code produced.
	 */
	public function test_two_tone_image_returns_a_dominant_colour_not_the_mean(): void {
		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			$this->markTestSkipped( 'GD is not available in this environment.' );
		}

		$path  = $this->two_tone_png();
		$color = $this->invoke( 'placeholder_color', $path );
		wp_delete_file( $path );

		$this->assertMatchesRegularExpression( '/^#[0-9a-f]{6}$/', $color, 'Placeholder must be a hex colour.' );
		$this->assertNotSame( '#793278', $color, 'Placeholder must not be the misleading mean colour.' );

		$parts   = sscanf( $color, '#%02x%02x%02x' );
		$r       = (int) $parts[0];
		$g       = (int) $parts[1];
		$b       = (int) $parts[2];
		$reddish = $r > $g + 40 && $r > $b + 40;
		$bluish  = $b > $r + 40 && $b > $g + 40;
		$this->assertTrue( $reddish || $bluish, "Expected a red- or blue-dominant colour, got {$color}." );
	}

	/**
	 * A file that is not a decodable image yields '' — the placeholder is a
	 * nicety, never a hard failure.
	 */
	public function test_undecodable_file_returns_empty_string(): void {
		$path = tempnam( sys_get_temp_dir(), 'mvs-phc' );
		file_put_contents( $path, 'this is not an image' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture on a temp path.
		$color = $this->invoke( 'placeholder_color', $path );
		wp_delete_file( $path );

		$this->assertSame( '', $color );
	}
}
