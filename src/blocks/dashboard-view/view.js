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

function proApiFetch( ctx, path, opts = {} ) {
	// Pro REST endpoints live at /wp-json/mvs-pro/v1/ — derive from ctx.restUrl
	// which is /wp-json/mvs/v1/ by replacing the namespace.
	const proUrl = ctx.restUrl.replace( 'mvs/v1/', 'mvs-pro/v1/' );
	opts.credentials = 'same-origin';
	if ( ! opts.headers ) {
		opts.headers = apiHeaders( ctx.nonce );
	}
	return fetch( proUrl + path, opts );
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
			showFields: true,
			title: '',
			description: '',
			tags: '',
			privacy: '',
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
			coverId: 0,
			pickerLoading: false,
			saving: false,
		},
		// Notifications
		notifications: {
			items: [],
			count: 0,
			visible: false,
		},
		// Favorites
		favorites: {
			items: [],
			page: 1,
			totalPages: 1,
			loading: false,
		},
		// Collections
		collections: {
			items: [],
			loading: false,
		},
		// Pro gamification tabs (data populated by lazy-load actions).
		challenges: { items: [], loading: false, activeChallenge: null },
		battles: { items: [], loading: false },
		tournaments: { items: [], loading: false, openTournament: null },
		// Collection modal
		collectionModal: {
			visible: false,
			isEdit: false,
			collectionId: 0,
			title: '',
			description: '',
			collectionType: 'smart',
			rules: [],
			saving: false,
			previewCount: 0,
			previewTimer: null,
			// Lookup data for dropdowns
			tags: [],
			categories: [],
		},
		// Derived state
		get isMediaTab() { return state.activeTab === 'media'; },
		get isAlbumsTab() { return state.activeTab === 'albums'; },
		get isFavoritesTab() { return state.activeTab === 'favorites'; },
		get isCollectionsTab() { return state.activeTab === 'collections'; },
		// Pro gamification tabs (computed here so panels can bind without store extension).
		get isChallengesTab() { return state.activeTab === 'challenges'; },
		get isBattlesTab() { return state.activeTab === 'battles'; },
		get isTournamentsTab() { return state.activeTab === 'tournaments'; },
		// Pro connectors tab.
		get isConnectorsTab() { return state.activeTab === 'connectors'; },
		get hasMoreMedia() { return state.media.page < state.media.totalPages; },
		get hasMoreFavorites() { return state.favorites.page < state.favorites.totalPages; },
		get hasNotifications() { return state.notifications.items.length > 0; },
		get showMediaEmpty() { return state.media.items.length === 0 && ! state.media.loading; },
		get showAlbumsEmpty() { return state.albums.items.length === 0 && ! state.albums.loading; },
		get showFavoritesEmpty() { return state.favorites.items.length === 0 && ! state.favorites.loading; },
		get showCollectionsEmpty() { return state.collections.items.length === 0 && ! state.collections.loading; },
		get showChallengesEmpty() { return state.challenges.items.length === 0 && ! state.challenges.loading; },
		get showBattlesEmpty() { return state.battles.items.length === 0 && ! state.battles.loading; },
		get showTournamentsEmpty() { return state.tournaments.items.length === 0 && ! state.tournaments.loading; },
		get hasActiveChallenge() { return state.challenges.activeChallenge !== null; },
		get hasOpenTournament() { return state.tournaments.openTournament !== null; },
		get mediaThumbUrl() {
			const ctx = getContext();
			const item = ctx.item;
			if ( ! item ) return '';
			if ( item.thumbnail_url ) return item.thumbnail_url;
			if ( item.media_type === 'image' ) return item.file_url;
			return '';
		},
		get showMediaVideoPlaceholder() {
			return ! state.mediaThumbUrl && getContext().item?.media_type === 'video';
		},
		get showMediaAudioPlaceholder() {
			return ! state.mediaThumbUrl && getContext().item?.media_type === 'audio';
		},
		get favThumbUrl() {
			const ctx = getContext();
			const item = ctx.item;
			if ( ! item ) return '';
			if ( item.thumbnail_url ) return item.thumbnail_url;
			if ( item.media_type === 'image' ) return item.file_url;
			return '';
		},
		get showFavVideoPlaceholder() {
			return ! state.favThumbUrl && getContext().item?.media_type === 'video';
		},
		get showFavAudioPlaceholder() {
			return ! state.favThumbUrl && getContext().item?.media_type === 'audio';
		},
		get pickerThumbUrl() {
			const ctx = getContext();
			const item = ctx.item;
			if ( ! item ) return '';
			if ( item.thumbnail_url ) return item.thumbnail_url;
			if ( item.media_type === 'image' ) return item.file_url;
			return '';
		},
		get showPickerVideoPlaceholder() {
			return ! state.pickerThumbUrl && getContext().item?.media_type === 'video';
		},
		get showPickerAudioPlaceholder() {
			return ! state.pickerThumbUrl && getContext().item?.media_type === 'audio';
		},
		get hasAlbumCover() {
			return !! getContext().item?.cover_url;
		},
		get itemTitle() {
			return getContext().item?.title || '(Untitled)';
		},
		get itemPrivacy() {
			return getContext().item?.privacy || 'public';
		},
		get albumItemCount() {
			return ( getContext().item?.media_count || 0 ) + ' items';
		},
		get collectionItemCount() {
			return ( getContext().item?.matchCount || 0 ) + ' items';
		},
		get rulePillText() {
			const rule = getContext().rule;
			return rule ? rule.key + ': ' + rule.value : '';
		},
		get isSmartCollection() {
			return getContext().item?.type === 'smart';
		},
		get collectionCoverUrl() {
			return getContext().item?.cover_url || '';
		},
		get hasCollectionCover() {
			return !! getContext().item?.cover_url;
		},
		get isPickerCover() {
			const ctx = getContext();
			return ctx.item && state.albumModal.coverId === ctx.item.id;
		},
		get isSmartType() {
			return state.collectionModal.collectionType === 'smart';
		},
		get isManualType() {
			return state.collectionModal.collectionType === 'manual';
		},
		get isRuleKeyMediaType() { return getContext().rule?.key === 'media_type'; },
		get isRuleKeyTag() { return getContext().rule?.key === 'tag'; },
		get isRuleKeyCategory() { return getContext().rule?.key === 'category'; },
		get isRuleKeyAuthor() { return getContext().rule?.key === 'author'; },
		get isRuleKeyPrivacy() { return getContext().rule?.key === 'privacy'; },
		get isRuleKeyDateAfter() { return getContext().rule?.key === 'date_after'; },
		get isRuleKeyDateBefore() { return getContext().rule?.key === 'date_before'; },
		get isRuleValueSelected() {
			const ctx = getContext();
			return ctx.opt && ctx.rule && String( ctx.opt.value ) === String( ctx.rule.value );
		},
		get isRuleSelectType() {
			const ctx = getContext();
			const rule = ctx.rule;
			if ( ! rule ) return false;
			return [ 'media_type', 'tag', 'category', 'privacy' ].includes( rule.key );
		},
		get ruleInputType() {
			const ctx = getContext();
			const rule = ctx.rule;
			if ( ! rule ) return 'text';
			if ( rule.key === 'date_after' || rule.key === 'date_before' ) return 'date';
			if ( rule.key === 'author' ) return 'number';
			return 'text';
		},
		get ruleInputPlaceholder() {
			const ctx = getContext();
			const rule = ctx.rule;
			if ( ! rule ) return '';
			if ( rule.key === 'author' ) return 'User ID';
			if ( rule.key === 'date_after' || rule.key === 'date_before' ) return 'YYYY-MM-DD';
			return 'Value';
		},
		get ruleValueOptions() {
			const ctx = getContext();
			const rule = ctx.rule;
			if ( ! rule ) return [];
			if ( rule.key === 'media_type' ) {
				return [
					{ value: '', label: '-- Select --' },
					{ value: 'image', label: 'Image' },
					{ value: 'video', label: 'Video' },
					{ value: 'audio', label: 'Audio' },
					{ value: 'document', label: 'Document' },
				];
			}
			if ( rule.key === 'privacy' ) {
				return [
					{ value: '', label: '-- Select --' },
					{ value: 'public', label: 'Public' },
					{ value: 'members', label: 'Members' },
					{ value: 'private', label: 'Private' },
				];
			}
			if ( rule.key === 'tag' ) {
				return [ { value: '', label: '-- Select --' }, ...state.collectionModal.tags ];
			}
			if ( rule.key === 'category' ) {
				return [ { value: '', label: '-- Select --' }, ...state.collectionModal.categories ];
			}
			return [];
		},
	},
	actions: {
		/* =====================================================================
		   Tabs
		   ===================================================================== */
		switchTab( event ) {
			const tab = event.target.closest( '[data-tab]' )?.dataset.tab;
			if ( ! tab ) return;
			state.activeTab = tab;
			window.location.hash = tab;
			const ctx = getContext();
			if ( tab === 'media' && state.media.items.length === 0 ) {
				actions.loadMedia( ctx );
			} else if ( tab === 'albums' && state.albums.items.length === 0 ) {
				actions.loadAlbums( ctx );
			} else if ( tab === 'favorites' && state.favorites.items.length === 0 ) {
				actions.loadFavorites( ctx );
			} else if ( tab === 'collections' && state.collections.items.length === 0 ) {
				actions.loadCollections( ctx );
			} else if ( tab === 'challenges' && state.challenges.items.length === 0 ) {
				actions.loadChallenges( ctx );
			} else if ( tab === 'battles' && state.battles.items.length === 0 ) {
				actions.loadBattles( ctx );
			} else if ( tab === 'tournaments' && state.tournaments.items.length === 0 ) {
				actions.loadTournaments( ctx );
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

			// Client-side file type filter.
			if ( ctx.allowedExtensions ) {
				const exts = ctx.allowedExtensions.split( ',' ).map( ( e ) => e.trim().toLowerCase() );
				const rejected = [];
				const accepted = [];
				for ( const f of files ) {
					const ext = '.' + f.name.split( '.' ).pop().toLowerCase();
					if ( exts.some( ( allowed ) => allowed.includes( ext ) ) ) {
						accepted.push( f );
					} else {
						rejected.push( f.name );
					}
				}
				if ( rejected.length ) {
					sharedUI.actions.showToast(
						'File type not allowed: ' + rejected.join( ', ' ) + '. Supported: ' + ctx.allowedExtensions,
						'error'
					);
				}
				if ( ! accepted.length ) return;
				files = accepted;
			}

			state.upload.uploading = true;
			const total = files.length;
			let uploaded = 0;
			let lastError = '';

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
					const res = await fetch( ctx.restUrl + 'media', {
						method: 'POST',
						headers: { 'X-WP-Nonce': ctx.nonce },
						credentials: 'same-origin',
						body: formData,
					} );
					if ( res.ok ) {
						uploaded++;
					} else {
						try {
							const errData = await res.json();
							lastError = errData.message || 'Upload failed.';
						} catch { /* ignore parse error */ }
					}
				} catch {
					// Continue with remaining.
				}
			}

			state.upload.uploading = false;
			state.upload.status = '';
			if ( uploaded === 0 ) {
				sharedUI.actions.showToast( lastError || 'Upload failed. Please try again.', 'error' );
			} else if ( uploaded < total ) {
				sharedUI.actions.showToast( uploaded + ' of ' + total + ' file(s) uploaded.', 'error' );
			} else {
				sharedUI.actions.showToast( total + ' file(s) uploaded!', 'success' );
			}
			if ( uploaded > 0 ) {
				// Reset upload form so old data doesn't carry forward.
				state.upload.title = '';
				state.upload.description = '';
				state.upload.tags = '';
				state.upload.privacy = ctx.defaultPrivacy || 'public';
				state.upload.showFields = false;
				state.media.page = 1;
				actions.loadMedia( ctx, 1 );
			}
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

		openMediaLightbox( event ) {
			event.preventDefault();
			const item = getContext().item;
			if ( ! item?.id ) return;
			const ctx = getContext();
			// Delegate to the shared-ui lightbox.
			const sharedState = sharedUI.state;
			sharedState.lightboxVisible = true;
			sharedState.lightboxMediaId = item.id;
			sharedState.lightboxLoading = true;
			sharedState.lightboxCommentText = '';
			sharedState.lightboxGroupItems = [];
			sharedState.lightboxCurrentIndex = 0;
			document.body.style.overflow = 'hidden';
			// Fetch media data via shared-ui openLightbox pattern.
			fetch( ctx.restUrl + 'media/' + item.id, {
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': ctx.nonce },
			} ).then( ( r ) => r.json() ).then( ( data ) => {
				sharedState.lightboxMediaData = data;
				sharedState.lightboxLoading = false;
				// Load social data.
				sharedUI.actions.lightboxLoadSocial( ctx, item.id, { 'X-WP-Nonce': ctx.nonce } );
			} ).catch( () => {
				sharedState.lightboxLoading = false;
			} );
		},
		openFavLightbox( event ) {
			event.preventDefault();
			const item = getContext().item;
			if ( ! item?.media_id ) return;
			const ctx = getContext();
			const sharedState = sharedUI.state;
			sharedState.lightboxVisible = true;
			sharedState.lightboxMediaId = item.media_id;
			sharedState.lightboxLoading = true;
			sharedState.lightboxCommentText = '';
			sharedState.lightboxGroupItems = [];
			sharedState.lightboxCurrentIndex = 0;
			document.body.style.overflow = 'hidden';
			fetch( ctx.restUrl + 'media/' + item.media_id, {
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': ctx.nonce },
			} ).then( ( r ) => r.json() ).then( ( data ) => {
				sharedState.lightboxMediaData = data;
				sharedState.lightboxLoading = false;
				sharedUI.actions.lightboxLoadSocial( ctx, item.media_id, { 'X-WP-Nonce': ctx.nonce } );
			} ).catch( () => {
				sharedState.lightboxLoading = false;
			} );
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

		handleReplaceFile( event ) {
			const file = event.target.files[ 0 ];
			if ( ! file ) return;
			event.target.value = '';
			actions.replaceMediaFile( file );
		},

		async replaceMediaFile( file ) {
			const ctx = getContext();
			const mediaId = state.editModal.itemId;
			if ( ! mediaId ) return;

			state.editModal.saving = true;
			const formData = new FormData();
			formData.append( 'file', file );

			try {
				const res = await fetch( ctx.restUrl + 'media/' + mediaId + '/replace', {
					method: 'POST',
					headers: { 'X-WP-Nonce': ctx.nonce },
					credentials: 'same-origin',
					body: formData,
				} );
				if ( res.ok ) {
					const updated = await res.json();
					const idx = state.media.items.findIndex( ( m ) => m.id === mediaId );
					if ( idx !== -1 ) {
						state.media.items[ idx ] = { ...state.media.items[ idx ], ...updated };
					}
					sharedUI.actions.showToast( 'File replaced!', 'success' );
				} else {
					const err = await res.json().catch( () => ( {} ) );
					sharedUI.actions.showToast( err.message || 'Replace failed.', 'error' );
				}
			} catch {
				sharedUI.actions.showToast( 'Replace failed.', 'error' );
			}
			state.editModal.saving = false;
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

		toggleProfileEdit() {
			const ctx = getContext();
			ctx.editingProfile = ! ctx.editingProfile;
			ctx.profileMessage = '';
			ctx.profileError = '';
		},

		/* =====================================================================
		   Delete Media
		   ===================================================================== */
		confirmDeleteMedia() {
			const ctx = getContext();
			const id = ctx.item?.id;
			if ( ! id ) return;
			sharedUI.actions.showConfirm( 'Delete this media item? This cannot be undone.', async () => {
				try {
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
				} catch {
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
			state.albumModal.originalItems = album?.items ? [ ...album.items ] : [];
			state.albumModal.coverId = album?.cover_media_id || 0;
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
			state.albumModal.coverId = 0;
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
			// Don't toggle selection when clicking "Set Cover" button.
			if ( event.target.closest( '.mvs-media-picker-cover-btn' ) ) return;
			const id = parseInt( event.target.closest( '[data-picker-id]' )?.dataset.pickerId, 10 );
			if ( ! id ) return;
			if ( state.albumModal.selectedIds.includes( id ) ) {
				state.albumModal.selectedIds = state.albumModal.selectedIds.filter( ( sid ) => sid !== id );
			} else {
				state.albumModal.selectedIds = [ ...state.albumModal.selectedIds, id ];
			}
		},

		setCoverItem( event ) {
			event.stopPropagation();
			const id = parseInt( event.target.closest( '[data-picker-id]' )?.dataset.pickerId, 10 );
			if ( ! id ) return;
			state.albumModal.coverId = state.albumModal.coverId === id ? 0 : id;
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
				let albumId = state.albumModal.albumId;
				if ( state.albumModal.isEdit ) {
					await apiFetch( ctx, 'albums/' + albumId, {
						method: 'PUT',
						headers: apiHeaders( ctx.nonce ),
						body: JSON.stringify( payload ),
					} );
					// Sync album items: add new, remove deselected.
					const currentItems = state.albumModal.originalItems || [];
					const selected = state.albumModal.selectedIds;
					const toAdd = selected.filter( ( id ) => ! currentItems.includes( id ) );
					const toRemove = currentItems.filter( ( id ) => ! selected.includes( id ) );
					if ( toAdd.length ) {
						await apiFetch( ctx, 'albums/' + albumId + '/items', {
							method: 'POST',
							headers: apiHeaders( ctx.nonce ),
							body: JSON.stringify( { media_ids: toAdd } ),
						} );
					}
					for ( const removeId of toRemove ) {
						await apiFetch( ctx, 'albums/' + albumId + '/items/' + removeId, {
							method: 'DELETE',
							headers: apiHeaders( ctx.nonce ),
						} );
					}
				} else {
					const res = await apiFetch( ctx, 'albums', {
						method: 'POST',
						headers: apiHeaders( ctx.nonce ),
						body: JSON.stringify( payload ),
					} );
					const created = await res.json();
					albumId = created.id;
					if ( state.albumModal.selectedIds.length && albumId ) {
						await apiFetch( ctx, 'albums/' + albumId + '/items', {
							method: 'POST',
							headers: apiHeaders( ctx.nonce ),
							body: JSON.stringify( { media_ids: state.albumModal.selectedIds } ),
						} );
					}
				}
				// Set cover if selected.
				if ( state.albumModal.coverId && albumId ) {
					await apiFetch( ctx, 'albums/' + albumId + '/cover', {
						method: 'PUT',
						headers: apiHeaders( ctx.nonce ),
						body: JSON.stringify( { media_id: state.albumModal.coverId } ),
					} );
				}
				state.albumModal.visible = false;
				state.albumModal.saving = false;
				sharedUI.actions.showToast( state.albumModal.isEdit ? 'Album updated!' : 'Album created!', 'success' );
				await actions.loadAlbums( ctx );
			} catch {
				state.albumModal.saving = false;
				sharedUI.actions.showToast( 'Save failed.', 'error' );
			}
		},

		confirmDeleteAlbum() {
			const ctx = getContext();
			const id = ctx.item?.id;
			if ( ! id ) return;
			sharedUI.actions.showConfirm( 'Delete this album? Media items will not be deleted.', async () => {
				try {
					const res = await apiFetch( ctx, 'albums/' + id, {
						method: 'DELETE',
						headers: apiHeaders( ctx.nonce ),
					} );
					if ( res.ok ) {
						state.albums.items = state.albums.items.filter( ( a ) => a.id !== id );
						sharedUI.actions.showToast( 'Album deleted.', 'success' );
					} else {
						sharedUI.actions.showToast( 'Delete failed.', 'error' );
					}
				} catch {
					sharedUI.actions.showToast( 'Delete failed.', 'error' );
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
		   Collections
		   ===================================================================== */
		async loadCollections( ctxOrEvent ) {
			const ctx = typeof ctxOrEvent?.restUrl === 'string' ? ctxOrEvent : getContext();
			state.collections.loading = true;
			try {
				const res = await apiFetch( ctx, 'collections' );
				const data = await res.json();
				// Enrich with match counts for smart collections.
				for ( const item of data ) {
					item.link = '/?p=' + item.id;
					if ( item.type === 'smart' ) {
						try {
							const detail = await apiFetch( ctx, 'collections/' + item.id + '?per_page=1' );
							const dData = await detail.json();
							item.matchCount = dData.total || 0;
						} catch {
							item.matchCount = 0;
						}
					} else {
						item.matchCount = item.favorites ? item.favorites.length : 0;
					}
				}
				state.collections.items = data;
			} catch {
				// Ignore.
			}
			state.collections.loading = false;
		},

		/* =====================================================================
		   Pro Gamification – Challenges / Battles / Tournaments
		   ===================================================================== */
		async loadChallenges( ctxOrEvent ) {
			const ctx = typeof ctxOrEvent?.restUrl === 'string' ? ctxOrEvent : getContext();
			if ( ! ctx.userId ) return;
			state.challenges.loading = true;
			try {
				const res = await proApiFetch( ctx, 'challenges?participant=' + ctx.userId + '&per_page=20' );
				state.challenges.items = await res.json();
				const activeRes = await proApiFetch( ctx, 'challenges?status=active&per_page=1' );
				const activeData = await activeRes.json();
				state.challenges.activeChallenge = activeData.length > 0 ? activeData[ 0 ] : null;
			} catch {
				state.challenges.items = [];
			}
			state.challenges.loading = false;
		},

		async loadBattles( ctxOrEvent ) {
			const ctx = typeof ctxOrEvent?.restUrl === 'string' ? ctxOrEvent : getContext();
			if ( ! ctx.userId ) return;
			state.battles.loading = true;
			try {
				const res = await proApiFetch( ctx, 'battles?participant=' + ctx.userId + '&per_page=20' );
				state.battles.items = await res.json();
			} catch {
				state.battles.items = [];
			}
			state.battles.loading = false;
		},

		async loadTournaments( ctxOrEvent ) {
			const ctx = typeof ctxOrEvent?.restUrl === 'string' ? ctxOrEvent : getContext();
			if ( ! ctx.userId ) return;
			state.tournaments.loading = true;
			try {
				const res = await proApiFetch( ctx, 'tournaments?participant=' + ctx.userId + '&per_page=20' );
				state.tournaments.items = await res.json();
				const openRes = await proApiFetch( ctx, 'tournaments?status=registration&per_page=1' );
				const openData = await openRes.json();
				state.tournaments.openTournament = openData.length > 0 ? openData[ 0 ] : null;
			} catch {
				state.tournaments.items = [];
			}
			state.tournaments.loading = false;
		},

		openCreateCollection() {
			state.collectionModal.visible = true;
			state.collectionModal.isEdit = false;
			state.collectionModal.collectionId = 0;
			state.collectionModal.title = '';
			state.collectionModal.description = '';
			state.collectionModal.collectionType = 'smart';
			state.collectionModal.rules = [ { key: '', value: '', index: 0 } ];
			state.collectionModal.saving = false;
			state.collectionModal.previewCount = 0;
			actions.loadRuleDropdownData();
		},

		openEditCollection( event ) {
			const id = parseInt( event.target.closest( '[data-collection-id]' )?.dataset.collectionId, 10 );
			const item = state.collections.items.find( ( c ) => c.id === id );
			if ( ! item ) return;

			state.collectionModal.visible = true;
			state.collectionModal.isEdit = true;
			state.collectionModal.collectionId = id;
			state.collectionModal.title = item.title || '';
			state.collectionModal.description = item.description || '';
			state.collectionModal.collectionType = item.type || 'manual';
			state.collectionModal.rules = ( item.rules || [] ).map( ( r, i ) => ( { ...r, index: i } ) );
			if ( state.collectionModal.rules.length === 0 && item.type === 'smart' ) {
				state.collectionModal.rules = [ { key: '', value: '', index: 0 } ];
			}
			state.collectionModal.saving = false;
			state.collectionModal.previewCount = item.matchCount || 0;
			actions.loadRuleDropdownData();
		},

		closeCollectionModal() {
			state.collectionModal.visible = false;
		},

		setCollectionTitle( event ) { state.collectionModal.title = event.target.value; },
		setCollectionDesc( event ) { state.collectionModal.description = event.target.value; },
		setCollectionTypeManual() { state.collectionModal.collectionType = 'manual'; },
		setCollectionTypeSmart() { state.collectionModal.collectionType = 'smart'; },

		async loadRuleDropdownData() {
			const ctx = getContext();
			// Load tags.
			try {
				const res = await apiFetch( ctx, 'tags?per_page=100' );
				const data = await res.json();
				state.collectionModal.tags = data.map( ( t ) => ( { value: String( t.id ), label: t.name } ) );
			} catch {
				state.collectionModal.tags = [];
			}
			// Load categories.
			try {
				const catRes = await fetch( ctx.restUrl.replace( 'mvs/v1/', '' ) + 'wp/v2/mvs_category?per_page=100', {
					credentials: 'same-origin',
					headers: apiHeaders( ctx.nonce ),
				} );
				const catData = await catRes.json();
				state.collectionModal.categories = catData.map( ( c ) => ( { value: String( c.id ), label: c.name } ) );
			} catch {
				state.collectionModal.categories = [];
			}
		},

		addRule() {
			const nextIndex = state.collectionModal.rules.length;
			state.collectionModal.rules = [
				...state.collectionModal.rules,
				{ key: '', value: '', index: nextIndex },
			];
		},

		removeRule( event ) {
			const idx = parseInt( event.target.closest( '[data-rule-index]' )?.dataset.ruleIndex, 10 );
			state.collectionModal.rules = state.collectionModal.rules
				.filter( ( r ) => r.index !== idx )
				.map( ( r, i ) => ( { ...r, index: i } ) );
			actions.previewRules();
		},

		setRuleKey( event ) {
			const idx = parseInt( event.target.closest( '[data-rule-index]' )?.dataset.ruleIndex, 10 );
			const rules = [ ...state.collectionModal.rules ];
			const rule = rules.find( ( r ) => r.index === idx );
			if ( rule ) {
				rule.key = event.target.value;
				rule.value = '';
				state.collectionModal.rules = rules;
			}
			actions.previewRules();
		},

		setRuleValue( event ) {
			const idx = parseInt( event.target.closest( '[data-rule-index]' )?.dataset.ruleIndex, 10 );
			const rules = [ ...state.collectionModal.rules ];
			const rule = rules.find( ( r ) => r.index === idx );
			if ( rule ) {
				rule.value = event.target.value;
				state.collectionModal.rules = rules;
			}
			actions.previewRules();
		},

		async previewRules() {
			// Live preview only works for existing collections (edit mode).
			// For new collections, the count appears after save.
			if ( ! state.collectionModal.isEdit || ! state.collectionModal.collectionId ) {
				state.collectionModal.previewCount = 0;
				return;
			}
			const ctx = getContext();
			const validRules = state.collectionModal.rules.filter( ( r ) => r.key && r.value );
			if ( ! validRules.length ) {
				state.collectionModal.previewCount = 0;
				return;
			}
			// Debounce.
			if ( state.collectionModal.previewTimer ) {
				clearTimeout( state.collectionModal.previewTimer );
			}
			state.collectionModal.previewTimer = setTimeout( async () => {
				try {
					await apiFetch( ctx, 'collections/' + state.collectionModal.collectionId + '/rules', {
						method: 'PUT',
						headers: apiHeaders( ctx.nonce ),
						body: JSON.stringify( { rules: validRules.map( ( { key, value } ) => ( { key, value } ) ) } ),
					} );
					const res = await apiFetch( ctx, 'collections/' + state.collectionModal.collectionId + '?per_page=1' );
					const data = await res.json();
					state.collectionModal.previewCount = data.total || 0;
				} catch {
					// Ignore preview errors.
				}
			}, 800 );
		},

		async saveCollection() {
			const ctx = getContext();
			state.collectionModal.saving = true;

			const payload = {
				title: state.collectionModal.title,
				description: state.collectionModal.description,
			};

			const validRules = state.collectionModal.rules
				.filter( ( r ) => r.key && r.value )
				.map( ( { key, value } ) => ( { key, value } ) );

			if ( state.collectionModal.collectionType === 'smart' && validRules.length ) {
				payload.rules = validRules;
			}

			try {
				if ( state.collectionModal.isEdit ) {
					await apiFetch( ctx, 'collections/' + state.collectionModal.collectionId, {
						method: 'PUT',
						headers: apiHeaders( ctx.nonce ),
						body: JSON.stringify( payload ),
					} );
					// Update rules separately if smart.
					if ( state.collectionModal.collectionType === 'smart' && validRules.length ) {
						await apiFetch( ctx, 'collections/' + state.collectionModal.collectionId + '/rules', {
							method: 'PUT',
							headers: apiHeaders( ctx.nonce ),
							body: JSON.stringify( { rules: validRules } ),
						} );
					}
					sharedUI.actions.showToast( 'Collection updated!', 'success' );
				} else {
					await apiFetch( ctx, 'collections', {
						method: 'POST',
						headers: apiHeaders( ctx.nonce ),
						body: JSON.stringify( payload ),
					} );
					sharedUI.actions.showToast( 'Collection created!', 'success' );
				}
				state.collectionModal.visible = false;
				state.collectionModal.saving = false;
				await actions.loadCollections( ctx );
			} catch {
				state.collectionModal.saving = false;
				sharedUI.actions.showToast( 'Save failed.', 'error' );
			}
		},

		confirmDeleteCollection() {
			const ctx = getContext();
			const id = ctx.item?.id;
			if ( ! id ) return;
			sharedUI.actions.showConfirm( 'Delete this collection? Media items will not be deleted.', async () => {
				try {
					const res = await apiFetch( ctx, 'collections/' + id, {
						method: 'DELETE',
						headers: apiHeaders( ctx.nonce ),
					} );
					if ( res.ok ) {
						state.collections.items = state.collections.items.filter( ( c ) => c.id !== id );
						sharedUI.actions.showToast( 'Collection deleted.', 'success' );
					} else {
						sharedUI.actions.showToast( 'Delete failed.', 'error' );
					}
				} catch {
					sharedUI.actions.showToast( 'Delete failed.', 'error' );
				}
			} );
		},

		/* =====================================================================
		   Notifications
		   ===================================================================== */
		async toggleNotifications( event ) {
			event.stopPropagation();
			const ctx = getContext();
			state.notifications.visible = ! state.notifications.visible;
			if ( state.notifications.visible && state.notifications.items.length === 0 ) {
				try {
					const res = await apiFetch( ctx, 'me/notifications?per_page=20' );
					const data = await res.json();
					state.notifications.items = Array.isArray( data )
						? data.map( ( n ) => ( {
							id: n.id,
							message: n.message || '',
							url: n.url || '',
							date: new Date( n.created_at ).toLocaleDateString(),
							read: !! n.is_read,
						} ) )
						: [];
				} catch {
					state.notifications.items = [];
				}
			}
		},

		async markAllRead( event ) {
			event.stopPropagation();
			const ctx = getContext();
			try {
				await apiFetch( ctx, 'me/notifications/read', {
					method: 'POST',
					headers: apiHeaders( ctx.nonce ),
				} );
				state.notifications.count = 0;
				state.notifications.items = state.notifications.items.map( ( n ) => ( { ...n, read: true } ) );
				sharedUI.actions.showToast( 'All notifications marked as read.', 'success' );
			} catch {
				// Ignore.
			}
		},

		async loadNotificationCount( ctx ) {
			try {
				const res = await apiFetch( ctx, 'me/notifications/count' );
				const data = await res.json();
				state.notifications.count = data.count || 0;
			} catch {
				// Ignore.
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
				state.collectionModal.visible = false;
			}
		},
	},
	callbacks: {
		init() {
			const ctx = getContext();
			// Apply admin default privacy to upload state.
			if ( ctx.defaultPrivacy ) {
				state.upload.privacy = ctx.defaultPrivacy;
			}
			const validTabs = [ 'media', 'albums', 'favorites', 'collections', 'challenges', 'battles', 'tournaments' ];
			const hashTab = window.location.hash.replace( '#', '' );
			if ( hashTab && validTabs.includes( hashTab ) ) {
				state.activeTab = hashTab;
			}
			// Load the active tab's data.
			if ( state.activeTab === 'albums' ) {
				actions.loadAlbums( ctx );
			} else if ( state.activeTab === 'favorites' ) {
				actions.loadFavorites( ctx );
			} else if ( state.activeTab === 'collections' ) {
				actions.loadCollections( ctx );
			} else if ( state.activeTab === 'challenges' ) {
				actions.loadChallenges( ctx );
			} else if ( state.activeTab === 'battles' ) {
				actions.loadBattles( ctx );
			} else if ( state.activeTab === 'tournaments' ) {
				actions.loadTournaments( ctx );
			} else {
				actions.loadMedia( ctx );
			}
			actions.loadNotificationCount( ctx );
		},
	},
} );
