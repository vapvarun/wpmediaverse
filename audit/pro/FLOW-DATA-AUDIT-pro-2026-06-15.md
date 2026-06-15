# WPMediaVerse Pro — Flow + Data Audit
**Date:** 2026-06-15  
**Pro version audited:** 1.7.0 (commit `af3cdb7`)  
**Free version:** 1.7.0-dev  
**Auditor:** AutoVAP  
**Rule under test:** CLAUDE.md Rule 18 — three entry points (frontend / admin / REST) per data store

---

## 1. Pro Data-Store Matrix

### 1.1 Custom DB Tables (Pro — `mvs_*`)

| Store | Frontend UX | Admin UI | REST API | VERDICT | Notes |
|---|---|---|---|---|---|
| `mvs_quota_packages` | `UsageWidget` renders package name + limits in member dashboard | `QuotaPage` (slug `mvs-quota`) — full CRUD, assign to user | `GET /me/quota`, `GET /users/{id}/quota`, `POST /users/{id}/quota/assign` | 3/3 | OK |
| `mvs_credit_log` | No direct frontend render of credit-log history to member | `QuotaPage` renders credit log per-user (admin view) | `GET /me/quota` includes balance but no pagination of the log itself | GAP (1/3) | Member cannot page through their own credit history via REST. REST returns balance-only summary. No `/me/credits` paginated route. Rule 18 violation for a member-facing feature. |
| `mvs_play_events` | Video player emits events via JS REST call on play/pause/seek/end | `AnalyticsDashboard` + video heatmap admin tab injected into Free's Stats page | `POST /videos/{id}/event` (write) + `GET /videos/{id}/analytics` (read heatmap) | 3/3 | OK |
| `mvs_competitions` | `challenges.php`, `battles.php`, `tournaments.php` templates + Interactivity API stores | `ChallengeManager`, `BattleMonitor`, `TournamentManager`, `CompetitionsDashboard` admin pages | `GET/POST /challenges`, `/battles`, `/tournaments` + detail + results | 3/3 | OK |
| `mvs_competition_entries` | Entry submission + vote counts visible in competition templates | Admin pages show entry counts and ranked results | `POST /challenges/{id}/entries`, `GET /challenges/{id}/entries`, `GET /challenges/{id}/results`, tournament bracket | 3/3 | OK |
| `mvs_competition_matches` | Tournament bracket visualizer in `tournaments.php` | `TournamentManager` — manual per-match resolve (added 1.6.0) | `GET /tournaments/{id}/bracket`, `POST /tournaments/{id}/matches/{match_id}/vote` | 3/3 | OK. `submit-media` for tournament match exists in `TournamentController` but is not in manifest.rest.json (verify at release). |
| `mvs_competition_votes` | Vote buttons + counts in competition templates | No dedicated admin vote viewer | `POST /battles/{id}/vote`, `POST /challenges/{id}/entries/{entry_id}/vote`, `POST /tournaments/{id}/matches/{match_id}/vote` | 2/3 (admin read gap) | Admin has no ability to inspect or audit who voted and for what (no vote log view). Low severity for MVP but worth noting. |
| `mvs_boosts` | `boost-modal.php` — member purchases boost; `mvs_feed_media_ids` filter promotes boosted media in Explore | No admin view of active boosts across users | `GET /me/boosts`, `POST /boosts` | 2/3 (admin gap) | Admin cannot see or manage boosts across users. `BoostService::promote_boosted_in_feed` reads `mvs_boosts` without an admin UI. Rule 18 soft violation. |
| `mvs_pro_collection_items` | 1.6.0: collection-picker JS in media grid lets member toggle multi-collection membership | No admin UI — no admin page lists or edits collection memberships | `GET /media/{id}/collections`, `POST /media/{id}/collections` (toggle) | 2/3 (admin gap) | Admin cannot see which media are in which collections for any user. Rule 18 violation — this is a member-facing table with zero admin surface. |

### 1.2 Key usermeta / option stores

