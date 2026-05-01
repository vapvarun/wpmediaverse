# Phase 0c — Flow-Test E2E Suite

Three Playwright specs that catch URL-signing leak classes which static
analysis (PHPCS, PHPStan, regex CI guards) cannot reach.

## Why these exist

The 1.1.3 lightbox bug exposed a blind spot: a raw
`/wp-content/uploads/wpmediaverse/...` URL was composed at render-time
inside the Interactivity API store. Every static check passed. Only a
flow-test that clicks the thumbnail and inspects the network panel could
catch it.

These specs encode the contracts Phase 0a and 0b enforce, plus the
"derive-cover-from-entries" UX fallback Phase 0b preserves.

## The three specs

| Spec | What it catches |
|---|---|
| `upload-sign-serve.spec.ts` | Raw URLs leaking from any thumbnail or lightbox emission. Asserts every wpmediaverse-served URL carries `mvs_sig=` and that the lightbox full-file fetch returns 200/206 (never 403). |
| `bp-activity-render.spec.ts` | Short-TTL signed URLs baked into BP activity HTML expiring after 1h and 403'ing for anyone who opens an old activity. Asserts at least one image uses `mvs_uid=0` with expiry > 30 days (broadcast TTL). |
| `challenge-cover-fallback.spec.ts` (Pro-paired) | `<img src="">` rendering when a Pro challenge has no cover. Asserts every card either has a real signed cover URL or the `data-wp-class--mvs-card-cover-wrap--placeholder` class binding. |

## Running locally

```bash
npm install                         # picks up @playwright/test from package.json
npm run test:e2e:install            # one-time Chromium download (~150 MB)
npm run test:e2e                    # runs all 3 specs against http://mediaverse.local
MVS_SITE_URL=http://localhost:8888 npm run test:e2e   # against wp-env or other host
npm run test:e2e:headed             # debug mode — watch the browser run
```

## Running in CI

`.github/workflows/e2e.yml` boots a fresh wp-env with Free + Pro +
BuddyPress active, then runs the suite. Failure blocks merge.

The workflow needs the `PRO_REPO_TOKEN` secret to fetch Pro from its
private repository (same secret the unit-test workflow uses).

## Authentication

Specs use the `?autologin=1` URL parameter, which the project's
`dev-auto-login` mu-plugin honours (see global CLAUDE.md). On wp-env or
a clean install where that mu-plugin is absent, drop a copy at
`wp-content/mu-plugins/dev-auto-login.php` before running.

## Adding new specs

Per the Phase 0c plan:

> Document: this is the layer-2 verification that the grep CI guard
> explicitly cannot do — flow-tests catch what text-pattern matching
> misses.

When a future bug slips past static analysis because the leak is composed
at runtime, write a flow-test here that catches it. Keep specs focused —
one signal per file.
