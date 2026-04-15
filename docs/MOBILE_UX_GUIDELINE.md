# WPMediaVerse Mobile UX Guideline

> **Status:** v1 — long-term design contract for every WPMediaVerse-owned frontend surface.
> **Owners:** anyone touching CSS, templates, blocks, or REST-rendered HTML.
> **Audience:** plugin contributors and Pro extension authors.
>
> Every rule in this doc is **enforceable by default**. New PRs must comply on
> mobile (390 × 844) before they're allowed to land. Use the existing token
> system in `frontend.css` and `theme.json` — no one-off hex colors, no magic
> pixel values, no per-page breakpoints.

---

## 1. Why this exists

WPMediaVerse ships a media-rich social platform that needs to feel **native-app
quality** on phones. The reference bar is iOS Photos / Instagram / Threads —
fluid layouts, 44×44 touch targets, predictable back navigation, no horizontal
scroll, no overlapping FABs.

This guideline collapses the audit findings (catalogued in section 9) into
**rules that can be applied uniformly**, instead of one-off CSS patches per
template.

---

## 2. Source of truth: tokens

All sizing, color, and spacing decisions reference the existing token system.
**Never inline a hex code or px value if a token exists.**

### 2.1 CSS custom properties (already defined in `assets/css/frontend.css:15-67`)

| Token | Value | Use for |
|---|---|---|
| `--mvs-bp-mobile` | 480px | media query reference |
| `--mvs-bp-tablet` | 768px | media query reference |
| `--mvs-bp-desktop` | 1024px | media query reference |
| `--mvs-radius-sm` / `-md` / `-lg` / `-pill` | 6 / 8 / 12 / 100px | corners |
| `--mvs-shadow-sm` / `-md` / `-lg` | 3 elevations | drop shadows |
| `--mvs-text` / `-text-secondary` / `-text-muted` | text colors | typography |
| `--mvs-bg` / `-surface` / `-surface-2` / `-bg-muted` / `-overlay` | layered surfaces | backgrounds |
| `--mvs-primary` / `-primary-hover` / `-danger` / `-success` / `-warning` | brand colors | actions |
| `--mvs-border` / `-border-light` | border colors | dividers |

### 2.2 New tokens introduced by this guideline (add to `frontend.css`)

```css
:root {
    /* Touch target floor — Apple HIG + WCAG 2.5.5 */
    --mvs-touch-min: 44px;

    /* Spacing scale (mirror theme.json) */
    --mvs-space-1: 0.25rem;  /*  4px */
    --mvs-space-2: 0.5rem;   /*  8px */
    --mvs-space-3: 1rem;     /* 16px — base gutter */
    --mvs-space-4: 1.5rem;   /* 24px */
    --mvs-space-5: 2rem;     /* 32px */
    --mvs-space-6: 3rem;     /* 48px */
    --mvs-space-7: 4rem;     /* 64px */

    /* Z-index layers — never use bare z-index numbers */
    --mvs-z-base: 1;
    --mvs-z-sticky: 50;
    --mvs-z-fab: 100;
    --mvs-z-overlay: 200;
    --mvs-z-modal: 300;
    --mvs-z-toast: 400;
    --mvs-z-tooltip: 500;

    /* Motion */
    --mvs-duration-fast: 120ms;
    --mvs-duration-base: 200ms;
    --mvs-duration-slow: 320ms;
    --mvs-easing: cubic-bezier(0.2, 0, 0, 1);

    /* Container queries (where supported, otherwise fallback to bp tokens) */
    --mvs-content-narrow: 560px;
    --mvs-content-base: 720px;
    --mvs-content-wide: 1200px;
}
```

### 2.3 Breakpoint convention

WPMediaVerse uses **two breakpoints, not three**:

| Range | Audience | Layout posture |
|---|---|---|
| 0 – 767px | phones | single column, full-bleed media, icon-only dense rows |
| 768px – 1023px | tablets / small laptops | hybrid — same as mobile but larger touch area |
| 1024px+ | desktop | multi-column, full text labels |

