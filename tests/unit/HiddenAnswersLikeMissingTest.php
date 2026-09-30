<?php
/**
 * A private item answers exactly like a missing one (QA, 2.6.0).
 *
 * For a member who cannot see the item: /media/{id}/access, /signed-url and
 * /report answered 403 or 200 (and /report stored a report), where a missing
 * id answered 404. Album cards counted items the viewer cannot open.
 *
 * Edit and delete checked ownership but never visibility, so another member's
 * private media, album, collection, comment or message answered 403 (or 400,
 * or 200) where a missing id answered 404.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WP_REST_Request;
use WPMediaVerse\Core\Plugin;

class HiddenAnswersLikeMissingTest extends WP_UnitTestCase {

	private int $owner;
	private int $other;

	public function set_up(): void {
		parent::set_up();
		( new \WPMediaVerse\Core\Migrator() )->run();
		$this->owner = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->other = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		do_action( 'rest_api_init' );
		update_option( 'mvs_enable_reports', true );
	}

	private function media( string $privacy ): int {
		return (int) Plugin::container()->get( 'media_repository' )->insert(
			array(
				'title'             => 'Hidden probe',
				'post_author'       => $this->owner,
				'media_type'        => 'image',
				'status'            => 'publish',
				'moderation_status' => 'approved',
				'privacy'           => $privacy,
				'file_path'         => '2026/09/probe.jpg',
				'file_type'         => 'image/jpeg',
				'slug'              => 'hidden-' . wp_generate_password( 8, false, false ),
			)
		);
	}

	private function status( string $method, string $route ): int {
		$req = new WP_REST_Request( $method, $route );
		if ( 'POST' === $method ) {
			$req->set_param( 'reason', 'spam' );
		}
		return rest_do_request( $req )->get_status();
	}

	public function test_private_item_routes_answer_like_a_missing_one(): void {
		global $wpdb;
		$private = $this->media( 'private' );
		$missing = 99999999;
		wp_set_current_user( $this->other );

		foreach ( array( array( 'GET', 'access' ), array( 'GET', 'signed-url' ), array( 'POST', 'report' ) ) as $case ) {
			list( $method, $leaf ) = $case;
			$this->assertSame(
				$this->status( $method, "/mvs/v1/media/{$missing}/{$leaf}" ),
				$this->status( $method, "/mvs/v1/media/{$private}/{$leaf}" ),
				"{$method} /media/{id}/{$leaf} tells a private item apart from a missing one."
			);
		}

		$reports = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}mvs_reports WHERE target_type = 'media' AND target_id = %d", $private ) ); // phpcs:ignore
		$this->assertSame( 0, $reports, 'A report was stored for an item the reporter cannot see.' );

		wp_set_current_user( $this->owner );
		$this->assertSame( 200, $this->status( 'GET', "/mvs/v1/media/{$private}/access" ), 'The owner lost access to their own item.' );
	}

	public function test_album_card_counts_only_what_the_viewer_can_open(): void {
		$albums = Plugin::container()->get( 'albums' );
		$album  = (int) $albums->create( $this->owner, array( 'title' => 'Mixed' ) );
		// A public album still holding a private photo: the shape upgraded sites keep
		// (v40 never loosens) and the mvs_album_inherit_privacy escape hatch makes.
		// The 2.6.0 album rule would publish the photo, so switch it off here.
		add_filter( 'mvs_album_inherit_privacy', '__return_false' );
		$albums->add_items( $album, array( $this->media( 'public' ), $this->media( 'public' ), $this->media( 'private' ) ) );
		remove_filter( 'mvs_album_inherit_privacy', '__return_false' );

		$this->assertSame( 2, $albums->viewable_item_count( $album, $this->other ), 'The card counted a hidden item.' );
		$this->assertSame( 3, $albums->viewable_item_count( $album, $this->owner ) );
	}

	/**
	 * Status and error code of a request, the two things a prober compares.
	 *
	 * @return array{0:int,1:string}
	 */
	private function answer( string $method, string $route, array $params = array() ): array {
		$request = new WP_REST_Request( $method, $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		$response = rest_do_request( $request );
		$data     = (array) $response->get_data();

		return array( $response->get_status(), (string) ( $data['code'] ?? $data['error'] ?? '' ) );
	}

	public function test_edit_and_delete_answer_a_hidden_item_like_a_missing_one(): void {
		$missing = 99999999;
		$friend  = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		wp_set_current_user( $this->owner );
		$media = $this->media( 'private' );

		$album = (int) Plugin::container()->get( 'albums' )->create( $this->owner, array( 'title' => 'Hidden album' ) );
		Plugin::container()->get( 'albums' )->set_privacy( $album, 'private' );

		$collection = self::factory()->post->create(
			array(
				'post_type'   => 'mvs_collection',
				'post_status' => 'private',
				'post_author' => $this->owner,
			)
		);

		$comment = (int) ( new \WPMediaVerse\Social\CommentService() )->add( $media, $this->owner, 'On a private photo' );

		$messaging = Plugin::container()->get( 'messaging' );
		$conv      = $messaging->find_or_create_conversation( $this->owner, $friend );
		$message   = (int) $messaging->send_message( (int) $conv['conversation_id'], $this->owner, array( 'content' => 'Just us' ) )['message_id'];
		$this->assertGreaterThan( 0, $message, 'Fixture: the message was not sent.' );

		// An author-level member, so role capabilities are not what refuses them.
		$outsider = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $outsider );

		$cases = array(
			array( 'PUT', '/mvs/v1/media/%d', $media, array( 'title' => 'x' ) ),
			array( 'DELETE', '/mvs/v1/media/%d', $media, array() ),
			array( 'GET', '/mvs/v1/media/%d/favorite', $media, array() ),
			array( 'DELETE', '/mvs/v1/media/%d/reactions', $media, array() ),
			array( 'PUT', '/mvs/v1/albums/%d', $album, array( 'title' => 'x' ) ),
			array( 'DELETE', '/mvs/v1/albums/%d', $album, array() ),
			array( 'GET', '/mvs/v1/collections/%d', $collection, array() ),
			array( 'PUT', '/mvs/v1/collections/%d', $collection, array( 'title' => 'x' ) ),
			array( 'DELETE', '/mvs/v1/collections/%d', $collection, array() ),
			array( 'DELETE', '/mvs/v1/messages/%d', $message, array() ),
			array( 'DELETE', '/mvs/v1/messages/%d/unsend', $message, array() ),
		);

		foreach ( $cases as $case ) {
			list( $method, $route, $id, $params ) = $case;
			$this->assertSame(
				$this->answer( $method, sprintf( $route, $missing ), $params ),
				$this->answer( $method, sprintf( $route, $id ), $params ),
				"{$method} {$route} tells a hidden item apart from a missing one."
			);
		}

		$route = "/mvs/v1/media/{$media}/comments/%d";
		foreach ( array( 'PUT', 'DELETE' ) as $method ) {
			$this->assertSame(
				$this->answer( $method, sprintf( $route, $missing ), array( 'content' => 'x' ) ),
				$this->answer( $method, sprintf( $route, $comment ), array( 'content' => 'x' ) ),
				"{$method} comment tells a hidden comment apart from a missing one."
			);
		}

		$own = (int) Plugin::container()->get( 'media_repository' )->insert(
			array(
				'title'       => 'Mine',
				'post_author' => $outsider,
				'media_type'  => 'image',
				'status'      => 'publish',
				'privacy'     => 'public',
				'slug'        => 'mine-' . wp_generate_password( 8, false, false ),
			)
		);
		$this->assertSame(
			$this->answer(
				'POST',
				'/mvs/v1/media/bulk',
				array(
					'action'    => 'move_to_album',
					'media_ids' => array( $own ),
					'album_id'  => $missing,
				)
			),
			$this->answer(
				'POST',
				'/mvs/v1/media/bulk',
				array(
					'action'    => 'move_to_album',
					'media_ids' => array( $own ),
					'album_id'  => $album,
				)
			),
			'Bulk move tells a hidden album apart from a missing one.'
		);

		$this->assertSame( 'On a private photo', get_comment( $comment )->comment_content );
		$this->assertTrue( Plugin::container()->get( 'media_repository' )->exists( $media ) );
	}

	public function test_a_visible_item_you_do_not_own_is_still_refused_as_forbidden(): void {
		$media = $this->media( 'public' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

		$this->assertSame( array( 403, 'mvs_forbidden' ), $this->answer( 'PUT', "/mvs/v1/media/{$media}", array( 'title' => 'x' ) ) );
	}
}
