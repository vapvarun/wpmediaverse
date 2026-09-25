<?php
/**
 * "Your name is edited on your community profile" - shared by every profile form.
 *
 * Printed only when a community plugin owns some profile fields (see
 * ProfileService::community_profile()); those fields are hidden in the form,
 * so this says where they went instead of leaving the member to wonder.
 *
 * Expects: $mvs_community - the array community_profile() returns.
 *
 * @package WPMediaVerse
 * @since   2.6.0
 */

defined( 'ABSPATH' ) || exit;

// Nothing deferred means the form edits every field itself - no notice.
if ( ! empty( $mvs_community['url'] ) ) :
	?>
<p class="mvs-profile-message">
	<?php esc_html_e( 'Your name is edited on your community profile.', 'wpmediaverse' ); ?>
	<a class="mvs-btn mvs-btn--secondary mvs-btn--small" href="<?php echo esc_url( $mvs_community['url'] ); ?>">
		<?php echo esc_html( $mvs_community['label'] ); ?>
	</a>
</p>
	<?php
endif;
