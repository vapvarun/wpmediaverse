<?php
/**
 * Webhook service for outbound notifications.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Integrations;

defined( 'ABSPATH' ) || exit;


/**
 * Outbound webhook system for media events.
 *
 * Sends signed HTTP POST payloads to configured webhook URLs
 * when media events occur (upload, delete, moderate).
 */
class WebhookService {

	/**
	 * Supported webhook events.
	 */
	const EVENTS = array(
		'media.uploaded',
		'media.updated',
		'media.deleted',
		'media.moderated',
		'media.reaction',
		'media.comment',
	);

	/**
	 * Initialize the webhook service.
	 */
	public function init(): void {
		// Always register hooks — dispatch() checks for configured webhooks at send time.
		add_action( 'mvs_media_uploaded', array( $this, 'on_media_uploaded' ) );
		add_action( 'mvs_media_deleted', array( $this, 'on_media_deleted' ), 10, 2 );
		add_action( 'mvs_moderation_changed', array( $this, 'on_media_moderated' ), 10, 2 );

		// Social events.
		add_action( 'mvs_reaction_added', array( $this, 'on_reaction' ), 10, 3 );
		add_action( 'mvs_comment_created', array( $this, 'on_comment' ), 10, 2 );
	}

	/**
	 * Handle media uploaded event.
	 *
	 * @param int $media_id Media post ID.
	 */
	public function on_media_uploaded( int $media_id ): void {
		$this->dispatch( 'media.uploaded', $this->build_media_payload( $media_id ) );
	}

	/**
	 * Handle media deleted event.
	 *
	 * @param int $media_id  Media ID.
	 * @param int $author_id Author user ID.
	 */
	public function on_media_deleted( int $media_id, int $author_id = 0 ): void {
		$this->dispatch(
			'media.deleted',
			array(
				'media_id' => $media_id,
				'author'   => $author_id,
			)
		);
	}

	/**
	 * Handle media moderated event.
	 *
	 * @param int    $media_id Media post ID.
	 * @param string $status   New moderation status.
	 */
	public function on_media_moderated( int $media_id, string $status ): void {
		$payload               = $this->build_media_payload( $media_id );
		$payload['mod_status'] = $status;
		$this->dispatch( 'media.moderated', $payload );
	}

	// Note: on_media_updated removed — media is no longer a CPT, save_post hook doesn't fire.
	// Media updates are tracked via mvs_media_index direct writes.

	/**
	 * Handle reaction event.
	 *
	 * @param int    $media_id      Media post ID.
	 * @param int    $user_id       User ID.
	 * @param string $reaction_type Reaction type.
	 */
	public function on_reaction( int $media_id, int $user_id, string $reaction_type ): void {
		$this->dispatch(
			'media.reaction',
			array(
				'media_id'      => $media_id,
				'user_id'       => $user_id,
				'reaction_type' => $reaction_type,
			)
		);
	}

	/**
	 * Handle comment event.
	 *
	 * @param int $comment_id Comment ID.
	 * @param int $media_id   Media post ID.
	 */
	public function on_comment( int $comment_id, int $media_id ): void {
		$comment = get_comment( $comment_id );
		$this->dispatch(
			'media.comment',
			array(
				'media_id'   => $media_id,
				'comment_id' => $comment_id,
				'user_id'    => $comment ? (int) $comment->user_id : 0,
				'content'    => $comment ? wp_strip_all_tags( $comment->comment_content ) : '',
			)
		);
	}

	/**
	 * Dispatch an event to all matching webhooks.
	 *
	 * @param string $event   Event name.
	 * @param array  $payload Event payload.
	 */
	private function dispatch( string $event, array $payload ): void {
		$webhooks = $this->get_webhooks();

		foreach ( $webhooks as $webhook ) {
			if ( empty( $webhook['url'] ) || empty( $webhook['events'] ) ) {
				continue;
			}

			if ( ! in_array( $event, $webhook['events'], true ) && ! in_array( '*', $webhook['events'], true ) ) {
				continue;
			}

			$body = wp_json_encode(
				array(
					'event'     => $event,
					'timestamp' => gmdate( 'c' ),
					'site_url'  => home_url(),
					'data'      => $payload,
				)
			);

			$secret    = ! empty( $webhook['secret'] ) ? $webhook['secret'] : '';
			$signature = $secret ? hash_hmac( 'sha256', $body, $secret ) : '';

			$headers = array(
				'Content-Type'   => 'application/json',
				'X-MVS-Event'    => $event,
				'X-MVS-Delivery' => wp_generate_uuid4(),
			);

			if ( $signature ) {
				$headers['X-MVS-Signature'] = 'sha256=' . $signature;
			}

			// Use Action Scheduler for async delivery if available.
			if ( function_exists( 'as_enqueue_async_action' ) ) {
				as_enqueue_async_action(
					'mvs_deliver_webhook',
					array(
						'url'     => $webhook['url'],
						'body'    => $body,
						'headers' => $headers,
					),
					'wpmediaverse'
				);
			} else {
				$this->send( $webhook['url'], $body, $headers );
			}
		}
	}