| Store (key) | Frontend | Admin | REST | VERDICT |
|---|---|---|---|---|
| `_mvs_quota_package_id` | Widget shows package name | `QuotaPage` assign UI | `POST /users/{id}/quota/assign` | 3/3 |
| `_mvs_image_count`, `_mvs_video_count`, `_mvs_audio_count` | `UsageWidget` | `QuotaPage` per-user usage | `GET /me/quota` (summary) | 3/3 |
| `_mvs_extra_*_credits` | Widget shows extra credits | `QuotaPage` add-credits form | `GET /me/quota` (credits array) | 3/3 |
| `_mvs_storage_used` | Widget shows storage bar | `QuotaPage` | `GET /me/quota` | 3/3 |
| `_mvs_current_streak` | `streak-widget.php` shows streak badge | `GamificationSettings` (read-only stat) | `GET /compete-summary` includes streak | 3/3 |
| `_mvs_longest_streak` | `streak-widget.php` | `GamificationSettings` | `GET /compete-summary` | 3/3 |
| `_mvs_last_upload_date` | Implicit (streak logic) | `GamificationSettings` | Via `GET /compete-summary` streak object | 3/3 |
| `_mvs_streak_freezes` | `streak-widget.php` freeze count | `GamificationSettings` | `GET /compete-summary` + `POST /streaks/buy-freeze` | 3/3 |
| `mvs_pro_s3_*` / `mvs_pro_bunny_*` / `mvs_pro_r2_*` / `mvs_pro_do_*` options | N/A (admin-only) | `ProSettings` storage sections | `POST /mvs-pro/v1/admin/test-connection` (AJAX-only; REST gap) | Admin+AJAX only — intentional for credentials, acceptable |
| `mvs_battles_enabled`, `mvs_challenges_enabled`, `mvs_tournaments_enabled`, `mvs_boosts_enabled`, `mvs_streaks_enabled`, `mvs_connectors_enabled`, `mvs_pro_transcode_enabled` | Feature gating | `GamificationSettings` | N/A — toggles are admin-only | Intentional — OK |
| `mvs_pro_boost_cost_per_100`, `mvs_pro_boost_max_impressions`, `mvs_pro_boost_expiry_days` | N/A | `GamificationSettings` | N/A | OK |
| `mvs_pro_streak_freeze_cost` | N/A | `GamificationSettings` | N/A | OK |

---

## 2. Pro Flow Inventory

31 flows documented. Trigger → service → DB writes → read-back surface.

### 2.1 Quota and Credits

**Flow 1 — Quota enforcement on upload**  
Trigger: user initiates upload → Free's `UploadService` runs `apply_filters('mvs_upload_args')` → `QuotaService::enforce_quota()` → reads `_mvs_quota_package_id` + `_mvs_*_count` + `_mvs_*_credits` + `_mvs_storage_used` usermeta → returns `WP_Error` on breach, passes args through on OK.  
Read-back: `GET /mvs-pro/v1/me/quota` returns updated counts.

**Flow 2 — Usage increment on upload**  
Trigger: `mvs_media_uploaded` action → `QuotaService::increment_usage()` → increments `_mvs_*_count` and `_mvs_storage_used` via usermeta → deducts extra credit if over package limit.  
Read-back: `GET /me/quota`.

**Flow 3 — Credit purchase via webhook**  
Trigger: HMAC-signed webhook `POST` to Free's webhook endpoint → `WebhookService` fires `mvs_pro_credits_added` → `QuotaService::add_credits()` → inserts to `mvs_credit_log` + increments `_mvs_extra_*_credits`.  
Read-back: `GET /me/quota` (balance only — no log pagination endpoint). **GAP: no `GET /me/credits` paginated history route.**

**Flow 4 — Subscription lapse**  
Trigger: MemberPress / WooCommerce / PMPro subscription-cancelled hook → `QuotaAdapter::on_subscription_ended()` → `QuotaService::end_subscription_package()` → calls `unassign_package()` (default) or `assign_package($default_id)` (legacy opt-in filter).  
Read-back: `GET /users/{id}/quota` (admin), `GET /me/quota` (member).

### 2.2 Cloud Storage

**Flow 5 — Upload to S3 / BunnyCDN / R2 / DO Spaces**  
Trigger: Upload completes → Free's `StorageService::resolve_driver()` → `apply_filters('mvs_storage_driver')` → Pro returns `AmazonS3\StorageDriver` / `BunnyCDN\StorageDriver` / `CloudflareR2\StorageDriver` / `DigitalOceanSpaces\StorageDriver` → driver `store($source, $rel_path)` → signed PUT to cloud.  
Read-back: `MediaUrl::for_file()` resolves CDN URL for public, `/serve` for private.

**Flow 6 — Migrate existing media between storage drivers**  
Trigger: Admin `CloudOpsManager` → AJAX `mvs_pro_cloud_migrate_chunk` → batched `StorageService::migrate_chunk()` → per-file move between drivers → progress bar.  
Read-back: `Admin\CloudOpsManager` overview tab.

### 2.3 AI — Moderation

**Flow 7 — AI content moderation (Google Vision / Rekognition)**  
Trigger: `mvs_media_uploaded` → Free's `AIService::moderate()` → `apply_filters('mvs_ai_providers')` → Pro registers `GoogleVision\AIProvider` or `Rekognition\AIProvider` → CircuitBreaker-guarded HTTP call to vendor API → result scores → ModerationService queues if flagged.  
Read-back: Free's moderation queue admin + Pro's `ReportManager` "User Reports" tab.

### 2.4 Watermarking

