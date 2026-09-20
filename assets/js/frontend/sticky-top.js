/**
 * Sticky-top offsets — measures what is pinned at the top of the viewport and
 * publishes it as two custom properties on <html>.
 *
 * Why this exists: our sticky surfaces used to park at a flat offset from the
 * top of the viewport, which on any theme that pins its own header (Reign, the
 * theme we ship with, occupies 0-90 at 1440 and 0-81 at 390) put them
 * UNDERNEATH it. `.mvs-bulk-bar`'s selection count, album picker and Add button
 * all hit-tested to the theme's header, so a member doing bulk work could not
 * click the controls at all; `.mvs-dashboard-tabs` at 390 sat entirely inside
 * the header band (0-70 against 0-81, z-index 50 against 999) and its first tab
 * hit-tested to the site logo. Basecamp 10320911387.
 *
 * A plugin cannot hardcode a theme's header height, so the number is measured:
 * probe the top edge of the viewport, keep the elements that are actually
 * `fixed`/`sticky` and actually cover that point, and take their lowest bottom.
 * Repeat from just under that bottom so a STACK (WP admin bar 0-32, then the
 * theme header 32-122) is measured whole rather than one band deep.
 *
 * TWO variables, because our own sticky surfaces stack on each other:
 *
 *   --mvs-sticky-top    Chrome only — the admin bar and the THEME's header.
 *                       Every element of ours is excluded from this walk.
 *                       Consumed by `.mvs-dashboard-tabs` and
 *                       `.mvs-dashboard-rail`.
 *   --mvs-sticky-stack  Chrome PLUS our own pinned surfaces above the bulk bar
 *                       (at 390 the dashboard tab strip is one of them).
 *                       Only `.mvs-bulk-bar` itself is excluded.
 *                       Consumed by `.mvs-bulk-bar`.
 *
 * The split is what stops a surface from chasing itself. A single variable that
 * the tab strip both READ and CONTRIBUTED TO would creep: the strip moves down,
 * which raises the measurement, which moves the strip again. Each variable is
 * measured with the element that consumes it excluded, so each one converges in
 * a single pass and holds.
 *
 * Chrome is defined as "not ours" — `[class*="mvs-"]` — rather than a list of
 * our sticky class names. A list drifts the moment somebody adds the next
 * sticky surface, and drifts silently.
 *
 * Nav-safe and cheap:
 * - listeners bound once on window/document at module eval, so an iAPI region
 *   swap cannot leave the page without them;
 * - every trigger goes through one requestAnimationFrame gate — no timer per
 *   scroll event, at most one measurement per frame;
 * - the whole thing is skipped unless the page has a surface that consumes the
 *   variables, so other MVS pages pay nothing on scroll;
 * - each property is written only when its value actually moves, so scrolling
 *   past a fixed-height header triggers no style recalc.
 *
 * Scroll (not just load/resize) is a trigger because plenty of themes shrink
 * their header once the page moves; measuring only at load would leave a gap.
 *
 * @package WPMediaVerse
 */
