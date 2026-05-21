# Upload Streaks

> **Requires WPMediaVerse Pro** - This feature is available exclusively in the Pro version.



Upload one photo a day and watch your streak grow - hit milestones to earn XP rewards, and use freeze tokens to protect your streak on days you miss.

## What You Can Do (as a User)

- Build a streak simply by uploading at least one photo or video each day
- See your current streak count and your all-time best streak on your dashboard
- Earn XP automatically when you hit streak milestones: 7 days, 30 days, 100 days, 365 days
- Use freeze tokens to skip one missed day without losing your streak
- Show off your streak badge next to your username across the site

## How It Works (for Users)

### Building Your Streak

1. Upload any photo or video on your site - that counts as your streak day
2. Your streak counter increments once per calendar day, no matter how many files you upload
3. Come back the next day and upload again to keep your streak alive
4. Your streak count and a flame badge appear on your profile and next to your username in comments

### Streak Milestones

When your streak reaches a milestone, XP is awarded automatically to your account:

| Milestone | XP Awarded |
|-----------|-----------|
| 7 days | 50 XP |
| 30 days | 250 XP |
| 100 days | 1,000 XP |
| 365 days | 5,000 XP |

Each milestone is awarded only once. If your streak breaks and you rebuild to 7 days again, no additional XP is awarded for that milestone.

![User dashboard showing streak badge with flame icon](../images/dashboard-media.png)

### Using Freeze Tokens

If you miss a day, a freeze token automatically protects your streak. One token covers one missed day. If you have no tokens and miss a day, your streak resets to zero (your all-time best is never reset).

Freeze tokens are earned through other gamification rewards on the site. Admins can also grant them manually.

### Where Your Streak Badge Appears

Once your streak reaches 3 days, a badge showing your streak count appears:
- Next to your username on every media card you post
- Next to your name in comments
- On your profile page
- In the member directory

## For Site Owners

1. Go to **Media > Settings > Gamification** and enable **Upload Streaks**
2. Streaks run automatically - no manual management needed
3. Grant freeze tokens to specific users from **Users > Edit User > Streak Tokens** in wp-admin
4. The daily streak check runs at 2 AM site timezone via Action Scheduler - confirm Action Scheduler is processing jobs

## How Streaks Work (Technical)

A streak increments by 1 when a user uploads at least one media item on a calendar day (site timezone). If the user uploads multiple times on the same day, the streak still only increments once.

If a user misses a day with no upload, the `mvs_check_streaks` cron job (runs daily at 2 AM, site timezone) resets their current streak to 0. The longest streak value is never reset.

A streak freeze token skips one missed day. If the user has a freeze token and misses a day, the cron job consumes one token and does not reset the streak.

## User Meta Keys

| Meta Key | Type | Description |
|----------|------|-------------|
| `_mvs_current_streak` | int | Number of consecutive days with at least one upload |
| `_mvs_longest_streak` | int | The user's all-time highest streak |
| `_mvs_last_upload_date` | string | `YYYY-MM-DD` of the user's most recent upload (site timezone) |
| `_mvs_streak_freezes` | int | Number of unused freeze tokens |

## Milestone XP Rewards

XP is awarded once per milestone. If a user reaches day 30, they receive the 7-day and 30-day rewards. If their streak later breaks and they rebuild to day 7, no XP is awarded again for that milestone.

Milestone awards are tracked via a separate user meta key to prevent duplicate payouts.

| Milestone | XP Awarded |
|-----------|-----------|
| 7 days | 50 XP |
| 30 days | 250 XP |
| 100 days | 1,000 XP |
| 365 days | 5,000 XP |

![Streak milestone XP award notification](../images/dashboard-media.png)

## Streak Freeze Tokens

Freeze tokens are earned through wb-gamification rewards or granted manually by an admin. Users cannot purchase them directly with points.

When the `mvs_check_streaks` cron runs and finds a user missed yesterday:

1. Check `_mvs_streak_freezes`
2. If count is greater than 0 - decrement by 1, leave streak intact
3. If count is 0 - reset `_mvs_current_streak` to 0

A freeze only protects against a single missed day. Two consecutive missed days break the streak even with a freeze token.

## Streak Badge

A streak badge displays next to the username in media cards, comments, and the member directory when the user has an active streak of 3 days or more. The badge shows the current streak count.

![Media card showing username with streak badge](../images/explore-feed.png)

The badge is suppressed if the user's streak is 0 or if the streaks feature is disabled.

## Settings Reference

| Setting | Key | Default |
|---------|-----|---------|
| Enable Upload Streaks | `mvs_streaks_enabled` | Off |

## Scheduled Actions

| Action Hook | Schedule | Description |
|-------------|----------|-------------|
| `mvs_check_streaks` | Daily at 2 AM (site timezone) | Compares `_mvs_last_upload_date` to yesterday for every user with a streak greater than 0. Applies freezes or resets streaks. Awards XP for newly reached milestones. |

> The daily streak check runs via Action Scheduler, not WP-Cron. If Action Scheduler is not processing jobs, streaks will not break or award XP on time.
