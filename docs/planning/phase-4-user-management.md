# Phase 4 — User Lifecycle & User Management

> Date: 2026-09-19 | Branch: TBD | Status: PLANNED
> Purpose: hyper-detailed task breakdown for Phase 4, adhering to the
> dependency-based execution protocol (Group A → B → C → D → E sequential).
> Scope: User CRUD, Activate/Deactivate, Lock/Unlock, Admin User Creation with Temp Password.
> NOTE: RBAC/Spatie Permission stays in Phase 6 — do NOT pull into Phase 4.

---

## Existing Foundation (already in place)

- Spatie Permission ^6.0 installed (Phase 6 scope — NOT for Phase 4 use)
- Action class pattern: `App\Actions\Auth\*` + `App\Actions\User\*`
- Form Request pattern: `App\Http\Requests\Auth\*` + `App\Http\Requests\User\*`
- `UnlockUserAction` + `UnlockUserRequest` exist (API only)
- `ChangePassword` action exists — reuse for user password updates
- `EnsurePasswordChangeRequired` middleware — enforces `must_change_password`
- No Filament — pure Blade + AdminLTE
- Base database roles/user traits initialized in Phase 2/3 only

## Group A — Foundation ✅ DONE

| ID | Task | Depends | Status |
|----|------|---------|--------|
| P4-A1 | User model audit — verify `is_active`, `is_locked`, `email_verified_at`, `must_change_password`, `last_activity_at` columns exist | — | DONE |
| P4-A2 | Soft-delete convention audit (users table `deleted_at`) | A1 | DONE |
| P4-A3 | Auth state constants/config (no magic strings for `is_active`, `is_locked`) | A1 | DONE |

**Deliverables:** `App\Enums\UserStatusEnum` (ACTIVE/INACTIVE/LOCKED/PENDING_VERIFICATION), User model `getStatus()` + helpers + scopes, `tests/Unit/Actions/UserLifecycleFoundationTest.php` (19 pass).

**Gate:** A verified + tested. Proceed to Group B.

## Group B — User CRUD (Web UI) ✅ DONE

|| ID | Task | Depends | Status |
||----|------|---------|--------|
|| P4-B1 | `UserIndexAction` (paginated list, filter/sort) + counts() conditional aggregation + forever cache | A3 | DONE |
|| P4-B2 | `UserRequest` (filter/sort/form params) | A3 | DONE |
|| P4-B3 | `Web\UserController` (index + update — thin) | B1,B2 | DONE |
|| P4-B4 | `resources/views/pages/users/index.blade.php` (AdminLTE table, status badges) | B3 | DONE |
|| P4-B5 | Route `web.php` → `users.index`, `users.update` | B3 | DONE |
|| P4-B6 | Tests: list users, toggle user status | B4,B5 | DONE |
|| P4-B7 | Soft Delete: `DeleteUserAction` — deactivate + soft delete | A3 | DONE |
|| P4-B8 | Restore: `RestoreUserAction` — undo soft delete | B7 | DONE |
|| P4-B9 | Permanent Delete: `ForceDeleteUserAction` — force delete | B7 | DONE |
|| P4-B10 | Detail/Edit View: `ShowUserAction` + edit form | B1 | DONE |
|| P4-B11 | Resend verification email (admin mode trigger) | B10 | DONE |

## Group C — Activate/Deactivate + Lock/Unlock (Web UI) ✅ DONE

|| ID | Task | Depends | Status |
|----|------|---------|--------|
|| P4-C1 | `ActivateUserAction` + `DeactivateUserAction` | A3 | DONE |
|| P4-C2 | `LockUserAction` + `UnlockUserAction` (moved to User namespace) | A3 | DONE |
||| P4-C3 | `Web\\UserStateController` + `Api\\V1\\User\\UserStateController` (activate/deactivate/lock/unlock — thin) | C1,C2 | DONE |
||| P4-C4 | Views: user state toggle (index + edit sidebar) | C3 | DONE |
||| P4-C5 | Route `web.php` + `api.php` → user state endpoints | C3 | DONE |
||| P4-C6 | Tests: activate, deactivate, lock, unlock flows + guards + API tests + rate limiter | C4,C5 | DONE |

