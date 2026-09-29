# Roles & Permissions

## Strategy

Use a mature package such as Spatie Permission rather than implementing RBAC from scratch.

## Permission Model

```
User → Role → Permissions
```

- Role permission changes must automatically affect all users assigned to that role.
- Do not perform unnecessary physical permission synchronization into every user.
- When a user's role changes, effective permissions immediately reflect the new role (derived/effective authorization).

## Seeded Roles

| Role | Permissions in `role_has_permissions` | Description |
|------|-------------|-------------|
| `superadmin` | **none** — access from `Gate::before` | System administrator |
| `admin` | the entire catalogue | Project administrator (delegated superadmin) |
| `user` | none | Standard user |

`superadmin` holds no permission rows on purpose. Its access comes from
`Gate::before` in `App\Providers\AuthServiceProvider`, so a permission added to
the catalogue never requires re-seeding that role, and editing a role's
permission set can never strip a superadmin of a capability.

## System Role Protection

System roles (`superadmin`, `admin`, `user`) are distinct from custom roles and carry
special protection rules.

### Protected System Operations

* System roles cannot be deleted (including by superadmin).
* System role names cannot be renamed.
* System role permissions cannot be manipulated directly (permissions are assigned
  to roles via the seeded matrix, not arbitrary user edits).
* It must never be possible to remove all capabilities from the last valid superadmin
  (guard against `role.user` only being assignable, or the last superadmin being
  reassigned to a non-superadmin role).
* `admin` and `user` roles may not be deleted, renamed, or have their system-assigned
  permissions stripped in a way that breaks baseline application operation.

### Role Assignment Restrictions

* Superadmin role assignment is restricted to the most privileged path.
* Removing the superadmin role from a user should require explicit, audited
  confirmation (particularly when that user is the last valid superadmin).
* Custom roles may not be assigned permissions that conflict with sealed system roles.

### Enforcement

* API: enforced server-side on every mutation affecting roles, permissions, or
  user-role assignments.
* UI: UI-level hiding/disabling is for UX clarity only; the backend is the security
  boundary.

### Bypass Semantics

Superadmin is a controlled privileged role:

* Bypasses where explicitly allowed (see Authorization Rules above).
* Does NOT bypass: last-superadmin deletion protection, system role
  deletion/renaming protection, sensitive-data redaction in logs, session
  revocation on password change/deactivation.

### Last Superadmin Enforcement

* Before any role-reassignment or role-deletion operation, the system must verify
  at least one other valid superadmin remains.
* This check is part of the mutation transaction.

## Superadmin Protection

(See System Role Protection above for full details.)

- Superadmin can bypass normal authorization where explicitly allowed.
- Superadmin does NOT automatically bypass every security boundary.
- Cannot delete the last valid superadmin.
- Cannot deactivate the last valid superadmin.
- Cannot accidentally remove all critical superadmin capabilities.
- Critical system role operations must be protected.

## Permission Naming Convention

```
{resource}.{action}
```

`{resource}` is the feature that exists today, which is why a permission can
only be added together with the page it guards. The seeded set:

| Resource | Permissions |
|---|---|
| `users` | `view` `create` `update` `delete` `force_delete` `restore` `activate` `deactivate` `lock` `unlock` `assign_roles` |
| `roles` | `view` `create` `update` `delete` `force_delete` `restore` `assign_permissions` |
| `permissions` | `view` |
| `settings` | `view` `manage` |

Do not add a permission name to this table by hand — `App\Support\PermissionCatalog`
is the source of truth and this table mirrors it. An earlier revision of this
document listed `roles.manage`, `permissions.assign` and
`feature_flags.manage`, none of which exist: exactly the drift a hand-typed list
produces.

## User State Permissions

```
users.activate
users.deactivate
users.lock
users.unlock
```

## Settings Permissions

```
settings.manage          (manage operational settings)
settings.view            (view settings)
```

## Management UI (Phase 6 Group A — read-only until Group C/D)

