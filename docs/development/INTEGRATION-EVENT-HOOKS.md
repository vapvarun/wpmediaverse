# Integration Event Hooks (gamification / activity / notifications)

WPMediaVerse fires action hooks for every user-meaningful event so external
plugins - gamification engines, activity feeds, notification bridges, mobile
adapters - can react **without coupling to WPMediaVerse internals**. Consumers
hook these actions; WPMediaVerse owns no integration manifest for any specific
consumer.

All hooks pass their data as positional parameters - a listener never needs to
read `$_POST` or re-query. For point-style integrations the "actor" (who did
it) and, where relevant, the "recipient" (who benefits) are both provided so a
give/receive rule can award the right user.

## Free - `wpmediaverse`

| Hook | Signature | Actor / Recipient |
|------|-----------|-------------------|
| `mvs_media_uploaded` | `( int $media_id, array $file_data, int $user_id, string $media_type )` | actor = `$user_id`. `$file_data['is_first']` flags the user's first-ever upload (first-upload badges). `$file_data['privacy']`, `mime`, `file_size` also included. |
| `mvs_comment_created` | `( int $media_id, int $user_id, int $comment_id, string $content, string $source )` | actor = commenter `$user_id`; recipient = media author (look up via repository). |
| `mvs_reaction_added` | `( int $media_id, int $user_id, string $reaction_type )` | actor = `$user_id`; recipient = media author. |
| `mvs_reaction_removed` | `( int $media_id, int $user_id )` | reversal - deduct if you awarded on add. |
| `mvs_favorite_added` | `( int $media_id, int $user_id )` | actor = `$user_id`; recipient = media author. |
| `mvs_favorite_toggled` | `( int $media_id, int $user_id, string $action )` | `$action` is `'added'` or `'removed'` - award/deduct accordingly. |
| `mvs_user_followed` | `( int $follower_id, int $following_id )` | actor = `$follower_id`; recipient = `$following_id`. |
| `mvs_user_unfollowed` | `( int $follower_id, int $following_id )` | reversal. |
| `mvs_media_shared` | `( int $media_id, int $user_id, string $platform )` | actor = sharer `$user_id`. |
| `mvs_story_created` | `( int $media_id, int $user_id, string $expires_at )` | actor = `$user_id`. |
| `mvs_album_items_added` | `( int $album_id, int $actor_id, array $media_ids, int $added )` | actor = `$actor_id`; `$added` = count actually added. |
| `mvs_mentions_created` | `( int $media_id, array $mentioned_ids, string $context, int $comment_id )` | recipients = `$mentioned_ids`. |
| `mvs_message_sent` | `( int $message_id, int $conversation_id, int $sender_id, array $recipient_ids )` | actor = `$sender_id`. |
| `mvs_report_submitted` | `( int $report_id, int $reporter_id, string $target_type, int $target_id, string $reason )` | actor = `$reporter_id`. |
| `mvs_media_privacy_changed` | `( int $media_id, string $new_privacy, string $old_privacy )` | lifecycle (e.g. revoke points if media goes private). |
| `mvs_moderation_changed` | `( int $media_id, string $status, string $old_status, int $user_id )` | gate awards on `approved`. |
| `mvs_media_deleted` | `( int $media_id, int $author_id )` | reversal - deduct upload points. |

> Tip: for "recipient = media author", resolve via the published interface -
> `\WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get_author( $media_id )`.

## Pro - `wpmediaverse-pro`

| Hook | Signature | Notes |
|------|-----------|-------|
| `mvs_challenge_entry_submitted` | `( int $challenge_id, int $user_id, int $media_id )` | actor = entrant. |
| `mvs_challenge_winner_named` | `( int $challenge_id, int $winner_user_id, int $rank )` | `$rank` = 1/2/3 placement → tiered award. |
| `mvs_challenge_finalized` | `( int $challenge_id, array $results )` | `$results` carries placement (1st/2nd/3rd) → tiered awards. |
| `mvs_battle_created` | `( int $battle_id, int $challenger_id, int $opponent_id )` | |
| `mvs_battle_resolved` | `( int $battle_id, int $winner_id, int $loser_id )` | win/loss awards. |
| `mvs_tournament_match_resolved` | `( int $match_id, int $winner_id )` | per-round award. |
| `mvs_tournament_finalized` | `( int $tournament_id, int $winner_id )` | overall winner. |
| `mvs_streak_milestone` | `( int $user_id, int $days, int $xp )` | streak rewards. |
| `mvs_pro_credits_added` | `( int $user_id, string $media_type, int $amount, string $source )` | credit/quota economy. |
| `mvs_media_imported` / `mvs_media_exported` | `( int $media_id, string $platform, mixed $remote_id )` | connector sync. |

## Example consumer

```php
// In any plugin/manifest - award points for the uploader + the media author.
add_action( 'mvs_media_uploaded', function ( $media_id, $file_data, $user_id, $media_type ) {
	$points = ( 'video' === $media_type ) ? 20 : 10;
	if ( ! empty( $file_data['is_first'] ) ) {
		// award a one-time "first upload" badge
	}
	my_points_engine_award( $user_id, $points, 'mvs_upload' );
}, 10, 4 );

add_action( 'mvs_comment_created', function ( $media_id, $user_id, $comment_id ) {
	$author = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get_author( $media_id );
	my_points_engine_award( $user_id, 2, 'mvs_comment_give' );   // commenter
	if ( $author && $author !== $user_id ) {
		my_points_engine_award( $author, 1, 'mvs_comment_receive' ); // author
	}
}, 10, 3 );
```

## Stability

These are public extension points and follow the plugin's deprecation policy
(no removal/rename without an alias for >= 2 major versions). New positional
args are appended, never inserted - listeners registered with a smaller
`accepted_args` keep working (as `mvs_media_uploaded` did when `$user_id` +
`$media_type` were appended in 1.2.3).
