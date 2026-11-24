# What's New in 2.7.0

MediaVerse 2.7.0 makes every control easier to see and reach by keyboard, moves background work onto one safer queue, and keeps the site running when a PHP extension is missing.

> **Included in Free** unless marked Pro. Paired with MediaVerse Pro 2.7.0 - install and test both together. Requires PHP 8.1 or newer.

> **Sites on 2.6.1 to 2.6.4:** update to 2.7.0 before you delete the plugin. Deleting those versions can fail; 2.7.0 removes its data in batches.

## Controls and accessibility

- Fields, selects, outlined buttons, chips and pills have an edge that stands out at 3:1 on light and dark themes.
- Every control shows a visible ring on keyboard focus, including the Upload media button, Explore filters, Load more and the collection type toggle.
- Buttons, badges and Follow labels switch to black text where white does not stand out on the site's brand colour.
- The chat's sent messages and filled buttons keep their text readable on any brand colour.
- The guest login prompt is no longer dimmed, and tag remove buttons are no longer faded.
- Share, Report and document share dialogs are named for screen readers.
- On phones the search field draws its own edge, so the content below it sits 7px lower.

## Members

- Members can cancel a follow request, and the button shows Requested while it waits.
- Dates show in the site's display timezone on every surface, and member emails go out in the recipient's language.
- One media edit form on every surface, with Replace File and a required title on the media page too.

## Site owners

- **Chat Button Position** moves the floating chat to the bottom left. See [Social settings](../settings/social.md).
- The Media Grid block has a Show pagination switch and adapts to narrow columns and sidebars.
- Deleting a reported item closes its reports.
- Licence notices appear only on Plugins, Updates and MediaVerse screens.
- The remove-data option says what is deleted, what stays and where the uploaded files live.

## Background jobs

- Every background job runs through one Action Scheduler queue and group, with Action Scheduler 4.0.0 bundled.
- Finished jobs are cleaned after 7 days, failed ones after 90, and only MediaVerse's own rows are touched.
- Deactivating keeps jobs that finish something a member already did, and reactivating queues again the ones that failed while MediaVerse was off.

See [Background Jobs](../developer-guide/background-jobs.md).

## Pro

- **Pro** Competition tabs, the collection picker and the photo import dialog show a focus ring, and competition blocks and shortcodes are styled on any page.
- **Pro** The competitions tick runs at the next deadline instead of every 5 minutes.
- **Pro** Document search backfill starts, resumes after a lost run and re-reads replaced files.
- **Pro** A document share reaches BuddyNext's notification centre.

## For developers

- New filters `mvs_can_follow`, `mvs_blocked_either_way_ids` and `mvs_display_timezone`, and new action `mvs_follow_request_withdrawn`.
- New filters `mvs_finished_action_retention_days`, `mvs_failed_action_retention_days` and `mvs_storage_cleanup_max_attempts`.
- Nine Free templates and five Pro templates carry a new version; check your theme overrides.

See [Hooks and Filters](../developer-guide/hooks-filters.md) and [Template Overrides](../developer-guide/template-overrides.md).
