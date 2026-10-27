# Rate Limiting

## Strategy

- Limit per endpoint, by how damaging abuse of that endpoint is.
- Never one global limit for everything.

## Current implementation

Extracted from `RateLimiter::for()` in `app/Providers/AuthServiceProvider.php`
and `app/Providers/AppServiceProvider.php`. **This table is the source of
truth** — if it disagrees with the code, the code is right and this table is
stale. Re-derive it rather than editing by hand when a limiter changes.

| Limiter | Setting key (default) | Window | Keyed on | Over-limit response |
|---------|-----------------------|--------|----------|---------------------|
| `login` | `login_rate_limit_per_minute` (5) | per minute | identifier + IP | 302 + errors (web) / 429 JSON |
| `login-ip` | `login_ip_rate_limit_per_minute` (10) | per minute | IP | 302 + errors (web) / 429 JSON |
| `register` | `registration_rate_limit_per_minute` (3) | per minute | IP | 302 + errors (web) / 429 JSON |
| `forgot-password` | `password_forgot_rate_limit` (3) | per minute | identifier + IP | 302 + errors (web) / 429 JSON |
| `reset-password` | `password_reset_rate_limit` (3) | per minute | identifier + IP | 302 + errors (web) / 429 JSON |
| `password-change` | `password_change_rate_limit_per_minute` (5) | per minute | user id | 302 + errors (web) / 429 JSON |
| `resend-verification` | `email_verification_rate_limit` (5) | per hour | user id, else email | 302 + errors (web) / 429 JSON |
| `email-verification` | `email_verification_rate_limit` (5) | per hour | identifier | 302 + errors (web) / 429 JSON |
| `user-state-actions` | `user_state_actions_rate_limit_per_minute` (15) | per minute | user id, else IP | 302 + errors (web) / 429 JSON |
| `bulk-action` | `bulk_action_rate_limit_per_minute` (5) | per minute | user id, else IP | 302 + errors (web) / 429 JSON |
| `send-test-mail` | `send_test_mail_rate_limit_per_minute` (5) | per minute | user id + IP + recipient | 302 + errors (web) / 429 JSON |
| `audit-export` | `audit_export_rate_limit_per_hour` (5) | per hour | user id + IP | 302 + errors (web) / 429 JSON |
| `queue-retry` | `queue_retry_rate_limit_per_minute` (10) | per minute | user id + IP | 302 + flash `error` (web) / 429 JSON |
| `health` | `health_rate_limit_per_minute` (120) | per minute | IP | 429 JSON only |

`email-verification` and `resend-verification` deliberately share one setting
key: they are the same action, reachable by two routes.

## API ladder — which limiter is the one that refuses

Two limiters can apply to one request. Both are checked, and **the tighter
ceiling is the one a caller meets first** — a looser second limiter with the
same key can only ever refuse someone the tighter one already refused. That is
why no `api` limiter exists: the protected group already runs
`user-state-actions` at 15/minute, and a typical 60/minute `api` limiter beside
it would change nothing while reading like a control that does the work. Adding
one *instead* would be a 4× loosening, which is not an option.

The exception is a second limiter with a **different key**. `/auth/login`
carries `login` and `login-ip`: the first counts per identifier + IP, the second
per IP alone. One address trying one password against a thousand accounts never
repeats the first key and is only visible to the second. A looser number on a
wider key is not redundant — it is the only bucket that sees the spray.

| Route(s) | Limiter(s) | Effective ceiling |
|---|---|---|
| `POST /auth/login` | `login` + `login-ip` | 5/min per identifier + IP (in the action on the API), and 10/min per IP |
| `POST /auth/register` | `register` | 3/min per IP |
| `POST /auth/password/forgot` | `forgot-password` | 3/min per identifier + IP |
| `POST /auth/password/reset` | `reset-password` | 3/min per identifier + IP |
| `GET /auth/email/verify/{id}/{hash}` | none — `signed` only | one valid link, expiring |
| every route in the protected group | `user-state-actions` | 15/min per user (IP when no user) |
| `POST /auth/email/resend`, `POST /users/{user}/resend-verification`, `GET /email/verify-change/{user}` | `user-state-actions` + `resend-verification` / `email-verification` | 5/hour per user |
| `POST /users/bulk-action`, `POST /roles/bulk-action`, `POST /features/bulk-action` | `user-state-actions` + `bulk-action` | 5/min per user |
| `POST /notifications/test-mail` | `user-state-actions` + `send-test-mail` | 5/min per user + IP + recipient |
| the three `/activity-logs/exports*` routes | `user-state-actions` + `audit-export` | 5/hour per user + IP |
| `POST /auth/password/change` | `password-change` | 5/min per user |
| `GET /health` | `health` | 120/min per IP |

