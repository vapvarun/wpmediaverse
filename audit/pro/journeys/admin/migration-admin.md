---
journey: migration-admin
plugin: wpmediaverse-pro
priority: normal
roles: [administrator]
covers: [MV-IMP-004, MV-IMP-005, MV-IMP-006, migration-admin, album-assignment-rule]
prerequisites:
  - "A detected source plugin (rtMedia/MediaPress/BuddyBoss tables) OR an already-started migration state"
estimated_runtime_minutes: 8
---

# The Import card only appears when there's something to import (or a run in flight); Start/Pause/Resume/Reset survive a full page reload; imported media follows the same one-album-per-photo rule as a normal upload

**Why this journey exists**: 2.6.0's consolidation (Basecamp 10264373450) made Free's `AlbumService::add_items()` the SINGLE writer of album membership for both uploads and imports — before that, all four importers inserted membership rows directly, bypassing the one-album-per-photo rule, the album-privacy-governs-photos rule, and firing an activity post per imported item. This journey proves the admin migration-card path (as distinct from the CLI path in `legacy-platform-import.md`) inherited that fix.

## Setup

- Site: `$SITE_URL`; admin `?autologin=admin`.

## Steps

### 1. Import sidebar link absent with nothing detected
- **Action**: with no source plugins installed/seeded, check the WPMediaVerse sidebar; then navigate directly to `admin.php?page=mvs-migration` by URL.
- **Expect**: "Import" is absent from the sidebar, but the URL still loads the page with a clean "no source detected" state per card, never a blank/broken shell.

### 2. Detection is cached, up to ~1 hour
- **Action**: seed rtMedia's tables (or install it); reload an admin page immediately.
- **Expect**: the sidebar link may not appear immediately — detection is cached in `mvs_pro_migration_has_source` (~1 hour TTL). Not filing this as a bug within the TTL window is correct; only flag it if it persists past the TTL or a hard refresh/option-clear.

### 3. Card renders once detected, with the right per-platform message
- **Action**: after the transient clears (or is manually cleared), reload the sidebar and Import page.
- **Expect**: card shows label, description, icon, available/not-found message, total count, already-imported count, and Start/Resume/Pause/Reset controls. Each platform's "not found" message is distinct, not shared boilerplate.

### 4. An already-started run keeps the sidebar link visible even if the source is later deactivated
- **Action**: start a run, then deactivate the source plugin.
- **Expect**: sidebar link stays visible (`is_available() || !empty(get_option(state_key))`).

### 5. Start, observe batches, Pause
- **Action**: click Start on a detected platform's card; observe progress (25 rows/AJAX call, `MigrationPage::BATCH_SIZE`); click Pause mid-run.
- **Expect**: state persists (`mvs_migration_state_{slug}`): status/offset/total/imported/skipped/errors/started_at/updated_at, plus platform extras (`bb_source`, `dry_run`, `skip_albums`).

### 6. Resume continues from the persisted offset, not from 0
- **Action**: click Resume.
- **Expect**: continues from the exact `next_offset` of the last batch — never re-processes or skips rows.

### 7. Reset requires the shared destructive confirm and truly clears state
- **Action**: click Reset.
- **Expect**: the shared `window.mvsConfirm()` dialog with "Reset progress? This cannot be undone."; on confirm, `clear_state()` wipes the option entirely (status idle, offset 0, totals 0).

### 8. Pausing survives a full page reload / browser close
- **Action**: pause mid-run, close the browser entirely, reopen the Import page later.
- **Expect**: state survives — it's a DB option, not JS-only state.

### 9. Two platforms' runs don't cross-contend
- **Action**: start one platform's migration, pause it, start a second platform's migration.
- **Expect**: each platform's state is a separate option key — no contention between them.

### 10. `skip_albums`/`dry_run` checkboxes are shared across every platform's card
- **Action**: confirm `mvs-opt-dryrun` and `mvs-opt-skipalbums` checkboxes render on every platform's card, with BuddyBoss additionally showing its media/document/video source dropdown.
- **Expect**: present on all cards without needing separate wiring per platform.

### 11. Imported media follows the normal album-assignment rule
- **Action**: import media belonging to a source album/gallery; check the resulting MVS album's membership and privacy; re-run the import (idempotency on the album, not just the media).
- **Expect**: album membership is written EXCLUSIVELY through `AlbumService::add_items()` — one album per photo, album privacy applies to the photo, the photo's own privacy is preserved for if it later leaves the album. Re-running does not duplicate the photo in the album (idempotent).
- **Also**: confirm NO activity-stream post is created per imported photo (`add_items()` is called with `array('announce' => false)` specifically for imports).
- **On fail**: a migration card path that bypasses `AlbumService::add_items()`.

### 12. Source-album privacy escalation only applies when nothing stricter was already derived
- **Action**: import a source item whose source-platform album context would normally escalate privacy (e.g. an rtMedia group-context album escalating public to group privacy).
- **Expect**: escalation happens only when the derived privacy from the item itself isn't already stricter — matching individual-media import behaviour.

## Pass criteria

1. The sidebar link and page state correctly reflect detection status, including the ~1-hour cache and the started-run exception.
2. Start/Pause/Resume/Reset behave exactly as documented and survive a full page reload.
3. Two platforms never cross-contend.
4. Imported album membership goes exclusively through `AlbumService::add_items()`, is idempotent on re-run, fires no per-item activity post, and respects the one-album-per-photo / album-privacy-governs-photos rules identically to a normal upload.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Resume restarts from 0 | `next_offset` not persisted/read correctly | `MigrationPage` AJAX batch handler |
| Reset skips the confirm dialog | `mvsConfirm()` not wired to the Reset button | migration admin JS |
| Imported photo generates an activity post | `add_items()` called without `announce: false` | migration card import path |
| Re-imported photo duplicates in the album | `add_items()` not idempotent, or bypassed on re-run | `AlbumService::add_items()` |
