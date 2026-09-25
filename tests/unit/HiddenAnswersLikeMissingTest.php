<?php
/**
 * A private item answers exactly like a missing one (QA, 2.6.0).
 *
 * For a member who cannot see the item: /media/{id}/access, /signed-url and
 * /report answered 403 or 200 (and /report stored a report), where a missing
 * id answered 404. Album cards counted items the viewer cannot open.
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
		$albums->add_items( $album, array( $this->media( 'public' ), $this->media( 'public' ), $this->media( 'private' ) ) );

		$this->assertSame( 2, $albums->viewable_item_count( $album, $this->other ), 'The card counted a hidden item.' );
		$this->assertSame( 3, $albums->viewable_item_count( $album, $this->owner ) );
	}
}
