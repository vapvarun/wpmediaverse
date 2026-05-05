import { expect, test, Page, Response } from '@playwright/test';

/**
 * Phase 0c gap-test: BuddyPress activity media rendering.
 *
 * BP activity HTML is BAKED into bp_activity.content and read by anyone
 * scrolling the feed days or months later. The 1.1.x default `mvs_signed_url_ttl`
 * (1h) caused every activity older than 1 hour to render with broken
 * images. The 1.1.3 patch added MediaUrl::for_broadcast() (1-year TTL,
 * user_id=0). Phase 0a moved that to `MediaRepository::get_broadcast_url`
 * / `get_broadcast_thumbnail_url`. This spec encodes the contract: BP
 * activity HTML emits long-lived signed URLs, and they all load.
 *
 * Contract:
 *   1. Visiting an activity stream renders ALL `<img>` tags inside
 *      `.mvs-activity-media` with naturalWidth > 0 (no broken-image icon,
 *      no <img src="">).
 *   2. At least one activity image URL has `mvs_uid=0` (anonymous viewer)
 *      AND an expiry > 30 days out (broadcast TTL contract).
 *   3. Zero 403s on any wpmediaverse-served URL during render.
 */

const ACTIVITY_URL = '/members-2/varundubey/activity/?autologin=1';

async function visitActivity(page: Page): Promise<void> {
	await page.goto(ACTIVITY_URL, { waitUntil: 'domcontentloaded' });
	await page.waitForLoadState('networkidle');
}

function isMvsServeUrl(url: string): boolean {
	return /\/wp-json\/mvs\/v1\/serve|[?&]rest_route=%2Fmvs%2Fv1%2Fserve/i.test(url);
}

function parseMvsParams(url: string): URLSearchParams {
	try {
		return new URL(url).searchParams;
	} catch {
		return new URLSearchParams();
	}
}

test.describe('BP activity media rendering (broadcast-TTL contract)', () => {
	test('every <img> inside .mvs-activity-media loads (no broken-image icons)', async ({ page }) => {
		await visitActivity(page);

		const activityImages = await page.locator('.mvs-activity-media img').all();
		expect(activityImages.length, 'activity stream should contain media images').toBeGreaterThan(0);

		const broken: string[] = [];
		for (const img of activityImages) {
			const src = await img.getAttribute('src');
			const naturalWidth = await img.evaluate((el) => (el as HTMLImageElement).naturalWidth);
			if (!src || src === '' || naturalWidth === 0) {
				broken.push(`src=${src ?? '(null)'} naturalWidth=${naturalWidth}`);
			}
		}

		expect(broken, `broken activity images: ${broken.join('\n')}`).toEqual([]);
	});

	test('at least one activity image is signed with broadcast TTL (mvs_uid=0, expiry > 30d)', async ({ page }) => {
		await visitActivity(page);

		const activitySrcs = await page.locator('.mvs-activity-media img').evaluateAll((imgs) =>
			imgs.map((el) => (el as HTMLImageElement).src)
		);

		const broadcastUrls = activitySrcs.filter((src) => {
			if (!isMvsServeUrl(src)) return false;
			const params = parseMvsParams(src);
			const uid = params.get('mvs_uid');
			const exp = parseInt(params.get('mvs_exp') || '0', 10);
			const now = Math.floor(Date.now() / 1000);
			return uid === '0' && exp - now > 30 * 24 * 60 * 60; // > 30 days
		});

		expect(
			broadcastUrls.length,
			`expected at least one broadcast-TTL signed URL in activity HTML; activity srcs: ${activitySrcs.join('\n')}`
		).toBeGreaterThan(0);
	});

	test('zero 403s on wpmediaverse-served URLs during activity render', async ({ page }) => {
		const failures: { url: string; status: number }[] = [];

		page.on('response', (resp: Response) => {
			if (isMvsServeUrl(resp.url()) && resp.status() === 403) {
				failures.push({ url: resp.url(), status: resp.status() });
			}
		});

		await visitActivity(page);
		await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
		await page.waitForTimeout(800);

		expect(failures, `403s on activity media: ${JSON.stringify(failures)}`).toEqual([]);
	});
});
