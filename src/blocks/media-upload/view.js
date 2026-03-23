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
		return 'images, videos, and audio files';
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
		ctx.uploadError = `File type not allowed: ${ rejected.join( ', ' ) }. Supported formats: ${ allowed }.`;
	}
	return valid;
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
			return ctx.uploading ? ( ctx.uploadMessage || 'Uploading...' ) : '';
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
				actions.uploadFiles( files );
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
				actions.uploadFiles( files );
			}
		},
		setPrivacy( event ) {
			const ctx = getContext();
			ctx.privacy = event.target.value;
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
			ctx.uploading = true;
			ctx.successMessage = '';
			ctx.uploadError = '';
			ctx.uploadMessage = `Uploading ${ files.length } file(s)...`;
			let successCount = 0;

			// Pre-upload quota check (Pro only — endpoint may not exist).
			try {
				const quotaCheckUrl = ctx.restUrl.replace( /\/media\/?$/, '' ).replace( /mvs\/v1\/?$/, 'mvs-pro/v1/me/quota/check' );
				const file = files[ 0 ];
				const mimeType = file.type || 'image/jpeg';
				const mediaType = mimeType.startsWith( 'video/' ) ? 'video' : ( mimeType.startsWith( 'audio/' ) ? 'audio' : 'image' );
				const checkResp = await fetch(
					quotaCheckUrl + `?media_type=${ mediaType }&file_size=${ file.size }`,
					{
						headers: { 'X-WP-Nonce': ctx.nonce },
						credentials: 'same-origin',
					}
				);
				if ( checkResp.ok ) {
					const checkData = await checkResp.json();
					if ( checkData.can_upload === false ) {
						ctx.uploading = false;
						ctx.uploadMessage = '';
						ctx.uploadError = checkData.reason || 'Upload limit reached. Please upgrade your plan.';
						return;
					}
				}
				// 404 = Pro not active, skip check.
			} catch {
				// Pro endpoint not available — proceed without check.
			}

			for ( let i = 0; i < files.length; i++ ) {
				ctx.uploadMessage = `Uploading ${ i + 1 } of ${ files.length }...`;
				const formData = new FormData();
				formData.append( 'file', files[ i ] );
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
					const resp = await fetch( ctx.restUrl, {
						method: 'POST',
						headers: { 'X-WP-Nonce': ctx.nonce },
						credentials: 'same-origin',
						body: formData,
					} );
					if ( resp.ok ) {
						successCount++;
					} else {
						const err = await resp.json().catch( () => ( {} ) );
						ctx.uploadError = err.message || `Upload failed for ${ files[ i ].name }.`;
					}
				} catch ( err ) {
					ctx.uploadError = `Network error uploading ${ files[ i ].name }.`;
				}
			}

			ctx.uploading = false;
			ctx.uploadMessage = '';
			if ( successCount === files.length ) {
				ctx.successMessage = `${ successCount } file(s) uploaded successfully!`;
			} else if ( successCount > 0 ) {
				ctx.successMessage = `${ successCount } of ${ files.length } file(s) uploaded.`;
			} else {
				ctx.successMessage = '';
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
			// Refresh quota widget if present.
		const quotaWidget = document.querySelector( '.mvs-quota-widget' );
		if ( quotaWidget && successCount > 0 ) {
			try {
				const quotaResp = await fetch(
					ctx.restUrl.replace( '/media', '-pro/v1/me/quota/check' ) + '?media_type=image&file_size=0',
					{
						headers: { 'X-WP-Nonce': ctx.nonce },
						credentials: 'same-origin',
					}
				);
				if ( quotaResp.ok ) {
					const quotaData = await quotaResp.json();
					const summary = quotaData.summary;
					if ( summary ) {
						const rows = quotaWidget.querySelectorAll( '.mvs-quota-row' );
						const typeMap = [ 'image', 'video', 'audio' ];
						rows.forEach( ( row, i ) => {
							const type = typeMap[ i ];
							const data = summary[ type ];
							if ( ! data ) {
								return;
							}
							const countEl = row.querySelector( '.mvs-quota-count' );
							if ( countEl && ! data.unlimited ) {
								countEl.textContent = `${ data.used } / ${ data.total }`;
							}
							const fillEl = row.querySelector( '.mvs-quota-fill' );
							if ( fillEl && ! data.unlimited && data.total > 0 ) {
								fillEl.style.width = `${ Math.min( 100, Math.round( ( data.used / data.total ) * 100 ) ) }%`;
							}
						} );
						// Update storage row (last row).
						if ( summary.storage && ! summary.storage.unlimited && rows.length > typeMap.length ) {
							const storageRow = rows[ typeMap.length ];
							const countEl = storageRow.querySelector( '.mvs-quota-count' );
							if ( countEl ) {
								const usedMB = ( summary.storage.used / ( 1024 * 1024 ) ).toFixed( 0 );
								const limitGB = ( summary.storage.limit / ( 1024 * 1024 * 1024 ) ).toFixed( 0 );
								countEl.textContent = `${ usedMB } MB / ${ limitGB } GB`;
							}
						}
					}
				}
			} catch {
				// Quota refresh is non-critical.
			}
		}
		setTimeout( () => { ctx.successMessage = ''; }, 8000 );
		},
	},
} );
