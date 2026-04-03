/**
 * Settings page sidebar navigation.
 *
 * Handles section show/hide, hash-based routing, and hash preservation on save.
 *
 * @package WPMediaVerse
 */
( function () {
	'use strict';

	const NAV_SELECTOR = '.mvs-settings-nav-item';
	const SECTION_SELECTOR = '.mvs-settings-section';
	const ACTIVE_CLASS = 'is-active';

	function activateSection( sectionId ) {
		// Deactivate all nav items and sections.
		document.querySelectorAll( NAV_SELECTOR ).forEach( ( el ) => {
			el.classList.remove( ACTIVE_CLASS );
			el.removeAttribute( 'aria-current' );
		} );
		document.querySelectorAll( SECTION_SELECTOR ).forEach( ( el ) => el.classList.remove( ACTIVE_CLASS ) );

		// Activate target nav item and section.
		const navItem = document.querySelector( `${ NAV_SELECTOR }[data-section="${ sectionId }"]` );
		const section = document.getElementById( 'section-' + sectionId );

		if ( navItem && section ) {
			navItem.classList.add( ACTIVE_CLASS );
			navItem.setAttribute( 'aria-current', 'page' );
			section.classList.add( ACTIVE_CLASS );
		} else {
			// Fallback: activate the first section.
			const firstNav = document.querySelector( NAV_SELECTOR );
			const firstSection = document.querySelector( SECTION_SELECTOR );
			if ( firstNav ) {
				firstNav.classList.add( ACTIVE_CLASS );
				firstNav.setAttribute( 'aria-current', 'page' );
			}
			if ( firstSection ) firstSection.classList.add( ACTIVE_CLASS );
		}
	}

	function getHashSection() {
		const hash = window.location.hash.replace( '#', '' );
		return hash || '';
	}

	function init() {
		// Click handler for sidebar nav items.
		document.querySelectorAll( NAV_SELECTOR ).forEach( ( item ) => {
			item.addEventListener( 'click', ( e ) => {
				// Only handle section links (hash-based). Let external page links navigate normally.
				const href = item.getAttribute( 'href' ) || '';
				if ( ! href.startsWith( '#' ) ) {
					return;
				}
				e.preventDefault();
				const sectionId = item.getAttribute( 'data-section' );
				activateSection( sectionId );
				history.replaceState( null, '', '#' + sectionId );
			} );
		} );

		// On load, activate section from hash or default to first.
		const hashSection = getHashSection();
		if ( hashSection ) {
			activateSection( hashSection );
		} else {
			const firstNav = document.querySelector( NAV_SELECTOR );
			if ( firstNav ) {
				activateSection( firstNav.getAttribute( 'data-section' ) );
			}
		}

		// Browser back/forward.
		window.addEventListener( 'hashchange', () => {
			const section = getHashSection();
			if ( section ) {
				activateSection( section );
			}
		} );

		// On form submit, inject hash into _wp_http_referer so WP redirects back to the same section.
		document.querySelectorAll( '.mvs-settings-section form' ).forEach( ( form ) => {
			form.addEventListener( 'submit', () => {
				const referer = form.querySelector( 'input[name="_wp_http_referer"]' );
				if ( referer && window.location.hash ) {
					// Strip any existing hash and append current one.
					referer.value = referer.value.replace( /#.*$/, '' ) + window.location.hash;
				}
			} );
		} );
	}

	// Ctrl+S / Cmd+S to save the active section's form.
	document.addEventListener( 'keydown', function ( e ) {
		if ( ( e.ctrlKey || e.metaKey ) && e.key === 's' ) {
			e.preventDefault();
			var form = document.querySelector( '.mvs-settings-section.' + ACTIVE_CLASS + ' form' );
			if ( form ) {
				form.submit();
			}
		}
	} );

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
