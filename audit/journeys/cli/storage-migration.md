---
journey: cli-storage-migration
plugin: wpmediaverse
priority: high
roles: [shell]
covers: [MV-CLI-012, MV-CLI-014, MV-CLI-015, wp-cli-storage-migration]
prerequisites:
  - "Shell/SSH access with WP-CLI, `wp mvs` registered"
  - "WPMediaVerse Pro active with a cloud storage driver (s3/bunnycdn) configured, to exercise the real cross-driver paths — the Free-only refusal paths can be run without Pro"
  - "A database backup taken before any non-dry-run pass (all three commands warn this themselves)"
estimated_runtime_minutes: 10
---

# Cloud-thumbnail backfill, local cleanup and private-media relocalization never risk data loss

**Why this journey exists**: these three commands move bytes between local
disk and a Pro cloud driver, or repair rows a privacy change left stranded on
cloud. Each one has a specific, code-documented safety net (never touch
non-public media into public cloud; never delete a local file whose cloud
copy fails to verify; skip rows already clean). This journey proves the
refusal paths AND the safety nets actually trigger, not just that the
happy path runs. Grounded against `includes/CLI/Commands.php`.

## Setup

- Free-only site (no Pro / no cloud driver configured) for the refusal-path
  steps.
- Pro site with `mvs_storage_driver` set to a cloud driver (s3 or bunnycdn),
  with a mix of public and non-public media, for the real-path steps.
- `wp option get mvs_storage_driver` to confirm current driver before each
  step.

## Steps

### 1. MV-CLI-012 — `wp mvs cloud-thumbs-backfill`
- **Action (refusal, local driver)**: with `mvs_storage_driver=local`, run
  `wp mvs cloud-thumbs-backfill`.
- **Assert**: `WP_CLI::error` "Active driver is 'local' — nothing to push to
  cloud. Set mvs_storage_driver to s3 or bunnycdn first." — no query runs.
- **Action (refusal, Free without Pro driver class)**: force
  `mvs_storage_driver=s3` on Free-only (no Pro), run the command.
- **Assert**: error "Storage driver 's3' is not available. Pro plugin
  required for s3/bunnycdn." (the `apply_filters('mvs_storage_driver', ...)`
  returns something that isn't a `StorageDriverInterface`).
- **Action (real, Pro + cloud driver)**: `wp mvs cloud-thumbs-backfill
  --dry-run`, then for real.
- **Assert**: query is scoped to `privacy_in: ['public']` AND
  `mime_like_in: ['image/%']` only (non-public media never enters this
  command's working set — code comment: the `/serve` proxy can't yet read
  cloud for non-public rows). Real run shows the off-peak network+CPU warning
  BEFORE the `WP_CLI::confirm()` prompt. Final line: "Backfilled thumbnails
  for {N} image(s). Skipped {S} (already on cloud). Failed {F}." — a single
  failed original download logs a warning and continues the batch rather than
  aborting.

### 2. MV-CLI-014 — `wp mvs cleanup-local`
- **Action (refusal, local driver)**: with `mvs_storage_driver=local`, run
  `wp mvs cleanup-local`.
- **Assert**: `WP_CLI::error` "Active driver is 'local' — cleaning local files
  would break the site. Migrate to a cloud driver first." — hard refusal,
  never proceeds.
- **Action (real, cloud driver)**: `wp mvs cleanup-local --dry-run`, then for
  real, on media already migrated to cloud.
- **Assert**: query is scoped to `privacy_in: ['public']` only — same
  non-public exclusion as MV-CLI-012's backfill, in the opposite direction.
  Real run shows the IRREVERSIBLE warning (naming the exact `migrate-storage
  --to=local` command needed to reverse) before `WP_CLI::confirm()`. The
  safety net: force one row's cloud copy to be unreachable (rename/delete it
  on the cloud side) and confirm that row logs "Cloud verify FAILED for media
  #{id} ({path}) — keeping local file as safety" and is NOT deleted locally —
  this is the single most important assertion in this file per the catalog's
  own UX-expectation note. Final summary distinguishes "already-clean"
  (no local file to begin with) from "not-on-cloud" (verify failed, kept).

### 3. MV-CLI-015 — `wp mvs relocalize-private`
- **Action**: on a non-public media row whose `file_url`/`thumb_*` meta still
  point at a cloud bucket (simulate by flipping a public-on-cloud item to
  `private` without running the automatic 1.4.0+ repatriation listener, or
  using a pre-existing legacy row), run `wp mvs relocalize-private --dry-run`,
  then for real.
- **Assert**: with nothing to heal: "No non-public media rows match. Nothing
  to heal." With real candidates: dry-run lists each as "media #{id}
  (privacy={p}) would be relocalized"; real run shows the DB-backup warning +
  confirm, then rewrites `mvs_media_index.file_url` and the `thumb_*` meta
  keys back to local paths — confirm via `wp mvs relocalize-private
  --media-id={id} --dry-run` afterward reporting it as already-clean (not a
  candidate anymore). Final summary line distinguishes healed vs.
  already-clean counts explicitly ("Healed {N} row(s). Skipped {M}
  already-clean row(s).").

## Pass criteria

1. All three commands refuse cleanly (no partial write) when the active
   driver makes the operation unsafe (`local` for cloud-thumbs-backfill /
   cleanup-local's inverse guards).
2. `cleanup-local`'s cloud-verify-before-delete safety net actually triggers
   on a broken cloud copy and the local file survives.
3. Non-public media is never touched by `cloud-thumbs-backfill` or
   `cleanup-local` — both are hard-scoped to `privacy_in: ['public']`.
4. `relocalize-private` correctly distinguishes healed vs. already-clean rows
   and is safe to re-run.
5. Every non-dry-run, non-trivial pass shows its warning text BEFORE the
   confirm prompt, never after.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| `cleanup-local` deletes a local file whose cloud copy is broken | `$driver->exists()` check removed or short-circuited | `includes/CLI/Commands.php::cleanup_local` |
| `cloud-thumbs-backfill` touches a non-public row | `privacy_in` filter dropped from `storage_walk_args()` | `includes/CLI/Commands.php::cloud_thumbs_backfill`, `storage_walk_args` |
| `relocalize-private` re-lists an already-healed row as a candidate | the "already local" detection (`baseurl` prefix check) broken | `includes/CLI/Commands.php::relocalize_private` |
| Confirm prompt appears without the safety warning first | warning/confirm order swapped in a refactor | `includes/CLI/Commands.php` (all three methods) |
