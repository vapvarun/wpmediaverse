# WPMediaVerse Pro -- Technical Architecture

> Full architecture reference for the Pro add-on plugin.
> Source of truth: `includes/Core/Plugin.php`, `includes/Core/Migrator.php`, and all
> feature-level Service / Controller classes.

---

## 1. Plugin Lifecycle

`Plugin::init()` (lines 78-429 of `includes/Core/Plugin.php`) runs on the main
plugin bootstrap. Every subsystem is instantiated in the order below. Items
marked *conditional* are gated by a per-feature `get_option()` toggle.

```
 1. load_plugin_textdomain   - bundled .mo files in languages/
 2. Migrator::run()          - runs DB migrations (idempotent, version-checked)
 3. LicenseManager           - EDD Software Licensing integration
 4. QuotaService             - enforces upload quotas, tracks usage
 5. QuotaController          - REST: /mvs-pro/v1/me/quota, /packages, /credits
 6. UsageWidget              - frontend usage meter
 7. Membership Adapters      - MemberPressAdapter, PaidMembershipsProAdapter,
                               WooCommerceAdapter (auto-assign packages on
                               membership change)
 8. LayoutManager            - Instagram, Dribbble, Flickr, Pinterest modes
 9. PrivacyUIService         - advanced per-item privacy (public/members/friends/
                               group/private/custom)
10. PrivacyController        - REST: /media/{id}/privacy, /media/bulk-privacy,
                               /privacy/presets
11. ChapterService           - video chapter markers
12. ResumeService            - per-user resume-playback positions
13. VideoController          - REST: /media/{id}/chapters, /media/{id}/resume
14. TranscriptionService     - AI-powered caption generation (Whisper)
    + AS hook:               mvs_pro_transcribe_media
15. CaptionController        - REST: /media/{id}/captions, /captions/generate,
                               /captions/status
16. Storage driver filter    - mvs_storage_driver (S3, Backblaze B2, etc.)
17. Watermark filter         - mvs_watermark_enabled + mvs_generate_watermark
18. AI providers action      - mvs_ai_providers
19. ConnectionTester         - admin AJAX for testing S3/API connections
20. AnalyticsService         - play event recording, heatmaps, retention curves
    + WP Cron:               mvs_pro_prune_play_events (daily)
21. AnalyticsController      - REST: /media/{id}/events, /media/{id}/analytics,
                               /analytics/top, /analytics/overview
22. BattleService            - (conditional: mvs_battles_enabled)
    + BattleController       - REST: /battles, /battles/{id}, accept/decline/
                               submit/vote
    + AS recurring:          mvs_resolve_expired_battles (hourly)
23. ChallengeService         - (conditional: mvs_challenges_enabled)
    + AutopilotService       - weekly auto-creation from theme pool
      + AS recurring:        mvs_autopilot_create_weekly_challenge (weekly)
    + ChallengeController    - REST: /challenges, /challenges/{id}, entries, vote,
                               results, cancel
    + AS recurring (x3):     mvs_activate_scheduled_challenges,
                             mvs_close_challenge_entries,
                             mvs_finalize_expired_challenges (all hourly)
24. TournamentService        - (conditional: mvs_tournaments_enabled)
    + TournamentController   - REST: /tournaments, /tournaments/{id}, register,
                               bracket, participants, matches/submit, matches/vote
    + AS recurring (x2):     mvs_start_registered_tournaments,
                             mvs_resolve_expired_matches (both hourly)
25. BoostService             - (conditional: mvs_boosts_enabled)
    + BoostController        - REST: /boosts (list + create)
    + AS recurring:          mvs_expire_boosts (hourly)
26. CompeteSummaryController - REST: /competitions/active-summary (public)
27. StreakService             - upload streak tracking + milestone XP
28. Streak badge filter      - mvs_user_display_name (appends streak badge)
29. Activity types filter    - mvs_activity_types (registers 8 gamification types)
30. GamificationTemplateLoader - frontend pages: /media/battles/, /media/challenges/,
                                /media/tournaments/
31. Admin block (is_admin):
      ProSettings, QuotaPage, MigrationPage, ReportManager,
      AnalyticsDashboard, GamificationSettings,
      ChallengeManager, TournamentManager, BattleMonitor,
      CompetitionsDashboard, ThemeLibrary
    + mvs_moderation_tabs filter (adds User Reports tab)
    + mvs_stats_tabs filter (adds Video Analytics tab)
32. First-run seed           - seeds default theme pool + creates first weekly
                               challenge (once, on activation)
33. admin_enqueue_scripts    - Pro admin CSS/JS
34. Submenu reorder          - admin_menu priority 999 for logical grouping
35. Reserved paths filter    - mvs_reserved_media_paths (reserves /compete/)
36. Explore banner action    - mvs_before_explore_grid (pinned competition card)
37. Nav menu filter          - wp_nav_menu_items (injects Compete link)
38. Dashboard tabs           - mvs_dashboard_tabs + mvs_dashboard_panels (My Media
                               compete panels)
```

