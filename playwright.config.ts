import { defineConfig, devices } from '@playwright/test';

/**
 * Playwright configuration for WPMediaVerse 1.2.0 Phase 0c E2E specs.
 *
 * Three flow-tests live under `tests/e2e/`:
 *  - upload-sign-serve.spec.ts        — lightbox URL signing (1.1.3 regression target)
 *  - bp-activity-render.spec.ts       — BuddyPress activity media rendering
 *  - challenge-cover-fallback.spec.ts — Pro challenge-card placeholder state
 *
 * Each catches a class of failure that text-pattern static analysis (PHPCS,
 * PHPStan, regex CI guards) cannot — runtime URL composition inside the
 * Interactivity API store, broadcast-TTL emission baked into BP activity
 * HTML, and conditional render flow for empty-state cover images.
 *
 * Run locally:
 *   npx playwright install chrome     # one-time (uses installed Google Chrome)
 *   MVS_SITE_URL=http://mediaverse.local npx playwright test
 *
 * CI:
 *   composer ci-e2e   # wraps the same; targets the wp-env site URL.
 */

const SITE_URL = process.env.MVS_SITE_URL || 'http://mediaverse.local';

export default defineConfig({
	testDir: './tests/e2e',
	timeout: 30_000,
	fullyParallel: false, // BP activity assertions inspect stateful page snapshots
	retries: process.env.CI ? 2 : 0,
	reporter: process.env.CI ? [['github'], ['list']] : 'list',
	use: {
		baseURL: SITE_URL,
		trace: 'on-first-retry',
		screenshot: 'only-on-failure',
		video: process.env.CI ? 'retain-on-failure' : 'off',
		ignoreHTTPSErrors: true,
		// Local: open real Chrome so you can watch. CI stays headless.
		headless: process.env.CI ? true : process.env.MVS_HEADED === '0',
		launchOptions: {
			slowMo: process.env.CI ? 0 : Number( process.env.MVS_SLOWMO || 150 ),
		},
	},
	projects: [
		{
			name: 'chrome',
			use: {
				...devices['Desktop Chrome'],
				channel: 'chrome', // installed Google Chrome, not bundled Chromium
			},
		},
	],
});
