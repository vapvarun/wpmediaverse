/**
 * Interactivity API store for the media-upload block.
 *
 * @package WPMediaVerse
 */

import { store, getContext } from '@wordpress/interactivity';

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
	// One decimal, like the server's size_format( $bytes, 1 ), so the line reads
	// the same before and after an upload.
	return `${ unit ? value.toFixed( 1 ) : value } ${ units[ unit ] }`;
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
		get uploadPercentText() {
			const ctx = getContext();
			return ctx.uploading ? ( ctx.uploadPercent || 0 ) + '%' : '';
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
		// What the member picked, shown in the drop area (Basecamp 10364777491).
		get pickedLabel() {
			const ctx = getContext();
			const names = ctx.pendingNames || [];
			return names.length === 1
				? names[ 0 ]
				: ( state.i18n?.filesPicked || '%d files: ' ).replace( '%d', names.length ) + names.join( ', ' );
		},
		get pickedThumb() {
			return getContext().pickedThumb || '';
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
			// Don't trigger from the controls inside the dropzone (the file input,
			// privacy select, metadata fields, Confirm/Cancel).
			if ( event.target.closest( 'input, select, textarea, button, a' ) ) {
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
		// role="button" gives the dropzone a Tab stop; Enter/Space open the picker.
		handleKeydown( event ) {
			if ( event.target !== event.currentTarget ) {
				return;
			}
			if ( 'Enter' === event.key || ' ' === event.key ) {
				event.preventDefault();
				actions.handleClick( event );
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
			ctx.pendingNames = files.map( ( f ) => f.name );
			if ( ctx.pickedThumb ) URL.revokeObjectURL( ctx.pickedThumb );
			const firstImage = files.find( ( f ) => /^image\//.test( f.type ) );
			ctx.pickedThumb = firstImage ? URL.createObjectURL( firstImage ) : '';
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
			if ( ctx.pickedThumb ) URL.revokeObjectURL( ctx.pickedThumb );
			ctx.pickedThumb = '';
			ctx.pendingNames = [];
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
			if ( ctx.pickedThumb ) URL.revokeObjectURL( ctx.pickedThumb );
			ctx.pickedThumb = '';
			ctx.pendingNames = [];
			ctx.pendingCount = 0;
			ctx.uploading = true;
			ctx.uploadPercent = 0;
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
				try {
					const resp = await window.mvsRest.uploadMedia(
						ctx.restUrl,
						files[ i ],
						{
							privacy: ctx.privacy,
							title: ctx.uploadTitle,
							description: ctx.uploadDescription,
							tags: ctx.uploadTags,
							mediaGroup,
							groupPosition: i,
						},
						// Overall percent: finished files plus the share of this one sent.
						( loaded, total ) => {
							ctx.uploadPercent = Math.min( 100, Math.round( ( ( i + ( total ? loaded / total : 0 ) ) / files.length ) * 100 ) );
						}
					);
					if ( resp.ok ) {
						successCount++;
						const mediaData = resp.data;
						ctx.lastLink = ( mediaData && mediaData.link ) || '';
						if ( mediaData && mediaData.duplicate_warning ) {
							duplicateCount++;
							lastDuplicateId = mediaData.existing_media_id || 0;
						}
						// "Also share as a story" (Pro). Non-fatal, like every surface.
						if ( ctx.isStory && mediaData && mediaData.id ) {
							await window.mvsRest.markAsStory( mediaData.id );
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
				// A note beside the success, not a red error next to a green one.
				ctx.successMessage = ( ctx.successMessage ? ctx.successMessage + ' ' : '' )
					+ ( state.i18n?.duplicatesDetected || 'You had already uploaded %1$d of these files.' )
						.replace( '%1$d', duplicateCount );
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