	/**
	 * Send a webhook HTTP request.
	 *
	 * @param string $url     Webhook URL.
	 * @param string $body    JSON body.
	 * @param array  $headers HTTP headers.
	 * @return bool True on success.
	 */
	public function send( string $url, string $body, array $headers, int $attempt = 1 ): bool {
		$response = wp_remote_post(
			$url,
			array(
				'body'      => $body,
				'headers'   => $headers,
				'timeout'   => 15,
				'sslverify' => (bool) apply_filters( 'mvs_webhook_sslverify', ! ( function_exists( 'wp_is_local_environment' ) && wp_is_local_environment() ), $url ),
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->log_failure( $url, $response->get_error_message() );
			return $this->maybe_retry( $url, $body, $headers, $attempt );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			$this->log_failure( $url, "HTTP {$code}" );
			// Retry on 5xx server errors, not 4xx client errors.
			if ( $code >= 500 ) {
				return $this->maybe_retry( $url, $body, $headers, $attempt );
			}
			return false;
		}

		return true;
	}

	/**
	 * Schedule a webhook retry via Action Scheduler.
	 *
	 * Max 3 attempts with 5-minute intervals.
	 *
	 * @param string $url     Webhook URL.
	 * @param string $body    JSON body.
	 * @param array  $headers HTTP headers.
	 * @param int    $attempt Current attempt number.
	 * @return bool Always false (current attempt failed).
	 */
	private function maybe_retry( string $url, string $body, array $headers, int $attempt ): bool {
		if ( $attempt >= 3 || ! function_exists( 'as_schedule_single_action' ) ) {
			return false;
		}

		as_schedule_single_action(
			time() + ( 300 * $attempt ), // 5min, 10min.
			'mvs_deliver_webhook',
			array(
				'url'     => $url,
				'body'    => $body,
				'headers' => $headers,
				'attempt' => $attempt + 1,
			),
			'wpmediaverse'
		);

		return false;
	}

	/**
	 * Build a standard media payload.
	 *
	 * @param int $media_id Media post ID.
	 * @return array
	 */
	private function build_media_payload( int $media_id ): array {
		$data = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get_all( $media_id );
		if ( empty( $data ) ) {
			return array( 'media_id' => $media_id );
		}

		return array(
			'media_id'  => $media_id,
			'title'     => $data['title'] ?? '',
			'author'    => (int) ( $data['post_author'] ?? 0 ),
			'url'       => \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get_permalink( $media_id ),
			'file_url'  => $data['file_url'] ?? '',
			'file_type' => $data['file_type'] ?? '',
			'privacy'   => $data['privacy'] ?? 'public',
			'status'    => $data['status'] ?? '',
		);
	}

	/**
	 * Get configured webhooks from options.
	 *
	 * @return array Array of webhook configs.
	 */
	private function get_webhooks(): array {
		$webhooks = get_option( 'mvs_webhooks', array() );
		return is_array( $webhooks ) ? $webhooks : array();
	}

	/**
	 * Log a webhook delivery failure.
	 *
	 * @param string $url   Webhook URL.
	 * @param string $error Error message.
	 */
	private function log_failure( string $url, string $error ): void {
		$log = get_option( 'mvs_webhook_failures', array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}

		$log[] = array(
			'url'   => $url,
			'error' => $error,
			'time'  => current_time( 'mysql', true ),
		);

		// Keep last 50 failures.
		$log = array_slice( $log, -50 );
		update_option( 'mvs_webhook_failures', $log, false );
	}
}
