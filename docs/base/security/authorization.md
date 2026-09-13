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