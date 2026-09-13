<?php
/**
 * Only public media, or the curator's own, may join a collection.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\Plugin;

/**
 * Basecamp 10298612348.
 *
 * A collection is curated and shareable, usually by an admin assembling other
 * members' work. can_view() is the wrong gate for that: it admits members-only
 * media to ANY logged-in curator, so a members-level photo could be pulled into
 * a collection by someone who merely happened to be signed in.
 */
class CollectionMembershipRuleTest extends WP_UnitTestCase {

	private int $curator;
	private int $someone_else;

	public function set_up(): void {
		parent::set_up();

		$this->curator      = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->someone_else = self::factory()->user->create( array( 'role' => 'subscriber' ) );
	}

	private function seed( int $author, string $privacy ): int {
		$id = (int) Plugin::container()->get( 'media_repository' )->insert(
			array(
				'title'       => 'Fixture ' . wp_generate_password( 8, false ),
				'slug'        => 'rule-' . strtolower( wp_generate_password( 10, false ) ),
				'post_author' => $author,
				'status'      => 'publish',
				'media_type'  => 'image',
				'privacy'     => $privacy,
			)
		);

		$this->assertGreaterThan( 0, $id, 'fixture media did not insert' );

		return $id;
	}

	private function collections() {
		return Plugin::container()->get( 'collections' );
	}

	public function test_anyones_public_media_may_be_collected(): void {
		$id = $this->seed( $this->someone_else, 'public' );

		$this->assertTrue( $this->collections()->may_contain( $id, $this->curator ) );
	}

	public function test_your_own_private_media_may_be_collected(): void {
		$id = $this->seed( $this->curator, 'private' );

		$this->assertTrue(
			$this->collections()->may_contain( $id, $this->curator ),
			'A curator must still be able to collect their own upload.'
		);
	}

	public function test_someone_elses_private_media_may_not(): void {
		$id = $this->seed( $this->someone_else, 'private' );

		$this->assertFalse( $this->collections()->may_contain( $id, $this->curator ) );
	}

	/**
	 * The case can_view() would have let through.
	 */
	public function test_someone_elses_members_only_media_may_not(): void {
		$id = $this->seed( $this->someone_else, 'members' );

		$this->assertTrue(
			Plugin::container()->get( 'privacy' )->can_view( $id, $this->curator ),
			'Fixture check: a logged-in curator CAN view members-level media...'
		);
		$this->assertFalse(
			$this->collections()->may_contain( $id, $this->curator ),
			'...but must not be able to collect it.'
		);
	}

	public function test_media_that_does_not_exist_may_not(): void {
		$this->assertFalse( $this->collections()->may_contain( 99999999, $this->curator ) );
	}

	/**
	 * The escape hatch a default change owes (Production Rule 3).
	 */
	public function test_a_site_can_widen_the_rule_with_the_filter(): void {
		$id = $this->seed( $this->someone_else, 'members' );

		add_filter( 'mvs_media_may_join_collection', '__return_true' );
		$widened = $this->collections()->may_contain( $id, $this->curator );
		remove_filter( 'mvs_media_may_join_collection', '__return_true' );

		$this->assertTrue( $widened );
	}
}
