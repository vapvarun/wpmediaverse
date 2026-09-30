---
journey: template-overrides
plugin: wpmediaverse
priority: normal
roles: [administrator]
covers: [MV-TPL-001, MV-TPL-002, MV-TPL-003, template-overrides]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "A child theme (or ability to add a wpmediaverse/ folder to the active theme)"
  - "MediaVerse Pro active with a non-default layout (e.g. Pinterest) for MV-TPL-003"
  - "Auto-login mu-plugin available (?autologin=1)"
estimated_runtime_minutes: 8
---

# A theme copy always wins, and a stale one is caught by Site Health, not silently

**Why this journey exists**: `TemplateLoader::locate()` is a `locate_template()`-style lookup — theme first, plugin second, then Pro's own layout templates via the `mvs_locate_template` filter — but that precedence is invisible until someone actually tests it with a real theme override. Coding Rule 24 exists because the one time this went wrong (a theme's stale Explore copy silently kept reading `s` instead of `q` for search — Basecamp 10344452624), nothing on the frontend looked broken. This journey proves the override mechanism itself (MV-TPL-001), the staleness signal that catches exactly that class of bug (MV-TPL-002), and that switching Pro layouts never silently discards an owner's customisation (MV-TPL-003).

## Setup

- Site: `$SITE_URL`
- Admin: `?autologin=1`
- Active theme with a `wpmediaverse/` folder capability (any theme; `TemplateLoader::THEME_DIR` = `wpmediaverse`).
- A template to override, e.g. `templates/collection.php`.
- Pro active with a non-grid layout (e.g. Pinterest) for step 3, and a template Pro replaces for that layout, e.g. `explore.php`.

## Steps

### 1. Theme override mechanism — byte-identical copy renders identically (MV-TPL-001)
- **Action**: Copy `templates/collection.php` unmodified into `<active-theme>/wpmediaverse/collection.php`. Load a collection page on the frontend and compare against the pre-copy render.
- **Expect**: no visible difference — `locate_template()` finds the theme's copy first (it is now what loads, confirmed by `TemplateLoader::locate()`'s own precedence: `locate_template()` against the theme's `wpmediaverse/` folder, falling back to the plugin's `templates/` only if nothing is found there) and an unmodified copy renders byte-for-byte the same as the plugin's own file.

### 2. Theme override actually takes effect — edit it (MV-TPL-001)
- **Action**: Edit the theme's copy (e.g. change a heading string) and reload the same collection page.
- **Expect**: the edited heading shows — proof the theme's file is genuinely what's being rendered, not a cached/ignored copy.

### 3. Staleness detection fires for a genuinely outdated copy (MV-TPL-002)
- **Action**: Revert the theme's `collection.php` copy to a version with no `@version` header (pre-2.6.0 style) or an older `@version` string than the plugin's current file. Reload Tools > Site Health (MV-HLT-005).
- **Expect**: Site Health's "MediaVerse Template Overrides" test flags this SPECIFIC file by name — either "your copy has no version (made before 2.6.0), current is %s" or "your copy is version %1$s, current is %2$s" — never a vague "some templates are outdated." Now update the theme's copy to match the current plugin version's markup/variables and bump (or add) its `@version` header to match. Reload Site Health.
- **Expect**: the test clears back to green.

### 4. A copy that is the SAME version or NEWER is never flagged (MV-TPL-002 edge case)
- **Action**: Set the theme's copy's `@version` to a string strictly HIGHER than the plugin's current template version (simulating a theme developer who got ahead of a plugin downgrade). Reload Site Health.
- **Expect**: the comparison only flags a copy whose version is strictly LOWER than the plugin's current version — same-or-newer is never flagged, confirmed correct behavior, not a false negative to chase.

### 5. A theme override still wins over a Pro layout template (MV-TPL-003)
- **Action**: With Pro active and the layout set to Pinterest (`admin.php?page=mvs-settings-display`, Layout select), copy the template Pro replaces for that layout (e.g. `explore.php`) into the theme's `wpmediaverse/` folder with a visible marker added (e.g. an HTML comment or a distinct heading). Load Explore.
- **Expect**: the theme copy renders — the marker is visible. This is the SAME precedence as MV-TPL-001: `locate_template()` finds the theme's file before the `mvs_locate_template` filter (where Pro substitutes its own layout template) ever runs, so a theme override is never silently replaced by switching layouts. Now remove the theme copy and reload.
- **Expect**: the Pro layout template renders instead (no marker), proving the fallback correctly engages once the override is gone.

### 6. Site Health's `mvs_template_roots` filter locates versions, not files (MV-TPL-003 edge case)
- **Action**: With the theme copy from step 5 removed, reload Site Health.
- **Expect**: Site Health (MV-HLT-005) walks Pro's registered template root via the `mvs_template_roots` filter purely to compare VERSIONS of any theme copies that exist for Pro's templates — it plays no part in deciding which file actually loads on the frontend (that's `mvs_locate_template`, exercised in step 5). Confirm the two mechanisms stay decoupled: a stale Pro-template version shows in Site Health even if no override is currently active for it.

## Pass criteria

ALL hold:
1. A theme's unmodified copy of any overridable template renders identically to the plugin's own file; an edited copy's changes are visible.
2. A copy older than the plugin's current `@version` is flagged BY NAME on Site Health; same-or-newer is never flagged.
3. Fixing the copy (matching markup + bumping `@version`) clears the Site Health warning.
4. A theme override wins over a Pro layout template for the same file, at every layout switch — never silently replaced.
5. Removing the theme override correctly falls back to the Pro layout template.
6. `mvs_template_roots` (version comparison) and `mvs_locate_template` (which file loads) stay functionally independent — neither one drives the other.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Theme copy never takes effect (plugin file always renders) | `locate_template()` lookup path/constant wrong | `includes/Core/TemplateLoader.php::locate()`, `THEME_DIR` |
| Stale copy never flagged on Site Health | Version comparison logic inverted or `@version` header not parsed | `includes/Services/HealthCheckService.php::test_template_overrides()` |
| Same-or-newer copy incorrectly flagged | Comparison uses `!=` instead of a strict "current is lower" check | `includes/Services/HealthCheckService.php::test_template_overrides()` |
| Pro layout template overrides a theme's own copy | `mvs_locate_template` filter substitutes a file even when `$template` was already found by `locate_template()` | Pro's `mvs_locate_template` filter callback (registers its layout templates) |
| Removing the theme override doesn't fall back to Pro's layout template | Pro's filter callback path/slug lookup wrong for the active layout | Pro's template-registration class (hooks `mvs_template_roots` + `mvs_locate_template`) |
| A template change ships without bumping `@version` | Coding Rule 24 / `bin/template-version-check.sh` gate not enforced on this file | `bin/template-version-check.sh`, the changed template's docblock |
