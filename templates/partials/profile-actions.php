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

// Messaging enabled? The site-wide `mvs_dm_access` option is a ceiling — see
// Plugin::resolve_privacy_ceiling(). Mirrors the exact precedence used by
// MessagingService::can_message() so the "Message" button's visibility
// always matches what a send attempt will actually be allowed to do
// (Basecamp #10053143680).
$mvs_dm_access    = \WPMediaVerse\Core\Plugin::resolve_privacy_ceiling(
	get_option( 'mvs_dm_access', 'everyone' ),
	get_user_meta( $mvs_profile_id, '_mvs_dm_access', true ),
	array( 'everyone', 'followers', 'mutual', 'nobody' )
);
$mvs_messaging_on = ( 'nobody' !== $mvs_dm_access );

// Member reporting is a Pro feature — POST /users/{id}/report 403s in Free unless
// this filter is true. Only surface the Report action when it will actually work.
$mvs_reports_on     = (bool) apply_filters( 'mvs_reports_enabled', false );
$mvs_report_reasons = array(
	'spam'           => __( 'Spam', 'wpmediaverse' ),
	'harassment'     => __( 'Harassment or bullying', 'wpmediaverse' ),
	'nudity'         => __( 'Nudity or sexual content', 'wpmediaverse' ),
	'violence'       => __( 'Violence', 'wpmediaverse' ),
	'copyright'      => __( 'Copyright infringement', 'wpmediaverse' ),
	'misinformation' => __( 'Misinformation', 'wpmediaverse' ),
	'other'          => __( 'Other', 'wpmediaverse' ),
);
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
<span class="mvs-actions-overflow">
	<button type="button" class="mvs-btn mvs-btn--text mvs-btn--small mvs-actions-more"
		aria-haspopup="true" aria-expanded="false"
		aria-label="<?php esc_attr_e( 'More actions', 'wpmediaverse' ); ?>">&#8943;</button>
	<div class="mvs-actions-menu" hidden role="menu">
		<?php if ( $mvs_reports_on ) : ?>
			<button type="button" class="mvs-actions-menu__item mvs-report-trigger" role="menuitem">
				<?php esc_html_e( 'Report', 'wpmediaverse' ); ?>
			</button>
		<?php endif; ?>
		<button type="button" class="mvs-actions-menu__item mvs-block-toggle<?php echo $mvs_is_blocked ? ' mvs-block-toggle--blocked' : ''; ?>"
			role="menuitem"
			data-user-id="<?php echo esc_attr( $mvs_profile_id ); ?>"
			data-blocked="<?php echo $mvs_is_blocked ? '1' : '0'; ?>"
			data-rest-url="<?php echo esc_url( rest_url( 'mvs/v1/' ) ); ?>"
			aria-label="<?php echo $mvs_is_blocked ? esc_attr__( 'Unblock this member', 'wpmediaverse' ) : esc_attr__( 'Block this member', 'wpmediaverse' ); ?>">
			<?php echo $mvs_is_blocked ? esc_html__( 'Unblock', 'wpmediaverse' ) : esc_html__( 'Block', 'wpmediaverse' ); ?>
		</button>
	</div>
</span>
<?php if ( $mvs_reports_on ) : ?>
	<div class="mvs-modal-overlay mvs-report-modal" hidden role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'Report this member', 'wpmediaverse' ); ?>"
		data-user-id="<?php echo esc_attr( $mvs_profile_id ); ?>"
		data-rest-url="<?php echo esc_url( rest_url( 'mvs/v1/' ) ); ?>">
		<div class="mvs-modal">
			<div class="mvs-modal-header">
				<h3 class="mvs-modal-title"><?php esc_html_e( 'Report this member', 'wpmediaverse' ); ?></h3>
				<button type="button" class="mvs-modal-close mvs-report-cancel" aria-label="<?php esc_attr_e( 'Close', 'wpmediaverse' ); ?>">&times;</button>
			</div>
			<div class="mvs-modal-body">
				<div class="mvs-report-form">
					<div class="mvs-modal-field">
						<label for="mvs-report-reason-<?php echo esc_attr( $mvs_profile_id ); ?>"><?php esc_html_e( 'Reason', 'wpmediaverse' ); ?></label>
						<select id="mvs-report-reason-<?php echo esc_attr( $mvs_profile_id ); ?>" class="mvs-report-reason">
							<?php foreach ( $mvs_report_reasons as $mvs_r_key => $mvs_r_label ) : ?>
								<option value="<?php echo esc_attr( $mvs_r_key ); ?>"><?php echo esc_html( $mvs_r_label ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="mvs-modal-field">
						<textarea class="mvs-report-details" rows="3" placeholder="<?php esc_attr_e( 'Add details (optional)', 'wpmediaverse' ); ?>"></textarea>
					</div>
				</div>
				<p class="mvs-report-done" hidden><?php esc_html_e( 'Thanks. This member has been reported to the moderators.', 'wpmediaverse' ); ?></p>
			</div>
			<div class="mvs-modal-footer">
				<button type="button" class="mvs-btn mvs-btn--secondary mvs-report-cancel"><?php esc_html_e( 'Cancel', 'wpmediaverse' ); ?></button>
				<button type="button" class="mvs-btn mvs-btn--primary mvs-report-submit"><?php esc_html_e( 'Report', 'wpmediaverse' ); ?></button>
			</div>
		</div>
	</div>
<?php endif; ?>
