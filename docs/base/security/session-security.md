# Session Security

## Account Activity

`last_activity_at` is a **Login-Only Strategy** timestamp. It is updated only
after a successful Web or API login. It is not updated for ordinary mutations,
administrative actions, or every HTTP request. `NULL` represents a user who
has never logged in and is handled by the inactivity grace/threshold policy.

### Revocation Semantics

A successful password change or password reset revokes all Sanctum tokens,
all database-backed Web sessions, and clears `remember_token`. This happens in
the same database transaction as the password mutation.

A token is also time-bounded even when nobody revokes it: the lifetime is a
setting (`api_token_expire_value` + `api_token_expire_unit`, default one day),
`AppServiceProvider::bindExpirations()` binds it to
`config('sanctum.expiration')`, and Sanctum checks `created_at` against it on
every request. Shortening it therefore cuts tokens already issued.
`DELETE /api/v1/sessions/{session}` revokes a single token and is scoped to the
caller's own tokens — another account's id answers 404.

Browser sessions have the same shape and the same source: the idle window is
`web_session_expire_value` + `web_session_expire_unit` (default 120 minutes,
the same default `config/session.php` carries), bound to
`config('session.lifetime')` by the same provider method. It is read when a
session starts and when its cookie is written, so a session already in flight
keeps the window it began with until it is next used; a lowered value ends idle
sessions as they are touched, not the moment it is saved.

## Session/Device Architecture

Supported: Web + Mobile.

### Intended Behavior

- Only one active Web authentication session at a time.
- Only one active Mobile authentication session at a time.
- Web + Mobile can remain active simultaneously.

When Web B logs in:
```
Web A → revoked
Web B → active
```

When Mobile B logs in:
```
Mobile A → revoked
Mobile B → active
```

## Revocation Triggers

| Event | Action |
|-------|--------|
| Password change/reset | Revoke existing sessions/tokens |
| Account lock | Revoke existing sessions/tokens |
| Account deactivation | Revoke existing sessions/tokens |
| Logout current device | Revoke current session only |
| Logout all devices | Revoke all sessions |

## Conceptual Operations

```
login()
logout()
logoutCurrentDevice()
logoutAllDevices()
revokeClientSessions()
revokeAllSessions()
invalidateOnPasswordChange()
invalidateOnPasswordReset()
invalidateOnAccountLock()
invalidateOnAccountDeactivation()
```

- Do not use invasive hardware fingerprinting for device identification.
- Use application-generated installation/device identifiers where appropriate.
- Central authentication/session management abstraction; no scattered invalidation logic in controllers.

## ADR References

- ADR-006: Session/device strategy