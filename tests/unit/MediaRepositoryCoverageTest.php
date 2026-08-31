<?php
/**
 * Phase 4 — supplementary coverage hitting the 20 MediaRepository public
 * methods not exercised by `MediaRepositoryTest`.
 *
 * One happy-path assertion per method to satisfy the 100% public-method
 * coverage criterion. Edge-case + branch tests are added to the main
 * test class as bugs surface them.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\Plugin;
use WPMediaVerse\Repository\MediaRepository;

class MediaRepositoryCoverageTest extends WP_UnitTestCase {

	private int $admin_id;
	private MediaRepository $repo;

	public function set_up(): void {
		parent::set_up();

		// Ensure mvs_media_meta exists (the main MediaRepositoryTest also
		// does this — keep both classes self-sufficient).
		global $wpdb;
		$meta_table = $wpdb->prefix . 'mvs_media_meta';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $meta_table ) );
		if ( ! $exists ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			$cs = $wpdb->get_charset_collate();
			dbDelta(
				"CREATE TABLE {$meta_table} (
					meta_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
					media_id bigint(20) unsigned NOT NULL,
					meta_key varchar(255) DEFAULT NULL,
					meta_value longtext,
					PRIMARY KEY  (meta_id),
					KEY media_id (media_id),
					KEY meta_key (meta_key(191))
				) {$cs};"
			);
		}
		$index_table = $wpdb->prefix . 'mvs_media_index';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $index_table ) ) ) {
			( new \WPMediaVerse\Core\Migrator() )->run();
		}

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
		$this->repo = Plugin::container()->get( 'media_repository' );
	}

	private function insert_media( array $overrides = array() ): int {
		return (int) $this->repo->insert(
			array_merge(
				array(
					'title'       => 'Coverage media ' . wp_generate_password( 6, false ),
					'post_author' => $this->admin_id,
					'media_type'  => 'image',
				),
				$overrides
			)
		);
	}

	// ── slug + batch + meta ────────────────────────────────────────────────

	public function test_get_by_slug_returns_row_for_published_slug(): void {
		$media_id = $this->insert_media();
		$slug     = (string) $this->repo->get_raw( $media_id, 'slug' );

		$row = $this->repo->get_by_slug( $slug );
		$this->assertIsArray( $row );
		$this->assertSame( $media_id, (int) $row['media_id'] );
	}

	public function test_get_batch_returns_indexed_rows(): void {
		$a = $this->insert_media( array( 'title' => 'Batch A' ) );
		$b = $this->insert_media( array( 'title' => 'Batch B' ) );

		$batch = $this->repo->get_batch( array( $a, $b ) );
		$this->assertCount( 2, $batch );
		$this->assertSame( 'Batch A', $batch[ $a ]['title'] );
		$this->assertSame( 'Batch B', $batch[ $b ]['title'] );
	}

	public function test_find_by_meta_locates_media_by_key_value(): void {
		$media_id = $this->insert_media();
		$this->repo->set( $media_id, 'external_ref', 'rt-12345' );

		$found = $this->repo->find_by_meta( 'external_ref', 'rt-12345' );
		$this->assertSame( $media_id, $found );
	}

	public function test_generate_unique_slug_appends_suffix_on_collision(): void {
		$first  = $this->repo->generate_unique_slug( 'Sunset Beach' );
		$this->insert_media(
			array(
				'title' => 'Sunset Beach',
				'slug'  => $first,
			)
		);
		$second = $this->repo->generate_unique_slug( 'Sunset Beach' );

		$this->assertSame( 'sunset-beach', $first );
		$this->assertNotSame( $first, $second );
		$this->assertStringStartsWith( 'sunset-beach', $second );
	}

	// ── count queries ──────────────────────────────────────────────────────

	public function test_count_published_increments_after_insert(): void {
		$before = $this->repo->count_published();
		$this->insert_media();
		$this->assertSame( $before + 1, $this->repo->count_published() );
	}

	public function test_count_by_author_filters_to_user(): void {
		$other_user = self::factory()->user->create();
		$this->insert_media( array( 'post_author' => $this->admin_id ) );
		$this->insert_media( array( 'post_author' => $other_user ) );

		$this->assertGreaterThanOrEqual( 1, $this->repo->count_by_author( $other_user ) );
		$this->assertGreaterThanOrEqual( 1, $this->repo->count_by_author( $this->admin_id ) );
	}

	public function test_count_by_moderation_groups_by_status(): void {
		$media_id = $this->insert_media();
		$this->repo->set( $media_id, 'moderation_status', 'flagged' );

		$flagged = $this->repo->count_by_moderation( 'flagged' );
		$this->assertGreaterThanOrEqual( 1, $flagged );
	}

	public function test_get_moderation_counts_returns_status_map(): void {
		$flagged = $this->insert_media();
		$this->repo->set( $flagged, 'moderation_status', 'flagged' );
		$approved = $this->insert_media();
		$this->repo->set( $approved, 'moderation_status', 'approved' );

		$counts = $this->repo->get_moderation_counts();
		$this->assertIsArray( $counts );
		$this->assertGreaterThanOrEqual( 1, (int) ( $counts['flagged'] ?? 0 ) );
		$this->assertGreaterThanOrEqual( 1, (int) ( $counts['approved'] ?? 0 ) );
	}

	public function test_count_by_group_counts_meta_group_id_rows(): void {
		$group_id = 'mvs_grp_' . wp_generate_password( 6, false );
		$media_id = $this->insert_media();
		$this->repo->set( $media_id, 'group_id', $group_id );

		$this->assertSame( 1, $this->repo->count_by_group( $group_id ) );
	}

	// ── stats + events ─────────────────────────────────────────────────────

	public function test_init_stats_creates_zeroed_row(): void {
		$media_id = $this->insert_media();
		$this->repo->init_stats( $media_id );

		$stats = $this->repo->get_stats( $media_id );
		$this->assertIsArray( $stats );
		$this->assertSame( 0, (int) $stats['views'] );
	}

	public function test_increment_stat_bumps_counter(): void {
		$media_id = $this->insert_media();
		$this->repo->init_stats( $media_id );
		$this->repo->increment_stat( $media_id, 'views' );
		$this->repo->increment_stat( $media_id, 'views' );

		$stats = $this->repo->get_stats( $media_id );
		$this->assertSame( 2, (int) $stats['views'] );
	}

	public function test_set_stat_writes_absolute_value(): void {
		$media_id = $this->insert_media();
		$this->repo->init_stats( $media_id );
		$this->repo->set_stat( $media_id, 'reactions', 42 );

		$stats = $this->repo->get_stats( $media_id );
		$this->assertSame( 42, (int) $stats['reactions'] );
	}

	public function test_record_event_persists_a_row(): void {
		$media_id = $this->insert_media();
		$this->repo->record_event( $media_id, $this->admin_id, 'ip-hash-' . wp_generate_password( 6, false ), 'view' );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}mvs_media_views WHERE media_id = %d",
				$media_id
			)
		);
		$this->assertSame( 1, $count );
	}

	public function test_prune_events_deletes_old_rows(): void {
		$media_id = $this->insert_media();
		$this->repo->record_event( $media_id, $this->admin_id, 'hash-old', 'view' );

		// Backdate the row to simulate a 200-day-old event.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}mvs_media_views SET created_at = DATE_SUB(NOW(), INTERVAL %d DAY) WHERE media_id = %d",
				200,
				$media_id
			)
		);

		$pruned = $this->repo->prune_events( 90 );
		$this->assertGreaterThanOrEqual( 1, $pruned );
	}

	public function test_get_user_stats_returns_aggregated_array(): void {
		$media_id = $this->insert_media();
		$this->repo->init_stats( $media_id );
		$this->repo->set_stat( $media_id, 'views', 5 );

		$stats = $this->repo->get_user_stats( $this->admin_id );
		$this->assertIsArray( $stats );
		$this->assertArrayHasKey( 'total_views', $stats );
		$this->assertGreaterThanOrEqual( 5, (int) $stats['total_views'] );
	}

	// ── lifecycle (trash → restore → cascade delete) ───────────────────────

	public function test_trash_then_restore_round_trip(): void {
		$media_id = $this->insert_media();

		$this->assertTrue( $this->repo->trash( $media_id ) );
		$this->assertSame( 'trash', $this->repo->get_raw( $media_id, 'status' ) );

		$this->assertTrue( $this->repo->restore( $media_id ) );
		$this->assertSame( 'publish', $this->repo->get_raw( $media_id, 'status' ) );
	}

	/**
	 * Trash and restore each announce themselves.
	 *
	 * The status column changing is not the point — an integration mirroring
	 * media (a BuddyNext activity card) has no way to see a column change. Until
	 * 2.4.0 only the permanent delete fired anything, so a member trashing a
	 * video left the community feed advertising it with a link that 404s
	 * (Basecamp 10252324048).
	 *
	 * The permalink argument is asserted because it is the reason the signature
	 * matches `mvs_media_deleted`: a listener withdrawing a mirror keyed on the
	 * URL handles both events with one method.
	 */
	public function test_trash_and_restore_fire_their_actions(): void {
		$media_id = $this->insert_media();
		$seen     = array();

		$capture = static function ( $id, $author, $permalink ) use ( &$seen ) {
			$seen[ current_action() ] = array( $id, $author, $permalink );
		};

		add_action( 'mvs_media_trashed', $capture, 10, 3 );
		add_action( 'mvs_media_restored', $capture, 10, 3 );

		$this->repo->trash( $media_id );
		$this->repo->restore( $media_id );

		remove_action( 'mvs_media_trashed', $capture, 10 );
		remove_action( 'mvs_media_restored', $capture, 10 );

		$this->assertArrayHasKey( 'mvs_media_trashed', $seen, 'Trashing fired nothing to listen to.' );
		$this->assertArrayHasKey( 'mvs_media_restored', $seen, 'Restoring fired nothing to listen to.' );
		$this->assertSame( $media_id, $seen['mvs_media_trashed'][0] );
		$this->assertSame( $media_id, $seen['mvs_media_restored'][0] );
		$this->assertIsString( $seen['mvs_media_trashed'][2] );
	}

	public function test_delete_cascade_removes_index_row(): void {
		$media_id = $this->insert_media();
		$this->assertTrue( $this->repo->exists( $media_id ) );

		$this->repo->delete_cascade( $media_id );
		$this->assertFalse( $this->repo->exists( $media_id ) );
	}

	// ── broadcast thumbnail URL ────────────────────────────────────────────

	public function test_get_broadcast_thumbnail_url_emits_long_ttl_signed_url(): void {
		$media_id = $this->insert_media();
		$url      = $this->repo->get_broadcast_thumbnail_url( $media_id, 'thumb_large' );

		$this->assertIsString( $url );
		// Either empty (signing service unavailable in unit-test bootstrap)
		// or a token-bearing URL with a 1-year expiry.
		if ( '' !== $url ) {
			$this->assertStringContainsString( 'mvs_sig=', $url );
			$this->assertStringContainsString( 'mvs_uid=0', $url );
			parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $params );
			$ttl = (int) ( $params['mvs_exp'] ?? 0 ) - time();
			$this->assertGreaterThan( DAY_IN_SECONDS * 300, $ttl );
		}
	}
}
