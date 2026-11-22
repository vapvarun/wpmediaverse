# Background jobs (Free + Pro)

The portfolio rule set is the Wbcom background-jobs standard (`~/.claude/workflows/wp-background-jobs.md`
for maintainers). This page is MediaVerse's copy: the rules as we apply them, and the real job list. Update
the job list in the same commit as any change to a job.

## 1. One entry point for the family

Every MediaVerse job, Free and Pro, is queued, found and cancelled through Free's `WPMediaVerse\Core\Jobs`.
Pro requires Free 2.6.5 (`MVS_PRO_MIN_FREE`) and calls it directly, as it calls Free's other static helpers.

Methods:

```
has_as(): bool
when_ready( callable $callback ): void
async( string $hook, array $args = [], bool $cron_fallback = true, int $cron_delay = 0 ): bool
async_once( string $hook, array $args = [] ): bool
at( int $timestamp, string $hook, array $args = [] ): bool
is_queued( string $hook, ?array $args = null ): bool
is_pending( string $hook, ?array $args = null ): bool
next( string $hook ): int
ensure_recurring( string $hook, int $first, int|string $schedule, bool $cron = false ): void
is_scheduling_request(): bool
cancel( string $hook, array $args = [] ): void
cancel_pending( array $hooks ): int
pending_args( string $hook ): array
prune_history(): int
adopt_pending( array $hooks ): int
```

- `when_ready()` runs the callback now when Action Scheduler's store is ready or Action Scheduler is not loaded
  at all, else on `action_scheduler_init`.
- `async()` / `at()` use Action Scheduler (Free's bundled copy, `libs/action-scheduler`) once its store is
  ready, and WP-Cron before that (for example an upgrade routine running early in a request). `async()` also
  falls back to WP-Cron when Action Scheduler refuses the job, and answers `true` when a WP-Cron copy with the
  same arguments already waits. They return `false` when no scheduler holds the job. A caller whose work must
  not be lost does the work inline on `false`, and that is the only fallback code a caller carries. On WP-Cron
  the arguments are stored as a list, so the listener receives them positionally on both schedulers (PHP 8
  would read string keys as named parameters).
- `is_queued()` counts a pending **or running** copy: use it to avoid starting the same work twice, typically
  as the start guard of an argument-less chain whose continuations queue unguarded.
  `is_pending()` counts only a copy waiting to start: use it when the question is whether a later run is still
  coming (a reaper, a follow-up pass, a continuation queued from inside the running job). `async_once()` queues
  unless a copy with the same arguments is waiting; once Action Scheduler is ready only its pending rows count.
  A continuation queued from inside its own running job must never use `is_queued()` with its own arguments:
  the running row matches and the chain stops after one batch.
- `next()` answers the soonest pending run time, falling back to WP-Cron; 0 when none is pending, one runs now,
  or the soonest is an async copy.
- `cancel( $hook, [] )` cancels every copy whatever its arguments, while `is_queued( $hook, null )` is the "any
  arguments" lookup: an empty array means "any" only for cancel, because cancelling just the argument-less copy
  is never what a caller wants.
- `cancel_pending( $hooks )` marks the pending Action Scheduler rows of exactly these hooks cancelled with
  set-based UPDATEs of 10,000 rows. It is the deactivation path (section 3, rule 9).

Differences from Jetonomy's `\Jetonomy\Queue` (shared names behave the same):

- `ensure_recurring()` also takes a cron expression (`$cron = true`, used by the streak check); Jetonomy takes
  only an interval. Both clamp an interval to at least 60 seconds.
- No `recurring()` registry: each MediaVerse recurring job arms itself on
  `action_scheduler_ensure_recurring_actions`.
- No `delete_rows()`: uninstall deletes rows by hook list directly, since `Jobs` is not loaded there.
- `adopt_pending()` exists only here: Jetonomy never used another group. `cancel_pending()` exists only here
  too; it follows BuddyNext's set-based deactivation cancel, scoped by hook instead of group.
- `prune_history()` also stops after 10 seconds, besides the batch cap.
- Lookups and cancels match by hook and arguments in **any** group. Every MediaVerse hook starts with `mvs_`
  and belongs to us alone, and a row queued before 2.6.5 may still sit in an old group.
- `ensure_recurring()` keeps exactly one argument-less recurring row (a continuation of the same hook carries a
  cursor and does not count) and runs only on scheduling requests.
- Pro's `WPMediaVersePro\Core\Jobs` keeps only its two 2.6.4 methods (`ensure_recurring()` with its old
  five-argument signature, `is_scheduling_request()`), deprecated, handing off to Free's.
