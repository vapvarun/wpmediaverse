# Profile Media

A member's uploads are reachable from their BuddyNext profile, so browsing someone's photos is part of reading their profile rather than a separate destination.

## Where it appears

BuddyNext owns the profile layout. MediaVerse supplies the media and the per-viewer privacy decisions; BuddyNext decides where the tab sits and how it is labelled.

## What the tab shows

Only media the person viewing is allowed to see. The same privacy rules that govern Explore apply here:

| Privacy | Who sees it on the profile |
|---|---|
| Public | Everyone, including logged-out visitors |
| Members | Signed-in members |
| Only me | The owner alone |

Counts obey the same rule. A profile that advertises "48 photos" shows 48 photos to that viewer - the number and the grid never disagree, and a stranger is not told how much they cannot open.

## Blocked members

If either person has blocked the other, the profile's media and its counts are withheld. This is enforced in the shared visibility gate rather than in the template, so every surface that lists media inherits it.

## Avatars

BuddyNext renders avatars from its own `bn_avatar` user meta. MediaVerse does not write that value; it reads whatever BuddyNext resolves, so a member's picture is consistent across the feed, their profile and every media card they own.
