# UX audit — member-facing + admin surfaces (Free + Pro)

_2026-08-29 · mediaverse.local · Reign 
 + Astra 4.13.10 · 1440px + 390px · light + dark + RTL spot-check_

Findings only. **No code was changed.** Site restored to Reign / light mode; both repos clean.

---

## How this was run, and why the numbers can be trusted

Two layers, per the `ux-audit` skill: a grep pass (§1) and a rendered-surface pass (§0). The
rendered pass is the one that found everything below — and it was itself wrong three times before it
was right, which is worth recording, because the same traps will catch the next person.

**The measurement probe produced three false failure classes before it was trustworthy:**

| Probe bug | What it claimed | Reality |
|---|---|---|
| `vis()` checked the element's own opacity, not its ancestors' | 5 × "invisible text" on Explore grid stats | White text inside a **hover overlay at `opacity: 0`**. Correct on hover. |
| Colour parsed with `match(/[\d.]+/g)` | A 13:1 heading scored **1.38** | Reign paints in **`oklch()`**; `oklch(1 0 0)` (white) parsed as `rgb(1,0,0)` (near-black). Now resolved through a canvas. |
| `bgOf()` read only `background-color` | Audio card title scored **1.00** | White text on a **`linear-gradient`** placeholder. The probe now abstains when a `background-image` sits between text and backdrop. |

False failures per surface fell from ~18 to 0–3 once fixed. **Every finding below survived all three
corrections and was then confirmed by looking at the rendered screen.**

---

## Findings, most severe first

### 1. [HIGH] Pro competition headings are invisible in dark mode

`ux-03-challenges-h1-dark.png` — the page `<h1>` "Photo Challenges" is **absent from view**; only its
subtitle renders.

| Surface | Heading | Dark-mode contrast |
|---|---|---|
| `/media/challenges/` | `<h1>` "Photo Challenges" | invisible |
| `/media/challenges/` | `h3.mvs-card-title` | **1.21** |
| `/media/battles/` | `<h1>` "Photo Battles" | invisible |
| `/compete/` | `<h1>` "Compete" | invisible |
| `/media/tournaments/` | `<h1>` "Tournaments" | invisible |

**Root cause, proven at runtime.** `.mvs-card-title` (`gamification.css:173`) sets font-size, weight
and margin — **and no `color`**. Its sibling `.mvs-card-desc` (`:805`) sets
`color: var(--mvs-text-secondary, …)` and flips correctly. The card itself sets
`color: var(--mvs-text, #1e1e1e)`, so inheritance *should* work — but the title is an `<h3>`, and a
theme rule targeting `h3` beats an inherited value. Measured in dark mode:

```
themeH3      rgb(32, 38, 46)   ← Reign's heading colour, does NOT flip
titleColor   rgb(32, 38, 46)   ← identical: the theme rule wins
cardColor    rgb(197,200,205)  ← the card's own token, correctly flipped
descColor    rgb(167,167,167)  ← flips, because it sets its own colour
```

**This is a class, not five bugs — and the boundary is now known.** A second sweep across album,
collection, single-media and messages found **zero** heading failures: on those surfaces the theme's
heading colour resolves to white in dark mode. On the four competition pages it resolves to
`rgb(32,38,46)`.

The difference is the template path. The competition pages render through
`GamificationTemplateLoader` (standalone templates), which puts them **outside the container Reign's
dark-mode heading rule scopes to** — so the theme's *light* heading colour applies while our
container is dark. Every other member surface renders inside the theme's normal content area and is
therefore fine.

So the fix has two candidate shapes, and the choice matters: give `.mvs-card-title` and the
competition `<h1>` an explicit token colour (fixes it everywhere, including on themes we have never
seen), or bring those templates inside whatever wrapper the theme scopes to (fixes it on Reign, and
leaves the next theme to re-discover it). The first is the one that survives Astra.

### 2. [HIGH] The active tag chip is the least readable thing on Explore

`ux-01-tag-cloud-active-contrast.png`.

| Mode | Text | Background | Ratio |
|---|---|---|---|
| Light | `rgb(64,72,85)` | `rgb(29,118,218)` | **2.05** |
| Dark | — | — | **1.13** (effectively invisible) |

The active state sets `background` to the accent **and leaves the foreground at the inactive
colour**. The *selected* filter — the one the member most needs to see — is the hardest to read, and
in dark mode it disappears. Same shape as finding 1: a state that changes one half of a colour pair.

**Blast radius: three surfaces.** The same chip renders on Explore, on `/media-tag/{slug}/` archives
(measured "nature" at 2.05) and on member profiles — so it is the most-seen defect in this report.

