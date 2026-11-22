# Background Jobs

> **Free + Pro** - Jobs marked **(Pro)** run only with MediaVerse Pro active.

MediaVerse runs slow work (file clean-up, member deletion, webhooks, document extraction, competition deadlines) in the background with Action Scheduler, which MediaVerse bundles. You can see every job under **Tools > Scheduled Actions**: search for `mvs_` or filter by the group `mvs`.

## What to expect on a site

- **One group.** Every MediaVerse job, Free and Pro, is in the Action Scheduler group `mvs`.
- **No polling.** Jobs are queued by the event that needs them (an upload, a deletion, a competition deadline). Nothing runs every few minutes waiting for work.
- **Finished jobs are trimmed.** Completed MediaVerse jobs are deleted 7 days after they finished, and cancelled ones 7 days after they were due, with their log rows, on the daily `mvs_prune_logs` run. Failed jobs are kept for 90 days after their last attempt so you can see what went wrong. Pending and running jobs and other plugins' jobs are never touched, and the site-wide Action Scheduler retention is left as it is.
- **Deactivation cancels recomputable jobs and keeps the rest.** Switching MediaVerse (or Pro) off cancels its pending jobs that come back by themselves or cannot be redone (AI analysis, push delivery, search indexing, the competitions tick). Jobs that finish something a member or owner already did (deleting media files, a privacy change, a member deletion, a folder move, trash or purge, a captions request) or carry data nothing else holds (a webhook's event, an upgrade step) stay queued, so the usual update (deactivate, replace, activate) loses nothing. If MediaVerse stays off while another plugin keeps Action Scheduler running, those queued jobs run with nothing to handle them and show as Failed ("no callbacks are registered"). Switching MediaVerse back on, or updating it with the one-click updater, queues again the ones that failed in the last 7 days, with the same arguments, on the next admin page load; older ones stay failed. A job that ran and failed for another reason stays failed. Only the plugin's own hooks are touched, never another plugin's jobs, and running, complete and failed jobs stay. Switching Pro back on re-arms the competitions tick and the daily streak check, and indexes any document uploaded while it was off, on the next admin page load.
- **Failed file deletions are retried.** When a file cannot be deleted from storage (a cloud outage), MediaVerse tries again after 5 and 10 minutes, 3 attempts in all; change the number with the [`mvs_storage_cleanup_max_attempts`](hooks-filters.md#mvs_storage_cleanup_max_attempts-new-in-265) filter. After the last attempt the file paths are listed as an error under **MediaVerse > Logs**; delete those files by hand.
- **Works without a server cron.** Action Scheduler runs on normal site traffic through WP-Cron. A real server cron is an optional speed-up, never a requirement.

## Keep finished jobs longer or shorter

Use the [`mvs_finished_action_retention_days`](hooks-filters.md#mvs_finished_action_retention_days-new-in-265) filter. It takes whole days; values below 1 count as 1.

```php
add_filter( 'mvs_finished_action_retention_days', function () {
    return 14;
} );
```

With this filter, a finished job from 10 days ago stays and one from 20 days ago is deleted on the next daily run. Failed jobs follow their own 90-day rule.

## Look up a MediaVerse job from your own code

Look jobs up by hook name, not by group, so jobs queued before 2.6.5 are found too:

```php
if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( 'mvs_user_deletion_cascade', array( $user_id ) ) ) {
    // MediaVerse is still removing this member's media.
}
```

The full job list (hook, trigger, how many jobs one event creates, arguments) is kept in the plugin repository at `docs/standards/background-jobs.md`.

## Changes in 2.6.5 for developers

| Change | Before | From 2.6.5 |
|---|---|---|
| Action Scheduler group | `wpmediaverse`, `wpmediaverse-pro`, `mvs-pro`, `mvs-connectors` | `mvs` for every job. Pending jobs are moved on upgrade with the same hook, arguments and run time. |
| Finished-job retention | Action Scheduler's site-wide 31 days | 7 days for MediaVerse's own completed and cancelled jobs (filter `mvs_finished_action_retention_days`, new) and 90 days for its failed jobs |
| Deactivation | Cancelled only recurring jobs; one-off jobs stayed queued with no listener | Cancels the plugin's recomputable pending jobs (`WPMediaVerse\Core\Jobs::cancel_pending()`, new) and keeps the ones listed in `Deactivator::STATE_JOB_HOOKS` / `Lifecycle::STATE_JOB_HOOKS`; Pro re-arms its competitions tick and streak check after reactivation |
| Jobs failed while the plugin was off | Stayed failed ("no callbacks are registered") | Queued again with the same arguments after reactivation or an update that runs the plugin's migrations (`WPMediaVerse\Core\Jobs::requeue_after_activation()` / `requeue_unheard()`, new), for the plugin's `STATE_JOB_HOOKS` that failed in the last 7 days |
| `StorageCleanupService::AS_GROUP`, `StorageRepairService::AS_GROUP` | `wpmediaverse` | Deprecated; now equal `mvs` |
| `ExtractionService::AS_GROUP`, `CompetitionsScheduler::AS_GROUP` **(Pro)** | `wpmediaverse-pro` | Deprecated; now equal `mvs` |
| `FolderService::enqueue()`, `FolderService::dequeue()` **(Pro)** | Public helpers | Deprecated (they still work and log a deprecation notice; removed in 4.0.0, like every deprecation in this table) |
| `WPMediaVersePro\Core\Jobs` **(Pro)** | Pro's scheduling helpers | Deprecated; use `WPMediaVerse\Core\Jobs` (Free, new in 2.6.5), the one entry point for both plugins: its methods are listed in the plugin repository at `docs/standards/background-jobs.md` |
| `Deactivator::clear_scheduled()`, `Lifecycle::clear_scheduled()` **(Pro)** | Required `$include_jobs`: `false` cleared the recurring WP-Cron events only, `true` every job | Optional `$include_state_jobs`. `false` (deactivation) cancels every pending job of the plugin except `STATE_JOB_HOOKS`; `true` (uninstall, the default) cancels those too. Passing `false` outside the plugin's own deactivation logs a deprecation notice |
| `DirectDelivery::is_scheduling_request()` | Admin page loads and cron | Deprecated; use `WPMediaVerse\Core\Jobs::is_scheduling_request()`, which also allows WP-CLI and Action Scheduler's daily housekeeping run |
| `StorageCleanupService::MAX_ATTEMPTS` | 5 | 3, the same cap as webhooks; filterable with `mvs_storage_cleanup_max_attempts` (new) |
| `WPMediaVerse\Core\Jobs::at()` | - | New in 2.6.5; a fourth argument `$unique` refuses the job when a pending or running copy with the same arguments exists |
| `DocumentLicense` **(Pro)** | Licence gate for document writes | Deprecated; every method now logs a deprecation notice; removed in 4.0.0 |
| `mvs_ai_process_media` arguments | `{ media_id }` | `[ media_id ]` (the listener accepts both, so jobs queued before the update still run) |
| `TranscriptionService::maybe_schedule_reaper()`, `REAPER_INTERVAL_SECONDS` **(Pro)** | Scheduled the 5-minute reaper | Deprecated; the reaper arms itself when a transcription starts |
| `CompetitionsScheduler::maybe_schedule_tick()` **(Pro)** | Scheduled the 5-minute tick | Deprecated; calls `CompetitionsScheduler::arm()` |
| `StreakService::maybe_schedule_daily_cron()` **(Pro)** | Scheduled the daily check | Deprecated; calls `StreakService::sync_daily_cron()` |
| `CompetitionsScheduler::unschedule_tick()`, `PushService::deactivate()`, `StoryService::deactivate()`, `ReplacedFileSweep::unschedule()` **(Pro)** | Deactivation helpers | Deprecated; they still work, but nothing calls them: `Lifecycle::clear_scheduled()` clears these hooks on deactivation and uninstall |
| Free version Pro needs **(Pro)** | 2.6.4 | 2.6.5 (Pro shows the "requires the free plugin" notice and stays off with an older Free) |
| Competition tick **(Pro)** | `mvs_competitions_tick` every 5 minutes | One job at the next deadline; the recurring row is cancelled on upgrade |
| Captions reaper **(Pro)** | `mvs_pro_captions_reap_stale` every 5 minutes | Armed only while a transcription is running; the recurring row is cancelled on upgrade |
| Streak check **(Pro)** | Queued whether or not streaks were on | Queued only while streaks are on |

If your code looked up a MediaVerse job with a group argument, drop the group (see the example above). Hook names did not change; the only argument change is `mvs_ai_process_media` above.
