<?php
/**
 * Chat files: what happens to a file sent in a message, the way any messenger works.
 *
 * Since 2.6.2 a chat file is conversation-scoped 'dm' media (see
 * MessagingController::upload_attachment()). This class covers the rest of its life:
 *
 * - Deleting or unsending the message removes the file, for everyone.
 * - Files sent before 2.6.2 were public WordPress attachments under their own
 *   names. A background job moves every sent one into 'dm' media, re-points its
 *   message and deletes the public copy; uploads that were never sent are
 *   deleted. Until it finishes they are kept out of every attachment listing
 *   (/wp/v2/media, the Media Library).
 *
 * Basecamp 10392474704.
 *
 * @package WPMediaVerse
 * @since   2.6.2
 */

declare( strict_types=1 );

namespace WPMediaVerse\Messaging;

use WPMediaVerse\Core\Plugin;
use WPMediaVerse\Services\DirectDelivery;
use WPMediaVerse\Services\LoggerService;
use WPMediaVerse\Services\StorageLimitService;

defined( 'ABSPATH' ) || exit;

/**
 * Lifecycle of files sent in messages.
 */
final class ChatFiles {

	/**
	 * Background job that moves pre-2.6.2 chat attachments.
	 */
	public const HOOK = 'mvs_move_chat_attachments';

	/**
	 * Progress: array{moved: int, removed: int, failed: int, done: bool}.
	 */
	public const OPTION = 'mvs_chat_attachments_moved';

	/**
	 * Marks an attachment the job could not move, so it is not retried forever.
	 */
	private const FAILED_META = '_mvs_dm_move_failed';

	/**
	 * Attachments per batch: each one is a file copy and a delete.
	 */
	private const BATCH = 25;

	/**
	 * Hook everything. Runs whether or not Messages is switched on: old chat
	 * files are public either way.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'mvs_message_deleted', array( self::class, 'on_message_deleted' ), 10, 1 );
		add_action( self::HOOK, array( self::class, 'run_batch' ) );
		add_action( 'wp_loaded', array( self::class, 'maybe_start' ) );
		add_action( 'pre_get_posts', array( self::class, 'hide_legacy' ) );
	}

	/**
	 * Remove the file of a deleted or unsent message, as a messenger does.
	 *
	 * Only the sender's own chat file, and only when no other live message
	 * still shows it.
	 *
	 * @param int $message_id Message id.
	 * @return void
	 */
	public static function on_message_deleted( $message_id ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'mvs_messages';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT sender_id, media_id, attachment_id FROM {$table} WHERE id = %d", (int) $message_id ) );
		if ( ! $row ) {
			return;
		}
		$sender = (int) $row->sender_id;

		$media_id = (int) $row->media_id;
		if ( $media_id > 0 && ! self::still_shown( 'media_id', $media_id, (int) $message_id ) ) {
			$repo = Plugin::container()->get( 'media_repository' );
			if ( 'dm' === (string) $repo->get( $media_id, 'privacy' ) && (int) $repo->get( $media_id, 'post_author' ) === $sender ) {
				$repo->delete_cascade( $media_id );
			}
		}

		$attachment_id = (int) $row->attachment_id;
		if ( $attachment_id > 0 && ! self::still_shown( 'attachment_id', $attachment_id, (int) $message_id ) && self::is_legacy_chat_file( $attachment_id, $sender ) ) {
			wp_delete_attachment( $attachment_id, true );
		}
	}

	/**
	 * Start the move when it has not finished: admin page loads and cron only,
	 * never a visitor's request, and only when no batch is already waiting.
	 *
	 * @return void
	 */
	public static function maybe_start(): void {
		if ( self::done() || ! DirectDelivery::is_scheduling_request() ) {
			return;
		}
		$queued = function_exists( 'as_has_scheduled_action' )
			? as_has_scheduled_action( self::HOOK, null, 'wpmediaverse' )
			: (bool) wp_next_scheduled( self::HOOK );
		if ( ! $queued ) {
			self::schedule();
		}
	}

	/**
	 * Move one batch of old chat attachments, then queue the next or finish.
	 *
	 * @return void
	 */
	public static function run_batch(): void {
		if ( self::done() ) {
			return;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ids = array_map(
			'intval',
			(array) $wpdb->get_col(
				$wpdb->prepare(
					"SELECT pm.post_id FROM {$wpdb->postmeta} pm
					 WHERE pm.meta_key = %s
					   AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} f WHERE f.post_id = pm.post_id AND f.meta_key = %s )
					 ORDER BY pm.post_id DESC LIMIT %d",
					StorageLimitService::DM_SIZE_META,
					self::FAILED_META,
					self::BATCH
				)
			)
		);

