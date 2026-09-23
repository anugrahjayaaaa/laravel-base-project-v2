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
| `is_active`              ← account lifecycle (admin deactivation)
| `is_locked`              ← security state (inactivity, failed login, admin lock)
| `email_verified_at`      ← email verification (separate from activation)
| `must_change_password`   ← first-login / post-reset enforcement
| `password expiration`    ← password lifecycle state
| `last_activity_at`       ← meaningful activity timestamp
| `soft-deleted`           ← deleted_at (deletion lifecycle)

Primary status resolution: `App\Enums\UserStatusEnum::resolve()` — precedence: PENDING_VERIFICATION → LOCKED → INACTIVE → ACTIVE.
```

### State Distinctions

`Active` ≠ `Unlocked`
`Unlocked` ≠ `Email Verified`
`Email Verified` ≠ `Authorized`
`Authorized` ≠ `Feature Available`

### Two Lock Mechanisms — CRITICAL DISTINCTION

| Mechanism | Storage | Auto-expires | Toggles `is_locked` |
|-----------|---------|-------------|---------------------|
| Admin Lock | `users.is_locked = true` | No (manual unlock) | Yes |
| Failed-login lockout | Cache + `failed_login_attempts.locked_until` | Yes | No |
| Inactivity lock | `users.is_locked = true` | No | Yes |

`users.is_locked` is ONLY set by admin actions (LockUserAction). Failed-login brute-force protection is 100% handled by the throttle layer — it never touches `users.is_locked`.

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
| Soft Delete/Restore   | deleted_at set/cleared (is_active unchanged) |
```

### Transition Rules

|| Trigger | From | To | Rule |
|---------|------|----|------|
|| Admin deactivation | active | inactive | `users.deactivate` permission; blocked if `is_locked` (must unlock first) |
|| Admin activation | inactive | active | `users.activate` permission |
|| Admin lock | active/unlocked | locked | `users.lock` permission; blocked if `!is_active` (must activate first) |
|| Admin unlock | locked | unlocked | `users.unlock` permission |
|| Failed-login threshold | any | throttle lock | configurable (default: 5 attempts → 15 min); cache + DB, does NOT touch `is_locked` |
|| Inactivity timeout | active/unlocked | locked | scheduled job; sets `is_locked` |
|| Password expires | valid | expired | configured `security.password_expiration.days`; forces change |
||| Admin deactivates last superadmin | — | rejected | system-role protection |
||| Soft delete | active/inactive | deleted | `users.delete` permission; does NOT set `is_active = false` |
||| Restore | deleted | active/inactive | `users.update` permission |
||| Permanent delete | deleted | — | `users.delete` permission; irreversibly removes data |

## States

|| State | Field | Description |
|-------|-------|-------------|
| Active/Inactive | `is_active` | Account lifecycle (admin deactivation) |
| Locked | `is_locked` | Admin security intervention only |
| Throttle lock | Cache + `failed_login_attempts.locked_until` | Auto-expiring brute-force protection |
| Email verified | `email_verified_at` | Email verification (separate from activation) |
| Last activity | `last_activity_at` | Inactivity tracking |
| Password state | password-related columns | Expiration, history, forced change |

## Inactivity Policy

- Uses `last_activity_at`.
- Do NOT update on every request — only on meaningful activity
  (successful authentication, meaningful mutations).
- A user who has **never logged in** has `last_activity_at = NULL` and is
  included in the inactivity query via the `security.inactivity.grace_days`
  configuration (see [Settings](../features/settings.md) §Inactivity Policy
  and [Authentication](../security/authentication.md) §Last Activity /
  Never-Logged-In Policy).
- **Unlocking does NOT set `last_activity_at`** — unlocking is an
  administrative action, not user activity. `last_activity_at` is preserved
  as-is (NULL if never active, or the prior value) until the user performs
  an actual application action.
