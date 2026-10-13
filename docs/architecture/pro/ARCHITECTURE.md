# WPMediaVerse Pro -- Technical Architecture

> Full architecture reference for the Pro add-on plugin.
> Source of truth: `includes/Core/Plugin.php`, `includes/Core/Migrator.php`, and all
> feature-level Service / Controller classes.

---

## 1. Plugin Lifecycle

`wpmediaverse-pro.php` guards the boot (PHP gate, `includes/Core/Plugin.php`
present, bundled SDK complete) and then waits for Free: `\WPMediaVersePro\Core\Plugin::init()`
runs inside the `mvs_loaded` callback, and `mvs_pro_loaded` fires after it.

`Plugin::init()` instantiates the subsystems below. Grep a class name in
`includes/Core/Plugin.php` for its exact position. *Gated* items are skipped
(their service stays `null`) when their option is off.

```
 Documents       StorageResolver, ExtractionService, DocumentSettings, then
                 init_document_surfaces() (drives, folder tree, ingest,
                 previews, search index, DeliveryController, PermissionService,
                 DocumentLicense write gate) and init_folder_jobs()
 LicenseManager  in-page licence form; activates through the bundled EDD SL SDK
 LayoutManager   Instagram, Dribbble, Flickr, Pinterest feed modes
 Blocks          BlockRegistrar (Pro blocks + shortcodes)
 Connectors      ConnectorManager + ConnectorRESTController
                 (gated: mvs_connectors_enabled)
 Privacy         PrivacyUIService + PrivacyController: advanced per-item privacy
 Video           ChapterService, ResumeService, VideoController
 Groups          GroupController: group DM routes (/mvs-pro/v1/groups) over Free's messaging engine
 Collections     CollectionItemsService + CollectionItemsController
 Captions        TranscriptionService (Whisper) + CaptionController
                 AS hook: mvs_pro_transcribe_media
 Free seams      mvs_storage_driver (S3, BunnyCDN, R2, DO Spaces),
                 mvs_watermark_stamp_file, mvs_ai_providers,
                 mvs_app_config_features, mvs_rest_gate_map,
                 mvs_rest_gate_resolve_targets
 ConnectionTester  admin AJAX for testing storage/API connections
 Analytics       AnalyticsService + AnalyticsController
                 WP Cron: mvs_pro_prune_play_events (daily)
 Competitions    master switch mvs_competitions_enabled, then per type via
                 Plugin::competition_feature_on():
                   battles      BattleService, BattleController, listener
                   challenges   ChallengeService, AutopilotService,
                                ChallengeController, listener
                   tournaments  TournamentService, TournamentController,
                                listener
                   boosts       BoostService, BoostController
                 CompetitionsScheduler: one Action Scheduler tick drives every
                 time-based transition (see 4.1)
 Compete summary CompeteSummaryController, LeaderboardController
 Streaks         StreakService (always inits); StreakController and the badge on
                 mvs_user_display_name only when mvs_streaks_enabled
 Push            PushService (Expo delivery) + PushController
 Stories         StoryService, StoryController, StoriesPage
                 (gated: mvs_stories_enabled)
 Gamification    CompetePointsBridge
 Frontend        GamificationTemplateLoader (/media/battles/, /media/challenges/,
                 /media/tournaments/), dashboard tabs and sections
                 (mvs_dashboard_tabs, mvs_dashboard_panels,
                 mvs_dashboard_sections)
 Admin           ProSettings, CloudOpsManager, ReportManager, AnalyticsDashboard,
                 MigrationPage, GamificationSettings, CompetitionsDashboard,
                 ChallengeManager, TournamentManager, BattleMonitor,
                 ThemeLibrary; mvs_moderation_tabs adds the User Reports tab,
                 mvs_stats_tabs adds Video Analytics
 First-run seed  default theme pool + first weekly challenge (once)
 Nav menu        Compete link injected on wp_nav_menu_items only behind the
                 default-false mvs_pro_inject_compete_nav filter
```

---

## 2. Database Schema

All tables created by `includes/Core/Migrator.php`. The list is
`Migrator::tables()`.
DB version option: `mvs_pro_db_version`; the target is `Migrator::CURRENT_VERSION`.

Pro has no quota, package or credit system. `Migrator::RETIRED_TABLES` names the
tables earlier versions created for one (and for webhook events); nothing
creates or reads them, and uninstall drops them.

