# Task Tracker

> Machine-readable + human-readable task tracker. All tasks start in `PLANNED` status. Only mark DONE after verification.

## Status Legend

| Status | Meaning |
|--------|---------|
| PLANNED | Task defined, not ready to implement |
| READY | Dependencies met, can be picked up |
| IN_PROGRESS | Actively being worked |
| BLOCKED | Cannot proceed (dependency or blocker) |
| REVIEW | Implementation done, awaiting review |
| DONE | Verified complete |
| CANCELLED | No longer needed |

## Columns

| Column | Description |
|--------|-------------|
| ID | Stable unique identifier |
| Task | Short description |
| Phase | Implementation phase |
| Priority | P0/P1/P2/P3 |
| Depends On | Blocking task IDs |
| Status | Current lifecycle status |
| Acceptance Criteria | What "done" looks like |
| Tests | Test scenarios required |
| Docs | Documentation file(s) to update |
| Security Impact | Security relevance |
| Notes | Additional context |

## Tasks

### Phase 0 — Architecture & Conventions

| ID | Task | Phase | Priority | Depends On | Status |
|----|------|-------|----------|-----------|--------|
| P0-001 | Define architecture principles | 0 | P0 | — | DONE |
| P0-002 | Define naming conventions | 0 | P0 | — | DONE |
| P0-003 | Define folder structure | 0 | P0 | — | DONE |
| P0-004 | Define dependency rules | 0 | P0 | — | DONE |
| P0-005 | Define ADR list (12 initial) | 0 | P0 | — | DONE |
| P0-006 | Create documentation structure | 0 | P0 | — | DONE |
| P0-007 | Create AI execution guide | 0 | P0 | — | DONE |
| P0-008 | Create task tracker | 0 | P0 | — | DONE |
| P0-009 | Create QA tracker | 0 | P0 | — | DONE |
| P0-010 | Create implementation roadmap | 0 | P0 | — | DONE |
| P0-011 | Set up Definition of Done | 0 | P0 | — | DONE |

### Phase 1 — Foundation & Environment

| ID | Task | Phase | Priority | Depends On | Status |
|----|------|-------|----------|-----------|--------|
| FOUND-001 | Initialize Laravel 13 project | 1 | P0 | P0-002 | DONE |
|| FOUND-002 | Configure .env / .env.example | 1 | P0 | FOUND-001 | DONE |
|| FOUND-003 | Set up config files (auth, cache, queue, session, mail, logging) | 1 | P0 | FOUND-001 | DONE |
|| FOUND-004 | Install Sanctum for API auth | 1 | P0 | FOUND-001 | DONE |
|| FOUND-005 | Install Spatie Permission (RBAC) | 1 | P0 | FOUND-001 | DONE |
|| FOUND-006 | Install audit package (e.g. spatie/laravel-activitylog) | 1 | P0 | FOUND-001 | DONE |
|| FOUND-007 | Install Telescope | 1 | P0 | FOUND-001 | DONE |
| FOUND-008 | Create correlation/request ID middleware | 1 | P0 | FOUND-001 | DONE |
| FOUND-009 | Set up PSR-12 linting (PHP CS Fixer) | 1 | P1 | FOUND-001 | DONE |
| FOUND-010 | Configure health check endpoint | 1 | P1 | FOUND-001 | DONE |
| CACHE-001 | Define cache config | 1 | P1 | FOUND-003 | DONE |
| QUEUE-001 | Configure queue (database + Redis compat) | 1 | P0 | FOUND-003 | DONE |
| CACHE-002 | Implement cache tagging & invalidation | 1 | P1 | CACHE-001 | PLANNED |
| UI-001 | Vendor AdminLTE 4.9.1 + wire Blade layout | 1 | P1 | FOUND-001 | DONE |
| UI-002 | Application shell (header, sidebar, footer, theme toggle) | 1 | P1 | UI-001 | DONE |
| UI-003 | Shared UI component conventions (buttons, forms, tables, modals) | 1 | P1 | UI-001 | DONE |
| UI-004 | Reusable confirmation modal component | 1 | P1 | UI-002 | DONE |
| UI-005 | Theme toggle (system default + manual dark/light) | 1 | P1 | UI-001 | DONE |
| UI-006 | UI foundation cleanup & style refinement (partials rename, theme fix, i18n removal, style guide) | 1 | P1 | UI-001,UI-002,UI-003,UI-004,UI-005 | DONE |
| SOFT-001 | Soft delete / trash / permanent delete convention | 1 | P1 | FOUND-002 | DONE |
| TABLE-001 | Shared table conventions (sortable, filterable, bulk actions, pagination) | 1 | P2 | UI-003 | DONE |
|| FLAG-001 | Feature flag package foundation | 1 | P1 | FOUND-003 | DONE |

**Audit pattern (cross-phase convention):** All mutations log audit at the mutation
site — Action self-logs when logic is complex/shared; Controller logs directly
(using `$this->audit()` helper on base Controller) for thin operations. No model
observers for audit. See `docs/base/architecture/application-components.md` §Action/Service.

---

### Phase 4 — User Lifecycle & User Management

|| ID | Task | Phase | Priority | Depends On | Status |
||----|------|-------|----------|-----------|--------|
|| USER-001 | Create user management module | 4 | P1 | AUTH-003 | DONE |
|| USER-002 | Implement user list/detail API | 4 | P1 | USER-001 | DONE |
|| USER-003 | Implement create user (admin) | 4 | P1 | AUTH-001 | DONE |
|| USER-004 | Implement update user | 4 | P1 | USER-002 | DONE |
|| USER-005 | Implement soft delete user | 4 | P2 | USER-001 | DONE |
|| USER-006 | Implement activate/deactivate | 4 | P1 | USER-003 | DONE |
|| USER-007 | Implement lock/unlock | 4 | P1 | USER-001 | DONE |
|| USER-008 | Implement force password change | 4 | P1 | AUTH-014 | DONE |
|| USER-009 | Implement admin reset password | 4 | P1 | AUTH-013 | DONE |

*(Task list truncated for phases 2-17. See full list in the JSON version below.)*

---

### Phase 6 — RBAC & Authorization

