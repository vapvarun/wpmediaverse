<?php
/**
 * Full-page messages layout — two-column chat at /messages/.
 *
 * @package WPMediaVerse
 * @since   1.1.0
 */

defined( 'ABSPATH' ) || exit;

\WPMediaVerse\Core\TemplateHelpers::site_header();

include MVS_PLUGIN_DIR . 'templates/partials/router-region-open.php';

do_action( 'mvs_before_content' );
?>

<div
	class="mvs-messages-page"
	data-wp-interactive="mvs/messaging"
	data-wp-init="callbacks.onInit"
	data-wp-bind--data-active-conv="state.activeConversationId"
>
	<!-- Sidebar: Conversation List OR New Conversation picker -->
	<div class="mvs-messages-sidebar">
		<div data-wp-bind--hidden="state.isViewNew">
			<?php require __DIR__ . '/partials/chat-list.php'; ?>
		</div>
		<div data-wp-bind--hidden="!state.isViewNew">
			<?php require __DIR__ . '/partials/chat-new.php'; ?>
		</div>
	</div>

	<!-- Main: Active Conversation -->
	<div class="mvs-messages-main" data-wp-bind--hidden="!state.activeConversationId">
		<?php require __DIR__ . '/partials/chat-conversation.php'; ?>
	</div>

	<!-- Empty State -->
	<div class="mvs-messages-main mvs-messages-main--empty" data-wp-bind--hidden="state.activeConversationId">
		<div class="mvs-messages-empty">
			<svg viewBox="0 0 24 24" width="48" height="48" fill="none" stroke="currentColor" stroke-width="1.5" xmlns="http://www.w3.org/2000/svg">
				<path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/>
			</svg>
			<p><?php esc_html_e( 'Select a conversation or start a new one', 'wpmediaverse' ); ?></p>
		</div>
	</div>
</div>

<?php
// @deprecated 2.3.0 Not the enqueue site any more — Core\Plugin::enqueue_frontend_assets()
// enqueues this handle for every MVS-owned page. Enqueuing from a template body only
// ever worked on a hard page load: the <script> tag prints in wp_footer, OUTSIDE
// [data-wp-router-region="mvs/main"], so a client-side navigation swapped in the markup
// without ever delivering the script (Basecamp #10148246386, #10134243697). Left as an
// idempotent no-op because themes may override this template — Production Rule #5.
?>
<?php wp_enqueue_script( 'mvs-messages-scroll' ); ?>

<?php
do_action( 'mvs_after_content' );

include MVS_PLUGIN_DIR . 'templates/partials/router-region-close.php';

\WPMediaVerse\Core\TemplateHelpers::site_footer();
