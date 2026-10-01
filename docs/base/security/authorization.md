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
  application function. `RoleDeleteAction::validate()` refuses all three,
  including for a superadmin caller; `RoleForceDeleteAction` refuses a
  trashed system role the same way.
- **Renaming**: system roles cannot be renamed — permission references and
  seed data depend on stable names. `RoleUpdateAction::guardAgainstSystemRename()`
  compares the input against the **stored** name, so `admin` cannot be renamed
  to anything and an ordinary role cannot be renamed *into* a system name.
- **Permission manipulation**: a system role's permission set is **code-defined**
  and cannot be edited at all. `PersistsRole::persist()` refuses any
  `permissions` key aimed at a `SystemRole` name — without it, submitting the
  matrix with no checkboxes ran `syncPermissions([])` and silently emptied the
  role, with an audit row recording a successful save. For `admin` (granted the
  whole catalogue) that was one POST from removing the delegated superadmin's
  access; for `superadmin` it was moot, because that role deliberately holds
  **zero** permission rows and its access comes from `Gate::before` — see
  `PermissionSeeder::matrix()`.
- **API enforcement**: system role protection is enforced at the application
  boundary (Action/Service layer), not just in the UI.
- **UI restrictions**: system role management UI is restricted to users with
  `roles.manage` permission; system role rows are not deletable in the UI.

## Superadmin Integrity (P6-E4 / P6-E5)

Two separate invariants, because they answer different questions. `Gate::before`
answers *authority*; these answer *consequence*.

### The last superadmin cannot be removed by any route

A superadmin can disappear four ways, and only the first was originally
guarded. `LastSuperadmin::guard()` now runs wherever an account's ability to
log in actually changes:

| Path | Guarded by |
|------|-----------|
| Role stripped via `PUT /users/{id}` | `RoleAssignAction::guardLastSuperadmin()` |
| `POST /users/{id}/deactivate` | `UserDeactivateAction` → `LastSuperadmin::guard()` |
| `DELETE /users/{id}` | `UserDeleteAction` → `LastSuperadmin::guard()` |
| `POST /users/bulk-action` (`deactivate`) | routes through `UserDeactivateAction`, so it inherits the guard |

This was a live hole, not a hypothetical: a caller holding `users.deactivate` +
`users.delete` + `users.force_delete` — and not being a superadmin — could take
the only superadmin to zero. The bulk path was a second door, because it ran a
raw `UPDATE` that bypassed `UserDeactivateAction` entirely, so the guard the row
button enforced was absent from the dropdown one screen above.

`UserForceDeleteAction` deliberately has **no** such guard: it only accepts an
already-trashed user, and `UserDeleteAction` already refuses to create that
state for the last superadmin. The reasoning is recorded at the call site.

The count is about **active** superadmins — the accounts that can administer
right now. A trashed or deactivated superadmin does not count toward it, so
the guard cannot be satisfied by a dormant row.

Refusals raise `LastSuperadminException`, rendered by `bootstrap/app.php` as
**409 `LAST_SUPERADMIN`** for JSON and a redirect carrying an `error` flash for
web — not a validation error bag.

### Granting or removing superadmin requires explicit confirmation

`RoleAssignAction::guardSuperadminChange()` requires an explicit
`confirm_superadmin` flag whenever a sync would **add or remove** the
superadmin role. The existing guards answer *who may* do it (superadmin only)
and *whether it would strand the app*; neither answers whether the caller
**intended** it, and intent is what a mis-clicked checkbox or a stray `roles`
key in an unrelated form post gets wrong. Removal is included because a demotion
is how an account quietly loses the ability to undo whatever demoted it.

The requirement fires only on an actual **change** — resubmitting `superadmin`
for a user who already holds it needs no flag, or every unrelated edit to a
superadmin's own form would be blocked. The control lives in
`partials/user-role-picker.blade.php` and is rendered only for a caller holding
`users.assign_roles`, so the guard cannot make superadmin unassignable through
the UI while also not leaking the field to callers who cannot use it.

Note the flag is **not** a substitute for authority: a delegated admin holding
`users.assign_roles` is still refused, with or without `confirm_superadmin`.

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