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
	<button class="mvs-chat-header__back" data-wp-on--click="actions.goBackToList" type="button" aria-label="Back">
		<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20v-2z" fill="currentColor"/></svg>
	</button>
	<h3 class="mvs-chat-header__title">New Message</h3>
	<button class="mvs-chat-header__close" data-wp-on--click="actions.closeChatPanel" type="button" aria-label="Close">
		<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z" fill="currentColor"/></svg>
	</button>
</div>

<div class="mvs-chat-new">
	<!-- Search -->
	<div class="mvs-chat-search">
		<input
			class="mvs-chat-search__input"
			type="text"
			placeholder="Search users..."
			data-wp-on--input="actions.updateSearchQuery"
			data-wp-bind--value="state.searchQuery"
			autofocus
		/>
	</div>

	<!-- Search Results -->
	<div class="mvs-chat-new__results">
		<div class="mvs-chat-new__section-title" data-wp-bind--hidden="!state.hasSearchResults">Search Results</div>

		<template data-wp-each="state.searchResults">
			<button
				class="mvs-chat-new__user"
				data-wp-on--click="actions.createOrOpenConversation"
				type="button"
			>
				<img data-wp-bind--src="context.item.avatar_url" alt="" width="40" height="40" />
				<span class="mvs-chat-new__user-name" data-wp-text="context.item.display_name"></span>
			</button>
		</template>

		<div class="mvs-chat-list__empty" data-wp-bind--hidden="!state.noSearchResults">
			No users found
		</div>

		<div class="mvs-chat-list__empty" data-wp-bind--hidden="state.searchQueryReady">
			Type a name to search for users
		</div>
	</div>
</div>
