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

import { store, getContext, getElement } from '@wordpress/interactivity';

// i18n: this is a script MODULE, so window.wp.i18n.__() is English-locked here
// (no getLocaleData for the domain). Strings are PHP-translated and injected into
// interactivity state by shared-ui-frame.php via wp_interactivity_state(); read
// them as `state.i18n.<key>` with an English fallback. Basecamp 10073528834.
let toastTimer = null;
let tagSearchTimer = null;

/**
 * After lightbox data is set, tell <video>/<audio> to load the new src.
 * The Interactivity API updates `src` via data-wp-bind, but <video>/<audio>
 * elements require an explicit .load() call to fetch a dynamically changed src.
 */
function loadLightboxMedia() {
	requestAnimationFrame( () => {
		const video = document.querySelector( '.mvs-lightbox-video:not([hidden])' );
		if ( video ) {
			video.load();
		}
		const audio = document.querySelector( '.mvs-lightbox-audio:not([hidden])' );
		if ( audio ) {
			audio.load();
		}
	} );
}

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
		uploadModalDuplicates: 0,
		uploadModalLastDuplicateId: 0,
		uploadModalLastError: '',
		uploadModalTitle: '',
		uploadModalDescription: '',
		uploadModalTags: '',
		uploadModalPrivacy: 'public',
		uploadModalAlbumTitle: '',
		uploadModalAlbumDescription: '',
		uploadModalMediaGroup: null,
		uploadModalAlbum: 0, // chosen album: 0 = none, -1 = create new, >0 = existing id
		uploadModalNewAlbumName: '', // typed name when "Create new album" is chosen
		userAlbums: [], // [{ id, title }] for the "Add to album" select
		get hideUploadMetaFields() {
			return state.uploadModalUploading;
		},
		get hasUserAlbums() {
			return Array.isArray( state.userAlbums ) && state.userAlbums.length > 0;
		},
		get isCreatingNewAlbum() {
			// "Create new album" chosen in the "Add to album" select (value -1).
			return state.uploadModalAlbum === -1;
		},
		get hideAlbumCoverHint() {
			return state.uploadModalMode !== 'album' || state.uploadModalUploading || ! state.hasFiles;
		},

		get uploadModalHeading() {
			const titles = {
				photo: ( state.i18n?.uploadPhoto || 'Upload Photo' ),
				gallery: ( state.i18n?.createGallery || 'Create Gallery Post' ),
				album: ( state.i18n?.createAlbum || 'Create Album' ),
				video: ( state.i18n?.uploadVideo || 'Upload Video' ),
				audio: ( state.i18n?.uploadAudio || 'Upload Audio' ),
			};
			return titles[ state.uploadModalMode ] || ( state.i18n?.upload || 'Upload' );
		},
		get uploadAccept() {
			// Auto-detect flow: accept every supported type; the picked file(s)
			// determine the mode (photo/gallery/video/audio).
			const allowed = getContext().allowedTypes || '';
			return allowed || 'image/*,video/*,audio/*';
		},
		get uploadMultiple() {
			return true;
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
		get isAudioMode() {
			return state.uploadModalMode === 'audio';
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

		// --- Edit Media modal (per-media settings: title, description,
		// privacy, allow_download). Opened via window.mvsOpenEditModal( id )
		// from the .mvs-media-edit-btn delegated click in bp-actions.js.
		editModalVisible: false,
		editModalMediaId: 0,
		editModalLoading: false,
		editModalSaving: false,
		editModalTitle: '',
		editModalDescription: '',
		editModalPrivacy: 'public',
		editModalAllowDownload: true,
		// Off by default — title edits leave the URL slug alone. The user
		// can opt in via the "Update URL slug from title" checkbox; the
		// save handler will compute sanitize_title(title) on the JS side
		// and pass it as `slug` so the REST controller's explicit-slug
		// branch applies.
		editModalRegenerateSlug: false,
		editModalError: '',


		// --- Popular tag pills (cached for the session, lazily loaded the
		// first time the upload or edit modal opens).
		popularTags: [],
		popularTagsLoaded: false,

		get lightboxImageUrl() {
			const d = state.lightboxMediaData;
			if ( ! d || d.media_type === 'video' || d.media_type === 'audio' ) {
				return '';
			}
			// Prefer the admin-chosen lightbox_url (honors `mvs_lightbox_image_source`);
			// fallback to file_url (original) over thumbnail so upgrades from 1.1.2 still
			// open full-size images instead of the low-res grid thumbnail.
			return d.lightbox_url || d.file_url || d.thumbnail_url || '';
		},
		get lightboxImageWebpUrl() {
			// WebP sibling of the lightbox image. Empty string when the upload
			// pre-dates 1.2.2 optimization or the variant is not safe to embed
			// directly (gated /serve route). Empty `srcset` makes the browser
			// skip the `<source>` and use the JPEG `<img>` fallback.
			const d = state.lightboxMediaData;
			if ( ! d || d.media_type === 'video' || d.media_type === 'audio' ) {
				return '';
			}
			return d.lightbox_webp_url || '';
		},
		get lightboxHideImageWebp() {
			// Hide the `<source>` when WebP is missing OR when the lightbox is
			// showing a non-image item. `data-wp-bind--hidden` on a `<source>`
			// element makes the browser ignore it during type negotiation.
			const d = state.lightboxMediaData;
			if ( ! d || d.media_type === 'video' || d.media_type === 'audio' ) {
				return true;
			}
			return ! d.lightbox_webp_url;
		},
		get lightboxImageAvifUrl() {
			// AVIF sibling — same contract as the WebP getter. Browsers walk
			// `<source>` elements in document order so we put the AVIF source
			// FIRST in the template; AVIF-capable browsers (Chrome, Firefox,
			// Safari 16.4+, Edge) pick it and skip the WebP/JPEG fallbacks.
			const d = state.lightboxMediaData;
			if ( ! d || d.media_type === 'video' || d.media_type === 'audio' ) {
				return '';
			}
			return d.lightbox_avif_url || '';
		},
		get lightboxHideImageAvif() {
			const d = state.lightboxMediaData;
			if ( ! d || d.media_type === 'video' || d.media_type === 'audio' ) {
				return true;
			}
			return ! d.lightbox_avif_url;
		},
		get lightboxIsVideo() {
			return state.lightboxMediaData?.media_type === 'video';
		},
		get lightboxIsAudio() {
			return state.lightboxMediaData?.media_type === 'audio';
		},
		get lightboxHideImage() {
			const t = state.lightboxMediaData?.media_type;
			return t === 'video' || t === 'audio';
		},
		get lightboxHideVideo() {
			return state.lightboxMediaData?.media_type !== 'video';
		},
		get lightboxHideAudio() {
			return state.lightboxMediaData?.media_type !== 'audio';
		},
		get lightboxVideoUrl() {
			return state.lightboxMediaData?.file_url || '';
		},
		get lightboxFileType() {
			return state.lightboxMediaData?.file_type || '';
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
		// Trusted decoration (currently bp-verified-member badge `<img>`)
		// rendered in a sibling node via `data-wp-html`. Source is the REST
		// `author_data.badge_html` field — see MediaController for the
		// payload contract. Empty string when the integration is inactive
		// or the user isn't verified; the template's sibling node degrades
		// silently in that case.
		get lightboxAuthorBadgeHtml() {
			return state.lightboxMediaData?.author_data?.badge_html || '';
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
		// Per-media download flag — defaults to true when the response
		// doesn't carry the field (older builds, public callers without
		// /media/{id} expansion). The lightbox Download button uses this
		// AND the global mvs_allow_downloads toggle (rendered server-
		// side; if global is off the button is not in the DOM at all).
		get lightboxAllowDownload() {
			const d = state.lightboxMediaData;
			if ( ! d ) return false;
			return d.allow_download !== false;
		},
		get lightboxHideDownload() {
			return ! state.lightboxAllowDownload;
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
			// Icon is rendered separately via Lucide (data-lucide="heart"); label is plain text.
			return state.lightboxIsFavorited ? 'Favorited' : 'Favorite';
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
					const res = await window.mvsRest.restFetch(
						restUrl + 'tags?search=' + encodeURIComponent( query ) + '&per_page=8'
					);
					const data = res.data;
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
			state.uploadModalDuplicates = 0;
			state.uploadModalLastDuplicateId = 0;
			state.uploadModalLastError = '';
			state.uploadModalTitle = '';
			state.uploadModalDescription = '';
			state.uploadModalTags = '';
			state.uploadModalPrivacy = ctx.defaultPrivacy || 'public';
			state.uploadModalAlbumTitle = '';
			state.uploadModalAlbumDescription = '';
			state.uploadModalMediaGroup = null;
			state.uploadModalAlbum = 0;
			state.uploadModalNewAlbumName = '';
			document.body.style.overflow = 'hidden';
			actions.loadPopularTags(); // fire-and-forget; pills lazy-load.
			actions.loadUserAlbums(); // fire-and-forget; "Add to album" options.
		},
		closeUploadModal() {
			state.uploadModalVisible = false;
			state.uploadModalFiles = [];
			state.uploadModalPreviews = [];
			state.uploadModalTitle = '';
			state.uploadModalDescription = '';
			state.uploadModalTags = '';
			state.uploadModalPrivacy = 'public';
			state.uploadModalAlbumTitle = '';
			state.uploadModalAlbumDescription = '';
			state.uploadModalUploading = false;
			state.uploadModalDone = 0;
			state.uploadModalFailed = 0;
			state.uploadModalDuplicates = 0;
			state.uploadModalLastDuplicateId = 0;
			state.uploadModalLastError = '';
			state.uploadModalAlbum = 0;
			state.uploadModalNewAlbumName = '';
			document.body.style.overflow = '';
		},
		// --- Edit Media modal actions ---
		async loadPopularTags() {
			if ( state.popularTagsLoaded ) return;
			state.popularTagsLoaded = true; // race-guard before the await.
			try {
				const restUrl = window.mvsBpActions?.restUrl
					|| ( window.location.origin + '/wp-json/mvs/v1/' );
				const res = await window.mvsRest.restFetch( `${ restUrl }tags/cloud?limit=8`, {
					headers: { Accept: 'application/json' },
				} );
				if ( ! res.ok ) return;
				const data = res.data;
				state.popularTags = Array.isArray( data )
					? data.map( ( t ) => ( { name: t.name || t.slug || '', slug: t.slug || '' } ) ).filter( ( t ) => !! t.name )
					: [];
			} catch {
				// Silent — pills are an enhancement, not load-blocking.
			}
		},
		// Click on a popular-tag pill in the upload modal: append the tag
		// name to the existing tag input, comma-separated, without dupes.
		addUploadTag() {
			const wrap = getElement().ref?.closest( '[data-mvs-tag-name]' );
			const tag = wrap?.getAttribute( 'data-mvs-tag-name' );
			if ( ! tag ) return;
			const current = ( state.uploadModalTags || '' ).split( ',' ).map( ( s ) => s.trim() ).filter( Boolean );
			if ( current.includes( tag ) ) return;
			current.push( tag );
			state.uploadModalTags = current.join( ', ' );
		},
		async openEditModal( mediaId ) {
			actions.loadPopularTags(); // fire-and-forget; pills lazy-load.

			const id = parseInt( mediaId, 10 );
			if ( ! id ) return;
			state.editModalMediaId = id;
			state.editModalVisible = true;
			state.editModalLoading = true;
			state.editModalError = '';
			document.body.style.overflow = 'hidden';

			try {
				const restUrl = window.mvsBpActions?.restUrl
					|| ( window.location.origin + '/wp-json/mvs/v1/' );
				const res = await window.mvsRest.restFetch( `${ restUrl }media/${ id }` );
				if ( ! res.ok ) throw new Error( 'fetch_failed' );
				const data = res.data;
				state.editModalTitle = data.title || '';
				state.editModalDescription = data.description || '';
				state.editModalPrivacy = data.privacy || 'public';
				state.editModalAllowDownload = data.allow_download !== false;
			} catch {
				state.editModalError = 'Could not load this media. Try again.';
			}
			state.editModalLoading = false;
		},
		closeEditModal() {
			state.editModalVisible = false;
			state.editModalMediaId = 0;
			state.editModalTitle = '';
			state.editModalDescription = '';
			state.editModalPrivacy = 'public';
			state.editModalAllowDownload = true;
			state.editModalRegenerateSlug = false;
			state.editModalError = '';
			state.editModalSaving = false;
			document.body.style.overflow = '';
		},
		updateEditTitle() {
			state.editModalTitle = getElement().ref?.value || '';
		},
		updateEditDescription() {
			state.editModalDescription = getElement().ref?.value || '';
		},
		updateEditPrivacy() {
			state.editModalPrivacy = getElement().ref?.value || 'public';
		},
		toggleEditAllowDownload() {
			state.editModalAllowDownload = !! getElement().ref?.checked;
		},
		updateEditRegenerateSlug() {
			state.editModalRegenerateSlug = !! getElement().ref?.checked;
		},
		async saveEditModal() {
			if ( state.editModalSaving || ! state.editModalMediaId ) return;
			state.editModalSaving = true;
			state.editModalError = '';

			try {
				const restUrl = window.mvsBpActions?.restUrl
					|| ( window.location.origin + '/wp-json/mvs/v1/' );
				const body = {
					title: state.editModalTitle,
					description: state.editModalDescription,
					privacy: state.editModalPrivacy,
					allow_download: state.editModalAllowDownload,
				};
				// Only include `slug` when the admin explicitly opted into
				// regenerating it from the new title. Leaving the field out
				// preserves the existing URL — the safer default per the
				// WordPress permalink convention.
				if ( state.editModalRegenerateSlug ) {
					// Match the server's sanitize_title — lowercase, dashes
					// for spaces, strip everything else. The server runs the
					// same pass + collision check, so this is just optimistic
					// formatting (and no-op if title unchanged).
					body.slug = ( state.editModalTitle || '' )
						.toLowerCase()
						.replace( /[^\w\s-]/g, '' )
						.trim()
						.replace( /\s+/g, '-' )
						.replace( /-+/g, '-' );
				}
				const res = await window.mvsRest.restFetch( `${ restUrl }media/${ state.editModalMediaId }`, {
					method: 'PUT',
					body,
				} );
				if ( ! res.ok ) {
					const err = res.data || {};
					throw new Error( err.message || 'save_failed' );
				}
				const updated = res.data || {};

				// When the user opted into a slug change AND they're CURRENTLY
				// on the media's single page (`/media/{old-slug}/`), the page
				// they're viewing now points at a dead URL. Redirect to the
				// new permalink so reload doesn't 404. On other pages
				// (Explore, BP profile, etc.) we just close the modal — the
				// page they're on is unaffected by the slug change.
				if (
					updated.link &&
					typeof window !== 'undefined' &&
					window.location.pathname !== new URL( updated.link ).pathname &&
					/\/media\/[^/]+\/?$/.test( window.location.pathname )
				) {
					actions.showToast( ( state.i18n?.savedRedirecting || 'Saved! Redirecting to the new URL…' ), 'success' );
					actions.closeEditModal();
					window.location.replace( updated.link );
					return;
				}

				actions.showToast( ( state.i18n?.settingsSaved || 'Media settings saved.' ), 'success' );
				actions.closeEditModal();
			} catch ( err ) {
				state.editModalError = err.message || 'Could not save. Try again.';
				state.editModalSaving = false;
			}
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
			actions.ingestFiles( Array.from( event.target.files ) );
		},
		handleUploadDrop( event ) {
			event.preventDefault();
			actions.ingestFiles( Array.from( event.dataTransfer.files ) );
		},
		// Auto-detect: key off the first picked file's type, keep only that media
		// group, set the mode (photo/gallery/video/audio), and preview.
		ingestFiles( rawFiles ) {
			if ( ! rawFiles.length ) return;
			const ft = rawFiles[ 0 ].type || '';
			let group = 'image/';
			if ( ft.startsWith( 'video/' ) ) {
				group = 'video/';
			} else if ( ft.startsWith( 'audio/' ) ) {
				group = 'audio/';
			}
			const valid = rawFiles.filter( ( f ) => ( f.type || '' ).startsWith( group ) );
			const rejected = rawFiles.length - valid.length;
			if ( rejected > 0 ) {
				actions.showToast( rejected + ' file(s) skipped — upload one media type at a time.', 'error' );
			}
			if ( ! valid.length ) return;
			if ( group === 'image/' ) {
				// Images append (so picking more turns a photo into a gallery).
				const existing = state.uploadModalFiles.filter( ( f ) => ( f.type || '' ).startsWith( 'image/' ) );
				state.uploadModalFiles = [ ...existing, ...valid ];
			} else {
				// One video / one audio per post.
				state.uploadModalFiles = [ valid[ valid.length - 1 ] ];
			}
			state.uploadModalMode = actions.detectMode();
			actions.generatePreviews();
		},
		detectMode() {
			const f = state.uploadModalFiles;
			if ( ! f.length ) return 'photo';
			const t = f[ 0 ].type || '';
			if ( t.startsWith( 'video/' ) ) return 'video';
			if ( t.startsWith( 'audio/' ) ) return 'audio';
			return f.length > 1 ? 'gallery' : 'photo';
		},
		handleUploadDragOver( event ) {
			event.preventDefault();
		},
		generatePreviews() {
			// Each preview is { uid, src, name, type, isAudio, isOther }
			// so the template can render filename + an icon for non-image
			// types (audio especially — previously rendered an empty <img>
			// with no visual cue at all). uid is a stable per-file marker
			// so removeUploadFile can identify which entry to drop without
			// relying on array index (which data-wp-each doesn't expose
			// directly in template context).
			const stamp = Date.now();
			const previews = state.uploadModalFiles.map( ( file, i ) => ( {
				uid: stamp + ':' + i + ':' + ( file.size || 0 ) + ':' + ( file.name || '' ),
				src: '',
				name: file.name || 'file',
				type: file.type || '',
				isAudio: file.type.startsWith( 'audio/' ),
				isOther: ! file.type.startsWith( 'image/' )
					&& ! file.type.startsWith( 'video/' )
					&& ! file.type.startsWith( 'audio/' ),
			} ) );
			state.uploadModalPreviews = previews;

			state.uploadModalFiles.forEach( ( file, idx ) => {
				if ( file.type.startsWith( 'image/' ) ) {
					const reader = new FileReader();
					reader.onload = ( e ) => {
						const next = [ ...state.uploadModalPreviews ];
						if ( next[ idx ] ) {
							next[ idx ] = { ...next[ idx ], src: e.target.result };
							state.uploadModalPreviews = next;
						}
					};
					reader.readAsDataURL( file );
				} else if ( file.type.startsWith( 'video/' ) ) {
					const video = document.createElement( 'video' );
					video.preload = 'metadata';
					video.muted = true;
					video.playsInline = true;
					const url = URL.createObjectURL( file );
					video.src = url;
					video.addEventListener( 'loadeddata', () => {
						video.currentTime = 1;
					} );
					video.addEventListener( 'seeked', () => {
						try {
							const canvas = document.createElement( 'canvas' );
							canvas.width = video.videoWidth || 320;
							canvas.height = video.videoHeight || 180;
							canvas.getContext( '2d' ).drawImage( video, 0, 0, canvas.width, canvas.height );
							const thumb = canvas.toDataURL( 'image/jpeg', 0.7 );
							const next = [ ...state.uploadModalPreviews ];
							if ( next[ idx ] ) {
								next[ idx ] = { ...next[ idx ], src: thumb };
								state.uploadModalPreviews = next;
							}
						} catch {
							// CORS / decode failure — leave src empty; template
							// shows the video icon as a fallback.
						}
						URL.revokeObjectURL( url );
					} );
				}
				// Audio + other types: no thumbnail. Template renders an
				// icon based on the type flags above.
			} );
		},
		removeUploadFile( event ) {
			const wrap = event.target?.closest?.( '[data-mvs-file-uid]' );
			if ( ! wrap ) {
				return;
			}
			const uid = wrap.getAttribute( 'data-mvs-file-uid' );
			if ( ! uid ) {
				return;
			}
			const idx = state.uploadModalPreviews.findIndex( ( p ) => p.uid === uid );
			if ( idx < 0 ) {
				return;
			}
			state.uploadModalFiles = state.uploadModalFiles.filter( ( _, i ) => i !== idx );
			state.uploadModalPreviews = state.uploadModalPreviews.filter( ( _, i ) => i !== idx );
			if ( state.uploadModalFiles.length === 0 ) {
				// All files removed — reset metadata so the modal shows the
				// dropzone placeholder again instead of stale field values.
				state.uploadModalTitle = '';
				state.uploadModalDescription = '';
				state.uploadModalTags = '';
			}
		},
		filterFilesByMode( files ) {
			const prefixes = { photo: 'image/', gallery: 'image/', album: 'image/', video: 'video/', audio: 'audio/' };
			const prefix = prefixes[ state.uploadModalMode ] || 'image/';
			const valid = files.filter( ( f ) => f.type.startsWith( prefix ) );
			const rejected = files.length - valid.length;
			if ( rejected > 0 ) {
				actions.showToast( rejected + ' file(s) not allowed for ' + state.uploadModalMode + ' upload.', 'error' );
			}
			return valid;
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
		updateUploadAlbum( event ) {
			// -1 = "Create new album", 0 = none, >0 = existing album id.
			const val = parseInt( event.target.value, 10 );
			state.uploadModalAlbum = Number.isNaN( val ) ? 0 : val;
			if ( state.uploadModalAlbum !== -1 ) {
				state.uploadModalNewAlbumName = '';
			}
		},
		updateNewAlbumName( event ) {
			state.uploadModalNewAlbumName = event.target.value;
		},
		async loadUserAlbums() {
			const ctx = getContext();
			if ( ! ctx || ! ctx.restUrl ) return;
			try {
				const res = await window.mvsRest.restFetch( ctx.restUrl + 'albums?author=' + ( ctx.currentUserId || 0 ) + '&per_page=50' );
				const rows = Array.isArray( res.data ) ? res.data : [];
				state.userAlbums = rows
					.filter( ( a ) => a && ( a.can_edit || a.is_owner ) )
					.map( ( a ) => ( { id: a.id, title: a.title || 'Untitled' } ) );
			} catch {
				state.userAlbums = [];
			}
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
				actions.showToast( ( state.i18n?.selectFiles || 'Please select files to upload.' ), 'error' );
				return;
			}

			state.uploadModalUploading = true;
			state.uploadModalTotal = files.length;
			state.uploadModalDone = 0;
			state.uploadModalFailed = 0;
			state.uploadModalDuplicates = 0;
			state.uploadModalLastDuplicateId = 0;

			// For album mode, create album first. Delegates to the single
			// shared validate-name + POST path (window.mvsRest.createAlbum) so
			// this surface and the BuddyPress albums tab share one create +
			// message implementation (Basecamp 10069383195).
			if ( state.uploadModalMode === 'album' ) {
				const albumResult = await window.mvsRest.createAlbum(
					state.uploadModalAlbumTitle,
					{
						description: state.uploadModalAlbumDescription,
						privacy: state.uploadModalPrivacy,
					}
				);
				if ( ! albumResult.ok ) {
					actions.showToast( albumResult.message, 'error' );
					state.uploadModalUploading = false;
					return;
				}
				const albumData = albumResult.data;
				state._pendingAlbumId = albumData.id;
				actions.showToast( ( state.i18n?.albumCreated || 'Album "%s" created!' ).replace( '%s', albumData.title ) );
				if ( ! files.length ) {
					state.uploadModalUploading = false;
					setTimeout( () => {
						actions.closeUploadModal();
						window.location.reload();
					}, 800 );
					return;
				}
			}

			// "Create new album" chosen in the Add-to-album select: create it up
			// front via the shared validate-name + POST helper so an invalid name
			// aborts before any file is uploaded. Reuse the _pendingAlbumId path
			// below to attach the uploads and set the cover.
			if ( state.uploadModalAlbum === -1 ) {
				const newAlbum = await window.mvsRest.createAlbum(
					state.uploadModalNewAlbumName,
					{ privacy: state.uploadModalPrivacy }
				);
				if ( ! newAlbum.ok ) {
					actions.showToast( newAlbum.message, 'error' );
					state.uploadModalUploading = false;
					return;
				}
				state._pendingAlbumId = newAlbum.data.id;
				state.uploadModalAlbum = 0; // consumed — skip the existing-album attach branch
			}

			const uploadedMediaIds = [];

			// Generate group ID for gallery mode.
			let mediaGroup = null;
			if ( state.uploadModalMode === 'gallery' && files.length > 1 ) {
				mediaGroup = 'grp_' + Date.now() + '_' + Math.random().toString( 36 ).slice( 2, 8 );
			}

			// Album mode doesn't carry per-file title/description/tags — the album
			// owns those. Privacy still applies per-item so it's always sent.
			const isAlbum = state.uploadModalMode === 'album';

			// Upload files sequentially.
			for ( let i = 0; i < files.length; i++ ) {
				const fd = new FormData();
				fd.append( 'file', files[ i ] );
				if ( ! isAlbum && state.uploadModalTitle ) fd.append( 'title', state.uploadModalTitle );
				if ( ! isAlbum && state.uploadModalDescription ) fd.append( 'description', state.uploadModalDescription );
				if ( ! isAlbum && state.uploadModalTags ) fd.append( 'tags', state.uploadModalTags );
				if ( state.uploadModalPrivacy ) fd.append( 'privacy', state.uploadModalPrivacy );
				if ( mediaGroup ) {
					fd.append( 'media_group', mediaGroup );
					fd.append( 'group_position', String( i ) );
				}
				// Send client-generated video thumbnail if available.
				// generatePreviews() stores preview objects: { uid, src, name, type, ... }.
				// The thumbnail data URL lives on `preview.src` — calling startsWith
				// on the object itself throws TypeError and aborts the whole upload
				// for video files (Basecamp #9871815208).
				const preview = state.uploadModalPreviews[ i ];
				const previewSrc = preview && typeof preview.src === 'string' ? preview.src : '';
				if ( files[ i ].type.startsWith( 'video/' ) && previewSrc.startsWith( 'data:' ) ) {
					try {
						const resp = await fetch( previewSrc );
						const blob = await resp.blob();
						fd.append( 'thumbnail', blob, 'video-thumb.jpg' );
					} catch { /* skip thumbnail */ }
				}

				// Album bulk-upload batch (≥2 files in one user action): tag the
				// upload so flag_activity_upload sets the activity_upload skip
				// flag. After the album link call below, the server emits ONE
				// "uploaded N photos to album X" gallery activity instead of
				// N "uploaded a new photo" per-file activities. Single-file
				// album uploads keep the per-photo activity (no bundling needed).
				const uploadUrl = restUrl + 'media' +
					( isAlbum && files.length > 1 ? '?album_upload=1' : '' );

				try {
					const res = await window.mvsRest.restFetch( uploadUrl, {
						method: 'POST',
						body: fd,
					} );
					if ( res.ok ) {
						const mediaData = res.data;
						if ( mediaData && mediaData.id ) uploadedMediaIds.push( mediaData.id );
						if ( mediaData && mediaData.duplicate_warning ) {
							state.uploadModalDuplicates++;
							state.uploadModalLastDuplicateId = mediaData.existing_media_id || 0;
						}
					} else {
						state.uploadModalFailed++;
						const errData = res.data;
						if ( errData && errData.message ) {
							state.uploadModalLastError = errData.message;
						} else {
							state.uploadModalLastError = 'Upload failed.';
						}
					}
				} catch {
					state.uploadModalFailed++;
				}
				state.uploadModalDone = i + 1;
			}

			// "Add to album" (chosen in Add details) — link the uploads to that
			// existing album. Doesn't touch the album cover.
			if ( state.uploadModalAlbum && uploadedMediaIds.length ) {
				try {
					await window.mvsRest.restFetch( restUrl + 'albums/' + state.uploadModalAlbum + '/items', {
						method: 'POST',
						body: { media_ids: uploadedMediaIds },
					} );
				} catch { /* linking failed — media still uploaded */ }
			}

			// Link uploaded media to album, then set the first image as the cover.
			// set_cover() is atomic (AlbumService::set_cover auto-adds media to the
			// album if not already present), but we've already linked items above,
			// so this is a single PUT. Non-fatal if cover setting fails.
			if ( state._pendingAlbumId && uploadedMediaIds.length ) {
				try {
					await window.mvsRest.restFetch( restUrl + 'albums/' + state._pendingAlbumId + '/items', {
						method: 'POST',
						body: { media_ids: uploadedMediaIds },
					} );
				} catch { /* linking failed — media still uploaded */ }
				const firstImageId = uploadedMediaIds.find( ( id, idx ) => {
					const f = files[ idx ];
					return f && f.type && f.type.startsWith( 'image/' );
				} );
				if ( firstImageId ) {
					try {
						await window.mvsRest.restFetch( restUrl + 'albums/' + state._pendingAlbumId + '/cover', {
							method: 'PUT',
							body: { media_id: firstImageId },
						} );
					} catch { /* cover failed — user can set it later from album edit */ }
				}
				state._pendingAlbumId = null;
			}

			const uploaded = files.length - state.uploadModalFailed;
			state.uploadModalUploading = false;

			if ( uploaded > 0 ) {
				let msg;
				let toastType;
				if ( state.uploadModalFailed > 0 ) {
					msg = uploaded + ' uploaded, ' + state.uploadModalFailed + ' failed.';
					toastType = 'error';
				} else if ( state.uploadModalDuplicates > 0 ) {
					msg = uploaded + ' uploaded — ' + state.uploadModalDuplicates + ' duplicate(s) detected (existing media #' + state.uploadModalLastDuplicateId + ').';
					toastType = 'warning';
				} else {
					msg = uploaded + ' file(s) uploaded!';
					toastType = 'success';
				}
				actions.showToast( msg, toastType );
				setTimeout( () => {
					actions.closeUploadModal();
					window.location.reload();
				}, state.uploadModalDuplicates > 0 ? 2500 : 800 );
			} else {
				actions.showToast( state.uploadModalLastError || 'Upload failed. Please try again.', 'error' );
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
				const res = await window.mvsRest.restFetch(
					ctx.restUrl + 'media/' + mediaId
				);
				const data = res.data;
				state.lightboxMediaData = data;
				state.lightboxLoading = false;
				loadLightboxMedia();

				// If this item is part of a gallery group, fetch all group members.
				if ( data.media_group && data.group_count > 1 ) {
					const groupRes = await window.mvsRest.restFetch(
						ctx.restUrl + 'media/' + mediaId + '/group'
					);
					const groupData = groupRes.data;
					if ( Array.isArray( groupData ) && groupData.length > 1 ) {
						state.lightboxGroupItems = groupData;
						// Set current index to 0 (cover image).
						state.lightboxCurrentIndex = 0;
						// Show the first image.
						state.lightboxMediaData = groupData[ 0 ];
						loadLightboxMedia();
					}
				}
			// Fetch social data in parallel.
				actions.lightboxLoadSocial( ctx, mediaId );
			} catch {
				state.lightboxLoading = false;
				actions.showToast( ( state.i18n?.failedLoad || 'Failed to load media.' ), 'error' );
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
			let isLoggedIn = false;
			const ctxEl = document.querySelector( '[data-wp-interactive="mvs/shared-ui"][data-wp-context]' );
			if ( ctxEl ) {
				try {
					const parsed = JSON.parse( ctxEl.dataset.wpContext );
					restUrl = parsed.restUrl || restUrl;
					nonce = parsed.nonce || nonce;
					isLoggedIn = !! parsed.currentUserId;
				} catch { /* use defaults */ }
			}

			// Try to read pre-embedded media data from the grid card DOM first.
			// This avoids a REST call entirely and works even when REST is blocked.
			const cardEl = document.querySelector( '[data-media-id="' + mediaId + '"][data-media-json]' );
			let embeddedData = null;
			if ( cardEl ) {
				try {
					embeddedData = JSON.parse( cardEl.dataset.mediaJson );
				} catch { /* fall through to REST */ }
			}

			try {
				let data;

				if ( embeddedData ) {
					// Use embedded data — instant, no network request.
					data = embeddedData;
				} else {
					const res = await window.mvsRest.restFetch( restUrl + 'media/' + mediaId );
					data = res.data;
				}

				state.lightboxMediaData = data;
				state.lightboxLoading = false;
				loadLightboxMedia();

				if ( data.media_group && data.group_count > 1 ) {
					const groupRes = await window.mvsRest.restFetch( restUrl + 'media/' + mediaId + '/group' );
					const groupData = groupRes.data;
					if ( Array.isArray( groupData ) && groupData.length > 1 ) {
						state.lightboxGroupItems = groupData;
						state.lightboxCurrentIndex = 0;
						state.lightboxMediaData = groupData[ 0 ];
						loadLightboxMedia();
					}
				}

				actions.lightboxLoadSocial( { restUrl, nonce, isLoggedIn }, mediaId );
			} catch {
				state.lightboxLoading = false;
				actions.showToast( ( state.i18n?.failedLoad || 'Failed to load media.' ), 'error' );
			}
		},
		noop() {},
		async lightboxLoadSocial( ctx, mediaId ) {
			// Reactions.
			try {
				const r = await window.mvsRest.restFetch( ctx.restUrl + 'media/' + mediaId + '/reactions' );
				const rd = r.data;
				state.lightboxReactions = rd.counts || {};
				state.lightboxUserReaction = rd.user_reaction || '';
			} catch { /* ignore */ }
			// Comments (latest 20, total from the X-WP-Total response header,
			// which restFetch now exposes via result.headers).
			try {
				const c = await window.mvsRest.restFetch( ctx.restUrl + 'media/' + mediaId + '/comments?per_page=20' );
				state.lightboxTotalComments = parseInt( ( c.headers && c.headers.get( 'X-WP-Total' ) ) || '0', 10 );
				const cd = c.data;
				state.lightboxComments = Array.isArray( cd ) ? cd : [];
			} catch { state.lightboxComments = []; state.lightboxTotalComments = 0; }
			// Stats.
			try {
				const s = await window.mvsRest.restFetch( ctx.restUrl + 'media/' + mediaId + '/stats' );
				state.lightboxStats = s.data;
			} catch { state.lightboxStats = {}; }
			// Favorite status. Attempt unconditionally — the REST endpoint
			// enforces its own auth, returning 401 for guests which lands in
			// the catch and leaves the default `false`. The previous
			// ctx.isLoggedIn gate was driven by parsed.currentUserId, which
			// is not emitted into every per-card data-wp-context (only
			// mediaId/restUrl/nonce are guaranteed). That mismatch caused
			// the "lightbox heart never reflects server truth after refresh"
			// desync: server records the favorite correctly, but the
			// lightbox always boots with lightboxIsFavorited=false because
			// the GET was skipped.
			try {
				const f = await window.mvsRest.restFetch( ctx.restUrl + 'media/' + mediaId + '/favorite' );
				if ( f.ok ) {
					state.lightboxIsFavorited = !! f.data.favorited;
				} else {
					state.lightboxIsFavorited = false;
				}
			} catch { state.lightboxIsFavorited = false; }
		},
		async lightboxToggleReaction( event ) {
			const ctx = getContext();
			const type = event.target.closest( '[data-reaction]' )?.dataset.reaction;
			if ( ! type || ! state.lightboxMediaId ) return;
			const isActive = state.lightboxUserReaction === type;
			try {
				await window.mvsRest.restFetch( ctx.restUrl + 'media/' + state.lightboxMediaId + '/reactions', {
					method: isActive ? 'DELETE' : 'POST',
					body: { reaction_type: type },
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
			// Extension point (Pro multi-collection picker): cancelable event;
			// if handled, the picker opens and we skip the plain toggle.
			const mvsFavEl    = getElement()?.ref;
			const mvsFavEvent = new CustomEvent( 'mvs-favorite-click', {
				bubbles: true,
				cancelable: true,
				detail: { mediaId: state.lightboxMediaId },
			} );
			( mvsFavEl || document.body ).dispatchEvent( mvsFavEvent );
			if ( mvsFavEvent.defaultPrevented ) {
				return;
			}
			// Optimistic flip for snappy UI; roll back on error / verify
			// against server response. Previously the flip was unconditional
			// and the catch was silent — a 4xx response (rate-limit, auth
			// lapse) would leave the heart filled with no server record,
			// then a refresh would re-show it empty.
			const previous = !! state.lightboxIsFavorited;
			state.lightboxIsFavorited = ! previous;
			try {
				const res = await window.mvsRest.restFetch( ctx.restUrl + 'media/' + state.lightboxMediaId + '/favorite', {
					method: previous ? 'DELETE' : 'POST',
				} );
				if ( res.ok ) {
					// Trust server-authoritative value if present in body.
					const body = res.data;
					if ( body && typeof body.favorited === 'boolean' ) {
						state.lightboxIsFavorited = body.favorited;
					}
				} else {
					state.lightboxIsFavorited = previous;
				}
			} catch {
				state.lightboxIsFavorited = previous;
			}
		},
		// Open the Pro "Save to collection" picker for the current lightbox item.
		// Favoriting (the heart) and saving to a collection are deliberately
		// separate actions; this only announces the media id so the Pro picker
		// can open. No-op if Pro is not present.
		lightboxOpenCollections() {
			if ( ! state.lightboxMediaId ) return;
			const ref = getElement()?.ref;
			( ref || document.body ).dispatchEvent(
				new CustomEvent( 'mvs-collections-click', {
					bubbles: true,
					cancelable: false,
					detail: { mediaId: state.lightboxMediaId },
				} )
			);
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
			try {
				const r = await window.mvsRest.restFetch( ctx.restUrl + 'media/' + state.lightboxMediaId + '/comments', {
					method: 'POST',
					body: { content: text },
				} );
				const comment = r.data;
				if ( comment && comment.id ) {
					state.lightboxComments = [ ...state.lightboxComments, comment ];
					state.lightboxCommentText = '';
				}
			} catch {
				actions.showToast( ( state.i18n?.failedComment || 'Failed to post comment.' ), 'error' );
			}
		},
		async lightboxShare() {
			// Two valid surfaces, in priority order:
			//   1. navigator.share — native OS share sheet on mobile +
			//      Edge/Chrome desktop where supported. Lets the user copy
			//      OR send to any installed sharing target.
			//   2. navigator.clipboard.writeText — silent copy + toast.
			// We do NOT fall back to window.prompt() any more — that
			// surfaced an ugly browser-native dialog with a pre-selected
			// URL on top of our own UI; redundant with the toast pattern.
			// Modern browsers all expose at least one of the two APIs;
			// the rare case where both are missing now shows a toast
			// pointing the user at the permalink button instead.
			const data = state.lightboxMediaData;
			const url = data?.link || window.location.href;
			let shared = false;

			if ( navigator.share ) {
				try {
					await navigator.share( { title: state.lightboxTitle, url } );
					shared = true;
				} catch { /* user cancelled — silent */ }
			} else {
				// navigator.clipboard exists only in a secure context (HTTPS /
				// localhost); on plain http it is undefined, which left Share
				// dead with a "not supported" toast. Try the async clipboard
				// first, then fall back to a temp-textarea execCommand('copy')
				// so Share copies the link on http too.
				if ( navigator.clipboard?.writeText && window.isSecureContext ) {
					try {
						await navigator.clipboard.writeText( url );
						shared = true;
					} catch { /* fall through to execCommand */ }
				}
				if ( ! shared ) {
					try {
						const ta = document.createElement( 'textarea' );
						ta.value = url;
						ta.setAttribute( 'readonly', '' );
						ta.style.position = 'fixed';
						ta.style.top = '-1000px';
						ta.style.opacity = '0';
						document.body.appendChild( ta );
						ta.select();
						shared = document.execCommand( 'copy' );
						document.body.removeChild( ta );
					} catch { shared = false; }
				}
				actions.showToast(
					shared
						? ( state.i18n?.linkCopied || 'Link copied!' )
						: ( state.i18n?.copyFailed || 'Could not copy link. Use the Open button to view this media in a new tab.' ),
					shared ? 'success' : 'error'
				);
			}

			// Best-effort stat increment — non-blocking. Only counts as a
			// share when the user actually completed the share flow (didn't
			// cancel the native picker, didn't fail clipboard).
			if ( shared && data?.id ) {
				try {
					const restUrl = window.mvsBpActions?.restUrl
						|| ( window.location.origin + '/wp-json/mvs/v1/' );
					await window.mvsRest.restFetch( `${ restUrl }media/${ data.id }/share`, {
						method: 'POST',
					} );
				} catch {
					// Stat increment failure is non-blocking.
				}
			}
		},
		lightboxReport() {
			// Navigate to the single media page where the full report dialog lives.
			const url = state.lightboxMediaData?.link;
			if ( url ) {
				window.location.href = url + '#report';
			}
		},
		async lightboxDownload() {
			// Browser-native download via the file URL (signed URLs already
			// carry Content-Disposition headers from the storage driver).
			// Increments the downloads stat via the /event endpoint —
			// non-blocking on failure. Gracefully degrades when the item has
			// no downloadable file_url.
			const data = state.lightboxMediaData;
			if ( ! data || ! data.file_url ) {
				actions.showToast( ( state.i18n?.notDownloadable || 'This media is not available for download.' ), 'error' );
				return;
			}

			// Hidden anchor with the download attribute — the browser honors
			// Content-Disposition; falls back to client-side rename via the
			// download attr value.
			const a = document.createElement( 'a' );
			a.href = data.file_url;
			a.download = data.title || 'media';
			a.rel = 'noopener';
			document.body.appendChild( a );
			a.click();
			document.body.removeChild( a );

			// Best-effort stat increment — non-blocking.
			try {
				const restUrl = window.mvsBpActions?.restUrl
					|| ( window.location.origin + '/wp-json/mvs/v1/' );
				await window.mvsRest.restFetch( `${ restUrl }media/${ data.id }/download`, {
					method: 'POST',
				} );
			} catch {
				// Stat increment failure is non-blocking — the download still happened.
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
			// Pause any playing video/audio before closing.
			const video = document.querySelector( '.mvs-lightbox-video' );
			if ( video ) {
				video.pause();
				video.removeAttribute( 'src' );
			}
			const audio = document.querySelector( '.mvs-lightbox-audio' );
			if ( audio ) {
				audio.pause();
				audio.removeAttribute( 'src' );
			}
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
				if ( state.editModalVisible ) {
					actions.closeEditModal();
				} else if ( state.uploadModalVisible ) {
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
	callbacks: {
		// Inject trusted badge HTML (verified-member, VIP, etc.) into the
		// lightbox author sibling node. The Interactivity API has no built-in
		// HTML directive — `data-wp-text` would render markup as literal text
		// and there is no `data-wp-bind--inner-html`. Wiring this through
		// `data-wp-init` (initial render) + `data-wp-watch` (reactive on
		// lightbox media change) keeps it in sync as the user navigates
		// between media in a gallery.
		//
		// Source markup is server-controlled — built by the
		// `mvs_user_badge_html` PHP filter chain and surfaced as
		// `author_data.badge_html` in the REST response. Plugins hooking
		// that filter must return trusted markup; the lightbox treats it
		// the same way PHP templates treat the existing
		// `mvs_user_display_name` filter (rendered through `wp_kses_post()`).
		// We use DOMParser + `replaceChildren` instead of `innerHTML` to
		// keep the call site explicit about the parse-then-attach flow.
		renderAuthorBadge() {
			const { ref } = getElement();
			if ( ! ref ) {
				return;
			}
			const html = state.lightboxAuthorBadgeHtml || '';
			if ( '' === html ) {
				ref.replaceChildren();
				return;
			}
			const parsed = new DOMParser().parseFromString( html, 'text/html' );
			ref.replaceChildren( ...parsed.body.childNodes );
		},
	},
} );

// Bridge: expose openLightboxById to vanilla JS (used by load-more.js delegated click handler).
window.mvsOpenLightbox = function ( mediaId ) {
	actions.openLightboxById( mediaId );
};

/**
 * MVS router store — client-side navigation via @wordpress/interactivity-router.
 *
 * `state.clientNav` and `state.denyPaths` are seeded server-side by
 * templates/partials/router-region-open.php via wp_interactivity_state().
 * Do not define JS defaults here — the server seed is the source of truth.
 *
 * The navigate action is attached to the router-region wrapper via
 * data-wp-on--click="actions.navigate" so it delegates all internal link
 * clicks from a single persistent element and survives router swaps.
 */
const { state: mvsState } = store( 'mvs', {
	actions: {
		*navigate( event ) {
			const link = event.target?.closest?.( 'a' );
			const href = link?.href;
			if ( ! href ) return;
			if ( event.defaultPrevented ) return;
			// Grid-tile media links are owned by the lightbox handler
			// (assets/js/frontend/load-more.js): clicking a tile opens the
			// in-page lightbox and keeps the user on the current grid. Do NOT
			// client-navigate those — otherwise both handlers fire and the
			// lightbox stacks over an already-navigated single-media page
			// (the "close reveals the wrong page" / "overlay blocks clicks"
			// regression). Author and other links inside a card are not
			// .mvs-grid-item-link, so they still client-navigate normally.
			if ( link.classList.contains( 'mvs-grid-item-link' ) ) return;
			const rawHref = link.getAttribute( 'href' );
			if ( ! rawHref || '#' === rawHref.charAt( 0 ) ) return;
			if ( event.metaKey || event.ctrlKey || event.shiftKey || event.altKey ||
				event.button !== 0 || link.hasAttribute( 'download' ) ||
				( link.target && link.target !== '_self' ) ||
				link.origin !== window.location.origin ) return;
			// Feature flag (server-seeded). Disabled => native full-load.
			if ( ! mvsState.clientNav ) return;
			// Deny-list: routes that must full-load. mvsState.denyPaths is an array of
			// path-prefix strings seeded server-side (Task 6 populates it; treat an
			// empty/absent array as "deny nothing").
			const path = link.pathname || '';
			const deny = Array.isArray( mvsState.denyPaths ) ? mvsState.denyPaths : [];
			if ( deny.some( ( p ) => p && ( path === p || path.indexOf( p ) === 0 ) ) ) return;
			event.preventDefault();
			try {
				const router = yield import( '@wordpress/interactivity-router' );
				yield router.actions.navigate( href );
				// The router swaps in the fetched page's matching region. If the
				// target page has no [data-wp-router-region="mvs/main"] wrapper
				// (e.g. a Pro non-grid Explore layout whose template omits it),
				// the swap yields EMPTY content instead of throwing, leaving a
				// blank page. Detect the empty swap and fall back to a full load
				// so the user never sees a blank screen. (#10033416011)
				const region = document.querySelector( '[data-wp-router-region="mvs/main"]' );
				if ( ! region || region.children.length === 0 ) {
					window.location.href = href;
					return;
				}
				document.dispatchEvent( new CustomEvent( 'mvs:navigated', { detail: { href } } ) );
				if ( ! region.hasAttribute( 'tabindex' ) ) region.setAttribute( 'tabindex', '-1' );
				region.focus( { preventScroll: true } );
				window.scrollTo( 0, 0 );
			} catch ( e ) {
				window.location.href = href; // never strand the user
			}
		},
	},
} );

// Bridge: expose openEditModal to vanilla JS (used by bp-actions.js
// delegated click handler on .mvs-media-edit-btn).
window.mvsOpenEditModal = function ( mediaId ) {
	actions.openEditModal( mediaId );
};
