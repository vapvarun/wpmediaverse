---
journey: media-accessibility
plugin: wpmediaverse
priority: high
roles: [subscriber]
covers: [MV-MED-016, MV-MED-017, reaction-a11y, confirm-dialog-focus-trap]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "dev-auto-login mu-plugin installed"
  - "A public media item with at least one existing comment; keyboard/screen-reader-style testing (assert on DOM attributes, not a live AT)"
estimated_runtime_minutes: 5
---

# The six-reaction bar and every destructive confirm dialog are fully keyboard/screen-reader accessible

**Why this journey exists**: two locked a11y regression specs (2026-05-03 pass) live in the reaction bar and the shared confirm-dialog component. Both are asserted here rather than assumed, per the plugin's own rule that any drift is a regression, not a stylistic choice.

## Setup

- Member A (`?autologin=<memberA>`), a public media item `$MEDIA_ID` owned by someone else, with an existing comment from a third member.

## Steps

### 1. Reaction bar group semantics (MV-MED-016)
- **Action**: `playwright_navigate $SITE_URL/media/{slug}/`; inspect `.mvs-reactions`.
- **Expect**: the wrapper carries `role="group" aria-label="Reactions"`. Each of the 6 reaction buttons carries a sentence-form `aria-label` (e.g. "Love this", not the bare word "Love" or the emoji), `aria-pressed` reflecting its toggle state, and the emoji glyph itself is `aria-hidden="true"` (verified via `getComputedStyle`/DOM inspection that the accessible name comes from `aria-label`, not the glyph text).
- **Action**: Tab through the six buttons; press Enter/Space on one.
- **Expect**: each button is reachable via Tab in document order; activating one toggles `aria-pressed` from `false` to `true` and applies `.active`.

### 2. Delete-comment confirm: Cancel is focused, never the destructive button (MV-MED-017)
- **Action**: as the comment owner (or a moderator), click Delete on a comment.
- **Expect**: `document.activeElement` matches `.mvs-confirm-overlay:not([hidden]) .mvs-confirm-cancel` immediately after the dialog opens — NOT the Confirm/destructive button. This must hold on first render, not just after a manual Tab.

### 3. Tab wraps forward, Shift+Tab wraps backward (the half people forget)
- **Action**: with the dialog open, press Tab repeatedly past the last focusable element inside it.
- **Expect**: focus wraps to the FIRST focusable element inside the dialog — it never escapes into the page behind (`dialogTrapTab()` in `src/blocks/shared-ui/view.js`).
- **Action**: from the first element, press Shift+Tab.
- **Expect**: focus wraps to the LAST focusable element inside the dialog. This is the branch most tests skip — verify explicitly, not just forward Tab.

### 4. Esc closes and returns focus to the opener
- **Action**: press Esc while the confirm dialog is open.
- **Expect**: dialog closes; focus returns to the button that opened it (the comment's Delete control), not to `<body>` or lost entirely.

### 5. Same pattern on the Report dialog and the Delete-media dialog
- **Action**: repeat steps 2-4 on the Report-media confirm and the Delete-media confirm.
- **Expect**: identical behavior — Cancel-focused, Tab/Shift+Tab wrap, Esc returns focus to the opener. This is one shared component (`shared-ui`), so a fix or regression in one surface should reproduce identically on all three; if any surface diverges, that itself is the finding.

## Pass criteria

ALL of the following hold:
1. `.mvs-reactions` has `role="group" aria-label="Reactions"`; every reaction button has a sentence-form `aria-label`, correct `aria-pressed`, and an `aria-hidden` glyph.
2. Every reaction is Tab-reachable and Enter/Space-activatable.
3. Cancel has initial focus on every destructive/report confirm dialog tested (delete comment, delete media, report media) — never the dangerous button.
4. Tab wraps forward and Shift+Tab wraps backward inside the dialog; neither ever escapes to the page behind.
5. Esc closes the dialog and returns focus to the control that opened it.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Reaction button's accessible name is the bare emoji | `aria-hidden` missing on the glyph span, or `aria-label` removed | `templates/media-single.php` (reaction bar markup) |
| Confirm button has initial focus instead of Cancel | the `requestAnimationFrame` focus call in `showConfirm()` targets the wrong selector or was removed | `src/blocks/shared-ui/view.js::showConfirm()` |
| Tab escapes the dialog into the page | `dialogTrapTab()` not bound to the dialog's keydown, or `dialogFocusables()` returns an empty/stale list | `src/blocks/shared-ui/view.js::dialogTrapTab()` |
| Shift+Tab does not wrap to the last element | the `event.shiftKey` branch in `dialogTrapTab()` regressed | `src/blocks/shared-ui/view.js::dialogTrapTab()` |
| Esc leaves focus on `<body>` | opener-element reference not stored/restored on close | `src/blocks/shared-ui/view.js` (confirm close handler) |
