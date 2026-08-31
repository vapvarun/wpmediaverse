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
- **Action**: `curl -X POST -d '{"reaction_type":"like"}' -H 'Content-Type: application/json' $SITE_URL/wp-json/mvs/v1/media/<id>/reactions`
- **Expect**: HTTP **401** `mvs_unauthorized`.
- **The param name is load-bearing** — see the note below. `reaction_type` is required and
  enum-constrained (`like|love|haha|wow|sad|angry`); `emoji` is not a parameter of this route.

### 4. Anonymous POST /media/(id)/comments
- **Action**: `curl -X POST -d '{"content":"hi"}' -H 'Content-Type: application/json' $SITE_URL/wp-json/mvs/v1/media/<id>/comments`
- **Expect**: HTTP **401** `mvs_unauthorized`. The required param is `content`, not `body`.

> **Send the CORRECT payload, or this journey passes without testing anything.** Corrected
> 2026-08-15: steps 3 and 4 previously sent `emoji` and `body`, neither of which is a
> parameter of its route. WordPress validates required params **before** running the
> permission callback, so a wrong param name returns **400 `rest_missing_callback_param`** —
> which satisfies a pass criterion of "non-2xx" while never once exercising the auth gate
> these steps exist to prove. Both were verified with correct payloads on 2026-08-15 and
> return 401. Use a real media id too: `1` is not a media id on most installs.

### 5. Anonymous can read public profile (positive control)
- **Action**: `curl $SITE_URL/wp-json/mvs/v1/users/1`
- **Expect**: HTTP 200 with public profile JSON.

## Pass criteria

Steps 1-2 return **403 `mvs_forbidden`**, steps 3-4 return **401 `mvs_unauthorized`**, and
step 5 returns 200.

Stated as specific codes rather than "non-2xx" deliberately: a 400 from param validation is
also non-2xx and proves nothing about authentication. The point of this journey is that the
permission callback was reached and said no.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Step 1–4 returns 200 | `permission_callback` removed or set to `__return_true` | `includes/REST/Controller/MediaController.php`, `CommentController.php`, `ReactionController.php` |
| Step 5 returns 401 | Public profile gated by mistake | `includes/REST/Controller/UserController.php` |
| Step 3-4 returns 400 `rest_missing_callback_param` | **Your payload is wrong, not the code.** The permission callback was never reached | check the route's required args with `rest_get_server()->get_routes()` |
