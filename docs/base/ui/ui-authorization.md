# UI Authorization Rule

## Core Rule

**Permission checks in the UI are NOT a security boundary.**

The UI may hide, disable, or prevent access to features based on
permission, but the **backend must always enforce authorization**
independently.

## Why

- UI hiding can be bypassed (direct API calls, dev tools, modified requests).
- The API/backend is the only enforceable security boundary.
- UI permission checks exist for UX/visibility — not security.

## Examples

### Menu Visibility

A user without `user.delete` permission must not see the "Delete User"
button in the UI.

But if the user manually calls:

```
DELETE /api/v1/users/{id}
```

the backend must still reject the request with `403 Forbidden`.

### Menu Items

A user without `audit.view` permission must not see the "Audit Trail" menu
item.

But navigating directly to `/audit` or calling the API endpoint must still
return `403`.

### Feature Availability

A feature flag may be OFF for all users — the UI hides the menu item.

But calling the endpoint directly must return `404` or
`FEATURE_UNAVAILABLE`, not bypass the flag.

## Implementation

| Layer | Responsibility |
|-------|----------------|
| UI | Hide/disable elements based on permission (UX convenience) |
| Backend (Policy/Gate) | Enforce authorization on every request (security boundary) |
| Backend (Feature flag) | Enforce feature availability on every request |

The UI reads permission/availability from the authenticated user's
effective permissions (via API or a permissions endpoint), but **never
trusts** the client-side state for security decisions.

## What Must Be Enforced Server-Side

- Menu visibility → UX only, not security
- Button visibility → UX only, not security
- Routes/pages → backend must 403/404 if unauthorized/unavailable
- API endpoints → backend must 403/401 if unauthorized
- Feature availability → backend must 404 if feature disabled

## ADR References

- ADR-001: API-first architecture
- ADR-002: UI-independent core
- ADR-004: Role-derived permissions

## Related

- [UI Architecture](./ui-architecture.md)
- [Feature Availability](../features/feature-flags.md)
- [Authorization](../security/authorization.md)