- Direct Action Scheduler calls outside `Jobs` are limited to these, each commented in place:
  `CompetitionsScheduler::arm()` reads `as_next_scheduled_action()` because it answers `true` while a tick
  runs; `TranscriptionService::retire_recurring_reaper()` checks and cancels only the argument-less row in the
  legacy `mvs-pro` group (one-time retirement); Pro `Lifecycle::clear_scheduled()`, which cancels row by row
  only when Free's `Jobs` is not loaded (Free inactive); both `uninstall.php` files, which run when the plugins
  may not be loaded; and `Repository\JobRepository`, the set-based UPDATE behind `Jobs::cancel_pending()`.
- Direct `wp_schedule_*()` calls are limited to the WP-Cron housekeeping set in section 4 (recurring events
  armed on boot, and the preset licence retry, which reads `DISABLE_WP_CRON` to decide whether to retry).
- `is_scheduling_request()` is the one rule for "may this request do upkeep": cron, WP-CLI, an admin page load,
  or Action Scheduler's daily `action_scheduler_ensure_recurring_actions` run (its async runner may execute it
  inside admin-ajax). `DirectDelivery::is_scheduling_request()` is a deprecated alias.

## 2. One group: `mvs`

Every MediaVerse job, Free and Pro, is queued in the Action Scheduler group `mvs`
(`Jobs::GROUP`). Before 2.6.5 jobs were spread over `wpmediaverse`, `wpmediaverse-pro`, `mvs-pro` and
`mvs-connectors` (`Jobs::LEGACY_GROUPS`).

Upgrade: Free migration v47 and Pro migration v26 call `Jobs::adopt_pending()` with their own hook lists
(`Core\Deactivator::JOB_HOOKS`, Pro `Core\Lifecycle::RECURRING_HOOKS` + `JOB_HOOKS`). Each pending row is
re-saved in `mvs` with the same hook, arguments, schedule and priority. Only rows no runner has claimed move;
the old row is cancelled before its copy is saved, then deleted, so it cannot run twice. One call moves at most
2,000 rows in at most 10 seconds and queues an `mvs_adopt_pending_jobs` job with the same hook list for the
rest. Each plugin moves
only its own hooks, so a newer Free never moves rows an older Pro still reads. Finished rows are not moved;
Action Scheduler's own cleaner ages them out. A row the move misses still runs and is still found, because
lookups are group-agnostic.

## 3. Rules we follow

1. **Lightest mechanism first.** Event-driven jobs are single actions armed by the event. No sub-hourly poll
   exists: 2.6.5 retired the five-minute competitions poll and the recurring captions reaper. Pro's upgrade
   (migration v24-v26, `Migrator::upgrade_jobs_265()`) cancels both and arms the single run that replaces each,
   on the upgrade request itself.
2. **Arguments are ids, under 191 characters.** Action Scheduler stores longer arguments in `extended_args`,
   which its lookups do not index. Exceptions, all deliberate:
   - `mvs_cleanup_media_files` carries file paths, because the media row is already gone when cleanup runs.
     Paths are chunked to `StorageCleanupService::ARGS_BUDGET` (7,000 bytes, under Action Scheduler's 8,000
     limit) and each chunk is never looked up, only run.
   - `mvs_deliver_webhook` carries the event body, because the body must be what was true when the event
     happened, not when the job runs.
   - `mvs_tag_forget_batch` carries the tag name (the term is already deleted) and up to `MediaTag::FORGET_BATCH` ids.
   - `mvs_rotate_media_files` carries `{ ids }`, up to 100 media ids per job.
   - `mvs_user_deletion_cascade` jobs queued before 2.6.2 carry `[ user_id, ids ]`; they still run. Jobs queued
     since carry only `[ user_id ]` and re-read the member's media each batch.
   - `mvs_pro_push_send` carries `[ user_id, message, data ]`, the push as it was when the notification fired.
   - `mvs_document_folder_subtree_rewrite` carries the old and new folder paths, which no longer exist on the
     folder rows once the move is saved.
