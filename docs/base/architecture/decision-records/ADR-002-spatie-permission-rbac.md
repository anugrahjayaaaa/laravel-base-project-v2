# ADR-002: Spatie Permission for RBAC

- **Status**: Accepted
- **Category**: Dependency Selection
- **Architecture Area**: Authorization (Phase 6 `RBAC-001`, Phase 1 `FOUND-005`)

## Context

The Base Project requires Role-Based Access Control (RBAC): users, roles,
permissions, and role-permission relationships. The permission model must
support role-derived effective permissions (changes to a role's permissions
automatically affect all assigned users).

## Decision

Use `spatie/laravel-permission` for RBAC, integrated through Laravel's
native Gate and Policy system.

## Alternatives Considered

- **Breeze / Jetstream built-in auth**: Provides authentication scaffolding,
  not RBAC. No role/permission model.
- **Custom RBAC**: Role/permission tables, permission caching, and
  role-derivation logic are non-trivial to implement correctly (cache
  invalidation, race conditions, morphMap support). Reinventing this is
  error-prone.
- **Laravel's native Gate/Policy with enums**: Provides authorization gates
  but not a persistent role/permission store with runtime assignment.
  Insufficient for a configurable RBAC system.

## Why This Decision

Spatie Permission provides:
- A mature, well-tested role/permission Eloquent schema
- Built-in permission caching with invalidation hooks
- Automatic compatibility with Laravel's `Gate` and `Policy` system
- Role hierarchy and `HasRoles`/`HasPermissions` traits
- Community-wide adoption — well-understood upgrade paths

## Consequences

- `roles`, `permissions`, `model_has_roles`, `model_has_permissions`,
  `role_has_permissions` tables created at Phase 1.
- Application code uses `$user->can('permission.name')` and
  `authorize()` in Form Requests — routing through Laravel's native
  Gate, which delegates to Spatie's traits.
- The role is the **source of truth** for effective permissions. Permissions
  are derived from the user's role at request time — they are NOT physically
  copied into each user record (ADR-004).

## Security Implications

- Permission caching must be invalidated on role/permission changes.
- Superadmin bypass is explicit (via `Gate::before`), not blanket.
- Permission names are seeded — never hardcoded in views/controllers.
- Menu visibility checks in the UI are a UX convenience, NOT a security
  boundary — backend authorization always enforces.

## Maintenance Implications

- Spatie Permission 6.x targets Laravel 10-13 + PHP 8.2+.
- Cache invalidation hooks must be maintained in role/permission Actions.
- Schema migrations are versioned — review before major upgrades.

## Reversal / Replacement

- Replace `HasRoles`/`HasPermissions` traits on the User model with a
  custom implementation.
- Reimplement `getUserPermissions()`, `hasRole()`, and `Gate` resolution.
- Laravel's native Gate/Policy layer isolates most application code.
