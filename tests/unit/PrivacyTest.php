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

	/**
	 * privacy=space resolves through the drive bridge — and ONLY through it.
	 *
	 * The bug this pins (Basecamp 10220491230): media rows were never stamped
	 * with a drive, so check_space() early-returned on drive_type=user and the
	 * bridge was never asked. `space` was accepted on write and behaved as
	 * private forever, for everyone but the owner.
	 *
	 * Mutation check: revert the drive columns below to ( 'user', 0 ) — the
	 * member assertion fails, by name.
	 */
	public function test_space_privacy_resolves_through_the_drive_bridge(): void {
		$member  = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$outside = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$media_id = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->insert(
			array(
				'title'       => 'Space Photo',
				'post_author' => $this->author_id,
				'privacy'     => 'space',
				'drive_type'  => 'space',
				'drive_id'    => 7,
			)
		);

		// Stand in for BuddyNext: space 7 is readable by $member and nobody else.
		$bridge = static function ( $level, $drive_type, $drive_id, $user_id ) use ( $member ) {
			if ( 'space' === $drive_type && 7 === (int) $drive_id && (int) $user_id === $member ) {
				return 'read';
			}
			return $level;
		};
		add_filter( 'mvs_document_drive_access', $bridge, 10, 4 );

		$privacy = \WPMediaVerse\Core\Plugin::container()->get( 'privacy' );
		$privacy->flush_cache();

		$this->assertTrue( $privacy->can_view( $media_id, $member ), 'A member of the space must see space media.' );
		$this->assertFalse( $privacy->can_view( $media_id, $outside ), 'A non-member must not.' );
		$this->assertFalse( $privacy->can_view( $media_id, 0 ), 'Anonymous must not.' );

		remove_filter( 'mvs_document_drive_access', $bridge, 10 );
	}

	/**
	 * Unbound media never reaches the bridge: no drive, no space membership.
	 *
	 * Fail-closed is the point — an unbridged site must not leak, and must not
	 * quietly grant either.
	 */
	public function test_space_privacy_on_a_personal_drive_is_denied(): void {
		$other = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$media_id = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->insert(
			array(
				'title'       => 'Unbound Space Photo',
				'post_author' => $this->author_id,
				'privacy'     => 'space',
				'drive_type'  => 'user',
				'drive_id'    => $this->author_id,
			)
		);

		$privacy = \WPMediaVerse\Core\Plugin::container()->get( 'privacy' );
		$privacy->flush_cache();

		$this->assertFalse( $privacy->can_view( $media_id, $other ) );
		$this->assertTrue( $privacy->can_view( $media_id, $this->author_id ), 'The owner always keeps their own media.' );
	}

	/**
	 * A drive binding is admitted only when the bridge grants write/own.
	 *
	 * Without this a member could file media into a Space they may only read —
	 * publishing it to that Space's members.
	 */
	public function test_drive_binding_requires_write_access(): void {
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$want_space = static fn( $drive, $uid, $args ) => array( 'space', 7 );
		add_filter( 'mvs_media_drive', $want_space, 10, 3 );

		$reader = static fn( $level, $type, $id, $uid ) => 'read';
		add_filter( 'mvs_document_drive_access', $reader, 10, 4 );

		$this->assertSame(
			array( 'user', 0 ),
			\WPMediaVerse\Services\PrivacyService::resolve_drive_for_user( $user ),
			'read-only access must fall back to the personal drive.'
		);

		remove_filter( 'mvs_document_drive_access', $reader, 10 );

		$writer = static fn( $level, $type, $id, $uid ) => 'write';
		add_filter( 'mvs_document_drive_access', $writer, 10, 4 );

		$this->assertSame(
			array( 'space', 7 ),
			\WPMediaVerse\Services\PrivacyService::resolve_drive_for_user( $user ),
			'write access must bind the space drive.'
		);

		remove_filter( 'mvs_document_drive_access', $writer, 10 );
		remove_filter( 'mvs_media_drive', $want_space, 10 );
	}
}
