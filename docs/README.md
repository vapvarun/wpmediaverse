# WPMediaVerse - Documentation

Single doc home for **both Free and Pro**. Pro is intentionally doc-free - all references in Pro point here.

> All content in this directory is excluded from the public customer ZIP via the Gruntfile copy task (`'!docs/**'`). It lives in the public GitHub repo for contributors and integrators.

## Layout

```
docs/
├── architecture/
│   ├── ARCHITECTURE.md                 # Free architecture (services, hooks, tables)
│   ├── architecture-contract.md        # Free ↔ Pro contract - what Pro can/can't do
│   ├── pro/
│   │   ├── ARCHITECTURE.md             # Pro architecture (5 extension patterns)
│   │   └── INTERACTIVITY-API-ARCHITECTURE.md
│   └── specs/                          # Per-feature design specs (date-stamped)
│       └── 2026-04-13-platform-connector-design.md
│
├── development/
│   ├── CODING_STANDARDS.md             # WPCS + PHPStan + plugin-specific rules
│   ├── COMPRESSION_INTEGRATIONS.md     # mvs_optimize_image bridges (EWWW, Imagify, …)
│   ├── CONTRIBUTING.md                 # How to contribute
│   ├── EXTENSION_GUIDE.md              # Building extensions on top of mvs_loaded
│   ├── GIT_WORKFLOW.md                 # Branching, PR flow, release branches
│   ├── INTEGRATION-EVENT-HOOKS.md      # Event hooks for gamification / activity bridges
│   ├── LOCAL_TESTING.md                # Local-by-Flywheel + wp-env setup
│   ├── LOCAL_TESTING-pro.md            # Pro-specific testing notes
│   ├── MOBILE_UX_GUIDELINE.md          # 390px breakpoints, touch targets
│   ├── REFACTORING_ROADMAP.md          # PLANNED structural backlog - not shipped state
│   ├── STORAGE-DRIVER-VERIFICATION.md  # Reusable runbook for a new storage driver
│   └── STRUCTURAL_GUIDELINE.md         # File-system organization rules
│
├── standards/                          # Portfolio-wide normative standards (synced copies)
│   ├── community-os-design.md
│   ├── frontend-interactivity.md
│   └── i18n.md
│
├── plans/                              # Per-topic working plans (dated / topical)
│
├── security/
│   └── SECURITY_CHECKLIST.md           # Per-PR security checklist + threat model
│
├── verification/                        # Ad-hoc verification reports
│   └── cloud-storage-verification.md   # Full QA matrix for cloud storage
│
├── website/                            # Public docs site source (published to wbcomdesigns.com)
│   ├── getting-started/
│   ├── settings/
│   ├── features/
│   ├── pro-features/
│   ├── gamification/
│   ├── buddypress/
│   ├── developer-guide/
│   └── images/
│
└── marketing/                          # Marketing asset folder (different from /marketing/)
```

## Where to put new docs

| Type | Goes in |
|------|---------|
| New architecture decision | `architecture/specs/{date}-{topic}.md` |
| New developer guide | `development/{TOPIC}.md` |
| Pro-specific architecture | `architecture/pro/{TOPIC}.md` |
| Security checklist update | `security/SECURITY_CHECKLIST.md` |
| Verification report (one-off) | `verification/{date}-{topic}.md` |
| Customer-facing docs | `website/{section}/{slug}.md` |
| Org rules / coding rules | `qa/rules/` (NOT here - those live with QA) |
| QA runbooks | `qa/runbooks/` (NOT here) |

## Quick links

- Free architecture: [`architecture/ARCHITECTURE.md`](architecture/ARCHITECTURE.md)
- Pro architecture: [`architecture/pro/ARCHITECTURE.md`](architecture/pro/ARCHITECTURE.md)
- Coding standards: [`development/CODING_STANDARDS.md`](development/CODING_STANDARDS.md)
- Security checklist: [`security/SECURITY_CHECKLIST.md`](security/SECURITY_CHECKLIST.md)
- QA home: [`../qa/`](../qa/)
- Audit home: [`../audit/`](../audit/) (machine-derived manifests + reports)
