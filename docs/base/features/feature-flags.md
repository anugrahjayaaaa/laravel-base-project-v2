# Feature Availability

## Concept

Three conceptual layers:
```
Authentication → Authorization → Feature Availability
```

- **Permission answers**: "WHO can use this?"
- **Feature availability answers**: "IS this capability available?"

## Enforcement

- Feature availability must be enforced at the backend/application boundary.
- UI hiding is NOT security.

## Feature Flag Package Foundation (Phase 1)

Phase 1 includes the **foundation** for a feature-flag system:

- Install and configure an appropriate feature-flag package.
- Define reusable UI conventions for feature flags in the AdminLTE shell.
- Document the feature availability enforcement pattern.

Actual feature-specific flags are NOT implemented in Phase 1 — they are added
by future feature phases when a feature requires one.

## Use Cases

Feature availability controls:
- Module enable/disable
- Beta feature rollout
- Maintenance mode
- Registration enable/disable
- Password policies configuration
- Any capability that can be toggled on/off

## Implementation

Use feature flags (see [feature-flags.md](./feature-flags.md)).

Feature availability is separate from authorization:
- A feature may be available but the user lacks permission → 403 Forbidden.
- A feature may be unavailable even with permission → 404 or feature disabled response.

## Permission Integration

- Feature management itself must only be accessible to users with appropriate permission.
- Permission to manage features: `features.manage`.
- Permission to view features: `features.view`.

## Settings Integration

Feature availability may be driven by settings:
```
registration.enabled
```

Or by feature flags:
```
features.new_dashboard.enabled
```

## API Responses

When a feature is disabled:
```json
{
  "message": "This feature is not available.",
  "code": "FEATURE_UNAVAILABLE",
  "meta": { "feature": "new_dashboard" }
}
```

When a feature is available but user lacks permission:
```json
{
  "message": "This action is unauthorized.",
  "code": "FORBIDDEN"
}
```