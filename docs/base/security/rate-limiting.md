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
| `register` | `registration_rate_limit_per_minute` (3) | per minute | IP | 302 + errors (web) / 429 JSON |
| `forgot-password` | `password_forgot_rate_limit` (3) | per minute | identifier + IP | 302 + errors (web) / 429 JSON |
| `reset-password` | `password_reset_rate_limit` (3) | per minute | identifier + IP | 302 + errors (web) / 429 JSON |
| `resend-verification` | `email_verification_rate_limit` (5) | per hour | user id, else email | 302 + errors (web) / 429 JSON |
| `email-verification` | `email_verification_rate_limit` (5) | per hour | identifier | 302 + errors (web) / 429 JSON |
| `bulk-action` | — | per minute | user | 302 + errors |
| `user-state-actions` | — | per minute | user | 302 + errors |

`email-verification` and `resend-verification` deliberately share one setting
key: they are the same action, reachable by two routes.

`register` is keyed on IP alone because there is no identifier to key on — the
account does not exist yet. The form's unique-email error tells an attacker
which addresses are registered, so the limit is what caps how fast they can
collect that.

**Every limiter reachable from the API must branch on
`$request->expectsJson()`.** A limiter that only returns `back()->withErrors()`
hands an API client an HTML 302, which most clients do not treat as a failure.
`email-verification`, `bulk-action` and `user-state-actions` do not have the
branch — they are web-only today, so it is not a live bug, but it becomes one
the moment either is exposed.

## Roadmap

Not implemented. No numbers are stated here on purpose — an unshipped number
written as fact is what made the previous version of this file wrong.

- **Adaptive rate limiting** — tighten per identifier once a source shows a
  pattern of abuse, instead of a flat per-IP ceiling.
- **Captcha on public sign-up** — the only effective answer to a botnet, which
  a per-IP limit cannot touch.
- **Per-route budgets for the general API** — authenticated and guest API
  routes currently rely on the framework's own throttling rather than explicit
  per-endpoint limits.
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
- **Failed-login tracking** protects the account — lockout after N failures.

Separate mechanisms. Neither replaces the other.

## Concurrency / race conditions

Failed-login counter increments and lockout checks must be atomic so concurrent
login attempts cannot bypass the threshold. A `locked_until` timestamp column
gives a durable, race-safe indicator that is checked before attempt
processing.

## ADR references

- ADR-003 — database queue with Redis compatibility
