<?php
/**
 * Template: Single Collection.
 *
 * Displays a collection's matched media items in a grid.
 * Override by copying to your-theme/wpmediaverse/collection.php
 *
 * @package WPMediaVerse
 */

defined( 'ABSPATH' ) || exit;

get_header();

do_action( 'mvs_before_content' );
?>
<div class="mvs-single-collection">
	<?php
	while ( have_posts() ) :
		the_post();

		$collection_id   = get_the_ID();
		$collection_type = get_post_meta( $collection_id, '_mvs_collection_type', true ) ?: 'manual';
		$is_owner        = is_user_logged_in() && (int) get_the_author_meta( 'ID' ) === get_current_user_id();

		// Resolve items.
		$container = \WPMediaVerse\Core\Plugin::container();
		$service   = $container->get( 'collections' );
		$items     = array();

		if ( 'smart' === $collection_type ) {
			$resolved = $service->resolve( $collection_id, 100, 1 );
			$items    = array_column( $resolved['items'], 'media_id' );
		} else {
			global $wpdb;
			$items = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT media_id FROM {$wpdb->prefix}mvs_favorites WHERE collection_id = %d ORDER BY created_at DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$collection_id
				)
			);
		}

		$rules = $service->get_rules( $collection_id );

		// Resolve rule values to human-readable names.
		foreach ( $rules as &$rule ) {
			if ( 'tag' === $rule['key'] ) {
				$term = get_term( (int) $rule['value'], 'mvs_tag' );
				if ( $term && ! is_wp_error( $term ) ) {
					$rule['value'] = $term->name;
				}
			} elseif ( 'category' === $rule['key'] ) {
				$term = get_term( (int) $rule['value'], 'mvs_category' );
				if ( $term && ! is_wp_error( $term ) ) {
					$rule['value'] = $term->name;
				}
			} elseif ( 'author' === $rule['key'] ) {
				$user = get_userdata( (int) $rule['value'] );
				if ( $user ) {
					$rule['value'] = $user->display_name;
				}
			}
		}
		unset( $rule );
		?>

		<article id="mvs-collection-<?php the_ID(); ?>" <?php post_class( 'mvs-collection-article' ); ?>>
			<!-- Info Card -->
			<div class="mvs-collection-card-info">
				<h1 class="mvs-collection-card-title"><?php the_title(); ?></h1>
				<div class="mvs-collection-card-meta">
					<span class="mvs-collection-meta-author">
						<?php echo get_avatar( get_the_author_meta( 'ID' ), 24, '', '', array( 'class' => 'mvs-collection-avatar' ) ); ?>
						<?php
						$mvs_author_id  = get_the_author_meta( 'ID' );
						$mvs_author_url = '';
						if ( function_exists( 'bp_members_get_user_url' ) ) {
							$mvs_author_url = bp_members_get_user_url( $mvs_author_id );
						} elseif ( function_exists( 'bp_core_get_user_domain' ) ) {
							$mvs_author_url = bp_core_get_user_domain( $mvs_author_id );
						} else {
							$mvs_author_url = home_url( '/media/@' . get_the_author_meta( 'user_login', $mvs_author_id ) . '/' );
						}

						if ( $mvs_author_url ) :
							?>
						<a href="<?php echo esc_url( $mvs_author_url ); ?>"><?php echo esc_html( get_the_author() ); ?></a>
						<?php else : ?>
						<span><?php echo esc_html( get_the_author() ); ?></span>
						<?php endif; ?>
					</span>
					<span class="mvs-collection-meta-text">
						<?php
						printf(
							/* translators: %d: number of items */
							esc_html( _n( '%d item', '%d items', count( $items ), 'wpmediaverse' ) ),
							count( $items )
						);
						?>
					</span>
					<span class="mvs-collection-type-badge"><?php echo esc_html( $collection_type ); ?></span>
					<?php if ( 'smart' === $collection_type && ! empty( $rules ) ) : ?>
						<?php foreach ( $rules as $rule ) : ?>
							<span class="mvs-rule-pill"><?php echo esc_html( $rule['key'] . ': ' . $rule['value'] ); ?></span>
						<?php endforeach; ?>
					<?php endif; ?>
				</div>
				<?php if ( get_the_content() ) : ?>
					<div class="mvs-collection-card-desc"><?php the_content(); ?></div>
				<?php endif; ?>
			</div>

			<!-- Search within collection -->
			<?php if ( ! empty( $items ) ) : ?>
			<div class="mvs-explore-search mvs-collection-search">
				<form onsubmit="return false;">
					<input type="text" class="mvs-collection-search-input" placeholder="<?php esc_attr_e( 'Search in this collection...', 'wpmediaverse' ); ?>" />
					<button type="button" class="mvs-collection-search-btn"><?php esc_html_e( 'Search', 'wpmediaverse' ); ?></button>
				</form>
			</div>
			<?php endif; ?>

			<!-- Media Grid -->
			<?php if ( ! empty( $items ) ) : ?>
				<?php $stats_map = \WPMediaVerse\Core\TemplateHelpers::bulk_get_stats( array_map( 'intval', $items ) ); ?>
				<div class="mvs-media-grid mvs-cols-3 mvs-feed">
					<?php
					foreach ( $items as $media_id ) :
						$media_id     = (int) $media_id;
						$media_title  = \WPMediaVerse\Services\MediaMeta::get( $media_id, 'title' );
						$media_status = \WPMediaVerse\Services\MediaMeta::get( $media_id, 'status' );
						if ( ! $media_title || 'publish' !== $media_status ) {
							continue;
						}
						\WPMediaVerse\Core\TemplateHelpers::render_grid_item(
							$media_id,
							$stats_map[ $media_id ] ?? array(),
							array(
								'show_author' => true,
								'data_attrs'  => array( 'title' => strtolower( $media_title ) ),
							)
						);
					endforeach;
					?>
				</div>
			<?php else : ?>
				<p class="mvs-no-media">
					<?php
					if ( 'smart' === $collection_type ) {
						esc_html_e( 'No media matches the current rules.', 'wpmediaverse' );
					} else {
						esc_html_e( 'This collection is empty.', 'wpmediaverse' );
					}
					?>
				</p>
			<?php endif; ?>
		</article>

	<?php endwhile; ?>
</div>
<script>
( function() {
	const input = document.querySelector( '.mvs-collection-search-input' );
	if ( ! input ) return;
	const grid = document.querySelector( '.mvs-collection-article .mvs-media-grid' );
	if ( ! grid ) return;
	const items = grid.querySelectorAll( '.mvs-grid-item' );

	function filterItems() {
		const q = input.value.toLowerCase().trim();
		items.forEach( function( item ) {
			const title = item.getAttribute( 'data-title' ) || '';
			item.style.display = ( ! q || title.indexOf( q ) !== -1 ) ? '' : 'none';
		} );
	}

	input.addEventListener( 'input', filterItems );
	const btn = document.querySelector( '.mvs-collection-search-btn' );
	if ( btn ) btn.addEventListener( 'click', filterItems );
} )();
</script>
<?php
wp_enqueue_style( 'mvs-frontend' );
do_action( 'mvs_after_content' );

get_footer();
