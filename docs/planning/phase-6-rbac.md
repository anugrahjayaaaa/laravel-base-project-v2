# Phase 6 — RBAC & Authorization

> Date: 2026-09-28 | Branch: feature/phase-6-rbac-and-authorization | Status: PLANNED
> Purpose: hyper-detailed task breakdown for Phase 6, UI-first then logic
> (Group A → B → C → D → E strict sequential).
> Scope: permission set definition, roles/permissions management UI, role
> assignment sync, route/menu/UI gating, superadmin + system-role protection.
> Dependency chain: A → B → C → D → E. A cannot skip to C.

---

## Research Summary — best practice for Spatie RBAC (2026-09-28)

Sources: Spatie official docs v6 (package installed is `spatie/laravel-permission: ^6.0`)
— `basic-usage/super-admin`, `best-practices/using-policies`, `best-practices/performance`,
`advanced-usage/seeding`, `advanced-usage/testing`.

| Topic | Official guidance | How this phase applies it |
|-------|-------------------|---------------------------|
| Superadmin | `Gate::before()` returning `true` for the role; **must return `null`, never `false`**, or it short-circuits every policy | `Gate::before` in `AuthServiceProvider::boot()`, returns `true` or `null` only |
| Role vs permission | Authorize on **permissions** (`@can`, `$user->can()`), never `hasRole()` outside the Gate rule | All Blade/controller checks use `can('resource.action')`; `hasRole` appears only inside the Gate closure |
| Policies | Laravel Policies are the recommended way to combine record rules with permission rules | `UserPolicy` stays the record-level boundary; permission checks stay in the Gate/route layer |
| Performance | Assign permission→role via `$permission->assignRole($role)` when adding/removing in bulk; cache is always on | Seeder assigns via the permission object, not per-role loops; package cache is left enabled (24h) |
| Seeding | `forgetCachedPermissions()` **before** seeding, and again after creating rows but before assigning | `PermissionSeeder` calls it twice; tests call it in `setUp()` |
| Testing | Clear the permission cache in test `setUp()` when roles/permissions are seeded there | Every new RBAC test extends the same `setUp()` pattern as `ConfirmActionUsageTest` |
| Guard | Spatie keys by `(name, guard_name)` | Reuse existing `App\Models\RoleLookup::guard()` — never `config('auth.defaults.guard')` |

**Project-specific decisions (from existing code, not the docs):**

1. `App\Models\RoleLookup` already resolves the correct guard. Every new role
   read goes through it. A literal `'web'` in a new seeder/controller re-opens
   the duplicate-role bug that `RoleGuardTest` exists to catch.
2. `config('permission.php')` still points at `Spatie\Permission\Models\{Role,Permission}`.
   Subclassing them is a three-place change (config + seeder imports + observer
   registration) and the failure is silent. **Not doing it in this phase** — the
   audit trail writes at the controller/action level, which needs no subclass.
3. `$user->can()` goes through the Gate, so it also honours `Gate::before`.
   `$user->hasPermissionTo()` does not — it is banned outside tests.

---

## Existing Foundation (audit 2026-09-28)

### Already in place
- `spatie/laravel-permission: ^6.0` installed, migrations run, `roles` /
  `permissions` / `model_has_roles` / `role_has_permissions` tables exist
- `App\Models\User` already `use HasRoles` (`app/Models/User.php:29`)
- `App\Models\RoleLookup` — guard resolver (`guard()`, `assignable()`, `find()`)
- `Database\Seeders\RoleSeeder` seeds `superadmin`, `admin`, `user` with **zero
  permissions** (`database/seeders/RoleSeeder.php:30`)
- `App\Policies\UserPolicy` — unlock/activate/deactivate/lock, all already
  calling `$user->can('users.*')` (`app/Policies/UserPolicy.php:31,65,83`)
- `App\View\Composers\AppMenuComposer` — static menu groups, already contains
  `Roles`, `Permissions`, `Activity Logs`, `Settings`, `Translations` items
  pointing at route names that **do not exist yet** (`Route::has()` falls back
  to `'#'`)
- `UpdateUserAction` already syncs roles with `array_key_exists` guard
  (`app/Actions/V1/User/UpdateUserAction.php:59`)
- `CreateUserAction` already assigns roles through `RoleLookup::find()`
- `partials/user-role-picker.blade.php` — shared role checkbox partial
- `<x-ui.confirm-action>` + `ACTION_CONFIG` — 8 action keys, all destructive/state
- `tests/Feature/RoleGuardTest.php` — pins the guard resolver
- Package cache is on: `config/permission.php` `cache.expiration_time` 24h,
  `CACHE_STORE=database`, cache table migration exists

### Phase 6 gap — the security hole (RBAC-006, found 2026-09-27)
The authenticated route group in `routes/web.php:58` carries only
`auth` + `verified` + `password.change.required` + `account.state`. There is
**no `can:` or `permission:` gate anywhere**. A self-registered user (role
`user`, zero permissions) can currently:

| Request | Current result |
|---------|----------------|
| `GET /users` | 200 + every email in the system |
| `GET /settings` | 200 + form to rewrite `login_max_attempts` |
| `POST /users` with `roles[]=superadmin` | 201 — creates a superadmin |
| `PUT /users/{id}` | demotes/deletes other superadmins |
| `DELETE /users/{id}` | deletes the superadmin account |
| `POST /users/bulk-action` | bulk state changes |
| Same set on `POST /api/v1/users`, `PUT /api/v1/settings` | all accepted a plain user's token |

Root cause: `SystemSettingRequest::authorize()`, `CreateUserRequest::authorize()`,
`UpdateUserRequest::authorize()` all `return true` unconditionally
(`app/Http/Requests/User/CreateUserRequest.php:15`, `UpdateUserRequest.php:19`,
`app/Http/Requests/System/SystemSettingRequest.php:17`).

**This phase closes that hole.** It is not a UI feature.

---

## Permission Set (RBAC-004) — the contract every other group codes against

Naming: `{resource}.{action}`, dotted, lowercase, exactly as
`docs/base/features/roles-permissions.md` §Permission Naming Convention.
This list is the single source of truth; the seeder, the route gates, the
`@can` calls, and the tests all read these exact strings.

### users
| Permission | Guards |
|---|---|
| `users.view` | user index + show/edit page |
| `users.create` | create form + store |
| `users.update` | edit form + update |
| `users.delete` | soft delete |
| `users.force_delete` | permanent delete |
| `users.restore` | restore from trash |
| `users.activate` | activate |
| `users.deactivate` | deactivate |
| `users.lock` | lock |
| `users.unlock` | unlock |
| `users.assign_roles` | **the role picker on create/edit** |

### roles
| Permission | Guards |
|---|---|
| `roles.view` | roles index |
| `roles.create` | create form + store |
| `roles.update` | edit form + update (rename + sync permissions) |
| `roles.delete` | delete a non-system role |
| `roles.force_delete` | permanent delete from trash — **added by C1**, not in the original list |
| `roles.restore` | restore from trash — **added by C1**, not in the original list |
| `roles.assign_permissions` | **the permission checkbox matrix on a role form** |

### permissions (catalogue)
| Permission | Guards |
|---|---|
| `permissions.view` | permissions index (read-only) |

### settings
| Permission | Guards |
|---|---|
| `settings.view` | settings page read |
| `settings.manage` | settings page write (POST/PUT) |

