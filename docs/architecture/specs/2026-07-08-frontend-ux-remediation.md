# MediaVerse Frontend UX Remediation — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: use `superpowers:subagent-driven-development` (recommended) or `superpowers:executing-plans` to implement task-by-task. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Make every customer-facing MediaVerse surface read as one premium product — plugin-owned page templates, a real desktop grid, the existing component primitives actually consumed, and one accent authority.

**Architecture:** MediaVerse already ships the vocabulary (`.mvs-page` 90 defs, `.mvs-card` 74, `.mvs-btn` 436, `.mvs-empty-state` 166) and a correct theme-bridge token chain. Nothing needs inventing. The work is (a) taking ownership of the page template we currently borrow from the theme, (b) defining what 1440px means, (c) migrating hand-rolled containers onto the primitives that already exist, and (d) settling who owns colour.

**Tech Stack:** WordPress 6.5+, PHP 8.1+, WP Interactivity API, ES modules, plain CSS custom properties. No build-system change. No new dependency.

---

## Global Constraints

Copied verbatim; every task inherits these.

- **Production Rule 1/2** — never hard-remove or rename a public identifier (class name consumed by a theme override, hook, option, template file) without a ≥2-major-version alias. CSS class names shipped to customers count.
- **Production Rule 5** — never remove a template file. Alias with `@deprecated` for ≥2 majors.
- **Coding Rule 15 (debt tax)** — no PR adds lines to `includes/Services/UploadService.php` or `includes/Admin/Settings/SettingsRegistrar.php`.
- **Coding Rule 19 / F1** — no inline cosmetic `style=` in markup, no inline `<style>` in PHP.
- **Coding Rule 12** — CSS file ownership; no dead selectors; no `!important` without a one-line comment.
- **ux-foundation Rule 6** — no raw hex/px in CSS; tokens only, hex fallback permitted before `color-mix()`.
- **ux-foundation Rule 5** — Lucide icons only in new code.
- **ux-foundation responsive** — exactly two `@media` blocks, at the bottom of each file: `max-width: 1024px`, `max-width: 640px`.
- **Verify-per-item** — every task with a visual change is browser-verified at 1440 and 390 before it is marked done. Code review passing is not done.
- **Do not edit BuddyNext.** BN issues are filed as Basecamp cards.
- **One development branch per release.** All of this lands on one version branch.

---

## What is measured, and what is not

### Measured (2026-07-08, desktop 1440×900, Chrome, BuddyX + BuddyNext active, admin session)

| Fact | Evidence |
|---|---|
| `.mvs-card` used on **zero** frontend screens | probe: `card: false` on `/explore-media/`, `/my-media/`, `/compete/`, `/media/{slug}/` |
| Bespoke `mvs-*` component families per screen | `/my-media/` 179 · `/explore-media/` 135 · `/media/{slug}/` 101 · `/compete/` 75 |
| `/my-media/` renders the theme's blog sidebar | probe: `sidebar_widgets: ["Recent Posts","Recent Comments"]` |
| Other plugin routes do not | `/compete/`, `/media/{slug}/`: `theme_sidebar: false` |
| All six plugin pages use `template=default` | `_wp_page_template` empty on pages 141/140/20/138/194/191 |
| The pages are created by us | `Activator.php:59 create_pages()` → `:128 wp_insert_post()` with block content |
| `.mvs-page` shell exists and spans 1425px on every screen | probe: `shell: true, shell_width: 1425` |
| Explore feed column is 614px inside it | probe: `feed_width: 614`, dead gutter 413px each side |
| `--mvs-bg` and `--mvs-border` are overwritten by BuddyNext | `bn-base.css :root { --bn-media-bg: oklch(96% 0.03 var(--bn-hue-media)) }` → `--mvs-bg: var(--bn-media-bg)` |
| Mint exists nowhere in MediaVerse CSS | `grep -rn "oklch([^)]*175" assets/css` → 0 hits, both plugins |
| `--mvs-accent: #6366f1` is a raw hex, painted on zero surfaces | `frontend.css:58` |
| `--mvs-primary` correctly bridges the theme | `frontend.css:32` → `--reign-site-button-bg-color` → … → `#0073aa` |
| `/gamification/` contains zero MediaVerse markup | probe: 70 `gam-` elements, 0 `mvs-` |
| ux-audit grep pass | Free: 5 block / 52 advisory. Pro: 2 block / 28 advisory |