- Controlled by configuration: `security.inactivity.enabled`,
  `security.inactivity.days`, `security.inactivity.grace_days`.
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
|users.view       (view user list/detail)
|users.create     (create new user)
|users.update     (update user, force password change)
|users.delete     (soft delete user)
|users.activate   (activate account)
|users.deactivate  (deactivate account)
|users.lock       (lock account)
|users.unlock     (unlock account) |
```

## API Endpoints — User State (Group C)

|| Method | Endpoint | Exists | Description |
||--------|----------|--------|-------------|
||| POST | `/api/v1/users/{user}/activate` | YES | `UserStateController@activate` |
||| POST | `/api/v1/users/{user}/deactivate` | YES | `UserStateController@deactivate` |
||| POST | `/api/v1/users/{user}/lock` | YES | `UserStateController@lock` |
||| POST | `/api/v1/users/{user}/unlock` | YES | `UserStateController@unlock` |
||| POST | `/api/v1/auth/unlock` | YES (legacy redirect) | redirects to `api.v1.users.unlock` |

All state endpoints return JSON `{ data: { message }, meta: { request_id, timestamp } }`.

**Force Logout on State Change:**
- `deactivate`, `lock`, `delete` → revoke ALL sessions (web `sessions` table + API Sanctum tokens)
- `activate`, `unlock` → does NOT revoke sessions (user stays authenticated)

### Namespace Fix (Phase 4C)

`UnlockUserAction` moved from `App\Actions\Auth\` → `App\Actions\User\` for consistency. All state actions now live in `App\Actions\User\`.

`UnlockController` (`Api\V1\Auth\`) removed — `unlock` method merged into `Api\V1\User\UserStateController`. Backwards-compat route `/api/v1/auth/unlock` redirects to `api.v1.users.unlock`.

## Username, Email Change & System Settings (Phase 4)

### Schema Additions (`users` table)

|| Column | Type | Description |
||--------|------|-------------|
|| `username` | string, unique | Login identifier (added earlier) |
|| `username_changed_at` | timestamp nullable | Last username change timestamp |
|| `email_changed_at` | timestamp nullable | Last email change timestamp |
|| `pending_email` | string nullable | New email awaiting verification |

### System Settings (`system_settings` table)

|| Key | Type | Default | Description |
||-----|------|---------|-------------|
|| `allow_username_change` | boolean | true | Toggle username change feature |
|| `allow_email_change` | boolean | true | Toggle email change feature |
|| `username_change_cooldown_days` | integer | 30 | Days before username can be changed again |
|| `email_change_cooldown_days` | integer | 30 | Days before email can be changed again |

### Email Change Verification Flow

1. Admin/user requests email change → `pending_email` + `email_change_token` + `email_change_token_expires_at` set
2. Signed email sent to **new** address (`pending_email`) with 24h expiry
3. User clicks link → `email` ← `pending_email`, `email_changed_at = now()`, `email_verified_at = now()`
4. All sessions + API tokens revoked on successful change
5. Cancel request clears `pending_email`

### Model Helpers

- `User::canChangeUsername()` — checks `allow_username_change` setting + cooldown
- `User::canChangeEmail()` — checks `allow_email_change` setting + cooldown

### Dual Login Support

Login accepts `email` OR `username` via `User::where('email', $id)->orWhere('username', $id)->first()`.

### Design System Compliance

All user management views follow `docs/base/ui/design-system.md` page skeleton templates.

### Restore Detail
- Dedicated restore flow with confirmation modal (warning variant)
- Restored user returns to previous status (active/inactive)
- Audit log entry on restore
- Notification to restored user (optional)

### Permanent Delete
- Only available from trash state (never from active)
- Double-confirmation: first modal (warning), second modal (danger)
- Audit log entry on permanent delete
- Associated data handling: cascade vs restrict (TBD per relationship)

## UI Patterns — User Index (Phase 4)

### Pagination
- Shows "Showing X to Y of Z entries" format
- Layout: info text left, pagination links right, responsive wrap

### Soft-Deleted User Visual
- Trashed rows: subtle red background (`color-mix(in srgb, var(--lbp-danger) 8%, transparent)`)
- Badge: `bg-danger text-white` with "DELETED" label on user name
- Row opacity maintained at full (background tint provides distinction)

### Permanent Delete Flow (Index)
- Trashed users show: Restore button (info variant) + Permanent Delete button (danger variant)
- Both trigger the shared `#confirmModal` with appropriate variant/message
- Permanent Delete uses `ForceDeleteUserAction` via `users.force-delete` route

## UI Patterns — Edit User (Phase 4)

### Layout
- Two-column grid: `col-lg-8` (form) + `col-lg-4` (sidebar)

### Card 1 — Profile Information (col-lg-8)
- **Header**: Avatar initials + Name + "Registered YYYY-MM-DD" metadata
- **Trashed user**: `badge bg-danger text-white` "TRASHED" in header (replaces status badge)
- **Form**: Name + Email (`col-md-6` grid), Username (readonly), **Status dropdown** (only status input on page)
- **Footer**: Save Changes, `card-body d-flex justify-content-end`

### Card 2 — Quick Actions & Security (col-lg-4)
- Unverified email warning + Resend button
- Failed Login Attempts widget
- **No status widget** — status only in form dropdown (eliminates duplication)

### Card 3 — Danger Zone (col-lg-4)
- Red-tinted card: `card-outline-danger bg-danger-subtle bg-opacity-10`
- Active: Soft Delete only (outline danger)
- Trashed: Restore (outline success) + Permanent Delete (solid danger)
- All destructive actions → shared `#confirmModal` with `data-*` attributes