/**
 * Interactivity API store: user dashboard (My Media, My Albums, My Favorites).
 *
 * Replaces assets/js/mvs-dashboard.js (1,075 lines).
 *
 * @package WPMediaVerse
 */

import { store, getContext } from '@wordpress/interactivity';

/**
 * Grab a poster frame from a video File and append it as `thumbnail`.
 *
 * See the twin in src/blocks/media-upload/view.js for the full rationale: a
 * video uploaded on a host with no ffmpeg and no embedded cover atom gets no
 * poster at all and renders as a blank tile. These view bundles are script
 * MODULES, so a cross-file import is emitted as a bare specifier the browser
 * 404s on — hence the deliberate copy. Keep the two in sync.
 *
 * @param {FormData} formData Upload payload, mutated in place.
 * @param {File}     file     File being uploaded.
 * @return {Promise<void>} Resolves whether or not a poster was attached.
 */
async function appendVideoPoster( formData, file ) {
	if ( ! file || ! file.type || ! file.type.startsWith( 'video/' ) ) {
		return;
	}
	const blob = await new Promise( ( resolve ) => {
		const video = document.createElement( 'video' );
		const url = URL.createObjectURL( file );
		let settled = false;
		const finish = ( result ) => {
			if ( settled ) return;
			settled = true;
			URL.revokeObjectURL( url );
			resolve( result );
		};
		const timer = setTimeout( () => finish( null ), 5000 );
		video.preload = 'metadata';
		video.muted = true;
		video.playsInline = true;
		video.addEventListener( 'loadeddata', () => {
			video.currentTime = video.duration && video.duration < 1 ? 0 : 1;
		} );
		video.addEventListener( 'seeked', () => {
			clearTimeout( timer );
			try {
				const canvas = document.createElement( 'canvas' );
				canvas.width = video.videoWidth || 320;
				canvas.height = video.videoHeight || 180;
				canvas.getContext( '2d' ).drawImage( video, 0, 0, canvas.width, canvas.height );
				canvas.toBlob( ( b ) => finish( b ), 'image/jpeg', 0.7 );
			} catch {
				finish( null );
			}
		} );
		video.addEventListener( 'error', () => {
			clearTimeout( timer );
			finish( null );
		} );
		video.src = url;
	} );
	if ( blob ) {
		formData.append( 'thumbnail', blob, 'video-thumb.jpg' );
	}
}

// i18n: this is a script MODULE, so window.wp.i18n.__() is English-locked here.
// PHP (dashboard-content.php) seeds translated strings into interactivity state;
// read them as `state.i18n.<key>` with an English fallback. Basecamp 10073528834.
const sharedUI = store( 'mvs/shared-ui' );

function apiFetch( ctx, path, opts = {} ) {
	if ( opts.body && typeof opts.body === 'string' ) {
		try { opts.body = JSON.parse( opts.body ); } catch { /* leave as-is */ }
	}
	// Strip headers key — window.mvsRest.restFetch handles nonce + Content-Type.
	delete opts.headers;
	return window.mvsRest.restFetch( ctx.restUrl + path, opts );
}

function proApiFetch( ctx, path, opts = {} ) {
	// Pro REST endpoints live at /wp-json/mvs-pro/v1/ — derive from ctx.restUrl
	// which is /wp-json/mvs/v1/ by replacing the namespace.
	const proUrl = ctx.restUrl.replace( 'mvs/v1/', 'mvs-pro/v1/' );
	if ( opts.body && typeof opts.body === 'string' ) {
		try { opts.body = JSON.parse( opts.body ); } catch { /* leave as-is */ }
	}
	// Strip headers key — window.mvsRest.restFetch handles nonce + Content-Type.
	delete opts.headers;
	return window.mvsRest.restFetch( proUrl + path, opts );
}

