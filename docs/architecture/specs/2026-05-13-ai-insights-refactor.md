# AI Insights — Usability-First Refactor

**Date:** 2026-05-13
**Status:** Proposed
**Target release:** 1.3.0
**Cross-refs:** Basecamp #9822981239 (Media Tagging Suggestions), #9822979354 (Improved Accessibility Features)

---

## Problem

AI runs invisibly today. `AIService::process()` writes `ai_description`, `ai_tags`, `ai_moderation`, `ai_confidence`, `ai_status` to `mvs_media_meta`, but nothing surfaces:

- Tags only show silently when `mvs_ai_auto_apply_tags=1`. No indicator they came from AI. No accept/reject.
- `ai_description` is never read by any template. Never shown to the user. Never used as `<img alt>`.
- `ai_moderation` is read only by `Admin\ModerationQueue` and the moderation REST endpoint. The uploader never learns their post was flagged.
- `mvs_media_flagged` action fires from `AIService::moderate()` but nobody listens.
- AI only runs from `mvs_media_uploaded`. No way to re-run, no way to backfill old media.

**Cost is high too.** Each upload with all three features on makes 3 separate Vision API calls = 3 image uploads to OpenAI = ~$0.0024 per media at gpt-4o-mini. At 100k uploads/month = ~$240/month. Customers turn AI off, then UX is moot.

The fix is one redesign that solves both problems.

---

## Non-goals

- No new admin settings page. Existing toggles (`mvs_ai_auto_analyze`, `mvs_ai_auto_apply_tags`, `mvs_ai_auto_moderate`, budget) stay.
- No new AI provider. We ship with the existing OpenAI/GoogleVision/Rekognition lineup.
- No Whisper changes. `TranscriptionService` and `CaptionProvider` are out of scope; they handle audio, not images.
- No Abilities API rewiring. The 17 declarative entries stay discovery-only for now; execution wiring is a separate 1.4.0 effort.

---

## Refactor decisions (not patchwork)

### 1. Provider interface: 3 narrow methods → 1 rich method

**Current** (`Services\AIProviderInterface`):
```
analyze_image( $url ): ?array{description, confidence}
generate_tags( $url, $description ): array
moderate_content( $url ): array{safe, flags, confidence}
```

**Why this is wrong:** The interface forces 3 API round trips per media because each method takes the image URL and returns a single concern. Providers cannot batch even when their API supports it.

**Refactor:**

```
interface AIProviderInterface {
    public function get_id(): string;
    public function is_available(): bool;
    public function analyze( string $image_url, AIAnalysisRequest $request ): ?AIAnalysis;
}
```

`AIAnalysisRequest` controls what to ask for (`include_description`, `include_alt`, `include_tags`, `include_moderation`) so providers can short-circuit unused work. `AIAnalysis` is the return value object — see §2.

**Migration:** the old 3-method interface stays as `AIProviderInterface_v1` for one release cycle. `AIService` checks `instanceof` and routes accordingly. New providers implement the v2 interface. Pro's GoogleVision + Rekognition are updated to v2 in the same release. Free's OpenAIProvider is the reference implementation. v1 deprecation warning logged on every call; removal in 1.4.0.

### 2. Introduce `AIAnalysis` value object

**Current:** AI output is a sprawl of 5 separate `MediaRepository::set()` calls (`ai_description`, `ai_confidence`, `ai_tags`, `ai_moderation`, `ai_status`). Plus a new `ai_alt` field for the alt-text work. Adding a 6th field grows the sprawl.

**Why this is wrong:** No single source of truth for what an "AI result" looks like. Every reader has to know all 5+ field names. Schema drift over time is guaranteed.

**Refactor:**

```
final class AIAnalysis {
    public function __construct(
        public readonly string $alt,          // <125 chars
        public readonly string $description,  // 2-3 sentences
        public readonly array  $tags,         // 5-10 single-word
        public readonly bool   $safe,         // moderation pass
        public readonly array  $flags,        // moderation categories
        public readonly float  $confidence,
        public readonly string $provider_id,
        public readonly int    $generated_at, // unix ts
    ) {}

    public static function from_provider_response( array $raw, string $provider_id ): ?self;
    public function to_storage_array(): array;            // {ai_alt, ai_description, ai_tags, ai_moderation, ai_status, ai_confidence, ai_provider, ai_generated_at}
    public static function from_storage_array( array $stored ): ?self;
    public function for_rest_response(): array;           // public-safe fields only
    public function is_flagged(): bool;
}
```

