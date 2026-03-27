# Social Features

WPMediaVerse includes reactions, comments, favorites, follows, mentions, and sharing — all built on a custom database layer separate from WordPress comments.

## Reactions

Users can react to media items with emoji reactions (like, love, wow, etc.).

[screenshot: Media item with reaction bar showing emoji counts]

### REST API

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/mvs/v1/media/{id}/reactions` | Get reaction counts for a media item |
| `POST` | `/mvs/v1/media/{id}/reactions` | Add or change your reaction |
| `DELETE` | `/mvs/v1/media/{id}/reactions` | Remove your reaction |

### Action: `mvs_reaction_added`

Fires when a reaction is added. BuddyPress integration uses this to send notifications.

```php
do_action( 'mvs_reaction_added', $media_id, $user_id, $reaction_type );
```

## Comments

WPMediaVerse uses a custom comments system stored in a dedicated table, separate from WordPress's `wp_comments`.

[screenshot: Comment section below a media item with reply threading]

### REST API

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/mvs/v1/media/{id}/comments` | List comments on a media item |
| `POST` | `/mvs/v1/media/{id}/comments` | Post a new comment |
| `PUT` | `/mvs/v1/media/{id}/comments/{comment_id}` | Edit a comment |
| `DELETE` | `/mvs/v1/media/{id}/comments/{comment_id}` | Delete a comment |

### Action: `mvs_comment_created`

```php
do_action( 'mvs_comment_created', $comment_id, $media_id, $user_id );
```

BuddyPress integration uses this to record activity and send notifications.

## Favorites

Users can save media items to their favorites or to a named collection.

### REST API

| Method | Endpoint | Description |
|--------|----------|-------------|
| `POST` | `/mvs/v1/media/{id}/favorite` | Add to favorites |
| `DELETE` | `/mvs/v1/media/{id}/favorite` | Remove from favorites |
| `GET` | `/mvs/v1/favorites` | List the current user's favorites |

## Follows

Users can follow other users to see their public media in a feed.

### REST API

| Method | Endpoint | Description |
|--------|----------|-------------|
| `POST` | `/mvs/v1/users/{id}/follow` | Follow a user |
| `DELETE` | `/mvs/v1/users/{id}/follow` | Unfollow a user |
| `GET` | `/mvs/v1/users/{id}/followers` | List a user's followers |
| `GET` | `/mvs/v1/users/{id}/following` | List who a user follows |

## Mentions

Users can @mention each other in comments. Mentioned users receive a notification.

### Action: `mvs_mentions_created`

```php
do_action( 'mvs_mentions_created', $mentioned_user_ids, $comment_id );
```

## Sharing

The `ShareService` provides share URL generation for media items. Shareable links respect the media's privacy level — private media returns a 403 when accessed via a share link by an unauthorized user.

## Reports

Users can report inappropriate media content.

### REST API

| Method | Endpoint | Description |
|--------|----------|-------------|
| `POST` | `/mvs/v1/media/{id}/report` | Submit a content report |

When the number of reports for a single media item reaches the **Auto-Hide Threshold** (set in AI & Moderation settings), the media is automatically hidden and added to the moderation queue.
