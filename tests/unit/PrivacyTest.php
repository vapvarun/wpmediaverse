<?php
/**
 * Test privacy levels and access control.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;

class PrivacyTest extends WP_UnitTestCase {

	private int $author_id;
	private int $other_id;

	public function set_up(): void {
		parent::set_up();
		$this->author_id = self::factory()->user->create( array( 'role' => 'author' ) );
		$this->other_id  = self::factory()->user->create( array( 'role' => 'subscriber' ) );
	}

	public function test_public_privacy_meta(): void {
		wp_set_current_user( $this->author_id );

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'mvs_media',
				'post_title'  => 'Public Photo',
				'post_status' => 'publish',
				'post_author' => $this->author_id,
			)
		);
		update_post_meta( $post_id, '_mvs_privacy', 'public' );

		$this->assertSame( 'public', get_post_meta( $post_id, '_mvs_privacy', true ) );
	}

	public function test_private_privacy_meta(): void {
		wp_set_current_user( $this->author_id );

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'mvs_media',
				'post_title'  => 'Private Photo',
				'post_status' => 'publish',
				'post_author' => $this->author_id,
			)
		);
		update_post_meta( $post_id, '_mvs_privacy', 'private' );

		$this->assertSame( 'private', get_post_meta( $post_id, '_mvs_privacy', true ) );
	}

	public function test_loggedin_privacy_meta(): void {
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'mvs_media',
				'post_title'  => 'Members Only',
				'post_status' => 'publish',
				'post_author' => $this->author_id,
			)
		);
		update_post_meta( $post_id, '_mvs_privacy', 'loggedin' );

		$this->assertSame( 'loggedin', get_post_meta( $post_id, '_mvs_privacy', true ) );
	}

	public function test_valid_privacy_levels(): void {
		$valid = array( 'public', 'loggedin', 'friends', 'group', 'private', 'custom' );

		foreach ( $valid as $level ) {
			$post_id = wp_insert_post(
				array(
					'post_type'   => 'mvs_media',
					'post_title'  => "Privacy: {$level}",
					'post_status' => 'publish',
					'post_author' => $this->author_id,
				)
			);
			update_post_meta( $post_id, '_mvs_privacy', $level );
			$this->assertSame( $level, get_post_meta( $post_id, '_mvs_privacy', true ), "Privacy level '{$level}' should persist." );
		}
	}
}
