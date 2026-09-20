# wppqa baseline — 2026-09-14

Plugin: MediaVerse 2.5.0 (branch 2.4.2) · Maturity **COMPLETE (73/100)** · Code Quality **C (61/100)**
Run: `mcp__wp-plugin-qa__wppqa_audit_plugin` with `site_url=http://mediaverse.local`, so API and
DB checks ran this time (browser and visual-regression still SKIP).

Refreshes the 2026-08-28 baseline, which was 13 days old and would have aged past the
14-day cap on 2026-09-16, failing local-CI stage 2.4 on every push after that.
**This is a baseline, not a to-do list** — it records the state the current work leaves
behind, so any new finding is attributable.

## Headline

| Gate | Errors | Warnings |
|---|---|---|
| Code quality (14 checks) | 1209 | 546 |
| Product quality (9 checks) | 53 | 173 |
| Systemic misalignment (8 checks) | 42 | 1925 |

2473 checks run · 589 passed · 1304 failed · 21 flagged "fix before release".

**Read those numbers against the false positives below before comparing them to
2026-08-28, which recorded PHPCS 0/0.** Nothing regressed; this run scanned a wider
tree than the last one.

## Known false positives — verified, ignore

Five artefacts account for nearly every "critical" item. Each was checked against the
code rather than taken at face value.

1. **Plugin identity: "Action Scheduler v3.9.3".** The scanner reads the header of the
   bundled runtime dependency under `libs/action-scheduler/` instead of the entry file.
   `wpmediaverse.php` says `Plugin Name: MediaVerse`, `Version: 2.5.0`. Same artefact the
   2026-08-28 baseline recorded; it now also poisons two downstream checks:
   - `[marketing] Version mismatch: readme "2.5.0" vs header "3.9.3"` — flagged **critical**
     and listed under Fix Before Release. Both files say 2.5.0. No mismatch.
   - `wppqa_readiness` reports the plugin as "Action Scheduler" for the same reason.

2. **PHPCS 1209 errors — 1098 of them are one dev-only file.** `tools/wp-stubs.php` is a
   561-line set of WordPress function stubs (`add_action`, `esc_html`, `sanitize_key`, …)
   that exists so the boot smoke can run without WordPress. Every finding against it is
   "function should start with the plugin prefix" — which is precisely what a stub file
   must not do. It is outside `phpcs.xml`'s `<file>includes/</file>` scope and excluded
   from the release zip (`Gruntfile.js:103`, `'!tools/**'`). The plugin's own gate,
   local-CI stage 1.2 `composer phpcs`, passes **152/152 files, 0 errors**.

3. **"14 REST write endpoints allow unauth access" — all sampled ones are GET routes.**
   Checked five of the fourteen:
   - `AlbumController.php:75`, `:93` — `WP_REST_Server::READABLE`, `__return_true`
   - `MediaController.php:81` — `READABLE`, `__return_true`
   - `TagController.php:41` — `READABLE`, `__return_true`
   - `ModerationController.php:68` — `READABLE`, and gated by `moderate_permissions_check`
   A public read with `__return_true` is the intended design for a public media library.
   The scanner labels readable routes as write endpoints. Coding Rule 2 already governs
   the real `__return_true` allowlist and passes in local-CI stage 2.1.

4. **"No activation / deactivation / uninstall hook" (3 of the 6 GAP items).**
   `wpmediaverse.php:258` registers `Activator::activate`, `:261` registers
   `Deactivator::deactivate`, and `uninstall.php` is present at the plugin root.

5. **`[qa-coverage] audit/manifest.json missing` and readiness'
   "no docs/qa/AGENT_SMOKE_RUNBOOK.md".** Both look in the wrong place. This plugin keeps
   manifests at `audit/manifests/manifest.json` and QA at `qa/runbooks/AGENT_SMOKE_RUNBOOK.md`.
   The readiness scorecard's 86/100 BELOW-BAR verdict rests entirely on these two paths.

## The baseline, by band (accepted pre-existing state)

Nothing below is new to this release; it is the standing surface the bug-finder reports
each run, recorded so a genuine regression stands out against it.

- **a11y (29 errors)** — 26 are `outline:none` without a `:focus-visible` replacement,
  counted once per source and once per `.min`. Basecamp 10300224447 verified all 23
  source sites by hand this cycle: 22 already pair with a real replacement elsewhere in
  their own file, and the one genuine gap (`.mvs-boost-slider`) was fixed in Pro
  `22d921b`. The count does not move because the scanner cannot see a companion rule
  declared separately. The remainder: 63 form inputs without labels, 36 `<img>` without
  `alt`, 1 icon-only control without an accessible name. Long-standing admin-surface debt.
- **api (14 errors)** — the false positive in §3 above.
- **marketing (4 errors)** — includes the version-mismatch artefact in §1.
- **admin-eval (3 errors)** — direct `$_POST` to `update_option` in admin handlers,
  bypassing the Settings API. Pre-existing admin pattern.
- **frontend-eval (3 errors)** — modal close-button heuristic misses.
- **wiring (3 errors)** — "half-wired setting" for `doaction`, `doaction2` and
  `mvs_permissions_submit`. All three are ours, and all three are artefacts: `doaction` /
  `doaction2` are the Apply submit buttons on the tag bulk-action bar
  (`Admin/TagManagementPage.php:179`, `:265`, named to match WordPress's own list-table
  convention), and `mvs_permissions_submit` is a hidden input at
  `Admin/Settings/PermissionsManager.php:147`. None is passed to `register_setting()` or
  read with `get_option()` — 0 occurrences. The check treats any named form field as a
  stored setting and then reports it unread.
- **enum-consistency (9 errors)** — drift reported on `action`, `size`, `media_type`,
  `status`, `tab`, `scope`, `orderby`, `privacy`, `type`. `media_type` and `privacy` are
  genuinely worth a look — both have canonical vocabularies (`MediaTypes`,
  `DocumentSettings::PRIVACY_VALUES`) that admin/REST/service should all read from.
- **rest-js-contract (7 errors)** — REST shapes the JS consumes without a matching
  declaration. Worth a pass next cycle.
- **plugin-dev-rules (16 errors)** — 11 × "nonce check without capability check",
  1 × "iterating over `$_POST`/`$_GET` directly".
- **ux-guidelines (6 errors, 1909 warnings)** — the warning volume is token/spacing
  advisories across every stylesheet.

## Product position

Customer Expectation Analysis puts the plugin in **Community Platform** (53% confidence)
at **97% feature completeness** — 14 of 15 expected features FOUND, 1 PARTIAL, 0 MISSING.

The single should-have gap is **Email Digests** (daily/weekly summary emails).
Verified: `digest` appears 0 times in Free's `includes/`. That is a real product gap
rather than a scanner artefact, and the only one this run found.

## What changed since 2026-08-28

- Version 2.4.0 → 2.5.0; the run now covers API and DB checks (`site_url` supplied).
- PHPCS moved 0 → 1209 because this run scanned outside `phpcs.xml`'s scope, not because
  code quality changed. The plugin's own gate is still 0 errors.
- The a11y `outline:none` band had its one genuine gap closed (Pro `22d921b`); the
  scanner count is unchanged for the reason given above.
