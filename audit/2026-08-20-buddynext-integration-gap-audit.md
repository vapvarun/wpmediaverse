# MediaVerse ↔ BuddyNext integration — gap audit

**Run 2026-08-20** against WPMediaVerse 2.4.0 + Pro 2.4.0 and BuddyNext on branch `1.1.6`
(content identical to `v1.1.5`; the 1.1.6 branch carries no work yet).

Method: enumerate every `mvs_*` hook MediaVerse fires or offers, from the real `do_action()` /
`apply_filters()` call sites — not from docs — then diff against every `mvs_*` token appearing
anywhere in `buddynext/includes`. Each candidate gap was then opened in the code before being
called a gap, because a raw diff over-reports: several "unanswered" filters turned out to be
answered in a shape the first grep did not match, and most of the rest are knobs with correct
defaults that BN has no reason to touch.

## Numbers

| | Count |
|---|---|
| `mvs_*` actions fired by MediaVerse | 117 |
| `mvs_*` filters offered by MediaVerse | 186 |
| Host-facing seams (community / space / profile / drive / DM / privacy / notification) | 34 |
| Hooks BuddyNext actually wires (`add_action` / `add_filter`) | 17 |
| `WPMediaVerseBridge.php` | 1,283 lines, 21 hooks wired |

**303 hooks against 17 consumed is not the finding.** Most of the 303 are internal knobs. The
finding is *which* seams are unanswered, and what breaks as a result.

## What already works

Wired and healthy: DM engine handoff (`mvs_message_sent`, `mvs_can_send_message`,
`mvs_message_content_check`, `mvs_dm_denial_reason`), follow mirroring
(`mvs_user_followed` / `_unfollowed`, with the re-entrancy guard), media comments
(`mvs_comment_created`), favourites (`mvs_favorite_toggled`), deletion cleanup
(`mvs_media_deleted`), album privacy inheritance (`mvs_album_inherit_privacy`), reports handover
(`mvs_reports_enabled`), single-media routing (`mvs_single_media_redirect`), profile URLs
(`mvs_user_profile_url`), presence (`mvs_buddynext_active`), and the private-community REST gate
(`mvs_rest_require_auth`, `mvs_rest_can_access`, `mvs_community_login_url` — the last one lives in
`Core/PrivateCommunity.php`, not the bridge).

MediaVerse also resolves BuddyNext's auth page natively in `TemplateHelpers::login_url()`, so
`mvs_login_url` needs no answer. That is a non-gap worth recording so nobody "fixes" it.

---

## GAP 1 — Documents: no integration at all

`WPMediaVerseBridge.php` contains **zero** `mvs_document*` hooks. MediaVerse Pro 2.4.0 ships a
complete document drive — folders, sharing, trash, in-drive search, previews, a full
`mvs-pro/v1` REST surface, all app-drivable with an Application Password alone — and BuddyNext
surfaces none of it.

Already carded, in Ready for Development: **10220143810** (feed), **10220146196** (profile Files
tab), **10220148861** (Spaces Files tab).

## GAP 2 — One unanswered filter disables `space` privacy for MEDIA as well as documents

**This is the highest-value finding in the audit, and it was not visible from the document work.**

`PrivacyService::check_space()` — the resolver for **media** whose privacy level is `space` —
does not consult BuddyNext directly. It reads the media's `drive_type` / `drive_id` and then asks:

```php
$level = (string) apply_filters( 'mvs_document_drive_access', 'none', $drive_type, $drive_id, $user_id );
return 'none' !== $level;
```

`includes/Services/PrivacyService.php:330`

That is the *same* filter the document bridge needs. Nothing answers it, so it returns its
default `'none'`, so `check_space()` returns false for every viewer, so **`space` privacy is dead
for media and for documents alike**.

The practical consequence: BuddyNext Spaces have no working space-scoped privacy for MediaVerse
content of any kind. Answering one filter turns it on for both. Card 10220148861 covers the
filter; this audit is the reason its priority is higher than "documents in Spaces" alone implies.

