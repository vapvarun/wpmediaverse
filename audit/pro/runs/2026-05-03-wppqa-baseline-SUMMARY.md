# wppqa baseline — Pro 1.2.0 RC

**Date:** 2026-05-03
**Plugin version:** 1.2.0
**Tools run:** `wppqa_check_plugin_dev_rules`, `wppqa_check_rest_js_contract`, `wppqa_check_wiring_completeness`

## Headline

**Real bugs surfaced: 0.** Both reported errors are false positives — same modal-first-with-fallback pattern as Free.

| Check | Passed | Failed | Real bugs |
|---|---|---|---|
| plugin-dev-rules | 7 | 2 | 0 |
| rest-js-contract | 44 | 0 | 0 |
| wiring-completeness | 48 | 0 | 0 |
| **Total** | **99** | **2** | **0** |

## Findings — full triage

### plugin-dev-rules (2 errors → 0 real)

#### `confirm-banned` (2)

| File:line | Verdict |
|---|---|
| `assets/js/connector-settings.js:313` | **False positive** — modal-first-with-fallback. Calls `window.mvsConfirm` first; `window.confirm` only as fallback when the helper hasn't loaded. ESLint suppression in place. |
| `assets/js/dashboard-connectors.js:136` | **False positive** — same pattern. |

The linter rule is intentionally strict ("never use `confirm()`"), but the Wbcom pattern of "modal first, native fallback" is the documented convention — see `~/.claude/skills/wp-plugin-development` admin-ux-rulebook addendum. Both files comply with the spirit of the rule.

### rest-js-contract (0 errors)

Clean — the 1.2.0 messaging cleanup (deleted Pro's stale fork of `MessagingService` + `messaging.{js,min.js}`) eliminated all of last baseline's drift findings, and the new Pro blocks all delegate to typed Renderer classes (zero direct REST property access in `src/blocks/pro-*/`).

### wiring-completeness (0 errors)

Clean — every Pro setting registered in `ProSettings` has a runtime reader (renderers, REST controllers, layout templates, or filter callbacks).

## Warnings (6 total) — informational

- 8 distinct CSS breakpoints. Same as Free; can be normalized in 1.2.1.
- 3 inline `onclick` attributes (`ChallengeManager.php:615`, `TournamentManager.php:350`, `Plugin.php:955`). All admin-only. Migration to event delegation is a 1.2.1 follow-up, not blocking.
- 2 tap-target warnings on `instagram-feed.css:437` (a 26px reaction button). Sub-40 px is a soft warning; real-world usability acceptable for hover-rich Instagram-style cards.

## Methodology

Same as Free's baseline file — wppqa linters prefer false positives over false negatives. Snapshot kept here so the next refresh can diff "still false positive" vs "newly surfaced".

## Compared to 2026-05-01 baseline

`audit/wppqa-baseline-2026-05-01/SUMMARY.md` already classified the Pro side as having zero real bugs. The 1.2.0 work (Phase 3a-e blocks, Phase 5 P2.1/P2.2 restructure, messaging cleanup) introduced zero regressions per the linters.
