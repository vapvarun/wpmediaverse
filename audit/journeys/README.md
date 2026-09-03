# Customer Journeys — verification framework

## Why journeys, not unit tests

A PHPUnit test that mocks a controller method's response shape passes even if the JS that consumes it never actually wires the redirect. It tests **the unit**, not **the user's reality**.

A journey is a contract that says: _"As a logged-in user named X, on resource Y, when I do Z, I should land in state Q within 3 seconds."_ Passing means the whole stack works — REST + JS + DOM + DB write — for an actual customer.

Journeys also cost less than the equivalent test suite. Each journey is a single self-contained markdown file. A cheap LLM agent can execute it end-to-end via Playwright + curl + MySQL, returning PASS/FAIL with the exact failure point. Re-running 30 journeys per release is cheaper than maintaining 200 unit tests.

## Schema

Each journey is one markdown file with YAML frontmatter:

```yaml
---
journey: <slug-with-dashes>
plugin: wpmediaverse
priority: critical | high | normal | nice-to-have
roles: [<role-1>, <role-2>, ...]
covers: [<bug-or-feature-tag>]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "<other setup needed>"
estimated_runtime_minutes: 5
---
```

Followed by:

- **## Setup** — initial state needed (autologin URL, IDs, fixtures)
- **## Steps** — numbered, each with: action, expectation, REST/DB checks
- **## Pass criteria** — ALL listed assertions must hold
- **## Fail diagnostics** — for each likely failure, point at the suspected file

## How an agent executes a journey

A journey-aware agent (today: a `general-purpose` agent with Playwright MCP + curl + mysql_query MCP loaded; tomorrow: a `wppqa_run_journeys` MCP tool) reads the file, then for each step:

1. **Action** — typically a `playwright_navigate` / `playwright_click` / `curl -X` / `mysql_query` call.
2. **Expectation** — assertion on the resulting state (DOM contains text, REST returns shape, DB row updated).
3. **On match → next step.** On mismatch → record actual vs expected + step number + suspected file → exit FAIL.

Output goes to `audit/journey-runs/{YYYY-MM-DD-HHMM}/{journey-slug}.json`:

```json
{
  "journey": "<slug>",
  "started_at": "2026-04-30T18:55:00Z",
  "site": "<url>",
  "outcome": "PASS | FAIL",
  "duration_seconds": 47,
  "steps": [
    { "n": 1, "action": "...", "outcome": "PASS" }
  ]
}
```

When `outcome: FAIL`, include `failure_step`, `expected`, `actual`, and `likely_files`.

## Coverage dimensions every journey must assert

Journeys are written from the **site owner's expectation**: "as the person who
installed this plugin to run a media community, X must work and look right." A
journey is not done until it asserts all applicable dimensions below.

### 1. Functional (always)
The flow produces the correct DB state + REST/DOM result. This is the baseline
every journey already covers.

### 2. Responsive — desktop AND mobile (every frontend/admin render journey)
**Target: 100% mobile ready.** Any journey that renders a frontend or admin
surface MUST run its render/UX assertions at BOTH viewports:
- **Desktop**: 1280x800 (`playwright_resize 1280 800`)
- **Mobile**: 390x844 (`playwright_resize 390 844`) — iPhone 12/13/14 width.

At 390px assert: no horizontal scroll (`document.documentElement.scrollWidth <=
window.innerWidth + 1`), primary actions reachable, tap targets >= 40px, no
content clipped or overlapping, modals/menus usable. Screenshot both viewports
into the run dir so UX regressions are reviewable.

### 3. Translation ready (i18n) — every surface
**Target: 100% translation ready.** For each surface a journey touches, assert
all visible strings come through `__()/_e()/esc_html__()` with the right text
domain (`wpmediaverse` / `wpmediaverse-pro`) — no hardcoded user-facing literals
in PHP or JS; JS strings localized via `wp_set_script_translations()` /
`wp_localize_script`, not inlined.

