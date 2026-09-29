# Journey coverage plan — every FUNCTIONALITY-CATALOG entry, both editions

**Goal:** every one of the catalog's ~498 entries (`qa/inventory/FUNCTIONALITY-CATALOG.md`) is asserted by at least one executable journey, in both repos, so a 3rd-party QA tool (or `bin/run-journeys.sh`) can walk the whole plugin pair, not just the 30 features `REQUIRED-COVERS.txt` currently gates.

**State when this plan was written (2026-09-29):**
- Free: 40 journeys (`audit/journeys/{admin,customer,security}`), `bin/journey-coverage.sh` gates 30 *required* tags only — a curated critical subset, not the full catalog.
- Pro: 3 journeys (`audit/journeys/admin`, all admin-only, untouched since 2026-08-11), **no coverage gate script exists**.
- Catalog: 498 entries with an explicit `**Edition:**` line — 262 Free-only, 213 Pro (+ a handful Free/Pro or missing the field, treat as Free unless the body says otherwise). Areas and counts are in the area table further down.

## Format decision — reuse the existing journey schema, do not invent a new one

`audit/journeys/README.md` already defines exactly what a 3rd-party tool needs: YAML frontmatter (`journey`, `plugin`, `priority`, `roles`, `covers`, `prerequisites`) + `## Setup` / `## Steps` / `## Pass criteria` / `## Fail diagnostics`, with a documented JSON run-output contract. Nothing new needs to exist at the schema level. Two changes only:

1. **`covers:` gains the literal catalog ID(s)** alongside the existing free-form feature tags, e.g. `covers: [MV-DOC-014, MV-DOC-015, doc-sharing]`. This is what makes catalog -> journey mapping mechanically checkable instead of implied.
2. **Two densities of the same file type**, not two formats:
   - **Narrative** (existing style): one flow, several related catalog IDs, full numbered steps with real assertions. Used for genuinely flow-shaped areas.
   - **Compact / bundled**: same schema, but one file holds a short table-style step per catalog ID (`### N. MV-SET-014 — <title>` / one-line action / one-line assertion), covering many IDs from one sub-theme (e.g. one settings tab) in one file. Used for settings/admin/API/CLI groups where each entry is "set X, assert Y" and a full narrative per entry would be pure repetition.

Both densities are still markdown+frontmatter, still runnable by the same executor, still produce the same JSON pass/fail contract. This is the same schema doing double duty, not a fork of it.

## Area → density map

Grouping rule: an area is **compact** when its entries are individually small (one setting, one CLI command, one endpoint) and mostly independent; **narrative** when entries chain into a real user flow (upload → privacy → display, create → invite → resolve, etc.).

| Density | Areas | Entry count |
|---|---|---|
| Compact (bundle by sub-theme, several IDs/file) | SET(122), ADM(59), CLI(23), API(32), TPL(7), DSH(8), HLT(9), LIC(6), PSET(2), NTF(7) | ~275 |
| Narrative (group related IDs into one flow per file) | EXP(12), MED(35), DOC(47), ALB(23), MSG(21), LAY(21), VID(20), PRF(20), TRN(19), STY(18), WMK(17), CHL(17), BLK(17), PRV(16), IMP(16), BAT(16), AI(16), PTL(14), PPV(13), CMP(13), BP(12), ACC(12), WIZ(11), STO(11), UPL(10), COL(10), PSH(8), FAV(4) | ~223 |

Neither bucket is 1 file per catalog ID. A compact bundle file typically covers 8-15 IDs (e.g. all Storage-tab settings in one file); a narrative file typically covers 3-6 IDs (e.g. "create playlist album, add tracks, reorder, remove a track" as one flow covering 4 MV-ALB IDs), matching how the existing 40 Free journeys already work (each covers 3-5 tags today).

**Realistic file count target:** ~25-35 compact bundle files + ~55-75 narrative files per edition-side ≈ **150-200 new/updated files total across both repos**, not 498.

## Directory layout

```
audit/journeys/
├── admin/ customer/ security/          # existing, unchanged
├── settings/                           # NEW - compact bundles, one file per settings tab
├── api/                                # NEW - compact bundles, one file per REST controller
└── cli/                                # NEW - compact bundle(s) for wp mvs CLI commands

audit/pro/journeys/                     # Pro mirror, same subfolders, currently only admin/ exists
```

## New gate — report-only until coverage is real, then hard

`REQUIRED-COVERS.txt` + `bin/journey-coverage.sh` stay exactly as-is (curated critical subset, hard gate, unchanged). Add a **second, separate** script:

- `bin/catalog-coverage.sh` (Free) and its Pro mirror: parses every `#### MV-` heading out of `qa/inventory/FUNCTIONALITY-CATALOG.md`, parses every `covers:` list out of every journey file (both densities, both `audit/journeys/` and `audit/pro/journeys/`), reports catalog IDs with zero covering journey.
- Runs as **report-only** (prints the gap list, exits 0) until coverage is near-total, wired into local-CI as an informational stage (not blocking). Flip to a hard gate (exit 1 on any gap) only once the gap list is empty or reduced to an explicitly-accepted exceptions list (mirroring how `REQUIRED-COVERS.txt` itself works).

## Execution phases

1. **Classification manifest** (this phase, delegated to an agent): read the full catalog, for every one of the 498 IDs record `{id, title, edition, area, existing-coverage (if any current journey's covers: already implies it), proposed bucket file path, density}`. Output: `qa/inventory/JOURNEY-COVERAGE-MANIFEST.md` (a big table) — nothing else, no journey files written yet. This is the thing to review before committing to write ~180 files.
2. **Writing** (after manifest review): dispatch writer agents per manifest chunk (by area or by bucket-file group) to actually produce the journey/bundle markdown files, add catalog IDs to `covers:`, and update `docs/website` cross-links where a journey references a doc.
3. **Pro gate**: build `bin/journey-coverage.sh` + `REQUIRED-COVERS.txt` for Pro from scratch, mirroring Free's, seeded from Pro's own release-critical features (reuse the Pro `cert-ledger.json` / `capability-map.json` entities as a starting list).
4. **Wire it up**: add the `catalog-coverage.sh` report stage to both repos' `local-ci.sh`, update each CLAUDE.md's journey-count line.

Phases 2-4 are real, multi-session work once the manifest (phase 1) defines the actual file list — do not estimate total effort until that manifest exists.
