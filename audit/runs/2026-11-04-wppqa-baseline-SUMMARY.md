# wppqa baseline - 2026-11-04

Plugin: MediaVerse 2.6.3 release candidate (branch 2.6.3) · grade **C+ (67/100)**
Run: `mcp__wp-plugin-qa__wppqa_audit_plugin` on the repo, without `site_url` (mediaverse.local
is not running), so the API, DB, browser and visual-regression checks did not run. The live
behaviour they cover was checked instead with `wp mvs cert` / `wp mvs-pro cert` on the MV+BP
Docker lab: Free 72 pass, Pro 36 pass, Free with Pro 72 pass, 0 holes.

Refreshes the 2026-10-07 baseline (local-CI stage 2.4, 16 days old). **This is a baseline, not
a to-do list**: it records the state 2.6.3 leaves behind.

## Headline

1317 checks · 1236 passed · 80 failed · 0 critical · 80 high · 265 medium.

| Band | Errors now | 2026-10-07 |
|---|---|---|
| a11y | 31 | 30 |
| enum-consistency | 9 | 9 |
| rest-js-contract | 2 | 4 |
| plugin-dev-rules | 18 | 18 |
| wiring | 3 | 3 (false positives 5 below) |
| stylelint | 11 | not in that run |
| qa-coverage | 1 | 1 (false positive 4 below) |
| frontend-eval | 3 | 3 |
| admin-eval | 2 | 2 |
| ux-guidelines | 0 (90 warnings) | 4 |

## The two a11y increases are not new code

- **outline:none without :focus-visible, 27 to 28.** No CSS source file changed its
  `outline: none|0` count since v2.6.2 (checked per file against the tag). The extra hit is
  in the locally built, untracked `assets/css/*.min.css`, which the scanner also walks.
- **img without alt, 37 to 38.** The two `<img>` tags added in 2.6.3
  (`src/shared/components/EntityPicker.js`, `src/blocks/album-viewer/edit.js`) both carry
  `alt=""`. The formatter splits them over several lines and the check reads one line at a time.

## Known false positives (unchanged since 2026-09-14)

1. Plugin identity read from the bundled Action Scheduler header.
2. "REST write endpoint allows unauth access": READABLE public reads; authority is enforced by
   `audit/route-authority.json` + `RouteAuthorityTest`.
3. Activation / uninstall hooks reported missing; they exist.
4. `[qa-coverage] audit/manifest.json missing`: manifests live at `audit/manifests/`.
5. wiring: `doaction`, `doaction2`, `mvs_permissions_submit` are form controls, not settings.

## What changed since 2026-10-07 that this run would have caught

Nothing new in a blocking band. 2.6.3 adds no REST route, no AJAX action and no table.
`GET /mvs/v1/media` now accepts a `media_type` list; its authority entry is unchanged.
