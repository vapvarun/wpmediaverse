---
journey: member-moderation
plugin: wpmediaverse
priority: normal
roles: [administrator]
covers: [MV-ADM-016, member-storage-limit-override]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "Auto-login mu-plugin available (?autologin=1)"
  - "A second member account with some storage already used, and a nonzero site-wide storage limit (MV-SET-001) configured"
estimated_runtime_minutes: 4
---

# Owner overrides one member's storage limit from their profile edit screen

**Why this journey exists**: `includes/Admin/MemberModeration.php` adds a
per-member storage-limit override on the native wp-admin Edit User screen. This is
distinct from MV-ADM-015 (Suspend/Restore), which already has full coverage in
`security/06-blocked-member-cannot-interact.md` §4 — this file covers ONLY the
storage-limit field so the two aren't duplicated.

## Setup

- Site: `$SITE_URL`
- Admin: `?autologin=1`
- Target member: has already uploaded enough to have a nonzero storage figure.
- Site-wide limit (MV-SET-001): confirm it is set to a nonzero MB value so the override
  is observably different from the default.
- Edit screen: `$SITE_URL/wp-admin/user-edit.php?user_id={target_id}`

## Steps

### 1. Field shows the member's real current usage, not a placeholder
- **Action**: open the target member's Edit User screen; locate the "Storage limit for
  this member (MB)" field (`#mvs_storage_limit_mb`).
- **Expect**: with the field blank (no override set), the description reads "Leave
  blank to use the site limit, or 0 for no limit. Using %s now." with `%s` substituted
  by the member's ACTUAL current usage via `size_format()` — a real, non-zero number
  matching what that member has actually uploaded, not a static placeholder string.
- **On fail**: `includes/Admin/MemberModeration.php` (~L149-157) — check the usage
  value is queried live, not hardcoded or stale-cached.

### 2. Explicit override persists and applies only to this member
- **Action**: set an explicit MB value (e.g. half the site default) for the target
  member, save. Then check a SECOND member's effective limit (with no override set on
  them).
- **Expect**: target member's uploads are now capped at the explicit value —
  `wp usermeta get {target_id} mvs_storage_limit_mb` reflects it, and the second member
  is still capped at the unmodified site default (MV-SET-001), proving the override is
  scoped to one user, not global.
- **On fail**: `MemberModeration.php` save handler (writes `mvs_storage_limit_mb` user
  meta), `Services/StorageService.php` (limit resolution: user meta present → use it,
  absent → fall back to site option).

### 3. Explicit 0 means unlimited for that member only
- **Action**: set the field to `0` for the target member, save; attempt an upload that
  would exceed the SITE default limit as that member.
- **Expect**: upload succeeds (member is exempt); a second member with no override
  remains capped at the site default — confirm by attempting the same oversized upload
  as them and getting the expected storage-limit refusal.
- **On fail**: `StorageService.php` limit-check logic treating `0` the same as "unset"
  instead of "no limit" for that user.

### 4. Blank field reverts to the site default
- **Action**: clear the field back to blank for the target member, save.
- **Expect**: `mvs_storage_limit_mb` user meta is removed (or empty), and the member's
  effective limit reverts to the site-wide default (MV-SET-001).

## Pass criteria

ALL of the following hold:

1. The blank-field description shows the member's real, live current usage — never a placeholder.
2. An explicit MB override applies to exactly that member and does not affect any other member's effective limit.
3. An explicit `0` override means unlimited for that member specifically, while other members stay capped at the site default.
4. Clearing the override reverts the member to the site default.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Description shows a stale/placeholder usage number | Usage not queried live at render time | `includes/Admin/MemberModeration.php` |
| Setting one member's override changes another member's limit | Limit resolution reads the wrong user id, or writes to a site option instead of user meta | `MemberModeration.php` save handler, `Services/StorageService.php` |
| `0` override still caps the member | `0` treated as falsy/"unset" instead of "no limit" | `Services/StorageService.php` limit-check |
