# WPMediaVerse — QA

Single QA home for **both Free and Pro**. Pro has no `qa/` directory — this is the canonical inventory of what must be true about the paired plugins.

## How this gets used

Doesn't matter who runs it — AI agent or human. The question at every release is the same: *can the plugin demonstrate the things in `inventory/WHAT-TO-CHECK.md`?* If yes, ship. If not, fix what's broken.

The release gate (`bin/build-release.sh`) reads `.last-smoke-pass.json` (combo mode) or `.last-smoke-pass-free.json` (free-only) and refuses to package without a fresh green pass.

## Freshness, honestly (checked 2026-08-30)

Not every file here tracks the code equally, and a stale runbook that looks authoritative is worse
than an obviously missing one. Current state:

| File | Last updated | Trust |
|---|---|---|
| `runbooks/AGENT_SMOKE_RUNBOOK.md` | 2026-08-30 | current |
| `inventory/WHAT-TO-CHECK.md` | 2026-08-30 | current |
| `MODEL-SITE.md` | 2026-08-19 | current |
| `runbooks/MANUAL-UX-QA-free.md` | 2026-08-30 | current — J17 admin screens, J18 messaging, J19 the 2.4.0 seams |
| `runbooks/MANUAL-UX-QA-pro.md` | 2026-08-30 | current — P22 captions/privacy/push/connectors, P23 documents |
| `runbooks/DOCUMENTS-QA.md` | 2026-08-19 | current |
| `inventory/FUNCTIONALITY-JOURNEYS.md` | 2026-06-24 | **stale, and deliberately not refreshed** — it duplicates what `audit/journeys/` now holds executably. Use those; this file is history. |

## Coverage, measured 2026-08-30

Not asserted — diffed against the code:

| Scope | Covered | Basis |
|---|---|---|
| Pro feature namespaces | **28 / 28** | every dir under `wpmediaverse-pro/includes/` except `Core`, `data` and `Cert` (internal tooling, no UX surface) |
| Free admin screens + member surfaces | **24 / 24** | the 13 admin pages plus the 11 member surfaces |
| Required executable journeys | **23 / 23** | `audit/journeys/REQUIRED-COVERS.txt`, enforced by local-CI gate 1.6 |

The gaps this closed, found by diffing rather than by memory: Pro had no journey for captions,
privacy, push or connectors, and none for documents — its largest feature — because that runbook
predated it. Free had none for the Documents, Integrations or Import admin screens, and none for
direct messaging at all.

**What this number does and does not mean.** It means every shipped surface is NAMED in a runbook
someone can walk. It does not mean every surface has been walked recently — that is
`.last-smoke-pass.json`, which is currently a deliberate partial (22 pass / 98 skipped) and which
the release gate correctly refuses to package on.

## Before you run anything

`MODEL-SITE.md` describes the install these runbooks assume — versions, settings, data shape, and
the two things most likely to make a run report a fault that is not there: **the Pro licence must
be active** (it gates document writes since 2026-08-19, so an unlicensed site looks like a broken
drive) and **the QA fixtures stay put** (they have names like `PRIVATE Tax Records uid22`; the
journeys depend on them, and this is not a demo site).

## Layout

