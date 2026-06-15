# Rule 18 / Architecture Follow-up — scoped for 1.8.0

**Date:** 2026-06-15
**Scope:** Pre-existing Rule 18 ("three entry points per data store — frontend / admin / REST") and related architecture gaps surfaced by the 2026-06-15 Flow & Data audits.
**Release target:** **1.8.0 (NOT 1.7.0).** None of these are 1.7.0 regressions — they are standing gaps the 1.7.0 audit re-confirmed. 1.7.0 ships its bug-card fixes; these are deferred so 1.7.0 stays a focused bug/perf release.
**Sources:** `audit/reports/FLOW-DATA-AUDIT-free-2026-06-15.md` (Free §4 Risks 1-6), `audit/pro/FLOW-DATA-AUDIT-pro-2026-06-15.md` (Pro §4 Risks 1-5, §5 summary).

Rule 18 is doubly load-bearing here: a native mobile **app is planned**, so every member-facing data store MUST be fully drivable through REST alone. A table that only works through a PHP template or admin-ajax is app-blocking, and a table with no read surface is dead weight a customer pays for but can never use.

---

## Free plugin

Ranked by severity (highest first).

### F1 — `mvs_activity` is write-only (no read UI or rendering REST consumer)  — SEVERITY: CRITICAL
- **Missing entry point(s):** Frontend render + Admin. `ActivityService::log()` writes a row on every upload/reaction/comment/follow, and `ActivityController` exposes `GET /feed` + `GET /users/{id}/activity` — but **no Free template or block renders from either endpoint** (Explore queries `mvs_media_index` directly), and no admin page reads the table.
- **Customer impact:** Every row written to `mvs_activity` on a Free-only site is unreadable by end users — the activity feature is invisible. A mobile-app dev who discovers `GET /feed` assumes a UI counterpart exists; there is none. After the 1.7.0 fix that now also logs *private* uploads (journey 16), the table grows faster while still surfacing nowhere.
- **Recommended fix (one line):** Add a "Following / Activity" feed tab to the member dashboard that renders from `GET /feed`, plus a read-only admin activity viewer — wiring the existing REST to the two missing legs.

### F2 — `mvs_mentions` has no REST read endpoint and no admin UI  — SEVERITY: HIGH
- **Missing entry point(s):** REST read + Admin (and frontend list). `MentionService` writes rows on every `@mention`; they are only ever surfaced as a notification side-effect.
- **Customer impact:** Members can't see a list of media they were mentioned in (only by scrolling notifications); admins can't audit mentions; a mobile client can't build a "Mentions" screen. Write-only across all three dimensions.
- **Recommended fix (one line):** Add `GET /me/mentions` (paginated) consumed by a dashboard "Mentions" tab, and a read-only admin mentions list.

### F3 — `mvs_transactions` is dead weight in Free  — SEVERITY: HIGH (hygiene/clarity)
- **Missing entry point(s):** All three in Free — the table is created by the Free Migrator but has zero Free reader/writer; every consumer lives in Pro.
- **Customer impact:** Every Free-only site ships a dead table that confuses admins/devs inspecting the DB, and it is not declared as an intentional exception in the manifest.
- **Recommended fix (one line):** Move `mvs_transactions` creation to the Pro Migrator, OR flag it as an intentional Pro-owned exception in `manifest.tables.json` (no schema change in a patch — do it in the 1.8.0 minor).

### F4 — `mvs_access_rules` / `mvs_access_grants` have no admin list  — SEVERITY: MEDIUM
- **Missing entry point(s):** Admin (both tables). Full REST CRUD exists (`AccessController`) and the lock-overlay block reads them, but there is no admin page to view all access rules site-wide or to see who has been granted access to gated content. (`CollectionMetaBox` manages collection smart-rules, not per-media access rules.)
- **Customer impact:** A site owner can't manage access rules/grants without API access — an operational gap for the exact people who buy gated-content features.
- **Recommended fix (one line):** Add an "Access Rules" admin list page (rules + grants per media, with revoke) reading the existing REST.

### F5 — DM moderation is blind  — SEVERITY: MEDIUM
- **Missing entry point(s):** Admin, for `mvs_conversations`, `mvs_conversation_participants`, `mvs_messages`, `mvs_message_reactions`. Full REST + frontend exist; no admin/moderator surface.
- **Customer impact:** A moderator can't review a reported conversation thread, delete an abusive message, or see a flagged user's conversation list — yet `ReportService` lets users report other users (which typically triggers a DM review). Moderation is incomplete.
- **Recommended fix (one line):** Add a read-only admin "Conversations" moderation view (search by participant, view thread, delete message) behind a `manage_options`/moderator cap.

