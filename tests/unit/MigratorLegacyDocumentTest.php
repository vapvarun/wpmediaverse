<?php
/**
 * Phase 2 — Migrator v27: legacy quarantine + document schema.
 *
 * The quarantine is the one destructive-ish step in the document work: it
 * rewrites `media_type` on existing member content. These tests pin the two
 * properties that make it safe -- it separates the pre-1.2.3 catch-all rows from
 * real documents, and it cannot run twice.
 *
 * Build plan: plan/document-library-build.md P2.2
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\MediaTypes;
use WPMediaVerse\Core\Migrator;
use WPMediaVerse\Core\Plugin;

/**
 * @since 2.4.0
 */
class MigratorLegacyDocumentTest extends WP_UnitTestCase {

	/**
	 * Media index table name.
	 *
	 * @var string
	 */
	private $index;

	/**
	 * Set up.
	 */
	public function set_up(): void {
		parent::set_up();

		global $wpdb;
		$this->index = $wpdb->prefix . 'mvs_media_index';

		// Model a site that has not yet taken v27.
		delete_option( Migrator::LEGACY_QUARANTINE_OPTION );
	}

	/**
	 * Insert through the repository so AUTO_INCREMENT assigns the id.
	 *
	 * @param string $type media_type value.
	 * @return int New media id.
	 */
	private function insert_row( string $type ): int {
		return (int) Plugin::container()->get( 'media_repository' )->insert(
			array(
				'title'             => 'v27 ' . $type . ' ' . wp_rand( 1000, 9999 ),
				'slug'              => 'v27-' . $type . '-' . wp_rand( 100000, 999999 ),
				'post_author'       => 1,
				'status'            => 'publish',
				'moderation_status' => 'approved',
				'privacy'           => 'public',
				'media_type'        => $type,
				'file_type'         => 'document' === $type ? 'application/pdf' : $type . '/generic',
				'file_path'         => '2026/08/v27-' . wp_rand( 1000, 9999 ) . '.bin',
			)
		);
	}

	/**
	 * Read a row's stored media_type straight from the table.
	 *
	 * @param int $media_id Media id.
	 * @return string
	 */
	private function stored_type( int $media_id ): string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return (string) $wpdb->get_var(
			$wpdb->prepare( "SELECT media_type FROM {$this->index} WHERE media_id = %d", $media_id )
		);
	}

	/**
	 * Run only v27, the way the version gate would.
	 */
	private function run_v27(): void {
		// No setAccessible() — it has been a no-op since PHP 8.1 and emits a
		// deprecation on 8.5, which would spray noise through every suite run.
		$migrator = new Migrator();
		$method   = new \ReflectionMethod( Migrator::class, 'migrate_to_27' );
		$method->invoke( $migrator );
	}

	/**
	 * The catch-all rows are re-typed; real media is untouched.
	 */
	public function test_quarantine_retypes_catch_all_rows_only(): void {
		$legacy = $this->insert_row( 'document' );
		$photo  = $this->insert_row( 'image' );

		$this->run_v27();

		$this->assertSame( MediaTypes::LEGACY_DOCUMENT, $this->stored_type( $legacy ) );
		$this->assertSame( 'image', $this->stored_type( $photo ), 'v27 touched a real media row.' );
	}

	/**
	 * Quarantined rows move to the DOCUMENT library, not out of existence.
	 *
	 * They leave the media grid — where a PDF can only draw as a broken tile —
	 * and are listed by the document surfaces, which render a row with a type
	 * chip. No drive claims them: they never passed DocumentTypes::resolve().
	 */
	public function test_quarantined_rows_move_to_the_document_library(): void {
		$legacy = $this->insert_row( 'document' );

		$this->run_v27();

		$this->assertNotContains(
			MediaTypes::LEGACY_DOCUMENT,
			MediaTypes::MEDIA_LIBRARY,
			'A quarantined PDF in a media grid renders as a broken tile (owner, 2026-08-09).'
		);
		$this->assertContains(
			MediaTypes::LEGACY_DOCUMENT,
			MediaTypes::DOCUMENT_LIBRARY,
			'It is relocated, not hidden — the document page draws it as a row with a type chip.'
		);
		$this->assertNotContains(
			MediaTypes::LEGACY_DOCUMENT,
			MediaTypes::DOCUMENTS,
			'A quarantined row was never a real document; no drive may claim it.'
		);
		$this->assertSame( MediaTypes::LEGACY_DOCUMENT, $this->stored_type( $legacy ) );
	}

	/**
	 * Re-running is a no-op — and this is the case that matters.
	 *
	 * Once the document library ships, `media_type='document'` means a real member
	 * document. A second quarantine pass would re-type all of them as legacy and
	 * silently empty every drive on the site.
	 */
	public function test_second_run_never_touches_real_documents(): void {
		$legacy = $this->insert_row( 'document' );
		$this->run_v27();
		$this->assertSame( MediaTypes::LEGACY_DOCUMENT, $this->stored_type( $legacy ) );

		// A genuine document, created after the library exists.
		$real = $this->insert_row( 'document' );

		$this->run_v27();

		$this->assertSame(
			'document',
			$this->stored_type( $real ),
			'The second run quarantined a real document — every drive on the site would empty.'
		);
	}

	/**
	 * The quarantine records what it did.
	 */
	public function test_quarantine_records_its_own_run(): void {
		$this->insert_row( 'document' );
		$this->insert_row( 'document' );

		$this->run_v27();

		$record = get_option( Migrator::LEGACY_QUARANTINE_OPTION );
		$this->assertIsArray( $record );
		$this->assertArrayHasKey( 'rows', $record );
		$this->assertArrayHasKey( 'at', $record );
		$this->assertGreaterThanOrEqual( 2, (int) $record['rows'] );
	}

	/**
	 * The schema the drive needs is in place, and adding it twice is safe.
	 */
	public function test_adds_folder_id_and_indexes_idempotently(): void {
		global $wpdb;

		$this->run_v27();
		$this->run_v27();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$this->index}" );
		$this->assertContains( 'folder_id', $columns );

		foreach ( array( 'doc_listing', 'type_file' ) as $key ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( "SHOW INDEX FROM {$this->index} WHERE Key_name = %s", $key ) );
			$this->assertNotEmpty( $rows, "Index {$key} is missing." );
		}

		// doc_listing must be the drive query's column order, left to right, or it
		// cannot serve that query as a prefix.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$cols = $wpdb->get_col( $wpdb->prepare( "SHOW INDEX FROM {$this->index} WHERE Key_name = %s", 'doc_listing' ), 4 );
		$this->assertSame( array( 'media_type', 'folder_id', 'status', 'created_at' ), $cols );
	}

	/**
	 * folder_id defaults to 0 — the virtual drive root.
	 */
	public function test_folder_id_defaults_to_the_virtual_root(): void {
		$this->run_v27();

		global $wpdb;
		$media_id = $this->insert_row( 'image' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$folder_id = $wpdb->get_var( $wpdb->prepare( "SELECT folder_id FROM {$this->index} WHERE media_id = %d", $media_id ) );

		$this->assertSame( '0', (string) $folder_id );
	}
}
