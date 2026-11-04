<?php
/**
 * Albums and album items take an optional source time for imports, and
 * 'announce' => false actually silences the announcement (BuddyNext card 10459592358).
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Services\AlbumService;

/**
 * @since 2.6.3
 */
class AlbumBackdateTest extends WP_UnitTestCase {

	private const OLD = '2020-02-02 02:02:02';

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

	public function test_album_create_takes_the_source_time(): void {
		$albums = new AlbumService();

		$dated = $albums->create(
			$this->owner,
			array(
				'title'      => 'Old album',
				'created_at' => self::OLD,
			)
		);
		$this->assertSame( self::OLD, get_post( $dated )->post_date_gmt );
		$this->assertSame( 'publish', get_post( $dated )->post_status );

		$live = $albums->create( $this->owner, array( 'title' => 'Live album' ) );
		$this->assertGreaterThan( time() - 60, strtotime( get_post( $live )->post_date_gmt . ' UTC' ) );

		$future = $albums->create(
			$this->owner,
			array(
				'title'      => 'Future album',
				'created_at' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
			)
		);
		$this->assertSame( 'publish', get_post( $future )->post_status );
		$this->assertLessThanOrEqual( time() + 1, strtotime( get_post( $future )->post_date_gmt . ' UTC' ) );
	}

	/**
	 * A media row owned by the album owner.
	 */
	private function media(): int {
		return (int) \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->insert(
			array(
				'title'       => 'Photo',
				'post_author' => $this->owner,
				'status'      => 'publish',
				'media_type'  => 'image',
				'privacy'     => 'public',
				'file_url'    => 'https://example.test/p.jpg',
				'file_path'   => 'p.jpg',
				'file_type'   => 'image/jpeg',
				'file_size'   => 1,
				'file_hash'   => wp_generate_password( 12, false ),
				'created_at'  => self::OLD,
			)
		);
	}

	public function test_add_items_takes_added_at_and_honours_announce_false(): void {
		global $wpdb;

		$albums = new AlbumService();
		$album  = $albums->create( $this->owner, array( 'title' => 'Album' ) );
		$fired  = 0;
		add_action(
			'mvs_album_items_added',
			static function () use ( &$fired ) {
				++$fired;
			}
		);

		$dated = $this->media();
		$this->assertSame(
			1,
			$albums->add_items(
				$album,
				array( $dated ),
				array(
					'announce' => false,
					'added_at' => self::OLD,
				)
			)
		);
		$this->assertSame( 0, $fired, "'announce' => false must silence the announcement" );
		$this->assertSame(
			self::OLD,
			$wpdb->get_var( $wpdb->prepare( "SELECT added_at FROM {$wpdb->prefix}mvs_album_items WHERE media_id = %d", $dated ) ) // phpcs:ignore WordPress.DB
		);

		$live = $this->media();
		$albums->add_items( $album, array( $live ) );
		$this->assertSame( 1, $fired, 'a plain add still announces' );
		$this->assertGreaterThan(
			time() - 60,
			strtotime( $wpdb->get_var( $wpdb->prepare( "SELECT added_at FROM {$wpdb->prefix}mvs_album_items WHERE media_id = %d", $live ) ) . ' UTC' ) // phpcs:ignore WordPress.DB
		);
	}
}
