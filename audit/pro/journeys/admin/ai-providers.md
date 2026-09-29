---
journey: ai-providers
plugin: wpmediaverse-pro
priority: high
roles: [administrator]
covers: [MV-AI-001, MV-AI-002, MV-AI-003, MV-AI-004, MV-AI-009, ai-providers, single-active-provider]
prerequisites:
  - "Both plugins active"
  - "Credentials (or intentionally invalid credentials) for Google Vision, AWS Rekognition and Anthropic"
estimated_runtime_minutes: 12
---

# Each AI provider configures and behaves per its own contract, and exactly one is ever active despite several being configured

**Why this journey exists**: `admin/09-ai-features-owner-control.md` already
proves the provider-mismatch guard between OpenAI and Google in general terms.
This journey is the full per-provider config-and-behavior pass — Google
Vision's fixed 5-category safe-search vocabulary, Rekognition's dual-key
registration requirement and hand-rolled SigV4 signing, Anthropic's
site-configurable moderation categories and server-side image fetch — plus the
2.6.0 "no silent fallback" contract: `register_ai_providers()` may register
several keyed providers at once, but exactly one runs, selected purely by
`mvs_ai_provider`, never a redundancy pool.

## Setup

- Site: `$SITE_URL`; admin `?autologin=admin`.
- AI tab: Settings > AI & Moderation.

## Steps

### 1. Google Vision — no key means no provider, not a broken provider
- **Action**: set `mvs_ai_provider = google_vision` with the key field empty; upload an image with auto-analyze on.
- **Expect**: `register_ai_providers()` never registers Google Vision with an empty key — no active provider exists at all; the call returns `mvs_no_ai_provider` "No AI provider is configured."

### 2. Google Vision — configured, description + tags write correctly
- **Action**: add a valid key, save; upload an image with auto-describe and auto-tag on.
- **Expect**: description written to `ai_description` meta (top 5 labels joined); tags written to `ai_tags` (up to 15 labels, lowercased).

### 3. Google Vision — legacy stored value normalizes
- **Action**: `wp option update mvs_ai_provider google` (the pre-rename value) directly, then reload the AI tab.
- **Expect**: normalizes to `google_vision` on read — an old saved choice keeps working after the provider id rename.

### 4. Google Vision moderation — fixed 5-category vocabulary, alias-matched to site categories
- **Action**: with auto-moderate on, upload a clean image, then one that would trip SAFE_SEARCH_DETECTION on adult/violence/racy/medical/spoof at LIKELY+ confidence.
- **Expect**: clean image -> `{safe:true, flags:[], confidence:1.0}`. Flagged image -> flags collected from the fixed 5-category set, `safe:false`, `confidence:0.9`; flags feed Free's alias matching (`AIService::match_moderation_category()`) so vendor vocabulary ("adult", "racy") maps to the site's own categories ("nudity", etc.). A raw-flags-vs-checkbox mismatch is documented, not a bug.

### 5. Rekognition — requires BOTH keys to register at all
- **Action**: set `mvs_ai_provider = rekognition`; set only the access key, leave the secret blank; trigger a call.
- **Expect**: `register_ai_providers()` only registers Rekognition when BOTH access key and secret are present — with the secret blank, it's never registered, so the call gets `mvs_no_ai_provider` (same "not configured" outcome as a totally blank field, not a distinguishable "half-configured" state).

### 6. Rekognition — both keys present, correct thresholds
- **Action**: set both keys correctly, upload an image.
- **Expect**: `DetectLabels` (min confidence 70 for description, 60 for the wider 15-tag set) and `DetectModerationLabels` (min confidence 70) both succeed via a hand-rolled SigV4 signature (no AWS SDK).

### 7. Rekognition — image fetched via bytes, not URL, so a slow/unreachable image manifests generically
- **Action**: point at an image whose URL is slow or briefly unreachable.
- **Expect**: failure surfaces as a generic HTTP-error-driven moderation/tagging failure — no AWS-specific error message, since Rekognition downloads full bytes via `wp_remote_get()` and base64-encodes rather than passing a URL.

