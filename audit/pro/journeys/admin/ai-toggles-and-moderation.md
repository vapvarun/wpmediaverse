---
journey: ai-toggles-and-moderation
plugin: wpmediaverse-pro
priority: high
roles: [administrator, member]
covers: [MV-AI-005, MV-AI-006, MV-AI-010, MV-AI-012, ai-toggles, moderation-categories, ai-budget]
prerequisites:
  - "Both plugins active; a working active provider (any of the four)"
  - "admin/09-ai-features-owner-control.md already proves the basic toggle-gates-path and no-key path — this file goes past that"
estimated_runtime_minutes: 8
---

# Auto-analyze sub-toggles suppress the right calls, category safety-nets hold, the budget cap blocks BEFORE any HTTP call, and a flagged upload really lands in the moderation queue

**Why this journey exists**: `admin/09-ai-features-owner-control.md` proves the
master toggle and a basic no-key path. This journey proves the finer contracts
underneath it: describe/tag are independent opt-out sub-toggles of the master
(a documented past bug had an owner enabling only moderation get 3 provider
calls instead of 1), an empty category selection falls back to ALL categories
rather than "moderate nothing" (a deliberate safety net that could look like a
bug to someone deliberately trying to disable moderation), the budget cap is
checked BEFORE any provider HTTP call, and a flagged AI result is a genuine DB
write into the moderation queue, not just a hook nobody listens to.

## Setup

- Site: `$SITE_URL`; admin `?autologin=admin`; member `?autologin=<member>`.
- AI tab: `mvs_ai_auto_analyze` (master), `mvs_ai_auto_describe`, `mvs_ai_auto_tag`, `mvs_ai_auto_apply_tags`.
- Moderation tab: `mvs_ai_moderation_categories`, `mvs_ai_moderation_custom_terms`, `mvs_ai_auto_moderate`.

## Steps

### 1. Master on with default sub-toggles — both description and tags generate
- **Action**: turn on ONLY `mvs_ai_auto_analyze` (describe/tag stay at their defaults, already on); upload an image as the member.
- **Expect**: both a description AND tags generate — both default true once the master is on.

### 2. Turning off describe leaves tag generation intact
- **Action**: turn off `mvs_ai_auto_describe`, leave `mvs_ai_auto_tag` on; upload.
- **Expect**: only tags generate, no description — confirms the historic "1 toggle, 3 calls" bug does not recur: turning off one sub-toggle actually suppresses that specific provider call, not just the write.
- **On fail**: the AI pipeline orchestrator gating each provider call on its own sub-toggle, not just the master.

### 3. `mvs_ai_auto_apply_tags` writes into the real taxonomy
- **Action**: turn on `mvs_ai_auto_apply_tags`; upload.
- **Expect**: generated tags are ALSO written into the real `mvs_tag` taxonomy via `wp_set_object_terms()` — visible on the frontend anywhere tags render, not just in admin meta.

### 4. Zero tags returned makes no taxonomy write
- **Action**: (simulate or find) a provider call that returns zero tags with `auto_apply_tags` on.
- **Expect**: no taxonomy write happens; any existing tags on the item are left untouched.

### 5. `mvs_ai_auto_moderate` lives on a different tab and isn't gated by the analyze master
- **Action**: with `mvs_ai_auto_analyze` OFF entirely, turn `mvs_ai_auto_moderate` ON (Moderation tab); upload an image that would trip moderation.
- **Expect**: moderation still runs and can flag the item — `mvs_ai_auto_moderate` is independent of the analyze master (moved to the Moderation tab in 2.6.0).

### 6. Unchecking ALL moderation categories falls back to moderating everything
- **Action**: uncheck every category checkbox, save; upload an image that would trip at least one category.
- **Expect**: an empty rule falls back to ALL categories, not "moderate nothing" — this is the deliberate safety net; verify the flagged upload still gets caught rather than sliding through.

### 7. Custom terms only reach the narrated-prompt providers
- **Action**: add a custom term (e.g. "graffiti"); check behavior with Anthropic active vs. with Vision/Rekognition active.
- **Expect**: the custom term is appended to the narrated term list Anthropic's (and Free's OpenAI) prompt uses — Vision/Rekognition, with their fixed vendor category sets, never see or act on custom terms at all.

