---
journey: settings-messages-tab
plugin: wpmediaverse
priority: high
roles: [administrator, member]
covers: [MV-SET-020, MV-SET-021, MV-SET-022, settings-messages-tab]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "Auto-login mu-plugin available (?autologin=1)"
  - "Two test members, A and B, who do not follow each other"
  - "A freshly-registered test account for MV-SET-022"
  - "WP-CLI + mysql_query access"
estimated_runtime_minutes: 8
---

# Messages tab settings persist and gate the DM engine

**Why this journey exists**: the Messages tab has 5 settings total (MV-SET-020 through 024), but two already have substantive coverage elsewhere and are deliberately NOT duplicated here: MV-SET-023 (Chat Panel Visibility) is covered in `admin/11-outbound-and-display-toggles.md` step 5, and MV-SET-024 (Online Status Visibility) is covered in `customer/05-dm-access-setting-persists.md` step 7. This file covers the remaining 3 — the master switch and the two access gates that decide whether a NEW conversation can even start.

**Manifest note**: `JOURNEY-COVERAGE-MANIFEST.md`'s dedup table claims 5 IDs for this file. The manifest's own per-area SET table routes MV-SET-023 to `admin/11-outbound-and-display-toggles.md` and MV-SET-024 to `customer/05-dm-access-setting-persists.md` as existing coverage, not to this file — so this file genuinely holds only 3 new IDs. The "5" in the dedup table is a manifest overcount, not a missing entry here.

## Setup

- Site: `$SITE_URL`
- Admin: `?autologin=1`
- Settings screen: `admin.php?page=mvs-settings-social` (labelled "Messages" in the sidebar).
- Two test members, A and B, with no follow relationship between them.
- A freshly-registered test account (for MV-SET-022's account-age gate).

## Steps

### 1. MV-SET-020 — Messages (master switch)
- **Action**: Turn off `mvs_messaging_enabled`; confirm `GET /mvs/v1/me/conversations` returns 404 (not 403), the chat panel/Message buttons/`/messages/` page are all absent. Turn it back on.
- **Assert**: this is a hard kill switch — `Core/Plugin::init_messaging()` skips booting the whole messaging engine when off, which is why the route 404s rather than refuses. Turning it back on preserves existing conversation history (not deleted) and GDPR export/erase still cover them. If BuddyNext is present, its own Messages entry point must also hide while this is off — no dangling menu item pointing at a dead feature.

### 2. MV-SET-021 — Who can send messages
- **Action**: Set `mvs_dm_access` to `followers`; as member A (no follow relationship with B), attempt to start a conversation with B. Repeat with `mutual` and `nobody`.
- **Assert**: a disallowed attempt under `followers`/`mutual` is refused with a specific, actionable message ("This member only accepts messages from people they follow"), not a generic 403 the caller has to guess at. Under `nobody`, existing conversations remain visible/readable — only NEW ones are blocked; this is a softer stop than MV-SET-020's hard kill.

### 3. MV-SET-022 — Minimum Account Age (messaging)
- **Action**: Set `mvs_dm_min_age` to `3` (days); as the freshly-registered test account, attempt to send a message. Then backdate the account's registration date (or wait) past 3 days and retry.
- **Assert**: the under-threshold attempt is refused with anti-spam framing visible to the member ("New accounts can send messages after N days"), not a raw permission error. After crossing the threshold, sending succeeds. Confirm this gate combines with MV-SET-021 — both must independently pass for the message to go through.

## Pass criteria

ALL hold:
1. `mvs_messaging_enabled`, `mvs_dm_access`, `mvs_dm_min_age` each persist to `wp_options` byte-for-byte after save + reload.
2. The master switch (MV-SET-020) is a genuine hard kill — routes 404, not merely 403, and no UI remnants render anywhere it's wired.
3. `mvs_dm_access` refusals under `followers`/`mutual`/`nobody` each show a specific, human-readable reason, and `nobody` never hides already-existing conversations.
4. `mvs_dm_min_age` refuses only accounts genuinely under the threshold, and combines correctly with `mvs_dm_access` (both gates must pass).

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Route returns 403 instead of 404 while master switch is off | Route still registered; `init_messaging()` guard incomplete | `includes/Core/Plugin.php::init_messaging()` |
| Chat panel/Message button still renders while master switch is off | Template/JS doesn't check `mvs_messaging_enabled` | `includes/Messaging/MessagingController.php`, frontend chat panel template |
| `followers`/`mutual` refusal is a generic 403 | Access-level check doesn't set a specific error code/message | `includes/Messaging/MessagingService.php` |
| `nobody` hides existing conversations instead of just blocking new ones | Access gate applied too broadly (to reads, not just conversation-start) | `includes/Messaging/MessagingService.php` |
| Account-age gate ignores real registration date | Age check uses wrong timestamp field | `includes/Messaging/MessagingService.php` |
| Setting reverts after reload | Duplicate `register_setting()` or sanitizer overwrite | `includes/Admin/Settings/MessagingSettingsRegistrar.php` |
