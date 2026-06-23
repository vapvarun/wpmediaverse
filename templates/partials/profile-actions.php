<?php
/**
 * Partial: Follow + Message buttons for user profile headers.
 *
 * Expects these variables in scope:
 *   $mvs_profile_id     (int)  — The profile user's ID.
 *   $mvs_is_own_profile (bool) — Whether the viewer is the profile owner.
 *
 * @package WPMediaVerse
 * @since   1.2.0
 */

defined( 'ABSPATH' ) || exit;

$mvs_is_own_profile = $mvs_is_own_profile ?? $mvs_is_own ?? false;
if ( ! isset( $mvs_profile_id ) || $mvs_is_own_profile || ! is_user_logged_in() ) {
	return;
}

// Follow + block state.
$mvs_is_following = false;
$mvs_is_blocked   = false;
if ( class_exists( '\WPMediaVerse\Core\Plugin' ) ) {
	$mvs_c = \WPMediaVerse\Core\Plugin::container();
	if ( $mvs_c->has( 'follows' ) ) {
		$mvs_is_following = $mvs_c->get( 'follows' )->is_following( get_current_user_id(), $mvs_profile_id );
	}
	if ( $mvs_c->has( 'reports' ) ) {
		$mvs_is_blocked = $mvs_c->get( 'reports' )->is_blocked( get_current_user_id(), $mvs_profile_id );
	}
}

// Messaging enabled? Check recipient-level DM privacy first, then fall back to global.
$mvs_dm_access    = get_user_meta( $mvs_profile_id, '_mvs_dm_access', true );
if ( ! $mvs_dm_access ) {
	$mvs_dm_access = get_option( 'mvs_dm_access', 'everyone' );
}
$mvs_messaging_on = ( 'nobody' !== $mvs_dm_access );
?>
<button type="button" class="mvs-btn mvs-btn--small mvs-follow-toggle <?php echo $mvs_is_following ? 'mvs-follow-toggle--following' : 'mvs-btn--primary'; ?>"
	data-user-id="<?php echo esc_attr( $mvs_profile_id ); ?>"
	data-following="<?php echo $mvs_is_following ? '1' : '0'; ?>"
	data-rest-url="<?php echo esc_url( rest_url( 'mvs/v1/' ) ); ?>"
	data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>">
	<?php echo $mvs_is_following ? esc_html__( 'Following', 'wpmediaverse' ) : esc_html__( 'Follow', 'wpmediaverse' ); ?>
</button>
<?php if ( $mvs_messaging_on ) : ?>
	<button type="button" class="mvs-btn mvs-btn--secondary mvs-btn--small mvs-message-btn"
		data-user-id="<?php echo esc_attr( $mvs_profile_id ); ?>"
		data-rest-url="<?php echo esc_url( rest_url( 'mvs/v1/' ) ); ?>"
		data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>">
		<?php esc_html_e( 'Message', 'wpmediaverse' ); ?>
	</button>
<?php endif; ?>
<button type="button" class="mvs-btn mvs-btn--text mvs-btn--small mvs-block-toggle<?php echo $mvs_is_blocked ? ' mvs-block-toggle--blocked' : ''; ?>"
	data-user-id="<?php echo esc_attr( $mvs_profile_id ); ?>"
	data-blocked="<?php echo $mvs_is_blocked ? '1' : '0'; ?>"
	data-rest-url="<?php echo esc_url( rest_url( 'mvs/v1/' ) ); ?>"
	aria-label="<?php echo $mvs_is_blocked ? esc_attr__( 'Unblock this member', 'wpmediaverse' ) : esc_attr__( 'Block this member', 'wpmediaverse' ); ?>">
	<?php echo $mvs_is_blocked ? esc_html__( 'Unblock', 'wpmediaverse' ) : esc_html__( 'Block', 'wpmediaverse' ); ?>
</button>
