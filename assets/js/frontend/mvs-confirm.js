/**
 * Frontend confirmation modal — promise-based replacement for window.confirm().
 *
 * Exposes window.mvsConfirm( message, opts ) returning Promise<boolean>:
 *
 *   const ok = await window.mvsConfirm( 'Delete this album?', {
 *       confirmLabel: 'Delete',
 *       cancelLabel:  'Keep',
 *       tone:         'destructive', // applies error-color to confirm button
 *   } );
 *   if ( ! ok ) { return; }
 *
 * Why this exists separate from Pro's admin ConfirmDialog:
 *   - Pro's ConfirmDialog is admin-only (gated on screen id prefix). Frontend
 *     surfaces — BuddyPress profile/group media tabs, the user dashboard
 *     Connected Accounts panel — need their own modal.
 *   - Native window.confirm() is banned by admin-ux-rulebook Rule 10 because
 *     it's unstyled, blocks the JS event loop, and can be suppressed by browser
 *     "do not allow this site to show further dialogs" prompts after a few
 *     fires in a row. mvsConfirm matches the rest of the plugin's modal UI.
 *
 * Loaded once per page (mvs-confirm script handle). Idempotent: re-loading
 * is a no-op because window.mvsConfirm is set once and the dialog DOM is
 * created lazily on first call.
 *
 * @package WPMediaVerse
 * @since   1.2.0
 */
( function () {
	'use strict';

	if ( typeof window.mvsConfirm === 'function' ) {
		// A different host page (e.g. Pro admin's ConfirmDialog) already
		// installed an mvsConfirm — don't overwrite it. The two implementations
		// share the same Promise<boolean> contract.
		return;
	}

	var dialog;
	var messageEl;
	var okBtn;
	var cancelBtn;

	/**
	 * Lazy-create the modal DOM the first time mvsConfirm is invoked.
	 * Uses DOM-builder methods only — no innerHTML, so no XSS surface.
	 */
	function ensureDialog() {
		if ( dialog ) {
			return;
		}

		dialog = document.createElement( 'dialog' );
		dialog.id = 'mvs-frontend-confirm-dialog';
		dialog.className = 'mvs-frontend-confirm-dialog';

		var form = document.createElement( 'form' );
		form.method = 'dialog';
		form.className = 'mvs-frontend-confirm-dialog__form';

		messageEl = document.createElement( 'p' );
		messageEl.className = 'mvs-frontend-confirm-dialog__message';

		var actions = document.createElement( 'div' );
		actions.className = 'mvs-frontend-confirm-dialog__actions';

		cancelBtn = document.createElement( 'button' );
		cancelBtn.type = 'button';
		cancelBtn.className = 'mvs-btn mvs-btn--ghost mvs-frontend-confirm-cancel';

		okBtn = document.createElement( 'button' );
		okBtn.type = 'button';
		okBtn.className = 'mvs-btn mvs-btn--primary mvs-frontend-confirm-ok';

		actions.appendChild( cancelBtn );
		actions.appendChild( okBtn );
		form.appendChild( messageEl );
		form.appendChild( actions );
		dialog.appendChild( form );

		document.body.appendChild( dialog );
	}

	/**
	 * Promise-based confirm.
	 *
	 * @param {string} message  Question shown in the modal body.
	 * @param {Object} [opts]   Options:
	 *   - confirmLabel {string} OK button label (default "Confirm").
	 *   - cancelLabel  {string} Cancel button label (default "Cancel").
	 *   - tone         {string} 'destructive' to render the OK button in error color.
	 * @return {Promise<boolean>} Resolves true on confirm, false on cancel/dismiss.
	 */
	window.mvsConfirm = function ( message, opts ) {
		opts = opts || {};

		ensureDialog();

		var i18n = window.mvsConfirmI18n || {};
		messageEl.textContent  = message || i18n.areYouSure || 'Are you sure?';
		okBtn.textContent      = opts.confirmLabel || i18n.confirm || 'Confirm';
		cancelBtn.textContent  = opts.cancelLabel  || i18n.cancel  || 'Cancel';
		dialog.classList.toggle( 'mvs-frontend-confirm-dialog--destructive', opts.tone === 'destructive' );

		return new Promise( function ( resolve ) {
			function cleanup() {
				okBtn.removeEventListener( 'click', onOk );
				cancelBtn.removeEventListener( 'click', onCancel );
				dialog.removeEventListener( 'close', onCancel );
			}
			function onOk() {
				cleanup();
				if ( typeof dialog.close === 'function' ) {
					dialog.close();
				}
				resolve( true );
			}
			function onCancel() {
				cleanup();
				if ( typeof dialog.close === 'function' ) {
					dialog.close();
				}
				resolve( false );
			}

			okBtn.addEventListener( 'click', onOk );
			cancelBtn.addEventListener( 'click', onCancel );
			dialog.addEventListener( 'close', onCancel );

			// The <dialog> element is universally supported by every
			// browser in the plugin's compatibility baseline (Chrome 37+,
			// Safari 15.4+, Firefox 98+). No native-confirm fallback —
			// admin-ux-rulebook Rule 10 bans confirm() / alert(), and a
			// browser without <dialog> would be too old to ship admin JS
			// to anyway. If showModal() is somehow unavailable, fail
			// closed (resolve false) rather than fall back to a banned UI.
			if ( typeof dialog.showModal === 'function' ) {
				dialog.showModal();
			} else {
				cleanup();
				resolve( false );
			}
		} );
	};
} )();
