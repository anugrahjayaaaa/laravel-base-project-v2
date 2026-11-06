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
| FOUND-002 | Configure .env / .env.example | 1 | P0 | FOUND-001 | DONE |
| FOUND-003 | Set up config files (auth, cache, queue, session, mail, logging) | 1 | P0 | FOUND-001 | DONE |
| FOUND-004 | Install Sanctum for API auth | 1 | P0 | FOUND-001 | DONE |
| FOUND-005 | Install Spatie Permission (RBAC) | 1 | P0 | FOUND-001 | DONE |
| FOUND-006 | Install audit package (e.g. spatie/laravel-activitylog) | 1 | P0 | FOUND-001 | DONE |
| FOUND-007 | Install Laravel Pulse (was Telescope) | 1 | P0 | FOUND-001 | DONE |
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
| FLAG-001 | Feature flag package foundation | 1 | P1 | FOUND-003 | DONE |

**Audit pattern (cross-phase convention):** ✅ DONE — every mutation is audited
by the **Action that performs it**, inside that action's own transaction, via
`Auditable::audit()`: `$model->audit($event, $causer, $properties)`. Controllers
only orchestrate and MUST NOT call `audit()` for a mutation an action performs.
HTTP context (`source`, `ip`, `user_agent`) is captured automatically by
`Auditable::audit()`; callers pass only event-specific properties and may
override `source` (a job passes `system`). No model observers. See
`docs/base/architecture/application-boundaries.md` §
Action-First Audit Logging Standard and `docs/base/features/audit-trail.md`.

Migrated: User, System, Role, Feature (already compliant), Auth web + API, Profile.
`Controller::audit()` is deleted; there is no caller left to migrate.

---

### Phase 4 — User Lifecycle & User Management

| ID | Task | Phase | Priority | Depends On | Status |
|----|------|-------|----------|-----------|--------|
| USER-001 | Create user management module | 4 | P1 | AUTH-003 | DONE |
| USER-002 | Implement user list/detail API | 4 | P1 | USER-001 | DONE |
| USER-003 | Implement create user (admin) | 4 | P1 | AUTH-001 | DONE |
| USER-004 | Implement update user | 4 | P1 | USER-002 | DONE |
| USER-005 | Implement soft delete user | 4 | P2 | USER-001 | DONE |
| USER-006 | Implement activate/deactivate | 4 | P1 | USER-003 | DONE |
| USER-007 | Implement lock/unlock | 4 | P1 | USER-001 | DONE |
| USER-008 | Implement force password change | 4 | P1 | AUTH-014 | DONE |
| USER-009 | Implement admin reset password | 4 | P1 | AUTH-013 | DONE |

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

**Still true:** two of the seven flags control nothing, because
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

### Phase 9 — Notifications & Mail

> Groups A–E shipped (2026-10-05) and the shipped phase was re-audited 2026-10-08. P9-X1..X4 below are that audit's tasks: each is a defect found in shipped code, not a design change.

| ID | Task | Phase | Priority | Depends On | Status |
|----|------|-------|----------|-----------|--------|
| P9-A1 | `pages/notifications/index.blade.php` — `col-lg-8` SMTP form + `col-lg-4` Send Test Mail, `card border-0 shadow-sm mb-4`, footer `bg-body-tertiary` | 9 | P0 | — | DONE |
| P9-A2 | `pages/notifications/channels.blade.php` — per-channel toggles, hidden `value="0"` + checkbox `value="1"` | 9 | P0 | P9-A1 | DONE |
| P9-A3 | `NotificationUiRenderTest` (19 tests / 119 assertions) — zero queries from inside the views, forbidden classes, `filter_var` ban, sibling-page link + card-header action styling on both permission branches | 9 | P0 | P9-A1..A2 | DONE |
| P9-A4 | `NotificationController` stub (`index`/`channels`) + `notifications.index` / `notifications.channels` routes | 9 | P0 | P9-A1 | DONE |
| P9-A5 | Group A audit — mutation-verified guards, `p-4` revert, narrowing `send_test` gate; re-audited 2026-10-05 after D1/D2/D4, one gap closed (no sibling link on the mail page) | 9 | P0 | P9-A1..A4 | DONE |
| P9-B1 | `UpdateMailSettingsRequest` — authorize `notifications.manage`, port 1–65535, mailer `Rule::in(config('mail.mailers'))`, encryption `Rule::in(smtp/tls/ssl/none)`; `none` stored as the empty value | 9 | P0 | P9-A4 | DONE |
| P9-B2 | `NotificationMailSettingUpdateAction` — transaction, `encrypt()` on the password (written only when a new one was submitted), audit inside, rebind AFTER commit; `$partial` for API | 9 | P0 | P9-B1 | DONE |
| P9-B3 | `NotificationTestMailSendAction` — `Mail::raw` probe; every transport failure becomes a message + `Log::error` + `test_mail.failed`, never a 500 | 9 | P0 | P9-B1 | DONE |
| P9-B4 | `Web\V1` + `Api\V1` NotificationController sharing every action; API returns booleans + `has_password`, 502 on transport failure | 9 | P0 | P9-B2, B3 | DONE |
| P9-B5 | `route()` URLs in the controller + `->can('notifications.manage')` / `->can('notifications.send_test')` on the write routes | 9 | P0 | P9-B4 | DONE |
| P9-B6 | `AppServiceProvider::bindMailConfig()` — **called from the action after commit, NOT boot** (boot runs before the table exists) + 8 seeder keys | 9 | P0 | P9-B1 | DONE |
| P9-B7 | `NotificationChannelUpdateAction` + `UpdateNotificationChannelsRequest` + 3 `notification_channel_*` keys — admin-owned, no per-user table | 9 | P0 | P9-B6 | DONE |
| P9-C1 | `notifications` migration from `make:notifications-table` — Laravel's own stub, no hand-written schema | 9 | P0 | P9-B7 | DONE |
| P9-C2 | **DONE** — see phase doc for the correction and the reasoning | 9 | P0 | P9-B7 | DONE |
| P9-C3 | **DONE** — see phase doc for the correction and the reasoning | 9 | P0 | P9-B7 | DONE |
| P9-C4 | **DONE with D2** — one flat entry per admin page; the inbox gets NO entry, it is reached from the bell | 9 | P0 | P9-B7 | DONE |
| P9-C5 | **DONE** — see phase doc for the correction and the reasoning | 9 | P0 | P9-B7 | DONE |
| P9-C6 | **DONE** — see phase doc for the correction and the reasoning | 9 | P0 | P9-B7 | DONE |
| P9-D1 | `notifications.view`/`.manage`/`.send_test` in `PermissionCatalog` + `can()` on both web routes, same commit | 9 | P0 | — | DONE |
| P9-D2 | `feature:notifications` — pennant flag + routes + composer. **403, not 404** | 9 | P0 | P9-D1 | DONE |
| P9-D3 | Action-first audit `mail_setting.updated` + `test_mail.sent` / `test_mail.failed` + `notification_channels.updated`, inside the action and the transaction | 9 | P0 | P9-B2, B3 | DONE |
| P9-D4 | Header bell is a real `<a>` with the same two gates as the sidebar entry — `NotificationAccessTest` (14 tests) | 9 | P0 | P9-D1, D2 | DONE |
| P9-D5 | `feature:notifications` on `/notifications/inbox` — flag only, **no** permission (D-2) | 9 | P0 | P9-C5 | DONE |
| P9-D6 | Swap `NotificationUiRenderTest`'s `Gate::before` fixture for real seeded roles + precondition assertions | 9 | P1 | P9-D1 | DONE |
| P9-E1 | `NotificationSettingsTest` (19 tests) — SMTP round-trip into config, encryption at rest, RBAC 403, flag 403, audit rows, validation bounds | 9 | P0 | P9-D3 | DONE |
| P9-E2 | `mail_password` encrypted at rest — DB row holds no plaintext, decrypts back, transport receives the usable value | 9 | P0 | P9-B2 | DONE |
| P9-E3 | Inbox isolation — A cannot mark B's notification read (single route nor mark-all) | 9 | P0 | P9-C5 | DONE |
| P9-E4 | Target Audience Rule — admin event reaches only permission holders, personal reaches only its subject, unclassified reaches nobody, and every event names a declared permission | 9 | P0 | P9-C3 | DONE |
| P9-E5 | Bell target — href is `/notifications/inbox`, renders for a plain user, badge reflects the count | 9 | P0 | P9-C2 | DONE |
| P9-E6 | Full suite green (`1111 passed / 4263 assertions` at close; **1590 / 5396** today, after the 2026-10-08 audit passes and the test-suite regroup) + reconcile `progress.md` / `task-tracker.md` / `feature-tracker.md` / phase doc | 9 | P1 | P9-E1 | DONE |
| P9-E7 | Reconcile `docs/base/features/notifications.md` — channels trimmed, transport table rewritten, "config/env only" reversed, audience table split | 9 | P1 | P9-B2 | DONE |
| P9-E8 | Correct the conventions skill — `Permission::featureOf()` does not exist; the sidebar is `AppMenuComposer`, not `@feature()` in Blade | 9 | P2 | — | DONE |
| P9-E9 | Regression for the null-default binding bug — `an_install_without_a_configured_username_still_binds`, verified load-bearing by reintroducing the bug | 9 | P0 | P9-B6 | DONE |
| P9-E10 | Bell count cache — invalidated on delivery AND on mark-read; warm cache costs no query per render | 9 | P0 | P9-C2 | DONE |
| P9-E11 | `NotificationAccountStateAction` wired to the four user-state actions — the audience rule now has callers (was zero) | 9 | P0 | P9-C3 | DONE |
| P9-E12 | `notification_channel_in_app` read as the `database` gate in `NotificationChannel::for()` — was a switch that saved and changed nothing | 9 | P0 | P9-C1 | DONE |
| P9-E13 | `docs/base/features/notifications.md` rewritten to name only shipped events; declared-but-undispatched ones marked unreachable | 9 | P1 | P9-E7 | DONE |
| P9-X1 | **Credential boundary** — `SystemSetting::getAll()` returned `mail_password` ciphertext to the Phase 8 settings module, whose `settings.view` gate is wider than `notifications.view`; filtered by `SECRET_KEYS` and asserted on four read paths | 9 | P0 | P9-B2 | DONE |
| P9-X2 | **Delivery boundary** — 3 actions dispatched inside a transaction against `after_commit => false` queues; moved out, and every notification class now declares `$afterCommit = true` (the property alone is what survives a caller that owns the transaction) | 9 | P0 | P9-C3 | DONE |
| P9-X3 | **Cache + retention** — the bell's five-row list shared the count's TTL but not its invalidation; `forgetAllFor()` clears both. Plus a daily `notification-retention` sweep (read rows > 90 days), which the table had none of | 9 | P0 | P9-C2 | DONE |
| P9-X4 | **Abuse + channel boundaries** — `throttle:send-test-mail` on web and API (a permission is not a rate limit); `NotificationChannel::for(essential: true)` for the four flows with no alternative channel; dead `app/Models/Notification.php` deleted | 9 | P0 | P9-B3 | DONE |
| P9-X5 | **Copy** — every class repeated its own title in the body, and `sprintf('Your roles%s were changed.', $by)` produced a broken sentence. Rewritten against researched guidance; subjects now stand alone because the bell shows subjects only | 9 | P1 | P9-E7 | DONE |
| P9-X6 | **`NotificationCopyTest`** — 5 mechanical rules × 7 classes × both audiences, mutation-checked against the old wording | 9 | P1 | P9-X5 | DONE |
| P9-X7 | **`NotificationBenchmarkTest`** — 8 measurements + 3 query-count assertions; no N+1, nothing over 2.85 ms, warm bell 0 queries, inbox bounded by pagination, audience 2 queries at 1 or 21 admins | 9 | P2 | — | DONE |
| P9-X8 | **`NotificationPentestTest`** — 11 probes / 108 assertions: authorization ×4 levels ×2 surfaces, inbox isolation ×4, credential ×4 read paths, 8 injection payloads, 5 switch shapes, relay abuse, stored XSS, flag enforcement. No findings | 9 | P0 | — | DONE |
| P9-X9 | **Docs reconciled** — phase doc carries both audit passes; `notifications.md` carries the copy rules, the credential/retention limits and the audience gap; `progress.md` / `feature-tracker.md` / `implementation-roadmap.md` updated | 9 | P1 | P9-X1..X8 | DONE |
| P9-X10 | **Audience = the Gate's definition** — `NotificationAudience` reached holders only through `roles.permissions`, so a permission attached directly to a person passed `can()` and resolved to nobody; both branches now read. Mutation-checked | 9 | P0 | P9-C3 | DONE |
| P9-X11 | **One unread number** — the inbox counted unread rows live while the bell read the cache invalidated on delivery, so two screens could disagree on the same inbox; both read `UnreadNotificationCount` now. One query fewer per visit. Mutation-checked | 9 | P1 | P9-C2 | DONE |

**Group A ships. B–E do not exist yet.** The pages render and are reachable
behind the full auth stack, but nothing persists: no write endpoint, no
`notifications` table, no permission, no flag. That is deliberate — a `->can()`
against an unseeded permission is a 403 on every page, so each gate lands with the
permission it checks.

Detail: `docs/planning/phase-9-notifications-mail.md`.

### Phase 12 — API V1

> Breakdown written 2026-10-20, owner-closed 2026-10-21. Status of the *phase* is
> DONE; most endpoints shipped before the breakdown, so Groups B–D were
> audit-and-close rather than greenfield. The post-review pass fixed the inline
> validation, the `meta`-less refusals and the documentation drift found after
> the first close — see `phase-12-api-v1.md` § *Post-review fixes*.
> The per-task detail (27 tasks, P12-A1..E5) lives in
> `docs/planning/phase-12-api-v1.md`; this table is the summary only, to keep one
> authority per task list.

| ID | Task | Phase | Priority | Depends On | Status |
|----|------|-------|----------|-----------|--------|
| P12-A | Scramble docs interface — package placement, config, `viewApiDocs` gate, public/private switch, sidebar entry, UI render test | 12 | P0 | — | DONE — all seven tasks implemented and committed 2026-10-20 on `feature/p12-a-api-docs` |
| P12-B | Base responses & resources — one base controller (accepted), resource schemas, error envelope reconciliation | 12 | P0 | P12-A | DONE — every emitter builds through `ApiError`; the limiter's own 429 callback included |
| P12-C | Routes & controllers — verify + annotate every module, close the absent `GET /features` | 12 | P0 | P12-A, P12-B | DONE — no controller validates inline or reads `$request->query()` |
| P12-D | Security — token abilities decision, `can:` sweep, rate-limit ladder, bearer scheme, audit context | 12 | P0 | P12-C | DONE — D1..D7 implemented 2026-10-20; detail and measurements in `docs/planning/phase-12-api-v1.md` § Group D |
| P12-E | Tests — per-module API tests, `ApiDocumentationTest` drift gate, full verification | 12 | P0 | P12-D | DONE — four per-module API files (16 tests, both directions of each sibling gate), `ApiDocumentationTest` (9 tests: route→path+method, 401/403/422 reachability, `bearerAuth`, the contract envelope on every documented error, the authorization requirement on every gated operation, the unnamed `/v1/health` path, no status outside the contract), 8-code envelope matrix in `ApiErrorEnvelopeTest`, `ThrottleJsonResponseTest` asserting `meta` on the limiter's own answer; `ApiDocsUiRenderTest` retained from A4. The three `meta`-less middleware 403s found by E4 are fixed and pinned. Full suite and Pint clean; live count in `progress.md` § *Test Status*. Detail: `phase-12-api-v1.md` § *Post-review fixes* and § *Post-close audit* |

