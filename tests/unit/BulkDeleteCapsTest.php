<?php
/**
 * Bulk delete applies the delete capabilities, like the single delete route.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_REST_Request;
use WP_UnitTestCase;
use WPMediaVerse\Core\Plugin;

/**
 * Basecamp 10393276020 item 14.
 */
class BulkDeleteCapsTest extends WP_UnitTestCase {

	public function test_member_without_delete_right_cannot_bulk_delete(): void {
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$u    = get_user_by( 'id', $user );
		$u->add_cap( 'edit_mvs_medias' );
		$u->add_cap( 'delete_mvs_medias', false );

		$repo = Plugin::container()->get( 'media_repository' );
		$id   = $repo->insert(
			array(
				'title'       => 'Bulk delete probe',
				'post_author' => $user,
				'status'      => 'publish',
				'media_type'  => 'image',
				'privacy'     => 'public',
			)
		);

		wp_set_current_user( $user );
		$request = new WP_REST_Request( 'POST', '/mvs/v1/media/bulk' );
		$request->set_body_params( array( 'action' => 'delete', 'media_ids' => array( $id ) ) );
		rest_do_request( $request );

		\WPMediaVerse\Repository\MediaRepository::reset_test_cache();
		$this->assertTrue( $repo->exists( $id ), 'Bulk delete ran without the delete capability.' );
	}
}
