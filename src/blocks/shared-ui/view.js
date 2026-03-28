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

		get lightboxImageUrl() {
			return state.lightboxMediaData?.file_url || '';
		},
		get lightboxTitle() {
			return state.lightboxMediaData?.title || '';
		},
		get lightboxAuthor() {
			return state.lightboxMediaData?.author_data?.name || '';
		},
		get lightboxAuthorAvatar() {
			return state.lightboxMediaData?.author_data?.avatar || '';
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
		showConfirm( msg, callback ) {
			state.confirmMessage = msg;
			state.confirmCallback = callback;
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
				const res = await fetch(
					ctx.restUrl + 'media/' + mediaId,
					{ credentials: 'same-origin' }
				);
				const data = await res.json();
				state.lightboxMediaData = data;
				state.lightboxLoading = false;

				// If this item is part of a gallery group, fetch all group members.
				if ( data.media_group && data.group_count > 1 ) {
					const groupRes = await fetch(
						ctx.restUrl + 'media/' + mediaId + '/group',
						{ credentials: 'same-origin' }
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
			} catch {
				state.lightboxLoading = false;
				actions.showToast( 'Failed to load media.', 'error' );
			}
		},
		lightboxPrev() {
			if ( state.lightboxGroupItems.length < 2 ) return;
			let idx = state.lightboxCurrentIndex - 1;
			if ( idx < 0 ) idx = state.lightboxGroupItems.length - 1;
			state.lightboxCurrentIndex = idx;
			state.lightboxMediaData = state.lightboxGroupItems[ idx ];
		},
		lightboxNext() {
			if ( state.lightboxGroupItems.length < 2 ) return;
			let idx = state.lightboxCurrentIndex + 1;
			if ( idx >= state.lightboxGroupItems.length ) idx = 0;
			state.lightboxCurrentIndex = idx;
			state.lightboxMediaData = state.lightboxGroupItems[ idx ];
		},
		closeLightbox() {
			state.lightboxVisible = false;
			state.lightboxMediaData = null;
			state.lightboxGroupItems = [];
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
			} else if ( state.lightboxVisible && state.lightboxGroupItems.length > 1 ) {
				if ( event.key === 'ArrowLeft' ) {
					actions.lightboxPrev();
				} else if ( event.key === 'ArrowRight' ) {
					actions.lightboxNext();
				}
			}
		},
	},
} );
