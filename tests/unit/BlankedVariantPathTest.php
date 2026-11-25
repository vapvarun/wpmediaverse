<?php
/**
 * A variant left on local disk by a failed cloud upload is still listed as one of the media's files.
 *
 * MediaVariantWriter::record() blanks the variant's path record in that case, and every place that lists a
 * media's files read only non-empty path records: delete never queued the file, and a move to the cloud skipped it.
 *
 * @package WPMediaVerse\Tests\Unit
 */

declare( strict_types=1 );

namespace WPMediaVerse\Tests\Unit;

use WPMediaVerse\Core\Plugin;
use WPMediaVerse\Services\CloudOps;

/**
 * Blank variant path records.
 */
class BlankedVariantPathTest extends \WP_UnitTestCase {

	/**
	 * Upload a real image and return its id.
	 *
	 * @return int
	 */
	private function media(): int {
		$img = imagecreatetruecolor( 1600, 1200 );
		imagefill( $img, 0, 0, imagecolorallocate( $img, wp_rand( 0, 255 ), 90, 30 ) );
		$file = wp_tempnam( 'mvs-variant' ) . '.jpg';
		imagejpeg( $img, $file, 90 );

		$owner = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $owner );
		$id = Plugin::container()->get( 'upload' )->handle(
			array(
				'tmp_name' => $file,
				'name'     => 'holiday.jpg',
				'size'     => filesize( $file ),
				'type'     => 'image/jpeg',
			),
			$owner,
			array( 'privacy' => 'public' )
		);
		$this->assertIsInt( $id );
		return $id;
	}

	/**
	 * A variant whose path record was blanked, with its URL kept, still lists its file.
	 */
	public function test_a_variant_with_a_blank_path_record_is_still_listed(): void {
		$repo     = Plugin::container()->get( 'media_repository' );
		$media_id = $this->media();

		$paths = $repo->get_variant_paths( $media_id );
		$this->assertNotEmpty( $paths, 'The upload recorded variant paths.' );
		$meta_key = (string) array_key_first( $paths );
		$rel_path = $paths[ $meta_key ];

		// What record() leaves behind after a failed cloud upload: the local URL, and a blank path.
		$url_key = (string) preg_replace( '/_path$/', '', $meta_key );
		$this->assertStringContainsString( '/wpmediaverse/', (string) $repo->get_raw( $media_id, $url_key ), 'The variant keeps its local URL.' );
		$repo->set( $media_id, $meta_key, '' );

		$this->assertSame( $rel_path, $repo->get_variant_paths( $media_id )[ $meta_key ] ?? null, 'The path is read back from the recorded URL.' );
		$this->assertContains( $rel_path, $repo->get_stored_file_paths( $media_id ), 'Delete and the storage clean-up list the file.' );
	}

	/**
	 * A blank path with no recorded URL is nothing to list, and a recorded path is never replaced by the URL's.
	 */
	public function test_a_variant_with_nothing_recorded_is_left_out_and_a_recorded_path_wins(): void {
		$repo     = Plugin::container()->get( 'media_repository' );
		$media_id = $this->media();

		$paths    = $repo->get_variant_paths( $media_id );
		$meta_key = (string) array_key_first( $paths );
		$url_key  = (string) preg_replace( '/_path$/', '', $meta_key );

		$repo->set( $media_id, $meta_key, 'custom/kept.jpg' );
		$this->assertSame( 'custom/kept.jpg', $repo->get_variant_paths( $media_id )[ $meta_key ], 'A recorded path wins.' );

		$repo->set( $media_id, $meta_key, '' );
		$repo->set( $media_id, $url_key, '' );
		$this->assertArrayNotHasKey( $meta_key, $repo->get_variant_paths( $media_id ), 'No path and no URL: nothing to list.' );
	}

	/**
	 * After a move to the cloud, the variant takes the destination's URL.
	 */
	public function test_a_moved_variant_with_a_blank_path_record_takes_the_new_url(): void {
		$repo     = Plugin::container()->get( 'media_repository' );
		$media_id = $this->media();

		$paths    = $repo->get_variant_paths( $media_id );
		$meta_key = (string) array_key_first( $paths );
		$url_key  = (string) preg_replace( '/_path$/', '', $meta_key );
		$repo->set( $media_id, $meta_key, '' );

		$destination = new class() {
			/**
			 * Destination URL for a relative path.
			 *
			 * @param string $rel Relative path.
			 * @return string
			 */
			public function url( string $rel ): string {
				return 'https://cdn.example.test/wpmediaverse/' . ltrim( $rel, '/' );
			}
		};
		$method      = new \ReflectionMethod( CloudOps::class, 'refresh_variant_urls' );
		$method->setAccessible( true );
		$method->invoke( null, $media_id, $destination );

		$this->assertSame( 'https://cdn.example.test/wpmediaverse/' . $paths[ $meta_key ], $repo->get_raw( $media_id, $url_key ) );
	}
}
