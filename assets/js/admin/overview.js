/**
 * Overview admin page behaviors: demo data import, demo data cleanup, and
 * welcome-banner dismiss. Replaces the three inline <script> blocks formerly
 * emitted by OverviewPage::render_page. Config + translatable strings come from
 * window.mvsOverview (wp_localize_script). The cleanup confirmation uses the
 * shared mvsConfirm modal instead of a native confirm().
 *
 * @package WPMediaVerse
 */
( function () {
	'use strict';

	var cfg = window.mvsOverview || {};
	var i18n = cfg.i18n || {};

	function parseJson( xhr, onError ) {
		try {
			return JSON.parse( xhr.responseText );
		} catch ( err ) {
			onError( err );
			return null;
		}
	}

	function initImportDemo() {
		var btn = document.getElementById( 'mvs-import-demo-btn' );
		if ( ! btn ) {
			return;
		}
		btn.addEventListener( 'click', function () {
			var status = document.getElementById( 'mvs-import-demo-status' );
			var progress = document.getElementById( 'mvs-import-progress' );
			var bar = document.getElementById( 'mvs-import-progress-bar' );
			btn.disabled = true;
			btn.textContent = i18n.importing || '';
			status.textContent = '';
			progress.classList.remove( 'mvs-hidden' );
			bar.style.width = '30%';

			var xhr = new XMLHttpRequest();
			xhr.open( 'POST', window.ajaxurl );
			xhr.setRequestHeader( 'Content-Type', 'application/x-www-form-urlencoded' );
			xhr.onload = function () {
				bar.style.width = '100%';
				var data = parseJson( xhr, function ( err ) {
					status.textContent = i18n.importFailedInvalid || '';
					status.className = 'mvs-status-inline mvs-btn-text-danger';
					btn.disabled = false;
					btn.textContent = i18n.importDemoData || '';
					progress.classList.add( 'mvs-hidden' );
					window.console.error( 'mvs_import_demo_data: invalid JSON response', err, xhr.responseText );
				} );
				if ( ! data ) {
					return;
				}
				if ( data.success ) {
					status.textContent = data.data.message;
					status.className = 'mvs-status-inline mvs-icon-success';
					if ( cfg.exploreUrl ) {
						status.textContent += ' ' + ( i18n.redirectingToExplore || '' );
						setTimeout( function () {
							window.location.href = cfg.exploreUrl;
						}, 1500 );
					} else {
						setTimeout( function () {
							window.location.reload();
						}, 1500 );
					}
				} else {
					status.textContent = data.data ? data.data.message : 'Import failed.';
					status.className = 'mvs-status-inline mvs-btn-text-danger';
					btn.disabled = false;
					btn.textContent = i18n.importDemoData || '';
					progress.classList.add( 'mvs-hidden' );
				}
			};
			setTimeout( function () {
				bar.style.width = '60%';
			}, 500 );
			xhr.send( 'action=mvs_import_demo_data&_nonce=' + btn.getAttribute( 'data-nonce' ) );
		} );
	}

	function runCleanup( btn ) {
		var status = document.getElementById( 'mvs-cleanup-demo-status' );
		btn.disabled = true;
		btn.textContent = i18n.deleting || '';
		status.textContent = '';

		var xhr = new XMLHttpRequest();
		xhr.open( 'POST', window.ajaxurl );
		xhr.setRequestHeader( 'Content-Type', 'application/x-www-form-urlencoded' );
		xhr.onload = function () {
			var data = parseJson( xhr, function ( err ) {
				status.textContent = i18n.cleanupFailedInvalid || '';
				status.className = 'mvs-status-inline mvs-btn-text-danger';
				btn.disabled = false;
				btn.textContent = i18n.deleteDemoData || '';
				window.console.error( 'mvs_cleanup_demo: invalid JSON response', err, xhr.responseText );
			} );
			if ( ! data ) {
				return;
			}
			if ( data.success ) {
				status.textContent = data.data.message;
				status.className = 'mvs-status-inline mvs-icon-success';
				setTimeout( function () {
					window.location.reload();
				}, 1500 );
			} else {
				status.textContent = data.data ? data.data.message : 'Cleanup failed.';
				status.className = 'mvs-status-inline mvs-btn-text-danger';
				btn.disabled = false;
				btn.textContent = i18n.deleteDemoData || '';
			}
		};
		xhr.send( 'action=mvs_cleanup_demo_data&_nonce=' + btn.getAttribute( 'data-nonce' ) );
	}

	function initCleanupDemo() {
		var btn = document.getElementById( 'mvs-cleanup-demo-btn' );
		if ( ! btn ) {
			return;
		}
		btn.addEventListener( 'click', function () {
			var msg = i18n.cleanupConfirm || '';
			if ( typeof window.mvsConfirm === 'function' ) {
				window.mvsConfirm( msg, { tone: 'destructive' } ).then( function ( ok ) {
					if ( ok ) {
						runCleanup( btn );
					}
				} );
			} else if ( window.confirm( msg ) ) { // eslint-disable-line no-alert -- fallback when modal helper absent.
				runCleanup( btn );
			}
		} );
	}

	function initDismissWelcome() {
		var btn = document.getElementById( 'mvs-dismiss-welcome' );
		if ( ! btn ) {
			return;
		}
		btn.addEventListener( 'click', function () {
			var banner = document.getElementById( 'mvs-welcome-banner' );
			if ( banner ) {
				banner.classList.add( 'mvs-hidden' );
			}
			if ( window.wp && window.wp.apiFetch ) {
				window.wp.apiFetch( { path: '/mvs/v1/admin/welcome/dismiss', method: 'POST' } );
			}
		} );
	}

	initImportDemo();
	initCleanupDemo();
	initDismissWelcome();
} )();
