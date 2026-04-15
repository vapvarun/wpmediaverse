<?php
/**
 * Template: Edit Profile.
 *
 * Standalone profile editor — works without BuddyPress.
 * Allows users to update first name, last name, display name, bio, and avatar.
 * Override by copying to your-theme/wpmediaverse/profile-edit.php
 *
 * @package WPMediaVerse
 * @since   1.1.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! is_user_logged_in() ) {
	get_header();
	echo '<div class="mvs-profile-edit"><p>';
	esc_html_e( 'Please log in to edit your profile.', 'wpmediaverse' );
	echo '</p></div>';
	get_footer();
	return;
}

$mvs_user       = wp_get_current_user();
$mvs_user_id    = $mvs_user->ID;
$mvs_avatar_url = get_avatar_url( $mvs_user_id, array( 'size' => 150 ) );

$mvs_container  = \WPMediaVerse\Core\Plugin::container();
$mvs_has_custom = false;
if ( $mvs_container->has( 'profile' ) ) {
	$mvs_profile_svc = $mvs_container->get( 'profile' );
	$mvs_has_custom  = $mvs_profile_svc->has_custom_avatar( $mvs_user_id );
}

$mvs_profile_ctx = array(
	'restUrl'         => esc_url_raw( rest_url( 'mvs/v1/' ) ),
	'nonce'           => wp_create_nonce( 'wp_rest' ),
	'userId'          => $mvs_user_id,
	'firstName'       => $mvs_user->first_name,
	'lastName'        => $mvs_user->last_name,
	'displayName'     => $mvs_user->display_name,
	'bio'             => $mvs_user->description,
	'avatarUrl'       => $mvs_avatar_url,
	'hasCustomAvatar' => $mvs_has_custom,
	'saving'          => false,
	'uploadingAvatar' => false,
	'savedMessage'    => '',
	'errorMessage'    => '',
);

get_header();

do_action( 'mvs_before_content' );

wp_enqueue_style( 'mvs-frontend' );

$mvs_profile_asset_file = MVS_PLUGIN_DIR . 'build/blocks/profile-edit/view.asset.php';
$mvs_profile_asset      = file_exists( $mvs_profile_asset_file )
	? require $mvs_profile_asset_file
	: array(
		'dependencies' => array(
			array(
				'id'     => '@wordpress/interactivity',
				'import' => 'static',
			),
		),
		'version'      => MVS_VERSION,
	);
wp_enqueue_script_module(
	'mvs-profile-edit',
	MVS_PLUGIN_URL . 'assets/js/profile-edit.js',
	$mvs_profile_asset['dependencies'],
	$mvs_profile_asset['version']
);
?>
<div class="mvs-profile-edit mvs-page"
	data-wp-interactive="mvs/profile-edit"
	<?php echo wp_interactivity_data_wp_context( $mvs_profile_ctx ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>

	<?php \WPMediaVerse\Core\TemplateHelpers::render_back_link( 'edit-profile', array( 'user_id' => $mvs_user_id ) ); ?>

	<h2><?php esc_html_e( 'Edit Profile', 'wpmediaverse' ); ?></h2>

	<!-- Success/Error Messages -->
	<div class="mvs-profile-message mvs-profile-message--success"
		data-wp-bind--hidden="!context.savedMessage"
		data-wp-text="context.savedMessage"></div>
	<div class="mvs-profile-message mvs-profile-message--error"
		data-wp-bind--hidden="!context.errorMessage"
		data-wp-text="context.errorMessage"></div>

	<!-- Avatar Section -->
	<div class="mvs-profile-avatar-section">
		<div class="mvs-profile-avatar-preview">
			<img data-wp-bind--src="context.avatarUrl"
				alt="<?php esc_attr_e( 'Your avatar', 'wpmediaverse' ); ?>"
				width="150" height="150" class="mvs-profile-avatar-img" />
		</div>
		<div class="mvs-profile-avatar-actions">
			<label class="mvs-btn mvs-btn--secondary mvs-profile-avatar-upload-label">
				<span data-wp-bind--hidden="context.uploadingAvatar"><?php esc_html_e( 'Change Avatar', 'wpmediaverse' ); ?></span>
				<span data-wp-bind--hidden="!context.uploadingAvatar"><?php esc_html_e( 'Uploading...', 'wpmediaverse' ); ?></span>
				<input type="file"
					accept="image/jpeg,image/png,image/gif,image/webp"
					class="mvs-profile-avatar-input"
					data-wp-on--change="actions.uploadAvatar" />
			</label>
			<button type="button"
				class="mvs-btn mvs-btn--text mvs-profile-avatar-remove"
				data-wp-bind--hidden="!context.hasCustomAvatar"
				data-wp-on--click="actions.deleteAvatar">
				<?php esc_html_e( 'Remove (use Gravatar)', 'wpmediaverse' ); ?>
			</button>
			<p class="mvs-profile-avatar-hint">
				<?php esc_html_e( 'JPEG, PNG, GIF, or WebP. Max 2 MB.', 'wpmediaverse' ); ?>
			</p>
		</div>
	</div>

	<!-- Profile Fields -->
	<form class="mvs-profile-form" data-wp-on--submit="actions.saveProfile">
		<div class="mvs-profile-field">
			<label for="mvs-first-name"><?php esc_html_e( 'First Name', 'wpmediaverse' ); ?></label>
			<input type="text" id="mvs-first-name"
				data-wp-bind--value="context.firstName"
				data-wp-on--input="actions.updateFirstName"
				autocomplete="given-name" />
		</div>

		<div class="mvs-profile-field">
			<label for="mvs-last-name"><?php esc_html_e( 'Last Name', 'wpmediaverse' ); ?></label>
			<input type="text" id="mvs-last-name"
				data-wp-bind--value="context.lastName"
				data-wp-on--input="actions.updateLastName"
				autocomplete="family-name" />
		</div>

		<div class="mvs-profile-field">
			<label for="mvs-display-name"><?php esc_html_e( 'Display Name', 'wpmediaverse' ); ?></label>
			<input type="text" id="mvs-display-name"
				data-wp-bind--value="context.displayName"
				data-wp-on--input="actions.updateDisplayName"
				autocomplete="nickname" />
		</div>

		<div class="mvs-profile-field">
			<label for="mvs-bio"><?php esc_html_e( 'Bio', 'wpmediaverse' ); ?></label>
			<textarea id="mvs-bio" rows="4" maxlength="500"
				data-wp-on--input="actions.updateBio"
				data-wp-text="context.bio"></textarea>
		</div>

		<div class="mvs-profile-actions">
			<button type="submit" class="mvs-btn mvs-btn--primary"
				data-wp-bind--disabled="context.saving">
				<span data-wp-bind--hidden="context.saving"><?php esc_html_e( 'Save Changes', 'wpmediaverse' ); ?></span>
				<span data-wp-bind--hidden="!context.saving"><?php esc_html_e( 'Saving...', 'wpmediaverse' ); ?></span>
			</button>
		</div>
	</form>
</div>
<?php
do_action( 'mvs_after_content' );

get_footer();