### 8. Anthropic — configured, description/tags/moderation all via a single vision message + site's own categories
- **Action**: set `mvs_ai_provider = anthropic` with a valid key; upload an image with auto-describe/tag/moderate all on; check the site's moderation category checkboxes narrow the flagged set (enable ONLY "violence" and "hate", re-upload a borderline image).
- **Expect**: description confidence fixed at 0.85 (not model-reported); tags via a 5-10 comma-separated list; moderation narrates the SITE's actually-enabled categories + custom terms directly into the prompt, and Anthropic's flagged set narrows to exactly those two categories — the only provider where this is true (Vision/Rekognition ignore the site's category checkboxes for their own detection).

### 9. Anthropic — no model dropdown since 2.6.0, old stored values still round-trip
- **Action**: `wp option get mvs_pro_anthropic_model`; check the AI tab for a model selector.
- **Expect**: no model dropdown visible; the option is pinned via a sanitize whitelist to `claude-haiku-4-5-20251001`; an old pre-removal stored choice still round-trips correctly (doesn't error or reset unexpectedly).

### 10. Anthropic — works on private/gated media, unlike Vision/Rekognition
- **Action**: upload a private/gated image with Anthropic active.
- **Expect**: succeeds — Anthropic's provider fetches image bytes server-side first and sends base64 (works for local/private/gated media), whereas Vision/Rekognition need the URL independently fetchable BY the remote API.

### 11. Anthropic — oversized image fails cleanly
- **Action**: upload an image over ~3.5MB raw bytes.
- **Expect**: every AI call on it fails cleanly (null response), never errors loudly — base64 inflation would exceed Anthropic's ~5MB request budget.

### 12. Only ONE provider is ever active, despite all three being keyed
- **Action**: configure valid keys for Google Vision, Rekognition AND Anthropic simultaneously, with `mvs_ai_provider = anthropic`; upload. Switch to `google_vision` without touching any keys; upload again. Switch to `rekognition`, then blank the Rekognition keys (leaving Vision/Anthropic keyed); upload again.
- **Expect**: step 1 -> only Anthropic's API is called; the other two sit registered-but-idle. Step 2 -> calls now go to Vision — proving the switch is a pure runtime selector, not a re-save-triggered reconfiguration. Step 3 -> with the selected provider now unavailable and `mvs_ai_provider_fallback` at its 2.6.0 default `false`, calls return `mvs_no_ai_provider` — NO silent fallback to Vision or Anthropic even though both are available.
- **On fail**: `AIService::get_active_provider()` — check it strictly reads `mvs_ai_provider` and never iterates registered-and-available providers as a fallback.

### 13. `mvs_ai_provider` pointing at nothing registered
- **Action**: `wp option update mvs_ai_provider not_a_real_provider`; upload.
- **Expect**: same `mvs_no_ai_provider` outcome as no provider configured — a stale/removed provider id behaves identically to blank.

## Pass criteria

1. Each of the three Pro providers registers only when its full credential set is present (Rekognition specifically requires BOTH keys); an incomplete or blank config always yields `mvs_no_ai_provider`, never a half-working state.
2. Google Vision's fixed 5-category vocabulary alias-matches correctly to site categories; Anthropic is the only provider whose moderation is genuinely driven by the site's own category checkboxes + custom terms.
3. Rekognition's image-bytes-via-`wp_remote_get()` and Anthropic's server-side-fetch-then-base64 behave exactly as documented, including the private-media and oversized-image edge cases.
4. Exactly one provider runs at a time, selected purely by `mvs_ai_provider` as a live runtime selector; with 2.6.0 defaults, there is never a silent fallback to another configured-but-unselected provider.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| A provider with a blank key still gets called | `register_ai_providers()` missing its credential-presence check | `includes/AI/*` provider registration |
| Anthropic's moderation ignores the site's category checkboxes | prompt-building not reading `mvs_ai_moderation_categories`/custom terms | Anthropic provider's `moderate_content()` |
| Switching provider silently falls back to another when the selected one fails | `mvs_ai_provider_fallback` default flipped, or fallback logic added | `AIService::get_active_provider()` |
| Legacy `google` value breaks instead of normalizing | normalization removed on read | wherever `mvs_ai_provider` is read/normalized |
| Rekognition registers with only one key present | dual-key check missing | Rekognition provider registration |
