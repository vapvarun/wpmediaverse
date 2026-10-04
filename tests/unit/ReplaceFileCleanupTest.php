<?php
/**
 * Replace leaves nothing of the old version behind (card 10369185146).
 *
 * @package WPMediaVerse\Tests
 */

declare( strict_types=1 );

namespace WPMediaVerse\Tests\Unit;

use WPMediaVerse\Core\Plugin;

/**
 * Replace is how a member takes an image down. Since 2.6.1 a size URL is served
 * straight from disk, so every file the old version owned (original, sizes,
 * WebP/AVIF) must be gone after a replace, and the new version's files kept.
 */
class ReplaceFileCleanupTest extends \WP_UnitTestCase {

	/**
	 * A real JPEG in a temp file.
	 *
	 * @param int $w Width.
	 * @param int $h Height.
	 * @return string Path.
	 */
	private function jpeg( int $w, int $h ): string {
		$img = imagecreatetruecolor( $w, $h );
		imagefill( $img, 0, 0, imagecolorallocate( $img, wp_rand( 0, 255 ), 90, 30 ) );
		$path = wp_tempnam( 'mvs-replace' ) . '.jpg';
		imagejpeg( $img, $path, 90 );
		return $path;
	}

	/**
	 * Absolute path for a stored relative path.
	 *
	 * @param string $rel Relative path.
	 * @return string
	 */
	private function abs( string $rel ): string {
		return trailingslashit( wp_upload_dir()['basedir'] ) . 'wpmediaverse/' . ltrim( $rel, '/' );
	}

	/**
	 * Old original and every old size are deleted; the new set exists.
	 *
	 * @return void
	 */
	public function test_replace_deletes_every_old_file_and_keeps_the_new(): void {
		$owner = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $owner );

		$first    = $this->jpeg( 1600, 1200 );
		$media_id = Plugin::container()->get( 'upload' )->handle(
			array(
				'tmp_name' => $first,
				'name'     => 'holiday.jpg',
				'size'     => filesize( $first ),
				'type'     => 'image/jpeg',
			),
			$owner,
			array( 'privacy' => 'public' )
		);
		$this->assertIsInt( $media_id );

		$repo = Plugin::container()->get( 'media_repository' );
		$old  = $repo->get_stored_file_paths( $media_id );
		$this->assertGreaterThan( 1, count( $old ), 'The upload produced sizes to leave behind.' );

		$second = $this->jpeg( 1400, 1000 );
		$req    = new \WP_REST_Request( 'POST', '/mvs/v1/media/' . $media_id . '/replace' );
		$req->set_file_params(
			array(
				'file' => array(
					'tmp_name' => $second,
					'name'     => 'replacement.jpg',
					'size'     => filesize( $second ),
					'type'     => 'image/jpeg',
					'error'    => 0,
				),
			)
		);
		$res = rest_do_request( $req );
		$this->assertSame( 200, $res->get_status(), wp_json_encode( $res->get_data() ) );

		$new = $repo->get_stored_file_paths( $media_id );
		foreach ( array_diff( $old, $new ) as $gone ) {
			$this->assertFileDoesNotExist( $this->abs( $gone ), "Old file left on disk: {$gone}" );
		}
		foreach ( $new as $kept ) {
			$this->assertFileExists( $this->abs( $kept ), "New file missing: {$kept}" );
		}

		foreach ( $new as $kept ) {
			wp_delete_file( $this->abs( $kept ) );
		}
	}
}
