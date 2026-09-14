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
is_active              ← account lifecycle (admin deactivation)
is_locked              ← security state (inactivity, failed login, admin lock)
email_verified_at      ← email verification (separate from activation)
must_change_password   ← first-login / post-reset enforcement
password expiration    ← password lifecycle state
last_activity_at       ← meaningful activity timestamp
soft-deleted           ← deleted_at (deletion lifecycle)
```

### State Distinctions

`Active` ≠ `Unlocked`
`Unlocked` ≠ `Email Verified`
`Email Verified` ≠ `Authorized`
`Authorized` ≠ `Feature Available`

## Lifecycle Transitions

```
Create
  ↓  (email sent)
Email verification  →  email_verified_at set
  ↓
Activation           →  is_active = true
  ↓
Login                 →  auth succeeds (if not locked/inactive/deactivated)
  ↓
Activity              →  last_activity_at updated
  ↓
Lock/Unlock           →  is_locked toggles
  ↓
Deactivate/Activate   →  is_active toggles
  ↓
Password lifecycle    →  must_change_password, expiration, history
  ↓
Soft Delete/Restore   →  deleted_at set/cleared (if permitted)
```

### Transition Rules

| Trigger | From | To | Rule |
|---------|------|----|------|
| Admin deactivation | active | inactive | `users.deactivate` permission; revokes sessions |
| Admin activation | inactive | active | `users.activate` permission |
| Administrator lock | any unlocked | locked | `users.lock` permission; revokes sessions |
| Administrator unlock | locked | unlocked | `users.unlock` permission |
| Failed-login threshold | any | locked | configurable (default: 5 attempts → 15 min) |
| Inactivity timeout | active/unlocked | locked | scheduled job; revokes sessions |
| Password expires | valid | expired | configured `security.password_expiration.days`; forces change |
| Admin deactivates last superadmin | — | rejected | system-role protection |
| Soft delete | active/inactive | deleted | `users.delete` permission; preserves data |
| Restore | deleted | active/inactive | `users.update` permission |

## States

|| State | Field | Description |
||-------|-------|-------------|
|| Active/Inactive | `is_active` | Account lifecycle (admin deactivation) |
|| Locked/Unlocked | `is_locked` | Security state (inactivity, failed login, admin lock) |
|| Email verified | `email_verified_at` | Email verification (separate from activation) |
|| Last activity | `last_activity_at` | Inactivity tracking |
|| Password state | password-related columns | Expiration, history, forced change |

## Inactivity Policy

- Uses `last_activity_at`.
- Do NOT update on every request — only on meaningful activity
  (successful authentication, meaningful mutations).
- A user who has **never logged in** has `last_activity_at = NULL` and is
  subject to the same inactivity policy. Implementations must handle NULL
  explicitly (e.g., treat NULL as "ineligible for inactivity lock" OR treat
  NULL as "immediately eligible" — the policy must be chosen by configuration,
  not silently assumed). See [Authentication](../security/authentication.md) §
  Last Activity / Never-Logged-In Policy.
- Controlled by configuration: `security.inactivity.days`.
- The inactivity process is implemented as a scheduled/background job.

## User Operations

|| Operation | Permission | Description |
||----------|-----------|-------------|
|| List users | `users.view` | Paginated user list |
|| View user | `users.view` | User detail |
|| Create user | `users.create` | Admin creates user (generates temp password) |
|| Update user | `users.update` | Edit profile/settings |
|| Activate | `users.activate` | Activate deactivated account |
|| Deactivate | `users.deactivate` | Deactivate account |
|| Lock | `users.lock` | Lock account (security) |
|| Unlock | `users.unlock` | Unlock account |
|| Force password change | `users.update` | Force password change on next login |
|| Reset password | `users.update` | Admin resets user password |

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