<?php
/**
 * Full-page messages layout — two-column chat at /messages/.
 *
 * @package WPMediaVerse
 * @since   1.1.0
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>

<div
	class="mvs-messages-page"
	data-wp-interactive="mvs/messaging"
	data-wp-init="callbacks.onInit"
	data-wp-bind--data-active-conv="state.activeConversationId"
>
	<!-- Sidebar: Conversation List -->
	<div class="mvs-messages-sidebar">
		<?php require __DIR__ . '/partials/chat-list.php'; ?>
	</div>

	<!-- Main: Active Conversation -->
	<div class="mvs-messages-main" data-wp-bind--hidden="!state.activeConversationId">
		<?php require __DIR__ . '/partials/chat-conversation.php'; ?>
	</div>

	<!-- Empty State -->
	<div class="mvs-messages-main mvs-messages-main--empty" data-wp-bind--hidden="state.activeConversationId">
		<div style="text-align:center;">
			<svg viewBox="0 0 24 24" width="48" height="48" fill="none" stroke="currentColor" stroke-width="1.5" xmlns="http://www.w3.org/2000/svg">
				<path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/>
			</svg>
			<p style="margin-top:12px;">Select a conversation or start a new one</p>
		</div>
	</div>
</div>

<?php
get_footer();
