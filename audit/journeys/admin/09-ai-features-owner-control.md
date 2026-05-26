---
journey: ai-features-owner-control
plugin: wpmediaverse
priority: high
roles: [administrator]
covers: [ai-provider-selection, ai-feature-toggles, ai-key-actually-used, graceful-degradation]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "WPMediaVerse Pro active (Google Vision / Rekognition / Whisper providers ship in Pro)"
  - "At least one real AI key available for a smoke test, OR run the no-key degradation path only"
estimated_runtime_minutes: 8
---

# The site owner controls which AI features run, and the selected provider is the one actually used

**Why this journey exists**: A site owner expects that (a) the AI Provider they pick is the one that actually gets called, (b) each AI feature has an on/off they control, and (c) with no key or a feature off, nothing breaks. AI keys must be wired at the implementation level, not just stored. This journey locks that contract and guards the known google vs google_vision provider-id mismatch.

## Setup

- Site: `$SITE_URL`
- Admin: `?autologin=1`
- AI settings: `$SITE_URL/wp-admin/admin.php?page=mvs-settings` then the "AI & Moderation" tab (`#ai`).
- Options of record: `mvs_ai_provider`, `mvs_ai_auto_analyze`, `mvs_ai_auto_apply_tags`, `mvs_ai_auto_moderate`, `mvs_moderation_auto_action`, `mvs_ai_monthly_budget`; Pro captions `mvs_pro_settings[captions_auto]`.

## Steps

### 1. Selected provider is the one used (mismatch guard)
- **Action**: set the AI Provider dropdown to "Google Vision" and Save; then `mysql_query "SELECT option_value FROM wp_options WHERE option_name='mvs_ai_provider'"`; then assert `AIService::get_active_provider()` resolves to the Google Vision provider (id `google_vision`) when its key is set.
- **Expect**: the stored value resolves to the Google Vision provider — NOT a silent fallback to OpenAI. (Legacy stored value `google` must also resolve to `google_vision`.)
- **On fail**: option value / whitelist / UI key vs `GoogleVision\AIProvider::get_id()` mismatch — `includes/Admin/Settings/{SettingsRegistrar,Sanitizers}.php`, `includes/Services/AIService.php::get_active_provider`.

### 2. Each AI feature toggle gates its code path
- **Action**: for each toggle (`mvs_ai_auto_analyze`, `mvs_ai_auto_moderate`, `mvs_ai_auto_apply_tags`, captions `captions_auto`), set it OFF, upload a media item, and confirm the corresponding provider call does NOT run (no AI tags written / no moderation flag / no caption job queued). Then set ON and confirm it does (with a key present).
- **Expect**: every toggle visible in the AI settings UI is actually checked in code before the provider is invoked — no toggle is cosmetic, and no AI feature runs with no owner control.
- **On fail**: `includes/Core/Plugin.php::maybe_queue_ai`, `AIService::process`, `ModerationService`, Pro `Captions/TranscriptionService::on_media_uploaded`.

### 3. Key is actually sent
- **Action**: with a provider + key configured and the feature ON, upload an item and confirm an outbound HTTP request to the provider carries the key (Authorization Bearer for OpenAI/Whisper, `?key=` for Vision, SigV4 for Rekognition).
- **Expect**: the configured key reaches the provider endpoint (not a stored-but-unused setting).
- **On fail**: provider `analyze_image` / `moderate_content` / `transcribe` request callsite.

### 4. Graceful degradation (no key / feature off)
- **Action**: clear the active provider's key; upload a media item.
- **Expect**: no fatal, no broken admin UX; AI steps cleanly skip (WP_Error logged, empty result), media still publishes and displays.
- **On fail**: provider `is_available()` / `get_active_provider()` null handling.

### 5. Budget cap applies where the owner expects
- **Action**: set `mvs_ai_monthly_budget` low; confirm analyze/tag AND moderate respect the cap.
- **Expect**: AI calls stop when the monthly cap is reached across all AI features (not just analyze/tag).
- **On fail**: `AIService::moderate` missing `check_budget()`.

## Pass criteria

ALL of the following hold:
1. The provider chosen in settings is the provider AIService actually uses (no silent OpenAI fallback); legacy `google` resolves to `google_vision`.
2. Every AI feature toggle in the UI gates its code path; no AI runs without an owner toggle.
3. The configured key is sent in the provider request when a feature is on.
4. With no key / feature off, AI cleanly skips and media still works.
5. The monthly budget cap applies to all AI features including moderation.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Picking Google Vision still calls OpenAI | provider-id mismatch (`google` vs `google_vision`) | `includes/Admin/Settings/Sanitizers.php`, `SettingsRegistrar.php`, `AIService.php` |
| Toggle off but AI still runs | toggle not checked in code | `includes/Core/Plugin.php`, `AIService.php`, Pro `TranscriptionService.php` |
| Key configured but never sent | provider request not wired | provider `AIProvider.php` / `OpenAIProvider.php` |
| Fatal on upload with no key | missing null/availability guard | `AIService::get_active_provider` |
| Moderation ignores budget | cap not applied to moderate() | `AIService::moderate` |
