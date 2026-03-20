<?php
/**
 * Chat panel — slide-out container rendered in wp_footer.
 *
 * @package WPMediaVerse
 * @since   1.1.0
 */

defined( 'ABSPATH' ) || exit;

$user_id = get_current_user_id();
if ( ! $user_id ) {
	return;
}
?>
<div
	data-wp-interactive="mvs/messaging"
	data-wp-init="callbacks.onInit"
	<?php echo wp_interactivity_data_wp_context( array( 'panelId' => 'chat-panel' ) ); ?>
>
	<!-- Floating Chat Button -->
	<button
		class="mvs-chat-trigger"
		data-wp-on--click="actions.toggleChatPanel"
		type="button"
		aria-label="Open messages"
	>
		<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H5.17L4 17.17V4h16v12z"/></svg>
		<span
			class="mvs-chat-trigger__badge"
			data-wp-text="state.totalUnread"
			data-wp-bind--hidden="!state.hasUnread"
		></span>
	</button>

	<!-- Overlay -->
	<div
		class="mvs-chat-panel__overlay"
		data-wp-bind--data-visible="state.chatPanelOpen"
		data-wp-on--click="actions.closeChatPanel"
	></div>

	<!-- Panel -->
	<div
		class="mvs-chat-panel"
		data-wp-bind--data-open="state.chatPanelOpen"
		role="dialog"
		aria-label="Messages"
	>
		<!-- List View -->
		<div data-wp-bind--hidden="!state.isViewList">
			<?php include __DIR__ . '/chat-list.php'; ?>
		</div>

		<!-- Conversation View -->
		<div class="mvs-chat-panel__view" data-wp-bind--hidden="!state.isViewConversation">
			<?php include __DIR__ . '/chat-conversation.php'; ?>
		</div>

		<!-- New Conversation View -->
		<div class="mvs-chat-panel__view" data-wp-bind--hidden="!state.isViewNew">
			<?php include __DIR__ . '/chat-new.php'; ?>
		</div>
	</div>
</div>