---

## 2. Database Schema

All tables created by `includes/Core/Migrator.php`.
DB version option: `mvs_pro_db_version`, current version: **4**.

### 2.1 `{prefix}mvs_quota_packages`

| Column | Type | Notes |
|---|---|---|
| id | bigint(20) unsigned | PK, AUTO_INCREMENT |
| name | varchar(200) | |
| image_limit | int unsigned | 0 = unlimited |
| video_limit | int unsigned | 0 = unlimited |
| audio_limit | int unsigned | 0 = unlimited |
| storage_bytes | bigint(20) unsigned | 0 = unlimited |
| is_default | tinyint(1) | |
| sort_order | int unsigned | |
| created_at | datetime | DEFAULT CURRENT_TIMESTAMP |

**Indexes:** `PRIMARY (id)`, `KEY is_default (is_default)`

A default "Unlimited" package (all limits=0, is_default=1) is seeded on
first migration.

### 2.2 `{prefix}mvs_credit_log`

| Column | Type | Notes |
|---|---|---|
| id | bigint(20) unsigned | PK, AUTO_INCREMENT |
| user_id | bigint(20) unsigned | |
| credit_type | varchar(20) | image, video, audio |
| amount | int | positive = credit, negative = debit |
| balance_after | int | |
| source | varchar(50) | admin, webhook, purchase, etc. |
| reference | varchar(200) | order ID, etc. |
| note | text | |
| created_at | datetime | DEFAULT CURRENT_TIMESTAMP |

**Indexes:** `PRIMARY (id)`, `KEY user_id`, `KEY source`, `KEY created_at`

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

### 2.4 Messaging Tables

These tables may also exist in the free plugin's Migrator (see note in
`Plugin.php` line 189 -- messaging now handled by free plugin; Pro retains
Group DM, read receipts, and WebSocket transport as future extensions).

#### `{prefix}mvs_conversations`

| Column | Type | Notes |
|---|---|---|
| id | bigint(20) unsigned | PK, AUTO_INCREMENT |
| type | varchar(20) | DEFAULT 'direct' |
| title | varchar(200) | nullable |
| created_by | bigint(20) unsigned | |
| last_message_id | bigint(20) unsigned | nullable |
| last_message_preview | varchar(100) | |
| last_activity_at | datetime | DEFAULT CURRENT_TIMESTAMP |
| created_at | datetime | DEFAULT CURRENT_TIMESTAMP |

**Indexes:** `PRIMARY (id)`, `KEY last_activity (last_activity_at)`,
`KEY created_by (created_by)`

#### `{prefix}mvs_conversation_participants`

| Column | Type | Notes |
|---|---|---|
| id | bigint(20) unsigned | PK, AUTO_INCREMENT |
| conversation_id | bigint(20) unsigned | |
| user_id | bigint(20) unsigned | |
| last_read_at | datetime | nullable |
| is_muted | tinyint(1) | DEFAULT 0 |
| muted_until | datetime | nullable |
| is_pinned | tinyint(1) | DEFAULT 0 |
| is_archived | tinyint(1) | DEFAULT 0 |
| status | varchar(20) | DEFAULT 'active' |
| joined_at | datetime | DEFAULT CURRENT_TIMESTAMP |

