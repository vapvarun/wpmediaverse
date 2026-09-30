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
	 * @param string $privacy    Privacy level.
	 * @return int Media id.
	 */
	private function seed_document( int $author, string $drive_type, int $drive_id, string $privacy = 'private' ): int {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'mvs_media_index',
			array(
				'title'       => 'T1 fixture',
				'slug'        => 't1-fixture-' . wp_generate_password( 8, false ),
				'post_author' => $author,
				'media_type'  => 'document',
				'status'      => 'publish',
				'privacy'     => $privacy,
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
	 * "Attribute all content to" keeps the member's media (Basecamp 10344411938).
	 *
	 * WordPress hands posts to the chosen member; media used to be erased
	 * instead. Everything moves with its privacy kept, a personal drive follows
	 * its new owner, Space files still go to their Space, and only DM
	 * attachments go with the erased messages (owner decision 2026-09-27).
	 */
	public function test_attribute_all_content_to_keeps_the_media(): void {
		$heir     = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$public   = $this->seed_document( $this->leaver, 'user', $this->leaver, 'public' );
		$private  = $this->seed_document( $this->leaver, 'user', $this->leaver, 'private' );
		$dm       = $this->seed_document( $this->leaver, 'user', $this->leaver, 'dm' );
		$team     = $this->seed_document( $this->leaver, 'space', 4242 );
		$outsider = $this->seed_document( $this->successor, 'user', $this->successor, 'public' );

		$successor = $this->successor;
		add_filter(
			'mvs_document_drive_successor',
			static function ( $default, $drive_type ) use ( $successor ) {
				return 'space' === $drive_type ? $successor : $default;
			},
			10,
			2
		);

		wp_delete_user( $this->leaver, $heir );

		foreach ( array( $public, $private ) as $id ) {
			$this->assertTrue( $this->exists( $id ), 'Media was erased although the admin chose to keep the content.' );
			$this->assertSame( $heir, $this->author_of( $id ) );
		}

		$repo = Plugin::container()->get( 'media_repository' );
		$this->assertSame( 'private', (string) $repo->get( $private, 'privacy' ), 'Privacy must not change hands with the media.' );
		$this->assertSame( $heir, (int) $repo->get( $public, 'drive_id' ), 'A personal drive must follow its new owner.' );

		$this->assertSame( $successor, $this->author_of( $team ), 'A Space file still goes to its Space, not to the heir.' );
		$this->assertFalse( $this->exists( $dm ), 'A DM attachment goes with the erased messages.' );
		$this->assertSame( $this->successor, $this->author_of( $outsider ), 'Another member\'s media is untouched.' );
	}

	/**
	 * The Delete Users screen offers "Attribute all content to" for a member
	 * whose only content is media; WordPress alone asks only about posts.
	 */
	public function test_a_member_with_only_media_is_offered_the_choice(): void {
		$this->assertFalse( (bool) apply_filters( 'users_have_additional_content', false, array( $this->leaver ) ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook.

		$this->seed_document( $this->leaver, 'user', $this->leaver, 'public' );

		$this->assertTrue( (bool) apply_filters( 'users_have_additional_content', false, array( $this->leaver ) ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook.
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
		self::factory()->user->create( array( 'role' => 'administrator' ) );
		$team = $this->seed_document( $this->leaver, 'space', 99 );

		// No `mvs_document_drive_successor` filter answers.
		wp_delete_user( $this->leaver );

		$this->assertTrue(
			$this->exists( $team ),
			'A file nobody claims is still the team\'s file — losing it is the harm T1 exists to prevent.'
		);

		// THE LOWEST-ID ADMINISTRATOR, not whichever one this test happened to
		// create. `fallback_successor()` orders by id precisely so the answer is
		// stable across runs, and a fresh install always has user 1 — asserting
		// on the factory's user encoded an assumption the implementation never
		// made, and only looked right because no other admin existed.
		$expected = (int) current(
			get_users(
				array(
					'role'    => 'administrator',
					'fields'  => 'ID',
					'orderby' => 'ID',
					'order'   => 'ASC',
					'number'  => 1,
				)
			)
		);

		$this->assertSame(
			$expected,
			$this->author_of( $team ),
			'The documented fallback is the lowest-id site administrator.'
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