**21 permissions, seeded** (11 users + 7 roles + 1 permissions + 2 settings).
The original list was 19; C1 added `roles.force_delete` and `roles.restore` when
it shipped the trash tab, because a restore and a permanent delete are not
`roles.update` — see `docs/base/features/roles-permissions.md` §Role Lifecycle.
`audit.*` and `features.*` were planned here and
are **deliberately not seeded** — the audit viewer is Phase 10 and feature flags
are Phase 7, and neither has a route, controller or view today. A permission
nothing checks is a row in the permissions UI that looks meaningful and grants
nothing. Each group arrives in the same commit as the page it guards;
`PermissionSeedTest::every_permission_maps_to_a_resource_the_app_actually_has`
fails the moment a group is added without one.

### Seeded role matrix
| Role | Permissions |
|---|---|
| `superadmin` | Gate bypass (not enumerated in DB — see below) |
| `admin` | **the whole catalogue** (`PermissionCatalog::all()`) |
| `user` | **none** — baseline is profile + sessions only, which need no permission |

`admin` is the delegated superadmin: the account you hand someone when you do not
want to hand them the superadmin account. It takes the entire catalogue rather
than a hand-picked subset, because a subset is a second copy of
`PermissionCatalog` that rots silently — every permission added later would need
remembering in two places, and forgetting is invisible. `P6-B7` asserts the count
matches `all()`, so drift fails a test.

`superadmin` gets no rows in `role_has_permissions`. Its access comes from
`Gate::before`. This is the documented Spatie pattern and it means adding a new
permission never requires re-seeding superadmin.

**Why `user` has zero permissions and that is correct:** profile, password
change, sessions, and logout are *self* actions — they are authorized by
`$request->user()->id === $target->id`, not by a permission. Giving `user` a
permission to edit its own profile would be a lie about the model. A user with
zero permissions still reaches `/dashboard`, `/profile`, `/sessions`.

---

## Group A — UI Only (views, no logic)

> Goal: every Blade surface for roles + permissions exists and matches the design
> system **before** any route, action, or gate is written.
> Depends: Phase 5 (design system, confirm-action, shared components).
> Blocks: Group B (controllers render these views), and transitively C/D/E.
> Constraint: **no controller, no action, no route, no migration in this group.**
> Views receive plain arrays from `$roles`/`$permissions` and must not query.

### UI reference set (read before writing any markup)

| Reference | Path | What to copy |
|---|---|---|
| Index page | `resources/views/pages/users/index.blade.php` | content-header + breadcrumb, card, filter row, table, pagination footer |
| Create form | `resources/views/pages/users/create.blade.php` | card + form + `card-footer` action bar, right-column explainer |
| Edit form | `resources/views/pages/users/edit.blade.php` | form with existing values, status/role picker placement |
| Confirmation | `resources/views/pages/users/index.blade.php` action column | `<x-ui.confirm-action>` usage, `ACTION_CONFIG` keys |
| Design system | `docs/base/ui/design-system.md` | page skeletons §1–5, forbidden classes, action colour convention |
| Style guide | `docs/base/ui/style-guide.md` §8–§15 | button hierarchy, table, badge, modal, empty state |
| UI architecture | `docs/base/ui/ui-architecture.md` | partial naming, view-does-not-query rule |
| Authorization rule | `docs/base/ui/ui-authorization.md` | UI hiding is UX only; backend is the boundary |

### Hard UI rules
- Copy `pages/users/*` structure. Do not invent a new layout.
- Forbidden classes (§ Forbidden Classes): `bg-white`, `bg-light`,
  `card-body` without `p-4`, standalone Back button (use the description link).
- Confirmation modal: **never** hand-build `data-*` attributes. Use
  `<x-ui.confirm-action action-type="…">`. `ConfirmActionUsageTest` fails on a
  hand-built trigger, and any new `action-type` must exist in `ACTION_CONFIG`.
- No `@php` closure that builds HTML (the `$editBtn` pattern in users/index is
  legacy; do not copy it into new files).
- Views must not call `Permission::…`, `Role::…`, or `SystemSetting::…`.
  A view reads variables only.
- i18n: direct static text. `ai-execution-guide.md` rule 2 forbids translation
  calls in feature phases.

| ID | Task | Depends | Est. |
|----|------|---------|------|
| P6-A1 | `resources/views/pages/roles/index.blade.php` — content-header (`Roles`, breadcrumb `Roles / All Roles`), card with `card-header` (search input + `Create Role` button, both hidden by `@can`), `card-body p-4`, table `#` / Name / Users / Permissions / Actions, `table-responsive`, pagination footer identical to users/index | — | medium |
| P6-A2 | Roles index actions column — Edit link (always) + Delete via `<x-ui.confirm-action action-type="delete_role">`. Delete button **not rendered** for system roles; a `<x-ui.badge variant="info">System</x-ui.badge>` sits next to the name instead | A1 | small |
| P6-A3 | `resources/views/pages/roles/create.blade.php` — card + form, `name` input (`form-control form-control-sm @error is-invalid`), permission matrix partial, footer Cancel/Save per skeleton §3, right column explaining system-role rules | A1 | medium |
| P6-A4 | `resources/views/pages/roles/edit.blade.php` — same as create; `name` prefilled from `$role->name`; matrix pre-checked from `$role->permissions` | A3 | small |
| P6-A5 | `resources/views/partials/role-permission-matrix.blade.php` — checkboxes grouped by resource prefix (`users`, `roles`, `settings`, `audit`, `features`), `name="permissions[]"`, `value="{{ $permission->id }}"`, re-checked via `old('permissions', $selectedPermissions ?? [])`, `@error('permissions')` + `@error('permissions.*')`, empty state via `<x-ui.empty-state>` | A3 | medium |
| P6-A6 | `resources/views/pages/permissions/index.blade.php` — **read-only** catalogue. Grouped by resource, badge per permission showing how many roles hold it. No create/edit/delete buttons at all | — | medium |
| P6-A7 | Add `delete_role` key to `resources/js/helpers/action-config.js` (`variant: 'danger'`, `msg: 'Delete <b>__ITEM__</b>? Users with this role lose its permissions immediately.'`) | A2 | tiny |
| P6-A8 | Static menu group in `AppMenuComposer` already lists Roles/Permissions — **no change in this group**; the `permission` key is added in Group D | A1 | — |
| P6-A9 | Gate: `tests/Feature/RbacUiRenderTest.php` — render each new view with a hand-built array (`view('pages.roles.index', ['roles' => collect()])`) and assert: no exception, no forbidden class present in output, delete button absent when `$role->is_system` is true | A1–A7 | medium |

**Group A status: ✅ DONE** (2026-09-28). Audited against the filesystem; the
table below is the audit, not a wish list.

### P6-A1 → A9 audit

| ID | Task | Status | Evidence |
|----|------|--------|----------|
| P6-A1 | Roles index — header, breadcrumb, filter, table, pagination | ✅ DONE | `resources/views/pages/roles/index.blade.php` (128 lines) |
| P6-A2 | Actions column — edit always, delete via `<x-ui.confirm-action>`, `System` badge for system roles | ✅ DONE | `delete_role` trigger present; badge on `$role->is_system` |
| P6-A3 | Roles create — card + form + explainer column | ✅ DONE | `resources/views/pages/roles/create.blade.php` (92 lines) |
| P6-A4 | Roles edit — prefilled name (readonly for system roles), pre-checked matrix | ✅ DONE | `resources/views/pages/roles/edit.blade.php` (98 lines) |
| P6-A5 | Permission matrix partial | ✅ DONE | `resources/views/partials/role-permission-matrix.blade.php` (44 lines) |
| P6-A6 | Permissions index — read-only catalogue, `roles_count` badge | ✅ DONE | `resources/views/pages/permissions/index.blade.php` (65 lines) |
| P6-A7 | `delete_role` key in `ACTION_CONFIG` | ✅ DONE | `resources/js/helpers/action-config.js:74` |
| P6-A8 | `AppMenuComposer` unchanged | ✅ as specified | no diff — its `Roles` / `Permissions` items were already listed; `Route::has()` now resolves instead of falling back to `'#'` |
| P6-A9 | Gate test | ✅ DONE | `tests/Feature/RbacUiRenderTest.php` — 14 tests, 49 assertions, green |