**Indexes:** `PRIMARY (id)`, `UNIQUE conv_user (conversation_id, user_id)`,
`KEY user_status (user_id, status)`, `KEY conv_read (conversation_id, last_read_at)`

#### `{prefix}mvs_messages`

| Column | Type | Notes |
|---|---|---|
| id | bigint(20) unsigned | PK, AUTO_INCREMENT |
| conversation_id | bigint(20) unsigned | |
| sender_id | bigint(20) unsigned | |
| content | text | nullable |
| message_type | varchar(20) | DEFAULT 'text' |
| attachment_id | bigint(20) unsigned | nullable |
| media_id | bigint(20) unsigned | nullable (shared media) |
| parent_id | bigint(20) unsigned | nullable (reply-to) |
| metadata | text | nullable (JSON) |
| is_deleted | tinyint(1) | DEFAULT 0 |
| deleted_for_all | tinyint(1) | DEFAULT 0 |
| created_at | datetime | DEFAULT CURRENT_TIMESTAMP |

**Indexes:** `PRIMARY (id)`, `KEY conv_date (conversation_id, created_at)`,
`KEY conv_id (conversation_id, id)`, `KEY sender (sender_id)`

#### `{prefix}mvs_message_reactions`

| Column | Type | Notes |
|---|---|---|
| id | bigint(20) unsigned | PK, AUTO_INCREMENT |
| message_id | bigint(20) unsigned | |
| user_id | bigint(20) unsigned | |
| emoji | varchar(20) | |
| created_at | datetime | DEFAULT CURRENT_TIMESTAMP |

**Indexes:** `PRIMARY (id)`, `UNIQUE msg_user (message_id, user_id)`,
`KEY message_id (message_id)`

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

---

## 3. REST API Map

All routes are under the `mvs-pro/v1` namespace.

### 3.1 Quota

| Method | Route | Permission | Description |
|---|---|---|---|
| GET | `/me/quota` | logged_in | Current user quota summary |
| GET | `/users/{user_id}/quota` | manage_mvs_settings | Any user's quota |
| POST | `/users/{user_id}/package` | manage_mvs_settings | Assign package to user |
| POST | `/users/{user_id}/credits` | manage_mvs_settings | Add credits to user |
| GET | `/packages` | manage_mvs_settings | List all packages |
| POST | `/packages` | manage_mvs_settings | Create package |
| PUT/PATCH | `/packages/{id}` | manage_mvs_settings | Update package |
| DELETE | `/packages/{id}` | manage_mvs_settings | Delete package |
| POST | `/credits/webhook` | __return_true (HMAC) | External credit top-up |
| GET | `/me/credits/history` | logged_in | Credit transaction history |

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

### 3.10 Messaging

| Method | Route | Permission | Description |
|---|---|---|---|
| GET | `/me/conversations` | logged_in | List conversations (tabs: all/unread/requests) |
| POST | `/conversations` | logged_in | Create/find conversation |
| GET | `/conversations/{id}` | logged_in | Get conversation |
| PATCH | `/conversations/{id}` | logged_in | Update (mute/pin/archive) |
| DELETE | `/conversations/{id}` | logged_in | Delete conversation |
| GET | `/conversations/{id}/messages` | logged_in | List messages (cursor) |
| POST | `/conversations/{id}/messages` | logged_in | Send message |
| DELETE | `/messages/{id}` | logged_in | Delete message (for self) |
| DELETE | `/messages/{id}/unsend` | logged_in | Unsend (delete for all) |
| POST | `/conversations/{id}/read` | logged_in | Mark read |
| POST | `/conversations/{id}/typing` | logged_in | Typing indicator |
| POST | `/messages/{id}/reactions` | logged_in | Add reaction |
| DELETE | `/messages/{id}/reactions` | logged_in | Remove reaction |
| GET | `/messages/poll` | logged_in | Long-poll for new messages |
| GET | `/me/messages/unread-count` | logged_in | Unread count |
| POST | `/conversations/{id}/accept` | logged_in | Accept message request |
| POST | `/conversations/{id}/decline` | logged_in | Decline message request |
| POST | `/messages/upload` | logged_in | Upload DM attachment |

