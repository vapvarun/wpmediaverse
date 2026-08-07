---
journey: private-community-gate
plugin: wpmediaverse
priority: high
roles: [administrator, subscriber, anonymous]
covers: [community-privacy-gate, page-gate, serve-exemption]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "A host community layer that arms mvs_rest_require_auth (BuddyNext: Settings -> Members -> Private & Data -> Require login to view the community), or a mu-plugin adding the filter"
  - "At least one PUBLIC published media item (id captured as $MEDIA_ID, its signed serve URL as $SERVE_URL from the REST list response)"
estimated_runtime_minutes: 5
---

# Private community seals pages + JSON but never breaks members' media

**Why this journey exists**: The 2.2.0 gate blocked ALL of `mvs/v1` on
`is_user_logged_in()`, including `/serve` — the media binary route browsers
fetch as `<img>`/`<video>` subresources, which carry cookies but can never
carry `X-WP-Nonce`. Core anonymizes nonce-less cookie REST requests, so every
media file 401'd for members and guests alike: the community went private and
all media broke for everyone (Basecamp 10180510437). The 2.3.2 fix exempts
the signed `/serve` route (its HMAC is the credential) and adds the missing
PAGE-layer gate — without which a guest could read media straight out of the
server-rendered explore/profile/compete HTML. This journey pins all three
properties at once: members keep media, guests lose pages AND data, `/serve`
honors its own signature model.

## Setup

- Enable the private-community toggle on the host (BuddyNext:
  `wp option update buddynext_private_community 1`).
- Capture `$SERVE_URL` for a public item from `GET /wp-json/mvs/v1/media?per_page=1`
  (its `thumbnail_url`) BEFORE logging out.
- Restore the toggle at the end.

## Steps

### 1. Member sees media everywhere
- **Action**: as a logged-in member, load `$SITE_URL/media/` in the browser;
  screenshot and LOOK at the grid.
- **Expect**: HTTP 200, every tile's image renders (no broken images). This is
  the exact 10180510437 regression surface.

### 2. Member's image subresources succeed
- **Action**: with the member's cookie but NO nonce header, GET `$SERVE_URL`
  (this is how a browser `<img>` asks).
- **Expect**: HTTP 200, `Content-Type: image/*`. A 401 here means the session
  gate is covering `/serve` again.

### 3. Guest is turned away from every server-rendered page
- **Action**: log out; GET `$SITE_URL/media/`, `$SITE_URL/media/@{user}/`, a
  single `/media/{slug}/`, and (Pro active) `$SITE_URL/media/battles/`.
- **Expect**: HTTP 302 to the login surface for each (BuddyNext: `/login/`).
  A 200 whose HTML contains `mvs/v1/serve` URLs is the page-layer leak.

### 4. Guest gets 401 on the JSON surface
- **Action**: anonymous GET `/wp-json/mvs/v1/media?per_page=1` and (Pro)
  `/wp-json/mvs-pro/v1/challenges`.
- **Expect**: HTTP 401 `mvs_community_private` on both.

### 5. Signed serve honors its own model
- **Action**: anonymous GET `$SERVE_URL`.
- **Expect**: HTTP 200 — the HMAC on the URL is the credential (S3
  pre-signed-URL model; the URL is only mintable through gated surfaces).
  Sites wanting a harder line empty `mvs_rest_gate_exempt_route_prefixes`.

### 6. Standalone regression — toggle off restores public behavior
- **Action**: `wp option update buddynext_private_community 0`; repeat steps
  3-4 anonymously.
- **Expect**: pages 200 with content, JSON 200. Standalone MediaVerse must
  never be affected by the gate machinery.

## Pass criteria

Members browse media untouched (200s with rendering images); guests are
redirected off every MVS page and 401'd on every gated JSON route; the signed
`/serve` route serves by signature in both states; disabling the toggle
restores the fully public baseline.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Member sees broken images (step 1-2) | `/serve` no longer exempt from the session gate | `includes/REST/CommunityPrivacyGate.php` `mvs_rest_gate_exempt_route_prefixes` default |
| Guest gets 200 with serve URLs in HTML (step 3) | Page-layer gate unhooked or scope regressed | `CommunityPrivacyGate::gate_page()` / `page_needs_login()`; Pro: `mvs_community_gated_page` filter in Pro `Core/Plugin.php` |
| Guest 200 on JSON (step 4) | REST gate unarmed — host filter wiring broken | BuddyNext `includes/Core/PrivateCommunity.php` `register()` |
| Guest redirected to wp-login.php instead of /login/ | Host stopped filtering `mvs_community_login_url` | BuddyNext `PrivateCommunity::register()` |
| Step 6 still gated | Toggle read cached or filter left armed by test harness | `buddynext_private_community` option; mu-plugins |
