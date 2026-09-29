# Journey coverage manifest — Phase 1 classification

Generated against `qa/inventory/FUNCTIONALITY-CATALOG.md` (2.6.0, 2026-09-27) by reading every entry's
Where/Steps/Expected/Edge-cases body (not just titles) and every existing journey's Setup/Steps (not
just `covers:` frontmatter), per `qa/inventory/JOURNEY-COVERAGE-PLAN.md`. No journey files were written
in this pass — manifest only, for review before Phase 2.

## Summary

- **Total catalog entries processed: 503** (grep ground truth on `#### MV-` headings; the plan's "~498"
  undercounted by 5 — five headings use a plain hyphen instead of an em dash and were missed by a
  narrower grep). Free: 255. Pro: 248.
- **Already have real existing coverage:** 74 entries, substantively exercised by one of the 40 Free
  journeys, the 3 Pro journeys in the `wpmediaverse-pro` repo's own `audit/journeys/`, or the
  **17 journeys already living in THIS repo's `audit/pro/journeys/`** — read in full for this pass.
- **Correction (post-review):** the first pass of this manifest missed that second Pro location
  entirely. `bin/journey-coverage.sh` scans both `audit/journeys` and `audit/pro/journeys` for the
  required-tags gate, so `audit/pro/journeys/` in the Free repo is a real, gated location, not a
  placeholder — it already holds 17 files (`security/` ×2, `admin/` ×6, `system/` ×1, `customer/` ×8),
  none of which were read in the first pass. Reading all 17 in full moved 13 IDs from "New" to
  "Extend" against a real existing file, let one Pro-side proposed file (`boosts-flow.md`) be deleted
  outright, and reshaped two others (`tournaments-registration-and-bracket.md`,
  `admin/tournament-manager.md`, both deleted and their IDs redistributed into sibling files). See the
  per-area tables below for the corrected `Existing Coverage` / `Proposed File` cells — every changed
  row is unchanged in ID/Title/Edition/Density, only those two columns moved. Four of the 17 files
  (`security/04-pro-rest-routes-distinct.md`, `admin/01-pro-bootstraps-after-free.md`,
  `admin/04-pro-options-rename-migration.md`, `admin/05-connector-disconnect-uses-modal.md`) are
  architecture/migration/UX-hygiene checks with no catalog-ID mapping at all — read in full, confirmed
  they don't overlap any proposed row, and left as-is (no manifest change from them).
  `security/08-anonymous-document-reads-follow-the-owner-switch.md` is real, substantive coverage of
  a document-drive anonymous-read boundary that has no matching catalog ID either (the catalog's
  DOC-0xx entries cover share-links and per-role visibility, not this drive-level `mvs_pro_documents_anon_links`
  ladder-vs-switch interaction) — noted here so Phase 2 knows the coverage exists even without a row
  pointing at it.
- **Need a new file or an extension to an existing file:** 429 entries.
- **Density override vs. the plan's default table:** none of the plan's per-area defaults needed
  overriding at the *area* level once the entries were actually read — DOC, TRN, CHL and BAT (all
  "narrative" by default) do contain several individually small, table-like entries (e.g. MV-DOC-035
  allowed-types, MV-TRN-016 toggle-off behavior), but grouping them into the narrative flow files below
  reads naturally rather than needing a compact sub-bundle. The one area that surprised: **PTL
  (template overrides, 13 entries)** — the plan didn't bucket it, and every entry is the *same*
  five-line override-mechanism check repeated per template, so it is treated as **compact** (one bundle
  file, `admin/template-overrides-pro.md`, 13 IDs) rather than narrative, despite living in the
  Pro/customer-adjacent space.
- **Deduplicated proposed-file list, with ID counts** (the actual file-count reality check):

  | Proposed file | IDs | New/Extend |
  |---|---|---|
  | `audit/journeys/customer/08-explore-browse-mobile.md` | 9 | Extend |
  | `audit/journeys/customer/41-explore-redirect-tagcloud-archives.md` | 3 | New |
  | `audit/journeys/customer/20-protected-media-login-gate.md` | 3 | Extend |
  | `audit/journeys/customer/22-edit-save-and-comment-no-post-leak.md` | 5 | Extend |
  | `audit/journeys/customer/46-media-engagement-actions.md` | 4 | New |
  | `audit/journeys/customer/47-media-fullscreen-edit-delete-replace.md` | 3 | New |
  | `audit/journeys/customer/48-media-report-and-moderation-visibility.md` | 2 | New |
  | `audit/journeys/customer/49-document-single-page.md` | 1 | New |
  | `audit/journeys/customer/50-media-accessibility.md` | 2 | New |
  | `audit/journeys/customer/52-upload-modal-and-types.md` | 4 | New |
  | `audit/journeys/customer/53-upload-validation-and-bulk.md` | 4 | New |
  | `audit/journeys/customer/54-album-create-and-privacy-cascade.md` | 5 | New |
  | `audit/journeys/customer/55-album-cover-reorder-remove.md` | 3 | New |
  | `audit/journeys/customer/56-album-playlist-and-archive.md` | 3 | New |
  | `audit/journeys/customer/21-album-privacy-and-pagination.md` | 1 | Extend |
  | `audit/journeys/customer/09-smart-collection-multi-rule.md` | 1 | Extend |
  | `audit/journeys/customer/57-collection-privacy-and-favorites.md` | 9 | New |
  | `audit/journeys/customer/58-profile-view-and-follow.md` | 5 | New |
  | `audit/journeys/customer/59-block-unblock-report.md` | 4 | New |
  | `audit/journeys/customer/60-profile-edit-and-avatar.md` | 3 | New |
  | `audit/journeys/customer/61-dashboard-rail-and-tabs.md` | 6 | New |
  | `audit/journeys/customer/62-notifications.md` | 5 | New |
  | `audit/journeys/customer/17-messaging-send-reliability.md` | 2 | Extend (partial coverage note only) |
  | `audit/journeys/customer/63-messaging-lifecycle.md` | 6 | New |
  | `audit/journeys/customer/64-messaging-social-and-groups.md` | 4 | New |
  | `audit/journeys/customer/65-messaging-status-and-panel.md` | 6 | New |
  | `audit/journeys/customer/66-blocks-grid-and-feed.md` | 5 | New |
  | `audit/journeys/customer/67-blocks-upload-and-dashboard-shortcodes.md` | 5 | New |
  | `audit/journeys/customer/68-blocks-documents-and-member-photos.md` | 4 | New |
  | `audit/journeys/customer/69-blocks-inserter-and-docs-check.md` | 2 | New |
  | `audit/journeys/security/06-private-community-gate.md` | 3 | Extend |
  | `audit/journeys/customer/70-access-privacy-defaults-and-suspension.md` | 2 | New |
  | `audit/journeys/security/06-blocked-member-cannot-interact.md` | 1 | Extend (cross-ref only) |
  | `audit/journeys/customer/18-bp-tab-assets-survive-suppression.md` | 2 | Extend |
  | `audit/journeys/customer/71-bp-activity-integration.md` | 7 | New |
  | `audit/journeys/settings/general-tab.md` | 13 | New |
  | `audit/journeys/settings/storage-display-tab.md` | 8 | New |
  | `audit/journeys/settings/ai-and-moderation-tab.md` | 8 | New |
  | `audit/journeys/settings/messages-tab.md` | 5 | New |
  | `audit/journeys/customer/05-dm-access-setting-persists.md` | 2 | Extend (cross-ref only) |
  | `audit/journeys/admin/11-outbound-and-display-toggles.md` | 1 | Extend (cross-ref only) |
  | `audit/journeys/admin/overview-and-demo-data.md` | 5 | New |
  | `audit/journeys/admin/media-list-and-actions.md` | 5 | New |
  | `audit/journeys/admin/tags-management.md` | 3 | New |
  | `audit/journeys/admin/moderation-queue.md` | 3 | New |
  | `audit/journeys/admin/04-moderation-approve-flow.md` | 2 | Extend |
  | `audit/journeys/admin/member-moderation.md` | 1 | New |
  | `audit/journeys/admin/stats-and-logs.md` | 2 | New |
  | `audit/journeys/admin/collection-settings-and-integrations.md` | 4 | New |
  | `audit/journeys/admin/12-setup-wizard-flow.md` | 4 | New |
  | `audit/journeys/admin/06-activation-creates-pages.md` | 2 | Extend |
  | `audit/journeys/admin/site-health-checks.md` | 5 | New |
  | `audit/journeys/admin/13-template-overrides.md` | 3 | New |
  | `audit/journeys/cli/maintenance-and-diagnostics.md` | 10 | New |
  | `audit/journeys/cli/storage-migration.md` | 3 | New |
  | `audit/journeys/admin/08-storage-switch-migrate-mobile.md` | 1 | Extend |
  | `audit/journeys/cli/media-repair-and-optimize.md` | 4 | New |
  | `audit/journeys/cli/buddypress-integration-cli.md` | 2 | New |
  | `audit/journeys/privacy/gdpr-export-erase.md` | 6 | New |
  | `audit/journeys/privacy/user-deletion.md` | 3 | New |
  | `audit/journeys/api/media-crud-and-social.md` | 9 | New |
  | `audit/journeys/api/albums-collections-tags.md` | 3 | New |
  | `audit/journeys/api/moderation-and-social.md` | 2 | New |
  | `audit/journeys/api/me-and-messaging.md` | 2 | New |
  | `audit/journeys/api/auth-app-feed-serve.md` | 6 | New |
  | `audit/pro/journeys/customer/layout-switching-and-parity.md` | 6 | New |
  | `audit/pro/journeys/customer/instagram-layout.md` | 2 | New |
  | `audit/pro/journeys/customer/pinterest-layout.md` | 2 | New |
  | `audit/pro/journeys/customer/flickr-layout.md` | 2 | New |
  | `audit/pro/journeys/customer/dribbble-layout.md` | 2 | New |
  | `audit/pro/journeys/customer/layout-blocks-and-mobile.md` | 2 | New |
  | `audit/pro/journeys/customer/compete-hub-cards.md` | 6 | New |
  | `audit/pro/journeys/customer/compete-hub-admin-and-api.md` | 5 | New |
  | `audit/pro/journeys/customer/battles-lifecycle.md` | 5 | New |
  | `audit/pro/journeys/customer/03-battle-win-xp-configured.md` | 2 | Extend (existing, this pass) |
  | `audit/pro/journeys/customer/battles-blocks-and-mobile.md` | 3 | New |
  | `audit/pro/journeys/customer/battles-edge-cases.md` | 3 | New |
  | `audit/pro/journeys/admin/battle-monitor.md` | 2 | New |
  | `audit/pro/journeys/customer/challenges-lifecycle.md` | 7 | New |
  | `audit/pro/journeys/customer/07-challenge-entry-happy-path.md` | 1 | Extend (existing, this pass) |
  | `audit/pro/journeys/customer/challenges-notifications-and-mobile.md` | 2 | New |
  | `audit/pro/journeys/admin/challenge-manager-and-themes.md` | 3 | New |
  | `audit/pro/journeys/customer/challenges-blocks.md` | 2 | New |
  | ~~`audit/pro/journeys/customer/tournaments-registration-and-bracket.md`~~ | 0 | **Deleted** — all 5 IDs redistributed (see below) |
  | `audit/pro/journeys/customer/tournaments-match-and-resolution.md` | 10 | New (was 8; +TRN-005, +TRN-013 redistributed in) |
  | `audit/pro/journeys/customer/tournaments-blocks-and-mobile.md` | 4 | New (was 3; +TRN-001 redistributed in) |
  | ~~`audit/pro/journeys/admin/tournament-manager.md`~~ | 0 | **Deleted** — TRN-013 moved to match-and-resolution, TRN-016 now Extends `admin/06` |
  | `audit/pro/journeys/customer/01-tournament-sparse-bracket-safety.md` | 2 | Extend (existing, this pass) |
  | `audit/pro/journeys/customer/06-tournament-registration-window-is-honest.md` | 1 | Extend (existing, this pass) |
  | `audit/pro/journeys/admin/06-competition-toggles-gate-their-own-section.md` | 2 | Extend (existing, this pass — CHL-015, TRN-016) |
  | `audit/pro/journeys/cli/competitions-tick-cli.md` | 2 | New |
  | ~~`audit/pro/journeys/customer/boosts-flow.md`~~ | 0 | **Deleted** — all 4 IDs redistributed (see below) |
  | `audit/pro/journeys/customer/streaks-flow.md` | 4 | New (was 6; BST-006/007 now Extend) |
  | `audit/pro/journeys/customer/leaderboard-and-mobile.md` | 3 | New |
  | `audit/pro/journeys/admin/gamification-settings.md` | 3 | New (was 2; +BST-004 redistributed in) |
  | `audit/pro/journeys/customer/04-boost-promotes-feed.md` | 3 | Extend (existing, this pass — BST-001/002/003) |
  | `audit/pro/journeys/customer/02-streak-freeze-proportional-cost.md` | 2 | Extend (existing, this pass — BST-006/007) |
  | `audit/pro/journeys/system/01-streak-daily-check-bounded.md` | 0 | Extend, secondary note only — covers the cron half of BST-006 alongside customer/02 |
  | `audit/pro/journeys/customer/stories-posting-and-viewing.md` | 7 | New |
  | `audit/pro/journeys/customer/stories-accessibility-and-viewers.md` | 2 | New |
  | `audit/pro/journeys/admin/stories-moderation.md` | 2 | New |
  | `audit/pro/journeys/api/stories-api-and-mobile.md` | 3 | New |
  | `audit/pro/journeys/customer/documents-drive-basics.md` | 5 | New |
  | `audit/pro/journeys/customer/documents-download-and-preview.md` | 4 | New |
  | `audit/pro/journeys/customer/documents-folders.md` | 3 | New |
  | `audit/pro/journeys/customer/documents-trash-and-restore.md` | 6 | New |
  | `audit/pro/journeys/customer/documents-privacy.md` | 4 | New |
  | `audit/pro/journeys/customer/documents-sharing.md` | 5 | New |
  | `audit/pro/journeys/customer/documents-search-and-spaces.md` | 5 | New |
  | `audit/pro/journeys/admin/documents-maintenance.md` | 4 | New |
  | `audit/pro/journeys/admin/documents-capabilities.md` | 3 | New |
  | `audit/pro/journeys/api/documents-gdpr-and-profile.md` | 2 | New |
  | `audit/pro/journeys/admin/documents-admin-screen-and-health.md` | 2 | New |
  | `audit/pro/journeys/api/documents-app-config-and-blocks.md` | 2 | New |
  | `wpmediaverse-pro/audit/journeys/admin/02-documents-toggle-gates-surfaces.md` | 1 | Extend (cross-ref) |
  | `wpmediaverse-pro/audit/journeys/admin/03-documents-settings-and-role-gate.md` | 8 | Extend |
  | `audit/pro/journeys/api/video-chapters.md` | 3 | New |
  | `audit/pro/journeys/customer/video-resume-playback.md` | 4 | New |
  | `audit/pro/journeys/customer/video-captions.md` | 6 | New |
  | `audit/pro/journeys/admin/video-analytics.md` | 2 | New |
  | `audit/pro/journeys/api/advanced-privacy.md` | 9 | New |
  | `audit/pro/journeys/admin/storage-drivers-cloud.md` | 4 | New |
  | `audit/pro/journeys/admin/storage-secrets-and-test.md` | 2 | New |
  | `audit/journeys/customer/23-watermark-stamped-at-upload.md` | 3 | Extend |
  | `audit/journeys/customer/24-watermark-ingest-paths.md` | 3 | Extend |
  | `audit/pro/journeys/admin/watermark-settings-details.md` | 5 | New |
  | `audit/pro/journeys/admin/ai-providers.md` | 5 | New |
  | `audit/pro/journeys/admin/ai-toggles-and-moderation.md` | 4 | New |
  | `audit/pro/journeys/admin/ai-reliability.md` | 3 | New |
  | `wpmediaverse-pro/audit/journeys/admin/01-feature-toggles-gate-routes.md` | — | (referenced only, no direct IDs) |
  | `audit/pro/journeys/cli/legacy-platform-import.md` | 3 | New |
  | `audit/pro/journeys/admin/migration-admin.md` | 3 | New |
  | `audit/pro/journeys/customer/flickr-connector.md` | 6 | New |
  | `audit/pro/journeys/api/push-notifications.md` | 7 | New |
  | `audit/pro/journeys/admin/license-management.md` | 6 | New |
  | `audit/pro/journeys/admin/template-overrides-pro.md` | 13 | New |
  | `audit/pro/journeys/admin/settings-tab-navigation.md` | 2 | New |

  **Reality check (corrected): ~109 distinct proposed-New files** (69 Free-side; 40 Pro-side — was 43,
  now minus the 3 deleted: `boosts-flow.md`, `tournaments-registration-and-bracket.md`,
  `admin/tournament-manager.md`) **+ 30 extensions** (was 22 — +8 for the newly-found real coverage in
  `audit/pro/journeys/`: `customer/{01,02,03,04,06,07}` and `admin/06`, with `system/01` as a secondary
  note on top of `customer/02`) **to the 40 existing Free journeys, 3 existing Pro-repo journeys, and
  17 existing `audit/pro/journeys/` files** — still inside the plan's 150-200 estimate once Phase 2
  also produces the Pro `REQUIRED-COVERS.txt`/gate script (not part of this manifest).

