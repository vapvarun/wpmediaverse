<?php
/**
 * Messaging service — conversations and messages CRUD, privacy, follow-gate.
 *
 * @package WPMediaVerse
 * @since   1.1.0
 */

namespace WPMediaVerse\Messaging;

defined( 'ABSPATH' ) || exit;

use WPMediaVerse\Repository\MediaRepository;

class MessagingService {

	/**
	 * Rate limit constants.
	 */
	const RATE_MESSAGES_PER_MIN = 30;
	const RATE_CONVOS_PER_HOUR  = 10;
	const MAX_MESSAGE_LENGTH    = 2000;
	const UNSEND_WINDOW_SECONDS = 900; // 15 min.
	const MAX_PINNED            = 3;
	const ONLINE_THRESHOLD      = 120; // 2 min.
	const COALESCE_SECONDS      = 30;

	// -------------------------------------------------------------------------
	// Privacy & Access
	// -------------------------------------------------------------------------

	/**
	 * Check if a user can message another user.
	 *
	 * @param int $sender_id   Sender user ID.
	 * @param int $recipient_id Recipient user ID.
	 * @return array{allowed: bool, reason: string, is_request: bool}
	 */
	public function can_message( int $sender_id, int $recipient_id ): array {
		if ( $sender_id === $recipient_id ) {
			return array(
				'allowed'    => false,
				'reason'     => 'cannot_message_self',
				'is_request' => false,
			);
		}

		// Block check via ReportService.
		$report_service = \WPMediaVerse\Core\Plugin::container()->get( 'reports' );
		if ( $report_service && $report_service->is_blocked_either_way( $sender_id, $recipient_id ) ) {
			return array(
				'allowed'    => false,
				'reason'     => 'blocked',
				'is_request' => false,
			);
		}

		// BuddyNext integration hook — allows external block lists.
		$allowed = apply_filters( 'mvs_can_send_message', true, $sender_id, $recipient_id );
		if ( ! $allowed ) {
			return array(
				'allowed'    => false,
				'reason'     => 'blocked',
				'is_request' => false,
			);
		}

		// Min account age check.
		$min_age = (int) get_option( 'mvs_dm_min_age', 0 );
		if ( $min_age > 0 ) {
			$sender = get_userdata( $sender_id );
			if ( $sender ) {
				$reg_time = strtotime( $sender->user_registered );
				$age_days = ( time() - $reg_time ) / DAY_IN_SECONDS;
				if ( $age_days < $min_age ) {
					return array(
						'allowed'    => false,
						'reason'     => 'account_too_new',
						'is_request' => false,
					);
				}
			}
		}

		// DM access level.
		$access = get_user_meta( $recipient_id, '_mvs_dm_access', true );
		if ( ! $access ) {
			$access = get_option( 'mvs_dm_access', 'everyone' );
		}

		$access = apply_filters( 'mvs_dm_access_level', $access, $sender_id, $recipient_id );

		if ( 'nobody' === $access ) {
			return array(
				'allowed'    => false,
				'reason'     => 'dms_disabled',
				'is_request' => false,
			);
		}

		// For followers/mutual checks, use free plugin's FollowService.
		$follow_service = \WPMediaVerse\Core\Plugin::container()->get( 'follows' );

		switch ( $access ) {
			case 'followers':
				if ( $follow_service && ! $follow_service->is_following( $sender_id, $recipient_id ) ) {
					return array(
						'allowed'    => true,
						'reason'     => 'request',
						'is_request' => true,
					);
				}
				break;

			case 'mutual':
				if ( $follow_service ) {
					$a_follows_b = $follow_service->is_following( $sender_id, $recipient_id );
					$b_follows_a = $follow_service->is_following( $recipient_id, $sender_id );
					if ( ! $a_follows_b || ! $b_follows_a ) {
						if ( $a_follows_b ) {
							return array(
								'allowed'    => true,
								'reason'     => 'request',
								'is_request' => true,
							);
						}
						return array(
							'allowed'    => false,
							'reason'     => 'mutual_follow_required',
							'is_request' => false,
						);
					}
				}
				break;
		}

		return array(
			'allowed'    => true,
			'reason'     => '',
			'is_request' => false,
		);
	}

	// -------------------------------------------------------------------------
	// Rate Limiting
	// -------------------------------------------------------------------------

	/**
	 * Check message rate limit.
	 *
	 * @param int $user_id User ID.
	 * @return bool True if within limit.
	 */
	public function check_message_rate( int $user_id ): bool {
		$limit = (int) apply_filters( 'mvs_dm_message_rate_limit', self::RATE_MESSAGES_PER_MIN );
		$key   = 'mvs_dm_msg_rate_' . $user_id;
		$count = (int) get_transient( $key );

		if ( $count >= $limit ) {
			return false;
		}

		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
		return true;
	}

	/**
	 * Check conversation creation rate limit.
	 *
	 * @param int $user_id User ID.
	 * @return bool True if within limit.
	 */
	public function check_convo_rate( int $user_id ): bool {
		$limit = (int) apply_filters( 'mvs_dm_convo_rate_limit', self::RATE_CONVOS_PER_HOUR );
		$key   = 'mvs_dm_convo_rate_' . $user_id;
		$count = (int) get_transient( $key );

		if ( $count >= $limit ) {
			return false;
		}

		set_transient( $key, $count + 1, HOUR_IN_SECONDS );
		return true;
	}

