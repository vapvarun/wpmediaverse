---
journey: cli-buddypress-integration
plugin: wpmediaverse
priority: normal
roles: [shell]
covers: [MV-CLI-007, MV-CLI-010, wp-cli-buddypress]
prerequisites:
  - "Shell/SSH access with WP-CLI, `wp mvs` registered"
  - "BuddyPress active with the Activity component enabled"
  - "Legacy activity entries imported from rtMedia/MediaPress/BuddyBoss with empty content, and/or MVS-linked activity whose hide_sitewide/privacy meta has drifted from the media's own privacy level"
estimated_runtime_minutes: 8
---

# The two BuddyPress-repair CLI commands share the same destructive-write safety pattern and require BuddyPress active

**Why this journey exists**: both commands write directly to `bp_activity`, a
table this plugin doesn't own. The site owner's expectation is a consistent,
non-skippable safety pattern (backup warning before confirm) across both, and
a clean, specific refusal when BuddyPress isn't active — not a fatal. Grounded
against `includes/CLI/Commands.php`.

## Setup

- A BuddyPress site with the Activity component active.
- A second test pass with BuddyPress deactivated (or the Activity component
  off) to exercise the refusal path.

## Steps

### 1. MV-CLI-007 — `wp mvs backfill-activity-thumbnails`
- **Action**: with BuddyPress/Activity inactive, run `wp mvs
  backfill-activity-thumbnails`.
- **Assert**: `WP_CLI::error` "BuddyPress activity component is not active."
  and exit — not a PHP fatal (`function_exists('bp_activity_get')` /
  `bp_is_active('activity')` guard runs first).
- **Action**: with BuddyPress active and no empty-content `mvs_media_upload`
  activities, run the command.
- **Assert**: "No activities with empty content found. Nothing to backfill."
- **Action**: with real candidates, run WITHOUT `--yes`.
- **Assert**: a `WP_CLI::warning()` "This will modify the bp_activity table.
  Make sure you have a database backup." appears BEFORE the
  `WP_CLI::confirm('Proceed with backfill?')` prompt — every time,
  non-skippable except by passing `--yes`.
- **Action**: `wp mvs backfill-activity-thumbnails --source=bogus`.
- **Assert**: WP-CLI itself rejects it ("Invalid value specified for
  'source'") because the command declares an `options` enum
  (`all|rtmedia|mediapress|buddyboss`) — this never reaches the command body.
- **Action**: run for real with `--source=mediapress` against a mix of
  rtmedia/mediapress/buddyboss-tagged activities.
- **Assert**: only mediapress-sourced rows (matched via the `mpp_id` meta
  key) are updated; the rest are counted as skipped, not touched.

### 2. MV-CLI-010 — `wp mvs sync-activity-privacy`
- **Action**: with BuddyPress/Activity inactive, run `wp mvs
  sync-activity-privacy`.
- **Assert**: the SAME "BuddyPress activity component is not active." error
  as MV-CLI-007 — identical refusal message/pattern across both commands.
- **Action**: with BuddyPress active and nothing drifted, run the command.
- **Assert**: "No MVS activities found. Nothing to sync."
- **Action**: with real drift (flip a media item's privacy without letting
  the normal sync listener run, or use a legacy pre-sync row) and no
  `--yes`.
- **Assert**: a warning naming BOTH `hide_sitewide` and activity privacy meta
  as the fields being updated, backup recommendation, THEN the confirm
  prompt — same ordering discipline as MV-CLI-007.
- **Action**: run for real against an activity linked to media that has since
  been hard-deleted.
- **Assert**: that activity is counted as skipped, not an error that aborts
  the whole batch.

## Pass criteria

1. Both commands give the identical "BuddyPress activity component is not
   active." refusal (not a fatal) when BuddyPress/Activity is off.
2. Both commands show their backup/impact warning BEFORE the confirm prompt,
   every non-`--yes` run, with no path that skips the warning.
3. `--source=bogus` on MV-CLI-007 is rejected by WP-CLI's own arg validation
   before the command body runs.
4. MV-CLI-010 handles a since-deleted linked media item as a skip, not a
   batch-aborting error.
5. Neither command runs any part of its DB write when BuddyPress is inactive
   — the guard is the very first thing each method does.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| PHP fatal instead of clean error when BuddyPress is off | `bp_is_active()`/`function_exists()` guard removed or reordered after other code | `includes/CLI/Commands.php::backfill_activity_thumbnails`, `::sync_activity_privacy` |
| Confirm prompt with no preceding warning | warning/confirm call order swapped | `includes/CLI/Commands.php` (both methods) |
| `--source=mediapress` also updates rtmedia/buddyboss rows | the per-source meta-key filter (`rtmedia_id`/`mpp_id`/`bb_media_id`) dropped | `includes/CLI/Commands.php::backfill_activity_thumbnails` |
| A deleted-media activity aborts the whole `sync-activity-privacy` batch | missing existence check before recomputing hide_sitewide | `includes/CLI/Commands.php::sync_activity_privacy`, `includes/Integrations/BuddyPress/ActivitySyncIntegration.php` |