Single class owns the shape. `AIService` calls `MediaRepository::set_many( $media_id, $analysis->to_storage_array() )` — one query, atomic write, no field-by-field drift.

### 3. `AIService` becomes thin orchestrator

**Current:** 335 lines. Three near-identical methods (`analyze`, `auto_tag`, `moderate`) that each fetch URL, call provider, persist results, track usage. `process()` runs all three. Lots of duplicated boilerplate.

**Why this is wrong:** Three methods, three signed-URL fetches, three provider calls, three repository writes, three usage tracks. Each one a chance for partial failure (description saved but tags failed and moderation timed out). State drift between fields.

**Refactor:**

```
class AIService {
    public function process( int $media_id, AIAnalysisRequest $request = null ): AIAnalysis|WP_Error {
        // 1. cache check on file_hash (early return)
        // 2. signed URL retrieval (once)
        // 3. budget check (once)
        // 4. provider call (once)
        // 5. AIAnalysis value object construction
        // 6. atomic persist via MediaRepository::set_many
        // 7. usage tracking (once)
        // 8. auto-apply taxonomy tags if setting on
        // 9. fire mvs_media_flagged if !$analysis->is_flagged()
        // 10. apply mvs_ai_result filter
        // return AIAnalysis
    }

    public function analyze( int $media_id ) { _deprecated_function(__METHOD__, '1.3.0', 'AIService::process'); return $this->process($media_id)->to_legacy_analyze(); }
    public function auto_tag( int $media_id ) { _deprecated_function(...); return $this->process($media_id)->tags; }
    public function moderate( int $media_id ) { _deprecated_function(...); return ... }
}
```

Old per-operation methods kept as thin deprecation shims that route through `process()`. Third-party code that calls `auto_tag` directly keeps working but gets a notice. Single source of truth for the AI pipeline.

### 4. Cache check via `file_hash`

`mvs_media_index.file_hash` already exists (Migrator schema lines 301, 548). Use it as the cache key:

```
// In AIService::process()
$file_hash = $repo->get_raw( $media_id, 'file_hash' );
$cached_for_hash = $repo->find_existing_ai_analysis_by_hash( $file_hash );
if ( $cached_for_hash ) {
    return $cached_for_hash;  // copy AI result from sibling media with same content
}
```

This dedupes re-uploads of the same image across users.

`MediaRepository::find_existing_ai_analysis_by_hash` is a new method:
```
SELECT m.id FROM mvs_media_index m
WHERE m.file_hash = %s
  AND EXISTS (SELECT 1 FROM mvs_media_meta WHERE media_id = m.id AND meta_key = 'ai_status' AND meta_value = 'complete')
LIMIT 1
```

### 5. Combine 3 OpenAI Vision calls into 1 structured JSON call

**Current OpenAIProvider** makes 3 separate `chat/completions` calls. Each sends the image. Each prompt is different. `max_tokens: 500` each.

**Refactor:** ONE call. ONE image upload. Use `response_format: {type: "json_object"}` to force JSON.

```
prompt:
  "Analyze this image. Return ONLY a JSON object with these keys:
   - alt: single sentence under 125 characters, for <img alt>
   - description: 2-3 sentence paragraph for the media detail page
   - tags: array of 5-10 single-word or short tags
   - safe: boolean — is this image safe for a general audience
   - flags: array — applicable categories from [nudity, violence, hate, self-harm, drugs, spam]
   - confidence: number 0-1
   Return ONLY the JSON, no prose."

image_url with detail: "low"  (image tokens: ~85 instead of ~1530)
max_tokens: 300
response_format: { type: "json_object" }
```

Effect: ~85% per-media cost reduction. Same outputs.

Pro's GoogleVision + Rekognition providers convert their multi-call responses into the same `AIAnalysis` shape via `from_provider_response`. They were already doing labels + moderation in separate calls; they may continue to do so internally and just bundle the result.

### 6. Prompts move out of provider PHP

**Current:** Prompts hard-coded inline in `OpenAIProvider::analyze_image()`, `generate_tags()`, `moderate_content()`.

