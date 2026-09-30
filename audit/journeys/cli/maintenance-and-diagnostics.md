---
journey: cli-maintenance-and-diagnostics
plugin: wpmediaverse
priority: normal
roles: [shell]
covers: [MV-CLI-001, MV-CLI-002, MV-CLI-003, MV-CLI-004, MV-CLI-005, MV-CLI-006, MV-CLI-009, MV-CLI-018, MV-CLI-019, MV-CLI-020, wp-cli-maintenance]
prerequisites:
  - "Shell/SSH access with WP-CLI, `wp mvs` registered (`wpmediaverse.php` calls `WP_CLI::add_command('mvs', ...)` and `WP_CLI::add_command('mvs cert', ...)`)"
  - "A library with some media/social data (stats/moderation/reindex read more usefully with rows, but every command must also run clean on an empty install)"
estimated_runtime_minutes: 12
---

# wp mvs maintenance & diagnostic commands run cleanly and report accurately

**Why this journey exists**: these ten commands are the plugin's own runbook for
DB health, cache, moderation backlog, storage integrity and its own release gate
(`wp mvs cert`). None should error on an empty site, none should silently lie
about what it did. Grounded against `includes/CLI/Commands.php` and
`includes/Cert/CertCommand.php`.

## Setup

- Shell: `wp mvs --help` (confirms `stats`, `migrate`, `prune-views`,
  `cleanup-expired`, `reindex`, `cache-flush`, `moderation-stats`,
  `repair-storage`, `diagnose-cpt-ids`, `cert` are all listed subcommands).
- DB: direct read access (`wp db query` or the mysql MCP) to cross-check counts.

## Steps

### 1. MV-CLI-001 — `wp mvs stats`
- **Action**: `wp mvs stats`.
- **Assert**: exits 0; prints a table with Metric/Value rows for Published Media,
  Albums, Total Views, Total Reactions, Total Favorites, DB Version, Plugin
  Version — all sourced from `admin_aggregates` (`AdminAggregatesService`), never
  a raw ad-hoc `COUNT(*)`. Runs clean (all zeros, no error) on an empty install.

### 2. MV-CLI-002 — `wp mvs migrate`
- **Action**: `wp mvs migrate --check` on an up-to-date DB, then `wp mvs migrate`
  again for real.
- **Assert**: `--check` on a current DB prints "Database is up to date (version
  {current})." with no writes. A real run on a current DB prints "Already at
  version {target}. Nothing to do." (idempotent, exit 0). On a stale
  `mvs_db_version`, a real run logs "Running migrations from v{X} to
  v{Y}..." then "Database migrated to version {Y}." — FROM and TO are both
  named, not just "done."

### 3. MV-CLI-003 — `wp mvs prune-views`
- **Action**: `wp mvs prune-views --dry-run`, then `wp mvs prune-views` for real,
  cross-checking the dry-run count against `SELECT COUNT(*) FROM
  wp_mvs_media_views WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL
  {days} DAY)`.
- **Assert**: dry-run reports "Would delete {count} view records older than
  {days} days." with zero rows actually removed (verify row count unchanged);
  the real run's "Pruned {deleted} ..." count matches the dry-run's count
  exactly. `--days=N` overrides the default 90. Zero eligible rows prints "0",
  not an error.

### 4. MV-CLI-004 — `wp mvs cleanup-expired`
- **Action**: `wp mvs cleanup-expired` (optionally `--batch-size=500`) on a site
  with no expired `mvs_access_grants` rows.
