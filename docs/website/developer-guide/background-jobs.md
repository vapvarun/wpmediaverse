# Background Jobs

> **Free + Pro** - Jobs marked **(Pro)** run only with MediaVerse Pro active.

MediaVerse runs slow work (file clean-up, member deletion, webhooks, document extraction, competition deadlines) in the background with Action Scheduler, which MediaVerse bundles. You can see every job under **Tools > Scheduled Actions**: search for `mvs_` or filter by the group `mvs`.

## What to expect on a site

- **One group.** Every MediaVerse job, Free and Pro, is in the Action Scheduler group `mvs`.
- **No polling.** Jobs are queued by the event that needs them (an upload, a deletion, a competition deadline). Nothing runs every few minutes waiting for work.
- **Finished jobs are trimmed.** Completed and cancelled MediaVerse jobs are deleted after 7 days, with their log rows, on the daily `mvs_prune_logs` run. Failed jobs are kept for 90 days so you can see what went wrong. Pending and running jobs and other plugins' jobs are never touched, and the site-wide Action Scheduler retention is left as it is.
- **Deactivation cancels recomputable jobs and keeps the rest.** Switching MediaVerse (or Pro) off cancels its pending jobs that come back by themselves or cannot be redone (AI analysis, webhook and push delivery, search indexing, the competitions tick). Jobs that finish something a member or owner already did (deleting media files, a privacy change, a member deletion, a folder move, trash or purge) stay queued, so the usual update (deactivate, replace, activate) loses nothing. If MediaVerse stays off while another plugin keeps Action Scheduler running, those queued jobs run with nothing to handle them and are lost; let them finish first (Tools > Scheduled Actions, search `mvs_`). Only the plugin's own hooks are touched, never another plugin's jobs, and running, complete and failed jobs stay. Switching Pro back on re-arms the competitions tick, the daily streak check and the document search backfill on the next admin page load.
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
| `StorageCleanupService::AS_GROUP`, `StorageRepairService::AS_GROUP` | `wpmediaverse` | Deprecated; now equal `mvs` |
| `ExtractionService::AS_GROUP`, `CompetitionsScheduler::AS_GROUP` **(Pro)** | `wpmediaverse-pro` | Deprecated; now equal `mvs` |
| `FolderService::enqueue()`, `FolderService::dequeue()` **(Pro)** | Public helpers | Deprecated (they still work and log a deprecation notice; removed in 4.0.0, like every deprecation in this table) |
| `WPMediaVersePro\Core\Jobs` **(Pro)** | Pro's scheduling helpers | Deprecated; use `WPMediaVerse\Core\Jobs` (Free, new in 2.6.5), the one entry point for both plugins: its methods are listed in the plugin repository at `docs/standards/background-jobs.md` |
| `Deactivator::clear_scheduled( $include_jobs )`, `Lifecycle::clear_scheduled( $include_jobs )` **(Pro)** | `false` cleared recurring jobs only | The argument is ignored; both cancel every pending job of the plugin |
| `DirectDelivery::is_scheduling_request()` | Admin page loads and cron | Deprecated; use `WPMediaVerse\Core\Jobs::is_scheduling_request()`, which also allows WP-CLI and Action Scheduler's daily housekeeping run |
| `StorageCleanupService::MAX_ATTEMPTS` | 5 | 3, the same cap as webhooks |
| `mvs_ai_process_media` arguments | `{ media_id }` | `[ media_id ]` (the listener accepts both, so jobs queued before the update still run) |
| `TranscriptionService::maybe_schedule_reaper()`, `REAPER_INTERVAL_SECONDS` **(Pro)** | Scheduled the 5-minute reaper | Deprecated; the reaper arms itself when a transcription starts |
| `CompetitionsScheduler::maybe_schedule_tick()` **(Pro)** | Scheduled the 5-minute tick | Deprecated; calls `CompetitionsScheduler::arm()` |
| `StreakService::maybe_schedule_daily_cron()` **(Pro)** | Scheduled the daily check | Deprecated; calls `StreakService::sync_daily_cron()` |
| `CompetitionsScheduler::unschedule_tick()`, `PushService::deactivate()`, `StoryService::deactivate()`, `ReplacedFileSweep::unschedule()` **(Pro)** | Deactivation helpers | Deprecated; nothing calls them, deactivation and uninstall clear every job through `Lifecycle::clear_scheduled()` |
| Free version Pro needs **(Pro)** | 2.6.4 | 2.6.5 (Pro shows the "requires the free plugin" notice and stays off with an older Free) |
| Competition tick **(Pro)** | `mvs_competitions_tick` every 5 minutes | One job at the next deadline; the recurring row is cancelled on upgrade |
| Captions reaper **(Pro)** | `mvs_pro_captions_reap_stale` every 5 minutes | Armed only while a transcription is running; the recurring row is cancelled on upgrade |
| Streak check **(Pro)** | Queued whether or not streaks were on | Queued only while streaks are on |

If your code looked up a MediaVerse job with a group argument, drop the group (see the example above). Hook names did not change; the only argument change is `mvs_ai_process_media` above.
