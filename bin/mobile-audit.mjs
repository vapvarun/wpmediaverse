#!/usr/bin/env node
/**
 * Mobile guideline auditor — defined by docs/MOBILE_UX_GUIDELINE.md §15.
 *
 * Walks every WPMediaVerse-owned route at 390x844 (iPhone 13/14 width) and
 * fails if any element exceeds the viewport width or any interactive element
 * reports < 44px in either axis.
 *
 * Usage:
 *   node bin/mobile-audit.mjs --base http://mediaverse.local
 *   node bin/mobile-audit.mjs --base http://localhost:8889 --verbose
 *
 * Requires Playwright (already devDep through wp-scripts):
 *   npx playwright install chromium  # if first run
 *
 * Exit codes:
 *   0 — all routes pass
 *   1 — at least one route violated the guideline
 *   2 — bootstrap error (Playwright missing, base URL unreachable, etc.)
 *
 * The script is pure Node — no WordPress dependency. It expects the target
 * site to be reachable, BuddyPress optional, and an autologin mu-plugin at
 * `?autologin=1` so it can hit logged-in routes.
 */

import { chromium } from 'playwright';

const args = process.argv.slice( 2 );
const baseFlag = args.indexOf( '--base' );
const BASE_URL = baseFlag >= 0 ? args[ baseFlag + 1 ] : 'http://mediaverse.local';
const VERBOSE = args.includes( '--verbose' );
const VIEWPORT = { width: 390, height: 844 };
const TOUCH_MIN = 44;

const ROUTES = [
	{ path: '/media/', name: 'Explore archive', detail: '/media/ — public media grid.' },
	{ path: '/my-media/', name: 'Dashboard / My Media', detail: '/my-media/ — logged-in dashboard with tab strip.' },
	{ path: '/media/edit-profile/', name: 'Edit profile', detail: '/media/edit-profile/ — back link required.' },
	{ path: '/media/@varundubey/', name: 'User profile', detail: '/media/@user/ — public profile feed.' },
	{ path: '/album/street-life/', name: 'Album single', detail: '/album/{slug}/ — owner action row + back link.' },
	{ path: '/media/challenges/', name: 'Pro: challenges hub', detail: '/media/challenges/ — Pro feature, optional.' },
	{ path: '/media/battles/',    name: 'Pro: battles hub',    detail: '/media/battles/ — Pro feature, side-by-side cards.' },
	{ path: '/compete/',          name: 'Pro: compete hub',    detail: '/compete/ — Pro hub.' },
];

/**
 * Run the audit. Returns an exit code.
 */
async function run() {
	let browser;
	try {
		browser = await chromium.launch();
	} catch ( e ) {
		console.error( '\n❌ Failed to launch Playwright Chromium.' );
		console.error( '   Run: npx playwright install chromium' );
		console.error( '   Cause:', e.message );
		return 2;
	}

	const ctx = await browser.newContext( { viewport: VIEWPORT } );
	const page = await ctx.newPage();

	// Hit autologin first to set the cookie for downstream routes.
	console.log( `🔐 Authenticating against ${ BASE_URL }/?autologin=1` );
	try {
		await page.goto( `${ BASE_URL }/?autologin=1`, { waitUntil: 'load', timeout: 15000 } );
	} catch ( e ) {
		console.error( '\n❌ Could not reach the base URL — is the site running?' );
		console.error( '   ', e.message );
		await browser.close();
		return 2;
	}

	const findings = [];

	for ( const route of ROUTES ) {
		console.log( `\n🔍 ${ route.name } — ${ route.path }` );
		try {
			await page.goto( `${ BASE_URL }${ route.path }`, { waitUntil: 'networkidle', timeout: 20000 } );
			// Give Lucide / Interactivity API one frame to hydrate.
			await page.waitForTimeout( 600 );
		} catch ( e ) {
			findings.push( { route: route.path, severity: 'warn', issue: `navigation failed: ${ e.message }` } );
			continue;
		}

		const audit = await page.evaluate( ( touchMin ) => {
			const docW = document.documentElement.clientWidth;
			const issues = [];

			// Body horizontal scroll.
			if ( document.body.scrollWidth > docW + 1 ) {
				issues.push( {
					severity: 'fail',
					rule: 'no-horizontal-scroll',
					detail: `body.scrollWidth ${ document.body.scrollWidth } > viewport ${ docW }`,
				} );
			}

			// Any element wider than viewport (excluding 3rd party theme classes).
			const overflowing = [];
			document.querySelectorAll( '*' ).forEach( ( el ) => {
				const cls = el.className?.toString?.() || '';
				// Skip common theme namespaces — guideline applies to MVS only.
				if ( /^reign|^bp-|^buddyx|^astra/.test( cls ) ) return;
				const r = el.getBoundingClientRect();
				if ( r.width > docW + 1 && r.width > 0 && el.offsetParent !== null ) {
					overflowing.push( { tag: el.tagName, cls: cls.slice( 0, 60 ), w: Math.round( r.width ) } );
				}
			} );
			if ( overflowing.length ) {
				issues.push( {
					severity: 'fail',
					rule: 'no-element-wider-than-viewport',
					detail: overflowing.slice( 0, 3 ),
				} );
			}

			// Touch target floor on visible interactive elements (MVS-owned only).
			const undersized = [];
			document.querySelectorAll(
				'.mvs-btn, .mvs-icon-btn, .mvs-tab, .mvs-action-icon, .mvs-fab, .mvs-back-link, .mvs-toast-close, .mvs-modal-close, .mvs-dashboard-tab'
			).forEach( ( el ) => {
				if ( el.offsetParent === null ) return;
				const r = el.getBoundingClientRect();
				if ( r.width === 0 || r.height === 0 ) return;
				if ( r.width < touchMin - 1 || r.height < touchMin - 1 ) {
					undersized.push( {
						cls: el.className?.toString?.().slice( 0, 60 ),
						w: Math.round( r.width ),
						h: Math.round( r.height ),
						text: el.innerText?.trim().slice( 0, 30 ),
					} );
				}
			} );
			if ( undersized.length ) {
				issues.push( {
					severity: 'fail',
					rule: 'touch-target-44px',
					detail: undersized.slice( 0, 5 ),
				} );
			}

			return issues;
		}, TOUCH_MIN );

		if ( audit.length === 0 ) {
			console.log( '   ✅ pass' );
		} else {
			for ( const issue of audit ) {
				console.log( `   ❌ ${ issue.rule }` );
				if ( VERBOSE || typeof issue.detail === 'string' ) {
					console.log( '     ', JSON.stringify( issue.detail ) );
				}
				findings.push( { route: route.path, ...issue } );
			}
		}
	}

	await browser.close();

	console.log( '\n──────────────────────────────────' );
	if ( findings.length === 0 ) {
		console.log( `✅ All ${ ROUTES.length } routes pass at ${ VIEWPORT.width }×${ VIEWPORT.height }.` );
		return 0;
	}
	console.log( `❌ ${ findings.length } issue(s) across ${ ROUTES.length } routes:` );
	for ( const f of findings ) {
		console.log( `   • ${ f.route } — ${ f.rule || f.issue }` );
	}
	console.log( '\nSee docs/MOBILE_UX_GUIDELINE.md for the rules each finding violates.' );
	return 1;
}

run().then( ( code ) => process.exit( code ) ).catch( ( e ) => {
	console.error( '\n❌ Auditor crashed:', e );
	process.exit( 2 );
} );
