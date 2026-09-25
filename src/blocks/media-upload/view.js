/**
 * Interactivity API store for the media-upload block.
 *
 * @package WPMediaVerse
 */

import { store, getContext } from '@wordpress/interactivity';

/**
 * Grab a poster frame from a video File and append it as `thumbnail`.
 *
 * MediaVerse builds a video poster server-side from an embedded cover atom or
 * an ffmpeg first-frame grab. On a host with no ffmpeg, a video with no cover
 * atom gets NO poster and the grid renders a blank tile with a play icon —
 * which reads to a member as "my video is broken". Capturing the frame in the
 * browser removes the ffmpeg dependency for the common case.
 * `MediaController::create_item()` already accepts the `thumbnail` file param
 * and hands it to `PosterService::stage_client_frame()`.
 *
 * This block and dashboard-view carry their own copy on purpose: these view
 * bundles are script MODULES, and a cross-file import is emitted as a bare
 * specifier the browser 404s on. shared-ui has a third variant that reuses the
 * frame it already decoded for its preview UI, so it isn't double-decoding.
 * Keep the three in sync — seek to 1s, fall back to frame 0 under 1s, 5s
 * timeout, never reject.
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
		// A file that never fires `seeked` (bad codec) must not hang the upload.
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

/**
 * Map of MIME types to human-readable labels for error messages.
 */
const MIME_LABELS = {
	'image/jpeg': 'JPEG',
	'image/png': 'PNG',
	'image/gif': 'GIF',
	'image/webp': 'WebP',
	'video/mp4': 'MP4',
	'video/webm': 'WebM',
	'audio/mpeg': 'MP3',
	'audio/ogg': 'OGG',
};

/**
 * Check whether a file matches the server-configured allowed MIME types.
 *
 * Falls back to broad prefix matching (image/, video/, audio/) when the
 * browser does not report a MIME type for the file.
 *
 * @param {File}     file         File to check.
 * @param {string[]} allowedTypes Array of allowed MIME strings from context.
 * @return {boolean} True when file type is allowed.
 */
function isAllowedFile( file, allowedTypes ) {
	if ( ! file.type ) {
		return false;
	}
	if ( allowedTypes && allowedTypes.length ) {
		return allowedTypes.includes( file.type );
	}
	// Fallback: allow any image/video/audio when server config is missing.
	return [ 'image/', 'video/', 'audio/' ].some( ( p ) => file.type.startsWith( p ) );
}

/**
 * Build a human-readable label string from the allowed MIME list.
 *
 * @param {string[]} allowedTypes Array of allowed MIME strings.
 * @return {string} Formatted label like "JPEG, PNG, GIF, WebP, MP4, WebM, MP3, OGG".
 */
function formatAllowedLabels( allowedTypes ) {
	if ( ! allowedTypes || ! allowedTypes.length ) {
		return ( state.i18n?.allowedFallback || 'images, videos, and audio files' );
	}
	return allowedTypes
		.map( ( mime ) => MIME_LABELS[ mime ] || mime.split( '/' ).pop().toUpperCase() )
		.join( ', ' );
}

/**
 * Validate files against allowed types. Sets ctx.uploadError for rejected files.
 *
 * @param {File[]} files Array of files to validate.
 * @param {Object} ctx   Interactivity API context.
 * @return {File[]} Only the files that passed validation.
 */
function filterFiles( files, ctx ) {
	const valid = [];
	const rejected = [];
	for ( const file of files ) {
		if ( isAllowedFile( file, ctx.allowedTypes ) ) {
			valid.push( file );
		} else {
			rejected.push( file.name );
		}
	}
	if ( rejected.length ) {
		const allowed = formatAllowedLabels( ctx.allowedTypes );
		ctx.uploadError = ( state.i18n?.fileTypeNotAllowed || 'File type not allowed: %1$s. Supported formats: %2$s.' )
			.replace( '%1$s', rejected.join( ', ' ) )
			.replace( '%2$s', allowed );
	}
	return valid;
}

function formatBytes( bytes ) {
	const units = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
	let value = Number( bytes ) || 0;
	let unit = 0;
	while ( value >= 1024 && unit < units.length - 1 ) {
		value /= 1024;
		unit++;
	}
	return `${ unit ? value.toFixed( 1 ).replace( /\.0$/, '' ) : value } ${ units[ unit ] }`;
}

