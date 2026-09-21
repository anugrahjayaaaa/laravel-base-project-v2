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
|| Admin deactivates last superadmin | — | rejected | system-role protection |
|| Soft delete | active/inactive | deleted | `users.delete` permission; preserves data |
|| Restore | deleted | active/inactive | `users.update` permission |
|| Permanent delete | deleted | — | `users.delete` permission; irreversibly removes data |

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
users.view       (view user list/detail)
users.create     (create new user)
users.update     (update user, force password change)
users.delete     (soft delete user)
users.activate   (activate account)
users.deactivate  (deactivate account)
users.lock       (lock account)
|users.unlock     (unlock account) |
```

## Planned — Restore Detail & Permanent Delete (Phase 5+)

Not yet broken down in this phase. Tracked here for reference.

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
- Shows "Page X of Y" format (no "Showing X to Y of Z")
- Layout: info text left, pagination links right, responsive wrap

### Soft-Deleted User Visual
- Trashed rows: subtle red background (`color-mix(in srgb, var(--lbp-danger) 8%, transparent)`)
- Badge: `bg-dark` with "DELETED" label on user name
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
- **Trashed user**: `badge bg-dark` "TRASHED" in header (replaces status badge)
- **Form**: Name + Email (`col-md-6` grid), Username (readonly), **Status dropdown** (only status input on page)
- **Footer**: Save Changes, `card-footer bg-light d-flex justify-content-end`

### Card 2 — Quick Actions & Security (col-lg-4)
- Unverified email warning + Resend button
- Failed Login Attempts widget
- **No status widget** — status only in form dropdown (eliminates duplication)

### Card 3 — Danger Zone (col-lg-4)
- Red-tinted card: `card-outline-danger bg-danger-subtle bg-opacity-10`
- Active: Deactivate / Soft Delete (outline danger)
- Trashed: Restore (outline success) + Permanent Delete (solid danger)
- All destructive actions → shared `#confirmModal` with `data-*` attributes