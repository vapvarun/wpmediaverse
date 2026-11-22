# Background Jobs

> **Free + Pro** - Jobs marked **(Pro)** run only with MediaVerse Pro active.

MediaVerse runs slow work (file clean-up, member deletion, webhooks, document extraction, competition deadlines) in the background with Action Scheduler, which MediaVerse bundles. You can see every job under **Tools > Scheduled Actions**: search for `mvs_` or filter by the group `mvs`.

## What to expect on a site

- **One group.** Every MediaVerse job, Free and Pro, is in the Action Scheduler group `mvs`.
- **No polling.** Jobs are queued by the event that needs them (an upload, a deletion, a competition deadline). Nothing runs every few minutes waiting for work.
- **Finished jobs are trimmed.** Completed MediaVerse jobs are deleted after 7 days, with their log rows, on the daily `mvs_prune_logs` run. Failed jobs are kept so you can see what went wrong. Other plugins' jobs are never touched, and the site-wide Action Scheduler retention is left as it is.
- **Works without a server cron.** Action Scheduler runs on normal site traffic through WP-Cron. A real server cron is an optional speed-up, never a requirement.

## Keep finished jobs longer or shorter

Use the [`mvs_finished_action_retention_days`](hooks-filters.md#mvs_finished_action_retention_days-new-in-265) filter. It takes whole days; values below 1 count as 1.

```php
add_filter( 'mvs_finished_action_retention_days', function () {
    return 14;
} );
```

With this filter, a finished job from 10 days ago stays and one from 20 days ago is deleted on the next daily run.

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
| Finished-job retention | Action Scheduler's site-wide 30 days | 7 days for MediaVerse's own completed jobs, filter `mvs_finished_action_retention_days` (new) |
| `StorageCleanupService::AS_GROUP`, `StorageRepairService::AS_GROUP` | `wpmediaverse` | Deprecated; now equal `mvs` |
| `ExtractionService::AS_GROUP`, `CompetitionsScheduler::AS_GROUP` **(Pro)** | `wpmediaverse-pro` | Deprecated; now equal `mvs` |
| `FolderService::enqueue()`, `FolderService::dequeue()` **(Pro)** | Public helpers | Deprecated (they still work and log a deprecation notice) |
| Competition tick **(Pro)** | `mvs_competitions_tick` every 5 minutes | One job at the next deadline; the recurring row is cancelled on upgrade |
| Captions reaper **(Pro)** | `mvs_pro_captions_reap_stale` every 5 minutes | Armed only while a transcription is running; the recurring row is cancelled on upgrade |
| Streak check **(Pro)** | Queued whether or not streaks were on | Queued only while streaks are on |

If your code looked up a MediaVerse job with a group argument, drop the group (see the example above). Hook names and their arguments did not change.
