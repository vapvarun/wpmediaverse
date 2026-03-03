<?php
/**
 * PrivacyService tests.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WPMediaVerse\Services\PrivacyService;

class PrivacyServiceTest extends TestCase {

	/**
	 * @var PrivacyService
	 */
	private $privacy;

	/**
	 * @var int Test media post ID.
	 */
	private $media_id;

	protected function setUp(): void {
		parent::setUp();

		$this->privacy = new PrivacyService();

		// Create a test media post owned by user 1.
		$this->media_id = wp_insert_post(
			array(
				'post_type'   => 'mvs_media',
				'post_title'  => 'Privacy Test Media',
				'post_status' => 'publish',
				'post_author' => 1,
			)
		);

		update_post_meta( $this->media_id, '_mvs_privacy', 'public' );
	}

	protected function tearDown(): void {
		wp_delete_post( $this->media_id, true );
		parent::tearDown();
	}

	public function test_public_allows_anonymous(): void {
		update_post_meta( $this->media_id, '_mvs_privacy', 'public' );
		$this->privacy->flush_cache();

		$this->assertTrue( $this->privacy->can_view( $this->media_id, 0 ) );
	}

	public function test_members_blocks_anonymous(): void {
		update_post_meta( $this->media_id, '_mvs_privacy', 'members' );
		$this->privacy->flush_cache();

		$this->assertFalse( $this->privacy->can_view( $this->media_id, 0 ) );
	}

	public function test_members_allows_logged_in(): void {
		update_post_meta( $this->media_id, '_mvs_privacy', 'members' );
		$this->privacy->flush_cache();

		$this->assertTrue( $this->privacy->can_view( $this->media_id, 2 ) );
	}

	public function test_private_blocks_non_owner(): void {
		update_post_meta( $this->media_id, '_mvs_privacy', 'private' );
		$this->privacy->flush_cache();

		$this->assertFalse( $this->privacy->can_view( $this->media_id, 2 ) );
	}

	public function test_private_allows_owner(): void {
		update_post_meta( $this->media_id, '_mvs_privacy', 'private' );
		$this->privacy->flush_cache();

		$this->assertTrue( $this->privacy->can_view( $this->media_id, 1 ) );
	}

	public function test_friends_falls_back_to_private_without_bp(): void {
		update_post_meta( $this->media_id, '_mvs_privacy', 'friends' );
		$this->privacy->flush_cache();

		// Without BuddyPress, friends should fall back to private.
		$this->assertFalse( $this->privacy->can_view( $this->media_id, 2 ) );
	}

	public function test_custom_allows_listed_user(): void {
		update_post_meta( $this->media_id, '_mvs_privacy', 'custom' );
		update_post_meta( $this->media_id, '_mvs_custom_access', array( 5, 10 ) );
		$this->privacy->flush_cache();

		$this->assertTrue( $this->privacy->can_view( $this->media_id, 5 ) );
		$this->assertFalse( $this->privacy->can_view( $this->media_id, 3 ) );
	}

	public function test_cache_returns_same_result(): void {
		update_post_meta( $this->media_id, '_mvs_privacy', 'public' );
		$this->privacy->flush_cache();

		$first  = $this->privacy->can_view( $this->media_id, 0 );
		$second = $this->privacy->can_view( $this->media_id, 0 );

		$this->assertSame( $first, $second );
	}

	public function test_nonexistent_media_returns_false(): void {
		$this->assertFalse( $this->privacy->can_view( 999999, 1 ) );
	}
}
