# Space Media

BuddyNext organises communities into **spaces**. Media uploaded into a space is announced in that space's feed and stays associated with it.

> If you are coming from BuddyPress, spaces are the equivalent of groups - but they are not a renamed version of them. The information architecture differs, so the behaviour below is specific to BuddyNext.

## Uploading into a space

A member uploading from within a space has the upload associated with it. The space's feed receives a post announcing what was added.

## Two producers, one post type

A space upload and a bridged MediaVerse upload both produce `media` posts in BuddyNext, but they carry opposite payloads:

| Producer | Carries | Renders as |
|---|---|---|
| Space album upload | Real media ids, no link | A media grid in the space feed |
| MediaVerse bridge upload | A link and the media id, no media ids | A typed card resolved per viewer |

Knowing which one you are looking at explains why some cards show a grid and others show a single linked card.

## Privacy inside a space

Space membership does not override a file's own privacy. A private upload stays private to its owner even inside a space the viewer belongs to; the space controls reach, the file controls access, and the stricter of the two wins.

## Document drives

With MediaVerse Pro, a space can carry a document drive. Access is resolved through `mvs_document_drive_access`, which BuddyNext answers based on the viewer's role in that space.
