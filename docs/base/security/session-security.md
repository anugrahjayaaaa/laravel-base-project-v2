# Session Security

## Token Strategy

- Web may use Laravel session/cookie authentication where appropriate.
- Mobile/API clients may use bearer tokens.
- Default mobile/API token expiration: 7 days (configurable).
- Session/token revocation must be possible independently from expiration.

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