<?php
/**
 * Private community: new uploads default to Members Only, never Public.
 *
 * Public media is stored where anyone holding the file address can open it,
 * which on a private community exposes the first uploads to people who are
 * not members. The first activation on an already-private community therefore
 * seeds Members Only (Activator), and a site that becomes private later is
 * asked once, on MediaVerse screens, instead of being changed behind the
 * owner's back (Basecamp 10252887515, owner decision 2026-09-27).
 *
 * "Private community" is whatever answers `mvs_rest_require_auth` with true,
 * the signal CommunityPrivacyGate already enforces (BuddyNext's private mode
 * answers it), so MediaVerse never reads another plugin's settings.
 *
 * @package WPMediaVerse
 * @since   2.6.0
 */

namespace WPMediaVerse\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Seeds and suggests the Members Only upload default on private communities.
 */
class PrivateCommunityDefault {

	/**
	 * admin-post action for the notice's two buttons.
	 *
	 * @var string
	 */
	public const ACTION = 'mvs_private_default';

	/**
	 * Option set once the owner answered the notice either way.
	 *
	 * @var string
	 */
	public const ANSWERED_OPTION = 'mvs_private_default_answered';

	/**
	 * Hook the notice and its handler.
	 */
	public function __construct() {
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Whether the site runs as a private community.
	 *
	 * @return bool
	 */
	public static function community_is_private(): bool {
		return (bool) apply_filters( 'mvs_rest_require_auth', false, null );
	}

	/**
	 * The upload privacy a fresh install should start with.
	 *
	 * @return string
	 */
	public static function default_privacy(): string {
		return self::community_is_private() ? 'members' : 'public';
	}

	/**
	 * Ask once when a private community still defaults new uploads to Public.
	 *
	 * @return void
	 */
	public function render_notice(): void {
		if ( ! current_user_can( 'manage_options' )
			|| get_option( self::ANSWERED_OPTION )
			|| 'public' !== get_option( 'mvs_default_privacy', 'public' )
			|| ! OverviewPage::is_mvs_screen( get_current_screen() )
			|| ! self::community_is_private()
		) {
			return;
		}

		// Two POST buttons in one form: the answer changes a site setting, so it
		// must not be reachable by following a link.
		printf(
			'<div class="notice notice-warning mvs-notice"><p><strong>%1$s</strong> %2$s</p><form method="post" action="%3$s"><input type="hidden" name="action" value="%4$s" />%5$s<p><button type="submit" name="choice" value="members" class="button button-primary">%6$s</button> <button type="submit" name="choice" value="keep" class="button">%7$s</button></p></form></div>',
			esc_html__( 'Your community is private, but new uploads default to Public.', 'wpmediaverse' ),
			esc_html__( 'Public files can be opened by anyone who has the file address, including people who are not members.', 'wpmediaverse' ),
			esc_url( admin_url( 'admin-post.php' ) ),
			esc_attr( self::ACTION ),
			wp_nonce_field( self::ACTION, '_wpnonce', true, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_nonce_field() returns escaped markup.
			esc_html__( 'Use Members Only', 'wpmediaverse' ),
			esc_html__( 'Keep Public', 'wpmediaverse' )
		);
	}

	/**
	 * Record the owner's answer.
	 *
	 * @return void
	 */
	public function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to change this setting.', 'wpmediaverse' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::ACTION );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by check_admin_referer() above.
		if ( 'members' === sanitize_key( wp_unslash( $_POST['choice'] ?? '' ) ) ) {
			update_option( 'mvs_default_privacy', 'members' );
		}

		update_option( self::ANSWERED_OPTION, 1, false );

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=' . OverviewPage::PAGE_SLUG ) );
		exit;
	}
}
