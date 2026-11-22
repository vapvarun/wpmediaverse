# Background jobs (Free + Pro)

The portfolio rule set is the Wbcom background-jobs standard (`~/.claude/workflows/wp-background-jobs.md`
for maintainers). This page is MediaVerse's copy: the rules as we apply them, and the real job list. Update
the job list in the same commit as any change to a job.

## 1. One entry point for the family

Every MediaVerse job, Free and Pro, is queued, found and cancelled through Free's `WPMediaVerse\Core\Jobs`.
Pro requires Free 2.6.5 (`MVS_PRO_MIN_FREE`) and calls it directly, as it calls Free's other static helpers.

Methods: `has_as()`, `when_ready( $cb )`, `async( $hook, $args )`, `async_once( $hook, $args )`,
`at( $time, $hook, $args )`, `is_queued( $hook, $args|null )`, `is_pending( $hook, $args|null )`, `next( $hook )`,
`cancel( $hook, $args )`, `pending_args( $hook )`, `ensure_recurring( $hook, $first, $schedule, $cron )`,
`is_scheduling_request()`, `prune_history()`, `adopt_pending( $hooks )`. The names match Jetonomy's `Queue`.

- `async()` / `at()` use Action Scheduler (Free's bundled copy, `libs/action-scheduler`) once its store is
  ready, and WP-Cron before that (for example an upgrade routine running early in a request). They return
  `false` when neither scheduler took the job. A caller whose work must not be lost does the work inline
  on `false`, and that is the only fallback code a caller carries. On WP-Cron the arguments are stored as a
  list, so the listener receives them positionally on both schedulers (PHP 8 would read string keys as named
  parameters).
- `is_queued()` counts a pending **or running** copy: use it to avoid starting the same work twice.
  `is_pending()` counts only a copy waiting to start: use it when the question is whether a later run is still
  coming (a reaper, a follow-up pass, a continuation queued from inside the running job). `async_once()` queues
  unless a copy with the same arguments is waiting.
- Lookups and cancels match by hook and arguments in **any** group. Every MediaVerse hook starts with `mvs_`
  and belongs to us alone, and a row queued before 2.6.5 may still sit in an old group.
- `ensure_recurring()` keeps exactly one argument-less recurring row (a continuation of the same hook carries a
  cursor and does not count) and runs only on scheduling requests.
- Pro's `WPMediaVersePro\Core\Jobs` keeps only its two 2.6.4 methods (`ensure_recurring()` with its old
  five-argument signature, `is_scheduling_request()`), deprecated, handing off to Free's.
- Direct Action Scheduler calls outside `Jobs` are limited to these, each commented in place:
  `CompetitionsScheduler::arm()` reads `as_next_scheduled_action()` because it answers `true` while a tick
  runs; `TranscriptionService::retire_recurring_reaper()` checks and cancels only the argument-less row in the
  legacy `mvs-pro` group (one-time retirement); Pro `Lifecycle::clear_scheduled()` and both `uninstall.php`
  files, which run when the plugins may not be loaded.
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
re-saved in `mvs` with the same hook, arguments and schedule, and the old row is deleted. Each plugin moves
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
   - `mvs_user_deletion_cascade` carries `[ user_id, rest ]` on a continuation: the media ids still to delete,
     read before the member's rows go.
   - `mvs_pro_push_send` carries `[ user_id, message, data ]`, the push as it was when the notification fired.
   - `mvs_document_folder_subtree_rewrite` carries the old and new folder paths, which no longer exist on the
     folder rows once the move is saved.
3. **Cursor jobs.** A sweep processes one bounded batch and queues its own continuation with the last id as
   the cursor (report sweep, user-deletion cascade, story cleanup, streak check, document extraction backfill,
   space-drive trash, storage repair). Each run handles one bounded batch (the sizes are in section 4: 20 to 500
   rows) and queues at most one continuation, so a sweep is one job at a time, never one job per row.
4. **No `unique` with arguments.** Action Scheduler's `$unique` flag ignores arguments, so a unique job with
   arguments would block every other job of the hook. Duplicate guards use `Jobs::is_queued( $hook, $args )`.
   `$unique` is used only for argument-less recurring rows (`Jobs::ensure_recurring()`).
5. **Never skip.** A job is never dropped because the queue is busy or stalled; at most an identical pending
   copy is reused.
6. **Retries are capped at 3** (`StorageCleanupService::MAX_ATTEMPTS`, `WebhookService::MAX_ATTEMPTS`), with a
   5-minute step (`RETRY_STEP_SECONDS`).
7. **Recurring rows are armed only on scheduling requests**: cron, WP-CLI, an admin page load or Action
   Scheduler's daily housekeeping run, never a visitor's request (`Jobs::is_scheduling_request()`). The streak
   check and the competitions tick also re-arm on `action_scheduler_ensure_recurring_actions`; autopilot re-arms
   on its next scheduling request (its `init` check runs on every cron request).
8. **Own rows only, one retention rule for the family.** The daily `mvs_prune_logs` event calls
   `Jobs::prune_history()`: in group `mvs`, completed actions older than `mvs_finished_action_retention_days`
   (default 7) and failed actions older than 90 days (`Jobs::FAILED_RETENTION_DAYS`, the same rule Jetonomy
   applies) are deleted with their log rows, 200 per batch, at most 50 batches of each a run. Pending and
   running rows, and every other plugin's rows, are never touched. We never change Action Scheduler's
   site-wide retention filters.
9. **Deactivation clears the recurring lists; uninstall clears every list**, in both schedulers
   (Free `Deactivator::CRON_HOOKS`, plus `JOB_HOOKS` on uninstall; Pro `Lifecycle::RECURRING_HOOKS`, plus
   `JOB_HOOKS` on uninstall). One-off jobs survive a deactivate so half-done work resumes. Uninstall deletes
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
