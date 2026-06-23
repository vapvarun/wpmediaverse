/**
 * WPMediaVerse — Messaging Store (Interactivity API)
 *
 * @package WPMediaVerse
 * @since   1.1.0
 */

import { store, getContext, getElement } from '@wordpress/interactivity';

// i18n runtime shim — script modules can't import @wordpress/i18n yet,
// so we read it from the global wp.i18n that core enqueues for admin
// + Interactivity-API-aware frontend. Falls back to the literal string
// if the global isn't present (e.g. before wp-i18n boots).
const __ = ( str, domain ) => ( window.wp && window.wp.i18n && window.wp.i18n.__ ) ? window.wp.i18n.__( str, domain || 'wpmediaverse' ) : str;

const config = window.mvsMessagingConfig || {};
const REST   = config.restBase || '/wp-json/mvs/v1';
const NONCE  = config.nonce || '';
const ME     = config.currentUser || {};
const TRANSPORT = config.transport || { type: 'polling', intervals: { active: 3000, list: 10000, background: 30000 } };

// Helper: REST fetch with auth.
async function apiFetch( path, options = {} ) {
	const url = path.startsWith( 'http' ) ? path : REST + path;

	// Call sites pre-stringify JSON bodies, so restFetch() (which only
	// auto-sets Content-Type for plain-object bodies) leaves the header off.
	// Without it WordPress REST never parses the JSON body and required
	// params 400. Set it here for every string body — the single chokepoint
	// all messaging POST/PATCH calls flow through. FormData uploads bypass
	// apiFetch and must keep the browser's multipart boundary, so they are
	// unaffected.
	if ( typeof options.body === 'string' ) {
		options.headers = Object.assign(
			{ 'Content-Type': 'application/json' },
			options.headers
		);
	}

	const res = await window.mvsRest.restFetch( url, options );

	if ( res.status === 204 ) return null;
	const data = res.data;
	if ( ! res.ok ) throw new Error( ( data && ( data.error || data.message ) ) || __( 'Request failed', 'wpmediaverse' ) );
	return data;
}

// Helper: format relative time.
function relativeTime( dateStr ) {
	if ( ! dateStr ) return '';
	const diff = ( Date.now() - new Date( dateStr ).getTime() ) / 1000;
	if ( diff < 60 ) return 'now';
	if ( diff < 3600 ) return Math.floor( diff / 60 ) + 'm';
	if ( diff < 86400 ) return Math.floor( diff / 3600 ) + 'h';
	if ( diff < 604800 ) return Math.floor( diff / 86400 ) + 'd';
	return new Date( dateStr ).toLocaleDateString();
}

// Helper: format duration.
function formatDuration( seconds ) {
	const m = Math.floor( seconds / 60 );
	const s = Math.floor( seconds % 60 );
	return m + ':' + String( s ).padStart( 2, '0' );
}

// Temp ID counter for optimistic messages.
let tempIdCounter = 0;

// Derive the sidebar preview string for a single message, mirroring the
// server's MessagingService::send_message() preview rules so the frontend
// recompute (after a delete) matches what the API would have stored.
function messagePreview( msg ) {
	if ( ! msg ) return '';
	const content = ( msg.content || '' ).trim();
	if ( content ) return content.slice( 0, 100 );
	switch ( msg.message_type ) {
		case 'voice':
		case 'audio':
			return 'Voice message';
		case 'media_share':
			return 'Shared a media';
		case 'image':
		case 'video':
		case 'file':
			return msg.message_type.charAt( 0 ).toUpperCase() + msg.message_type.slice( 1 );
		default:
			return '';
	}
}

// Unsent-message ids the poll handler has already reacted to. The poll
// re-serves an unsent row for the whole unsend window (mvs_messages has no
// updated_at to scope "unsent since last poll"), so without this the client
// would re-render and reload the sidebar on every poll tick.
const seenUnsendIds = new Set();

// onInit idempotency guards.
// openConversationBound: ensures the mvs-open-conversation listener is added at
// most once across the page lifetime (the slide-out persists in wp_footer and
// its store is never re-mounted, but onInit can be called again by the
// Interactivity API on partial hydration).
let openConversationBound = false;

// Enrich message with pre-computed boolean flags for Interactivity API directives.
function enrichMessage( msg ) {
	// Interactivity API does NOT track underscore-prefixed properties on
	// context.item inside data-wp-each. Use plain camelCase names instead.
	msg.isSent     = msg.sender_id === ME.id || String( msg.sender_id ) === String( ME.id );
	msg.isReceived = ! msg.isSent && msg.message_type !== 'system';
	msg.isSystem   = msg.message_type === 'system';
	// Optimistic send lifecycle. Plain camelCase so Interactivity tracks them
	// inside data-wp-each (underscore-prefixed props silently don't track), and
	// so a failed send shows an error + retry instead of looking delivered.
	msg.isSending  = !! msg._sending;
	msg.isFailed   = !! msg._failed;
	msg.notSending = ! msg.isSending;
	msg.notFailed  = ! msg.isFailed;
	// Hide the delivered double-check while the message is still in flight or
	// failed (Interactivity directives can't OR, so derive it here).
	msg.hideCheck  = msg.isReceived || msg.isSending || msg.isFailed;
	// Deleted covers BOTH server flags: deleted_for_all (unsend) and
	// is_deleted (delete-for-me — get_messages still returns the row to the
	// sender, blanked). Only checking deleted_for_all left the sender's
	// reopened chat rendering a blue empty bubble (Basecamp #9962618059).
	msg.isDeleted  = Number( msg.deleted_for_all ) === 1 || Number( msg.is_deleted ) === 1;
	msg.notDeleted = ! msg.isDeleted;
	// Normalize a deleted message before the type flags below: text type
	// (hides image/video/voice/file/media-share sections), no content, no
	// metadata — same shape the local unsend/delete transforms produce.
	if ( msg.isDeleted ) {
		msg.content      = '';
		msg.message_type = 'text';
		msg.metadata     = null;
	}
	msg.showMenu   = false;
	msg.noMenu     = true;
	msg.hasReply   = !! msg.parent_id && msg.parent_id !== '0';
	msg.noReply    = ! msg.hasReply;
	msg.hasReactions = Array.isArray( msg.reactions ) && msg.reactions.length > 0;
	msg.noReactions  = ! msg.hasReactions;
	msg.notText    = msg.message_type !== 'text';
	msg.notImage   = msg.message_type !== 'image';
	msg.notVideo   = msg.message_type !== 'video';
	msg.notVoice   = msg.message_type !== 'voice' && msg.message_type !== 'audio';
	msg.notFile    = msg.message_type !== 'file';
	msg.notMediaShare = msg.message_type !== 'media_share';

	// Show text content alongside attachments when both are present.
	msg.hasTextContent = !! ( msg.content && msg.content.trim() );
	msg.noTextContent  = ! msg.hasTextContent;

	// Parse metadata if it's a JSON string.
	if ( typeof msg.metadata === 'string' && msg.metadata ) {
		try { msg.metadata = JSON.parse( msg.metadata ); } catch ( e ) { msg.metadata = null; }
	}
	if ( ! msg.metadata ) {
		msg.metadata = {};
	}

	// Ensure nested objects exist so Interactivity API bindings
	// (e.g. context.item.attachment.url) don't throw on text messages.
	if ( ! msg.attachment ) {
		msg.attachment = { url: '', thumbnail: '', name: '' };
	}
	if ( ! msg.parent_preview ) {
		msg.parent_preview = { sender: '', content: '' };
	}
	return msg;
}