### 3. [MEDIUM, systemic] Contrast depends on the host theme, and there is no floor

The failure set **changes with the theme**, because `--mvs-primary` and friends derive from whatever
the theme exposes (`--reign-site-button-bg-color`, `--button-background-color`, …).

| Element | Reign | Astra |
|---|---|---|
| `.mvs-btn--primary` "Enter Challenge" | passes | **4.29** ✗ |
| `.mvs-drive__upload-button` "Upload" | passes | **4.29** ✗ |
| `.mvs-tag-cloud-item.active` | **2.05** ✗ | 4.23 ✗ |
| `.mvs-dashboard-tab__label` | 4.36 ✗ | 3.93 ✗ |
| `.mvs-drive__meta`, `__chip`, `__tab`, `.mvs-panel-toolbar__count` | ~4.36 ✗ | 3.90–4.26 ✗ |

On Astra our **primary call-to-action fails AA**. Per the standing rule — most owners do not run our
themes — this is the finding with the widest blast radius: we inherit a colour we never check.

### 4. [MEDIUM] Tap targets under 40px, on desktop only

Mobile is compliant (`gamification.css` raises controls to `--mvs-touch-min` under `max-width:1023px`);
desktop is not, so the 40px contract holds at exactly the viewport where it was written and nowhere else.

`mvs-search-mode-btn` 66×26 · `mvs-tag-cloud-item` 44×31 · `mvs-dashboard-tab` 202×37 ·
`mvs-dashboard-rail-head__link` 81×19 · `mvs-competition-tab` 75×36 · `mvs-btn` 138×38 (Astra)

### 5. [MEDIUM] Upload modal uses placeholders as labels

Four controls, both viewports, both themes: `mvs-upload-meta-title`, `-desc`, `-tags`, `-privacy`.
No `<label for>`, no `aria-label`. The placeholder vanishes on first keystroke, so a screen-reader
user and a returning user both lose the field's identity. (`ux-audit` §2 names this exact
anti-pattern.)

### 6. [LOW, systemic] Breakpoint sprawl — the shape that produced today's mobile bug

Contract says 3. Actual distinct `@media` widths:

- **Free: 14** — 380, 480, 600, 640, 680, 767, 768, 782, 860, 861, 960, 1023, 1024, 1200
- **Pro: 9** — 480, 560, 600, 640, 767, 768, 782, 900, 1023

Note the near-duplicate pairs (767/768, 1023/1024, 860/861). A rule written at one and not its twin
is precisely how card 10248974871 happened this morning — a `margin` shorthand inside one breakpoint
silently clobbering a base value.

### 7. [LOW] Token drift

Bare hex outside a `var(--token, #fallback)` fallback: **Free 285**, **Pro 252**. Hardcoded `rgba()`:
Free 112, Pro 51 (focus rings and shadows that cannot respond to dark mode). Concentrated, not
smeared — `gamification.css` 205, `frontend.css` 161, `shared-ui-frame.css` 40. `gamification.css`
being the worst file is consistent with findings 1 and 4 both landing there.

### 8. [TOOLING] The shared audit script's F8 gate is 7/7 false positives

Every "block" violation it reported in both plugins is a **comment** explaining that native dialogs
are banned:

