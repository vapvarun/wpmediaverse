/**
 * BuddyPress integration — per-item action handlers.
 *
 * Wires up the delete / edit buttons rendered on .mvs-grid-item cards
 * inside .mvs-bp-screen. Uses event delegation so it covers items added
 * after page load by the infinite-scroll builder.
 *
 * REST endpoints used:
 *   DELETE /wp-json/mvs/v1/media/{id}    — delete a single media item
 *   DELETE /wp-json/mvs/v1/albums/{id}   — delete an album (media kept)
 *
 * Expects `window.mvsBpActions` (localized by the PHP enqueue) with
 *   { restUrl: string, nonce: string }.
 */
( function () {
	'use strict';

	var cfg = window.mvsBpActions || {};
	if ( ! cfg.restUrl || ! cfg.nonce ) {
		// Not on a BP screen that enqueued the config — nothing to do.
		return;
	}

	/**
	 * Confirmation prompt — uses the styled mvsConfirm modal when available
	 * (mvs-confirm script is a declared dep so it should always be loaded);
	 * falls back to window.confirm() so the destructive flow never silently
	 * proceeds if the helper failed to load. Returns Promise<boolean>.
	 *
	 * @param {string} message
	 * @return {Promise<boolean>}
	 */
	function confirmAction( message ) {
		// mvsConfirm is a hard dependency on every surface this script
		// runs on. Fail closed when absent — admin-ux-rulebook Rule 10
		// bans native confirm() even as a fallback.
		if ( typeof window.mvsConfirm !== 'function' ) {
			return Promise.resolve( false );
		}
		var i18n = window.mvsConfirmI18n || {};
		return window.mvsConfirm( message, {
			confirmLabel: i18n.delete || 'Delete',
			cancelLabel:  i18n.cancel || 'Cancel',
			tone:         'destructive',
		} );
	}

	function apiDelete( path ) {
		return window.mvsRest.restFetch( cfg.restUrl + path, {
			method: 'DELETE',
		} ).then( function ( res ) {
			if ( res.status === 204 || res.ok ) {
				return { ok: true };
			}
			var body = res.data;
			return { ok: false, error: body && body.message ? body.message : 'Delete failed.' };
		} );
	}

	function removeCard( btn ) {
		var card = btn.closest( '.mvs-grid-item' );
		if ( card ) {
			card.style.opacity = '0';
			card.style.transition = 'opacity 0.2s ease';
			window.setTimeout( function () {
				card.parentNode && card.parentNode.removeChild( card );
			}, 220 );
		}
	}

	function toast( message, type ) {
		if ( window.mvsSharedUI && typeof window.mvsSharedUI.showToast === 'function' ) {
			window.mvsSharedUI.showToast( message, type || 'info' );
			return;
		}
		// Silent fallback — the card removal is its own success signal.
	}

	document.addEventListener( 'click', function ( event ) {
		// Media edit (settings cog) — bridges to the Interactivity API
		// store via window.mvsOpenEditModal so the modal renders / fetches
		// / saves through the shared-ui state, not duplicate vanilla code.
		var mediaEdit = event.target.closest( '.mvs-media-edit-btn' );
		if ( mediaEdit ) {
			event.preventDefault();
			event.stopPropagation();
			var editId = mediaEdit.dataset.mediaId;
			if ( ! editId ) { return; }
			if ( typeof window.mvsOpenEditModal === 'function' ) {
				window.mvsOpenEditModal( editId );
			}
			return;
		}

		// Media delete.
		var mediaDel = event.target.closest( '.mvs-media-delete-btn' );
		if ( mediaDel ) {
			event.preventDefault();
			event.stopPropagation();
			var mediaId = mediaDel.dataset.mediaId;
			if ( ! mediaId ) { return; }
			confirmAction( 'Delete this media? This cannot be undone.' ).then( function ( ok ) {
				if ( ! ok ) { return; }
				mediaDel.disabled = true;
				apiDelete( 'media/' + mediaId ).then( function ( res ) {
					if ( res.ok ) {
						removeCard( mediaDel );
						toast( 'Media deleted.', 'success' );
					} else {
						mediaDel.disabled = false;
						toast( res.error, 'error' );
					}
				} );
			} );
			return;
		}

		// Album delete.
		var albumDel = event.target.closest( '.mvs-bp-album-delete' );
		if ( albumDel ) {
			event.preventDefault();
			event.stopPropagation();
			var albumId = albumDel.dataset.albumId;
			if ( ! albumId ) { return; }
			confirmAction( 'Delete this album? Media items inside it will remain in your library.' ).then( function ( ok ) {
				if ( ! ok ) { return; }
				albumDel.disabled = true;
				apiDelete( 'albums/' + albumId ).then( function ( res ) {
					if ( res.ok ) {
						removeCard( albumDel );
						toast( 'Album deleted.', 'success' );
					} else {
						albumDel.disabled = false;
						toast( res.error, 'error' );
					}
				} );
			} );
			return;
		}

		// Album edit — navigate to the single-album view where the owner
		// already has a full edit surface. Keeps the scope of this bundle
		// focused on the destructive action.
		var albumEdit = event.target.closest( '.mvs-bp-album-edit' );
		if ( albumEdit ) {
			event.preventDefault();
			event.stopPropagation();
			var card = albumEdit.closest( '.mvs-grid-item' );
			var link = card && card.querySelector( '.mvs-grid-item-link' );
			if ( link && link.href ) {
				window.location.href = link.href;
			}
		}
	} );
} )();
