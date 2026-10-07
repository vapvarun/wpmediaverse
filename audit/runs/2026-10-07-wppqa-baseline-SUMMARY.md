# wppqa baseline - 2026-10-07

Plugin: MediaVerse 2.6.1 release candidate (branch 2.6.1) · Maturity **COMPLETE (73/100)** · Code Quality **C (62/100)**
Run: `mcp__wp-plugin-qa__wppqa_audit_plugin` with `site_url=http://mediaverse.local`, so the API
and DB checks ran (browser and visual-regression still SKIP).

Refreshes the 2026-09-14 baseline, which was 21 days old and failing local-CI stage 2.4.
**This is a baseline, not a to-do list**: it records the state 2.6.1 leaves behind, so a new
finding in a later run is attributable.

## Headline

| Gate | Errors | Warnings | 2026-09-14 errors |
|---|---|---|---|
| Code quality (14 checks) | 23 | 227 | 1209 |
| Product quality (9 checks) | 54 | 177 | 53 |
| Systemic misalignment (8 checks) | 39 | 1939 | 42 |

980 checks run · 606 passed · 116 failed · 22 flagged "fix before release".

The code-quality drop from 1209 to 23 is scan scope, not a rewrite: the dev-only
`tools/wp-stubs.php` file (1098 of the old errors) is no longer walked, and PHPCS itself
reports 0 errors and 0 warnings. The 23 that remain are all `pcp-deep` "echo without late
escaping" heuristics; Plugin Check on the plugin's own code is 0 errors (1 warning,
`base64_decode()` review).

## Known false positives - verified on 2026-09-14, unchanged in this run

1. **Plugin identity "Action Scheduler v3.9.3".** The scanner reads the header of the
   bundled library under `libs/action-scheduler/`. It also produces the "Version mismatch:
   readme 2.6.0 vs header 3.9.3" critical. `wpmediaverse.php` and `readme.txt` agree.
2. **"REST write endpoint allows unauth access" (15).** The flagged routes are
   `WP_REST_Server::READABLE` public reads. Real authority is enforced by
   `audit/route-authority.json` + `RouteAuthorityTest` in the unit suite, which passes.
   One more than last time: the new `GET /mvs/v1/offload-probe/{token}`, public by design
   for the loopback probe, single-use 32-hex token, one minute, declared in the route
   authority file with its reason.
3. **"No activation / deactivation / uninstall hook" and "cron jobs with no deactivation
   hook".** `wpmediaverse.php` registers `Activator::activate` and `Deactivator::deactivate`;
   `uninstall.php` is at the plugin root; `Deactivator::CRON_HOOKS` clears the recurring
   events (2.6.1 adds the preset licence activation event to that list).
4. **`[qa-coverage] audit/manifest.json missing`.** Manifests live at
   `audit/manifests/manifest.json`.
5. **wiring: `doaction`, `doaction2`, `mvs_permissions_submit` "half-wired settings".** Named
   form controls, not stored settings.

## The baseline, by band (accepted standing state)

- **a11y (30 errors)** - 27 `outline:none` without a `:focus-visible` replacement the scanner
  can see (each pairs with a rule declared elsewhere in its file; counted once per source and
  once per `.min`), 48 form inputs without labels (was 63), 37 `<img>` without `alt`, 1
  icon-only control. The 2.6.1 accessibility pass lowered the label count; the rest is
  admin-surface debt.
- **api (15)** - the false positive in 2 above, plus two AJAX demo-data actions that return 0
  to an unauthenticated probe, as intended.
- **enum-consistency (9)** - `privacy`, `scope`, `media_type`, `status`, `size`, `orderby`,
  `type`, `tab`, `action`. For `privacy` and `scope`, 2.6.1 moved to one list each
  (`PrivacyService::supported_levels()` / `TemplateHelpers::privacy_choices()`, and the
  `GET /media` scope enum now lists all four handled values). The check still sees the
  BuddyPress activity form's own numeric levels and reports drift.
- **rest-js-contract (4)**, **plugin-dev-rules (18)**, **ux-guidelines (4)**,
  **frontend-eval (3)**, **admin-eval (2)**, **marketing (4)** - same findings as
  2026-09-14, not touched by 2.6.1.

## What changed since 2026-09-14 that this run would have caught

Nothing new in the blocking bands. No new REST route without a declared audience, no new
AJAX action, no new table, no new setting written outside the Settings API.
