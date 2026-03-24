# WPMediaVerse — Reusable QA Suite

**Version:** 1.1.0
**Scope:** Free + Pro, with and without BuddyPress
**Site:** wb-media.local (Local by Flywheel)
**Method:** WP-CLI + Playwright browser tests
**Reusable:** Run before every release, after major changes, or on-demand via `/autovap-dev qa`

---

## How to Run

### Full suite (WP-CLI + browser)
```
/autovap-dev qa
```

### WP-CLI only (fast, no browser needed)
```bash
cd /Users/varundubey/Local\ Sites/wb-media/app/public
# Copy-paste any section below into terminal
```

### Browser only (via Playwright MCP)
```
# Navigate to each URL with ?autologin=1
# Take screenshots at each step
```

---

## Part 1: Database & Schema (WP-CLI)

### 1.1 Tables exist

```bash
wp eval "
global \$wpdb;
\$expected = [
  'mvs_reactions','mvs_favorites','mvs_media_views','mvs_media_stats',
  'mvs_access_rules','mvs_access_grants','mvs_mentions','mvs_album_items',
  'mvs_media_index','mvs_error_log','mvs_follows','mvs_notifications',
  'mvs_reports','mvs_blocks','mvs_activity',
  'mvs_conversations','mvs_conversation_participants','mvs_messages','mvs_message_reactions'
];
\$existing = \$wpdb->get_col(\"SHOW TABLES LIKE '{$wpdb->prefix}mvs_%'\");
\$short = array_map(fn(\$t) => str_replace(\$wpdb->prefix, '', \$t), \$existing);
foreach (\$expected as \$t) {
  \$ok = in_array(\$t, \$short) ? 'OK' : 'MISSING';
  echo \"[\$ok] \$t\" . PHP_EOL;
}
echo 'Total: ' . count(\$existing) . ' tables' . PHP_EOL;
"
```

**Expected:** 19 free tables + 5 pro tables (if Pro active) = 24 total. Zero MISSING.

### 1.2 Pro tables (only if Pro active)

```bash
wp eval "
global \$wpdb;
\$pro = ['mvs_quota_packages','mvs_credit_log','mvs_play_events','mvs_email_leads','mvs_transactions'];
\$existing = \$wpdb->get_col(\"SHOW TABLES LIKE '{$wpdb->prefix}mvs_%'\");
\$short = array_map(fn(\$t) => str_replace(\$wpdb->prefix, '', \$t), \$existing);
foreach (\$pro as \$t) {
  \$ok = in_array(\$t, \$short) ? 'OK' : 'MISSING';
  echo \"[\$ok] \$t\" . PHP_EOL;
}
"
```

### 1.3 Index table populated

```bash
wp eval "
global \$wpdb;
\$count = \$wpdb->get_var(\"SELECT COUNT(*) FROM {$wpdb->prefix}mvs_media_index\");
\$posts = \$wpdb->get_var(\"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='mvs_media' AND post_status='publish'\");
echo \"Index rows: \$count | Published media: \$posts\" . PHP_EOL;
echo (\$count == \$posts) ? '[OK] Index in sync' : '[WARN] Index out of sync — run deactivate/reactivate to rebuild';
echo PHP_EOL;
"
```

---

## Part 2: Capabilities & Permissions (WP-CLI)

### 2.1 Role capabilities

```bash
wp eval "
\$caps = ['upload_mvs_media','edit_mvs_media','delete_mvs_media','edit_others_mvs_medias',
  'delete_others_mvs_medias','publish_mvs_medias','read_private_mvs_medias',
  'moderate_mvs_media','manage_mvs_settings','manage_mvs_albums',
  'manage_mvs_collections','view_mvs_logs'];
\$roles = ['administrator','editor','author','subscriber'];
foreach (\$roles as \$r) {
  \$role = get_role(\$r);
  \$has = [];
  foreach (\$caps as \$c) { if (\$role && \$role->has_cap(\$c)) \$has[] = \$c; }
  echo \$r . ': ' . count(\$has) . '/' . count(\$caps) . ' — ' . implode(', ', \$has) . PHP_EOL;
}
"
```