```
qa/
├── MODEL-SITE.md             # the baseline install every run assumes — READ FIRST
├── runbooks/                 # what to walk, step by step
│   ├── AGENT_SMOKE_RUNBOOK.md       # pre-release gate runbook (sections A–G)
│   ├── MANUAL-UX-QA-free.md         # Free manual UX walkthrough
│   └── MANUAL-UX-QA-pro.md          # Pro manual UX walkthrough (20 journeys)
│
├── inventory/                # flat list of what must be true
│   └── WHAT-TO-CHECK.md      # surfaces, actions, settings, data stores, cross-layer contracts.
│                             # Includes the Regression Locks table — specs that have regressed
│                             # at least once and must not drift.
│
├── rules/                    # canonical specs behind CLAUDE.md Coding Rules
│   ├── CSS-ORGANIZATION-RULES.md    # file ownership, scoping, specificity — Rule #12
│   ├── NAMING-RULES.md              # names don't lie — Rules #5, #13
│   ├── PHP-ORGANIZATION-RULES.md    # file/method size, no-inline-HTML, boundary — Rules #1, #2, #4, #8, #10, #16
│   ├── RENDER-STATE-RULES.md        # every render emits a state — Rule #11
│   └── PROCESS-RULES.md             # where rules live, debt tax, rule retirement — Rule #15
│
├── audits/                   # dated audits (a11y, doc-drift, etc.)
│   ├── A11Y-AUDIT-2026-05-03.md
│   └── 2026-05-09-doc-drift-audit.md
│
├── runs/                     # append-only run evidence
│   ├── FINDINGS-HISTORY.md          # past findings + cleared-FN status
│   ├── drafts/                      # worker-agent drafts (Sonnet) before reviewer gate
│   └── {date}-{mode}.md             # dated run reports
│
├── .last-smoke-pass.json     # green-light signal — combo mode (Free + Pro)
└── .last-smoke-pass-free.json # green-light signal — free-only mode
```

## Two execution modes (both walked by the `mediaverse-qa` skill)

| Mode | When | What's walked |
|------|------|---------------|
| `free` | Verifying Free alone | Free `inventory/WHAT-TO-CHECK.md` + `runbooks/MANUAL-UX-QA-free.md` + Pro-absent guards (404 / hidden / gated) |
| `combo` | Verifying paired release | Free + Pro runbooks walked together + Pro layout cycle + feature-toggle degradation |

## Worker → reviewer gate (non-negotiable)

Sonnet (worker) findings are drafts. A reviewer (Opus or human) runs the 4-question citation gate before any row reaches a Basecamp card, the release-blocking JSON, or a "this is broken" label. See `runbooks/AGENT_SMOKE_RUNBOOK.md` "verification gate" section for the gate's exact form.

This rule exists because earlier sessions filed WP-core conventions as plugin bugs and aesthetic opinions as Majors. The reviewer gate keeps the runbook honest.

## Pre-release green-pass contract

When a run completes with zero `from`-origin failures and zero new debug.log entries, the worker writes `qa/.last-smoke-pass.json` (combo) or `qa/.last-smoke-pass-free.json` (free). Schema is in the `mediaverse-qa` skill spec (`wpmediaverse-pro/.claude/skills/mediaverse-qa/SKILL.md`). The build script asserts `release_version` matches HEAD before it will package.

On red runs: write the `runs/{date}-{mode}.md` evidence file but **NOT** the JSON. A red run blocks the gate; an "almost green" JSON would silently corrupt it.

## Quick links

- Smoke runbook: [`runbooks/AGENT_SMOKE_RUNBOOK.md`](runbooks/AGENT_SMOKE_RUNBOOK.md)
- **Documents QA (hand this to a tester):** [`runbooks/DOCUMENTS-QA.md`](runbooks/DOCUMENTS-QA.md) — the one standalone checklist for the document library, walkable without opening anything else. Its steps cite the release-gate rows they correspond to, so it and the smoke runbook stay aligned. Feature plan: [`../plan/document-library.md`](../plan/document-library.md).
- Inventory: [`inventory/WHAT-TO-CHECK.md`](inventory/WHAT-TO-CHECK.md)
- Findings history: [`runs/FINDINGS-HISTORY.md`](runs/FINDINGS-HISTORY.md)
- Doc-drift audit: [`audits/2026-05-09-doc-drift-audit.md`](audits/2026-05-09-doc-drift-audit.md)
- Build-release script: [`../bin/build-release.sh`](../bin/build-release.sh)
- mediaverse-qa skill: [`../../wpmediaverse-pro/.claude/skills/mediaverse-qa/SKILL.md`](../../wpmediaverse-pro/.claude/skills/mediaverse-qa/SKILL.md)