### Shipped beyond the plan (the build needed reachable pages)

| File | Purpose |
|---|---|
| `app/Http/Controllers/Web/V1/RoleController.php` | `index` / `create` / `edit` + `SORTABLE` whitelist |
| `app/Http/Controllers/Web/V1/PermissionController.php` | `index` only |
| `routes/web.php` | `roles.index`, `roles.create`, `roles.edit`, `permissions.index` (GET) |
| `app/Support/SystemRole.php` | pulled forward from P6-B4 — the views need `is_system` and there is nowhere honest to put that answer in a view |

### Added after the first review round

| Item | Status | Why |
|---|---|---|
| Search filter + `Create Role` button | ✅ DONE | Both were wrapped in `@can('roles.view')` / `@can('roles.create')`, and the permission rows do not exist until P6-B1 — so `@can` was always false and neither rendered. Un-gated here; `@can` returns at P6-D5. |
| Sortable table headers | ✅ DONE | `<x-ui.sortable-th>` on Name / Users / Permissions; `#` and Actions not sortable. `SORTABLE` whitelist in the controller — the value reaches `orderBy`. |
| Filter placement aligned with `users/index` | ✅ DONE | Form moved from `card-header` into `card-body` above the table, same markup as `pages/users/index.blade.php:120` |
| `Create Role` right-aligned | ✅ DONE | `ms-auto` inside the `justify-content-between` row, matching `users/index` |

### Verified gaps inside Group A

| Gap | Why it is still open | Closes at |
|---|---|---|
| The four GET routes carried **no `can:` gate** | A `can:` gate on a permission that is not seeded denies everyone, superadmin included. Group B seeded the catalogue, so Group C2 gated them: `can:roles.view` / `can:roles.create` / `can:roles.update` / `can:permissions.view`. **CLOSED** | — |
| Roles create/edit forms posted to `roles.index` | No `roles.store` / `roles.update` route existed. Group C1 gave both real endpoints and the views post to them. **CLOSED** | — |
| `delete_role` trigger pointed at `roles.index` | No `roles.destroy` route existed. Group C1 added it, with the force override behind the confirm modal. **CLOSED** | — |
| The five role **write** routes still have no `can:` on the route | Not exploitable: each is gated by its own Form Request (`StoreRoleRequest` → `roles.create`, `UpdateRoleRequest` → `roles.update`, `DeleteRoleRequest` → `roles.delete`, `RestoreRoleRequest` → `roles.restore`, `ForceDeleteRoleRequest` → `roles.force_delete`). Route-level gates there are defence in depth only | P6-D1 (cosmetic) |
| `$role->users_count` on the roles index | `withCount('users')` is in the query, but a hand-built test fixture sets it manually; no controller-level gap. | — |

### Deliberately not built (proposed 2026-09-28, declined)

| Proposal | Decision | Reason |
|---|---|---|
| Navbar tabs: Active / Inactive / Trash | ✅ **Trash built 2026-09-29** (Active/Inactive still not) | `deleted_at` on `roles` is in. Only two tabs shipped — `roles` has no `is_active`, and a tab with no column behind it is a lie. The soft-delete half needed the revocation semantics documented in `docs/base/features/roles-permissions.md` §Role Lifecycle; `RoleSeeder` also restores a trashed system role so a reseed can repair its own state. |
| "Make default role" action in the actions column | ⛔ not built | `registration_default_role` already lives in `SystemSetting` (`database/seeders/SystemSettingSeeder.php:72`, read at `CreateUserAction.php:101`). A second control means two writers for one value — the `PolicyKeyTest` drift pattern. A guard belongs in `SystemSettingRequest`, not a new button. |
| Reassign a default role to users whose role was trashed | ⛔ not built | A user who loses a role keeps whatever else they hold. A fallback here would be a second writer for `registration_default_role` and would silently grant access nobody asked for. DeleteRoleAction documents the omission; add it when an app actually needs the behaviour. |

### Current state of the routes

```
GET  /roles             roles.index        Web\V1\RoleController@index
GET  /roles/create      roles.create       Web\V1\RoleController@create
GET  /roles/{role}/edit roles.edit         Web\V1\RoleController@edit
GET  /permissions       permissions.index  Web\V1\PermissionController@index
```

All four sit inside the authenticated group with **no permission gate**. Any
authenticated user can reach them. This is Group A's known, tracked gap and it
closes at P6-D1 — before then, the RBAC-006 escalation surface in
`task-tracker.md` is unchanged by this work.

**View-data contract the views read** (handed over, never queried by the view):

| View | Variables |
|---|---|
| `pages.roles.index` | `roles` (paginator of Role + `is_system`, `users_count`, `permissions_count`, `destroy_url`), `search`, `currentSort`, `currentDirection` |
| `pages.roles.create` / `edit` | `permissions` (Collection), `permissionGroups` (resource prefix → list) |
| `pages.roles.edit` | `role` (with `permissions` loaded, `is_system`, `users_count`) |
| `pages.permissions.index` | `permissions` (with `roles_count`), `permissionGroups` |

`is_system` and `destroy_url` are decorated onto the model by the controller.
A view reading them is reading view data, not reaching into the domain.

**Deviation from the plan, deliberate:** the plan said Group A ships views only.
The build needed the two controllers and the four GET routes to be reachable in
a browser, so they came in with it. They contain no save/delete path, no Form
Request, and no `can:` gate.

**Verification at close of Group A:**

