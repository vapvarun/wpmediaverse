<?php
/**
 * LocalDriver::delete() is a trust boundary and must look in the right tree.
 *
 * The path comes from a database row, so a `../` in a stored row must never
 * reach unlink(). And a path declared uploads-relative through
 * `mvs_local_only_path_prefixes` (Pro documents) must resolve against the
 * uploads base — resolving it under uploads/wpmediaverse/ found nothing and
 * reported success, leaving every deleted document on disk (Basecamp
 * 10320657202).
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Services\LocalDriver;
use WPMediaVerse\Services\StorageService;

class LocalDriverDeleteTest extends WP_UnitTestCase {

	/**
	 * Top-level uploads entries this test created.
	 *
	 * @var string[]
	 */
	private $created = array();

	/**
	 * Tear down. Hooks are restored by WP_UnitTestCase itself.
	 */
	public function tear_down(): void {
		foreach ( array_unique( $this->created ) as $path ) {
			if ( is_link( $path ) || is_file( $path ) ) {
				unlink( $path );
				continue;
			}
			if ( is_dir( $path ) ) {
				$items = new \RecursiveIteratorIterator(
					new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ),
					\RecursiveIteratorIterator::CHILD_FIRST
				);
				foreach ( $items as $item ) {
					( $item->isDir() && ! $item->isLink() ) ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
				}
				rmdir( $path );
			}
		}
		$this->created = array();
		parent::tear_down();
	}

	/**
	 * Write a file at an uploads-relative path, creating its directories.
	 *
	 * @param string $relative Uploads-relative path.
	 * @return string Absolute path.
	 */
	private function put( string $relative ): string {
		$base = trailingslashit( wp_upload_dir()['basedir'] );
		$abs  = $base . $relative;
		wp_mkdir_p( dirname( $abs ) );
		file_put_contents( $abs, 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$this->created[] = $base . strtok( $relative, '/' );
		return $abs;
	}

	/**
	 * Declare a local-only prefix, as Pro does for its document tree.
	 *
	 * @param string $prefix Prefix.
	 */
	private function declare_prefix( string $prefix ): void {
		add_filter(
			'mvs_local_only_path_prefixes',
			static function ( $prefixes ) use ( $prefix ) {
				$prefixes[] = $prefix;
				return $prefixes;
			}
		);
	}

	/**
	 * A declared uploads-relative path is deleted through the shared seam.
	 */
	public function test_delete_everywhere_removes_a_local_only_file(): void {
		$this->declare_prefix( 'mvs-test-docs' );
		$abs = $this->put( 'mvs-test-docs/seg123/2026/09/7-brief.docx' );

		$this->assertTrue( ( new StorageService() )->delete_everywhere( 'mvs-test-docs/seg123/2026/09/7-brief.docx' ) );
		$this->assertFileDoesNotExist( $abs );
	}

	/**
	 * Without the declaration the same path stays under uploads/wpmediaverse/ —
	 * no blind fallback to the uploads root, where media-shaped `YYYY/MM/<file>`
	 * paths would collide with WordPress attachments.
	 */
	public function test_undeclared_path_never_falls_back_to_uploads_root(): void {
		$abs = $this->put( 'mvs-test-undeclared/2026/09/keep.jpg' );

		$this->assertTrue( ( new LocalDriver() )->delete( 'mvs-test-undeclared/2026/09/keep.jpg' ), 'Absent under the media base reads as already gone.' );
		$this->assertFileExists( $abs );
	}

	/**
	 * Deleting a media file that is already gone stays idempotent.
	 */
	public function test_already_deleted_media_file_is_success(): void {
		$this->assertTrue( ( new LocalDriver() )->delete( '2026/09/never-existed-' . wp_generate_password( 8, false ) . '.jpg' ) );
	}

	/**
	 * Traversal, absolute and NUL paths are refused and nothing is unlinked.
	 */
	public function test_traversal_and_absolute_paths_are_refused(): void {
		$this->declare_prefix( 'mvs-test-docs' );
		$canary = $this->put( 'mvs-traversal-canary.txt' );
		$driver = new LocalDriver();

		$refused = array(
			'../mvs-traversal-canary.txt',
			'2026/../../mvs-traversal-canary.txt',
			'..\\mvs-traversal-canary.txt',
			'mvs-test-docs/../mvs-traversal-canary.txt',
			'mvs-test-docs/seg/..',
			'./x',
			'.',
			$canary,
			'C:\\Windows\\win.ini',
			"2026/09/a.jpg\0.txt",
		);
		foreach ( $refused as $path ) {
			$this->assertFalse( $driver->delete( $path ), 'Must refuse: ' . $path );
		}
		$this->assertFileExists( $canary );

		// The shared seam strips a leading slash (legacy absolute rows) and then
		// still refuses the traversal.
		$this->assertFalse( ( new StorageService() )->delete_everywhere( '/../mvs-traversal-canary.txt' ) );
		$this->assertFileExists( $canary );
	}

	/**
	 * A symlink inside the tree cannot carry the delete outside it.
	 */
	public function test_symlink_out_of_the_tree_is_refused(): void {
		$this->declare_prefix( 'mvs-test-docs' );
		$target = $this->put( 'mvs-symlink-target.txt' );
		$this->put( 'mvs-test-docs/seg/placeholder.txt' );
		$link = trailingslashit( wp_upload_dir()['basedir'] ) . 'mvs-test-docs/seg/link.txt';

		if ( ! @symlink( $target, $link ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$this->markTestSkipped( 'Filesystem does not support symlinks.' );
		}

		$this->assertFalse( ( new LocalDriver() )->delete( 'mvs-test-docs/seg/link.txt' ) );
		$this->assertFileExists( $target );
	}

	/**
	 * An empty or unsafe declared prefix is ignored — '' would reroute every
	 * media path to the uploads root.
	 */
	public function test_empty_or_unsafe_prefixes_are_ignored(): void {
		$this->declare_prefix( '' );
		$this->declare_prefix( '/' );
		$this->declare_prefix( '../etc' );
		$this->declare_prefix( '/mvs-test-docs/' );

		// Contains/NotContains rather than an exact list: Pro, when loaded,
		// declares its own document prefix.
		$prefixes = LocalDriver::local_only_prefixes();
		$this->assertContains( 'mvs-test-docs', $prefixes );
		$this->assertNotContains( '', $prefixes );
		$this->assertNotContains( '../etc', $prefixes );
		$this->assertSame( '', LocalDriver::local_only_prefix( '2026/09/photo.jpg' ) );
	}
}
