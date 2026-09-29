---
journey: setup-wizard-flow
plugin: wpmediaverse
priority: high
roles: [administrator]
covers: [MV-WIZ-001, MV-WIZ-002, MV-WIZ-003, MV-WIZ-004, setup-wizard-flow]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "Auto-login mu-plugin available (?autologin=1)"
  - "WP-CLI available to reset activation state between steps"
estimated_runtime_minutes: 6
---

# First activation redirects into a 3-step wizard that ends at a working dashboard

**Why this journey exists**: the wizard is the very first thing a new site owner sees, and it is a single connected flow (redirect → Welcome → Display → Done) rather than four independent screens. A step that renders correctly in isolation but loses the admin's chosen values by the time they reach Done, or a redirect that fires on the wrong occasion (bulk-activate, a reactivation of an already-configured site), is exactly the kind of bug this journey is built to catch by walking the whole path once, not each screen separately.

## Setup

- Site: `$SITE_URL`
- Admin: `?autologin=1`
- Wizard page: `admin.php?page=mvs-setup` (hidden from the sidebar via CSS, reachable directly or via the activation redirect). Registered under the `wpmediaverse` parent menu with capability `mvs_settings_screen`.
- Reset activation state before the walkthrough: `wp option delete mvs_setup_complete` (forces the "first time" branch).

## Steps

### 1. First-activation redirect fires within its window (MV-WIZ-001)
- **Action**: `wp option delete mvs_setup_complete`, then `wp plugin deactivate wpmediaverse && wp plugin activate wpmediaverse`. Within 30 seconds, load any wp-admin page as the admin.
- **Expect**: the request redirects to `admin.php?page=mvs-setup` (the wizard), because `Activator::activate()` set the `mvs_activation_redirect` transient (30s TTL) and `mvs_setup_complete` is not yet set. The transient is consumed on this one read — reloading wp-admin again does NOT redirect a second time.
- **Edge case — bulk activate**: repeat via WordPress's own bulk-activate flow (`?activate-multi` present in the URL) or via WP-CLI (`defined('WP_CLI')`) — confirm NEITHER redirects; both are explicitly excluded in `Plugin::maybe_redirect_after_activation()`.
- **Edge case — already-configured reactivation**: with `mvs_setup_complete` already set to true from a prior run of this journey, deactivate + reactivate again within the window. **Expect**: the redirect now lands on the Overview page (`admin.php?page=wpmediaverse`), NOT the wizard — `maybe_redirect_after_activation()` branches on `mvs_setup_complete`, sending completed sites to Overview and only incomplete ones to the wizard. (This differs from the catalog's stated edge case, which describes the wizard reappearing on every reactivation regardless of completion state — record this discrepancy if reproduced, don't paper over it by asserting the catalog's wording instead of the code's actual branch.)

### 2. Welcome step (MV-WIZ-002)
- **Action**: Load `admin.php?page=mvs-setup&step=welcome` (or land here via the redirect). Also separately load `admin.php?page=mvs-setup&step=pages` (an old bookmark using the pre-2.6.0 step name).
- **Expect**: the progress indicator shows 3 steps — Welcome / Display / Done — with Welcome marked `active` (step 1 of 3; the old "Pages" step was removed in 2.6.0 and folded into Display). The feature list shows exactly 4 bullets: albums/collections, social features (reactions/comments/favorites/follows), AI-powered moderation and privacy controls, and optional BuddyPress integration. Two calls to action are both visible: "Let's Get Started" (primary, continues to `step=display`) and "Skip setup" (a plain link to `admin.php?page=wpmediaverse`, not buried or styled as disabled). The old `step=pages` URL normalizes to the Display step, not an error and not Welcome.

### 3. Display step (MV-WIZ-003)
- **Action**: From Welcome, click "Let's Get Started". On the Display step, change "Items Per Page" to `24` and "Default Layout" to "List - one row per item" (`mvs_thumbnail_style=list`). Click "Continue".
- **Expect**: the form POSTs `mvs_wizard_step=display` with a nonce (`mvs_setup_wizard`/`mvs_wizard_nonce`), handled by `handle_wizard_save()` → `save_display()`, which writes `mvs_items_per_page` and `mvs_thumbnail_style` directly via `update_option()`. After the redirect to `step=done`, separately open `admin.php?page=mvs-settings-display` (the real Display settings tab) and confirm "Items Per Page" reads `24` and "Layout" reads "List" — the wizard and the real settings screen must never disagree about the stored value. Progress indicator now shows Welcome `completed`, Display `active`.
- **Edge case — forged/incomplete POST**: submit a POST to the wizard's admin-post handler with `mvs_wizard_step` absent entirely. **Expect**: `handle_wizard_save()` returns early on the very first check — no option is touched, no redirect happens.

### 4. Done step (MV-WIZ-004)
- **Action**: On reaching Done (after step 3's redirect), confirm the confirmation copy and the two conditional links, then click "Go to Overview". Separately, bookmark and revisit `admin.php?page=mvs-setup&step=done` directly afterward.
- **Expect**: heading reads "Your Media Hub is Ready!"; "Visit Explore Page" and "My Dashboard" links each appear ONLY if `mvs_page_explore` / `mvs_page_dashboard` resolve to a non-zero page id (per MV-WIZ-005's activation-created pages) — on a site where those exist, both links appear and each targets that page's real permalink. The primary "Go to Overview" button POSTs `mvs_wizard_step=done`, which sets `mvs_setup_complete=true` and redirects to `admin.php?page=wpmediaverse`. Revisiting the bookmarked `step=done` URL later renders the same confirmation screen WITHOUT re-running any save logic (GET requests never hit `handle_wizard_save()`, which only responds to POST).

## Pass criteria

ALL hold:
1. The activation redirect fires once, within 30 seconds, only for a human admin request, and never for bulk-activate, AJAX, or WP-CLI.
2. The wizard shows exactly 3 progress steps (Welcome/Display/Done) with the correct one marked active/completed at each stage; the legacy `step=pages` URL lands on Display.
3. "Skip setup" is a genuine, reachable escape hatch from Welcome at every stage of this walkthrough.
4. Values chosen on the Display step are readable, unchanged, on the real Settings > Display screen afterward.
5. The Done step's conditional links only render for pages that actually exist, and its primary action lands the admin at Overview with `mvs_setup_complete` now true.
6. A forged POST missing `mvs_wizard_step` changes no option state.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Redirect fires on bulk-activate or via WP-CLI | Missing/broken exclusion check | `includes/Core/Plugin.php::maybe_redirect_after_activation()` |
| Redirect never fires on genuine first activation | Transient not set, or set with 0 TTL | `includes/Core/Activator.php::activate()` |
| Reactivating a completed site still shows the wizard (or vice versa) | Branch on `mvs_setup_complete` inverted/missing | `includes/Core/Plugin.php::maybe_redirect_after_activation()` |
| `step=pages` errors or lands on Welcome | Legacy-step normalization removed | `includes/Admin/SetupWizard.php::render_wizard()` |
| Display step's saved value disagrees with the real Settings screen | Different option keys used by wizard vs `GeneralSettingsRegistrar`/`SettingsRegistrar` | `includes/Admin/SetupWizard.php::save_display()`, `includes/Admin/Settings/SettingsRegistrar.php` |
| Done step shows a link to a page that doesn't exist / dead link | Conditional check on page id removed | `includes/Admin/SetupWizard.php::render_step_done()` |
| Forged POST still saves a value | `mvs_wizard_step` presence check or nonce check bypassed | `includes/Admin/SetupWizard.php::handle_wizard_save()` |
