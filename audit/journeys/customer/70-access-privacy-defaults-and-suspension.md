---
journey: access-privacy-defaults-and-suspension
plugin: wpmediaverse
priority: high
roles: [subscriber, administrator]
covers: [MV-ACC-002, MV-ACC-003, default-privacy, allow-user-privacy]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "dev-auto-login mu-plugin installed"
  - "An album owned by Member A, for the consistency check"
estimated_runtime_minutes: 5
---

# Default upload privacy and the site-wide "let members choose" lock

## Setup

- Member A (`?autologin=<memberA>`), owns at least one album.

## Steps

### 1. Default privacy for new uploads (MV-ACC-002)
- **Action**: `wp option update mvs_default_privacy private`; as Member A, upload a new item WITHOUT touching the privacy field (if it's even shown).
- **Expect**: the new item's privacy is `private` — inherits the site default.
- **Action**: repeat with `mvs_default_privacy` set to `members` and then `public`.
- **Expect**: each upload inherits the currently-set default.
- **Action**: with `mvs_allow_user_privacy` ON, upload while EXPLICITLY choosing a different privacy than the default.
- **Expect**: the member's explicit choice wins over the default.
- **Action**: check `mvs_default_privacy`'s OWN default value on a fresh install that was set up as a private community at activation, vs. one that wasn't.
- **Expect**: `PrivateCommunityDefault::default_privacy()` derives a different baseline depending on that activation choice — report which value ships in each case if testable.

### 2. Allow Users to Set Privacy — one rule, read everywhere (MV-ACC-003)
- **Action**: `wp option update mvs_allow_user_privacy 0`. As Member A, open the upload modal, the edit-media modal, and the album create/edit modal.
- **Expect**: the Privacy field is ABSENT from ALL THREE surfaces — consistently, not shown-then-doomed on one and hidden on another.
- **Action**: if BuddyPress is active, open the activity composer.
- **Expect**: its privacy picker is ALSO absent for the same reason (one rule read by every surface as of 2.5.1).
- **Action**: with the setting still off, attempt to CHANGE an existing item's privacy via a direct REST call: `PUT /mvs/v1/media/{id}` with a different `privacy` value.
- **Expect**: `403 mvs_privacy_locked`.
- **Action**: resend the SAME (unchanged) privacy value for that item.
- **Expect**: succeeds as a no-op (edit screens submit the current value on every save, so this must not spuriously fail).
- **Action**: as an admin (`manage_mvs_settings`), attempt the same genuine privacy change.
- **Expect**: succeeds — admins are exempt from the lock everywhere this rule applies.
- **Action**: restore `mvs_allow_user_privacy` to 1.

## Pass criteria

ALL of the following hold:
1. A new upload inherits the currently-set `mvs_default_privacy` unless the member explicitly overrides it (and is allowed to).
2. With `mvs_allow_user_privacy` off, the Privacy field disappears consistently from the upload modal, edit modal, album modal, AND the BuddyPress activity composer (when active) — no surface left showing an interactive-but-doomed field.
3. A genuine privacy CHANGE via the API is refused (`403 mvs_privacy_locked`) for a plain member while the lock is on; resubmitting the unchanged value succeeds; an admin is exempt.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| New upload ignores `mvs_default_privacy` | default not read at create time | `includes/Services/UploadService.php` |
| Privacy field still shown (even if disabled) on one of the four surfaces while the others hide it | that surface not yet migrated to the shared 2.5.1 single-rule check | the surface's own render condition (upload modal / edit modal / album modal / BP composer) |
| Resubmitting an unchanged privacy value 403s | the lock check comparing against the wrong "before" value, or not comparing at all | `includes/REST/Controller/MediaController.php::update_item()` |
| Admin is also blocked by the lock | `manage_mvs_settings` exemption missing from the check | `includes/REST/Controller/MediaController.php` |
