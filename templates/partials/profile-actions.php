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

// Follow state.
$mvs_is_following = false;
if ( class_exists( '\WPMediaVerse\Core\Plugin' ) ) {
	$mvs_c = \WPMediaVerse\Core\Plugin::container();
	if ( $mvs_c->has( 'follows' ) ) {
		$mvs_is_following = $mvs_c->get( 'follows' )->is_following( get_current_user_id(), $mvs_profile_id );
	}
}

// Messaging enabled?
$mvs_dm_access      = get_option( 'mvs_dm_access', 'everyone' );
$mvs_messaging_on   = ( 'nobody' !== $mvs_dm_access );
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
