<?php
/**
 * Chat conversation — active chat header, messages, and composer.
 *
 * @package WPMediaVerse
 * @since   1.1.0
 */

defined( 'ABSPATH' ) || exit;
?>
<!-- Conversation Header -->
<div class="mvs-chat-header">
	<button class="mvs-chat-header__back" data-wp-on--click="actions.goBackToList" type="button" aria-label="<?php esc_attr_e( 'Back', 'wpmediaverse' ); ?>">
		<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20v-2z" fill="currentColor"/></svg>
	</button>
	<div class="mvs-chat-header__avatar">
		<img data-wp-bind--src="state.otherAvatar" data-wp-bind--hidden="!state.otherAvatar" alt="" width="36" height="36" />
		<span class="mvs-online-dot" data-wp-bind--hidden="!state.otherIsOnline"></span>
	</div>
	<div style="flex:1;min-width:0;">
		<div class="mvs-chat-header__title" data-wp-text="state.otherName"></div>
		<div class="mvs-chat-header__subtitle" data-wp-text="state.otherLastActive"></div>
	</div>
	<button class="mvs-chat-header__menu" data-wp-on--click="actions.toggleMute" type="button" aria-label="<?php esc_attr_e( 'Mute', 'wpmediaverse' ); ?>">
		<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.9 2 2 2zm6-6v-5c0-3.07-1.63-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.64 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2z" fill="currentColor"/></svg>
	</button>
</div>

<!-- Messages Area -->
<div class="mvs-chat-messages" data-wp-init="callbacks.onMessagesMount" data-wp-on--click="actions.hideContextMenu">
	<!-- Load More -->
	<div class="mvs-chat-messages__load-more" data-wp-bind--hidden="!state.hasMoreMessages">
		<button data-wp-on--click="actions.loadOlderMessages" type="button">
			<span data-wp-bind--hidden="state.loadingMessages"><?php esc_html_e( 'Load older messages', 'wpmediaverse' ); ?></span>
			<span data-wp-bind--hidden="!state.loadingMessages"><?php esc_html_e( 'Loading...', 'wpmediaverse' ); ?></span>
		</button>
	</div>

	<!-- Message Bubbles -->
	<template data-wp-each="state.messages">
		<?php require __DIR__ . '/chat-message.php'; ?>
	</template>

	<!-- Typing Indicator -->
	<template data-wp-each="state.typingUsers">
		<div class="mvs-chat-messages__typing">
			<span data-wp-text="context.item.name"></span> <?php esc_html_e( 'is typing...', 'wpmediaverse' ); ?>
		</div>
	</template>
</div>

<!-- Message Request Banner -->
<div class="mvs-chat-request-banner" data-wp-bind--hidden="!state.isRequest">
	<div class="mvs-chat-request-banner__text">
		<?php esc_html_e( 'This user wants to send you a message. Accept or decline the request.', 'wpmediaverse' ); ?>
	</div>
	<div class="mvs-chat-request-banner__actions">
		<button class="mvs-chat-request-banner__btn mvs-chat-request-banner__btn--accept" data-wp-on--click="actions.acceptRequest" type="button"><?php esc_html_e( 'Accept', 'wpmediaverse' ); ?></button>
		<button class="mvs-chat-request-banner__btn mvs-chat-request-banner__btn--decline" data-wp-on--click="actions.declineRequest" type="button"><?php esc_html_e( 'Decline', 'wpmediaverse' ); ?></button>
	</div>
</div>

<!-- Composer -->
<div data-wp-bind--hidden="state.isRequest">
	<?php require __DIR__ . '/chat-composer.php'; ?>
</div>
