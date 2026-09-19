# Phase 4 — User Lifecycle & User Management

> Date: 2026-09-19 | Branch: TBD | Status: PLANNED
> Purpose: hyper-detailed task breakdown for Phase 4, adhering to the
> dependency-based execution protocol (Group A → B → C → D → E sequential).
> Scope: User CRUD, Activate/Deactivate, Lock/Unlock, Admin User Creation with Temp Password.
> NOTE: RBAC/Spatie Permission stays in Phase 6 — do NOT pull into Phase 4.

---

## Existing Foundation (already in place)

- Spatie Permission ^6.0 installed (Phase 6 scope — NOT for Phase 4 use)
- Action class pattern: `App\Actions\Auth\*`
- Form Request pattern: `App\Http\Requests\Auth\*`
- `UnlockUserAction` + `UnlockUserRequest` exist (API only)
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

## Group B — User CRUD (Web UI)

| ID | Task | Depends | Status |
|----|------|---------|--------|
| P4-B1 | `UserIndexAction` (paginated list, filter/sort) | A3 | PLANNED |
| P4-B2 | `UserRequest` (filter/sort/form params) | A3 | PLANNED |
| P4-B3 | `Web\UserController` (index + update — thin) | B1,B2 | PLANNED |
| P4-B4 | `resources/views/pages/users/index.blade.php` (AdminLTE table, status badges) | B3 | PLANNED |
| P4-B5 | Route `web.php` → `users.index`, `users.update` | B3 | PLANNED |
| P4-B6 | Tests: list users, toggle user status | B4,B5 | PLANNED |

## Group C — Activate/Deactivate + Lock/Unlock (Web UI)

| ID | Task | Depends | Status |
|----|------|---------|--------|
| P4-C1 | `ActivateUserAction` + `DeactivateUserAction` | A3 | PLANNED |
| P4-C2 | `LockUserAction` + `UnlockUserAction` (extend existing API action) | A3 | PLANNED |
| P4-C3 | `Web\UserStateController` (activate/deactivate/lock/unlock — thin) | C1,C2 | PLANNED |
| P4-C4 | Views: user state toggle (reuse confirm-action modal) | C3 | PLANNED |
| P4-C5 | Route `web.php` → user state endpoints | C3 | PLANNED |
| P4-C6 | Tests: activate, deactivate, lock, unlock flows | C4,C5 | PLANNED |

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