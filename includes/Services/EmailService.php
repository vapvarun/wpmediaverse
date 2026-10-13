<?php
/**
 * Member emails: a minimal set.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Emails a member about the few things they would otherwise miss while they
 * are away from the site: a photo battle invite, a document shared with them,
 * the outcome of their report, and (always) a confirmation that their account
 * is scheduled for deletion.
 *
 * One channel: it listens to the notifications MediaVerse already creates
 * (mvs_notification_created) and sends only the types the owner switched on
 * in Settings > General > Emails. A member turns all of it off with one "Email
 * me about activity" switch, or with the unsubscribe link in any email. From
 * is the site name and the admin email. Plain, translatable text; developers
 * change it with the mvs_email_subject / mvs_email_body filters.
 *
 * @since 2.6.0
 */
class EmailService {

	/** Notification type => the option that switches its email on ('1'). */
	public const TYPES = array(
		'battle_invite'   => 'mvs_email_battle_invite',
		'document_shared' => 'mvs_email_document_shared',
		'report_resolved' => 'mvs_email_report_outcome',
		'new_follower'    => 'mvs_email_new_follower',
		'media_comment'   => 'mvs_email_media_comment',
	);

	/** User meta: 'off' when the member does not want activity emails. */
	public const MEMBER_META = 'mvs_email_activity';

	/**
	 * Hook the channel.
	 */
	public function init(): void {
		add_action( 'mvs_notification_created', array( $this, 'on_notification' ), 20, 7 );
		add_action( 'mvs_account_deletion_requested', array( $this, 'on_deletion_requested' ), 10, 2 );
		add_action( 'template_redirect', array( $this, 'maybe_unsubscribe' ), 1 );
	}

