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

## Superadmin Protection

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

- ADR-004: Role-derived permissions