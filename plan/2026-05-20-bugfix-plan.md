# Bug-fix plan — Basecamp Bugs column (investigated 2026-05-20)

Read-only investigation done; **no code changed yet**. Each bug verified against
current `1.4.0` source. Project ` WPMediaVerse` `46336461`, Bugs column
`9667036607`.

---

## BUG 1 — Video thumbnail blank in Safari & Bing  (card 9910574354)
**Severity:** P3 · **Category:** Display · **Effort:** ~30 min · **Confidence:** high

**Verified root cause (NOT what the card suggests).** The activity `<video>` at
`includes/Integrations/BuddyPress/ActivityContentIntegration.php:157` *already*
supports a poster: line 151 builds `$poster_url` from
`SignedUrlService::generate_thumbnail($media_id, …, 'large')`, added at 152-154.
But `generate_thumbnail()` (`includes/Services/SignedUrlService.php:120`) returns
**`false`** for any video with no generated poster frame (the
`has_resolvable_thumbnail()` gate at ~line 155). So `$poster` stays empty → the
tag renders `<video preload="metadata">` with **no `poster=`** → Safari/iOS and
Bing show a blank player (they don't paint a frame from `preload=metadata`;
Chrome does, which is why it "works" there).

The card's suggested `preload="metadata"` → `preload="auto"` is a band-aid: it
forces a heavier download and still does **not** reliably give Safari a poster.

**Proper fix (pattern already exists in the codebase).**
`TemplateHelpers::default_video_poster_url()` (`includes/Core/TemplateHelpers.php:508`,
filter `mvs_default_video_poster_url`, asset `assets/images/default-video-poster.svg`)
is the canonical fallback, and `TemplateHelpers.php:368` already does
`$poster_url = '' !== $thumb_url ? $thumb_url : self::default_video_poster_url();`.
Mirror that in `ActivityContentIntegration.php:151-154`: when `generate_thumbnail`
returns empty, fall back to `TemplateHelpers::default_video_poster_url()` so the
`<video>` always carries a `poster=`. Keep `preload="metadata"` (cheaper).

**Risk:** very low — render-time only, single template, reuses shipped helper +
asset. No data writes.
**Verify:** activity video in Safari shows the default poster; videos that *do*
have a real poster still use it.

---

## BUG 3 — Members/Friends/Only-me activities visible in sitewide stream (card 9867136209)
**Severity:** P1 (privacy leak) · **Category:** Logic/Permission · **Effort:** unknown until reproduced · **Confidence:** medium — needs local repro before any code change

**Card history is contradictory** (verified against current source):
- A 2026-05-11 comment claimed the read-side SQL filter exists + works.
- A 2026-05-15 comment said the read-side filter was **deferred to 1.3.0**.
- Current code: `ActivityPrivacyFilter` **does exist**
  (`includes/Integrations/BuddyPress/ActivityPrivacyFilter.php`, 11 KB), wired in
  `BuddyPressManager.php:34`, hooking `bp_activity_get_join_sql`,
  `bp_activity_get_where_conditions`, `bp_activity_total_activities_sql`,
  `bp_activity_get_user_join_filter`. Write-side meta `_mvs_activity_privacy`
  is set on both upload paths; `hide_sitewide=1` is set **only for `private`**
  via `ActivitySyncIntegration::privacy_to_hide_sitewide()` (line 82) →
  `should_hide_for_media()` (line 460) at insert + `update_single_activity_hide_sitewide()`
  on privacy change. So the read-side filter shipped in 1.3.0.

**So the bug is NOT "filter missing."** It's one of (must reproduce to pin down):
1. A query path that bypasses the 4 hooks (BP **REST** `/buddypress/v1/activity`,
   or a theme/AJAX path on Reign/BuddyX) — most likely culprit.
2. A logic gap in `ActivityPrivacyFilter::build_where_clause()` (~lines 188-227) —
   e.g. NULL `_mvs_activity_privacy` treated as public, or an over-broad OR.
3. The reporter (Nitin, 2026-05-13) tested **"Only me"** — that *should* be hidden
   by `hide_sitewide=1`. If still visible, either the **composer path** isn't
   setting `hide_sitewide` (only the dashboard path is), or the **media image**
   itself is served independently of the activity row's visibility.