const { state, actions } = store( 'mvs/messaging', {
	state: {
		conversations: [],
		activeConversationId: null,
		messages: [],
		composerText: '',
		replyingTo: null,
		sendingMessage: false,
		totalUnread: 0,
		chatPanelOpen: false,
		chatView: 'list', // list, conversation, new
		activeTab: 'all', // all, unread, requests
		searchQuery: '',
		searchResults: [],
		typingUsers: [],
		isRecordingVoice: false,
		voiceDuration: 0,
		selectedAttachment: null,
		attachmentPreview: null,
		pollingSince: new Date().toISOString(),
		pollingTimer: null,
		unreadTimer: null,
		loadingConversations: false,
		loadingMessages: false,
		hasMoreMessages: true,
		contextMenuMessageId: null,
		pendingMediaShareId: null,

		// Voice recording internals.
		_mediaRecorder: null,
		_audioChunks: [],
		_voiceTimer: null,
		_voiceSpeed: 1,
		_playingVoiceId: null,
		_voiceAudio: null,

		// Derived state getters.
		get hasUnread() {
			return state.totalUnread > 0;
		},

		get activeConversation() {
			return state.conversations.find( c => String( c.id ) === String( state.activeConversationId ) ) || null;
		},

		get otherParticipant() {
			const conv = state.activeConversation;
			if ( ! conv || ! conv.participants ) return {};
			return conv.participants.find( p => p.id !== ME.id ) || {};
		},

		get otherName() {
			return state.otherParticipant?.display_name || '';
		},

		get otherAvatar() {
			return state.otherParticipant?.avatar_url || '';
		},

		get canSend() {
			if ( state.sendingMessage ) return false;
			if ( state.isRecordingVoice ) return false;
			const hasText = state.composerText.trim().length > 0;
			const hasAttachment = !! state.selectedAttachment;
			return hasText || hasAttachment;
		},

		get pinnedConversations() {
			return state.conversations.filter( c => c.is_pinned );
		},

		get requestConversations() {
			return state.conversations.filter( c => c.participant_status === 'request_pending' );
		},

		get isRequest() {
			const conv = state.activeConversation;
			return conv && conv.participant_status === 'request_pending';
		},

		get otherIsOnline() {
			const p = state.otherParticipant;
			return p ? p.is_online : false;
		},

		get otherLastActive() {
			const p = state.otherParticipant;
			if ( ! p || ! p.last_active ) return '';
			if ( p.is_online ) return 'Online';
			return 'Active ' + relativeTime( p.last_active ) + ' ago';
		},

		get voiceDurationFormatted() {
			return formatDuration( state.voiceDuration );
		},

		get voiceSpeedLabel() { return state._voiceSpeed + 'x'; },

		get hideUnsend() {
			const ctx = getContext();
			const msg = ctx.item;
			if ( ! msg || ! msg.isSent ) return true;
			// Only allow unsend within 15 minutes.
			const created = new Date( msg.created_at ).getTime();
			const fifteenMin = 15 * 60 * 1000;
			return ( Date.now() - created ) > fifteenMin;
		},

		get messageDuration() {
			const ctx = getContext();
			const meta = ctx.item?.metadata;
			if ( ! meta || ! meta.duration ) return '';
			return formatDuration( Number( meta.duration ) );
		},

		// View checks (directives can't do === comparisons).
		get isViewList() { return state.chatView === 'list'; },
		get isViewConversation() { return state.chatView === 'conversation'; },
		get isViewNew() { return state.chatView === 'new'; },

		// Tab checks.
		get isTabAll() { return state.activeTab === 'all'; },
		get isTabUnread() { return state.activeTab === 'unread'; },
		get isTabRequests() { return state.activeTab === 'requests'; },

		// Empty state.
		get hasConversations() { return state.conversations.length > 0; },
		get noConversations() { return state.conversations.length === 0; },
		// Distinct from noConversations: only show the "no conversations" empty
		// state once loading has finished, so a slow load shows a spinner instead
		// of falsely reading "no conversations". Likewise for an empty thread.
		get showListEmpty() { return ! state.loadingConversations && state.conversations.length === 0; },
		get showThreadEmpty() { return ! state.loadingMessages && state.messages.length === 0; },

		// Reply preview.
		get replyToName() { return state.replyingTo?.sender_name || ''; },
		get replyToContent() { return state.replyingTo?.content || ''; },
		get hasReplyTo() { return !! state.replyingTo; },

		// Search state.
		get hasSearchResults() { return state.searchResults.length > 0; },
		get searchQueryShort() { return state.searchQuery.length < 2; },
		get searchQueryReady() { return state.searchQuery.length >= 2; },
		get noSearchResults() { return state.searchQuery.length >= 2 && state.searchResults.length === 0; },
	},

	actions: {
		// ---- Panel ----
		toggleChatPanel() {
			state.chatPanelOpen = ! state.chatPanelOpen;
			if ( state.chatPanelOpen ) {
				actions.startPolling();
				if ( state.conversations.length === 0 ) {
					actions.loadConversations();
				}
			} else {
				actions.stopPolling();
			}
		},

		closeChatPanel() {
			state.chatPanelOpen = false;
			actions.stopPolling();
		},

		/**
		 * Open panel in "new conversation" view with a media item queued to share.
		 * Called cross-store from instagram-feed: store('mvs/messaging').actions.openWithMediaShare( mediaId ).
		 *
		 * @param {number} mediaId The mvs_media post ID to share after selecting a recipient.
		 */
		openWithMediaShare( mediaId ) {
			state.pendingMediaShareId = mediaId || null;
			state.chatView = 'new';
			state.chatPanelOpen = true;
			actions.startPolling();
			if ( state.conversations.length === 0 ) {
				actions.loadConversations();
			}
		},

		/**
		 * Open panel and start/open a DM with a specific user.
		 * Called cross-store from profile: store('mvs/messaging').actions.openWithRecipient( userId ).
		 *
		 * @param {number} userId The user ID to message.
		 */
		*openWithRecipient( userId ) {
			if ( ! userId ) return;
			state.chatPanelOpen = true;
			actions.startPolling();

			try {
				const data = yield apiFetch( '/conversations', {
					method: 'POST',
					body: JSON.stringify( { recipient_id: userId } ),
				} );

				if ( data.conversation ) {
					const idx = state.conversations.findIndex( c => String( c.id ) === String( data.conversation.id ) );
					if ( idx >= 0 ) {
						state.conversations[ idx ] = data.conversation;
					} else {
						state.conversations.unshift( data.conversation );
					}

					state.activeConversationId = data.conversation.id;
					state.chatView = 'conversation';
					state.messages = [];
					state.hasMoreMessages = true;
					yield actions.loadMessages();
				}
			} catch ( e ) {
				actions.showToast( e.message || __( 'Could not open conversation.', 'wpmediaverse' ) );
			}
		},

		goBackToList() {
			state.chatView = 'list';
			state.activeConversationId = null;
			state.messages = [];
			state.typingUsers = [];
			state.replyingTo = null;
			state.contextMenuMessageId = null;
		},

		openNewConversation() {
			state.chatView = 'new';
			state.searchQuery = '';
			state.searchResults = [];
			// Focus the recipient search once the 'new' view renders. Replaces
			// the input's autofocus attribute, which fired on every page load
			// (the chat panel ships in the DOM site-wide) and scrolled the page
			// down to the still-hidden panel before hydration.
			setTimeout( () => {
				const input = document.querySelector( '.mvs-chat-search__input' );
				if ( input ) {
					input.focus();
				}
			}, 50 );
		},

		// ---- Tabs ----
		setTab() {
			const ctx = getContext();
			if ( ctx.tab ) {
				state.activeTab = ctx.tab;
				actions.loadConversations();
			}
		},

		// ---- Conversations ----
		*loadConversations() {
			state.loadingConversations = true;
			try {
				const data = yield apiFetch( '/me/conversations?tab=' + state.activeTab );
				// Pre-compute other participant for each conversation (avoids array indexing in directives).
				state.conversations = ( data || [] ).map( conv => {
					const other = ( conv.participants || [] ).find( p => p.id !== ME.id ) || conv.participants?.[0] || {};
					conv.otherName   = other.display_name || '';
					conv.otherAvatar = other.avatar_url || '';
					conv.otherOnline = !! other.is_online;
					conv.hasUnread   = !! conv.unread_count;
					return conv;
				} );
			} catch ( e ) {
				// Silently fail, keep existing data.
			}
			state.loadingConversations = false;
		},

		*openConversation() {
			const ctx = getContext();
			const convId = ctx.convId || ctx.item?.id;
			if ( ! convId ) return;

			state.activeConversationId = convId;
			state.chatView = 'conversation';
			state.messages = [];
			state.hasMoreMessages = true;
			state.replyingTo = null;
			state.contextMenuMessageId = null;

			yield actions.loadMessages();
			yield actions.markRead();
		},

		*createOrOpenConversation() {
			const ctx = getContext();
			const userId = ctx.userId || ctx.item?.id;
			if ( ! userId ) return;

			try {
				const data = yield apiFetch( '/conversations', {
					method: 'POST',
					body: JSON.stringify( { recipient_id: userId } ),
				} );

				if ( data.conversation ) {
					// Add or update in list.
					const idx = state.conversations.findIndex( c => String( c.id ) === String( data.conversation.id ) );
					if ( idx >= 0 ) {
						state.conversations[ idx ] = data.conversation;
					} else {
						state.conversations.unshift( data.conversation );
					}

					state.activeConversationId = data.conversation.id;
					state.chatView = 'conversation';
					state.messages = [];
					state.hasMoreMessages = true;
					yield actions.loadMessages();

					// Auto-send pending media share if queued.
					if ( state.pendingMediaShareId ) {
						const mediaId = state.pendingMediaShareId;
						state.pendingMediaShareId = null;
						try {
							const msg = yield apiFetch(
								'/conversations/' + data.conversation.id + '/messages',
								{
									method: 'POST',
									body: JSON.stringify( {
										content: '',
										message_type: 'media_share',
										media_id: mediaId,
									} ),
								}
							);
							state.messages = [ ...state.messages, enrichMessage( msg ) ];
							actions.scrollToBottom();
						} catch ( shareErr ) {
							actions.showToast( __( 'Could not share media.', 'wpmediaverse' ) );
						}
					}
				}
			} catch ( e ) {
				actions.showToast( e.message );
			}
		},

		*acceptRequest() {
			const convId = state.activeConversationId;
			if ( ! convId ) return;

			try {
				yield apiFetch( '/conversations/' + convId + '/accept', { method: 'POST' } );
				// Update local state.
				const conv = state.activeConversation;
				if ( conv ) conv.participant_status = 'active';
			} catch ( e ) {
				actions.showToast( e.message );
			}
		},

		*declineRequest() {
			const convId = state.activeConversationId;
			if ( ! convId ) return;

			try {
				yield apiFetch( '/conversations/' + convId + '/decline', { method: 'POST' } );
				state.conversations = state.conversations.filter( c => String( c.id ) !== String( convId ) );
				actions.goBackToList();
			} catch ( e ) {
				actions.showToast( e.message );
			}
		},

		*toggleMute() {
			const convId = state.activeConversationId;
			const conv = state.activeConversation;
			if ( ! conv ) return;

			const newMuted = ! conv.is_muted;
			try {
				yield apiFetch( '/conversations/' + convId, {
					method: 'PATCH',
					body: JSON.stringify( { is_muted: newMuted } ),
				} );
				conv.is_muted = newMuted ? 1 : 0;
			} catch ( e ) {
				actions.showToast( e.message );
			}
		},

		*togglePin() {
			const convId = state.activeConversationId;
			const conv = state.activeConversation;
			if ( ! conv ) return;

			const newPinned = ! conv.is_pinned;
			try {
				yield apiFetch( '/conversations/' + convId, {
					method: 'PATCH',
					body: JSON.stringify( { is_pinned: newPinned } ),
				} );
				conv.is_pinned = newPinned ? 1 : 0;
			} catch ( e ) {
				actions.showToast( e.message );
			}
		},

		*archiveConversation() {
			const convId = state.activeConversationId;
			if ( ! convId ) return;

			try {
				yield apiFetch( '/conversations/' + convId, {
					method: 'PATCH',
					body: JSON.stringify( { is_archived: true } ),
				} );
				state.conversations = state.conversations.filter( c => String( c.id ) !== String( convId ) );
				actions.goBackToList();
			} catch ( e ) {
				actions.showToast( e.message );
			}
		},

		*deleteConversation() {
			const convId = state.activeConversationId;
			if ( ! convId ) return;

			try {
				yield apiFetch( '/conversations/' + convId, { method: 'DELETE' } );
				state.conversations = state.conversations.filter( c => String( c.id ) !== String( convId ) );
				actions.goBackToList();
			} catch ( e ) {
				actions.showToast( e.message );
			}
		},

		// ---- Messages ----
		*loadMessages() {
			if ( ! state.activeConversationId || state.loadingMessages ) return;

			state.loadingMessages = true;
			try {
				const data = yield apiFetch(
					'/conversations/' + state.activeConversationId + '/messages?per_page=30'
				);
				state.messages = data.map( enrichMessage );
				state.hasMoreMessages = data.length >= 30;
				actions.scrollToBottom();
			} catch ( e ) {
				// Silently fail.
			}
			state.loadingMessages = false;
		},

		*loadOlderMessages() {
			if ( ! state.activeConversationId || state.loadingMessages || ! state.hasMoreMessages ) return;
			if ( state.messages.length === 0 ) return;

			state.loadingMessages = true;
			const firstId = state.messages[0].id;
			try {
				const data = yield apiFetch(
					'/conversations/' + state.activeConversationId + '/messages?per_page=30&before=' + firstId
				);
				state.messages = [ ...data.map( enrichMessage ), ...state.messages ];
				state.hasMoreMessages = data.length >= 30;
			} catch ( e ) {
				// Silently fail.
			}
			state.loadingMessages = false;
		},

		*sendMessage() {
			if ( ! state.canSend || ! state.activeConversationId ) return;

			state.sendingMessage = true;
			const content = state.composerText.trim();
			const parentId = state.replyingTo?.id || null;

			// Build message data.
			const body = { content, message_type: 'text' };
			if ( parentId ) body.parent_id = parentId;

			if ( state.selectedAttachment ) {
				body.attachment_id = state.selectedAttachment.id;
				body.message_type = state.selectedAttachment.type || 'file';
			}

			// Optimistic: add temp message immediately.
			const tempId = 'temp-' + ( ++tempIdCounter );
			const tempMsg = enrichMessage( {
				id: tempId,
				conversation_id: state.activeConversationId,
				sender_id: ME.id,
				sender_name: ME.name,
				sender_avatar: ME.avatar,
				content,
				message_type: body.message_type,
				created_at: new Date().toISOString(),
				reactions: [],
				is_deleted: 0,
				deleted_for_all: 0,
				parent_id: parentId,
				parent_preview: state.replyingTo ? { id: state.replyingTo.id, content: state.replyingTo.content, sender: state.replyingTo.sender_name, type: 'text' } : null,
				_sending: true,
			} );
			state.messages = [ ...state.messages, tempMsg ];
			state.composerText = '';
			state.replyingTo = null;
			state.selectedAttachment = null;
			state.attachmentPreview = null;
			actions.scrollToBottom();

			try {
				const realMsg = yield apiFetch(
					'/conversations/' + state.activeConversationId + '/messages',
					{ method: 'POST', body: JSON.stringify( body ) }
				);

				// Replace temp with real message.
				state.messages = state.messages.map( m => m.id === tempId ? enrichMessage( { ...realMsg, _sending: false } ) : m );

				// Update conversation preview.
				const conv = state.activeConversation;
				if ( conv ) {
					conv.last_message_preview = content.slice( 0, 100 ) || body.message_type;
					conv.last_activity_at = new Date().toISOString();
				}
			} catch ( e ) {
				// Mark as failed — re-enrich so isFailed/notSending recompute and
				// the bubble shows an error + retry instead of looking delivered.
				state.messages = state.messages.map( m => m.id === tempId ? enrichMessage( { ...m, _failed: true, _sending: false } ) : m );
				actions.showToast( e.message );
			}

			state.sendingMessage = false;
		},

		// Retry a failed optimistic send (re-uses the failed bubble's content).
		*retrySend() {
			const ctx = getContext();
			const msgId = ctx.item?.id || ctx.messageId;
			const failed = state.messages.find( m => String( m.id ) === String( msgId ) && m.isFailed );
			if ( ! failed ) return;

			state.messages = state.messages.map( m => String( m.id ) === String( msgId ) ? enrichMessage( { ...m, _failed: false, _sending: true } ) : m );

			const body = { content: failed.content || '', message_type: failed.message_type || 'text' };
			if ( failed.parent_id && failed.parent_id !== '0' ) body.parent_id = failed.parent_id;
			if ( failed.attachment && failed.attachment.id ) body.attachment_id = failed.attachment.id;

			try {
				const realMsg = yield apiFetch(
					'/conversations/' + state.activeConversationId + '/messages',
					{ method: 'POST', body: JSON.stringify( body ) }
				);
				state.messages = state.messages.map( m => String( m.id ) === String( msgId ) ? enrichMessage( { ...realMsg, _sending: false } ) : m );
			} catch ( e ) {
				state.messages = state.messages.map( m => String( m.id ) === String( msgId ) ? enrichMessage( { ...m, _failed: true, _sending: false } ) : m );
				actions.showToast( e.message );
			}
		},

		*deleteMessage() {
			const ctx = getContext();
			const msgId = ctx.messageId || state.contextMenuMessageId;
			if ( ! msgId ) return;

			// Was this the last message in the thread? If so the sidebar preview
			// needs to be recomputed from whatever remains after the delete.
			const wasLast = state.messages.length > 0
				&& String( state.messages[ state.messages.length - 1 ].id ) === String( msgId );

			try {
				yield apiFetch( '/messages/' + msgId, { method: 'DELETE' } );
				// Remove the message entirely from the thread — no greyed tombstone
				// for a delete-for-me. (Unsend keeps its own "message deleted" state.)
				state.messages = state.messages.filter( m => String( m.id ) !== String( msgId ) );

				// Recompute the conversation's last-message preview so the sidebar
				// stops showing the now-deleted message. Frontend state only — the
				// /messages DELETE route contract is unchanged.
				if ( wasLast ) {
					const conv = state.activeConversation;
					if ( conv ) {
						const remaining = state.messages.filter( m => m.notDeleted );
						const last = remaining.length > 0 ? remaining[ remaining.length - 1 ] : null;
						conv.last_message_preview = last ? messagePreview( last ) : '';
						conv.last_activity_at = last ? ( last.created_at || conv.last_activity_at ) : conv.last_activity_at;
					}
				}
			} catch ( e ) {
				actions.showToast( e.message );
			}
			state.contextMenuMessageId = null;
		},

		*unsendMessage() {
			const ctx = getContext();
			const msgId = ctx.messageId || state.contextMenuMessageId;
			if ( ! msgId ) return;

			try {
				yield apiFetch( '/messages/' + msgId + '/unsend', { method: 'DELETE' } );
				state.messages = state.messages.map( m =>
					String( m.id ) === String( msgId )
						? enrichMessage( { ...m, deleted_for_all: 1, content: '', message_type: 'text' } )
						: m
				);
			} catch ( e ) {
				actions.showToast( e.message );
			}
			state.contextMenuMessageId = null;
		},

		*markRead() {
			if ( ! state.activeConversationId ) return;
			try {
				yield apiFetch( '/conversations/' + state.activeConversationId + '/read', { method: 'POST' } );
				const conv = state.activeConversation;
				if ( conv ) {
					conv.unread_count = 0;
					conv.last_read_at = new Date().toISOString();
				}
				yield actions.refreshUnreadCount();
			} catch ( e ) {
				// Silently fail.
			}
		},

		// ---- Attachments ----
		openAttachmentPicker() {
			const input = document.createElement( 'input' );
			input.type = 'file';
			input.accept = 'image/*,video/*,audio/*,.pdf,.doc,.docx,.zip';
			input.onchange = ( e ) => {
				const file = e.target.files[0];
				if ( ! file ) return;

				// Determine type.
				let type = 'file';
				if ( file.type.startsWith( 'image/' ) ) type = 'image';
				else if ( file.type.startsWith( 'video/' ) ) type = 'video';
				else if ( file.type.startsWith( 'audio/' ) ) type = 'audio';

				// Preview.
				if ( type === 'image' ) {
					const reader = new FileReader();
					reader.onload = ( re ) => { state.attachmentPreview = re.target.result; };
					reader.readAsDataURL( file );
				} else {
					state.attachmentPreview = null;
				}

				// Upload via FormData.
				actions.uploadAttachment( file, type );
			};
			input.click();
		},

		*uploadAttachment( file, type ) {
			const formData = new FormData();
			formData.append( 'file', file );

			try {
				const res = yield window.mvsRest.restFetch( REST + '/messages/upload', {
					method: 'POST',
					body: formData,
				} );
				const data = res.data;

				if ( ! res.ok ) throw new Error( ( data && data.message ) || __( 'Upload failed', 'wpmediaverse' ) );

				state.selectedAttachment = {
					id: data.id,
					url: data.source_url,
					type,
					name: file.name,
					size: file.size,
				};
			} catch ( e ) {
				state.attachmentPreview = null;
				actions.showToast( e.message );
			}
		},

		cancelAttachment() {
			state.selectedAttachment = null;
			state.attachmentPreview = null;
		},

		// ---- Voice Recording ----
		*startVoiceRecording() {
			try {
				const stream = yield navigator.mediaDevices.getUserMedia( { audio: true } );
				const mimeType = MediaRecorder.isTypeSupported( 'audio/webm;codecs=opus' )
					? 'audio/webm;codecs=opus'
					: 'audio/mp4';

				const recorder = new MediaRecorder( stream, { mimeType } );
				state._audioChunks = [];
				state._mediaRecorder = recorder;
				state.isRecordingVoice = true;
				state.voiceDuration = 0;

				recorder.ondataavailable = ( e ) => {
					if ( e.data.size > 0 ) state._audioChunks.push( e.data );
				};

				recorder.start( 250 );

				// Duration timer.
				const maxDuration = 300; // 5 min.
				state._voiceTimer = setInterval( () => {
					state.voiceDuration++;
					if ( state.voiceDuration >= maxDuration ) {
						actions.stopVoiceRecording();
					}
				}, 1000 );

			} catch ( e ) {
				actions.showToast( __( 'Microphone access denied', 'wpmediaverse' ) );
			}
		},

		*stopVoiceRecording() {
			if ( ! state._mediaRecorder || state._mediaRecorder.state === 'inactive' ) return;

			const recorder = state._mediaRecorder;
			const duration = state.voiceDuration;

			return new Promise( ( resolve ) => {
				recorder.onstop = () => {
					clearInterval( state._voiceTimer );
					state.isRecordingVoice = false;

					const blob = new Blob( state._audioChunks, { type: recorder.mimeType } );
					state._audioChunks = [];

					// Stop all tracks.
					recorder.stream.getTracks().forEach( t => t.stop() );

					// Upload and send.
					actions.sendVoiceMessage( blob, duration, recorder.mimeType );
					resolve();
				};
				recorder.stop();
			} );
		},

		cancelVoiceRecording() {
			if ( state._mediaRecorder && state._mediaRecorder.state !== 'inactive' ) {
				state._mediaRecorder.stream.getTracks().forEach( t => t.stop() );
				state._mediaRecorder.stop();
			}
			clearInterval( state._voiceTimer );
			state.isRecordingVoice = false;
			state.voiceDuration = 0;
			state._audioChunks = [];
		},

		*sendVoiceMessage( blob, duration, mimeType ) {
			const ext = mimeType.includes( 'webm' ) ? '.webm' : '.mp4';
			const file = new File( [ blob ], 'voice' + ext, { type: mimeType } );

			// Upload.
			const formData = new FormData();
			formData.append( 'file', file );

			try {
				const res = yield window.mvsRest.restFetch( REST + '/messages/upload', {
					method: 'POST',
					body: formData,
				} );
				const data = res.data;
				if ( ! res.ok ) throw new Error( ( data && data.message ) || __( 'Upload failed', 'wpmediaverse' ) );

				// Send message.
				const msg = yield apiFetch(
					'/conversations/' + state.activeConversationId + '/messages',
					{
						method: 'POST',
						body: JSON.stringify( {
							content: '',
							message_type: 'voice',
							attachment_id: data.id,
							metadata: { duration },
						} ),
					}
				);

				state.messages = [ ...state.messages, enrichMessage( msg ) ];
				actions.scrollToBottom();
			} catch ( e ) {
				actions.showToast( e.message );
			}
		},

		toggleVoicePlayback( event ) {
			event.stopPropagation();
			const ctx = getContext();
			const msgId = ctx.item?.id;

			if ( state._playingVoiceId === msgId && state._voiceAudio ) {
				if ( state._voiceAudio.paused ) {
					state._voiceAudio.play().catch( () => {} );
				} else {
					state._voiceAudio.pause();
				}
				return;
			}

			// Stop current playback.
			if ( state._voiceAudio ) {
				state._voiceAudio.pause();
				state._voiceAudio = null;
				state._playingVoiceId = null;
			}

			const msg = state.messages.find( m => String( m.id ) === String( msgId ) );
			if ( ! msg || ! msg.attachment || ! msg.attachment.url ) return;

			// Use <audio> element — works for both audio/* and video/webm with audio tracks.
			const audio = document.createElement( 'audio' );
			audio.src = msg.attachment.url;
			audio.preload = 'auto';
			audio.playbackRate = state._voiceSpeed;

			state._voiceAudio = audio;
			state._playingVoiceId = msgId;

			audio.play().catch( ( err ) => {
				console.error( 'Voice playback failed:', err, msg.attachment.url );
				state._playingVoiceId = null;
				state._voiceAudio = null;
			} );

			audio.onended = () => {
				state._playingVoiceId = null;
				state._voiceAudio = null;
			};
		},

		setVoiceSpeed( event ) {
			event.stopPropagation();
			const speeds = [ 1, 1.5, 2 ];
			const idx = speeds.indexOf( state._voiceSpeed );
			state._voiceSpeed = speeds[ ( idx + 1 ) % speeds.length ];
			if ( state._voiceAudio ) {
				state._voiceAudio.playbackRate = state._voiceSpeed;
			}
		},

		// ---- Media Share ----
		*sendMediaShare() {
			const ctx = getContext();
			const mediaId = ctx.mediaId;
			if ( ! mediaId || ! state.activeConversationId ) return;

			try {
				const msg = yield apiFetch(
					'/conversations/' + state.activeConversationId + '/messages',
					{
						method: 'POST',
						body: JSON.stringify( {
							content: '',
							message_type: 'media_share',
							media_id: mediaId,
						} ),
					}
				);
				state.messages = [ ...state.messages, enrichMessage( msg ) ];
				actions.scrollToBottom();
			} catch ( e ) {
				actions.showToast( e.message );
			}
		},

		// ---- Reactions ----
		showContextMenu( event ) {
			event.preventDefault();
			event.stopPropagation();
			const ctx = getContext();
			const msgId = ctx.item?.id;
			if ( ! msgId ) return;

			// Toggle: if already open on this message, close it.
			if ( state.contextMenuMessageId === msgId ) {
				state.messages.forEach( m => { m.showMenu = false; m.noMenu = true; } );
				state.contextMenuMessageId = null;
				return;
			}

			// Clear previous menu.
			state.messages.forEach( m => { m.showMenu = false; m.noMenu = true; } );

			// Show menu on this message.
			const msg = state.messages.find( m => String( m.id ) === String( msgId ) );
			if ( msg ) {
				msg.showMenu = true;
				msg.noMenu = false;
				state.contextMenuMessageId = msgId;
			}
		},

		hideContextMenu() {
			state.messages.forEach( m => { m.showMenu = false; m.noMenu = true; } );
			state.contextMenuMessageId = null;
		},

		*addReaction() {
			const ctx = getContext();
			const msgId = ctx.messageId || state.contextMenuMessageId;
			const emoji = ctx.emoji;
			if ( ! msgId || ! emoji ) return;

			try {
				yield apiFetch( '/messages/' + msgId + '/reactions', {
					method: 'POST',
					body: JSON.stringify( { emoji } ),
				} );

				// Optimistic update.
				state.messages = state.messages.map( m => {
					if ( String( m.id ) !== String( msgId ) ) return m;
					const reactions = [ ...( m.reactions || [] ) ];
					const existing = reactions.findIndex( r => r.emoji === emoji );
					if ( existing >= 0 ) {
						if ( ! reactions[ existing ].user_ids.includes( ME.id ) ) {
							reactions[ existing ] = {
								...reactions[ existing ],
								count: reactions[ existing ].count + 1,
								user_ids: [ ...reactions[ existing ].user_ids, ME.id ],
							};
						}
					} else {
						reactions.push( { emoji, count: 1, user_ids: [ ME.id ] } );
					}
					return enrichMessage( { ...m, reactions } );
				} );
			} catch ( e ) {
				// Silently fail.
			}
			state.contextMenuMessageId = null;
			state.messages.forEach( m => { m.showMenu = false; m.noMenu = true; } );
		},

		*removeReaction() {
			const ctx = getContext();
			const msgId = ctx.messageId;
			if ( ! msgId ) return;

			try {
				yield apiFetch( '/messages/' + msgId + '/reactions', { method: 'DELETE' } );

				state.messages = state.messages.map( m => {
					if ( String( m.id ) !== String( msgId ) ) return m;
					const reactions = ( m.reactions || [] )
						.map( r => ( {
							...r,
							count: r.user_ids.includes( ME.id ) ? r.count - 1 : r.count,
							user_ids: r.user_ids.filter( id => id !== ME.id ),
						} ) )
						.filter( r => r.count > 0 );
					return { ...m, reactions };
				} );
			} catch ( e ) {
				// Silently fail.
			}
		},

		// ---- Reply ----
		setReplyTo() {
			const ctx = getContext();
			// Reply is triggered from the message context menu, which records the
			// target in state.contextMenuMessageId — ctx.messageId is not set on
			// that path. Use the same fallback as deleteMessage/unsendMessage so
			// the lookup resolves and replyingTo is actually set.
			const msgId = ctx.messageId || state.contextMenuMessageId;
			const msg = state.messages.find( m => String( m.id ) === String( msgId ) );
			if ( msg ) {
				state.replyingTo = msg;
				state.contextMenuMessageId = null;
				// Focus composer.
				setTimeout( () => {
					const input = document.querySelector( '.mvs-chat-composer__input' );
					if ( input ) input.focus();
				}, 50 );
			}
		},

		clearReply() {
			state.replyingTo = null;
		},

		// ---- Typing ----
		onComposerInput() {
			const el = getElement();
			state.composerText = el.ref.value;

			// Auto-resize textarea.
			el.ref.style.height = 'auto';
			el.ref.style.height = Math.min( el.ref.scrollHeight, 120 ) + 'px';

			// Send typing indicator (debounced).
			if ( state.activeConversationId ) {
				if ( ! state._typingTimeout ) {
					apiFetch( '/conversations/' + state.activeConversationId + '/typing', { method: 'POST' } ).catch( () => {} );
				}
				clearTimeout( state._typingTimeout );
				state._typingTimeout = setTimeout( () => { state._typingTimeout = null; }, 3000 );
			}
		},

		handleComposerKeydown( event ) {
			if ( event.key === 'Enter' && ! event.shiftKey ) {
				event.preventDefault();
				actions.sendMessage();
			}
		},

		// ---- Search ----
		*searchUsers() {
			const q = state.searchQuery.trim();
			if ( q.length < 2 ) {
				state.searchResults = [];
				return;
			}

			try {
				const data = yield apiFetch(
					REST + '/users/search?q=' + encodeURIComponent( q )
				);
				// Free's UserController returns `name` and `avatar`; normalize for template consistency.
				state.searchResults = ( data || [] ).filter( u => u.id !== ME.id ).map( u => {
					u.display_name = u.display_name || u.name || '';
					u.avatar_url = u.avatar_url || u.avatar || '';
					return u;
				} );
			} catch ( e ) {
				state.searchResults = [];
			}
		},

		updateSearchQuery() {
			const el = getElement();
			state.searchQuery = el.ref.value;
			// Debounced search.
			clearTimeout( state._searchTimeout );
			state._searchTimeout = setTimeout( () => actions.searchUsers(), 300 );
		},

		// ---- Polling ----
		startPolling() {
			actions.stopPolling();
			state.pollingSince = new Date().toISOString();

			const interval = state.chatView === 'conversation'
				? TRANSPORT.intervals.active
				: ( state.chatPanelOpen ? TRANSPORT.intervals.list : TRANSPORT.intervals.background );

			state.pollingTimer = setInterval( () => actions.pollForUpdates(), interval );

			// Unread count polling (always).
			state.unreadTimer = setInterval( () => actions.refreshUnreadCount(), TRANSPORT.intervals.background );

			// Visibility change.
			document.addEventListener( 'visibilitychange', actions.handleVisibilityChange );
		},

		stopPolling() {
			if ( state.pollingTimer ) {
				clearInterval( state.pollingTimer );
				state.pollingTimer = null;
			}
			if ( state.unreadTimer ) {
				clearInterval( state.unreadTimer );
				state.unreadTimer = null;
			}
			document.removeEventListener( 'visibilitychange', actions.handleVisibilityChange );
		},

		*pollForUpdates() {
			if ( document.hidden ) return;

			try {
				const params = new URLSearchParams( { since: state.pollingSince } );
				if ( state.activeConversationId ) {
					params.set( 'conversation_id', state.activeConversationId );
				}

				const data = yield apiFetch( '/messages/poll?' + params.toString() );

				// Update since.
				if ( data.server_time ) {
					state.pollingSince = data.server_time;
				}

				// New messages.
				if ( data.messages && data.messages.length > 0 ) {
					let appended = false;
					let changed  = false;
					for ( const rawMsg of data.messages ) {
						const msg      = enrichMessage( rawMsg );
						const inActive = String( msg.conversation_id ) === String( state.activeConversationId );

						if ( msg.isDeleted ) {
							// Unsent message. The poll re-serves it for the whole
							// unsend window (no updated_at column to scope it), so
							// dedupe via seenUnsendIds: react to each unsend ONCE
							// (Basecamp #9962618059 — "Unsend for everyone" must
							// reach other participants without a refresh).
							if ( ! seenUnsendIds.has( String( msg.id ) ) ) {
								seenUnsendIds.add( String( msg.id ) );
								changed = true;
							}
							if ( inActive ) {
								const existing = state.messages.find( m => String( m.id ) === String( msg.id ) );
								// Update the rendered bubble in place — same shape
								// as the local unsendMessage() transform. Never ADD
								// a deleted message that isn't in the DOM.
								if ( existing && existing.notDeleted ) {
									state.messages = state.messages.map( m =>
										String( m.id ) === String( msg.id )
											? enrichMessage( { ...rawMsg, content: '', message_type: 'text', metadata: null } )
											: m
									);
								}
							}
						} else if ( inActive ) {
							const exists = state.messages.some( m => String( m.id ) === String( msg.id ) );
							if ( ! exists ) {
								state.messages = [ ...state.messages, msg ];
								appended = true;
								changed  = true;
							}
						} else {
							// New message in another conversation — refresh the
							// sidebar so its preview/unread state updates.
							changed = true;
						}
					}
					if ( appended ) {
						actions.scrollToBottom();
					}

					// Refresh conversation list — but not for the re-served
					// unsent rows the poll repeats for the unsend window.
					if ( changed ) {
						yield actions.loadConversations();
					}
				}

				// Typing indicators.
				state.typingUsers = data.typing || [];

			} catch ( e ) {
				// Silently fail.
			}
		},

		handleVisibilityChange() {
			if ( document.hidden ) {
				actions.stopPolling();
			} else {
				actions.startPolling();
				actions.refreshUnreadCount();
			}
		},

		*refreshUnreadCount() {
			try {
				const data = yield apiFetch( '/me/messages/unread-count' );
				state.totalUnread = data.unread || 0;
			} catch ( e ) {
				// Silently fail.
			}
		},

		// ---- Helpers ----
		scrollToBottom() {
			setTimeout( () => {
				const container = document.querySelector( '.mvs-chat-messages' );
				if ( container ) container.scrollTop = container.scrollHeight;
			}, 50 );
		},

		showToast( message ) {
			// Use shared UI toast if available.
			try {
				const sharedStore = store( 'mvs/shared-ui' );
				if ( sharedStore && sharedStore.actions.showToast ) {
					sharedStore.actions.showToast( message );
					return;
				}
			} catch ( e ) {
				// Fallback: no shared UI store.
			}
			console.warn( '[MVS Messaging]', message );
		},

		// Format time for display (used in templates via derived state).
		formatMessageTime( dateStr ) {
			if ( ! dateStr ) return '';
			const d = new Date( dateStr );
			return d.toLocaleTimeString( [], { hour: '2-digit', minute: '2-digit' } );
		},

		formatConvTime( dateStr ) {
			return relativeTime( dateStr );
		},
	},

	callbacks: {
		// Auto-scroll on mount.
		onMessagesMount() {
			actions.scrollToBottom();
		},

		// Check deep link hash.
		onInit() {
			const hash = window.location.hash;
			if ( hash.startsWith( '#mvs-chat/user/' ) ) {
				// Deep link to DM a specific user: #mvs-chat/user/{userId}
				const userId = parseInt( hash.split( '/' )[2], 10 );
				if ( userId ) {
					actions.openWithRecipient( userId );
				}
			} else if ( hash.startsWith( '#mvs-chat/' ) ) {
				const convId = hash.split( '/' )[1];
				if ( convId ) {
					state.chatPanelOpen = true;
					state.activeConversationId = parseInt( convId, 10 );
					state.chatView = 'conversation';
					actions.loadMessages();
					actions.startPolling();
				}
			}

			// Listen for message-user events from non-Interactivity templates.
			// Guard: bind at most once per page — the slide-out lives in wp_footer
			// and persists across client-side navigations, so re-running onInit
			// must not stack duplicate listeners.
			if ( ! openConversationBound ) {
				openConversationBound = true;
				document.addEventListener( 'mvs-open-conversation', ( e ) => {
					if ( e.detail?.userId ) {
						actions.openWithRecipient( e.detail.userId );
					} else if ( e.detail?.conversationId ) {
						state.chatPanelOpen = true;
						state.activeConversationId = e.detail.conversationId;
						state.chatView = 'conversation';
						state.messages = [];
						state.hasMoreMessages = true;
						actions.loadMessages();
						actions.startPolling();
					}
				} );
			}

			// Full /messages/ page: auto-load conversations and start polling.
			if ( document.querySelector( '.mvs-messages-page' ) ) {
				state.chatPanelOpen = true;
				actions.loadConversations();
				actions.startPolling();
			}

			// Start background unread polling for all logged-in pages.
			// Guard: never stack a second timer if one is already running. Without
			// this guard, a second onInit call (e.g. partial re-hydration) would
			// launch a duplicate setInterval and double the polling rate.
			actions.refreshUnreadCount();
			if ( ! state.unreadTimer ) {
				state.unreadTimer = setInterval( () => actions.refreshUnreadCount(), TRANSPORT.intervals.background );
			}

		},
	},
} );

// Teardown: stop the active chat polling timer on every client-side navigation
// (mvs:navigated fires after each swap). Registered at module level — NOT inside
// onInit — so it persists for the page lifetime and fires on EVERY navigation, not
// just the first. A conversation opened on a later client-nav page (which starts a
// fresh pollingTimer) is therefore still cleaned up even if the slide-out stays
// open. The background unreadTimer is intentionally kept alive so the unread badge
// keeps updating while browsing.
document.addEventListener( 'mvs:navigated', () => {
	if ( state.pollingTimer ) {
		clearInterval( state.pollingTimer );
		state.pollingTimer = null;
	}
} );
