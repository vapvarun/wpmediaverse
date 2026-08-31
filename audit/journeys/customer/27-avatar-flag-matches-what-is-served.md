---
journey: avatar-flag-matches-what-is-served
plugin: wpmediaverse
priority: high
roles: [subscriber]
covers: [avatar-flag-truthfulness]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "A second avatar provider active, or the stub below"
estimated_runtime_minutes: 4
---

# `has_custom_avatar` does not contradict the avatar beside it

**Why this journey exists**: the flag used to answer "is there a row in OUR avatar store" while the `avatar` field in the same REST response answered "what does the avatar chain resolve to". On a site with a second avatar provider those stop agreeing: measured with BuddyNext active, 8 of 8 members who had uploaded a picture there got `has_custom_avatar: false` while their real photograph was being served in the field next to it. Anything gating on the flag then asks a member for a picture they already have.

## Setup

If no second avatar provider is installed, stub one:

```bash
cat > wp-content/mu-plugins/zz-journey-avatar.php <<'PHP'
<?php
// JOURNEY FIXTURE — remove after the run.
add_filter( 'mvs_has_custom_avatar', function ( $has, $user_id ) {
    return $has || (bool) get_user_meta( $user_id, 'journey_avatar', true );
}, 10, 2 );
PHP
```

## Steps

### 1. A member with nothing reports false

- **Action**: `GET /wp-json/mvs/v1/users/$UID` for a member with no avatar anywhere.
- **Expect**: `has_custom_avatar: false`.
- **Why first**: a seam that returns true for everyone is as useless as the bug it replaces — nobody is ever asked for a photo again. This is the assertion that catches that.

### 2. A member whose avatar we store reports true

- **Action**: upload an avatar through MediaVerse, re-request.
- **Expect**: `has_custom_avatar: true`, and `avatar` points at the uploaded file.

### 3. A member whose avatar ANOTHER plugin stores reports true

- **Action**: `wp user meta update $UID journey_avatar 1` (or set a real avatar in the other provider), re-request.
- **Expect**: `has_custom_avatar: true`.
- **Fail condition**: false here while `avatar` serves a real photograph is the reported bug.

### 4. The flag and the field do not contradict each other

- **Action**: for each of the three members above, compare `has_custom_avatar` against what `avatar` actually resolves to.
- **Expect**: no member is served a real photograph while reporting `false`.
- **Note**: the reverse — `true` while a placeholder is served — indicates the other provider is overwriting a real avatar with its placeholder, which is that plugin's bug, not this flag's. Record it rather than failing this journey.

## Teardown

```bash
rm -f wp-content/mu-plugins/zz-journey-avatar.php
wp user meta delete $UID journey_avatar
```

## Notes

Unit coverage: `tests/unit/ProfileAvatarFlagTest.php` (3 tests), mutation-tested. One pins that the filter receives our own answer as its default, so a careless listener returning a bare `false` cannot erase a member's own MediaVerse upload.

Basecamp 10252323883.