**Plan (verify-first, do NOT blind-fix):**
1. Repro on local: as User A, upload via the activity composer at Members /
   Friends / Only-me; inspect the activity row (`hide_sitewide`,
   `_mvs_activity_privacy` meta) for the composer path specifically.
2. View the sitewide stream logged-out AND as a non-friend; check both the
   web stream and the BP REST endpoint.
3. Pin which of (1)/(2)/(3) above is the actual leak.
4. Likely durable fix: harden `privacy_to_hide_sitewide()` to set
   `hide_sitewide=1` for **all** non-public levels as a DB-layer fallback
   (defense-in-depth behind the SQL filter), + cover the REST/AJAX query path.
   Confirm against the WHERE logic before changing.

**Risk:** medium — privacy logic on a 50+ site plugin; changing `hide_sitewide`
mapping affects what every viewer sees. Must reproduce + add a regression
journey before shipping.

---

## BUG 4 — Dark-mode issues on Reign / BuddyX Pro (card 9886883672)
**Severity:** P3 · **Category:** Display (CSS) · **Effort:** ~2-3 h · **Confidence:** medium-high

**Mechanism:** dark mode is scoped by `[data-theme="dark"]` (with
`html.dark-mode` / `body.dark-mode` fallbacks), defined in
`assets/css/frontend.css`. Four elements don't adapt:

- **(a) single-media title** — locate the title rule in `frontend.css`; confirm
  it has a `[data-theme="dark"]` override (likely missing or hardcoded hex).
  *Needs one more grep to pin the selector.*
- **(b) lightbox close button** — `.mvs-modal-close` at
  `assets/css/shared-ui-frame.css:88` has **no** `[data-theme="dark"]` override →
  hardcoded color invisible on dark. Fix: add a dark override (token or light color).
- **(c) delete confirm dialog** — `assets/css/mvs-confirm.css` **already has** a
  dark block (lines 97-112) but base colors are hardcoded (`#1d2327`, `#fff`,
  `#2271b1`…). Either the dark block is incomplete (title/message text not
  covered) or Reign/BuddyX toggles dark with a selector the block doesn't match.
  *Needs a quick browser repro on Reign/BuddyX to confirm which.*
- **(d) Pro challenges page** (`/media/challenges/`) — Pro `assets/css/gamification.css`
  and the `pro-challenges-list` / `pro-challenge` block CSS have **zero**
  `[data-theme]` coverage (Pro dark rules exist only in instagram-feed +
  messaging CSS). All colors hardcoded → unreadable. Fix: add dark overrides for
  the challenges title + content.

**Plan:** confirm the active dark selector on Reign/BuddyX in the browser, then
add `[data-theme="dark"]` overrides (prefer existing `--mvs-*` tokens over new
hex) per element. Browser-verify each at the affected page in dark mode (per the
verify-per-item rule).

**Risk:** low — additive CSS, dark-mode only. No logic.

---

## BUG 2 — Plugin activated but not in "Integration" tab (card 9901882156)
**Severity:** unknown · **Category:** unclear · **Effort:** blocked

Card body empty; the single comment says "activated but not appearing under the
Integration tab" with 2 screenshots (not yet viewable here). **"Integration tab"
is ambiguous** — could be a third-party plugin's integration list, BuddyPress
components screen, or a WPMediaVerse settings sub-tab. **Cannot root-cause
without seeing the screenshots / knowing which screen.**

**Plan:** view the 2 screenshots (Basecamp) or ask the reporter which
"Integration" screen + which plugin is missing, then trace registration. Do not
investigate code blind.

---

## Recommended order
1. **BUG 1** — verified, ~30 min, low risk, fix pattern already in code.
2. **BUG 4** — contained CSS batch (do (b)+(d) first; confirm (a)+(c) selector in browser).
3. **BUG 3** — highest impact but reproduce-first; allocate proper time + a regression journey.
4. **BUG 2** — unblock by viewing screenshots / asking the reporter.

All fixes route through the bug-fix workflow: reproduce → confirm root cause →
fix → browser-verify (per-item) → update the Basecamp card → move to Ready for
Testing. No code touched until you approve this plan.
