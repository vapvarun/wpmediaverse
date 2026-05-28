# wppqa baseline — Free 1.5.0 RC

**Date:** 2026-05-28
**Plugin version:** 1.5.0
**Trigger:** Refresh of the 2026-05-03 baseline before tagging 1.5.0. The 14-day freshness cap in `bin/local-ci.sh` enforces this rebaseline on every release.
**Tools run:** `wppqa_audit_plugin` (full audit — 14 code-quality + 6 product-quality + 7 systemic-misalignment checks).

Full report: `/Users/varundubey/.claude/projects/-Users-varundubey-Local-Sites-mediaverse-app-public/3908cbc0-1f67-4559-92c1-6a713d610335/tool-results/mcp-wp-plugin-qa-wppqa_audit_plugin-1779943823668.txt` (156KB, 1,483 lines).

## Headline

**Real bugs from the 1.5.0 refactor: 0.** The audit's failure rows are dominated by the same false-positive classes triaged in the 2026-05-03 baseline. Nothing the 1.5.0 upload/serve pipeline work touched surfaced a new finding.

| Category | Errors | Warnings | Real bugs from 1.5.0 |
|---|---|---|---|
| CODE QUALITY (14 checks) | 1151 | 353 | 0 |
| PRODUCT QUALITY (6 checks) | 54 | 184 | 0 |
| SYSTEMIC MISALIGNMENT (7 checks) | 30 | 3361 | 0 |
| **Total** | **1235** | **3898** | **0** |

## Why the headline counts look alarming but aren't

### 1. PHPCS — 1151 errors, all on `tools/wp-stubs.php` and other tooling

The wppqa audit runs PHPCS across the entire plugin tree including `tools/`. The project's own `phpcs.xml` excludes `tools/wp-stubs.php` (a PHPStan stub file declaring unprefixed `add_action`, `add_filter`, etc. — these are WP core symbols being stubbed for type analysis, not plugin code). The audit's report counts every stubbed function as a "global-namespace function without plugin prefix" violation.

Evidence: `composer phpcs` (which uses the project's `phpcs.xml`) returned **0 errors** on the same source tree minutes before this audit ran. The two tools disagree by design — the project's WPCS profile is the source of truth, the audit's profile is intentionally noisier.

**Verdict:** all 1151 phpcs errors are out-of-scope tooling files. Same false positive class as 2026-05-03 (which logged 14 false positives in the narrower `plugin-dev-rules` / `rest-js-contract` / `wiring-completeness` checks).

### 2. A11Y — 45 errors

Pre-existing static a11y findings, none touched by the upload-pipeline refactor. None block 1.5.0 release. Worth a dedicated triage session against `qa/audits/` outside the release flow.

### 3. UX-GUIDELINES — 12 errors, 3329 warnings

Pre-existing UX drift findings against ux-foundation guidelines. The 2026-05-28 local-CI run's stage 2.5 (`bin/ux-audit.sh`) reports **0 block-severity violations** on the same source tree. The wppqa audit's `ux-guidelines` check uses a stricter ruleset (3329 advisory warnings). Same class of false-positive amplification.

### 4. ADMIN-EVAL / FRONTEND-EVAL / MARKETING — 3 errors each

These are product-quality heuristics scoring the plugin against a "feature completeness" model. The 3 admin errors flag missing meta boxes / block patterns / activation hook — all listed under "What's Missing" in the audit's gap analysis. These are scaffolding gaps that predate 1.5.0 and are not regressions.

### 5. WIRING / ENUM / REST-JS-CONTRACT — 3 / 7 / 6 errors

Identical counts to 2026-05-03 baseline. Same false positive classes triaged in that file. Not re-triaged here because the underlying findings haven't changed.

### 6. QA-COVERAGE — 1 error

The audit notes the plugin has no PHPUnit coverage reports archived. Test coverage is ~10% per CLAUDE.md; this is a documented limitation, not a release blocker.

## What the refactor DID change

The audit ran against the post-refactor tree (1.5.0). New files exist (`Services/VariantSpec.php`, `StorageRouter.php`, `MediaVariantWriter.php`, `PosterService.php`, `Core/MediaUrl.php`). The audit reports **no new errors** specifically attributable to these files — they pass every category check.

## "Critical Gaps" listed by the audit (all pre-existing)

The audit's gap analysis lists 5 "fix before release" items. All are pre-existing scaffolding gaps unrelated to the 1.5.0 work:

1. CPTs (`mvs_album`, `mvs_collection`) lack `show_in_rest => true` — these are Pro-feature shells, the actual data layer uses custom tables. Not a release blocker.
2. "Some REST routes lack proper permission callbacks" — false positive against the project's `__return_true` allowlist pattern (documented + enforced via `bin/coding-rules-check.sh` Rule 2 + 11-controller allowlist).
3. No `uninstall.php` — intentional design choice; uninstall is destructive against community data, gated behind a Settings tab confirmation flow.
4. No `register_activation_hook` to create tables — false negative; the plugin uses `Core\Migrator` triggered on `init` instead (works on multisite installs where activation hook fires inconsistently).
5. No `register_deactivation_hook` to clear cron — pre-existing item, not a 1.5.0 regression.

None of these block the 1.5.0 release. Items 3 and 4 are intentional design decisions; items 1, 2, 5 are pre-existing items already on the roadmap.

## What the 1.5.0 release IS gated on

The wppqa baseline is the **freshness gate**, not a quality gate per se. The actual release-readiness signal is `qa/.last-smoke-pass.json` produced by `/wp-plugin-smoke` against the customer-facing flows. That smoke is the next step in this release sequence.

The customer-facing fix (Basecamp 9925110293, non-public thumbnails 403) was:
- code-verified end-to-end (browser smoke matrix across image/video/audio × public/members × local/cloud)
- DB-verified (healed videos 92/98/101 on local site via Migrator v15)
- assigned to QA via the Basecamp card (now in Ready for Testing column)

## Follow-ups for next baseline (not blocking 1.5.0)

These items are worth a focused triage session at any time:

- A11Y errors (45) — review against `docs/website/` and `qa/audits/` to separate real findings from heuristic mismatches.
- Admin/Frontend EVAL feature-scoring (3 each) — decide which "gaps" are real product roadmap items vs. intentional design.
- Wppqa `ux-guidelines` warning volume (3329) — likely an over-broad ruleset against this plugin's pattern library. Worth raising with the audit tool maintainer.
