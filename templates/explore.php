<?php
/**
 * Template: Media Explore/Archive.
 *
 * Unified feed displaying both media items and albums (Instagram-style).
 * Override by copying to your-theme/wpmediaverse/explore.php
 *
 * @package WPMediaVerse
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
<?php // Logged-out CTA banner. ?>
<?php if ( ! is_user_logged_in() ) : ?>
	<div class="mvs-logged-out-banner" id="mvs-logged-out-banner">
		<div class="mvs-logged-out-banner__content">
			<strong><?php esc_html_e( 'Join the community', 'wpmediaverse' ); ?></strong>
			<span><?php esc_html_e( '— upload, share, and discover media', 'wpmediaverse' ); ?></span>
		</div>
		<div class="mvs-logged-out-banner__actions">
			<a href="<?php echo esc_url( wp_login_url( get_post_type_archive_link( 'mvs_media' ) ) ); ?>" class="mvs-btn mvs-btn--primary mvs-btn--small">
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
	<script>(function(){var b=document.getElementById('mvs-logged-out-banner');var btn=document.getElementById('mvs-logged-out-banner-close');if(b&&localStorage.getItem('mvs_hide_cta')==='1'){b.style.display='none';}if(btn)btn.addEventListener('click',function(){if(b)b.style.display='none';localStorage.setItem('mvs_hide_cta','1');});}());</script>
<?php endif; ?>

<div class="mvs-explore-page">
	<header class="mvs-explore-header">
		<h1>
		<?php
		if ( is_tax( 'mvs_tag' ) ) {
			printf(
				/* translators: %s: tag name */
				esc_html__( 'Tag: %s', 'wpmediaverse' ),
				esc_html( single_term_title( '', false ) )
			);
		} elseif ( is_tax( 'mvs_category' ) ) {
			printf(
				/* translators: %s: category name */
				esc_html__( 'Category: %s', 'wpmediaverse' ),
				esc_html( single_term_title( '', false ) )
			);
		} else {
			esc_html_e( 'Explore', 'wpmediaverse' );
		}
		?>
		</h1>
		<?php if ( is_tax() && term_description() ) : ?>
			<p class="mvs-explore-term-desc"><?php echo wp_kses_post( term_description() ); ?></p>
		<?php endif; ?>
	</header>

	<!-- Search Bar with Media/Users toggle -->
	<div class="mvs-explore-search">
		<form method="get" action="<?php echo esc_url( get_post_type_archive_link( 'mvs_media' ) ); ?>" id="mvs-explore-search-form">
			<div class="mvs-search-tabs">
				<button type="button" class="mvs-search-tab active" data-search-mode="media"><?php esc_html_e( 'Media', 'wpmediaverse' ); ?></button>
				<button type="button" class="mvs-search-tab" data-search-mode="users"><?php esc_html_e( 'Users', 'wpmediaverse' ); ?></button>
			</div>
			<div class="mvs-search-input-wrap">
				<input type="text" name="s" placeholder="<?php esc_attr_e( 'Search media...', 'wpmediaverse' ); ?>"
					value="<?php echo esc_attr( get_search_query() ); ?>" id="mvs-search-input" />
				<button type="submit" aria-label="<?php esc_attr_e( 'Search', 'wpmediaverse' ); ?>"><?php esc_html_e( 'Search', 'wpmediaverse' ); ?></button>
			</div>
		</form>
		<!-- User search results (populated via safe DOM methods) -->
		<div class="mvs-user-search-results" id="mvs-user-search-results" style="display:none;"></div>
	</div>
	<script>
	(function() {
		var tabs = document.querySelectorAll('.mvs-search-tab');
		var input = document.getElementById('mvs-search-input');
		var form = document.getElementById('mvs-explore-search-form');
		var results = document.getElementById('mvs-user-search-results');
		var mode = 'media';
		var debounce;

		tabs.forEach(function(tab) {
			tab.addEventListener('click', function() {
				tabs.forEach(function(t) { t.classList.remove('active'); });
				tab.classList.add('active');
				mode = tab.getAttribute('data-search-mode');
				input.placeholder = mode === 'users'
					? '<?php echo esc_js( __( 'Search users...', 'wpmediaverse' ) ); ?>'
					: '<?php echo esc_js( __( 'Search media...', 'wpmediaverse' ) ); ?>';
				results.style.display = 'none';
				while (results.firstChild) results.removeChild(results.firstChild);
			});
		});

		form.addEventListener('submit', function(e) {
			if (mode === 'users') {
				e.preventDefault();
				searchUsers(input.value.trim());
			}
		});

		input.addEventListener('input', function() {
			if (mode !== 'users') return;
			clearTimeout(debounce);
			debounce = setTimeout(function() {
				if (input.value.trim().length >= 2) searchUsers(input.value.trim());
				else { results.style.display = 'none'; while (results.firstChild) results.removeChild(results.firstChild); }
			}, 300);
		});

		function searchUsers(q) {
			if (!q) return;
			var restUrl = '<?php echo esc_url_raw( rest_url( 'mvs/v1/users/search' ) ); ?>';
			fetch(restUrl + '?q=' + encodeURIComponent(q), {
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>' }
			})
			.then(function(r) { return r.json(); })
			.then(function(users) {
				while (results.firstChild) results.removeChild(results.firstChild);
				if (!users.length) {
					var emptyP = document.createElement('p');
					emptyP.className = 'mvs-user-search-empty';
					emptyP.textContent = '<?php echo esc_js( __( 'No users found.', 'wpmediaverse' ) ); ?>';
					results.appendChild(emptyP);
					results.style.display = 'block';
					return;
				}
				users.forEach(function(u) {
					var name = u.display_name || u.name || u.user_login || '';
					var card = document.createElement('a');
					card.href = u.profile_url || '#';
					card.className = 'mvs-user-card';

					var img = document.createElement('img');
					img.src = u.avatar || '';
					img.alt = '';
					img.className = 'mvs-user-card-avatar';
					img.width = 48;
					img.height = 48;
					card.appendChild(img);

					var info = document.createElement('div');
					info.className = 'mvs-user-card-info';
					var nameEl = document.createElement('strong');
					nameEl.textContent = name;
					info.appendChild(nameEl);
					if (u.media_count !== undefined) {
						var countEl = document.createElement('span');
						countEl.textContent = u.media_count + ' <?php echo esc_js( __( 'media', 'wpmediaverse' ) ); ?>';
						info.appendChild(countEl);
					}
					card.appendChild(info);
					results.appendChild(card);
				});
				results.style.display = 'block';
			});
		}
	})();
	</script>

	<!-- Tag Cloud (Interactivity API) -->
	<?php
	$mvs_explore_ctx = array(
		'restUrl'    => esc_url_raw( rest_url( 'mvs/v1/' ) ),
		'archiveUrl' => esc_url( get_post_type_archive_link( 'mvs_media' ) ),
		'activeTag'  => is_tax( 'mvs_tag' ) ? get_queried_object()->slug : ( isset( $_GET['mvs_tag'] ) ? sanitize_text_field( wp_unslash( $_GET['mvs_tag'] ) ) : '' ), // phpcs:ignore WordPress.Security.NonceVerification
		'tags'       => array(),
		'loaded'     => false,
	);
	?>
	<div class="mvs-tag-cloud"
		data-wp-interactive="mvs/explore"
		<?php echo wp_interactivity_data_wp_context( $mvs_explore_ctx ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		data-wp-init="callbacks.init">
		<template data-wp-each="context.tags">
			<a class="mvs-tag-cloud-item"
				data-wp-bind--href="context.item.href"
				data-wp-text="context.item.name"
				data-wp-class--active="context.item.active"></a>
		</template>
	</div>

	<?php if ( have_posts() ) : ?>
		<div class="mvs-media-grid mvs-cols-3 mvs-feed">
			<?php
			while ( have_posts() ) :
				the_post();

				$post_type = get_post_type();
				$is_album  = ( 'mvs_album' === $post_type );

				if ( $is_album ) {
					$container  = \WPMediaVerse\Core\Plugin::container();
					$album_svc  = $container->get( 'albums' );
					$item_count = $album_svc->get_item_count( get_the_ID() );
					$cover_url  = $album_svc->get_cover_url( get_the_ID() );
				} else {
					$stats_data = \WPMediaVerse\Core\TemplateHelpers::bulk_get_stats( array( get_the_ID() ) );
					$my_stats   = $stats_data[ get_the_ID() ] ?? array();
				}
				?>
				<?php if ( $is_album ) : ?>
				<div class="mvs-grid-item mvs-grid-item--album">
					<a href="<?php the_permalink(); ?>" class="mvs-grid-item-link">
						<?php if ( $cover_url ) : ?>
							<img src="<?php echo esc_url( $cover_url ); ?>"
								alt="<?php echo esc_attr( get_the_title() ); ?>"
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
					<div class="mvs-grid-item-info">
						<?php echo get_avatar( get_the_author_meta( 'ID' ), 24, '', '', array( 'class' => 'mvs-grid-avatar' ) ); ?>
						<span class="mvs-grid-item-author"><?php echo esc_html( get_the_author() ); ?></span>
					</div>
				</div>
				<?php else : ?>
					<?php
					\WPMediaVerse\Core\TemplateHelpers::render_grid_item(
						get_the_ID(),
						$my_stats,
						array( 'show_author' => true )
					);
					?>
				<?php endif; ?>
			<?php endwhile; ?>
		</div>

		<div class="mvs-pagination">
			<?php
			the_posts_pagination(
				array(
					'prev_text' => __( '&laquo; Previous', 'wpmediaverse' ),
					'next_text' => __( 'Next &raquo;', 'wpmediaverse' ),
				)
			);
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
</div>
<?php
// Enqueue Interactivity API stores.
$mvs_explore_asset_file = MVS_PLUGIN_DIR . 'build/blocks/explore-view/view.asset.php';
$mvs_explore_asset      = file_exists( $mvs_explore_asset_file ) ? require $mvs_explore_asset_file : array(
	'dependencies' => array(),
	'version'      => MVS_VERSION,
);
wp_enqueue_script_module(
	'mvs-explore-view',
	MVS_PLUGIN_URL . 'build/blocks/explore-view/view.js',
	$mvs_explore_asset['dependencies'],
	$mvs_explore_asset['version']
);

get_footer();
