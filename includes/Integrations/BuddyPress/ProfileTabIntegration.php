<?php
/**
 * ProfileTabIntegration — user profile media tab for BuddyPress.
 *
 * Adds a "Media" tab with sub-tabs (All Media, Albums) to BuddyPress
 * member profiles. The actual rendering — upload form, grid, albums,
 * single-album view — is shared with the group tab via
 * `BaseBPTabIntegration`. This subclass supplies only the
 * profile-specific bits: BP nav setup, the media-count badge, scope
 * queries (`WHERE post_author = $user_id`), owner check, and copy.
 *
 * @package WPMediaVerse\Integrations\BuddyPress
 */

namespace WPMediaVerse\Integrations\BuddyPress;

defined( 'ABSPATH' ) || exit;

/**
 * Manages the Media tab on BuddyPress member profiles.
 */
class ProfileTabIntegration extends BaseBPTabIntegration {

	/**
	 * Register profile tab hooks.
	 */
	public function init(): void {
		if ( ! function_exists( 'buddypress' ) ) {
			return;
		}

		add_action( 'bp_setup_nav', array( $this, 'add_profile_tab' ), 100 );
		add_action( 'bp_template_redirect', array( $this, 'update_media_tab_count' ) );

		// Resolve member profile URLs to the BP member page. Lives here so core
		// (TemplateHelpers::get_user_profile_url) stays standalone — it defaults
		// to the plugin's own /media/@login/ route and BP/BuddyNext override via
		// this filter. No bp_* calls in core templates or helpers.
		add_filter( 'mvs_user_profile_url', array( $this, 'filter_user_profile_url' ), 10, 2 );
	}

	/**
	 * Resolve a member's profile URL to their BuddyPress member page.
	 *
	 * Covers BuddyNext as well, since it exposes the same bp_* functions.
	 *
	 * @param string $url     Standalone default URL (passed through if BP can't resolve).
	 * @param int    $user_id User ID.
	 * @return string
	 */
	public function filter_user_profile_url( string $url, int $user_id ): string {
		if ( function_exists( 'bp_members_get_user_url' ) ) {
			$bp_url = (string) bp_members_get_user_url( $user_id );
		} elseif ( function_exists( 'bp_core_get_user_domain' ) ) {
			$bp_url = (string) bp_core_get_user_domain( $user_id );
		} else {
			return $url;
		}

		return '' !== $bp_url ? $bp_url : $url;
	}

	/**
	 * Add a Media tab on the user profile with sub-tabs.
	 */
	public function add_profile_tab(): void {
		if ( ! function_exists( 'bp_core_new_nav_item' ) ) {
			return;
		}

		$user_domain = bp_displayed_user_domain();
		$media_link  = trailingslashit( $user_domain . 'media' );

		bp_core_new_nav_item(
			array(
				'name'                => __( 'Media', 'wpmediaverse' ),
				'slug'                => 'media',
				'parent_url'          => $media_link,
				'parent_slug'         => buddypress()->profile->slug,
				'screen_function'     => array( $this, 'render_profile_media_tab' ),
				'position'            => 80,
				'default_subnav_slug' => 'all',
			)
		);

		bp_core_new_subnav_item(
			array(
				'name'            => __( 'Media', 'wpmediaverse' ),
				'slug'            => 'all',
				'parent_url'      => $media_link,
				'parent_slug'     => 'media',
				'screen_function' => array( $this, 'render_profile_media_tab' ),
				'position'        => 10,
			)
		);

		bp_core_new_subnav_item(
			array(
				'name'            => __( 'Albums', 'wpmediaverse' ),
				'slug'            => 'albums',
				'parent_url'      => $media_link,
				'parent_slug'     => 'media',
				'screen_function' => array( $this, 'render_profile_albums_tab' ),
				'position'        => 20,
			)
		);

		// Documents sub-tab — only when the feature is switched on site-wide
		// (Pro flips `mvs_documents_enabled`). Free owns the tab; Pro renders the
		// viewer-scoped, privacy-filtered list (documents are a Pro feature and
		// their permission ladder lives there). Missing the guard would put an
		// always-empty Documents tab on every profile of a Free-only site.
		if ( \WPMediaVerse\Core\Plugin::documents_enabled() ) {
			bp_core_new_subnav_item(
				array(
					'name'            => __( 'Documents', 'wpmediaverse' ),
					'slug'            => 'documents',
					'parent_url'      => $media_link,
					'parent_slug'     => 'media',
					'screen_function' => array( $this, 'render_profile_documents_tab' ),
					'position'        => 30,
				)
			);
		}
	}

