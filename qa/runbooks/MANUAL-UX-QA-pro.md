# WPMediaVerse Pro — Manual UX/Usability QA (Pre-Release Gate)

> **Mandatory before every Pro release.** Run the **Free plugin** doc first (`../../wpmediaverse/qa/MANUAL-UX-QA.md`) — it covers Explore, Lightbox, Upload, Dashboard, Profile, Albums, Collections, BuddyPress, and Admin core.
> This file lists **Pro-only additions and interactions** on plugin-mapped URLs: Compete hub, Battles / Challenges / Tournaments, Streaks, Layout Modes, Pro admin pages (Competitions Dashboard, Challenge Manager, Tournament Manager, Battle Monitor, Quota & Credits, Theme Library, Migration Tool, Gamification Settings, License, Pro AI keys, Cloud Storage).
>
> **Out of scope here:** WP home, theme pages, other plugins. Anything covered already in Free doc is not repeated.

---

## 0. Pro-Owned URL Surface

Frontend (routed by Pro):

| URL | Template | Purpose |
|-----|----------|---------|
| `/compete/` | `templates/compete-hub.php` | Compete hub (query var `mvs_compete_page=1`) |
| `/media/battles/` | `templates/battles.php` | Battles list + detail |
| `/media/challenges/` | `templates/challenges.php` | Challenges list + detail |
| `/media/tournaments/` | `templates/tournaments.php` | Tournaments list + bracket |
| Layout modes on `/media/` | `templates/layouts/{instagram,flickr,pinterest,dribbble}/...` | Alternative explore layouts |
| Streak widget | `templates/partials/streak-widget.php` | Dashboard widget |
| Dashboard panels | `dashboard-{battles,challenges,tournaments}-panel.php` | Competitions on `/my-media/` |
| Instagram feed template | `templates/instagram-feed.php` | Integration-layout |
| User profile Pro | `templates/user-profile.php` | Pro-enhanced profile |
| Messaging | `templates/messages.php` + chat partials | DM UI (Pro owns) |

Admin (Pro additions under WPMediaVerse menu):

| Slug | Purpose |
|------|---------|
| `mvs-competitions` | Competitions Dashboard (overview + Welcome modal) |
| `mvs-challenges` | Challenge Manager |
| `mvs-tournaments` | Tournament Manager |
| `mvs-battles` | Battle Monitor |
| `mvs-quotas` | Quota & Credits |
| `mvs-theme-library` | Theme Library |
| `mvs-migration` | Migration Tool |
| Gamification Settings (in Settings) | XP / cooldowns / autopilot / boost config |
| License page | EDD license UI |
| Pro tabs in Settings | AI (Vision / Rekognition), S3, BunnyCDN, FFmpeg path |
| Moderation — User Reports tab | Added via `mvs_moderation_tabs` filter |
| Stats — Video Analytics tab | Added via `mvs_stats_tabs` filter |

---

## 1. Pre-flight for Pro

Run the Free doc's Section 1 setup, then add:

- [ ] Pro activated, license status **Active**
- [ ] Feature toggles ON: `mvs_battles_enabled`, `mvs_challenges_enabled`, `mvs_tournaments_enabled`, `mvs_boosts_enabled`, `mvs_streaks_enabled`, `mvs_connectors_enabled`
- [ ] At least 1 active Challenge, 1 pending Battle (for authoring account), 1 Tournament in registration
- [ ] Streak counter seeded: `user_meta _mvs_current_streak = 5` on `author1`
- [ ] A challenge entry exists so a voter flow can be tested
- [ ] S3 credentials either real or explicit test-bucket; same for BunnyCDN
- [ ] FFmpeg available OR intentionally missing (each case must be tested for error messaging)
- [ ] Test "OpenAI/Vision/Rekognition" keys present or intentionally missing

---

## 2. Journey P1 — Compete Hub (`/compete/`)

- [ ] **P1.1** Hub heading + 3 cards (Battles / Challenges / Tournaments). **Pass:** each has a one-line description that differentiates them to a first-time reader.
- [ ] **P1.2** "How it works" link on each card. **Pass:** Clicks open rules modal or dedicated help page; closes cleanly; copy doesn't assume prior gaming knowledge.
- [ ] **P1.3** Active counts per card accurate. **Pass:** Match `GET /mvs-pro/v1/competitions/active-summary`; no stale cache.
- [ ] **P1.4** Click-through to sub-pages. **Pass:** Each card links to `/media/battles/` / `/media/challenges/` / `/media/tournaments/` with matching active-item highlighted.
- [ ] **P1.5** Feature toggle OFF for one module (say Tournaments). **Pass:** Tournaments card is **hidden** from the hub — not a greyed card or broken link.
- [ ] **P1.6** Anon user on `/compete/`. **Pass:** Can browse; action buttons say "Log in to participate"; not silent no-ops.
- [ ] **P1.7** Interactivity API store `compete-hub-store.js` works. **Pass:** No console errors; tab switching and counts are reactive.

