# WPMediaVerse Pro — Code Flows

**Generated:** 2026-05-03 · **Plugin version:** 1.2.0

End-to-end request paths for the high-traffic Pro features. Read alongside Free's [`CODE_FLOWS.md`](../../../wpmediaverse/audit/CODE_FLOWS.md) — Pro flows often start in Pro and dip into Free for media reads/writes.

---

## Flow 1 — Submit a challenge entry

**Trigger:** user picks a media item and clicks "Submit entry" in `templates/challenges.php`.

```
Browser (Interactivity API mvs-pro/challenges)
  └─ POST /wp-json/mvs-pro/v1/challenges/{id}/entries  (via REST helper)
     └─ ChallengeController::submit_entry()
        ├─ permission: logged-in
        ├─ ChallengeService::submit_entry($id, $user_id, $media_id)
        │   ├─ Validate: challenge status === ACTIVE
        │   ├─ Validate: now < entry deadline
        │   ├─ Check max_entries_per_user (DB count on mvs_competition_entries)
        │   ├─ Check duplicate (same competition_id + media_id)
        │   ├─ INSERT INTO mvs_competition_entries
        │   └─ do_action('mvs_pro_challenge_entry_submitted', $entry_id, $user_id, $media_id)
        └─ JSON response → frontend re-fetches entries list
```

**Cover fallback:** `format_challenge` derives cover from most-recent entry's `MediaUrl::for_file` when `cover_image_url` is empty (1.1.3 fix).

**Files:**

| Layer | File |
|---|---|
| Controller | `includes/Challenges/ChallengeController.php` |
| Service | `includes/Challenges/ChallengeService.php` |
| Template | `templates/challenges.php` |
| CSS | `assets/css/gamification.css` (`.mvs-card-cover-wrap--placeholder`) |

---

## Flow 2 — Vote in a battle

**Trigger:** user clicks vote button in `templates/battles.php` during a live battle's voting window.

```
Browser
  └─ POST /wp-json/mvs-pro/v1/battles/{id}/vote
     └─ BattleController::vote_item()
        ├─ permission: can_vote (logged-in + not own battle)
        ├─ BattleService::vote($battle_id, $user_id, $entry_id)
        │   ├─ Validate: battle status === ACCEPTED, voting window open
        │   ├─ Check duplicate vote (mvs_competition_votes WHERE competition_id+user_id)
        │   ├─ INSERT INTO mvs_competition_votes
        │   ├─ UPDATE mvs_competition_entries SET vote_count = vote_count + 1
        │   ├─ Recompute winner_id if voting closed
        │   └─ do_action('mvs_pro_battle_voted', $battle_id, $user_id)
        └─ JSON response → vote counts re-render
```

**Files:**

| Layer | File |
|---|---|
| Controller | `includes/Battles/BattleController.php` |
| Service | `includes/Battles/BattleService.php` |

---

## Flow 3 — Tournament participant registration

**Trigger:** user clicks "Register" in `templates/tournaments.php` during the registration window.

```
Browser
  └─ POST /wp-json/mvs-pro/v1/tournaments/{id}/register
     └─ TournamentController::register_item()
        ├─ TournamentService::register_participant($tournament_id, $user_id)
        │   ├─ Validate: status === REGISTRATION
        │   ├─ Check spot remaining (settings.bracket_size − count(entries))
        │   ├─ Check duplicate registration
        │   └─ INSERT INTO mvs_competition_entries (role='participant', media_id=0)
        └─ JSON response
```

When registration closes, `AutopilotService::cron` (or admin "Start tournament" action) seeds the bracket: shuffles entries, creates `mvs_competition_matches` rows for round 1, transitions status to ACTIVE.

**Files:**

| Layer | File |
|---|---|
| Controller | `includes/Tournaments/TournamentController.php` |
| Service | `includes/Tournaments/TournamentService.php` |

---

## Flow 4 — Whisper transcription (auto-captions)

**Trigger:** user clicks "Generate captions" on a media item, OR media-uploaded hook auto-runs.

