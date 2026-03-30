<?php
/**
 * Test MediaMeta service (custom-table architecture).
 *
 * Replaces the old MediaCRUDTest which relied on wp_insert_post / CPTs.
 * All media data now lives in mvs_media_index + mvs_media_meta tables.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Services\MediaMeta;

class MediaMetaTest extends WP_UnitTestCase {

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
		$media_id = MediaMeta::insert(
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
		$media_id = MediaMeta::insert(
			array(
				'title'       => 'My Awesome Photo!',
				'post_author' => $this->admin_id,
			)
		);

		$slug = MediaMeta::get( $media_id, 'slug' );
		$this->assertSame( 'my-awesome-photo', $slug );
	}

	/**
	 * Two inserts with the same title get distinct slugs (second gets -1 suffix).
	 */
	public function test_insert_unique_slug_suffix(): void {
		$id1 = MediaMeta::insert(
			array(
				'title'       => 'Duplicate Title',
				'post_author' => $this->admin_id,
			)
		);
		$id2 = MediaMeta::insert(
			array(
				'title'       => 'Duplicate Title',
				'post_author' => $this->admin_id,
			)
		);

		$slug1 = MediaMeta::get( $id1, 'slug' );
		$slug2 = MediaMeta::get( $id2, 'slug' );

		$this->assertSame( 'duplicate-title', $slug1 );
		$this->assertSame( 'duplicate-title-1', $slug2 );
	}

	/**
	 * insert() applies default status, privacy, and moderation_status.
	 */
	public function test_insert_defaults(): void {
		$media_id = MediaMeta::insert(
			array(
				'title'       => 'Defaults Test',
				'post_author' => $this->admin_id,
			)
		);

		$this->assertSame( 'publish', MediaMeta::get( $media_id, 'status' ) );
		$this->assertSame( 'public', MediaMeta::get( $media_id, 'privacy' ) );
		$this->assertSame( 'approved', MediaMeta::get( $media_id, 'moderation_status' ) );
	}

	/* ------------------------------------------------------------------
	 * get()
	 * ----------------------------------------------------------------*/

	/**
	 * get() retrieves an index column set during insert.
	 */
	public function test_get_index_column(): void {
		$media_id = MediaMeta::insert(
			array(
				'title'       => 'Mountain View',
				'post_author' => $this->admin_id,
				'media_type'  => 'image',
			)
		);

		$this->assertSame( 'Mountain View', MediaMeta::get( $media_id, 'title' ) );
		$this->assertSame( 'image', MediaMeta::get( $media_id, 'media_type' ) );
	}

	/**
	 * get() retrieves a meta (non-index) field via mvs_media_meta.
	 */
	public function test_get_meta_field(): void {
		$media_id = MediaMeta::insert(
			array(
				'title'       => 'Meta Field Test',
				'post_author' => $this->admin_id,
			)
		);

		MediaMeta::set( $media_id, 'thumb_large', 'https://example.com/thumb-lg.jpg' );

		$this->assertSame(
			'https://example.com/thumb-lg.jpg',
			MediaMeta::get( $media_id, 'thumb_large' )
		);
	}

	/**
	 * get() returns null for a non-existent media_id.
	 */
	public function test_get_nonexistent_returns_null(): void {
		$this->assertNull( MediaMeta::get( 999999, 'title' ) );
		$this->assertNull( MediaMeta::get( 999999, 'some_custom_key' ) );
	}

	/* ------------------------------------------------------------------
	 * set()
	 * ----------------------------------------------------------------*/

	/**
	 * set() updates an existing index column.
	 */
	public function test_set_index_column(): void {
		$media_id = MediaMeta::insert(
			array(
				'title'       => 'Original Title',
				'post_author' => $this->admin_id,
			)
		);

		MediaMeta::set( $media_id, 'title', 'Updated Title' );

		$this->assertSame( 'Updated Title', MediaMeta::get( $media_id, 'title' ) );
	}

	/**
	 * set() stores a custom meta key in mvs_media_meta and retrieves it.
	 */
	public function test_set_meta_field(): void {
		$media_id = MediaMeta::insert(
			array(
				'title'       => 'Meta Write Test',
				'post_author' => $this->admin_id,
			)
		);

		MediaMeta::set( $media_id, 'camera_model', 'Canon EOS R5' );

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
		$this->assertSame( 'Canon EOS R5', MediaMeta::get( $media_id, 'camera_model' ) );
	}

	/**
	 * set() JSON-encodes array values in mvs_media_meta.
	 */
	public function test_set_meta_serializes_array(): void {
		$media_id = MediaMeta::insert(
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

		MediaMeta::set( $media_id, 'exif_data', $exif_data );

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
		$media_id = MediaMeta::insert(
			array(
				'title'       => 'Bulk Set Test',
				'post_author' => $this->admin_id,
			)
		);

		MediaMeta::set_many(
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
		$this->assertSame( 'A beautiful landscape photo.', MediaMeta::get( $media_id, 'description' ) );
		$this->assertSame( 'image', MediaMeta::get( $media_id, 'media_type' ) );
		$this->assertEquals( 1920, MediaMeta::get( $media_id, 'width' ) );
		$this->assertEquals( 1080, MediaMeta::get( $media_id, 'height' ) );

		// Verify meta keys.
		$this->assertSame( 'https://example.com/thumb-sm.jpg', MediaMeta::get( $media_id, 'thumb_small' ) );
		$this->assertSame( '#3498db', MediaMeta::get( $media_id, 'color_hex' ) );
	}

	/* ------------------------------------------------------------------
	 * delete()
	 * ----------------------------------------------------------------*/

	/**
	 * delete() sets an index column to NULL.
	 */
	public function test_delete_index_column(): void {
		$media_id = MediaMeta::insert(
			array(
				'title'       => 'Delete Column Test',
				'post_author' => $this->admin_id,
				'media_type'  => 'image',
			)
		);

		$this->assertSame( 'image', MediaMeta::get( $media_id, 'media_type' ) );

		MediaMeta::delete( $media_id, 'media_type' );

		$this->assertNull( MediaMeta::get( $media_id, 'media_type' ) );
	}

	/**
	 * delete() removes a meta row from mvs_media_meta.
	 */
	public function test_delete_meta_field(): void {
		$media_id = MediaMeta::insert(
			array(
				'title'       => 'Delete Meta Test',
				'post_author' => $this->admin_id,
			)
		);

		MediaMeta::set( $media_id, 'temp_flag', 'should_be_removed' );
		$this->assertSame( 'should_be_removed', MediaMeta::get( $media_id, 'temp_flag' ) );

		MediaMeta::delete( $media_id, 'temp_flag' );

		$this->assertNull( MediaMeta::get( $media_id, 'temp_flag' ) );

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
		$media_id = MediaMeta::insert(
			array(
				'title'       => 'Get All Test',
				'post_author' => $this->admin_id,
				'media_type'  => 'video',
			)
		);

		MediaMeta::set( $media_id, 'encoding', 'h264' );
		MediaMeta::set( $media_id, 'bitrate', '5000kbps' );

		$all = MediaMeta::get_all( $media_id );

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
		$media_id = MediaMeta::insert(
			array(
				'title'       => 'Exists Test',
				'post_author' => $this->admin_id,
			)
		);

		$this->assertTrue( MediaMeta::exists( $media_id ) );
	}

	/**
	 * exists() returns false for a non-existent ID.
	 */
	public function test_exists_false_for_missing(): void {
		$this->assertFalse( MediaMeta::exists( 999999 ) );
	}

	/* ------------------------------------------------------------------
	 * get_author()
	 * ----------------------------------------------------------------*/

	/**
	 * get_author() returns the post_author as an integer.
	 */
	public function test_get_author(): void {
		$media_id = MediaMeta::insert(
			array(
				'title'       => 'Author Test',
				'post_author' => $this->admin_id,
			)
		);

		$author = MediaMeta::get_author( $media_id );

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
		$media_id = MediaMeta::insert(
			array(
				'title'       => 'Permalink Test Photo',
				'post_author' => $this->admin_id,
			)
		);

		$permalink = MediaMeta::get_permalink( $media_id );

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
		$media_id = MediaMeta::insert(
			array(
				'title'       => 'Delete All Test',
				'post_author' => $this->admin_id,
				'media_type'  => 'image',
			)
		);

		// Add some meta too.
		MediaMeta::set( $media_id, 'thumb_url', 'https://example.com/thumb.jpg' );
		MediaMeta::set( $media_id, 'source', 'upload' );

		// Sanity check — item exists before deletion.
		$this->assertTrue( MediaMeta::exists( $media_id ) );

		MediaMeta::delete_all( $media_id );

		// Index row gone.
		$this->assertFalse( MediaMeta::exists( $media_id ) );

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
