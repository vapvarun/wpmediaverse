/**
 * All Media admin list: bulk select-all toggle + bulk-delete confirmation.
 * Replaces the former inline <script>. The "no media selected" notice uses the
 * mvsToast helper and the permanent-delete confirmation uses the shared
 * mvsConfirm modal instead of native alert()/confirm(). Strings come from
 * window.mvsMediaList (wp_localize_script).
 *
 * @package WPMediaVerse
 */
( function () {
	'use strict';

	var i18n = ( window.mvsMediaList || {} ).i18n || {};
	var rowSelector = 'input[type="checkbox"][name="media_ids[]"]';

	// Select-all toggle (header + footer checkboxes both drive the row inputs).
	document.addEventListener( 'change', function ( event ) {
		if ( event.target && event.target.classList && event.target.classList.contains( 'mvs-cb-select-all' ) ) {
			var checked = event.target.checked;
			document.querySelectorAll( rowSelector ).forEach( function ( cb ) {
				cb.checked = checked;
			} );
			document.querySelectorAll( '.mvs-cb-select-all' ).forEach( function ( cb ) {
				cb.checked = checked;
			} );
		}
	} );

	function notify( msg ) {
		// mvsToast is enqueued as a hard dependency. If absent, fail
		// silently — admin-ux-rulebook Rule 10 bans alert() even as
		// a fallback. The underlying form state is still visible to the
		// admin without a popup.
		if ( typeof window.mvsToast === 'function' ) {
			window.mvsToast( msg, 'error' );
		}
	}

	function submitForm( form ) {
		// The PHP handler keys off $_GET['do_bulk'] (the submit button name), so
		// re-add it before the programmatic submit, which omits button values.
		var hidden = document.createElement( 'input' );
		hidden.type = 'hidden';
		hidden.name = 'do_bulk';
		hidden.value = '1';
		form.appendChild( hidden );
		form.submit();
	}

	var bulkForm = document.getElementById( 'mvs-do-bulk' );
	if ( bulkForm ) {
		var form = bulkForm.closest( 'form' );
		form.addEventListener( 'submit', function ( event ) {
			var sel = document.getElementById( 'mvs-bulk-action' );
			if ( ! sel || sel.value !== 'bulk_delete' ) {
				return;
			}
			event.preventDefault();
			var checked = document.querySelectorAll( rowSelector + ':checked' );
			if ( checked.length === 0 ) {
				notify( i18n.noMedia || '' );
				return;
			}
			var msg = i18n.confirmDelete || '';
			// mvsConfirm is a hard dependency; fail closed when absent.
			if ( typeof window.mvsConfirm !== 'function' ) {
				return;
			}
			window.mvsConfirm( msg, { tone: 'destructive' } ).then( function ( ok ) {
				if ( ok ) {
					submitForm( form );
				}
			} );
		} );
	}
} )();