**Expected:**
- administrator: 12/12 (all caps)
- editor: 11/12 (no manage_mvs_settings)
- author: 8/12
- subscriber: 4/12 (upload, edit own, delete own, read)

### 2.2 REST endpoint permissions

```bash
wp eval "
\$tests = [
  ['GET','/mvs/v1/media',0,'200'],
  ['GET','/mvs/v1/me/notifications',0,'401'],
  ['GET','/mvs/v1/me/notifications',1,'200'],
  ['GET','/mvs/v1/moderation',1,'200'],
  ['GET','/mvs/v1/me/conversations',0,'401'],
  ['GET','/mvs/v1/me/conversations',1,'200'],
  ['POST','/mvs/v1/users/2/follow',0,'401'],
  ['POST','/mvs/v1/users/2/follow',1,'200'],
];
foreach (\$tests as [\$method,\$route,\$uid,\$expect]) {
  wp_set_current_user(\$uid);
  \$req = new WP_REST_Request(\$method, \$route);
  \$res = rest_do_request(\$req);
  \$status = \$res->get_status();
  \$ok = (\$status == \$expect) ? 'OK' : 'FAIL';
  \$user = \$uid ? 'admin' : 'anon';
  echo \"[\$ok] \$method \$route (\$user) → \$status (expected \$expect)\" . PHP_EOL;
}
// Cleanup follow
wp_set_current_user(1);
\$req = new WP_REST_Request('DELETE', '/mvs/v1/users/2/follow');
rest_do_request(\$req);
"
```

**Expected:** All OK, zero FAIL.

---

## Part 3: REST API Data Flow (WP-CLI)

### 3.1 Media CRUD

```bash
wp eval "
wp_set_current_user(1);

// CREATE
\$req = new WP_REST_Request('POST', '/mvs/v1/media');
\$req->set_param('title', 'QA Test Media');
\$req->set_param('privacy', 'public');
\$res = rest_do_request(\$req);
\$id = \$res->get_data()['id'] ?? null;
echo '[' . (\$res->get_status() === 201 ? 'OK' : 'FAIL') . '] CREATE: status=' . \$res->get_status() . ' id=' . \$id . PHP_EOL;

if (\$id) {
  // READ
  \$req2 = new WP_REST_Request('GET', '/mvs/v1/media/' . \$id);
  \$res2 = rest_do_request(\$req2);
  echo '[' . (\$res2->get_status() === 200 ? 'OK' : 'FAIL') . '] READ: status=' . \$res2->get_status() . PHP_EOL;

  // UPDATE
  \$req3 = new WP_REST_Request('PUT', '/mvs/v1/media/' . \$id);
  \$req3->set_param('title', 'QA Test Updated');
  \$res3 = rest_do_request(\$req3);
  echo '[' . (\$res3->get_status() === 200 ? 'OK' : 'FAIL') . '] UPDATE: status=' . \$res3->get_status() . PHP_EOL;

  // DELETE
  \$req4 = new WP_REST_Request('DELETE', '/mvs/v1/media/' . \$id);
  \$res4 = rest_do_request(\$req4);
  echo '[' . (\$res4->get_status() === 200 ? 'OK' : 'FAIL') . '] DELETE: status=' . \$res4->get_status() . PHP_EOL;
}
"
```

### 3.2 Reaction flow (toggle + stats + notification)