3. **Chained jobs.** A sweep processes one bounded batch and queues its own continuation. Cursor chains carry
   the last id (report sweep, story cleanup, streak check, document extraction backfill, storage repair, captions
   reaper). Shrinking-set chains carry the same arguments every pass and re-read from the top, because each pass
   removes what it handled (user-deletion cascade, tag merge, space-drive trash, folder trash, restore and purge);
   each re-queues only when a pass changed something, so rows it cannot handle cannot keep it alive. Each run
   handles one bounded batch (the sizes are in section 4: 20 to 500 rows) and queues at most one continuation,
   so a sweep is one job at a time, never one job per row. A continuation is queued from inside the running job,
   so its guard counts pending copies only (`async_once()` or `is_pending()`), never `is_queued()`.
4. **No `unique` with arguments.** Action Scheduler's `$unique` flag ignores arguments, so a unique job with
   arguments would block every other job of the hook. Duplicate guards use `Jobs::async_once()` (a copy waiting
   is enough) or, for "is this work already under way", `Jobs::is_queued()`; see section 1.
   `$unique` is used only for argument-less recurring rows (`Jobs::ensure_recurring()`).
5. **Never skip.** A job is never dropped because the queue is busy or stalled; at most an identical pending
   copy is reused.
6. **Retries are capped at 3** (`StorageCleanupService::MAX_ATTEMPTS`, `WebhookService::MAX_ATTEMPTS`), with a
   5-minute step (`RETRY_STEP_SECONDS`).
7. **Recurring rows are armed only on scheduling requests**: cron, WP-CLI, an admin page load or Action
   Scheduler's daily housekeeping run, never a visitor's request (`Jobs::is_scheduling_request()`). The streak
   check and the competitions tick also re-arm on `action_scheduler_ensure_recurring_actions`, and on the first
   scheduling request after Pro is activated (`Lifecycle::rearm_after_activation()`, read from the
   `mvs_pro_rearm_jobs` transient the activation hook sets); autopilot re-arms on its next scheduling request.
8. **Own rows only, one retention rule for the family.** The daily `mvs_prune_logs` event calls
   `Jobs::prune_history()`: in group `mvs`, completed actions older than `mvs_finished_action_retention_days`
   (default 7), cancelled actions after the same days, and failed actions older than 90 days
   (`Jobs::FAILED_RETENTION_DAYS`, the same rule Jetonomy
   applies) are deleted with their log rows, 200 per batch, at most 50 batches of each and 10 seconds a run. Pending and
   running rows, and every other plugin's rows, are never touched. We never change Action Scheduler's
   site-wide retention filters.
9. **Deactivation cancels queued background jobs; uninstall deletes them.** Each plugin clears its own lists
   from WP-Cron and cancels the pending Action Scheduler rows of its own hooks (Free `Deactivator::CRON_HOOKS` +
   `JOB_HOOKS`; Pro `Lifecycle::RECURRING_HOOKS` + `JOB_HOOKS`), with set-based UPDATEs of 10,000 rows
   (`Jobs::cancel_pending()`), scoped by exact hook, never by group, because `mvs` is shared. Running, complete
   and failed rows, and every other plugin's rows, are never touched. Once a plugin is off its listeners are
   gone, and Action Scheduler, often loaded by another plugin, would run those rows with no listener and mark
   them complete, so the work is lost either way; cancelling says so. **Work that was pending does not carry
   across a deactivation.** Section 6 lists what repairs each job; several have no recovery path yet. Uninstall deletes
   each plugin's own rows by its own hook list in batches of 1,000 (a batch that deletes nothing stops the
   loop), then the group rows once nothing uses them; Free never deletes a Pro row, and neither drops a table.

## 4. Job list

All Action Scheduler jobs are in group `mvs`. Retention is the rule in 3.8.

### Free

