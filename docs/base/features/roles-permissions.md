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

| Role | Permissions | Description |
|------|-------------|-------------|
| `superadmin` | All (with protected exceptions) | System administrator |
| `admin` | Manage users, audit, settings | Project administrator |
| `user` | Default access | Standard user |

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

Examples:
users.view
users.create
users.update
users.delete
users.activate
users.deactivate
users.lock
users.unlock
roles.view
roles.manage
permissions.assign
settings.manage
audit.view
audit.export
feature_flags.manage
```

## User State Permissions

```
users.activate
users.deactivate
users.lock
users.unlock
```

## Feature Permissions

```
features.manage          (manage feature flags)
features.view            (view feature flags)
```

## Settings Permissions

```
settings.manage          (manage operational settings)
settings.view            (view settings)
```

## Audit Permissions

```
audit.view               (view audit records)
audit.export             (export audit records)
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

**These four routes carry no `can:` gate yet.** The permission rows do not exist
until P6-B1 seeds them, and a gate on a non-existent permission denies everyone
— superadmin included. P6-D1 wraps them in `can:roles.view` / `can:roles.create`
/ `can:roles.update` / `can:roles.delete` / `can:permissions.view`. Until then,
any authenticated user can reach these four pages. That window is Group A's
known, tracked gap, not an oversight.

**No save path yet.** The create/edit forms post to `roles.index` and the delete
trigger posts to `roles.index`; both land on real endpoints at P6-C5 / P6-C6.

**System roles** (`superadmin`, `admin`, `user`) are answered by
`App\Support\SystemRole` — the single place that knows the list. The views read
`$role->is_system`, which the controller decorates; a system role shows a
`System` badge in place of its delete trigger, and its name field is readonly on
the edit form. The server-side refusal lands at P6-C6/P6-E1 — the UI is UX only
per [UI Authorization Rule](../ui/ui-authorization.md).

**Not built, and why** (proposed 2026-09-28, declined):

- *Active / Inactive / Trash tabs* — need `is_active` + `deleted_at` on `roles`.
  Soft delete forces a `Role` subclass and a 3-place change
  (`config/permission.php`, seeder import, observer) whose failure is silent, and
  `findByName()` / `getStoredRole()` would then throw `RoleDoesNotExist` for
  users still holding a trashed role. Revisit alongside the subclass, not before.
- *"Make default role" action* — `registration_default_role` already lives in
  `SystemSetting`. The guard belongs in `SystemSettingRequest`, not a second
  writer for one value.
- *Deactivate / soft-delete buttons* — no endpoint exists yet; the confirm-modal
  copy does, and `action-type="delete_role"` goes live at P6-C6.

## Seeding Strategy

System roles (`superadmin`, `admin`, `user`) are seeded in Phase 2 via
`Database\Seeders\RoleSeeder`. Permissions are assigned in Phase 6 (RBAC-004)
once the permission set is defined.

**Note on Spatie Permission `guard_name`:** The architecture supports both
`web` (session/Sanctum SPA) and `api` (Sanctum bearer token) authentication
guards, both backed by the same `User` model. The specific `guard_name` value
assigned to seeded roles is an implementation decision to be resolved during
the RBAC implementation phase (Phase 6), based on whether roles must be
shared across both guards or scoped per guard. See
`docs/base/security/authentication.md` §Authentication Methods for the guard
model.

## ADR References

- ADR-004: Role-derived permissions
- ADR-008: System-role protection and superadmin bypass semantics