# WPMediaVerse — QA

Canonical home for what must be true about the plugin. Not process, not runbooks — just the inventory.

## Files

- `WHAT-TO-CHECK.md` — flat list of surfaces, actions, settings, data stores, and cross-layer contracts that must work.
- `RENDER-STATE-RULES.md` — contract for what every render surface must emit in populated and empty states.
- `MANUAL-UX-QA.md` — procedural walkthrough of the surfaces in WHAT-TO-CHECK §1, step by step. Use when walking a specific journey or writing a spec for it.
- `runs/` — dated output of previous release passes. Append only.

## Pro

`../../wpmediaverse-pro/qa/` has Pro's equivalents. Pro releases run both sets.

## How this gets used

Doesn't matter who runs it — AI agent or human. The question at every release is the same: *can the plugin demonstrate the things in WHAT-TO-CHECK?* If yes, ship. If not, fix what's broken.

The `/mediaverse-qa` skill is one way to run a pass; a human opening `MANUAL-UX-QA.md` is another. Both consume the same lists.
