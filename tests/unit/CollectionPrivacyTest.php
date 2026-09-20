<?php
/**
 * A collection has its own visibility, and only two levels of it.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\Plugin;
use WPMediaVerse\Services\CollectionService;
use WPMediaVerse\Services\PrivacyService;

/**
 * Basecamp 10298612348.
 *
 * The docs promised private collections and the 2.0.0 changelog said the gating
 * shipped, but there was nowhere to store the value - so every collection was
 * public and the gates added for 10073499554 could only ever deny the owner
 * check. These pin the storage, the two-level vocabulary, and the fact that a
 * collection's privacy does NOT cascade into the media inside it.
 */
class CollectionPrivacyTest extends WP_UnitTestCase {

	private CollectionService $collections;
	private int $owner;
	private int $stranger;

	public function set_up(): void {
		parent::set_up();

		$this->collections = Plugin::container()->get( 'collections' );
		$this->owner       = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->stranger    = self::factory()->user->create( array( 'role' => 'subscriber' ) );
	}

	private function make_collection(): int {
		return (int) self::factory()->post->create(
			array(
				'post_type'   => 'mvs_collection',
				'post_status' => 'publish',
				'post_author' => $this->owner,
			)
		);
	}

	private function privacy(): PrivacyService {
		$privacy = Plugin::container()->get( 'privacy' );
		$privacy->flush_cache();

		return $privacy;
	}

	/**
	 * Every collection that predates this field reads public, which is the
	 * value they effectively had - so the upgrade changes nothing by itself.
	 */
	public function test_a_collection_with_nothing_stored_is_public(): void {
		$id = $this->make_collection();

		$this->assertSame( 'public', $this->collections->get_privacy( $id ) );
	}

	public function test_privacy_round_trips(): void {
		$id = $this->make_collection();

		$this->collections->set_privacy( $id, 'members' );

		$this->assertSame( 'members', $this->collections->get_privacy( $id ) );
	}

	/**
	 * Two levels, and a caller cannot smuggle in a third.
	 */
	public function test_an_unsupported_level_becomes_public(): void {
		$id = $this->make_collection();

		foreach ( array( 'private', 'friends', 'space', 'nonsense', '' ) as $bad ) {
			$this->collections->set_privacy( $id, $bad );

			$this->assertSame(
				'public',
				$this->collections->get_privacy( $id ),
				"'{$bad}' was accepted as a collection privacy level."
			);
		}
	}

	/**
	 * THE POINT OF THE CARD. The 2.0.0 gate finally denies someone.
	 */
	public function test_a_members_collection_is_denied_to_anonymous(): void {
		$id = $this->make_collection();

		$this->assertTrue(
			$this->privacy()->can_view( $id, 0, PrivacyService::SPACE_CPT ),
			'precondition: a public collection is open to anonymous'
		);

		$this->collections->set_privacy( $id, 'members' );

		$this->assertFalse(
			$this->privacy()->can_view( $id, 0, PrivacyService::SPACE_CPT ),
			'A members-only collection was still readable by a logged-out visitor.'
		);
		$this->assertTrue(
			$this->privacy()->can_view( $id, $this->stranger, PrivacyService::SPACE_CPT ),
			'A logged-in member must still see a members-only collection.'
		);
		$this->assertTrue(
			$this->privacy()->can_view( $id, $this->owner, PrivacyService::SPACE_CPT ),
			'The owner must keep their own collection.'
		);
	}

	/**
	 * NO CASCADE, unlike an album.
	 *
	 * AlbumService::set_privacy() clamps the items inside, because an album
	 * holds its owner's own uploads (#10149366902). A collection is a curated
	 * view of OTHER people's public media, so cascading would rewrite a
	 * stranger's photo because a curator changed a collection they own.
	 */
	public function test_collection_privacy_does_not_cascade_to_its_media(): void {
		$id    = $this->make_collection();
		$repo  = Plugin::container()->get( 'media_repository' );
		$photo = (int) $repo->insert(
			array(
				'title'       => 'Someone elses public photo',
				'slug'        => 'cascade-' . strtolower( wp_generate_password( 10, false ) ),
				'post_author' => $this->stranger,
				'status'      => 'publish',
				'media_type'  => 'image',
				'privacy'     => 'public',
			)
		);
		$this->assertGreaterThan( 0, $photo, 'fixture media did not insert' );

		$this->collections->set_privacy( $id, 'members' );

		$this->assertSame(
			'public',
			(string) $repo->get( $photo, 'privacy' ),
			"A collection's privacy rewrote an unrelated member's photo."
		);
	}
}
