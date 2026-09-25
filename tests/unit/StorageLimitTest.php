<?php
/**
 * Fair-use storage limit per member (2.6.0).
 *
 * One optional allowance, default unlimited; a per-member override; usage
 * read live so deleting frees space; site admins never blocked.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WP_REST_Request;
use WPMediaVerse\Core\Plugin;
use WPMediaVerse\Services\StorageLimitService;

class StorageLimitTest extends WP_UnitTestCase {

	private int $member;
	private StorageLimitService $limits;

	public function set_up(): void {
		parent::set_up();
		( new \WPMediaVerse\Core\Migrator() )->run();
		$this->member = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->limits = Plugin::container()->get( 'storage_limit' );
		wp_cache_flush();
	}

	private function file_of( int $bytes ): int {
		$id = (int) Plugin::container()->get( 'media_repository' )->insert(
			array(
				'title'       => 'Sized probe',
				'post_author' => $this->member,
				'media_type'  => 'image',
				'status'      => 'publish',
				'privacy'     => 'public',
				'file_size'   => $bytes,
				'slug'        => 'sized-' . wp_generate_password( 8, false, false ),
			)
		);
		$this->limits->forget_usage( $this->member );
		return $id;
	}

	public function test_no_limit_by_default(): void {
		$this->file_of( 900 * MB_IN_BYTES );
		$this->assertSame( 0, $this->limits->limit_bytes( $this->member ) );
		$this->assertNull( $this->limits->check( $this->member, 500 * MB_IN_BYTES ) );
	}

	public function test_site_limit_refuses_what_would_go_over(): void {
		update_option( StorageLimitService::OPTION, 50 );
		$this->file_of( 40 * MB_IN_BYTES );

		$this->assertNull( $this->limits->check( $this->member, 5 * MB_IN_BYTES ) );
		$refusal = $this->limits->check( $this->member, 20 * MB_IN_BYTES );
		$this->assertInstanceOf( \WP_Error::class, $refusal );
		$this->assertSame( 'mvs_storage_limit', $refusal->get_error_code() );
		$this->assertStringContainsString( 'Delete something to upload more.', $refusal->get_error_message() );
	}

	public function test_member_override_wins_and_zero_lifts_the_limit(): void {
		update_option( StorageLimitService::OPTION, 10 );
		$this->file_of( 30 * MB_IN_BYTES );
		$this->assertNotNull( $this->limits->check( $this->member, MB_IN_BYTES ) );

		update_user_meta( $this->member, StorageLimitService::USER_META, 100 );
		$this->assertNull( $this->limits->check( $this->member, MB_IN_BYTES ), 'The member override did not win.' );

		update_user_meta( $this->member, StorageLimitService::USER_META, 0 );
		$this->assertNull( $this->limits->check( $this->member, 999 * MB_IN_BYTES ), '0 should mean no limit for this member.' );
	}

	public function test_deleting_frees_space(): void {
		update_option( StorageLimitService::OPTION, 50 );
		$big = $this->file_of( 45 * MB_IN_BYTES );
		$this->assertNotNull( $this->limits->check( $this->member, 10 * MB_IN_BYTES ) );

		Plugin::container()->get( 'media_repository' )->delete_cascade( $big );
		$this->assertNull( $this->limits->check( $this->member, 10 * MB_IN_BYTES ), 'Deleting did not free the space.' );
	}

	public function test_every_upload_path_is_refused_through_the_shared_filter(): void {
		update_option( StorageLimitService::OPTION, 10 );
		$this->file_of( 9 * MB_IN_BYTES );

		// Fresh upload, document ingest and a replacement all apply this filter.
		$refused = apply_filters( 'mvs_upload_args', array( 'mime' => 'application/pdf', 'file_size' => 5 * MB_IN_BYTES ), $this->member );
		$this->assertInstanceOf( \WP_Error::class, $refused, 'An upload over the limit was not refused by mvs_upload_args.' );

		$shrink = apply_filters( 'mvs_upload_args', array( 'file_size' => -2 * MB_IN_BYTES, 'context' => 'replace' ), $this->member );
		$this->assertIsArray( $shrink, 'A replacement that frees space was refused.' );
	}

	public function test_site_admins_are_never_blocked(): void {
		update_option( StorageLimitService::OPTION, 1 );
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $admin );
		}
		$this->assertNull( $this->limits->check( $admin, 999 * MB_IN_BYTES ) );
	}

	public function test_member_reads_their_own_usage(): void {
		update_option( StorageLimitService::OPTION, 20 );
		$this->file_of( 5 * MB_IN_BYTES );
		do_action( 'rest_api_init' );
		wp_set_current_user( $this->member );

		$data = rest_do_request( new WP_REST_Request( 'GET', '/mvs/v1/me/storage' ) )->get_data();
		$this->assertSame( 5 * MB_IN_BYTES, $data['used'] );
		$this->assertSame( 20 * MB_IN_BYTES, $data['limit'] );
	}
}
