# WPMediaVerse — App-Readiness Spec (Plugin-side, Free + Pro)

Status: **DRAFT for review — no code until approved.**
Branch: `1.8.1` (both repos). Author pass: wbcom-mobile-app skill, Phase 1 readiness audit + API-completeness audit (live-probed against `mediaverse.local`).
Supersedes/extends: [`MEDIAVERSE-1.8.1-MOBILE-API.md`](./MEDIAVERSE-1.8.1-MOBILE-API.md) (the original 4-delta contract is folded in below as Phases 1–3 + 6).

## Goal

Make the **plugin 100% REST-ready for every feature the native app will display**, before any app code is written. Every member-facing feature must be fully drivable through `mvs/v1` (+ `mvs-pro/v1`) REST alone: complete read + write, viewer-relative state, honest pagination, one consistent media model, and app-password-safe auth. No admin-ajax-only or template-only member paths.

## Non-goals (this cycle)

- Deep security / performance / UX-design sweep (separate scope).
- Admin-only surfaces as app screens (moderation queue, analytics, quota admin stay desktop).
- Gamification **authoring** in-app (create contest stays desktop; view + vote are in-app).
- The app itself / `@wbcom/module-mediaverse` (that's Phase 3 of the mobile-app lifecycle, after this).
- Social login (BuddyNext-only, future).

## Locked decisions

| Decision | Choice |
|---|---|
| Audit scope | API-completeness (plugin 100% ready for app features) |
| Auth | WordPress core Application Passwords — **already working** (verified live, read+write, http+https). No JWT. |
| Stories | **Pro feature**, WhatsApp-minimal model, **clean-move** from Free (Free has no Stories UX today — hidden) |
| Process | Spec-first; implement phase-by-phase; verify per item; lockstep Free+Pro |

## Versioning note (decide before release)

Production Rule #7/#8: a **patch** (`1.8.x`) is bug-fixes only; **new features/endpoints are additive and belong in a minor (`1.9.0`)**. Everything in this spec is additive (new routes, new fields, new feature). **Recommendation: release this as `1.9.0`, not `1.8.1`.** The `1.8.1` branch can be the working branch; bump to `1.9.0` at release. Flagged for owner confirmation — does not block planning.

---

## Current state (verified live on mediaverse.local)

- `mvs/v1` = **80 routes**, `mvs-pro/v1` = **57 routes**. Core index advertises `application-passwords`.
- App Passwords work end-to-end (created real cred → `GET /me/profile` + `POST /media/{id}/favorite` succeeded, http & https).
- Member routes reject anonymous (401, cookieless gating). Public browse (`/feed`, `/media`) = 200.
- Pagination headers present on `/media`, `/me/media`, `/me/favorites`, `/comments`, `/feed` (`X-WP-Total`).
- **Gaps confirmed:** `/app/config` 404 · `/push/register-device` 404 · no `viewer_reaction`/`is_favorited` on media object · `/feed` & `/me/favorites` return divergent media shapes · no leaderboard route · comment objects lack `is_author/can_edit/can_delete` · write-time block enforcement only at data layer · Stories has no REST/admin.

---

## PHASE 1 — Canonical media read-model + viewer state (Free)

**Why:** the app renders the same media in a grid/feed/profile/favorites; today those endpoints return *different* shapes and none carry "did I favorite/react." This is the biggest app-experience unblock.

**Changes**
1. **Viewer fields on the media object** — add to `MediaController::prepare_item_for_response()` (`includes/REST/Controller/MediaController.php:1480`, beside `can_edit` at `:1558`):
   - `is_favorited` (bool) — current viewer favorited this media.
   - `viewer_reaction` (string|null) — viewer's reaction type (`like|love|haha|wow|sad|angry`) or null.
   - Both `null`/`false` for anonymous. Additive, backward-compatible.
2. **Batch hydration (no N+1)** — add a per-request batch path so a page of media resolves viewer state in **2 queries total**, not 2×N:
   - `FavoriteService::get_favorited_set(int $user_id, array $media_ids): array`
   - `ReactionService::get_user_reactions_map(int $user_id, array $media_ids): array`
   - Call once per list/feed page; `prepare_item_for_response()` reads from the prefilled set (same prefetch pattern as the 1.7.0 grid fix).
3. **One canonical media shape** — make `/feed` items and `/me/favorites` items carry the **same** media object (the full `prepare_item_for_response()` shape incl. viewer fields), instead of their current thin shapes:
   - `/feed`: `ActivityController` currently returns `{id,type,user,media_id,album_id,content,created_at,media{thin}}`. Replace the thin `media` sub-object with the canonical media object (keep the activity envelope).
   - `/me/favorites`: `FavoriteController` returns a thin shape — return the canonical media object instead.
4. **Fix `/users/{id}/media` N+1** — `UserController` builds items in a `foreach` with per-item repo calls; add `MediaRepository::prefetch($ids)` before the loop (1.7.0 pattern) so profile grids are query-bounded.
5. **Comment viewer-state** — add to `CommentService::format_comment()`:
   - `is_author` (bool), `can_edit` (bool: author within edit window OR moderator), `can_delete` (bool: author OR moderator).

**Manifest:** no new routes; note new response fields in `audit/manifests/manifest.rest.json`.
**Tests:** unit tests for the two batch helpers (set correctness, empty input, anonymous); assert `/media`, `/feed`, `/me/favorites` payloads carry viewer fields; assert `/users/{id}/media` query count is bounded.
**Acceptance:** a 20-tile grid renders favorite + reaction state from one list call; feed/favorites/profile all return the identical media model; 2000-item feed adds no per-row queries.

---

## PHASE 2 — `/app/config` + Pro feature-flag aggregation (Free + Pro)

**Why:** the app needs one pre-login call for branding + which features/modules to mount.

**Changes**
1. **New `ConfigController` (Free)** — `includes/REST/Controller/ConfigController.php`, registered in the controller array at `includes/Core/Plugin.php:775`.
   - `GET /mvs/v1/app/config` — **public** (`permission_callback => __return_true`).
   - Response:
     ```json
     {
       "accent_color": "#...", "logo_url": "...", "login_bg_url": "...",
       "dark_mode_default": false,
       "pro_active": true,
       "features": { "messaging": true, "reactions": true, "comments": true,
                     "albums": true, "stories": true,
                     "battles": true, "challenges": false, "tournaments": false,
                     "boosts": true, "streaks": true, "video": true }
     }
     ```
   - Branding: Pro white-label settings → Free core settings fallback. **Do not** restate site name/description/icon (those come from the core `/wp-json/` index).
2. **`mvs_app_config_features` filter (Free)** — Free seeds always-on/free flags (`messaging` from `mvs_dm_access_level !== 'nobody'`, `reactions`, `comments`, `albums`, AI). `pro_active` = whether `mvs_pro_loaded` ran.
3. **Pro contributes flags** — Pro hooks `mvs_app_config_features` and adds its toggles (read-only): `mvs_battles_enabled`, `mvs_challenges_enabled`, `mvs_tournaments_enabled`, `mvs_boosts_enabled`, `mvs_streaks_enabled`, `mvs_pro_transcode_enabled` (→`video`), and `mvs_stories_enabled` (→`stories`, from Phase 5). Additive — adding a plugin needs no Free change.

**Manifest:** add `/app/config` route + `mvs_app_config_features` filter (Free + Pro).
**Tests:** unauthenticated 200; toggling a Pro option flips its `features.*` key; `pro_active` false when Pro inactive.
**Acceptance:** app themes itself and decides mounted surfaces from one unauthenticated call.

---

## PHASE 3 — Write-time block/ban enforcement in REST permission callbacks (Free)

**Why:** App Passwords are minted by core and bypass login gates; today block/ban is enforced at the data layer (reads) and only for DMs on write — a blocked user can `200` a write. Skill hard-rule #2.

**Changes**
1. **Shared helper** — `MediaCapabilities::deny_if_blocked( int $actor, int $target ): ?WP_Error` (or a small `RestGuards` helper), returning `403 mvs_blocked` when either side blocked.
2. **Apply in `create_item_permissions_check()`** of: comments (`CommentController`), reactions (`ReactionController`), DMs (`MessagingController` create/message — confirm parity with existing data-layer gate), follow (`FollowController`).
3. **Site-level suspend/ban** — if a ban flag exists, gate all writes (parity with Jetonomy). If none exists, document as out-of-scope (no ban concept in MediaVerse today).

**Tests:** **contract test** — actor blocked by target gets `403` on POST comment / reaction / DM / follow (not `200`).
**Acceptance:** automated test proves blocked actor is `403` on every write path.

---

## PHASE 4 — Leaderboard REST route (Pro)

**Why:** the gamification screen needs a sortable, paginated leaderboard; today only a block renderer + `competitions/active-summary` exist (no REST list).

**Changes**
1. **Extract** `LeaderboardRenderer::fetch_leaders()` (`wpmediaverse-pro/includes/Frontend/LeaderboardRenderer.php:120-204`) into a shared `Leaderboard\LeaderboardService` (renderer + REST both consume it; no duplicate query).
2. **New `LeaderboardController` (Pro)** — `GET /mvs-pro/v1/leaderboard?source=reactions|media_count|gamification_xp&period=all|30d|7d&page=&per_page=` (public, `__return_true`, like `CompeteSummaryController:52-71`).
   - Response: `{ rows:[{ rank, user_id, display_name, avatar_url, profile_url, score, metric_label }], total, viewer_rank, viewer_score }`.
   - `viewer_rank`/`viewer_score`: secondary COUNT query for logged-in viewer (new — renderer has none).
   - Pagination via Free `REST\Pagination` (`Plugin.php:296`); set `X-WP-Total`/`X-WP-TotalPages`.
   - `gamification_xp` source stays delegated to `mvs_pro_leaderboard_xp_rows` (WB Gamification optional dep) — empty when absent.
   - Optional transient cache `mvs_pro_leaderboard_{source}_{period}` (30-min TTL) to avoid per-request SUM aggregations.

**Manifest:** add Pro route. **Tests:** ranking order per source; pagination totals; `viewer_rank` correct/absent for anon.
**Acceptance:** app renders a paginated leaderboard with the viewer's own rank in one call.

---

## PHASE 5 — Stories as a Pro feature (clean-move + WhatsApp-minimal build)

**Model (WhatsApp Status, minimal):** ephemeral 24h media; stories bar grouped by author (self first, then followed); full-screen sequential viewer (tap fwd/back, progress segments); view receipts ("seen by" on own story); reply → DM (reuse messaging); **no likes/comments on stories**; privacy follows existing media privacy.

**Engine relocation (Free → Pro), safe because Free has no Stories UX:**
- **Move** `StoryService` (`wpmediaverse/includes/Services/StoryService.php`), the `story-viewer` block (`src/blocks/story-viewer/`), and the `mvs_story_created` hook firing into Pro.
- **Remove** the hidden Free copies + the Free container key `'stories'` (`Plugin.php:315`).
- **Safety gate (implementation-time):** grep both repos for `'stories'`, `StoryService`, `mvs_story_created`, `story-viewer`, `is_story`, `story_expires_at`. Expected: zero external consumers (it was never surfaced). If an unexpected reference exists → leave a `@deprecated` shim in Free instead of deleting. Storage stays media-meta (`is_story` / `story_expires_at` in `mvs_media_meta`) — **no DB schema change**, so existing meta keeps working.

**Pro build (three entry points + gating):**
1. **Toggle** — `mvs_stories_enabled` in `ProSettings` (default per product call; suggest `'1'`).
2. **REST — `StoryController` (Pro, `mvs-pro/v1`)**, resolving the engine via `Plugin::free_service('stories')` (allowed boundary pattern; **no concrete Free import**):
   - `GET /mvs-pro/v1/stories` — active stories grouped by author (self first, then followed), viewer-relative (`viewed` bool per author/story), paginated, privacy-gated.
   - `POST /mvs-pro/v1/media/{id}/story` — mark an existing media as a story (`duration_hours` optional, default 24); owner-only.
   - `DELETE /mvs-pro/v1/media/{id}/story` — end early; owner-only.
   - `POST /mvs-pro/v1/stories/{media_id}/view` — record a view receipt (or reuse Free `POST /mvs/v1/media/{id}/view`; decide at impl — receipts need per-viewer rows).
   - `GET /mvs-pro/v1/stories/{media_id}/viewers` — "seen by" list; **own story only**.
   - Reply → reuse existing DM routes (no new endpoint).
3. **Admin** — Pro Stories page (active/expired list, author, expiry, force-expire/delete) under the WPMediaVerse menu (Pro admin pattern).
4. **Frontend** — Pro-owned stories bar + sequential viewer (the relocated `story-viewer` block, now Pro), gated by `mvs_stories_enabled`.
5. **Expiry** — keep the hourly `mvs_story_cleanup` cron (moves to Pro).
6. **app/config** — `stories` flag exposed via the Phase 2 filter.

**Manifest:** Pro gains StoryService/StoryController/routes/block; Free manifest drops the relocated entries.
**Tests:** create→appears in `/stories`; expiry removes it; non-owner cannot see viewers; viewer receipt recorded once; toggle off → routes 404/forbidden + block hidden; boundary check (no Free concrete import — Pro CI Rule 3).
**Acceptance:** app shows a WhatsApp-style stories bar + viewer driven entirely by `mvs-pro/v1`; Stories absent when Pro inactive or toggle off.

---

## PHASE 6 — Native push fan-out (Pro)

**Why:** notifications are poll-only today; a social app needs native push.

**Changes**
1. **Table** — `mvs_push_devices` via Pro `Migrator` bump: `id, user_id, expo_push_token (UNIQUE), platform (ios|android), device_name, created_at, updated_at`. UNIQUE token so it migrates between accounts on re-login. (Pro Migrator — schema change ⇒ minor release, consistent with the 1.9.0 recommendation.)
2. **Routes (Pro)** — `POST /mvs-pro/v1/push/register-device` `{expo_push_token, platform, device_name?}`; `DELETE /mvs-pro/v1/push/register-device`. Login-required.
3. **Fan-out** — bind a sender to the existing `NotificationService` events (reaction, comment, mention, follow, DM, moderation); in addition to the in-app row, POST to `https://exp.host/--/api/v2/push/send` for the user's tokens, **async** via Action Scheduler.
   - **Verify accepted-args count** on the notification hook (Jetonomy bug: hook fired more args than handler accepted → silent bail). Use `add_action(..., 10, N)` with correct N.
   - Deep-link payload `{ type: "media"|"comment"|"reaction"|"follow"|"conversation", id: int }`.

**Manifest:** add routes + `mvs_push_devices` table (Pro).
**Tests:** register/unregister; token uniqueness/migration; a fired notification enqueues an Expo send with deep-link (mock HTTP).
**Acceptance:** registering a device then triggering reaction/comment/DM delivers an Expo push with deep-link (final verify on a real/preview build — not the Expo dev client).

---

## PHASE 7 — Pagination/consistency + container viewer-state (Free + Pro)

**Why:** close the long-tail so every app list is honest and every container carries viewer state.

**Changes**
1. **Pagination totals** — audit + add `X-WP-Total`/`X-WP-TotalPages` where missing: `/users/{id}/followers`, `/users/{id}/following`, `/me/following`, `/me/followers`, `/feed` (`TotalPages`), and verify all Pro gamification lists (`/battles`, `/challenges`, `/tournaments`, `/boosts`). Enforce a `per_page` cap everywhere (big-site).
2. **Container viewer-state** — add `is_owner`/`can_edit` to album + collection objects, and `is_subscribed` to collections (smart-collection follow, if applicable).
3. **Album/collection items** — ensure they reuse the canonical media object from Phase 1.

**Tests:** header presence on each list; viewer fields on album/collection reads.
**Acceptance:** every app list can show totals/“load more”; every container shows owner/edit state.

---

## Cross-cutting (every phase, definition of done)

- **Manifest sync** in the same change (Free `audit/manifests/`, Pro `audit/pro/manifests/`).
- **Local CI green**: `composer ci` (php -l, WPCS, PHPStan, CSS/token, coding-rules incl. Pro Rule 3 boundary, settings-contract, manifest) + **`/wp-contract-audit`** as a release gate (keys read-never-written, hooks consumed-never-fired, JS action ↔ PHP handler).
- **REST verification** with a real app password (read + write) per new/changed route; **browser verification** for any admin/frontend surface (Stories admin + bar/viewer at 390px).
- **Lockstep**: bump Free + Pro together; `MVS_PRO_MIN_FREE` only if Pro starts calling new Free APIs (Stories uses existing `free_service('stories')` → check whether the new boundary needs a bump).
- **Production Rules**: additive only; no removals except the Stories clean-move (justified: hidden, zero references — verified at impl).

## Suggested sequence & dependencies

1 → 2 → 3 → 4 → 5 → 6 → 7. Phase 2's `mvs_app_config_features` filter is a prerequisite for the Stories flag (Phase 5) but ships independently. Phases 4/5/6 are Pro-weighted; 1/3/7 are Free-weighted. Each phase is independently shippable and verifiable.

## Open items for owner

1. **Release version** — ship as `1.9.0` (recommended, it's additive) vs keep `1.8.1`?
2. **`mvs_stories_enabled` default** — on or off out of the box?
3. **Story view receipts** — reuse Free `/media/{id}/view` or dedicated per-viewer rows for an accurate "seen by"? (Receipts need per-viewer rows; suggest dedicated.)
