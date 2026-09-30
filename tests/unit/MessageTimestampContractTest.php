<?php
/**
 * Message timestamps carry an unambiguous UTC value (Zoho #41777).
 *
 * `created_at` stays the naive UTC string the mobile app parses (it appends
 * "Z" itself, so an ISO value there would break installed apps). Every
 * message response must also carry `created_at_gmt`, ISO-8601 with Z, for
 * clients that pass the value straight to `new Date()` - BuddyNext's DM
 * screen read the naive value as local time and the clock jumped by the
 * viewer's offset.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WP_REST_Request;
use WPMediaVerse\Core\Plugin;

class MessageTimestampContractTest extends WP_UnitTestCase {

	private function assert_utc_pair( array $message, string $where ): void {
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $message['created_at'], "{$where}: created_at changed shape; the app parses it." );
		$this->assertArrayHasKey( 'created_at_gmt', $message, "{$where}: no created_at_gmt." );
		$this->assertSame( str_replace( ' ', 'T', $message['created_at'] ) . 'Z', $message['created_at_gmt'], "{$where}: created_at_gmt is not the same instant in UTC." );
	}

	public function test_send_thread_and_poll_carry_utc_iso(): void {
		( new \WPMediaVerse\Core\Migrator() )->run();
		$alice = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$bob   = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$convo = (int) Plugin::container()->get( 'messaging' )->find_or_create_conversation( $alice, $bob )['conversation_id'];
		do_action( 'rest_api_init' );
		wp_set_current_user( $alice );

		$send = new WP_REST_Request( 'POST', '/mvs/v1/conversations/' . $convo . '/messages' );
		$send->set_param( 'content', 'clock check' );
		$sent = rest_do_request( $send );
		$this->assertLessThan( 300, $sent->get_status() );
		$sent_data = json_decode( (string) wp_json_encode( rest_get_server()->response_to_data( $sent, false ) ), true );
		$this->assert_utc_pair( (array) ( $sent_data['message'] ?? $sent_data ), 'send' );

		$thread = json_decode( (string) wp_json_encode( rest_get_server()->response_to_data( rest_do_request( new WP_REST_Request( 'GET', '/mvs/v1/conversations/' . $convo . '/messages' ) ), false ) ), true );
		$thread_messages = (array) ( $thread['messages'] ?? $thread );
		$this->assertNotEmpty( $thread_messages );
		$this->assert_utc_pair( (array) reset( $thread_messages ), 'thread' );

		wp_set_current_user( $bob );
		$poll = new WP_REST_Request( 'GET', '/mvs/v1/messages/poll' );
		$poll->set_param( 'since', gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) );
		$poll_data = json_decode( (string) wp_json_encode( rest_get_server()->response_to_data( rest_do_request( $poll ), false ) ), true );
		$found     = array();
		array_walk_recursive(
			$poll_data,
			static function ( $value, $key ) use ( &$found ) {
				if ( 'created_at_gmt' === $key ) {
					$found[] = $value;
				}
			}
		);
		$this->assertNotEmpty( $found, 'poll: no created_at_gmt anywhere in the response.' );
	}
}
