<?php
/**
 * A photo in an album shows with the album's privacy; out of it, its own.
 *
 * Owner decisions 2026-09-27 (Basecamp 10264373450): one uniform rule, the
 * album wins in both directions, a photo belongs to one album, and the
 * member's own choice is kept and comes back when the photo leaves.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\Plugin;
use WPMediaVerse\Services\AlbumService;

/**
 * @since 2.6.0
 */
class AlbumPrivacyRuleTest extends WP_UnitTestCase {

	/**
	 * Album owner.
	 *
	 * @var int
	 */
	private int $owner;

	public function set_up(): void {
		parent::set_up();
		$this->owner = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->owner );
	}

	public function tear_down(): void {
		remove_all_filters( 'mvs_album_inherit_privacy' );
		parent::tear_down();
	}

	private function albums(): AlbumService {
		return Plugin::container()->get( 'albums' );
	}

	private function photo( string $privacy ): int {
		$repo = Plugin::container()->get( 'media_repository' );

		return (int) $repo->insert(
			array(
				'title'       => 'Rule ' . wp_generate_password( 6, false ),
				'post_author' => $this->owner,
				'media_type'  => 'image',
				'status'      => 'publish',
				'privacy'     => $privacy,
			)
		);
	}

	private function privacy( int $id ): string {
		return (string) Plugin::container()->get( 'media_repository' )->get( $id, 'privacy' );
	}

	private function album( string $privacy ): int {
		$id = (int) $this->albums()->create( $this->owner, array( 'title' => 'Rule album' ) );
		$this->albums()->set_privacy( $id, $privacy );

		return $id;
	}

	public function test_making_an_album_public_again_brings_its_photos_back(): void {
		$a     = $this->photo( 'public' );
		$b     = $this->photo( 'public' );
		$album = $this->album( 'public' );
		$this->albums()->add_items( $album, array( $a, $b ) );

		$this->albums()->set_privacy( $album, 'private' );
		$this->assertSame( array( 'private', 'private' ), array( $this->privacy( $a ), $this->privacy( $b ) ) );

		$this->albums()->set_privacy( $album, 'public' );
		$this->assertSame(
			array( 'public', 'public' ),
			array( $this->privacy( $a ), $this->privacy( $b ) ),
			'Re-publishing the album must bring its photos back; they used to stay private forever.'
		);
	}

	public function test_the_album_wins_even_when_it_is_more_public(): void {
		$own   = $this->photo( 'private' );
		$album = $this->album( 'public' );

		$this->albums()->add_items( $album, array( $own ) );

		$this->assertSame( 'public', $this->privacy( $own ) );
	}

	public function test_leaving_the_album_restores_the_members_own_choice(): void {
		$own   = $this->photo( 'members' );
		$album = $this->album( 'public' );
		$this->albums()->add_items( $album, array( $own ) );
		$this->assertSame( 'public', $this->privacy( $own ) );

		$this->albums()->remove_item( $album, $own );

		$this->assertSame( 'members', $this->privacy( $own ) );
		$this->assertSame( '', (string) Plugin::container()->get( 'media_repository' )->get( $own, AlbumService::OWN_PRIVACY_META ) );
	}

	public function test_deleting_the_album_restores_its_photos(): void {
		$own   = $this->photo( 'private' );
		$album = $this->album( 'members' );
		$this->albums()->add_items( $album, array( $own ) );

		$this->albums()->delete_all_items( $album );

		$this->assertSame( 'private', $this->privacy( $own ) );
		$this->assertSame( 0, (int) Plugin::container()->get( 'media_repository' )->get( $own, 'album_id' ) );
	}

	public function test_a_photo_belongs_to_one_album_and_moving_keeps_its_own_choice(): void {
		$own    = $this->photo( 'private' );
		$first  = $this->album( 'public' );
		$second = $this->album( 'members' );

		$this->albums()->add_items( $first, array( $own ) );
		$this->albums()->add_items( $second, array( $own ) );

		$this->assertSame( array( $second ), $this->albums()->albums_for_media( $own ) );
		$this->assertSame( 'members', $this->privacy( $own ) );

		$this->albums()->remove_item( $second, $own );
		$this->assertSame( 'private', $this->privacy( $own ), 'The choice made before either album is the one that comes back.' );
	}

	public function test_setting_privacy_on_a_photo_in_an_album_keeps_it_for_later(): void {
		$own   = $this->photo( 'public' );
		$album = $this->album( 'members' );
		$this->albums()->add_items( $album, array( $own ) );

		$this->assertTrue( $this->albums()->keep_own_privacy_if_in_album( $own, 'private' ) );
		$this->assertSame( 'members', $this->privacy( $own ), 'The album still decides.' );

		$this->albums()->remove_item( $album, $own );
		$this->assertSame( 'private', $this->privacy( $own ), 'The later choice applies once it leaves.' );

		$this->assertFalse( $this->albums()->keep_own_privacy_if_in_album( $own, 'public' ), 'Out of every album, the caller writes privacy itself.' );
	}

	public function test_saving_an_edit_with_the_album_privacy_keeps_the_members_own_choice(): void {
		$own   = $this->photo( 'private' );
		$album = $this->album( 'members' );
		$this->albums()->add_items( $album, array( $own ) );

		// The edit screens always send the current level, here the album's.
		$request = new \WP_REST_Request( 'PUT', '/mvs/v1/media/' . $own );
		$request->set_param( 'title', 'Renamed' );
		$request->set_param( 'privacy', 'members' );
		$this->assertSame( 200, rest_do_request( $request )->get_status() );

		$this->assertSame( 'private', (string) Plugin::container()->get( 'media_repository' )->get( $own, AlbumService::OWN_PRIVACY_META ), 'A title edit must not overwrite the member\'s own choice.' );

		$this->albums()->remove_item( $album, $own );
		$this->assertSame( 'private', $this->privacy( $own ) );
	}

	public function test_own_privacy_counts_per_level(): void {
		$album = $this->album( 'public' );
		$this->albums()->add_items( $album, array( $this->photo( 'private' ), $this->photo( 'private' ), $this->photo( 'public' ) ) );

		$this->assertSame(
			array(
				'private' => 2,
				'public'  => 1,
			),
			$this->sorted( $this->albums()->own_privacy_counts( $album ) )
		);
	}

	public function test_the_escape_hatch_keeps_album_and_photo_independent(): void {
		add_filter( 'mvs_album_inherit_privacy', '__return_false' );
		$own   = $this->photo( 'private' );
		$album = $this->album( 'public' );

		$this->albums()->add_items( $album, array( $own ) );
		$this->albums()->set_privacy( $album, 'members' );

		$this->assertSame( 'private', $this->privacy( $own ) );
	}

	/**
	 * Sort by key so assertions do not depend on SQL order.
	 *
	 * @param array $rows Rows.
	 * @return array
	 */
	private function sorted( array $rows ): array {
		ksort( $rows );
		return $rows;
	}
}