- **Assert**: "Cleaned up 0 expired access grants." on a Free-only site with no
  Pro document-sharing links — this is expected, not broken (the table is
  written by Pro's document-sharing feature only). Code sets `revoked_at` via a
  single batched `UPDATE ... LIMIT %d`, never a per-row loop.

### 5. MV-CLI-005 — `wp mvs reindex`
- **Action**: `wp mvs reindex` (optionally `--batch-size=50`) against a library
  with at least one media row missing its `mvs_media_stats` row (delete one
  manually to force it).
- **Assert**: periodic "Processed {total} media items..." progress lines appear
  during the run (not silent); final line "Reindex complete. {total} media
  items checked, {stats_added} stats rows created." matches the number of rows
  actually inserted into `mvs_media_stats`. Re-running immediately reports
  `stats_added: 0` (idempotent, no side effects on a consistent library).

### 6. MV-CLI-006 — `wp mvs cache-flush`
- **Action**: `wp mvs cache-flush`.
- **Assert**: "All MediaVerse caches flushed." exit 0. Calls
  `container->get('cache')->flush_all()` — scoped to MediaVerse's own cache
  group, not a site-wide `wp cache flush`.

### 7. MV-CLI-009 — `wp mvs moderation-stats`
- **Action**: `wp mvs moderation-stats` on an empty index, then with real media
  in mixed moderation states.
- **Assert**: empty index prints "No media found in the index." (not an error);
  with data, prints a Status/Count table from
  `MediaRepository::get_moderation_counts()` — the SAME method the admin
  moderation screen reads, so the CLI and wp-admin backlog counts can never
  drift (code comment explicitly calls this out as a prior duplication fix).

### 8. MV-CLI-018 — `wp mvs repair-storage`
- **Action**: `wp mvs repair-storage --dry-run`, then for real, on a site with
  no broken storage paths.
- **Assert**: "Nothing to repair." on a healthy site (safe to run when nothing
  is broken). The service (`storage_repair`) is copy-only — never issues a
  delete; re-running twice back-to-back produces the same "Nothing to repair."
  outcome both times.

### 9. MV-CLI-019 — `wp mvs diagnose-cpt-ids`
- **Action**: `wp mvs diagnose-cpt-ids` (and `--format=json`) on a healthy site.
- **Assert**: read-only — no DB write of any kind (confirm via `CptIdCollisionService::analyze()` being a pure read); prints Totals + Forecast tables and six report sections (Collisions, Purge risk, Slug overwrites, Attribute-only rows, mvs_media_meta rows, Taxonomy spread); ends with "No collisions on this site." when `totals['collisions'] === 0`. A real collision (test fixture) flips this to a `WP_CLI::warning()` naming the count and refuses to imply it's safe to delete the colliding CPTs.

### 10. MV-CLI-020 — `wp mvs cert`
- **Action**: `wp mvs cert` against a live WP install (or with `--format=json`).
- **Assert**: exits non-zero (`WP_CLI::error`) on any failed check, 0 on all-pass
  (`WP_CLI::success`); output lists each check's mark/name/entity/detail line,
  covering boot-smoke of every REST route plus dead-toggle-oracle and toggle
  coverage per `includes/Cert/CertRunner.php`. This is the same gate stage 3.2
  of local-CI runs when `MVS_WP_PATH` is set.

## Pass criteria

1. All ten commands exit 0 (or a documented non-zero for `cert` on a real
   failure) and never fatal on an empty install.
2. Dry-run counts match real-run counts exactly for `prune-views`.
3. `moderation-stats` and `stats` read from the same shared services the admin
   UI reads (no duplicated/independent queries that could drift).
4. `repair-storage` and `diagnose-cpt-ids` never write anything destructive —
   `repair-storage` only copies missing files into place, `diagnose-cpt-ids`
   never writes at all.
5. `cert` boot-smokes every REST route and reports toggle coverage; a red
   result is a real release blocker, not noise.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| `stats`/`moderation-stats` numbers disagree with wp-admin | a second ad-hoc query bypassing `AdminAggregatesService`/`get_moderation_counts()` | `includes/CLI/Commands.php`, `includes/Services/AdminAggregatesService.php`, `includes/Repository/MediaRepository.php` |
| `prune-views --dry-run` count != real-run count | the dry-run `SELECT COUNT(*)` predicate drifted from `StatsService::prune_views()`'s delete predicate | `includes/CLI/Commands.php::prune_views`, `includes/Services/StatsService.php` |
| `reindex` never terminates or loops forever | batch loop condition (`$row_count === $batch_size`) broken after a repository change | `includes/CLI/Commands.php::reindex` |
| `repair-storage` deletes anything | a regression turning the copy-only repair into a move/delete | `includes/Services/StorageRepairService.php` |
| `cert` always green even with a broken route | `CertRunner` boot-smoke swallowing an exception instead of failing the check | `includes/Cert/CertRunner.php`, `includes/Cert/CertCommand.php` |
