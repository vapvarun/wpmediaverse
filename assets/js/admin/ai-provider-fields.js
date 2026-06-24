/**
 * Progressive disclosure for AI provider credentials on the settings AI tab.
 *
 * Shows only the selected provider's key/model and hides the other providers'
 * cards, so a site owner who picks Claude sees the Anthropic fields, not the
 * OpenAI/Google/AWS ones. The OpenAI key/model rows also stay visible when
 * Whisper auto-captions is enabled, because that feature reuses the OpenAI key.
 *
 * @package WPMediaVerse
 */
( function () {
	'use strict';

	// Provider value (mvs_ai_provider) -> settings-card section id.
	var PROVIDER_CARDS = {
		google_vision: 'mvs_pro_ai_google',
		rekognition: 'mvs_pro_ai_aws',
		anthropic: 'mvs_pro_ai_anthropic',
	};

	function init() {
		var select = document.querySelector( 'select[name="mvs_ai_provider"]' );
		if ( ! select ) {
			return;
		}

		var captions = document.querySelector( 'input[name="mvs_pro_settings[captions_auto]"]' );

		function sync() {
			var provider = select.value;

			// Show only the selected provider's card; hide the others.
			Object.keys( PROVIDER_CARDS ).forEach( function ( prov ) {
				var card = document.querySelector(
					'.mvs-settings-card[data-section="' + PROVIDER_CARDS[ prov ] + '"]'
				);
				if ( card ) {
					card.style.display = provider === prov ? '' : 'none';
				}
			} );

			// OpenAI key/model rows live inside the shared "AI Features" card.
			// Show them when OpenAI is the provider OR when Whisper captions are
			// on (captions reuse the OpenAI key).
			var captionsOn = captions ? captions.checked : false;
			var showOpenAi = 'openai' === provider || captionsOn;
			var rows = document.querySelectorAll( 'tr.mvs-ai-openai-field' );
			Array.prototype.forEach.call( rows, function ( row ) {
				row.style.display = showOpenAi ? '' : 'none';
			} );
		}

		select.addEventListener( 'change', sync );
		if ( captions ) {
			captions.addEventListener( 'change', sync );
		}
		sync();
	}

	if ( 'loading' !== document.readyState ) {
		init();
	} else {
		document.addEventListener( 'DOMContentLoaded', init );
	}
}() );
