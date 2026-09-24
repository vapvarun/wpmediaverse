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
		<img data-wp-bind--src="state.headerAvatar" data-wp-bind--hidden="!state.headerAvatar" alt="" width="36" height="36" />
		<span class="mvs-online-dot" data-wp-bind--hidden="!state.headerOnline"></span>
	</div>
	<div class="mvs-chat-header__info">
		<div class="mvs-chat-header__title" data-wp-text="state.headerTitle"></div>
		<div class="mvs-chat-header__subtitle" data-wp-text="state.headerSubtitle"></div>
	</div>
	<!-- Group info / roster — hidden for 1:1 threads -->
	<button
		class="mvs-chat-header__roster"
		data-wp-on--click="actions.openRoster"
		data-wp-bind--hidden="!state.isGroupConversation"
		type="button"
		aria-label="<?php esc_attr_e( 'Group info', 'wpmediaverse' ); ?>"
		title="<?php esc_attr_e( 'Group info', 'wpmediaverse' ); ?>"
	>
		<svg viewBox="0 0 24 24" width="20" height="20" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z" fill="currentColor"/></svg>
	</button>
	<button
		class="mvs-chat-header__menu"
		data-wp-on--click="actions.toggleMute"
		data-wp-class--is-muted="state.isMuted"
		data-wp-bind--aria-pressed="state.isMuted"
		data-wp-bind--aria-label="state.muteLabel"
		data-wp-bind--title="state.muteLabel"
		type="button"
	>
		<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.9 2 2 2zm6-6v-5c0-3.07-1.63-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.64 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2z" fill="currentColor"/></svg>
	</button>
</div>

<!-- Group roster panel — title, member list, admin-only rename/add/remove, leave -->
<div
	class="mvs-chat-roster"
	data-wp-bind--hidden="!state.rosterOpen"
	role="dialog"
	aria-modal="true"
	aria-labelledby="mvs-chat-roster-heading"
>
	<div class="mvs-chat-roster__header">
		<h4 id="mvs-chat-roster-heading" class="mvs-chat-roster__heading"><?php esc_html_e( 'Group info', 'wpmediaverse' ); ?></h4>
		<button class="mvs-chat-roster__close" data-wp-on--click="actions.closeRoster" type="button" aria-label="<?php esc_attr_e( 'Close', 'wpmediaverse' ); ?>">
			<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z" fill="currentColor"/></svg>
		</button>
	</div>

	<!-- Title + rename (group admin only) -->
	<div class="mvs-chat-roster__title-row">
		<div class="mvs-chat-roster__title-view" data-wp-bind--hidden="state.editingGroupTitle">
			<span class="mvs-chat-roster__title-text" data-wp-text="state.groupTitle"></span>
			<button
				class="mvs-chat-roster__rename"
				type="button"
				data-wp-on--click="actions.startRenameGroup"
				data-wp-bind--hidden="!state.canManageGroup"
			><?php esc_html_e( 'Rename', 'wpmediaverse' ); ?></button>
		</div>
		<div class="mvs-chat-roster__title-edit" data-wp-bind--hidden="!state.editingGroupTitle">
			<input
				type="text"
				class="mvs-chat-roster__title-input"
				data-wp-bind--value="state.groupTitleDraft"
				data-wp-on--input="actions.updateGroupTitleDraft"
			/>
			<button class="mvs-chat-roster__rename-save" type="button" data-wp-on--click="actions.saveGroupTitle"><?php esc_html_e( 'Save', 'wpmediaverse' ); ?></button>
		</div>
	</div>

	<!-- Members -->
	<div class="mvs-chat-roster__section-row">
		<span class="mvs-chat-roster__section-title"><?php esc_html_e( 'Members', 'wpmediaverse' ); ?> (<span data-wp-text="state.groupMemberCount"></span>)</span>
		<button
			class="mvs-chat-roster__add-toggle"
			type="button"
			data-wp-on--click="actions.toggleGroupAddPanel"
			data-wp-bind--hidden="!state.canManageGroup"
		><?php esc_html_e( 'Add people', 'wpmediaverse' ); ?></button>
	</div>

	<!-- Add people (group admin only) -->
	<div class="mvs-chat-roster__add-panel" data-wp-bind--hidden="!state.showGroupAddPanel">
		<input
			type="text"
			class="mvs-chat-search__input"
			placeholder="<?php esc_attr_e( 'Search users…', 'wpmediaverse' ); ?>"
			data-wp-bind--value="state.groupAddQuery"
			data-wp-on--input="actions.updateGroupAddQuery"
		/>
		<template data-wp-each="state.groupAddResults">
			<button class="mvs-chat-roster__add-result" type="button" data-wp-on--click="actions.addGroupMember">
				<img data-wp-bind--src="context.item.avatar_url" alt="" width="28" height="28" />
				<span data-wp-text="context.item.display_name"></span>
			</button>
		</template>
	</div>

	<ul class="mvs-chat-roster__list">
		<template data-wp-each="state.activeGroupParticipants">
			<li class="mvs-chat-roster__member">
				<img class="mvs-chat-roster__member-avatar" data-wp-bind--src="context.item.avatar_url" alt="" width="32" height="32" />
				<span class="mvs-chat-roster__member-name" data-wp-text="context.item.display_name"></span>
				<span class="mvs-chat-roster__member-admin" data-wp-bind--hidden="!context.item.isAdmin"><?php esc_html_e( 'Admin', 'wpmediaverse' ); ?></span>
				<button
					class="mvs-chat-roster__member-remove"
					type="button"
					data-wp-bind--hidden="!context.item.canRemove"
					data-wp-on--click="actions.removeGroupMember"
					aria-label="<?php esc_attr_e( 'Remove member', 'wpmediaverse' ); ?>"
					title="<?php esc_attr_e( 'Remove member', 'wpmediaverse' ); ?>"
				>
					<svg viewBox="0 0 24 24" width="16" height="16" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z" fill="currentColor"/></svg>
				</button>
			</li>
		</template>
	</ul>

	<button
		class="mvs-chat-roster__leave"
		type="button"
		data-wp-on--click="actions.leaveGroup"
		data-wp-bind--hidden="!state.hasGroupsRestBase"
	>
		<?php esc_html_e( 'Leave group', 'wpmediaverse' ); ?>
	</button>
</div>

<!-- Messages Area -->
<div class="mvs-chat-messages" data-wp-init="callbacks.onMessagesMount" data-wp-on--click="actions.hideContextMenu">
	<!-- Load More -->
	<div class="mvs-chat-messages__load-more" data-wp-bind--hidden="!state.hasMoreMessages">
		<button data-wp-on--click="actions.loadOlderMessages" type="button">
			<span data-wp-bind--hidden="state.loadingMessages"><?php esc_html_e( 'Load older messages', 'wpmediaverse' ); ?></span>
			<span data-wp-bind--hidden="!state.loadingMessages" hidden><?php esc_html_e( 'Loading...', 'wpmediaverse' ); ?></span>
		</button>
	</div>

	<!-- Message Bubbles -->
	<template data-wp-each="state.displayMessages">
		<?php require __DIR__ . '/chat-message.php'; ?>
	</template>

	<!-- Empty thread: a brand-new conversation with no messages yet -->
	<div class="mvs-chat-messages__empty" data-wp-bind--hidden="!state.showThreadEmpty">
		<p><?php esc_html_e( 'No messages yet', 'wpmediaverse' ); ?></p>
		<p><?php esc_html_e( 'Say hello to start the conversation.', 'wpmediaverse' ); ?></p>
	</div>

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
