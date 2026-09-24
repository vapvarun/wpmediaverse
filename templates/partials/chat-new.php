<?php
/**
 * New conversation — user search + recent contacts.
 *
 * @package WPMediaVerse
 * @since   1.1.0
 */

defined( 'ABSPATH' ) || exit;
?>
<!-- Header -->
<div class="mvs-chat-header">
	<button class="mvs-chat-header__back" data-wp-on--click="actions.goBackToList" type="button" aria-label="<?php esc_attr_e( 'Back', 'wpmediaverse' ); ?>">
		<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20v-2z" fill="currentColor"/></svg>
	</button>
	<h3 class="mvs-chat-header__title" data-wp-text="state.newMessageHeaderTitle"><?php esc_html_e( 'New Message', 'wpmediaverse' ); ?></h3>
	<button class="mvs-chat-header__close" data-wp-on--click="actions.closeChatPanel" type="button" aria-label="<?php esc_attr_e( 'Close', 'wpmediaverse' ); ?>">
		<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z" fill="currentColor"/></svg>
	</button>
</div>

<div class="mvs-chat-new">
	<!-- "New group" entry point (Pro only — hidden when groupsRestBase is empty) -->
	<button
		class="mvs-chat-new__group-toggle"
		data-wp-on--click="actions.startNewGroup"
		data-wp-bind--hidden="!state.showNewGroupToggle"
		type="button"
	>
		<svg viewBox="0 0 24 24" width="20" height="20" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z" fill="currentColor"/></svg>
		<?php esc_html_e( 'New group', 'wpmediaverse' ); ?>
	</button>

	<!-- Group setup — name + selected member chips -->
	<div class="mvs-chat-new__group-setup" data-wp-bind--hidden="!state.newGroupMode">
		<input
			class="mvs-chat-new__group-name"
			type="text"
			placeholder="<?php esc_attr_e( 'Group name (optional)', 'wpmediaverse' ); ?>"
			data-wp-bind--value="state.newGroupTitle"
			data-wp-on--input="actions.updateNewGroupTitle"
		/>
		<ul class="mvs-chat-new__chips" data-wp-bind--hidden="state.newGroupSelectedEmpty">
			<template data-wp-each="state.newGroupSelected">
				<li class="mvs-chat-new__chip">
					<span data-wp-text="context.item.display_name"></span>
					<button
						class="mvs-chat-new__chip-remove"
						type="button"
						data-wp-on--click="actions.removeNewGroupMember"
						aria-label="<?php esc_attr_e( 'Remove', 'wpmediaverse' ); ?>"
					>
						<svg viewBox="0 0 24 24" width="12" height="12" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z" fill="currentColor"/></svg>
					</button>
				</li>
			</template>
		</ul>
		<div class="mvs-chat-new__group-actions">
			<button
				class="mvs-chat-new__group-create"
				type="button"
				data-wp-on--click="actions.createGroup"
				data-wp-bind--disabled="!state.canCreateGroup"
			><?php esc_html_e( 'Create', 'wpmediaverse' ); ?></button>
			<button
				class="mvs-chat-new__group-cancel"
				type="button"
				data-wp-on--click="actions.cancelNewGroup"
			><?php esc_html_e( 'Cancel', 'wpmediaverse' ); ?></button>
		</div>
	</div>

	<!-- Search -->
	<div class="mvs-chat-search">
		<input
			class="mvs-chat-search__input"
			type="text"
			placeholder="<?php esc_attr_e( 'Search users...', 'wpmediaverse' ); ?>"
			data-wp-on--input="actions.updateSearchQuery"
			data-wp-bind--value="state.searchQuery"
		/>
	</div>

	<!-- Search Results -->
	<div class="mvs-chat-new__results">
		<div class="mvs-chat-new__section-title" data-wp-bind--hidden="!state.hasSearchResults"><?php esc_html_e( 'Search Results', 'wpmediaverse' ); ?></div>

		<template data-wp-each="state.searchResults">
			<button
				class="mvs-chat-new__user"
				data-wp-on--click="actions.onSearchResultClick"
				type="button"
			>
				<img data-wp-bind--src="context.item.avatar_url" alt="" width="40" height="40" />
				<span class="mvs-chat-new__user-name" data-wp-text="context.item.display_name"></span>
			</button>
		</template>

		<div class="mvs-chat-list__empty" data-wp-bind--hidden="!state.noSearchResults">
			<?php esc_html_e( 'No users found', 'wpmediaverse' ); ?>
		</div>

		<div class="mvs-chat-list__empty" data-wp-bind--hidden="state.searchQueryReady">
			<?php esc_html_e( 'Type a name to search for users', 'wpmediaverse' ); ?>
		</div>
	</div>
</div>
