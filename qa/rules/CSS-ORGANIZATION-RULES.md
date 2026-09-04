# CSS Organization Rules

> **Rule (standing):** Every CSS rule in this plugin lives in exactly one file whose name tells you where it applies. Rules leak out of their intended scope only when someone adds a rule to the wrong file; once leaked, they accumulate until a customer screenshot forces a cleanup.
>
> **Why this rule exists:** In 2026-04 we migrated ~2500 lines of BP-specific CSS out of `frontend.css` and into `bp-integration.css` where it belonged. The migration took 7 commits and fixed three user-visible bugs that had been accumulating silently: attach-media button/privacy select misalignment, single-upload preview stretching full-width, Reign's group-cover image blown up to 600px inside join-group activities. All three root-caused to "BP rule landed in frontend.css and drifted from the rest of the BP styling."

---

## 1. File ownership matrix

Every CSS file in `assets/css/` has a single scope. If a rule doesn't match the scope of any file, that's a sign the plugin needs a new file — not that the rule goes in the nearest one.

| File | Scope | What goes here | What does NOT go here |
|------|-------|----------------|------------------------|
| `frontend.css` | Generic plugin frontend | Design tokens (`:root`), media grid, single-media template, album template, dashboard, lightbox, toasts, modals, shortcodes, auth gate, 404, empty states, mobile touch targets | **Anything BP-specific** (→ `bp-integration.css`), **anything wp-admin-specific** (→ `admin.css`), **block-specific rules** (→ `src/blocks/*/style.css`), **messaging-specific rules** (→ `messaging.css`) |
| `bp-integration.css` | BuddyPress surfaces only | Everything scoped under `#buddypress` or `.activity-list`: profile media tab, group media tab, activity composer, activity stream, sub-tabs, dropzone, upload preview, theme-compat for Reign / BuddyBoss / BuddyX | Generic plugin UI (→ `frontend.css`), wp-admin rules (→ `admin.css`) |
| `admin.css` | wp-admin only | Settings page, Overview / Stats / Logs / Moderation / Setup Wizard pages, list tables, metaboxes, admin modals | Frontend rules. `admin.css` does NOT load on the frontend, so referencing `--mvs-*` tokens from `frontend.css` will silently fall back to defaults. Tokens needed here must be duplicated or extracted to a shared `vars-only.css` (not yet extracted — deferred). |
| `messaging.css` | DM / inbox surfaces only | `.mvs-dm-*`, `.mvs-conversation-*`, `.mvs-message-*` | Notification bell (→ `frontend.css` unless BP-scoped), generic modals |
| `shared-ui-frame.css` | The shared lightbox/overlay frame used by both frontend and BP (renamed from `shared-ui-shell.css` in 1.2.1 — customer WAFs flag the "shell" token) | Lightbox social sidebar variables, overlay utility classes shared across surfaces | Anything surface-specific |
| `src/blocks/*/style.css` | That specific Gutenberg block only | Block's own wrapper styles, editor parity | Rules intended for anywhere else the plugin renders (those go in `frontend.css`) |

### Decision tree

When adding a new CSS rule, answer in this order:

1. **Does the selector contain `#buddypress`, `.buddypress-wrap`, `.activity-*`, `#whats-new*`, or a `.mvs-bp-*` / `.mvs-activity-*` class?** → `bp-integration.css`.
2. **Is it a selector that only matches inside wp-admin?** → `admin.css`.
3. **Does it style markup emitted by a single Gutenberg block's `render.php` or `view.js`?** → that block's `style.css`.
4. **Does it style messaging (DM) markup?** → `messaging.css`.
5. **Everything else** → `frontend.css`.

If #1–#4 all return no and #5 feels wrong, stop and ask — the need for a new scope is a signal, not a nuisance.

---

## 2. Scoping (selectors must match intended surface)

A CSS rule that lives in `bp-integration.css` must be scoped under `#buddypress` (or `.activity-list` for AJAX-injected activity items that render outside the `#buddypress` wrapper). Without that anchor, the rule applies everywhere — including non-BP pages where the same class name may be (legitimately) used.

### ❌ BAD (BP rule without #buddypress anchor)

```css
/* In bp-integration.css */
.mvs-activity-media-grid {
    display: grid;
    gap: 3px;
}
```

