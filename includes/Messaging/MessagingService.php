<?php
/**
 * Messaging service — conversations and messages CRUD, privacy, follow-gate.
 *
 * @package WPMediaVerse
 * @since   1.1.0
 */

namespace WPMediaVerse\Messaging;

defined( 'ABSPATH' ) || exit;


class MessagingService {

	/**
	 * Rate limit constants.
	 */
	const RATE_MESSAGES_PER_MIN  = 30;
	const RATE_CONVOS_PER_HOUR   = 10;
	const MAX_MESSAGE_LENGTH     = 2000;
	const UNSEND_WINDOW_SECONDS  = 900; // 15 min.
	const MAX_PINNED             = 3;
	const ONLINE_THRESHOLD       = 120; // 2 min.
	const MAX_GROUP_PARTICIPANTS = 50; // Group conversation hard cap (incl. creator).
	const POLL_OVERLAP           = 2; // Seconds BOTH poll cursors look back (see poll()).
	const COALESCE_SECONDS       = 30;

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
			/**
			 * Filters the denial reason when the mvs_can_send_message gate blocks a send.
			 *
			 * The gate itself is boolean, so an integrator that denies for more than
			 * one cause (e.g. a hard block vs a "who can DM me" privacy preference) can
			 * report the specific reason here, letting clients show an accurate notice
			 * instead of a generic "blocked". Defaults to 'blocked'. The value should be
			 * one of the codes denial_message() understands.
			 *
			 * @param string $reason       Default 'blocked'.
			 * @param int    $sender_id    Sender user ID.
			 * @param int    $recipient_id Recipient user ID.
			 */
			$reason = (string) apply_filters( 'mvs_dm_denial_reason', 'blocked', $sender_id, $recipient_id );
			return array(
				'allowed'    => false,
				'reason'     => $reason,
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

		// DM access level. The site-wide `mvs_dm_access` option is a CEILING:
		// the recipient's own per-user meta may only narrow it (e.g. global
		// "everyone" + user "mutual" -> "mutual"), never widen past it (e.g.
		// global "nobody" + user "everyone" must stay "nobody"). Without this,
		// a recipient's per-user meta could silently re-open DMs an admin
		// disabled site-wide (Basecamp #10053143680).
		$global_access = get_option( 'mvs_dm_access', 'everyone' );
		$user_access   = get_user_meta( $recipient_id, '_mvs_dm_access', true );
		$access        = \WPMediaVerse\Core\Plugin::resolve_privacy_ceiling(
			$global_access,
			$user_access,
			array( 'everyone', 'followers', 'mutual', 'nobody' )
		);

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

	/**
	 * Human-readable message for a DM denial reason code.
	 *
	 * Maps the stable reason codes returned by can_message(), find_or_create_conversation(),
	 * and send_message() (blocked, dms_disabled, mutual_follow_required, account_too_new,
	 * rate_limited, not_participant, content_too_long, …) to a default sentence, so REST
	 * responses are self-describing for native-app clients that consume mvs/v1 directly.
	 * Apps may localize from the code instead; the string is filterable via
	 * `mvs_dm_denial_message` for per-site / per-locale overrides.
	 *
	 * @param string $reason Reason code.
	 * @return string Default human-readable message.
	 */
	public function denial_message( string $reason ): string {
		$messages = array(
			'blocked'                => __( 'You can no longer message this member.', 'wpmediaverse' ),
			'dms_disabled'           => __( 'This member isn’t accepting messages right now.', 'wpmediaverse' ),
			'mutual_follow_required' => __( 'This member only accepts messages from people they are connected with.', 'wpmediaverse' ),
			'connections_only'       => __( 'This member only accepts messages from their connections.', 'wpmediaverse' ),
			'account_too_new'        => __( 'Your account is too new to message this member yet.', 'wpmediaverse' ),
			'cannot_message_self'    => __( 'You can’t send a message to yourself.', 'wpmediaverse' ),
			'rate_limited'           => __( 'You’re sending messages too quickly. Please wait a moment and try again.', 'wpmediaverse' ),
			'not_participant'        => __( 'You can no longer post to this conversation.', 'wpmediaverse' ),
			'content_too_long'       => __( 'That message is too long to send.', 'wpmediaverse' ),
			'empty_content'          => __( 'Your message is empty.', 'wpmediaverse' ),
			'invalid_recipient'      => __( 'That member could not be found.', 'wpmediaverse' ),
		);

		$message = $messages[ $reason ] ?? __( 'This message could not be delivered.', 'wpmediaverse' );

		/**
		 * Filters the human-readable message for a DM denial reason code.
		 *
		 * @param string $message Default message.
		 * @param string $reason  Reason code.
		 */
		return (string) apply_filters( 'mvs_dm_denial_message', $message, $reason );
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
	 * @param int   $user_a First user (the initiator).
	 * @param int   $user_b Second user (the recipient).
	 * @param array $args   Optional. When $args['force_request'] is true, a newly
	 *                      created conversation marks the recipient as a pending
	 *                      request even when their DM access would otherwise allow
	 *                      an active thread, for first-contact flows such as a
	 *                      connection request that carries a note (the recipient
	 *                      accepts or declines before the thread opens). Every
	 *                      denial — hard block, DMs-disabled, self, too-new,
	 *                      mutual-follow-required, rate limit — is still enforced
	 *                      first; the flag applies only once the send is already
	 *                      permitted. `created_at` (optional): backdated UTC
	 *                      timestamp for the whole thread (importer seam, see
	 *                      Core\Dates::resolve_backdate()). Default empty array.
	 * @return array{conversation_id: int, created: bool, status: string}
	 */
	public function find_or_create_conversation( int $user_a, int $user_b, array $args = array() ): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$conv_table = $wpdb->prefix . 'mvs_conversations';
		$part_table = $wpdb->prefix . 'mvs_conversation_participants';

		// Find the pair's existing direct conversation — INCLUDING one the
		// current user has deleted. A pair keeps a single thread: "Delete" set a
		// per-user clear watermark (participants.cleared_up_to, see
		// leave_conversation()), so re-contacting reuses the thread and the
		// initiator simply sees no pre-delete history. The old fresh-thread rule
		// (`p1.status <> 'left'` here) spawned a second conversation row on
		// re-contact, and the OTHER member — who never deleted anything — ended
		// up with two live threads for the same person (card 10127717045).
		// ORDER BY newest keeps the lookup deterministic for pairs that already
		// have historical duplicates from the old behaviour.
		$existing_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT p1.conversation_id
				FROM {$part_table} p1
				INNER JOIN {$part_table} p2 ON p1.conversation_id = p2.conversation_id
				INNER JOIN {$conv_table} c ON c.id = p1.conversation_id
				WHERE p1.user_id = %d AND p2.user_id = %d AND c.type = 'direct'
				ORDER BY p1.conversation_id DESC
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

		// Importer seam: $args['created_at'] backdates the conversation (and the
		// participants' joined_at below) so a migrated DM history keeps its
		// original dates. resolve_backdate() returns null for live callers.
		$now = \WPMediaVerse\Core\Dates::resolve_backdate( isset( $args['created_at'] ) ? (string) $args['created_at'] : null ) ?? current_time( 'mysql', true );

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

		// Add recipient — pending request when the DM access requires one, or when
		// the caller explicitly forces a request (first-contact flows). Reached
		// only after can_message() allowed the send and the rate limit passed, so
		// forcing a request can never bypass a block, a disabled inbox, or a limit.
		$force_request    = ! empty( $args['force_request'] );
		$recipient_status = ( $access['is_request'] || $force_request ) ? 'request_pending' : 'active';

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
				"SELECT c.*, p.last_read_at, p.is_muted, p.muted_until, p.is_pinned, p.is_archived, p.status AS participant_status, p.cleared_up_to
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
			$conv->participants         = $this->get_participants( (int) $conv->id );
			$conv->unread_count         = $this->get_conversation_unread_count( (int) $conv->id, $user_id );
			$conv->created_at_gmt       = self::to_iso8601( (string) ( $conv->created_at ?? '' ) );
			$conv->last_activity_at_gmt = self::to_iso8601( (string) ( $conv->last_activity_at ?? '' ) );
			$this->suppress_cleared_preview( $conv );
		}
		unset( $conv );

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
				"SELECT c.*, p.last_read_at, p.is_muted, p.muted_until, p.is_pinned, p.is_archived, p.status AS participant_status, p.cleared_up_to
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

		$conv->participants         = $this->get_participants( $conversation_id );
		$conv->unread_count         = $this->get_conversation_unread_count( $conversation_id, $user_id );
		$conv->created_at_gmt       = self::to_iso8601( (string) ( $conv->created_at ?? '' ) );
		$conv->last_activity_at_gmt = self::to_iso8601( (string) ( $conv->last_activity_at ?? '' ) );
		$this->suppress_cleared_preview( $conv );

		return $conv;
	}

