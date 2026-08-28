# wppqa baseline — 2026-08-28

Plugin: WPMediaVerse 2.4.0 (branch 2.4.0) · Maturity **COMPLETE (73/100)** · Code Quality **C- (59/100)**
Run: `mcp__wp-plugin-qa__wppqa_audit_plugin` (no `site_url`, so API/DB/browser/visual checks did not run).

Refreshes the 2026-08-08 baseline, which had aged past the 14-day cap and was
failing local-CI stage 2.4 on every push (Basecamp 10245257103, stage 4).
**This is a baseline, not a to-do list** — it records the state the current work
leaves behind, so any new finding is attributable.

## Headline

| Gate | Errors | Warnings |
|---|---|---|
| Code quality (14 checks) | **0** | 3 |
| Product quality (6 checks) | 38 | 172 |
| Systemic misalignment (7 checks) | 43 | 1865 |

557 checks run · 468 passed · 81 failed · 7 flagged "fix before release".

**Code quality is clean**: PHPCS 0/0, PHP-lint 312/312, composer-audit, i18n,
plugin-check, phpcompat, bundle-size all pass. PHPStan/ESLint/Stylelint report SKIP
here because this runner does not invoke them — they are covered by local-CI stages
1.2/1.3 and are green there.

## Known false positive to ignore

- **marketing: "Version mismatch: readme 2.4.0 vs header 3.9.3"** and the audit
  banner reading "Action Scheduler v3.9.3". The scanner picks up the header of the
  bundled runtime dependency under `libs/action-scheduler/` rather than the plugin's
  own `wpmediaverse.php` header (2.4.0). The plugin version is 2.4.0; there is no real
  mismatch. Same artefact will recur every run until the scanner is taught to read the
  entry file specifically.

## The baseline, by band (accepted pre-existing state)

Nothing below is new to this release; it is the standing surface the bug-finder
reports each run, recorded so a genuine regression stands out against it.

- **a11y (28 errors)** — the bulk is `outline:none` without a `:focus-visible`
  replacement across admin/frontend/bp CSS (counted once per source and once per
  `.min`), plus admin form inputs without labels (`CollectionMetaBox`,
  `IntegrationsPage`) and `<img>` without `alt`. Long-standing admin-surface a11y debt.
- **admin-eval (3 errors)** — direct `$_POST` to `update_option` in admin handlers
  (bypasses the Settings API). Pre-existing admin pattern.
- **frontend-eval (3 errors)** — modal/popup close-button heuristic misses on three
  interactivity-driven modals that close via `data-wp-on--click` rather than a
  `.close`/`×` selector the grep looks for.
- **marketing (4 errors)** — the version false positive above, plus no
  `.wordpress-org/` banner/icon/screenshot assets (not shipped from this repo).
- **wiring (3), enum-consistency (9), rest-js-contract (7)** — the same drift the
  document-library plan already tracks (canonical enum lists not centralised;
  a handful of JS keys read from response shapes the grep cannot match, several of
  which are messaging fields present at runtime). No new drift this release.
- **ux-guidelines (7) / plugin-dev-rules (16)** — dashicons in a few admin views that
  predate the Lucide runtime, and nonce-checks paired with a capability check the
  heuristic cannot see because the `current_user_can()` sits one call up. Reviewed;
  not acted on here.
- **qa-coverage (1)** — "audit/manifest.json missing" is a path the scanner expects;
  this plugin keeps its manifest at `audit/manifests/manifest.json`.

## Gates this baseline is NOT

Code quality (PHPCS/PHPStan/lint) is green and enforced per-push by local-CI stages
1.x. This file exists only to satisfy stage 2.4's freshness check and to give the
next contributor a dated snapshot to diff a real regression against.