```bash
wp eval "
global \$wpdb;
wp_set_current_user(1);
\$media_id = \$wpdb->get_var(\"SELECT ID FROM {$wpdb->posts} WHERE post_type='mvs_media' AND post_status='publish' AND post_author != 1 LIMIT 1\");
if (!\$media_id) { \$media_id = \$wpdb->get_var(\"SELECT ID FROM {$wpdb->posts} WHERE post_type='mvs_media' AND post_status='publish' LIMIT 1\"); }

// Add reaction
\$req = new WP_REST_Request('POST', '/mvs/v1/media/' . \$media_id . '/reactions');
\$req->set_param('reaction_type', 'like');
\$res = rest_do_request(\$req);
echo '[' . (\$res->get_status() === 200 ? 'OK' : 'FAIL') . '] Add reaction: ' . \$res->get_status() . PHP_EOL;

// Check stats updated
\$stats = \$wpdb->get_var(\$wpdb->prepare(\"SELECT reactions FROM {$wpdb->prefix}mvs_media_stats WHERE media_id = %d\", \$media_id));
echo '[' . (\$stats > 0 ? 'OK' : 'WARN') . '] Stats reactions: ' . \$stats . PHP_EOL;

// Toggle off (cleanup)
\$req2 = new WP_REST_Request('POST', '/mvs/v1/media/' . \$media_id . '/reactions');
\$req2->set_param('reaction_type', 'like');
rest_do_request(\$req2);
echo '[OK] Reaction toggled off (cleanup)' . PHP_EOL;
"
```

### 3.3 Comment flow (add + notification + stats)

```bash
wp eval "
global \$wpdb;
wp_set_current_user(1);
\$media_id = \$wpdb->get_var(\"SELECT ID FROM {$wpdb->posts} WHERE post_type='mvs_media' AND post_status='publish' LIMIT 1\");

\$req = new WP_REST_Request('POST', '/mvs/v1/media/' . \$media_id . '/comments');
\$req->set_param('content', 'QA test comment — delete me');
\$res = rest_do_request(\$req);
\$cid = \$res->get_data()['id'] ?? null;
echo '[' . (\$res->get_status() < 300 ? 'OK' : 'FAIL') . '] Add comment: status=' . \$res->get_status() . ' id=' . \$cid . PHP_EOL;

// Check stats
\$stats = \$wpdb->get_var(\$wpdb->prepare(\"SELECT comments FROM {$wpdb->prefix}mvs_media_stats WHERE media_id = %d\", \$media_id));
echo '[' . (\$stats > 0 ? 'OK' : 'WARN') . '] Stats comments: ' . \$stats . PHP_EOL;

// Delete comment (cleanup)
if (\$cid) {
  \$req2 = new WP_REST_Request('DELETE', '/mvs/v1/media/' . \$media_id . '/comments/' . \$cid);
  rest_do_request(\$req2);
  echo '[OK] Comment deleted (cleanup)' . PHP_EOL;
}
"
```

### 3.4 Follow flow (follow + notification + unfollow)

```bash
wp eval "
global \$wpdb;
wp_set_current_user(1);
\$target = \$wpdb->get_var(\"SELECT ID FROM {$wpdb->users} WHERE ID != 1 LIMIT 1\");

// Follow
\$req = new WP_REST_Request('POST', '/mvs/v1/users/' . \$target . '/follow');
\$res = rest_do_request(\$req);
\$data = \$res->get_data();
echo '[' . (\$data['following'] ? 'OK' : 'FAIL') . '] Follow user ' . \$target . ': following=' . (\$data['following'] ? 'true' : 'false') . PHP_EOL;

// Check DB
\$row = \$wpdb->get_var(\$wpdb->prepare(\"SELECT COUNT(*) FROM {$wpdb->prefix}mvs_follows WHERE follower_id=1 AND following_id=%d\", \$target));
echo '[' . (\$row > 0 ? 'OK' : 'FAIL') . '] Follow row in DB' . PHP_EOL;

// Check notification
\$notif = \$wpdb->get_var(\$wpdb->prepare(\"SELECT COUNT(*) FROM {$wpdb->prefix}mvs_notifications WHERE user_id=%d AND type='new_follower' AND actor_id=1\", \$target));
echo '[' . (\$notif > 0 ? 'OK' : 'WARN') . '] Notification created for target' . PHP_EOL;

// Unfollow (cleanup)
\$req2 = new WP_REST_Request('DELETE', '/mvs/v1/users/' . \$target . '/follow');
\$res2 = rest_do_request(\$req2);
echo '[' . (!\$res2->get_data()['following'] ? 'OK' : 'FAIL') . '] Unfollow: following=' . (\$res2->get_data()['following'] ? 'true' : 'false') . PHP_EOL;
"
```

