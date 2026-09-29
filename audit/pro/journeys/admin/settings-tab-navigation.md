---
journey: settings-tab-navigation
plugin: wpmediaverse-pro
priority: normal
roles: [administrator]
covers: [MV-PSET-001, MV-PSET-002, settings-tab-navigation, settings-sidebar-merge]
prerequisites:
  - "Both plugins active"
estimated_runtime_minutes: 5
---

# Compact bundle: hash-anchor tabs load every section's assets, and Pro's sidebar entries merge into Free's cards rather than floating separately

**Why this journey exists**: the settings screen's tabs are pure client-side hash anchors with no `$_GET['section']` on the server — this was the direct cause of a real bug where connector CSS/JS never loaded because it was gated on a `$_GET` check that could never be true on a hash-routed UI. Any NEW settings section is exactly as exposed to this bug class as connectors was.

## Steps (one block per ID)

### 1. MV-PSET-001 — Hash-anchor tabs, assets load regardless of active tab
- **Action**: navigate directly to `admin.php?page=mvs-settings#connectors` via URL; reload while on a non-default tab; switch tabs via the sidebar without a page reload.
- **Expect**: the correct tab is pre-selected on load (deep-linking works); EVERY Pro-added tab's assets — not just Connectors, which was the one already fixed — load correctly regardless of which hash tab is active, verified via `strpos($hook_suffix, 'mvs-settings')`-style loading rather than a `$_GET['section']` check. Re-check this specifically on any settings section added since the last time this journey ran — it's the exact bug class most likely to reappear on a new section.

### 2. MV-PSET-002 — Pro fields merge into Free's existing sidebar cards
- **Action**: confirm S3/BunnyCDN/R2/DOSpaces/Watermark sections appear INSIDE the existing "Storage" sidebar card (not as standalone Pro cards); confirm "Flickr import" appears under the "access" group, not under storage/media.
- **Expect**: merge via the `mvs_settings_sections` filter, per the explicit "keeps the sidebar clean" design intent — no orphaned duplicate Pro-only cards.

### 3. Every Pro sidebar entry actually renders an icon
- **Action**: spot-check the "Documents" sidebar item specifically (Lucide icon `file-text`, group `media`, priority 46).
- **Expect**: renders an icon. A prior version used a Dashicon-vocabulary name (`media-document`) on a sidebar that renders `data-lucide` attributes, which silently rendered NO icon at all — this is exactly the failure mode to catch on any future icon-name change.

### 4. A missing/renamed Free sidebar group fails silently, not loudly — confirm which
- **Action**: (structural check) the merge guards each target group with `isset($sections[$key])`.
- **Expect**: confirmed defensive — Pro's fields don't fatal if a Free group key is ever renamed/missing, but they DO silently disappear in that case. Flag as a known gap if it's ever observed in practice, not something to "fix" here.

## Pass criteria

1. Every Pro settings tab's assets load correctly on page load and on hash-only tab switches, for every hash including a freshly-added one.
2. Pro's sections/fields appear inside Free's existing sidebar groups at the documented priorities, never as duplicate standalone cards.
3. Every Pro sidebar entry (especially Documents) renders a real icon.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| A tab's CSS/JS never loads on direct hash navigation | asset enqueue still gated on `$_GET['section']` instead of the page hook suffix | `ProSettings.php` asset enqueue methods |
| Flickr import appears under Storage instead of Access | wrong `group` key passed to `mvs_settings_sections` | `ProSettings.php` section registration |
| Documents sidebar entry has no icon | icon name using Dashicon vocabulary instead of a Lucide name | `ProSettings.php` sidebar icon field |
