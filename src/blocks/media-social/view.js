/**
 * Interactivity API store: single media + album social interactions.
 *
 * Replaces assets/js/media-single.js (603 lines).
 * Handles reactions, favorites, comments, share, stats, owner edit/delete.
 *
 * IMPORTANT: getContext() only works inside direct directive handlers.
 * Internal helper functions receive `ctx` as a parameter instead.
 *
 * @package WPMediaVerse
 */

import { store, getContext, getElement } from '@wordpress/interactivity';

// i18n: this is a script MODULE, so window.wp.i18n.__() is English-locked here
// (no getLocaleData for the domain). Strings are PHP-translated and injected into
// interactivity state by TemplateHelpers::media_social_i18n_state() via
// wp_interactivity_state(); read them as `state.i18n.<key>` with an English
// fallback. Basecamp 10073528834.
const REACTION_TYPES = {
	like: '\u{1F44D}',
	love: '\u{2764}\u{FE0F}',
	haha: '\u{1F602}',
	wow: '\u{1F62E}',
	sad: '\u{1F622}',
	angry: '\u{1F621}',
};

const sharedUI = store( 'mvs/shared-ui' );

function endpoint( ctx ) {
	return ctx.type === 'album' ? 'albums/' : 'media/';
}

/* --- Internal helpers (receive ctx, no getContext() calls) --- */

async function fetchReactions( ctx ) {
	if ( ctx.type === 'album' ) return;
	try {
		const res = await window.mvsRest.restFetch( ctx.restUrl + 'media/' + ctx.mediaId + '/reactions' );
		const data = res.data;
		ctx.userReaction = data.user_reaction || '';
		ctx.reactions = Object.keys( REACTION_TYPES ).map( ( type ) => ( {
			type,
			emoji: REACTION_TYPES[ type ],
			count: data.counts?.[ type ] || 0,
			active: data.user_reaction === type,
		} ) );
	} catch {
		// Ignore.
	}
}

async function fetchComments( ctx ) {
	if ( ctx.type === 'album' ) return;
	try {
		const res = await window.mvsRest.restFetch(
			ctx.restUrl + 'media/' + ctx.mediaId + '/comments?per_page=20'
		);
		const data = res.data;
		const now = Date.now();
		// Seconds from the server (filterable mvs_comment_edit_window), not a
		// hardcoded 15 minutes — the two used to drift on any site that changed it.
		const editWindow = ( ctx.commentEditWindow || 15 * 60 ) * 1000;
		ctx.comments = Array.isArray( data )
			? data.map( ( c ) => {
				const commentAge = now - new Date( c.date ).getTime();
				const isOwnComment = ctx.currentUserId && c.author === ctx.currentUserId;
				return {
					id: c.id,
					author: c.author,
					author_name: c.author_name || 'Anonymous',
					author_avatar: c.author_avatar || '',
					author_url: c.author_url || '',
					// Keep ISO for the <time datetime> attribute and tooltip; display
					// the human-readable "2 days ago" coming from the REST payload.
					date: c.date,
					date_human: c.date_human || new Date( c.date ).toLocaleDateString(),
					content: c.content,
					canEdit: isOwnComment && commentAge < editWindow,
					editing: false,
					editText: '',
				};
			} )
			: [];
	} catch {
		ctx.comments = [];
	}
}

async function fetchStats( ctx ) {
	if ( ctx.type === 'album' ) return;
	try {
		const res = await window.mvsRest.restFetch( ctx.restUrl + 'media/' + ctx.mediaId + '/stats' );
		const data = res.data;
		if ( data?.views !== undefined ) {
			ctx.viewCount = data.views + ' views';
		}
	} catch {
		// Ignore.
	}
}

async function fetchFavorite( ctx ) {
	if ( ! ctx.isLoggedIn ) return;
	try {
		const res = await window.mvsRest.restFetch( ctx.restUrl + 'me/favorites?per_page=100' );
		const data = res.data;
		ctx.isFavorite = Array.isArray( data ) && data.some( ( f ) => f.media_id === ctx.mediaId );
	} catch {
		// Ignore.
	}
}