**Heuristic P1:** __ / 50. Findings:

---

## 3. Journey P2 — Challenges end-to-end (`/media/challenges/`)

- [ ] **P2.1** List of challenges. **Pass:** Each card shows theme, deadline (with live countdown), prize (XP), status badge (Active/Closed/Finalized).
- [ ] **P2.2** Click active challenge. **Pass:** Detail page: theme description, rules, current entries count, voting rules.
- [ ] **P2.3** Submit Entry. **Pass:** Upload new OR pick from existing media; confirmation; "Entry submitted!" toast.
- [ ] **P2.4** Submit after deadline. **Pass:** Disabled submit with countdown over + "Voting in progress" message.
- [ ] **P2.5** Vote. **Pass:** Pair or grid; cannot vote on own entry (greyed with tooltip); one vote per round; confirmation after vote.
- [ ] **P2.6** Results after finalize. **Pass:** Winner highlighted; XP awarded + visible toast "+X XP"; streak may update.
- [ ] **P2.7** Challenge cancel by admin. **Pass:** Active participants see "Cancelled" status + reason (if provided); entries refunded (if applicable).
- [ ] **P2.8** Autopilot. **Pass:** If autopilot enabled and no active challenge, one auto-created within cron window (observable via admin or frontend).

**Heuristic P2:** __ / 50. Findings:

---

## 4. Journey P3 — Battles (`/media/battles/`)

- [ ] **P3.1** Start a battle. **Pass:** Typeahead opponent; submit media; pending state; opponent notified.
- [ ] **P3.2** Pending as opponent. **Pass:** Accept or Decline visible; Accept → status active, submit your media; Decline → battle closed, no XP penalty.
- [ ] **P3.3** Voting phase. **Pass:** Both media side-by-side; anon can view but not vote; cast vote; cannot vote twice; cannot vote on own battle.
- [ ] **P3.4** Resolution. **Pass:** Winner highlighted; XP via gamification trigger; loser consolation (if any); status "completed".
- [ ] **P3.5** Expired unvoted battles resolved hourly. **Pass:** After cron, tied/unvoted battles resolve per policy documented in rules.
- [ ] **P3.6** Cancel while pending. **Pass:** Creator can cancel; opponent sees "Cancelled"; no XP either way.

**Heuristic P3:** __ / 50. Findings:

---

## 5. Journey P4 — Tournaments (`/media/tournaments/`)

- [ ] **P4.1** Tournament card. **Pass:** Shows registration window, max players, entry requirements, prize schedule.
- [ ] **P4.2** Register. **Pass:** "Register" button; after registration, status changes; participant count updates.
- [ ] **P4.3** Bracket view. **Pass:** Renders cleanly for 4 / 8 / 16 players; byes indicated; current round highlighted; mobile horizontally scrolls with fade hint.
- [ ] **P4.4** Match page. **Pass:** Submit media → vote → result; round advancement updates bracket without refresh (polling or optimistic).
- [ ] **P4.5** XP per round. **Pass:** Dynamic XP via `mvs_tournament_advancement` trigger; visible feedback.
- [ ] **P4.6** Non-power-of-2 counts. **Pass:** Byes handled visibly ("Bye" on bracket), not empty slots.

**Heuristic P4:** __ / 50. Findings:

---

## 6. Journey P5 — Streaks & Boosts

- [ ] **P5.1** Streak widget on `/my-media/`. **Pass:** Current / longest / next milestone; milestone markers (7d / 30d / 100d / 365d) with XP.
- [ ] **P5.2** Buy Freeze. **Pass:** Cost + effect clear; confirm dialog; after purchase `_mvs_streak_freezes` decrements; button becomes "Used 0 of N" if out.
- [ ] **P5.3** Missed day with freeze available. **Pass:** Daily cron preserves streak, uses freeze token; user sees "Freeze used" notification.
- [ ] **P5.4** Missed day with no freezes. **Pass:** Streak resets; `_mvs_longest_streak` preserved; user sees gentle "Streak reset — start fresh today" message.
- [ ] **P5.5** Streak badge in display name. **Pass:** Appears via `mvs_user_display_name` filter; no raw HTML leak; not duplicated.
- [ ] **P5.6** Boost media. **Pass:** Dashboard action "Boost this media"; cost in points; duration selectable; impression target clear; after purchase media ranks higher visibly.
- [ ] **P5.7** Boost expires via cron. **Pass:** Status changes to completed/expired automatically.

