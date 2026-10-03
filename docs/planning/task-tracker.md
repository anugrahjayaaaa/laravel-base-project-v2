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
|| FOUND-007 | Install Laravel Pulse (was Telescope) | 1 | P0 | FOUND-001 | DONE |
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

**Audit pattern (cross-phase convention):** ✅ DONE — every mutation is audited
by the **Action that performs it**, inside that action's own transaction, via
`Auditable::audit()`: `$model->audit($event, $causer, $properties)`. Controllers
only orchestrate and MUST NOT call `audit()` for a mutation an action performs.
HTTP context (`source`, `ip`, `user_agent`) is captured automatically by
`Auditable::audit()`; callers pass only event-specific properties and may
override `source` (a job passes `system`). No model observers. See
`docs/base/architecture/application-boundaries.md` §
Action-First Audit Logging Standard and `docs/base/features/audit-trail.md`.

Migrated: User, System, Role, Feature (already compliant), Auth web + API.
Remaining: AUD-006 (Profile) — the last caller of `Controller::audit()`.

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
| RBAC-005 | Superadmin + system-role protection | 6 | P0 | RBAC-001 | DONE — `Gate::before` (AuthServiceProvider), SuperAdminSeeder, C6 system-role delete/rename refusal, C11 last-superadmin assignment guard, and Group E closes the rest: **E3** system-role permissions refused in `PersistsRole::persist()` (the matrix was one POST from emptying `admin`), **E4** `LastSuperadmin::guard()` on deactivate AND delete — the two paths `RoleAssignAction` never covered, verified exploitable end to end (superadmin count reached 0) and fixed at the root, including the bulk handler whose raw `UPDATE` bypassed the action entirely, **E5** `confirm_superadmin` required to add or remove superadmin. Each guard sabotaged and confirmed to turn its tests red |l DONE. Remaining is E4 (delete / deactivate the last superadmin *user*) and E5 (`confirm_superadmin` on grant) |
| RBAC-006 | Gate /users and /settings behind permissions | 6 | P0 | RBAC-004 | DONE — the web reads and the user writes are gated (C2 reads, C14–C18 the form requests, `UserPolicy`); D1/D2 put route-level `can:` on both files including the four state toggles and the settings pair that carried no gate at all; D3/D5–D8 gated the sidebar composer and the in-page controls; E7 replays both brief scenarios end to end and E9 replays the RBAC-006 exploit list itself. Attack suite uses a SIBLING permission rather than an empty one — a route written `can('users.view')` instead of `can('users.deactivate')` passes every zero-permission test in the repo | (C2 reads, C14–C18 the form requests, `UserPolicy`). **Still open:** the seven ungated user state routes and `api.v1.settings.index` (P6-D1/D2), plus the D-group button `can:` gates and the E9 pentest replay |
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
| P6-C3 | `RoleIndexAction` — guard-scoped, `withCount`, search, `trashed` flag | 6 | P0 | P6-B5 | DONE |
| P6-C4 | `SaveRoleAction` — shipped split as `RoleCreateAction` + `RoleUpdateAction` over a `PersistsRole` trait | 6 | P0 | P6-C1 | DONE (split) |
| P6-C5 | `RoleController` — thin, 10 methods | 6 | P0 | P6-C1..C4 | DONE |
| P6-C6 | `RoleDeleteAction` + `RoleRestoreAction` + `RoleForceDeleteAction` | 6 | P0 | P6-B5 | DONE (extended) |
| P6-C7 | `PermissionIndexAction` — see the kept name deviation in `phase-6-rbac.md` § C4 | 6 | P0 | P6-B3 | DONE (renamed from spec) |
| P6-C8 | `PermissionController@index` only — `can('permissions.view')` | 6 | P0 | P6-C7 | DONE |
| P6-C9 | `UserUpdateAction` — `users.assign_roles` check before `syncRoles` | 6 | P0 | P6-C5 | DONE (check lives in C11) |
| P6-C10 | `UserCreateAction` — same check, admin path only | 6 | P0 | P6-C5 | DONE (check lives in C11) |
| P6-C11 | `RoleAssignAction` — the one path to `syncRoles`, last-superadmin guard, audit | 6 | P0 | P6-B5 | DONE |
| P6-C12 | `LastSuperadminException` — web redirect + API 409 | 6 | P0 | P6-C11 | DONE |
| P6-C13 | ~~`AssignRolesRequest`~~ merged into `RoleAssignAction` | 6 | P0 | P6-C11 | DONE (merged) |
| P6-C14 | `CreateUserRequest::authorize()` → `can('users.create')` | 6 | P0 | P6-C5 | DONE |
| P6-C15 | `UpdateUserRequest::authorize()` → `can('users.update')` + narrow self-edit exception | 6 | P0 | P6-C5 | DONE |
| P6-C16 | `SystemSettingRequest::authorize()` → `can('settings.manage')` | 6 | P0 | P6-C5 | DONE |
| P6-C17 | `BulkUserRequest::authorize()` → action→permission map, via `AuthorizesBulkAction` | 6 | P0 | P6-C5 | DONE (extended to `BulkRoleRequest`) |
| P6-C18 | `UserPolicy` — 7 CRUD methods delegating to `users.*`; 4 state methods untouched | 6 | P0 | P6-C5 | DONE |
| P6-C-GATE | Gate C: routes authorized, zero-perm 403, sync proven, last-superadmin held | 6 | P0 | P6-C1..C18 | **MET (2026-09-30)** — `GateCAuthorizationTest` (8) + `RoleManagementTest` + `RoleAssignActionTest` + `UserRoleEditTest`; 505 tests / 1657 assertions |

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

### Phase 7 — Feature Availability & Feature Flags

