---
journey: social-failure-paths
plugin: wpmediaverse
priority: high
roles: [member, anonymous]
covers: [social-failure-paths, comment-no-false-success, loadmore-error-not-end, upload-loggedout-cta]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "Auto-login mu-plugin available (?autologin=<login>)"
  - "At least 2 pages of public media exist (so Load More is shown)"
  - "A page containing the media-upload block, and a single-media page with comments enabled"
estimated_runtime_minutes: 5
---

# Social actions surface failures instead of faking success (regression sentinel)

**Why this journey exists**: 2026-06 fixes replaced silent/optimistic success with real
failure handling:
- Comment submit cleared the textarea unconditionally and ignored the REST result — a
  failed post lost the typed comment and looked successful. Now it checks `res.ok`, keeps
  the text + toasts on failure, has a logged-out guard, and an in-flight guard.
- "Load More" (both `load-more.js` and the explore-feed block) showed "You're all caught
  up!" / silently swallowed errors on a failed fetch. Now a network/server error shows a
  retry toast and keeps the control, instead of a false end-of-feed.
- The media-upload block rendered nothing for logged-out visitors. Now it shows a "Log in
  to upload" CTA.

## Steps

### 1. Comment failure keeps the text + shows an error (no false success)
- **Action**: as a logged-in member on a single-media page, type a comment, force `POST /wp-json/mvs/v1/media/{id}/comments` to fail, then submit.
- **Expect**: an error toast; the typed text is STILL in the textarea (not cleared); no new comment row.

### 2. Comment succeeds normally
- **Action**: restore the network, submit again.
- **Expect**: the comment posts, the textarea clears, the comment appears.

### 3. Logged-out comment is guarded
- **Action**: as anonymous, attempt to submit a comment.
- **Expect**: a "Please log in to comment" toast; no silent clear, no request that 401s unhandled.

### 4. Load More error shows retry, NOT "all caught up"
- **Action**: on a feed with >1 page, force the next `GET /wp-json/mvs/v1/media?page=2` to fail, click Load More.
- **Expect**: a "Couldn't load more. Tap to retry." toast; the button is re-enabled (not hidden); the "You're all caught up" end message is NOT shown.

### 5. Load More recovers
- **Action**: restore network, click Load More again.
- **Expect**: page 2 appends normally.

### 6. Logged-out upload block shows a CTA, not a blank gap
- **Action**: as anonymous, visit the page with the media-upload block.
- **Expect**: a "Log in to upload" empty state with a login link renders — not an empty gap.

## Pass criteria

ALL hold:
1. A failed comment keeps the typed text and surfaces an error (never a false "posted").
2. Anonymous comment attempts get a login prompt.
3. A failed Load More shows a retry affordance, never "You're all caught up".
4. The upload block shows a login CTA for logged-out visitors.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Comment textarea clears on failure | submitComment clears before checking res.ok | `src/blocks/media-social/view.js` (submitComment) |
| "You're all caught up" on a fetch error | error path calls showEnd() not showError() | `assets/js/frontend/load-more.js`; `src/blocks/explore-feed/view.js` |
| Upload block blank for logged-out | early return without a CTA | `src/blocks/media-upload/render.php` |
