/**
 * Show a settings row or card only when the control it depends on says so.
 *
 * One rule for the whole settings screen (Coding Rule 22): a field or section
 * declares `show_when`, SettingsPage prints it as `data-mvs-show-when`, and this
 * file evaluates it. Nothing here names a field.
 *
 * Rule syntax:
 *   name        the checkbox `name` is ticked (a select: has a non-empty value)
 *   name=value  the control `name` has that value
 *   a|b         either term holds
 *
 * Controls are looked up in the element's own <form>. A control that is not on
 * the form, or that sits inside something hidden by its own rule, counts as off,
 * so rules nest without repeating their parents.
 *
 * Hiding is visual only: hidden inputs still post, so nothing is lost on Save.
 *
 * Kept at this path and handle (mvs-ai-provider-fields) so the enqueue does not
 * change; it started life as the AI-provider toggle.
 *
 * @package WPMediaVerse
 */
( function () {
	'use strict';

	var ATTR = 'data-mvs-show-when';

	function controlIsOn( control, want, depth ) {
		if ( ! control ) {
			return false;
		}
		// Several controls share the name (radios): use the checked one.
		if ( ! control.tagName && control.length ) {
			var list = Array.prototype.slice.call( control );
			control = list.filter( function ( c ) {
				return c.checked;
			} )[ 0 ] || list[ 0 ];
		}
		if ( ! visible( control, depth ) ) {
			return false;
		}
		var isToggle = 'checkbox' === control.type || 'radio' === control.type;
		if ( null === want ) {
			return isToggle ? control.checked : '' !== control.value;
		}
		return ( ! isToggle || control.checked ) && control.value === want;
	}

	function ruleHolds( el, depth ) {
		if ( depth > 10 ) {
			return false;
		}
		var form = el.closest( 'form' );
		return el.getAttribute( ATTR ).split( '|' ).some( function ( term ) {
			var eq = term.indexOf( '=' );
			var name = eq < 0 ? term : term.slice( 0, eq );
			var want = eq < 0 ? null : term.slice( eq + 1 );
			var control = form ? form.elements.namedItem( name ) : null;
			return controlIsOn( control, want, depth + 1 );
		} );
	}

	// An element is visible when every ancestor carrying a rule passes it.
	function visible( el, depth ) {
		var host = el.closest( '[' + ATTR + ']' );
		while ( host ) {
			if ( ! ruleHolds( host, depth + 1 ) ) {
				return false;
			}
			host = host.parentElement ? host.parentElement.closest( '[' + ATTR + ']' ) : null;
		}
		return true;
	}

	function sync() {
		var nodes = document.querySelectorAll( '[' + ATTR + ']' );
		Array.prototype.forEach.call( nodes, function ( el ) {
			el.style.display = ruleHolds( el, 0 ) ? '' : 'none';
		} );
	}

	function init() {
		if ( ! document.querySelector( '[' + ATTR + ']' ) ) {
			return;
		}
		document.addEventListener( 'change', sync );
		sync();
	}

	if ( 'loading' !== document.readyState ) {
		init();
	} else {
		document.addEventListener( 'DOMContentLoaded', init );
	}
}() );