### 3.5 Privacy enforcement

```bash
wp eval "
global \$wpdb;
\$media_id = \$wpdb->get_var(\"SELECT ID FROM {$wpdb->posts} WHERE post_type='mvs_media' AND post_status='publish' AND post_author=1 LIMIT 1\");
\$other = \$wpdb->get_var(\"SELECT ID FROM {$wpdb->users} WHERE ID != 1 LIMIT 1\");

// Set private
update_post_meta(\$media_id, '_mvs_privacy', 'private');
\$wpdb->update(\$wpdb->prefix.'mvs_media_index', ['privacy'=>'private'], ['media_id'=>\$media_id]);

// Test as other user
wp_set_current_user((int)\$other);
\$req = new WP_REST_Request('GET', '/mvs/v1/media/' . \$media_id);
\$res = rest_do_request(\$req);
echo '[' . (\$res->get_status() === 403 ? 'OK' : 'FAIL') . '] Other user blocked: ' . \$res->get_status() . PHP_EOL;

// Test as anonymous
wp_set_current_user(0);
\$req2 = new WP_REST_Request('GET', '/mvs/v1/media/' . \$media_id);
\$res2 = rest_do_request(\$req2);
echo '[' . (\$res2->get_status() === 403 ? 'OK' : 'FAIL') . '] Anonymous blocked: ' . \$res2->get_status() . PHP_EOL;

// Test as owner
wp_set_current_user(1);
\$req3 = new WP_REST_Request('GET', '/mvs/v1/media/' . \$media_id);
\$res3 = rest_do_request(\$req3);
echo '[' . (\$res3->get_status() === 200 ? 'OK' : 'FAIL') . '] Owner can view: ' . \$res3->get_status() . PHP_EOL;

// Restore
update_post_meta(\$media_id, '_mvs_privacy', 'public');
\$wpdb->update(\$wpdb->prefix.'mvs_media_index', ['privacy'=>'public'], ['media_id'=>\$media_id]);
echo '[OK] Restored to public' . PHP_EOL;
"
```

### 3.6 Block flow (block + feed filtering)

```bash
wp eval "
global \$wpdb;
wp_set_current_user(1);
\$other = \$wpdb->get_var(\"SELECT ID FROM {$wpdb->users} WHERE ID != 1 LIMIT 1\");

// Block
\$req = new WP_REST_Request('POST', '/mvs/v1/users/' . \$other . '/block');
\$res = rest_do_request(\$req);
echo '[' . (\$res->get_status() === 200 ? 'OK' : 'FAIL') . '] Block user ' . \$other . PHP_EOL;

// Check feed excludes blocked
\$req2 = new WP_REST_Request('GET', '/mvs/v1/media');
\$items = rest_do_request(\$req2)->get_data();
\$found = false;
foreach (\$items as \$item) {
  if (isset(\$item['author']) && (int)\$item['author'] === (int)\$other) { \$found = true; break; }
}
echo '[' . (!\$found ? 'OK' : 'FAIL') . '] Blocked user media hidden from feed' . PHP_EOL;

// Unblock (cleanup)
\$req3 = new WP_REST_Request('DELETE', '/mvs/v1/users/' . \$other . '/block');
rest_do_request(\$req3);
echo '[OK] Unblocked (cleanup)' . PHP_EOL;
"
```

### 3.7 Report flow

