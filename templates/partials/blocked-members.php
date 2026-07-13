<?php
/**
 * Blocked members — see and undo your blocks.
 *
 * Shared by the standalone profile-edit page and the member dashboard. It used
 * to live only in templates/profile-edit.php, which is reachable solely through
 * the [mvs_profile_edit] shortcode — and the plugin never creates a page for it
 * (activation only creates Explore, My Media and Upload). So on a stock install
 * a member could block someone and then had no way to see or undo it. Blocking
 * without unblocking is a trap, and a blocked-list is also what App Store
 * guideline 1.2 expects a UGC app to provide.
 *
 * Must render inside a `mvs/profile-edit` interactive region — that store owns
 * actions.unblockMember.
 *
 * Server-rendered (display + remove only, never add-reactive) so SSR matches
 * hydration under any theme.
 *
 * @package WPMediaVerse
 */

defined( 'ABSPATH' ) || exit;

$mvs_bm_user_id = get_current_user_id();

if ( $mvs_bm_user_id <= 0 ) {
	return;
}

$mvs_bm_container = \WPMediaVerse\Core\Plugin::container();

if ( ! $mvs_bm_container->has( 'reports' ) ) {
	return;
}

/** @var \WPMediaVerse\Social\ReportService $mvs_bm_reports */
$mvs_bm_reports = $mvs_bm_container->get( 'reports' );

$mvs_bm_blocked = array();

foreach ( $mvs_bm_reports->get_blocked_ids( $mvs_bm_user_id ) as $mvs_bm_id ) {
	$mvs_bm_who = get_userdata( $mvs_bm_id );

	if ( $mvs_bm_who ) {
		$mvs_bm_blocked[] = array(
			'id'     => (int) $mvs_bm_id,
			'name'   => $mvs_bm_who->display_name,
			'avatar' => get_avatar_url( $mvs_bm_id, array( 'size' => 48 ) ),
		);
	}
}
?>
<section class="mvs-profile-edit__section mvs-blocked-members">
	<h2 class="mvs-blocked-members__title"><?php esc_html_e( 'Blocked members', 'wpmediaverse' ); ?></h2>
	<p class="mvs-blocked-members__empty"<?php echo empty( $mvs_bm_blocked ) ? '' : ' hidden'; ?>>
		<?php esc_html_e( "You haven't blocked anyone.", 'wpmediaverse' ); ?>
	</p>
	<ul class="mvs-blocked-members__list"<?php echo empty( $mvs_bm_blocked ) ? ' hidden' : ''; ?>>
		<?php foreach ( $mvs_bm_blocked as $mvs_bm_row ) : ?>
			<li class="mvs-blocked-members__row">
				<img class="mvs-blocked-members__avatar" src="<?php echo esc_url( $mvs_bm_row['avatar'] ); ?>" alt="" width="40" height="40" loading="lazy" />
				<span class="mvs-blocked-members__name"><?php echo esc_html( $mvs_bm_row['name'] ); ?></span>
				<button type="button" class="mvs-btn mvs-btn--secondary mvs-btn--sm mvs-blocked-members__unblock"
					data-wp-on--click="actions.unblockMember"
					data-id="<?php echo esc_attr( (string) $mvs_bm_row['id'] ); ?>">
					<?php esc_html_e( 'Unblock', 'wpmediaverse' ); ?>
				</button>
			</li>
		<?php endforeach; ?>
	</ul>
</section>