### 4. Capability / privacy gate (where relevant)
The right role sees the surface; the wrong role / anonymous does not. Private
media never appears to others and is never emitted as a public cloud URL.

**A privacy assertion must request the dangerous URL, not merely read it.** Asking
the API and getting 403 proves the API. It does not prove the file. If a journey
captures a stored path, it must fetch that path, unauthenticated, and require a
non-200 — see `security/05-private-media-local-and-gated.md` steps 5b and 5c, which
were added after a `priority: critical` journey claiming `privacy-gate` passed for
months while every private file on an nginx host was downloadable by anyone.

### 5. Theme independence (every frontend render journey)
Most installs are not on BuddyX, BuddyX Pro or Reign. A journey that only ever runs
on a theme we ship cannot see anything we left to the theme to decide.

Assert on a theme the project does **not** ship (Astra, or a Twenty* default):

- Nothing carrying the HTML `hidden` attribute is visible. Our themes ship a
  `[hidden]{display:none}` reset that loads after us and silently rescues any
  `.mvs-*` rule setting `display`; other themes do not.
- Our controls are sized by us. A control with no width constraint inherits the
  theme's `select{width:100%}` and spans the content column.
- Measure on the **live page** (`offsetParent !== null`), never on markup injected
  into a detached container — an element inside a correctly hidden ancestor computes
  its own `display` in isolation and reports a false positive.

Covered by `customer/38-theme-independence.md`.

### 6. Thresholds come from the code, never restated
If a journey polices a standard the plugin defines — a touch-target floor, a
contrast ratio, a page-size cap — it must **read the value at runtime** and compare
against that. A number retyped into a journey drifts from the token it is policing
and quietly licenses the thing it was written to catch: `customer/08` asserted
"controls >= 40px" against a plugin whose own `--mvs-touch-min` is 44px, and seven
controls sat in the gap.

## Site-owner expectation map (what the suite must cover)

| Area | Owner expectation | Journey dir |
|---|---|---|
| Onboarding | Activation creates pages + wizard; settings save and persist | admin |
| Storage | Switch local/cloud, migrate, counters; private stays local; nothing breaks | admin |
| Moderation | Approve/reject works; pending media hidden from feeds | admin |
| Library | All Media list, bulk actions, optimize, edit | admin |
| AI | Keys actually call the provider; each AI feature has an owner-controlled toggle | admin |
| Upload | Public + private upload from desktop and phone | customer |
| Browse | Explore grid + search/filter + single view + lightbox | customer |
| Social | Reactions, comments, favorites, follows, DM | customer |
| Profile | Member media tab + albums/collections | customer |
| Privacy | Private/members media gated everywhere | security |

## Directory layout

```
audit/journeys/
├── README.md                       (this file)
├── customer/                       End-user flows
│   ├── 01-<flow>.md
│   └── 02-<flow>.md
├── instructor/                     Power-user / staff flows (optional)
├── admin/                          Admin flows
├── security/                       Auth-gate verifications
└── system/                         Cron, webhooks, background
```

## When to write a new journey

Add one when:
- A new customer-facing feature lands (one journey per feature)
- A bug is fixed that wasn't journey-covered (the journey becomes the regression sentinel)
- A REST/AJAX endpoint family changes shape (the journey re-locks the contract)

Don't add one for:
- Internal refactors with no user-visible change (the journey can't tell)
- Performance optimizations (use Lighthouse instead)
- One-off admin scripts run from CLI (use `wp` command tests)

## How journeys integrate with `bin/local-ci.sh`

Stage 4.1 of local-CI runs `bin/run-journeys.sh` against the configured site. Skipped automatically when the site isn't reachable, so the gate works on a fresh clone before WordPress is even installed. To force-run on a non-default site:

```
bash bin/local-ci.sh --site http://staging.local
```

To skip journeys (useful for headless CI without a browser):

```
bash bin/local-ci.sh --no-journeys
```
