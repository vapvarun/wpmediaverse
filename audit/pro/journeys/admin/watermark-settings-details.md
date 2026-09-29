---
journey: watermark-settings-details
plugin: wpmediaverse-pro
priority: high
roles: [administrator, member]
covers: [MV-WMK-002, MV-WMK-003, MV-WMK-004, MV-WMK-005, MV-WMK-008, watermark, silent-skip]
prerequisites:
  - "Both plugins active for the Pro steps; Free-only site available for MV-WMK-008"
  - "A GD-capable host; a PNG logo with transparency; a public user_nicename fixture"
estimated_runtime_minutes: 10
---

# Text tokens, logo, both-mode, position/opacity all draw correctly — and every silent-skip path leaves an unmarked upload with no error

**Why this journey exists**: `customer/23-watermark-stamped-at-upload.md` and
`24-watermark-ingest-paths.md` already prove watermarking is ON, per-role
scoped, and baked into stored files. This journey is specifically the
admin-settings DETAIL surface those don't reach — text tokens, logo, "both"
mode, every position/opacity combination — and the two highest-risk silent-skip
misconfigurations this feature has: forgetting to pick a logo while type is
"Image", and a 0% opacity slider. Both leave an upload completely unmarked with
NO error surfaced anywhere, which is the exact shape of bug a customer only
discovers by noticing their "protected" media isn't protected.

## Setup

- Site: `$SITE_URL`; admin `?autologin=admin`; member `?autologin=<member>` with `user_nicename = janedoe`.
- Settings > Storage tab, watermark fields.

## Steps

### 1. Text watermark with the `{username}` and `{site}` tokens
- **Action**: enable watermarking, type = Text, text = `{site} — {username}`; upload an image as `janedoe`; upload the same image as a different member.
- **Expect**: `janedoe`'s image renders `<Site Name> — @janedoe` — `{username}` resolves from `user_nicename`, NEVER `user_login` (deliberate, since `user_login` can be a private credential on a public image). The other member's upload shows their own nicename with no re-save needed — tokens resolve at stamp time.
- **On fail**: text-watermark token resolver (search `{username}` replacement, gated on `$user_id > 0`).

### 2. Font size scales proportionally, not as a literal pixel size
- **Action**: set an unusual font size (e.g. 80); re-upload.
- **Expect**: text scales proportionally to image width (calibrated for ~1000px-wide images) — not a fixed pixel size regardless of image dimensions.

### 3. Missing TrueType font degrades visibly smaller, silently
- **Action**: (if feasible) remove/rename the font path the `mvs_watermark_font_path` filter and common system paths would resolve to, forcing GD's fallback bitmap font; upload.
- **Expect**: text still renders, just via GD's tiny bitmap font ignoring the configured size — a real, documented gap in feedback (looks "wrong," not a total failure). Note it, don't file it as a crash.

### 4. Image (logo) watermark scales and blends correctly
- **Action**: type = "Image (logo)", select a PNG logo with transparency; upload.
- **Expect**: logo scaled to 20% of the base image's width (proportional height), alpha-blended at the configured opacity, placed per the configured position.

