<?php
/**
 * A private media item keeps its own privacy when an album shares its ID.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\Plugin;
use WPMediaVerse\Services\PrivacyService;

/**
 * Basecamp 10298525085.
 *
 * `mvs_media_index.media_id` and `wp_posts.ID` are independent AUTO_INCREMENT
 * sequences, so one integer can name BOTH a photo and an album. check_access()
 * asked `get_post_type()` first, so on a collision a PUBLISHED album decided a
 * PRIVATE photo's privacy and the file was served to anyone.
 *
 * The opposite ordering is not the fix either: resolving media-first
 * unconditionally is what denied an album owner their own album
 * (Basecamp 10071824547). The caller declares the ID space; only the genuine
 * collision needs a tie-break, and it breaks toward media because a refusal is
 * a better failure than a leak.
 */
class CollidingIdPrivacyTest extends WP_UnitTestCase {

	private int $album_owner;
	private int $media_owner;
	private int $stranger;

	public function set_up(): void {
		parent::set_up();

		// SUBSCRIBERS ON PURPOSE. `moderate_mvs_media` grants access before the
		// resolver is reached, so an administrator fixture would return true on
		// every path and the test would pass without testing anything.
		$this->album_owner = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->media_owner = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->stranger    = self::factory()->user->create( array( 'role' => 'subscriber' ) );
	}

	/**
	 * Build the collision: a published album, and a PRIVATE photo owned by
	 * someone else occupying the same integer.
	 *
	 * @return int The shared ID.
	 */
	private function seed_collision(): int {
		global $wpdb;

		$album_id = self::factory()->post->create(
			array(
				'post_type'   => 'mvs_album',
				'post_status' => 'publish',
				'post_author' => $this->album_owner,
				'post_title'  => 'Published Album',
			)
		);
		update_post_meta( $album_id, '_mvs_privacy', 'public' );

		// Explicit primary key, so clear it first and PROVE the insert landed —
		// AUTO_INCREMENT never rolls back, so this id may already be taken and a
		// silent false would leave another test's row here.
		$wpdb->delete( $wpdb->prefix . 'mvs_media_index', array( 'media_id' => $album_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'mvs_media_index',
			array(
				'media_id'    => $album_id,
				'title'       => 'Private Photo',
				'slug'        => 'colliding-private-' . $album_id,
				'post_author' => $this->media_owner,
				'status'      => 'publish',
				'media_type'  => 'image',
				'privacy'     => 'private',
				'file_path'   => '2026/09/colliding-' . $album_id . '.jpg',
			)
		);

		$this->assertNotFalse(
			$inserted,
			"Could not seed mvs_media_index at media_id {$album_id}: " . $wpdb->last_error
		);

		\WPMediaVerse\Repository\MediaRepository::reset_test_cache();

		return $album_id;
	}

	private function privacy(): PrivacyService {
		$privacy = Plugin::container()->get( 'privacy' );
		$privacy->flush_cache();

		return $privacy;
	}

	/**
	 * THE LEAK. A stranger must not get a private photo because a published
	 * album happens to share its ID.
	 */
	public function test_a_published_album_does_not_expose_a_colliding_private_photo(): void {
		$id = $this->seed_collision();

		$this->assertFalse(
			$this->privacy()->can_view( $id, $this->stranger ),
			'A private photo was served because a published album shared its ID.'
		);
	}

	/**
	 * Not even the album's owner: they own the album, not the photo.
	 */
	public function test_the_album_owner_does_not_inherit_the_colliding_photo(): void {
		$id = $this->seed_collision();

		$this->assertFalse(
			$this->privacy()->can_view( $id, $this->album_owner ),
			"The album's owner was granted an unrelated member's private photo."
		);
	}

	/**
	 * The photo's own owner still sees it.
	 */
	public function test_the_media_owner_still_sees_their_own_photo(): void {
		$id = $this->seed_collision();

		$this->assertTrue(
			$this->privacy()->can_view( $id, $this->media_owner ),
			'The owner lost their own media to the collision.'
		);
	}

	/**
	 * The other half of the trap: fixing the leak must not re-break
	 * Basecamp 10071824547. Asked as a CPT, the album still resolves as the
	 * album — its owner sees it, and the colliding photo does not decide it.
	 */
	public function test_the_album_is_still_viewable_as_an_album(): void {
		$id = $this->seed_collision();

		$this->assertTrue(
			$this->privacy()->can_view( $id, $this->album_owner, PrivacyService::SPACE_CPT ),
			'The album owner was denied their own album — 10071824547 has regressed.'
		);

		$this->assertTrue(
			$this->privacy()->can_view( $id, $this->stranger, PrivacyService::SPACE_CPT ),
			'A public album stopped being public because a private photo shared its ID.'
		);
	}
}
