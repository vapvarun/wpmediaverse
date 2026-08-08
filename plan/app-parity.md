# Plugin ↔ app functionality catalogue

**Living document.** Update a row in the PR that moves it.

| | |
|---|---|
| **Plugin** | WPMediaVerse — Free `mvs/v1` + Pro `mvs-pro/v1` |
| **App** | `~/apps/mediaverse-app` · `main` |
| **First built** | 2026-08-08 |
| **Live routes** | 152 (91 Free + 63 Pro) |
| **Called by the app** | **91 of 152** |
| **App screens** | 32 |
| **App `❌ Missing`** | **3 areas — the gate is not green** |

## Why this file exists

The capability catalogue is **plugin-owned** (`CAPABILITIES.md`, per rule 7 of the
`wbcom-mobile-app` skill). The app never re-enumerates features — it maps coverage against this
spine. This file is the other direction: what the plugin still owes the app.

The companion release gate is `mediaverse-app/docs/FEATURE-COVERAGE.md`.

---

## Headline

This pair is the fleet's **App Store compliance reference**, and that part is genuinely done:
reporting, blocking, account deletion and the legal block are all implemented and wired to
`/app/config`. Every other app is told to copy it.

What nobody was tracking is the ordinary product surface underneath. Building the matrix for the
first time shows **61 of 152 routes uncalled**, and most are correctly out of scope — but three
member-facing areas are absent with no recorded decision:

| Gap | Size | Why it matters |
|---|---|---|
| **The network feed** — `GET /feed` | 1 route | A social media app with no following feed. The home tab is a hub; discovery is Explore-only |
| **The competition suite** — battles, challenges, tournaments, groups | 27 routes | The app *declares the feature flags* in `api/config.ts` and ships no screens. On a site with them ON, the member sees nothing |
| **Social graph and profile detail** — 8 `/me/*` routes | 8 routes | A member cannot see who follows them, who they follow, their favourites or their stats |

**The flags-without-screens case is the one to fix first**, and not only for the missing feature: a
flag that reads as supported and delivers nothing is worse than an absent flag, because it makes the
gap invisible to everyone reading config.

---

## Where the 61 uncalled routes go

| Class | Count | Verdict |
|---|---|---|
| Competition suite (battles, challenges, tournaments, groups, summary, streak-freeze) | 27 | ❌ Missing — flags exist, screens do not |
| Social graph + profile `/me/*` | 8 | ❌ Missing (2 of the 8 are monetization reads — see below) |
| Messaging extras (unsend, reactions, media upload, poll, edit) | 5 | ⚠️ Partial — the thread works, these do not |
| Admin moderation (queue, counts, approve, reject, analyze) | 5 | 🚫 Web-only — member-side report/block **are** implemented |
| Infrastructure (transcode ×2, serve, access options) | 4 | 🚫 Web-only |
| Analytics ×2, AI usage | 3 | 🚫 Web-only — owner surface |
| Credit packages ×2, credit webhook | 3 | 🚫 Web-only — Apple IAP |
| Plugin-side auth (`/auth/app-password`, `/auth/nonce`) | 2 | 🚫 By design — core Application Passwords (skill rule 1) |
| Admin welcome dismissals | 2 | 🚫 wp-admin only |
| `/feed` | 1 | ❌ Missing |
| `/privacy/presets` | 1 | ⚠️ Partial |

---

## What the plugin owes the app

### 1. Nothing publishes the competition feature set

The app hardcodes `battles`, `challenges`, `tournaments`, `streaks` as flags in `api/config.ts`. They
default `false`, which is correct fail-closed behaviour — but the app is *guessing the flag names*.
If `/app/config` published the competition feature block the way it publishes the legal block, the
app could not drift, and a site enabling a competition type would have a defined contract to render
against.

### 2. Read-only credit history needs a decision, not an assumption

`/me/credits/history` and `/me/transactions` are currently lumped with "monetization → web-only under
Apple's IAP rules". But **reading** a balance history is display, not purchase — Listora ships credit
balance and packs read-only for exactly this reason. Either publish these as app-readable and let the
app show history, or record the decision that it stays on the web. Right now it is neither.

### 3. Enum declarations, so faithfulness becomes checkable

The Jetonomy pass found that when a route declares an `enum`, an app's hardcoded list can be diffed
against it mechanically — five of seven lists cleared instantly and the two without enums were the
ones nobody could verify. Declaring enums on this plugin's status/type/reason arguments turns the
same check from an opinion into a script.

---

## Faithfulness — not yet run

This matrix catches **absence**. It does not catch **divergence** — a screen that exists, works, and
shows something the site never said still scores ✅. That check has **not** been run against this
app, and it is the next thing after the Missing rows.

Method that worked on Jetonomy: diff every hardcoded list in app source against the live route
schema's `enum`. Candidates here: media visibility, reaction types, report reasons, notification
types.

**One warning from doing this exercise.** A first pass at the route diff on Jetonomy reported 30
uncalled routes including `/search` — on an app with a `search.tsx` screen. The extraction regex
matched one call shape and missed others; the real number was 8. On this app the same first pass
missed three whole `api/` modules. **A generated list is not evidence** — spot-check it against the
source before writing any row that says "missing".

---

## Verification status

| Level | State |
|---|---|
| Route reachability | ✅ 152 routes probed live across both namespaces |
| App endpoint usage | ✅ all 23 `api/` modules |
| Screen inventory | ✅ 32 screens |
| Enum faithfulness | ❌ not run |
| **Runtime behaviour** | ❌ not exercised |
| Ban gate (skill rule 2) | ❌ untested — a banned member holding a valid app password must 403 on every write |
