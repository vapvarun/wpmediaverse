---
journey: user-deletion
plugin: wpmediaverse
priority: critical
roles: [administrator, subscriber]
covers: [MV-PRV-005, MV-PRV-006, MV-PRV-007, user-deletion-reassign, media-only-reassignment-choice, self-service-account-deletion]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "dev-auto-login mu-plugin installed"
  - "wp-admin Users > Delete access"
  - "Member A (has media, some in a shared team-drive context if Pro Documents is active), Member B (successor), Member C (media-only, zero native WP posts/comments)"
  - "A test member account with a known real password, for self-service deletion"
estimated_runtime_minutes: 8
---

# WordPress core's own user-delete screen correctly reassigns MediaVerse content, media-only members still get the reassignment choice, and members can delete their own account safely

## Setup

- Member A: owns several media items.
- Member B: the reassignment successor.
- Member C: has ONLY MediaVerse media, zero native WordPress posts/pages/comments of their own.
- Member D: a fresh test account with a real (non-SSO) password, for the self-service deletion step.

## Steps

### 1. "Attribute all content to" reassigns MediaVerse content correctly (MV-PRV-005)
- **Action**: as an admin, wp-admin → Users → All Users → Delete Member A, choosing "Attribute all content to" → Member B.
- **Expect**: `deleted_user` fires with `$reassign` carrying Member B's id exactly as WordPress core provides it; Member A's media now shows Member B as author (`post_author`/`mvs_media_index.post_author` updated); stats, reactions, and album membership on those items are PRESERVED, not reset.
- **Action**: if Member A had "team drive" media (Pro Documents shared-drive context), check its ownership after reassignment.
- **Expect**: team-drive media is reassigned in a FIRST phase to a filtered fallback successor (if the chosen target doesn't apply there), THEN everything else goes to Member B, the admin's explicit choice — verify team-drive items did not silently follow Member B if a fallback successor was more appropriate.
- **Action**: repeat with a second test member, this time choosing "Delete all content" instead of reassigning.
- **Expect**: their media cascades to deletion — for a heavy account, confirm this is BATCHED (multiple smaller queries), not one giant synchronous query that could time out.
- **Action**: attempt to reassign to a user ALSO being deleted in the same batch, and separately to a nonexistent user id.
- **Expect**: both refused/guarded against (`get_userdata($reassign)` check) — reassignment does not silently create rows pointing at a deleted or nonexistent user.

### 2. Media-only members still get the reassignment choice (MV-PRV-006)
- **Action**: as an admin, attempt to delete Member C (zero native posts/pages/comments, only MediaVerse media).
- **Expect**: WordPress STILL shows the "What should be done with content owned by this user?" reassignment section — confirm its presence explicitly; core alone (checking only native posts) would otherwise skip it entirely, silently offering no reassignment choice for a member who genuinely owns content.
- **Action**: give Member C exactly ONE media item, and set its status to TRASHED (not published).
- **Expect**: the reassignment section still appears — the "has additional content" check does not filter by media status at all; trashed, draft, pending, and published items all count equally.

### 3. Member self-service account deletion (MV-PRV-007)
- **Action**: as Member D, `GET /wp-json/mvs/v1/me/deletion` to check current status.
- **Expect**: no pending deletion.
- **Action**: `DELETE /mvs/v1/me/deletion` with the WRONG account password.
- **Expect**: refused with `mvs_invalid_password` — a specific, clear error, not a generic failure.
- **Action**: repeat with the CORRECT password.
- **Expect**: succeeds; `GET /me/deletion` now shows a pending deletion with a grace-period end date (`mvs_account_deletion_grace_days` filter, default value — read it rather than assuming a number). The account is suspended (browsable-only) during the wait.
- **Action**: BEFORE the request, note an Application Password issued to Member D and confirm it works (e.g. a simple authenticated GET). Immediately AFTER the deletion request, retry using that same Application Password.
- **Expect**: it no longer works — every Application Password and active session is revoked IMMEDIATELY on request, even though the account is not yet deleted (defends against a stolen-session deletion continuing to be usable).
- **Action**: before the grace period ends, sign in as Member D with the real password and cancel the pending deletion.
- **Expect**: the account returns to normal — no longer suspended, deletion no longer pending.
- **Action**: repeat the full flow (request deletion, do NOT cancel) with a `mvs_account_deletion_grace_days` filter forcing 0 days (owner-configured immediate deletion).
- **Expect**: deletion executes with NO waiting window.
- **Action**: request deletion again with a normal grace period, let it become due, then run/simulate the cron sweep (`AccountDeletionService::process_due()`).
- **Expect**: the account whose grace period has expired IS processed by the sweep.
- **Action**: request deletion, cancel it in time, THEN run the same cron sweep.
- **Expect**: the cancelled account is correctly EXCLUDED from the sweep — not accidentally caught by a race between cancellation and the cron run.

## Pass criteria

ALL of the following hold:
1. "Attribute all content to" correctly reassigns MediaVerse media/stats/reactions/album-membership to the chosen successor, honors the team-drive fallback-then-explicit-choice ordering, batches cascade-delete for a heavy account, and guards against reassigning to a deleted/nonexistent user.
2. A media-only member (even with only one trashed item) still triggers WordPress's own reassignment UI via the `users_have_additional_content` hook.
3. Self-service deletion requires the real account password (specific error on mismatch), enters a grace period (or deletes immediately at 0 days), revokes every Application Password/session immediately on request, can be cancelled with the real password before the window closes, and the cron sweep processes only genuinely-due, non-cancelled accounts.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Reassigned media keeps the old author | `deleted_user` hook not reading `$reassign` correctly, or reassignment query not covering `mvs_media_index` | `includes/Services/UserDeletionService.php::handle_user_deletion()` |
| Media-only member's delete screen shows no reassignment option | `users_have_additional_content` filter not hooked, or its check filtering by status | `includes/Services/UserDeletionService.php::users_have_media()` |
| Wrong password on self-deletion returns a generic error | specific `mvs_invalid_password` code dropped | `includes/Services/AccountDeletionService.php::request()` |
| An Application Password still works right after a deletion request | credential/session revocation not immediate | `includes/Services/AccountDeletionService.php::request()` |
| Cancelled account still gets deleted by the cron sweep | `process_due()` query not excluding cancelled/updated records, or a race on the status field | `includes/Services/AccountDeletionService.php::process_due()` |
