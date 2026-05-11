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
plugin: wpmediaverse-pro
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
