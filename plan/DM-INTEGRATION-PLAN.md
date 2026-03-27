# WPMediaVerse — DM Engine: BuddyNext Integration Plan

**Status:** Locked
**Date:** 2026-03-20
**Context:** BuddyNext community plugin is being built on top of WPMediaVerse. DM engine stays fully in WPMediaVerse. BuddyNext is UI layer only.

Full spec: `buddynext/docs/specs/WPMediaVerse-DM-Integration-Requirements.md`

---

## Architecture Decision

**WPMediaVerse owns DM completely — in standalone mode and in BuddyNext mode.**

WordPress `wp_users` is the same table in both modes. No user mapping, no migration, no BuddyNext dependency. The same `mvs_*` tables and codebase work identically whether BuddyNext is active or not.

| Mode | DM Engine | DM UI |
|------|-----------|-------|
| WPMediaVerse standalone | WPMediaVerse (owns everything) | WPMediaVerse chat panel + /messages/ page |
| WPMediaVerse + BuddyNext | WPMediaVerse (owns everything) | BuddyNext DM inbox (WPMediaVerse UI suppressed) |

---

## Work Required — Free

### 1. Move DM engine from Pro → Free

The full 1:1 DM engine currently lives in `WPMediaVersePro\Messaging`. Move to `WPMediaVerse\Messaging`:

| File | Action |
|------|--------|
| `MessagingService.php` | Move to free |
| `MessagingController.php` | Move to free — change REST namespace `mvs-pro/v1` → `mvs/v1` |
| `RestPollingTransport.php` | Move to free |
| `TransportInterface.php` | Move to free |
| `NotificationListener.php` | Move to free |
| `templates/messages.php` | Move to free |
| `templates/partials/chat-*.php` | Move to free |
| `assets/css/messaging.css` | Move to free |
| `assets/js/messaging.js` | Move to free |

### 2. Move 4 DB tables to Free Activator

Currently created by Pro Migrator. Move creation to `includes/Core/Activator.php` so they exist for all free users:

```
mvs_conversations             — id, type (direct|group), title, created_by,
                                last_message_id, last_message_preview,
                                last_activity_at, created_at

mvs_conversation_participants — conversation_id, user_id, role, status,
                                last_read_at, is_muted, muted_until,
                                is_pinned, is_archived, joined_at

mvs_messages                  — id, conversation_id, sender_id, content,
                                message_type, attachment_id, media_id,
                                parent_id, metadata, is_deleted,
                                deleted_for_all, created_at

mvs_message_reactions         — message_id, user_id, emoji, created_at
```

Note: `mvs_conversations.type` already supports `'direct'` and `'group'` — no schema change needed for Group DM.

### 3. Add 2 integration hooks for BuddyNext

**Hook 1 — `mvs_buddynext_active` filter**

Add in the chat panel template AND in `NotificationListener`:

```php
if ( apply_filters( 'mvs_buddynext_active', false ) ) {
    return; // BuddyNext handles UI and notifications
}
```

**Hook 2 — `mvs_can_send_message` filter**

Add inside `can_message()` after the existing block check:

```php
$allowed = apply_filters( 'mvs_can_send_message', true, $sender_id, $recipient_id );
if ( ! $allowed ) {
    return [ 'allowed' => false, 'reason' => 'blocked', 'is_request' => false ];
}
```

### Existing actions — no changes needed

These already fire correctly in `MessagingService.php`:

- `mvs_conversation_created( $conv_id, $creator_id, $participant_ids )`
- `mvs_message_sent( $message_id, $conversation_id, $sender_id, $recipient_ids )`
- `mvs_message_deleted( $message_id, $user_id, $deleted_for_all )`
- `mvs_conversation_read( $conversation_id, $user_id )`

---

## Work Required — Pro

### 1. Group DM

Schema already supports `mvs_conversations.type = 'group'`. Add:

**`Messaging\GroupMessagingService`**
```
create_group( $creator_id, $participant_ids, $name, $avatar_id )
add_member( $conversation_id, $user_id, $added_by )
remove_member( $conversation_id, $user_id, $removed_by )
update_group( $conversation_id, $data )          — name, avatar
transfer_admin( $conversation_id, $new_admin_id )
— on creator leave: auto-promote longest-remaining member to admin
```

**`Messaging\GroupMessagingController`** — REST at `mvs-pro/v1`

Limits: 2–49 participants. Inherits all free DM features (reactions, quoted replies, delete, rate limits).

New actions to fire:
```php
do_action( 'mvs_group_created', $conversation_id, $creator_id, $participant_ids );
do_action( 'mvs_group_member_added', $conversation_id, $user_id, $added_by );
do_action( 'mvs_group_member_removed', $conversation_id, $user_id, $removed_by );
```

### 2. Read Receipts

`last_read_at` on `mvs_conversation_participants` already tracks conversation-level reads. For per-message delivery status, store in `mvs_messages.metadata` JSON:

```json
{
  "delivery": {
    "read_by": [ { "user_id": 5, "read_at": "2026-03-20T10:01:00Z" } ]
  }
}
```

New REST endpoint: `GET mvs-pro/v1/messages/{id}/receipts`

New action: `do_action( 'mvs_message_read', $message_id, $user_id, $read_at )`

### 3. WebSocket Transport

Current transport: `RestPollingTransport` (5s polling).
Add: `WebSocketTransport` implementing `TransportInterface`.

`mvs_messaging_transport` filter (already exists) returns `WebSocketTransport` when configured.

Channel naming: `mvs-dm-{conversation_id}` (separate from BuddyNext Pro's feed channels).

BuddyNext Pro also runs WebSocket for feed + presence — prefer shared Soketi server, different channels.

---

## What BuddyNext Does (for reference)

BuddyNext bridge (`includes/Bridges/WPMediaVerse.php`):

1. Sets `mvs_buddynext_active` → `true` on `plugins_loaded:15`
2. Hooks `mvs_can_send_message` → checks `bn_blocks` table
3. Hooks `mvs_message_sent` → creates `bn_notifications` entry (`bn.new_message`)
4. Renders DM inbox UI using `mvs/v1/conversations` + `mvs/v1/messages` endpoints
5. Shows unread count badge in BuddyNext nav from WPMediaVerse unread count API

**BuddyNext has no own DM tables.** WPMediaVerse Pro is bundled in BuddyNext Pro.