### NOT measured — this is the honest gap

**7 screens looked at. 20+ never opened.** Pending: `/messages/`, `/activity/`, member profile, albums, collections, stories, 12 Free blocks, 12 Pro blocks, 17 Free + 10 Pro admin pages, and every generated artifact.

**Every observation above is desktop-only, light mode, LTR, admin role.** No mobile (390), no dark, no RTL, no logged-out, no peer-role pass exists.

**Phase 0 exists to close that gap.** No task after Phase 2 may be scheduled until Phase 0 completes, because the migration scope (how many screens, which containers) is currently unknown.

---

## Scope check

This spec spans four independent subsystems. Per `writing-plans` §Scope Check they should not share one plan beyond Phase 2:

1. **Page-template ownership** (PHP routing) — Phase 1, self-contained.
2. **Colour authority** (tokens, cross-plugin) — Phase 2, self-contained.
3. **Desktop grid + primitive migration** (CSS + templates, N screens) — Phase 3-4, **needs Phase 0 output to size**.
4. **Sections layer + CSS consolidation** (`mvs-ui.css`, Rule 9) — Phase 5-6, **its own plan; do not start inside this one.**

Phases 1 and 2 ship value immediately and independently. Phase 3+ is gated.

---

## Phase 0 — Discovery: finish the rendered-surface sweep

**Why first:** we cannot plan a migration across N screens without knowing N, or which of them already compose correctly. Skipping this produces 26 differently-polished screens — the exact failure we are trying to fix.

**Deliverable:** `audit/screens/INDEX.md` (started) with every row `looked`, plus one record per screen per the `ux-audit` §0 template.

### Task 0.1: Enumerate every customer-visible surface

**Files:**
- Modify: `audit/screens/INDEX.md`

- [ ] **Step 1: Derive the route list from the code, not from memory**

```bash
cd wp-content/plugins/wpmediaverse
grep -rn "add_rewrite_rule(" includes/Core/TemplateLoader.php | sed "s/.*'\^\([^']*\)'.*/\1/"
grep -rn "add_menu_page\|add_submenu_page" includes/Admin/ | wc -l
ls src/blocks | grep -v '^shared'
ls ../wpmediaverse-pro/src/blocks | grep -v '^shared'
```

- [ ] **Step 2: Write one INDEX row per surface, all marked `pending`**

Expected: ≥27 rows. Any surface you cannot name a route for is itself a finding — record it.

- [ ] **Step 3: Commit**

```bash
git add audit/screens/INDEX.md
git commit -m "audit: enumerate every customer-visible surface (all pending)"
```

### Task 0.2: Look at each surface and write its record

**Files:**
- Create: `audit/screens/<screen-slug>.md` (one per row)
- Create: `audit/screens/_shots/<screen-slug>/desktop-light.png`

- [ ] **Step 1: Render and probe.** For each surface, at 1440×900, run the standard probe:

```js
() => {
  const vw = innerWidth;
  const side = document.querySelector('#secondary, .widget-area, aside.sidebar');
  const mvs = [...document.querySelectorAll('[class*="mvs-"]')];
  const widest = mvs.map(e=>e.getBoundingClientRect().width).sort((a,b)=>b-a)[0] || 0;
  const has = s => !!document.querySelector(s);
  const bespoke = new Set();
  mvs.forEach(e=>e.className.toString().split(/\s+/).forEach(c=>{
    if(/^mvs-/.test(c) && !/^mvs-(page|card|btn|input|badge|empty|icon|grid|stack|cluster|sr-only)/.test(c))
      bespoke.add(c.split('--')[0]);
  }));
  return { url: location.pathname, theme_sidebar: !!side,
    sidebar_widgets: side ? [...side.querySelectorAll('h2,h3')].map(h=>h.textContent.trim()).slice(0,3) : [],
    shell: has('.mvs-page'), card: has('.mvs-card'), empty: has('.mvs-empty, .mvs-empty-state'),
    widest: Math.round(widest), gutter: Math.round((vw-widest)/2),
    bespoke: bespoke.size, dashicons: document.querySelectorAll('[class*="dashicons"]').length,
    hscroll: document.documentElement.scrollWidth > vw+2 };
}
```