```bash
wp eval "
global \$wpdb;
wp_set_current_user(1);
\$media_id = \$wpdb->get_var(\"SELECT ID FROM {$wpdb->posts} WHERE post_type='mvs_media' AND post_status='publish' LIMIT 1\");

\$req = new WP_REST_Request('POST', '/mvs/v1/media/' . \$media_id . '/report');
\$req->set_param('reason', 'spam');
\$req->set_param('details', 'QA test report');
\$res = rest_do_request(\$req);
echo '[' . (\$res->get_status() < 300 ? 'OK' : 'FAIL') . '] Report media: ' . \$res->get_status() . PHP_EOL;

// Check DB
\$row = \$wpdb->get_row(\$wpdb->prepare(\"SELECT * FROM {$wpdb->prefix}mvs_reports WHERE target_id=%d ORDER BY id DESC LIMIT 1\", \$media_id));
echo '[' . (\$row ? 'OK' : 'FAIL') . '] Report in DB: status=' . (\$row->status ?? 'N/A') . PHP_EOL;

// Cleanup
if (\$row) { \$wpdb->delete(\$wpdb->prefix.'mvs_reports', ['id'=>\$row->id]); echo '[OK] Report cleaned up' . PHP_EOL; }
"
```

---

## Part 4: DM / Messaging (WP-CLI)

### 4.1 Create conversation + send message

```bash
wp eval "
global \$wpdb;
wp_set_current_user(1);
\$other = \$wpdb->get_var(\"SELECT ID FROM {$wpdb->users} WHERE ID != 1 LIMIT 1\");

// Create conversation
\$req = new WP_REST_Request('POST', '/mvs/v1/conversations');
\$req->set_param('recipient_id', (int)\$other);
\$res = rest_do_request(\$req);
\$conv = \$res->get_data();
\$conv_id = \$conv['id'] ?? null;
echo '[' . (\$conv_id ? 'OK' : 'FAIL') . '] Create conversation: id=' . \$conv_id . PHP_EOL;

if (\$conv_id) {
  // Send message
  \$req2 = new WP_REST_Request('POST', '/mvs/v1/conversations/' . \$conv_id . '/messages');
  \$req2->set_param('content', 'QA test message');
  \$res2 = rest_do_request(\$req2);
  \$msg_id = \$res2->get_data()['id'] ?? null;
  echo '[' . (\$msg_id ? 'OK' : 'FAIL') . '] Send message: id=' . \$msg_id . PHP_EOL;

  // Read as other user
  wp_set_current_user((int)\$other);
  \$req3 = new WP_REST_Request('GET', '/mvs/v1/conversations/' . \$conv_id . '/messages');
  \$res3 = rest_do_request(\$req3);
  \$msgs = \$res3->get_data();
  echo '[' . (count(\$msgs) > 0 ? 'OK' : 'FAIL') . '] Other user reads messages: count=' . count(\$msgs) . PHP_EOL;

  // Mark as read
  \$req4 = new WP_REST_Request('POST', '/mvs/v1/conversations/' . \$conv_id . '/read');
  \$res4 = rest_do_request(\$req4);
  echo '[' . (\$res4->get_status() === 200 ? 'OK' : 'FAIL') . '] Mark as read' . PHP_EOL;

  // Unread count
  wp_set_current_user(1);
  \$req5 = new WP_REST_Request('GET', '/mvs/v1/me/messages/unread-count');
  \$res5 = rest_do_request(\$req5);
  echo '[OK] Unread count: ' . json_encode(\$res5->get_data()) . PHP_EOL;
}
"
```

### 4.2 DM privacy (non-participant blocked)

