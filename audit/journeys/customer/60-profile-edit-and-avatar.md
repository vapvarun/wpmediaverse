---
journey: profile-edit-and-avatar
plugin: wpmediaverse
priority: high
roles: [subscriber]
covers: [MV-PRF-010, MV-PRF-011, MV-PRF-012, profile-edit-redirect, profile-fields, avatar-upload]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "dev-auto-login mu-plugin installed"
  - "A JPEG under 2MB and a file over 2MB (or a disallowed type) for the avatar step"
  - "A My Media dashboard page exists and is mapped"
estimated_runtime_minutes: 5
---

# Editing your profile redirects to the dashboard, fields save per-member, and avatar upload/remove work with their own hardcoded limits

## Setup

- Member A (`?autologin=<memberA>`).

## Steps

### 1. `/media/edit-profile/` redirects to the dashboard (MV-PRF-010)
- **Action**: logged in as Member A, `curl -sI -b cookies.txt $SITE_URL/media/edit-profile/`.
- **Expect**: HTTP 302 to the My Media dashboard's Edit Profile panel (`/my-media/profile/` or the mapped dashboard page's `/profile/`); landing there shows a working, PREFILLED edit form immediately — not an intermediate blank page.
- **Action**: log out; `curl -sI $SITE_URL/media/edit-profile/`.
- **Expect**: redirected to login with a `redirect_to` back to `/media/edit-profile/`.
- **Action**: `wp eval "add_filter('mvs_profile_edit_redirect', '__return_false');"` equivalent via a mu-plugin, or verify the filter exists; re-check the direct URL.
- **Expect**: with the filter false, the standalone `templates/profile-edit.php` page renders directly instead of redirecting.

### 2. Edit profile fields save per-member (MV-PRF-011)
- **Action**: in the Edit Profile panel, change First name, Last name, Display name, Bio, "Who can message you" (to a non-default value), "Show your online status" (toggle), "Email me about activity" (toggle); Save.
- **Expect**: `PUT /mvs/v1/me/profile` succeeds; success/error messages render INLINE in the form (`.mvs-profile-message--success`/`--error`) — NOT as a toast (this specific-to-this-form pattern differs from the rest of the plugin; confirm it in the browser). Save button shows a "Saving…" state while the request is in flight. Reload the panel and confirm every field persisted.
- **Action**: on a BuddyPress/BuddyNext-active site where a community plugin owns name fields.
- **Expect**: First/Last/Display name fields are hidden entirely from this panel (the copy "Fields a community plugin owns are edited there, not here" — verify their absence, not just a disabled state).

### 3. Avatar upload / remove (MV-PRF-012)
- **Action**: click "Change Avatar"; select a file OVER 2MB (or of a disallowed type).
- **Expect**: refused client-side or server-side (this 2MB / allowed-types check is hardcoded for avatars, NOT admin-configurable — do not look for a settings toggle).
- **Action**: select a valid JPEG under 2MB.
- **Expect**: "Uploading…" label swap while in flight; on success the avatar preview updates IMMEDIATELY, no stale image left showing; `POST /mvs/v1/me/avatar` returns 200.
- **Action**: click "Remove (use Gravatar)".
- **Expect**: `DELETE /mvs/v1/me/avatar` returns 200; the avatar reverts to the Gravatar fallback; the Remove button ITSELF disappears once there's no custom avatar left (it's only ever shown while a custom avatar exists) — no stale preview.

## Pass criteria

ALL of the following hold:
1. Logged-in `/media/edit-profile/` 302s to a prefilled dashboard panel; logged-out redirects to login with the correct `redirect_to`; the `mvs_profile_edit_redirect` filter restores the standalone page when set false.
2. All profile fields save and persist across reload; success/error surfaces inline in the form, not as a toast; community-owned name fields are hidden (not disabled) when a community plugin is active.
3. An oversize/disallowed avatar is refused; a valid one uploads with a loading-label swap and an immediate preview update; Remove reverts to Gravatar and hides its own button with no stale preview.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| `/media/edit-profile/` never redirects | `mvs_profile_edit_redirect` filter check removed, or the dashboard page mapping missing | `includes/Core/TemplateLoader.php` (edit-profile redirect branch) |
| Profile save shows a toast instead of inline message | this form diverged from the shared toast pattern without updating the spec, or a regression the other direction | `src/blocks/dashboard-view/view.js` (profile panel) |
| Name fields still editable with BuddyPress active | community-plugin detection missing from the field-render condition | `templates/partials/dashboard-content.php` or the profile panel template |
| Oversize avatar accepted | hardcoded 2MB/type check removed from `upload_avatar()` | `includes/REST/Controller/ProfileController.php::upload_avatar()` |
| Remove button still shown after reverting to Gravatar | button visibility not re-evaluated after successful remove | `src/blocks/dashboard-view/view.js` (avatar section) |