	/**
	 * Whether the owner switched on any member email type. When none is on,
	 * a member's activity-email switch would do nothing, so it is hidden.
	 *
	 * @since 2.6.0
	 * @return bool
	 */
	public static function any_type_enabled(): bool {
		foreach ( array_keys( self::types() ) as $type ) {
			if ( ! self::host_owns( $type ) && self::is_type_on( $type ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Every member email the owner can switch, with its setting and default.
	 *
	 * The one list the Emails settings section renders and every send checks.
	 * Pro and other plugins add their emails through the filter; they then send
	 * with send_member_email() and get the owner switch, the member's
	 * unsubscribe and the From header for free.
	 *
	 * 'host_owned' (default true): a community host such as BuddyNext sends
	 * this email itself. Set it false for an email the host has no equivalent
	 * for, so it is still sent (and still switchable) on the host's sites.
	 *
	 * @since 2.6.2
	 *
	 * @return array<string, array{option:string, label:string, description:string, default:bool, host_owned?:bool}>
	 */
	public static function types(): array {
		$types = array(
			'battle_invite'   => array(
				'option'      => self::TYPES['battle_invite'],
				'label'       => __( 'Photo battle invites', 'wpmediaverse' ),
				'description' => __( 'Someone challenged the member to a battle (MediaVerse Pro).', 'wpmediaverse' ),
				'default'     => false,
			),
			'document_shared' => array(
				'option'      => self::TYPES['document_shared'],
				'label'       => __( 'Documents shared with a member', 'wpmediaverse' ),
				'description' => __( 'Someone shared a document with the member (MediaVerse Pro).', 'wpmediaverse' ),
				'default'     => false,
			),
			'report_resolved' => array(
				'option'      => self::TYPES['report_resolved'],
				'label'       => __( 'Report reviewed', 'wpmediaverse' ),
				'description' => __( 'A moderator resolved or dismissed a report the member filed. The email does not say what was decided.', 'wpmediaverse' ),
				'default'     => false,
			),
			'new_follower'    => array(
				'option'      => self::TYPES['new_follower'],
				'label'       => __( 'New follower', 'wpmediaverse' ),
				'description' => __( 'Someone started following the member.', 'wpmediaverse' ),
				'default'     => false,
			),
			'media_comment'   => array(
				'option'      => self::TYPES['media_comment'],
				'label'       => __( 'Comment on their media', 'wpmediaverse' ),
				'description' => __( 'Someone commented on a photo, video or file the member posted.', 'wpmediaverse' ),
				'default'     => false,
			),
		);

		/**
		 * Filters the member emails the owner can switch on or off.
		 *
		 * Each entry: `option` (the setting name), `label`, `description`, and
		 * `default` (true when the email is on until the owner turns it off).
		 *
		 * @since 2.6.2
		 *
		 * @param array<string, array> $types Type => definition.
		 */
		return (array) apply_filters( 'mvs_email_types', $types );
	}

	/**
	 * Whether an email type is switched on (its setting, else its default).
	 *
	 * @param string $type Email type.
	 * @return bool
	 */
	private static function is_type_on( string $type ): bool {
		$types = self::types();
		if ( empty( $types[ $type ]['option'] ) ) {
			return false;
		}
		return (bool) get_option( $types[ $type ]['option'], ! empty( $types[ $type ]['default'] ) );
	}

	/**
	 * Whether the community host sends this email type itself.
	 *
	 * @since 2.6.2
	 *
	 * @param string $type Email type.
	 * @return bool
	 */
	public static function host_owns( string $type ): bool {
		return self::host_sends() && false !== ( self::types()[ $type ]['host_owned'] ?? true );
	}

	/**
	 * Whether BuddyNext sends member emails for MediaVerse activity. MediaVerse
	 * is a core feature of BuddyNext, so on a BuddyNext site BuddyNext owns
	 * these emails and MediaVerse sends only when it runs on its own (owner
	 * decision 2026-10-02; one email per event, not two). Account deletion
	 * confirmations are not activity and are always sent.
	 *
	 * @since 2.6.1
	 * @return bool
	 */
	public static function host_sends(): bool {
		return (bool) apply_filters( 'mvs_buddynext_active', false );
	}

	/**
	 * Whether the owner switched a type's email on.
	 *
	 * @param string $type Notification type.
	 * @return bool
	 */
	public function type_enabled( string $type ): bool {
		return self::is_type_on( $type );
	}

	/**
	 * Send a member email by every rule: the owner's switch for its type, the
	 * member's unsubscribe, BuddyNext sending its own mail, the unsubscribe link
	 * and the site's From header.
	 *
	 * The door for plugins that email members (register the type through
	 * mvs_email_types first).
	 *
	 * @since 2.6.2
	 *
	 * @param int    $user_id Recipient.
	 * @param string $type    Registered email type.
	 * @param string $subject Subject.
	 * @param string $body    Plain-text body.
	 * @return bool Whether the email was sent.
	 */
	public function send_member_email( int $user_id, string $type, string $subject, string $body ): bool {
		if ( self::host_owns( $type ) || ! $this->type_enabled( $type ) || ! $this->member_wants( $user_id ) ) {
			return false;
		}
		return $this->send( $user_id, $type, $subject, $body, true );
	}

	/**
	 * Whether the member wants activity emails (default: yes).
	 *
	 * @param int $user_id Member.
	 * @return bool
	 */
	public function member_wants( int $user_id ): bool {
		return 'off' !== get_user_meta( $user_id, self::MEMBER_META, true );
	}

	/**
	 * Email a notification the owner switched on (mvs_notification_created).
	 *
	 * @param int    $notification_id Notification id.
	 * @param int    $user_id         Recipient.
	 * @param string $type            Type.
	 * @param int    $actor_id        Who caused it.
	 * @param int    $media_id        Related item.
	 * @param string $message         Rendered notification text.
	 * @param string $link            Where it points.
	 */
	public function on_notification( $notification_id, $user_id, $type, $actor_id = 0, $media_id = 0, $message = '', $link = '' ): void {
		unset( $notification_id, $actor_id, $media_id );
		$user_id = (int) $user_id;
		$type    = (string) $type;

		if ( self::host_owns( $type ) || ! $this->type_enabled( $type ) || ! $this->member_wants( $user_id ) ) {
			return;
		}

		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		switch ( $type ) {
			case 'battle_invite':
				/* translators: %s: site name. */
				$subject = sprintf( __( 'You have a photo battle invite on %s', 'wpmediaverse' ), $site );
				break;
			case 'document_shared':
				/* translators: %s: site name. */
				$subject = sprintf( __( 'A document was shared with you on %s', 'wpmediaverse' ), $site );
				break;
			case 'new_follower':
				/* translators: %s: site name. */
				$subject = sprintf( __( 'You have a new follower on %s', 'wpmediaverse' ), $site );
				break;
			case 'media_comment':
				/* translators: %s: site name. */
				$subject = sprintf( __( 'New comment on your media on %s', 'wpmediaverse' ), $site );
				break;
			default:
				/* translators: %s: site name. */
				$subject = sprintf( __( 'Your report on %s was reviewed', 'wpmediaverse' ), $site );
		}

		$body = wp_strip_all_tags( (string) $message );
		if ( '' !== (string) $link ) {
			$body .= "\n\n" . esc_url_raw( (string) $link );
		}

		$this->send( $user_id, $type, $subject, $body, true );
	}

	/**
	 * Always confirm a scheduled account deletion: it is how a member learns
	 * that someone with their password asked for it.
	 *
	 * @param int $user_id      Member.
	 * @param int $scheduled_at When deletion runs (Unix time).
	 */
	public function on_deletion_requested( $user_id, $scheduled_at ): void {
		$user_id = (int) $user_id;
		$site    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$date    = date_i18n( get_option( 'date_format' ), (int) $scheduled_at );

		/* translators: %s: site name. */
		$subject = sprintf( __( 'Your %s account is scheduled for deletion', 'wpmediaverse' ), $site );
		$body    = sprintf(
			/* translators: 1: date the account will be deleted, 2: login URL. */
			__( "Your account and everything you uploaded will be permanently deleted on %1\$s.\n\nIf you did not ask for this, or you changed your mind, log in before then to cancel:\n%2\$s", 'wpmediaverse' ),
			$date,
			wp_login_url()
		);

		$this->send( $user_id, 'account_deletion', $subject, $body, false );
	}

	/**
	 * Send one email.
	 *
	 * @param int    $user_id     Recipient.
	 * @param string $type        Type (for the filters).
	 * @param string $subject     Subject.
	 * @param string $body        Plain-text body.
	 * @param bool   $unsubscribe Whether to add the unsubscribe link.
	 * @return bool
	 */
	private function send( int $user_id, string $type, string $subject, string $body, bool $unsubscribe ): bool {
		$user = get_userdata( $user_id );
		if ( ! $user || ! is_email( $user->user_email ) ) {
			return false;
		}

		if ( $unsubscribe ) {
			$body .= "\n\n--\n" . sprintf(
				/* translators: %s: unsubscribe URL. */
				__( 'To stop these emails: %s', 'wpmediaverse' ),
				$this->unsubscribe_url( $user_id )
			);
		}

		/**
		 * Filters a member email's subject.
		 *
		 * @since 2.6.0
		 *
		 * @param string $subject Subject.
		 * @param string $type    battle_invite | document_shared | report_resolved | account_deletion.
		 * @param int    $user_id Recipient.
		 */
		$subject = (string) apply_filters( 'mvs_email_subject', $subject, $type, $user_id );

		/**
		 * Filters a member email's plain-text body.
		 *
		 * @since 2.6.0
		 *
		 * @param string $body    Body.
		 * @param string $type    battle_invite | document_shared | report_resolved | account_deletion.
		 * @param int    $user_id Recipient.
		 */
		$body = (string) apply_filters( 'mvs_email_body', $body, $type, $user_id );

		$from = sprintf( 'From: %s <%s>', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), get_option( 'admin_email' ) );

		return (bool) wp_mail( $user->user_email, $subject, $body, array( $from ) );
	}

	/**
	 * A link that turns activity emails off without signing in.
	 *
	 * @param int $user_id Member.
	 * @return string
	 */
	public function unsubscribe_url( int $user_id ): string {
		return add_query_arg(
			array(
				'mvs_unsubscribe' => $user_id,
				'mvs_sig'         => $this->signature( $user_id ),
			),
			home_url( '/' )
		);
	}

	/**
	 * Handle an unsubscribe link.
	 */
	public function maybe_unsubscribe(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- a signed link from an email; the HMAC is the check.
		if ( ! isset( $_GET['mvs_unsubscribe'], $_GET['mvs_sig'] ) ) {
			return;
		}
		$user_id = absint( $_GET['mvs_unsubscribe'] );
		$sig     = sanitize_text_field( wp_unslash( $_GET['mvs_sig'] ) );
		// phpcs:enable

		if ( ! $user_id || ! hash_equals( $this->signature( $user_id ), $sig ) ) {
			wp_die( esc_html__( 'This unsubscribe link is not valid.', 'wpmediaverse' ), '', array( 'response' => 400 ) );
		}

		update_user_meta( $user_id, self::MEMBER_META, 'off' );
		wp_die(
			esc_html__( 'You will not get activity emails from this site any more. You can turn them back on in your profile settings.', 'wpmediaverse' ),
			esc_html__( 'Unsubscribed', 'wpmediaverse' ),
			array( 'response' => 200 )
		);
	}

	/**
	 * HMAC for a member's unsubscribe link.
	 *
	 * @param int $user_id Member.
	 * @return string
	 */
	private function signature( int $user_id ): string {
		return hash_hmac( 'sha256', 'mvs-unsubscribe|' . $user_id, wp_salt( 'auth' ) );
	}
}