| ID | Task | Phase | Priority | Depends On | Status |
|----|------|-------|----------|-----------|--------|
| P7-A1 | Features index view — header, metric strip, grouped table | 7 | P0 | — | DONE |
| P7-A2 | `components/ui/feature-toggle.blade.php` — switch for managers, badge for viewers | 7 | P0 | P7-A1 | DONE |
| P7-A3 | `<x-ui.confirm-action>` `tag` prop — an `<input type="checkbox">` trigger | 7 | P0 | P7-A2 | DONE |
| P7-A4 | `FeatureFlagUiRenderTest` — render gate for both branches | 7 | P0 | P7-A1..A3 | DONE |
| P7-A5 | Group A audit — `align-middle` on all five `<th>`; `ConfirmActionUsageTest` extended to `<input` triggers | 7 | P0 | P7-A1..A4 | DONE |
| P7-B1 | `config/pennant.php` — 8 flags across 5 groups | 7 | P0 | P7-A1 | DONE |
| P7-B2 | `AppServiceProvider::boot()` — declaration loop + `resolveScopeUsing('global')` | 7 | P0 | P7-B1 | DONE |
| P7-B3 | `App\Support\FeatureCatalog` — single reader, `isActive()` honours `disabled => true` | 7 | P0 | P7-B1 | DONE |
| P7-B4 | `FeatureFlagSeeder` — idempotent, never blanket-activates | 7 | P0 | P7-B2, B3 | DONE |
| P7-B5 | Register in `DatabaseSeeder` | 7 | P1 | P7-B4 | DONE |
| P7-B6 | `FeatureFlagCatalogTest` | 7 | P0 | P7-B4 | DONE |
| P7-B7 | Group B audit — seeder non-destructiveness proven live, `stores` block diffed vs vendor | 7 | P1 | P7-B1..B6 | DONE |
| P7-D1 | Seed `features.view` / `features.manage` in `PermissionCatalog` | 7 | P0 | P7-B3 | DONE |
| P7-D2 | `FeatureIndexAction` — grouped rows + 4 counters, resolved once | 7 | P0 | P7-B3 | DONE |
| P7-D3 | `FeatureToggleAction` — activate/deactivate, flush, audit `feature.toggled` | 7 | P0 | P7-D2 | DONE |
| P7-D4 | `Web\V1\FeatureController` — thin, `index` + `toggle` | 7 | P0 | P7-D2, D3 | DONE |
| P7-D5 | `routes/web.php` — `features.index` + `features.toggle` | 7 | P0 | P7-D4 | DONE |
| P7-D6 | `AppMenuComposer` — Feature Flags item gated on `features.view` | 7 | P1 | P7-D5 | DONE |
| P7-C1 | `EnsureFeatureIsEnabled` — 403 on any inactive flag, no manage bypass | 7 | P0 | P7-B3 | DONE |
| P7-C2 | Register the `feature:` middleware alias in `bootstrap/app.php` | 7 | P0 | P7-C1 | DONE |
| P7-C3 | Verify `@feature` (package-registered — do not re-register) | 7 | P1 | P7-B6 | DONE |
| P7-C4 | Do **not** extend `Gate::before()` — no superadmin bypass | 7 | P0 | P7-C1 | DONE |
| P7-C5 | `FeatureFlagMiddlewareTest` — 403 for every role, config kill switch, multi-flag | 7 | P0 | P7-C2 | DONE |
| P7-D7 | `routes/web.php` — `feature:{slug}` grouped middleware on existing routes | 7 | P0 | P7-C2 | DONE |
| P7-D8 | `routes/api.php` — the same matrix | 7 | P0 | P7-D7 | DONE |
| P7-D9 | `AppMenuComposer` — filter items on `FeatureCatalog::isActive()` | 7 | P0 | P7-D7 | DONE |
| P7-D10 | `FeatureFlagMenuTest` — flag off hides the item for superadmin too | 7 | P0 | P7-D9 | DONE |
| P7-F1 | `enable_feature` / `disable_feature` keys in `ACTION_CONFIG` (success / warning) | 7 | P0 | — | DONE |
| P7-F2 | `BulkActionCopyTest` — every offered action resolves to a dropdown label AND modal copy | 7 | P1 | P7-F1 | DONE |
| P7-F3 | `FeatureBulkToggleAction` — one audit row, all `from` read before any write | 7 | P0 | P7-D3 | DONE |
| P7-F4 | `features.bulk-action` route + `BulkFeatureRequest` (NOT `AuthorizesBulkAction`) | 7 | P0 | P7-F3 | DONE |
| P7-F5 | `#bulkBar` on `pages/features/index.blade.php` per §Bulk Actions | 7 | P0 | P7-F1, F4 | DONE |
| P7-F6 | `FeatureFlagBulkTest` — mixed selection offers only universally safe actions | 7 | P0 | P7-F5 | DONE |
| P7-F7 | `AssetBundleFreshnessTest` — built bundle carries the feature actions + select-all selector matches the markup | 7 | P0 | P7-F1, F5 | DONE |
| P7-F8 | `FeatureSelectAllTest` — real bundle over both page shapes (id and class) | 7 | P0 | P7-F5, F7 | DONE |
| P7-F9 | `FeatureBulkDropdownTest` — dropdown offers only actions safe for the selection | 7 | P0 | P7-F1, F5 | DONE |
| P7-E1 | 403 web + API for superadmin, plus fail-closed cases — covered by `FeatureFlagMiddlewareTest` (every role incl. superadmin, `features.manage` holder, undeclared slug, kill switch) + `FeatureFlagRouteTest` (API logout-all gate). The named `FeatureFlagTest.php` was never created; the coverage exists, split by concern | 7 | P0 | P7-C5, P7-D10 | DONE (renamed scope) |
| P7-E2 | Round trip — store row + audit `from`/`to` + cache flush in `FeatureFlagRouteTest`; the follow-up half now in `FeatureFlagPerformanceTest::a_toggled_flag_is_reflected_by_the_next_page_view`, which re-resolves and asserts the row AND the enabled counter both moved | 7 | P0 | P7-D3 | DONE |
| P7-E3 | `ConfirmActionUsageTest` — `features.index` added, trigger regex extended to `<input\b` | 7 | P0 | P7-A3 | DONE |
| P7-E4 | Regression — `php artisan test` green; gated tests activate the flag in `setUp()` | 7 | P0 | P7-D7 | DONE |
| P7-E5 | Perf — `FeatureFlagPerformanceTest` measures the index resolution at two catalogue sizes and asserts the count is IDENTICAL. It found a real N+1: `resolve()` called `isActive()` per slug, measured 2/4/8 queries for 2/4/8 flags. `FeatureCatalog::activeMap()` now reads them in one `WHERE name IN (...)`; sabotage-verified by restoring the loop, which turns the test red (2 vs 8) | 7 | P1 | P7-D2 | DONE |
| P7-E6 | Docs — `feature-flags.md` + broken link done; trackers close with the phase | 7 | P1 | P7-F6 | IN_PROGRESS |
| P7-E7 | Full verification — `php artisan test` (846 passed / 3030 assertions), `npm run build`, `pint --test` on every Group F file (passed; repo-wide pint still fails on 33 PRE-EXISTING files, none in Phase 7), `view:cache`. Only the repo-wide pint debt is open | 7 | P0 | P7-E4, E6 | DONE (pint debt pre-existing) |

**Groups A, B, C and D1–D6 ship.** The middleware exists and answers correctly,
and the routes now carry it (`P7-D7`/`D8`) — turning a flag off at `/features`
refuses those routes and drops the menu item, for superadmin included.

**Still true:** three of the eight flags control nothing, because `translations`,
`activity_logs` and `pulse` have no routes to gate. Those modules ship in Phase 8.
Closed. Full suite green; the follow-up audit is in `docs/planning/progress.md`.

