<?php
/**
 * A member's profile offers only the tags that member's media carries.
 *
 * @package WPMediaVerse
 */

declare( strict_types=1 );

namespace WPMediaVerse\Tests\Unit;

use WPMediaVerse\Core\Plugin;

/**
 * Per-member tag cloud.
 */
class ProfileTagCloudTest extends \WP_UnitTestCase {

	/**
	 * Upload a public image for a member and tag it.
	 *
	 * @param int    $owner Member id.
	 * @param string $tag   Tag name.
	 * @return int
	 */
	private function tagged_media( int $owner, string $tag ): int {
		$img = imagecreatetruecolor( 400, 300 );
		imagefill( $img, 0, 0, imagecolorallocate( $img, wp_rand( 0, 255 ), 90, 30 ) );
		$file = wp_tempnam( 'mvs-tag' ) . '.jpg';
		imagejpeg( $img, $file, 90 );

		wp_set_current_user( $owner );
		$id = Plugin::container()->get( 'upload' )->handle(
			array(
				'tmp_name' => $file,
				'name'     => 'shot.jpg',
				'size'     => filesize( $file ),
				'type'     => 'image/jpeg',
			),
			$owner,
			array( 'privacy' => 'public' )
		);
		$this->assertIsInt( $id );
		wp_set_object_terms( $id, array( $tag ), 'mvs_tag' );
		return $id;
	}

	/**
	 * The cloud and its total narrow to one member.
	 */
	public function test_the_cloud_and_its_total_narrow_to_one_member(): void {
		$repo = Plugin::container()->get( 'media_repository' );
		$a    = self::factory()->user->create( array( 'role' => 'author' ) );
		$b    = self::factory()->user->create( array( 'role' => 'author' ) );
		$this->tagged_media( $a, 'sunsets' );
		$this->tagged_media( $b, 'portraits' );

		$slugs = static function ( array $rows ): array {
			$out = wp_list_pluck( $rows, 'slug' );
			sort( $out );
			return $out;
		};

		$this->assertSame( array( 'sunsets' ), $slugs( $repo->tag_cloud( 20, null, $a ) ), 'Member A is offered only their own tag.' );
		$this->assertSame( 1, $repo->tag_cloud_total( null, $a ) );
		$this->assertSame( array( 'portraits' ), $slugs( $repo->tag_cloud( 20, null, $b ) ) );
		$this->assertContains( 'sunsets', $slugs( $repo->tag_cloud( 20 ) ), 'Without an author the whole library is offered.' );
		$this->assertContains( 'portraits', $slugs( $repo->tag_cloud( 20 ) ) );
	}
}
