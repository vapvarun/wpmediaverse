# Feed Media

When a member uploads to MediaVerse, BuddyNext publishes a card into the community feed so the upload is visible where people are already reading.

## What gets published

| Upload | Feed result |
|---|---|
| Photo | A native BuddyNext photo post carrying the media ids, rendered as a grid |
| Video, audio | A typed `media` card linking to the item |
| Document | A typed document card, resolved per viewer |
| Private or members-only upload | Nothing. Only public uploads are announced |

## The card stores a reference, not a snapshot

A media card stores **only the media id**. Its title and cover are read live, for the person reading the feed.

That is deliberate, and it solves two problems a snapshot would create:

- **Signed thumbnails expire.** MediaVerse thumbnail URLs are signed with a short TTL. A URL frozen into a post would stop working within the hour.
- **A frozen title outlives its privacy.** Rename or lock a file and a snapshot would keep printing the old name to everyone who can read the feed.

Because resolution happens at render, tightening a file's privacy immediately stops showing its name and poster to people who may no longer open it.

## Covers

A card shows a cover when MediaVerse has produced a thumbnail for that media:

- **Images** always have one.
- **Video** has one when the file carries an embedded cover atom, or when the browser captured a frame during upload.
- **Audio** has one when the file carries embedded ID3 artwork.

A file with no artwork still produces a valid card - it simply renders compact, without a cover image.

## Publishing is deferred

Cards are published through a scheduled action a couple of minutes after upload rather than inline, so a large upload is not slowed by feed work. A card appearing a short time after the upload is expected behaviour, not a fault.

## Withdrawal

Deleting media withdraws its card. MediaVerse passes the pre-delete permalink when it fires `mvs_media_deleted`, because the card is keyed on that URL and it cannot be reconstructed once the slug row is gone.
