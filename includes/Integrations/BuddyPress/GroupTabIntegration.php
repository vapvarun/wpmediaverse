<?php
/**
 * GroupTabIntegration — group media tab for BuddyPress.
 *
 * Adds a "Media" tab to BP group pages with sub-tabs for browsing media
 * and albums. The actual rendering — upload form, grid, albums, single
 * album — is shared with the profile tab via `BaseBPTabIntegration`.
 * This subclass supplies only the group-specific bits: BP subnav setup,
 * the inline sub-tab nav (BP doesn't render nested subnavs in groups),
 * scope queries (`JOIN mvs_media_meta WHERE group_id`), member check,
 * and copy.
 *
 * @package WPMediaVerse\Integrations\BuddyPress
 */

namespace WPMediaVerse\Integrations\BuddyPress;

defined( 'ABSPATH' ) || exit;

/**
 * Manages the Media tab on BuddyPress group pages.
 */
class GroupTabIntegration extends BaseBPTabIntegration {

	/**
	 * Register group tab hooks.
	 */
	public function init(): void {
		if ( ! function_exists( 'buddypress' ) || ! bp_is_active( 'groups' ) ) {
			return;
		}

		add_action( 'bp_setup_nav', array( $this, 'add_group_tab' ), 100 );
	}

	/**
	 * Add a Media subnav under the current group's nav.
	 */
	public function add_group_tab(): void {
		if ( ! function_exists( 'bp_is_group' ) || ! bp_is_group() ) {
			return;
		}

		if ( ! function_exists( 'groups_get_current_group' ) ) {
			return;
		}

		$group = groups_get_current_group();
		if ( ! $group ) {
			return;
		}

		bp_core_new_subnav_item(
			array(
				'name'            => __( 'Media', 'wpmediaverse' ),
				'slug'            => 'media',
				'parent_url'      => MediaDisplayHelper::group_url( $group ),
				'parent_slug'     => $group->slug,
				'screen_function' => array( $this, 'render_group_media_tab' ),
				'position'        => 80,
			)
		);
	}

	/**
	 * BP screen function for the group Media subnav. Internal routing
	 * picks media / albums / single-album based on `bp_action_variable`.
	 */
	public function render_group_media_tab(): void {
		$action = bp_action_variable( 0 );

		if ( 'albums' === $action ) {
			$album_slug = bp_action_variable( 1 );
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
		} else {
			add_action( 'bp_template_content', array( $this, 'media_content' ) );
		}

		bp_core_load_template( 'groups/single/plugins' );
	}

	// ============================================================
	// BaseBPTabIntegration contract — group-specific implementations.
	// ============================================================

	protected function is_authorized(): bool {
		$group = groups_get_current_group();
		if ( ! $group ) {
			return false;
		}
		return is_user_logged_in()
			&& function_exists( 'groups_is_user_member' )
			&& (bool) groups_is_user_member( get_current_user_id(), $group->id );
	}