**Flow 8 — GD watermark on upload**  
Trigger: `mvs_media_uploaded` → `apply_filters('mvs_generate_watermark')` → `Watermarker::generate($file_path)` → GD library applies overlay → writes preview to `uploads/wpmediaverse/previews/`.  
Read-back: Watermarked URL served via `/serve`.

### 2.5 EDD Licensing

**Flow 9 — License activation**  
Trigger: Admin enters key in `License` UI → EDD SL API call → response stored as `mvs_pro_license_*` options.  
Read-back: `ProSettings` license status badge (active/inactive). License gates ONLY the update channel and the status badge — **never feature or REST registration**. Intentional design per CLAUDE.md.

### 2.6 Report Moderation

**Flow 10 — User report submission and review**  
Trigger: Free's `POST /mvs/v1/media/{id}/report` → Free's `ReportService` → inserts to Free's `mvs_reports` → Pro's `mvs_moderation_tabs` filter injects "User Reports" tab into Free's Moderation Queue page → admin reviews.  
Read-back: `Admin\ReportManager` page (`mvs-reports` slug) shows pending reports with approve/reject actions.

### 2.7 Video Features

**Flow 11 — Video chapters**  
Trigger: Video owner writes chapters via `POST /mvs-pro/v1/videos/{id}/chapters` (or admin) → `ChapterService` → stored as postmeta on `mvs_media` CPT.  
Read-back: `GET /mvs-pro/v1/videos/{id}/chapters` (public); chapter markers in player template.

**Flow 12 — Resume playback**  
Trigger: Player sends `POST /mvs-pro/v1/videos/{id}/resume` with `position` → `ResumeService` → stored as usermeta `_mvs_resume_{media_id}`.  
Read-back: `GET /mvs-pro/v1/videos/{id}/resume` returns saved position on next load.

**Flow 13 — Video analytics / heatmap**  
Trigger: JS player events → `POST /mvs-pro/v1/videos/{id}/event` → `AnalyticsService::record()` → INSERT to `mvs_play_events`.  
Aggregation: `GET /mvs-pro/v1/videos/{id}/analytics` → `AnalyticsService::aggregate_heatmap()` returns per-second bucket array.  
Read-back: `AnalyticsDashboard` admin page renders chart. Daily cron `mvs_pro_prune_play_events` prunes rows older than 90 days.

**Flow 14 — Auto-captions (Whisper)**  
Trigger: `POST /mvs-pro/v1/captions/{media_id}` → `TranscriptionService::transcribe()` → reads file from filesystem → multipart `wp_remote_post` to OpenAI Whisper API → VTT file written to `uploads/wpmediaverse/captions/` → `MediaRepository::set(caption_url)`.  
Read-back: `GET /mvs-pro/v1/captions/{media_id}` returns caption VTT URL. Player template loads VTT track.

**Flow 15 — Video transcoding (FFmpeg)**  
Trigger: `POST /mvs-pro/v1/transcode` (admin-only) → `TranscodeController::start_transcode()` → `TranscodeService::queue_job()` → Action Scheduler job → FFmpeg subprocess via `proc_open`.  
Read-back: `GET /mvs-pro/v1/transcode/{job_id}` polls status. Hourly cron `mvs_pro_transcode_cleanup` expires jobs >7 days.

### 2.8 Email Gate / Leads

**Status: RETIRED.** The `mvs_email_leads` and `mvs_transactions` tables referenced in project memory no longer exist in the `Core/Migrator.php` (version 7). No `EmailGate`, `LeadsPage`, or `EarningsDashboard` class was found in `includes/`. These features were scoped in early planning but were NOT shipped. No Pro DB tables, no REST routes, no admin pages. Confirm with project owner that these are intentionally deferred.

### 2.9 Instagram Feed / Profiles / Follow UI

**Flow 16 — Instagram-layout feed**  
Trigger: `[mvs_pro_instagram_feed]` shortcode or `mvs/pro-instagram-feed` block → `InstagramLayout::render_feed()` → queries Free's `MediaRepository` (paginated) → renders 3-col grid.  
Read-back: `templates/layouts/instagram/feed-body.php`. Free's follow/unfollow via `POST /mvs/v1/users/{id}/follow`.

**Flow 17 — User profile (@username page)**  
Trigger: Frontend `/media/@{username}/` URL → Free's `ProfileService` + `ProfileController` → `templates/layouts/instagram/profile.php` (Pro override via `mvs_locate_template`).  
Follow button calls Free REST. Pro renders the layout shell.

### 2.10 DM / Chat

**Flow 18 — Direct messaging (1:1)**  
Note: DM was moved to Free plugin in session 2026-03-20. Pro's `templates/partials/chat-*.php` are Pro-flavored overrides of Free's messaging engine; `Groups/GroupController` adds a Pro REST layer for group conversations.

