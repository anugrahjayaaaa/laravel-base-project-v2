# Rate Limiting

## Strategy

- Use endpoint/security-sensitive rate limiting.
- Do NOT use one global limit for everything.

## Recommended Baseline (Configurable Defaults)

| Endpoint | Limit | Scope |
|----------|-------|-------|
| Login | 5 attempts/minute | per IP + identifier |
| Registration | 5/hour | per IP |
| Forgot password | 3/hour | per identifier |
| Resend verification | 3/hour | per identifier |
| Password reset | 5/hour | per IP + identifier |
| Authenticated API | 60 requests/minute | per user |
| Public API | 60 requests/minute | per IP |

## Configuration Layers

1. **Technical configuration** — belongs in config files (e.g., `config/sanctum.php`, `config/cache.php`).
2. **Operational values** — may be exposed through Settings (e.g., max login attempts, lockout duration).
3. **Infrastructure configuration** — must NOT be exposed through normal Settings.

## Redis Compatibility

- The architecture must remain Redis-compatible.
- Rate limiting should use Laravel's rate limiter with Redis driver when available.
- Database driver fallback (cache table) for environments without Redis.

## Separation from Failed-Login Protection

- **Rate limiting** protects the endpoint/request surface.
- **Failed-login tracking** protects the account.
- These are separate mechanisms.