Media queries must use the tokens **as reference** (CSS doesn't accept custom
properties inside `@media` directly, so write the px value in the query and
add a `/* var(--mvs-bp-tablet) */` comment for grep-ability):

```css
/* mobile-first */
.some-component { /* mobile rules here */ }

@media (min-width: 768px) /* var(--mvs-bp-tablet) */ {
    .some-component { /* tablet+ overrides */ }
}
```

**Mobile-first only.** Never write desktop rules and override them with
`max-width` queries — that defeats the touch-first default.

---

## 3. Touch targets — 44×44 minimum, no exceptions

**Apple HIG** says 44pt × 44pt. **Google Material** says 48dp × 48dp. **WCAG
2.5.5 (AAA)** says 44px CSS. WPMediaVerse adopts **44×44 as the floor** for
every interactive element on mobile.

### 3.1 The rule

```css
@media (max-width: 1023px) /* below desktop */ {
    .mvs-btn,
    .mvs-icon-btn,
    a.mvs-link-btn,
    .mvs-tab,
    .mvs-action-icon,
    .mvs-fab,
    .mvs-toast-close,
    .mvs-modal-close {
        min-height: var(--mvs-touch-min);
        min-width:  var(--mvs-touch-min);
    }
}
```

### 3.2 Audit-found violations to fix

| Component | Selector | Current | Fix |
|---|---|---|---|
| Edit button on single media | `.mvs-social-actions .mvs-btn--edit` | 52×34 | `min-height: var(--mvs-touch-min)` |
| Delete button on single media | `.mvs-social-actions .mvs-btn--delete` | 70×34 | same |
| Toast close `×` | `.mvs-toast-close` | ~24×24 | size up to 44×44 (icon centered) |

### 3.3 Compact-text exemption

When a control is text-only and inside a flowing list (e.g. inline tag pills,
breadcrumb separators), the **44px floor still applies to the hit area** —
extend padding rather than enlarging the visible chip.

```css
.mvs-tag-pill {
    padding: 0.25rem 0.75rem;     /* visual */
    margin: 0.25rem;               /* expand hit area */
    min-height: var(--mvs-touch-min);
    display: inline-flex;
    align-items: center;
}
```

---

## 4. Button density — icon-only with tooltips on mobile

**The pain:** dense action rows (Share / Edit / Delete / Save / Report …) wrap
to two lines or push the layout horizontally on phones.

**The fix:** at `< 768px`, switch buttons in dense rows to **icon-only with an
accessible tooltip + `aria-label`**. We already ship Lucide icons — wire them
up here so the pattern is consistent across every surface.

### 4.1 The rule

```html
<!-- Universal pattern — works on all viewports -->
<button class="mvs-btn mvs-btn--icon-collapse"
        aria-label="Share media"
        data-mvs-tooltip="Share">
    <i data-lucide="share-2"></i>
    <span class="mvs-btn__label">Share</span>
</button>
```

```css
/* mobile: hide the text label, keep the icon centered */
@media (max-width: 767px) {
    .mvs-btn--icon-collapse .mvs-btn__label {
        position: absolute;
        width: 1px;
        height: 1px;
        padding: 0;
        margin: -1px;
        overflow: hidden;
        clip: rect(0, 0, 0, 0);
        white-space: nowrap;
        border: 0;
    }
    .mvs-btn--icon-collapse {
        min-width: var(--mvs-touch-min);
        padding-inline: 0;
        gap: 0;
    }
}
```

### 4.2 Tooltip pattern

Use **CSS-only tooltips** that show on `:hover` (desktop) and `:focus-visible`
(touch + keyboard). No JavaScript dependency for accessibility.