Shipped and audited 2026-09-28.

| Page | Route | Controller | Write path |
|---|---|---|---|
| Roles | `GET /roles` → `roles.index` | `Web\V1\RoleController@index` | none yet (P6-C5) |
| Create Role | `GET /roles/create` → `roles.create` | `Web\V1\RoleController@create` | none yet |
| Edit Role | `GET /roles/{role}/edit` → `roles.edit` | `Web\V1\RoleController@edit` | none yet (P6-C5) |
| Permissions | `GET /permissions` → `permissions.index` | `Web\V1\PermissionController@index` | **none by design** |

The roles index carries a search filter and sortable Name / Users / Permissions
headers, laid out like `pages/users/index`: the filter form sits in the card body
above the table, the Create Role button right-aligned in the card header.
Sorting is whitelisted in the controller (`RoleController::SORTABLE`) because
the value reaches `orderBy`.

The permissions catalogue is deliberately read-only: a permission row that no
`can()` call references grants nothing, so a UI that creates one only makes it
look real. Permissions are seeded from the catalogue (P6-B1/P6-B3).

**These four routes still carry no `can:` gate.** They shipped in Group A before
the permissions existed, and a gate on a non-existent permission denies everyone
— superadmin included. **Group B seeded the catalogue, so the blocker is gone
and P6-D1 can now gate them** with `can:roles.view` / `can:roles.create` /
`can:roles.update` / `can:roles.delete` / `can:permissions.view`. Until it does,
any authenticated user can reach these four pages. That window is tracked, not
an oversight.

**No save path yet.** The create/edit forms post to `roles.index` and the delete
trigger posts to `roles.index`; both land on real endpoints at P6-C5 / P6-C6.

**System roles** (`superadmin`, `admin`, `user`) are answered by
`App\Support\SystemRole` — the single place that knows the list. The views read
`$role->is_system`, which the controller decorates; a system role shows a
`System` badge in place of its delete trigger, and its name field is readonly on
the edit form. The server-side refusal lands at P6-C6/P6-E1 — the UI is UX only
per [UI Authorization Rule](../ui/ui-authorization.md).

**Not built, and why** (proposed 2026-09-28, declined):

- *"Make default role" action* — `registration_default_role` already lives in
  `SystemSetting`. The guard belongs in `SystemSettingRequest`, not a second
  writer for one value.

## Role Lifecycle — Trash, Revocation, Restore

Trashing a role is a **revocation**, not a rename. The row survives; the access
does not.

| | Effect |
|---|---|
| `DELETE /roles/{role}` | soft-deletes the role row, **detaches it from every user holding it**, writes `role.deleted` with `revoked_users` + `revoked_permissions` |
| `POST /roles/{role}/restore` | restores the row **with its `role_has_permissions` intact**, re-assigns nobody, writes `role.restored` |
| `DELETE /roles/{role}/force` | hard-deletes a **trashed** role only, writes `role.force_deleted` |

### Two mechanisms, both required

1. **Write side — explicit detach.** `DeleteRoleAction` calls
   `$role->users()->detach()` before `$role->delete()`. Spatie's own `deleting`
   hook *skips* detach on a non-force delete (`HasRoles::bootHasRoles` returns
   early when `isForceDeleting()` is false), so a plain soft delete would leave
   the `model_has_roles` pivot rows intact and a later restore would silently
   re-grant the role to everyone who had it.
2. **Read side — the global scope.** `App\Models\Role` uses `SoftDeletes`, so
   Spatie resolves roles through a model whose global scope hides the trashed
   row. `$user->roles`, `hasRole()` and every `can()` return "no" with no change
   at the call sites. `RoleLookup::find()` and `assignable()` therefore never
   offer a trashed role.

Each is tested separately (`RoleManagementTest`) so a refactor cannot trade one
mechanism for the other and leave the other half broken.

### Deliberate consequences

