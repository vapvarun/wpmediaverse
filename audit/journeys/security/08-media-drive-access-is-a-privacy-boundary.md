---
journey: media-drive-access-is-a-privacy-boundary
plugin: wpmediaverse
priority: critical
roles: [subscriber]
covers: [media-drive-access-seam]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "A bridge answering `mvs_document_drive_access` — this journey installs a stub mu-plugin"
estimated_runtime_minutes: 8
---

# `mvs_media_drive_access` decides placement AND reads, and the two never disagree

**Why this journey exists**: `mvs_media_drive_access` (2.4.0) lets a bridge allow photos on a Space drive while keeping files behind that Space's own files setting. It is easy to read as a placement hint. It is not — the SAME answer governs two gates:

| gate | question | passes when |
|---|---|---|
| `PrivacyService::resolve_drive_for_user()` | may this member PLACE media here? | `write` / `own` |
| `PrivacyService::check_space()` | may this member READ space-scoped media? | `!= none` |

If those two ever answer differently, a photo is stored scoped to a drive whose own members cannot open it — media accepted and then invisible, with nothing to indicate why. That is the failure this journey exists to catch, and it is the same shape as the space-privacy leak fixed in 2.4.0 reached from the opposite direction.

## Setup

Install a stub bridge as an mu-plugin so the ladder has something to answer with. **Remove it at the end** — it grants drive access site-wide.

```bash
cat > wp-content/mu-plugins/zz-journey-drive-bridge.php <<'PHP'
<?php
// JOURNEY FIXTURE — remove after the run.
add_filter( 'mvs_document_drive_access', function ( $level, $type, $id, $user_id ) {
    // Documents refused on this drive: a Space with its files tab off.
    return 'space' === $type ? 'none' : $level;
}, 10, 4 );
add_filter( 'mvs_media_drive', function () {
    return array( 'space', 7 );
} );
PHP
```

## Steps

### 1. Baseline — documents refused means media refused too

- **Action**: with only the stub above active, upload a photo as a member.
- **Expect**: the media lands on the member's PERSONAL drive (`drive_type='user'`), because the document filter answered `none` and nothing widened it.
- **Check**: `mysql_query "SELECT drive_type, drive_id FROM wp_mvs_media_index WHERE media_id=$MEDIA_ID"` → `user`, `0`.

This is the pre-2.4.0 behaviour, and it must still hold for any site that has not adopted the new filter. If this step fails, the seam is not additive and every existing integration has changed underneath it.

### 2. Allow media where documents are refused

- **Action**: append to the stub:
  ```php
  add_filter( 'mvs_media_drive_access', function () { return 'write'; }, 10, 4 );
  ```
- **Action**: upload another photo as the same member.
- **Expect**: this one lands on the SPACE drive — `drive_type='space'`, `drive_id=7`.

### 3. The read gate agrees — the assertion this journey is really for

- **Action**: set that media's privacy to `space`:
  `wp eval '\WPMediaVerse\Core\Plugin::container()->get("media_repository")->set(MEDIA_ID, "privacy", "space");'`
- **Action**: as a DIFFERENT signed-in member, request the media.
- **Expect**: **readable**. `can_view()` must return true.
- **Fail condition**: if placement succeeded in step 2 but this read is refused, the two gates have diverged — media is now stored somewhere its members cannot see. Stop and treat as critical.

### 4. Narrowing works too

- **Action**: change the media filter to return `none` while the document filter returns `own`.
- **Expect**: upload lands on the personal drive again, and space-scoped reads are refused. The seam widens AND narrows, so it cannot be assumed one-directional.

### 5. Anonymous cannot be let in by accident

- **Action**: signed out, request the space-scoped media from step 3.
- **Expect**: refused. A drive is somebody's; `drive_access()` only grants `own` on a user drive when `$user_id > 0`, and the bridge decides everything else.

## Teardown

```bash
rm wp-content/mu-plugins/zz-journey-drive-bridge.php
```

Then confirm a normal upload lands on `user`/`0` again — leaving the stub installed would grant every member write access to Space drive 7.

## Notes

Unit coverage: `tests/unit/MediaDriveAccessSeamTest.php` (7 tests) covers the same contract at the service layer, including that a site with only the document filter is unaffected. This journey is the end-to-end half: it proves the two gates agree through a real upload and a real read, which a unit test of one method cannot.

Basecamp 10252314484.