```css
.mvs-btn[data-mvs-tooltip] {
    position: relative;
}

.mvs-btn[data-mvs-tooltip]::after {
    content: attr(data-mvs-tooltip);
    position: absolute;
    bottom: calc(100% + 8px);
    left: 50%;
    transform: translateX(-50%);
    background: var(--mvs-text);
    color: var(--mvs-bg);
    font-size: 0.75rem;
    padding: 0.25rem 0.5rem;
    border-radius: var(--mvs-radius-sm);
    white-space: nowrap;
    pointer-events: none;
    opacity: 0;
    transition: opacity var(--mvs-duration-fast) var(--mvs-easing);
    z-index: var(--mvs-z-tooltip);
}

.mvs-btn[data-mvs-tooltip]:hover::after,
.mvs-btn[data-mvs-tooltip]:focus-visible::after {
    opacity: 1;
}
```

### 4.3 Dense rows that should adopt this

| Page | Selector | Current density | Recommended icons |
|---|---|---|---|
| Single media action row | `.mvs-social-actions` | Share / Edit / Delete | `share-2` / `pencil` / `trash-2` |
| Reactions row | `.mvs-reactions` | 6 emoji chips | already icons — no change |
| Album owner actions | `.mvs-collection-card-actions` | Edit Album / Delete Album | `pencil` / `trash-2` |
| Lightbox top bar | `.mvs-lightbox-top` | close, share, fav, comment, more | `x` / `share-2` / `heart` / `message-circle` / `more-vertical` |
| Profile edit | `.mvs-profile-edit-actions` | Save / Cancel | keep text — only 2 buttons, no overflow risk |

**Rule of thumb:** if the row has **3+ destructive/secondary actions**, collapse
to icons on `< 768px`. Two-button rows can stay text.

---

## 5. Tab patterns — overflow rules

### 5.1 The pain (audit finding)

`.mvs-dashboard-tabs` on `/my-media/` has **8 tabs** (Media, Albums, Favorites,
Collections, Connectors, Challenges, Battles, Tournaments). Container is
`flex-wrap: nowrap; overflow-x: auto`, so it scrolls — but with no scrollbar
on touch, no edge-fade, and no auto-scroll-to-active, **users don't know more
tabs exist**. At 390px viewport, only 3 tabs are visible (981px content
overflowing 345px container).

### 5.2 The rule

Every horizontally-scrolling tab strip on mobile must:

1. Use `overflow-x: auto` + `flex-wrap: nowrap`
2. Add an **edge-fade** so the cut-off side is visually telegraphed
3. Use **scroll-snap** so each tap lands on a tab boundary
4. **Auto-scroll the active tab into view** on page load (1 line of JS)
5. Each tab must meet the 44px touch minimum

```css
.mvs-tabs-strip {
    display: flex;
    flex-wrap: nowrap;
    overflow-x: auto;
    scroll-snap-type: x mandatory;
    scrollbar-width: none;
    -ms-overflow-style: none;
    /* Edge-fade — pseudo-mask shows scrollable affordance */
    mask-image: linear-gradient(to right, black calc(100% - 32px), transparent);
    -webkit-mask-image: linear-gradient(to right, black calc(100% - 32px), transparent);
}

.mvs-tabs-strip::-webkit-scrollbar { display: none; }

.mvs-tabs-strip > * {
    scroll-snap-align: start;
    flex: 0 0 auto;
    min-height: var(--mvs-touch-min);
    display: inline-flex;
    align-items: center;
    padding: 0 var(--mvs-space-3);
}
```

```js
// One-time auto-scroll on load — runs in dashboard view.js
const strip = document.querySelector('.mvs-tabs-strip');
const active = strip?.querySelector('.is-active');
active?.scrollIntoView({ inline: 'center', block: 'nearest' });
```

### 5.3 When the strip is short (≤ 4 tabs at 390px)

Use **distributed flex** so they fill the row instead of clumping left:

```css
@media (max-width: 767px) {
    .mvs-tabs-strip:has(> :nth-child(-n+4):last-child) {
        justify-content: space-around;
        mask-image: none;
        -webkit-mask-image: none;
    }
}
```

---

## 6. Navigation — every detail page needs a back affordance

**The pain (audit finding):** From `/media/edit-profile/` there is no way back
to `/media/@username/` except the browser back button. Same on single media
pages, album pages, and single challenge pages.

