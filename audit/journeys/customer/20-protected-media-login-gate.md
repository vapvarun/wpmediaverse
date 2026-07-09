---
journey: protected-media-login-gate
plugin: wpmediaverse
priority: critical
roles: [author, subscriber, anonymous]
covers: [protected-media-gate, privacy-gate-single, login-url-buddynext]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "dev-auto-login mu-plugin installed"
  - "Two member accounts exist (owner + a second member)"
estimated_runtime_minutes: 6
---

# A members-only media page shows the SAME container with a login gate, never a 404

**Member expectation**: when a member marks media "Members only", the owner and
other logged-in members still see the media on `/media/{slug}/`; a logged-out
visitor sees the *same* single-media container with a "Log in to view" message
**where the image would be** — NOT a bare "not found" 404, and never the image
itself. The login link goes to the BuddyNext auth page when BuddyNext is active.

This journey guards the 2.0.0 protected-media gate (`TemplateLoader::serve_single_media`
+ `templates/media-single.php` `$mvs_can_view` branch + `TemplateHelpers::login_url`).
Regressions here either leak private media, 404 legit content, or send members to
`wp-login.php` instead of the community login.

## Setup

- Owner: member A (autologin `?autologin=<ownerA>`), owns one image `$MEDIA_ID` with slug `$SLUG`.
- Set it members-only through the real flow (fires the privacy hook):
  `restFetch('media/$MEDIA_ID', {method:'POST', body:{privacy:'members'}})` as owner A,
  or `mysql_query "UPDATE wp_mvs_media_index SET privacy='members' WHERE media_id=$MEDIA_ID"`.
- Second member: member B (`?autologin=<memberB>`), NOT the owner.

## Steps

### 1. Owner sees the real image
- **Action**: `playwright_navigate $SITE_URL/media/$SLUG/?autologin=<ownerA>`
- **Expect**: `.mvs-media-image img` present with `naturalWidth > 0`; NO `.mvs-media-gate`; social bar visible. HTTP 200.

### 2. Another logged-in member sees the image (members = any logged-in user)
- **Action**: `playwright_navigate $SITE_URL/media/$SLUG/?autologin=<memberB>`
- **Expect**: image renders; NO `.mvs-media-gate`.

### 3. Logged-out visitor gets the in-container gate, not a 404
- **Action**: log out (`/wp-login.php?action=logout` + nonce), then `playwright_navigate $SITE_URL/media/$SLUG/`
- **Expect**:
  - `.mvs-media-gate` IS present with title "This media is for members".
  - The media container still renders (`.mvs-single-media .mvs-media-article`) — it is NOT the branded 404 ("couldn't find that media").
  - NO `.mvs-media-image img` and NO `img[src*="/wpmediaverse/"]` in the DOM (image never emitted).
  - `.mvs-reactions` and `.mvs-comments-section` are absent (social hidden for the gated view).
  - HTTP status is 403 with `<meta name="robots" content="noindex,nofollow">`.

### 4. The gate login link routes to BuddyNext (not wp-login.php)
- **Action**: read `.mvs-media-gate__cta` href.
- **Expect**: href matches the BuddyNext auth page (contains `/login` or the configured auth slug, plus `redirect_to=<this media url>`), and does NOT contain `wp-login.php`. (When BuddyNext is inactive it may fall back to `wp_login_url()`; assert BN routing when `class_exists('\\BuddyNext\\Core\\PageRouter')`.)

### 5. No OG-image leak for the gated page
- **Action**: `curl -s $SITE_URL/media/$SLUG/ | grep -i 'og:image'` as anonymous.
- **Expect**: NO `og:image` / `twitter:image` meta for the members-only page (the OG block is skipped for denied viewers).

## Pass criteria

ALL hold:
1. Owner + other member see the real image (no gate).
2. Logged-out visitor sees the gate INSIDE the media container (not a 404, not the image).
3. No image URL, poster, `/wpmediaverse/` src, or OG image is emitted to the denied viewer.
4. HTTP 403 + noindex for the gated page.
5. Gate login link points to the BuddyNext auth page (with redirect_to), not wp-login.php.

## Fail diagnostics

- Visitor sees a 404 "couldn't find that media" → `TemplateLoader::serve_single_media` regressed to `render_branded_404` for the denied branch.
- Visitor sees the image / it leaks → `media-single.php` `$mvs_can_view` guard on `.mvs-media-content`, or `MediaUrl::file()`/`get_thumb_url()` returned a URL for a denied viewer.
- Login goes to `wp-login.php` with BuddyNext active → `TemplateHelpers::login_url()` not preferring `\BuddyNext\Core\PageRouter::auth_url()`, or a call site still using `wp_login_url()` directly.
- OG image present on the gated page → the `if ( $can_view )` wrap around the `wp_head` OG block in `serve_single_media`.
