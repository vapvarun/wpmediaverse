<?php
/**
 * App-page template.
 *
 * FALLBACK sidebar-free template for the pages the plugin created on activation
 * (/my-media/, /explore-media/, /upload-media/).
 *
 * On the three Wbcom themes, TemplateLoader::use_app_template() uses the theme's
 * OWN full-width mechanism instead of this file — BuddyX / BuddyX-Pro route to
 * their `page-templates/full-width-container.php`, and Reign forces full-width
 * via post-meta. This template is the fallback for any OTHER active theme, so a
 * member's media library is never rendered beside the theme's blog sidebar
 * regardless of what theme the site runs.
 *
 * Deliberately never calls get_sidebar(). A theme that wants a different shell
 * ships its own wpmediaverse/app-page.php (resolved first by
 * TemplateLoader::locate()) or filters mvs_app_template.
 *
 * The page's own block content (the mvs blocks the page was created with) renders
 * via the_content(); this template only supplies the header, the app shell
 * wrapper, and the footer.
 *
 * @package WPMediaVerse
 */

defined( 'ABSPATH' ) || exit;

\WPMediaVerse\Core\TemplateHelpers::site_header();
?>

<main id="primary" class="mvs-page mvs-app-page">
	<?php
	while ( have_posts() ) :
		the_post();
		the_content();
	endwhile;
	?>
</main>

<?php
\WPMediaVerse\Core\TemplateHelpers::site_footer();