const { state } = store( 'mvs/media-social', {
	state: {
		reactions: [],
		userReaction: '',
		isFavorite: false,
		comments: [],
		commentText: '',
		viewCount: '',
		editVisible: false,
		editTitle: '',
		editDesc: '',
		editPrivacy: 'public',
		editRegenerateSlug: false,
		editTags: [],
		tagInput: '',
		tagResults: [],
		tagDropdownVisible: false,
		saving: false,
		shareLabel: 'Share',
		get hideCommentActions() {
			const item = getContext().item;
			return ! item?.canEdit || item?.editing;
		},
	},
	actions: {
		/* --- Reactions --- */
		async toggleReaction( event ) {
			const ctx = getContext();
			if ( ! ctx.isLoggedIn ) {
				sharedUI.actions.showToast( ( state.i18n?.loginToReact || 'Please log in to react.' ), 'error' );
				return;
			}
			const type = event.target.closest( '[data-reaction-type]' )?.dataset.reactionType;
			if ( ! type ) return;

			const previousReaction = ctx.userReaction;
			const previousReactions = ctx.reactions.map( ( r ) => ( { ...r } ) );
			const wasActive = previousReaction === type;

			// Optimistic UI: update count + active state immediately, then call REST.
			ctx.reactions = ctx.reactions.map( ( r ) => {
				const next = { ...r };
				if ( wasActive && r.type === type ) {
					next.count = Math.max( 0, ( r.count || 0 ) - 1 );
					next.active = false;
				} else if ( ! wasActive && r.type === type ) {
					next.count = ( r.count || 0 ) + 1;
					next.active = true;
				} else if ( ! wasActive && r.type === previousReaction ) {
					// User had a different reaction — decrement the old one.
					next.count = Math.max( 0, ( r.count || 0 ) - 1 );
					next.active = false;
				} else {
					next.active = false;
				}
				return next;
			} );
			ctx.userReaction = wasActive ? '' : type;

			try {
				const res = wasActive
					? await window.mvsRest.restFetch( ctx.restUrl + 'media/' + ctx.mediaId + '/reactions', {
						method: 'DELETE',
					} )
					: await window.mvsRest.restFetch( ctx.restUrl + 'media/' + ctx.mediaId + '/reactions', {
						method: 'POST',
						body: { reaction_type: type },
					} );
				if ( ! res.ok ) {
					// Roll back the optimistic update.
					ctx.userReaction = previousReaction;
					ctx.reactions = previousReactions;
					sharedUI.actions.showToast( ( state.i18n?.reactionSaveFailed || 'Could not save reaction.' ), 'error' );
					return;
				}
				// Reconcile with server-truth so the count matches reality after race conditions.
				await fetchReactions( ctx );
			} catch ( e ) {
				ctx.userReaction = previousReaction;
				ctx.reactions = previousReactions;
				sharedUI.actions.showToast( ( state.i18n?.networkError || 'Network error.' ), 'error' );
			}
		},

		/* --- Favorites --- */
		async toggleFavorite() {
			const ctx = getContext();
			if ( ! ctx.isLoggedIn ) {
				sharedUI.actions.showToast( ( state.i18n?.loginToFavorite || 'Please log in to favorite.' ), 'error' );
				return;
			}
			// Extension point: an add-on (Pro multi-collection picker) may handle
			// the click by opening a "Save to" picker instead. It listens for this
			// cancelable event and calls preventDefault; with no add-on the event
			// is ignored and the plain favorite toggle below runs unchanged.
			const mvsFavEl    = getElement()?.ref;
			const mvsFavEvent = new CustomEvent( 'mvs-favorite-click', {
				bubbles: true,
				cancelable: true,
				detail: { mediaId: ctx.mediaId },
			} );
			( mvsFavEl || document.body ).dispatchEvent( mvsFavEvent );
			if ( mvsFavEvent.defaultPrevented ) {
				return;
			}
			// Optimistic UI: flip the state immediately so the heart fills/empties
			// before the REST round-trip. Roll back on error.
			const previous = !! ctx.isFavorite;
			ctx.isFavorite = ! previous;
			const method = previous ? 'DELETE' : 'POST';
			try {
				const res = await window.mvsRest.restFetch( ctx.restUrl + 'media/' + ctx.mediaId + '/favorite', {
					method,
				} );
				if ( ! res.ok ) {
					ctx.isFavorite = previous;
					sharedUI.actions.showToast( ( state.i18n?.favoriteUpdateFailed || 'Could not update favorite.' ), 'error' );
				}
			} catch ( e ) {
				ctx.isFavorite = previous;
				sharedUI.actions.showToast( ( state.i18n?.networkError || 'Network error.' ), 'error' );
			}
		},

		/* --- Comments --- */
		updateCommentText( event ) {
			const ctx = getContext();
			ctx.commentText = event.target.value;
		},

		async submitComment( event ) {
			event.preventDefault();
			const ctx = getContext();
			if ( ! ctx.isLoggedIn ) {
				sharedUI.actions.showToast( ( state.i18n?.loginToComment || 'Please log in to comment.' ), 'error' );
				return;
			}
			const text = ctx.commentText.trim();
			if ( ! text || ctx.submittingComment ) return;

			ctx.submittingComment = true;
			try {
				const res = await window.mvsRest.restFetch( ctx.restUrl + 'media/' + ctx.mediaId + '/comments', {
					method: 'POST',
					body: { content: text },
				} );
				if ( ! res.ok ) {
					// Keep the typed text so the user can retry — do NOT clear.
					sharedUI.actions.showToast( ( res.data && ( res.data.message || res.data.error ) ) || ( state.i18n?.commentPostFailed || 'Could not post comment.' ), 'error' );
					return;
				}
				ctx.commentText = '';
				await fetchComments( ctx );
			} catch ( e ) {
				sharedUI.actions.showToast( ( state.i18n?.networkError || 'Network error.' ), 'error' );
			} finally {
				ctx.submittingComment = false;
			}
		},

		/* --- Comment Edit/Delete --- */
		startEditComment() {
			const ctx = getContext();
			const comment = ctx.item;
			if ( ! comment ) return;
			comment.editing = true;
			comment.editText = comment.content;
		},

		updateEditCommentText( event ) {
			const ctx = getContext();
			if ( ctx.item ) {
				ctx.item.editText = event.target.value;
			}
		},

		async saveEditComment() {
			const ctx = getContext();
			const comment = ctx.item;
			if ( ! comment || ! comment.editText.trim() ) return;

			try {
				const res = await window.mvsRest.restFetch( ctx.restUrl + 'media/' + ctx.mediaId + '/comments/' + comment.id, {
					method: 'PUT',
					body: { content: comment.editText.trim() },
				} );
				if ( res.ok ) {
					const updated = res.data;
					comment.content = updated.content;
					comment.editing = false;
					comment.editText = '';
					sharedUI.actions.showToast( ( state.i18n?.commentUpdated || 'Comment updated.' ), 'success' );
				} else {
					const err = res.data;
					sharedUI.actions.showToast( err.message || ( state.i18n?.editFailed || 'Edit failed.' ), 'error' );
				}
			} catch {
				sharedUI.actions.showToast( ( state.i18n?.editFailed || 'Edit failed.' ), 'error' );
			}
		},

		cancelEditComment() {
			const ctx = getContext();
			if ( ctx.item ) {
				ctx.item.editing = false;
				ctx.item.editText = '';
			}
		},

		deleteComment() {
			const ctx = getContext();
			const comment = ctx.item;
			if ( ! comment ) return;

			sharedUI.actions.showConfirm( ( state.i18n?.deleteCommentConfirm || 'Delete this comment?' ), async () => {
				const res = await window.mvsRest.restFetch( ctx.restUrl + 'media/' + ctx.mediaId + '/comments/' + comment.id, {
					method: 'DELETE',
				} );
				if ( res.ok ) {
					await fetchComments( ctx );
					sharedUI.actions.showToast( ( state.i18n?.commentDeleted || 'Comment deleted.' ), 'success' );
				}
			} );
		},

		/* --- Share --- */
		async handleShare() {
			const ctx = getContext();
			const url = window.location.href;
			const title = document.title;

			if ( navigator.share ) {
				try {
					await navigator.share( { title, url } );
					return;
				} catch {
					// User cancelled or share failed — fall through to clipboard.
				}
			}

			// Clipboard fallback.
			try {
				if ( navigator.clipboard ) {
					await navigator.clipboard.writeText( url );
				} else {
					// Legacy fallback for older browsers / non-HTTPS.
					const ta = document.createElement( 'textarea' );
					ta.value = url;
					ta.style.position = 'fixed';
					ta.style.opacity = '0';
					document.body.appendChild( ta );
					ta.select();
					document.execCommand( 'copy' );
					document.body.removeChild( ta );
				}
				ctx.shareLabel = ( state.i18n?.shareCopied || '✓ Copied!' );
				sharedUI.actions.showToast( ( state.i18n?.linkCopiedClipboard || 'Link copied to clipboard!' ), 'success' );
				setTimeout( () => {
					ctx.shareLabel = ( state.i18n?.shareResetLabel || 'Share' );
				}, 2000 );
			} catch {
				sharedUI.actions.showToast( ( state.i18n?.copyLinkFailed || 'Could not copy link. Please copy the URL manually.' ), 'error' );
			}
		},

		/* --- Follow --- */
		async toggleFollow() {
			const ctx = getContext();
			const authorId = ctx.followAuthorId || ctx.authorId;
			if ( ! authorId ) return;
			// Optimistic UI: flip Following/Follow label immediately, roll back on error.
			const previous = !! ctx.isFollowing;
			ctx.isFollowing = ! previous;
			const method = previous ? 'DELETE' : 'POST';
			try {
				const res = await window.mvsRest.restFetch( ctx.restUrl + 'users/' + authorId + '/follow', {
					method,
				} );
				if ( ! res.ok ) {
					ctx.isFollowing = previous;
					sharedUI.actions.showToast( ( state.i18n?.followFailed || 'Follow action failed.' ), 'error' );
				}
			} catch {
				ctx.isFollowing = previous;
				sharedUI.actions.showToast( ( state.i18n?.followFailed || 'Follow action failed.' ), 'error' );
			}
		},

		/* --- Owner Edit --- */
		toggleEdit() {
			const ctx = getContext();
			ctx.editVisible = ! ctx.editVisible;
		},

		cancelEdit() {
			getContext().editVisible = false;
		},

		updateEditTitle( event ) {
			getContext().editTitle = event.target.value;
		},

		updateEditDesc( event ) {
			getContext().editDesc = event.target.value;
		},

		updateEditPrivacy( event ) {
			getContext().editPrivacy = event.target.value;
		},

		updateEditRegenerateSlug( event ) {
			// Off by default — title edits leave the URL slug alone. Tick
			// to opt into regenerating the slug from the title (will break
			// inbound links to the old URL).
			getContext().editRegenerateSlug = !! event.target.checked;
		},

		/* --- Owner Tag Input --- */
		updateTagInput( event ) {
			const ctx = getContext();
			ctx.tagInput = event.target.value;
			sharedUI.actions.searchTags( ctx.tagInput, ctx.restUrl );
			setTimeout( () => {
				const uiState = store( 'mvs/shared-ui' ).state;
				ctx.tagResults = ( uiState.tagAutocomplete?.results || [] )
					.filter( ( t ) => ! ctx.editTags.includes( t ) );
				ctx.tagDropdownVisible = ctx.tagResults.length > 0;
			}, 350 );
		},

		addTagFromInput( event ) {
			if ( event.key !== 'Enter' ) return;
			event.preventDefault();
			const ctx = getContext();
			const val = ctx.tagInput.trim();
			if ( val && ! ctx.editTags.includes( val ) ) {
				ctx.editTags = [ ...ctx.editTags, val ];
			}
			ctx.tagInput = '';
			ctx.tagDropdownVisible = false;
		},

		selectTag( event ) {
			const ctx = getContext();
			const tag = event.target.dataset.tagName;
			if ( tag && ! ctx.editTags.includes( tag ) ) {
				ctx.editTags = [ ...ctx.editTags, tag ];
			}
			ctx.tagInput = '';
			ctx.tagDropdownVisible = false;
		},

		removeTag( event ) {
			const ctx = getContext();
			const tag = event.target.closest( '[data-tag-name]' )?.dataset.tagName;
			if ( tag ) {
				ctx.editTags = ctx.editTags.filter( ( t ) => t !== tag );
			}
		},

		async saveEdit() {
			const ctx = getContext();
			ctx.saving = true;
			const ep = endpoint( ctx );
			const payload = {
				title: ctx.editTitle,
				description: ctx.editDesc,
				privacy: ctx.editPrivacy,
			};
			if ( ctx.type !== 'album' ) {
				payload.tags = ctx.editTags;
			}
			// Slug stays put unless the user explicitly opted in via the
			// "Update URL slug" checkbox. Read the DOM directly so the
			// IA reactive context isn't on the critical path — keeps the
			// checkbox state authoritative regardless of any hydration
			// timing quirks. The server still runs sanitize_title +
			// collision check.
			const slugCheckbox = document.querySelector( '.mvs-inline-edit .mvs-edit-regenerate-slug' );
			if ( slugCheckbox && slugCheckbox.checked ) {
				payload.slug = ( ctx.editTitle || '' )
					.toLowerCase()
					.replace( /[^\w\s-]/g, '' )
					.trim()
					.replace( /\s+/g, '-' )
					.replace( /-+/g, '-' );
			}

			try {
				const res = await window.mvsRest.restFetch( ctx.restUrl + ep + ctx.mediaId, {
					method: 'PUT',
					body: payload,
				} );
				const updated = res.data;
				ctx.saving = false;
				ctx.editVisible = false;

				// If the slug changed (admin opted into the regenerate-slug
				// checkbox), the URL the user is currently on is now stale
				// and would 404 on reload. Redirect to the new permalink so
				// the address bar reflects reality. `replace` (vs `assign`)
				// so the back-button doesn't bounce them back to the dead URL.
				if (
					updated.link &&
					typeof window !== 'undefined' &&
					window.location.pathname !== new URL( updated.link ).pathname
				) {
					sharedUI.actions.showToast( ( state.i18n?.savedRedirecting || 'Saved! Redirecting to the new URL…' ), 'success' );
					window.location.replace( updated.link );
					return;
				}

				const titleEl = document.querySelector( ctx.type === 'album' ? '.mvs-album-title' : '.mvs-media-title' );
				if ( titleEl ) titleEl.textContent = updated.title || '';
				const descEl = document.querySelector( ctx.type === 'album' ? '.mvs-album-description' : '.mvs-media-description' );
				if ( descEl ) descEl.textContent = updated.description || '';

				if ( ctx.type !== 'album' && updated.tags ) {
					const tagsContainer = document.querySelector( '.mvs-media-tags' );
					if ( tagsContainer ) {
						while ( tagsContainer.firstChild ) tagsContainer.removeChild( tagsContainer.firstChild );
						updated.tags.forEach( ( t ) => {
							const a = document.createElement( 'a' );
							a.className = 'mvs-tag';
							a.textContent = t;
							a.href = '#';
							tagsContainer.appendChild( a );
						} );
					}
				}

				sharedUI.actions.showToast( ctx.type === 'album' ? ( state.i18n?.albumSaved || 'Album saved!' ) : ( state.i18n?.saved || 'Saved!' ), 'success' );
			} catch {
				ctx.saving = false;
				sharedUI.actions.showToast( ( state.i18n?.saveFailed || 'Save failed.' ), 'error' );
			}
		},

		/* --- Owner Delete --- */
		confirmDelete() {
			const ctx = getContext();
			const msg = ctx.type === 'album'
				? ( state.i18n?.deleteAlbumConfirm || 'Delete this album? Media items will not be deleted.' )
				: ( state.i18n?.deleteMediaConfirm || 'Delete this media item? This cannot be undone.' );
			sharedUI.actions.showConfirm( msg, async () => {
				const ep = endpoint( ctx );
				const res = await window.mvsRest.restFetch( ctx.restUrl + ep + ctx.mediaId, {
					method: 'DELETE',
				} );
				if ( res.ok ) {
					window.location.href = ctx.archiveUrl || '/';
				}
			}, ( state.i18n?.deleteAction || 'Delete' ) );
		},

		/* --- Report Media --- */
		reportMedia() {
			const ctx = getContext();
			if ( ! ctx.isLoggedIn ) {
				sharedUI.actions.showToast( ( state.i18n?.loginToReport || 'Please log in to report content.' ), 'error' );
				return;
			}

			// Build a reason selector dropdown.
			const reasons = [
				{ value: 'spam', label: ( state.i18n?.reasonSpam || 'Spam' ) },
				{ value: 'harassment', label: ( state.i18n?.reasonHarassment || 'Harassment' ) },
				{ value: 'nudity', label: ( state.i18n?.reasonNudity || 'Nudity or sexual content' ) },
				{ value: 'violence', label: ( state.i18n?.reasonViolence || 'Violence or dangerous acts' ) },
				{ value: 'copyright', label: ( state.i18n?.reasonCopyright || 'Copyright infringement' ) },
				{ value: 'misinformation', label: ( state.i18n?.reasonMisinformation || 'Misinformation' ) },
				{ value: 'other', label: ( state.i18n?.reasonOther || 'Other' ) },
			];

			// Create a temporary select element in the DOM.
			const select = document.createElement( 'select' );
			select.className = 'mvs-report-reason-select';
			reasons.forEach( ( r ) => {
				const opt = document.createElement( 'option' );
				opt.value = r.value;
				opt.textContent = r.label;
				select.appendChild( opt );
			} );

			// Inject select into confirm message area after dialog opens.
			sharedUI.actions.showConfirm(
				( state.i18n?.reportPrompt || 'Why are you reporting this media?' ),
				async () => {
					const reason = select.value || 'other';
					const res = await window.mvsRest.restFetch( ctx.restUrl + 'media/' + ctx.mediaId + '/report', {
						method: 'POST',
						body: { reason, details: 'Reported via media page' },
					} );
					if ( res.ok ) {
						sharedUI.actions.showToast( ( state.i18n?.reportSubmitted || 'Report submitted. Thank you.' ), 'success' );
						ctx.reported = true;
					} else {
						const data = res.data || {};
						sharedUI.actions.showToast( data.message || ( state.i18n?.reportAlready || 'Already reported or error occurred.' ), 'error' );
					}
				},
				( state.i18n?.reportAction || 'Report' )
			);

			// Append select to the confirm dialog message area.
			requestAnimationFrame( () => {
				const container = document.querySelector( '.mvs-confirm' );
				if ( container ) {
					container.querySelectorAll( '.mvs-report-reason-select' ).forEach( ( el ) => el.remove() );
					const msgEl = container.querySelector( 'p' );
					if ( msgEl ) {
						msgEl.after( select );
					}
				}
			} );
		},

		stopPropagation( event ) {
			event.stopPropagation();
		},
	},
	callbacks: {
		async init() {
			const ctx = getContext();

			// Initialize edit fields from server-rendered values.
			ctx.editTitle = ctx.initialTitle || '';
			ctx.editDesc = ctx.initialDesc || '';
			ctx.editPrivacy = ctx.initialPrivacy || 'public';
			ctx.editTags = ctx.initialTags || [];

			// Load all data in parallel using helpers (ctx passed explicitly).
			const promises = [];
			if ( ctx.type !== 'album' ) {
				promises.push( fetchReactions( ctx ) );
				promises.push( fetchComments( ctx ) );
				promises.push( fetchStats( ctx ) );
				// Fire-and-forget view recording.
				window.mvsRest.restFetch( ctx.restUrl + 'media/' + ctx.mediaId + '/view', {
					method: 'POST',
				} );
			}
			if ( ctx.isLoggedIn ) {
				promises.push( fetchFavorite( ctx ) );
				// Check follow status for media author.
				if ( ctx.authorId && ! ctx.isOwner ) {
					promises.push( ( async () => {
						try {
							const res = await window.mvsRest.restFetch( ctx.restUrl + 'me/following' );
							const data = res.data;
							ctx.isFollowing = Array.isArray( data ) && data.some( ( f ) => f.user_id === ctx.authorId || f.id === ctx.authorId );
						} catch {
							// Ignore.
						}
					} )() );
				}
			}
			await Promise.all( promises );
		},

		async initFollow() {
			const ctx = getContext();
			if ( ! ctx.followAuthorId ) return;
			try {
				const res = await window.mvsRest.restFetch( ctx.restUrl + 'me/following' );
				const data = res.data;
				ctx.isFollowing = Array.isArray( data ) && data.some( ( f ) => f.user_id === ctx.followAuthorId || f.id === ctx.followAuthorId );
			} catch {
				// Ignore.
			}
		},
	},
} );
