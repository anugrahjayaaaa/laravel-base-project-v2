# Web Security

## Secure Defaults

| Header | Recommended Value |
|--------|------------------|
| HTTPS | Enforce (redirect HTTP to HTTPS) |
| HSTS | `max-age=31536000; includeSubDomains; preload` |
| Content-Security-Policy | Strict, allowlist only |
| X-Content-Type-Options | `nosniff` |
| Referrer-Policy | `strict-origin-when-cross-origin` |
| Permissions-Policy | Restrictive defaults |
| X-Frame-Options | `DENY` or `SAMEORIGIN` (frame protection) |
| Cookie Secure | `true` |
| Cookie SameSite | `Lax` or `Strict` |
| CSRF | Laravel Sanctum/VerifyCsrfToken middleware |
| CORS | Restrict to known origins |

## CSRF Protection

- Laravel's built-in CSRF tokens via `VerifyCsrfToken` middleware.
- API routes use `api` middleware group (stateless) or Sanctum SPA tokens.
- Web routes require CSRF token for all state-changing requests.

## CORS Policy

- Configured via `fruitcake/laravel-cors` or native Laravel CORS.
- Restrict to known frontend origins.
- Do not use wildcard `*` for credentials-enabled endpoints.

## Frontend Compatibility

Security policies must remain compatible with:
- Blade server-rendered pages
- Vue SPA
- React SPA
- Mobile applications

## Security Audit

All security-relevant changes must be reviewed:
- Input validation at trust boundaries
- Authorization checks (Policy-based)
- SQL injection prevention (Eloquent/query builder)
- XSS prevention (escaping in views)
- Session fixation prevention
- Password hashing (bcrypt/Argon2)
- Secret management (never in code)