**Flow 19 — Group DM (1.6.0 new)**  
Trigger: `POST /mvs-pro/v1/groups` → `GroupController::create_group()` → Free's `MessagingService::create_group_conversation($creator, $members, $title)` → inserts to Free's `mvs_conversations` + `mvs_conversation_participants`.  
Read-back: `GET /mvs-pro/v1/groups`, `GET /mvs-pro/v1/groups/{id}`, `GET /mvs-pro/v1/groups/{id}/messages`.  
REST fully wired. Admin: no dedicated group-DM admin page (acceptable — Free's messaging service is the data owner). Frontend: chat partials render group threads.

### 2.11 Gamification / Competition System

**Flow 20 — Battle: create → accept → submit → vote → resolve → notify**  
Trigger: challenger clicks "Challenge" → `POST /battles` → `BattleService::create()` → INSERT `mvs_competitions` (type=battle) + 2x `mvs_competition_entries` → notification to opponent.  
Opponent: `POST /battles/{id}/accept` → status ACCEPTED.  
Submit: `POST /battles/{id}/submit` (each player) → `BattleService::submit_entry()` → UPDATE `mvs_competition_matches` player_*_media_id.  
Vote: `POST /battles/{id}/vote` → INSERT `mvs_competition_votes` → UPDATE vote_count.  
Resolve (cron): `CompetitionsScheduler` tick → `mvs_resolve_expired_matches` → `BattleService::resolve_expired()` → winner computed → `mvs_tournament_match_resolved` (reused) → `BattleNotificationListener::notify_players()` notifies winner + loser.  
XP: `wb_gam_points_for_action` filter → `CompetePointsBridge::resolve_points()` → NOT called for battles (no battle-specific case in bridge). **GAP: battles award flat default XP, not a per-battle configured amount.** Battles have no `xp_win` setting in create args.

**Flow 21 — Challenge: create → entries → voting → finalize → award XP → notify**  
Trigger (admin): `POST /challenges` → `ChallengeService::create()` → INSERT `mvs_competitions` (type=challenge) with settings JSON including `xp_1st`, `xp_2nd`, `xp_3rd`, `xp_participation`.  
Member: `POST /challenges/{id}/entries` → INSERT `mvs_competition_entries`.  
Vote: `POST /challenges/{id}/entries/{entry_id}/vote` → INSERT `mvs_competition_votes` + UPDATE vote_count.  
Cron finalize: `CompetitionsScheduler::tick` → `mvs_finalize_expired_challenges` → `ChallengeService::finalize()` → ranks entries → fires `mvs_challenge_winner_named` (per rank 1/2/3) + `mvs_challenge_finalized`.  
XP: `CompetePointsBridge::resolve_points('mvs_challenge_winner', ...)` → `ChallengeService::xp_for_rank($challenge_id, $rank)` → reads `settings.xp_{rank}st` JSON field. **Configured XP is honored.** Participation XP via `mvs_challenge_participate` event + `xp_for_participation()`.  
Notify: `ChallengeNotificationListener::notify_winner()` + `notify_participant()` → Free's `NotificationService::create()`.  
Read-back: `GET /challenges/{id}/results`, `GET /challenges/{id}/entries` (sorted by vote_count).

**Flow 22 — Tournament: register → bracket → match-submit → vote → advance rounds → finalize → award XP → notify**  
Trigger (admin): `POST /tournaments` → INSERT `mvs_competitions` (type=tournament, settings includes `xp_round_win`, `xp_tournament_win`, `bracket_size`).  
Register: `POST /tournaments/{id}/register` → INSERT `mvs_competition_entries`.  
Bracket: `CompetitionsScheduler` → `mvs_start_registered_tournaments` → `TournamentService::generate_bracket()` → shuffles → creates `mvs_competition_matches` round 1. **1.7.0 fix:** both-null slot skip prevents fatal.  
Match submit: `POST /tournaments/{id}/matches/{match_id}/submit-media` → UPDATE `mvs_competition_matches.player_*_media_id`.  
Vote: `POST /tournaments/{id}/matches/{match_id}/vote` → INSERT `mvs_competition_votes`.  
Resolve (cron + manual): `mvs_resolve_expired_matches` → `TournamentService::resolve_expired_matches()` → determines winner → advances bracket → eliminates loser. Admin can also manually resolve via `TournamentManager` "Resolve" button (added 1.6.0).  
XP: `CompetePointsBridge::resolve_points('mvs_tournament_round_win', ...)` → `TournamentService::xp_for_round_win($tournament_id)` → reads `settings.xp_round_win`. Champion: `mvs_tournament_win` event → `xp_for_tournament_win()`.  
Notify: `TournamentNotificationListener::notify_loser()` on `mvs_tournament_match_resolved` + `notify_champion()` on `mvs_tournament_finalized`. Types registered via `mvs_notification_types` filter.  
Read-back: `GET /tournaments/{id}/bracket` (public), Admin `TournamentManager`.

**Flow 23 — Streak: upload → extend / freeze-use → milestone XP**  
Trigger: `mvs_media_uploaded` → `StreakService::on_upload()` → reads `_mvs_last_upload_date`, `_mvs_current_streak`, `_mvs_streak_freezes`.  
Gap handling (1.7.0 fix): computes `missed_days = max(1, gap_days - 1)` → requires `freezes >= missed_days` to bridge.  
Daily cron: `StreakService::daily_check()` → per-user usermeta query → burns 1 freeze per cron-run (only catches 1 missed day per tick).  
Milestone: `check_milestones()` → `do_action('mvs_streak_milestone', $user_id, $days, $xp)` → `CompetePointsBridge` handles via `mvs_streak_milestone` case (reads `meta.xp_bonus`).  
Read-back: `GET /compete-summary` (streak object), `streak-widget.php`.  
Freeze purchase: `POST /streaks/buy-freeze` → `StreakController::buy_freeze()` → deducts `mvs_pro_streak_freeze_cost` points via `WBGam\Engine\PointsEngine::debit()` — **deduction confirmed before granting freeze**.

**Flow 24 — Boosts: purchase → promote in Explore feed**  
Trigger: `POST /boosts` → `BoostService::create()` → checks active boost on same media → checks points balance → debits points → INSERT `mvs_boosts`.  
Promotion: `mvs_feed_media_ids` filter → `BoostService::promote_boosted_in_feed()` → queries `mvs_boosts WHERE status='active'` → bumps boosted media to top of Explore query. Max impressions capped by `mvs_pro_boost_max_impressions`.  
Read-back: `GET /me/boosts` (member), No admin list of all boosts.

**Flow 25 — Leaderboard**  
Trigger: `[mvs_pro_leaderboard]` shortcode or block → `LeaderboardRenderer::render()` → queries Free tables (`mvs_reactions`, `mvs_media_index`) or WB Gamification points store depending on `source` attribute (`reactions` | `media_count` | `gamification_xp`).  
Read-back: Frontend block only. `GET /compete-summary` returns member's own rank. No paginated `GET /leaderboard` REST endpoint. **GAP: no REST endpoint for full leaderboard data → mobile app cannot render leaderboard natively.**

**Flow 26 — Connectors (Flickr)**  
Trigger: `POST /connectors/flickr/connect` → `ConnectorRESTController::start_connect()` → OAuth 1.0a flow via `OAuthHelper` → stores tokens as usermeta.  
Import: `POST /connectors/flickr/import` → `Flickr\Connector::import()` → sideloads via Free's `upload` service.  
Sync: `POST /connectors/flickr/sync-delta` → incremental import since last `_mvs_flickr_last_sync` timestamp.  
Read-back: `GET /connectors/flickr/status` + `GET /connectors/flickr/browse` + dashboard connector panel template.

**Flow 27 — Multi-collection membership (1.6.0)**  
Trigger: Member clicks collection-picker → `POST /media/{id}/collections` (body: `collection_id`, `member: true|false`) → `CollectionItemsController::toggle_membership()` → `CollectionItemsService` → UPSERT / DELETE from `mvs_pro_collection_items`.  
Read-back: `GET /media/{id}/collections` returns which collections the media belongs to (Favorites from Free + manual Pro collections). Frontend: collection-picker JS built into 1.6.0 dist.  
Admin: **no admin surface for collection memberships**. Rule 18 violation.

**Flow 28 — Advanced privacy settings**  
Trigger: `POST /privacy/settings` → `PrivacyController` → saves advanced privacy flags as usermeta (granular audience controls beyond Free's public/private/friends).  
Read-back: `GET /privacy/settings`; `PrivacyUIService` decorates `mvs_media_response` filter with `privacy_options` field.

**Flow 29 — Platform migrations (admin WP-CLI + browser)**  
Trigger: Admin opens Migration Tools (`mvs-migration`) → detects installed source plugins → runs batched AJAX `mvs_migration_batch` → `RtMedia\MigrationAdmin::run_batch()` etc. → sideloads media into Free's pipeline.  
WP-CLI: `wp mvs migrate rtmedia|mediapress|buddyboss`.  
Read-back: Progress bar in admin page.

**Flow 30 — CompeteSummary (member dashboard)**  
Trigger: `GET /compete-summary` → `CompeteSummaryController::get_summary()` → aggregates: streak data (usermeta), active battles/challenges/tournaments (mvs_competitions JOIN entries), points balance (WB Gamification API), active boosts count.  
Read-back: `compete-hub.php` template + `dashboard-*-panel.php` partials.

**Flow 31 — Storage connection test**  
Trigger: Admin clicks "Test Connection" in `ProSettings` → AJAX `mvs_pro_test_s3` / `mvs_pro_test_bunny` / R2 / DO → `ConnectionTester::test_*()` → signed GET to the driver's root → returns success/failure JSON.  
Read-back: Toast in admin UI.

---

## 3. 1.7.0 + 1.6.0 Coverage Requirements

### 3.1 New assertions required for 1.7.0 (commit `af3cdb7`)

**TC-1.7.0-A — Tournament sparse-bracket: both-null skip (Basecamp 9966421635)**

Scenario: Create tournament with `bracket_size=16`, register 3 participants (sparse: 13 byes). Call `generate_bracket()`.

Expected assertions:
1. No PHP fatal / no unhandled exception on `generate_bracket()`.
2. Exactly 1 real match created in `mvs_competition_matches` (the one match with 2 actual players).
3. Remaining match positions are either a single-player bye match or skipped entirely (both-null positions produce no row).
4. Tournament status transitions to `active`.
5. `GET /tournaments/{id}/bracket` returns HTTP 200 with the bracket shape (round 1 has 1 real match + up to 7 single-bye matches — none with `null` winner_entry_id unless they are `status='bye'`).

Regression: Call `generate_bracket()` with 2 participants in a `bracket_size=64` tournament (maximum sparseness). Assert: no fatal, 1 real match row, remaining 31 positions are either single-bye matches or absent (no both-null rows created).

**TC-1.7.0-B — Streak freeze proportional cost (Basecamp 9966423677)**

Scenario: User has `_mvs_streak_freezes = 1`, uploads yesterday, then skips 5 days and uploads today (6-day gap).

Expected assertions:
1. `missed_days = max(1, 6 - 1) = 5`.
2. Since `freezes (1) < missed_days (5)`, streak resets to 1 — NOT preserved. `_mvs_current_streak = 1`.
3. `_mvs_streak_freezes` unchanged (still 1 — insufficient, so no deduction).

Scenario B: User has `_mvs_streak_freezes = 3`, uploads yesterday, skips 3 days, uploads today (4-day gap).
1. `missed_days = max(1, 4 - 1) = 3`.
2. `freezes (3) >= missed_days (3)` → streak preserved. `_mvs_current_streak` incremented.
3. `_mvs_streak_freezes = 0` (3 consumed).

Contrast with pre-fix behavior (regression test): before `af3cdb7`, 1 freeze bridged any gap → a 5-day gap cost the same as a 1-day gap. Confirm the old code path is gone.

**TC-1.7.0-C — Cron sweeps bounded (Basecamp 9966423880)**

Scenario: Seed 200 `mvs_competitions` rows in each of the 5 cron-targeted statuses (`scheduled`, `active` challenges; `voting` challenges; `active` tournaments; `registration` tournaments).

Expected assertions:
1. A single cron tick (`CompetitionsScheduler::tick()`) processes at most 50 rows per status category.
2. After one tick, 150 rows in each status category remain unprocessed (will be caught by the next tick).
3. No timeout / memory exhaustion on the tick action handler.
4. Subsequent ticks process the next 50 until all rows are drained.

Additional assertion: confirm each of the 5 SQL queries in `ChallengeService::activate_scheduled()`, `close_entries()`, `finalize_expired()`, `TournamentService::resolve_expired_matches()`, `start_registration_closed()` contains `ORDER BY id ASC LIMIT 50` (code-level assertion).

### 3.2 Coverage requirements for 1.6.0 features still needing test coverage

**TC-1.6.0-A — Configured XP honored (not flat defaults)**

For challenges:
1. Create challenge with `xp_1st=500, xp_2nd=300, xp_3rd=150, xp_participation=25`.
2. Finalize the challenge with 3+ entries.
3. Assert: `wb_gam_points_for_action` filter called with `action_id='mvs_challenge_winner'`; `CompetePointsBridge::resolve_points()` returns 500 for rank 1, 300 for rank 2, 150 for rank 3.
4. Assert: NOT the WB Gamification flat default (200 or whatever the engine's default is).
5. Assert: participation event returns 25 XP.

For tournaments:
1. Create tournament with `xp_round_win=150, xp_tournament_win=500`.
2. Resolve one match to trigger `mvs_tournament_round_win` event.
3. Assert: bridge returns 150, not engine default.
4. Finalize tournament; assert champion receives 500 XP.

**TC-1.6.0-B — Points deduct on streak freeze purchase**

1. User has 150 WB Gamification points. `mvs_pro_streak_freeze_cost = 100`.
2. Call `POST /streaks/buy-freeze`.
3. Assert: HTTP 200, `freezes` incremented, `balance = 50`.
4. Assert: `WBGam\Engine\PointsEngine::debit()` was called (mock or spy).
5. Scenario: User has 50 points, cost is 100. Call `POST /streaks/buy-freeze`.
6. Assert: HTTP 400 with `mvs_insufficient_points` error code. No freeze granted. Points unchanged.
7. Scenario: `WBGam\Engine\PointsEngine::debit()` returns `false` (simulated failure).
8. Assert: HTTP 400 with `mvs_deduction_failed`. No freeze granted.

**TC-1.6.0-C — Winners notified, eliminations not dropped**

Challenges:
1. Finalize a challenge with at least 3 entries.
2. Assert: `mvs_challenge_winner_named` fires for rank 1, 2, and 3 (all three, not just rank 1).
3. Assert: `ChallengeNotificationListener::notify_winner()` creates notification for each top-3 user via Free's `NotificationService::create()`.
4. Assert: `mvs_challenge_finalized` fires once with `$results` array containing correct `winner_1st`, `winner_2nd`, `winner_3rd`.
5. Finalize a challenge with only 1 entry: assert `winner_2nd` and `winner_3rd` are 0, and `mvs_challenge_winner_named` fires exactly once (rank 1 only).

Tournaments:
1. Resolve a tournament match. Assert: losing player receives `tournament_eliminated` notification.
2. Assert: `TournamentNotificationListener::register_notification_types()` adds `tournament_eliminated` and `tournament_won` to the Free notification whitelist — otherwise `NotificationService::create()` silently drops the notification.
3. Finalize a tournament. Assert: champion receives `tournament_won` notification.

Battles:
1. Resolve a battle (vote window expires). Assert: winner and loser both receive notifications via `BattleNotificationListener::notify_players()`.

**TC-1.6.0-D — Manual per-match tournament resolve from admin (audit B4)**

1. Create active tournament with 2 participants in a match with status `voting`.
2. Navigate to `TournamentManager` admin page.
3. Click "Resolve" on the match.
4. Assert: match `winner_entry_id` set correctly in `mvs_competition_matches`.
5. Assert: eliminated player's `eliminated_in_round` updated in `mvs_competition_entries`.
6. Assert: next-round match created with the winner as a participant.
7. Assert: notification sent to eliminated player.

**TC-1.6.0-E — Group DMs (1.6.0 feature)**

1. `POST /mvs-pro/v1/groups` with `{members: [id1, id2, id3], title: "Test Group"}` as creator.
2. Assert: HTTP 200, group conversation created in Free's `mvs_conversations` + 4 rows in `mvs_conversation_participants` (creator + 3 members).
3. `GET /mvs-pro/v1/groups/{id}/messages` — assert HTTP 200, empty messages array.
4. `POST /mvs-pro/v1/groups/{id}/messages` with message content — assert message persisted.
5. `GET /mvs-pro/v1/groups/{id}/messages` — assert the message appears.
6. Non-member attempts `GET /mvs-pro/v1/groups/{id}/messages` — assert HTTP 403.

**TC-1.6.0-F — Save to multiple collections**

1. Create 3 collections via Free's collection API.
2. `POST /mvs-pro/v1/media/{id}/collections` with `{collection_id: 1, member: true}` — assert HTTP 200, row in `mvs_pro_collection_items`.
3. `POST /mvs-pro/v1/media/{id}/collections` with `{collection_id: 2, member: true}` — assert second row.
4. `GET /mvs-pro/v1/media/{id}/collections` — assert both collections returned.
5. `POST /mvs-pro/v1/media/{id}/collections` with `{collection_id: 1, member: false}` — assert membership removed (row deleted).
6. `GET /mvs-pro/v1/media/{id}/collections` — assert only collection 2 remains.
7. UNIQUE KEY `user_media_collection` — attempt duplicate insert, assert no duplicate row.

---

## 4. Top Correctness Risks

### Risk 1 — Battle XP awards flat default (UNRESOLVED)
`CompetePointsBridge::resolve_points()` handles `mvs_challenge_winner`, `mvs_tournament_round_win`, `mvs_tournament_win`, and `mvs_streak_milestone` — but has NO case for battle win. Battles resolve with `BattleService::resolve_winner()` which fires `mvs_pro_battle_resolved` + `mvs_pro_battle_voted`. Neither event carries a metadata-backed XP amount. Result: battle winners always receive WB Gamification's flat default points for whatever action ID is used, not a per-battle configured reward. There is no `xp_win` field in `BattleService::create()` args. **This contradicts the "configured XP honored" design goal stated for 1.6.0.**

### Risk 2 — `mvs_credit_log` has no member-facing paginated REST read
Members can see their extra-credit balance via `GET /me/quota` but cannot see the transaction history. `QuotaService::get_credit_log()` exists and is paginated, but no REST route exposes it. The mobile app (planned) will have no way to show the member a history of credit purchases, deductions, and webhook grants. Rule 18 violation — only admin + internal writes; no member REST read.

### Risk 3 — `mvs_pro_collection_items` has no admin surface (Rule 18 violation)
Migrator v7 added the table; `CollectionItemsController` and `CollectionItemsService` wire REST and frontend. But no admin page lists or manages collection memberships across users. A site owner cannot audit what collections exist, how many members each has, or delete stale memberships without direct DB access.

### Risk 4 — Leaderboard has no paginated REST endpoint (mobile app blocker)
`LeaderboardRenderer` is a PHP string renderer (block/shortcode only). `GET /compete-summary` gives a single member their own rank, but there is no `GET /mvs-pro/v1/leaderboard` route returning ranked user lists with pagination. The planned native mobile app will be unable to render a leaderboard natively. This is a Rule 18 REST entry point gap on a member-facing feature.

### Risk 5 — `daily_check()` consumes only 1 freeze per missed day, but scans ALL users with unbounded `get_col()` query
`StreakService::daily_check()` queries `wp_usermeta WHERE meta_key='_mvs_last_upload_date' AND meta_value=%s` with no LIMIT. On a large site this is an unbounded scan. At 10,000 users with active streaks, this SELECT returns 10,000 rows into PHP per cron tick and runs one `get_user_meta` + one `update_user_meta` per user inside the `foreach`. This is an N+1 pattern on usermeta and is not bounded by the 1.7.0 LIMIT 50 fix (that fix targeted `mvs_competitions` cron queries, not the streak daily scan). **High severity for big-site readiness.**

---

## 5. Data-Store Three-Entry-Point Summary

| Table | 3/3 | GAP | Violation type |
|---|---|---|---|
| `mvs_quota_packages` | 3/3 | - | - |
| `mvs_credit_log` | - | 1/3 | No member REST read (no paginated `/me/credits` route) |
| `mvs_play_events` | 3/3 | - | - |
| `mvs_competitions` | 3/3 | - | - |
| `mvs_competition_entries` | 3/3 | - | - |
| `mvs_competition_matches` | 3/3 | - | submit-media route gap needs manifest verification |
| `mvs_competition_votes` | 2/3 | Admin read | No admin vote audit log |
| `mvs_boosts` | 2/3 | Admin read | No admin view of all user boosts |
| `mvs_pro_collection_items` | 2/3 | Admin gap | No admin surface for collection membership management |

**Stores with full 3/3:** 5 of 9 (56%)  
**Stores with gaps:** 4 of 9 (44%)  
**Rule 18 violations (missing REST):** 1 (`mvs_credit_log`)  
**Rule 18 violations (missing admin):** 3 (`mvs_competition_votes`, `mvs_boosts`, `mvs_pro_collection_items`)

---

## 6. Flow Count Summary

Total Pro flows documented: **31**

| Domain | Flow count |
|---|---|
| Quota + credits | 4 |
| Cloud storage | 2 |
| AI moderation | 1 |
| Watermarking | 1 |
| EDD licensing | 1 |
| Report moderation | 1 |
| Video (chapters/resume/analytics/captions/transcode) | 5 |
| Email gate/leads/earnings | 0 (retired / unshipped) |
| Layout feeds (Instagram/Flickr/Pinterest/Dribbble) | 2 |
| DM (1:1 via Free + group DM via Pro) | 2 |
| Gamification: battles | 1 |
| Gamification: challenges | 1 |
| Gamification: tournaments | 1 |
| Gamification: streaks + freeze | 1 |
| Gamification: boosts | 1 |
| Gamification: leaderboard | 1 |
| Connectors (Flickr OAuth + import) | 1 |
| Multi-collection membership | 1 |
| Advanced privacy | 1 |
| Platform migrations | 1 |
| Compete summary | 1 |
| Storage connection test | 1 |

---

## 7. Manifest Gap Note (for refresh)

The `manifest.rest.json` in `audit/pro/manifests/` predates 1.6.0 and 1.7.0. Routes added since 1.2.0 that are missing from the manifest:

- `POST /mvs-pro/v1/groups` — group DM create
- `GET /mvs-pro/v1/groups` — list group conversations
- `GET /mvs-pro/v1/groups/{id}` — group detail
- `GET /mvs-pro/v1/groups/{id}/messages` — group messages
- `POST /mvs-pro/v1/groups/{id}/messages` — send group message
- `POST /mvs-pro/v1/streaks/buy-freeze` — purchase streak freeze
- `POST /mvs-pro/v1/tournaments/{id}/matches/{match_id}/submit-media` — tournament match submission
- `GET/POST /mvs-pro/v1/media/{id}/collections` — multi-collection membership (present in manifest but route spec incomplete)

Recommend running `/wp-plugin-onboard --refresh` from the Free plugin after the 1.7.0 release to bring manifest.rest.json current.