| Hook | Trigger | Rows per event | Arguments |
|---|---|---|---|
| `mvs_cleanup_media_files` | media deleted (paths buffered, queued at shutdown) | one per 7,000-byte chunk of paths; retry rows up to 3 | `[ paths, attempt ]` (exception 3.2) |
| `mvs_ai_process_media` | upload with AI moderation on | 1 per media, deduped | `[ media_id ]` |
| `mvs_deliver_webhook` | a subscribed event | 1 per endpoint; retries up to 3 | event body (exception 3.2) |
| `mvs_cloud_sync_media` | upload with a cloud driver | 1 per media | `{ media_id }` |
| `mvs_cloud_repatriate_media` | media made non-public while its bytes sit on the cloud driver | 1 per media, deduped (pending only) | `{ media_id }` |
| `mvs_user_deletion_cascade` | member deleted | 1, continues per 100 media | `[ user_id ]` or `[ user_id, rest ]` |
| `mvs_user_deletion_purge` | member deleted | 1 | `[ user_id ]` |
| `mvs_tag_merge_batch` | large tag merge in admin | 1, continues per batch | `[ source, target, offset ]` |
| `mvs_tag_forget_batch` | tag deleted | 1 per `FORGET_BATCH` items (first runs inline) | `[ name, ids ]` (exception 3.2) |
| `mvs_close_orphan_reports` | migration v46 | 1, continues per 500 reports | `[ cursor ]` |
| `mvs_move_chat_attachments` | upgrade / chat-files repair | 1, continues per 25 | none |
| `mvs_repair_storage_paths` | post-update storage repair | 1, continues per 25 | `[ cursor ]` |
| `mvs_rotate_media_files` | file-name rotation | 1 per 100 ids | `{ ids }` |
| `mvs_convert_legacy_media_names` | upgrade | 1, continues | none |
| `mvs_backfill_drive_columns` | an old migration | 1, continues per batch | none |
| `mvs_adopt_pending_jobs` | upgrade moved its row cap | 1, continues per 2,000 rows | `[ hooks ]` |

WP-Cron housekeeping (direct `wp_schedule_*()`, not moved to Action Scheduler because that would change when they
run): `mvs_prune_logs` (daily, also runs `prune_history()`), `mvs_purge_old_views` (daily),
`mvs_process_account_deletions` (daily), `wpmediaverse_activate_preset_key` (licence preset, one-off retry that
reads `DISABLE_WP_CRON`).

### Pro

| Hook | Trigger | Rows per event | Arguments |
|---|---|---|---|
| `mvs_competitions_tick` | next competition deadline | at most 1 pending, single action at the deadline | none |
| `mvs_autopilot_create_weekly_challenge` | autopilot on | 1 recurring weekly | none |
| `mvs_daily_streak_check` | streaks on | 1 recurring (cron `0 2 * * *`); continuation per batch | none, or `[ cursor ]` |
| `mvs_story_cleanup` | hourly WP-Cron sweep; continuation | 1 continuation per batch | `[ cursor ]` |
| `mvs_pro_transcribe_media` | captions requested | 1 per media, deduped | `[ media_id ]` |
| `mvs_pro_captions_reap_stale` | a transcription starts (armed even while a reaper runs) | at most 1 waiting reaper; continuation | `[ 0 ]` or `[ last_id ]` |
| `mvs_pro_push_send` | Free's `mvs_push_send` | 1 per notification | `[ user_id, message, data ]` (exception 3.2) |
| `mvs_connector_auto_export` | upload with auto-export | 1 per connector | `[ connector, media_id, author ]` |
| `mvs_pro_connector_delta_sync_continue` | connector sync | 1, continues | sync cursor |
| `mvs_pro_extract_document` | document uploaded | 1 per document, deduped | `[ media_id ]` |
| `mvs_pro_extract_documents` | extraction backfill | 1, continues per 20 | `[ cursor ]` |
| `mvs_document_folder_subtree_rewrite` | folder move over 5,000 descendants | 1 | `[ folder, old_path, new_path, depth_delta ]` (exception 3.2) |
| `mvs_document_folder_privacy_cascade` | privacy change over 5,000 items | 1 | `[ folder, privacy ]` |
| `mvs_document_folder_trash_contents` / `_restore_contents` | folder trashed / restored | 1 per folder, deduped | `[ folder ]` |
| `mvs_document_folder_purge` | trash expiry | 1 per trashed folder at its expiry | `[ folder ]` |
| `mvs_document_space_drive_trash` | space deleted, drive over 200 docs | 1 continuation per run | `[ space_id ]` |

