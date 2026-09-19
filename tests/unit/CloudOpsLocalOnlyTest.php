<?php
/**
 * Local-only files (Pro documents) never move between storage drivers.
 *
 * 2.5.1 regression: LocalDriver::delete() learned to find uploads-relative
 * document paths, but exists()/download() still looked under
 * uploads/wpmediaverse/. A cloud migration of a public document therefore
 * "skipped" the copy as missing, flipped file_url to a CDN object that did not
 * exist, then deleted the only local copy. The media bulk route did the same
 * kind of damage through a direct driver delete.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\Plugin;
use WPMediaVerse\Services\CloudOps;
use WPMediaVerse\Services\LocalDriver;
use WPMediaVerse\Services\StorageDriverInterface;

/**
 * @since 2.5.1
 */
class CloudOpsLocalOnlyTest extends WP_UnitTestCase {

	/**
	 * Local-only prefix the test declares, as Pro does for documents.
	 */
	const PREFIX = 'mvs-test-docs';

	/**
	 * Repository.
	 *
	 * @var \WPMediaVerse\Repository\MediaRepository
	 */
	private $repo;

	/**
	 * Fake cloud destination.
	 *
	 * @var StorageDriverInterface
	 */
	private $cloud;

	/**
	 * Absolute paths to remove on tear down.
	 *
	 * @var string[]
	 */
	private $created = array();

	/**
	 * Set up.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->repo  = Plugin::container()->get( 'media_repository' );
		$this->cloud = $this->fake_driver();

		add_filter(
			'mvs_storage_driver',
			function ( $driver, $slug ) {
				return 'fakecloud' === $slug ? $this->cloud : $driver;
			},
			10,
			2
		);
		add_filter(
			'mvs_local_only_path_prefixes',
			static function ( $prefixes ) {
				$prefixes   = (array) $prefixes;
				$prefixes[] = self::PREFIX;
				return $prefixes;
			}
		);
	}

	/**
	 * Tear down.
	 */
	public function tear_down(): void {
		foreach ( array_unique( $this->created ) as $dir ) {
			if ( ! is_dir( $dir ) ) {
				continue;
			}
			$items = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::CHILD_FIRST
			);
			foreach ( $items as $item ) {
				$item->isDir() ? rmdir( $item->getPathname() ) : wp_delete_file( $item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture teardown.
			}
			rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture teardown.
		}
		$this->created = array();
		parent::tear_down();
	}