### State Design — Two Flags with Guards (Option B)

`is_active` and `is_locked` are separate boolean flags with distinct lifecycles:

| Flag | Purpose | Trigger | Reversed By |
|------|---------|---------|-------------|
| `is_active` | Account lifecycle (deactivate/suspend) | Admin action | Activate |
| `is_locked` | Security intervention (admin lock) | Admin action | Unlock |

**Business Guards:**
- Deactivate blocked if `is_locked = true` — must unlock first
- Lock blocked if `is_active = false` — must activate first
- These guards prevent contradictory states (locked+inactive)

**Status precedence** (`UserStatusEnum::resolve`):
1. PENDING_VERIFICATION (email null)
2. LOCKED (is_locked = true) — overrides inactive
3. INACTIVE (is_active = false)
4. ACTIVE (default)

**Design tokens (badge colors):**
- ACTIVE: `bg-success-subtle text-success border border-success-subtle`
- INACTIVE: `bg-secondary-subtle text-secondary border border-secondary-subtle`
- LOCKED: `bg-warning-subtle text-warning border border-warning-subtle`
- TRASHED: `bg-danger text-white`

**Session Invalidation (WEB + API) — Force Logout Specs:**
- Deactivating or locking a user INVALIDATES all active sessions (force logout)
- **WEB Layer**: Delete session records from `sessions` table where `user_id`
- **API Layer**: Revoke all Sanctum tokens via `$user->tokens()->delete()` → subsequent API requests return `401 Unauthorized`
- Activating/unlocking does NOT invalidate sessions — user stays logged in
- Applies to both web and API layers when user is currently authenticated

**API Endpoints (all unified under `Api\V1\User\UserStateController`):**
- `POST /api/v1/users/{user}/activate` — `UserStateController@activate`
- `POST /api/v1/users/{user}/deactivate` — `UserStateController@deactivate`
- `POST /api/v1/users/{user}/lock` — `UserStateController@lock`
- `POST /api/v1/users/{user}/unlock` — `UserStateController@unlock`
- Backwards-compat: `/api/v1/auth/unlock` redirects to `api.v1.users.unlock`

**Contextual UI rules:**
- Active → show Deactivate + Lock
- Inactive → show Activate only (no Lock)
- Locked → show Unlock only (no Deactivate)
- Trashed → show Restore + Permanent Delete

## Group D — Admin User Creation + Temp Password ✅ DONE

| ID | Task | Depends | Status |
|----|------|---------|--------|
| P4-D1 | `CreateUserAction` (generate temp password, send email) | A3 | DONE |
| P4-D2 | `CreateUserRequest` (validation) | A3 | DONE |
| P4-D3 | `Web\\UserController` (create + store — thin) | D1,D2 | DONE |
| P4-D4 | View: admin create user form (AdminLTE consistent) | D3 | DONE |
| P4-D5 | Route `web.php` → admin user creation | D3 | DONE |
| P4-D6 | Tests: admin creates user, temp password enforced on first login | D4,D5 | DONE |

**Note:** `CreateUserAction` (renamed from planned `CreateUserByAdminAction` — no conflict expected).
API `POST /api/v1/users` shares same Action + Request (single source of truth).

**Gaps → Phase 6 (RBAC):**
- API user CRUD: only `POST /api/v1/users` (create) exists. list/show/edit/update (soft delete + permanent delete) not implemented — needed for RBAC permission management
- Authorization middleware (`can:users.create`) on create/store — permission defined in user-management.md, not enforced yet

## Group E — API User CRUD (Read, Update, Delete, Restore) ✅ DONE

| ID | Task | Depends | Status |
|----|------|---------|--------|
| P4-E1 | API endpoint: list users (GET /api/v1/users) | D | PLANNED |
| P4-E2 | API endpoint: show user (GET /api/v1/users/{user}) | D | PLANNED |
| P4-E3 | API endpoint: update user (PUT/PATCH /api/v1/users/{user}) | D | PLANNED |
| P4-E4 | API endpoint: soft delete user (DELETE /api/v1/users/{user}) | D | PLANNED |
| P4-E5 | API endpoint: permanent delete (DELETE /api/v1/users/{user}/force) | D | PLANNED |
| P4-E6 | API endpoint: restore soft-deleted user (POST /api/v1/users/{id}/restore) | D | PLANNED |
| P4-E7 | Tests: API CRUD operations + soft delete / restore flows | E1-E6 | PLANNED |