	protected function fetch_media_ids( int $per_page, int $offset ): array {
		$group = groups_get_current_group();
		if ( ! $group ) {
			return array(
				'ids'   => array(),
				'total' => 0,
			);
		}

		global $wpdb;
		$index_table = $wpdb->prefix . 'mvs_media_index';
		$meta_table  = $wpdb->prefix . 'mvs_media_meta';

		// Viewer-scoped privacy gate, built in SQL so pagination stays honest.
		// The group tab previously listed EVERY item with this group_id
		// regardless of privacy — members/friends/private/group items leaked
		// to non-members and logged-out visitors (audit 2026-06-04). ProfileTab
		// is saved by query_by_author's pre-filter; this tab had none.
		$viewer_id       = get_current_user_id();
		$is_group_member = $viewer_id > 0
			&& function_exists( 'groups_is_user_member' )
			&& groups_is_user_member( $viewer_id, (int) $group->id );

		$priv_params = array();
		if ( $viewer_id > 0 && user_can( $viewer_id, 'moderate_mvs_media' ) ) {
			$priv_sql = '1 = 1';
		} else {
			$clauses = array( "mi.privacy = 'public'" );
			if ( $viewer_id > 0 ) {
				$clauses[]     = "mi.privacy = 'members'";
				$clauses[]     = 'mi.post_author = %d';
				$priv_params[] = $viewer_id;
			}
			if ( $is_group_member ) {
				// Group-level items are visible to members of THIS group.
				$clauses[] = "mi.privacy = 'group'";
			}
			$priv_sql = '( ' . implode( ' OR ', $clauses ) . ' )';
		}

		$base_where  = "mm.meta_key = 'group_id' AND mm.meta_value = %s AND mi.status = 'publish' AND {$priv_sql}";
		$base_params = array_merge( array( (string) $group->id ), $priv_params );

		$total = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$meta_table} mm INNER JOIN {$index_table} mi ON mm.media_id = mi.media_id WHERE {$base_where}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				...$base_params
			)
		);

		if ( ! $total ) {
			return array(
				'ids'   => array(),
				'total' => 0,
			);
		}

		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT mi.media_id FROM {$meta_table} mm INNER JOIN {$index_table} mi ON mm.media_id = mi.media_id WHERE {$base_where} ORDER BY mi.created_at DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				...array_merge( $base_params, array( $per_page, $offset ) )
			)
		);

		return array(
			'ids'   => array_map( 'intval', $ids ?: array() ),
			'total' => $total,
		);
	}

	protected function get_albums_query_args( int $per_page, int $paged ): array {
		$group_id = (int) ( groups_get_current_group()->id ?? 0 );

		return array(
			'post_type'      => 'mvs_album',
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $paged,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
				array(
					'key'   => '_mvs_group_id',
					'value' => $group_id,
					'type'  => 'NUMERIC',
				),
			),
		);
	}

	protected function album_belongs_to_context( \WP_Post $album ): bool {
		$group = groups_get_current_group();
		if ( ! $group ) {
			return false;
		}
		$album_group_id = (int) \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get( $album->ID, 'group_id' );
		return $album_group_id === (int) $group->id;
	}

	protected function get_albums_index_url(): string {
		$group = groups_get_current_group();
		return $group ? trailingslashit( MediaDisplayHelper::group_url( $group ) . 'media/albums' ) : '';
	}

	protected function empty_media_message_owner(): string {
		return __( 'No group media yet. Members can share media with the group!', 'wpmediaverse' );
	}

	protected function empty_media_message_other(): string {
		return __( 'No group media yet. Members can share media with the group!', 'wpmediaverse' );
	}

	protected function empty_albums_message_owner(): string {
		return __( 'No group albums yet.', 'wpmediaverse' );
	}

	protected function empty_albums_message_other(): string {
		return __( 'No group albums yet.', 'wpmediaverse' );
	}

	protected function empty_single_album_message_owner(): string {
		return __( 'This album is empty. Add some media!', 'wpmediaverse' );
	}

	protected function empty_single_album_message_other(): string {
		return __( 'This album is empty.', 'wpmediaverse' );
	}

	protected function load_more_data_attrs(): array {
		// Group scope is enforced server-side via meta join — no need to
		// pass author/scope on the Load More button.
		return array();
	}

	protected function extra_upload_form_fields(): string {
		// The group_id is sent as an extra FormData field (see
		// extra_upload_fields()), not a hidden input, since the uploader builds
		// FormData programmatically. Returning empty here is intentional.
		return '';
	}

	protected function extra_upload_fields(): array {
		$group = groups_get_current_group();
		if ( ! $group ) {
			return array();
		}
		return array( 'group_id' => (int) $group->id );
	}

	/**
	 * Render the inline sub-tab navigation. BP doesn't show nested
	 * subnavs in the group context, so we render our own.
	 *
	 * @param string $active 'media' or 'albums'.
	 */
	protected function render_sub_tabs( string $active ): void {
		$group = groups_get_current_group();
		if ( ! $group ) {
			return;
		}

		$group_url  = MediaDisplayHelper::group_url( $group );
		$media_url  = trailingslashit( $group_url . 'media' );
		$albums_url = trailingslashit( $group_url . 'media/albums' );

		echo '<nav class="mvs-bp-sub-tabs">';
		echo '<a href="' . esc_url( $media_url ) . '" class="mvs-bp-sub-tab' . ( 'media' === $active ? ' active' : '' ) . '">';
		esc_html_e( 'Media', 'wpmediaverse' );
		echo '</a>';
		echo '<a href="' . esc_url( $albums_url ) . '" class="mvs-bp-sub-tab' . ( 'albums' === $active ? ' active' : '' ) . '">';
		esc_html_e( 'Albums', 'wpmediaverse' );
		echo '</a>';
		echo '</nav>';
	}

	protected function album_form_extra_fields(): string {
		$group = groups_get_current_group();
		if ( ! $group ) {
			return '';
		}
		return '<input type="hidden" id="mvs-bp-group-id" value="' . (int) $group->id . '" />';
	}
}