Detail: `docs/planning/phase-7-feature-flags.md`.

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
    "note": "LoginController: __invoke, uses AuthAuthenticateAction (throttle + findUser + account state). Controllers thin — API JSON, Web redirect. Audit by controller with channel."
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
    "note": "VerifyEmailController → AuthVerifyEmailAction (markEmailAsVerified + audit). ResendVerificationController unchanged."
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
    "note": "PasswordForgotController → AuthSendResetLinkAction (lock check + send link + audit). Anti-enumeration: always-same-response."
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
    "note": "PasswordResetController → AuthResetPasswordAction (lock check + reset + audit). Uses Laravel Password facade."
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
    "note": "Group A complete: PasswordStrengthRule wired into AuthChangePasswordAction, ResetPassword, ProfileUpdate requests."
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
    "note": "2026-09-30: Groups C1-C4 all shipped and audited. C1 role CRUD (guard-scoped unique, PersistsRole trait, soft delete with revocation, trash/restore/force, bulk); C2 read-only catalogue + the can: gates on the four reads; C3 RoleAssignAction as the single path to syncRoles with the last-superadmin guard; C4 the four blanket authorize() returns closed, AuthorizesBulkAction trait, UserPolicy CRUD methods. Gate C MET - 505 tests / 1657 assertions. STILL OPEN but out of C scope: no can: on the five role write routes (defence in depth only, each is gated by its Form Request) and the seven ungated user state routes (P6-D1/D2, tracked as P6C4-001..005)."
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
    "status": "DONE",
    "note": "2026-09-30: Gate::before in place (AuthServiceProvider::configureSuperAdmin), SuperAdminSeeder assigns the role, C6 refuses system-role delete/rename, C11 refuses stripping the last superadmin on assignment, and granting superadmin requires the causer to already be one. Group E closed the rest. P6-E3: system-role permissions are code-defined — PersistsRole::persist() now refuses a permission payload aimed at any SystemRole name; the role matrix was one POST (submit no checkboxes) from silently emptying admin, and the old RoleManagementTest asserted that edit SUCCEEDED. P6-E4: LastSuperadmin::guard() now runs in UserDeactivateAction and UserDeleteAction. This was a live hole, not a theoretical one — verified before the fix that a caller holding users.deactivate + users.delete + users.force_delete and NOT being a superadmin could deactivate the only superadmin, delete them, and force-delete them, ending at zero superadmins. The bulk handler compounded it: 'deactivate' ran a raw User::whereIn()->update(), bypassing UserDeactivateAction entirely, so the guard the row button enforced was absent from the dropdown one screen above; it now routes through the action. UserForceDeleteAction deliberately has NO guard (it only accepts already-trashed rows, which UserDeleteAction already refused to create) — documented in place rather than left as a silent omission. P6-E5: confirm_superadmin is required to add OR remove superadmin, threaded through both user write actions and both FormRequests, with a confirm control in the role picker so the UI can still perform the operation. Every guard was sabotaged and confirmed to turn its tests red (E5 -> 4 red, E4 -> 6, E3 -> 5, bulk hole -> 1). Pinned by LastSuperadminGuardTest (19) and RoleManagementTest (30). Full suite 661 passed / 2243 assertions."
  },
  {
    "id": "RBAC-006",
    "task": "Gate /users and /settings behind permissions — self-registered users can currently escalate to superadmin",
    "phase": 6,
    "priority": "P0",
    "depends_on": [
      "RBAC-004"
    ],
    "status": "DONE",
    "note": "CLOSED 2026-09-30 (Phase 6 Groups A-E). Every exploit in the original report is now refused, and each was replayed as a test rather than asserted in prose: RbacPentestTest covers roles[] mass-assigned onto PUT /profile and PUT /api/v1/profile/update, roles[]=superadmin on PUT /users/{other} (web and API), a delegated users.assign_roles holder granting superadmin even WITH confirm_superadmin, the same via POST /users/bulk-action, and CSRF on POST /settings (419, with the companion test proving the write lands once CSRF is dropped so the refusal is not measuring something else). GateD6DPentestTest attacks the D-group routes with a SIBLING permission rather than an empty one — the shape 6D actually opened — and E7 replays both brief scenarios end to end across routes, sidebar and in-page controls. ORIGINAL REPORT: Found by manual browser test of self-registration, 2026-09-27. The authenticated route group in routes/web.php carries only auth+verified+password.change.required+account.state — no can:/permission gate — and UserPolicy only covers unlock/activate/deactivate/lock, none of which index/store/update/destroy call. A user created through POST /register (role `user`, zero permissions) was able to: GET /users (200, all emails); POST /settings (changed login_max_attempts); POST /users with roles[]=superadmin (201, created a superadmin); PUT /users/{id} (demoted a superadmin); DELETE /users/{id} (deleted the superadmin account); POST /users/bulk-action; POST /users/{id}/deactivate. Same on the API: POST /api/v1/settings, POST /api/v1/users, GET /api/v1/users/{id} all accepted a plain user's token. SystemSettingRequest::authorize() and RegisterRequest::authorize() both return true unconditionally, and the `user` role is seeded with no permissions. Not introduced by the register feature — it made an already-reachable escalation available to anyone on the internet. Fix belongs here, not as a patch: add can:/permission middleware per route, give the `user` role its real permission set, and make authorize() consult the caller. 2026-09-28 update: still open. Group A added /roles and /permissions with no can: gate (permissions not seeded until Group B). Original /users and /settings escalation unchanged. Fix remains C14-C18 + D1/D2 + E9. 2026-09-28 (post-Group B): the blocker for gating the Group A routes is gone — the catalogue is seeded, so P6-D1 can now apply can: without denying everyone. /users and /settings escalation unchanged."
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
    "task": "RoleIndexAction — guard-scoped, withCount, search, sortable, trashed flag",
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
    "note": "Phase 6 Group C1, shipped 2026-09-29. DEVIATION: the spec named one SaveRoleAction; shipped as RoleCreateAction + RoleUpdateAction over an App\\Actions\\Concerns\\PersistsRole trait holding everything that is not the verb — the DB::transaction, the array_map('intval') before syncPermissions (Spatie resolves a string '19' as a permission NAMED 19 and throws), and the audit write inside the transaction per DEP-003. Split to match UserCreateAction/UserUpdateAction on the user side."
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
    "note": "Phase 6 Group C1, shipped 2026-09-29. 10 methods, resolves route data and delegates. The system-role refusal this row places in the controller lives in RoleDeleteAction instead (P6-C6's own wording) so the API cannot bypass it by not going through the web controller."
  },
  {
    "id": "P6-C6",
    "task": "RoleDeleteAction — refuse system roles and populated roles unless forced, detach + soft delete + audit in one transaction",
    "phase": 6,
    "priority": "P0",
    "depends_on": [
      "P6-B5"
    ],
    "status": "DONE",
    "note": "Phase 6 Group C1, shipped 2026-09-29, EXTENDED beyond the spec: also RoleRestoreAction + RoleForceDeleteAction, a trash tab, and bulk actions, for the enterprise/SaaS role-retirement requirement. The users()->detach() is explicit because Spatie's deleting hook skips it on a soft delete — relying on the package would revoke nothing and a restore would silently re-grant (P6C1-004)."
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
    "task": "UserUpdateAction — users.assign_roles check before syncRoles",
    "phase": 6,
    "priority": "P0",
    "depends_on": [
      "P6-C5"
    ],
    "status": "DONE",
    "note": "Phase 6 Group C3, shipped 2026-09-29. The array_key_exists guard on the roles branch was left alone as instructed. The check ships in RoleAssignAction (C11), not here — with the check in each caller the create path was protected and the update path was not, so a caller holding only users.update could make any account a superadmin (measured: PUT /users/{victim} with roles:[superadmin] returned 302 and the victim became superadmin)."
  },
  {
    "id": "P6-C10",
    "task": "UserCreateAction — same check, admin path only; self-registration stays ungated",
    "phase": 6,
    "priority": "P0",
    "depends_on": [
      "P6-C5"
    ],
    "status": "DONE",
    "note": "Phase 6 Group C3, shipped 2026-09-29. The self-registration path (defaultRolesForSelfRegistration) is deliberately not permission-gated: it is not an admin action and its role is server-side, not client-supplied. Check lives in RoleAssignAction (C11)."
  },
  {
    "id": "P6-C11",
    "task": "RoleAssignAction — the single path to syncRoles, last-superadmin guard, before/after audit",
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
    "note": "Phase 6 Group C3, 2026-09-29. MERGED, not built: the class was created as specced then deleted. No route ever referenced it, so the users.assign_roles check lived in a class nothing could reach. The check and the guard-scoped role resolution now live in RoleAssignAction, which both write paths call. Role payloads are still validated before they reach it, by CreateUserRequest/UpdateUserRequest. A standalone role-assignment endpoint was not needed — role editing is the picker on the existing user forms."
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
    "note": "Phase 6 Group C4, shipped 2026-09-30 in 015d6c3, EXTENDED: extracted to the App\Concerns\\AuthorizesBulkAction trait and adopted by BulkRoleRequest too, which is what closed P6C1-005. One BULK_ACTIONS map plus an entity prefix, so a new action is added once and both bars get it; an unmapped action fails closed because authorize() runs before rules()."
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
    "note": "MET 2026-09-30. Evidence, per clause: 403 on all admin read routes — GateCAuthorizationTest::test_a_user_with_no_permissions_gets_403_on_every_admin_read_route; 403 on all five role write routes — RoleManagementTest::test_a_user_with_no_permissions_cannot_write_roles + test_a_user_with_no_permissions_cannot_reach_the_trash_endpoints; role sync (add/replace/empty-clears/absent-untouched) — RoleAssignActionTest (8) + UserRoleEditTest (7); last-superadmin — test_stripping_the_last_superadmin_is_refused + test_the_refusal_leaves_the_superadmin_in_place; regression suite green — 505 passed / 1657 assertions, 1 risky (pre-existing). Gate C is a GROUP C gate: the seven ungated user state routes and api.v1.settings.index are P6-D1/D2 and remain open."
  },
  {
    "id": "FEAT-001",
    "task": "Implement feature flags backend",
    "phase": 7,
    "priority": "P1",
    "depends_on": [
      "RBAC-005"
    ],
    "status": "DONE",
    "note": "DONE 2026-10-02. Every group ships: A (UI), B (catalogue + activation), C (enforcement middleware), D1-D10 (route + menu gate), E (tests + docs), F (bulk enable/disable). Engine is Pennant, not a custom table — see docs/planning/phase-7-feature-flags.md Decision 1. Follow-up audit fixed a bulkAudit() row written with event NULL (invisible to every event filter), gated the undeclared pulse route, collapsed 4 duplicated cache-key literals into one public const, and removed a doc that told operators to activate flags via tinker without flushing the resolved snapshot."
  },
  {
    "id": "FEAT-002",
    "task": "Implement feature availability enforcement",
    "phase": 7,
    "priority": "P1",
    "depends_on": [
      "FEAT-001"
    ],
    "status": "DONE",
    "note": "DONE 2026-10-02. EnsureFeatureIsEnabled exists (403, fail-closed, no superadmin bypass, reads the config kill switch that Pennant\'s own middleware cannot see); the feature: alias is registered in bootstrap/app.php; 62/62 module routes are gated across web.php and api.php, plus vendor /pulse through pulse.middleware; the sidebar filters on the flag before the permission. Verified by walking gatherMiddleware() at runtime — route:list hides middleware GROUPS, so the gate is invisible there."
  },
  {
    "id": "P7-A1",
    "task": "Features index view — content-header, 4 metric cards, table grouped by module",
    "phase": 7,
    "priority": "P0",
    "depends_on": [],
    "status": "DONE",
    "note": "Phase 7 Group A, shipped 9545bce. Reads $featureGroups/$totalFeatures/$enabledCount/$disabledCount only — no queries in Blade, per ui-architecture.md."
  },
  {
    "id": "P7-A2",
    "task": "components/ui/feature-toggle.blade.php — switch for managers, badge for viewers",
    "phase": 7,
    "priority": "P0",
    "depends_on": ["P7-A1"],
    "status": "DONE",
    "note": "Phase 7 Group A, shipped 9545bce. A viewer sees badges rather than disabled switches — same read-only shape as pages/settings. toggle_url is passed in, not built here: route() in a view is controller logic in a view."
  },
  {
    "id": "P7-A3",
    "task": "<x-ui.confirm-action> gains a tag prop so a trigger can be <input type=\"checkbox\">",
    "phase": 7,
    "priority": "P0",
    "depends_on": ["P7-A2"],
    "status": "DONE",
    "note": "Phase 7 Group A, shipped 9545bce. The 8 existing button triggers render unchanged. The switch is a confirmation trigger because design-system.md names feature flag changes as requiring confirmation."
  },
  {
    "id": "P7-A4",
    "task": "FeatureFlagUiRenderTest — render gate for the manager and viewer branches",
    "phase": 7,
    "priority": "P0",
    "depends_on": ["P7-A1", "P7-A2", "P7-A3"],
    "status": "DONE",
    "note": "Phase 7 Group A, shipped 9545bce."
  },
  {
    "id": "P7-A5",
    "task": "Group A audit — align-middle on all five th, ConfirmActionUsageTest extended to input triggers",
    "phase": 7,
    "priority": "P0",
    "depends_on": ["P7-A1", "P7-A2", "P7-A3", "P7-A4"],
    "status": "DONE",
    "note": "Audit 2026-10-01, against the code rather than the plan. Two real gaps. (1) align-middle sat on the <table> instead of per-header, unlike users (7), roles (2) and permissions (2); added to all five. That broke the_toggle_column_is_centred, which pins the exact header string — the assertion was right, so the string was updated rather than the fix reverted. (2) The <input> switch was INVISIBLE to ConfirmActionUsageTest: the trigger regex matched <button\\b only, so confirm-action's tag prop escaped verification entirely. Regex now <(?:button|input)\\b, features.index added to the data provider, and the data-action-type assertion now skips own-copy triggers (which must carry data-title) instead of demanding the null default the component deliberately refuses to set. 8 tests to 9. Same bug class found while fixing it: the hand-built-trigger ban globbed views/pages/ only, so a hand-built trigger in views/components/ would have escaped too — widened to both. Both fixes sabotage-verified."
  },
  {
    "id": "P7-B1",
    "task": "config/pennant.php — 8 flags across 5 groups",
    "phase": 7,
    "priority": "P0",
    "depends_on": ["P7-A1"],
    "status": "DONE",
    "note": "Phase 7 Group B, shipped b72a5f6. users, roles, permissions, settings, translations, sessions, activity_logs, pulse. The brief's `registration` flag was DROPPED — registration_enabled is already a system_settings row read at four entry points, so a flag would be a second writer for one question. `telescope` also dropped (package removed in 85384b4). The published `stores` block stays byte-identical: it carries the PENNANT_STORE env wiring deploy reads."
  },
  {
    "id": "P7-B2",
    "task": "AppServiceProvider::boot() — declaration loop plus Feature::resolveScopeUsing('global')",
    "phase": 7,
    "priority": "P0",
    "depends_on": ["P7-B1"],
    "status": "DONE",
    "note": "Phase 7 Group B, shipped b72a5f6. The global scope is load-bearing: Pennant defaults to the authenticated user, which would make the management page show one user's flags as the installation's."
  },
  {
    "id": "P7-B3",
    "task": "App\\Support\\FeatureCatalog — single reader, isActive() honours disabled => true",
    "phase": 7,
    "priority": "P0",
    "depends_on": ["P7-B1"],
    "status": "DONE",
    "note": "Phase 7 Group B, shipped b72a5f6. This REPLACED the brief's FeatureManager::isEnabled(). isActive() returns false when config says disabled => true before consulting the store, so a config entry is a working kill switch rather than a default — otherwise flipping the flag and re-seeding would re-enable it."
  },
  {
    "id": "P7-B4",
    "task": "FeatureFlagSeeder — idempotent, never blanket-activates",
    "phase": 7,
    "priority": "P0",
    "depends_on": ["P7-B2", "P7-B3"],
    "status": "DONE",
    "note": "Phase 7 Group B, shipped b72a5f6. This is the whole answer to the Pennant trap: declaring a flag does not activate it, so without a seeder a newly added flag 403s its route for everyone including superadmin. Never blanket-activates, so a flag an operator turned off stays off across a reseed."
  },
  {
    "id": "P7-B5",
    "task": "Register FeatureFlagSeeder in DatabaseSeeder",
    "phase": 7,
    "priority": "P1",
    "depends_on": ["P7-B4"],
    "status": "DONE",
    "note": "Phase 7 Group B, shipped b72a5f6."
  },
  {
    "id": "P7-B6",
    "task": "FeatureFlagCatalogTest — every catalogue slug declared and active after seeding",
    "phase": 7,
    "priority": "P0",
    "depends_on": ["P7-B4"],
    "status": "DONE",
    "note": "Phase 7 Group B, shipped b72a5f6. Verified again 2026-10-01 with the route and UI suites: 30 tests / 174 assertions green."
  },
  {
    "id": "P7-B7",
    "task": "Group B audit — seeder non-destructiveness proven live, stores block diffed vs vendor",
    "phase": 7,
    "priority": "P1",
    "depends_on": ["P7-B1", "P7-B2", "P7-B3", "P7-B4", "P7-B5", "P7-B6"],
    "status": "DONE",
    "note": "Audit 2026-10-01, against the code and the database. Group B holds. Verified: FeatureCatalog::isActive() is the only reader (grep — the sole two callers are FeatureIndexAction and FeatureToggleAction, so no second implementation can drift); all 8 flags declare label/group/description 8/8 with no default relied on; the published stores block diffs IDENTICAL against vendor, so the PENNANT_STORE env wiring deploy reads is intact; seeder non-destructiveness proven by RUNNING it — deactivated pulse, reseeded, pulse stayed false and the row count held at 8 (flag restored afterwards); Feature::stored() returns 8 names live. Two gaps, neither a defect: disabled => true is implemented and tested but unused, correct today because a config-disabled flag needs its route gated first (Group C) or it refuses for everyone with no way back but a deploy; and no test pins the slug count, because assertSame(count(slugs()), rows) compares store against config — adding a flag passes, accidentally deleting one still fails, and a hardcoded 8 would break on every legitimate addition. Also recorded: the seeder uses activate() not activateForEveryone() because the database driver implements all-scopes as setForAllScopes -> where(name)->update(), which matches nothing and silently writes nothing on a flag with no row — which is every flag this seeder exists to create."
  },
  {
    "id": "P7-D1",
    "task": "Seed features.view + features.manage in PermissionCatalog",
    "phase": 7,
    "priority": "P0",
    "depends_on": ["P7-B3"],
    "status": "DONE",
    "note": "Phase 7 Group D, shipped e538c49. The :68-72 comment that reserved them for this phase is gone. admin takes them automatically via PermissionCatalog::all(); superadmin needs no row (Gate::before)."
  },
  {
    "id": "P7-D2",
    "task": "FeatureIndexAction — grouped rows plus four counters, each flag resolved once",
    "phase": 7,
    "priority": "P0",
    "depends_on": ["P7-B3"],
    "status": "DONE",
    "note": "Phase 7 Group D, shipped e538c49. Resolution happens here and not in the Blade loop — per-row resolution in the view would be one store read per flag per render. Also builds toggle_url per flag so the view never calls route()."
  },
  {
    "id": "P7-D3",
    "task": "FeatureToggleAction — activate/deactivate, flush cache, audit feature.toggled",
    "phase": 7,
    "priority": "P0",
    "depends_on": ["P7-D2"],
    "status": "DONE",
    "note": "Phase 7 Group D, shipped e538c49. The `from` value is read BEFORE the write; read after, it is always the new value and the audit row records a transition that never happened."
  },
  {
    "id": "P7-D4",
    "task": "Web\\V1\\FeatureController — thin, index + toggle only",
    "phase": 7,
    "priority": "P0",
    "depends_on": ["P7-D2", "P7-D3"],
    "status": "DONE",
    "note": "Phase 7 Group D, shipped e538c49. No Form Request class: one validated boolean on a query string, and P6-C13 deleted a one-rule request class for exactly this shape."
  },
  {
    "id": "P7-D5",
    "task": "routes/web.php — features.index + features.toggle",
    "phase": 7,
    "priority": "P0",
    "depends_on": ["P7-D4"],
    "status": "DONE",
    "note": "Phase 7 Group D, shipped e538c49. Both inside the authenticated group so auth runs before can, making the answer 403 rather than 401."
  },
  {
    "id": "P7-D6",
    "task": "AppMenuComposer — Feature Flags item gated on features.view",
    "phase": 7,
    "priority": "P1",
    "depends_on": ["P7-D5"],
    "status": "DONE",
    "note": "Phase 7 Group D, shipped e538c49. Permission gate only — it does NOT yet filter on flag state. That is P7-D9; until it lands the menu and the routes can disagree."
  },
  {
    "id": "P7-C1",
    "task": "App\\Http\\Middleware\\EnsureFeatureIsEnabled — 403 on any inactive flag, no manage bypass",
    "phase": 7,
    "priority": "P0",
    "depends_on": ["P7-B3"],
    "status": "DONE",
    "note": "Built 2026-10-01. Variadic string ...$features, abort(403) on the first inactive flag, no permission check anywhere in the class. Status revised 404 -> 403 on 2026-10-01: 403 is what can: and CheckAccountState already return, so one status means 'you may not have this' across the admin, whereas 404 claims a route does not exist when it does. Cost accepted: *module killed*, *no permission* and *account disabled* now share a status, and a client needing to distinguish them must ask /features, which is never gated. Pennant's EnsureFeaturesAreActive could NOT be aliased for two measured reasons: it aborts 400 (Decision 2), and it resolves through Feature::active() which asks the store — with config disabled=>true and a stored true row, FeatureCatalog::isActive() returns false while Feature::active() returns true and Feature::someAreInactive() reports the flag fine. Aliasing it would leave the config kill switch inert on every gated route."
  },
  {
    "id": "P7-C2",
    "task": "Register the feature: middleware alias in bootstrap/app.php",
    "phase": 7,
    "priority": "P0",
    "depends_on": ["P7-C1"],
    "status": "DONE",
    "note": "Built 2026-10-01 as the third alias alongside password.change.required and account.state. Verified live: app('router')->getMiddleware()['feature'] resolves to App\\Http\\Middleware\\EnsureFeatureIsEnabled. SYNTAX TRAP recorded in the class docblock — Laravel splits middleware params on the FIRST colon then on commas (Pipeline.php:241), so feature:users,roles is correct and feature:users,feature:roles yields the literal string 'feature:roles', an undeclared slug that fails closed into a 403 looking exactly like a working kill switch."
  },
  {
    "id": "P7-C3",
    "task": "Verify @feature / @featureany — registered by Pennant, do not re-register",
    "phase": 7,
    "priority": "P1",
    "depends_on": ["P7-B6"],
    "status": "DONE",
    "note": "Verified 2026-10-01: PennantServiceProvider.php:46 registers $blade->if('feature'), :54 registers featureany. The app does not re-register either — a second Blade::if('feature') would silently override the package's. No code written, which is the correct outcome for this task."
  },
  {
    "id": "P7-C4",
    "task": "Do not extend Gate::before() for flags — no superadmin bypass",
    "phase": 7,
    "priority": "P0",
    "depends_on": ["P7-C1"],
    "status": "DONE",
    "note": "Confirmed 2026-10-01: AuthServiceProvider::configureSuperAdmin() returns true for superadmin and null otherwise, with no flag awareness, and was left untouched. The trap is live rather than hypothetical — Gate::before returning true for superadmin is exactly what would keep a killed module reachable, and FeatureFlagMiddlewareTest is the only thing that catches it: adding a bypass turned 7 assertions red naming the role."
  },
  {
    "id": "P7-C5",
    "task": "FeatureFlagMiddlewareTest — flag off returns 403 on web and API, including for superadmin",
    "phase": 7,
    "priority": "P0",
    "depends_on": ["P7-C2"],
    "status": "DONE",
    "note": "Built 2026-10-01, 9 tests. Each registers a throwaway route carrying only web+auth+feature:{slug} — a real route is also gated by can: and account.state, and all three answer 403, so on a real route the cause would be unattributable, which is the exact confusion the test rules out. Covers: active passes, inactive 403, inactive 403 for admin AND superadmin (data provider), features.manage holder still 403, undeclared slug refused, disabled=>true beats a stored active row, several flags ANDed, and features.index never gated on itself. Each test registers a throwaway route carrying only web+auth+feature:{slug} because a real route is also gated by can: and account.state, and all three of those answer 403 — on a real route the cause would be unattributable, which is the exact confusion this rules out. Sabotage-verified three ways under the 403 status: a features.manage bypass turns 7 red naming the role, abort(400) turns 7 red, reading Feature::active() instead of isActive() turns 1 red naming the kill switch as decorative."
  },
  {
    "id": "P7-D7",
    "task": "routes/web.php — feature:{slug} grouped middleware on the existing module routes",
    "phase": 7,
    "priority": "P0",
    "depends_on": ["P7-C2"],
    "status": "DONE",
    "note": "Built 2026-10-01. One `feature:{slug}` group per flag rather than a call per route: a flag on 3 of 9 routes is a partial gate and the routes missed keep working. web: users 19 routes (incl. bulk-action, the four state toggles, the email-change flow), roles 9, permissions 1, settings 2, sessions 2. api: users 17, roles 7, permissions 1, settings 2, sessions 2. api.v1.auth.logout-all is gated too — it calls the same action and writes the same audit event as sessions.logout-all, so leaving it open let a client mass-logout every device with the module off. Two deliberate exclusions: `logout` stays OUTSIDE feature:sessions because logging out must keep working when the module is off or a bad flag strands an admin who cannot end a session; and roles/permissions are two groups rather than one `feature:roles,permissions` because ANDing them would switch off the permission catalogue whenever roles are off \u2014 the catalogue is code-defined, so roles being off does not invalidate it, which is exactly why it is its own flag. Verified by walking gatherMiddleware() at runtime: 62 of 62 module routes resolve a feature: middleware, none unflagged. Re-verified 2026-10-02, which also found /pulse ungated — a vendor route, so no route in this project carries its flag; it is now gated through pulse.middleware, the vendor's own extension point. route:list does NOT show it \u2014 its Middleware column omits the group, so a reader checking the gate there sees nothing. The first pass was NOT clean: users.bulk-action, the four state toggles and the email-change routes fell outside the group, which a diff review missed and the runtime walk caught."
  },
  {
    "id": "P7-D8",
    "task": "routes/api.php — the same feature matrix as web",
    "phase": 7,
    "priority": "P0",
    "depends_on": ["P7-D7"],
    "status": "DONE",
    "note": "Built 2026-10-01, the same matrix as the web side. An API-only gap is the same hole under a different URL \u2014 the RbacPentestTest lesson from Phase 6 applied to a new dimension. Counts into the same 62/62 runtime verification: every api/v1 module route now resolves a feature: middleware."
  },
  {
    "id": "P7-D9",
    "task": "AppMenuComposer — filter menu items on FeatureCatalog::isActive()",
    "phase": 7,
    "priority": "P0",
    "depends_on": ["P7-D7"],
    "status": "DONE",
    "note": "NOT STARTED. A menu that disagrees with the routes shows links that 403, or hides links that work."
  },
  {
    "id": "P7-D10",
    "task": "FeatureFlagMenuTest — a flag off removes its sidebar item for superadmin too",
    "phase": 7,
    "priority": "P0",
    "depends_on": ["P7-D9"],
    "status": "DONE",
    "note": "NOT STARTED. Must test admin AND superadmin, who passes every can(). The item is removed, never greyed."
  },
  {
    "id": "P7-F1",
    "task": "Add enable_feature / disable_feature keys to ACTION_CONFIG",
    "phase": 7,
    "priority": "P0",
    "depends_on": [],
    "status": "DONE",
    "note": "NOT STARTED. ACTION_CONFIG has 12 keys and none are flag-related, so data-bulk-keys has nothing to resolve against yet. Variants per design-system.md §Action Color Convention: enable => success, disable => warning. disable is NOT danger — disabling pauses access and destroys nothing."
  },
  {
    "id": "P7-F2",
    "task": "UI-consistency test — every data-action-type exists in ACTION_CONFIG",
    "phase": 7,
    "priority": "P1",
    "depends_on": [
        "P7-F1"
    ],
    "status": "DONE",
    "note": "Built 2026-10-01. tests/Feature/BulkActionCopyTest.php cross-checks the RENDERED page against actionOptions and ACTION_CONFIG. Rendered, because the attribute holds @json(...) which is Blade, not JSON, so parsing the template would assert on a string the browser never sees. Both failure modes are silent: populateDropdown skips an action it cannot label, and resolveAction warns and returns null, so a typo renders an empty dropdown with nothing in the console a test run would catch. Also pins enable=>success and disable=>warning in BOTH maps per the Action Color Convention. Sabotage-verified three ways: typo in ACTION_CONFIG, entry removed from actionOptions, variant swapped. Pages leaning on the driver defaults (users) are skipped rather than falsely failed."
},
  {
    "id": "P7-F3",
    "task": "FeatureBulkToggleAction — one request, one feature.bulk_toggled audit row",
    "phase": 7,
    "priority": "P0",
    "depends_on": [
        "P7-D3"
    ],
    "status": "DONE",
    "note": "Built 2026-10-01. FeatureBulkToggleAction: one transaction, one feature.bulk_toggled row carrying requested + changed + unchanged, every from read BEFORE the first write. Deliberately does not loop FeatureToggleAction, which writes N audit rows and flushes the cache N times for one user action. Validates the whole slug list before touching any flag, so a crafted POST naming one real and one invented slug changes neither."
},
  {
    "id": "P7-F4",
    "task": "features.bulk-action route + BulkFeatureRequest via AuthorizesBulkAction",
    "phase": 7,
    "priority": "P0",
    "depends_on": [
        "P7-F3"
    ],
    "status": "DONE",
    "note": "Built 2026-10-01. POST features.bulk-action + BulkFeatureRequest. Deliberately does NOT use AuthorizesBulkAction: the trait maps action to prefix.suffix, so delete_feature would demand features.delete_feature, which does not exist. The catalogue has exactly features.view and features.manage, and both directions rewrite the same store row, so one features.manage gate covers both. Minting per-action permissions would create two nobody can hold separately without meaning anything. Route carries throttle:bulk-action and no can(), because the permission depends on the requested action."
},
  {
    "id": "P7-F5",
    "task": "#bulkBar on pages/features/index.blade.php",
    "phase": 7,
    "priority": "P0",
    "depends_on": [
        "P7-F1",
        "P7-F4"
    ],
    "status": "DONE",
    "note": "Built 2026-10-01. #bulkBar outside the module loop, with data-bulk-mixed=disable_feature: on a mixed selection the only action safe for every row is disable, and enabling is the more expensive direction for a kill switch. One bar, not one per card, because the driver binds a single #bulkBar by id."
},
  {
    "id": "P7-F6",
    "task": "FeatureFlagBulkTest — a mixed selection offers only actions safe for every selected row",
    "phase": 7,
    "priority": "P0",
    "depends_on": [
        "P7-F5"
    ],
    "status": "DONE",
    "note": "Built 2026-10-01. tests/Feature/FeatureFlagBulkTest.php, 11 tests: one audit row for a multi-flag change; from read before the write; undeclared slug refused over HTTP AND directly against the action, since a queued job or console command never runs the form request; unknown action refused; duplicate slug applied once; unauthorised caller refused; state survives a store re-read. All three sabotage checks passed only after the assertions were tightened: filtering on the bulk event alone hid a stray per-slug row."
},
  {
    "id": "P7-E1",
    "task": "FeatureFlagTest — 403 on web and API for superadmin, menu hidden, re-enable restores",
    "phase": 7,
    "priority": "P0",
    "depends_on": ["P7-C5", "P7-D10"],
    "status": "PLANNED",
    "note": "NOT STARTED. Must also cover the two cases the brief does not name and that are the ones that break: an undeclared slug 403s (fail-closed), and a slug declared in config but never activated 403s (the Pennant trap)."
  },
  {
    "id": "P7-E2",
    "task": "Round trip — POST the rendered toggle URL, assert store, cache flush, audit from/to, follow-up GET",
    "phase": 7,
    "priority": "P0",
    "depends_on": ["P7-D3"],
    "status": "PLANNED",
    "note": "NOT STARTED. Closes the item Group A left open — A4 asserts the trigger attributes but no controller existed to receive them."
  },
  {
    "id": "P7-E3",
    "task": "ConfirmActionUsageTest — features.index added and trigger regex extended to <input",
    "phase": 7,
    "priority": "P0",
    "depends_on": ["P7-A3"],
    "status": "DONE",
    "note": "Closed early, in the Group A audit (P7-A5) rather than at E3 — it was a Group A verification gap, not a Group E one. The regex at :153 matched <button\\b only, so the <input> switch was never checked at all, which is exactly how confirm-action's tag prop escaped verification. Sabotage-verified: reverting the regex turns the suite red naming the per-flag switch assertion."
  },
  {
    "id": "P7-E4",
    "task": "Regression — php artisan test green after route gating",
    "phase": 7,
    "priority": "P0",
    "depends_on": ["P7-D7"],
    "status": "DONE",
    "note": "Done 2026-10-01. The churn arrived: 274 tests failed with 403, none about feature flags, all because a test visiting /users had no seeded flag and declaring does not activate (the Pennant trap). Fixed at ONE root cause in tests/TestCase.php \u2014 every test starts with all flags ACTIVE, the state a real install is in after FeatureFlagSeeder \u2014 rather than a seeder call in each of the 74 files that touch a gated route. FeatureFlagCatalogTest opts out via shouldSeedFeatureFlags() because every_catalogued_flag_resolves_off_until_it_is_seeded exists to assert an UNSEEDED database; that assertion was NOT weakened to go green. Full suite 811 tests / 2873 assertions / 0 failures. Separately, 33 pint failures exist at HEAD and are pre-existing; only the 3 introduced by this phase were fixed."
  },
  {
    "id": "P7-E5",
    "task": "Perf — flat store reads for the index, pinned as a delta at two flag counts",
    "phase": 7,
    "priority": "P1",
    "depends_on": ["P7-D2"],
    "status": "DONE",
    "note": "NOT STARTED. A ceiling like 'under 20' passes for an N+1 that happens to fit under a number someone picked."
  },
  {
    "id": "P7-E6",
    "task": "Docs — feature-flags.md, trackers, and fix the broken link at ui-architecture.md:158",
    "phase": 7,
    "priority": "P1",
    "depends_on": ["P7-F6"],
    "status": "IN_PROGRESS",
    "note": "The two doc DEFECTS closed in the Group A audit: feature-flags.md rewritten (Phase 7 catalogue with the 8 flags, the declare-does-not-activate trap, 403 settled against Pennant's 400 with the reasoning, the no-features.manage-bypass rule), and ui-architecture.md:158 relinked from ./feature-flags.md to ../features/feature-flags.md. Remaining: the trackers, which close with the phase, and feature-tracker.md row 24 — it reads 'done' today, which is wrong, because the table existed and nothing used it until Phase 7."
  },
  {
    "id": "P7-E7",
    "task": "Full verification — php artisan test, npm run build, pint --test, view:cache",
    "phase": 7,
    "priority": "P0",
    "depends_on": ["P7-E4", "P7-E6"],
    "status": "PLANNED",
    "note": "NOT STARTED."
  }

