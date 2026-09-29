---
journey: moderation-queue
plugin: wpmediaverse
priority: normal
roles: [administrator, subscriber]
covers: [MV-ADM-014, reports-admin-screen]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "Auto-login mu-plugin available (?autologin=1)"
  - "At least one member report in each status: Pending, Resolved, Dismissed"
  - "At least one report whose original reporter account has since been deleted"
estimated_runtime_minutes: 4
---

# Owner reviews the Reports admin screen — filter by status, resolve/dismiss/reopen

**Why this journey exists**: `includes/Admin/ReportsPage.php` is where an owner actually
acts on member abuse reports (MV-ADM-014). Note on scope: this file covers ONE catalog
ID only. An earlier pass of the coverage manifest's summary table claimed 3 IDs land
here; re-grepping the manifest's own per-area ADM table (not the summary) shows only
`MV-ADM-014` maps to `moderation-queue.md` — MV-ADM-012 (tabs) and MV-ADM-013
(approve/reject) both map to `admin/04-moderation-approve-flow.md` instead, which is
being extended separately. This file does not invent content for those two IDs.

## Setup

- Site: `$SITE_URL`
- Admin: `?autologin=1`
- Reports screen: `$SITE_URL/wp-admin/admin.php?page=mvs-reports` (only reachable via
  its own URL or the Moderation > Reports tab — `ReportsPage` self-removes from the
  sidebar on every other screen via `remove_submenu_page()`, per `ReportsPage.php`
  L112-114).

## Steps

### 1. Reports screen is reachable by direct URL even though it's not in the sidebar
- **Action**: navigate directly to `admin.php?page=mvs-reports` without going through
  Moderation.
- **Expect**: the page renders (bookmark still works) even though the submenu entry is
  removed on every screen except itself.
- **On fail**: `includes/Admin/ReportsPage.php::add_menu_page()`.

### 2. Filter by status tab
- **Action**: switch between Pending / Resolved / Dismissed tabs.
- **Expect**: each tab shows only reports in that status. Columns present: Reported
  (item), Reason, Details, Reported by, When, Action.

### 3. Deleted reporter degrades gracefully
- **Action**: view the report whose original reporter account was deleted.
- **Expect**: "Deleted member" shown in the Reported by column — not a broken user
  reference, not a fatal.

### 4. Resolve a pending report
- **Action**: on a Pending report, click "Resolve" (nonce
  `mvs_report_status_{report_id}`).
- **Expect**: notice "Report marked as resolved."; the report's status flips and it
  disappears from the Pending tab, appearing under Resolved.

### 5. Dismiss a pending report
- **Action**: on another Pending report, click "Dismiss".
- **Expect**: notice "Report dismissed."; item moves to the Dismissed tab.

### 6. Reopen a resolved/dismissed report
- **Action**: on a Resolved (or Dismissed) report, click "Reopen".
- **Expect**: notice "Report reopened."; item returns to the Pending tab. Action
  buttons are status-dependent throughout: Resolve/Dismiss shown only while pending,
  Reopen shown only once resolved/dismissed.

### 7. Empty status tab
- **Action**: view a status tab with zero reports (e.g. Dismissed on a fresh install).
- **Expect**: "Nothing here. No reports with this status." — not a bare empty table.

### 8. Capability-less visit is a 403, not a silent redirect
- **Action**: as the subscriber account, hit `admin.php?page=mvs-reports` directly.
- **Expect**: `wp_die()` 403 reading "You are not allowed to moderate reports." — the
  attempt must be visibly refused, not dropped via a redirect that looks like nothing
  happened.
- **On fail**: capability check in `ReportsPage::render_page()`.

### 9. Cross-reference note — full queue-view + resolve coverage lives in customer/25
- **Note only, no additional steps**: `customer/25-reports-enabled-by-default.md` §3
  already exercises the queue view + resolve path from the member-report-submission
  side. This journey is the admin-screen-focused complement (status filtering, reopen,
  deleted-reporter handling, capability gate) — do not duplicate §3's steps here.

## Pass criteria

ALL of the following hold:

1. The Reports screen is reachable by direct URL despite being absent from the sidebar elsewhere.
2. Status-tab filtering shows only reports in that status, with the documented columns.
3. A deleted reporter renders as "Deleted member", never a broken reference or fatal.
4. Resolve / Dismiss / Reopen each show their exact documented notice and move the report to the correct tab.
5. An empty status tab shows the documented "Nothing here" copy.
6. A capability-less direct visit is a `wp_die()` 403 with the documented message.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Reports page 404s or redirects when visited directly | `remove_submenu_page()` call also removed the page registration, not just the sidebar link | `includes/Admin/ReportsPage.php::add_menu_page()` |
| Deleted reporter shows a PHP notice / broken output | Missing null-check on the reporter user object | `ReportsPage.php` row-render |
| Resolve/Dismiss/Reopen notice text wrong or missing | Status-transition handler not wired to admin notices | `ReportsPage.php` action handler |
| Capability-less visit silently redirects | Guard uses `wp_safe_redirect()` instead of `wp_die()` | `ReportsPage.php::render_page()` |
