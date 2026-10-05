<?php
/**
 * The preset licence key is activated with backoff, never in a loop.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\PresetActivation;

/**
 * Card 10370576415: one site sent 933 activation POSTs in 23h because every
 * non-valid reply retried on the next admin_init (Heartbeat included).
 */
class PresetActivationTest extends WP_UnitTestCase {

	/**
	 * Store requests seen by the stub.
	 *
	 * @var int
	 */
	private int $calls = 0;

	public function tear_down(): void {
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'wp_doing_ajax' );
		delete_option( PresetActivation::OPT_DONE );
		delete_transient( PresetActivation::RETRY_TRANSIENT );
		parent::tear_down();
	}

	/**
	 * Answer every store request with $reply (array response or WP_Error).
	 *
	 * @param mixed $reply Stubbed response.
	 */
	private function store_replies( $reply ): void {
		add_filter(
			'pre_http_request',
			function () use ( $reply ) {
				++$this->calls;
				return $reply;
			}
		);
	}

	private function json( array $body, int $code = 200 ): array {
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( $body ),
			'response' => array(
				'code'    => $code,
				'message' => '',
			),
			'cookies'  => array(),
		);
	}

	public function test_valid_reply_activates_once(): void {
		$this->store_replies( $this->json( array( 'license' => 'valid' ) ) );

		PresetActivation::maybe_activate();
		PresetActivation::maybe_activate();

		$this->assertSame( 1, $this->calls );
		$this->assertSame( 1, (int) get_option( PresetActivation::OPT_DONE ) );
	}

	public function test_definitive_store_answer_is_final(): void {
		$this->store_replies(
			$this->json(
				array(
					'license' => 'invalid',
					'error'   => 'no_activations_left',
				)
			)
		);

		PresetActivation::maybe_activate();
		PresetActivation::maybe_activate();

		$this->assertSame( 1, $this->calls, 'A store answer is never retried.' );
		$this->assertSame( 'invalid', get_option( PresetActivation::OPT_DONE ) );
	}

	public function test_unanswered_attempt_waits_before_retrying(): void {
		foreach ( array(
			new \WP_Error( 'http_request_failed', 'timeout' ),
			array(
				'headers'  => array(),
				'body'     => '<html>403</html>',
				'response' => array(
					'code'    => 403,
					'message' => '',
				),
				'cookies'  => array(),
			),
		) as $reply ) {
			$this->calls = 0;
			remove_all_filters( 'pre_http_request' );
			delete_transient( PresetActivation::RETRY_TRANSIENT );
			$this->store_replies( $reply );

			PresetActivation::maybe_activate();
			PresetActivation::maybe_activate();

			$this->assertSame( 1, $this->calls, 'Second admin load inside the wait sends nothing.' );
			$this->assertFalse( get_option( PresetActivation::OPT_DONE ), 'Not final: retried after the wait.' );
			$this->assertNotFalse( get_transient( PresetActivation::RETRY_TRANSIENT ) );
		}
	}

	public function test_never_runs_on_ajax_requests(): void {
		$this->store_replies( $this->json( array( 'license' => 'valid' ) ) );
		add_filter( 'wp_doing_ajax', '__return_true' );

		PresetActivation::maybe_activate();

		$this->assertSame( 0, $this->calls, 'Heartbeat/admin-ajax never pings the store.' );
	}
}
