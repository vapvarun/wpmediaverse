<?php
/**
 * Signed URLs for non-public media stay identical inside a window (so a
 * member's browser can reuse the image) and never outlive the owner's TTL.
 *
 * @package WPMediaVerse\Tests\Unit
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Services\SignedUrlService;

/**
 * @covers \WPMediaVerse\Services\SignedUrlService
 */
class SignedUrlExpiryWindowTest extends WP_UnitTestCase {

	/**
	 * Non-public expiry is stable within a window and bounded by the TTL.
	 *
	 * @return void
	 */
	public function test_non_public_expiry_is_stable_and_bounded(): void {
		$repo = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );
		$id   = (int) $repo->insert(
			array(
				'title'             => 'Members photo',
				'post_author'       => self::factory()->user->create(),
				'media_type'        => 'image',
				'status'            => 'publish',
				'moderation_status' => 'approved',
				'privacy'           => 'members',
				'file_path'         => '2026/10/members-photo.jpg',
				'file_type'         => 'image/jpeg',
			)
		);
		$this->assertGreaterThan( 0, $id );

		$service = new \ReflectionMethod( SignedUrlService::class, 'resolve_expiry' );
		$signer  = \WPMediaVerse\Core\Plugin::container()->get( 'signed_urls' );
		$ttl     = HOUR_IN_SECONDS;

		$window = intdiv( $ttl, 2 );
		$t1     = time();
		$first  = (int) $service->invoke( $signer, $id, $ttl );
		sleep( 1 ); // A later render: a rolling time() + TTL would differ now.
		$second = (int) $service->invoke( $signer, $id, $ttl );
		if ( intdiv( $t1, $window ) === intdiv( time(), $window ) ) {
			$this->assertSame( $first, $second, 'Same URL for every render inside the window.' );
		}

		$left = $first - time();
		$this->assertLessThanOrEqual( $ttl, $left, 'Never valid longer than the TTL.' );
		$this->assertGreaterThanOrEqual( intdiv( $ttl, 2 ) - 1, $left, 'Always at least half the TTL left.' );
	}
}
