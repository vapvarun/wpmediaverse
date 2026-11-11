# What's New in 2.6.4

MediaVerse 2.6.4 adds demo content to the setup wizard, limits who can edit albums and collections, and brings Stories a length, reactions and a Who can post setting.

> **Included in Free** unless marked Pro. Paired with MediaVerse Pro 2.6.4 - install and test both together. Requires PHP 8.1 or newer.

## Setup and demo content

- The setup wizard offers demo content, and the demo members get photos.
- Demo seeding and cleanup are safe to run on a live site.

See [Setup Wizard](../getting-started/setup-wizard.md).

## Albums and collections

- Only the owner and site managers can edit an album or collection, and only the owner and moderators can delete one, over the REST API and in wp-admin. Editors could change other members' items before.
- Moderators can take down albums and collections without being able to edit them.
- Administrators can create and publish albums and collections in wp-admin.
- Collection pages have a back link, and the rule builder names the rule it applies.

## Uploads and the lightbox

- When the server rejects an upload for size, the message says how large an upload may be.
- The upload window stays open on an outside click while it holds files, and upload errors stay on screen.
- Lightbox tags have a visible remove control, and the bulk-select box shows when you hover an item.
- Chat search, profile edit, media edit fields and tag remove have accessible names. New group explains why Create is disabled.
- Overlay controls and the upload button keep contrast in dark mode.

## Pro: Stories

- **Pro** Owners set how long stories last, from 6 hours to 7 days.
- **Pro** Members can react to a story, and the author sees each viewer's reaction in Seen by. Seen by lists every viewer, 50 at a time.
- **Pro** Who can post stories on Display > Stories picks the roles that may post. The Add story tile is hidden for everyone else and accepts videos and audio as well as photos.
- **Pro** An upload made only for a story is deleted when the story ends, unless an album, a collection or a host's content holds it.
- **Pro** The story sheet sizes to the screen, and Seen by rows stay on one line.
- **Pro** Instagram layout feed cards show a posted comment and the new count at once.
- **Pro** Documents upload says "1 file", and share messages name the @handle.

See [Stories](../pro-features/stories.md).

## For developers

- New filter `mvs_story_duration_hours` sets how long a story lasts.
- **Pro** New filter `mvs_story_keep_item` lets a host keep a story-only upload it also uses.
- New method `reaction_types_for_users()` on the media repository returns which reactions a list of members gave an item.

See [Hooks and Filters](../developer-guide/hooks-filters.md).