### 2.3 `{prefix}mvs_play_events`

| Column | Type | Notes |
|---|---|---|
| id | bigint(20) unsigned | PK, AUTO_INCREMENT |
| media_id | bigint(20) unsigned | |
| user_id | bigint(20) unsigned | nullable (anonymous) |
| session_id | varchar(64) | |
| event_type | varchar(20) | play, pause, seek, ended, etc. |
| position_seconds | float | |
| duration_seconds | float | nullable |
| created_at | datetime | DEFAULT CURRENT_TIMESTAMP |

**Indexes:** `PRIMARY (id)`, `KEY idx_media_created (media_id, created_at)`,
`KEY idx_session (session_id)`, `KEY idx_media_event (media_id, event_type)`

### 2.4 Messaging Tables -- FREE-OWNED, not created here

Pro does **not** create `mvs_conversations`, `mvs_conversation_participants`,
`mvs_messages` or `mvs_message_reactions`. Free's `Migrator` owns them and Free's
`docs/architecture/ARCHITECTURE.md` §2.6 describes them. Pro adds only the Group
DM REST surface (`Groups\GroupController`, `/mvs-pro/v1/groups`) over Free's
`MessagingService`.

### 2.5 Competition Tables (Unified -- v4)

All competition types (battles, challenges, tournaments) share a single
set of tables. Legacy per-entity tables are dropped during migration.

#### `{prefix}mvs_competitions`

| Column | Type | Notes |
|---|---|---|
| id | bigint(20) unsigned | PK, AUTO_INCREMENT |
| type | varchar(20) | battle, challenge, tournament |
| title | varchar(200) | |
| description | text | |
| theme | varchar(200) | |
| cover_image_url | varchar(500) | |
| status | varchar(20) | DEFAULT 'pending' |
| settings | text | JSON blob (type-specific) |
| winner_id | bigint(20) unsigned | nullable |
| created_by | bigint(20) unsigned | |
| created_at | datetime | DEFAULT CURRENT_TIMESTAMP |
| resolved_at | datetime | nullable |

**Indexes:** `PRIMARY (id)`, `KEY type_status (type, status)`,
`KEY created_by (created_by)`

**settings JSON examples:**

- Battle: `{}`
- Challenge: `{"start_date", "end_date", "voting_end_date", "max_entries_per_user", "xp_1st", "xp_2nd", "xp_3rd", "xp_participation"}`
- Tournament: `{"bracket_size", "registration_start", "registration_end", "round_duration_hours", "xp_round_win", "xp_tournament_win"}`

#### `{prefix}mvs_competition_entries`

| Column | Type | Notes |
|---|---|---|
| id | bigint(20) unsigned | PK, AUTO_INCREMENT |
| competition_id | bigint(20) unsigned | |
| user_id | bigint(20) unsigned | |
| role | varchar(20) | challenger, opponent, entrant, participant |
| media_id | bigint(20) unsigned | nullable |
| vote_count | int unsigned | DEFAULT 0 |
| rank | int unsigned | nullable (challenges) |
| seed | int unsigned | nullable (tournaments) |
| eliminated_in_round | int unsigned | nullable (tournaments) |
| created_at | datetime | DEFAULT CURRENT_TIMESTAMP |

**Indexes:** `PRIMARY (id)`, `UNIQUE comp_user_role (competition_id, user_id, role)`,
`KEY comp_votes (competition_id, vote_count)`, `KEY user_id (user_id)`

#### `{prefix}mvs_competition_matches`

| Column | Type | Notes |
|---|---|---|
| id | bigint(20) unsigned | PK, AUTO_INCREMENT |
| competition_id | bigint(20) unsigned | |
| round_number | int unsigned | DEFAULT 1 |
| match_position | int unsigned | DEFAULT 1 |
| player_a_entry_id | bigint(20) unsigned | nullable (FK: competition_entries) |
| player_b_entry_id | bigint(20) unsigned | nullable (FK: competition_entries) |
| player_a_media_id | bigint(20) unsigned | nullable |
| player_b_media_id | bigint(20) unsigned | nullable |
| player_a_votes | int unsigned | DEFAULT 0 |
| player_b_votes | int unsigned | DEFAULT 0 |
| winner_entry_id | bigint(20) unsigned | nullable |
| status | varchar(20) | DEFAULT 'pending' |
| submit_deadline | datetime | nullable |
| vote_deadline | datetime | nullable |
| created_at | datetime | DEFAULT CURRENT_TIMESTAMP |