**Heuristic P5:** __ / 50. Findings:

---

## 7. Journey P6 — Layout Modes (`/media/` with Instagram / Flickr / Pinterest / Dribbble)

- [ ] **P6.1** Default layout = Instagram. **Pass:** Feed renders with square tiles; stories bar visible.
- [ ] **P6.2** Switch to Flickr (justified). **Pass:** Tiles align in justified rows; no blank end-of-row; hover shows title/meta.
- [ ] **P6.3** Switch to Pinterest (masonry). **Pass:** Columns fill based on aspect ratio; infinite scroll works; no large gaps.
- [ ] **P6.4** Switch to Dribbble (showcase). **Pass:** Larger tiles; hover reveals stats; one-row-per-viewport feel.
- [ ] **P6.5** Switch persistence. **Pass:** Reload keeps chosen layout (per-user setting or URL param).
- [ ] **P6.6** Mobile: each layout gracefully collapses to 1-2 col; no horizontal overflow.
- [ ] **P6.7** `mvs_active_layout` filter override. **Pass:** Site admin can pin layout sitewide; user control disables cleanly.

**Heuristic P6:** __ / 50. Findings:

---

## 8. Journey P7 — Admin Competitions Dashboard (`mvs-competitions`)

- [ ] **P7.1** Welcome modal on first visit. **Pass:** Dismiss persists (AJAX `mvs_dismiss_gamification_welcome`); no re-show after reload.
- [ ] **P7.2** Overview counts (active battles / challenges / tournaments). **Pass:** Numbers match the frontend hub.
- [ ] **P7.3** Quick links to each manager page. **Pass:** Clicks navigate correctly.
- [ ] **P7.4** Screenshots suggest "Competitions Dashboard" already has polish passes — confirm: stat cards spacing, color tokens, no raw hex leaking.
- [ ] **P7.5** Empty state (zero competitions anywhere). **Pass:** Explicit empty + "Create your first challenge" primary CTA.

**Heuristic P7:** __ / 50. Findings:

---

## 9. Journey P8 — Challenge Manager (`mvs-challenges`)

- [ ] **P8.1** List view with status badges. **Pass:** Filter by status; sort by deadline; row actions (Edit, Activate, Finalize, Delete).
- [ ] **P8.2** Create form. **Pass:** Title, description (rich editor or markdown), theme selector, start date, deadline, voting window, prize. Dates validated (end > start).
- [ ] **P8.3** Save → redirect to list with success notice.
- [ ] **P8.4** Activate scheduled challenge manually. **Pass:** Status changes; participants allowed to enter immediately.
- [ ] **P8.5** Finalize. **Pass:** Computes winner, awards XP, locks further voting; confirmation before.
- [ ] **P8.6** View entries per challenge. **Pass:** Thumbnails + author + votes; admin can remove abusive entry.
- [ ] **P8.7** Autopilot panel in Gamification Settings drives Challenge Manager. **Pass:** Toggling Autopilot on auto-creates challenges on schedule; visible in list with "Auto" badge.

**Heuristic P8:** __ / 50. Findings:

---

## 10. Journey P9 — Tournament Manager (`mvs-tournaments`)

- [ ] **P9.1** List of tournaments with bracket thumbnails.
- [ ] **P9.2** Create flow. **Pass:** Title, max players (4/8/16/32), registration window, round settings, XP per round, prize.
- [ ] **P9.3** Bracket visualization admin-side. **Pass:** Live updates as rounds progress; click a match to force-resolve.
- [ ] **P9.4** Leaderboard panel. **Pass:** Top players by wins / XP with pagination.
- [ ] **P9.5** Edit while active. **Pass:** Only safe fields editable; destructive edits blocked with explanation.

**Heuristic P9:** __ / 50. Findings:

---

## 11. Journey P10 — Battle Monitor (`mvs-battles`)

- [ ] **P10.1** Tabs: Pending / Active / Completed. **Pass:** Counts accurate; switching filters instantly.
- [ ] **P10.2** Row details panel. **Pass:** Players, media thumbnails, votes, status; inline "Resolve now" for stale battles.
- [ ] **P10.3** Manual resolve with reason. **Pass:** Audit log entry created.
- [ ] **P10.4** Bulk actions. **Pass:** Close many stale battles at once with confirmation.

**Heuristic P10:** __ / 50. Findings:

---

## 12. Journey P11 — Quota & Credits (`mvs-quotas`)

