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
| `roles.assign_permissions` | **the permission checkbox matrix on a role form** |

### permissions (catalogue)
| Permission | Guards |
|---|---|
| `permissions.view` | permissions index (read-only) |

### settings / audit / features
| Permission | Guards |
|---|---|
| `settings.view` | settings page read |
| `settings.manage` | settings page write (POST/PUT) |
| `audit.view` | activity logs read (Phase 10) |
| `audit.export` | activity log export (Phase 10) |
| `features.view` | feature flag page read (Phase 7) |
| `features.manage` | feature flag write (Phase 7) |

### Seeded role matrix
| Role | Permissions |
|---|---|
| `superadmin` | Gate bypass (not enumerated in DB — see below) |
| `admin` | `users.*` (all 11) + `settings.view` + `settings.manage` + `audit.view` |
| `user` | **none** — baseline is profile + sessions only, which need no permission |

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

**Gate A:** all six views render, `php artisan view:clear` clean, no query
inside any view, `RbacUiRenderTest` green. Only then start Group B.

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

## Group C — Controllers, Actions, Form Requests

> Goal: the role/permission CRUD endpoints exist and are authorized, and role
> assignment on the user form **syncs**. This is where the sync bug in the
> brief lives.
> Depends: Group B (permissions must exist to be granted). Blocks: D (menu
> gating reads the same permissions), E.

### C1 — Role management (Web)
| ID | Task | Depends | Est. |
|----|------|---------|------|
| P6-C1 | `App\Http\Requests\Role\StoreRoleRequest` — `authorize(): return $this->user()?->can('roles.create') ?? false`; rules: `name` required/string/max:64/`Rule::unique('roles','name')` **scoped to the resolved guard**; `permissions` nullable array; `permissions.*` `integer` + `exists:permissions,id` | B | small |
| P6-C2 | `App\Http\Requests\Role\UpdateRoleRequest` — `authorize(): can('roles.update')`; `name` unique ignoring `$role`; `permissions.*` as above | B, C1 | small |
| P6-C3 | `App\Actions\V1\Role\RoleIndexAction` — `Role::query()->where('guard_name', RoleLookup::guard())->withCount(['permissions','users' …])`, optional `search` filter applied **before** `paginate(10)`, `->withQueryString()`. No N+1 | B4 | small |
| P6-C4 | `App\Actions\V1\Role\SaveRoleAction` — one action for create+update. `DB::transaction`: reject system-role rename (C8), `syncPermissions(array_map('intval', $data['permissions'] ?? []))` — the `intval` is required, spatie resolves a **string** `'19'` via `findByName` and throws `PermissionDoesNotExist`; audit `role.created` / `role.updated` **inside** the transaction before commit (ADR: no audit record on rollback) | B | medium |
| P6-C5 | `App\Http\Controllers\Web\V1\RoleController` — thin. `index` → `$action->run()` → `view('pages.roles.index')`; `create`/`edit` → `Permission::orderBy('name')->get()` + `PermissionCatalog::grouped()`; `store`/`update` → `SaveRoleAction`; `destroy` → deny for system roles, else delete + audit | C1–C4 | medium |
| P6-C6 | `App\Actions\V1\Role\DeleteRoleAction` — refuse when `SystemRole::isSystem($role->name)`; refuse when the role still has users assigned (409 with a message naming the count) unless `force` is passed; delete inside a transaction + audit | B4 | small |

### C2 — Permission catalogue (Web, read-only)
| ID | Task | Depends | Est. |
|----|------|---------|------|
| P6-C7 | `App\Actions\V1\Permission\PermissionIndexAction` — `Permission::where('guard_name', RoleLookup::guard())->withCount('roles')->orderBy('name')->get()`, grouped by prefix for the view | B3 | tiny |
| P6-C8 | `App\Http\Controllers\Web\V1\PermissionController` — `index()` only, guarded by `can('permissions.view')`. **No store/update/destroy** — permissions are code-defined and seeded. A UI that creates a permission row nobody's `can()` call references is a trap | C7 | tiny |

### C3 — Role assignment sync (the brief's core case)
| ID | Task | Depends | Est. |
|----|------|---------|------|
| P6-C9 | `UpdateUserAction` — roles branch is already `array_key_exists`-guarded (`:59`). **Do not change it.** Add one thing: a `users.assign_roles` check before `syncRoles`, and reject a payload that would strip `superadmin` from the last superadmin (C11) | C5 | small |
| P6-C10 | `CreateUserAction` — roles come from `RoleLookup::find()` already (`:62`). Add the same `users.assign_roles` check on the admin path only; the self-registration path (`defaultRolesForSelfRegistration()`) is **not** permission-gated because it is not an admin action and its role is server-side, not client-supplied | C5 | small |
| P6-C11 | `App\Actions\V1\Role\AssignRolesAction` — one place that both C9 and C10 call: `DB::transaction`, resolve names through `RoleLookup::find()` (skip unknown rather than throw — `CreateUserAction` already silently skips, and a hard failure on a stale form is worse), count superadmins before/after, throw `LastSuperadminException` if the result is zero, `syncRoles()`, audit `user.roles_assigned` with the before/after lists in `properties` | B4 | medium |
| P6-C12 | `App\Exceptions\LastSuperadminException` + renderable in `bootstrap/app.php` → web redirect with `error` flash, API 409 JSON | C11 | small |
| P6-C13 | `App\Http\Requests\User\AssignRolesRequest` — `authorize(): can('users.assign_roles')`; `roles` array; `roles.*` `string` + `Rule::exists('roles','name')` **scoped to the resolved guard** (today's rule at `CreateUserRequest.php:27` is unscoped, which is why `RoleGuardTest` exists) | C5 | small |

### C4 — Fix the ungated `authorize()` returns
| ID | Task | Depends | Est. |
|----|------|---------|------|
| P6-C14 | `CreateUserRequest::authorize()` → `can('users.create')` **on the admin path only**. A self-registering user has no permission, so the register controller must not reuse this request. `RegisterRequest` is the separate public contract and stays `true` | C5 | small |
| P6-C15 | `UpdateUserRequest::authorize()` → `can('users.update')`, plus `$this->user()->id === $user->id` **only** when the target is the caller and the payload has no `roles` key (self-profile edit through the admin form) | C5 | small |
| P6-C16 | `SystemSettingRequest::authorize()` → `can('settings.manage')` | C5 | small |
| P6-C17 | `BulkUserRequest::authorize()` → map the requested `action` to its permission (`delete`→`users.delete`, `lock`→`users.lock`, …) instead of one blanket check | C5 | medium |
| P6-C18 | `UserPolicy` — add `viewAny`, `view`, `create`, `update`, `delete`, `forceDelete`, `restore` methods delegating to the matching `users.*` permission, keeping the four existing state methods **exactly as they are** (their 409 preconditions are business rules, not authorization) | C5 | small |

**Gate C:** every role/permission route reachable and authorized; a
zero-permission user gets 403 on all of them; role assignment syncs (add →
grants, replace → swaps, empty array → clears, absent key → untouched);
last-superadmin cannot be stripped. `UserCrudWebTest` +
`UserRoleEditTest` still green — they call these endpoints and must be updated
to the new authorization, not deleted.

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
| P6-E10 | Performance check — `PermissionCatalog::all()` and the matrix view add **zero** queries per row (`assertQueryCount` or Telescope-free manual count): roles index uses `withCount`, permissions index uses `withCount('roles')`, the sidebar composer makes at most one permission-cache read per request (package cache is on; `Gate::before` does `hasRole` on an already-loaded relation, not a fresh query). Record the numbers in the phase report | C, D | small |
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
