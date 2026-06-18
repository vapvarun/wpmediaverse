<?php
/**
 * Template: Media Explore/Archive.
 *
 * Queries mvs_media_index directly instead of WP_Query.
 * Unified feed displaying both media items and albums (Instagram-style).
 * Override by copying to your-theme/wpmediaverse/explore.php
 *
 * @package WPMediaVerse
 */

defined( 'ABSPATH' ) || exit;

get_header();

do_action( 'mvs_before_content' );

include MVS_PLUGIN_DIR . 'templates/partials/router-region-open.php';

// Archive URL (base media page).
$mvs_archive_url = home_url( '/media/' );
?>
<?php // Logged-out CTA banner. ?>
<?php if ( ! is_user_logged_in() ) : ?>
	<div class="mvs-logged-out-banner" id="mvs-logged-out-banner">
		<div class="mvs-logged-out-banner__content">
			<strong><?php esc_html_e( 'Join the community', 'wpmediaverse' ); ?></strong>
			<span><?php esc_html_e( 'Upload, share, and discover media', 'wpmediaverse' ); ?></span>
		</div>
		<div class="mvs-logged-out-banner__actions">
			<a href="<?php echo esc_url( wp_login_url( $mvs_archive_url ) ); ?>" class="mvs-btn mvs-btn--primary mvs-btn--small">
				<?php esc_html_e( 'Log In', 'wpmediaverse' ); ?>
			</a>
			<?php if ( get_option( 'users_can_register' ) ) : ?>
				<a href="<?php echo esc_url( wp_registration_url() ); ?>" class="mvs-btn mvs-btn--secondary mvs-btn--small">
					<?php esc_html_e( 'Register', 'wpmediaverse' ); ?>
				</a>
			<?php endif; ?>
		</div>
		<button type="button" class="mvs-logged-out-banner__close" id="mvs-logged-out-banner-close"
			aria-label="<?php esc_attr_e( 'Dismiss', 'wpmediaverse' ); ?>">&times;</button>
	</div>
	<?php wp_enqueue_script( 'mvs-dismissible' ); ?>
<?php endif; ?>

