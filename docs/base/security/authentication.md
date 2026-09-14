# Authentication

## Requirements

The base project must support:
- Login using username OR email
- Email verification
- Forgot password
- Password reset
- User-initiated password change
- Admin-triggered password reset
- Initial password generation during administrative user creation
- Forced password change after initial account creation
- Password expiration
- Password history
- Password policy (IM8)
- Session/token expiration
- Logout current device
- Logout all devices
- Session invalidation
- Account lock
- Account unlock
- Account activation
- Account deactivation
- Failed login tracking
- Temporary lock after excessive failed login attempts
- Rate limiting

## Key Distinctions

- **Email verification** and **account activation** are two completely different concepts. Never combine them.
- **Authentication** (who are you?) ≠ Authorization (what can you do?) ≠ Feature Availability (is this available?).

## Login Flow

```
authenticate
    ↓
password expired?
    ↓ yes
must change password
    ↓
restricted application access

    ↓ no (not expired)
normal application access
```

Password expiration enforced via middleware/application-boundary enforcement.

## Failed Login Protection

Recommended baseline (configurable):
- 5 failed attempts
- 15-minute temporary lock
- Reset counter on success

### Configuration

```
security.login.failed_attempts.enabled
security.login.failed_attempts.max_attempts
security.login.failed_attempts.lock_duration_minutes
security.login.failed_attempts.reset_on_success
```

Rate limiting protects the endpoint. Failed-login tracking protects the account. These are separate mechanisms.

## Session/Device Strategy

Conceptual operations:
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
- Central authentication/session management abstraction; no scattered
  invalidation logic in controllers.

## Session Revocation Triggers

The following events MUST revoke active sessions/tokens:

| Event | Revokes |
|-------|---------|
| Password change/reset | Existing sessions/tokens |
| Account lock | Existing sessions/tokens |
| Account deactivation | Existing sessions/tokens |
| Logout current device | Current session only |
| Logout all devices | All sessions |
| Inactivity lock | All sessions |

## Last Activity / Never-Logged-In Policy

- `last_activity_at` represents meaningful account activity (successful
  authentication, meaningful mutations).
- Do NOT update it on every HTTP request.
- A user who has **never logged in** has `last_activity_at = NULL`. This must
  be handled explicitly — the inactivity policy must define whether NULL means
  "ineligible" or "immediately eligible" by configuration, not silently
  assumed. The default recommendation is: **never-logged-in users are NOT
  subject to inactivity lock** (they have not had a chance to establish
  activity), but this must be a configurable policy.
- The inactivity process is a scheduled/background job (see
  [User Management](../features/user-management.md) §Inactivity Policy).
- The inactivity threshold is configurable: `security.inactivity.days`.