```
WP backend
  └─ TranscriptionService::transcribe($media_id)
     ├─ Resolve filesystem path:
     │   ├─ MediaRepository::get($id, 'wp_attachment_id') → get_attached_file()
     │   └─ Fallback: parse MediaRepository::get($id, 'file_url') → derive abs path
     │      // CI: storage-internal — URL never emitted, only used to compute filesystem path
     ├─ WhisperProvider::transcribe($file_path, $language)
     │   ├─ file_get_contents($file_path)  ← reads filesystem directly (NOT a URL fetch)
     │   ├─ Build multipart/form-data body
     │   └─ wp_remote_post('https://api.openai.com/v1/audio/transcriptions', …)
     ├─ Convert segments → WebVTT
     ├─ Save .vtt to wp-content/uploads/wpmediaverse/captions/<media_id>.vtt
     └─ MediaRepository::set($id, 'caption_url', <signed URL>)
```

**Why this is path-based, not URL-based:** Whisper accepts file uploads only (multipart). If we passed a URL, OpenAI would have to fetch through the `.htaccess` gate (which requires a signed URL). Reading directly from the filesystem avoids that round-trip.

**Files:**

| Layer | File |
|---|---|
| Service | `includes/Captions/TranscriptionService.php` |
| Provider | `includes/Captions/WhisperProvider.php` |
| Controller | `includes/Captions/CaptionController.php` |

---

## Flow 5 — S3 / BunnyCDN storage

**Trigger:** user uploads media and `mvs_storage_provider` is set to `s3` or `bunnycdn` in Pro settings.

```
Free's UploadService::upload()
  └─ StorageService::resolve_driver()
     └─ apply_filters('mvs_storage_driver')
        └─ Pro: returns S3Driver or BunnyCDNDriver based on setting
  └─ $driver->store($source_path, $rel_path)
     ├─ S3Driver:
     │   ├─ AWS Signature V4 PUT to s3://bucket/wpmediaverse/<rel>
     │   ├─ Set ACL = private
     │   └─ Retry 3× on transient failure
     └─ BunnyCDNDriver:
         ├─ HTTP PUT to https://storage.bunnycdn.com/<storage-zone>/<rel>
         ├─ Authentication via AccessKey header
         └─ CDN URL = https://<pull-zone>.b-cdn.net/<rel> (with optional token signing)
```

When media is read for emission, Free's `Plugin::maybe_sign_file_url` filter signs the URL (regardless of which driver returned it). Cloud drivers' `url()` method returns the CDN URL; Free's signing wraps it with the gated-uploads serve token.

**Files:**

| Layer | File |
|---|---|
| Filter consumer | `includes/Core/Plugin.php::register_storage_driver` |
| S3 | `includes/Storage/S3Driver.php` |
| Bunny | `includes/Storage/BunnyCDNDriver.php` |
| Free upload | `../wpmediaverse/includes/Services/UploadService.php` |

---

## Flow 6 — Quota enforcement on upload

**Trigger:** user attempts to upload a media file.

```
UploadService::validate($file)  ← Free
  └─ apply_filters('mvs_pre_upload_check', $allowed=true, $user_id, $file)
     └─ Pro: QuotaService::check_user_quota($user_id, $file_size, $media_type)
        ├─ Resolve quota package: WooCommerce/MemberPress/PMP adapters or default mvs_quota_packages
        ├─ Sum used storage: SUM(file_size) from mvs_media_index WHERE post_author = $user_id
        ├─ Sum used count by media_type
        └─ Return WP_Error if over limit, else true
```

**Files:**

| Layer | File |
|---|---|
| Service | `includes/Quota/QuotaService.php` |
| Adapters | `includes/Quota/Adapters/{MemberPress,PaidMembershipsPro,WooCommerce}Adapter.php` |

---

## Flow 7 — Video analytics (heatmap)

**Trigger:** user plays a video; `templates/partials/feed-card.php` (video variant) emits play events via REST.

```
Browser (HTML5 video element)
  └─ ['play','pause','seek','timeupdate','ended'] events fire
     └─ POST /wp-json/mvs-pro/v1/videos/{id}/event
        └─ AnalyticsController::record_event()
           └─ AnalyticsService::record($media_id, $user_id, $session_id, $event, $position_seconds)
              └─ INSERT INTO mvs_play_events

Owner views heatmap:
  └─ GET /wp-json/mvs-pro/v1/videos/{id}/analytics
     └─ AnalyticsController::get_heatmap()
        └─ AnalyticsService::aggregate_heatmap($media_id, $duration)
           └─ Bucket events by 1-second slots → return [{position, plays, drops}]
```

Daily cron `mvs_pro_prune_play_events` drops events > 90 days.

**Files:**

| Layer | File |
|---|---|
| Service | `includes/Analytics/AnalyticsService.php` |
| Controller | `includes/Analytics/AnalyticsController.php` |