- [ ] **Step 2: Screenshot desktop-light. Look at it.** A 200 response is not a look.
- [ ] **Step 3: Repeat at 390px, dark (`data-bx-mode="dark"` — the theme's real toggle, never a forced `.mvs-dark` class), and logged-out.**
- [ ] **Step 4: Write the record** using the `ux-audit` §0 template (Reached by / Renders via / States / Viewports / Theme / Rendered ref / Visual contract / Breaks when / Gaps found).
- [ ] **Step 5: Flip the INDEX row to `looked`. Every gap found becomes a Basecamp card.**
- [ ] **Step 6: Commit per batch of 5 screens**

```bash
git add audit/screens/
git commit -m "audit: rendered-surface records for <batch>"
```

**Gate:** Phase 3 may not begin while any INDEX row reads `pending`.

---

## Phase 1 — Own the page template

**Problem (measured):** `Activator::create_pages()` inserts `/my-media/`, `/explore-media/` etc. as ordinary WP pages with `template=default`. On BuddyX that yields the blog page template, so a member's photo library renders beside *"Recent Posts"* and *"A WordPress Commenter on Hello world!"*. `/compete/` and `/media/{slug}/` happen not to, which means the product treats its own routes inconsistently.

**Approach:** `template_include`, per `ux-foundation` §"Layout — Register a template, never hide HTML". Never CSS-hide the sidebar: it still queries widgets, screen readers still announce it, Google still indexes it, and it breaks silently when the theme updates.

### Task 1.1: Ship a plugin page template, theme-overridable

**Files:**
- Create: `templates/app-page.php`
- Modify: `includes/Core/TemplateLoader.php`
- Test: `tests/unit/TemplateLoaderTest.php`

**Interfaces:**
- Produces: `TemplateLoader::is_app_page( int $post_id ): bool` — true when the post is one of the plugin-created app pages.
- Produces: filter `mvs_app_page_ids` (array of int) so a site owner can add/remove pages.
- Produces: filter `mvs_app_template` (string path) so a theme can substitute its own.

**VERIFIED CODEBASE FACTS (use these exact values — the earlier draft was wrong):**
- Path constant is **`MVS_PLUGIN_DIR`** (`wpmediaverse.php:27`), NOT `MVS_DIR`.
- The plugin creates exactly **three** pages, recorded in these option keys (`Activator.php:74-84`):
  - `mvs_page_dashboard` → `/my-media/` (#141) — the screen with the blog sidebar bug.
  - `mvs_page_explore`   → `/explore-media/` (#140).
  - `mvs_page_upload`    → `/upload-media/` (#142).
- `/compete/`, `/messages/`, `/activity/`, `/gamification/` are **NOT plugin-created** — do not route them. `/compete/` already renders sidebar-free; the others belong to Pro / BuddyPress / wb-gamification.
- `TemplateLoader::init()` exists at `:33`; register the `template_include` filter there.

- [ ] **Step 1: Write the failing test**

```php
public function test_app_page_ids_include_created_pages(): void {
    $id = (int) get_option( 'mvs_page_dashboard' );
    $this->assertGreaterThan( 0, $id, 'dashboard (my-media) page option must exist' );
    $this->assertTrue( \WPMediaVerse\Core\TemplateLoader::is_app_page( $id ) );
}

public function test_non_app_page_is_not_an_app_page(): void {
    $id = self::factory()->post->create( array( 'post_type' => 'page' ) );
    $this->assertFalse( \WPMediaVerse\Core\TemplateLoader::is_app_page( $id ) );
}
```

- [ ] **Step 2: Run it, confirm it fails**

Run: `./vendor/bin/phpunit --filter TemplateLoaderTest`
Expected: FAIL — `Call to undefined method ... is_app_page()`

- [ ] **Step 3: Implement**

```php
/**
 * The pages this plugin created on activation and therefore owns the layout of.
 *
 * We inserted these pages (Activator::create_pages()), so the blog sidebar that
 * a theme's default page template renders beside a member's photo library is our
 * doing, not WordPress's. Own the template rather than CSS-hide the chrome:
 * hidden chrome still queries widgets, still gets announced by screen readers,
 * still gets indexed, and still takes Tab focus.
 *
 * @return int[]
 */
public static function app_page_ids(): array {
    $ids = array();
    // Only the pages this plugin actually created (Activator::create_pages()).
    // dashboard=/my-media/, explore=/explore-media/, upload=/upload-media/.
    foreach ( array( 'dashboard', 'explore', 'upload' ) as $key ) {
        $id = (int) get_option( 'mvs_page_' . $key, 0 );
        if ( $id > 0 ) {
            $ids[] = $id;
        }
    }
    /**
     * Filter the pages that render with the plugin's full-bleed app template.
     *
     * @since 2.1.0
     * @param int[] $ids Page IDs.
     */
    return (array) apply_filters( 'mvs_app_page_ids', $ids );
}

public static function is_app_page( int $post_id ): bool {
    return in_array( $post_id, self::app_page_ids(), true );
}
```

And the hook (registered in `TemplateLoader::init()`):

```php
add_filter( 'template_include', array( self::class, 'use_app_template' ), 99 );

public static function use_app_template( string $template ): string {
    if ( ! is_page() || ! self::is_app_page( (int) get_queried_object_id() ) ) {
        return $template;
    }
    // Theme override first — themes may ship wpmediaverse/app-page.php.
    $override = locate_template( 'wpmediaverse/app-page.php' );
    $resolved = $override ?: MVS_PLUGIN_DIR . 'templates/app-page.php';

    /**
     * Filter the app-page template path.
     *
     * @since 2.1.0
     * @param string $resolved Absolute template path.
     */
    return (string) apply_filters( 'mvs_app_template', $resolved );
}
```

`templates/app-page.php` calls `get_header()`, renders `the_content()` inside `<main class="mvs-page">`, calls `get_footer()`, and **never** calls `get_sidebar()`.

- [ ] **Step 4: Run the test, confirm it passes**

Run: `./vendor/bin/phpunit --filter TemplateLoaderTest`
Expected: OK (2 tests)

- [ ] **Step 5: Browser-verify — the gate**

Load `http://mediaverse.local/my-media/` at 1440 and probe:

```js
() => ({ sidebar: !!document.querySelector('#secondary, .widget-area'),
         widgets: [...document.querySelectorAll('#secondary h2')].map(h=>h.textContent.trim()) })
```

Expected: `{ sidebar: false, widgets: [] }`
Then repeat at 390px, and logged-out. Screenshot each into `audit/screens/_shots/my-media/`.

Also verify the negative: a plain WP page (e.g. `/sample-page/`) still renders the theme sidebar. Regression guard.

- [ ] **Step 6: Commit**

```bash
git add templates/app-page.php includes/Core/TemplateLoader.php tests/unit/TemplateLoaderTest.php
git commit -m "templates: own the app-page layout instead of borrowing the theme's blog template

Activator::create_pages() inserts /my-media/, /explore-media/ etc. as ordinary
pages, so BuddyX rendered a member's photo library beside 'Recent Posts' and
'A WordPress Commenter on Hello world!'. We created those pages; the sidebar is
our doing, not WordPress's.

template_include now routes them to templates/app-page.php (theme-overridable via
wpmediaverse/app-page.php, filterable via mvs_app_template). No CSS-hiding: hidden
chrome still queries widgets, gets announced, gets indexed, and takes Tab focus.

Verified: /my-media/ has no sidebar at 1440 + 390, logged-in and out; a plain WP
page still renders the theme sidebar."
```

### Task 1.2: Record the page IDs at creation

**Files:**
- Modify: `includes/Core/Activator.php` (inside `create_pages()`)

`app_page_ids()` above reads `mvs_page_{key}` options. Verify `create_pages()` writes them; if it does not, add the `update_option()` call in the same loop that inserts the page. Do not add a second loop.

- [ ] **Step 1:** `grep -n "update_option( 'mvs_page_" includes/Core/Activator.php`
- [ ] **Step 2:** If absent, write it beside the `wp_insert_post()` at `:128`.
- [ ] **Step 3:** Deactivate + reactivate the plugin on the local site; assert every option exists:

```bash
for k in dashboard explore upload; do wp option get mvs_page_$k; done
```

Expected: five integers, none empty.
- [ ] **Step 4: Commit.**

---

## Phase 2 — Settle colour authority

**Problem (measured):** three parties claim colour.

```
BuddyNext  bn-base.css  --mvs-bg, --mvs-border  ←  var(--bn-media-bg) = oklch(96% 0.03 175)
theme      (BuddyX)     --mvs-primary            ←  #ee4036
MediaVerse frontend.css --mvs-accent: #6366f1    ←  raw hex, painted on ZERO surfaces
```

**Decisions, stated once:**

1. **BuddyNext owns surface tint.** It deliberately hue-codes each module (`--bn-hue-media: 175`). MediaVerse must not fight it — a plugin override pinning a value the host is supposed to own is itself the bug. On a site without BuddyNext, `--mvs-bg` correctly falls back to the theme's body background.
2. **If the mint is unwanted, that is a BuddyNext product decision.** File a BN Basecamp card. Do not edit BN, do not override `--mvs-bg` in MediaVerse.
3. **`--mvs-accent: #6366f1` is dead and must go.** Raw hex (Rule 6), painted nowhere.

### Task 2.1: Remove the dead indigo accent

**Files:**
- Modify: `assets/css/frontend.css:58`

- [ ] **Step 1: Prove it is dead**

```bash
cd wp-content/plugins/wpmediaverse
grep -rn "var(--mvs-accent" assets/ templates/ src/ includes/ ../wpmediaverse-pro/ | grep -v '\-\-mvs-accent:'
```

Expected: **zero** consumers. If any consumer exists, this task stops and becomes "point the consumer at `--mvs-primary`" instead — do not delete a live token.

- [ ] **Step 2: Delete the declaration.** Per Production Rule 2, a CSS custom property consumed by nobody needs no alias; note the removal in the commit body.

- [ ] **Step 3: Browser-verify no visual change**

Screenshot `/explore-media/`, `/my-media/`, `/compete/` at 1440 before and after. Expect pixel-identical.

- [ ] **Step 4: Commit**

```bash
git commit -am "css: drop --mvs-accent, a raw-hex indigo painted on zero surfaces

frontend.css:58 declared --mvs-accent: #6366f1 (raw hex, ux-foundation Rule 6).
grep across both plugins finds no consumer. Deleting it changes no pixel and
removes a third colour authority from a system that already has two."
```

### Task 2.2: File the BuddyNext token-namespace card

**Not code.** BuddyNext's `bn-base.css` writes `--mvs-bg` and `--mvs-border` — another plugin's namespace. Whether the mint is desirable is a BN product call; the namespace crossing is a contract question either way.

- [ ] **Step 1:** File a Basecamp card on the BuddyNext project with: the two overwritten tokens, the `bn-base.css` selector and value, a screenshot of `/my-media/` showing mint surfaces beside a red primary button, and the question: *is hue-coding each module by writing into its token namespace intended, or should BN expose `--bn-media-bg` for plugins to opt into?*
- [ ] **Step 2:** Link the card here.

---

## Phase 3 — Define the desktop grid  *(gated on Phase 0)*

**Problem (measured):** `.mvs-page` spans 1425px; the Explore feed column is 614px; 413px of dead gutter each side. 57% of a desktop viewport is unused. There is no second column.

**Not schedulable yet.** The grid's shape depends on what Phase 0 finds on the 20 unopened screens — specifically whether a context rail has content to hold on more than one screen. Writing the CSS before that is guessing.

**What Phase 0 must answer, explicitly, before this phase is written:**

- Which screens have a natural secondary column (suggested people? your stats? trending tags? challenge sidebar?), and which are genuinely single-column (single media, messages)?
- Does any screen already ship a rail that could become the canonical one?
- What is the correct max content width — 614px is Instagram's; is that our intent, or an accident?

Only then: `.mvs-page--feed` (content + rail), `.mvs-page--single`, `.mvs-page--full`, defined once, consumed by every screen.

---

## Phase 4 — Consume the primitives  *(gated on Phase 0)*

**Problem (measured):** `.mvs-card` has 74 CSS definitions and **zero** frontend consumers. Bespoke component families: 179 / 135 / 101 / 75 on the four screens sampled.

**Method (per `ux-audit` §4 Step 5):** one template per commit. For each: replace bespoke containers with `.mvs-card` + `__head`/`__body`, bespoke buttons with `.mvs-btn--*`, status text with `.mvs-badge--*`, and rebuild the empty state on `.mvs-empty-state` with icon + title + body + CTA (Rule 15). Screenshot at 5 viewports before and after each commit. Add a deprecated alias for every removed class name for one release cycle (Production Rule 1).

**Scope unknown until Phase 0.** The four sampled screens carry 490 bespoke families between them; the remaining 20+ are unmeasured. Do not estimate this phase before Phase 0 lands.

---

## Phase 5 — Sections layer  *(separate plan)*

`hero`, `feature-row`, `stats-row`, `cta-banner` are all absent and reinvented inline (`.mvs-how-it-works` is a hand-rolled `repeat(3,1fr)` with `gap: 1rem`, raw rem). There is no `mvs_render_section()`.

This is its own subsystem with its own QA surface. **Do not start it inside this plan.** Copy Jetonomy (`jt-`), the canonical reference.

---

## Phase 6 — CSS consolidation  *(separate plan)*

Rule 9 wants one `assets/css/mvs-ui.css`. Free currently ships `admin.css`, `bp-integration.css`, `collection-metabox.css`, `frontend.css`, …; Pro ships `admin.css`, `analytics.css`, `app-branding.css`, `collection-picker.css`, `confirm-dialog.css`, `gamification.css`, … Also: `frontend.css:2773` styles `.mvs-empty-state .dashicons` (Rule 5). Portfolio-level; own plan; own QA pass.

---

## Risks and rollback

| Risk | Mitigation |
|---|---|
| `template_include` breaks a customer's theme override | Theme override checked first (`locate_template( 'wpmediaverse/app-page.php' )`), plus `mvs_app_template` filter. Production Rule 3 satisfied. |
| A site owner *wants* the sidebar on `/my-media/` | `mvs_app_page_ids` filter removes any page from app-template routing in one line. |
| Removing `--mvs-accent` breaks a customer's child-theme override | It is a custom property nobody reads; a child theme overriding it was already a no-op. Called out in the commit body. |
| Migrating containers to `.mvs-card` breaks theme CSS targeting old class names | Deprecated alias block at the bottom of the stylesheet for one release cycle (Production Rule 1), then dropped in Phase 6. |
| Phase 3/4 sized from four screens instead of 27 | **This is why Phase 0 gates them.** |

**Rollback:** every phase is one or more independent commits on the version branch. Phase 1 reverts by removing one `add_filter`. Phase 2 reverts by restoring one CSS line.

---

## Out of scope

- Editing BuddyNext (standing rule — file cards).
- `wb-gamification`'s `/gamification/` screen — different plugin, `gam-` prefix, zero MediaVerse markup. Its inconsistency with MediaVerse is real and belongs in a portfolio card.
- The two open Challenges bug cards (`10074021405`, `10073961776`). They are functional bugs on a screen this plan will later restyle; they ship first, independently, and must not wait on any of this.

---

## Definition of done

- [ ] `audit/screens/INDEX.md` has **zero** `pending` rows.
- [ ] `/my-media/` and `/explore-media/` render no theme sidebar; a plain WP page still does.
- [ ] `grep -rn "var(--mvs-accent" ` returns zero hits and the declaration is gone.
- [ ] BuddyNext token-namespace card filed and linked.
- [ ] Phases 3 and 4 have been *written* as plans, from Phase 0's evidence.
- [ ] `bin/ux-audit.sh` block-severity count has not increased.
- [ ] Every visual change screenshotted at 1440 and 390, light and dark, and looked at.
