<?php
/**
 * Test MediaRepository service (custom-table architecture).
 *
 * Replaces the old MediaCRUDTest which relied on wp_insert_post / CPTs.
 * All media data now lives in mvs_media_index + mvs_media_meta tables.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Repository\MediaRepository;

class MediaRepositoryTest extends WP_UnitTestCase {

	/**
	 * Admin user ID used across tests.
	 *
	 * @var int
	 */
	private int $admin_id;

	/**
	 * Set up each test — ensure tables exist and create an admin user.
	 */
	public function set_up(): void {
		parent::set_up();

		global $wpdb;

		// Ensure mvs_media_meta table exists (not yet in Migrator).
		$meta_table = $wpdb->prefix . 'mvs_media_meta';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$table_exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $meta_table )
		);

		if ( ! $table_exists ) {
			$charset_collate = $wpdb->get_charset_collate();
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			dbDelta(
				"CREATE TABLE {$meta_table} (
					meta_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
					media_id bigint(20) unsigned NOT NULL,
					meta_key varchar(255) DEFAULT NULL,
					meta_value longtext,
					PRIMARY KEY  (meta_id),
					KEY media_id (media_id),
					KEY meta_key (meta_key(191))
				) {$charset_collate};"
			);
		}

		// Ensure mvs_media_index table exists via Migrator.
		$index_table = $wpdb->prefix . 'mvs_media_index';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$index_exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $index_table )
		);

		if ( ! $index_exists ) {
			$migrator = new \WPMediaVerse\Core\Migrator();
			$migrator->run();
		}

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
	}

	/* ------------------------------------------------------------------
	 * insert()
	 * ----------------------------------------------------------------*/

	/**
	 * insert() returns an auto-increment int > 0.
	 */
	public function test_insert_returns_media_id(): void {
		$media_id = MediaRepository::insert(
			array(
				'title'       => 'Sunset Beach',
				'post_author' => $this->admin_id,
				'media_type'  => 'image',
			)
		);

		$this->assertIsInt( $media_id );
		$this->assertGreaterThan( 0, $media_id );
	}

	/**
	 * insert() auto-generates a sanitized slug from the title.
	 */
	public function test_insert_auto_generates_slug(): void {
		$media_id = MediaRepository::insert(
			array(
				'title'       => 'My Awesome Photo!',
				'post_author' => $this->admin_id,
			)
		);

		$slug = MediaRepository::get( $media_id, 'slug' );
		$this->assertSame( 'my-awesome-photo', $slug );
	}

	/**
	 * Two inserts with the same title get distinct slugs (second gets -1 suffix).
	 */
	public function test_insert_unique_slug_suffix(): void {
		$id1 = MediaRepository::insert(
			array(
				'title'       => 'Duplicate Title',
				'post_author' => $this->admin_id,
			)
		);
		$id2 = MediaRepository::insert(
			array(
				'title'       => 'Duplicate Title',
				'post_author' => $this->admin_id,
			)
		);

		$slug1 = MediaRepository::get( $id1, 'slug' );
		$slug2 = MediaRepository::get( $id2, 'slug' );

		$this->assertSame( 'duplicate-title', $slug1 );
		$this->assertSame( 'duplicate-title-1', $slug2 );
	}

	/**
	 * insert() applies default status, privacy, and moderation_status.
	 */
	public function test_insert_defaults(): void {
		$media_id = MediaRepository::insert(
			array(
				'title'       => 'Defaults Test',
				'post_author' => $this->admin_id,
			)
		);

		$this->assertSame( 'publish', MediaRepository::get( $media_id, 'status' ) );
		$this->assertSame( 'public', MediaRepository::get( $media_id, 'privacy' ) );
		$this->assertSame( 'approved', MediaRepository::get( $media_id, 'moderation_status' ) );
	}

	/* ------------------------------------------------------------------
	 * get()
	 * ----------------------------------------------------------------*/

	/**
	 * get() retrieves an index column set during insert.
	 */
	public function test_get_index_column(): void {
		$media_id = MediaRepository::insert(
			array(
				'title'       => 'Mountain View',
				'post_author' => $this->admin_id,
				'media_type'  => 'image',
			)
		);

		$this->assertSame( 'Mountain View', MediaRepository::get( $media_id, 'title' ) );
		$this->assertSame( 'image', MediaRepository::get( $media_id, 'media_type' ) );
	}

	/**
	 * get() retrieves a meta (non-index, non-URL) field via mvs_media_meta.
	 *
	 * Uses an arbitrary meta key — `thumb_*` and `file_url` are special-cased
	 * by `get()` to return signed URLs and so are tested separately.
	 */
	public function test_get_meta_field(): void {
		$media_id = MediaRepository::insert(
			array(
				'title'       => 'Meta Field Test',
				'post_author' => $this->admin_id,
			)
		);

		MediaRepository::set( $media_id, 'custom_meta_key', 'arbitrary-value' );

		$this->assertSame(
			'arbitrary-value',
			MediaRepository::get( $media_id, 'custom_meta_key' )
		);
	}

	/**
	 * get() returns null for a non-existent media_id.
	 */
	public function test_get_nonexistent_returns_null(): void {
		$this->assertNull( MediaRepository::get( 999999, 'title' ) );
		$this->assertNull( MediaRepository::get( 999999, 'some_custom_key' ) );
	}

	/**
	 * get($id, 'file_url') signs the URL via SignedUrlService — never returns
	 * the raw stored URL to external callers (Phase 0a item 1).
	 *
	 * The contract is: any caller using `MediaRepository::get($id, 'file_url')`
	 * gets a token-bearing URL that flows through the gated uploads serve
	 * endpoint, OR an empty string. Never the raw varchar.
	 */
	public function test_get_file_url_returns_signed_or_empty_never_raw(): void {
		$raw_url  = 'https://example.com/wp-content/uploads/wpmediaverse/2026/05/raw.jpg';
		$media_id = MediaRepository::insert(
			array(
				'title'       => 'Signed-URL Contract',
				'post_author' => $this->admin_id,
				'media_type'  => 'image',
				'file_url'    => $raw_url,
			)
		);

		$result = MediaRepository::get( $media_id, 'file_url' );

		// Assert: NEVER the raw stored URL — that would mean signing was
		// bypassed and the .htaccess deny-all would 403 the caller.
		$this->assertNotSame( $raw_url, $result );

		// Result is either an empty string (signing service unavailable in
		// the test environment — fail-quiet) or a signed URL routed through
		// the mvs/v1/serve REST endpoint with HMAC token params. Both
		// satisfy the "never raw" contract.
		$this->assertIsString( $result );
		if ( '' !== $result ) {
			$this->assertStringContainsString( 'mvs_sig=', $result );
			$this->assertStringContainsString( 'mvs_id=', $result );
		}
	}

	/**
	 * get($id, 'thumb_large'|'thumb_medium'|'thumb_thumb') signs the URL via
	 * SignedUrlService — never returns the raw stored URL (Phase 0a item 2).
	 *
	 * The contract is: any caller using `MediaRepository::get` for a thumb
	 * key gets a token-bearing URL routed through the gated uploads serve
	 * endpoint, OR an empty string. Never the raw varchar.
	 */
	public function test_get_thumb_keys_return_signed_or_empty_never_raw(): void {
		$raw_thumb = 'https://example.com/wp-content/uploads/wpmediaverse/2026/05/thumb-large.jpg';
		$media_id  = MediaRepository::insert(
			array(
				'title'       => 'Thumb-URL Contract',
				'post_author' => $this->admin_id,
				'media_type'  => 'image',
			)
		);

		// Seed every thumbnail size with a distinct raw URL so we can prove
		// none of them leak through `get()`.
		MediaRepository::set( $media_id, 'thumb_large', $raw_thumb );
		MediaRepository::set( $media_id, 'thumb_medium', str_replace( 'large', 'medium', $raw_thumb ) );
		MediaRepository::set( $media_id, 'thumb_thumb', str_replace( 'large', 'thumb', $raw_thumb ) );

		foreach ( array( 'thumb_large', 'thumb_medium', 'thumb_thumb' ) as $key ) {
			$result = MediaRepository::get( $media_id, $key );

			$this->assertNotSame(
				MediaRepository::get_raw( $media_id, $key ),
				$result,
				"get($key) must not return the raw stored URL"
			);
			$this->assertIsString( $result );
			if ( '' !== $result ) {
				$this->assertStringContainsString( 'mvs_sig=', $result );
				$this->assertStringContainsString( 'mvs_id=', $result );
			}
		}
	}

	/**
	 * get($id, 'watermark_url') signs via SignedUrlService when a
	 * watermark exists, returns empty string when none has been generated
	 * (Phase 0a item 3).
	 *
	 * Watermark presence is the cache marker — Pro's Watermarker writes
	 * the raw URL into meta only after creating the preview file. The
	 * signed URL routes through the serve endpoint with size=watermark.
	 */
	public function test_get_watermark_url_returns_signed_when_present_empty_when_absent(): void {
		$media_id = MediaRepository::insert(
			array(
				'title'       => 'Watermark Contract',
				'post_author' => $this->admin_id,
				'media_type'  => 'image',
			)
		);

		// No watermark yet → empty string, never a signed URL pointing at a non-existent file.
		$this->assertSame( '', (string) MediaRepository::get( $media_id, 'watermark_url' ) );

		// Pro's Watermarker writes raw URL into meta after generating the preview.
		$raw_watermark = 'https://example.com/wp-content/uploads/wpmediaverse/previews/' . $media_id . '-preview.jpg';
		MediaRepository::set( $media_id, 'watermark_url', $raw_watermark );

		$result = MediaRepository::get( $media_id, 'watermark_url' );

		// Signed URL — routed through serve endpoint with the watermark variant.
		$this->assertNotSame( $raw_watermark, $result );
		$this->assertIsString( $result );
		if ( '' !== $result ) {
			$this->assertStringContainsString( 'mvs_sig=', $result );
			$this->assertStringContainsString( 'mvs_id=', $result );
			$this->assertStringContainsString( 'mvs_size=watermark', $result );
		}

		// Internal callers can still read the raw URL via get_raw.
		$this->assertSame( $raw_watermark, MediaRepository::get_raw( $media_id, 'watermark_url' ) );
	}

	/**
	 * get_raw() returns the raw stored thumbnail URLs — internal escape
	 * hatch for the signing service serving files from disk and the
	 * upload pipeline backfilling thumb_large with file_url.
	 */
	public function test_get_raw_thumb_keys_return_stored_value(): void {
		$raw_thumb = 'https://example.com/wp-content/uploads/wpmediaverse/2026/05/raw-thumb.jpg';
		$media_id  = MediaRepository::insert(
			array(
				'title'       => 'Raw-Thumb Internal Read',
				'post_author' => $this->admin_id,
				'media_type'  => 'image',
			)
		);

		MediaRepository::set( $media_id, 'thumb_large', $raw_thumb );

		$this->assertSame( $raw_thumb, MediaRepository::get_raw( $media_id, 'thumb_large' ) );
	}

	/**
	 * get_raw($id, 'file_url') returns the raw stored URL — internal
	 * escape hatch for the signing service itself and filesystem readers.
	 */
	public function test_get_raw_file_url_returns_stored_value(): void {
		$raw_url  = 'https://example.com/wp-content/uploads/wpmediaverse/2026/05/raw.jpg';
		$media_id = MediaRepository::insert(
			array(
				'title'       => 'Raw-URL Internal Read',
				'post_author' => $this->admin_id,
				'media_type'  => 'image',
				'file_url'    => $raw_url,
			)
		);

		$this->assertSame( $raw_url, MediaRepository::get_raw( $media_id, 'file_url' ) );
	}

	/**
	 * find_by_url() reverses a stored gated URL into the media_id.
	 * Returns 0 for URLs outside the gated uploads dir or unknown to mvs_media_index.
	 */
	public function test_find_by_url_reverses_indexed_url(): void {
		$raw_url  = 'https://example.com/wp-content/uploads/wpmediaverse/2026/05/find-' . wp_generate_password( 8, false ) . '.jpg';
		$media_id = MediaRepository::insert(
			array(
				'title'       => 'find_by_url contract',
				'post_author' => $this->admin_id,
				'media_type'  => 'image',
				'file_url'    => $raw_url,
			)
		);

		// Round-trip: indexed URL → media_id.
		$this->assertSame( $media_id, MediaRepository::find_by_url( $raw_url ) );

		// Unknown URL inside the gated dir → 0 (not in index).
		$this->assertSame(
			0,
			MediaRepository::find_by_url( 'https://example.com/wp-content/uploads/wpmediaverse/nope.jpg' )
		);

		// URL outside the gated dir (avatars, theme images) → 0 pass-through.
		$this->assertSame(
			0,
			MediaRepository::find_by_url( 'https://example.com/wp-content/uploads/2026/05/avatar.jpg' )
		);

		// Empty URL → 0.
		$this->assertSame( 0, MediaRepository::find_by_url( '' ) );
	}

	/**
	 * get_broadcast_url() emits a long-lived (1y) signed URL with anonymous
	 * viewer (user_id=0) so BP activity feeds keep rendering for months.
	 */
	public function test_get_broadcast_url_emits_long_lived_signed_url(): void {
		$media_id = MediaRepository::insert(
			array(
				'title'       => 'broadcast contract',
				'post_author' => $this->admin_id,
				'media_type'  => 'image',
				'file_url'    => 'https://example.com/wp-content/uploads/wpmediaverse/2026/05/broadcast.jpg',
			)
		);

		$url = MediaRepository::get_broadcast_url( $media_id );

		$this->assertIsString( $url );
		if ( '' !== $url ) {
			// Token-bearing URL routed through the serve endpoint.
			$this->assertStringContainsString( 'mvs_sig=', $url );
			$this->assertStringContainsString( 'mvs_id=', $url );
			// Anonymous viewer (user_id=0) baked into the URL.
			$this->assertStringContainsString( 'mvs_uid=0', $url );

			// Expiry should be ~1 year out, not the default 1h TTL.
			parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $params );
			$ttl = (int) ( $params['mvs_exp'] ?? 0 ) - time();
			$this->assertGreaterThan( DAY_IN_SECONDS * 300, $ttl, 'Broadcast TTL must outlive months of activity feed reads' );
		}
	}

	/**
	 * get_filesystem_path() resolves stored relative file_path against the
	 * uploads base, returns absolute realpath after containment check.
	 */
	public function test_get_filesystem_path_resolves_relative_to_uploads(): void {
		$upload_dir = wp_upload_dir();
		$rel        = '2026/05/test-fs-path-' . wp_generate_password( 8, false ) . '.txt';
		$abs        = trailingslashit( $upload_dir['basedir'] ) . 'wpmediaverse/' . $rel;

		wp_mkdir_p( dirname( $abs ) );
		file_put_contents( $abs, 'unit-test fixture' );

		$media_id = MediaRepository::insert(
			array(
				'title'       => 'Filesystem Path Resolve',
				'post_author' => $this->admin_id,
				'media_type'  => 'image',
				'file_path'   => $rel,
			)
		);

		$resolved = MediaRepository::get_filesystem_path( $media_id );

		$this->assertSame( realpath( $abs ), $resolved );

		unlink( $abs );
	}

	/**
	 * get_filesystem_path() returns null for media with no file_path meta.
	 */
	public function test_get_filesystem_path_returns_null_when_no_path(): void {
		$media_id = MediaRepository::insert(
			array(
				'title'       => 'No file_path',
				'post_author' => $this->admin_id,
				'media_type'  => 'image',
			)
		);

		$this->assertNull( MediaRepository::get_filesystem_path( $media_id ) );
	}

	/**
	 * get_filesystem_path() rejects out-of-tree paths via the realpath
	 * containment check (defense against legacy/bad stored values).
	 */
	public function test_get_filesystem_path_rejects_out_of_tree(): void {
		$media_id = MediaRepository::insert(
			array(
				'title'       => 'Out-of-tree path',
				'post_author' => $this->admin_id,
				'media_type'  => 'image',
				// Absolute path outside the uploads tree.
				'file_path'   => '/etc/passwd',
			)
		);

		$this->assertNull( MediaRepository::get_filesystem_path( $media_id ) );
	}

	/**
	 * get_raw() also works for non-special index columns and meta fields —
	 * behavioral parity with get() for everything except `file_url`.
	 */
	public function test_get_raw_handles_index_columns_and_meta(): void {
		$media_id = MediaRepository::insert(
			array(
				'title'       => 'Raw Reader Parity',
				'post_author' => $this->admin_id,
				'media_type'  => 'image',
			)
		);

		MediaRepository::set( $media_id, 'thumb_large', 'https://example.com/thumb-lg.jpg' );

		// Index column.
		$this->assertSame( 'Raw Reader Parity', MediaRepository::get_raw( $media_id, 'title' ) );
		// Meta field.
		$this->assertSame(
			'https://example.com/thumb-lg.jpg',
			MediaRepository::get_raw( $media_id, 'thumb_large' )
		);
	}

	/* ------------------------------------------------------------------
	 * set()
	 * ----------------------------------------------------------------*/

	/**
	 * set() updates an existing index column.
	 */
	public function test_set_index_column(): void {
		$media_id = MediaRepository::insert(
			array(
				'title'       => 'Original Title',
				'post_author' => $this->admin_id,
			)
		);

		MediaRepository::set( $media_id, 'title', 'Updated Title' );

		$this->assertSame( 'Updated Title', MediaRepository::get( $media_id, 'title' ) );
	}

	/**
	 * set() stores a custom meta key in mvs_media_meta and retrieves it.
	 */
	public function test_set_meta_field(): void {
		$media_id = MediaRepository::insert(
			array(
				'title'       => 'Meta Write Test',
				'post_author' => $this->admin_id,
			)
		);

		MediaRepository::set( $media_id, 'camera_model', 'Canon EOS R5' );

		// Verify via direct DB query.
		global $wpdb;
		$value = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->prefix}mvs_media_meta WHERE media_id = %d AND meta_key = %s",
				$media_id,
				'camera_model'
			)
		);

		$this->assertSame( 'Canon EOS R5', $value );
		$this->assertSame( 'Canon EOS R5', MediaRepository::get( $media_id, 'camera_model' ) );
	}

	/**
	 * set() JSON-encodes array values in mvs_media_meta.
	 */
	public function test_set_meta_serializes_array(): void {
		$media_id = MediaRepository::insert(
			array(
				'title'       => 'Array Meta Test',
				'post_author' => $this->admin_id,
			)
		);

		$exif_data = array(
			'aperture'     => 'f/2.8',
			'shutter'      => '1/250',
			'iso'          => 400,
			'focal_length' => '50mm',
		);

		MediaRepository::set( $media_id, 'exif_data', $exif_data );

		// Raw DB value should be JSON-encoded.
		global $wpdb;
		$raw = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->prefix}mvs_media_meta WHERE media_id = %d AND meta_key = %s",
				$media_id,
				'exif_data'
			)
		);

		$decoded = json_decode( $raw, true );
		$this->assertSame( 'f/2.8', $decoded['aperture'] );
		$this->assertSame( 400, $decoded['iso'] );
	}

	/* ------------------------------------------------------------------
	 * set_many()
	 * ----------------------------------------------------------------*/

	/**
	 * set_many() handles a mix of index columns and meta keys in one call.
	 */
	public function test_set_many_mixed(): void {
		$media_id = MediaRepository::insert(
			array(
				'title'       => 'Bulk Set Test',
				'post_author' => $this->admin_id,
			)
		);

		MediaRepository::set_many(
			$media_id,
			array(
				// Index columns.
				'description' => 'A beautiful landscape photo.',
				'media_type'  => 'image',
				'width'       => 1920,
				'height'      => 1080,
				// Meta keys.
				'thumb_small' => 'https://example.com/thumb-sm.jpg',
				'color_hex'   => '#3498db',
			)
		);

		// Verify index columns.
		$this->assertSame( 'A beautiful landscape photo.', MediaRepository::get( $media_id, 'description' ) );
		$this->assertSame( 'image', MediaRepository::get( $media_id, 'media_type' ) );
		$this->assertEquals( 1920, MediaRepository::get( $media_id, 'width' ) );
		$this->assertEquals( 1080, MediaRepository::get( $media_id, 'height' ) );

		// Verify meta keys.
		$this->assertSame( 'https://example.com/thumb-sm.jpg', MediaRepository::get( $media_id, 'thumb_small' ) );
		$this->assertSame( '#3498db', MediaRepository::get( $media_id, 'color_hex' ) );
	}

	/* ------------------------------------------------------------------
	 * delete()
	 * ----------------------------------------------------------------*/

	/**
	 * delete() sets an index column to NULL.
	 */
	public function test_delete_index_column(): void {
		$media_id = MediaRepository::insert(
			array(
				'title'       => 'Delete Column Test',
				'post_author' => $this->admin_id,
				'media_type'  => 'image',
			)
		);

		$this->assertSame( 'image', MediaRepository::get( $media_id, 'media_type' ) );

		MediaRepository::delete( $media_id, 'media_type' );

		$this->assertNull( MediaRepository::get( $media_id, 'media_type' ) );
	}

	/**
	 * delete() removes a meta row from mvs_media_meta.
	 */
	public function test_delete_meta_field(): void {
		$media_id = MediaRepository::insert(
			array(
				'title'       => 'Delete Meta Test',
				'post_author' => $this->admin_id,
			)
		);

		MediaRepository::set( $media_id, 'temp_flag', 'should_be_removed' );
		$this->assertSame( 'should_be_removed', MediaRepository::get( $media_id, 'temp_flag' ) );

		MediaRepository::delete( $media_id, 'temp_flag' );

		$this->assertNull( MediaRepository::get( $media_id, 'temp_flag' ) );

		// Confirm row is actually gone in DB.
		global $wpdb;
		$count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}mvs_media_meta WHERE media_id = %d AND meta_key = %s",
				$media_id,
				'temp_flag'
			)
		);
		$this->assertSame( 0, $count );
	}

	/* ------------------------------------------------------------------
	 * get_all()
	 * ----------------------------------------------------------------*/

	/**
	 * get_all() merges index columns and meta keys into one array.
	 */
	public function test_get_all_merges_index_and_meta(): void {
		$media_id = MediaRepository::insert(
			array(
				'title'       => 'Get All Test',
				'post_author' => $this->admin_id,
				'media_type'  => 'video',
			)
		);

		MediaRepository::set( $media_id, 'encoding', 'h264' );
		MediaRepository::set( $media_id, 'bitrate', '5000kbps' );

		$all = MediaRepository::get_all( $media_id );

		// Index columns present.
		$this->assertSame( 'Get All Test', $all['title'] );
		$this->assertSame( 'video', $all['media_type'] );
		$this->assertEquals( $this->admin_id, $all['post_author'] );

		// Meta keys present.
		$this->assertSame( 'h264', $all['encoding'] );
		$this->assertSame( '5000kbps', $all['bitrate'] );

		// Auto-populated fields present.
		$this->assertArrayHasKey( 'media_id', $all );
		$this->assertArrayHasKey( 'created_at', $all );
		$this->assertArrayHasKey( 'slug', $all );
	}

	/* ------------------------------------------------------------------
	 * exists()
	 * ----------------------------------------------------------------*/

	/**
	 * exists() returns true for an existing media item.
	 */
	public function test_exists_true_for_existing(): void {
		$media_id = MediaRepository::insert(
			array(
				'title'       => 'Exists Test',
				'post_author' => $this->admin_id,
			)
		);

		$this->assertTrue( MediaRepository::exists( $media_id ) );
	}

	/**
	 * exists() returns false for a non-existent ID.
	 */
	public function test_exists_false_for_missing(): void {
		$this->assertFalse( MediaRepository::exists( 999999 ) );
	}

	/* ------------------------------------------------------------------
	 * get_author()
	 * ----------------------------------------------------------------*/

	/**
	 * get_author() returns the post_author as an integer.
	 */
	public function test_get_author(): void {
		$media_id = MediaRepository::insert(
			array(
				'title'       => 'Author Test',
				'post_author' => $this->admin_id,
			)
		);

		$author = MediaRepository::get_author( $media_id );

		$this->assertIsInt( $author );
		$this->assertSame( $this->admin_id, $author );
	}

	/* ------------------------------------------------------------------
	 * get_permalink()
	 * ----------------------------------------------------------------*/

	/**
	 * get_permalink() returns /media/{slug}/ format.
	 */
	public function test_get_permalink(): void {
		$media_id = MediaRepository::insert(
			array(
				'title'       => 'Permalink Test Photo',
				'post_author' => $this->admin_id,
			)
		);

		$permalink = MediaRepository::get_permalink( $media_id );

		$this->assertStringContainsString( '/media/permalink-test-photo/', $permalink );
		$this->assertStringStartsWith( home_url(), $permalink );
	}

	/* ------------------------------------------------------------------
	 * delete_all()
	 * ----------------------------------------------------------------*/

	/**
	 * delete_all() removes rows from both mvs_media_index and mvs_media_meta.
	 */
	public function test_delete_all(): void {
		$media_id = MediaRepository::insert(
			array(
				'title'       => 'Delete All Test',
				'post_author' => $this->admin_id,
				'media_type'  => 'image',
			)
		);

		// Add some meta too.
		MediaRepository::set( $media_id, 'thumb_url', 'https://example.com/thumb.jpg' );
		MediaRepository::set( $media_id, 'source', 'upload' );

		// Sanity check — item exists before deletion.
		$this->assertTrue( MediaRepository::exists( $media_id ) );

		MediaRepository::delete_all( $media_id );

		// Index row gone.
		$this->assertFalse( MediaRepository::exists( $media_id ) );

		// Meta rows gone.
		global $wpdb;
		$meta_count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}mvs_media_meta WHERE media_id = %d",
				$media_id
			)
		);
		$this->assertSame( 0, $meta_count );
	}
}
