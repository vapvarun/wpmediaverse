# wppqa baseline — 2026-08-08

Plugin: WPMediaVerse 2.3.2 (branch 2.3.3) · Maturity **COMPLETE (73/100)** · Code Quality **C (60/100)**
Run: `mcp__wp-plugin-qa__wppqa_audit_plugin` (no `site_url`, so API/DB/browser/visual checks did not run).

Re-establishes the baseline deleted in `dc3e80df`, which had been failing local-CI
stage 2.4 on every push since. **This is a baseline, not a to-do list** — it records
the state the document-library work starts from, so new findings are attributable.

## Headline

| Gate | Errors | Warnings |
|---|---|---|
| Code quality (14 checks) | **0** | 2 |
| Product quality (6 checks) | 37 | 140 |
| Systemic misalignment (7 checks) | 35 | 1805 |

411 checks run · 331 passed · 72 failed · 6 flagged "fix before release".

**Code quality is clean**: PHPCS 0/0, PHP-lint 187/187, composer-audit, i18n,
plugin-check, phpcompat all pass. PHPStan/ESLint/Stylelint report SKIP here because
the runner does not invoke them — they are covered by local-CI stages 1.2/1.3 and are
green there.

## Directly relevant to the document library

Two findings the audit surfaced independently that the build plan already addresses —
useful corroboration that the design is aimed at real problems:

- **`enum-consistency`: "Enum drift on `media_type`"** — the canonical list is not in one
  place, so admin, REST and service can disagree. **P2.1 fixes exactly this** by
  introducing `Core\MediaTypes` as the single source, and the audit's own advice is the
  task's design: *"extract the canonical list to one place and have admin/REST/service
  all call it."* Also flags drift on `privacy` and `status`, both of which the document
  work touches.
- **`qa-coverage`: `audit/manifest.json` missing** — a path mismatch, not a gap. This
  plugin keeps its manifest at `audit/manifests/manifest.json` (see CLAUDE.md trust
  order). Recorded so nobody "fixes" it by adding a duplicate at the wrong path.

## Known-and-accepted at baseline

Not regressions, and not in scope for the document library:

- **a11y 28 errors** — dominated by `outline:none` without a `:focus-visible` replacement,
  counted once per file including `.min.css` builds, so the real site count is lower than
  28. Plus 70 unlabelled form inputs and 28 images without `alt`.
- **`admin-eval` 3 errors** — direct `$_POST` → `update_option`, bypassing the Settings API.
- **`frontend-eval` 3 errors** — modals without a visible close button.
- **`marketing` 3 errors** — no WordPress.org banner, icon or screenshots. Expected: this
  is not a .org-hosted plugin.
- **`rest-js-contract` 7 errors** — JS reading keys absent from the response shape
  (`message_type`, `attachment_id`, `parent_id`, `unread`, `total`, `count`).
- **`plugin-dev-rules` 6 errors** — nonce check without an accompanying capability check.
- **bundle-size** — 1100 KB total, `frontend.css` 161 KB.

## Category expectation

Detected **Community Platform** (72% confidence). Feature completeness **97%** — 14 of 15
expected features found. The single PARTIAL is **email digests**; every must-have is present.

## How to refresh

```
mcp__wp-plugin-qa__wppqa_audit_plugin --plugin_path="$(pwd)"
```

Save to `audit/runs/YYYY-MM-DD-wppqa-baseline-SUMMARY.md`. Refresh at release, or when a
new finding needs to be told apart from a pre-existing one.