**Note:** API user CRUD only. WEB UI CRUD already done (Group B).
Authorization via RBAC (Phase 6) — permission gates applied per endpoint.

## Group F — Username/Email Change + System Settings + Email Verification 🔄 ON PROGRESS

|| ID | Task | Depends | Status |
|----|------|---------|--------|
| P4-F1 | Migration: `username_changed_at`, `email_changed_at`, `pending_email`, `email_change_token`, `email_change_token_expires_at` on users; `system_settings` table | D | DONE |
| P4-F2 | Model: User `canChangeUsername()`/`canChangeEmail()`, SystemSetting model | F1 | DONE |
| P4-F3 | Actions: CreateUserAction (username), UpdateUserAction (cooldown + email flow) | F1,F2 | DONE |
| P4-F4 | Requests: CreateUserRequest (username), UpdateUserRequest (cooldown guard), EmailChangeRequest | F2 | DONE |
| P4-F5 | Notification: ChangeEmailVerificationNotification (signed URL, 24h expiry) | F1,F2 | DONE |
| P4-F6 | Controller: requestEmailChange, cancelEmailChange, verifyEmailChange, resendVerification | F3,F4,F5 | DONE |
| P4-F7 | Routes: users.request-email-change, users.cancel-email-change, email.verify-change | F6 | DONE |
| P4-F8 | Views: create (username @input), edit (cooldown badges, pending email callout) | F6 | DONE |
| P4-F9 | Tests: UsernameEmailChangeTest (7 tests, all pass) | F1-F8 | DONE |
| P4-F10 | System Settings UI: allow_username_change, allow_email_change, cooldown days | F1 | DONE |
| P4-F11 | Docs: user-management.md + progress.md Phase 4F update | F9 | DONE |

### Phase 4F Additions (from user request)

| ID | Task | Depends | Status |
|----|------|---------|--------|
| P4-F12 | UI: SystemSetting-gated username/email fields — show "Username can be changed" text when enabled, disable input when not | F2,F10 | DONE |
| P4-F13 | UI: disable username/email input during cooldown period | F2 | DONE |

## Group G — Self-Service Profile Page ✅ DONE

|| ID | Task | Depends | Status |
||----|------|---------|--------|
|| P4-G1 | Profile page: user updates own username/email (if enabled) + change password | F12,F13 | DONE |
|| P4-G2 | Route + controller: profile edit/update | G1 | DONE |
|| P4-G3 | View: profile form (AdminLTE consistent) | G1 | DONE |

## Group H — Bulk Actions + Audit Close-out

| ID | Task | Depends | Status |
|----|------|---------|--------|
| P4-H1 | Bulk actions: soft delete, force delete (trashed only), lock/unlock, activate/deactivate | B,C,D,F | DONE |
| P4-H2 | Audit logging on user state changes (Auditable trait) | B,C,D,E,F,G | DONE |
| P4-H3 | Integration test: full user lifecycle flow (create → activate → lock → unlock → deactivate) | B,C,D,E,F,G | PLANNED |
| P4-H4 | Final audit: all views use @error, no magic strings, no hardcoded routes | H2,H3 | PLANNED |

---

## Architecture Rules (Phase 4+)

1. Thin controllers — all logic in Action classes.
2. Custom Form Requests — no inline `$request->validate()`.
3. Response symmetry: API → structured JSON; Web → Blade/redirect+flash.
4. Single source of truth — no business logic duplication between Web/API.
5. Audit at mutation site — controller logs directly for thin ops; Action self-logs for complex/shared logic.
6. AdminLTE consistency — existing patterns, @error validation feedback.
7. RBAC stays in Phase 6 — no Spatie Permission feature scope in Phase 4.