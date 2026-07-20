---
journey: reports-enabled-by-default
plugin: wpmediaverse
priority: critical
roles: [subscriber, administrator]
covers: [report, reports-default-on, safety, moderation-queue]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "A FRESH install state: no mu-plugin, theme, or site code filtering `mvs_reports_enabled`"
  - "One subscriber member, and one media item owned by somebody else"
estimated_runtime_minutes: 4
---

# A member can report abuse out of the box, and a moderator can act on it

**Why this journey exists**: This is the regression sentinel for the other half of the 2.1.0 safety incident. Reporting was **dead on every single install**, Free and Pro alike.

`mvs_reports_enabled` defaulted to `false`, and **no shipped code anywhere ever set it to true**. So `ReportController` refused every report with `403 mvs_reports_disabled`, and the Report button was hidden from every template — while Pro cheerfully rendered a "User Reports" admin queue that could never receive a single report. The read side and the write side were built and never connected. The only way to turn reporting on was for a site owner to hand-write `add_filter( 'mvs_reports_enabled', '__return_true' )` in a mu-plugin, which is not something a site owner would ever know to do. The free readme even claimed *"Report UI is Pro-only"* — but Pro never flipped the filter either, so the claim was simply false.

Nothing caught it. `wp mvs cert` passed **66/66** on a site where every report 403'd, because its boot check only dispatches **GET** and reporting is a `POST`. Its contract check needs a hand-written oracle, and the only oracle in the entire Free system is `mvs_allow_downloads` — a bad default on an option nobody thought to name is invisible to that system by construction. And no journey covered reporting at all.

A community whose members cannot report abuse is not safe to run, and the mobile app cannot pass App Store review without a working report path (guideline 1.2). **Reporting now defaults ON, and site owners opt _out_ in Settings rather than opting in via code.**

## Setup

- Confirm nothing is forcing the filter: `grep -rn "mvs_reports_enabled" wp-content/mu-plugins wp-content/themes` must return nothing.
- Confirm the option is unset (fresh-install state): `wp option delete mvs_enable_reports`
- Member: `wp user create journey_reporter journey_reporter@example.test --role=subscriber --user_pass=journey-pass`
- Note a `$MEDIA_ID` owned by somebody other than the reporter.

## Steps

### 1. Reporting works on a fresh install (as **member**)

1.1 `POST /mvs/v1/media/{MEDIA_ID}/report` with `{ "reason": "spam" }` → **200/201**. Must **not** be `403 mvs_reports_disabled`.
1.2 `POST /mvs/v1/users/{OTHER_USER_ID}/report` with `{ "reason": "harassment" }` → **200/201**.

### 2. The control exists in the UI, and works (browser, as **member**)

2.1 Open the media page. A **Report** control is visible to a non-owner.
2.2 Click it. A reason picker appears offering at least: spam, harassment, nudity, violence, copyright, misinformation, other.
2.3 Pick a reason and submit. A success confirmation appears ("Report submitted").

> On a site running the **BuddyNext** theme, MediaVerse's single-media permalink is redirected to the member's profile → Media tab, and BuddyNext's own lightbox has no Report control — so this step will fail there through no fault of the report path. That is tracked separately; run §2 on a standalone MediaVerse site.

### 3. It reaches a human (browser, as **administrator**)

3.1 Go to the reports queue — **User Reports** (Pro) or **Member Reports** (Free, shown when Pro is absent).
3.2 The report from §2 is listed as **Pending**, with the reporter, the target, and the reason.
3.3 Click **Resolve**. The status changes to *resolved* and the Pending count decreases.

This step is the point. A report that nobody can read is worse than no report button: it tells the member their report was sent, and it goes nowhere. Guideline 1.2 expects reports to be **acted on**, not merely collected.

### 4. The site owner can still turn it off

4.1 **Settings → AI & Moderation → Member Reporting** — the checkbox is present and **checked by default**.
4.2 Uncheck it and save.
4.3 As the member, `POST /mvs/v1/media/{MEDIA_ID}/report` → **403 `mvs_reports_disabled`**.
4.4 The Report control disappears from the media page.
4.5 Re-check the setting; reporting works again.

### 5. A blocked member can still report their blocker

Covered in depth by `security/06-blocked-member-cannot-interact.md` §2, and restated here because it is the single most important property of the report path: if blocking silenced reporting, an abuser could simply block their victim to suppress the complaint.

## Pass criteria

- §1: both report endpoints return 2xx on a fresh install with **no filter and no mu-plugin**.
- §2: the Report control is visible and completes a report.
- §3: the report appears in the moderator queue and can be resolved.
- §4: the owner's opt-out actually refuses reports (403) and hides the control; re-enabling restores both.

## Fail diagnostics

- **§1 returns `403 mvs_reports_disabled` on a fresh install** — the exact regression. Check `ReportService::reports_enabled()`: the option must default to **true**, and every gate (REST, service, all three templates) must read *that one helper* rather than re-deriving `apply_filters( 'mvs_reports_enabled', false )`. The original bug was four independent call sites all defaulting to false.
- **§1 passes but §2 shows no button** — a template is still re-deriving the flag with its own `false` default. Grep for `apply_filters( 'mvs_reports_enabled'` outside `ReportService`.
- **§2 passes but §3 shows nothing** — reports are being written but not read. Confirm the queue is registered: Pro's `Admin\ReportManager`, or Free's `Admin\ReportsPage` (which only registers when Pro is absent, so the two never collide).
- **§4's opt-out does not stick** — a boolean option that defaults `true` cannot be turned off by writing `false` to a row that does not exist yet. Verify through the real Settings form, not `update_option( $opt, false )` in isolation.