WP-Cron housekeeping (direct `wp_schedule_event()`, light hourly or daily): `mvs_expire_boosts`,
`mvs_pro_flush_boost_impressions`, `mvs_story_cleanup` (hourly start), `mvs_pro_sweep_replaced_documents`,
`mvs_pro_prune_play_events`.

## 5. Adding a job

1. Look in this list and the manifest (`audit/manifests/manifest.json`, `cron`) for a job that already does it.
2. Queue it through Free's `\WPMediaVerse\Core\Jobs`; never call `as_*()` or `wp_schedule_*()` directly
   (the only exceptions are listed in section 1).
3. Add the hook to `Deactivator::JOB_HOOKS` (Free) or `Lifecycle::JOB_HOOKS` / `RECURRING_HOOKS` (Pro), so
   deactivation and uninstall clear it.
4. Add a row to section 4.

## 6. Recovery after a deactivation

A deactivation cancels pending jobs (rule 9). What each one leaves and what repairs it:

| Hook | Left behind | Recovery |
|---|---|---|
| `mvs_move_chat_attachments`, `mvs_convert_legacy_media_names` | state option not done | automatic: re-queued on the next scheduling request |
| `mvs_competitions_tick`, `mvs_daily_streak_check` (Pro) | no pending row | automatic: re-armed on the first scheduling request after activation |
| `mvs_pro_captions_reap_stale` (Pro) | no reaper | the next transcription arms it |
| `mvs_repair_storage_paths` | state stuck at `running` | `wp mvs repair-storage` |
| `mvs_ai_process_media` | no AI status | `wp mvs backfill_ai`, or Re-run AI per item |
| `mvs_cloud_sync_media` | media stays local | `wp mvs migrate-storage --from=local --to=<cloud>` |
| `mvs_tag_merge_batch` | source tag keeps unmoved items | merge again in Tags |
| `mvs_pro_connector_delta_sync_continue`, `mvs_connector_auto_export` (Pro) | partial sync, missed exports | sync again; export per item |
| `mvs_document_folder_restore_contents` (Pro) | documents still trashed | trash and restore the folder again |
| `mvs_document_folder_trash_contents` (Pro) | documents of a trashed folder still published | restore and trash again, or Delete permanently |
| `mvs_document_folder_purge` (Pro) | trashed folder never expires; a `purging` folder half deleted | Delete permanently; a `purging` folder only through REST `DELETE ?force=1` |
| `mvs_cloud_repatriate_media` | private media still in the bucket | manual `wp mvs migrate-storage --from=<cloud> --to=local --include-non-public --media-id=<id>`; nothing finds which |
| `mvs_document_folder_privacy_cascade` (Pro) | documents keep the looser privacy | loosen and tighten the folder again; nothing automatic |
| `mvs_pro_transcribe_media` (Pro) | captions status stuck at `queued` | delete the captions, then generate again |
| `mvs_cleanup_media_files` | files of deleted media stay on disk or CDN | **none** |
| `mvs_rotate_media_files` | old file names (old direct URLs) stay valid | **none** |
| `mvs_user_deletion_cascade`, `mvs_user_deletion_purge` | a deleted member's media and mapped rows stay | **none** (the eraser needs the user to exist) |
| `mvs_tag_forget_batch` | deleted tag name stays in items' tag lists | **none** |
| `mvs_close_orphan_reports` | reports on deleted media stay open | close by hand |
| `mvs_backfill_drive_columns` | drive listing keeps its slower path | **none** |
| `mvs_deliver_webhook`, `mvs_pro_push_send` (Pro) | the event is not delivered | **none** (by nature) |
| `mvs_pro_extract_document`, `mvs_pro_extract_documents` (Pro) | document missing from search; backfill stuck at `running` | **none** |
| `mvs_document_folder_subtree_rewrite` (Pro) | moved folder stuck at `moving`, hidden from listings | **none** |
| `mvs_document_space_drive_trash` (Pro) | documents of a deleted space stay published | **none** |

Rows marked **none** are open: each needs a sweep or command before deactivation may cancel it without loss.