const { state, actions } = store( 'mvs/dashboard', {
	state: {
		// NOT defaulted here. The client state literal is applied ON TOP of the
		// server's, so a default in this file silently overwrote
		// wp_interactivity_state()'s value and /my-media/documents/ always
		// opened on Media. The server seeds it; the getters below fall back.

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
			pendingFiles: [],
			pendingCount: 0,
			hasPending: false,
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
		get editModalSaveDisabled() {
			// Runbook contract C.member.lightbox-edit-modal: "save disabled
			// while title empty". A disabled Save is the feedback — pairing it
			// with the inline hint below the field so it is not a dead button
			// with no explanation.
			return state.editModal.saving
				|| '' === String( state.editModal.title || '' ).trim();
		},
		get editModalTitleMissing() {
			return '' === String( state.editModal.title || '' ).trim();
		},
		get isMediaTab() { return ( state.activeTab || 'media' ) === 'media'; },
		get isAlbumsTab() { return ( state.activeTab || 'media' ) === 'albums'; },
		get isFavoritesTab() { return ( state.activeTab || 'media' ) === 'favorites'; },
		get isCollectionsTab() { return ( state.activeTab || 'media' ) === 'collections'; },
		// Documents: a server-rendered panel, so the store only owns which tab
		// is showing. The drive inside it is plain HTML — no client state to
		// keep in step with it.
		get isDocumentsTab() { return ( state.activeTab || 'media' ) === 'documents'; },
		// Pro gamification tabs (computed here so panels can bind without store extension).
		get isChallengesTab() { return ( state.activeTab || 'media' ) === 'challenges'; },
		get isBattlesTab() { return ( state.activeTab || 'media' ) === 'battles'; },
		get isTournamentsTab() { return ( state.activeTab || 'media' ) === 'tournaments'; },
		// Pro connectors tab.
		get isConnectorsTab() { return ( state.activeTab || 'media' ) === 'connectors'; },
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
		// Canonical media thumbnail state — mirrors PHP TemplateHelpers::media_thumbnail()
		// priority: thumb > video preview > dark placeholder > audio placeholder.
		// Three parallel triplets (media, fav, picker) because dashboard has
		// three independent list contexts; keep the shape consistent so the
		// template bindings stay symmetrical.
		get mediaThumbUrl() {
			const item = getContext().item;
			if ( ! item ) return '';
			if ( item.thumbnail_url ) return item.thumbnail_url;
			if ( item.media_type === 'image' ) return item.file_url;
			return '';
		},
		get mediaVideoPreviewUrl() {
			// Mirror PHP TemplateHelpers::media_thumbnail(): a streamable video
			// ALWAYS renders as a <video> first-frame preview (with mediaThumbUrl
			// as the <video poster> fallback). This makes a posterless upload show
			// its real first frame instead of the bundled default-poster image
			// REST now supplies as thumbnail_url. Do NOT gate on mediaThumbUrl —
			// that gate was what made My Media diverge from Explore.
			const item = getContext().item;
			if ( ! item || item.media_type !== 'video' ) return '';
			return item.file_url ? item.file_url : '';
		},
		get showMediaVideoPreview() {
			return !! state.mediaVideoPreviewUrl;
		},
		get showMediaImage() {
			// Static <img> path is image-only. Videos render via <video> (or the
			// dark placeholder when no streamable file); audio uses its own card.
			const item = getContext().item;
			if ( ! item || item.media_type === 'video' ) return false;
			return !! state.mediaThumbUrl;
		},
		get showMediaVideoPlaceholder() {
			// Video with no streamable file_url (e.g. access-rule locked).
			const item = getContext().item;
			if ( ! item || item.media_type !== 'video' ) return false;
			return ! state.showMediaVideoPreview;
		},
		get showMediaAudioPlaceholder() {
			return ! state.mediaThumbUrl && getContext().item?.media_type === 'audio';
		},
		get showMediaPlayIcon() {
			// Play overlay sits on the streamable <video> preview. The dark
			// placeholder branch carries its own play icon markup.
			return state.showMediaVideoPreview;
		},
		get favThumbUrl() {
			const item = getContext().item;
			if ( ! item ) return '';
			if ( item.thumbnail_url ) return item.thumbnail_url;
			if ( item.media_type === 'image' ) return item.file_url;
			return '';
		},
		get favVideoPreviewUrl() {
			// See mediaVideoPreviewUrl — a streamable video always previews.
			const item = getContext().item;
			if ( ! item || item.media_type !== 'video' ) return '';
			return item.file_url ? item.file_url : '';
		},
		get showFavVideoPreview() {
			return !! state.favVideoPreviewUrl;
		},
		get showFavImage() {
			const item = getContext().item;
			if ( ! item || item.media_type === 'video' ) return false;
			return !! state.favThumbUrl;
		},
		get showFavVideoPlaceholder() {
			const item = getContext().item;
			if ( ! item || item.media_type !== 'video' ) return false;
			return ! state.showFavVideoPreview;
		},
		get showFavAudioPlaceholder() {
			return ! state.favThumbUrl && getContext().item?.media_type === 'audio';
		},
		get showFavPlayIcon() {
			return state.showFavVideoPreview;
		},
		get pickerThumbUrl() {
			const item = getContext().item;
			if ( ! item ) return '';
			if ( item.thumbnail_url ) return item.thumbnail_url;
			if ( item.media_type === 'image' ) return item.file_url;
			return '';
		},
		get pickerVideoPreviewUrl() {
			// See mediaVideoPreviewUrl — a streamable video always previews.
			const item = getContext().item;
			if ( ! item || item.media_type !== 'video' ) return '';
			return item.file_url ? item.file_url : '';
		},
		get showPickerVideoPreview() {
			return !! state.pickerVideoPreviewUrl;
		},
		get showPickerImage() {
			const item = getContext().item;
			if ( ! item || item.media_type === 'video' ) return false;
			return !! state.pickerThumbUrl;
		},
		get showPickerVideoPlaceholder() {
			const item = getContext().item;
			if ( ! item || item.media_type !== 'video' ) return false;
			return ! state.showPickerVideoPreview;
		},
		get showPickerAudioPlaceholder() {
			return ! state.pickerThumbUrl && getContext().item?.media_type === 'audio';
		},
		get showPickerPlayIcon() {
			return state.showPickerVideoPreview;
		},
		get hasAlbumCover() {
			return !! getContext().item?.cover_url;
		},
		get itemTitle() {
			return getContext().item?.title || ( state.i18n?.untitled || '(Untitled)' );
		},
		get itemPrivacy() {
			return getContext().item?.privacy || 'public';
		},
		get albumItemCount() {
			return ( state.i18n?.itemsCount || '%d items' ).replace( '%d', getContext().item?.media_count || 0 );
		},
		get collectionItemCount() {
			return ( state.i18n?.itemsCount || '%d items' ).replace( '%d', getContext().item?.matchCount || 0 );
		},
		get rulePillText() {
			const rule = getContext().rule;
			// Prefer the REST-provided label (term/user name); value holds the raw ID.
			return rule ? rule.key + ': ' + ( rule.label || rule.value ) : '';
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
			if ( rule.key === 'author' ) return ( state.i18n?.ruleUserIdPlaceholder || 'User ID' );
			if ( rule.key === 'date_after' || rule.key === 'date_before' ) return ( state.i18n?.ruleDatePlaceholder || 'YYYY-MM-DD' );
			return ( state.i18n?.ruleValuePlaceholder || 'Value' );
		},
		get ruleValueOptions() {
			const ctx = getContext();
			const rule = ctx.rule;
			if ( ! rule ) return [];
			if ( rule.key === 'media_type' ) {
				return [
					{ value: '', label: ( state.i18n?.selectOption || '-- Select --' ) },
					{ value: 'image', label: ( state.i18n?.optImage || 'Image' ) },
					{ value: 'video', label: ( state.i18n?.optVideo || 'Video' ) },
					{ value: 'audio', label: ( state.i18n?.optAudio || 'Audio' ) },
					{ value: 'document', label: ( state.i18n?.optDocument || 'Document' ) },
				];
			}
			if ( rule.key === 'privacy' ) {
				return [
					{ value: '', label: ( state.i18n?.selectOption || '-- Select --' ) },
					{ value: 'public', label: ( state.i18n?.optPublic || 'Public' ) },
					{ value: 'members', label: ( state.i18n?.optMembers || 'Members' ) },
					{ value: 'private', label: ( state.i18n?.optPrivate || 'Private' ) },
				];
			}
			if ( rule.key === 'tag' ) {
				return [ { value: '', label: ( state.i18n?.selectOption || '-- Select --' ) }, ...state.collectionModal.tags ];
			}
			if ( rule.key === 'category' ) {
				return [ { value: '', label: ( state.i18n?.selectOption || '-- Select --' ) }, ...state.collectionModal.categories ];
			}
			return [];
		},
	},
	actions: {
		/* =====================================================================
		   Tabs
		   ===================================================================== */
		switchTab( event ) {
			const tabBtn = event.target.closest( '[data-tab]' );
			const tab = tabBtn?.dataset.tab;
			if ( ! tab ) return;
			state.activeTab = tab;
			window.location.hash = tab;
			// Mobile §5.2: scroll the tapped tab into view so users can see what's
			// next when the strip overflows. Center inline keeps neighbours visible.
			if ( tabBtn && typeof tabBtn.scrollIntoView === 'function' ) {
				tabBtn.scrollIntoView( { inline: 'center', block: 'nearest', behavior: 'smooth' } );
			}
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
			if ( files.length ) actions.stageUploadFiles( files );
		},

		handleUploadFileSelect( event ) {
			const files = Array.from( event.target.files );
			if ( files.length ) actions.stageUploadFiles( files );
			event.target.value = '';
		},

		// Hold the selected files and reveal the details step instead of
		// uploading immediately, so the user can fill in title/description/tags/
		// privacy before the upload starts (matches the media-upload block).
		stageUploadFiles( files ) {
			state.upload.pendingFiles = files;
			state.upload.pendingCount = files.length;
			state.upload.hasPending = true;
			state.upload.showFields = true;
			state.upload.status = '';
		},

		confirmUpload() {
			const files = state.upload.pendingFiles || [];
			if ( ! files.length ) return;
			actions.uploadFiles( files );
		},

		cancelUpload() {
			state.upload.pendingFiles = [];
			state.upload.pendingCount = 0;
			state.upload.hasPending = false;
			const input = document.querySelector( '.mvs-dashboard-upload input[type="file"]' );
			if ( input ) input.value = '';
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

			// Leave the review step now that the upload is confirmed.
			state.upload.hasPending = false;
			state.upload.pendingFiles = [];
			state.upload.pendingCount = 0;

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
						( state.i18n?.fileTypeNotAllowed || 'File type not allowed: %1$s. Supported: %2$s' )
							.replace( '%1$s', rejected.join( ', ' ) )
							.replace( '%2$s', ctx.allowedExtensions ),
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

			// Tie a multi-file selection together so the BuddyPress activity
			// sync emits ONE carousel item instead of one feed row per file.
			// Same key shape as the upload modal (shared-ui), which has always
			// sent this.
			const mediaGroup =
				total > 1
					? 'grp_' + Date.now() + '_' + Math.random().toString( 36 ).slice( 2, 8 )
					: null;

			for ( let i = 0; i < total; i++ ) {
				state.upload.status = ( state.i18n?.uploadingProgress || 'Uploading %1$d of %2$d...' )
					.replace( '%1$d', i + 1 )
					.replace( '%2$d', total );
				const formData = new FormData();
				formData.append( 'file', files[ i ] );
				// Capture a poster frame for videos — see media-upload. Without
				// it, a video uploaded from the dashboard is posterless on a
				// host with no ffmpeg and renders as a blank tile.
				await appendVideoPoster( formData, files[ i ] );
				if ( mediaGroup ) {
					formData.append( 'media_group', mediaGroup );
					formData.append( 'group_position', String( i ) );
				}
				if ( state.upload.privacy ) formData.append( 'privacy', state.upload.privacy );
				// Title stays single-file only (N files must not share one name),
				// but the description IS the carousel caption — dropping it on a
				// multi-file upload is exactly why grouped feed items posted with
				// no caption text.
				if ( state.upload.title && total === 1 ) formData.append( 'title', state.upload.title );
				if ( state.upload.description ) formData.append( 'description', state.upload.description );
				if ( state.upload.tags ) {
					state.upload.tags.split( ',' ).map( ( t ) => t.trim() ).filter( Boolean )
						.forEach( ( tag ) => formData.append( 'tags[]', tag ) );
				}
				try {
					const res = await window.mvsRest.restFetch( ctx.restUrl + 'media', {
						method: 'POST',
						body: formData,
					} );
					if ( res.ok ) {
						uploaded++;
					} else {
						const errData = res.data || {};
						lastError = errData.message || ( state.i18n?.uploadFailed || 'Upload failed.' );
					}
				} catch {
					// Continue with remaining.
				}
			}

			state.upload.uploading = false;
			state.upload.status = '';
			if ( uploaded === 0 ) {
				sharedUI.actions.showToast( lastError || ( state.i18n?.uploadFailedRetry || 'Upload failed. Please try again.' ), 'error' );
			} else if ( uploaded < total ) {
				sharedUI.actions.showToast(
					( state.i18n?.filesUploadedPartial || '%1$d of %2$d file(s) uploaded.' )
						.replace( '%1$d', uploaded )
						.replace( '%2$d', total ),
					'error'
				);
			} else {
				sharedUI.actions.showToast(
					( state.i18n?.filesUploaded || '%d file(s) uploaded!' ).replace( '%d', total ),
					'success'
				);
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
				// X-WP-TotalPages drives Load More; restFetch exposes it via res.headers.
				state.media.totalPages = parseInt( ( res.headers && res.headers.get( 'X-WP-TotalPages' ) ) || '1', 10 );
				const data = res.data;

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
			window.mvsRest.restFetch( ctx.restUrl + 'media/' + item.id ).then( ( r ) => {
				sharedState.lightboxMediaData = r.data;
				sharedState.lightboxLoading = false;
				// Load social data.
				sharedUI.actions.lightboxLoadSocial( ctx, item.id, {} );
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
			window.mvsRest.restFetch( ctx.restUrl + 'media/' + item.media_id ).then( ( r ) => {
				sharedState.lightboxMediaData = r.data;
				sharedState.lightboxLoading = false;
				sharedUI.actions.lightboxLoadSocial( ctx, item.media_id, {} );
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
			// Use Array.from() — `item.tags` may be a WordPress Interactivity
			// Proxy or a JSON-parsed array. Array.from() handles both safely
			// and always yields a plain array for JSON serialization.
			state.editModal.tags = Array.isArray( item.tags ) || item.tags
				? Array.from( item.tags )
				: [];
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
				const res = await window.mvsRest.restFetch( ctx.restUrl + 'media/' + mediaId + '/replace', {
					method: 'POST',
					body: formData,
				} );
				if ( res.ok ) {
					const updated = res.data;
					const idx = state.media.items.findIndex( ( m ) => m.id === mediaId );
					if ( idx !== -1 ) {
						state.media.items[ idx ] = { ...state.media.items[ idx ], ...updated };
					}
					sharedUI.actions.showToast( ( state.i18n?.fileReplaced || 'File replaced!' ), 'success' );
				} else {
					const err = res.data || {};
					sharedUI.actions.showToast( err.message || ( state.i18n?.replaceFailed || 'Replace failed.' ), 'error' );
				}
			} catch {
				sharedUI.actions.showToast( ( state.i18n?.replaceFailed || 'Replace failed.' ), 'error' );
			}
			state.editModal.saving = false;
		},

		async saveEdit() {
			const ctx = getContext();

			// A media item with no name renders as an untitled tile everywhere
			// it appears, and the server refuses it (mvs_empty_title). The
			// Save button is bound to editModalSaveDisabled so this should be
			// unreachable from the UI; it stays as the belt-and-braces path
			// for keyboard/programmatic submits.
			if ( '' === String( state.editModal.title || '' ).trim() ) {
				return;
			}

			state.editModal.saving = true;

			// Array.from() unwraps the Interactivity Proxy so JSON.stringify
			// produces a real array. Without it the Proxy can serialize to `{}`
			// or drop entries, which the server then reads as "clear all tags".
			const payload = {
				title: state.editModal.title,
				description: state.editModal.description,
				privacy: state.editModal.privacy,
				tags: Array.from( state.editModal.tags || [] ),
			};

			// Slug stays put unless the user explicitly opted in via the
			// "Update URL slug" checkbox. Read DOM directly — keeps the
			// checkbox state authoritative regardless of any IA hydration
			// timing quirks. Server runs sanitize_title + collision check.
			const slugCheckbox = document.querySelector( '.mvs-edit-regenerate-slug' );
			if ( slugCheckbox && slugCheckbox.checked ) {
				payload.slug = ( state.editModal.title || '' )
					.toLowerCase()
					.replace( /[^\w\s-]/g, '' )
					.trim()
					.replace( /\s+/g, '-' )
					.replace( /-+/g, '-' );
			}

			try {
				const res = await apiFetch( ctx, 'media/' + state.editModal.itemId, {
					method: 'PUT',
					body: payload,
				} );
				const updated = res.data;
				state.editModal.saving = false;
				state.editModal.visible = false;

				// Update item in-place.
				const idx = state.media.items.findIndex( ( m ) => m.id === state.editModal.itemId );
				if ( idx !== -1 ) {
					state.media.items[ idx ] = { ...state.media.items[ idx ], ...updated };
				}

				sharedUI.actions.showToast( ( state.i18n?.mediaUpdated || 'Media updated!' ), 'success' );
			} catch {
				state.editModal.saving = false;
				sharedUI.actions.showToast( ( state.i18n?.updateFailed || 'Update failed.' ), 'error' );
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
			sharedUI.actions.showConfirm( ( state.i18n?.confirmDeleteMedia || 'Delete this media item? This cannot be undone.' ), async () => {
				try {
					const res = await apiFetch( ctx, 'media/' + id, {
						method: 'DELETE',
					} );
					if ( res.ok ) {
						state.media.items = state.media.items.filter( ( m ) => m.id !== id );
						sharedUI.actions.showToast( ( state.i18n?.mediaDeleted || 'Media deleted.' ), 'success' );
					} else {
						sharedUI.actions.showToast( ( state.i18n?.deleteFailed || 'Delete failed.' ), 'error' );
					}
				} catch {
					sharedUI.actions.showToast( ( state.i18n?.deleteFailed || 'Delete failed.' ), 'error' );
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
				const data = res.data;
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
				const data = res.data;
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
				// Deselecting the current cover — clear it, otherwise save
				// would PUT /cover for a media that's no longer in the album
				// and the backend returns 400 mvs_cover_not_in_album.
				if ( state.albumModal.coverId === id ) {
					state.albumModal.coverId = 0;
				}
			} else {
				state.albumModal.selectedIds = [ ...state.albumModal.selectedIds, id ];
			}
		},

		setCoverItem( event ) {
			event.stopPropagation();
			const id = parseInt( event.target.closest( '[data-picker-id]' )?.dataset.pickerId, 10 );
			if ( ! id ) return;
			state.albumModal.coverId = state.albumModal.coverId === id ? 0 : id;
			// The cover must be one of the album's items — auto-select it so the
			// save pipeline adds it before the /cover PUT (which would otherwise 400).
			if ( state.albumModal.coverId && ! state.albumModal.selectedIds.includes( id ) ) {
				state.albumModal.selectedIds = [ ...state.albumModal.selectedIds, id ];
			}
		},

		async saveAlbum() {
			const ctx = getContext();
			state.albumModal.saving = true;

			// Invariant: the cover media MUST be one of the album's items,
			// or AlbumService::set_cover() returns 400 mvs_cover_not_in_album.
			// Enforce at the save boundary so no UI path (Set Cover then
			// deselect, for example) can desynchronise coverId and selectedIds.
			if (
				state.albumModal.coverId &&
				! state.albumModal.selectedIds.includes( state.albumModal.coverId )
			) {
				state.albumModal.selectedIds = [ ...state.albumModal.selectedIds, state.albumModal.coverId ];
			}

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
						body: payload,
					} );
					// Sync album items: add new, remove deselected.
					const currentItems = state.albumModal.originalItems || [];
					const selected = state.albumModal.selectedIds;
					const toAdd = selected.filter( ( id ) => ! currentItems.includes( id ) );
					const toRemove = currentItems.filter( ( id ) => ! selected.includes( id ) );
					if ( toAdd.length ) {
						await apiFetch( ctx, 'albums/' + albumId + '/items', {
							method: 'POST',
							body: { media_ids: toAdd },
						} );
					}
					for ( const removeId of toRemove ) {
						await apiFetch( ctx, 'albums/' + albumId + '/items/' + removeId, {
							method: 'DELETE',
						} );
					}
				} else {
					// Shared validate-name + POST path — the same helper the
					// upload modal and BuddyPress albums tab use, so the
					// empty-name message and create logic live in one place.
					// It also checks the response, so a failed create (e.g. an
					// empty title returning 400) can no longer fall through and
					// be reported as "Album created!" (Basecamp 10069383195).
					const albumResult = await window.mvsRest.createAlbum(
						state.albumModal.title,
						{
							description: state.albumModal.description,
							privacy: state.albumModal.privacy,
						}
					);
					if ( ! albumResult.ok ) {
						state.albumModal.saving = false;
						sharedUI.actions.showToast( albumResult.message, 'error' );
						return;
					}
					albumId = albumResult.data.id;
					if ( state.albumModal.selectedIds.length && albumId ) {
						await apiFetch( ctx, 'albums/' + albumId + '/items', {
							method: 'POST',
							body: { media_ids: state.albumModal.selectedIds },
						} );
					}
				}
				// Set cover if selected.
				if ( state.albumModal.coverId && albumId ) {
					await apiFetch( ctx, 'albums/' + albumId + '/cover', {
						method: 'PUT',
						body: { media_id: state.albumModal.coverId },
					} );
				}
				state.albumModal.visible = false;
				state.albumModal.saving = false;
				sharedUI.actions.showToast( state.albumModal.isEdit ? ( state.i18n?.albumUpdated || 'Album updated!' ) : ( state.i18n?.albumCreated || 'Album created!' ), 'success' );
				await actions.loadAlbums( ctx );
			} catch {
				state.albumModal.saving = false;
				sharedUI.actions.showToast( ( state.i18n?.saveFailed || 'Save failed.' ), 'error' );
			}
		},

		confirmDeleteAlbum() {
			const ctx = getContext();
			const id = ctx.item?.id;
			if ( ! id ) return;
			sharedUI.actions.showConfirm( ( state.i18n?.confirmDeleteAlbum || 'Delete this album? Media items will not be deleted.' ), async () => {
				try {
					const res = await apiFetch( ctx, 'albums/' + id, {
						method: 'DELETE',
					} );
					if ( res.ok ) {
						state.albums.items = state.albums.items.filter( ( a ) => a.id !== id );
						sharedUI.actions.showToast( ( state.i18n?.albumDeleted || 'Album deleted.' ), 'success' );
					} else {
						sharedUI.actions.showToast( ( state.i18n?.deleteFailed || 'Delete failed.' ), 'error' );
					}
				} catch {
					sharedUI.actions.showToast( ( state.i18n?.deleteFailed || 'Delete failed.' ), 'error' );
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
				// X-WP-TotalPages drives Load More; restFetch exposes it via res.headers.
				state.favorites.totalPages = parseInt( ( res.headers && res.headers.get( 'X-WP-TotalPages' ) ) || '1', 10 );
				const data = res.data;

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
			} );
			if ( res.ok ) {
				state.favorites.items = state.favorites.items.filter( ( f ) => f.media_id !== mediaId );
				sharedUI.actions.showToast( ( state.i18n?.removedFromFavorites || 'Removed from favorites.' ), 'success' );
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
				const data = res.data;
				// Enrich with match counts for smart collections.
				for ( const item of data ) {
					item.link = '/?p=' + item.id;
					if ( item.type === 'smart' ) {
						try {
							const detail = await apiFetch( ctx, 'collections/' + item.id + '?per_page=1' );
							const dData = detail.data;
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
				state.challenges.items = res.data;
				const activeRes = await proApiFetch( ctx, 'challenges?status=active&per_page=1' );
				const activeData = activeRes.data;
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
				state.battles.items = res.data;
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
				state.tournaments.items = res.data;
				const openRes = await proApiFetch( ctx, 'tournaments?status=registration&per_page=1' );
				const openData = openRes.data;
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
				const data = res.data;
				state.collectionModal.tags = data.map( ( t ) => ( { value: String( t.id ), label: t.name } ) );
			} catch {
				state.collectionModal.tags = [];
			}
			// Load categories.
			try {
				const catUrl = ctx.restUrl.replace( 'mvs/v1/', '' ) + 'wp/v2/mvs_category?per_page=100';
				const catRes = await window.mvsRest.restFetch( catUrl );
				const catData = catRes.data;
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
						body: { rules: validRules.map( ( { key, value } ) => ( { key, value } ) ) },
					} );
					const res = await apiFetch( ctx, 'collections/' + state.collectionModal.collectionId + '?per_page=1' );
					const data = res.data;
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
						body: payload,
					} );
					// Update rules separately if smart.
					if ( state.collectionModal.collectionType === 'smart' && validRules.length ) {
						await apiFetch( ctx, 'collections/' + state.collectionModal.collectionId + '/rules', {
							method: 'PUT',
							body: { rules: validRules },
						} );
					}
					sharedUI.actions.showToast( ( state.i18n?.collectionUpdated || 'Collection updated!' ), 'success' );
				} else {
					await apiFetch( ctx, 'collections', {
						method: 'POST',
						body: payload,
					} );
					sharedUI.actions.showToast( ( state.i18n?.collectionCreated || 'Collection created!' ), 'success' );
				}
				state.collectionModal.visible = false;
				state.collectionModal.saving = false;
				await actions.loadCollections( ctx );
			} catch {
				state.collectionModal.saving = false;
				sharedUI.actions.showToast( ( state.i18n?.saveFailed || 'Save failed.' ), 'error' );
			}
		},

		confirmDeleteCollection() {
			const ctx = getContext();
			const id = ctx.item?.id;
			if ( ! id ) return;
			sharedUI.actions.showConfirm( ( state.i18n?.confirmDeleteCollection || 'Delete this collection? Media items will not be deleted.' ), async () => {
				try {
					const res = await apiFetch( ctx, 'collections/' + id, {
						method: 'DELETE',
					} );
					if ( res.ok ) {
						state.collections.items = state.collections.items.filter( ( c ) => c.id !== id );
						sharedUI.actions.showToast( ( state.i18n?.collectionDeleted || 'Collection deleted.' ), 'success' );
					} else {
						sharedUI.actions.showToast( ( state.i18n?.deleteFailed || 'Delete failed.' ), 'error' );
					}
				} catch {
					sharedUI.actions.showToast( ( state.i18n?.deleteFailed || 'Delete failed.' ), 'error' );
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
					const data = res.data;
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
				} );
				state.notifications.count = 0;
				state.notifications.items = state.notifications.items.map( ( n ) => ( { ...n, read: true } ) );
				sharedUI.actions.showToast( ( state.i18n?.allNotificationsRead || 'All notifications marked as read.' ), 'success' );
			} catch {
				// Ignore.
			}
		},

		// Mark an individual notification as read when the user clicks it.
		// The default link navigation still fires — this runs alongside it so
		// the unread count decrements and the row is restyled without waiting
		// for the next page load.
		async markNotificationRead() {
			const clicked = getContext().item;
			if ( ! clicked || clicked.read ) {
				return;
			}
			// Optimistically flip the UI so the badge updates immediately —
			// the network call happens in the background as the browser navigates.
			clicked.read = true;
			if ( state.notifications.count > 0 ) {
				state.notifications.count -= 1;
			}
			const ctx = getContext();
			try {
				await apiFetch( ctx, 'me/notifications/read', {
					method: 'POST',
					body: { ids: [ clicked.id ] },
				} );
			} catch ( err ) {
				// Roll back the optimistic update so the user can try again.
				clicked.read = false;
				state.notifications.count += 1;
				// eslint-disable-next-line no-console
				console.error( 'mvs: failed to mark notification read', err );
			}
		},

		async loadNotificationCount( ctx ) {
			try {
				const res = await apiFetch( ctx, 'me/notifications/count' );
				const data = res.data;
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
			const validTabs = [ 'media', 'albums', 'favorites', 'collections', 'challenges', 'battles', 'tournaments', 'connectors' ];
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

			// Mobile §5.2: when the dashboard tab strip overflows on small viewports,
			// scroll the active tab into view so users see context (and the fact that
			// more tabs exist) on first paint. Wait one frame so the active class is
			// applied by the Interactivity API before measuring.
			requestAnimationFrame( () => {
				const strip = document.querySelector( '.mvs-dashboard-tabs' );
				const active = strip?.querySelector( '.mvs-dashboard-tab.active, [data-tab="' + state.activeTab + '"]' );
				if ( strip && active && strip.scrollWidth > strip.clientWidth ) {
					active.scrollIntoView( { inline: 'center', block: 'nearest' } );
				}
			} );
		},
	},
} );