( function () {
	'use strict';

	// Anything carrying an mvs-* class is OURS, never chrome.
	var OURS = '[class*="mvs-"]';
	// Surfaces that read one of the two variables. No match, no work.
	var CONSUMERS = '.mvs-bulk-bar, .mvs-dashboard-tabs, .mvs-dashboard-rail';

	var root = document.documentElement;
	var published = { '--mvs-sticky-top': -1, '--mvs-sticky-stack': -1 };
	var frame = 0;

	/**
	 * Bottom edge of `el` if it is pinned chrome covering `y`, else 0.
	 *
	 * @param {Element} el   Candidate element.
	 * @param {number}  y    Viewport y being probed.
	 * @param {string}  skip Selector for elements this walk must ignore.
	 * @return {number} Bottom edge in pixels, or 0.
	 */
	function pinnedBottom( el, y, skip ) {
		if ( ! el || ! el.closest ) {
			return 0;
		}

		// `closest`, not `matches`: a control nested inside one of our sticky
		// surfaces has to be skipped along with the surface itself.
		// A hit on <body>/<html> does NOT count, though — the plugin puts an
		// `mvs-page` class on <body>, so a bare closest('[class*="mvs-"]')
		// matched the body for EVERY element on the page and skipped the entire
		// walk, publishing 0 and reverting both surfaces to the original bug.
		// Page-level flags are not components.
		var owner = el.closest( skip );
		if ( owner && owner !== document.body && owner !== document.documentElement ) {
			return 0;
		}

		var position = window.getComputedStyle( el ).position;
		if ( 'fixed' !== position && 'sticky' !== position ) {
			return 0;
		}

		// Covers the probe point AND starts at or above it — an element that
		// merely happens to be on screen is not chrome pinned to the top.
		var rect = el.getBoundingClientRect();
		if ( rect.height <= 0 || rect.top > y || rect.bottom <= y ) {
			return 0;
		}

		return rect.bottom;
	}

	/**
	 * Lowest bottom edge of the pinned stack, ignoring anything matching `skip`.
	 *
	 * @param {string} skip Selector for elements this walk must ignore.
	 * @return {number} Pixels, 0 when nothing is pinned.
	 */
	function measure( skip ) {
		var height = window.innerHeight || 0;
		var x = ( window.innerWidth || 0 ) / 2;
		var top = 0;

		// The admin bar is seeded rather than discovered: it is the one piece of
		// pinned chrome that is always the same element, and seeding it saves a
		// probe. It is `position: absolute` under 783px, where it scrolls away
		// and must NOT count — hence the same computed-position test as any
		// other candidate.
		var adminBar = document.getElementById( 'wpadminbar' );
		if ( adminBar ) {
			top = Math.max( top, pinnedBottom( adminBar, 0, skip ) );
		}

		// Walk down the stack. Four rounds is well past any real page (admin bar
		// + header + tab strip + announcement bar) and guarantees termination.
		for ( var i = 0; i < 4; i++ ) {
			var y = top + 1;
			if ( y >= height ) {
				break;
			}

			var stack = document.elementsFromPoint( x, y );
			var next = top;
			for ( var j = 0; j < stack.length; j++ ) {
				next = Math.max( next, pinnedBottom( stack[ j ], y, skip ) );
			}

			if ( next <= top ) {
				break;
			}
			top = next;
		}

		// ponytail: a fixed element taller than 40% of the viewport is a sidebar,
		// a cookie wall or an open mobile-nav overlay, not top chrome — honouring
		// it would push our surfaces off screen. Cap rather than try to classify.
		return Math.min( top, Math.round( height * 0.4 ) );
	}

	/**
	 * Write a custom property, but only when its value actually moved.
	 *
	 * @param {string} name  Custom property name.
	 * @param {number} value Pixels.
	 */
	function publish( name, value ) {
		if ( Math.abs( value - published[ name ] ) < 1 ) {
			return;
		}
		published[ name ] = value;
		root.style.setProperty( name, value + 'px' );
	}

	/** Measure and publish, if the page has a surface that cares. */
	function update() {
		frame = 0;

		if ( ! document.querySelector( CONSUMERS ) ) {
			return;
		}

		// Chrome first, and published BEFORE the second walk: the tab strip
		// consumes this value, and the second walk has to see the strip where
		// the new value puts it. getBoundingClientRect() inside the walk forces
		// the pending layout, so one pass is enough — there is no frame of lag.
		publish( '--mvs-sticky-top', measure( OURS ) );
		publish( '--mvs-sticky-stack', measure( '.mvs-bulk-bar' ) );
	}

	/** Coalesce every trigger into at most one measurement per frame. */
	function schedule() {
		if ( ! frame ) {
			frame = window.requestAnimationFrame( update );
		}
	}

	schedule();
	window.addEventListener( 'scroll', schedule, { passive: true } );
	window.addEventListener( 'resize', schedule, { passive: true } );
	window.addEventListener( 'orientationchange', schedule );
	// A client-side navigation can swap in a page that has these surfaces when
	// the previous one had none, and can change the header's height with it.
	document.addEventListener( 'mvs:navigated', schedule );
}() );
