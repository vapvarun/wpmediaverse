/**
 * Interactivity API store for the media-upload block.
 *
 * @package WPMediaVerse
 */

import { store, getContext } from '@wordpress/interactivity';

// Allowed MIME prefixes for client-side validation.
const ALLOWED_PREFIXES = [ 'image/', 'video/', 'audio/' ];

function isAllowedFile( file ) {
	return ALLOWED_PREFIXES.some( ( prefix ) => file.type.startsWith( prefix ) );
}

function filterFiles( files, ctx ) {
	const valid = [];
	const rejected = [];
	for ( const file of files ) {
		if ( isAllowedFile( file ) ) {
			valid.push( file );
		} else {
			rejected.push( file.name );
		}
	}
	if ( rejected.length ) {
		ctx.uploadError = `File type not allowed: ${ rejected.join( ', ' ) }. Only images, videos, and audio files are accepted.`;
		setTimeout( () => { ctx.uploadError = ''; }, 5000 );
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
			if ( ! ctx.uploading ) return '';
			return ctx.uploadMessage || 'Uploading...';
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
			const files = filterFiles(
				Array.from( event.target.files ).slice( 0, ctx.maxFiles ),
				ctx
			);
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
		async uploadFiles( files ) {
			const ctx = getContext();
			ctx.uploading = true;
			ctx.uploadError = '';
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
			if ( successCount === files.length ) {
				ctx.uploadMessage = `${ successCount } file(s) uploaded successfully!`;
			} else if ( successCount > 0 ) {
				ctx.uploadMessage = `${ successCount } of ${ files.length } file(s) uploaded.`;
			} else {
				ctx.uploadMessage = '';
			}
			setTimeout( () => { ctx.uploadMessage = ''; }, 4000 );
		},
	},
} );