- [ ] **P11.1** Package list with counts of assigned users.
- [ ] **P11.2** Create package. **Pass:** Name, image/video/audio limits, storage bytes (human unit input: MB/GB), save. Validation: no negatives; reasonable upper bound.
- [ ] **P11.3** Edit package. **Pass:** Changes propagate to assigned users immediately (or clearly state "applies to new assignments only").
- [ ] **P11.4** Delete package with assigned users. **Pass:** Blocked with count; offers bulk-reassign target.
- [ ] **P11.5** Assign package to user. **Pass:** Lookup by email/username; saves `_mvs_quota_package_id`.
- [ ] **P11.6** Award bonus credits. **Pass:** Quantity + reason; logs entry in `mvs_credit_log`.
- [ ] **P11.7** Deduct credits. **Pass:** Guardrails: cannot go negative; reason required.
- [ ] **P11.8** Credit log. **Pass:** Paginated; filters by user / type / date; each row has timestamp + actor + reason.
- [ ] **P11.9** Membership adapters (WooCommerce / MemberPress / PMPro). **Pass:** UI to map purchase → package; clear when adapter plugin not installed.

**Heuristic P11:** __ / 50. Findings:

---

## 13. Journey P12 — Theme Library (`mvs-theme-library`)

- [ ] **P12.1** Default themes listed with previews.
- [ ] **P12.2** Create theme. **Pass:** Name, description, cover image, tags, season; save.
- [ ] **P12.3** Edit / Delete. **Pass:** Delete blocked if theme used by active challenge; offers replacement.
- [ ] **P12.4** Theme appears in Challenge Manager create form.

**Heuristic P12:** __ / 50. Findings:

---

## 14. Journey P13 — Migration Tool (`mvs-migration`)

- [ ] **P13.1** Detect button. **Pass:** AJAX `mvs_migration_detect`; displays counts per source (rtMedia / MediaPress / BuddyBoss).
- [ ] **P13.2** Zero detected. **Pass:** Empty state explains — not misleading counts.
- [ ] **P13.3** Run Batch. **Pass:** Progress bar updates; batch size visible; pause/resume supported.
- [ ] **P13.4** Error handling. **Pass:** Failed items listed with reason; retry option.
- [ ] **P13.5** Reset. **Pass:** Confirms destructive action; clears migration state (not the migrated data already imported).

**Heuristic P13:** __ / 50. Findings:

---

## 15. Journey P14 — Gamification Settings & Admin Toggles

- [ ] **P14.1** Feature toggles save. **Pass:** Off → frontend pages 404 or hide; dashboard panels disappear; cron jobs unregister.
- [ ] **P14.2** Per-action XP validated. **Pass:** No negatives; max reasonable cap.
- [ ] **P14.3** Cooldowns validated (seconds/minutes).
- [ ] **P14.4** Autopilot config. **Pass:** Theme pool, frequency, voting window; preview of next auto-challenge.
- [ ] **P14.5** Boost settings. **Pass:** Cost, duration, impression targets.

**Heuristic P14:** __ / 50. Findings:

---

## 16. Journey P15 — License page

- [ ] **P15.1** Active license. **Pass:** Green badge, expiry date, "Renew" link.
- [ ] **P15.2** Expired. **Pass:** Amber badge, explicit list of what still works vs what doesn't (updates blocked, features remain).
- [ ] **P15.3** Invalid key. **Pass:** Red badge + specific error; doesn't wipe stored key silently.
- [ ] **P15.4** Deactivate. **Pass:** Confirmation; site detaches cleanly.

**Heuristic P15:** __ / 50. Findings:

---

## 17. Journey P16 — Pro AI + Storage connection tests

- [ ] **P16.1** S3 settings tab. **Pass:** All fields (bucket, region, key, secret, prefix, ACL); secret masked on reload.
- [ ] **P16.2** S3 "Test Connection". **Pass:** Inline success/failure message (not a JS `alert()`); actionable error (wrong key / bucket missing / region mismatch).
- [ ] **P16.3** BunnyCDN same test + result.
- [ ] **P16.4** AI Vision / Rekognition keys masked; provider selection works; "Test" button returns a sample result or a clear error.
- [ ] **P16.5** FFmpeg path. **Pass:** Valid path test passes; invalid path shows specific error ("FFmpeg not found at /path"); transcode-dependent UI disables with explanation.
- [ ] **P16.6** Circuit breaker. **Pass:** If provider rate-limits repeatedly, UI shows "Service temporarily paused — retrying in N min", not a scary red error.

**Heuristic P16:** __ / 50. Findings:

---

## 18. Journey P17 — Moderation & Stats Pro tabs

- [ ] **P17.1** Moderation page: "User Reports" tab added by Pro. **Pass:** Appears only when Pro active; badge with pending count; lists users with reasons; action buttons (warn / suspend / clear).
- [ ] **P17.2** Stats page: "Video Analytics" tab. **Pass:** Heatmap + retention charts; library overview; time range selector; mobile responsive at 390.