| ID | Task | Phase | Priority | Depends On | Status |
|----|------|-------|----------|-----------|--------|
| RBAC-001 | Seed roles (superadmin, admin, user) | 6 | P0 | DB-002 | DONE |
| RBAC-002 | Implement role management | 6 | P0 | RBAC-001 | DONE — C1 (P6-C1..C6 + soft delete + bulk), C2 (catalogue), C3 (assignment sync), C4 (ungated `authorize()` returns). Gate C met; role write routes still lack a route-level `can:` (defence in depth, P6-D1) |
| RBAC-003 | Implement permission management | 6 | P0 | RBAC-001 | DONE — catalogue seeded by Group B (`PermissionSeeder`, prune-then-create), read-only web page by C2 (P6-C7/C8). No create/edit UI, by design: a permission row no `can()` references grants nothing |
| RBAC-004 | Define permission set | 6 | P0 | P0-004 | DONE — `PermissionCatalog` (19), `PermissionSeeder` |
| RBAC-005 | Superadmin + system-role protection | 6 | P0 | RBAC-001 | IN PROGRESS — `Gate::before`, the seeder role, the C6 delete/rename refusals, and the C11 last-superadmin assignment guard are all DONE. Remaining is E4 (delete / deactivate the last superadmin *user*) and E5 (`confirm_superadmin` on grant) |
| RBAC-006 | Gate /users and /settings behind permissions | 6 | P0 | RBAC-004 | IN PROGRESS — the web reads and the user writes are gated (C2 reads, C14–C18 the form requests, `UserPolicy`). **Still open:** the seven ungated user state routes and `api.v1.settings.index` (P6-D1/D2), plus the D-group button `can:` gates and the E9 pentest replay |
| P6-A1 | Roles index view | 6 | P0 | — | DONE |
| P6-A2 | Roles index actions column | 6 | P0 | P6-A1 | DONE |
| P6-A3 | Roles create view | 6 | P0 | P6-A1 | DONE |
| P6-A4 | Roles edit view | 6 | P0 | P6-A3 | DONE |
| P6-A5 | Permission matrix partial | 6 | P0 | P6-A3 | DONE |
| P6-A6 | Permissions catalogue view | 6 | P0 | P6-A1 | DONE |
| P6-A7 | `delete_role` confirm-modal key | 6 | P0 | P6-A2 | DONE |
| P6-A8 | AppMenuComposer — no change in Group A | 6 | P0 | — | DONE |
| P6-A9 | Gate test `RbacUiRenderTest` | 6 | P0 | P6-A1..A7 | DONE |
| P6-B1 | `PermissionSeeder` | 6 | P0 | P6-B3, P6-B4 | DONE |
| P6-B2 | Register seeder in `DatabaseSeeder` | 6 | P0 | P6-B1 | DONE |
| P6-B3 | `App\Support\PermissionCatalog` | 6 | P0 | P6-B1 | DONE |
| P6-B4 | `SystemRole` (shipped in Group A) | 6 | P0 | P6-A1 | DONE |
| P6-B5 | `RoleSeeder` uses `SystemRole::names()` | 6 | P0 | P6-B4 | DONE |
| P6-B6 | `Gate::before` superadmin bypass | 6 | P0 | P6-B1 | DONE |
| P6-B7 | `PermissionSeedTest` (17 tests) | 6 | P0 | P6-B1, B5, B6 | DONE |
| P6-B8 | `PermissionCacheTest` (5 tests) | 6 | P0 | P6-B7 | DONE |
| P6-C1 | `StoreRoleRequest` — `roles.create`, guard-scoped unique | 6 | P0 | P6-B3 | DONE |
| P6-C2 | `UpdateRoleRequest` — `roles.update`, unique ignoring `$role` | 6 | P0 | P6-C1 | DONE |
| P6-C3 | `IndexRoleAction` — guard-scoped, `withCount`, search, `trashed` flag | 6 | P0 | P6-B5 | DONE |
| P6-C4 | `SaveRoleAction` — shipped split as `CreateRoleAction` + `UpdateRoleAction` over a `PersistsRole` trait | 6 | P0 | P6-C1 | DONE (split) |
| P6-C5 | `RoleController` — thin, 10 methods | 6 | P0 | P6-C1..C4 | DONE |
| P6-C6 | `DeleteRoleAction` + `RestoreRoleAction` + `ForceDeleteRoleAction` | 6 | P0 | P6-B5 | DONE (extended) |
| P6-C7 | `PermissionIndexAction` — see the kept name deviation in `phase-6-rbac.md` § C4 | 6 | P0 | P6-B3 | DONE (renamed from spec) |
| P6-C8 | `PermissionController@index` only — `can('permissions.view')` | 6 | P0 | P6-C7 | DONE |
| P6-C9 | `UpdateUserAction` — `users.assign_roles` check before `syncRoles` | 6 | P0 | P6-C5 | DONE (check lives in C11) |
| P6-C10 | `CreateUserAction` — same check, admin path only | 6 | P0 | P6-C5 | DONE (check lives in C11) |
| P6-C11 | `AssignRolesAction` — the one path to `syncRoles`, last-superadmin guard, audit | 6 | P0 | P6-B5 | DONE |
| P6-C12 | `LastSuperadminException` — web redirect + API 409 | 6 | P0 | P6-C11 | DONE |
| P6-C13 | ~~`AssignRolesRequest`~~ merged into `AssignRolesAction` | 6 | P0 | P6-C11 | DONE (merged) |
| P6-C14 | `CreateUserRequest::authorize()` → `can('users.create')` | 6 | P0 | P6-C5 | DONE |
| P6-C15 | `UpdateUserRequest::authorize()` → `can('users.update')` + narrow self-edit exception | 6 | P0 | P6-C5 | DONE |
| P6-C16 | `SystemSettingRequest::authorize()` → `can('settings.manage')` | 6 | P0 | P6-C5 | DONE |
| P6-C17 | `BulkUserRequest::authorize()` → action→permission map, via `AuthorizesBulkAction` | 6 | P0 | P6-C5 | DONE (extended to `BulkRoleRequest`) |
| P6-C18 | `UserPolicy` — 7 CRUD methods delegating to `users.*`; 4 state methods untouched | 6 | P0 | P6-C5 | DONE |
| P6-C-GATE | Gate C: routes authorized, zero-perm 403, sync proven, last-superadmin held | 6 | P0 | P6-C1..C18 | **MET (2026-09-30)** — `GateCAuthorizationTest` (8) + `RoleManagementTest` + `AssignRolesActionTest` + `UserRoleEditTest`; 505 tests / 1657 assertions |

**Gate C is met.** Groups C1–C4 all shipped and were audited against the code,
not against the plan. What remains open from the RBAC work is Group D — the
seven ungated user state routes (`restore`, `force-delete`, `activate`,
`deactivate`, `lock`, `unlock`, `resend-verification`, `cancel-email-change`) and
`api.v1.settings.index` carry no `can:` and no request-level check, so
`UserPolicy`'s methods for them are still uncalled on those paths. Measured with
the side effect confirmed, tracked as P6C4-001..005 in
`docs/qa/remediation-tracker.md`, closes at P6-D1/D2. The four Group A GET
routes this section used to describe as ungated were closed in Group C2.
Detail: `docs/planning/phase-6-rbac.md` § Group A and § C4.

**The gating blocker is gone, and Group C2 used it.** Group B seeded the 19
permissions, which removed the reason the Group A reads were left open (a gate
on a permission that does not exist yet denies everyone, superadmin included).
Group C2 then gated them: `can:roles.view` / `can:roles.create` /
`can:roles.update` / `can:permissions.view`. What is left for D1/D2 is the
**write** side — the five role write routes and the seven user state routes,
none of which carry a route-level `can:`. The role writes are already refused by
their Form Requests, so those are defence in depth; the user state routes are
genuinely open and are the real D1/D2 work.

## Full Task List (JSON for AI parsing)