The inbox is the one protected list that looks under-throttled against its web
twin: the web polls at `120,1`, the API has no extra limiter, so the API's real
ceiling is the group's 15/min. A 30-second poll uses 2 of those; a 5-second poll
would not fit. The web number is not copied across because a second, looser
limiter on the API route could never be the one to refuse.

**`POST /auth/password/change`** sits outside the protected group on purpose — a
user whose password expired must be able to reach it — so it carries its own
limiter instead of inheriting the group's. `password-change` is keyed on the
authenticated user alone: the route already verifies `current_password` before
doing any work, so a refusal costs one hash, and a stolen session cannot escape
the ceiling by changing address.

The same limiter is on both web routes that can change a password:
`PUT /password/change` and `PUT /profile` (the profile form changes the password
when its password fields are filled). One bucket per user across the three
addresses, because it is one capability — limiting only the API route would
leave the web form as the way around it. A profile save that does not touch the
password still spends a request from that bucket; five a minute is well above
what the form needs.

Every limiter answers through the shared builder
(`App\Auth\LoginThrottle::responseFor()`), so every one branches on
`$request->expectsJson()`: an API client gets 429 JSON, a browser gets a
redirect with a field error.

`queue-retry` is the one exception to the field error:
`LoginThrottle::flashResponseFor()` sends a flash under `error` instead, because
the queue monitor is a vendor layout that renders `session('error')` and carries
no `$errors` bag — the standard shape would reload the page with no message on
it, i.e. a button that silently stops working. Its JSON branch is identical to
every other limiter's; the shape a machine reads cannot depend on which page a
human happened to be looking at.

`health` is the one exception, and for a reason none of the others have: its
route (`GET /api/v1/health`) has no web branch at all, so the shape is not a
question about the caller — it is always JSON. A machine that sends no
`Accept` header (an uptime monitor) would otherwise have been handed the
`back()` redirect, which a monitor that follows redirects reads as a 200 login
page and therefore as UP — the one answer a health check must not give while
it is refusing to answer.

`register` is keyed on IP alone because there is no identifier to key on — the
account does not exist yet. The form's unique-email error tells an attacker
which addresses are registered, so the limit is what caps how fast they can
collect that.

`health` is keyed on IP for the same structural reason, and there that unit is
weaker: one address can be a carrier's NAT or a corporate egress shared by
thousands of people. That is why its default is 120/min where the per-user
limiters use 3-15, and why its validated ceiling reaches 600 rather than 120.

**Every limiter reachable from the API must branch on
`$request->expectsJson()`** — or pass `jsonOnly` if it has no web branch.
A limiter that only returns `back()->withErrors()` hands an API client an HTML
302, which most clients do not treat as a failure.

## Roadmap

Not implemented. No numbers are stated here on purpose — an unshipped number
written as fact is what made the previous version of this file wrong.

- **Adaptive rate limiting** — tighten per identifier once a source shows a
  pattern of abuse, instead of a flat per-IP ceiling.
- **Captcha on public sign-up** — the only effective answer to a botnet, which
  a per-IP limit cannot touch.
- **Per-route budgets for the general API** — no `api` limiter exists. The
  protected group's `user-state-actions` (15/min per user) is the general API
  budget; per-route limiters sit on top of it only where they are tighter. See
  § *API ladder* above. `GET /api/v1/health` is the only guest API route with a
  limiter of its own (ADR-023).
- **Redis counters** — the current keying is per-node if the cache backend is
  local; Redis removes that caveat.

## Configuration precedence

When a limit can be resolved from more than one place:

1. **Endpoint-specific policy** in `RateLimiter::for()` — highest
2. **Runtime settings** (`system_settings`) — adjustable without a deploy
3. **Static config** (`config/`) — fallback
4. **Framework defaults** — lowest

Infrastructure concerns (Redis, cache driver) are never exposed through
settings.

## Redis compatibility

Rate limiting uses Laravel's `RateLimiter` facade, which works with any cache
backend — file, database or Redis. Business logic must not depend on Redis
directly; the facade is the only thing that should know.

## Separation from failed-login protection

- **Rate limiting** protects the endpoint surface — DoS and brute force at the
  transport layer.
- **Failed-login tracking** protects the account — lockout after N failures,
  scoped to identifier + IP. The per-IP `login-ip` limiter above is what sees
  one address spraying many accounts.

Separate mechanisms. Neither replaces the other.

## Concurrency / race conditions

Failed-login counter increments and lockout checks must be atomic so concurrent
login attempts cannot bypass the threshold. A `locked_until` timestamp column
gives a durable, race-safe indicator that is checked before attempt
processing.

## ADR references

- ADR-003 — database queue with Redis compatibility
- ADR-023 — a rate ceiling and a cached check set on the ungated health endpoint