---

## Area: EXP (Explore) — 12 entries, narrative

| ID | Title | Edition | Density | Existing Coverage | Proposed File |
|---|---|---|---|---|---|
| MV-EXP-001 | Explore feed default view | Free | narrative | `customer/08-explore-browse-mobile.md` (grid render, step 1) | `audit/journeys/customer/08-explore-browse-mobile.md` |
| MV-EXP-002 | Explore search (`?q=`) | Free | narrative | `customer/08-explore-browse-mobile.md` (step 2, search) | `audit/journeys/customer/08-explore-browse-mobile.md` |
| MV-EXP-003 | Legacy `?s=` search on `/media/` | Free | narrative | none (only `?q=` tested by 08) | `audit/journeys/customer/08-explore-browse-mobile.md` |
| MV-EXP-004 | Filter by tag | Free | narrative | `customer/08-explore-browse-mobile.md` (step 2, filter tab, generic) | `audit/journeys/customer/08-explore-browse-mobile.md` |
| MV-EXP-005 | Filter by category | Free | narrative | none | `audit/journeys/customer/08-explore-browse-mobile.md` |
| MV-EXP-006 | Sort control | Free | narrative | none | `audit/journeys/customer/08-explore-browse-mobile.md` |
| MV-EXP-007 | Load More pagination | Free | narrative | none | `audit/journeys/customer/08-explore-browse-mobile.md` |
| MV-EXP-008 | Explore search autocomplete | Free | narrative | none | `audit/journeys/customer/08-explore-browse-mobile.md` |
| MV-EXP-009 | `/media/` 301 redirect to mapped page | Free | narrative | none | `audit/journeys/customer/41-explore-redirect-tagcloud-archives.md` |
| MV-EXP-010 | Explore tag cloud | Free | narrative | none | `audit/journeys/customer/41-explore-redirect-tagcloud-archives.md` |
| MV-EXP-011 | Album and Collection archives | Free | narrative | none | `audit/journeys/customer/41-explore-redirect-tagcloud-archives.md` |
| MV-EXP-012 | Single-media OG/Twitter meta | Free | narrative | none (customer/20 tests denial, not the positive OG-present case) | `audit/journeys/customer/20-protected-media-login-gate.md` |

## Area: MED (Single media + lightbox) — 20 entries, narrative