	/**
	 * Check for duplicate message (anti-spam).
	 *
	 * @param int    $user_id User ID.
	 * @param string $content Message content.
	 * @return bool True if duplicate detected.
	 */
	public function is_duplicate( int $user_id, string $content ): bool {
		$key  = 'mvs_dm_dup_' . $user_id;
		$hash = md5( $content );
		$last = get_transient( $key );

		if ( $last === $hash ) {
			return true;
		}

		set_transient( $key, $hash, 5 );
		return false;
	}

	// -------------------------------------------------------------------------
	// Conversations
	// -------------------------------------------------------------------------

	/**
	 * Find or create a 1:1 conversation.
	 *
	 * @param int $user_a First user.
	 * @param int $user_b Second user.
	 * @return array{conversation_id: int, created: bool, status: string}
	 */
	public function find_or_create_conversation( int $user_a, int $user_b ): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$conv_table = $wpdb->prefix . 'mvs_conversations';
		$part_table = $wpdb->prefix . 'mvs_conversation_participants';

		// Find existing conversation between these two users.
		$existing_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT p1.conversation_id
				FROM {$part_table} p1
				INNER JOIN {$part_table} p2 ON p1.conversation_id = p2.conversation_id
				INNER JOIN {$conv_table} c ON c.id = p1.conversation_id
				WHERE p1.user_id = %d AND p2.user_id = %d AND c.type = 'direct'
				LIMIT 1",
				$user_a,
				$user_b
			)
		);

		if ( $existing_id ) {
			// Reactivate if the current user had left.
			$wpdb->update(
				$part_table,
				array(
					'status'      => 'active',
					'is_archived' => 0,
				),
				array(
					'conversation_id' => $existing_id,
					'user_id'         => $user_a,
				),
				array( '%s', '%d' ),
				array( '%d', '%d' )
			);

			$participant = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT status FROM {$part_table} WHERE conversation_id = %d AND user_id = %d",
					$existing_id,
					$user_a
				)
			);

			return array(
				'conversation_id' => (int) $existing_id,
				'created'         => false,
				'status'          => $participant ? $participant->status : 'active',
			);
		}

		// Check access.
		$access = $this->can_message( $user_a, $user_b );
		if ( ! $access['allowed'] ) {
			return array(
				'conversation_id' => 0,
				'created'         => false,
				'status'          => $access['reason'],
			);
		}

		// Rate limit.
		if ( ! $this->check_convo_rate( $user_a ) ) {
			return array(
				'conversation_id' => 0,
				'created'         => false,
				'status'          => 'rate_limited',
			);
		}

		$now = current_time( 'mysql', true );

		$wpdb->insert(
			$conv_table,
			array(
				'type'             => 'direct',
				'created_by'       => $user_a,
				'last_activity_at' => $now,
				'created_at'       => $now,
			),
			array( '%s', '%d', '%s', '%s' )
		);
		$conv_id = (int) $wpdb->insert_id;

		if ( ! $conv_id ) {
			return array(
				'conversation_id' => 0,
				'created'         => false,
				'status'          => 'db_error',
			);
		}

		// Add creator as active participant.
		$wpdb->insert(
			$part_table,
			array(
				'conversation_id' => $conv_id,
				'user_id'         => $user_a,
				'status'          => 'active',
				'joined_at'       => $now,
			),
			array( '%d', '%d', '%s', '%s' )
		);

		// Add recipient — check if this is a request.
		$recipient_status = $access['is_request'] ? 'request_pending' : 'active';

		$wpdb->insert(
			$part_table,
			array(
				'conversation_id' => $conv_id,
				'user_id'         => $user_b,
				'status'          => $recipient_status,
				'joined_at'       => $now,
			),
			array( '%d', '%d', '%s', '%s' )
		);

		// phpcs:enable

		/**
		 * Fires when a new conversation is created.
		 *
		 * @param int   $conv_id         Conversation ID.
		 * @param int   $user_a          Creator user ID.
		 * @param int[] $participant_ids All participant user IDs.
		 */
		do_action( 'mvs_conversation_created', $conv_id, $user_a, array( $user_a, $user_b ) );

		// Fire message_sent hook so DM notifications reach the recipient even
		// when the conversation is created without an initial send_message() call.
		do_action( 'mvs_message_sent', 0, $conv_id, $user_a, array( $user_b ) );

		return array(
			'conversation_id' => $conv_id,
			'created'         => true,
			'status'          => $recipient_status,
		);
	}

	/**
	 * Get conversations for a user.
	 *
	 * @param int    $user_id  User ID.
	 * @param string $tab      Tab: all, unread, requests.
	 * @param int    $per_page Items per page.
	 * @param int    $page     Page number.
	 * @return array
	 */
	public function get_conversations( int $user_id, string $tab = 'all', int $per_page = 20, int $page = 1 ): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$conv_table = $wpdb->prefix . 'mvs_conversations';
		$part_table = $wpdb->prefix . 'mvs_conversation_participants';
		$msg_table  = $wpdb->prefix . 'mvs_messages';

		$offset = ( $page - 1 ) * $per_page;

		$where_parts = array( 'p.user_id = %d' );
		$params      = array( $user_id );

		switch ( $tab ) {
			case 'unread':
				$where_parts[] = "p.status = 'active'";
				$where_parts[] = 'p.is_archived = 0';
				$where_parts[] = '(p.last_read_at IS NULL OR c.last_activity_at > p.last_read_at)';
				$where_parts[] = 'c.last_message_id IS NOT NULL';
				break;

			case 'requests':
				$where_parts[] = "p.status = 'request_pending'";
				break;

			default: // all.
				$where_parts[] = "p.status IN ('active', 'request_pending')";
				$where_parts[] = 'p.is_archived = 0';
				break;
		}

		$where_sql = implode( ' AND ', $where_parts );

		$conversations = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.*, p.last_read_at, p.is_muted, p.muted_until, p.is_pinned, p.is_archived, p.status AS participant_status
				FROM {$conv_table} c
				INNER JOIN {$part_table} p ON p.conversation_id = c.id
				WHERE {$where_sql}
				ORDER BY p.is_pinned DESC, c.last_activity_at DESC
				LIMIT %d OFFSET %d",
				array_merge( $params, array( $per_page, $offset ) )
			)
		);

		// phpcs:enable

		// Enrich with participant data and unread count.
		foreach ( $conversations as &$conv ) {
			$conv->participants = $this->get_participants( (int) $conv->id );
			$conv->unread_count = $this->get_conversation_unread_count( (int) $conv->id, $user_id );
		}

		return $conversations;
	}

	/**
	 * Get conversation by ID with access check.
	 *
	 * @param int $conversation_id Conversation ID.
	 * @param int $user_id         User requesting access.
	 * @return object|null
	 */
	public function get_conversation( int $conversation_id, int $user_id ) {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$conv_table = $wpdb->prefix . 'mvs_conversations';
		$part_table = $wpdb->prefix . 'mvs_conversation_participants';

		$conv = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT c.*, p.last_read_at, p.is_muted, p.muted_until, p.is_pinned, p.is_archived, p.status AS participant_status
				FROM {$conv_table} c
				INNER JOIN {$part_table} p ON p.conversation_id = c.id AND p.user_id = %d
				WHERE c.id = %d",
				$user_id,
				$conversation_id
			)
		);

		// phpcs:enable

		if ( ! $conv ) {
			return null;
		}

		$conv->participants = $this->get_participants( $conversation_id );
		$conv->unread_count = $this->get_conversation_unread_count( $conversation_id, $user_id );

		return $conv;
	}

	/**
	 * Get participants of a conversation.
	 *
	 * @param int $conversation_id Conversation ID.
	 * @return array
	 */
	public function get_participants( int $conversation_id ): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$part_table = $wpdb->prefix . 'mvs_conversation_participants';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id, status, last_read_at FROM {$part_table} WHERE conversation_id = %d",
				$conversation_id
			)
		);
		// phpcs:enable

		$participants = array();
		foreach ( $rows as $row ) {
			$user = get_userdata( (int) $row->user_id );
			if ( ! $user ) {
				continue;
			}
			$participants[] = array(
				'id'           => (int) $row->user_id,
				'display_name' => $user->display_name,
				'avatar_url'   => get_avatar_url( $row->user_id, array( 'size' => 96 ) ),
				'status'       => $row->status,
				'last_read_at' => $row->last_read_at,
				'is_online'    => $this->is_user_online( (int) $row->user_id ),
				'last_active'  => $this->get_last_active( (int) $row->user_id ),
			);
		}

		return $participants;
	}

	/**
	 * Update conversation participant settings (mute, pin, archive).
	 *
	 * @param int   $conversation_id Conversation ID.
	 * @param int   $user_id         User ID.
	 * @param array $data            Fields to update.
	 * @return bool
	 */
	public function update_participant( int $conversation_id, int $user_id, array $data ): bool {
		global $wpdb;

		$part_table = $wpdb->prefix . 'mvs_conversation_participants';
		$allowed    = array( 'is_muted', 'muted_until', 'is_pinned', 'is_archived' );
		$update     = array();
		$formats    = array();

		foreach ( $data as $key => $value ) {
			if ( ! in_array( $key, $allowed, true ) ) {
				continue;
			}

			if ( 'is_pinned' === $key && $value ) {
				// Check pin limit.
				$pinned_count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$part_table} WHERE user_id = %d AND is_pinned = 1",
						$user_id
					)
				);
				if ( $pinned_count >= self::MAX_PINNED ) {
					return false;
				}
			}

			$update[ $key ] = $value;
			$formats[]      = is_int( $value ) ? '%d' : '%s';
		}

		if ( empty( $update ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->update(
			$part_table,
			$update,
			array(
				'conversation_id' => $conversation_id,
				'user_id'         => $user_id,
			),
			$formats,
			array( '%d', '%d' )
		);

		return false !== $result;
	}

	/**
	 * Leave / delete a conversation (for the user).
	 *
	 * @param int $conversation_id Conversation ID.
	 * @param int $user_id         User ID.
	 * @return bool
	 */
	public function leave_conversation( int $conversation_id, int $user_id ): bool {
		global $wpdb;

		$part_table = $wpdb->prefix . 'mvs_conversation_participants';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->update(
			$part_table,
			array( 'status' => 'left' ),
			array(
				'conversation_id' => $conversation_id,
				'user_id'         => $user_id,
			),
			array( '%s' ),
			array( '%d', '%d' )
		);

		return false !== $result;
	}

	/**
	 * Accept a message request.
	 *
	 * @param int $conversation_id Conversation ID.
	 * @param int $user_id         User accepting.
	 * @return bool
	 */
	public function accept_request( int $conversation_id, int $user_id ): bool {
		global $wpdb;

		$part_table = $wpdb->prefix . 'mvs_conversation_participants';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->update(
			$part_table,
			array( 'status' => 'active' ),
			array(
				'conversation_id' => $conversation_id,
				'user_id'         => $user_id,
				'status'          => 'request_pending',
			),
			array( '%s' ),
			array( '%d', '%d', '%s' )
		);

		if ( false !== $result && $result > 0 ) {
			do_action( 'mvs_message_request_accepted', $conversation_id, $user_id );
			return true;
		}

		return false;
	}

	/**
	 * Decline a message request.
	 *
	 * @param int $conversation_id Conversation ID.
	 * @param int $user_id         User declining.
	 * @return bool
	 */
	public function decline_request( int $conversation_id, int $user_id ): bool {
		global $wpdb;

		$part_table = $wpdb->prefix . 'mvs_conversation_participants';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->update(
			$part_table,
			array( 'status' => 'request_declined' ),
			array(
				'conversation_id' => $conversation_id,
				'user_id'         => $user_id,
				'status'          => 'request_pending',
			),
			array( '%s' ),
			array( '%d', '%d', '%s' )
		);

		return false !== $result && $result > 0;
	}

	// -------------------------------------------------------------------------
	// Messages
	// -------------------------------------------------------------------------

	/**
	 * Send a message in a conversation.
	 *
	 * @param int   $conversation_id Conversation ID.
	 * @param int   $sender_id       Sender user ID.
	 * @param array $data            Message data: content, message_type, attachment_id, media_id, parent_id, metadata.
	 * @return array{success: bool, message_id: int, error: string}
	 */
	public function send_message( int $conversation_id, int $sender_id, array $data ): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$conv_table = $wpdb->prefix . 'mvs_conversations';
		$part_table = $wpdb->prefix . 'mvs_conversation_participants';
		$msg_table  = $wpdb->prefix . 'mvs_messages';

		// Verify sender is a participant.
		$participant = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT status FROM {$part_table} WHERE conversation_id = %d AND user_id = %d",
				$conversation_id,
				$sender_id
			)
		);

		if ( ! $participant || ! in_array( $participant->status, array( 'active', 'request_pending' ), true ) ) {
			return array(
				'success'    => false,
				'message_id' => 0,
				'error'      => 'not_participant',
			);
		}

		// Rate limit.
		if ( ! $this->check_message_rate( $sender_id ) ) {
			return array(
				'success'    => false,
				'message_id' => 0,
				'error'      => 'rate_limited',
			);
		}

		$content      = isset( $data['content'] ) ? $this->sanitize_message( $data['content'] ) : '';
		$message_type = sanitize_text_field( $data['message_type'] ?? 'text' );
		$max_length   = (int) apply_filters( 'mvs_message_max_length', self::MAX_MESSAGE_LENGTH );

		// Validate content: text-only messages require content, but attachment/media messages allow empty text.
		$has_attachment = ! empty( $data['attachment_id'] ) || ! empty( $data['media_id'] );
		if ( 'text' === $message_type && empty( $content ) && ! $has_attachment ) {
			return array(
				'success'    => false,
				'message_id' => 0,
				'error'      => 'empty_content',
			);
		}

		if ( mb_strlen( $content ) > $max_length ) {
			return array(
				'success'    => false,
				'message_id' => 0,
				'error'      => 'content_too_long',
			);
		}

		// Duplicate check.
		if ( $content && $this->is_duplicate( $sender_id, $content ) ) {
			return array(
				'success'    => false,
				'message_id' => 0,
				'error'      => 'duplicate_message',
			);
		}

		$allowed_types = apply_filters(
			'mvs_message_types',
			array( 'text', 'media_share', 'image', 'video', 'audio', 'voice', 'file', 'system' )
		);
		if ( ! in_array( $message_type, $allowed_types, true ) ) {
			$message_type = 'text';
		}

		$now = current_time( 'mysql', true );

		$insert_data    = array(
			'conversation_id' => $conversation_id,
			'sender_id'       => $sender_id,
			'content'         => $content,
			'message_type'    => $message_type,
			'created_at'      => $now,
		);
		$insert_formats = array( '%d', '%d', '%s', '%s', '%s' );

		if ( ! empty( $data['attachment_id'] ) ) {
			$insert_data['attachment_id'] = (int) $data['attachment_id'];
			$insert_formats[]             = '%d';
		}

		if ( ! empty( $data['media_id'] ) ) {
			$insert_data['media_id'] = (int) $data['media_id'];
			$insert_formats[]        = '%d';
		}

		if ( ! empty( $data['parent_id'] ) ) {
			$insert_data['parent_id'] = (int) $data['parent_id'];
			$insert_formats[]         = '%d';
		}

		if ( ! empty( $data['metadata'] ) ) {
			$insert_data['metadata'] = wp_json_encode( $data['metadata'] );
			$insert_formats[]        = '%s';
		}

		$wpdb->insert( $msg_table, $insert_data, $insert_formats );
		$message_id = (int) $wpdb->insert_id;

		if ( ! $message_id ) {
			return array(
				'success'    => false,
				'message_id' => 0,
				'error'      => 'db_error',
			);
		}

		// Update conversation last_message.
		$preview = mb_substr( wp_strip_all_tags( $content ), 0, 100 );
		if ( 'voice' === $message_type ) {
			$preview = 'Voice message';
		} elseif ( in_array( $message_type, array( 'image', 'video', 'file' ), true ) && empty( $preview ) ) {
			$preview = ucfirst( $message_type );
		} elseif ( 'media_share' === $message_type && empty( $preview ) ) {
			$preview = 'Shared a media';
		}

		$wpdb->update(
			$conv_table,
			array(
				'last_message_id'      => $message_id,
				'last_message_preview' => $preview,
				'last_activity_at'     => $now,
			),
			array( 'id' => $conversation_id ),
			array( '%d', '%s', '%s' ),
			array( '%d' )
		);

		// Mark as read for sender.
		$wpdb->update(
			$part_table,
			array( 'last_read_at' => $now ),
			array(
				'conversation_id' => $conversation_id,
				'user_id'         => $sender_id,
			),
			array( '%s' ),
			array( '%d', '%d' )
		);

		// phpcs:enable

		// Get recipient IDs.
		$recipient_ids = array();
		$participants  = $this->get_participants( $conversation_id );
		foreach ( $participants as $p ) {
			if ( $p['id'] !== $sender_id ) {
				$recipient_ids[] = $p['id'];
			}
		}

		/**
		 * Fires after a message is sent.
		 *
		 * @param int   $message_id    Message ID.
		 * @param int   $conversation_id Conversation ID.
		 * @param int   $sender_id     Sender user ID.
		 * @param int[] $recipient_ids Recipient user IDs.
		 */
		do_action( 'mvs_message_sent', $message_id, $conversation_id, $sender_id, $recipient_ids );

		if ( 'voice' === $message_type ) {
			$duration = 0;
			if ( ! empty( $data['metadata']['duration'] ) ) {
				$duration = (float) $data['metadata']['duration'];
			}
			do_action( 'mvs_voice_message_sent', $message_id, $conversation_id, $duration );
		}

		return array(
			'success'    => true,
			'message_id' => $message_id,
			'error'      => '',
		);
	}

	/**
	 * Get messages for a conversation with cursor pagination.
	 *
	 * @param int $conversation_id Conversation ID.
	 * @param int $user_id         User requesting.
	 * @param int $before          Message ID to get messages before (cursor).
	 * @param int $per_page        Items per page.
	 * @return array
	 */
	public function get_messages( int $conversation_id, int $user_id, int $before = 0, int $per_page = 30 ): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$msg_table   = $wpdb->prefix . 'mvs_messages';
		$react_table = $wpdb->prefix . 'mvs_message_reactions';

		$where  = 'm.conversation_id = %d AND (m.is_deleted = 0 OR m.sender_id = %d)';
		$params = array( $conversation_id, $user_id );

		if ( $before > 0 ) {
			$where   .= ' AND m.id < %d';
			$params[] = $before;
		}

		$messages = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT m.* FROM {$msg_table} m WHERE {$where} ORDER BY m.id DESC LIMIT %d",
				array_merge( $params, array( $per_page ) )
			)
		);

		// phpcs:enable

		// Enrich messages.
		foreach ( $messages as &$msg ) {
			// Hide content for deleted messages.
			if ( $msg->deleted_for_all ) {
				$msg->content  = '';
				$msg->metadata = null;
			} elseif ( $msg->is_deleted && (int) $msg->sender_id === $user_id ) {
				$msg->content  = '';
				$msg->metadata = null;
			}

			// Parse metadata JSON.
			if ( $msg->metadata ) {
				$msg->metadata = json_decode( $msg->metadata, true );
			}

			// Get reactions.
			$msg->reactions = $this->get_message_reactions( (int) $msg->id );

			// Get parent message preview for reply-to.
			if ( $msg->parent_id ) {
				$msg->parent_preview = $this->get_message_preview( (int) $msg->parent_id );
			}

			// Add sender info.
			$sender = get_userdata( (int) $msg->sender_id );
			if ( $sender ) {
				$msg->sender_name   = $sender->display_name;
				$msg->sender_avatar = get_avatar_url( $msg->sender_id, array( 'size' => 64 ) );
			}

			// Attachment info.
			if ( $msg->attachment_id ) {
				$msg->attachment = $this->get_attachment_data( (int) $msg->attachment_id );
			}

			// Media share info.
			if ( $msg->media_id ) {
				$msg->media_share = $this->get_media_share_data( (int) $msg->media_id );
			}
		}

		return array_reverse( $messages ); // Return in chronological order.
	}

	/**
	 * Get a brief preview of a message (for reply-to).
	 *
	 * @param int $message_id Message ID.
	 * @return array|null
	 */
	public function get_message_preview( int $message_id ) {
		global $wpdb;

		$msg_table = $wpdb->prefix . 'mvs_messages';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$msg = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, sender_id, content, message_type, deleted_for_all FROM {$msg_table} WHERE id = %d",
				$message_id
			)
		);

		if ( ! $msg ) {
			return null;
		}

		if ( $msg->deleted_for_all ) {
			return array(
				'id'      => (int) $msg->id,
				'content' => 'This message was deleted',
				'sender'  => '',
				'type'    => 'text',
			);
		}

		$sender = get_userdata( (int) $msg->sender_id );

		return array(
			'id'      => (int) $msg->id,
			'content' => mb_substr( wp_strip_all_tags( $msg->content ), 0, 100 ),
			'sender'  => $sender ? $sender->display_name : '',
			'type'    => $msg->message_type,
		);
	}

	/**
	 * Delete a message (for sender only).
	 *
	 * @param int $message_id Message ID.
	 * @param int $user_id    User requesting deletion.
	 * @return bool
	 */
	public function delete_message( int $message_id, int $user_id ): bool {
		global $wpdb;

		$msg_table = $wpdb->prefix . 'mvs_messages';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->update(
			$msg_table,
			array( 'is_deleted' => 1 ),
			array(
				'id'        => $message_id,
				'sender_id' => $user_id,
			),
			array( '%d' ),
			array( '%d', '%d' )
		);

		if ( false !== $result && $result > 0 ) {
			do_action( 'mvs_message_deleted', $message_id, $user_id, false );
			return true;
		}

		return false;
	}

	/**
	 * Unsend a message (delete for everyone, within 15 min).
	 *
	 * @param int $message_id Message ID.
	 * @param int $user_id    Sender user ID.
	 * @return array{success: bool, error: string}
	 */
	public function unsend_message( int $message_id, int $user_id ): array {
		global $wpdb;

		$msg_table = $wpdb->prefix . 'mvs_messages';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$msg = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT sender_id, created_at FROM {$msg_table} WHERE id = %d",
				$message_id
			)
		);

		if ( ! $msg ) {
			return array(
				'success' => false,
				'error'   => 'not_found',
			);
		}

		if ( (int) $msg->sender_id !== $user_id ) {
			return array(
				'success' => false,
				'error'   => 'not_sender',
			);
		}

		$created_time = strtotime( $msg->created_at );
		$now          = time();
		if ( ( $now - $created_time ) > self::UNSEND_WINDOW_SECONDS ) {
			return array(
				'success' => false,
				'error'   => 'window_expired',
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			$msg_table,
			array(
				'deleted_for_all' => 1,
				'content'         => '',
			),
			array( 'id' => $message_id ),
			array( '%d', '%s' ),
			array( '%d' )
		);

		do_action( 'mvs_message_deleted', $message_id, $user_id, true );

		return array(
			'success' => true,
			'error'   => '',
		);
	}

	/**
	 * Mark a conversation as read.
	 *
	 * @param int $conversation_id Conversation ID.
	 * @param int $user_id         User ID.
	 * @return bool
	 */
	public function mark_read( int $conversation_id, int $user_id ): bool {
		global $wpdb;

		$part_table = $wpdb->prefix . 'mvs_conversation_participants';
		$now        = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->update(
			$part_table,
			array( 'last_read_at' => $now ),
			array(
				'conversation_id' => $conversation_id,
				'user_id'         => $user_id,
			),
			array( '%s' ),
			array( '%d', '%d' )
		);

		if ( false !== $result ) {
			do_action( 'mvs_conversation_read', $conversation_id, $user_id );
		}

		return false !== $result;
	}

	/**
	 * Set typing indicator.
	 *
	 * @param int $conversation_id Conversation ID.
	 * @param int $user_id         User ID.
	 */
	public function set_typing( int $conversation_id, int $user_id ): void {
		set_transient( "mvs_typing_{$conversation_id}_{$user_id}", true, 5 );
	}

	/**
	 * Get typing users for a conversation.
	 *
	 * @param int $conversation_id Conversation ID.
	 * @param int $exclude_user_id User to exclude (current user).
	 * @return array User IDs currently typing.
	 */
	public function get_typing_users( int $conversation_id, int $exclude_user_id ): array {
		$participants = $this->get_participants( $conversation_id );
		$typing       = array();

		foreach ( $participants as $p ) {
			if ( $p['id'] === $exclude_user_id ) {
				continue;
			}
			if ( get_transient( "mvs_typing_{$conversation_id}_{$p['id']}" ) ) {
				$typing[] = array(
					'id'   => $p['id'],
					'name' => $p['display_name'],
				);
			}
		}

		return $typing;
	}

	// -------------------------------------------------------------------------
	// Reactions
	// -------------------------------------------------------------------------

	/**
	 * Add or replace a reaction on a message.
	 *
	 * @param int    $message_id Message ID.
	 * @param int    $user_id    User ID.
	 * @param string $emoji      Emoji character.
	 * @return bool
	 */
	public function add_reaction( int $message_id, int $user_id, string $emoji ): bool {
		global $wpdb;

		$react_table = $wpdb->prefix . 'mvs_message_reactions';

		// Remove existing reaction from this user.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete(
			$react_table,
			array(
				'message_id' => $message_id,
				'user_id'    => $user_id,
			),
			array( '%d', '%d' )
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->insert(
			$react_table,
			array(
				'message_id' => $message_id,
				'user_id'    => $user_id,
				'emoji'      => sanitize_text_field( $emoji ),
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%s' )
		);

		if ( $result ) {
			do_action( 'mvs_message_reaction_added', $message_id, $user_id, $emoji );
		}

		return (bool) $result;
	}

	/**
	 * Remove a reaction from a message.
	 *
	 * @param int $message_id Message ID.
	 * @param int $user_id    User ID.
	 * @return bool
	 */
	public function remove_reaction( int $message_id, int $user_id ): bool {
		global $wpdb;

		$react_table = $wpdb->prefix . 'mvs_message_reactions';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->delete(
			$react_table,
			array(
				'message_id' => $message_id,
				'user_id'    => $user_id,
			),
			array( '%d', '%d' )
		);

		return (bool) $result;
	}

	/**
	 * Get reactions for a message.
	 *
	 * @param int $message_id Message ID.
	 * @return array
	 */
	public function get_message_reactions( int $message_id ): array {
		global $wpdb;

		$react_table = $wpdb->prefix . 'mvs_message_reactions';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT emoji, GROUP_CONCAT(user_id) as user_ids, COUNT(*) as count
				FROM {$react_table}
				WHERE message_id = %d
				GROUP BY emoji",
				$message_id
			)
		);

		$reactions = array();
		foreach ( $rows as $row ) {
			$reactions[] = array(
				'emoji'    => $row->emoji,
				'count'    => (int) $row->count,
				'user_ids' => array_map( 'intval', explode( ',', $row->user_ids ) ),
			);
		}

		return $reactions;
	}

	// -------------------------------------------------------------------------
	// Online Status
	// -------------------------------------------------------------------------

	/**
	 * Update user's last active timestamp.
	 *
	 * @param int $user_id User ID.
	 */
	public function update_online_status( int $user_id ): void {
		update_user_meta( $user_id, '_mvs_last_active', time() );
	}

	/**
	 * Check if a user is online.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public function is_user_online( int $user_id ): bool {
		$show = apply_filters( 'mvs_show_online_status', true, get_current_user_id(), $user_id );
		if ( ! $show ) {
			return false;
		}

		$last = (int) get_user_meta( $user_id, '_mvs_last_active', true );
		return $last && ( time() - $last ) < self::ONLINE_THRESHOLD;
	}

	/**
	 * Get last active time for a user.
	 *
	 * @param int $user_id User ID.
	 * @return string ISO 8601 datetime or empty.
	 */
	public function get_last_active( int $user_id ): string {
		$show = apply_filters( 'mvs_show_online_status', true, get_current_user_id(), $user_id );
		if ( ! $show ) {
			return '';
		}

		$last = (int) get_user_meta( $user_id, '_mvs_last_active', true );
		return $last ? gmdate( 'c', $last ) : '';
	}

	// -------------------------------------------------------------------------
	// Unread Counts
	// -------------------------------------------------------------------------

	/**
	 * Get unread count for a specific conversation.
	 *
	 * @param int $conversation_id Conversation ID.
	 * @param int $user_id         User ID.
	 * @return int
	 */
	public function get_conversation_unread_count( int $conversation_id, int $user_id ): int {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$msg_table  = $wpdb->prefix . 'mvs_messages';
		$part_table = $wpdb->prefix . 'mvs_conversation_participants';

		$last_read = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT last_read_at FROM {$part_table} WHERE conversation_id = %d AND user_id = %d",
				$conversation_id,
				$user_id
			)
		);

		if ( ! $last_read ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$msg_table} WHERE conversation_id = %d AND sender_id != %d AND deleted_for_all = 0",
					$conversation_id,
					$user_id
				)
			);
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$msg_table}
				WHERE conversation_id = %d AND sender_id != %d AND created_at > %s AND deleted_for_all = 0",
				$conversation_id,
				$user_id,
				$last_read
			)
		);

		// phpcs:enable
	}

	/**
	 * Get total unread count across all conversations.
	 *
	 * @param int $user_id User ID.
	 * @return int
	 */
	public function get_total_unread( int $user_id ): int {
		$cached = get_transient( 'mvs_dm_unread_' . $user_id );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$msg_table  = $wpdb->prefix . 'mvs_messages';
		$part_table = $wpdb->prefix . 'mvs_conversation_participants';

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				FROM {$msg_table} m
				INNER JOIN {$part_table} p ON p.conversation_id = m.conversation_id AND p.user_id = %d
				WHERE p.status = 'active'
				AND p.is_muted = 0
				AND m.sender_id != %d
				AND m.deleted_for_all = 0
				AND (p.last_read_at IS NULL OR m.created_at > p.last_read_at)",
				$user_id,
				$user_id
			)
		);

		// phpcs:enable

		set_transient( 'mvs_dm_unread_' . $user_id, $count, 60 );

		return $count;
	}

	// -------------------------------------------------------------------------
	// Polling
	// -------------------------------------------------------------------------

	/**
	 * Get new messages since a timestamp (for polling).
	 *
	 * @param int    $user_id User ID.
	 * @param string $since   ISO 8601 datetime.
	 * @return array
	 */
	public function poll( int $user_id, string $since ): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$msg_table  = $wpdb->prefix . 'mvs_messages';
		$part_table = $wpdb->prefix . 'mvs_conversation_participants';

		$since_gmt = gmdate( 'Y-m-d H:i:s', strtotime( $since ) );

		// Get new messages across all user's conversations.
		$new_messages = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT m.*
				FROM {$msg_table} m
				INNER JOIN {$part_table} p ON p.conversation_id = m.conversation_id AND p.user_id = %d
				WHERE p.status IN ('active', 'request_pending')
				AND m.created_at > %s
				AND m.deleted_for_all = 0
				ORDER BY m.created_at ASC
				LIMIT 100",
				$user_id,
				$since_gmt
			)
		);

		// phpcs:enable

		// Enrich messages.
		foreach ( $new_messages as &$msg ) {
			$sender = get_userdata( (int) $msg->sender_id );
			if ( $sender ) {
				$msg->sender_name   = $sender->display_name;
				$msg->sender_avatar = get_avatar_url( $msg->sender_id, array( 'size' => 64 ) );
			}
			if ( $msg->metadata ) {
				$msg->metadata = json_decode( $msg->metadata, true );
			}
			$msg->reactions = $this->get_message_reactions( (int) $msg->id );
			if ( $msg->attachment_id ) {
				$msg->attachment = $this->get_attachment_data( (int) $msg->attachment_id );
			}
			if ( $msg->media_id ) {
				$msg->media_share = $this->get_media_share_data( (int) $msg->media_id );
			}
			if ( $msg->parent_id ) {
				$msg->parent_preview = $this->get_message_preview( (int) $msg->parent_id );
			}
		}

		return $new_messages;
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Sanitize message content.
	 *
	 * @param string $content Raw content.
	 * @return string
	 */
	private function sanitize_message( string $content ): string {
		return wp_kses(
			$content,
			array(
				'a'      => array(
					'href'  => array(),
					'title' => array(),
					'rel'   => array(),
				),
				'br'     => array(),
				'em'     => array(),
				'strong' => array(),
				'code'   => array(),
			)
		);
	}

	/**
	 * Get attachment data for a WP attachment.
	 *
	 * @param int $attachment_id WP attachment ID.
	 * @return array|null
	 */
	private function get_attachment_data( int $attachment_id ) {
		$url  = wp_get_attachment_url( $attachment_id );
		$mime = get_post_mime_type( $attachment_id );
		$meta = wp_get_attachment_metadata( $attachment_id );

		if ( ! $url ) {
			return null;
		}

		$data = array(
			'id'   => $attachment_id,
			'url'  => set_url_scheme( $url ),
			'mime' => $mime,
			'name' => get_the_title( $attachment_id ),
			'size' => filesize( get_attached_file( $attachment_id ) ) ?: 0,
		);

		// Thumbnail for images.
		if ( strpos( $mime, 'image/' ) === 0 ) {
			$thumb = wp_get_attachment_image_url( $attachment_id, 'medium' );
			if ( $thumb ) {
				$data['thumbnail'] = set_url_scheme( $thumb );
			}
		}

		// Thumbnail for videos.
		if ( strpos( $mime, 'video/' ) === 0 && ! empty( $meta['thumb'] ) ) {
			$data['thumbnail'] = set_url_scheme( $meta['thumb'] );
		}

		return $data;
	}

	/**
	 * Get media share data for an mvs_media post.
	 *
	 * @param int $media_id mvs_media post ID.
	 * @return array|null
	 */
	private function get_media_share_data( int $media_id ) {
		if ( ! MediaRepository::exists( $media_id ) ) {
			return null;
		}

		$data = array(
			'id'        => $media_id,
			'title'     => MediaRepository::get( $media_id, 'title' ),
			'permalink' => MediaRepository::get_permalink( $media_id ),
			'type'      => MediaRepository::get( $media_id, 'media_type' ) ?: 'image',
		);

		$file_url = MediaRepository::get( $media_id, 'file_url' );
		if ( $file_url ) {
			$data['thumbnail'] = set_url_scheme( $file_url );
		}

		return $data;
	}

	// -------------------------------------------------------------------------
	// GDPR
	// -------------------------------------------------------------------------

	/**
	 * Export user messages for GDPR.
	 *
	 * @param int $user_id User ID.
	 * @return array
	 */
	public function export_user_data( int $user_id ): array {
		global $wpdb;

		$msg_table = $wpdb->prefix . 'mvs_messages';
		$data      = array();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$messages = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, conversation_id, content, message_type, created_at FROM {$msg_table} WHERE sender_id = %d AND is_deleted = 0 ORDER BY created_at ASC",
				$user_id
			)
		);

		foreach ( $messages as $msg ) {
			$data[] = array(
				'group_id'    => 'mvs-messages',
				'group_label' => 'WPMediaVerse Messages',
				'item_id'     => 'message-' . $msg->id,
				'data'        => array(
					array(
						'name'  => 'Conversation ID',
						'value' => $msg->conversation_id,
					),
					array(
						'name'  => 'Content',
						'value' => $msg->content,
					),
					array(
						'name'  => 'Type',
						'value' => $msg->message_type,
					),
					array(
						'name'  => 'Date',
						'value' => $msg->created_at,
					),
				),
			);
		}

		return $data;
	}

	/**
	 * Erase user messages for GDPR.
	 *
	 * @param int $user_id User ID.
	 * @return int Number of items erased.
	 */
	public function erase_user_data( int $user_id ): int {
		global $wpdb;

		$msg_table = $wpdb->prefix . 'mvs_messages';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$count = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$msg_table} SET is_deleted = 1, content = '' WHERE sender_id = %d",
				$user_id
			)
		);

		return (int) $count;
	}
}
