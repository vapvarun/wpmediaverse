/**
 * Followers / Following modal for member cards.
 *
 * The member-photos card's follower + following counts are role="button"
 * (.mvs-follows-open) carrying data-user-id + data-list. Clicking (or Enter/Space)
 * opens a single shared modal (.mvs-follows-modal) that reuses the .mvs-modal
 * component; rows are fetched from /users/{id}/followers|following and rendered
 * here. Follow-back is handled inline (POST/DELETE /users/{id}/follow).
 *
 * Vanilla + document-level delegation so it survives iAPI router DOM morphs.
 * Depends on window.mvsRest (mvs-rest handle) for the auth'd fetch wrapper.
 *
 * @package WPMediaVerse
 */
( function () {
	'use strict';

	function modalEl() {
		return document.querySelector( '.mvs-follows-modal' );
	}

	function setState( m, name ) {
		m.querySelectorAll( '.mvs-follows-state' ).forEach( function ( el ) {
			el.setAttribute( 'hidden', '' );
		} );
		if ( name ) {
			var el = m.querySelector( '.mvs-follows-' + name );
			if ( el ) {
				el.removeAttribute( 'hidden' );
			}
		}
	}

	function setActiveTab( m, list ) {
		m.querySelectorAll( '.mvs-follows-tab' ).forEach( function ( t ) {
			t.classList.toggle( 'is-active', t.getAttribute( 'data-list' ) === list );
		} );
	}

	function renderRows( m, users ) {
		var ul = m.querySelector( '.mvs-follows-list' );
		var selfId = parseInt( m.getAttribute( 'data-self-id' ), 10 ) || 0;
		var restUrl = m.getAttribute( 'data-rest-url' );
		ul.innerHTML = '';
		users.forEach( function ( u ) {
			var li = document.createElement( 'li' );
			li.className = 'mvs-follows-row';

			var img = document.createElement( 'img' );
			img.className = 'mvs-follows-row__avatar';
			img.src = u.avatar || '';
			img.alt = '';
			img.width = 40;
			img.height = 40;
			img.loading = 'lazy';
			li.appendChild( img );

			var name = document.createElement( 'span' );
			name.className = 'mvs-follows-row__name';
			name.textContent = u.name || '';
			li.appendChild( name );

			// Follow-back button (not for yourself). Reuses the .mvs-btn ladder.
			if ( selfId && u.id !== selfId ) {
				var btn = document.createElement( 'button' );
				btn.type = 'button';
				btn.className = 'mvs-btn mvs-btn--small mvs-follows-follow ' + ( u.is_following ? 'mvs-btn--secondary' : 'mvs-btn--primary' );
				btn.setAttribute( 'data-user-id', u.id );
				btn.setAttribute( 'data-following', u.is_following ? '1' : '0' );
				btn.setAttribute( 'data-rest-url', restUrl );
				btn.textContent = u.is_following ? 'Following' : 'Follow';
				li.appendChild( btn );
			}
			ul.appendChild( li );
		} );
	}

	function load( m, list ) {
		var userId = m.getAttribute( 'data-user-id' );
		var restUrl = m.getAttribute( 'data-rest-url' );
		if ( ! userId || ! restUrl || ! window.mvsRest ) {
			return;
		}
		setActiveTab( m, list );
		m.querySelector( '.mvs-follows-list' ).innerHTML = '';
		setState( m, 'loading' );
		window.mvsRest.restFetch( restUrl + 'users/' + userId + '/' + list + '?per_page=50' )
			.then( function ( r ) {
				var users = r && Array.isArray( r.data ) ? r.data : [];
				if ( ! users.length ) {
					setState( m, 'empty' );
					return;
				}
				setState( m, null );
				renderRows( m, users );
			} )
			.catch( function () {
				setState( m, 'empty' );
			} );
	}

	function open( userId, list ) {
		var m = modalEl();
		if ( ! m ) {
			return;
		}
		m.setAttribute( 'data-user-id', userId );
		m.removeAttribute( 'hidden' );
		load( m, list );
	}

	function close() {
		var m = modalEl();
		if ( m ) {
			m.setAttribute( 'hidden', '' );
		}
	}

	// Open from a count (.mvs-follows-open) — click + keyboard.
	document.addEventListener( 'click', function ( e ) {
		var trig = e.target.closest( '.mvs-follows-open' );
		if ( ! trig ) {
			return;
		}
		open( trig.getAttribute( 'data-user-id' ), trig.getAttribute( 'data-list' ) || 'followers' );
	} );
	document.addEventListener( 'keydown', function ( e ) {
		if ( e.key !== 'Enter' && e.key !== ' ' ) {
			return;
		}
		var trig = e.target.closest && e.target.closest( '.mvs-follows-open' );
		if ( ! trig ) {
			return;
		}
		e.preventDefault();
		open( trig.getAttribute( 'data-user-id' ), trig.getAttribute( 'data-list' ) || 'followers' );
	} );

	// Tab switch.
	document.addEventListener( 'click', function ( e ) {
		var tab = e.target.closest( '.mvs-follows-tab' );
		if ( ! tab ) {
			return;
		}
		var m = tab.closest( '.mvs-follows-modal' );
		if ( m ) {
			load( m, tab.getAttribute( 'data-list' ) );
		}
	} );

	// Close: × button or backdrop click.
	document.addEventListener( 'click', function ( e ) {
		if ( e.target.closest( '.mvs-follows-close' ) || e.target.classList.contains( 'mvs-follows-modal' ) ) {
			close();
		}
	} );
	document.addEventListener( 'keydown', function ( e ) {
		if ( e.key === 'Escape' ) {
			close();
		}
	} );

	// Follow-back inside the modal.
	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '.mvs-follows-follow' );
		if ( ! btn || btn.classList.contains( 'is-loading' ) ) {
			return;
		}
		var userId = btn.getAttribute( 'data-user-id' );
		var restUrl = btn.getAttribute( 'data-rest-url' );
		var isFollowing = btn.getAttribute( 'data-following' ) === '1';
		btn.classList.add( 'is-loading' );
		btn.disabled = true;
		window.mvsRest.restFetch( restUrl + 'users/' + userId + '/follow', {
			method: isFollowing ? 'DELETE' : 'POST',
		} )
			.then( function () {
				btn.classList.remove( 'is-loading' );
				btn.disabled = false;
				var nowFollowing = ! isFollowing;
				btn.setAttribute( 'data-following', nowFollowing ? '1' : '0' );
				btn.textContent = nowFollowing ? 'Following' : 'Follow';
				btn.classList.toggle( 'mvs-btn--secondary', nowFollowing );
				btn.classList.toggle( 'mvs-btn--primary', ! nowFollowing );
			} )
			.catch( function () {
				btn.classList.remove( 'is-loading' );
				btn.disabled = false;
			} );
	} );
}() );
