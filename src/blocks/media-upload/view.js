/**
 * Interactivity API store for the media-upload block.
 *
 * @package WPMediaVerse
 */

import { store, getContext } from '@wordpress/interactivity';

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
			const files = Array.from( event.dataTransfer.files ).slice( 0, ctx.maxFiles );
			if ( files.length ) {
				actions.uploadFiles( files );
			}
		},
		handleFileSelect( event ) {
			const ctx = getContext();
			const files = Array.from( event.target.files ).slice( 0, ctx.maxFiles );
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
			ctx.uploadMessage = `Uploading ${ files.length } file(s)...`;

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
					await fetch( ctx.restUrl, {
						method: 'POST',
						headers: { 'X-WP-Nonce': ctx.nonce },
						body: formData,
					} );
				} catch ( err ) {
					// Continue with remaining files.
				}
			}

			ctx.uploading = false;
			ctx.uploadMessage = 'Upload complete!';
			setTimeout( () => { ctx.uploadMessage = ''; }, 3000 );
		},
	},
} );