<div class="mvs-explore-page">
	<?php
	// Profile user detection (set by TemplateLoader).
	$mvs_profile = isset( $GLOBALS['mvs_profile_user'] ) ? $GLOBALS['mvs_profile_user'] : null;
	?>

	<?php if ( ! $mvs_profile ) : ?>
	<header class="mvs-explore-header">
		<h1>
		<?php
		// Check for tag/category filter via query vars.
		$mvs_filter_tag = get_query_var( 'mvs_tag', '' );
		if ( ! $mvs_filter_tag && isset( $_GET['mvs_tag'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$mvs_filter_tag = sanitize_text_field( wp_unslash( $_GET['mvs_tag'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		}
		$mvs_filter_cat = get_query_var( 'mvs_category', '' );

		if ( $mvs_filter_tag ) {
			$tag_term = get_term_by( 'slug', $mvs_filter_tag, 'mvs_tag' );
			printf(
				/* translators: %s: tag name */
				esc_html__( 'Tag: %s', 'wpmediaverse' ),
				esc_html( $tag_term ? $tag_term->name : $mvs_filter_tag )
			);
		} elseif ( $mvs_filter_cat ) {
			$cat_term = get_term_by( 'slug', $mvs_filter_cat, 'mvs_category' );
			printf(
				/* translators: %s: category name */
				esc_html__( 'Category: %s', 'wpmediaverse' ),
				esc_html( $cat_term ? $cat_term->name : $mvs_filter_cat )
			);
		} else {
			esc_html_e( 'Explore', 'wpmediaverse' );
		}
		?>
		</h1>
	</header>
	<?php endif; ?>

	<?php
	if ( $mvs_profile ) :
		$mvs_profile_post_count = \WPMediaVerse\Core\Plugin::container()
			->get( 'media_repository' )
			->count_by_author( (int) $mvs_profile->ID, 'publish' );
		$mvs_follow_counts      = array(
			'followers' => 0,
			'following' => 0,
		);
		if ( class_exists( '\WPMediaVerse\Core\Plugin' ) ) {
			$mvs_container = \WPMediaVerse\Core\Plugin::container();
			if ( $mvs_container->has( 'follows' ) ) {
				$mvs_follow_counts = $mvs_container->get( 'follows' )->get_counts( $mvs_profile->ID );
			}
		}
		$mvs_is_own_profile = is_user_logged_in() && get_current_user_id() === $mvs_profile->ID;
		$mvs_dashboard_id   = (int) get_option( 'mvs_page_dashboard', 0 );
		$mvs_dashboard_link = $mvs_dashboard_id ? get_permalink( $mvs_dashboard_id ) : '';
		if ( ! $mvs_dashboard_link ) {
			$mvs_dash_page      = get_page_by_path( 'my-media' );
			$mvs_dashboard_link = $mvs_dash_page ? get_permalink( $mvs_dash_page ) : home_url( '/' );
		}
		?>
	<div class="mvs-profile-header-card">
		<img class="mvs-profile-header-avatar" src="<?php echo esc_url( get_avatar_url( $mvs_profile->ID, array( 'size' => 96 ) ) ); ?>"
			alt="<?php echo esc_attr( $mvs_profile->display_name ); ?>" width="96" height="96" />
		<div class="mvs-profile-header-info">
			<div class="mvs-profile-header-top">
				<h2 class="mvs-profile-header-name"><?php echo esc_html( $mvs_profile->display_name ); ?></h2>
				<?php if ( $mvs_is_own_profile ) : ?>
					<a class="mvs-btn mvs-btn--secondary mvs-btn--small" href="<?php echo esc_url( $mvs_dashboard_link ); ?>">
						<?php esc_html_e( 'Edit Profile', 'wpmediaverse' ); ?>
					</a>
				<?php elseif ( is_user_logged_in() ) : ?>
					<?php
					$mvs_profile_id     = $mvs_profile->ID;
					$mvs_is_own_profile = false;
					include MVS_PLUGIN_DIR . 'templates/partials/profile-actions.php';
					?>
				<?php endif; ?>
			</div>
			<div class="mvs-profile-header-stats">
				<span><strong><?php echo esc_html( number_format_i18n( $mvs_profile_post_count ) ); ?></strong> <?php esc_html_e( 'media', 'wpmediaverse' ); ?></span>
				<span><strong><?php echo esc_html( number_format_i18n( $mvs_follow_counts['followers'] ) ); ?></strong> <?php esc_html_e( 'followers', 'wpmediaverse' ); ?></span>
				<span><strong><?php echo esc_html( number_format_i18n( $mvs_follow_counts['following'] ) ); ?></strong> <?php esc_html_e( 'following', 'wpmediaverse' ); ?></span>
			</div>
			<?php if ( $mvs_profile->description ) : ?>
				<p class="mvs-profile-header-bio"><?php echo esc_html( $mvs_profile->description ); ?></p>
			<?php endif; ?>
		</div>
	</div>
	<?php endif; ?>

	<!-- Search Bar with Media/Users toggle -->
	<div class="mvs-explore-search">
		<form method="get" action="<?php echo esc_url( $mvs_archive_url ); ?>" id="mvs-explore-search-form">
			<div class="mvs-search-bar">
				<div class="mvs-search-mode" role="tablist" aria-label="<?php esc_attr_e( 'Search mode', 'wpmediaverse' ); ?>">
					<button type="button" class="mvs-search-mode-btn active" data-search-mode="media" role="tab" aria-selected="true"><?php esc_html_e( 'Media', 'wpmediaverse' ); ?></button>
					<button type="button" class="mvs-search-mode-btn" data-search-mode="users" role="tab" aria-selected="false"><?php esc_html_e( 'People', 'wpmediaverse' ); ?></button>
				</div>
				<div class="mvs-search-field">
					<svg class="mvs-search-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
					<label for="mvs-search-input" class="screen-reader-text"><?php esc_html_e( 'Search media', 'wpmediaverse' ); ?></label>
					<input type="text" name="s" placeholder="<?php esc_attr_e( 'Search media...', 'wpmediaverse' ); ?>"
						value="<?php echo isset( $_GET['s'] ) ? esc_attr( sanitize_text_field( wp_unslash( $_GET['s'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification ?>" id="mvs-search-input" />
				</div>
			</div>
		</form>
		<!-- User search results (populated via safe DOM methods) -->
		<div class="mvs-user-search-results" id="mvs-user-search-results" style="display:none;"></div>
	</div>
	<?php wp_enqueue_script( 'mvs-explore-search' ); ?>

	<!-- Tag Cloud (Interactivity API) -->
	<?php
	$mvs_explore_ctx = array(
		'restUrl'    => esc_url_raw( rest_url( 'mvs/v1/' ) ),
		'archiveUrl' => esc_url( $mvs_archive_url ),
		'activeTag'  => $mvs_filter_tag ?? ( isset( $_GET['mvs_tag'] ) ? sanitize_text_field( wp_unslash( $_GET['mvs_tag'] ) ) : '' ), // phpcs:ignore WordPress.Security.NonceVerification
		'tags'       => array(),
		'loaded'     => false,
	);
	?>
	<div class="mvs-tag-cloud"
		data-wp-interactive="mvs/explore"
		<?php echo wp_interactivity_data_wp_context( $mvs_explore_ctx ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		data-wp-init="callbacks.init">
		<a class="mvs-tag-cloud-item <?php echo empty( $mvs_explore_ctx['activeTag'] ) && empty( $_GET['s'] ) ? 'active' : ''; // phpcs:ignore WordPress.Security.NonceVerification ?>"
			href="<?php echo esc_url( $mvs_archive_url ); ?>"><?php esc_html_e( 'All', 'wpmediaverse' ); ?></a>
		<template data-wp-each="context.tags">
			<a class="mvs-tag-cloud-item" href="#"
				data-wp-bind--href="context.item.href"
				data-wp-text="context.item.name"
				data-wp-class--active="context.item.active"
				role="link"></a>
		</template>
	</div>

	<?php
	// --- Query media via MediaRepository (TIER-D query engine) ---
	$per_page = absint( get_option( 'mvs_items_per_page', 12 ) );
	$paged    = max( 1, absint( get_query_var( 'paged' ) ?: get_query_var( 'page' ) ) );
	$offset   = ( $paged - 1 ) * $per_page;

	$mvs_search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

	// Privacy scope by viewer: anon -> public only; logged-in non-moderator ->
	// public + members + own; moderator -> no privacy filter.
	if ( ! is_user_logged_in() ) {
		$mvs_privacy   = 'public';
		$mvs_viewer_id = 0;
	} elseif ( ! current_user_can( 'moderate_mvs_media' ) ) {
		$mvs_privacy   = 'visible';
		$mvs_viewer_id = get_current_user_id();
	} else {
		$mvs_privacy   = 'any';
		$mvs_viewer_id = 0;
	}

	$mvs_query_args = array(
		'status'                  => 'publish',
		'moderation_status'       => 'approved',
		'search'                  => $mvs_search,
		'privacy'                 => $mvs_privacy,
		'viewer_id'               => $mvs_viewer_id,
		'exclude_non_cover_group' => true,
		'orderby'                 => 'created_at',
		'order'                   => 'DESC',
		'limit'                   => $per_page,
		'offset'                  => $offset,
	);

	// Profile user filter.
	if ( $mvs_profile ) {
		$mvs_query_args['author_id'] = (int) $mvs_profile->ID;
	}

	// Tag filter: resolve slug to term_taxonomy_id. An unknown slug forces the
	// empty-state branch (with a "Tag not found" message + Browse-all CTA)
	// rather than silently returning the unfiltered feed.
	$mvs_invalid_filter_tag = '';
	if ( ! empty( $mvs_filter_tag ) ) {
		$tag_term_obj = get_term_by( 'slug', $mvs_filter_tag, 'mvs_tag' );
		if ( $tag_term_obj ) {
			$mvs_query_args['tag_tt_id'] = (int) $tag_term_obj->term_taxonomy_id;
		} else {
			$mvs_invalid_filter_tag = $mvs_filter_tag;
		}
	}

	// Category filter: same pattern as the tag filter above.
	$mvs_invalid_filter_cat = '';
	if ( ! empty( $mvs_filter_cat ) ) {
		$cat_term_obj = get_term_by( 'slug', $mvs_filter_cat, 'mvs_category' );
		if ( $cat_term_obj ) {
			$mvs_query_args['category_tt_id'] = (int) $cat_term_obj->term_taxonomy_id;
		} else {
			$mvs_invalid_filter_cat = $mvs_filter_cat;
		}
	}

	/**
	 * Filter the Explore feed query args before they reach MediaRepository.
	 *
	 * Escape hatch for sites that need to change what the Explore / profile
	 * feed shows (e.g. include additional statuses, relax the privacy scope).
	 *
	 * @since 1.4.0
	 *
	 * @param array        $mvs_query_args MediaRepository::query() args.
	 * @param \WP_User|null $mvs_profile    Profile user when on a profile feed, else null.
	 */
	$mvs_query_args = apply_filters( 'mvs_explore_query_args', $mvs_query_args, $mvs_profile );

	$mvs_repo = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );

	// An unknown tag/category slug means "no such filter" -> show the empty
	// state, skip the query entirely.
	if ( '' !== $mvs_invalid_filter_tag || '' !== $mvs_invalid_filter_cat ) {
		$total_items = 0;
		$media_items = array();
	} else {
		$total_items = $mvs_repo->query_count( $mvs_query_args );
		$media_items = $per_page > 0 ? $mvs_repo->query( $mvs_query_args ) : array();
	}

	// Also count albums (albums are still a CPT).
	$album_count = 0;
	if ( ! $mvs_profile && ! $mvs_search && ! $mvs_filter_tag && ! $mvs_filter_cat ) {
		$album_count = (int) wp_count_posts( 'mvs_album' )->publish;
	}

	$max_pages = $per_page > 0 ? (int) ceil( $total_items / $per_page ) : 1;

	// Explore feed is media-only, recent-upload-first. Albums are static
	// containers (people keep uploading new media INTO an existing album
	// without the album itself getting a fresher date), so mixing them
	// in misrepresents what's actually fresh. Albums live on their own
	// /albums page and via per-album permalinks. The Explore page focuses
	// purely on the media stream. Search / profile / tag / category filters
	// already worked this way; this just removes the album-on-top exception
	// from page 1.
	$albums = array(); // no albums in this feed.

	$has_items = ! empty( $media_items );
	?>

	<?php do_action( 'mvs_before_explore_grid' ); ?>

	<?php if ( $has_items ) : ?>
		<?php $mvs_grid_cols = max( 2, min( 5, (int) get_option( 'mvs_grid_columns', 3 ) ) ); ?>
		<div class="mvs-media-grid mvs-cols-<?php echo (int) $mvs_grid_cols; ?> mvs-feed<?php echo 'original' === get_option( 'mvs_thumbnail_style', 'square' ) ? ' mvs-grid--original' : ''; ?>" data-mvs-grid-container>
			<?php
			// Render albums first.
			foreach ( $albums as $album_post ) :
				$album_svc      = \WPMediaVerse\Core\Plugin::container()->get( 'albums' );
				$item_count     = $album_svc->get_item_count( $album_post->ID );
				$cover_media_id = $album_svc->get_resolved_cover_media_id( $album_post->ID );
				// Route album cover through the read-side facade — bypasses
				// the .htaccess deny-all and uses the unified signed-URL flow.
				$cover_url = $cover_media_id
					? \WPMediaVerse\Core\MediaUrl::thumb( $cover_media_id )
					: $album_svc->get_cover_url( $album_post->ID );
				?>
				<div class="mvs-grid-item mvs-grid-item--album">
					<a href="<?php echo esc_url( get_permalink( $album_post->ID ) ); ?>" class="mvs-grid-item-link">
						<?php if ( $cover_url ) : ?>
							<img src="<?php echo esc_url( $cover_url ); ?>"
								alt="<?php echo esc_attr( $album_post->post_title ); ?>"
								loading="lazy" />
						<?php else : ?>
							<div class="mvs-grid-item-placeholder mvs-grid-item-placeholder--album">
								<span class="mvs-grid-album-icon">&#128193;</span>
							</div>
						<?php endif; ?>
						<span class="mvs-album-badge" title="<?php echo esc_attr( sprintf( '%d items', $item_count ) ); ?>">
							<span class="dashicons dashicons-images-alt2"></span>
						</span>
						<div class="mvs-grid-item-overlay">
							<div class="mvs-grid-item-stats">
								<span class="mvs-grid-stat">&#x1F5BC;&#xFE0F; <?php echo esc_html( $item_count ); ?></span>
							</div>
						</div>
					</a>
					<?php
					// Plain name only — keep badge decoration for the
					// single-media / lightbox surfaces, not on every grid
					// thumbnail. Avatar + name link to the album owner's
					// profile, matching the media grid (card #9962508646).
					$mvs_album_author_id  = (int) $album_post->post_author;
					$mvs_tpl_helpers      = \WPMediaVerse\Core\Plugin::container()->get( 'template_helpers' );
					$mvs_album_author_url = $mvs_tpl_helpers->get_user_profile_url( $mvs_album_author_id );
					$mvs_album_author     = $mvs_tpl_helpers->get_display_name_plain( $mvs_album_author_id );
					?>
					<div class="mvs-grid-item-info">
						<?php if ( '' !== $mvs_album_author_url ) : ?>
							<a class="mvs-grid-item-author-link" href="<?php echo esc_url( $mvs_album_author_url ); ?>">
								<?php echo get_avatar( $mvs_album_author_id, 24, '', '', array( 'class' => 'mvs-grid-avatar' ) ); ?>
								<span class="mvs-grid-item-author"><?php echo esc_html( $mvs_album_author ); ?></span>
							</a>
						<?php else : ?>
							<?php echo get_avatar( $mvs_album_author_id, 24, '', '', array( 'class' => 'mvs-grid-avatar' ) ); ?>
							<span class="mvs-grid-item-author"><?php echo esc_html( $mvs_album_author ); ?></span>
						<?php endif; ?>
					</div>
				</div>
			<?php endforeach; ?>

			<?php
			// Render media items from index table.
			$media_ids_for_stats = array_map( 'intval', array_column( $media_items, 'media_id' ) );
			// Batch-load index + all meta for the whole page in 2 queries so each
			// tile renders from the request cache instead of ~14 queries/tile. (1.7.0)
			$mvs_repo->prefetch( $media_ids_for_stats );
			// Prime the access-rules presence cache too (else can_view() COUNTs the
			// rules table once per tile). (1.7.0)
			\WPMediaVerse\Core\Plugin::container()->get( 'access_rules' )->prefetch_active_rules( $media_ids_for_stats );
			$stats_data = \WPMediaVerse\Core\Plugin::container()->get( 'template_helpers' )->bulk_get_stats( $media_ids_for_stats );

			foreach ( $media_items as $item ) :
				$item_id  = (int) $item['media_id'];
				$my_stats = $stats_data[ $item_id ] ?? array();
				\WPMediaVerse\Core\Plugin::container()->get( 'template_helpers' )->render_grid_item(
					$item_id,
					$my_stats,
					array( 'show_author' => true )
				);
			endforeach;
			?>
		</div>

		<?php if ( $max_pages > 1 ) : ?>
			<div class="mvs-load-more">
				<button type="button" class="mvs-load-more-btn"
					data-rest-url="<?php echo esc_attr( rest_url( 'mvs/v1/' ) ); ?>"
					data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"
					data-page="<?php echo esc_attr( $paged ); ?>"
					data-per-page="<?php echo esc_attr( $per_page ); ?>"
					data-endpoint="media"
					data-layout="grid"
					data-tag="<?php echo esc_attr( get_query_var( 'mvs_tag', '' ) ); ?>"
					data-category="<?php echo esc_attr( get_query_var( 'mvs_category', '' ) ); ?>"
					data-search="<?php echo esc_attr( get_query_var( 's', '' ) ); ?>"
					data-scope="public"
					data-group-covers="true">
					<span class="mvs-load-more-label"><?php esc_html_e( 'Load More', 'wpmediaverse' ); ?></span>
					<span class="mvs-load-more-spinner"></span>
				</button>
			</div>
			<p class="mvs-load-more-end" hidden>
				<?php esc_html_e( "You're all caught up!", 'wpmediaverse' ); ?>
			</p>
		<?php endif; ?>
	<?php else : ?>
		<?php if ( ! empty( $mvs_invalid_filter_tag ) || ! empty( $mvs_invalid_filter_cat ) ) : ?>
			<?php
			$mvs_invalid_term = ! empty( $mvs_invalid_filter_tag ) ? $mvs_invalid_filter_tag : $mvs_invalid_filter_cat;
			$mvs_invalid_kind = ! empty( $mvs_invalid_filter_tag ) ? 'tag' : 'category';
			?>
			<div class="mvs-empty-state-frontend">
				<span class="mvs-empty-state-icon">&#x1F50D;</span>
				<h3>
					<?php
					if ( 'tag' === $mvs_invalid_kind ) {
						printf(
							/* translators: %s: tag slug that could not be found. */
							esc_html__( 'Tag "%s" not found', 'wpmediaverse' ),
							esc_html( $mvs_invalid_term )
						);
					} else {
						printf(
							/* translators: %s: category slug that could not be found. */
							esc_html__( 'Category "%s" not found', 'wpmediaverse' ),
							esc_html( $mvs_invalid_term )
						);
					}
					?>
				</h3>
				<p><?php esc_html_e( 'Browse all media, or pick a popular tag below.', 'wpmediaverse' ); ?></p>
				<div class="mvs-empty-state-actions">
					<a href="<?php echo esc_url( $mvs_archive_url ); ?>" class="mvs-btn mvs-btn--primary">
						<?php esc_html_e( 'Browse all media', 'wpmediaverse' ); ?>
					</a>
				</div>
				<?php
				$mvs_popular_tags = get_terms(
					array(
						'taxonomy'   => 'mvs_tag',
						'hide_empty' => true,
						'number'     => 5,
						'orderby'    => 'count',
						'order'      => 'DESC',
					)
				);
				if ( ! is_wp_error( $mvs_popular_tags ) && ! empty( $mvs_popular_tags ) ) :
					?>
					<div class="mvs-tag-cloud mvs-empty-state-tags">
						<?php foreach ( $mvs_popular_tags as $mvs_popular_tag ) : ?>
							<a href="<?php echo esc_url( add_query_arg( 'mvs_tag', $mvs_popular_tag->slug, $mvs_archive_url ) ); ?>" class="mvs-tag-cloud-item">
								<?php echo esc_html( $mvs_popular_tag->name ); ?>
							</a>
						<?php endforeach; ?>
					</div>
					<?php
				endif;
				?>
			</div>
		<?php elseif ( $mvs_search ) : ?>
			<div class="mvs-empty-state-frontend">
				<span class="mvs-empty-state-icon">&#x1F50D;</span>
				<h3>
					<?php
					printf(
						/* translators: %s: search term entered by the user. */
						esc_html__( 'No results for "%s"', 'wpmediaverse' ),
						esc_html( $mvs_search )
					);
					?>
				</h3>
				<p><?php esc_html_e( 'Try a different keyword or browse by popular tag:', 'wpmediaverse' ); ?></p>
				<div class="mvs-empty-state-actions">
					<a href="<?php echo esc_url( $mvs_archive_url ); ?>" class="mvs-btn mvs-btn--primary">
						<?php esc_html_e( 'Browse all media', 'wpmediaverse' ); ?>
					</a>
				</div>
				<?php
				// Popular tags from Taxonomies\MediaTag — top 5 by count.
				$mvs_popular_tags = get_terms(
					array(
						'taxonomy'   => 'mvs_tag',
						'hide_empty' => true,
						'number'     => 5,
						'orderby'    => 'count',
						'order'      => 'DESC',
					)
				);
				if ( ! is_wp_error( $mvs_popular_tags ) && ! empty( $mvs_popular_tags ) ) :
					?>
					<div class="mvs-tag-cloud mvs-empty-state-tags">
						<?php foreach ( $mvs_popular_tags as $mvs_popular_tag ) : ?>
							<a href="<?php echo esc_url( add_query_arg( 'mvs_tag', $mvs_popular_tag->slug, $mvs_archive_url ) ); ?>" class="mvs-tag-cloud-item">
								<?php echo esc_html( $mvs_popular_tag->name ); ?>
							</a>
						<?php endforeach; ?>
					</div>
					<?php
				endif;
				?>
			</div>
		<?php else : ?>
			<div class="mvs-empty-state-frontend">
				<span class="mvs-empty-state-icon">&#x1F4F7;</span>
				<h3><?php esc_html_e( 'No media has been shared yet', 'wpmediaverse' ); ?></h3>
				<p><?php esc_html_e( 'Be the first to share something with the community!', 'wpmediaverse' ); ?></p>
				<?php
				$mvs_upload_page_id = (int) get_option( 'mvs_page_upload', 0 );
				if ( is_user_logged_in() && $mvs_upload_page_id ) :
					?>
					<a href="<?php echo esc_url( get_permalink( $mvs_upload_page_id ) ); ?>" class="mvs-btn mvs-btn--primary">
						<?php esc_html_e( 'Upload Media', 'wpmediaverse' ); ?>
					</a>
				<?php endif; ?>
			</div>
		<?php endif; ?>
	<?php endif; ?>

	<?php
	/**
	 * Fires after the explore grid, symmetric with mvs_before_explore_grid.
	 *
	 * @since 1.1.0
	 */
	do_action( 'mvs_after_explore_grid' );
	?>
</div>
<?php
// Enqueue Interactivity API stores.
wp_enqueue_script_module(
	'@mvs/explore-view',
	MVS_PLUGIN_URL . 'src/blocks/explore-view/view.js',
	array( '@wordpress/interactivity' ),
	MVS_VERSION
);

do_action( 'mvs_after_content' );

if ( $mvs_profile ) {
	$mvs_profile_id     = $mvs_profile->ID;
	$mvs_is_own_profile = is_user_logged_in() && get_current_user_id() === $mvs_profile->ID;
	include MVS_PLUGIN_DIR . 'templates/partials/profile-actions-js.php';
}

include MVS_PLUGIN_DIR . 'templates/partials/router-region-close.php';

get_footer();
