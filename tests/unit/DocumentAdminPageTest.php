<?php
/**
 * Phase 6 — the Documents admin screen.
 *
 * Two things are worth a test here and the rest is markup: the listing must
 * stay a document listing under every filter, and the action guard must refuse
 * a media id. The second is the one that matters — the screen never lists a
 * photo, so nothing on it would ever reveal that its actions could delete one.
 *
 * Build plan: P6.1, P6.2.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Admin\DocumentListPage;
use WPMediaVerse\Core\Plugin;

/**
 * @since 2.4.0
 */
class DocumentAdminPageTest extends WP_UnitTestCase {

	/**
	 * Repository.
	 *
	 * @var \WPMediaVerse\Repository\MediaRepository
	 */
	private $repo;

	/**
	 * Author.
	 *
	 * @var int
	 */
	private $author;

	/**
	 * Set up.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->repo   = Plugin::container()->get( 'media_repository' );
		$this->author = self::factory()->user->create( array( 'role' => 'subscriber' ) );
	}

	/**
	 * Insert an index row through the repository.
	 *
	 * @param string $media_type Media type.
	 * @param string $mime       MIME type.
	 * @param string $title      Title.
	 * @param string $privacy    Privacy.
	 * @return int Media id.
	 */
	private function row( string $media_type, string $mime, string $title = 'Row', string $privacy = 'public' ): int {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'mvs_media_index',
			array(
				'title'             => $title,
				'slug'              => sanitize_title( $title ) . '-' . wp_rand( 1000, 9999 ),
				'post_author'       => $this->author,
				'media_type'        => $media_type,
				'file_type'         => $mime,
				'file_path'         => 'x/y.bin',
				'file_size'         => 1024,
				'privacy'           => $privacy,
				'status'            => 'publish',
				'moderation_status' => 'approved',
				'created_at'        => current_time( 'mysql', true ),
			)
		);