{
    "id": "P7-F7",
    "task": "AssetBundleFreshnessTest — the built bundle actually contains the feature actions and matches every page select-all markup",
    "phase": 7,
    "priority": "P1",
    "depends_on": [
        "P7-F1",
        "P7-F5"
    ],
    "status": "DONE",
    "note": "Built 2026-10-01, after the bulk dropdown shipped EMPTY. Nothing in PHP can see JavaScript: Blade rendered correct data-bulk-* attributes and all 800+ request tests passed while the browser ran a stale pre-Group-F Vite bundle. Reads app.js THROUGH public/build/manifest.json, because Vite content-hashes filenames and the old bundle stays on disk, so a glob reads whichever file sorts first. Also pins the driver select-all selector against the markup the views actually ship: users and roles use an id, features uses a class because it renders one table per module, and matching only one form silently kills the other pages. Sabotage-verified by pointing the manifest at a stale bundle and by narrowing the selector."
}

{
    "id": "P7-F8",
    "task": "FeatureSelectAllTest — select-all scoped to its own card on the multi-table page",
    "phase": 7,
    "priority": "P1",
    "depends_on": [
        "P7-F5",
        "P7-F7"
    ],
    "status": "DONE",
    "note": "Built 2026-10-01. Runs the real bundle over BOTH page shapes in SEPARATE vm contexts. Evaluated in one context they fight over the shared DOM stub and produce flaky results, which is what an earlier attempt did. Catches three regressions distinctly: a class-only selector (users and roles select-all dead), an id-only selector (features select-all dead), and a page-wide select-all (one card header ticking every flag). Sabotage-verified against all three."
}