**Indexes:** `PRIMARY (id)`, `KEY comp_round (competition_id, round_number)`,
`KEY status (status)`, `KEY vote_deadline (vote_deadline)`

#### `{prefix}mvs_competition_votes`

| Column | Type | Notes |
|---|---|---|
| id | bigint(20) unsigned | PK, AUTO_INCREMENT |
| competition_id | bigint(20) unsigned | |
| votable_type | varchar(10) | entry or match |
| votable_id | bigint(20) unsigned | entry ID or match ID |
| user_id | bigint(20) unsigned | |
| voted_for | bigint(20) unsigned | DEFAULT 0 |
| created_at | datetime | DEFAULT CURRENT_TIMESTAMP |

**Indexes:** `PRIMARY (id)`, `UNIQUE votable_user (votable_type, votable_id, user_id)`,
`KEY comp_id (competition_id)`

#### `{prefix}mvs_boosts`

| Column | Type | Notes |
|---|---|---|
| id | bigint(20) unsigned | PK, AUTO_INCREMENT |
| user_id | bigint(20) unsigned | |
| media_id | bigint(20) unsigned | |
| points_spent | int unsigned | DEFAULT 0 |
| boost_type | varchar(20) | DEFAULT 'standard' |
| impressions_target | int unsigned | DEFAULT 0 |
| impressions_delivered | int unsigned | DEFAULT 0 |
| status | varchar(20) | DEFAULT 'active' |
| started_at | datetime | DEFAULT CURRENT_TIMESTAMP |
| expires_at | datetime | nullable |

**Indexes:** `PRIMARY (id)`, `KEY user_id (user_id)`,
`KEY media_status (media_id, status)`, `KEY status_expires (status, expires_at)`

### 2.6 Pro tables not written up above

