<?php
/**
 * BaseBPTabIntegration — shared base for ProfileTab + GroupTab.
 *
 * Both `ProfileTabIntegration` and `GroupTabIntegration` mount a "Media"
 * tab on a BuddyPress surface (member profile vs group page). The tabs
 * render the same three views — media grid, albums grid, single album —
 * differing only in:
 *   - which BP nav surface they hook into,
 *   - the scope query that selects which media/albums to show,
 *   - the "is the viewer authorized to upload" check,
 *   - the back-link URL,
 *   - whether they need an inline sub-tab navigation (group does; profile
 *     uses BP's native subnav so it doesn't),
 *   - empty-state copy.
 *
 * Everything else — asset enqueue, upload form HTML+JS, album creation
 * form, album upload form HTML+JS, grid rendering, load-more button,
 * back link, album items grid — is identical and now lives here.
 *
 * Subclasses provide the context-specific bits via the abstract methods
 * below. The public template methods (`media_content`, `albums_content`,
 * `single_album_content`) are `final` so subclasses can't accidentally
 * override the orchestration.
 *
 * @package WPMediaVerse\Integrations\BuddyPress
 * @since   1.2.0
 */

namespace WPMediaVerse\Integrations\BuddyPress;

defined( 'ABSPATH' ) || exit;

/**
 * Shared rendering + flow for BP Media tabs (profile + group).
 */
abstract class BaseBPTabIntegration {

	// ============================================================
	// Subclass contract — context-specific bits only.
	// ============================================================

	/**
	 * Hook BP nav setup. Each subclass picks the appropriate
	 * `bp_setup_nav` priority + nav helper (`bp_core_new_nav_item`
	 * for profile parent nav, `bp_core_new_subnav_item` only for group).
	 */
	abstract public function init(): void;

	/**
	 * Authorization check — can the current viewer upload here?
	 *
	 * For profile: viewing your own profile.
	 * For group:   member of the group.
	 */
	abstract protected function is_authorized(): bool;

	/**
	 * Scope-specific media list query.
	 *
	 * Profile: `mvs_media_index WHERE post_author = $user_id`.
	 * Group:   `mvs_media_index JOIN mvs_media_meta WHERE meta_key='group_id' AND meta_value=$group_id`.
	 *
	 * @param int $per_page Items per page.
	 * @param int $offset   SQL OFFSET.
	 * @return array{ids: int[], total: int}
	 */
	abstract protected function fetch_media_ids( int $per_page, int $offset ): array;

	/**
	 * Scope-specific WP_Query args for the albums list.
	 *
	 * Profile: `'author' => $user_id`.
	 * Group:   `'meta_query' => [ ['key'=>'_mvs_group_id','value'=>$group_id] ]`.
	 *
	 * @param int $per_page Albums per page.
	 * @param int $paged    Current page.
	 * @return array<string, mixed>
	 */
	abstract protected function get_albums_query_args( int $per_page, int $paged ): array;

	/**
	 * Single-album access check.
	 *
	 * Profile: `$album->post_author === $user_id`.
	 * Group:   `mvs_media_meta.group_id === $group_id`.
	 *
	 * @param \WP_Post $album Album CPT post.
	 */
	abstract protected function album_belongs_to_context( \WP_Post $album ): bool;

	/**
	 * Albums index URL — used by the single-album view's back link.
	 */
	abstract protected function get_albums_index_url(): string;

	/**
	 * Empty-state messages. Owner / non-owner variants because the
	 * copy says different things to the user themselves vs visitors.
	 */
	abstract protected function empty_media_message_owner(): string;
	abstract protected function empty_media_message_other(): string;
	abstract protected function empty_albums_message_owner(): string;
	abstract protected function empty_albums_message_other(): string;
	abstract protected function empty_single_album_message_owner(): string;
	abstract protected function empty_single_album_message_other(): string;

	/**
	 * Data attributes for the Load More button. Profile passes
	 * `data-author=$user_id`; group passes empty (server-side scope
	 * already filters by group meta).
	 *
	 * @return array<string, scalar>
	 */
	abstract protected function load_more_data_attrs(): array;

	/**
	 * Extra hidden form fields for upload (e.g. `<input type="hidden" name="group_id" />`).
	 * Profile returns empty; group adds the group-id hidden input.
	 */
	abstract protected function extra_upload_form_fields(): string;