{
    "id": "P7-F9",
    "task": "FeatureBulkDropdownTest — the dropdown offers only actions safe for the selection",
    "phase": 7,
    "priority": "P1",
    "depends_on": [
        "P7-F1",
        "P7-F5"
    ],
    "status": "DONE",
    "note": "Built 2026-10-01. Executes the shipped bundle via vm rather than a reimplementation, so it tests the artifact and not a copy of its logic. Asserts active rows offer disable, inactive rows offer enable, and a MIXED selection offers only the one action safe for all rows. Forces an active/inactive spread first: the baseline seeder activates every flag, so without that the mixed rule is never exercised and a broken driver passes. The PHP side asserts EVERY check line by name plus a blanket no-FAIL check, after an earlier version asserted only the first and so missed a real failure the script had already printed."
},
  {
    "id": "SET-001",
    "task": "Design settings schema",
    "phase": 8,
    "priority": "P1",
    "depends_on": [
      "DB-002"
    ],
    "status": "DONE",
    "note": "Reconciled 2026-10-02 during the Phase 8 audit; already shipped by Phase 4F. Flat key/value table \u2014 migration 2026_09_21_153100_create_system_settings_table.php is id + key(unique) + value(nullable) + timestamps, nothing else. No category column (the categories are seeder comment groups, not a stored taxonomy), no is_autoload, no typed schema and no per-setting class, deliberately: a setting is one row and one setter. Seeded by SystemSettingSeeder (36 keys, grouped by seeder comment category: login rate limiting, password policy/lifecycle, identity/account rules, self-registration). Rules are per-key integers/booleans/enums in SystemSettingRequest, not columns."
  },
  {
    "id": "SET-002",
    "task": "Implement settings CRUD",
    "phase": 8,
    "priority": "P1",
    "depends_on": [
      "SET-001"
    ],
    "status": "DONE",
    "note": "Reconciled 2026-10-02; already shipped. Read via SystemSetting::getAll() (ONE query, plucks value+key), written via SystemSettingsUpdateAction inside ONE DB::transaction covering all ~36 keys \u2014 set() has no transaction of its own, so a bare loop could stop half way and leave the password policy updated with the registration toggle not. Web and API share the action and differ in ONE flag: run($data, partial: false) for the form (a missing boolean means OFF) vs partial: true for the API (a missing key means LEAVE IT ALONE). Sharing the default reset password_min_length to 8 and switched registration_enabled off in the same API call, disarming the password policy and self-signup. Routes: GET/POST /settings (web), GET/PUT /api/v1/settings."
  },
  {
    "id": "SET-003",
    "task": "Implement settings validation",
    "phase": 8,
    "priority": "P1",
    "depends_on": [
      "SET-002"
    ],
    "status": "DONE",
    "note": "Reconciled 2026-10-02; already shipped as App\\Http\\Requests\\V1\\System\\SystemSettingRequest \u2014 37 rules with min/max bounds, guard-scoped unique checks, Rule::exists on the timezone reference table, and prepareForValidation casting '0'/'1'/'on' to bool. authorize() requires settings.manage (P6-C16, after RBAC-006). PARTIAL COVERAGE, tracked as P8-E1: bounds are proven out-of-range on ONE field of 37 (password_security_sweep_timezone), so a mistyped max is invisible."
  },
  {
    "id": "SET-004",
    "task": "Implement settings audit",
    "phase": 8,
    "priority": "P1",
    "depends_on": [
      "SET-002"
    ],
    "status": "DONE",
    "note": "Reconciled 2026-10-02; already shipped and compliant with DEP-003 (record written INSIDE the transaction, before COMMIT, so a rollback takes it with the settings it describes). Action-first: SystemSettingsUpdateAction calls ->audit('system_setting.updated', $causer, $data) at action:128, never the controller. causer is passed explicitly by both controllers so one action yields one correctly-attributed row from either channel; Auditable::audit() derives source (web|api), ip and user_agent itself (Auditable:77-79). PARTIAL COVERAGE, tracked as P8-E1: SystemSettingUpdateTest asserts causer/subject/event through the WEB path only; the API twin's audit row is unproven."
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
    "status": "DONE",
    "note": "Reconciled 2026-10-02; already shipped, and NOT via cache::tags() \u2014 so it does not depend on the deferred CACHE-002. SystemSetting::set() calls bustCache(), clearing both the request-level static cache and Cache::forget('app_system_settings') (model:156-161), so N getter calls in one request cost exactly ONE query. KNOWN GAP: there is no SystemSettingObserver, so a write that bypasses set() \u2014 a query-builder update, a seeder using upsert() \u2014 leaves the cache stale. Latent, not live: every shipped write path goes through set(). Decide whether to add the observer or document the invariant (open item 5 in phase-8-settings-management.md)."
  },
  {
    "id": "P8-E1a",
    "task": "Settings validation breadth \u2014 prove every numeric bound out-of-range",
    "phase": 8,
    "priority": "P1",
    "depends_on": [],
    "status": "DONE",
    "note": "DONE 2026-10-03 in 4de344f. SystemSettingUpdateTest::test_every_numeric_bound_rejects_a_value_outside_it walks all 40 min:/max: bounds in rules() and posts one out-of-range value per bound against the API channel (161 assertions), plus asserts each limit itself is accepted. Runs against the API rather than the web form because a web failure redirects and asserts against flashed session state, which reads the same whether the bound held or not. HONEST LIMIT, recorded in the test docblock: this catches DRIFT, not a single-typo. The probed limit is read back out of rules(), so a bound mistyped inward (max:140 for max:1440) still rejects 141 and the test stays green \u2014 pinning the 40 numbers would mean the same constant in two places. That is a decision for the owner, not a defect."
  },
  {
    "id": "P8-E1b",
    "task": "Settings API audit + partial-semantics test",
    "phase": 8,
    "priority": "P1",
    "depends_on": [],
    "status": "DONE",
    "note": "DONE 2026-10-03 in 4de344f. Three tests in SystemSettingUpdateTest. (1) test_the_api_channel_writes_an_audit_row_attributed_to_the_caller \u2014 one system_setting.updated row, causer_id is the caller, event asserted non-null because a row with a NULL event sits in the table and is skipped by every where('event', ...) filter. (2) test_the_api_channel_treats_an_omitted_key_as_untouched \u2014 the guard on the shipped bug: the web form always submits every field so its full-payload default is invisible from the browser, and a wrong default on the API resets password_min_length AND flips registration_enabled in one 200 response. (3) test_a_settings_audit_row_records_the_channel_it_came_from \u2014 asserts source is exactly web or api per channel, not merely one of the two: a settings change over the API recorded as web is the failure that matters here, and nothing else would notice. Sabotage-verified: causer->null red, partial true->false red, hardcoded source->web red, dropped user_agent red."
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
    "task": "Integrate Laravel Pulse (was Telescope + Periscope)",
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
      "DEP-004-laravel-pulse-observability.md"
    ],
    "tests": [
      "PulseFeatureGateTest"
    ],
    "note": "Telescope at FOUND-007 plus Periscope v0.3 as a companion UI, both removed in commit 85384b4 in favour of Laravel Pulse ^1.8 (DEP-004). The pulse feature flag gates /pulse through pulse.middleware (b08b8b4), verified by PulseFeatureGateTest. The pulse.view permission + viewPulse gate override is PLANNED in docs/planning/phase-11-monitoring-observability.md."
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