---

## Cross-cutting: Pro→Free boundary

Pro never imports Free classes directly (sole exception: `MediaRepository`). Two access patterns:

1. **`Plugin::free_service('key')`** — returns a Free service instance from `ServiceContainer`, or `null` if missing. Safe across Free version drift.
2. **Hooks** — `apply_filters('mvs_*', …)` and `do_action('mvs_*', …)` fired by Free; Pro consumes via `add_filter` / `add_action`.

Whenever a flow needs to "talk to Free", it's one of those two patterns.

---

## Flow 8 — Block render (universal Phase 3 pattern, 1.2.0)

Every Pro block (`mvs/pro-tournament`, `…/pro-challenge`, `…/pro-leaderboard`, the 4 feed blocks, etc. — 12 total) follows the same shape. **Zero DB queries inside the block render.php; all data access lives in the Renderer/Layout class.**

```
WP block-rendering pipeline (frontend or REST oEmbed)
  └─ src/blocks/pro-<slug>/render.php
     ├─ Read $attributes (WP merges block.json defaults)
     ├─ \WPMediaVersePro\Blocks\MVS_CSS::add( $uniqueId, $attributes )
     │   └─ Builds per-instance scoped CSS (padding/margin/border/shadow/typography from
     │      the 20 standard attributes), keyed off `mvs-block-{uniqueId}`. Stored in a
     │      static array; emitted on `wp_footer`.
     ├─ get_block_wrapper_attributes(['class' => 'mvs-pro-block mvs-block-<uid> <visibility-classes>'])
     │   ├─ WP core handles `align: wide/full`, anchor, custom class names.
     │   └─ visibility classes from \WPMediaVersePro\Blocks\StandardAttributes::visibility_classes()
     ├─ Instantiate the appropriate handler:
     │     Competition blocks  → new \WPMediaVersePro\<Domain>\Renderer()
     │                            (Tournaments/Challenges/Battles)
     │     Leaderboard         → new \WPMediaVersePro\Frontend\LeaderboardRenderer()
     │     Compete-hub         → new \WPMediaVersePro\Frontend\CompeteHubRenderer()
     │     Feed blocks         → new \WPMediaVersePro\Frontend\Layouts\<Mode>Layout()
     │                            (Instagram/Flickr/Pinterest/Dribbble)
     ├─ For feed blocks ONLY: $layout->enqueue_assets()  // Rule 6 — Layouts don't auto-enqueue
     │                                                       on the block path
     └─ printf('<div %1$s>%2$s</div>', $wrapper_attrs, $renderer->render…($attributes))
```

**Two render targets per Renderer class:**

| Renderer | `render_*()` methods returning string |
|---|---|
| `Tournaments\Renderer` | `render_single($id, $attrs)` (block: `pro-tournament`), `render_list($attrs)` (block: `pro-tournaments-list`) |
| `Challenges\Renderer` | `render_single`, `render_list` |
| `Battles\Renderer` | `render_single`, `render_active` |
| `Frontend\LeaderboardRenderer` | `render($attrs)` — self-contained, no template |
| `Frontend\CompeteHubRenderer` | `render($attrs)` |
| `Frontend\Layouts\<Mode>Layout` | `render_feed(array $args = []): string` — Phase 3a `LayoutMode` interface contract |

**Competition renderers buffer extracted body templates** (`templates/<surface>-body.php`) and seed the deep-link query var (`mvs_tournament_id` / `mvs_challenge_id` / `mvs_battle_id`) so the existing Interactivity store loads the right view without template forking.

**Files:**

| Layer | File |
|---|---|
| Block source | `src/blocks/pro-*/{block.json,index.js,edit.js,render.php,style.css}` |
| Block registrar | `includes/Blocks/BlockRegistrar.php` (reads `build/blocks/<slug>/block.json`) |
| Standard attrs | `includes/Blocks/StandardAttributes.php` (injects 20 attrs via `block_type_metadata` filter) |
| Scoped CSS | `includes/Blocks/MVS_CSS.php` (per-instance `<style>` emitted on `wp_footer`) |
| Shortcodes | `includes/Blocks/Shortcodes.php` (12 `[mvs_pro_*]` shortcodes; same renderer call as the block; kebab→camelCase attr translation) |
| Editor preview | `src/blocks/shared/block-preview-card.js` (replaces `<ServerSideRender>` for Interactivity-store-driven blocks) |

---

