/**
 * "Remove" link beside a saved API key or secret on the settings page.
 *
 * A saved key is never printed back into the form, so an empty field means
 * "keep it" and there was no way to clear one. Clicking Remove enables a hidden
 * input naming the option; the save then clears it
 * (SettingsHelper::secret_removal_requested). Clicking again (Undo) restores.
 *
 * @package WPMediaVerse
 */
( function () {
	'use strict';

	var i18n = window.wp && window.wp.i18n ? window.wp.i18n : null;
	var __ = i18n ? i18n.__ : function ( text ) {
		return text;
	};

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '.mvs-secret-remove' );
		if ( ! button ) {
			return;
		}
		event.preventDefault();

		var option = button.getAttribute( 'data-mvs-secret' );
		var marker = button.nextElementSibling;
		var field = document.getElementById( option ) || document.querySelector( '[name="' + option + '"]' );
		var removing = marker.disabled;

		if ( ! button.dataset.removeLabel ) {
			button.dataset.removeLabel = button.textContent;
			if ( field ) {
				button.dataset.placeholder = field.getAttribute( 'placeholder' ) || '';
			}
		}

		marker.disabled = ! removing;
		button.textContent = removing ? button.getAttribute( 'data-undo-label' ) : button.dataset.removeLabel;

		if ( field ) {
			field.value = '';
			field.disabled = removing;
			field.setAttribute(
				'placeholder',
				removing ? __( 'Will be removed when you save', 'wpmediaverse' ) : button.dataset.placeholder
			);
		}
	} );
} )();
