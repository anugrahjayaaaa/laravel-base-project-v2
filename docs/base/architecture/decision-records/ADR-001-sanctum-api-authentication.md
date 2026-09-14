# ADR-001: Sanctum for API Authentication

- **Status**: Accepted
- **Category**: Dependency Selection
- **Architecture Area**: Authentication (Phase 3 `AUTH-002`, Phase 1 `FOUND-004`)

## Context

The Base Project requires API authentication for web, mobile, and future
client channels. Options include Laravel Passport (full OAuth2 server),
Sanctum (lightweight API tokens + SPA auth), or a custom token implementation.

## Decision

Use `laravel/sanctum` for API authentication infrastructure.

## Alternatives Considered

- **Laravel Passport**: Full OAuth2 authorization server. Appropriate when
  the application must act as an OAuth2 identity provider for third parties.
  Rejected — the Base Project does not need third-party OAuth2 delegation;
  it needs bearer-token auth for its own API consumers. Passport adds
  substantial infrastructure overhead.
- **Custom token implementation**: Reinventing token hashing, revocation,
  and expiration is error-prone and unnecessary. The community has not
  standardized on a custom approach for this.
- **Laravel Fortify**: Provides backend auth scaffolding (login, registration,
  password reset) but not API tokens. Would need Sanctum or Passport for
  tokens anyway. Not selected as a token solution.

## Why This Decision

Sanctum provides:
- Light API token issuance and revocation
- SPA cookie-based session auth
- Token `abilities` scoping
- Integration with Laravel's native `auth:sanctum` middleware

It is the right tool for a foundation project that needs bearer-token
authentication without OAuth2 server complexity.

## Consequences

- `personal_access_tokens` table migration required at Phase 1.
- Application-layer security policies (account lock, session invalidation,
  logout-all-devices) remain in the Base Project — Sanctum does not handle
  these.
- Sanctum handles token infrastructure; the application handles token
  lifecycle policy.

## Security Implications

- Tokens must be transmitted over TLS.
- Bearer tokens must never appear in logs (handled by logging privacy rules).
- Token revocation must be explicit — Sanctum does not auto-revoke on
  password change; the application must call `revoke()` or delete tokens.

## Maintenance Implications

- Sanctum version must track the Laravel framework version.
- Minor Sanctum releases may introduce migration changes.
- Upgrade alongside Laravel framework upgrades.

## Reversal / Replacement

- To replace Sanctum: swap `auth:sanctum` middleware to a custom guard,
  replace `Sanctum::actingAs()` in tests with a custom token guard, and
  reimplement token issuance/revocation.
- An application-level Auth/Session Service abstraction isolates most
  application code from Sanctum's API.
