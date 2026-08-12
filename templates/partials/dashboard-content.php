<?php
/**
 * Partial: Dashboard content.
 *
 * Shared by templates/dashboard.php and Shortcodes\Shortcodes::render_dashboard().
 * Expects $mvs_dash_ctx (array) to be set before inclusion.
 *
 * @package WPMediaVerse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Fires before the dashboard content is rendered.
 *
 * Pro uses this to display the quota usage widget.
 *
 * @since 1.1.0
 */
do_action( 'mvs_dashboard_before_content' );

// Grid column count from the display setting, clamped to supported range.
$mvs_grid_cols = max( 2, min( 5, (int) get_option( 'mvs_grid_columns', 3 ) ) );

/*
 * Panel toolbar setup.
 *
 * The toolbar is rendered from the SAME helper the document drive uses, so the
 * five list surfaces stop being five different answers to "how do I find one of
 * these". Each panel's initial values come from the URL, which is what makes a
 * filtered view shareable: the server paints the toolbar in the state the link
 * asked for, and the client store reads the same keys on init.
 */
$mvs_tpl = \WPMediaVerse\Core\Plugin::container()->get( 'template_helpers' );

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view state, no write.
$mvs_toolbar_s       = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
$mvs_toolbar_orderby = isset( $_GET['sort'] ) ? sanitize_key( wp_unslash( $_GET['sort'] ) ) : '';
$mvs_toolbar_order   = ( isset( $_GET['order'] ) && 'asc' === strtolower( (string) wp_unslash( $_GET['order'] ) ) ) ? 'asc' : 'desc';
// phpcs:enable WordPress.Security.NonceVerification.Recommended

$mvs_order_options = array(
	'desc' => __( 'Newest first', 'wpmediaverse' ),
	'asc'  => __( 'Oldest first', 'wpmediaverse' ),
);

// Resolved here rather than reusing the copy made further down for the rail:
// the toolbars are rendered before that point, and capturing a variable that
// does not exist yet is a null comparison that silently matches nothing.
$mvs_toolbar_active = \WPMediaVerse\Core\DashboardSections::resolve(
	get_query_var( 'mvs_doc_view' )
		? 'documents'
		: (string) get_query_var( 'mvs_section', '' )
);

// Only the panel named in the URL adopts the query's toolbar state. Applying it
// to all four would mean opening Albums with a search you typed in Media.
$mvs_toolbar_state = static function ( string $slug, string $default_sort ) use ( $mvs_toolbar_active, $mvs_toolbar_s, $mvs_toolbar_orderby, $mvs_toolbar_order ) {
	$mine = ( $slug === $mvs_toolbar_active );

	return array(
		's'       => $mine ? $mvs_toolbar_s : '',
		'orderby' => ( $mine && '' !== $mvs_toolbar_orderby ) ? $mvs_toolbar_orderby : $default_sort,
		'order'   => $mine ? $mvs_toolbar_order : 'desc',
	);
};

$mvs_sort_options_media = array(
	'date'     => __( 'Date', 'wpmediaverse' ),
	'trending' => __( 'Trending', 'wpmediaverse' ),
	'popular'  => __( 'Popular', 'wpmediaverse' ),
);

$mvs_sort_options_albums      = array(
	'date'  => __( 'Date', 'wpmediaverse' ),
	'title' => __( 'Name', 'wpmediaverse' ),
);
$mvs_sort_options_collections = $mvs_sort_options_albums;

$mvs_sort_options_favorites = array(
	// "When you saved it" is a different question from "when it was made", and
	// for a favourites list the first one is what a member means.
	'favorited' => __( 'Recently added', 'wpmediaverse' ),
	'title'     => __( 'Name', 'wpmediaverse' ),
	'date'      => __( 'Date created', 'wpmediaverse' ),
);

// Profile data for the header.
$mvs_current_user = wp_get_current_user();
$mvs_avatar_url   = get_avatar_url( $mvs_current_user->ID, array( 'size' => 96 ) );
$mvs_has_custom   = false;
if ( isset( $mvs_container ) && $mvs_container->has( 'profile' ) ) {
	$mvs_has_custom = $mvs_container->get( 'profile' )->has_custom_avatar( $mvs_current_user->ID );
} elseif ( class_exists( '\WPMediaVerse\Core\Plugin' ) ) {
	$mvs_c = \WPMediaVerse\Core\Plugin::container();
	if ( $mvs_c->has( 'profile' ) ) {
		$mvs_has_custom = $mvs_c->get( 'profile' )->has_custom_avatar( $mvs_current_user->ID );
	}
}

// Merge profile context into dashboard context.
$mvs_dash_ctx['firstName']       = $mvs_current_user->first_name;
$mvs_dash_ctx['lastName']        = $mvs_current_user->last_name;
$mvs_dash_ctx['displayName']     = $mvs_current_user->display_name;
$mvs_dash_ctx['bio']             = $mvs_current_user->description;
$mvs_dash_ctx['avatarUrl']       = $mvs_avatar_url ?: '';
$mvs_dash_ctx['hasCustomAvatar'] = $mvs_has_custom;
$mvs_dash_ctx['editingProfile']  = false;
$mvs_dash_ctx['savingProfile']   = false;
$mvs_dash_ctx['uploadingAvatar'] = false;
$mvs_dash_ctx['profileMessage']  = '';
$mvs_dash_ctx['profileError']    = '';
$mvs_dash_ctx['defaultPrivacy']  = get_option( 'mvs_default_privacy', 'public' );

// Allowed file extensions for client-side upload validation.
$mvs_allowed_mimes = array_map( 'trim', explode( ',', get_option( 'mvs_allowed_file_types', 'image/jpeg,image/png,image/gif,image/webp,video/mp4,video/webm,audio/mpeg,audio/ogg' ) ) );

// NEVER offer what the server refuses outright. This list was echoed verbatim
// from the stored option, so every install predating 1.2.3 — where the option
// still carries `application/pdf` as legacy residue nobody chose — advertised
// PDF to every member and then refused all of them, with no admin remedy
// (Basecamp 10190738445). Subtracting here fixes the member-facing half whatever
// state the option is in, including a site that re-adds PDF through the filter.
$mvs_allowed_mimes = array_values( array_diff( $mvs_allowed_mimes, \WPMediaVerse\Services\UploadService::hard_refused_mimes() ) );
$mvs_mime_to_ext   = array(
	'image/jpeg'      => '.jpg,.jpeg',
	'image/png'       => '.png',
	'image/gif'       => '.gif',
	'image/webp'      => '.webp',
	'video/mp4'       => '.mp4',
	'video/webm'      => '.webm',
	'audio/mpeg'      => '.mp3',
	'audio/ogg'       => '.ogg',
	'application/pdf' => '.pdf',
);
$mvs_allowed_exts  = array();
foreach ( $mvs_allowed_mimes as $mvs_mime ) {
	if ( isset( $mvs_mime_to_ext[ $mvs_mime ] ) ) {
		$mvs_allowed_exts[] = $mvs_mime_to_ext[ $mvs_mime ];
	}
}
$mvs_dash_ctx['allowedExtensions'] = implode( ',', $mvs_allowed_exts );
$mvs_dash_ctx['allowedMimeTypes']  = implode( ',', $mvs_allowed_mimes );

// Enqueue profile edit store.
$mvs_pe_asset_file = MVS_PLUGIN_DIR . 'build/blocks/profile-edit/view.asset.php';
$mvs_pe_asset      = file_exists( $mvs_pe_asset_file )
	? require $mvs_pe_asset_file
	: array(
		'dependencies' => array(
			array(
				'id'     => '@wordpress/interactivity',
				'import' => 'static',
			),
		),
		'version'      => defined( 'MVS_VERSION' ) ? MVS_VERSION : '1.1.0',
	);
wp_enqueue_script_module(
	'mvs-profile-edit',
	( defined( 'MVS_PLUGIN_URL' ) ? MVS_PLUGIN_URL : '' ) . 'assets/js/profile-edit.js',
	$mvs_pe_asset['dependencies'],
	$mvs_pe_asset['version']
);

// Enqueue dashboard store (mvs/dashboard — handles media/albums/favorites/collections tabs).
wp_enqueue_script_module(
	'mvs-dashboard-view',
	( defined( 'MVS_PLUGIN_URL' ) ? MVS_PLUGIN_URL : '' ) . 'src/blocks/dashboard-view/view.js',
	array(
		array(
			'id'     => '@wordpress/interactivity',
			'import' => 'static',
		),
	),
	defined( 'MVS_VERSION' ) ? MVS_VERSION : '1.0.0'
);

// Enqueue frontend CSS.
wp_enqueue_style( 'mvs-frontend' );