- **The permission set is kept; the assignment is not.** Restoring returns the
  role's `role_has_permissions` exactly as they were, so reassigning is a single
  deliberate act. A restore that silently re-granted access to a dozen accounts
  would be indistinguishable, to an auditor, from the compromise it undoes.
- **The name stays reserved while trashed.** The unique index is on
  `(name, guard_name)` and a soft-deleted row still occupies it, so
  `Rule::unique('roles', 'name')` keeps rejecting a second role of that name.
  That is what makes a restore collision *impossible* rather than merely
  unlikely — pinned by `test_a_trashed_role_name_stays_reserved` so nobody
  "helpfully" adds `whereNull('deleted_at')` and opens the hole.
- **A populated role needs `force`.** The web `destroy` refuses while users
  still hold the role, because trashing it deassigns all of them. The action's
  `force: true` is the deliberate override; either way the revocation is counted
  in the audit row.
- **A trashed role cannot be edited** — the `{role}` route binding resolves
  through the global scope and 404s. The view offers no Edit link for the same
  reason.
- **No default-role fallback.** A user who loses a role keeps whatever else they
  hold. Inventing a fallback here would be a second writer for
  `registration_default_role` (`SystemSetting`) and would silently grant access
  nobody asked for.
- **Restore and force-delete have their own permissions** (`roles.restore`,
  `roles.force_delete`), not `roles.update` / `roles.delete`: a restore brings
  back a whole permission set, and a force delete destroys the audit subject.
  Neither is "editing a role".

The trash lives on the roles index as a second tab (`?trashed=1`) with its own
count, mirroring `pages/users/index`. `Route::post`/`Route::delete` take the raw
id rather than an implicit `{role}` binding — that binding resolves through the
global scope and would 404 every trashed row these routes exist for.

## Seeding Strategy

`Database\Seeders\RoleSeeder` creates the three system roles;
`Database\Seeders\PermissionSeeder` owns the permission catalogue and the role
matrix. `DatabaseSeeder` runs them in that order, then `SuperAdminSeeder`.

**The catalogue is seeded in full**, across the four resources that exist today
(`users.*`, `roles.*`, `permissions.view`, `settings.*`). Read the count from
`count(PermissionCatalog::all())` rather than this sentence — a hand-written
number here is the drift the paragraph below warns about, and it has already
been wrong once.

`audit.*` and `features.*` are **not seeded**. The audit viewer is Phase 10 and
feature flags are Phase 7; neither has a route, controller or view, so a
permission for them would be a row in the permissions UI that looks meaningful
and grants nothing. Each group arrives in the same commit as the page it guards.
`PermissionSeedTest::every_permission_maps_to_a_resource_the_app_actually_has`
fails the build if a group is added without a feature behind it.

### `App\Support\PermissionCatalog` is the single source of truth

`all()` returns every name, `grouped()` groups by the `Str::before($name, '.')`
prefix, `forResource()` returns one resource. The seeder, the role permission
matrix, and the tests all read it — no permission list is hand-typed anywhere,
including in Blade. The prefix is derived, never listed, so a permission cannot
land under the wrong heading.

### Re-seeding is idempotent, and prunes

`PermissionSeeder` flushes the permission cache three times: before seeding
(a cached registrar makes it write a second real role), after creating and
before assigning (`syncPermissions` reads the cache), and at the end.

It also **deletes** rows on the app's guard that `all()` no longer declares.
`firstOrCreate` alone only ever adds, which left a removed permission in the
database permanently — still listed in the permissions UI, still granting `can()`
to whoever held it. Scoped to `RoleLookup::guard()`, so another guard's
catalogue is untouched.

**Note on Spatie Permission `guard_name`:** resolved via
`App\Models\RoleLookup::guard()` — `Guard::getDefaultName(User::class)`, the
resolver Spatie itself uses — and never `config('auth.defaults.guard')`, which is
mutated per request by `Sanctum::actingAs()` and disagreed with the permission
checks the roles feed.

## ADR References

- ADR-004: Role-derived permissions
- ADR-008: System-role protection and superadmin bypass semantics