Problem: `.mvs-activity-media-grid` is a BP-only class today, but nothing stops a future feature from reusing the class on a non-BP surface. The rule would apply there too.

### ✅ GOOD (anchored)

```css
#buddypress .mvs-activity-media-grid,
.activity-list .mvs-activity-media-grid {
    display: grid;
    gap: 3px;
}
```

The `.activity-list` fallback is specifically for activity streams that BP re-injects via AJAX after `#buddypress` is already rendered — those tiles can end up outside the wrapper.

### The generic-selector trap

A rule like `.activity-content img:not(.avatar):not(.emoji)` is catastrophic when it lands in a broadly-enqueued file. In 2026-04 that exact selector in `frontend.css` caught Reign's `.bp-group-preview-cover img` (a classless theme-injected image) and stretched it from 150px → 600px inside every join-group activity. **Never use an `img` + `:not()` catch-all selector for plugin-owned images.** Anchor on a link pattern that the plugin owns:

```css
/* ✅ Only our media permalinks get constrained */
#buddypress .activity-content a[href*="/media/"] img:not(.emoji):not(.avatar) { ... }
```

---

## 3. Specificity strategy (beating theme rules without !important)

BP themes (Reign, BuddyX, BuddyBoss, Kadence) ship heavy rules like:

```css
body #buddypress.buddypress-wrap form#whats-new-form #whats-new-options select { ... }
/* specificity: 3 IDs, 1 class, 3 elements = (3,1,3) */
```

To win that battle without `!important`, our selector needs matching or higher specificity. Stacking IDs is the cheapest way:

```css
/* Specificity: 5 IDs, 1 class = (5,1,0) — wins */
#buddypress #whats-new-form #whats-new-options #mvs-activity-privacy.mvs-activity-privacy { ... }
```

**`!important` is allowed only as a last resort, and every use must have a one-line comment above it explaining which theme rule it's fighting and why a specificity-based solution wasn't viable.** Every `!important` without a comment is a bug.

---

## 4. No duplicate class-vs-ID rules for the same element

If an element has both an `id` and a `class`, pick one and style it in one place. Class-vs-ID duplicates that target the same element are the #1 source of "I styled it but nothing changed" bugs — the ID silently wins and the class rule becomes dead code.

### Anti-example

```css
/* Line 2787 */
.mvs-activity-media-btn { padding: 6px 14px; border-radius: 20px; background: var(--mvs-surface-2); }

/* Line 4257 (1400 lines later, same element) */
#mvs-activity-media-btn { padding: 6px 14px; border-radius: 4px; background: var(--mvs-bg); }
```

Result: the button is a rectangle, not a pill. Whoever wrote the class rule never noticed it was dead. This pattern cost us a day of debugging in 2026-04.

**Rule:** one canonical selector per element. If an element has an ID (because PHP/JS references it by `getElementById`), style by ID and delete the class-based duplicate.

---

## 5. No dead CSS selectors

Every `.mvs-*` / `#mvs-*` / `#whats-new-*` selector in our CSS files must correspond to markup emitted somewhere in `includes/`, `src/`, `templates/`, or `assets/js/`. A selector with no emitter is dead code.

Historical example: `.theme-flavor` in `frontend.css` had rules for ~15 lines. No PHP/JS/template ever emitted that class; no known theme added it to `<body>`. It sat dead for at least 6 months before the 2026-04 audit caught it. Additionally, a dangling `.theme-flavor` selector with no rule body was silently hijacking a sibling rule's selector list (`.theme-flavor .buddyboss-theme X`), breaking what little theme-compat was supposedly there.

**Rule:** selectors without emitters are bugs. The dead-selector detector (CI aspiration; see `PROCESS-RULES.md`) should fail the build on violations.

---

## 6. File-top scope banner (mandatory)

Every CSS file under `assets/css/` must start with a banner comment stating its scope contract — what it owns, what it does NOT own, and where the excluded rules should go instead.

### Template

```css
/**
 * <FILE PURPOSE — one line>
 *
 * Scope:       <what this file owns>
 * Not scope:   <what this file does NOT own>
 *   - <rule type A> → <other file>
 *   - <rule type B> → <other file>
 *
 * Loaded by:   <PHP integration(s) that enqueue this>
 * Depends on:  <other stylesheets this depends on, if any>
 * RTL:         <auto-generated by grunt-rtlcss | hand-authored>
 *
 * Specificity strategy (if file competes with theme rules):
 *   <brief explanation of how selectors beat theme CSS>
 */
```

