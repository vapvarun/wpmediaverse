<?php
/**
 * Media and documents can be answered separately for drive access.
 *
 * @package WPMediaVerse
 */

use WPMediaVerse\Services\PrivacyService;

/**
 * Covers the `mvs_media_drive_access` seam.
 */
class MediaDriveAccessSeamTest extends WP_UnitTestCase {

	/**
	 * Filters added by a test, removed in tear_down.
	 *
	 * @var array<int, array{0:string,1:callable}>
	 */
	private $added = array();

	/**
	 * Register a filter and remember to remove it.
	 *
	 * @param string   $hook Hook name.
	 * @param callable $cb   Callback.
	 */
	private function hook( string $hook, callable $cb ): void {
		add_filter( $hook, $cb, 10, 4 );
		$this->added[] = array( $hook, $cb );
	}

	/**
	 * Tear down.
	 */
	public function tear_down(): void {
		foreach ( $this->added as $pair ) {
			remove_filter( $pair[0], $pair[1], 10 );
		}
		$this->added = array();

		parent::tear_down();
	}

	/**
	 * Put a member on a space drive so there is something to resolve.
	 *
	 * @param int $user_id User id.
	 */
	private function place_on_space( int $user_id ): void {
		$this->hook(
			'mvs_media_drive',
			static function () {
				return array( 'space', 7 );
			}
		);
	}

	/**
	 * A site that has not adopted the new filter behaves exactly as before.
	 *
	 * The load-bearing test. This seam is additive or it is a regression for
	 * every existing integration (Basecamp 10252314484).
	 */
	public function test_without_the_new_filter_the_document_answer_still_decides(): void {
		$user_id = self::factory()->user->create();
		$this->place_on_space( $user_id );

		$this->hook(
			'mvs_document_drive_access',
			static function () {
				return 'write';
			}
		);

		$this->assertSame(
			array( 'space', 7 ),
			PrivacyService::resolve_drive_for_user( $user_id ),
			'The document filter alone should still place media on the drive.'
		);
	}

	/**
	 * And it still refuses when the document filter refuses.
	 */
	public function test_without_the_new_filter_a_refusal_is_still_a_refusal(): void {
		$user_id = self::factory()->user->create();
		$this->place_on_space( $user_id );

		$this->hook(
			'mvs_document_drive_access',
			static function () {
				return 'none';
			}
		);

		$this->assertSame( array( 'user', 0 ), PrivacyService::resolve_drive_for_user( $user_id ) );
	}

	/**
	 * Media can be allowed where documents are not — the whole point.
	 *
	 * A space with its Files tab off says `none` for documents. Members should
	 * still be able to post a photo to that space, because "no files here" and
	 * "no photos here" are two different settings and an owner expects the
	 * toggle to mean the one it is labelled with.
	 */
	public function test_media_can_be_allowed_where_documents_are_refused(): void {
		$user_id = self::factory()->user->create();
		$this->place_on_space( $user_id );

		$this->hook(
			'mvs_document_drive_access',
			static function () {
				return 'none';
			}
		);
		$this->hook(
			'mvs_media_drive_access',
			static function () {
				return 'write';
			}
		);

		$this->assertSame(
			array( 'space', 7 ),
			PrivacyService::resolve_drive_for_user( $user_id ),
			'Media was refused a drive that its own filter allowed.'
		);
	}

	/**
	 * And narrowed the other way, so the seam is not one-directional.
	 */
	public function test_media_can_be_refused_where_documents_are_allowed(): void {
		$user_id = self::factory()->user->create();
		$this->place_on_space( $user_id );

		$this->hook(
			'mvs_document_drive_access',
			static function () {
				return 'own';
			}
		);
		$this->hook(
			'mvs_media_drive_access',
			static function () {
				return 'none';
			}
		);

		$this->assertSame( array( 'user', 0 ), PrivacyService::resolve_drive_for_user( $user_id ) );
	}

	/**
	 * A space photo makes a row the read gate will actually reach.
	 *
	 * @param int $owner Owner user id.
	 * @return int Media id.
	 */
	private function space_photo( int $owner ): int {
		$repo = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );

		return (int) $repo->insert(
			array(
				'title'       => 'Space photo ' . wp_generate_password( 6, false ),
				'post_author' => $owner,
				'media_type'  => 'image',
				'privacy'     => 'space',
				'drive_type'  => 'space',
				'drive_id'    => 7,
			)
		);
	}

	/**
	 * Baseline: with only the document filter, and it refusing, the read is refused.
	 *
	 * Split from the test below rather than asserting both in one method,
	 * because a privacy decision is memoised for the request — calling
	 * can_view() twice with different filters returns the first answer twice,
	 * which reads as the seam being broken when it is not.
	 */
	public function test_read_is_refused_when_only_the_document_filter_answers_none(): void {
		$owner  = self::factory()->user->create();
		$reader = self::factory()->user->create();
		$id     = $this->space_photo( $owner );

		$this->hook(
			'mvs_document_drive_access',
			static function () {
				return 'none';
			}
		);

		$privacy = \WPMediaVerse\Core\Plugin::container()->get( 'privacy' );
		$this->assertFalse( $privacy->can_view( $id, $reader ) );
	}

	/**
	 * The READ gate honours the media filter too.
	 *
	 * This is the half neither card asked for and the half that makes the seam
	 * safe. If placement consulted the media filter and `check_space()` kept
	 * asking the document one, a photo posted to a space whose Files tab is off
	 * would be STORED scoped to that space and then be unreadable by its own
	 * members — the same leak shape, arrived at from the opposite direction.
	 */
	public function test_the_read_gate_uses_the_media_filter_too(): void {
		$owner  = self::factory()->user->create();
		$reader = self::factory()->user->create();
		$id     = $this->space_photo( $owner );

		$this->hook(
			'mvs_document_drive_access',
			static function () {
				return 'none';
			}
		);
		$this->hook(
			'mvs_media_drive_access',
			static function () {
				return 'write';
			}
		);

		$privacy = \WPMediaVerse\Core\Plugin::container()->get( 'privacy' );
		$this->assertTrue(
			$privacy->can_view( $id, $reader ),
			'Media was placed on the drive but its members could not read it.'
		);
	}

	/**
	 * The filter receives the document answer as its default.
	 *
	 * That is what makes "leave it alone and nothing changes" true, so it is
	 * worth pinning rather than assuming.
	 */
	public function test_the_document_answer_is_passed_in_as_the_default(): void {
		$user_id = self::factory()->user->create();
		$this->place_on_space( $user_id );
		$seen = null;

		$this->hook(
			'mvs_document_drive_access',
			static function () {
				return 'write';
			}
		);
		$this->hook(
			'mvs_media_drive_access',
			static function ( $level ) use ( &$seen ) {
				$seen = $level;
				return $level;
			}
		);

		PrivacyService::resolve_drive_for_user( $user_id );

		$this->assertSame( 'write', $seen );
	}
}