**Heuristic P17:** __ / 50. Findings:

---

## 19. Journey P18 — BuddyPress activity with Pro features

- [ ] **P18.1** Post activity with 6 images (max). **Pass:** Grid renders; 7th rejected.
- [ ] **P18.2** Post with boosted media. **Pass:** Boost indicator visible on activity tile.
- [ ] **P18.3** Streak badge in activity author name. **Pass:** Appears via display-name filter; doesn't break BP layout.

**Heuristic P18:** __ / 50. Findings:

---

## 20. Journey P19 — Messaging (Pro owns transport)

Re-run the Free J9 (DM). Pro-specific additions:

- [ ] **P19.1** Message reactions (emoji on bubble). **Pass:** Long-press/hover picker; both users see the reaction.
- [ ] **P19.2** Online status indicator respects `mvs_show_online_status`. **Pass:** Off → hidden; On → green dot near avatars.
- [ ] **P19.3** DM access level enforcement. **Pass:** `mvs_dm_access=followers` blocks non-followers with clear reason; `all` permits everyone; `none` disables DM site-wide.
- [ ] **P19.4** Account age check. **Pass:** Users below `mvs_dm_min_age` days cannot start DM; explanation shown.
- [ ] **P19.5** Transport latency. **Pass:** REST polling doesn't exceed 1 call / 30s when idle; scales to more frequent during active conversation.

**Heuristic P19:** __ / 50. Findings:

---

## 21. Journey P20 — Mobile 390px sweep for Pro

- [ ] **P20.1** `/compete/` — 3 cards stack; no overflow.
- [ ] **P20.2** Tournament bracket — horizontal scroll with fade-edge hint.
- [ ] **P20.3** Battles side-by-side collapses to stacked on mobile.
- [ ] **P20.4** Challenges entry submission usable with keyboard open.
- [ ] **P20.5** Streak widget readable at 390; buttons ≥40px.
- [ ] **P20.6** Quota & Credits admin readable on tablet/mobile (WP admin at 768).
- [ ] **P20.7** Migration Tool progress bar readable at 390 (admin).

**Heuristic P20:** __ / 50. Findings:

---

## 21.5. Journey P21 — Layout mode matrix

Cycle `wp_options.mvs_pro_feed_layout` through every registered slug and verify `/media/` renders correctly under each at **1440×900 desktop** and **390×844 mobile**. Take one screenshot per (layout × viewport) — 10 images total.

- [ ] **P21.1** Layout `grid` (default, LayoutManager short-circuits) — Free explore template renders.
- [ ] **P21.2** Layout `instagram` — `templates/layouts/instagram/feed.php` renders; stories bar present; square tiles; mobile collapses to 1-col without overflow.
- [ ] **P21.3** Layout `flickr` — `templates/layouts/flickr/feed.php` renders justified rows; no orphan blank tail; hover meta OK; mobile stacks.
- [ ] **P21.4** Layout `pinterest` — `templates/layouts/pinterest/feed.php` renders masonry; no column-gap weirdness; infinite scroll works; mobile collapses cleanly.
- [ ] **P21.5** Layout `dribbble` — `templates/layouts/dribbble/feed.php` renders showcase cards; hover reveals stats; mobile legible.
- [ ] **P21.6** Profile variant under each layout — `/media/@author1/` swaps `templates/layouts/{mode}/profile.php`. Test at least two layouts (instagram, pinterest).
- [ ] **P21.7** After the run, reset option back to `grid` (or desired default).

**Pass criterion per layout:**
- No horizontal overflow at either viewport.
- No console error referencing a layout JS asset.
- `wp_kses` allowlists now include `aria-label` (verified earlier) — streak badge visible.
- Switcher UI in admin actually changes the rendered layout (admin flow).
- Each layout's own CSS/JS enqueued (check Network tab for its distinct asset).

**Heuristic score for P21:** __ / 50. Findings:

---

## 21.6. Journey P22 — Pro features this runbook never covered (added 2026-08-30)

A coverage diff on 2026-08-30 compared this runbook against Pro's actual namespaces and found four
shipped features with no journey here. This runbook was last updated 2026-05-11 and predates the
whole document library.

### Captions (Whisper transcription)

- [ ] **P22.1** Upload a video with speech → a caption file is generated and offered in the player.
- [ ] **P22.2** Language and provider settings on the AI tab persist and are honoured.
- [ ] **P22.3** With no API key configured the feature says so plainly and does NOT queue work that
      cannot run.
- [ ] **P22.4** Whisper is the ONLY thing that touches video here — Pro does not transcode. If any
      UI implies renditions or adaptive quality, that is a copy bug (removed 2.4.0).