```
| block | F8 native-alert-confirm | assets/js/frontend/mvs-confirm.js:2 |
        ` * Frontend confirmation modal — promise-based replacement for window.confirm(`
```

F1/F2 filter comments; F8 does not. Verified: **zero real `alert`/`confirm`/`prompt` calls** in
either plugin. A gate that cries wolf every time it fires is a gate people learn to skip — worth
fixing in `~/.claude/skills/ux-audit/templates/ux-audit.sh` rather than per-plugin.

### 9. [MEDIUM] Anonymous visitors: the sign-in call-to-action fails contrast

Checked in a fresh browser context (`logged-in` absent from `<body>`, so the state is genuine).

| Element | Surface | Issue |
|---|---|---|
| `a.mvs-btn--primary` "Log In" | logged-out banner, Explore | **3.66** contrast, both viewports |
| `button.mvs-logged-out-banner__close` | logged-out banner | **20×28** tap target |
| `a.mvs-auth-gate__secondary` | `/my-media/` auth gate | **263×24** tap target |
| `a.mvs-btn--primary` | `/my-media/` auth gate | 240×**38** |

The banner and gate are the *only* things a visitor sees before deciding to join, and its primary
button is the least legible element on the page. Nothing leaks — no member-only control renders for
anonymous, and every surface returned 200 with no layout breakage.

---

## Admin pass — all 18 MediaVerse admin screens

All 18 return 200 as admin. Two findings here are more severe than anything on the member side.

### A1. [HIGH] Every attention badge on the Competitions dashboard is invisible

`ux-04-attention-badge-invisible.png` — the row renders "Next autopilot challenge: 'Motion' · View →"
with **blank space where the badge should be**.

Measured: `color: rgb(255,255,255)` on `background: rgba(0,0,0,0)`, no background-image, every
ancestor transparent — so white text lands on the white admin page.

**Root cause: the PHP and the CSS use two different vocabularies, with zero overlap.**

| | Values |
|---|---|
| `CompetitionsDashboard.php` emits (`:611-716`) | `danger`, `warning`, `success`, `info` |
| `gamification.css` defines (`:1585-1589`) | `ending`, `low`, `completed`, `autopilot`, `battle` |

Not one value matches. `.mvs-attention-badge--info` does not exist, so the element falls back to the
base rule — which sets white text and expects a variant to supply the background. **No badge on this
dashboard can ever be styled**, and the badge whose text reads "AUTO-PILOT" is emitted as `--info`
while the CSS is sitting there with an unused `--autopilot` rule.

This is a `wp-contract-audit`-class defect (a key read but never written) wearing UX clothing, and it
is invisible to a grep UX audit: both files are individually valid.

### A2. [HIGH] Three admin screens scroll horizontally at 390px

The first real layout breakage in the whole audit. The responsive contract says the page body must
never scroll horizontally.

| Screen | Offender | Measured |
|---|---|---|
| Overview (`?page=wpmediaverse`) | `.mvs-admin-widget` | **395px inside a 380px content area** → document 405px, 15px of scroll |
| Logs (`?page=mvs-logs`) | `table.mvs-log-table` | **470px** |
| Settings (`?page=mvs-settings`) | `.mvs-settings-sidebar` | **392px** |

`?page=mvs-quotas` has a 739px table overflowing its container but **no page-level scroll** — that one
is contained, so it is a lower severity than the three above.

### A3. [MEDIUM] Admin controls sit below even the relaxed admin floor

`ux-foundation` allows a 34px density exception on compact admin rows. These are under it:

`mvs-admin-tab` **32px** (moderation, stats, quotas, challenges, tournaments, battles — six screens) ·
`mvs-range-btn` **30px** · `mvs-settings-nav-item` **30–32px** · `mvs-btn--sm` **28px** ·
`mvs-welcome-banner__dismiss` **26×29** · `mvs-attention-link` 44×**18** · `mvs-fam-header__link` 208×**18** ·
`mvs-setup-skip` 64×**18** · `mvs-cb-select-all` **16×16** at 1440

### A4. [MEDIUM] Status badges are the admin's weakest contrast, consistently

A pattern, not scattered incidents — coloured text on a tinted background, everywhere the admin
reports state:

`mvs-status-value--warn` "Not set" **2.22** · `mvs-log-level--warning` "WARNING" **2.22** ·
`mvs-stat-number` "62 MB" **2.22** · `mvs-status-badge--success` "Connected" **2.95** ·
`mvs-status-badge--active` **3.05** · `mvs-status-ok` / `mvs-media-badge` / `mvs-theme-status` **3.35** ·
`mvs-status-badge--inactive` 3.68 · `mvs-settings-nav-item.is-active` 3.87 · `mvs-admin-tab.is-active` 4.40

The irony is worth stating: the text that tells the owner something is **wrong** ("Not set",
"WARNING") is the least legible text on the page.

### Clean admin screens

`mvs-documents` and `mvs-stories` reported **nothing** at either viewport. Everything else has at
least one row above.

**Unlabelled inputs** were reported on `mvs-media`, `mvs-tags` and `mvs-settings`, but most are WP
core list-table controls (`input.button.action`, the bulk-action `select`) rather than ours —
`input.small-text` on Settings is the one worth checking. Not counted as a finding pending that split.

---

## Second-peer pass — member-A viewing member-B (clean)

Ran as `mvs_gate_b` in a fresh context (`logged-in` confirmed), across five surfaces that all belong
to somebody else: another member's profile `/media/@journey_blocker/`, their single media, an album
owned by a third user, a collection, and a `/media-tag/` archive.

**Nothing leaked.** Zero owner-only controls rendered — no bulk tick box, no edit/delete, no privacy
select, no row menu — and zero write-labelled controls of ours appeared on any of the five. No
horizontal scroll. This is the axis where permission bugs usually hide, and the own-items-only
discipline held everywhere, including the Explore tick boxes added earlier today.

The only defects seen as a peer were ones already recorded above (the active tag chip, and small tap
targets on album/collection author links).

---

## Lightbox pass — the surface with the worst accessibility story

Opened the way a member does (clicking a grid tile), then measured it as a surface in its own right.

### L1. [HIGH] The lightbox is a modal that never says it is one

```
role                        null
aria-modal                  null
aria-label / -labelledby    null
body scroll locked          "hidden"   ← it BEHAVES as a modal
```

It takes over the viewport, locks page scroll and overlays everything — and carries no dialog
semantics whatsoever. A screen-reader user gets no announcement that a dialog opened, no name for it,
and no boundary telling them where it ends.

### L2. [HIGH] Focus is never moved into it, and never trapped

```
focusable elements inside          27
focus inside after opening         false
```

Opening the lightbox leaves focus behind in the grid. A keyboard user presses Enter on a tile, the
overlay appears, and their next Tab walks the **page behind the overlay** — through 27 reachable
controls they cannot see. There is no focus trap and no return-focus-on-close.

Escape does close it (verified separately today), which is the one modal behaviour that is present.

Together L1 and L2 mean the lightbox is usable with a mouse and effectively unusable with a keyboard
or a screen reader. This is the largest single accessibility gap found in the audit.

### L3. [MEDIUM] Tap targets and a nameless control inside the lightbox

`mvs-lightbox-comment-action` **24×24** (the comment edit/delete icons) ·
`mvs-lightbox-reaction` **48×29** and **36×29** · `mvs-lightbox-comment-post` 66×**34** ·
`mvs-lightbox-author-link` 347×**36** · `mvs-lightbox-comment-author-link` 126×**27**

`mvs-lightbox-comment-avatar-link` has **no accessible name** — unlike the earlier 390px case, this
one is ours.

---

## Admin colour schemes — a closed question, not a gap

WordPress ships eight admin colour schemes, two of them dark. Ran the admin probe with
**`admin-color-midnight`** active (confirmed on `<body>`): the results were **identical to the
default scheme** — same elements, same ratios, same counts.

That is correct WordPress behaviour, not a defect: colour schemes recolour admin *chrome* (menu,
links, buttons), not plugin content panels. So this axis needs no further work, and the earlier note
calling it an open gap was wrong. **"Admin on Astra" is likewise not a thing** — wp-admin does not
load the frontend theme.

---

## What is genuinely clean

- **No horizontal scroll** on any surface, at 1440 or 390, on Reign or Astra.
- **No overflowing elements, no missing `alt`, no unnamed icon-only controls of ours.** The one
  nameless link that appears at 390px on every surface is Reign's own mobile header avatar
  (`.user-wrap > a > img[alt=""]`, no `mvs-` prefix) — theirs, not ours.
- **Album, collection, single-media and messages are clean on every axis tested** — no contrast,
  target, label or dark-mode failure at either viewport.
- **No native browser dialogs** anywhere (the confirm/toast toolkit is used consistently).
- **Dark mode plumbing works** — the token bridge follows the theme's real toggle
  (`data-bx-mode`), `--mvs-bg`/`--mvs-text` flip correctly. The documents drive scored **zero**
  contrast failures in dark. Findings 1 and 2 are components that opted out of that system, not a
  broken system.
- **RTL is near-clean** — no horizontal scroll, one asymmetric margin (`mvs-drive__newfolder`).

---

## Coverage — what was looked at, and what was not

**Looked (11):** `/explore-media/` (populated + zero-results), `/my-media/`, `/my-media/documents/`,
`/compete/`, `/media/challenges/`, `/media/battles/`, `/media/tournaments/`, `/media/{slug}/`,
`/album/{slug}/`, `/collection/{slug}/`, `/messages/`.

Plus `/media/@{user}/` profile and `/media-tag/{slug}/` archive from the peer pass, and the
**lightbox** as its own surface — **14 member surfaces**.

**Roles:** member/admin, **anonymous**, and **a second peer** — all three exercised.

**Pending — never viewed, and therefore a gap, not coverage:**

- BuddyPress `/members/{user}/media/` and `/groups/{slug}/media/`
- Loading and error states; only empty and populated were exercised
- Dark mode was checked on 9 of the member surfaces; the **lightbox was not checked in dark**
- Admin was viewed as full admin only — no editor/shop-manager role

`qa/inventory/WHAT-TO-CHECK.md` lists 74 member surfaces. **14 looked, 60 pending.**
Admin: **18 of 18 looked** at 1440 and 390.
