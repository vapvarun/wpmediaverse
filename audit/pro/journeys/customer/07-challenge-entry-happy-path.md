---
journey: challenge-entry-happy-path
plugin: wpmediaverse-pro
priority: high
roles: [administrator, member]
covers: [challenges, entries, compete-hub]
prerequisites:
  - "mvs_challenges_enabled = 1"
  - "A member who owns at least one published image"
estimated_runtime_minutes: 6
---

# An owner runs a challenge and a member enters it in one pass

**Why this journey exists**: Challenges are the everyday engagement loop — the owner sets a theme and a deadline, members submit one photo, the community votes, a winner is announced. The whole loop has to work from the two surfaces people actually use: the admin's Photo Challenges screen and the member's challenge page. This journey walks it end to end so a regression in either half is caught before a site runs a challenge on it.

## Setup

- Site: `$SITE_URL`; admin `?autologin=admin`; member `?autologin=<member-with-photos>`
- Tables: `wp_mvs_competitions`, `wp_mvs_competition_entries`.

## Steps

### 1. Admin creates a challenge
- **Action**: MediaVerse → Competitions → Photo Challenges → create, with a title, theme, entry deadline (future) and voting end (later).
- **Expect**: saved with a success notice; row in `wp_mvs_competitions` with `type=challenge`, `status=active`; it appears in the admin list.
- **On fail**: `includes/Admin/ChallengeManager.php` (`admin_post` handlers).

### 2. It reaches the member surfaces
- **Action**: as the member, visit `/compete/` and `/media/challenges/`.
- **Expect**: the challenge shows on both, with entry count 0 and the deadline; the hub's Enter Challenge CTA points at the challenge page.

### 3. The member enters
- **Action**: open the challenge page, pick one of their photos, press Submit Entry.
- **Expect**: `POST /wp-json/mvs-pro/v1/challenges/{id}/entries` returns 201; the page confirms ("Entry submitted successfully") and the count becomes 1.
- **On fail**: `includes/Challenges/ChallengeController.php` (entries route), `templates/challenges-body.php`.

### 4. The entry limit holds
- **Action**: try to submit a second photo (`max_entries_per_user = 1`).
- **Expect**: refused with a readable message, not a silent failure; entry count stays 1.

### 5. A member with no photos is offered a way in
- **Action**: as a member who owns no images, open the same challenge.
- **Expect**: no empty picker with a dead Submit — an "Upload a new photo" path instead.

### 6. Admin sees the entry
- **Action**: back in Photo Challenges, open the challenge.
- **Expect**: entry count 1 and the entry listed.

### 7. Cleanup
- **Action**: delete the test entry and the test challenge.

## Pass criteria

1. A challenge created in admin appears on the hub and the challenges page with the right state.
2. A member can submit one entry and sees confirmation plus an updated count.
3. A second entry is refused with a readable reason.
4. A member with no photos gets an upload path, never a dead Submit.
5. The admin screen reflects the entry.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Challenge missing from hub | summary query / toggle | `includes/REST/CompeteSummaryController.php:181` |
| Submit does nothing | entries route or picker wiring | `includes/Challenges/ChallengeController.php`, `templates/challenges-body.php` |
| Second entry silently ignored | limit enforced without a message | `includes/Challenges/ChallengeService.php` |
