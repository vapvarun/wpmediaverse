# Rendered-surface audit — MediaVerse-owned screens

Per `ux-audit` §0: the rendered screen is the unit of truth. A row is `looked` only
when opened in a browser and examined. `pending` is a gap, never coverage.

Scope: **MediaVerse-owned surfaces only.** `/gamification/` (wb-gamification),
`/activity/` `/messages/` (BuddyPress/BuddyNext) are explicitly OUT — not our
surface, not audited here.

Desktop pass (1440×900, Chrome, BuddyX-Pro, admin). Mobile/dark/RTL/logged-out/peer
passes still pending on every row.

Updated 2026-07-08 (after Phase 1 sidebar fix).

## Frontend screens

| Screen | Route | Looked | Sidebar | Shell | .mvs-card | Empty prim | Gutter/side | Bespoke families |
|---|---|---|---|---|---|---|---|---|
| Explore feed | `/explore-media/` | ✅ | none | yes | **no** | no | **413px** | 135 |
| My Media | `/my-media/` | ✅ | **none (fixed)** | yes | **no** | no | 8px | 179 |
| Upload | `/upload-media/` | ✅ | none | yes | **no** | — | 8px | — |
| Single media | `/media/{slug}/` | ✅ | none | yes | **no** | no | 8px | 101 |
| Profile | `/media/@{nicename}/` | ✅ | none | yes | **no** | no | 8px | 148 |
| Album | `/album/{slug}/` | ✅ | none | yes | **no** | no | 0px | 68 |
| Compete hub | `/compete/` | ✅ | none | yes | **no** | yes | 8px | 75 |
| Challenges list | `pro-challenges-list` | ✅ | inherits | — | no | yes (bare) | — | — |
| Challenge detail | (deep-link) | ✅ | inherits | — | no | yes (bare) | — | — |
| Collection | `/media/collection/{slug}/` | pending | | | | | | |
| Stories | `pro-stories` | pending | | | | | | |
| 12 Free blocks (standalone) | `src/blocks/*` | pending | | | | | | |
| 12 Pro blocks (standalone) | `src/blocks/pro-*` | pending | | | | | | |
| Admin pages (17 Free + 10 Pro) | `wp-admin` | pending | | | | | | |

**Looked: 9 frontend. Pending: collection, stories, 24 blocks standalone, 27 admin.**

## Findings that hold on EVERY MediaVerse-owned screen looked at

### 1. Phase 1 fixed the sidebar everywhere — consistently

All three block-backed pages (my-media / explore-media / upload-media) now render
sidebar-free via the theme's own no-sidebar page template. The rewrite-based routes
(single / profile / album) were already sidebar-free (they load plugin templates
directly with get_header/get_footer). So the whole product is now consistent:
**no theme blog sidebar on any MediaVerse surface.** No horizontal overflow on any
screen. Zero Dashicons on the frontend.

### 2. `.mvs-card` is defined 74× in CSS and used on ZERO frontend screens

Every screen hand-rolls its containers. Bespoke `mvs-*` component families outside
the canonical vocabulary, per screen: 179 / 148 / 135 / 101 / 75 / 68. The
primitive ships; nothing consumes it. This is the single structural cause of "reads
as formal work, not premium" — there is no shared container language, so every
surface looks subtly different.

### 3. Explore feed is a 614px column with 413px dead gutter each side

57% of a 1440 viewport unused. `.mvs-page` shell spans 1425px; the feed column does
not use it. This is the biggest visible desktop gap and the one that needs a design
decision (what, if anything, fills a second column) before it can be fixed well —
which is why the desktop-grid phase is gated on finishing this sweep.

## Not touched (per direction)

- BuddyNext token bleed (`--mvs-bg` mint) — BN's surface, not ours.
- `--mvs-accent` — LIVE on ~50 surfaces (admin accent, follows tab, modal summary,
  gamification, story rings). NOT dead; the "delete it" plan was a false premise and
  is cancelled. Its raw-hex declaration is a Rule 6 item for a future visual task,
  not a deletion.
