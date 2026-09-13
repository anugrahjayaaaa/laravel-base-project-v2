# User Management

## Overview

User lifecycle management includes creation, activation, deactivation, lockout, and password operations.

## Related Documents

- [Registration](./registration.md)
- [Roles & Permissions](./roles-permissions.md)
- [Authentication](../security/authentication.md)
- [Password Security](../security/password-security.md)
- [Audit Trail](./audit-trail.md)

## User State Model

Do NOT use a single status field for all account states.

Use separate concepts:

```
is_active
is_locked
email_verified_at
last_activity_at
password-related security state
```

## States

| State | Field | Description |
|-------|-------|-------------|
| Active/Inactive | `is_active` | Account lifecycle (admin deactivation) |
| Locked/Unlocked | `is_locked` | Security state (inactivity, failed login, admin lock) |
| Email verified | `email_verified_at` | Email verification (separate from activation) |
| Last activity | `last_activity_at` | Inactivity tracking |
| Password state | password-related columns | Expiration, history, forced change |

## User Operations

| Operation | Permission | Description |
|----------|-----------|-------------|
| List users | `users.view` | Paginated user list |
| View user | `users.view` | User detail |
| Create user | `users.create` | Admin creates user (generates temp password) |
| Update user | `users.update` | Edit profile/settings |
| Activate | `users.activate` | Activate deactivated account |
| Deactivate | `users.deactivate` | Deactivate account |
| Lock | `users.lock` | Lock account (security) |
| Unlock | `users.unlock` | Unlock account |
| Force password change | `users.update` | Force password change on next login |
| Reset password | `users.update` | Admin resets user password |

## Creation Flow

```
Admin creates user
  ↓
System generates temporary password
  ↓
User receives credentials + email verification link
  ↓
User MUST change password before normal application access
```

## Inactivity Policy

- Uses `last_activity_at`.
- Do NOT update on every request.
- Controlled activity mechanism (successful auth, meaningful activity, mutations).
- Configurable inactivity policy (see [Settings](./settings.md)).

## Permissions

```
users.view       (view user list/detail)
users.create     (create new user)
users.update     (update user, force password change)
users.delete     (soft delete user)
users.activate   (activate account)
users.deactivate  (deactivate account)
users.lock       (lock account)
users.unlock     (unlock account)
```