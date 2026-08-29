---
journey: trash-withdraws-the-mirror
plugin: wpmediaverse
priority: critical
roles: [subscriber, administrator]
covers: [media-trash-lifecycle]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "At least one published media item owned by a test member"
estimated_runtime_minutes: 6
---

# Trashing media tells integrations, from every path that can trash it

**Why this journey exists**: trash is the ordinary delete a member performs; permanent delete is the rare one. Until 2.4.0 only the permanent path fired anything, so an integration mirroring media — a BuddyNext activity card — had nothing to listen to. A member trashed a video and the community feed went on advertising it, linking to a URL that now 404s.

The hook alone does not fix that, which is the second reason this journey exists. `trash()` was not the funnel: the admin list wrote the status column directly at four call sites. A journey that only exercises the member path would pass while an admin trashing media still told nobody.

## Setup

Install a probe that records the events, so the journey can assert on them without an integration installed.

```bash
cat > wp-content/mu-plugins/zz-journey-trash-probe.php <<'PHP'
<?php
// JOURNEY FIXTURE — remove after the run.
foreach ( array( 'mvs_media_trashed', 'mvs_media_restored', 'mvs_media_deleted' ) as $h ) {
    add_action( $h, function ( $id, $author = 0, $permalink = '' ) use ( $h ) {
        file_put_contents( WP_CONTENT_DIR . '/journey-trash-probe.log',
            sprintf( "%s id=%s author=%s permalink=%s\n", $h, $id, $author, $permalink ), FILE_APPEND );
    }, 10, 3 );
}
PHP
rm -f wp-content/journey-trash-probe.log
```

## Steps

### 1. Member trashes their own media (REST)

- **Action**: as the owner, `DELETE /wp-json/mvs/v1/media/$MEDIA_ID` with the trash (not permanent) parameter the UI uses.
- **Expect**: log gains `mvs_media_trashed id=$MEDIA_ID author=<owner> permalink=http://.../media/<slug>/`.
- **Expect**: the permalink is NON-EMPTY. It is the whole reason the signature matches `mvs_media_deleted` — a listener withdrawing a mirror keyed on the URL must handle both events with one method.

### 2. Restore puts it back

- **Action**: restore the same item.
- **Expect**: log gains `mvs_media_restored` with the same three arguments.
- **Why it matters**: without the pair, trash-and-restore is a one-way trip for every mirror — the card comes down and never returns.

### 3. Admin row action — the path that used to be silent

- **Action**: in wp-admin, MediaVerse → All Media → **Trash** on a row.
- **Expect**: log gains `mvs_media_trashed` for that id.
- **Fail condition**: if steps 1 and 2 log but this does not, the admin path is writing the status column directly again and every integration is blind to admin moderation.

### 4. Admin bulk action

- **Action**: select two rows → Bulk actions → **Move to Trash** → Apply.
- **Expect**: two `mvs_media_trashed` lines, one per id.

### 5. Documents, which have their own event

- **Action**: in MediaVerse → Documents, trash a document via the row action, then via bulk.
- **Expect**: `mvs_document_trashed` fires for each. That hook already existed and integrations already listen to it, but the ADMIN paths never fired it — so a document card survived an admin trash while a member trash withdrew it.

### 6. Permanent delete still works

- **Action**: permanently delete one item.
- **Expect**: `mvs_media_deleted` with the pre-delete permalink. This path was never broken; the assertion guards against a regression while the trash path was being added around it.

## Teardown

```bash
rm -f wp-content/mu-plugins/zz-journey-trash-probe.php wp-content/journey-trash-probe.log
```

Restore any media trashed during the run (`status` back to `publish`) so the site is left as found.

## Notes

Unit coverage: `MediaRepositoryCoverageTest::test_trash_and_restore_fire_their_actions`, mutation-tested — commenting out the `do_action` fails it with "Trashing fired nothing to listen to." That test covers the repository funnel. This journey covers what the unit test cannot: that all six call sites actually route through the funnel.

Basecamp 10252324048.
