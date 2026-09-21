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
|| P4-C3 | `Web\\UserStateController` (activate/deactivate/lock/unlock — thin) | C1,C2 | DONE |
|| P4-C4 | Views: user state toggle (index + edit sidebar) | C3 | DONE |
|| P4-C5 | Route `web.php` → user state endpoints | C3 | DONE |
|| P4-C6 | Tests: activate, deactivate, lock, unlock flows + guards + API tests | C4,C5 | DONE |

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

**API Endpoint Gap:**
- Only `unlock` exists in API (`UnlockController`)
- `activate`, `deactivate`, `lock` are WEB-ONLY — no API controllers yet

**Contextual UI rules:**
- Active → show Deactivate + Lock
- Inactive → show Activate only (no Lock)
- Locked → show Unlock only (no Deactivate)
- Trashed → show Restore + Permanent Delete

## Group D — Admin User Creation + Temp Password

| ID | Task | Depends | Status |
|----|------|---------|--------|
| P4-D1 | `CreateUserByAdminAction` (generate temp password, send email) | A3 | PLANNED |
| P4-D2 | `AdminCreateUserRequest` (validation) | A3 | PLANNED |
| P4-D3 | `Web\AdminUserController` (create + store — thin) | D1,D2 | PLANNED |
| P4-D4 | View: admin create user form (AdminLTE consistent) | D3 | PLANNED |
| P4-D5 | Route `web.php` → admin user creation | D3 | PLANNED |
| P4-D6 | Tests: admin creates user, temp password enforced on first login | D4,D5 | PLANNED |

## Group E — Audit & Close-out

| ID | Task | Depends | Status |
|----|------|---------|--------|
| P4-E1 | Audit logging on user state changes (Auditable trait) | B,C,D | PLANNED |
| P4-E2 | Integration test: full user lifecycle flow (create → activate → lock → unlock → deactivate) | B,C,D | PLANNED |
| P4-E3 | Final audit: all views use @error, no magic strings, no hardcoded routes | E1,E2 | PLANNED |

---

## Architecture Rules (Phase 4+)

1. Thin controllers — all logic in Action classes.
2. Custom Form Requests — no inline `$request->validate()`.
3. Response symmetry: API → structured JSON; Web → Blade/redirect+flash.
4. Single source of truth — no business logic duplication between Web/API.
5. Audit at mutation site — controller logs directly for thin ops; Action self-logs for complex/shared logic.
6. AdminLTE consistency — existing patterns, @error validation feedback.
7. RBAC stays in Phase 6 — no Spatie Permission feature scope in Phase 4.