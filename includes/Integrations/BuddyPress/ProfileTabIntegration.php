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