const { state, actions } = store( 'mvs/media-upload', {
	state: {
		get isDragOver() {
			return getContext().dragOver;
		},
		get isUploading() {
			return getContext().uploading;
		},
		get uploadStatus() {
			const ctx = getContext();
			return ctx.uploading ? ( ctx.uploadMessage || ( state.i18n?.uploading || 'Uploading...' ) ) : '';
		},
		get hasSuccess() {
			const ctx = getContext();
			return ! ctx.uploading && !! ctx.successMessage;
		},
		get successText() {
			return getContext().successMessage || '';
		},
		get hasError() {
			return !! getContext().uploadError;
		},
		get errorMessage() {
			return getContext().uploadError || '';
		},
		get hasPending() {
			return !! getContext().hasPending;
		},
		get pendingLabel() {
			const n = getContext().pendingCount || 0;
			return n === 1
				? ( state.i18n?.uploadOneFile || 'Upload 1 file' )
				: ( state.i18n?.uploadNFiles || 'Upload %d files' ).replace( '%d', n );
		},
	},
	actions: {
		handleClick( event ) {
			// Don't trigger if clicking the file input itself, privacy select, or metadata fields.
			if ( event.target.closest( 'input, select, textarea' ) ) {
				return;
			}
			const dropzone = event.target.closest( '.mvs-upload-dropzone' );
			if ( ! dropzone ) {
				return;
			}
			const fileInput = dropzone.querySelector( '.mvs-upload-input' );
			if ( fileInput ) {
				fileInput.click();
			}
		},
		handleDragOver( event ) {
			event.preventDefault();
			const ctx = getContext();
			ctx.dragOver = true;
		},
		handleDragLeave() {
			const ctx = getContext();
			ctx.dragOver = false;
		},
		handleDrop( event ) {
			event.preventDefault();
			const ctx = getContext();
			ctx.dragOver = false;
			ctx.uploadError = '';
			const files = filterFiles(
				Array.from( event.dataTransfer.files ).slice( 0, ctx.maxFiles ),
				ctx
			);
			if ( files.length ) {
				actions.stageFiles( files );
			}
		},
		handleFileSelect( event ) {
			const ctx = getContext();
			ctx.uploadError = '';
			const files = filterFiles(
				Array.from( event.target.files ).slice( 0, ctx.maxFiles ),
				ctx
			);
			// Reset input so re-selecting the same file triggers change again.
			event.target.value = '';
			if ( files.length ) {
				actions.stageFiles( files );
			}
		},
		// Hold selected files and reveal the review step instead of uploading
		// immediately, so the user can fill in title/description/tags/privacy
		// before the upload starts. Files are kept on the per-instance context
		// (not a module variable) so multiple upload blocks don't clobber each
		// other.
		stageFiles( files ) {
			const ctx = getContext();
			ctx.pendingFiles = files;
			ctx.pendingCount = files.length;
			ctx.hasPending = true;
			ctx.successMessage = '';
		},
		confirmUpload() {
			const ctx = getContext();
			const files = ctx.pendingFiles || [];
			if ( ! files.length ) {
				return;
			}
			actions.uploadFiles( files );
		},
		cancelPending() {
			const ctx = getContext();
			ctx.pendingFiles = [];
			ctx.pendingCount = 0;
			ctx.hasPending = false;
			ctx.uploadError = '';
			const fileInput = document.querySelector( '.mvs-upload-block input[type="file"]' );
			if ( fileInput ) {
				fileInput.value = '';
			}
		},
		setPrivacy( event ) {
			const ctx = getContext();
			ctx.privacy = event.target.value;
		},
		toggleStory( event ) {
			const ctx = getContext();
			ctx.isStory = !! event.target.checked;
		},
		setTitle( event ) {
			const ctx = getContext();
			ctx.uploadTitle = event.target.value;
		},
		setDescription( event ) {
			const ctx = getContext();
			ctx.uploadDescription = event.target.value;
		},
		setTags( event ) {
			const ctx = getContext();
			ctx.uploadTags = event.target.value;
		},
		dismissError() {
			const ctx = getContext();
			ctx.uploadError = '';
		},
		async uploadFiles( files ) {
			const ctx = getContext();
			// Leave the review step now that the upload is confirmed.
			ctx.hasPending = false;
			ctx.pendingFiles = [];
			ctx.pendingCount = 0;
			ctx.uploading = true;
			ctx.successMessage = '';
			ctx.uploadError = '';
			ctx.uploadMessage = ( state.i18n?.uploadingNFiles || 'Uploading %d file(s)...' ).replace( '%d', files.length );
			let successCount = 0;
			let duplicateCount = 0;
			let lastDuplicateId = 0;

			// Tie a multi-file selection together so the BuddyPress activity
			// sync emits ONE carousel item instead of one feed row per file.
			// The upload modal (shared-ui) has always sent this; this block and
			// the dashboard did not, so uploading N files here posted N
			// separate activity rows. Same key shape as shared-ui.
			const mediaGroup =
				files.length > 1
					? 'grp_' + Date.now() + '_' + Math.random().toString( 36 ).slice( 2, 8 )
					: null;

			for ( let i = 0; i < files.length; i++ ) {
				ctx.uploadMessage = ( state.i18n?.uploadingProgress || 'Uploading %1$d of %2$d...' )
					.replace( '%1$d', i + 1 )
					.replace( '%2$d', files.length );
				const formData = new FormData();
				formData.append( 'file', files[ i ] );
				// Capture a poster frame for videos. Without this a video
				// uploaded here has no poster at all on a host with no ffmpeg,
				// and the grid renders a blank tile. The upload modal has
				// always done this; this surface did not.
				await appendVideoPoster( formData, files[ i ] );
				if ( mediaGroup ) {
					formData.append( 'media_group', mediaGroup );
					formData.append( 'group_position', String( i ) );
				}
				if ( ctx.privacy ) {
					formData.append( 'privacy', ctx.privacy );
				}
				if ( ctx.uploadTitle ) {
					formData.append( 'title', ctx.uploadTitle );
				}
				if ( ctx.uploadDescription ) {
					formData.append( 'description', ctx.uploadDescription );
				}
				if ( ctx.uploadTags ) {
					const tags = ctx.uploadTags.split( ',' ).map( ( t ) => t.trim() ).filter( Boolean );
					tags.forEach( ( tag ) => formData.append( 'tags[]', tag ) );
				}

				try {
					const resp = await window.mvsRest.restFetch( ctx.restUrl, {
						method: 'POST',
						body: formData,
					} );
					if ( resp.ok ) {
						successCount++;
						const mediaData = resp.data;
						ctx.lastLink = ( mediaData && mediaData.link ) || '';
						if ( mediaData && mediaData.duplicate_warning ) {
							duplicateCount++;
							lastDuplicateId = mediaData.existing_media_id || 0;
						}
						// "Also share as a story" — mark the new media as a 24h story (Pro).
						if ( ctx.isStory && mediaData && mediaData.id ) {
							const storyUrl =
								ctx.restUrl.replace( '/mvs/v1/', '/mvs-pro/v1/' ) + '/' + mediaData.id + '/story';
							try {
								await window.mvsRest.restFetch( storyUrl, { method: 'POST', body: {} } );
							} catch ( e ) {
								// Non-fatal: media uploaded even if the story flag failed.
							}
						}
					} else {
						const err = resp.data || {};
						ctx.uploadError = err.message || ( state.i18n?.uploadFailedFor || 'Upload failed for %s.' ).replace( '%s', files[ i ].name );
					}
				} catch ( err ) {
					ctx.uploadError = ( state.i18n?.networkError || 'Network error uploading %s.' ).replace( '%s', files[ i ].name );
				}
			}

			ctx.uploading = false;
			ctx.uploadMessage = '';
			// "View it" only makes sense for a single file; several go to My Media.
			if ( successCount !== 1 ) {
				ctx.lastLink = '';
			}
			if ( successCount === files.length ) {
				ctx.successMessage = ( state.i18n?.uploadSuccess || '%d file(s) uploaded successfully!' ).replace( '%d', successCount );
			} else if ( successCount > 0 ) {
				ctx.successMessage = ( state.i18n?.uploadPartial || '%1$d of %2$d file(s) uploaded.' )
					.replace( '%1$d', successCount )
					.replace( '%2$d', files.length );
			} else {
				ctx.successMessage = '';
			}
			if ( duplicateCount > 0 ) {
				ctx.uploadError = ( state.i18n?.duplicatesDetected || '%1$d duplicate file(s) detected. Existing media #%2$d already contains this content.' )
					.replace( '%1$d', duplicateCount )
					.replace( '%2$d', lastDuplicateId );
			}

			// Bring the notice to the member. The error box sits at the bottom of
			// the form, and on this page that is BELOW the fold - measured 123px
			// past a 840px viewport with the page still at scrollY 0. The warning
			// rendered correctly and was simply never seen, which reads as "the
			// duplicate warning is not displayed". Basecamp 10280477806.
			if ( ctx.uploadError ) {
				// Deferred twice: setting ctx.uploadError only QUEUES the render
				// that removes the box's `hidden` attribute, and an element with
				// no layout box cannot be scrolled to - calling scrollIntoView
				// synchronously here does nothing at all.
				requestAnimationFrame( () => {
					requestAnimationFrame( () => {
						const el = document.querySelector( '.mvs-upload-error' );
						if ( el && ! el.hasAttribute( 'hidden' ) && typeof el.scrollIntoView === 'function' ) {
							el.scrollIntoView( { block: 'center', behavior: 'smooth' } );
						}
					} );
				} );
			}

			// Reset form fields after upload.
			if ( successCount > 0 ) {
				ctx.uploadTitle = '';
				ctx.uploadDescription = '';
				ctx.uploadTags = '';
				const fileInput = document.querySelector( '.mvs-upload-block input[type="file"]' );
				if ( fileInput ) {
					fileInput.value = '';
				}
			}
			// "Used X of Y" (shown only when a storage limit applies) follows
			// the upload without a reload.
			const usageLine = document.querySelector( '[data-mvs-storage-usage]' );
			if ( usageLine && successCount > 0 ) {
				try {
					const usage = await window.mvsRest.restFetch( ctx.restUrl.replace( /mvs\/v1\/.*$/, 'mvs/v1/me/storage' ) );
					if ( usage.ok && usage.data && usage.data.limit ) {
						usageLine.textContent = ( state.i18n?.storageUsed || 'Used %1$s of %2$s' )
							.replace( '%1$s', formatBytes( usage.data.used ) )
							.replace( '%2$s', formatBytes( usage.data.limit ) );
					}
				} catch {
					// Usage refresh is non-critical.
				}
			}
		},
	},
} );
