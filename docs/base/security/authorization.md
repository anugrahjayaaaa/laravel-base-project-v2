# Authorization

## RBAC

Use a mature package such as Spatie Permission rather than implementing RBAC from scratch.

### Permission Model

```
User → Role → Permissions
```

- Role permission changes must automatically affect all users assigned to that role.
- Do not perform unnecessary physical permission synchronization into every user.
- When a user's role changes, effective permissions immediately reflect the new role (derived/effective authorization).

## Superadmin

Create a protected Superadmin role:
- Can bypass normal authorization rules where explicitly allowed.
- Does NOT automatically bypass every security boundary.
- Document explicit exceptions.

Protection rules:
- Cannot delete the last valid superadmin
- Cannot deactivate the last valid superadmin
- Cannot accidentally remove all critical superadmin capabilities
- Critical system role operations must be protected

### What Superadmin Bypasses vs. Does NOT Bypass

| Boundary | Superadmin Can Bypass? | Notes |
|----------|------------------------|-------|
| Permission checks (general) | Yes, where explicitly allowed via `Gate::before` | E.g. view any user record, manage roles |
| Account state changes (lock/deactivate last superadmin) | No | Protected regardless of role |
| Password change | No | Must provide current password |
| Audit Trail view/export | No (requires `audit.view`/`audit.export`) | Audit is for accountability |
| Feature availability | No | Feature flags apply to everyone |
| System role protection | No | Cannot delete/rename system roles |
| Settings management | Requires `settings.manage` | Not auto-bypassed |

Superadmin is a **controlled privileged role**, not an uncontrolled "everything
bypass" concept.

## System Role Protection

System roles (`superadmin`, `admin`, `user`) are protected:

- **Deletion**: system roles cannot be deleted — they are required for
  application function.
- **Renaming**: system roles cannot be renamed — permission references and
  seed data depend on stable names.
- **Permission manipulation**: system role permissions are managed through
  controlled admin operations, not arbitrary bulk assignment.
- **API enforcement**: system role protection is enforced at the application
  boundary (Action/Service layer), not just in the UI.
- **UI restrictions**: system role management UI is restricted to users with
  `roles.manage` permission; system role rows are not deletable in the UI.

## User State Permissions

```
users.activate
users.deactivate
users.lock
users.unlock
```

## Feature Availability

Three conceptual layers:
```
Authentication → Authorization → Feature Availability
```

- Permission answers: "WHO can use this?"
- Feature availability answers: "IS this capability available?"
- Feature availability must be enforced at the backend/application boundary.
- UI hiding is not security.
- Feature management must only be accessible to users with appropriate permission.

## Single Source of Truth

Role permissions are effective permissions derived from the user's role. Do NOT physically copy all role permissions into every user unless a future requirement explicitly requires it.

## ADR References

- ADR-004: Role-derived permissions