### Precedent (already in tree)

`bp-integration.css` has a proper banner today; it's why we didn't accidentally put new BP rules in `frontend.css` after the 2026-04 migration. The banner works. Extend the pattern to all `assets/css/*.css` files.

---

## 7. Section numbering inside a file

Section headers inside a CSS file must be numbered sequentially and NOT reused. `frontend.css` currently has section #26 three times and #27 four times — that's rot from cherry-pick merges overwriting each other's numbering. It makes grep-by-section-number useless and signals nobody is reading the file end-to-end.

**Rule:** when adding a new section, use `N+1` where `N` is the current last section in the file. When a file hits 20+ sections, that's also a signal it's probably doing too much — revisit whether some sections should split out.

---

## 8. Anti-patterns (never do)

| Pattern | Why it's bad | What to do instead |
|---------|--------------|---------------------|
| Same selector styled in two CSS files with different rules | One wins by load order; the other is dead. Author never notices. | Pick one file (per the ownership matrix), delete the other. |
| `display:` inline style set in JS overriding a `display:` CSS rule | JS inline styles beat CSS of any specificity. A CSS `display: grid` rule becomes dead any time JS sets `element.style.display = 'flex'`. Single-upload preview bug (2026-04) was this exact issue. | If JS needs to toggle visibility, toggle a class, not an inline style. `element.classList.toggle('is-visible')`, not `element.style.display = 'none'`. |
| Appending `!important` to win a specificity fight with a theme | Accumulates forever. Next theme update ships an `!important` too, and now you need `!important !important` (which doesn't exist). | Raise specificity via extra ID anchors. See §3. |
| Plugin rule whose selector has no `#buddypress` / `.activity-list` prefix but targets BP markup | The rule applies everywhere, not just BP. A legitimate reuse of the same class name on a non-BP surface becomes styled incorrectly. | Anchor under `#buddypress` (or `.activity-list` for AJAX-injected streams). |
| `img:not(.avatar):not(.emoji)` or other broad element-level catch-alls | Catches theme-injected classless images. See §2 for the Reign cover-image case. | Anchor on a link pattern or wrapper class the plugin owns (`a[href*="/media/"] img`, `.mvs-X img`). |
| Adding new rules to a 6000-line CSS file without reading §6 banner first | You don't know what the file owns. Rules land in the nearest plausible section regardless of whether they belong. | Read the banner. If your rule doesn't fit, you probably need a different file (or a new section). |

---

## 9. Relationship to other rules

- **`CLAUDE.md` Coding Rule #12** ("CSS file ownership") is the one-line canonical version of this document. This file is the full spec; Rule #12 is the pointer.
- **`qa/WHAT-TO-CHECK.md`** contains regression locks for specific selectors — when a rule in this doc gets violated and causes a user-visible bug, the bug fix adds a row there.
- **`qa/PROCESS-RULES.md`** describes how rules in this doc become machine-checkable (stylelint, CI, pre-commit hooks).

---

## 10. When adding a new rule (checklist)

Before committing a CSS change, the author should be able to answer all of these:

- [ ] Does the rule live in the file that owns its scope? (§1 decision tree)
- [ ] If it targets BP markup, does the selector include `#buddypress` or `.activity-list`? (§2)
- [ ] If it uses `!important`, does the next line comment explain which theme rule it's fighting? (§3)
- [ ] Is the selector unique — no pre-existing rule for the same element in a different file or at a different specificity? (§4)
- [ ] Is every class/id in the selector actually emitted by the plugin code? (§5)
- [ ] Does the file have a banner, and does this rule fit inside that banner's "scope" line? (§6)
- [ ] If the rule is inside a section, does the section header number continue the file's sequence? (§7)
- [ ] None of the §8 anti-patterns apply?
- [ ] Is the styling in a stylesheet, NOT an inline `style="…"` in markup? (§11)

If any answer is "no", the rule isn't ready to merge.

---

## 11. No inline cosmetic CSS in markup (canonical: CLAUDE.md Coding Rule #19)

Templates, block `render.php`, and HTML-echoing PHP (`includes/**`) must NOT carry cosmetic `style="…"` attributes or hardcoded hex. The CSS gates in §1–§10 only scan `.css` files — they are structurally blind to inline `style=` and hardcoded hex inside markup, which is exactly why inline cosmetic CSS accumulated undetected until the 1.8.0 audit (68 violations across ~30 files).

**A `style="…"` is a violation when it sets cosmetic CSS** — `color`, `background`, `margin`/`padding` (incl. longhands), `font*`, `border*`, `box-shadow`, `text-align`, `gap`, `display:flex` mixed with other props — or contains a literal hex outside a `var()` fallback. Move it to the stylesheet that owns the surface (§1 matrix) as a tokenized `var(--mvs-*)` class.

**Reuse before you add.** Most 1.8.0 leaks were renderers re-inlining styling a tokenized class already provided — `.mvs-stat-value`/`.mvs-stat-label` (media-stats), `.mvs-activity-audio-icon`/`-title` (BP audio card, duplicated across two renderers), `.mvs-story-avatar` (story-viewer). The fix was to use the existing class, not add CSS. The media-stats block even carried dead `ol`/`li` selectors while rendering a `<table>` — markup and CSS had diverged (§5). Check the stylesheet first; never duplicate; never diverge.

**Allowed inline (NOT violations):**
- Pure visibility toggles — `style="display:none"` / `display:block` and nothing else (a behavioral JS / Interactivity-API initial state, not appearance).
- Custom-property fallbacks — `var(--mvs-token, #fallback)`. Token first, literal only as last resort; this is the tokenized pattern, not a hardcoded color.
- Instance custom properties set inline — `style="--mvs-story-avatar-size:<?php echo $n; ?>px"` — a per-instance knob the stylesheet consumes (add the token to the css-token-contract `ALLOW` list so it isn't flagged as phantom).
- A dynamic value the server computes — `style="width:<?php echo $pct; ?>%"` (progress bars). The computed part is allowed; a literal hex baked into a dynamic style is still flagged.

**Admin warning callouts** with no dark-mode variant (e.g. amber FFmpeg-missing box) may use literal hex in the `.css` file with a comment, but never inline in markup.

**Enforcement:** `bin/template-style-check.sh`, wired as local-CI **stage 1.7** (Free and Pro) + `composer template-styles`. It flags static cosmetic inline styles and bare hex, allowing the four cases above. Add the same gate to any sibling plugin.

## `[hidden]` and the Interactivity API

The Interactivity API hides elements by toggling the HTML `hidden` attribute.
`hidden` is a *presentation hint* with the specificity of a UA style, so **any**
rule of yours that sets `display` beats it. An element declared `display:flex`
and hidden by `data-wp-bind--hidden` will render.

**One rule, on our namespace:**

```css
[class*="mvs-"][hidden],
[class*="mvs-"] [hidden] {
    display: none !important;
}
```

Do NOT add a per-selector `[hidden]` exception. That is what the file used to
do — twelve selectors, maintained by hand, under a comment describing the exact
bug — and `.mvs-bulk-bar` and `.mvs-dashboard-loading` were simply never added.
See Coding Rule 22.

**Why it looked fine for months:** Reign ships `[hidden]{display:none}` in
`critical.min.css` and enqueues *after* us. Equal specificity, so source order
decided it and our themes silently rescued the bug. Astra ships no such reset.
Anything you leave to the theme is correct only by luck on the themes you look at.

**Measure on the live page.** An element inside a correctly hidden ancestor
computes its own `display` in isolation, so checking markup injected into a
detached container reports false positives. `offsetParent !== null` is what
separates "on screen" from "styled but masked".

## Deriving a fill from a token that may equal its background

`--mvs-surface-*` frequently resolves to the same colour as the thing behind it,
and a `color-mix()` from `currentColor` can land in the same place. A tint that
matches its container paints nothing while looking deliberate in the source.

**Measure the rendered pixel before keeping a fill.** The document placeholder
added in 2.4.1 was given a background twice — a surface token and a currentColor
mix — and both computed to `rgb(250,251,253)`, exactly the card behind them. The
fill was dropped and the glyph carries the tile. If you cannot show the computed
value differs from its parent, do not ship the declaration.