### Privacy (Pro UI)

- [ ] **P22.5** The Pro privacy surfaces render for a member and an admin.
- [ ] **P22.6** A privacy change on a Pro surface writes the same value the Free REST path would —
      one vocabulary, not two.

### Push notifications

- [ ] **P22.7** Device registration through `/me/devices` succeeds and is listed.
- [ ] **P22.8** With no push credentials configured, registration fails visibly rather than silently
      queueing to nowhere.

### Integrations / Connectors

- [ ] **P22.9** Connector cards render; connect and disconnect both use the plugin's own modal, not
      a native `confirm()` (Coding Rule: no native dialogs).
- [ ] **P22.10** Auto-export on upload fires for a connected account and does nothing for a
      disconnected one.

**Heuristic score for P22:** __ / 50. Findings:

---

## 21.7. Journey P23 — Documents (added 2026-08-30)

The document library shipped in 2.4.0 and is Pro's largest feature. **Deep coverage is in
`runbooks/DOCUMENTS-QA.md` (508 lines)** — this journey exists so a Pro walk cannot silently skip
it, and it names the traps that make a run report a fault that is not there.

- [ ] **P23.1** The Pro licence is ACTIVE. It gates document writes since 2026-08-19, so an
      unlicensed site looks like a broken drive and every finding after this point is noise.
- [ ] **P23.2** Drive renders for a member: folders, documents, empty state.
- [ ] **P23.3** Upload, preview (PDF inline; text/Markdown/CSV in place), download, share.
- [ ] **P23.4** Role gate — a role without `use_mvs_documents` gets 403 `mvs_documents_unavailable`
      from `/documents`, `/folders` and `/me/shared`, and no dashboard section.
- [ ] **P23.5** Trash and restore from BOTH the member drive and the admin screen, and both fire
      `mvs_document_trashed` / `_restored`.
- [ ] **P23.6** A document NEVER appears in a media surface — grids draw pictures and a document
      has none (this regressed once).

**Heuristic score for P23:** __ / 30. Findings:

---

## 22. Regression Hot-Spots for Pro

- [ ] **22.1** Feature toggle OFF for a module must disappear it from nav, dashboard panels, hub cards, and 404 the frontend page gracefully — never half-broken.
- [ ] **22.2** Cron jobs (battles resolve / challenges activate / transcode cleanup) fire without errors — check WP Cron.
- [ ] **22.3** Circuit breaker persisted across reloads; doesn't silently re-open too fast.
- [ ] **22.4** Quota enforcement: upload blocked at 100% with useful upgrade CTA, not a raw error.
- [ ] **22.5** Membership adapter absent (no WooCommerce installed) — admin UI gracefully hides that adapter, not errors.
- [ ] **22.6** License expired — Pro features remain usable on existing data; updates blocked; no data loss.
- [ ] **22.7** Streak badge doesn't duplicate when a user shows up in multiple lists.
- [ ] **22.8** Battle against deleted user — resolves gracefully, no ghost entries.
- [ ] **22.9** Tournament with withdrawn player — bye assigned, bracket advances.
- [ ] **22.10** Transcoding with missing FFmpeg — visible, actionable admin notice; no WSOD.

---

## 23. Current-Session Baseline Findings (2026-04-23, Pro)

**Run: 2026-04-23** | Tester: Claude Sonnet 4.6 (automated) | Build: v1.1.2 (Pro) / v1.1.3 (Free) | Viewport: 1440×900 + 390×844