```bash
wp eval "
global \$wpdb;
wp_set_current_user(1);
\$other = \$wpdb->get_var(\"SELECT ID FROM {$wpdb->users} WHERE ID != 1 LIMIT 1\");

// Get a conversation between user 1 and other
\$conv_id = \$wpdb->get_var(\$wpdb->prepare(
  \"SELECT cp1.conversation_id FROM {$wpdb->prefix}mvs_conversation_participants cp1
   INNER JOIN {$wpdb->prefix}mvs_conversation_participants cp2 ON cp1.conversation_id = cp2.conversation_id
   WHERE cp1.user_id = %d AND cp2.user_id = %d LIMIT 1\", 1, \$other
));

if (\$conv_id) {
  // Try to access as a third user who is NOT a participant
  \$third = \$wpdb->get_var(\"SELECT ID FROM {$wpdb->users} WHERE ID NOT IN (1, \$other) LIMIT 1\");
  if (\$third) {
    wp_set_current_user((int)\$third);
    \$req = new WP_REST_Request('GET', '/mvs/v1/conversations/' . \$conv_id . '/messages');
    \$res = rest_do_request(\$req);
    echo '[' . (\$res->get_status() === 403 ? 'OK' : 'FAIL') . '] Non-participant blocked: ' . \$res->get_status() . PHP_EOL;
  } else {
    echo '[SKIP] No third user to test with' . PHP_EOL;
  }
} else {
  echo '[SKIP] No conversation found' . PHP_EOL;
}
"
```

### 4.3 DM with blocked user

```bash
wp eval "
global \$wpdb;
wp_set_current_user(1);
\$other = \$wpdb->get_var(\"SELECT ID FROM {$wpdb->users} WHERE ID != 1 LIMIT 1\");

// Block the other user
\$container = WPMediaVerse\Core\Plugin::container();
if (\$container->has('report')) {
  \$svc = \$container->get('report');
  \$svc->block_user(1, (int)\$other);
  echo '[OK] Blocked user ' . \$other . PHP_EOL;

  // Try to create conversation
  \$req = new WP_REST_Request('POST', '/mvs/v1/conversations');
  \$req->set_param('recipient_id', (int)\$other);
  \$res = rest_do_request(\$req);
  echo '[' . (\$res->get_status() >= 400 ? 'OK' : 'FAIL') . '] Create convo with blocked user: ' . \$res->get_status() . PHP_EOL;

  // Unblock (cleanup)
  \$svc->unblock_user(1, (int)\$other);
  echo '[OK] Unblocked (cleanup)' . PHP_EOL;
} else {
  echo '[SKIP] Report service not registered' . PHP_EOL;
}
"
```

---

## Part 5: BuddyPress Integration (WP-CLI)

### 5.1 BP active check

```bash
wp eval "
echo 'BuddyPress active: ' . (function_exists('buddypress') ? 'YES v' . buddypress()->version : 'NO') . PHP_EOL;
echo 'BP Activity: ' . (bp_is_active('activity') ? 'YES' : 'NO') . PHP_EOL;
echo 'BP Friends: ' . (bp_is_active('friends') ? 'YES' : 'NO') . PHP_EOL;
echo 'BP Messages: ' . (bp_is_active('messages') ? 'YES' : 'NO') . PHP_EOL;
echo 'BP Notifications: ' . (bp_is_active('notifications') ? 'YES' : 'NO') . PHP_EOL;
echo 'BP Groups: ' . (bp_is_active('groups') ? 'YES' : 'NO') . PHP_EOL;
"
```

### 5.2 BP activity created on media upload

```bash
wp eval "
global \$wpdb;
// Check if recent mvs_media uploads generated BP activity
\$activities = \$wpdb->get_results(\"SELECT id, type, content, date_recorded FROM {$wpdb->prefix}bp_activity WHERE component='wpmediaverse' ORDER BY id DESC LIMIT 5\");
echo 'BP activities (wpmediaverse component): ' . count(\$activities) . PHP_EOL;
foreach (\$activities as \$a) {
  echo '  [' . \$a->id . '] type=' . \$a->type . ' date=' . \$a->date_recorded . PHP_EOL;
}
"
```

### 5.3 BP notifications sync

