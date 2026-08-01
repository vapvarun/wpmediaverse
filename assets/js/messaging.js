/**
 * WPMediaVerse — Messaging Store (Interactivity API)
 *
 * @package WPMediaVerse
 * @since   1.1.0
 */

import { store, getContext, getElement } from '@wordpress/interactivity';

const config = window.mvsMessagingConfig || {};

// i18n — script modules can't import @wordpress/i18n and the global wp.i18n
// carries no 'wpmediaverse' catalog on the frontend, so reading it there just
// returned the English source. Instead PHP seeds mvsMessagingConfig.i18n with
// __()-translated strings keyed by their English source (gettext-style); the
// shim looks the source up, falling back to the literal. Matches how the
// Interactivity stores read wp_interactivity_state i18n. Basecamp 10073528834.
const I18N = config.i18n || {};
const __ = ( str ) => ( Object.prototype.hasOwnProperty.call( I18N, str ) ? I18N[ str ] : str );

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

	// Fail loudly, not silently. Every caller below wraps apiFetch in a
	// "silently fail, keep existing data" catch, so a missing REST client used
	// to surface as an empty conversation list — indistinguishable from "you
	// have no conversations". That is exactly how the chat panel reported "No
	// conversations" on any page that didn't enqueue mvs-rest
	// (Basecamp #10149580967). The enqueue is fixed in Core\Plugin, but keep
	// this guard so the next delivery regression is diagnosable instead of
	// looking like an empty state.
	if ( ! window.mvsRest || typeof window.mvsRest.restFetch !== 'function' ) {
		throw new Error(
			'mvsRest unavailable — the mvs-rest script was not enqueued on this page.'
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

// Calendar-day key in the viewer's timezone. Used only to detect day
// boundaries between adjacent messages.
function dayKey( dateStr ) {
	const d = new Date( dateStr );
	if ( isNaN( d.getTime() ) ) return '';
	return d.getFullYear() + '-' + d.getMonth() + '-' + d.getDate();
}

// "Today" / "Yesterday" / weekday within the last week / full date beyond that,
// matching how WhatsApp, Messenger and Telegram label day separators.
function dayLabel( dateStr ) {
	const d = new Date( dateStr );
	if ( isNaN( d.getTime() ) ) return '';
	const now = new Date();
	if ( dayKey( d ) === dayKey( now ) ) return __( 'Today', 'wpmediaverse' );
	const yest = new Date( now );
	yest.setDate( now.getDate() - 1 );
	if ( dayKey( d ) === dayKey( yest ) ) return __( 'Yesterday', 'wpmediaverse' );
	const ageDays = ( now - d ) / 86400000;
	if ( ageDays < 7 ) return d.toLocaleDateString( undefined, { weekday: 'long' } );
	return d.toLocaleDateString( undefined, { year: 'numeric', month: 'long', day: 'numeric' } );
}

// Time-only label for a message bubble.
function messageTimeLabel( dateStr ) {
	const d = new Date( dateStr );
	if ( isNaN( d.getTime() ) ) return '';
	return d.toLocaleTimeString( undefined, { hour: 'numeric', minute: '2-digit' } );
}

// Temp ID counter for optimistic messages.
let tempIdCounter = 0;

// Bumped on every attachment upload start and on cancel; lets an in-flight
// upload detect that it was cancelled and drop its stale response.
let attachmentRequestId = 0;

// Derive the sidebar preview string for a single message, mirroring the
// server's MessagingService::send_message() preview rules so the frontend
// recompute (after a delete) matches what the API would have stored.
function messagePreview( msg ) {
	if ( ! msg ) return '';
	const content = ( msg.content || '' ).trim();
	if ( content ) return content.slice( 0, 100 );
	switch ( msg.message_type ) {
		case 'voice':
			return __( 'Voice message' );
		case 'audio':
			return __( 'Audio' );
		case 'media_share':
			return __( 'Shared a media' );
		case 'image':
			return __( 'Photo' );
		case 'video':
			return __( 'Video' );
		case 'file':
			return __( 'File' );
		default:
			// Attachment under message_type 'text' (mobile clients / MIME
			// strings the server allow-list resets) — card 10127764989.
			return ( msg.attachment || msg.attachment_id || msg.media_id ) ? __( 'Attachment' ) : '';
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
	// Attribution + toggle affordance for each reaction pill. In a 1:1 DM a
	// reaction is mine, the other person's, or both — so `mine` is the meaningful
	// "who reacted" signal, and it also decides whether tapping the pill adds or
	// removes my own reaction (removeReaction existed but was bound to nothing).
	if ( msg.hasReactions ) {
		msg.reactions = msg.reactions.map( r => {
			const ids  = Array.isArray( r.user_ids ) ? r.user_ids : [];
			const mine = ids.some( id => String( id ) === String( ME.id ) );
			// Only the `__` shim exists in this module (no _n/sprintf). In a 1:1
			// DM `mine` is the complete who-reacted signal, and it also drives the
			// tap-to-remove toggle below.
			const label = mine
				? __( 'You reacted — tap to remove', 'wpmediaverse' )
				: __( 'Reacted', 'wpmediaverse' );
			// messageId is carried onto the reaction so the pill — which lives
			// inside data-wp-each="reactions" where context.item is the reaction,
			// not the message — can resolve its own message. Plain camelCase: the
			// Interactivity each does not track underscore-prefixed props.
			return { ...r, mine, notMine: ! mine, reactedByLabel: label, messageId: msg.id };
		} );
	}
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
		activeTab: 'all', // all, unread, requests, archived
		// searchQuery drives the NEW MESSAGE member search (chat-new.php).
		// listQuery drives the conversation-LIST filter (chat-list.php). They
		// were the same field, which is why typing in "Search conversations…"
		// ran a member search and never filtered the list (#10153528515).
		searchQuery: '',
		listQuery: '',
		searchResults: [],
		typingUsers: [],
		isRecordingVoice: false,
		voiceDuration: 0,
		selectedAttachment: null,
		attachmentPreview: null,
		uploadingAttachment: false,
		attachmentName: '',
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
			// Never send while an attachment upload is in flight — the message
			// would go out without its attachment_id.
			if ( state.uploadingAttachment ) return false;
			const hasText = state.composerText.trim().length > 0;
			const hasAttachment = !! state.selectedAttachment;
			return hasText || hasAttachment;
		},

		get hasAttachmentBar() {
			return !! ( state.attachmentPreview || state.uploadingAttachment || state.selectedAttachment );
		},

		get attachmentChipLabel() {
			if ( state.uploadingAttachment ) {
				return __( 'Uploading %s…' ).replace( '%s', state.attachmentName );
			}
			if ( state.selectedAttachment && ! state.attachmentPreview ) {
				return state.selectedAttachment.name || '';
			}
			return '';
		},

		get pinnedConversations() {
			return state.conversations.filter( c => c.is_pinned );
		},

		get requestConversations() {
			return state.conversations.filter( c => c.participant_status === 'request_pending' );
		},

		// What the list actually renders. Filters the loaded page by the other
		// participant's name so the search box does something; server-side tab
		// filtering still decides which conversations are loaded at all.
		get filteredConversations() {
			const q = state.listQuery.trim().toLowerCase();
			if ( ! q ) {
				return state.conversations;
			}
			return state.conversations.filter(
				c => ( c.otherName || '' ).toLowerCase().includes( q )
			);
		},

		// What the thread actually renders. Derives the time label and the
		// day-separator flag here rather than at each of the eleven places that
		// assign state.messages. The template bound created_at directly, so
		// bubbles showed a raw MySQL datetime (2026-07-08 12:27:26) and there
		// were no day separators at all (#10074503902). formatMessageTime()
		// already existed in this store but was never referenced by any template.
		get displayMessages() {
			let prevKey = '';
			return state.messages.map( m => {
				const key = dayKey( m.created_at );
				const isNewDay = key !== '' && key !== prevKey;
				if ( key ) prevKey = key;
				return Object.assign( {}, m, {
					timeLabel: messageTimeLabel( m.created_at ),
					dayLabel: isNewDay ? dayLabel( m.created_at ) : '',
					showDayHeader: isNewDay,
					hideDayHeader: ! isNewDay,
				} );
			} );
		},

		get showNoListResults() {
			return ! state.loadingConversations &&
				state.conversations.length > 0 &&
				state.listQuery.trim() !== '' &&
				this.filteredConversations.length === 0;
		},

		// The bell had NO state binding at all: no data-wp-bind, no
		// data-wp-class, a hardcoded aria-label of "Mute". It rendered
		// identically muted or unmuted, so members reported it "resetting to
		// off" — it was never on (#10153578635). Muting itself always worked:
		// NotificationListener and the unread counter both honour is_muted.
		get isMuted() {
			const conv = state.activeConversation;
			return !! ( conv && Number( conv.is_muted ) === 1 );
		},

		get isNotMuted() {
			return ! this.isMuted;
		},

		get muteLabel() {
			return this.isMuted
				? __( 'Unmute notifications', 'wpmediaverse' )
				: __( 'Mute notifications', 'wpmediaverse' );
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
		get isTabArchived() { return state.activeTab === 'archived'; },

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
			// Open the conversation LIST, not the compose view. Sharing is
			// almost always to someone you already talk to, and forcing compose
			// meant the control -- which is a paper-plane "Share" icon on the
			// Instagram layout -- looked like it had opened the wrong screen
			// (#10153188975). The list still has "New message" for the rest.
			state.chatView = 'list';
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
			// Drop a pending media share. It was only ever consumed by
			// createOrOpenConversation(), so backing out to the list left the id
			// armed and it later fired on an unrelated new conversation.
			state.pendingMediaShareId = null;
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
				// Scope to the New Message view. '.mvs-chat-search__input' alone
				// matches the conversation-LIST input first (it precedes
				// chat-new.php in DOM order), so this focused a hidden field and
				// the recipient box never got the caret.
				const input = document.querySelector( '.mvs-chat-new .mvs-chat-search__input' );
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
				// A filter typed for one tab should not silently hide results in
				// the next one.
				state.listQuery = '';
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

		/**
		 * Send a queued media share into a conversation, if one is pending.
		 *
		 * Single owner of the handoff. It used to live inline in
		 * createOrOpenConversation() only, so picking an EXISTING thread from
		 * the list dropped the media silently and left the id armed to fire
		 * later on an unrelated conversation (#10153188975).
		 *
		 * @param {number|string} conversationId Target conversation.
		 */
		*flushPendingMediaShare( conversationId ) {
			if ( ! state.pendingMediaShareId || ! conversationId ) {
				return;
			}
			const mediaId = state.pendingMediaShareId;
			state.pendingMediaShareId = null;
			try {
				const msg = yield apiFetch(
					'/conversations/' + conversationId + '/messages',
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
			yield actions.flushPendingMediaShare( convId );
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

					yield actions.flushPendingMediaShare( data.conversation.id );
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

		/**
		 * Re-resolve a conversation from the live list by id.
		 *
		 * loadConversations() REPLACES state.conversations wholesale on every
		 * poll tick, so an object captured before an await is detached by the
		 * time the request resolves and writing to it updates nothing the UI
		 * renders. Any optimistic write must re-resolve after the await.
		 *
		 * @param {number|string} convId Conversation id.
		 * @return {Object|null} The current object, or null if it is gone.
		 */
		resolveConversation( convId ) {
			return state.conversations.find( c => String( c.id ) === String( convId ) ) || null;
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
				// Re-resolve: a poll landing mid-PATCH detaches `conv`.
				const fresh = actions.resolveConversation( convId );
				if ( fresh ) {
					fresh.is_muted = newMuted ? 1 : 0;
				}
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
				const fresh = actions.resolveConversation( convId );
				if ( fresh ) {
					fresh.is_pinned = newPinned ? 1 : 0;
				}
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
			state.attachmentName = '';
			actions.scrollToBottom();

			try {
				const realMsg = yield apiFetch(
					'/conversations/' + state.activeConversationId + '/messages',
					{ method: 'POST', body: JSON.stringify( body ) }
				);

				// Replace temp with real message.
				state.messages = state.messages.map( m => m.id === tempId ? enrichMessage( { ...realMsg, _sending: false } ) : m );

				// Update conversation preview. Same placeholder rules as the
				// server's build_message_preview() — the raw lowercase type
				// ('image') used to flash here until the next poll refetched
				// the stored preview (card 10127764989).
				const conv = state.activeConversation;
				if ( conv ) {
					conv.last_message_preview = messagePreview( { content: content, message_type: body.message_type, attachment_id: body.attachment_id, media_id: body.media_id } );
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
			// Media only — MediaVerse DMs share image/video/audio, not documents.
			// The server enforces the same allowlist (mvs_dm_allowed_file_types).
			input.accept = 'image/*,video/*,audio/*';
			input.onchange = ( e ) => {
				const file = e.target.files[0];
				if ( ! file ) return;

				// Determine type. The accept attribute is advisory (the picker's
				// "All Files" option bypasses it), so reject non-media here too.
				let type = '';
				if ( file.type.startsWith( 'image/' ) ) type = 'image';
				else if ( file.type.startsWith( 'video/' ) ) type = 'video';
				else if ( file.type.startsWith( 'audio/' ) ) type = 'audio';

				if ( ! type ) {
					actions.showToast( __( 'Only image, video, and audio files can be shared in messages.' ) );
					return;
				}

				// Preview.
				if ( type === 'image' ) {
					const reader = new FileReader();
					reader.onload = ( re ) => { state.attachmentPreview = re.target.result; };
					reader.readAsDataURL( file );
				} else {
					state.attachmentPreview = null;
				}
				state.attachmentName = file.name;

				// Upload via FormData.
				actions.uploadAttachment( file, type );
			};
			input.click();
		},

		*uploadAttachment( file, type ) {
			const formData = new FormData();
			formData.append( 'file', file );

			// Generation token: if the user cancels while the upload is in
			// flight, the stale response must not re-attach the file.
			const requestId = ++attachmentRequestId;
			state.uploadingAttachment = true;

			try {
				const res = yield window.mvsRest.restFetch( REST + '/messages/upload', {
					method: 'POST',
					body: formData,
				} );
				const data = res.data;

				if ( ! res.ok ) throw new Error( ( data && data.message ) || __( 'Upload failed', 'wpmediaverse' ) );

				if ( requestId !== attachmentRequestId ) return; // Cancelled mid-flight.

				state.selectedAttachment = {
					id: data.id,
					url: data.source_url,
					type,
					name: file.name,
					size: file.size,
				};
			} catch ( e ) {
				if ( requestId !== attachmentRequestId ) return; // Cancelled mid-flight.
				state.attachmentPreview = null;
				state.attachmentName = '';
				actions.showToast( e.message );
			} finally {
				if ( requestId === attachmentRequestId ) {
					state.uploadingAttachment = false;
				}
			}
		},

		cancelAttachment() {
			attachmentRequestId++; // Invalidate any in-flight upload.
			state.uploadingAttachment = false;
			state.selectedAttachment = null;
			state.attachmentPreview = null;
			state.attachmentName = '';
		},

		// ---- Voice Recording ----
		*startVoiceRecording() {
			// navigator.mediaDevices is undefined on a non-secure origin, so
			// the getUserMedia() call below threw a TypeError BEFORE the browser
			// could show a permission prompt, and the catch swallowed it. From
			// the member's side the microphone button simply did nothing, with
			// no prompt and no error (#10153607841). Tell them why.
			if ( ! window.isSecureContext || ! navigator.mediaDevices || ! window.MediaRecorder ) {
				actions.showToast(
					__( 'Voice messages need a secure (https) connection.', 'wpmediaverse' )
				);
				return;
			}
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

		// Tapping an existing reaction pill toggles MY reaction: remove it if it
		// is already mine, otherwise add it. The pill's context.item IS the
		// reaction (it sits inside data-wp-each="reactions"), so the message id
		// comes from the reaction's carried messageId, not ctx.messageId (which
		// is only set while the context menu is open). Backend allows one reaction
		// per user per message, so adding also clears my reaction on any other
		// emoji to keep the optimistic view honest until the next poll.
		*toggleReaction() {
			const ctx      = getContext();
			const reaction = ctx.item || {};
			const msgId    = reaction.messageId;
			const emoji    = reaction.emoji;
			if ( ! msgId || ! emoji ) return;
			const removing = !! reaction.mine;

			try {
				yield apiFetch( '/messages/' + msgId + '/reactions', removing
					? { method: 'DELETE' }
					: { method: 'POST', body: JSON.stringify( { emoji } ) }
				);

				state.messages = state.messages.map( m => {
					if ( String( m.id ) !== String( msgId ) ) return m;
					let reactions = ( m.reactions || [] ).map( r => ( { ...r } ) );

					if ( removing ) {
						const idx = reactions.findIndex( r => r.emoji === emoji );
						if ( idx >= 0 ) {
							reactions[ idx ].user_ids = reactions[ idx ].user_ids.filter( id => String( id ) !== String( ME.id ) );
							reactions[ idx ].count    = reactions[ idx ].user_ids.length;
						}
					} else {
						// One reaction per user: drop me from every other emoji first.
						reactions.forEach( r => {
							if ( r.emoji !== emoji && r.user_ids.some( id => String( id ) === String( ME.id ) ) ) {
								r.user_ids = r.user_ids.filter( id => String( id ) !== String( ME.id ) );
								r.count    = r.user_ids.length;
							}
						} );
						const idx = reactions.findIndex( r => r.emoji === emoji );
						if ( idx >= 0 ) {
							if ( ! reactions[ idx ].user_ids.some( id => String( id ) === String( ME.id ) ) ) {
								reactions[ idx ].user_ids = [ ...reactions[ idx ].user_ids, ME.id ];
								reactions[ idx ].count    = reactions[ idx ].user_ids.length;
							}
						} else {
							reactions.push( { emoji, count: 1, user_ids: [ ME.id ] } );
						}
					}

					reactions = reactions.filter( r => r.count > 0 );
					return enrichMessage( { ...m, reactions } );
				} );
			} catch ( e ) {
				// Silently fail — the next poll reconciles server truth.
			}
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

		// New Message view: debounced MEMBER search.
		updateSearchQuery() {
			const el = getElement();
			state.searchQuery = el.ref.value;
			// Debounced search.
			clearTimeout( state._searchTimeout );
			state._searchTimeout = setTimeout( () => actions.searchUsers(), 300 );
		},

		// Conversation list: filter the loaded conversations. Deliberately not
		// debounced and not a network call — it is a local filter over a page
		// that is already in memory, so it should feel instant. The list input
		// used to call updateSearchQuery(), which searched MEMBERS and rendered
		// its results only in the hidden New Message view, so the list itself
		// never changed (#10153528515).
		updateListQuery() {
			const el = getElement();
			state.listQuery = el.ref.value;
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

				// Reactions on already-delivered messages. A reaction never moves
				// created_at, so it does not arrive in data.messages above — the
				// server sends it separately, or the other participant's reaction
				// only appeared after a full reload (card 10122929662). Re-enrich
				// just the affected message with the server's fresh reaction set.
				if ( Array.isArray( data.reaction_updates ) && data.reaction_updates.length > 0 ) {
					for ( const u of data.reaction_updates ) {
						if ( String( u.conversation_id ) !== String( state.activeConversationId ) ) {
							continue;
						}
						state.messages = state.messages.map( m =>
							String( m.id ) === String( u.id )
								? enrichMessage( { ...m, reactions: u.reactions } )
								: m
						);
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

// Escape closes the slide-out. A panel that traps a keyboard user with no way
// out but the mouse is not an acceptable dialog, and every other drawer on the
// web dismisses this way. Registered at module level alongside the teardown
// above so it survives client-side navigation.
//
// Deliberately a no-op when the panel is already closed, so Escape keeps
// working normally for the lightbox, modals and anything else on the page.
document.addEventListener( 'keydown', ( event ) => {
	if ( 'Escape' !== event.key || ! state.chatPanelOpen ) {
		return;
	}
	actions.closeChatPanel();
} );
