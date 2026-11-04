/**
 * Player guard.
 *
 * A player marked controlsList="nodownload" plays an item whose owner, or the
 * site, switched downloads off. The attribute already removes Download from
 * the browser's own player menu; this also refuses the right-click menu on it,
 * so "Save audio as" / "Save video as" is not one click away. A deterrent only:
 * anyone allowed to play the file can still record it.
 *
 * One delegated listener covers players added later (lightbox, feeds, chat),
 * so any plugin that prints a nodownload player just enqueues this handle.
 *
 * @package WPMediaVerse
 * @since   2.6.3
 */
document.addEventListener( 'contextmenu', function ( event ) {
	var el = event.target;
	if ( el && el.closest && el.closest( 'audio[controlslist~="nodownload"], video[controlslist~="nodownload"]' ) ) {
		event.preventDefault();
	}
} );
