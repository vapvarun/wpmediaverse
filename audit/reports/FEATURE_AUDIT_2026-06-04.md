# MediaVerse Full Feature Audit — 2026-06-04

Master/sub-agent audit, 21 of 23 features (quota already audited separately 2026-06-04; quota + video agents failed structured output). Three axes per feature: **API**, **data flow**, **UX integration**. Adversarial-verify stage failed on a model-availability blip, so findings below are **agent-reported**; the P0 privacy-leak class was **hand-verified by direct code read** (8 render files confirmed). Raw data: `FEATURE_AUDIT_2026-06-04.json`.

Product lens (owner): "give the customer a better solution instead of half-cooked or over-cooked complication." Every feature scored MIXED — none fully half-cooked, none fully over-cooked, but every one has a customer-facing gap.

## P0 — SYSTEMIC PRIVACY LEAK (hand-verified, ship-blocker)

The exact bug class fixed on BP profile tabs (f8939f0) — raw `SELECT ... FROM mvs_media_index WHERE status='publish'` with **no privacy filter** — is repeated across **8 render surfaces**. Private / members-only / friends-only media (and in two cases unmoderated pending/rejected media) renders to **anyone, logged out included**.

| Surface | File | Leak |
|---|---|---|
| Explore Feed block + `[mvs_explore_feed]` | `src/blocks/explore-feed/render.php:26` | private + pending/rejected → everyone |
| Album page template | `templates/album.php:30` | members/private album contents → everyone |
| Album Viewer block + `[mvs_album]` | `src/blocks/album-viewer/render.php:26` | same album leak, 2nd/3rd surface |
| Media Grid block | `src/blocks/media-grid/render.php:42` | private media in grid |
| Story Viewer block | `src/blocks/story-viewer/render.php:33` | private stories |
| Media Stats block | `src/blocks/media-stats/render.php:43` | private counted in public totals |
| BP Group Media tab | `Integrations/BuddyPress/GroupTabIntegration.php:118` | group/members/private → non-members |
| Pro Instagram layout | `wpmediaverse-pro/.../InstagramLayout.php:96` | private photos in feed |
| Pro Leaderboard | `wpmediaverse-pro/.../LeaderboardRenderer.php:124` | private media inflates public score |
| REST `GET /media/{id}/comments` + `/reactions` | `CommentController.php`, `ReactionController.php` | comments/reactions on private media world-readable |

**Root cause:** these bypass `MediaRepository::query()` / `build_privacy_where()` (the gated path) and hand-write SQL. **Fix:** route every one through `MediaRepository::query()` with viewer scope (the `profile`/`visible` privacy mode added in f8939f0), and add `PrivacyService::can_view()` to the two REST GETs. One shared fix pattern closes all 10. This is the same fix shape as f8939f0 — high confidence, low risk.

## P1 — other blockers / high (agent-reported, need code-verify before fix)

- **Upload `replace_file` bypasses the pipeline** (`MediaController.php:833`) — replacing a private item pushes raw bytes to the public CDN, keeps old thumbnails, no EXIF strip, no extension block, orphans old variants, fires no hook. Route through `UploadService`.
- **Hash dedup is site-global** (`UploadService.php:125`) — discloses another user's private media ID to an uploader. Scope to author.
- **Free reports are write-only** (`ReportService.php` + `ModerationQueue.php`) — members file reports into `mvs_reports`, free owner has no UI to read/resolve them (lives only in Pro). Either a minimal free queue or document Pro-only clearly.
- **BuddyBoss "All" import stops after photos** (`BuddyBoss/MigrationAdmin.php:311`) — documents + videos never import, but UI shows "Completed". Walk to next table.
- **Album dedup split store** — existence check and marker write hit different stores → re-import duplicates.

## Three-entry-point gaps (CLAUDE.md Rule 18 — the dead-feature class)

Tables/engines built but missing a frontend or API entry point a customer can reach:

