import { expect, test, Page } from '@playwright/test';

/**
 * Phase 0c gap-test (Pro-paired): challenge-card cover-fallback rendering.
 *
 * Before Phase 0b, `mvs_competitions.cover_image_url` stored a raw URL
 * that 403'd against the .htaccess deny-all unless every read site
 * remembered to call MediaUrl::resolve(). The 1.1.3 patch added a
 * "derive-from-most-recent-entry" UX fallback for empty covers; Phase 0b
 * replaced URL-storage with `cover_media_id` (BIGINT FK to mvs_media_index.id)
 * and signing now happens automatically inside MediaRepository::get.
 *
 * The card-template uses Interactivity API class + visibility binding:
 *
 *   <div class="mvs-card-cover-wrap …">
 *     <img class="mvs-card-cover"
 *          data-wp-bind--src="context.item.cover_image_url"
 *          data-wp-bind--hidden="!context.item.cover_image_url"
 *          src="…signed-url…" />
 *   </div>
 *
 * The wrapper toggles between `mvs-card-cover-wrap--has-cover` (real cover
 * resolved) and `mvs-card-cover-wrap--placeholder` (empty state — the
 * inner <img> is `src="" hidden=""`, the wrapper paints the placeholder
 * gradient + image-icon SVG).
 *
 * This spec encodes the contract:
 *   - Each card belongs to exactly one of those two states.
 *   - Has-cover: the inner <img> has a non-empty src that contains a mvs_sig
 *     token (i.e. went through MediaRepository::get → SignedUrlService).
 *   - Placeholder: the inner <img> has `src=""` AND `hidden=""` AND the
 *     wrapper has the `--placeholder` class. (`<img src="">` without the
 *     placeholder signal is the bug we're protecting against.)
 *
 * Requires the Pro plugin active. Skipped automatically when the
 * /media/challenges/ surface returns 404.
 */

const CHALLENGES_URL = '/media/challenges/?autologin=1';

async function visitChallenges(page: Page): Promise<boolean> {
	const resp = await page.goto(CHALLENGES_URL, { waitUntil: 'domcontentloaded' });
	if (!resp || resp.status() === 404) {
		return false;
	}
	await page.waitForLoadState('networkidle');
	return true;
}

test.describe('challenge cover fallback (Pro-paired)', () => {
	test('every challenge card is in a valid has-cover OR placeholder state', async ({ page }) => {
		const proActive = await visitChallenges(page);
		test.skip(!proActive, 'Pro plugin inactive — challenge surface unavailable.');

		const cardStates = await page.locator('.mvs-card-cover-wrap').evaluateAll((wraps) =>
			wraps.map((wrap, i) => {
				const img = wrap.querySelector('img.mvs-card-cover');
				const src = img ? img.getAttribute('src') ?? '' : '';
				const hidden = img ? img.hasAttribute('hidden') : false;
				return {
					index: i,
					hasCoverClass: wrap.classList.contains('mvs-card-cover-wrap--has-cover'),
					hasPlaceholderClass: wrap.classList.contains('mvs-card-cover-wrap--placeholder'),
					imgSrc: src,
					imgHidden: hidden,
					html: wrap.innerHTML.slice(0, 240),
				};
			})
		);

		expect(cardStates.length, 'challenges page should render at least one card').toBeGreaterThan(0);

		const violations: string[] = [];
		for (const card of cardStates) {
			const isHasCover = card.hasCoverClass && !card.hasPlaceholderClass;
			const isPlaceholder = card.hasPlaceholderClass && !card.hasCoverClass;

			if (!isHasCover && !isPlaceholder) {
				violations.push(
					`Card #${card.index} is in neither --has-cover nor --placeholder state: classes=${JSON.stringify({
						hasCoverClass: card.hasCoverClass,
						hasPlaceholderClass: card.hasPlaceholderClass,
					})}`
				);
				continue;
			}

			if (isHasCover) {
				if (card.imgSrc === '' || card.imgHidden) {
					violations.push(
						`Card #${card.index} is in --has-cover state but inner <img> is hidden or src="" (HTML: ${card.html})`
					);
					continue;
				}
				if (!/mvs_sig=/.test(card.imgSrc)) {
					violations.push(
						`Card #${card.index} cover URL is not signed: ${card.imgSrc.slice(0, 120)}`
					);
				}
			}

			if (isPlaceholder) {
				// Placeholder must explicitly hide the <img> AND have empty src.
				// `<img src="">` without `hidden` is the broken-image-icon bug.
				if (!card.imgHidden) {
					violations.push(
						`Card #${card.index} is in --placeholder state but inner <img> is not hidden (HTML: ${card.html})`
					);
				}
			}
		}

		expect(violations, violations.join('\n')).toEqual([]);
	});

	test('no <img src=""> renders without the placeholder hide signal', async ({ page }) => {
		const proActive = await visitChallenges(page);
		test.skip(!proActive, 'Pro plugin inactive — challenge surface unavailable.');

		// Empty src is OK ONLY when the <img> is `hidden` (Interactivity API
		// data-wp-bind--hidden="!context.item.cover_image_url" toggle) AND
		// the wrapper carries the placeholder class. Anywhere else, an empty
		// src renders a broken-image icon — the bug Phase 0c protects against.
		const violations = await page
			.locator('.mvs-card-cover-wrap img[src=""]')
			.evaluateAll((imgs) =>
				imgs
					.filter((img) => {
						const wrap = img.closest('.mvs-card-cover-wrap');
						const hidden = img.hasAttribute('hidden');
						const placeholder = wrap?.classList.contains('mvs-card-cover-wrap--placeholder');
						return !hidden || !placeholder;
					})
					.map((img) => img.outerHTML.slice(0, 200))
			);

		expect(
			violations,
			`<img src=""> emitted without the placeholder hide signal:\n${violations.join('\n')}`
		).toEqual([]);
	});

	test('zero 403s on cover thumbnail requests', async ({ page }) => {
		const failures: { url: string; status: number }[] = [];

		page.on('response', (resp) => {
			const url = resp.url();
			if (
				/mvs\/v1\/serve|rest_route=%2Fmvs%2Fv1%2Fserve/i.test(url) &&
				resp.status() === 403
			) {
				failures.push({ url, status: resp.status() });
			}
		});

		const proActive = await visitChallenges(page);
		test.skip(!proActive, 'Pro plugin inactive — challenge surface unavailable.');

		expect(failures, `403s on challenge covers: ${JSON.stringify(failures)}`).toEqual([]);
	});
});