	/**
	 * Write a file under uploads/ and remember its top directory.
	 *
	 * @param string $relative Uploads-relative path.
	 * @return string Absolute path.
	 */
	private function put( string $relative ): string {
		$base = trailingslashit( wp_upload_dir()['basedir'] );
		$abs  = $base . $relative;
		wp_mkdir_p( dirname( $abs ) );
		file_put_contents( $abs, 'bytes' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$parts           = explode( '/', $relative );
		$this->created[] = $base . ( 'wpmediaverse' === $parts[0] ? $parts[0] . '/' . $parts[1] : $parts[0] );
		return $abs;
	}

	/**
	 * Insert an index row.
	 *
	 * @param array $overrides Column overrides.
	 * @return int Media id.
	 */
	private function insert( array $overrides ): int {
		return (int) $this->repo->insert(
			array_merge(
				array(
					'title'       => 'Cloud ops ' . wp_generate_password( 6, false ),
					'post_author' => 1,
					'status'      => 'publish',
					'privacy'     => 'public',
					'media_type'  => 'image',
					'file_type'   => 'image/jpeg',
				),
				$overrides
			)
		);
	}

	/**
	 * A public document with a real file on disk.
	 *
	 * @param string $file_url Stored file_url.
	 * @return array{0:int,1:string} Media id and absolute file path.
	 */
	private function document( string $file_url = '' ): array {
		$rel = self::PREFIX . '/seg' . wp_rand( 1000, 9999 ) . '/2026/09/doc.pdf';
		$abs = $this->put( $rel );
		$id  = $this->insert(
			array(
				'media_type' => 'document',
				'file_type'  => 'application/pdf',
				'file_path'  => $rel,
				'file_url'   => $file_url,
			)
		);
		return array( $id, $abs );
	}

	/**
	 * LocalDriver finds a local-only file where delete() finds it.
	 */
	public function test_local_driver_reads_local_only_paths_from_uploads_base(): void {
		$rel    = self::PREFIX . '/seg/2026/09/read.pdf';
		$abs    = $this->put( $rel );
		$driver = new LocalDriver();
		$dest   = trailingslashit( get_temp_dir() ) . 'mvs-local-only-' . wp_rand() . '.pdf';

		$this->assertTrue( $driver->exists( $rel ) );
		$this->assertSame( $abs, $driver->get_full_path( $rel ) );
		$this->assertTrue( $driver->download( $rel, $dest ) );
		$this->assertFileExists( $dest );
		$this->assertSame( '', $driver->url( $rel ), 'A deny-protected local-only file has no direct URL.' );
		$this->assertFalse( $driver->exists( '../' . $rel ), 'Traversal must be refused by exists() too.' );

		wp_delete_file( $dest );
	}

	/**
	 * Local to cloud on a document: refused, file and row untouched.
	 */
	public function test_migrate_one_refuses_document_and_touches_nothing(): void {
		list( $id, $abs ) = $this->document();

		$result = CloudOps::migrate_one( $id, 'local', 'fakecloud', false );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'skipped-local-only', $result['status'] );
		$this->assertFileExists( $abs, 'The only copy of the document was deleted.' );
		$this->assertSame( '', (string) $this->repo->get_raw( $id, 'file_url' ) );
		$this->assertSame( array(), $this->cloud->calls, 'The cloud driver must not even be asked about a document path.' );
	}

	/**
	 * Cloud to local (privacy repatriation) on a document: refused as well.
	 */
	public function test_repatriation_refuses_document(): void {
		list( $id, $abs ) = $this->document();

		$result = CloudOps::migrate_one( $id, 'fakecloud', 'local', false );

		$this->assertSame( 'skipped-local-only', $result['status'] );
		$this->assertFileExists( $abs );
		$this->assertSame( array(), $this->cloud->calls );
	}

	/**
	 * "Free up server space" never touches a document either.
	 */
	public function test_cleanup_local_one_refuses_document(): void {
		update_option( 'mvs_storage_driver', 'fakecloud' );
		list( $id, $abs ) = $this->document();

		$result = CloudOps::cleanup_local_one( $id, false );

		$this->assertSame( 'skipped-local-only', $result['status'] );
		$this->assertFileExists( $abs );
	}

	/**
	 * The candidate list and its count leave documents out.
	 */
	public function test_candidates_exclude_local_only_paths(): void {
		$local_url = content_url( '/uploads/wpmediaverse/2026/09/' );
		$before    = $this->repo->count_public_cloud_candidates( true );

		list( $doc ) = $this->document( content_url( '/uploads/wpmediaverse/' . self::PREFIX . '/x/doc.pdf' ) );
		$this->assertSame( $before, $this->repo->count_public_cloud_candidates( true ), 'A document was counted as a cloud candidate.' );

		$photo = $this->insert(
			array(
				'file_path' => '2026/09/photo-' . wp_rand() . '.jpg',
				'file_url'  => $local_url . 'photo.jpg',
			)
		);
		$this->assertSame( $before + 1, $this->repo->count_public_cloud_candidates( true ) );

		$ids = array_map( 'intval', array_column( $this->repo->query_public_cloud_candidates( 100000, true ), 'media_id' ) );
		$this->assertContains( $photo, $ids );
		$this->assertNotContains( $doc, $ids );

		$all = array_map( 'intval', array_column( $this->repo->query_public_cloud_candidates( 100000, false ), 'media_id' ) );
		$this->assertNotContains( $doc, $all, 'The cleanup scan must not list documents either.' );
	}

