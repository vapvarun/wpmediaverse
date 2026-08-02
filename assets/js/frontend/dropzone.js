/**
 * Shared file-dropzone behaviour for the vanilla (non-Interactivity) uploaders.
 *
 * Single owner of: open/cancel panel toggling, click-to-pick, drag feedback,
 * drop, and file-input change. Callers supply only `onFiles(files)` — the
 * upload flow itself stays with the caller, because the album uploader and the
 * BuddyPress tab uploader post to different endpoints with different payloads.
 *
 * Consumers (do not add a third — extend this instead):
 *   - assets/js/frontend/album-upload.js   (single-album page)
 *   - assets/js/frontend/bp-tab-upload.js  (BP profile tab, group tab, BP album)
 *
 * The Interactivity-API surfaces (shared-ui upload modal, dashboard dropzone)
 * do NOT use this module — they get `data-wp-on--drop` / `data-wp-on--dragover`
 * directives, which the iAPI router re-hydrates on every region swap.
 *
 * NAV-SAFETY: every listener is delegated on `document` and every element is
 * resolved at dispatch time from its id. The iAPI router morphs/replaces region
 * nodes on a client-side navigation, so anything holding an element reference
 * from module-eval time is dead after the first swap — the hazard documented in
 * load-more.js. Registration only records ids; it never touches the DOM.
 *
 * @package WPMediaVerse
 * @since   2.3.0
 */
( function () {
	'use strict';

	// Registered zones, keyed by dropzone element id so a re-register replaces
	// rather than stacks (a template that runs twice must not double-upload).
	var zones = {};

	function byId( id ) {
		return id ? document.getElementById( id ) : null;
	}

	// Resolve the zone whose dropzone/button/cancel/input the event came from.
	function zoneFor( e, key ) {
		var ids = Object.keys( zones );
		for ( var i = 0; i < ids.length; i++ ) {
			var z = zones[ ids[ i ] ];
			if ( z[ key ] && e.target.closest && e.target.closest( '#' + z[ key ] ) ) {
				return z;
			}
		}
		return null;
	}

	function openPanel( z ) {
		var wrap = byId( z.wrap );
		var btn = byId( z.btn );
		if ( wrap ) {
			wrap.style.display = 'block';
		}
		if ( btn ) {
			btn.style.display = 'none';
		}
	}

	function closePanel( z ) {
		var wrap = byId( z.wrap );
		var btn = byId( z.btn );
		var preview = byId( z.preview );
		var status = byId( z.status );
		if ( wrap ) {
			wrap.style.display = 'none';
		}
		if ( btn ) {
			btn.style.display = '';
		}
		if ( preview ) {
			preview.textContent = '';
		}
		if ( status ) {
			status.style.display = 'none';
		}
	}

	var clicking = false;

	document.addEventListener( 'click', function ( e ) {
		if ( ! e.target.closest ) {
			return;
		}

		var open = zoneFor( e, 'btn' );
		if ( open ) {
			openPanel( open );
			return;
		}

		var cancel = zoneFor( e, 'cancel' );
		if ( cancel ) {
			closePanel( cancel );
			return;
		}

		var z = zoneFor( e, 'dropzone' );
		if ( ! z ) {
			return;
		}
		// The dropzone doubles as a file picker. Guard the synthetic click so a
		// single user click cannot open two file dialogs.
		e.preventDefault();
		e.stopPropagation();
		if ( clicking ) {
			return;
		}
		clicking = true;
		var input = byId( z.input );
		if ( input ) {
			input.click();
		}
		setTimeout( function () {
			clicking = false;
		}, 100 );
	} );

	// dragenter AND dragover must BOTH be cancelled or the drop never fires.
	// Firefox is strictest here and otherwise navigates the tab to the dropped
	// file. The active class is `is-dragover`; the previous
	// `mvs-bp-dropzone--active` matched no CSS rule anywhere, which is why
	// there was no drag feedback on any of these uploaders.
	function setDrag( e, on ) {
		var z = zoneFor( e, 'dropzone' );
		if ( ! z ) {
			return null;
		}
		var el = byId( z.dropzone );
		if ( el ) {
			el.classList[ on ? 'add' : 'remove' ]( 'is-dragover' );
		}
		return z;
	}

	document.addEventListener( 'dragenter', function ( e ) {
		if ( setDrag( e, true ) ) {
			e.preventDefault();
		}
	} );
	document.addEventListener( 'dragover', function ( e ) {
		if ( setDrag( e, true ) ) {
			e.preventDefault();
		}
	} );
	document.addEventListener( 'dragleave', function ( e ) {
		setDrag( e, false );
	} );
	document.addEventListener( 'drop', function ( e ) {
		var z = setDrag( e, false );
		if ( ! z ) {
			return;
		}
		e.preventDefault();
		z.onFiles( Array.prototype.slice.call( e.dataTransfer.files ) );
	} );

	document.addEventListener( 'change', function ( e ) {
		var z = zoneFor( e, 'input' );
		if ( ! z ) {
			return;
		}
		var input = byId( z.input );
		z.onFiles( Array.prototype.slice.call( input.files ) );
		input.value = '';
	} );

	window.mvsDropzone = {
		/**
		 * Register a dropzone.
		 *
		 * @param {Object}   opts           Element ids + callback.
		 * @param {string}   opts.dropzone  Dropzone element id (required, also the registry key).
		 * @param {string}   opts.input     File input id.
		 * @param {string}   [opts.btn]     "Add media" button id — opens the panel.
		 * @param {string}   [opts.wrap]    Panel wrapper id.
		 * @param {string}   [opts.cancel]  Cancel button id — closes the panel.
		 * @param {string}   [opts.status]  Status element id.
		 * @param {string}   [opts.preview] Preview container id.
		 * @param {Function} opts.onFiles   Receives an Array of File objects.
		 */
		register: function ( opts ) {
			if ( ! opts || ! opts.dropzone || typeof opts.onFiles !== 'function' ) {
				return;
			}
			zones[ opts.dropzone ] = opts;
		},
	};
} )();
