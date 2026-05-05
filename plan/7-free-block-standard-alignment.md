# Phase 7 — Free Block Standard Alignment (Wbcom block standard)

> **Why this phase exists.** Free has 12 block.json files in `src/blocks/` (8 registered as Gutenberg blocks + 4 view-only Interactivity stores). They predate the Wbcom block standard documented in `~/.claude/skills/wp-block-development/references/block-quality-standard.md` with wbcom-essential v4.5.0 `plugins/gutenberg/src/shared/` as the canonical reference implementation. Pro's parallel work landed in 1.2.0 Phase 3e and now serves as a reference for what this phase mirrors on the Free side. Once Phase 7 ships, Free + Pro + wbcom-essential customers see the same block controls (Spacing / Border / Shadow / Visibility / Typography) regardless of which plugin they're using.
>
> **The Pro Phase 3e work is the template.** Phase 7 ports the same `src/shared/` infrastructure, the same `MVS_CSS` PHP class, the same `StandardAttributes::inject()` `block_type_metadata` filter, and the same edit.js retrofit pattern (`useUniqueId`, `StandardInspectorPanels`, `BlockPreviewCard` for IA-driven blocks). Free's prefix is already `mvs` so the prefix-swap step is a no-op — just copy the files.

---

## Inventory

### Registered Gutenberg blocks (8)

These have `block.json` AND are listed in `BlockRegistrar::BLOCKS` so they appear in the inserter:

| Slug | Existing pattern | Renderer |
|---|---|---|
| `mvs/album-viewer` | dynamic, render.php | `Services/AlbumService` |
| `mvs/explore-feed` | dynamic, render.php | inline grid query |
| `mvs/lock-overlay` | dynamic, render.php | access-rules surface |
| `mvs/media-grid` | dynamic, render.php | `MediaRepository` query |
| `mvs/media-player` | dynamic, render.php | inline player markup |
| `mvs/media-stats` | dynamic, render.php | `Services/StatsService` |
| `mvs/media-upload` | dynamic, render.php | `Services/UploadService` |
| `mvs/story-viewer` | dynamic, render.php | `Services/StoryService` |

### View-only Interactivity stores (4)

These have `block.json` (so the editor sees them) but are NOT in `BlockRegistrar::BLOCKS` — they ship view.js stores that hydrate server-rendered markup elsewhere on the page:

| Slug | Purpose |
|---|---|
| `mvs/dashboard-view` | Dashboard page Interactivity store |
| `mvs/explore-view` | Explore page IA store |
| `mvs/media-social` | Reaction / comment / favourite IA store (mounted by other blocks) |
| `mvs/shared-ui` | Lightbox + report + share modals — global UI state |

These don't get the standard inspector panels (they have no editor surface), but they DO benefit from the schema injection (`StandardAttributes::inject` filter is name-prefix-gated at registration time, so view-only stores can opt out by virtue of not being in `BLOCKS`).

---

## What changes

### Add (mirrors Pro Phase 3e Step 1)

| File | Action |
|---|---|
| `src/shared/` (new tree) | Copy from Pro's `wpmediaverse-pro/src/shared/` (which itself was ported from wbcom-essential v4.5.0). All 17 files — same `mvs` prefix, no rename needed. Contains `design-tokens.css`, `base.css`, `theme-isolation.css`, 7 named inspector components, 3 hooks (`useUniqueId`, `useResponsiveValue`, `useDeviceType`), `utils/{attributes,css}.js`, `StandardInspectorPanels.js` wrapper, plus the `BlockPreviewCard` from `src/blocks/shared/` if the latter is also ported. |
| `includes/Blocks/MVS_CSS.php` (new) | Port from Pro. Same file content; namespace becomes `WPMediaVerse\Blocks` (drop the "Pro"). Class name stays `MVS_CSS` since it's plugin-scoped already. |
| `includes/Blocks/StandardAttributes.php` (new) | Port from Pro. Namespace `WPMediaVerse\Blocks`. Same SCHEMA / SPACING / TYPOGRAPHY / SHADOW / BORDER / VISIBILITY / UNIQUE_ID groups. The `inject()` filter callback name-prefix-gates on `mvs/` (matches every Free block) but excludes the 4 view-only stores via an opt-out list — see `BlockRegistrar`'s `viewOnlyStores` for the canonical reference. |
| `src/blocks/shared/block-preview-card.{js,css}` (new) | Same component Pro ships. Used by the Free blocks whose runtime markup depends on the IA store (most of them). |

