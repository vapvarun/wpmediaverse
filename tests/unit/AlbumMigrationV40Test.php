<?php
/**
 * Migrator v40 puts album data in the one-album shape without changing what
 * anyone sees (Basecamp 10264373450).
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\Migrator;
use WPMediaVerse\Core\Plugin;

/**
 * @since 2.6.0
 */
class AlbumMigrationV40Test extends WP_UnitTestCase {

	private function photo( string $privacy, int $album_pointer = 0 ): int {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'mvs_media_index',
			array(
				'title'       => 'v40',
				'slug'        => 'v40-' . wp_generate_password( 8, false ),
				'post_author' => 1,
				'media_type'  => 'image',
				'status'      => 'publish',
				'privacy'     => $privacy,
				'album_id'    => $album_pointer,
				'created_at'  => current_time( 'mysql' ),
			)
		);

		return (int) $wpdb->insert_id;
	}

	private function member( int $album, int $media, string $added_at ): void {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'mvs_album_items',
			array(
				'album_id' => $album,
				'media_id' => $media,
				'position' => 0,
				'added_at' => $added_at,
			)
		);
	}

	private function row( int $media, string $column ): string {
		global $wpdb;
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT {$column} FROM {$wpdb->prefix}mvs_media_index WHERE media_id = %d", $media ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	private function albums_of( int $media ): array {
		global $wpdb;
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT album_id FROM {$wpdb->prefix}mvs_album_items WHERE media_id = %d", $media ) ) );
	}

	public function test_v40_repairs_membership_and_records_own_privacy_without_publishing_anything(): void {
		$older = self::factory()->post->create( array( 'post_type' => 'mvs_album' ) );
		$newer = self::factory()->post->create( array( 'post_type' => 'mvs_album' ) );

		$twice    = $this->photo( 'private', 0 );      // In two albums, stale pointer.
		$orphaned = $this->photo( 'members', 999999 ); // Only in a deleted album.
		$single   = $this->photo( 'private', 0 );      // A private photo in a public album.

		$this->member( $older, $twice, '2026-01-01 00:00:00' );
		$this->member( $newer, $twice, '2026-02-01 00:00:00' );
		$this->member( 999999, $orphaned, '2026-01-01 00:00:00' );
		$this->member( $older, $single, '2026-01-01 00:00:00' );

		( new \ReflectionMethod( Migrator::class, 'migrate_to_40' ) )->invoke( new Migrator() );
		( new \ReflectionMethod( Migrator::class, 'migrate_to_40' ) )->invoke( new Migrator() ); // Re-runnable.

		$this->assertSame( array( $newer ), $this->albums_of( $twice ), 'A photo keeps only the album it joined last.' );
		$this->assertSame( (string) $newer, $this->row( $twice, 'album_id' ), 'Pointer follows membership.' );

		$this->assertSame( array(), $this->albums_of( $orphaned ), 'Rows for a deleted album are gone.' );
		$this->assertSame( '0', $this->row( $orphaned, 'album_id' ) );

		$this->assertSame( 'private', $this->row( $single, 'privacy' ), 'An update must not publish a private photo.' );
		$this->assertSame( 'private', $this->row( $twice, 'privacy' ) );
		$this->assertSame( 'members', $this->row( $orphaned, 'privacy' ) );

		$repo = Plugin::container()->get( 'media_repository' );
		$this->assertSame( 'private', (string) $repo->get( $single, 'own_privacy' ), 'Current privacy is recorded as the member\'s own.' );
	}
}