| Check | Result |
|---|---|
| `php artisan test tests/Feature/RbacUiRenderTest.php` | 14 passed, 49 assertions |
| Full suite | 398 passed, 1235 assertions, 1 risky (pre-existing `DebugRateLimiterTest`) |
| `vendor/bin/pint --test --dirty` | passed |
| `npm run build` | built in 1.31s |
| `php artisan view:clear` | clean |
| Queries inside the views | 0 (asserted on a second render, so the Gate's own cache warm-up does not mask a `Role::…` left in Blade) |

---

## Group B — Permission Set & Seeders (the data foundation)

> Goal: the permission rows exist in the DB and the role matrix is seeded, so
> every later group gates against real data instead of hardcoded strings.
> Depends: Group A (nothing else in the phase can gate on permissions that
> don't exist). Blocks: C, D, E.

| ID | Task | Depends | Est. |
|----|------|---------|------|
| P6-B1 | `database/seeders/PermissionSeeder.php` — `forgetCachedPermissions()` **before** seeding (docs: avoids cache conflict), create every permission from the table above with `guard_name => RoleLookup::guard()`, `forgetCachedPermissions()` again after creation but **before** any assignment, then `$permission->assignRole($role)` per role per the matrix. Idempotent: `firstOrCreate` on `(name, guard_name)`, and `syncPermissions` on the role so re-seeding cannot leave a stale grant | — | medium |
| P6-B2 | Register `PermissionSeeder` in `DatabaseSeeder` **after** `RoleSeeder` (roles must exist before permission→role assignment) and **before** `SuperAdminSeeder` so the superadmin user is assignable in the same pass | B1 | tiny |
| P6-B3 | `App\Support\PermissionCatalog` — static `all(): array<string>` returning the permission-name list, and `grouped(): array<string, array<string>>` (resource prefix → permission names), derived with `Str::before($name, '.')`. Single source read by the seeder, the matrix view's group headings, and the tests. **No hardcoded copy in Blade** | B1 | small |
| P6-B4 | Add `'is_system'` derivation — `App\Support\SystemRole::isSystem(string $name): bool` for `superadmin`/`admin`/`user`, plus `SystemRole::names()`. Replaces the bare array literal in `RoleSeeder` and is the single place that answers "is this a system role" | B1 | tiny |
| P6-B5 | Update `RoleSeeder` to use `SystemRole::names()` (comment at `:16-18` already says permissions come in RBAC-004 — that comment becomes true, keep it accurate) | B4 | tiny |
| P6-B6 | `Gate::before` in `AuthServiceProvider::boot()`: `return $user->hasRole('superadmin') ? true : null;` — **`null`, never `false`**. Comment records why: returning `false` would short-circuit every policy and deny everyone | B1 | tiny |
| P6-B7 | `tests/Feature/PermissionSeedTest.php` — every name in `PermissionCatalog::all()` exists on the resolved guard; `superadmin` has **zero** `role_has_permissions` rows and passes `can('anything')`; `admin` has all 11 `users.*`; `user` has none; re-running the seeder twice changes no counts; a role does not exist twice on the same guard | B1–B6 | medium |
| P6-B8 | `tests/Feature/PermissionCacheTest.php` — after `forgetCachedPermissions()` in `setUp()`, `can()` reflects a fresh DB change; assert a seeded permission is visible to a user assigned after `setUp()` | B7 | small |

**Gate B:** `php artisan db:seed` twice is idempotent, `PermissionSeedTest`
green, `hasRole('superadmin')` → `can()` true while `role_has_permissions` is
empty for that role.

---

**Group B status: ✅ DONE (verified 2026-09-28)**

| ID | Status | Evidence |
|----|--------|----------|
| P6-B1 | ✅ | `database/seeders/PermissionSeeder.php` — prune → create → assign, cache flushed at all three points |
| P6-B2 | ✅ | `DatabaseSeeder` orders it `RoleSeeder` → `PermissionSeeder` → `SuperAdminSeeder` |
| P6-B3 | ✅ | `app/Support/PermissionCatalog.php` — `all()`, `grouped()`, `forResource()` |
| P6-B4 | ✅ | `App\Support\SystemRole` — shipped in Group A, spec satisfied, unchanged |
| P6-B5 | ✅ | `RoleSeeder` consumes `SystemRole::names()`; the stale RBAC-004 comment corrected |
| P6-B6 | ✅ | `AuthServiceProvider::configureSuperAdmin()` — `?bool`, returns `true`/`null` |
| P6-B7 | ✅ | `PermissionSeedTest` — 17 tests, 114 assertions |
| P6-B8 | ✅ | `PermissionCacheTest` — 5 tests, 10 assertions |

**Gate B result:** 19 permissions seeded · `superadmin` 0 rows and `can()` true ·
`admin` 19 · `user` 0 · `db:seed` twice → counts unchanged · full suite 420 passed ·
`pint --test --dirty` clean.

### Deviations from the spec, and why

1. **`matrix()` is a method, not a class constant.** PHP constants cannot call
   methods, and `admin` needs `PermissionCatalog::all()` rather than a literal
   copy. A const would have forced a hand-written list back in.

2. **`syncPermissions`, not `assignRole` per permission.** The spec said
   `$permission->assignRole($role)`. `syncPermissions` also *removes* grants the
   role no longer has, so a permission dropped from the matrix stops granting
   instead of sticking forever.

3. **Permissions were pruned. Not in the spec.** `firstOrCreate` only ever adds,
   so a permission removed from `PermissionCatalog` stayed in the database
   permanently — visible in the permissions UI and still granting `can()` to
   whoever held it. The seeder now deletes rows on our guard that `all()` no
   longer declares, cascading to `role_has_permissions`. Scoped to
   `RoleLookup::guard()` so another guard's catalogue is untouched.

4. **`SuperAdminSeeder` now assigns the role. Not in the spec, and required.**
   It created the account but never called `assignRole`, which was invisible
   until `Gate::before` arrived: the superadmin user had no `superadmin` row, so
   `hasRole('superadmin')` was false and the account was denied everything. This
   is the one change outside the B1–B8 list; without it P6-B6 is not actually
   true. Uses `syncRoles` and `RoleLookup::guard()`.

5. **`audit.*` and `features.*` are not seeded.** See the permission table
   above. `permissions.view` was added — it guards `permissions.index`, a route
   that exists, and omitting it would have left the catalogue page ungateable.

6. **`Gate::before` returns `?bool`.** The declared `null`-not-`false` rule is
   load-bearing, so the return type states it. A `Gate::before` returning
   `true` wins over every policy — anything that must still apply to a
   superadmin belongs in the policy, not here. Noted in the docblock.

---

## Group C — Controllers, Actions, Form Requests

> Goal: the role/permission CRUD endpoints exist and are authorized, and role
> assignment on the user form **syncs**. This is where the sync bug in the
> brief lives.
> Depends: Group B (permissions must exist to be granted). Blocks: D (menu
> gating reads the same permissions), E.

### C1 — Role management (Web)

> **Status: ✅ DONE (2026-09-29, audited against the code).** P6-C1..C6 all
> implemented. Deviations are listed in § C1 audit notes below. Gate C is now
> **met** — the evidence table sits in the § C4 section below.

| ID | Task | Depends | Est. | Status |
|----|------|---------|------|--------|
| P6-C1 | `App\Http\Requests\Role\StoreRoleRequest` — `authorize(): return $this->user()?->can('roles.create') ?? false`; rules: `name` required/string/max:64/`Rule::unique('roles','name')` **scoped to the resolved guard**; `permissions` nullable array; `permissions.*` `integer` + `exists:permissions,id` | B | small | **DONE** — as specified; unique guard-scoped via `RoleLookup::guard()` |
| P6-C2 | `App\Http\Requests\Role\UpdateRoleRequest` — `authorize(): can('roles.update')`; `name` unique ignoring `$role`; `permissions.*` as above | B, C1 | small | **DONE** — as specified; `Rule::unique()->ignore($role)` |
| P6-C3 | `App\Actions\V1\Role\IndexRoleAction` — `Role::query()->where('guard_name', RoleLookup::guard())->withCount(['permissions','users' …])`, optional `search` filter applied **before** `paginate(10)`, `->withQueryString()`. No N+1 | B4 | small | **DONE** — as specified, plus a `trashed` flag added for the trash tab (scopes the whole query rather than filtering rows) |
| P6-C4 | `App\Actions\V1\Role\SaveRoleAction` — one action for create+update. `DB::transaction`: reject system-role rename (C8), `syncPermissions(array_map('intval', $data['permissions'] ?? []))` — the `intval` is required, spatie resolves a **string** `'19'` via `findByName` and throws `PermissionDoesNotExist`; audit `role.created` / `role.updated` **inside** the transaction before commit (ADR: no audit record on rollback) | B | medium | **DONE, split** — shipped as `CreateRoleAction` + `UpdateRoleAction` over a shared `App\Actions\Concerns\PersistsRole` trait, which holds the transaction, the `intval` cast, and the in-transaction audit. Split to match `CreateUserAction` / `UpdateUserAction` on the user side |
| P6-C5 | `App\Http\Controllers\Web\V1\RoleController` — thin. `index` → `$action->run()` → `view('pages.roles.index')`; `create`/`edit` → `Permission::orderBy('name')->get()` + `PermissionCatalog::grouped()`; `store`/`update` → `SaveRoleAction`; `destroy` → deny for system roles, else delete + audit | C1–C4 | medium | **DONE** — thin as specified. The system-role refusal this row places in the controller lives in `DeleteRoleAction` instead (P6-C6's own wording), so the API cannot bypass it by not going through the controller |
| P6-C6 | `App\Actions\V1\Role\DeleteRoleAction` — refuse when `SystemRole::isSystem($role->name)`; refuse when the role still has users assigned (unless `force` is passed) — trashing **deassigns** every holder, so the count lands in the `role.deleted` audit row. Inside one transaction: `users()->detach()` → `delete()` (soft) → audit. `RestoreRoleAction` / `ForceDeleteRoleAction` own the other two verbs | B4 | small | **DONE, extended** — also `RestoreRoleAction` + `ForceDeleteRoleAction`, and the web path passes `force` because the confirm modal is the deliberate override |

### C2 — Permission catalogue (Web, read-only)
| ID | Task | Depends | Est. |
|----|------|---------|------|
| P6-C7 | `App\Actions\V1\Permission\PermissionIndexAction` — `Permission::where('guard_name', RoleLookup::guard())->withCount('roles')->orderBy('name')`, plus search, a sortable whitelist, and pagination. **Grouping by prefix not built** (open decision, see audit notes) | B3 | done, with deviation |
| P6-C8 | `App\Http\Controllers\Web\V1\PermissionController` — `index()` only, guarded by `can('permissions.view')`. **No store/update/destroy** — permissions are code-defined and seeded. A UI that creates a permission row nobody's `can()` call references is a trap | C7 | done |

### C3 — Role assignment sync (the brief's core case)
| ID | Task | Depends | Est. |
|----|------|---------|------|
| P6-C9 | `UpdateUserAction` — roles branch is already `array_key_exists`-guarded (`:59`). **Do not change it.** Add one thing: a `users.assign_roles` check before `syncRoles`, and reject a payload that would strip `superadmin` from the last superadmin (C11) | C5 | done |
| P6-C10 | `CreateUserAction` — roles come from `RoleLookup::find()` already (`:62`). Add the same `users.assign_roles` check on the admin path only; the self-registration path (`defaultRolesForSelfRegistration()`) is **not** permission-gated because it is not an admin action and its role is server-side, not client-supplied | C5 | done |
| P6-C11 | `App\Actions\V1\Role\AssignRolesAction` — one place that both C9 and C10 call: `DB::transaction`, resolve names through `RoleLookup::find()` (skip unknown rather than throw — `CreateUserAction` already silently skips, and a hard failure on a stale form is worse), count superadmins before/after, throw `LastSuperadminException` if the result is zero, `syncRoles()`, audit `user.roles_assigned` with the before/after lists in `properties` | B4 | done |
| P6-C12 | `App\Exceptions\LastSuperadminException` + renderable in `bootstrap/app.php` → web redirect with `error` flash, API 409 JSON | C11 | done |
| P6-C13 | ~~`AssignRolesRequest`~~ **merged into `AssignRolesAction` (2026-09-29).** The class was built as specced but no route ever referenced it, so the `users.assign_roles` check lived in code nothing could reach. The check and the guard-scoped role resolution now live in `AssignRolesAction`, which both write paths call. Role payloads are still validated before they reach it, by `CreateUserRequest`/`UpdateUserRequest`. Standalone role assignment is a role-picker edit on the existing user forms, not a separate endpoint | C5 | done |

### C4 — Fix the ungated `authorize()` returns
> **Status: ✅ DONE (2026-09-30, re-audited).** P6-C14..C18 all implemented in
> `015d6c3`. Gate C is met; the evidence is in § C4 audit notes below.

| ID | Task | Depends | Est. | Status |
|----|------|---------|------|--------|
| P6-C14 | `CreateUserRequest::authorize()` → `can('users.create')` **on the admin path only**. A self-registering user has no permission, so the register controller must not reuse this request. `RegisterRequest` is the separate public contract and stays `true` | C5 | small | **DONE** — as specified. `UserController@store` (web + api) is the only caller; `RegisterController` uses `RegisterRequest` |
| P6-C15 | `UpdateUserRequest::authorize()` → `can('users.update')`, plus `$this->user()->id === $user->id` **only** when the target is the caller and the payload has no `roles` key (self-profile edit through the admin form) | C5 | small | **DONE** — as specified. The self exception is also key-compared as strings, so a `null` user cannot match |
| P6-C16 | `SystemSettingRequest::authorize()` → `can('settings.manage')` | C5 | small | **DONE** — as specified |
| P6-C17 | `BulkUserRequest::authorize()` → map the requested `action` to its permission (`delete`→`users.delete`, `lock`→`users.lock`, …) instead of one blanket check | C5 | medium | **DONE, extended** — extracted to the `AuthorizesBulkAction` trait and adopted by `BulkRoleRequest` too, which is what closed P6C1-005 |
| P6-C18 | `UserPolicy` — add `viewAny`, `view`, `create`, `update`, `delete`, `forceDelete`, `restore` methods delegating to the matching `users.*` permission, keeping the four existing state methods **exactly as they are** (their 409 preconditions are business rules, not authorization) | C5 | small | **DONE** — as specified. The four state methods are untouched |

**Gate C: MET (2026-09-30).** Every clause, with the test that carries it:

| Gate C clause | Evidence |
|---|---|
| Every role/permission route reachable and authorized | `routes/web.php:128-141` gates the four reads; the five writes are gated by their Form Requests. `RoleManagementTest::test_a_user_with_no_permissions_cannot_write_roles` + `test_a_user_with_no_permissions_cannot_reach_the_trash_endpoints` assert 403 on all five |
| A zero-permission user gets 403 on all of them | `GateCAuthorizationTest::test_a_user_with_no_permissions_gets_403_on_every_admin_read_route` loops `users.index` / `roles.index` / `permissions.index` / `settings.index` |
| Role assignment syncs (add → grants, replace → swaps, empty → clears, absent → untouched) | `AssignRolesActionTest` (8 tests) + `UserRoleEditTest` (7 tests) |
| Last-superadmin cannot be stripped | `test_stripping_the_last_superadmin_is_refused` + `test_the_refusal_leaves_the_superadmin_in_place`; a demotion among two is still allowed, so the guard is a floor, not a freeze |
| `UserCrudWebTest` + `UserRoleEditTest` still green, updated not deleted | Full suite: **505 passed / 1657 assertions**, 1 risky (pre-existing) |

**What Gate C deliberately does not cover.** Gate C is a *Group C* gate. The
seven ungated user state routes (`restore`, `force-delete`, `activate`,
`deactivate`, `lock`, `unlock`, `resend-verification`, `cancel-email-change`) and
`api.v1.settings.index` carry no `can:` and no request-level check — measured,
with the side effect confirmed rather than the 302 — and `UserPolicy`'s methods
for them are still dead code on those paths. All of that is P6-D1/D2, tracked in
`docs/qa/remediation-tracker.md` as P6C4-001..005. Calling Gate C met is not a
claim that the whole RBAC surface is closed; it is a claim that Group C's own
tasks are.

**C7's class name is a kept deviation, not a pending refactor.** What ships is
`PermissionIndexAction`; the C1 convention (`IndexRoleAction`) would name it
`IndexPermissionAction`. Decided 2026-09-30 to **keep** the shipped name and
record the deviation here rather than spend the rename — the name is referenced
in exactly 10 places, all internal, and `PermissionIndexActionTest` already
pins behaviour. Revisit if a third action appears and the inconsistency starts
costing a reader.

### Performance — measured 2026-09-30 (P6-E10, Group C surfaces)

SQLite, warm permission cache, `RefreshDatabase`. Counts are per HTTP request,
measured after one throwaway request warms Spatie's cache, so the cache fill is
not billed to the page. Pinned by `RbacPerformanceTest` (7 tests).

| Request | Queries | ms | Note |
|---|---|---|---|
| `GET /roles` — 10 roles | 5 | 46 | |
| `GET /roles` — **100 roles** | **5** | 55 | flat: `withCount` is a subquery, not a per-row load |
| `GET /roles?search=A` — 100 roles | 5 | 50 | search is applied before `paginate`, so it narrows rather than filters |
| `GET /roles?trashed=1` — 100 roles | 4 | 31 | one fewer: the live count query is skipped |
| `GET /permissions` — 21 catalogue | 4 | 80 | `withCount('roles')` + the eager role load |
| `GET /permissions?search=users` | 4 | 117 | |
| `GET /roles/create` — 21 checkboxes | 2 | 35 | `PermissionCatalog` is a static array: 0 queries for the catalogue |
| `GET /roles/{id}/edit` | 5 | 39 | +3 over create: the role, its permissions, its user count |
| `GET /dashboard` — sidebar | 1 | 21 | the sidebar carries **no** `@can` gates (that gap is P6-D5), so it cannot cost a permission query |
| `GET /users` | 3 | 126 | not a Group C page; the ms is the count subqueries, unchanged by this phase |
| `AssignRolesAction` — 1 role | 9 | — | after `a4b33ab`; was 11 |
| `AssignRolesAction` — **20 roles** | **9** | — | **was 28** — `RoleLookup::find()` in a loop, one select per name. Now one `whereIn` |
| `DeleteRoleAction` — 0 or 30 holders | 5 | — | flat: `detach()` runs once, not per holder |
| `RestoreRoleAction` / `ForceDeleteRoleAction` | 4 / 5 | — | |

**The assertion is the delta, not the ceiling.** Each test renders the same page
at two row counts and asserts the counts match. A ceiling like "under 20
queries" passes for an N+1 that happens to fit under the number someone picked;
only a count that *moves* when the rows move distinguishes the two. The
absolute numbers are in the failure messages so a regression is visible even
while the delta still passes.

**One thing the measurement caught — a real N+1, now fixed.** `AssignRolesAction`
resolved role names through `RoleLookup::find()` once per name, so a 20-role
payload cost 20 selects: 28 queries total, of which 20 were the same lookup
repeated. The query tally is what identified it, since a page-level count
would not show a cost that only appears on a write path. `RoleLookup::findMany()`
now answers the whole set in one `whereIn` (`a4b33ab`), and the same 20 roles
cost 9. Fixed in `RoleLookup` rather than at the call site because
`CreateUserAction`'s self-registration branch had the identical loop.

**Two things the measurement did NOT catch, both of which cost a wrong
conclusion first.** Worth writing down, because the failure mode is a red
number that reads exactly like a real defect:

- A fixture write flushes Spatie's cache, so the next call pays to refill it —
  a full `permissions` select plus a role eager load. The first run of
  `the_permissions_index_does_not_grow_with_the_row_count` reported 4 → 6 and
  looked like a missing `withCount`. It was not: both extra queries were the
  refill caused by the test's own `givePermissionTo()`, and the page's query
  shape was an identical 4 either way.
- A cold first call is not comparable to a warm second one. The first
  `can()`/`hasRole()` in a process fills the cache, so measuring both and
  comparing gives 11 vs 9 — which reads as "one role costs more than twenty".
  It is the cache fill, not the query.

Rule: warm, then measure. If a delta goes red, check whether the fixture wrote
first.

### C2/C3 audit notes (2026-09-29)

The tables above carry the per-task status; these notes hold the reasoning, the
deviations, and what is deliberately still open.

**Why the `users.assign_roles` check is not in either action.** C9 and C10 each
ask for the same check, in the two actions that already had a roles branch. It
ships in `AssignRolesAction` instead, which is the one place both call. Two
copies is how C9 and C10 end up disagreeing about who may hand out a role — and
they did: with the check in each caller, the create path was protected and the
update path was not, so a caller holding only `users.update` could make any
account a superadmin:

    PUT /users/{victim}  + roles:["superadmin"]  =>  302, victim is superadmin

`AssignRolesAction` is also the only path to `syncRoles`; the single inline
`assignRole()` in `CreateUserAction` is the self-registration default branch,
which is server-side and must stay un-gated.

**Superadmin grant vs. superadmin removal are different questions.** Removing
the last superadmin is refused by `guardLastSuperadmin()`, which counts before
and after and throws `LastSuperadminException` if the result is zero — that is
C11 exactly as written. Granting it additionally requires the causer to already
be a superadmin, because `users.assign_roles` is broad enough to edit any
ordinary role, and on its own it would let a delegated admin mint a second
superadmin. Note the asymmetry this leaves, which is deliberate: the *count*
guard is a minimum of one, not exactly one. A second superadmin is still
creatable by an existing superadmin. The spec says "if the result is zero", so
enforcing "exactly one" is a change of requirement, not a fix — raise it before
assuming it.

**C7's class name and the grouping decision.** The table names
`IndexPermissionAction`; what ships is `PermissionIndexAction`, and the shipped
name is the inconsistent one against the C1 convention (`IndexRoleAction`). The
deviation is now **kept, not pending** — see the note in § C4 below.

Grouping by resource prefix is **not built**. The catalogue page is searchable,
sortable and paginated, and a paginator cannot be grouped without losing all
three. The page stays a flat table and the resource is still derived, once per
row, in the view. This is an open decision between grouping and pagination, not
a silent omission.

**C13 merged, not built.** `AssignRolesRequest` was built as specced and then
deleted. No route ever referenced it, so the check it existed to express lived
in a class nothing could reach; the check and the guard-scoped role resolution
now live in `AssignRolesAction`. Role payloads are still validated before they
reach it, by `CreateUserRequest` / `UpdateUserRequest`. A standalone
role-assignment endpoint was not needed — role editing is the picker on the
existing user forms.

**Shipped beyond the C2/C3 tables.** The superadmin visibility rule (the role
and the account that holds it are visible only to a superadmin), the two
audience-specific user-index count keys, the route-level `can()` gates for
`/users`, `/roles` and `/permissions`, and the form requests that read the
index query strings (`RoleQueryRequest`, `PermissionQueryRequest`).
`registration_default_role` is now validated against the same visible-to-this-
viewer set its dropdown is built from, so the form cannot offer a choice the
rules then accept.

**Out of scope, tracked elsewhere.** `lock`, `unlock`, `activate`, `deactivate`,
`restore`, `force-delete` and `users.bulk-action` carry no route gate. That is
P6-C18 / C4 work, not a C2/C3 gap — `UserPolicy` already has the state methods,
they are simply not called yet.

### C1 audit notes (2026-09-29)

The table above carries the per-task status; these notes hold the reasoning and
what shipped beyond it.

**Why C4 was split rather than merged.** The table names one `SaveRoleAction`
for both verbs. Shipped as two classes over a `PersistsRole` trait, because the
user side already splits (`CreateUserAction` / `UpdateUserAction`) and a reader
scanning the action list should see both verbs. The trait holds everything that
is *not* the verb: the `DB::transaction`, the `intval` cast, and the audit write
inside the transaction (DEP-003 — an audit row that survives a rollback records
a save that never happened). Duplicating those across two classes is how one of
them ends up forgetting the audit.

The `intval` is load-bearing, not tidying: the matrix partial posts checkbox
values, which arrive as strings. Spatie's `syncPermissions()` resolves a non-int
through `findByName()`, so `'19'` is looked up as a permission literally **named**
"19" and throws `PermissionDoesNotExist`.

**Why C5's refusal moved.** The table places the system-role refusal in the
controller; P6-C6's own wording places it in `DeleteRoleAction`. It lives in the
action so the API cannot bypass it by not going through the web controller.

**Not in the C1 table, shipped anyway** (the enterprise / SaaS role-retirement
requirement): soft delete with revocation, the trash tab, restore, permanent
delete, and bulk actions. All documented in
`docs/base/features/roles-permissions.md`.

**The `BulkRoleRequest` defect this section used to carry is fixed.** It shipped
with a blanket `return true`, so a user holding no `roles.*` permission could
bulk-trash a role while the single-row delete correctly refused. P6-C17 closed
it: both bulk requests now share the `AuthorizesBulkAction` trait, one
`BULK_ACTIONS` map plus an entity prefix, and an unmapped action fails closed.
Pinned by `test_a_role_bulk_action_requires_its_own_permission` in
`GateCAuthorizationTest`. Tracked as P6C1-005 in
`docs/qa/remediation-tracker.md` (RESOLVED).

---

## Group D — Route, Menu & UI Gating

> Goal: the permission becomes the access boundary on every path — web, API,
> sidebar, and button. This is the group that actually closes RBAC-006.
> Depends: Group C (endpoints exist), Group B (permissions exist). Blocks: E.

| ID | Task | Depends | Est. |
|----|------|---------|------|
| P6-D1 | `routes/web.php` — split the flat authenticated group. `Route::middleware(['can:users.view'])->group()` around the `users` resource; `can:settings.view` + `can:settings.manage` on the two settings routes; `can:roles.view` / `can:roles.create` / `can:roles.update` / `can:roles.delete` split per role route; `can:permissions.view` on the permission index. **Never both an `auth` group and a `permission:` group for the same route** — that registers it twice. Use `can:`, not the `permission:` alias, so the check goes through the Gate and `Gate::before` applies uniformly | C | medium |
| P6-D2 | `routes/api.php` — **same permission matrix on the API group** (`routes/api.php:44`). Both files, same gates, same throttle patterns. An API-only gap is the same hole with a different URL | D1 | medium |
| P6-D3 | `AppMenuComposer` — add a `'permission' => 'users.view'` key per item and filter in the composer: `array_filter` on `auth()->user()?->can($item['permission'])`. Drop a group whose items all filtered out. `Dashboard` gets no key (every authenticated user has it). Remove `Roles`/`Permissions` items only if the routes are not shipped — they are | B6 | small |
| P6-D4 | Sidebar: no `@can` in `partials/sidebar.blade.php`. The composer already filters; adding a second `@can` in Blade would be a duplicate lookup for the same answer | D3 | — |
| P6-D5 | Users index — wrap the `Create User` button in `@can('users.create')`; wrap each state button in its own `@can` (`users.deactivate`, `users.lock`, `users.activate`, `users.unlock`, `users.delete`, `users.restore`, `users.force_delete`). Triggers stay `<x-ui.confirm-action>` — the `@can` goes **around** the component, never inside it | C | medium |
| P6-D6 | Users index bulk dropdown — filter `<option>`s by the same permissions. A visible option that 403s is a worse UX than a hidden one | D5 | small |
| P6-D7 | `partials/user-role-picker.blade.php` — wrap the whole picker in `@can('users.assign_roles')`; when hidden, submit **no** `roles` key at all (an unchecked-but-present key that posts `[]` would clear the user's roles on the next unrelated save). The `array_key_exists` contract in C9 is what makes this safe | C9 | small |
| P6-D8 | Settings page — the write form is `@can('settings.manage')`; the page itself needs `settings.view`. A `settings.view`-only user sees a read-only page, not a 403 | C16 | small |
| P6-D9 | Update `ConfirmActionUsageTest` data provider — add `roles.index` and `permissions.index` pages and the `delete_role` key | A7 | small |
| P6-D10 | Update `partials/user-role-picker` consumers — `pages/users/create.blade.php:62` and `pages/users/edit.blade.php:99` pass `$roles`; the picker now also needs to know nothing extra (permission handled by `@can`) — verify no caller breaks | D7 | tiny |

**Gate D:** a zero-permission user (role `user`) gets 403 on `/users`,
`/settings`, `/roles`, `/permissions` and the whole API equivalent, while
`/dashboard`, `/profile`, `/sessions` still return 200. A `staff`-equivalent
role with `users.view` + `users.update` + `settings.manage` sees exactly three
sidebar items and can reach exactly those three areas.

---

## Group E — Superadmin & System-Role Protection, Tests, Docs

> Goal: close the documented abuse paths and prove the whole matrix.
> Depends: A–D. Final group.

| ID | Task | Depends | Est. |
|----|------|---------|------|
| P6-E1 | `RolePolicy` / `SaveRoleAction` — system role rename refused (name in `SystemRole::names()` cannot change; the input is compared to the stored name, server-side, not hidden in the UI) | C4 | small |
| P6-E2 | `DeleteRoleAction` — refuse for all three system roles, including by superadmin. Already in C6; here add the test | C6 | tiny |
| P6-E3 | System-role permission stripping — a system role's permission set is written by the seeder, not by the role form. `SaveRoleAction` refuses a permission payload for a system role name and returns a 422/redirect with the reason | E1 | small |
| P6-E4 | Last-superadmin: `AssignRolesAction` (C11) already counts. Add the two remaining paths — **delete** the last superadmin user and **deactivate** the last superadmin user — both must be refused. `DeleteUserAction` / `DeactivateUserAction` call the same count helper | C11 | medium |
| P6-E5 | "Superadmin assignment restricted to the most privileged path" — assigning `superadmin` requires `roles.update` **and** an explicit `confirm_superadmin` flag on the payload; without it the request is rejected. The UI asks via the confirm modal. This is the "removing superadmin requires explicit audited confirmation" rule, applied to both directions | C11, A7 | medium |
| P6-E6 | Confirm modal copy for E4/E5 — add `remove_superadmin` key to `ACTION_CONFIG` (`variant: 'danger'`, names the account) | E4 | tiny |
| P6-E7 | `tests/Feature/RbacAuthorizationMatrixTest.php` — the brief's two cases, as tests: **User A** role `user`, zero permissions → 403 on `/users`, `/settings`, `/roles`, `/permissions`; 200 on `/dashboard`, `/profile`, `/sessions`; sidebar HTML contains no `Users`, `Roles`, `Settings` link. **User B** role `staff` with `users.view` + `users.update` + `settings.manage` → 200 on `/users` + `/settings`; 403 on `/roles`; sidebar contains `Users` + `Settings`, not `Roles` | D | medium |
| P6-E8 | `tests/Feature/RbacRoleSyncTest.php` — user A `user` → change to `staff` → `$user->fresh()->can('users.view')` is true and `can('roles.view')` is false; the DB shows no row in `model_has_permissions` (role-derived, ADR-004, no physical copy); replace roles swaps; `roles => []` clears; **omitting** `roles` leaves them untouched; a payload naming a non-existent role is rejected with a validation error | C9 | medium |
| P6-E9 | `tests/Feature/RbacPentestTest.php` — replay the RBAC-006 exploit list verbatim: every request in the table at the top of this doc, as a zero-permission user, web **and** API, expecting 403. Plus: mass-assignment of `roles` on `PUT /profile` (self endpoint) must not assign roles; `PUT /users/{other}` with `roles[]=superadmin` must 403; `POST /settings` with a valid `settings.manage` holder still requires CSRF | D, C | medium |
| P6-E10 | Performance check — `PermissionCatalog::all()` and the matrix view add **zero** queries per row (`assertQueryCount` or Telescope-free manual count): roles index uses `withCount`, permissions index uses `withCount('roles')`, the sidebar composer makes at most one permission-cache read per request (package cache is on; `Gate::before` does `hasRole` on an already-loaded relation, not a fresh query). Record the numbers in the phase report | C, D | small | **PARTIAL (2026-09-30)** — every Group C surface measured and pinned by `RbacPerformanceTest` (7 tests); numbers in `phase-6-rbac.md` § Performance. No regression anywhere: the roles index is flat at 5 queries from 10 rows to 100, and three warm `can()` calls cost 0. One real N+1 found and fixed on the way (`a4b33ab`). **Blocked on D** for the sidebar half — it carries no `@can` gates at all today, so there is nothing to measure until D5 adds them |
| P6-E11 | Docs — update `docs/base/features/roles-permissions.md` (fill the permission table, mark seeded roles with real sets, resolve the `guard_name` open question from §Seeding Strategy: `RoleLookup::guard()`), `docs/base/security/authorization.md` (Gate::before is the superadmin mechanism), `docs/planning/task-tracker.md` (RBAC-001..006 → DONE with group refs), `docs/planning/progress.md` (phase 6 row), `docs/planning/qa-tracker.md` (QA-RBAC-* rows → DONE with the manual scenario each one covers) | A–E | medium |
| P6-E12 | Full regression — `php artisan test` green, `npm run build` clean, `vendor/bin/pint --test` clean. Every pre-existing user test that calls a now-gated endpoint gets a permission grant in `setUp()`, not a deleted assertion | A–E | medium |

**Gate E:** all 403s hold, the two brief scenarios pass as written, exploit
replay is fully blocked, no query-count regression, docs match code.

---

## Performance Notes

| Risk | Mitigation |
|---|---|
| N+1 on role/permission listings | `withCount()` in the index actions; the views iterate counts, not relations |
| Permission cache misses | Package cache stays enabled (24h, `CACHE_STORE=database`); the seeder flushes before + after so a fresh DB is not read stale |
| `hasRole` per menu item | The composer runs once per request; every item shares the user's loaded `roles` relation and the package cache. Filter with `can()`, never a fresh `Role::findByName` |
| `exists:roles,name` unscoped | Today's `CreateUserRequest:27` and `UpdateUserRequest:39` are unscoped. Fixing this to a guard-scoped `Rule::exists` adds one indexed lookup on a small table — a fair trade for removing the duplicate-role class of bug |
| Route middleware ordering | `can:` must run **after** `auth` — a `can` gate on an unauthenticated user returns 401 not 403, and inside the authenticated group it is automatic |

## Security Notes (pentest checklist)

| Vector | Control |
|---|---|
| Escalation via self-registration | `RegisterRequest` is the only `authorize() => true` request; the register controller never reads client-supplied roles. `defaultRolesForSelfRegistration()` is server-side |
| Escalation via `PUT /users/{id}` | `users.update` **and** `users.assign_roles` both required; C15 |
| Escalation via `POST /users` | `users.create` + `users.assign_roles`; C14 |
| Reading the user list | `users.view`; the list is a full PII dump (every email) |
| Rewriting security settings | `settings.manage`; the page also logs in on every change (Phase 8) |
| Guard confusion | Every role read via `RoleLookup`; `Rule::exists` guard-scoped |
| Privilege persistence | `can:` gates, not UI hiding — `ui-authorization.md` is explicit |
| Superadmin abuse | System roles undeletable/unrenamable, last-superadmin protected on assign + delete + deactivate, superadmin assignment needs an explicit confirmed flag |
| Audit gaps | Every role/permission/user-role mutation audits **inside** its transaction, before commit |
| Stringified permission IDs | `array_map('intval', …)` before `syncPermissions` — spatie resolves the string `'19'` as a *name* and throws |

## Deliberate Simplifications

```
ponytail: the permission catalogue is a static list in PermissionCatalog, not a
DB-editable CRUD. Permissions are code — a row nothing's can() references is a
trap. Revisit only when a tenant or plugin needs runtime-defined permissions.
```
```
ponytail: no `is_system` column on the roles table; the three system names live
in SystemRole. Adding a fourth system role is a one-line change. Revisit if
roles ever need to be created by data migration rather than by name.
```
```
ponytail: permissions.index is read-only. No create/edit/delete UI for
permissions in this phase. Revisit with the catalogue above.
```

---

## Execution Order

```
Group A (UI — views only)        — A1 → A2 → A3 → A4 → A5 → A6 → A7 → A9
                                          ↓ (A9 gate) RbacUiRenderTest green
Group B (Permission set + seed)  — B1 → B2 → B3 → B4 → B5 → B6 → B7 → B8
                                          ↓ (B8 gate) seeder idempotent + superadmin bypass
Group C (Controllers + actions)  — C1..C6 → C7..C8 → C9..C13 → C14..C18
                                          ↓ (C gate) all endpoints authorized + sync proven
Group D (Route/menu/UI gating)   — D1 → D2 → D3 → D5 → D6 → D7 → D8 → D9 → D10
                                          ↓ (D gate) 403 matrix holds on web + API
Group E (Protection + tests)     — E1..E6 → E7 → E8 → E9 → E10 → E11 → E12
                                          ↓ (E12 gate) full regression green
                                   Phase 6 COMPLETE
```

Each group = one commit boundary. Sequential — A cannot skip to C.

## Task Tracker Reconciliation

After Phase 6:
- `RBAC-001` → DONE (B4, B5 — roles were seeded in Phase 2, now with a guard-resolved source of truth)
- `RBAC-002` → DONE (C1–C6)
- `RBAC-003` → DONE (C7, C8)
- `RBAC-004` → DONE (B1, B3)
- `RBAC-005` → DONE (E1–E6)
- `RBAC-006` → DONE (C14–C18, D1, D2, D7, E9)

New task IDs to add to `docs/planning/task-tracker.md`:
`P6-A1`..`P6-A9`, `P6-B1`..`P6-B8`, `P6-C1`..`P6-C18`, `P6-D1`..`P6-D10`,
`P6-E1`..`P6-E12` — all start `PLANNED`, move to `DONE` as completed.
