/**
 * Interactivity API store: shared UI components.
 *
 * Provides global components available on every frontend page:
 * - Toast notifications
 * - Confirm dialogs
 * - Tag autocomplete
 * - Upload modal (photo, gallery, album, video)
 * - Media lightbox (view, react, comment without page navigation)
 *
 * Other stores import via: store( 'mvs/shared-ui' ).actions.showToast( msg, type )
 *
 * @package WPMediaVerse
 */

import { store, getContext } from '@wordpress/interactivity';

let toastTimer = null;
let tagSearchTimer = null;

const { state, actions } = store( 'mvs/shared-ui', {
	state: {
		// --- Toast (flat) ---
		toastMessage: '',
		toastType: 'success',
		toastVisible: false,
		get isToastSuccess() { return state.toastType === 'success'; },
		get isToastError() { return state.toastType === 'error'; },

		// --- Confirm (flat) ---
		confirmMessage: '',
		confirmVisible: false,
		confirmCallback: null,
		confirmButtonLabel: 'Confirm',

		// --- Tag Autocomplete (flat) ---
		tagQuery: '',
		tagResults: [],
		tagVisible: false,

		// --- Upload Modal (flat) ---
		uploadModalVisible: false,
		uploadModalMode: 'photo', // photo | gallery | album | video
		uploadModalFiles: [],
		uploadModalPreviews: [],
		uploadModalUploading: false,
		uploadModalProgress: 0,
		uploadModalTotal: 0,
		uploadModalDone: 0,
		uploadModalFailed: 0,
		uploadModalTitle: '',
		uploadModalDescription: '',
		uploadModalTags: '',
		uploadModalPrivacy: 'public',
		uploadModalAlbumTitle: '',
		uploadModalAlbumDescription: '',
		uploadModalMediaGroup: null,

		get uploadModalHeading() {
			const titles = { photo: 'Upload Photo', gallery: 'Create Gallery Post', album: 'Create Album', video: 'Upload Video' };
			return titles[ state.uploadModalMode ] || 'Upload';
		},
		get uploadAccept() {
			return state.uploadModalMode === 'video' ? 'video/*' : 'image/*';
		},
		get uploadMultiple() {
			return state.uploadModalMode === 'gallery';
		},
		get isPhotoMode() {
			return state.uploadModalMode === 'photo';
		},
		get isGalleryMode() {
			return state.uploadModalMode === 'gallery';
		},
		get isAlbumMode() {
			return state.uploadModalMode === 'album';
		},
		get isVideoMode() {
			return state.uploadModalMode === 'video';
		},
		get hasFiles() {
			return state.uploadModalFiles.length > 0;
		},
		get uploadProgressText() {
			if ( ! state.uploadModalUploading ) return '';
			return 'Uploading ' + ( state.uploadModalDone + 1 ) + ' of ' + state.uploadModalTotal + '...';
		},
		get uploadProgressWidth() {
			if ( ! state.uploadModalTotal ) return '0%';
			return Math.round( ( state.uploadModalDone / state.uploadModalTotal ) * 100 ) + '%';
		},

		// --- Lightbox (flat) ---
		lightboxVisible: false,
		lightboxMediaId: null,
		lightboxMediaData: null,
		lightboxGroupItems: [],
		lightboxCurrentIndex: 0,
		lightboxLoading: false,
		lightboxCommentText: '',
		lightboxComments: [],
		lightboxTotalComments: 0,
		lightboxReactions: {},
		lightboxUserReaction: '',
		lightboxIsFavorited: false,
		lightboxStats: {},

		get lightboxImageUrl() {
			const d = state.lightboxMediaData;
			return d?.thumbnail_url || d?.file_url || '';
		},
		get lightboxTitle() {
			return state.lightboxMediaData?.title || '';
		},
		get lightboxDescription() {
			return state.lightboxMediaData?.description || '';
		},
		get lightboxAuthor() {
			return state.lightboxMediaData?.author_data?.name || '';
		},
		get lightboxAuthorAvatar() {
			return state.lightboxMediaData?.author_data?.avatar || '';
		},
		get lightboxAuthorUrl() {
			const d = state.lightboxMediaData;
			if ( ! d?.author ) return '#';
			return d.author_data?.profile_url || '#';
		},
		get lightboxPermalink() {
			return state.lightboxMediaData?.link || '#';
		},
		get lightboxViewsText() {
			const s = state.lightboxStats;
			const views = s?.views || 0;
			const downloads = s?.downloads || 0;
			let text = views + ' view' + ( views !== 1 ? 's' : '' );
			if ( downloads > 0 ) text += ' · ' + downloads + ' download' + ( downloads !== 1 ? 's' : '' );
			return text;
		},
		get lightboxFavoriteLabel() {
			return state.lightboxIsFavorited ? '\u2764\uFE0F Favorited' : '\u2661 Favorite';
		},
		get lightboxHasComments() {
			return state.lightboxComments.length > 0;
		},
		get lightboxHasMoreComments() {
			return state.lightboxTotalComments > state.lightboxComments.length;
		},
		// Reaction count getters.
		get lightboxReactionCount_like() { return state.lightboxReactions?.like || ''; },
		get lightboxReactionCount_love() { return state.lightboxReactions?.love || ''; },
		get lightboxReactionCount_haha() { return state.lightboxReactions?.haha || ''; },
		get lightboxReactionCount_wow() { return state.lightboxReactions?.wow || ''; },
		get lightboxReactionCount_sad() { return state.lightboxReactions?.sad || ''; },
		get lightboxReactionCount_angry() { return state.lightboxReactions?.angry || ''; },
		// Active reaction checks.
		get lightboxUserReactionIsLike() { return state.lightboxUserReaction === 'like'; },
		get lightboxUserReactionIsLove() { return state.lightboxUserReaction === 'love'; },
		get lightboxUserReactionIsHaha() { return state.lightboxUserReaction === 'haha'; },
		get lightboxUserReactionIsWow() { return state.lightboxUserReaction === 'wow'; },
		get lightboxUserReactionIsSad() { return state.lightboxUserReaction === 'sad'; },
		get lightboxUserReactionIsAngry() { return state.lightboxUserReaction === 'angry'; },
		get lightboxIsOwner() {
			const ctx = getContext();
			const authorId = state.lightboxMediaData?.author;
			return ctx.currentUserId > 0 && authorId > 0 && ctx.currentUserId === authorId;
		},
		get lightboxIsGroup() {
			return state.lightboxGroupItems.length > 1;
		},
		get lightboxGroupCount() {
			return state.lightboxGroupItems.length;
		},
		get lightboxPositionText() {
			if ( state.lightboxGroupItems.length < 2 ) return '';
			return ( state.lightboxCurrentIndex + 1 ) + ' / ' + state.lightboxGroupItems.length;
		},
		get lightboxHasPrev() {
			if ( state.lightboxGroupItems.length > 1 ) return true;
			const gridIds = window.mvsGridRegistry || [];
			const idx = gridIds.indexOf( state.lightboxMediaId );
			return idx > 0;
		},
		get lightboxHasNext() {
			if ( state.lightboxGroupItems.length > 1 ) return true;
			const gridIds = window.mvsGridRegistry || [];
			const idx = gridIds.indexOf( state.lightboxMediaId );
			return idx >= 0 && idx < gridIds.length - 1;
		},
	},
	actions: {
		// --- Toast ---
		showToast( msg, type = 'success' ) {
			state.toastMessage = msg;
			state.toastType = type;
			state.toastVisible = true;
			clearTimeout( toastTimer );
			toastTimer = setTimeout( () => {
				state.toastVisible = false;
			}, 3000 );
		},
		hideToast() {
			state.toastVisible = false;
		},

		// --- Confirm ---
		showConfirm( msg, callback, buttonLabel = 'Confirm' ) {
			state.confirmMessage = msg;
			state.confirmCallback = callback;
			state.confirmButtonLabel = buttonLabel;
			state.confirmVisible = true;
		},
		handleConfirmYes() {
			const cb = state.confirmCallback;
			state.confirmVisible = false;
			state.confirmCallback = null;
			if ( typeof cb === 'function' ) {
				cb();
			}
		},
		handleConfirmCancel() {
			state.confirmVisible = false;
			state.confirmCallback = null;
		},

		// --- Tag Autocomplete ---
		searchTags( query, restUrl ) {
			state.tagQuery = query;
			if ( query.length < 2 ) {
				state.tagResults = [];
				state.tagVisible = false;
				return;
			}
			clearTimeout( tagSearchTimer );
			tagSearchTimer = setTimeout( async () => {
				try {
					const res = await fetch(
						restUrl + 'tags?search=' + encodeURIComponent( query ) + '&per_page=8',
						{ credentials: 'same-origin' }
					);
					const data = await res.json();
					state.tagResults = data.map( ( t ) => t.name || t );
					state.tagVisible = state.tagResults.length > 0;
				} catch {
					state.tagResults = [];
					state.tagVisible = false;
				}
			}, 300 );
		},
		hideTagAutocomplete() {
			state.tagVisible = false;
			state.tagResults = [];
		},

		// --- Upload Modal ---
		openUploadModal() {
			const ctx = getContext();
			state.uploadModalMode = ctx.uploadMode || 'photo';
			state.uploadModalVisible = true;
			state.uploadModalFiles = [];
			state.uploadModalPreviews = [];
			state.uploadModalUploading = false;
			state.uploadModalProgress = 0;
			state.uploadModalDone = 0;
			state.uploadModalFailed = 0;
			state.uploadModalTitle = '';
			state.uploadModalDescription = '';
			state.uploadModalTags = '';
			state.uploadModalPrivacy = 'public';
			state.uploadModalAlbumTitle = '';
			state.uploadModalAlbumDescription = '';
			state.uploadModalMediaGroup = null;
			document.body.style.overflow = 'hidden';
		},
		closeUploadModal() {
			state.uploadModalVisible = false;
			state.uploadModalFiles = [];
			state.uploadModalPreviews = [];
			document.body.style.overflow = '';
		},
		setUploadMode() {
			const ctx = getContext();
			state.uploadModalMode = ctx.uploadMode || 'photo';
			state.uploadModalFiles = [];
			state.uploadModalPreviews = [];
		},
		handleUploadClick() {
			const input = document.getElementById( 'mvs-modal-file-input' );
			if ( input ) {
				input.click();
			}
		},
		handleFileSelect( event ) {
			const files = Array.from( event.target.files );
			if ( ! files.length ) return;

			// In photo/video mode, only keep last file.
			if ( state.uploadModalMode === 'photo' || state.uploadModalMode === 'video' ) {
				state.uploadModalFiles = [ files[ files.length - 1 ] ];
			} else {
				state.uploadModalFiles = [ ...state.uploadModalFiles, ...files ];
			}

			// Generate previews.
			state.uploadModalPreviews = [];
			state.uploadModalFiles.forEach( ( file ) => {
				if ( file.type.startsWith( 'image/' ) ) {
					const reader = new FileReader();
					reader.onload = ( e ) => {
						state.uploadModalPreviews = [ ...state.uploadModalPreviews, e.target.result ];
					};
					reader.readAsDataURL( file );
				} else {
					state.uploadModalPreviews = [ ...state.uploadModalPreviews, '' ];
				}
			} );
		},
		handleUploadDrop( event ) {
			event.preventDefault();
			const files = Array.from( event.dataTransfer.files );
			if ( ! files.length ) return;

			if ( state.uploadModalMode === 'photo' || state.uploadModalMode === 'video' ) {
				state.uploadModalFiles = [ files[ 0 ] ];
			} else {
				state.uploadModalFiles = [ ...state.uploadModalFiles, ...files ];
			}

			state.uploadModalPreviews = [];
			state.uploadModalFiles.forEach( ( file ) => {
				if ( file.type.startsWith( 'image/' ) ) {
					const reader = new FileReader();
					reader.onload = ( e ) => {
						state.uploadModalPreviews = [ ...state.uploadModalPreviews, e.target.result ];
					};
					reader.readAsDataURL( file );
				} else {
					state.uploadModalPreviews = [ ...state.uploadModalPreviews, '' ];
				}
			} );
		},
		handleUploadDragOver( event ) {
			event.preventDefault();
		},
		updateUploadTitle( event ) {
			state.uploadModalTitle = event.target.value;
		},
		updateUploadDescription( event ) {
			state.uploadModalDescription = event.target.value;
		},
		updateUploadTags( event ) {
			state.uploadModalTags = event.target.value;
		},
		updateUploadPrivacy( event ) {
			state.uploadModalPrivacy = event.target.value;
		},
		updateAlbumTitle( event ) {
			state.uploadModalAlbumTitle = event.target.value;
		},
		updateAlbumDescription( event ) {
			state.uploadModalAlbumDescription = event.target.value;
		},
		async submitUpload() {
			const ctx = getContext();
			const restUrl = ctx.restUrl;
			const nonce = ctx.nonce;
			const files = state.uploadModalFiles;

			if ( ! files.length && state.uploadModalMode !== 'album' ) {
				actions.showToast( 'Please select files to upload.', 'error' );
				return;
			}

			state.uploadModalUploading = true;
			state.uploadModalTotal = files.length;
			state.uploadModalDone = 0;
			state.uploadModalFailed = 0;

			// For album mode, create album first.
			if ( state.uploadModalMode === 'album' ) {
				if ( ! state.uploadModalAlbumTitle.trim() ) {
					actions.showToast( 'Please enter an album name.', 'error' );
					state.uploadModalUploading = false;
					return;
				}
				try {
					const albumRes = await fetch( restUrl + 'albums', {
						method: 'POST',
						headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
						credentials: 'same-origin',
						body: JSON.stringify( {
							title: state.uploadModalAlbumTitle.trim(),
							description: state.uploadModalAlbumDescription.trim(),
							privacy: state.uploadModalPrivacy,
						} ),
					} );
					const albumData = await albumRes.json();
					if ( albumData.id ) {
						actions.showToast( 'Album "' + albumData.title + '" created!' );
						if ( ! files.length ) {
							state.uploadModalUploading = false;
							setTimeout( () => {
								actions.closeUploadModal();
								window.location.reload();
							}, 800 );
							return;
						}
					} else {
						actions.showToast( albumData.message || 'Failed to create album.', 'error' );
						state.uploadModalUploading = false;
						return;
					}
				} catch {
					actions.showToast( 'Network error creating album.', 'error' );
					state.uploadModalUploading = false;
					return;
				}
			}

			// Generate group ID for gallery mode.
			let mediaGroup = null;
			if ( state.uploadModalMode === 'gallery' && files.length > 1 ) {
				mediaGroup = 'grp_' + Date.now() + '_' + Math.random().toString( 36 ).slice( 2, 8 );
			}

			// Upload files sequentially.
			for ( let i = 0; i < files.length; i++ ) {
				const fd = new FormData();
				fd.append( 'file', files[ i ] );
				if ( state.uploadModalTitle ) fd.append( 'title', state.uploadModalTitle );
				if ( state.uploadModalDescription ) fd.append( 'description', state.uploadModalDescription );
				if ( state.uploadModalTags ) fd.append( 'tags', state.uploadModalTags );
				if ( state.uploadModalPrivacy ) fd.append( 'privacy', state.uploadModalPrivacy );
				if ( mediaGroup ) {
					fd.append( 'media_group', mediaGroup );
					fd.append( 'group_position', String( i ) );
				}

				try {
					const res = await fetch( restUrl + 'media', {
						method: 'POST',
						headers: { 'X-WP-Nonce': nonce },
						credentials: 'same-origin',
						body: fd,
					} );
					if ( ! res.ok ) state.uploadModalFailed++;
				} catch {
					state.uploadModalFailed++;
				}
				state.uploadModalDone = i + 1;
			}

			const uploaded = files.length - state.uploadModalFailed;
			state.uploadModalUploading = false;

			if ( uploaded > 0 ) {
				actions.showToast( uploaded + ' file(s) uploaded!' );
				setTimeout( () => {
					actions.closeUploadModal();
					window.location.reload();
				}, 800 );
			} else {
				actions.showToast( 'Upload failed. Please try again.', 'error' );
			}
		},

		// --- Lightbox ---
		async openLightbox( event ) {
			event.preventDefault();
			const ctx = getContext();
			const mediaId = ctx.mediaId;
			if ( ! mediaId ) return;

			state.lightboxVisible = true;
			state.lightboxMediaId = mediaId;
			state.lightboxLoading = true;
			state.lightboxCommentText = '';
			state.lightboxGroupItems = [];
			state.lightboxCurrentIndex = 0;
			document.body.style.overflow = 'hidden';

			try {
				const headers = {};
				if ( ctx.nonce ) {
					headers[ 'X-WP-Nonce' ] = ctx.nonce;
				}
				const res = await fetch(
					ctx.restUrl + 'media/' + mediaId,
					{ credentials: 'same-origin', headers }
				);
				const data = await res.json();
				state.lightboxMediaData = data;
				state.lightboxLoading = false;

				// If this item is part of a gallery group, fetch all group members.
				if ( data.media_group && data.group_count > 1 ) {
					const groupRes = await fetch(
						ctx.restUrl + 'media/' + mediaId + '/group',
						{ credentials: 'same-origin', headers }
					);
					const groupData = await groupRes.json();
					if ( Array.isArray( groupData ) && groupData.length > 1 ) {
						state.lightboxGroupItems = groupData;
						// Set current index to 0 (cover image).
						state.lightboxCurrentIndex = 0;
						// Show the first image.
						state.lightboxMediaData = groupData[ 0 ];
					}
				}
			// Fetch social data in parallel.
				actions.lightboxLoadSocial( ctx, mediaId, headers );
			} catch {
				state.lightboxLoading = false;
				actions.showToast( 'Failed to load media.', 'error' );
			}
		},
		async openLightboxById( mediaId ) {
			if ( ! mediaId ) return;

			state.lightboxMediaId = mediaId;
			state.lightboxVisible = true;
			state.lightboxLoading = true;
			state.lightboxCommentText = '';
			state.lightboxGroupItems = [];
			state.lightboxCurrentIndex = 0;
			document.body.style.overflow = 'hidden';

			// Find REST URL + nonce from any existing Interactivity context on the page.
			let restUrl = '/wp-json/mvs/v1/';
			let nonce = '';
			const ctxEl = document.querySelector( '[data-wp-interactive="mvs/shared-ui"][data-wp-context]' );
			if ( ctxEl ) {
				try {
					const parsed = JSON.parse( ctxEl.dataset.wpContext );
					restUrl = parsed.restUrl || restUrl;
					nonce = parsed.nonce || nonce;
				} catch { /* use defaults */ }
			}

			try {
				const headers = {};
				if ( nonce ) headers[ 'X-WP-Nonce' ] = nonce;
				const res = await fetch( restUrl + 'media/' + mediaId, {
					credentials: 'same-origin',
					headers,
				} );
				const data = await res.json();
				state.lightboxMediaData = data;
				state.lightboxLoading = false;

				if ( data.media_group && data.group_count > 1 ) {
					const groupRes = await fetch( restUrl + 'media/' + mediaId + '/group', {
						credentials: 'same-origin',
						headers,
					} );
					const groupData = await groupRes.json();
					if ( Array.isArray( groupData ) && groupData.length > 1 ) {
						state.lightboxGroupItems = groupData;
						state.lightboxCurrentIndex = 0;
						state.lightboxMediaData = groupData[ 0 ];
					}
				}

				actions.lightboxLoadSocial( { restUrl, nonce }, mediaId, headers );
			} catch {
				state.lightboxLoading = false;
				actions.showToast( 'Failed to load media.', 'error' );
			}
		},
		noop() {},
		async lightboxLoadSocial( ctx, mediaId, headers ) {
			const opts = { credentials: 'same-origin', headers };
			// Reactions.
			try {
				const r = await fetch( ctx.restUrl + 'media/' + mediaId + '/reactions', opts );
				const rd = await r.json();
				state.lightboxReactions = rd.counts || {};
				state.lightboxUserReaction = rd.user_reaction || '';
			} catch { /* ignore */ }
			// Comments (latest 20, total from header).
			try {
				const c = await fetch( ctx.restUrl + 'media/' + mediaId + '/comments?per_page=20', opts );
				state.lightboxTotalComments = parseInt( c.headers.get( 'X-WP-Total' ) || '0', 10 );
				const cd = await c.json();
				state.lightboxComments = Array.isArray( cd ) ? cd : [];
			} catch { state.lightboxComments = []; state.lightboxTotalComments = 0; }
			// Stats.
			try {
				const s = await fetch( ctx.restUrl + 'media/' + mediaId + '/stats', opts );
				state.lightboxStats = await s.json();
			} catch { state.lightboxStats = {}; }
			// Favorite status.
			try {
				const f = await fetch( ctx.restUrl + 'media/' + mediaId + '/favorite', opts );
				const fd = await f.json();
				state.lightboxIsFavorited = !! fd.favorited;
			} catch { state.lightboxIsFavorited = false; }
		},
		async lightboxToggleReaction( event ) {
			const ctx = getContext();
			const type = event.target.closest( '[data-reaction]' )?.dataset.reaction;
			if ( ! type || ! state.lightboxMediaId ) return;
			const headers = { 'X-WP-Nonce': ctx.nonce, 'Content-Type': 'application/json' };
			const isActive = state.lightboxUserReaction === type;
			try {
				await fetch( ctx.restUrl + 'media/' + state.lightboxMediaId + '/reactions', {
					method: isActive ? 'DELETE' : 'POST',
					credentials: 'same-origin',
					headers,
					body: JSON.stringify( { reaction_type: type } ),
				} );
				if ( isActive ) {
					state.lightboxUserReaction = '';
					const c = state.lightboxReactions[ type ];
					state.lightboxReactions = { ...state.lightboxReactions, [ type ]: Math.max( 0, ( c || 1 ) - 1 ) };
				} else {
					// Remove old reaction count.
					if ( state.lightboxUserReaction ) {
						const old = state.lightboxUserReaction;
						state.lightboxReactions = { ...state.lightboxReactions, [ old ]: Math.max( 0, ( state.lightboxReactions[ old ] || 1 ) - 1 ) };
					}
					state.lightboxUserReaction = type;
					state.lightboxReactions = { ...state.lightboxReactions, [ type ]: ( state.lightboxReactions[ type ] || 0 ) + 1 };
				}
			} catch { /* ignore */ }
		},
		async lightboxToggleFavorite() {
			const ctx = getContext();
			if ( ! state.lightboxMediaId ) return;
			const headers = { 'X-WP-Nonce': ctx.nonce };
			try {
				await fetch( ctx.restUrl + 'media/' + state.lightboxMediaId + '/favorite', {
					method: state.lightboxIsFavorited ? 'DELETE' : 'POST',
					credentials: 'same-origin',
					headers,
				} );
				state.lightboxIsFavorited = ! state.lightboxIsFavorited;
			} catch { /* ignore */ }
		},
		lightboxUpdateComment( event ) {
			state.lightboxCommentText = event.target.value;
		},
		lightboxCommentKeydown( event ) {
			if ( event.key === 'Enter' && state.lightboxCommentText.trim() ) {
				actions.lightboxPostComment();
			}
		},
		async lightboxPostComment() {
			const ctx = getContext();
			const text = state.lightboxCommentText.trim();
			if ( ! text || ! state.lightboxMediaId ) return;
			const headers = { 'X-WP-Nonce': ctx.nonce, 'Content-Type': 'application/json' };
			try {
				const r = await fetch( ctx.restUrl + 'media/' + state.lightboxMediaId + '/comments', {
					method: 'POST',
					credentials: 'same-origin',
					headers,
					body: JSON.stringify( { content: text } ),
				} );
				const comment = await r.json();
				if ( comment.id ) {
					state.lightboxComments = [ ...state.lightboxComments, comment ];
					state.lightboxCommentText = '';
				}
			} catch {
				actions.showToast( 'Failed to post comment.', 'error' );
			}
		},
		lightboxShare() {
			const url = state.lightboxMediaData?.link || window.location.href;
			if ( navigator.share ) {
				navigator.share( { title: state.lightboxTitle, url } );
			} else if ( navigator.clipboard ) {
				navigator.clipboard.writeText( url );
				actions.showToast( 'Link copied!', 'success' );
			}
		},
		lightboxReport() {
			// Navigate to the single media page where the full report dialog lives.
			const url = state.lightboxMediaData?.link;
			if ( url ) {
				window.location.href = url + '#report';
			}
		},
		lightboxPrev() {
			if ( state.lightboxGroupItems.length > 1 ) {
				let idx = state.lightboxCurrentIndex - 1;
				if ( idx < 0 ) idx = state.lightboxGroupItems.length - 1;
				state.lightboxCurrentIndex = idx;
				state.lightboxMediaData = state.lightboxGroupItems[ idx ];
				return;
			}
			const gridIds = window.mvsGridRegistry || [];
			const currentIdx = gridIds.indexOf( state.lightboxMediaId );
			if ( currentIdx > 0 ) {
				actions.openLightboxById( gridIds[ currentIdx - 1 ] );
			}
		},
		lightboxNext() {
			if ( state.lightboxGroupItems.length > 1 ) {
				let idx = state.lightboxCurrentIndex + 1;
				if ( idx >= state.lightboxGroupItems.length ) idx = 0;
				state.lightboxCurrentIndex = idx;
				state.lightboxMediaData = state.lightboxGroupItems[ idx ];
				return;
			}
			const gridIds = window.mvsGridRegistry || [];
			const currentIdx = gridIds.indexOf( state.lightboxMediaId );
			if ( currentIdx >= 0 && currentIdx < gridIds.length - 1 ) {
				actions.openLightboxById( gridIds[ currentIdx + 1 ] );
			}
		},
		closeLightbox() {
			state.lightboxVisible = false;
			state.lightboxMediaData = null;
			state.lightboxGroupItems = [];
			state.lightboxComments = [];
			state.lightboxTotalComments = 0;
			state.lightboxReactions = {};
			state.lightboxUserReaction = '';
			state.lightboxIsFavorited = false;
			state.lightboxStats = {};
			state.lightboxCommentText = '';
			document.body.style.overflow = '';
		},
		handleModalClick( event ) {
			event.stopPropagation();
		},
		handleLightboxKeydown( event ) {
			if ( event.key === 'Escape' ) {
				if ( state.uploadModalVisible ) {
					actions.closeUploadModal();
				} else if ( state.lightboxVisible ) {
					actions.closeLightbox();
				}
			} else if ( state.lightboxVisible ) {
				if ( event.key === 'ArrowLeft' && state.lightboxHasPrev ) {
					actions.lightboxPrev();
				} else if ( event.key === 'ArrowRight' && state.lightboxHasNext ) {
					actions.lightboxNext();
				}
			}
		},
	},
} );

// Bridge: expose openLightboxById to vanilla JS (used by load-more.js delegated click handler).
window.mvsOpenLightbox = function ( mediaId ) {
	actions.openLightboxById( mediaId );
};