- **Moderation REST** (6 routes) + **user block/report** routes — fully built, **no free UI consumes them**. Block engine enforced but unreachable from free.
- **AI `/analyze` + `/ai/usage`** routes — built, permissioned, **no consumer**.
- **Connector `/export` + `/sync`** routes — dead.
- **Storage `GET /media/{id}/signed-url`** — no internal consumer (candidate headless/app seam — keep + document for the app).
- **Access-rules monetization stubs** (price/currency, EXPLICIT_GRANT_TYPES) — no payment flow in free.

## App-readiness (REST-only drivability — Rule 18, app is planned)

API axis is the strongest overall: routes are namespaced `mvs/v1`, honest `X-WP-Total` pagination, enum validation, rate-limited writes. **But for a native app:**
- Auth is cookie/nonce-assumed across controllers — the app needs token auth (Application Passwords work today; JWT/OAuth is a feature decision).
- The privacy leaks above are also **API leaks** (`/media`, `/comments`, `/reactions`) — must be gated before the app ships them.
- Several member features are **template-only** (no REST): the download flow, document/PDF viewing on single page, profile-edit lives in 3 duplicated markups. App needs these as routes.

## Over-cooked — simplify/remove (no customer value)

- **4 parallel view/download recorders** (MediaController, SignedUrlService, StatsService, MediaRepository) — collapse to one owner. `StatsService::record_download()` is dead.
- **ShareService** — dead class duplicating `MediaController::record_share` with a divergent hook. Two share hooks for one action.
- **3 duplicated profile-edit markups** (page template, shortcode body, dashboard partial) — the source of the DM-settings-missed-a-surface bug. Collapse to one partial.
- **5 privacy-decision reimplementations** — collapse onto `can_view` / `build_privacy_where` (this is also what fixes the P0 leak).
- **Two Explore implementations** (archive template + block render) — collapse block onto `MediaRepository::query()`.
- `mvs_ai_cost_per_call` — pseudo-precision dollar knob that can't track real spend.
- License `get_tier()` maps tiers but no tier changes anything; no actual Pro enforcement gate.

## Per-feature verdict matrix

All MIXED overall. Axis breakdown (RS=right-sized, M=mixed, HC=half-cooked):

| Feature | API | Data | UX | Blockers |
|---|---|---|---|---|
| upload | M | M | HC | 1 |
| storage-serve | M | RS | RS | 0 |
| privacy | M | M | HC | 1 |
| explore | RS | HC | M | 1 |
| media-single | M | M | HC | 1 |
| albums-collections | RS | M | M | 2 |
| dashboard-profile | RS | M | M | 0 |
| social | M | M | M | 1 |
| notifications | RS | M | HC | 0 |
| moderation | M | M | HC | 1 |
| messaging | M | M | M | 0 (group work in-flight) |
| bp-integration | RS | M | M | 2 |
| stats-analytics | M | HC | M | 0 |
| ai | M | RS | HC | 0 |
| admin-settings | RS | RS | M | 0 |
| cleanup-gdpr | RS | M | HC | 0 |
| gamification | M | M | M | 1 |
| connectors-feeds | M | M | HC | 1 |
| cloud-drivers | RS | M | M | 0 |
| importers | RS | HC | M | 1 |
| license | RS | M | M | 0 |

## Recommended order

1. **P0 privacy leak** — one shared fix (route 8 surfaces + 2 REST GETs through the gated path). Verify each surface in-browser per the just-added Rule 18. **Before 1.6.0 ships.**
2. **Upload replace_file + hash dedup** — security (private→CDN, cross-user ID disclosure).
3. **Three-entry-point gaps** — decide per feature: wire the missing UI/API, or move to Pro, or remove the dead route. Drives the app-API backlog.
4. **Over-cooked consolidation** — collapse the duplicate recorders / profile-edit markups / privacy reimplementations (also hardens against the leak recurring).
5. **Importers + reports + notifications dedup** — customer-visible polish.
