# WPMediaVerse — QA

Canonical home for what must be true about the plugin. Not process, not runbooks — just the inventory.

## Files

### Specs — what must be true

- `WHAT-TO-CHECK.md` — flat list of surfaces, actions, settings, data stores, and cross-layer contracts that must work. Includes the **Regression Locks** table — specific specs that have regressed at least once and must not drift.
- `MANUAL-UX-QA.md` — procedural walkthrough of the surfaces in WHAT-TO-CHECK §1, step by step. Use when walking a specific journey or writing a spec for it.

### Rules — how we work (canonical specs behind `CLAUDE.md` Coding Rules)

- `CSS-ORGANIZATION-RULES.md` — file ownership matrix, scoping, specificity strategy, duplicate-rule ban, dead-selector ban, file-top banners, section numbering. Full spec for CLAUDE.md Rule #12.
- `PHP-ORGANIZATION-RULES.md` — file/method size, no-inline-HTML, no-inline-JS, enqueue consistency, sibling base-class rule, service container discipline, Free/Pro boundary. Covers CLAUDE.md Rules #1, #2, #4, #8, #10.
- `NAMING-RULES.md` — "names don't lie." CSS classes, PHP classes/methods, hooks, REST routes, DB tables, i18n, option keys, CSS tokens. Covers CLAUDE.md Rule #5.
- `RENDER-STATE-RULES.md` — every render path emits a visible state, populated or empty. Covers CLAUDE.md Rule #11.
- `PROCESS-RULES.md` — where rules live, debt tax, regression-lock workflow, CI aspirations, rule retirement. Meta-doc that keeps the others coherent.

### Runs — evidence

- `runs/` — dated output of previous release passes. Append only.

## Pro

`../../wpmediaverse-pro/qa/` has Pro's equivalents. Pro releases run both sets.

## How this gets used

Doesn't matter who runs it — AI agent or human. The question at every release is the same: *can the plugin demonstrate the things in WHAT-TO-CHECK?* If yes, ship. If not, fix what's broken.

The `/mediaverse-qa` skill is one way to run a pass; a human opening `MANUAL-UX-QA.md` is another. Both consume the same lists.
