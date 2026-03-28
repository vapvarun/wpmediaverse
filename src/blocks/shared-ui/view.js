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
		toast: {
			message: '',
			type: 'success',
			visible: false,
		},
		confirm: {
			message: '',
			visible: false,
			onConfirm: null,
		},
		get isToastSuccess() { return state.toast.type === 'success'; },
		get isToastError() { return state.toast.type === 'error'; },
		tagAutocomplete: {
			query: '',
			results: [],
			visible: false,
		},

		// Upload modal state.
		uploadModal: {
			visible: false,
			mode: 'photo', // photo | gallery | album | video
			files: [],
			previews: [],
			uploading: false,
			progress: 0,
			total: 0,
			done: 0,
			failed: 0,
			title: '',
			description: '',
			tags: '',
			privacy: 'public',
			albumTitle: '',
			albumDescription: '',
			mediaGroup: null,
		},
		get uploadModalTitle() {
			const titles = { photo: 'Upload Photo', gallery: 'Create Gallery Post', album: 'Create Album', video: 'Upload Video' };
			return titles[ state.uploadModal.mode ] || 'Upload';
		},
		get uploadAccept() {
			if ( state.uploadModal.mode === 'video' ) return 'video/*';
			return 'image/*';
		},
		get uploadMultiple() {
			return state.uploadModal.mode === 'gallery';
		},
		get isAlbumMode() {
			return state.uploadModal.mode === 'album';
		},
		get hasFiles() {
			return state.uploadModal.files.length > 0;
		},
		get uploadProgressText() {
			if ( ! state.uploadModal.uploading ) return '';
			return 'Uploading ' + ( state.uploadModal.done + 1 ) + ' of ' + state.uploadModal.total + '...';
		},

		// Lightbox state.
		lightbox: {
			visible: false,
			mediaId: null,
			mediaData: null,
			groupItems: [],
			currentIndex: 0,
			loading: false,
			commentText: '',
		},
		get lightboxImageUrl() {
			return state.lightbox.mediaData?.file_url || '';
		},
		get lightboxTitle() {
			return state.lightbox.mediaData?.title || '';
		},
		get lightboxAuthor() {
			return state.lightbox.mediaData?.author_data?.name || '';
		},
		get lightboxAuthorAvatar() {
			return state.lightbox.mediaData?.author_data?.avatar || '';
		},
		get lightboxIsGroup() {
			return state.lightbox.groupItems.length > 1;
		},
		get lightboxGroupCount() {
			return state.lightbox.groupItems.length;
		},
	},
	actions: {
		// --- Toast ---
		showToast( msg, type = 'success' ) {
			state.toast.message = msg;
			state.toast.type = type;
			state.toast.visible = true;
			clearTimeout( toastTimer );
			toastTimer = setTimeout( () => {
				state.toast.visible = false;
			}, 3000 );
		},
		hideToast() {
			state.toast.visible = false;
		},

		// --- Confirm ---
		showConfirm( msg, callback ) {
			state.confirm.message = msg;
			state.confirm.onConfirm = callback;
			state.confirm.visible = true;
		},
		handleConfirmYes() {
			const cb = state.confirm.onConfirm;
			state.confirm.visible = false;
			state.confirm.onConfirm = null;
			if ( typeof cb === 'function' ) {
				cb();
			}
		},
		handleConfirmCancel() {
			state.confirm.visible = false;
			state.confirm.onConfirm = null;
		},

		// --- Tag Autocomplete ---
		searchTags( query, restUrl ) {
			state.tagAutocomplete.query = query;
			if ( query.length < 2 ) {
				state.tagAutocomplete.results = [];
				state.tagAutocomplete.visible = false;
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
					state.tagAutocomplete.results = data.map( ( t ) => t.name || t );
					state.tagAutocomplete.visible = state.tagAutocomplete.results.length > 0;
				} catch {
					state.tagAutocomplete.results = [];
					state.tagAutocomplete.visible = false;
				}
			}, 300 );
		},
		hideTagAutocomplete() {
			state.tagAutocomplete.visible = false;
			state.tagAutocomplete.results = [];
		},

		// --- Upload Modal ---
		openUploadModal() {
			const ctx = getContext();
			state.uploadModal.mode = ctx.uploadMode || 'photo';
			state.uploadModal.visible = true;
			state.uploadModal.files = [];
			state.uploadModal.previews = [];
			state.uploadModal.uploading = false;
			state.uploadModal.progress = 0;
			state.uploadModal.done = 0;
			state.uploadModal.failed = 0;
			state.uploadModal.title = '';
			state.uploadModal.description = '';
			state.uploadModal.tags = '';
			state.uploadModal.privacy = 'public';
			state.uploadModal.albumTitle = '';
			state.uploadModal.albumDescription = '';
			state.uploadModal.mediaGroup = null;
			document.body.style.overflow = 'hidden';
		},
		closeUploadModal() {
			state.uploadModal.visible = false;
			state.uploadModal.files = [];
			state.uploadModal.previews = [];
			document.body.style.overflow = '';
		},
		setUploadMode() {
			const ctx = getContext();
			state.uploadModal.mode = ctx.uploadMode || 'photo';
			state.uploadModal.files = [];
			state.uploadModal.previews = [];
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
			if ( state.uploadModal.mode === 'photo' || state.uploadModal.mode === 'video' ) {
				state.uploadModal.files = [ files[ files.length - 1 ] ];
			} else {
				state.uploadModal.files = [ ...state.uploadModal.files, ...files ];
			}

			// Generate previews.
			state.uploadModal.previews = [];
			state.uploadModal.files.forEach( ( file ) => {
				if ( file.type.startsWith( 'image/' ) ) {
					const reader = new FileReader();
					reader.onload = ( e ) => {
						state.uploadModal.previews = [ ...state.uploadModal.previews, e.target.result ];
					};
					reader.readAsDataURL( file );
				} else {
					state.uploadModal.previews = [ ...state.uploadModal.previews, '' ];
				}
			} );
		},
		handleUploadDrop( event ) {
			event.preventDefault();
			const files = Array.from( event.dataTransfer.files );
			if ( ! files.length ) return;

			if ( state.uploadModal.mode === 'photo' || state.uploadModal.mode === 'video' ) {
				state.uploadModal.files = [ files[ 0 ] ];
			} else {
				state.uploadModal.files = [ ...state.uploadModal.files, ...files ];
			}

			state.uploadModal.previews = [];
			state.uploadModal.files.forEach( ( file ) => {
				if ( file.type.startsWith( 'image/' ) ) {
					const reader = new FileReader();
					reader.onload = ( e ) => {
						state.uploadModal.previews = [ ...state.uploadModal.previews, e.target.result ];
					};
					reader.readAsDataURL( file );
				} else {
					state.uploadModal.previews = [ ...state.uploadModal.previews, '' ];
				}
			} );
		},
		handleUploadDragOver( event ) {
			event.preventDefault();
		},
		updateUploadTitle( event ) {
			state.uploadModal.title = event.target.value;
		},
		updateUploadDescription( event ) {
			state.uploadModal.description = event.target.value;
		},
		updateUploadTags( event ) {
			state.uploadModal.tags = event.target.value;
		},
		updateUploadPrivacy( event ) {
			state.uploadModal.privacy = event.target.value;
		},
		updateAlbumTitle( event ) {
			state.uploadModal.albumTitle = event.target.value;
		},
		updateAlbumDescription( event ) {
			state.uploadModal.albumDescription = event.target.value;
		},
		async submitUpload() {
			const ctx = getContext();
			const restUrl = ctx.restUrl;
			const nonce = ctx.nonce;
			const files = state.uploadModal.files;

			if ( ! files.length && state.uploadModal.mode !== 'album' ) {
				actions.showToast( 'Please select files to upload.', 'error' );
				return;
			}

			state.uploadModal.uploading = true;
			state.uploadModal.total = files.length;
			state.uploadModal.done = 0;
			state.uploadModal.failed = 0;

			// For album mode, create album first.
			if ( state.uploadModal.mode === 'album' ) {
				if ( ! state.uploadModal.albumTitle.trim() ) {
					actions.showToast( 'Please enter an album name.', 'error' );
					state.uploadModal.uploading = false;
					return;
				}
				try {
					const albumRes = await fetch( restUrl + 'albums', {
						method: 'POST',
						headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
						credentials: 'same-origin',
						body: JSON.stringify( {
							title: state.uploadModal.albumTitle.trim(),
							description: state.uploadModal.albumDescription.trim(),
							privacy: state.uploadModal.privacy,
						} ),
					} );
					const albumData = await albumRes.json();
					if ( albumData.id ) {
						actions.showToast( 'Album "' + albumData.title + '" created!' );
						// If files selected, upload them to the album.
						if ( ! files.length ) {
							state.uploadModal.uploading = false;
							setTimeout( () => {
								actions.closeUploadModal();
								window.location.reload();
							}, 800 );
							return;
						}
					} else {
						actions.showToast( albumData.message || 'Failed to create album.', 'error' );
						state.uploadModal.uploading = false;
						return;
					}
				} catch {
					actions.showToast( 'Network error creating album.', 'error' );
					state.uploadModal.uploading = false;
					return;
				}
			}

			// Generate group ID for gallery mode.
			let mediaGroup = null;
			if ( state.uploadModal.mode === 'gallery' && files.length > 1 ) {
				mediaGroup = 'grp_' + Date.now() + '_' + Math.random().toString( 36 ).slice( 2, 8 );
			}

			// Upload files sequentially.
			for ( let i = 0; i < files.length; i++ ) {
				const fd = new FormData();
				fd.append( 'file', files[ i ] );
				if ( state.uploadModal.title ) fd.append( 'title', state.uploadModal.title );
				if ( state.uploadModal.description ) fd.append( 'description', state.uploadModal.description );
				if ( state.uploadModal.tags ) fd.append( 'tags', state.uploadModal.tags );
				if ( state.uploadModal.privacy ) fd.append( 'privacy', state.uploadModal.privacy );
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
					if ( ! res.ok ) state.uploadModal.failed++;
				} catch {
					state.uploadModal.failed++;
				}
				state.uploadModal.done = i + 1;
			}

			const uploaded = files.length - state.uploadModal.failed;
			state.uploadModal.uploading = false;

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
		async openLightbox() {
			const ctx = getContext();
			const mediaId = ctx.mediaId;
			if ( ! mediaId ) return;

			state.lightbox.visible = true;
			state.lightbox.mediaId = mediaId;
			state.lightbox.loading = true;
			state.lightbox.commentText = '';
			document.body.style.overflow = 'hidden';

			try {
				const res = await fetch(
					ctx.restUrl + 'media/' + mediaId,
					{ credentials: 'same-origin' }
				);
				state.lightbox.mediaData = await res.json();
				state.lightbox.loading = false;
			} catch {
				state.lightbox.loading = false;
				actions.showToast( 'Failed to load media.', 'error' );
			}
		},
		closeLightbox() {
			state.lightbox.visible = false;
			state.lightbox.mediaData = null;
			state.lightbox.groupItems = [];
			document.body.style.overflow = '';
		},
		handleLightboxKeydown( event ) {
			if ( event.key === 'Escape' ) {
				if ( state.uploadModal.visible ) {
					actions.closeUploadModal();
				} else if ( state.lightbox.visible ) {
					actions.closeLightbox();
				}
			}
		},
	},
} );
