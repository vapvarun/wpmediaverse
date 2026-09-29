---
journey: settings-ai-and-moderation-tab
plugin: wpmediaverse
priority: high
roles: [administrator, member]
covers: [MV-SET-025, MV-SET-027, MV-SET-028, MV-SET-029, MV-SET-030, MV-SET-031, MV-SET-032, settings-ai-and-moderation-tab]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "Auto-login mu-plugin available (?autologin=1)"
  - "A valid OpenAI API key for MV-SET-028/029/030/031/032"
  - "3+ distinct test member accounts able to report the same media item, for MV-SET-027"
  - "WP-CLI + mysql_query access"
estimated_runtime_minutes: 12
---

# AI and Moderation tab settings persist and gate real AI/moderation behavior

**Why this journey exists**: `admin/09-ai-features-owner-control.md` already locks the provider-selection and toggle-gates-code-path contract at a general level. This journey is the compact, settings-screen-specific companion: it walks the seven remaining AI/Moderation fields that live on these two tabs since the 2.6.0 tab reorganization, and proves each one's specific edge behavior named in the catalog (progressive disclosure, distinct-reporter counting, calendar-month budget reset).

**Manifest note**: `JOURNEY-COVERAGE-MANIFEST.md`'s dedup table claims 8 IDs for this file; the manifest's own per-area rows show only 7 (MV-SET-026, Member Reporting master switch, routes to the existing `customer/25-reports-enabled-by-default.md` instead). No 8th ID was found — treated as a manifest overcount, not a missing entry.

## Setup

- Site: `$SITE_URL`
- Admin: `?autologin=1`
- Settings screens: `admin.php?page=mvs-settings-moderation` (MV-SET-025, 027, 028); `admin.php?page=mvs-settings-ai` (MV-SET-029, 030, 031, 032).
- 3 distinct member accounts able to report the same media item (MV-SET-027).
- A valid OpenAI key (or `wp-config.php` constant) for the AI steps.

## Steps

### 1. MV-SET-025 — Community Guidelines URL
- **Action**: On the Moderation tab, enter a URL for `mvs_guidelines_url`; save; open the frontend report-content dialog. Then save a non-URL string (e.g. `not a url`) into the same field.
- **Assert**: the link appears in the report dialog once set; with the field empty, no broken/empty href is shown, the link is simply omitted. Confirm the non-URL string passes through WordPress's own URL-cleaning function and is saved as whatever survives that cleaning — there is no strict validation that rejects or bounces back a non-URL value (documented gap, not a new bug).

### 2. MV-SET-027 — Auto-Hide Threshold
- **Action**: Confirm `mvs_report_auto_hide_threshold` defaults to `3`. Have 3 distinct member accounts each report the same media item. Then have one of those same members attempt to report it a second time.
- **Assert**: the item auto-hides pending review once the 3rd DISTINCT reporter's report lands (report count, not reporter count, is never what's measured — a single member reporting twice can never inflate it, since the 2nd attempt from the same member is refused outright before it's counted). The owner and moderators still see the hidden item with a "hidden pending review" indicator in both the admin moderation queue and their own frontend view.

### 3. MV-SET-028 — AI Moderation (toggle + flag behavior)
- **Action**: On the Moderation tab, turn on "AI Moderation" (`mvs_ai_auto_moderate`); set "When AI Flags Content" to `flag`; upload content that should trip a listed category; check MediaVerse > Moderation > AI Flagged tab. Then empty the "AI Flag Criteria" category checklist and save.
- **Assert**: the category checklist and custom-terms box show ONLY while AI Moderation is on (progressive disclosure — showing them unconditionally is a bug); the AI Flagged tab itself is hidden when nothing is flagged AND AI moderation is off, but shows the flagged item otherwise. An emptied category list does NOT silently mean "flag nothing" — it must still behave as "all categories" (nudity, violence, hate, self-harm, drugs, spam).