## GAP 3 — `group` privacy is BuddyPress-only, so Spaces cannot scope media

`PrivacyService::check_group()` is hard-wired to BuddyPress:

```php
if ( ! function_exists( 'groups_is_user_member' ) ) {
    return false;   // BuddyPress not active — fall back to private
}
```

`includes/Services/PrivacyService.php:282`

BuddyNext Spaces are **not** BuddyPress groups. On a BuddyNext-only site (no BuddyPress), media
set to `group` privacy resolves to private for everyone but its owner. Combined with GAP 2, a
BuddyNext-only stack has **no working mechanism to scope media to a Space** — neither `group`
nor `space`.

Whose fix: arguably MediaVerse's (teach `check_group()` to defer to a filter the way
`check_space()` does), but BuddyNext is the only party that can answer it. Worth a joint decision
rather than a card thrown over the fence.

## GAP 4 — Reactions and mentions never reach the BuddyNext notification centre

MediaVerse renders six notification types: `new_follower`, `media_reaction`, `media_comment`,
`media_mention`, `media_favorite`, `new_message`
(`includes/Social/NotificationService.php:509-541`).

BuddyNext deliberately does not consume `mvs_notification_created` — it builds its own
notifications from source events, which matches the bridge contract's "collect-only" rule and the
documented exception that DM and favourite types are BN-native. It listens for comments,
favourites, messages and follows.

**It listens for neither reactions nor mentions.** MediaVerse fires `mvs_reaction_added`,
`mvs_reaction_removed`, `mvs_reaction_toggled` and `mvs_mentions_created`; `buddynext/includes`
contains no reference to any of them.

So on a BuddyNext stack: someone reacts to your photo, or @mentions you in a media comment, and
the notification centre members actually look at never hears about it. Four of six MediaVerse
notification types are covered; two are silently missing.

## Worth confirming, not yet called gaps

Recorded honestly rather than asserted, because intent is unclear from the code alone:

- **`mvs_group_conversation_created`** — unlistened. MediaVerse supports group conversations and
  there is already a closed card, "WPMediaVerse: No group-message UI despite group conversations
  being imported", suggesting this is known territory rather than news.
- **`mvs_media_group_assigned`** — unlistened. Fires when media is assigned to a group
  (`MediaController.php:922`). Relevant only if Spaces should react to it.
- **`mvs_community_gated_page`** — unanswered, but BuddyNext runs its own `PrivateCommunity`
  implementation and gates its own pages, so MediaVerse's page-level default may simply be
  irrelevant here.

## Explicitly NOT gaps

`mvs_storage_driver`, `mvs_dm_*` rate limits and size caps, `mvs_activity_max_*`,
`mvs_profile_*`, `mvs_settings_group_labels` and the rest of the unanswered list are knobs with
correct defaults. A host plugin answering them would be overriding site-owner settings, not
integrating. Do not card them.

---

## Suggested order for the BuddyNext team

1. **`mvs_document_drive_access`** — one filter, four consumers: Space document drives, Space
   media privacy, the drive picker, and the visibility ladder. Highest value per line of code in
   this whole audit (card 10220148861).
2. **Reaction and mention notifications** — two listeners, closes a visible hole in the
   notification centre (GAP 4, needs a card).
3. **The other three drive filters** — `mvs_document_drive_visible`,
   `mvs_document_drives_for_user`, `mvs_document_drive_label` (card 10220148861).
4. **Profile and feed document surfaces** — cards 10220146196 and 10220143810.
5. **Joint decision on `check_group()`** — MediaVerse-side change, BuddyNext-side answer (GAP 3).

## Caveat on this audit's environment

`mediaverse.local` was running BuddyNext **89 commits behind** `origin/1.1.5` until 2026-08-20.
This audit reads the *current* checkout (branch `1.1.6`), so the code findings stand. But the
behavioural observations in `qa/runs/2026-08-19-partial-smoke.md` — the single-media redirect and
the Action Scheduler deadlock — were recorded against the old copy and need re-checking before
they are trusted.
