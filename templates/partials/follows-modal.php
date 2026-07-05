<?php
/**
 * Partial: Followers / Following modal shell.
 *
 * Rendered once per page (guarded by $GLOBALS['mvs_follows_modal_done']). Any
 * surface with clickable follower/following counts (.mvs-follows-open carrying
 * data-user-id + data-list) shares this single modal. Reuses the .mvs-modal
 * component; rows + follow-back are filled by assets/js/frontend/member-follows.js.
 *
 * @package WPMediaVerse
 */

defined( 'ABSPATH' ) || exit;

if ( ! empty( $GLOBALS['mvs_follows_modal_done'] ) ) {
	return;
}
$GLOBALS['mvs_follows_modal_done'] = true;

wp_enqueue_script(
	'mvs-member-follows',
	MVS_PLUGIN_URL . 'assets/js/frontend/member-follows.js',
	array( 'mvs-rest' ),
	MVS_VERSION,
	true
);
?>
<div class="mvs-modal-overlay mvs-follows-modal" hidden role="dialog" aria-modal="true"
	aria-label="<?php esc_attr_e( 'Members', 'wpmediaverse' ); ?>"
	data-rest-url="<?php echo esc_url( rest_url( 'mvs/v1/' ) ); ?>"
	data-self-id="<?php echo esc_attr( get_current_user_id() ); ?>"
	data-user-id="">
	<div class="mvs-modal">
		<div class="mvs-modal-header">
			<div class="mvs-follows-tabs" role="tablist">
				<button type="button" class="mvs-follows-tab is-active" data-list="followers"><?php esc_html_e( 'Followers', 'wpmediaverse' ); ?></button>
				<button type="button" class="mvs-follows-tab" data-list="following"><?php esc_html_e( 'Following', 'wpmediaverse' ); ?></button>
			</div>
			<button type="button" class="mvs-modal-close mvs-follows-close" aria-label="<?php esc_attr_e( 'Close', 'wpmediaverse' ); ?>">&times;</button>
		</div>
		<div class="mvs-modal-body">
			<p class="mvs-follows-state mvs-follows-loading" hidden><?php esc_html_e( 'Loading…', 'wpmediaverse' ); ?></p>
			<p class="mvs-follows-state mvs-follows-empty" hidden><?php esc_html_e( 'No members to show.', 'wpmediaverse' ); ?></p>
			<ul class="mvs-follows-list"></ul>
		</div>
	</div>
</div>
