# Security Baseline

## Core Principles

1. **Secure by default** — security enforced at the backend/application boundary.
2. Do not rely on UI visibility as a security mechanism.
3. Input validation at trust boundaries.
4. Never expose internal details (stack traces, secrets, credentials) in responses.

## Scope

This document covers the overall security baseline. Detailed areas:

| Area | Document |
|------|----------|
| Authentication | [authentication.md](./authentication.md) |
| Authorization | [authorization.md](./authorization.md) |
| Password Security | [password-security.md](./password-security.md) |
| Session Security | [session-security.md](./session-security.md) |
| Rate Limiting | [rate-limiting.md](./rate-limiting.md) |
| Web Security | [web-security.md](./web-security.md) |
| Data Protection | [data-protection.md](./data-protection.md) |

## Security Headers

Secure defaults for:
- HTTPS
- HSTS
- Content Security Policy
- X-Content-Type-Options
- Referrer Policy
- Permissions Policy
- Frame protection (X-Frame-Options)
- Secure cookies + SameSite
- CSRF tokens
- CORS policy

Security policies must remain compatible with supported frontend architectures (Web, Vue, React, Mobile).

## Error Handling

HTTP semantics:
| Code | Meaning |
|------|---------|
| 401 | Unauthenticated |
| 403 | Authenticated but forbidden |
| 404 | Resource/feature intentionally unavailable where appropriate |

- Custom error pages for web requests.
- API errors: consistent JSON structure.
- Never expose: stack traces, secrets, internal credentials, unnecessary infrastructure details.

## Secrets

- Secrets belong in environment/deployment configuration.
- Never commit `.env`, passwords, API secrets, private keys, mail credentials, database credentials.

## Settings Validation

Settings changes must have:
- Validation
- Authorization
- Audit
- Cache invalidation where appropriate