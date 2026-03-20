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
			ctx.uploadMessage = `Uploading ${ files.length } file(s)...`;
			let successCount = 0;

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
			setTimeout( () => { ctx.successMessage = ''; }, 4000 );
		},
	},
} );