| ID | Title | Edition | Density | Existing Coverage | Proposed File |
|---|---|---|---|---|---|
| MV-MED-001 | View a single media page | Free | narrative | `customer/20-protected-media-login-gate.md` (gate container, steps 1-3) | `audit/journeys/customer/20-protected-media-login-gate.md` |
| MV-MED-002 | Record a view | Free | narrative | none | `audit/journeys/customer/46-media-engagement-actions.md` |
| MV-MED-003 | React with an emoji | Free | narrative | `customer/22-edit-save-and-comment-no-post-leak.md` (step 6, reaction toggle) | `audit/journeys/customer/22-edit-save-and-comment-no-post-leak.md` |
| MV-MED-004 | Favourite / bookmark an item | Free | narrative | none | `audit/journeys/customer/46-media-engagement-actions.md` |
| MV-MED-005 | Comment on media | Free | narrative | `customer/22-edit-save-and-comment-no-post-leak.md` (step 3, post comment); `customer/18-social-failure-paths.md` (failure path) | `audit/journeys/customer/22-edit-save-and-comment-no-post-leak.md` |
| MV-MED-006 | Edit own comment within window | Free | narrative | none | `audit/journeys/customer/22-edit-save-and-comment-no-post-leak.md` |
| MV-MED-007 | Delete a comment | Free | narrative | none | `audit/journeys/customer/22-edit-save-and-comment-no-post-leak.md` |
| MV-MED-008 | Share a media item | Free | narrative | none | `audit/journeys/customer/46-media-engagement-actions.md` |
| MV-MED-009 | Download a media item | Free | narrative | none | `audit/journeys/customer/46-media-engagement-actions.md` |
| MV-MED-010 | Fullscreen toggle | Free | narrative | none | `audit/journeys/customer/47-media-fullscreen-edit-delete-replace.md` |
| MV-MED-011 | Edit own media (title/desc/privacy) | Free | narrative | `customer/22-edit-save-and-comment-no-post-leak.md` (steps 1-2, edit persist) | `audit/journeys/customer/22-edit-save-and-comment-no-post-leak.md` |
| MV-MED-012 | Delete own media | Free | narrative | none | `audit/journeys/customer/47-media-fullscreen-edit-delete-replace.md` |
| MV-MED-013 | Report a media item | Free | narrative | `customer/25-reports-enabled-by-default.md` (§1-2, report submit) | `audit/journeys/customer/48-media-report-and-moderation-visibility.md` |
| MV-MED-014 | Lightbox — open from grid card | Free | narrative | `customer/08-explore-browse-mobile.md` (step 4, lightbox) | `audit/journeys/customer/08-explore-browse-mobile.md` |
| MV-MED-015 | Replace a file (keep metadata) | Free | narrative | none (Pro's watermark journey 24 exercises replace but only the stamp behavior) | `audit/journeys/customer/47-media-fullscreen-edit-delete-replace.md` |
| MV-MED-016 | Six-reaction accessibility | Free | narrative | none | `audit/journeys/customer/50-media-accessibility.md` |
| MV-MED-017 | Report/Delete confirm keyboard behaviour | Free | narrative | none | `audit/journeys/customer/50-media-accessibility.md` |
| MV-MED-018 | Denied-viewer page never leaks metadata | Free | narrative | `customer/20-protected-media-login-gate.md` (step 5, OG-leak check) | `audit/journeys/customer/20-protected-media-login-gate.md` |
| MV-MED-019 | Document single page (tiered preview) | Free (Pro-adjacent) | narrative | none | `audit/journeys/customer/49-document-single-page.md` |
| MV-MED-020 | Comment moderation visibility | Free | narrative | none | `audit/journeys/customer/48-media-report-and-moderation-visibility.md` |

## Area: UPL (Upload) — 8 entries, narrative

| ID | Title | Edition | Density | Existing Coverage | Proposed File |
|---|---|---|---|---|---|
| MV-UPL-001 | Open the upload modal (FAB) | Free | narrative | none (customer/01/02 hit the REST route directly, not the modal UX) | `audit/journeys/customer/52-upload-modal-and-types.md` |
| MV-UPL-002 | Upload a photo with title/desc/tags | Free | narrative | `customer/01-media-upload-public.md` (REST-level only, not the modal fields) | `audit/journeys/customer/52-upload-modal-and-types.md` |
| MV-UPL-003 | Upload gallery (multiple images) | Free | narrative | none | `audit/journeys/customer/52-upload-modal-and-types.md` |
| MV-UPL-004 | Upload video / audio | Free | narrative | none | `audit/journeys/customer/52-upload-modal-and-types.md` |
| MV-UPL-005 | Upload rejected: oversize/disallowed/empty | Free | narrative | none | `audit/journeys/customer/53-upload-validation-and-bulk.md` |
| MV-UPL-006 | Duplicate detection | Free | narrative | none | `audit/journeys/customer/53-upload-validation-and-bulk.md` |
| MV-UPL-007 | Set privacy + add to album while uploading | Free | narrative | none | `audit/journeys/customer/53-upload-validation-and-bulk.md` |
| MV-UPL-008 | Bulk actions on your own media | Free | narrative | none | `audit/journeys/customer/53-upload-validation-and-bulk.md` |

## Area: ALB (Albums) — 12 entries, narrative

| ID | Title | Edition | Density | Existing Coverage | Proposed File |
|---|---|---|---|---|---|
| MV-ALB-001 | Create an album | Free | narrative | none | `audit/journeys/customer/54-album-create-and-privacy-cascade.md` |
| MV-ALB-002 | Album privacy governs photos (2.6.0 rule) | Free | narrative | none | `audit/journeys/customer/54-album-create-and-privacy-cascade.md` |
| MV-ALB-003 | Confirm dialog before widening album privacy | Free | narrative | none | `audit/journeys/customer/54-album-create-and-privacy-cascade.md` |
| MV-ALB-004 | One album per photo | Free | narrative | none | `audit/journeys/customer/54-album-create-and-privacy-cascade.md` |
| MV-ALB-005 | Own privacy restored on leaving an album | Free | narrative | none | `audit/journeys/customer/54-album-create-and-privacy-cascade.md` |
| MV-ALB-006 | Set album cover | Free | narrative | none | `audit/journeys/customer/55-album-cover-reorder-remove.md` |
| MV-ALB-007 | Reorder album items | Free | narrative | none | `audit/journeys/customer/55-album-cover-reorder-remove.md` |
| MV-ALB-008 | Remove item from album / delete album | Free | narrative | none | `audit/journeys/customer/55-album-cover-reorder-remove.md` |
| MV-ALB-009 | Album single page | Free | narrative | `customer/21-album-privacy-and-pagination.md` (steps 5-6, owner/visitor single view) | `audit/journeys/customer/21-album-privacy-and-pagination.md` |
| MV-ALB-010 | Audio playlist album (API only) | Free | narrative | none | `audit/journeys/customer/56-album-playlist-and-archive.md` |
| MV-ALB-011 | Album privacy lock | Free | narrative | none (SET-011/PPV-003 cover the underlying lock elsewhere but not via the album route) | `audit/journeys/customer/56-album-playlist-and-archive.md` |
| MV-ALB-012 | Album archive & taxonomy interplay | Free | narrative | none | `audit/journeys/customer/56-album-playlist-and-archive.md` |

## Area: COL (Collections) — 6 entries, narrative

| ID | Title | Edition | Density | Existing Coverage | Proposed File |
|---|---|---|---|---|---|
| MV-COL-001 | Create a smart (rule-based) collection | Free | narrative | `customer/09-smart-collection-multi-rule.md` (full create+rule flow) | `audit/journeys/customer/09-smart-collection-multi-rule.md` |
| MV-COL-002 | Collection privacy is only Public/Members | Free | narrative | none | `audit/journeys/customer/57-collection-privacy-and-favorites.md` |
| MV-COL-003 | Collection privacy never cascades | Free | narrative | none | `audit/journeys/customer/57-collection-privacy-and-favorites.md` |
| MV-COL-004 | Favourites is your automatic collection | Free | narrative | none | `audit/journeys/customer/57-collection-privacy-and-favorites.md` |
| MV-COL-005 | Manual "Save to collection" gated off in Free | Free | narrative | none | `audit/journeys/customer/57-collection-privacy-and-favorites.md` |
| MV-COL-006 | Collection single page | Free | narrative | none | `audit/journeys/customer/57-collection-privacy-and-favorites.md` |

## Area: FAV (Favourites) — 4 entries, narrative

| ID | Title | Edition | Density | Existing Coverage | Proposed File |
|---|---|---|---|---|---|
| MV-FAV-001 | Toggle favourite from a grid card | Free | narrative | none | `audit/journeys/customer/57-collection-privacy-and-favorites.md` |
| MV-FAV-002 | Favorites tab listing, search/sort | Free | narrative | none | `audit/journeys/customer/57-collection-privacy-and-favorites.md` |
| MV-FAV-003 | Favouriting respects privacy/membership | Free | narrative | none | `audit/journeys/customer/57-collection-privacy-and-favorites.md` |
| MV-FAV-004 | Un-favourite notification does not fire | Free | narrative | none | `audit/journeys/customer/57-collection-privacy-and-favorites.md` |

## Area: PRF (Profiles / Follow / Block) — 12 entries, narrative

| ID | Title | Edition | Density | Existing Coverage | Proposed File |
|---|---|---|---|---|---|
| MV-PRF-001 | View a member's profile | Free | narrative | none | `audit/journeys/customer/58-profile-view-and-follow.md` |
| MV-PRF-002 | Follow/unfollow row hides for owner/logged-out | Free | narrative | none | `audit/journeys/customer/58-profile-view-and-follow.md` |
| MV-PRF-003 | Follow a member | Free | narrative | none | `audit/journeys/customer/58-profile-view-and-follow.md` |
| MV-PRF-004 | Unfollow a member | Free | narrative | none | `audit/journeys/customer/58-profile-view-and-follow.md` |
| MV-PRF-005 | View followers/following lists | Free | narrative | none | `audit/journeys/customer/58-profile-view-and-follow.md` |
| MV-PRF-006 | Block a member | Free | narrative | none (security/06-blocked-member tests the blocked party's restrictions, not the blocker's own action) | `audit/journeys/customer/59-block-unblock-report.md` |
| MV-PRF-007 | Unblock a member | Free | narrative | none | `audit/journeys/customer/59-block-unblock-report.md` |
| MV-PRF-008 | Blocked list | Free | narrative | none | `audit/journeys/customer/59-block-unblock-report.md` |
| MV-PRF-009 | Report a member (Pro-gated in Free) | Free | narrative | none | `audit/journeys/customer/59-block-unblock-report.md` |
| MV-PRF-010 | Edit profile redirects to dashboard | Free | narrative | none | `audit/journeys/customer/60-profile-edit-and-avatar.md` |
| MV-PRF-011 | Edit profile fields | Free | narrative | none | `audit/journeys/customer/60-profile-edit-and-avatar.md` |
| MV-PRF-012 | Avatar upload/remove | Free | narrative | none | `audit/journeys/customer/60-profile-edit-and-avatar.md` |

## Area: DSH (My Media Dashboard) — 6 entries, narrative

| ID | Title | Edition | Density | Existing Coverage | Proposed File |
|---|---|---|---|---|---|
| MV-DSH-001 | Dashboard access and rail | Free | narrative | none | `audit/journeys/customer/61-dashboard-rail-and-tabs.md` |
| MV-DSH-002 | Media tab | Free | narrative | none | `audit/journeys/customer/61-dashboard-rail-and-tabs.md` |
| MV-DSH-003 | Albums tab | Free | narrative | none | `audit/journeys/customer/61-dashboard-rail-and-tabs.md` |
| MV-DSH-004 | Collections tab | Free | narrative | none | `audit/journeys/customer/61-dashboard-rail-and-tabs.md` |
| MV-DSH-005 | Favorites (reachable, not on rail) | Free | narrative | none | `audit/journeys/customer/61-dashboard-rail-and-tabs.md` |
| MV-DSH-006 | Edit profile panel | Free | narrative | none | `audit/journeys/customer/61-dashboard-rail-and-tabs.md` |

## Area: NTF (Notifications) — 5 entries, narrative

| ID | Title | Edition | Density | Existing Coverage | Proposed File |
|---|---|---|---|---|---|
| MV-NTF-001 | Notification bell and dropdown | Free | narrative | none | `audit/journeys/customer/62-notifications.md` |
| MV-NTF-002 | Mark notifications read | Free | narrative | none | `audit/journeys/customer/62-notifications.md` |
| MV-NTF-003 | Notification types generated | Free | narrative | `customer/13-notification-hook-message-link.md` (hook contract, not the 7-type sweep) | `audit/journeys/customer/62-notifications.md` |
| MV-NTF-004 | Email notification preferences | Free | narrative | none | `audit/journeys/customer/62-notifications.md` |
| MV-NTF-005 | Mentions | Free | narrative | none | `audit/journeys/customer/62-notifications.md` |

## Area: MSG (Messages / DM) — 16 entries, narrative

| ID | Title | Edition | Density | Existing Coverage | Proposed File |
|---|---|---|---|---|---|
| MV-MSG-001 | Messaging master switch | Free | narrative | none | `audit/journeys/customer/63-messaging-lifecycle.md` |
| MV-MSG-002 | Who can send messages (DM access level) | Free | narrative | none | `audit/journeys/customer/63-messaging-lifecycle.md` |
| MV-MSG-003 | Start a new conversation | Free | narrative | none | `audit/journeys/customer/63-messaging-lifecycle.md` |
| MV-MSG-004 | Send a text message | Free | narrative | `customer/17-messaging-send-reliability.md` (send/failure/retry) | `audit/journeys/customer/63-messaging-lifecycle.md` |
| MV-MSG-005 | Send an attachment | Free | narrative | none | `audit/journeys/customer/63-messaging-lifecycle.md` |
| MV-MSG-006 | Share existing media into a conversation | Free | narrative | none | `audit/journeys/customer/63-messaging-lifecycle.md` |
| MV-MSG-007 | React to a message | Free | narrative | none | `audit/journeys/customer/64-messaging-social-and-groups.md` |
| MV-MSG-008 | Unsend vs. delete a message | Free | narrative | none | `audit/journeys/customer/64-messaging-social-and-groups.md` |
| MV-MSG-009 | Mark conversation read / unread badge | Free | narrative | `customer/17-messaging-send-reliability.md` (loading/empty state, adjacent) | `audit/journeys/customer/65-messaging-status-and-panel.md` |
| MV-MSG-010 | Message requests: accept/decline | Free | narrative | none | `audit/journeys/customer/64-messaging-social-and-groups.md` |
| MV-MSG-011 | Group conversations | Free | narrative | none | `audit/journeys/customer/64-messaging-social-and-groups.md` |
| MV-MSG-012 | Typing indicator | Free | narrative | none | `audit/journeys/customer/65-messaging-status-and-panel.md` |
| MV-MSG-013 | Search within a conversation | Free | narrative | none | `audit/journeys/customer/65-messaging-status-and-panel.md` |
| MV-MSG-014 | Chat panel visibility scoping | Free | narrative | `admin/11-outbound-and-display-toggles.md` (step 5, chat panel gate — the setting, not the scoping modes) | `audit/journeys/customer/65-messaging-status-and-panel.md` |
| MV-MSG-015 | Online status visibility | Free | narrative | none | `audit/journeys/customer/65-messaging-status-and-panel.md` |
| MV-MSG-016 | DM timestamps are server-side | Free | narrative | none | `audit/journeys/customer/65-messaging-status-and-panel.md` |

## Area: BLK (Blocks & Shortcodes) — 16 entries, narrative

| ID | Title | Edition | Density | Existing Coverage | Proposed File |
|---|---|---|---|---|---|
| MV-BLK-001 | Media Grid block / `[mvs_gallery]` | Free | narrative | none | `audit/journeys/customer/66-blocks-grid-and-feed.md` |
| MV-BLK-002 | Explore Feed block / `[mvs_explore_feed]` | Free | narrative | none | `audit/journeys/customer/66-blocks-grid-and-feed.md` |
| MV-BLK-003 | Media Player block / `[mvs_player]` | Free | narrative | none | `audit/journeys/customer/66-blocks-grid-and-feed.md` |
| MV-BLK-004 | Album Viewer block / `[mvs_album]` | Free | narrative | none | `audit/journeys/customer/66-blocks-grid-and-feed.md` |
| MV-BLK-005 | Media Stats block / `[mvs_stats]` | Free | narrative | none | `audit/journeys/customer/66-blocks-grid-and-feed.md` |
| MV-BLK-006 | Media Upload block / `[mvs_upload]` | Free | narrative | `customer/18-social-failure-paths.md` (step 6, logged-out CTA only) | `audit/journeys/customer/67-blocks-upload-and-dashboard-shortcodes.md` |
| MV-BLK-007 | Member Photos block / `[mvs_member_photos]` | Free | narrative | none | `audit/journeys/customer/68-blocks-documents-and-member-photos.md` |
| MV-BLK-008 | PDF Viewer block / `[mvs_pdf_viewer]` | Free | narrative | none | `audit/journeys/customer/68-blocks-documents-and-member-photos.md` |
| MV-BLK-009 | `[mvs_dashboard]` shortcode | Free | narrative | none | `audit/journeys/customer/67-blocks-upload-and-dashboard-shortcodes.md` |
| MV-BLK-010 | `[mvs_collection]` shortcode | Free | narrative | none | `audit/journeys/customer/68-blocks-documents-and-member-photos.md` |
| MV-BLK-011 | `[mvs_profile_edit]` shortcode | Free | narrative | none | `audit/journeys/customer/67-blocks-upload-and-dashboard-shortcodes.md` |
| MV-BLK-012 | `[mvs_documents]` shortcode | Free | narrative | none | `audit/journeys/customer/68-blocks-documents-and-member-photos.md` |
| MV-BLK-013 | `[mvs_usage_history]` shortcode | Free | narrative | none | `audit/journeys/customer/67-blocks-upload-and-dashboard-shortcodes.md` |
| MV-BLK-014 | `[mvs_lock_overlay]` shortcode (deprecated) | Free | narrative | none | `audit/journeys/customer/67-blocks-upload-and-dashboard-shortcodes.md` |
| MV-BLK-015 | Internal Interactivity-only blocks never insertable | Free | narrative | none | `audit/journeys/customer/69-blocks-inserter-and-docs-check.md` |
| MV-BLK-016 | Documentation matches shipped shortcodes | Free | narrative | none | `audit/journeys/customer/69-blocks-inserter-and-docs-check.md` |

## Area: ACC (Site access / Members Only / Privacy) — 5 entries, narrative

| ID | Title | Edition | Density | Existing Coverage | Proposed File |
|---|---|---|---|---|---|
| MV-ACC-001 | Members Only master switch | Free | narrative | `security/06-private-community-gate.md` (host-decides path; standalone-only path partially) | `audit/journeys/security/06-private-community-gate.md` |
| MV-ACC-002 | Default privacy for new uploads | Free | narrative | none | `audit/journeys/customer/70-access-privacy-defaults-and-suspension.md` |
| MV-ACC-003 | Allow Users to Set Privacy | Free | narrative | none | `audit/journeys/customer/70-access-privacy-defaults-and-suspension.md` |
| MV-ACC-004 | Site-wide "make the API private" (host-only) | Free | narrative | `security/06-private-community-gate.md` (this IS the mechanism the journey exercises) | `audit/journeys/security/06-private-community-gate.md` |
| MV-ACC-005 | Suspended member cannot write | Free | narrative | `security/06-blocked-member-cannot-interact.md` (§4, full suspension gate incl. App Password) | `audit/journeys/security/06-blocked-member-cannot-interact.md` |

## Area: BP (BuddyPress integration) — 9 entries, narrative

| ID | Title | Edition | Density | Existing Coverage | Proposed File |
|---|---|---|---|---|---|
| MV-BP-001 | Member profile "Media" tab | Free | narrative | `customer/18-bp-tab-assets-survive-suppression.md` (step 1, asset survival — not content correctness) | `audit/journeys/customer/18-bp-tab-assets-survive-suppression.md` |
| MV-BP-002 | Group "Media" tab | Free | narrative | `customer/18-bp-tab-assets-survive-suppression.md` (step 4, group tab assets) | `audit/journeys/customer/18-bp-tab-assets-survive-suppression.md` |
| MV-BP-003 | Upload creates a BuddyPress activity item | Free | narrative | none | `audit/journeys/customer/71-bp-activity-integration.md` |
| MV-BP-004 | Comment syncs into BP activity comments | Free | narrative | none | `audit/journeys/customer/71-bp-activity-integration.md` |
| MV-BP-005 | Activity privacy filtering matches media privacy | Free | narrative | none | `audit/journeys/customer/71-bp-activity-integration.md` |
| MV-BP-006 | Activity composer's attach-media control | Free | narrative | none | `audit/journeys/customer/71-bp-activity-integration.md` |
| MV-BP-007 | BuddyPress notification bell is the single source | Free | narrative | none | `audit/journeys/customer/71-bp-activity-integration.md` |
| MV-BP-008 | Handing frontend to a community layer (BuddyNext) | Free | narrative | none (explicitly out of scope for plain-BP QA per the entry itself) | `audit/journeys/customer/71-bp-activity-integration.md` |
| MV-BP-009 | Reactions/mentions in the BuddyNext bell | Free | narrative | none | `audit/journeys/customer/71-bp-activity-integration.md` |

## Area: SET (Settings) — 35 entries, compact

| ID | Title | Edition | Density | Existing Coverage | Proposed File |
|---|---|---|---|---|---|
| MV-SET-001 | Fair-use storage limit per member | Free | compact | none | `audit/journeys/settings/general-tab.md` |
| MV-SET-002 | Max Upload Size | Free | compact | none | `audit/journeys/settings/general-tab.md` |
| MV-SET-003 | Allowed File Types | Free | compact | none | `audit/journeys/settings/general-tab.md` |
| MV-SET-004 | Duplicate Detection | Free | compact | none | `audit/journeys/settings/general-tab.md` |
| MV-SET-005 | Remove location from photos (EXIF strip) | Free | compact | none | `audit/journeys/settings/general-tab.md` |
| MV-SET-006 | Remove Data on Delete (uninstall) | Free | compact | none | `audit/journeys/settings/general-tab.md` |
| MV-SET-007 | Storage driver (Where files are stored) | Free | compact | `admin/08-storage-switch-migrate-mobile.md` (step 1, overview only) | `audit/journeys/settings/storage-display-tab.md` |
| MV-SET-008 | Compress uploaded images (Optimize Originals) | Free | compact | `admin/10-media-optimization-toggles.md` (full toggle+pipeline check) | `audit/journeys/admin/10-media-optimization-toggles.md` |
| MV-SET-009 | Backend-only storage tuning (no screen control) | Free | compact | `admin/10-media-optimization-toggles.md` (webp/avif portion) | `audit/journeys/settings/storage-display-tab.md` |
| MV-SET-010 | Default Privacy Level | Free | compact | none | `audit/journeys/settings/general-tab.md` |
| MV-SET-011 | Allow Users to Set Privacy | Free | compact | none | `audit/journeys/settings/general-tab.md` |
| MV-SET-012 | Who can upload media | Free | compact | none | `audit/journeys/settings/general-tab.md` |
| MV-SET-013 | Allow Downloads | Free | compact | none | `audit/journeys/settings/storage-display-tab.md` |
| MV-SET-014 | Members Only (community-wide gate) | Free | compact | `security/06-private-community-gate.md` (same mechanism as ACC-001) | `audit/journeys/security/06-private-community-gate.md` |
| MV-SET-015 | Mobile App tab (App Sign-In, Terms, Abuse, EULA) | Free | compact | none | `audit/journeys/settings/general-tab.md` |
| MV-SET-016 | Layout (Display) | Free | compact | none | `audit/journeys/settings/storage-display-tab.md` |
| MV-SET-017 | Grid Columns | Free | compact | none | `audit/journeys/settings/storage-display-tab.md` |
| MV-SET-018 | Items Per Page | Free | compact | none | `audit/journeys/settings/storage-display-tab.md` |
| MV-SET-019 | Backend-only display tuning | Free | compact | none | `audit/journeys/settings/storage-display-tab.md` |
| MV-SET-020 | Messages (master switch) | Free | compact | none | `audit/journeys/settings/messages-tab.md` |
| MV-SET-021 | Who can send messages | Free | compact | `customer/05-dm-access-setting-persists.md` (persistence, not behavior) | `audit/journeys/settings/messages-tab.md` |
| MV-SET-022 | Minimum Account Age (messaging) | Free | compact | none | `audit/journeys/settings/messages-tab.md` |
| MV-SET-023 | Chat Panel Visibility | Free | compact | `admin/11-outbound-and-display-toggles.md` (step 5) | `audit/journeys/admin/11-outbound-and-display-toggles.md` |
| MV-SET-024 | Online Status Visibility | Free | compact | `customer/05-dm-access-setting-persists.md` (step 7, persistence) | `audit/journeys/customer/05-dm-access-setting-persists.md` |
| MV-SET-025 | Community Guidelines URL | Free | compact | none | `audit/journeys/settings/ai-and-moderation-tab.md` |
| MV-SET-026 | Member Reporting (master switch) | Free | compact | `customer/25-reports-enabled-by-default.md` (§4, full on/off behavior) | `audit/journeys/customer/25-reports-enabled-by-default.md` |
| MV-SET-027 | Auto-Hide Threshold | Free | compact | `customer/25-reports-enabled-by-default.md` (adjacent, threshold not directly asserted) | `audit/journeys/settings/ai-and-moderation-tab.md` |
| MV-SET-028 | AI Moderation (toggle + flag behavior) | Free | compact | `admin/09-ai-features-owner-control.md` (step 2, toggle gates path); `admin/04-moderation-approve-flow.md` (step 6, AI Flagged tab) | `audit/journeys/settings/ai-and-moderation-tab.md` |
| MV-SET-029 | AI Provider + OpenAI API Key | Free | compact | `admin/09-ai-features-owner-control.md` (step 1, provider mismatch guard) | `audit/journeys/settings/ai-and-moderation-tab.md` |
| MV-SET-030 | Auto-Analyze Uploads | Free | compact | `admin/09-ai-features-owner-control.md` (step 2) | `audit/journeys/settings/ai-and-moderation-tab.md` |
| MV-SET-031 | Generate Descriptions/Tags/Auto-Apply | Free | compact | `admin/09-ai-features-owner-control.md` (step 4b, show-when only) | `audit/journeys/settings/ai-and-moderation-tab.md` |
| MV-SET-032 | Monthly AI Budget ($) | Free | compact | `admin/09-ai-features-owner-control.md` (step 5) | `audit/journeys/settings/ai-and-moderation-tab.md` |
| MV-SET-033 | Email notification toggles | Free | compact | `admin/09-ai-features-owner-control.md` — no; unrelated. None. | `audit/journeys/settings/general-tab.md` |
| MV-SET-034 | Pages (Explore/My Media/Upload/Docs) | Free | compact | `admin/06-activation-creates-pages.md` (activation-created values, not the settings-screen editing) | `audit/journeys/settings/general-tab.md` |
| MV-SET-035 | Webhook Configuration | Free | compact | none | `audit/journeys/settings/general-tab.md` |

## Area: ADM (Admin screens) — 22 entries, compact

| ID | Title | Edition | Density | Existing Coverage | Proposed File |
|---|---|---|---|---|---|
| MV-ADM-001 | Overview / Dashboard page | Free | compact | none | `audit/journeys/admin/overview-and-demo-data.md` |
| MV-ADM-002 | Import Demo Data | Free | compact | none (Coding Rule 20's refusal-as-success fix references this exact button) | `audit/journeys/admin/overview-and-demo-data.md` |
| MV-ADM-003 | Delete Demo Data | Free | compact | none | `audit/journeys/admin/overview-and-demo-data.md` |
| MV-ADM-004 | All Media admin list | Free | compact | none | `audit/journeys/admin/media-list-and-actions.md` |
| MV-ADM-005 | Media bulk actions | Free | compact | none | `audit/journeys/admin/media-list-and-actions.md` |
| MV-ADM-006 | Media row actions | Free | compact | none | `audit/journeys/admin/media-list-and-actions.md` |
| MV-ADM-007 | Media "Optimize" row action / thumb repair | Free | compact | none | `audit/journeys/admin/media-list-and-actions.md` |
| MV-ADM-008 | Documents admin screen (Pro-gated) | Free | compact | `wpmediaverse-pro/audit/journeys/admin/02-documents-toggle-gates-surfaces.md` (admin screen absence, step 6) | `audit/journeys/admin/media-list-and-actions.md` |
| MV-ADM-009 | Tags list | Free | compact | none | `audit/journeys/admin/tags-management.md` |
| MV-ADM-010 | Tag edit screen | Free | compact | none | `audit/journeys/admin/tags-management.md` |
| MV-ADM-011 | Tag merge tool | Free | compact | none | `audit/journeys/admin/tags-management.md` |
| MV-ADM-012 | Moderation queue tabs | Free | compact | `admin/04-moderation-approve-flow.md` (step 6, AI Flagged tab visibility) | `audit/journeys/admin/04-moderation-approve-flow.md` |
| MV-ADM-013 | Moderation approve/reject (single + bulk) | Free | compact | `admin/04-moderation-approve-flow.md` (full single-approve flow) | `audit/journeys/admin/04-moderation-approve-flow.md` |
| MV-ADM-014 | Reports admin screen | Free | compact | `customer/25-reports-enabled-by-default.md` (§3, queue view + resolve) | `audit/journeys/admin/moderation-queue.md` |
| MV-ADM-015 | Member Moderation: Suspend/Restore | Free | compact | `security/06-blocked-member-cannot-interact.md` (§4, suspend + App Password + restore) | `audit/journeys/security/06-blocked-member-cannot-interact.md` |
| MV-ADM-016 | Member Moderation: per-user storage limit | Free | compact | none | `audit/journeys/admin/member-moderation.md` |
| MV-ADM-017 | Stats page | Free | compact | none | `audit/journeys/admin/stats-and-logs.md` |
| MV-ADM-018 | Log Viewer | Free | compact | none | `audit/journeys/admin/stats-and-logs.md` |
| MV-ADM-019 | Collection Settings meta box | Free | compact | none | `audit/journeys/admin/collection-settings-and-integrations.md` |
| MV-ADM-020 | Integrations page | Free | compact | none | `audit/journeys/admin/collection-settings-and-integrations.md` |
| MV-ADM-021 | Private Community Default notice | Free | compact | none | `audit/journeys/admin/collection-settings-and-integrations.md` |
| MV-ADM-022 | Role permissions (no matrix screen since 2.6.0) | Free | compact | none | `audit/journeys/admin/collection-settings-and-integrations.md` |

## Area: WIZ (Setup wizard, demo data, activation) — 8 entries, compact

| ID | Title | Edition | Density | Existing Coverage | Proposed File |
|---|---|---|---|---|---|
| MV-WIZ-001 | First-activation redirect to wizard | Free | compact | none | `audit/journeys/admin/12-setup-wizard-flow.md` |
| MV-WIZ-002 | Setup Wizard: Welcome step | Free | compact | none | `audit/journeys/admin/12-setup-wizard-flow.md` |
| MV-WIZ-003 | Setup Wizard: Display step | Free | compact | none | `audit/journeys/admin/12-setup-wizard-flow.md` |
| MV-WIZ-004 | Setup Wizard: Done step | Free | compact | none | `audit/journeys/admin/12-setup-wizard-flow.md` |
| MV-WIZ-005 | Activation creates frontend pages | Free | compact | `admin/06-activation-creates-pages.md` (full flow) | `audit/journeys/admin/06-activation-creates-pages.md` |
| MV-WIZ-006 | Activation never edits site navigation menus | Free | compact | `admin/06-activation-creates-pages.md` (implied by the safe-adoption steps; not a direct menu check) | `audit/journeys/admin/06-activation-creates-pages.md` |
| MV-WIZ-007 | Import Demo Data (cross-reference) | Free | compact | = MV-ADM-002 | `audit/journeys/admin/overview-and-demo-data.md` |
| MV-WIZ-008 | Delete Demo Data (cross-reference) | Free | compact | = MV-ADM-003 | `audit/journeys/admin/overview-and-demo-data.md` |

## Area: HLT (Site Health) — 5 entries, compact

| ID | Title | Edition | Density | Existing Coverage | Proposed File |
|---|---|---|---|---|---|
| MV-HLT-001 | MediaVerse Database Tables | Free | compact | none | `audit/journeys/admin/site-health-checks.md` |
| MV-HLT-002 | MediaVerse Upload Directory | Free | compact | none | `audit/journeys/admin/site-health-checks.md` |
| MV-HLT-003 | MediaVerse Required Pages | Free | compact | none | `audit/journeys/admin/site-health-checks.md` |
| MV-HLT-004 | MediaVerse Media Privacy | Free | compact | `security/05-private-media-local-and-gated.md` (5b, the probe's own subject matter — different angle) | `audit/journeys/admin/site-health-checks.md` |
| MV-HLT-005 | MediaVerse Template Overrides | Free | compact | none | `audit/journeys/admin/site-health-checks.md` |

## Area: CLI (WP-CLI) — 20 entries, compact

| ID | Title | Edition | Density | Existing Coverage | Proposed File |
|---|---|---|---|---|---|
| MV-CLI-001 | `wp mvs stats` | Free | compact | none | `audit/journeys/cli/maintenance-and-diagnostics.md` |
| MV-CLI-002 | `wp mvs migrate` | Free | compact | none | `audit/journeys/cli/maintenance-and-diagnostics.md` |
| MV-CLI-003 | `wp mvs prune-views` | Free | compact | none | `audit/journeys/cli/maintenance-and-diagnostics.md` |
| MV-CLI-004 | `wp mvs cleanup-expired` | Free | compact | none | `audit/journeys/cli/maintenance-and-diagnostics.md` |
| MV-CLI-005 | `wp mvs reindex` | Free | compact | none | `audit/journeys/cli/maintenance-and-diagnostics.md` |
| MV-CLI-006 | `wp mvs cache-flush` | Free | compact | none | `audit/journeys/cli/maintenance-and-diagnostics.md` |
| MV-CLI-007 | `wp mvs backfill-activity-thumbnails` | Free | compact | none | `audit/journeys/cli/buddypress-integration-cli.md` |
| MV-CLI-008 | `wp mvs regenerate-thumbnails` | Free | compact | none | `audit/journeys/cli/media-repair-and-optimize.md` |
| MV-CLI-009 | `wp mvs moderation-stats` | Free | compact | none | `audit/journeys/cli/maintenance-and-diagnostics.md` |
| MV-CLI-010 | `wp mvs sync-activity-privacy` | Free | compact | none | `audit/journeys/cli/buddypress-integration-cli.md` |
| MV-CLI-011 | `wp mvs migrate-storage` | Free | compact | `admin/08-storage-switch-migrate-mobile.md` (step 5b, full variant-carrying check) | `audit/journeys/admin/08-storage-switch-migrate-mobile.md` |
| MV-CLI-012 | `wp mvs cloud-thumbs-backfill` | Free | compact | none | `audit/journeys/cli/storage-migration.md` |
| MV-CLI-013 | `wp mvs backfill-placeholder-color` | Free | compact | none | `audit/journeys/cli/media-repair-and-optimize.md` |
| MV-CLI-014 | `wp mvs cleanup-local` | Free | compact | none | `audit/journeys/cli/storage-migration.md` |
| MV-CLI-015 | `wp mvs relocalize-private` | Free | compact | none | `audit/journeys/cli/storage-migration.md` |
| MV-CLI-016 | `wp mvs optimize` / `optimize-bulk` | Free | compact | none | `audit/journeys/cli/media-repair-and-optimize.md` |
| MV-CLI-017 | `wp mvs backfill-ai` | Free | compact | none | `audit/journeys/cli/media-repair-and-optimize.md` |
| MV-CLI-018 | `wp mvs repair-storage` | Free | compact | none | `audit/journeys/cli/maintenance-and-diagnostics.md` |
| MV-CLI-019 | `wp mvs diagnose-cpt-ids` | Free | compact | none | `audit/journeys/cli/maintenance-and-diagnostics.md` |
| MV-CLI-020 | `wp mvs cert` | Free | compact | none | `audit/journeys/cli/maintenance-and-diagnostics.md` |

## Area: PRV (Privacy export/erase, user deletion) — 9 entries, compact

| ID | Title | Edition | Density | Existing Coverage | Proposed File |
|---|---|---|---|---|---|
| MV-PRV-001 | Personal Data Export: MediaVerse (all data) | Free | compact | none | `audit/journeys/privacy/gdpr-export-erase.md` |
| MV-PRV-002 | Personal Data Export: MediaVerse Media | Free | compact | none | `audit/journeys/privacy/gdpr-export-erase.md` |
| MV-PRV-003 | Personal Data Export: Social Data | Free | compact | none | `audit/journeys/privacy/gdpr-export-erase.md` |
| MV-PRV-004 | Personal Data Erasure: MediaVerse | Free | compact | none | `audit/journeys/privacy/gdpr-export-erase.md` |
| MV-PRV-005 | User deletion: "Attribute all content to" | Free | compact | none | `audit/journeys/privacy/user-deletion.md` |
| MV-PRV-006 | User deletion: media-only members get reassignment | Free | compact | none | `audit/journeys/privacy/user-deletion.md` |
| MV-PRV-007 | Member self-service account deletion | Free | compact | none | `audit/journeys/privacy/user-deletion.md` |
| MV-PRV-008 | Remove Data on Delete (cross-reference) | Free | compact | = MV-SET-006 | `audit/journeys/privacy/gdpr-export-erase.md` |
| MV-PRV-009 | Privacy Policy content suggestion | Free | compact | none | `audit/journeys/privacy/gdpr-export-erase.md` |

## Area: TPL (Template overrides and versioning) — 3 entries, compact

| ID | Title | Edition | Density | Existing Coverage | Proposed File |
|---|---|---|---|---|---|
| MV-TPL-001 | Theme template override mechanism | Free | compact | none | `audit/journeys/admin/13-template-overrides.md` |
| MV-TPL-002 | Template `@version` header staleness | Free | compact | none | `audit/journeys/admin/13-template-overrides.md` |
| MV-TPL-003 | Pro layout templates and theme copies | Free+Pro | compact | none | `audit/journeys/admin/13-template-overrides.md` |

## Area: API (REST `mvs/v1`) — 22 entries, compact

| ID | Title | Edition | Density | Existing Coverage | Proposed File |
|---|---|---|---|---|---|
| MV-API-001 | Media: list and single read | Free | compact | `security/02-anonymous-cannot-modify.md` (adjacent, write-only) | `audit/journeys/api/media-crud-and-social.md` |
| MV-API-002 | Media: create (upload) | Free | compact | `customer/01-media-upload-public.md` (full) | `audit/journeys/api/media-crud-and-social.md` |
| MV-API-003 | Media: update and delete | Free | compact | `security/02-anonymous-cannot-modify.md` (anon refusal); `customer/22-edit-save-and-comment-no-post-leak.md` (owner edit) | `audit/journeys/api/media-crud-and-social.md` |
| MV-API-004 | Media: bulk actions | Free | compact | none | `audit/journeys/api/media-crud-and-social.md` |
| MV-API-005 | Media: replace file | Free | compact | `customer/24-watermark-ingest-paths.md` (Pro angle only) | `audit/journeys/api/media-crud-and-social.md` |
| MV-API-006 | Media: view/download/share/report tracking | Free | compact | `customer/25-reports-enabled-by-default.md` (§1, report tracking) | `audit/journeys/api/media-crud-and-social.md` |
| MV-API-007 | Media: signed URL/access/group/stats | Free | compact | `security/03-signed-url-expires.md` (TTL); `security/05-private-media-local-and-gated.md` (privacy) | `audit/journeys/api/media-crud-and-social.md` |
| MV-API-008 | Media: comments | Free | compact | `customer/22-edit-save-and-comment-no-post-leak.md` (post+comment-id-zero) | `audit/journeys/api/media-crud-and-social.md` |
| MV-API-009 | Media: reactions and favorites | Free | compact | `customer/22-edit-save-and-comment-no-post-leak.md` (step 6, reaction) | `audit/journeys/api/media-crud-and-social.md` |
| MV-API-010 | Albums: CRUD, items, cover, reorder | Free | compact | `customer/21-album-privacy-and-pagination.md` (list/single privacy) | `audit/journeys/api/albums-collections-tags.md` |
| MV-API-011 | Collections: CRUD, items, rules | Free | compact | `customer/09-smart-collection-multi-rule.md` (create+rules) | `audit/journeys/api/albums-collections-tags.md` |
| MV-API-012 | Tags: list, cloud, create, merge, update, delete | Free | compact | none | `audit/journeys/api/albums-collections-tags.md` |
| MV-API-013 | Moderation: queue, counts, analyze/approve/reject | Free | compact | `admin/04-moderation-approve-flow.md` (REST approve step 3) | `audit/journeys/api/moderation-and-social.md` |
| MV-API-014 | Users: profile/media/activity/followers/block/follow/report | Free | compact | `security/06-blocked-member-cannot-interact.md` (block/report exemptions) | `audit/journeys/api/moderation-and-social.md` |
| MV-API-015 | Me: profile/media/stats/storage/favorites/etc. | Free | compact | none | `audit/journeys/api/me-and-messaging.md` |
| MV-API-016 | Conversations and messages | Free | compact | `customer/17-messaging-send-reliability.md` (send/retry); `security/06-blocked-member-cannot-interact.md` (block gate) | `audit/journeys/api/me-and-messaging.md` |
| MV-API-017 | Auth: nonce refresh, Application Password exchange | Free | compact | none | `audit/journeys/api/auth-app-feed-serve.md` |
| MV-API-018 | App: config and interests | Free | compact | `security/06-private-community-gate.md` (app/config exemption) | `audit/journeys/api/auth-app-feed-serve.md` |
| MV-API-019 | Admin: welcome dismiss | Free | compact | none | `audit/journeys/api/auth-app-feed-serve.md` |
| MV-API-020 | AI usage | Free | compact | none | `audit/journeys/api/auth-app-feed-serve.md` |
| MV-API-021 | Feed | Free | compact | none | `audit/journeys/api/auth-app-feed-serve.md` |
| MV-API-022 | Serve (signed media delivery) | Free | compact | `security/03-signed-url-expires.md`; `security/05-private-media-local-and-gated.md`; `security/06-private-community-gate.md` (all touch `/serve` from different angles) | `audit/journeys/api/auth-app-feed-serve.md` |

## Area: LAY (Pro layouts) — 16 entries, narrative

| ID | Title | Edition | Density | Existing Coverage | Proposed File |
|---|---|---|---|---|---|
| MV-LAY-001 | Global layout mode switcher | Pro | narrative | none | `audit/pro/journeys/customer/layout-switching-and-parity.md` |
| MV-LAY-002 | Instagram layout on Explore | Pro | narrative | none | `audit/pro/journeys/customer/instagram-layout.md` |
| MV-LAY-003 | Pinterest layout on Explore | Pro | narrative | none | `audit/pro/journeys/customer/pinterest-layout.md` |
| MV-LAY-004 | Flickr layout on Explore | Pro | narrative | none | `audit/pro/journeys/customer/flickr-layout.md` |
| MV-LAY-005 | Dribbble layout on Explore | Pro | narrative | none | `audit/pro/journeys/customer/dribbble-layout.md` |
| MV-LAY-006 | Instagram layout on a member profile | Pro | narrative | none | `audit/pro/journeys/customer/instagram-layout.md` |
| MV-LAY-007 | Pinterest layout on a member profile | Pro | narrative | none | `audit/pro/journeys/customer/pinterest-layout.md` |
| MV-LAY-008 | Flickr layout on a member profile | Pro | narrative | none | `audit/pro/journeys/customer/flickr-layout.md` |
| MV-LAY-009 | Dribbble layout on a member profile | Pro | narrative | none | `audit/pro/journeys/customer/dribbble-layout.md` |
| MV-LAY-010 | Search parity across all four layouts | Pro | narrative | none | `audit/pro/journeys/customer/layout-switching-and-parity.md` |
| MV-LAY-011 | Sort parity across all four layouts | Pro | narrative | none | `audit/pro/journeys/customer/layout-switching-and-parity.md` |
| MV-LAY-012 | Tag/category chip filtering parity | Pro | narrative | none | `audit/pro/journeys/customer/layout-switching-and-parity.md` |
| MV-LAY-013 | The four feed blocks in the block editor | Pro | narrative | none | `audit/pro/journeys/customer/layout-blocks-and-mobile.md` |
| MV-LAY-014 | Mobile/390px across all four layouts | Pro | narrative | none | `audit/pro/journeys/customer/layout-blocks-and-mobile.md` |
| MV-LAY-015 | Cross-layout feature-parity audit | Pro | narrative | none | `audit/pro/journeys/customer/layout-switching-and-parity.md` |
| MV-LAY-016 | Block `render.php` must enqueue layout assets | Pro | narrative | none | `audit/pro/journeys/customer/layout-switching-and-parity.md` |

## Area: CMP (Compete hub) — 11 entries, narrative

| ID | Title | Edition | Density | Existing Coverage | Proposed File |
|---|---|---|---|---|---|
| MV-CMP-001 | Compete hub landing at /compete/ | Pro | narrative | `audit/pro/journeys/customer/08-compete-hub-has-a-way-back.md` (partial — the "Back to My Media" link only, incl. 390px + foreign-theme) | `audit/pro/journeys/customer/compete-hub-cards.md` |
| MV-CMP-002 | Compete hub not reachable from nav by default | Pro | narrative | none | `audit/pro/journeys/customer/compete-hub-admin-and-api.md` |
| MV-CMP-003 | Active Challenge card | Pro | narrative | `audit/pro/journeys/admin/06-competition-toggles-gate-their-own-section.md` (partial — toggle-off hides the card); `audit/pro/journeys/customer/07-challenge-entry-happy-path.md` (partial — hub entry-count + CTA-target correctness) | `audit/pro/journeys/customer/compete-hub-cards.md` |
| MV-CMP-004 | Open Tournaments card | Pro | narrative | `audit/pro/journeys/admin/06-competition-toggles-gate-their-own-section.md` (partial — toggle-off hides the card); `audit/pro/journeys/customer/06-tournament-registration-window-is-honest.md` (partial — Register CTA / spots-left honesty specifically) | `audit/pro/journeys/customer/compete-hub-cards.md` |
| MV-CMP-005 | Battle Arena card and Recent Results | Pro | narrative | `audit/pro/journeys/admin/06-competition-toggles-gate-their-own-section.md` (partial — toggle-off hides the card); `audit/pro/journeys/customer/05-compete-results-departed-members.md` (partial — deleted/anonymised-member labelling in Recent Results specifically) | `audit/pro/journeys/customer/compete-hub-cards.md` |
| MV-CMP-006 | Hub REST summary endpoint | Pro | narrative | `audit/pro/journeys/admin/06-competition-toggles-gate-their-own-section.md` (partial — exercises `get_active_challenge()`/`get_open_tournaments()` toggle-gating, not the cache-busting or community-private edge cases) | `audit/pro/journeys/customer/compete-hub-admin-and-api.md` |
| MV-CMP-007 | First-run guided view | Pro | narrative | none | `audit/pro/journeys/customer/compete-hub-cards.md` |
| MV-CMP-008 | Dashboard tab-switching regression guard | Pro | narrative | none | `audit/pro/journeys/customer/compete-hub-admin-and-api.md` |
| MV-CMP-009 | Compete hub Gutenberg block | Pro | narrative | none | `audit/pro/journeys/customer/compete-hub-admin-and-api.md` |
| MV-CMP-010 | Compete pages respect Members-only gate | Pro | narrative | none | `audit/pro/journeys/customer/compete-hub-admin-and-api.md` |
| MV-CMP-011 | Points balance chip (WB Gamification optional) | Pro | narrative | none | `audit/pro/journeys/customer/compete-hub-cards.md` |

## Area: BAT (Battles) — 15 entries, narrative

| ID | Title | Edition | Density | Existing Coverage | Proposed File |
|---|---|---|---|---|---|
| MV-BAT-001 | Create a battle challenge | Pro | narrative | none | `audit/pro/journeys/customer/battles-lifecycle.md` |
| MV-BAT-002 | Opponent receives and responds | Pro | narrative | none | `audit/pro/journeys/customer/battles-lifecycle.md` |
| MV-BAT-003 | Submit media entry for a battle | Pro | narrative | none | `audit/pro/journeys/customer/battles-lifecycle.md` |
| MV-BAT-004 | Voting phase and casting a vote | Pro | narrative | none (setup-only step in `customer/03-battle-win-xp-configured.md`, not asserted there) | `audit/pro/journeys/customer/battles-lifecycle.md` |
| MV-BAT-005 | Battle resolution and winner determination | Pro | narrative | `audit/pro/journeys/customer/03-battle-win-xp-configured.md` (full — resolve, configured-XP snapshot, live fallback) | `audit/pro/journeys/customer/03-battle-win-xp-configured.md` |
| MV-BAT-006 | Battle notifications (in-app + email) | Pro | narrative | `audit/pro/journeys/customer/03-battle-win-xp-configured.md` (step 6, winner+loser notification) | `audit/pro/journeys/customer/03-battle-win-xp-configured.md` |
| MV-BAT-007 | Battles list and single-battle deep link | Pro | narrative | none | `audit/pro/journeys/customer/battles-lifecycle.md` |
| MV-BAT-008 | Battle Monitor admin | Pro | narrative | none | `audit/pro/journeys/admin/battle-monitor.md` |
| MV-BAT-009 | Battle expiry (unaccepted/unsubmitted) | Pro | narrative | none | `audit/pro/journeys/admin/battle-monitor.md` |
| MV-BAT-010 | Voting/entry cutoff enforcement matches display | Pro | narrative | none | `audit/pro/journeys/customer/battles-edge-cases.md` |
| MV-BAT-011 | License-not-required for battles | Pro | narrative | none | `audit/pro/journeys/customer/battles-edge-cases.md` |
| MV-BAT-012 | `mvs/pro-battle` block (single embed) | Pro | narrative | none | `audit/pro/journeys/customer/battles-blocks-and-mobile.md` |
| MV-BAT-013 | `mvs/pro-battles-active` block | Pro | narrative | none | `audit/pro/journeys/customer/battles-blocks-and-mobile.md` |
| MV-BAT-014 | Mobile layout at 390px | Pro | narrative | none | `audit/pro/journeys/customer/battles-blocks-and-mobile.md` |
| MV-BAT-015 | Concurrency: voting/accepting races | Pro | narrative | none | `audit/pro/journeys/customer/battles-edge-cases.md` |

## Area: CHL (Challenges) — 17 entries, narrative

| ID | Title | Edition | Density | Existing Coverage | Proposed File |
|---|---|---|---|---|---|
| MV-CHL-001 | Challenges list view | Pro | narrative | `audit/pro/journeys/customer/07-challenge-entry-happy-path.md` (partial — hub + challenges-page visibility only, not the tab/badge sweep) | `audit/pro/journeys/customer/challenges-lifecycle.md` |
| MV-CHL-002 | Challenge detail view | Pro | narrative | none | `audit/pro/journeys/customer/challenges-lifecycle.md` |
| MV-CHL-003 | Submit entry: new upload | Pro | narrative | `audit/pro/journeys/customer/07-challenge-entry-happy-path.md` (partial — only confirms an upload path exists for a no-photos member) | `audit/pro/journeys/customer/challenges-lifecycle.md` |
| MV-CHL-004 | Submit entry: pick from existing media | Pro | narrative | `audit/pro/journeys/customer/07-challenge-entry-happy-path.md` (full happy-path + entry-limit; ownership/duplicate-media-id negative guards still need adding) | `audit/pro/journeys/customer/07-challenge-entry-happy-path.md` |
| MV-CHL-005 | Submit entry after deadline is blocked | Pro | narrative | none | `audit/pro/journeys/customer/challenges-lifecycle.md` |
| MV-CHL-006 | Voting on entries | Pro | narrative | none | `audit/pro/journeys/customer/challenges-lifecycle.md` |
| MV-CHL-007 | Deadline countdown accuracy | Pro | narrative | none | `audit/pro/journeys/customer/challenges-lifecycle.md` |
| MV-CHL-008 | Finalization and winner announcement | Pro | narrative | none | `audit/pro/journeys/customer/challenges-lifecycle.md` |
| MV-CHL-009 | Four email notification events | Pro | narrative | none | `audit/pro/journeys/customer/challenges-notifications-and-mobile.md` |
| MV-CHL-010 | Autopilot: weekly scheduled creation | Pro | narrative | none | `audit/pro/journeys/admin/challenge-manager-and-themes.md` |
| MV-CHL-011 | Theme Library admin | Pro | narrative | none | `audit/pro/journeys/admin/challenge-manager-and-themes.md` |
| MV-CHL-012 | Challenge Manager admin lifecycle | Pro | narrative | `audit/pro/journeys/customer/07-challenge-entry-happy-path.md` (partial — Create action only, not edit/cancel/start-now/end-entries/finalize) | `audit/pro/journeys/admin/challenge-manager-and-themes.md` |
| MV-CHL-013 | `pro-challenge` block (single embed) | Pro | narrative | none | `audit/pro/journeys/customer/challenges-blocks.md` |
| MV-CHL-014 | `pro-challenges-list` block | Pro | narrative | none | `audit/pro/journeys/customer/challenges-blocks.md` |
| MV-CHL-015 | Master/sub toggle off behavior | Pro | narrative | `audit/pro/journeys/admin/06-competition-toggles-gate-their-own-section.md` (full — admin submenu, REST, frontend route, and hub-card removal, all in one pass) | `audit/pro/journeys/admin/06-competition-toggles-gate-their-own-section.md` |
| MV-CHL-016 | Mobile 390px | Pro | narrative | none | `audit/pro/journeys/customer/challenges-notifications-and-mobile.md` |
| MV-CHL-017 | `wp mvs competitions tick`/`recompute` | Pro | narrative | none | `audit/pro/journeys/cli/competitions-tick-cli.md` |

## Area: TRN (Tournaments) — 19 entries, narrative

| ID | Title | Edition | Density | Existing Coverage | Proposed File |
|---|---|---|---|---|---|
| MV-TRN-001 | Tournaments list view | Pro | narrative | `audit/pro/journeys/customer/06-tournament-registration-window-is-honest.md` (partial — default-tab check only, not the empty-tab strings) | `audit/pro/journeys/customer/tournaments-blocks-and-mobile.md` |
| MV-TRN-002 | Registration window (register/unregister) | Pro | narrative | `audit/pro/journeys/customer/06-tournament-registration-window-is-honest.md` (full for the registration-honesty half; unregister + duplicate-registration edge cases still need adding) | `audit/pro/journeys/customer/06-tournament-registration-window-is-honest.md` |
| MV-TRN-003 | Bracket creation and seeding | Pro | narrative | `audit/pro/journeys/customer/01-tournament-sparse-bracket-safety.md` (full) | `audit/pro/journeys/customer/01-tournament-sparse-bracket-safety.md` |
| MV-TRN-004 | Sparse bracket / bye handling | Pro | narrative | `audit/pro/journeys/customer/01-tournament-sparse-bracket-safety.md` (full — both-null skip, single-bye, max-sparseness) | `audit/pro/journeys/customer/01-tournament-sparse-bracket-safety.md` |
| MV-TRN-005 | Viewing the bracket | Pro | narrative | `audit/pro/journeys/customer/01-tournament-sparse-bracket-safety.md` (partial — step 6, clean 200 + no null-winner-non-bye check only) | `audit/pro/journeys/customer/tournaments-match-and-resolution.md` |
| MV-TRN-006 | Submit media for a match | Pro | narrative | none | `audit/pro/journeys/customer/tournaments-match-and-resolution.md` |
| MV-TRN-007 | Submit deadline missed (stale match) | Pro | narrative | none | `audit/pro/journeys/customer/tournaments-match-and-resolution.md` |
| MV-TRN-008 | Voting per matchup | Pro | narrative | none | `audit/pro/journeys/customer/tournaments-match-and-resolution.md` |
| MV-TRN-009 | Match resolution and tie handling | Pro | narrative | `audit/pro/journeys/customer/01-tournament-sparse-bracket-safety.md` (partial — step 8, admin manual resolve path only, not the auto tie-rule) | `audit/pro/journeys/customer/tournaments-match-and-resolution.md` |
| MV-TRN-010 | Round advancement | Pro | narrative | `audit/pro/journeys/customer/01-tournament-sparse-bracket-safety.md` (partial — step 8, next-round match creation on manual resolve only) | `audit/pro/journeys/customer/tournaments-match-and-resolution.md` |
| MV-TRN-011 | Final results and champion display | Pro | narrative | none | `audit/pro/journeys/customer/tournaments-match-and-resolution.md` |
| MV-TRN-012 | Notification events (in-app only) | Pro | narrative | `audit/pro/journeys/customer/01-tournament-sparse-bracket-safety.md` (partial — step 8, elimination notification only, not the champion notification) | `audit/pro/journeys/customer/tournaments-match-and-resolution.md` |
| MV-TRN-013 | Tournament Manager admin | Pro | narrative | `audit/pro/journeys/customer/01-tournament-sparse-bracket-safety.md` (partial — force-resolve action only, via step 8) | `audit/pro/journeys/customer/tournaments-match-and-resolution.md` |
| MV-TRN-014 | `pro-tournament` block | Pro | narrative | none | `audit/pro/journeys/customer/tournaments-blocks-and-mobile.md` |
| MV-TRN-015 | `pro-tournaments-list` block | Pro | narrative | none | `audit/pro/journeys/customer/tournaments-blocks-and-mobile.md` |
| MV-TRN-016 | Master/sub toggle off behavior | Pro | narrative | `audit/pro/journeys/admin/06-competition-toggles-gate-their-own-section.md` (full — admin submenu, REST, frontend route, hub-card removal) | `audit/pro/journeys/admin/06-competition-toggles-gate-their-own-section.md` |
| MV-TRN-017 | Mobile 390px bracket rendering | Pro | narrative | `audit/pro/journeys/customer/01-tournament-sparse-bracket-safety.md` (partial — step 9, 1280x800 + 390x844 screenshots) | `audit/pro/journeys/customer/tournaments-blocks-and-mobile.md` |
| MV-TRN-018 | Multi-actor concurrency | Pro | narrative | none | `audit/pro/journeys/customer/tournaments-match-and-resolution.md` |
| MV-TRN-019 | `wp mvs competitions tick`/`recompute` | Pro | narrative | none | `audit/pro/journeys/cli/competitions-tick-cli.md` |

## Area: BST (Boosts, Streaks, Leaderboard) — 15 entries, narrative

| ID | Title | Edition | Density | Existing Coverage | Proposed File |
|---|---|---|---|---|---|
| MV-BST-001 | Boost a media item | Pro | narrative | `audit/pro/journeys/customer/04-boost-promotes-feed.md` (full — button regression, REST create, real deduction) | `audit/pro/journeys/customer/04-boost-promotes-feed.md` |
| MV-BST-002 | Boost expiry, impressions, promotion | Pro | narrative | `audit/pro/journeys/customer/04-boost-promotes-feed.md` (full — feed promotion + impression-target completion) | `audit/pro/journeys/customer/04-boost-promotes-feed.md` |
| MV-BST-003 | Boost affordance across surfaces | Pro | narrative | `audit/pro/journeys/customer/04-boost-promotes-feed.md` (full — the exact 2026-06-22 feed-card dropped-button regression) | `audit/pro/journeys/customer/04-boost-promotes-feed.md` |
| MV-BST-004 | Boosts master-switch and points-backend dependency | Pro | narrative | `audit/pro/journeys/admin/02-feature-toggles-gate-all-routes.md` (partial — `mvs_boosts_enabled` route+menu gating only, not the points-backend/`show_when` settings-row behavior) | `audit/pro/journeys/admin/gamification-settings.md` |
| MV-BST-005 | Upload streak counting, day-to-day | Pro | narrative | none | `audit/pro/journeys/customer/streaks-flow.md` |
| MV-BST-006 | Streak freeze: gap-bridging, daily cron | Pro | narrative | `audit/pro/journeys/customer/02-streak-freeze-proportional-cost.md` (full — proportional one-freeze-per-missed-day on upload); `audit/pro/journeys/system/01-streak-daily-check-bounded.md` (full — the bounded/keyset-paginated `daily_check()` cron half) | `audit/pro/journeys/customer/02-streak-freeze-proportional-cost.md` |
| MV-BST-007 | Buy a streak freeze token | Pro | narrative | `audit/pro/journeys/customer/02-streak-freeze-proportional-cost.md` (full — atomic debit, insufficient-points, debit-failure paths) | `audit/pro/journeys/customer/02-streak-freeze-proportional-cost.md` |
| MV-BST-008 | Streak feature toggle OFF: full behavior | Pro | narrative | none | `audit/pro/journeys/customer/streaks-flow.md` |
| MV-BST-009 | Streak badge next to display name | Pro | narrative | none | `audit/pro/journeys/customer/streaks-flow.md` |
| MV-BST-010 | Streak milestone XP awards | Pro | narrative | none | `audit/pro/journeys/customer/streaks-flow.md` |
| MV-BST-011 | Leaderboard display: sources and windows | Pro | narrative | none | `audit/pro/journeys/customer/leaderboard-and-mobile.md` |
| MV-BST-012 | Leaderboard viewer's own rank | Pro | narrative | none | `audit/pro/journeys/customer/leaderboard-and-mobile.md` |
| MV-BST-013 | Gamification Settings admin page | Pro | narrative | none | `audit/pro/journeys/admin/gamification-settings.md` |
| MV-BST-014 | WB Gamification points bridge | Pro | narrative | none | `audit/pro/journeys/admin/gamification-settings.md` |
| MV-BST-015 | Mobile/390px for Boosts/Streaks/Leaderboard | Pro | narrative | none | `audit/pro/journeys/customer/leaderboard-and-mobile.md` |

## Area: STY (Stories) — 14 entries, narrative

| ID | Title | Edition | Density | Existing Coverage | Proposed File |
|---|---|---|---|---|---|
| MV-STY-001 | Enable / disable Stories | Pro | narrative | none | `audit/pro/journeys/customer/stories-posting-and-viewing.md` |
| MV-STY-002 | Posting a story from an upload surface | Pro | narrative | none | `audit/pro/journeys/customer/stories-posting-and-viewing.md` |
| MV-STY-003 | "Your story" add tile | Pro | narrative | none | `audit/pro/journeys/customer/stories-posting-and-viewing.md` |
| MV-STY-004 | Stories bar auto-display on Instagram | Pro | narrative | none | `audit/pro/journeys/customer/stories-posting-and-viewing.md` |
| MV-STY-005 | Stories bar manually placed on any layout | Pro | narrative | none | `audit/pro/journeys/customer/stories-posting-and-viewing.md` |
| MV-STY-006 | Viewing a story (image) | Pro | narrative | none | `audit/pro/journeys/customer/stories-posting-and-viewing.md` |
| MV-STY-007 | Viewing a story (video/audio) | Pro | narrative | none | `audit/pro/journeys/customer/stories-posting-and-viewing.md` |
| MV-STY-008 | Dialog focus-trap and keyboard behavior | Pro | narrative | none | `audit/pro/journeys/customer/stories-accessibility-and-viewers.md` |
| MV-STY-009 | "Seen by" viewer counts (owner-only) | Pro | narrative | none | `audit/pro/journeys/customer/stories-accessibility-and-viewers.md` |
| MV-STY-010 | Story expiry and hourly cleanup cron | Pro | narrative | none | `audit/pro/journeys/admin/stories-moderation.md` |
| MV-STY-011 | Admin moderation: Stories list + Force expire | Pro | narrative | none | `audit/pro/journeys/admin/stories-moderation.md` |
| MV-STY-012 | REST API surface and permission checks | Pro | narrative | none | `audit/pro/journeys/api/stories-api-and-mobile.md` |
| MV-STY-013 | Mobile app feature-flag interaction | Pro | narrative | none | `audit/pro/journeys/api/stories-api-and-mobile.md` |
| MV-STY-014 | Feature-off and unlicensed-state edge behavior | Pro | narrative | none | `audit/pro/journeys/api/stories-api-and-mobile.md` |

## Area: DOC (Documents) — 45 entries, narrative

| ID | Title | Edition | Density | Existing Coverage | Proposed File |
|---|---|---|---|---|---|
| MV-DOC-001 | Opening your document drive (frontend) | Pro | narrative | `wpmediaverse-pro/admin/03-documents-settings-and-role-gate.md` (§5, capability-off surface loss) | `audit/pro/journeys/customer/documents-drive-basics.md` |
| MV-DOC-002 | Uploading a document, licensed member | Pro | narrative | none | `audit/pro/journeys/customer/documents-drive-basics.md` |
| MV-DOC-003 | Uploading a document, unlicensed (refused) | Pro | narrative | none | `audit/pro/journeys/customer/documents-drive-basics.md` |
| MV-DOC-004 | Uploading, unlicensed but administering member | Pro | narrative | none | `audit/pro/journeys/customer/documents-drive-basics.md` |
| MV-DOC-005 | Replacing a document's bytes | Pro | narrative | none | `audit/pro/journeys/customer/documents-drive-basics.md` |
| MV-DOC-006 | Downloading a document | Pro | narrative | none | `audit/pro/journeys/customer/documents-download-and-preview.md` |
| MV-DOC-007 | Previewing a PDF (tier 1, inline) | Pro | narrative | `security/07-document-never-in-media-surface.md` (step 12, reachable-at-own-URL, different angle) | `audit/pro/journeys/customer/documents-download-and-preview.md` |
| MV-DOC-008 | Previewing text/Markdown/CSV (tier 2) | Pro | narrative | none | `audit/pro/journeys/customer/documents-download-and-preview.md` |
| MV-DOC-009 | Previewing Office/ODF/RTF/archive (tier 3/4) | Pro | narrative | none | `audit/pro/journeys/customer/documents-download-and-preview.md` |
| MV-DOC-010 | Creating a folder | Pro | narrative | none | `audit/pro/journeys/customer/documents-folders.md` |
| MV-DOC-011 | Renaming a folder | Pro | narrative | none | `audit/pro/journeys/customer/documents-folders.md` |
| MV-DOC-012 | Moving/nesting a folder | Pro | narrative | none | `audit/pro/journeys/customer/documents-folders.md` |
| MV-DOC-013 | Trashing a document | Pro | narrative | none | `audit/pro/journeys/customer/documents-trash-and-restore.md` |
| MV-DOC-014 | Restoring a document as the uploader | Pro | narrative | none | `audit/pro/journeys/customer/documents-trash-and-restore.md` |
| MV-DOC-015 | Restoring as documents-admin (non-uploader) | Pro | narrative | none | `audit/pro/journeys/customer/documents-trash-and-restore.md` |
| MV-DOC-016 | Restoring as an unrelated member (refused) | Pro | narrative | none | `audit/pro/journeys/customer/documents-trash-and-restore.md` |
| MV-DOC-017 | Trash listing visibility | Pro | narrative | none | `audit/pro/journeys/customer/documents-trash-and-restore.md` |
| MV-DOC-018 | Permanent delete and on-disk removal | Pro | narrative | none | `audit/pro/journeys/customer/documents-trash-and-restore.md` |
| MV-DOC-019 | Default privacy setting | Pro | narrative | `wpmediaverse-pro/admin/03-documents-settings-and-role-gate.md` (§11) | `audit/pro/journeys/customer/documents-privacy.md` |
| MV-DOC-020 | Private/members/public visibility per role | Pro | narrative | none | `audit/pro/journeys/customer/documents-privacy.md` |
| MV-DOC-021 | Space privacy without BuddyNext (refused) | Pro | narrative | none | `audit/pro/journeys/customer/documents-privacy.md` |
| MV-DOC-022 | Space privacy with BuddyNext active | Pro | narrative | none | `audit/pro/journeys/customer/documents-privacy.md` |
| MV-DOC-023 | Sharing a document with a named member | Pro | narrative | none | `audit/pro/journeys/customer/documents-sharing.md` |
| MV-DOC-024 | Revoking a share (licence-exempt) | Pro | narrative | none | `audit/pro/journeys/customer/documents-sharing.md` |
| MV-DOC-025 | Sharing with a role (refused going forward) | Pro | narrative | none | `audit/pro/journeys/customer/documents-sharing.md` |
| MV-DOC-026 | Permission share link (create) | Pro | narrative | `wpmediaverse-pro/admin/03-documents-settings-and-role-gate.md` (§12, mint+redeem) | `audit/pro/journeys/customer/documents-sharing.md` |
| MV-DOC-027 | Anonymous share links on/off, revocation | Pro | narrative | `wpmediaverse-pro/admin/03-documents-settings-and-role-gate.md` (§12) | `audit/pro/journeys/customer/documents-sharing.md` |
| MV-DOC-028 | Searching a drive (title/content/tags, PDF exception) | Pro | narrative | `wpmediaverse-pro/admin/03-documents-settings-and-role-gate.md` (§13, extraction toggle only) | `audit/pro/journeys/customer/documents-search-and-spaces.md` |
| MV-DOC-029 | Space search covering linked files | Pro | narrative | none | `audit/pro/journeys/customer/documents-search-and-spaces.md` |
| MV-DOC-030 | Linking a document into multiple spaces | Pro | narrative | none | `audit/pro/journeys/customer/documents-search-and-spaces.md` |
| MV-DOC-031 | Unlinking a document from a space | Pro | narrative | none | `audit/pro/journeys/customer/documents-search-and-spaces.md` |
| MV-DOC-032 | Space drive cleanup on space deletion | Pro | narrative | none | `audit/pro/journeys/customer/documents-search-and-spaces.md` |
| MV-DOC-033 | Orphan reclaim dry run + delete via settings | Pro | narrative | none | `audit/pro/journeys/admin/documents-maintenance.md` |
| MV-DOC-034 | Orphan reclaim via WP-CLI | Pro | narrative | none | `audit/pro/journeys/admin/documents-maintenance.md` |
| MV-DOC-035 | Allowed-types restriction (absent vs empty) | Pro | narrative | `wpmediaverse-pro/admin/03-documents-settings-and-role-gate.md` (§10) | `audit/pro/journeys/admin/documents-maintenance.md` |
| MV-DOC-036 | Max-size clamp to server limit | Pro | narrative | `wpmediaverse-pro/admin/03-documents-settings-and-role-gate.md` (§9) | `audit/pro/journeys/admin/documents-maintenance.md` |
| MV-DOC-037 | Capability grant via Permissions matrix | Pro | narrative | `wpmediaverse-pro/admin/03-documents-settings-and-role-gate.md` (§1-4) | `audit/pro/journeys/admin/documents-capabilities.md` |
| MV-DOC-038 | Capability grant via Documents settings | Pro | narrative | `wpmediaverse-pro/admin/03-documents-settings-and-role-gate.md` (§3) | `audit/pro/journeys/admin/documents-capabilities.md` |
| MV-DOC-039 | Per-user capability override filter | Pro | narrative | none | `audit/pro/journeys/admin/documents-capabilities.md` |
| MV-DOC-040 | GDPR export/erase of document data | Pro | narrative | none | `audit/pro/journeys/api/documents-gdpr-and-profile.md` |
| MV-DOC-041 | Profile Documents sub-tab | Pro | narrative | none | `audit/pro/journeys/api/documents-gdpr-and-profile.md` |
| MV-DOC-042 | Admin document list, single view, Pro panels | Pro | narrative | none | `audit/pro/journeys/admin/documents-admin-screen-and-health.md` |
| MV-DOC-043 | Health check (Site Health) | Pro | narrative | none | `audit/pro/journeys/admin/documents-admin-screen-and-health.md` |
| MV-DOC-044 | App config surface for a native client | Pro | narrative | none | `audit/pro/journeys/api/documents-app-config-and-blocks.md` |
| MV-DOC-045 | Document embed and document list blocks | Pro | narrative | none | `audit/pro/journeys/api/documents-app-config-and-blocks.md` |

## Area: VID (Video: chapters, resume, captions, analytics) — 15 entries, narrative

| ID | Title | Edition | Density | Existing Coverage | Proposed File |
|---|---|---|---|---|---|
| MV-VID-001 | Add/list video chapters (REST only) | Pro | narrative | none | `audit/pro/journeys/api/video-chapters.md` |
| MV-VID-002 | Chapters surfaced in media REST response | Pro | narrative | none | `audit/pro/journeys/api/video-chapters.md` |
| MV-VID-003 | Chapter privacy on read (private video) | Pro | narrative | none | `audit/pro/journeys/api/video-chapters.md` |
| MV-VID-004 | Web player auto-resume on load | Pro | narrative | none | `audit/pro/journeys/customer/video-resume-playback.md` |
| MV-VID-005 | Resume position saving during playback | Pro | narrative | none | `audit/pro/journeys/customer/video-resume-playback.md` |
| MV-VID-006 | Resume cleared on completion/"Start over" | Pro | narrative | none | `audit/pro/journeys/customer/video-resume-playback.md` |
| MV-VID-007 | Resume REST endpoints for a direct API client | Pro | narrative | none | `audit/pro/journeys/customer/video-resume-playback.md` |
| MV-VID-008 | Auto-caption generation on upload | Pro | narrative | none | `audit/pro/journeys/customer/video-captions.md` |
| MV-VID-009 | Manually request caption generation + status | Pro | narrative | none | `audit/pro/journeys/customer/video-captions.md` |
| MV-VID-010 | Caption job stale-processing reaper | Pro | narrative | none | `audit/pro/journeys/customer/video-captions.md` |
| MV-VID-011 | Whisper key missing/invalid, oversized file | Pro | narrative | none | `audit/pro/journeys/customer/video-captions.md` |
| MV-VID-012 | Manual caption upload/replace and delete | Pro | narrative | none | `audit/pro/journeys/customer/video-captions.md` |
| MV-VID-013 | Web visitor sees no captions ever | Pro | narrative | none | `audit/pro/journeys/customer/video-captions.md` |
| MV-VID-014 | Video Analytics admin dashboard | Pro | narrative | none | `audit/pro/journeys/admin/video-analytics.md` |
| MV-VID-015 | Play event ingestion, rate limiting, pruning | Pro | narrative | none | `audit/pro/journeys/admin/video-analytics.md` |

## Area: PPV (Pro advanced privacy) — 9 entries, narrative

| ID | Title | Edition | Density | Existing Coverage | Proposed File |
|---|---|---|---|---|---|
| MV-PPV-001 | Update privacy on a single media item | Pro | narrative | none | `audit/pro/journeys/api/advanced-privacy.md` |
| MV-PPV-002 | Bulk privacy update across multiple items | Pro | narrative | none | `audit/pro/journeys/api/advanced-privacy.md` |
| MV-PPV-003 | Privacy lock honored by Pro's routes | Pro | narrative | none | `audit/pro/journeys/api/advanced-privacy.md` |
| MV-PPV-004 | "Followers Only" privacy not enforceable (bug) | Pro | narrative | none | `audit/pro/journeys/api/advanced-privacy.md` |
| MV-PPV-005 | Privacy presets: save and retrieve | Pro | narrative | none | `audit/pro/journeys/api/advanced-privacy.md` |
| MV-PPV-006 | Presets: relevance surfaced in media response | Pro | narrative | none | `audit/pro/journeys/api/advanced-privacy.md` |
| MV-PPV-007 | Album-level "inherit album privacy" | Pro | narrative | none | `audit/pro/journeys/api/advanced-privacy.md` |
| MV-PPV-008 | Flickr import sync no longer overrides locked privacy | Pro | narrative | none | `audit/pro/journeys/api/advanced-privacy.md` |
| MV-PPV-009 | GDPR export/erase covers Pro privacy data | Pro | narrative | none | `audit/pro/journeys/api/advanced-privacy.md` |

## Area: STO (Cloud storage drivers) — 11 entries, narrative

| ID | Title | Edition | Density | Existing Coverage | Proposed File |
|---|---|---|---|---|---|
| MV-STO-001 | Configure Amazon S3 as active driver | Pro | narrative | `audit/pro/journeys/admin/03-pro-storage-driver-registers.md` (partial — filter returns the driver object + dropdown show/hide, not credentials/Test Connection depth) | `audit/pro/journeys/admin/storage-drivers-cloud.md` |
| MV-STO-002 | Configure BunnyCDN as active driver | Pro | narrative | `audit/pro/journeys/admin/03-pro-storage-driver-registers.md` (partial, same scope as STO-001) | `audit/pro/journeys/admin/storage-drivers-cloud.md` |
| MV-STO-003 | Configure Cloudflare R2 as active driver | Pro | narrative | `audit/pro/journeys/admin/03-pro-storage-driver-registers.md` (partial — dropdown lists it + show/hide only, filter itself untested for r2) | `audit/pro/journeys/admin/storage-drivers-cloud.md` |
| MV-STO-004 | Configure DigitalOcean Spaces as active driver | Pro | narrative | `audit/pro/journeys/admin/03-pro-storage-driver-registers.md` (partial, same scope as STO-003) | `audit/pro/journeys/admin/storage-drivers-cloud.md` |
| MV-STO-005 | Switch active storage driver | Pro | narrative | `admin/08-storage-switch-migrate-mobile.md` (steps 1-4) | `audit/journeys/admin/08-storage-switch-migrate-mobile.md` |
| MV-STO-006 | Migrate all media to active cloud driver | Pro | narrative | `admin/08-storage-switch-migrate-mobile.md` (step 5) | `audit/journeys/admin/08-storage-switch-migrate-mobile.md` |
| MV-STO-007 | Free up server space (delete local copies) | Pro | narrative | none (admin/08 doesn't test cleanup) | `audit/journeys/admin/08-storage-switch-migrate-mobile.md` |
| MV-STO-008 | Secret credential fields preserve value | Pro | narrative | none | `audit/pro/journeys/admin/storage-secrets-and-test.md` |
| MV-STO-009 | Private media never uploaded to cloud/CDN | Pro | narrative | `admin/08-storage-switch-migrate-mobile.md` (step 4) | `audit/journeys/admin/08-storage-switch-migrate-mobile.md` |
| MV-STO-010 | Test Connection button per driver | Pro | narrative | none | `audit/pro/journeys/admin/storage-secrets-and-test.md` |
| MV-STO-011 | Storage Management panel on mobile | Pro | narrative | `admin/08-storage-switch-migrate-mobile.md` (step 6) | `audit/journeys/admin/08-storage-switch-migrate-mobile.md` |

## Area: WMK (Watermarking) — 9 entries, narrative

| ID | Title | Edition | Density | Existing Coverage | Proposed File |
|---|---|---|---|---|---|
| MV-WMK-001 | Enable watermarking (images only, master toggle) | Pro | narrative | `customer/23-watermark-stamped-at-upload.md` (steps 1-2) | `audit/journeys/customer/23-watermark-stamped-at-upload.md` |
| MV-WMK-002 | Text watermark with tokens | Pro | narrative | none | `audit/pro/journeys/admin/watermark-settings-details.md` |
| MV-WMK-003 | Image (logo) watermark | Pro | narrative | none | `audit/pro/journeys/admin/watermark-settings-details.md` |
| MV-WMK-004 | Logo + text ("both") mode | Pro | narrative | none | `audit/pro/journeys/admin/watermark-settings-details.md` |
| MV-WMK-005 | Position and opacity settings | Pro | narrative | none | `audit/pro/journeys/admin/watermark-settings-details.md` |
| MV-WMK-006 | Per-role scope ("Apply to") | Pro | narrative | `customer/23-watermark-stamped-at-upload.md` (step 4) | `audit/journeys/customer/23-watermark-stamped-at-upload.md` |
| MV-WMK-007 | Baked into stored original + derivatives, no re-stamp | Pro | narrative | `customer/23-watermark-stamped-at-upload.md` (step 2, no dead preview) | `audit/journeys/customer/23-watermark-stamped-at-upload.md` |
| MV-WMK-008 | Free-only (no Pro) gets no watermark | Pro | narrative | none | `audit/pro/journeys/admin/watermark-settings-details.md` |
| MV-WMK-009 | GD-unavailable failure + replace-upload re-stamping | Pro | narrative | `customer/24-watermark-ingest-paths.md` (steps 1-2, 4) | `audit/journeys/customer/24-watermark-ingest-paths.md` |

## Area: AI (AI providers, moderation, budget) — 12 entries, narrative

| ID | Title | Edition | Density | Existing Coverage | Proposed File |
|---|---|---|---|---|---|
| MV-AI-001 | Select active provider and configure Google Vision | Pro | narrative | `admin/09-ai-features-owner-control.md` (step 1, provider mismatch guard — OpenAI/Google only, not full config) | `audit/pro/journeys/admin/ai-providers.md` |
| MV-AI-002 | Google Vision moderation and tagging behavior | Pro | narrative | none | `audit/pro/journeys/admin/ai-providers.md` |
| MV-AI-003 | Configure and use AWS Rekognition | Pro | narrative | none | `audit/pro/journeys/admin/ai-providers.md` |
| MV-AI-004 | Configure and use Anthropic (Claude) | Pro | narrative | none | `audit/pro/journeys/admin/ai-providers.md` |
| MV-AI-005 | Auto-analyze/describe/tag/apply toggles | Pro | narrative | `admin/09-ai-features-owner-control.md` (step 2) | `audit/pro/journeys/admin/ai-toggles-and-moderation.md` |
| MV-AI-006 | Moderation categories and custom terms | Pro | narrative | none | `audit/pro/journeys/admin/ai-toggles-and-moderation.md` |
| MV-AI-007 | Circuit breaker: trip, cooldown, auto-recovery | Pro | narrative | none | `audit/pro/journeys/admin/ai-reliability.md` |
| MV-AI-008 | Missing/invalid credentials per provider | Pro | narrative | `admin/09-ai-features-owner-control.md` (step 4, no-key path) | `audit/pro/journeys/admin/ai-reliability.md` |
| MV-AI-009 | Only one provider active despite multiple configured | Pro | narrative | `admin/09-ai-features-owner-control.md` (step 1) | `audit/pro/journeys/admin/ai-providers.md` |
| MV-AI-010 | Monthly AI budget cap | Pro | narrative | `admin/09-ai-features-owner-control.md` (step 5) | `audit/pro/journeys/admin/ai-toggles-and-moderation.md` |
| MV-AI-011 | Manual AI re-run and reject in admin Media list | Pro | narrative | none | `audit/pro/journeys/admin/ai-reliability.md` |
| MV-AI-012 | Auto-moderation flagging feeds moderation queue | Pro | narrative | `admin/04-moderation-approve-flow.md` (step 6, AI Flagged tab visibility) | `audit/pro/journeys/admin/ai-toggles-and-moderation.md` |

## Area: IMP (Import & connectors) — 12 entries, narrative

| ID | Title | Edition | Density | Existing Coverage | Proposed File |
|---|---|---|---|---|---|
| MV-IMP-001 | WP-CLI import from rtMedia | Pro | narrative | none | `audit/pro/journeys/cli/legacy-platform-import.md` |
| MV-IMP-002 | WP-CLI import from MediaPress | Pro | narrative | none | `audit/pro/journeys/cli/legacy-platform-import.md` |
| MV-IMP-003 | WP-CLI import from BuddyBoss Platform | Pro | narrative | none | `audit/pro/journeys/cli/legacy-platform-import.md` |
| MV-IMP-004 | Admin migration card: detection and visibility | Pro | narrative | none | `audit/pro/journeys/admin/migration-admin.md` |
| MV-IMP-005 | Admin migration: run a batch | Pro | narrative | none | `audit/pro/journeys/admin/migration-admin.md` |
| MV-IMP-006 | Imported media follows normal album-assignment rule | Pro | narrative | none | `audit/pro/journeys/admin/migration-admin.md` |
| MV-IMP-007 | Flickr OAuth connect flow | Pro | narrative | none | `audit/pro/journeys/customer/flickr-connector.md` |
| MV-IMP-008 | Flickr import (browse and import) | Pro | narrative | none | `audit/pro/journeys/customer/flickr-connector.md` |
| MV-IMP-009 | Flickr export / auto-export | Pro | narrative | none | `audit/pro/journeys/customer/flickr-connector.md` |
| MV-IMP-010 | Flickr delta sync (incremental) | Pro | narrative | none | `audit/pro/journeys/customer/flickr-connector.md` |
| MV-IMP-011 | Connectors master toggle off | Pro | narrative | none | `audit/pro/journeys/customer/flickr-connector.md` |
| MV-IMP-012 | External-source badge and dashboard panel | Pro | narrative | none | `audit/pro/journeys/customer/flickr-connector.md` |

## Area: PSH (Push notifications) — 7 entries, narrative

| ID | Title | Edition | Density | Existing Coverage | Proposed File |
|---|---|---|---|---|---|
| MV-PSH-001 | Register a device for push | Pro | narrative | none | `audit/pro/journeys/api/push-notifications.md` |
| MV-PSH-002 | Cross-registry: token already owned by another member | Pro | narrative | none | `audit/pro/journeys/api/push-notifications.md` |
| MV-PSH-003 | Push delivery via Expo on a real notification | Pro | narrative | none | `audit/pro/journeys/api/push-notifications.md` |
| MV-PSH-004 | Invalid/expired token handling (Expo prune) | Pro | narrative | none | `audit/pro/journeys/api/push-notifications.md` |
| MV-PSH-005 | Per-type mute preference suppresses delivery | Pro | narrative | none | `audit/pro/journeys/api/push-notifications.md` |
| MV-PSH-006 | Unregister a device | Pro | narrative | none | `audit/pro/journeys/api/push-notifications.md` |
| MV-PSH-007 | App branding settings via /app/config | Pro | narrative | none | `audit/pro/journeys/api/push-notifications.md` |

## Area: LIC (License) — 6 entries, narrative

| ID | Title | Edition | Density | Existing Coverage | Proposed File |
|---|---|---|---|---|---|
| MV-LIC-001 | Activate a license key | Pro | narrative | none | `audit/pro/journeys/admin/license-management.md` |
| MV-LIC-002 | Deactivate a license key | Pro | narrative | none | `audit/pro/journeys/admin/license-management.md` |
| MV-LIC-003 | Expired/invalid license status and errors | Pro | narrative | none | `audit/pro/journeys/admin/license-management.md` |
| MV-LIC-004 | Update channel gating (licence controls updates only) | Pro | narrative | none | `audit/pro/journeys/admin/license-management.md` |
| MV-LIC-005 | No feature functionality blocked outside Documents writes | Pro | narrative | `wpmediaverse-pro/admin/01-feature-toggles-gate-routes.md` (adjacent — toggle vs. license are different axes) | `audit/pro/journeys/admin/license-management.md` |
| MV-LIC-006 | License settings screen on mobile (390px) | Pro | narrative | none | `audit/pro/journeys/admin/license-management.md` |

## Area: PTL (Template overrides, Pro) — 13 entries, compact

| ID | Title | Edition | Density | Existing Coverage | Proposed File |
|---|---|---|---|---|---|
| MV-PTL-001 | Compete hub page + body template override | Pro | compact | none | `audit/pro/journeys/admin/template-overrides-pro.md` |
| MV-PTL-002 | Battles page + body template override | Pro | compact | none | `audit/pro/journeys/admin/template-overrides-pro.md` |
| MV-PTL-003 | Challenges page + body template override | Pro | compact | none | `audit/pro/journeys/admin/template-overrides-pro.md` |
| MV-PTL-004 | Tournaments page + body template override | Pro | compact | none | `audit/pro/journeys/admin/template-overrides-pro.md` |
| MV-PTL-005 | Boost modal partial override | Pro | compact | none | `audit/pro/journeys/admin/template-overrides-pro.md` |
| MV-PTL-006 | Streak widget partial override | Pro | compact | none | `audit/pro/journeys/admin/template-overrides-pro.md` |
| MV-PTL-007 | Connected accounts panel override | Pro | compact | none | `audit/pro/journeys/admin/template-overrides-pro.md` |
| MV-PTL-008 | External source badge partial override | Pro | compact | none | `audit/pro/journeys/admin/template-overrides-pro.md` |
| MV-PTL-009 | Connector import modal override (frontend exception) | Pro | compact | none | `audit/pro/journeys/admin/template-overrides-pro.md` |
| MV-PTL-010 | Feed card partial override (shared, Instagram-only) | Pro | compact | none | `audit/pro/journeys/admin/template-overrides-pro.md` |
| MV-PTL-011 | Stories bar partial override | Pro | compact | none | `audit/pro/journeys/admin/template-overrides-pro.md` |
| MV-PTL-012 | Per-layout feed-body template override | Pro | compact | none | `audit/pro/journeys/admin/template-overrides-pro.md` |
| MV-PTL-013 | `@version` bump enforcement on Pro templates | Pro | compact | none | `audit/pro/journeys/admin/template-overrides-pro.md` |

## Area: PSET (Pro settings-screen mechanics) — 2 entries, compact

| ID | Title | Edition | Density | Existing Coverage | Proposed File |
|---|---|---|---|---|---|
| MV-PSET-001 | Settings sub-tab navigation model (hash-anchor) | Pro | compact | none | `audit/pro/journeys/admin/settings-tab-navigation.md` |
| MV-PSET-002 | Sidebar section merge model | Pro | compact | none | `audit/pro/journeys/admin/settings-tab-navigation.md` |
