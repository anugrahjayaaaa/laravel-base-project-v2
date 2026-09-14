# Rate Limiting

## Strategy

- Use endpoint/security-sensitive rate limiting.
- Do NOT use one global limit for everything.

## Baseline Categories

| Category | Endpoints | Default Limit | Scope |
|----------|-----------|---------------|-------|
| Login | `auth.login` | 5 attempts/minute | per IP + identifier |
| Registration | `auth.register` | 5/hour | per IP |
| Forgot password | `auth.forgot-password` | 3/hour | per identifier |
| Password reset | `auth.reset-password` | 5/hour | per IP + identifier |
| Resend verification | `auth.resend-verification` | 3/hour | per identifier |
| Authenticated API | all `auth:sanctum` routes | 60 requests/minute | per user |
| Public API | all guest routes | 60 requests/minute | per IP |
| Audit export | `audit.export` | 5/hour | per user |
| Settings change | `settings.update` | 20/hour | per user |

Endpoint-specific security sensitivity determines the limiter. Do not use a
single global limit for all endpoints.

## Configuration Precedence

When a rate-limit value can be resolved from multiple sources, the precedence
is:

1. **Endpoint-specific policy** (in code/config per endpoint category) —
   highest precedence
2. **Runtime database Settings** (e.g. `security.login.failed_attempts.max_attempts`)
   — adjustable at runtime
3. **Static application configuration** (`config/rate_limits.php`) —
   default/fallback values
4. **Laravel framework defaults** — lowest precedence

Use `config/rate_limits.php` for static/default policy. Use Settings for
runtime-adjustable values where appropriate. Infrastructure configuration
(Redis, cache driver) must NOT be exposed through Settings.

## Baseline Defaults (Configurable)

| Setting | Default | Config Key |
|---------|---------|------------|
| Login max attempts | 5/minute | `security.login.failed_attempts.max_attempts` |
| Login lock duration | 15 minutes | `security.login.failed_attempts.lock_duration_minutes` |
| Login counter reset | on success | `security.login.failed_attempts.reset_on_success` |
| Authenticated API | 60/minute | (endpoint-specific) |
| Public API | 60/minute | (endpoint-specific) |

These are Base Project defaults. They must be configurable — do not hard-code.

## Configuration Layers

1. **Technical configuration** — belongs in config files (e.g., `config/sanctum.php`, `config/cache.php`).
2. **Operational values** — may be exposed through Settings (e.g., max login attempts, lockout duration).
3. **Infrastructure configuration** — must NOT be exposed through normal Settings.

## Redis Compatibility

- The architecture must remain Redis-compatible.
- Rate limiting uses Laravel's `RateLimiter` facade, which works with any
  cache backend (file, Redis, etc.).
- Redis may be used as the high-throughput backend.
- Database cache driver fallback for environments without Redis.
- Business logic must NOT depend directly on Redis — use
  `Illuminate\Cache\RateLimiter`.

## Separation from Failed-Login Protection

- **Rate limiting** protects the endpoint/request surface (DoS, brute-force
  at the transport layer).
- **Failed-login tracking** protects the account (account lockout after N
  failed attempts).
- These are separate mechanisms. Rate limiting does NOT replace failed-login
  tracking, and failed-login tracking does NOT replace rate limiting.

## Concurrency / Race-Condition Handling

Failed-login counter increments and lockout checks must be atomic to prevent
concurrent login attempts from bypassing the threshold. Use database-level
locking or atomic increment operations. A `locked_until` timestamp column
provides a durable, race-safe lockout indicator that is checked before
attempt processing.

## ADR References

- ADR-003: Database queue with Redis compatibility