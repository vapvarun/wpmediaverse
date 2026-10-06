# Wbcom preset licence activation standard

Applies to every Wbcom **free** plugin that ships a baked-in ("preset") licence
key so update downloads work. Reference implementations, kept line for line the
same apart from names, key and item id:

- `buddynext/includes/Core/PresetActivation.php` (from 1.2.4)
- `wpmediaverse/includes/Core/PresetActivation.php` (from 2.6.1)

To adopt it in another plugin, copy the class, then change only: namespace,
option and hook prefix, text domain, product name, `PRESET_KEY`, `ITEM_ID`.
Copy the tests too (`PresetActivationStoreAnswerTest`).

## The incident this exists to prevent

Old releases posted `activate_license` to wbcomdesigns.com on **every**
`admin_init` until the reply was `valid`. `admin_init` also fires on
admin-ajax.php, so Heartbeat repeated it every few minutes. Any error, timeout,
firewall page or refusal meant another request. One site sent 933 requests in
23 hours; a single host sent bursts every 8 to 12 seconds (October 2026). The
store had to block sites at the edge.

## The rules

1. **Never call the store on a page load.** `admin_init` only *queues* a
   background single event (30 seconds out). No remote request on admin pages,
   admin-ajax, Heartbeat or REST.
2. **A store answer is final.** If the reply is JSON with a `license` field:
   `valid` marks the site activated; anything else (`expired`, `disabled`,
   `no_activations_left`, `invalid`, ...) is a refusal. Asking again cannot
   change it, so stop at once, store the reason (`error`, else `license`) and
   show the owner notice. Never retry a refusal automatically.
3. **No answer is retried, with a bound.** Network error, firewall page, 5xx or
   non-JSON: retry hourly, at most 24 times, then give up. Do not shorten the
   interval or raise the count.
4. **Giving up is latched.** Once given up, page loads do not re-arm anything.
   Only the owner's Retry does.
5. **The owner is told, with one action.** A warning notice for
   `manage_options` users says what happened in plain words (could not reach
   the store, or the store's reason) and has one button: *Retry activation
   now*. A retry reports its result. A retry never resets the attempt count.
6. **Dead cron is handled.** With `DISABLE_WP_CRON`, or a queued event overdue
   by more than an hour, make one inline attempt (5 second timeout) and give up
   on failure so the notice shows on that same load. Filter:
   `{prefix}_wp_cron_disabled`.
7. **Timeout is 5 seconds.** It is a fire-and-forget authorisation.
8. **No consent is written.** Activation never switches usage tracking on.
9. **Deactivation clears the queued event.** A switched-off plugin asks the
   store nothing.
10. **Pro plugins have no loop at all.** A customer licence is activated only
    when the owner submits the licence form.

## State (option names use the plugin's own prefix)

| Option | Meaning |
|---|---|
| `{prefix}_preset_activated` | `1` once the store said `valid` |
| `{prefix}_preset_activation_attempts` | failed tries so far; cleared on success |
| `{prefix}_preset_activation_gave_up` | time we stopped; blocks re-arming |
| `{prefix}_preset_activation_refused` | the store's reason when it answered no |

## Checks before release

- Tests: refusal sends one request and queues nothing; no answer queues one
  retry and stores no reason; `valid` clears an earlier refusal; an admin page
  load sends nothing.
- Browser: with the refused state set, the notice names the reason and shows
  the Retry button; with the site activated there is no notice.
- `grep -rn "activate_license" includes/ *.php`: the only automatic caller is
  `PresetActivation`. Anything else must be an owner's click.

## Store side

Free preset keys are lifetime with unlimited activations (BuddyNext 39021,
MediaVerse 38280). A key that can expire turns every site into a refusal on
the same day.