// i18n for the mvs/dashboard store. It is a script MODULE (viewScriptModule), so
// wp_set_script_translations() can't reach it and window.wp.i18n.__() falls
// through to English. Seed PHP-translated strings into interactivity state; the
// store reads state.i18n.<key> with an English fallback. Basecamp 10073528834.
wp_interactivity_state(
	'mvs/dashboard',
	array(
		// Which tab the URL asked for. Seeded server-side so that landing on
		// /my-media/documents/ paints the right panel on first render rather
		// than flashing Media and correcting itself once the module loads.
		'activeTab' => get_query_var( 'mvs_doc_view' )
			? 'documents'
			: ( get_query_var( 'mvs_section' ) ? (string) get_query_var( 'mvs_section' ) : 'media' ),
		'i18n'      => array(
			// Rule-builder select options + placeholders.
			'selectOption'            => __( '-- Select --', 'wpmediaverse' ),
			'optImage'                => __( 'Image', 'wpmediaverse' ),
			'optVideo'                => __( 'Video', 'wpmediaverse' ),
			'optAudio'                => __( 'Audio', 'wpmediaverse' ),
			'optDocument'             => __( 'Document', 'wpmediaverse' ),
			'optPublic'               => __( 'Public', 'wpmediaverse' ),
			'optMembers'              => __( 'Members', 'wpmediaverse' ),
			'optPrivate'              => __( 'Private', 'wpmediaverse' ),
			'ruleUserIdPlaceholder'   => __( 'User ID', 'wpmediaverse' ),
			'ruleDatePlaceholder'     => __( 'YYYY-MM-DD', 'wpmediaverse' ),
			'ruleValuePlaceholder'    => __( 'Value', 'wpmediaverse' ),
			// List item labels.
			'untitled'                => __( '(Untitled)', 'wpmediaverse' ),
			/* translators: %d: number of items. */
			'itemsCount'              => __( '%d items', 'wpmediaverse' ),
			// The singular, seeded separately because this panel's JS is a
			// script MODULE and cannot reach `wp.i18n._n()`. Every count here
			// read "1 items" until the toolbar started printing them where a
			// member actually looks. A one-vs-many split is what this seam
			// allows; locales with more than two plural forms are still served
			// approximately, and fixing that properly means moving the count
			// server-side, not adding a third string here.
			'itemCount'               => __( '%d item', 'wpmediaverse' ),
			// Upload flow.
			/* translators: 1: rejected file names, 2: supported extensions. */
			'fileTypeNotAllowed'      => __( 'File type not allowed: %1$s. Supported: %2$s', 'wpmediaverse' ),
			/* translators: 1: current file number, 2: total files. */
			'uploadingProgress'       => __( 'Uploading %1$d of %2$d...', 'wpmediaverse' ),
			'uploadFailed'            => __( 'Upload failed.', 'wpmediaverse' ),
			'uploadFailedRetry'       => __( 'Upload failed. Please try again.', 'wpmediaverse' ),
			/* translators: 1: uploaded count, 2: total files. */
			'filesUploadedPartial'    => __( '%1$d of %2$d file(s) uploaded.', 'wpmediaverse' ),
			/* translators: %d: number of files uploaded. */
			'filesUploaded'           => __( '%d file(s) uploaded!', 'wpmediaverse' ),
			'fileReplaced'            => __( 'File replaced!', 'wpmediaverse' ),
			'replaceFailed'           => __( 'Replace failed.', 'wpmediaverse' ),
			// Edit media.
			'mediaUpdated'            => __( 'Media updated!', 'wpmediaverse' ),
			'updateFailed'            => __( 'Update failed.', 'wpmediaverse' ),
			'confirmDeleteMedia'      => __( 'Delete this media item? This cannot be undone.', 'wpmediaverse' ),
			'mediaDeleted'            => __( 'Media deleted.', 'wpmediaverse' ),
			'deleteFailed'            => __( 'Delete failed.', 'wpmediaverse' ),
			'saveFailed'              => __( 'Save failed.', 'wpmediaverse' ),
			// Albums.
			'albumUpdated'            => __( 'Album updated!', 'wpmediaverse' ),
			'albumCreated'            => __( 'Album created!', 'wpmediaverse' ),
			'confirmDeleteAlbum'      => __( 'Delete this album? Media items will not be deleted.', 'wpmediaverse' ),
			'albumDeleted'            => __( 'Album deleted.', 'wpmediaverse' ),
			// Favorites.
			'removedFromFavorites'    => __( 'Removed from favorites.', 'wpmediaverse' ),
			// Collections.
			'collectionUpdated'       => __( 'Collection updated!', 'wpmediaverse' ),
			'collectionCreated'       => __( 'Collection created!', 'wpmediaverse' ),
			'confirmDeleteCollection' => __( 'Delete this collection? Media items will not be deleted.', 'wpmediaverse' ),
			'collectionDeleted'       => __( 'Collection deleted.', 'wpmediaverse' ),
			// Notifications.
			'allNotificationsRead'    => __( 'All notifications marked as read.', 'wpmediaverse' ),
		),
	)
);
?>
<div class="mvs-dashboard"
	data-wp-interactive="mvs/dashboard"
	<?php echo wp_interactivity_data_wp_context( $mvs_dash_ctx ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_interactivity_data_wp_context() encodes + escapes the JSON payload itself. ?>
	data-wp-init="callbacks.init">


	<?php
	// Profile completion prompt (if no avatar or empty bio).
	//
	// "Has avatar" means either a plugin-uploaded custom avatar OR a Gravatar
	// keyed off the user's email. `get_avatar_data()` returns `found_avatar`
	// true only when Gravatar returned an actual image (not the mystery-man
	// placeholder), so the banner correctly stays hidden for users whose
	// Gravatar is set.
	$mvs_avatar_data        = get_avatar_data( $mvs_current_user->ID );
	$mvs_has_gravatar       = ! empty( $mvs_avatar_data['found_avatar'] );
	$mvs_has_any_avatar     = $mvs_has_custom || $mvs_has_gravatar;
	$mvs_profile_incomplete = ! $mvs_has_any_avatar || empty( $mvs_current_user->description );

	// DISMISSED IS ASKED SERVER-SIDE. This used to render regardless and let
	// `dismissible.js` remove it after reading localStorage, which meant every
	// dashboard load painted a 70px banner and then collapsed it — the largest
	// layout shift on the page, and the reason it felt jumpy. The flag is user
	// meta now, so a member who closed it never sees it again on any device.
	$mvs_prompt_dismissed = (bool) get_user_meta( $mvs_current_user->ID, '_mvs_profile_prompt_dismissed', true );

	if ( $mvs_profile_incomplete && ! $mvs_prompt_dismissed ) :
		?>
	<div class="mvs-profile-prompt" id="mvs-profile-prompt">
		<span class="mvs-profile-prompt-icon">&#x1F464;</span>
		<span class="mvs-profile-prompt-text">
			<?php esc_html_e( 'Complete your profile. Add an avatar and bio to help others find you.', 'wpmediaverse' ); ?>
			<button class="mvs-btn mvs-btn--secondary mvs-btn--small mvs-dashboard-profile-edit-btn"
				type="button"
				data-wp-on--click="actions.toggleProfileEdit">
				<?php esc_html_e( 'Edit Profile', 'wpmediaverse' ); ?>
			</button>
		</span>
		<button type="button" class="mvs-profile-prompt-close" id="mvs-profile-prompt-close"
			aria-label="<?php esc_attr_e( 'Dismiss', 'wpmediaverse' ); ?>">&times;</button>
	</div>
		<?php
		// @deprecated 2.3.0 Not the enqueue site any more — Core\Plugin::enqueue_frontend_assets()
		// enqueues this handle for every MVS-owned page. Enqueuing from a template body only
		// ever worked on a hard page load: the <script> tag prints in wp_footer, OUTSIDE
		// [data-wp-router-region="mvs/main"], so a client-side navigation swapped in the markup
		// without ever delivering the script (Basecamp #10148246386, #10134243697). Left as an
		// idempotent no-op because themes may override this template — Production Rule #5.
		?>
		<?php wp_enqueue_script( 'mvs-dismissible' ); ?>
	<?php endif; ?>

	<?php
	// Show the MVS dashboard bell ONLY when BP is not active.
	//
	// When BP is active, every MVS event is also injected into
	// bp_notifications (see Integrations/BuddyPress/NotificationIntegration)
	// so BP's nav bell is already showing them globally — including on this
	// dashboard page. Rendering a second bell here would surface the same
	// items twice. The MVS bell stays as the canonical surface for
	// non-BP sites where there is no other notification chrome.
	$mvs_show_dashboard_bell = ! function_exists( 'buddypress' );
	?>
	<?php
	// THE PAGE ALREADY SAYS "My Media" — it is the h1 in the page band, 32px,
	// 190px above where this sat and repeated it at 15px. A second copy tells a
	// reader nothing they did not just read, and a screen reader has to hear it
	// twice. It was never a heading either: a bare <span>, so it labelled
	// nothing and carried no outline position.
	//
	// The row survives only for the notification bell. With no bell there is
	// nothing in it, so it is not rendered — an empty flex row still occupies
	// its gap.
	?>
	<?php if ( $mvs_show_dashboard_bell ) : ?>
	<div class="mvs-dashboard-header">
		<?php if ( $mvs_show_dashboard_bell ) : ?>
		<div class="mvs-notification-bell" data-wp-on--click="actions.toggleNotifications"
			role="button" tabindex="0" aria-label="<?php esc_attr_e( 'Notifications', 'wpmediaverse' ); ?>">
			<span class="mvs-notification-bell-icon">&#128276;</span>
			<span class="mvs-notification-badge" data-wp-bind--hidden="!state.notifications.count"
				data-wp-text="state.notifications.count"></span>
			<div class="mvs-notification-dropdown" data-wp-bind--hidden="!state.notifications.visible"
				data-wp-on--click="actions.stopPropagation">
				<div class="mvs-notification-dropdown-header">
					<strong><?php esc_html_e( 'Notifications', 'wpmediaverse' ); ?></strong>
					<button class="mvs-btn mvs-btn--small mvs-btn--secondary" type="button"
						data-wp-on--click="actions.markAllRead"
						data-wp-bind--hidden="!state.notifications.count"><?php esc_html_e( 'Mark all read', 'wpmediaverse' ); ?></button>
				</div>
				<ul class="mvs-notification-list">
					<template data-wp-each="state.notifications.items">
						<li class="mvs-notification-item" data-wp-class--mvs-notification-unread="!context.item.read">
							<a class="mvs-notification-link" data-wp-bind--href="context.item.url"
								data-wp-on--click="actions.markNotificationRead">
								<span data-wp-text="context.item.message"></span>
								<span class="mvs-notification-time" data-wp-text="context.item.date"></span>
							</a>
						</li>
					</template>
				</ul>
				<p data-wp-bind--hidden="state.hasNotifications" class="mvs-notification-empty">
					<?php esc_html_e( 'No notifications yet.', 'wpmediaverse' ); ?>
				</p>
			</div>
		</div>
		<?php endif; ?>
	</div>
	<?php endif; ?>

	<?php
	/**
	 * A section's URL. Every rail item is a real link, so a section can be
	 * shared, bookmarked and opened in a new tab; the click handler still
	 * switches client-side and writes the URL with pushState.
	 *
	 * @param string $section Section slug.
	 * @return string
	 */
	$mvs_dash_section_url = static function ( string $section ): string {
		$page = (int) get_option( 'mvs_page_dashboard', 0 );
		$base = $page ? (string) get_permalink( $page ) : home_url( '/' );

		if ( ! get_option( 'permalink_structure' ) ) {
			return add_query_arg( 'documents' === $section ? array( 'mvs_doc_view' => 1 ) : array( 'mvs_section' => $section ), $base );
		}

		return trailingslashit( $base ) . $section . '/';
	};

	$mvs_dash_active = \WPMediaVerse\Core\DashboardSections::resolve(
		get_query_var( 'mvs_doc_view' )
			? 'documents'
			: (string) get_query_var( 'mvs_section', '' )
	);

	// PANELS START HIDDEN EXCEPT THE ACTIVE ONE, in the HTML itself.
	//
	// Visibility was expressed only as `data-wp-bind--hidden`, which the
	// Interactivity runtime applies after it hydrates. Before that moment every
	// panel is visible, so a fresh page load painted the upload form, the media
	// grid, albums, favourites and collections stacked on top of each other and
	// then collapsed them a beat later.
	//
	// It was invisible while the dashboard only ever switched panels
	// client-side — there was no second paint to see it in. The Documents
	// section is a real page load now, which is what surfaced it, but the flash
	// was always there for anyone arriving from a bookmark, a shared link or a
	// refresh.
	//
	// The binding stays: it is what makes switching work after hydration. This
	// only decides the state the markup is BORN in.
	$mvs_dash_panel_hidden = static function ( string $mvs_dash_slug ) use ( $mvs_dash_active ): string {
		return $mvs_dash_slug === $mvs_dash_active ? '' : ' hidden';
	};

	// Is there anything to put in the documents panel?
	//
	// `$mvs_dash_drive` was READ below and never assigned — anywhere. An
	// undefined variable is null, `'' !== null` is true, so the gate always
	// opened and emitted a PHP warning on every dashboard load while doing it.
	//
	// The other half of the contract was already built: the drive filter answers
	// a `probe` request with a marker instead of the whole drive, precisely so
	// this question can be asked without rendering the answer twice. Nothing
	// asked it, so that branch was unreachable too. This asks.
	$mvs_dash_drive = (string) apply_filters(
		'mvs_documents_drive_html',
		'',
		'my-drive',
		array( 'probe' => true )
	);
	?>
	<?php
	// The rail and the panels share a grid; everything else on the page — the
	// streak bar, the profile header, the completion notice, the modals — must
	// stay OUTSIDE it. Gridding `.mvs-dashboard` itself put all of them into
	// the rail column beside the nav.
	?>
	<div class="mvs-dashboard__body">

	<?php
	// The identity, at the head of the rail.
	//
	// It was a full-width card above the tabs: a 64px avatar, the name, the bio
	// and two buttons, costing every member around 110px of vertical space
	// before the library they came for started — on a phone, most of the first
	// screen. In the rail it is a line, and it is beside the sections it
	// belongs to rather than stacked on top of them.
	//
	// NOT a tablist child: `role="tablist"` means its children are tabs, and an
	// avatar and a link out to the community profile are not tabs. It sits
	// before the nav, in the same rail column.
	$mvs_dash_profile_url = \WPMediaVerse\Core\Plugin::container()->get( 'template_helpers' )->get_user_profile_url( (int) $mvs_current_user->ID );
	?>
	<div class="mvs-dashboard-rail-head">
		<?php if ( $mvs_dash_profile_url ) : ?>
			<a class="mvs-dashboard-rail-head__avatar-link" href="<?php echo esc_url( $mvs_dash_profile_url ); ?>">
				<img class="mvs-dashboard-rail-head__avatar" data-wp-bind--src="context.avatarUrl"
					alt="" data-wp-bind--alt="context.displayName" width="40" height="40" />
			</a>
		<?php else : ?>
			<img class="mvs-dashboard-rail-head__avatar" data-wp-bind--src="context.avatarUrl"
				alt="" data-wp-bind--alt="context.displayName" width="40" height="40" />
		<?php endif; ?>

		<div class="mvs-dashboard-rail-head__id">
			<span class="mvs-dashboard-rail-head__name" data-wp-text="context.displayName"></span>
			<?php if ( $mvs_dash_profile_url ) : ?>
				<a class="mvs-dashboard-rail-head__link" href="<?php echo esc_url( $mvs_dash_profile_url ); ?>">
					<?php esc_html_e( 'View profile', 'wpmediaverse' ); ?>
				</a>
			<?php endif; ?>
		</div>
	</div>

	<nav class="mvs-dashboard-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Your library', 'wpmediaverse' ); ?>">
		<?php
		// Rendered from the SECTION REGISTRY, not from hardcoded markup. Eight
		// sections built eight ways is eight things to keep in step — which is
		// how Documents ended up gated correctly while the competition tabs were
		// still switching by hash. A declaration can be rendered one way.
		$mvs_dash_groups = \WPMediaVerse\Core\DashboardSections::grouped();
		$mvs_dash_labels = array(
			'library' => __( 'Library', 'wpmediaverse' ),
			'compete' => __( 'Compete', 'wpmediaverse' ),
			'account' => __( 'Account', 'wpmediaverse' ),
		);

		$mvs_dash_first_group = true;

		foreach ( $mvs_dash_groups as $mvs_dash_group => $mvs_dash_sections ) {
			// A heading earns its place over two or more items; over one it is a
			// label repeating itself.
			$mvs_dash_titled = count( $mvs_dash_sections ) > 1 && isset( $mvs_dash_labels[ $mvs_dash_group ] );

			if ( $mvs_dash_titled ) {
				printf(
					'<span class="mvs-dashboard-tabs__group">%s</span>',
					esc_html( $mvs_dash_labels[ $mvs_dash_group ] )
				);
			}

			// A one-item group gets no heading, and without one it reads as the
			// last item of the group above it — "Edit profile" looked like a
			// competition. The break is drawn instead of named: the grouping is
			// real, it just does not need a word.
			$mvs_dash_break = ! $mvs_dash_titled && ! $mvs_dash_first_group;

			$mvs_dash_first_group = false;
			$mvs_dash_item        = 0;

			foreach ( $mvs_dash_sections as $mvs_dash_slug => $mvs_dash_section ) {
				$mvs_dash_starts_group = $mvs_dash_break && 0 === $mvs_dash_item;

				++$mvs_dash_item;
				$mvs_dash_count = \WPMediaVerse\Core\DashboardSections::count( $mvs_dash_slug );

				// The client store exposes is<Slug>Tab getters for the sections it
				// knows. One it does not know still highlights server-side; it
				// simply will not re-highlight without a page load.
				$mvs_dash_binding = 'state.is' . ucfirst( $mvs_dash_slug ) . 'Tab';
				?>
				<a class="mvs-dashboard-tab<?php echo $mvs_dash_slug === $mvs_dash_active ? ' active' : ''; ?><?php echo $mvs_dash_starts_group ? ' mvs-dashboard-tab--group-start' : ''; ?>"
					data-tab="<?php echo esc_attr( $mvs_dash_slug ); ?>"
					role="tab"
					href="<?php echo esc_url( \WPMediaVerse\Core\DashboardSections::url( $mvs_dash_slug ) ); ?>"
					data-wp-class--active="<?php echo esc_attr( $mvs_dash_binding ); ?>"
					data-wp-on--click="actions.switchTab">
					<span class="mvs-dashboard-tab__label"><?php echo esc_html( $mvs_dash_section['label'] ); ?></span>
					<?php if ( null !== $mvs_dash_count ) : ?>
						<?php // Null and zero are different answers: null is "does not count itself". ?>
						<span class="mvs-dashboard-tab__count"><?php echo esc_html( number_format_i18n( $mvs_dash_count ) ); ?></span>
					<?php endif; ?>
				</a>
				<?php
			}
		}

		/**
		 * Fires after the last dashboard tab button.
		 *
		 * @deprecated 2.4.0 Declare a section through `mvs_dashboard_sections`
		 *                   instead. An action that echoes markup cannot be given
		 *                   a shape by the page that hosts it — which is how three
		 *                   competition tabs stayed hash-switching buttons after
		 *                   every other item became a link. Still fired; nothing
		 *                   is removed in a minor.
		 *
		 * @since 1.1.0
		 */
		do_action( 'mvs_dashboard_tabs' );
		?>
	</nav>

	<?php
	// Profile edit, as a panel among panels. Same markup as the card carried,
	// at a new address — see the partial's header.
	require MVS_PLUGIN_DIR . 'templates/partials/profile-edit-panel.php';
	?>

	<?php
	// RENDERED ONLY WHEN ASKED FOR. The drive is server-rendered — folders,
	// rows, per-row controls and its own pagination — while every other panel
	// fetches its contents over REST when its tab is first opened. So the drive
	// was the one panel paying its full cost on every section: measured at 27
	// `mvs_media_index` reads and 26 `mvs_access_grants` reads on
	// `/my-media/albums/` and `/my-media/profile/` alike, for a member with 46
	// documents. A member with none paid 2 and 0 — the cost scales with their
	// drive, on pages that are not their drive.
	//
	// The panel is a real page now rather than a hidden div, which is what it
	// already behaved like: its folder path and page number live in the URL.
	// `view.js` therefore lets the Documents tab navigate instead of swapping
	// client-side — see the comment there.
	?>
	<?php if ( '' !== $mvs_dash_drive && 'documents' === $mvs_dash_active ) : ?>
		<!-- Documents Panel -->
		<div class="mvs-dashboard-panel" role="tabpanel" data-wp-bind--hidden="!state.isDocumentsTab"<?php echo esc_attr( $mvs_dash_panel_hidden( 'documents' ) ); ?>>
			<?php
			// The drive, rendered server-side into the panel: folders, upload,
			// filters and the per-row controls, on the same screen.
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the filter contract requires escaped markup.
			// The path comes from the URL — `/my-media/documents/contracts/2026/`
			// — not from a folder id in a query string. Pro turns the slug path
			// into a folder, scoped to this member's own drive, so one member's
			// `/2026/` can never resolve to another's.
			echo apply_filters(
				'mvs_documents_drive_html',
				'',
				'my-drive',
				array(
					// Free owns the page, so Free says where the drive lives.
					'base' => (string) get_permalink( (int) get_option( 'mvs_page_dashboard', 0 ) ),
					'path' => (string) get_query_var( 'mvs_doc_path', '' ),
					'page' => max( 1, (int) get_query_var( 'mvs_doc_page', 1 ) ),
				)
			);
			?>
		</div>
	<?php endif; ?>

	<!-- My Media Panel -->
	<div class="mvs-dashboard-panel" role="tabpanel" data-wp-bind--hidden="!state.isMediaTab"<?php echo esc_attr( $mvs_dash_panel_hidden( 'media' ) ); ?>>
		<!-- Upload Section -->
		<div class="mvs-dashboard-upload">
			<div class="mvs-dashboard-dropzone"
				data-wp-class--mvs-drag-active="state.upload.dragOver"
				data-wp-on--click="actions.handleUploadClick"
				data-wp-on--dragover="actions.handleUploadDragOver"
				data-wp-on--dragleave="actions.handleUploadDragLeave"
				data-wp-on--drop="actions.handleUploadDrop"
				role="button" tabindex="0"
				aria-label="<?php esc_attr_e( 'Upload media files', 'wpmediaverse' ); ?>">
				<span class="mvs-dashboard-dropzone-icon">&#x2B06;&#xFE0F;</span>
				<span class="mvs-dashboard-dropzone-label"><?php esc_html_e( 'Drop files here or click to upload', 'wpmediaverse' ); ?></span>
				<input type="file" multiple accept="<?php echo esc_attr( $mvs_dash_ctx['allowedMimeTypes'] ); ?>" class="mvs-upload-file-input" style="display:none"
					data-wp-on--change="actions.handleUploadFileSelect" />
			</div>
			<button class="mvs-btn mvs-btn--small mvs-btn--secondary" type="button"
				data-wp-on--click="actions.toggleUploadFields">
				<span data-wp-bind--hidden="state.upload.showFields"><?php esc_html_e( 'Add title, tags & privacy', 'wpmediaverse' ); ?></span>
				<span data-wp-bind--hidden="!state.upload.showFields"><?php esc_html_e( 'Hide fields', 'wpmediaverse' ); ?></span>
			</button>
			<div class="mvs-dashboard-upload-fields" data-wp-bind--hidden="!state.upload.showFields">
				<input type="text" placeholder="<?php esc_attr_e( 'Title (optional)', 'wpmediaverse' ); ?>" class="mvs-upload-meta-title"
					data-wp-on--input="actions.setUploadTitle" />
				<textarea placeholder="<?php esc_attr_e( 'Description (optional)', 'wpmediaverse' ); ?>" class="mvs-upload-meta-desc" rows="2"
					data-wp-on--input="actions.setUploadDesc"></textarea>
				<div class="mvs-dashboard-upload-row">
					<input type="text" placeholder="<?php esc_attr_e( 'Tags (comma separated)', 'wpmediaverse' ); ?>" class="mvs-upload-meta-tags"
						data-wp-on--input="actions.setUploadTags" />
					<?php $mvs_def_priv = get_option( 'mvs_default_privacy', 'public' ); ?>
					<?php if ( get_option( 'mvs_allow_user_privacy', true ) ) : ?>
					<select class="mvs-upload-meta-privacy" data-wp-on--change="actions.setUploadPrivacy">
						<option value="public" <?php selected( $mvs_def_priv, 'public' ); ?>><?php esc_html_e( 'Public', 'wpmediaverse' ); ?></option>
						<option value="members" <?php selected( $mvs_def_priv, 'members' ); ?>><?php esc_html_e( 'Members', 'wpmediaverse' ); ?></option>
						<?php if ( function_exists( 'bp_is_active' ) && bp_is_active( 'friends' ) ) : ?>
						<option value="friends" <?php selected( $mvs_def_priv, 'friends' ); ?>><?php esc_html_e( 'Friends', 'wpmediaverse' ); ?></option>
						<?php endif; ?>
						<option value="private" <?php selected( $mvs_def_priv, 'private' ); ?>><?php esc_html_e( 'Private', 'wpmediaverse' ); ?></option>
					</select>
					<?php endif; ?>
				</div>
			</div>
			<div class="mvs-dashboard-upload-review" data-wp-bind--hidden="!state.upload.hasPending" hidden>
				<span class="mvs-dashboard-upload-review-label">
					<?php esc_html_e( 'Add details above (optional), then upload.', 'wpmediaverse' ); ?>
				</span>
				<div class="mvs-dashboard-upload-review-actions">
					<button class="mvs-btn mvs-btn--small mvs-btn--primary" type="button"
						data-wp-on--click="actions.confirmUpload">
						<?php esc_html_e( 'Upload', 'wpmediaverse' ); ?>
						<span data-wp-text="state.upload.pendingCount"></span>
						<?php esc_html_e( 'file(s)', 'wpmediaverse' ); ?>
					</button>
					<button class="mvs-btn mvs-btn--small mvs-btn--text" type="button"
						data-wp-on--click="actions.cancelUpload">
						<?php esc_html_e( 'Cancel', 'wpmediaverse' ); ?>
					</button>
				</div>
			</div>
			<div class="mvs-dashboard-upload-status" data-wp-bind--hidden="!state.upload.uploading"
				data-wp-text="state.upload.status" hidden></div>
		</div>

		<!-- Media Grid -->
		<?php
		// The SAME toolbar the document drive renders, from the same helper.
		// Client-driven here, so it applies on change and needs no Apply button.
		$mvs_tb_media = $mvs_toolbar_state( 'media', 'date' );
		echo $mvs_tpl->render_panel_toolbar(
			array(
				'id'     => 'mvs-media',
				// Bound, not baked. The drive's toolbar prints how many rows
				// the view holds and these four printed nothing, so the shape
				// they were told to copy read differently on every panel. The
				// text is a placeholder the Interactivity binding replaces on
				// first render and after every search.
				'count'  => array(
					'attrs' => array( 'data-wp-text' => 'state.mediaCountLabel' ),
				),
				'search' => array(
					'name'  => 'q',
					'label' => __( 'Search your media', 'wpmediaverse' ),
					'value' => $mvs_tb_media['s'],
					'attrs' => array(
						'data-panel'        => 'media',
						'data-wp-on--input' => 'actions.toolbarSearch',
					),
				),
				'sort'   => array(
					'name'    => 'sort',
					'label'   => __( 'Sort by', 'wpmediaverse' ),
					'value'   => $mvs_tb_media['orderby'],
					'options' => $mvs_sort_options_media,
					'attrs'   => array(
						'data-panel'         => 'media',
						'data-wp-on--change' => 'actions.toolbarSort',
					),
				),
				'order'  => array(
					'name'    => 'order',
					'label'   => __( 'Direction', 'wpmediaverse' ),
					'value'   => $mvs_tb_media['order'],
					'options' => $mvs_order_options,
					'attrs'   => array(
						'data-panel'         => 'media',
						'data-wp-on--change' => 'actions.toolbarOrder',
					),
				),
			)
		); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapes every value.
		?>
		<div class="mvs-dashboard-grid mvs-cols-<?php echo (int) $mvs_grid_cols; ?>">
			<template data-wp-each="state.media.items">
				<div class="mvs-dashboard-card" data-wp-bind--data-media-id="context.item.id">
					<a class="mvs-dashboard-card-thumb" data-wp-bind--href="context.item.link"
						data-wp-on--click="actions.openMediaLightbox">
						<img data-wp-bind--hidden="!state.showMediaImage" data-wp-bind--src="state.mediaThumbUrl" alt="" data-wp-bind--alt="context.item.title" loading="lazy" />
						<video class="mvs-grid-video-preview" preload="metadata" muted playsinline disablepictureinpicture aria-hidden="true"
							data-wp-bind--hidden="!state.showMediaVideoPreview"
							data-wp-bind--poster="state.mediaThumbUrl"
							data-wp-bind--src="state.mediaVideoPreviewUrl"></video>
						<span class="mvs-grid-play-icon" data-wp-bind--hidden="!state.showMediaPlayIcon"><?php echo \WPMediaVerse\Core\Plugin::container()->get( 'template_helpers' )->icon_play_svg(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hardcoded SVG helper returns markup with no user input. ?></span>
						<div class="mvs-grid-item-placeholder mvs-grid-item-placeholder--video"
							data-wp-bind--hidden="!state.showMediaVideoPlaceholder">
							<span class="mvs-grid-play-icon"><?php echo \WPMediaVerse\Core\Plugin::container()->get( 'template_helpers' )->icon_play_svg(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hardcoded SVG helper returns markup with no user input. ?></span>
						</div>
						<div class="mvs-grid-item-placeholder mvs-grid-item-placeholder--audio"
							data-wp-bind--hidden="!state.showMediaAudioPlaceholder">
							<span class="mvs-grid-audio-icon"><?php echo \WPMediaVerse\Core\Plugin::container()->get( 'template_helpers' )->icon_music_svg(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hardcoded SVG helper returns markup with no user input. ?></span>
						</div>
					</a>
					<div class="mvs-dashboard-card-body">
						<a class="mvs-dashboard-card-title" data-wp-bind--href="context.item.link"
							data-wp-text="state.itemTitle"></a>
						<div class="mvs-dashboard-card-meta">
							<span class="mvs-privacy-badge" data-wp-text="state.itemPrivacy"></span>
						</div>
						<div class="mvs-dashboard-card-actions">
							<button class="mvs-btn mvs-btn--small mvs-btn--secondary" type="button"
								data-wp-on--click="actions.openEditModal"><?php esc_html_e( 'Edit', 'wpmediaverse' ); ?></button>
							<button class="mvs-btn mvs-btn--small mvs-btn--danger" type="button"
								data-wp-on--click="actions.confirmDeleteMedia"><?php esc_html_e( 'Delete', 'wpmediaverse' ); ?></button>
						</div>
					</div>
				</div>
			</template>
		</div>
		<div class="mvs-dashboard-loading" role="status" aria-live="polite"
			data-wp-bind--hidden="!state.showMediaLoading" hidden>
			<span class="mvs-dashboard-loading__spinner" aria-hidden="true"></span>
			<span class="mvs-dashboard-loading__label"><?php esc_html_e( 'Loading…', 'wpmediaverse' ); ?></span>
		</div>
		<div data-wp-bind--hidden="!state.showMediaEmpty">
			<?php
			// The canonical empty state (Coding Rule 11). Six surfaces used to
			// hand-roll this markup while the helper sat on the Free/Pro
			// interface with no dashboard caller.
			echo $mvs_tpl->render_block_empty_state(
				array(
					'icon'    => 'image',
					'title'   => __( 'No media yet', 'wpmediaverse' ),
					'message' => __( 'Upload your first photo, video or audio file to get started.', 'wpmediaverse' ),
				)
			); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapes.
			?>
		</div>
		<div class="mvs-load-more-wrap" data-wp-bind--hidden="!state.hasMoreMedia">
			<button class="mvs-btn mvs-btn--secondary" type="button"
				data-wp-on--click="actions.loadMoreMedia"><?php esc_html_e( 'Load More', 'wpmediaverse' ); ?></button>
		</div>
	</div>

	<!-- My Albums Panel -->
	<div class="mvs-dashboard-panel" role="tabpanel" data-wp-bind--hidden="!state.isAlbumsTab"<?php echo esc_attr( $mvs_dash_panel_hidden( 'albums' ) ); ?>>
		<div class="mvs-dashboard-actions">
			<button class="mvs-btn mvs-btn--secondary" type="button"
				data-wp-on--click="actions.openCreateAlbum">+ <?php esc_html_e( 'Create Album', 'wpmediaverse' ); ?></button>
		</div>
		<?php
		// The SAME toolbar the document drive renders, from the same helper.
		// Client-driven here, so it applies on change and needs no Apply button.
		$mvs_tb_albums = $mvs_toolbar_state( 'albums', 'date' );
		echo $mvs_tpl->render_panel_toolbar(
			array(
				'id'     => 'mvs-albums',
				// Bound, not baked. The drive's toolbar prints how many rows
				// the view holds and these four printed nothing, so the shape
				// they were told to copy read differently on every panel. The
				// text is a placeholder the Interactivity binding replaces on
				// first render and after every search.
				'count'  => array(
					'attrs' => array( 'data-wp-text' => 'state.albumsCountLabel' ),
				),
				'search' => array(
					'name'  => 'q',
					'label' => __( 'Search your albums', 'wpmediaverse' ),
					'value' => $mvs_tb_albums['s'],
					'attrs' => array(
						'data-panel'        => 'albums',
						'data-wp-on--input' => 'actions.toolbarSearch',
					),
				),
				'sort'   => array(
					'name'    => 'sort',
					'label'   => __( 'Sort by', 'wpmediaverse' ),
					'value'   => $mvs_tb_albums['orderby'],
					'options' => $mvs_sort_options_albums,
					'attrs'   => array(
						'data-panel'         => 'albums',
						'data-wp-on--change' => 'actions.toolbarSort',
					),
				),
				'order'  => array(
					'name'    => 'order',
					'label'   => __( 'Direction', 'wpmediaverse' ),
					'value'   => $mvs_tb_albums['order'],
					'options' => $mvs_order_options,
					'attrs'   => array(
						'data-panel'         => 'albums',
						'data-wp-on--change' => 'actions.toolbarOrder',
					),
				),
			)
		); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapes every value.
		?>
		<div class="mvs-dashboard-grid mvs-cols-<?php echo (int) $mvs_grid_cols; ?>">
			<template data-wp-each="state.albums.items">
				<div class="mvs-dashboard-card" data-wp-bind--data-album-id="context.item.id">
					<a class="mvs-dashboard-card-thumb" data-wp-bind--href="context.item.link">
						<img data-wp-bind--hidden="!state.hasAlbumCover" data-wp-bind--src="context.item.cover_url" alt="" data-wp-bind--alt="context.item.title" loading="lazy" />
						<div class="mvs-grid-item-placeholder mvs-grid-item-placeholder--album"
							data-wp-bind--hidden="state.hasAlbumCover">
							<span class="mvs-grid-album-icon">&#128193;</span>
						</div>
					</a>
					<div class="mvs-dashboard-card-body">
						<div class="mvs-dashboard-card-title" data-wp-text="state.itemTitle"></div>
						<div class="mvs-dashboard-card-meta" data-wp-text="state.albumItemCount"></div>
						<div class="mvs-dashboard-card-actions">
							<button class="mvs-btn mvs-btn--small mvs-btn--secondary" type="button"
								data-wp-on--click="actions.openAlbumModal"><?php esc_html_e( 'Edit', 'wpmediaverse' ); ?></button>
							<button class="mvs-btn mvs-btn--small mvs-btn--danger" type="button"
								data-wp-on--click="actions.confirmDeleteAlbum"><?php esc_html_e( 'Delete', 'wpmediaverse' ); ?></button>
						</div>
					</div>
				</div>
			</template>
		</div>
		<div class="mvs-dashboard-loading" role="status" aria-live="polite"
			data-wp-bind--hidden="!state.showAlbumsLoading" hidden>
			<span class="mvs-dashboard-loading__spinner" aria-hidden="true"></span>
			<span class="mvs-dashboard-loading__label"><?php esc_html_e( 'Loading…', 'wpmediaverse' ); ?></span>
		</div>
		<div data-wp-bind--hidden="!state.showAlbumsEmpty">
			<?php
			// The canonical empty state (Coding Rule 11). Six surfaces used to
			// hand-roll this markup while the helper sat on the Free/Pro
			// interface with no dashboard caller.
			echo $mvs_tpl->render_block_empty_state(
				array(
					'icon'    => 'folder',
					'title'   => __( 'No albums yet', 'wpmediaverse' ),
					'message' => __( 'Create your first album to organize your media into collections.', 'wpmediaverse' ),
				)
			); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapes.
			?>
		</div>
		<div class="mvs-load-more-wrap" data-wp-bind--hidden="!state.hasMoreAlbums">
			<button class="mvs-btn mvs-btn--secondary" type="button"
				data-wp-on--click="actions.loadMoreAlbums"><?php esc_html_e( 'Load More', 'wpmediaverse' ); ?></button>
		</div>
	</div>

	<!-- My Favorites Panel -->
	<div class="mvs-dashboard-panel" role="tabpanel" data-wp-bind--hidden="!state.isFavoritesTab"<?php echo esc_attr( $mvs_dash_panel_hidden( 'favorites' ) ); ?>>
		<?php
		// The SAME toolbar the document drive renders, from the same helper.
		// Client-driven here, so it applies on change and needs no Apply button.
		$mvs_tb_favorites = $mvs_toolbar_state( 'favorites', 'favorited' );
		echo $mvs_tpl->render_panel_toolbar(
			array(
				'id'     => 'mvs-favorites',
				// Bound, not baked. The drive's toolbar prints how many rows
				// the view holds and these four printed nothing, so the shape
				// they were told to copy read differently on every panel. The
				// text is a placeholder the Interactivity binding replaces on
				// first render and after every search.
				'count'  => array(
					'attrs' => array( 'data-wp-text' => 'state.favoritesCountLabel' ),
				),
				'search' => array(
					'name'  => 'q',
					'label' => __( 'Search your favourites', 'wpmediaverse' ),
					'value' => $mvs_tb_favorites['s'],
					'attrs' => array(
						'data-panel'        => 'favorites',
						'data-wp-on--input' => 'actions.toolbarSearch',
					),
				),
				'sort'   => array(
					'name'    => 'sort',
					'label'   => __( 'Sort by', 'wpmediaverse' ),
					'value'   => $mvs_tb_favorites['orderby'],
					'options' => $mvs_sort_options_favorites,
					'attrs'   => array(
						'data-panel'         => 'favorites',
						'data-wp-on--change' => 'actions.toolbarSort',
					),
				),
				'order'  => array(
					'name'    => 'order',
					'label'   => __( 'Direction', 'wpmediaverse' ),
					'value'   => $mvs_tb_favorites['order'],
					'options' => $mvs_order_options,
					'attrs'   => array(
						'data-panel'         => 'favorites',
						'data-wp-on--change' => 'actions.toolbarOrder',
					),
				),
			)
		); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapes every value.
		?>
		<div class="mvs-dashboard-grid mvs-cols-<?php echo (int) $mvs_grid_cols; ?>">
			<template data-wp-each="state.favorites.items">
				<div class="mvs-dashboard-card" data-wp-bind--data-fav-id="context.item.media_id">
					<a class="mvs-dashboard-card-thumb" data-wp-bind--href="context.item.link"
						data-wp-on--click="actions.openFavLightbox">
						<img data-wp-bind--hidden="!state.showFavImage" data-wp-bind--src="state.favThumbUrl" alt="" data-wp-bind--alt="context.item.title" loading="lazy" />
						<video class="mvs-grid-video-preview" preload="metadata" muted playsinline disablepictureinpicture aria-hidden="true"
							data-wp-bind--hidden="!state.showFavVideoPreview"
							data-wp-bind--poster="state.favThumbUrl"
							data-wp-bind--src="state.favVideoPreviewUrl"></video>
						<span class="mvs-grid-play-icon" data-wp-bind--hidden="!state.showFavPlayIcon"><?php echo \WPMediaVerse\Core\Plugin::container()->get( 'template_helpers' )->icon_play_svg(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hardcoded SVG helper returns markup with no user input. ?></span>
						<div class="mvs-grid-item-placeholder mvs-grid-item-placeholder--video"
							data-wp-bind--hidden="!state.showFavVideoPlaceholder">
							<span class="mvs-grid-play-icon"><?php echo \WPMediaVerse\Core\Plugin::container()->get( 'template_helpers' )->icon_play_svg(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hardcoded SVG helper returns markup with no user input. ?></span>
						</div>
						<div class="mvs-grid-item-placeholder mvs-grid-item-placeholder--audio"
							data-wp-bind--hidden="!state.showFavAudioPlaceholder">
							<span class="mvs-grid-audio-icon"><?php echo \WPMediaVerse\Core\Plugin::container()->get( 'template_helpers' )->icon_music_svg(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hardcoded SVG helper returns markup with no user input. ?></span>
						</div>
					</a>
					<div class="mvs-dashboard-card-body">
						<div class="mvs-dashboard-card-title" data-wp-text="state.itemTitle"></div>
						<button class="mvs-btn mvs-btn--small mvs-btn--secondary" type="button"
							data-wp-on--click="actions.unfavorite"><?php esc_html_e( 'Unfavorite', 'wpmediaverse' ); ?></button>
					</div>
				</div>
			</template>
		</div>
		<div class="mvs-dashboard-loading" role="status" aria-live="polite"
			data-wp-bind--hidden="!state.showFavoritesLoading" hidden>
			<span class="mvs-dashboard-loading__spinner" aria-hidden="true"></span>
			<span class="mvs-dashboard-loading__label"><?php esc_html_e( 'Loading…', 'wpmediaverse' ); ?></span>
		</div>
		<div data-wp-bind--hidden="!state.showFavoritesEmpty">
			<?php
			// The canonical empty state (Coding Rule 11). Six surfaces used to
			// hand-roll this markup while the helper sat on the Free/Pro
			// interface with no dashboard caller.
			echo $mvs_tpl->render_block_empty_state(
				array(
					'icon'    => 'heart',
					'title'   => __( 'No favourites yet', 'wpmediaverse' ),
					'message' => __( 'Media you favourite appears here so you can find it again.', 'wpmediaverse' ),
				)
			); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapes.
			?>
		</div>
		<div class="mvs-load-more-wrap" data-wp-bind--hidden="!state.hasMoreFavorites">
			<button class="mvs-btn mvs-btn--secondary" type="button"
				data-wp-on--click="actions.loadMoreFavorites"><?php esc_html_e( 'Load More', 'wpmediaverse' ); ?></button>
		</div>
	</div>

	<!-- My Collections Panel -->
	<div class="mvs-dashboard-panel" role="tabpanel" data-wp-bind--hidden="!state.isCollectionsTab"<?php echo esc_attr( $mvs_dash_panel_hidden( 'collections' ) ); ?>>
		<div class="mvs-dashboard-actions">
			<button class="mvs-btn mvs-btn--secondary" type="button"
				data-wp-on--click="actions.openCreateCollection">+ <?php esc_html_e( 'Create Collection', 'wpmediaverse' ); ?></button>
		</div>
		<?php
		// The SAME toolbar the document drive renders, from the same helper.
		// Client-driven here, so it applies on change and needs no Apply button.
		$mvs_tb_collections = $mvs_toolbar_state( 'collections', 'date' );
		echo $mvs_tpl->render_panel_toolbar(
			array(
				'id'     => 'mvs-collections',
				// Bound, not baked. The drive's toolbar prints how many rows
				// the view holds and these four printed nothing, so the shape
				// they were told to copy read differently on every panel. The
				// text is a placeholder the Interactivity binding replaces on
				// first render and after every search.
				'count'  => array(
					'attrs' => array( 'data-wp-text' => 'state.collectionsCountLabel' ),
				),
				'search' => array(
					'name'  => 'q',
					'label' => __( 'Search your collections', 'wpmediaverse' ),
					'value' => $mvs_tb_collections['s'],
					'attrs' => array(
						'data-panel'        => 'collections',
						'data-wp-on--input' => 'actions.toolbarSearch',
					),
				),
				'sort'   => array(
					'name'    => 'sort',
					'label'   => __( 'Sort by', 'wpmediaverse' ),
					'value'   => $mvs_tb_collections['orderby'],
					'options' => $mvs_sort_options_collections,
					'attrs'   => array(
						'data-panel'         => 'collections',
						'data-wp-on--change' => 'actions.toolbarSort',
					),
				),
				'order'  => array(
					'name'    => 'order',
					'label'   => __( 'Direction', 'wpmediaverse' ),
					'value'   => $mvs_tb_collections['order'],
					'options' => $mvs_order_options,
					'attrs'   => array(
						'data-panel'         => 'collections',
						'data-wp-on--change' => 'actions.toolbarOrder',
					),
				),
			)
		); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapes every value.
		?>
		<div class="mvs-dashboard-grid mvs-cols-<?php echo (int) $mvs_grid_cols; ?>">
			<template data-wp-each="state.collections.items">
				<div class="mvs-dashboard-card mvs-collection-card" data-wp-bind--data-collection-id="context.item.id">
					<a class="mvs-dashboard-card-thumb" data-wp-bind--href="context.item.link">
						<img data-wp-bind--src="state.collectionCoverUrl"
							alt="" data-wp-bind--alt="state.itemTitle"
							data-wp-bind--hidden="!state.hasCollectionCover"
							loading="lazy" />
						<div class="mvs-grid-item-placeholder mvs-grid-item-placeholder--collection"
							data-wp-bind--hidden="state.hasCollectionCover">
							<span class="mvs-grid-collection-icon">&#128218;</span>
						</div>
						<span class="mvs-collection-type-badge" data-wp-text="context.item.type"></span>
					</a>
					<div class="mvs-dashboard-card-body">
						<a class="mvs-dashboard-card-title" data-wp-bind--href="context.item.link"
							data-wp-text="state.itemTitle"></a>
						<div class="mvs-dashboard-card-meta" data-wp-text="state.collectionItemCount"></div>
						<div class="mvs-dashboard-card-meta" data-wp-text="context.item.description"
							data-wp-bind--hidden="!context.item.description"></div>
						<div class="mvs-collection-rules-preview" data-wp-bind--hidden="!state.isSmartCollection">
							<template data-wp-each--rule="context.item.rules">
								<span class="mvs-rule-pill" data-wp-text="state.rulePillText"></span>
							</template>
						</div>
						<div class="mvs-dashboard-card-actions">
							<button class="mvs-btn mvs-btn--small mvs-btn--secondary" type="button"
								data-wp-on--click="actions.openEditCollection"><?php esc_html_e( 'Edit', 'wpmediaverse' ); ?></button>
							<button class="mvs-btn mvs-btn--small mvs-btn--danger" type="button"
								data-wp-on--click="actions.confirmDeleteCollection"><?php esc_html_e( 'Delete', 'wpmediaverse' ); ?></button>
						</div>
					</div>
				</div>
			</template>
		</div>
		<div class="mvs-dashboard-loading" role="status" aria-live="polite"
			data-wp-bind--hidden="!state.showCollectionsLoading" hidden>
			<span class="mvs-dashboard-loading__spinner" aria-hidden="true"></span>
			<span class="mvs-dashboard-loading__label"><?php esc_html_e( 'Loading…', 'wpmediaverse' ); ?></span>
		</div>
		<div data-wp-bind--hidden="!state.showCollectionsEmpty">
			<?php
			// The canonical empty state (Coding Rule 11). Six surfaces used to
			// hand-roll this markup while the helper sat on the Free/Pro
			// interface with no dashboard caller.
			echo $mvs_tpl->render_block_empty_state(
				array(
					'icon'    => 'library',
					'title'   => __( 'No collections yet', 'wpmediaverse' ),
					'message' => __( 'Create a smart collection to auto-organize your media!', 'wpmediaverse' ),
				)
			); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapes.
			?>
		</div>
		<div class="mvs-load-more-wrap" data-wp-bind--hidden="!state.hasMoreCollections">
			<button class="mvs-btn mvs-btn--secondary" type="button"
				data-wp-on--click="actions.loadMoreCollections"><?php esc_html_e( 'Load More', 'wpmediaverse' ); ?></button>
		</div>
	</div>

	<?php
	/**
	 * Fires after the last dashboard tab panel.
	 *
	 * Pro uses this to inject gamification panels (Challenges, Battles, Tournaments).
	 *
	 * @since 1.1.0
	 */
	do_action( 'mvs_dashboard_panels' );
	?>

	</div><!-- /.mvs-dashboard__body -->

	<!-- Collection Modal (Create/Edit with Rule Builder) -->
	<div class="mvs-modal-overlay" hidden data-wp-bind--hidden="!state.collectionModal.visible"
		data-wp-on--click="actions.closeOverlay">
		<div class="mvs-modal mvs-modal--wide" data-wp-on--click="actions.stopPropagation">
			<div class="mvs-modal-header">
				<h2>
					<span data-wp-bind--hidden="state.collectionModal.isEdit"><?php esc_html_e( 'Create Collection', 'wpmediaverse' ); ?></span>
					<span data-wp-bind--hidden="!state.collectionModal.isEdit"><?php esc_html_e( 'Edit Collection', 'wpmediaverse' ); ?></span>
				</h2>
				<button class="mvs-modal-close" type="button" data-wp-on--click="actions.closeCollectionModal" aria-label="<?php esc_attr_e( 'Close', 'wpmediaverse' ); ?>">&times;</button>
			</div>
			<div class="mvs-modal-body">
				<div class="mvs-field">
					<label><?php esc_html_e( 'Title', 'wpmediaverse' ); ?></label>
					<input type="text" data-wp-bind--value="state.collectionModal.title"
						data-wp-on--input="actions.setCollectionTitle" />
				</div>
				<div class="mvs-field">
					<label><?php esc_html_e( 'Description', 'wpmediaverse' ); ?></label>
					<textarea data-wp-bind--value="state.collectionModal.description"
						data-wp-on--input="actions.setCollectionDesc" rows="2"></textarea>
				</div>
				<div class="mvs-field">
					<label><?php esc_html_e( 'Type', 'wpmediaverse' ); ?></label>
					<div class="mvs-collection-type-toggle">
						<button type="button" class="mvs-toggle-btn"
							data-wp-class--active="state.isManualType"
							data-wp-on--click="actions.setCollectionTypeManual">
							<?php esc_html_e( 'Manual', 'wpmediaverse' ); ?>
						</button>
						<button type="button" class="mvs-toggle-btn"
							data-wp-class--active="state.isSmartType"
							data-wp-on--click="actions.setCollectionTypeSmart">
							<?php esc_html_e( 'Smart', 'wpmediaverse' ); ?>
						</button>
					</div>
					<p class="mvs-field-hint" data-wp-bind--hidden="!state.isManualType">
						<?php esc_html_e( 'Add media to this collection manually via the Favorites button.', 'wpmediaverse' ); ?>
					</p>
					<p class="mvs-field-hint" data-wp-bind--hidden="!state.isSmartType">
						<?php esc_html_e( 'Define rules and media matching all conditions will appear automatically.', 'wpmediaverse' ); ?>
					</p>
				</div>

				<!-- Smart Rules Builder -->
				<div class="mvs-rules-builder" data-wp-bind--hidden="!state.isSmartType">
					<label><?php esc_html_e( 'Rules (all must match)', 'wpmediaverse' ); ?></label>
					<div class="mvs-rules-list">
						<template data-wp-each--rule="state.collectionModal.rules">
							<div class="mvs-rule-row" data-wp-bind--data-rule-index="context.rule.index">
								<select class="mvs-rule-key" data-wp-on--change="actions.setRuleKey">
									<option value=""><?php esc_html_e( '-- Select --', 'wpmediaverse' ); ?></option>
									<option value="media_type" data-wp-bind--selected="state.isRuleKeyMediaType"><?php esc_html_e( 'Media Type', 'wpmediaverse' ); ?></option>
									<option value="tag" data-wp-bind--selected="state.isRuleKeyTag"><?php esc_html_e( 'Tag', 'wpmediaverse' ); ?></option>
									<option value="category" data-wp-bind--selected="state.isRuleKeyCategory"><?php esc_html_e( 'Category', 'wpmediaverse' ); ?></option>
									<option value="author" data-wp-bind--selected="state.isRuleKeyAuthor"><?php esc_html_e( 'Author', 'wpmediaverse' ); ?></option>
									<option value="privacy" data-wp-bind--selected="state.isRuleKeyPrivacy"><?php esc_html_e( 'Privacy', 'wpmediaverse' ); ?></option>
									<option value="date_after" data-wp-bind--selected="state.isRuleKeyDateAfter"><?php esc_html_e( 'Date After', 'wpmediaverse' ); ?></option>
									<option value="date_before" data-wp-bind--selected="state.isRuleKeyDateBefore"><?php esc_html_e( 'Date Before', 'wpmediaverse' ); ?></option>
								</select>

								<!-- Value input: changes based on rule key -->
								<select class="mvs-rule-value" data-wp-on--change="actions.setRuleValue"
									data-wp-bind--hidden="!state.isRuleSelectType">
									<template data-wp-each--opt="state.ruleValueOptions">
										<option data-wp-bind--value="context.opt.value"
											data-wp-bind--selected="state.isRuleValueSelected"
											data-wp-text="context.opt.label"></option>
									</template>
								</select>
								<input type="text" class="mvs-rule-value-text" data-wp-on--change="actions.setRuleValue"
									data-wp-bind--hidden="state.isRuleSelectType"
									data-wp-bind--value="context.rule.value"
									data-wp-bind--type="state.ruleInputType"
									data-wp-bind--placeholder="state.ruleInputPlaceholder" />

								<button type="button" class="mvs-rule-remove" data-wp-on--click="actions.removeRule">&times;</button>
							</div>
						</template>
					</div>
					<button type="button" class="mvs-btn mvs-btn--small mvs-btn--secondary"
						data-wp-on--click="actions.addRule">+ <?php esc_html_e( 'Add Rule', 'wpmediaverse' ); ?></button>

					<div class="mvs-rules-preview" data-wp-bind--hidden="!state.collectionModal.previewCount">
						<span data-wp-text="state.collectionModal.previewCount"></span> <?php esc_html_e( 'media items match', 'wpmediaverse' ); ?>
					</div>
				</div>
			</div>
			<div class="mvs-modal-footer">
				<button class="mvs-btn mvs-btn--secondary" type="button"
					data-wp-on--click="actions.closeCollectionModal"><?php esc_html_e( 'Cancel', 'wpmediaverse' ); ?></button>
				<button class="mvs-btn" type="button"
					data-wp-on--click="actions.saveCollection"
					data-wp-bind--disabled="state.collectionModal.saving">
					<span data-wp-bind--hidden="state.collectionModal.saving">
						<span data-wp-bind--hidden="state.collectionModal.isEdit"><?php esc_html_e( 'Create', 'wpmediaverse' ); ?></span>
						<span data-wp-bind--hidden="!state.collectionModal.isEdit"><?php esc_html_e( 'Save', 'wpmediaverse' ); ?></span>
					</span>
					<span data-wp-bind--hidden="!state.collectionModal.saving" hidden><?php esc_html_e( 'Saving...', 'wpmediaverse' ); ?></span>
				</button>
			</div>
		</div>
	</div>

	<!-- Edit Media Modal -->
	<div class="mvs-modal-overlay" hidden data-wp-bind--hidden="!state.editModal.visible"
		data-wp-on--click="actions.closeOverlay">
		<div class="mvs-modal" data-wp-on--click="actions.stopPropagation">
			<div class="mvs-modal-header">
				<h2><?php esc_html_e( 'Edit Media', 'wpmediaverse' ); ?></h2>
				<button class="mvs-modal-close" type="button" data-wp-on--click="actions.closeEditModal" aria-label="<?php esc_attr_e( 'Close', 'wpmediaverse' ); ?>">&times;</button>
			</div>
			<div class="mvs-modal-body">
				<div class="mvs-field">
					<label><?php esc_html_e( 'Title', 'wpmediaverse' ); ?></label>
					<input type="text" data-wp-bind--value="state.editModal.title"
						data-wp-on--input="actions.setEditTitle"
						aria-describedby="mvs-edit-title-hint"
						data-wp-bind--aria-invalid="state.editModalTitleMissing" />
					<span class="mvs-field-hint" id="mvs-edit-title-hint" role="status"
						data-wp-bind--hidden="!state.editModalTitleMissing"><?php esc_html_e( 'Title cannot be empty.', 'wpmediaverse' ); ?></span>
				</div>
				<div class="mvs-field">
					<label><?php esc_html_e( 'Description', 'wpmediaverse' ); ?></label>
					<textarea data-wp-bind--value="state.editModal.description"
						data-wp-on--input="actions.setEditDesc"></textarea>
				</div>
				<!-- Privacy + slug-regenerate share a row to save vertical space.
					Off by default — keeps inbound URLs stable. -->
				<div class="mvs-field-row">
					<div class="mvs-field mvs-field--inline">
						<label><?php esc_html_e( 'Privacy', 'wpmediaverse' ); ?></label>
						<select data-wp-on--change="actions.setEditPrivacy">
							<option value="public"><?php esc_html_e( 'Public', 'wpmediaverse' ); ?></option>
							<option value="members"><?php esc_html_e( 'Members', 'wpmediaverse' ); ?></option>
							<?php if ( function_exists( 'bp_is_active' ) && bp_is_active( 'friends' ) ) : ?>
							<option value="friends"><?php esc_html_e( 'Friends', 'wpmediaverse' ); ?></option>
							<?php endif; ?>
							<option value="private"><?php esc_html_e( 'Private', 'wpmediaverse' ); ?></option>
						</select>
					</div>
					<div class="mvs-field mvs-field--inline mvs-field--checkbox">
						<label title="<?php esc_attr_e( 'Tick to regenerate the URL slug from the new title. Off by default to keep inbound links stable.', 'wpmediaverse' ); ?>">
							<input type="checkbox" class="mvs-edit-regenerate-slug" />
							<?php esc_html_e( 'Update URL slug', 'wpmediaverse' ); ?>
						</label>
					</div>
				</div>
				<div class="mvs-field">
					<label><?php esc_html_e( 'Tags', 'wpmediaverse' ); ?></label>
					<div class="mvs-tag-input-wrap">
						<div class="mvs-tag-pills">
							<template data-wp-each="state.editModal.tags">
								<span class="mvs-tag-pill">
									<span data-wp-text="context.item"></span>
									<button type="button" class="mvs-tag-pill-remove"
										data-wp-bind--data-tag-name="context.item"
										data-wp-on--click="actions.removeEditTag">&times;</button>
								</span>
							</template>
							<input type="text" class="mvs-tag-text-input" placeholder="<?php esc_attr_e( 'Add tags...', 'wpmediaverse' ); ?>"
								data-wp-bind--value="state.editModal.tagInput"
								data-wp-on--input="actions.updateEditTagInput"
								data-wp-on--keydown="actions.addEditTag" />
						</div>
						<div class="mvs-tag-autocomplete" data-wp-bind--hidden="!state.editModal.tagDropdownVisible">
							<template data-wp-each="state.editModal.tagResults">
								<div class="mvs-tag-autocomplete-item"
									data-wp-bind--data-tag-name="context.item"
									data-wp-text="context.item"
									data-wp-on--click="actions.selectEditTag"></div>
							</template>
						</div>
					</div>
				</div>
			</div>
				<div class="mvs-replace-file-row">
					<label class="mvs-btn mvs-btn--secondary mvs-btn--small mvs-replace-file-label">
					&#8635; <?php esc_html_e( 'Replace File', 'wpmediaverse' ); ?>
					<input type="file" hidden data-wp-on--change="actions.handleReplaceFile" />
				</label>
					<span class="mvs-replace-file-hint"><?php esc_html_e( 'Upload a new file. Metadata is preserved.', 'wpmediaverse' ); ?></span>
			</div>
			<div class="mvs-modal-footer">
				<button class="mvs-btn mvs-btn--secondary" type="button"
					data-wp-on--click="actions.closeEditModal"><?php esc_html_e( 'Cancel', 'wpmediaverse' ); ?></button>
				<button class="mvs-btn" type="button"
					data-wp-on--click="actions.saveEdit"
					data-wp-bind--disabled="state.editModalSaveDisabled">
					<span data-wp-bind--hidden="state.editModal.saving"><?php esc_html_e( 'Save', 'wpmediaverse' ); ?></span>
					<span data-wp-bind--hidden="!state.editModal.saving" hidden><?php esc_html_e( 'Saving...', 'wpmediaverse' ); ?></span>
				</button>
			</div>
		</div>
	</div>

	<!-- Album Modal (Create/Edit) -->
	<div class="mvs-modal-overlay" hidden data-wp-bind--hidden="!state.albumModal.visible"
		data-wp-on--click="actions.closeOverlay">
		<div class="mvs-modal" data-wp-on--click="actions.stopPropagation">
			<div class="mvs-modal-header">
				<h2>
					<span data-wp-bind--hidden="state.albumModal.isEdit"><?php esc_html_e( 'Create Album', 'wpmediaverse' ); ?></span>
					<span data-wp-bind--hidden="!state.albumModal.isEdit"><?php esc_html_e( 'Edit Album', 'wpmediaverse' ); ?></span>
				</h2>
				<button class="mvs-modal-close" type="button" data-wp-on--click="actions.closeAlbumModal" aria-label="<?php esc_attr_e( 'Close', 'wpmediaverse' ); ?>">&times;</button>
			</div>
			<div class="mvs-modal-body">
				<div class="mvs-field">
					<label><?php esc_html_e( 'Title', 'wpmediaverse' ); ?></label>
					<input type="text" data-wp-bind--value="state.albumModal.title"
						data-wp-on--input="actions.setAlbumTitle" />
				</div>
				<div class="mvs-field">
					<label><?php esc_html_e( 'Description', 'wpmediaverse' ); ?></label>
					<textarea data-wp-bind--value="state.albumModal.description"
						data-wp-on--input="actions.setAlbumDesc"></textarea>
				</div>
				<div class="mvs-field">
					<label><?php esc_html_e( 'Privacy', 'wpmediaverse' ); ?></label>
					<select data-wp-on--change="actions.setAlbumPrivacy">
						<option value="public"><?php esc_html_e( 'Public', 'wpmediaverse' ); ?></option>
						<option value="members"><?php esc_html_e( 'Members', 'wpmediaverse' ); ?></option>
						<?php if ( function_exists( 'bp_is_active' ) && bp_is_active( 'friends' ) ) : ?>
						<option value="friends"><?php esc_html_e( 'Friends', 'wpmediaverse' ); ?></option>
						<?php endif; ?>
						<option value="private"><?php esc_html_e( 'Private', 'wpmediaverse' ); ?></option>
					</select>
				</div>
				<div class="mvs-field">
					<label>
						<span data-wp-bind--hidden="state.albumModal.isEdit"><?php esc_html_e( 'Select Media', 'wpmediaverse' ); ?></span>
						<span data-wp-bind--hidden="!state.albumModal.isEdit"><?php esc_html_e( 'Album Media & Cover', 'wpmediaverse' ); ?></span>
					</label>
					<p class="mvs-field-hint">
						<?php esc_html_e( 'Click a thumbnail to toggle selection. Click "Set Cover" to choose the album cover.', 'wpmediaverse' ); ?>
					</p>
					<div class="mvs-media-picker">
						<p data-wp-bind--hidden="!state.albumModal.pickerLoading" hidden><?php esc_html_e( 'Loading media...', 'wpmediaverse' ); ?></p>
						<template data-wp-each="state.albumModal.pickerItems">
							<div class="mvs-media-picker-item"
								data-wp-bind--data-picker-id="context.item.id"
								data-wp-class--selected="state.isPickerSelected"
								data-wp-class--mvs-media-picker-cover="state.isPickerCover"
								data-wp-on--click="actions.togglePickerItem">
								<img data-wp-bind--hidden="!state.showPickerImage" data-wp-bind--src="state.pickerThumbUrl" alt="" data-wp-bind--alt="context.item.title" loading="lazy" />
								<video class="mvs-grid-video-preview" preload="metadata" muted playsinline disablepictureinpicture aria-hidden="true"
									data-wp-bind--hidden="!state.showPickerVideoPreview"
									data-wp-bind--poster="state.pickerThumbUrl"
									data-wp-bind--src="state.pickerVideoPreviewUrl"></video>
								<span class="mvs-grid-play-icon" data-wp-bind--hidden="!state.showPickerPlayIcon"><?php echo \WPMediaVerse\Core\Plugin::container()->get( 'template_helpers' )->icon_play_svg(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hardcoded SVG helper returns markup with no user input. ?></span>
								<div class="mvs-grid-item-placeholder mvs-grid-item-placeholder--video"
									data-wp-bind--hidden="!state.showPickerVideoPlaceholder">
									<span class="mvs-grid-play-icon"><?php echo \WPMediaVerse\Core\Plugin::container()->get( 'template_helpers' )->icon_play_svg(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hardcoded SVG helper returns markup with no user input. ?></span>
								</div>
								<div class="mvs-grid-item-placeholder mvs-grid-item-placeholder--audio"
									data-wp-bind--hidden="!state.showPickerAudioPlaceholder">
									<span class="mvs-grid-audio-icon"><?php echo \WPMediaVerse\Core\Plugin::container()->get( 'template_helpers' )->icon_music_svg(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hardcoded SVG helper returns markup with no user input. ?></span>
								</div>
								<span class="mvs-media-picker-check">&#x2713;</span>
								<button class="mvs-media-picker-cover-btn" type="button"
									data-wp-on--click="actions.setCoverItem">
									<?php esc_html_e( 'Set Cover', 'wpmediaverse' ); ?>
								</button>
								<span class="mvs-media-picker-cover-badge" data-wp-bind--hidden="!state.isPickerCover">
									<?php esc_html_e( 'Cover', 'wpmediaverse' ); ?>
								</span>
							</div>
						</template>
					</div>
				</div>
			</div>
			<div class="mvs-modal-footer">
				<button class="mvs-btn mvs-btn--secondary" type="button"
					data-wp-on--click="actions.closeAlbumModal"><?php esc_html_e( 'Cancel', 'wpmediaverse' ); ?></button>
				<button class="mvs-btn" type="button"
					data-wp-on--click="actions.saveAlbum"
					data-wp-bind--disabled="state.albumModal.saving">
					<span data-wp-bind--hidden="state.albumModal.saving">
						<span data-wp-bind--hidden="state.albumModal.isEdit"><?php esc_html_e( 'Create', 'wpmediaverse' ); ?></span>
						<span data-wp-bind--hidden="!state.albumModal.isEdit"><?php esc_html_e( 'Save', 'wpmediaverse' ); ?></span>
					</span>
					<span data-wp-bind--hidden="!state.albumModal.saving" hidden><?php esc_html_e( 'Saving...', 'wpmediaverse' ); ?></span>
				</button>
			</div>
		</div>
	</div>

	<!-- Toast is provided globally by shared-ui-frame.php in wp_footer. -->

	<!-- Confirm Dialog (shared-ui) -->
	<div class="mvs-confirm-overlay" hidden
		data-wp-interactive="mvs/shared-ui"
		data-wp-bind--hidden="!state.confirmVisible">
		<div class="mvs-confirm">
			<p data-wp-text="state.confirmMessage"></p>
			<div class="mvs-confirm-actions">
				<button class="mvs-btn mvs-btn--secondary" type="button"
					data-wp-on--click="actions.handleConfirmCancel"><?php esc_html_e( 'Cancel', 'wpmediaverse' ); ?></button>
				<button class="mvs-btn mvs-btn--danger" type="button"
					data-wp-on--click="actions.handleConfirmYes" data-wp-text="state.confirmButtonLabel"></button>
			</div>
		</div>
	</div>

	<?php
	/**
	 * Fires after the dashboard content is rendered.
	 *
	 * Pro uses this to display the quota usage widget below the media grid.
	 * Fired INSIDE the .mvs-dashboard wrapper so Interactivity API directives
	 * (e.g. data-wp-bind--hidden) work in the rendered markup.
	 *
	 * @since 1.1.0
	 */
	do_action( 'mvs_dashboard_after_content' );
	?>
</div>