**Refactor:** New namespace `WPMediaVerse\AI\Prompts\` with one file per prompt template returning a string. Filterable via `mvs_ai_prompt_{slug}` so customers can tweak without forking. Default prompt: `WPMediaVerse\AI\Prompts\AnalyzeImagePrompt::get()`.

### 7. Template helper for alt text — central refactor for the a11y wiring

**Current:** No central alt-text helper. Every `<img>` rendering site has its own logic (or none). Some pass the media title as alt, some pass empty string. The `ai_description` field is never consulted.

**Why this is wrong:** Wiring `ai_alt` to images by patching individual templates one by one is patchwork. Future templates will forget the fallback chain. Schema drift returns.

**Refactor:**

```
// in TemplateHelpers
public static function media_alt( int $media_id ): string {
    $repo = self::repository();
    $manual = (string) $repo->get( $media_id, 'alt_text' );    // user-provided
    if ( '' !== $manual ) return $manual;

    $ai_alt = (string) $repo->get( $media_id, 'ai_alt' );      // AI-generated
    if ( '' !== $ai_alt ) return $ai_alt;

    $title = (string) $repo->get( $media_id, 'title' );        // fallback
    return $title;
    // empty string for decorative images is a deliberate downstream choice
}
```

**Single rule for every `<img>` render site:** call `TemplateHelpers::media_alt( $media_id )`. No exceptions.

Touch list for the refactor (Free + Pro):

| File | Current state |
|---|---|
| `templates/media-single.php` | hard-coded title-as-alt |
| `templates/partials/shared-ui-shell.php` | empty alt |
| `templates/album.php` | title-as-alt |
| `src/blocks/*/render.php` (12 blocks Free + 12 Pro feed/list/grid blocks) | inconsistent |
| `includes/Integrations/BuddyPress/ActivityContentIntegration.php` (BP activity render path) | uses media title |
| `includes/Admin/ModerationQueue.php` | empty alt on thumbs |
| Pro `LayoutManager` + 4 feed layouts (Instagram/Flickr/Pinterest/Dribbble) | inconsistent |

Every one updated in a single PR. Lint rule via `bin/coding-rules-check.sh`: any `<img src=` for a WPMediaVerse media in our templates must have its `alt=` come from `TemplateHelpers::media_alt()`. New Coding Rule #17.

### 8. Trigger surface refactor — extract `AIScheduler`

**Current:** `Plugin::maybe_queue_ai` and `Plugin::handle_ai_process` live in `Plugin.php` as static methods. `Plugin.php` is already 1,208 lines; the AI bootstrap is one of seven concerns mashed together.

**Refactor:** Extract to `Services\AIScheduler` class:

```
class AIScheduler {
    public function __construct( private AIService $ai ) {}
    public function register_hooks(): void {
        add_action( 'mvs_media_uploaded', [ $this, 'maybe_queue' ], 10, 1 );
        add_action( 'mvs_ai_process_media', [ $this, 'handle' ], 10, 1 );
        add_action( 'mvs_media_flagged', [ $this, 'notify_uploader' ], 10, 2 );
    }
    public function maybe_queue( int $media_id ): void { ... }
    public function handle( int $media_id ): void { ... }
    public function notify_uploader( int $media_id, AIAnalysis $analysis ): void { ... }
}
```

`mvs_media_flagged` listener wires the missing notification:
```
$notifications->create( [
    'user_id' => $repo->get( $media_id, 'user_id' ),
    'type'    => 'mvs_ai_moderation_flag',
    'subject' => __( 'Your media was flagged for review', 'wpmediaverse' ),
    'body'    => sprintf( __( 'AI flagged your upload (%s) for: %s. An admin will review.', 'wpmediaverse' ), $title, implode( ', ', $analysis->flags ) ),
    'link'    => admin_url( 'admin.php?page=wpmediaverse-moderation' ),  // or frontend if appropriate
] );
```

The action already fires; we just connect the wire that should have been there from day one.

### 9. Manual "Run AI" trigger

**Current:** AI only runs from `mvs_media_uploaded`. Users cannot re-run after editing media. Admins cannot backfill older media.

**Refactor:** New REST endpoint, not a patch on existing routes.

```
POST /wp-json/mvs/v1/media/{id}/ai-analyze
- Permission: media owner OR manage_options
- Body: { force_refresh: bool }  (default false; use cached if file_hash matches)
- Returns: AIAnalysis::for_rest_response()
- Queues the same mvs_ai_process_media job under the hood for consistency
```

New controller `REST\Controller\AIController` (separate from `ModerationController` which currently owns `/ai/usage`). Eventually `ModerationController::get_ai_usage` migrates to `AIController` too; do that in the same PR since it's adjacent code.

### 10. UI surface — AI Insights panel in upload modal

The UI deliverable lives in `src/blocks/shared-ui/` (the upload modal lives there). After upload completes, the modal:

1. Polls the new `GET /media/{id}` (or subscribes via Interactivity API store) for `ai_status === 'complete'`
2. When complete, renders an "AI Insights" panel above the publish button:
   - **Alt text** — single-line input pre-filled with `ai_alt`, editable
   - **Description** — textarea pre-filled with `ai_description`, editable
   - **Tags** — chips with checkboxes, all checked by default; user unchecks rejected ones
   - **Moderation** — if `!safe`, amber banner: "AI flagged: nudity, violence. Review and edit before publishing."
   - **Re-run** — small "Generate again" button if user wants fresh suggestions
3. On publish, the accepted edits ride the existing media update endpoint as normal fields. AI-generated values not edited by user become the saved values. Rejected tags simply aren't applied to `mvs_tag` taxonomy.

Single React-style state slice for AI insights. No new state management library. Uses existing Interactivity API store + `@wordpress/i18n`.

### 11. Settings UX — simplify

**Current:** Three separate toggles in admin (`mvs_ai_auto_analyze`, `mvs_ai_auto_apply_tags`, `mvs_ai_auto_moderate`).

**Refactor:** Replace with one "AI Insights" toggle (`mvs_ai_enabled`, default off — opt-in for API key cost reasons). When enabled, the upload modal shows the panel and writes accepted edits. The three legacy toggles auto-mirror to `mvs_ai_enabled` on upgrade and are hidden from the new settings UI but retained as filters for the rare admin who wants the old silent behavior:

```
add_filter( 'mvs_ai_auto_apply_tags', '__return_true' );  // old silent mode
```

Settings migration: on `1.3.0` activation, if any of the three legacy options are `1`, set `mvs_ai_enabled = 1`. Document the change in the upgrade notice.

---

## Implementation order

### Phase 1 — Foundation refactor (no user-visible change)

- [ ] Add `AIAnalysisRequest` value object
- [ ] Add `AIAnalysis` value object with serialization helpers
- [ ] Add `AIProviderInterface_v2` (the new single-method interface). Keep v1 with deprecation notice.
- [ ] Update Free `OpenAIProvider` to v2 with combined JSON prompt + `detail: "low"` + `response_format`
- [ ] Update Pro `GoogleVision\AIProvider` and `Rekognition\AIProvider` to v2
- [ ] Move prompts to `AI\Prompts\` namespace
- [ ] Refactor `AIService::process()` to single-call orchestrator
- [ ] Add v1 deprecation shims for `analyze`, `auto_tag`, `moderate`
- [ ] Add `MediaRepository::find_existing_ai_analysis_by_hash()`
- [ ] Add cache lookup at top of `AIService::process()`
- [ ] PHPUnit covering: cache hit/miss, partial failure handling, deprecation shim behavior
- [ ] Lint via existing coding-rules-check.sh

**Effort:** ~2 days. Zero user-visible change. All existing automated tests still pass.

### Phase 2 — Trigger + notification refactor

- [ ] Extract `Services\AIScheduler` from `Plugin.php`. `Plugin::maybe_queue_ai` and `Plugin::handle_ai_process` become 1-line delegators (deprecated).
- [ ] `AIScheduler::notify_uploader` listener for `mvs_media_flagged`
- [ ] New `NotificationService` notification type `mvs_ai_moderation_flag`
- [ ] PHPUnit: hook registration, flag → notification flow

**Effort:** ~0.5 day.

### Phase 3 — Manual trigger + REST

- [ ] New `REST\Controller\AIController` with `POST /media/{id}/ai-analyze`
- [ ] Migrate `GET /ai/usage` from `ModerationController` to `AIController`
- [ ] Wire admin per-row "Run AI" button
- [ ] Wire frontend per-media "Generate AI Insights" button (visible to owner)
- [ ] PHPUnit + REST contract test

**Effort:** ~1 day.

### Phase 4 — Alt text wiring (the a11y refactor)

- [ ] Add `TemplateHelpers::media_alt( $media_id )` with fallback chain
- [ ] Update every `<img>` render site (Free + Pro) to call the helper
- [ ] Add Coding Rule #17 to `bin/coding-rules-check.sh`
- [ ] PHPUnit + smoke test: render BP activity, lightbox, gallery block; assert alt attribute resolution

**Effort:** ~1 day. Heaviest file count but mechanical refactor.

### Phase 5 — Upload modal AI Insights panel

- [ ] Extend Interactivity API store with `aiInsights` slice
- [ ] Build the panel UI (alt input + description textarea + tag chips + moderation banner + re-run button)
- [ ] Wire to the new `POST /media/{id}/ai-analyze` endpoint for re-runs
- [ ] Wire publish flow to save accepted edits
- [ ] CSS for the panel (mobile responsive per CLAUDE.md rule)
- [ ] Playwright smoke: upload an image, assert AI panel appears, edit tag, publish, assert tag taxonomy reflects only accepted tags
- [ ] 390px mobile pass

**Effort:** ~1.5 days.

### Phase 6 — Settings simplification + upgrade migration

- [ ] Add `mvs_ai_enabled` setting (default off)
- [ ] On `1.3.0` activation, migrate `mvs_ai_auto_analyze=1` → `mvs_ai_enabled=1`
- [ ] Hide the three legacy toggles from settings UI; keep options + filters for back-compat
- [ ] Upgrade notice in readme.txt explaining the consolidation
- [ ] PHPUnit: upgrade migration scenarios

**Effort:** ~0.5 day.

**Total: ~6.5 days** for the full plan with PHPUnit + browser verification per CLAUDE.md.

---

## Migration considerations

### Data
- New fields written by `AIAnalysis::to_storage_array`: `ai_alt` (new), `ai_provider` (new), `ai_generated_at` (new). All meta-key adds — no schema change needed; `mvs_media_meta` is key-value.
- Old fields stay populated by the same atomic write so existing readers (ModerationQueue, ModerationController) keep working unchanged.

### Third-party providers
- v1 `AIProviderInterface` deprecated, not removed in 1.3.0
- `AIService` detects which version via `instanceof` and routes accordingly
- Third-party providers continue to work for one release with a `_doing_it_wrong` notice on every call
- Removal scheduled for 1.4.0 with clear changelog warning

### Existing AI data
- Pre-1.3.0 media has `ai_description`, `ai_tags`, `ai_moderation` but no `ai_alt`
- `TemplateHelpers::media_alt()` falls back gracefully: manual alt > ai_alt (empty for old) > title
- Background backfill cron not needed; alt populates naturally next time the media is re-processed (via manual "Run AI" button or fresh upload of same file_hash)

### Settings
- `mvs_ai_auto_apply_tags=1` was the only way to actually surface AI tags before. After upgrade, AI Insights panel shows tags for accept/reject regardless of this option. The setting becomes a filter, not a toggle: returns whether to auto-apply if the user does NOT engage the AI Insights panel (e.g. publishes immediately before AI completes).

---

## Coding rule additions

**Rule #17 — AI output flows through value object.** Any code path that reads more than one AI field (`ai_*`) must reconstruct an `AIAnalysis` via `AIAnalysis::from_storage_array()` rather than calling `MediaRepository::get()` for each field. Prevents schema-drift across readers. Enforced by `bin/coding-rules-check.sh` regex check for `ai_description.*ai_tags` patterns in `get()` chains.

**Rule #18 — Alt-text helper is the only alt source for our media.** Templates rendering a WPMediaVerse media `<img>` must call `TemplateHelpers::media_alt( $media_id )` for the alt attribute. Hard-coded titles or empty alts fail the gate. Enforced by `bin/coding-rules-check.sh` regex match on `<img[^>]*src=[^>]*mvs[^>]*alt=`.

---

## Files touched (estimate)

**Free (~15 files):**
- `includes/Services/AIService.php` — full refactor
- `includes/Services/OpenAIProvider.php` — full refactor
- `includes/Services/AIProviderInterface.php` — v2 added, v1 marked deprecated
- `includes/AI/AIAnalysis.php` — new
- `includes/AI/AIAnalysisRequest.php` — new
- `includes/AI/Prompts/AnalyzeImagePrompt.php` — new
- `includes/Services/AIScheduler.php` — new
- `includes/Repository/MediaRepository.php` — `find_existing_ai_analysis_by_hash()` added
- `includes/Core/Plugin.php` — bootstrap update (remove inlined AI hooks)
- `includes/Core/TemplateHelpers.php` — `media_alt()` added
- `includes/REST/Controller/AIController.php` — new
- `includes/REST/Controller/ModerationController.php` — `/ai/usage` removed (moved to AIController)
- `includes/Admin/Settings/SettingsRegistrar.php` — settings simplification
- `src/blocks/shared-ui/view.js` — AI Insights panel
- `assets/css/shared-ui-frame.css` — panel styles + mobile breakpoint
- `bin/coding-rules-check.sh` — Rule #17, #18

**Pro (~10 files):**
- `includes/Integrations/GoogleVision/AIProvider.php` — v2 refactor
- `includes/Integrations/Rekognition/AIProvider.php` — v2 refactor
- All Pro block `render.php` files — alt helper wired
- `includes/Frontend/Layouts/*.php` — alt helper wired

**Templates (~12 files):** every `<img>` site listed in §7 touch list.

---

## Backward compatibility commitment

- 1.3.0 ships with v1 provider interface + legacy AIService method shims working
- 1.4.0 removes v1 interface and legacy methods (one release cycle of deprecation)
- All actions and filters preserved: `mvs_should_ai_analyze`, `mvs_ai_moderation_result`, `mvs_ai_result`, `mvs_media_flagged`
- New action: `mvs_ai_analysis_complete( AIAnalysis $analysis, int $media_id )` — fires after persist + filter chain
- New filter: `mvs_ai_analysis( AIAnalysis $analysis, int $media_id )` — mutate before persist

---

## Test plan

**PHPUnit (Free + Pro):**
- `AIAnalysis` serialization round-trip
- `AIService::process` cache hit returns cached, cache miss calls provider
- `AIService::process` partial provider failure → WP_Error, no partial write
- Deprecation shims route through `process()` and return matching shape
- `TemplateHelpers::media_alt` fallback chain in all four states
- `AIScheduler::notify_uploader` creates notification when `mvs_media_flagged` fires

**Browser (Playwright, per CLAUDE.md verify-per-item rule):**
- Upload image → AI panel appears → edit description → publish → media saved with edited description
- Upload image → AI panel appears → uncheck a tag → publish → that tag not in media's tags
- Upload image with deliberately-flagged content → moderation banner shows → notification created
- Existing media without `ai_*` data → "Generate AI Insights" button → panel populates after run
- 390px mobile: panel layout, tag chips wrap, buttons stack
- Hover/focus/visited states on all panel buttons

**Performance:**
- Token usage measured before and after (assert ≥80% reduction per media)
- Cache hit on same file_hash skips provider call (assert no HTTP request)

---

## Open questions

1. Should the AI Insights panel block publish until AI completes, or let user publish early and apply AI result async? **Proposed:** let user publish early; AI result applies async via existing auto-apply flow. Most uploads complete the AI call in 2-4 seconds; users who want speed don't get blocked.

2. Should `ai_alt` go through the same auto-apply gate as tags, or always apply (it's invisible to readers)? **Proposed:** always apply, since alt text in `<img alt>` is invisible to sighted users and benefits accessibility/SEO unconditionally. Editable via per-media admin.

3. Where does the manual "Generate AI Insights" button live for the media owner on the frontend? **Proposed:** in the media detail page action row (next to Edit/Delete) for the owner only. Hidden for everyone else.

---

## Done criteria

A user can:

1. Upload a photo → see AI suggestions → accept/edit/reject → publish
2. Have alt text on their images without thinking about it (accessibility + SEO win)
3. Know if their post was flagged and why (notification + moderation banner)
4. Trigger AI manually on any media they own (button)

And the engineering side:

5. AI per-upload cost reduced by ≥80% (combined JSON call + low-detail mode + caching)
6. Single value object owns the AI shape (no schema drift)
7. Single trigger class owns AI scheduling (no Plugin.php sprawl)
8. Single template helper owns alt-text resolution (no per-template patchwork)
9. v1 provider interface deprecated with a one-release runway for third-party providers

When all nine are true, AI Insights ships as 1.3.0's headline customer-visible improvement.