**Deviations from the brief, recorded rather than applied:** flags return **403**
(not 404 — Phase 7 decision, pinned by `FeatureFlagMiddlewareTest`); no second
`Api\V1\BaseApiController` (`App\Http\Controllers\Controller::respond()` is that
class); Scramble was dev-only in this phase and ADR-025 has since promoted it to
`require` and moved the document to a deploy-time export. See the phase doc
§ *Decisions that override the brief* (D-1..D-6).

**Post-close audit (2026-10-24):** eight findings, all closed — the scheme is
named `bearerAuth`; `ApiErrorResponseExtension` publishes the real error
envelope instead of Scramble's `{message}`; `AuthorizationDescriptionExtension`
states each route's `can:`/`feature:`; operations are tagged by module folder;
the reachable statuses inference cannot see are annotated (`#[Response]`); and
the three endpoints that had no API test (password reset, email verify,
notification channels) plus `PATCH /users/{id}` and cancel-email-change now have
one. The test factory no longer repeats a username. Detail:
`phase-12-api-v1.md` § *Post-close audit*.

### Phase 13 — Security Hardening

> Breakdown written 2026-10-26, status **IN PROGRESS** — Groups A, B and C **DONE**
> (owner-reviewed 2026-10-27); Groups D–E
> not started. Groups A, B, D2,
> E1 and E2 are audit-and-close because the controls shipped earlier (Phase 10's
> `AddSecurityHeaders`, `VerifyCsrfToken`, the exception envelope, the composite throttle
> keys); Group C and D1 carry the phase's real work. Five brief-vs-code conflicts are
> recorded as D-1..D-5 in the phase doc, the load-bearing ones being **no
> `X-Frame-Options`** (`frame-ancestors 'none'` already answers it) and **no global
> `'unsafe-eval'`** (scoped to `pulse.*`/`vantage.*` only).
> The per-task detail (14 tasks, P13-A1..E3) lives in
> `docs/planning/phase-13-security-hardening.md`; this table is the summary only.

| ID | Task | Phase | Priority | Depends On | Status |
|----|------|-------|----------|-----------|--------|
| P13-A | CSP & security headers — verify `AddSecurityHeaders`, unescaped-output/nonce audit, extend `SecurityHeadersTest` | 13 | P0 | — | DONE — implemented 2026-10-26, owner-reviewed 2026-10-27; 94 security tests green; detail in `phase-13-security-hardening.md` § Group A |
| P13-B | CSRF — verify strict middleware, `@csrf`/meta-token/AJAX audit, new `CsrfProtectionTest` | 13 | P0 | P13-A | DONE — implemented 2026-10-27, owner-reviewed 2026-10-27; fixed the group slot (was appended, `auth` sorted above it) and the dead 419 branch; `CsrfProtectionTest` 10/127 plus 259 adjacent tests green; detail in `phase-13-security-hardening.md` § Group B |
| P13-C | CORS — ship `config/cors.php` default-deny from `CORS_ALLOWED_ORIGINS`, new `CorsPolicyTest` | 13 | P0 | P13-B | DONE — implemented 2026-10-27, owner-reviewed 2026-10-27; one env key with derived credentials (D-6), `api/*` only, `HandleCors` untouched; `CorsPolicyTest` 8/41 plus 240 security/API tests green; detail in `phase-13-security-hardening.md` § Group C |
| P13-D | Input safety — output-sink audit, fillable/whitelist sweep, new `InputSanitizationTest` | 13 | P0 | P13-C | DONE — audited 2026-10-27, owner-reviewed 2026-10-27: no production code changed (sinks and whitelists already correct); `InputSanitizationTest` 6/31 fills the gaps, existing probes cited; `Security`+`User`+`Notification` 483/2005 green, Pint clean; detail in `phase-13-security-hardening.md` § Group D |
| P13-E | Disclosure & limits — HTML error masking, composite-key 429 proof, `--filter=Security` + full suite run | 13 | P0 | P13-D | DONE — implemented 2026-10-27, owner-reviewed 2026-10-27: `CompositeRateLimitKeyTest` 4/20 (key scope + `reset-password`/`email-verification` 429s that had no probe), `SecurityAuditTest` HTML 500 branch, ladder completed with the admin resend route; full suite 2241/8034, Pint clean — detail in `phase-13-security-hardening.md` § Group E |

### Phase 14 — Storage, Backup & Retention

> Breakdown revised 2026-10-29. Group A **REVIEW** (2026-10-29), Group B
> **REVIEW** (2026-10-30), Group F **REVIEW** (2026-10-31) — owner review
> pending, uncommitted on `feature/phase-14-storage-backup-retention`. Groups
> C–E still PLANNED. Seventeen tasks in six groups (A storage card/driver
> binding/capacity guard/S3 readiness → B backup strategy/async engine → C
> temporary retention/cleanup → D security/RBAC/feature flags/audit → E
> tests/verification/docs → F global timezone follow-up).
> Detail: `docs/planning/phase-14-storage-backup-retention.md`.

| ID | Task | Phase | Priority | Depends On | Status |
|----|------|-------|----------|-----------|--------|
| P14-A1 | Storage Management Card UI (`resources/views/pages/settings/index.blade.php`, `col-lg-8`) — reactive `local`/`s3` fieldset + capacity gauge | 14 | P1 | FOUND-003 | DONE |
| P14-A2 | Dynamic Driver Runtime Binding & Conditional Validation (`AppServiceProvider::bindStorageConfig()`, `SystemSettingRequest`, `SystemSettingsUpdateAction`, encrypted S3 credentials, `SECRET_KEYS`) | 14 | P1 | P14-A1 | DONE |
| P14-A3 | Storage Capacity Guard & Exception Handler (`StorageCapacityExceededException` → 507 `STORAGE_FULL`, `storage_max_capacity_mb`, warning threshold) | 14 | P1 | P14-A2 | DONE |
| P14-A4 | Secure Private Media Route & Signed URL Handler (`secure_documents`, `storage.view`, signed URL expiry) | 14 | P1 | P14-A3 | DONE |
| P14-A5 | Automated Storage Disk, UI & Capacity Test (`tests/Feature/Storage/StorageDiskTest.php`) | 14 | P1 | P14-A1, P14-A2, P14-A3, P14-A4 | DONE |
| P14-B1 | Spatie Backup Integration & Dynamic `SystemSetting` Control (MySQL DB + `storage/app`, dedicated `backups` disk, ADR first) | 14 | P1 | P14-A2 | DONE |
| P14-B2 | Pre-Backup Capacity Check & Asynchronous Execution (`ExecuteSystemBackupJob`, `QueueName::BACKUPS`), abort on full target | 14 | P1 | P14-B1, P14-A3 | DONE |
| P14-B3 | Automated Backup Strategy & Capacity Test (`tests/Feature/Storage/BackupStrategyTest.php`) | 14 | P1 | P14-B1, P14-B2 | DONE |
| P14-C1 | Retention & Threshold Policy Configuration (`temp_file_retention_hours`, `storage_warning_threshold_percent`) | 14 | P1 | DB-001 | DONE |
| P14-C2 | Scheduled & Capacity-Triggered Cleanup Job (`CleanTemporaryStorageJob`, `tmp` disk only, hourly dispatch throttle) | 14 | P1 | P14-C1, P14-A3 | DONE |
| P14-C3 | Automated Retention Test (`tests/Feature/Storage/DataRetentionTest.php`, audit logs + soft deletes preserved) | 14 | P1 | P14-C1, P14-C2 | DONE |
| P14-D1 | RBAC Authorization (`storage.view`, `storage.manage`, `backup.execute`) | 14 | P1 | RBAC-004 | DONE |
| P14-D2 | Feature Flag Middleware (`feature:storage_management` — management surface only, guard stays active) | 14 | P1 | P14-D1 | DONE |
| P14-D3 | Action-First Audit Trail Logging (`storage_setting.updated`, `backup.triggered`, `backup.aborted_storage_full`, `temp_storage.cleaned`, `storage.capacity_warning`) | 14 | P1 | P14-B2, P14-C2 | DONE |
| P14-E1 | Comprehensive Test Run (`php artisan test --filter=Storage`, full suite, Pint) | 14 | P0 | P14-A5, P14-B3, P14-C3, P14-D3 | DONE |
| P14-E2 | Update Documentation (`docs/base/infrastructure/storage.md`, `docs/base/operations/retention.md`, `docs/base/infrastructure/backup-disaster-recovery.md`, `docs/base/infrastructure/queue.md`, `docs/base/api/error-contract.md`, `progress.md`) | 14 | P2 | P14-E1 | DONE |
| P14-F1 | Global Timezone (`app_timezone` replaces `password_security_sweep_timezone`, `AppServiceProvider::bindTimezone()`, System Timezone card, `timezone:rebase`; ADR-027) | 14 | P1 | — | DONE |

**Invariants (non-negotiable):** Audit Trail / Activity Logs are never deleted; soft-deleted models are never force-deleted automatically; only the `tmp` disk is cleaned by TTL; backup runs asynchronously and never lands on the disk it backs up; storage access gated by RBAC + feature flag; the flag gates the management surface only, not the upload guard; disk binding falls back to config and never blocks boot; all jobs follow queue convention; audit rows written inside the transaction by the Action layer.

---

### Phase 15 — Comprehensive Testing