### Modify

| File | Action |
|---|---|
| `includes/Blocks/BlockRegistrar.php` | Wire the `block_type_metadata` filter to `StandardAttributes::inject` and the `wp_footer` hook to `MVS_CSS::output`. Mirrors Pro's `BlockRegistrar::init` after Phase 3e Step 2. |
| `src/blocks/<slug>/edit.js` (×8 registered blocks) | Same retrofit Pro Phase 3e Step 3 did: drop any inline padding/margin number controls, mount `<StandardInspectorPanels>` from `'../../shared/components'`, call `useUniqueId( clientId, attributes.uniqueId, setAttributes )`, replace `<ServerSideRender>` with `<BlockPreviewCard>` for blocks whose render.php emits `data-wp-bind--*` (most do). Block-specific controls (e.g. media-grid's column count, media-player's source URL) stay in their own `PanelBody` mounted BEFORE `<StandardInspectorPanels>`. |
| `src/blocks/<slug>/render.php` (×8 registered blocks) | Wrapper migration: `$mvs_block_uid = ! empty( $attributes['uniqueId'] ) ? $attributes['uniqueId'] : ''; \WPMediaVerse\Blocks\MVS_CSS::add( $mvs_block_uid, $attributes );` then `get_block_wrapper_attributes()` with `mvs-block-{uniqueId}` + `visibility_classes()` in the class. |
| `src/blocks/<slug>/block.json` (×8) | + `"editorStyle": "file:./index.css"` if missing (Phase 3d-polish learning). The 4 view-only blocks already have this from prior work. |
| Each block's `block.json` viewScript field | Confirm / migrate to `viewScriptModule` (most are already migrated per `grep -l "viewScriptModule" src/blocks/*/block.json` returning 10/12). The remaining 2 — likely `media-stats` and `album-viewer` — get migrated in this phase. |
| `webpack.config.js` | Update the `viewOnlyStores` list comment to point at this plan file. No functional change. |
| `package.json` | + `@wordpress/icons` devDep (peer of the shared `SpacingControl` etc.). |

### Out of scope (deferred to 1.2.1)

- `Block_CSS::render` per-instance — Phase 7 uses `MVS_CSS::add()`-in-render-php instead, same effect.
- Templates moved to `templates/blocks/<name>/` for theme overrides (plan/1.2.0.md Phase 7 line 260) — biggest open-ended task; defer per the existing plan.
- size-limit + Lighthouse CI gates — infra work; defer.
- Strip jQuery audit — Phase 7 retrofit will catch any inadvertent jQuery; an explicit audit is overkill now.

---

## Execution order

Same step shape as Phase 3e — atomic, CI-green commits.

### Step 1 — Port `src/shared/` from Pro
**Files added:** `src/shared/` (~18 files including BlockPreviewCard).
**Files modified:** none — pure addition.
**CI:** Pro CI green; PHP lint on `MVS_CSS.php` + `StandardAttributes.php` ports. Free blocks unchanged this step.
**Commit:** `Phase 7 step 1: port src/shared/ from Pro Phase 3e`.

