---
journey: anonymous-cannot-modify
plugin: wpmediaverse
priority: critical
roles: [anonymous]
covers: [security-gate, rest-permission_callback]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "Existing public media item (any ID) for read tests"
estimated_runtime_minutes: 2
---

# Anonymous (unauthenticated) cannot create/modify/delete media or comments

**Why this journey exists**: Every write endpoint MUST gate on `permission_callback`. A regression that exposes write to anonymous (e.g., `__return_true` slipped into a controller) leaks the entire site. Read endpoints with privacy filtering are still publicly readable — that's expected.

## Steps

### 1. Anonymous POST /media (no auth)
- **Action**: `curl -X POST -F 'file=@/dev/null' $SITE_URL/wp-json/mvs/v1/media`
- **Expect**: HTTP 401 OR 403; response contains `"code":"rest_forbidden"` or similar.

### 2. Anonymous DELETE /media/(id)
- **Action**: `curl -X DELETE $SITE_URL/wp-json/mvs/v1/media/1`
- **Expect**: HTTP 401/403.

### 3. Anonymous POST /media/(id)/reactions
- **Action**: `curl -X POST -d '{"emoji":"like"}' -H 'Content-Type: application/json' $SITE_URL/wp-json/mvs/v1/media/1/reactions`
- **Expect**: HTTP 401/403.

### 4. Anonymous POST /media/(id)/comments
- **Action**: `curl -X POST -d '{"body":"hi"}' -H 'Content-Type: application/json' $SITE_URL/wp-json/mvs/v1/media/1/comments`
- **Expect**: HTTP 401/403.

### 5. Anonymous can read public profile (positive control)
- **Action**: `curl $SITE_URL/wp-json/mvs/v1/users/1`
- **Expect**: HTTP 200 with public profile JSON.

## Pass criteria

ALL of steps 1–4 return non-2xx; step 5 returns 200.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Step 1–4 returns 200 | `permission_callback` removed or set to `__return_true` | `includes/REST/Controller/MediaController.php`, `CommentController.php`, `ReactionController.php` |
| Step 5 returns 401 | Public profile gated by mistake | `includes/REST/Controller/UserController.php` |
