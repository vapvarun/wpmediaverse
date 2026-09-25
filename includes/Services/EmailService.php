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
	 * Whether the owner switched a type's email on.
	 *
	 * @param string $type Notification type.
	 * @return bool
	 */
	public function type_enabled( string $type ): bool {
		return isset( self::TYPES[ $type ] ) && (bool) get_option( self::TYPES[ $type ], false );
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

		if ( ! $this->type_enabled( $type ) || ! $this->member_wants( $user_id ) ) {
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
			__( "Your account and everything you uploaded will be permanently deleted on %1\$s.\n\nIf you did not ask for this, or you changed your mind, sign in before then to cancel:\n%2\$s", 'wpmediaverse' ),
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
