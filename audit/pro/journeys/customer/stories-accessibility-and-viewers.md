---
journey: stories-accessibility-and-viewers
plugin: wpmediaverse-pro
priority: high
roles: [member]
covers: [MV-STY-008, MV-STY-009, stories, accessibility, focus-trap]
prerequisites:
  - "Both plugins active; mvs_stories_enabled = 1"
  - "At least two active stories to test prev/next navigation"
  - "A story with at least one distinct non-owner viewer"
estimated_runtime_minutes: 6
---

# The story viewer traps focus and closes on Escape from inside native controls; "seen by" is owner-only and privacy-preserving

**Why this journey exists**: the fullscreen story viewer is a real dialog, so it
needs the same focus-trap/keyboard contract as any other modal in this plugin,
with one added wrinkle — native `<video>`/`<audio>` controls swallow Escape and
reroute it to seeking, so the close handler is deliberately bound in the
CAPTURE phase to intercept it first. Separately, `GET /stories/{id}/viewers`
uses a 404-vs-403 split specifically so a refusal never confirms whether a
private story exists — the same privacy-preserving pattern used elsewhere in
this plugin, and worth locking here since it's easy to collapse into a single
generic error.

## Setup

- Site: `$SITE_URL`; two members: story owner and a non-owner viewer.
- At least two active stories for the same author (navigation test).

## Steps

### 1. Tab wraps inside the dialog only
- **Action**: open a story with keyboard/mouse; press Tab repeatedly.
- **Expect**: focus cycles between the first and last focusable control inside the dialog only — never escapes to the underlying page.

### 2. Focus lands on Close once the dialog is actually visible
- **Action**: open a story, observe where focus lands immediately.
- **Expect**: focus moves to the Close button only after the `hidden` attribute is removed (dialog visible) — not before.

### 3. Escape closes from anywhere, including inside native media controls
- **Action**: open a video/audio story; click into the native controls (e.g. the scrubber); press Escape while focus is inside them.
- **Expect**: viewer closes immediately — the capture-phase listener intercepts Escape before native controls reroute it to seeking. Test on Chrome AND Firefox/Safari specifically, since native keyboard handling differs by browser.
- **On fail**: the story viewer's Escape handler — check it's bound with `capture: true`.

### 4. Focus returns to the exact control that opened the viewer
- **Action**: open a story from a specific avatar in the bar; close it (Escape or Close button).
- **Expect**: focus returns to that same story-bar avatar/button — never left dangling on a removed element, never reset to `<body>`.

### 5. ArrowLeft/ArrowRight navigate; direction flips under RTL
- **Action**: with two+ active stories, press ArrowRight then ArrowLeft; repeat in an RTL locale.
- **Expect**: steps to next/previous story correctly; direction is flipped under RTL (ArrowRight goes to the previous story, ArrowLeft to next, or the equivalent RTL-correct mapping).

### 6. Close/prev/next controls carry accessible labels
- **Action**: inspect the dialog's Close, Previous and Next controls.
- **Expect**: Close labeled "Close stories"; chevrons labeled "Previous story"/"Next story" for screen readers.

### 7. Owner requests the viewers list — full paginated result
- **Action**: as the story's author, `GET /mvs-pro/v1/stories/{id}/viewers`.
- **Expect**: paginated list (`viewers`, `total`, `X-WP-Total`/`X-WP-TotalPages`), distinct viewers most-recently-viewed first, excluding the author's own views.

### 8. Non-owner who CAN see the media — ownership refusal (403), not privacy refusal
- **Action**: as a different logged-in member who CAN otherwise see the media, request the same endpoint.
- **Expect**: 403 "You can only manage your own stories." — a clear ownership message, distinguishable from the privacy case.

### 9. Anyone who cannot see the media at all — privacy refusal (404), indistinguishable from a nonexistent id
- **Action**: as a logged-in member with no access to the underlying media (or logged out entirely), request the same endpoint.
- **Expect**: 404 "Media not found." if logged in and unable to see it; 401 "You must be logged in." if logged out. Both must be indistinguishable from a genuinely nonexistent story id — never confirm existence through the shape of the refusal.
- **On fail**: `includes/Stories/*Controller` viewers-route permission callback — check the 403-vs-404 branch order.

### 10. A re-posted story starts its viewer count at zero
- **Action**: have a member view story A; let it expire; have the same author post a NEW story B; check B's viewer list immediately.
- **Expect**: B's "seen by" is empty — windowed by `story_started_at`, does not inherit A's viewer list.

## Pass criteria

1. Tab wraps inside the dialog only; initial focus lands on Close only once visible; focus returns to the opening control on close.
2. Escape closes the viewer from anywhere, including from inside native media controls, on at least two different browser engines.
3. Arrow keys navigate correctly and flip direction under RTL.
4. Owner gets the full paginated viewers list excluding their own views; a non-owner who can see the media gets 403; anyone who cannot see the media (logged in or not) gets an indistinguishable refusal (404/401) that never confirms existence.
5. A new story never inherits a prior story's viewer list.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Escape doesn't close while focus is in native video controls | listener not bound in capture phase | story viewer's keydown handler |
| Focus escapes the dialog on Tab | focus-trap boundary miscalculated | story viewer focus-trap logic |
| Non-owner-who-can't-see gets 403 instead of 404 | branch order wrong, confirms existence | viewers-route permission callback |
| New story shows old viewers | query not filtered by `story_started_at` | `includes/Stories/*Service` viewers query |
