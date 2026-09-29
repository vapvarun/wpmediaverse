---
journey: media-report-and-moderation-visibility
plugin: wpmediaverse
priority: high
roles: [subscriber, administrator]
covers: [MV-MED-013, MV-MED-020, media-report, comment-moderation-visibility]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "dev-auto-login mu-plugin installed"
  - "A public media item owned by another member; a comment on it from a third member"
estimated_runtime_minutes: 5
---

# A member reports another member's media, and comment-delete visibility matches capability exactly

## Setup

- Owner: member A (`?autologin=<ownerA>`), owns `$MEDIA_ID`.
- Reporter: member B (`?autologin=<memberB>`), not the owner.
- Moderator: a user with `moderate_mvs_media` (or an admin).
- Confirm `mvs_enable_reports` is on (default): `wp option get mvs_enable_reports` (empty/unset also means enabled — `ReportService::reports_enabled()` defaults true).

## Steps

### 1. Owner never sees Report on their own item
- **Action**: as owner A, open `/media/{slug}/`.
- **Expect**: no Report control anywhere on the page — owners see Edit/Delete there instead.

### 2. A logged-out visitor never sees Report
- **Action**: view the same page logged out.
- **Expect**: no Report control (also no comment box — reporting and commenting both require login).

### 3. Member B reports the item
- **Action**: as member B, open Report; the reason picker appears inside the shared confirm-dialog component (Cancel-focused, Esc closes); pick "Spam"; submit.
- **Expect**: `POST /mvs/v1/media/$MEDIA_ID/report` returns 200/201; toast "Report submitted. Thank you."; `mysql_query "SELECT * FROM wp_mvs_reports WHERE target_id=$MEDIA_ID AND reporter_id=<memberB id>"` returns a row.

### 4. Duplicate report is refused, not silently re-filed
- **Action**: as member B, report the SAME item again.
- **Expect**: an error / "already reported" message; `mysql_query "SELECT COUNT(*) FROM wp_mvs_reports WHERE target_id=$MEDIA_ID AND reporter_id=<memberB id>"` still returns 1, not 2.

### 5. Auto-hide fires at the configured threshold
- **Action**: read `mvs_report_auto_hide_threshold` (`wp option get mvs_report_auto_hide_threshold`, default 3); have that many DISTINCT members report `$MEDIA_ID`.
- **Expect**: once the threshold is reached, the item is auto-hidden pending review — confirm it drops out of the public Explore grid and lands in the admin moderation queue.

### 6. Reports-disabled hides the control site-wide
- **Action**: `wp option update mvs_enable_reports 0`; reload `/media/{slug}/` as member B.
- **Expect**: Report control is absent; the direct REST call is refused. Restore: `wp option update mvs_enable_reports 1`.

### 7. Comment-delete visibility matches the server's own check (MV-MED-020)
- **Action**: have member C post a comment on `$MEDIA_ID`. As member B (a plain member, no `moderate_mvs_media`), view that comment.
- **Expect**: NO Delete affordance on member C's comment (only Edit/Delete on your own comments render at all, and even those only within the edit window for Edit — see customer/22 step 8).
- **Action**: as the moderator, view the same comment.
- **Expect**: a Delete control IS shown, and clicking it succeeds (`DELETE /mvs/v1/media/$MEDIA_ID/comments/{id}` returns 204) — the same `moderate_mvs_media` check gates both the visible button and the server route, so there is no case where the button is shown but the delete 403s, or hidden but the delete would have succeeded.

## Pass criteria

ALL of the following hold:
1. Report is never shown to the item's owner or to a logged-out visitor.
2. A member can report once; a second report on the same item by the same member is refused, not duplicated.
3. Reaching the configured report threshold auto-hides the item from the public grid.
4. Disabling reports site-wide removes the control and refuses the endpoint.
5. A plain member never sees a Delete control on someone else's comment; a moderator does, and the capability check on the button matches the server's exactly (no shown-but-403, no hidden-but-would-succeed).

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Owner sees Report on their own item | owner-exclusion check missing in the template/JS render condition | `templates/media-single.php`, `src/blocks/media-social/view.js` |
| Duplicate report accepted | missing unique-report guard | `includes/Social/ReportService.php::report()` |
| Auto-hide never fires at threshold | `mvs_report_auto_hide_threshold` not read, or the hide action not wired to the moderation queue | `includes/Social/ReportService.php`, `includes/Admin/ModerationQueue.php` |
| Reports-disabled option ignored | `ReportService::reports_enabled()` filter/option read regressed | `includes/Social/ReportService.php::reports_enabled()` |
| Plain member sees Delete on another's comment | `canModerateComments` / `hideDeleteComment` getter not checking `moderate_mvs_media` | `templates/media-single.php`, `src/blocks/media-social/view.js` |