### 5. Logo type with NO logo selected — silent skip, the highest-risk misconfiguration in the feature
- **Action**: type = "Image (logo)", leave the logo attachment id at 0/unset (default); upload.
- **Expect**: upload stores COMPLETELY UN-WATERMARKED, with NO error surfaced anywhere — no admin notice for this specific combination (unlike MV-WMK-006's role-selection notice, which DOES warn). Flag this explicitly as the highest-risk silent gap in the whole feature.
- **On fail**: this is expected/documented behavior per the field description ("image watermarking is skipped") — do not file as a defect; DO flag in the report that no proactive notice exists here.

### 6. Tiled logo position
- **Action**: position = "Tiled"; upload.
- **Expect**: logo repeats across the entire canvas on a grid, ~40px gaps.

### 7. Corrupt/unsupported logo file fails the same silent-skip path
- **Action**: point the logo id at a corrupt image, then at an unsupported format (e.g. BMP/TIFF).
- **Expect**: both fail to load via `load_gd_image()` (only PNG/JPEG/GIF/WebP supported) and follow the same silent-skip — unmarked upload, no error.

### 8. Logo + text ("both") mode — layers never overlap by default
- **Action**: type = "both", position = bottom-right, both logo and text configured; upload.
- **Expect**: logo draws at bottom-right; text draws at the opposite corner (top-left) by default so the two never overlap.

### 9. "Both" with center/tile position falls back to bottom-left for text
- **Action**: type = "both", position = center or tile.
- **Expect**: text falls back to bottom-left (no natural "opposite corner" for center/tile) — visually check it doesn't collide with a tiled logo pattern.

### 10. "Both" with one layer removed — the other still stamps
- **Action**: type = "both", remove the logo (id=0) but keep valid text; upload.
- **Expect**: logo layer skipped, text layer draws successfully — either layer succeeding counts as a stamp, upload is marked watermarked.

### 11. Both layers failing — original stored unmarked, no error
- **Action**: type = "both" with both logo AND text configured to fail (e.g. bad logo + font unavailable, if reproducible).
- **Expect**: `$drawn` stays false, save skipped entirely, original stored un-watermarked, no error surfaced — same silent-skip pattern as steps 5/7.

### 12. Opacity 0% — enabled but functionally invisible, no warning
- **Action**: set opacity to 0; upload.
- **Expect**: mark IS drawn (counts as watermarked) but fully transparent — invisible to the eye, with no warning to an owner who dragged the slider to 0 by accident. Flag as a UX gap in the report, not a functional bug.

### 13. Opacity 100% uses the opaque codepath
- **Action**: set opacity to 100; upload.
- **Expect**: fully opaque; `imagecopy()` path used instead of `imagecopymerge()`.

### 14. All six positions place correctly
- **Action**: cycle through center/bottom-right/bottom-left/top-right/top-left/tile on a sample image.
- **Expect**: all six place per the documented margin logic (20px margin for logos, proportionally-scaled margin for text); tile repeats across the full canvas for both logo and text.

### 15. Free-only (no Pro) — fully configured watermark UI does absolutely nothing
- **Action**: on a site with Free active and Pro NOT active/installed, enable and fully configure watermarking; upload a matching image.
- **Expect**: image uploads completely un-watermarked. `WatermarkService::stamp_new_upload()` calls the `mvs_watermark_stamp_file` filter; with no Pro listener registered, the filter returns its default `false` unchanged, and since `has_filter()` is also false, NO error is logged either — the one case where failure is silent by design, not a Pro-active bug.
- **Report**: the Free settings screen shows a fully "enabled and configured" UI with no indication anywhere that it does nothing without Pro — check the Free settings copy and any feature-comparison page for language that could mislead a Free-only buyer.

## Pass criteria

1. Text tokens resolve from `user_nicename` (never `user_login`), scale proportionally, and degrade (not crash) without a system font.
2. Logo watermark scales/blends/tiles correctly; a missing or corrupt/unsupported logo silently skips with no error, exactly as documented.
3. "Both" mode places logo and text at non-overlapping default positions, falls back sensibly for center/tile, and stamps successfully if only one layer succeeds.
4. All six positions and both opacity extremes (0%, 100%) behave exactly as documented, including the 0%-invisible-no-warning gap.
5. Free-only (no Pro) leaves every upload completely unmarked with zero error, regardless of how fully the settings are configured.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| `{username}` resolves to `user_login` instead of `user_nicename` | wrong user field in the token resolver | text-watermark token resolution |
| Logo-type-no-logo produces an error instead of a silent skip | behavior changed without updating this journey/docs | logo-drawing path in the watermarker |
| Text and logo overlap in "both" mode at the default position | opposite-corner logic missing/wrong | "both" mode positioning logic |
| Free-only site actually gets a watermark | a Pro listener leaked into Free, or `has_filter()` check removed | `WatermarkService::stamp_new_upload()` |
| Opacity 0% produces a fully invisible AND unmarked (not-drawn) result | opacity=0 treated as disabling the stamp entirely instead of drawing transparently | opacity-to-alpha calc in the watermarker |
