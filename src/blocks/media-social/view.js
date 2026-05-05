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

import { store, getContext } from '@wordpress/interactivity';

const REACTION_TYPES = {
	like: '\u{1F44D}',
	love: '\u{2764}\u{FE0F}',
	haha: '\u{1F602}',
	wow: '\u{1F62E}',
	sad: '\u{1F622}',
	angry: '\u{1F621}',
};

const sharedUI = store( 'mvs/shared-ui' );

function apiHeaders( nonce ) {
	return {
		'Content-Type': 'application/json',
		'X-WP-Nonce': nonce,
	};
}

function endpoint( ctx ) {
	return ctx.type === 'album' ? 'albums/' : 'media/';
}

/* --- Internal helpers (receive ctx, no getContext() calls) --- */

async function fetchReactions( ctx ) {
	if ( ctx.type === 'album' ) return;
	try {
		const res = await fetch( ctx.restUrl + 'media/' + ctx.mediaId + '/reactions', {
			headers: apiHeaders( ctx.nonce ),
			credentials: 'same-origin',
		} );
		const data = await res.json();
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
		const res = await fetch(
			ctx.restUrl + 'media/' + ctx.mediaId + '/comments?per_page=20',
			{ headers: apiHeaders( ctx.nonce ), credentials: 'same-origin' }
		);
		const data = await res.json();
		const now = Date.now();
		const editWindow = 15 * 60 * 1000; // 15 minutes in ms.
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
		const res = await fetch( ctx.restUrl + 'media/' + ctx.mediaId + '/stats', {
			headers: apiHeaders( ctx.nonce ),
			credentials: 'same-origin',
		} );
		const data = await res.json();
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
		const res = await fetch( ctx.restUrl + 'me/favorites?per_page=100', {
			headers: apiHeaders( ctx.nonce ),
			credentials: 'same-origin',
		} );
		const data = await res.json();
		ctx.isFavorite = Array.isArray( data ) && data.some( ( f ) => f.media_id === ctx.mediaId );
	} catch {
		// Ignore.
	}
}