	/**
	 * Blank the stored last-message preview when it predates the requesting
	 * user's clear point.
	 *
	 * `last_message_id` / `last_message_preview` live on the conversation row
	 * and are shared by both participants; a user who deleted the thread must
	 * not see the pre-delete preview when the thread reactivates before any
	 * new message arrives (card 10127717045). Rows expose `cleared_up_to`
	 * via the participant JOIN in get_conversations()/get_conversation().
	 *
	 * @since 2.2.1
	 *
	 * @param object $conv Conversation row (mutated in place).
	 */
	private function suppress_cleared_preview( object $conv ): void {
		$cleared = (int) ( $conv->cleared_up_to ?? 0 );
		if ( $cleared <= 0 ) {
			return;
		}

		if ( (int) ( $conv->last_message_id ?? 0 ) <= $cleared ) {
			$conv->last_message_id      = null;
			$conv->last_message_preview = '';
		}
	}

	/**
	 * Which conversation a message belongs to.
	 *
	 * Used by the REST write gate to resolve the other party behind a
	 * message-scoped route (reacting to a message) without the gate reaching
	 * into the messages table itself.
	 *
	 * @since 2.1.0
	 *
	 * @param int $message_id Message ID.
	 * @return int Conversation ID, or 0 when the message is gone.
	 */
	public function get_message_conversation_id( int $message_id ): int {
		global $wpdb;

		if ( $message_id <= 0 ) {
			return 0;
		}

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT conversation_id FROM {$wpdb->prefix}mvs_messages WHERE id = %d",
				$message_id
			)
		);
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
				"SELECT user_id, role, status, last_read_at FROM {$part_table} WHERE conversation_id = %d",
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
				'role'         => isset( $row->role ) ? (string) $row->role : 'member',
				'display_name' => $user->display_name,
				'avatar_url'   => get_avatar_url( $row->user_id, array( 'size' => 96 ) ),
				'profile_url'  => \WPMediaVerse\Core\Plugin::container()->get( 'template_helpers' )->get_user_profile_url( (int) $row->user_id ),
				'status'       => $row->status,
				'last_read_at' => $row->last_read_at,
				'is_online'    => $this->is_user_online( (int) $row->user_id ),
				'last_active'  => $this->get_last_active( (int) $row->user_id ),
			);
		}

		return $participants;
	}

	/**
	 * Get a participant's role in a conversation.
	 *
	 * @param int $conversation_id Conversation id.
	 * @param int $user_id         User id.
	 * @return string 'admin' | 'member', or '' if not an active participant.
	 */
	public function get_participant_role( int $conversation_id, int $user_id ): string {
		global $wpdb;
		$part_table = $wpdb->prefix . 'mvs_conversation_participants';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$role = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT role FROM {$part_table} WHERE conversation_id = %d AND user_id = %d AND status = 'active'",
				$conversation_id,
				$user_id
			)
		);
		return null === $role ? '' : (string) $role;
	}

	/**
	 * Whether a user is an active participant of a conversation the given media
	 * was shared into (as a message media_id or attachment_id).
	 *
	 * Lets the privacy layer grant DM recipients access to media shared with
	 * them — the conversation membership already gates who can read the message,
	 * so the attachment must be viewable to the same set. Owner/admin are
	 * handled earlier in the privacy check; this only covers the recipient side.
	 *
	 * @param int $user_id  Viewer user id.
	 * @param int $media_id Media id shared in a message.
	 * @return bool
	 */
	public function user_received_media( int $user_id, int $media_id ): bool {
		if ( $user_id <= 0 || $media_id <= 0 ) {
			return false;
		}

		global $wpdb;
		$msg_table  = $wpdb->prefix . 'mvs_messages';
		$part_table = $wpdb->prefix . 'mvs_conversation_participants';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1
				   FROM {$msg_table} m
				   INNER JOIN {$part_table} p ON p.conversation_id = m.conversation_id
				  WHERE ( m.media_id = %d OR m.attachment_id = %d )
				    AND p.user_id = %d
				    AND p.status = 'active'
				  LIMIT 1",
				$media_id,
				$media_id,
				$user_id
			)
		);

		return null !== $found;
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
		$msg_table  = $wpdb->prefix . 'mvs_messages';

		// `cleared_up_to` is this user's clear point: every message with an id
		// at or below it stays hidden from THEM even after the thread
		// reactivates (re-contact via find_or_create_conversation(), or an
		// inbound message via send_message()). The other participant keeps the
		// same single thread and their full history — no duplicate conversation
		// is ever created for the pair (card 10127717045). An id watermark, not
		// a timestamp: ids are strictly monotonic, so a message sent in the
		// same second as the delete is still delivered.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$watermark = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE( MAX( id ), 0 ) FROM {$msg_table} WHERE conversation_id = %d",
				$conversation_id
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->update(
			$part_table,
			array(
				'status'        => 'left',
				'cleared_up_to' => $watermark,
			),
			array(
				'conversation_id' => $conversation_id,
				'user_id'         => $user_id,
			),
			array( '%s', '%d' ),
			array( '%d', '%d' )
		);

		return false !== $result;
	}

	/**
	 * Create a group conversation with an ordered participant roster.
	 *
	 * Engine-level primitive (the engine owns the tables). Feature gating —
	 * whether group DM is offered, the participant cap policy, who may create —
	 * is the caller's concern (e.g. WPMediaVerse Pro). The creator is added as
	 * `admin`; everyone else as `member`. Optionally scopes the conversation to a
	 * container (`bn_space`, `bp_group`, …) for space/group channels.
	 *
	 * @param int    $creator_id      Creator (becomes admin).
	 * @param int[]  $participant_ids Other members to add.
	 * @param string $title           Group title.
	 * @param array  $opts            Optional: container_type, container_id, created_at
	 *                                (backdated UTC timestamp - importer seam, see
	 *                                Core\Dates::resolve_backdate()).
	 * @return int New conversation id, or 0 on failure / cap exceeded.
	 */
	public function create_group_conversation( int $creator_id, array $participant_ids, string $title = '', array $opts = array() ): int {
		global $wpdb;

		if ( $creator_id <= 0 ) {
			return 0;
		}

		// Unique, valid members (excluding the creator), capped.
		$members = array();
		foreach ( $participant_ids as $pid ) {
			$pid = (int) $pid;
			if ( $pid > 0 && $pid !== $creator_id ) {
				$members[ $pid ] = true;
			}
		}
		$members = array_keys( $members );
		if ( count( $members ) + 1 > self::MAX_GROUP_PARTICIPANTS ) {
			return 0;
		}

		$conv_table = $wpdb->prefix . 'mvs_conversations';
		$part_table = $wpdb->prefix . 'mvs_conversation_participants';
		// Importer seam: $opts['created_at'] backdates a migrated group thread.
		$now        = \WPMediaVerse\Core\Dates::resolve_backdate( isset( $opts['created_at'] ) ? (string) $opts['created_at'] : null ) ?? current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$conv_table,
			array(
				'type'             => 'group',
				'container_type'   => isset( $opts['container_type'] ) ? sanitize_key( (string) $opts['container_type'] ) : '',
				'container_id'     => isset( $opts['container_id'] ) ? (int) $opts['container_id'] : 0,
				'title'            => sanitize_text_field( $title ),
				'created_by'       => $creator_id,
				'last_activity_at' => $now,
				'created_at'       => $now,
			),
			array( '%s', '%s', '%d', '%s', '%d', '%s', '%s' )
		);
		$conv_id = (int) $wpdb->insert_id;
		if ( ! $conv_id ) {
			return 0;
		}

		$this->insert_participant( $conv_id, $creator_id, 'admin', $now );
		foreach ( $members as $pid ) {
			$this->insert_participant( $conv_id, $pid, 'member', $now );
		}

		$all_ids = array_merge( array( $creator_id ), $members );

		/** This action is documented in includes/Messaging/MessagingService.php (find_or_create_conversation). */
		do_action( 'mvs_conversation_created', $conv_id, $creator_id, $all_ids );

		/**
		 * Fires when a group conversation is created.
		 *
		 * @since 1.6.0
		 *
		 * @param int    $conv_id    Conversation id.
		 * @param int    $creator_id Creator (admin).
		 * @param int[]  $all_ids    All participant ids incl. creator.
		 * @param array  $opts       Container opts (container_type, container_id).
		 */
		do_action( 'mvs_group_conversation_created', $conv_id, $creator_id, $all_ids, $opts );

		return $conv_id;
	}

	/**
	 * Get (or lazily create) the single group conversation for a container.
	 *
	 * Used for "space channels": one group conversation per container
	 * (`bn_space:ID`, `bp_group:ID`). Returns the existing channel id if present.
	 *
	 * @param string $container_type Container namespace.
	 * @param int    $container_id   Container id.
	 * @param int    $creator_id     Creator if the channel must be created.
	 * @param string $title          Title used on creation.
	 * @return int Conversation id, or 0 on failure.
	 */
	public function get_or_create_channel( string $container_type, int $container_id, int $creator_id, string $title = '' ): int {
		global $wpdb;

		$container_type = sanitize_key( $container_type );
		if ( '' === $container_type || $container_id <= 0 ) {
			return 0;
		}

		$conv_table = $wpdb->prefix . 'mvs_conversations';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$conv_table} WHERE type = 'group' AND container_type = %s AND container_id = %d LIMIT 1",
				$container_type,
				$container_id
			)
		);
		if ( $existing > 0 ) {
			return $existing;
		}

		return $this->create_group_conversation(
			$creator_id,
			array(),
			$title,
			array(
				'container_type' => $container_type,
				'container_id'   => $container_id,
			)
		);
	}

	/**
	 * Add (or reactivate) a participant in a conversation.
	 *
	 * @param int    $conversation_id Conversation id.
	 * @param int    $user_id         User to add.
	 * @param string $role            'admin' | 'member'. Default 'member'.
	 * @return bool True on success, false on cap exceeded / failure.
	 */
	public function add_participant( int $conversation_id, int $user_id, string $role = 'member' ): bool {
		global $wpdb;

		if ( $conversation_id <= 0 || $user_id <= 0 ) {
			return false;
		}

		$part_table = $wpdb->prefix . 'mvs_conversation_participants';
		$role       = ( 'admin' === $role ) ? 'admin' : 'member';

		// Already a participant? Reactivate.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$part_table} WHERE conversation_id = %d AND user_id = %d", $conversation_id, $user_id )
		);
		if ( $existing ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$part_table,
				array( 'status' => 'active' ),
				array(
					'conversation_id' => $conversation_id,
					'user_id'         => $user_id,
				),
				array( '%s' ),
				array( '%d', '%d' )
			);
			return true;
		}

		// Enforce the group cap on active participants.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$active = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$part_table} WHERE conversation_id = %d AND status = 'active'", $conversation_id )
		);
		if ( $active >= self::MAX_GROUP_PARTICIPANTS ) {
			return false;
		}

		$ok = $this->insert_participant( $conversation_id, $user_id, $role, current_time( 'mysql', true ) );
		if ( $ok ) {
			/**
			 * Fires when a participant is added to a conversation.
			 *
			 * @since 1.6.0
			 *
			 * @param int    $conversation_id Conversation id.
			 * @param int    $user_id         Added user.
			 * @param string $role            Assigned role.
			 */
			do_action( 'mvs_participant_added', $conversation_id, $user_id, $role );
		}
		return $ok;
	}

	/**
	 * Remove a participant from a conversation (admin action).
	 *
	 * @param int $conversation_id Conversation id.
	 * @param int $user_id         User to remove.
	 * @return bool
	 */
	public function remove_participant( int $conversation_id, int $user_id ): bool {
		global $wpdb;

		if ( $conversation_id <= 0 || $user_id <= 0 ) {
			return false;
		}

		$part_table = $wpdb->prefix . 'mvs_conversation_participants';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			$part_table,
			array( 'status' => 'removed' ),
			array(
				'conversation_id' => $conversation_id,
				'user_id'         => $user_id,
			),
			array( '%s' ),
			array( '%d', '%d' )
		);

		if ( false !== $result ) {
			/**
			 * Fires when a participant is removed from a conversation.
			 *
			 * @since 1.6.0
			 *
			 * @param int $conversation_id Conversation id.
			 * @param int $user_id         Removed user.
			 */
			do_action( 'mvs_participant_removed', $conversation_id, $user_id );
		}
		return false !== $result;
	}

	/**
	 * Set a participant's role (admin | member).
	 *
	 * @param int    $conversation_id Conversation id.
	 * @param int    $user_id         User.
	 * @param string $role            'admin' | 'member'.
	 * @return bool
	 */
	public function set_participant_role( int $conversation_id, int $user_id, string $role ): bool {
		global $wpdb;

		$role       = ( 'admin' === $role ) ? 'admin' : 'member';
		$part_table = $wpdb->prefix . 'mvs_conversation_participants';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			$part_table,
			array( 'role' => $role ),
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
	 * Rename a (group) conversation.
	 *
	 * @param int    $conversation_id Conversation id.
	 * @param string $title           New title.
	 * @return bool
	 */
	public function rename_conversation( int $conversation_id, string $title ): bool {
		global $wpdb;
		$conv_table = $wpdb->prefix . 'mvs_conversations';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			$conv_table,
			array( 'title' => sanitize_text_field( $title ) ),
			array( 'id' => $conversation_id ),
			array( '%s' ),
			array( '%d' )
		);
		return false !== $result;
	}

	/**
	 * Insert one participant row (internal helper).
	 *
	 * @param int    $conversation_id Conversation id.
	 * @param int    $user_id         User id.
	 * @param string $role            Role.
	 * @param string $now             MySQL UTC datetime.
	 * @return bool
	 */
	private function insert_participant( int $conversation_id, int $user_id, string $role, string $now ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ok = $wpdb->insert(
			$wpdb->prefix . 'mvs_conversation_participants',
			array(
				'conversation_id' => $conversation_id,
				'user_id'         => $user_id,
				'role'            => $role,
				'status'          => 'active',
				'joined_at'       => $now,
			),
			array( '%d', '%d', '%s', '%s', '%s' )
		);
		return false !== $ok;
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
	 * @param array $data            Message data: content, message_type, attachment_id, media_id, parent_id, metadata,
	 *                               created_at (optional backdated UTC timestamp - importer seam, see Core\Dates::resolve_backdate()).
	 * @return array{success: bool, message_id: int, error: string}
	 */
	public function send_message( int $conversation_id, int $sender_id, array $data ): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$conv_table = $wpdb->prefix . 'mvs_conversations';
		$part_table = $wpdb->prefix . 'mvs_conversation_participants';
		$msg_table  = $wpdb->prefix . 'mvs_messages';

		// Verify sender is a participant. Also pull the conversation type in
		// the same query so the DM re-check below doesn't need a second
		// round trip.
		$participant = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT p.status, c.type
				FROM {$part_table} p
				INNER JOIN {$conv_table} c ON c.id = p.conversation_id
				WHERE p.conversation_id = %d AND p.user_id = %d",
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

		// Re-check DM privacy at SEND time, not just at conversation-creation
		// time. A conversation can outlive a later privacy change (recipient
		// sets DM access to "nobody", blocks the sender, admin flips the
		// site-wide setting) — without this, every send into an
		// already-open conversation silently bypassed can_message() forever
		// (Basecamp #10053143680). Only applies to 1:1 `direct` conversations;
		// group DM access isn't governed by per-recipient DM privacy rules.
		if ( 'direct' === $participant->type ) {
			$other_id = 0;
			foreach ( $this->get_participants( $conversation_id ) as $p ) {
				if ( (int) $p['id'] !== $sender_id ) {
					$other_id = (int) $p['id'];
					break;
				}
			}

			if ( $other_id ) {
				$access = $this->can_message( $sender_id, $other_id );
				if ( ! $access['allowed'] ) {
					return array(
						'success'    => false,
						'message_id' => 0,
						'error'      => $access['reason'],
					);
				}
			}
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

		// Content-moderation seam. MediaVerse ships standalone, so it only fires a
		// filter; a host (e.g. BuddyNext auto-moderation) can hook it to scan the
		// message text. A WP_Error return blocks the send.
		if ( '' !== $content ) {
			$moderation = apply_filters( 'mvs_message_content_check', true, $content, $sender_id, $conversation_id );
			if ( is_wp_error( $moderation ) ) {
				return array(
					'success'    => false,
					'message_id' => 0,
					'error'      => $moderation->get_error_code() ? $moderation->get_error_code() : 'content_blocked',
					'message'    => $moderation->get_error_message(),
				);
			}
		}

		$allowed_types = apply_filters(
			'mvs_message_types',
			array( 'text', 'media_share', 'image', 'video', 'audio', 'voice', 'file', 'system' )
		);
		if ( ! in_array( $message_type, $allowed_types, true ) ) {
			$message_type = 'text';
		}

		// Importer seam: $data['created_at'] backdates the message and carries
		// into the conversation's last_activity_at below, so a migrated thread
		// sorts by its real history rather than the migration run time.
		$now = \WPMediaVerse\Core\Dates::resolve_backdate( isset( $data['created_at'] ) ? (string) $data['created_at'] : null ) ?? current_time( 'mysql', true );

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
		$preview = $this->build_message_preview(
			$content,
			$message_type,
			(int) ( $data['attachment_id'] ?? 0 ),
			(int) ( $data['media_id'] ?? 0 )
		);

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

		// Delivery semantics: a new message resurrects the thread for any
		// participant who had deleted it (status 'left'). Without this the
		// recipient stays 'left', get_conversations()/poll() filter the
		// conversation out for them, and the message is stored but never seen.
		// 'request_pending' is intentionally left untouched so the message-request
		// gate still holds; the sender was already verified active/request_pending
		// above, so this only reactivates recipients.
		$wpdb->update(
			$part_table,
			array(
				'status'      => 'active',
				'is_archived' => 0,
			),
			array(
				'conversation_id' => $conversation_id,
				'status'          => 'left',
			),
			array( '%s', '%d' ),
			array( '%d', '%s' )
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
	 * Convert a stored UTC "Y-m-d H:i:s" timestamp to an ISO-8601 'Z' string.
	 *
	 * Messaging timestamps are written with current_time('mysql', true) (UTC)
	 * but shipped unmarked, which is timezone-ambiguous for the mobile app. This
	 * powers the additive `*_gmt` fields (e.g. 2026-07-18T19:44:27Z) the app uses
	 * to compute the absolute instant. Empty/zero/invalid input yields ''.
	 *
	 * @param string $mysql_utc UTC datetime as stored (no timezone marker).
	 * @return string ISO-8601 UTC ('...Z'), or '' when input is empty/invalid.
	 */
	public static function to_iso8601( string $mysql_utc ): string {
		// Single converter lives in Core\Dates (also the source for the REST-wide
		// rest_post_dispatch normalizer). Kept as a thin delegate so the hot
		// polling paths here stay explicit.
		return \WPMediaVerse\Core\Dates::iso8601( $mysql_utc );
	}

	/**
	 * Search messages within a single conversation by content.
	 *
	 * The caller (controller) must first confirm the user participates in the
	 * conversation. Deleted / unsent messages are excluded (except the requester's
	 * own soft-deleted rows are also hidden). Newest match first. Each hit is
	 * enriched with the sender's display name + avatar (and a UTC-ISO timestamp)
	 * so the result is usable in group threads and identical for web + app.
	 *
	 * @param int    $conversation_id Conversation ID.
	 * @param int    $user_id         User requesting.
	 * @param string $query           Search term.
	 * @param int    $limit           Max results (1-100).
	 * @return array
	 */
	public function search_messages( int $conversation_id, int $user_id, string $query, int $limit = 50 ): array {
		$query = trim( $query );
		if ( '' === $query ) {
			return array();
		}

		global $wpdb;
		$msg_table = $wpdb->prefix . 'mvs_messages';
		$like      = '%' . $wpdb->esc_like( $query ) . '%';
		$limit     = max( 1, min( 100, $limit ) );

		// History this user deleted stays deleted (per-user clear watermark).
		$cleared_up_to = $this->get_cleared_up_to( $conversation_id, $user_id );
		$cleared_sql   = $cleared_up_to > 0 ? ' AND m.id > %d' : '';
		$params        = array( $conversation_id, $like );
		if ( $cleared_up_to > 0 ) {
			$params[] = $cleared_up_to;
		}
		$params[] = $limit;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT m.id, m.sender_id, m.content, m.created_at
				 FROM {$msg_table} m
				 WHERE m.conversation_id = %d
				   AND m.is_deleted = 0
				   AND m.deleted_for_all = 0
				   AND m.content LIKE %s{$cleared_sql}
				 ORDER BY m.id DESC
				 LIMIT %d",
				$params
			)
		);
		// phpcs:enable

		$rows = is_array( $rows ) ? $rows : array();
		if ( empty( $rows ) ) {
			return array();
		}

		// Prime the user cache once for all distinct senders (results cap at 100),
		// then enrich each hit — a bare content snippet with no author is unusable
		// in group threads, and the app cannot resolve names client-side.
		$sender_ids = array();
		foreach ( $rows as $row ) {
			$sender_ids[] = (int) $row->sender_id;
		}
		// $rows is non-empty here (early return above), so there is always at
		// least one sender to prime.
		cache_users( array_unique( $sender_ids ) );

		foreach ( $rows as &$row ) {
			$sender              = get_userdata( (int) $row->sender_id );
			$row->sender_name    = $sender ? $sender->display_name : '';
			$row->sender_avatar  = get_avatar_url( (int) $row->sender_id, array( 'size' => 64 ) );
			$row->created_at_gmt = self::to_iso8601( (string) $row->created_at );
		}
		unset( $row );

		return $rows;
	}

	/**
	 * List media shared in a conversation, newest first.
	 *
	 * Backs the "shared media" info panel and the mobile app's equivalent. The
	 * web panel previously scraped the DOM of already-loaded bubbles, so it saw
	 * only part of the thread and the app could not do it at all. This walks the
	 * full message history for rows carrying an attachment or a shared media
	 * item and returns a resolved payload per item, so web + app share one
	 * source. The caller (controller) must confirm participation first.
	 *
	 * @param int $conversation_id Conversation ID.
	 * @param int $user_id         Requesting participant (for media-share access).
	 * @param int $limit           Max items (1-200).
	 * @return array<int,array<string,mixed>> Newest-first shared-media items.
	 */
	public function get_conversation_media( int $conversation_id, int $user_id, int $limit = 60 ): array {
		global $wpdb;
		$msg_table = $wpdb->prefix . 'mvs_messages';
		$limit     = max( 1, min( 200, $limit ) );

		// History this user deleted stays deleted (per-user clear watermark).
		$cleared_up_to = $this->get_cleared_up_to( $conversation_id, $user_id );
		$cleared_sql   = $cleared_up_to > 0 ? ' AND m.id > %d' : '';
		$params        = array( $conversation_id );
		if ( $cleared_up_to > 0 ) {
			$params[] = $cleared_up_to;
		}
		$params[] = $limit;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT m.id, m.sender_id, m.attachment_id, m.media_id, m.created_at
				 FROM {$msg_table} m
				 WHERE m.conversation_id = %d
				   AND m.is_deleted = 0
				   AND m.deleted_for_all = 0
				   AND ( m.attachment_id > 0 OR m.media_id > 0 ){$cleared_sql}
				 ORDER BY m.id DESC
				 LIMIT %d",
				$params
			)
		);
		// phpcs:enable

		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return array();
		}

		$items = array();
		foreach ( $rows as $row ) {
			$payload = null;
			if ( (int) $row->attachment_id > 0 ) {
				$payload = $this->get_attachment_data( (int) $row->attachment_id );
			} elseif ( (int) $row->media_id > 0 ) {
				$payload = $this->get_media_share_data( (int) $row->media_id, $user_id );
			}
			if ( ! $payload ) {
				continue;
			}
			$items[] = array(
				'message_id'     => (int) $row->id,
				'sender_id'      => (int) $row->sender_id,
				'created_at'     => (string) $row->created_at,
				'created_at_gmt' => self::to_iso8601( (string) $row->created_at ),
				'media'          => $payload,
			);
		}

		return $items;
	}

	/**
	 * The requesting user's clear-point watermark for a conversation.
	 *
	 * Set by leave_conversation() ("Delete" in the UI). Messages with an id at
	 * or below the watermark must never be served to this user again — the
	 * thread itself is reused on re-contact instead of duplicated
	 * (card 10127717045).
	 *
	 * @since 2.2.1
	 *
	 * @param int $conversation_id Conversation ID.
	 * @param int $user_id         User ID.
	 * @return int Highest cleared message id, or 0 when never cleared.
	 */
	private function get_cleared_up_to( int $conversation_id, int $user_id ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT cleared_up_to FROM {$wpdb->prefix}mvs_conversation_participants
				WHERE conversation_id = %d AND user_id = %d",
				$conversation_id,
				$user_id
			)
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

		// History this user deleted stays deleted (per-user clear watermark).
		$cleared_up_to = $this->get_cleared_up_to( $conversation_id, $user_id );
		if ( $cleared_up_to > 0 ) {
			$where   .= ' AND m.id > %d';
			$params[] = $cleared_up_to;
		}

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
			// Hide content for deleted messages (unsent for everyone, or
			// soft-deleted rows still served to their sender).
			$is_hidden = $msg->deleted_for_all
				|| ( $msg->is_deleted && (int) $msg->sender_id === $user_id );

			if ( $is_hidden ) {
				$msg->content  = '';
				$msg->metadata = null;
			}

			// Parse metadata JSON.
			if ( $msg->metadata ) {
				$msg->metadata = json_decode( $msg->metadata, true );
			}

			// Additive UTC-ISO timestamp for the app (stored value is UTC but
			// unmarked). Web ignores it; the app reads it for the absolute instant.
			$msg->created_at_gmt = self::to_iso8601( (string) $msg->created_at );

			// Get reactions.
			$msg->reactions = $this->get_message_reactions( (int) $msg->id );

			// Get parent message preview for reply-to.
			if ( $msg->parent_id ) {
				$msg->parent_preview = $this->get_message_preview( (int) $msg->parent_id );
			}

			// Add sender info.
			$sender = get_userdata( (int) $msg->sender_id );
			if ( $sender ) {
				$msg->sender_name        = $sender->display_name;
				$msg->sender_avatar      = get_avatar_url( $msg->sender_id, array( 'size' => 64 ) );
				$msg->sender_profile_url = \WPMediaVerse\Core\Plugin::container()->get( 'template_helpers' )->get_user_profile_url( (int) $msg->sender_id );
			}

			// Attachment / media-share payloads are never shipped for deleted
			// messages — same contract as poll() (Basecamp #9962618059).
			if ( $msg->attachment_id && ! $is_hidden ) {
				$msg->attachment = $this->get_attachment_data( (int) $msg->attachment_id );
			}

			if ( $msg->media_id && ! $is_hidden ) {
				$msg->media_share = $this->get_media_share_data( (int) $msg->media_id, $user_id );
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
	 * Delete a message (delete-for-everyone, sender only).
	 *
	 * @param int $message_id Message ID.
	 * @param int $user_id    User requesting deletion.
	 * @return array{success: bool, error?: string} Result with a status code the
	 *               controller maps to HTTP (not_found→404, not_sender→403).
	 */
	public function delete_message( int $message_id, int $user_id ): array {
		global $wpdb;

		$msg_table = $wpdb->prefix . 'mvs_messages';

		// Look the message up first so the controller can return precise status
		// codes (404 when it never existed, 403 when the caller isn't the
		// sender) instead of a blanket 400 Bad Request (Basecamp #9936826065).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT sender_id, is_deleted, conversation_id FROM {$msg_table} WHERE id = %d",
				$message_id
			)
		);

		if ( null === $row ) {
			return array(
				'success' => false,
				'error'   => 'not_found',
			);
		}

		// Delete-for-everyone is sender-only. A recipient hiding only their own
		// copy needs per-participant deletion, which is part of the
		// group-conversation work (tracked separately) - until then a recipient
		// gets a clear 403 rather than a confusing 400.
		if ( (int) $row->sender_id !== $user_id ) {
			return array(
				'success' => false,
				'error'   => 'not_sender',
			);
		}

		// Already soft-deleted by the sender — idempotent no-op, report success.
		if ( 1 === (int) $row->is_deleted ) {
			return array( 'success' => true );
		}

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

		if ( false === $result ) {
			return array(
				'success' => false,
				'error'   => 'db_error',
			);
		}

		if ( $result > 0 ) {
			// Keep the conversation's stored last-message preview honest: if the
			// just-deleted message was the conversation's last, recompute it from
			// the newest still-visible message (or clear it). Without this the
			// sidebar preview stays stale on reload / other devices after a
			// delete - the client only updated its own state optimistically
			// (Basecamp #9962618059, symptom B).
			$this->refresh_conversation_last_message( (int) $row->conversation_id );

			do_action( 'mvs_message_deleted', $message_id, $user_id, false );
		}

		return array( 'success' => true );
	}

	/**
	 * Build the stored conversation preview string for a message. Shared by
	 * send_message() and refresh_conversation_last_message() so the preview
	 * format stays identical across the send and delete paths.
	 *
	 * @param string $content       Message content.
	 * @param string $message_type  Message type (text|voice|image|video|audio|file|media_share).
	 * @param int    $attachment_id Attachment post id, when the message carries
	 *                              one. Clients (the BuddyNext theme, mobile apps)
	 *                              may send an attachment under message_type
	 *                              'text' (or a raw MIME the allow-list resets to
	 *                              'text'), which previously stored an EMPTY
	 *                              preview and left a blank line in the
	 *                              conversation list (card 10127764989); the
	 *                              attachment's real MIME resolves the label.
	 * @param int    $media_id      Shared media item id, when present.
	 * @return string Preview (max 100 chars).
	 */
	private function build_message_preview( string $content, string $message_type, int $attachment_id = 0, int $media_id = 0 ): string {
		$preview = mb_substr( wp_strip_all_tags( $content ), 0, 100 );
		if ( '' !== $preview && 'voice' !== $message_type ) {
			return $preview;
		}

		// Attachment-only messages: a typed placeholder, the way every chat app
		// previews its media messages. 'audio' was previously missing entirely
		// and fell through to '' (card 10127764989).
		$labels = array(
			'voice'       => __( 'Voice message', 'wpmediaverse' ),
			'image'       => __( 'Photo', 'wpmediaverse' ),
			'video'       => __( 'Video', 'wpmediaverse' ),
			'audio'       => __( 'Audio', 'wpmediaverse' ),
			'file'        => __( 'File', 'wpmediaverse' ),
			'media_share' => __( 'Shared a media', 'wpmediaverse' ),
		);

		if ( isset( $labels[ $message_type ] ) ) {
			return $labels[ $message_type ];
		}

		if ( $attachment_id > 0 ) {
			$mime = (string) get_post_mime_type( $attachment_id );
			foreach ( array( 'image', 'video', 'audio' ) as $media_kind ) {
				if ( 0 === strpos( $mime, $media_kind . '/' ) ) {
					return $labels[ $media_kind ];
				}
			}
			return __( 'Attachment', 'wpmediaverse' );
		}

		if ( $media_id > 0 ) {
			// A shared MediaVerse item (how the BuddyNext theme sends DM photos):
			// label by the item's real type, falling back to the generic share.
			$media_type = (string) \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get( $media_id, 'media_type' );
			if ( isset( $labels[ $media_type ] ) ) {
				return $labels[ $media_type ];
			}
			return $labels['media_share'];
		}

		return $preview;
	}

	/**
	 * Recompute a conversation's stored last_message_id / last_message_preview
	 * from the newest still-visible message (not is_deleted, not
	 * deleted_for_all), or clear them when nothing visible remains. Called after
	 * a message is deleted/unsent so the sidebar preview never shows a removed
	 * message (Basecamp #9962618059).
	 *
	 * @param int $conversation_id Conversation ID.
	 */
	private function refresh_conversation_last_message( int $conversation_id ): void {
		if ( $conversation_id <= 0 ) {
			return;
		}

		global $wpdb;
		$conv_table = $wpdb->prefix . 'mvs_conversations';
		$msg_table  = $wpdb->prefix . 'mvs_messages';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$last = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, content, message_type, attachment_id, media_id, created_at
				FROM {$msg_table}
				WHERE conversation_id = %d AND is_deleted = 0 AND deleted_for_all = 0
				ORDER BY created_at DESC, id DESC
				LIMIT 1",
				$conversation_id
			)
		);

		if ( $last ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update(
				$conv_table,
				array(
					'last_message_id'      => (int) $last->id,
					'last_message_preview' => $this->build_message_preview(
						(string) $last->content,
						(string) $last->message_type,
						(int) $last->attachment_id,
						(int) $last->media_id
					),
					'last_activity_at'     => $last->created_at,
				),
				array( 'id' => $conversation_id ),
				array( '%d', '%s', '%s' ),
				array( '%d' )
			);
		} else {
			// Nothing visible left - clear the stored preview so the sidebar
			// shows an empty conversation rather than a deleted message.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update(
				$conv_table,
				array(
					'last_message_id'      => null,
					'last_message_preview' => '',
				),
				array( 'id' => $conversation_id ),
				array( '%d', '%s' ),
				array( '%d' )
			);
		}
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
				"SELECT sender_id, created_at, conversation_id FROM {$msg_table} WHERE id = %d",
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

		// Unsend (delete-for-everyone) also removes the message from every
		// surface, so refresh the conversation's stored preview the same way
		// delete_message does (Basecamp #9962618059).
		$this->refresh_conversation_last_message( (int) $msg->conversation_id );

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
	 * How long (seconds) a typing ping stays live before it is treated as stale.
	 * The client re-pings well inside this window while a user is actively typing.
	 *
	 * @since 2.1.0
	 */
	private const TYPING_TTL = 6;

	/**
	 * Mark a user as typing in a conversation.
	 *
	 * Persisted on the participant's own row (`mvs_conversation_participants.
	 * typing_until`) rather than `wp_cache`: request-scoped object cache meant the
	 * write in the typist's request was gone before the recipient's poll read it,
	 * so on any site without a persistent object cache the indicator never showed.
	 * The per-(conversation, user) row already exists, so this adds no cardinality
	 * and honours Coding Rule #16 (no wp_options / unguarded-transient bloat).
	 *
	 * @param int $conversation_id Conversation ID.
	 * @param int $user_id         User ID.
	 */
	public function set_typing( int $conversation_id, int $user_id ): void {
		global $wpdb;

		$table = $wpdb->prefix . 'mvs_conversation_participants';
		$until = gmdate( 'Y-m-d H:i:s', time() + self::TYPING_TTL );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET typing_until = %s WHERE conversation_id = %d AND user_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$until,
				$conversation_id,
				$user_id
			)
		);
	}

	/**
	 * Get the users currently typing in a conversation.
	 *
	 * One indexed query against `mvs_conversation_participants` (KEY conv_typing),
	 * so it works identically with or without a persistent object cache. A row is
	 * "typing" while its `typing_until` is in the future; stale markers simply
	 * fall out of the window with no cleanup pass needed.
	 *
	 * @param int $conversation_id Conversation ID.
	 * @param int $exclude_user_id User to exclude (current user).
	 * @return array<int, array{id: int, name: string}> Users currently typing.
	 */
	public function get_typing_users( int $conversation_id, int $exclude_user_id ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'mvs_conversation_participants';
		$now   = gmdate( 'Y-m-d H:i:s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$typing_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT user_id FROM {$table}
				 WHERE conversation_id = %d
				   AND user_id <> %d
				   AND typing_until IS NOT NULL
				   AND typing_until > %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$conversation_id,
				$exclude_user_id,
				$now
			)
		);

		if ( empty( $typing_ids ) ) {
			return array();
		}

		$typing_ids = array_map( 'intval', $typing_ids );
		$typing     = array();

		// Resolve display names from the already-cached participant set.
		foreach ( $this->get_participants( $conversation_id ) as $p ) {
			if ( in_array( (int) $p['id'], $typing_ids, true ) ) {
				$typing[] = array(
					'id'   => (int) $p['id'],
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
			$this->touch_message( $message_id );
			do_action( 'mvs_message_reaction_added', $message_id, $user_id, $emoji );
		}

		return (bool) $result;
	}

	/**
	 * Stamp a message's updated_at so the poll notices a change that did not
	 * touch created_at.
	 *
	 * A reaction lives in its own table, so without this a reaction on an
	 * already-delivered message is invisible to the other participant's poll (it
	 * filters on created_at) until a full reload. Stamped on both add and remove
	 * because a removal is a hard delete that leaves no other trace.
	 * poll_reaction_updates() reads this column.
	 *
	 * @param int $message_id Message ID.
	 * @return void
	 */
	private function touch_message( int $message_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . 'mvs_messages',
			array( 'updated_at' => current_time( 'mysql', true ) ),
			array( 'id' => $message_id ),
			array( '%s' ),
			array( '%d' )
		);
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

		if ( $result ) {
			$this->touch_message( $message_id );
		}

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
				"SELECT emoji, GROUP_CONCAT(user_id) as user_ids, COUNT(*) as count, MAX(created_at) as reacted_at
				FROM {$react_table}
				WHERE message_id = %d
				GROUP BY emoji",
				$message_id
			)
		);

		$reactions = array();
		foreach ( $rows as $row ) {
			$reactions[] = array(
				'emoji'      => $row->emoji,
				'count'      => (int) $row->count,
				'user_ids'   => array_map( 'intval', explode( ',', $row->user_ids ) ),
				// Latest reaction of this emoji (UTC); normalizer adds reacted_at_gmt.
				'reacted_at' => (string) $row->reacted_at,
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

		$participant = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT last_read_at, cleared_up_to FROM {$part_table} WHERE conversation_id = %d AND user_id = %d",
				$conversation_id,
				$user_id
			)
		);

		$last_read     = $participant ? (string) $participant->last_read_at : '';
		$cleared_up_to = $participant ? (int) $participant->cleared_up_to : 0;

		// History this user deleted can never count as unread.
		if ( '' === $last_read ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$msg_table}
					WHERE conversation_id = %d AND sender_id != %d AND deleted_for_all = 0 AND id > %d",
					$conversation_id,
					$user_id,
					$cleared_up_to
				)
			);
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$msg_table}
				WHERE conversation_id = %d AND sender_id != %d AND created_at > %s AND deleted_for_all = 0 AND id > %d",
				$conversation_id,
				$user_id,
				$last_read,
				$cleared_up_to
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
				AND (p.last_read_at IS NULL OR m.created_at > p.last_read_at)
				AND m.id > p.cleared_up_to",
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

		$since_ts  = strtotime( $since );
		$since_gmt = gmdate( 'Y-m-d H:i:s', $since_ts );

		/*
		 * Sweep POLL_OVERLAP seconds BEHIND the cursor — the message cursor has
		 * the same second-precision race the reaction sweep does.
		 *
		 * `created_at` is a second-precision DATETIME and the client's cursor is
		 * the previous response's `server_time`, stamped AFTER this query ran. A
		 * message inserted in the remainder of that same second was therefore
		 * missed by this query AND excluded by the next poll's strict `>`, so it
		 * never reached the other participant until a reload or a thread switch
		 * refetched the list. The exposed window is up to a full second against
		 * a ~5s poll — the same arithmetic that lost roughly one reaction in five
		 * before the sweep was added below.
		 *
		 * Re-serving a couple of seconds of messages the client already has is
		 * free: the client keys off the message id (`appendMessage()` returns
		 * early when `.bn-dm-msg[data-msg-id]` is already in the log, which is
		 * how our own optimistic send survives being echoed back).
		 */
		$sweep_gmt = gmdate( 'Y-m-d H:i:s', $since_ts - self::POLL_OVERLAP );

		// Unsent (deleted_for_all) messages must still be POLLED so other
		// participants' open chats learn about the unsend without a refresh
		// (Basecamp #9962618059 follow-up: excluding them left the original
		// bubble in every other client until a hard reload). There is no
		// updated_at column to scope "unsent since the last poll", but unsend
		// is only allowed within UNSEND_WINDOW_SECONDS of created_at — so any
		// row that just flipped is younger than the window (+1 min clock
		// slack). The client merge is idempotent, so re-serving an unsent row
		// for a few polls is harmless.
		$unsend_window_start = gmdate( 'Y-m-d H:i:s', time() - self::UNSEND_WINDOW_SECONDS - MINUTE_IN_SECONDS );

		// Get new messages across all user's conversations.
		$new_messages = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT m.*
				FROM {$msg_table} m
				INNER JOIN {$part_table} p ON p.conversation_id = m.conversation_id AND p.user_id = %d
				WHERE p.status IN ('active', 'request_pending')
				AND m.is_deleted = 0
				AND m.id > p.cleared_up_to
				AND (
					( m.deleted_for_all = 0 AND m.created_at > %s )
					OR ( m.deleted_for_all = 1 AND m.created_at > %s )
				)
				ORDER BY m.created_at ASC
				LIMIT 100",
				$user_id,
				$sweep_gmt,
				$unsend_window_start
			)
		);

		// phpcs:enable

		// Enrich messages.
		foreach ( $new_messages as &$msg ) {
			// Same blanking as get_messages(): an unsent message must never
			// ship its original content/metadata to other participants.
			if ( $msg->deleted_for_all ) {
				$msg->content  = '';
				$msg->metadata = null;
			}
			$sender = get_userdata( (int) $msg->sender_id );
			if ( $sender ) {
				$msg->sender_name        = $sender->display_name;
				$msg->sender_avatar      = get_avatar_url( $msg->sender_id, array( 'size' => 64 ) );
				$msg->sender_profile_url = \WPMediaVerse\Core\Plugin::container()->get( 'template_helpers' )->get_user_profile_url( (int) $msg->sender_id );
			}
			if ( $msg->metadata ) {
				$msg->metadata = json_decode( $msg->metadata, true );
			}
			// Additive UTC-ISO timestamp for the app (stored value is UTC but unmarked).
			$msg->created_at_gmt = self::to_iso8601( (string) $msg->created_at );
			$msg->reactions      = $this->get_message_reactions( (int) $msg->id );
			// Don't ship attachment/media data for unsent messages either.
			if ( $msg->attachment_id && ! $msg->deleted_for_all ) {
				$msg->attachment = $this->get_attachment_data( (int) $msg->attachment_id );
			}
			if ( $msg->media_id && ! $msg->deleted_for_all ) {
				$msg->media_share = $this->get_media_share_data( (int) $msg->media_id, $user_id );
			}
			if ( $msg->parent_id ) {
				$msg->parent_preview = $this->get_message_preview( (int) $msg->parent_id );
			}
		}

		return $new_messages;
	}

	/**
	 * Already-seen messages whose reactions changed since the last poll.
	 *
	 * The poll only returns messages newer than the client's last-seen time, so a
	 * reaction on an ALREADY-delivered message is invisible to it — the reason
	 * reactions did not appear live for the other participant (card 10122929662).
	 * This is the companion query: messages the viewer participates in, created on
	 * or before `$since` (a newer message already ships its reactions in poll()),
	 * whose updated_at moved past `$since` because a reaction was added or removed.
	 *
	 * Returns just id + conversation_id + the fresh reaction set — never the
	 * message body, which has not changed and must not be re-shipped (an unsent
	 * message's blanking, for one, must not be undone here).
	 *
	 * @param int    $user_id Viewer.
	 * @param string $since   Client's last-poll time (any strtotime-able string).
	 * @return array<int,array{id:int,conversation_id:int,reactions:array}>
	 */
	public function poll_reaction_updates( int $user_id, string $since ): array {
		global $wpdb;

		$msg_table  = $wpdb->prefix . 'mvs_messages';
		$part_table = $wpdb->prefix . 'mvs_conversation_participants';
		$since_ts   = strtotime( $since );

		/*
		 * Sweep a couple of seconds BEHIND the cursor.
		 *
		 * `updated_at` is a second-precision DATETIME and the client's cursor is
		 * the previous response's server_time, so a reaction landing in the SAME
		 * second as that cursor was skipped forever by the strict `>` below: the
		 * row's updated_at equals the cursor, the next poll asks for strictly
		 * greater, and the client never learned about it until a full reload.
		 * With a ~5s poll that silently lost roughly one reaction in five —
		 * measured live, two-actor, on 2.2.0.
		 *
		 * Re-sending a reaction set the client already has is harmless: the
		 * client rebuilds a message's chips from the authoritative set in this
		 * payload, so a repeated render is idempotent.
		 *
		 * Both bounds below use the SWEPT cursor, which keeps this set disjoint
		 * from poll()'s: poll() returns messages created after the sweep point
		 * and enriches each with its own reactions, so anything newer than that
		 * must not be re-shipped here.
		 */
		$sweep_gmt = gmdate( 'Y-m-d H:i:s', $since_ts - self::POLL_OVERLAP );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT m.id, m.conversation_id
				FROM {$msg_table} m
				INNER JOIN {$part_table} p ON p.conversation_id = m.conversation_id AND p.user_id = %d
				WHERE p.status IN ('active', 'request_pending')
				AND m.is_deleted = 0
				AND m.deleted_for_all = 0
				AND m.id > p.cleared_up_to
				AND m.updated_at > %s
				AND m.created_at <= %s
				ORDER BY m.updated_at ASC
				LIMIT 100",
				$user_id,
				$sweep_gmt,
				$sweep_gmt
			)
		);

		$updates = array();
		foreach ( (array) $rows as $row ) {
			$updates[] = array(
				'id'              => (int) $row->id,
				'conversation_id' => (int) $row->conversation_id,
				'reactions'       => $this->get_message_reactions( (int) $row->id ),
			);
		}

		return $updates;
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
	 * Get media share data for an mvs_media post, scoped to a specific viewer.
	 *
	 * All URLs are signed for the explicit conversation viewer (NOT the ambient
	 * current user — message formatting can run outside the viewer's auth
	 * context, e.g. background poll enrichment, which is why the thumbnail used
	 * to come back empty) and routed through the access-controlled mvs serve
	 * endpoint. That makes conversation-scoped ('dm'-privacy) attachments
	 * viewable AND downloadable by the conversation's participants while staying
	 * inaccessible to everyone else. The public /media/{slug}/ permalink is
	 * intentionally NOT exposed — DM media must only be reachable via the signed
	 * serve URL.
	 *
	 * @param int $media_id  mvs_media post ID.
	 * @param int $viewer_id User the URLs are signed for (the message viewer).
	 * @return array|null
	 */
	private function get_media_share_data( int $media_id, int $viewer_id ) {
		$repo = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );
		if ( ! $repo->exists( $media_id ) ) {
			return null;
		}

		$data = array(
			'id'    => $media_id,
			'title' => $repo->get( $media_id, 'title' ),
			'type'  => $repo->get( $media_id, 'media_type' ) ?: 'image',
		);

		$su = \WPMediaVerse\Core\Plugin::container()->get( 'signed_urls' );
		if ( $su ) {
			$thumb                = $su->generate_thumbnail( $media_id, $viewer_id );
			$view                 = $su->generate( $media_id, $viewer_id );
			$download             = $su->generate( $media_id, $viewer_id, 0, true );
			$data['thumbnail']    = is_string( $thumb ) ? $thumb : '';
			$data['url']          = is_string( $view ) ? $view : '';
			$data['download_url'] = is_string( $download ) ? $download : '';
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

		// Conversations the user has messages in - captured BEFORE the soft-delete
		// so each one's stored preview can be refreshed afterwards.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$conversation_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT conversation_id FROM {$msg_table} WHERE sender_id = %d",
				$user_id
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$count = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$msg_table} SET is_deleted = 1, content = '' WHERE sender_id = %d",
				$user_id
			)
		);

		// Refresh each affected conversation's stored last-message preview so the
		// sidebar never keeps showing an erased message (Basecamp #9962618059 -
		// same staleness as delete/unsend, caught in the double-verify pass).
		foreach ( $conversation_ids as $conversation_id ) {
			$this->refresh_conversation_last_message( (int) $conversation_id );
		}

		return (int) $count;
	}
}