### 3.11 Competition Summary

| Method | Route | Permission | Description |
|---|---|---|---|
| GET | `/competitions/active-summary` | public | Aggregated competition discovery data |

---

## 4. Hook Reference

All hooks are within `includes/`. Organized by category.

### 4.1 Cron / Scheduled Hooks

| Hook | Type | Schedule | File : Line |
|---|---|---|---|
| `mvs_pro_transcribe_media` | AS single | on-demand | TranscriptionService.php:50 |
| `mvs_pro_prune_play_events` | WP Cron | daily | AnalyticsService.php:47 |
| `mvs_resolve_expired_battles` | AS recurring | hourly | Plugin.php:206 |
| `mvs_activate_scheduled_challenges` | AS recurring | hourly | Plugin.php:231 |
| `mvs_close_challenge_entries` | AS recurring | hourly | Plugin.php:234 |
| `mvs_finalize_expired_challenges` | AS recurring | hourly | Plugin.php:237 |
| `mvs_start_registered_tournaments` | AS recurring | hourly | Plugin.php:259 |
| `mvs_resolve_expired_matches` | AS recurring | hourly | Plugin.php:262 |
| `mvs_expire_boosts` | AS recurring | hourly | Plugin.php:282 |
| `mvs_autopilot_create_weekly_challenge` | AS recurring | weekly | AutopilotService.php:72 |

### 4.2 Analytics Hooks

| Hook | Type | File : Line |
|---|---|---|
| `mvs_pro_analytics_event_data` | filter | AnalyticsService.php:119 |
| `mvs_pro_analytics_recorded` | action | AnalyticsService.php:144 |
| `mvs_pro_analytics_summary` | filter | AnalyticsService.php:648 |

### 4.3 Membership Adapter Hooks

| Hook | Type | File : Line |
|---|---|---|
| `mvs_pro_memberpress_package_assigned` | action | MemberPressAdapter.php:105 |
| `mvs_pro_memberpress_package_reverted` | action | MemberPressAdapter.php:137 |
| `mvs_pro_pmpro_package_assigned` | action | PaidMembershipsProAdapter.php:92 |
| `mvs_pro_pmpro_package_reverted` | action | PaidMembershipsProAdapter.php:109 |
| `mvs_pro_woo_package_assigned` | action | WooCommerceAdapter.php:114 |
| `mvs_pro_woo_package_reverted` | action | WooCommerceAdapter.php:137 |

### 4.4 Messaging Hooks

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

| Hook | Type | File : Line |
|---|---|---|
| `mvs_pro_quota_source` | filter | QuotaService.php:232 |
| `mvs_pro_before_quota_check` | filter | QuotaService.php:424 |
| `mvs_pro_credits_added` | action | QuotaService.php:315 |
| `mvs_quota_render_mapping_fields` | action | QuotaPage.php:525 |
| `mvs_quota_save_mapping` | action | QuotaPage.php:689 |

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

| Hook | Type | File : Line |
|---|---|---|
| `mvs_layout_config` | filter | LayoutManager.php:93 |
| `mvs_active_layout` | filter | LayoutManager.php:119 |
| `mvs_layout_modes` | filter | LayoutManager.php:139 |
| `mvs_layout_template_map` | filter | LayoutManager.php:162 |
| `mvs_before_layout_render` | action | LayoutManager.php:178 |
| `mvs_layout_assets` | action | LayoutManager.php:217 |
| `mvs_user_display_name` | filter | Plugin.php:302 |
| `mvs_activity_types` | filter | Plugin.php:318 |
| `mvs_reserved_media_paths` | filter | Plugin.php:414 |
| `mvs_before_explore_grid` | action | Plugin.php:421 |
| `mvs_dashboard_tabs` | action | Plugin.php:427 |
| `mvs_dashboard_panels` | action | Plugin.php:428 |