### 6.1 The rule

Every WPMediaVerse-owned **detail / edit page** must render a back affordance
in the page header that links to the parent route. Browser back is not enough
on mobile — users come from search results, deep links, push notifications.

### 6.2 The component

```html
<header class="mvs-page-header">
    <a class="mvs-back-link"
       href="<?php echo esc_url( $parent_url ); ?>"
       aria-label="<?php esc_attr_e( 'Back', 'wpmediaverse' ); ?>">
        <i data-lucide="arrow-left"></i>
        <span class="mvs-back-link__label"><?php echo esc_html( $parent_label ); ?></span>
    </a>
    <h1 class="mvs-page-header__title"><?php the_title(); ?></h1>
</header>
```

```css
.mvs-page-header {
    display: flex;
    align-items: center;
    gap: var(--mvs-space-3);
    padding: var(--mvs-space-3);
    margin-bottom: var(--mvs-space-3);
}

.mvs-back-link {
    display: inline-flex;
    align-items: center;
    gap: var(--mvs-space-2);
    min-height: var(--mvs-touch-min);
    min-width: var(--mvs-touch-min);
    color: var(--mvs-text-secondary);
    text-decoration: none;
    font-size: 0.875rem;
}

.mvs-back-link:hover,
.mvs-back-link:focus-visible {
    color: var(--mvs-primary);
}

.mvs-back-link i {
    width: 20px;
    height: 20px;
}

@media (max-width: 767px) {
    /* Hide the textual label — keep the chevron only */
    .mvs-back-link__label {
        position: absolute;
        clip: rect(0, 0, 0, 0);
        width: 1px;
        height: 1px;
        overflow: hidden;
    }
}
```

### 6.3 Parent-page mapping

| Detail route | Parent route | Back label |
|---|---|---|
| `/media/{slug}/` | `/media/` | "Explore" |
| `/album/{slug}/` | `/media/@{author}/` | "Profile" |
| `/media/edit-profile/` | `/media/@{me}/` | "My profile" |
| `/media/challenges/{id}/` | `/media/challenges/` | "Challenges" |
| `/media/battles/{id}/` | `/media/battles/` | "Battles" |
| `/media/tournaments/{id}/` | `/media/tournaments/` | "Tournaments" |

Templates must compute the parent URL server-side via a helper:
`mvs_parent_url( $context )` — added in `Core/TemplateHelpers.php`.

---

## 7. FAB and floating elements — never overlap content

**The pain (audit finding):** the chat-FAB on `/media/edit-profile/` overlaps
the `Display Name` input. On single media, the FAB sits over the action row.

### 7.1 The rule

When a FAB is on screen, every page must reserve **bottom safe-area padding**
equal to `var(--mvs-fab-safe)` so content can scroll past it.

```css
:root {
    --mvs-fab-size: 48px;
    --mvs-fab-offset: 16px;
    --mvs-fab-safe: calc(var(--mvs-fab-size) + var(--mvs-fab-offset) * 2);
}

.mvs-page,
.mvs-single-media,
.mvs-single-album,
.mvs-profile-edit,
.mvs-explore {
    padding-bottom: var(--mvs-fab-safe);
}

.mvs-fab {
    position: fixed;
    right: var(--mvs-fab-offset);
    bottom: calc(var(--mvs-fab-offset) + env(safe-area-inset-bottom, 0px));
    width: var(--mvs-fab-size);
    height: var(--mvs-fab-size);
    z-index: var(--mvs-z-fab);
    /* honor iOS notch */
}
```

When **two FABs** are present (upload + chat), stack them vertically with the
chat above the upload:

```css
.mvs-fab--chat {
    bottom: calc(var(--mvs-fab-offset) + var(--mvs-fab-size) + 12px + env(safe-area-inset-bottom, 0px));
}
```

---

## 8. Card and grid layouts — when to collapse

### 8.1 The rule