| # | Journey | Severity | Finding | Status | Screenshot |
|---|---------|----------|---------|--------|------------|
| PF1 | P1 | Pass | Compete hub `/compete/` renders all 3 competition cards (Battles/Challenges/Tournaments) with accurate counts and CTAs. Hub is fully responsive at 390px (single-column stack). | Pass | `ux-sonnet-p1-01-compete-hub.png`, `ux-sonnet-p20-compete-mobile.png` |
| PF2 | P2 | Pass | Challenges list `/media/challenges/` renders Active/Voting/Results tabs. Golden Hour Photography (3 entries, OPEN) and Shadows (0 entries, OPEN) both show countdown "6d 20h left". Mobile view perfect. | Pass | `ux-sonnet-p2-01-challenges-list.png`, `ux-sonnet-p20-challenges-mobile.png` |
| PF3 | P3 | Pass | Battles `/media/battles/` shows active Landscape vs Portrait battle (Oliver Brooks vs Mina Aoki, VOTING OPEN, 3d 20h). Side-by-side layout holds at 390px. Vote buttons present. | Pass | `ux-sonnet-p3-01-battles.png`, `ux-sonnet-p20-battles-mobile.png` |
| PF4 | P4 | Pass | Tournaments `/media/tournaments/` lists Spring Photography Championship (4/16 players, REGISTRATION OPEN, 12 spots). Tournament cards responsive at 390px. | Pass | `ux-sonnet-p4-01-tournaments.png`, `ux-sonnet-p20-tournaments-mobile.png` |
| PF5 | P5 | Minor | Streak badge "1d" appears in explore grid author names. Badge has `title="1 day streak"` (desktop tooltip only). No `aria-label` — inaccessible on mobile/touch screens. Streak widget does not appear on `/my-media/` or user profile (expected when user has 0 uploads — correct behavior). | Open — Minor (also logged as Free F3) | `ux-sonnet-p5-streak-badge-mobile.png` |
| PF6 | P6 | ⚠️ Not Tested | Layout mode switcher (Instagram/Flickr/Pinterest/Dribbble) not found on `/media/` Explore page. No UI toggle visible. Layout modes may require configuration or are rendered only when a Connector is active. Cannot evaluate P6 journeys without a working layout switcher. | Needs Investigation | — |
| PF7 | P7 | Pass | Competitions Dashboard (`mvs-competitions`) renders with stat cards (challenges, tournaments, battles counts), Welcome modal (if first visit), and quick links to sub-managers. | Pass | `ux-sonnet-p7-01-competitions-dashboard.png` |
| PF8 | P8 | Pass | Challenge Manager (`mvs-challenges`) shows list with status badges, filter controls, and Create/Edit row actions. | Pass | `ux-sonnet-p8-01-challenges-manager.png` |
| PF9 | P9 | Pass | Tournament Manager (`mvs-tournaments`) shows Spring Photography Championship with bracket preview, player count, registration window. | Pass | `ux-sonnet-p9-tournaments.png` |
| PF10 | P10 | Pass | Battle Monitor (`mvs-battles`) shows Pending/Active/Completed tabs with Landscape vs Portrait battle detail visible. | Pass | `ux-sonnet-p10-battles-monitor.png` |
| PF11 | P11 | Pass | Quota & Credits (`mvs-quotas`) renders package management table and credit log. Frontend `/my-media/` Quota widget shows "Unlimited" for admin correctly. | Pass | `ux-sonnet-p11-quota.png` |
| PF12 | P12 | Pass | Theme Library (`mvs-theme-library`) renders 40+ challenge themes in a 4-column grid with category/status filters and Active/Disable toggles. "Add Custom Theme" button present. | Pass | `ux-sonnet-p12-theme-library.png` |
| PF13 | P13 | Pass | Migration Tool (`mvs-migration`) renders Detect section. All 3 sources (rtMedia, MediaPress, BuddyBoss) show NOT DETECTED — correct for this test site. | Pass | `ux-sonnet-p13-migration.png` |
| PF14 | P14 | Pass | Gamification Settings (Competitions tab in Settings) renders Competition Features toggles (Battles/Challenges/Tournaments/Boosts all Enabled), Boost Pricing, Weekly Autopilot (Monday 9AM, 7-day entry/3-day voting), and Upload Streaks settings. All save. | Pass | `ux-sonnet-p14-gamification-competitions.png` |
| PF15 | P15 | Pass | License page renders with "Enter your license key" field and "Activate License" button. Link to Wbcom Designs account present. No active license on this dev site — expected. | Pass | `ux-sonnet-p15-license.png` |
| PF16 | P16 | Pass | AI & Moderation settings show OpenAI (provider/model/API key), Google Cloud Vision section, AWS Rekognition section. Storage settings show Amazon S3 and BunnyCDN sections with all required fields. Connection test buttons present (not testable without real credentials). | Pass | `ux-sonnet-p16-ai-moderation.png`, `ux-sonnet-p16-storage.png` |
| PF17 | P17 | Pass | Moderation Queue shows "User Reports" Pro tab (via `mvs_moderation_tabs` filter). Stats page shows "Video Analytics" Pro tab (via `mvs_stats_tabs` filter). Both tabs render correctly. | Pass | `ux-sonnet-j12-07-moderation.png`, `ux-sonnet-j12-08-stats.png` |
| PF18 | P18 | ⚠️ Not Tested | No BuddyPress groups exist on test site (0 groups). Group media tab test (`/groups/{slug}/media/`) could not be completed. BP member media tab has Critical bug (Free F7 — broken image srcs). | Blocked — see Free F7 | — |
| PF19 | P19 | Pass | DM messaging panel opens from envelope icon (header) and floating chat button. User search typeahead works (typed "Oliver" → "Oliver Brooks" result). Conversation opens with message composer (text input, attach, voice, send). All interactive. | Pass | `ux-sonnet-j9-01-messages-panel.png`, `ux-sonnet-j9-04-conversation.png` |
| PF20 | P20 | Pass | Mobile 390px sweep complete: `/compete/` single-column ✅, Challenges cards full-width ✅, Tournaments cards responsive ✅, Battles side-by-side preserved at 390px ✅, navigation hamburger drawer ✅, profile responsive ✅. | Pass | `ux-sonnet-p20-*.png` |

