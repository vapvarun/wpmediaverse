<?php
/**
 * Cancelling a deletion lifts only the suspension the deletion request set.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\Plugin;

/**
 * Cancelling a deletion lifts only the suspension the deletion request set.
 */
class DeletionCancelSuspensionTest extends WP_UnitTestCase {

	private function member(): int {
		return self::factory()->user->create( array( 'role' => 'subscriber', 'user_pass' => 'pw-12345' ) );
	}

	public function test_cancel_keeps_a_moderator_suspension(): void {
		$user = $this->member();
		update_user_meta( $user, 'mvs_suspended', 1 ); // Moderator's suspension.

		$service = Plugin::container()->get( 'account_deletion' );
		$this->assertIsArray( $service->request( $user, 'pw-12345' ) );
		$service->cancel( $user );

		$this->assertSame( '1', (string) get_user_meta( $user, 'mvs_suspended', true ) );
	}

	public function test_cancel_lifts_the_request_suspension(): void {
		$user    = $this->member();
		$service = Plugin::container()->get( 'account_deletion' );
		$this->assertIsArray( $service->request( $user, 'pw-12345' ) );
		$this->assertSame( '1', (string) get_user_meta( $user, 'mvs_suspended', true ) );

		$service->cancel( $user );
		$this->assertSame( '', (string) get_user_meta( $user, 'mvs_suspended', true ) );
	}
}