```json[
  {
    "id": "AUTH-001",
    "task": "Define authentication requirements",
    "phase": 3,
    "priority": "P0",
    "depends_on": [
      "FOUND-004"
    ],
    "status": "DONE",
    "note": "requirements.md + authentication.md exist. Phase 3 implemented per spec."
  },
  {
    "id": "AUTH-002",
    "task": "Configure Sanctum API token driver",
    "phase": 3,
    "priority": "P0",
    "depends_on": [
      "FOUND-004"
    ],
    "status": "DONE",
    "note": "Sanctum configured — middleware, token creation in LoginController, logout deletes token."
  },
  {
    "id": "AUTH-003",
    "task": "Create authentication data model",
    "phase": 3,
    "priority": "P0",
    "depends_on": [
      "DB-002"
    ],
    "status": "DONE",
    "note": "User model: email_verified_at, is_active, is_locked, must_change_password, password_expires_at, last_activity_at, verification_token."
  },
  {
    "id": "AUTH-004",
    "task": "Implement login validation (username/email)",
    "phase": 3,
    "priority": "P0",
    "depends_on": [
      "AUTH-001"
    ],
    "status": "DONE",
    "note": "LoginRequest: identifier+password, custom messages, anti-enumeration."
  },
  {
    "id": "AUTH-005",
    "task": "Implement login action",
    "phase": 3,
    "priority": "P0",
    "depends_on": [
      "AUTH-004"
    ],
    "status": "DONE",
    "note": "LoginController: __invoke, uses AuthenticateUserAction (throttle + findUser + account state). Controllers thin — API JSON, Web redirect. Audit by controller with channel."
  },
  {
    "id": "AUTH-006",
    "task": "Implement session creation",
    "phase": 3,
    "priority": "P0",
    "depends_on": [
      "AUTH-005"
    ],
    "status": "DONE",
    "note": "Sanctum token via createToken."
  },
  {
    "id": "AUTH-007",
    "task": "Implement failed-login tracking",
    "phase": 3,
    "priority": "P0",
    "depends_on": [
      "AUTH-005"
    ],
    "status": "DONE",
    "note": "LoginThrottle::recordFailed/reset/lockedFor/clearIfExpired."
  },
  {
    "id": "AUTH-008",
    "task": "Implement temporary lock after failed attempts",
    "phase": 3,
    "priority": "P0",
    "depends_on": [
      "AUTH-007"
    ],
    "status": "DONE",
    "note": "LoginThrottle::isLocked with exponential backoff."
  },
  {
    "id": "AUTH-009",
    "task": "Implement logout (current device)",
    "phase": 3,
    "priority": "P0",
    "depends_on": [
      "AUTH-006"
    ],
    "status": "DONE",
    "note": "LogoutController: delete current token + audit."
  },
  {
    "id": "AUTH-010",
    "task": "Implement logout-all-devices",
    "phase": 3,
    "priority": "P1",
    "depends_on": [
      "AUTH-009"
    ],
    "status": "DONE",
    "note": "LogoutAllController: delete all tokens + audit."
  },
  {
    "id": "AUTH-011",
    "task": "Implement email verification",
    "phase": 3,
    "priority": "P0",
    "depends_on": [
      "AUTH-006"
    ],
    "status": "DONE",
    "note": "VerifyEmailController → VerifyEmailAction (markEmailAsVerified + audit). ResendVerificationController unchanged."
  },
  {
    "id": "UI-A-001",
    "task": "Auth login page (Blade view + form)",
    "phase": 3,
    "priority": "P0",
    "depends_on": [
      "AUTH-005"
    ],
    "status": "DONE",
    "note": "resources/views/pages/auth/login.blade.php, layouts/auth.blade.php, route /login."
  },
  {
    "id": "UI-A-002",
    "task": "Auth forgot password page",
    "phase": 3,
    "priority": "P1",
    "depends_on": [
      "AUTH-012"
    ],
    "status": "DONE",
    "note": "resources/views/pages/auth/forgot-password.blade.php, route /forgot-password."
  },
  {
    "id": "UI-A-003",
    "task": "Auth reset password page",
    "phase": 3,
    "priority": "P1",
    "depends_on": [
      "AUTH-013"
    ],
    "status": "DONE",
    "note": "resources/views/pages/auth/reset-password.blade.php, route /reset-password."
  },
  {
    "id": "UI-A-004",
    "task": "Auth verify email page",
    "phase": 3,
    "priority": "P0",
    "depends_on": [
      "AUTH-011"
    ],
    "status": "DONE",
    "note": "resources/views/pages/auth/verify-email.blade.php + verified.blade.php, routes /verify-email, /email/verify/{id}/{hash}."
  },
  {
    "id": "UI-A-005",
    "task": "Connect UI views to API logic (JS fetch/AJAX)",
    "phase": 3,
    "priority": "P0",
    "depends_on": [
      "UI-A-001",
      "UI-A-002",
      "UI-A-003",
      "UI-A-004"
    ],
    "status": "DONE",
    "note": "Web forms wired via controller redirects (not JS fetch). POST /login, /forgot-password, /reset-password, /email/resend → AuthControllerredirect."
  },
  {
    "id": "AUTH-012",
    "task": "Implement forgot password",
    "phase": 3,
    "priority": "P0",
    "depends_on": [
      "AUTH-001"
    ],
    "status": "DONE",
    "note": "PasswordForgotController → SendPasswordResetLinkAction (lock check + send link + audit). Anti-enumeration: always-same-response."
  },
  {
    "id": "AUTH-013",
    "task": "Implement password reset",
    "phase": 3,
    "priority": "P0",
    "depends_on": [
      "AUTH-012"
    ],
    "status": "DONE",
    "note": "PasswordResetController → ResetPasswordAction (lock check + reset + audit). Uses Laravel Password facade."
  },
  {
    "id": "AUTH-014",
    "task": "Implement password change (user)",
    "phase": 5,
    "priority": "P0",
    "depends_on": [
      "PWD-002"
    ],
    "status": "DONE",
    "note": "Web/API password change flow uses the shared action and revokes all Sanctum tokens, database sessions, and remember_token atomically."
  },
  {
    "id": "AUTH-015",
    "task": "Implement rate limiting for login",
    "phase": 5,
    "priority": "P0",
    "depends_on": [
      "RATE-001"
    ],
    "status": "DONE",
    "note": "Login throttling and progressive lockout are implemented and covered by the authentication test suite."
  },
  {
    "id": "USER-001",
    "task": "Create user management module",
    "phase": 4,
    "priority": "P1",
    "depends_on": [
      "AUTH-003"
    ],
    "status": "DONE"
  },
  {
    "id": "USER-002",
    "task": "Implement user list/detail API",
    "phase": 4,
    "priority": "P1",
    "depends_on": [
      "USER-001"
    ],
    "status": "DONE"
  },
  {
    "id": "USER-003",
    "task": "Implement create user (admin)",
    "phase": 4,
    "priority": "P1",
    "depends_on": [
      "AUTH-001"
    ],
    "status": "DONE"
  },
  {
    "id": "USER-004",
    "task": "Implement update user",
    "phase": 4,
    "priority": "P1",
    "depends_on": [
      "USER-002"
    ],
    "status": "DONE"
  },
  {
    "id": "USER-005",
    "task": "Implement soft delete user",
    "phase": 4,
    "priority": "P2",
    "depends_on": [
      "USER-001"
    ],
    "status": "DONE"
  },
  {
    "id": "USER-006",
    "task": "Implement activate/deactivate",
    "phase": 4,
    "priority": "P1",
    "depends_on": [
      "USER-003"
    ],
    "status": "DONE"
  },
  {
    "id": "USER-007",
    "task": "Implement lock/unlock",
    "phase": 4,
    "priority": "P1",
    "depends_on": [
      "USER-001"
    ],
    "status": "DONE"
  },
  {
    "id": "USER-008",
    "task": "Implement force password change",
    "phase": 4,
    "priority": "P1",
    "depends_on": [
      "AUTH-014"
    ],
    "status": "DONE"
  },
  {
    "id": "USER-009",
    "task": "Implement admin reset password",
    "phase": 4,
    "priority": "P1",
    "depends_on": [
      "AUTH-013"
    ],
    "status": "DONE"
  },
  {
    "id": "PWD-001",
    "task": "Define IM8 password policy",
    "phase": 5,
    "priority": "P1",
    "depends_on": [
      "P0-001"
    ],
    "status": "DONE",
    "note": "Group A complete: PasswordPolicy + PasswordStrengthRule + views + tests. P5-A1..A9 shipped."
  },
  {
    "id": "PWD-002",
    "task": "Implement password validation rule",
    "phase": 5,
    "priority": "P0",
    "depends_on": [
      "PWD-001"
    ],
    "status": "DONE",
    "note": "Group A complete: PasswordStrengthRule wired into ChangePasswordAction, ResetPassword, ProfileUpdate requests."
  },
  {
    "id": "PWD-003",
    "task": "Implement password history",
    "phase": 5,
    "priority": "P1",
    "depends_on": [
      "PWD-002"
    ],
    "status": "DONE",
    "note": "Phase 5 Group B complete: password history table, policy enforcement, settings UI, and tests shipped."
  },
  {
    "id": "PWD-004",
    "task": "Implement password expiration",
    "phase": 5,
    "priority": "P1",
    "depends_on": [
      "PWD-002"
    ],
    "status": "DONE",
    "note": "Phase 5 Group C complete: password expiry service, middleware, forced-change screen, warning banner, sweeps, settings, and tests shipped."
  },
  {
    "id": "PWD-005",
    "task": "Implement admin password reset",
    "phase": 4,
    "priority": "P1",
    "depends_on": [
      "AUTH-013"
    ],
    "status": "DONE"
  },
  {
    "id": "PWD-006",
    "task": "Implement temp password + force change",
    "phase": 4,
    "priority": "P1",
    "depends_on": [
      "USER-003"
    ],
    "status": "DONE"
  },
  {
    "id": "RBAC-001",
    "task": "Seed roles (superadmin, admin, user)",
    "phase": 6,
    "priority": "P0",
    "depends_on": [
      "DB-002"
    ],
    "status": "DONE",
    "note": "Roles seeded in Phase 2 (RoleSeeder) and pinned by RoleGuardTest. 2026-09-28: the system-role list moved to App\\Support\\SystemRole so the views and the seeder answer 'is this a system role?' identically. Permissions are a separate task — RBAC-004 / Group B."
  },
  {
    "id": "RBAC-002",
    "task": "Implement role management",
    "phase": 6,
    "priority": "P0",
    "depends_on": [
      "RBAC-001"
    ],
    "status": "DONE",
    "note": "2026-09-30: Groups C1-C4 all shipped and audited. C1 role CRUD (guard-scoped unique, PersistsRole trait, soft delete with revocation, trash/restore/force, bulk); C2 read-only catalogue + the can: gates on the four reads; C3 AssignRolesAction as the single path to syncRoles with the last-superadmin guard; C4 the four blanket authorize() returns closed, AuthorizesBulkAction trait, UserPolicy CRUD methods. Gate C MET - 505 tests / 1657 assertions. STILL OPEN but out of C scope: no can: on the five role write routes (defence in depth only, each is gated by its Form Request) and the seven ungated user state routes (P6-D1/D2, tracked as P6C4-001..005)."
  },
  {
    "id": "RBAC-003",
    "task": "Implement permission management",
    "phase": 6,
    "priority": "P0",
    "depends_on": [
      "RBAC-001"
    ],
    "status": "DONE",
    "note": "2026-09-30: Group B seeded the catalogue (PermissionSeeder prunes orphans, then creates, then assigns, flushing the cache at all three points) and Group C2 shipped the read-only web page (P6-C7/C8, PermissionIndexAction). No create/edit UI, by design: a permission row that no can() call references grants nothing. Known deviation kept: the class is PermissionIndexAction, not the table's IndexPermissionAction - documented in phase-6-rbac.md section C4."
  },
  {
    "id": "RBAC-004",
    "task": "Define permission set",
    "phase": 6,
    "priority": "P0",
    "depends_on": [
      "P0-004"
    ],
    "status": "DONE",
    "note": "2026-09-28: Group B. PermissionCatalog + PermissionSeeder. audit.* and features.* intentionally excluded — no feature behind them. Seeder prunes permissions dropped from the catalogue, which firstOrCreate alone would not do. 2026-09-30 correction: the catalogue is 21, not the 19 measured on 2026-09-28 — C1 added roles.force_delete and roles.restore with the trash tab. The count in the other Group B notes is left as written because it records what that commit actually measured."
  },
  {
    "id": "RBAC-005",
    "task": "Implement superadmin protection + system role protection (deletion/rename/permission manipulation guards, last-superadmin enforcement)",
    "phase": 6,
    "priority": "P0",
    "depends_on": [
      "RBAC-001"
    ],
    "status": "IN PROGRESS",
    "note": "2026-09-30: Gate::before in place (AuthServiceProvider::configureSuperAdmin), SuperAdminSeeder assigns the role, C6 refuses system-role delete/rename, C11 refuses stripping the last superadmin on assignment, and granting superadmin requires the causer to already be one. REMAINING: P6-E4 (delete or deactivate the last superadmin USER — the two paths AssignRolesAction does not cover) and P6-E5 (an explicit confirm_superadmin flag on the payload)."
  },
  {
    "id": "RBAC-006",
    "task": "Gate /users and /settings behind permissions — self-registered users can currently escalate to superadmin",
    "phase": 6,
    "priority": "P0",
    "depends_on": [
      "RBAC-004"
    ],
    "status": "PLANNED",
    "note": "Found by manual browser test of self-registration, 2026-09-27. The authenticated route group in routes/web.php carries only auth+verified+password.change.required+account.state — no can:/permission gate — and UserPolicy only covers unlock/activate/deactivate/lock, none of which index/store/update/destroy call. A user created through POST /register (role `user`, zero permissions) was able to: GET /users (200, all emails); POST /settings (changed login_max_attempts); POST /users with roles[]=superadmin (201, created a superadmin); PUT /users/{id} (demoted a superadmin); DELETE /users/{id} (deleted the superadmin account); POST /users/bulk-action; POST /users/{id}/deactivate. Same on the API: POST /api/v1/settings, POST /api/v1/users, GET /api/v1/users/{id} all accepted a plain user's token. SystemSettingRequest::authorize() and RegisterRequest::authorize() both return true unconditionally, and the `user` role is seeded with no permissions. Not introduced by the register feature — it made an already-reachable escalation available to anyone on the internet. Fix belongs here, not as a patch: add can:/permission middleware per route, give the `user` role its real permission set, and make authorize() consult the caller. 2026-09-28 update: still open. Group A added /roles and /permissions with no can: gate (permissions not seeded until Group B). Original /users and /settings escalation unchanged. Fix remains C14-C18 + D1/D2 + E9. 2026-09-28 (post-Group B): the blocker for gating the Group A routes is gone — the catalogue is seeded, so P6-D1 can now apply can: without denying everyone. /users and /settings escalation unchanged."
  },
  {
    "id": "P6-A1",
    "task": "Roles index view — header, breadcrumb, search filter, table, pagination",
    "phase": 6,
    "priority": "P0",
    "depends_on": [],
    "status": "DONE",
    "note": "Phase 6 Group A, shipped 2026-09-28. Audit: docs/planning/phase-6-rbac.md. Two items shipped beyond the original plan because the pages had to be reachable in a browser: Web\\V1\\RoleController + Web\\V1\\PermissionController and the four GET routes. App\\Support\\SystemRole was pulled forward from P6-B4 because the views need is_system. Search filter, sortable headers and the right-aligned Create button were added after a review round; the filter and button had been wrapped in @can, which is always false until P6-B1 seeds the permissions."
  },
  {
    "id": "P6-A2",
    "task": "Roles index actions column — edit always, delete via <x-ui.confirm-action>, System badge for system roles",
    "phase": 6,
    "priority": "P0",
    "depends_on": [
      "P6-A1"
    ],
    "status": "DONE",
    "note": "Phase 6 Group A, shipped 2026-09-28. See docs/planning/phase-6-rbac.md."
  },
  {
    "id": "P6-A3",
    "task": "Roles create view — form + system-role explainer column",
    "phase": 6,
    "priority": "P0",
    "depends_on": [
      "P6-A1"
    ],
    "status": "DONE",
    "note": "Phase 6 Group A, shipped 2026-09-28. See docs/planning/phase-6-rbac.md."
  },
  {
    "id": "P6-A4",
    "task": "Roles edit view — prefilled name (readonly for system roles), pre-checked permission matrix",
    "phase": 6,
    "priority": "P0",
    "depends_on": [
      "P6-A3"
    ],
    "status": "DONE",
    "note": "Phase 6 Group A, shipped 2026-09-28. See docs/planning/phase-6-rbac.md."
  },
  {
    "id": "P6-A5",
    "task": "Shared permission matrix partial — checkboxes posting permission IDs, old() re-check, empty state",
    "phase": 6,
    "priority": "P0",
    "depends_on": [
      "P6-A3"
    ],
    "status": "DONE",
    "note": "Phase 6 Group A, shipped 2026-09-28. See docs/planning/phase-6-rbac.md."
  },
  {
    "id": "P6-A6",
    "task": "Permissions catalogue view — read-only, grouped by resource, roles_count badge",
    "phase": 6,
    "priority": "P0",
    "depends_on": [
      "P6-A1"
    ],
    "status": "DONE",
    "note": "Phase 6 Group A, shipped 2026-09-28. See docs/planning/phase-6-rbac.md."
  },
  {
    "id": "P6-A7",
    "task": "Add delete_role key to ACTION_CONFIG for the role delete confirmation",
    "phase": 6,
    "priority": "P0",
    "depends_on": [
      "P6-A2"
    ],
    "status": "DONE",
    "note": "Phase 6 Group A, shipped 2026-09-28. See docs/planning/phase-6-rbac.md."
  },
  {
    "id": "P6-A8",
    "task": "AppMenuComposer — no change in Group A (Roles/Permissions items already listed)",
    "phase": 6,
    "priority": "P0",
    "depends_on": [],
    "status": "DONE",
    "note": "Phase 6 Group A, shipped 2026-09-28. See docs/planning/phase-6-rbac.md."
  },
  {
    "id": "P6-A9",
    "task": "Gate test RbacUiRenderTest — views render, query nothing, no forbidden classes, system-role delete hidden",
    "phase": 6,
    "priority": "P0",
    "depends_on": [
      "P6-A1",
      "P6-A2",
      "P6-A3",
      "P6-A4",
      "P6-A5",
      "P6-A6",
      "P6-A7"
    ],
    "status": "DONE",
    "note": "Phase 6 Group A, shipped 2026-09-28. See docs/planning/phase-6-rbac.md."
  },
  {
    "id": "P6-B1",
    "task": "PermissionSeeder — prune orphans, create from catalogue, sync matrix, flush cache 3x",
    "phase": 6,
    "priority": "P0",
    "depends_on": [
      "P6-B3",
      "P6-B4"
    ],
    "status": "DONE",
    "note": "Phase 6 Group B, shipped 2026-09-28. Verified at that commit: 19 permissions, superadmin 0 rows + can() true, admin 19, user 0, db:seed x2 idempotent, full suite 420 passed. Six deviations from the spec recorded in docs/planning/phase-6-rbac.md §Group B — two fixed real bugs (seeder only ever added, so a permission removed from the catalogue stayed forever; SuperAdminSeeder never assigned its role, which Gate::before turned into a total lockout). audit.* and features.* deliberately not seeded — no feature behind them (Phase 10 / Phase 7). LATER: the catalogue is 21 since C1 added roles.force_delete and roles.restore; the 19 above is what this commit measured."
  },
  {
    "id": "P6-B2",
    "task": "Register PermissionSeeder between RoleSeeder and SuperAdminSeeder",
    "phase": 6,
    "priority": "P0",
    "depends_on": [
      "P6-B1"
    ],
    "status": "DONE",
    "note": "Phase 6 Group B, shipped 2026-09-28. Verified at that commit: 19 permissions, superadmin 0 rows + can() true, admin 19, user 0, db:seed x2 idempotent, full suite 420 passed. Six deviations from the spec recorded in docs/planning/phase-6-rbac.md §Group B — two fixed real bugs (seeder only ever added, so a permission removed from the catalogue stayed forever; SuperAdminSeeder never assigned its role, which Gate::before turned into a total lockout). audit.* and features.* deliberately not seeded — no feature behind them (Phase 10 / Phase 7). LATER: the catalogue is 21 since C1 added roles.force_delete and roles.restore; the 19 above is what this commit measured."
  },
  {
    "id": "P6-B3",
    "task": "App\\Support\\PermissionCatalog — all(), grouped(), forResource()",
    "phase": 6,
    "priority": "P0",
    "depends_on": [
      "P6-B1"
    ],
    "status": "DONE",
    "note": "Phase 6 Group B, shipped 2026-09-28. Verified at that commit: 19 permissions, superadmin 0 rows + can() true, admin 19, user 0, db:seed x2 idempotent, full suite 420 passed. Six deviations from the spec recorded in docs/planning/phase-6-rbac.md §Group B — two fixed real bugs (seeder only ever added, so a permission removed from the catalogue stayed forever; SuperAdminSeeder never assigned its role, which Gate::before turned into a total lockout). audit.* and features.* deliberately not seeded — no feature behind them (Phase 10 / Phase 7). LATER: the catalogue is 21 since C1 added roles.force_delete and roles.restore; the 19 above is what this commit measured."
  },
  {
    "id": "P6-B4",
    "task": "SystemRole::isSystem() / names() — shipped in Group A, spec satisfied",
    "phase": 6,
    "priority": "P0",
    "depends_on": [
      "P6-A1"
    ],
    "status": "DONE",
    "note": "Phase 6 Group B, shipped 2026-09-28. Verified at that commit: 19 permissions, superadmin 0 rows + can() true, admin 19, user 0, db:seed x2 idempotent, full suite 420 passed. Six deviations from the spec recorded in docs/planning/phase-6-rbac.md §Group B — two fixed real bugs (seeder only ever added, so a permission removed from the catalogue stayed forever; SuperAdminSeeder never assigned its role, which Gate::before turned into a total lockout). audit.* and features.* deliberately not seeded — no feature behind them (Phase 10 / Phase 7). LATER: the catalogue is 21 since C1 added roles.force_delete and roles.restore; the 19 above is what this commit measured."
  },
  {
    "id": "P6-B5",
    "task": "RoleSeeder consumes SystemRole::names(); stale RBAC-004 comment corrected",
    "phase": 6,
    "priority": "P0",
    "depends_on": [
      "P6-B4"
    ],
    "status": "DONE",
    "note": "Phase 6 Group B, shipped 2026-09-28. Verified at that commit: 19 permissions, superadmin 0 rows + can() true, admin 19, user 0, db:seed x2 idempotent, full suite 420 passed. Six deviations from the spec recorded in docs/planning/phase-6-rbac.md §Group B — two fixed real bugs (seeder only ever added, so a permission removed from the catalogue stayed forever; SuperAdminSeeder never assigned its role, which Gate::before turned into a total lockout). audit.* and features.* deliberately not seeded — no feature behind them (Phase 10 / Phase 7). LATER: the catalogue is 21 since C1 added roles.force_delete and roles.restore; the 19 above is what this commit measured."
  },
  {
    "id": "P6-B6",
    "task": "Gate::before in AuthServiceProvider — true for superadmin, null otherwise, never false",
    "phase": 6,
    "priority": "P0",
    "depends_on": [
      "P6-B1"
    ],
    "status": "DONE",
    "note": "Phase 6 Group B, shipped 2026-09-28. Verified at that commit: 19 permissions, superadmin 0 rows + can() true, admin 19, user 0, db:seed x2 idempotent, full suite 420 passed. Six deviations from the spec recorded in docs/planning/phase-6-rbac.md §Group B — two fixed real bugs (seeder only ever added, so a permission removed from the catalogue stayed forever; SuperAdminSeeder never assigned its role, which Gate::before turned into a total lockout). audit.* and features.* deliberately not seeded — no feature behind them (Phase 10 / Phase 7). LATER: the catalogue is 21 since C1 added roles.force_delete and roles.restore; the 19 above is what this commit measured."
  },
  {
    "id": "P6-B7",
    "task": "PermissionSeedTest — catalogue seeded, role matrix, idempotency, pruning",
    "phase": 6,
    "priority": "P0",
    "depends_on": [
      "P6-B1",
      "P6-B5",
      "P6-B6"
    ],
    "status": "DONE",
    "note": "Phase 6 Group B, shipped 2026-09-28. Verified at that commit: 19 permissions, superadmin 0 rows + can() true, admin 19, user 0, db:seed x2 idempotent, full suite 420 passed. Six deviations from the spec recorded in docs/planning/phase-6-rbac.md §Group B — two fixed real bugs (seeder only ever added, so a permission removed from the catalogue stayed forever; SuperAdminSeeder never assigned its role, which Gate::before turned into a total lockout). audit.* and features.* deliberately not seeded — no feature behind them (Phase 10 / Phase 7). LATER: the catalogue is 21 since C1 added roles.force_delete and roles.restore; the 19 above is what this commit measured."
  },
  {
    "id": "P6-B8",
    "task": "PermissionCacheTest — can() reflects writes behind a warm cache",
    "phase": 6,
    "priority": "P0",
    "depends_on": [
      "P6-B7"
    ],
    "status": "DONE",
    "note": "Phase 6 Group B, shipped 2026-09-28. Verified at that commit: 19 permissions, superadmin 0 rows + can() true, admin 19, user 0, db:seed x2 idempotent, full suite 420 passed. Six deviations from the spec recorded in docs/planning/phase-6-rbac.md §Group B — two fixed real bugs (seeder only ever added, so a permission removed from the catalogue stayed forever; SuperAdminSeeder never assigned its role, which Gate::before turned into a total lockout). audit.* and features.* deliberately not seeded — no feature behind them (Phase 10 / Phase 7). LATER: the catalogue is 21 since C1 added roles.force_delete and roles.restore; the 19 above is what this commit measured."
  },
  {
    "id": "P6-C1",
    "task": "StoreRoleRequest — can('roles.create'), guard-scoped unique name",
    "phase": 6,
    "priority": "P0",
    "depends_on": [
      "P6-B3"
    ],
    "status": "DONE",
    "note": "Phase 6 Group C1, shipped 2026-09-29. Uniqueness scoped to RoleLookup::guard() so a name taken on another guard stays available."
  },
  {
    "id": "P6-C2",
    "task": "UpdateRoleRequest — can('roles.update'), unique ignoring the role",
    "phase": 6,
    "priority": "P0",
    "depends_on": [
      "P6-C1"
    ],
    "status": "DONE",
    "note": "Phase 6 Group C1, shipped 2026-09-29. Rule::unique()->ignore($role) keeps the role out of its own uniqueness check."
  },
  {
    "id": "P6-C3",
    "task": "IndexRoleAction — guard-scoped, withCount, search, sortable, trashed flag",
    "phase": 6,
    "priority": "P0",
    "depends_on": [
      "P6-B5"
    ],
    "status": "DONE",
    "note": "Phase 6 Group C1, shipped 2026-09-29. As specified plus a trashed flag that scopes the whole query rather than filtering rows. No N+1 (pinned by test_it_searches_and_paginates_without_n_plus_one)."
  },
  {
    "id": "P6-C4",
    "task": "SaveRoleAction — one action for create+update, transaction + in-transaction audit",
    "phase": 6,
    "priority": "P0",
    "depends_on": [
      "P6-C1"
    ],
    "status": "DONE",
    "note": "Phase 6 Group C1, shipped 2026-09-29. DEVIATION: the spec named one SaveRoleAction; shipped as CreateRoleAction + UpdateRoleAction over an App\\Actions\\Concerns\\PersistsRole trait holding everything that is not the verb — the DB::transaction, the array_map('intval') before syncPermissions (Spatie resolves a string '19' as a permission NAMED 19 and throws), and the audit write inside the transaction per DEP-003. Split to match CreateUserAction/UpdateUserAction on the user side."
  },
  {
    "id": "P6-C5",
    "task": "RoleController — thin, index/create/edit/store/update/destroy",
    "phase": 6,
    "priority": "P0",
    "depends_on": [
      "P6-C1",
      "P6-C2",
      "P6-C3",
      "P6-C4"
    ],
    "status": "DONE",
    "note": "Phase 6 Group C1, shipped 2026-09-29. 10 methods, resolves route data and delegates. The system-role refusal this row places in the controller lives in DeleteRoleAction instead (P6-C6's own wording) so the API cannot bypass it by not going through the web controller."
  },
  {
    "id": "P6-C6",
    "task": "DeleteRoleAction — refuse system roles and populated roles unless forced, detach + soft delete + audit in one transaction",
    "phase": 6,
    "priority": "P0",
    "depends_on": [
      "P6-B5"
    ],
    "status": "DONE",
    "note": "Phase 6 Group C1, shipped 2026-09-29, EXTENDED beyond the spec: also RestoreRoleAction + ForceDeleteRoleAction, a trash tab, and bulk actions, for the enterprise/SaaS role-retirement requirement. The users()->detach() is explicit because Spatie's deleting hook skips it on a soft delete — relying on the package would revoke nothing and a restore would silently re-grant (P6C1-004)."
  },
  {
    "id": "P6-C7",
    "task": "PermissionIndexAction — guard-scoped catalogue with role counts, search, sortable whitelist, pagination",
    "phase": 6,
    "priority": "P0",
    "depends_on": [
      "P6-B3"
    ],
    "status": "DONE",
    "note": "Phase 6 Group C2, shipped 2026-09-29. DEVIATION 1: class is PermissionIndexAction, not the spec's IndexPermissionAction. Decided 2026-09-30 to KEEP the shipped name rather than spend the rename (10 internal refs, behaviour already pinned by PermissionIndexActionTest). DEVIATION 2: grouping by resource prefix NOT built — a paginator cannot be grouped without losing search, sort and pagination. Open decision, not an omission."
  },
  {
    "id": "P6-C8",
    "task": "PermissionController@index only, can('permissions.view') — no store/update/destroy",
    "phase": 6,
    "priority": "P0",
    "depends_on": [
      "P6-C7"
    ],
    "status": "DONE",
    "note": "Phase 6 Group C2, shipped 2026-09-29. Read-only by design: a permission row that no can() call references grants nothing, so a UI that creates one only makes it look real."
  },
  {
    "id": "P6-C9",
    "task": "UpdateUserAction — users.assign_roles check before syncRoles",
    "phase": 6,
    "priority": "P0",
    "depends_on": [
      "P6-C5"
    ],
    "status": "DONE",
    "note": "Phase 6 Group C3, shipped 2026-09-29. The array_key_exists guard on the roles branch was left alone as instructed. The check ships in AssignRolesAction (C11), not here — with the check in each caller the create path was protected and the update path was not, so a caller holding only users.update could make any account a superadmin (measured: PUT /users/{victim} with roles:[superadmin] returned 302 and the victim became superadmin)."
  },
  {
    "id": "P6-C10",
    "task": "CreateUserAction — same check, admin path only; self-registration stays ungated",
    "phase": 6,
    "priority": "P0",
    "depends_on": [
      "P6-C5"
    ],
    "status": "DONE",
    "note": "Phase 6 Group C3, shipped 2026-09-29. The self-registration path (defaultRolesForSelfRegistration) is deliberately not permission-gated: it is not an admin action and its role is server-side, not client-supplied. Check lives in AssignRolesAction (C11)."
  },
  {
    "id": "P6-C11",
    "task": "AssignRolesAction — the single path to syncRoles, last-superadmin guard, before/after audit",
    "phase": 6,
    "priority": "P0",
    "depends_on": [
      "P6-B5"
    ],
    "status": "DONE",
    "note": "Phase 6 Group C3, shipped 2026-09-29. DB::transaction, resolve names via RoleLookup::findMany() (skip unknown rather than throw — a hard failure on a stale form is worse; fewer roles than names IS the skip), count superadmins before/after and throw LastSuperadminException if the result is zero, syncRoles, audit user.roles_assigned with both lists. Granting superadmin additionally requires the causer to already be one, or a delegated admin could mint a second. The count guard is a MINIMUM of one, not exactly one — the spec says zero, so enforcing exactly-one would be a change of requirement, not a fix. 2026-09-30: name resolution was one RoleLookup::find() per name, so a 20-role payload cost 28 queries; findMany() brings it to 9 (a4b33ab). Pinned by RbacPerformanceTest::test_assigning_roles_costs_the_same_at_one_and_at_twenty."
  },
  {
    "id": "P6-C12",
    "task": "LastSuperadminException — web redirect with error flash, API 409 JSON",
    "phase": 6,
    "priority": "P0",
    "depends_on": [
      "P6-C11"
    ],
    "status": "DONE",
    "note": "Phase 6 Group C3, shipped 2026-09-29. Rendered in bootstrap/app.php."
  },
  {
    "id": "P6-C13",
    "task": "AssignRolesRequest",
    "phase": 6,
    "priority": "P0",
    "depends_on": [
      "P6-C11"
    ],
    "status": "DONE",
    "note": "Phase 6 Group C3, 2026-09-29. MERGED, not built: the class was created as specced then deleted. No route ever referenced it, so the users.assign_roles check lived in a class nothing could reach. The check and the guard-scoped role resolution now live in AssignRolesAction, which both write paths call. Role payloads are still validated before they reach it, by CreateUserRequest/UpdateUserRequest. A standalone role-assignment endpoint was not needed — role editing is the picker on the existing user forms."
  },
  {
    "id": "P6-C14",
    "task": "CreateUserRequest::authorize() — can('users.create') on the admin path only",
    "phase": 6,
    "priority": "P0",
    "depends_on": [
      "P6-C5"
    ],
    "status": "DONE",
    "note": "Phase 6 Group C4, shipped 2026-09-30 in 015d6c3. RegisterController uses the separate public RegisterRequest, which stays true — a self-registering user holds no permission, so reusing this request would lock registration out entirely."
  },
  {
    "id": "P6-C15",
    "task": "UpdateUserRequest::authorize() — can('users.update') plus a narrow self-edit exception",
    "phase": 6,
    "priority": "P0",
    "depends_on": [
      "P6-C5"
    ],
    "status": "DONE",
    "note": "Phase 6 Group C4, shipped 2026-09-30 in 015d6c3. The self exception applies only when the target IS the caller and the payload carries no roles key; keys are compared as strings so a null user cannot match. Pinned by test_a_user_cannot_escalate_by_editing_their_own_profile."
  },
  {
    "id": "P6-C16",
    "task": "SystemSettingRequest::authorize() — can('settings.manage')",
    "phase": 6,
    "priority": "P0",
    "depends_on": [
      "P6-C5"
    ],
    "status": "DONE",
    "note": "Phase 6 Group C4, shipped 2026-09-30 in 015d6c3. Was return true unconditionally — part of the RBAC-006 escalation (a zero-permission user could POST /settings)."
  },
  {
    "id": "P6-C17",
    "task": "BulkUserRequest::authorize() — map the requested action to its own permission",
    "phase": 6,
    "priority": "P0",
    "depends_on": [
      "P6-C5"
    ],
    "status": "DONE",
    "note": "Phase 6 Group C4, shipped 2026-09-30 in 015d6c3, EXTENDED: extracted to the App\\Http\\Requests\\Concerns\\AuthorizesBulkAction trait and adopted by BulkRoleRequest too, which is what closed P6C1-005. One BULK_ACTIONS map plus an entity prefix, so a new action is added once and both bars get it; an unmapped action fails closed because authorize() runs before rules()."
  },
  {
    "id": "P6-C18",
    "task": "UserPolicy — viewAny/view/create/update/delete/forceDelete/restore delegating to users.*",
    "phase": 6,
    "priority": "P0",
    "depends_on": [
      "P6-C5"
    ],
    "status": "DONE",
    "note": "Phase 6 Group C4, shipped 2026-09-30 in 015d6c3. The four pre-existing state methods (unlock/activate/deactivate/lock) are kept exactly as they were — their 409 preconditions are business rules, not authorization. NOTE: the state methods are still not CALLED on the user state routes, which carry no gate at all. That is P6-D1/D2, tracked as P6C4-001/002."
  },
  {
    "id": "P6-C-GATE",
    "task": "Gate C — every role/permission route reachable and authorized, zero-perm 403, sync proven, last-superadmin held",
    "phase": 6,
    "priority": "P0",
    "depends_on": [
      "P6-C1",
      "P6-C18"
    ],
    "status": "DONE",
    "note": "MET 2026-09-30. Evidence, per clause: 403 on all admin read routes — GateCAuthorizationTest::test_a_user_with_no_permissions_gets_403_on_every_admin_read_route; 403 on all five role write routes — RoleManagementTest::test_a_user_with_no_permissions_cannot_write_roles + test_a_user_with_no_permissions_cannot_reach_the_trash_endpoints; role sync (add/replace/empty-clears/absent-untouched) — AssignRolesActionTest (8) + UserRoleEditTest (7); last-superadmin — test_stripping_the_last_superadmin_is_refused + test_the_refusal_leaves_the_superadmin_in_place; regression suite green — 505 passed / 1657 assertions, 1 risky (pre-existing). Gate C is a GROUP C gate: the seven ungated user state routes and api.v1.settings.index are P6-D1/D2 and remain open."
  },
  {
    "id": "FEAT-001",
    "task": "Implement feature flags backend",
    "phase": 7,
    "priority": "P1",
    "depends_on": [
      "RBAC-005"
    ],
    "status": "PLANNED"
  },
  {
    "id": "FEAT-002",
    "task": "Implement feature availability enforcement",
    "phase": 7,
    "priority": "P1",
    "depends_on": [
      "FEAT-001"
    ],
    "status": "PLANNED"
  },
  {
    "id": "SET-001",
    "task": "Design settings schema",
    "phase": 8,
    "priority": "P1",
    "depends_on": [
      "DB-002"
    ],
    "status": "PLANNED"
  },
  {
    "id": "SET-002",
    "task": "Implement settings CRUD",
    "phase": 8,
    "priority": "P1",
    "depends_on": [
      "SET-001"
    ],
    "status": "PLANNED"
  },
  {
    "id": "SET-003",
    "task": "Implement settings validation",
    "phase": 8,
    "priority": "P1",
    "depends_on": [
      "SET-002"
    ],
    "status": "PLANNED"
  },
  {
    "id": "SET-004",
    "task": "Implement settings audit",
    "phase": 8,
    "priority": "P1",
    "depends_on": [
      "SET-002"
    ],
    "status": "PLANNED"
  },
  {
    "id": "SET-005",
    "task": "Implement settings cache invalidation",
    "phase": 8,
    "priority": "P1",
    "depends_on": [
      "SET-002",
      "CACHE-002"
    ],
    "status": "PLANNED"
  },
  {
    "id": "NOTIF-001",
    "task": "Configure mail",
    "phase": 9,
    "priority": "P1",
    "depends_on": [
      "FOUND-003"
    ],
    "status": "PLANNED"
  },
  {
    "id": "NOTIF-002",
    "task": "Implement notification channels",
    "phase": 9,
    "priority": "P1",
    "depends_on": [
      "NOTIF-001"
    ],
    "status": "PLANNED"
  },
  {
    "id": "NOTIF-003",
    "task": "Queue email sending",
    "phase": 9,
    "priority": "P1",
    "depends_on": [
      "QUEUE-001"
    ],
    "status": "PLANNED"
  },
  {
    "id": "AUDIT-001",
    "task": "Integrate audit package",
    "phase": 10,
    "priority": "P0",
    "depends_on": [
      "RBAC-005"
    ],
    "status": "PLANNED"
  },
  {
    "id": "AUDIT-002",
    "task": "Create audit abstraction layer",
    "phase": 10,
    "priority": "P0",
    "depends_on": [
      "AUDIT-001"
    ],
    "status": "PLANNED"
  },
  {
    "id": "AUDIT-003",
    "task": "Implement audit recording in Actions",
    "phase": 10,
    "priority": "P0",
    "depends_on": [
      "AUDIT-002"
    ],
    "status": "PLANNED"
  },
  {
    "id": "AUDIT-004",
    "task": "Implement audit view/detail API",
    "phase": 10,
    "priority": "P1",
    "depends_on": [
      "AUDIT-002"
    ],
    "status": "PLANNED"
  },
  {
    "id": "AUDIT-005",
    "task": "Implement async audit export",
    "phase": 10,
    "priority": "P2",
    "depends_on": [
      "AUDIT-002",
      "QUEUE-001"
    ],
    "status": "PLANNED"
  },
  {
    "id": "RATE-001",
    "task": "Define rate limit config",
    "phase": 3,
    "priority": "P0",
    "depends_on": [
      "SET-001"
    ],
    "status": "DONE",
    "note": "AuthServiceProvider::boot() — 4 RateLimiter: login (5/min), forgot-password (3/min), reset-password (3/min), resend-verification (5/hour). Config: config/rate_limits.php."
  },
  {
    "id": "RATE-002",
    "task": "Implement rate limiting middleware",
    "phase": 3,
    "priority": "P0",
    "depends_on": [
      "RATE-001",
      "FOUND-003"
    ],
    "status": "DONE",
    "note": "throttle:login, throttle:forgot-password, throttle:reset-password, throttle:resend-verification wired on web + API routes. Shared key via LoginThrottle::key()."
  },
  {
    "id": "CACHE-001",
    "task": "Define cache config",
    "phase": 1,
    "priority": "P1",
    "depends_on": [
      "FOUND-003"
    ],
    "status": "DONE",
    "docs": [
      "cache.md"
    ],
    "verifies": "FOUND-003 config + DEP-006"
  },
  {
    "id": "CACHE-002",
    "task": "Implement cache tagging & invalidation",
    "phase": 1,
    "priority": "P1",
    "depends_on": [
      "CACHE-001"
    ],
    "status": "PLANNED",
    "note": "Deferred: base project has no application-level cached data requiring grouped invalidation. Pattern documented in cache.md; implement when a feature introduces cacheable data needing cache::tags() invalidation."
  },
  {
    "id": "QUEUE-001",
    "task": "Configure queue (database + Redis compat)",
    "phase": 1,
    "priority": "P0",
    "depends_on": [
      "FOUND-003",
      "DB-001"
    ],
    "status": "DONE",
    "verifies": "FOUND-003 config + DEP-006"
  },
  {
    "id": "DB-001",
    "task": "Create base migration scaffold",
    "phase": 2,
    "priority": "P0",
    "depends_on": [
      "FOUND-002"
    ],
    "status": "DONE"
  },
  {
    "id": "DB-002",
    "task": "Create seed data (roles, permissions)",
    "phase": 2,
    "priority": "P0",
    "depends_on": [
      "DB-001"
    ],
    "status": "DONE"
  },
  {
    "id": "API-001",
    "task": "Define API v1 routes",
    "phase": 12,
    "priority": "P0",
    "depends_on": [
      "SET-003",
      "AUDIT-004"
    ],
    "status": "PLANNED"
  },
  {
    "id": "API-002",
    "task": "Implement API resources (v1)",
    "phase": 12,
    "priority": "P0",
    "depends_on": [
      "API-001"
    ],
    "status": "PLANNED"
  },
  {
    "id": "API-003",
    "task": "Generate API documentation (Scramble)",
    "phase": 12,
    "priority": "P1",
    "depends_on": [
      "API-002"
    ],
    "status": "PLANNED"
  },
  {
    "id": "SEC-001",
    "task": "Implement security headers",
    "phase": 13,
    "priority": "P0",
    "depends_on": [
      "FOUND-003"
    ],
    "status": "PLANNED"
  },
  {
    "id": "SEC-002",
    "task": "Implement CSRF/CORS (web)",
    "phase": 13,
    "priority": "P0",
    "depends_on": [
      "SEC-001"
    ],
    "status": "PLANNED"
  },
  {
    "id": "SEC-003",
    "task": "Implement error handling (consistent JSON)",
    "phase": 13,
    "priority": "P0",
    "depends_on": [
      "SEC-001"
    ],
    "status": "PLANNED"
  },
  {
    "id": "STOR-001",
    "task": "Define storage disks (local, public, tmp)",
    "phase": 14,
    "priority": "P1",
    "depends_on": [
      "FOUND-003"
    ],
    "status": "PLANNED"
  },
  {
    "id": "STOR-002",
    "task": "Implement audit export storage (private)",
    "phase": 14,
    "priority": "P1",
    "depends_on": [
      "STOR-001",
      "AUDIT-005"
    ],
    "status": "PLANNED"
  },
  {
    "id": "BACKUP-001",
    "task": "Define backup strategy",
    "phase": 14,
    "priority": "P1",
    "depends_on": [
      "STOR-001"
    ],
    "status": "PLANNED"
  },
  {
    "id": "RETAIN-001",
    "task": "Implement retention policy jobs",
    "phase": 14,
    "priority": "P1",
    "depends_on": [
      "DB-001"
    ],
    "status": "PLANNED"
  },
  {
    "id": "MONITOR-001",
    "task": "Integrate Telescope + Periscope companion UI",
    "phase": 1,
    "priority": "P1",
    "depends_on": [
      "FOUND-007"
    ],
    "status": "DONE",
    "docs": [
      "monitoring.md",
      "observability.md",
      "overview.md",
      "DEP-004-telescope-technical-observability.md"
    ],
    "tests": [
      "PeriscopeFoundationTest"
    ],
    "note": "Telescope installed at FOUND-007 (Phase 1). Periscope v0.3 added as companion UI reading the same Telescope data via Telescope::check(); inherits Telescope authorization. No separate auth/gate/migration."
  },
  {
    "id": "MONITOR-002",
    "task": "Implement health check endpoint",
    "phase": 1,
    "priority": "P1",
    "depends_on": [
      "FOUND-010"
    ],
    "status": "DONE",
    "docs": [
      "monitoring.md"
    ],
    "tests": [
      "HealthCheckEndpointTest"
    ]
  },
  {
    "id": "CORR-001",
    "task": "Implement correlation ID middleware",
    "phase": 1,
    "priority": "P0",
    "depends_on": [
      "FOUND-008"
    ],
    "status": "DONE",
    "tests": [
      "TEST-API-003",
      "CorrelationIdMiddlewareTest"
    ],
    "docs": [
      "logging.md"
    ]
  },
  {
    "id": "UI-001",
    "task": "Vendor AdminLTE from official release ZIP (not npm) + wire initial Blade layout (UI-independent)",
    "phase": 1,
    "priority": "P1",
    "depends_on": [
      "FOUND-001"
    ],
    "status": "DONE",
    "docs": [
      "ui-adminlte-setup.md",
      "ui-architecture.md"
    ]
  },
  {
    "id": "UI-002",
    "task": "Application shell (header, sidebar, footer, theme toggle)",
    "phase": 1,
    "priority": "P1",
    "depends_on": [
      "UI-001"
    ],
    "status": "DONE",
    "docs": [
      "ui-architecture.md"
    ]
  },
  {
    "id": "UI-003",
    "task": "Shared UI component conventions (buttons, forms, tables, modals)",
    "phase": 1,
    "priority": "P1",
    "depends_on": [
      "UI-001"
    ],
    "status": "DONE",
    "docs": [
      "design-system.md",
      "ui-architecture.md"
    ]
  },
  {
    "id": "UI-004",
    "task": "Reusable confirmation modal component",
    "phase": 1,
    "priority": "P1",
    "depends_on": [
      "UI-002"
    ],
    "status": "DONE",
    "docs": [
      "ui-architecture.md"
    ]
  },
  {
    "id": "UI-005",
    "task": "Theme toggle (system default + manual dark/light)",
    "phase": 1,
    "priority": "P1",
    "depends_on": [
      "UI-001"
    ],
    "status": "DONE",
    "docs": [
      "ui-architecture.md"
    ]
  },
  {
    "id": "SOFT-001",
    "task": "Soft delete / trash / permanent delete convention",
    "phase": 1,
    "priority": "P1",
    "depends_on": [
      "FOUND-002"
    ],
    "status": "DONE",
    "docs": [
      "soft-delete.md"
    ]
  },
  {
    "id": "TABLE-001",
    "task": "Shared table conventions (sortable, filterable, bulk actions, pagination)",
    "phase": 1,
    "priority": "P2",
    "depends_on": [
      "UI-003"
    ],
    "status": "DONE",
    "docs": [
      "ui-architecture.md"
    ]
  },
  {
    "id": "FLAG-001",
    "task": "Feature flag package foundation (install + configure + UI conventions)",
    "phase": 1,
    "priority": "P1",
    "depends_on": [
      "FOUND-003"
    ],
    "status": "DONE",
    "docs": [
      "feature-flags.md"
    ],
    "tests": [
      "PennantFoundationTest"
    ],
    "note": "Implemented with Laravel Pennant. Migration published (features table). Config via env PENNANT_STORE. No business-specific flags created in Phase 1."
  },
  {
    "id": "UI-006",
    "task": "UI foundation cleanup: rename partials (no app-* prefix), remove obsolete adminlte layout, fix theme (system default + manual override, no flash), remove i18n from UI foundation, add style guide",
    "phase": 1,
    "priority": "P1",
    "depends_on": [
      "UI-001",
      "UI-002",
      "UI-003",
      "UI-004",
      "UI-005"
    ],
    "status": "DONE",
    "docs": [
      "style-guide.md",
      "ui-architecture.md",
      "ai-execution-guide.md"
    ]
  },
  {
    "id": "INACT-001",
    "task": "Implement inactivity tracking",
    "phase": 5,
    "priority": "P1",
    "depends_on": [
      "USER-001"
    ],
    "status": "DONE",
    "note": "Phase 5 Group C complete: last activity login tracking, null-user grace period, request-time lock audit, scheduled lock sweep, and tests shipped."
  },
  {
    "id": "ROUTE-001",
    "task": "Group routes: public vs auth (web + api)",
    "phase": 3,
    "priority": "P0",
    "depends_on": [],
    "status": "DONE",
    "note": "web.php: public routes (/, login, forgot-password, reset-password, verify-email) + auth group (dashboard). api.php: public (login, forgot, reset, verify) + protected (logout, logout-all, resend, password.change)."
  },
  {
    "id": "ROUTE-002",
    "task": "Dashboard auth middleware",
    "phase": 3,
    "priority": "P0",
    "depends_on": [],
    "status": "DONE",
    "note": "Added 'auth' middleware to /dashboard route. Unauthenticated access returns 302 redirect to login."
  },
  {
    "id": "ROUTE-003",
    "task": "Brute force throttle on web/auth endpoints",
    "phase": 3,
    "priority": "P0",
    "depends_on": [],
    "status": "DONE",
    "note": "API: throttle:login (5/min), throttle:forgot-password (3/min), throttle:resend-verification (5/hour). Shared keys via LoginThrottle::key() (identifier+IP). Web forms POST to API endpoints → shared throttle."
  },
  {
    "id": "ROUTE-004",
    "task": "Document route grouping + throttle in planning docs",
    "phase": 3,
    "priority": "P1",
    "depends_on": [],
    "status": "DONE",
    "note": "Added route grouping table and brute force throttle table to phase-3-audit-breakdown.md §1."
  }
]```