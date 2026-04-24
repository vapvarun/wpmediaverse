<?php
/**
 * Plugin-branded 404 template.
 *
 * Rendered by `TemplateLoader::render_branded_404()` when a plugin-owned
 * URL resolves to nothing — missing media, deleted profile, private item
 * the viewer cannot access, etc. Keeps the user inside the plugin's
 * experience instead of bouncing them to the theme's generic 404.php.
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

get_header();

do_action( 'mvs_before_content' );

$mvs_context       = isset( $GLOBALS['mvs_404_context'] ) ? (string) $GLOBALS['mvs_404_context'] : '';
$mvs_identifier    = isset( $GLOBALS['mvs_404_identifier'] ) ? (string) $GLOBALS['mvs_404_identifier'] : '';
$mvs_archive_url   = home_url( '/media/' );
$mvs_explore_label = __( 'Browse all media', 'wpmediaverse' );

switch ( $mvs_context ) {
	case 'profile':
		$mvs_title   = $mvs_identifier
			? sprintf( /* translators: %s: username. */ __( 'We couldn\'t find @%s', 'wpmediaverse' ), $mvs_identifier )
			: __( 'Profile not found', 'wpmediaverse' );
		$mvs_message = __( 'The profile you\'re looking for doesn\'t exist. Browse media instead.', 'wpmediaverse' );
		break;
	case 'album':
		$mvs_title   = __( 'Album not found', 'wpmediaverse' );
		$mvs_message = __( 'This album doesn\'t exist or has been removed.', 'wpmediaverse' );
		break;
	case 'collection':
		$mvs_title   = __( 'Collection not found', 'wpmediaverse' );
		$mvs_message = __( 'This collection doesn\'t exist or has been removed.', 'wpmediaverse' );
		break;
	case 'media':
	default:
		$mvs_title   = __( 'Media not found', 'wpmediaverse' );
		$mvs_message = __( 'The media you\'re looking for doesn\'t exist, has been removed, or is private.', 'wpmediaverse' );
		break;
}
?>
<div class="mvs-archive">
	<div class="mvs-empty-state-frontend mvs-empty-state-frontend--404">
		<span class="mvs-empty-state-icon" aria-hidden="true">&#x1F50D;</span>
		<h1><?php echo esc_html( $mvs_title ); ?></h1>
		<p><?php echo esc_html( $mvs_message ); ?></p>
		<div class="mvs-empty-state-actions">
			<a href="<?php echo esc_url( $mvs_archive_url ); ?>" class="mvs-btn mvs-btn--primary">
				<?php echo esc_html( $mvs_explore_label ); ?>
			</a>
		</div>
	</div>
</div>
<?php
do_action( 'mvs_after_content' );
get_footer();
