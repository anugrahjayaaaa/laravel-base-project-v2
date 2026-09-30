# Roles & Permissions

> **Status: 🟡 IN PROGRESS (Phase 6).** Implemented and audited: Group A (UI),
> Group B (permission catalogue + seeders + `Gate::before`), Group C1 (role
> management web — CRUD, soft delete with revocation, trash tab, restore,
> permanent delete, bulk actions), Group C2 (permission catalogue, read-only),
> Group C3 (role assignment sync — the brief's core case), Group C4 (the ungated
> `authorize()` returns, including the `BulkRoleRequest` blanket `true` that
> arrived with C1's bulk work). **Gate C is met** — 505 tests / 1657 assertions.
> Not yet implemented: D (route / menu / button `can:` gates — the seven ungated
> user state routes and `api.v1.settings.index` are tracked as P6C4-001..005),
> E (system-role protection tests, the 403 matrix, API pentest replay, docs).
> See `docs/planning/phase-6-rbac.md` § C4 and `docs/planning/progress.md`.

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

* Before any role reassignment, `AssignRolesAction` counts the superadmins before
  and after the sync and refuses with `LastSuperadminException` if the result
  would be zero. The check runs inside the same transaction as the sync.
* A refused reassignment renders as a redirect-back with an `error` flash on the
  web, and as `409 Conflict` on the API.
* Granting the `superadmin` role additionally requires the acting user to already
  be a superadmin. `users.assign_roles` alone is not enough — it can edit any
  ordinary role, so on its own it would let a delegated admin mint a superadmin.
* This is a **minimum of one**, not exactly one. A second superadmin can still be
  created by an existing superadmin.
* The check guards reassignment only. A superadmin account can still be
  soft-deleted, and the role can be trashed, provided the last superadmin keeps
  its own access.

## Superadmin Protection

(See System Role Protection above for full details.)

- Superadmin can bypass normal authorization where explicitly allowed.
- Superadmin does NOT automatically bypass every security boundary.
- Cannot delete the last valid superadmin.
- Cannot deactivate the last valid superadmin.
- Cannot accidentally remove all critical superadmin capabilities.
- Critical system role operations must be protected.

## Superadmin Visibility

The `superadmin` role, and the accounts holding it, are visible only to a user
who is already a superadmin. This is presentation, layered on top of the
authorization rules above — hiding a control is not what prevents the action, and
the API paths are guarded independently.

`RoleLookup::viewerIsSuperAdmin()` is the single decision. `RoleLookup::visibleTo()`
applies it to the role list, and the two index actions apply the same predicate to
their own queries — an Eloquent relation filter cannot be expressed by the
role-name query, so the predicate is shared while the queries stay separate:

- the role picker on the user create/edit forms — `visibleTo()`
- the registration-default-role dropdown on the settings page — `visibleTo()`
- the roles index, and its tab counts — inline in `IndexRoleAction`
- the users index, and its tab counts — inline in `UserIndexAction`

The counts follow the rows on purpose. A badge reading "4" above three rows tells
an observer something was removed, so the users index keeps two cached count
keys — one per audience — busted together from the user observer and the bulk
handler.

`RoleLookup::assignable()` is deliberately **not** viewer-filtered. It answers
"which roles are on this guard", which settings validation and the guard tests
depend on; making it viewer-dependent would silently change what those accept.

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

## Management UI (Phase 6 Groups A + C1/C2 — full CRUD since 2026-09-29)

Shipped and audited 2026-09-28 (Group A reads), extended 2026-09-29/30.

| Page | Route | Controller | Write path |
|---|---|---|---|
| Roles | `GET /roles` → `roles.index` | `Web\V1\RoleController@index` | — (read; `can:roles.view`) |
| Create Role | `GET /roles/create` → `roles.create` | `Web\V1\RoleController@create` | `POST /roles` → `roles.store` (`StoreRoleRequest`) |
| Edit Role | `GET /roles/{role}/edit` → `roles.edit` | `Web\V1\RoleController@edit` | `PUT /roles/{role}` → `roles.update` (`UpdateRoleRequest`) |
| — delete | — | `RoleController@destroy` | `DELETE /roles/{role}` → `roles.destroy` (`DeleteRoleRequest`) |
| — trash / restore / force | — | `RoleController@restore` / `@forceDelete` | `POST /roles/{id}/restore`, `DELETE /roles/{id}/force` |
| — bulk | — | `RoleController@bulkAction` | `POST /roles/bulk-action` (`BulkRoleRequest`) |
| Permissions | `GET /permissions` → `permissions.index` | `Web\V1\PermissionController@index` | **none by design** |

The roles index carries a search filter and sortable Name / Users / Permissions
headers, laid out like `pages/users/index`: the filter form sits in the card body
above the table, the Create Role button right-aligned in the card header.
Sorting is whitelisted in the controller (`RoleController::SORTABLE`) because
the value reaches `orderBy`.

The permission catalogue is deliberately read-only: a permission row that no
`can()` call references grants nothing, so a UI that creates one only makes it
look real. Permissions are seeded from the catalogue (P6-B1/P6-B3).

**The four read routes are now gated.** They shipped in Group A before the
permissions existed, and a gate on a non-existent permission denies everyone —
superadmin included. Group B seeded the catalogue, so the blocker was gone and
Group C2 closed them with `can:roles.view` / `can:roles.create` /
`can:roles.update` / `can:permissions.view`. The five **write** routes still
carry no `can:` on the route itself, but each is gated by its own Form Request
on the same `roles.*` permission, so a zero-permission user is refused either
way — `RoleManagementTest` asserts 403 on all five. Route-level gates on the
writes are defence in depth and land at P6-D1.

**Save paths exist.** Group C1 gave the create/edit forms and the delete trigger
real endpoints (`roles.store`, `roles.update`, `roles.destroy`) in place of the
`roles.index` placeholders Group A left behind.

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
- **A populated role needs `force`.** `DeleteRoleAction` refuses while users
  still hold the role, because trashing it deassigns all of them. Both the web
  `destroy` and the bulk bar pass `force: true` — the confirm modal in front of
  them spells the consequence out ("removed from every user holding it") before
  the admin confirms, so the modal *is* the deliberate override. The guard still
  protects the callers that have no modal: the API and the console, which pass
  `force` explicitly. Either way the revocation is counted in the audit row.
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

Both tabs badge their count pill, as `pages/users/index` does: a bare label
beside a badged one reads as a different component, which is the whole of what
"the Trash tab looks different" was.

## Bulk Actions

`POST /roles/bulk-action` applies one action to many roles. It reuses the whole
users bulk path rather than a second implementation:

- `RoleBulkActionHandler` implements the same `BulkActionHandler` interface
  `UserBulkActionHandler` does, so `BulkActionProcessor` drives both unchanged.
- `BulkRoleRequest` mirrors `BulkUserRequest`; the id lookup is
  `Role::withTrashed()` because the trash tab submits already-deleted ids.
- `resources/js/helpers/bulk-actions.js` is shared. It reads the field name,
  noun, per-state action map, and action-config key mapping from `data-*` on
  `#bulkBar`, defaulting to the user values — so the roles page gets correct
  behaviour and correct modal copy ("selected role(s)", `delete_role` wording)
  without a second JS file to keep in sync.

`getValidItems()` is the security boundary, not the dropdown: it excludes system
roles from every action and excludes live roles from restore / force-delete, so
a hand-posted id cannot trash `admin`. The view simply renders no checkbox for a
system role, which is convenience rather than enforcement.

Unlike `UserController::bulkAction`, this path writes **no** aggregate audit
row. Each role action already logs its own `role.deleted` row carrying
`revoked_users` and `revoked_permissions`; a bulk row would repeat the subjects
without adding the properties that make the log useful.

**Not implemented:** bulk action on the API. The web route exists; `POST
/api/v1/roles/bulk-action` does not. Add it when a client needs it — the handler
is already the reusable half.

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