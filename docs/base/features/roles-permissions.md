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

## ADR References

- ADR-002: Role/permission infrastructure (Spatie)
- ADR-004: Role-derived permissions
- ADR-008: System-role protection and superadmin bypass semantics