Beyond the ones detailed above, `Migrator::tables()` also lists
`{prefix}mvs_pro_collection_items`, `{prefix}mvs_pro_push_devices` (emptied by a
migration and never written; push tokens live in Free's `mvs_device_tokens`),
`{prefix}mvs_pro_folders` (document folder tree) and
`{prefix}mvs_pro_document_search` (full-text index).
Read their column definitions off `includes/Core/Migrator.php`, which carries a
written rationale per table. Only the `mvs_pro_` ones carry the prefix, so name
is not a reliable ownership signal.

---

## 3. REST API Map

All routes are under the `mvs-pro/v1` namespace. This map is partial: Documents,
Folders, Permissions, Collections, Groups, Stories, Streaks, Push, Connectors,
Leaderboard and the admin controller register routes that are not written up
here. The complete code-derived list is
`wpmediaverse/audit/pro/manifests/manifest.rest.json`. Pro has no quota,
package or credit routes, and no messaging routes (those are Free's `mvs/v1`).

Competition routes (3.6-3.9) register only while `mvs_competitions_enabled` and
the type's own option are on.

### 3.2 Privacy

| Method | Route | Permission | Description |
|---|---|---|---|
| PUT | `/media/{id}/privacy` | owner or moderate_mvs_media | Update single item privacy |
| POST | `/media/bulk-privacy` | logged_in (ownership checked per-item) | Bulk-update privacy |
| GET | `/privacy/presets` | logged_in | List user's saved presets |
| POST | `/privacy/presets` | logged_in | Create a new preset |

### 3.3 Video (Chapters + Resume)

| Method | Route | Permission | Description |
|---|---|---|---|
| GET | `/media/{id}/chapters` | public | List chapters |
| PUT | `/media/{id}/chapters` | owner or edit_mvs_media | Set chapters |
| GET | `/media/{id}/resume` | logged_in | Get resume position |
| POST | `/media/{id}/resume` | logged_in | Save resume position |
| DELETE | `/media/{id}/resume` | logged_in | Clear resume position |

### 3.4 Captions / Transcription

| Method | Route | Permission | Description |
|---|---|---|---|
| GET | `/media/{id}/captions` | public (if media accessible) | Get caption metadata |
| PUT | `/media/{id}/captions` | owner or manage_mvs_settings | Upload manual VTT |
| DELETE | `/media/{id}/captions` | owner or manage_mvs_settings | Remove captions |
| POST | `/media/{id}/captions/generate` | owner or manage_mvs_settings | Queue transcription (202) |
| GET | `/media/{id}/captions/status` | public | Poll transcription status |

### 3.5 Analytics

| Method | Route | Permission | Description |
|---|---|---|---|
| POST | `/media/{id}/events` | public (rate-limited) | Record a play event |
| GET | `/media/{id}/analytics` | owner or manage_mvs_settings | Full analytics + heatmap |
| GET | `/analytics/top` | manage_mvs_settings | Top media by engagement |
| GET | `/analytics/overview` | manage_mvs_settings | Site-wide summary |

### 3.6 Battles

| Method | Route | Permission | Description |
|---|---|---|---|
| GET | `/battles` | public | List battles |
| POST | `/battles` | logged_in | Create a battle challenge |
| GET | `/battles/{id}` | public | Single battle detail |
| POST | `/battles/{id}/accept` | logged_in | Accept battle |
| POST | `/battles/{id}/decline` | logged_in | Decline battle |
| POST | `/battles/{id}/submit` | logged_in | Submit media entry |
| POST | `/battles/{id}/vote` | logged_in | Cast vote |

### 3.7 Challenges

| Method | Route | Permission | Description |
|---|---|---|---|
| GET | `/challenges` | public | List challenges |
| POST | `/challenges` | manage_options | Create challenge |
| GET | `/challenges/{id}` | public | Single challenge detail |
| PUT | `/challenges/{id}` | manage_options | Update challenge |
| POST | `/challenges/{id}/cancel` | manage_options | Cancel challenge |
| GET | `/challenges/{id}/entries` | public | List entries |
| POST | `/challenges/{id}/entries` | logged_in | Submit entry |
| POST | `/challenges/{id}/entries/{eid}/vote` | logged_in | Vote for entry |
| DELETE | `/challenges/{id}/entries/{eid}/vote` | logged_in | Remove vote |
| GET | `/challenges/{id}/results` | public | Finalized results |

### 3.8 Tournaments

| Method | Route | Permission | Description |
|---|---|---|---|
| GET | `/tournaments` | public | List tournaments |
| POST | `/tournaments` | manage_options | Create tournament |
| GET | `/tournaments/{id}` | public | Single tournament detail |
| POST | `/tournaments/{id}/register` | logged_in | Register for tournament |
| DELETE | `/tournaments/{id}/register` | logged_in | Unregister |
| GET | `/tournaments/{id}/bracket` | public | Get bracket |
| GET | `/tournaments/{id}/participants` | public | Get participants |
| POST | `/tournaments/{id}/matches/{mid}/submit` | logged_in | Submit media for match |
| POST | `/tournaments/{id}/matches/{mid}/vote` | logged_in | Vote in match |

### 3.9 Boosts

| Method | Route | Permission | Description |
|---|---|---|---|
| GET | `/boosts` | logged_in | List current user's boosts |
| POST | `/boosts` | logged_in | Create a boost |

### 3.10 Group DM

| Method | Route | Permission | Description |
|---|---|---|---|
| POST | `/groups` | logged_in | Create a group conversation |
| PUT | `/groups/{id}` | group admin | Rename |
| POST | `/groups/{id}/participants` | group admin | Add a member |
| DELETE | `/groups/{id}/participants/{user_id}` | group admin | Remove a member |
| PUT | `/groups/{id}/participants/{user_id}/role` | group admin | Set role (`admin` or `member`) |
| POST | `/groups/{id}/leave` | participant | Leave |

Direct messaging itself is Free's `mvs/v1` surface (Free `ARCHITECTURE.md` §3.19).

### 3.11 Competition Summary

| Method | Route | Permission | Description |
|---|---|---|---|
| GET | `/competitions/active-summary` | public | Aggregated competition discovery data |

---

## 4. Hook Reference

Organized by category. **Line numbers below are indicative and drift with every
edit -- grep the hook name, do not trust the number.** Sections 4.1-4.3 and
4.5-4.7 are hooks Pro *fires*; 4.4 and 4.9-4.11 are mostly Free hooks Pro
*consumes*, which is called out per section.

### 4.1 Cron / Scheduled Hooks

| Hook | Type | Schedule | File : Line |
|---|---|---|---|
| `mvs_pro_transcribe_media` | AS single | on-demand | TranscriptionService.php:50 |
| `mvs_pro_prune_play_events` | WP Cron | daily | AnalyticsService.php:47 |
| `mvs_competitions_tick` | AS recurring | `CompetitionsScheduler::TICK_INTERVAL_SECONDS` | Competitions/CompetitionsScheduler.php; fires the six hooks below in order |
| `mvs_resolve_expired_battles` | fired by the tick | per tick | Competitions/CompetitionsScheduler.php |
| `mvs_activate_scheduled_challenges` | fired by the tick | per tick | Competitions/CompetitionsScheduler.php |
| `mvs_close_challenge_entries` | fired by the tick | per tick | Competitions/CompetitionsScheduler.php |
| `mvs_finalize_expired_challenges` | fired by the tick | per tick | Competitions/CompetitionsScheduler.php |
| `mvs_start_registered_tournaments` | fired by the tick | per tick | Competitions/CompetitionsScheduler.php |
| `mvs_resolve_expired_matches` | fired by the tick | per tick | Competitions/CompetitionsScheduler.php |
| `mvs_expire_boosts` | WP Cron | hourly | Core/Plugin.php (only while boosts are on) |
| `BoostService::FLUSH_IMPRESSIONS_HOOK` | WP Cron | hourly | Core/Plugin.php (only while boosts are on) |
| `mvs_autopilot_create_weekly_challenge` | AS recurring | weekly | AutopilotService.php:72 |

### 4.2 Analytics Hooks

| Hook | Type | File : Line |
|---|---|---|
| `mvs_pro_analytics_event_data` | filter | AnalyticsService.php:119 |
| `mvs_pro_analytics_recorded` | action | AnalyticsService.php:144 |
| `mvs_pro_analytics_summary` | filter | AnalyticsService.php:648 |

### 4.3 Membership Hooks

Pro has none. It has no membership adapters and no quota system.

### 4.4 Messaging Hooks -- FREE-FIRED, listed for reference

Every hook in this section is fired by Free's `Messaging/MessagingService.php` /
`MessagingController.php`, not by Pro. Pro ships no messaging code today; the
paths below are relative to the **Free** plugin's `includes/`.

| Hook | Type | File : Line |
|---|---|---|
| `mvs_dm_access_level` | filter | MessagingService.php:82 |
| `mvs_dm_message_rate_limit` | filter | MessagingService.php:146 |
| `mvs_dm_convo_rate_limit` | filter | MessagingService.php:165 |
| `mvs_dm_max_upload_size` | filter | MessagingController.php:727 |
| `mvs_message_max_length` | filter | MessagingService.php:688 |
| `mvs_show_online_status` | filter | MessagingService.php:1247, 1263 |
| `mvs_conversation_created` | action | MessagingService.php:338 |
| `mvs_message_request_accepted` | action | MessagingService.php:603 |
| `mvs_message_sent` | action | MessagingService.php:819 |
| `mvs_voice_message_sent` | action | MessagingService.php:826 |
| `mvs_message_deleted` | action | MessagingService.php:983, 1045 |
| `mvs_conversation_read` | action | MessagingService.php:1079 |
| `mvs_message_reaction_added` | action | MessagingService.php:1162 |

### 4.5 Quota Hooks

Pro has none (see 4.3).

### 4.6 Competition Hooks

| Hook | Type | File : Line |
|---|---|---|
| `mvs_battle_created` | action | BattleService.php:169 |
| `mvs_battle_accepted` | action | BattleService.php:232 |
| `mvs_battle_resolved` | action | BattleService.php:494 |
| `mvs_challenge_created` | action | ChallengeService.php:107 |
| `mvs_challenge_entry_submitted` | action | ChallengeService.php:279 |
| `mvs_challenge_finalized` | action | ChallengeService.php:524 |
| `mvs_tournament_created` | action | TournamentService.php:106 |
| `mvs_tournament_started` | action | TournamentService.php:295 |
| `mvs_tournament_match_resolved` | action | TournamentService.php:466, 534 |
| `mvs_tournament_finalized` | action | TournamentService.php:608 |
| `mvs_streak_milestone` | action | StreakService.php:182 |
| `mvs_autopilot_no_theme_available` | action | AutopilotService.php:121 |
| `mvs_autopilot_create_failed` | action | AutopilotService.php:152 |
| `mvs_autopilot_challenge_created` | action | AutopilotService.php:158 |
| `mvs_autopilot_pool_reset` | action | AutopilotService.php:417 |

### 4.7 Video / Captions Hooks

| Hook | Type | File : Line |
|---|---|---|
| `mvs_pro_captions_generated` | action | TranscriptionService.php:210 |

### 4.8 Privacy Hooks

| Hook | Type | File : Line |
|---|---|---|
| `mvs_privacy_can_view` | filter | PrivacyUIService.php:157 |

### 4.9 Frontend / Layout Hooks

Pro fires the `mvs_layout_*` set; the rest are Free hooks Pro attaches to.

| Hook | Type | Fired by | Pro call site |
|---|---|---|---|
| `mvs_layout_config` | filter | Pro | LayoutManager.php |
| `mvs_active_layout` | filter | Pro | LayoutManager.php |
| `mvs_layout_modes` | filter | Pro | LayoutManager.php |
| `mvs_layout_template_map` | filter | Pro | LayoutManager.php |
| `mvs_before_layout_render` | action | Pro | LayoutManager.php |
| `mvs_layout_assets` | action | Pro | LayoutManager.php |
| `mvs_user_display_name` | filter | Free | Plugin.php:784 (streak badge) |
| `mvs_activity_types` | filter | Free | Plugin.php:803 (8 gamification types) |
| `mvs_reserved_media_paths` | filter | Free | Plugin.php:970 |
| `mvs_dashboard_tabs` | action | Free | Plugin.php:298, 1046 |
| `mvs_dashboard_panels` | action | Free | Plugin.php:299, 1047 |

`mvs_before_explore_grid` is fired by Free but Pro does not listen to it today;
it was listed here in error.

The dashboard registry hook is `mvs_dashboard_sections` (Free's
`Core/DashboardSections.php`), which Pro filters at `Plugin.php:1624`. The name
`mvs_dashboard_tab_registry` appears in older notes and does not exist.

### 4.10 Admin UI Hooks (Free-fired, Pro-consumed)

| Hook | Type | Pro call site |
|---|---|---|
| `mvs_moderation_tabs` | filter | Plugin.php:901 |
| `mvs_stats_tabs` | filter | Plugin.php:915 |

### 4.11 Core Extension Points (Free-fired, Pro attaches in Plugin.php)

| Hook | Type | Pro call site |
|---|---|---|
| `mvs_storage_driver` | filter | Plugin.php:467 |
| `mvs_watermark_stamp_file` | filter | Plugin.php:471 -> `Core\Watermarker::stamp_file()` |
| `mvs_ai_providers` | action | Plugin.php:474 |

`mvs_watermark_enabled` is a Free filter Pro does not attach to; the settings UI
reads and writes the `mvs_watermark_enabled` **option** (`Admin/ProSettings.php`).
`mvs_generate_watermark` does not exist in either plugin -- there is no
watermarked-preview-URL hook; Pro stamps bytes at ingest instead.

---

## 5. Competition System Architecture

### 5.1 Unified Table Design

All three competition types -- battles, challenges, and tournaments -- share
`mvs_competitions` as the header table. The `type` column discriminates
(`battle`, `challenge`, `tournament`) and the `settings` column holds a JSON
blob with type-specific configuration:

- **Battle settings:** empty or minimal (theme only).
- **Challenge settings:** `start_date`, `end_date`, `voting_end_date`,
  `max_entries_per_user`, `xp_1st`, `xp_2nd`, `xp_3rd`, `xp_participation`.
- **Tournament settings:** `bracket_size`, `registration_start`,
  `registration_end`, `round_duration_hours`, `xp_round_win`,
  `xp_tournament_win`.

### 5.2 Entry Roles

`mvs_competition_entries.role` semantics per type:

| Type | Roles Used | media_id | seed / eliminated_in_round |
|---|---|---|---|
| Battle | challenger, opponent | submitted photo | unused |
| Challenge | entrant | submitted photo | unused / rank set on finalize |
| Tournament | participant | nullable (submitted per-match) | seed assigned on start |

### 5.3 Match Table Usage

- **Battles:** one match per battle (round_number=1, match_position=1).
  `player_a_entry_id` / `player_b_entry_id` point to the challenger and opponent entries.
- **Tournaments:** N matches per tournament, organized by `round_number` and
  `match_position`. Winners of round N populate `player_a_entry_id` /
  `player_b_entry_id` for round N+1.
- **Challenges:** do not use the matches table; voting is on entries directly
  (`votable_type = 'entry'`).

### 5.4 Vote Table

`mvs_competition_votes` uses a polymorphic pattern:

- `votable_type = 'entry'` + `votable_id = entry.id` for challenge votes.
- `votable_type = 'match'` + `votable_id = match.id` for battle/tournament
  match votes. `voted_for` stores the entry ID the user voted for.
- `UNIQUE (votable_type, votable_id, user_id)` prevents double-voting.

### 5.5 Status Lifecycle

```
Battle:    pending -> active -> voting -> completed
           pending -> declined

Challenge: pending -> active -> voting -> finalized
           pending -> cancelled
           (scheduled challenges: pending -> active via
            mvs_activate_scheduled_challenges)

Tournament: registration -> active -> finalized
            (registration -> active via mvs_start_registered_tournaments
             when bracket_size is filled or registration_end passes)
```

### 5.6 Resolution via ActionScheduler

One recurring Action Scheduler action, `mvs_competitions_tick`, fires every
transition hook below (a throwing hook is logged and does not stop the rest):

- **Battles:** `mvs_resolve_expired_battles` calls `BattleService::resolve_expired()`.
  Finds battles past vote_deadline and declares the entry with more votes the
  winner, or draws if tied.
- **Challenges:** Three hooks:
  1. `mvs_activate_scheduled_challenges` -- moves pending challenges past start_date to active.
  2. `mvs_close_challenge_entries` -- locks submissions after end_date (voting-only phase).
  3. `mvs_finalize_expired_challenges` -- ranks entries by vote_count after voting_end_date,
     awards XP (1st/2nd/3rd/participation).
- **Tournaments:** Two hooks:
  1. `mvs_start_registered_tournaments` -- starts tournaments that have filled their bracket
     or passed registration_end; generates round-1 bracket.
  2. `mvs_resolve_expired_matches` -- resolves matches past vote_deadline, advances winners
     to next round, creates next round's matches, or finalizes the tournament when the
     final match completes.

---

## 6. Video Pipeline

### 6.1 Captions / Transcription

```
CaptionController: POST /media/{id}/captions/generate
  |
  v
TranscriptionService::queue_transcription($media_id)
  - schedules AS single action: mvs_pro_transcribe_media (+5s)
  - fallback: wp_schedule_single_event if AS unavailable
  |
  v
TranscriptionService::transcribe($media_id)  [AS worker]
  - resolves the local file path (resolve_file_path); NO audio extraction and no
    FFmpeg -- the source file is posted as-is. Coding Rule 21 bans exec-family
    calls in shipped source, so nothing here shells out.
  - sends to configured AI provider (Integrations\Whisper\CaptionProvider)
  - generates WebVTT file
  - stores in {uploads}/mvs-captions/{media_id}.vtt
  - writes media meta: captions = {vtt_url, language, provider, word_count, duration, generated_at}
  - fires: do_action('mvs_pro_captions_generated', $media_id, $saved)
```

---

## 7. Licensing

The licence gates updates, never features. The one exception is
`Documents\DocumentLicense`, which refuses document writes on an unlicensed site
(Pro `CLAUDE.md` §7 lists what stays exempt).

- **Activation.** `Core\LicenseManager::handle_license_form()` is nonce- and
  `manage_options`-guarded and talks to the store through the bundled SDK
  (`EasyDigitalDownloads\Updater\Requests\API` and
  `EasyDigitalDownloads\Updater\Licensing\License`), so the SDK is the one writer
  of the stored key and status. If either class is missing it shows a settings
  error instead of calling the store.
- **Status.** `License\License` only reads the SDK's options
  (`wpmediaverse-pro_license_key`, `wpmediaverse-pro_license`). `is_valid()`
  feeds the settings badge and `DocumentLicense`.
- **Updates.** `wpmediaverse-pro.php` loads `libs/edd-sl-sdk` only when
  `src/Versions.php` is present and registers Pro on `edd_sl_sdk_registry`. An
  incomplete SDK shows an admin warning that automatic updates are off; every
  other feature keeps working.
- **SDK path utility.** `libs/edd-sl-sdk/src/Utilities/Path.php` builds the asset
  URL with `plugins_url()`, the same code as BuddyNext's bundled copy (Free's copy
  is identical to it). A re-bundle must keep that file.

Pro has no quota, package, credit or membership-adapter system.