```bash
wp eval "
global \$wpdb;
\$notifs = \$wpdb->get_results(\"SELECT id, component_name, component_action, date_notified FROM {$wpdb->prefix}bp_notifications WHERE component_name='wpmediaverse' ORDER BY id DESC LIMIT 5\");
echo 'BP notifications (wpmediaverse): ' . count(\$notifs) . PHP_EOL;
foreach (\$notifs as \$n) {
  echo '  [' . \$n->id . '] action=' . \$n->component_action . ' date=' . \$n->date_notified . PHP_EOL;
}
"
```

---

## Part 6: Pro Plugin Features (WP-CLI)

### 6.1 Pro active + version check

```bash
wp eval "
echo 'Pro active: ' . (defined('MVS_PRO_VERSION') ? 'YES v' . MVS_PRO_VERSION : 'NO') . PHP_EOL;
echo 'Free version: ' . (defined('MVS_VERSION') ? MVS_VERSION : 'N/A') . PHP_EOL;
"
```

### 6.2 Quota system

```bash
wp eval "
wp_set_current_user(1);
\$req = new WP_REST_Request('GET', '/mvs-pro/v1/me/quota');
\$res = rest_do_request(\$req);
echo '[' . (\$res->get_status() === 200 ? 'OK' : 'FAIL') . '] Quota endpoint: ' . \$res->get_status() . PHP_EOL;
\$data = \$res->get_data();
echo '  Package: ' . (\$data['package']['name'] ?? 'N/A') . PHP_EOL;
echo '  Images: ' . (\$data['usage']['image'] ?? 0) . '/' . (\$data['limits']['image'] ?? 'unlimited') . PHP_EOL;
"
```

### 6.3 Video transcoding config

```bash
wp eval "
wp_set_current_user(1);
\$req = new WP_REST_Request('GET', '/mvs-pro/v1/transcode/config');
\$res = rest_do_request(\$req);
echo '[' . (\$res->get_status() === 200 ? 'OK' : 'FAIL') . '] Transcode config: ' . \$res->get_status() . PHP_EOL;
\$data = \$res->get_data();
echo '  FFmpeg: ' . (\$data['ffmpeg_version'] ?? 'N/A') . PHP_EOL;
echo '  Presets: ' . implode(', ', array_keys(\$data['presets'] ?? [])) . PHP_EOL;
"
```

### 6.4 Analytics overview

```bash
wp eval "
wp_set_current_user(1);
\$req = new WP_REST_Request('GET', '/mvs-pro/v1/analytics/overview');
\$res = rest_do_request(\$req);
echo '[' . (\$res->get_status() === 200 ? 'OK' : 'FAIL') . '] Analytics: ' . \$res->get_status() . PHP_EOL;
echo '  ' . json_encode(\$res->get_data()) . PHP_EOL;
"
```

---

## Part 7: Browser Tests (Playwright)

### Test matrix

Run ALL URLs in both states:
- **With BP**: BuddyPress active (default on wb-media.local)
- **Without BP**: `wp plugin deactivate buddypress`, test, then `wp plugin activate buddypress`

### 7.1 Admin pages

| # | URL | Check |
|---|-----|-------|
| A1 | `/wp-admin/edit.php?post_type=mvs_media&page=mvs-overview&autologin=1` | Overview loads, stats cards visible, welcome banner |
| A2 | `/wp-admin/edit.php?post_type=mvs_media&page=mvs-settings` | All 5 tabs load, Save button on each |
| A3 | `/wp-admin/edit.php?post_type=mvs_media&page=mvs-moderation` | Queue loads, status cards visible |
| A4 | `/wp-admin/edit.php?post_type=mvs_media&page=mvs-stats` | Stats load, date range, CSV button |
| A5 | `/wp-admin/edit.php?post_type=mvs_media&page=mvs-logs` | Logs load, filter dropdowns, clear button |
| A6 | `/wp-admin/edit.php?post_type=mvs_media` | Media list: Thumb, Type, Privacy, Status columns visible |

### 7.2 Frontend pages