	/**
	 * Update the Media tab name with the media count (runs on bp_template_redirect).
	 *
	 * Profile-only — the group tab doesn't need a count badge because
	 * the group nav doesn't show one in the BP default theme.
	 */
	public function update_media_tab_count(): void {
		if ( ! bp_is_user() ) {
			return;
		}

		$displayed_user_id = bp_displayed_user_id();
		if ( ! $displayed_user_id ) {
			return;
		}

		// Viewer-aware count via the repository privacy gate — members/friends
		// items no longer counted for viewers outside those audiences
		// (Basecamp #9941246549).
		$media_count = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )
			->count_visible_by_author( $displayed_user_id );

		if ( $media_count > 0 ) {
			$nav_name = sprintf(
				/* translators: %s: media count */
				__( 'Media', 'wpmediaverse' ) . ' <span class="count">%s</span>',
				$media_count
			);
			buddypress()->members->nav->edit_nav( array( 'name' => $nav_name ), 'media' );
		}
	}

	/**
	 * BP screen function for the All Media subnav.
	 */
	public function render_profile_media_tab(): void {
		add_action( 'bp_template_content', array( $this, 'media_content' ) );
		bp_core_load_template( 'members/single/plugins' );
	}

	/**
	 * BP screen function for the Albums subnav. Routes to single-album
	 * view if a slug is present in the URL.
	 */
	public function render_profile_albums_tab(): void {
		$album_slug = bp_action_variable( 0 );
		if ( $album_slug ) {
			add_action(
				'bp_template_content',
				function () use ( $album_slug ) {
					$this->single_album_content( (string) $album_slug );
				}
			);
		} else {
			add_action( 'bp_template_content', array( $this, 'albums_content' ) );
		}
		bp_core_load_template( 'members/single/plugins' );
	}

	/**
	 * BP screen function for the Documents subnav.
	 */
	public function render_profile_documents_tab(): void {
		add_action( 'bp_template_content', array( $this, 'documents_content' ) );
		bp_core_load_template( 'members/single/plugins' );
	}

	/**
	 * Render the displayed member's documents inside their profile.
	 *
	 * Free owns the tab but cannot render the document ladder — rows, folders,
	 * grants and the privacy resolution all live in Pro. So this emits a seam
	 * Pro answers with the finished, VIEWER-SCOPED, privacy-filtered HTML. The
	 * viewer id travels with it precisely because the list is viewer-relative:
	 * the owner sees everything, a member sees members-level + public + anything
	 * shared with them, a stranger sees only public. Pro is responsible for
	 * filtering every row through PermissionService before it renders — Free has
	 * no way to, and must not, list documents itself.
	 */
	public function documents_content(): void {
		// Same CSS bundle the Media and Albums tabs load (mvs-frontend design
		// tokens + mvs-bp-integration, which carries the .mvs-profile-documents
		// rules). Enqueued here so the list is styled whether Pro renders it or
		// Free paints the empty state.
		$this->enqueue_assets();

		$owner_id  = (int) bp_displayed_user_id();
		$viewer_id = (int) get_current_user_id();

		/**
		 * Viewer-scoped documents HTML for a member's profile.
		 *
		 * Answered by Pro. Default '' means "nothing to show" — either the
		 * feature is inert or the viewer may see none of this member's
		 * documents — and Free renders the empty state below.
		 *
		 * @since 2.4.0
		 *
		 * @param string $html      Rendered list HTML. Default ''.
		 * @param int    $owner_id  The profile owner whose documents to list.
		 * @param int    $viewer_id The current viewer (0 when logged out).
		 */
		$html = (string) apply_filters( 'mvs_profile_documents_html', '', $owner_id, $viewer_id );

		if ( '' !== trim( $html ) ) {
			// Pro escapes each field as it builds the list.
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Pro renderer escapes every value.
			return;
		}

		$is_owner = $viewer_id > 0 && $viewer_id === $owner_id;
		$message  = $is_owner
			? __( "You haven't added any documents yet.", 'wpmediaverse' )
			: __( 'No documents to show.', 'wpmediaverse' );

		echo \WPMediaVerse\Core\Plugin::container()->get( 'template_helpers' )->render_block_empty_state( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper returns escaped markup.
			array(
				'icon'    => 'file-text',
				'message' => $message,
			)
		);
	}

	// ============================================================
	// BaseBPTabIntegration contract — profile-specific implementations.
	// ============================================================

	protected function is_authorized(): bool {
		$user_id = bp_displayed_user_id();
		return is_user_logged_in() && get_current_user_id() === (int) $user_id;
	}

	protected function fetch_media_ids( int $per_page, int $offset ): array {
		$user_id = (int) bp_displayed_user_id();
		$repo    = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );

		// Viewer-aware repository gate — replaces raw SQL that listed
		// members/friends-level media to any visitor, including logged-out
		// ones (Basecamp #9941246549).
		$total = $repo->count_visible_by_author( $user_id );

		if ( ! $total ) {
			return array(
				'ids'   => array(),
				'total' => 0,
			);
		}

		$rows = $repo->query_by_author(
			$user_id,
			array(
				'limit'  => $per_page,
				'offset' => $offset,
			)
		);

		return array(
			'ids'   => array_map( 'intval', array_column( $rows, 'media_id' ) ),
			'total' => $total,
		);
	}

	protected function get_albums_query_args( int $per_page, int $paged ): array {
		return array(
			'post_type'      => 'mvs_album',
			'post_status'    => 'publish',
			'author'         => (int) bp_displayed_user_id(),
			'posts_per_page' => $per_page,
			'paged'          => $paged,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);
	}

	protected function album_belongs_to_context( \WP_Post $album ): bool {
		return (int) $album->post_author === (int) bp_displayed_user_id();
	}

	protected function get_albums_index_url(): string {
		return trailingslashit( bp_displayed_user_domain() . 'media/albums' );
	}

	protected function empty_media_message_owner(): string {
		return __( "You haven't uploaded any media yet. Get started!", 'wpmediaverse' );
	}

	protected function empty_media_message_other(): string {
		return __( "This user hasn't uploaded any media yet.", 'wpmediaverse' );
	}

	protected function empty_albums_message_owner(): string {
		return __( "You haven't created any albums yet.", 'wpmediaverse' );
	}

	protected function empty_albums_message_other(): string {
		return __( "This user hasn't created any albums yet.", 'wpmediaverse' );
	}

	protected function empty_single_album_message_owner(): string {
		return __( 'This album is empty. Add some media!', 'wpmediaverse' );
	}

	protected function empty_single_album_message_other(): string {
		return __( 'This album is empty.', 'wpmediaverse' );
	}

	protected function load_more_data_attrs(): array {
		return array(
			'data-author' => (int) bp_displayed_user_id(),
		);
	}

	protected function extra_upload_form_fields(): string {
		return '';
	}

	protected function extra_upload_fields(): array {
		return array();
	}

	protected function render_sub_tabs( string $active ): void {
		// Profile uses BP's native subnav (Media / Albums), so no inline tabs needed.
	}

	protected function album_form_extra_fields(): string {
		return '';
	}
}
