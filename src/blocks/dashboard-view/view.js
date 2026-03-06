/**
 * Interactivity API store: user dashboard (My Media, My Albums, My Favorites).
 *
 * Replaces assets/js/mvs-dashboard.js (1,075 lines).
 *
 * @package WPMediaVerse
 */

import { store, getContext } from '@wordpress/interactivity';

const sharedUI = store( 'mvs/shared-ui' );

function apiHeaders( nonce ) {
	return {
		'Content-Type': 'application/json',
		'X-WP-Nonce': nonce,
	};
}

function apiFetch( ctx, path, opts = {} ) {
	opts.credentials = 'same-origin';
	if ( ! opts.headers ) {
		opts.headers = apiHeaders( ctx.nonce );
	}
	return fetch( ctx.restUrl + path, opts );
}

const { state, actions } = store( 'mvs/dashboard', {
	state: {
		activeTab: 'media',
		// My Media
		media: {
			items: [],
			page: 1,
			totalPages: 1,
			loading: false,
		},
		// Upload
		upload: {
			dragOver: false,
			uploading: false,
			status: '',
			showFields: false,
			title: '',
			description: '',
			tags: '',
			privacy: 'public',
		},
		// Edit modal
		editModal: {
			visible: false,
			itemId: 0,
			title: '',
			description: '',
			privacy: 'public',
			tags: [],
			tagInput: '',
			tagResults: [],
			tagDropdownVisible: false,
			saving: false,
		},
		// Albums
		albums: {
			items: [],
			loading: false,
		},
		// Album modal
		albumModal: {
			visible: false,
			isEdit: false,
			albumId: 0,
			title: '',
			description: '',
			privacy: 'public',
			pickerItems: [],
			selectedIds: [],
			pickerLoading: false,
			saving: false,
		},
		// Favorites
		favorites: {
			items: [],
			page: 1,
			totalPages: 1,
			loading: false,
		},
		// Confirm dialog (local, also uses shared-ui)
		get isMediaTab() { return state.activeTab === 'media'; },
		get isAlbumsTab() { return state.activeTab === 'albums'; },
		get isFavoritesTab() { return state.activeTab === 'favorites'; },
		get hasMoreMedia() { return state.media.page < state.media.totalPages; },
		get hasMoreFavorites() { return state.favorites.page < state.favorites.totalPages; },
		get showMediaEmpty() { return state.media.items.length === 0 && ! state.media.loading; },
		get showAlbumsEmpty() { return state.albums.items.length === 0 && ! state.albums.loading; },
		get showFavoritesEmpty() { return state.favorites.items.length === 0 && ! state.favorites.loading; },
	},
	actions: {
		/* =====================================================================
		   Tabs
		   ===================================================================== */
		switchTab( event ) {
			const tab = event.target.closest( '[data-tab]' )?.dataset.tab;
			if ( ! tab ) return;
			state.activeTab = tab;
			const ctx = getContext();
			if ( tab === 'media' && state.media.items.length === 0 ) {
				actions.loadMedia( ctx );
			} else if ( tab === 'albums' && state.albums.items.length === 0 ) {
				actions.loadAlbums( ctx );
			} else if ( tab === 'favorites' && state.favorites.items.length === 0 ) {
				actions.loadFavorites( ctx );
			}
		},

		/* =====================================================================
		   Upload
		   ===================================================================== */
		handleUploadClick( event ) {
			if ( event.target.closest( 'input, select, textarea, button' ) ) return;
			const dropzone = event.target.closest( '.mvs-dashboard-dropzone' );
			if ( ! dropzone ) return;
			const input = dropzone.querySelector( '.mvs-upload-file-input' );
			if ( input ) input.click();
		},

		handleUploadDragOver( event ) {
			event.preventDefault();
			state.upload.dragOver = true;
		},

		handleUploadDragLeave() {
			state.upload.dragOver = false;
		},

		handleUploadDrop( event ) {
			event.preventDefault();
			state.upload.dragOver = false;
			const files = Array.from( event.dataTransfer.files );
			if ( files.length ) actions.uploadFiles( files );
		},

		handleUploadFileSelect( event ) {
			const files = Array.from( event.target.files );
			if ( files.length ) actions.uploadFiles( files );
			event.target.value = '';
		},

		toggleUploadFields() {
			state.upload.showFields = ! state.upload.showFields;
		},

		setUploadTitle( event ) { state.upload.title = event.target.value; },
		setUploadDesc( event ) { state.upload.description = event.target.value; },
		setUploadTags( event ) { state.upload.tags = event.target.value; },
		setUploadPrivacy( event ) { state.upload.privacy = event.target.value; },

		async uploadFiles( files ) {
			const ctx = getContext();
			state.upload.uploading = true;
			const total = files.length;

			for ( let i = 0; i < total; i++ ) {
				state.upload.status = `Uploading ${ i + 1 } of ${ total }...`;
				const formData = new FormData();
				formData.append( 'file', files[ i ] );
				if ( state.upload.privacy ) formData.append( 'privacy', state.upload.privacy );
				if ( state.upload.title && total === 1 ) formData.append( 'title', state.upload.title );
				if ( state.upload.description && total === 1 ) formData.append( 'description', state.upload.description );
				if ( state.upload.tags ) {
					state.upload.tags.split( ',' ).map( ( t ) => t.trim() ).filter( Boolean )
						.forEach( ( tag ) => formData.append( 'tags[]', tag ) );
				}
				try {
					await fetch( ctx.restUrl + 'media', {
						method: 'POST',
						headers: { 'X-WP-Nonce': ctx.nonce },
						credentials: 'same-origin',
						body: formData,
					} );
				} catch {
					// Continue with remaining.
				}
			}

			state.upload.uploading = false;
			state.upload.status = '';
			sharedUI.actions.showToast( total + ' file(s) uploaded!', 'success' );
			state.media.page = 1;
			actions.loadMedia( ctx, 1 );
		},

		/* =====================================================================
		   My Media
		   ===================================================================== */
		async loadMedia( ctxOrEvent, page = 1 ) {
			const ctx = typeof ctxOrEvent?.restUrl === 'string' ? ctxOrEvent : getContext();
			state.media.loading = true;
			state.media.page = page;

			try {
				const res = await apiFetch( ctx, 'me/media?per_page=20&page=' + page );
				state.media.totalPages = parseInt( res.headers.get( 'X-WP-TotalPages' ) || '1', 10 );
				const data = await res.json();

				if ( page === 1 ) {
					state.media.items = data;
				} else {
					state.media.items = [ ...state.media.items, ...data ];
				}
			} catch {
				// Ignore.
			}
			state.media.loading = false;
		},

		loadMoreMedia() {
			const ctx = getContext();
			actions.loadMedia( ctx, state.media.page + 1 );
		},

		/* =====================================================================
		   Edit Media Modal
		   ===================================================================== */
		openEditModal( event ) {
			const id = parseInt( event.target.closest( '[data-media-id]' )?.dataset.mediaId, 10 );
			const item = state.media.items.find( ( m ) => m.id === id );
			if ( ! item ) return;

			state.editModal.visible = true;
			state.editModal.itemId = id;
			state.editModal.title = item.title || '';
			state.editModal.description = item.description || '';
			state.editModal.privacy = item.privacy || 'public';
			state.editModal.tags = item.tags ? [ ...item.tags ] : [];
			state.editModal.tagInput = '';
			state.editModal.tagResults = [];
			state.editModal.tagDropdownVisible = false;
		},

		closeEditModal() {
			state.editModal.visible = false;
		},

		setEditTitle( event ) { state.editModal.title = event.target.value; },
		setEditDesc( event ) { state.editModal.description = event.target.value; },
		setEditPrivacy( event ) { state.editModal.privacy = event.target.value; },

		updateEditTagInput( event ) {
			const ctx = getContext();
			state.editModal.tagInput = event.target.value;
			sharedUI.actions.searchTags( state.editModal.tagInput, ctx.restUrl );
			setTimeout( () => {
				const uiState = store( 'mvs/shared-ui' ).state;
				state.editModal.tagResults = ( uiState.tagAutocomplete?.results || [] )
					.filter( ( t ) => ! state.editModal.tags.includes( t ) );
				state.editModal.tagDropdownVisible = state.editModal.tagResults.length > 0;
			}, 350 );
		},

		addEditTag( event ) {
			if ( event.key !== 'Enter' ) return;
			event.preventDefault();
			const val = state.editModal.tagInput.trim();
			if ( val && ! state.editModal.tags.includes( val ) ) {
				state.editModal.tags = [ ...state.editModal.tags, val ];
			}
			state.editModal.tagInput = '';
			state.editModal.tagDropdownVisible = false;
		},

		selectEditTag( event ) {
			const tag = event.target.dataset.tagName;
			if ( tag && ! state.editModal.tags.includes( tag ) ) {
				state.editModal.tags = [ ...state.editModal.tags, tag ];
			}
			state.editModal.tagInput = '';
			state.editModal.tagDropdownVisible = false;
		},

		removeEditTag( event ) {
			const tag = event.target.closest( '[data-tag-name]' )?.dataset.tagName;
			if ( tag ) {
				state.editModal.tags = state.editModal.tags.filter( ( t ) => t !== tag );
			}
		},

		async saveEdit() {
			const ctx = getContext();
			state.editModal.saving = true;

			const payload = {
				title: state.editModal.title,
				description: state.editModal.description,
				privacy: state.editModal.privacy,
				tags: state.editModal.tags,
			};

			try {
				const res = await apiFetch( ctx, 'media/' + state.editModal.itemId, {
					method: 'PUT',
					headers: apiHeaders( ctx.nonce ),
					body: JSON.stringify( payload ),
				} );
				const updated = await res.json();
				state.editModal.saving = false;
				state.editModal.visible = false;

				// Update item in-place.
				const idx = state.media.items.findIndex( ( m ) => m.id === state.editModal.itemId );
				if ( idx !== -1 ) {
					state.media.items[ idx ] = { ...state.media.items[ idx ], ...updated };
				}

				sharedUI.actions.showToast( 'Media updated!', 'success' );
			} catch {
				state.editModal.saving = false;
				sharedUI.actions.showToast( 'Update failed.', 'error' );
			}
		},

		/* =====================================================================
		   Delete Media
		   ===================================================================== */
		confirmDeleteMedia( event ) {
			const id = parseInt( event.target.closest( '[data-media-id]' )?.dataset.mediaId, 10 );
			const ctx = getContext();
			sharedUI.actions.showConfirm( 'Delete this media item? This cannot be undone.', async () => {
				const res = await apiFetch( ctx, 'media/' + id, {
					method: 'DELETE',
					headers: apiHeaders( ctx.nonce ),
				} );
				if ( res.ok ) {
					state.media.items = state.media.items.filter( ( m ) => m.id !== id );
					sharedUI.actions.showToast( 'Media deleted.', 'success' );
				} else {
					sharedUI.actions.showToast( 'Delete failed.', 'error' );
				}
			} );
		},

		/* =====================================================================
		   Albums
		   ===================================================================== */
		async loadAlbums( ctxOrEvent ) {
			const ctx = typeof ctxOrEvent?.restUrl === 'string' ? ctxOrEvent : getContext();
			state.albums.loading = true;
			try {
				const res = await apiFetch( ctx, 'albums?author=' + ctx.userId );
				const data = await res.json();
				state.albums.items = data;
			} catch {
				// Ignore.
			}
			state.albums.loading = false;
		},

		/* =====================================================================
		   Album Modal (Create / Edit)
		   ===================================================================== */
		openAlbumModal( event ) {
			const id = event?.target?.closest( '[data-album-id]' )?.dataset.albumId;
			const album = id ? state.albums.items.find( ( a ) => a.id === parseInt( id, 10 ) ) : null;

			state.albumModal.visible = true;
			state.albumModal.isEdit = !! album;
			state.albumModal.albumId = album?.id || 0;
			state.albumModal.title = album?.title || '';
			state.albumModal.description = album?.description || '';
			state.albumModal.privacy = album?.privacy || 'public';
			state.albumModal.selectedIds = album?.items ? [ ...album.items ] : [];
			state.albumModal.saving = false;

			// Load user media for picker.
			actions.loadPickerMedia();
		},

		openCreateAlbum() {
			state.albumModal.visible = true;
			state.albumModal.isEdit = false;
			state.albumModal.albumId = 0;
			state.albumModal.title = '';
			state.albumModal.description = '';
			state.albumModal.privacy = 'public';
			state.albumModal.selectedIds = [];
			state.albumModal.saving = false;
			actions.loadPickerMedia();
		},

		closeAlbumModal() {
			state.albumModal.visible = false;
		},

		setAlbumTitle( event ) { state.albumModal.title = event.target.value; },
		setAlbumDesc( event ) { state.albumModal.description = event.target.value; },
		setAlbumPrivacy( event ) { state.albumModal.privacy = event.target.value; },

		async loadPickerMedia() {
			const ctx = getContext();
			state.albumModal.pickerLoading = true;
			try {
				const res = await apiFetch( ctx, 'me/media?per_page=100' );
				const data = await res.json();
				state.albumModal.pickerItems = data;
			} catch {
				state.albumModal.pickerItems = [];
			}
			state.albumModal.pickerLoading = false;
		},

		togglePickerItem( event ) {
			const id = parseInt( event.target.closest( '[data-picker-id]' )?.dataset.pickerId, 10 );
			if ( ! id ) return;
			if ( state.albumModal.selectedIds.includes( id ) ) {
				state.albumModal.selectedIds = state.albumModal.selectedIds.filter( ( sid ) => sid !== id );
			} else {
				state.albumModal.selectedIds = [ ...state.albumModal.selectedIds, id ];
			}
		},

		async saveAlbum() {
			const ctx = getContext();
			state.albumModal.saving = true;

			const payload = {
				title: state.albumModal.title,
				description: state.albumModal.description,
				privacy: state.albumModal.privacy,
			};

			try {
				if ( state.albumModal.isEdit ) {
					await apiFetch( ctx, 'albums/' + state.albumModal.albumId, {
						method: 'PUT',
						headers: apiHeaders( ctx.nonce ),
						body: JSON.stringify( payload ),
					} );
					sharedUI.actions.showToast( 'Album updated!', 'success' );
				} else {
					const res = await apiFetch( ctx, 'albums', {
						method: 'POST',
						headers: apiHeaders( ctx.nonce ),
						body: JSON.stringify( payload ),
					} );
					const created = await res.json();
					if ( state.albumModal.selectedIds.length && created.id ) {
						await apiFetch( ctx, 'albums/' + created.id + '/items', {
							method: 'POST',
							headers: apiHeaders( ctx.nonce ),
							body: JSON.stringify( { media_ids: state.albumModal.selectedIds } ),
						} );
					}
					sharedUI.actions.showToast( 'Album created!', 'success' );
				}
				state.albumModal.visible = false;
				state.albumModal.saving = false;
				await actions.loadAlbums( ctx );
			} catch {
				state.albumModal.saving = false;
				sharedUI.actions.showToast( 'Save failed.', 'error' );
			}
		},

		confirmDeleteAlbum( event ) {
			const id = parseInt( event.target.closest( '[data-album-id]' )?.dataset.albumId, 10 );
			const ctx = getContext();
			sharedUI.actions.showConfirm( 'Delete this album? Media items will not be deleted.', async () => {
				const res = await apiFetch( ctx, 'albums/' + id, {
					method: 'DELETE',
					headers: apiHeaders( ctx.nonce ),
				} );
				if ( res.ok ) {
					state.albums.items = state.albums.items.filter( ( a ) => a.id !== id );
					sharedUI.actions.showToast( 'Album deleted.', 'success' );
				}
			} );
		},

		/* =====================================================================
		   Favorites
		   ===================================================================== */
		async loadFavorites( ctxOrEvent, page = 1 ) {
			const ctx = typeof ctxOrEvent?.restUrl === 'string' ? ctxOrEvent : getContext();
			state.favorites.loading = true;
			state.favorites.page = page;

			try {
				const res = await apiFetch( ctx, 'me/favorites?per_page=20&page=' + page );
				state.favorites.totalPages = parseInt( res.headers.get( 'X-WP-TotalPages' ) || '1', 10 );
				const data = await res.json();

				if ( page === 1 ) {
					state.favorites.items = data;
				} else {
					state.favorites.items = [ ...state.favorites.items, ...data ];
				}
			} catch {
				// Ignore.
			}
			state.favorites.loading = false;
		},

		loadMoreFavorites() {
			const ctx = getContext();
			actions.loadFavorites( ctx, state.favorites.page + 1 );
		},

		async unfavorite( event ) {
			const mediaId = parseInt( event.target.closest( '[data-fav-id]' )?.dataset.favId, 10 );
			const ctx = getContext();
			const res = await apiFetch( ctx, 'media/' + mediaId + '/favorite', {
				method: 'DELETE',
				headers: apiHeaders( ctx.nonce ),
			} );
			if ( res.ok ) {
				state.favorites.items = state.favorites.items.filter( ( f ) => f.media_id !== mediaId );
				sharedUI.actions.showToast( 'Removed from favorites.', 'success' );
			}
		},

		/* =====================================================================
		   Utility
		   ===================================================================== */
		stopPropagation( event ) {
			event.stopPropagation();
		},

		closeOverlay( event ) {
			if ( event.target === event.currentTarget ) {
				state.editModal.visible = false;
				state.albumModal.visible = false;
			}
		},
	},
	callbacks: {
		init() {
			const ctx = getContext();
			actions.loadMedia( ctx );
		},
	},
} );
