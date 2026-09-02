<?php
/**
 * Plugin-branded 404 template.
 *
 * Rendered by `TemplateLoader::render_branded_404()` when a plugin-owned
 * URL resolves to nothing — missing media, deleted profile, private item
 * the viewer cannot access, etc. Keeps the user inside the plugin's
 * experience instead of bouncing them to the theme's generic 404.php.
 *
 * Shares the card + halo + gradient-bar design language with the auth
 * gate so customers see a consistent "something went wrong, here's where
 * to go next" presentation across plugin surfaces.
 *
 * Expected globals (optional):
 *   $GLOBALS['mvs_404_context']    — 'media' | 'profile' | 'album' | 'collection' | ''.
 *   $GLOBALS['mvs_404_identifier'] — the slug/username the user requested.
 *
 * Override: copy to `your-theme/wpmediaverse/404.php`.
 *
 * @package WPMediaVerse
 */

defined( 'ABSPATH' ) || exit;

\WPMediaVerse\Core\TemplateHelpers::site_header();

include MVS_PLUGIN_DIR . 'templates/partials/router-region-open.php';

do_action( 'mvs_before_content' );

$mvs_context     = isset( $GLOBALS['mvs_404_context'] ) ? (string) $GLOBALS['mvs_404_context'] : '';
$mvs_identifier  = isset( $GLOBALS['mvs_404_identifier'] ) ? (string) $GLOBALS['mvs_404_identifier'] : '';
$mvs_archive_url = home_url( '/media/' );

switch ( $mvs_context ) {
	case 'profile':
		$mvs_title   = $mvs_identifier
			? sprintf( /* translators: %s: username. */ __( 'We couldn\'t find @%s', 'wpmediaverse' ), $mvs_identifier )
			: __( 'Profile not found', 'wpmediaverse' );
		$mvs_message = __( 'The profile you\'re looking for doesn\'t exist or was removed. Browse the community instead.', 'wpmediaverse' );
		break;
	case 'album':
		$mvs_title   = __( 'Album not found', 'wpmediaverse' );
		$mvs_message = __( 'This album doesn\'t exist or has been removed. Keep exploring — there\'s plenty more to see.', 'wpmediaverse' );
		break;
	case 'collection':
		$mvs_title   = __( 'Collection not found', 'wpmediaverse' );
		$mvs_message = __( 'This collection doesn\'t exist or has been removed. Keep exploring — there\'s plenty more to see.', 'wpmediaverse' );
		break;
	case 'media':
	default:
		$mvs_title   = __( 'We couldn\'t find that media', 'wpmediaverse' );
		$mvs_message = __( 'The item you\'re looking for doesn\'t exist, has been removed, or is set to private.', 'wpmediaverse' );
		break;
}

// Popular tags for the secondary-navigation row. Counted against the media
// index, not the taxonomy count, so a suggested tag always has media behind it
// — a 404 that hands the visitor another dead end is the worst place for one.
$mvs_popular_tags = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->tag_cloud( 5 );
?>
<div class="mvs-archive mvs-archive--404">
	<div class="mvs-empty-state-frontend mvs-empty-state-frontend--404">
		<div class="mvs-empty-state-frontend__glyph" aria-hidden="true">
			<i data-lucide="search-x"></i>
		</div>
		<h1 class="mvs-empty-state-frontend__title"><?php echo esc_html( $mvs_title ); ?></h1>
		<p class="mvs-empty-state-frontend__lede"><?php echo esc_html( $mvs_message ); ?></p>
		<div class="mvs-empty-state-actions">
			<a href="<?php echo esc_url( $mvs_archive_url ); ?>" class="mvs-btn mvs-btn--primary">
				<?php esc_html_e( 'Browse all media', 'wpmediaverse' ); ?>
			</a>
		</div>

		<?php if ( ! empty( $mvs_popular_tags ) ) : ?>
			<div class="mvs-empty-state-frontend__tags-label">
				<?php esc_html_e( 'Or jump to a popular tag', 'wpmediaverse' ); ?>
			</div>
			<div class="mvs-tag-cloud mvs-empty-state-tags">
				<?php foreach ( $mvs_popular_tags as $mvs_popular_tag ) : ?>
					<a href="<?php echo esc_url( add_query_arg( 'mvs_tag', $mvs_popular_tag->slug, $mvs_archive_url ) ); ?>" class="mvs-tag-cloud-item">
						<?php echo esc_html( $mvs_popular_tag->name ); ?>
					</a>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</div>
</div>
<?php
do_action( 'mvs_after_content' );
include MVS_PLUGIN_DIR . 'templates/partials/router-region-close.php';

\WPMediaVerse\Core\TemplateHelpers::site_footer();
