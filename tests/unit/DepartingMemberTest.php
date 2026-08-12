<?php
/**
 * T1 — what a departing member takes with them, and what they leave behind.
 *
 * Regression coverage for the bug this file was written after: the team-drive
 * query tested `drive_id > 0` and treated `drive_id = 0` as "personal". Zero
 * actually means "Migrator v29 has not stamped this row yet". A personal
 * document is stamped `drive_type = user, drive_id = <author id>`, so the author
 * id — always > 0 — made every personal file look like a team file. On a real
 * drive, 58 of 58 personal documents were classified as team, which on account
 * deletion would have handed them all to an administrator instead of erasing
 * them: the inverse of T1, and a GDPR erasure failure.
 *
 * The two directions matter equally and fail in opposite ways, so both are
 * asserted here rather than only the one that was broken:
 *
 *   personal -> DELETED, or erasure is incomplete
 *   team     -> REASSIGNED, or the team loses its files when someone leaves
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\Plugin;
use WPMediaVerse\Services\UserDeletionService;

class DepartingMemberTest extends WP_UnitTestCase {

	/**
	 * The member who leaves.
	 *
	 * @var int
	 */
	private int $leaver;

	/**
	 * The member who inherits the team file.
	 *
	 * @var int
	 */
	private int $successor;

	public function set_up(): void {
		parent::set_up();

		$this->leaver    = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->successor = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		( new UserDeletionService() )->init();

		require_once ABSPATH . 'wp-admin/includes/user.php';
	}

	/**
	 * Insert one document row directly.
	 *
	 * Written to the index rather than through the ingest service so the drive
	 * stamping under test is the thing the test controls.
	 *
	 * @param int    $author     Author.
	 * @param string $drive_type Drive type.
	 * @param int    $drive_id   Drive id.
	 * @return int Media id.
	 */
	private function seed_document( int $author, string $drive_type, int $drive_id ): int {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'mvs_media_index',
			array(
				'title'       => 'T1 fixture',
				'slug'        => 't1-fixture-' . wp_generate_password( 8, false ),
				'post_author' => $author,
				'media_type'  => 'document',
				'status'      => 'publish',
				'privacy'     => 'private',
				'drive_type'  => $drive_type,
				'drive_id'    => $drive_id,
				'created_at'  => current_time( 'mysql' ),
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Whether a row is still in the index.
	 *
	 * @param int $media_id Media id.
	 * @return bool
	 */
	private function exists( int $media_id ): bool {
		global $wpdb;

		return (bool) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}mvs_media_index WHERE media_id = %d", $media_id )
		);
	}

	/**
	 * The author currently on a row.
	 *
	 * @param int $media_id Media id.
	 * @return int
	 */
	private function author_of( int $media_id ): int {
		global $wpdb;

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare( "SELECT post_author FROM {$wpdb->prefix}mvs_media_index WHERE media_id = %d", $media_id )
		);
	}

	/**
	 * A personal document stamped the way v29 and the ingest service stamp one
	 * — `drive_type = user`, `drive_id = <author id>` — is erased, and a Space
	 * document by the same uploader is handed on.
	 */
	public function test_personal_documents_are_purged_and_space_documents_reassigned(): void {
		// THE ROW THAT BROKE IT: drive_id is the author id, not zero.
		$personal = $this->seed_document( $this->leaver, 'user', $this->leaver );
		$team     = $this->seed_document( $this->leaver, 'space', 4242 );

		$successor = $this->successor;

		add_filter(
			'mvs_document_drive_successor',
			static function ( $default, $drive_type ) use ( $successor ) {
				return 'space' === $drive_type ? $successor : $default;
			},
			10,
			2
		);

		wp_delete_user( $this->leaver );

		$this->assertFalse(
			$this->exists( $personal ),
			'A personal document must be erased with its author, or the account deletion is incomplete.'
		);

		$this->assertTrue(
			$this->exists( $team ),
			'A Space document must survive its author leaving, or the team loses its files.'
		);

		$this->assertSame(
			$successor,
			$this->author_of( $team ),
			'The Space document must name its successor, not the member who was erased.'
		);
	}

	/**
	 * A row Migrator v29 has not stamped yet counts as PERSONAL and is purged.
	 *
	 * This is the direction the original bug inverted. `drive_id = 0` means
	 * unstamped, not "on a team drive", and treating it as team-owned would
	 * retain a departing member's files on a site that happened to be
	 * mid-migration.
	 */
	public function test_unstamped_rows_are_treated_as_personal_and_purged(): void {
		$unstamped = $this->seed_document( $this->leaver, '', 0 );

		wp_delete_user( $this->leaver );

		$this->assertFalse(
			$this->exists( $unstamped ),
			'An unstamped row is personal until proven otherwise and must be erased.'
		);
	}

	/**
	 * With nobody claiming the drive, a team document goes to an administrator
	 * rather than being deleted.
	 */
	public function test_unclaimed_team_document_falls_back_to_an_administrator(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$team  = $this->seed_document( $this->leaver, 'space', 99 );

		// No `mvs_document_drive_successor` filter answers.
		wp_delete_user( $this->leaver );

		$this->assertTrue(
			$this->exists( $team ),
			'A file nobody claims is still the team\'s file — losing it is the harm T1 exists to prevent.'
		);

		$this->assertSame(
			$admin,
			$this->author_of( $team ),
			'The documented fallback is a site administrator.'
		);
	}

	/**
	 * The classifier itself, because both callers depend on it agreeing with
	 * how documents are actually stamped.
	 */
	public function test_team_drive_query_does_not_claim_personal_documents(): void {
		$repo = Plugin::container()->get( 'media_repository' );

		$this->seed_document( $this->leaver, 'user', $this->leaver );
		$this->seed_document( $this->leaver, 'user', $this->leaver );
		$team = $this->seed_document( $this->leaver, 'space', 7 );

		$rows = $repo->author_team_drive_media( $this->leaver );
		$ids  = array_map( static fn( $row ) => (int) $row['media_id'], $rows );

		$this->assertSame(
			array( $team ),
			$ids,
			'Only the Space document is a team file; personal rows stamped with the author id are not.'
		);
	}
}
