<?php
/**
 * Explore's Type filter and items uploaded together.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_REST_Request;
use WP_UnitTestCase;

/**
 * A batch of files is one gallery group with one cover. A listing narrowed to a
 * type must still find the members whose cover is of another type, and the
 * bulk privacy route must refuse a level the site does not have.
 */
class ExploreTypeFilterBatchTest extends WP_UnitTestCase {

	/**
	 * Insert one public, approved item in a group.
	 *
	 * @param string $type     Media type.
	 * @param string $group    Group key.
	 * @param int    $position Position in the group.
	 * @param int    $author   Author id.
	 * @return int
	 */
	private function member( string $type, string $group, int $position, int $author ): int {
		$repo = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );
		$id   = (int) $repo->insert(
			array(
				'title'             => 'Item',
				'post_author'       => $author,
				'media_type'        => $type,
				'status'            => 'publish',
				'moderation_status' => 'approved',
				'privacy'           => 'public',
			)
		);
		$repo->set( $id, 'media_group', $group );
		$repo->set( $id, 'group_position', (string) $position );
		return $id;
	}

	public function test_type_filter_finds_a_member_whose_cover_is_another_type(): void {
		$repo   = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );
		$author = self::factory()->user->create();
		$video  = $this->member( 'video', 'g-mixed', 0, $author );
		$audio  = $this->member( 'audio', 'g-mixed', 1, $author );
		$photo1 = $this->member( 'image', 'g-photos', 0, $author );
		$photo2 = $this->member( 'image', 'g-photos', 1, $author );

		$ids = static function ( array $types ) use ( $repo, $author ): array {
			$rows = $repo->query(
				array(
					'author_id'               => $author,
					'media_types'             => $types,
					'exclude_non_cover_group' => true,
					'per_page'                => 50,
				)
			);
			return array_map( 'intval', array_column( $rows, 'media_id' ) );
		};

		$this->assertSame( array( $audio ), $ids( array( 'audio' ) ), 'The audio is found under Audio although its cover is a video.' );

		$photos = $ids( array( 'image' ) );
		$this->assertContains( $photo1, $photos );
		$this->assertNotContains( $photo2, $photos, 'A photo behind a photo cover stays behind it.' );

		$all = $ids( array( 'image', 'video', 'audio' ) );
		$this->assertContains( $video, $all );
		$this->assertNotContains( $audio, $all, 'Unfiltered, the batch is still one tile.' );
	}

	/**
	 * A gallery member is listed itself once its cover can no longer stand in
	 * for it: private, held for review, or deleted. Basecamp 10392589060.
	 */
	public function test_a_member_is_listed_when_its_cover_is_private_held_or_deleted(): void {
		$repo   = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );
		$author = self::factory()->user->create();
		$listed = static function () use ( $repo, $author ): array {
			$rows = $repo->query(
				array(
					'author_id'               => $author,
					'exclude_non_cover_group' => true,
					'per_page'                => 50,
				)
			);
			return array_map( 'intval', array_column( $rows, 'media_id' ) );
		};

		$cover  = $this->member( 'image', 'g-cover', 0, $author );
		$member = $this->member( 'video', 'g-cover', 1, $author );
		$this->assertNotContains( $member, $listed(), 'Behind a public cover, the batch is one tile.' );

		$repo->set( $cover, 'privacy', 'private' );
		$this->assertContains( $member, $listed(), 'Cover private: the public member is listed itself.' );

		$repo->set( $cover, 'privacy', 'public' );
		$repo->set( $cover, 'moderation_status', 'pending' );
		$this->assertContains( $member, $listed(), 'Cover held for review: the member is listed itself.' );

		$repo->set( $cover, 'moderation_status', 'approved' );
		$repo->delete_cascade( $cover );
		$this->assertContains( $member, $listed(), 'Cover deleted: the member is listed itself, for good.' );
	}

	public function test_bulk_privacy_refuses_an_unknown_level(): void {
		$author = self::factory()->user->create();
		$id     = $this->member( 'image', 'g-one', 0, $author );
		wp_set_current_user( $author );

		$request = new WP_REST_Request( 'POST', '/mvs/v1/media/bulk' );
		$request->set_body_params(
			array(
				'action'    => 'change_privacy',
				'media_ids' => array( $id ),
				'privacy'   => 'everyone-i-like',
			)
		);
		$response = rest_do_request( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'public', \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get( $id, 'privacy' ) );
	}
}