| Component | mobile (< 768px) | desktop |
|---|---|---|
| Media grid (`.mvs-grid`) | `repeat(2, 1fr)` | `repeat(auto-fill, minmax(240px, 1fr))` |
| Profile stats row | flex wrap, full row each line | inline flex |
| Album items | `repeat(2, 1fr)` square thumbs | `repeat(auto-fill, minmax(180px, 1fr))` |
| **Battle card** (Pro) | **keep `1fr auto 1fr`** with square photos — never collapse to single column | `1fr auto 1fr` with 4/3 photos |
| Tournament bracket | horizontal scroll with snap | full SVG |
| Challenge card | single column | `repeat(auto-fill, minmax(400px, 1fr))` |

### 8.2 Why "battles must stay side-by-side"

The audit revealed that `gamification.css:1247` collapsed `.mvs-battle-matchup-full`
from `1fr auto 1fr` to `1fr` on `< 640px`, stacking the two contender photos
vertically. **A battle without a side-by-side comparison stops being a battle**
— it becomes a feed. Fixed in commit `c0bb8f4`. **Never recreate this pattern**
for any head-to-head UI.

---

## 9. Audit findings — concrete inventory

Captured at 390 × 844 (iPhone 13/14 width) on a logged-in admin session.

| # | Route | Selector | Issue | Severity | Rule reference |
|---|---|---|---|---|---|
| 1 | `/my-media/` | `.mvs-dashboard-tabs` | 8 tabs, 981px wide, scrolls horizontally with no edge-fade or auto-scroll-to-active. Only 3 of 8 tabs visible. | High | §5.2 |
| 2 | `/media/{slug}/` | `.mvs-social-actions .mvs-btn--edit` | 52×34 — below 44 touch floor | High | §3.2 |
| 3 | `/media/{slug}/` | `.mvs-social-actions .mvs-btn--delete` | 70×34 — below 44 touch floor | High | §3.2 |
| 4 | `/media/{slug}/` | `.mvs-social-bar` | overflows viewport by 2px | Low | mask/overflow |
| 5 | `/media/edit-profile/` | (no element) | No back-to-profile link anywhere on the page | High | §6 |
| 6 | `/media/edit-profile/` | `.mvs-fab--chat` | Overlaps `#display-name` input on mobile | Medium | §7 |
| 7 | `/media/{slug}/` | `.mvs-fab` | Overlaps action row | Medium | §7 |
| 8 | `/media/battles/` | `.mvs-battle-matchup-full` (≤640px) | ✅ FIXED in `c0bb8f4` — was forcing single-column | n/a | §8.2 |
| 9 | All detail pages | (no element) | No breadcrumb / back nav between detail and parent route | High | §6 |
| 10 | `/album/{slug}/` | `.mvs-collection-card-actions` | Edit/Delete buttons — height OK but two text labels could collapse to icons on mobile | Medium | §4.3 |
| 11 | `/media/challenges/` | `.mvs-status--voting-open` badge | Wraps to two lines at 390px (badge text "OPEN FOR SUBMISSIONS") | Low | §10 |
| 12 | All routes | `.mvs-toast` | Close `×` is ~24×24, below 44 floor | Medium | §3.2 |

---

## 10. Typography and density

### 10.1 Fluid type

Use **`clamp()`** for fluid sizing on titles. Body copy stays at the theme's
base font-size to inherit theme spacing.

```css
.mvs-page-header__title {
    font-size: clamp(1.25rem, 4vw + 0.5rem, 1.75rem);
    line-height: 1.25;
}
```

### 10.2 Min font-size on mobile

Never go below **0.75rem (12px)** on any user-facing text. Labels under
this floor become unreadable on touch devices and fail WCAG.

### 10.3 Wrap chips and badges, don't truncate

Chips and badges may wrap to a second line on mobile **if** their `min-width`
fits. Truncation hides information. Two-line badges are OK; ellipsis is not.

---

## 11. Modal / sheet patterns

### 11.1 Bottom sheets > centered modals on mobile

The FAB upload modal currently uses a centered card. On `< 768px`, every modal
should slide up from the bottom and pin to the bottom of the viewport for
thumb reach.

