---
journey: notification-hook-message-link
plugin: wpmediaverse
priority: medium
roles: [system]
covers: [mvs_notification_created, notification-contract, buddynext-integration]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "Two users (recipient + actor) and one media item exist"
estimated_runtime_minutes: 2
---

# `mvs_notification_created` carries the rendered message + deep link

**Why this journey exists**: BuddyNext's central notification center mirrors every Wbcom plugin's notifications. The hook previously passed only IDs, so consumers had to re-derive the text (which drifts from WPMediaVerse's own wording). 1.7.0 appends two backward-compatible args — `$message` (same text as the plugin's own notifications menu) and `$link` (deep link) — sourced from the single shared `build_message_and_link()` builder so REST output and the hook never diverge. This journey guards that contract. (Basecamp card: "Pass rendered message + link in mvs_notification_created — BuddyNext integration".)

## Steps

### 1. Capture the hook payload
- **Action**:
  ```bash
  wp eval '
  $cap=[];
  add_action("mvs_notification_created", function() use (&$cap){ $cap[]=func_get_args(); }, 10, 7);
  $mid = (int) $GLOBALS["wpdb"]->get_var("SELECT media_id FROM {$GLOBALS[\"wpdb\"]->prefix}mvs_media_index LIMIT 1");
  \WPMediaVerse\Core\Plugin::container()->get("notifications")->create(1, "media_reaction", 2, $mid);
  echo "args=".count($cap[0])." msg=".$cap[0][5]." link=".$cap[0][6];
  '
  ```
- **Expect**: `args=7`; arg index 5 (`$message`) is a non-empty human string (e.g. "X reacted to Y"); arg index 6 (`$link`) is a valid URL (media permalink, profile, or /messages/).

### 2. Wording matches the REST notifications list
- **Action**: `curl $SITE_URL/wp-json/mvs/v1/notifications` (as the recipient) and compare the newest item's `message`/`url`.
- **Expect**: identical wording + destination to the hook payload — no drift (both come from `build_message_and_link()`).

### 3. Backward compatibility
- **Expect**: an existing 5-arg listener (`add_action('mvs_notification_created', $cb, 10, 5)`) still fires without error — the extra args are appended, not reordered.