### 4.10 Admin UI Hooks

| Hook | Type | File : Line |
|---|---|---|
| `mvs_moderation_tabs` | filter | Plugin.php:351 |
| `mvs_stats_tabs` | filter | Plugin.php:365 |

### 4.11 Core Extension Points (registered in Plugin.php)

| Hook | Type | File : Line |
|---|---|---|
| `mvs_storage_driver` | filter | Plugin.php:165 |
| `mvs_watermark_enabled` | filter | Plugin.php:168 |
| `mvs_generate_watermark` | filter | Plugin.php:169 |
| `mvs_ai_providers` | action | Plugin.php:172 |

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

Each competition type has hourly recurring AS actions:

- **Battles:** `mvs_resolve_expired_battles` calls `BattleService::resolve_expired()`.
  Finds battles past vote_deadline and declares the entry with more votes the
  winner, or draws if tied.
- **Challenges:** Three hourly hooks:
  1. `mvs_activate_scheduled_challenges` -- moves pending challenges past start_date to active.
  2. `mvs_close_challenge_entries` -- locks submissions after end_date (voting-only phase).
  3. `mvs_finalize_expired_challenges` -- ranks entries by vote_count after voting_end_date,
     awards XP (1st/2nd/3rd/participation).
- **Tournaments:** Two hourly hooks:
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
  - extracts audio from source file
  - sends to configured AI provider (OpenAI Whisper)
  - generates WebVTT file
  - stores in {uploads}/mvs-captions/{media_id}.vtt
  - writes media meta: captions = {vtt_url, language, provider, word_count, duration, generated_at}
  - fires: do_action('mvs_pro_captions_generated', $media_id, $saved)
```

---

## 7. Quota System

### 7.1 Package Model

Quota packages define per-type upload limits and total storage:

- `image_limit`, `video_limit`, `audio_limit` -- 0 means unlimited
- `storage_bytes` -- 0 means unlimited
- One package is `is_default = 1` (the free-tier fallback)
- Users are assigned a package via `_mvs_quota_package_id` user meta

### 7.2 Credit System

Credits extend a user's quota beyond their package limits. Stored in user meta:
`_mvs_extra_image_credits`, `_mvs_extra_video_credits`, `_mvs_extra_audio_credits`.

Every credit change is logged in `mvs_credit_log` with source (admin, webhook,
purchase), reference, and running balance.

### 7.3 Enforcement

`QuotaService::enforce_quota()` hooks into `mvs_upload_args`:

1. Resolves user's effective package (filterable via `mvs_pro_quota_source`).
2. Checks current usage against package limits + extra credits.
3. Checks total storage against `storage_bytes`.
4. Returns WP_Error if any limit exceeded; passes through if within quota.

Usage counters are maintained via hooks:
- `mvs_media_uploaded` -> `increment_usage()` (image_count, video_count, etc.)
- `mvs_media_deleted` -> `decrement_usage()`

### 7.4 Membership Adapter Pattern

Three adapters auto-assign packages when a user's membership changes:

| Adapter | Plugin | Option Key | Hooks |
|---|---|---|---|
| `MemberPressAdapter` | MemberPress | `mvs_pro_quota_memberpress_map` | `mepr-txn-status-complete`, `mepr-txn-status-refunded`, etc. |
| `PaidMembershipsProAdapter` | PMPro | `mvs_pro_quota_pmpro_map` | `pmpro_after_change_membership_level` |
| `WooCommerceAdapter` | WooCommerce | `mvs_pro_quota_woo_map` | `woocommerce_order_status_completed`, etc. |

Each adapter stores a mapping (membership/product ID -> package ID) in a WP
option. When a transaction completes, the mapped package is assigned; when
a subscription expires, the user reverts to the default package.

### 7.5 External Webhook

`POST /mvs-pro/v1/credits/webhook` accepts HMAC-SHA256-signed payloads
(secret stored in `mvs_pro_webhook_secret` option). Payload: `{user_id,
media_type, amount, reason}`. Allows external billing systems to top up
credits without a dedicated adapter.
