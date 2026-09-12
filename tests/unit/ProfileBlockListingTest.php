<?php
/**
 * A blocked member must not be shown the blocker's profile listing.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\Plugin;

/**
 * Basecamp 10296867415.
 *
 * PrivacyService::can_view() already refuses the ITEM. This covers the LIST
 * that advertises it, and the count printed above the list - the two must move
 * together, which is why the guard lives in resolve_profile_privacy_mode()
 * rather than in either caller.
 */
class ProfileBlockListingTest extends WP_UnitTestCase {

	private int $author;
	private int $viewer;
	private int $bystander;

	public function set_up(): void {
		parent::set_up();

		$this->author    = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->viewer    = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->bystander = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		global $wpdb;
		$index = $wpdb->prefix . 'mvs_media_index';
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $index ) ) ) {
			( new \WPMediaVerse\Core\Migrator() )->run();
		}

		// One public item by the author, so a leak is visible as a count of 1.
		$wpdb->insert(
			$index,
			array(
				'post_author' => $this->author,
				'title'       => 'Blocked listing fixture',
				'media_type'  => 'image',
				'file_url'    => 'https://example.test/x.jpg',
				'privacy'     => 'public',
				'status'      => 'publish',
				'created_at'  => current_time( 'mysql' ),
			)
		);
	}

	private function repo() {
		return Plugin::container()->get( 'media_repository' );
	}

	private function tiles( int $viewer ): int {
		return count( $this->repo()->query_by_author( $this->author, array( 'viewer_id' => $viewer, 'limit' => 50 ) ) );
	}

	public function test_a_bystander_sees_the_listing(): void {
		$this->assertSame( 1, $this->tiles( $this->bystander ) );
		$this->assertSame( 1, $this->repo()->count_visible_by_author( $this->author, $this->bystander ) );
	}

	public function test_a_blocked_viewer_sees_neither_tiles_nor_count(): void {
		Plugin::container()->get( 'reports' )->block_user( $this->author, $this->viewer );

		$this->assertSame( 0, $this->tiles( $this->viewer ), 'the blocked member was shown the listing' );
		$this->assertSame(
			0,
			$this->repo()->count_visible_by_author( $this->author, $this->viewer ),
			'the count still advertised media the blocked member cannot open'
		);
	}

	public function test_a_viewer_who_blocked_the_author_sees_nothing_either(): void {
		// The VIEWER blocks the AUTHOR - the mirror of the case above.
		// build_query_parts() already drops authors the viewer blocked, so the
		// listing empties from this side too. Asserted here because the count
		// has to empty WITH it: a list and a count that disagree is the whole
		// defect this card is about, and it would be just as wrong pointing
		// this way.
		Plugin::container()->get( 'reports' )->block_user( $this->viewer, $this->author );

		$this->assertSame( 0, $this->tiles( $this->viewer ) );
		$this->assertSame(
			0,
			$this->repo()->count_visible_by_author( $this->author, $this->viewer ),
			'the count still advertised media the list had already dropped'
		);
	}

	public function test_the_owner_and_a_moderator_are_unaffected(): void {
		Plugin::container()->get( 'reports' )->block_user( $this->author, $this->viewer );

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$this->assertSame( 1, $this->tiles( $this->author ), 'the owner lost their own listing' );
		$this->assertSame( 1, $this->tiles( $admin ), 'a moderator lost the listing' );
	}
}