		$state = self::state();
		foreach ( $ids as $attachment_id ) {
			++$state[ self::move( $attachment_id ) ];
		}
		$state['done'] = count( $ids ) < self::BATCH;
		update_option( self::OPTION, $state, true );
		if ( ! $state['done'] ) {
			self::schedule();
		}
	}

	/**
	 * Keep old chat attachments out of every attachment listing until moved.
	 *
	 * @param \WP_Query $query Query about to run.
	 * @return void
	 */
	public static function hide_legacy( $query ): void {
		if ( ! $query instanceof \WP_Query || self::done() || ! in_array( 'attachment', (array) $query->get( 'post_type' ), true ) ) {
			return;
		}
		$meta   = (array) $query->get( 'meta_query' );
		$meta[] = array(
			'key'     => StorageLimitService::DM_SIZE_META,
			'compare' => 'NOT EXISTS',
		);
		$query->set( 'meta_query', $meta );
	}

	/**
	 * Move one old chat attachment.
	 *
	 * @param int $attachment_id Attachment id.
	 * @return string 'moved' | 'removed' | 'failed'.
	 */
	private static function move( int $attachment_id ): string {
		global $wpdb;
		$table = $wpdb->prefix . 'mvs_messages';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sent = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE attachment_id = %d", $attachment_id ) );
		$path = (string) get_attached_file( $attachment_id );

		if ( 0 === $sent || '' === $path || ! is_readable( $path ) ) {
			// Never sent, or its file is already gone: nothing to keep.
			wp_delete_attachment( $attachment_id, true );
			return 'removed';
		}

		$author = (int) get_post_field( 'post_author', $attachment_id );
		$size   = (int) get_post_meta( $attachment_id, StorageLimitService::DM_SIZE_META, true );
		$tmp    = wp_tempnam( basename( $path ) );
		if ( ! $tmp || ! copy( $path, $tmp ) ) {
			return self::fail( $attachment_id, 'copy failed' );
		}

		// The size is counted once: off the attachment, onto the new media.
		delete_post_meta( $attachment_id, StorageLimitService::DM_SIZE_META );
		$skip = static function () {
			return true; // The file is already on this server, not a browser upload.
		};
		add_filter( 'mvs_upload_skip_move_uploaded_file_check', $skip );
		$media_id = Plugin::container()->get( 'upload' )->handle(
			array(
				'name'     => basename( $path ),
				'tmp_name' => $tmp,
				'type'     => (string) get_post_mime_type( $attachment_id ),
				'error'    => UPLOAD_ERR_OK,
				'size'     => (int) filesize( $tmp ),
			),
			$author,
			array(
				'privacy' => 'dm',
				'title'   => get_the_title( $attachment_id ),
			)
		);
		remove_filter( 'mvs_upload_skip_move_uploaded_file_check', $skip );

		if ( is_wp_error( $media_id ) ) {
			wp_delete_file( $tmp );
			update_post_meta( $attachment_id, StorageLimitService::DM_SIZE_META, $size );
			return self::fail( $attachment_id, $media_id->get_error_message() );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			$table,
			array(
				'media_id'      => (int) $media_id,
				'attachment_id' => null,
			),
			array( 'attachment_id' => $attachment_id )
		);
		wp_delete_attachment( $attachment_id, true );
		return 'moved';
	}

	/**
	 * Record an attachment the job could not move and log why.
	 *
	 * @param int    $attachment_id Attachment id.
	 * @param string $reason        Why.
	 * @return string 'failed'.
	 */
	private static function fail( int $attachment_id, string $reason ): string {
		update_post_meta( $attachment_id, self::FAILED_META, $reason );
		LoggerService::error(
			'messaging',
			'Chat attachment could not be moved to protected storage',
			array(
				'attachment_id' => $attachment_id,
				'reason'        => $reason,
			)
		);
		return 'failed';
	}

	/**
	 * Whether another live message still shows this file.
	 *
	 * @param string $column     'media_id' or 'attachment_id'.
	 * @param int    $id         File id.
	 * @param int    $message_id The message being deleted.
	 * @return bool
	 */
	private static function still_shown( string $column, int $id, int $message_id ): bool {
		global $wpdb;
		$table  = $wpdb->prefix . 'mvs_messages';
		$column = 'media_id' === $column ? 'media_id' : 'attachment_id';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return null !== $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$table} WHERE {$column} = %d AND id <> %d AND is_deleted = 0 AND deleted_for_all = 0 LIMIT 1", $id, $message_id ) );
	}

	/**
	 * Whether an attachment is a pre-2.6.2 chat file of this sender.
	 *
	 * @param int $attachment_id Attachment id.
	 * @param int $sender        Sender id.
	 * @return bool
	 */
	private static function is_legacy_chat_file( int $attachment_id, int $sender ): bool {
		return (int) get_post_field( 'post_author', $attachment_id ) === $sender
			&& '' !== (string) get_post_meta( $attachment_id, StorageLimitService::DM_SIZE_META, true );
	}

	/**
	 * Has the move finished?
	 *
	 * @return bool
	 */
	private static function done(): bool {
		return ! empty( self::state()['done'] );
	}

	/**
	 * Current progress.
	 *
	 * @return array{moved: int, removed: int, failed: int, done: bool}
	 */
	private static function state(): array {
		return wp_parse_args(
			(array) get_option( self::OPTION, array() ),
			array(
				'moved'   => 0,
				'removed' => 0,
				'failed'  => 0,
				'done'    => false,
			)
		);
	}

	/**
	 * Queue one batch (Action Scheduler, else WP-Cron).
	 *
	 * @return void
	 */
	private static function schedule(): void {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::HOOK, array(), 'wpmediaverse' );
		} else {
			wp_schedule_single_event( time(), self::HOOK );
		}
	}
}
