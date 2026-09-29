---
journey: template-overrides-pro
plugin: wpmediaverse-pro
priority: normal
roles: [administrator]
covers: [MV-PTL-001, MV-PTL-002, MV-PTL-003, MV-PTL-004, MV-PTL-005, MV-PTL-006, MV-PTL-007, MV-PTL-008, MV-PTL-009, MV-PTL-010, MV-PTL-011, MV-PTL-012, MV-PTL-013, template-overrides, template-versioning]
prerequisites:
  - "Both plugins active"
  - "A test theme with wpmediaverse/templates/ override capability"
  - "Battles, Challenges, Tournaments, Boosts, Streaks, Connectors, and Stories each enabled at least once during the run"
estimated_runtime_minutes: 10
---

# Compact bundle: every Pro template is the same five-line override check, once per template

**Why this journey exists**: every entry below is the identical mechanism check (`templates/README.md`'s schema, Coding Rule 24) repeated per template — a theme copy under `<active-theme>/wpmediaverse/templates/...` (mirroring the exact relative path) is used instead of the plugin's, the plugin file is never touched, and every overridable template carries a bumped `@version`. Pro registers its template root via the `mvs_template_roots` filter, resolved through Free's `TemplateLoader::locate()` / `theme_or_plugin()`. Each block below is one line action + one line assertion, per `MV-PTL-*` catalog ID — no full narrative needed since the mechanism is identical throughout.

## Setup

- Site: `$SITE_URL`; admin `?autologin=admin`; test theme active.

## Steps (one block per ID)

### 1. MV-PTL-001 — Compete hub page + body
- **Action**: copy `templates/compete-hub-body.php` to the theme path; edit a visible string; reload `/compete/`.
- **Expect**: theme copy renders; plugin file untouched; both files carry an `@version` header.

### 2. MV-PTL-002 — Battles page + body
- **Action**: copy `templates/battles-body.php`; reorder a tab label; reload `/media/battles/`.
- **Expect**: theme copy renders; Interactivity API `data-wp-*` bindings for vote/accept/submit still work if preserved by the override (the plugin does not validate override markup — a dropped binding degrades that control only, not a fatal).

### 3. MV-PTL-003 — Challenges page + body
- **Action**: copy `templates/challenges-body.php`; reload `/media/challenges/`.
- **Expect**: theme copy renders; countdown/vote/submit continue to work if the override preserves the relevant hooks.

### 4. MV-PTL-004 — Tournaments page + body
- **Action**: copy `templates/tournaments-body.php`; reload `/media/tournaments/`.
- **Expect**: theme copy renders the bracket UI; horizontal-scroll/fade-edge behaviour depends on the CSS classes the override keeps (on the theme developer, not re-injected by the plugin).

### 5. MV-PTL-005 — Boost modal partial
- **Action**: copy `templates/partials/boost-modal.php`; adjust the slider markup; open the Boost modal.
- **Expect**: theme copy renders; `role="dialog"`/`aria-modal`/focus-trap hooks must be preserved for the accessibility contract (`MV-BST-001`) to keep working.

### 6. MV-PTL-006 — Streak widget partial
- **Action**: copy `templates/partials/streak-widget.php`; adjust milestone display; reload `/my-media/`.
- **Expect**: theme copy renders; the "Buy Freeze" button's data attributes must be preserved for `MV-BST-007`'s purchase flow.

### 7. MV-PTL-007 — Connected accounts panel
- **Action**: copy `templates/partials/dashboard-connectors-panel.php`; adjust Connect/auto-export toggle markup; reload the dashboard's Connected Accounts tab.
- **Expect**: theme copy renders; Connect button and auto-export toggle data attributes must survive for `MV-IMP-007`/`MV-IMP-009` to keep working.

### 8. MV-PTL-008 — External source badge partial
- **Action**: copy `templates/partials/external-source-badge.php`; adjust badge copy; view an imported item's single page.
- **Expect**: theme copy renders. A copy made before 2.6.0 (still printing a "Sync Now" button) renders WITHOUT it, because `$is_connected` is now always false server-side.

### 9. MV-PTL-009 — Connector import modal (the frontend exception)
- **Action**: copy `templates/admin/connector-import-modal.php` into the theme path exactly as any frontend template, despite the `templates/admin/` path. Adjust the modal markup; open "Import from Flickr" on the dashboard.
- **Expect**: theme copy renders; dialog role/aria-modal/focus-trap and browsing markup must be preserved. This is the ONE explicit exception to "`templates/admin/` is never theme-overridable" (Coding Rule 11) — confirm `bin/template-version-check.sh --include templates/admin/connector-import-modal.php` genuinely includes this file (it would otherwise be excluded by the blanket admin-path rule).

### 10. MV-PTL-010 — Feed card partial (Instagram-only scope)
- **Action**: copy `templates/partials/feed-card.php`; adjust card markup; reload Explore under Instagram layout, then switch to Pinterest/Flickr/Dribbble.
- **Expect**: theme copy renders for Instagram ONLY. `feed-card.php` is consumed exclusively by the Instagram layout's `feed-body.php`/`profile.php` — the other three layouts render their own inline card markup and are UNAFFECTED by this override. Document this scope; a developer expecting one override to reskin every layout would be surprised.

### 11. MV-PTL-011 — Stories bar partial
- **Action**: copy `templates/partials/stories-bar.php`; adjust avatar ring/story-tile markup; reload a page showing the stories bar.
- **Expect**: theme copy renders; the story viewer's dialog/focus-trap/keyboard behaviour (`MV-STY-008`) depends on markup hooks the override must preserve.

### 12. MV-PTL-012 — Per-layout feed-body override, isolated per layout
- **Action**: copy ONE layout's `templates/layouts/<name>/feed-body.php` (Instagram/Flickr/Pinterest/Dribbble) into the mirrored theme path; adjust markup; reload Explore under that layout, then switch to a different layout.
- **Expect**: only the overridden layout's markup changes; the other three continue using the plugin's own templates — overrides are strictly per-layout. Search/sort/tag-chip parity (`MV-LAY-010/011/012`) must survive the override just as much as the default.

### 13. MV-PTL-013 — `@version` bump enforcement (release-process gate, not member-facing)
- **Action**: edit any template above without bumping its `@version` header; run `bash bin/template-version-check.sh` (local-CI stage 1.9). Then bump the version and re-run.
- **Expect**: the unbumped edit fails the gate; the bumped edit passes. The check compares against actual content diff since the last tag, not merely "does this file exist in the templates list" — a template with no real change since authoring should not be forced into a needless bump.

## Pass criteria

1. Every one of the 12 template/partial overrides (PTL-001 through 012) renders the theme's copy, leaves the plugin file untouched, and preserves the specific interactive markup hook named for that template where one is called out.
2. `connector-import-modal.php` is confirmed genuinely overridable and included in the version-check gate despite its `templates/admin/` path.
3. `feed-card.php` and the per-layout `feed-body.php` overrides are each confirmed scoped to exactly the layout(s) they claim — no cross-layout bleed.
4. The `@version` gate fails an unbumped real change and passes a bumped one, without forcing a bump on an unchanged file.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Theme copy never used, plugin file still renders | `TemplateLoader::locate()`/`theme_or_plugin()` not checking Pro's registered root | `mvs_template_roots` filter registration |
| Overriding `feed-card.php` affects Pinterest/Flickr/Dribbble too | those layouts were changed to consume the shared partial without updating this journey's scope note | layout `feed-body.php` files |
| `connector-import-modal.php` excluded from the version-check gate | `--include` flag dropped from the local-CI invocation | `bin/local-ci.sh` (Pro) stage 1.9 |
| `@version` gate passes an unbumped real change | version-check diff comparison broken | `bin/template-version-check.sh` |