---

## Pro plugin

Ranked by severity (highest first).

### P1 — `mvs_credit_log` has no member REST read  — SEVERITY: HIGH
- **Missing entry point(s):** REST (member-facing). `QuotaService::get_credit_log()` is paginated and the admin `QuotaPage` shows it, but no REST route exposes a member's own credit history. `GET /me/quota` returns balance only.
- **Customer impact:** The planned mobile app can't show a member their credit purchase/deduction/grant history — a member-facing feature with no member read path. Rule 18 REST violation.
- **Recommended fix (one line):** Add `GET /mvs-pro/v1/me/credits` (paginated) over the existing `get_credit_log()`.

### P2 — Leaderboard has no paginated REST endpoint  — SEVERITY: HIGH (app-blocker)
- **Missing entry point(s):** REST. `LeaderboardRenderer` is a block/shortcode PHP string renderer only; `GET /compete-summary` returns one member's own rank, but there is no ranked-list endpoint.
- **Customer impact:** The native mobile app cannot render a leaderboard at all — a flagship gamification surface is app-invisible.
- **Recommended fix (one line):** Add `GET /mvs-pro/v1/leaderboard?source=…` returning the ranked, paginated user list the renderer already computes.

### P3 — `mvs_pro_collection_items` has no admin surface  — SEVERITY: MEDIUM
- **Missing entry point(s):** Admin. Migrator v7 added the table; `CollectionItemsController` + the 1.6.0 collection-picker JS wire REST + frontend; no admin page lists or manages memberships.
- **Customer impact:** A site owner can't audit which media are in which collections per user, count memberships, or delete stale ones without direct DB access.
- **Recommended fix (one line):** Add a collection-membership admin list (filter by collection/user, bulk remove) reading the existing REST.

### P4 — `mvs_boosts` admin blind spot  — SEVERITY: MEDIUM
- **Missing entry point(s):** Admin read. `BoostService::promote_boosted_in_feed()` reads active boosts and `GET /me/boosts` exists, but there is no admin view of all active boosts across users.
- **Customer impact:** The site owner can't see what's being promoted in Explore, by whom, or revoke an abusive/erroneous boost — a paid-visibility feature running with no owner oversight.
- **Recommended fix (one line):** Add an admin "Active Boosts" list (media, owner, impressions used, expiry, revoke).

### P5 — `mvs_competition_votes` admin blind spot  — SEVERITY: LOW
- **Missing entry point(s):** Admin read. Votes write across battle/challenge/tournament REST and show as counts in templates, but there is no admin vote-audit log.
- **Customer impact:** The owner can't investigate vote brigading / fraud on a contested competition — minor for MVP, but the first thing asked when a result is disputed.
- **Recommended fix (one line):** Add a read-only admin vote log (per competition: who voted, for which entry, when).

---

## Severity roll-up (1.8.0 backlog order)

| # | Plugin | Store / area | Missing leg(s) | Severity |
|---|--------|--------------|----------------|----------|
| F1 | Free | `mvs_activity` | Frontend + Admin | CRITICAL |
| F2 | Free | `mvs_mentions` | REST read + Admin | HIGH |
| F3 | Free | `mvs_transactions` | All three (dead weight) | HIGH (hygiene) |
| P1 | Pro | `mvs_credit_log` | REST (member read) | HIGH |
| P2 | Pro | leaderboard | REST | HIGH (app-blocker) |
| F4 | Free | `mvs_access_rules` / `mvs_access_grants` | Admin | MEDIUM |
| F5 | Free | DM moderation (4 tables) | Admin | MEDIUM |
| P3 | Pro | `mvs_pro_collection_items` | Admin | MEDIUM |
| P4 | Pro | `mvs_boosts` | Admin read | MEDIUM |
| P5 | Pro | `mvs_competition_votes` | Admin read | LOW |

**Recommended 1.8.0 cut line:** ship F1, F2, P1, P2 (the CRITICAL/HIGH member-facing + app-blocking gaps) as the headline Rule 18 catch-up; F3 as a hygiene cleanup in the same minor; defer F4/F5/P3/P4/P5 to 1.8.x admin-tooling follow-ups if scope is tight. None of these belong in 1.7.0.
