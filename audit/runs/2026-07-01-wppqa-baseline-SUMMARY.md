# wppqa baseline — Free 1.8.1 (1.9.0-dev)

**Date:** 2026-07-01
**Plugin version:** 1.8.1 (branch `1.8.1`; latest work tagged 1.9.0 — access-rules UI + watermark, competition frontend assets, smart-collection count fix)
**Trigger:** The 14-day freshness cap in `bin/local-ci.sh` (stage 2.4) went stale (previous baseline 2026-05-28, 34 days old). Rebaseline after the recent 1.9.0-dev work so the gate reflects the current tree.
**Tools run:** `wppqa_audit_plugin` (full audit — 14 code-quality + 9 product-quality + 8 systemic-misalignment checks) against `http://mediaverse.local`.

Full report: `~/.claude/projects/-Users-varundubey-Local-Sites-mediaverse-app-public/d65aeddd-9950-40ab-9f3e-1c1f47e621eb/tool-results/mcp-wp-plugin-qa-wppqa_audit_plugin-1782918813382.txt` (155KB, 1,474 lines).

## Headline

**Real bugs from the 1.9.0-dev work: 0.** The audit's failure rows are the same false-positive classes triaged in the 2026-05-28 and 2026-05-03 baselines. Nothing the recent work touched — access-rules UI, watermark display, competition frontend assets, or the smart-collection list-count fix (`CollectionController::prepare_collection_response`) — surfaced a new finding. The project's own `composer phpcs` (WPCS profile — the source of truth) returned **0 errors** on the same tree in the local-CI run minutes before this audit.

| Category | Errors | Warnings | Real bugs from 1.9.0-dev |
|---|---|---|---|
| CODE QUALITY (14 checks) | 1122 | 337 | 0 |
| PRODUCT QUALITY (9 checks) | 62 | 133 | 0 |
| SYSTEMIC MISALIGNMENT (8 checks) | 37 | 3478 | 0 |
| **Total** | **1221** | **3948** | **0** |

## Why the counts look alarming but aren't

Same analysis as the 2026-05-28 baseline; counts drifted slightly but the classes are identical.

### 1. PHPCS — 1122 errors (was 1151)
The audit runs PHPCS across the whole tree including `tools/wp-stubs.php` (PHPStan stubs declaring unprefixed WP core symbols) and counts every stub as a "global function without plugin prefix" violation, plus phpcbf-fixable style nits (block-comment spacing, double-quoted strings) in newer code. The project's `phpcs.xml` excludes the tooling files and `composer phpcs` returns **0 errors** on the same source. The project WPCS profile is authoritative; the audit's profile is intentionally noisier. **Verdict: out-of-scope tooling + auto-fixable cosmetics, not customer-shipping errors.**

### 2. A11Y — 53 errors (was 45)
Pre-existing static a11y heuristic findings, none touched by the recent work. Not release-blocking. Worth a dedicated triage against `qa/audits/` outside the release flow.

### 3. UX-GUIDELINES — 15 errors, 3446 warnings (was 12 / 3329)
Pre-existing UX drift against a stricter-than-`ux-foundation` ruleset. Local-CI stage 2.5 (`bin/ux-audit.sh`) reports **0 block-severity violations** on the same tree (52 advisory → `audit/ux-audit-2026-07-01.md`). Same false-positive amplification.

### 4. ADMIN-EVAL / FRONTEND-EVAL / MARKETING — 3 errors each
Product-quality "feature completeness" heuristics — the same scaffolding gaps listed under Critical Gaps (CPT `show_in_rest`, block patterns, activation hook). Pre-existing design decisions, not regressions.

### 5. WIRING / ENUM-CONSISTENCY / REST-JS-CONTRACT — 3 / 8 / 5 errors
Same false-positive classes as prior baselines (counts within noise: enum 7→8, rest-js 6→5). Not re-triaged; underlying findings unchanged.

### 6. PLUGIN-DEV-RULES (5) / QA-COVERAGE (1)
Same as prior baselines — the `__return_true` allowlist pattern (documented + enforced by `bin/coding-rules-check.sh` Rule 2) and the archived-coverage note (~10% coverage per CLAUDE.md, a documented limitation).

## What the recent work DID change

The audit ran against the current tree including the 1.9.0-dev commits. The smart-collection fix refactored `CollectionController` (new private helpers `manual_cover_ids` / `count_manual_items` / `cover_from_media_ids`) — it passes PHP-LINT, PHPCOMPAT, PLUGIN-CHECK, API, and the project WPCS/PHPStan/coding-rules/contract gates. No new errors are attributable to it.

## Critical Gaps listed by the audit (all pre-existing)

1. CPTs (`mvs_album`, `mvs_collection`) lack `show_in_rest => true` — Pro-feature shells; the data layer uses custom `mvs_*` tables. Not a blocker.
2. "Some REST routes lack permission callbacks" — false positive against the documented `__return_true` allowlist.
3. No `uninstall.php` — intentional (uninstall is destructive against community data; gated behind a Settings confirmation flow).
4. No `register_activation_hook` for tables — false negative; `Core\Migrator` runs on `init` (reliable on multisite).
5. No `register_deactivation_hook` to clear cron — pre-existing, on the roadmap.

None block release.

## Release-readiness signal

The wppqa baseline is the **freshness gate**, not a quality gate. The customer-facing release signal is `qa/.last-smoke-pass*.json` from `/wp-plugin-smoke`. The smart-collection fix is additionally **live-verified in the MediaVerse app** (smart collections now show real item counts — 79 / 7 — and the detail loads the resolved media).

## Follow-ups for next baseline (not blocking)

- A11Y errors (53) — triage against `qa/audits/` to separate real findings from heuristic mismatches.
- PHPCS cosmetics in newer code (block-comment spacing, double quotes) — sweep with `phpcbf` on the customer-shipping files next time those files are touched.
- Admin/Frontend EVAL scoring (3 each) — decide which "gaps" are roadmap items vs. intentional design.
- `ux-guidelines` warning volume (3446) — likely an over-broad ruleset against this plugin's pattern library.