### 8. Vendor-flag-to-category alias matching
- **Action**: with Rekognition active, trigger a call that returns "Explicit Nudity" as a raw flag.
- **Expect**: substring-matches the `nudity` category's alias list case-insensitively and maps correctly.

### 9. A comma inside a custom term breaks the parser — known gap, verify it's still there and document it
- **Action**: add a custom term containing a literal comma (e.g. `"red, white"`).
- **Expect**: the comma-split parser splits it into two separate terms — no escaping mechanism exists. Confirm this matches the documented gap; don't silently "fix" it without a decision.

### 10. Budget cap blocks BEFORE any provider HTTP call
- **Action**: set `mvs_ai_monthly_budget` low enough (or accumulate tracked cost) to exceed it within the current month; trigger a call.
- **Expect**: `mvs_ai_budget_exceeded` "Monthly AI budget has been exceeded." returned BEFORE any provider HTTP call is made — verify via a network-request check (or a deliberately broken/blackholed provider endpoint that would otherwise be hit) that nothing actually goes out over the wire.
- **On fail**: budget-check ordering relative to the provider call in `AIService`.

### 11. Auto-triggered pipeline hits the SAME budget check as manual calls
- **Action**: with the budget exceeded, upload as a member (auto-analyze path) — not a manual admin re-run.
- **Expect**: auto-analyze/auto-moderate silently pause for the rest of the month too — same gate, no owner notification beyond whatever surfaces in `ai_status`.

### 12. Budget resets automatically on month rollover
- **Action**: `wp eval` to simulate the calendar month rolling over (or set the system clock forward if feasible), trigger a call.
- **Expect**: a new month starts the usage counter fresh automatically — no manual reset needed.

### 13. Negative budget treated as uncapped, same as 0
- **Action**: `wp option update mvs_ai_monthly_budget -5`; trigger a call.
- **Expect**: treated as uncapped (code checks `<= 0`), same as a `0` value.

### 14. A flagged AI result actually lands in the moderation queue via a real DB write
- **Action**: with auto-moderate on and moderation categories configured, upload an image a provider flags as unsafe; check the Moderation Queue admin page.
- **Expect**: `moderate()`'s result stores to `ai_moderation` meta, passes through the `mvs_ai_moderation_result` filter, matches against enabled categories, and — if flagged — writes `moderation_status = 'flagged'` DIRECTLY on the media row (a DB write, not merely a fired hook) plus fires `mvs_media_flagged`. The item becomes visible in the Moderation Queue.
- **On fail**: the auto-moderate pipeline's DB write to `moderation_status` — confirm it's an actual column update, not just a hook dispatch nobody in Free consumes.

### 15. Empty flags array on `safe:false` renders a plain dash, not a crash
- **Action**: (simulate) a provider result of `safe:false` with an EMPTY `flags` array reaching the Moderation Queue.
- **Expect**: the Flags column renders a plain "—" instead of any pill — no crash, no missing row. A moderator would need to open the item to investigate further; this is documented, not a bug.

## Pass criteria

1. Describe/tag sub-toggles independently suppress their specific provider call when off, not just their meta write.
2. `auto_apply_tags` writes to the real taxonomy only when tags exist; empty results never write.
3. An empty category selection falls back to ALL categories; custom terms reach only narrated-prompt providers (Anthropic/OpenAI), never Vision/Rekognition.
4. The budget cap blocks BEFORE any HTTP call, applies identically to the auto-triggered pipeline, resets on month rollover, and treats a negative value as uncapped.
5. A flagged upload is a genuine DB write into `moderation_status`, visible in the Moderation Queue, including the empty-flags-array render case.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Turning off describe still generates a description | pipeline gates only on the master, not the sub-toggle | AI pipeline orchestrator |
| All-categories-unchecked moderates nothing | empty-fallback-to-all safety net missing | category resolution logic |
| Budget-exceeded call still hits the provider's HTTP endpoint | budget check ordered after the call, not before | `AIService` call-dispatch method |
| Flagged upload never appears in Moderation Queue | `moderation_status` write missing, only a hook fired | the auto-moderate pipeline's queue-write step |
| Custom terms affect Vision/Rekognition | custom-term injection leaked into the fixed-vendor-vocabulary providers | Vision/Rekognition provider moderate methods |