```css
.mvs-modal {
    position: fixed;
    inset: 0;
    z-index: var(--mvs-z-modal);
    background: var(--mvs-overlay);
    display: flex;
    justify-content: center;
    align-items: flex-end;     /* bottom-pin on mobile */
}

.mvs-modal__panel {
    width: 100%;
    max-width: var(--mvs-content-base);
    background: var(--mvs-bg);
    border-radius: var(--mvs-radius-lg) var(--mvs-radius-lg) 0 0;
    max-height: 90vh;
    overflow-y: auto;
    transform: translateY(100%);
    animation: mvs-slide-up var(--mvs-duration-base) var(--mvs-easing) forwards;
}

@keyframes mvs-slide-up {
    to { transform: translateY(0); }
}

@media (min-width: 768px) {
    .mvs-modal {
        align-items: center;     /* center modal on desktop */
    }
    .mvs-modal__panel {
        max-width: 560px;
        border-radius: var(--mvs-radius-lg);
        animation: mvs-fade-in var(--mvs-duration-base) var(--mvs-easing) forwards;
    }
}
```

### 11.2 Drag handle

Bottom sheets must show a visual **drag handle** (4px tall pill) at the top
so the affordance to dismiss is obvious.

```html
<div class="mvs-modal__panel">
    <div class="mvs-modal__handle" aria-hidden="true"></div>
    ...
</div>
```

```css
.mvs-modal__handle {
    width: 36px;
    height: 4px;
    background: var(--mvs-text-muted);
    border-radius: var(--mvs-radius-pill);
    margin: 8px auto 12px;
}
```

---

## 12. Accessibility (non-negotiable)

| Requirement | How |
|---|---|
| Focus-visible ring on every interactive element | `:focus-visible { outline: 2px solid var(--mvs-primary); outline-offset: 2px; }` — shipped in `frontend.css` |
| Every icon-only button has `aria-label` | required by `.mvs-btn--icon-collapse` pattern |
| Tooltips show on `:focus-visible`, not just `:hover` | see §4.2 |
| Reduced motion | wrap animations in `@media (prefers-reduced-motion: no-preference)` |
| Color contrast | AA minimum (4.5:1 body, 3:1 large) — use only the token palette |
| Keyboard reach for FAB | `tabindex` order matches visual order; `aria-label="Upload media"` |

---

## 13. PR checklist (paste into every mobile-touching PR)

```
- [ ] Tested at 390×844 viewport in Playwright MCP
- [ ] No horizontal scroll on the body or any non-explicitly-scrolling container
- [ ] Every interactive element ≥ 44×44 (or extended hit area via padding)
- [ ] Every dense action row (3+ buttons) collapses to icons on < 768px
- [ ] Every detail page has a back affordance (`.mvs-back-link`)
- [ ] No FAB overlaps a form input or content button
- [ ] Active tab in any horizontal strip is auto-scrolled into view
- [ ] All new colors/sizes/spacing reference tokens — no inline hex/px
- [ ] Tooltips work on `:focus-visible`, not just hover
- [ ] Tested in dark mode (`html.dark-mode`) and light mode
```

---

## 14. Implementation order

The audit findings ranked by impact for the next refactor sweep:

1. **High** — §6 back nav (affects every detail page, breaks the navigation model)
2. **High** — §3 touch target floor (Edit/Delete on single media, toast close)
3. **High** — §5 dashboard tabs auto-scroll + edge-fade
4. **Medium** — §7 FAB safe-area padding (overlap on edit-profile, single media)
5. **Medium** — §4 icon-collapse on action rows
6. **Low** — §10 badge wrap on `/challenges/`

Each item should be a single commit that touches the **base layer**
(`frontend.css` and a small set of templates) — never a per-page CSS override.

---

## 15. Compliance enforcement

A future PR should add a CI check that runs Playwright at 390×844 across all
routes in section §9 and **fails** if any element exceeds the viewport width or
any interactive element reports < 44px in either dimension. Until that exists,
the §13 checklist is the contract.