	/**
	 * Any media whose original reached neither side keeps its source and URL.
	 *
	 * The source here reports "missing" while actually holding the file — the
	 * exact lie that cost a document its only copy. Nothing may be deleted or
	 * repointed on the strength of that answer.
	 */
	public function test_original_on_neither_side_keeps_source_and_url(): void {
		$source = $this->fake_driver();
		add_filter(
			'mvs_storage_driver',
			static function ( $driver, $slug ) use ( $source ) {
				return 'fakesource' === $slug ? $source : $driver;
			},
			10,
			2
		);

		$url = content_url( '/uploads/wpmediaverse/2026/09/kept.jpg' );
		$id  = $this->insert(
			array(
				'file_path' => '2026/09/kept-' . wp_rand() . '.jpg',
				'file_url'  => $url,
			)
		);

		$result = CloudOps::migrate_one( $id, 'fakesource', 'fakecloud', false );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'failed', $result['status'] );
		$this->assertSame( $url, (string) $this->repo->get_raw( $id, 'file_url' ) );
		$this->assertSame( array(), $source->deleted, 'A source copy was deleted without a verified destination copy.' );
	}

	/**
	 * Control: an ordinary media still migrates, flips and cleans its source.
	 */
	public function test_media_still_migrates(): void {
		$rel = 'mvstest-' . wp_rand( 1000, 9999 ) . '/photo.jpg';
		$abs = $this->put( 'wpmediaverse/' . $rel );
		$id  = $this->insert(
			array(
				'file_path' => $rel,
				'file_url'  => content_url( '/uploads/wpmediaverse/' . $rel ),
			)
		);

		$result = CloudOps::migrate_one( $id, 'local', 'fakecloud', false );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'migrated', $result['status'] );
		$this->assertSame( 'https://cdn.example.test/' . $rel, (string) $this->repo->get_raw( $id, 'file_url' ) );
		$this->assertFileDoesNotExist( $abs );
	}

	/**
	 * The media bulk route refuses documents and never reaches a cloud driver.
	 */
	public function test_bulk_delete_refuses_document_and_never_calls_cloud(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		do_action( 'rest_api_init' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook.

		$storage  = Plugin::container()->get( 'storage' );
		$property = new \ReflectionProperty( $storage, 'driver' );
		$property->setAccessible( true );
		$previous = $property->getValue( $storage );
		$property->setValue( $storage, $this->cloud );

		try {
			list( $doc, $abs ) = $this->document();
			$photo             = $this->insert( array( 'file_path' => '2026/09/bulk-' . wp_rand() . '.jpg' ) );

			$request = new \WP_REST_Request( 'POST', '/mvs/v1/media/bulk' );
			$request->set_param( 'action', 'delete' );
			$request->set_param( 'media_ids', array( $photo, $doc ) );
			$response = rest_get_server()->dispatch( $request );

			$this->assertSame( 400, $response->get_status() );
			$this->assertSame( 'mvs_document_route', $response->as_error()->get_error_code() );
			$this->assertTrue( $this->repo->exists( $doc ) );
			$this->assertTrue( $this->repo->exists( $photo ), 'A refused request must not half-run.' );
			$this->assertFileExists( $abs );

			$request->set_param( 'media_ids', array( $photo ) );
			$response = rest_get_server()->dispatch( $request );

			$this->assertSame( 200, $response->get_status() );
			$this->assertFalse( $this->repo->exists( $photo ) );
			foreach ( $this->cloud->calls as $call ) {
				$this->assertSame( '', LocalDriver::local_only_prefix( $call[1] ), 'A local-only path reached the cloud driver.' );
			}
		} finally {
			$property->setValue( $storage, $previous );
		}
	}

	/**
	 * Replacing a document through the MEDIA route is refused before any write.
	 *
	 * The media route would store the new bytes in the media tree on the
	 * active (cloud) driver, re-type the row, and delete the old document
	 * through the cloud driver while its local copy stayed on disk.
	 */
	public function test_media_replace_refuses_document(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		do_action( 'rest_api_init' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook.

		$storage  = Plugin::container()->get( 'storage' );
		$property = new \ReflectionProperty( $storage, 'driver' );
		$property->setAccessible( true );
		$previous = $property->getValue( $storage );
		$property->setValue( $storage, $this->cloud );

		try {
			list( $doc, $abs ) = $this->document();
			$rel               = (string) $this->repo->get_raw( $doc, 'file_path' );

			$response = rest_get_server()->dispatch( new \WP_REST_Request( 'POST', '/mvs/v1/media/' . $doc . '/replace' ) );

			$this->assertSame( 400, $response->get_status() );
			$this->assertSame( 'mvs_document_route', $response->as_error()->get_error_code() );
			$this->assertFileExists( $abs );
			$this->assertSame( $rel, (string) $this->repo->get_raw( $doc, 'file_path' ) );
			$this->assertSame( 'document', (string) $this->repo->get_raw( $doc, 'media_type' ) );
			$this->assertSame( array(), $this->cloud->calls );
		} finally {
			$property->setValue( $storage, $previous );
		}
	}

	/**
	 * The storage panel's total leaves documents out, like every other tile.
	 */
	public function test_panel_total_excludes_documents(): void {
		$before = CloudOps::count_candidates();

		$this->document( content_url( '/uploads/wpmediaverse/' . self::PREFIX . '/x/doc.pdf' ) );
		list( $private ) = $this->document();
		$this->repo->set( $private, 'privacy', 'private' );

		$this->assertSame( $before, CloudOps::count_candidates(), 'A document moved a storage-panel tile.' );

		$this->insert(
			array(
				'file_path' => '2026/09/tile-' . wp_rand() . '.jpg',
				'file_url'  => content_url( '/uploads/wpmediaverse/2026/09/tile.jpg' ),
			)
		);
		$after = CloudOps::count_candidates();
		$this->assertSame( $before['total'] + 1, $after['total'] );
		$this->assertSame( $before['needs_migration'] + 1, $after['needs_migration'] );
	}

	/**
	 * In-memory driver that records every call.
	 *
	 * @return StorageDriverInterface
	 */
	private function fake_driver(): StorageDriverInterface {
		return new class() implements StorageDriverInterface {
			/**
			 * Paths it holds.
			 *
			 * @var array<string,bool>
			 */
			public $files = array();

			/**
			 * Every call as [method, path].
			 *
			 * @var array<int,array{0:string,1:string}>
			 */
			public $calls = array();

			/**
			 * Paths delete() was asked for.
			 *
			 * @var string[]
			 */
			public $deleted = array();

			/**
			 * Store.
			 *
			 * @param string $source_path Source.
			 * @param string $dest_path   Dest.
			 * @return bool
			 */
			public function store( string $source_path, string $dest_path ): bool {
				$this->calls[]             = array( 'store', $dest_path );
				$this->files[ $dest_path ] = file_exists( $source_path );
				return $this->files[ $dest_path ];
			}

			/**
			 * Delete.
			 *
			 * @param string $path Path.
			 * @return bool
			 */
			public function delete( string $path ): bool {
				$this->calls[]   = array( 'delete', $path );
				$this->deleted[] = $path;
				unset( $this->files[ $path ] );
				return true;
			}

			/**
			 * URL.
			 *
			 * @param string $path Path.
			 * @return string
			 */
			public function url( string $path ): string {
				return 'https://cdn.example.test/' . $path;
			}

			/**
			 * Exists.
			 *
			 * @param string $path Path.
			 * @return bool
			 */
			public function exists( string $path ): bool {
				$this->calls[] = array( 'exists', $path );
				return ! empty( $this->files[ $path ] );
			}

			/**
			 * Full path.
			 *
			 * @param string $path Path.
			 * @return string
			 */
			public function get_full_path( string $path ): string {
				return $path;
			}

			/**
			 * Download.
			 *
			 * @param string $path       Path.
			 * @param string $local_dest Dest.
			 * @return bool
			 */
			public function download( string $path, string $local_dest ): bool {
				$this->calls[] = array( 'download', $path );
				return false;
			}
		};
	}
}
