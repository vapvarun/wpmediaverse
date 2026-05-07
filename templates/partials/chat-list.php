<?php
/**
 * Chat list — conversation list with tabs.
 *
 * @package WPMediaVerse
 * @since   1.1.0
 */

defined( 'ABSPATH' ) || exit;
?>
<!-- Header -->
<div class="mvs-chat-header">
	<h3 class="mvs-chat-header__title"><?php esc_html_e( 'Messages', 'wpmediaverse' ); ?></h3>
	<button class="mvs-chat-header__new" data-wp-on--click="actions.openNewConversation" type="button" aria-label="<?php esc_attr_e( 'New message', 'wpmediaverse' ); ?>">
		<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z" fill="currentColor"/></svg>
	</button>
	<button class="mvs-chat-header__close" data-wp-on--click="actions.closeChatPanel" type="button" aria-label="<?php esc_attr_e( 'Close', 'wpmediaverse' ); ?>">
		<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z" fill="currentColor"/></svg>
	</button>
</div>

<!-- Tabs -->
<div class="mvs-chat-tabs">
	<button
		class="mvs-chat-tabs__tab"
		data-wp-on--click="actions.setTab"
		<?php
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_interactivity_data_wp_context() handles its own escaping.
		echo wp_interactivity_data_wp_context( array( 'tab' => 'all' ) );
		?>
		data-wp-bind--data-active="state.isTabAll"
		type="button"
	><?php esc_html_e( 'All', 'wpmediaverse' ); ?></button>
	<button
		class="mvs-chat-tabs__tab"
		data-wp-on--click="actions.setTab"
		<?php
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_interactivity_data_wp_context() handles its own escaping.
		echo wp_interactivity_data_wp_context( array( 'tab' => 'unread' ) );
		?>
		data-wp-bind--data-active="state.isTabUnread"
		type="button"
	><?php esc_html_e( 'Unread', 'wpmediaverse' ); ?></button>
	<button
		class="mvs-chat-tabs__tab"
		data-wp-on--click="actions.setTab"
		<?php
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_interactivity_data_wp_context() handles its own escaping.
		echo wp_interactivity_data_wp_context( array( 'tab' => 'requests' ) );
		?>
		data-wp-bind--data-active="state.isTabRequests"
		type="button"
	><?php esc_html_e( 'Requests', 'wpmediaverse' ); ?></button>
</div>

<!-- Search -->
<div class="mvs-chat-search">
	<input
		class="mvs-chat-search__input"
		type="text"
		placeholder="<?php esc_attr_e( 'Search conversations...', 'wpmediaverse' ); ?>"
		data-wp-on--input="actions.updateSearchQuery"
	/>
</div>

<!-- Conversation List -->
<div class="mvs-chat-list">
	<template data-wp-each="state.conversations">
		<button
			class="mvs-chat-conv-item"
			data-wp-on--click="actions.openConversation"
			data-wp-bind--data-pinned="context.item.is_pinned"
			type="button"
		>
			<div class="mvs-chat-conv-item__avatar">
				<img data-wp-bind--src="context.item.otherAvatar" alt="" width="48" height="48" />
				<span class="mvs-online-dot" data-wp-bind--hidden="!context.item.otherOnline"></span>
			</div>
			<div class="mvs-chat-conv-item__body">
				<div class="mvs-chat-conv-item__name" data-wp-text="context.item.otherName"></div>
				<div class="mvs-chat-conv-item__preview" data-wp-text="context.item.last_message_preview"></div>
			</div>
			<div class="mvs-chat-conv-item__meta">
				<span class="mvs-chat-conv-item__pin" data-wp-bind--hidden="!context.item.is_pinned"><?php esc_html_e( 'Pinned', 'wpmediaverse' ); ?></span>
				<span
					class="mvs-chat-conv-item__unread"
					data-wp-text="context.item.unread_count"
					data-wp-bind--hidden="!context.item.hasUnread"
				></span>
			</div>
		</button>
	</template>

	<div class="mvs-chat-list__empty" data-wp-bind--hidden="state.hasConversations">
		<p><?php esc_html_e( 'No conversations yet', 'wpmediaverse' ); ?></p>
		<p><?php esc_html_e( 'Start a new conversation to begin messaging.', 'wpmediaverse' ); ?></p>
	</div>
</div>