		return (int) $wpdb->insert_id;
	}

	// ------------------------------------------------------------- listing --

	/**
	 * The admin listing returns documents and NEVER media.
	 */
	public function test_listing_contains_only_documents(): void {
		$this->row( 'image', 'image/jpeg', 'A photo' );
		$this->row( 'video', 'video/mp4', 'A video' );
		$doc = $this->row( 'document', 'application/pdf', 'A report' );

		$result = $this->repo->admin_documents( array( 'per_page' => 50 ) );
		$ids    = array_map( static fn( $r ) => (int) $r['media_id'], $result['items'] );

		$this->assertContains( $doc, $ids );

		foreach ( $result['items'] as $item ) {
			$this->assertContains(
				$item['media_type'],
				array( 'document', 'legacy_document' ),
				'A media row must never reach the Documents screen.'
			);
		}
	}

	/**
	 * Legacy documents are listed too — they are the reason the screen exists
	 * on a site that upgraded rather than started fresh.
	 */
	public function test_legacy_documents_are_listed(): void {
		$legacy = $this->row( 'legacy_document', 'application/pdf', 'An old file' );

		$ids = array_map(
			static fn( $r ) => (int) $r['media_id'],
			$this->repo->admin_documents( array( 'per_page' => 50 ) )['items']
		);

		$this->assertContains( $legacy, $ids );
	}

	/**
	 * The total is a real COUNT, not the size of the page.
	 */
	public function test_total_is_the_full_count_not_the_page(): void {
		for ( $i = 0; $i < 7; $i++ ) {
			$this->row( 'document', 'application/pdf', "Doc {$i}" );
		}

		$page = $this->repo->admin_documents( array( 'per_page' => 3, 'page' => 1 ) );

		$this->assertCount( 3, $page['items'], 'The page is capped.' );
		$this->assertGreaterThanOrEqual( 7, $page['total'], 'The total counts every match.' );
		$this->assertSame( (int) ceil( $page['total'] / 3 ), $page['pages'] );
	}

	/**
	 * An unknown type filter matches nothing rather than everything.
	 *
	 * Failing open here would show every document under a label the admin chose
	 * in order to narrow — the worst possible answer, because it looks right.
	 */
	public function test_unknown_type_filter_matches_nothing(): void {
		$this->row( 'document', 'application/pdf', 'A report' );

		$result = $this->repo->admin_documents( array( 'doc_type' => 'not-a-type' ) );

		$this->assertSame( 0, $result['total'] );
		$this->assertSame( array(), $result['items'] );
	}

	/**
	 * The type filter narrows to that type only.
	 */
	public function test_type_filter_narrows(): void {
		$pdf = $this->row( 'document', 'application/pdf', 'A report' );
		$this->row( 'document', 'text/csv', 'A sheet' );

		$result = $this->repo->admin_documents( array( 'doc_type' => 'pdf' ) );
		$ids    = array_map( static fn( $r ) => (int) $r['media_id'], $result['items'] );

		$this->assertContains( $pdf, $ids );

		foreach ( $result['items'] as $item ) {
			$this->assertSame( 'application/pdf', $item['file_type'] );
		}
	}

	/**
	 * Privacy filtering works, and the admin screen is NOT privacy-scoped by
	 * default — the site owner sees private documents too.
	 */
	public function test_admin_sees_private_documents(): void {
		$private = $this->row( 'document', 'application/pdf', 'Private report', 'private' );

		$all = array_map(
			static fn( $r ) => (int) $r['media_id'],
			$this->repo->admin_documents( array( 'per_page' => 50 ) )['items']
		);

		$this->assertContains( $private, $all, 'The owner of the site can see every document.' );

		$filtered = $this->repo->admin_documents( array( 'privacy' => 'private' ) );
		foreach ( $filtered['items'] as $item ) {
			$this->assertSame( 'private', $item['privacy'] );
		}
	}

	// -------------------------------------------------------- action guard --

	/**
	 * THE guard: a media id is never actionable from the Documents screen.
	 *
	 * The screen never lists a photo, so nothing about it would suggest
	 * `?action=delete&media_id=<a photo>` reaches anything.
	 */
	public function test_media_is_not_actionable_from_the_documents_screen(): void {
		$photo = $this->row( 'image', 'image/jpeg', 'A photo' );
		$video = $this->row( 'video', 'video/mp4', 'A video' );

		$this->assertFalse( DocumentListPage::is_document( $photo ) );
		$this->assertFalse( DocumentListPage::is_document( $video ) );
	}

	/**
	 * Both document types ARE actionable.
	 */
	public function test_documents_are_actionable(): void {
		$this->assertTrue( DocumentListPage::is_document( $this->row( 'document', 'application/pdf' ) ) );
		$this->assertTrue( DocumentListPage::is_document( $this->row( 'legacy_document', 'application/pdf' ) ) );
	}

	/**
	 * A missing or nonsense id is refused rather than treated as absent-and-fine.
	 */
	public function test_missing_ids_are_refused(): void {
		$this->assertFalse( DocumentListPage::is_document( 0 ) );
		$this->assertFalse( DocumentListPage::is_document( -1 ) );
		$this->assertFalse( DocumentListPage::is_document( 99999999 ) );
	}

	// --------------------------------------------------------- aggregates --

	/**
	 * P6.3 / P1.6: media + documents account for EVERY published index row.
	 *
	 * This is the check that makes the split honest. "Total Media" stopped
	 * counting documents, so if the documents card were ever wrong — or absent —
	 * rows would simply vanish from the admin's view of the site with nothing to
	 * reveal it. Any third media_type added later without its own card fails
	 * here, which is the point.
	 */
	public function test_media_and_documents_account_for_every_row(): void {
		global $wpdb;

		$this->row( 'image', 'image/jpeg', 'A photo' );
		$this->row( 'video', 'video/mp4', 'A video' );
		$this->row( 'audio', 'audio/mpeg', 'A track' );
		$this->row( 'document', 'application/pdf', 'A report' );
		$this->row( 'legacy_document', 'application/pdf', 'An old file' );

		$aggregates = Plugin::container()->get( 'admin_aggregates' );

		// remember_persistent caches, and these rows were written after the
		// first call in this process would have run.
		wp_cache_flush();

		$media     = $aggregates->total_media();
		$documents = $aggregates->total_documents();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}mvs_media_index WHERE status = 'publish'" );

		$this->assertSame(
			$rows,
			$media + $documents,
			'Every published row belongs to exactly one of the two counts.'
		);
	}

	/**
	 * Total Media does NOT count documents.
	 */
	public function test_total_media_excludes_documents(): void {
		$aggregates = Plugin::container()->get( 'admin_aggregates' );

		wp_cache_flush();
		$before = $aggregates->total_media();

		$this->row( 'document', 'application/pdf', 'A report' );
		$this->row( 'legacy_document', 'application/pdf', 'An old file' );

		wp_cache_flush();

		$this->assertSame( $before, $aggregates->total_media(), 'Documents must not inflate the media count.' );
	}

	/**
	 * Deleting a document must move the cached counts.
	 *
	 * `AdminAggregatesService::CACHE_KEYS` is the canonical list that
	 * `CacheService::on_admin_aggregate_change()` iterates, and its own comment
	 * says a new aggregate needs a new entry. Phase 6 added two cached keys and
	 * added neither — so both counts went stale on the first write and stayed
	 * that way until something unrelated flushed the cache. The Overview
	 * screen read 11 documents while the admin list showed 6.
	 *
	 * The failure mode is silent by construction: the number is plausible, just
	 * old. This test is the thing that makes it loud.
	 */
	public function test_deleting_a_document_invalidates_the_cached_counts(): void {
		$aggregates = Plugin::container()->get( 'admin_aggregates' );

		$doc = $this->row( 'document', 'application/pdf', 'Doomed report' );

		// Warm both caches.
		$documents_before = $aggregates->total_documents();
		$media_before     = $aggregates->total_media();

		$this->repo->delete_cascade( $doc );

		$this->assertSame(
			$documents_before - 1,
			$aggregates->total_documents(),
			'The cached document count must follow a delete.'
		);
		$this->assertSame(
			$media_before,
			$aggregates->total_media(),
			'Deleting a document must not change the media count.'
		);
	}

	/**
	 * Every aggregate this service caches is listed for invalidation.
	 *
	 * Guards the class of mistake rather than the instance: a new cached key
	 * that nobody adds to CACHE_KEYS is a number that never updates again.
	 */
	public function test_every_cached_aggregate_is_registered_for_invalidation(): void {
		$source = file_get_contents( MVS_PLUGIN_DIR . 'includes/Services/AdminAggregatesService.php' );

		preg_match_all( "/remember_persistent\(\s*'([a-z0-9_]+)'/", (string) $source, $matches );

		$this->assertNotEmpty( $matches[1], 'The scan must find the cached keys.' );

		foreach ( array_unique( $matches[1] ) as $key ) {
			$this->assertContains(
				$key,
				\WPMediaVerse\Services\AdminAggregatesService::CACHE_KEYS,
				"'{$key}' is cached but not listed in CACHE_KEYS, so nothing will ever invalidate it."
			);
		}
	}
}
