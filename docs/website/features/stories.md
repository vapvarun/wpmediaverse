# Stories

Stories are time-limited media sequences — similar to Instagram or WhatsApp Stories — displayed in a full-screen viewer with auto-advance.

[screenshot: Story viewer in full-screen mode with progress bar at top]

## Displaying Stories

**Gutenberg Block:** Add the **WPMediaVerse: Story Viewer** block to any page.

The Story Viewer block queries recent media that has been designated as a story and presents them in a horizontal story-rail UI.

## How Stories Work

1. When uploading, users can mark media as a story via the REST API (`is_story: true` parameter).
2. Story media is stored as standard `mvs_media` posts with the `_mvs_is_story` meta key set to `true`.
3. The `StoryService` resolves active (non-expired) stories and serves them via the REST API.
4. Stories expire based on the TTL configured in your General settings.

## REST API for Stories

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/mvs/v1/stories` | List active stories for a user or feed |
| `POST` | `/mvs/v1/stories` | Create a story (upload + mark as story) |
| `DELETE` | `/mvs/v1/stories/{id}` | Delete a story |

## Story Privacy

Stories inherit the privacy level of their underlying `mvs_media` post. Public stories appear in the global story rail. Friends-only stories appear only for BuddyPress friends of the uploader.
