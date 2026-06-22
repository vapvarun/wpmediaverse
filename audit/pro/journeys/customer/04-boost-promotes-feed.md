---
journey: boost-promotes-feed
plugin: wpmediaverse-pro
priority: critical
roles: [member]
covers: [pro-boosts, boost-feed-promotion, boost-button-regression]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "Auto-login mu-plugin available (?autologin=1)"
  - "WPMediaVerse Pro active; mvs_boosts_enabled = '1'"
  - "Free wb-gamification (points backend) active — Plugin::points_backend_available() === true"
  - "Feed layout set to instagram (mvs_pro_feed_layout = 'instagram')"
  - "Test member owns >=1 published public media and has enough points for one boost"
estimated_runtime_minutes: 5
---

# Boost actually promotes media in the feed (regression sentinel for the boost-drop)

**Why this journey exists**: On 2026-06-22 a Pro template de-duplication delegated the
Explore (Instagram layout) and IG-profile feed-cards to the canonical
`templates/layouts/instagram/partials/feed-card.php`, which — unlike the old
`templates/partials/feed-card.php` — never carried the Boost button, the
`mvs-pro-boosts-store` script-module enqueue, or the `wp_footer` boost-modal. The
Boost control silently vanished from those two surfaces for boost-enabled sites.
It passed every existing check (HTTP 200, page rendered, no PHP error) because the
*absence* of a button is invisible to functional smoke tests. This journey makes
the boost feature a verified contract end-to-end so a dropped button, a dead
points deduction, or an un-applied feed filter fails CI instead of shipping.

It also guards the pre-existing defects fixed alongside it: points must really be
debited, and the boost must really reorder the feed (not a "fake boost").

## Setup

- Site: `$SITE_URL`
- Member: autologin as the media owner via `?autologin=<login>`
- Pick a target media: `mysql_query "SELECT media_id, post_author FROM wp_mvs_media_index WHERE status='publish' AND privacy='public' ORDER BY created_at ASC LIMIT 1"` — call it `$MEDIA_ID`, owner `$OWNER`.
- Record starting points: `mysql_query` the wb-gamification points balance for `$OWNER` — call it `$BAL_BEFORE`.

## Steps

### 1. Boost control renders on the IG feed (the regression assertion)
- **Action**: autologin as `$OWNER`, `playwright_navigate $SITE_URL/media/@$OWNER/` (IG layout profile feed).
- **Expect**: the owner's own card exposes `.mvs-ig-boost-btn` (a `button[data-wp-on--click="actions.openBoostModal"]`) inside `.mvs-ig-boost-wrap[data-wp-interactive="mvs-pro/boosts"]`. If this button is absent, the feed-card partial dropped the boost section — FAIL here.
- **Also expect**: the page enqueues the `mvs-pro-boosts-store` script module, and a boost modal partial is present after `wp_footer`.

### 2. Create a boost via REST
- **Action**: `curl -X POST $SITE_URL/wp-json/mvs-pro/v1/boosts -H "X-WP-Nonce: <nonce>" --data '{"media_id": $MEDIA_ID, "impressions_target": 500}'` (authenticated as `$OWNER`).
- **Expect**: HTTP 201/200 with a boost id; NOT a 4xx. (A `mvs_boost_gamification_unavailable` 4xx means the points backend prerequisite wasn't met — fix setup, not code.)

### 3. Points were actually deducted
- **Action**: re-read the `$OWNER` points balance.
- **Expect**: `$BAL_AFTER < $BAL_BEFORE` by exactly `ceil(impressions_target / 100) * cost_per_100`. Points MUST move — a boost that deducts nothing is the "fake boost" bug.

### 4. Boost row is active
- **Action**: `mysql_query "SELECT status, impressions_target, impressions_delivered FROM wp_mvs_boosts WHERE media_id=$MEDIA_ID ORDER BY id DESC LIMIT 1"`.
- **Expect**: one row, `status='active'`, `impressions_target=500`, `impressions_delivered>=0`.

### 5. Boosted media is promoted in the feed
- **Action**: `curl "$SITE_URL/wp-json/mvs/v1/media?per_page=20"` (the feed endpoint that applies `mvs_feed_media_ids`).
- **Expect**: `$MEDIA_ID` appears in the result set AND ahead of at least one non-boosted item it followed before the boost (BoostService::promote_boosted_in_feed moved it to the front). Re-querying increments `impressions_delivered` (record_impression ran).

### 6. Impression target completes the boost
- **Action**: drive `impressions_delivered` to `>= impressions_target` (repeat step 5 or seed the counter), then re-query the row.
- **Expect**: `status` flips to `completed` (record_impression's target check). The boost stops being promoted.

## Pass criteria

ALL of the following hold:
1. `.mvs-ig-boost-btn` renders on the owner's own card in the IG feed (boosts enabled + points backend present).
2. POST /mvs-pro/v1/boosts returns success (not 4xx) and creates an `active` row in `mvs_boosts`.
3. The member's points balance decreased by the computed cost (real deduction).
4. The boosted media appears ahead of non-boosted media in `GET /mvs/v1/media` (real promotion via `mvs_feed_media_ids`).
5. Reaching `impressions_target` flips the row to `completed`.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| `.mvs-ig-boost-btn` absent on the feed (step 1) | Feed-card partial dropped the boost section — the exact 2026-06-22 regression | `templates/layouts/instagram/partials/feed-card.php` (boost enqueue block near top + boost button in `.mvs-ig-actions-right`) |
| Boost button never appears even when enabled | Gating wrong, or points backend not detected | `Plugin::points_backend_available()`; `get_option('mvs_boosts_enabled')` |
| POST returns 4xx `mvs_boost_gamification_unavailable` | Points backend (wb-gamification) not active | prerequisite, not code |
| Points balance unchanged after boost | Deduction skipped/failed (the historic wrong-function-name "fake boost" bug) | `includes/Boosts/BoostService.php::create` (`\WBGam\Engine\PointsEngine::debit`) |
| Boosted media not promoted in feed | `mvs_feed_media_ids` filter not applied or BoostService not hooked | `includes/Boosts/BoostService.php::init` + `promote_boosted_in_feed`; `MediaController` `apply_filters('mvs_feed_media_ids', …)` |
| Boost never expires / promotes forever | `mvs_expire_boosts` cron not scheduled (time expiry) or impression check missing | `includes/Core/Plugin.php` (wp_schedule_event 'mvs_expire_boosts'); `BoostService::record_impression` target check |