| # | URL | Check |
|---|-----|-------|
| F1 | `/media/?autologin=1` | Explore grid, search bar, tag cloud, pagination |
| F2 | `/upload-media/` | Upload form, dropzone, privacy selector, title/desc fields |
| F3 | `/my-media/` | Dashboard, profile header, tabs (My Media/Albums/Favorites) |
| F4 | Click any media item | Single page: image, reactions (6 types), comments, share, delete |
| F5 | `/media/@varundubey/` | Profile: avatar, name, bio, grid, follow button, follower counts |
| F6 | `/messages/` | Full-page DM: conversation list, chat area, composer |
| F7 | Chat trigger button (bottom-right on any page) | Slide-out panel opens, conversations list |

### 7.3 Social interactions (browser)

| # | Action | Check |
|---|--------|-------|
| S1 | Click heart/reaction on single media | Toggles active state, count updates |
| S2 | Add a comment | Appears in list, edit button visible (15-min window) |
| S3 | Click share button | Clipboard copied toast OR Web Share API opens |
| S4 | Click follow button on profile page | Button text changes, follower count updates |
| S5 | Click "New message" in DM | User search appears, can select user |
| S6 | Send a DM message | Message appears in conversation, sent indicator |

### 7.4 DM full flow (browser)

| # | Step | Check |
|---|------|-------|
| D1 | Navigate to `/messages/` | Page loads with All/Unread/Requests tabs |
| D2 | Click "New message" | User search field appears |
| D3 | Search for a user, select | New conversation created |
| D4 | Type message, send | Message bubble appears with timestamp |
| D5 | Switch to other tab (Unread) | Only unread conversations shown |
| D6 | Open a conversation | Messages load, read receipt updates |

### 7.5 Without BuddyPress

```bash
# Deactivate BP
wp plugin deactivate buddypress

# Run browser tests F1-F7, S1-S6, D1-D6 again
# All should still work — standalone mode

# Reactivate BP
wp plugin activate buddypress
```

| # | Check | Expected without BP |
|---|-------|-------------------|
| NB1 | Explore page | Works (no BP activity tab) |
| NB2 | Follow system | Works (native mvs_follows) |
| NB3 | Notifications | Works (native mvs_notifications) |
| NB4 | DM/Messages | Works (native messaging engine) |
| NB5 | User profiles (`/media/@username/`) | Works (native route) |
| NB6 | Single media reactions/comments | Works (no change) |
| NB7 | Admin pages | Works (no BP sections) |

---

## Part 8: PHP Syntax & Code Quality

### 8.1 PHP lint all files

```bash
find /Users/varundubey/Local\ Sites/wb-media/app/public/wp-content/plugins/wpmediaverse -name "*.php" -not -path "*/vendor/*" -exec php -l {} \; 2>&1 | grep -i "error"
```

**Expected:** Zero errors.

### 8.2 PHP lint Pro

```bash
find /Users/varundubey/Local\ Sites/wb-media/app/public/wp-content/plugins/wpmediaverse-pro -name "*.php" -not -path "*/vendor/*" -exec php -l {} \; 2>&1 | grep -i "error"
```

### 8.3 Build check

```bash
cd /Users/varundubey/Local\ Sites/wb-media/app/public/wp-content/plugins/wpmediaverse && npm run build 2>&1 | tail -5
```

---

## Scoring

After running all tests, tally results:

| Category | Tests | OK | FAIL | WARN |
|----------|-------|----|----|------|
| Part 1: Database | 3 | | | |
| Part 2: Permissions | 2 | | | |
| Part 3: Data Flow | 7 | | | |
| Part 4: DM | 3 | | | |
| Part 5: BuddyPress | 3 | | | |
| Part 6: Pro | 4 | | | |
| Part 7: Browser | 25 | | | |
| Part 8: Code Quality | 3 | | | |
| **TOTAL** | **50** | | | |

**Release gate:** Zero FAIL. WARN items documented but non-blocking.
