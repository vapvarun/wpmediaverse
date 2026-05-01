import { expect, test, Page, Response } from '@playwright/test';

/**
 * Phase 0c gap-test: lightbox URL signing.
 *
 * The 1.1.3 lightbox bug emitted a raw /wp-content/uploads/wpmediaverse/...
 * URL inside the Interactivity API lightbox store. Static analysis (PHPCS,
 * PHPStan, the regex CI guard) all passed because the URL was COMPOSED at
 * render-time on the client; no PHP source string ever exposed it. Only a
 * flow-test that actually clicks the thumbnail and inspects the network
 * panel can catch this class of leak.
 *
 * Contract this spec encodes:
 *   1. Grid thumbnails are signed (mvs/v1/serve?...&mvs_sig=...).
 *   2. Click → lightbox opens; the original-image network request returns
 *      HTTP 200 (or 206 for range), NEVER 403.
 *   3. Every wpmediaverse-served URL flowing through the lightbox carries
 *      a mvs_sig token — no raw uploads URLs leak.
 */

const AUTO_LOGIN = '/media/?autologin=1';

async function autoLogin(page: Page): Promise<void> {
	await page.goto(AUTO_LOGIN, { waitUntil: 'domcontentloaded' });
}

function isMvsServeUrl(url: string): boolean {
	return /\/wp-json\/mvs\/v1\/serve|[?&]rest_route=%2Fmvs%2Fv1%2Fserve/i.test(url);
}

function isRawUploadsUrl(url: string): boolean {
	return /\/wp-content\/uploads\/wpmediaverse\//.test(url);
}

test.describe('upload → sign → serve (lightbox URL leak target)', () => {
	test('grid thumbnails route through signed serve endpoint', async ({ page }) => {
		const failures: { url: string; status: number }[] = [];
		const rawLeaks: string[] = [];

		page.on('response', (resp: Response) => {
			const url = resp.url();
			if (isMvsServeUrl(url) && resp.status() >= 400) {
				failures.push({ url, status: resp.status() });
			}
			if (isRawUploadsUrl(url)) {
				rawLeaks.push(url);
			}
		});

		await autoLogin(page);
		await page.waitForLoadState('networkidle');

		const thumbnailRequests = await page.evaluate(() => {
			return Array.from(document.querySelectorAll('img.mvs-media-thumb')).map(
				(img) => (img as HTMLImageElement).currentSrc || (img as HTMLImageElement).src
			);
		});

		expect(thumbnailRequests.length).toBeGreaterThan(0);
		for (const src of thumbnailRequests) {
			expect(src).toMatch(/mvs_sig=/);
			expect(src).not.toMatch(/^https?:\/\/[^/]+\/wp-content\/uploads\/wpmediaverse\//);
		}

		expect(failures, `signed thumbnail requests must be 200, got: ${JSON.stringify(failures)}`).toEqual([]);
		expect(rawLeaks, `raw /wp-content/uploads/wpmediaverse/ URLs leaked: ${rawLeaks.join(', ')}`).toEqual([]);
	});

	test('lightbox opens with signed full-file URL (1.1.3 regression target)', async ({ page }) => {
		const lightboxImageRequests: { url: string; status: number }[] = [];

		page.on('response', (resp: Response) => {
			if (isMvsServeUrl(resp.url())) {
				lightboxImageRequests.push({ url: resp.url(), status: resp.status() });
			}
		});

		await autoLogin(page);
		await page.waitForLoadState('networkidle');

		// Click the first single-media thumbnail (NOT an album link).
		const mediaThumb = page.locator('a[href*="/media/"]:not([href*="/album/"]) img.mvs-media-thumb').first();
		await mediaThumb.waitFor({ state: 'visible' });

		const thumbnailRequestCountBeforeClick = lightboxImageRequests.length;
		await mediaThumb.click();

		// Lightbox renders via Interactivity API on the same page (no nav).
		// Wait for the lightbox to settle and the original-image fetch to land.
		await page.waitForTimeout(1500);

		const newRequests = lightboxImageRequests.slice(thumbnailRequestCountBeforeClick);
		expect(newRequests.length, 'lightbox should fetch the signed original/sized image').toBeGreaterThan(0);

		for (const req of newRequests) {
			// 200 OK or 206 Partial Content (range request for streaming) are both fine.
			expect.soft(req.status, `lightbox image ${req.url} returned ${req.status}`).toBeLessThan(400);
			expect(req.url).toMatch(/mvs_sig=/);
		}

		const fail = newRequests.find((r) => r.status >= 400);
		expect(fail, fail ? `lightbox 403 leak: ${JSON.stringify(fail)}` : '').toBeUndefined();
	});

	test('every wpmediaverse-served URL on the page carries a mvs_sig token', async ({ page }) => {
		const rawLeaks: string[] = [];

		page.on('request', (req) => {
			const url = req.url();
			if (isRawUploadsUrl(url)) {
				rawLeaks.push(url);
			}
		});

		await autoLogin(page);
		await page.waitForLoadState('networkidle');

		// Scroll once to trigger any lazy-loaded thumbnails below the fold.
		await page.evaluate(() => window.scrollBy(0, 1200));
		await page.waitForTimeout(800);

		expect(rawLeaks, `unsigned uploads URLs requested: ${rawLeaks.join('\n')}`).toEqual([]);
	});
});