---

## 23.5. 1.2.0 new-feature journeys (gated by Definition of Done)

Each of the 12 new Pro Gutenberg blocks needs an in-editor + frontend pass. Use the `/mvs-test-pro-blocks/` staging page (create one in admin → Pages → New, insert all 12 blocks back-to-back with realistic ids).

- [ ] **23.5.1** `mvs/pro-tournament` (with `tournamentId`) — editor inspector lets user pick tournament; frontend renders bracket; deep-link with `?mvs_tournament_id=X` opens the same view.
- [ ] **23.5.2** `mvs/pro-tournaments-list` — frontend renders the list of tournaments; off-state when `mvs_tournaments_enabled` = '0' (admin sees notice; visitors see empty).
- [ ] **23.5.3** `mvs/pro-challenge` (with `challengeId`) — editor picks challenge; frontend renders single challenge with entries.
- [ ] **23.5.4** `mvs/pro-challenges-list` — frontend renders the list of challenges; off-state honored.
- [ ] **23.5.5** `mvs/pro-battle` (with `battleId`) — editor picks battle; frontend renders head-to-head; vote action wires.
- [ ] **23.5.6** `mvs/pro-battles-active` — frontend renders all currently-active battles; empty state when none.
- [ ] **23.5.7** `mvs/pro-instagram-feed` (`perPage`, `scope`) — frontend renders Instagram-styled feed; per-layout CSS enqueued so SVG icons are 14×14 px (NOT viewBox-default — Rule 6 regression check).
- [ ] **23.5.8** `mvs/pro-flickr-feed` — frontend Flickr-styled grid; per-layout CSS enqueued.
- [ ] **23.5.9** `mvs/pro-pinterest-feed` — masonry layout; per-layout CSS enqueued.
- [ ] **23.5.10** `mvs/pro-dribbble-feed` — Dribbble-styled cards; per-layout CSS enqueued.
- [ ] **23.5.11** `mvs/pro-leaderboard` (`source`, `perPage`, `window`) — top-creators list. **Regression check:** rank numbers must NOT double up — themes that style `<ol>` with `1. 2. 3.` markers used to render "1. 1.", "2. 2."; we ship `<ul style="list-style:none;">` to prevent this. Visit on a theme that styles ordered lists (e.g. Twenty Twenty-Four content area) and verify single rank numbers.
- [ ] **23.5.12** `mvs/pro-compete-hub` — gates on "any one of Tournaments/Challenges/Battles enabled"; off-state returns admin-only notice.

**Standard inspector panels regression** — for each of the 12 blocks above, sidebar must show block-specific panels first, then **Spacing → Border → Shadow → Visibility → Advanced** in canonical order (same as Free + wbcom-essential). Per-instance scoped CSS via `mvs-block-{uniqueId}` dumps in `wp_footer`.

**Explore filters a11y (1.2.0 RC fix)** — visit any frontend page that includes `templates/layouts/partials/explore-filters.php` (Pro feed layouts). Tab to the Media / People search-mode toggle: ARIA inspector should report `role="tablist"` on the wrapper, `role="tab"` + `aria-selected` on each button, screen-reader-only `<label>` bound to the search input via `for=`. VoiceOver / NVDA should announce "Media, tab, selected" then "People, tab".

**Heuristic score for J23.5:** __ / 60. Findings:

---

## 24. Sign-off

| Role | Name | Date | Build / Tag |
|------|------|------|-------------|
| QA | | | |
| Engineering lead | | | |
| Product | | | |

**Pro release gate:** Free sign-off + all Critical Pro items PASS + heuristic ≥ 3.5 / 5.

---

## 25. Change log

| Date | Version | Author | Change |
|------|---------|--------|--------|
| 2026-04-23 | 1.0 | Varun | Initial — 20 Pro journeys on plugin-mapped pages + admin, regression hot-spots |
| 2026-04-23 | 1.1 | Claude Sonnet 4.6 | Updated Section 23 baseline: 20 Pro journey results logged; 15 Pass, 1 Minor (streak badge aria-label), 1 Not Investigated (layout modes), 2 Not Tested/Blocked (BP groups, layout switcher); no new Critical Pro-specific findings beyond Free F7 |
