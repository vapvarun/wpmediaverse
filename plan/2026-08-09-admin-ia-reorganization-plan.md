# Plan: Admin IA reorganization (site-owner clarity)

**Date:** 2026-08-09  
**Type:** UX / information architecture (Free + Pro)  
**Status:** **PLAN ONLY — do not treat working-tree edits as shipped.** A prior agent session may have left partial WIP in Free/Pro admin files; **revert or finish deliberately before merge.**  
**Owner:** _unassigned — team pickup via Basecamp Bugs card_  
**Reviewer:** QA sign-off required before Ready for Testing  

Basecamp card: [Admin IA confusing for site owners — menu order, duplicate Tags, Settings regroup](https://app.basecamp.com/5798509/buckets/46336461/card_tables/cards/10184667104) (Bugs column)

---

## Problem

Site owners find the WPMediaVerse admin confusing. Menus and Settings grew feature-by-feature, so the sidebar reads like an internal feature list rather than owner jobs (content → moderation → insights → tools → settings).

This is not a single defect — it is an **IA / labeling / save-contract** cluster. Shipping piecemeal renames without the acceptance criteria below will leave the same confusion.

---

## Goals

1. One clear WPMediaVerse submenu order on Free-only and Free+Pro.
2. Exactly one **Tags** entry (no duplicate core taxonomy menu).
3. Settings sidebar grouped by owner job: General → Media → Safety → Access → Competitions.
4. Owner-facing chrome never says “Gamification” when the product says “Competitions”.
5. Stories enable control is visible and saves.
6. In-plugin Settings deep links always open the intended sidebar section.
7. Saving AI settings must not wipe Moderation options (and vice versa).

**Non-goals**

- Redesign of individual page UIs (list tables, competition CRUD).
- Merging Competitions top-level into WPMediaVerse (ops stay separate).
- Capability matrix rewrite (document only unless a concrete role bug is filed).

---

## Current state (audit snapshot)

### Menu (problems)

| ID | Sev | Finding |
|---|---|---|
| M-1 | P0 | Two Tags menus: custom `mvs-tags` + `edit-tags.php?taxonomy=mvs_tag` |
| M-2 | P1 | Free `reorder_submenu` incomplete; Pro order omits Documents / Tags / Stories / Integrations / Migration → items land unpredictably |
| M-3 | P1 | Naming drift: Media Moderation / Member Reports / Pro Reports tab |
| M-4 | P2 | “Import Migration” jargon |
| M-5 | P1 | Competitions config labeled “Gamification” in Settings while top-level is Competitions |

### Settings (problems)

| ID | Sev | Finding |
|---|---|---|
| S-1 | P0 | Stories settings section registered but missing from `section_ids` → toggle never renders |
| S-2 | P1 | “AI & Moderation” mixes provider credentials with community policy |
| S-3 | P1 | “Social” nav = DMs only |
| S-4 | P2 | Video + Connected Accounts buried under Advanced |
| S-5 | P1 | Deep links inconsistent (`#section-storage`, `?section=connectors`, hash `#storage`) |

### Key files

**Free**

- `includes/Core/Plugin.php` — top menu + `reorder_submenu`
- `includes/Admin/Settings/SettingsPage.php` — sidebar sections / groups
- `includes/Admin/Settings/SettingsRegistrar.php` — option groups / sections
- `includes/Admin/Settings/AiSettingsRegistrar.php`
- `includes/Taxonomies/MediaTag.php` — duplicate Tags menu source
- `includes/Admin/ModerationQueue.php`, `ReportsPage.php`, `IntegrationsPage.php`, `LogViewerPage.php`, `TagManagementPage.php`

**Pro**

- `includes/Core/Plugin.php` — `reorder_submenu` (wins at `admin_menu:999` when Pro active)
- `includes/Admin/ProSettings.php` — merges Pro sections into Free sidebar
- `includes/Admin/GamificationSettings.php` — Competitions / Stories settings
- `includes/Admin/MigrationPage.php`, `StoriesPage.php`, `QuotaPage.php`
- Deep-link sites: `CloudOpsManager.php`, `CompetitionsDashboard.php`, `ConnectorManager.php`

---

## Target IA

### WPMediaVerse submenu

```
Overview
── Content ──
All Media | Documents | Albums | Collections | Tags | Categories | Stories (Pro)
── Moderation ──
Moderation   (+ Reports as tab when Pro; Free shows Reports submenu when Pro absent)
── Insights ──
Stats (+ Analytics tab when Pro) | Quota & Credits (Pro)
── Tools ──
Integrations | Import (Pro) | Logs
── Config ──
Settings   (always last)
```

Setup stays URL-accessible / CSS-hidden (existing pattern).

### Competitions top-level (unchanged)

Dashboard | Challenges | Battles | Tournaments | Themes

### Settings sidebar

```
General
  General | Messaging          (label only; keep section key `social` for BC)

Media
  Display | Storage | Video (Pro)

Safety
  AI | Moderation              (separate option_groups / page_slugs)

Access & Integrations
  Permissions | Connected Accounts (Pro) | Webhooks

Competitions                   (group label MUST match top-level)
  Competitions (+ Stories card in section_ids)
```

---

## Implementation phases

### Phase 0 — Working tree hygiene (do first)

1. Diff Free + Pro against last known good / release branch.
2. If partial IA WIP exists from 2026-08-09 agent session: either **revert entirely** then implement this plan, or **finish against acceptance criteria** (no half-renames).
3. Note decision on the Basecamp card.

### Phase 1 — P0 fixes (same PR ok if small)

1. **Stories `section_ids`** — include `mvs_pro_stories` in `GamificationSettings::register_sidebar_section()`.
2. **Duplicate Tags** — `MediaTag` taxonomy: `show_in_menu => false` (keep `show_ui` so terms remain manageable via `TagManagementPage`).
3. Verify Categories taxonomy menu remains (only category admin surface).

### Phase 2 — Submenu order (Free + Pro)

1. Replace Free + Pro `reorder_submenu` with one explicit ordered slug list (Pro extends Free’s list with Pro-only slugs).
2. Labels:
   - Moderation queue → **Moderation**
   - Free reports → **Reports**
   - Migration → **Import**
3. Confirm Free-only (Reports visible) and Free+Pro (Reports as Moderation tab, not duplicate menu).

### Phase 3 — Settings sidebar regroup

1. Update `SettingsPage::get_registered_sections()` + `group_sections()` labels:
   - groups: `general`, `media`, `safety`, `access`, `gamification` (display label **Competitions**)
2. Split AI vs Moderation into two nav items with **separate** `option_group` / `page_slug` (`_ai` / `_moderation`). Move moderation `register_setting` / `add_settings_field` page args in `SettingsRegistrar`.
3. Rename Social label → **Messaging** (keep key `social`).
4. Pro: Video `group => media`; Connectors `group => access`.
5. Gamification group label → **Competitions**.

### Phase 4 — Deep links

Normalize all Settings links to hash = `data-section` id:

| Bad | Good |
|---|---|
| `#section-storage` | `#storage` |
| `?section=gamification` | `#gamification` |
| `?section=connectors` | `#connectors` |

Touch: `CloudOpsManager`, `CompetitionsDashboard`, `ProSettings` (`settingsUrl`), `ConnectorManager`, any Overview quick-links.

Optional hardening: teach `settings-nav.js` to accept `#section-X` as alias for `#X`.

### Phase 5 — QA / docs

1. Manual smoke (below).
2. Update docs that mention “AI & Moderation”, “Social”, “Import Migration”, “Gamification” settings group (`docs/website/…` as needed).
3. No release notes claim until browser-verified.

---

## Acceptance criteria

- [ ] Exactly one Tags menu under WPMediaVerse.
- [ ] Submenu order matches Target IA on Free-only and Free+Pro.
- [ ] Settings groups read: General → Media → Safety → Access & Integrations → Competitions.
- [ ] Stories enable toggle visible under Settings → Competitions; persists after save.
- [ ] Save on AI does not clear Moderation options (checkbox + legal URLs survive).
- [ ] Save on Moderation does not clear AI provider options.
- [ ] Cloud Ops / Compete dashboard / Connector OAuth return to the correct Settings section.
- [ ] No owner-facing “Gamification” string in admin chrome (group label, menu, empty states).
- [ ] Import menu label is **Import**; Moderation queue is **Moderation**; Free reports are **Reports**.

---

## Test plan (manual)

| # | Path | Expect |
|---|---|---|
| 1 | wp-admin → WPMediaVerse submenu | Order matches Target IA; one Tags |
| 2 | Settings → Messaging | DM fields only; label not “Social” |
| 3 | Settings → AI then Moderation | Separate nav; independent Save |
| 4 | Settings → Competitions | Stories checkbox present; enable → Stories menu still works |
| 5 | Pro Storage Cloud Ops action redirect | Lands on Storage section |
| 6 | Compete dashboard “configure” link | Lands on Competitions settings |
| 7 | Free-only site | Reports submenu present; no Pro-only items |
| 8 | Role with `moderate_mvs_media` only | Sees Moderation/Reports as designed (document actual) |

Automated: extend Settings contract tests if option_group split is added (no duplicate `register_setting`, moderation options on `_moderation` group).

---

## Risks

| Risk | Mitigation |
|---|---|
| Splitting AI/Moderation option groups without moving `register_setting` | Forms share a group → WP options.php can wipe missing fields |
| Hiding taxonomy Tags menu breaks someone bookmarking `edit-tags.php` | Keep URL working (`show_ui`); only hide menu; redirect optional |
| Partial WIP already in tree | Phase 0 hygiene |
| i18n string churn | Expected; no string freezes called out for this IA pass |

---

## Suggested PR split

1. **PR A** — Phase 0–1 (P0 + hygiene)  
2. **PR B** — Phase 2 menu order + labels  
3. **PR C** — Phase 3–4 Settings IA + deep links + docs  

Ship Free + Pro paired if Pro reorder / GamificationSettings / deep links change.
