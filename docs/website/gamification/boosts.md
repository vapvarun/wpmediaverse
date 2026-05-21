# Media Boosts

> **Requires WPMediaVerse Pro** - This feature is available exclusively in the Pro version.



Spend your earned points to push a photo to the top of the Explore feed - get more eyes on your best work right when you want it seen.

## What You Can Do (as a User)

- Boost any of your photos to increase its visibility in the Explore feed
- Choose how many impressions you want to buy (e.g., 500 views)
- See a live point cost preview before committing
- Track how many impressions your boost has delivered so far
- Cancel an active boost at any time (points are not refunded)
- Run boosts on multiple photos simultaneously

## How It Works (for Users)

1. Open one of your uploaded photos
2. Click **Boost** - a panel shows your current point balance and the cost per 100 impressions
3. Enter your target impression count (e.g., 500 impressions costs 250 points at the default rate)
4. Click **Confirm Boost** - points are deducted immediately from your balance
5. Your photo is now marked as boosted and appears at elevated positions in the Explore feed
6. Return to the photo page anytime to see a progress bar showing impressions delivered vs. target
7. When the impression target is reached (or after 30 days), the boost expires automatically and your photo returns to its normal organic rank

![Media item page showing Boost button](../images/single-media.jpg)

### Where Boosted Content Appears

Boosted photos appear throughout the Explore feed at regular intervals mixed in with organic content - they do not all cluster at the top. This means boosted photos reach a wider audience as visitors scroll, rather than just people who see the top of the page.

## For Site Owners

1. Go to **Media > Settings > Gamification** and enable **Media Boosts**
2. Set the **Max Impressions Per Boost** (default: 5,000) to control how large a boost can be
3. Set the **Cost Per 100 Impressions** (default: 50 points) to match your community's point economy
4. Users must earn points through other gamification activities (uploads, challenges, streaks) before they can boost

## How Boosts Work (Technical)

1. The user clicks **Boost** on any media item they own
2. They set an impression target (up to the site maximum)
3. The system deducts points from their wb-gamification balance
4. The media item is flagged as boosted in `mvs_boosts`
5. The Explore feed query injects boosted items at a higher rank
6. When the impression counter reaches the target, or the boost duration expires, the boost auto-expires and the media returns to organic ranking

A user can have multiple media items boosted simultaneously. One media item can only have one active boost at a time.

## Boost Cost Calculation

Cost is based on the impression target:

```
cost = ceil( impression_target / 100 ) × mvs_boost_cost_per_100
```

Example: boosting for 500 impressions at the default cost of 50 points per 100 costs **250 points**.

The point deduction fires via wb-gamification's point spend API at the moment the boost is created. If the user does not have enough points, the API returns an error and the boost is not created.

## Settings Reference

| Setting | Key | Default | Description |
|---------|-----|---------|-------------|
| Enable Media Boosts | `mvs_boosts_enabled` | Off | Master toggle for the boosts feature |
| Max Impressions Per Boost | `mvs_boost_max_impressions` | `5000` | The highest impression target a user can set |
| Cost Per 100 Impressions | `mvs_boost_cost_per_100` | `50` | Points deducted per 100 impressions purchased |

## Database Table: mvs_boosts

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint | Primary key |
| `media_id` | bigint | The boosted media item |
| `user_id` | bigint | User who created the boost |
| `target_impressions` | int | Impression target set at boost creation |
| `current_impressions` | int | Running impression count |
| `points_spent` | int | Points deducted from user balance |
| `status` | varchar | `active` or `expired` |
| `created_at` | datetime | When the boost was created |
| `expires_at` | datetime | Hard expiry datetime (independent of impressions) |

## REST API

**Base URL:** `/wp-json/mvs-pro/v1/media/{id}/boost`

| Method | Endpoint | Description |
|--------|----------|-------------|
| `POST` | `/media/{id}/boost` | Create a boost for a media item |
| `GET` | `/media/{id}/boost` | Get the active boost status for a media item |
| `DELETE` | `/media/{id}/boost` | Cancel an active boost. Points are not refunded. |

### POST /media/{id}/boost

```json
{
  "target_impressions": 500
}
```

Returns `400 Bad Request` if `target_impressions` exceeds `mvs_boost_max_impressions`. Returns `402 Payment Required` if the user does not have enough points. Returns `409 Conflict` if the media item already has an active boost.

### GET /media/{id}/boost

```json
{
  "active": true,
  "target_impressions": 500,
  "current_impressions": 143,
  "points_spent": 250,
  "expires_at": "2026-04-07T09:00:00Z"
}
```

Returns `{ "active": false }` if no active boost exists.

## Explore Feed Injection

Boosted media is injected into the Explore feed by a filter on the WPMediaVerse explore query. The injected items appear at regular intervals (every N organic items) rather than clustering at the top. The injection interval is not currently user-configurable.

Impression counts increment server-side when a boosted item is rendered in the feed. Impressions are tracked per page load, not per unique user.

## Expiry

A boost expires when either condition is met:

- `current_impressions` reaches `target_impressions`
- The current time passes `expires_at`

The hard expiry datetime is set to 30 days after boost creation, regardless of the impression target.

## Scheduled Actions

| Action Hook | Condition |
|-------------|-----------|
| `mvs_expire_boosts` | Runs hourly - sets `status = expired` on boosts where the impression target is reached or `expires_at` has passed |