> Written 2026-11-04, status **DONE (owner-closed 2026-11-04, merged PR #41)** —
> Groups A–E all DONE. Seventeen tasks in five
> groups (A view/render → B unit → C feature/API lifecycles → D static
> analysis/security audits → E performance/coverage/CI).
> Groups C and D are mostly **audit-and-close**: the suites exist (Phases 3, 5,
> 9, 12, 13, 14), so each task builds a coverage map first and fills only the
> named gaps. Group D begins with an ADR — no static analyser is installed.
> Thirteen brief-vs-code conflicts are recorded as D-1..D-13 in the phase doc;
> the load-bearing ones are **no Pest**, **no Psalm/Enlightn**, **no
> `SanitizesInput`**, **no `RolePolicy`/`SystemSettingPolicy`**,
> **`testing.yml` must not duplicate the existing `ci-cd.yml`**, and
> **the PHPStan ledger holds own-code precision debt, not vendor noise**.
> Detail: `docs/planning/phase-15-comprehensive-testing.md`.

| ID | Task | Phase | Priority | Depends On | Status |
|----|------|-------|----------|-----------|--------|
| P15-A1 | Blade Component & Layout Render Tests (`tests/Feature/UI/BladeComponentRenderTest.php`, shell + partials + UI components, `bg-white`/`bg-light` sweep scoped to app views) | 15 | P1 | — | DONE — 12 tests; `bg-white`/`bg-light` and footer-token sweeps sabotage-verified |
| P15-A2 | Form & Interactive UI Render Tests (`tests/Feature/UI/FormViewRenderTest.php`, array payloads, zero-query render, `@error` slots) | 15 | P1 | P15-A1 | DONE — 7 tests; zero-query asserted on source, not on rendered pages (recorded in phase doc) |
| P15-A3 | Responsive & Theme Attribute Verification (`tests/Feature/UI/ResponsiveUiTest.php`, `.table-responsive`, `data-variant`, `@nonce`) | 15 | P1 | P15-A2 | DONE — 4 tests; found and fixed the unwrapped sessions table |
| P15-B1 | Domain Action Unit Suite (`tests/Unit/Actions/`, 50 actions across User/Auth/Role/Permission/Feature/System/Notification/Audit/BulkAction, behavioural rollback proof) | 15 | P1 | — | DONE (owner-reviewed 2026-11-04) — 143 tests; found + fixed the `PersistsRole` missing-key permission-strip, pinned by regression test |
| P15-B2 | Form Requests & Rules Suite (`tests/Unit/Requests/`, `tests/Unit/Rules/`, `BaseFormRequest` 422 contract, `PasswordStrengthRule`, username rules) | 15 | P1 | — | DONE (owner-reviewed 2026-11-04) — 15 tests; contract pinned centrally on `BaseFormRequest` |
| P15-B3 | Policies & Support Utilities (`tests/Unit/Policies/`, `tests/Unit/Support/`, `UserPolicy` at four actor levels, `PasswordPolicy`, catalogue/queue/url invariants) | 15 | P1 | — | DONE (owner-reviewed 2026-11-04) — 78 tests; `ApiError` left pinned by `ApiErrorEnvelopeTest` (no unit duplicate), `PasswordPolicyTest` already covered both entry points |
| P15-C1 | Web Auth & Security Lifecycle Suite (`tests/Feature/Auth/`, `tests/Feature/Security/` — login/logout, forced change, signed verification, composite rate keys, session revocation) | 15 | P0 | — | DONE (owner-reviewed 2026-11-04) — map built; 6 rows filled (deactivated login, tampered hash, force-change loop, revocation×3 through the real middleware stack in `WebSessionRevocationTest`); web verify carries NO signature/expiry check — recorded as a design question, not invented |
| P15-C2 | Web User Management & State Operations (`tests/Feature/User/` — CRUD, four toggles, restore, bulk, audit, sibling-permission probes) | 15 | P0 | P15-C1 | DONE (owner-reviewed 2026-11-04) — map built; every row already had an owner except bulk last-superadmin delete/force_delete (data sets added); active-superadmin force-delete 404s by trash-admission — recorded |
| P15-C3 | API V1 Contract & Resource Suite (`tests/Feature/Api/V1/` — bearer auth, `data.pagination`, eight error codes, exported-document integrity) | 15 | P0 | P15-C1 | DONE (owner-reviewed 2026-11-04) — map built; four pagination keys + `per_page` 10 + no `Link`/no `meta.pagination` pinned; flag-off 403-not-404 pinned on a REAL api.v1 route; real `scramble:export` diffed against the route table; brief's "409 untested on API" was wrong — already pinned in `LastSuperadminGuardTest` |
| P15-C4 | Storage, Backup & Notification Engine Suite (`tests/Feature/Storage/`, `tests/Feature/Notification/` — audit-and-close, deferred S3/restore rows named) | 15 | P0 | P15-C1 | DONE (owner-reviewed 2026-11-04) — map built; tampered-signature row added to `StorageDiskTest`; deferred named: real S3 endpoint, backup→restore cycle, cross-disk negative isolation, API list N+1 (E1) |
| P15-D1 | Static Analysis — PHPStan level 8 via Larastan (ADR + `changelog.md` first; committed baseline as a debt ledger) | 15 | P0 | — | DONE (owner-reviewed 2026-11-04) — ADR-030 + changelog first; `larastan ^3.14`/PHPStan 2.3.5, `phpstan.neon` level 8 over `app/`, `reportUnmatchedIgnoredErrors`; first run's 332 findings committed as an annotated 310-entry ledger (deviation D-12: mostly own-code PHPDoc debt, not vendor noise); sabotage-verified: new `return.type` violation exits 1, corrupted ledger entry exits 1; `composer analyse` script |
| P15-D2 | Code Style Gate — Pint over the full tree (replaces `--test --dirty`) | 15 | P1 | — | DONE (owner-reviewed 2026-11-04) — `pint --test` (484 files PASS); `fetch-depth: 0` removed with its `--dirty`-only reason; sabotage: one unformatted file exits 1 |
| P15-D3 | Application Security Audit Suite — extend in-house probes to the Phase 14 surface (Enlightn/Psalm declined, D-3) | 15 | P1 | P15-C1 | DONE (owner-reviewed 2026-11-04) — mapped first; 5 new rows: 507 web branch (message-only flash), guest + valid signed URL never serves bytes, cross-path signature replay 403, 507 added to the shared envelope matrix (handler documents nine codes, not eight), rebase refusal message + by-design production `--dry-run`; recorded not faked: `GenerateAuditExport` attack path and backup fail-open attack remain unprobed (owner tests pin the mechanism) |
| P15-D4 | Dependency Vulnerability Scanning — weekly scheduled `composer audit` / `npm audit` beside the existing PR run | 15 | P1 | — | DONE (owner-reviewed 2026-11-04) — `.github/workflows/testing.yml` Mondays 06:00 UTC, same thresholds as the unchanged PR run; actionlint clean on both files; audits exit 0 today; caveat: `schedule` only fires from the default branch — inert until merged |
| P15-E1 | Performance Guard — extend `tests/Feature/Performance/PerformanceGuardTest.php` (query counts, N+1 across chunk boundaries, peak memory, warm bell cache); wall clock recorded, not asserted | 15 | P1 | P15-C1, P15-C2, P15-C3, P15-C4 | DONE (owner-closed 2026-11-04, merged PR #41) — mapped first: web lists, bell warm path and the 3-vs-40 export were already pinned (`UserViewPerformanceTest`, `RbacPerformanceTest`, `AuditBenchmarkTest`, `NotificationBenchmarkTest` 0-query warm bell) — recorded, not duplicated. Three new guards, each sabotage-verified: API list 3→40 rows identical query counts over users/activity-logs/inbox (C4's deferred row; dropping `AuditIndexAction`'s eager loads turns it red, 23 vs 9); chunk-straddle 501 vs 502 rows in `AuditExportTest` (lives with its fixture, not copied into `PerformanceGuardTest`; chunk 500→1 turns it red, 1510 vs 1507); peak memory 24 MB bar over the five hot reads (measured 7.6 MB, the >2× bar the brief asks; a 40 MB allocation in the user index turns it red). Wall clock stays recorded in the existing benchmarks (D-8). ~5 s added to the suite. No production code changed. |
| P15-E2 | Coverage Thresholds — pcov driver, `--min=85` global, Actions ≥ 95 % and Policies/Security = 100 % from one clover run; scheduled, not per-PR | 15 | P0 | P15-A3, P15-B3, P15-C4 | DONE (owner-closed 2026-11-04, merged PR #41) — one clover run feeds everything: `php artisan test --coverage --min=85 --coverage-clover=…` (global, built-in) plus `bin/coverage_gate.php` summing three layers from the same file (XPath `//file` — PHPUnit nests files under `<package>`, `->project->file` finds nothing). First measured run: global 94.48 %, Actions 97.11 %, Policies 100 %, Requests **94.26 % — FAIL**. Closed with tests, not a lowered bar: `tests/Unit/Requests/RequestGuardsTest.php` (8 tests over the `authorize()` refusals and `withValidator()` cooldown/disabled throws — the branches the B2 rules suite never walks); re-run gives Requests **98.09 % (461/470)**, all bars OK, gate exit 0. Final global 94.87 % (5031/5303). Sabotage-verified: zeroing one Action's file metrics in a clover copy drops the layer to 94.73 %, prints the gap list, exits 1. Bar assignment for `app/Http/Requests` recorded as deviation D-13. Recorded, not deleted: the three auth Form Requests' `password()`/`remember()` accessors are dead code (12 statements, verified by exhaustive grep incl. tests) — a production deletion for the owner, not this testing phase |
| P15-E3 | CI Pipeline & Tracker Finalisation — `.github/workflows/testing.yml` owns only PHPStan + coverage + weekly audit; `ci-cd.yml` keeps the deploy gate | 15 | P0 | P15-D1, P15-D2, P15-E1, P15-E2 | DONE (owner-closed 2026-11-04, merged PR #41) — `testing.yml` is three trigger-split jobs: `phpstan` on every PR (~2 min, standalone so it never queues behind the suite), `coverage` on schedule + release tags (pcov via setup-php, `--min=85` + the gate), `dependency-audit` weekly (unchanged from D4). `ci-cd.yml` untouched — `git status` proves it; the deploy gate and `cancel-in-progress: false` stay as they were (D-7: one check, one owner). actionlint clean on both files. Acceptance: the PHPStan-error sabotage is the D1 one (same command the job runs); the coverage-regression sabotage is E2's clover gate run above. Trackers reconciled: E1–E3 at REVIEW with evidence, Group D flipped to DONE (owner-reviewed), `progress.md` carries the measured run (2661 passed / 9830 assertions, 9 risky, instrumented) |

**Invariants (non-negotiable):** no test is deleted or weakened to make a threshold pass; a missed threshold is a recorded gap with an owner; a new analyser or scanner needs an ADR before `composer require`; the vendor view tree is excluded from the design-system sweep; route-level `can:` remains the authorization pattern (no invented policies); one check has exactly one owner in CI.

---

### Phase 16 — Documentation Verification

> Breakdown written 2026-11-04, status **IN PROGRESS** — Groups A–D **DONE**
> (owner-reviewed 2026-11-04); Group E in **REVIEW** (2026-11-04). Fourteen
> tasks in five groups (A docs interfaces / Markdown compliance → B schema,
> Action-naming and setting-key mappings → C security/RBAC/backup/retention
> docs → D audit-event map and operational runbooks → E link-integrity gate,
> full-suite regression, tracker closure). Audit-and-close throughout: most
> properties already have an owner test (`ApiDocumentationTest`,
> `ApiDocsUiRenderTest`, `OpenApiExportTest`, the Phase 13 security suites,
> `JobConventionTest`); this phase extends or cites, never duplicates. Seven
> brief-vs-code conflicts recorded as D-1..D-7 in the phase doc, the
> load-bearing ones being **no in-app Markdown renderer**, **no new
> `DocsUiRenderTest` beside the existing `ApiDocsUiRenderTest`**, **doc paths
> moved under `docs/base/`**, and **~60 setting keys, not 37**.
> Detail: `docs/planning/phase-16-documentation-verification.md`.

| ID | Task | Phase | Priority | Depends On | Status |
|----|------|-------|----------|-----------|--------|
| P16-A1 | API Documentation UI verification (`/docs/api` + `/docs/api.json`) — run and read the existing owners (`ApiDocsUiRenderTest`, `ApiDocumentationTest`, `OpenApiExportTest`), manual Scalar pass, every documented status reachable in the schema | 16 | P1 | — | DONE (owner-reviewed 2026-11-04) —  owners run green (22/70 incl. real export diff); exported doc measured: 48 paths/59 ops, statuses all inside the contract; 2 findings filed: JR001 `HealthCheckResource` inference (→ B1) and 507 per-op absence = by-design web-only, handler-pinned (recorded exception) |
| P16-A2 | Markdown & design-system compliance sweep of ~95 `docs/**/*.md` (heading hierarchy, relative links, code-block info strings); scripted, baseline recorded, no wholesale reformat | 16 | P1 | — | DONE (owner-reviewed 2026-11-04) —  96 files scripted: 1 broken link (`soft-delete.md` → moved ui doc, fixed, anchor verified) + 1 real heading skip (`remediation-tracker.md`, promoted); 4 of 5 apparent skips were fence-comment false positives; fences-with-info 46 % = mass violation → house style, NOT gated (E1 gates links+headings) |
| P16-A3 | Docs render gate — extend the owning files with any A1-found gap; no duplicate `DocsUiRenderTest` (D-4) | 16 | P1 | P16-A1, P16-A2 | DONE (owner-reviewed 2026-11-04) —  no code produced: both A1 findings are schema/annotation rows owned by B1, not render properties; extending `ApiDocsUiRenderTest` would copy existing assertions (§ 2.3) |
| P16-B1 | API endpoint schema & annotation audit — per-endpoint table PHPDoc/Scramble ↔ test-asserted payload ↔ contracts; ❌ rows fixed keeping `ApiDocumentationTest` green | 16 | P1 | — | DONE — owner-reviewed 2026-11-04 — walked the exported doc (48 paths/59 ops): every response carries a schema (7 "missing" candidates were anyOf/text-csv false positives), zero statuses outside the contract; A1 JR001 closed as cosmetic (health schema exports complete from its  shape — the class docblock already reasons it); 507 per-op absence recorded as the by-design web-only exception; all 8 resources keep their shapes; the two Resource-less controllers are the documented Phase 12 passthrough. Zero fixes — proof, not assumption |
| P16-B2 | Action naming reconciliation — enumerate all 50 `app/Actions/V1` classes against `{Domain}{Operation}Action`; doc domain list = directory list (D-2 paths) | 16 | P1 | — | DONE — owner-reviewed 2026-11-04 — enumeration over 54 files found the drift inside the doc: prose said "every class" while its own example used BulkActionProcessor. Docs-only fixes: sentence scoped to Action classes + named exception family (BulkActionHandler/User/Role handlers/Processor), dead example SystemUpdateSettingsAction → SystemSettingsUpdateAction, domains table 6→9 rows (Audit/Feature/Notification) with every example verified to resolve. Enforcement already owned by ActionNamingConventionTest (3 green) — no new Arch test |
| P16-B3 | SystemSetting key mapping audit — enumerate validated keys from `SystemSettingRequest::rules()` (~60, floor not ceiling), every key documented in its owning feature doc and vice versa | 16 | P1 | — | DONE — owner-reviewed 2026-11-04 — measured 75 keys, not 37: 66 in `SystemSettingRequest` + 9 mail keys in `UpdateMailSettingsRequest` (its own request since Phase 9); all found in `docs/base`, 0 undocumented; `settings.md` key block diffs 66↔66 exact in both directions; the two odd expiry names are past-tense REMOVED keys, correct as written; one-off PHP diff, no harness landed |
| P16-C1 | RBAC & flags docs audit — `PermissionCatalog` vs `authorization.md` / `roles-permissions.md`; `FeatureCatalog` vs fail-closed Pennant behaviour (flag OFF = 403 both channels) | 16 | P1 | — | DONE — owner-reviewed 2026-11-04 — audited 2026-11-04: catalog (36 perms) diffed against all three docs; 3 stale spots fixed (`roles.manage` prose → per-operation gates, `dependencies/overview.md` invented names, `feature-flags.md` missing `storage_management` + `api_docs.view` rows); `roles-permissions.md` verified clean |
| P16-C2 | Backup & retention docs audit — `docs/base/infrastructure/backup-disaster-recovery.md` + `docs/base/operations/retention.md` vs Phase 14 (audit logs never pruned, `tmp`-only TTL, pentest findings #1–#8); restore procedure written (D-3 paths) | 16 | P1 | — | DONE — owner-reviewed 2026-11-04 — audited 2026-11-04: backup doc accurate on every mechanism; the generic Restore Procedure replaced with a concrete operator runbook whose zip layout (db-dumps/mysql-<db>.sql plain, absolute-minus-slash file paths, ZipCrypto) was verified from the shipped vendor source; round trip stays deferred-as-deferred. retention.md: 4 no-mechanism rows corrected (reset token 15 min, revoked tokens deleted-on-revoke, failed jobs/logs = operator policy), `retention.*` pseudo-keys relabelled design targets, Vantage 14 d row added |
| P16-C3 | Security hardening & CSP docs — headers/nonce/`frame-ancestors`, CSRF group swap, CORS default-deny, composite rate keys, HTML 500 masking; every doc claim cites its pinning suite | 16 | P1 | — | DONE — owner-reviewed 2026-11-04 — audited 2026-11-04: limiter table re-derived from `RateLimiter::for()` (15 registered, 14 documented — `system-backup` row + ladder line added) and `web-security.md` "Policy-based" authorization line corrected to the route-level pattern; headers/CSRF/CORS/CSP prose already cites its suites; `--filter=Security` 139/718 green, Pint clean |
| P16-D1 | Audit event map — build the complete `## Event Map` in `docs/base/features/audit-trail.md` from `Auditable::audit()` call sites (incl. `storage.capacity_warning`, `causer='SYSTEM'` rows); zero observer writers | 16 | P1 | — | DONE — owner-reviewed 2026-11-04 — built 2026-11-04 from call sites: 48 events across 5 subject groups, each row citing its owner; SYSTEM-marker semantics recorded as implemented (null causer + properties marker); brief's four examples present; `ActionFirstAuditTest` cited (green 38/101 with `DataRetentionTest`) |
| P16-D2 | Operational runbooks — `docs/base/operations/*` walkable cold (worker invocation, scheduler, job failure handling, `timezone:rebase --force`); gap list for operator-needed source reading | 16 | P2 | P16-C2 | DONE — owner-reviewed 2026-11-04 — walked cold 2026-11-04; every command verified to resolve (`bin/run-workers.sh` subcommands, `backup:list`, rebase flags); 4 defects fixed: phantom `audit.enabled` diagnosis, malformed tools table, stale MAIL_MAILER-first triage, deployment.md missing the process-start step entirely; gap list: empty for the walked surface |
| P16-E1 | Link-integrity gate — new test pinning A2's mechanical properties (relative links resolve, info strings where the convention holds); sabotage-verified, <1 s | 16 | P1 | P16-A2 | DONE — closed 2026-11-05 by P17-D2 — `tests/Arch/DocumentationLinkIntegrityTest.php`: 3 gates (relative links resolve, heading hierarchy never skips, no table row starts with a double pipe) over every `docs/` Markdown file, fence-aware. Green 3/3 in <1 s; each gate sabotage-verified individually (broken link, h2→h4, `||` row) before being called a gate. The pipe gate paid for itself on first run: it caught `||` corruption in README rows the manual sweep had missed. **Closure evidence:** re-run green 3/3 in P17-C2's record and again 2026-11-05 over the Group D docs (including the new `final-release-summary.md`) |
| P16-E2 | Full regression run — `pint --test`, `phpstan analyse`, `php artisan test`; counts recorded from the run, not hand-carried | 16 | P0 | all A–D | DONE — closed 2026-11-05 by P17-D2 — three gates on the final tree, all exit 0: Pint PASS (486 files), PHPStan exit 0 against the committed ledger, `php artisan test` **2665 passed / 9834 assertions, 9 risky**. The +4 tests / +4 assertions over Phase 15 Group E's 2661/9830 are `DocumentationLinkIntegrityTest` alone — docs-only phase, no other suite movement, exactly the one allowed change. Carried into `progress.md` § *Test Status* verbatim from the run's own output. Post-run: one Pint `no_unused_imports` fix in the E1 test itself (FQCNs → imports; behaviour identical), Pint then PASS 487 files and the gate re-run green 3/3. **Closure evidence:** superseded by a newer measurement of the same three gates on the same tree — P17-A1 re-ran the suite (2665/9834, exit 0, counts identical) and P17-B1/B2 re-ran Pint and PHPStan; the row is closed on Phase 17's log, not on a re-assertion of its own |
| P16-E3 | Trackers reconciliation & closure — `task-tracker.md` rows, `qa-tracker.md`, `progress.md` (row 16 + Documentation Status re-dated), `implementation-roadmap.md`; rows flip only with evidence | 16 | P2 | P16-E1, P16-E2 | DONE — closed 2026-11-05 by P17-D2 — reconciled four surfaces against measured state: this file's P16 rows A–D DONE / E rows REVIEW with evidence (none flipped without one); `qa-tracker.md` measured 0 open / 0 pending; `progress.md` row 16 updated, rows 3 and 6 flipped to DONE on ledger aggregation (24/24 and 42/42 tasks DONE parsed from the tracker's own JSON), § *Test Status* set to the E2 run's exact numbers, § *Documentation Status* re-marked per-area with the audit that backs each (Testing/UI left bare "Complete" — not audited this phase, no false verification); `implementation-roadmap.md` phases 0–15 → DONE (11–16 were stale), 16 IN PROGRESS, 17 PLANNED — the four trackers now agree with each other and the repo. **Closure evidence:** its own claim "`qa-tracker.md` measured 0 open / 0 pending" was a different question from row-level reconciliation, and P17-D2 answered that one (99 scenario rows read against the suite). The three rows E3 wrote as REVIEW are now DONE (E1, E2 above); roadmap 16/17 flipped; `progress.md` rows 16 and 17 closed. What E3 deliberately left undone — Testing and UI had no verification row — is closed by P17-D1 |

**Invariants (non-negotiable):** docs are corrected toward code-with-ADR, never toward aspiration; a contract the code broke is a defect with a regression test, not a doc rewrite; one check has one owner (no duplicate render/schema tests); no new Composer/npm package without an ADR; no runtime behaviour changes in this phase.

---

### Phase 17 — Full Regression & Final Review

> Breakdown written 2026-11-04; **Groups A, B and C run 2026-11-04 and closed by
> P17-D2 on 2026-11-05; Group D run and recorded 2026-11-05 — see
> `docs/planning/phase-17-full-regression.md` § 11 and
> `docs/planning/final-release-summary.md`.** Phase 17 is closed as a phase; the
> work is uncommitted and awaits owner review (`AGENTS.md`: the owner commits).
> Twelve tasks in four groups (A full-suite + coverage bars + UI render → B
> Pint, PHPStan, dependency audits → C asset build, `optimize`, scheduler set →
> D code-to-docs reconciliation, tracker closure, release summary). **This is a
> run-and-record phase, not a build phase:** every A/B gate was built and
> sabotage-verified in Phase 15 (coverage bars in `bin/coverage_gate.php`,
> PHPStan level 8 + ADR-030 ledger, full-tree Pint, `composer audit` /
> `npm audit` in CI) and the docs gates in Phase 16. The genuinely new work is
> C2 (`optimize` + warm-cache page walk), C3 (the six scheduler entries audited
> as a set), and D1–D3. Ten brief-vs-code conflicts recorded as D-1..D-12 in
> the phase doc, the load-bearing ones being **Psalm is not installed and one
> analyser owns the ledger**, **no tree-wide "zero queries in Blade" gate exists
> (it is proven per page)**, **`qa-tracker.md` has 58 of 99 rows unreconciled so
> a blanket CLOSED stamp would make the tracker lie**, and **the Vite build ships
> a Tailwind stylesheet no layout loads while `AGENTS.md` says "No Tailwind"** —
> an owner decision, reported not deleted. Phase 16 Group E must leave REVIEW
> before P17-D2 flips any row.
> Detail: `docs/planning/phase-17-full-regression.md`.

| ID | Task | Phase | Priority | Depends On | Status |
|----|------|-------|----------|-----------|--------|
| P17-A1 | Comprehensive backend test run — `php artisan test` on the final tree; 0 failures/errors, the 9 risky accounted for not absorbed; every named surface shown present (Auth, User CRUD, RBAC, Settings, Flags, Notifications, Storage, Audit, API V1); the printed count quoted into `progress.md` verbatim | 17 | P0 | — | DONE — closed 2026-11-05 by P17-D2 — `XDEBUG_MODE=coverage php artisan test --coverage --min=85 --coverage-clover=build/coverage/clover.xml` on `feature/phase-17-full-regression`: exit 0, **`Tests: 9 risky, 2665 passed (9834 assertions)`**, Duration 763.70 s, 212 `PASS` class blocks, zero `FAIL`/`Error`. Counts identical to P16-E2's 2665/9834 — nothing in this phase has touched code, so a moved number would have been the finding; the run reports the same **9 risky** as P16-E2 (attribution P15-E1, accounted for not absorbed; matched **by count** — this run's printer emitted the summary line only, no per-test risky detail, so their identity was not re-proven by name). Every named surface verified present in the run log by class, not by assumption (Auth `WebSessionRevocationTest`, `User/User*`, `RbacPerformanceTest`, `VantagePermissionGateTest`, `ActionNamingConventionTest`, `SettingsPentestTest`, `SettingsUiRenderTest`, `FeatureFlagMiddlewareTest`, `StorageAuthorizationTest`+`BackupStrategyTest`, `ActionFirstAuditTest`+`AuditExportTest`, `ApiDocumentationTest`+`ApiErrorEnvelopeTest`+`OpenApiExportTest`, 19 `Api\V1` + 1 `Web\V1` classes). Unit/Feature/Arch all ran — plain command, no filter. Line quoted into `progress.md` § *Test Status* from the run's own output |
| P17-A2 | Coverage threshold assertion — one instrumented run feeding `--min=85` + `bin/coverage_gate.php` (Actions ≥ 95 %, Policies = 100 %, `app/Http/Requests` ≥ 95 %); xdebug locally vs pcov in CI recorded as the cost; a miss is a gap list with owners, never a lowered bar | 17 | P0 | P17-A1 | DONE — closed 2026-11-05 by P17-D2 — one clover run feeds all four bars; `php bin/coverage_gate.php build/coverage/clover.xml` exits 0: **Actions 97.11 %** (1547/1593, bar 95) · **Policies 100 %** (31/31) · **Requests 98.09 %** (461/470, bar 95), and the run's own `--min=85` floor held with global **94.87 %** (5031/5303) read off the same file's `<project>` metrics. Every figure is unchanged from P15-E2 — the expected result of a docs-only interval, and the reason no bar or gate was touched. Local driver is **xdebug** (CI's coverage job uses pcov), so the 763.70 s wall clock is the cost of measuring, not a defect (D-11 honoured). No new gate written (rule § 2.2); no re-sabotage of a gate that did not change (D: P15-E2 already proved it). `app/Security` remains a non-namespace — security surface cited as the suites that ran inside A1 (D-3) |
| P17-A3 | UI & Blade render regression — run the owners (`tests/Feature/UI/` 23 tests + the four page render suites) and the Phase 15 design-system sweeps; the "no direct DB queries in templates" property recorded as **proven per page by query-count gates**, with unproven pages named as a gap (D-4) | 17 | P1 | P17-A1 | DONE — closed 2026-11-05 by P17-D2 — owners run individually, all **OK**, no new file: `BladeComponentRenderTest` 12/114 · `FormViewRenderTest` 7/66 · `ResponsiveUiTest` 4/129 (= the 23) · `SettingsUiRenderTest` 14/358 · `RbacUiRenderTest` 20/114 · `NotificationUiRenderTest` 19/128 · `HealthUiRenderTest` 11/50 · and the **three the phase doc did not name** — `AuditLogUiRenderTest` 27/116 · `FeatureFlagUiRenderTest` 14/109 · `ApiDocsUiRenderTest` 9/38 — 137 tests green. Phase-doc omission, not a code gap: the tree has seven page render suites, § 3 said four. Design-system sweeps re-run as part of those files (forbidden `bg-white`/`bg-light`, `.table-responsive` wrapper, `@nonce` on every inline script) — nothing rewritten. D-4 holds and is now written down with names: **eleven** page groups carry a query-count gate (zero-on-warm-render or count-does-not-grow), and the unproven remainder is listed, not claimed — `users/create`, `users/show`, `profile/edit`, `sessions`, `notifications/show`, the six auth pages, `welcome`, layout partials other than sidebar and header bell. No global "views run zero queries" test invented. Record: `docs/base/testing/testing-matrix.md` § *UI render & view-query coverage* + phase doc § 11 |
| P17-B1 | Code style gate — `vendor/bin/pint --test` full tree (PSR-12 preset, since P15-D2); exit 0 with file count recorded; failures fixed per file, not by adding ignores | 17 | P1 | — | DONE — closed 2026-11-05 by P17-D2 — `vendor/bin/pint --test` on `feature/phase-17-full-regression`: **exit 0, PASS 487 files** (PSR-12, whole tree). Nothing was reformatted and no ignore was added — the count is unchanged from P16-E2's post-fix 487, which is what a docs-only interval should produce. Exit code read from the tool (`cmd > log 2>&1; echo $?`), not from a pipeline tail |
| P17-B2 | Static analysis — `vendor/bin/phpstan analyse --memory-limit=1G` at **level 8** over `app/` against the committed ADR-030 ledger; baseline entry count recorded, growth is a finding; Psalm declined (one analyser, one ledger — D-2) | 17 | P1 | — | DONE — closed 2026-11-05 by P17-D2 — `vendor/bin/phpstan analyse --memory-limit=1G`: **exit 0, `[OK] No errors`, 208 files at level 8** (Larastan ^3.14, ADR-030). Ledger measured from `phpstan-baseline.neon`: **310 entries** (2171 lines) — identical to the P15-D1 committed figure, so no debt grew and none was quietly paid; `reportUnmatchedIgnoredErrors: true` makes a stale entry fail the run, so exit 0 is also the proof the ledger still matches the code. No second analyser installed (D-2). `docs/base/testing/testing-strategy.md` left alone — it does not quote the entry count, so its figures are not stale (conditional Docs clause not triggered) |
| P17-B3 | Dependency & vulnerability audit at the release tree — `composer audit --locked` + `npm audit --audit-level=high`, same thresholds CI uses per-PR and weekly; every advisory listed with package/severity/owner decision, dev-only vs runtime distinguished | 17 | P1 | — | DONE — closed 2026-11-05 by P17-D2 — release-tree read, not the CI schedule: `composer audit --locked` **exit 0** (`No security vulnerability advisories found.`) and `npm audit --audit-level=high` **exit 1 on the first read** — `vite 8.3.0 - 8.3.2`, **6 high advisories** (GHSA-698v-4r3x-3vcv, GHSA-r825-w8mm-5pxw, GHSA-587h-7wmj-h5pm, GHSA-9jrq-w75r-8gcw, GHSA-rq7h-c2jc-7f22, GHSA-vfpm-58rq-9qcg). **Risk class: dev-only** — `package.json` has no runtime dependencies at all (vite, laravel-vite-plugin, @tailwindcss/vite, tailwindcss, bootstrap, concurrently are devDependencies) and every issue is a dev-server or `launch-editor` behaviour that cannot exist in a built asset, so no shipped byte was exposed; but the step lives inside the `test` job and `deploy` is `needs: test`, so it blocked the release path. **Owner decision: upgrade** (asked, answered — not chosen by this task, per § 2.3). Applied `vite 8.3.0 → 8.3.5`, **`package-lock.json` only** (20 version entries, 81 lines; `package.json`'s `^8.0.0` already allowed it, no package added or removed, no ADR trigger). Verified with CI's own sequence: `npm ci` exit 0 → `npm run build` exit 0 (manifest 9 entries; `app-*.css` 49.62 kB, `app-*.js` 15.00 kB, fonts 2.35 kB) → `npm audit --audit-level=high` **exit 0, `found 0 vulnerabilities`**. D-8 untouched: the built Tailwind stylesheet is still loaded by no layout (C1's owner decision). `changelog.md` line added; no new workflow, no duplicated job (D-5) |
| P17-C1 | Frontend asset compilation — `npm ci && npm run build`, exit 0 with bundle sizes recorded; settle the Tailwind conflict on paper: `resources/css/app.css` is a Vite input importing Tailwind while no layout links the built CSS (all CSS is self-hosted from `public/vendor/`) and `AGENTS.md` says "No Tailwind" — **owner decision, reported not unilaterally deleted** (D-8) | 17 | P1 | — | DONE — closed 2026-11-05 by P17-D2 — `npm ci` **exit 0** → `npm run build` **exit 0** (vite 8.3.5, the B3 bump; `✓ 9 modules transformed`, `✓ built in 4.12s`). Sizes from the manifest: `app-*.css` **49.62 kB** (gzip 9.29) · `app-*.js` **15.00 kB** (gzip 4.62) · `fonts-*.css` 2.35 kB · 6 font files · 9 entries total. D-8 measured, not assumed: `resources/css/app.css` is 9 lines (tailwind import, 2 `@source` hints, one `--font-sans` token) with **no project rule in it**; all three layouts `@vite` only the JS (`app.blade.php:61`, `guest.blade.php:31`, `auth.blade.php:83`) and neither entry carries a `css` array; `Paginator::useBootstrap()` (`AppServiceProvider:70-71`) means the Tailwind-classed pagination views never render; `@viteFonts()` appears nowhere, so the font CSS + 6 files are unreferenced and `--lbp-font: 'Instrument Sans'` (`theme.css:21`) falls back to `system-ui`. Dead bytes, not a broken page → **nothing deleted**, decision handed to the owner (drop the input + `tailwindcss`/`@tailwindcss/vite`, or load the CSS + amend `AGENTS.md` by ADR). `AGENTS.md` untouched |
| P17-C2 | Cache optimization & warm-boot verification — `optimize:clear` → `optimize` (config/events/routes/views), then a page walk returning 200 with caches warm; `env()`-outside-config scan (currently zero) and no closures in `config/` recorded; DB-backed `SystemSetting` + feature-flag mutation proven still effective under a warm config cache; dev state restored afterwards | 17 | P1 | P17-C1 | DONE — closed 2026-11-05 by P17-D2 — **found the deploy blocker**: first `php artisan optimize` **exit 1** — `LogicException … value at "scramble.security_strategy.1.scheme" is non-serializable` (`ConfigCacheCommand.php:77` serialises via `eval(var_export())`, so a live `SecurityScheme` object in config breaks the documented deploy steps 6-8). Fixed in-group, behaviour unchanged: new `app/Support/OpenApi/BearerAuthSecurityStrategy.php` supplies the scheme as a constructor default, `config/scramble.php` is scalar again, and `tests/Arch/SerializableConfigTest.php` gates it (every object config leaf must have `__set_state` or be an enum). Re-run **exit 0**: config 44.78 ms · events 1.92 ms · routes 67.70 ms · views 1 s, all DONE. Sabotage-verified: putting the object back failed the gate naming that exact key and failed `config:cache` on the same key (first attempt was a false green — `bootstrap/cache/config.php` shadows the files until `config:clear`). **16 pages → 200** with caches warm (`/ /dashboard /users /users/create /profile /sessions /roles /permissions /features /settings /notifications/inbox /notifications/channels /activity-logs /health /pulse /docs/api`) in **one** login session — per-page re-login hits the `login` limiter (5/min identifier+IP, 10/min IP), and the limits were not raised. Runtime mutability proven under warm config: Pennant `notifications` OFF → inbox **403** → ON → **200**; `api_docs_public` false → true → guest `/docs/api` **200** → restored → **302** (DB reads, `DB::afterCommit` busting — the plan's § 5 "no closures ⇒ nothing to serialise" inference was wrong and is corrected there). `env()` outside `config/` re-scanned at **zero live calls**. `optimize:clear` **exit 0** afterwards — dev state restored. Post-change gates: Pint **PASS 489 files** (487 + the two new files), PHPStan **exit 0** 209 files ledger still 310, `ApiDocumentationTest` green 10/21 (the `bearerAuth` name survives the move). `tests/Arch/DocumentationLinkIntegrityTest` re-run 3/3 after the docs edits. Full suite re-measured: `php artisan test` **exit 0 — 2668 passed / 9837 assertions, 9 risky** (498.58 s); the +3 over A1's 2665/9834 is attributed by a per-class diff of the two logs — `SerializableConfigTest` 0→1 and `NoInlineFqnTest` 424→426 (one case per PHP file under `app/`+`tests/`, and this fix added two files) — nothing else moved. Findings filed, not fixed: `bin/fetch-page.sh` default credentials are stale (`LocalTest#2026` vs seeded `#Password123`, `SuperAdminSeeder:24`); local dev DB has a **Pending** migration (`2026_11_03_000000_remove_duplicate_expiry_settings`) deliberately **not** run |
| P17-C3 | Scheduler & queue set audit — six entries in `routes/console.php` (`password-security-sweeps`, `system-backup` every-minute both self-gating on a stored time; `temp-storage-cleanup`, `notification-retention`, `audit-export-retention`, `vantage-retention` daily) cross-checked against `schedule:list`; the 00:00 collision answered (queues `sweeps` vs `backups`, `withoutOverlapping`/`onOneServer`, worker listening); `JobConventionTest` cited | 17 | P2 | — | DONE — closed 2026-11-05 by P17-D2 — `php artisan schedule:list` **exit 0**: exactly the six entries in the table, no seventh, none missing (`routes/console.php:124-259`). **The 00:00 question, answered: safe, because of the queue split rather than the guards** — both `everyMinute` closures self-gate off the same `app_timezone` clock, but dispatch to different queues (`sweeps` for `PasswordExpirySweep:44` + `InactivityLockSweep:41`, `backups` for `ExecuteSystemBackupJob:47`) and `bin/run-workers.sh` listens on both (`default,exports,sweeps` + a dedicated `queue:work backups`); defaults are not even the same minute (sweeps `00:00`, `SystemBackupSchedule::DEFAULT_TIME = '02:00'`, `backup_enabled = false` locally). Single-flight: all six `withoutOverlapping`, backup alone also `onOneServer` plus job-level `WithoutOverlapping('system-backup')->expireAfter(3600)` with `$tries = 1`, `$timeout = 1800`, `$maxExceptions = 1`; queue names verified against `App\Support\QueueName`, `JobConventionTest` cited as standing enforcement (green inside A1). Two things the command cannot show (D-9 confirmed): the every-minute closures print as names only — the second is a `dispatchIfDue()` indirection — and the Vantage entry prints its **command string** instead of its `->name('vantage-retention')` label, because `ScheduleListCommand.php:93` prefers `$event->command` when set: a printer quirk, the label is in the file. One overlap named, unchanged: `CleanTemporaryStorageJob` runs nightly **and** from `StorageCapacityGuard:301`, so two can be concurrent (`withoutOverlapping` guards the entry, not the ad-hoc dispatch) — idempotent by `temp_file_retention_hours`, so safe |
| P17-D1 | Final code-to-docs reconciliation — re-date every `docs/base/` area in § *Documentation Status*, **including Testing and UI, which have no verification row today**; resolve or file the C1 CSS conflict; code-contradicts-contract = defect + regression test + `changelog.md`, not a doc edit; `docs/base/architecture.md` path correction honoured (D-9) | 17 | P1 | P17-A1..C3 | DONE — 2026-11-05 — Testing and UI now carry dated verification rows in `progress.md` § *Documentation Status* (the two Phase 16 left bare); the bars written into `docs/base/testing/testing-strategy.md` are the enforced ones from `bin/coverage_gate.php` plus the measured 2026-11-04 figures, and the P17-C2 gate is documented there; the D-9 path note is recorded against `docs/base/architecture/architecture.md`. The C1 conflict is **filed, not silently fixed**: `docs/base/ui/ui-adminlte-setup.md` now states the doc-vs-config contradiction with the measurements (`@vite` passes only the JS in all three layouts, no `css` array in the manifest, `@viteFonts()` unused, `Paginator::useBootstrap()`) and the two owner options — no code-contradicts-contract defect, so no new test or `changelog.md` line was owed. `DocumentationLinkIntegrityTest` green 3/3 over the edited tree |
| P17-D2 | Tracker closure — reconcile `qa-tracker.md`'s **58 unreconciled rows** (49 PLANNED / 6 TODO / 3 PARTIAL of 99) against the suite row by row, close Phase 16's three REVIEW E rows on their own evidence, flip `task-tracker.md` / `progress.md` / `implementation-roadmap.md`; deferred runtime evidence and recorded delete-candidates must survive into § *Known Issues*, not vanish at closure (D-10) | 17 | P0 | P17-D1, P16-E1..E3 | DONE — 2026-11-05 — `qa-tracker.md`: all 99 scenario rows read against the suite; the 55 that still read PLANNED or TODO (of the 58 the plan named — the other 3 were already PARTIAL) now read **36 DONE / 5 PARTIAL / 13 UNPROVEN / 1 N/A**, whole file **77 DONE / 8 PARTIAL / 13 UNPROVEN / 1 N/A**, and every DONE row cites the test file and method whose assertion was opened and read. The 21 unproven/partial rows are **left open with the missing assertion named**, not stamped — three of them (QA-AUDIT-004, QA-AUDIT-008, QA-MON-010) are marked `PARTIAL — needs a human` because no automated test can close them. P16-E1/E2/E3 closed above. This file's nine Phase 17 A–C rows flipped from REVIEW to DONE on the run logs in `phase-17-full-regression.md` § *As implemented* (that section is the evidence line each row cites; the flip adds no new claim). The JSON ledger's 15 stale entries (P7-E1/E2/E6/E7, NOTIF-001..003, SEC-001..003, STOR-001/002, BACKUP-001, RETAIN-001, CACHE-002) reconciled against shipped code + their proving suites, CACHE-002 kept open as the deliberate deferral `progress.md` already records. `progress.md` rows 16/17 closed, § *Known Issues* now carries the deferred evidence and the delete-candidates; `implementation-roadmap.md` 16 and 17 → DONE |
| P17-D3 | Final release summary — `docs/planning/final-release-summary.md`: delivered capabilities with their proving files, the measured bar and how CI enforces it, what stays **deferred as deferred** (real S3, restore round trip, live rebase, 9 risky, dead auth-request accessors), three deployment lines pointing at the runbook, and a verdict that does not exceed the evidence | 17 | P2 | P17-D2 | DONE — 2026-11-05 — `docs/planning/final-release-summary.md` written in the plan's five-part order: 14 capabilities each with the route gate (line-cited in `routes/web.php` / `routes/api.php` / `config/pulse.php`), the proving test paths (every one checked to exist) and its `docs/base/` design doc; the measured bar with the workflow job and trigger that enforces it (`ci-cd.yml` `test`/`deploy`, `testing.yml` `phpstan` per PR, `coverage` on schedule + release with pcov, `dependency-audit` weekly); **nine deferred items stated as deferred** (S3, restore round trip, live rebase, 9 risky matched by count only, the 21 open QA rows, the dead auth accessors, D-8, web-verify signature/expiry, log redaction as policy-not-mechanism, the un-gated pages + no i18n, the two Group C findings); three deploy lines pointing at `docs/base/operations/deployment.md`; verdict "shippable for its stated purpose" with § 3 as its boundary — no unqualified "100 % verified" anywhere |

**Group A–C closure note (P17-D2, 2026-11-05):** the nine rows above (A1–A3, B1–B3, C1–C3) are closed on the measurements in `docs/planning/phase-17-full-regression.md` § *As implemented* — that section is their evidence line, and each row's Status cell already quotes its own numbers. Nothing in Group D changed code, so no measurement was re-taken to justify the flip and none of the counts moved. The Tailwind decision (D-8) and the 21 open QA rows remain the owner's, recorded in `docs/planning/final-release-summary.md` § 3.

**Invariants (non-negotiable):** a number is quoted from a run, never carried from a doc; one check has one owner (no second render/schema/coverage gate); a missed bar is a gap list with owners, never a lowered threshold or a deleted/weakened test; no new analyser, linter, load tester or package without an ADR; the closing statement may not exceed the evidence — deferred items stay labelled deferred; nothing is committed by the implementing turn.

---

### Phase 18 — General Membership & Loyalty System

> Breakdown written 2026-11-05. All rows **PLANNED** — no task started, no code written.
> Nineteen tasks in five groups (the brief's eighteen, with A4 split per its D-4), executed
> strictly sequentially
> (`A → B → C → D → E`; a group does not open while the previous one has a row in
> REVIEW). Dynamic membership tiers, the subscription duration/expiry engine, member
> statistics, an automated expiry sweep with queued bulk notification, and an atomic
> voucher engine — built on the RBAC / Pennant / notification / audit / API primitives
> Phases 1–17 already ship, adding no new infrastructure and no new package.
> **Seven brief-vs-code conflicts are recorded as D-1..D-7 in the phase doc § 3 before
> any row is built**, the load-bearing ones being: **a disabled flag answers 403, not the
> brief's 404** (settled in `EnsureFeatureIsEnabled`, pinned by 62 tests, changing it is an
> ADR); **`members` is a loyalty profile keyed to `users.id`, not a second identity**
> (Spatie roles resolve on `User`, so a parallel model cannot hold a tier role at all);
> **there is no WhatsApp channel** (`NotificationChannel::for()` is mail + database only —
> a driver needs a package + ADR, recorded as open decision O-2); **Group A cannot ship the
> sidebar entries** (a menu item must point at a route that exists, so A4 splits: A4a flag +
> seeder in A, A4b routes + menu with B6); **`lockForUpdate()` is inert on the SQLite test
> connection**, so the race test proves quota arithmetic and the unique usage row while the
> row lock stays deferred runtime evidence needing a MySQL two-session probe; **a bulk POST
> needs its own permission** (`members.notify`, per `AuthorizesBulkAction`); and the new
> `Member` Action domain must be added to the `naming-conventions.md` domains table.
> Detail: `docs/planning/phase-18-membership-loyalty.md`.

| ID | Task | Phase | Priority | Depends On | Status |
|----|------|-------|----------|-----------|--------|
| P18-A1 | Member management & form views — `pages/members/{index,create,show}.blade.php`, two-column `col-lg-8`/`col-lg-4`, design-system cards, `.table-responsive`, no `input-group` around validated inputs, no query or arithmetic in Blade | 18 | P1 | — | DONE |
| P18-A2 | Tier configuration view — `pages/members/tiers.blade.php`; tier row edits price/duration/point multiplier/assigned role (select built from real roles, not free text). **Voucher view moved to Phase 19 (P19-A6) per owner: vouchers are their own domain, validated at payment time, not part of membership**. **Rewritten 2026-11-05 (owner asked for create/edit/delete screens like the users feature): the inline per-row editor is gone and `pages/members/tiers.blade.php` is a read-only list with prices formatted by `App\Support\Money`, while `pages/members/tier-form.blade.php` is one page serving create and edit. `MemberTierSeeder` ships the three defaults (Bronze 30 d / 35.000, Silver 60 d / 70.000, Gold 100 d / 100.000) and `MemberTier::assignableRoleOptions()` keeps `superadmin` out of the role select** | 18 | P1 | P18-A1 | DONE |
| P18-A3 | Member dashboard & expiry alert widget — `pages/members/dashboard.blade.php`; counters as primitives from the read Action, expiring-subscription warning list, bulk-notification modal on the shared `confirm-action` + `bulk-actions.js` (parameterise via `data-*`, do not fork) | 18 | P1 | P18-A2 | DONE |
| P18-A4a | Feature-flag declaration & activation — `member_loyalty` in `config/pennant.php` + a `FeatureFlagSeeder` row (split from the brief's A4 per D-4; the store is fail-closed, so a declared-but-unseeded flag 403s the module for everyone) | 18 | P1 | — | DONE |
| P18-A5 | UI render gate — `tests/Feature/Member/MemberUiRenderTest.php` in the `FormViewRenderTest` shape: fixture render, no member view reaches for the database, each gate sabotage-verified individually; renders by fixture rather than HTTP because routes/controllers arrive in B6 (D-4). **The tier slice joined 2026-11-05: the swept page list carries `tier-form`, the list render asserts a formatted price and each row's edit/delete links (and that the inline editor is really gone), and the form render asserts `superadmin` never appears in the role select and `is_active` keeps its hidden companion** | 18 | P1 | P18-A1..A4a | DONE |
| P18-B1 | Migrations, models, factories — `member_tiers`, `members` (`user_id` unique FK, D-2), `member_subscriptions` (per-row `role_name`, index `(status, expires_at)`), `member_point_histories` (four tables — `vouchers`/`voucher_usages` moved to P19-B6); `Auditable` + `SoftDeletes`; migrate/rollback exit 0. **Shipped 2026-11-05 as the read-only slice (owner pulled it forward so the sidebar has real routes to point at): four migrations, four models + four factories, `User::member()`, four `MorphMap` aliases. `Member` domain added to `naming-conventions.md` here rather than in B3. migrate exit 0 proven on the dev MySQL database and `migrate:rollback` exit 0 on a scratch sqlite database. DONE 2026-11-06 — Group B exit gate in P18-B6 row | 18 | P0 | P18-A5 | DONE |
| P18-B2 | Form Requests — `app/Http/Requests/V1/Member/*` with real `authorize()` naming the permission that performs the change; every numeric bound rejects out-of-range; `BroadcastMemberExpiryRequest` authorizes on `members.notify` (D-6). **Two shipped 2026-11-05 with the writes they guard: `MemberCreateRequest` (`members.manage`; name/username/email/notes — `member_number` left the request on 2026-11-05, it is generated by `Member::nextMemberNumber()` from the `member_number_prefix` setting) and `MemberAdjustPointsRequest` (`members.manage`; `after()` rejects delta and purchase_amount together and rejects a zero delta). Remaining: subscription/broadcast requests. **`MemberTierRequest` shipped 2026-11-05 with the tier write routes: `authorize()` names `members.manage`, name/slug are unique against trashed rows as well (the database's unique index does not forget them), `role_name` is limited to `MemberTier::assignableRoleOptions()` — the same set the dropdown is built from, so superadmin cannot be posted directly — price/multiplier/sort_order are bounded, and `is_active` arrives on the hidden-input pair rather than a relaxed `boolean` rule** **`MemberSubscribeRequest` shipped 2026-11-06 with the subscribe route: `authorize()` names `members.update` (issuing a period edits the profile, it does not create one), `member_tier_id` is `exists` with a `whereNull(deleted_at)` guard so a trashed tier cannot be posted, and `tier()` resolves the row once so the controller never queries. **`BroadcastMemberExpiryRequest` moved to P18-C2 with the broadcast route it guards (owner 2026-11-06), and was deleted there the same day: `rules()` was empty and the route already carries `can:members.notify` — the body-less trigger shape of `activate`/`cancel`, per the empty-Form-Request convention. D-6 stands: the permission is the gate, one layer instead of two** | 18 | P0 | P18-B1 | DONE |
| P18-B3 | Subscription Actions — `ActivateMemberSubscriptionAction` / `CancelMemberSubscriptionAction`: one `DB::transaction`, role applied to the **`User`** via `syncRoles` with `role_name` copied per row, audit `member.subscribed` + `member.role_synced` inside the transaction, nothing dispatched mid-transaction; `Member` domain added to `naming-conventions.md` (D-7) Shipped 2026-11-06 (P18-B3): `MemberSubscriptionActivateAction` / `MemberSubscriptionCancelAction` (renamed from the brief's verb-first `Activate…`/`Cancel…` — `ActionNamingConventionTest` enforces `{Domain}{Operation}Action`). Activate: one `DB::transaction`, refuses an inactive tier and a second live period (`ValidationException`), `role_name` copied per row, `syncRoles` applied to the `User` preserving the base role, audits `member.subscribed` + `member.role_synced`. Cancel: marks `cancelled`, revokes only the role the row applied when no other live period needs it, audits `member.subscription_cancelled`. Proven by `MemberSubscriptionTest` (10 tests). Routes/UI wiring shipped with P18-B6 2026-11-06. | 18 | P0 | P18-B1, P18-B2 | DONE |
| P18-B4 | Loyalty point Actions — `EarnMemberPointsAction` / `RedeemMemberPointsAction`: signed delta + `balance_after` ledger (a correction is a new row), balance locked, over-redemption refused before any write. **Manual half shipped 2026-11-05 (owner asked for a cashier-less adjust + the System Setting earn rule): `MemberAdjustPointsAction` (signed `delta` *or* `purchase_amount` converted by `member_points_earn_amount`/`member_points_earn_value`, balance re-read under `lockForUpdate()`, stored `balance_after`, negative balance refused, audit inside the transaction) + `MemberExpirePointsAction` / `MemberPointsExpirySweepJob` for O-3. `MemberPointsTest` covers all of it. **The POS-driven `Earn`/`Redeem` classes and the tier `point_multiplier` are Phase 19 scope, not remaining Group B work (owner 2026-11-06)**. **Owner revision 2026-11-06: the web Adjust Points card + `members.points` web route deleted — balance moves belong to the Phase 19 checkout; `MemberAdjustPointsAction`/`Request` and `api.v1.members.points` stay, `MemberPointsTest` drives the API route now** | 18 | P1 | P18-B3 | DONE |
| P18-B5 | Atomic voucher claim — **MOVED to Phase 19 P19-B6** (owner decision 2026-11-05): vouchers are their own domain — own flag, own menu, checked at payment time — not part of membership. `ClaimVoucherAction`, both tables and the race test ship there | 18 | P0 | — | CANCELLED |
| P18-B6 | Controllers, routes, sidebar, API twins — thin `Web/V1` controllers (`MemberController`, `MemberTierController`, `MemberDashboardController` — `VoucherController` moved to P19-B7) + `Api/V1/Member` + `Resources/Api/V1`, `feature:member_loyalty` + `can:` on the route, Scramble annotations per `api-architecture.md` § API Conventions, **A4b**: `AppMenuComposer` entries; web/API capability table diffed by capability (Phase 12 method). **Partly shipped 2026-11-05 (read-only slice): the three web controllers, the four read routes, the four read Actions and three sidebar entries (Members, Membership Dashboard, Tiers) are live, plus `tests/Feature/Member/MemberWebTest.php` (real-data 200s, staff 403, flag-off 403, sidebar). Remaining: every write route (`store`/`update`/state actions/broadcast), the API twins + Scramble, and the null write URLs the views already guard on. `ChannelParityTest` is green for it: the four reads are declared as one-sided `membership screens` with the reason stated, and that entry is replaced by a two-sided capability when `Api/V1/Member` lands. **Writes added 2026-11-05 (owner asked for an Add new member button and a points adjust): `members.create` + `members.store` (`MemberCreateAction` → `UserCreateAction`, fixed `App\Support\SystemRole::MEMBER` role, verification mail, then the profile in its own transaction so no mail goes out for a rolled-back account) and `members.points` on the detail page; the four read URLs stay as they were, the unbuilt ones stay null and their affordances vanish (`MemberUiRenderTest::test_the_adjust_points_form_appears_only_with_a_write_url`). Sidebar: the three entries moved out of **Management** under their own **Membership** label — and the Tiers entry had been silently dropped because it named `members.tier` (no such route; the composer filters `Route::has()` first) with a `tiers.view` permission the catalog never had. `MemberWebTest` now pins all three labels** **Member profile trash shipped 2026-11-05 (owner asked why the detail Danger Zone was empty): `MemberDeleteAction` + `DELETE /members/{member}` (`members.destroy`, `members.manage` + `feature:member_loyalty`), the web-only half of the parity entry `membership screens`, `MemberShowAction::delete_url` live (null for a trashed row, so the card disappears), the identity block stacked with a `user_url` link to the account behind the profile, and `MemberDeleteTest`. API twin, subscribe/cancel and bulk (incl. restore) still open** **Tier CRUD shipped 2026-11-05 (owner asked for the screens, three default tiers, a currency on every price and no superadmin in the role select): `members.tiers.{create,store,edit,update,destroy}` — each `can:members.manage` inside the `feature:member_loyalty` group, `{tier}` bound through the default (non-trashed) scope so a repeat DELETE 404s rather than writing a second audit row — with `MemberTierCreate/Update/DeleteAction` auditing `member_tier.created` / `.updated` / `.deleted`, the update carrying only the fields that changed and the delete refusing while a subscription still points at the tier. `CurrencySeeder` + the `currencies` table + `currency_code` (a global setting on the Localization card, owned by no feature flag) back `App\Support\Money`, which formats every tier price and the settings earn-rule line; `MemberDemoSeeder` calls `MemberTierSeeder` and derives subscription status from `expires_at`; the sidebar Tiers entry matches `members.tiers*`. Proven by `MemberTierTest`; the API twins for members and tiers stay open** **Trash tabs + bulk shipped 2026-11-05 (owner: a trashed tier never showed up again, and both lists needed the users-style Active/Inactive/Trash nav plus bulk actions): `status` on `members.index` / `members.tiers` (validated, default `active`, tabs built by the Actions because D-4 keeps `route()` out of Blade), six new routes — `members.{restore,force-delete,bulk-action}` + `members.tiers.{restore,force-delete,bulk-action}` — four new Actions and two `BulkActionHandler`s on the shared `BulkActionProcessor`, and the row buttons on both lists (trash / restore / permanent delete, each behind its own permission). Subscribe and cancel stay unbuilt; their URLs stay `null`** **Subscribe/cancel + the API twins shipped 2026-11-06 (owner picked this as the remaining Group B scope): `members.subscribe` / `members.subscriptions.cancel` put the P18-B3 Actions behind a route (`MemberSubscriptionActivateAction` refuses an inactive tier and a second live period, so the web page and the API cannot each invent their own rule), and `MemberShowAction` now returns real `subscribeUrl` / `tierOptions` / `cancel_url` — no Blade changed, because the buttons were already conditional on those keys and the nulls were the contract waiting for the routes. `Api/V1/Member/{MemberController,MemberTierController}` + `Member/MemberTier/MemberSubscription/MemberPointHistory` resources: 21 routes under `feature:member_loyalty`, same Actions, same Form Requests, same per-route `can:`, four `#[Response]` annotations for the documented 409/404s. `MemberIndexAction::query()` and `MemberTierIndexAction::query()` went public rather than copied — one query per listing, decoration per channel — which is what lets `ResponseParityTest` diff the rows. `ChannelParityTest` now declares every member and tier capability on both sides; `membership screens` is gone and `membership dashboard` is the one remaining one-sided entry. Proven by `tests/Feature/Api/V1/Member/{MemberApiTest,MemberTierApiTest}.php` (23 tests) and five new cases in `MemberSubscriptionTest`; still open here: `BroadcastMemberExpiryRequest` (P18-C2) and the POS `Earn`/`Redeem` paths (Phase 19)** — Group B exit gate re-run 2026-11-06 on the committed tree: `tests/Feature/Member tests/Feature/Api/V1/Member tests/Feature/CrossCutting` 192 passed / 1168 assertions, Pint PASS 561 files, PHPStan `[OK] No errors`; the broadcast route ships with P18-C2. **Owner revision 2026-11-06 (after that gate): the web `members.points` route, the Adjust Points card, `MemberController::points()` and the `adjustUrl`/`earnRule` keys in `MemberShowAction` were deleted — the API twin stays and `ChannelParityTest` declares `member points adjust` one-sided `api` with the reason** | 18 | P0 | P18-B2..B4, P18-A4a | DONE |
| P18-C1 | Scheduled expiry sweep job — `MemberSubscriptionExpirySweepJob` on `QueueName::SWEEPS`, 500-row chunks, status → `expired`, revoke the role that row applied, audit `member.expired` with `causer = 'SYSTEM'`; the full `queue.md` job standard by reflection (`JobConventionTest`). **Shipped 2026-11-06 with `MemberSubscriptionExpireAction`; the revoke is `RevokesSubscriptionRole`, extracted from `MemberSubscriptionCancelAction` so the sweep and a manual cancel cannot invent different rules — a role another live period still needs survives, `paused_at` rows are never seen, `chunkById(500)`, flag off logs `feature_off` and returns, a second run claims zero rows. `MemberExpirySweepTest` (6); `JobConventionTest` green without edits; `audit-trail.md` gained the `member.expired` row** | 18 | P1 | P18-B3 | REVIEW |
| P18-C2 | Queued bulk notification — `SendMemberExpiryNotificationJob` chunked, one message per member; `MemberExpiryWarningNotification` via `NotificationChannel::for()` (**mail + in-app only**, D-3), `$afterCommit = true`, subject stands alone per `NotificationCopyTest`; request returns before any send. **Shipped 2026-11-06 together with the trigger it queues — the route's own `can:members.notify` (D-6 — `members.update` alone is refused, proven; `BroadcastMemberExpiryRequest` was built, then deleted same-day per owner: empty `rules()` behind a route that already names the permission is the dead-weight shape, so `broadcastExpiry()` takes no request, like the body-less state routes), `MemberExpiryBroadcastAction` (window read at trigger time from `member_expiry_alert_window_days`, inline 14 fallback — the same shape every `SystemSetting` reader in the app uses; an owner revision 2026-11-06 removed the cross-Action constant (`MemberDashboardAction` had borrowed `MemberExpiryBroadcastAction::DEFAULT_ALERT_WINDOW_DAYS`, a value that can shadow the stored setting instead of falling back to it)), `POST /members/dashboard/broadcast-expiry` + API twin `api.v1.members.broadcast-expiry`, both `can:members.notify` inside the flag group — `ChannelParityTest` gained the two-sided `expiry broadcast` capability. The job collapses a member's periods to the earliest expiry and sends once; benchmark: 1 000 members = 1 000 queued `SendQueuedNotifications` on the mail channel (2 000 total counting the in-app copy), zero sends in the request process. The suite overrides `shouldFakeNotifications(): false` — the global fake in `Tests\TestCase` swallows real queue-outs. `MemberExpiryNotificationTest` (9); `NotificationCopyTest` gained the class; dashboard Blade already hid the button behind `@can` + null `broadcastUrl`, so `MemberWebTest`'s stale "button absent" assert flipped to present** | 18 | P1 | P18-C1 | REVIEW |
| P18-C3 | Console scheduler integration — seventh `routes/console.php` entry: daily, `withoutOverlapping`/`onOneServer`, gated on the flag, dispatch to `sweeps`; `schedule:list` verified and the collision question answered for the new entry (P17-C3 method). **Shipped 2026-11-06 as the eighth entry (the points sweep took the seventh slot early): `Schedule::job(new MemberSubscriptionExpirySweepJob())->dailyAt('00:30')` — the collision question answered by staggering half an hour off the points sweep's midnight, named, `onOneServer()->withoutOverlapping()`; the flag gate lives inside the job, not the schedule entry (a scheduled command has no HTTP middleware to lean on). `schedule:list` prints it. `queue.md` SWEEPS row and `membership.md` § Subscription expiry sweep record both new jobs** | 18 | P1 | P18-C1 | REVIEW |
| P18-D1 | RBAC route authorization — `members.view` / `members.manage` / `members.notify` in `PermissionCatalog` + seeder + role matrix (`vouchers.manage` moved to P19-D1); bulk POST mapped through `AuthorizesBulkAction`; zero-permission 403 on every member route on both channels. **Catalog half shipped 2026-11-05: the three permissions are in `PermissionCatalog::MEMBERS`, `all()` and `FEATURE_BY_RESOURCE`, so the seeder and role matrix pick them up with no new rows. `members.view` is enforced on all four web routes; `manage`/`notify` guards land with the write routes; API channel and bulk POST remain. `members.manage` is enforced on every member and tier write route since 2026-11-05 (`create`/`store`/`points`/`destroy` and the five `members.tiers.*` writes), and a holder of `members.manage` *without* `users.assign_roles` is refused — creating a member assigns a role, which is its own permission. `SystemRole::MEMBER` joined the seeded roles carrying no permissions, so no member inherits any. The five keys `member_expiry_alert_window_days`, `member_number_prefix`, `member_points_expiry_date`, `member_points_earn_amount`, `member_points_earn_value` are declared under `member_loyalty`'s `settings` group in `config/pennant.php`, so saving with the flag off strips them — while `currency_code` sits in no group at all (Localization card), because how money is written outlives the loyalty module** **Removing is its own permission since 2026-11-05: `members.delete` / `members.restore` / `members.force_delete` joined the catalogue (seeder and role matrix pick them up with no new rows), the six trash routes name them, and both bulk endpoints carry no `can:` on the route — `BulkMemberRequest` / `BulkMemberTierRequest` map the body's `action` through `AuthorizesBulkAction` on the `members` prefix, which is also what tiers use (one module, one permission set). `members.manage` no longer trashes anything: `MemberDeleteTest` pins that a `manage`-only caller gets a 403 and the row stays alive** **Switching off is a permission pair of its own since 2026-11-05 (owner revision: members and tiers behave like users): `members.activate` / `members.deactivate` joined the catalogue (eight `members.*` slugs), the four state routes (`members.{activate,deactivate}`, `members.tiers.{activate,deactivate}`) name them, and `MemberStateTest` pins that `members.manage` alone is refused on all four** **`members.update` is what guards subscribe and cancel on both channels since 2026-11-06 — no new permission: issuing or ending a period is an edit of the profile, and the catalogue already had that slug** | 18 | P0 | P18-B6 | REVIEW |
| P18-D2 | Feature-flag enforcement — flag off → **403 on web and API (not 404, D-1)** and the sidebar entry drops for a superadmin too; an undeclared slug fails closed. **Web half shipped and tested 2026-11-05 (`MemberWebTest`): flag off → 403 for a superadmin, sidebar entry gone. API twin shipped with B6 2026-11-06: `MemberApiTest` + `MemberTierApiTest` each loop the module routes with the flag off and a superadmin, and every address 403s (`EnsureFeatureIsEnabled` runs before `can:`, and the trashed-row bindings mean the loop uses real ids — a missing row would answer 404 from `SubstituteBindings` and prove nothing)** | 18 | P0 | P18-B6, P18-A4a | REVIEW |
| P18-D3 | Action-first audit trail — `member.subscribed`, `member.role_synced`, `member.expired`, plus the fourth event the brief cannot see because it has no call site in its own list: `member.points_adjusted` (`voucher.claimed` moved to P19-D3); enumerated from `->audit()` call sites, added to the Event Map (48 → 52) and `audit-trail.md`. **Three shipped 2026-11-05 with the tier screens: `member_tier.created` / `.updated` / `.deleted`, in `audit-trail.md` § Member tiers; `member.created` / `.deleted` / `.points_adjusted` / `.points_expired` shipped with their own slices. **All four now have call sites and Event Map rows: `member.subscribed` / `member.role_synced` shipped with the P18-B3 Actions (documented in the B slice), `member.expired` with the P18-C1 sweep 2026-11-06** | 18 | P0 | P18-B3..B4, P18-C1 | PLANNED |
| P18-E1 | Comprehensive feature test suite — `MemberSubscriptionTest`, `MemberExpirySweepTest` (+ the per-task files; `VoucherRaceConditionTest` moved to P19-E1); the points-lock test sabotage-verified and naming in its docblock what SQLite cannot prove (D-5 now covers `members.points_balance` only); bars unmoved (global ≥ 85 %, Actions ≥ 95 %, Requests ≥ 95 %, Policies 100 %) | 18 | P0 | P18-D1..D3 | PLANNED |
| P18-E2 | Full-suite run & tracker reconciliation — `pint --test`, `phpstan analyse`, `php artisan test`, `route:list`, `schedule:list`, `npm run build`; counts quoted from the run; PHPStan ledger stays at 310 entries or its growth is documented; rows flip only on an assertion someone opened and read; deferred evidence carried into `progress.md` § Known Issues | 18 | P0 | P18-E1 | PLANNED |

---

### Phase 19 — Basic POS Engine & Omnichannel Payment Gateway

> Breakdown written 2026-11-05. All rows **PLANNED** — no task started, no code written.
> Nineteen tasks in five groups, executed strictly sequentially (`A → B → C → D → E`).
> Cashier POS engine (shifts, sales, stock deduction, ESC/POS receipts), an omnichannel
> gateway integration behind encrypted credentials, an idempotent settlement webhook, and
> the financial dashboard that reads the ledger this phase creates. Hard predecessor:
> **Phase 18** — B2 calls its voucher/points Actions and populates the morphs Phase 18 left
> unpopulated on purpose.
> **Thirteen brief-vs-code conflicts recorded as D-1..D-13 in the phase doc § 3 before any
> row is built**, the load-bearing ones being: **there is no product table anywhere in the
> schema** (`transaction_items` and `stock_movements` have nothing to reference — B1 must
> add `products`, and how a product gets created is open decision O-1); **Livewire and
> Alpine are not installed and the stack forbids them** (vanilla JS + `@nonce`d CSP, so the
> cashier screen is one Blade page + a JS cart posting to our own endpoints);
> **`Illuminate\Support\CircuitBreaker` does not exist in this install** while `Http::retry`
> and `Http::timeout` are native — and retry against a payment gateway is only safe with our
> own deterministic `order_id`, otherwise a timeout retry double-charges; **the webhook's
> race shield cannot be `lockForUpdate()` alone** (inert on the SQLite test connection) — it
> is an atomic conditional update plus a unique `(gateway, event_id)` index; **gateway
> credentials must NOT join the settings whitelist** and MUST join `SystemSetting::SECRET_KEYS`,
> with `UpdatePaymentSettingsAction` as the only writer, because a `settings.manage` holder
> must not be able to repoint production money (Phase 9's finding #1, the same shape);
> **Net Profit is uncomputable** — no cost data exists (O-2); a **void is a reversing row,
> not a status flip**; `mike42/escpos-php` needs **ADR-031 + a changelog line before
> `composer require`**, and only its byte stream is testable here; flag-off answers **403,
> not the brief's 404**; and **"100 % coverage" is not the enforced bar** (global ≥ 85 %,
> Actions ≥ 95 %, Requests ≥ 95 %, Policies 100 %).
> Detail: `docs/planning/phase-19-pos-and-payment.md`.

| ID | Task | Phase | Priority | Depends On | Status |
|----|------|-------|----------|-----------|--------|
| P19-A1 | Cashier POS interface — `pages/pos/cashier.blade.php`: product search, cart, member picker, method selector (cash/QRIS/EDC), keyboard-shortcut legend, receipt preview. Vanilla JS module under `resources/js/pos/` (`@vite`, `@nonce`) — no Livewire/Alpine (D-2). The cart is a preview; the server recomputes every figure (D-7) | 19 | P1 | — | PLANNED |
| P19-A2 | Payment gateway settings card — `pages/payments/settings.blade.php` (`col-lg-8`): driver select, sandbox/production toggle, the three credential inputs rendered blank with "leave blank keeps the stored value", read-only webhook URL. Mirrors the mail/S3 cards; no credential in the rendered HTML | 19 | P1 | — | PLANNED |
| P19-A3 | Financial dashboard — `pages/payments/dashboard.blade.php`: gross/net revenue, gateway fees, PPN/PPH breakdown, include/exclude tax as a query-string filter; Profit card conditional on cost data existing (D-11). Numbers arrive as primitives; page query count pinned, not growing with rows | 19 | P1 | — | PLANNED |
| P19-A4a | Feature-flag declaration & activation — `pos` + `payment_gateway` in `config/pennant.php` and `FeatureFlagSeeder` (fail-closed: declared ≠ activated). **Two independent flags, not one ANDed group**, so killing the gateway leaves the cash path open. The third Sales flag, `vouchers`, shipped early under P19-A6 | 19 | P1 | — | PLANNED |
| P19-A5 | UI render gate — `tests/Feature/POS/PosUiRenderTest.php` + the A2/A3 files: fixture render, no POS/payment view reaches for the database, and no secret-shaped value in any rendered markup; each gate sabotage-verified individually | 19 | P1 | P19-A1..A4a | PLANNED |
| P19-A6 | Voucher admin view + flag + render gate — **moved out of Phase 18 (P18-A2/A4a/A5) per owner: voucher is its own domain, checked at payment time, not part of membership and not part of the gateway**. Shipped 2026-11-05: `pages/vouchers/index.blade.php` under `@feature('vouchers')`, `vouchers` declared in `config/pennant.php` (group Sales), `tests/Feature/Voucher/VoucherUiRenderTest.php` — fixture render (quota rendered as handed, never a computed remaining), flag-off and permission gates sabotage-verified individually, no-DB/route/inline-script sweep | 19 | P1 | — | REVIEW |
| P19-B1 | Migrations, models, factories — `products` (**forced by D-4: the schema has no product/stock table at all**), `pos_shifts`, `pos_transactions` (`reference` unique — it is the idempotency key), `pos_transaction_items` (price snapshotted at sale time), `stock_movements`, `payment_logs` (unique `(gateway, event_id)`, redacted payload); money `decimal(12,2)` never float; `Pos` domain added to `naming-conventions.md` (D-13) | 19 | P0 | P19-A5, P18-B1 | PLANNED |
| P19-B2 | `ProcessPosTransactionAction` — one `DB::transaction`: require an open shift → recompute every line from `products` → validate + apply the voucher through `ValidateVoucherAction` (P19-B6 — its own domain) and the Phase 18 points (populate the point ledger's `reference_*` morph) → lock + conditional stock decrement (no negative) → insert transaction + items → change arithmetic → `EarnMemberPointsAction` → audit `pos_transaction.created`; print dispatched after commit, **outside** the money transaction | 19 | P0 | P19-B1, P19-B6, P18-B4 | PLANNED |
| P19-B3 | `OpenPosShiftAction` / `ClosePosShiftAction` — one open shift per cashier, opening float validated, expected vs counted cash and `variance` audited; an unsettled `pending` sale at close is a stated, tested outcome, not a silent sweep | 19 | P1 | P19-B1 | PLANNED |
| P19-B4 | ESC/POS thermal printing — **ADR-031 + `docs/planning/changelog.md` before `composer require` (hard rule)**, then `mike42/escpos-php` behind `App\Support\Receipt\ReceiptPrinter`: header, itemised lines, native QR/barcode commands (no GD), one cash-drawer kick on a cash sale; printer failure surfaces as "not printed — reprint available" and must never roll back a settled payment | 19 | P1 | P19-B2 | PLANNED |
| P19-B5 | POS controllers, routes, sidebar — thin `Web/V1` controllers + `Api/V1/Pos` twins + resources, `feature:pos` + `can:` at the route, the `AppMenuComposer` entries (menu wiring ships here, not in A), Scramble annotations; web/API capability table diffed by capability | 19 | P0 | P19-B2..B4 | PLANNED |
| P19-B6 | Voucher domain data & validation — `vouchers` + `voucher_usages` migrations (moved from P18-B1/B5), `Voucher`/`VoucherUsage` models + factories, `StoreVoucherRequest`/`UpdateVoucherRequest`/`ClaimVoucherRequest`, `ValidateVoucherAction` (active, inside window, quota vs `claimed_count`, per-member usage vs `per_member_limit`, `min_purchase` → discount capped by `max_discount`; refusals carry the error contract's code) + atomic `ClaimVoucherAction` (quota read under `lockForUpdate()`, conditional increment + the unique `(voucher_id, member_id)` usage row as the portable backstop, audit `voucher.claimed` inside the transaction); `Voucher` domain added to the `naming-conventions.md` domains table | 19 | P0 | P19-A6, P19-B1 | PLANNED |
| P19-B7 | Voucher controllers, routes & its own sidebar entry — thin `Web/V1/VoucherController` (moved from P18-B6) + `Api/V1/Voucher` twin + resources, `feature:vouchers` + `can:` on the route group, one `AppMenuComposer` entry with `'permission' => 'vouchers.view'` + `'feature' => 'vouchers'` — **its own menu, not a child of Members**; Scramble annotations; web/API capability table diffed by capability | 19 | P0 | P19-B6 | PLANNED |
| P19-C1 | `CreatePaymentOrderAction` — deterministic `reference` generated **before** the call, PPN/PPH from `SystemSetting` rates, voucher discount applied via `ValidateVoucherAction` (P19-B6), row stored `pending`, gateway call via `Http::timeout(5)` + `retry(3, …, $when: connection/timeout only)` wrapped by `GatewayCircuitBreaker` (the only new infrastructure in this phase, D-3); an open breaker answers a distinct contract error code so the cashier falls back to cash | 19 | P0 | P19-B2 | PLANNED |
| P19-C2 | `ProcessPaymentWebhookAction` — HMAC over the **raw** body verified with `hash_equals()` before any query → resolve by `reference` → `DB::transaction` with `lockForUpdate()` **and** the atomic `WHERE status = 'pending'` update (the portable half of the shield, D-5) → `payment_logs` row on the unique event key → settle-once side effects (stock, points, shift cash, or `ActivateMemberSubscriptionAction`) → audit `payment.webhook_settled`/`payment.failed`; duplicate delivery is a 2xx no-op | 19 | P0 | P19-C1 | PLANNED |
| P19-C3 | `UpdatePaymentSettingsAction` — the **only** writer of the three credential keys (D-6): plaintext compare via `AppServiceProvider::storedSecret()`, re-`encrypt()` only on a real change, secrets stripped from the audit payload, `bindPaymentConfig()` rebound after commit (the `bindMailConfig()` shape, never at boot). Keys deliberately excluded from the shared settings whitelist | 19 | P0 | P19-A2 | PLANNED |
| P19-C4 | Payment controllers + the public webhook route — `Web/V1/PaymentGatewayController`, `Api/V1/Payment/*`, sidebar entries, and `POST /api/v1/payment/webhook/{driver}`: no `auth:sanctum`, outside the `web` group (so **no CSRF `except` entry** — D-12, do not "fix" the pinned empty list), its own `RateLimiter::for('payment-webhook')` bucket, bounded body, never echoes the payload | 19 | P0 | P19-C1..C3 | PLANNED |
| P19-D1 | RBAC — `pos.access`, `pos.manage`, `pos.void_transaction`, `payments.view`, `payments.manage`, `vouchers.view`, `vouchers.manage` (the two voucher permissions moved from P18-D1) in `PermissionCatalog` + seeder + role matrix; `can:` on every write route, FormRequest `authorize()` naming the same permission. First non-admin permission on a money write path — which role holds `pos.access` is open decision O-3 | 19 | P0 | P19-B5, P19-B7, P19-C4 | PLANNED |
| P19-D2 | Feature-flag enforcement — `pos` off → **403 (not 404, D-1)** on both channels and the sidebar entry drops for superadmin; `payment_gateway` off → the QRIS/gateway path 403s **while a cash sale still completes** (the assertion that proves A4a's two-flag split); `vouchers` off → 403 on the voucher screens (web + API) and its own sidebar entry drops for superadmin (moved from P18-D2); an undeclared slug fails closed | 19 | P0 | P19-B5, P19-B7, P19-C4, P19-A4a | PLANNED |
| P19-D3 | Action-first audit trail — `pos_shift.opened`, `pos_shift.closed`, `pos_transaction.created`, `pos_transaction.voided`, `payment.order_created`, `payment.webhook_settled`, `payment.failed`, `payment.settings_updated`, `voucher.claimed` (moved from P18-D3) (the brief's four miss shift close, void and credential change — a money module whose void and float-variance writes have no trail is what an owner asks about at 2 a.m.); `causer = 'SYSTEM'` for webhook/scheduler rows; settings row carries no credential value; Event Map 52 → 61 | 19 | P0 | P19-B2, P19-B3, P19-B6, P19-C1..C2 | PLANNED |
| P19-E1 | Comprehensive feature test suite — `tests/Feature/POS/{PosTransactionTest,PaymentWebhookRaceConditionTest,FinancialDashboardTest}.php` + `tests/Feature/Voucher/VoucherRaceConditionTest.php` (moved from P18-E1: quota arithmetic, unique usage-row backstop, second claim refused; docblock names what SQLite cannot prove) + the per-task files: oversell impossible, duplicate webhook settles once (proven by the conditional update + unique index, since SQLite ignores `FOR UPDATE`), tax/change on exact `decimal` comparisons including the 50 %-off + 11 %-PPN rounding edge, receipt asserted as a byte stream; every gate sabotage-verified; bars unmoved (D-10) | 19 | P0 | P19-D1..D3 | PLANNED |
| P19-E2 | Full-suite run & tracker reconciliation — `pint --test`, `phpstan analyse`, `php artisan test`, `route:list`, `schedule:list`, `composer audit --locked` (now including escpos), `npm ci && npm run build`; counts quoted from the run; PHPStan ledger stays at 310 entries or its growth is named; deferred runtime evidence (printer hardware, live gateway sandbox, the MySQL two-session probe) carried into `progress.md` § Known Issues | 19 | P0 | P19-E1 | PLANNED |

---

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
    "note": "Phase 6 Group C4, shipped 2026-09-30 in 015d6c3, EXTENDED: extracted to the App\\Concerns\\\\AuthorizesBulkAction trait and adopted by BulkRoleRequest too, which is what closed P6C1-005. One BULK_ACTIONS map plus an entity prefix, so a new action is added once and both bars get it; an unmapped action fails closed because authorize() runs before rules()."
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
    "note": "DONE 2026-10-02. EnsureFeatureIsEnabled exists (403, fail-closed, no superadmin bypass, reads the config kill switch that Pennant's own middleware cannot see); the feature: alias is registered in bootstrap/app.php; 62/62 module routes are gated across web.php and api.php, plus vendor /pulse through pulse.middleware; the sidebar filters on the flag before the permission. Verified by walking gatherMiddleware() at runtime — route:list hides middleware GROUPS, so the gate is invisible there."
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
    "note": "Phase 7 Group B, shipped b72a5f6. users, roles, permissions, settings, sessions, activity_logs, pulse. The brief's `registration` flag was DROPPED — registration_enabled is already a system_settings row read at four entry points, so a flag would be a second writer for one question. `telescope` also dropped (package removed in 85384b4). The published `stores` block stays byte-identical: it carries the PENNANT_STORE env wiring deploy reads."
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
    "note": "Built 2026-10-01. tests/Feature/FeatureFlag/BulkActionCopyTest.php cross-checks the RENDERED page against actionOptions and ACTION_CONFIG. Rendered, because the attribute holds @json(...) which is Blade, not JSON, so parsing the template would assert on a string the browser never sees. Both failure modes are silent: populateDropdown skips an action it cannot label, and resolveAction warns and returns null, so a typo renders an empty dropdown with nothing in the console a test run would catch. Also pins enable=>success and disable=>warning in BOTH maps per the Action Color Convention. Sabotage-verified three ways: typo in ACTION_CONFIG, entry removed from actionOptions, variant swapped. Pages leaning on the driver defaults (users) are skipped rather than falsely failed."
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
    "note": "Built 2026-10-01. tests/Feature/FeatureFlag/FeatureFlagBulkTest.php, 11 tests: one audit row for a multi-flag change; from read before the write; undeclared slug refused over HTTP AND directly against the action, since a queued job or console command never runs the form request; unknown action refused; duplicate slug applied once; unauthorised caller refused; state survives a store re-read. All three sabotage checks passed only after the assertions were tightened: filtering on the bulk event alone hid a stray per-slug row."
},
  {
    "id": "P7-E1",
    "task": "FeatureFlagTest — 403 on web and API for superadmin, menu hidden, re-enable restores",
    "phase": 7,
    "priority": "P0",
    "depends_on": ["P7-C5", "P7-D10"],
    "status": "DONE",
    "note": "Written PLANNED as 'NOT STARTED' — closed 2026-11-05 by P17-D2. Must also cover the two cases the brief does not name and that are the ones that break: an undeclared slug 403s (fail-closed), and a slug declared in config but never activated 403s (the Pennant trap). | Closed 2026-11-05 by P17-D2: the fail-closed cases this note demanded exist and ran green in P17-A1 - tests/Feature/FeatureFlag/FeatureFlagMiddlewareTest.php, FeatureFlagRouteTest.php (test_a_flag_off_route_answers_, test_an_unknown_slug_is_a_), FeatureFlagBulkTest.php (test_bulk_action_refuses_an_undeclared_slug), FeatureFlagPentestTest.php, PendingFeatureFlagTest.php."
  },
  {
    "id": "P7-E2",
    "task": "Round trip — POST the rendered toggle URL, assert store, cache flush, audit from/to, follow-up GET",
    "phase": 7,
    "priority": "P0",
    "depends_on": ["P7-D3"],
    "status": "DONE",
    "note": "Written PLANNED as 'NOT STARTED' — closed 2026-11-05 by P17-D2. Closes the item Group A left open — A4 asserts the trigger attributes but no controller existed to receive them. | Closed 2026-11-05 by P17-D2: the POST-the-rendered-toggle round trip is proven by tests/Feature/FeatureFlag/FeatureFlagApiTest.php::test_a_manager_can_toggle_a_flag_through_the_api plus FeatureFlagRouteTest.php::test_toggling_flips_the_flag_and_audits_it and ::test_the_toggle_forgets_the_resolved_snapshot (store + cache bust + audit row)."
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
    "status": "DONE",
    "note": "The two doc DEFECTS closed in the Group A audit: feature-flags.md rewritten (Phase 7 catalogue with the 8 flags, the declare-does-not-activate trap, 403 settled against Pennant's 400 with the reasoning, the no-features.manage-bypass rule), and ui-architecture.md:158 relinked from ./feature-flags.md to ../features/feature-flags.md. Remaining: the trackers, which close with the phase, and feature-tracker.md row 24 — it reads 'done' today, which is wrong, because the table existed and nothing used it until Phase 7. | Closed 2026-11-05 by P17-D2: the two doc DEFECTS were already fixed (see the note above); what this row deferred - the trackers - was reconciled on 2026-11-05 (task-tracker.md, qa-tracker.md, progress.md, implementation-roadmap.md), and feature-tracker.md row 24 now reads done with the shipped Phase 7 evidence, so the wrongness this note recorded is gone."
  },
  {
    "id": "P7-E7",
    "task": "Full verification — php artisan test, npm run build, pint --test, view:cache",
    "phase": 7,
    "priority": "P0",
    "depends_on": ["P7-E4", "P7-E6"],
    "status": "DONE",
    "note": "Written PLANNED as 'NOT STARTED' — closed 2026-11-05 by P17-D2. | Closed 2026-11-05 by P17-D2: the verification this row names was run on the final tree by Phase 17 - php artisan test 2668 passed / 9837 assertions exit 0 (P17-A1 + the post-C2 re-measure), vendor/bin/pint --test PASS 489 files (P17-B1), npm run build exit 0 (P17-C1), view render suites green (P17-A3)."
  },

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
  },

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
  },

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
    "note": "Closed 2026-11-05 by P17-D2: the mail transport is operator-configurable and encrypted at rest - app/Http/Requests/V1/Notification/UpdateMailSettingsRequest.php, proven by tests/Feature/Notification/NotificationSettingsTest.php::test_a_manager_saves_the_transport_and_it_reaches_the_runtime_config and ::test_the_password_is_encrypted_at_rest_and_decrypted_for_the_transport.",
    "status": "DONE"
  },
  {
    "id": "NOTIF-002",
    "task": "Implement notification channels",
    "phase": 9,
    "priority": "P1",
    "depends_on": [
      "NOTIF-001"
    ],
    "note": "Closed 2026-11-05 by P17-D2: channels save, render and gate - NotificationSettingsTest.php::test_the_channel_switches_save_and_render_as_booleans plus the mail-configuration and channels render gates in tests/Feature/Notification/NotificationUiRenderTest.php.",
    "status": "DONE"
  },
  {
    "id": "NOTIF-003",
    "task": "Queue email sending",
    "phase": 9,
    "priority": "P1",
    "depends_on": [
      "QUEUE-001"
    ],
    "note": "Closed 2026-11-05 by P17-D2: 8 classes in app/Notifications implement ShouldQueue (UserCreated, AccountStateChanged, ChangeEmailVerification, RolesChanged, ConfigurationChanged, UserRegistered, Register, AuditExportReady); the per-job standard is enforced by reflection in tests/Feature/Queue/JobConventionTest.php per docs/base/infrastructure/queue.md.",
    "status": "DONE"
  },
  {
    "id": "AUDIT-001",
    "task": "Integrate audit package",
    "phase": 10,
    "priority": "P0",
    "depends_on": [
      "RBAC-005"
    ],
    "status": "DONE",
    "note": "spatie/laravel-activitylog ^4.8 installed; 3 migrations on `activity_log`; 41 call sites writing through the `Auditable` trait. `docs/planning/phase-10-audit-trail.md` §9."
  },
  {
    "id": "AUDIT-002",
    "task": "Create audit abstraction layer",
    "phase": 10,
    "priority": "P0",
    "depends_on": [
      "AUDIT-001"
    ],
    "status": "DONE — as a trait, not a service",
    "note": "The task said \"abstraction layer\". What shipped is `App\\Models\\Concerns\\Auditable` (D4): a model trait, because the derivation of source/ip/UA/request_id belongs on the model that carries it, making `Auditable::auditContext()` the single place it exists. `Controller::audit()` and `bulkAudit()` were deleted. DEP-003 corrected; `docs/base/dependencies/overview.md` corrected 2026-10-13."
  },
  {
    "id": "AUDIT-003",
    "task": "Implement audit recording in Actions",
    "phase": 10,
    "priority": "P0",
    "depends_on": [
      "AUDIT-002"
    ],
    "status": "DONE",
    "note": "Every `->audit()` call site is an Action, a Job or one service (`InactivityLock`). Zero controllers and zero observers write a row. Pinned by `AuditReadOnlyTest::test_nothing_in_the_application_writes_through_the_activity_model` and by `ActionFirstAuditTest` (11 cases, incl. three rollback cases proving a failed mutation leaves no row)."
  },
  {
    "id": "AUDIT-004",
    "task": "Implement audit view/detail API",
    "phase": 10,
    "priority": "P1",
    "depends_on": [
      "AUDIT-002"
    ],
    "status": "DONE",
    "note": "Web: `ActivityLogController` index/show. API: `Api\\V1\\Audit\\AuditController` added 2026-10-13 after a route audit found the module browser-only on the read side. Same Actions, same Form Requests, same `audit.view` split. Satisfied by P10-A1..A7, P10-B2/B3/B5, P10-C1/C3..C8, P10-D5..D9, P10-F2/F3. See `phase-12-api-v1.md` §1."
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
    "status": "DONE",
    "note": "Migration + model + `GenerateAuditExport` job on the private disk + retention sweep + rate limit now in `system_settings` + notification on both channels. Satisfied by P10-E1..E8, P10-E9..E12, and the API half in `phase-12-api-v1.md` §1. Two defects found in review and closed: the flash was rendered by no admin view, and a retry could orphan a complete CSV because the path column is written after the file lands."
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
    "note": "Deferred: base project has no application-level cached data requiring grouped invalidation. Pattern documented in cache.md; implement when a feature introduces cacheable data needing cache::tags() invalidation. | Still open by decision, not unfinished work: recorded in docs/planning/progress.md section Known Issues (Open) and in docs/base/infrastructure/cache.md. Reconciled 2026-11-05 by P17-D2 - no application-level cached data on this tree needs cache::tags() grouped invalidation."
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
    "status": "DONE"
  },
  {
    "id": "API-002",
    "task": "Implement API resources (v1)",
    "phase": 12,
    "priority": "P0",
    "depends_on": [
      "API-001"
    ],
    "status": "DONE"
  },
  {
    "id": "API-003",
    "task": "Generate API documentation (Scramble)",
    "phase": 12,
    "priority": "P1",
    "depends_on": [
      "API-002"
    ],
    "status": "DONE"
  },
  {
    "id": "SEC-001",
    "task": "Implement security headers",
    "phase": 13,
    "priority": "P0",
    "depends_on": [
      "FOUND-003"
    ],
    "note": "Closed 2026-11-05 by P17-D2: app/Http/Middleware/AddSecurityHeaders.php, pinned by tests/Feature/Security/SecurityHeadersTest.php and ContentSecurityPolicyTest.php (nonce, frame-ancestors, 500 masking).",
    "status": "DONE"
  },
  {
    "id": "SEC-002",
    "task": "Implement CSRF/CORS (web)",
    "phase": 13,
    "priority": "P0",
    "depends_on": [
      "SEC-001"
    ],
    "note": "Closed 2026-11-05 by P17-D2: config/cors.php is default-deny and pinned by tests/Feature/Security/CorsPolicyTest.php; CSRF runs through app/Http/Middleware/VerifyCsrfToken.php, pinned by SecurityAuditTest::test_web_state_changing_forms_require_csrf.",
    "status": "DONE"
  },
  {
    "id": "SEC-003",
    "task": "Implement error handling (consistent JSON)",
    "phase": 13,
    "priority": "P0",
    "depends_on": [
      "SEC-001"
    ],
    "note": "Closed 2026-11-05 by P17-D2: every error leaves as the shared envelope via bootstrap/app.php withExceptions - tests/Feature/CrossCutting/ApiErrorEnvelopeTest.php::test_every_contract_status_is_emitted_in_the_shared_envelope against docs/base/api/error-contract.md.",
    "status": "DONE"
  },
  {
    "id": "STOR-001",
    "task": "Define storage disks (local, public, tmp)",
    "phase": 14,
    "priority": "P1",
    "depends_on": [
      "FOUND-003"
    ],
    "note": "Closed 2026-11-05 by P17-D2: config/filesystems.php declares local, public, tmp, secure_documents (private), backups and s3; disk behaviour pinned by tests/Feature/Storage/StorageDiskTest.php.",
    "status": "DONE"
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
    "note": "Closed 2026-11-05 by P17-D2: audit exports land on the private secure_documents disk (app/Http/Controllers/Web/V1/StorageController.php:36); authorization, signing and cross-path replay proven by tests/Feature/Storage/StorageAuthorizationTest.php and tests/Feature/Audit/AuditExportTest.php.",
    "status": "DONE"
  },
  {
    "id": "BACKUP-001",
    "task": "Define backup strategy",
    "phase": 14,
    "priority": "P1",
    "depends_on": [
      "STOR-001"
    ],
    "note": "Closed 2026-11-05 by P17-D2: app/Services/Backup/SystemBackupRunner.php plus tests/Feature/Storage/BackupStrategyTest.php and the system-backup scheduler entry audited by P17-C3. Deferred as deferred: the backup-to-restore round trip has never been executed against a real dump (docs/planning/final-release-summary.md section 3).",
    "status": "DONE"
  },
  {
    "id": "RETAIN-001",
    "task": "Implement retention policy jobs",
    "phase": 14,
    "priority": "P1",
    "depends_on": [
      "DB-001"
    ],
    "note": "Closed 2026-11-05 by P17-D2: four retention mechanisms ship and were audited as a set by P17-C3 - notification-retention, audit-export-retention and temp-storage-cleanup in routes/console.php plus vantage:prune behind config('vantage.enabled'); proven by tests/Feature/Storage/DataRetentionTest.php; docs/base/operations/retention.md corrected in Phase 16 C2.",
    "status": "DONE"
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
      "PulseFeatureGateTest",
      "PulsePermissionGateTest",
      "PulseQueryCacheTest",
      "ContentSecurityPolicyTest"
    ],
    "note": "Telescope at FOUND-007 plus Periscope v0.3 as a companion UI, both removed in commit 85384b4 in favour of Laravel Pulse ^1.8 (DEP-004). The pulse feature flag gates /pulse through pulse.middleware (b08b8b4), verified by PulseFeatureGateTest. The pulse.view permission + viewPulse gate override shipped in Phase 11 P11-B1/B2 (2026-10-15) and is pinned by PulsePermissionGateTest, which also pins D-3: a flag-off module 403s superadmin too. Since ADR-024 (2026-10-17) the flag is ANDed into the gate and also stops ingestion (Pulse::filter), and switching the flag off revokes pulse.view from every role. See docs/planning/phase-11-monitoring-observability.md."
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
    "id": "MONITOR-003",
    "task": "Integrate the Vantage queue monitor (reverses the Phase 11 D-1 deferral)",
    "phase": 11,
    "priority": "P1",
    "depends_on": [
      "MONITOR-001"
    ],
    "status": "DONE",
    "docs": [
      "monitoring.md",
      "overview.md"
    ],
    "tests": [
      "VantagePermissionGateTest",
      "VantageRetryTest",
      "ContentSecurityPolicyTest"
    ],
    "note": "storviaio/vantage ^0.1.25, installed 2026-10-16 per ADR-022 (supersedes the Phase 11 D-1 deferral). The app declares the six web routes itself (config/vantage.php routes => false) so they carry feature:vantage + can:queues.view / can:queues.retry; viewVantage is overridden to queues.view (since ADR-024, the vantage flag ANDed with it); the retry POST is throttled by queue-retry; store_payload is forced false and retry reads the payload from failed_jobs via App\\Support\\Vantage\\FailedJobPayloadRestorer; vantage:prune runs daily. See docs/planning/phase-11-monitoring-observability.md §13."
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