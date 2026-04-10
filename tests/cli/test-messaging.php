<?php
/**
 * CLI Tests: Messaging — Conversations, messages, read receipts, typing.
 *
 * Covers: /mvs/v1/conversations, /messages, /read, /typing, /unread-count
 */

function run_messaging_tests(): array {
	$p    = 0;
	$f    = 0;
	$data = get_test_data();
	$uid  = $data['other_id'];

	section( 'CONVERSATIONS' );
	$r = rest( 'GET', '/mvs/v1/me/conversations' );
	assert_test( 'List my conversations', is_array( $r['data'] ), $r['count'] . ' conversations' ) ? $p++ : $f++;

	$r = rest( 'GET', '/mvs/v1/me/messages/unread-count' );
	assert_test( 'Unread count', $r['ok'] || $r['status'] === 404, 'status:' . $r['status'] ) ? $p++ : $f++;

	section( 'CREATE CONVERSATION' );
	if ( $uid ) {
		$r      = rest( 'POST', '/mvs/v1/conversations', array(
			'recipient_id' => $uid,
			'message'      => 'CLI test message ' . time(),
		) );
		$conv_id = $r['data']['conversation_id'] ?? $r['data']['id'] ?? 0;
		assert_test( 'Create conversation / send message', in_array( $r['status'], array( 200, 201 ), true ), 'conv:' . $conv_id ) ? $p++ : $f++;

		if ( $conv_id ) {
			section( 'CONVERSATION MESSAGES' );
			$r = rest( 'GET', "/mvs/v1/conversations/$conv_id/messages" );
			assert_test( 'List messages', is_array( $r['data'] ), $r['count'] . ' messages' ) ? $p++ : $f++;

			// Send another message.
			$r      = rest( 'POST', "/mvs/v1/conversations/$conv_id/messages", array(
				'content' => 'Follow-up CLI test',
			) );
			$msg_id = $r['data']['id'] ?? 0;
			assert_test( 'Send follow-up message', in_array( $r['status'], array( 200, 201 ), true ), 'msg:' . $msg_id ) ? $p++ : $f++;

			// Mark read.
			$r = rest( 'POST', "/mvs/v1/conversations/$conv_id/read" );
			assert_test( 'Mark conversation read', in_array( $r['status'], array( 200, 204 ), true ), 'status:' . $r['status'] ) ? $p++ : $f++;

			// Typing indicator.
			$r = rest( 'POST', "/mvs/v1/conversations/$conv_id/typing" );
			assert_test( 'Typing indicator', in_array( $r['status'], array( 200, 204 ), true ), 'status:' . $r['status'] ) ? $p++ : $f++;

			// Unsend message.
			if ( $msg_id ) {
				$r = rest( 'POST', "/mvs/v1/messages/$msg_id/unsend" );
				assert_test( 'Unsend message', in_array( $r['status'], array( 200, 204, 403 ), true ), 'status:' . $r['status'] ) ? $p++ : $f++;
			}

			// Message reactions.
			if ( $msg_id ) {
				$r = rest( 'POST', "/mvs/v1/messages/$msg_id/reactions", array( 'emoji' => '❤️' ) );
				assert_test( 'React to message', in_array( $r['status'], array( 200, 201 ), true ), 'status:' . $r['status'] ) ? $p++ : $f++;
			}

			// Poll.
			$r = rest( 'GET', '/mvs/v1/messages/poll' );
			assert_test( 'Poll for updates', $r['ok'] || $r['status'] === 404, 'status:' . $r['status'] ) ? $p++ : $f++;
		}
	}

	return array( $p, $f );
}