	/**
	 * Extra fields appended to each media-upload FormData. Profile returns an
	 * empty array; group returns `[ 'group_id' => N ]`. Localized to JS and
	 * appended client-side, so no JS is injected from PHP.
	 *
	 * @return array<string, scalar>
	 */
	abstract protected function extra_upload_fields(): array;

	/**
	 * Render any tab navigation BEFORE the main content. Group renders
	 * an inline `<nav>` because BP doesn't show nested subnavs in groups.
	 * Profile uses BP's native subnav so this returns nothing.
	 *
	 * @param string $active 'media' or 'albums'.
	 */
	abstract protected function render_sub_tabs( string $active ): void;

	/**
	 * Hidden `<input>` to inject into the album-creation form (e.g.
	 * group_id for the group context). Profile returns empty.
	 */
	abstract protected function album_form_extra_fields(): string;

	// ============================================================
	// Public template methods — final so the orchestration is fixed.
	// Subclasses customize via the abstract hooks above.
	// ============================================================

	/**
	 * Render the "Media" tab body — upload form (if authorized) + grid + load more.
	 */
	final public function media_content(): void {
		$this->enqueue_assets();
		echo '<div class="mvs-bp-screen">';
		$this->render_sub_tabs( 'media' );

		if ( $this->is_authorized() ) {
			$this->render_upload_form();
		}

		$paged    = isset( $_GET['mpage'] ) ? absint( $_GET['mpage'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$per_page = absint( get_option( 'mvs_items_per_page', 12 ) );
		$offset   = ( $paged - 1 ) * $per_page;

		$result = $this->fetch_media_ids( $per_page, $offset );

		if ( empty( $result['total'] ) ) {
			$this->render_empty_media();
			echo '</div>';
			return;
		}

		$this->render_media_grid( $result['ids'] );
		$this->render_load_more( $paged, $per_page, (int) $result['total'] );

		echo '</div>';
	}

	/**
	 * Render the "Albums" tab body — create-album form (if authorized) + grid.
	 */
	final public function albums_content(): void {
		$this->enqueue_assets();
		echo '<div class="mvs-bp-screen">';
		$this->render_sub_tabs( 'albums' );

		if ( $this->is_authorized() ) {
			$this->render_album_creation_form();
		}

		$paged    = isset( $_GET['mpage'] ) ? absint( $_GET['mpage'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$per_page = absint( get_option( 'mvs_items_per_page', 12 ) );

		$query = new \WP_Query( $this->get_albums_query_args( $per_page, $paged ) );

		if ( ! $query->have_posts() ) {
			$this->render_empty_albums();
			echo '</div>';
			return;
		}

		$this->render_albums_grid( $query );
		$this->render_albums_pagination( $query, $paged );

		wp_reset_postdata();
		echo '</div>';
	}

	/**
	 * Render the single-album view — back link + header + items grid + (optional) upload.
	 *
	 * @param string $album_slug Album slug from `bp_action_variable`.
	 */
	final public function single_album_content( string $album_slug ): void {
		$this->enqueue_assets();
		echo '<div class="mvs-bp-screen">';

		$album = $album_slug ? get_page_by_path( $album_slug, OBJECT, 'mvs_album' ) : null;

		if ( ! $album || ! $this->album_belongs_to_context( $album ) ) {
			echo '<div class="mvs-empty-state"><p>' . esc_html__( 'Album not found.', 'wpmediaverse' ) . '</p></div>';
			echo '</div>';
			return;
		}

		$this->render_back_link();
		$this->render_album_header( $album );

		$items = $this->fetch_album_items( (int) $album->ID );

		if ( $this->is_authorized() ) {
			$this->render_album_upload_form( (int) $album->ID );
		}

		if ( empty( $items ) ) {
			$this->render_empty_single_album();
		} else {
			$this->render_album_items_grid( $items );
		}

		echo '</div>';
	}

	// ============================================================
	// Shared rendering primitives — same for both subclasses.
	// ============================================================

	/**
	 * Enqueue the frontend + BP-integration CSS/JS bundle.
	 * Idempotent: WordPress dedupes by handle.
	 */
	protected function enqueue_assets(): void {
		wp_enqueue_style( 'mvs-frontend' );
		wp_enqueue_style( 'mvs-bp-integration' );
		wp_enqueue_script( 'mvs-bp-actions' );
		wp_localize_script(
			'mvs-bp-actions',
			'mvsBpActions',
			array(
				'restUrl' => esc_url_raw( rest_url( 'mvs/v1/' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
			)
		);
	}

	/**
	 * Render the "Upload Media" button + dropzone form.
	 *
	 * The behavior lives in assets/js/frontend/bp-tab-upload.js. Context-specific
	 * FormData fields (e.g. the group's `group_id`) come from the subclass via
	 * extra_upload_fields() and are localized to JS, not injected as inline JS.
	 */
	protected function render_upload_form(): void {
		$this->enqueue_upload_assets();
		?>
		<div class="mvs-bp-profile-actions">
			<button type="button" id="mvs-bp-upload-btn" class="mvs-btn">
				<i data-lucide="upload-cloud" aria-hidden="true"></i> <?php esc_html_e( 'Upload Media', 'wpmediaverse' ); ?>
			</button>
		</div>

		<div class="mvs-bp-upload-wrap" id="mvs-bp-upload-wrap" style="display:none;">
			<input type="file" multiple accept="image/*,video/*,audio/*" class="mvs-bp-file-input" id="mvs-bp-file-input" style="display:none" />
			<div class="mvs-bp-dropzone" id="mvs-bp-dropzone">
				<i data-lucide="upload-cloud" aria-hidden="true"></i>
				<span class="mvs-bp-dropzone-text"><?php esc_html_e( 'Drop files here or click to upload', 'wpmediaverse' ); ?></span>
			</div>
			<div id="mvs-bp-upload-preview" class="mvs-bp-upload-preview"></div>
			<div class="mvs-bp-upload-status" id="mvs-bp-upload-status" style="display:none;"></div>
			<div class="mvs-bp-upload-form-actions">
				<button type="button" id="mvs-bp-upload-cancel" class="mvs-btn mvs-btn-secondary"><?php esc_html_e( 'Cancel', 'wpmediaverse' ); ?></button>
			</div>
			<?php echo $this->extra_upload_form_fields(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- subclass returns escaped HTML ?>
		</div>
		<?php
	}

	/**
	 * Enqueue + localize the shared BP tab uploader script. Idempotent (the
	 * handle dedupes), so calling it from both upload renderers is safe.
	 */
	protected function enqueue_upload_assets(): void {
		wp_enqueue_script(
			'mvs-bp-tab-upload',
			MVS_PLUGIN_URL . 'assets/js/frontend/bp-tab-upload.js',
			array(),
			MVS_VERSION,
			array( 'in_footer' => true )
		);
		wp_localize_script(
			'mvs-bp-tab-upload',
			'mvsBpUpload',
			array(
				'restUrl'     => esc_url_raw( rest_url( 'mvs/v1/' ) ),
				'nonce'       => wp_create_nonce( 'wp_rest' ),
				'extraFields' => $this->extra_upload_fields(),
				'i18n'        => array(
					/* translators: 1: current file number, 2: total files. */
					'uploading'     => __( 'Uploading %1$d of %2$d...', 'wpmediaverse' ),
					/* translators: %d: number of files. */
					'uploaded'      => __( '%d file(s) uploaded!', 'wpmediaverse' ),
					'addingToAlbum' => __( 'Adding to album...', 'wpmediaverse' ),
					/* translators: %d: number of files. */
					'addedToAlbum'  => __( '%d file(s) added to album!', 'wpmediaverse' ),
				),
			)
		);
	}

	/**
	 * Render the "Create Album" button + hidden inline form. Used by
	 * the JS in `assets/js/bp-actions.js` that posts to `mvs/v1/albums`.
	 */
	protected function render_album_creation_form(): void {
		echo '<div class="mvs-bp-profile-actions">';
		echo '<button type="button" id="mvs-bp-create-album-btn" class="mvs-btn">';
		echo '<i data-lucide="plus" aria-hidden="true"></i> ' . esc_html__( 'Create Album', 'wpmediaverse' );
		echo '</button>';
		echo '</div>';

		echo '<div id="mvs-bp-album-form" class="mvs-bp-album-form" style="display:none;">';
		echo '<div class="mvs-bp-album-form-inner">';
		echo '<label for="mvs-bp-album-title">' . esc_html__( 'Album Name', 'wpmediaverse' ) . '</label>';
		echo '<input type="text" id="mvs-bp-album-title" placeholder="' . esc_attr__( 'Enter album name...', 'wpmediaverse' ) . '" />';
		echo '<label for="mvs-bp-album-desc">' . esc_html__( 'Description (optional)', 'wpmediaverse' ) . '</label>';
		echo '<textarea id="mvs-bp-album-desc" rows="2" placeholder="' . esc_attr__( 'Album description...', 'wpmediaverse' ) . '"></textarea>';
		echo $this->album_form_extra_fields(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- subclass returns escaped HTML
		echo '<div class="mvs-bp-album-form-actions">';
		echo '<button type="button" id="mvs-bp-album-save" class="mvs-btn mvs-btn-primary">' . esc_html__( 'Create', 'wpmediaverse' ) . '</button>';
		echo '<button type="button" id="mvs-bp-album-cancel" class="mvs-btn mvs-btn-secondary">' . esc_html__( 'Cancel', 'wpmediaverse' ) . '</button>';
		echo '</div>';
		echo '<div id="mvs-bp-album-msg" class="mvs-bp-album-msg"></div>';
		echo '</div>';
		echo '</div>';
	}

	/**
	 * Render the media grid for the supplied media IDs.
	 *
	 * @param int[] $media_ids Media IDs to render.
	 */
	protected function render_media_grid( array $media_ids ): void {
		$stats_map = \WPMediaVerse\Core\Plugin::container()->get( 'template_helpers' )->bulk_get_stats( $media_ids );

		echo '<div class="mvs-media-grid mvs-cols-3 mvs-feed" data-mvs-grid-container data-show-actions="1">';
		foreach ( $media_ids as $mid ) {
			\WPMediaVerse\Core\Plugin::container()->get( 'template_helpers' )->render_grid_item(
				$mid,
				$stats_map[ $mid ] ?? array(),
				array(
					'show_author'  => true,
					'show_actions' => true,
				)
			);
		}
		echo '</div>';
	}

	/**
	 * Render the "Load More" button if more pages exist.
	 *
	 * @param int $paged    Current page.
	 * @param int $per_page Items per page.
	 * @param int $total    Total items in the scope.
	 */
	protected function render_load_more( int $paged, int $per_page, int $total ): void {
		$max_pages = $per_page > 0 ? (int) ceil( $total / $per_page ) : 1;
		if ( $max_pages <= 1 ) {
			return;
		}

		$attrs = array_merge(
			array(
				'data-rest-url'     => rest_url( 'mvs/v1/' ),
				'data-nonce'        => wp_create_nonce( 'wp_rest' ),
				'data-page'         => $paged,
				'data-per-page'     => $per_page,
				'data-endpoint'     => 'media',
				'data-layout'       => 'grid',
				'data-author'       => '',
				'data-scope'        => '',
				'data-group-covers' => 'true',
			),
			$this->load_more_data_attrs()
		);

		echo '<div class="mvs-load-more">';
		echo '<button type="button" class="mvs-load-more-btn"';
		foreach ( $attrs as $k => $v ) {
			echo ' ' . esc_attr( $k ) . '="' . esc_attr( (string) $v ) . '"';
		}
		echo '>';
		echo '<span class="mvs-load-more-label">' . esc_html__( 'Load More', 'wpmediaverse' ) . '</span>';
		echo '<span class="mvs-load-more-spinner"></span>';
		echo '</button>';
		echo '</div>';
		echo '<p class="mvs-load-more-end" hidden>' . esc_html__( "You're all caught up!", 'wpmediaverse' ) . '</p>';
	}

	/**
	 * Render the album grid (called by `albums_content`).
	 *
	 * @param \WP_Query $query Albums query (already executed).
	 */
	protected function render_albums_grid( \WP_Query $query ): void {
		$album_svc = \WPMediaVerse\Core\Plugin::container()->get( 'albums' );

		echo '<div class="mvs-media-grid mvs-cols-3 mvs-feed">';
		while ( $query->have_posts() ) {
			$query->the_post();
			$album_id   = (int) get_the_ID();
			$cover_url  = $album_svc->get_cover_url( $album_id );
			$item_count = $album_svc->get_item_count( $album_id );
			$album_link = get_permalink( $album_id );

			echo '<div class="mvs-grid-item mvs-grid-item--album" data-album-id="' . esc_attr( (string) $album_id ) . '">';

			if ( $this->is_authorized() ) {
				echo '<div class="mvs-grid-item-actions">';
				echo '<button type="button" class="mvs-grid-item-action mvs-bp-album-edit" data-album-id="' . esc_attr( (string) $album_id ) . '" data-album-title="' . esc_attr( get_the_title() ) . '" aria-label="' . esc_attr__( 'Edit album', 'wpmediaverse' ) . '" title="' . esc_attr__( 'Edit album', 'wpmediaverse' ) . '">';
				echo '<i data-lucide="pencil" aria-hidden="true"></i>';
				echo '</button>';
				echo '<button type="button" class="mvs-grid-item-action mvs-grid-item-action--danger mvs-bp-album-delete" data-album-id="' . esc_attr( (string) $album_id ) . '" aria-label="' . esc_attr__( 'Delete album', 'wpmediaverse' ) . '" title="' . esc_attr__( 'Delete album', 'wpmediaverse' ) . '">';
				echo '<i data-lucide="trash-2" aria-hidden="true"></i>';
				echo '</button>';
				echo '</div>';
			}

			echo '<a href="' . esc_url( $album_link ) . '" class="mvs-grid-item-link">';
			if ( $cover_url ) {
				echo '<img src="' . esc_url( $cover_url ) . '" alt="' . esc_attr( get_the_title() ) . '" loading="lazy" />';
			} else {
				echo '<div class="mvs-grid-item-placeholder mvs-grid-item-placeholder--album"><span class="mvs-grid-album-icon">&#128193;</span></div>';
			}
			echo '<div class="mvs-grid-item-overlay"><div class="mvs-grid-item-stats">';
			echo '<span class="mvs-grid-stat">&#x1F5BC;&#xFE0F; ' . esc_html( (string) $item_count ) . '</span>';
			echo '</div></div>';
			echo '</a>';
			echo '<div class="mvs-grid-item-info"><span class="mvs-grid-item-title">' . esc_html( get_the_title() ) . '</span></div>';
			echo '</div>';
		}
		echo '</div>';
	}

	/**
	 * Render numeric pagination under the albums grid.
	 *
	 * @param \WP_Query $query Albums query.
	 * @param int       $paged Current page.
	 */
	protected function render_albums_pagination( \WP_Query $query, int $paged ): void {
		if ( $query->max_num_pages <= 1 ) {
			return;
		}
		$pagination = paginate_links(
			array(
				'base'    => add_query_arg( 'mpage', '%#%' ),
				'format'  => '',
				'current' => $paged,
				'total'   => $query->max_num_pages,
			)
		);
		if ( $pagination ) {
			echo '<div class="mvs-pagination">' . wp_kses_post( $pagination ) . '</div>';
		}
	}

	/**
	 * Render the back link at the top of a single album.
	 */
	protected function render_back_link(): void {
		echo '<div class="mvs-bp-back-link">';
		echo '<a href="' . esc_url( $this->get_albums_index_url() ) . '">&larr; ' . esc_html__( 'Back to Albums', 'wpmediaverse' ) . '</a>';
		echo '</div>';
	}

	/**
	 * Render the album header (title + description + item count).
	 *
	 * @param \WP_Post $album Album CPT post.
	 */
	protected function render_album_header( \WP_Post $album ): void {
		$items      = $this->fetch_album_items( (int) $album->ID );
		$item_count = count( $items );

		echo '<div class="mvs-album-header">';
		echo '<h2 class="mvs-album-title">' . esc_html( $album->post_title ) . '</h2>';
		if ( $album->post_content ) {
			echo '<div class="mvs-album-description">' . wp_kses_post( $album->post_content ) . '</div>';
		}
		echo '<span class="mvs-album-count">';
		printf(
			/* translators: %d: number of items */
			esc_html( _n( '%d item', '%d items', $item_count, 'wpmediaverse' ) ),
			(int) $item_count
		);
		echo '</span>';
		echo '</div>';
	}

	/**
	 * Render the album-upload "Add Media" button + hidden dropzone.
	 *
	 * The behavior lives in assets/js/frontend/bp-tab-upload.js, which reads the
	 * album id from the wrap's data-album-id attribute. Context-specific upload
	 * fields come from extra_upload_fields() (localized, not inline JS).
	 *
	 * @param int $album_id Album CPT ID.
	 */
	protected function render_album_upload_form( int $album_id ): void {
		$this->enqueue_upload_assets();

		echo '<div class="mvs-bp-profile-actions">';
		echo '<button type="button" id="mvs-album-upload-btn" class="mvs-btn">';
		echo '<i data-lucide="plus" aria-hidden="true"></i> ' . esc_html__( 'Add Media', 'wpmediaverse' );
		echo '</button>';
		echo '</div>';

		echo '<div id="mvs-album-upload-wrap" class="mvs-bp-upload-wrap" style="display:none;" data-album-id="' . (int) $album_id . '">';
		echo '<input type="file" multiple accept="image/*,video/*,audio/*" id="mvs-album-file-input" style="display:none" />';
		echo '<div class="mvs-bp-dropzone" id="mvs-album-dropzone">';
		echo '<i data-lucide="upload-cloud" aria-hidden="true"></i>';
		echo '<span class="mvs-bp-dropzone-text">' . esc_html__( 'Drop files here or click to upload into this album', 'wpmediaverse' ) . '</span>';
		echo '</div>';
		echo '<div id="mvs-album-upload-preview" class="mvs-bp-upload-preview"></div>';
		echo '<div id="mvs-album-upload-status" class="mvs-bp-upload-status" style="display:none;"></div>';
		echo '<div class="mvs-bp-upload-form-actions">';
		echo '<button type="button" id="mvs-album-upload-cancel" class="mvs-btn mvs-btn-secondary">' . esc_html__( 'Cancel', 'wpmediaverse' ) . '</button>';
		echo '</div>';
		echo '</div>';
	}

	/**
	 * Render the items grid inside a single album view.
	 *
	 * @param int[] $media_ids Media IDs (already sorted by `position`).
	 */
	protected function render_album_items_grid( array $media_ids ): void {
		echo '<div class="mvs-media-grid mvs-cols-3" data-show-actions="1">';
		foreach ( $media_ids as $media_id ) {
			\WPMediaVerse\Core\Plugin::container()->get( 'template_helpers' )->render_grid_item(
				(int) $media_id,
				array(),
				array(
					'show_author'  => false,
					'show_overlay' => false,
					'show_actions' => true,
				)
			);
		}
		echo '</div>';
	}

	/**
	 * Fetch ordered media IDs for an album.
	 *
	 * @param int $album_id Album CPT ID.
	 * @return int[]
	 */
	protected function fetch_album_items( int $album_id ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'mvs_album_items';
		$ids   = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT media_id FROM {$table} WHERE album_id = %d ORDER BY position ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$album_id
			)
		);
		return array_map( 'intval', $ids ?: array() );
	}

	// ============================================================
	// Empty-state renderers — share markup, vary copy via subclass.
	// ============================================================

	protected function render_empty_media(): void {
		echo '<div class="mvs-empty-state">';
		echo '<i data-lucide="images" aria-hidden="true"></i>';
		echo '<p>' . esc_html( $this->is_authorized() ? $this->empty_media_message_owner() : $this->empty_media_message_other() ) . '</p>';
		echo '</div>';
	}

	protected function render_empty_albums(): void {
		echo '<div class="mvs-empty-state">';
		echo '<i data-lucide="images" aria-hidden="true"></i>';
		echo '<p>' . esc_html( $this->is_authorized() ? $this->empty_albums_message_owner() : $this->empty_albums_message_other() ) . '</p>';
		echo '</div>';
	}

	protected function render_empty_single_album(): void {
		echo '<div class="mvs-empty-state">';
		echo '<i data-lucide="images" aria-hidden="true"></i>';
		echo '<p>' . esc_html( $this->is_authorized() ? $this->empty_single_album_message_owner() : $this->empty_single_album_message_other() ) . '</p>';
		echo '</div>';
	}
}