store( 'mvs/media-social', {
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
				sharedUI.actions.showToast( 'Please log in to react.', 'error' );
				return;
			}
			const type = event.target.closest( '[data-reaction-type]' )?.dataset.reactionType;
			if ( ! type ) return;

			const headers = apiHeaders( ctx.nonce );
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
					? await fetch( ctx.restUrl + 'media/' + ctx.mediaId + '/reactions', {
						method: 'DELETE',
						headers,
						credentials: 'same-origin',
					} )
					: await fetch( ctx.restUrl + 'media/' + ctx.mediaId + '/reactions', {
						method: 'POST',
						headers,
						credentials: 'same-origin',
						body: JSON.stringify( { reaction_type: type } ),
					} );
				if ( ! res.ok ) {
					// Roll back the optimistic update.
					ctx.userReaction = previousReaction;
					ctx.reactions = previousReactions;
					sharedUI.actions.showToast( 'Could not save reaction.', 'error' );
					return;
				}
				// Reconcile with server-truth so the count matches reality after race conditions.
				await fetchReactions( ctx );
			} catch ( e ) {
				ctx.userReaction = previousReaction;
				ctx.reactions = previousReactions;
				sharedUI.actions.showToast( 'Network error.', 'error' );
			}
		},

		/* --- Favorites --- */
		async toggleFavorite() {
			const ctx = getContext();
			if ( ! ctx.isLoggedIn ) {
				sharedUI.actions.showToast( 'Please log in to favorite.', 'error' );
				return;
			}
			// Optimistic UI: flip the state immediately so the heart fills/empties
			// before the REST round-trip. Roll back on error.
			const previous = !! ctx.isFavorite;
			ctx.isFavorite = ! previous;
			const method = previous ? 'DELETE' : 'POST';
			try {
				const res = await fetch( ctx.restUrl + 'media/' + ctx.mediaId + '/favorite', {
					method,
					headers: apiHeaders( ctx.nonce ),
					credentials: 'same-origin',
				} );
				if ( ! res.ok ) {
					ctx.isFavorite = previous;
					sharedUI.actions.showToast( 'Could not update favorite.', 'error' );
				}
			} catch ( e ) {
				ctx.isFavorite = previous;
				sharedUI.actions.showToast( 'Network error.', 'error' );
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
			if ( ! ctx.commentText.trim() ) return;

			await fetch( ctx.restUrl + 'media/' + ctx.mediaId + '/comments', {
				method: 'POST',
				headers: apiHeaders( ctx.nonce ),
				credentials: 'same-origin',
				body: JSON.stringify( { content: ctx.commentText.trim() } ),
			} );
			ctx.commentText = '';
			await fetchComments( ctx );
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
				const res = await fetch( ctx.restUrl + 'media/' + ctx.mediaId + '/comments/' + comment.id, {
					method: 'PUT',
					headers: apiHeaders( ctx.nonce ),
					credentials: 'same-origin',
					body: JSON.stringify( { content: comment.editText.trim() } ),
				} );
				if ( res.ok ) {
					const updated = await res.json();
					comment.content = updated.content;
					comment.editing = false;
					comment.editText = '';
					sharedUI.actions.showToast( 'Comment updated.', 'success' );
				} else {
					const err = await res.json();
					sharedUI.actions.showToast( err.message || 'Edit failed.', 'error' );
				}
			} catch {
				sharedUI.actions.showToast( 'Edit failed.', 'error' );
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

			sharedUI.actions.showConfirm( 'Delete this comment?', async () => {
				const res = await fetch( ctx.restUrl + 'media/' + ctx.mediaId + '/comments/' + comment.id, {
					method: 'DELETE',
					headers: apiHeaders( ctx.nonce ),
					credentials: 'same-origin',
				} );
				if ( res.ok ) {
					await fetchComments( ctx );
					sharedUI.actions.showToast( 'Comment deleted.', 'success' );
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
				ctx.shareLabel = '\u2713 Copied!';
				sharedUI.actions.showToast( 'Link copied to clipboard!', 'success' );
				setTimeout( () => {
					ctx.shareLabel = '\u{1F517} Share';
				}, 2000 );
			} catch {
				sharedUI.actions.showToast( 'Could not copy link. Please copy the URL manually.', 'error' );
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
				const res = await fetch( ctx.restUrl + 'users/' + authorId + '/follow', {
					method,
					headers: apiHeaders( ctx.nonce ),
					credentials: 'same-origin',
				} );
				if ( ! res.ok ) {
					ctx.isFollowing = previous;
					sharedUI.actions.showToast( 'Follow action failed.', 'error' );
				}
			} catch {
				ctx.isFollowing = previous;
				sharedUI.actions.showToast( 'Follow action failed.', 'error' );
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
				const res = await fetch( ctx.restUrl + ep + ctx.mediaId, {
					method: 'PUT',
					headers: apiHeaders( ctx.nonce ),
					credentials: 'same-origin',
					body: JSON.stringify( payload ),
				} );
				const updated = await res.json();
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
					sharedUI.actions.showToast( 'Saved! Redirecting to the new URL…', 'success' );
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

				sharedUI.actions.showToast( ctx.type === 'album' ? 'Album saved!' : 'Saved!', 'success' );
			} catch {
				ctx.saving = false;
				sharedUI.actions.showToast( 'Save failed.', 'error' );
			}
		},

		/* --- Owner Delete --- */
		confirmDelete() {
			const ctx = getContext();
			const msg = ctx.type === 'album'
				? 'Delete this album? Media items will not be deleted.'
				: 'Delete this media item? This cannot be undone.';
			sharedUI.actions.showConfirm( msg, async () => {
				const ep = endpoint( ctx );
				const res = await fetch( ctx.restUrl + ep + ctx.mediaId, {
					method: 'DELETE',
					headers: apiHeaders( ctx.nonce ),
					credentials: 'same-origin',
				} );
				if ( res.ok ) {
					window.location.href = ctx.archiveUrl || '/';
				}
			}, 'Delete' );
		},

		/* --- Report Media --- */
		reportMedia() {
			const ctx = getContext();
			if ( ! ctx.isLoggedIn ) {
				sharedUI.actions.showToast( 'Please log in to report content.', 'error' );
				return;
			}

			// Build a reason selector dropdown.
			const reasons = [
				{ value: 'spam', label: 'Spam' },
				{ value: 'harassment', label: 'Harassment' },
				{ value: 'nudity', label: 'Nudity or sexual content' },
				{ value: 'violence', label: 'Violence or dangerous acts' },
				{ value: 'copyright', label: 'Copyright infringement' },
				{ value: 'misinformation', label: 'Misinformation' },
				{ value: 'other', label: 'Other' },
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
				'Why are you reporting this media?',
				async () => {
					const reason = select.value || 'other';
					const res = await fetch( ctx.restUrl + 'media/' + ctx.mediaId + '/report', {
						method: 'POST',
						headers: apiHeaders( ctx.nonce ),
						credentials: 'same-origin',
						body: JSON.stringify( { reason, details: 'Reported via media page' } ),
					} );
					if ( res.ok ) {
						sharedUI.actions.showToast( 'Report submitted. Thank you.', 'success' );
						ctx.reported = true;
					} else {
						const data = await res.json().catch( () => ( {} ) );
						sharedUI.actions.showToast( data.message || 'Already reported or error occurred.', 'error' );
					}
				},
				'Report'
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
				fetch( ctx.restUrl + 'media/' + ctx.mediaId + '/view', {
					method: 'POST',
					headers: apiHeaders( ctx.nonce ),
					credentials: 'same-origin',
				} );
			}
			if ( ctx.isLoggedIn ) {
				promises.push( fetchFavorite( ctx ) );
				// Check follow status for media author.
				if ( ctx.authorId && ! ctx.isOwner ) {
					promises.push( ( async () => {
						try {
							const res = await fetch( ctx.restUrl + 'me/following', {
								headers: apiHeaders( ctx.nonce ),
								credentials: 'same-origin',
							} );
							const data = await res.json();
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
				const res = await fetch( ctx.restUrl + 'me/following', {
					headers: apiHeaders( ctx.nonce ),
					credentials: 'same-origin',
				} );
				const data = await res.json();
				ctx.isFollowing = Array.isArray( data ) && data.some( ( f ) => f.user_id === ctx.followAuthorId || f.id === ctx.followAuthorId );
			} catch {
				// Ignore.
			}
		},
	},
} );