### 4. MV-SET-029 — AI Provider + OpenAI API Key
- **Action**: On the AI tab, enter an OpenAI key in the field and save. Then define the same credential as a `wp-config.php` constant and reload the screen.
- **Assert**: the DB-stored key value is masked on screen, never echoed in plaintext in the page's HTML source. With the wp-config constant defined, the field renders LOCKED with "Defined in wp-config.php" text, and the stored DB option is ignored — not merged or silently overridden with no on-screen sign.

### 5. MV-SET-030 — Auto-Analyze Uploads
- **Action**: Turn on `mvs_ai_auto_analyze` with a valid key configured; upload an image; watch the AI status column on the All Media admin list (MV-ADM-004).
- **Assert**: the badge visibly transitions Processing → Complete (not silently eventually-consistent with no feedback in between). Force a failure (invalid key or budget exceeded) and confirm the badge lands visibly in "Failed" — it must never hang in "Processing" forever.

### 6. MV-SET-031 — Generate Descriptions / Generate Tags / Auto-Apply Tags
- **Action**: Turn on "Generate Tags" (`mvs_ai_auto_tag`) with "Auto-Apply Tags" (`mvs_ai_auto_apply_tags`) OFF; upload media; check the AI Review link on the All Media row. Then turn Auto-Apply Tags ON and upload again.
- **Assert**: with Auto-Apply off, tags are SUGGESTED and visible for review but not attached to the item automatically — this is the documented "safe starting configuration." With Auto-Apply on, tags attach automatically without a review step. Turning off Generate Tags while Auto-Apply Tags stays on produces no error (there is simply nothing to apply).

### 7. MV-SET-032 — Monthly AI Budget ($)
- **Action**: Set `mvs_ai_monthly_budget` to a very low value (e.g. `0.05`); trigger enough AI calls (describe/tag AND moderate) at the configured `mvs_ai_cost_per_call` to exceed it; check the Stats page (MV-ADM-017) AI Usage panel.
- **Assert**: further AI calls stop across ALL AI features once the cap is hit — moderation included, not just describe/tag. The AI Usage panel shows Budget alongside API Calls/Successful/Failed/Cost so the owner can see WHY calls stopped. Confirm the reset is calendar-month-boundary based (spend tracked per "YYYY-MM"), not a rolling 30-day window — spend must hit zero on the 1st of the next month regardless of when in the prior month the cap was reached.

## Pass criteria

ALL hold:
1. Every option above persists to `wp_options` byte-for-byte after save + reload.
2. Progressive-disclosure rows (category checklist, custom terms, review-link) show/hide exactly per their gating toggle, never unconditionally.
3. The distinct-reporter count for auto-hide is never inflatable by one member's repeat reports.
4. A `wp-config.php`-defined AI key always wins over the DB option, visibly locked, and the key is never exposed in plaintext HTML.
5. The monthly AI budget cap applies to moderation as well as describe/tag, and resets on the calendar month boundary.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Guidelines link shows as broken href when empty | Template doesn't check for empty option before rendering `<a>` | relevant report-dialog template |
| Auto-hide fires before 3 distinct reporters, or same member inflates count | Report count used instead of distinct-reporter count | `includes/Social/ReportService.php` |
| Category/custom-terms rows show while AI Moderation is off | Missing `data-mvs-show-when` gate or JS not wired | `assets/js/admin/ai-provider-fields.js`, `includes/Admin/Settings/SettingsPage.php` |
| Emptied category list flags nothing | Empty-array fallback not applied | `includes/Services/ModerationService.php` |
| AI key echoed in HTML source, or wp-config constant not honored | Field renderer missing the mask/lock branch | `includes/Admin/Settings/FieldRenderer.php` |
| AI status badge stuck in Processing | No terminal "Failed" transition on provider error | `includes/Services/AIService.php` |
| Auto-Apply Tags attaches tags with the toggle off | Apply step doesn't check `mvs_ai_auto_apply_tags` | `includes/Services/AIService.php` |
| Budget cap ignored by moderation calls | `check_budget()` missing from the moderate() path | `includes/Services/AIService.php::moderate` |
| Budget doesn't reset on month boundary | Spend key not keyed by "YYYY-MM" | `includes/Services/AIService.php` |