### Step 2 — `MVS_CSS` + `StandardAttributes` PHP, wired into `BlockRegistrar`
**Files added:** `includes/Blocks/MVS_CSS.php`, `includes/Blocks/StandardAttributes.php`.
**Files modified:** `includes/Blocks/BlockRegistrar.php` (wire filter + footer hook), each registered block's `block.json` (add `editorStyle` if missing), each registered block's `render.php` (migrate wrapper to `MVS_CSS::add` pattern + `mvs-block-{uniqueId}` class). 4 view-only block.json files left untouched.
**CI:** Free CI green; PHPStan baseline regenerated.
**Commit:** `Phase 7 step 2: schema + MVS_CSS + render.php migration`.

### Step 3 — edit.js retrofit (×8 registered blocks)
**Files modified:** each registered block's `edit.js` × 8. Each gains `import { StandardInspectorPanels } from '../../shared/components'` + `import { useUniqueId } from '../../shared/hooks'` + `<BlockPreviewCard>` if the block's runtime depends on the IA store.
**Files added:** `src/blocks/shared/block-preview-card.{js,css}` if not already ported in Step 1.
**CI:** Free CI green; webpack build successful.
**Commit:** `Phase 7 step 3: retrofit 8 edit.js to shared inspector components`.

### Step 4 — Cleanup + docs
**Files modified:** `plan/1.2.0.md` (flip Phase 7 checkboxes), Free `CLAUDE.md` (Recent Changes entry).
**Commit:** `Phase 7 step 4: cleanup + docs`.

---

## Acceptance criteria

A 1.2.0 Free release ships only when all of the below are true:

- [ ] `src/shared/` mirrors Pro's `wpmediaverse-pro/src/shared/` (functionally identical — same prefix `mvs`).
- [ ] Every registered block's wrapper carries `wp-block-mvs-{slug} mvs-block-{uniqueId}` class names.
- [ ] `MVS_CSS::add()` emits per-instance `<style>` in `wp_footer`; verified in DevTools on a real frontend page render.
- [ ] All 4 standard inspector panels (Spacing, Border, Shadow, Visibility) render via `StandardInspectorPanels` in every registered block's editor sidebar.
- [ ] Padding/margin/border-radius store as per-side objects, shadow includes `shadowSpread`, visibility uses `hideOnDesktop/Tablet/Mobile`.
- [ ] No `<ServerSideRender>` in any of the 8 registered block edit.js files.
- [ ] Free CI gate green after each step.
- [ ] Mobile (390px) viewport renders cleanly for `mvs/media-grid`, `mvs/explore-feed`, `mvs/media-stats` (3 representative blocks). Other blocks deferred to 1.2.0 RC walkthrough.
- [ ] No customer attribute-shape break: existing 1.1.x posts with old block markup either still parse OR get a `block-deprecation` migration path. Most likely no break since Free's existing blocks didn't use the Phase 3d-shape attributes anyway (those were Pro's).

---

## Why this is worth doing now

- **Cross-plugin consistency.** Free + Pro + wbcom-essential customers see identical block controls. No "why does the wbcom-essential Login Form's Spacing panel look different from WPMediaVerse's Media Grid?" tickets.
- **Faster Pro Phase 3 work going forward.** Any future block Pro adds inherits the same shared components — Pro already imports from its own `src/shared/`. Free now can too.
- **Skill-driven self-correction.** The skill update committed earlier today (`dfbfbe4` in `~/claude-backup`) makes the Wbcom standard the routed-to default for ALL Wbcom plugin block work. Shipping Free 1.2.0 in the OLD shape would invite the same "this skill says one thing, the codebase does another" drift Phase 3e was triggered by.

---

## Estimated effort

- **Step 1** (port src/shared/): 15-20 min. Mostly mechanical copy.
- **Step 2** (schema + render.php × 8): 60-90 min. Schema is straightforward; the 8 render.php files need careful inspection because each block has different existing wrapper logic.
- **Step 3** (edit.js retrofit × 8): 60-90 min. Each block ~5-7 min including verification.
- **Step 4** (cleanup): 15 min.

**Total:** ~2.5–3.5 hours, four atomic commits, all CI-green.
