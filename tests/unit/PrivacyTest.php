<?php
/**
 * Test privacy levels and access control.
 *
 * Uses custom table architecture (MediaRepository) instead of post meta.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;

class PrivacyTest extends WP_UnitTestCase {

	private int $author_id;

	public function set_up(): void {
		parent::set_up();
		$this->author_id = self::factory()->user->create( array( 'role' => 'author' ) );
	}

	public function test_public_privacy(): void {
		$media_id = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->insert(
			array(
				'title'       => 'Public Photo',
				'post_author' => $this->author_id,
				'privacy'     => 'public',
			)
		);

		$this->assertSame( 'public', \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get( $media_id, 'privacy' ) );
	}

	public function test_private_privacy(): void {
		$media_id = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->insert(
			array(
				'title'       => 'Private Photo',
				'post_author' => $this->author_id,
				'privacy'     => 'private',
			)
		);

		$this->assertSame( 'private', \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get( $media_id, 'privacy' ) );
	}

	public function test_loggedin_privacy(): void {
		$media_id = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->insert(
			array(
				'title'       => 'Members Only',
				'post_author' => $this->author_id,
				'privacy'     => 'loggedin',
			)
		);

		$this->assertSame( 'loggedin', \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get( $media_id, 'privacy' ) );
	}

	public function test_valid_privacy_levels(): void {
		$valid = array( 'public', 'loggedin', 'friends', 'group', 'private', 'custom' );

		foreach ( $valid as $level ) {
			$media_id = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->insert(
				array(
					'title'       => "Privacy: {$level}",
					'post_author' => $this->author_id,
					'privacy'     => $level,
				)
			);
			$this->assertSame( $level, \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get( $media_id, 'privacy' ), "Privacy level '{$level}' should persist." );
		}
	}

	public function test_default_privacy_is_public(): void {
		$media_id = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->insert(
			array(
				'title'       => 'Default Privacy',
				'post_author' => $this->author_id,
			)
		);

		$this->assertSame( 'public', \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get( $media_id, 'privacy' ) );
	}

	public function test_update_privacy(): void {
		$media_id = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->insert(
			array(
				'title'       => 'Change Privacy',
				'post_author' => $this->author_id,
				'privacy'     => 'public',
			)
		);

		\WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->set( $media_id, 'privacy', 'private' );

		$this->assertSame( 'private', \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get( $media_id, 'privacy' ) );
	}
}
