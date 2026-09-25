# Authentication

## Overview

Authentication is the process of verifying user identity. The Base Project
supports multiple authentication methods while enforcing a unified account
state model.

## Account State

Account state is represented as independent dimensions
(see ADR-005 in `decisions.md`):

- `is_active` — account exists and is usable
- `is_locked` — security lock (login attempts, admin, inactivity)
- `email_verified_at` — email verification state
- `must_change_password` — force password change on next login
- `password_expires_at` — password expiration state
- `last_activity_at` — timestamp of the last meaningful application activity
- `trashed` (soft-delete) — account is removed

`last_activity_at` represents meaningful account activity.
Do NOT update it on every HTTP request.

## Login Flow

Login accepts `identifier` (email OR username) + password.
Identifier lookup queries both `email` and `username` columns.

Both Web and API share the same authentication logic via the
`AuthenticatesUsers` trait (`findUser()` + `checkAccountState()`).
Controllers only differ in response format (JSON vs redirect).

```
Receive request (identifier + password)
  ↓
findUser() — lookup by email/username + Hash::check
  ↓
Null? → recordFailed() + return error (401 / back with error)
  ↓
checkAccountState() — is_active, is_locked
  ↓
Blocked? → return error (403 / back with error)
  ↓
Web only: email_verified_at null? → redirect to verification notice
  ↓
Success
  ↓
Reset throttle counters
  ↓
Update last_activity_at
  ↓
API: createToken + audit + return JSON
Web: Auth::login + audit + redirect to intended
```

### Failed Login

- Each failed attempt increments a counter.
- Baseline: 5 consecutive failed attempts → temporary lock for 15 minutes.
- Configurable via Settings:
  - `security.login.failed_attempts.max_attempts`
  - `security.login.failed_attempts.lockout_minutes`
- Successful login resets the failed counter.
- Administrator/security unlock supported (see ADR-005 / user-management.md).

### Security: Race Conditions

Concurrent login attempts must be guarded against race conditions
(see concurrency.md). Use distributed locking (Redis-based) on the
failed-attempt counter.

## Last Activity / Inactivity Policy

- `last_activity_at` is set to the timestamp on first successful login.
- Do NOT update on every HTTP request — only on successful authentication
  or meaningful mutations.

### Never-Logged-In Users

A user who has never performed an activity has `last_activity_at = NULL`.

- NULL is a valid, first-class state — handle it explicitly (do NOT
  substitute a default timestamp).
- NULL users ARE included in the inactivity query via the `inactivity_lock_grace_enabled` and
  `inactivity_lock_grace_days` settings (see ADR-018 in `decisions.md`).
  - When enabled, the check is: `created_at < now() - (days + grace_days)` when `last_activity_at IS NULL`
  - When disabled, the check is: `created_at < now() - days`.

### Unlocking Does NOT Set last_activity_at

Unlocking an account is an **administrative action**, not user activity.

- Unlocking must NOT populate or change `last_activity_at`.
- If `last_activity_at` was `NULL` (never logged in), it remains `NULL`
  after unlock.
- After unlocking, the user must perform an actual application action
  (successful login or meaningful mutation) before `last_activity_at`
  gets a timestamp.

See `user-management.md` (`## Account Unlock`) and ADR-018 in
`decisions.md` for the full policy.

## Session & Token Management

- Web and Mobile can be logged in concurrently.
- Within the same client type, a new login revokes the previous session:
  - New web login revokes previous web session.
  - New mobile login revokes previous mobile session.
- Web and mobile sessions may remain concurrent.

### Client Types

- `web` — cookie-based session
- `mobile` — bearer token (Sanctum)

### Revocation Triggers

Account lock, deactivation, password change, and global logout all revoke
relevant sessions/tokens. See `session-security.md`.

## Authentication Methods

- **Web**: Laravel session guard + CSRF + Sanctum SPA cookies
- **Mobile**: Sanctum bearer tokens (`api` guard)

## Authorization

Authorization decisions are delegated to Policies (see `ui-authorization.md`
and `roles-permissions.md`). Authentication is the source of truth for
identity; authorization is the source of truth for capability.

## ADR References

- ADR-005: Separate active/inactive and locked/unlocked
- ADR-006: Session/device strategy
- ADR-007: Password history strategy
- ADR-018: last_activity_at and never-logged-in policy