## Flow 9 — Per-page Layout block render (Phase 3a, 1.2.0)

Frontend feed blocks (`pro-instagram-feed`, `pro-flickr-feed`, `pro-pinterest-feed`, `pro-dribbble-feed`) embed a layout's feed grid anywhere on the site, with per-instance attribute control.

```
Browser request → page containing the feed block
  └─ src/blocks/pro-<layout>-feed/render.php
     ├─ MVS_CSS::add($uid, $attributes)
     ├─ wrapper_attrs = get_block_wrapper_attributes(...)
     ├─ $layout = new Frontend\Layouts\<Mode>Layout()
     ├─ $layout->enqueue_assets()                    // Rule 6 — required
     └─ $layout->render_feed($attributes)            // Phase 3a contract
        └─ ob_start; include templates/layouts/<slug>/feed-body.php; ob_get_clean
           ├─ Reads only $args (perPage, paged, scope, filterTag, filterCategory, profileUser)
           │  — InstagramLayout is fully arg-driven; the other three currently ignore $args
           │  (Phase 3d arg-ifies them as it builds matching surfaces)
           ├─ Queries via Free's MediaRepository (paginated)
           └─ Emits the feed-body markup (cards / stories bar / tag cloud as applicable)
```

Site-wide path is unchanged — `LayoutManager::get_layout($mode)` selects the layout and calls the same `render_feed()`, but from `LayoutManager::override_template()` on `mvs_locate_template`, not from a block. Both paths produce identical output.

**Files:**

| Layer | File |
|---|---|
| LayoutMode interface | `includes/Frontend/Layouts/LayoutMode.php` |
| LayoutManager | `includes/Frontend/Layouts/LayoutManager.php` |
| InstagramLayout | `includes/Frontend/Layouts/InstagramLayout.php` (+ 3 sibling layouts) |
| Feed bodies | `templates/layouts/<slug>/feed-body.php` |
| Page wrappers | `templates/layouts/<slug>/feed.php` (~10 lines, includes the body) |

---

## Flow 10 — Migration platform card (Phase 5 P2.1, 1.2.0)

Admin opens **WPMediaVerse → Migration Tools**. The `MigrationPage` shell renders one card per registered platform-specific `MigrationAdmin`. Detection + counts run server-side at page load (no `mvs_migration_detect` AJAX hop). User clicks "Start" on a card; JS dispatches AJAX batches.

```
Admin page load:
  └─ \WPMediaVersePro\Admin\MigrationPage::render
     └─ For each \WPMediaVersePro\Integrations\<Platform>\MigrationAdmin in the registered list:
        ├─ $card->is_available()    // skip if target plugin not active
        ├─ $card->count_total()     // source-table SELECT COUNT(*)
        ├─ $card->count_imported()  // mvs_media_meta SELECT COUNT WHERE meta_key=card->meta_key()
        └─ $card->render_card()     // emits the card HTML (+ optional extra_card_html())

User clicks "Start" on (say) the rtMedia card:
  └─ Browser → POST admin-ajax.php?action=mvs_migration_batch
     ├─ payload: { platform: 'rtmedia', batch_size: 25, offset: <current> }
     ├─ nonce: mvs_migration_nonce  (capability: manage_options)
     └─ \WPMediaVersePro\Admin\MigrationPage::ajax_run_batch (Rule 5 allowlisted)
        ├─ Resolve card by sanitize_key($_POST['platform'])
        ├─ $card->run_batch(int $batch_size, int $offset, array $options): array
        │   ├─ Query the next batch from the platform's source table
        │   ├─ For each row: build MVS attachment (sideload thumbnail via ImportThumbnailTrait)
        │   ├─ Stamp _mvs_<platform>_id meta key for dedup
        │   ├─ Optionally add to album via $card->add_to_album()
        │   └─ Return { imported, skipped, next_offset, done? }
        └─ wp_send_json_success(progress_payload)

Browser receives progress; updates the progress bar; loops while !done.
```

**Files:**

| Layer | File |
|---|---|
| Shell | `includes/Admin/MigrationPage.php` (627 lines — was 1,866) |
| Abstract base | `includes/Integrations/AbstractMigrationAdmin.php` |
| RtMedia card | `includes/Integrations/RtMedia/MigrationAdmin.php` (394) |
| MediaPress card | `includes/Integrations/MediaPress/MigrationAdmin.php` (278) |
| BuddyBoss card | `includes/Integrations/BuddyBoss/MigrationAdmin.php` (453) |
